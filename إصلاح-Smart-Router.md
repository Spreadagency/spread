# إصلاح: Smart Router — اللوجو والسجل

## 🔍 المشكلتين

أنا صلّحت المرة اللي فاتت **المسار المباشر بس**. لكن انت شغّال على **Smart Router** — وده مسار تاني بالكامل بملفاته الخاصة:

```
ai_generate_image()
  ├─ Smart Router شغّال → services/AIService::generateImage() → Adapters/OpenAIAdapter  ← لسه فيه الباگ
  └─ Smart Router مقفول → includes/ai.php                                              ← اتصلح قبل كده
```

### ① اللوجو مع OpenAI
`services/Adapters/OpenAIAdapter.php` كان فيه **نفس الباگ بالظبط**:
```php
$payload = ['model' => $model, 'prompt' => $prompt, ...];   ← نص بس
$this->http('POST', "{$this->baseUrl}/images/generations", ...);
```
الـ `reference_images` كانت بتوصل للمحوّل **وبيرميها**.

### ② السجل فاضي
التسجيل كان متوصّل بـ `ai.php` بس — والمسار الذكي بيعدّي من `smart-ai.php` ومكانش بيسجّل حاجة.

## ✅ الحل

**المحوّل**: لما فيه صور مرجعية → `/images/edits` بصيغة multipart (حد 4 صور)، ولو مفيش → `/images/generations` زي ما هو.
ولو الصور كلها غير صالحة، بيرجع للتوليد النصي بدل ما يفشل.

**السجل**: اتوصّل بالمسار الذكي في `smart-ai.php` — للصور والنص، للنجاح والفشل.
عمود **«مسار»** في السجل بيفرّق: `smart` أو `legacy`.

## ✅ الاختبار

| # | النوع | مسار | الموديل | صور | الحالة |
|---|---|---|---|---|---|
| 7 | design | **smart** | gpt-image-1 | **1** | ok |
| 8 | design | **smart** | gpt-image-1 | 0 | ok |
| 9 | content | **smart** | — | 0 | failed |

واللي وصل للـ API فعليًا:
```json
{ "endpoint": "/v1/images/edits", "content_type": "multipart/form-data",
  "images_received": 1, "has_prompt": true, "model": "gpt-image-1" }
```

## 📦 الملفات
`services/Adapters/OpenAIAdapter.php` · `includes/smart-ai.php`
