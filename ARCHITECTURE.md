# منصة حصّال (HASSAL) — المعمارية البرمجية (ARCHITECTURE.md)

## 1. النمط المعماري العام (Architectural Paradigm)

تعتمد منصة **حصّال** على معمارية **API-First Clean Modular Architecture** مبنية فوق إطار عمل **Laravel** الحديث (PHP 8.3+)، ومصممة لتكون المحرك الخلفي المركزي (Core Headless Engine) القادر على خدمة:
- تطبيقات الهواتف الذكية (Flutter iOS & Android).
- لوحات التحكم والإدارة عبر الويب (Web Management Portals).
- أنظمة تخطيط الموارد الخارجية للمؤسسات الكبرى (ERP, WMS, POS, Accounting).

```
+-------------------------------------------------------------------------+
|                              Clients Tier                               |
|   [Flutter Mobile App]    [Flutter/React Web]     [ERP / WMS Integrator]|
+-------------------------------------------------------------------------+
                                     |
                          HTTPS / JSON API v1
                        (Bearer Token / Sanctum)
                     (X-Idempotency-Key / HMAC-SHA256)
                                     v
+-------------------------------------------------------------------------+
|                            Ingress & Routing                            |
|             Rate Limiting | Localization | Security Headers             |
+-------------------------------------------------------------------------+
                                     |
                                     v
+-------------------------------------------------------------------------+
|                       Middleware Pipeline                               |
| - Authenticate (Sanctum)                                                |
| - ResolveOrganizationContext (Multi-Tenant Header / Active Org)         |
| - VerifyIdempotencyKey (Deduplication Layer)                            |
| - EnforceFeatureFlag                                                    |
| - AuditContextLogger                                                    |
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
| - InventoryReservationService     - DealLifecycleService                |
| - PaymentManager & Providers      - GeoDiscoveryService                 |
| - DisputeArbitrationService       - LedgerAccountingService             |
| - PricingEngine (Rule-based / AI) - WebhookDispatchService              |
+-------------------------------------------------------------------------+
             |                                              |
             v                                              v
+-----------------------------+               +---------------------------+
|    Data Access & Storage    |               |  Event & Queue Pipeline   |
| - MySQL 8+ / PostgreSQL     |               | - Redis / DB Queue Driver |
| - Strict Foreign Keys       |               | - Async Event Listeners   |
| - Decimal Currency Math     |               | - Scheduled Cron Jobs     |
| - Full DB Transactions      |               | - Webhook Retries         |
| - Pessimistic Locking       |               | - Expiry Sweepers         |
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
تحتوي على المنطق التجاري الحقيقي للمنصة:
- **`InventoryService`:** المعنية بالعمليات الذرية لحجز المخزون، وتحرير الحجوزات المنتهية، وتحويل الحجز إلى بيع مكتمل عبر استعلامات مؤمنة بـ `DB::transaction` و `lockForUpdate()`.
- **`DealLifecycleService`:** المعنية بتطبيق آلة الحالة (State Machine) لصفقات التداول، ومطابقة الشروط قبل كل انتقال، وتسجيل الأحداث المرتبطة.
- **`PaymentManager` & `PaymentProviderInterface`:** المعنية بتجريد التعامل مع بوابات الدفع المختلفة، وإتاحة إضافة مزودين جدد بسهولة.
- **`LedgerService`:** المعنية بتدوين حركات القيود المحاسبية الثنائية (Double-Entry principles) وتأكيد عدم تلاعب الأرصدة.
- **`GeoDiscoveryService`:** المعنية بحساب معادلة المسافات المكانية (Haversine Formula) وتطبيق مؤشرات البحث الجغرافي السريع.
- **`FeatureFlagService` & `SettingService`:** إدارة الإعدادات المركزية الديناميكية المخبأة في الذاكرة السريعة (Cache-backed settings).

### 2.3 طبقة العزل وتعدد المؤسسات (Multi-Tenancy Layer)
- **مفهوم العزل التنظيمي:** لا يملك المستخدم بيانات تداول مباشرة؛ بل تملكها **المؤسسة (`Organization`)**.
- يرتبط المستخدم بالمؤسسة عبر `OrganizationMembership` التي تحدد صفته داخلها (Owner, Manager, Buyer, Warehouse Staff).
- يتم تفعيل سياق المؤسسة عبر رأس الطلب `X-Organization-Id`، ويتحقق وسيط النقل (`ResolveOrganizationContext Middleware`) من أن المستخدم الحالي عضو فعّال يملك صلاحيات ضمن تلك المؤسسة.
- يتم تطبيق **Scoped Eloquent Global Scopes** و **Laravel Policies** على كافة الاستعلامات لمنع ثغرات الوصول المباشر للكائنات (IDOR).

---

## 3. معمارية الحجز الذري للمخزون ومنع البيع المزدوج

البيع المزدوج (Double-Selling) هو الخطر الأكبر في منصات تداول الجملة. تم تصميم المعمارية لتفاديه هندسياً كالتالي:

1. **تقسيم كميات المخزون:**
   - `available_quantity`: الكمية المتاحة للشراء الفوري.
   - `reserved_quantity`: الكمية المحجوزة بموجب طلبات شراء معلقة وقيد السداد.
   - `sold_quantity`: الكمية التي تم سداد قيمتها وتأكيد تسليمها.
2. **بروتوكول الحجز المتزامن:**
   ```php
   return DB::transaction(function () use ($offerId, $requestedQty, $buyerOrgId) {
       // قفل تشاؤمي على سجل العرض لمنع أي قراءة أو تعديل متزامن
       $offer = Offer::where('id', $offerId)->lockForUpdate()->firstOrFail();

       // التحقق من أهلية العرض وحالته
       if ($offer->status !== OfferStatus::ACTIVE) {
           throw new OfferNotActiveException();
       }

       // التحقق الصارم من كفاية الرصيد المتاح
       if ($offer->available_quantity < $requestedQty) {
           throw new InsufficientQuantityException();
       }

       // تعديل الكميات وتدوين الحجز ذرياً
       $offer->available_quantity -= $requestedQty;
       $offer->reserved_quantity  += $requestedQty;
       $offer->save();

       // إنشاء طلب الشراء وتحديد وقت انتهاء الحجز
       $reservation = PurchaseRequest::create([
           'offer_id' => $offer->id,
           'buyer_organization_id' => $buyerOrgId,
           'quantity' => $requestedQty,
           'status' => PurchaseRequestStatus::PENDING,
           'reserved_until' => now()->addMinutes(Setting::get('marketplace.reservation_timeout', 30)),
       ]);

       return $reservation;
   });
   ```
3. **مهمة التحرير التلقائي (Scheduled Reservation Sweeper):**
   تعمل مهمة دورية (`ReleaseExpiredReservationsJob`) عبر Laravel Scheduler كل دقيقة للبحث عن الحجوزات المنتهية وإعادة الكميات إلى `available_quantity` ضمن عملية ذرية آمنة.

---

## 4. معمارية مفتاح عدم التكرار (Idempotency Architecture)

لضمان عدم تكرار الخصم المالي أو تكرار إنشاء الصفقات عند ضعف اتصال شبكة العميل (Mobile Network Jitter / Retry Storms):
1. يرسل العميل رأس الطلب `X-Idempotency-Key` (UUIDv4) مع العمليات الحساسة (`POST /purchase-requests`, `POST /deals/{id}/payments`, `POST /deals/{id}/refund`).
2. يقوم الوسيط `EnsureIdempotency` بالبحث عن المفتاح في جدول `idempotency_keys`:
   - إذا كان المفتاح غير موجود: يُنشئ سجلاً بحالة `STARTED` مصحوباً بـ Request Hash.
   - إذا كان السجل قيد المعالجة (`STARTED`): يعيد استجابة `409 Conflict` (Operation in progress).
   - إذا كان السجل مكتملاً (`COMPLETED`): يعيد الاستجابة المخزنة سابقاً مباشرة بنفس الرمز والمحتوى دون إعادة تنفيذ العملية.

---

## 5. تجريد بوابات الدفع (Payment Provider Abstraction)

```php
interface PaymentProviderInterface
{
    public function initiatePayment(Deal $deal, array $paymentDetails): PaymentResult;
    public function verifyPayment(string $externalReference): PaymentVerificationResult;
    public function processRefund(Payment $payment, Decimal $amount, string $reason): RefundResult;
    public function getProviderName(): string;
}
```
يتم تسجيل المزودين (`CashOnDeliveryProvider`, `BankTransferProvider`, `OnlineMadaProvider`, `CreditCardProvider`) داخل `PaymentManager` مع دعم التبديل الديناميكي وحفظ المراجع الخارجية وسجلات التدقيق.

---

## 6. معمارية الويب هوكس الخارجية (External Webhook System)
- إرسال إشعارات الأحداث التجارية للأنظمة الشريكة والـ ERPs.
- توقيع حمولة الإشعار بخوارزمية `HMAC-SHA256` باستخدام سر خاص بكل نظام (`webhook_secret`).
- تنفيذ الإرسال عبر طوابير المعالجة (`Queued Webhook Jobs`) مع تكرار محاولات الإرسال (Exponential Backoff: 1m, 5m, 15m, 1h, 6h).
- تسجيل تفاصيل كل استدعاء، كود الاستجابة، وسجل المحاولات في جدول `webhook_deliveries`.

---

## 7. المراقبة وصحة النظام (Observability & Health Checks)
- توفير نقطة فحص مركزية `/api/v1/health` تفحص:
  - اتصال قاعدة البيانات وزمن الاستجابة.
  - جاهزية مخزن الكاش (Redis/Memory).
  - حالة طوابير الانتظار (Queue Backlog).
  - مساحة التخزين المتاحة للمستندات والملفات.
- توثيق دقيق للأخطاء في Laravel Logger مع إخفاء المفاتيح والبيانات الشخصية وكلمات المرور.
