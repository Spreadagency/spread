<?php
/**
 * Spread AI v2 — إنتاج فكرة واحدة → بوست كامل + فكرة تصميم (Phase 3)
 * بيتنادى من الواجهة في loop (فكرة فكرة) عشان مفيش timeout
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/social.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/ai.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/plan-functions.php';
if (is_file(__DIR__ . '/../../includes/smart-ai.php')) {
    require_once __DIR__ . '/../../includes/smart-ai.php';
}

require_login();
require_csrf();

$user = current_user();
$brand = user_brand();
$ideaId = (int) ($_POST['idea_id'] ?? 0);

if (!$brand || empty($brand['business_name'])) {
    json_response(['ok' => false, 'error' => 'كمّل بيانات الهوية أولًا']);
}

$idea = db_one(
    'SELECT pi.* FROM plan_ideas pi JOIN content_plans p ON p.id = pi.plan_id
     WHERE pi.id = ? AND p.user_id = ?',
    [$ideaId, $user['id']]
);
if (!$idea) {
    json_response(['ok' => false, 'error' => 'الفكرة غير موجودة']);
}
if ($idea['status'] === 'produced' && $idea['content_id']) {
    json_response(['ok' => true, 'already' => true, 'content_id' => (int) $idea['content_id']]);
}
if ($idea['status'] !== 'selected') {
    json_response(['ok' => false, 'error' => 'اختار الفكرة الأول قبل الإنتاج']);
}

// Rate limit: 15 إنتاج كل 10 دقايق
if (!rate_limit('plan_produce', 'u' . $user['id'], 15, 600)) {
    json_response(['ok' => false, 'error' => 'وصلت للحد الأقصى. استنى شوية وكمّل الإنتاج.']);
}

if ($__g = plan_gate((int) $user['id'], 'posts')) {
    json_response(['ok' => false, 'error' => $__g, 'code' => 'quota']);
}
$cost = cost_for('content_generation_cost');
if (credits_balance((int) $user['id']) < $cost) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

$prompt = build_produce_prompt($brand, $idea);

if (!credits_consume((int) $user['id'], $cost, 'إنتاج بوست من الخطة: ' . mb_substr($idea['title'], 0, 60), 'plan_idea', $ideaId)) {
    json_response(['ok' => false, 'error' => credits_short_msg()]);
}

if (function_exists('smart_ai_enabled') && smart_ai_enabled()) {
    $ai = smart_ai_generate($prompt, [], 'plan_produce', [
        'user_id' => (int) $user['id'], 'reference_type' => 'plan_idea', 'reference_id' => $ideaId,
        'job_type' => 'plan_produce', 'max_tokens' => 2500, 'temperature' => 0.8,
    ]);
} else {
    $ai = ai_generate($prompt);
}

if (!$ai['ok']) {
    credits_add((int) $user['id'], $cost, 'استرداد: فشل إنتاج الفكرة #' . $ideaId, null, 'refund', $ideaId);
    json_response(['ok' => false, 'error' => $ai['error']]);
}

$parsed = parse_produced_post($ai['response']);
if ($parsed['content'] === '') {
    credits_add((int) $user['id'], $cost, 'استرداد: رد غير صالح للفكرة #' . $ideaId, null, 'refund', $ideaId);
    json_response(['ok' => false, 'error' => 'الرد غير صالح — جرب تاني']);
}

// إنشاء المحتوى — نفس بنية contents الحالية + الحقول الجديدة
$contentId = db_insert_row('contents', [
    'user_id'          => (int) $user['id'],
    'brand_profile_id' => (int) $brand['id'],
    'content_type'     => $idea['angle'],
    'platform'         => 'both',
    'length'           => 'medium',
    'tone'             => $brand['tone'] ?? null,
    'extra_notes'      => 'من خطة المحتوى — فكرة: ' . mb_substr($idea['title'], 0, 200),
    'final_prompt'     => $prompt,
    'generated_text'   => $parsed['content'],
    'hashtags'         => $parsed['hashtags'],
    'cta'              => $parsed['cta'],
    'design_direction' => $parsed['design_direction'],
    'image_prompt'     => $parsed['image_prompt'],
    'plan_idea_id'     => $ideaId,
    'status'           => 'generated',
    'credits_used'     => $cost,
    // تاريخ الخطة هو موعد النشر الثابت للبوست (الساعة الافتراضية من الإعدادات)
    'scheduled_at'     => !empty($idea['scheduled_date'])
        ? default_publish_datetime((string) $idea['scheduled_date'])
        : null,
]);

db_run('UPDATE plan_ideas SET status = "produced", content_id = ? WHERE id = ?', [$contentId, $ideaId]);
db_run('UPDATE content_plans SET status = "producing", credits_used = credits_used + ? WHERE id = ?', [$cost, $idea['plan_id']]);

// لو كل المختار اتنتج → الخطة done
$remaining = db_count('SELECT COUNT(*) FROM plan_ideas WHERE plan_id = ? AND status = "selected"', [$idea['plan_id']]);
if ($remaining === 0) {
    db_run('UPDATE content_plans SET status = "done" WHERE id = ?', [$idea['plan_id']]);
}

ai_log_usage((int) $user['id'], 'generate', $ai['model'] ?? 'router', $ai['usage'] ?? [], $contentId);

json_response([
    'ok' => true,
    'content_id' => $contentId,
    'title' => $idea['title'],
    'remaining' => $remaining,
]);
