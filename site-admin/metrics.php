<?php
/**
 * أرقام لوحة الموقع — كلها حقيقية:
 *   الزيارات من site_pageviews (عدّاد الموقع) · التجربة/التسجيلات/الاشتراكات/الإيرادات من قاعدة المنصة (قراءة بس)
 *   لو المنصة مش متاحة: القيمة null والواجهة بتقول «اربط المنصة» بدل ما تخترع أرقام.
 */
require_once __DIR__ . '/auth.php';

/** سلسلة يومية [Y-m-d => n] لآخر $days يوم */
function sm_days(int $days): array
{
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) $out[date('Y-m-d', strtotime("-{$i} days"))] = 0;
    return $out;
}

function sm_fill(array $base, array $rows): array
{
    foreach ($rows as $r) {
        $d = (string) ($r['d'] ?? '');
        if (isset($base[$d])) $base[$d] = (float) $r['n'];
    }
    return $base;
}

function sm_platform_ok(): bool
{
    return s_platform_pdo() !== null;
}

/**
 * المقاييس: key => [label, color, series[], total, prev_total]
 * prev_total = نفس الفترة اللي قبلها (للنسبة)
 */
function sm_metrics(int $days = 30): array
{
    static $cache = [];
    if (isset($cache[$days])) return $cache[$days];
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $prevFrom = date('Y-m-d', strtotime('-' . (2 * $days - 1) . ' days'));
    $base = sm_days($days);

    $m = [];
    // الزيارات (الموقع)
    $rows = s_all('SELECT day AS d, SUM(views) AS n FROM site_pageviews WHERE day >= ? GROUP BY day', [$from]);
    $prev = (int) (s_one('SELECT COALESCE(SUM(views),0) n FROM site_pageviews WHERE day >= ? AND day < ?', [$prevFrom, $from])['n'] ?? 0);
    $m['visits'] = ['إجمالي الزيارات', '#0C87EF', sm_fill($base, $rows), null, $prev];

    $p = sm_platform_ok();
    $q = function (string $sqlSeries, string $sqlPrev) use ($p, $base, $from, $prevFrom): array {
        if (!$p) return [null, null];
        return [sm_fill($base, s_platform_all($sqlSeries, [$from])), (float) (s_platform_all($sqlPrev, [$prevFrom, $from])[0]['n'] ?? 0)];
    };
    [$s, $pv] = $q('SELECT DATE(created_at) d, COUNT(*) n FROM trial_sessions WHERE created_at >= ? GROUP BY DATE(created_at)',
                   'SELECT COUNT(*) n FROM trial_sessions WHERE created_at >= ? AND created_at < ?');
    $m['trials'] = ['محاولات Create Post', '#2EC4A9', $s, null, $pv];
    [$s, $pv] = $q('SELECT DATE(created_at) d, COUNT(*) n FROM trial_sessions WHERE created_at >= ? AND generated_text IS NOT NULL GROUP BY DATE(created_at)',
                   'SELECT COUNT(*) n FROM trial_sessions WHERE created_at >= ? AND created_at < ? AND generated_text IS NOT NULL');
    $m['posts'] = ['منشورات اتولّدت في التجربة', '#9C8CFF', $s, null, $pv];
    [$s, $pv] = $q('SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE created_at >= ? GROUP BY DATE(created_at)',
                   'SELECT COUNT(*) n FROM users WHERE created_at >= ? AND created_at < ?');
    $m['signups'] = ['التسجيلات', '#F2A93B', $s, null, $pv];
    [$s, $pv] = $q('SELECT DATE(claimed_at) d, COUNT(*) n FROM trial_sessions WHERE claimed_at >= ? GROUP BY DATE(claimed_at)',
                   'SELECT COUNT(*) n FROM trial_sessions WHERE claimed_at >= ? AND claimed_at < ?');
    $m['claims'] = ['تسجيل من التجربة', '#0A9FB0', $s, null, $pv];
    [$s, $pv] = $q('SELECT DATE(created_at) d, COUNT(*) n FROM user_plans WHERE created_at >= ? AND source = "paid" GROUP BY DATE(created_at)',
                   'SELECT COUNT(*) n FROM user_plans WHERE created_at >= ? AND created_at < ? AND source = "paid"');
    $m['subs'] = ['الاشتراكات', '#E5534B', $s, null, $pv];
    [$s, $pv] = $q('SELECT DATE(reviewed_at) d, SUM(final_amount) n FROM payment_requests WHERE status = "approved" AND reviewed_at >= ? GROUP BY DATE(reviewed_at)',
                   'SELECT COALESCE(SUM(final_amount),0) n FROM payment_requests WHERE status = "approved" AND reviewed_at >= ? AND reviewed_at < ?');
    $m['revenue'] = ['الإيرادات (ج.م)', '#0B7A66', $s, null, $pv];

    foreach ($m as $k => &$v) {
        $v[3] = is_array($v[2]) ? array_sum($v[2]) : null;
    }
    unset($v);
    return $cache[$days] = $m;
}

/** نسبة التغيّر مقارنة بالفترة اللي قبلها */
function sm_delta(?float $cur, ?float $prev): ?int
{
    if ($cur === null || $prev === null || $prev <= 0) return null;
    return (int) round(($cur - $prev) / $prev * 100);
}

/** كارت مقياس */
function sm_stat_card(string $key, array $mt, string $href = ''): string
{
    [$label, $color, $series, $total, $prev] = $mt;
    $tag = $href !== '' ? 'a' : 'div';
    $h = '<' . $tag . ' class="ad-stat sa-in"' . ($href !== '' ? ' href="' . e($href) . '"' : '') . '><small>' . e($label) . '</small>';
    if ($total === null) {
        return $h . '<span class="v"><b style="font-size:19px">اربط المنصة</b></span><span class="ad-hint">الرقم ده من قاعدة المنصة — مش متاحة دلوقتي.</span></' . $tag . '>';
    }
    $d = sm_delta((float) $total, $prev);
    $fmt = $key === 'revenue' ? number_format($total, 0) : number_format($total);
    $h .= '<span class="v"><b>' . $fmt . '</b>' . ($d !== null ? '<em class="' . ($d < 0 ? 'dn' : '') . '" dir="ltr">' . ($d >= 0 ? '+' : '') . $d . '%</em>' : '') . '</span>';
    return $h . sa_spark(array_values($series), $color) . '</' . $tag . '>';
}

/** رسم خطي لعدة سلاسل (SVG) */
function sm_line_chart(array $metrics, array $keys, int $days): string
{
    $w = 720; $h = 220;
    $max = 1;
    foreach ($keys as $k) if (is_array($metrics[$k][2] ?? null)) $max = max($max, max($metrics[$k][2]));
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="رسم بياني للنشاط">';
    for ($g = 0; $g <= 4; $g++) {
        $y = round(10 + ($h - 20) * $g / 4, 1);
        $svg .= '<line x1="0" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '" stroke="#EEF3F8" stroke-width="1" vector-effect="non-scaling-stroke"/>';
    }
    foreach ($keys as $k) {
        $s = $metrics[$k][2] ?? null;
        if (!is_array($s)) continue;
        $vals = array_values($s);
        $n = count($vals);
        $pts = [];
        foreach ($vals as $i => $v) $pts[] = round($i / max(1, $n - 1) * $w, 1) . ',' . round($h - 10 - ($v / $max) * ($h - 20), 1);
        $svg .= '<polyline fill="none" stroke="' . e($metrics[$k][1]) . '" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" points="' . implode(' ', $pts) . '"/>';
    }
    return $svg . '</svg>';
}
