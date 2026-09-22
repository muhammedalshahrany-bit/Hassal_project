<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\Organization;
use App\Models\OrganizationDocument;
use App\Services\SystemSettingsService;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a commercial action from a business nobody has reviewed yet.
 *
 * Why this is a gate and not a hidden button: an offer, a purchase request or
 * a deal is a commercial claim made by a named company. If the platform has
 * not read that company's registration papers, the counterparty is being asked
 * to trust a string somebody typed, and the marketplace is the one that
 * advertised it. `§36` in the product brief is the general rule — hiding the
 * action in the client is a UI preference, this is the control.
 *
 * What stays open before review, deliberately: browsing, the organization's
 * own profile, filing documents, inviting members and managing a warehouse.
 * An applicant cannot complete verification from a locked account, and a
 * marketplace that blocks onboarding in order to enforce onboarding ends up
 * with no supply at all.
 *
 * The switch (`marketplace.require_verified_to_trade`) is a setting an operator
 * edits, not a constant here, and SuperAdmin is exempt: platform staff act on
 * listings that belong to other businesses as their job.
 *
 * "Verified" means a member of this platform's staff accepted the filed
 * documents. No official register is consulted anywhere in this codebase, so
 * nothing in this response may say otherwise, and neither may the client.
 */
class EnsureVerifiedOrganization
{
    public function __construct(protected SystemSettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = TenantContext::get();

        // No organization in context is not this gate's problem to solve:
        // `EnsureTenantContext`-style checks and each controller decide what a
        // tenant-less request may do, and refusing here would turn a 403 with
        // the wrong explanation into a 403 with a stranger one.
        if ($organization === null) {
            return $next($request);
        }

        if ($request->user()?->hasRole('SuperAdmin')) {
            return $next($request);
        }

        if (!$this->requiresVerifiedOrganization()) {
            return $next($request);
        }

        if ($organization->status === Organization::STATUS_VERIFIED) {
            return $next($request);
        }

        return ApiResponse::error(
            $this->messageFor($organization->status),
            'ORGANIZATION_NOT_VERIFIED',
            Response::HTTP_FORBIDDEN,
            [
                'required_action'     => 'COMPLETE_ORGANIZATION_VERIFICATION',
                'organization_status' => $organization->status,
                'checklist'           => $this->checklistFor($organization),
            ]
        );
    }

    private function requiresVerifiedOrganization(): bool
    {
        $value = $this->settings->get('marketplace.require_verified_to_trade', '1');

        // The row is seeded as the string '1'; a hand-edited row can hold any
        // of the shapes a boolean setting takes, so the truth test is written
        // out rather than trusting a cast.
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function checklistFor(Organization $organization): array
    {
        $documents = OrganizationDocument::query()->where('organization_id', $organization->id);

        $requiresRegistration = filter_var(
            $this->settings->get('legal.require_commercial_registration', '0'),
            FILTER_VALIDATE_BOOLEAN
        );
        $requiresTaxNumber = filter_var(
            $this->settings->get('legal.require_vat_number', '0'),
            FILTER_VALIDATE_BOOLEAN
        );

        return [
            [
                'key'      => 'commercial_registration_number',
                'label_ar' => 'رقم السجل التجاري',
                'required' => $requiresRegistration,
                'done'     => filled($organization->commercial_registration_number),
            ],
            [
                'key'      => 'vat_number',
                'label_ar' => 'الرقم الضريبي',
                'required' => $requiresTaxNumber,
                'done'     => filled($organization->vat_number),
            ],
            [
                'key'      => 'documents',
                'label_ar' => 'وثائق مرفوعة',
                'required' => true,
                'done'     => (clone $documents)->exists(),
            ],
            [
                'key'      => 'accepted_documents',
                'label_ar' => 'وثائق قبلها فريق المنصة',
                'required' => true,
                'done'     => (clone $documents)->where('status', OrganizationDocument::STATUS_ACCEPTED)->exists(),
            ],
        ];
    }

    private function messageFor(string $status): string
    {
        // `rejected` says a reviewer looked and refused; `pending_kyc` says
        // nobody has looked yet. Those are different things to tell a user and
        // different jobs for support, so the refusal does not flatten them.
        return match ($status) {
            Organization::STATUS_REJECTED => 'رفض فريق المنصة وثائق هذه المنشأة. راجع السبب وأعد التقديم.',
            Organization::STATUS_SUSPENDED => 'هذه المنشأة موقوفة عن التداول.',
            default => 'لم تُراجع وثائق هذه المنشأة بعد. التصفح وإكمال الملف ورفع الوثائق متاح، أما النشر والشراء والصفقات فبعد المراجعة.',
        };
    }
}
