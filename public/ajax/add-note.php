<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/functions.php';

require_login();
require_csrf();

$user = current_user();

// حد المحاولات: 30 كل 5 دقيقة لكل مستخدم
if (!rate_limit('add_note', 'u' . ($user['id'] ?? 0), 30, 300)) {
    json_response(['ok' => false, 'error' => 'استنى شوية وحاول تاني']);
}
$contentId = (int) ($_POST['content_id'] ?? 0);
$note = trim($_POST['note'] ?? '');

$content = get_user_content($contentId, (int) $user['id']);
if (!$content) {
    json_response(['ok' => false, 'error' => 'المحتوى غير موجود']);
}

if ($note === '') {
    json_response(['ok' => false, 'error' => 'الملاحظة فاضية']);
}

$id = db_insert_row('content_notes', [
    'content_id' => $contentId,
    'user_id'    => $user['id'],
    'note'       => $note,
]);

json_response(['ok' => true, 'id' => $id]);
