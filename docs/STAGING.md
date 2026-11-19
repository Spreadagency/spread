# نسخة Staging للتجربة + فحص ZAP + اختبار الموقع (MCP المرحلة 8)

**Staging** = نسخة طبق الأصل من الموقع على دومين فرعي، زي `staging.ai.spreadagency.net`. بتجرّب فيها التحديثات والفحوصات قبل ما توصل للعملاء.

| | |
|---|---|
| الوقت | حوالي ساعة أول مرة |
| التكلفة | ولا حاجة زيادة (نفس الاستضافة) |
| القاعدة | **الموقع الأساسي مايتلمسش.** Staging بقاعدة بيانات منفصلة وملفات منفصلة |

---

## 1) الدومين الفرعي
cPanel ← **Domains** (أو Subdomains) ← **Create**:

| الحقل | القيمة |
|---|---|
| الدومين | `staging.ai.spreadagency.net` |
| Document Root | `staging_spread` (فولدر جديد، **مش** فولدر الموقع) |

وبعدين cPanel ← **SSL/TLS Status** ← Run AutoSSL، علشان يبقى عليه HTTPS.

## 2) قاعدتين بيانات جداد
cPanel ← **MySQL Databases**:
1. اعمل قاعدتين: `…_staging` و `…_staging_ai`.
2. اعمل مستخدم جديد بباسورد قوي.
3. اديله **ALL PRIVILEGES** على القاعدتين دول بس.

## 3) نسخ البيانات
cPanel ← **phpMyAdmin**:
1. القاعدة الأساسية ← **Export** ← Quick ← SQL ← نزّل الملف.
2. القاعدة `…_staging` ← **Import** ← الملف.
3. نفس الكلام لقاعدة الـ AI ← `…_staging_ai`.

⚠️ **البيانات دي فيها بيانات عملاء حقيقية.** بعد الاستيراد مباشرة، من phpMyAdmin على **قواعد الـ staging بس** (اتأكد من اسم القاعدة فوق قبل ما تدوس Go):
```sql
-- إيميلات وأرقام وهمية علشان مفيش إيميل أو واتساب يوصل لعميل حقيقي من الـ staging
UPDATE users SET email = CONCAT('user', id, '@staging.invalid'), phone = NULL, whatsapp_phone = NULL, whatsapp_opt_in = 0;
-- قفل النشر على صفحات العملاء (الربط بيبقى «ملغي» والتوكن بيتشال)
UPDATE social_connections SET status = 'revoked', access_token_enc = '';
```
> جدول `users` ده للعملاء بس. حسابات الأدمن في جدول منفصل ومش بتتأثر.
> المهم: **مفيش تواصل حقيقي مع العملاء من الـ staging.**

## 4) الملفات
1. cPanel ← File Manager ← انسخ كل ملفات الموقع لـ `staging_spread`. أو ارفع حزمة الموقع وفكّها هناك.
2. **ماتنسخش** `storage/uploads` لو كبير. الصور القديمة مش هتظهر بس، وده عادي.
3. عدّل `staging_spread/includes/config.php`:

```php
define('APP_URL', 'https://staging.ai.spreadagency.net');
define('DB_NAME', '…_staging');        // + DB_USER و DB_PASS بتوع مستخدم الـ staging
define('MCP_AI_DB_NAME', '…_staging_ai');
define('APP_DEBUG', false);
// توكن مختلف عن الموقع الأساسي (openssl rand -hex 32):
define('INTERNAL_API_TOKEN', '…');
// ENCRYPTION_KEY: نفس بتاع الموقع الأساسي، وإلا مفاتيح الـ API المتخزنة مش هتتفك
// MCP_DB_*_USER / PASS: سيبهم فاضيين — خادم الـ MCP مربوط بالموقع الأساسي بس، ومستخدمين MySQL بتوعه مالهمش صلاحية على قواعد الـ staging
```
وبعدين من أدمن الـ staging ← **التكاملات** ← غيّر **سر الـ cron** لقيمة جديدة. ولو هتشغّل cron للـ staging، خليه بأمر منفصل بالسر الجديد.

4. **مفاتيح الـ AI:** الأحسن تحط مفتاح منفصل بحد صرف صغير (من لوحة المزوّد)، علشان التجارب ماتاكلش من رصيد الموقع.
5. **الدفع:** خلّي بوابات الدفع على وضع **Test/Sandbox**، أو اقفلها خالص.

## 5) حسابات الاختبار
من الأدمن على الـ staging:
- **عميل اختبار:** إيميل زي `qa-client@staging.invalid` وباسورد قوي، وادّيله باقة.
- **أدمن اختبار:** بدور بصلاحيات **مشاهدة** بس.

دول اللي هتحطهم في `/opt/spread-mcp/checks.env` على الـ VPS.

## 6) قفل الـ staging عن جوجل والناس
- الأدمن على الـ staging ← SEO ← **منع الفهرسة** (noindex). أو حط ملف `robots.txt` فيه `Disallow: /`.
- (اختياري) cPanel ← **Directory Privacy** على `staging_spread` (باسورد للفولدر).
  ⚠️ لو عملت كده، ZAP و Playwright مش هيعرفوا يدخلوا، فاستخدمها بس لو هتفحص الموقع الأساسي.

---

## 7) الفحوصات على الـ Staging من الـ VPS

لو الـ VPS متركّب (`docs/DEPLOY.md`):
```bash
sudo bash /opt/spread-mcp/app/deploy/install.sh checks staging.ai.spreadagency.net
sudo nano /opt/spread-mcp/checks.env          # حسابات الاختبار من خطوة 5
sudo systemctl start spread-mcp-checks        # تشغيل دلوقتي
journalctl -u spread-mcp-checks -n 80 --no-pager
```

⚠️ **النتايج بتتبعت للموقع الأساسي.** خادم الـ MCP متوصّل بـ `INTERNAL_API_URL` بتاع الموقع الأساسي، والنتايج بتظهر في «الأمان» هناك. ده المطلوب: بتشوف صحة الكود قبل ما تنزّله.

### أو يدوي من أي جهاز (من غير VPS)

**ZAP baseline** (محتاج Docker):
```bash
docker run --rm -v "$PWD":/zap/wrk:rw ghcr.io/zaproxy/zaproxy:stable \
  zap-baseline.py -t https://staging.ai.spreadagency.net -J zap-report.json -m 3 -I
```

**اختبار الموقع** (محتاج Node 20+):
```bash
cd mcp-server
npm i --no-save playwright && npx playwright install chromium
E2E_BASE=https://staging.ai.spreadagency.net E2E_USER=… E2E_PASS=… E2E_ADMIN=… E2E_ADMIN_PASS=… \
  node checks/site-e2e.mjs
```

**npm audit:**
```bash
cd mcp-server && npm audit --json > npm-audit.json
```

**إدخال النتايج:** لازم من جهاز الـ IP بتاعه مسموح، يعني الـ VPS:
```bash
cd /opt/spread-mcp/app
sudo -u spreadmcp node scripts/push-checks.mjs zap /path/zap-report.json
sudo -u spreadmcp node scripts/push-checks.mjs e2e /path/e2e-results.json
sudo -u spreadmcp node scripts/push-checks.mjs npm /path/npm-audit.json
```

أو على سيرفر الموقع نفسه (cPanel ← Terminal):
```bash
php tests/security/import-findings.php zap zap-report.json
php tests/security/import-findings.php npm npm-audit.json
php tests/e2e/record.php e2e-results.json
```

---

## 8) قبل كل تحديث للموقع الأساسي
1. نزّل الحزمة الجديدة على الـ **staging** الأول (نفس خطوات `docs/CPANEL_UPDATE.md`).
2. الأدمن على الـ staging ← **فحص التحديث** ← شغّل الترحيلات.
3. `sudo systemctl start spread-mcp-checks` ← استنى، وشوف «الأمان ← اختبارات الموقع»: لازم 100% ✓.
4. جرّب بإيدك أهم 3 حاجات: تسجيل · إنشاء منشور · الدفع (Test).
5. تمام؟ نزّل نفس الحزمة على الموقع الأساسي.

## 9) تحديث بيانات الـ staging (كل شهر مثلًا)
كرر خطوة 3: Export من الأساسي ← Import على الـ staging ← تنظيف البيانات الحساسة. **أوعى تعمل العكس أبدًا** (من الـ staging للأساسي).
