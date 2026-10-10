<?php
/**
 * Spread AI v2 — توليد أفكار خطة المحتوى (Phase 3)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/prompt-builder.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/ai.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/plan-functions.php';
if (is_file(__DIR__ . '/../../includes/smart-ai.php')) {
    require_once __DIR__ . '/../../includes/smart-ai.php';
}

require_login();
require_csrf();
session_release();   // العملية طويلة — مانقفلش باقي صفحات العميل لحد ما تخلص

$user = current_user();
$brand = user_brand();
$planId = (int) ($_POST['plan_id'] ?? 0);

if (!$brand || empty($brand['business_name'])) {
    json_response(['ok' => false, 'error' => 'كمّل بيانات الهوية أولًا']);
}

$plan = db_one('SELECT * FROM content_plans WHERE id = ? AND user_id = ?', [$planId, $user['id']]);
if (!$plan) {
    json_response(['ok' => false, 'error' => 'الخطة غير موجودة']);
}

// Rate limit: 4 توليد أفكار كل 10 دقايق
if (!rate_limit('plan_ideas', 'u' . $user['id'], 4, 600)) {
    json_response(['ok' => false, 'error' => 'وصلت للحد الأقصى. استنى شوية وجرب تاني.']);
}

// التكلفة حسب عدد الأفكار (شرائح من الأدمن)
$cost = idea_credits_for((int) ($plan['ideas_count'] ?? 10));
if (credits_balance((int) $user['id']) < $cost) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

$prompt = build_plan_ideas_prompt($brand, $plan);

if (!credits_consume((int) $user['id'], $cost, 'توليد أفكار خطة #' . $planId, 'plan', $planId)) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

// أفكار كتير محتاجة tokens أكتر — عبر الـ Router لو مفعّل وإلا الطريقة القديمة
if (function_exists('smart_ai_enabled') && smart_ai_enabled()) {
    $ai = smart_ai_generate($prompt, [], 'plan_ideas', [
        'user_id' => (int) $user['id'], 'reference_type' => 'plan', 'reference_id' => $planId,
        'job_type' => 'plan_ideas', 'max_tokens' => 4000, 'temperature' => 0.9, 'json_mode' => true,
    ]);
} else {
    ai_log_set_context([
        'kind' => 'ideas', 'user_id' => (int) $user['id'],
        'reference_type' => 'content_plans', 'reference_id' => (int) ($plan['id'] ?? 0),
        'options' => ['ideas_count' => (int) ($plan['ideas_count'] ?? 0), 'credits' => $cost ?? 0],
    ]);
    $ai = ai_generate($prompt);
}

if (!$ai['ok']) {
    credits_add((int) $user['id'], $cost, 'استرداد: فشل توليد أفكار الخطة #' . $planId, null, 'refund', $planId);
    json_response(['ok' => false, 'error' => $ai['error']]);
}

$ideas = parse_plan_ideas($ai['response']);
if (count($ideas) < 3) {
    credits_add((int) $user['id'], $cost, 'استرداد: أفكار غير صالحة للخطة #' . $planId, null, 'refund', $planId);
    json_response(['ok' => false, 'error' => 'الرد غير صالح — جرب تاني']);
}

// امسح الأفكار المقترحة القديمة (اللي متعملها اختيار) واحتفظ بالمختار/المنتَج
db_run('DELETE FROM plan_ideas WHERE plan_id = ? AND status = "suggested"', [$planId]);

$order = (int) (db_one('SELECT COALESCE(MAX(sort_order),0) AS m FROM plan_ideas WHERE plan_id = ?', [$planId])['m'] ?? 0);
foreach ($ideas as $i) {
    $order++;
    db_insert(
        'INSERT INTO plan_ideas (plan_id, user_id, title, angle, description, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
        [$planId, $user['id'], $i['title'], $i['angle'], $i['description'], $order]
    );
}

db_run('UPDATE content_plans SET status = "ideas_ready", credits_used = credits_used + ? WHERE id = ?', [$cost, $planId]);
ai_log_usage((int) $user['id'], 'generate', $ai['model'] ?? 'router', $ai['usage'] ?? [], $planId);

json_response(['ok' => true, 'count' => count($ideas)]);
