<?php

namespace App\Organizations\Services;

use App\Core\Exceptions\ApiRefused;
use App\Organizations\Models\Organization;
use App\Organizations\Models\OrganizationMembership;
use App\Organizations\Models\OrganizationRole;
use App\Identity\Models\User;
use App\Platform\Services\SystemSettingsService;
use App\Auth\Support\AccountRequirements;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Founding a business, and the rules only that door can apply.
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
 * — the switch an operator sets, default on — is what keeps it from publishing
 * offers or signing deals until a reviewer has read the documents.
 */
class OrganizationRegistrationService
{
    public function __construct(
        protected SystemSettingsService $settingsService,
        protected AccountRequirements $accountRequirements,
    ) {}

    /**
     * @param array<string, mixed> $validated the (already field-validated) payload
     *
     * @throws ApiRefused
     */
    public function found(User $user, array $validated): Organization
    {
        if (!$user->isPlatformOperator()) {
            $missing = $this->accountRequirements->missingFields($user);

            if ($missing !== []) {
                throw new ApiRefused(
                    'أكمل بيانات حسابك قبل تسجيل منشأة: ' . implode('، ', array_map(
                        self::labelForAccountField(...),
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
                    throw new ApiRefused(
                        "وصل هذا الحساب إلى الحد المسموح له بإنشائه من منشآت ({$ceiling}). تواصل مع إدارة المنصة إذا كانت لك منشأة أخرى.",
                        'ORGANIZATION_CREATION_LIMIT_REACHED',
                        Response::HTTP_FORBIDDEN,
                        ['limit' => $ceiling, 'founded' => $founded]
                    );
                }
            }
        }

        return DB::transaction(function () use ($validated, $user) {
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

            // A company is born with the standings its owner will staff from, in
            // this transaction, because a membership naming `owner` with no
            // `owner` row behind it is read against the platform's defaults — the
            // right answer, but only until somebody edits the company's own matrix
            // and finds the standing they edited is not the one in use. Same
            // reason the seeder provisions: the rows are the source of truth from
            // the first moment the company can have one.
            OrganizationRole::provisionOrganization($org->id);

            return $org;
        });
    }

    /**
     * The Arabic name of a required account field, for the sentence that asks
     * for it. Field keys travel in the response for the client to branch on;
     * the words are for the person reading it.
     */
    public static function labelForAccountField(string $field): string
    {
        return match ($field) {
            AccountRequirements::FIELD_PHONE => 'رقم الهاتف',
            AccountRequirements::FIELD_NATIONAL_ID => 'الرقم الوطني أو المدني',
            default => $field,
        };
    }
}
