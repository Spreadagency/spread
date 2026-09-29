<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/social.php';

require_login();
require_csrf();

$user = current_user();

// حد المحاولات: 30 كل 5 دقيقة لكل مستخدم
if (!rate_limit('schedule', 'u' . ($user['id'] ?? 0), 30, 300)) {
    json_response(['ok' => false, 'error' => 'جدولة كتير بسرعة — استنى دقيقة']);
}
$contentId = (int) ($_POST['content_id'] ?? 0);
$scheduledAt = trim($_POST['scheduled_at'] ?? '');
$platform = in_array($_POST['publish_platform'] ?? '', ['facebook', 'instagram', 'both'])
    ? $_POST['publish_platform'] : 'facebook';

$content = db_one('SELECT * FROM contents WHERE id = ? AND user_id = ?', [$contentId, $user['id']]);
if (!$content) {
    json_response(['ok' => false, 'error' => 'المحتوى غير موجود']);
}

// Cancel scheduling
if (!empty($_POST['cancel'])) {
    db_run('UPDATE contents SET scheduled_at = NULL, publish_platform = NULL, publish_error = NULL, connection_id = NULL, publish_status = "draft", attempts = 0, next_attempt_at = NULL, lock_token = NULL WHERE id = ?', [$contentId]);
    if (function_exists('plan_event_release')) plan_event_release((int) $user['id'], 'publishes', $contentId); // 8-ب: الحصة ترجع
    json_response(['ok' => true, 'msg' => 'تم إلغاء الجدولة']);
}

// ─── اتصال شخصي (وحدة النشر الجديدة) ───
$connectionId = (int) ($_POST['connection_id'] ?? 0);
$conn = null;
if ($connectionId > 0) {
    if (!feature_allows((int) $user['id'])) {
        json_response(['ok' => false, 'error' => 'ميزة النشر التلقائي غير مفعّلة لحسابك']);
    }
    $conn = connection_for_user($connectionId, (int) $user['id']);
    if (!$conn || $conn['status'] !== 'active') {
        json_response(['ok' => false, 'error' => 'الصفحة المختارة غير متاحة — أعد ربطها من «حساباتي المربوطة»']);
    }
}

// 8-ب: حصة النشر — الجدولة على صفحة مربوطة بتتحسب (مرة واحدة لكل منشور)
if ($conn && function_exists('plan_gate')) {
    $__pubCounted = plan_counted((int) $user['id'], 'publishes', $contentId);
    if (!$__pubCounted && ($__g = plan_gate((int) $user['id'], 'publishes'))) {
        json_response(['ok' => false, 'error' => $__g, 'code' => 'quota']);
    }
}

// Validate datetime (must be in the future)
$ts = strtotime($scheduledAt);
if (!$ts || $ts < time() + 60) {
    json_response(['ok' => false, 'error' => 'اختر وقت في المستقبل']);
}

if ($platform !== 'facebook') {
    $hasDesign = db_one('SELECT id FROM content_designs WHERE content_id = ? LIMIT 1', [$contentId]);
    if (!$hasDesign) {
        json_response(['ok' => false, 'error' => 'النشر على إنستجرام يتطلب توليد تصميم أولًا']);
    }
    if ($conn && empty($conn['ig_user_id'])) {
        json_response(['ok' => false, 'error' => 'الصفحة دي مش مربوط بيها حساب انستجرام بيزنس']);
    }
}

db_run(
    'UPDATE contents SET scheduled_at = ?, publish_platform = ?, connection_id = ?, publish_status = ?, attempts = 0, next_attempt_at = NULL, lock_token = NULL, publish_error = NULL, published_at = NULL WHERE id = ?',
    [date('Y-m-d H:i:s', $ts), $platform, $conn ? $conn['id'] : null, $conn ? 'pending' : 'draft', $contentId]
);
if ($conn && function_exists('plan_event')) plan_event((int) $user['id'], 'publishes', $contentId);

json_response(['ok' => true, 'msg' => $conn
    ? 'تمت الجدولة ✓ — هينتشر تلقائيًا على «' . $conn['page_name'] . '» في معاده'
    : 'تمت الجدولة بنجاح ✓']);
