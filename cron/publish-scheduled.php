<?php
/**
 * Publish scheduled content — feature 23
 *
 * cPanel Cron (every 5 minutes):
 *   /usr/local/bin/php /home/USER/public_html/cron/publish-scheduled.php cron_secret=YOUR_SECRET
 * Or via URL:
 *   https://ai.spreadagency.net/cron/publish-scheduled.php?cron_secret=YOUR_SECRET
 * Set cron_secret in admin > integrations first.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/integrations.php';

// Auth: secret required (CLI arg or GET param)
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

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../includes/social.php';

// ─── قفل ملف: يمنع تشغيل نسختين معًا ───
$lockFile = fopen(sys_get_temp_dir() . '/spread_publish.lock', 'c');
if (!$lockFile || !flock($lockFile, LOCK_EX | LOCK_NB)) {
    die("Another worker is running\n");
}

// 8: آخر تشغيل (بيظهر في «صحة النظام» في لوحة التشغيل)
try { set_setting('cron_publish_last_run', date('Y-m-d H:i:s')); } catch (\Throwable $e) {}

// ─── ⑥-أ: حسابات طلبت الحذف وعدّت مهلة الـ 14 يوم ← مسح نهائي (مرة كل ساعة بالكتير) ───
try {
    require_once __DIR__ . '/../includes/account.php';
    if (time() - (int) get_setting('account_purge_last_run', '0') >= 3600) {
        set_setting('account_purge_last_run', (string) time());
        $__purged = account_purge_due(5);
        if ($__purged) echo "Deleted {$__purged} account(s) after the grace period\n";
    }
} catch (\Throwable $e) {
    echo 'account purge skipped: ' . $e->getMessage() . "\n";
}

// ─── صفوف عالقة في processing من أكثر من 10 دقائق → رجّعها pending ───
db_run(
    'UPDATE contents SET publish_status = "pending", lock_token = NULL
     WHERE publish_status = "processing" AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)'
);

/* ═══════════ المسار الجديد: اتصالات المستخدمين ═══════════ */
$uuid = bin2hex(random_bytes(18));

// حجز ذري للصفوف المستحقة
db_run(
    'UPDATE contents
     SET publish_status = "processing", lock_token = ?, attempts = attempts + 1
     WHERE publish_status = "pending"
       AND connection_id IS NOT NULL
       AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()
       AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
     ORDER BY scheduled_at ASC
     LIMIT 10',
    [$uuid]
);
$claimed = db_all('SELECT * FROM contents WHERE lock_token = ?', [$uuid]);
echo 'Claimed ' . count($claimed) . " connection-based post(s)\n";

foreach ($claimed as $content) {
    $cid = (int) $content['id'];

    // 1) صاحب البوست لسه مسموح له؟ (سحب الصلاحية = إلغاء مش نشر)
    if (!feature_allows((int) $content['user_id'])) {
        db_run('UPDATE contents SET publish_status = "cancelled", lock_token = NULL, publish_error = ? WHERE id = ?',
            ['أُلغي: صلاحية النشر مسحوبة من الإدارة', $cid]);
        if (function_exists('plan_event_release')) plan_event_release((int) $content['user_id'], 'publishes', (int) $cid); // 8-ب: ماتنشرش → الحصة ترجع
        echo "#$cid cancelled (feature revoked)\n";
        continue;
    }

    // 2) الاتصال نشط؟
    $conn = db_one('SELECT * FROM social_connections WHERE id = ?', [$content['connection_id']]);
    if (!$conn || $conn['status'] !== 'active') {
        db_run('UPDATE contents SET publish_status = "failed", lock_token = NULL, publish_error = ? WHERE id = ?',
            ['الصفحة المربوطة غير نشطة — أعد ربطها', $cid]);
        if (function_exists('plan_event_release')) plan_event_release((int) $content['user_id'], 'publishes', (int) $cid); // 8-ب: ماتنشرش → الحصة ترجع
        echo "#$cid failed (connection inactive)\n";
        continue;
    }

    // 3) انشر (فيسبوك و/أو انستجرام)
    $targets = $content['publish_platform'] === 'both'
        ? ['facebook', 'instagram']
        : [$content['publish_platform'] ?: 'facebook'];

    // لو جزء اتنشر/اتجدول خلاص على فيسبوك، منعيدش نشره
    $already = (string) ($content['publish_post_id'] ?? '');
    if ($already !== '') {
        $targets = array_values(array_filter($targets, static fn ($t) => strpos($already, $t . ':') === false));
        if (!$targets) {
            db_run('UPDATE contents SET publish_status = "published", published_at = COALESCE(published_at, NOW()), lock_token = NULL WHERE id = ?', [$cid]);
            echo "#$cid already handled\n";
            continue;
        }
    }
    $ok = true;
    $postIds = [];
    $lastErr = null;
    $classified = null;

    foreach ($targets as $tg) {
        $r = social_publish($content, $conn, $tg);
        if ($r['ok']) {
            $postIds[] = $tg . ':' . $r['post_id'];
        } else {
            $ok = false;
            $lastErr = '[' . $tg . '] ' . $r['error'];
            $classified = $r;
            break;
        }
    }

    if ($ok) {
        db_run(
            'UPDATE contents SET publish_status = "published", published_at = NOW(), publish_post_id = ?, publish_error = NULL, lock_token = NULL WHERE id = ?',
            [implode(' | ', $postIds), $cid]
        );
        echo "#$cid published (" . implode(', ', $postIds) . ")\n";
    } else {
        // توكن ميت → علّم الاتصال منتهي، وفشل نهائي
        if (!empty($classified['token_dead'])) {
            connection_mark((int) $conn['id'], 'expired', $lastErr);
        }
        $permanent = !empty($classified['permanent']) || (int) $content['attempts'] >= 3;
        if ($permanent) {
            db_run('UPDATE contents SET publish_status = "failed", lock_token = NULL, publish_error = ? WHERE id = ?',
                [mb_substr($lastErr, 0, 900), $cid]);
            if (function_exists('plan_event_release')) plan_event_release((int) $content['user_id'], 'publishes', (int) $cid); // 8-ب: ماتنشرش → الحصة ترجع
            echo "#$cid FAILED permanently: $lastErr\n";
        } else {
            $mins = $classified['retry_minutes'] ?? social_backoff_minutes((int) $content['attempts']);
            db_run('UPDATE contents SET publish_status = "pending", lock_token = NULL, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), publish_error = ? WHERE id = ?',
                [$mins, mb_substr($lastErr, 0, 900), $cid]);
            echo "#$cid retry in {$mins}m: $lastErr\n";
        }
    }
}

/* ═══════════ المسار القديم: توكن المنصة العام (fallback) ═══════════ */
// Pick due, unpublished, not-permanently-failed items (max 10 per run)
$due = db_all(
    'SELECT * FROM contents
     WHERE scheduled_at IS NOT NULL
       AND scheduled_at <= ?
       AND published_at IS NULL
       AND connection_id IS NULL
       AND publish_status IN ("draft", "pending")
     ORDER BY scheduled_at ASC
     LIMIT 10',
    [date('Y-m-d H:i:s')]
);

echo 'Found ' . count($due) . " due item(s)\n";

foreach ($due as $content) {
    $result = publish_content($content);

    if ($result['ok']) {
        db_run(
            'UPDATE contents SET published_at = ?, publish_post_id = ?, publish_error = ?, status = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $result['post_id'], $result['error'], 'published', $content['id']]
        );
        echo "#{$content['id']} published ({$result['post_id']})\n";
    } else {
        // Record error and unschedule to avoid retry loops; user can reschedule
        db_run(
            'UPDATE contents SET publish_error = ?, scheduled_at = NULL WHERE id = ?',
            [$result['error'], $content['id']]
        );
        if (function_exists('plan_event_release')) plan_event_release((int) $content['user_id'], 'publishes', (int) $content['id']);
        integrations_log('publish', "#{$content['id']} failed: " . $result['error']);
        echo "#{$content['id']} FAILED: {$result['error']}\n";
    }
}

echo "Done\n";
