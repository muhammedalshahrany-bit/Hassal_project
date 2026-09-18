<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProviderInterface;
use App\Services\DoubleEntryLedgerService;
use InvalidArgumentException;

class PaymentGatewayManager
{
    protected array $providers = [];

    public function __construct(DoubleEntryLedgerService $ledgerService)
    {
        $this->registerProvider(new BankTransferPaymentProvider($ledgerService));
        $this->registerProvider(new CashOnDeliveryPaymentProvider());
    }

    public function registerProvider(PaymentProviderInterface $provider): void
    {
        $this->providers[$provider->getIdentifier()] = $provider;
    }

    public function getProvider(string $identifier): PaymentProviderInterface
    {
        if (!isset($this->providers[$identifier])) {
            throw new InvalidArgumentException("Unsupported payment provider [{$identifier}]. Production extension required.");
        }

        return $this->providers[$identifier];
    }

    public function getSupportedProviders(): array
    {
        $list = [];
        foreach ($this->providers as $key => $p) {
            $list[] = [
                'identifier' => $p->getIdentifier(),
                'name' => $p->getName(),
                'is_ready' => true,
            ];
        }

        // Placeholders intentionally do not claim a live provider integration.
        $list[] = [
            'identifier' => 'ONLINE_PROVIDER',
            'name' => 'Electronic payment provider',
            'is_ready' => false,
            'status' => 'EXTERNAL_PROVIDER_REQUIRED',
        ];

        return $list;
    }
}
