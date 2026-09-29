<?php
/**
 * Streaming content generation (SSE) — feature 24
 * Emits: event chunks {t: "..."} then final event {done: true, content_id, ...}
 * Falls back gracefully — the frontend uses the normal endpoint if this fails.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/prompt-builder.php';
require_once __DIR__ . '/../../includes/ai.php';
require_once __DIR__ . '/../../includes/rate-limit.php';

require_login();
require_csrf();

$user = current_user();
$brand = user_brand();

// SSE headers + kill buffering (cPanel/Apache safe)
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) { @ob_end_flush(); }

function sse(array $data): void
{
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    // padding forces intermediate proxies/Apache to flush
    echo ': ' . str_repeat(' ', 2048) . "\n\n";
    @flush();
}

function sse_fail(string $error): void
{
    sse(['done' => true, 'ok' => false, 'error' => $error]);
    exit;
}

if (!rate_limit('generate', 'u' . $user['id'], 10, 600)) {
    sse_fail('وصلت للحد الأقصى من التوليد. استنى شوية وجرب تاني.');
}
if (!$brand || empty($brand['business_name'])) {
    sse_fail('كمّل بيانات الهوية الأساسية أولًا');
}

$cost = cost_for('content_generation_cost');

$options = [
    'content_type' => $_POST['content_type'] ?? 'introductory',
    'platform'     => $_POST['platform'] ?? 'both',
    'length'       => $_POST['length'] ?? 'medium',
    'tone'         => $_POST['tone'] ?? 'simple',
    'dialect'      => in_array($_POST['dialect'] ?? '', ['egyptian', 'gulf', 'msa']) ? $_POST['dialect'] : 'egyptian',
    'template_id'  => (int) ($_POST['template_id'] ?? 0),
    'extra_notes'  => mb_substr(trim($_POST['extra_notes'] ?? ''), 0, 500),
];

$visionUrls = [];
if (!empty($_POST['use_logo']) && !empty($brand['logo_path'])) {
    $visionUrls[] = rtrim(APP_URL, '/') . '/storage/' . $brand['logo_path'];
}
if (!empty($_POST['use_personal_image']) && !empty($brand['personal_image_path'])) {
    $visionUrls[] = rtrim(APP_URL, '/') . '/storage/' . $brand['personal_image_path'];
}

$finalPrompt = build_final_prompt($brand, $options, $options['extra_notes']);

// 8-ب: حصة المنشورات
if ($__g = plan_gate((int) $user['id'], 'posts')) {
    sse_fail($__g);
}
// Credits first — refund on failure
if (!credits_consume((int) $user['id'], $cost, 'توليد محتوى', 'content', null)) {
    sse_fail(credits_short_msg($cost));
}

$ai = ai_generate_stream($finalPrompt, function (string $delta) {
    sse(['t' => $delta]);
}, $visionUrls);

if (!$ai['ok']) {
    credits_add((int) $user['id'], $cost, 'استرداد: فشل توليد المحتوى', null, 'refund', null);
    sse_fail($ai['error'] ?? 'فشل التوليد');
}

$parsed = parse_ai_response($ai['response']);

$contentId = db_insert_row('contents', [
    'user_id'            => $user['id'],
    'brand_profile_id'   => $brand['id'],
    'content_type'       => $options['content_type'],
    'platform'           => $options['platform'],
    'length'             => $options['length'],
    'tone'               => $options['tone'],
    'dialect'            => $options['dialect'],
    'template_id'        => $options['template_id'] ?: null,
    'model'              => $ai['model'] ?? null,
    'tokens_in'          => $ai['usage']['in'] ?? 0,
    'tokens_out'         => $ai['usage']['out'] ?? 0,
    'extra_notes'        => $options['extra_notes'],
    'use_logo'           => !empty($_POST['use_logo']) ? 1 : 0,
    'use_personal_image' => !empty($_POST['use_personal_image']) ? 1 : 0,
    'final_prompt'       => $finalPrompt,
    'generated_text'     => $parsed['content'],
    'hashtags'           => $parsed['hashtags'],
    'cta'                => $parsed['cta'],
    'status'             => 'generated',
    'credits_used'       => $cost,
]);

// ربط المنشور بالحملة لو جاي منها (المرحلة 1)
$__campaignId = (int) ($_POST['campaign_id'] ?? 0);
if ($__campaignId > 0 && $contentId > 0) {
    $__own = db_one('SELECT id FROM campaigns WHERE id = ? AND user_id = ? AND status <> "archived"', [$__campaignId, (int) $user['id']]);
    if ($__own) {
        db_run('UPDATE contents SET campaign_id = ? WHERE id = ?', [$__campaignId, $contentId]);
        db_run('UPDATE campaigns SET max_stage = GREATEST(max_stage, 2), revision = revision + 1 WHERE id = ?', [$__campaignId]);
    }
}

save_content_version($contentId, $parsed['content'], $parsed['hashtags'], $parsed['cta'], 'ai', 'الإصدار الأول');
ai_log_usage((int) $user['id'], 'generate', $ai['model'] ?? '', $ai['usage'] ?? [], $contentId);

sse([
    'done' => true,
    'ok' => true,
    'content_id' => $contentId,
    'content' => $parsed['content'],
    'hashtags' => $parsed['hashtags'],
    'cta' => $parsed['cta'],
]);
