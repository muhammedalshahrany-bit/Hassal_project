<?php

namespace App\Organizations\Services;

use App\Core\Models\AuditLog;
use App\Core\Exceptions\ApiRefused;
use App\Organizations\Models\Organization;
use App\Identity\Models\User;
use App\Platform\Services\SystemSettingsService;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform's decisions about whether a business may trade, and the one
 * indirect way that decision gets reopened by the business itself.
 *
 * What a VERIFY means, precisely: a HASSAL reviewer accepted the records the
 * business submitted. The platform does not query a registry, a tax authority
 * or a chamber of commerce, so nothing here may be worded as an official
 * verification, and the audit row says so by naming its source.
 */
class OrganizationVerificationService
{
    public function __construct(
        protected SystemSettingsService $settingsService,
    ) {}

    /**
     * Record VERIFY / REJECT / SUSPEND / REINSTATE against one business.
     *
     * @return array{organization: Organization, message: string}
     *
     * @throws ApiRefused
     */
    public function decide(Organization $org, string $decision, ?string $reason, User $decidedBy, string $ip): array
    {
        // A rejection or a suspension is a decision against somebody, and an
        // unexplained one cannot be answered, appealed, or reconstructed later.
        if (in_array($decision, ['REJECT', 'SUSPEND'], true) && !$reason) {
            throw new ApiRefused(
                'يجب توضيح سبب القرار عند الرفض أو الإيقاف',
                'REASON_REQUIRED',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['reason' => 'سبب القرار مطلوب']
            );
        }

        if ($decision === 'VERIFY') {
            $missing = $this->missingRequiredLegalFields($org);
            if ($missing !== []) {
                throw new ApiRefused(
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
            throw new ApiRefused(
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
            'decided_by'  => $decidedBy->id,
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
            'user_id'         => $decidedBy->id,
            'action'          => 'ORGANIZATION_VERIFICATION_' . $decision,
            'auditable_type'  => Organization::class,
            'auditable_id'    => $org->id,
            'old_values'      => ['status' => $previousStatus],
            'new_values'      => ['status' => $newStatus, 'reason' => $reason],
            'ip_address'      => $ip,
            'created_at'      => now(),
        ]);

        $message = match ($decision) {
            'VERIFY'    => 'تم اعتماد بيانات المنشأة بعد مراجعتها داخل منصة حصّال',
            'REJECT'    => 'تم رفض طلب اعتماد المنشأة',
            'SUSPEND'   => 'تم إيقاف المنشأة عن التداول',
            'REINSTATE' => 'تمت إعادة تفعيل المنشأة',
        };

        return ['organization' => $org, 'message' => $message];
    }

    /**
     * Save a profile edit, and answer whether it reopened the review.
     *
     * `verified` was the platform's conclusion about the records as they stood
     * when they were read. A new registration number is a different claim, so
     * the old decision cannot describe it — sending the field unchanged is not
     * a change, and nulls are compared as "not provided" so a form that echoes
     * an empty field back does not reopen a decision that was never disturbed.
     *
     * @param array<string, mixed> $validated
     */
    public function applyProfileEdit(Organization $org, array $validated): bool
    {
        $reopenReview = false;

        foreach (['commercial_registration_number', 'vat_number'] as $field) {
            if (!array_key_exists($field, $validated)) {
                continue;
            }

            $incoming = $validated[$field];
            $stored = $org->{$field};

            if (($incoming === null ? null : (string) $incoming) === ($stored === null ? null : (string) $stored)) {
                continue;
            }

            if ($org->status === Organization::STATUS_VERIFIED) {
                $reopenReview = true;
            }
        }

        $org->update($validated);

        if ($reopenReview) {
            $org->update([
                'status' => Organization::STATUS_PENDING,
                'kyc_verified_at' => null,
            ]);
        }

        return $reopenReview;
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
    public function missingRequiredLegalFields(Organization $org): array
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
}
