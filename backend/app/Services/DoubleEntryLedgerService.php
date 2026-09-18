<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\LedgerLine;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DoubleEntryLedgerService
{
    // Standard Account Codes
    public const ACC_CASH_CLEARING = '1010-CASH-CLEARING';
    public const ACC_ESCROW_HOLDING = '2010-ESCROW-HOLDING';
    public const ACC_SELLER_PAYABLE = '2020-SELLER-PAYABLE';
    public const ACC_VAT_OUTPUT = '2030-VAT-OUTPUT';
    public const ACC_COMMISSION_REVENUE = '4010-COMMISSION-REVENUE';
    public const ACC_BUYER_RECEIVABLE = '1020-BUYER-RECEIVABLE';

    /**
     * Finds or creates a standard ledger account.
     */
    public function getOrCreateAccount(string $accountCode, string $nameAr, string $nameEn, string $type, ?int $orgId = null, string $currencyCode = 'YER'): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['account_code' => $accountCode],
            [
                'name_ar' => $nameAr,
                'name_en' => $nameEn,
                'type' => $type,
                'organization_id' => $orgId,
                'currency_code' => $currencyCode,
                'balance' => 0.0000,
                'is_active' => true,
            ]
        );
    }

    /**
     * Initializes default chart of accounts.
     */
    public function ensureChartOfAccounts(): void
    {
        $this->getOrCreateAccount(self::ACC_CASH_CLEARING, 'حساب وسيط النقد والمدفوعات', 'Cash Clearing Account', LedgerAccount::TYPE_ASSET);
        $this->getOrCreateAccount(self::ACC_ESCROW_HOLDING, 'حساب أمانات الضمان المالي', 'Escrow Holding Liability', LedgerAccount::TYPE_LIABILITY);
        $this->getOrCreateAccount(self::ACC_SELLER_PAYABLE, 'مستحقات البائعين الدائنة', 'Seller Accounts Payable', LedgerAccount::TYPE_LIABILITY);
        $this->getOrCreateAccount(self::ACC_VAT_OUTPUT, 'ضريبة القيمة المضافة المستحقة', 'VAT Output Liability', LedgerAccount::TYPE_LIABILITY);
        $this->getOrCreateAccount(self::ACC_COMMISSION_REVENUE, 'إيرادات عمولة المنصة', 'Platform Commission Revenue', LedgerAccount::TYPE_REVENUE);
        $this->getOrCreateAccount(self::ACC_BUYER_RECEIVABLE, 'مستحقات المشترين المدينة', 'Buyer Accounts Receivable', LedgerAccount::TYPE_ASSET);
    }

    /**
     * Posts a balanced double-entry journal entry with debit and credit lines.
     */
    public function postJournalEntry(
        string $description,
        array $lines, // array of ['account_code' => ..., 'debit' => ..., 'credit' => ..., 'org_id' => ..., 'memo' => ...]
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $txRef = null,
        string $currencyCode = 'YER',
        ?User $user = null
    ): JournalEntry {
        $this->ensureChartOfAccounts();

        return DB::transaction(function () use ($description, $lines, $entityType, $entityId, $txRef, $currencyCode, $user) {
            $totalDebit = 0.0000;
            $totalCredit = 0.0000;

            foreach ($lines as $line) {
                $totalDebit += (float)($line['debit'] ?? 0);
                $totalCredit += (float)($line['credit'] ?? 0);
            }

            $totalDebit = round($totalDebit, 4);
            $totalCredit = round($totalCredit, 4);

            if (abs($totalDebit - $totalCredit) > 0.0001) {
                throw new Exception("Double-entry journal is unbalanced! Total Debit ({$totalDebit}) does not equal Total Credit ({$totalCredit})");
            }

            if ($totalDebit <= 0.0000) {
                throw new Exception("Journal entry must have a non-zero transaction amount.");
            }

            $entryNumber = 'JRN-' . date('Ymd') . '-' . strtoupper(Str::random(6));

            $journalEntry = JournalEntry::create([
                'entry_number' => $entryNumber,
                'transaction_reference' => $txRef,
                'currency_code' => $currencyCode,
                'exchange_rate_to_base' => 1.000000,
                'description' => $description,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'created_by_user_id' => $user?->id,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'posted_at' => Carbon::now(),
                'is_reversal' => false,
            ]);

            foreach ($lines as $line) {
                $accountCode = $line['account_code'];
                $debit = round((float)($line['debit'] ?? 0), 4);
                $credit = round((float)($line['credit'] ?? 0), 4);
                $orgId = $line['org_id'] ?? null;
                $memo = $line['memo'] ?? null;

                $account = LedgerAccount::where('account_code', $accountCode)->lockForUpdate()->firstOrFail();

                LedgerLine::create([
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $account->id,
                    'organization_id' => $orgId,
                    'debit_amount' => $debit,
                    'credit_amount' => $credit,
                    'memo' => $memo,
                ]);

                // Update account balance based on normal balance rules
                $netChange = ($account->type === LedgerAccount::TYPE_ASSET || $account->type === LedgerAccount::TYPE_EXPENSE)
                    ? ($debit - $credit)
                    : ($credit - $debit);

                $account->update(['balance' => round((float)$account->balance + $netChange, 4)]);
            }

            return $journalEntry;
        });
    }

    /**
     * Records Escrow Funding when Buyer deposits funds for a deal.
     */
    public function recordEscrowFunding(Deal $deal, ?User $user = null): JournalEntry
    {
        $amount = (float)$deal->buyer_total_amount;

        $lines = [
            [
                'account_code' => self::ACC_CASH_CLEARING,
                'debit' => $amount,
                'credit' => 0.0000,
                'org_id' => $deal->buyer_organization_id,
                'memo' => "Escrow deposit from Buyer Org #{$deal->buyer_organization_id} for Deal #{$deal->deal_number}",
            ],
            [
                'account_code' => self::ACC_ESCROW_HOLDING,
                'debit' => 0.0000,
                'credit' => $amount,
                'org_id' => $deal->buyer_organization_id,
                'memo' => "Escrow holding liability for Deal #{$deal->deal_number}",
            ],
        ];

        return $this->postJournalEntry(
            "تمويل الضمان المالي للصفقة {$deal->deal_number}",
            $lines,
            'Deal',
            $deal->id,
            $deal->deal_number,
            $deal->currency_code,
            $user
        );
    }

    /**
     * Records Final Deal Settlement: Releases escrow to Seller, recognizes Platform Commission Revenue & VAT Output.
     */
    public function recordDealSettlement(Deal $deal, ?User $user = null): JournalEntry
    {
        $totalEscrow = (float)$deal->buyer_total_amount;
        $sellerNet = (float)$deal->seller_net_amount;
        $platformFee = (float)$deal->platform_fee_amount;
        $taxAmount = (float)$deal->tax_amount;
        $deliveryFee = (float)$deal->delivery_fee_amount;

        // Seller receives Net Amount + Delivery Fee (if delivered by seller)
        $sellerReceivable = $sellerNet + $deliveryFee;

        $lines = [
            [
                'account_code' => self::ACC_ESCROW_HOLDING,
                'debit' => $totalEscrow,
                'credit' => 0.0000,
                'org_id' => $deal->seller_organization_id,
                'memo' => "Release escrow holding for Deal #{$deal->deal_number}",
            ],
            [
                'account_code' => self::ACC_SELLER_PAYABLE,
                'debit' => 0.0000,
                'credit' => $sellerReceivable,
                'org_id' => $deal->seller_organization_id,
                'memo' => "Payable to Seller Org #{$deal->seller_organization_id} for Deal #{$deal->deal_number}",
            ],
            [
                'account_code' => self::ACC_COMMISSION_REVENUE,
                'debit' => 0.0000,
                'credit' => $platformFee,
                'org_id' => null,
                'memo' => "Platform commission fee revenue for Deal #{$deal->deal_number}",
            ],
            [
                'account_code' => self::ACC_VAT_OUTPUT,
                'debit' => 0.0000,
                'credit' => $taxAmount,
                'org_id' => null,
                'memo' => "VAT output liability for Deal #{$deal->deal_number}",
            ],
        ];

        return $this->postJournalEntry(
            "تسوية الحسابات والإفراج عن مستحقات الصفقة {$deal->deal_number}",
            $lines,
            'Deal',
            $deal->id,
            $deal->deal_number,
            $deal->currency_code,
            $user
        );
    }

    /**
     * Records Escrow Refund back to Buyer in case of dispute or cancellation.
     */
    public function recordEscrowRefund(Deal $deal, float $refundAmount, ?User $user = null, ?string $reason = null): JournalEntry
    {
        $lines = [
            [
                'account_code' => self::ACC_ESCROW_HOLDING,
                'debit' => $refundAmount,
                'credit' => 0.0000,
                'org_id' => $deal->buyer_organization_id,
                'memo' => "Refund debit from escrow for Deal #{$deal->deal_number}",
            ],
            [
                'account_code' => self::ACC_CASH_CLEARING,
                'debit' => 0.0000,
                'credit' => $refundAmount,
                'org_id' => $deal->buyer_organization_id,
                'memo' => "Cash refund payable to Buyer Org #{$deal->buyer_organization_id}. Reason: {$reason}",
            ],
        ];

        return $this->postJournalEntry(
            "استرداد أموال الضمان للمشتري للصفقة {$deal->deal_number}",
            $lines,
            'Deal',
            $deal->id,
            $deal->deal_number,
            $deal->currency_code,
            $user
        );
    }
}
