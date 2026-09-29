<?php
/**
 * Spread AI v2 — استوديو التصميم المستقل (AJAX)
 * actions:
 *   idea     → توليد فكرة تصميم من المحتوى
 *   generate → توليد التصميم (from_content / before_after / free / personal)
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
$action = $_POST['action'] ?? 'generate';

// ═══════════════════════════════════════════════════════
// 1) فكرة تصميم من المحتوى
// ═══════════════════════════════════════════════════════
if ($action === 'idea') {
    $text = mb_substr(trim($_POST['content_text'] ?? ''), 0, 2000);
    if (mb_strlen($text) < 10) {
        json_response(['ok' => false, 'error' => 'اكتب المحتوى الأول (10 حروف على الأقل)']);
    }
    if (!rate_limit('studio_idea', 'u' . $user['id'], 10, 600)) {
        json_response(['ok' => false, 'error' => 'وصلت للحد الأقصى — استنى شوية']);
    }
    $cost = (int) get_setting('studio_idea_cost', 1);
    if (!credits_consume((int) $user['id'], $cost, 'فكرة تصميم — الاستوديو')) {
        json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
    }

    $brandCtx = '';
    if ($brand) {
        if (!empty($brand['business_name'])) $brandCtx .= "- البراند: {$brand['business_name']}\n";
        if (!empty($brand['colors']))        $brandCtx .= "- الألوان: {$brand['colors']}\n";
        if (!empty($brand['design_rules']))  $brandCtx .= "- شروط التصميم: {$brand['design_rules']}\n";
        if (!empty($brand['visual_identity'])) $brandCtx .= "- الهوية البصرية: " . mb_substr($brand['visual_identity'], 0, 800) . "\n";
    }

    $prompt = "أنت مدير فني محترف. اقرأ المحتوى التالي واكتب فكرة تصميم سوشيال ميديا له في 2-4 جمل بالعربي: التكوين، العناصر الرئيسية، الألوان، والنص الظاهر على التصميم (لو مناسب).\n\n"
        . ($brandCtx ? "الهوية:\n{$brandCtx}\n" : '')
        . "المحتوى:\n{$text}\n\nاكتب الفكرة مباشرة بدون مقدمات.";

    $ai = ai_generate($prompt);
    if (!$ai['ok']) {
        credits_add((int) $user['id'], $cost, 'استرداد: فشل فكرة التصميم', null, 'refund');
        json_response(['ok' => false, 'error' => $ai['error']]);
    }
    $idea = trim(preg_replace('/\[\/?[A-Z_]+\]/', '', $ai['response']));
    ai_log_usage((int) $user['id'], 'studio_idea', $ai['model'] ?? 'router', $ai['usage'] ?? []);
    json_response(['ok' => true, 'idea' => mb_substr($idea, 0, 1200)]);
}

// ═══════════════════════════════════════════════════════
// 2) توليد التصميم
// ═══════════════════════════════════════════════════════
$externalStyleUri = null;
$mode = in_array($_POST['mode'] ?? '', ['from_content', 'before_after', 'free', 'personal', 'from_image', 'custom', 'trend'], true) ? $_POST['mode'] : 'free';
require_once __DIR__ . '/../../includes/studio-config.php';
// كلام العميل الأصلي (قبل الـ Brief) — المقاس بيتقري منه الأول
$userText = mb_substr(trim($_POST['user_text'] ?? ''), 0, 1500);
// ترند من لوحة الترندات
$trend = null;
if ($mode === 'trend') {
    require_once __DIR__ . '/../../includes/trends.php';
    $trend = trend_get_live((int) ($_POST['trend_id'] ?? 0));
    if (!$trend) {
        json_response(['ok' => false, 'error' => 'الترند ده مابقاش متاح']);
    }
}
// طريقة جديدة من الأدمن
$method = null;
if ($mode === 'custom') {
    $method = studio_method((string) ($_POST['method'] ?? ''));
    if (!$method || $method['kind'] !== 'custom') {
        json_response(['ok' => false, 'error' => 'الطريقة دي مش متاحة']);
    }
}
$contentText = mb_substr(trim($_POST['content_text'] ?? ''), 0, 2000);
$designIdea  = mb_substr(trim($_POST['design_idea'] ?? ''), 0, 1200);
$freePrompt  = mb_substr(trim($_POST['free_prompt'] ?? ''), 0, 1200);
$includeLogo = ($_POST['include_logo'] ?? '0') === '1' && $brand && !empty($brand['logo_path']);
$templateId  = (int) ($_POST['template_id'] ?? 0);

// تعديل بالكلام على تصميم موجود (⑤-ب): «كبّر اللوجو» ← نسخة جديدة منه
$editOf = null;
if (($_POST['design_edit'] ?? '') === '1') {
    $editOf = db_one('SELECT * FROM studio_designs WHERE id = ? AND user_id = ?', [(int) ($_POST['edit_of'] ?? 0), $user['id']]);
    if (!$editOf) {
        json_response(['ok' => false, 'error' => 'التصميم ده مش موجود']);
    }
    if (mb_strlen($freePrompt) < 3) {
        json_response(['ok' => false, 'error' => 'اكتب عايز تعدّل إيه']);
    }
}

if (!rate_limit('studio_design', 'u' . $user['id'], 6, 600)) {
    json_response(['ok' => false, 'error' => 'وصلت للحد الأقصى لتوليد التصميمات — استنى شوية']);
}

// صور المشروع المحفوظ (مسودة): العميل رجع يكمّل — الصور اترفعت قبل كده ومش محتاج يرفعها تاني
require_once __DIR__ . '/../../includes/studio-drafts.php';
$draftFiles = [];
if (!empty($_POST['draft_id'])) {
    $draftFiles = studio_draft_files(studio_draft_get((int) $_POST['draft_id'], (int) $user['id']));
}
$draftSlot = function (string $field): string {
    $map = ['before_image' => 'before', 'after_image' => 'after', 'source_image' => 'source', 'personal_image' => 'personal'];
    return $map[$field] ?? (preg_match('/^custom_image_(\d+)$/', $field, $m) ? 'c' . $m[1] : $field);
};
$hasImage = function (string $field) use ($draftFiles, $draftSlot): bool {
    if (!empty($_FILES[$field]['name'])) return true;
    $p = $draftFiles[$draftSlot($field)] ?? '';
    return $p !== '' && is_file(STORAGE_PATH . '/' . ltrim($p, '/'));
};

// رفع الصور حسب الوضع
$uploads = [];  // [['path' => rel, 'role' => text]]
$grab = function (string $field, string $role) use (&$uploads, $draftFiles, $draftSlot) {
    if (!empty($_FILES[$field]['name'])) {
        $up = upload_image($_FILES[$field], 'references');
        if ($up['ok']) {
            $uploads[] = ['path' => $up['path'], 'role' => $role];
        }
        return;
    }
    $p = $draftFiles[$draftSlot($field)] ?? '';
    if ($p !== '' && is_file(STORAGE_PATH . '/' . ltrim($p, '/'))) {
        $uploads[] = ['path' => $p, 'role' => $role];
    }
};

if ($editOf) {
    $uploads[] = ['path' => $editOf['image_path'], 'role' => "the CURRENT DESIGN TO EDIT — reproduce this exact design (same layout, elements, texts, colors and composition) and apply ONLY the change requested in the instructions. Do not redesign or add anything else."];
} elseif ($mode === 'before_after') {
    $grab('before_image', "the BEFORE photo — place it on one side of a professional before/after (قبل/بعد) comparison layout, keeping it accurate and unedited in content.");
    $grab('after_image', "the AFTER photo — place it on the other side of the comparison, keeping it accurate.");
    if (count($uploads) < 2) {
        json_response(['ok' => false, 'error' => 'ارفع صورتين: قبل وبعد']);
    }
} elseif ($mode === 'trend') {
    // صورة الترند: الهيكل بس — مش المحتوى ولا البراند
    if (!empty($trend['ref_path'])) {
        $uploads[] = ['path' => $trend['ref_path'], 'role' => "the TREND REFERENCE — copy ONLY its structure, layout, framing and pacing. Do NOT copy its people, products, text, logos or brand identity. Rebuild it entirely for our brand."];
    }
    if ($designIdea === '') {
        json_response(['ok' => false, 'error' => 'اختار فكرة الأول']);
    }
} elseif ($mode === 'custom') {
    foreach ($method['uploads'] as $i => $u) {
        $grab('custom_image_' . $i, $u['role'] !== '' ? $u['role'] : ('the ' . $u['label'] . ' photo — use it in the design exactly as it is.'));
        if ($u['required'] && !$hasImage('custom_image_' . $i)) {
            json_response(['ok' => false, 'error' => 'ارفع «' . $u['label'] . '»']);
        }
    }
    if ($method['text']['required'] && $userText === '' && $designIdea === '') {
        json_response(['ok' => false, 'error' => 'اكتب «' . ($method['text']['label'] ?: 'الوصف') . '»']);
    }
} elseif ($mode === 'from_image') {
    // «من صورة» (Design Studio الجديد): الصورة هي بطل التصميم نفسه — مش مرجع ستايل
    $grab('source_image', "the MAIN SUBJECT photo (the client's real product/place/result) — make it the hero of the design. Keep it exactly as it is: do NOT redraw, alter, or replace it. Build the background, layout and text around it.");
    if (!$uploads) {
        json_response(['ok' => false, 'error' => 'ارفع الصورة الأول']);
    }
} elseif ($mode === 'personal') {
    $grab('personal_image', "the client's PERSONAL photo — build the whole concept around this person, keeping their face and appearance completely accurate and realistic.");
    if (!$uploads) {
        json_response(['ok' => false, 'error' => 'ارفع صورتك الشخصية الأول']);
    }
} else {
    $grab('source_image', "the STYLE REFERENCE — strictly follow this image's visual style: colors, mood, composition, and aesthetic.");
    // أو مرجع مختار من معرض الإلهام / صوره (الملف المرفوع له الأولوية)
    if (!$uploads && !empty($_POST['style_ref'])) {
        $refPath = resolve_style_ref((string) $_POST['style_ref'], (int) $user['id'], $brand);
        if ($refPath && strpos($refPath, 'url:') === 0) {
            $externalStyleUri = remote_image_data_uri(substr($refPath, 4));
        } elseif ($refPath) {
            $uploads[] = ['path' => $refPath, 'role' => "the STYLE REFERENCE — strictly follow this image's visual style: colors, mood, composition, and aesthetic."];
        }
    }
}

// التحقق من المطلوب حسب الوضع
if ($mode === 'from_content' && $contentText === '' && $designIdea === '') {
    json_response(['ok' => false, 'error' => 'اكتب المحتوى أو فكرة التصميم الأول']);
}
if ($mode === 'free' && $freePrompt === '') {
    json_response(['ok' => false, 'error' => 'اكتب برومبت التصميم الأول']);
}

if ($__g = plan_gate((int) $user['id'], 'designs')) {
    json_response(['ok' => false, 'error' => $__g, 'code' => 'quota']);
}
$cost = cost_for('content_design_cost');
if (!credits_consume((int) $user['id'], $cost, 'تصميم من الاستوديو (' . $mode . ')', 'studio', null)) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

// ═══ بناء البرومبت ═══
$identity = [];
if ($brand) {
    $identity[] = 'Brand: ' . ($brand['business_name'] ?? '');
    if (!empty($brand['colors']))       $identity[] = 'BRAND COLORS (primary palette): ' . $brand['colors'];
    if (!empty($brand['design_rules'])) $identity[] = 'DESIGN RULES (must follow): ' . $brand['design_rules'];
    if (!empty($brand['ai_summary']))   $identity[] = 'Brand identity summary: ' . mb_substr($brand['ai_summary'], 0, 500);
    if (!empty($brand['visual_identity'])) $identity[] = 'VISUAL IDENTITY GUIDE (follow these style rules precisely): ' . mb_substr($brand['visual_identity'], 0, 1500);
}
$identityBlock = $identity ? implode("\n", $identity) . "\n" : '';

$concept = match ($mode) {
    'from_content' => ($designIdea !== '' ? "Design concept (follow closely): {$designIdea}\n" : '')
        . ($contentText !== '' ? "The content (Arabic) this design represents: " . mb_substr($contentText, 0, 500) . "\n" : ''),
    'before_after' => "Create a professional BEFORE/AFTER comparison design using the two attached photos. Split layout with clear Arabic labels (قبل / بعد)."
        . ($designIdea !== '' ? " Extra direction: {$designIdea}" : '') . "\n",
    'trend' => ($trend['prompt_template'] !== ''
            ? strtr($trend['prompt_template'], ['{idea}' => $designIdea, '{brand}' => (string) ($brand['business_name'] ?? ''), '{structure}' => implode(' → ', $trend['structure'])])
            : ("Create a social media creative that follows this TREND BLUEPRINT (use its structure — not a copy of it):\n" . trend_blueprint_text($trend)
               . "\nThe idea for our brand (follow closely): {$designIdea}"))
        . ['Reel' => "\nFormat: this image is the COVER / key frame of a vertical reel — bold hook text, one strong moment.",
           'Carousel' => "\nFormat: this image is the FIRST slide of a carousel — it must make people swipe.",
           'Meme' => "\nFormat: a relatable meme-style image with short punchy Arabic text.",
           'Visual' => ''][$trend['type']] . "\n",
    'custom' => studio_method_prompt($method, $designIdea !== '' ? $designIdea : $userText, (string) ($_POST['purpose'] ?? ''), (string) ($brand['business_name'] ?? '')) . "\n",
    'from_image' => "Create a professional social-media advertising design built around the attached MAIN SUBJECT photo."
        . ($designIdea !== '' ? " Design direction (follow closely): {$designIdea}" : '') . "\n",
    'personal' => "Create a personal-brand concept design featuring the attached personal photo."
        . ($designIdea !== '' ? " Concept direction: {$designIdea}" : ' Modern, professional, social-media ready.') . "\n",
    default => "Design concept (follow closely): {$freePrompt}\n",
};
if ($editOf) {
    $concept = "EDIT the attached CURRENT DESIGN. Keep everything exactly the same and apply ONLY this change (the client's request, in Arabic): {$freePrompt}\n";
}

// المقاس: لو العميل كتب مقاس (1080×1350 · مقاس 4:5 · ستوري) ده الأساسي — مش الاختيار
$ratioRes = studio_ratio_resolve($editOf ? (string) $editOf['ratio'] : (string) ($_POST['ratio'] ?? ''), $userText, $freePrompt, $designIdea, $contentText);
$ratioKey = $ratioRes['key'];
$ratioMeta = $ratioRes['meta'];

$designPrompt = "Design a professional social media image.\n" . studio_ratio_hint($ratioRes) . "\n" . $identityBlock . $concept;

// قالب البرومبت من الأدمن
$tpl = null;
if ($templateId) {
    $tpl = db_one('SELECT * FROM design_templates WHERE id = ? AND is_active = 1', [$templateId]);
    if ($tpl) {
        $designPrompt .= "Style template: " . $tpl['prompt_snippet'] . "\n";
    }
}

// اللوجو كمدخل للموديل
$inputImages = [];
if (!empty($externalStyleUri)) {
    $inputImages[] = ['uri' => $externalStyleUri, 'role' => "the STYLE REFERENCE — strictly follow this image's visual style: colors, mood, composition, and aesthetic."];
}
if ($includeLogo) {
    $uri = image_data_uri($brand['logo_path']);
    if ($uri) {
        $inputImages[] = ['uri' => $uri, 'role' => "the brand's official LOGO — integrate this exact logo naturally (top-right unless design rules say otherwise). Do NOT redraw or alter it."];
    }
}
foreach ($uploads as $u) {
    $uri = image_data_uri($u['path']);
    if ($uri) {
        $inputImages[] = ['uri' => $uri, 'role' => $u['role']];
    }
}
$inputImages = array_slice($inputImages, 0, 4);

if ($inputImages) {
    $designPrompt .= "\nATTACHED IMAGES (in order):\n";
    foreach ($inputImages as $i => $img) {
        $designPrompt .= 'Image ' . ($i + 1) . ' is ' . $img['role'] . "\n";
    }
}
// برومبت نوع التصميم من الأدمن (حسب الوضع)
$__typeKey = ['from_content' => 'general', 'before_after' => 'before_after', 'free' => 'general', 'personal' => 'personal'][$mode] ?? 'general';
$__typeP = type_prompt('design', $__typeKey);
if ($__typeP !== '') {
    $designPrompt .= "\n" . $__typeP . "\n";
}
$designPrompt .= "Style: modern, clean, suitable for Facebook/Instagram. No watermarks, no fake or invented logos.";

// ═══ التوليد ═══
$modelReceivedImages = false;
if (function_exists('smart_ai_enabled') && smart_ai_enabled()) {
    $result = smart_ai_image($designPrompt, [
        'size' => $ratioMeta['size'],
        'user_id' => (int) $user['id'],
        'reference_type' => 'studio',
        'reference_images' => array_column($inputImages, 'uri'),
    ]);
    $modelReceivedImages = !empty($inputImages) && $result['ok'];
} else {
    ai_log_set_context([
        'kind' => 'studio', 'user_id' => (int) $user['id'],
        'reference_type' => 'studio_designs', 'reference_id' => null,
        'options' => ['mode' => $mode ?? null, 'ratio' => $ratio ?? null],
        'images' => $inputImages,
    ]);
    $result = ai_generate_image($designPrompt, array_column($inputImages, 'uri'));
    $modelReceivedImages = !empty($inputImages) && $result['ok'];
}

if (!$result['ok']) {
    credits_add((int) $user['id'], $cost, 'استرداد: فشل تصميم الاستوديو', null, 'refund');
    json_response(['ok' => false, 'error' => $result['error']]);
}

// fallback لوجو GD
if ($includeLogo && !$modelReceivedImages) {
    brand_logo_apply($result['path'], $brand['logo_path']);
}
if (watermark_ready()) {
    watermark_apply($result['path']);
}

$designId = db_insert(
    'INSERT INTO studio_designs (user_id, mode, content_text, design_idea, prompt, image_path, model, template_id, credits_used, ratio'
    . ($trend ? ', trend_id' : '') . ')
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?' . ($trend ? ', ?' : '') . ')',
    array_merge([
        $user['id'], $mode === 'custom' ? $method['key'] : $mode,   // الطريقة الجديدة بمفتاحها — الاسم بيتجاب من الأدمن وقت العرض
        $contentText ?: null,
        $designIdea ?: ($freePrompt ?: null),
        $designPrompt,
        $result['path'],
        $result['model'],
        $tpl ? $tpl['id'] : null,
        $cost,
        $ratioKey,
    ], $trend ? [(int) $trend['id']] : [])
);

if ($editOf) {
    // نفس الطريقة والـ Brief والترند — وبتتربط بالأصل (V1) عشان النسخ تتعرض مع بعض
    db_run('UPDATE studio_designs SET mode = ?, parent_id = ?, brief = ?, trend_id = ?, design_idea = ? WHERE id = ?', [
        $editOf['mode'], (int) ($editOf['parent_id'] ?: $editOf['id']), $editOf['brief'], $editOf['trend_id'] ?? null,
        mb_substr('تعديل: ' . $freePrompt, 0, 1200), $designId,
    ]);
}

ai_log_usage((int) $user['id'], 'studio_design', $result['model'], [], $designId);

json_response([
    'ok' => true,
    'design_id' => $designId,
    'image_url' => url('storage/' . $result['path']),
    'ratio' => $ratioKey,
    'ratio_typed' => $ratioRes['typed'],
    'ratio_match' => $ratioRes['match'],
]);
