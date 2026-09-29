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
$cost = cost_for('content_design_cost');
if (!credits_consume((int) $user['id'], $cost, 'توليد تصميم للمحتوى #' . $contentId . ($slideNo ? ' — شريحة ' . $slideNo : ''), 'design', $contentId)) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

// ═══ تجميع مدخلات الموديل: كل صورة بدورها ═══
$inputImages = [];  // [['uri' => data uri, 'role' => وصف الدور بالإنجليزي]]

if ($includeLogo) {
    $uri = image_data_uri($brand['logo_path']);
    if ($uri) {
        $refLog[] = ['role' => 'logo', 'path' => $brand['logo_path']];
        $inputImages[] = ['uri' => $uri, 'role' => "the brand's official LOGO — integrate this exact logo into the design naturally (top-right unless the design rules say otherwise). Do NOT redraw, alter, distort, or recreate it."];
    }
}

if ($includeImages) {
    // الصورة الشخصية / المنتج أولًا
    if (!empty($brand['personal_image_path'])) {
        $uri = image_data_uri($brand['personal_image_path']);
        if ($uri) {
            $refLog[] = ['role' => 'personal', 'path' => $brand['personal_image_path']];
            $inputImages[] = ['uri' => $uri, 'role' => "the client's personal/product photo — feature this person or product prominently in the design, keeping their appearance accurate."];
        }
    }
    foreach (array_slice(get_brand_images((int) $brand['id']), 0, 1) as $img) {
        $uri = image_data_uri($img['image_path']);
        if ($uri) {
            $refLog[] = ['role' => 'brand', 'path' => $img['image_path']];
            $inputImages[] = ['uri' => $uri, 'role' => "a brand photo — you may include or reference it in the design."];
        }
    }
}

$styleUri = $externalStyleUri ?: ($sourceImagePath ? image_data_uri($sourceImagePath) : null);
if ($styleUri) {
    // تعديل تصميم موجود: الصورة دي هي التصميم نفسه اللي هيتعدّل — مش مجرد إلهام بالستايل
    // (لو اتوصفت كـ STYLE REFERENCE الـ AI بيعمل تصميم جديد شبهها بدل ما يعدّل اللي اتطلب بس)
    $refLog[] = ['role' => $carouselRef ? 'carousel_slide_' . $carouselRef : ((($_POST['design_edit'] ?? '') === '1') ? 'edit' : 'style'),
                 'path' => $sourceImagePath, 'url' => $externalStyleUri ? mb_substr((string) $_POST['style_ref'], 0, 300) : null];
    $inputImages[] = ['uri' => $styleUri, 'role' => $carouselRef
        ? "SLIDE {$carouselRef} OF THIS SAME CAROUSEL — keep EXACTLY the same visual system: color palette, typography style, layout grid, margins, background treatment, decorative elements and logo placement. The new slide must look like the next page of the same carousel, NOT a copy: new headline/content, same design language."
        : ((($_POST['design_edit'] ?? '') === '1')
        ? "the CURRENT DESIGN TO EDIT — reproduce this exact design (same layout, elements, texts, colors and composition) and apply ONLY the change requested in the instructions. Do not redesign or add anything else."
        : "the STYLE REFERENCE — strictly follow this image's visual style: colors, mood, composition, typography style, and overall aesthetic.")];
}

// حد أقصى 4 مدخلات (المرجع آخر واحد — بنحافظ عليه لو الكاروسيل)
if (count($inputImages) > 4) {
    $last = array_pop($inputImages); $lastLog = array_pop($refLog);
    $inputImages = array_merge(array_slice($inputImages, 0, 3), [$last]);
    $refLog = array_merge(array_slice($refLog, 0, 3), [$lastLog]);
}

// ═══ بناء برومبت التصميم من الهوية ═══
$identity = [];
$identity[] = 'Brand: ' . ($brand['business_name'] ?? '');
if (!empty($brand['industry'])) $identity[] = 'Industry: ' . $brand['industry'];
if (!empty($brand['colors']))   $identity[] = 'BRAND COLORS (primary palette): ' . $brand['colors'];
if (!empty($brand['design_rules'])) $identity[] = 'DESIGN RULES from the client (must follow strictly): ' . $brand['design_rules'];
if (!empty($brand['visual_identity'])) $identity[] = 'VISUAL IDENTITY GUIDE (follow these style rules precisely): ' . mb_substr($brand['visual_identity'], 0, 1500);
if (!empty($brand['ai_summary'])) $identity[] = 'Brand identity summary: ' . mb_substr($brand['ai_summary'], 0, 700);
$identityBlock = implode("\n", $identity) . "\n";

if ($format === 'carousel' && $slide) {
    $pos = $slideNo === 1 ? 'the COVER slide (strong hook, invites swiping)' : ($slideNo === $slidesTotal ? 'the LAST slide (clear call-to-action)' : 'a middle slide');
    $concept = "This is slide {$slideNo} of {$slidesTotal} of ONE Instagram/Facebook carousel — {$pos}.\n"
        . ($slide['title'] !== '' ? "Slide headline (Arabic — show it as the main text): " . $slide['title'] . "\n" : '')
        . ($slide['text'] !== '' ? "Slide supporting text (Arabic, short): " . $slide['text'] . "\n" : '')
        . ($slide['design'] !== '' ? "Slide design idea: " . $slide['design'] . "\n" : '')
        . ($customPrompt !== '' ? "Extra instructions: " . $customPrompt . "\n" : '')
        . "All slides share the same size, palette and layout system; add a small page indicator ({$slideNo}/{$slidesTotal}).";
} elseif ($customPrompt !== '') {
    $concept = "Design concept (follow closely): " . $customPrompt;
} elseif (!empty($content['image_prompt'])) {
    $concept = "Design concept: " . $content['image_prompt'];
} else {
    $concept = "The post text (Arabic): " . mb_substr($content['generated_text'], 0, 400)
        . "\nCreate a design that visually represents this post.";
}

// المقاس: المكتوب في طلب العميل هو الأساسي (⑤-ب)
require_once __DIR__ . '/../../includes/studio-config.php';
// الستوري دايمًا 9:16 · شرايح الكاروسيل بنفس مقاس الشريحة المرجع
$__ratioIn = $format === 'story' ? '9:16' : ($carouselRatio ?: (string) ($_POST['ratio'] ?? ''));
$ratioRes = studio_ratio_resolve($__ratioIn, $format === 'post' ? $customPrompt : '');
$ratioKey = $ratioRes['key'];
$ratioMeta = $ratioRes['meta'];

$designPrompt = 'Design a professional social media ' . ['post' => 'post image', 'story' => 'vertical STORY image (full-screen 9:16, keep text away from the top/bottom 14%)', 'carousel' => 'CAROUSEL SLIDE image'][$format] . ".\n" . studio_ratio_hint($ratioRes) . "\n"
    . $identityBlock
    . $concept . "\n";

// وصف دور كل صورة مرفقة بالترتيب
if ($inputImages) {
    $designPrompt .= "\nATTACHED IMAGES (in order):\n";
    foreach ($inputImages as $i => $img) {
        $designPrompt .= 'Image ' . ($i + 1) . ' is ' . $img['role'] . "\n";
    }
}

// برومبت نوع التصميم من الأدمن
$__typeP = type_prompt('design', $format === 'story' ? 'story' : 'post');
if ($__typeP === '' && $format === 'story') $__typeP = type_prompt('design', 'post');
if ($__typeP !== '') {
    $designPrompt .= "\n" . $__typeP . "\n";
}
$designPrompt .= "Style: modern, clean, on-brand, suitable for Facebook/Instagram. No watermarks, no fake or invented logos.";

// أسلوب الستوديو (نصيًا)
$styleHints = studio_style_hints((int) $user['id']);
if ($styleHints !== '' && !$sourceImagePath) {
    $designPrompt .= "\nClient's preferred visual style (from selected references): " . $styleHints . ".";
}

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

// ═══ Fallback: لو الموديل ما استلمش الصور (مسار قديم) → اللوجو بـ GD ═══
if ($includeLogo && !$modelReceivedImages) {
    brand_logo_apply($result['path'], $brand['logo_path']);
}

// العلامة المائية للمنصة (لو مفعّلة)
if (watermark_ready()) {
    watermark_apply($result['path']);
}

$designId = db_insert_row('content_designs', [
    'content_id' => $contentId,
    'user_id' => $user['id'],
    'image_path' => $result['path'],
    'prompt' => $designPrompt,
    'model' => $result['model'],
    'credits_used' => $cost,
    'ratio' => $ratioKey,
] + (formats_ready() ? ['slide_no' => $slideNo, 'refs_json' => $refLog ? json_encode($refLog, JSON_UNESCAPED_UNICODE) : null] : []));

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

json_response([
    'ok' => true,
    'design_id' => $designId,
    'image_url' => url('storage/' . $result['path']),
    'slide_no' => $slideNo,
    'ratio' => $ratioKey,
]);
