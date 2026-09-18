<?php

namespace App\Contracts;

use App\Models\Deal;
use App\Models\Payment;

interface PaymentProviderInterface
{
    /**
     * Unique identifier for the payment provider.
     */
    public function getIdentifier(): string;

    /**
     * Human-readable name of the payment provider.
     */
    public function getName(): string;

    /**
     * Initializes a payment for a deal into escrow holding.
     */
    public function chargeEscrow(Deal $deal, float $amount, string $currency, array $metadata = []): Payment;

    /**
     * Refunds an escrow payment back to the buyer.
     */
    public function refund(Payment $payment, float $amount, ?string $reason = null): bool;

    /**
     * Releases escrow funds to the seller.
     */
    public function releaseToSeller(Payment $payment, float $amount): bool;
}
