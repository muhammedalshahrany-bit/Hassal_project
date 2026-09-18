<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProviderInterface;
use App\Models\Deal;
use App\Models\Escrow;
use App\Models\Payment;
use App\Services\DoubleEntryLedgerService;
use Carbon\Carbon;
use Illuminate\Support\Str;

class BankTransferPaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        protected DoubleEntryLedgerService $ledgerService
    ) {}

    public function getIdentifier(): string
    {
        return 'BANK_TRANSFER';
    }

    public function getName(): string
    {
        return 'Bank wire / direct transfer';
    }

    public function chargeEscrow(Deal $deal, float $amount, string $currency, array $metadata = []): Payment
    {
        $reference = 'PAY-WIRE-' . date('ymd') . '-' . strtoupper(Str::random(4));

        $payment = Payment::create([
            'payment_number' => $reference,
            'deal_id' => $deal->id,
            'payer_organization_id' => $deal->buyer_organization_id,
            'payee_organization_id' => $deal->seller_organization_id,
            'amount' => $amount,
            'currency_code' => $currency,
            'gateway_provider' => $this->getIdentifier(),
            'gateway_transaction_id' => $metadata['wire_reference'] ?? ('WT-' . strtoupper(Str::random(8))),
            'status' => Payment::STATUS_COMPLETED,
            'metadata' => $metadata,
            'completed_at' => Carbon::now(),
        ]);

        // Post Double-Entry Ledger for Escrow Deposit
        $this->ledgerService->postEscrowDeposit($payment);

        return $payment;
    }

    public function refund(Payment $payment, float $amount, ?string $reason = null): bool
    {
        $this->ledgerService->postRefund($payment, $amount, $reason ?? 'Buyer refund on bank transfer');
        $payment->update(['status' => Payment::STATUS_REFUNDED]);
        return true;
    }

    public function releaseToSeller(Payment $payment, float $amount): bool
    {
        return true;
    }
}
