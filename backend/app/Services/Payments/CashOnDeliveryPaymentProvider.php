<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProviderInterface;
use App\Models\Deal;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Str;

class CashOnDeliveryPaymentProvider implements PaymentProviderInterface
{
    public function getIdentifier(): string
    {
        return 'CASH_ON_DELIVERY';
    }

    public function getName(): string
    {
        return 'Cash on Delivery (B2B Settlement)';
    }

    public function chargeEscrow(Deal $deal, float $amount, string $currency, array $metadata = []): Payment
    {
        return Payment::create([
            'payment_number' => 'PAY-COD-' . date('ymd') . '-' . strtoupper(Str::random(4)),
            'deal_id' => $deal->id,
            'payer_organization_id' => $deal->buyer_organization_id,
            'payee_organization_id' => $deal->seller_organization_id,
            'amount' => $amount,
            'currency_code' => $currency,
            'gateway_provider' => $this->getIdentifier(),
            'gateway_transaction_id' => 'COD-' . strtoupper(Str::random(8)),
            'status' => Payment::STATUS_COMPLETED,
            'metadata' => $metadata,
            'completed_at' => Carbon::now(),
        ]);
    }

    public function refund(Payment $payment, float $amount, ?string $reason = null): bool
    {
        $payment->update(['status' => Payment::STATUS_REFUNDED]);
        return true;
    }

    public function releaseToSeller(Payment $payment, float $amount): bool
    {
        return true;
    }
}
