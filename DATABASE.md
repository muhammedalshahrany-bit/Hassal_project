# منصة حصّال (HASSAL) — المخطط الفعلي لقاعدة البيانات (DATABASE.md)

> **How to read this file.** The tables below were generated from the live MySQL schema
> (`information_schema`) on 2026-09-19, not written from a design report. Type, nullability and
> default are exactly what the database enforces after `php artisan migrate`. This document does
> not carry business meaning: what a column is *for*, and which rules the application puts on it,
> belong to `DOMAIN_MODEL.md`, `backend/API_CONTRACT.md` and the migrations themselves.
> An earlier revision of this file described a schema that was never built — invented columns such
> as `offers.market_reference_price`, `offers.discount_percentage`, a `SAR` currency default and a
> `users.phone NOT NULL` login rule. None of those exist here, because none of them exist in the
> database.
>
> **2026-09-25:** a normalization pass (§3 below) dropped three columns and added nine foreign
> keys to `currencies`. Those rows were corrected by hand inside the generated tables above.
>
> **2026-10-01:** the payment-era drift was removed. `payments` and `escrows` appeared in this
> file although no migration ever created them and the live schema has never held them — their
> sections are deleted here, not marked retired. The same pass corrected `deals` (the `paid_at`
> and `settled_at` rows were never built; the payment states are gone from the state machine),
> corrected `disputes` to the columns the database actually enforces, and added the nine tables
> that were created since the 2026-09-19 dump: `deal_handovers`, `external_identities`,
> `user_devices`, `support_tickets`, `product_images`, `offer_comments`, `offer_comment_reports`,
> `offer_likes` and `organization_documents` — each generated from `information_schema` on
> 2026-10-01.

- **العملة الافتراضية في المخطط:** `YER` (قابلة للتغيير من `currencies` و`system_settings`).
- **الدقة المالية والكمّية:** العمودان `unit_price` و`total_quantity` في `offers` مثال حي على النمط
  المتَّبع (`DECIMAL(18,4)` للمبالغ، `DECIMAL(14,2)` للكميات)، والنوع الدقيق لكل حقل مذكور في
  جداول القسم 2 — لا تُعِمم من هذا السطر.
  لا يوجد أي معدل ضريبة أو عمولة ثابت في المخطط: القيمتان `0.0000` افتراضان حياديان لا قراران تجاريان.
- **الحذف المرن (`deleted_at`):** مطبّق حيث يقرره الكود فقط؛ عمود `deleted_at` الغائب يعني أن السجل لا يُحذف مرناً.

الجداول في القاعدة: **58**.

---

## 1. الجداول حسب القطاع

### الهوية والاشتراك (Identity & Tenancy)

| الجدول | عدد الأعمدة |
| :--- | :---: |
| `users` | 13 |
| `external_identities` | 10 |
| `user_devices` | 10 |
| `password_reset_tokens` | 3 |
| `sessions` | 6 |
| `cache` | 3 |
| `cache_locks` | 3 |
| `jobs` | 7 |
| `job_batches` | 10 |
| `failed_jobs` | 7 |

### المنشآت والعضوية (Organizations & Memberships)

| الجدول | عدد الأعمدة |
| :--- | :---: |
| `organizations` | 21 |
| `organization_memberships` | 8 |
| `organization_roles` | 8 |
| `organization_role_permissions` | 5 |
| `organization_membership_permissions` | 6 |
| `organization_documents` | 16 |

### المستودعات والمواقع (Warehouses & Locations)

| الجدول | عدد الأعمدة |
| :--- | :---: |
| `warehouses` | 16 |

### الكتالوج (Catalog)

| الجدول | عدد الأعمدة |
| :--- | :---: |
| `categories` | 13 |
| `brands` | 8 |
| `products` | 19 |
| `product_variants` | 14 |
| `product_images` | 15 |

### العروض والطلبات والحجوزات (Offers, Requests, Reservations)

| الجدول | عدد الأعمدة |
| :--- | :---: |
| `offers` | 31 |
| `offer_items` | 9 |
| `offer_comments` | 14 |
| `offer_comment_reports` | 11 |
| `offer_likes` | 6 |
| `purchase_requests` | 20 |
| `reservations` | 18 |

### الصفقات (Deals)

| الجدول | عدد الأعمدة |
| :--- | :---: |
| `deals` | 41 |
| `deal_items` | 11 |
| `deal_status_histories` | 9 |
| `deal_handovers` | 13 |

### دفتر الأستاذ (Ledger)

| الجدول | عدد الأعمدة |
| :--- | :---: |
| `ledger_accounts` | 11 |
| `journal_entries` | 16 |
| `ledger_lines` | 9 |

### طلبات الاحتياج والمطابقات (Demands & Matching)

| الجدول | عدد الأعمدة |
| :--- | :---: |
| `demands` | 21 |
| `quotations` | 17 |
| `demand_matches` | 13 |

### النزاعات وسجلات التدقيق (Disputes & Audit)

| الجدول | عدد الأعمدة |
| :--- | :---: |
| `disputes` | 15 |
| `audit_logs` | 11 |

### منصة الإعداد والميزات (Platform Configuration)

| الجدول | عدد الأعمدة |
| :--- | :---: |
| `system_settings` | 14 |
| `feature_flags` | 10 |
| `currencies` | 14 |

### الحركات والمفاتيح والإشعارات (Movements, Idempotency, Notifications)

| الجدول | عدد الأعمدة |
| :--- | :---: |
| `inventory_movements` | 17 |
| `idempotency_keys` | 11 |
| `notifications` | 14 |
| `support_tickets` | 14 |

### التكامل B2B (API Keys & Webhooks)

| الجدول | عدد الأعمدة |
| :--- | :---: |
| `api_keys` | 11 |
| `webhooks` | 10 |
| `webhook_deliveries` | 14 |

### جداول Laravel الداخلية

| الجدول | عدد الأعمدة |
| :--- | :---: |
| `personal_access_tokens` | 10 |
| `migrations` | 3 |
| `role_has_permissions` | 2 |
| `permissions` | 6 |
| `model_has_roles` | 3 |
| `roles` | 5 |
| `model_has_permissions` | 3 |

---

## 2. الأعمدة كما هي في القاعدة

### الهوية والاشتراك (Identity & Tenancy)

#### `users`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `name` | `varchar(255)` | لا | — | — |
| `email` | `varchar(255)` | لا | — | UNI |
| `phone` | `varchar(255)` | نعم | — | UNI |
| `national_id` | `varchar(255)` | نعم | — | — |
| `status` | `varchar(255)` | لا | `active` | — |
| `email_verified_at` | `timestamp` | نعم | — | — |
| `phone_verified_at` | `timestamp` | نعم | — | — |
| `password` | `varchar(255)` | لا | — | — |
| `remember_token` | `varchar(100)` | نعم | — | — |
| `deleted_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `external_identities`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `user_id` | `bigint unsigned` | لا | — | MUL |
| `provider` | `varchar(50)` | لا | — | UNI |
| `provider_user_id` | `varchar(191)` | لا | — | UNI |
| `email` | `varchar(255)` | نعم | — | — |
| `email_verified_at` | `timestamp` | نعم | — | — |
| `linked_at` | `timestamp` | نعم | — | — |
| `last_verified_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `user_devices`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `user_id` | `bigint unsigned` | لا | — | UNI |
| `device_key` | `varchar(120)` | لا | — | UNI |
| `name` | `varchar(120)` | لا | — | — |
| `platform` | `varchar(20)` | لا | — | — |
| `token_id` | `bigint unsigned` | نعم | — | — |
| `biometric_unlock` | `tinyint(1)` | لا | 0 | — |
| `last_seen_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `password_reset_tokens`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `email` | `varchar(255)` | لا | — | PRI |
| `token` | `varchar(255)` | لا | — | — |
| `created_at` | `timestamp` | نعم | — | — |

#### `sessions`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `varchar(255)` | لا | — | PRI |
| `user_id` | `bigint unsigned` | نعم | — | MUL |
| `ip_address` | `varchar(45)` | نعم | — | — |
| `user_agent` | `text` | نعم | — | — |
| `payload` | `longtext` | لا | — | — |
| `last_activity` | `int` | لا | — | MUL |

#### `cache`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `key` | `varchar(255)` | لا | — | PRI |
| `value` | `mediumtext` | لا | — | — |
| `expiration` | `int` | لا | — | — |

#### `cache_locks`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `key` | `varchar(255)` | لا | — | PRI |
| `owner` | `varchar(255)` | لا | — | — |
| `expiration` | `int` | لا | — | — |

#### `jobs`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `queue` | `varchar(255)` | لا | — | MUL |
| `payload` | `longtext` | لا | — | — |
| `attempts` | `tinyint unsigned` | لا | — | — |
| `reserved_at` | `int unsigned` | نعم | — | — |
| `available_at` | `int unsigned` | لا | — | — |
| `created_at` | `int unsigned` | لا | — | — |

#### `job_batches`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `varchar(255)` | لا | — | PRI |
| `name` | `varchar(255)` | لا | — | — |
| `total_jobs` | `int` | لا | — | — |
| `pending_jobs` | `int` | لا | — | — |
| `failed_jobs` | `int` | لا | — | — |
| `failed_job_ids` | `longtext` | لا | — | — |
| `options` | `mediumtext` | نعم | — | — |
| `cancelled_at` | `int` | نعم | — | — |
| `created_at` | `int` | لا | — | — |
| `finished_at` | `int` | نعم | — | — |

#### `failed_jobs`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `uuid` | `varchar(255)` | لا | — | UNI |
| `connection` | `text` | لا | — | — |
| `queue` | `text` | لا | — | — |
| `payload` | `longtext` | لا | — | — |
| `exception` | `longtext` | لا | — | — |
| `failed_at` | `timestamp` | لا | `CURRENT_TIMESTAMP` | — |

### المنشآت والعضوية (Organizations & Memberships)

#### `organizations`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `name_ar` | `varchar(255)` | لا | — | — |
| `name_en` | `varchar(255)` | لا | — | — |
| `trade_name_ar` | `varchar(255)` | نعم | — | — |
| `trade_name_en` | `varchar(255)` | نعم | — | — |
| `type` | `varchar(255)` | لا | — | MUL |
| `country_code` | `varchar(2)` | لا | `YE` | MUL |
| `city` | `varchar(100)` | نعم | — | — |
| `website` | `varchar(255)` | نعم | — | — |
| `phone` | `varchar(30)` | نعم | — | — |
| `email` | `varchar(255)` | نعم | — | — |
| `commercial_registration_number` | `varchar(50)` | نعم | — | UNI |
| `vat_number` | `varchar(50)` | نعم | — | UNI |
| `status` | `varchar(255)` | لا | `pending_kyc` | — |
| `credit_limit` | `decimal(14,2)` | لا | `0.00` | — |
| `rating` | `decimal(3,2)` | نعم | — | — |
| `kyc_verified_at` | `timestamp` | نعم | — | — |
| `metadata` | `json` | نعم | — | — |
| `deleted_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `organization_memberships`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `organization_id` | `bigint unsigned` | لا | — | MUL |
| `user_id` | `bigint unsigned` | لا | — | MUL |
| `role` | `varchar(50)` | لا | `staff` | — |
| `is_active` | `tinyint(1)` | لا | `1` | — |
| `joined_at` | `timestamp` | لا | `CURRENT_TIMESTAMP` | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

> **`role` لم يعد مقيّداً بخمسة أسماء.** القيد `organization_memberships_role_is_canonical` حُذف مع
> ترحيل `2026_09_25_190000`: العمود صار يسجّل مفتاح وقوفٍ تملكه هذه المنشأة وحدها، وقد تخترع شركةٌ
> دوراً («أمين مستودع») لا تعرفه المنصة، وقيدُ `CHECK` لا يشير إلى جدول آخر في MySQL. المكان الذي
> يُفحص فيه الاسم الآن هو باب الكتابة (`AddMemberRequest` و`updateMember`) مقابل `organization_roles`
> الخاصة بتلك المنشأة، والنتيجة أن المفتاح المخزّن له صفّ يقف خلفه دائمًا. بقي في المخطط: فهارس
> `id` و`organization_id + user_id` الفريد ومفاتيح الانتماء. (الافتراضي `staff` لا `operator`: الاسم
> الأخير لم يكن مقبولاً في أي مُحقِّق، وهُجّرت صفوفه إليه.)

#### `organization_roles`

وقوف كل شركة: خمسة صفوف `is_system` تُزوَّد لكل منشأة من `config('rbac.membership_roles')`، وصفوفٌ
أخرى تسمّيها الشركة لنفسها. المفتاح فريد **داخل المنشأة** لا عالميًا، ومقارنته حساسة لحالة الأحرف
(`utf8mb4_bin`) لأن `Admin` و`admin` وقوفان مختلفان بالنسبة للشاشة التي تعرضهما.

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `organization_id` | `bigint unsigned` | لا | — | UNI (مع `key`)، FK → `organizations` عند حذفها CASCADE |
| `key` | `varchar(64)` | لا | — | UNI (مع `organization_id`) · `utf8mb4_bin` |
| `name_ar` | `varchar(255)` | لا | — | — |
| `name_en` | `varchar(255)` | نعم | — | — |
| `is_system` | `tinyint(1)` | لا | `0` | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `organization_role_permissions`

ما يساويه كل وقف بالـ `permission_id` لا بالاسم: قرار النطاق يُقرأ من عمود `permissions.scope` عند
الكتابة، فلا تُركَّب صلاحية منصة على وقوف شركة بمقارنة نصّ في متحكم.

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `organization_role_id` | `bigint unsigned` | لا | — | UNI (مع `permission_id`)، FK → `organization_roles` CASCADE |
| `permission_id` | `bigint unsigned` | لا | — | FK → `permissions` CASCADE |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `organization_membership_permissions`

التجاوز الفردي: صفٌّ لكل (عضو، صلاحية) بـ`effect` يقول أهذه إضافة فوق الوقوف أم سحبٌ منه. الاتجاهان
موجودان لأن جدولًا يضيف فقط لا يجيب على «أبقوا دوره، لكن امنعوه من حذف المنشورات» إلا بإنزاله درجةً
لم يطلبها أحد.

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `organization_membership_id` | `bigint unsigned` | لا | — | UNI (مع `permission_id`)، FK → `organization_memberships` CASCADE |
| `permission_id` | `bigint unsigned` | لا | — | FK → `permissions` CASCADE |
| `effect` | `varchar(8)` | لا | `grant` | `CHECK organization_membership_permissions_effect_is_canonical` (`grant`\|`deny` بمقارنة `utf8mb4_bin`) |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

> الحقل الأول هنا `unsignedBigInteger` بمفتاح مسمّى يدويًا (`org_member_perms_membership_fk`) لا
> `foreignId()->constrained()`: الاسم الذي يشتقه MySQL لجدولٍ باسم `organization_membership_permissions`
> يتجاوز حدّ المعرّف واحدًا وستّين حرفًا فيرفض الترحيل كله. كذلك الفهارس المشتركة مُسمّاة صراحةً
> (`org_role_perms_role_perm_unique`، `org_member_perms_member_perm_unique`،
> `organization_roles_org_key_unique`) للسبب نفسه — حتى تستطيع ترحيلات لاحقة أن تُسمّي ما تُبدله.

#### `organization_documents`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `organization_id` | `bigint unsigned` | لا | — | MUL |
| `type` | `varchar(40)` | لا | — | MUL |
| `reference_number` | `varchar(255)` | نعم | — | — |
| `disk` | `varchar(40)` | لا | public | — |
| `path` | `varchar(255)` | لا | — | — |
| `original_name` | `varchar(255)` | نعم | — | — |
| `mime_type` | `varchar(100)` | لا | — | — |
| `size_bytes` | `bigint unsigned` | لا | — | — |
| `status` | `varchar(20)` | لا | pending | MUL |
| `uploaded_by` | `bigint unsigned` | نعم | — | MUL |
| `reviewed_by` | `bigint unsigned` | نعم | — | MUL |
| `reviewed_at` | `timestamp` | نعم | — | — |
| `review_reason` | `text` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | MUL |
| `updated_at` | `timestamp` | نعم | — | — |

### المستودعات والمواقع (Warehouses & Locations)

#### `warehouses`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `organization_id` | `bigint unsigned` | لا | — | MUL |
| `name` | `varchar(255)` | لا | — | — |
| `code` | `varchar(50)` | نعم | — | — |
| `city` | `varchar(100)` | لا | — | MUL |
| `country_code` | `varchar(2)` | لا | `YE` | MUL |
| `district` | `varchar(100)` | نعم | — | — |
| `address_line` | `varchar(255)` | لا | — | — |
| `latitude` | `decimal(10,7)` | نعم | — | MUL |
| `longitude` | `decimal(10,7)` | نعم | — | — |
| `contact_name` | `varchar(255)` | نعم | — | — |
| `contact_phone` | `varchar(50)` | نعم | — | — |
| `is_active` | `tinyint(1)` | لا | `1` | — |
| `deleted_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

### الكتالوج (Catalog)

#### `categories`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `parent_id` | `bigint unsigned` | نعم | — | MUL |
| `code` | `varchar(50)` | لا | — | UNI |
| `slug` | `varchar(255)` | لا | — | UNI |
| `name_ar` | `varchar(255)` | لا | — | — |
| `name_en` | `varchar(255)` | لا | — | — |
| `description_ar` | `text` | نعم | — | — |
| `description_en` | `text` | نعم | — | — |
| `icon` | `varchar(100)` | نعم | — | — |
| `sort_order` | `int` | لا | `0` | MUL |
| `is_active` | `tinyint(1)` | لا | `1` | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `brands`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `name_ar` | `varchar(255)` | لا | — | — |
| `name_en` | `varchar(255)` | لا | — | — |
| `slug` | `varchar(255)` | لا | — | UNI |
| `logo_url` | `varchar(255)` | نعم | — | — |
| `is_active` | `tinyint(1)` | لا | `1` | MUL |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `products`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `organization_id` | `bigint unsigned` | نعم | — | MUL |
| `category_id` | `bigint unsigned` | لا | — | MUL |
| `brand_id` | `bigint unsigned` | نعم | — | MUL |
| `sku` | `varchar(100)` | نعم | — | MUL |
| `barcode` | `varchar(100)` | نعم | — | MUL |
| `name_ar` | `varchar(255)` | لا | — | — |
| `name_en` | `varchar(255)` | نعم | — | — |
| `description_ar` | `text` | نعم | — | — |
| `description_en` | `text` | نعم | — | — |
| `unit` | `varchar(50)` | لا | `carton` | — |
| `package_size` | `varchar(100)` | نعم | — | — |
| `temperature_class` | `varchar(50)` | لا | `ambient` | — |
| `shelf_life_days` | `int unsigned` | نعم | — | — |
| `image_url` | `varchar(255)` | نعم | — | — |
| `is_active` | `tinyint(1)` | لا | `1` | — |
| `deleted_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `product_variants`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `product_id` | `bigint unsigned` | لا | — | MUL |
| `sku` | `varchar(100)` | لا | — | UNI |
| `barcode` | `varchar(100)` | نعم | — | — |
| `name_ar` | `varchar(255)` | لا | — | — |
| `name_en` | `varchar(255)` | لا | — | — |
| `weight_kg` | `decimal(10,3)` | نعم | — | — |
| `packaging_spec` | `varchar(255)` | نعم | — | — |
| `image_url` | `varchar(255)` | نعم | — | — |
| `attributes_json` | `json` | نعم | — | — |
| `is_active` | `tinyint(1)` | لا | `1` | — |
| `deleted_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `product_images`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `product_id` | `bigint unsigned` | لا | — | MUL |
| `organization_id` | `bigint unsigned` | لا | — | MUL |
| `disk` | `varchar(40)` | لا | public | — |
| `path` | `varchar(255)` | لا | — | — |
| `original_name` | `varchar(255)` | نعم | — | — |
| `mime_type` | `varchar(100)` | لا | — | — |
| `size_bytes` | `bigint unsigned` | لا | — | — |
| `width` | `smallint unsigned` | نعم | — | — |
| `height` | `smallint unsigned` | نعم | — | — |
| `sort_order` | `smallint unsigned` | لا | 0 | MUL |
| `is_cover` | `tinyint(1)` | لا | 0 | — |
| `uploaded_by` | `bigint unsigned` | نعم | — | MUL |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

### العروض والطلبات والحجوزات (Offers, Requests, Reservations)

#### `offers`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `seller_organization_id` | `bigint unsigned` | لا | — | MUL |
| `warehouse_id` | `bigint unsigned` | لا | — | MUL |
| `product_variant_id` | `bigint unsigned` | لا | — | MUL |
| `title_ar` | `varchar(255)` | لا | — | — |
| `title_en` | `varchar(255)` | نعم | — | — |
| `description_ar` | `text` | نعم | — | — |
| `description_en` | `text` | نعم | — | — |
| `opportunity_reason` | `varchar(50)` | لا | — | — |
| `opportunity_reason_notes` | `text` | نعم | — | — |
| `status` | `varchar(30)` | لا | `DRAFT` | MUL |
| `min_order_quantity` | `decimal(14,2)` | لا | `1.00` | — |
| `max_order_quantity` | `decimal(14,2)` | نعم | — | — |
| `total_quantity` | `decimal(14,2)` | لا | — | — |
| `available_quantity` | `decimal(14,2)` | لا | — | — |
| `reserved_quantity` | `decimal(14,2)` | لا | `0.00` | — |
| `sold_quantity` | `decimal(14,2)` | لا | `0.00` | — |
| `unit` | `varchar(50)` | لا | `carton` | — |
| `unit_price` | `decimal(18,4)` | لا | — | — |
| `currency_code` | `varchar(3)` | لا | `YER` | MUL |
| `production_date` | `date` | نعم | — | — |
| `expiry_date` | `date` | نعم | — | — |
| `storage_condition` | `varchar(50)` | لا | `ambient` | — |
| `delivery_terms` | `varchar(50)` | لا | `BOTH` | — |
| `inspection_window_hours` | `int unsigned` | لا | `48` | — |
| `published_at` | `timestamp` | نعم | — | — |
| `expires_at` | `timestamp` | نعم | — | MUL |
| `metadata` | `json` | نعم | — | — |
| `deleted_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `offer_items`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `offer_id` | `bigint unsigned` | لا | — | MUL |
| `product_id` | `bigint unsigned` | لا | — | MUL |
| `product_variant_id` | `bigint unsigned` | لا | — | MUL |
| `quantity` | `decimal(14,2)` | لا | — | — |
| `unit_price` | `decimal(18,4)` | لا | — | — |
| `currency_code` | `varchar(3)` | لا | `YER` | MUL |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `offer_comments`

خيط الأسئلة المنشورة: كل سطر يسمّي كاتبه دائماً، ويسمّي منشأته عند وجود سياق منشأة — المستهلك
يسأل باسمه المجرد و`organization_id` فيه `NULL` (ترقية 2026-10-01: `person attribution`).

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `offer_id` | `bigint unsigned` | لا | — | MUL |
| `organization_id` | `bigint unsigned` | نعم | — | MUL |
| `user_id` | `bigint unsigned` | لا | — | MUL |
| `parent_id` | `bigint unsigned` | نعم | — | MUL |
| `is_seller_reply` | `tinyint(1)` | لا | 0 | — |
| `body` | `text` | لا | — | — |
| `status` | `varchar(20)` | لا | published | MUL |
| `report_count` | `int unsigned` | لا | 0 | — |
| `moderated_by` | `bigint unsigned` | نعم | — | MUL |
| `moderated_at` | `timestamp` | نعم | — | — |
| `moderation_reason` | `text` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | MUL |
| `updated_at` | `timestamp` | نعم | — | — |

#### `offer_comment_reports`

بلاغ للفرد: قيد التفرد `comment_reports_unique_per_person (offer_comment_id, user_id)` —
لا يبلّغ شخص عن التعليق مرتين — و`organization_id` توثيق منشأة السياق عند وجودها، `NULL`
لمستهلك بلا منشأة.

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `offer_comment_id` | `bigint unsigned` | لا | — | MUL |
| `organization_id` | `bigint unsigned` | نعم | — | MUL |
| `user_id` | `bigint unsigned` | لا | — | MUL |
| `reason` | `varchar(40)` | لا | — | — |
| `note` | `text` | نعم | — | — |
| `status` | `varchar(20)` | لا | open | — |
| `reviewed_by` | `bigint unsigned` | نعم | — | MUL |
| `reviewed_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `offer_likes`

الاهتمام للفرد لا للشركة: قيد التفرد `offer_likes_unique_per_person (offer_id, user_id)`،
و`organization_id` يوثّق منشأة السياق عند وجودها ويكون `NULL` لمستهلك بلا منشأة.

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `offer_id` | `bigint unsigned` | لا | — | MUL |
| `user_id` | `bigint unsigned` | لا | — | MUL |
| `organization_id` | `bigint unsigned` | نعم | — | MUL |
| `created_at` | `timestamp` | نعم | — | MUL |
| `updated_at` | `timestamp` | نعم | — | — |

#### `purchase_requests`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `purchase_request_number` | `varchar(50)` | لا | — | UNI |
| `demand_id` | `bigint unsigned` | نعم | — | MUL |
| `demand_match_id` | `bigint unsigned` | نعم | — | MUL |
| `offer_id` | `bigint unsigned` | لا | — | MUL |
| `reservation_id` | `bigint unsigned` | نعم | — | MUL |
| `buyer_organization_id` | `bigint unsigned` | لا | — | MUL |
| `seller_organization_id` | `bigint unsigned` | لا | — | MUL |
| `buyer_user_id` | `bigint unsigned` | لا | — | MUL |
| `seller_user_id` | `bigint unsigned` | نعم | — | MUL |
| `requested_quantity` | `decimal(14,2)` | لا | — | — |
| `unit` | `varchar(50)` | لا | `unit` | — |
| `requested_price` | `decimal(18,4)` | لا | — | — |
| `currency_code` | `varchar(3)` | لا | `YER` | MUL |
| `delivery_preference` | `varchar(30)` | لا | `PICKUP` | — |
| `status` | `varchar(30)` | لا | `REQUESTED` | — |
| `notes` | `text` | نعم | — | — |
| `proposed_terms` | `json` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `reservations`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `reservation_number` | `varchar(50)` | لا | — | UNI |
| `offer_id` | `bigint unsigned` | لا | — | MUL |
| `buyer_organization_id` | `bigint unsigned` | لا | — | MUL |
| `buyer_user_id` | `bigint unsigned` | لا | — | MUL |
| `quantity` | `decimal(14,2)` | لا | — | — |
| `unit_price` | `decimal(18,4)` | لا | — | — |
| `currency_code` | `varchar(3)` | لا | `YER` | MUL |
| `total_amount` | `decimal(18,4)` | لا | — | — |
| `delivery_preference` | `varchar(50)` | لا | `PICKUP` | — |
| `status` | `varchar(30)` | لا | `ACTIVE` | MUL |
| `expires_at` | `timestamp` | لا | — | — |
| `converted_at` | `timestamp` | نعم | — | — |
| `cancelled_at` | `timestamp` | نعم | — | — |
| `cancellation_reason` | `text` | نعم | — | — |
| `notes` | `text` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

### الصفقات (Deals)

#### `deals`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `deal_number` | `varchar(50)` | لا | — | UNI |
| `offer_id` | `bigint unsigned` | لا | — | MUL |
| `reservation_id` | `bigint unsigned` | نعم | — | MUL |
| `purchase_request_id` | `bigint unsigned` | نعم | — | MUL |
| `buyer_organization_id` | `bigint unsigned` | لا | — | MUL |
| `seller_organization_id` | `bigint unsigned` | لا | — | MUL |
| `buyer_user_id` | `bigint unsigned` | لا | — | MUL |
| `seller_user_id` | `bigint unsigned` | نعم | — | MUL |
| `warehouse_id` | `bigint unsigned` | نعم | — | MUL |
| `quantity` | `decimal(14,2)` | لا | — | — |
| `unit_price` | `decimal(18,4)` | لا | — | — |
| `currency_code` | `varchar(3)` | لا | `YER` | MUL |
| `base_currency` | `varchar(3)` | لا | `YER` | — |
| `exchange_rate_snapshot` | `decimal(18,6)` | لا | `1.000000` | — |
| `rate_source` | `varchar(50)` | لا | `MANUAL_ADMIN_RATE` | — |
| `rate_timestamp` | `timestamp` | نعم | — | — |
| `base_amount` | `decimal(18,4)` | لا | — | — |
| `discount_amount` | `decimal(18,4)` | لا | `0.0000` | — |
| `tax_rate_snapshot` | `decimal(6,4)` | لا | `0.0000` | — |
| `tax_amount` | `decimal(18,4)` | لا | `0.0000` | — |
| `platform_fee_rate_snapshot` | `decimal(6,4)` | لا | `0.0000` | — |
| `platform_fee_amount` | `decimal(18,4)` | لا | `0.0000` | — |
| `delivery_fee_amount` | `decimal(18,4)` | لا | `0.0000` | — |
| `buyer_total_amount` | `decimal(18,4)` | لا | — | — |
| `seller_net_amount` | `decimal(18,4)` | لا | — | — |
| `status` | `varchar(40)` | لا | `PENDING_SELLER_APPROVAL` | MUL |
| `delivery_method` | `varchar(50)` | لا | `PICKUP` | — |
| `delivery_address` | `varchar(255)` | نعم | — | — |
| `inspection_window_hours` | `int unsigned` | لا | `48` | — |
| `inspection_deadline_at` | `timestamp` | نعم | — | — |
| `accepted_at` | `timestamp` | نعم | — | — |
| `dispatched_at` | `timestamp` | نعم | — | — |
| `delivered_at` | `timestamp` | نعم | — | — |
| `completed_at` | `timestamp` | نعم | — | — |
| `cancelled_at` | `timestamp` | نعم | — | — |
| `cancellation_reason` | `text` | نعم | — | — |
| `metadata` | `json` | نعم | — | — |
| `deleted_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `deal_items`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `deal_id` | `bigint unsigned` | لا | — | MUL |
| `product_variant_id` | `bigint unsigned` | لا | — | MUL |
| `snapshot_product_name` | `varchar(255)` | لا | — | — |
| `snapshot_sku` | `varchar(100)` | لا | — | — |
| `snapshot_unit_name` | `varchar(50)` | لا | — | — |
| `quantity` | `decimal(14,2)` | لا | — | — |
| `unit_price` | `decimal(18,4)` | لا | — | — |
| `subtotal_amount` | `decimal(18,4)` | لا | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `deal_status_histories`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `deal_id` | `bigint unsigned` | لا | — | MUL |
| `from_status` | `varchar(40)` | لا | — | — |
| `to_status` | `varchar(40)` | لا | — | — |
| `changed_by_user_id` | `bigint unsigned` | نعم | — | MUL |
| `notes` | `text` | نعم | — | — |
| `metadata` | `json` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `deal_handovers`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `deal_id` | `bigint unsigned` | لا | — | UNI |
| `code` | `varchar(24)` | لا | — | UNI |
| `status` | `varchar(20)` | لا | ACTIVE | MUL |
| `issued_by_user_id` | `bigint unsigned` | نعم | — | MUL |
| `verified_by_user_id` | `bigint unsigned` | نعم | — | MUL |
| `verified_by_organization_id` | `bigint unsigned` | نعم | — | MUL |
| `issued_at` | `timestamp` | لا | — | MUL |
| `verified_at` | `timestamp` | نعم | — | — |
| `invalidated_at` | `timestamp` | نعم | — | — |
| `verification_notes` | `text` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

### دفتر الأستاذ (Ledger)

> لا وجود لجدولي `payments` و`escrows` في هذه القاعدة ولا في أي هجرة: وُصفا في نسخة قديمة من هذا
> الملف وصفاً لم يُبنَ قطّ، وحُذفا من الوثيقة لا من القاعدة. المنصّة لا تحتفظ بأموال — راجع `DOMAIN_MODEL.md` §3.4.

#### `ledger_accounts`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `account_code` | `varchar(50)` | لا | — | UNI |
| `name_ar` | `varchar(255)` | لا | — | — |
| `name_en` | `varchar(255)` | لا | — | — |
| `type` | `varchar(30)` | لا | — | — |
| `organization_id` | `bigint unsigned` | نعم | — | MUL |
| `currency_code` | `varchar(3)` | لا | `YER` | MUL |
| `balance` | `decimal(18,4)` | لا | `0.0000` | — |
| `is_active` | `tinyint(1)` | لا | `1` | MUL |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `journal_entries`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `entry_number` | `varchar(50)` | لا | — | UNI |
| `transaction_reference` | `varchar(100)` | نعم | — | MUL |
| `currency_code` | `varchar(3)` | لا | `YER` | MUL |
| `exchange_rate_to_base` | `decimal(18,6)` | لا | `1.000000` | — |
| `description` | `text` | لا | — | — |
| `entity_type` | `varchar(100)` | نعم | — | MUL |
| `entity_id` | `bigint unsigned` | نعم | — | — |
| `created_by_user_id` | `bigint unsigned` | نعم | — | MUL |
| `total_debit` | `decimal(18,4)` | لا | — | — |
| `total_credit` | `decimal(18,4)` | لا | — | — |
| `posted_at` | `timestamp` | لا | — | MUL |
| `is_reversal` | `tinyint(1)` | لا | `0` | — |
| `reversal_of_entry_id` | `bigint unsigned` | نعم | — | MUL |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `ledger_lines`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `journal_entry_id` | `bigint unsigned` | لا | — | MUL |
| `ledger_account_id` | `bigint unsigned` | لا | — | MUL |
| `organization_id` | `bigint unsigned` | نعم | — | MUL |
| `debit_amount` | `decimal(18,4)` | لا | `0.0000` | — |
| `credit_amount` | `decimal(18,4)` | لا | `0.0000` | — |
| `memo` | `varchar(255)` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

### طلبات الاحتياج والمطابقات (Demands & Matching)

#### `demands`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `demand_number` | `varchar(50)` | لا | — | UNI |
| `buyer_organization_id` | `bigint unsigned` | لا | — | MUL |
| `buyer_user_id` | `bigint unsigned` | لا | — | MUL |
| `category_id` | `bigint unsigned` | نعم | — | MUL |
| `title_ar` | `varchar(255)` | لا | — | — |
| `title_en` | `varchar(255)` | نعم | — | — |
| `description` | `text` | نعم | — | — |
| `target_quantity` | `decimal(14,2)` | لا | — | — |
| `unit` | `varchar(50)` | لا | `carton` | — |
| `target_unit_price` | `decimal(18,4)` | نعم | — | — |
| `currency_code` | `varchar(3)` | لا | `YER` | MUL |
| `target_delivery_date` | `date` | نعم | — | — |
| `delivery_city` | `varchar(100)` | لا | — | MUL |
| `delivery_latitude` | `decimal(10,7)` | نعم | — | — |
| `delivery_longitude` | `decimal(10,7)` | نعم | — | — |
| `special_requirements` | `text` | نعم | — | — |
| `status` | `varchar(30)` | لا | `OPEN` | — |
| `expires_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `quotations`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `quotation_number` | `varchar(50)` | لا | — | UNI |
| `demand_id` | `bigint unsigned` | لا | — | MUL |
| `seller_organization_id` | `bigint unsigned` | لا | — | MUL |
| `seller_user_id` | `bigint unsigned` | لا | — | MUL |
| `quoted_quantity` | `decimal(14,2)` | لا | — | — |
| `unit_price` | `decimal(18,4)` | لا | — | — |
| `currency_code` | `varchar(3)` | لا | `YER` | MUL |
| `total_amount` | `decimal(18,4)` | لا | — | — |
| `validity_days` | `int unsigned` | لا | `7` | — |
| `notes` | `text` | نعم | — | — |
| `status` | `varchar(30)` | لا | `SUBMITTED` | — |
| `accepted_at` | `timestamp` | نعم | — | — |
| `rejected_at` | `timestamp` | نعم | — | — |
| `converted_deal_id` | `bigint unsigned` | نعم | — | MUL |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `demand_matches`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `demand_id` | `bigint unsigned` | لا | — | MUL |
| `offer_id` | `bigint unsigned` | لا | — | MUL |
| `buyer_organization_id` | `bigint unsigned` | لا | — | MUL |
| `seller_organization_id` | `bigint unsigned` | لا | — | MUL |
| `requested_quantity` | `decimal(14,2)` | لا | — | — |
| `match_score` | `decimal(5,2)` | لا | `0.00` | — |
| `match_reasons` | `json` | نعم | — | — |
| `estimated_total` | `decimal(18,4)` | لا | `0.0000` | — |
| `status` | `varchar(30)` | لا | `ACTIVE` | — |
| `expires_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

### النزاعات وسجلات التدقيق (Disputes & Audit)

#### `disputes`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `dispute_number` | `varchar(50)` | لا | — | UNI |
| `deal_id` | `bigint unsigned` | لا | — | MUL |
| `initiator_organization_id` | `bigint unsigned` | لا | — | MUL |
| `initiator_user_id` | `bigint unsigned` | لا | — | MUL |
| `reason` | `varchar(100)` | لا | — | — |
| `description` | `text` | لا | — | — |
| `claimed_amount` | `decimal(18,4)` | لا | — | — |
| `evidence_urls` | `json` | نعم | — | — |
| `status` | `varchar(30)` | لا | `OPEN` | — |
| `resolution_notes` | `text` | نعم | — | — |
| `resolved_by_user_id` | `bigint unsigned` | نعم | — | MUL |
| `resolved_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `audit_logs`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `organization_id` | `bigint unsigned` | نعم | — | MUL |
| `user_id` | `bigint unsigned` | نعم | — | MUL |
| `action` | `varchar(100)` | لا | — | — |
| `auditable_type` | `varchar(100)` | نعم | — | MUL |
| `auditable_id` | `bigint unsigned` | نعم | — | — |
| `ip_address` | `varchar(45)` | نعم | — | — |
| `user_agent` | `text` | نعم | — | — |
| `old_values` | `json` | نعم | — | — |
| `new_values` | `json` | نعم | — | — |
| `created_at` | `timestamp` | لا | `CURRENT_TIMESTAMP` | — |

### منصة الإعداد والميزات (Platform Configuration)

#### `system_settings`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `key` | `varchar(255)` | لا | — | — |
| `value` | `text` | نعم | — | — |
| `type` | `varchar(20)` | لا | `string` | — |
| `group` | `varchar(50)` | لا | `platform` | MUL |
| `description_ar` | `varchar(255)` | نعم | — | — |
| `description_en` | `varchar(255)` | نعم | — | — |
| `default_value` | `text` | نعم | — | — |
| `is_editable` | `tinyint(1)` | لا | `1` | — |
| `is_public` | `tinyint(1)` | لا | `0` | — |
| `validation_rules` | `json` | نعم | — | — |
| `organization_id` | `bigint unsigned` | نعم | — | MUL |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `feature_flags`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `key` | `varchar(255)` | لا | — | UNI |
| `name_ar` | `varchar(255)` | لا | — | — |
| `name_en` | `varchar(255)` | لا | — | — |
| `description_ar` | `text` | نعم | — | — |
| `description_en` | `text` | نعم | — | — |
| `is_enabled` | `tinyint(1)` | لا | `1` | MUL |
| `rules_json` | `json` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `currencies`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `iso_code` | `varchar(3)` | لا | — | UNI |
| `name_ar` | `varchar(255)` | لا | — | — |
| `name_en` | `varchar(255)` | لا | — | — |
| `symbol` | `varchar(10)` | لا | — | — |
| `decimal_places` | `tinyint unsigned` | لا | `2` | — |
| `exchange_rate_to_base` | `decimal(18,6)` | لا | `1.000000` | — |
| `rate_source` | `varchar(100)` | نعم | — | — |
| `rate_updated_at` | `timestamp` | نعم | — | — |
| `rate_updated_by_user_id` | `bigint unsigned` | نعم | — | MUL |
| `is_active` | `tinyint(1)` | لا | `1` | MUL |
| `is_default` | `tinyint(1)` | لا | `0` | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

### الحركات والمفاتيح والإشعارات (Movements, Idempotency, Notifications)

#### `inventory_movements`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `organization_id` | `bigint unsigned` | لا | — | MUL |
| `warehouse_id` | `bigint unsigned` | لا | — | MUL |
| `product_variant_id` | `bigint unsigned` | لا | — | MUL |
| `offer_id` | `bigint unsigned` | نعم | — | MUL |
| `deal_id` | `bigint unsigned` | نعم | — | MUL |
| `user_id` | `bigint unsigned` | نعم | — | MUL |
| `type` | `varchar(30)` | لا | — | MUL |
| `quantity` | `decimal(14,2)` | لا | — | — |
| `previous_quantity` | `decimal(14,2)` | لا | `0.00` | — |
| `new_quantity` | `decimal(14,2)` | لا | `0.00` | — |
| `reference_type` | `varchar(50)` | نعم | — | — |
| `reference_id` | `varchar(100)` | نعم | — | — |
| `reason` | `text` | نعم | — | — |
| `metadata` | `json` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `idempotency_keys`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `key` | `varchar(128)` | لا | — | MUL |
| `user_id` | `bigint unsigned` | نعم | — | MUL |
| `organization_id` | `bigint unsigned` | نعم | — | MUL |
| `endpoint` | `varchar(255)` | لا | — | — |
| `request_hash` | `varchar(64)` | لا | — | — |
| `response_code` | `int` | لا | — | — |
| `response_body` | `longtext` | لا | — | — |
| `expires_at` | `timestamp` | لا | — | MUL |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `notifications`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `user_id` | `bigint unsigned` | نعم | — | MUL |
| `organization_id` | `bigint unsigned` | نعم | — | MUL |
| `title_ar` | `varchar(255)` | لا | — | — |
| `title_en` | `varchar(255)` | نعم | — | — |
| `body_ar` | `text` | لا | — | — |
| `body_en` | `text` | نعم | — | — |
| `channel` | `varchar(30)` | لا | `IN_APP` | — |
| `type` | `varchar(50)` | لا | `SYSTEM` | — |
| `data` | `json` | نعم | — | — |
| `is_read` | `tinyint(1)` | لا | `0` | — |
| `read_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `support_tickets`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `reference` | `varchar(20)` | نعم | — | UNI |
| `user_id` | `bigint unsigned` | لا | — | MUL |
| `organization_id` | `bigint unsigned` | نعم | — | MUL |
| `category` | `varchar(40)` | لا | — | — |
| `message` | `text` | لا | — | — |
| `screen` | `varchar(80)` | نعم | — | — |
| `context` | `json` | نعم | — | — |
| `status` | `varchar(20)` | لا | open | MUL |
| `reply` | `text` | نعم | — | — |
| `answered_by` | `bigint unsigned` | نعم | — | MUL |
| `answered_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | MUL |
| `updated_at` | `timestamp` | نعم | — | — |

### التكامل B2B (API Keys & Webhooks)

#### `api_keys`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `organization_id` | `bigint unsigned` | لا | — | MUL |
| `name` | `varchar(255)` | لا | — | — |
| `key_id` | `varchar(64)` | لا | — | UNI |
| `key_secret_hash` | `varchar(64)` | لا | — | — |
| `scopes` | `json` | لا | — | — |
| `status` | `varchar(20)` | لا | `ACTIVE` | — |
| `last_used_at` | `timestamp` | نعم | — | — |
| `expires_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `webhooks`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `organization_id` | `bigint unsigned` | لا | — | MUL |
| `endpoint_url` | `varchar(500)` | لا | — | — |
| `secret` | `varchar(128)` | لا | — | — |
| `events` | `json` | لا | — | — |
| `is_active` | `tinyint(1)` | لا | `1` | — |
| `failure_count` | `int unsigned` | لا | `0` | — |
| `last_triggered_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `webhook_deliveries`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `webhook_id` | `bigint unsigned` | لا | — | MUL |
| `event_name` | `varchar(100)` | لا | — | — |
| `event_id` | `varchar(64)` | لا | — | UNI |
| `payload` | `json` | لا | — | — |
| `signature` | `varchar(128)` | لا | — | — |
| `status` | `varchar(20)` | لا | `PENDING` | — |
| `response_status` | `smallint unsigned` | نعم | — | — |
| `response_body` | `text` | نعم | — | — |
| `attempt` | `tinyint unsigned` | لا | `1` | — |
| `last_error` | `text` | نعم | — | — |
| `delivered_at` | `timestamp` | نعم | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

### جداول Laravel الداخلية

#### `personal_access_tokens`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `tokenable_type` | `varchar(255)` | لا | — | MUL |
| `tokenable_id` | `bigint unsigned` | لا | — | — |
| `name` | `text` | لا | — | — |
| `token` | `varchar(64)` | لا | — | UNI |
| `abilities` | `text` | نعم | — | — |
| `last_used_at` | `timestamp` | نعم | — | — |
| `expires_at` | `timestamp` | نعم | — | MUL |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `migrations`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `int unsigned` | لا | — | PRI |
| `migration` | `varchar(255)` | لا | — | — |
| `batch` | `int` | لا | — | — |

#### `role_has_permissions`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `permission_id` | `bigint unsigned` | لا | — | PRI |
| `role_id` | `bigint unsigned` | لا | — | PRI |

#### `permissions`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `name` | `varchar(255)` | لا | — | MUL (`permissions_name_guard_name_unique` مع `guard_name`) |
| `guard_name` | `varchar(255)` | لا | — | — |
| `scope` | `varchar(16)` | لا | `business` | MUL (`permissions_scope_index`) |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

> `scope` عمود هذا المشروع لا حقول Spatie، ومصدره `config('rbac.permission_scopes')` الذي يغطّي
> الكتالوج تغطية كاملة بلا تكرار (`platform` ١٠ و`business` ٢٥ و`both` ١٠ من ٤٥ اسمًا). لا يُقرأ عند
> القرار — الدالة `RbacScope` هي التي تحكم — لكنه يبقى في القاعدة حتى يُسأل «أي صفوف المنصّة علقت
> بحساب؟» بجملة واحدة بدل قائمة أسماء مكتوبة في نصّ.

#### `model_has_roles`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `role_id` | `bigint unsigned` | لا | — | PRI |
| `model_type` | `varchar(255)` | لا | — | PRI |
| `model_id` | `bigint unsigned` | لا | — | PRI |

#### `roles`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `id` | `bigint unsigned` | لا | — | PRI |
| `name` | `varchar(255)` | لا | — | MUL |
| `guard_name` | `varchar(255)` | لا | — | — |
| `created_at` | `timestamp` | نعم | — | — |
| `updated_at` | `timestamp` | نعم | — | — |

#### `model_has_permissions`

| الحقل | النوع | NULL | الافتراضي | مفتاح |
| :--- | :--- | :---: | :--- | :---: |
| `permission_id` | `bigint unsigned` | لا | — | PRI |
| `model_type` | `varchar(255)` | لا | — | PRI |
| `model_id` | `bigint unsigned` | لا | — | PRI |


---

## 3. تمرير التطبيع (3NF) — 2026-09-25

> **Why this section exists and the tables above do not carry it.** Section 2 is a dump of what the
> database enforces. What follows is why three columns were *removed* from that enforcement and
> nine keys were added, and — just as important — which duplications were left exactly where they
> are. A reader who wants the schema reads §2; a reader who wants to know whether a redundant column
> is a bug or a decision reads this.

### ما أُزيل

| العمود | سبب الإزالة |
| :--- | :--- |
| `deals.transaction_currency` | نسخة طبق الأصل من `deals.currency_code`: كان `PricingAndCurrencyService` يكتب الحقيقةَ نفسها في عمودين، فلا حالةً في الكود يُجيب فيها أحدهما بغير ما أجاب الآخر. الاسم الباقي هو `currency_code` — وهو الاسم الذي تستعمله كل جدولٍ آخر في القاعدة. |
| `deals.exchange_rate` | نسخة من `deals.exchange_rate_snapshot`، والاسم الثاني هو ما تصفّه بقية الجداول (`tax_rate_snapshot`, `exchange_rate_snapshot`). عمودان لسعرٍ واحد يعنيان أن تصحيحاً لاحقاً يجب أن يكتب مرتين أو يُخطئ. |
| `offers.category_id` | **مشتَقّ لا مكرَّر**: تُحدّده `offers.product_variant_id ← product_variants.product_id ← products.category_id`. الحقيقة المشتقة المخزَّنة لا تُخلِّف تكراراً بل خلافاً، وقد وُجدت عروضٌ كتبت صنفاً غير صنف منتَجها. |

الهجرة الوسيطة `2026_09_25_100100_drop_derived_category_from_offers.php` لا تحذف على عمى: تعدّ
الاتفاقات والاختلافات بين `offers.category_id` وصنف المنتج أولاً، وترمي `RuntimeException` إن
اختلفت صُفرةٌ واحدة، فتُترك للمسؤول قرارُ أي الرقمين كان صحيحاً. وبعد الحذف بقي العقد كما عرفه
العملاء: `category_id` يُجاب في الاستجابة نفسها (`OfferResource`، و`$appends` على النموذج للقراءات
التي تُرجع النموذج الخام مباشرة)، والمرشّح `?category_id=` يعمل بـ
`whereHas('variant.product')`، و`store()` ما زال يرفض `CATEGORY_PRODUCT_MISMATCH` بدل أن ينسخ صنف
المنتج سرّاً — الرفض هو الجواب الأمين لأن البائع سمّى صنفاً والمنتَج في غيره.

### ما تُرِك عامداً، وهو ليس إهمالاً

- **`deals.currency_code` + `deals.base_currency` + `deals.exchange_rate_snapshot` +
  `rate_source`/`rate_timestamp`/`base_amount`: لقطةٌ تجاريّة، لا تطبيعٌ ناقص.** سعر الصرف يومَ
  أُبرم الصفقة ليس دالةً على رمز العملة؛ هو ما قالته المنصّة في تلك اللحظة، وإعادة اشتقاقه من
  `currencies.rate` اليومَ تُعيد تقييم صفقاتٍ موقَّعة. هذا العمود — وعمودا `rate_source` و
  `rate_timestamp` معه — هما الغاية من الجدول لا عيبٌ فيه.
- **`deal_items.snapshot_*` و`reservations.unit_price`:** لقطةٌ للسلعة نفسها (اسمها وسعرها يومَ
  بيعت)، بنفس العلة.
- **`offers.unit`** نصٌّ حرٌّ يقوله البائع («طن»، «قيراط»، «حزمة»): لا مفردات على المنصّة تحمل هذه
  الأسماء حتى يُشار إليها بمفتاح، و`currencies` عملةٌ لا عيار.
- **`offer_items.product_id`:** منسوخٌ من صنف العرض عمداً، وموثَّقٌ في هجرة إنشائه.

### ما أُضيف: تسعة مفاتيح إلى `currencies`

`2026_09_25_100200_constrain_currency_codes_to_currencies.php` يربط `currency_code` بـ
`currencies.iso_code` في `deals` و`demands` و`journal_entries` و`ledger_accounts` و
`offer_items` و`offers` و`purchase_requests` و`quotations` و`reservations`.

- **قبل الربط** يعدّ السطور التي تسمّي عملةً لم تُعلَن قطّ، ويرفض إن وُجدت واحدة: المفتاح الذي
  يُضاف فوق بيانات مكسورة يحوّل كسراً قديماً إلى عطلٍ في الترقية.
- **`restrictOnDelete`**: العملة التي استعملها أحد الدفاتر لا تُحذف، بل تُوقَف. التاريخ يبقى.
- **`cascadeOnUpdate`**: تصحيح رمزٍ إداريٍّ نادرٌ ويجب أن يمشي معه كل سجلّ.
- ما لم يكن يوماً عموداً في `currencies` بقي هناك وحده: الرمز، عدد الخانات العشرية، السعر — لا
  تكرار لشيءٍ منها في أي جدولٍ آخر.

### الاختبار

`backend/tests/Feature/DatabaseNormalizationTest.php` (٥ اختبارات): صنفُ العرض يتبع منتَجَه عند
الكتابة والقائمة والتفصيل والمرشّح، وعدمُ التطابق يُرفض بـ 422، وعملةٌ لم تُعلَن لا تحمل مالاً
(`QueryException`)، ومجموعةُ الجداول الحاملة للعملة تساوي مجموعةَ المحروسة بالأداة نفسها، وصفقةٌ
تسمّي عملتَها وسعرَها مرةً واحدة. Suite كامل (2026-10-01): **392 اختباراً / 3162 تأكيداً**.

> بيتُ الكتابة في هذا المشروع أن تُصحَّح هجرةُ الإنشاء نفسها مع الهجرة اللاحقة؛ لذلك حُذف
> `offers.category_id` من `2026_09_15_130011_create_offers_and_items_tables.php` و
> `transaction_currency`/`exchange_rate` من
> `2026_09_15_130013_create_deals_and_items_and_histories_tables.php` في مصدرهما، حتى يكون قاعدةٌ
> تُبنى من الصفر مطابقةً لقاعدةٍ مُرقَّاة إلى اليوم.
