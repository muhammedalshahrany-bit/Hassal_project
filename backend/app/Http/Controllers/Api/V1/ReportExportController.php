<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Deal;
use App\Models\LedgerEntry;
use App\Models\Offer;
use App\Models\Organization;
use App\Services\TenantContext;
use App\Services\SystemSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __construct(protected SystemSettingsService $settingsService) {}

    public function salesSummary(Request $request): JsonResponse
    {
        $orgId = $this->scopedOrganizationId($request);
        if ($orgId === false) {
            return ApiResponse::error('No organization context for this report.', 'NO_ORGANIZATION_CONTEXT', 403);
        }

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        $dealsQuery = Deal::query();
        if ($orgId !== null) {
            $dealsQuery->where(function ($q) use ($orgId) {
                $q->where('seller_organization_id', $orgId)
                  ->orWhere('buyer_organization_id', $orgId);
            });
        }

        // The window is applied to the query and named in the answer. A total
        // that silently covers every deal reads as the period's total.
        if (!empty($validated['from'])) {
            $dealsQuery->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay());
        }
        if (!empty($validated['to'])) {
            $dealsQuery->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay());
        }

        $totalDeals = $dealsQuery->count();
        $completedDeals = (clone $dealsQuery)->whereIn('status', [Deal::STATUS_COMPLETED, Deal::STATUS_SETTLED])->count();
        $totalGrossVolume = (clone $dealsQuery)->whereIn('status', [Deal::STATUS_COMPLETED, Deal::STATUS_SETTLED])->sum('buyer_total_amount');
        $totalPlatformFees = (clone $dealsQuery)->whereIn('status', [Deal::STATUS_COMPLETED, Deal::STATUS_SETTLED])->sum('platform_fee_amount');

        return ApiResponse::success([
            'total_deals_count' => $totalDeals,
            'completed_deals_count' => $completedDeals,
            // Settled states only: an agreed deal whose goods never moved is not turnover.
            'counts_completed_and_settled_only' => true,
            'gross_merchandise_value' => (float)$totalGrossVolume,
            'platform_commission_collected' => (float)$totalPlatformFees,
            'period_from' => $validated['from'] ?? null,
            'period_to' => $validated['to'] ?? null,
            // Which date the window cut on. A period is meaningless unless the
            // reader knows whether it is the deal's creation or its settlement.
            'period_field' => 'created_at',
            // null rather than a guessed currency: an unconfigured platform
            // must not print one on a financial report.
            'currency' => $this->settingsService->get('platform.default_currency'),
            'generated_at' => now()->toIso8601String(),
        ], 'Sales summary report generated');
    }

    public function exportDealsCsv(Request $request): StreamedResponse|JsonResponse
    {
        // Resolved before the first byte: once a stream starts there is no
        // way to answer with a 403.
        $orgId = $this->scopedOrganizationId($request);
        if ($orgId === false) {
            return ApiResponse::error('No organization context for this export.', 'NO_ORGANIZATION_CONTEXT', 403);
        }

        // Validated before streaming for the same reason: a bad window must
        // answer as JSON, not as a truncated CSV body.
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="hassal_deals_export_' . date('Ymd_His') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($orgId, $validated) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Excel Arabic support
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // An export with no visible window reads as an all-time export.
            if (!empty($validated['from']) || !empty($validated['to'])) {
                fputcsv($file, [
                    'Period from', $validated['from'] ?? '',
                    'Period to', $validated['to'] ?? '',
                ]);
            }

            fputcsv($file, [
                'Deal Number', 'Status', 'Buyer Org', 'Seller Org', 'Quantity', 'Unit Price',
                'Currency', 'Total Amount', 'Commission', 'Created At'
            ]);

            $query = Deal::with(['buyerOrganization:id,name_ar,name_en', 'sellerOrganization:id,name_ar,name_en']);
            if ($orgId !== null) {
                $query->where(function ($q) use ($orgId) {
                    $q->where('seller_organization_id', $orgId)->orWhere('buyer_organization_id', $orgId);
                });
            }

            if (!empty($validated['from'])) {
                $query->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay());
            }
            if (!empty($validated['to'])) {
                $query->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay());
            }

            $query->chunk(100, function ($deals) use ($file) {
                foreach ($deals as $deal) {
                    fputcsv($file, [
                        $deal->deal_number,
                        $deal->status,
                        $deal->buyerOrganization?->name_ar ?? 'N/A',
                        $deal->sellerOrganization?->name_ar ?? 'N/A',
                        $deal->quantity,
                        $deal->unit_price,
                        $deal->currency_code,
                        $deal->buyer_total_amount,
                        $deal->platform_fee_amount,
                        $deal->created_at?->toIso8601String(),
                    ]);
                }
            });

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Organization scope for reporting.
     *
     * @return int|null|false  null = platform-wide (SuperAdmin only),
     *                          int  = that organization,
     *                          false = refuse; no scope could be established.
     */
    protected function scopedOrganizationId(Request $request): int|null|false
    {
        if ($request->user()?->hasRole('SuperAdmin')) {
            return null;
        }

        return TenantContext::id() ?? false;
    }
}
