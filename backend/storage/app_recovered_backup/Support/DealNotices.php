<?php

namespace App\Support;

use App\Models\Deal;
use App\Models\DealItem;
use App\Models\Organization;

/**
 * What a deal notice says.
 *
 * The notices this file replaced named a deal number and nothing else: «تم إنشاء
 * الصفقة DEAL-1001-7QAX وتنتظر موافقة البائع.» A seller with three orders open
 * cannot act on that sentence — they have to open the app, find the row among
 * the others, and read which goods and which buyer it was about before the
 * notification has told them anything they could decide on. The same sentence
 * arriving on a phone in a truck is worse, because the screen it points at is
 * the one thing the person is not looking at.
 *
 * So every deal notice carries the four facts a decision needs — what, how
 * much, who, and what is being asked — and names the deal in its *title*, not
 * only in its body. Android shows the title in the shade and folds the body
 * away; a number that only lives in the second line is a number nobody sees.
 *
 * ## Why the wording is here and not in the client
 *
 * The row is written once, at the moment the platform knows most about it, and
 * it is read on a handset that may be offline, in a shade that renders no
 * template. A client-side formatter would mean a notice whose sentence depends
 * on which build of the app happens to be installed — and the older builds are
 * the ones that produced the complaint.
 *
 * The Arabic status labels below do duplicate `L.dealStatus` in the Flutter
 * client. That is the cost of the paragraph above, paid on purpose: the notice
 * has to be readable where the app's strings are not.
 */
final class DealNotices
{
    /**
     * The deal's own states, in the words a person reads them in.
     */
    private const STATUS_AR = [
        Deal::STATUS_DRAFT => 'مسودة',
        Deal::STATUS_PENDING_SELLER_APPROVAL => 'بانتظار موافقة البائع',
        Deal::STATUS_ACCEPTED => 'مقبولة',
        Deal::STATUS_PROCESSING => 'قيد التجهيز',
        Deal::STATUS_READY_FOR_PICKUP => 'جاهزة للاستلام',
        Deal::STATUS_DISPATCHED => 'في الطريق',
        Deal::STATUS_DELIVERED => 'تم التسليم',
        Deal::STATUS_INSPECTION => 'قيد الفحص',
        Deal::STATUS_COMPLETED => 'مكتملة',
        Deal::STATUS_CANCELLED => 'ملغاة',
        Deal::STATUS_DISPUTED => 'متنازَع عليها',
        Deal::STATUS_RESOLVED => 'تم حل النزاع',
    ];

    /**
     * The notice a seller receives when a buyer's reservation becomes an order.
     *
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public static function awaitingSeller(Deal $deal): array
    {
        return self::for(
            $deal,
            "صفقة جديدة بانتظار موافقتك · {$deal->deal_number}",
            self::line($deal, [
                self::who($deal, 'buyer').' تشتريك',
                'المطلوب منك: الموافقة أو الرفض.',
            ]),
        );
    }

    /**
     * The notice the buyer's own account gets when the order leaves the handset.
     *
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public static function createdForBuyer(Deal $deal): array
    {
        return self::for(
            $deal,
            "تم إنشاء صفقتك · {$deal->deal_number}",
            self::line($deal, [
                'إلى '.self::who($deal, 'seller'),
                'بانتظار ردّ البائع.',
            ]),
        );
    }

    /**
     * The notice one side gets when the other moves the order.
     *
     * `movingOrganization` is the party that acted, so the sentence names who
     * did this rather than leaving a state change floating without an author —
     * «الصفقة صارت مقبولة» does not say whether you are the one who accepted it.
     *
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public static function moved(Deal $deal, string $status, ?Organization $movingOrganization): array
    {
        $label = self::STATUS_AR[$status] ?? $status;

        return self::for(
            $deal,
            "الصفقة صارت $label · {$deal->deal_number}",
            self::line($deal, [
                $movingOrganization === null
                    ? 'قرّرت المنصة هذا التغيير.'
                    : self::name($movingOrganization) . ' أجرت هذا التغيير.',
                'حالتها الآن: ' . $label . '.',
            ]),
        );
    }

    /**
     * The notice that a delivery code was scanned and verified at the gate.
     *
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public static function handoverVerified(Deal $deal, ?Organization $scanningOrganization): array
    {
        return self::for(
            $deal,
            "تم التحقق من رمز التسليم · {$deal->deal_number}",
            self::line($deal, [
                $scanningOrganization === null
                    ? 'مسحت المنصة الرمز.'
                    : self::name($scanningOrganization) . ' مسح الرمز.',
                'يمكن الآن تأكيد الاستلام وإغلاق الطلب.',
            ]),
        );
    }

    /**
     * The payload every one of these travels with.
     *
     * `type` is what the client routes on and what the preference gate reads;
     * `deal_id` is what makes the tap land on this order rather than on the
     * list it belongs to, and `deal_number` lets a row be labelled before it
     * is read. Which organisation a copy belongs to is not the notice's
     * business — the caller is the one holding the recipient, and adds
     * `organization_id` to this.
     *
     * @return array<string, mixed>
     */
    public static function data(Deal $deal): array
    {
        return [
            'type' => 'DEAL',
            'deal_id' => $deal->id,
            'deal_number' => $deal->deal_number,
        ];
    }

    /**
     * @param  array<int, string>  $tail  sentences after the goods line
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    private static function for(Deal $deal, string $title, string $body): array
    {
        return ['title' => $title, 'body' => $body, 'data' => self::data($deal)];
    }

    /**
     * The one line that makes a notice actionable: what, how much, for how much
     * money — then whatever the moment adds.
     *
     * @param  array<int, string>  $tail
     */
    private static function line(Deal $deal, array $tail): string
    {
        $parts = array_merge([self::goods($deal), self::total($deal)], $tail);

        return implode(' · ', array_filter($parts, fn ($part) => $part !== ''));
    }

    /**
     * The ordered goods, read from the snapshot the deal wrote for itself.
     *
     * The snapshot, not the live offer: a deal is a record of terms, and the
     * offer it was made from may have been renamed, repriced or withdrawn since.
     */
    private static function goods(Deal $deal): string
    {
        /** @var DealItem|null $item */
        $item = $deal->items->first();

        if ($item === null) {
            return '';
        }

        $quantity = rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.');

        return $item->snapshot_product_name
            .' — '.$quantity.' '.($item->snapshot_unit_name ?: '');
    }

    /**
     * What the buyer pays, in the currency the deal was struck in.
     */
    private static function total(Deal $deal): string
    {
        $amount = $deal->buyer_total_amount;

        if ($amount === null) {
            return '';
        }

        return 'الإجمالي '
            .number_format((float) $amount, 2, '.', ',')
            .' '.($deal->currency_code ?? '');
    }

    private static function who(Deal $deal, string $side): string
    {
        $organization = $side === 'buyer' ? $deal->buyerOrganization : $deal->sellerOrganization;

        return $organization === null ? 'الطرف الآخر' : self::name($organization);
    }

    /**
     * The organisation's own name, in the language these sentences are in.
     */
    private static function name(Organization $organization): string
    {
        // The Arabic name is the one these sentences are written in; the Latin
        // spelling is the fallback a deployment registered in English keeps, and
        // a notice that named «» would be the second version of the complaint
        // this file exists to answer.
        return $organization->name_ar !== ''
            ? $organization->name_ar
            : ($organization->name_en !== '' ? $organization->name_en : 'منشأة');
    }
}
