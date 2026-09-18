<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Deal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LedgerAccountingService
{
    /**
     * Record a balanced double-entry transaction.
     * Ensures Debit Account and Credit Account are simultaneously updated with identical amount.
     *
     * @param int $dealId
     * @param int|null $paymentId
     * @param int $debitAccountId
     * @param int $creditAccountId
     * @param float $amount
     * @param string $entryType
     * @param string $description
     * @param string $currency
     * @return LedgerEntry
     */
    public function recordDoubleEntry(
        int $dealId,
        ?int $paymentId,
        int $debitAccountId,
        int $creditAccountId,
        float $amount,
        string $entryType,
        string $description,
        string $currency = 'SAR'
    ): LedgerEntry {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Ledger entry amount must be strictly positive.');
        }

        return DB::transaction(function () use (
            $dealId, $paymentId, $debitAccountId, $creditAccountId, $amount, $entryType, $description, $currency
        ) {
            // Update Debit Account balance
            $debitAcc = LedgerAccount::where('id', $debitAccountId)->lockForUpdate()->firstOrFail();
            $debitAcc->balance = (float)$debitAcc->balance + $amount;
            $debitAcc->save();

            // Update Credit Account balance
            $creditAcc = LedgerAccount::where('id', $creditAccountId)->lockForUpdate()->firstOrFail();
            $creditAcc->balance = (float)$creditAcc->balance + $amount;
            $creditAcc->save();

            // Insert immutable balanced entry
            return LedgerEntry::create([
                'deal_id' => $dealId,
                'payment_id' => $paymentId,
                'debit_account_id' => $debitAccountId,
                'credit_account_id' => $creditAccountId,
                'amount' => $amount,
                'currency' => $currency,
                'entry_type' => $entryType,
                'description' => $description,
            ]);
        });
    }

    /**
     * Freeze buyer funds into Escrow upon payment verification.
     */
    public function recordEscrowDeposit(Deal $deal, float $amount, ?int $paymentId = null): LedgerEntry
    {
        return $this->recordDoubleEntry(
            $deal->id,
            $paymentId,
            6, // Buyer Wallet / Cash In Transit
            3, // Platform Escrow Vault
            $amount,
            'deal_escrow_hold',
            "تجميد قيمة الصفقة {$deal->deal_number} في حساب الضمان المالي Escrow"
        );
    }

    /**
     * Release Escrow funds to seller and record platform commission.
     */
    public function recordEscrowRelease(Deal $deal, float $sellerNet, float $commissionAmount): array
    {
        return DB::transaction(function () use ($deal, $sellerNet, $commissionAmount) {
            // Payout to Seller
            $sellerEntry = $this->recordDoubleEntry(
                $deal->id,
                null,
                3, // Escrow Vault
                5, // Seller Payable
                $sellerNet,
                'seller_payout',
                "تحرير مستحقات البائع للصفقة {$deal->deal_number} بعد تأكيد الاستلام"
            );

            // Platform Commission Revenue
            $commissionEntry = $this->recordDoubleEntry(
                $deal->id,
                null,
                3, // Escrow Vault
                4, // Platform Revenue
                $commissionAmount,
                'platform_fee',
                "استقطاع عمولة منصة حصّال للصفقة {$deal->deal_number}"
            );

            return [
                'seller_entry' => $sellerEntry,
                'commission_entry' => $commissionEntry,
            ];
        });
    }
}
