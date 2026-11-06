<?php
/**
 * Spread AI v2 — توليد التصميم v5
 *
 * اللوجو + صور العميل + الصورة المرجعية بيتبعتوا **للموديل نفسه** كمدخلات:
 * - اللوجو → الموديل يدمجه جوه التصميم كما هو
 * - صور العميل / المنتج → تظهر في التصميم
 * - الصورة المرجعية → الموديل يلتزم بستايلها
 *
 * fallback: لو المسار القديم (بدون دعم صور) → اللوجو بيتحط بـ GD بعد التوليد
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/prompt-builder.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/ai.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/uploader.php';
require_once __DIR__ . '/../../includes/plan-functions.php';
require_once __DIR__ . '/../../includes/watermark.php';
if (is_file(__DIR__ . '/../../includes/smart-ai.php')) {
    require_once __DIR__ . '/../../includes/smart-ai.php';
}

require_login();
require_csrf();
session_release();   // العملية طويلة — مانقفلش باقي صفحات العميل لحد ما تخلص
decode_b64_fields();

$user = current_user();
$brand = user_brand();
$contentId = (int) ($_POST['content_id'] ?? 0);

if (!$brand) {
    json_response(['ok' => false, 'error' => 'كمّل بيانات الهوية أولًا']);
}

$content = db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [$contentId, $user['id']]);
if (!$content) {
    json_response(['ok' => false, 'error' => 'المحتوى غير موجود']);
}

// ⑦-ج أشكال المحتوى: الفيديو بيتنفذ يدوي · الكاروسيل تصميم لكل شريحة · الستوري رأسي
require_once __DIR__ . '/../../includes/content-formats.php';
$format = content_format_key($content['format'] ?? 'post');
if ($format === 'video') {
    json_response(['ok' => false, 'error' => 'الفيديو مش بيتصمم بالـ AI — راجع السكريبت واطلب التنفيذ من «🎬 إنشاء الفيديو»']);
}
// شرط الاشتراك — قبل أي حاجة بتتخزن أو بتتخصم (مفيش تصميم ولا Credits ولا Job للي مش مشترك)
if ($__sub = design_subscription_gate((int) $user['id'])) {
    json_response($__sub);
}
$slideNo = null;
$slide = null;
if ($format === 'carousel') {
    $slidesTotal = max(1, (int) $content['slides_count']);
    $slideNo = (int) ($_POST['slide_no'] ?? 0);
    if ($slideNo < 1) {
        // من غير رقم (مسار قديم / الرحلة): أول شريحة ناقصة
        $slideNo = content_design_progress($content)['missing'][0] ?? 1;
    }
    if ($slideNo > $slidesTotal) {
        json_response(['ok' => false, 'error' => 'الكاروسيل ده فيه ' . $slidesTotal . ' شرائح بس']);
    }
    $slide = content_slides($content)[$slideNo - 1] ?? null;
}

// التصميم الجماعي من الحملة (⑥-ب): حد أعلى — كل تصميم بيتخصم من الكريدت برضه
$__bulk = ($_POST['bulk'] ?? '') === '1';
if (!($__bulk ? rate_limit('design_bulk', 'u' . $user['id'], 30, 600) : rate_limit('design', 'u' . $user['id'], 6, 600))) {
    json_response(['ok' => false, 'error' => 'وصلت للحد الأقصى لتوليد التصميمات. استنى شوية.']);
}


// ── الخيارات من الواجهة ──
$includeLogo   = ($_POST['include_logo'] ?? '1') === '1' && !empty($brand['logo_path']);
$includeImages = ($_POST['include_brand_images'] ?? '0') === '1';
$customPrompt  = mb_substr(trim($_POST['custom_prompt'] ?? ''), 0, 1200);

// ── صورة مرجعية مرفوعة لهذا التصميم (اختياري) ──
$sourceImagePath = null;
if (!empty($_FILES['source_image']['name'])) {
    $up = upload_image($_FILES['source_image'], 'references');
    if ($up['ok']) {
        $sourceImagePath = $up['path'];
    }
}
// أو مرجع مختار من معرض الإلهام / صوره (الملف المرفوع له الأولوية)
$externalStyleUri = null;
$refLog = [];     // وصف الصور المرجعية اللي اتبعتت (للأدمن) — مسارات مش data URIs
if (!$sourceImagePath && !empty($_POST['style_ref'])) {
    $resolved = resolve_style_ref((string) $_POST['style_ref'], (int) $user['id'], $brand);
    if ($resolved && strpos($resolved, 'url:') === 0) {
        $externalStyleUri = remote_image_data_uri(substr($resolved, 4));
    } else {
        $sourceImagePath = $resolved;
    }
}

// الكاروسيل: الشريحة 2+ بتاخد الشريحة الأولى (أو أقرب شريحة قبلها) مرجع — علشان يطلعوا كاروسيل واحد متناسق
$carouselRef = false;
$carouselRatio = null;
if ($format === 'carousel' && $slideNo > 1 && !$sourceImagePath && !$externalStyleUri && ($_POST['design_edit'] ?? '') !== '1') {
    $prev = db_one('SELECT image_path, ratio, slide_no FROM content_designs WHERE content_id = ? AND slide_no BETWEEN 1 AND ? ORDER BY slide_no = 1 DESC, slide_no DESC, id DESC LIMIT 1',
        [$contentId, $slideNo - 1]);
    if ($prev) {
        $sourceImagePath = $prev['image_path'];
        $carouselRef = (int) $prev['slide_no'];
        $carouselRatio = (string) $prev['ratio'];
    }
}

// الكاروسيل: أول شريحة ناقصة بتتأكد إن الحصة تكفي باقي الشرائح (علشان مايقفش في النص)
$__need = 1;
if ($format === 'carousel') {
    $__miss = content_design_progress($content)['missing'] ?? [];
    if (in_array($slideNo, $__miss, true)) $__need = max(1, count($__miss));
}
if ($__g = plan_gate((int) $user['id'], 'designs', $__need)) {
    json_response(['ok' => false, 'error' => $__g, 'code' => 'quota']);
}
$cost = design_credit_cost();
if (!credits_consume((int) $user['id'], $cost, 'توليد تصميم للمحتوى #' . $contentId . ($slideNo ? ' — شريحة ' . $slideNo : ''), 'design', $contentId)) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

// ═══ تجهيز التصميم (المدخلات + البرومبت) — includes/design-builder.php (نفس المنطق للمحرك) ═══
require_once __DIR__ . '/../../includes/design-builder.php';
$__built = design_build([
    'uid' => (int) $user['id'], 'brand' => $brand, 'content' => $content, 'format' => $format,
    'slide' => $slide, 'slide_no' => $slideNo, 'slides_total' => $slidesTotal ?? 1,
    'include_logo' => $includeLogo, 'include_images' => $includeImages, 'custom_prompt' => $customPrompt,
    'source_image_path' => $sourceImagePath, 'external_style_uri' => $externalStyleUri,
    'carousel_ref' => $carouselRef, 'carousel_ratio' => $carouselRatio, 'edit' => ($_POST['design_edit'] ?? '') === '1',
    'character_ids' => isset($_POST['character_ids']) ? (string) $_POST['character_ids'] : null,
    'style_ref' => (string) ($_POST['style_ref'] ?? ''), 'style_preset_id' => $_POST['style_preset_id'] ?? null,
    'size_wh' => (string) ($_POST['size_wh'] ?? ''), 'ratio' => (string) ($_POST['ratio'] ?? ''),
]);
$inputImages = $__built['inputs'];
$refLog = array_merge($refLog, $__built['refs']);
$designPrompt = $__built['prompt'];
$ratioKey = $__built['ratio_key'];
$ratioMeta = $__built['ratio_meta'];
$__exact = $__built['exact'];

// ═══ التوليد ═══
$modelReceivedImages = false;

if (function_exists('smart_ai_enabled') && smart_ai_enabled()) {
    $result = smart_ai_image($designPrompt, [
        'size' => $ratioMeta['size'],
        'user_id' => (int) $user['id'],
        'reference_type' => 'content',
        'reference_id' => $contentId,
        'reference_images' => array_column($inputImages, 'uri'),
    ]);
    $modelReceivedImages = !empty($inputImages) && $result['ok'];
} else {
    ai_log_set_context([
        'kind' => 'design', 'user_id' => (int) $user['id'],
        'reference_type' => 'contents', 'reference_id' => $contentId,
        'options' => ['ratio' => $ratioKey, 'size' => $ratioMeta['size'] ?? null, 'format' => $format, 'slide' => $slideNo],
        'images' => $inputImages,
    ]);
    $result = ai_generate_image($designPrompt, array_column($inputImages, 'uri'));
    $modelReceivedImages = !empty($inputImages) && $result['ok'];
}

if (!$result['ok']) {
    credits_add((int) $user['id'], $cost, 'استرداد: فشل توليد التصميم #' . $contentId, null, 'refund', $contentId);
    json_response(['ok' => false, 'error' => $result['error']]);
}

// المقاس المخصص: الصورة بالمقاس المطلوب بالظبط (قص من النص من غير مط)
if ($__exact) {
    design_fit_exact($result['path'], $__exact[0], $__exact[1]);
}

// ═══ Fallback: لو الموديل ما استلمش الصور (مسار قديم) → اللوجو بـ GD ═══
if ($includeLogo && !$modelReceivedImages) {
    brand_logo_apply($result['path'], $brand['logo_path']);
}

// العلامة المائية للمنصة (لو مفعّلة)
if (watermark_for_user((int) $user['id'])) {
    watermark_apply($result['path']);
}

$designId = db_insert_row('content_designs', [
    'content_id' => $contentId,
    'user_id' => $user['id'],
    'image_path' => $result['path'],
    'prompt' => $designPrompt,
    'model' => $result['model'],
    'credits_used' => $cost,
    'ratio' => $__exact ? mb_substr($__exact[0] . 'x' . $__exact[1], 0, 10) : $ratioKey,
] + (formats_ready() ? ['slide_no' => $slideNo, 'refs_json' => $refLog ? json_encode($refLog, JSON_UNESCAPED_UNICODE) : null] : []));

ai_stamp('content_designs', (int) $designId, 'design_base', $result);   // الإصدار والمزود (نظام الجودة)

// تعديل تصميم موجود بالكلام: طلب التعديل مش «اتجاه التصميم» — ومايتكتبش مكانه
$isDesignEdit = ($_POST['design_edit'] ?? '') === '1';
if ($customPrompt !== '' && !$isDesignEdit) {
    db_run('UPDATE contents SET design_direction = ? WHERE id = ?', [$customPrompt, $contentId]);
}
if ($isDesignEdit) {
    // النسخة الجديدة بتبقى الغلاف — والقديمة موجودة في «التصميمات السابقة» لو عايز ترجعلها
    db_run('UPDATE contents SET selected_image_id = ? WHERE id = ? AND user_id = ?', [$designId, $contentId, $user['id']]);
}

ai_log_usage((int) $user['id'], 'design', $result['model'], [], $contentId);

// واتساب + داخل المنصة (مرة كل 30 دقيقة بالكتير لنفس العميل — منع التكرار في Notifier)
require_once __DIR__ . '/../../includes/Notifier.php';
Notifier::notify((int) $user['id'], 'designs_ready', ['count' => 1], ['url' => 'content-view.php?id=' . (int) $contentId]);

json_response([
    'ok' => true,
    'design_id' => $designId,
    'image_url' => url('storage/' . $result['path']),
    'slide_no' => $slideNo,
    'ratio' => $__exact ? $__exact[0] . 'x' . $__exact[1] : $ratioKey,
]);
