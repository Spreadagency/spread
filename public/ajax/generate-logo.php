<?php
/**
 * Spread AI v2 — توليد لوجو للبراند بالذكاء الاصطناعي
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/prompt-builder.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/ai.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/uploader.php';
if (is_file(__DIR__ . '/../../includes/smart-ai.php')) {
    require_once __DIR__ . '/../../includes/smart-ai.php';
}

require_login();
require_csrf();
session_release();   // العملية طويلة — مانقفلش باقي صفحات العميل لحد ما تخلص
decode_b64_fields();

$user = current_user();
$brand = user_brand();

if (!$brand) {
    json_response(['ok' => false, 'error' => 'كمّل بيانات الهوية الأساسية الأول']);
}

$desc = mb_substr(trim($_POST['description'] ?? ''), 0, 600);
if (mb_strlen($desc) < 5) {
    json_response(['ok' => false, 'error' => 'اكتب وصف اللوجو']);
}

$style = mb_substr(trim($_POST['style'] ?? ''), 0, 200) ?: 'minimal flat vector logo, clean geometric shapes';

if (!rate_limit('logo_gen', 'u' . $user['id'], 5, 900)) {
    json_response(['ok' => false, 'error' => 'وصلت للحد الأقصى لتوليد اللوجوهات — استنى شوية']);
}

$cost = (int) get_setting('logo_generate_cost', 3);
if (!credits_consume((int) $user['id'], $cost, 'توليد لوجو بالذكاء الاصطناعي', 'logo', (int) $brand['id'])) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

// ── برومبت اللوجو ──
$prompt = "Design a professional LOGO on a plain solid white background.\n"
    . 'Business name: ' . ($brand['business_name'] ?? '') . "\n"
    . (!empty($brand['industry']) ? 'Industry: ' . $brand['industry'] . "\n" : '')
    . (!empty($brand['colors']) ? 'Brand colors to use: ' . $brand['colors'] . "\n" : '')
    . 'Requirements: ' . $desc . "\n"
    . 'Style: ' . $style . ".\n"
    . "The logo must be centered, simple, memorable, and scalable. Vector-like clean edges, no photo textures, "
    . "no drop shadows, no mockups, no business cards, no extra text besides the brand name, no watermark. "
    . "Plain white background only.";

// برومبت اللوجو من الأدمن
$__logoP = type_prompt('design', 'logo');
if ($__logoP !== '') {
    $prompt .= "\n" . $__logoP;
}

if (function_exists('smart_ai_enabled') && smart_ai_enabled()) {
    $result = smart_ai_image($prompt, [
        'user_id' => (int) $user['id'],
        'reference_type' => 'logo',
        'reference_id' => (int) $brand['id'],
    ]);
} else {
    ai_log_set_context([
        'kind' => 'logo', 'user_id' => (int) $user['id'],
        'reference_type' => 'brand_profiles', 'reference_id' => (int) ($brand['id'] ?? 0),
        'images' => $inputImages ?? [],
    ]);
    $result = ai_generate_image($prompt, array_column($inputImages ?? [], 'uri'));
}

if (!$result['ok']) {
    credits_add((int) $user['id'], $cost, 'استرداد: فشل توليد اللوجو', null, 'refund');
    json_response(['ok' => false, 'error' => $result['error']]);
}

// حفظه كلوجو البراند (مع إبقاء القديم في المعرض لو موجود)
$old = $brand['logo_path'] ?? '';
db_run('UPDATE brand_profiles SET logo_path = ? WHERE id = ?', [$result['path'], $brand['id']]);
if ($old && $old !== $result['path']) {
    delete_upload($old);
}

ai_log_usage((int) $user['id'], 'logo_generate', $result['model'], [], (int) $brand['id']);

json_response([
    'ok' => true,
    'image_url' => url('storage/' . $result['path']),
]);
