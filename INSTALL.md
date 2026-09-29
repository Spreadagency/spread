# ⚡ دليل التثبيت السريع — Spread AI

تثبيت في 5 خطوات على cPanel.

---

## 1. رفع الملفات

- ادخل **File Manager** في cPanel
- اذهب إلى `public_html/` (أو الفولدر اللي عايز تضع فيه التطبيق)
- ارفع الـ ZIP، ثم Extract

---

## 2. إنشاء قاعدة البيانات

في cPanel → **MySQL Databases**:

- ✅ أنشئ قاعدة بيانات جديدة (مثلاً `myaccount_spreadai`)
- ✅ أنشئ مستخدم جديد للقاعدة بباسورد قوي
- ✅ أضف المستخدم للقاعدة بكل الصلاحيات (`ALL PRIVILEGES`)

---

## 3. استيراد جداول قاعدة البيانات

في cPanel → **phpMyAdmin**:

- اختر القاعدة اللي عملتها
- اضغط **Import**
- اختر ملف `sql/schema.sql` من المشروع
- اضغط **Go**

---

## 4. تعديل ملف الإعدادات

افتح `includes/config.php` وعدّل:

```php
// عنوان الموقع (مع slash في النهاية)
define('APP_URL', 'https://yourdomain.com/');

// قاعدة البيانات
define('DB_HOST', 'localhost');
define('DB_NAME', 'myaccount_spreadai');
define('DB_USER', 'myaccount_user');
define('DB_PASS', 'YourStrongPassword123!');

// مفتاح OpenRouter (اختياري — للتوليد الحقيقي)
define('AI_API_KEY', 'sk-or-...');

// ⚠ في الإنتاج، خليها false
define('APP_DEBUG', false);
```

---

## 5. صلاحيات المجلدات

من File Manager → كليك يمين على المجلدات:

- `storage/` → 755
- `storage/uploads/` → 755
- `storage/uploads/logos/` → 755
- `storage/uploads/brand-images/` → 755
- `storage/logs/` → 755

---

## ✅ التحقق

- المستخدمين: `https://yourdomain.com/`
- الإدارة: `https://yourdomain.com/admin/login.php`
  - **Email:** `admin@spread-ai.local`
  - **Password:** `admin123`
  - ⚠ غيّر الباسورد فورًا

---

## 🚨 مشاكل شائعة

### "خطأ في الاتصال"
- بيانات الاتصال في `config.php` غلط، أو المستخدم ما عندوش صلاحيات

### "صفحة 500 Error"
- حقوق المجلد غلط (لازم 755)
- `mod_rewrite` مش مفعّل (تواصل مع الاستضافة)

### "الإيميل ما وصلش"
- في وضع `APP_DEBUG=true`: الإيميل بيتسجل في `storage/logs/mail.log`
- في الإنتاج: استخدم PHPMailer مع SMTP لاستضافتك

### "ما يطلعش محتوى"
- لو `AI_API_KEY` فاضي → الموقع بيشتغل بـ Mock Mode (محتوى تجريبي)
- ضع المفتاح من [openrouter.ai](https://openrouter.ai) أو [openai.com](https://platform.openai.com)

---

## 📞 الدعم

أي مشاكل، تواصل مع Spread Agency.
