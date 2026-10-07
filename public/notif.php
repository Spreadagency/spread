<?php
/** فتح إشعار: بيتعلّم مقروء وبيحوّل على الصفحة بتاعته */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$uid = (int) current_user()['id'];
$n = null;
try {
    $n = db_one('SELECT id, url FROM user_notifications WHERE id = ? AND user_id = ?', [(int) ($_GET['id'] ?? 0), $uid]);
    if ($n) db_run('UPDATE user_notifications SET read_at = NOW() WHERE id = ? AND read_at IS NULL', [$n['id']]);
} catch (\Throwable $e) {}
$to = (string) ($n['url'] ?? '');
// صفحة داخلية بس (مفيش تحويل لمواقع بره)
redirect(preg_match('#^[a-z0-9_\-/]+\.php([?\#][^\s]*)?$#i', $to) ? $to : 'dashboard.php');
