<?php

namespace App\Support;

use App\Models\Offer;

/**
 * What the market says when a listing appears on it.
 *
 * Until now the only notices an offer produced were the ones about *your* offer:
 * a moderation decision, a comment, a reservation. A buyer who wanted to know
 * that someone three towns away had just put twenty tonnes of dates on the
 * market found out by opening the market — which is the one thing a notification
 * exists to save a person from doing.
 *
 * So this is the seller's listing read from the buyer's side: what is on offer,
 * how much of it, at what price, and where. The name of the seller is *not* in
 * that list on purpose. `show_seller_identity` is the publisher's decision about
 * who sees their company, it is honoured by `OfferResource` on the market itself,
 * and a push to every handset in the country is the worst possible place to
 * quietly overrule it.
 */
final class OfferNotices
{
    /**
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public static function published(Offer $offer): array
    {
        $title = 'عرض جديد في السوق · '.self::heading($offer);

        return [
            'title' => $title,
            'body' => implode(' · ', array_filter([
                self::terms($offer),
                self::where($offer),
                'افتح العرض من السوق.',
            ], fn ($part) => $part !== '')),
            'data' => self::data($offer),
        ];
    }

    /**
     * The payload the notice travels with.
     *
     * `type` is `OFFER_PUBLISHED` rather than `OFFER` because the two reach
     * different people for different reasons: `OFFER` is a decision about the
     * seller's own listing and is gated by the moderation switch, while this is
     * market news for everybody and is gated by the offers switch. One token
     * serving both would make one of the two switches a lie.
     *
     * @return array<string, mixed>
     */
    public static function data(Offer $offer): array
    {
        return [
            'type' => 'OFFER_PUBLISHED',
            'offer_id' => $offer->id,
            'organization_id' => $offer->seller_organization_id,
        ];
    }

    /**
     * The listing's own words, with the product behind them as the fallback.
     */
    private static function heading(Offer $offer): string
    {
        return $offer->title_ar !== '' && $offer->title_ar !== null
            ? $offer->title_ar
            : ((string) $offer->variant?->product?->name_ar);
    }

    /**
     * How much, at what price.
     */
    private static function terms(Offer $offer): string
    {
        $quantity = rtrim(rtrim(number_format((float) $offer->available_quantity, 3, '.', ''), '0'), '.');
        $unit = (string) $offer->unit;

        $parts = [];
        if ($quantity !== '' && $quantity !== '0') {
            $parts[] = $quantity.($unit !== '' ? ' '.$unit : '');
        }
        if ($offer->unit_price !== null) {
            $parts[] = 'بـ '.number_format((float) $offer->unit_price, 2, '.', ',')
                .' '.(string) $offer->currency_code;
        }

        return implode(' ', $parts);
    }

    /**
     * Where it sits, and who put it there if they agreed to be named.
     */
    private static function where(Offer $offer): string
    {
        $city = (string) $offer->warehouse?->city;

        // The seller's name rides only with their own permission, read the same
        // way the market reads it.
        if (! (bool) ($offer->show_seller_identity ?? true)) {
            return $city;
        }

        $name = $offer->seller?->name_ar ?: (string) $offer->seller?->name_en;

        return $city !== '' && $name !== '' ? "$city — $name" : ($name ?: $city);
    }
}
