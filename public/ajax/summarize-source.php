<?php
/**
 * Spread AI v2 — تلخيص مصدر بالـ AI (Phase 2)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/ai.php';
require_once __DIR__ . '/../../includes/source-extract.php';

// الـ Router لو ملفاته موجودة (اختياري — بيشتغل من غيره برضه)
if (is_file(__DIR__ . '/../../includes/smart-ai.php')) {
    require_once __DIR__ . '/../../includes/smart-ai.php';
}

require_login();
require_csrf();

$user = current_user();

// حد المحاولات: 6 كل 10 دقيقة لكل مستخدم
if (!rate_limit('summarize', 'u' . ($user['id'] ?? 0), 6, 600)) {
    json_response(['ok' => false, 'error' => 'وصلت للحد — استنى عشر دقايق']);
}
$sourceId = (int) ($_POST['source_id'] ?? 0);

$src = db_one('SELECT * FROM brand_sources WHERE id = ? AND user_id = ?', [$sourceId, $user['id']]);
if (!$src) {
    json_response(['ok' => false, 'error' => 'المصدر غير موجود']);
}
if (empty($src['raw_text'])) {
    json_response(['ok' => false, 'error' => 'لا يوجد نص مستخرج — أعد الاستخراج أولًا']);
}

// خصم الكريدت
$cost = (int) get_setting('source_summary_cost', 1);
if ($cost > 0) {
    if (credits_balance((int) $user['id']) < $cost) {
        json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
    }
    if (!credits_consume((int) $user['id'], $cost, 'تلخيص مستند هوية بالذكاء الاصطناعي', 'brand_sources', $sourceId)) {
        json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
    }
}

$r = source_summarize($sourceId, (int) $user['id']);

if (!$r['ok']) {
    // رد الكريدت لو فشل التلخيص
    if ($cost > 0) {
        credits_add((int) $user['id'], $cost, 'استرجاع: فشل تلخيص المستند', null, 'refund', $sourceId);
    }
    json_response(['ok' => false, 'error' => $r['error'] ?? 'تعذر التلخيص حاليًا']);
}

json_response(['ok' => true, 'summary' => mb_substr((string) $r['summary'], 0, 500)]);
