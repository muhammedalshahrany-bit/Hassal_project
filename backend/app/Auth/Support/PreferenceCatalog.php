<?php

namespace App\Auth\Support;

use App\Platform\Support\SettingCatalog;

/**
 * The one vocabulary of *per-account* display and behaviour choices.
 *
 * `SettingCatalog` is what the platform decides for everybody. This is what one
 * person decides for themselves, and the difference decides where a value
 * lives: a preference must follow the account from handset to browser, so it is
 * stored here and served by `GET /auth/me` rather than kept in a device's
 * `SharedPreferences`. A client that stored these locally would show a person
 * their own organization's name on the phone they hid it from, on the laptop.
 *
 * Rules, the same ones the setting catalog enforces:
 *   1. Every key is read by a client. A key nothing reads is decoration.
 *   2. Every default is the behaviour that existed before the switch — a
 *      preference table must not silently change what an established account
 *      sees the day it is introduced.
 *   3. A client cannot invent a key. `sanitize()` drops unknown names rather
 *      than storing them, so the column cannot become a dumping ground for
 *      whatever a screen felt like caching; that is what makes a JSON column
 *      auditable instead of a junk drawer.
 */
final class PreferenceCatalog
{
    public const HIDE_ORGANIZATION_NAME = 'hide_organization_name';

    public const HIDE_SELLER_NAME = 'hide_seller_name';

    public const COMPACT_LISTS = 'compact_lists';

    public const THEME = 'theme';

    public const LANGUAGE = 'language';

    public const NOTIFICATIONS_ENABLED = 'notifications_enabled';

    public const NOTIFY_DEALS = 'notify_deals';

    public const NOTIFY_OFFERS = 'notify_offers';

    public const NOTIFY_MODERATION = 'notify_moderation';

    public const NOTIFY_DEMANDS = 'notify_demands';

    public const NOTIFY_SUPPORT = 'notify_support';

    public const OFFLINE_BROWSING = 'offline_browsing';

    /**
     * @return array<string, array{type: string, default: mixed, group: string, ar: string, en: string, values?: array<int, string>}>
     */
    public static function all(): array
    {
        return [
            // ── What a person shows other people ─────────────────────────────
            // The business line under every screen title is the acting
            // organization's name. A person who presents the app to a customer
            // on a shared tablet, or who screenshares a pipeline, reasonably
            // wants that line gone without signing out of the business.
            self::HIDE_ORGANIZATION_NAME => [
                'type' => 'boolean',
                'default' => false,
                'group' => 'identity',
                'ar' => 'إخفاء اسم المنشأة في الشاشات',
                'en' => 'Hide the organization name on screen',
            ],
            // A buyer-side reading preference. It governs only what *this*
            // account sees: a seller who published with `show_seller_identity`
            // off was already hidden, and hiding everyone's name for one
            // account changes nothing about what the publisher agreed to.
            self::HIDE_SELLER_NAME => [
                'type' => 'boolean',
                'default' => false,
                'group' => 'display',
                'ar' => 'إخفاء أسماء البائعين في القوائم',
                'en' => 'Hide seller names in lists',
            ],
            self::COMPACT_LISTS => [
                'type' => 'boolean',
                'default' => false,
                'group' => 'display',
                'ar' => 'قوائم مضغوطة (بطاقات أصغر)',
                'en' => 'Compact lists',
            ],
            // ── Look and language ────────────────────────────────────────────
            self::THEME => [
                'type' => 'enum',
                'default' => 'system',
                'values' => ['system', 'light', 'dark'],
                'group' => 'display',
                'ar' => 'السمة',
                'en' => 'Theme',
            ],
            self::LANGUAGE => [
                'type' => 'enum',
                'default' => 'ar',
                'values' => ['ar', 'en'],
                'group' => 'display',
                'ar' => 'اللغة',
                'en' => 'Language',
            ],
            // ── Notices ──────────────────────────────────────────────────────
            // The master switch first, then the channels under it. A client
            // that ignores the master and honours only the channels would keep
            // counting badges for a person who asked for none.
            self::NOTIFICATIONS_ENABLED => [
                'type' => 'boolean',
                'default' => true,
                'group' => 'notifications',
                'ar' => 'تفعيل التنبيهات',
                'en' => 'Enable notifications',
            ],
            self::NOTIFY_DEALS => [
                'type' => 'boolean',
                'default' => true,
                'group' => 'notifications',
                'ar' => 'تنبيهات الصفقات وطلبات الشراء',
                'en' => 'Deals and purchase requests',
            ],
            // What the market put on its shelves. This is the one notice that is
            // not about the person's own business at all — a deal, a document or
            // a support answer names them; a new listing is news from a stranger
            // — so it has its own switch rather than riding on the deals one, and
            // it is the switch a person who wants a quiet phone turns off first.
            //
            // Its default is the exception to rule 2 above, and deliberately so:
            // the platform was asked to bring new offers to every account, and a
            // switch that ships off would be that request unfulfilled behind a
            // setting nobody goes looking for.
            self::NOTIFY_OFFERS => [
                'type' => 'boolean',
                'default' => true,
                'group' => 'notifications',
                'ar' => 'تنبيهات العروض الجديدة في السوق',
                'en' => 'New offers in the market',
            ],
            self::NOTIFY_MODERATION => [
                'type' => 'boolean',
                'default' => true,
                'group' => 'notifications',
                'ar' => 'تنبيهات المراجعة والوثائق',
                'en' => 'Moderation and documents',
            ],
            self::NOTIFY_DEMANDS => [
                'type' => 'boolean',
                'default' => true,
                'group' => 'notifications',
                'ar' => 'تنبيهات طلبات الاحتياج والمطابقات',
                'en' => 'Demands and matches',
            ],
            // A support answer is its own channel because it is the one notice
            // about the platform rather than about a business: a person who
            // muted deal traffic still has to learn that the bug they reported
            // was fixed, and a person who wants none of them should be able to
            // stop only that one.
            self::NOTIFY_SUPPORT => [
                'type' => 'boolean',
                'default' => true,
                'group' => 'notifications',
                'ar' => 'تنبيهات الدعم الفني وردّه على بلاغاتك',
                'en' => 'Support replies',
            ],
            // ── Data ─────────────────────────────────────────────────────────
            // Offline reading is a privacy and storage choice, not an
            // implementation detail: a person on a shared handset may not want
            // a cached market sitting on it. Off means the app reads the
            // network or says it cannot, and keeps nothing.
            self::OFFLINE_BROWSING => [
                'type' => 'boolean',
                'default' => true,
                'group' => 'data',
                'ar' => 'الحفظ للقراءة دون إنترنت',
                'en' => 'Keep data for offline reading',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * The stored shape every account starts from.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::all() as $key => $spec) {
            $out[$key] = $spec['default'];
        }

        return $out;
    }

    /**
     * Merge a submitted patch onto the current stored map and drop what is not ours.
     *
     * Unknown keys are discarded rather than rejected. A client from a newer
     * build sending a key this deployment has not defined yet should keep
     * working, and a stored map full of unread keys is the failure this whole
     * class exists to prevent — so the response's `ignored` field is what makes
     * the discard visible instead of silent.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $patch
     * @return array{preferences: array<string, mixed>, ignored: array<int, string>}
     */
    public static function merge(array $current, array $patch): array
    {
        $specs = self::all();
        $ignored = [];
        $next = self::defaults();

        // A stored map is authoritative over the compiled defaults, but only for
        // keys this build still defines: a key retired from the catalog must not
        // be carried forward forever by the row that remembers it.
        foreach ($current as $key => $value) {
            if (isset($specs[$key])) {
                $next[$key] = $value;
            }
        }

        foreach ($patch as $key => $value) {
            if (! isset($specs[$key])) {
                $ignored[] = (string) $key;
                continue;
            }

            $coerced = self::coerce($specs[$key], $value);
            if ($coerced === self::REJECTED) {
                $ignored[] = (string) $key;
                continue;
            }
            $next[$key] = $coerced;
        }

        return ['preferences' => $next, 'ignored' => array_values(array_unique($ignored))];
    }

    private const REJECTED = "\0rejected";

    /**
     * @param  array<string, mixed>  $spec
     */
    private static function coerce(array $spec, mixed $value): mixed
    {
        if ($spec['type'] === 'boolean') {
            return match (true) {
                is_bool($value) => $value,
                $value === 'true' || $value === 1 || $value === '1' => true,
                $value === 'false' || $value === 0 || $value === '0' => false,
                default => self::REJECTED,
            };
        }

        // An enum is the only string worth storing here, and it is checked
        // against its own list: `theme => 'pinned to a dark corner'` would be a
        // value no client renders, and a column that accumulates those stops
        // being a preference map.
        if ($spec['type'] === 'enum') {
            return is_string($value) && in_array($value, $spec['values'], true)
                ? $value
                : self::REJECTED;
        }

        return self::REJECTED;
    }
}
