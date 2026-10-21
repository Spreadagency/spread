# نظام الجودة والمراقبة (MCP): سجل المراحل

القرارات المعتمدة (بعد المرحلة 1):
1. قاعدة بيانات منفصلة للـ AI.
2. توسيع `prompts` بدل جدول إصدارات جديد.
3. `ai_events` للأحداث الناقصة بس.
4. **التقرير اليومي في شات Claude** عن طريق موصّل الـ MCP، مش الواتساب.
5. خادم الـ MCP على **VPS**.
6. نسخة **Staging** لفحوصات الأمان والـ E2E.

---

## المرحلة 2: الأساس ✅

### الترحيل
- `sql/2026_mcp_foundation.sql`: آمن يتشغل أكتر من مرة، ومتسجّل في `includes/migrations.php`. بيتشغل من «فحص التحديث» في الأدمن.
- `sql/2026_mcp_foundation_rollback.sql`: الرجوع. ⚠️ بيمسح البلاغات وتقييمات التصميم والأحداث، فخد نسخة احتياطية الأول.

| التغيير | التفاصيل |
|---|---|
| `prompts` | `status` · `traffic_percent` · `created_by_kind` · `reason` · `parent_id` · `approved_at` · `approved_by` |
| `contents` · `content_designs` | `prompt_version_id` · `provider` |
| `ai_events` | `idea_selected` · `post_scheduled` · `post_rated` · `design_rated` · `design_downloaded` · `issue_reported` |
| `design_ratings` | 👍/👎 لكل تصميم + السبب (صف واحد لكل عميل وتصميم) |
| `user_issues` | «بلّغ عن مشكلة»: النوع · الوصف · صورة الشاشة · السياق · الحالة |
| `settings` | `issues_enabled` · `issue_rate_per_hour` |

### اللي اتعمل
- **`includes/EventTracker.php`**
  - `EventTracker::track()` مابيوقّعش رحلة العميل أبدًا: أي خطأ بيتسجّل في الـ log وخلاص.
  - `EventTracker::recent()`: آخر 10 أحداث (من `ai_events` وطلبات الـ AI)، بتتحفظ مع البلاغ.
  - `ai_stamp()`: بيكتب الإصدار والمزود على المنشور أو التصميم بعد التوليد.
- **إصدار البرومبت على كل منشور وتصميم:**
  - المنشور العادي، وإعادة التوليد، والبث.
  - إنتاج فكرة الخطة.
  - كتابة الحملة وتحسينها.
  - المنشور السريع.
  - التصميم.
- **A/B:** `prompt_pick()` في `includes/prompt-library.php` بياخد النسخة المفعّلة، أو نسخة `status = testing` لنسبة `traffic_percent` من العملاء.
  - العميل بيفضل في نفس المجموعة.
  - كل الكود بيعدي عليها: `get_active_prompt` و`prompt_get`.
- **الأحداث متركّبة في:**
  - اختيار الفكرة: الحملة والخطة.
  - الجدولة: الحملة وصفحة الجدولة.
  - تقييم المنشور (+ السبب) وتقييم التصميم (+ السبب).
  - تحميل التصميم.
  - البلاغ.
- **التقييم:**
  - 👍/👎 للتصميم في صفحة المنشور.
  - أسباب التقييم للمنشور والتصميم: «مش مناسب للبراند»، «الكلام ضعيف»، «التصميم وحش»، «النص في الصورة غلط»، «مكرر»، «أخرى».
- **«⚑ بلّغ عن مشكلة»** بيظهر في:
  - الهيدر (الجديد والقديم).
  - صفحة المنشور.
  - كل تصميم.
  - كروت المكتبة.

  المحتوى:
  - النوع: تقني · المحتوى · التصميم · صعوبة في الاستخدام · اقتراح.
  - الوصف.
  - صورة شاشة اختيارية: JPG أو PNG أو WEBP لحد 5MB، بنوعها الحقيقي واسم عشوائي، في `storage/uploads/issues` المقفول بـ `.htaccess`.
  - السياق اللي بيتحفظ تلقائي: الصفحة · المتصفح · المقاس · آخر 10 أحداث · إصدار البرومبت والمزود للمنشور أو التصميم.

  وبعد الإرسال رسالة تأكيد بتوصل في صندوق الرسايل.
- **API:** `public/api/quality.php`
  - الـ actions: `design_rate` · `post_reason` · `design_download` · `issue_create` · `reasons`.
  - الحماية: الملكية بتتشيك، وCSRF، وحد أقصى للبلاغات في الساعة.

### الملفات اللي اتغيرت
- **جديد:**
  - `includes/EventTracker.php`
  - `public/api/quality.php`
  - `assets/js/quality.js`
  - `assets/css/quality.css`
  - `sql/2026_mcp_foundation.sql`
  - `sql/2026_mcp_foundation_rollback.sql`
  - `storage/uploads/issues/.htaccess`
  - `docs/MCP_PHASES.md`
- **معدّل:**
  - `includes/migrations.php`
  - `includes/prompt-library.php`
  - `includes/prompt-builder.php`
  - `public/ajax/generate-content.php`
  - `public/ajax/generate-stream.php`
  - `public/ajax/regenerate-content.php`
  - `public/ajax/produce-idea.php`
  - `public/ajax/generate-design.php`
  - `public/ajax/schedule-content.php`
  - `public/ajax/plan-idea-action.php`
  - `public/api/campaign-flow.php`
  - `public/api/quick-post.php`
  - `public/api/brand-kit.php`
  - `public/content-view.php`
  - `templates/brand-kit-post.php`
  - `assets/js/brand-kit-post.js`
  - `templates/footer.php`
  - `templates/topbar.php`
  - `templates/v2/topbar.php`
  - `templates/v2/contents.php`
  - `includes/build-manifest.php`

### التجربة
1. شغّل الترحيل من «فحص التحديث» في الأدمن، أو شغّل `sql/2026_mcp_foundation.sql`.
2. صفحة أي منشور فيه تصميم:
   1. دوس 👎 تحت التصميم واختار سبب، والمفروض يتحفظ في `design_ratings` ويتسجّل `design_rated` في `ai_events`.
   2. دوس ⬇، والمفروض يتسجّل `design_downloaded`.
   3. دوس 👎 في لوحة الهوية واختار سبب، والمفروض يتسجّل `post_rated` ومعاه السبب.
3. **⚑ من الهيدر:** اختار النوع، واكتب الوصف، وارفع صورة. المفروض يحصل الآتي:
   - صف جديد في `user_issues` ومعاه `context`.
   - رسالة «وصلنا بلاغك #N» في صندوق الرسايل.
4. **A/B:** اعمل نسخة من أي برومبت بـ `status = 'testing'` و`traffic_percent = 100`. المنشورات الجديدة هتاخد رقمها في `contents.prompt_version_id`، ولما ترجّع النسبة لـ 0 بترجع للنسخة المفعّلة.
5. **الرجوع:** `sql/2026_mcp_foundation_rollback.sql`. الموقع بيكمّل يشتغل عادي، وكل الأكواد الجديدة متحمية لو الجداول مش موجودة.

### نتيجة الاختبار المحلي
- الترحيل اتجرب: تطبيق ← تطبيق تاني (مفيش أخطاء) ← رجوع ← تطبيق، والبرومبتات (40) زي ما هي.
- التقييم والأسباب والتحميل والبلاغ (بصورة ومن غير صورة) ورسالة التأكيد ✓.
- الـ A/B: الإصدار المفعّل ← نسخة التجربة على 100% ← رجوع على 0% ✓.
- أحداث اختيار الفكرة والجدولة ومعاها الإصدار والمزود ✓.
- اختبارات الحملة والهوية والمنشور وصفحات العميل من غير أخطاء ✓.
- ⚠️ سيرفر PHP المحلي مابيقراش `.htaccess`، فمنع فتح صور البلاغات من بره محتاج يتأكد على Apache (cPanel). عرضها للأدمن هيبقى من صفحة الأدمن في المرحلة 5.

---

## المرحلة 3: الـ Internal API ✅

### اللي اتعمل
- **قاعدة الـ AI المنفصلة:** `sql/2026_mcp_ai_db.sql` (+ rollback) فيها 12 جدول:
  - التقييمات وتحليل البلاغات.
  - مسودات الردود والبرومبتات والاستايلات.
  - الاقتراحات والتقارير.
  - الأمان والترابط والـ E2E.
  - التوليد التجريبي وسجل الـ MCP.
- **3 مستخدمين MySQL محدودين** (read / ai_write / draft)، والدليل لـ cPanel في `docs/INTERNAL_API.md`، وملف root للـ VPS في `sql/2026_mcp_db_users.example.sql`.
- **`/internal-api/v1/`:** 29 مسار:
  - 19 للقراءة (منهم مسار التحقق من الترابط، لسه للمرحلة 8).
  - 5 للتقييم والتسجيل.
  - 5 للمسودات (منهم التوليد التجريبي، لسه للمرحلة 6).

  والحماية:
  - IP + توكن + صلاحية = نوع المسار + POST للكتابة.
  - اتصال MySQL منفصل لكل صلاحية.
  - حد للطلبات.
  - إخفاء الإيميلات والأرقام.
  - سجل لكل طلب.
- **الاقتراح المرفوض** مايتعادش بنفس الأدلة (fingerprint + evidence hash).
- **صور التصميمات والبلاغات** بتتجاب بالتوكن من مسار `media`، مش لينك عام.
- **الأدمن ← AI CENTER ← «إعداد الـ MCP»:** الحالة، وإنشاء جداول قاعدة الـ AI، واختبار حدود الصلاحيات، وآخر 25 طلب.

### الملفات
- **جديد:**
  - `includes/internal-api.php`
  - `includes/internal-api-routes.php`
  - `includes/mcp-config.php`
  - `public/internal-api/v1/index.php`
  - `public/internal-api/.htaccess`
  - `sql/2026_mcp_ai_db.sql`
  - `sql/2026_mcp_ai_db_rollback.sql`
  - `sql/2026_mcp_db_users.example.sql`
  - `admin/mcp-setup.php`
  - `tests/internal-api-smoke.php`
  - `tests/internal-api.http`
  - `tests/.htaccess`
  - `docs/INTERNAL_API.md`
- **معدّل:**
  - `includes/config.sample.php` (الثوابت الجديدة)
  - `includes/admin-ui.php` (رابط الصفحة)
  - `includes/build-manifest.php`

### التجربة
اتبع `docs/INTERNAL_API.md` من الخطوة 1 للخطوة 5. صفحة «إعداد الـ MCP» لازم تبقى كلها ✅، واختبار الصلاحيات **14 / 14 مرفوض**.

### نتيجة الاختبار المحلي
اتعملت قاعدة AI منفصلة و3 مستخدمين بنفس صلاحيات الإنتاج، و`php tests/internal-api-smoke.php` جاب **40 / 40**:
- **البوابة:** توكن غلط ← 401، وصلاحية مش مطابقة ← 403، ومسار مش موجود ← 404.
- **القراءة:** كل مسارات القراءة شغالة، والهوية من غير أرقام أو إيميلات كاملة، وطلب ملف برّه `uploads` اترفض.
- **الكتابة:** حفظ التقييم والتقارير والملاحظات شغال، وتقييم ناقص أو برّه من 1 لـ 10 بيترفض.
- **المسودات:** الاقتراح المكرر مابيتسجلش تاني.
- **الصلاحيات:** 14 محاولة ممنوعة (حذف، كريدت، دفع، حسابات، أفلييت، اشتراكات، نشر برومبت مباشرة، مسح السجل) **رفضها MySQL**.
- **السجل:** كل طلب اتسجّل.

---

## الجاي: المرحلة 4، خادم الـ MCP (Node + TypeScript على الـ VPS)
- أدوات القراءة والتقييم + `rubric://content-quality` + برومبت `daily_review`.
- Streamable HTTP + OAuth 2.1 (لموصّل Claude) + Bearer ثابت.
- دليل التركيب على الـ VPS (Node + reverse proxy + HTTPS) والربط بموصّل Claude.
