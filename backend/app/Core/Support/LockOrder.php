<?php

namespace App\Core\Support;

/**
 * The one order in which this platform takes row locks.
 *
 * Why a total order is needed at all: two transactions that acquire the same
 * locks in different orders deadlock, and MySQL resolves a deadlock by killing
 * whichever one it noticed last. The killed transaction is a person's
 * reservation or a delivery scan that reached the ledger and stopped — and because the
 * collision needs two requests to overlap by milliseconds, it does not appear in
 * development, appears once a month in production, and is blamed on the network.
 *
 * Before this was written down, each service locked what it needed when it
 * happened to need it: `InventoryReservationService::resolve()` took a
 * reservation and then its offer, while `reserve()` took the offer alone, and
 * `DealStateMachineService` took a deal and then its offer. Two paths through
 * `offers` with a different second lock is exactly the pair that deadlocks.
 *
 * The order, from outermost resource to innermost:
 *
 *   1. `offers`              — the stock counter every other row depends on
 *   2. `reservations`        — a hold against an offer
 *   3. `deals`               — a commitment that consumes a hold
 *   4. `deal_handovers`      — the code that attests one deal's delivery
 *   5. `ledger_accounts`     — by primary key ascending, see `ACCOUNTS_ASCENDING`
 *   6. any insert            — journal entries, lines, movement rows
 *
 * Two rules that make step 5 work: accounts are locked as a *set* sorted by id
 * (see `DoubleEntryLedgerService::lockAccounts()`), because an entry touches
 * several at once; and a service that learns a lower-step id only by reading a
 * higher-step row reads it *without* a lock first, then locks in this order and
 * re-checks the state it acted on. `DealStateMachineService` is that case —
 * `deal.offer_id` never changes after the deal is created, so reading it
 * unlocked cannot tell a lie about which offer to lock.
 *
 * Adding a table to this list is a deliberate act: put it where its row depends
 * on nothing else, and make every service that touches it agree.
 */
final class LockOrder
{
    /**
     * The resources, outermost first.
     *
     * Named rather than numbered in comments because the string is what appears
     * in a service's docblock when it says which step it is on — and a service
     * that quotes "step 3" is not self-describing after the next insert.
     */
    public const SEQUENCE = [
        'offers',
        'reservations',
        'deals',
        'deal_handovers',
        'ledger_accounts',
    ];

    /**
     * Ledger accounts are locked as a set, ordered by their own primary key.
     *
     * The set ordering is the part a caller cannot be allowed to choose: an
     * entry's lines are written for the reader's benefit, in debit-then-credit
     * order, and that order is not the same as another entry's.
     */
    public const ACCOUNTS_ASCENDING = 'ledger_accounts.id ASC';

    /**
     * Where a resource sits in the sequence, or null when it is not in it.
     */
    public static function rank(string $resource): ?int
    {
        $index = array_search($resource, self::SEQUENCE, true);

        return $index === false ? null : $index;
    }

    /**
     * Assert, at the point a service takes its second lock, that it goes the
     * same way as this document.
     *
     * This is not enforcement — a caller can pass any two strings — and it does
     * not pretend to be. What it buys is the failure a reviewer can act on: a
     * service that later adds a lock on something that must come first throws
     * here in development and in tests, in the same request, instead of
     * deadlocking once a month in production under load.
     *
     * @throws \LogicException
     */
    public static function assertBefore(string $first, string $second): void
    {
        $left = self::rank($first);
        $right = self::rank($second);

        if ($left === null || $right === null) {
            throw new \LogicException(
                "Unknown resource in the lock order: '{$first}' / '{$second}'. "
                    . 'Add it to LockOrder::SEQUENCE deliberately rather than by omission.'
            );
        }

        if ($left > $right) {
            throw new \LogicException(
                "Lock order violation: '{$first}' must never be locked before '{$second}'. "
                    . 'See App\Core\Support\LockOrder for the sequence and why it exists.'
            );
        }
    }
}
