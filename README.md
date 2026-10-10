# Spread AI — النسخة الكاملة (v2.0)

منصة توليد منشورات السوشيال ميديا بالذكاء الاصطناعي — PHP Vanilla + MySQL — جاهزة لاستضافة cPanel.

هذه النسخة تشمل كل الإصلاحات (1-20) وكل المميزات الجديدة (21-29).

---

## التنصيب من الصفر (موقع جديد)

1. **قاعدة البيانات:** أنشئ قاعدة بيانات utf8mb4 من cPanel، ثم شغّل `sql/schema.sql` من phpMyAdmin
   - الـ schema آمنة (بدون DROP، كلها IF NOT EXISTS)
   - لا يوجد أدمن افتراضي — أنشئه يدويًا (التعليمات داخل الملف)
2. **الإعدادات:** عدّل `includes/config.php`:
   - بيانات قاعدة البيانات (DB_HOST / DB_NAME / DB_USER / DB_PASS)
   - `APP_URL` بالدومين الصحيح
   - `AI_API_KEY` (مفتاح OpenRouter أو OpenAI) — لو فاضي المنصة تعمل بوضع Mock تجريبي
   - `MAIL_FROM` بإيميل على نفس الدومين
3. **الرفع:** ارفع كل الملفات لـ public_html (أو مجلد الدومين الفرعي)
4. **الصلاحيات:** تأكد أن `storage/` قابلة للكتابة (755 عادة كافية على cPanel)
5. **الكرون (للنشر المجدول):** من cPanel أضف Cron Job كل 5 دقايق:
   ```
   /usr/local/bin/php /home/USER/public_html/cron/publish-scheduled.php cron_secret=SECRET
   ```
   (حط نفس الـ SECRET في أدمن → التكاملات)

## الترقية من نسخة قديمة (الإنتاج الحالي)

شغّل ملفات SQL بالترتيب من phpMyAdmin (كلها آمنة للتشغيل المتكرر):
1. `sql/security-upgrade.sql`
2. `sql/performance-upgrade.sql`
3. `sql/features-upgrade.sql`

ثم ارفع كل ملفات PHP فوق القديمة.

## بعد التنصيب — إعدادات التكاملات (أدمن → التكاملات)

| التكامل | المطلوب |
|---|---|
| النشر على Meta | Facebook Page ID + Page Access Token طويل الأمد + IG Business User ID |
| الدفع Paymob | API Key + Integration ID + Iframe ID + HMAC Secret |
| Spread CRM | Webhook URL + Secret |
| موديلات AI | الموديل الاحتياطي + موديل الصور |
| الكرون | Cron Secret |

**رابط الـ callback في Paymob:** `https://YOUR-DOMAIN/paymob-callback.php` (Transaction response callback)

## تنبيهات أمنية مهمة

- غيّر باسورد قاعدة البيانات لو كانت النسخة القديمة اتشاركت مع أي حد
- غيّر باسورد الأدمن فور أول تسجيل دخول
- تأكد أن HTTPS شغال (الـ .htaccess بيجبره تلقائيًا)
- `APP_DEBUG` لازم تفضل `false` على الإنتاج

## بنية المشروع

```
/includes/        الملفات المشتركة (config, db, auth, ai, credits, integrations, rate-limit...)
/public/          صفحات المستخدم + /ajax/ معالجات JSON
/admin/           لوحة التحكم
/templates/       الهيدر/الفوتر/السايدبار
/storage/uploads/ الصور المرفوعة والتصميمات المولدة
/storage/logs/    السجلات (محمية — mail.log, ai.log, integrations.log)
/cron/            النشر المجدول
/sql/             schema + ملفات الترقية
```

## نظام الكريدت (قابل للتعديل من الأدمن)

- توليد محتوى: 1 كريدت
- إعادة توليد: 1 كريدت
- توليد تصميم: 2 كريدت
- الخصم يتم قبل التوليد مع استرداد تلقائي عند الفشل
