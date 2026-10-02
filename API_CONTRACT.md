# منصة حصّال (HASSAL) — وثيقة مواصفات الـ API للمطورين وتطبيقات الهاتف (API_CONTRACT.md)

## 1. المعايير العامة للاتصال (General Standards)

- **الرابط الأساسي (Base URL):** `/api/v1` (محلياً: بوابة التطوير `http://localhost:3000/api/v1` التي تعيد التوجيه إلى خادم Laravel على المنفذ 8000).
- **المرجع التنفيذي:** المسارات الفعلية هي ما تسجّله `backend/routes/api.php` فقط. لذلك تُوضح كل نقطة نهاية في القسم 4 حالتها صراحةً: `منفَّذ` أو `غير منفَّذ`. توجد وثيقة مختصرة يدوية الصياغة تغطي جزءاً من هذه المسارات في `backend/docs/openapi.yaml`.
- **التوثيق (Authentication):** `Authorization: Bearer <Sanctum-Token>`
- **سياق المؤسسة (Organization Context):** `X-Organization-Id: <ID>` (مطلوب للعمليات التجارية).
- **منع التكرار (Idempotency):** `X-Idempotency-Key: <UUIDv4>` على مسارات التعديل (POST/PUT/PATCH/DELETE) التي تُشغّل طبقة منع التكرار. الانتباه: الطبقة تتجاوز الطلب بصمت عند غياب الرأس، فإرسال مفتاح فريد مسؤولية العميل ولا يفرضها الخادم بعد.
- **اللغة والتعريب (Localization):** `Accept-Language: ar` أو `en` (الافتراضي: `ar`).
- **صيغة البيانات:** `Content-Type: application/json` و `Accept: application/json`.

---

## 2. الهيكل الموحد للاستجابات (Standard Response Envelope)

تُنتِج هذه الهياكل ثلاث دوال ساكنة في `App\Http\Responses\ApiResponse`
(`success` و `paginated` و `error`)، ولا توجد أشكال أخرى للاستجابة.

### 2.1 الاستجابة الناجحة (Success Response)
```json
{
  "success": true,
  "message": "Operation completed successfully",
  "data": {},
  "meta": {
    "timestamp": "2026-09-15T15:00:00+03:00",
    "correlation_id": "0f4c1c2e-6d5a-4a2f-9b1c-1e2d3f4a5b6c"
  }
}
```
> `correlation_id` يعيد الخادم قيمة الرأس `X-Correlation-Id` إن أرسله العميل، وإلا يولّد
> `UUID`. نصوص `message` تُرجِعها طبقات الخادم بالإنجليزية حالياً، والواجهات هي المسؤولة
> عن توطينها.

### 2.2 الاستجابة المقسمة لصفحات (Paginated Response)
```json
{
  "success": true,
  "message": "Data retrieved successfully",
  "data": [],
  "pagination": {
    "current_page": 1,
    "per_page": 20,
    "total_items": 150,
    "total_pages": 8,
    "has_more": true
  },
  "meta": {
    "timestamp": "2026-09-15T15:00:00+03:00",
    "correlation_id": "0f4c1c2e-6d5a-4a2f-9b1c-1e2d3f4a5b6c"
  }
}
```
> لا يعيد الخادم كائن `links`؛ الترقيم يتم عبر `page` و `per_page` في المسار الاستعلامي.

### 2.3 استجابة الخطأ الموحدة (Error Response)
```json
{
  "success": false,
  "error_code": "QUANTITY_EXCEEEDS_AVAILABLE",
  "error": {
    "code": "QUANTITY_EXCEEEDS_AVAILABLE",
    "message": "Requested quantity exceeds available quantity",
    "details": []
  },
  "meta": {
    "timestamp": "2026-09-15T15:00:00+03:00",
    "correlation_id": "0f4c1c2e-6d5a-4a2f-9b1c-1e2d3f4a5b6c"
  }
}
```
> الخطأ يعرض الرمز مرتين (`error_code` للسهولة، و `error.code` للتوافق)، وأخطاء التحقق
> من المدخلات تظهر داخل `error.details`. لا يوجد حقل `errors` في أعلى الاستجابة.

---

## 3. معجم رموز الأخطاء البرمجية (Error Codes Dictionary)

الرموز أدناه هي الرموز التي يُصدِرها الكود فعلياً (من `bootstrap/app.php`، والطبقات
`ResolveOrganizationContext` و `IdempotencyMiddleware`، ومن طبقات `ApiResponse::error` في
المتحكمات). أي رمز آخر مذكور في وثائق قديمة غير منفَّذ.

| رمز الخطأ (Error Code) | كود HTTP | المصدر | المعنى البرمجي لتطبيقات الهاتف والواجهات |
| :--- | :---: | :--- | :--- |
| `VALIDATION_FAILED` | 422 | معالج الاستثناءات | فشل التحقق من المدخلات؛ تفاصيل الحقول في `error.details`. |
| `UNAUTHENTICATED` | 401 | معالج الاستثناءات | لا توجد جلسة/توكن صالح، أو انتهت صلاحيته. |
| `FORBIDDEN` | 403 | `AuthorizationException` / `AccessDeniedHttpException` | العملية مرفوضة عاماً (رسالة الاستثناء نصها). |
| `PERMISSION_DENIED` | 403 | `Spatie\Permission\Exceptions\UnauthorizedException` | صلاحية ناقصة؛ `error.details.required_permission` يسمّ capability المفقودة. |
| `ROLE_PERMISSION_REFUSED` | 422 | `OrganizationRoleController` | اسمٌ لا يُمنح داخل منشأة (صلاحية منصة أو اسم غير معلن)؛ الأسماء في `error.details.permissions`. |
| `ACCOUNT_PERMISSION_REFUSED` | 422 | `RoleGovernanceController` | اسمٌ لا يُسنَد للحساب من باب المنصة (صلاحية منشأة أو اسم غير معلن)؛ الأسماء في `error.details.permissions`. |
| `MEMBER_PERMISSION_REFUSED` | 422 | `OrganizationRoleController::writeOverrides` | محاولة منح أو سحب اسمٍ خارج متاحة المنشأة؛ الأسماء في `error.details.permissions`. |
| `MEMBER_PERMISSION_CONTRADICTION` | 422 | `OrganizationRoleController::validateOverrides` | الاسم في قائمة المنح وقائمة السحب معًا؛ القرار يُرفض ولا يُحسب بتسلسل أسبقية. |
| `OWNER_STANDING_PROTECTED` | 422 | باب المنشأة | محاولة تجريدة المالك (أو دوره) من `members.manage`/`organizations.manage`؛ المالك هو من يعيّن غيرهم. |
| `MEMBERSHIP_NOT_FOUND` | 422 | `OrganizationRoleController::bulkMemberPermissions` | حساب مذكور في طلب جماعي ليس ضمن هذه المنشأة؛ الرقماء في `error.details.user_ids`، ولا يُكتب أي صف. |
| `ROLE_KEY_INVALID` / `ROLE_KEY_TAKEN` / `ROLE_KEY_RESERVED` | 422 | `OrganizationRoleController::store` | مفتاح الدور المخصّص غير مقبول: صيغة خاطئة، أو مستعمل في هذه المنشأة، أو اسم وقوف تعتمده المنصة. |
| `ROLE_NOT_FOUND` / `ROLE_SYSTEM_PROTECTED` / `ROLE_IN_USE` / `ROLE_SOURCE_MISSING` | 422/404 | `OrganizationRoleController` | الدور غير موجود في هذه المنشأة / دور منصة لا يُحذف / دورٌ يحمله أحد / الدور المنقول عنه مفقود. |
| `ROLE_NOT_PLATFORM` / `ROLE_IMMUTABLE` | 422 | `RoleGovernanceController` | باب المنصة لا يحرّر أدوار الشركات، و`SuperAdmin` يُعرَّف في `config/rbac.php` ولا يُحرَّر من الواجهة. |
| `USER_NOT_FOUND` | 422 | `RoleGovernanceController::updateUserPermissions` | حسابات مذكورة لا توجد؛ الرقماء في `error.details.user_ids`، ولا يُكتب أي صف. |
| `INVALID_CREDENTIALS` | 401 | `AuthController::login` | بريد أو كلمة مرور غير صحيحة. |
| `ACCOUNT_INACTIVE` | 403 | `AuthController::login` و`AuthController::google` | حالة الحساب ليست نشطة (`Account is currently <status>`)؛ الرمز نفسه يعود من مسار جوجل حين يكون صاحب الهوية المرتبطة موقوفًا. |
| `GOOGLE_SIGNIN_DISABLED` | 403 | `AuthController::google` | الإعداد `auth.google_enabled` مطفأ؛ الباب مغلق من الطرفين (الواجهة لا ترسم الزر أصلاً). |
| `GOOGLE_NOT_CONFIGURED` | 403 | `AuthController::google` | الميزة مفعّلة لكن `auth.google_client_id` فاضي؛ الفرق عن السابق مقصود لأن المدير يُصلحه في مكان آخر. |
| `GOOGLE_TOKEN_REJECTED` | 401 | `AuthController::google` ← `GoogleIdTokenVerifier` | لم يُتحقّق من رقم التوكن. الرسالة للإنسان واحد، والقرار لآلة في `error.details.reason` ∈ `GOOGLE_TOKEN_MALFORMED`, `GOOGLE_TOKEN_ALG_UNSUPPORTED`, `GOOGLE_TOKEN_KEY_UNAVAILABLE`, `GOOGLE_TOKEN_SIGNATURE_INVALID`, `GOOGLE_TOKEN_ISSUER_REJECTED`, `GOOGLE_TOKEN_AUDIENCE_REJECTED`, `GOOGLE_TOKEN_EXPIRED`, `GOOGLE_TOKEN_NOT_YET_VALID`, `GOOGLE_EMAIL_UNVERIFIED`, `GOOGLE_TOKEN_SUBJECT_MISSING`, `GOOGLE_TOKEN_NONCE_MISMATCH`, `GOOGLE_SIGNIN_NOT_CONFIGURED`, `GOOGLE_PROVIDER_UNAVAILABLE`. مع `error.details.retryable`؛ `GOOGLE_PROVIDER_UNAVAILABLE` وحده يُرجع 503. لا يُسمّى البريد مرفوضًا أبدًا حتى لا يتحوّل المسار إلى عدّاد حسابات. |
| `NOT_FOUND` | 404 | معالج الاستثناءات | المورد أو المسار غير موجود. |
| `TENANT_REQUIRED` | 403 | متحكمات الصفقات/المفاتيح/الطلبات | لم يُحدَّد سياق منشأة نشطة لهذا الطلب. |
| `ORGANIZATION_ACCESS_DENIED` | 403 | `ResolveOrganizationContext` | لا توجد عضوية نشطة في المنشأة المطلوب سياقها. |
| `ORGANIZATION_NOT_MEMBER` | 403 | `AuthController::switchOrganization` | محاولة تبديل منشأة غير عضو فيها. |
| `ORGANIZATION_NOT_FOUND` | 404 | `ResolveOrganizationContext` | المنشأة المطلوبة غير موجودة. |
| `ORGANIZATION_SUSPENDED` | 403 | `ResolveOrganizationContext` | المنشأة موقوفة إدارياً. |
| `IDEMPOTENCY_CONFLICT` | 422 | `IdempotencyMiddleware` | أُعيد استخدام نفس `X-Idempotency-Key` بمتن طلب مختلف. |
| `QUANTITY_EXCEEEDS_AVAILABLE` | 422 | `PurchaseRequestController` | الكمية المطلوبة أكبر من المتاح (انتبه: الاسم في الكود مطابق حرفياً). |
| `QUANTITY_BELOW_MIN_ORDER` | 422 | `PurchaseRequestController` | الكمية أقل من حدّ الطلب الأدنى لدى البائع. |
| `PURCHASE_REQUEST_ACCEPT_FAILED` | 422 | `PurchaseRequestController::accept` | تعذّر الحجز عند القبول، وأشهره نفاد المتاح بعد إرسال الطلب؛ تفاصيل السبب في `error.details`. |
| `PURCHASE_REQUEST_NOT_ACCEPTABLE` / `_NOT_REJECTABLE` / `_NOT_PROPOSABLE` / `_NOT_PROPOSED` / `_NOT_CANCELLABLE` | 422 | `PurchaseRequestController` | الإجراء لا يليق بحالة الطلب الحالية. |
| `PURCHASE_REQUEST_ACCESS_DENIED` | 403 | `PurchaseRequestController` | الطلب ليس لمنشأة المستخدم. |
| `PROPOSAL_HAS_NO_CHANGE` | 422 | `PurchaseRequestController::propose` | العرض المضادّ لا يغيّر أي شرط. |
| `RESERVATION_NOT_ACTIVE` | 422 | `ReservationController::cancel` | الحجز لم يعد نشطاً فلا كمية يُطلقها؛ الحال الحالية في `error.details.status`. |
| `RESERVATION_EXPIRED` | 422 | `DealController::store` | انتهت صلاحية الحجز ويلزم تجديده. |
| `SELF_PURCHASE_FORBIDDEN` | 422 | `PurchaseRequestController` | محاولة شراء عرض منشأة المشتري نفسه. |
| `SELLER_NOT_TRADING` | 422 | `PurchaseRequestController` | منشأة البائع لا تتاجر حالياً، فلا يُقبل طلب على عروضها. |
| `OFFER_NOT_TRADABLE` / `OFFER_NOT_AVAILABLE` / `OFFER_UNAVAILABLE` / `OFFER_EXPIRED` | 422 | `PurchaseRequestController` | العرض لا يقبل طلب شراء في حالته أو بعد انتهائه. |
| `OFFER_HAS_NO_STOCK` | 422 | `OfferController` | لا متبقٍ في العرض. |
| `OFFER_NOT_EDITABLE` | 422 | `OfferController::update` | التحرير متاح للمسودة والموقوف فقط. |
| `OFFER_NOT_FEATUREABLE` | 422 | `OfferController::setFeatured` | التمييز أعلى السوق يُطلب على عرضٍ ليس `ACTIVE`؛ الحال الحالية في `error.details.status`. |
| `OFFER_QUANTITY_COMMITTED` | 422 | `OfferController::update` | الكمية الكلية غير قابلة للتغيير بعد حجز أو بيع جزء. |
| `OFFER_NOT_OWNED` / `OFFER_CLOSED` / `QUANTITY_REQUIRED` / `QUANTITY_MUST_BE_POSITIVE` / `QUANTITY_INFEASIBLE` | 422 | `InventoryMovementController` | حركة مخزون على عرض غير مملوك أو مغلق، أو كمية بعلامة/حجم مرفوضة. |
| `DEAL_CREATION_FAILED` | 422 | `DealController::store` | فشل إنشاء الصفقة من الحجز. |
| `STATE_TRANSITION_FAILED` | 422 | `DealController::transition` | الانتقال مرفوض من آلة الحالة (رسالة نصية توضح من/إلى). |
| `DEAL_ACCESS_DENIED` | 403 | `DealController` | الصفقة لا تخص منشأة المستخدم. |
| `UNAUTHORIZED_OFFER_ACTION` | 403 | `OfferController` | غير مصرح بتعديل هذا العرض. |
| `INVALID_OFFER_TRANSITION` | 422 | `OfferController::updateStatus` | انتقال حالة غير مسموح للعرض. |
| `WAREHOUSE_NOT_OWNED` | 403 | `OfferController` | المستودع المحدد لا ينتمي إلى منشأتك. |
| `CURRENCY_NOT_ACTIVE` | 422 | `OfferController` / `DemandController` | العملة المختارة غير موجودة أو غير مفعلة. |
| `DEMAND_CLOSED` | 422 | `DemandController` | طلب الاحتياج مغلق ولا يقبل عروضاً جديدة. |
| `DEMAND_ACCESS_DENIED` | 403 | `DemandController` | لا صلاحية للوصول إلى هذا الطلب أو مطابقاته. |
| `MATCH_ACCESS_DENIED` | 403 | `PurchaseRequestController` | المطابقة ليست للمنشأة الحالية. |
| `SELLER_ACCEPT_DENIED` / `SELLER_REJECT_DENIED` / `SELLER_PROPOSE_DENIED` | 403 | `PurchaseRequestController` | إجراءات البائع مرفوضة على طلب ليس له. |
| `SELF_QUOTATION_DENIED` | 403 | `DemandController` | محاولة تقديم عرض على طلب منشأتك. |
| `CANNOT_REMOVE_LAST_OWNER` | 422 | `OrganizationController` | لا يمكن إخراج آخر مالك للمنشأة. |
| `INVALID_REGISTRATION_DATA` | 422 | `AuthController::register` | بيانات التسجيل غير صالحة؛ ومن بينها إرسال `organization_name` أو `organization_type` أو `commercial_registration_number` أو `vat_number` مع الحساب — كل واحد منها مرفوق برسالة توجّه إلى `/organizations`. |
| `ACCOUNT_PROFILE_INCOMPLETE` | 403 | `OrganizationController::store` | الحساب لم يُمدّ بعد بما تطلبه هذه النشرَة (`account.require_*`). `error.details.missing_fields` يحمل المفاتيح (`phone` / `national_id`) وهي نفسها التي يعيدها `profile_completion.missing_fields`، والرسالة تسمّيها بالعربية. |
| `ORGANIZATION_CREATION_LIMIT_REACHED` | 403 | `OrganizationController::store` | بلغ الحساب سقف `account.max_organizations_per_account` (صفر = بلا سقف). `error.details` يحمل `limit` و`founded`؛ والعدّ للمملوكة (`owner`) لا للمشترك فيها كعامل. |
| `GOOGLE_ACCOUNT_ALREADY_LINKED` | 403 | `AuthController::google` | البريد موجود ومربوط بهوية جوجل أخرى؛ لا دمج لأن الدمج يسلّم منشآت شخصٍ لغيره. |
| `GOOGLE_ACCOUNT_NOT_PROVISIONED` | 403 | `AuthController::google` | لا حساب لهذا البريد و`auth.google_auto_register` مطفأ. |
| `PASSWORD_RESET_INVALID` / `PASSWORD_RESET_FAILED` | 422 | `AuthController` | رمز إعادة التعيين غير صالح أو فشل إرسال رابط الاستعادة. |
| `CATEGORY_PRODUCT_MISMATCH` | 422 | `OfferController` | تصنيف العرض لا يطابق تصنيف المنتج. |
| `USER_NOT_FOUND` | 404 | `OrganizationController` | لا يوجد مستخدم بالبريد المحدد عند إضافة عضو. |
| `ORGANIZATION_NOT_VERIFIED` | 403 | `EnsureVerifiedOrganization` (`verified_tenant`) | منشأة المستخدم لم يُسمح لها بالتداول بعد. `error.details` يحمل `required_action: COMPLETE_ORGANIZATION_VERIFICATION` و`organization_status` و`checklist: [{key,label_ar,required,done}]`؛ القرار قابل للطيّ من الإعداد `marketplace.require_verified_to_trade`، و`SuperAdmin` مُعفى. |
| `OFFER_NOT_LIKEABLE` | 404 | `OfferLikeController::store` | الاهتمام مسجَّل على العرض النشط فقط؛ المسودة والمسحوب والنافد لا يقبله. |
| `OFFER_NOT_COMMENTABLE` | 404 | `OfferCommentController::store` | التعليق على `ACTIVE` و`PAUSED` و`SOLD_OUT` فقط. |
| `PARENT_COMMENT_MISMATCH` | 422 | `OfferCommentController::store` | التعليق الأصلي المطلوب الردّ عليه ليس من هذا العرض. |
| `COMMENT_NOT_DELETABLE` | 403 | `OfferCommentController::destroy` | المسح للمؤلِّف أو منشأته أو المنصة؛ لا لزميل في منشأة أخرى. |
| `NO_IMAGE_ACCEPTED` / `IMAGE_LIMIT_REACHED` | 422 | `ProductImageController::store` | لم تُقبل أي صورة (النوع/الضرر)، أو تجاوز عدد `hassal.uploads.images.max_per_product`. الأول يرسل `error.details.rejected: [{file, reason}]`. |
| `DOCUMENT_TYPE_UNSUPPORTED` / `DOCUMENT_TOO_LARGE` / `DOCUMENT_LIMIT_REACHED` / `DOCUMENT_ALREADY_ACCEPTED` | 422 | `OrganizationDocumentController::store` | صيغة/حجم/عدد مرفوض من `hassal.uploads.documents`، أو إعادة رفع لورقة مقبولة. |
| `DOCUMENT_ALREADY_REVIEWED` | 403 | `OrganizationDocumentController::destroy` | الوثيقة المراجَعة لا تُحذف؛ الحذف لنسخة ما زالت `pending`. |
| `PRODUCT_NOT_OWNED` / `PLATFORM_REFERENCE_NOT_EDITABLE` | 403 | `ProductCatalogController::updateProduct` | صنفٌ لا لمنشأتك، أو مرجع مشترك على مستوى المنصة لا يملكه أحد. |
| `CURRENT_PASSWORD_INCORRECT` | 422 | `AuthController::changePassword` | كلمة المرور الحالية لا تطابق المحفوظ. |
| `WAREHOUSE_NOT_FOUND` | 404 | `WarehouseController::update` | لا مستودع بهذا المعرّف داخل سياق المستخدم. |
| `DUPLICATE_REPORT` | 409 | `SupportController::store` | نفس الشخص أرسل نفس النص في نفس التصنيف خلال عشر دقائق؛ `error.details.reference` يحمل رقم البلاغ الأول. |
| `REPLY_REQUIRED` | 422 | `SupportController::answer` | إغلاق بلاغ (`resolved` أو `dismissed`) بلا ردٍّ يقرؤه صاحبه. |
| `FEATURE_DISABLED` | 503 | `EnsureFeatureEnabled` (`feature:KEY`) | القدرة مغلقة بمفتاح ميزة؛ الاستجابة لا تسمّي المفتاح، فتُقرأ القوائم من `GET /api/v1/feature-flags` ومن `bootstrap`. |

---

## 4. مسارات النظام ونقاط النهاية (API Endpoints Catalog)

المصدر التنفيذي لهذه القائمة هو مخرجات `php artisan route:list --path=api/v1` (١٢٤ صفاً، عدَّها `route:list --json`
في ٢٠٢٦-٠٩-٢٢) مع `backend/routes/api.php`. كل مسار مذكور أدناه مسجَّل فعلياً؛ وما ليس كذلك مكتوب صراحةً تحت عنوان
«مسارات لا توجد». وبتطبيع المعاملات (`{id}` مقابل `${id}`) ودمج صفوف `GET|HEAD` في مسار واحد تُنتج الـ ١٢٤ صفاً
١٠٣ مسارات مميّزة، وهي نفسها مسارات `src/lib/api.ts` المميّزة وعدَّها ١٠٢، عدا مسار واحد لا يناديه العميل:
`…/documents/{id}/file`، وهو لا يُستدعى بالمسار بل يُفتح من الرابط الموقّع المؤقّت الذي ترجعه واجهة الوثائق.
(العدَّان من المخرجات لا من الحصر اليدوي: عدد صفوف `route:list --json`، ومقارنة مصفوفة مسارات العميل المطبَّعة
بمسارات الخادم المطبَّعة.)

### 4.1 التهيئة وصحة النظام (Bootstrap & Health)
- `GET /api/v1/bootstrap` — منفَّذ (`BootstrapController` ← `App\Services\BootstrapService`)، مروراً بـ `tenant`
  فلا يُلزم بتوكن لكنه يرفض `X-Organization-Id` ليس للمستخدم عضوية فيه.
  ```json
  {
    "success": true,
    "message": "Application bootstrap data loaded successfully",
    "data": {
      "app_name": "HASSAL",
      "app_version": "<config: hassal.brand.app_version>",
      "api_version": "v1",
      "system_status": "OPERATIONAL",
      "server_time": "2026-09-19T15:00:00+03:00",
      "default_locale": "<setting: platform.default_locale>",
      "supported_locales": ["ar", "en"],
      "active_locale": "ar",
      "country": "<setting: platform.country>",
      "base_currency": "<setting: platform.default_currency>",
      "currencies": [
        { "iso_code": "YER", "name_ar": "ريال يمني", "symbol": "ر.ي", "decimal_places": 2, "is_default": true }
      ],
      "categories": [],
      "opportunity_reasons": [],
      "feature_flags": { "marketplace": { "key": "marketplace", "name_ar": "…", "name_en": "…", "is_enabled": true, "rules_json": null } },
      "platform_settings": {
        "tax": { "enabled": false, "name": "…", "rate": 0.0, "inclusive": false },
        "commission_rate": 0.0,
        "reservation_timeout_minutes": 30,
        "inspection_window_hours": 48,
        "default_city": "<setting: platform.default_city>",
        "public_configs": []
      },
      "user": null,
      "tenant": null
    }
  }
  ```
  القيم بين أقواس معقوفة تُقرأ من `system_settings` وقت الطلب، فلا تصلح ثوابت في العميل. عند تركيبة
  المنصة الافتراضية تكون الضريبة معطّلة بمعدل `0.0` والعمولة `0.0` حتى يسجّل المديرهما،
  و`currencies` تضمّ النشطة فقط. عند وجود جلسة تُعبَّأ `user` بـ `roles` و`permissions` وتُعبَّأ `tenant`
  بالمنشأة النشطة بما فيها `commercial_registration_number` و`vat_number`.
- `GET /api/v1/health` — منفَّذ (إغلاق في `routes/api.php`). يقيس فعلاً: محاولة اتصال بقاعدة البيانات
  تُرجع `driver` و`version` من الخادم نفسه، وفشلها يضع `status: degraded` مع `unreachable` ونص الخطأ،
  ويضيف `php_version` و`laravel_version` و`environment`. لا يفحص الكاش ولا الطوابير.

---

### 4.2 المصادقة والحساب الشخصي (Authentication)
- `POST /api/v1/auth/register` — **حسابٌ لشخص، لا منشأة**. `throttle:10,1` + `idempotency`. الحقول:
  `name` و`email` و`password` (`min:8`, `confirmed`) وإلزامية، و`phone` و`national_id` مقبولان لا مطلوبان.
  الحساب الجديد لا يحمل دور منصة ولا وقوفاً تجارياً (`assignRole('BuyerStaff')` كان هنا؛ لم يكن يمنح شيئاً
  اليوم، وكان يكتب صفة شركة على حسابٍ يتنقّل بين الشركات). أما `organization_name` و`organization_type` و
  `commercial_registration_number` و`vat_number` فمرفوضة صراحةً بقاعدة `missing` مع رسالة تسمّي المسار الذي
  يأخذها (`POST /organizations`)، لأن بوابةً ثانية تُنشئ المنشأة بلا فحص السجل والعدد كانت ثغرة في السياسة لا
  في الكود. الجواب `201` بحزمة الجلسة نفسها (بند `login`).
- `POST /api/v1/auth/login` — `login` (بريد **أو** هاتف) + `password`؛ يُصدر توكن Sanctum بصلاحية
  `config('hassal.auth.token_ttl_minutes')` مع قائمة منشآت المستخدم وحزمة الجلسة. حسابٌ بلا كلمة مرور محلية
  (فُتح من مزوّد هوية) يُرفض بنفس كلمات الرفض الأخرى، ولا يُخترع له بصمة مرور. ويقبل مع ذلك حقول الجهاز
  الاختيارية (`device_key` و`device_name` و`device_platform` و`biometric_unlock`) — بند 4.17.
- `POST /api/v1/auth/google` — `throttle:10,1`، عامّ بلا توكن. المتن: `credential` (رمز ID من زر جوجل، حتى
  4096 حرفاً) و`nonce` (16..128 حرفاً، يولّده المتصفح **قبل** السؤال ثم يعيده) و`device_name` اختياري.
  يُتحقّق من التوقيع ومفاتيح جوجل المنشورة ومن `iss` و`aud` و`exp` و`nbf` و`alg`، ومن أن `nonce` المطابق هو
  ما سألت به هذه الجلسة — فيرفض `GOOGLE_TOKEN_REJECTED` مع `error.details.reason` و`retryable`. لا يوجد
  `client_secret` في هذا المشروع كله: رمز ID يُتحقَّق منه بالمفاتيح العامة، لا بسرّ. الباب موافق بين الطرفين:
  الزر لا يُرسم ما لم يكن `auth.google_enabled` و`auth.google_client_id` (عامّان، يظهران في `bootstrap` تحت
  `platform_settings.public_configs`) مضبوطين، والمسار يرفض بالرمزين نفسيهما. التطابق: هوية محفوظة مسجَّلة
  مسبقاً → 200؛ بريد جديد و`auth.google_auto_register` (مبذول `1`) → 201 مع `password = NULL` و
  `email_verified_at` الآن؛ ولا رابط لبريدٍ مربوط بهوية أخرى (`GOOGLE_ACCOUNT_ALREADY_LINKED`). معرّف العميل
  إعدادٌ تشغيلي لا ثابت في الشيفرة، وهو معرّف عام تنشره جوجل نفسها للمتصفح — لا يُعدّ سرّاً ولا يُودَع في Git.
- `POST /api/v1/auth/logout` — إلغاء التوكن الحالي.
- **حزمة الجلسة واحدة** (`sessionPayload`): `token` (بصلاحية `config('hassal.auth.token_ttl_minutes')`)
  و`user` (وبه `roles` و`permissions` محسوبةً على سياق المنشأة التي يهبط فيها الطلب) و`active_organization`
  و`organizations[]` و`profile_completion`. تعيدها
  `login` و`google` و`register` و`me` جميعاً، لأن ثلاث نسخ من الشكل هي كيف تنفرد واحدةٌ منها — و
  `profile_completion` بالذات هو الفرق بين «أهلاً» و«لا نسمح لك بالتداول بعد».
  ```json
  { "profile_completion": { "required": true, "missing_fields": ["phone"], "has_organization": false, "has_password": false } }
  ```
  `missing_fields` من `AccountRequirements` وحدها (ترتيب عرض النموذج)، و`required` صحيحٌ أيضاً لمن لا منشأة
  له، و`has_password` كاذبة لمن فُتح حسابه من مزوّد هوية — فلا يُعرض عليه نموذج «تغيير كلمة المرور» ولا تُخمَّن
  الحالة من حقول فارغة في العميل. لا شيء هنا يدّعي أن رقماً تحقّقت منه الجهة الحكومية: رقم وطني كتبه المستخدم
  رقمٌ مكتوب.
- `GET /api/v1/auth/me` — الملف الشخصي والعضويات والصلاحيات. `user.permissions` هي **المجموعة الفعلية
  لهذا الطلب**: صلاحيات أدوار المنصة (حيثما كان الحساب) زائداً وقوفَ العضو في المنشأة التي يُعمل بها
  (`X-Organization-Id`، أو أول عضوية نشطة بلا ترويسة) من `config('rbac.membership_permissions')`.
  تُبنى أزرار اللوحة من هذا الجواب لا من تخمين العميل، لأنه الجواب نفسه الذي يعطيه
  `hasPermissionTo()` لترويسة `permission:` في المسارات — فلا تقرأ الواجهة صلاحيةً بطريقة ويؤدّيها
  الخادم بأخرى.
  `current_organization.membership_role` هي الوقوف المسجَّل، و`organizations[]` تعيد كل منشأة بدورها
  وحالتها.
- **نطاق الاسم هو القرار، لا مصدره.** `config('rbac.permission_scopes')` يقسّم الكتالوج قسمةً لا
  بقايا فيها: `platform` (ما تملكه المنصة على الجميع: `offers.moderate`, `offers.feature`,
  `community.moderate`, `organizations.verify`, `disputes.resolve`, `users.manage`, `roles.manage`,
  `settings.manage`, `currencies.manage`, `support.manage`) و`business` (فعل داخل شركة: النشر، الطلب،
  تأكيد الصفقة، إدارة الأعضاء، مفاتيح API) و`both` (قراءة ودفاتر ومخازن وأشعارات — يملكها الجانبان).
  القسمة مُتحقَّق منها في الاتجاهين (`RbacMatrixTest`)، وعمود `permissions.scope` يحمل النتيجة نفسها
  للتقارير.
  - `platform` لا تُقرأ من وقوف شركة ولا تُمنح من بابها، و`business` لا تُقرأ من الحساب ولا تُمنح من باب
    المنصة — والقاعدتان في `User::hasPermissionTo()` لا في المتحكمات، فصفٌّ وصل بالترحيل أو من وحدة تحكم
    القاعدة لا يفتح بابًا. `SuperAdmin` لا يحتاج تفرعًا: دورُه يحمل كل صفوف الكتالوج.
  - `both` يُقيَّم من المكان الذي يقف فيه الطلب: داخل شركة من عضويتها، وخارجها من الحساب. ولهذا لا يُسقط
    `/auth/me` اسمًا مشتركًا حين لا يكون هناك سياق — هو في الحالتين، والقرار للمتوسط.
  - العملاء يقرؤون النطاق من `GET /organizations/{id}/roles` (`grantable_permissions`) ومن
    `GET /rbac/platform` (`permissions` + `account_forbidden`)، لا من قائمة أسماء في نصّ مكتوب.
- `POST /api/v1/auth/switch-organization` — تبديل السياق؛ يرفض `ORGANIZATION_NOT_MEMBER` لمن ليس بعضو.
- `PATCH /api/v1/auth/profile` — تعديل هوية المستخدم نفسه: `name` و`phone` و`national_id` (كلها `sometimes`).
  لا `email` هنا: امتلاك البريد هو ما يُثبت مسار إعادة التعيين، فنقل هوية الدخول بكتابة في نموذج يسلم
  استعاداتها لمالك البريد التالي. ولا المنشأة ولا الأدوار: الأولى `PUT /organizations/{id}` والثانية
  `users.manage`، حتى لا يصبح شاشة البروفايل أقصر طريق لترقية الصلاحيات. `national_id` رقم يُدخله المستخدم
  وتقرأه المنصة؛ لا يتحقق منه أحد ولا يُعرض كـ«موثّق». يرفض `VALIDATION_FAILED` إن كان الهاتف مملوكاً لغيره.
- `POST /api/v1/auth/password` — `current_password` + `password` (`min:8`, `confirmed`)؛ يرفض
  `CURRENT_PASSWORD_INCORRECT`. يُلغي كل توكنات الحساب الأخرى ويُبقي التوكن الداعي، فلا يُطرد المستخدم بفعله.
- `POST /api/v1/auth/forgot-password` و`POST /api/v1/auth/reset-password` — `throttle:6,1`.

---

### 4.3 المنشآت والعضوية (Organizations & Memberships)
- `POST /api/v1/organizations` — **البوابة الوحيدة إلى سجلّ تجاري**، وهي التي تحمل القواعد كلها
  (`idempotency`؛ لا `permission` لأن من يُنشئ منشأة لا يملك بعدُ صلاحيةً في أي منشأة). ثلاث طبقات قبل كتابة
  أي صفّ، وكل واحدة رفضٌ باسمه:
  1. `ACCOUNT_PROFILE_INCOMPLETE` (403) إن ناقصٌ الحسابَ ما تطلبه هذه النشرَة؛ `error.details.missing_fields`
     هي القائمة نفسها التي يعيدها `profile_completion`، فلا يُفاجأ نموذجٌ بباب. ولا يُطالَب بذلك في
     `PATCH /auth/profile` — هناك كان سيُقفل الباب الذي يُفتح به.
  2. `ORGANIZATION_CREATION_LIMIT_REACHED` (403) إذا كان عدد المنشآت التي **أسّسها** (`role = owner`)
     قد بلغ `account.max_organizations_per_account` (صفر = بلا سقف)؛ التفاصيل `limit` و`founded`، والعضوية
     كعاملٍ في تسع شركات لا تُحسب — ذلك قرار توظيفٍ غير قرار التأسيس. ويعفى `SuperAdmin` لأن سقفاً وُضع
     ليمنع شخصاً من احتكار الهويات لا ليمنع من يُدير الهويات.
  3. المعرّفات القانونية من `legal.require_commercial_registration` و`legal.require_vat_number` (مطفآن
     مبذولاً): مطلوبان إن أُوقدا، و`nullable` وإلا — والفارغ يُخزَّن `NULL` لا بديلاً يشبه رقماً حقيقياً،
     مع فحص تفرد الرقمين في `withValidator` لأن الاسم ليس معرّفاً (متجران يحملان «مؤسسة النور» ولا يحمل
     السجلَ إلا واحد).
  النوع مقصور على `buyer`/`seller` لمن يسجّل بنفسه، و`intermediary`/`admin` لـ`SuperAdmin` فقط — فأدوار المنصة
  ليست أنواع منشآت يمنحها مشترٍ لحسابه. والسجلّ الجديد يبدأ `pending_kyc` دائماً، مع عضوية `owner` نشطة لصاحبه،
  ويمنعه `marketplace.require_verified_to_trade` من النشر والتداول حتى يقرأه مراجع.
  و`GET /api/v1/organizations` (للمنشآت التي تهمّ caller) و`GET /api/v1/organizations/{id}`.
- `PUT /api/v1/organizations/{id}` — بصلاحية `organizations.manage`. الحقول القانونية المطلوبة تُقرأ من الإعدادات،
  ولا يدّعي النظام توثيقاً حكومياً: لا يوجد ربط بسجل تجاري خارجي.
- `PUT /api/v1/organizations/{id}/verification` — صلاحية `organizations.verify` مستقلة عن `organizations.manage`،
  لأن قرار Allows التداول فعلٌ تخص المنصة لا صاحب المنشأة. الحالات: `pending_kyc|verified|suspended|rejected`.
- **بوابة التحقق (`verified_tenant`)**: تسدّ ما يُنشئ أثراً تجارياً — نشر العرض (`POST /marketplace/offers`)
  وإرساله للمراجعة وطلبُ الشراء بمساريه والصفقة وطلبُ الاحتياج وعروضُه والنزاع، إضافة إلى تسجيل الاهتمام
  والتعليق. ولا تسدّ التصفّح ولا `auth/profile` ولا العضوية ولا المستودعات ولا رفع الوثائق: لو سُدّت هذه
  لما تركت البوابة مخرجاً تُطالِب به. القرار من الإعداد `marketplace.require_verified_to_trade` (مبذول `1`)،
  و`SuperAdmin` مُعفى لأن عمله الجاري على معروضات غيره. ولا يُفتح المسار بالكمال الذاتي: `PUT …/status`
  لا ينقل مسودة إلى `ACTIVE` (`INVALID_OFFER_TRANSITION`)، فالبوابة تُسدّ من طرفيها.
- **وثائق المنشأة** — المجموعة الوحيدة المقصود أن تصلها منشأةٌ غير موثَّقة:
  `GET|POST /api/v1/organizations/{organizationId}/documents` و
  `DELETE /api/v1/organizations/{organizationId}/documents/{documentId}` و
  `PUT /api/v1/organizations/{organizationId}/documents/{documentId}/review` و
  `GET /api/v1/organizations/{organizationId}/documents/{documentId}/file`.
  - القراءة (`GET …/documents`) يحرسها `maySee()`: من هو `SuperAdmin`، أو يملك `organizations.verify`،
    أو كانت المنشأة المطلوبة هي سياق الطلب الحالي (`X-Organization-Id`) — من يستطيع أن يقرّر ورقةً يجب أن
    يستطيع قراءتها. ولا صلاحية خاصة مطلوبة هنا لأن الأمر قرار انتماء لا فعل إداري.
  - الرفع بـ `organizations.manage` وعلى منشأة المستخدم نفسه، `multipart/form-data`: `type` ∈
    `commercial_registration|tax_certificate|trade_license|founding_statute|id_proof|other`، و`file`
    (`application/pdf|image/jpeg|image/png`، حتى `hassal.uploads.documents.max_bytes` وافتراضه ٨ ميغابايت،
    وبحدّ `max_per_org` وافتراضه ٢٠)، و`reference_number` اختياري حتى ١٠٠ حرف. هذه هي الحقول كلها؛ لا
    تاريخ انتهاء للوثيقة في المخطط. الاستجابة `201` بحالة `pending`. إعادة رفع نوعٍ مقبول تُرفض
    (`DOCUMENT_ALREADY_ACCEPTED`)، وإعادة رفع نوعٍ مرفوض تستبدل نسخته المنتظرة فلا يبقى في الطابور ورقتان
    للسؤال نفسه.
  - **أين تسكن النسخة وكيف تُقرأ:** تُحفظ على قرص `local` الخاص (`storage/app/private`)، فليس لها مسار
    `public/storage` يُفتح من متصفح. مفتاح `url` في الاستجابة رابطٌ موقّع من `…/documents/{id}/file`
    بصلاحية ١٥ دقيقة، يوقّعه الخادم من طلبٍ كان قد اجتاز `maySee()` بالفعل، ويرفض `signed` مساره بلا
    توقيع. الرابط المؤقّت يجعل التسريب نافذة تُغلق، بينما الرابط الدائم يجعل ورقةً قانونية مكشوفة إلى الأبد.
  - الحالة `pending` عند الرفع؛ `accepted|rejected` بعد `review` بصلاحية `organizations.verify` وحدها،
    و`action` ∈ `accept|reject` مع `reason` إلزامي عند الرفض. نسخة مقبولة لا تُعاد ولا تُحذف
    (`DOCUMENT_ALREADY_ACCEPTED` / `DOCUMENT_ALREADY_REVIEWED`)؛ تُحذف المنتظرة أو المرفوضة لاستبدالها.
  - **ما يعنيه `accepted`:** أن موظف المنصة فتح هذه النسخة وقرأها. لا استعلام عن سجل تجاري خارجي في هذا
    المشروع كله، فلا تعرضه العميل ولا هذه الوثيقة «توثيقاً حكومياً». قرار التداول يبقى حالة المنشأة في
    `PUT …/verification`، وليس محصّلة قبول ورقات.
- `GET/POST /api/v1/organizations/{id}/members` و`PUT/DELETE /api/v1/organizations/{id}/members/{userId}`
  بصلاحية `members.manage` للكتابة؛ الدور membership-scoped داخل المنشأة فقط.
- `GET/POST /api/v1/organizations/{id}/roles` و`PUT|DELETE /api/v1/organizations/{id}/roles/{key}` —
  `members.manage` + `idempotency` للكتابة. هذه هي وقوف **هذه الشركة وحدها**: خمسة صفوف `is_system`
  تُزوَّد لكل منشأة من `config('rbac.membership_roles')`، وأدوار مخترعة (`warehouse_keeper`) يسمّيها
  صاحب العمل بمفتاح لاتيني صغير (`[a-z][a-z0-9_]{1,62}`) لا يصطدم بأسماء المنصة.
  - `GET` يُزوّد الصفوف الناقصة عند القراءة، ويرد مع الأدوار `grantable_permissions`: قائمة الأسماء التي
    يحتملها هذا الباب **وحدها** (`scope ∈ {business, both}`) مع نطاق كل اسم. الواجهة لا تُعرض ما لا
    تستطيع إرساله، والإرسال رغم ذلك مرفوض بالاسم.
  - `POST` يقبل `permissions` أو `copy_from` (نسخة من دور موجود في هذه المنشأة). إن حُددت `permissions`
    فهي التعريف كله؛ وحين تُحذف مع `copy_from` تُنقل صلاحيات المصدر. `reason` إلزامي (`ORG_ROLE_CREATED`).
  - `PUT` يستبدل صلاحيات الدور استبدالاً (محرّر صناديق، لا `add/remove`)، ويحمي `owner`: نقص أي من
    `members.manage` أو `organizations.manage` منه يرد `OWNER_STANDING_PROTECTED` قبل أي كتابة.
  - `DELETE` للأدوار المخترعة فقط، وبسبب (`reason`)، ولا يُحذف دورٌ يحمله أحد (`ROLE_IN_USE` مع
    `error.details.holders`)؛ أدوار المنصة الخمسة تُحرَّر ولا تُمحى لأن العمود يخزّن نصّها.
  - كل قرار يُسجَّل في `audit_logs` بحالتيه قبل وبعد (`ORG_ROLE_CREATED` / `ORG_ROLE_UPDATED` /
    `ORG_ROLE_DELETED`) مع `organization_id`، فالسؤال عن الصلاحيات لا يُطرح إلا بعد فواته.
- `GET|PUT /api/v1/organizations/{id}/members/{userId}/permissions` و
  `PUT /api/v1/organizations/{id}/members/permissions/bulk` — `members.manage`؛ التجاوز الفردي:
  `grants` تضيف فوق الوقوف و`denials` تمنع حتى لو كان الوقوف يحمله.
  - المسألتان إلزاميتان في `PUT` وكلتاهما تقبل الفراغ: القائمة الفارغة قرار معلن («لا إضافة») لا حقل
    مُهمَل، والاستبدال كامل — اسمٌ لم يَرِد في أيٍّ من القائمتين تُسحب صفّته عن هذا الشخص.
  - `MEMBER_PERMISSION_CONTRADICTION` إذا اجتمع منح وسحب للاسم نفسه في الطلب، بدل أن يحسمها ترتيب أسبقية
    لا يعرفه المرسل. ومنح اسمٍ خارج متاحة المنشأة `MEMBER_PERMISSION_REFUSED` بأسمائه.
  - المالك لا يُسحب منه `members.manage`/`organizations.manage` ولو كان الرفض من قراره هو
    (`OWNER_STANDING_PROTECTED`)؛ شركةٌ لا يستطيع آخر ملّاكها أن يوظّف فيها لا يُصلحها إلا باب المنصة.
  - الجماعي يرفض كل الطلب إذا كان أحد الأرقام ليس عضو (`MEMBERSHIP_NOT_FOUND`) ولا يكتب صفًّا واحدًا —
    «منحنا الأحد عشر باستثناء علي» نصف قرار لا يصفه سجل ولا يعاد إرساله بأمان.
  - الاستجابة المشتركة `role_permissions` (ما يحمله الوقوف) و`grants`/`denials` (صفوف هذا الشخص)
    و`effective` (ما ينفّذه وسيط الصلاحيات فعلًا) — والقائمة الأخيرة محسوبة بدالة القرار نفسها، فلا تعد
    الواجهة صلاحية يردّ عليها المسار بـ 403.
  - **قاموس الوقوف الذي تعرضه الواجهات** (`GET /api/v1/organizations/membership-roles`) يقرأ الآن وقوفَ
    الشركة نفسها من `organization_roles` متى وُجد سياق منشأة، ويرتد إلى `config` قبل أي عضوية. لكل صف
    `value` و`label` و`is_system` و`permissions` + `permission_count`. هذا هو القاموس الذي تبني منه
    الواجهات قوائمها، وهو الذي يقبله المُحقِّق في `role`: قائمة مكتوبة داخل العميل هي 422 مؤجَّل إلى أول
    اسم جديد. (قيد `organization_memberships_role_is_canonical` حُذف عند هذا الترحيل لأن العمود صار
    يسجّل أسماء تخترعها شركة؛ `operator` هُجّر سابقًا إلى `staff`.)
  - `reason` إلزامي في الإضافة والتغيير والإزالة، ويُخزن في سجل التدقيق (`ORG_MEMBER_ADDED` /
    `ORG_MEMBER_UPDATED` / `ORG_MEMBER_ROLE_CHANGED` / `ORG_MEMBER_REMOVED`) مع الدور السابق واللاحق.
  - هذا السطر هو **مصدر الصلاحيات التجارية** لا عنوان للعرض: الصلاحية تُحسب من صفّ وقوف الشركة
    (`organization_roles`) زائداً منح العضو ناقصاً سحبه، ومع غياب الصف يُرجَع إلى
    `config('rbac.membership_permissions')` — وهي نفسها وقوف المنصة الخمسة التي تُزوَّد بها كل شركة
    فتعطي `owner` ٣٥ صلاحية و`admin` ٣٤ (بدون `api.manage`) و`staff` ٢٢ و`finance` ١٣ و`auditor` ٩.
    كل ما يُحسب يُرشَّح بقاعدة النطاق: `users.manage` و`offers.moderate` و`organizations.verify` و
    `disputes.resolve` (وبقية `platform`) لا تدخل هذا الحساب من أي باب، فلا تُركَّب صلاحية منصة من وقوف
    شركة. يقرؤها `User::hasPermissionTo()` على سياق المنشأة الحالية؛ تغيير الدور هنا يغيّر ما يستطيع
    الشخص فعله في هذه الشركة وحدها، وسحبه من الشركة (`is_active=false`) يُنهي سلطته بلا لمس أي دور آخر.
    `members.manage` نفسها مستمدّة من المصفوفة (`covers()`)، فلا تُصانَ قائمة ويُسكت أخرى.
  - من يدير العضوية: مالك المنشأة أو مديرها (`owner`/`admin`)، أو `SuperAdmin`. غير ذلك 403
    (`FORBIDDEN`) قبل أي فحص آخر.
  - الملكية لا تُمنح ولا تُسحب إلا بمالك حالي أو بإدارة المنصة (`OWNERSHIP_MOVE_FORBIDDEN`)، ولا يبقى
    كيان بلا مالك نشط (`LAST_OWNER_REQUIRED` عند التغيير و`CANNOT_REMOVE_LAST_OWNER` عند الإزالة).

---

### 4.4 دليل المنتجات (Catalog)
- `GET /api/v1/marketplace/categories` و`GET /api/v1/marketplace/brands` — عامّان.
- `GET /api/v1/catalog/products` — بصلاحية `offers.view`.
- `GET /api/v1/catalog/products/{id}/variants` — لمستخدم مصادَق عليه.
- `POST /api/v1/catalog/products` — بصلاحية `catalog.manage`، ويُنشئ معه صنفًا فرعيًا واحدًا تلقائياً.
- `PUT /api/v1/catalog/products/{id}` — `catalog.manage` + `idempotency`: الحقول `category_id`, `brand_id`,
  `name_ar`, `name_en`, `unit`, `package_size`, `temperature_class`. **`sku` ليس محرَّرًا هنا** بحساب الكود:
  الكود الذي يمسحه المستودع يعيش على الصنف الفرعي المولَّد مع الصنف، فتغييره دون تغييره يترك مسحاً واحداً
  بإجابتين. الرفض: `PRODUCT_NOT_FOUND` (٤٠٤)، `PLATFORM_REFERENCE_NOT_EDITABLE` (٤٠٣ على مرجع مشترك
  `organization_id = null`، لأن تعديله يعيد كتابة مفردات غيرك)، `PRODUCT_NOT_OWNED` (٤٠٣ على صنف منشأة أخرى).
- **صور الصنف** — على `catalog.manage` لا على صلاحية جديدة: من يحرّر الصنف يستطيع أن يُريه.
  - `GET /api/v1/catalog/products/{productId}/images` و`POST` بنفس المسار و
    `PUT|DELETE /api/v1/catalog/products/{productId}/images/{imageId}`.
  - الرفع `multipart/form-data` بالحقل `images[]` (مصفوفة، `min:1` وأقصى `hassal.uploads.images.max_per_product`
    = ٨)، المقبول `image/jpeg|png|webp` حتى `max_bytes` = ٤ ميغابايت. الملف يُقرأ من بايتاته لا من اسمه،
    فلا يصل اسم ملف العميل إلى القرص. تُستضاف الصور على قرص `public` لأن صورة الصنف واجهة العرض نفسها؛
    وهذا لا يُطبَّق على وثائق المنشأة في §4.3.
  - **رفض جزئي لا فشل كامل:** كل صورة صالحة تُحفظ، وترد غير المقبولات في `data.rejected: [{file, reason}]`
    مع حفظ الباقي. وإن لم تُقبل واحدة: `NO_IMAGE_ACCEPTED` بنفس التفاصيل. تجاوز العدد `IMAGE_LIMIT_REACHED`.
  - أول صورة محفوظة تُصبح الغلاف إن لم يكن للصنف غلاف؛ و`PUT` يقبل `is_cover` (يجعلها الغلاف الوحيد بجعل
    الأخريات لا) و`sort_order` (٠–٩٩٩) لإعادة الترتيب. حذف الغلاف ينقل الغلاف إلى التالية ترتيباً فلا يفقد
    الصنف صورته الرئيسية.
  - شكل الصورة: `id`, `product_id`, `url`, `is_cover`, `sort_order`, `mime_type`, `size_bytes`, `width`,
    `height`, `original_name`, `uploaded_at`. عرض `url` عامّ فلا تُرفع صورة تحمل بيانات شخصية.
- الكتالوج مشترك على مستوى المنصة، والسعر والكمية يبقى لدى العرض؛ لا يوجد `GET /api/v1/catalog/categories`
  ولا `GET /api/v1/catalog/brands`.

---

### 4.5 السوق والعروض (Offers & Geo Marketplace)
كل مسارات السوق تحت البادئة `/api/v1/marketplace`. القائمة العامة لا تُوسَّع من الطلب: تُصفّى دائماً على
عروض نشطة، بمتبقٍ، غير منتهية، من منشأةسمح لها بالمنصة بالتداول.
- `GET /api/v1/marketplace/offers`
  - **المعلمات:** `search`, `category_id`, `city`, `warehouse`, `sku`, `opportunity_reason`, `min_price`, `max_price`,
    `min_order_quantity`, `max_moq`, `currency_code`, `delivery_terms` (`BUYER_PICKUP|SELLER_DELIVERY|BOTH`),
    `sort_by` (`newest|cheapest|priciest|expiring|quantity|nearest_to_expiry` أو اسم عمود:
    `unit_price|created_at|expires_at|available_quantity`) مع `sort_dir`, و`page`, `per_page` (١–٥٠، افتراضي ١٥).
- `GET /api/v1/marketplace/offers/nearby` — خلف `feature:geo_discovery`
  - **المعلمات:** `latitude`, `longitude` (مطلوبان)، `radius_km` (١–٥٠٠؛ افتراضياً `matching.geo_radius_km`
    وقيمته ١٠٠ إن لم تُضبط، أي ٥٠ كم كما يُعرض في الواجهة فقط إن قرأتها من الإعدادات)، إضافة إلى
    `category_id`, `opportunity_reason`, `min_price`, `max_price`, `min_quantity`, `max_moq`, `currency_code`,
    `delivery_terms`, `sort_by` (`distance|price|freshness`), `per_page`.
  - **الاستجابة:** عروض نشطة مرتبة تصاعدياً بـ `distance_km` مع اسم المستودع والمدينة.
- `GET /api/v1/marketplace/offers/featured` — عام (لا جلسة). الشريط المميّز أعلى السوق.
  - **الاستجابة:** `data.offers` (مصفوفة، لا صفحة: السقف عددٌ صغير يقرّره الإعداد) مرتّبة
    بـ `featured_at` تنازلياً — أحدث تمييزٍ أولاً — و`data.limit` و`data.total_featured`.
  - **لا استثناء في الإظهار:** تُطبَّق على صفوف هذه القائمة السلسلةُ نفسها التي تُصفّي
    القائمة العامّة (`tradable` + `ACTIVE` + متبقٍ أكبر من صفر + `expires_at` غائب أو لم يمضِ)،
    فالتمييز ليس جواز مرور: دبوسٌ على عرضٍ نافد أو منتهٍ أو موقوف أو سحبت المنصةُ تجارةَ صاحبه
    لا يظهر هنا، وإن بقي الدبوس محفوظاً.
  - `total_featured` قد تزيد على `limit`، وهذا جواب «كم مثبَّتاً عندك» لا «كم أضعنا»:
    السقف من `marketplace.featured_limit` (١–٣٠ بعد القصّ) وتُعرض أحدثُه.
- `GET /api/v1/marketplace/offers/{id}` — عام.
- `POST /api/v1/marketplace/offers` — `offers.create`: ينشئ **مسودة** `DRAFT` ولا ينشرها.
- `POST /api/v1/marketplace/offers/{id}/submit` — `offers.publish`: مسودة إلى طابور المراجعة.
- `PUT /api/v1/marketplace/offers/{id}/status` — `offers.publish`: تنشيط/إيقاف/إلغاء، ويرفض `INVALID_OFFER_TRANSITION`.
- `PUT /api/v1/marketplace/offers/{id}` — `offers.update`: التحرير مقبول في `DRAFT` أو `PAUSED` فقط
  (`OFFER_NOT_EDITABLE`)، والكمية الكلية لا تتغير بعد حجز أو بيع (`OFFER_QUANTITY_COMMITTED`). المستودع والمتغير
  والعملة ليست حقولاً محرَّرة في هذا المسار.
- `GET /api/v1/marketplace/my-offers` — `offers.view`: أرفف منشأة المستخدم بكل الحالات.
  - **`category_id` مشتقٌّ لا مكتوب (تطبيع 2026-09-25):** العمود رُفع من `offers`، لأن صنف العرض تحدّده
    سلسلة `product_variant_id ← product_variants.product_id ← products.category_id`، فالتصنيف المخزَّن
    المشتَقّ لا يخلّف تكراراً بل خلافاً: منتجٌ يُنقل يصحب رفوفَه القديمة في صنفٍ غادرته. الاسم يبقى في
    الاستجابة (`OfferResource`، وقراءة النموذج الخام عبر `$appends`) ويبقى مرشّحاً في `?category_id=`
    (بـ `whereHas` على المنتج)، لكن لا مساراً يقبل كتابته: `POST` و`PUT` يرفضان `category_id` في الجسم
    ويقرّران الصنف من المنتج المسمّى، والخلاف بينهما `CATEGORY_PRODUCT_MISMATCH`.
- `GET /api/v1/marketplace/moderation-queue` و`PUT /api/v1/marketplace/offers/{id}/review` — `offers.moderate`.
- `PUT /api/v1/marketplace/offers/{id}/feature` — `offers.feature` + `idempotency`. يثبّت العرض أعلى السوق أو
  يرفع عنه التثبيت: الجسم `{"featured": true|false}`، والجواب عرضٌ كامل كبقية المسارات حتى تقرأ الواجهة
  الحقيقة نفسها التي رسمتها. التثبيت على غير `ACTIVE` يُرفض `OFFER_NOT_FEATUREABLE`، وإعادة تثبيت عرضٍ
  مثبَّتٍ نقلةٌ إلى مقدّمة الشريط (الترتيب هو زمن القرار)، والرفع عنه يمسح `featured_at` و`featured_by` معاً.
  الصلاحية الجديدة ليست `offers.moderate`: المراجعة تقرّر هل يُعرض شيءٍ أصلاً، والتمييز تقرّر أيَّ
  المعروض يُرى أولاً — ولا تحملها عضويةٌ في منشأة (`config/rbac.php`).
  - **حقلا العرض:** `featured_at` (توقيت أو `null`) معطىً عامٌّ لكل قارئ، لأن كون السوق يُروِّج لبطاقةٍ ما
    حقيقةٌ يجب أن تُرى؛ و`featured_by` (`{id,name}` حساب الموظف) لا يظهر إلا لمن يحمل `offers.feature`،
    لأن اسم موظفٍ على استجابةٍ عامّة تسريبٌ لا تدقيق.
- `DELETE /api/v1/marketplace/offers/{id}` — `offers.delete`.
- `GET /api/v1/marketplace/warehouses` — عام؛ و`GET|POST /api/v1/warehouses` بصلاحية `warehouses.view|manage`.
- `PUT /api/v1/warehouses/{id}` — `warehouses.manage` + `idempotency`: نفس قاعدة الإنشاء بالحروف، لأن
  المستودع لا يجوز أن يُقبل تحريره في مسار ويرفض في الآخر. إلزامي دائماً: `name`, `city`, `address_line`,
  `latitude`, `longitude` (مدى الإحداثيات عالمي WGS84 لا صندوق دولة: قراءة متصفح فاشلة هي بالضبط ما يفحصه
  القيد)؛ واختياري لا يُمسّ إن غاب: `code`, `district`, `contact_name`, `contact_phone`, `is_active`. فإحداثيات
  لا تُرسل تعني `VALIDATION_FAILED` لا مسحاً صامتاً، ومع ذلك تعيد الواجهة إرسال القراءة كاملة عند التصحيح.
  المعرّف من منشأة المستخدم وإلا `WAREHOUSE_NOT_FOUND` (٤٠٤) — المسار لا يُقرّ بأن لغيرك مستودعاً بهذا الرقم
  لأن ذلك ذاته تسريب. قراءة المتصفح للأحداثيات اختيارٌ يقترحه العميل لا افتراض: الرفض أو المهلة لا تمنعان
  الإدخال اليدوي.

---

### 4.6 طلبات الشراء ثم الحجز (Purchase Requests → Reservation)
هذا هو الترتيب المقرَّر في المواصفة الأصلية: العرض → طلب شراء → مراجعة البائع → الحجز → الصفقة.

- `POST /api/v1/purchase-requests`
  - **الرؤوس:** `Authorization`, `X-Organization-Id`, `X-Idempotency-Key`. **الصلاحية:** `requests.view` + `requests.create`.
  - **جسم الطلب:**
    ```json
    {
      "offer_id": 12,
      "requested_quantity": 100,
      "delivery_preference": "PICKUP",
      "notes": "نحتاج الاستلام صباح يوم الأحد"
    }
    ```
  - **السلوك:** يُسجَّل طلب برقم `PR-yymdd-XXXXXX` بحالة `REQUESTED`، بأخذ لقطة من سعر العرض ووحدة قياسه،
    ويحمل الكمية والمهلة والملاحظات، و**لا يمسّ
    `reserved_quantity` ولا `available_quantity`**. الرفض هنا: `SELF_PURCHASE_FORBIDDEN`،
    `OFFER_NOT_TRADABLE`، `SELLER_NOT_TRADING`، `QUANTITY_EXCEEEDS_AVAILABLE`، `QUANTITY_BELOW_MIN_ORDER`.
- `POST /api/v1/purchase-requests/matches/{matchId}` — نفس الأثر من مسار الطلب/المطابقة، ويرفض
  `MATCH_ACCESS_DENIED` و`MATCH_EXPIRED` و`MATCH_NOT_ACTIONABLE`.
- `GET /api/v1/purchase-requests` و`GET /api/v1/purchase-requests/{id}` — `requests.view`.
- `POST /api/v1/purchase-requests/{id}/accept` — `requests.approve`. **هنا يحدث الحجز**: داخل معاملة مع
  `lockForUpdate` يعيد فحص حالة العرض والصلاحية والحدّ الأدنى والمتاح، ويولّد حجزاً برقم `RES-yymdd-XXXXXXXX`
  بمدة من إعداد `reservation.timeout`. إن نفد المتاح بعد إرسال الطلب: `PURCHASE_REQUEST_ACCEPT_FAILED`
  ويبقى الطلب `REQUESTED`.
- `POST /api/v1/purchase-requests/{id}/reject` — `requests.reject` (لا كمية ترجع لأن لم تُحجز أصلاً).
- `POST /api/v1/purchase-requests/{id}/propose` — `requests.update`: عرض مضادّ يُسجَّل كشروط مقترحة
  دون إعادة كتابة ما طلبه المشتري؛ `PROPOSAL_HAS_NO_CHANGE` عند عدم التغيير.
- `POST /api/v1/purchase-requests/{id}/accept-proposal` — `requests.update`: المشتري يتبنّى العرض المضادّ
  فيعود الطلب إلى مراجعة البائع.
- `POST /api/v1/purchase-requests/{id}/cancel` — `requests.cancel`.
- `GET /api/v1/marketplace/reservations` — الحجوزات التي أنشأها القبول **لمنشأة المشترة الحالية**
  (`buyer_organization_id`)، مع فلتر `status`، و٢٠ في الصفحة.
- `POST /api/v1/marketplace/reservations/{id}/cancel` — `requests.cancel`: التخلّي عن الكمية وإعادتها
  لمتاح البائع؛ `RESERVATION_NOT_ACTIVE` إذا كان الحجز قد تحوّل صفقة أو انتهت مدته.

---

### 4.7 الصفقات وآلة الحالة (Deals)
- `GET /api/v1/deals` و`GET /api/v1/deals/{id}` — `deals.view` + `feature:marketplace`؛ `perspective=buyer|seller|all`.
- `POST /api/v1/deals` — `deals.view` + `deals.create`:
  ```json
  {
    "reservation_id": 8,
    "delivery_method": "PICKUP",
    "delivery_address": "صيرة، عدن",
    "notes": "…"
  }
  ```
  الصفقة تُبنى على حجز **نشط غير منتهٍ** (`RESERVATION_EXPIRED`) مملوك للمنشأة، ولا تُبنى من عرض مباشرة.
  لقطة التسعير والضريبة والعملة تأتي من `PricingAndCurrencyService::calculateDealPricing()` وفق الإعدادات.
  - **أسماء الحقول (تطبيع 2026-09-25):** العملة تُخزَّن مرة واحدة باسم `currency_code`، والمعدل يُجمَّد مرة
    واحدة باسم `exchange_rate_snapshot` إلى جانب `base_currency`. لا `transaction_currency` ولا
    `exchange_rate` في الصفقة ولا في استجابتها: كان الأول نسخةً طبق الأصل من `currency_code` وكان الثاني
    نسخةً من اللقطة نفسها، فعمودان لسعرٍ واحد يعنيان تصحيحاً يجب أن يكتب مرتين أو يُخطئ. لقطةٌ تُخزَّن
    للاحتفاظ بالوعد لا للحالة الراهنة؛ سعر الصرف المتحرّك يبقى في `currencies` ويُقرأ من هناك عند الحاجة.
- `POST /api/v1/deals/{id}/transition`
  ```json
  { "status": "PROCESSING", "notes": "…", "metadata": {} }
  ```
  الحقل `status` لا `target_status`. كل حالة هدف تعلن صلاحيتها الخاصة (البوابة على الهدف لا على
  الرابط) إضافة إلى حصر الممثل داخل آلة الحالة:
  `deals.confirm` (PENDING_SELLER_APPROVAL، ACCEPTED، INSPECTION، COMPLETED، CANCELLED)،
  `deals.dispatch` (PROCESSING، READY_FOR_PICKUP، DISPATCHED، DELIVERED)، `deals.dispute` (DISPUTED)،
  `disputes.resolve` (RESOLVED — للمنصة فقط)؛ و`notes` إلزامية لإلغاء ونزاع وتسوية.
  الرفض: `DEAL_TRANSITION_FORBIDDEN` و`DEAL_STATE_UNKNOWN` و`STATE_TRANSITION_FAILED`
  و`HANDOVER_NOT_VERIFIED`.
  الآلة اثنتا عشرة حالة:
  `DRAFT, PENDING_SELLER_APPROVAL, ACCEPTED, PROCESSING, READY_FOR_PICKUP, DISPATCHED, DELIVERED,
  INSPECTION, COMPLETED, CANCELLED, DISPUTED, RESOLVED`.
  لا حالات دفع فيها إطلاقًا: `PENDING_PAYMENT` و`ESCROW_FUNDED` و`SETTLED` و`REFUNDED` أُزيلت مع
  نماذجها وجودلها — التسوية بين المنشأتين خارج المنصة، وهذا قرار هندسي لا حالة انتظار لعلم.
- **إثبات التسليم (`deal_handovers`) — جزء من الدورة لا خدمة جانبية:**
  - `GET /api/v1/deals/{id}/handover` — الرمز وحالته لأطراف الصفقة (تحت `deals.view`).
  - `GET /api/v1/deals/{id}/handover/label` — الملصق القابل للطباعة (SVG للرمز نفسه) للصندوق الذي
    يغادر المستودع قبل الهاتف الذي يحمله.
  - `POST /api/v1/deals/{id}/handover/verify` — `deals.deliver` + `idempotency`: ينفق الرمز مرة
    واحدة لصفقة بعينها، ومقبول في حالات `DELIVERED/INSPECTION/DISPUTED/RESOLVED` فقط.
    الرمز يُصدر عند `ACCEPTED` ضمن نفس المعاملة، ولا انتقال إلى `INSPECTION` أو `COMPLETED` قبل
    نفاده، والإلغاء يُبطل غير المنفاد (`INVALIDATED`) دون محو ما نُفد.

---

### 4.8 النزاعات (Disputes)
- `GET /api/v1/disputes` و`POST /api/v1/disputes` — `disputes.open`. تُرفع من صفقة داخل دورة
  التنفيذ (`ACCEPTED` حتى `INSPECTION`) وتحوّلها إلى `DISPUTED`.
- `POST /api/v1/disputes/{id}/resolve` — للمنصة (`SuperAdmin`)، والجسم
  `{ "status": "RESOLVED_RELEASE" | "RESOLVED_CANCELLATION", "notes": "…" }`: لا حقل `decision`
  ثانٍ يترجم إلى حالة، ولا مبلغ استرداد — منصّة لم تحتفظ مالاً لا تردّ شيئاً.
- الحالات الخمس: `OPEN` و`UNDER_REVIEW` و`EVIDENCE_REQUIRED` ثم قرار من اثنين:
  `RESOLVED_RELEASE` (الصفقة قائمة: `DISPUTED → RESOLVED` وتُغلق لاحقًا بمسح رمز التسليم) أو
  `RESOLVED_CANCELLATION` (`DISPUTED → RESOLVED → CANCELLED`: الكمية ترجع للمتاح والرمز يُبطل).
- لا يوجد رفع مستندات عبر API ولا تجميد مبالغ: المنصة لا تحتفظ بأموال، و`claimed_amount` رقم
  يصف مطالبة رافع النزاع لا أمر دفع.

---

### 4.9 دفتر المنصة (Ledger) — `ledger.view` + `feature:double_entry_ledger`
- `GET /api/v1/ledger/accounts` و`GET /api/v1/ledger/journal-entries` و`GET /api/v1/ledger/trial-balance`.
- لا وجود لـ`GET /api/v1/reconciliation/audit`: المسار حُذف مع `ReconciliationController` ولا بديل له؛
  القيد نفسه هو سجل المطابقة — عمولة واحدة تُقَيَّد عند إغلاق الصفقة.
- لا كشف حساب بمفهوم «أمانات محتجزة ورصيد قابل للتحويل»؛ القيد مزدوج يصف ما جرى في الصفقة فقط.

---

### 4.10 مخزون البائع (Inventory Movements)
- `GET /api/v1/inventory-movements` — `warehouses.view`: سجل الحركات (النوع، الكمية الموقّعة، السابق/الجديد، السبب، المُدخل).
- `POST /api/v1/inventory-movements/adjust` — `warehouses.manage` + `idempotency`:
  `offer_id` (من منشأة المستخدم وإلا `OFFER_NOT_OWNED`), `type` ∈ `IN|ADJUSTED|RETURNED`,
  `quantity` موقّعة (`QUANTITY_REQUIRED` للصفر، `QUANTITY_MUST_BE_POSITIVE` للإيراد والمرتجع),
  `reason` إلزامي حتى ٥٠٠ حرف. يرفض العرض المغلق `OFFER_CLOSED` والكمية المستحيلة `QUANTITY_INFEASIBLE`،
  ويحدّث المتاح والكمية الكلية في نفس المعاملة المقفلة.

---

### 4.11 طلبات الاحتياج (RFQ Demands) — `demands.view` + `feature:demand_rfq`
- `GET /api/v1/demands` ( للمنشأة )، `POST /api/v1/demands` (+`demands.create`)،
  `GET /api/v1/demands/{id}/matches` (+`feature:matching_engine`)،
  `POST /api/v1/demands/{id}/quotations` (+`demands.quote`)؛ `SELF_QUOTATION_DENIED` و`DEMAND_CLOSED` و`DEMAND_ACCESS_DENIED`.

---

### 4.12 طبقة المسؤول والإدارة (Platform Admin)
- `GET /api/v1/settings` و`POST /api/v1/settings/update` — `role:SuperAdmin`؛ `SETTING_NOT_EDITABLE` و`SETTING_TYPE_MISMATCH`.
- `GET /api/v1/feature-flags` و`POST /api/v1/feature-flags/{key}/toggle` — `settings.manage`؛ `FLAG_NOT_IN_CATALOG`.
- `GET|POST|PUT /api/v1/currencies[...]` و`POST /api/v1/currencies/{id}/set-default` — `role:SuperAdmin`.
- `GET /api/v1/users[...]` و`POST/DELETE /api/v1/users/{id}/roles[...]` و`PUT /api/v1/users/{id}/status` — `users.manage`.
  - صفّ `GET /api/v1/users` مُهيّأ لا نموذج Eloquent خاماً: `roles` مصفوفة أسماء نصوص (كما في
    `/auth/me`)، و`organizations[]` فيها `id, name_ar, name_en, type, status, role, membership_active, joined_at`.
    `role` هنا هو دور العضوية من `organization_memberships` — وهو ما تقرأه لوحة الحسابات. حين كان الدور يسكن
    وحده في `organizations[].pivot.role` كانت اللوحة لا تجد الحقل المعلن فتعرض أول خيار («مالك المنشأة»)
    لكل حساب، وقد كان مسجّلاً في القاعدة `staff`. لا تُسرّب القائمة أرقام السجل التجاري ولا `pivot`.
  - `PUT /organizations/{orgId}/members/{userId}` يقبل `is_active` اختيارياً مع `role` و`reason` في قرار واحد،
    فتُنهى عودة الشخص إلى منشأة أو تُعاد بسجل تدقيق واحد (`ORG_MEMBER_ROLE_CHANGED`).
  - `GET /api/v1/users/roles` يعيد أدوار المنصة فقط (`config('rbac.platform_roles')` = `SuperAdmin`,
    `Auditor`) مع ما يمنحه كل دور فعليًا (`permissions` + `permission_count`، معدودة من صفوف `roles`
    المطبَّقة لا من نص في الواجهة). أسماء مثل `SellerAdmin`/`BuyerAdmin` أدوار داخل منشأة ولا تُقبل هنا:
    `Rule::in(platform_roles)` ترفضها 422، لأن إسنادها من لوحة المنصة كان يكتب صفاً عالمياً ويترك
    مكان الشخص في شركته كما هو — وهذا جوهر شكوى «حقل تغيير الدور لا ينعكس».
  - `reason` إلزامي في الإسناد والسحب ويُخزن مع القرار (`USER_ROLE_ASSIGNED` / `USER_ROLE_REVOKED`).
    السحب عبر `DELETE` يحمل الجسم نفسه (لا مسار بلا سبب).
  - حواجز القفل: لا سحب لدور منصة من هذه الشاشة لغير قوامها (`NOT_A_PLATFORM_ROLE`، وتحوّل إلى
    `PUT …/members/{userId}`)، ولا دور غير-held (`ROLE_NOT_HELD`)، ولا تكرار (`ROLE_ALREADY_ASSIGNED`)،
    ولا سحب `SuperAdmin` عن النفس (`CANNOT_REVOKE_SELF`)، ولا سحبه من آخر من يحمله
    (`LAST_SUPER_ADMIN`) — وإلا تُغلق المنصة على نفسها.
  - `PUT …/status` يطلب سببًا ويقفل الجلسات: تُحذف كل `personal_access_token` للحساب عند أي حالة غير
    `active` ويعيد `meta.closed_sessions`. الحالة لم تعد تُقرأ عند الدخول فقط:
    `EnsureAccountIsActive` في مجموعة `api` ترفض كل طلب بحساب غير نشط بـ 403 `ACCOUNT_NOT_ACTIVE`
    (خارج `SecurityHeaders` كي تحمل الاستجابة ترويسات الأمان نفسها). القيم `active|suspended|inactive`
    lowercase ومقيّدة في العمود بـ `users_status_is_canonical` بمقارنة `utf8mb4_bin`، لأن
    `utf8mb4_unicode_ci` كان يجعل `SUSPENDED` «يساوي» `suspended`.
- **باب المنصة للصلاحيات** — `GET /api/v1/rbac/platform` و
  `PUT /api/v1/rbac/platform/roles/{roleName}/permissions` و
  `GET /api/v1/rbac/platform/users/{id}/permissions` و`PUT /api/v1/rbac/platform/users/permissions`،
  كلها بصلاحية `roles.manage` (لا `users.manage`: تحرير ما يساويه الدور فعلٌ غير تحرير الحساب، ومن يملك
  واحدهما لا يملك الأخرى بالضرورة) و`idempotency` للكتابة.
  - `GET` يردّ `permissions` (كل الكتالوج مع نطاقه)، و`roles[]` بأدوار المنصة فقط مع `declared` (ما يقوله
    `config`) و`permissions` (ما تقوله صفوف الدور الفعلية) و`is_immutable` و`holder_count`، و
    `account_forbidden` = أسماء المنشأة التي لا تُسنَد لحساب. القوائم تُعرض للتحكم لا لتُعاد كتابتها.
  - استبدال صلاحيات دور منصة يستثني `SuperAdmin` (`ROLE_IMMUTABLE`، لأنه `'*'` في الملف وتحريره من واجهة
    يقفل اللوحة التي تُصلحها) ويستثني أدوار الشركات (`ROLE_NOT_PLATFORM` — تُحرَّر من باب منشأتها)، ويرفض
    أي اسم `business` أو غير معلن (`ACCOUNT_PERMISSION_REFUSED`) لأن المنح على الحساب يتبع صاحبه إلى شركات
    لا علاقة له بها ويبقى بعد رحيله.
  - الإسناد الفردي للحسابات يقبل `user_ids[]` و`permissions[]` و`mode ∈ replace|add|remove` و`reason`:
    الطلب الواحد قد يكون صيانة محلّل واحد أو توظيف فريق منهم، والفرق بين «هذه كلها» و«أضف هذه» أكبر من أن
    يُستنتج من شكل المتن. حساب غير موجود يردّ `USER_NOT_FOUND` ولا يكتب أي صف.
    والمسؤولية عن `mode` على العميل: `replace` استبدال لا استنتاج، فلا يقرّر أي عميل ذلك من المربعات
    المحدَّدة. هذا الباب يُحرَّر في كل عميل من موضع واحد — من صفّ الحساب نفسه في «حسابات المنصة» (ويب)
    ومن `account_permissions_sheet.dart` (جوال) — لا من شاشة مصفوفة الأدوار، لأن النسخة الثانية من
    الكتابة نفسها هي ما يجعل أحد الموضعين يفرض قاعدةً لا تعرفها الأخرى.
  - `GET …/users/{id}/permissions` يفرق بين `grants` (الصفوف الخام، بما تركه ترحيل قديم) و`effective`
    (ما يقرّره الوسيط بعد قاعدة النطاق) — والفرق بينهما هو ما يحتاج المشغّل أن يراه لينظّفه، ولو رُدّت
    واحدة منهما لبدت الأخرى كذبة.
- `GET /api/v1/audit-logs` — `audit.view`: SuperAdmin يرى الكل، وغيره مقيّد صارماً بمنشأته
  (`NO_ORGANIZATION_CONTEXT` إن غاب السياق).
- `GET /api/v1/reports/sales-summary` و`GET /api/v1/reports/export/deals-csv` — `reports.view`.
- `GET|POST /api/v1/api-keys`، `DELETE /api/v1/api-keys/{id}` — `api.manage` + `feature:api_keys`.
- `GET|POST /api/v1/webhooks`، `GET /api/v1/webhooks/{id}/deliveries`، `POST /api/v1/webhooks/{id}/ping` —
  `api.manage` + `feature:webhooks`.
- `GET /api/v1/setup-wizard/status` (عام) و`POST /api/v1/setup-wizard/configure` (`role:SuperAdmin`).

---

### 4.13 وسائط السوق: الاهتمام والأسئلة والبلاغات (Offer Community) — `feature:community`
الإعجاب والتعليق **سطران منسوبان** لا عدّادات يحرسها العميل: كل منهما يحمل `user_id` و`organization_id`،
وهذا ما يجعل حذفهما قراراً يستطيع الخادم فحصه. لا تقييم بالنجوم ولا «تقييم البائع» في هذا النظام كله: رقم
نجمة واحد يستعرِض سمعة منشأة لم يقرأ أحد أوراقها، أما الاهتمام والسؤال فأفعال تُسجَّل باسم صاحبها.

**عامّ من غير توكن:**
- `GET /api/v1/marketplace/offers/{id}/comments` — خيط العرض المنشور. يستجيب `{offer_id, comments: […]}`
  من الأقدم إلى الأحدث، وكل سؤال معه `replies[]` منهورة (ردّ على ردّ يلحق السؤال نفسه، فالخيط تجاري لا شجرة). عنصر
  التعليق: `id`, `offer_id`, `body`, `author_name`, `organization_id`, `organization_name`,
  `is_seller_reply`, `status`, `report_count`, `created_at`, و`replies` عند الطلب. تُصفّى على `published`،
  فالتعليق المنتظر مراجعة لا يتسرب وجوده ولا نصه.

**الكتابة (تحتاج توكنًا؛ سياق المنشأة اختياري هنا لا إلزامي — للمستهلك بلا منشأة وقوفٌ باسمه):**
- `POST /api/v1/marketplace/offers/{id}/like` — `offers.like`: صف `201` بـ `{liked, likes_count, created}`.
  على عرض `ACTIVE` فقط (`OFFER_NOT_LIKEABLE`/٤٠٤)، والتكرار idempotent بـ`firstOrCreate` فلا يرفع العدّاد.
  السطر للفرد لا للمنشأة: `(offer, user)` هو القيد الفريد، و`organization_id` يوثَّق من السياق إن وُجد ويكون
  `null` للمستهلك. من ضغط من شركتين لا يضيف إشارة ثانية، والسطر يبقى لأولي نسبتهما.
- `DELETE /api/v1/marketplace/offers/{id}/like` — `offers.like`، وبلا بوابة: التراجع عن فعل لا يُمنع بانقضاء
  الأوراق. الحذف بمجموعة `(offer, user)` لا بمعرّف الصف، فتخمين سطر غيرك يمسح سطرك أنت.
- `GET /api/v1/marketplace/liked-offers` — `{offer_ids: […]}` لكل ما سجّله هذا الحساب من اهتمام، عبر كل
  منشآت صاحبه وباسمه المجرد إن لم تكن له منشأة. القائمة العامة
  مجهولة بالقصد فتردّ نفس الجواب بتوكن وبغيره، ولهذا يحمّل العميل هذه المجموعة مرة ويملا أزراره منها.
- `POST /api/v1/marketplace/offers/{id}/comments` — `comments.create`: `body` (٢–٢٠٠٠) و`parent_id` اختياري.
  العرض المقبول `ACTIVE|PAUSED|SOLD_OUT` (`OFFER_NOT_COMMENTABLE`)، و`parent_id` من عرض آخر
  (`PARENT_COMMENT_MISMATCH`/٤٢٢). `is_seller_reply` يقرره الخادم بمقارنة منشأة الكاتب بمنشأة البائع — لا
  يقبله العميل بياناً — فيصدق على ملاحظة البائع الافتتاحية كما على ردّه. الحالة `pending` أو `published`
  بحسب الإعداد `community.moderate_before_publishing` (مبذول `0`)، والرسالة ترجع مطابقة لهما. و`organization_id`
  في الصف من السياق أو `null` لمستهلك يسأل باسمه: المُنبّه `is_seller_reply` لا يصدق على فراغ، و`shape` يحذف
  الحقول الفارغة فلا يظهر اسم منشأة حيث لا منشأة.
- `DELETE /api/v1/marketplace/comments/{id}` — للمؤلِّف نفسه (بأي اسم كتب)، أو لمالك/مسؤول منشأته، أو للمنصة؛
  لا لزميل في منشأة أخرى (`COMMENT_NOT_DELETABLE`/٤٠٣). السطر يبقى بـ`status: removed` ولا يختفي: خيط يعيد
  كتابة نفسه بصمت هو ما يقوم عليه نزاع لاحق.
- `POST /api/v1/marketplace/comments/{id}/report` — `comments.create`: `reason` ∈
  `spam|off_topic|abusive|contact_info|misleading|other` و`note` حتى ٥٠٠. بلاغك الثاني للتعليق نفسه يحدّث
  سبب أول بلاغ بدلاً من أن يضاعف `report_count`، فلا يتحوّل الإلحاح إلى وزن. والقيد `(comment, user)` للفرد:
  منشأة البلاغ توثيق إن وُجدت، لا شرط.

### 4.14 صندوق مراقبة المجتمع (Platform Community Moderation) — `community.moderate` + `feature:community`
لا تحملها أي دور تجاري مبذول: قراءة بلاغات منشآت أخرى فعلٌ يخص المنصة، كمراقبة معروضاتها.
- `GET /api/v1/community/summary` — عدّادات من نفس الجداول التي تقرأها الطوابير، فلا تسبق وسيلةٌ عملها:
  `pending_offers`, `pending_comments`, `reported_comments`, `open_reports`, `pending_documents`,
  `pending_organizations`.
- `GET /api/v1/community/comments?status=pending|published|removed|reported&perPage=1..100` (افتراضي
  `pending`، و٢٥ في الصفحة) — قائمة المراجعة، وكل عنصر مع `offer` (عنوانه وحالته و`seller_org_id`
  و`seller_name_ar` و`seller_cr` و`seller_vat` و`seller_status`) و`organization` و`reports[]` بأكملها،
  وقرار آخر مراجِع (`moderator_name`, `moderation_reason`, `moderated_at`). ونظرة `reported` هي منشورةٌ
  عليها بلاغات، وليست حالةً في القاعدة.
- `PUT /api/v1/community/comments/{id}/moderate` — `action` ∈ `publish|remove|pending`، و`reason` إلزامي عند
  `remove` ومن نفس معجم الأسباب.
- `GET /api/v1/community/reports?status=open|upheld|dismissed` (افتراضي `open`) و
  `POST /api/v1/community/reports/{id}/review` — `action` ∈ `upheld|dismissed`، و`reason` إلزامي عند القبول،
  و`remove_comment` (boolean) **منفصل عنه**: قبول البلاغ دون تعليمه يترك التعليق منشوراً، وهذا فرق يجب أن
  تعرضه الواجهة ولا تخبئه. الاستجابة تحمل `comment_status` ليعرف القارئ ما جرى فعلاً.

### 4.15 صندوق الإشعارات (Notifications) — `notifications.view`
- `GET /api/v1/notifications` (مقسّم، `per_page` حتى ٥٠، افتراضي ٢٠) و`GET /api/v1/notifications/unread-count`
  و`POST /api/v1/notifications/{id}/read` و`POST /api/v1/notifications/read-all`.
- الصندوق مصفّىً إلى المستخدم نفسه داخل المتحكم، والحارس على المجموعة هو ما يجعل `notifications.view`
  قدرة يفحصها الخادم لا صفة يُدّعى بها. وهي مدرجة في `rbac.person_capabilities`، فحساب بلا منشأة
  يقرأ صندوقه باسمه وحده: الصفوف موجَّهة إلى الحساب لا إلى شركة.

---

### 4.16 مسارات لا توجد (حتى لا يبني أحد عميلاً عليها)
هذه كانت مكتوبة في نسخ قديمة من هذه الوثيقة ولم تُنفَّذ قط، بل حُذف بعضها بعد مراجعة الدورة التجارية:

| المسار المذكور قديماً | الواقع |
| :--- | :--- |
| `POST /api/v1/marketplace/reservations` | **محذوف عمداً.** الحجز من قبول البائع فقط؛ النداء الآن `405`. |
| `POST /api/v1/purchase-requests` بسلوك «حجز فوري» | الطلب يُسجَّل ولا يحجز؛ الحجز في `/{id}/accept`. |
| `POST /api/v1/purchase-requests/{id}/accept` «ينشئ صفقة» | ينشئ **حجزاً**؛ الصفقة `POST /api/v1/deals`. |
| `GET/POST /api/v1/offers*` | البادئة الصحيحة `/api/v1/marketplace/offers*`. |
| `POST /api/v1/offers/{id}/publish` و`/pause` | `POST …/submit` ثم `PUT …/status` بحالة هدف. |
| `POST /api/v1/deals/{id}/confirm-received` | `POST /api/v1/deals/{id}/transition` بـ `status: DELIVERED`/`INSPECTION`. |
| `POST /api/v1/deals/{id}/payments` و`POST /api/v1/payments/{id}/verify` | لا مسار مدفوعات: المنصة لا تحتفظ بأموال ولا تتصل بمزوّد. |
| `POST /api/v1/deals/{id}/disputes` و`/disputes/{id}/evidence` و`/arbitrate` | `POST /api/v1/disputes` و`POST /api/v1/disputes/{id}/resolve`. |
| `GET /api/v1/ledger/statement` | `trial-balance` و`journal-entries`؛ وكان الصف يذكر `reconciliation/audit` كبديل — وهو توهُّم ثانٍ حُذف، لا مسار ولا بديل (§4.9). |
| `POST /api/v1/organizations/{id}/verify-request` | `PUT /api/v1/organizations/{id}/verification`. |
| `/api/v1/admin/**` (verifications, settings, feature-flags, analytics, audit-logs) | لا بادئة `/admin`؛ الأدوار عبر `role:SuperAdmin` و`settings.manage` و`audit.view` و`users.manage`. |
| `GET /api/v1/admin/analytics/overview` | غير منفَّذ؛ `reports/sales-summary` ما هو موجود. |
| `GET /api/v1/catalog/categories` و`/catalog/brands` | `/api/v1/marketplace/categories` و`/api/v1/marketplace/brands`. |

---

### 4.17 أجهزة الحساب وفتح القفل البيومتري (Sign-in Devices)
الحقل الاختياري نفسه تقبله ثلاث بوابات تُنشئ جلسة: `POST /auth/login` و`/auth/register` و`/auth/google` —
`device_key` (حتى ١٢٠ حرفاً، `[A-Za-z0-9._:-]`، يولّده الطرف المولِج ويُثبّته) و`device_name` و
`device_platform` (`android|ios|web`) و`biometric_unlock` (منطوق). كلها اختياري: عميلٌ لا يرسل مفتاحاً
يجلس ويعمل ولا يظهر في القائمة، وهو سلوكها قبل هذا البند لا انحدارٌ فيه. `device_key` ليس سرّاً ولا
credential؛ هو تسميةٌ تُصفّى داخل الحساب (`unique(user_id, device_key)`)، وكل مسار أدناه خلف
`auth:sanctum`.

- `GET /api/v1/auth/devices` — قائمة هذا الحساب، مرتّبة بالأحدث مشاهدةً. العنصر: `id` و`name` و`platform`
  و`biometric_unlock` و`current` و`last_seen_at` و`created_at`. `current` محسوبةٌ من الطلب لا مخزّنة: هي
  جواب «هل هذا هو الجهاز الذي يسأل؟»، وتحتاجها الواجهة قبل أن تعرض إلغاءً يُغلق الجلسة التي تُقرأ منها
  القائمة. القراءة تُصلح أيضاً الصفوف التي يشير `token_id` فيها إلى توكن لم يعد موجوداً (انتهت صلاحيته،
  أو غيّر أحدهم كلمة المرور فألغى البقية) — فلا تعرض القائمة جلسةً حيّة وهي ميتة، ولا تُلغي ما لا وجود له.
- `POST /api/v1/auth/devices` — يُسجّل الجهاز الداعي أو يحدّثه، ويُجيب `201` بصيغته مع `current: true`.
  سببه: الهاتف يكتشف عن نفسه أمراً لم تعرفه بوابة الدخول — فعّل الشخص البصمة بعده، أو بدّل اسمه.
  مفتاح غير معروف يُنشأ تحت `user_id` التوكن، ولا يُفتح به باب.
- `PATCH /api/v1/auth/devices/{id}` — تسميتان يقرّرهما صاحبه فقط: `name` و`biometric_unlock`. لا واحدة
  منهما تغيّر ما يستطيع التوكن فعله.
- `DELETE /api/v1/auth/devices/{id}` — يحذف الصف **و** توكن Sanctum الذي كان يحمله في الطلب نفسه، لأن
  نصف العملية هو الخطأ: حذفٌ يُبقي credential حيّاً يعرض للقائمة جهازاً أقل وبقي عدد الجلسات كما كان.
  إلغاء الجهاز الداعي يوقّع صاحبه خارج الجلسة عند أول طلب بعدها (الجواب نفسه ينجح لأن المطلوب وقع).
- `{id}` غريب عن الحساب → `404`، لا `403`: لا تُخبر أحداً أن جهازاً برقمٍ ما موجود عند غيره.

**لا يوجد مسار يقبل بصمة.** البصمة أو الوجه يفتحان *التوكن المخزّن على الجهاز*، عند الجهاز؛ وما يصل إلى
الخادم بعدها هو توكن Sanctum نفسه الذي كان typed password سيُخرجه. `biometric_unlock` إذن حقيقةٌ
يعرضها الحساب ويلغي جهازها، لا صلاحية. لا تُخزَّن هنا صورة إصبع ولا قوالب بيومترية ولا أي رمز مرور:
`flutter_secure_storage` على الجهاز هو مكان التوكن، وهذا الجدول مكان التسمية.

### 4.18 تفضيلات الحساب (Account Preferences)
أحد عشر مفتاحاً يقرّرها الشخص لنفسه، لا للمنصة. `SettingCatalog` هو ما تقرّره المنصة للجميع؛ هذا ما يقرّره
حسابٌ واحد، ولهذا يُخزَّن هنا لا في `SharedPreferences` عند كل عميل: التفضيل يجب أن ينتقل مع الحساب من
الهاتف إلى المتصفّح، ومن يخبئه في جهازٍ واحد يرى اسم منشأته على الجهاز الذي أخفاه منه.

- `GET /api/v1/auth/preferences` — يجيب `{preferences, catalog}`. القيم معبّأة من الافتراضات المجمّعة
  (لا `null` لمفتاحٍ لم يلمسه صاحبه)، و`catalog` هو القائمة نفسها بمفاتيحها وأنواعها ومجموعاتها
  وتسميتيها `ar|en`. تُرسل مع القيم حتى تبني الواجهة نموذجها من قائمة الخادم لا من مرآةٍ مكتوبة بيدها،
  ففشل المرآة هو مفتاحٌ يعرضه التطبيق وقد تقاعد من المنصة.
- `PUT /api/v1/auth/preferences` — يحمل `idempotency` كما تحمل كل كتابةٍ تجارية. الجسم
  `{"preferences": {…}}`، وهو **ترقيع لا استبدال**: عميلٌ يعرف مفتاحين لا يمسح السبعة الباقية بإغفالها.
  المُجيب هو الخريطة الكاملة بعد الدمج، مع `ignored` (قائمة أسماء) حين أرسل طرفٌ مفتاحاً لا تعرفه
  المنصة — يُهمل ويُسمَّى، لا يُخزَّن؛ وهذا ما يجعل عمود JSON خريطةَ تفضيلات لا صندوق مهملاتٍ لشيءٍ
  قرّرت شاشةٌ أن تحفظه.
- `GET /api/v1/auth/preferences/catalog` — القائمة وحدها، بلا قراءة صفّ.

المفاتيح الأحد عشر: `hide_organization_name` (مجموعة `identity`)، `hide_seller_name` و`compact_lists` و
`theme` و`language` (`display`)، `notifications_enabled` و`notify_deals` و`notify_moderation` و
`notify_demands` و`notify_support` (`notifications`)، `offline_browsing` (`data`). كلُّها `boolean` ما عدا `theme`
(`system|light|dark`) و`language` (`ar|en`). لا يوجد مسار «يحذف» تفضيلاً: الافتراض هو السلوك الذي كان
قبل المفتاح، فلا يغيّر جدولُ التفضيلات ما يراه حسابٌ قديم في اليوم الذي أُدخل فيه.

لا صلاحية على هذه المسارات الثلاثة، وهذا مقصود: `GET` يقرأ صفّ الداعي و`PUT` يكتبه، والنطاق هو
`$request->user()` لا `id` في المسار — مسارٌ يقبل رقم مستخدمٍ هنا هو ثغرة انتحالٍ تنقصها `abort_if`
واحدة. `preferences` جزءٌ من `GET /auth/me` أيضاً، لأن العميل الذي يقرأ جلسته يجب أن يعرف كيف يرسمها
قبل أن يطلب مساره الخاص.

---

### 4.19 الدعم الفني: البلاغ عن مشاكل المنصّة (Support Desk)
سطحٌ مستقلّ عن §4.13 و§4.14: بلاغُ تعليقٍ هناك هو منشأةٌ تشتكي على كلام منشأةٍ أخرى، ونزاعُ §4.8 اختلافُ
شركتين على صفقة؛ هذا بابٌ يشتكي فيه من كان داخل الجلسة على **المنصّة نفسها** («شاشة الرفّ تُضيع سعري»).
لم يكن له مسار قبل هذه الجولة، فكانت الإجابة الوحيدة المتاحة بريداً خارج العقد لا يُعرف منه هل قُرئ.

`notify_support` مفتاحٌ مستقلّ في التفضيلات لأن جواباً عن بلاغٍ في المنصّة ليس تنبيهاً عن صفقة: من كتم
حركة صفقاته لا يزال ينتظر أن يُقال له إنّ العطل الذي أبلغ عنه قد صحّ.

- `POST /api/v1/support/tickets` — بجلسةٍ فقط؛ **لا صلاحية** ولا `verified_tenant`، والمقصودُ بالضبط هو
  الحساب الذي تعثّر ورقُه: منعه من الباب هو إسكاتُ الشكوى. خلف `throttle:10,1`. بلا `idempotency` عن
  قصد: رفضُ النصّ نفسه من الشخص نفسه في عشر دقائق يغطّي إعادة الإرسال وإعادة اللمسة معاً، أمّا مفتاحٌ
  يُطلب من كل عميل فيغطّي واحدهما فقط.
  - **الجسم:** `category` من `FUNCTIONAL_BUG | WRONG_DATA | ACCOUNT_ACCESS | LISTING_TRADE | SUGGESTION | OTHER`،
    و`message` (١٥–٤٠٠٠ حرفاً)، و`screen` اختياري وهو اسم المسار لا نصٌّ حرّ (`[\w\-/.]+`، إلى ٨٠) لأن
    الطابور يُصفّى به فيصير «شاشةُ السعر» و«الرفّ» سطراً واحداً، و`context` اختياري.
  - **`context` قائمةٌ مغلقة لا صندوق مهملات:** تُبقي المنصّة خمسة مفاتيح وتُسقِط ما عداها —`platform` و
    `app_version` و`locale` و`screen_label` و`device_name` — لأن الوكيل يقرأها حقيقةً عن البرنامج، والحقل
    الذي يختاره العميل هو توكنٌ في جيبٍ مُسمّى `build`.
  - **الاستجابة `201`:** البلاغ كاملٌ بـ `reference` بصيغة `SUP-00042`، مُولَّدٌ من رقم الصف داخل المعاملة
    نفسها فليس للعميل أن يعدّه ولا أن يُخمّنه، وبحالٍ افتتاحية `open`.
  - `DUPLICATE_REPORT` (٤٠٩) مع `error.details.reference` هو جواب التكرار، لا `201` ثانية: طابورٌ يَعُدّ
    اللمسات المزدوجة يَعُدّ شيئاً لم يقع.
- `GET /api/v1/support/tickets` — بلاغات **الداعي** وحدها (`user_id` لا `organization_id`) بالأحدث، صفحة
  قياسية. البلاغ ينتقل مع صاحبه بين المنشآت، فليس لشركةٍ أن تقرأ ما يشتكي به شريكُها السابق.
- الطابور والردّ خلف `support.manage` — لا تحملها عضويةٌ في منشأة، و`RbacMatrixTest` يثبّت ذلك:
  - `GET /api/v1/support/queue` — كل البلاغات عبر كل المبلِّغين بالأقدم (ما انتظر أطول يُقرأ أولاً)، يقبل
    `status` و`category` و`page`/`per_page` (إلى ١٠٠). صفّ الطابور يحمل اسم المبلِّغ ومنشأته لأن من يُجيب
    يحتاج أن يعرف إلى من يردّ؛ ولا يظهر ذلك في قائمة صاحبه.
  - `GET /api/v1/support/summary` — عدّادٌ لكل حالة، و`awaiting_attention`، و`oldest_open_minutes`
    محسوبةٌ من الطلب لا معلَنةٌ في الكود: «الطابور متراكم» يجب أن يبقى رقماً يُختلف عليه.
  - `PUT /api/v1/support/tickets/{id}/answer` — مع `idempotency`. `status` من `open | in_review | resolved |
    dismissed` و`reply` اختياري (١٠–٤٠٠٠). `in_review` مطالبةٌ بالعمل لا جوابٌ: هو الذي يمنع اثنين من
    معالجة البلاغ نفسه. أمّا `resolved` و`dismissed` فتطلبان `reply` وإلا `REPLY_REQUIRED`، لأن إغلاقاً لا
    يقرأ صاحبه شيئاً إخفاءٌ لا معالجة. عند الإجابة يوسم `answered_by` و`answered_at` ويصل المبلِّغ تنبيهٌ
    `type: SUPPORT` و`data: {support_ticket_id, reference}`.
  - `answered_by` (اسم الموظف) في صفحات الطابور فقط؛ قائمة صاحب البلاغ ترى الردّ وتاريخه بلا اسم كاتبه.
    كذلك صفّ الطابور وحده يحمل `filed_by` (اسم المبلِّغ) و`organization_name` (منشأته عند البلاغ)؛ في
    قائمة الداعي يُرجع الحقلان `null` لا يُحذفان، حتى يبقى شكلُ العنصر واحداً لعميلٍ يقرأ القائين معاً.
    لماذا لا تظهر هويّتان في الطابور فقط؟ لأن من يردّ يحتاج أن يعرف إلى من يردّ، ولم تطلب منصّةٌ قطّ أن
    تعرف مَن يشتكي على مَن.
- **لا مرفقات، ولا محادثة.** عمودُ صورةٍ أو ملفٍ يَعِدُ بخزينةٍ لا سقف لها ولا فحص قبل العرض؛ حتى توجد
  تلك الطبقة يصفُ المبلِّغ ما يرى في `message`. وبلاغٌ واحد بخيطٍ واحد بقرارٍ واحد: لا «تتبع» ولا رسائل.
- `{id}` غريب عن الحساب → لا مسار قراءةٍ فردي أصلاً: قائمةُ الداعي تُجيب ما يخصّه وطابورُ المنصّة تُجيب
  ما يخصّها، ومسارٌ يقبل رقماً من الخارج هو بابٌ لسؤال «هل هذا البلاغ لغيري؟».

