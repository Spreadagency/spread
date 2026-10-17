<?php
/**
 * Spread AI — إشعارات يومية (واتساب + المنصة) — يشتغل الساعة 10 الصبح
 *   • الاشتراك هيخلص بعد 3 أيام  → subscription_expiring
 *   • مدخلش من 7 أيام            → inactive_reminder (مرة كل 14 يوم بالكتير)
 *   • تنظيف أكواد التحقق القديمة
 *
 * cPanel Cron (يوميًا 10:00):
 *   /usr/local/bin/php /home/USER/public_html/cron/daily_notifications.php cron_secret=YOUR_SECRET
 * أو:
 *   https://ai.spreadagency.net/cron/daily_notifications.php?cron_secret=YOUR_SECRET
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Notifier.php';

$provided = '';
if (php_sapi_name() === 'cli') {
    foreach ($argv ?? [] as $arg) {
        if (strpos($arg, 'cron_secret=') === 0) $provided = substr($arg, 12);
    }
} else {
    $provided = $_GET['cron_secret'] ?? '';
}
$secret = get_setting('cron_secret', '');
if (!$secret || !hash_equals($secret, $provided)) {
    http_response_code(403);
    die('Forbidden');
}
@set_time_limit(300);
if (php_sapi_name() !== 'cli') header('Content-Type: text/plain; charset=utf-8');
wa_ensure_schema();

$active = "u.status = 'active' AND u.approval_status = 'approved'";
try {
    if (in_array('deletion_requested_at', array_column(db_all('SHOW COLUMNS FROM users'), 'Field'), true)) $active .= ' AND u.deletion_requested_at IS NULL';
} catch (\Throwable $e) {}

// ① الاشتراك هيخلص بعد 3 أيام (الباقات المدفوعة)
$n1 = 0;
try {
    $rows = db_all("SELECT up.user_id, up.name, up.ends_at FROM user_plans up JOIN users u ON u.id = up.user_id
                    WHERE $active AND up.status = 'active' AND up.source <> 'trial'
                      AND DATE(up.ends_at) = DATE(NOW() + INTERVAL 3 DAY)
                      AND NOT EXISTS (SELECT 1 FROM user_plans nx WHERE nx.user_id = up.user_id AND nx.status = 'active' AND nx.ends_at > up.ends_at)
                      AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.user_id = up.user_id AND m.event_key = 'subscription_expiring' AND m.created_at >= NOW() - INTERVAL 5 DAY)
                    LIMIT 2000");
    foreach ($rows as $r) {
        if (Notifier::notify((int) $r['user_id'], 'subscription_expiring',
            ['date' => fmt_date((string) $r['ends_at']), 'plan' => (string) $r['name']], ['url' => 'packages.php', 'tone' => 'warn', 'queue_only' => true])) $n1++;
    }
} catch (\Throwable $e) {
    echo 'subscription_expiring: ' . $e->getMessage() . "\n";
}
echo "subscription_expiring: $n1\n";

// ② مدخلش من 7 أيام — مرة كل 14 يوم بالكتير
$n2 = 0;
try {
    $rows = db_all("SELECT u.id, COALESCE(u.last_active_at, u.created_at) seen FROM users u
                    WHERE $active AND COALESCE(u.last_active_at, u.created_at) < NOW() - INTERVAL 7 DAY
                      AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.user_id = u.id AND m.event_key = 'inactive_reminder' AND m.created_at >= NOW() - INTERVAL 14 DAY)
                    ORDER BY u.id ASC LIMIT 1000");
    foreach ($rows as $r) {
        $days = (int) floor((time() - strtotime((string) $r['seen'])) / 86400);
        if (Notifier::notify((int) $r['id'], 'inactive_reminder', ['days' => $days], ['url' => 'dashboard.php', 'queue_only' => true])) $n2++;
    }
} catch (\Throwable $e) {
    echo 'inactive_reminder: ' . $e->getMessage() . "\n";
}
echo "inactive_reminder: $n2\n";

// ③ تنظيف
try {
    db_run('DELETE FROM wa_otps WHERE created_at < NOW() - INTERVAL 7 DAY');
    wa_release_scheduled();
} catch (\Throwable $e) {}
echo "done\n";
