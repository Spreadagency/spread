<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/prompt-builder.php';
require_once __DIR__ . '/../../includes/brand-kit.php';
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

// Brand Kit: نفس اختيارات البوست + أي chip العميل غيّره (للبوست ده بس)
$bkNew = bk_overrides_from_input($_POST);
$bkBrief = bk_enabled() ? bk_brief_for_content($content, (int) $user['id'], $bkNew) : null;
$options['bk_brief'] = $bkBrief;
// chips البوست (المحفوظة + الجديدة) ليها الأولوية على اختيارات المرة اللي فاتت
$bkAllO = array_merge(bk_arr($content['brand_overrides'] ?? ''), $bkNew);
$options['tone'] = $bkAllO['tone'] ?? $options['tone'];
if (!empty($bkAllO['caption_length'])) $options['length'] = $bkAllO['caption_length'];
$options['bk_overrides'] = array_intersect_key($bkAllO, ['tone' => 1, 'caption_length' => 1, 'goal' => 1]);
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
$bkWhy = BrandContext::parseWhy((string) $ai['response']);
$parsed['content'] = BrandContext::enforce(BrandContext::stripWhy($parsed['content']), (int) $user['id']);
$parsed['cta'] = BrandContext::enforce((string) $parsed['cta'], (int) $user['id']);
$parsed['hashtags'] = BrandContext::enforce((string) $parsed['hashtags'], (int) $user['id']);

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

if ($bkBrief) {
    $bkAll = array_merge(bk_arr($content['brand_overrides'] ?? ''), $bkNew);
    unset($bkAll['request']);
    BrandContext::attach($contentId, (int) $user['id'], $bkBrief, $bkWhy, $bkAll);
}
bk_feedback((int) $user['id'], $contentId, 'regenerate', 'post', null, $bkNew ? bk_json($bkNew) : null);

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
    'reasons' => $bkWhy ?: ($bkBrief['reasons'] ?? []),
]);
