<?php
/**
 * Spread AI v2 — اختيار/إلغاء تصميم من الستوديو (Phase 4)
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php'; // get_setting

require_login();
require_csrf();

$user = current_user();

// حد المحاولات: 40 كل 5 دقيقة لكل مستخدم
if (!rate_limit('studio_sel', 'u' . ($user['id'] ?? 0), 40, 300)) {
    json_response(['ok' => false, 'error' => 'استنى شوية وحاول تاني']);
}
$mediaId = (int) ($_POST['media_id'] ?? 0);

$media = db_one('SELECT id FROM media_library WHERE id = ? AND is_active = 1', [$mediaId]);
if (!$media) {
    json_response(['ok' => false, 'error' => 'التصميم غير موجود']);
}

$exists = db_one('SELECT id FROM user_media_selections WHERE user_id = ? AND media_id = ?', [$user['id'], $mediaId]);

if ($exists) {
    db_run('DELETE FROM user_media_selections WHERE id = ?', [$exists['id']]);
    $selected = false;
} else {
    $max = (int) get_setting('studio_max_selections', 10);
    $count = db_count('SELECT COUNT(*) FROM user_media_selections WHERE user_id = ?', [$user['id']]);
    if ($count >= $max) {
        json_response(['ok' => false, 'error' => "الحد الأقصى {$max} تصميمات — شيل واحد الأول"]);
    }
    db_insert('INSERT INTO user_media_selections (user_id, media_id) VALUES (?, ?)', [$user['id'], $mediaId]);
    $selected = true;
}

$count = db_count('SELECT COUNT(*) FROM user_media_selections WHERE user_id = ?', [$user['id']]);
json_response(['ok' => true, 'selected' => $selected, 'count' => $count]);
