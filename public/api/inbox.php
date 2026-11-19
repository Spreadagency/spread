<?php
/**
 * Spread AI — صندوق الرسايل (الجرس + صفحة الإشعارات)
 *
 *   GET  action=unread                 {count, latest:[…5], max_id}   ← polling كل 30 ثانية
 *   GET  action=list   page=1          {items:[…20], page, pages, unread}
 *   POST action=read   {ids:[…]} | {all:true}
 *
 * المصدر: user_notifications (نفس الجرس) + تفاصيل الرسالة من messages (صورة · رابط · من الإدارة)
 * المستخدم بيشوف ويعدّل رسايله هو بس
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/Notifier.php';

$user = api_boot(true);
$uid = (int) $user['id'];
$action = api_action() ?: 'unread';
wa_ensure_schema();

function inbox_row(array $r): array
{
    $admin = in_array((string) $r['kind'], ['admin_direct', 'admin_broadcast'], true);
    $link = (string) ($r['m_link'] ?? '');
    $url = (string) ($r['url'] ?? '');
    return [
        'id' => (int) $r['id'], 'title' => (string) $r['title'], 'body' => (string) ($r['body'] ?? ''),
        'kind' => (string) $r['kind'], 'tone' => (string) $r['tone'], 'admin' => $admin,
        'read' => $r['read_at'] !== null, 'ago' => function_exists('time_ago') ? time_ago((string) $r['created_at']) : (string) $r['created_at'],
        'at' => (string) $r['created_at'],
        'open' => url('notif.php?id=' . (int) $r['id']),
        'internal' => ($url !== '' && $url !== 'notifications.php') ? url($url) : null,
        'link' => ($link !== '' && preg_match('#^https://#i', $link)) ? $link : null,
        'media' => !empty($r['m_media']) ? wa_abs_link((string) $r['m_media']) : null,
        'media_type' => $r['m_media_type'] ?? null,
    ];
}

$select = 'SELECT un.*, m.link m_link, m.media_url m_media, m.media_type m_media_type
           FROM user_notifications un LEFT JOIN messages m ON m.notif_id = un.id';

switch ($action) {
    case 'unread':
        $count = (int) (db_one('SELECT COUNT(*) n FROM user_notifications WHERE user_id = ? AND read_at IS NULL', [$uid])['n'] ?? 0);
        $latest = db_all("$select WHERE un.user_id = ? ORDER BY un.id DESC LIMIT 5", [$uid]);
        api_ok(['count' => $count, 'latest' => array_map('inbox_row', $latest), 'max_id' => (int) ($latest[0]['id'] ?? 0)]);

    case 'list':
        $per = 20;
        $total = (int) (db_one('SELECT COUNT(*) n FROM user_notifications WHERE user_id = ?', [$uid])['n'] ?? 0);
        $pages = max(1, (int) ceil($total / $per));
        $page = api_int('page', 1, 1, $pages);
        $rows = db_all("$select WHERE un.user_id = ? ORDER BY un.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), [$uid]);
        $unread = (int) (db_one('SELECT COUNT(*) n FROM user_notifications WHERE user_id = ? AND read_at IS NULL', [$uid])['n'] ?? 0);
        api_ok(['items' => array_map('inbox_row', $rows), 'page' => $page, 'pages' => $pages, 'unread' => $unread]);

    case 'read':
        if (!empty(api_param('all'))) {
            db_run('UPDATE user_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$uid]);
        } else {
            $ids = array_slice(array_values(array_filter(array_map('intval', (array) api_param('ids', [])))), 0, 100);
            if ($ids) {
                db_run('UPDATE user_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL AND id IN (' . implode(',', $ids) . ')', [$uid]);
            }
        }
        // نفس الحالة في سجل الرسايل (نسبة القراءة في الحملات)
        db_run('UPDATE messages m JOIN user_notifications un ON un.id = m.notif_id SET m.read_at = un.read_at
                WHERE m.user_id = ? AND m.read_at IS NULL AND un.read_at IS NOT NULL', [$uid]);
        $count = (int) (db_one('SELECT COUNT(*) n FROM user_notifications WHERE user_id = ? AND read_at IS NULL', [$uid])['n'] ?? 0);
        api_ok(['count' => $count]);

    default:
        api_fail('طلب غير معروف', 'invalid', 400);
}
