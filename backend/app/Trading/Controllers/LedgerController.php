<?php

namespace App\Trading\Controllers;

use App\Core\Http\Controllers\Controller;
use App\Core\Http\ApiResponse;
use App\Trading\Models\JournalEntry;
use App\Trading\Models\LedgerAccount;
use App\Trading\Services\DoubleEntryLedgerService;
use App\Core\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LedgerController extends Controller
{
    public function __construct(
        protected DoubleEntryLedgerService $ledgerService
    ) {}

    /**
     * Lists journal entries (Admin / Financial Auditor or Organization-scoped).
     */
    public function journalEntries(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = JournalEntry::with(['lines.account', 'lines.organization', 'createdByUser']);

        if (!$user->isPlatformOperator()) {
            $orgId = TenantContext::id();
            if (!$orgId) {
                return ApiResponse::error('Active organization required', 'TENANT_REQUIRED', Response::HTTP_FORBIDDEN);
            }

            $query->whereHas('lines', function ($l) use ($orgId) {
                $l->where('organization_id', $orgId);
            });
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        $entries = $query->orderBy('posted_at', 'desc')->paginate(20);

        return ApiResponse::paginated($entries, 'Journal entries retrieved successfully');
    }

    /**
     * Lists chart of accounts and current balances.
     */
    public function accounts(Request $request): JsonResponse
    {
        $this->ledgerService->ensureChartOfAccounts();

        $user = $request->user();
        $query = LedgerAccount::query();

        if (!$user->isPlatformOperator()) {
            $orgId = TenantContext::id();
            $query->where(function ($q) use ($orgId) {
                $q->whereNull('organization_id')->orWhere('organization_id', $orgId);
            });
        }

        $accounts = $query->orderBy('account_code')->get();

        return ApiResponse::success($accounts, 'Chart of accounts retrieved successfully');
    }

    /**
     * Generates a Trial Balance summary verifying Total Debits = Total Credits.
     */
    public function trialBalance(): JsonResponse
    {
        $this->ledgerService->ensureChartOfAccounts();

        $accounts = LedgerAccount::all();
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        $rows = [];
        foreach ($accounts as $acc) {
            $bal = (float)$acc->balance;
            $debit = 0.0;
            $credit = 0.0;

            if ($acc->type === LedgerAccount::TYPE_ASSET || $acc->type === LedgerAccount::TYPE_EXPENSE) {
                if ($bal >= 0) $debit = $bal;
                else $credit = abs($bal);
            } else {
                if ($bal >= 0) $credit = $bal;
                else $debit = abs($bal);
            }

            $totalDebit += $debit;
            $totalCredit += $credit;

            $rows[] = [
                'account_code' => $acc->account_code,
                'name_ar' => $acc->name_ar,
                'name_en' => $acc->name_en,
                'type' => $acc->type,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
            ];
        }

        $isBalanced = abs($totalDebit - $totalCredit) < 0.01;

        return ApiResponse::success([
            'accounts' => $rows,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'is_balanced' => $isBalanced,
            'generated_at' => now()->toIso8601String(),
        ], 'Trial balance generated successfully');
    }
}
