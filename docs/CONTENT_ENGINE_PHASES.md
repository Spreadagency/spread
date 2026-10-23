# Content Engine v3: سجل المراحل

المراجعة الكاملة والخطة موجودين في `docs/CONTENT_ENGINE_AUDIT.md`، وخطوات الرفع في `docs/CPANEL_UPDATE.md`.

---

## المرحلة A: المراجعة ✅
المراجعة غطّت الأمان، وسلامة البيانات، وطبقة الـ AI والبرومبتات والتكلفة، والأداء. وطلّعت 13 مشكلة High و 15 Medium و 8 Low، والفرص، وتأثير الأقسام الجاية، والخطة، وتجميع الأدمن في 7 أقسام.

## المرحلة B: إصلاح High وأهم Medium ✅
الاختبار: `php tests/ce-b-fixes.php http://localhost:8080` (للتطوير المحلي بس) ← **38 ✓**.

| الإصلاح | الملفات |
|---|---|
| مفتاح التشفير اتشال من الـ sample. فيه `ENCRYPTION_KEY_PREV` للتغيير من غير توقف، وصفحة **مفاتيح التشفير** (فحص + إعادة تشفير في transaction) وصف في صحة الداشبورد | `includes/crypto.php` · `includes/config.sample.php` · `admin/security-keys.php` · `admin/dashboard.php` · `تركيب-النسخة-الكاملة.md` |
| GET للقراءة بس في الـ API، وأي عملية تانية بترجع 405 | `includes/api.php` |
| `js_str()` بدل `e()` جوه JS في الأدمن | `includes/helpers.php` · `admin/users.php` · `admin/offer-redemptions.php` · `admin/ai-providers.php` · `admin/user-view.php` · `public/social-accounts.php` |
| Paymob: transaction واحدة + idempotency + rollback + تنبيه | `public/paymob-callback.php` |
| السجل المالي بيفضل بعد مسح العميل، و `account_purge` بقت شاملة وفي transaction | `includes/account.php` · `sql/2026_ce_fixes.sql` (+rollback) |
| `ai_generate` بقت بتقبل max_tokens/temperature/json/task | `includes/ai.php` · `generate-plan-ideas.php` · `source-extract.php` · `produce-idea.php` · `brand-kit.php` |
| JSON mode + إعادة الرد المقصوص + حد المحاولات | `includes/ai-gateway.php` · `includes/campaign-flow.php` |
| الحملة: الجلسة بتتساب، والفيديو المقفول بيترفض قبل الخصم | `public/api/campaign-flow.php` |
| استرجاع العرض بتحديث مشروط | `includes/offers.php` |
| `REMOTE_ADDR` بدل `CF-Connecting-IP` | `offers.php` · `trial-brand.php` · `auth/google.php` · `site-admin/auth.php` |
| SSRF في صورة الستايل | `includes/uploader.php` |
| قفل صفحات التشخيص | `includes/diag-gate.php` · `check.php` · `check-db.php` · `public/auth/ms-test.php` · `public/social-diagnose.php` |
| Meta webhook من غير سيكرت بيترفض | `public/webhooks/meta.php` |
| صلاحيات القوالب والسجل | `admin/templates.php` · `admin/logs.php` |
| كاش إحصائيات العملاء 5 دقايق + indexes | `admin/users.php` · `sql/2026_ce_fixes.sql` |

## المرحلة C: سجل الموديلات والمراحل ✅
- **الترحيل:** `sql/2026_ce_models.sql` (+rollback).
  - `ai_model_registry`: الموديلات بقدراتها (vision · json · cache) وأسعارها و tier.
  - `ai_model_presets`: اقتصادي / متوازن / جودة.
  - `ai_task_routes` زاد عليه `temperature` و `stage_group` و `sort_order`، وفيه 10 مراحل للمحرك الجديد.
  - `ai_runs` زاد عليه `campaign_id` و `prompt_version_id` و `model_id` و `tokens_cached` و `retry_of` و `sandbox`.
- **`includes/ai-models.php`:**
  - جلب الموديلات من API كل مزود: OpenAI-compatible و OpenRouter (بالأسعار) و Gemini، و Perplexity من قايمة ثابتة.
  - مزامنة الأسعار في `ai_prices`.
  - اختيار preset بالقدرات والسعر. الـ Critic من مزود غير الكاتب لو ينفع.
- **`admin/ai-models.php`:** 3 تبويبات: سجل الموديلات، وخريطة المراحل (قايمة موديلات متفلترة بالقدرة + حرارة + بدائل)، والإعدادات الجاهزة. والإضافة اليدوية تحت «متقدم».
- **الـ Gateway والـ usage:**
  - حرارة المرحلة بتغلب، إلا لو النداء قافلها.
  - تسعير الـ cached tokens.
  - كل نداء بيتسجل بالحملة وإصدار البرومبت والموديل والـ sandbox.

---

## الجاي (مستني موافقتك)
D: Brand DNA + Wizard + Estimator + `jobs`. القرارات المطلوبة في آخر `CONTENT_ENGINE_AUDIT.md`.
