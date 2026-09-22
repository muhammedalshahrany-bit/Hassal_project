# منصة حصّال (HASSAL) — المعمارية البرمجية (ARCHITECTURE.md)

## 1. النمط المعماري العام (Architectural Paradigm)

تعتمد منصة **حصّال** على معمارية **API-First Clean Modular Architecture** مبنية فوق إطار عمل **Laravel** الحديث (PHP 8.3+)، لتكون محركًا خلفيًا بلا واجهة مدمجة (Headless). وما هو مبنيّ فعليًا اليوم:
- لوحة ويب بلغة React/TypeScript في `src/` تخدمها الواجهة نفسها (المسار `/api/v1`).
- **عميل Flutter غير موجود في هذا المستودع** — التصميم يسمح به، ولم يُبنَ بعد.
- التكامل الخارجي: إرسال Webhooks موقّعة ومفاتيح API للمؤسسات فقط؛ لا موصل ERP أو WMS أو POS مكتوبًا.

```
+-------------------------------------------------------------------------+
|                              Clients Tier                               |
|  [React Web Dashboard (src/)]   [REST /api/v1 clients]  [ERP via Webhook]|
|   لا يوجد عميل Flutter في هذا المستودع حتى الآن.                         |
+-------------------------------------------------------------------------+
                                     |
                          HTTPS / JSON API v1
                        (Bearer Token / Sanctum)
                     (X-Idempotency-Key / HMAC-SHA256)
                                     v
+-------------------------------------------------------------------------+
|                            Ingress & Routing                            |
|      Throttle (على مسارات المصادقة فقط) | LocalizationMiddleware         |
+-------------------------------------------------------------------------+
                                     |
                                     v
+-------------------------------------------------------------------------+
|                       Middleware Pipeline                               |
| - Authenticate (Sanctum)                                                |
| - ResolveOrganizationContext        (alias: tenant)                     |
| - IdempotencyMiddleware             (alias: idempotency)                |
| - EnsureFeatureEnabled              (alias: feature:{key})              |
| - AuditLogMiddleware                (alias: audit)                      |
| - Spatie permission:/role:          (على مستوى كل مسار)                 |
+-------------------------------------------------------------------------+
                                     |
                                     v
+-------------------------------------------------------------------------+
|                  Thin Controllers (HTTP Transport Layer)                |
| - Validate Request (FormRequests)                                       |
| - Authorize Action (Laravel Policies / Spatie Permissions)              |
| - Delegate to Application / Domain Service                              |
| - Transform Output (API Resources)                                      |
+-------------------------------------------------------------------------+
                                     |
                                     v
+-------------------------------------------------------------------------+
|                       Domain & Application Services                     |
| - InventoryReservationService     - DealStateMachineService             |
| - DoubleEntryLedgerService        - GeoDiscoveryService                 |
| - PricingAndCurrencyService       - DemandMatchingService               |
| - FeatureFlagService              - SystemSettingsService               |
| - WebhookDispatcherService        - ApiKeyAuthenticationService         |
| - (Payments adapters: present, not wired into any route)                |
+-------------------------------------------------------------------------+
             |                                              |
             v                                              v
+-----------------------------+               +---------------------------+
|    Data Access & Storage    |               |  Scheduled Background     |
| - MySQL 8+                  |               |  Commands (routes/        |
| - Strict Foreign Keys       |               |  console.php)             |
| - Decimal Currency Math     |               | - app:expire-reservations |
| - Full DB Transactions      |               |   (every minute)          |
| - Pessimistic Locking       |               | - app:process-webhooks    |
|     (lockForUpdate)         |               |   (every minute, max 5    |
|                             |               |    attempts per delivery) |
+-----------------------------+               +---------------------------+
```

---

## 2. طبقات المعمارية البرمجية (Layered Architecture)

### 2.1 طبقة بروتوكول HTTP والتحكم (HTTP Transport Layer)
- **Controllers:** وحدات خفيفة ومحددة الهدف (Single Responsibility). دورها يقتصر على:
  1. استلام الطلب واستدعاء التحقق التلقائي (`FormRequest`).
  2. فحص الصلاحية بواسطة `Policy` أو `Gate`.
  3. استدعاء الـ `Service` المختصة.
  4. تغليف النتيجة داخل `JsonResource` موحد النمط.
- **Form Requests:** مسؤولة عن التحقق الصارم من المدخلات، وتحديد الأنواع والحدود، والتحقق من قيود الوجود (`exists`) في سياق المؤسسة المصرح لها.
- **API Resources:** مسؤولة عن تشكيل مخرجات الـ JSON، وإخفاء الحقول الحساسة، وتحويل الأرقام المالية بدقة.

### 2.2 طبقة النطاق وتطبيق الأعمال (Domain & Service Layer)
تحتوي على المنطق التجاري الحقيقي للمنصة، وأصنافها الفعلية هي الموجودة في `app/Services`:

- **`InventoryReservationService`:** المعنية بالعمليات الذرية لحجز المخزون، وتحرير الحجوزات المنتهية، عبر استعلامات مؤمنة بـ `DB::transaction` و `lockForUpdate()`.
- **`DealStateMachineService`:** المعنية بتطبيق آلة الحالة (State Machine) لصفقات التداول، ومطابقة الشروط والصلاحيات قبل كل انتقال، وإنشاء سجل الـ `escrow` المحاسبي عند `ESCROW_FUNDED`.
- **`DoubleEntryLedgerService`:** المعنية بتدوين حركات القيود المحاسبية الثنائية والتحقق من توازن الأطراف قبل الحفظ.
- **`GeoDiscoveryService`:** المعنية بحساب معادلة المسافات المكانية (Haversine Formula) وتطبيق مؤشرات البحث الجغرافي.
- **`PricingAndCurrencyService`:** تحويل العملات عبر سعر الصرف المخزّن للعملة، وحساب أسعار الصفقة والعمولة. لا يوجد محرك تسعير تلقائي ولا توصيات أسعار.
- **`DemandMatchingService`:** مطابقة طلبات الشراء (Demands) بالعروض المنشورة جغرافيًا وفئويًا.
- **`FeatureFlagService` و `SystemSettingsService`:** الأعلام والإعدادات المركزية، وقراءة الإعدادات مخزّنة مؤقتًا (Cache-backed) عبر `Cache::remember` مع `CACHE_TTL` وإبطال الكاش عند الكتابة.
- **`WebhookDispatcherService`:** بناء الحدث وتوقيعه وإرساله وتسجيل المحاولة في `webhook_deliveries`.
- **`ApiKeyAuthenticationService`:** التحقق من مفاتيح API بالتجزئة المشفّرة (SHA-256).

> **طبقة الدفع:** `app/Contracts/PaymentProviderInterface` ومنفّذاه
> (`CashOnDeliveryPaymentProvider`, `BankTransferPaymentProvider`) و`PaymentGatewayManager`
> موجودة كالكود، لكن **لا يشير إليها أي Controller أو مسار أو أمر مجدول** خارج ذلك المجلد.
> لا شيء في النظام يمرّ مدفوعًا فعليًا عبرها، ولا يوجد مزوّد Mada أو بطاقة ائتمان.

### 2.3 طبقة العزل وتعدد المؤسسات (Multi-Tenancy Layer)
- **مفهوم العزل التنظيمي:** لا يملك المستخدم بيانات تداول مباشرة؛ بل تملكها **المؤسسة (`Organization`)**.
- يرتبط المستخدم بالمؤسسة عبر `OrganizationMembership` (بحقل `role` من نوع `varchar(50)` مقُيَّد بـ CHECK)،
  والقيم المقبولة هي `owner, admin, staff, finance, auditor` من `config('rbac.membership_roles')` —
  القاموس نفسه الذي تبني منه الواجهات خياراتها وتقبض عليه `AddMemberRequest`. وهذا السطر **هو مصدر
  الصلاحيات التجارية**: `rbac.membership_permissions` تحدد ما يجوز لكل وقوف داخل شركته، ويقرأه
  `User::hasPermissionTo()` على سياق المنشأة الحالية، فسلطة الشخص تنتهي بمغادرته الشركة دون أن يتذكر
  أحد إلغاء دور. أما أدوار Spatie فبقيت لما هو فوق الشركة: `SuperAdmin` و`Auditor`
  (`config('rbac.platform_roles')`)، و`SellerAdmin`/`SellerStaff`/`BuyerAdmin`/`BuyerStaff` متقاعدة
  بقوائم فارغة حتى تُقرأ الإسنادات القديمة دون استثناء، وتُفصل عن الحسابات في
  `2026_09_21_090000_detach_the_retired_business_roles`.
- يتم تفعيل سياق المؤسسة عبر رأس الطلب `X-Organization-Id`؛ وإذا لم يُرسل الرأس، يرجع الوسيط إلى أول عضوية فعّالة للمستخدم
  (`memberships()->where('is_active', true)->first()`). وفي الحالتين يتحقق `ResolveOrganizationContext` من أن المؤسسة موجودة
  وأنها غير معلّقة/غير مرفوضة، ويضع الكيانين في `TenantContext::set($organization, $membership)`.
- **آلية منع الوصول بين المؤسسات (IDOR) كما هي في الكود فعلًا:** وسيط `tenant` يرفض بـ `ORGANIZATION_SUSPENDED` أو
  `ORGANIZATION_ACCESS_DENIED`، ثم تتحقق كل نقطة نهاية من الصلاحية عبر `permission:` middleware، وتُقيَّد الاستعلامات يدويًا
  داخل الـ Controllers بشرط `where('organization_id', …)`. **لا يوجد `app/Policies` ولا `addGlobalScope` في `app/Models`**،
  أي أن العزل يعتمد على انضباط كل استعلام على حدة لا على نطاق عام شامل. الاختبارات الوظيفية (`TenantIsolationAndIdorTest`)
  تغطي مسارات مختارة من هذا العزل، لا كل مسار.

---

## 3. معمارية الحجز الذري للمخزون ومنع البيع المزدوج

البيع المزدوج (Double-Selling) هو الخطر الأكبر في منصات تداول الجملة. تم تصميم المعمارية لتفاديه هندسياً كالتالي:

1. **تقسيم كميات المخزون على جدول `offers`:**
   - `available_quantity`: الكمية المتاحة للحجز.
   - `reserved_quantity`: الكمية المحجوزة بموجب Hold لم يتحوّل إلى صفقة بعد.
   - `sold_quantity`: الكمية التي حُرِّزت عند انتقال الصفقة، ويكتبها `DealStateMachineService`
     (`$newSold = sold_quantity + qty`) عند تحرير الحجز المقابل.
   - `total_quantity` و `min_order_quantity` و `max_order_quantity`: الحدّان وكمية العرض الأصلية.

2. **الحجز لا يبدأ من المشتري:** المشتري يرسل `PurchaseRequest` بحالة `REQUESTED` دون تحريك أي كمية.
   الكميات تتحرك فقط عندما يقبل البائع الطلب عبر
   `POST /api/v1/purchase-requests/{id}/accept` → `PurchaseRequestController::accept()`
   (يتحقق أولًا أن المؤسسة الحالية هي البائع وإلا `SELLER_ACCEPT_DENIED`، وأن الحالة ما زالت `REQUESTED`
   وإلا `PURCHASE_REQUEST_NOT_ACCEPTABLE`) ثم يستدعي `InventoryReservationService::reserve()`.

3. **بروتوكول الحجز المتزامن** — كما هو في `app/Services/InventoryReservationService.php`:
   ```php
   return DB::transaction(function () use ($offer, $buyerUser, $buyerOrgId, $quantity, ...) {
       // قفل تشاؤمي على سجل العرض يمنع التخصيص المزدوج
       $lockedOffer = Offer::where('id', $offer->id)->lockForUpdate()->firstOrFail();

       if ($lockedOffer->status !== Offer::STATUS_ACTIVE) {
           throw new Exception("Cannot reserve from an offer that is not ACTIVE ...");
       }
       if ($lockedOffer->expires_at && Carbon::now()->greaterThan($lockedOffer->expires_at)) {
           throw new OfferExpiredException((int) $lockedOffer->id);
       }
       if ($quantity < (float) $lockedOffer->min_order_quantity) { throw new Exception("... below the minimum ..."); }
       if ($lockedOffer->max_order_quantity && $quantity > (float) $lockedOffer->max_order_quantity) { throw new Exception("... exceeds the maximum ..."); }
       if ($quantity > (float) $lockedOffer->available_quantity) {
           throw new Exception("Insufficient inventory available. Requested: {$quantity}, Available: ...");
       }

       $newAvailable = (float) $lockedOffer->available_quantity - $quantity;
       $newReserved  = (float) $lockedOffer->reserved_quantity + $quantity;
       $lockedOffer->update([
           'available_quantity' => $newAvailable,
           'reserved_quantity'  => $newReserved,
           'status' => Offer::statusAfterQuantityChange($lockedOffer->status, $newAvailable),
       ]);

       $timeoutMinutes = (int) $this->settingsService->get(
           'reservation.timeout',
           config('hassal.reservations.default_timeout_minutes')
       );

       $reservation = Reservation::create([
           'reservation_number' => 'RES-' . now()->format('ymd') . '-' . strtoupper(Str::random(8)),
           'offer_id' => $lockedOffer->id,
           'buyer_organization_id' => $buyerOrgId,
           'buyer_user_id' => $buyerUser->id,
           'quantity' => $quantity,
           'unit_price' => (float) $lockedOffer->unit_price,
           'currency_code' => $lockedOffer->currency_code,
           'total_amount' => round($quantity * (float) $lockedOffer->unit_price, 4),
           'status' => Reservation::STATUS_ACTIVE,
           'expires_at' => Carbon::now()->addMinutes($timeoutMinutes),
           // ... delivery_preference, notes
       ]);

       $this->webhookDispatcher->dispatch('inventory.reserved', [...], $lockedOffer->seller_organization_id);
       return $reservation;
   });
   ```
   استثناءات `OfferNotActiveException` و `InsufficientQuantityException` غير موجودة في المشروع؛ الاستثناء
   المخصص الوحيد في `app/Exceptions` هو `OfferExpiredException` (وله معالجة تعيد تحديث العرض إلى
   `EXPIRED` بعد التراجع عن المعاملة)، وبقية حالات الرفض رسائل `Exception` نصية يلتقطها الـ Controller.

4. **تحرير الحجز:** مكان واحد للإغلاق `finalize()` يقفل سجل الحجز ثم العرض بـ `lockForUpdate` ويعيد
   الكمية إلى `available_quantity`؛ ويستخدمه `release()` (إلغاء صريح بحالة `CANCELLED`) و `expire()`
   (انتهاء وقت بحالة `EXPIRED` دون كتابة `cancelled_at`)، ويعيد `false` إذا كان الحجز محلولًا مسبقًا
   حتى لا يُحرَّر الرصيد مرتين. كل إغلاق يرسل حدث `inventory.released` إلى البائع.

5. **مهمة التحرير التلقائي:** لا وجود لـ `ReleaseExpiredReservationsJob`. الأمر `app:expire-reservations`
   (`app/Console/Commands/ExpireReservationsCommand`) مجدول كل دقيقة في `routes/console.php` ويستدعي
   `sweepExpiredReservations()`، التي تفرج فقط عن الحجوزات التي تجاوزت `expires_at` بهامش أمان
   `config('hassal.reservations.expiry_grace_seconds')` — لأن الدقيقة التي تنتهي فيها الحجز قد تكون
   الدقيقة التي تؤكَّد فيها الصفقة.

---

## 4. معمارية مفتاح عدم التكرار (Idempotency Architecture)

لضمان عدم تكرار إنشاء الصفقات أو الطلبات عند ضعف اتصال شبكة العميل (Mobile Network Jitter / Retry Storms)،
يُنفَّذ مفتاح عدم التكرار في `App\Http\Middleware\IdempotencyMiddleware` (اسم الوسيط في `bootstrap/app.php`
هو `idempotency`) وبجدول `idempotency_keys`. السلوك الفعلي:

1. يرسل العميل رأس `X-Idempotency-Key` مع عمليات الكتابة. **الرأس اختياري:** غيابه يمرّر الطلب دون أي حفظ
   أو استرجاع، والوسيط يعمل على `POST/PUT/PATCH/DELETE` فقط.
2. يُبنى بصمة الطلب من `hash('sha256', fullUrl|method|body|user_id)`، ويُبحث عن المفتاح في
   `idempotency_keys` مقيّدًا بـ `user_id` و `organization_id` و `expires_at > now()`.
   - مفتاح غير موجود: يُنفَّذ الطلب، ثم يُخزَّن الرد (رمزًا وجسمًا) إذا كان رمزه أقل من 500، لمدة 24 ساعة.
   - مفتاح موجود ببصمة مختلفة: رفض بـ `IDEMPOTENCY_CONFLICT` ورمز `422`.
   - مفتاح موجود بنفس البصمة: يُعاد الرد المخزّن نفسه مع رأسي `X-Cache-Lookup: HIT` و `X-Idempotency-Replay: true`.
3. لا توجد حالتا `STARTED`/`COMPLETED` ولا استجابة `409 Conflict`: السجل يُكتب بعد اكتمال الرد، لذا
   **طلبان متزامنان بنفس المفتاح لا يُسلسلان** — كل واحد ينفَّذ، والأخير يكتب الرد المخزَّن. هذه فجوة معروفة
   في التصميم، والعزل الحقيقي لتكرار الكميات يعتمد على `lockForUpdate()` داخل خدمة الحجز لا على هذا الوسيط.
4. المسارات التي تركّب الوسيط فعليًا هي مسارات الكتابة في `routes/api.php` (إنشاء/تحرير/نشر العرض، إرسال
   طلب الشراء وقبوله ورفضه وإلغاؤه، إنشاء الصفقة وانتقالاتها، حركات المخزون، إدارة المؤسسات والمستخدمين
   والإعدادات والعملات ومفاتيح الـ API والـ Webhooks). **لا يوجد `POST /deals/{id}/payments` ولا
   `POST /deals/{id}/refund`** في النظام، ولا خصم مالي يمرّ من هنا.

---

## 5. طبقة بوابات الدفع (Payment Provider Layer)

الواجهة الفعلية في `app/Contracts/PaymentProviderInterface.php`:

```php
interface PaymentProviderInterface
{
    public function getIdentifier(): string;
    public function getName(): string;
    public function chargeEscrow(Deal $deal, float $amount, string $currency, array $metadata = []): Payment;
    public function refund(Payment $payment, float $amount, ?string $reason = null): bool;
    public function releaseToSeller(Payment $payment, float $amount): bool;
}
```

المنفّذات الموجودة: `CashOnDeliveryPaymentProvider` و `BankTransferPaymentProvider`، وحاوية
`App\Services\Payments\PaymentGatewayManager`. لا يوجد `OnlineMadaProvider` ولا `CreditCardProvider`
ولا صنف باسم `PaymentManager`.

> **حالة التوصيل (كما هي في الكود):** لا يشير إلى هذه الأصناف الثلاثة أيّ ملف خارج مجلد
> `app/Services/Payments` — لا Controller ولا مسار ولا أمر مجدول ولا انتقال في آلة الحالة. أي أن
> الطبقة **غير موصولة بالنظام**: لا يوجد أي مسار دفع في `routes/api.php`، ولا يكتب أي سطر في جدول
> `payments` في كل الشجرة (المرجع الوحيد خارج المجلد هو علاقة `Deal::payments()`). الجدول يبقى فارغًا.
> الصفقة تصل إلى `ESCROW_FUNDED` عبر `DealStateMachineService`
> الذي ينشئ سجل `escrow` وقيدًا محاسبيًا في حساب `2010-ESCROW-HOLDING` — **سجلٌ محاسبي لا حيازة مال**:
> لا طرف ثالث يقبض مبلغًا ولا يوجد مزوّد دفع حقيقي موصول. ربط هذه الطبقة ببوابة فعلية عملٌ مطلوب
> ولا يجوز ادعاؤه قبل تنفيذه والاختبار عليه.

---

## 6. معمارية الويب هوكس الخارجية (External Webhook System)
- إرسال إشعارات الأحداث التجارية للأنظمة الشريكة والـ ERPs عبر `WebhookDispatcherService`.
- توقيع الحمولة بـ `HMAC-SHA256` بسرّ خاص بكل نظام (`webhook_secret`)، على النص
  `"t={timestamp}.{jsonPayload}"`، ويرسل في رأس `X-Hassal-Signature: t=…,v1=…`، مع تحمّل زمني
  300 ثانية في `verify()`.
- **الإرسال متزامن داخل طلب HTTP** (`Http::` لا طابور): `dispatch()` يبني الحدث ويوجهه لكل Webhook نشط
  مطابق، ويغلف الحمولة كـ `{id: 'evt_…', event, timestamp, data}`. لا وجود لـ Queued Webhook Jobs
  ولا لسلّم backoff من 1m/5m/15m/1h/6h.
- إعادة المحاولة تأتي من الأمر المجدول `app:process-webhooks` (كل دقيقة في `routes/console.php`،
  `ProcessWebhooksCommand`) الذي يعيد إرسال حالات `FAILED` التي يقل عددها عن 5 محاولات لويب هوك نشط،
  ويزيد `attempt` — أي حدّ المحاولات خمس وليس تأخيرًا متصاعدًا.
- تسجيل تفاصيل كل استدعاء وكود الاستجابة وسجل المحاولات في جدول `webhook_deliveries`.
- **الأحداث الفعلية ثلاثة فقط:** `deal.status_changed` (للمشتري والبائع)، و`inventory.reserved`
  و`inventory.released` (للمؤسسة البائعة). لا يوجد حدث لإنشاء العرض أو الطلب.

---

## 7. المراقبة وصحة النظام (Observability & Health Checks)
- نقطة الفحص المركزية `GET /api/v1/health` معرَّفة كدالة مجهولة (Closure) داخل `routes/api.php`،
  وقياسها الوحيد هو اتصال قاعدة البيانات: تعيد `driver` و `version` من PDO عند النجاح أو `status: unreachable`
  مع رسالة الخطأ عند الفشل، ثم تحسب الحالة العامة `healthy`/`degraded` من نتيجة تلك المحاولة وحدها.
  وتشمل أيضًا بيانات وصفية من الإعدادات: اسم المنصة، `config('hassal.brand.app_version')`،
  `PHP_VERSION`، إصدار Laravel، البيئة، والطابع الزمني.
- **ما لا يفحصه الفحص اليوم:** لا يقيس زمن الاستجابة، ولا جاهزية الكاش (Redis/Memory)، ولا ازدحام
  الطابور، ولا مساحة التخزين. إضافة هذه القياسات عمل مطلوب قبل أي ادعاء بمراقبة كاملة.
- فحص البقاء الخاص بإطار العمل (`/up` في `bootstrap/app.php`) يظل منفصلًا عن هذه النقطة التجارية.
- توثيق الأخطاء عبر Laravel Logger مع إخفاء المفاتيح والبيانات الشخصية وكلمات المرور.
