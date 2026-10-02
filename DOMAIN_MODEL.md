# منصة حصّال (HASSAL) — نموذج النطاق وحدود الأعمال (DOMAIN_MODEL.md)

> **كيف تُقرأ هذه الصفحة.** كل سطر هنا مقابل بملف في المستودع أو بجدول في
> القاعدة الفعلية، والمصدر مذكور بجانبه حتى يُعاد فحصه. الأعمدة وأنواعها في
> `DATABASE.md` (مولّدة من `information_schema`)، وعناوين المسارات واستجاباتها في
> `API_CONTRACT.md` و`backend/API_CONTRACT.md`، وحدود الصلاحيات التنفيذية في
> `backend/SECURITY.md`.
>
> **ما جرى حذفه من هذا الملف.** الإصدار السابق كان يصف نظامًا لم يُبنَ: كيانات
> `InventoryLedger` و`PriceSchedule` و`Shipment` و`Courier` و`SettlementBatch`
> و`Evidence`، وآلات حالة غير موجودة (REJECTED وARCHIVED للعروض؛ REQUESTED
> وPAYMENT_PENDING وPAID وPREPARING وSHIPPED وRECEIVED للصفقات)، وحقل
> `offers.currency` بنوع `Decimal(14,2)` لـ `unit_price`، ومصفوفة صلاحيات
> بأسماء مثل `create_offers` و`arbitrate_disputes` لا يوجد منها اسم في الكود،
> و«كتالوج أحداث نطاق» (`OfferCreatedEvent` وأخواتها) بينما لا يوجد مجلد
> `app/Events` ولا `app/Listeners` ولا `app/Jobs` في المشروع أصلًا. البنية الحقيقية
> أدناه.

---

## 1. النطاقات الفرعية كما هي موزّعة فعلًا

| النطاق | الجداول | الكود الذي يملك القرار |
| :--- | :--- | :--- |
| الهوية والعضوية | `users`, `organizations`, `organization_memberships`, `organization_roles`, `organization_role_permissions`, `organization_membership_permissions`, `roles`, `permissions`, `model_has_roles` | `AuthController`, `OrganizationController`, `OrganizationRoleController`, `RoleGovernanceController`, `config/rbac.php`, `Database\Seeders\RolesAndPermissionsSeeder` |
| سياق المستأجر | — | `App\Services\TenantContext`, `App\Http\Middleware\ResolveOrganizationContext` (الاسم `tenant`) |
| الكتالوج | `categories`, `brands`, `products`, `product_variants` | `CatalogController`, `App\Support\CategoryCatalog` |
| المستودعات والاكتشاف الجغرافي | `warehouses` | `WarehouseController`, `App\Services\GeoDiscoveryService` |
| العرض والمخزون | `offers`, `offer_items`, `inventory_movements`, `reservations` | `OfferController`, `InventoryMovementController`, `App\Services\InventoryReservationService` |
| الطلب وعروض الأسعار والمطابقة | `demands`, `quotations`, `demand_matches` | `DemandController`, `App\Services\DemandMatchingService` |
| دورة الصفقة | `purchase_requests`, `deals`, `deal_items`, `deal_status_histories`, `deal_handovers`, `disputes` | `PurchaseRequestController`, `DealController`, `DealHandoverController`, `DisputeController`, `App\Services\DealStateMachineService`, `App\Services\DealHandoverService` |
| التسعير والعملة | `currencies`, `system_settings` | `App\Services\PricingAndCurrencyService`, `CurrencyManagementController`, `App\Services\SystemSettingsService` |
| دفتر الأستاذ بالقيود المزدوجة | `ledger_accounts`, `journal_entries`, `ledger_lines` | `App\Services\DoubleEntryLedgerService`, `LedgerController` |
| الإعدادات والقدرات | `system_settings`, `feature_flags` | `SystemSettingController`, `FeatureFlagController`, `App\Support\SettingCatalog`, `App\Support\FlagCatalog`, `EnsureFeatureEnabled` |
| التكامل الخارجي | `webhooks`, `webhook_deliveries`, `api_keys`, `idempotency_keys`, `notifications`, `audit_logs` | `WebhookController`, `App\Services\WebhookDispatcherService`, `ApiKeyController`, `App\Services\Notifications\NotificationManager`, `AuditLogMiddleware` |

> سطر «الدفع عبر مزوّد خارجي» الذي كان هنا أُزيل: `app/Services/Payments` ومحول
> `PaymentProviderInterface` حُذفت من الكود، وجدول `payments` لم يُنشأ في أي هجرة.
> المنصّة لا تحتفظ بأموال ولا تحرّكها — التسوية تجري خارجها، وقرار البرومبت §20
> أن لا Wallet ولا Payment ضمن النطاق.

ملاحظة على الحدود: لا يوجد فصل بحزم مستقلة (Modules / DDD)؛ الفصل هنا هو فصل
الخدمة التي تملك القرار مقابل المتحكم الذي يقرأ الطلب فقط. ما لم يُحترم في هذا
الفصل يُذكر صراحة في القسم 6.

---

## 2. الكيانات وقواعد النزاهة

> اصطلاح متَّبع في هذا القسم: `؟` بعد اسم عمود تعني أنه `NULL` في القاعدة،
> و`؟` بعد اسم إعداد تعني أن لا قيمة افتراضية مضغوطة في الكود.

### 2.1 الهوية والعضوية

- **`User`** — فرد، لا كيان تجاري: `name`, `email` (فريد), `phone` (فريد ومسموح
  بالغياب، ويُقبل بديلًا عن البريد في الدخول), `national_id`؟, `status`,
  `password`. لا سجل تجاري ولا رقم ضريبي على المستخدم: هذه بيانات المنشأة.
- **`Organization`** — العمل التجاري: `name_ar`, `name_en`, `trade_name_ar/en`؟,
  `type` (`seller` أو `buyer` عند التسجيل الذاتي؛ `intermediary` و`admin`
  ينشئهما فقط من يحمل `SuperAdmin` — `StoreOrganizationRequest:45-52`),
  `country_code`, `city`, `commercial_registration_number`؟, `vat_number`؟,
  `status`, `kyc_verified_at`؟, `credit_limit` `decimal(14,2)`,
  `rating`؟ `decimal(3,2)`.
  **الحقول القانونية اختيارية حسب إعداد المسؤول**
  (`legal.require_commercial_registration`, `legal.require_vat_number` في
  `App\Support\SettingCatalog`)، وغياب الرقم يعني «لم يُطلب بعد» لا «غير موجود».
  لا يوجد في الكود أي تحقّق من جهة حكومية: `kyc_verified_at` يكتبه موظف
  المنصة بعد مراجعة، ولا يجوز وصفه بأنه توثيق رسمي.
- **`OrganizationMembership`** — الصلة بين المستخدم والمنشأة. `role` فيها **مفتاح
  وقفٍ تملكه تلك المنشأة** (`organization_roles.key`)، لا قيمة من قائمة عالمية:
  الخُمسة المزروعة `owner`, `admin`, `staff`, `finance`, `auditor` وما تسمّيه
  الشركة لنفسها. هذه **صفات داخل المنشأة** لا أدوار Spatie (القسم 4)، وباب
  العضوية يتحقق من المفتاح ضد أدوار شركته لا ضد إعداد ثابت — لأن قاموس الشركة
  صفوف في جدول لا كلمات في `CHECK`.
- **قاعدة النزاهة:** كل مسار مكتوب تحت مجموعة `auth:sanctum` + `tenant`
  (`routes/api.php:100`). بلا سياق منشأة لا تُكتب حركة تجارية، ومن يرسل
  `X-Organization-Id` لغير منشأته يُردّ بـ 403 `ORGANIZATION_ACCESS_DENIED`.
  الاستثناء المقصود: `SuperAdmin` يُقبل بلا عضوية.

### 2.2 الكتالوج

- **`Category`** مشترك ومملوك للمنصة (`parent_id`؟ للتسلسل، `code`؟ فريد،
  `slug` فريد، `is_active`)؛ تُseed من `App\Support\CategoryCatalog` عند التنصيب.
- **`Brand`** للمنصة أيضًا (`organization_id` غير موجود في الجدول).
- **`Product`** — `organization_id`؟: أي أن الصنف قد يكون مشتركًا أو مملوكًا
  لمنشأة، وهذا قرار نطاق صريح موثّق في `backend/CONFIGURATION.md`.
- **`ProductVariant`** — الوحدة المتداولة: `sku` فريد، `barcode`؟، وزن
  `decimal(10,3)`، `attributes_json`؟.
- **قاعدة النزاهة:** العرض يُربط بـ `product_variant_id` لا بمنتج عام
  (`offers.product_variant_id` NOT NULL)، والعنصر التفصيلي في `offer_items`
  يحتفظ بالمنتج والصنف معًا.

### 2.3 العرض (`Offer`)

سجل بيع من منشأة بائعة، مرتبط بمستودع وبصنف محدد:

- الهوية والعرض: `seller_organization_id`, `warehouse_id`, `product_variant_id`,
  `category_id`؟, `title_ar`, `opportunity_reason` + `opportunity_reason_notes`؟.
- **الصنف لم يعد حقلًا على العرض (تطبيع 2026-09-25):** صنفُ العرض صنفُ منتجه،
  عبر `product_variant_id → product_variants.product_id → products.category_id`.
  العمود المخزَّن رُفع لأنه لم يكن تكرارًا بل خلافاً محتملاً: منتجٌ يُنقل تصنيفه
  تُركت رفوفُه في الصنف الذي غادره. الاسم باقٍ في الاستجابة وفي المرشّح، ولا
  يُكتب من مسار؛ الخلاف بين صنف المنتج والصنف المسمّى `CATEGORY_PRODUCT_MISMATCH`.
- الكميات (`decimal(14,2)`): `total_quantity`, `available_quantity`,
  `reserved_quantity`, `sold_quantity`، مع `min_order_quantity` (افتراضي 1)
  و`max_order_quantity`؟.
- السعر: `unit_price` **`decimal(18,4)`**، `currency_code` `varchar(3)`
  (الافتراضي `YER`، والقيم من جدول `currencies`)، `unit`.
- الشروط: `storage_condition`, `delivery_terms`, `inspection_window_hours`
  (افتراضي 48), `production_date`؟, `expiry_date`؟, `expires_at`؟, `status`.
- **قاعدة الكميات:** الحجز ينقل من `available` إلى `reserved`؛ الإلغاء أو
  الانتهاء يعيد من `reserved` إلى `available`؛ التسوية تنقل من `reserved` إلى
  `sold`. لا مسار يزيد `total_quantity` بعد النشر — التزويد كميةٌ جديدة بعرض
  جديد (موثّق داخل جدول الانتقالات في 3.1).
- **الظهور العام:** `isPubliclyVisible()` يسمح بـ `ACTIVE` و`SOLD_OUT`
  و`EXPIRED` فقط (`Offer.php:100-107`)، و`scopeTradable()` يستبعد عروض من
  `status` منشأتهم `SUSPENDED` أو `REJECTED` (`Offer.php:118-132`).

### 2.4 طلب الشراء والحجز والصفقة

- **`PurchaseRequest`** — سؤال المشتري: `offer_id` (+ `demand_id`؟ و
  `demand_match_id`؟ إن جاء من مطابقة)، `requested_quantity`, `requested_price`,
  `delivery_preference`, `status`, `proposed_terms`؟ (JSON).
- **`Reservation`** — الكمية الموقوفة باسم مشتري: `quantity`, `unit_price`,
  `total_amount`, `expires_at` **NOT NULL** (مدّتها من `reservation.timeout`
  بالدقائق، `InventoryReservationService:82-86`).
- **`Deal`** — الوثيقة المالية الموقّعة من الطرفين، وتحمل لقطة كاملة:
  `base_amount`, `discount_amount`, `tax_rate_snapshot` و`tax_amount`،
  `platform_fee_rate_snapshot` و`platform_fee_amount`, `delivery_fee_amount`,
  `buyer_total_amount`, `seller_net_amount` (كلها `decimal(18,4)`؛ النِسَب
  `decimal(6,4)`)، إضافة إلى لقطة العملة (`base_currency`, `currency_code`,
  `exchange_rate_snapshot`, `rate_source`, `rate_timestamp`). كانت اللقطة تُخزَّن
  مرتين — `transaction_currency` مع `currency_code`، و`exchange_rate` حيًّا بجانب
  `exchange_rate_snapshot` المجمَّدة — فَرُفع العمودان المكرران في تطبيع
  2026-09-25: عمودان لسعرٍ واحد معناهما أن كل تصحيح لاحقًا يجب أن يكتب مرتين أو
  يُخطئ، واللقطة وجودُها تجميدُ الوعد لا متابعةُ السوق؛ المعدل المتحرّك يبقى في
  `currencies.exchange_rate_to_base` ويُقرأ من هناك عند الحاجة.
- **قاعدة التسلسل (لا اختصار فيها):**
  `عرض ACTIVE` → `طلب شراء REQUESTED` → **قبول البائع هو الذي يحجز**
  (`PurchaseRequestController::accept:338` يستدعي `InventoryReservationService::reserve`)
  → `تحويل الحجز إلى صفقة` (`DealController@store`، والحالة الابتدائية
  `PENDING_SELLER_APPROVAL`) → انتقالات الصفقة. لا تُنشأ صفقة من عرض مباشرة؛
  لا يوجد مسار يخلق `Deal` بلا `reservation` مُحوَّل أو `purchase_request`.
- **العملة والإجماليات:** كل ما يُخزَّن على الصفقة بعملة معاملتها. الرقم
  المحوَّل إلى العملة الأساسية (`base_currency_amount`) حسابيٌّ للعرض فقط
  ويعتمد على سعر يُدخله المسؤول، والاتجاه موثّق عند مصدره:
  `currencies.exchange_rate_to_base` = كم من هذه العملة يعادل وحدة واحدة من
  العملة الأساسية، فالمحوَّل = المبلغ ÷ السعر
  (`PricingAndCurrencyService:79`). الضريبة والعمولة لا معدل لهما في الكود:
  `0.0000` يعني أن المنصة لا تحتسبها ما لم يقرر المسؤول خلاف ذلك.

### 2.5 المطابقة وعروض الأسعار

- **`Demand`** — احتياج مشتري: `category_id`؟, `target_quantity`,
  `target_unit_price`؟, `delivery_city`, `delivery_latitude/longitude`؟,
  `target_delivery_date`؟, `expires_at`؟.
- **`DemandMatch`** — نتيجة محرك المطابقة: `match_score` `decimal(5,2)`,
  `match_reasons` JSON, `estimated_total`, `requested_quantity`. نقطة القطع
  للتخزين `MIN_SCORE = 45.0` (`DemandMatchingService:35`)، والأوزان: تصنيف 30،
  كمية كاملة 15 / جزئية 8، سعر ضمن الهدف 15 / قريب منه (≤10%) 5، تطابق مدينة
  المستودع مع مدينة التسليم 10، حداثة النشر خلال 7 أيام 5، إتاحة حتى تاريخ
  التسليم 5. **شروط التنفيذ لا تُسجَّل نقاطًا**: الطلب لا يحمل تفضيل تسليم،
  فتسجيلها نقطة لكل عرض كان سيمنح كل العروض نفس النقاط.
- **`Quotation`** — رد بائع على طلب: `quoted_quantity`, `unit_price`,
  `total_amount`, `validity_days`, `converted_deal_id`؟.

### 2.6 دفتر الأستاذ والمخزون

- **دليل الحسابات** (اثنان، وهما كل ما في القاعدة الحيّة):
  `1010-FEE-RECEIVABLE` (أصل) و`4010-COMMISSION-REVENUE` (إيراد).
  حسابات `1010-CASH-CLEARING` و`1020-BUYER-RECEIVABLE` و`2010-ESCROW-HOLDING` و
  `2020-SELLER-PAYABLE` و`2030-VAT-OUTPUT` كانت في تصميم لم يُبنَ: لا قيد يمسّها
  بعد إزالة حالات الدفع، و`ensureChartOfAccounts()` لا ينشئ إلا الحسابين أعلاه.
- **القيد** يسجَّل بعملة العملية ويحمل `exchange_rate_to_base = 1.000000`
  (`DoubleEntryLedgerService`) — أي لا إعادة تقييم تلقائية إلى العملة
  الأساسية داخل الدفتر. القيد يُنشأ عند `COMPLETED` فقط (استحقاق عمولة المنصة
  عن صفقة وقعت فعلًا)، وحين يكون علم `double_entry_ledger` مفعّلًا — وهو مغلق
  افتراضياً.
- **الحركة المخزنية** (`inventory_movements`) توثّق `type` و`quantity` مع
  `previous_quantity`/`new_quantity`، وتُربط اختياريًا بـ `offer_id` و`deal_id`
  و`reference_type/reference_id`.

---

## 3. دورات الحياة الحقيقية

### 3.1 العرض — سبع حالات

المصدر: `Offer.php:42-64` (الحالات) و`OfferController::updateStatus:778-787`
(الانتقالات) و`Offer::statusAfterQuantityChange:76-88` (ما تفعله الكميات).

```
DRAFT ──submit──> PENDING_REVIEW ──moderate(offers.moderate)──> ACTIVE
  │                     │                                          │  ▲
  │                     └──reject──> DRAFT (تحرير ثم إعادة تقديم)  │  │
  │                                                              ▼  │
  └──────────────> CANCELLED <────── PAUSED ◄───────────────────────┘
                                    (نفس المسار من ACTIVE)

ACTIVE ⇄ SOLD_OUT        : تُفرض بالكمية، لا بقرار من البائع
ACTIVE/PUBLISHED → EXPIRED : وضعٌ كسول — يُكتب عند محاولة حجز بعد `expires_at`
                             (`InventoryReservationService:32`)، ولا يوجد أمر
                             مجدوول يحوّل العروض المنتهية تلقائيًا.
```

- القبول الأول للعرض الجديد يعتمد إعداد `offer.requires_review`: بدونه يذهب
  العرض إلى `PENDING_REVIEW` فقط إذا طلب المسؤول المراجعة، وإلا يُنشر مباشرة
  (`OfferController:654`).
- من `SOLD_OUT` لا يُعاد التنشيط بزيادة الكمية عبر `updateStatus`؛ الجدول
  يسمح بـ `CANCELLED` فقط، والتزويد حركة مخزون جديدة.
- `EXPIRED` و`CANCELLED` حالتان نهائيّتان (قائمة انتقال فارغة).

### 3.2 طلب الشراء — خمس حالات

`REQUESTED → ACCEPTED | REJECTED | PROPOSED`، ومن `PROPOSED`
يقبل المشتري `acceptProposal` فيعود `REQUESTED` بشروط البائع، أو
`CANCELLED` من الحالتين. كل انتقال يشترط أن تكون الحالة الحالية `REQUESTED`
(أو `PROPOSED` للقبول)، والقبول هو وحده ما يستدعي الحجز.

### 3.3 الحجز — أربع حالات

```
ACTIVE ──> CONVERTED_TO_DEAL   (عند إنشاء الصفقة)
       ├──> CANCELLED          (بإلغاء المشتري، مع سبب)
       └──> EXPIRED            (بالمكنس: app:expire-reservations كل دقيقة)
```

كلا المسارين يمرّ بـ `finalize()` الواحد (`InventoryReservationService:148`)
تحت `lockForUpdate()`، وكلاهما يعيد الكمية إلى `available`.

### 3.4 الصفقة — اثنتا عشرة حالة

الحالات في `Deal.php`، والانتقالات في
`DealStateMachineService::ALLOWED_TRANSITIONS`، ومن يُنفّذها في
`TRANSITION_RULES`. القواعد المشتركة: صفقة واحدة لكل انتقال، ورفض الانتقال
إلى الحالة نفسها («already {status}; nothing changed»)، وسجل `deal_status_histories`
لكل انتقال، وحدث `deal.status_changed` إلى ويبهوك الطرفين، وإعلان عبر
`NotificationManager` للطرف الذي لم يحرّك الصفقة — من داخل نفس المعاملة.

```
DRAFT (لا مسار ينشئها؛ آلة الحالة تقبل الخروج منها)
  └─> PENDING_SELLER_APPROVAL ───> ACCEPTED ───┬─> PROCESSING ───┬─> READY_FOR_PICKUP ─┐
        (منشأ الصفقة: تحويل الحجز)             │                └─> DISPATCHED ──┐     │
                                               ├────────────> DISPATCHED          │     │
                                               ├─> READY_FOR_PICKUP ──────────────┤     │
                                               └─> CANCELLED / DISPUTED           ▼     ▼
                                                              DELIVERED ──┬─> INSPECTION ─┐
                                                                         └─> COMPLETED ◄──┘
   DELIVERED/INSPECTION ──> DISPUTED ──> RESOLVED ──┬─> COMPLETED
   (وDISPUTED متاحة من ACCEPTED..INSPECTION)        └─> CANCELLED
   COMPLETED وCANCELLED حالتا انتهاء بلا خروج.
   لا حالة دفع في الآلة إطلاقاً: لا PENDING_PAYMENT ولا ESCROW_FUNDED ولا
   SETTLED ولا REFUNDED — إزالة مقصودة، فالمنصّة لا تمسّ مالاً والتسوية
   خارجها (البرومبت §20).
```

- **بوابة التسليم (`deal_handovers`):** تُصدر آلة الحالة رمزاً عند `ACCEPTED`
  (`DealHandover::issueFor`)؛ ولا يُقبل الانتقال إلى `INSPECTION` أو `COMPLETED`
  قبل مسح الرمز لمطابقة الصفقة (`HANDOVER_NOT_VERIFIED`). حالات مسح الرمز
  الصالحة: `DELIVERED` و`INSPECTION` و`DISPUTED` و`RESOLVED`؛ والإلغاء يُبطل
  الرمز (`INVALIDATED`) دون أن يمحوه إن كان مُستعملاً.
- **الممثلون:** `buyer` و`seller` تُحسب من علاقة المنشأة الفاعلة بالصفقة،
  و`platform` هو `SuperAdmin`. حالات لا يملكها إلا الطرف الآخر: `ACCEPTED`
  للبائع، `INSPECTION` و`COMPLETED` للمشتري، `RESOLVED` للمنصة. هذا داخل
  الخدمة لا في Middleware لأن مسارًا واحدًا يخدم الطرفين.
- **الآثار:** `ACCEPTED` يوقّع البائع (`seller_user_id`, `accepted_at`) ويصدر
  رمز التسليم؛ `DELIVERED`/`INSPECTION` تحسبان `inspection_deadline_at`؛
  `COMPLETED` تنقل الكمية من `reserved` إلى `sold` وتقيّد عمولة المنصة في
  الدفتر إن فُعّل علمه؛ `CANCELLED` تعيد الكمية إلى `available` وتُبطل رمز
  التسليم.
- **نقود الصفقة رقمٌ لا حركة:** `unit_price` و`buyer_total_amount` لقطة ما
  اتفق عليه الطرفان؛ لا مسار في API يحرّك مبلغاً ولا يرفع إيصال دفع.

### 3.5 النزاع — خمس حالات

`OPEN → UNDER_REVIEW → EVIDENCE_REQUIRED →` ثم قرار واحد من اثنين (`Dispute::RESOLVED_STATUSES`):

- `RESOLVED_RELEASE`: الصفقة قائمة؛ تخرج من `DISPUTED` إلى `RESOLVED` وتُغلق
  لاحقاً بـ `COMPLETED` حين يُمسح رمز التسليم.
- `RESOLVED_CANCELLATION`: الصفقة مُلغاة؛ انتقالان بالآلة — `DISPUTED →
  RESOLVED → CANCELLED` — فتُرجع الكمية إلى `available` ويبطل رمز التسليم،
  بدل كتابة هذه الآثار يدوياً في المتحكم.

قرار النزاع يُرسَل بالاسم الذي يخزّنه السجل (`status ∈ RESOLVED_*`) لا بقاموس
`decision` ثانٍ، ولا مبلغ استرداد: منصّة لم تحتفظ مالاً لا تملك أن تردّه.
`claimed_amount` رقم يصف مطالبة رافع النزاع، لا أمر دفع. الأدلة `evidence_urls`
JSON في نفس الصف — لا جدول أدلة ولا رفع ملفات في هذا المسار.

### 3.6 الطلب وعروض الأسعار والمطابقة

- `Demand`: `OPEN → QUOTED → ACCEPTED → CLOSED`، و`EXPIRED` من `expires_at`.
- `Quotation`: `SUBMITTED → ACCEPTED | REJECTED | EXPIRED`، و`accepted_at`
  و`rejected_at` و`converted_deal_id`؟.
- `DemandMatch`: `ACTIVE → USED` (عند تحويله إلى طلب شراء) أو `EXPIRED`
  (انتهت صلاحيته) أو `INVALIDATED` (اختفى العرض الصالح تحته).

### 3.7 حالات لم تُستَهلك بعد

- `Deal::STATUS_DRAFT` معرفة ومسموح الخروج منها، لكن لا مسار ينشئها.
- `ApiKey` (`ACTIVE/REVOKED/EXPIRED`) تُنشأ وتُلغى لكن لا حارس يقبلها.

---

## 4. الأدوار والصلاحيات (بابان لا يبلغان صفوف الآخر)

المصدر: `backend/config/rbac.php` — **44 صلاحية** و**قاموسان** يُطبّقهما
`RolesAndPermissionsSeeder` الذي **يحذف** ما يُحذف من الإعداد حتى لا تبقى في
القاعدة صلاحيات يظنّها المسؤول مضبوطة.

الأولوية في هذا الفصل ليست التسمية بل العزل: صلاحيةٌ واحدة لا تُطلب بطريقتين ولا
تُمنح من باب واحد للجهتين.

**نطاق الاسم هو القرار، لا مصدر الصفّ.** `App\Support\RbacScope` يقسم الفهرس إلى
ثلاثة أقسام لا تتداخل وتفي به: `platform` (10) و`business` (24) و`both` (10).
التقسيم مكتوب لا مُستنتَج: `RolesAndPermissionsSeeder::assertScopesCover()` يفشل
الزرع إذا غاب اسم عن الثلاثة أو ظهر في اثنين، لأن قائمةً لم يكتبها أحد لا تُراجع.
أما عمود `permissions.scope` فمرآة تُستعمل للاستعلام والتقرير فقط — قرار اللحظة
يقرأ الإعداد.

**البابان:**

| الباب | المسارات | الحارس | ما يُحرَّر |
| :--- | :--- | :--- | :--- |
| حساب المنصة | `/api/v1/rbac/platform*` | `roles.manage` | أدوار المنصة ومنح الحساب المباشرة |
| داخل الشركة | `/api/v1/organizations/{id}/roles` و`/{id}/members/{userId}/permissions` و`/{id}/members/permissions/bulk` | `members.manage` | وقوف الشركة وتجاوزات أعضائها |

`roles.manage` لا تُقرأ في أي مسار منشأة، و`members.manage` وقوفٌ تجاري لا يبلغ
أدوار المنصة؛ فلا صلاحية واحدة تفتح البابين، ولا باب يكتب سطرًا في صفوف الآخر.
الرفض اسمه: `ROLE_PERMISSION_REFUSED` حيث تُرفأ صلاحية نطاقها
`platform` إلى دور شركة، و`ACCOUNT_PERMISSION_REFUSED` حيث تُمنح صلاحية
`business` خالصة للحساب عبر `RbacScope::unacceptableOnAccount()`، و
`MEMBER_PERMISSION_REFUSED` في تجاوز العضو.

**وقفة الفرد بلا شركة.** الاستثناء الوحيد على قاعدة «الأعمال تُقرأ من سطر العضوية»
هو ما declaresُه `rbac.person_capabilities`: قائمة مغلقة (`offers.like`
و`comments.create` و`notifications.view`) يمارسها الحساب باسمه حين لا تكون منشأة في
السياق (`TenantContext::id() === null`) وكان الحساب نشطاً. لا تُخزَّن هذه الصلاحية في
أي صف منح؛ يقررها الباب نفسه في `User::hasPermissionTo()` — فلا وجود لسطر يتسرب مع
صاحبه خارج الشركة. التداول خارج القائمة كلها: طلب الشراء والحجز والأمر بابٌ باسم
منشأة موثَّقة، لأن الطرف المقابل يستحق أن يعرف من يوقّع. و`RbacMatrixTest`
يحرّس القائمة: كل اسم فيها مذكور في الفهرس ولا اسم بنطاق `platform` يدخلها،
وإلا صارت «وقفة الفرد» باباً للخطة نفسها. الأسطر الناتجة (اهتمام/سؤال/بلاغ) تسمّي
كاتبها دائماً، ويسجل `organization_id` منشأة السياق إن وُجدت ويصبح `NULL`
للمستهلك — والإعلان العام يسقط الحقل الفارغ لا يخترع بديلاً له.

**موضعُ تحريرٍ واحد لكل باب في كل عميل.** البابان قرارُهما في الخادم، وليسَا
شاشتين تكتبان الصف نفسه: منحُ الحساب المباشرة يُحرَّر من صفّ الحساب حيث يُقرأ
اسمه — «حسابات المنصة» في الويب (`UsersPanel` + `AccountPermissionsEditor`،
فرديًا ومن شريط التحديد جماعيًا) و`lib/features/admin/account_permissions_sheet.dart`
في الجوال — أما مصفوفة أدوار المنصة فتبقى وحدها في
`PlatformPermissionsPanel` لأن تلك تحرّر وقوفًا لا أشخاصًا. وباب الشركة يُحرَّر من
قائمة الأعضاء: `MembersView` و`OrganizationRolesPanel` في الويب،
و`lib/features/org/members_screen.dart` + `member_overrides_sheet.dart` في الجوال.
النسخة الثانية من الكتابة نفسها هي الطريق الذي يجعل أحد الموضعين يفرض قاعدةً لا
تعرفها الأخرى، ولذلك حُذف الموضع المكرر لا الشاشة الجديدة.

**أدوار المنصة** (`platform_roles`) هي ما يمسك الحساب أينما كان:

| الدور | ما يُمنح | الطبيعة |
| :--- | :--- | :--- |
| `SuperAdmin` | `*` — كل الـ 45 | مالك المنصة |
| `Auditor` | 9 | قراءة مالية عبر المنصة كلها (`ledger.view`) |

**وأما ما يكونه الشخص داخل شركة** فسطران لا سطر: `organization_memberships.role`
يذكر مفتاح الوقوف، وقيمة ذلك المفتاح صفوف في `organization_roles`
المُسندة بشركة، وما يملكه الوقوف صفوف في `organization_role_permissions`، وما
يُزاد على وقفه أو يُسحب منه صفوف في `organization_membership_permissions`
بـ `effect` يقول `grant` أو `deny`.

الشركة تُنشئ وقوفها: `owner` و`admin` و`staff` و`finance` و`auditor` تُزرع لكل
شركة كـ `is_system` بقيمها من `rbac.membership_permissions` (35 و34 و22 و13 و9
من 45)، وبجانبها يسمّي صاحبُ الشركة أمينَ مستودع لا يُسعّر، أو مكتبَ شراء يجهّز
الطلبات ولا يعتمد شيئًا. المفتاح فريد داخل الشركة لا خارجها، ويقارَن حسّاسًا
للحالة (`utf8mb4_bin`) — لأن صفّين يقرآن `Admin` و`admin` وقوفان عند القاعدة
ووقوفٌ واحد عند كل شاشة تعرضهما.

القيمة الفعلية لعضو = صفوف وقوفه (أو إعداد الوقوف المعروف إن لم تُحرَّر صفوف
بعد) + منحُه − سحبُه، ثم تُصفّى النتيجة بـ
`RbacScope::exercisableInsideOrganization()` والفهرس. السحب موجود لأن شركةً لا
تستطيع إلا أن تزيد لا تجيب الطلب العادي «البقوا على وقوفهم، لكن هذا واحدٌ لا يحذف
إعلانات»؛ والباب الذي لا يجيبه يُنزل الشخص عن وقوفه، وهو يغيّر أكثر مما طُلب. المنح
والسحب لنفس الاسم في نفس الطلب `MEMBER_PERMISSION_CONTRADICTION`، ووقف `owner`
المحميّ بالاسم الأساسي (`members.manage`, `organizations.manage`)
`OWNER_STANDING_PROTECTED` — لأن مالكًا يفقد قدرته على التوظيف لا يستردّها من
شاشة.

الفارق بين هذا وما قبله ليس تسمية: المنح على الحساب كان يعني أن موظفًا في مصنع
صار مدير منشأة في كل شركة دخلتها قدمه، وأن مغادرته الشركة لا تسحب شيئًا. الآن
يغادر سطر العضوية فتنتهي سلطته التجارية كلها بلا أحد يتذكر أن يلغي مربع اختيار،
ويبقى له ما مُنح على حسابه لأنه ملكٌ للمنصة لا لتلك الشركة.

**صلاحيات لا تملكها أي شركة، ولا يمكن إضافتها هنا:** `offers.moderate`
(اعتماد إعلان غيرك)، `community.moderate`، `organizations.verify` (قرار أن منشأةً
يحق لها التداول)، `disputes.resolve` (الفصل في خصومة بين طرفين)، `users.manage`،
`roles.manage`، `settings.manage`، `currencies.manage` — لأن من له مصلحة في
النتيجة لا يفصل فيها. `ledger.view` هو الاستثناء المُسمّى: مسارات الدفتر تقرأ
`TenantContext`، فطلب شركةٍ دفاترها فعلٌ تجاري، أما قراءة دفاتر كل الشركات فتأتي
من أدوار المنصة التي لا سياق منشأة لها.

**التنفيذ في الخادم لا في الواجهة:** كل صلاحية مذكورة تُستخدم فعلًا في
`routes/api.php` كـ `permission:` أو داخل خدمة/متحكم كـ `can()`،
و`RbacMatrixTest` يفرض الاتجاهين: لا صلاحية بلا نقطة تنفيذ، ولا نقطة تنفيذ
بصلاحية غير معلنة. (خمس صلاحيات ديكورية كانت معلنة بلا تنفيذ وحُذفت:
`demands.match`, `disputes.manage`, `notifications.manage`, `ledger.export`,
`ledger.manage`.)

**نقطة تنفيذ واحدة، لا نقطتان:** `App\Models\User::hasPermissionTo()` هي المفصل
الذي يمرّ منه `Gate::before` والـ middleware والـ `can()` و`/auth/me` معًا، فلا
تتفق الواجهة والخادم على معنى صلاحية واحدة ويختلفان على الثانية. تُعيد الطريقة
قائمة الصلاحيات التي يراها الحساب نفسه (`getAllPermissions()`)، ولذلك أزرار
اللوحة تُبنى من جواب الخادم لا من تخمين العميل.

**الحدود الثلاثة مُختبَرة:** `tests/Feature/MembershipPermissionsTest.php` — ما
يجوز لوقوف الشركة وما لا يجوز؛ `tests/Feature/PermissionIsolationTest.php` — أن
البابين لا يبلغان صفوف بعضهما، وأن المنح والسحب والتجاوز الجماعي تصل إلى القرار
الفعلي لا إلى الصف فقط؛ و`RbacMatrixTest` — أن الفهرس والنقاط متساويان.

**انحراف معروف في القاعدة الحيّة:** جدول `roles` قد يحمل سطرًا لا تعرفه
الإعدادات (`Intermediary` من نسخة سابقة). `config/rbac.php` لا يذكره، والـ seeder
يتركه لأنه مسند إلى مستخدم، ويكتفي بالإبلاغ؛ و
`2026_09_21_090000_detach_the_retired_business_roles.php` يسمّي كل دور خارج
الإعداد في السجل دون أن يمسّه، لأن سحب سلطة حقيقية قرار مسؤول لا قرار ترقية.
الأدوار الأربعة المتقاعدة (`SellerAdmin`, `SellerStaff`, `BuyerAdmin`,
`BuyerStaff`) تُفصل عن الحسابات في تلك الهجرة نفسها: لم تعد تمنح شيئًا، وكانت
تظهر في اللوحة وقفةً على المنصة.

---

## 5. الآثار الجانبية: استدعاء مباشر، لا رسائل

لا توجد طبقة رسائل. ما يسميه بعض التصاميم «أحداث نطاق» هو في هذا الكود استدعاء
صريح داخل نفس الطلب:

| بدلا من حدث | يحدث فعليًا |
| :--- | :--- |
| `OfferActivatedEvent` | `OfferController` يكتب الحالة ثم `WebhookDispatcherService::dispatch('offer.created', …)` |
| `PurchaseRequestedEvent` | `InventoryReservationService::reserve()` يحجز تحت قفل على الصف (`lockForUpdate`)، و`NotificationManager` يكتب إشعارًا |
| `DealAcceptedEvent` / `GoodsDispatchedEvent` / … | `DealStateMachineService::transition()` يطبّق القواعد ويكتب في `deal_status_histories` ويطلق `deal.status_changed` للطرفين، ويعلن للطرف الآخر عبر `NotificationManager` من داخل نفس المعاملة |
| `DealCompletedEvent` | لا حدث: عند `COMPLETED` تنتقل الكمية من `reserved` إلى `sold`، ويُقيَّد ربح العمولة في الدفتر إن فُعّل علمه — قرار مباشر في `transition()` |
| `DisputeOpenedEvent` | `DisputeController::store` ينشئ السجل ويحوّل الصفقة إلى `DISPUTED` |

نتيجة مباشرة يجب أن تُعرف: **كل ما يُقرأ من قاعدة أو يستدعى شريكًا يحدث أثناء
طلب المستخدم** — لا طابور ولا عامل خلفي؛ الوحيدان الخلفيان هما أمران مجدولان كل
دقيقة (`app:expire-reservations`, `app:process-webhooks`).

---

## 6. ما لا يوجد في هذا النموذج (ولا يُدّعى)

- لا `Shipment` ولا `Courier` ولا `Geofence` ولا `Capacity` ولا `PriceSchedule`
  ولا `SettlementBatch` ولا جدول أدلة للخصومة.
- لا `app/Policies` ولا `addGlobalScope`: الفصل بين المنشآت كتابةٌ في كل استعلام
  (انظر `backend/SECURITY.md` §1).
- لا تنفيذ لعقد مزوّد دفع حقيقي، ولا تسعير سوقي، ولا ربط بجهة حكومية للتحقق من
  السجل التجاري أو الرقم الضريبي.
- لا ضريبة ولا عمولة مفروضة: الافتراضي `0.0000`، والنسبة قرار مسؤول في
  `system_settings` تُؤخذ لقطة لها على الصفقة.
- لا عميل تنقّل بعد: الواجهة الموجودة لوحة React، و`/api/v1/bootstrap` مصمَّم
  لعميل مقبل، ومجلد `mobile/` غير موجود.
