<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/functions.php';

require_login();
require_csrf();

$user = current_user();

// حد المحاولات: 40 كل 5 دقيقة لكل مستخدم
if (!rate_limit('save_content', 'u' . ($user['id'] ?? 0), 40, 300)) {
    json_response(['ok' => false, 'error' => 'حفظ كتير بسرعة — استنى دقيقة']);
}
$contentId = (int) ($_POST['content_id'] ?? 0);
$content = get_user_content($contentId, (int) $user['id']);

if (!$content) {
    json_response(['ok' => false, 'error' => 'المحتوى غير موجود']);
}

$newText = trim($_POST['generated_text'] ?? '');
$newHashtags = trim($_POST['hashtags'] ?? '');
$newCta = trim($_POST['cta'] ?? '');

if ($newText === '') {
    json_response(['ok' => false, 'error' => 'النص فارغ']);
}

// Save old version
save_content_version(
    $contentId,
    $content['generated_text'],
    $content['hashtags'],
    $content['cta'],
    'edited',
    'إصدار قبل التعديل'
);

db_run(
    'UPDATE contents SET generated_text=?, hashtags=?, cta=?, status=?, updated_at=NOW() WHERE id = ?',
    [$newText, $newHashtags, $newCta, 'edited', $contentId]
);

json_response(['ok' => true]);
