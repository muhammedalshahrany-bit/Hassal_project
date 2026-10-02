<?php

namespace App\Core\Support;

/**
 * One place for the question "how far".
 *
 * The platform asks it in two directions. The nearby-offers search asks it of a
 * query — thousands of listings ranked by their distance from a buyer — and that
 * has to be answered inside MySQL, because pulling every tradable listing into
 * PHP to sort it is a memory problem waiting for a city with a good market. The
 * warehouse board asks it of a page it already holds in memory, twenty rows at
 * most, and reaching for SQL there would mean a second formula that everyone
 * trusts to agree with the first.
 *
 * So both live here, and they are the same formula: the same mean earth radius,
 * the same cosine-rule of spherical trigonometry, and the same clamp that keeps
 * a floating-point rounding from pushing `acos()` outside its domain — which is
 * not a theoretical worry. Two identical coordinates make the argument 1.0000000002,
 * `acos()` of that is `NAN`, and a `NAN` distance sorts nowhere and renders as
 * nothing, so the buyer standing next to the warehouse would be shown the
 * furthest thing the platform has.
 *
 * Distances are rounded once, at the edge that presents them, and never
 * silently: a `distance_km` of 12.7 is a claim that the platform measured
 * 12.7 kilometres, and a client that re-rounds a number the server already
 * rounded is how two screens end up disagreeing about the same truck.
 */
final class Geo
{
    /**
     * Mean earth radius in kilometres.
     *
     * The same constant on both sides of the formula, which is the only reason
     * the two answers are comparable at all.
     */
    public const EARTH_RADIUS_KM = 6371;

    private function __construct()
    {
    }

    /**
     * A Haversine expression over two columns of a joined table.
     *
     * Takes the column names rather than hard-coding them because the caller has
     * already joined whatever carries the coordinates, and a distance computed
     * against a column that is not in the query is a 500, not a wrong number.
     *
     * The three `?` are, in order: the observer's latitude, its longitude, and
     * its latitude again. That repetition is the formula, not a mistake.
     */
    public static function distanceSql(string $latitudeColumn, string $longitudeColumn): string
    {
        return sprintf(
            '(%d * acos(GREATEST(-1, LEAST(1, cos(radians(?)) * cos(radians(%s)) * cos(radians(%s) - radians(?)) + sin(radians(?)) * sin(radians(%s))))))',
            self::EARTH_RADIUS_KM,
            $latitudeColumn,
            $longitudeColumn,
            $latitudeColumn
        );
    }

    /**
     * The same distance, computed in PHP for rows already in memory.
     *
     * @param  float|null  $toLatitude   `null` when the target records no position
     * @return float|null  Kilometres, or null when either end is unknown
     */
    public static function kilometers(
        ?float $fromLatitude,
        ?float $fromLongitude,
        ?float $toLatitude,
        ?float $toLongitude
    ): ?float {
        if ($fromLatitude === null || $fromLongitude === null
            || $toLatitude === null || $toLongitude === null) {
            return null;
        }

        $deltaLatitude = deg2rad($toLatitude - $fromLatitude);
        $deltaLongitude = deg2rad($toLongitude - $fromLongitude);

        $a = sin($deltaLatitude / 2) ** 2
            + cos(deg2rad($fromLatitude)) * cos(deg2rad($toLatitude))
            * sin($deltaLongitude / 2) ** 2;

        // Clamped for the same reason the SQL clamps its `acos()` argument: a
        // pair of identical coordinates can round `a` a hair above 1.
        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt(min(1.0, max(0.0, $a))), sqrt(max(0.0, 1 - $a)));
    }

    /**
     * Two decimal places, or `null` kept as `null`.
     *
     * `null` is a fact of its own here — the row records no position, or the
     * caller sent none — and turning it into `0.0` would tell a buyer that the
     * goods are on top of them.
     */
    public static function present(?float $kilometers): ?float
    {
        return $kilometers === null ? null : round($kilometers, 2);
    }
}
