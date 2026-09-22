<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Deal;
use App\Models\Escrow;
use App\Models\LedgerLine;
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

        // 2. Posted side check: every debit must have a matching credit.
        //    `ledger_accounts` stores one signed `balance` column, so there is
        //    no separate debit/credit balance to compare; the posted sides on
        //    the journal lines are what prove double entry.
        $totalDebits = (float) LedgerLine::sum('debit_amount');
        $totalCredits = (float) LedgerLine::sum('credit_amount');
        $imbalance = round($totalDebits - $totalCredits, 4);

        if (abs($imbalance) > 0.0001) {
            $exceptions[] = [
                'type' => 'RECONCILIATION_EXCEPTION',
                'entity' => 'General Ledger',
                'severity' => 'CRITICAL',
                'issue' => 'Trial balance imbalance detected between total debits and credits',
                'total_debits' => $totalDebits,
                'total_credits' => $totalCredits,
                'difference' => abs($imbalance),
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
