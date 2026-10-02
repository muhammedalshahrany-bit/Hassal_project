<?php

namespace App\Organizations\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\ApiResponse;
use App\Core\Exceptions\ApiRefused;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMembership;
use App\Organizations\Services\OrganizationRoleEditingService;
use App\Organizations\Services\OrganizationStaffingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a company's own standings are worth, and what one person inside it is worth.
 *
 * This is the organization door of the permissions system, and the only one a
 * business may open. Three rules shape everything behind it, and each of them is a
 * boundary the platform decided and the company cannot move:
 *
 *   * The names on offer are the ones a company may hold. `RbacScope` excludes the
 *     platform's own — approving a rival's listing, verifying a business, ruling a
 *     dispute, staffing accounts — from every list, every write and every read,
 *     so a company cannot compose a platform capability out of standings of its
 *     own. The screen is not shown what it cannot submit; submitting it anyway
 *     is refused by name. That rule lives in `PermissionMatrixResolver`, shared
 *     by both halves of the matrix.
 *   * Authority is per company. Every route takes the organization id and checks
 *     the caller's membership *in that organization*, so a manager at one business
 *     cannot edit the matrix of another by guessing its id. The tenant header is
 *     never trusted for this: it says which company a request is acting as, not
 *     whose roles may be rewritten. The check itself is `OrganizationStaffingService`'s
 *     — the same `members.manage` question the roster endpoints ask, asked here of
 *     the matrix.
 *   * Ownership cannot be edited away. The `owner` standing keeps `members.manage`
 *     and `organizations.manage`, and an owner keeps them through their own
 *     override rows, because a company whose last active owner has been denied the
 *     ability to staff is a company nobody can run. The platform can still reach
 *     it — SuperAdmin passes every gate here, and that is the recovery path.
 *
 * Every write takes a `reason` and lands in the audit log with the before and
 * after. A permission change is the sort of event that is only ever interesting
 * after the fact, and "who gave accounting the right to cancel deals, and when"
 * should have one answer rather than three guesses.
 */
class OrganizationRoleController extends Controller
{
    public function __construct(
        private readonly OrganizationRoleEditingService $matrix,
        private readonly OrganizationStaffingService $staffing,
    ) {}

    /**
     * The company's standings, each with what it is worth and who holds it.
     */
    public function index(Request $request, int|string $organizationId): JsonResponse
    {
        $guard = $this->guard($request, $organizationId);

        if ($guard !== null) {
            return $guard;
        }

        $org = Organization::findOrFail($organizationId);

        return ApiResponse::success($this->matrix->listRoles($org), 'Organization roles retrieved');
    }

    /**
     * Give a company its own name for a standing.
     */
    public function store(Request $request, int|string $organizationId): JsonResponse
    {
        $guard = $this->guard($request, $organizationId);

        if ($guard !== null) {
            return $guard;
        }

        $org = Organization::findOrFail($organizationId);

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string'],
            'copy_from' => ['nullable', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            $role = $this->matrix->createRole($org, $validated, $request->user(), $request->ip());
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success($role, 'Role created', Response::HTTP_CREATED);
    }

    /**
     * Rename a standing or replace the capabilities it carries.
     */
    public function update(Request $request, int|string $organizationId, string $key): JsonResponse
    {
        $guard = $this->guard($request, $organizationId);

        if ($guard !== null) {
            return $guard;
        }

        $org = Organization::findOrFail($organizationId);

        $validated = $request->validate([
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'required', 'array'],
            'permissions.*' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            $role = $this->matrix->updateRole($org, $key, $validated, $request->user(), $request->ip());
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        if ($role === null) {
            return ApiResponse::error('لا يوجد هذا الوقوف في هذه المنشأة', 'ROLE_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        return ApiResponse::success($role, 'Role updated');
    }

    /**
     * Retire a standing the company invented.
     */
    public function destroy(Request $request, int|string $organizationId, string $key): JsonResponse
    {
        $guard = $this->guard($request, $organizationId);

        if ($guard !== null) {
            return $guard;
        }

        $reason = (string) $request->input('reason', '');

        if (strlen($reason) < 3) {
            return ApiResponse::error(
                'السبب مطلوب لتوثيق إزالة الوقوف',
                'REASON_REQUIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $org = Organization::findOrFail($organizationId);

        try {
            $snapshot = $this->matrix->deleteRole($org, $key, $reason, $request->user(), $request->ip());
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        if ($snapshot === null) {
            return ApiResponse::error('لا يوجد هذا الوقوف في هذه المنشأة', 'ROLE_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        return ApiResponse::success(['deleted' => $snapshot['key']], 'Role deleted');
    }

    /**
     * One member's individual grants and denials, beside what their standing gives.
     */
    public function memberPermissions(Request $request, int|string $organizationId, int|string $userId): JsonResponse
    {
        $guard = $this->guard($request, $organizationId);

        if ($guard !== null) {
            return $guard;
        }

        $membership = $this->member($organizationId, $userId);

        if ($membership instanceof JsonResponse) {
            return $membership;
        }

        return ApiResponse::success($this->matrix->memberPayload($membership), 'Member permissions retrieved');
    }

    /**
     * Add to and take away from one person's standing.
     *
     * Replaced wholesale, like the matrix: the request states the two lists as
     * they stand on the screen, and a row that is in neither is removed. Half-measures
     * here would leave a denial nobody remembers writing in place forever.
     */
    public function updateMemberPermissions(Request $request, int|string $organizationId, int|string $userId): JsonResponse
    {
        $guard = $this->guard($request, $organizationId);

        if ($guard !== null) {
            return $guard;
        }

        $membership = $this->member($organizationId, $userId);

        if ($membership instanceof JsonResponse) {
            return $membership;
        }

        $validated = $this->validateOverrides($request);

        try {
            $this->matrix->writeMemberOverrides(
                $membership,
                $validated['grants'],
                $validated['denials'],
                $validated['reason'],
                $request->user(),
                $request->ip()
            );
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success($this->matrix->memberPayload($membership->fresh()), 'Member permissions updated');
    }

    /**
     * The same decision applied to several people at once.
     *
     * Who is in the company, what happens to a batch one member of it refuses,
     * and which resolutions must be thrown away are the matrix service's rules —
     * see `writeBulkMemberOverrides()`. This door reads the form, hands it over,
     * and answers in the client's envelope.
     */
    public function bulkMemberPermissions(Request $request, int|string $organizationId): JsonResponse
    {
        $guard = $this->guard($request, $organizationId);

        if ($guard !== null) {
            return $guard;
        }

        $validated = $this->validateOverrides($request, true);

        try {
            $result = $this->matrix->writeBulkMemberOverrides(
                $organizationId,
                $validated['user_ids'],
                $validated['grants'],
                $validated['denials'],
                $validated['reason'],
                $request->user(),
                $request->ip()
            );
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success($result, 'Member permissions updated in bulk');
    }

    /**
     * Read the override form and state it as the two lists it means.
     *
     * Only the shape of the request is decided here. Whether a name may be spoken
     * inside a company at all, and whether the same name may be both added and
     * taken away, are rules about the decision, so the service asks them of every
     * writer — see `OrganizationRoleEditingService::writeMemberOverrides()`.
     *
     * @return array{grants: list<string>, denials: list<string>, reason: string, user_ids: list<int>}
     */
    private function validateOverrides(Request $request, bool $bulk = false): array
    {
        // `present`, not `required`: an empty list is the whole point of one of
        // these two keys. A screen that takes a capability away has nothing to
        // grant in the same request, and a form that sends `grants: []` is stating
        // the screen honestly — "no individual additions" — not omitting a field.
        // `required` would reject that as a malformed request and the caller would
        // learn it only when the removal they came for was refused.
        $rules = [
            'grants' => ['present', 'array'],
            'grants.*' => ['required', 'string'],
            'denials' => ['present', 'array'],
            'denials.*' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];

        if ($bulk) {
            $rules['user_ids'] = ['required', 'array', 'min:1'];
            $rules['user_ids.*'] = ['required', 'integer'];
        }

        $validated = $request->validate($rules);

        return [
            // Repeated names mean the same thing once, and a list that arrives
            // with gaps from the form is compared field by field in the audit
            // snapshot — so the request is stated in the same canonical order
            // every time rather than handed on as typed.
            'grants' => array_values(array_unique((array) ($validated['grants'] ?? []))),
            'denials' => array_values(array_unique((array) ($validated['denials'] ?? []))),
            'reason' => (string) $validated['reason'],
            'user_ids' => $bulk ? array_values(array_unique(array_map('intval', $validated['user_ids']))) : [],
        ];
    }

    /**
     * Is the caller allowed to re-staff this company? The same question the
     * roster endpoints ask — membership row first, platform staff as the
     * recovery path — answered by the staffing service so the two doors cannot
     * drift into different definitions of who manages members.
     *
     * The organization-existence check runs first here because this door names
     * a missing company rather than refusing at its door: `findOrFail` below
     * would answer 404 anyway, and an editor pointing at a company that does
     * not exist is told so, not that it lacks permission.
     */
    private function guard(Request $request, int|string $organizationId): ?JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('غير مسجّل', 'UNAUTHENTICATED', Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->isPlatformOperator() && !Organization::whereKey($organizationId)->exists()) {
            return ApiResponse::error('المنشأة غير موجودة', 'ORGANIZATION_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        // Reaching here the company exists for everyone who is not platform
        // staff; platform staff pass the staffing check whatever the id, and
        // their 404 for a missing company comes from the action's own
        // findOrFail, exactly as before.
        try {
            $this->staffing->assertMayStaff($user, Organization::find($organizationId) ?? new Organization(), 'لا تملك صلاحية إدارة أدوار هذه المنشأة');
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return null;
    }

    /**
     * The member row, if this account belongs to this company.
     *
     * A non-member and a missing company answer differently here on purpose: the
     * caller named both, and only one of them is theirs to fix.
     */
    private function member(int|string $organizationId, int|string $userId): OrganizationMembership|JsonResponse
    {
        $membership = $this->matrix->membershipFor($organizationId, $userId);

        return $membership
            ?: ApiResponse::error('هذا الحساب ليس ضمن هذه المنشأة', 'MEMBERSHIP_NOT_FOUND', Response::HTTP_NOT_FOUND);
    }
}
