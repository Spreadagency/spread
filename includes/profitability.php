<?php
/**
 * Spread AI — تقرير الربحية لكل باقة (المرحلة 8)
 *
 * الإيراد (موزّع على المدة): المبلغ اللي اتدفع في الاشتراك بيتوزّع بالتساوي على أيام الدورة، والفترة بتاخد نصيب أيامها بس
 *   (السنوي مايتحسبش كله في شهر الدفع). الدورة اللي اتبدّلت بدورة جديدة بتخلص يوم ما الجديدة بدأت.
 *   المبلغ: طلب الدفع المعتمد المربوط بالدورة · أو أوردر Paymob («Paymob #ID» في ملاحظة الدورة) · غير كده صفر (هدية · باقة التسجيل · تفعيل يدوي).
 * الإضافات (تصميمات/كريدت مساعد): بتتحسب يوم اعتمادها على باقة العميل وقتها. الفيديو: إيراد لوحده (التنفيذ يدوي ومالوش تكلفة AI).
 * التكلفة: تكلفة الـ AI الفعلية (ai_runs.cost_egp — بكل المحاولات، بسعر الدولار وقت العملية، من غير تجارب الأدمن)
 *   على الباقة اللي كانت شغالة للعميل يوم العملية. العمليات من غير عميل (النظام) لوحدها.
 */

require_once __DIR__ . '/usage.php';

const PF_NONE = 0;      // بدون باقة مدفوعة / مجاني
const PF_VIDEO = -1;    // الفيديو (صف لوحده)

/** دورات الاشتراك اللي بتلمس الفترة + المبلغ المدفوع لكل دورة ونهايتها الفعلية */
function pf_plans(string $from, string $to): array
{
    $rows = db_all('SELECT p.id, p.user_id, p.package_id, p.name, p.source, p.note, p.starts_at, p.ends_at, p.quality_tier,
                           (SELECT MIN(n.starts_at) FROM user_plans n WHERE n.user_id = p.user_id AND n.starts_at > p.starts_at) next_start,
                           (SELECT SUM(r.final_amount) FROM payment_requests r WHERE r.user_plan_id = p.id AND r.status = "approved") paid
                    FROM user_plans p
                    WHERE p.starts_at < ? AND p.ends_at > ?', [$to, $from]);
    $out = [];
    foreach ($rows as $p) {
        $end = $p['next_start'] && $p['next_start'] < $p['ends_at'] ? $p['next_start'] : $p['ends_at'];
        if ($end <= $from) continue;
        $amount = (float) ($p['paid'] ?? 0);
        $src = $amount > 0 ? 'request' : 'none';
        if ($amount <= 0 && preg_match('/^Paymob #(\d+)/', (string) $p['note'], $m)) {
            $o = db_one('SELECT amount_egp FROM payment_orders WHERE id = ? AND status = "paid"', [(int) $m[1]]);
            if ($o) { $amount = (float) $o['amount_egp']; $src = 'paymob'; }
        }
        // بالـ UTC عشان التوقيت الصيفي مايغيّرش عدد ساعات اليوم
        $utc = fn(string $d) => (int) strtotime($d . ' UTC');
        $s = $utc($p['starts_at']);
        $e = max($s + 1, $utc($end));
        $ov = max(0, min($e, $utc($to)) - max($s, $utc($from)));
        $out[] = $p + ['eff_end' => $end, 'amount' => $amount, 'amount_src' => $src, 'accrued' => $amount * $ov / ($e - $s),
                       'overlap_days' => $ov / 86400, 'is_new' => $p['starts_at'] >= $from && $p['starts_at'] < $to];
    }
    return $out;
}

/** الباقة اللي كانت شغالة للعميل في لحظة معينة (من دورات الفترة) */
function pf_bucket_at(array $plansByUser, int $uid, string $at): int
{
    foreach ($plansByUser[$uid] ?? [] as $p) {
        if ($at >= $p['starts_at'] && $at < $p['eff_end']) return $p['amount'] > 0 || $p['source'] === 'paid' ? (int) ($p['package_id'] ?: PF_NONE) : PF_NONE;
    }
    return PF_NONE;
}

/**
 * التقرير كامل
 * @return array{rows: array, totals: array, system: array, users: array, tiers: array, notes: array}
 */
function pf_report(string $from, string $to): array
{
    $pk = [];
    foreach (db_all('SELECT id, name, price_egp, quality_tier, is_active FROM credit_packages') as $r) $pk[(int) $r['id']] = $r;
    $blank = fn(int $id, string $name) => ['id' => $id, 'name' => $name, 'subs' => [], 'new' => 0, 'accrued' => 0.0, 'cash' => 0.0, 'addons' => 0.0,
        'cost' => 0.0, 'runs' => 0, 'credits' => 0, 'designs' => 0, 'tasks' => [], 'unknown_amount' => 0, 'tier' => (string) ($pk[$id]['quality_tier'] ?? '')];
    $rows = [];
    $row = function (int $id) use (&$rows, $pk, $blank) {
        if (!isset($rows[$id])) $rows[$id] = $blank($id, $id === PF_NONE ? 'بدون باقة مدفوعة (مجاني · هدية · تجربة)' : ($id === PF_VIDEO ? '🎬 الفيديو (تنفيذ يدوي)' : (string) ($pk[$id]['name'] ?? 'باقة #' . $id . ' (اتمسحت)')));
        return $id;
    };
    $users = [];
    $u = function (int $uid) use (&$users) {
        if (!isset($users[$uid])) $users[$uid] = ['revenue' => 0.0, 'cost' => 0.0, 'runs' => 0, 'pkg' => PF_NONE];
    };

    // ① الإيراد الموزّع + المشتركين + الجداد
    $plans = pf_plans($from, $to);
    $byUser = [];
    foreach ($plans as $p) {
        $byUser[(int) $p['user_id']][] = $p;
        $paid = $p['amount'] > 0 || $p['source'] === 'paid';
        $id = $row($paid ? (int) ($p['package_id'] ?: PF_NONE) : PF_NONE);
        $rows[$id]['subs'][(int) $p['user_id']] = true;
        $rows[$id]['accrued'] += $p['accrued'];
        if ($p['is_new']) $rows[$id]['new']++;
        if ($p['source'] === 'paid' && $p['amount'] <= 0) $rows[$id]['unknown_amount']++;
        $u((int) $p['user_id']);
        $users[(int) $p['user_id']]['revenue'] += $p['accrued'];
        $users[(int) $p['user_id']]['pkg'] = $id;
    }
    // ② المدفوع فعلًا في الفترة (كاش) + الإضافات
    foreach (db_all('SELECT user_id, package_id, addon_id, video_order_id, final_amount, reviewed_at FROM payment_requests
                     WHERE status = "approved" AND reviewed_at >= ? AND reviewed_at < ?', [$from, $to]) as $r) {
        if (!empty($r['video_order_id'])) continue;   // الفيديو تحت
        if (!empty($r['addon_id'])) {
            $id = $row(pf_bucket_at($byUser, (int) $r['user_id'], (string) $r['reviewed_at']));
            $rows[$id]['addons'] += (float) $r['final_amount'];
            $u((int) $r['user_id']);
            $users[(int) $r['user_id']]['revenue'] += (float) $r['final_amount'];
            continue;
        }
        $rows[$row((int) ($r['package_id'] ?: PF_NONE))]['cash'] += (float) $r['final_amount'];
    }
    try {
        foreach (db_all('SELECT package_id, amount_egp FROM payment_orders WHERE status = "paid" AND paid_at >= ? AND paid_at < ?', [$from, $to]) as $o) {
            $rows[$row((int) ($o['package_id'] ?: PF_NONE))]['cash'] += (float) $o['amount_egp'];
        }
    } catch (\Throwable $e) {}
    // الفيديو
    try {
        $v = db_one('SELECT COUNT(*) n, COALESCE(SUM(final_egp),0) s FROM video_orders WHERE status = "paid" AND paid_at >= ? AND paid_at < ?', [$from, $to]);
        if ($v && (int) $v['n'] > 0) {
            $id = $row(PF_VIDEO);
            $rows[$id]['cash'] = $rows[$id]['accrued'] = (float) $v['s'];
            $rows[$id]['new'] = (int) $v['n'];
        }
    } catch (\Throwable $e) {}

    // ③ تكلفة الـ AI (يوم بيوم لكل عميل ← باقته يومها)
    $system = ['cost' => 0.0, 'runs' => 0];
    foreach (db_all('SELECT user_id, DATE(created_at) d, task, COUNT(*) n, COALESCE(SUM(cost_egp),0) c, COALESCE(SUM(credits_charged),0) cr
                     FROM ai_runs WHERE created_at >= ? AND created_at < ? AND COALESCE(sandbox,0) = 0
                     GROUP BY user_id, d, task', [$from, $to]) as $r) {
        if (!$r['user_id']) { $system['cost'] += (float) $r['c']; $system['runs'] += (int) $r['n']; continue; }
        $uid = (int) $r['user_id'];
        $id = $row(pf_bucket_at($byUser, $uid, $r['d'] . ' 12:00:00'));
        $rows[$id]['cost'] += (float) $r['c'];
        $rows[$id]['runs'] += (int) $r['n'];
        $rows[$id]['credits'] += (int) $r['cr'];
        $rows[$id]['tasks'][$r['task']] = ($rows[$id]['tasks'][$r['task']] ?? 0) + (float) $r['c'];
        $rows[$id]['subs'][$uid] = true;   // اللي مالوش دورة بيتعدّ في «بدون باقة»
        $u($uid);
        $users[$uid]['cost'] += (float) $r['c'];
        $users[$uid]['runs'] += (int) $r['n'];
        if (!isset($byUser[$uid])) $users[$uid]['pkg'] = $id;
    }
    // ④ التصميمات (من الحصة)
    try {
        foreach (db_all('SELECT user_id, DATE(created_at) d, COUNT(*) n FROM usage_events WHERE unit = "designs" AND created_at >= ? AND created_at < ? GROUP BY user_id, d', [$from, $to]) as $r) {
            $rows[$row(pf_bucket_at($byUser, (int) $r['user_id'], $r['d'] . ' 12:00:00'))]['designs'] += (int) $r['n'];
        }
    } catch (\Throwable $e) {}

    // ⑤ الأرقام النهائية
    $alert = max(0, min(100, (float) get_setting('profit_alert_margin', '30')));
    $tot = ['subs' => 0, 'new' => 0, 'accrued' => 0.0, 'cash' => 0.0, 'addons' => 0.0, 'cost' => 0.0, 'runs' => 0, 'credits' => 0, 'designs' => 0];
    $tiers = [];
    foreach ($rows as $id => &$r) {
        $r['subs'] = $id === PF_VIDEO ? 0 : count($r['subs']);
        $r['revenue'] = $r['accrued'] + $r['addons'];
        $r['profit'] = $r['revenue'] - $r['cost'];
        $r['margin'] = $r['revenue'] > 0 ? round($r['profit'] / $r['revenue'] * 100, 1) : null;
        $r['rev_per_sub'] = $r['subs'] ? $r['revenue'] / $r['subs'] : 0;
        $r['cost_per_sub'] = $r['subs'] ? $r['cost'] / $r['subs'] : 0;
        $r['level'] = $id === PF_VIDEO ? 'ok' : ($r['revenue'] <= 0 ? ($r['cost'] > 0 ? 'cost' : 'ok') : ($r['profit'] < 0 ? 'loss' : ($r['margin'] < $alert ? 'low' : 'ok')));
        arsort($r['tasks']);
        $r['tasks'] = array_slice($r['tasks'], 0, 5, true);
        foreach ($tot as $k => $_) $tot[$k] += $r[$k];
        if ($id > 0) {
            $t = $r['tier'] ?: 'standard';
            $tiers[$t] = $tiers[$t] ?? ['subs' => 0, 'revenue' => 0.0, 'cost' => 0.0];
            $tiers[$t]['subs'] += $r['subs'];
            $tiers[$t]['revenue'] += $r['revenue'];
            $tiers[$t]['cost'] += $r['cost'];
        }
    }
    unset($r);
    uasort($rows, fn($a, $b) => ($a['id'] <= 0) <=> ($b['id'] <= 0) ?: $b['revenue'] <=> $a['revenue']);
    $tot['revenue'] = $tot['accrued'] + $tot['addons'];
    $tot['profit'] = $tot['revenue'] - $tot['cost'] - $system['cost'];
    $tot['margin'] = $tot['revenue'] > 0 ? round($tot['profit'] / $tot['revenue'] * 100, 1) : null;

    // ⑥ العملاء اللي بيخسّروا (التكلفة أكتر من اللي دفعوه في الفترة)
    $list = [];
    foreach ($users as $uid => $x) {
        if ($x['cost'] <= 0) continue;
        $list[] = ['user_id' => $uid, 'pkg' => $x['pkg'], 'revenue' => $x['revenue'], 'cost' => $x['cost'], 'runs' => $x['runs'], 'profit' => $x['revenue'] - $x['cost']];
    }
    usort($list, fn($a, $b) => $a['profit'] <=> $b['profit']);
    $list = array_slice(array_values(array_filter($list, fn($x) => $x['profit'] <= -0.01)), 0, 15);
    if ($list) {
        $names = [];
        foreach (db_all('SELECT id, name, email FROM users WHERE id IN (' . implode(',', array_map(fn($x) => (int) $x['user_id'], $list)) . ')') as $n) $names[(int) $n['id']] = $n;
        foreach ($list as &$x) $x += ['name' => (string) ($names[$x['user_id']]['name'] ?? '#' . $x['user_id']), 'email' => (string) ($names[$x['user_id']]['email'] ?? ''), 'pkg_name' => $rows[$x['pkg']]['name'] ?? ''];
        unset($x);
    }
    return ['rows' => array_values($rows), 'totals' => $tot, 'system' => $system, 'users' => $list, 'tiers' => $tiers, 'alert' => $alert];
}

/** CSV للتقرير (Excel بيفتحه بالعربي) */
function pf_csv(array $rep, string $label): string
{
    $f = fopen('php://temp', 'r+');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['ربحية الباقات — ' . $label]);
    fputcsv($f, ['الباقة', 'مشتركين', 'جداد', 'الإيراد الموزّع', 'إضافات', 'إجمالي الإيراد', 'المدفوع في الفترة', 'تكلفة الـ AI', 'الربح', 'الهامش %', 'إيراد/مشترك', 'تكلفة/مشترك', 'عمليات AI', 'كريدت', 'تصميمات']);
    foreach ($rep['rows'] as $r) {
        fputcsv($f, [$r['name'], $r['subs'], $r['new'], round($r['accrued'], 2), round($r['addons'], 2), round($r['revenue'], 2), round($r['cash'], 2), round($r['cost'], 2),
                     round($r['profit'], 2), $r['margin'] ?? '', round($r['rev_per_sub'], 2), round($r['cost_per_sub'], 2), $r['runs'], $r['credits'], $r['designs']]);
    }
    $t = $rep['totals'];
    fputcsv($f, ['عمليات النظام (من غير عميل)', '', '', '', '', '', '', round($rep['system']['cost'], 2), '', '', '', '', $rep['system']['runs'], '', '']);
    fputcsv($f, ['الإجمالي', $t['subs'], $t['new'], round($t['accrued'], 2), round($t['addons'], 2), round($t['revenue'], 2), round($t['cash'], 2), round($t['cost'] + $rep['system']['cost'], 2), round($t['profit'], 2), $t['margin'] ?? '', '', '', $t['runs'], $t['credits'], $t['designs']]);
    rewind($f);
    return (string) stream_get_contents($f);
}
