# Content Engine v3: المراجعة الشاملة (المرحلة A)

الكود اتراجع كله قبل أي تعديل في 4 محاور: الأمان، سلامة البيانات، طبقة الـ AI والبرومبتات والتكلفة، والأداء وتجربة الاستخدام.
المراجعة اعتمدت على قراءة الكود و `EXPLAIN` على نسخة محلية صغيرة من القاعدة. مفيش أي بيانات حقيقية اتلمست.

**الحالة:**
- ✅ = اتصلّح في المرحلة B ومعاه اختبار في `tests/ce-b-fixes.php`.
- 🅒 = اتعمل في المرحلة C.
- ⏭ = مأجّل لمرحلة محددة.
- ❓ = محتاج قرار منك.

---

## أ) المشاكل حسب الخطورة

### 🔴 High

| # | المحور | المشكلة | المكان | الحالة |
|---|---|---|---|---|
| S1 | أمان | مفتاح التشفير `ENCRYPTION_KEY` كان مكتوب في `config.sample.php` المرفوع على GitHub، ودليل التركيب بيقول «سيبه زي ما هو». يعني أي حد معاه نسخة من القاعدة يقدر يفك مفاتيح الـ AI وتوكنز صفحات فيسبوك و App Secret بتاع ميتا وسيكرت جوجل. | `includes/config.sample.php:110` · `تركيب-النسخة-الكاملة.md:12` | ✅ اتشال من الملف. اتعمل دعم `ENCRYPTION_KEY_PREV` للتغيير من غير توقف، وصفحة **مفاتيح التشفير** للفحص وإعادة التشفير. ❓ لازم تغيّر المفتاح على السيرفر (الخطوات تحت) |
| S2 | أمان | CSRF عن طريق GET: الـ API بيتحقق من CSRF في POST بس، و `api_param` بيقرا من `$_GET` كمان. فلينك زي `campaigns.php?action=delete&id=5` كان بيمسح حملة العميل لو فتحه وهو داخل. | `includes/api.php:147` · `:83` | ✅ GET بقى مسموح لعمليات القراءة بس (قايمة بيضا لكل endpoint)، وأي عملية تانية بترجع 405 |
| S3 | أمان | Stored XSS في لوحة الأدمن: اسم العميل أو إيميله بيتحط جوه `onclick="confirm('…')"`. و `e()` مابيحميش جوه JS، لأن المتصفح بيفك الـ entities الأول. | `admin/offer-redemptions.php:116` · `admin/users.php:225` · وأماكن مشابهة | ✅ دالة `js_str()` (JSON + HEX flags) في 6 أماكن |
| I1 | بيانات | Paymob: الطلب بيتعلّم `paid` قبل إضافة الرصيد، ونتيجة `credits_add` مش بتتشيك، ومفيش مفتاح idempotency. لو الإضافة فشلت، العميل يكون دفع والرصيد مانزلش، والطلب مقفول كأنه اتشحن. | `public/paymob-callback.php:37-52` | ✅ تحديث الطلب والرصيد والباقة بقوا في transaction واحدة. الرصيد بيتضاف بمفتاح `po:<id>`. لو حصل فشل: rollback (الطلب يفضل pending ويتعاد بأمان) وتنبيه critical للأدمن. واتعمل UNIQUE على `paymob_order_id` |
| I2 | بيانات | مسح عميل كان بيمسح سجله المالي: `credit_transactions` و `payment_orders` عليهم `ON DELETE CASCADE`، و `account_purge` نفسها كانت بتمسح القيود. | `sql/features-upgrade.sql` · `includes/account.php:261-286` | ✅ الـ FK بقى `SET NULL`، والقيود المالية بتفضل من غير صاحب |
| I3 | بيانات | `account_purge` مش بتغطي معظم الجداول الجديدة (حملات، أفكار، Brand Kit، شخصيات، رسايل، توكنز موبايل، جلسات…)، ومش شغالة في transaction. والجداول الجديدة نفسها مفيهاش FKs، فبيفضل صفوف يتيمة (محليًا: `brand_kit_versions` 14 و `brand_facts` 24). | `includes/account.php` | ✅ اتكتبت من جديد: transaction واحدة، 45+ جدول، مسح الصفوف الفرعية بـ JOIN، و `ai_runs` بيتعمله anonymise. ❓ إضافة FKs للجداول الجديدة محتاجة تنضيف اليتامى الأول |
| A1 | AI | `ai_generate()` تحت الـ Gateway بيفرض `max_tokens=1000` و `temperature=0.8` على كل حاجة. عشان كده أفكار الخطة (محتاجة ~4000 + JSON) وملخص المصادر وموضوعات الـ Brand Kit كانت بتتقص. | `includes/ai.php:275` → `ai-gateway.php:919` | ✅ `ai_generate($prompt, $images, $opts)` بقت بتاخد `max_tokens` و `temperature` و `json` و `task`، والأماكن الأربعة الكبيرة اتظبطت |
| A2 | AI | JSON mode عمره ما اتطلب من الموديل. `campaign_ai_call` بيرمي `json_mode`. | `ai-gateway.php` · `campaign-flow.php:71` | ✅ `response_format: json_object` لما الطلب يحتاج JSON. ولو المزود رفضه (400) بيتشال تلقائي ويتعاد الطلب |
| A3 | AI | `finish_reason=length` (الرد اتقص) كان بيتقبل كأنه رد سليم. | `ai-gateway.php:589-597` | ✅ بيتعاد بـ `max_tokens × 1.6` (لحد 16000)، والمحاولة بتتسجل `truncated` |
| A4 | تكلفة | هوية البراند كاملة بتتبعت في كل نداء (6–12 ألف توكن)، وترتيب البرومبت مش مناسب للـ prompt caching: المتغيرات في الأول والقواعد الثابتة في الآخر. | `brand-kit.php:1898` · قوالب v1 | ⏭ D/F: Brand DNA مختصر + ترتيب ثابت-ثم-متغير. 🅒 تتبع الـ cached tokens وتسعيرها بقى جاهز |
| A5 | تكلفة | كل شغل الـ AI متزامن جوه طلب HTTP (لحد 200 ثانية)، والتوليد الجماعي للحملة loop في المتصفح: لو التاب اتقفل الشغل بيقف ومفيش استكمال. | `templates/v2/campaign.php:947` · `api/brand-kit.php:987` | ⏭ D: جدول `jobs` + worker على cron كل دقيقة (زي نمط `publish-scheduled`) |
| P1 | أداء | `api/campaign-flow.php` كان ماسك قفل الجلسة طول أي طلب، فلما العميل يولّد بالجملة، كل صفحاته التانية بتعلّق. | `api/campaign-flow.php:25` | ✅ الجلسة بتتساب لكل العمليات ماعدا `ideas_generate` |
| P2 | أداء | صفحة العملاء في الأدمن بتحسب ~9 subqueries لكل عميل في كل فتحة. | `admin/users.php:77-133` | ✅ كاش 5 دقايق في الجلسة (`?fresh=1` بيتخطاه). ⏭ جدول `user_stats` لو العدد كبر |

### 🟠 Medium

| # | المشكلة | المكان | الحالة |
|---|---|---|---|
| M1 | الحملة بتخصم الكريدت قبل ما تتأكد إن الفيديو مقفول، ومفيش استرداد. | `api/campaign-flow.php:289-292` | ✅ الشرط بقى قبل الخصم |
| M2 | نقط الـ AI على الويب مفيهاش idempotency، فالضغطة المزدوجة بتخصم مرتين (regenerate، generate-design، produce-idea، …). | `public/ajax/*` | ⏭ D (مع `jobs`): مفتاح لكل مرحلة، و«إعادة تشغيل مرحلة مابتخصمش مرتين» |
| M3 | الاسترداد بيحصل في الأخطاء المتوقعة بس. لو حصل fatal أو timeout، الكريدت بيضيع. | `generate-design.php:123→357` | ⏭ D: الـ worker بيسجل حالة كل مرحلة، و cron بيصالح |
| M4 | اعتماد طلب الدفع اليدوي و `plan_start` بيكتبوا في كذا جدول من غير transaction، فممكن يبقى للعميل باقتين شغالين. | `billing.php:335-395` · `plans.php:311` | ⏭ ❓ (بيمس الفلوس، محتاج موافقتك) |
| M5 | استرجاع مكافأة عرض كان ممكن يخصم مرتين لو اتطلب في نفس اللحظة. | `includes/offers.php:370` | ✅ تحديث مشروط الأول (اختبار بعمليتين متوازيتين) |
| M6 | الثقة في هيدر `CF-Connecting-IP`، وأي حد يقدر يزوّره (تجربة مجانية بلا حدود، تخطي منع نفس الـ IP في الإحالات). | `trial-brand.php:50` · `offers.php:87` · `auth/google.php` · `site-admin/auth.php` | ✅ `REMOTE_ADDR` |
| M7 | SSRF: صورة الستايل من لينك كانت بتتحمل بـ curl عادي بيتبع التحويلات، فممكن توصل لعناوين داخلية أو metadata السيرفر. | `includes/uploader.php:112` | ✅ بقت بتعدي على `safe_http_get` |
| M8 | أسرار متخزنة نص عادي في `settings`: `paymob_api_key`، `paymob_hmac`، `meta_page_token`، `crm_webhook_secret`، `cron_secret`، `wa_shared_secret`. | `settings-registry.php` | ❓ تشفيرها محتاج تعديل كل مكان بيقراها، وأقترح نعمله بعد ما تغيّر المفتاح |
| M9 | `site-admin/login.php` مفيهوش حد للمحاولات. | | ⏭ قريب، صغير |
| M10 | `max_attempts` بيعد المحاولة المكررة على نفس المزود ومحاولة إصلاح الـ JSON، فالمزود التالت عمره ما بيتجرب. | `ai-gateway.php` | ✅ الحد بقى `max(route, عدد الموديلات+1)` بسقف 8 |
| M11 | المزود اللي مابيرجعش usage بيتحسب بـ $0 من غير أي تحذير، وصور الـ legacy من غير تكلفة. | | 🅒 جزئي (سجل الموديلات + الأسعار). ⏭ I (لوحة الاستخدام: تنبيه «تكلفة مجهولة») |
| M12 | `campaign_parse_eval` بيملا المعايير الناقصة بالمتوسط، فبيدّي تقييم مضلل. | `campaign-flow.php:484` | ⏭ F (Critic بـ schema) |
| M13 | البحث بـ `LIKE %q%` في `generated_text`، و `content_status_sql` بيتحسب في كل قراية (CASE + EXISTS). | `api/contents.php:221` · `lifecycle.php:37` | ⏭ ممكن بعد F (عمود status مخزّن) |
| M14 | N+1 في `campaign_to_api` والشخصيات والـ personas. | `api/campaigns.php:42` · `brand-kit.php:883` · `campaign-flow.php:609` | ⏭ D/E (الحملة الجديدة بتتبني من غيرها) |
| M15 | الداشبورد ~40 aggregate في كل فتحة. | `admin/dashboard.php:22-65` | ⏭ I |

### 🟡 Low

| # | المشكلة | الحالة |
|---|---|---|
| L1 | صفحات التشخيص (`check.php`، `check-db.php`، `ms-test.php`) مفتوحة لأي حد. و `social-diagnose.php?fix=1` بيعمل تعديل جداول بـ GET. | ✅ `includes/diag-gate.php`: بتفتح للأدمن بس، أو وقت التركيب، أو لو فيه ملف `.diag-open` عمره أقل من ساعة. و `fix=1` بقى للأدمن بس |
| L2 | Meta webhook بيقبل طلبات من غير توقيع لما مايكونش فيه App Secret، وأي حد يقدر يلغي ربط صفحات العملاء برقم الصفحة. | ✅ بيرجع 503 لحد ما السيكرت يتحط |
| L3 | `admin/templates.php` و `admin/logs.php` كانوا بيتشيكوا إن اللي داخل أدمن وبس، من غير صلاحية الصفحة. | ✅ `require_admin_can` |
| L4 | `content_versions` مفيهاش UNIQUE على (content_id, version_number)، وفيه indexes مكررة. | ⏭ |
| L5 | indexes ناقصة: `contents(user_id, updated_at)`، `contents(created_at)`، `content_designs(created_at)`، `users(created_at)`، `credit_transactions(action_type, created_at)`. | ✅ `sql/2026_ce_fixes.sql` |
| L6 | `ai_runs` و `ai_attempts` و `ai_job_logs` بيكبروا من غير حد. | ⏭ I (تنضيف بعد 180 يوم) |
| L7 | صورة `robot-anim.webp` حجمها 2.7MB، وفيه JS كبير inline (`campaign.php` 119KB)، و `.htaccess` مفيهوش gzip أو expires للـ webp/woff2، ومفيش thumbnails. | ⏭ بعد G |
| L8 | `admin/impersonate.php` و `public/logout.php` بيغيّروا حالة بـ GET. | ⏭ |

### ✅ اللي سليم

- مفيش SQL injection: كل الاستعلامات prepared.
- الملكية بتتشيك في كل endpoint.
- الرفع: الامتداد والـ MIME والحجم بيتشيكوا، والفولدر مايشغّلش PHP.
- CSRF في الفورمز تمام.
- الباسوردات والجلسات تمام.
- `credits_consume` فيها `FOR UPDATE` جوه transaction، ومفيش محفظة بالسالب.
- `safe_http` موجودة ومستخدمة في استخراج المصادر.
- الأسرار مش بتوصل للمتصفح.

---

## ب) فرص التحسين

**تجربة العميل**
- التوليد الجماعي يكمّل لو التاب اتقفل، ويبان شريط تقدّم لكل مرحلة (`jobs`).
- يبان تقدير الكريدت قبل ما يبدأ.
- يقدر يعيد الفاشل بس.
- الكتابة تظهر وهي بتتكتب (SSE).

**تجربة الأدمن**
- اختيار الموديل من قايمة بدل الكتابة. 🅒 اتعمل.
- Presets بضغطة. 🅒 اتعمل.
- Playground لمقارنة 3 موديلات (H).
- تجميع اللوحة في 7 أقسام (تحت).

**الكود**
- مسار AI واحد: الـ Gateway، بدل 3 مسارات (`ai_call` legacy، و smart router، و gateway).
- برومبت بترتيب «ثابت ← متغير» عشان الـ caching.
- schema لكل مخرج + إعادة مرة واحدة بالخطأ (F).

**التوسع**
- جدول `jobs` + worker.
- كاش للإحصائيات.
- تنضيف الجداول اللي بتكبر.

---

## ج) تأثير الأقسام 2+ على الكود الحالي

| الجزء | جديد | بيتعدّل | ممكن يتكسر | الحماية |
|---|---|---|---|---|
| Brand DNA (2.1) | `brand_dna` (نسخة مختصرة متولّدة من Brand Kit) | `bk_brand_block` بيقرا الـ DNA لو موجود | البرومبتات القديمة لو اتغيّرت المتغيرات | المتغيرات القديمة تفضل شغالة |
| Campaign Wizard + Estimator (2.2، 4.2) | `campaign_briefs` و `jobs` | الحملة الحالية مابتتلمسش | — | الحملة الجديدة جنبها ورا مفتاح (قرارك) |
| Matrix + Plan Gate (2.3) | `matrix_slots` و `plan_evaluations` | — | — | — |
| Ideation → QA (2.4–2.6) | `content_evaluations` | `contents` (أعمدة اختيارية) | تقييم المنشورات الحالي | بيفضل للحملة القديمة |
| Design + Vision QA (2.8) | `design_briefs` و `text_templates` + overlay عربي في المتصفح | `generate-design` (مسار جديد اختياري) | — | — |
| Prompt Manager + Playground (5.2–5.4) | `golden_cases` و `regression_runs` (+ `sandbox` في `ai_runs` 🅒) | `prompts` (الإصدارات موجودة من MCP-2) | — | الـ sandbox مابيخصمش ومابيدخلش التقارير |
| Learning Loop (2.9) | `performance_metrics` | الـ cron الحالي | — | — |
| Model Registry (3) | 🅒 `ai_model_registry` و `ai_model_presets` | 🅒 `ai_task_routes` (temperature و stage_group) | — | المراحل من غير إعداد بتمشي على الإعداد القديم |

**أسماء الجداول:** البريف بيقترح `campaigns` و `contents` و `brand_profiles` و `ai_runs`، والجداول دي موجودة فعلًا. هنكمّل عليها بأعمدة جديدة بدل ما نعمل جداول بنفس الاسم.

---

## د) خطة التنفيذ

| المرحلة | النطاق | التقدير | الحالة |
|---|---|---|---|
| A | المراجعة دي | 1 يوم | ✅ |
| B | إصلاح High + أهم Medium، ومعاهم اختبارات | 1–2 يوم | ✅ (38 اختبار) |
| C | سجل الموديلات + خريطة المراحل + Presets + توسيع `ai_runs` | 2 يوم | ✅ |
| D | Brand DNA + Wizard + Estimator + `jobs`/worker + idempotency | 3–4 أيام | ⏭ |
| E | Matrix + Plan Gate (القواعد في الكود + نسبة ≥ 80) | 2–3 أيام | ⏭ |
| F | Ideation → Selection → Writer → Critic (حد أقصى دورتين) + schemas | 4–5 أيام | ⏭ |
| G | التصميم + قوالب النص العربي + Vision QA | 4–5 أيام | ⏭ |
| H | Prompt Manager + Playground + Regression (+ MCP-6) | 3–4 أيام | ⏭ |
| I | Learning Loop + لوحة الاستخدام + تنضيف السجلات | 2–3 أيام | ⏭ |

---

## هـ) تجميع لوحة الأدمن في 7 أقسام (من غير مسح أي صفحة)

| القسم | الصفحات |
|---|---|
| الرئيسية | dashboard · alerts · ai-report · logs |
| العملاء والكريدت | users · approvals · credits · payments · packages · pricing · offers · referrals · whatsapp · social-connections · brand-kits · users-export |
| المحتوى والجودة | content · gallery · studio · video-requests · templates · ai-evaluations · ai-issues · ai-quality-settings · brand-learning |
| البرومبتات | prompts · sector-templates (type-prompts · prompt-view) |
| الموديلات | **ai-models (جديدة)** · ai-providers · ai-router · style-presets · studio-settings · ai-costs |
| الـ Playground | ai-logs · ai-mcp-log (+ playground في H) |
| الإعدادات | settings · security-keys (جديدة) · admins · features · appearance · announcements · watermark · mcp-setup · migrations · ui-diagnose |

**ملاحظة:** فيه bug بسيط في الـ sidebar. `users-export` بيعلّم «العملاء» كأنها الصفحة المفتوحة، و `prompt-view` بيعلّم «المحتوى». هيتصلّح مع التجميع.

---

## و) قرارات محتاجاها منك

1. **تغيير مفتاح التشفير على السيرفر** (الخطوات في صفحة «مفاتيح التشفير»). بعدها الأحوط تعمل مفاتيح AI جديدة و App Secret جديد لميتا.
2. **هامش الكريدت**: الكريدت = التكلفة الفعلية × كام؟ ده محتاجه للـ Estimator في D.
3. **إضافة FKs للجداول الجديدة**: محتاجة تنضيف الصفوف اليتيمة الأول، يعني مسح بيانات محلية مالهاش صاحب. نعملها؟
4. **Paymob webhook من السيرفر** (بجانب لينك الرجوع)، عشان الدفع يتسجل حتى لو العميل قفل الصفحة.
5. **تشفير الأسرار النص العادي** (M8)، بعد تغيير المفتاح.
6. **transaction لاعتماد الدفع اليدوي و `plan_start`** (M4).
7. **توقيت تجميع الأدمن في 7 أقسام**: دلوقتي، ولا مع H؟
