# تقييم فني: نظام الربط بالـ AI في Spread AI v2

> مراجعة مبنية على قراءة الكود الفعلي — مش توثيق نظري.

---

## 1) الخريطة العامة

فيه **مسارين** للاتصال بالـ AI، والنظام بيختار بينهم أوتوماتيكيًا:

```
ai_generate($prompt, $images)
        │
        ├── smart_ai_enabled() == true ؟
        │     ├── نعم → Smart Router (services/)
        │     │           SmartRouter::route(task_code)
        │     │              ↓ يقرأ smart_routing_rules من الداتابيز
        │     │           ProviderFactory → Adapter (OpenAI / OpenRouter / Gemini)
        │     │              ↓ المفتاح بيتفك تشفيره من ai_providers.api_key_encrypted
        │     │           استدعاء الـ API + fallback chain لو فشل
        │     │
        │     └── لأ → المسار القديم (includes/ai.php)
        │               AI_API_KEY من config.php
        │               موديل أساسي → موديل احتياطي واحد
        │
        └── AI_API_KEY فاضي؟ → **mock_ai_response()** (محتوى وهمي للتجربة)
```

**الفرق العملي:**

| | Smart Router | المسار القديم |
|---|---|---|
| مصدر المفتاح | جدول `ai_providers` مشفّر AES-256 | ثابت `AI_API_KEY` في config.php |
| اختيار الموديل | لكل مهمة موديل (`smart_routing_rules`) | موديل واحد لكل المنصة |
| الاحتياطي | سلسلة كاملة (fallback_chain) | موديل احتياطي واحد |
| تسجيل التوكنات | `ai_job_logs` تفصيلي | `ai_usage_log` مبسّط |
| إرسال الصور للموديل | ✅ مدعوم | ❌ نصوص بس |

---

## 2) ورك فلو كتابة المحتوى

### الملفات
`public/create-content.php` → `public/ajax/generate-content.php` → `includes/prompt-builder.php` → `includes/ai.php`

### اللي بيتبعت للـ API بالظبط

الدالة `build_final_prompt()` بتركّب **6 طبقات بالترتيب ده**:

```
① برومبت الأدمن الأساسي   ← get_active_prompt('content') من جدول prompts
                            (لو مفيش → default_base_prompt() المدمج في الكود)

② بيانات البراند           ← brand_profiles: الاسم، المجال، الوصف، الجمهور،
                            النبرة، الكلمات المفضلة/الممنوعة، الملاحظات، ملخص الهوية

③ المعرفة الموثّقة         ← get_brand_knowledge() من brand_sources
                            (بس اللي use_in_prompts=1، بحد أقصى knowledge_max_chars = 4000 حرف
                             والملخص له أولوية على النص الخام)

④ متطلبات المنشور          ← نوع المحتوى · المنصة · الطول · النبرة · اللهجة

⑤ قالب المنشور (اختياري)   ← content_templates.prompt_snippet لو العميل اختار قالب

⑥ ملاحظات العميل           ← extra_notes (مقصوصة عند 500 حرف)
```

كل ده **نص واحد متسلسل** بيتبعت كـ user message واحدة (مفيش system message منفصلة).

### الإخراج
الموديل مطلوب منه يرجّع بصيغة وسوم:
```
[CONTENT]...[/CONTENT]  [HASHTAGS]...[/HASHTAGS]  [CTA]...[/CTA]
```
و`parse_ai_response()` بتفصلهم بـ regex. **لو الموديل مالتزمش بالوسوم**، فيه fallback بيحط الرد كله في خانة المحتوى.

### اللي بيوصل للعميل في صفحة إنشاء المنشور
العميل بيتحكم في: `content_type` · `platform` · `length` · `tone` · `dialect` · `template_id` · `extra_notes` · `use_logo` · `use_personal_image`.
والرد بيرجعله: نص المنشور + الهاشتاجات + الـ CTA (متفصّلين).
و**البرومبت النهائي بيتخزن كامل** في `contents.final_prompt` — ده مفيد جدًا للمراجعة والتشخيص.

### حماية موجودة فعلًا
- ✅ خصم الكريدت **قبل** الاستدعاء (atomic بـ FOR UPDATE) → مفيش توليد مجاني في حالة race
- ✅ استرداد تلقائي لو الاستدعاء فشل
- ✅ rate limit: 10 توليدات / 10 دقائق للمستخدم
- ✅ `extra_notes` مقصوصة عند 500 حرف + تحذير صريح في البرومبت إن دي "تفضيلات أسلوبية فقط ولا تتجاوز القواعد" ← حماية معقولة ضد prompt injection

---

## 3) ورك فلو صناعة الصور

### الملفات
`public/ajax/generate-design.php` (من داخل بوست) · `public/ajax/studio-design.php` (الاستوديو المستقل)

### بناء البرومبت (إنجليزي بالكامل — مقصود لأن موديلات الصور أقوى بالإنجليزي)

```
"Design a professional social media post image."
+ مقاس الصورة (ratio_prompt_hint) — 1:1 / 4:5 / 9:16 / 5:4 / 3:2
+ كتلة الهوية:
    Brand / Industry
    BRAND COLORS (primary palette)
    DESIGN RULES from the client (must follow strictly)
    VISUAL IDENTITY GUIDE  ← ملخص AI لستايل صور العميل (1500 حرف)
    Brand identity summary  ← (700 حرف)
+ الفكرة (بالأولوية دي):
    برومبت العميل المخصص → image_prompt المولّد مع البوست → نص البوست نفسه
+ قالب الستايل من الأدمن (design_templates.prompt_snippet)
+ وصف أدوار الصور المرفقة
+ "Style: modern, clean, on-brand… No watermarks, no fake logos."
```

### أهم نقطة: **الصور بتتبعت للموديل نفسه**

مش مجرد وصف نصي — الصور بتتحول `data:image/...;base64` وبتتبعت كـ **مدخلات بصرية** مع وصف دور كل واحدة بالترتيب:

```
Image 1 is the brand's official LOGO — integrate this exact logo… Do NOT redraw
Image 2 is the client's personal/product photo — feature prominently…
Image 3 is a brand photo…
Image 4 is the STYLE REFERENCE — strictly follow this image's colors, mood, composition…
```

- الحد الأقصى **4 صور** (`array_slice`)
- `OpenRouterAdapter` بيبعتها كـ `image_url` parts
- `GeminiAdapter` بيحولها `inline_data` (جيميني مبيقبلش URLs)

### fallback ذكي
لو الموديل ماستلمش الصور (المسار القديم)، بيتم لصق اللوجو بـ **GD** على الصورة الناتجة (`brand_logo_apply`) — يعني اللوجو بيظهر في الحالتين بس بجودة دمج مختلفة.

---

## 4) البرومبتات المتعددة — إزاي بتترابط؟

**دي أهم ملاحظة في التقييم.**

فيه **4 مستويات برومبت** في النظام:

| المستوى | مصدره | بيتحكم فيه مين |
|---|---|---|
| برومبت المحتوى الأساسي | جدول `prompts` (type=`content`) | الأدمن — صفحة البرومبت |
| قوالب المنشور | `content_templates.prompt_snippet` | الأدمن |
| قوالب التصميم | `design_templates.prompt_snippet` | الأدمن |
| برومبتات مدمجة في الكود | hardcoded | مبرمج بس |

### ⚠️ الخلل المكتشف

جدول `prompts` مصمم ليدعم **أنواع متعددة** (`prompt_type` + إصدارات + تفعيل/إلغاء)، لكن الكود بينادي:

```php
get_active_prompt('content')   // ← الاستدعاء الوحيد في المشروع كله
```

يعني **نوع واحد بس اللي شغال فعليًا**. أي برومبت تاني الأدمن يضيفه بنوع مختلف **مش هيتقري من أي مكان**.

والعمليات دي برومبتاتها **مكتوبة في الكود مباشرة** ومش قابلة للتعديل من الأدمن:
- توليد أفكار الخطة (`plan-functions.php:58`)
- إنتاج بوست من فكرة (`plan-functions.php:161`)
- ملخص الهوية (`generate-brand-summary.php:55`)
- تحليل الهوية البصرية
- مساعد الهوية (الايجنت)
- تلخيص المستندات
- برومبت التصميم نفسه

**التوصية:** توسيع `get_active_prompt()` للأنواع دي (`plan_ideas`, `plan_produce`, `brand_summary`, `visual_identity`, `design_base`) عشان تعدّلها من الأدمن من غير ما تلمس الكود. البنية جاهزة — ناقص التوصيل بس.

---

## 5) ✅ المطلوب عشان المشروع يشتغل فعليًا

### 🔴 حرج — من غيره المنصة بتطلع محتوى وهمي

الكود الحالي فيه:
```php
define('AI_API_KEY', '');   // config.php سطر 57
```

ولما المفتاح فاضي والـ Smart Router مقفول:
```php
if (empty(AI_API_KEY)) {
    return ['ok' => true, 'response' => mock_ai_response($prompt), 'model' => 'mock'];
}
```

**النتيجة:** المنصة **بترجّع محتوى وهمي جاهز** وبتقول "نجح" — من غير أي اتصال بالـ AI. وده بيخصم كريدت كمان.

**الحل — اختار واحد:**

**(أ) المسار الموصى به — Smart Router:**
1. الأدمن ← **إدارة الـ AI** ← أضف موفر (OpenRouter / OpenAI / Gemini) + المفتاح
2. أضف الموديلات وحدد `model_type` (text / vision / image)
3. اضبط `smart_routing_rules` لكل مهمة + سلسلة احتياطية
4. فعّل `use_smart_router = 1`

**(ب) السريع:** حط المفتاح في `config.php` → `AI_API_KEY` و`AI_MODEL`.

> **تحقق سريع:** ولّد أي منشور وشوف عمود `contents.model` — لو لقيته `mock` يبقى مفيش اتصال حقيقي.

### 🟠 مهم للتشغيل الكامل

| المتطلب | ليه |
|---|---|
| `ENCRYPTION_KEY` (64 hex) في config.php | تشفير مفاتيح الـ AI وتوكنات فيسبوك — من غيره الحفظ بيفشل |
| **موديل صور** مفعّل (`model_type='image'`) | من غيره التصميمات مش هتشتغل. الموصى به: `google/gemini-2.5-flash-image` أو `openai/gpt-image-1` |
| مجلد `storage/` قابل للكتابة | حفظ التصميمات والرفع |
| كرون كل دقيقة | للنشر المجدول «على المنصة» وانستجرام بس |
| تطبيق ميتا + App Review | للنشر على فيسبوك/انستجرام |
| `storage` متاح للعامة عبر HTTPS | فيسبوك بيسحب الصور من عندك وقت النشر |

### 🟡 تحسينات مقترحة

1. **توصيل باقي أنواع البرومبت بالأدمن** (البند 4 فوق)
2. **`mock` لازم يبان بوضوح** — دلوقتي بيرجع `ok: true` وكأنه محتوى حقيقي. الأفضل: تحذير صريح في الواجهة لما `model === 'mock'`
3. **مفيش system message منفصلة** — كل حاجة user message واحدة. فصل التعليمات في `system` بيحسّن التزام الموديل ويقلل تأثير حقن العميل
4. **`knowledge_max_chars = 4000`** — مع براند عنده مستندات كتير، القصّ ممكن يشيل معلومات مهمة. يستحق مراجعة حسب حجم نافذة الموديل

---

## 6) الخلاصة

**نقاط القوة:**
- فصل نظيف بين بناء البرومبت والاتصال بالـ API — سهل تغيّر الموفر من غير ما تلمس منطق المحتوى
- طبقة الـ Smart Router مبنية بحماية ممتازة: لو ملفات `services/` ناقصة، النظام بيرجع للمسار القديم من غير أي fatal
- معالجة الكريدت سليمة (خصم ذري + استرداد عند الفشل)
- إرسال الصور للموديل بأدوار موصوفة — ده تصميم متقدم ونادر
- حفظ `final_prompt` كامل مع كل بوست

**أهم ٣ حاجات محتاجة تشتغل:**
1. **مفتاح API فعلي** — من غيره كل حاجة وهمية
2. **موديل صور مفعّل** — التصميمات معتمدة عليه
3. **توصيل باقي البرومبتات بالأدمن** — البنية موجودة بس مش موصّلة
