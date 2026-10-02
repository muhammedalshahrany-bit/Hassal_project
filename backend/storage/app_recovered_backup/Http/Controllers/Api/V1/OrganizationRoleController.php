<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMemberPermission;
use App\Models\OrganizationMembership;
use App\Models\OrganizationRole;
use App\Support\RbacScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a company's own standings are worth, and what one person inside it is worth.
 *
 * This is the organization door of the permissions system, and the only one a
 * business may open. Three rules shape everything below it, and each of them is a
 * boundary the platform decided and the company cannot move:
 *
 *   * The names on offer are the ones a company may hold. `RbacScope` excludes the
 *     platform's own — approving a rival's listing, verifying a business, ruling a
 *     dispute, staffing accounts — from every list, every write and every read
 *     here, so a company cannot compose a platform capability out of standings of
 *     its own. The screen is not shown what it cannot submit; submitting it anyway
 *     is refused by name.
 *   * Authority is per company. Every route takes the organization id and checks
 *     the caller's membership *in that organization*, so a manager at one business
 *     cannot edit the matrix of another by guessing its id. The tenant header is
 *     never trusted for this: it says which company a request is acting as, not
 *     whose roles may be rewritten.
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
    /** The two capabilities an owner standing must never be without. */
    private const OWNER_CORE = ['members.manage', 'organizations.manage'];

    /**
     * The company's standings, each with what it is worth and who holds it.
     */
    public function index(Request $request, int|string $organizationId): JsonResponse
    {
        $guard = $this->authorizeStaffing($request, $organizationId);

        if ($guard !== null) {
            return $guard;
        }

        $org = Organization::findOrFail($organizationId);

        $this->provisionIfMissing($org);

        $roles = OrganizationRole::forOrganization($org->id);

        $counts = DB::table('organization_memberships')
            ->where('organization_id', $org->id)
            ->groupBy('role')
            ->select('role', DB::raw('count(*) as holders'))
            ->pluck('holders', 'role');

        return ApiResponse::success([
            'organization_id' => $org->id,
            // Only the names this company may be given a standing for. A screen
            // that renders the platform's capabilities as greyed-out boxes is a
            // screen that gets asked why they are greyed out; the list simply does
            // not contain them, and `scope` says which side each name belongs to
            // for the ones that do.
            'grantable_permissions' => $this->grantablePermissions(),
            'roles' => array_map(static fn (OrganizationRole $role): array => [
                'id' => $role->id,
                'key' => $role->key,
                'name_ar' => $role->name_ar,
                'name_en' => $role->name_en,
                'is_system' => $role->is_system,
                'holder_count' => (int) ($counts[$role->key] ?? 0),
                'permissions' => $role->permissionNames(),
            ], $roles),
        ], 'Organization roles retrieved');
    }

    /**
     * Give a company its own name for a standing.
     *
     * A custom standing starts from the platform default it is modelled on, when
     * the caller names one, and from nothing otherwise. Copying is the honest
     * default for «نائب المدير» and a trap for «أمين المستودع», so the caller
     * chooses and the choice is auditable.
     */
    public function store(Request $request, int|string $organizationId): JsonResponse
    {
        $guard = $this->authorizeStaffing($request, $organizationId);

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

        [$accepted, $why] = OrganizationRole::validateKey($validated['key']);

        if (!$accepted) {
            return ApiResponse::error($why, 'ROLE_KEY_INVALID', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (OrganizationRole::existsForKey($org->id, $validated['key'])) {
            return ApiResponse::error(
                'لدي هذه المنشأة دور بهذا المفتاح بالفعل',
                'ROLE_KEY_TAKEN',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // The five standings the platform names belong to every company and are
        // re-provisioned on read; a custom key may not collide with one, or the
        // same word would mean two different things depending on whether this
        // company had been provisioned yet.
        if (OrganizationMembership::isRole($validated['key'])) {
            return ApiResponse::error(
                'هذا المفتاح اسم دور معتمد من المنصة؛ استخدم مفتاحًا آخر',
                'ROLE_KEY_RESERVED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $resolved = $this->resolveWantedPermissions($validated);

        if ($resolved['refused'] !== []) {
            return $this->permissionRefusal($resolved['refused']);
        }

        $wanted = $resolved['names'];

        $role = OrganizationRole::create([
            'organization_id' => $org->id,
            'key' => $validated['key'],
            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'] ?? null,
            'is_system' => false,
        ]);

        if ($validated['permissions'] === null && !empty($validated['copy_from'])) {
            $source = OrganizationRole::lookup($org->id, $validated['copy_from']);

            if ($source === null) {
                $role->delete();

                return ApiResponse::error(
                    'الوقوف المنقول عنه غير موجود في هذه المنشأة',
                    'ROLE_SOURCE_MISSING',
                    Response::HTTP_NOT_FOUND
                );
            }

            $role->permissions()->sync($source->permissions->pluck('id')->all());
        } else {
            $role->permissions()->sync($this->permissionIds($wanted));
        }

        $this->audit($request, $org->id, $role->id, 'ORG_ROLE_CREATED', null, [
            'key' => $role->key,
            'permissions' => $role->permissionNames(),
            'reason' => $validated['reason'],
        ]);

        OrganizationMembership::flushResolutions();

        return ApiResponse::success([
            'id' => $role->id,
            'key' => $role->key,
            'name_ar' => $role->name_ar,
            'name_en' => $role->name_en,
            'is_system' => false,
            'permissions' => $role->permissionNames(),
        ], 'Role created', Response::HTTP_CREATED);
    }

    /**
     * Rename a standing or replace the capabilities it carries.
     *
     * The grants are replaced, not merged. A matrix editor shows checkboxes and
     * the state of the row after a save is expected to be the state of the screen
     * when it was submitted; an additive save would mean a box cannot be unticked.
     */
    public function update(Request $request, int|string $organizationId, string $key): JsonResponse
    {
        $guard = $this->authorizeStaffing($request, $organizationId);

        if ($guard !== null) {
            return $guard;
        }

        $org = Organization::findOrFail($organizationId);
        $role = OrganizationRole::lookup($org->id, $key);

        if ($role === null) {
            return ApiResponse::error('لا يوجد هذا الوقوف في هذه المنشأة', 'ROLE_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'required', 'array'],
            'permissions.*' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $before = $role->permissionNames();

        if (array_key_exists('permissions', $validated)) {
            $resolved = $this->resolveWantedPermissions($validated);

            if ($resolved['refused'] !== []) {
                return $this->permissionRefusal($resolved['refused']);
            }

            $wanted = $resolved['names'];

            if ($role->key === OrganizationRole::KEY_OWNER) {
                $missing = array_values(array_diff(self::OWNER_CORE, $wanted));

                if ($missing !== []) {
                    return ApiResponse::error(
                        'لا يمكن تجريده من: ' . implode(', ', $missing) . ' — المالك هو من يعيّن غيرهم',
                        'OWNER_STANDING_PROTECTED',
                        Response::HTTP_UNPROCESSABLE_ENTITY
                    );
                }
            }

            $refused = $role->replacePermissions($wanted);

            if ($refused !== []) {
                return ApiResponse::error(
                    'لا تُمنح داخل المنشأة: ' . implode(', ', $refused),
                    'ROLE_PERMISSION_REFUSED',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        $role->fill(array_filter([
            'name_ar' => $validated['name_ar'] ?? null,
            'name_en' => $validated['name_en'] ?? null,
        ], static fn (?string $value): bool => $value !== null))->save();

        $this->audit($request, $org->id, $role->id, 'ORG_ROLE_UPDATED', ['permissions' => $before], [
            'permissions' => $role->permissionNames(),
            'name_ar' => $role->name_ar,
            'reason' => $validated['reason'],
        ]);

        OrganizationMembership::flushResolutions();

        return ApiResponse::success([
            'id' => $role->id,
            'key' => $role->key,
            'name_ar' => $role->name_ar,
            'name_en' => $role->name_en,
            'is_system' => $role->is_system,
            'permissions' => $role->permissionNames(),
        ], 'Role updated');
    }

    /**
     * Retire a standing the company invented.
     *
     * The five the platform names stay: `organization_memberships.role` holds a
     * string, and a company that deleted `staff` would keep rows naming it. A
     * custom standing goes only when nobody holds it — deleting one with holders
     * would leave them with a key that resolves to nothing, and the read path
     * falls back to the platform default for such a row, which is the opposite of
     * what an editor who just deleted it means.
     */
    public function destroy(Request $request, int|string $organizationId, string $key): JsonResponse
    {
        $guard = $this->authorizeStaffing($request, $organizationId);

        if ($guard !== null) {
            return $guard;
        }

        $org = Organization::findOrFail($organizationId);
        $role = OrganizationRole::lookup($org->id, $key);

        if ($role === null) {
            return ApiResponse::error('لا يوجد هذا الوقوف في هذه المنشأة', 'ROLE_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        if ($role->is_system) {
            return ApiResponse::error(
                'دور معتمد من المنصة لا يُحذف: يمكن تعديل صلاحياته فقط',
                'ROLE_SYSTEM_PROTECTED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $holders = OrganizationMembership::where('organization_id', $org->id)
            ->where('role', $role->key)
            ->count();

        if ($holders > 0) {
            return ApiResponse::error(
                "يزال {$holders} من أعضاء المنشأة يحملون هذا الدور؛ انقلهم أولًا",
                'ROLE_IN_USE',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['holders' => $holders]
            );
        }

        $snapshot = [
            'key' => $role->key,
            'name_ar' => $role->name_ar,
            'permissions' => $role->permissionNames(),
        ];

        $reason = (string) $request->input('reason', '');

        if (strlen($reason) < 3) {
            return ApiResponse::error(
                'السبب مطلوب لتوثيق إزالة الوقوف',
                'REASON_REQUIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $role->delete();

        $this->audit($request, $org->id, $role->id, 'ORG_ROLE_DELETED', $snapshot, ['reason' => $reason]);

        OrganizationMembership::flushResolutions();

        return ApiResponse::success(['deleted' => $snapshot['key']], 'Role deleted');
    }

    /**
     * One member's individual grants and denials, beside what their standing gives.
     */
    public function memberPermissions(Request $request, int|string $organizationId, int|string $userId): JsonResponse
    {
        $guard = $this->authorizeStaffing($request, $organizationId);

        if ($guard !== null) {
            return $guard;
        }

        $membership = $this->membership($organizationId, $userId);

        if ($membership === null) {
            return ApiResponse::error('هذا الحساب ليس ضمن هذه المنشأة', 'MEMBERSHIP_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        return ApiResponse::success($this->memberPayload($membership), 'Member permissions retrieved');
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
        $guard = $this->authorizeStaffing($request, $organizationId);

        if ($guard !== null) {
            return $guard;
        }

        $membership = $this->membership($organizationId, $userId);

        if ($membership === null) {
            return ApiResponse::error('هذا الحساب ليس ضمن هذه المنشأة', 'MEMBERSHIP_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        [$validated, $error] = $this->validateOverrides($request);

        if ($error !== null) {
            return $error;
        }

        $before = [
            'grants' => $this->overrideNames($membership, OrganizationMemberPermission::EFFECT_GRANT),
            'denials' => $this->overrideNames($membership, OrganizationMemberPermission::EFFECT_DENY),
        ];

        $refused = $this->writeOverrides(
            $request,
            $membership,
            $validated['grants'],
            $validated['denials'],
            $validated['reason']
        );

        if ($refused !== null) {
            return $refused;
        }

        OrganizationMembership::flushResolutions();

        return ApiResponse::success($this->memberPayload($membership->fresh()), 'Member permissions updated');
    }

    /**
     * The same decision applied to several people at once.
     *
     * Bulk exists because a company of forty does not want forty screens to reach
     * the same answer, and because an edit applied to a group is one audit entry
     * with one reason rather than forty that no longer say why. Every member row
     * is validated on its own, so a request touching a company the caller does not
     * run fails for that id and not for the whole call — a partial write is what
     * "these twelve, except the one I mistyped" would otherwise mean.
     */
    public function bulkMemberPermissions(Request $request, int|string $organizationId): JsonResponse
    {
        $guard = $this->authorizeStaffing($request, $organizationId);

        if ($guard !== null) {
            return $guard;
        }

        [$validated, $error] = $this->validateOverrides($request, true);

        if ($error !== null) {
            return $error;
        }

        $ids = array_values(array_unique(array_map('intval', $validated['user_ids'])));

        $memberships = OrganizationMembership::where('organization_id', $organizationId)
            ->whereIn('user_id', $ids)
            ->get()
            ->keyBy('user_id');

        $missing = array_values(array_diff($ids, $memberships->keys()->all()));

        if ($missing !== []) {
            return ApiResponse::error(
                'ليسوا أعضاء في هذه المنشأة: ' . implode(', ', $missing),
                'MEMBERSHIP_NOT_FOUND',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['user_ids' => $missing]
            );
        }

        $applied = [];

        foreach ($memberships as $membership) {
            $before = [
                'grants' => $this->overrideNames($membership, OrganizationMemberPermission::EFFECT_GRANT),
                'denials' => $this->overrideNames($membership, OrganizationMemberPermission::EFFECT_DENY),
            ];

            $refused = $this->writeOverrides(
                $request,
                $membership,
                $validated['grants'],
                $validated['denials'],
                $validated['reason']
            );

            if ($refused !== null) {
                // Refuse the whole call: a screen that reports "granted to
                // everybody except Ali" cannot be re-submitted safely, and a
                // half-applied permission change is the one outcome neither the
                // editor nor the audit log can be trusted to describe.
                OrganizationMembership::flushResolutions();

                return $refused;
            }

            $applied[] = [
                'user_id' => (int) $membership->user_id,
                'grants' => $validated['grants'],
                'denials' => $validated['denials'],
                'changed' => $before['grants'] !== $validated['grants'] || $before['denials'] !== $validated['denials'],
            ];
        }

        OrganizationMembership::flushResolutions();

        return ApiResponse::success([
            'organization_id' => (int) $organizationId,
            'applied' => $applied,
        ], 'Member permissions updated in bulk');
    }

    /**
     * @return array<string, mixed>
     */
    private function memberPayload(OrganizationMembership $membership): array
    {
        return [
            'user_id' => (int) $membership->user_id,
            'organization_id' => (int) $membership->organization_id,
            'role' => $membership->role,
            'is_active' => $membership->is_active,
            'role_permissions' => OrganizationRole::lookup($membership->organization_id, $membership->role)
                ?->permissionNames() ?? OrganizationMembership::permissionsFor($membership->role),
            'grants' => $this->overrideNames($membership, OrganizationMemberPermission::EFFECT_GRANT),
            'denials' => $this->overrideNames($membership, OrganizationMemberPermission::EFFECT_DENY),
            // What the account can actually do here — the composition the runtime
            // applies, computed by the same method the enforcement path uses, so
            // the screen cannot show an answer the middleware disagrees with.
            'effective' => $membership->effectivePermissions(),
        ];
    }

    /**
     * @param  list<string>  $grants
     * @param  list<string>  $denials
     * @return JsonResponse|null a refusal, with the names that caused it
     */
    private function writeOverrides(
        Request $request,
        OrganizationMembership $membership,
        array $grants,
        array $denials,
        string $reason
    ): ?JsonResponse {
        $blocked = RbacScope::unacceptableInOrganization([...$grants, ...$denials]);

        $unknown = array_values(array_filter(
            [...$grants, ...$denials],
            static fn (string $name): bool => !OrganizationMembership::isCatalogued($name)
        ));

        if ($unknown !== []) {
            $blocked = array_values(array_unique([...$blocked, ...$unknown]));
        }

        if ($blocked !== []) {
            return ApiResponse::error(
                'لا تُمنح ولا تُسحب داخل المنشأة: ' . implode(', ', $blocked),
                'MEMBER_PERMISSION_REFUSED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['permissions' => $blocked]
            );
        }

        if ($membership->role === OrganizationMembership::ROLE_OWNER) {
            $stripped = array_values(array_intersect(self::OWNER_CORE, $denials));

            if ($stripped !== []) {
                return ApiResponse::error(
                    'لا يُجرَّد المالك من: ' . implode(', ', $stripped),
                    'OWNER_STANDING_PROTECTED',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['permissions' => $stripped]
                );
            }
        }

        $ids = $this->permissionIds([...$grants, ...$denials]);

        // Rows are replaced rather than synced through a relation the model does
        // not own as a belongsToMany: `effect` is part of the identity of a row,
        // and a pivot sync that cannot express it would leave a stale `deny`
        // standing where a `grant` was just written.
        $membership->overrides()->delete();

        foreach ($grants as $name) {
            if (isset($ids[$name])) {
                $membership->overrides()->create([
                    'permission_id' => $ids[$name],
                    'effect' => OrganizationMemberPermission::EFFECT_GRANT,
                ]);
            }
        }

        foreach ($denials as $name) {
            if (isset($ids[$name])) {
                $membership->overrides()->create([
                    'permission_id' => $ids[$name],
                    'effect' => OrganizationMemberPermission::EFFECT_DENY,
                ]);
            }
        }

        $this->audit(
            $request,
            $membership->organization_id,
            $membership->id,
            'ORG_MEMBER_PERMISSIONS_UPDATED',
            null,
            ['user_id' => (int) $membership->user_id, 'grants' => $grants, 'denials' => $denials, 'reason' => $reason]
        );

        return null;
    }

    /**
     * @return array{0: array{grants: list<string>, denials: list<string>, reason: string, user_ids?: list<int>}, 1: JsonResponse|null}
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

        $grants = array_values(array_unique((array) ($validated['grants'] ?? [])));
        $denials = array_values(array_unique((array) ($validated['denials'] ?? [])));

        $both = array_values(array_intersect($grants, $denials));

        if ($both !== []) {
            return [[], ApiResponse::error(
                'لا يمكن منح الصلاحية وسحبها للعضو نفسه في الوقت نفسه: ' . implode(', ', $both),
                'MEMBER_PERMISSION_CONTRADICTION',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['permissions' => $both]
            )];
        }

        return [[
            'grants' => $grants,
            'denials' => $denials,
            'reason' => (string) $validated['reason'],
            'user_ids' => $bulk ? array_values(array_unique(array_map('intval', $validated['user_ids']))) : [],
        ], null];
    }

    /**
     * @return list<string>
     */
    private function overrideNames(OrganizationMembership $membership, string $effect): array
    {
        $membership->loadMissing('overrides.permission');

        return $membership->overrides
            ->where('effect', $effect)
            ->map(fn (OrganizationMemberPermission $override): ?string => $override->permission?->name)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The names a company may put in front of its people, with their scope.
     *
     * @return list<array{name: string, scope: string}>
     */
    private function grantablePermissions(): array
    {
        return array_values(array_filter(
            RbacScope::catalog(),
            static fn (array $entry): bool => RbacScope::exercisableInsideOrganization($entry['name'])
        ));
    }

    /**
     * The names the caller asked for, and the names this door refuses.
     *
     * Two refusals, both about names rather than about people: a platform capability,
     * which no company standing may carry whatever the screen sends, and a name the
     * catalog never declared, which enforces nothing and would otherwise be saved as
     * though it did. Both come back as lists because the 422 has to name them — a
     * matrix editor cannot untick "the request was invalid", and the refusal is the
     * same rule the list of offered names applies, so the screen that shows this
     * response was shown a form that should not have contained the name.
     *
     * Which standing is being edited is not this method's business: the owner
     * protection belongs to the caller that knows it.
     *
     * @param  array<string, mixed>  $validated
     * @return array{names: list<string>, refused: list<string>}
     */
    private function resolveWantedPermissions(array $validated): array
    {
        $wanted = array_values(array_unique((array) ($validated['permissions'] ?? [])));

        $refused = RbacScope::unacceptableInOrganization($wanted);

        $unknown = array_values(array_filter(
            $wanted,
            static fn (string $name): bool => !OrganizationMembership::isCatalogued($name)
        ));

        return [
            'names' => array_values(array_diff($wanted, [...$refused, ...$unknown])),
            'refused' => array_values(array_unique([...$refused, ...$unknown])),
        ];
    }

    /**
     * @param  list<string>  $refused
     */
    private function permissionRefusal(array $refused): JsonResponse
    {
        return ApiResponse::error(
            'لا تُمنح داخل المنشأة: صلاحيات المنصة وأسماء غير معلنة لا تحملها وقفات الشركات — ' . implode(', ', $refused),
            'ROLE_PERMISSION_REFUSED',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['permissions' => $refused]
        );
    }

    /**
     * @param  array<array-key, string>  $names
     * @return array<string, int>  name => permissions.id
     */
    private function permissionIds(array $names): array
    {
        if ($names === []) {
            return [];
        }

        return DB::table('permissions')->whereIn('name', $names)->pluck('id', 'name')->all();
    }

    private function membership(int|string $organizationId, int|string $userId): ?OrganizationMembership
    {
        return OrganizationMembership::where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Is the caller allowed to re-staff this company?
     *
     * Their membership row is asked, not the tenant header and not the account's
     * global roles: `members.manage` reaches this path through
     * `effectivePermissions()`, so a standing the company created and gave that
     * capability passes, and a manager at another company does not.
     */
    private function authorizeStaffing(Request $request, int|string $organizationId): ?JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return ApiResponse::error('غير مسجّل', 'UNAUTHENTICATED', Response::HTTP_UNAUTHORIZED);
        }

        if (!$user->hasRole('SuperAdmin') && !Organization::whereKey($organizationId)->exists()) {
            return ApiResponse::error('المنشأة غير موجودة', 'ORGANIZATION_NOT_FOUND', Response::HTTP_NOT_FOUND);
        }

        $membership = $this->membership($organizationId, $user->id);

        if ($membership !== null && $membership->is_active && $membership->covers('members.manage')) {
            return null;
        }

        if ($user->hasRole('SuperAdmin')) {
            return null;
        }

        return ApiResponse::error(
            'لا تملك صلاحية إدارة أدوار هذه المنشأة',
            'FORBIDDEN',
            Response::HTTP_FORBIDDEN
        );
    }

    /**
     * Backfill the standings for a company older than the table.
     *
     * The migration provisions every company that exists when it runs and the
     * creation path provisions every company created after; a row inserted by a
     * seeder, an import or a direct insert falls between both, and a company with
     * no standing rows reads against the platform defaults — correct, but invisible
     * to the editor that offers "edit this company's roles" and finds none.
     */
    private function provisionIfMissing(Organization $org): void
    {
        if (OrganizationRole::where('organization_id', $org->id)->exists()) {
            return;
        }

        OrganizationRole::provisionOrganization($org->id);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>  $new
     */
    private function audit(Request $request, int|string $organizationId, int|string $id, string $action, ?array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'auditable_type' => OrganizationRole::class,
            'auditable_id' => $id,
            'old_values' => $old,
            'new_values' => $new,
            'organization_id' => $organizationId,
            'ip_address' => $request->ip(),
        ]);
    }
}
