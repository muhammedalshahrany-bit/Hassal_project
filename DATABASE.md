# منصة حصّال (HASSAL) — تصميم قاعدة البيانات والمخطط العلائقي (DATABASE.md)

## 1. المعايير العامة للتصميم (Database Standards)

- **سياسة المعرفات الأساسية (Primary Keys):** اعتماد `BIGINT UNSIGNED AUTO_INCREMENT` لكافة المعرفات الأساسية (`id`) لضمان الكفاءة القصوى، السرعة، والتوافق العالي مع محركات الفهرسة B-Tree في MySQL 8+ وPostgreSQL.
- **الحسابات المالية والكميات (Precision & Decimals):**
  - المبالغ المالية: `DECIMAL(14, 2)` (يدعم حتى 999 مليار بوحدات العملة وسنتين عشريين).
  - أسعار الصرف ونسب العمولات والضرائب: `DECIMAL(10, 4)` (دقة 4 خانات عشرية).
  - الكميات والأوزان: `DECIMAL(12, 3)` (دقة 3 خانات لدعم الأوزان والكسور مثل الكيلوجرام والطن).
  - الإحداثيات الجغرافية: `DECIMAL(10, 7)` لخطوط الطول والعرض (دقة مليمترية).
- **الترميز ومحرك التخزين:** `utf8mb4` مع `utf8mb4_unicode_ci` ومحرك `InnoDB` لضمان الدعم الكامل للغة العربية والرموز التعبيرية والمعاملات المتوافقة مع معايير ACID.
- **الحذف المرن (Soft Deletes):** مطبق على الكيانات الهامة (`organizations`, `products`, `offers`, `deals`, `warehouses`) عبر حقل `deleted_at TIMESTAMP NULL`.

---

## 2. هيكل الجداول والمخطط العلائقي (Schema Specification)

### 2.1 قطاع الهوية وتعدد المؤسسات (Identity & Tenancy)

#### 1. `users`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف المستخدم الفريد |
| `name` | VARCHAR(191) | NOT NULL | اسم المستخدم الكامل |
| `email` | VARCHAR(191) | UNIQUE, NULLABLE | البريد الإلكتروني |
| `phone` | VARCHAR(32) | UNIQUE, NOT NULL | رقم الهاتف الدولي (معتمد للمصادقة) |
| `password` | VARCHAR(255) | NOT NULL | كلمة المرور المشفرة بـ Bcrypt/Argon2id |
| `avatar_url` | VARCHAR(255) | NULLABLE | رابط الصورة الشخصية |
| `status` | ENUM('active','inactive','suspended') | DEFAULT 'active' | حالة الحساب |
| `email_verified_at` | TIMESTAMP | NULLABLE | توقيت تأكيد البريد |
| `phone_verified_at` | TIMESTAMP | NULLABLE | توقيت تأكيد الهاتف |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات القياسية |

#### 2. `organizations`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف المؤسسة |
| `name` | VARCHAR(191) | NOT NULL | الاسم التجاري للمؤسسة |
| `legal_name` | VARCHAR(191) | NOT NULL | الاسم القانوني في السجل التجاري |
| `cr_number` | VARCHAR(64) | UNIQUE, NOT NULL | رقم السجل التجاري |
| `tax_number` | VARCHAR(64) | UNIQUE, NULLABLE | الرقم الضريبي للمنشأة |
| `logo_url` | VARCHAR(255) | NULLABLE | رابط شعار المنشأة |
| `business_type` | ENUM('distributor','wholesaler','retailer','manufacturer','other') | NOT NULL | نوع النشاط التجاري |
| `verification_status`| ENUM('unverified','pending','verified','rejected','suspended') | DEFAULT 'unverified' | حالة توثيق المنشأة |
| `verified_at` | TIMESTAMP | NULLABLE | تاريخ اعتماد التوثيق |
| `rejection_reason` | TEXT | NULLABLE | سبب رفض التوثيق |
| `created_at`, `updated_at`, `deleted_at` | TIMESTAMP | NULLABLE | توقيتات التعديل والحذف المرن |
*فهارس:* `INDEX (verification_status)`, `INDEX (business_type)`.

#### 3. `organization_memberships`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف العضوية |
| `user_id` | BIGINT UNSIGNED | FK -> users(id) ON DELETE CASCADE | المستخدم |
| `organization_id` | BIGINT UNSIGNED | FK -> organizations(id) ON DELETE CASCADE | المؤسسة |
| `role` | ENUM('owner','manager','buyer','warehouse_officer','finance') | NOT NULL | الدور داخل المؤسسة |
| `status` | ENUM('active','inactive','suspended') | DEFAULT 'active' | حالة العضوية |
| `joined_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | تاريخ الانضمام |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات |
*قيود فريدة:* `UNIQUE (user_id, organization_id)`.

---

### 2.2 قطاع المستودعات والمواقع الجغرافية (Warehouses & Locations)

#### 4. `warehouses`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف المستودع |
| `organization_id` | BIGINT UNSIGNED | FK -> organizations(id) | المؤسسة المالكة |
| `name` | VARCHAR(191) | NOT NULL | اسم المستودع (مثال: مستودع السلي المركزي) |
| `city` | VARCHAR(100) | NOT NULL | المدينة |
| `district` | VARCHAR(100) | NULLABLE | الحي |
| `address_line` | TEXT | NOT NULL | العنوان التفصيلي |
| `latitude` | DECIMAL(10, 7) | NOT NULL | خط العرض |
| `longitude` | DECIMAL(10, 7) | NOT NULL | خط الطول |
| `contact_name` | VARCHAR(100) | NOT NULL | اسم مسؤول المستودع |
| `contact_phone` | VARCHAR(32) | NOT NULL | هاتف مسؤول المستودع |
| `is_active` | BOOLEAN | DEFAULT TRUE | هل المستودع نشط |
| `created_at`, `updated_at`, `deleted_at` | TIMESTAMP | NULLABLE | التوقيتات |
*فهارس:* `INDEX (organization_id)`, `INDEX (city)`, `INDEX (latitude, longitude)`.

---

### 2.3 قطاع الكتالوج والمنتجات (Catalog Domain)

#### 5. `categories`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف التصنيف |
| `parent_id` | BIGINT UNSIGNED | FK -> categories(id) NULLABLE | التصنيف الأب للتصنيفات الهرمية |
| `name_ar` | VARCHAR(100) | NOT NULL | الاسم بالعربية |
| `name_en` | VARCHAR(100) | NOT NULL | الاسم بالإنجليزية |
| `slug` | VARCHAR(120) | UNIQUE, NOT NULL | المعرف النصي للرابط |
| `image_url` | VARCHAR(255) | NULLABLE | صورة التصنيف |
| `sort_order` | INT | DEFAULT 0 | ترتيب العرض |
| `is_active` | BOOLEAN | DEFAULT TRUE | حالة التفعيل |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات |

#### 6. `brands`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف العلامة التجارية |
| `name_ar` | VARCHAR(100) | NOT NULL | اسم العلامة بالعربية |
| `name_en` | VARCHAR(100) | NOT NULL | اسم العلامة بالإنجليزية |
| `slug` | VARCHAR(120) | UNIQUE, NOT NULL | الرابط المميز |
| `logo_url` | VARCHAR(255) | NULLABLE | شعار الماركة |
| `is_active` | BOOLEAN | DEFAULT TRUE | حالة النشاط |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات |

#### 7. `units`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف وحدة القياس |
| `name_ar` | VARCHAR(50) | NOT NULL | كرتون، حبة، كيس، باليت، طن |
| `name_en` | VARCHAR(50) | NOT NULL | Carton, Piece, Bag, Pallet, Ton |
| `code` | VARCHAR(20) | UNIQUE, NOT NULL | رمز الوحدة القياسي (CTN, PCS, BAG, PLT) |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات |

#### 8. `products`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف المنتج الرئيسي |
| `category_id` | BIGINT UNSIGNED | FK -> categories(id) | التصنيف |
| `brand_id` | BIGINT UNSIGNED | FK -> brands(id) NULLABLE | العلامة التجارية |
| `name_ar` | VARCHAR(191) | NOT NULL | اسم المنتج بالعربية |
| `name_en` | VARCHAR(191) | NOT NULL | اسم المنتج بالإنجليزية |
| `description_ar`| TEXT | NULLABLE | وصف المنتج بالعربية |
| `description_en`| TEXT | NULLABLE | وصف المنتج بالإنجليزية |
| `is_active` | BOOLEAN | DEFAULT TRUE | حالة النشاط |
| `created_at`, `updated_at`, `deleted_at` | TIMESTAMP | NULLABLE | التوقيتات |
*فهارس:* `INDEX (category_id)`, `INDEX (brand_id)`, `FULLTEXT (name_ar, name_en)`.

#### 9. `product_variants`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف متغير المنتج |
| `product_id` | BIGINT UNSIGNED | FK -> products(id) ON DELETE CASCADE | المنتج الأب |
| `unit_id` | BIGINT UNSIGNED | FK -> units(id) | وحدة القياس والبيع |
| `sku` | VARCHAR(64) | UNIQUE, NOT NULL | كود المخزون القياسي |
| `barcode` | VARCHAR(64) | NULLABLE | الباركود التجاري العالمي |
| `variant_name_ar`| VARCHAR(100) | NOT NULL | كيس 10 كجم، كرتون 24×200 مل |
| `variant_name_en`| VARCHAR(100) | NOT NULL | 10 KG Bag, 24x200ml Carton |
| `weight_kg` | DECIMAL(8, 3) | NULLABLE | الوزن الصافي بالكيلوجرام |
| `packaging_spec`| VARCHAR(100) | NULLABLE | مواصفة التعبئة والتغليف |
| `image_url` | VARCHAR(255) | NULLABLE | الصورة الأساسية للمتغير |
| `gallery` | JSON | NULLABLE | مصفوفة صور إضافية |
| `is_active` | BOOLEAN | DEFAULT TRUE | حالة المتغير |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات |
*فهارس:* `INDEX (product_id)`, `INDEX (barcode)`, `INDEX (sku)`.

---

### 2.4 قطاع العروض التجارية والمخزون (Offers & Inventory)

#### 10. `offers`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف العرض التجاري |
| `seller_organization_id` | BIGINT UNSIGNED | FK -> organizations(id) | المؤسسة البائعة |
| `product_variant_id` | BIGINT UNSIGNED | FK -> product_variants(id) | المتغير المعروض |
| `warehouse_id` | BIGINT UNSIGNED | FK -> warehouses(id) | المستودع المتواجد به المخزون |
| `available_quantity` | DECIMAL(12, 3) | NOT NULL | الكمية المتاحة للبيع الفوري |
| `reserved_quantity` | DECIMAL(12, 3) | DEFAULT 0.000 | الكمية المحجوزة في طلبات معلقة |
| `sold_quantity` | DECIMAL(12, 3) | DEFAULT 0.000 | الكمية المكتمل بيعها |
| `unit_price` | DECIMAL(14, 2) | NOT NULL | سعر الوحدة للبيع |
| `market_reference_price` | DECIMAL(14, 2) | NULLABLE | السعر المرجعي في السوق للمقارنة |
| `currency` | CHAR(3) | DEFAULT 'SAR' | العملة (SAR, YER, USD, AED) |
| `min_order_quantity`| DECIMAL(12, 3) | DEFAULT 1.000 | الحد الأدنى للطلب (MOQ) |
| `max_order_quantity`| DECIMAL(12, 3) | NULLABLE | الحد الأقصى للطلب |
| `opportunity_reason`| ENUM('surplus_stock','slow_moving','overproduction','assortment_change','branch_closure','seasonal_inventory','commercial_liquidation','other') | NOT NULL | السبب التجاري للفرصة |
| `reason_notes` | TEXT | NULLABLE | تفاصيل تجارية إضافية |
| `production_date`| DATE | NULLABLE | تاريخ الإنتاج الفعلي |
| `expiry_date` | DATE | NULLABLE | تاريخ انتهاء الصلاحية |
| `delivery_terms` | ENUM('buyer_pickup','seller_delivery','platform_courier','both') | NOT NULL | شروط الشحن والاستلام |
| `status` | ENUM('draft','pending_review','active','paused','sold_out','expired','cancelled','archived') | DEFAULT 'draft' | حالة العرض |
| `published_at` | TIMESTAMP | NULLABLE | تاريخ وتوقيت النشر بالسوق |
| `expires_at` | TIMESTAMP | NULLABLE | موعد انتهاء سريان العرض |
| `created_at`, `updated_at`, `deleted_at` | TIMESTAMP | NULLABLE | التوقيتات |
*فهارس:*
- `INDEX (seller_organization_id)`
- `INDEX (product_variant_id)`
- `INDEX (warehouse_id)`
- `INDEX (status, expires_at)`
- `INDEX (currency, unit_price)`
- `INDEX (opportunity_reason)`

---

### 2.5 قطاع طلبات الشراء والصفقات (Purchasing & Deals)

#### 11. `purchase_requests`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف طلب الشراء |
| `offer_id` | BIGINT UNSIGNED | FK -> offers(id) | العرض المطلوب |
| `buyer_organization_id` | BIGINT UNSIGNED | FK -> organizations(id) | المؤسسة المشترية |
| `buyer_user_id` | BIGINT UNSIGNED | FK -> users(id) | مسؤول المشتريات الذي أنشأ الطلب |
| `quantity` | DECIMAL(12, 3) | NOT NULL | الكمية المطلوبة |
| `unit_price` | DECIMAL(14, 2) | NOT NULL | سعر الوحدة وقت الطلب |
| `total_price` | DECIMAL(14, 2) | NOT NULL | الإجمالي الأساسي |
| `currency` | CHAR(3) | NOT NULL | عملة الطلب |
| `delivery_preference`| ENUM('pickup','delivery') | NOT NULL | رغبة الاستلام |
| `shipping_address_id`| BIGINT UNSIGNED | NULLABLE | عنوان التسليم إذا وجد |
| `status` | ENUM('pending','accepted','rejected','expired','cancelled') | DEFAULT 'pending' | حالة الطلب |
| `reserved_until` | TIMESTAMP | NOT NULL | موعد نهاية صلاحية حجز المخزون |
| `rejection_reason` | TEXT | NULLABLE | سبب الرفض إن وجد |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات |
*فهارس:* `INDEX (offer_id)`, `INDEX (buyer_organization_id)`, `INDEX (status, reserved_until)`.

#### 12. `deals`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف الصفقة |
| `deal_number` | VARCHAR(32) | UNIQUE, NOT NULL | رقم الصفقة التسلسلي (مثال: DSL-2026-00045) |
| `purchase_request_id`| BIGINT UNSIGNED | FK -> purchase_requests(id) UNIQUE | طلب الشراء المرتبط |
| `seller_organization_id`| BIGINT UNSIGNED | FK -> organizations(id) | المؤسسة البائعة |
| `buyer_organization_id` | BIGINT UNSIGNED | FK -> organizations(id) | المؤسسة المشترية |
| `warehouse_id` | BIGINT UNSIGNED | FK -> warehouses(id) | مستودع الانطلاق والتجهيز |
| `currency` | CHAR(3) | NOT NULL | العملة المعتمدة للصفقة |
| `subtotal_amount` | DECIMAL(14, 2) | NOT NULL | المجموع الفرعي للبضاعة |
| `tax_rate` | DECIMAL(6, 4) | NOT NULL | نسبة الضريبة المجمدة (مثلاً 0.1500) |
| `tax_amount` | DECIMAL(14, 2) | NOT NULL | قيمة ضريبة القيمة المضافة |
| `commission_rate` | DECIMAL(6, 4) | NOT NULL | نسبة عمولة منصة حصّال المجمدة |
| `commission_amount`| DECIMAL(14, 2) | NOT NULL | قيمة عمولة المنصة |
| `shipping_fee` | DECIMAL(14, 2) | DEFAULT 0.00 | رسوم الشحن والتوصيل |
| `total_amount` | DECIMAL(14, 2) | NOT NULL | المبلغ الإجمالي النهائي المطلوب سداده |
| `payment_method` | ENUM('bank_transfer','cod','online_card','wallet','deferred') | NOT NULL | طريقة الدفع المتفق عليها |
| `delivery_method`| ENUM('pickup','seller_fleet','courier') | NOT NULL | طريقة الاستلام والتسليم |
| `status` | ENUM('requested','accepted','payment_pending','paid','preparing','ready_for_pickup','shipped','delivered','received','completed','cancelled','disputed','refunded','failed') | DEFAULT 'requested' | حالة الصفقة |
| `inspection_window_hours`| INT | DEFAULT 48 | مهلة فحص البضاعة بعد الاستلام بالساعات |
| `accepted_at` | TIMESTAMP | NULLABLE | وقت قبول الصفقة |
| `paid_at` | TIMESTAMP | NULLABLE | وقت تأكيد الدفع |
| `dispatched_at` | TIMESTAMP | NULLABLE | وقت خروج البضاعة من المستودع |
| `delivered_at` | TIMESTAMP | NULLABLE | وقت وصول البضاعة للمشتري |
| `completed_at` | TIMESTAMP | NULLABLE | وقت الإغلاق النهائي والتسوية |
| `cancelled_at` | TIMESTAMP | NULLABLE | وقت الإلغاء إن حصل |
| `cancellation_reason`| TEXT | NULLABLE | سبب الإلغاء |
| `created_at`, `updated_at`, `deleted_at` | TIMESTAMP | NULLABLE | التوقيتات |
*فهارس:*
- `INDEX (deal_number)`
- `INDEX (seller_organization_id)`
- `INDEX (buyer_organization_id)`
- `INDEX (status)`
- `INDEX (created_at)`

#### 13. `deal_items` (لقطة تفاصيل الأصناف المحمية)
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف السجل |
| `deal_id` | BIGINT UNSIGNED | FK -> deals(id) ON DELETE CASCADE | الصفقة |
| `product_variant_id`| BIGINT UNSIGNED | FK -> product_variants(id) | صنف البضاعة |
| `snapshot_product_name`| VARCHAR(191) | NOT NULL | لقطة اسم المنتج كما كان لحظة الصفقة |
| `snapshot_sku` | VARCHAR(64) | NOT NULL | لقطة رمز التخزين SKU |
| `snapshot_unit_name`| VARCHAR(50) | NOT NULL | لقطة وحدة البيع والتعبئة |
| `quantity` | DECIMAL(12, 3) | NOT NULL | الكمية المتداولة |
| `unit_price` | DECIMAL(14, 2) | NOT NULL | سعر الوحدة المجمد |
| `subtotal` | DECIMAL(14, 2) | NOT NULL | إجمالي الصنف قبل الضريبة |
| `created_at` | TIMESTAMP | NULLABLE | التوقيت |

#### 14. `deal_status_histories`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | المعرف |
| `deal_id` | BIGINT UNSIGNED | FK -> deals(id) ON DELETE CASCADE | الصفقة |
| `from_status` | VARCHAR(40) | NOT NULL | الحالة السابقة |
| `to_status` | VARCHAR(40) | NOT NULL | الحالة الجديدة |
| `changed_by_user_id`| BIGINT UNSIGNED | FK -> users(id) NULLABLE | المستخدم مغير الحالة |
| `notes` | TEXT | NULLABLE | ملاحظات إضافية |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | وقت التغيير |
*فهارس:* `INDEX (deal_id)`.

---

### 2.6 قطاع المدفوعات ودفتر الأستاذ (Payments & Ledger)

#### 15. `payments`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف عملية الدفع |
| `deal_id` | BIGINT UNSIGNED | FK -> deals(id) | الصفقة المرتبطة |
| `payment_reference`| VARCHAR(64) | UNIQUE, NOT NULL | المرجع الداخلي (مثال: PAY-2026-0089) |
| `provider` | VARCHAR(50) | NOT NULL | bank_transfer, cod, mada, visa, mastercard |
| `amount` | DECIMAL(14, 2) | NOT NULL | المبلغ المدفوع |
| `currency` | CHAR(3) | NOT NULL | العملة |
| `external_transaction_id`| VARCHAR(120) | NULLABLE | رقم العملية لدى البنك أو البوابة الخارجية |
| `bank_receipt_url` | VARCHAR(255) | NULLABLE | صورة أو ملف إشعار التحويل البنكي |
| `status` | ENUM('initiated','pending','processing','success','failed','refunded','partially_refunded') | DEFAULT 'initiated' | حالة الدفع |
| `verified_by_user_id`| BIGINT UNSIGNED | FK -> users(id) NULLABLE | الموظف الذي راجع التحويل واعتمد السند |
| `verified_at` | TIMESTAMP | NULLABLE | تاريخ الاعتماد المالي |
| `metadata` | JSON | NULLABLE | حمولة الاستجابة البنكية أو وسائط الدفع |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات |
*فهارس:* `INDEX (deal_id)`, `INDEX (status)`.

#### 16. `ledger_accounts`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف الحساب المالي |
| `organization_id` | BIGINT UNSIGNED | FK -> organizations(id) NULLABLE | المؤسسة التابع لها الحساب (NULL لمنصة حصّال) |
| `account_type` | ENUM('escrow','available_balance','commission_revenue','tax_liability','receivable') | NOT NULL | نوع الحساب المحاسبي |
| `currency` | CHAR(3) | NOT NULL | عملة الحساب |
| `balance` | DECIMAL(14, 2) | DEFAULT 0.00 | الرصيد الحالي التراكمي (مدقق بالقيود) |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات |
*قيود فريدة:* `UNIQUE (organization_id, account_type, currency)`.

#### 17. `ledger_entries` (سجل القيود المحاسبية الثنائية غير القابل للتعديل)
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف القيد |
| `deal_id` | BIGINT UNSIGNED | FK -> deals(id) NULLABLE | الصفقة ذات الصلة |
| `payment_id` | BIGINT UNSIGNED | FK -> payments(id) NULLABLE | الدفعة ذات الصلة |
| `debit_account_id` | BIGINT UNSIGNED | FK -> ledger_accounts(id) | حساب المدين |
| `credit_account_id` | BIGINT UNSIGNED | FK -> ledger_accounts(id) | حساب الدائن |
| `amount` | DECIMAL(14, 2) | NOT NULL | قيمة القيد المحاسبي |
| `currency` | CHAR(3) | NOT NULL | العملة |
| `entry_type` | ENUM('deal_escrow_hold','seller_payout','platform_commission','vat_deduction','refund','fee_adjustment') | NOT NULL | تصنيف الحركة |
| `description` | VARCHAR(255) | NOT NULL | الشرح والبيان المحاسبي |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | وقت تدوين القيد (غير قابل للتعديل) |
*فهارس:* `INDEX (deal_id)`, `INDEX (debit_account_id)`, `INDEX (credit_account_id)`.

---

### 2.7 قطاع النزاعات والتحكيم (Disputes & Arbitration)

#### 18. `disputes`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف النزاع |
| `deal_id` | BIGINT UNSIGNED | FK -> deals(id) UNIQUE | الصفقة محل النزاع |
| `opened_by_organization_id`| BIGINT UNSIGNED | FK -> organizations(id) | المنشأة التي فتحت النزاع |
| `opened_by_user_id` | BIGINT UNSIGNED | FK -> users(id) | الشخص الذي باشر النزاع |
| `reason` | ENUM('damaged_goods','quantity_mismatch','spec_mismatch','expired_goods','non_delivery','other') | NOT NULL | سبب النزاع الرئيسي |
| `claimed_amount` | DECIMAL(14, 2) | NOT NULL | المبلغ المطالب باسترداده |
| `status` | ENUM('opened','under_review','evidence_required','resolved_refund','resolved_reject','resolved_partial','closed') | DEFAULT 'opened' | حالة مسار النزاع |
| `arbitrator_user_id`| BIGINT UNSIGNED | FK -> users(id) NULLABLE | محكم منصة حصّال |
| `resolution_decision`| ENUM('full_refund','partial_refund','dismiss_dispute') | NULLABLE | القرار النهائي |
| `refunded_amount` | DECIMAL(14, 2) | DEFAULT 0.00 | المبلغ المسترد فعلياً في حال صدور قرار |
| `resolution_notes` | TEXT | NULLABLE | تسبيب القرار الإداري |
| `resolved_at` | TIMESTAMP | NULLABLE | تاريخ البت بالنزاع |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات |

#### 19. `dispute_evidences`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | المعرف |
| `dispute_id` | BIGINT UNSIGNED | FK -> disputes(id) ON DELETE CASCADE | النزاع |
| `uploader_user_id`| BIGINT UNSIGNED | FK -> users(id) | رافع البينة |
| `file_type` | ENUM('image','pdf','video','inspection_report') | NOT NULL | نوع المستند |
| `file_url` | VARCHAR(255) | NOT NULL | مسار الملف الآمن |
| `description` | VARCHAR(255) | NULLABLE | شرح البينة |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | وقت الرفع |

---

### 2.8 قطاع طلبات الاحتياج والمفضلات (Demands & Favorites)

#### 20. `demands` (طلبات الاحتياج التجاري B2B Commercial RFQ)
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | معرف طلب الاحتياج |
| `buyer_organization_id`| BIGINT UNSIGNED | FK -> organizations(id) | المنشأة طالبة البضاعة |
| `category_id` | BIGINT UNSIGNED | FK -> categories(id) | تصنيف البضاعة |
| `title` | VARCHAR(191) | NOT NULL | عنوان طلب الاحتياج (مثال: مطلوب 500 كرتون زيت نباتي) |
| `description` | TEXT | NOT NULL | المواصفات الفنية والشروط |
| `quantity` | DECIMAL(12, 3) | NOT NULL | الكمية المطلوبة |
| `target_unit_price` | DECIMAL(14, 2) | NULLABLE | السعر المستهدف للوحدة |
| `currency` | CHAR(3) | DEFAULT 'SAR' | العملة المطلوبة |
| `target_city` | VARCHAR(100) | NOT NULL | مدينة الاستلام |
| `required_by_date`| DATE | NOT NULL | تاريخ الحاجة الماسة للبضاعة |
| `status` | ENUM('open','fulfilled','expired','cancelled') | DEFAULT 'open' | حالة طلب الاحتياج |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات |

#### 21. `favorites`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | المعرف |
| `user_id` | BIGINT UNSIGNED | FK -> users(id) ON DELETE CASCADE | المستخدم |
| `organization_id` | BIGINT UNSIGNED | FK -> organizations(id) ON DELETE CASCADE | المؤسسة المفضلة |
| `favoritable_type`| VARCHAR(80) | NOT NULL | Offer أو Organization |
| `favoritable_id` | BIGINT UNSIGNED | NOT NULL | معرف الكيان المفضل |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | وقت الإضافة للمفضلة |
*قيود فريدة:* `UNIQUE (user_id, favoritable_type, favoritable_id)`.

---

### 2.9 قطاع الإعدادات والميزات ومفاتيح التكرار وسجلات التدقيق (Platform System)

#### 22. `settings`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | المعرف |
| `key` | VARCHAR(100) | UNIQUE, NOT NULL | مفتاح الإعداد (مثال: `tax.default_rate`) |
| `value` | TEXT | NOT NULL | القيمة المخزنة |
| `cast_type` | ENUM('string','integer','float','boolean','json') | DEFAULT 'string' | نوع التحويل البرمجي |
| `group` | VARCHAR(50) | NOT NULL | تصنيف الإعداد (financial, marketplace, general) |
| `is_public` | BOOLEAN | DEFAULT FALSE | هل يتاح عبر API bootstrap |
| `description` | VARCHAR(255) | NULLABLE | شرح الإعداد |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات |

#### 23. `feature_flags`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | المعرف |
| `name` | VARCHAR(100) | UNIQUE, NOT NULL | اسم الراية (nearby_offers, demands, ai_pricing) |
| `is_enabled` | BOOLEAN | DEFAULT FALSE | هل الميزة مفعلة |
| `description` | VARCHAR(255) | NULLABLE | وصف أثر الميزة |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات |

#### 24. `idempotency_keys`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | المعرف |
| `key` | VARCHAR(120) | UNIQUE, NOT NULL | المفتاح المرسل من العميل X-Idempotency-Key |
| `user_id` | BIGINT UNSIGNED | FK -> users(id) | صاحب الطلب |
| `request_hash` | VARCHAR(64) | NOT NULL | هاش SHA-256 للمسار والحمولة |
| `response_code` | INT | NULLABLE | كود الاستجابة المخزن (200, 201) |
| `response_body` | JSON | NULLABLE | كامل جسم الاستجابة لإعادته فورا عند التكرار |
| `status` | ENUM('started','completed','failed') | DEFAULT 'started' | حالة المعالجة |
| `expires_at` | TIMESTAMP | NOT NULL | موعد حذف المفتاح بعد انقضاء نافذة الأمان (24 ساعة) |
| `created_at`, `updated_at` | TIMESTAMP | NULLABLE | التوقيتات |

#### 25. `audit_logs`
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | المعرف |
| `user_id` | BIGINT UNSIGNED | FK -> users(id) NULLABLE | منفذ العملية |
| `organization_id` | BIGINT UNSIGNED | FK -> organizations(id) NULLABLE | المؤسسة المعنية |
| `action` | VARCHAR(100) | NOT NULL | create_offer, accept_deal, refund_payment |
| `auditable_type` | VARCHAR(100) | NOT NULL | اسم الموديل (Offer, Deal, Payment) |
| `auditable_id` | BIGINT UNSIGNED | NOT NULL | معرف السجل المتأثر |
| `old_values` | JSON | NULLABLE | القيم السابقة قبل التعديل |
| `new_values` | JSON | NULLABLE | القيم الجديدة بعد التعديل |
| `ip_address` | VARCHAR(45) | NULLABLE | عنوان IP المنفذ |
| `user_agent` | TEXT | NULLABLE | متصفح أو جهاز العميل |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | وقت حدوث العملية |
*فهارس:* `INDEX (auditable_type, auditable_id)`, `INDEX (organization_id)`, `INDEX (action)`.

#### 26. `webhooks` و `webhook_deliveries`
| الجدول | الحقول الجوهرية | الوصف |
| :--- | :--- | :--- |
| `webhooks` | `id`, `organization_id`, `url`, `secret` (HMAC), `events` (JSON), `is_active` | عناوين استدعاء الشركاء |
| `webhook_deliveries` | `id`, `webhook_id`, `event_name`, `payload`, `response_code`, `attempts`, `status` | سجل الإرسال وإعادة المحاولات |

#### 27. `sync_logs` (ERP / WMS Integration Tracking)
| الحقل | النوع | القيود | الوصف |
| :--- | :--- | :--- | :--- |
| `id` | BIGINT UNSIGNED | PK | المعرف |
| `organization_id` | BIGINT UNSIGNED | FK -> organizations(id) | المؤسسة المتكاملة |
| `system_type` | ENUM('erp','wms','pos','custom') | NOT NULL | نوع النظام الخارجي |
| `entity_type` | VARCHAR(64) | NOT NULL | inventory, deal, offer, receipt |
| `external_id` | VARCHAR(120) | NOT NULL | المعرف في النظام الخارجي |
| `internal_id` | BIGINT UNSIGNED | NOT NULL | المعرف داخل حصّال |
| `sync_direction` | ENUM('inbound','outbound') | NOT NULL | اتجاه المزامنة |
| `status` | ENUM('success','failed','conflict')| NOT NULL | نتيجة المزامنة |
| `payload` | JSON | NULLABLE | البيانات المتبادلة |
| `error_message` | TEXT | NULLABLE | تفاصيل الخطأ إن وجد |
| `created_at` | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP | توقيت السجل |
