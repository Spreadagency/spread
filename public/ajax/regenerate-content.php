<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/prompt-builder.php';
require_once __DIR__ . '/../../includes/ai.php';

require_once __DIR__ . '/../../includes/rate-limit.php';

require_login();
require_csrf();
session_release();   // العملية طويلة — مانقفلش باقي صفحات العميل لحد ما تخلص

$user = current_user();
$contentId = (int) ($_POST['content_id'] ?? 0);
$content = get_user_content($contentId, (int) $user['id']);

if (!$content) {
    json_response(['ok' => false, 'error' => 'المحتوى غير موجود']);
}

// Rate limit: 10 regenerations per 10 minutes per user
if (!rate_limit('regenerate', 'u' . $user['id'], 10, 600)) {
    json_response(['ok' => false, 'error' => 'وصلت للحد الأقصى من إعادة التوليد. استنى شوية وجرب تاني.']);
}

$cost = cost_for('content_regeneration_cost');

$brand = user_brand();
$options = [
    'content_type' => $content['content_type'],
    'platform'     => $content['platform'],
    'length'       => $content['length'],
    'tone'         => $content['tone'],
    'extra_notes'  => $content['extra_notes'] ?? '',
];

$finalPrompt = build_final_prompt($brand, $options, $options['extra_notes']);

// Consume credits FIRST — refund on failure
if (!credits_consume((int) $user['id'], $cost, 'إعادة توليد #' . $contentId, 'regenerate', $contentId)) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

$ai = ai_generate($finalPrompt);

if (!$ai['ok']) {
    credits_add((int) $user['id'], $cost, 'استرداد: فشل إعادة التوليد #' . $contentId, null, 'refund', $contentId);
    json_response(['ok' => false, 'error' => $ai['error'] ?? 'فشل التوليد']);
}

$parsed = parse_ai_response($ai['response']);

// Save the OLD version first
save_content_version(
    $contentId,
    $content['generated_text'],
    $content['hashtags'],
    $content['cta'],
    'ai',
    'إصدار قبل إعادة التوليد'
);

// Update main content
db_run(
    'UPDATE contents SET generated_text=?, hashtags=?, cta=?, final_prompt=?, status=?, credits_used = credits_used + ?, updated_at=NOW() WHERE id = ?',
    [$parsed['content'], $parsed['hashtags'], $parsed['cta'], $finalPrompt, 'generated', $cost, $contentId]
);

// Actual usage tracking (feature 25)
ai_log_usage((int) $user['id'], 'regenerate', $ai['model'] ?? '', $ai['usage'] ?? [], $contentId);
try {
    db_run('UPDATE contents SET model = ?, tokens_in = tokens_in + ?, tokens_out = tokens_out + ? WHERE id = ?',
        [$ai['model'] ?? null, $ai['usage']['in'] ?? 0, $ai['usage']['out'] ?? 0, $contentId]);
} catch (\Throwable $e) {}

json_response([
    'ok' => true,
    'content' => $parsed['content'],
    'hashtags' => $parsed['hashtags'],
    'cta' => $parsed['cta'],
]);
