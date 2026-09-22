<?php

namespace App\Support;

/**
 * The capabilities an installation can switch on or off.
 *
 * A flag is only listed here if some backend path refuses or allows work on
 * it. Flags that gate nothing — and the settings that duplicated a setting
 * (`offer_review_required` gated the same behaviour as `offer.requires_review`)
 * — were removed rather than kept as documentation, because an admin console
 * that offers a dead switch teaches everyone to distrust the live ones.
 *
 * Anything involving money leaving or being held by the platform starts
 * disabled: it stays off until a real payment provider is wired behind the
 * integration contract, at which point enabling it is an operator decision
 * instead of a code release.
 */
final class FlagCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            self::row('marketplace', true, 'سوق العروض والطلبات', 'Marketplace', 'نشر العروض والتصفح والشراء'),
            self::row('demand_rfq', true, 'طلبات الشراء وعروض الأسعار', 'Demands & RFQ', 'إنشاء الطلبات وردود البائعين'),
            self::row('matching_engine', true, 'محرك المطابقة', 'Matching Engine', 'مطابقة قواعد بين العرض والطلب'),
            self::row('geo_discovery', true, 'الفرص القريبة', 'Nearby Discovery', 'البحث الجغرافي بالعروض القريبة'),

            // Interest and public questions on a listing. On by default because
            // every path behind it is written and tested: unlike the money
            // capabilities below, nothing here waits on an outside provider.
            self::row('community', true, 'الاهتمام والأسئلة على العروض', 'Offer Community', 'إعجابات منشأة وتعليقاتها العامة وبلاغات التعليقات'),

            // Issuing a key is built (hashed, scoped, revocable); using one is
            // not: no guard accepts a key, so `ApiKeyAuthenticationService::authenticate()`
            // has no callers and a key handed to a partner today opens nothing.
            // Advertising it as an integration would be a false control, so the
            // capability stays off until a request path authenticates it.
            self::row('api_keys', false, 'مفاتيح API', 'API Keys', 'البنية جاهزة؛ لم يُفعّل بعد أي مسار مصادقة بالمفتاح'),

            self::row('webhooks', false, 'إشعارات الويب هوك', 'Webhooks', 'يتطلب مستقبِلًا عامًا عبر HTTPS؛ تُرفض العناوين الداخلية وتُوقَّع الشحنات بـ HMAC'),
            self::row('double_entry_ledger', false, 'دفتر الأستاذ المزدوج', 'Double-Entry Ledger', 'يتطلب ربطًا فعليًا بحسابات مالية'),
            self::row('escrow', false, 'حساب الضمان المالي', 'Escrow', 'يتطلب بوابة دفع وأموالًا حقيقية'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(string $key, bool $enabled, string $nameAr, string $nameEn, string $descriptionAr): array
    {
        return [
            'key' => $key,
            'is_enabled' => $enabled,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'description_ar' => $descriptionAr,
        ];
    }
}
