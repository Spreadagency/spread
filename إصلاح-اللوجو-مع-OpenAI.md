# إصلاح: OpenAI مش بيلتزم باللوجو

## 🔍 السبب

مش مشكلة جودة في OpenAI — **الكود كان بيرمي الصور قبل ما يبعتها**.

### باگ ① — الدالة مكانتش بتاخد صور أصلًا
```php
function ai_generate_image(string $prompt): array   ← مفيش بارامتر للصور
```
والنداء كان: `ai_generate_image($designPrompt);` — اللوجو مكانش بيتمرّر خالص.

### باگ ② — OpenAI على endpoint نص فقط
```php
curl_init('https://api.openai.com/v1/images/generations');
'prompt' => $prompt,     ← نص بس، مفيش مكان للصور في الـ API ده
```
`/v1/images/generations` **مبيقبلش صور مرجعية** — لازم `/v1/images/edits` بصيغة multipart.

### باگ ③ — OpenRouter كمان كان بيرمي الصور
```php
'messages' => [['role' => 'user', 'content' => $prompt]]   ← نص بس
```

**طب ليه كان شغال مع Gemini عندك؟** لأنك كنت مفعّل **Smart Router** — وده مسار تاني بالكامل
(`smart_ai_image`) وهو **بيمرّر الصور فعلًا**. أول ما تقفله أو تختار OpenAI، بتقع على المسار
القديم اللي بيرمي الصور.

| الحالة | المسار | الصور |
|---|---|---|
| Smart Router شغّال | `smart_ai_image(..., reference_images)` | ✅ |
| Smart Router مقفول | `ai_generate_image($prompt)` | ❌ |

## ✅ الحل

1. **الدالة بقت تاخد صور**: `ai_generate_image($prompt, $refImages)`
2. **OpenAI**: لما يكون فيه لوجو → `/v1/images/edits` بـ multipart (حد 4 صور)، ولو مفيش → التوليد النصي زي ما هو
3. **OpenRouter**: الصور بتتبعت كـ `image_url` parts جنب النص
4. **كل نقاط النداء** بتمرّر الصور: تصميم البوست · الاستوديو · اللوجو
5. **أمان**: لو مفيش صورة صالحة، بيرجع للتوليد النصي وبيسجّل تحذير بدل ما يفشل

## ✅ الاختبار (بموك بيقيس اللي وصل فعلًا)

| الحالة | الـ Endpoint | صور واصلة |
|---|---|---|
| OpenAI بدون لوجو | `/v1/images/generations` | 0 ✓ صح |
| **OpenAI مع لوجو** | `/v1/images/edits` | **2** ✅ |
| **OpenRouter مع لوجو** | `/chat/completions` | **2** ✅ |

والبرومبت والموديل بيوصلوا صح مع الصور.

## 🐞 باگ اتصلح أثناء التنفيذ
أول نسخة من بنّاء الـ multipart كتبت `\r\n` مزدوجة فالجسم طلع مكسور والصور مكانتش بتوصل —
الاختبار كشفه واتصلح.

## 📦 الملفات
`includes/ai.php` · `public/ajax/generate-design.php` · `public/ajax/studio-design.php` · `public/ajax/generate-logo.php`
