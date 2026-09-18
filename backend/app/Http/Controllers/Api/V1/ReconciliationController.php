<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Deal;
use App\Models\Escrow;
use App\Models\LedgerAccount;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function audit(): JsonResponse
    {
        $exceptions = [];

        // 1. Escrow vs Deal Status Mismatch Check
        $deals = Deal::with('escrow')->whereIn('status', [
            Deal::STATUS_ESCROW_FUNDED,
            Deal::STATUS_PROCESSING,
            Deal::STATUS_DISPATCHED,
            Deal::STATUS_DELIVERED,
            Deal::STATUS_INSPECTION,
        ])->get();

        foreach ($deals as $deal) {
            if (!$deal->escrow || $deal->escrow->status !== Escrow::STATUS_HELD) {
                $exceptions[] = [
                    'type' => 'RECONCILIATION_EXCEPTION',
                    'entity' => 'Deal #' . $deal->deal_number,
                    'severity' => 'HIGH',
                    'issue' => 'Deal is in active post-payment state but Escrow is not in HELD state',
                    'deal_status' => $deal->status,
                    'escrow_status' => $deal->escrow?->status ?? 'MISSING',
                ];
            }
        }

        // 2. Ledger Accounts Balance Check (Total Debits == Total Credits)
        $accounts = LedgerAccount::all();
        $totalDebits = $accounts->sum('current_debit');
        $totalCredits = $accounts->sum('current_credit');
        $imbalance = abs($totalDebits - $totalCredits);

        if ($imbalance > 0.0001) {
            $exceptions[] = [
                'type' => 'RECONCILIATION_EXCEPTION',
                'entity' => 'General Ledger',
                'severity' => 'CRITICAL',
                'issue' => 'Trial balance imbalance detected between total debits and credits',
                'total_debits' => $totalDebits,
                'total_credits' => $totalCredits,
                'difference' => $imbalance,
            ];
        }

        // 3. Paid Payments vs Escrow Funded Amounts
        $escrows = Escrow::where('status', Escrow::STATUS_HELD)->get();
        foreach ($escrows as $escrow) {
            $paidTotal = Payment::where('deal_id', $escrow->deal_id)
                ->where('status', Payment::STATUS_PAID)
                ->sum('amount');

            if (abs((float)$paidTotal - (float)$escrow->amount) > 0.0001) {
                $exceptions[] = [
                    'type' => 'RECONCILIATION_EXCEPTION',
                    'entity' => 'Escrow #' . $escrow->id,
                    'severity' => 'HIGH',
                    'issue' => 'Escrow held amount does not match sum of settled payments',
                    'escrow_amount' => (float)$escrow->amount,
                    'paid_sum' => (float)$paidTotal,
                ];
            }
        }

        return ApiResponse::success([
            'reconciliation_status' => count($exceptions) === 0 ? 'BALANCED' : 'EXCEPTIONS_FOUND',
            'exceptions_count' => count($exceptions),
            'exceptions' => $exceptions,
            'summary' => [
                'total_debits' => $totalDebits,
                'total_credits' => $totalCredits,
                'escrow_held_count' => $escrows->count(),
                'active_deals_audited' => $deals->count(),
                'timestamp' => now()->toIso8601String(),
            ],
        ], 'Financial reconciliation audit completed');
    }
}
