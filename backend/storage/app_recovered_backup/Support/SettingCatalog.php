<?php

namespace App\Support;

/**
 * The platform's one vocabulary of configuration keys.
 *
 * Why this exists: the installer, the reference seeder and the code that
 * *reads* settings had each invented their own key names
 * (`platform.commission_rate` vs `commission.rate`,
 * `marketplace.require_offer_moderation` vs `offer.requires_review`). A
 * setting an operator edited in the admin console was therefore not the
 * setting the service layer consulted, and the code silently fell back to its
 * own compiled-in number — which is how a 2.5% commission and a 15% VAT ended
 * up priced into real transactions without anyone approving them.
 *
 * Rules for anything that appears here:
 *   1. Every key is read by code. A key nobody reads is not configuration, it
 *      is decoration, and it is left out.
 *   2. Every default is either neutral (0, false) or an operator choice made
 *      during installation. No tax rate, commission rate, currency or country
 *      is presented as a fact about the world.
 *   3. Both `database/seeders/DatabaseSeeder.php` and
 *      `app:install-system` seed from this list, and neither may add a key of
 *      its own.
 */
final class SettingCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            // ── Platform identity ────────────────────────────────────────────
            self::row('platform.name_ar', 'منصة حصّال', 'string', 'platform', true, 'اسم المنصة بالعربية'),
            self::row('platform.name_en', 'HASSAL', 'string', 'platform', true, 'Platform name in English'),
            self::row('platform.default_locale', 'ar', 'string', 'platform', true, 'اللغة الافتراضية', 'Default locale'),
            self::row('platform.country', 'YE', 'string', 'platform', true, 'الدولة التي تبدأ فيها المنصة', 'Country the platform starts in'),
            self::row('platform.default_city', 'عدن', 'string', 'platform', true, 'المدينة التي تبدأ منها المنصة', 'Launch city'),
            self::row('platform.default_currency', 'YER', 'string', 'platform', true, 'العملة الأساسية', 'Base currency code'),
            self::row('platform.timezone', 'Asia/Aden', 'string', 'platform', true, 'المنطقة الزمنية', 'Timezone'),

            // ── Money ────────────────────────────────────────────────────────
            // Every one of these starts at zero on purpose. The platform does
            // not guess a tax it has not been told to charge, and it does not
            // take a commission an operator has not set.
            self::row('tax.enabled', '0', 'boolean', 'tax', true, 'تفعيل احتساب الضريبة', 'Enable tax calculation'),
            self::row('tax.name', 'ضريبة المبيعات', 'string', 'tax', true, 'اسم الضريبة في المستندات', 'Tax label on documents'),
            self::row('tax.rate', '0.0000', 'decimal', 'tax', true, 'نسبة الضريبة؛ يقررها المشرف بعد التحقق القانوني', 'Tax rate; an operator decision'),
            self::row('tax.inclusive', '0', 'boolean', 'tax', true, 'هل الأسعار شاملة الضريبة', 'Displayed prices include tax'),
            self::row('commission.rate', '0.0000', 'decimal', 'commission', false, 'عمولة المنصة من صافي الصفقة', 'Platform commission rate'),

            // ── Operations ───────────────────────────────────────────────────
            self::row('reservation.timeout', '30', 'integer', 'operations', true, 'مهلة حجز المخزون بالدقائق', 'Inventory hold in minutes'),
            self::row('inspection.window', '48', 'integer', 'operations', true, 'نافذة الفحص بعد التسليم بالساعات', 'Post-delivery inspection window in hours'),
            self::row('demand.expiry_days', '14', 'integer', 'operations', false, 'انتهاء طلب الشراء بالأيام', 'Demand lifetime in days'),
            self::row('offer.expiry_days', '60', 'integer', 'operations', false, 'انتهاء العرض بالأيام', 'Offer lifetime in days'),
            self::row('offer.requires_review', '0', 'boolean', 'operations', false, 'مراجعة المشرف قبل نشر العرض', 'Moderate offers before publishing'),

            // How many pinned listings the market puts at the top of every
            // visitor's screen. Read by `OfferController::featured()`, which also
            // clamps a hand-edited value into 1..30 so an operator typing 5000
            // gets a ceiling rather than a full-table sort on the home page.
            // Extra pins are not discarded — they wait their turn behind the
            // newest ones, and the response says how many there are.
            self::row('marketplace.featured_limit', '8', 'integer', 'operations', true, 'عدد العروض المميزة المعروضة أعلى السوق', 'Featured offers shown at the top of the marketplace'),

            // ── Signing in ───────────────────────────────────────────────────
            // Whether a Google account may open a session here is a deployment
            // choice, and the two values that decide it are together: a switch
            // with no client ID behind it shows a button that cannot work, and
            // a client ID with the switch off accepts a token nobody enabled.
            //
            // The client ID is a setting and not a constant because it identifies
            // *this* installation to Google — a copy of the product moved to
            // another operator must carry its own, and editing a file plus
            // redeploying is not how an operator changes their own configuration.
            // It is public because it is public: a client ID is sent to every
            // browser that renders the button and protects nothing; what must
            // stay secret is the client *secret*, which this design never uses
            // (an ID token is verified by signature, not by a shared secret).
            // Whether a Google assertion that matches no account here may
            // create one. On, sign-in and sign-up are the same act, which is
            // what the button looks like it does. Off, the platform is an
            // invitation list: a person registers first and links Google
            // afterwards, and nothing enters on an address this platform never
            // heard of. The default is on because a marketplace that nobody can
            // join has no supply; an operator who wants the closed list turns
            // it off without a deploy.
            self::row('auth.google_auto_register', '1', 'boolean', 'auth', false, 'إنشاء حساب تلقائياً عند أول دخول عبر جوجل', 'Create an account on first Google sign-in when none matches'),
            self::row('auth.google_enabled', '0', 'boolean', 'auth', true, 'السماح بفتح الجلسة عبر حساب جوجل', 'Allow sessions to be opened with a Google account'),
            self::row('auth.google_client_id', '', 'string', 'auth', true, 'معرّف العميل من مشروع جوجل لهذا النشر', 'OAuth client ID issued to this deployment by Google Cloud'),

            // What a person owes this platform before they can trade in it.
            // Google or a signup form can supply a name and an address nobody
            // else has checked; a phone number is the one contact a counterparty
            // can actually reach when a delivery goes wrong, and a national
            // number is what distinguishes two people of the same name. Whether
            // either is demanded is a policy of this marketplace, so both are
            // switches an operator sets — and `UserCompletionController` refuses
            // the missing data at the server, because a screen that asks for a
            // field is a request and a server that checks it is a rule.
            self::row('account.require_phone_number', '1', 'boolean', 'auth', true, 'إلزام إكمال رقم الهاتف قبل التصرف التجاري', 'A phone number must be completed before an account can trade'),
            self::row('account.require_national_id', '0', 'boolean', 'auth', true, 'إلزام إكمال الرقم الوطني أو المدني', 'A national or civil ID must be completed before an account can trade'),

            // How many businesses one account may found. It is a ceiling on the
            // account, not on the person: the limit exists because registration
            // is free and a business record is the thing a counterparty relies
            // on, so an unbounded count is the same script — open a hundred
            // storefronts, use four, abandon the rest — that fills a marketplace
            // with names no one stands behind. Platform staff are exempt: an
            // operator seeding reference tenants is doing the job, not the
            // register-once-per-phone trick the ceiling refuses.
            self::row('account.max_organizations_per_account', '3', 'integer', 'auth', false, 'أقصى ما يُنشئه حساب واحد من منشآت؛ صفر يعني بلا سقف', 'Maximum number of businesses one account may found; 0 means unlimited'),

            // ── Legal identity requirements ──────────────────────────────────
            // Whether a business must hold a commercial registration, and
            // whether it must show a tax number, is a question about the law of
            // the country this deployment runs in. The code cannot know it, and
            // hardcoding it once excluded every business the law did not
            // require — a small grocery with a shop licence and no CR is still a
            // real counterparty. Both switches gate the registration form only;
            // neither says anything was checked against an official register.
            self::row('legal.require_commercial_registration', '0', 'boolean', 'legal', false, 'هل رقم السجل التجاري إلزامي عند تسجيل منشأة', 'Require a commercial registration number when a business registers'),
            self::row('legal.require_vat_number', '0', 'boolean', 'legal', false, 'هل الرقم الضريبي إلزامي عند تسجيل منشأة', 'Require a tax number when a business registers'),

            // ── Trust and moderation ─────────────────────────────────────────
            // Which commercial actions a business may take before a platform
            // reviewer has read its legal record is a policy choice about this
            // marketplace. It defaults to "not yet", because a listing from an
            // unreviewed company is the one case a buyer cannot self-serve
            // around — but a deployment that opens with hand-held invitations
            // may legitimately switch it off. It is a switch, not a claim that
            // any register was consulted.
            self::row('marketplace.require_verified_to_trade', '1', 'boolean', 'trust', true, 'منع النشر وطلبات الشراء والصفقات قبل مراجعة وثائق المنشأة', 'Refuse commercial actions until the business record has been reviewed'),

            // A comment written on a listing is public and attributed to its
            // author's company. Pre-moderation holds each one until a reviewer
            // publishes it; off, comments appear immediately and a report
            // queue is what a reviewer works from.
            self::row('community.moderate_before_publishing', '0', 'boolean', 'trust', true, 'مراجعة التعليق قبل إظهاره للعامة', 'Hold public comments until a reviewer publishes them'),

            // ── Request ceilings ─────────────────────────────────────────────
            // Every route in this platform is one JSON call away from a database
            // query, so an account that decides to loop is an outage. The limiter
            // in AppServiceProvider reads these three numbers per request; the
            // values in config/hassal.php are only what it falls back to before
            // an installer has run. Exceeding one answers 429 TOO_MANY_REQUESTS.
            self::row('security.requests_per_minute', '300', 'integer', 'security', false, 'حد الطلبات في الدقيقة لكل حساب مسجّل الدخول؛ ما يتجاوزها يُرد 429', 'Requests per minute per authenticated account; extras answer 429'),
            self::row('security.guest_requests_per_minute', '60', 'integer', 'security', false, 'حد الطلبات في الدقيقة لكل عنوان غير مسجّل', 'Requests per minute per unauthenticated address'),
            self::row('security.organization_requests_per_minute', '1500', 'integer', 'security', false, 'سقف مشترك لكل حسابات المنشأة الواحدة في الدقيقة', 'Shared per-minute ceiling for every account of one organization'),

            // ── Tenant storage ───────────────────────────────────────────────
            // How much disk one business may occupy is a commercial policy, so it
            // lives here and an operator can change it. The per-file ceilings are
            // NOT here: those are limits of the machine (config/hassal.php), and
            // a web form must not be able to raise them past what the server can
            // hold. Zero means no ceiling.
            self::row('uploads.quota_bytes_per_organization', '268435456', 'integer', 'uploads', false, 'سقف مساحة صور المنتجات ووثائق المنشأة الواحدة بالبايت؛ صفر يعني بلا سقف', 'Total bytes one organization may hold in product images and filed documents; 0 means unlimited'),

            // ── Discovery and matching ───────────────────────────────────────
            self::row('matching.geo_radius_km', '100', 'integer', 'matching', false, 'نصف قطر المطابقة الجغرافي', 'Matching radius in km'),
            self::row('geo.max_radius_km', '500', 'integer', 'matching', true, 'أقصى نصف قطر مسموح للبحث القريب', 'Maximum nearby radius'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(
        string $key,
        string $value,
        string $type,
        string $group,
        bool $isPublic,
        string $descriptionAr,
        string $descriptionEn = ''
    ): array {
        return [
            'key' => $key,
            'value' => $value,
            'type' => $type,
            'group' => $group,
            'is_public' => $isPublic,
            'description_ar' => $descriptionAr,
            'description_en' => $descriptionEn,
            'default_value' => $value,
            'is_editable' => true,
        ];
    }
}
