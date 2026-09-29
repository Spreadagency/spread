<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/prompt-builder.php';
require_once __DIR__ . '/../../includes/ai.php';

require_once __DIR__ . '/../../includes/rate-limit.php';

require_login();
require_csrf();
decode_b64_fields();

$user = current_user();
$brand = user_brand();

// Rate limit: 10 generations per 10 minutes per user
if (!rate_limit('generate', 'u' . $user['id'], 10, 600)) {
    json_response(['ok' => false, 'error' => 'وصلت للحد الأقصى من التوليد. استنى شوية وجرب تاني.']);
}

if (!$brand || empty($brand['business_name'])) {
    json_response(['ok' => false, 'error' => 'كمّل بيانات الهوية الأساسية أولًا']);
}

if ($__g = plan_gate((int) $user['id'], 'posts')) {
    json_response(['ok' => false, 'error' => $__g, 'code' => 'quota']);
}
$cost = cost_for('content_generation_cost');
if (credits_balance((int) $user['id']) < $cost) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

$options = [
    'content_type' => $_POST['content_type'] ?? 'introductory',
    'platform'     => $_POST['platform'] ?? 'both',
    'length'       => $_POST['length'] ?? 'medium',
    'tone'         => $_POST['tone'] ?? 'simple',
    'dialect'      => in_array($_POST['dialect'] ?? '', ['egyptian', 'gulf', 'msa']) ? $_POST['dialect'] : 'egyptian',
    'template_id'  => (int) ($_POST['template_id'] ?? 0),
    // Max 500 chars: limits prompt-injection surface and API cost abuse
    'extra_notes'  => mb_substr(trim($_POST['extra_notes'] ?? ''), 0, 500),
];

// Vision (feature 22): attach brand logo/personal image URLs when requested
$visionUrls = [];
if (!empty($_POST['use_logo']) && !empty($brand['logo_path'])) {
    $visionUrls[] = rtrim(APP_URL, '/') . '/storage/' . $brand['logo_path'];
}
if (!empty($_POST['use_personal_image']) && !empty($brand['personal_image_path'])) {
    $visionUrls[] = rtrim(APP_URL, '/') . '/storage/' . $brand['personal_image_path'];
}

// Build prompt
$finalPrompt = build_final_prompt($brand, $options, $options['extra_notes']);

// Consume credits FIRST (atomic, FOR UPDATE) so no free generations on races
if (!credits_consume((int) $user['id'], $cost, 'توليد محتوى', 'content', null)) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

// Call AI — refund on failure
ai_log_set_context([
    'kind' => 'content', 'user_id' => (int) $user['id'],
    'reference_type' => 'contents', 'reference_id' => $contentId ?? null,
    'options' => ['content_type' => $contentType ?? null, 'platform' => $platform ?? null,
                  'length' => $length ?? null, 'dialect' => $dialect ?? null],
]);
$ai = ai_generate($finalPrompt, $visionUrls);
if (!$ai['ok']) {
    credits_add((int) $user['id'], $cost, 'استرداد: فشل توليد المحتوى', null, 'refund', null);
    json_response(['ok' => false, 'error' => $ai['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي']);
}

// Parse response
$parsed = parse_ai_response($ai['response']);

// Insert content
$contentId = db_insert_row('contents', [
    'user_id'           => $user['id'],
    'brand_profile_id'  => $brand['id'],
    'content_type'      => $options['content_type'],
    'platform'          => $options['platform'],
    'length'            => $options['length'],
    'tone'              => $options['tone'],
    'dialect'           => $options['dialect'],
    'template_id'       => $options['template_id'] ?: null,
    'model'             => $ai['model'] ?? null,
    'tokens_in'         => $ai['usage']['in'] ?? 0,
    'tokens_out'        => $ai['usage']['out'] ?? 0,
    'extra_notes'       => $options['extra_notes'],
    'use_logo'          => !empty($_POST['use_logo']) ? 1 : 0,
    'use_personal_image' => !empty($_POST['use_personal_image']) ? 1 : 0,
    'final_prompt'      => $finalPrompt,
    'generated_text'    => $parsed['content'],
    'hashtags'          => $parsed['hashtags'],
    'cta'               => $parsed['cta'],
    'status'            => 'generated',
    'credits_used'      => $cost,
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

// Save first version
save_content_version($contentId, $parsed['content'], $parsed['hashtags'], $parsed['cta'], 'ai', 'الإصدار الأول');

// Actual usage tracking (feature 25)
ai_log_usage((int) $user['id'], 'generate', $ai['model'] ?? '', $ai['usage'] ?? [], $contentId);

json_response([
    'ok' => true,
    'content_id' => $contentId,
    'content' => $parsed['content'],
    'hashtags' => $parsed['hashtags'],
    'cta' => $parsed['cta'],
]);
