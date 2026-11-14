<?php
/**
 * Spread AI — «اليوم» في لوحة الإدارة (خريطة التطوير · المرحلة 5)
 * طابور واحد بالحاجات اللي مستنية حد من الفريق — كل مصدر استعلام مجمّع واحد (مفيش N+1)،
 * وكل مصدر بيظهر للأدوار اللي ليها صلاحية صفحته بس.
 */
require_once __DIR__ . '/managed-accounts.php';
require_once __DIR__ . '/messaging.php';

const TODAY_ROWS = 6;

/**
 * @return array<int, array{key:string, title:string, count:int, url:string, tone:string, rows:array}>
 *   rows: [{label, sub, url, inbound_id?}] — أول TODAY_ROWS بس
 */
function admin_today_queue(): array
{
    $me = function_exists('current_admin') ? current_admin() : null;
    $senior = in_array(($me['role'] ?? 'super'), ['manager', 'super'], true);
    $out = [];
    $add = function (string $key, string $title, string $perm, string $url, string $tone, callable $load) use (&$out) {
        if ($perm !== '' && !admin_can($perm)) return;
        try {
            [$count, $rows] = $load();
        } catch (\Throwable $e) {
            error_log('[today] ' . $key . ': ' . $e->getMessage());
            return;
        }
        if ((int) $count > 0) $out[] = ['key' => $key, 'title' => $title, 'count' => (int) $count, 'url' => $url, 'tone' => $tone, 'rows' => $rows];
    };

    // ① رسائل العملاء اللي محدش رد عليها (المساعد: حساباته بس)
    $add('inbox', 'رسائل مستنية رد', 'view_users', 'admin/inbox.php', 'bad', function () use ($me, $senior) {
        if (!msg_ready()) return [0, []];
        $rows = msg_unhandled($senior ? null : (int) ($me['id'] ?? 0), 200);
        return [count($rows), array_map(fn($r) => [
            'label' => $r['user_id'] ? (string) $r['user_name'] : 'رقم مش مربوط · ' . $r['wa_phone'],
            'sub' => mb_substr(trim((string) $r['body']), 0, 90),
            'url' => $r['managed'] ? 'admin/workspace.php?id=' . (int) $r['user_id'] . '&tab=messages' : ($r['user_id'] ? 'admin/user-view.php?id=' . (int) $r['user_id'] : 'admin/inbox.php'),
            'inbound_id' => (int) $r['id'],
        ], array_slice($rows, 0, TODAY_ROWS))];
    });

    // ② دفعات مستنية مراجعة
    $add('payments', 'دفعات مستنية مراجعة', 'view_users', 'admin/payments.php', 'warn', function () {
        $n = (int) (db_one('SELECT COUNT(*) n FROM payment_requests WHERE status IN ("pending","under_review")')['n'] ?? 0);
        $rows = $n ? db_all('SELECT r.id, r.plan_name, r.final_amount, r.currency, r.created_at, u.name FROM payment_requests r LEFT JOIN users u ON u.id = r.user_id
                             WHERE r.status IN ("pending","under_review") ORDER BY r.created_at LIMIT ' . TODAY_ROWS) : [];
        return [$n, array_map(fn($r) => ['label' => (string) ($r['name'] ?? '—') . ' · ' . (string) $r['plan_name'],
            'sub' => rtrim(rtrim(number_format((float) $r['final_amount'], 2, '.', ','), '0'), '.') . ' ' . $r['currency'] . ' · ' . time_ago((string) $r['created_at']),
            'url' => 'admin/payment-view.php?id=' . (int) $r['id']], $rows)];
    });

    // ③ الحسابات المُدارة: العميل طلب تعديل (المساعد: حساباته بس)
    $add('changes', 'العميل طلب تعديل', 'view_users', 'admin/my-accounts.php', 'warn', function () use ($me, $senior) {
        if (!ma_ready()) return [0, []];
        $w = $senior ? '' : ' AND m.manager_admin_id = ' . (int) ($me['id'] ?? 0);
        $n = (int) (db_one('SELECT COUNT(*) n FROM contents c JOIN managed_accounts m ON m.user_id = c.user_id WHERE c.approval_status = "changes"' . $w)['n'] ?? 0);
        $rows = $n ? db_all('SELECT c.id, c.user_id, c.approval_note, u.name FROM contents c JOIN managed_accounts m ON m.user_id = c.user_id JOIN users u ON u.id = c.user_id
                             WHERE c.approval_status = "changes"' . $w . ' ORDER BY c.approval_at DESC LIMIT ' . TODAY_ROWS) : [];
        return [$n, array_map(fn($r) => ['label' => (string) $r['name'] . ' · منشور #' . (int) $r['id'], 'sub' => mb_substr((string) ($r['approval_note'] ?? ''), 0, 90),
            'url' => 'admin/workspace.php?id=' . (int) $r['user_id']], $rows)];
    });

    // ④ فشل نشر (آخر 7 أيام)
    $add('publish', 'منشورات فشل نشرها', 'view_content', 'admin/content-history.php', 'bad', function () {
        $n = (int) (db_one('SELECT COUNT(*) n FROM contents WHERE publish_status = "failed" AND updated_at >= NOW() - INTERVAL 7 DAY')['n'] ?? 0);
        $rows = $n ? db_all('SELECT c.id, c.publish_error, c.user_id, u.name FROM contents c LEFT JOIN users u ON u.id = c.user_id
                             WHERE c.publish_status = "failed" AND c.updated_at >= NOW() - INTERVAL 7 DAY ORDER BY c.updated_at DESC LIMIT ' . TODAY_ROWS) : [];
        $ce = 0;
        try { $ce = (int) (db_one('SELECT COUNT(*) n FROM ce_publish_queue WHERE status = "failed" AND updated_at >= NOW() - INTERVAL 7 DAY')['n'] ?? 0); } catch (\Throwable $e) {}
        return [$n + $ce, array_map(fn($r) => ['label' => (string) ($r['name'] ?? '—') . ' · منشور #' . (int) $r['id'], 'sub' => mb_substr((string) ($r['publish_error'] ?? ''), 0, 90),
            'url' => 'admin/content-view.php?id=' . (int) $r['id']], $rows)];
    });

    // ⑤ طلبات فيديو مستنية تنفيذ
    $add('video', 'طلبات فيديو', 'view_content', 'admin/video-requests.php', 'warn', function () {
        $n = (int) (db_one('SELECT COUNT(*) n FROM contents WHERE format = "video" AND video_status IN ("sent","in_production")')['n'] ?? 0);
        return [$n, []];
    });

    // ⑥ حسابات جديدة مستنية موافقة
    $add('approvals', 'حسابات جديدة مستنية موافقة', 'approve_users', 'admin/approvals.php', 'warn', function () {
        $n = (int) (db_one('SELECT COUNT(*) n FROM users WHERE approval_status = "pending"')['n'] ?? 0);
        $rows = $n ? db_all('SELECT id, name, email, created_at FROM users WHERE approval_status = "pending" ORDER BY created_at LIMIT ' . TODAY_ROWS) : [];
        return [$n, array_map(fn($r) => ['label' => (string) $r['name'], 'sub' => (string) $r['email'] . ' · ' . time_ago((string) $r['created_at']), 'url' => 'admin/approvals.php'], $rows)];
    });

    // ⑦ بلاغات العملاء الجديدة
    $add('issues', 'بلاغات جديدة من العملاء', 'view_users', 'admin/ai-issues.php', 'warn', function () {
        $n = (int) (db_one('SELECT COUNT(*) n FROM user_issues WHERE status = "new"')['n'] ?? 0);
        return [$n, []];
    });

    // ⑧ تنبيهات حرجة (الـ AI والنظام)
    $add('alerts', 'تنبيهات حرجة', 'view_costs', 'admin/alerts.php', 'bad', function () {
        $n = (int) (db_one('SELECT COUNT(*) n FROM admin_alerts WHERE read_at IS NULL AND level = "critical"')['n'] ?? 0);
        $rows = $n ? db_all('SELECT title, link, last_at FROM admin_alerts WHERE read_at IS NULL AND level = "critical" ORDER BY last_at DESC LIMIT ' . TODAY_ROWS) : [];
        return [$n, array_map(fn($r) => ['label' => (string) $r['title'], 'sub' => time_ago((string) $r['last_at']),
            'url' => preg_match('#^admin/[a-z0-9_\-]+\.php([?\#][^\s]*)?$#i', (string) $r['link']) ? (string) $r['link'] : 'admin/alerts.php'], $rows)];
    });

    // الأهم الأول: الأحمر قبل الأصفر، وجوه كل لون بالترتيب اللي فوق
    $rank = ['bad' => 0, 'warn' => 1];
    usort($out, fn($a, $b) => ($rank[$a['tone']] ?? 2) <=> ($rank[$b['tone']] ?? 2));
    return $out;
}

/** صحة النظام (للـ super): آخر تشغيل لكل كرون · الطوابير · الترحيلات المستنية */
function admin_system_health(): array
{
    $now = time();
    $crons = [];
    foreach (['cron_publish_last_run' => ['النشر المجدول', 'cron/publish-scheduled.php', 15], 'ce_worker_last_run' => ['عامل المحرك (الحملات والتصميم)', 'cron/ce-worker.php', 10],
              'cron_health_last_run' => ['فحص مزودين الـ AI', 'cron/ai-health.php', 90], 'daily_report_last_run' => ['التقرير اليومي', 'cron/daily_notifications.php', 60 * 26],
              'security_last_run' => ['فحص الأمان', '—', 60 * 26]] as $key => [$label, $file, $maxMin]) {
        $at = (string) get_setting($key, '');
        $ts = $at !== '' ? (strtotime($at) ?: 0) : 0;
        $crons[] = ['label' => $label, 'file' => $file, 'at' => $at, 'ok' => $ts > 0 && $now - $ts <= $maxMin * 60, 'never' => $ts === 0, 'max_min' => $maxMin];
    }
    $q = function (string $sql): ?int { try { return (int) (db_one($sql)['n'] ?? 0); } catch (\Throwable $e) { return null; } };
    $queues = [
        ['شغل المحرك المستني', $q('SELECT COUNT(*) n FROM ce_jobs WHERE status IN ("queued","running")'), 'admin/ce-settings.php'],
        ['شغل المحرك اللي فشل (24 ساعة)', $q('SELECT COUNT(*) n FROM ce_jobs WHERE status = "failed" AND updated_at >= NOW() - INTERVAL 24 HOUR'), 'admin/ce-settings.php'],
        ['منشورات معادها عدّى ولسه ماتنشرتش', $q('SELECT COUNT(*) n FROM contents WHERE publish_status = "pending" AND scheduled_at < NOW() - INTERVAL 30 MINUTE'), 'admin/content-history.php'],
        ['رسائل واتساب في الطابور', $q('SELECT COUNT(*) n FROM messages WHERE wa_status IN ("pending","processing")'), 'admin/whatsapp.php'],
        ['رسائل واتساب فشلت (24 ساعة)', $q('SELECT COUNT(*) n FROM messages WHERE wa_status = "failed" AND updated_at >= NOW() - INTERVAL 24 HOUR'), 'admin/whatsapp.php'],
        ['تنبيهات حرجة مفتوحة', $q('SELECT COUNT(*) n FROM admin_alerts WHERE read_at IS NULL AND level = "critical"'), 'admin/alerts.php'],
    ];
    $pending = [];
    try {
        if (!function_exists('migrations_status')) require_once __DIR__ . '/migrations.php';
        foreach (migrations_status() as $m) if (in_array($m['state'], ['pending', 'incomplete'], true)) $pending[] = $m['file'];
    } catch (\Throwable $e) {}
    return ['crons' => $crons, 'queues' => $queues, 'migrations' => $pending];
}
