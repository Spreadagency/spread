<?php
/**
 * Spread AI — كرون يومي: التعلّم من تعديلات العميل (Brand Kit)
 *   ① نفس نوع التعديل اتكرر 3 مرات أو أكتر في آخر 10 بوستات ← اقتراح بالمصري (مفيش تعديل من غير موافقة العميل)
 *   ② كل 90 يوم: «في خدمات أو عروض جديدة؟» (نظام الرسايل/الواتساب)
 *
 * التشغيل (مرة في اليوم):
 *   /usr/local/bin/php /home/USER/public_html/cron/brand_learning.php cron_secret=YOUR_SECRET
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/brand-kit.php';
require_once __DIR__ . '/../includes/Notifier.php';

$provided = '';
if (PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $arg) {
        if (strpos($arg, 'cron_secret=') === 0) $provided = substr($arg, 12);
    }
} else {
    $provided = $_GET['cron_secret'] ?? '';
}
$secret = get_setting('cron_secret', '');
if ($secret === '' || !hash_equals($secret, (string) $provided)) {
    http_response_code(403);
    exit("forbidden\n");
}
@set_time_limit(300);
bk_ensure_schema();
if (!bk_enabled()) exit("brand kit disabled\n");
if (function_exists('wa_seed_templates')) wa_seed_templates();

// ① الاقتراحات — العملاء اللي عندهم تعديلات جديدة من آخر يومين
$users = array_column(db_all('SELECT DISTINCT user_id FROM brand_feedback WHERE created_at >= NOW() - INTERVAL 2 DAY'), 'user_id');
$sug = 0;
foreach ($users as $uid) {
    try {
        $n = bk_learn_for_user((int) $uid);
        if ($n > 0) {
            $sug += $n;
            $last = db_one('SELECT message FROM brand_suggestions WHERE user_id = ? AND status = "pending" ORDER BY id DESC LIMIT 1', [(int) $uid]);
            Notifier::notify((int) $uid, 'brand_suggestion', ['message' => (string) ($last['message'] ?? '')], ['url' => 'brand-kit.php', 'queue_only' => true]);
        }
    } catch (\Throwable $e) {
        error_log('[brand-learning] ' . $uid . ': ' . $e->getMessage());
    }
}

// ② تذكير كل 90 يوم (مرة واحدة لكل فترة — بنعتمد على تاريخ آخر تذكير في الإعدادات الخاصة بالعميل)
$rem = 0;
$due = db_all("SELECT k.user_id FROM brand_kits k JOIN users u ON u.id = k.user_id
               WHERE u.status = 'active' AND k.updated_at < NOW() - INTERVAL 90 DAY
                 AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.user_id = k.user_id AND m.event_key = 'brand_refresh' AND m.created_at > NOW() - INTERVAL 90 DAY)
               LIMIT 500");
foreach ($due as $r) {
    try {
        Notifier::notify((int) $r['user_id'], 'brand_refresh', [], ['url' => 'brand-kit.php', 'queue_only' => true]);
        $rem++;
    } catch (\Throwable $e) {
        error_log('[brand-learning] refresh ' . $r['user_id'] . ': ' . $e->getMessage());
    }
}


echo 'checked=' . count($users) . " suggestions={$sug} reminders={$rem}\n";
