<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\DoubleEntryLedgerService;
use Exception;
use Tests\TestCase;

class DoubleEntryLedgerTest extends TestCase
{
    protected DoubleEntryLedgerService $ledgerService;
    protected Deal $deal;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerService = app(DoubleEntryLedgerService::class);
        $this->ledgerService->ensureChartOfAccounts();

        $this->user = User::first();
        $seller = Organization::where('type', 'seller')->first() ?? Organization::first();
        $buyer = Organization::where('type', 'buyer')->first() ?? Organization::first();

        $this->deal = Deal::firstOrCreate(
            ['deal_number' => 'DEAL-LEDGER-TEST-01'],
            [
                'seller_organization_id' => $seller->id,
                'buyer_organization_id' => $buyer->id,
                'offer_id' => 1,
                'status' => Deal::STATUS_ACCEPTED,
                'quantity' => 20,
                'unit_price' => 100,
                'subtotal_amount' => 2000,
                'vat_rate' => 0.15,
                'vat_amount' => 300,
                'platform_commission_rate' => 0.025,
                'platform_fee_amount' => 50,
                'buyer_total_amount' => 2300,
                'seller_net_amount' => 2250,
                'currency_code' => 'SAR',
            ]
        );
    }

    public function test_escrow_funding_journal_entry_is_balanced(): void
    {
        $entry = $this->ledgerService->recordEscrowFunding($this->deal, $this->user);

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(JournalEntry::STATUS_POSTED, $entry->status);

        $this->assertEquals(
            (float) $entry->total_debit,
            (float) $entry->total_credit,
            'Total Debits must equal Total Credits in Double-Entry accounting'
        );

        $this->assertEquals(2300.00, (float) $entry->total_debit);
    }

    public function test_settlement_journal_entry_is_balanced(): void
    {
        $entry = $this->ledgerService->recordDealSettlement($this->deal, $this->user);

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertEquals(
            (float) $entry->total_debit,
            (float) $entry->total_credit
        );

        $this->assertEquals(2300.00, (float) $entry->total_debit);
    }

    public function test_unbalanced_manual_entry_is_rejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Double-entry imbalance');

        $this->ledgerService->createJournalEntry(
            'JE-INVALID-001',
            'Imbalanced Test Entry',
            'SAR',
            [
                ['account_code' => '1010-CASH-ESCROW', 'type' => 'DEBIT', 'amount' => 1000.00],
                ['account_code' => '2010-ESCROW-HOLDING', 'type' => 'CREDIT', 'amount' => 800.00], // Mismatched
            ],
            $this->user
        );
    }
}
