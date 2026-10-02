<?php

namespace App\Console\Commands;

use App\Models\Deal;
use App\Models\DealHandover;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PurchaseRequest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read the demo world back and answer one question: does it agree with itself?
 *
 * `SandboxSeeder` writes every row through the services that write it in
 * production, which is the argument for trusting it — and the reason this command
 * exists rather than being redundant: an invariant can break in the service, in
 * the seeder, or in the months after the seed when somebody edits a row in a
 * database tool. A demo world whose listings, holds, orders and delivery codes
 * disagree is worse than an empty one, because every screen built over it then
 * looks correct while proving nothing, and the disagreement is found by a buyer
 * at a gate with a code that does not scan.
 *
 * It reads. It never repairs: a screen showing a wrong number is the operator's
 * decision to make, not a command's to overwrite.
 */
class VerifySandboxDataCommand extends Command
{
    protected $signature = 'sandbox:verify';

    protected $description = 'Check the seeded sandbox world for internal disagreements (quantities, order money, state history, handover codes, role coverage)';

    protected int $problems = 0;

    public function handle(): int
    {
        if (Offer::count() === 0) {
            $this->warn('There is no data to check: this database has no listings at all.');

            return self::FAILURE;
        }

        $this->checkOfferArithmetic();
        $this->checkHoldsAgainstListings();
        $this->checkOrderMoney();
        $this->checkStateHistories();
        $this->checkHandoverCodes();
        $this->checkStateCoverage();
        $this->checkThePlatformIsHeldByOne();

        $this->newLine();

        if ($this->problems === 0) {
            $this->info('OK — the demo world agrees with itself.');

            return self::SUCCESS;
        }

        $this->error("{$this->problems} disagreement(s) above.");

        return self::FAILURE;
    }

    /**
     * A listing's shelf is split three ways and nothing else: what may be held,
     * what is held, what has been received. `InventoryMovementController` already
     * reads it that way when it records a movement.
     */
    protected function checkOfferArithmetic(): void
    {
        $this->line('--- Listings: total = available + reserved + sold ---');
        $checked = 0;

        foreach (Offer::orderBy('id')->get() as $offer) {
            $checked++;
            $sum = (float) $offer->available_quantity + (float) $offer->reserved_quantity + (float) $offer->sold_quantity;

            if (abs($sum - (float) $offer->total_quantity) > 0.0001) {
                $this->markProblem("offer #{$offer->id} ({$offer->title_ar}): {$offer->available_quantity} + {$offer->reserved_quantity} + {$offer->sold_quantity} is not the {$offer->total_quantity} the listing says it has");
            }
        }

        $this->info("   {$checked} listings checked.");
    }

    /**
     * `reserved_quantity` is not a count of active holds, and pretending it is
     * would report a disagreement on every order in flight.
     *
     * The reservation service moves quantity from available to reserved when a
     * hold opens and back when it is released or expires. Converting a hold into
     * an order does not touch it: the quantity stays reserved until the order
     * closes — then `DealStateMachineService` moves it to sold — or is called off,
     * which returns it. So a converted hold still belongs to the figure while its
     * order is open, and stops belonging the moment that order ends.
     */
    protected function checkHoldsAgainstListings(): void
    {
        $this->line('--- Holds: reserved_quantity = active holds + holds of open orders ---');

        $openOrderStates = [
            Deal::STATUS_DRAFT, Deal::STATUS_PENDING_SELLER_APPROVAL, Deal::STATUS_ACCEPTED,
            Deal::STATUS_PROCESSING, Deal::STATUS_READY_FOR_PICKUP, Deal::STATUS_DISPATCHED,
            Deal::STATUS_DELIVERED, Deal::STATUS_INSPECTION, Deal::STATUS_DISPUTED, Deal::STATUS_RESOLVED,
        ];

        $committed = DB::table('reservations')
            ->leftJoin('deals', 'deals.reservation_id', '=', 'reservations.id')
            ->where(function ($query) use ($openOrderStates): void {
                $query->where('reservations.status', Reservation::STATUS_ACTIVE)
                    ->orWhere(function ($query) use ($openOrderStates): void {
                        $query->where('reservations.status', Reservation::STATUS_CONVERTED_TO_DEAL)
                            ->whereIn('deals.status', $openOrderStates);
                    });
            })
            ->select('reservations.offer_id', DB::raw('SUM(reservations.quantity) as held'))
            ->groupBy('reservations.offer_id')
            ->pluck('held', 'offer_id');

        foreach (Offer::orderBy('id')->get() as $offer) {
            $held = (float) ($committed[$offer->id] ?? 0);

            if (abs($held - (float) $offer->reserved_quantity) > 0.0001) {
                $this->markProblem("offer #{$offer->id}: the holds add up to {$held} but the listing reserves {$offer->reserved_quantity}");
            }
        }

        $this->info('   ' . Reservation::count() . ' holds counted against ' . Offer::count() . ' listings.');
    }

    /**
     * The figures on an order are the snapshot the pricing service made when it
     * opened, so they must still be internally consistent — a term that changed
     * after the fact is a term nobody agreed to.
     */
    protected function checkOrderMoney(): void
    {
        $this->line('--- Orders: the money fields add up as the pricing service adds them ---');

        foreach (Deal::orderBy('id')->get() as $deal) {
            $line = round((float) $deal->quantity * (float) $deal->unit_price, 4);

            if (abs($line - (float) $deal->base_amount) > 0.0001) {
                $this->markProblem("deal {$deal->deal_number}: {$deal->quantity} × {$deal->unit_price} is not the base_amount {$deal->base_amount}");
            }

            $buyerTotal = round((float) $deal->base_amount - (float) $deal->discount_amount
                + (float) $deal->tax_amount + (float) $deal->delivery_fee_amount, 4);

            if (abs($buyerTotal - (float) $deal->buyer_total_amount) > 0.0001) {
                $this->markProblem("deal {$deal->deal_number}: buyer_total_amount {$deal->buyer_total_amount} is not the computed {$buyerTotal}");
            }

            $sellerNet = round((float) $deal->base_amount - (float) $deal->discount_amount
                - (float) $deal->platform_fee_amount, 4);

            if (abs($sellerNet - (float) $deal->seller_net_amount) > 0.0001) {
                $this->markProblem("deal {$deal->deal_number}: seller_net_amount {$deal->seller_net_amount} is not the computed {$sellerNet}");
            }

            if ($deal->items()->count() === 0) {
                $this->markProblem("deal {$deal->deal_number} has no line item; every screen that shows what was bought reads it from there");
            }
        }

        $this->info('   ' . Deal::count() . ' orders checked.');
    }

    /**
     * An order never writes its own status; the state machine does, and it writes
     * the history row in the same statement. So the history is the only account of
     * how the order got where it is — with one exception that is not a seeder
     * shortcut but the actual shape of the cycle: an order opens at
     * `PENDING_SELLER_APPROVAL` with no move behind it, exactly as
     * `DealController::store()` leaves one.
     */
    protected function checkStateHistories(): void
    {
        $this->line('--- Orders: the state each one sits in is the state its history ends at ---');

        foreach (Deal::orderBy('id')->get() as $deal) {
            $last = DB::table('deal_status_histories')->where('deal_id', $deal->id)->orderByDesc('id')->value('to_status');

            if ($last === null) {
                if ($deal->status !== Deal::STATUS_PENDING_SELLER_APPROVAL) {
                    $this->markProblem("deal {$deal->deal_number} is {$deal->status} with no status history at all — the machine cannot have put it there");
                }
                continue;
            }

            if ($last !== $deal->status) {
                $this->markProblem("deal {$deal->deal_number} is {$deal->status} but its history ends at {$last}");
            }
        }

        $this->info('   ' . DB::table('deal_status_histories')->count() . ' history rows.');
    }

    /**
     * The code and the order have to tell the same story, because that is the one
     * place a dispute is settled from.
     *
     * A spent code belongs to an order the machine can close — `INSPECTION`,
     * `COMPLETED`, or a dispute raised after the goods were attested. An order that
     * has ended cannot keep a live code: nobody may scan a closed deal.
     */
    protected function checkHandoverCodes(): void
    {
        $this->line('--- Delivery codes: each one matches the order it was issued for ---');

        $spendable = [
            Deal::STATUS_INSPECTION, Deal::STATUS_COMPLETED, Deal::STATUS_DISPUTED, Deal::STATUS_RESOLVED,
        ];

        foreach (DealHandover::orderBy('id')->get() as $handover) {
            $deal = Deal::find($handover->deal_id);

            if (!$deal) {
                $this->markProblem("handover #{$handover->id} belongs to no order");
                continue;
            }

            if ($handover->status === DealHandover::STATUS_USED && !in_array($deal->status, $spendable, true)) {
                $this->markProblem("handover {$handover->code} is spent, but order {$deal->deal_number} is {$deal->status}");
            }

            if ($handover->status === DealHandover::STATUS_ACTIVE
                && in_array($deal->status, [Deal::STATUS_COMPLETED, Deal::STATUS_CANCELLED], true)) {
                $this->markProblem("order {$deal->deal_number} is {$deal->status} while its code is still scannable");
            }

            if ($handover->status === DealHandover::STATUS_USED && $handover->verified_at === null) {
                $this->markProblem("handover {$handover->code} is spent without a timestamp");
            }
        }

        $this->info('   ' . DealHandover::count() . ' codes checked.');
    }

    /**
     * The point of a demo world is that a screen can be pointed at every state a
     * user can be in. A missing state is not a smaller demo; it is an untested
     * branch of the interface.
     */
    protected function checkStateCoverage(): void
    {
        $this->line('--- Coverage: every state a screen walks has at least one row ---');

        $lists = [
            'listings' => Offer::pluck('status')->unique()->sort()->values()->all(),
            'purchase requests' => PurchaseRequest::pluck('status')->unique()->sort()->values()->all(),
            'holds' => Reservation::pluck('status')->unique()->sort()->values()->all(),
            'orders' => Deal::pluck('status')->unique()->sort()->values()->all(),
            'delivery codes' => DealHandover::pluck('status')->unique()->sort()->values()->all(),
            'disputes' => DB::table('disputes')->pluck('status')->unique()->sort()->values()->all(),
        ];

        foreach ($lists as $label => $states) {
            $this->line('   ' . $label . ': ' . implode(', ', $states));
        }

        // `DRAFT` is deliberately absent from the orders: `DealController::store()`
        // opens an order at `PENDING_SELLER_APPROVAL`, so nothing in the product
        // creates the twelfth state, and a demo that showed one would be showing a
        // state the screens can never receive.
        foreach ([
            Deal::STATUS_PENDING_SELLER_APPROVAL, Deal::STATUS_ACCEPTED, Deal::STATUS_PROCESSING,
            Deal::STATUS_READY_FOR_PICKUP, Deal::STATUS_DISPATCHED, Deal::STATUS_DELIVERED,
            Deal::STATUS_INSPECTION, Deal::STATUS_COMPLETED, Deal::STATUS_CANCELLED,
            Deal::STATUS_DISPUTED, Deal::STATUS_RESOLVED,
        ] as $state) {
            if (!in_array($state, $lists['orders'], true)) {
                $this->markProblem("no order in {$state}");
            }
        }

        foreach ([Reservation::STATUS_ACTIVE, Reservation::STATUS_EXPIRED, Reservation::STATUS_CANCELLED, Reservation::STATUS_CONVERTED_TO_DEAL] as $state) {
            if (!in_array($state, $lists['holds'], true)) {
                $this->markProblem("no hold in {$state}");
            }
        }

        foreach ([DealHandover::STATUS_ACTIVE, DealHandover::STATUS_USED, DealHandover::STATUS_INVALIDATED] as $state) {
            if (!in_array($state, $lists['delivery codes'], true)) {
                $this->markProblem("no delivery code in {$state}");
            }
        }

        $byType = Organization::pluck('type')->unique()->sort()->values()->all();
        $this->line('   organizations: ' . Organization::count() . ' across ' . implode(', ', $byType));
    }

    /**
     * One operator holds the platform, and no demo account may hold it.
     *
     * This is checked here as well as in the installer because the demo world is
     * the environment where people try things: a seeder that quietly granted
     * SuperAdmin to a demo seller would put a second owner on the installation and
     * make every permission test run afterwards meaningless.
     */
    protected function checkThePlatformIsHeldByOne(): void
    {
        $this->line('--- The platform has exactly one operator, and no demo account is one ---');

        $operators = User::role('SuperAdmin')->pluck('email')->all();
        $this->line('   SuperAdmin: ' . (empty($operators) ? 'nobody' : implode(', ', $operators)));

        if (count($operators) !== 1) {
            $this->markProblem('the product is designed to run with one platform operator; this database has ' . count($operators));
        }

        $demoHolders = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->join('users', 'users.id', '=', 'model_has_roles.model_id')
            ->where('model_has_roles.model_type', User::class)
            ->whereIn('roles.name', (array) config('rbac.platform_roles'))
            ->where('users.email', 'like', '%@hassal.test')
            ->pluck('users.email')
            ->all();

        if (!empty($demoHolders)) {
            $this->markProblem('a demo account holds a platform role: ' . implode(', ', $demoHolders));
        }

        $this->line('   demo accounts: ' . User::where('email', 'like', '%@hassal.test')->count());
    }

    public function markProblem(string $message): void
    {
        $this->problems++;
        $this->error('   [FAIL] ' . $message);
    }
}
