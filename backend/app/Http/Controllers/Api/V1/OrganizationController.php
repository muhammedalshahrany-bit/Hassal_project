<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddMemberRequest;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\SystemSettingsService;
use App\Services\TenantContext;
use App\Support\AccountRequirements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class OrganizationController extends Controller
{
    public function __construct(
        protected SystemSettingsService $settingsService,
        private readonly AccountRequirements $accountRequirements,
    ) {}

    /**
     * The Arabic name of a required account field, for the sentence that asks
     * for it. Field keys travel in the response for the client to branch on;
     * the words are for the person reading it.
     */
    private static function labelForAccountField(string $field): string
    {
        return match ($field) {
            AccountRequirements::FIELD_PHONE => 'رقم الهاتف',
            AccountRequirements::FIELD_NATIONAL_ID => 'الرقم الوطني أو المدني',
            default => $field,
        };
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasRole('SuperAdmin')) {
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
     * Register a business.
     *
     * This is the only door to a business record, which is why it is the door
     * that carries the rules. Registration (`POST /auth/register`) used to open
     * a second one for anyone who typed a company name into the sign-up form —
     * and that second door checked nothing the first one did: not the legal
     * identifiers an administrator switched on, not a commercial registration
     * number already held by another business, not how many businesses the
     * account had already founded. A rule that can be bypassed by choosing the
     * other entrance is not a rule.
     *
     * Three things are checked before a row is written, and each one is a
     * different kind of failure:
     *
     *   * the account has completed what this deployment requires of an account
     *     (`account.require_*`). A person who has not said how to reach them
     *     cannot found a company a counterparty would contract with;
     *   * the account has not founded more than `account.max_organizations_per_account`
     *     businesses. The count is of *owned* businesses, because ownership is
     *     the act of founding; being added as staff to nine companies is someone
     *     else's hiring decision, not this ceiling's business;
     *   * the legal identifiers the operator demanded are present, and this
     *     registration number belongs to nobody else — both in
     *     `StoreOrganizationRequest`, so the answer arrives as field errors on
     *     the form that asked for them rather than as an unexplained 422.
     *
     * A name is not a unique identifier and is not treated as one: two shops may
     * be called «مؤسسة النور» and only one of them may hold the registration
     * number. What the platform refuses is a duplicate *number*, which is the
     * claim that would put two businesses' paperwork on the same record.
     *
     * The new business starts as `pending_kyc` whatever it supplied. Nothing
     * here has been checked against a register, and `marketplace.require_verified_to_trade`
     — the switch an operator sets, default on — is what keeps it from publishing
     * offers or signing deals until a reviewer has read the documents.
     */
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $isPlatformStaff = $user->hasRole('SuperAdmin');

        if (! $isPlatformStaff) {
            $missing = $this->accountRequirements->missingFields($user);

            if ($missing !== []) {
                return ApiResponse::error(
                    'أكمل بيانات حسابك قبل تسجيل منشأة: ' . implode('، ', array_map(
                        [self::class, 'labelForAccountField'],
                        $missing
                    )),
                    'ACCOUNT_PROFILE_INCOMPLETE',
                    Response::HTTP_FORBIDDEN,
                    ['missing_fields' => $missing]
                );
            }

            $ceiling = max(0, (int) $this->settingsService->get('account.max_organizations_per_account', 3));

            if ($ceiling > 0) {
                $founded = OrganizationMembership::query()
                    ->where('user_id', $user->id)
                    ->where('role', OrganizationMembership::ROLE_OWNER)
                    ->count();

                if ($founded >= $ceiling) {
                    return ApiResponse::error(
                        "وصل هذا الحساب إلى الحد المسموح له بإنشائه من منشآت ({$ceiling}). تواصل مع إدارة المنصة إذا كانت لك منشأة أخرى.",
                        'ORGANIZATION_CREATION_LIMIT_REACHED',
                        Response::HTTP_FORBIDDEN,
                        ['limit' => $ceiling, 'founded' => $founded]
                    );
                }
            }
        }

        $organization = DB::transaction(function () use ($validated, $user) {
            $org = Organization::create([
                'name_ar' => $validated['name_ar'],
                'name_en' => $validated['name_en'],
                'type' => $validated['type'],
                // Blank means "not provided", which the record keeps as null. A
                // placeholder here would be a legal identifier the platform
                // invented, and it would reach a counterparty's screen looking
                // exactly like a real one.
                'commercial_registration_number' => $validated['commercial_registration_number'] ?? null,
                'vat_number' => $validated['vat_number'] ?? null,
                // Whatever was supplied, nobody has looked at it yet.
                'status' => 'pending_kyc',
                'credit_limit' => $validated['credit_limit'] ?? 0.00,
                'metadata' => $validated['metadata'] ?? null,
            ]);

            OrganizationMembership::create([
                'organization_id' => $org->id,
                'user_id' => $user->id,
                'role' => OrganizationMembership::ROLE_OWNER,
                'is_active' => true,
                'joined_at' => now(),
            ]);

            return $org;
        });

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
            'members_count' => $org->memberships()->where('is_active', true)->count(),
            'created_at' => $org->created_at,
        ], 'Organization retrieved successfully');
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);
        $user = $request->user();

        // Check permission (owner, admin of this org or SuperAdmin)
        $role = $user->roleInOrganization($org->id);
        if (!$user->hasRole('SuperAdmin') && !in_array($role, ['owner', 'admin'])) {
            return ApiResponse::error(
                'تعديل ملف المنشأة للمالك أو مدير المنشأة فقط',
                'FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
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

        $reopenReview = false;

        foreach (['commercial_registration_number', 'vat_number'] as $field) {
            if (!array_key_exists($field, $validated)) {
                continue;
            }

            $incoming = $validated[$field];
            $stored = $org->{$field};

            // Sending the field unchanged is not a change. Nulls are compared
            // as "not provided" so a form that echoes an empty field back does
            // not reopen a decision that was never disturbed.
            if (($incoming === null ? null : (string) $incoming) === ($stored === null ? null : (string) $stored)) {
                continue;
            }

            if ($org->status === Organization::STATUS_VERIFIED) {
                $reopenReview = true;
            }
        }

        $org->update($validated);

        if ($reopenReview) {
            // `verified` was the platform's conclusion about the records as
            // they stood when they were read. A new registration number is a
            // different claim, so the old decision cannot describe it.
            $org->update([
                'status' => Organization::STATUS_PENDING,
                'kyc_verified_at' => null,
            ]);
        }

        return ApiResponse::success(
            $org->fresh(),
            $reopenReview
                ? 'تم حفظ الملف، وتحتاج بياناتك القانونية إلى مراجعة جديدة من المنصة'
                : 'تم حفظ ملف المنشأة'
        );
    }

    /**
     * The platform's decision about whether a business may trade.
     *
     * This action did not exist: `update()` lets a business edit its own
     * profile and nothing let the platform answer it, so a submitted business
     * stayed in `pending_kyc` forever no matter what a reviewer concluded.
     *
     * What a VERIFY means, precisely: a HASSAL reviewer accepted the records the
     * business submitted. The platform does not query a registry, a tax
     * authority or a chamber of commerce, so nothing here may be worded as an
     * official verification, and the audit row says so by naming its source.
     */
    public function verification(Request $request, int|string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['VERIFY', 'REJECT', 'SUSPEND', 'REINSTATE'])],
            'reason'   => ['nullable', 'string', 'max:500'],
        ]);

        $decision = $validated['decision'];
        $reason   = $validated['reason'] ?? null;

        // A rejection or a suspension is a decision against somebody, and an
        // unexplained one cannot be answered, appealed, or reconstructed later.
        if (in_array($decision, ['REJECT', 'SUSPEND'], true) && !$reason) {
            return ApiResponse::error(
                'يجب توضيح سبب القرار عند الرفض أو الإيقاف',
                'REASON_REQUIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['reason' => 'سبب القرار مطلوب']
            );
        }

        if ($decision === 'VERIFY') {
            $missing = $this->missingRequiredLegalFields($org);
            if ($missing !== []) {
                return ApiResponse::error(
                    'لا يمكن اعتماد المنشأة قبل استكمال البيانات النظامية المطلوبة',
                    'LEGAL_FIELDS_INCOMPLETE',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    ['missing_fields' => $missing]
                );
            }
        }

        $previousStatus = $org->status;
        $newStatus = match ($decision) {
            'VERIFY', 'REINSTATE' => Organization::STATUS_VERIFIED,
            'REJECT'              => Organization::STATUS_REJECTED,
            'SUSPEND'             => Organization::STATUS_SUSPENDED,
        };

        if ($newStatus === $previousStatus) {
            return ApiResponse::error(
                'المنشأة في هذا الحالة بالفعل',
                'DECISION_ALREADY_APPLIED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['status' => $previousStatus]
            );
        }

        // Leave the metadata untouched for businesses that have no array yet,
        // and only replace our own `kyc` key.
        $metadata = is_array($org->metadata) ? $org->metadata : [];
        $metadata['kyc'] = [
            'decision'    => $decision,
            'reason'      => $reason,
            'decided_by'  => $request->user()->id,
            'decided_at'  => now()->toIso8601String(),
            // Named so a later reader cannot mistake a platform review for an
            // authority check.
            'source'      => 'hassal_platform_review',
        ];

        $org->update([
            'status'   => $newStatus,
            'metadata' => $metadata,
            // The first approval is the timestamp that matters; reinstating a
            // suspended business does not re-date its original verification.
            'kyc_verified_at' => $org->kyc_verified_at
                ?? ($decision === 'VERIFY' ? now() : null),
        ]);

        AuditLog::create([
            'organization_id' => $org->id,
            'user_id'         => $request->user()->id,
            'action'          => 'ORGANIZATION_VERIFICATION_' . $decision,
            'auditable_type'  => Organization::class,
            'auditable_id'    => $org->id,
            'old_values'      => ['status' => $previousStatus],
            'new_values'      => ['status' => $newStatus, 'reason' => $reason],
            'ip_address'      => $request->ip(),
            'created_at'      => now(),
        ]);

        $message = match ($decision) {
            'VERIFY'    => 'تم اعتماد بيانات المنشأة بعد مراجعتها داخل منصة حصّال',
            'REJECT'    => 'تم رفض طلب اعتماد المنشأة',
            'SUSPEND'   => 'تم إيقاف المنشأة عن التداول',
            'REINSTATE' => 'تمت إعادة تفعيل المنشأة',
        };

        return ApiResponse::success($org->fresh(), $message);
    }

    /**
     * Legal identifiers the platform required but this business has not given.
     *
     * Which fields are required is a settings decision (`legal.*`), never a
     * hardcoded assumption about any one country's paperwork, so the check
     * follows whatever the operator configured.
     *
     * @return array<int, string>
     */
    protected function missingRequiredLegalFields(Organization $org): array
    {
        $missing = [];

        if ((bool) $this->settingsService->get('legal.require_commercial_registration', false)
            && blank($org->commercial_registration_number)) {
            $missing[] = 'commercial_registration_number';
        }

        if ((bool) $this->settingsService->get('legal.require_vat_number', false)
            && blank($org->vat_number)) {
            $missing[] = 'vat_number';
        }

        return $missing;
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

        $members = $org->memberships()
            ->where('is_active', true)
            ->with('user:id,name,email,phone,status')
            ->get();

        return ApiResponse::success($members, 'Organization members retrieved');
    }

    /**
     * The staffing vocabulary a screen may offer.
     *
     * Returned rather than typed into the client because the same list is what
     * the validator accepts and what the column constrains; a dropdown built
     * from a client-side copy is a 422 waiting for the next rename.
     */
    public function membershipRoles(): JsonResponse
    {
        $roles = [];

        // Each standing is published with the capabilities it is worth, because
        // the owner who appoints somebody is making a real decision with this
        // list: a dropdown that says only «المالية» cannot show that the person
        // chosen from it will not be able to move stock, and a role whose meaning
        // lives only in a config file is a role people appoint by guessing.
        foreach (OrganizationMembership::roles() as $name => $label) {
            $permissions = OrganizationMembership::permissionsFor($name);

            $roles[] = [
                'value' => $name,
                'label' => $label,
                'permission_count' => count($permissions),
                'permissions' => $permissions,
            ];
        }

        return ApiResponse::success($roles, 'Membership roles retrieved');
    }

    public function addMember(AddMemberRequest $request, int|string $id): JsonResponse
    {
        $org = Organization::findOrFail($id);
        $currentUser = $request->user();

        // Owner and admin of *this* company staff it; everyone else is refused
        // here rather than merely not shown the button.
        $role = $currentUser->roleInOrganization($org->id);
        if (!OrganizationMembership::canManage($role, $currentUser->hasRole('SuperAdmin'))) {
            return ApiResponse::error(
                'لا تملك صلاحية إضافة أعضاء إلى هذه المنشأة: الإضافة للمالك أو مدير المنشأة',
                'FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validated();
        $targetUserId = $validated['user_id'] ?? null;

        if (!$targetUserId && !empty($validated['email'])) {
            $user = User::where('email', $validated['email'])->first();
            if (!$user) {
                return ApiResponse::error('لا يوجد حساب مسجّل بهذا البريد على المنصة', 'USER_NOT_FOUND', Response::HTTP_NOT_FOUND);
            }
            $targetUserId = $user->id;
        }

        $existing = OrganizationMembership::where('organization_id', $org->id)
            ->where('user_id', $targetUserId)
            ->first();

        // Ownership outranks every admin in the company, including the caller's,
        // so moving it is not something an admin may do to somebody — or to
        // themselves by adding a co-owner and resigning. Only the current owner
        // or the platform may set or clear it.
        if ($this->touchesOwnership($validated['role'], $existing?->role)
            && !$this->mayMoveOwnership($currentUser, $org)) {
            return ApiResponse::error(
                'نقل الملكية أو منحها يكون من المالك الحالي أو من إدارة المنصة',
                'OWNERSHIP_MOVE_FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        $membership = OrganizationMembership::updateOrCreate(
            [
                'organization_id' => $org->id,
                'user_id' => $targetUserId,
            ],
            [
                'role' => $validated['role'],
                'is_active' => true,
                'joined_at' => now(),
            ]
        );

        AuditLog::create([
            'user_id' => $currentUser->id,
            'action' => $existing ? 'ORG_MEMBER_UPDATED' : 'ORG_MEMBER_ADDED',
            'auditable_type' => OrganizationMembership::class,
            'auditable_id' => $membership->id,
            'old_values' => $existing ? ['role' => $existing->role, 'is_active' => $existing->is_active] : null,
            'new_values' => ['role' => $membership->role, 'reason' => $validated['reason']],
            'organization_id' => $org->id,
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success(
            $membership->load('user:id,name,email,phone'),
            'Member added to organization successfully',
            Response::HTTP_CREATED
        );
    }

    public function updateMember(Request $request, int|string $id, int|string $userId): JsonResponse
    {
        $org = Organization::findOrFail($id);
        $currentUser = $request->user();

        $role = $currentUser->roleInOrganization($org->id);
        if (!OrganizationMembership::canManage($role, $currentUser->hasRole('SuperAdmin'))) {
            return ApiResponse::error(
                'لا تملك صلاحية تغيير أدوار الأعضاء في هذه المنشأة',
                'FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validate([
            'role' => ['required', 'string', OrganizationMembership::roleRule()],
            'is_active' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $membership = OrganizationMembership::where('organization_id', $org->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $previous = ['role' => $membership->role, 'is_active' => $membership->is_active];

        // Permission before business rules: telling an admin that the last owner
        // cannot be demoted would itself be a way of probing the roster.
        if ($this->touchesOwnership($validated['role'], $membership->role)
            && !$this->mayMoveOwnership($currentUser, $org)) {
            return ApiResponse::error(
                'نقل الملكية أو منحها يكون من المالك الحالي أو من إدارة المنصة',
                'OWNERSHIP_MOVE_FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        // A company with no active owner has no one who can appoint one, so the
        // demotion that would empty it is refused instead of logged.
        if ($this->wouldLeaveWithoutOwner($membership, $validated)) {
            return ApiResponse::error(
                'لا يمكن إزالة آخر مالك نشط للمنشأة؛ عيّن مالكًا آخر أولًا',
                'LAST_OWNER_REQUIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $membership->update([
            'role' => $validated['role'],
            ...array_key_exists('is_active', $validated) ? ['is_active' => $validated['is_active']] : [],
        ]);

        AuditLog::create([
            'user_id' => $currentUser->id,
            'action' => 'ORG_MEMBER_ROLE_CHANGED',
            'auditable_type' => OrganizationMembership::class,
            'auditable_id' => $membership->id,
            'old_values' => $previous,
            'new_values' => ['role' => $membership->role, 'is_active' => $membership->is_active, 'reason' => $validated['reason']],
            'organization_id' => $org->id,
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success($membership->load('user:id,name,email,phone'), 'Member role updated successfully');
    }

    /** Does this edit put ownership on somebody, or take it off them? */
    private function touchesOwnership(string $newRole, ?string $currentRole): bool
    {
        return $newRole !== $currentRole
            && in_array(OrganizationMembership::ROLE_OWNER, [$newRole, (string) $currentRole], true);
    }

    /**
     * Ownership is the standing that decides who can change every other
     * standing in a company, so it moves only by the decision of whoever holds
     * it, or of the platform.
     */
    private function mayMoveOwnership(User $actor, Organization $org): bool
    {
        if ($actor->hasRole('SuperAdmin')) {
            return true;
        }

        return $actor->roleInOrganization($org->id) === OrganizationMembership::ROLE_OWNER;
    }

    /** Is this edit the one that leaves the organization ownerless? */
    private function wouldLeaveWithoutOwner(OrganizationMembership $membership, array $validated): bool
    {
        if ($membership->role !== OrganizationMembership::ROLE_OWNER) {
            return false;
        }

        $staysOwner = $validated['role'] === OrganizationMembership::ROLE_OWNER
            && (bool) ($validated['is_active'] ?? true);

        if ($staysOwner) {
            return false;
        }

        return OrganizationMembership::where('organization_id', $membership->organization_id)
                ->where('id', '!=', $membership->id)
                ->where('role', OrganizationMembership::ROLE_OWNER)
                ->where('is_active', true)
                ->count() === 0;
    }

    public function removeMember(Request $request, int|string $id, int|string $userId): JsonResponse
    {
        $org = Organization::findOrFail($id);
        $currentUser = $request->user();

        $role = $currentUser->roleInOrganization($org->id);
        if (!OrganizationMembership::canManage($role, $currentUser->hasRole('SuperAdmin'))) {
            return ApiResponse::error(
                'لا تملك صلاحية إزالة أعضاء من هذه المنشأة',
                'FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        // Prevent removing the last owner
        $membership = OrganizationMembership::where('organization_id', $org->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        // Taking an owner out of the company is an ownership change as far as
        // that person is concerned, so it obeys the same rule as demoting one.
        if ($membership->role === OrganizationMembership::ROLE_OWNER
            && !$this->mayMoveOwnership($currentUser, $org)) {
            return ApiResponse::error(
                'إزالة مالك تكون بقرار منه أو من إدارة المنصة',
                'OWNERSHIP_MOVE_FORBIDDEN',
                Response::HTTP_FORBIDDEN
            );
        }

        if ($membership->role === OrganizationMembership::ROLE_OWNER) {
            $ownersCount = OrganizationMembership::where('organization_id', $org->id)
                ->where('role', OrganizationMembership::ROLE_OWNER)
                ->where('is_active', true)
                ->count();

            if ($ownersCount <= 1) {
                return ApiResponse::error(
                    'لا يمكن إزالة آخر مالك للمنشأة',
                    'CANNOT_REMOVE_LAST_OWNER',
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        $membership->update(['is_active' => false]);

        AuditLog::create([
            'user_id' => $currentUser->id,
            'action' => 'ORG_MEMBER_REMOVED',
            'auditable_type' => OrganizationMembership::class,
            'auditable_id' => $membership->id,
            'old_values' => ['role' => $membership->role, 'is_active' => true],
            'new_values' => ['is_active' => false, 'reason' => $validated['reason']],
            'organization_id' => $org->id,
            'ip_address' => $request->ip(),
        ]);

        return ApiResponse::success(null, 'Member deactivated and removed from active roster');
    }

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

        if ($user->hasRole('SuperAdmin')) {
            return true;
        }

        return $organization->memberships()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }
}
