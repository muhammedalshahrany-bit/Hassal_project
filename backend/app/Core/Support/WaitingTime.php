<?php

namespace App\Core\Support;

use DateTimeInterface;

/**
 * How long something has been waiting, in whole hours.
 *
 * The queues print this next to each row, so the number has to mean what its
 * label claims. Carbon 3 returns a *signed* difference — `$a->diffInMinutes($b)`
 * is negative when `$b` is in the past — so the intuitive-looking
 * `now()->diffInMinutes($createdAt)` produced `-1` for a paper filed fourteen
 * minutes ago, and `floor()` of a negative fraction rounds away from zero, so
 * even a row submitted seconds earlier displayed a negative wait. Both queues
 * showed that figure to staff as "ساعة انتظار".
 *
 * The fix is to measure forward from the older moment, and to clamp at zero:
 * clocks and replicated databases disagree by seconds sometimes, and a queue
 * that says "-0 hours" is worse than one that says "0".
 */
final class WaitingTime
{
    /**
     * Whole hours elapsed since $moment, or null when there is no moment to
     * measure from — an absent timestamp is not a wait of zero hours.
     */
    public static function hoursSince(?DateTimeInterface $moment): ?int
    {
        if ($moment === null) {
            return null;
        }

        $minutes = \Illuminate\Support\Carbon::instance($moment)->diffInMinutes(now());

        return max(0, intdiv((int) floor($minutes), 60));
    }
}
