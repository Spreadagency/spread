# AUDIT — نظام الجودة والمراقبة والأمان عبر MCP (المرحلة 1)

> الهدف: نعرف إيه الموجود فعلًا قبل ما نكتب سطر، ونربط كل جدول وفكرة في الطلب بالموجود بدل ما نكرره.
> المصدر: الكود الحالي في المستودع + قاعدة البيانات المحلية (نسخة مطابقة للـ schema). MariaDB 10.11 / PHP 8.3.

---

## 1) الموجود باختصار

| المجال | الموجود | الملفات / الجداول |
|---|---|---|
| المنصة | PHP procedural + MySQL، واجهة v2 بـ petite-vue، أدمن عربي RTL | `public/` `admin/` `includes/` `templates/` |
| إعدادات وأسرار | `includes/config.php` (مستبعد من git) + جدول `settings` (مفاتيح من الأدمن) | `config.sample.php` · `get_setting/set_setting` |
| الترحيلات | ملفات `sql/*.sql` + منفّذ بيسجّل اللي اتشغّل في `schema_migrations` | `includes/migrations.php` (قائمة مرتبة) |
| المستخدمين | `users` (role/status) · جلسات `user_sessions` · `user_remember_tokens` | `includes/auth.php` (regenerate عند الدخول، كوكيز httponly+SameSite=Lax) |
| الأدمن | `admin_users` (super/manager/assistant) + صلاحيات `admin_can()` · سجل `admin_logs` · `impersonation_logs` | `includes/admin-auth.php` · `includes/admin-ui.php` (السايدبار فيه قسم **AI CENTER** موجود) |
| الهوية (البراند) | `brand_profiles` (= brand_id) + Brand Kit: `brand_kits` (+نسخ) `brand_services` `brand_topics` `brand_characters` `brand_personas` `brand_sources` `brand_facts` … | `includes/brand-kit.php` · `BrandContext` |
| المحتوى (= posts) | `contents` (النص · الهاشتاجات · CTA · format · `model` · `final_prompt` · `brief_json` · `ai_reasoning` · `feedback` · النشر/الجدولة) | `content_versions` (كل نسخة: ai / edited / ai_edit / restore) |
| التصميمات | `content_designs` (content_id · image_path · prompt · model · ratio · slide_no · refs_json) + `studio_designs` | `public/ajax/generate-design.php` |
| الأفكار والحملات | `plan_ideas` `content_plans` `campaigns` `campaign_ideas` (فيها `eval_json` تقييم داخلي) | `includes/campaign-flow.php` |
| توليد الـ AI | بوابة بالبدائل `ai_gw_*` + Smart Router + مزودين/موديلات/مسارات من الأدمن | `includes/ai.php` `ai-gateway.php` `smart-ai.php` · `ai_providers` `ai_models` `ai_task_routes` `ai_attempts` `ai_runs` |
| سجل كل طلب AI | `ai_request_logs` (kind · user · reference · provider · model · status · error · tokens · credits · **duration_ms**) | `includes/ai-log.php` |
| البرومبتات | جدول `prompts` (prompt_type · version · is_active) — **فيه إصدارات فعلًا** + مكتبة v1 + المحرك | `includes/prompt-library.php` `prompt-engine.php` · `admin/prompts.php` |
| الاستايلات | `style_presets` (design_style/palette/tone/visual/character_style) | `admin/style-presets.php` |
| التقييم من العميل | `contents.feedback` (👍 1 / 👎 -1) + `brand_feedback` (like/dislike/edit/regenerate/chip + before/after) | — مفيش تقييم للتصميم لوحده |
| الكريدت والفلوس | `credit_wallets` `credit_lots` `credit_transactions` (idem_key · balance_after) · `payment_*` · `user_plans` · `credit_packages` · `quota_overrides` | `includes/credits.php` `billing.php` |
| الأفلييت | `referrals` (signup_ip · device_hash · status) · `offers` `offer_redemptions` `promo_usages` | `includes/offers*` |
| الاستهلاك | `usage_events` (unit · ref_id) للحصص | `includes/usage.php` |
| النشر | `social_connections` (access_token_enc) · `publish_logs` · cron `publish-scheduled.php` | `includes/social.php` |
| الرسائل والواتساب | صندوق العميل `messages` (inapp/whatsapp/both + wa_status) · `user_notifications` · `Notifier` · بوابة n8n `public/api/wa/*` بمفتاح `X-Spread-Key` | `includes/Notifier.php` · `admin/whatsapp.php` |
| API موجود | `public/api/*` (جلسة + CSRF) · `public/api/v1/*` للموبايل (Bearer `mobile_tokens`) | `includes/api.php` |
| Rate limit | جدول `rate_limits` + `rate_limit($action,$key,$max,$window)` | `includes/rate-limit.php` |
| الملفات المرفوعة | `storage/uploads/**` — `.htaccess` بيمنع تشغيل PHP/سكريبتات ويمنع الفهرسة | `includes/uploader.php` |
| Cron | `cron/publish-scheduled.php` `daily_notifications.php` `brand_learning.php` `ai-health.php` | — |

---

## 2) ربط جداول الطلب بالموجود (منع التكرار)

| في الطلب | القرار | السبب |
|---|---|---|
| `posts` / `post_id` | = `contents.id` | — |
| `brand_id` | = `brand_profiles.id` (والـ Kit بـ user_id) | — |
| `ai_events` | **جدول جديد خفيف** `ai_events` — بس للأحداث اللي مش متسجلة: `idea_selected` · `design_downloaded` · `post_scheduled` · `post_rated` · `generation_error` (من الواجهة) | الباقي **موجود فعلًا** ومش هنكرره: توليد AI + latency + provider + الأخطاء ← `ai_request_logs` · الكريدت ← `credit_transactions` · التعديل اليدوي ← `content_versions(edited)` · إعادة التوليد ← `brand_feedback(regenerate)`/`content_versions` · النشر ← `publish_logs`. الـ API بيجمعهم في «خط أحداث» واحد. |
| `post_ratings` | **نستخدم** `contents.feedback` + `brand_feedback` للمحتوى، ونضيف جدول `design_ratings` صغير للتصميم (+ reason chips) | تقييم المحتوى موجود؛ التصميم مش موجود |
| `prompt_versions` | **نوسّع `prompts`** (مش جدول جديد): `status` · `traffic_percent` · `created_by_kind` · `reason` · `parent_id` · `approved_at/by` — ونضيف `contents.prompt_version_id` | الإصدارات والتفعيل والرجوع موجودين؛ ناقص A/B والحالة |
| `style_preset_drafts` | جديد (في قاعدة الـ AI — تحت) | — |
| `ai_evaluations` | جديد | التقييم الداخلي (`campaign_ideas.eval_json`) تقييم الـ AI لنفسه — ده تقييم Claude المستقل بالـ rubric |
| `user_issues` | جديد (في قاعدة المنصة) + تحليل الـ AI في جدول منفصل `issue_analysis` | العميل بيكتب البلاغ (المنصة)، والـ MCP بيكتب التحليل بس |
| `reply_drafts` | جديد — والإرسال بعد الموافقة عن طريق `Notifier`/`messages` الموجود (صندوق + واتساب) | قناة الإرسال موجودة |
| `change_requests` · `ai_reports` · `security_findings` · `integrity_issues` · `e2e_runs` | جداول جديدة | — |
| `mcp_audit_log` | جديد | `admin_logs` للأدمن البشري بس |
| التقارير على الواتساب | بوابة n8n الموجودة (`X-Spread-Key`) + `messages` لرقم الأدمن | مش محتاجين Evolution API منفصل لو n8n شغال بالفعل |

---

## 3) قرار مهم: الصلاحيات الثلاثة على cPanel

الطلب: 3 مستخدمين MySQL (`read` · `ai_write` · `draft`) بصلاحيات على مستوى الجداول.
**المشكلة:** واجهة cPanel «MySQL Databases» بتدي الصلاحيات **على مستوى قاعدة البيانات كلها** — مفيش GRANT على جدول معيّن من غير root/WHM.

**الحل المقترح (أقوى من المطلوب):**
- **قاعدة جديدة للـ AI** (مثلًا `cpuser_spread_ai`) فيها كل جداول الكتابة: التقييمات · التقارير · تحليل البلاغات · الاقتراحات والمسودات · الأمان والترابط · e2e · سجل الـ MCP.
- `mcp_read` ← SELECT بس على قاعدة المنصة.
- `mcp_ai` ← SELECT/INSERT/UPDATE على قاعدة الـ AI بس (**من غير DELETE**).
- `mcp_draft` ← SELECT/INSERT على قاعدة الـ AI بس.
- مستخدم المنصة الحالي بياخد صلاحية على قاعدة الـ AI (الأدمن يعرض ويوافق).
- **الموافقة بس** (من الأدمن، بمستخدم المنصة) هي اللي بتنقل المسودة للمنصة (`prompts` · `style_presets` · إرسال الرد).

النتيجة: الـ MCP **مستحيل** يكتب في قاعدة المنصة. يعني مفيش كريدت ولا دفع ولا حسابات ولا أفلييت، ومفيش حذف خالص، والحماية دي على مستوى MySQL نفسه مش بس في الكود. وده بيتثبت بتيست.

---

## 4) نقاط ربط كل موديول

| الموديول | بيتركّب فين |
|---|---|
| EventTracker (`includes/EventTracker.php`) | `campaign-flow.php` (idea_toggle/select) · تحميل التصميم · `plan_set`/الجدولة · التقييم 👍👎 · أخطاء الواجهة — try/catch صامت |
| prompt_version_id + provider | بعد كل توليد: `contents.prompt_version_id` من المحرك (`prompt_text_for`) · الـ provider من نتيجة `ai_*` (`model`) |
| A/B | داخل `prompt_text_for()` / `get_active_prompt()`: لو فيه نسخة `testing` ← نسبة الزيارات (ثابتة لكل عميل بالـ hash) |
| تقييم التصميم | كارت التصميم في `content-view.php` + معاينة الحملة |
| «بلّغ عن مشكلة» | زرار في الهيدر v2 + كروت المنشور/التصميم · رفع صورة عن طريق `uploader.php` (MIME + 5MB + اسم عشوائي) · الرد في `messages` |
| Internal API | `public/internal-api/v1/*.php` — Bearer + `X-Scope` + IP allowlist + `rate_limit` + إخفاء PII + سجل |
| الأدمن «مركز الذكاء الاصطناعي» | صفحات جديدة تحت قسم **AI CENTER** الموجود في السايدبار، بصلاحية جديدة `ai_center` |
| Integrity checks | `includes/IntegrityChecks.php` + `cron/integrity.php` يومي |
| الإرسال للواتساب | `Notifier` / بوابة n8n الموجودة |

---

## 5) PII (اللي بيتخفى قبل ما يخرج من الـ API)

`users.email` · `users.phone` · `brand_profiles.phones/whatsapp/address` (العنوان العام للبيزنس بيفضل لأنه جزء من الهوية؛ الأرقام بتتخفى) · `messages.wa_phone` · `payment_*` (مش بيخرج خالص) · `social_connections.access_token_enc` (مش بيخرج خالص) · `referrals.signup_ip/device_hash` · `credit_transactions` (أرقام إجمالية بس).
شكل الإخفاء: `0101***678` · `a***@gmail.com`.

---

## 6) خادم الـ MCP

- **Node.js + TypeScript**، SDK الرسمي (الإصدار الحالي v2 `@modelcontextprotocol/server`)، Streamable HTTP **stateless**.
- **مكانه:** الأسهل cPanel «Setup Node.js App» (Passenger) على `mcp.ai.spreadagency.net`، أو VPS صغير. الخادم مابيلمسش MySQL، بيكلم الـ Internal API بس.
- **المصادقة:**
  - **n8n / Claude API:** Bearer token ثابت. الـ Claude API بيقبل `authorization_token` للـ MCP.
  - **موصّل Claude (claude.ai):** محتاج OAuth 2.1 (PKCE + Dynamic Client Registration).
  - الاقتراح: خادم OAuth صغير جوه الـ MCP نفسه، الدخول فيه بحساب أدمن من المنصة (super بس). ده أكبر جزء في المرحلة 4.
- **Rubric** كـ resource `rubric://content-quality`، و`daily_review` كـ MCP prompt.

---

## 7) حاجات مش هتتعمل على الإنتاج (وده مقصود)

- **ZAP scans و Playwright:** على **Staging بس** (subdomain + قاعدة منفصلة ببيانات مجهّلة). محتاج تعمله من cPanel، وهكتب دليل خطوة بخطوة.
- **فحوصات الإنتاج:** قراءة بس (headers · SSL · ملفات مكشوفة · directory listing · أخطاء PHP ظاهرة).
- `composer audit`: المشروع مفيهوش composer. هنعمل `npm audit` لخادم الـ MCP والموبايل، و«فحص إصدار PHP/الإضافات».

---

## 8) ملاحظات أمان لقيتها أثناء المراجعة (هتدخل `security_findings` أول تشغيل)

- الكوكيز `SameSite=Lax` + `httponly` + regenerate عند الدخول ✓.
- `storage/uploads` بيمنع تشغيل PHP ✓، بس الصور **عامة بلينك ثابت** (أي حد معاه اللينك يفتحها). للـ MCP هنستخدم لينكات موقّعة قصيرة العمر.
- صلاحية `ai_settings` = super بس ✓. هنعمل صلاحية `ai_center` منفصلة.
- مفيش `.env`: الأسرار في `includes/config.php` (مستبعد من git ✓). هنضيف `.env.example` لخادم الـ MCP بس، ومفاتيح الـ Internal API في `config.php`/الإعدادات.

---

## 9) الترحيلات

نفس نظام المشروع: ملف `sql/2026_mcp_quality_*.sql` + ملف `*_rollback.sql` جنبه، ويتسجّل في `includes/migrations.php`.
(الطلب قال `/migrations/YYYYMMDD_name.sql` — هنلتزم بنظام المشروع علشان المنفّذ والفحص في الأدمن يشوفوه.)

---

## 10) محتاج موافقتك قبل المرحلة 2

1. **قاعدة AI منفصلة** للكتابة (بند 3) بدل الصلاحيات على مستوى الجداول.
2. **توسيع `prompts`** بدل `prompt_versions` جديد، و`contents.feedback` + `design_ratings` بدل `post_ratings`.
3. **`ai_events` خفيف** (الأحداث الناقصة بس) والباقي من الجداول الموجودة.
4. **الواتساب** عن طريق n8n + `Notifier` الموجودين.
5. **مكان خادم الـ MCP:** cPanel Node App ولا VPS؟
6. **Staging:** هتعمل subdomain + قاعدة منفصلة؟

---

## ملحق: الـ Schema النهائي (مسودة — مش متطبّقة لسه)

### قاعدة المنصة (`spread_app`) — إضافات بس
```sql
-- prompts: إصدارات + A/B (الموجود: prompt_type · version · is_active)
ALTER TABLE prompts
  ADD status ENUM('draft','approved','testing','live','archived','rejected') NULL,   -- NULL = النظام القديم (is_active)
  ADD traffic_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD created_by_kind ENUM('admin','ai') NOT NULL DEFAULT 'admin',
  ADD reason TEXT NULL, ADD parent_id INT NULL,
  ADD approved_at DATETIME NULL, ADD approved_by INT NULL;

ALTER TABLE contents ADD prompt_version_id INT NULL, ADD provider VARCHAR(60) NULL;
ALTER TABLE content_designs ADD prompt_version_id INT NULL, ADD provider VARCHAR(60) NULL;

ai_events (id BIGINT PK, user_id INT, brand_id INT NULL, event_type VARCHAR(50), entity_type VARCHAR(30),
           entity_id BIGINT NULL, prompt_version_id INT NULL, provider VARCHAR(60) NULL, latency_ms INT NULL,
           meta JSON NULL, created_at DATETIME, INDEX(event_type, created_at), INDEX(user_id, created_at))

design_ratings (id, design_id INT, content_id INT, user_id INT, rating TINYINT /* 1 · -1 */, reason VARCHAR(255) NULL,
                created_at, UNIQUE(design_id, user_id))

user_issues (id, user_id, content_id NULL, design_id NULL, page_url VARCHAR(500), category_user VARCHAR(30),
             description TEXT, screenshot_path VARCHAR(500) NULL, context JSON,
             status ENUM('new','analyzing','analyzed','in_progress','resolved','wont_fix') DEFAULT 'new',
             created_at, resolved_at NULL, INDEX(status, created_at))
```

### قاعدة الـ AI الجديدة (`spread_ai`) — كل اللي الـ MCP بيكتبه
```sql
ai_evaluations      (id, content_id, design_id NULL, prompt_version_id NULL, evaluator VARCHAR(30),
                     content_scores JSON, design_scores JSON, content_total DECIMAL(4,2), design_total DECIMAL(4,2),
                     overall DECIMAL(4,2), strengths TEXT, weaknesses TEXT, fix_suggestion TEXT, created_at)
issue_analysis      (issue_id PK, ai_category VARCHAR(30), ai_severity ENUM('low','medium','high','critical'),
                     root_cause TEXT, fix TEXT, related_issue_ids JSON, analyzed_at)
reply_drafts        (id, issue_id, body TEXT, channel ENUM('inbox','whatsapp'),
                     status ENUM('draft','approved','sent','rejected'), created_at, reviewed_at, reviewed_by)
change_requests     (id, type VARCHAR(40), target VARCHAR(255), diff MEDIUMTEXT, reason TEXT, evidence JSON,
                     expected_impact TEXT, priority ENUM(...), status ENUM('pending','approved','rejected','applied','reverted','postponed'),
                     fingerprint CHAR(40) /* لمنع تكرار المرفوض من غير دليل جديد */, created_at, reviewed_at, reviewed_by, review_note TEXT)
prompt_drafts       (id, prompt_type VARCHAR(100), body MEDIUMTEXT, reason TEXT, parent_prompt_id INT, status, created_at, reviewed_at, reviewed_by)
style_preset_drafts (id, preset_id INT NULL, payload JSON, reason TEXT, status, created_at, reviewed_at, reviewed_by)
sandbox_runs        (id, content_id, prompt_draft_id NULL, prompt_version_id NULL, before_text MEDIUMTEXT, after_text MEDIUMTEXT, created_at)
ai_reports          (id, type ENUM('daily','weekly','security'), report_date DATE, summary TEXT, payload JSON,
                     whatsapp_sent TINYINT DEFAULT 0, created_at, UNIQUE(type, report_date))
security_findings   (id, source ENUM('code_review','zap','config','logs','dependency'), title, severity, location,
                     description, recommendation, status, first_seen, last_seen, fixed_at, UNIQUE(source, location(191)))
integrity_issues    (id, check_key VARCHAR(80) UNIQUE, entity_type, entity_ids JSON, count INT, description, suggested_fix,
                     status ENUM('open','fixed','ignored'), detected_at)
e2e_runs            (id, flow_key, status ENUM('passed','failed'), duration_ms, error TEXT NULL, screenshot_path NULL, run_at)
mcp_audit_log       (id, client VARCHAR(40), tool VARCHAR(80), scope VARCHAR(10), params JSON, result_status VARCHAR(20),
                     duration_ms INT, ip VARCHAR(45), created_at, INDEX(created_at))
```
- الموافقة على `prompt_drafts` ← بيتعمل صف جديد في `prompts` بـ `status = testing/live`.
- الرجوع ← بيرجّع الإصدار اللي قبله (`parent_id`) بزرار واحد.
