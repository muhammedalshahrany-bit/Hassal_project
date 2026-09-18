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
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __construct(protected SystemSettingsService $settingsService) {}

    public function salesSummary(Request $request): JsonResponse
    {
        $orgId = TenantContext::id();

        $dealsQuery = Deal::query();
        if (!$request->user()->hasRole('SuperAdmin') && $orgId) {
            $dealsQuery->where(function ($q) use ($orgId) {
                $q->where('seller_organization_id', $orgId)
                  ->orWhere('buyer_organization_id', $orgId);
            });
        }

        $totalDeals = $dealsQuery->count();
        $completedDeals = (clone $dealsQuery)->whereIn('status', [Deal::STATUS_COMPLETED, Deal::STATUS_SETTLED])->count();
        $totalGrossVolume = (clone $dealsQuery)->whereIn('status', [Deal::STATUS_COMPLETED, Deal::STATUS_SETTLED])->sum('buyer_total_amount');
        $totalPlatformFees = (clone $dealsQuery)->whereIn('status', [Deal::STATUS_COMPLETED, Deal::STATUS_SETTLED])->sum('platform_fee_amount');

        return ApiResponse::success([
            'total_deals_count' => $totalDeals,
            'completed_deals_count' => $completedDeals,
            'gross_merchandise_value' => (float)$totalGrossVolume,
            'platform_commission_collected' => (float)$totalPlatformFees,
            'currency' => $this->settingsService->get('platform.default_currency', 'YER'),
            'generated_at' => now()->toIso8601String(),
        ], 'Sales summary report generated');
    }

    public function exportDealsCsv(Request $request): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="hassal_deals_export_' . date('Ymd_His') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($request) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM for Excel Arabic support
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($file, [
                'Deal Number', 'Status', 'Buyer Org', 'Seller Org', 'Quantity', 'Unit Price',
                'Currency', 'Total Amount', 'Commission', 'Created At'
            ]);

            $query = Deal::with(['buyerOrganization:id,name_ar,name_en', 'sellerOrganization:id,name_ar,name_en']);
            $orgId = TenantContext::id();
            if (!$request->user()->hasRole('SuperAdmin') && $orgId) {
                $query->where(function ($q) use ($orgId) {
                    $q->where('seller_organization_id', $orgId)->orWhere('buyer_organization_id', $orgId);
                });
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
}
