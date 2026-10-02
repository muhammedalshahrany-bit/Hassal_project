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
    /*
     * The platform's own chart of accounts, and it is two accounts wide.
     *
     * HASSAL never holds anybody's money: the buyer pays the seller outside the
     * platform, and there is no wallet, no gateway and no holding account here.
     * What the platform does have is one real commercial claim — the commission
     * it earned on a deal that completed, which the seller owes it. Accrual
     * accounting says that claim exists the moment the deal is closed and
     * settled, whatever hour the bank transfer happens afterwards, so the book
     * records a receivable and the revenue it stands for.
     *
     * This used to hold six accounts, including a cash-clearing and an
     * escrow-holding account, because the service was written to post money
     * moving through the platform. Every one of those postings described a
     * transfer that cannot happen here, and one of them credited the VAT a
     * buyer paid on the *goods* to the platform's own tax liability, as if the
     * platform were the merchant. Removing the impossible entries is what left
     * these two.
     *
     * Note also what is *not* here: no VAT-output account. The deal snapshot
     * stores the tax charged on the goods, which is the seller's liability to
     * the tax authority and not something this book may recognise as its own.
     * A commission-VAT account would only be honest once the pricing service
     * computed a tax on the fee, and it does not.
     */
    public const ACC_FEE_RECEIVABLE = '1010-FEE-RECEIVABLE';
    public const ACC_COMMISSION_REVENUE = '4010-COMMISSION-REVENUE';

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
        $this->getOrCreateAccount(self::ACC_FEE_RECEIVABLE, 'مستحقات المنصة المدينة من العمولات', 'Platform Commission Receivable', LedgerAccount::TYPE_ASSET);
        $this->getOrCreateAccount(self::ACC_COMMISSION_REVENUE, 'إيرادات عمولة المنصة', 'Platform Commission Revenue', LedgerAccount::TYPE_REVENUE);
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

            // Lock the accounts this entry touches, in one fixed order, before
            // anything is written.
            //
            // The lines arrive in whatever order the caller composed them, and
            // two entries that touch the same pair of accounts in opposite
            // orders are the textbook deadlock: session A holds `1100` and waits
            // for `4000`, session B holds `4000` and waits for `1100`, and MySQL
            // kills one of them. Two commissions posted against the same
            // receivable and revenue accounts in the same second are enough to
            // hit it, and which half of a posting wins is not something a
            // ledger should leave to the deadlock detector.
            //
            // Sorting by the account's own primary key rather than by its code
            // means the order is a property of the rows, not of how a caller
            // spelled them, and every service that ever locks accounts can reach
            // the same sequence by reading `LockOrder::ACCOUNTS`.
            $this->lockAccounts(array_column($lines, 'account_code'));

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

                // Already locked, in `LockOrder` sequence, by `lockAccounts()`
                // above. Re-locking here in the caller's line order is what the
                // sort exists to prevent, so this reads the row it needs without
                // asking for a lock it already holds.
                $account = LedgerAccount::where('account_code', $accountCode)->firstOrFail();

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
     * Take the row locks for a set of account codes, lowest id first.
     *
     * One direction of travel for every service that locks ledger accounts is
     * the whole point: two callers that each need `{1100, 4000}` cannot deadlock
     * if both are made to acquire 1100 before 4000, whatever order their own
     * lines happened to be written in.
     *
     * A missing code is left to the caller's `firstOrFail()` rather than
     * reported here, so the error a person sees is the one the posting path
     * already produces — this method is about ordering, not validation.
     *
     * @param  array<int, string>  $accountCodes
     */
    private function lockAccounts(array $accountCodes): void
    {
        $codes = array_values(array_unique(array_filter($accountCodes, static fn ($code) => is_string($code) && $code !== '')));

        if ($codes === []) {
            return;
        }

        LedgerAccount::query()
            ->whereIn('account_code', $codes)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
    }

    /**
     * Recognise the commission a completed deal earned the platform.
     *
     * One posting, made once, at the moment the deal becomes COMPLETED — the
     * point where the seller's obligation to pay it crystallises. Debit the
     * receivable, credit the revenue, and the book says exactly what is true:
     * the platform has earned this amount and has not received it, because
     * receiving it is a bank transfer outside HASSAL that this system will
     * never see. There is deliberately no counterpart entry for the goods
     * price: the two businesses settle that between themselves, and a ledger
     * line for money that never passes through us would be a fiction.
     *
     * A deal with no commission — the rate was zero when it was priced — posts
     * nothing rather than a zero entry, so the journal stays a record of
     * claims and not a log of deals.
     */
    public function recordDealFee(Deal $deal, ?User $user = null): ?JournalEntry
    {
        $fee = round((float) $deal->platform_fee_amount, 4);

        if ($fee <= 0.0000) {
            return null;
        }

        $lines = [
            [
                'account_code' => self::ACC_FEE_RECEIVABLE,
                'debit' => $fee,
                'credit' => 0.0000,
                'org_id' => $deal->seller_organization_id,
                'memo' => "عمولة مستحقة على البائع Org #{$deal->seller_organization_id} للصفقة #{$deal->deal_number}",
            ],
            [
                'account_code' => self::ACC_COMMISSION_REVENUE,
                'debit' => 0.0000,
                'credit' => $fee,
                'org_id' => null,
                'memo' => "إيراد عمولة الصفقة #{$deal->deal_number}",
            ],
        ];

        return $this->postJournalEntry(
            "اعتراف بعمولة المنصة للصفقة {$deal->deal_number}",
            $lines,
            'Deal',
            $deal->id,
            $deal->deal_number,
            $deal->currency_code,
            $user
        );
    }
}
