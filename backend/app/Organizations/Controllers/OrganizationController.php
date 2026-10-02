<?php

namespace App\Organizations\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Exceptions\ApiRefused;
use App\Organizations\Requests\AddMemberRequest;
use App\Organizations\Requests\StoreOrganizationRequest;
use App\Core\Http\ApiResponse;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMembership;
use App\Organizations\Services\OrganizationRegistrationService;
use App\Organizations\Services\OrganizationRoleEditingService;
use App\Organizations\Services\OrganizationStaffingService;
use App\Organizations\Services\OrganizationVerificationService;
use App\Core\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The HTTP edge of a business: read the request, answer with the envelope.
 *
 * Every rule that decides *whether* and *what* — founding ceilings, staffing
 * permissions, ownership continuity, verification decisions — lives in the
 * three services this controller injects. Those services say "no" by throwing
 * `ApiRefused`, which carries the exact message, code, status and
 * details the client must receive; `refused()` below is the only translation
 * this layer performs. A response shape that changed while moving code here
 * would be a contract break dressed up as a refactor, so the mapping is
 * mechanical and nothing else happens in the catch.
 */
class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationRegistrationService $registration,
        private readonly OrganizationStaffingService $staffing,
        private readonly OrganizationVerificationService $verification,
        private readonly OrganizationRoleEditingService $matrix,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isPlatformOperator()) {
            $query = Organization::query();

            if ($request->has('type')) {
                $query->where('type', $request->query('type'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->query('status'));
            }

            // Name search for the console. The columns are the ones a buyer
            // would recognise: the registered names and the trading name.
            if ($request->filled('search')) {
                $term = '%' . trim((string) $request->query('search')) . '%';
                $query->where(function ($orgs) use ($term) {
                    $orgs->where('name_ar', 'like', $term)
                        ->orWhere('name_en', 'like', $term)
                        ->orWhere('trade_name_ar', 'like', $term)
                        ->orWhere('trade_name_en', 'like', $term);
                });
            }

            $organizations = $query->paginate($request->query('per_page', 15));
            return ApiResponse::paginated($organizations, 'All organizations retrieved');
        }

        $userOrganizations = $user->organizations()
            ->withPivot('role', 'is_active')
            ->paginate($request->query('per_page', 15));

        return ApiResponse::paginated($userOrganizations, 'User organizations retrieved');
    }

    /**
     * Register a business. The door, not the rules — see
     * `OrganizationRegistrationService::found()` for the account completeness,
     * per-account ceiling, and the transaction that writes the company, its
     * owner's membership and its standings together.
     */
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        try {
            $organization = $this->registration->found($request->user(), $request->validated());
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $organization->load('memberships.user'),
            'تم تسجيل المنشأة وهي في انتظار مراجعة المنصة',
            Response::HTTP_CREATED
        );
    }

    public function show(int|string $id): JsonResponse
    {
        $org = Organization::with(['memberships.user'])->findOrFail($id);

        if (!$this->isMemberOf($org)) {
            return ApiResponse::error(
                'أنت لست عضواً في هذه المنشأة',
                'ORGANIZATION_ACCESS_DENIED',
                Response::HTTP_FORBIDDEN
            );
        }

        return ApiResponse::success([
            'id' => $org->id,
            'name_ar' => $org->name_ar,
            'name_en' => $org->name_en,
            'trade_name_ar' => $org->trade_name_ar,
            'trade_name_en' => $org->trade_name_en,
            'type' => $org->type,
            'country_code' => $org->country_code,
            'city' => $org->city,
            'website' => $org->website,
            'phone' => $org->phone,
            'email' => $org->email,
            'commercial_registration_number' => $org->commercial_registration_number,
            'vat_number' => $org->vat_number,
            'status' => $org->status,
            'credit_limit' => $org->credit_limit,
            // Nothing on this platform computes a score, so this is null for
            // every business; a screen may show it as unrecorded and nothing
            // else. It is not this column's to earn by default.
            'rating' => $org->rating,
            'kyc_verified_at' => $org->kyc_verified_at,
            'members_count' => $org->activeMemberships()->count(),
            'created_at' => $org->created_at,
        ], 'Organization retrieved successfully');
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);

        // Asked before the form is read: an account that may not rewrite this
        // company's identity is told so, not that one of its fields was too long.
        try {
            $this->staffing->assertMayEditProfile($request->user(), $org);
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        $validated = $request->validate([
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'trade_name_ar' => ['sometimes', 'nullable', 'string', 'max:255'],
            'trade_name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'commercial_registration_number' => ['sometimes', 'nullable', 'string', 'max:50', 'unique:organizations,commercial_registration_number,' . $id],
            'vat_number' => ['sometimes', 'nullable', 'string', 'max:50', 'unique:organizations,vat_number,' . $id],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $reopenReview = $this->verification->applyProfileEdit($org, $validated);

        return ApiResponse::success(
            $org->fresh(),
            $reopenReview
                ? 'تم حفظ الملف، وتحتاج بياناتك القانونية إلى مراجعة جديدة من المنصة'
                : 'تم حفظ ملف المنشأة'
        );
    }

    /**
     * The platform's decision about whether a business may trade. Gated at the
     * route by `organizations.verify`; the decision itself — including the
     * audit row and its `hassal_platform_review` source — is the verification
     * service's to make.
     */
    public function verification(Request $request, int|string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['VERIFY', 'REJECT', 'SUSPEND', 'REINSTATE'])],
            'reason'   => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->verification->decide(
                $org,
                $validated['decision'],
                $validated['reason'] ?? null,
                $request->user(),
                $request->ip()
            );
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success($result['organization']->fresh(), $result['message']);
    }

    public function members(int|string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);

        // The roster is a list of people, not of commercial records: exposing it
        // lets any account map which staff work for which business.
        if (!$this->isMemberOf($org)) {
            return ApiResponse::error(
                'أنت لست عضواً في هذه المنشأة',
                'ORGANIZATION_ACCESS_DENIED',
                Response::HTTP_FORBIDDEN
            );
        }

        $members = $org->activeMemberships()
            ->with('user:id,name,email,phone,status')
            ->get();

        return ApiResponse::success($members, 'Organization members retrieved');
    }

    /**
     * The staffing vocabulary a screen may offer, for the company in context or
     * the platform's own standings when there is no context to read.
     *
     * The list is the service's to build — see
     * `OrganizationRoleEditingService::staffingVocabulary()`.
     */
    public function membershipRoles(): JsonResponse
    {
        return ApiResponse::success(
            $this->matrix->staffingVocabulary(TenantContext::get()),
            'Membership roles retrieved'
        );
    }

    public function addMember(AddMemberRequest $request, int|string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);

        try {
            $result = $this->staffing->addMember($org, $request->user(), $request->validated(), $request->ip());
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(
            $result['membership']->load('user:id,name,email,phone'),
            'Member added to organization successfully',
            Response::HTTP_CREATED
        );
    }

    public function updateMember(Request $request, int|string $id, int|string $userId): JsonResponse
    {
        $org = Organization::findOrFail($id);

        $validated = $request->validate([
            'role' => [
                'required',
                'string',
                'max:64',
                function (string $attribute, mixed $value, \Closure $fail) use ($org): void {
                    if (!OrganizationMembership::isStaffableRole($org->id, is_string($value) ? $value : null)) {
                        $fail('هذا الدور غير موجود في هذه المنشأة');
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            $membership = $this->staffing->updateMember($org, $userId, $request->user(), $validated, $request->ip());
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success($membership->load('user:id,name,email,phone'), 'Member role updated successfully');
    }

    public function removeMember(Request $request, int|string $id, int|string $userId): JsonResponse
    {
        $org = Organization::findOrFail($id);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            $this->staffing->removeMember($org, $userId, $request->user(), $validated['reason'], $request->ip());
        } catch (ApiRefused $refused) {
            return $this->refused($refused);
        }

        return ApiResponse::success(null, 'Member deactivated and removed from active roster');
    }

    /**
     * Say "no" exactly as the service meant it: the message and code are the
     * client's contract, and this layer adds nothing to them.
     */

    /**
     * Is the acting user a member of this organization (or the platform)?
     *
     * Membership, not permission, is what decides this: `organizations.manage`
     * says what a person may do inside a business they belong to, and says
     * nothing about reaching into somebody else's.
     */
    protected function isMemberOf(Organization $organization): bool
    {
        $user = request()->user();

        if (!$user) {
            return false;
        }

        if ($user->isPlatformOperator()) {
            return true;
        }

        return $user->belongsToOrganization($organization->id);
    }
}
