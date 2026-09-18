# منصة حصّال (HASSAL) — نموذج النطاق وحدود الأعمال (DOMAIN_MODEL.md)

## 1. حدود النطاقات الفرعية (Bounded Contexts)

تم تقسيم نظام **حصّال** إلى نطاقات فرعية مستقلة ومترابطة تحترم مبدأ المسؤولية الفردية وتمنع تداخل المسؤوليات:

```
+-------------------------------------------------------------------------+
|                         IDENTITY & MEMBERSHIP                           |
|  User | Organization | OrganizationMembership | Role | Permission       |
+-------------------------------------------------------------------------+
                                    |
                                    v
+-----------------------+   +-----------------------+   +-----------------+
|        CATALOG        |   |      WAREHOUSES       |   |    SETTINGS     |
| Category | Product    |   | Warehouse | Location  |   | Setting | Flag  |
| Variant  | Brand/Unit |   | Geofence  | Capacity  |   | Currency Rate   |
+-----------------------+   +-----------------------+   +-----------------+
            \                       /
             v                     v
+-------------------------------------------------------------------------+
|                           OFFERS & INVENTORY                            |
| Offer | InventoryLedger | PriceSchedule | GeoLocation                   |
+-------------------------------------------------------------------------+
                                    |
                                    v
+-------------------------------------------------------------------------+
|                       ORDERING & DEALS LIFECYCLE                        |
| PurchaseRequest | Deal (Snapshot) | DealItem | StatusTransitionLog      |
+-------------------------------------------------------------------------+
            /                       |                       \
           v                        v                        v
+--------------------+    +--------------------+    +--------------------+
|      PAYMENTS      |    |      DELIVERY      |    |      DISPUTES      |
| Payment | Provider |    | Shipment | Address |    | Dispute | Evidence |
| Refund  | Fee      |    | Pickup   | Courier |    | Message | Decision |
+--------------------+    +--------------------+    +--------------------+
           \                        |                        /
            ------------------------+------------------------
                                    |
                                    v
+-------------------------------------------------------------------------+
|                       LEDGER & FINANCIAL AUDIT                          |
| LedgerAccount | LedgerEntry | SettlementBatch | CommissionCalculation   |
+-------------------------------------------------------------------------+
```

---

## 2. الكيانات الجوهرية وقواعد النزاهة (Core Entities & Invariants)

### 2.1 هوية المستخدم والمؤسسة (Identity & Tenancy Invariant)
- **`User`:** يمثل الفرد صاحب الحساب الشخصي (رقم الجوال، البريد الإلكتروني، الاسم، كلمة المرور المشفرة). لا يحمل `User` أي سمات تجارية أو سجلات تجارية مباشرة.
- **`Organization`:** الكيان التجاري القانوني (شركة، مؤسسة، مصنع، شبكة بيع) وله سجل تجاري (`cr_number`)، ورقم ضريبي (`tax_number`)، وحالة توثيق (`verification_status`).
- **`OrganizationMembership`:** حلقة الوصل التي تحدد دور المستخدم في تلك المؤسسة (مالك، مدير، مسؤول مشتريات، أمين مستودع).
- **قاعدة النزاهة:** لا يمكن لأي مستخدم إجراء عملية تجارية (طرح عرض، طلب شراء، قبول صفقة) دون تحديد سياق مؤسسة نشطة ومحققة الصلاحية.

### 2.2 دليل المنتجات والأنواع (Catalog Invariants)
- **`Product`:** المفهوم العام للمنتج (مثال: "أرز بسمتي أبيض سيلا").
- **`ProductVariant`:** الوحدة القابلة للتداول الفعلي والبيع والتي تمتلك باركود وSKU وحجم ووزن محدد (مثال: "كيس 10 كجم", "كرتون 4 × 5 كجم").
- **قاعدة النزاهة:** العروض التجارية تُطرح دائماً على مستوى الـ `ProductVariant` وليس على اسم المنتج العام.

### 2.3 العرض التجاري (Offer Invariant)
- يمثل رغبة مؤسسة بائعة في بيع كمية محددة من `ProductVariant` من مستودع محدد بشروط وسعر محددين.
- **الحقول الأساسية:**
  - `seller_organization_id`: المؤسسة البائعة.
  - `product_variant_id`: المنتج الدقيق.
  - `warehouse_id`: موقع البضاعة وتخزينها.
  - `available_quantity`: الكمية المتاحة للشراء.
  - `reserved_quantity`: الكمية المحجوزة في طلبات جارية.
  - `sold_quantity`: الكمية المباعة والمكتملة.
  - `unit_price`: سعر الوحدة بالعملة المحددة (Decimal 14,2).
  - `min_order_quantity` و `max_order_quantity`.
  - `currency`: العملة المعتمدة (SAR, YER, USD, AED).
  - `reason_type`: تصنيف الفرصة (فائض مخزون، تصفية خط إنتاج، اقتراب مواسم، وفرة توريد، إعادة تموضع المنتجات).

### 2.4 طلب الشراء وحجز المخزون (Purchase Request Invariant)
- وثيقة الطلب الصادرة من المشتري لشراء كمية $Q$ من العرض.
- **قاعدة النزاهة الذرية:**
  $$\text{Requested Quantity} \le \text{Offer.available\_quantity}$$
  يتم حجز الكمية فوراً بتخفيض المتاح وزيادة المحجوز مع وضع توقيت انتهاء للحجز (`reserved_until`). إذا رفض البائع أو انتهت المهلة، تعود الكمية فوراً للمتاح.

### 2.5 الصفقة التجارية (Deal Invariant & Snapshot)
- تنشأ الصفقة حصراً عند قبول البائع لطلب الشراء.
- **مبدأ تجميد البيانات (Immutability Snapshot):**
  تحتوي الصفقة على لقطة كاملة لا تتأثر بأي تعديل لاحق على العرض أو المنتج أو نسب الضرائب أو العمولات:
  - `snapshot_product_title`: اسم المنتج ومواصفاته لحظة الصفقة.
  - `snapshot_unit_price`: سعر الوحدة المعتمد.
  - `snapshot_tax_rate`: نسبة الضريبة المعتمدة وقت إبرام الصفقة.
  - `snapshot_commission_rate`: نسبة عمولة المنصة وقت إبرام الصفقة.
  - `subtotal_amount`, `tax_amount`, `commission_amount`, `total_amount`.

---

## 3. آلات الحالة ودورات الحياة (State Machines)

### 3.1 دورة حياة العرض التجاري (Offer State Machine)

```
 [ مسودة: DRAFT ] 
        |
        v
 [ قيد المراجعة: PENDING_REVIEW ] 
        |
        +-----------------------------------> [ مرفوض: REJECTED ]
        | (موافقة الإدارة / تلقائي حسب الإعدادات)
        v
 [ نَشِط: ACTIVE ] <-----------------+
   |            \                      |
   | (إيقاف مؤقت) \ (حجز كافة الكمية)  | (إعادة تفعيل / زيادة كمية)
   v              v                    |
 [ متوقف: PAUSED ] [ نفد: SOLD_OUT ] -+
   |              |
   |              +---> [ منتهي: EXPIRED ] (بحلول تاريخ الصلاحية)
   v
 [ ملغي: CANCELLED ] 
        |
        v
 [ مؤرشف: ARCHIVED ]
```

**قواعد الانتقال:**
- لا يمكن الحجز أو الشراء من العرض إلا عندما تكون حالته `ACTIVE`.
- إذا أصبحت `available_quantity == 0` يتحول العرض تلقائياً إلى `SOLD_OUT`.
- إذا تم تزويد الكمية في مستودع العرض يتحول من `SOLD_OUT` إلى `ACTIVE`.

---

### 3.2 دورة حياة الصفقة التجارية (Deal State Machine)

```
   [ قيد الموافقة: REQUESTED ]
              |
              +---------------------------> [ مرفوض / ملغي: CANCELLED ]
              | (موافقة البائع)                 (إعادة المخزون فوراً)
              v
   [ مقبولة / بانتظار الدفع: PAYMENT_PENDING ]
              |
              +---------------------------> [ انتهاء المهلة: EXPIRED ]
              | (تأكيد الدفع أو سند التحويل)     (إعادة المخزون فوراً)
              v
   [ تم الدفع: PAID ]
              |
              v
   [ جاري التجهيز بالمستودع: PREPARING ]
              |
              +----------------------------+
              | (استلام من المستودع)       | (شحن وتوصيل)
              v                            v
   [ جاهز للاستلام: READY_FOR_PICKUP ]   [ تم الشحن: SHIPPED ]
              \                            /
               \                          /
                v                        v
          [ تم التسليم للعميل: DELIVERED ]
                        |
                        v
          [ تم تأكيد الفحص والاستلام: RECEIVED ]
                        |
                        +------------------> [ نزاع قائم: DISPUTED ]
                        | (انقضاء مهلة الفحص     |
                        |  أو قبول صريح)         v
                        |              [ تسوية / استرداد / رفض ]
                        v
              [ مكتملة ومسواة: COMPLETED ]
```

---

### 3.3 دورة حياة الدفع المالي (Payment State Machine)

```
 [ قيد الإنشاء: INITIATED ]
            |
            v
 [ بانتظار التحصيل: PENDING ]
      /                  \
     v                    v
[ ناجح: SUCCESS ]     [ متعثر: FAILED ]
      |
      +-------------------------> [ مسترد جزئياً: PARTIALLY_REFUNDED ]
      |
      v
[ مسترد كلياً: REFUNDED ]
```

---

### 3.4 دورة حياة النزاع التجاري (Dispute State Machine)

```
 [ مفتوح: OPENED ]
        |
        v
 [ قيد المراجعة والتحكيم: UNDER_REVIEW ]
        |
        +-----------------------------> [ طلب بينات إضافية: EVIDENCE_REQUIRED ]
        |                                             |
        v                                             |
 [ صدور قرار التحكيم: RESOLVED ] <---------------------+
   |
   +---> إرجاع كامل للمشتري (REFUND_BUYER)
   +---> رفض النزاع وإطلاق المبلغ للبائع (REJECT_DISPUTE)
   +---> تسوية جزئية مقسمة (PARTIAL_SETTLEMENT)
   |
   v
 [ مغلق وموثق: CLOSED ]
```

---

## 4. مصفوفة الصلاحيات والأدوار (RBAC Matrix)

تعتمد المنصة على Spatie Laravel Permission مع ربط الصلاحيات بسياق العضوية بالمؤسسة:

| الصلاحية (Permission) | System Admin | Org Owner | Org Manager | Buyer Specialist | Warehouse Officer |
| :--- | :---: | :---: | :---: | :---: | :---: |
| `manage_system_settings` | ✅ | ❌ | ❌ | ❌ | ❌ |
| `manage_feature_flags` | ✅ | ❌ | ❌ | ❌ | ❌ |
| `arbitrate_disputes` | ✅ | ❌ | ❌ | ❌ | ❌ |
| `verify_organizations` | ✅ | ❌ | ❌ | ❌ | ❌ |
| `manage_organization_profile` | ❌ | ✅ | ✅ | ❌ | ❌ |
| `manage_organization_members` | ❌ | ✅ | ❌ | ❌ | ❌ |
| `manage_warehouses` | ❌ | ✅ | ✅ | ❌ | ❌ |
| `create_offers` | ❌ | ✅ | ✅ | ❌ | ❌ |
| `publish_offers` | ❌ | ✅ | ✅ | ❌ | ❌ |
| `view_offers` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `create_purchase_requests` | ❌ | ✅ | ✅ | ✅ | ❌ |
| `accept_reject_deals` | ❌ | ✅ | ✅ | ❌ | ❌ |
| `confirm_order_preparation` | ❌ | ✅ | ✅ | ❌ | ✅ |
| `confirm_goods_dispatch` | ❌ | ✅ | ✅ | ❌ | ✅ |
| `confirm_goods_receipt` | ❌ | ✅ | ✅ | ✅ | ✅ |
| `view_financial_ledger` | ✅ | ✅ | ❌ | ❌ | ❌ |
| `open_deal_dispute` | ❌ | ✅ | ✅ | ✅ | ❌ |

---

## 5. كتالوج أحداث النظام (Domain Events Catalog)

1. `OfferCreatedEvent` & `OfferActivatedEvent`: تحديث محرك البحث وتنبيه المشترين المتابعين للصنف.
2. `PurchaseRequestedEvent`: حجز المخزون ذرياً وإشعار البائع بالطلب الجديد.
3. `DealAcceptedEvent`: تثبيت الصفقة وإنشاء سجل اللقطة (Deal Snapshot) والبدء في مهلة السداد.
4. `PaymentConfirmedEvent`: تغيير حالة الصفقة إلى `PAID`، وتوليد أوامر التجهيز للمستودع، وتسجيل القيد في دفتر الأستاذ.
5. `GoodsDispatchedEvent`: توليد كود الاستلام والشحن وإشعار المشتري بالمتابعة.
6. `GoodsReceivedEvent`: بدء نافذة فحص البضاعة وحمايتها قبل تحرير المستحقات للبائع.
7. `DealCompletedEvent`: إطلاق مستحقات البائع واحتساب عمولة المنصة في جدول التسويات.
8. `DisputeOpenedEvent`: تجميد المبالغ المالية للصفقة في دفتر الأستاذ وإشعار الإدارة للتحكيم.
