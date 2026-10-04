<?php
/**
 * Spread AI — أرقام لوحة الأدمن (المرحلة 8): الفترات · السلاسل اليومية · اقتصاديات الـ AI
 */

require_once __DIR__ . '/usage.php';

/** فترة من الـ query: today | 7d | month | last_month | 30d | 90d | custom (from/to) */
function a2_period(string $p = '7d', ?string $from = null, ?string $to = null): array
{
    $today = date('Y-m-d');
    switch ($p) {
        case 'today':
            $f = $today; $t = date('Y-m-d', strtotime('+1 day')); $lbl = 'النهارده'; break;
        case 'month':
            $f = date('Y-m-01'); $t = date('Y-m-d', strtotime('+1 day')); $lbl = 'الشهر ده'; break;
        case 'last_month':
            $f = date('Y-m-01', strtotime('first day of last month')); $t = date('Y-m-01'); $lbl = 'الشهر اللي فات'; break;
        case '30d':
            $f = date('Y-m-d', strtotime('-29 days')); $t = date('Y-m-d', strtotime('+1 day')); $lbl = 'آخر 30 يوم'; break;
        case '90d':
            $f = date('Y-m-d', strtotime('-89 days')); $t = date('Y-m-d', strtotime('+1 day')); $lbl = 'آخر 3 شهور'; break;
        case 'custom':
            $fv = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-d', strtotime('-6 days'));
            $tv = $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : $today;
            if ($tv < $fv) [$fv, $tv] = [$tv, $fv];
            $f = $fv; $t = date('Y-m-d', strtotime($tv . ' +1 day')); $lbl = $fv . ' ← ' . $tv; break;
        default:
            $p = '7d'; $f = date('Y-m-d', strtotime('-6 days')); $t = date('Y-m-d', strtotime('+1 day')); $lbl = 'آخر 7 أيام';
    }
    $days = max(1, (int) round((strtotime($t) - strtotime($f)) / 86400));
    return ['key' => $p, 'from' => $f . ' 00:00:00', 'to' => $t . ' 00:00:00', 'label' => $lbl, 'days' => $days,
            'from_d' => $f, 'to_d' => date('Y-m-d', strtotime($t . ' -1 day'))];
}

/** الفترة السابقة بنفس الطول (للمقارنة) */
function a2_prev_period(array $per): array
{
    $len = strtotime($per['to']) - strtotime($per['from']);
    return ['from' => date('Y-m-d H:i:s', strtotime($per['from']) - $len), 'to' => $per['from']];
}

/**
 * سلسلة يومية: SQL لازم يرجّع (d DATE, v NUMBER) ويستخدم ? للبداية والنهاية
 * @return array قيم بترتيب الأيام من $fromD لـ $toD
 */
function a2_daily(string $sql, string $fromD, string $toD, array $extra = []): array
{
    $out = [];
    for ($d = strtotime($fromD); $d <= strtotime($toD); $d += 86400) $out[date('Y-m-d', $d)] = 0;
    try {
        foreach (db_all($sql, array_merge([$fromD . ' 00:00:00', date('Y-m-d', strtotime($toD . ' +1 day')) . ' 00:00:00'], $extra)) as $r) {
            if (isset($out[$r['d']])) $out[$r['d']] = (float) $r['v'];
        }
    } catch (\Throwable $e) {
    }
    return $out;
}

/** صفوف بأمان (جدول ناقص قبل الترحيل = قايمة فاضية) */
function a2_all(string $sql, array $params = []): array
{
    try { return db_all($sql, $params); } catch (\Throwable $e) { return []; }
}

/** رقم واحد بأمان */
function a2_scalar(string $sql, array $params = [], $default = 0)
{
    try {
        $r = db_one($sql, $params);
        if (!$r) return $default;
        $v = reset($r);
        return $v === null ? $default : $v;
    } catch (\Throwable $e) {
        return $default;
    }
}

/** نسبة التغيّر */
function a2_delta(float $now, float $prev): ?float
{
    if ($prev <= 0) return $now > 0 ? null : 0.0;
    return round(($now - $prev) / $prev * 100, 1);
}

/** قيمة الكريدت (ج.م): يدوي من الإعدادات، وإلا متوسط المدفوع فعلًا في آخر 90 يوم */
function a2_credit_value(): array
{
    $manual = (float) (function_exists('get_setting') ? get_setting('credit_value_egp', '0') : 0);
    if ($manual > 0) return [$manual, 'manual'];
    $r = null;
    try {
        $r = db_one('SELECT COALESCE(SUM(credits * unit_egp),0) v, COALESCE(SUM(credits),0) c FROM credit_lots WHERE source = "paid" AND estimated = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)');
    } catch (\Throwable $e) {
    }
    if ($r && (int) $r['c'] > 0) return [round((float) $r['v'] / (int) $r['c'], 4), 'paid_90d'];
    try {
        $r = db_one('SELECT COALESCE(SUM(price_egp),0) v, COALESCE(SUM(credits),0) c FROM credit_packages WHERE is_active = 1');
        if ($r && (int) $r['c'] > 0) return [round((float) $r['v'] / (int) $r['c'], 4), 'packages'];
    } catch (\Throwable $e) {
    }
    return [0.0, 'none'];
}

/** العمليات اللي بتتسعّر بالكريدت ← مهمة البوابة اللي بتصرف عليها (لتسعير العمليات) */
function a2_op_catalog(): array
{
    return [
        'content_generation_cost'   => ['✎ توليد منشور', 'content', 1],
        'content_regeneration_cost' => ['↻ إعادة توليد منشور', 'content', 1],
        'content_design_cost'       => ['🎨 توليد تصميم (لكل صورة/شريحة)', 'design', 2],
        'logo_generate_cost'        => ['✨ توليد لوجو', 'design', 3],
        'plan_ideas_cost'           => ['💡 أفكار خطة/حملة', 'ideas', 2],
        'studio_idea_cost'          => ['✦ فكرة تصميم', 'ideas', 1],
        'source_summary_cost'       => ['📄 تلخيص مستند', 'brand', 1],
        'brand_summary_cost'        => ['◈ ملخص الهوية الذكي', 'brand', 2],
        'visual_identity_cost'      => ['🖌 تحليل الهوية البصرية', 'brand', 2],
        'campaign_eval_cost'        => ['⭐ تقييم منشور', 'eval', 0],
        'research_cost_quick'       => ['🔬 بحث عميق — سريع', 'research', 2],
        'research_cost_medium'      => ['🔬 بحث عميق — متوسط', 'research', 4],
        'research_cost_deep'        => ['🔬 بحث عميق — عميق', 'research', 8],
    ];
}

/** متوسط التكلفة الفعلية (USD) لعملية ناجحة من مهمة في آخر N يوم — شامل المحاولات الفاشلة */
function a2_task_avg_cost(string $task, int $days = 30): array
{
    try {
        if ($task === 'research') {
            // البحث: كل عمليات البحث الواحد (بحث ويب + تحليل) مجمّعة على رقمه
            $r = db_one(
                'SELECT COUNT(*) n, COALESCE(AVG(c),0) avg_usd FROM (
                    SELECT ref_id, SUM(cost_usd) c FROM ai_runs WHERE ref_type = "research" AND ref_id IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                    GROUP BY ref_id) x',
                [$days]
            );
        } else {
            $r = db_one(
                'SELECT SUM(status = "ok") n, COALESCE(SUM(cost_usd) / NULLIF(SUM(status = "ok"), 0), 0) avg_usd
                 FROM ai_runs WHERE task = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$task, $days]
            );
        }
        return ['n' => (int) ($r['n'] ?? 0), 'avg_usd' => (float) ($r['avg_usd'] ?? 0)];
    } catch (\Throwable $e) {
        return ['n' => 0, 'avg_usd' => 0.0];
    }
}

/** SVG خطي لعدة سلاسل (مع إمكانية الإخفاء من الـ legend) */
function a2_line_chart(array $series, array $labels, string $id = 'a2c'): string
{
    $w = 1000; $h = 220; $pad = 12;
    $n = max(2, count($labels));
    $max = 1;
    foreach ($series as $s) $max = max($max, max($s['data'] ?: [0]));
    $grid = '';
    for ($i = 1; $i <= 3; $i++) {
        $y = round($pad + ($h - 2 * $pad) * $i / 4, 1);
        $grid .= '<line x1="0" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" stroke="#EEF2F7" stroke-width="1"/>';
    }
    $paths = '';
    foreach ($series as $k => $s) {
        $pts = [];
        $vals = array_values($s['data']);
        foreach ($vals as $i => $v) {
            $x = round($i * $w / ($n - 1), 1);
            $y = round($h - $pad - ($v / $max) * ($h - 2 * $pad), 1);
            $pts[] = "$x,$y";
        }
        $paths .= '<polyline data-s="' . $k . '" data-v="' . e(json_encode($vals)) . '" fill="none" stroke="' . e($s['color']) . '" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" points="' . implode(' ', $pts) . '"/>';
    }
    $leg = '';
    foreach ($series as $k => $s) {
        $leg .= '<button type="button" class="a2-lg" data-s="' . $k . '" aria-pressed="true"><i style="background:' . e($s['color']) . '"></i>' . e($s['label']) . '</button>';
    }
    $first = $labels ? reset($labels) : '';
    $last = $labels ? end($labels) : '';
    return '<div class="a2-lc" id="' . e($id) . '"><svg class="a2-chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="رسم الاستهلاك">'
        . $grid . $paths . '</svg><div class="a2-lc-x"><span>' . e($first) . '</span><span>' . e($last) . '</span></div><div class="a2-legend">' . $leg . '</div></div>';
}
