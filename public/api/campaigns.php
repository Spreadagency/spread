<?php
/**
 * API الحملات
 *   GET  ?action=list[&status=active|completed|archived][&q=]
 *   GET  ?action=get&id=
 *   POST action=create   {title?, goal?, topic?}
 *   POST action=save     {id, rev, title?, goal?, basis?, topic?, ideas_count?, notes?}   ← الحفظ التلقائي
 *   POST action=stage    {id, stage}
 *   POST action=duplicate|archive|restore|complete|delete  {id}
 */
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/campaign-flow.php';   // الأساس الجديد + أرقام الحملة (⑥-ب)

$user = api_boot();
$uid = (int) $user['id'];
$action = api_action() ?: 'list';

if (get_setting('campaigns_enabled', '1') !== '1') {
    api_fail('الحملات مش متاحة حاليًا', 'disabled', 403);
}

switch ($action) {

    /* ── القائمة ── */
    case 'list':
        $status = in_array($_GET['status'] ?? '', ['active', 'completed', 'archived'], true) ? $_GET['status'] : 'active';
        $q = api_str('q', 100);
        $w = ['user_id = ?', 'status = ?'];
        $p = [$uid, $status];
        if ($q !== '') {
            $w[] = '(title LIKE ? OR topic LIKE ?)';
            $p[] = "%{$q}%";
            $p[] = "%{$q}%";
        }
        $rows = db_all('SELECT * FROM campaigns WHERE ' . implode(' AND ', $w) . ' ORDER BY updated_at DESC LIMIT 100', $p);

        $counts = ['active' => 0, 'completed' => 0, 'archived' => 0];
        foreach (db_all('SELECT status, COUNT(*) n FROM campaigns WHERE user_id = ? GROUP BY status', [$uid]) as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        api_ok([
            'campaigns' => array_map('campaign_to_api', $rows),
            'counts'    => $counts,
        ]);

    /* ── حملة واحدة ── */
    case 'get':
        $c = api_own('campaigns', api_int('id'), $uid);
        api_ok([
            'campaign' => campaign_to_api($c),
            'stages'   => campaign_stages(),
            'goals'    => campaign_goals(),
            'bases'    => campaign_bases(),
            'statuses' => array_map('content_status_meta', array_keys(content_statuses())),
        ]);

    /* ── إنشاء ── */
    case 'create':
        $open = (int) (db_one('SELECT COUNT(*) n FROM campaigns WHERE user_id = ? AND status = "active"', [$uid])['n'] ?? 0);
        if ($open >= 30) {
            api_fail('عندك 30 حملة نشطة — خلّص أو أرشف واحدة الأول', 'limit', 422);
        }
        $title = api_str('title', 150) ?: ('حملة ' . arabic_month_name((int) date('n')));
        $goal = array_key_exists(api_str('goal', 40), campaign_goals()) ? api_str('goal', 40) : null;

        $id = db_insert(
            'INSERT INTO campaigns (user_id, title, goal, topic, period_month) VALUES (?,?,?,?,?)',
            [$uid, $title, $goal, api_str('topic', 300) ?: null, date('Y-m')]
        );
        api_ok(['campaign' => campaign_to_api(db_one('SELECT * FROM campaigns WHERE id = ?', [$id]))], 201);

    /* ── الحفظ التلقائي (مع منع التعارض) ── */
    case 'save':
        $c = api_own('campaigns', api_int('id'), $uid);
        $rev = api_int('rev');
        if ($rev > 0 && $rev !== (int) $c['revision']) {
            // اتعدّلت من تبويب/جهاز تاني — نرجّع النسخة الحالية بدل ما نمسحها
            api_fail('الحملة اتعدّلت من مكان تاني', 'conflict', 409, ['campaign' => campaign_to_api($c, false)]);
        }

        $set = [];
        $vals = [];
        $in = api_input();
        $map = [
            'title'       => fn() => api_str('title', 150) ?: $c['title'],
            'goal'        => fn() => array_key_exists((string) $in['goal'], campaign_goals()) ? $in['goal'] : null,
            'basis'       => fn() => (array_key_exists((string) $in['basis'], campaign_flow_bases()) || array_key_exists((string) $in['basis'], campaign_bases())) ? $in['basis'] : null,
            'topic'       => fn() => api_str('topic', 300) ?: null,
            'ideas_count' => fn() => api_int('ideas_count', 10, 3, 30),
            'notes'       => fn() => api_str('notes', 2000) ?: null,
        ];
        foreach ($map as $col => $fn) {
            if (array_key_exists($col, $in)) {
                $set[] = "`{$col}` = ?";
                $vals[] = $fn();
            }
        }
        if (!$set) {
            api_ok(['campaign' => campaign_to_api($c, false), 'unchanged' => true]);
        }
        $vals[] = $c['id'];
        $vals[] = $c['revision'];
        db_run('UPDATE campaigns SET ' . implode(', ', $set) . ', revision = revision + 1 WHERE id = ? AND revision = ?', $vals);

        $fresh = db_one('SELECT * FROM campaigns WHERE id = ?', [$c['id']]);
        if ((int) $fresh['revision'] === (int) $c['revision']) {
            // سباق: حد تاني حفظ في نفس اللحظة
            api_fail('الحملة اتعدّلت من مكان تاني', 'conflict', 409, ['campaign' => campaign_to_api($fresh, false)]);
        }
        api_ok(['campaign' => campaign_to_api($fresh, false), 'saved_at' => date('c')]);

    /* ── الانتقال بين المراحل ── */
    case 'stage':
        $c = api_own('campaigns', api_int('id'), $uid);
        $to = api_int('stage', 1, 1, 6);
        // مسموح ترجع لأي مرحلة، أو تتقدم خطوة واحدة بعد أبعد مرحلة وصلتها
        if ($to > (int) $c['max_stage'] + 1) {
            api_fail('كمّل المرحلة الحالية الأول', 'locked', 422);
        }
        // Brand Brain: المحتوى والتصميم بيفتحوا عند نسبة صحة الهوية المحددة (الأفكار مفتوحة دايمًا)
        if ($to >= 2) {
            require_once __DIR__ . '/../../includes/brand-brain.php';
            $h = brand_health(brand_for_user($uid));
            if (!$h['unlocked']) {
                api_fail('لازم هوية البراند تعدّي ' . $h['gate'] . '% علشان المحتوى يطلع بأسلوبك — هويتك دلوقتي ' . $h['pct'] . '%',
                    'brand_gate', 422, ['health' => $h]);
            }
        }
        db_run('UPDATE campaigns SET stage = ?, max_stage = GREATEST(max_stage, ?), revision = revision + 1 WHERE id = ?',
            [$to, $to, $c['id']]);
        api_ok(['campaign' => campaign_to_api(db_one('SELECT * FROM campaigns WHERE id = ?', [$c['id']]))]);

    /* ── تكرار ── */
    case 'duplicate':
        $c = api_own('campaigns', api_int('id'), $uid);
        $id = db_insert(
            'INSERT INTO campaigns (user_id, title, goal, basis, topic, ideas_count, notes, period_month)
             VALUES (?,?,?,?,?,?,?,?)',
            [$uid, mb_substr($c['title'] . ' (نسخة)', 0, 150), $c['goal'], $c['basis'], $c['topic'],
             $c['ideas_count'], $c['notes'], date('Y-m')]
        );
        api_ok(['campaign' => campaign_to_api(db_one('SELECT * FROM campaigns WHERE id = ?', [$id]))], 201);

    /* ── تغيير الحالة ── */
    case 'archive':
    case 'restore':
    case 'complete':
        $c = api_own('campaigns', api_int('id'), $uid);
        $to = ['archive' => 'archived', 'restore' => 'active', 'complete' => 'completed'][$action];
        db_run('UPDATE campaigns SET status = ?, revision = revision + 1 WHERE id = ?', [$to, $c['id']]);
        api_ok(['campaign' => campaign_to_api(db_one('SELECT * FROM campaigns WHERE id = ?', [$c['id']]))]);

    /* ── حذف (المحتوى بيفضل — بيتفك من الحملة بس) ── */
    case 'delete':
        $c = api_own('campaigns', api_int('id'), $uid);
        db_run('UPDATE contents SET campaign_id = NULL WHERE campaign_id = ? AND user_id = ?', [$c['id'], $uid]);
        db_run('DELETE FROM campaigns WHERE id = ? AND user_id = ?', [$c['id'], $uid]);
        api_ok(['deleted' => (int) $c['id']]);

    default:
        api_fail('عملية غير معروفة', 'bad_action', 400);
}
