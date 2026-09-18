# منصة حصّال (HASSAL) — وثيقة مواصفات الـ API للمطورين وتطبيقات الهاتف (API_CONTRACT.md)

## 1. المعايير العامة للاتصال (General Standards)

- **الرابط الأساسي (Base URL):** `/api/v1`
- **التوثيق (Authentication):** `Authorization: Bearer <Sanctum-Token>`
- **سياق المؤسسة (Organization Context):** `X-Organization-Id: <ID>` (مطلوب للعمليات التجارية).
- **منع التكرار (Idempotency):** `X-Idempotency-Key: <UUIDv4>` (مطلوب للطلبات والمدفوعات).
- **اللغة والتعريب (Localization):** `Accept-Language: ar` أو `en` (الافتراضي: `ar`).
- **صيغة البيانات:** `Content-Type: application/json` و `Accept: application/json`.

---

## 2. الهيكل الموحد للاستجابات (Standard Response Envelope)

### 2.1 الاستجابة الناجحة (Success Response)
```json
{
  "success": true,
  "message": "تمت العملية بنجاح",
  "data": {},
  "meta": {
    "timestamp": "2026-09-15T12:00:00Z"
  }
}
```

### 2.2 الاستجابة المقسمة لصفحات (Paginated Response)
```json
{
  "success": true,
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 150,
    "last_page": 8
  },
  "links": {
    "first": "/api/v1/offers?page=1",
    "last": "/api/v1/offers?page=8",
    "prev": null,
    "next": "/api/v1/offers?page=2"
  }
}
```

### 2.3 استجابة الخطأ الموحدة (Error Response)
```json
{
  "success": false,
  "message": "الكمية المطلوبة غير متوفرة في المخزون الحالي",
  "error_code": "INSUFFICIENT_QUANTITY",
  "errors": {
    "quantity": ["الكمية المتاحة حالياً هي 35 كرتون فقط"]
  }
}
```

---

## 3. معجم رموز الأخطاء البرمجية (Error Codes Dictionary)

| رمز الخطأ (Error Code) | كود HTTP | المعنى البرمجي لتطبيقات Flutter والواجهات |
| :--- | :---: | :--- |
| `UNAUTHENTICATED` | 401 | المستخدم غير مسجل دخول أو انتهت صلاحية الجلسة. |
| `FORBIDDEN_ACTION` | 403 | المستخدم لا يملك الصلاحية اللازمة للعملية. |
| `ORGANIZATION_CONTEXT_REQUIRED` | 400 | يلزم تمرير رأس `X-Organization-Id` للعمليات التجارية. |
| `ORGANIZATION_SUSPENDED` | 403 | حساب المنشأة معلق إدارياً لمخالفة الشروط. |
| `ORGANIZATION_NOT_VERIFIED` | 403 | العملية تشترط توثيق السجل التجاري أولاً. |
| `OFFER_NOT_ACTIVE` | 422 | العرض التجاري غير متاح للطلب أو انتهت مدته أو بيع بالكامل. |
| `INSUFFICIENT_QUANTITY` | 422 | الكمية المتاحة في المستودع أقل من الكمية المطلوبة. |
| `BELOW_MIN_ORDER_QTY` | 422 | الكمية أقل من الحد الأدنى للطلب (MOQ). |
| `ABOVE_MAX_ORDER_QTY` | 422 | الكمية تتجاوز الحد الأقصى المسموح به للطلب الواحد. |
| `RESERVATION_EXPIRED` | 410 | انتهت نافذة حجز المخزون وتم تحرير الكمية لباقي المشترين. |
| `DEAL_INVALID_STATE_TRANSITION` | 409 | محاولة نقل الصفقة لحالة غير مسموح بها في دورة الحياة. |
| `PAYMENT_ALREADY_CONFIRMED` | 409 | تم سداد قيمة الصفقة مسبقاً ولا يمكن تكرار الدفع. |
| `DUPLICATE_IDEMPOTENT_REQUEST` | 409 | العملية قيد التنفيذ حالياً بنفس مفتاح `X-Idempotency-Key`. |
| `DISPUTE_WINDOW_EXPIRED` | 422 | انقضت مهلة فحص البضاعة المحددة ولا يمكن فتح نزاع جديد. |
| `DISPUTE_ALREADY_OPEN` | 409 | يوجد نزاع مفتوح وقائم بالفعل على هذه الصفقة. |

---

## 4. مسارات النظام ونقاط النهاية (API Endpoints Catalog)

### 4.1 التهيئة وصحة النظام (Bootstrap & Health)
- `GET /api/v1/bootstrap`
  - **الوصف:** نقطة الدخول الأولى لتطبيق Flutter عند الإقلاع لتحميل الإعدادات، اللغات، العملات، ورايات الميزات.
  - **الصلاحية:** عام (Public).
  - **الاستجابة:**
    ```json
    {
      "success": true,
      "data": {
        "app_name": "حصّال — HASSAL",
        "supported_locales": ["ar", "en"],
        "default_locale": "ar",
        "supported_currencies": ["SAR", "YER", "USD", "AED"],
        "default_currency": "SAR",
        "vat_rate": 0.15,
        "reservation_timeout_minutes": 30,
        "feature_flags": {
          "nearby_offers": true,
          "demands": true,
          "online_payment": false,
          "ai_pricing": false
        },
        "categories": []
      }
    }
    ```
- `GET /api/v1/health`
  - **الوصف:** فحص حيوي لحالة الخوادم والخدمات (Database, Cache, Queue).

---

### 4.2 المصادقة والحساب الشخصي (Authentication)
- `POST /api/v1/auth/register` (تسجيل مستخدم جديد برقم الهاتف/البريد)
- `POST /api/v1/auth/login` (تسجيل الدخول وإصدار Bearer Token وقائمة المنشآت التابع لها)
- `POST /api/v1/auth/logout` (إلغاء التوكن الحالي)
- `GET /api/v1/auth/me` (بيانات المستخدم الحالي والمنشأة النشطة وأدواره)
- `POST /api/v1/auth/switch-organization` (تغيير المنشأة النشطة واستلام سياق الصلاحيات)

---

### 4.3 المنشآت والمستودعات (Organizations & Warehouses)
- `POST /api/v1/organizations` (تسجيل منشأة تجارية جديدة وربط السجل التجاري)
- `GET /api/v1/organizations/{id}` (بيانات المنشأة العامة والتقييم والتوثيق)
- `POST /api/v1/organizations/{id}/verify-request` (رفع مستندات السجل التجاري والتراخيص)
- `GET /api/v1/organizations/{id}/members` (قائمة أعضاء المنشأة وأدوارهم)
- `POST /api/v1/organizations/{id}/members` (دعوة عضو جديد وتعيين دوره: Buyer, Manager, Warehouse)
- `GET /api/v1/warehouses` (استعراض مستودعات المنشأة)
- `POST /api/v1/warehouses` (إضافة مستودع جديد مع الإحداثيات الجغرافية بدقة)

---

### 4.4 دليل المنتجات (Catalog)
- `GET /api/v1/catalog/categories` (شجرة التصنيفات الغذائية)
- `GET /api/v1/catalog/brands` (العلامات التجارية المعتمدة)
- `GET /api/v1/catalog/products` (استعراض المنتجات مع الفلترة والبحث بالاسم والماركة)
- `GET /api/v1/catalog/products/{id}/variants` (استعراض المتغيرات والأحجام والباركود لكل صنف)

---

### 4.5 السوق والعروض التجارية (Offers & Geo Marketplace)
- `GET /api/v1/offers`
  - **المعلمات:** `category_id`, `brand_id`, `reason`, `min_price`, `max_price`, `city`, `sort_by` (cheapest, newest, expiring_soon), `page`, `per_page`.
- `GET /api/v1/offers/nearby`
  - **المعلمات:** `latitude`, `longitude`, `radius_km` (افتراضي 50 كم، حد أقصى 250 كم).
  - **الاستجابة:** تعيد العروض النشطة مرتبة تصاعدياً حسب المسافة بالكيلومتر (`distance_km`) مع اسم المستودع والمدينة.
- `GET /api/v1/offers/{id}` (تفاصيل العرض الكاملة، الكميات، الشروط، والصور)
- `POST /api/v1/offers` (إنشاء مسودة عرض تجاري من المورد)
- `PUT /api/v1/offers/{id}` (تعديل كميات أو شروط العرض)
- `POST /api/v1/offers/{id}/publish` (نشر العرض في السوق وتحويله إلى `ACTIVE`)
- `POST /api/v1/offers/{id}/pause` (إيقاف مؤقت للعرض)

---

### 4.6 طلبات الشراء وحجز المخزون (Purchase Requests & Atomic Reservation)
- `POST /api/v1/purchase-requests`
  - **الرؤوس:** `X-Organization-Id`, `X-Idempotency-Key`
  - **جسم الطلب:**
    ```json
    {
      "offer_id": 12,
      "quantity": 100,
      "delivery_preference": "pickup",
      "notes": "نحتاج الاستلام صباح يوم الأحد"
    }
    ```
  - **السلوك:** قفل تشاؤمي فوري للمخزون (`lockForUpdate`)، اقتطاع 100 وحدة من `available_quantity` وإضافتها إلى `reserved_quantity` لمدة 30 دقيقة.
- `GET /api/v1/purchase-requests` (استعراض طلبات الشراء الصادرة والواردة)
- `POST /api/v1/purchase-requests/{id}/accept` (قبول البائع للطلب وإنشاء صفقة تجارية `Deal`)
- `POST /api/v1/purchase-requests/{id}/reject` (رفض البائع للطلب وإعادة الكمية المحجوزة فوراً للمخزون المتاح)

---

### 4.7 الصفقات وإدارة الحالات (Deals & Operations)
- `GET /api/v1/deals` (قائمة صفقات المنشأة - بيع وشراء)
- `GET /api/v1/deals/{id}` (تفاصيل الصفقة، لقطة الأسعار، الضرائب، وحالة التسليم)
- `POST /api/v1/deals/{id}/transition`
  - **جسم الطلب:**
    ```json
    {
      "target_status": "preparing",
      "notes": "تم استلام الدفعة والبدء برص البضاعة في مستودع السلي"
    }
    ```
- `POST /api/v1/deals/{id}/confirm-received` (تأكيد المشتري استلام وفحص البضاعة وبدء التسوية)

---

### 4.8 المدفوعات والتحويلات (Payments)
- `POST /api/v1/deals/{id}/payments`
  - **الرؤوس:** `X-Idempotency-Key`
  - **جسم الطلب:**
    ```json
    {
      "provider": "bank_transfer",
      "amount": 11500.00,
      "external_reference": "TRX-ALRAJHI-998811",
      "receipt_document_url": "https://storage.hassal.app/receipts/rec_9988.pdf"
    }
    ```
- `POST /api/v1/payments/{id}/verify` (اعتماد مسؤول المالية للسند وتحويل الصفقة لـ `PAID`)

---

### 4.9 النزاعات والتحكيم (Disputes)
- `POST /api/v1/deals/{id}/disputes` (فتح نزاع رسمي على الصفقة وتجميد المبالغ المالية)
- `POST /api/v1/disputes/{id}/evidence` (رفع صور الفحص، تقرير الهيئة، أو بوالص الشحن)
- `POST /api/v1/disputes/{id}/arbitrate` (صلاحية الإدارة: قرار استرداد أو رفض مع تدوين أسباب الحكم)

---

### 4.10 طلبات الاحتياج التجاري (B2B RFQ Demands)
- `GET /api/v1/demands` (تصفح طلبات الاحتياج المفتوحة في السوق)
- `POST /api/v1/demands` (نشر المشتري لطلب احتياج بكمية وسعر مستهدف)

---

### 4.11 دفتر الأستاذ والمحفظة (Ledger Accounts)
- `GET /api/v1/ledger/statement` (كشف الحساب المالي للمنشأة: الأمانات المحتجزة، العمولات، الأرصدة المتاحة للتحويل)

---

### 4.12 لوحة الإدارة والتحكم (Admin System Endpoints)
- `GET /api/v1/admin/organizations/verifications` (طلبات السجلات التجارية المعلقة للمراجعة)
- `POST /api/v1/admin/organizations/{id}/verify-decision` (قبول أو رفض توثيق المنشأة)
- `GET /api/v1/admin/settings` و `PUT /api/v1/admin/settings` (تعديل نسب الضرائب والعمولات ومهل الحجوزات)
- `PUT /api/v1/admin/feature-flags/{name}` (تفعيل/تعطيل الميزات برمجياً)
- `GET /api/v1/admin/analytics/overview` (مؤشرات GMV، حجم التداول، ونسبة النزاعات)
- `GET /api/v1/admin/audit-logs` (سجلات التدقيق الأمني والعمليات الحساسة)
