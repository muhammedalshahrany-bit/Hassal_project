# HASSAL Platform - Production Migration Guide
**دليل الترحيل التقني الشامل لمنصة حصّال إلى بنية Laravel 13 و MySQL 8.4**

---

## ملخص الترحيل | Migration Overview
تم تحويل النظام من نموذج المعاينة / النموذج الهجين (Node.js router logic + SQLite) إلى بنية إنتاجية كاملة:
1. **قاعدة البيانات**: الانتقال الكامل إلى **MySQL 8.4 LTS** بمحرك InnoDB وترميز `utf8mb4_unicode_ci` ومفاتيح أجنبية صارمة ودقة حسابية `DECIMAL(18, 4)`.
2. **منطق الأعمال**: ترحيل كافة عمليات الحجز الذري، والصفقات، والضمان المالي، والدفتر المالي المزدوج، والتحقق من المنشآت إلى خدمات ونماذج Laravel (`App\Services`, `App\Models`, `App\Http\Controllers`).
3. **التكامل مع تطبيقات الهواتف الذكية (Flutter)**: توفير نقطة النهاية `/api/v1/bootstrap` الموحدة مع دعم كامل لمواصفات OpenAPI 3.1.
4. **الأمان والصلاحيات**: دمج Spatie Laravel Permission و Laravel Sanctum وحماية العمليات بمفاتيح `Idempotency-Key` وتواقيع Webhook HMAC-SHA256.

---

## خطوات تنفيذ الترحيل في بيئة الإنتاج | Execution Steps

### 1. إعداد قاعدة بيانات MySQL 8.4
```sql
CREATE DATABASE hassal_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hassal_app'@'localhost' IDENTIFIED BY 'YOUR_SECURE_PASSWORD';
GRANT ALL PRIVILEGES ON hassal_production.* TO 'hassal_app'@'localhost';
FLUSH PRIVILEGES;
```

### 2. تفعيل أمر التثبيت الشامل
```bash
cd /var/www/hassal/backend
php artisan app:install-system --force
```

### 3. التحقق من تكامل الخدمات
```bash
php artisan test
```
