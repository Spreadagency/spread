<?php
/**
 * Spread AI — تقرير تكلفة الحملة (خطة الحملات · المرحلة 0) — قراية بس، مابيكتبش أي حاجة
 *
 * لكل خطوة في الحملة الجديدة (§3 في docs/PLAN_CAMPAIGNS_V2.md):
 *   - المتوسط الحقيقي من سجل الطلبات (ai_runs، آخر 30 يوم) لو فيه عمليات كفاية بتكلفة معروفة
 *   - وإلا تقدير: أسعار الموديل (ai_prices) × التوكنز المتوقعة للخطوة
 *   - ولو مفيش سعر خالص ← «تكلفة مجهولة» ومابتدخلش الحساب
 * وبعدين المعادلتين (المتوسط وأقصى حالة) لكل باقة على موديلات مستوى الجودة بتاعها، والتكلفة الشهرية قدام السعر.
 */

require_once __DIR__ . '/usage.php';
if (is_file(__DIR__ . '/ai-gateway.php')) require_once __DIR__ . '/ai-gateway.php';
if (is_file(__DIR__ . '/quality-tiers.php')) require_once __DIR__ . '/quality-tiers.php';

/** خطوات الحملة: مهمة البوابة + التوكنز المتوقعة لكل طلب (تقدير مبدئي — بيتعدل من الإعداد cc_steps_json) */
function cc_steps(): array
{
    $def = [
        'plan'   => ['task' => 'ideas',   'label' => 'الخطة والأفكار (لكل دفعة 5 أفكار)', 'cap' => 'text',  'in' => 4500, 'out' => 1500],
        'write'  => ['task' => 'content', 'label' => 'كتابة منشور + تقييمه (طلب واحد)',    'cap' => 'text',  'in' => 5000, 'out' => 900],
        'dwrite' => ['task' => 'general', 'label' => 'كاتب برومبت التصميم (لكل شريحة)',    'cap' => 'text',  'in' => 3000, 'out' => 400],
        'image'  => ['task' => 'design',  'label' => 'صورة التصميم (لكل شريحة)',           'cap' => 'image', 'in' => 0,    'out' => 0, 'images' => 1],
        'video'  => ['task' => 'content', 'label' => 'سكريبت فيديو',                       'cap' => 'text',  'in' => 4500, 'out' => 900],
    ];
    $ov = json_decode((string) (function_exists('get_setting') ? get_setting('cc_steps_json', '') : ''), true);
    if (is_array($ov)) {
        foreach ($ov as $k => $v) {
            if (!isset($def[$k]) || !is_array($v)) continue;
            foreach (['in', 'out'] as $f) if (isset($v[$f]) && is_numeric($v[$f])) $def[$k][$f] = max(0, (int) $v[$f]);
        }
    }
    return $def;
}

/** شكل الحملة المفترض (من الفورم في صفحة الأدمن) — كل الأرقام بحدود معقولة */
function cc_shape(array $in = []): array
{
    $n = fn(string $k, float $def, float $min, float $max) => isset($in[$k]) && is_numeric($in[$k]) ? max($min, min($max, (float) $in[$k])) : $def;
    return [
        'campaigns' => (int) $n('campaigns', 2, 0, 30),
        'posts' => (int) $n('posts', 12, 0, 60),
        'carousel_pct' => $n('carousel_pct', 25, 0, 100),
        'slides' => (int) $n('slides', 5, 1, 10),
        'videos' => (int) $n('videos', 2, 0, 20),
        'edits' => (int) $n('edits', 10, 0, 100),
        'improve' => $n('improve', 0.3, 0, 1),
        'plan_regens' => (int) $n('plan_regens', 2, 0, 5),
        'retry' => $n('retry', 1.2, 1, 3),
    ];
}

/** الموديل اللي المهمة هتشتغل عليه في مستوى جودة معيّن ← ['p' => provider, 'm' => model] أو null */
function cc_model_for(string $task, string $tier): ?array
{
    if (function_exists('qt_route') && ($r = qt_route($tier, $task)) && !empty($r['p']) && !empty($r['m'])) return ['p' => $r['p'], 'm' => $r['m']];
    if (function_exists('ai_gw_chain')) {
        try {
            $ch = ai_gw_chain($task);
            if (!empty($ch[0]['p']['name']) && !empty($ch[0]['m'])) return ['p' => (string) $ch[0]['p']['name'], 'm' => (string) $ch[0]['m']];
        } catch (\Throwable $e) {}
    }
    return null;
}

/** المتوسط الحقيقي للمهمة على موديل معيّن من ai_runs (آخر 30 يوم) — null لو العمليات قليلة */
function cc_measured(string $task, ?string $model, int $minRuns = 5): ?array
{
    try {
        $p = [$task];
        $sqlM = '';
        if ($model !== null && $model !== '') { $sqlM = ' AND model = ?'; $p[] = $model; }
        $r = db_one('SELECT COUNT(*) n, SUM(cost_usd > 0) priced, AVG(CASE WHEN cost_usd > 0 THEN cost_usd END) avg_usd,
                            AVG(tokens_in) tin, AVG(tokens_out) tout
                     FROM ai_runs WHERE task = ? AND status IN ("ok","success") AND COALESCE(sandbox, 0) = 0
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)' . $sqlM, $p);
    } catch (\Throwable $e) {
        return null;
    }
    if (!$r || (int) $r['priced'] < $minRuns) return null;
    return ['n' => (int) $r['n'], 'priced' => (int) $r['priced'], 'unknown' => (int) $r['n'] - (int) $r['priced'],
            'usd' => round((float) $r['avg_usd'], 6), 'tin' => (int) round((float) $r['tin']), 'tout' => (int) round((float) $r['tout'])];
}

/**
 * تكلفة طلب واحد لكل خطوة في مستوى جودة:
 * [step => ['label','task','model','usd' (أو null = مجهولة),'source' (measured|estimate|default|unknown),'tin','tout','runs']]
 * default = الموديل مالوش سعر في ai_prices فاتحسب بالسعر الافتراضي (ai_est_*) — بيظهر كتحذير
 */
function cc_step_costs(string $tier): array
{
    $out = [];
    foreach (cc_steps() as $k => $s) {
        $m = cc_model_for($s['task'], $tier);
        $row = ['label' => $s['label'], 'task' => $s['task'], 'model' => $m ? $m['p'] . ' · ' . $m['m'] : null,
                'usd' => null, 'source' => 'unknown', 'tin' => $s['in'], 'tout' => $s['out'], 'runs' => 0];
        $meas = cc_measured($s['task'], $m['m'] ?? null);
        if ($meas) {
            $row = array_merge($row, ['usd' => $meas['usd'], 'source' => 'measured', 'tin' => $meas['tin'], 'tout' => $meas['tout'], 'runs' => $meas['priced']]);
        } elseif ($m) {
            [$usd, $src] = usage_cost(['provider' => $m['p'], 'model' => $m['m'], 'capability' => $s['cap'],
                                       'tokens_in' => $s['in'], 'tokens_out' => $s['out'], 'images' => (int) ($s['images'] ?? 0), 'size' => '1024x1024']);
            // calculated = من جدول الأسعار · estimated = الموديل مالوش سعر، فاتحسب بالسعر الافتراضي من الإعدادات (بيتعلّم)
            if ($usd > 0) $row = array_merge($row, ['usd' => round((float) $usd, 6), 'source' => $src === 'calculated' ? 'estimate' : 'default']);
        }
        $out[$k] = $row;
    }
    return $out;
}

/**
 * المعادلتين (§3.2): تكلفة حملة واحدة بالدولار — المتوسط وأقصى حالة + الخطوات اللي تكلفتها مجهولة
 */
function cc_campaign_cost(array $steps, array $shape): array
{
    $c = fn(string $k) => (float) ($steps[$k]['usd'] ?? 0);
    $unknown = array_keys(array_filter($steps, fn($s) => $s['usd'] === null));
    $defaulted = array_keys(array_filter($steps, fn($s) => $s['source'] === 'default'));
    $pieces = $shape['posts'] + $shape['videos'];
    $batches = (int) max(1, ceil($pieces / 5));
    $carousels = (int) round($shape['posts'] * $shape['carousel_pct'] / 100);
    $singles = $shape['posts'] - $carousels;
    $frames = $singles + $carousels * $shape['slides'];                       // عدد الصور في الحملة
    $design1 = $c('dwrite') + $c('image');                                    // تكلفة شريحة واحدة

    $avg = $batches * $c('plan')
        + $shape['posts'] * (1 + $shape['improve']) * $c('write')
        + $frames * $design1
        + $shape['videos'] * $c('video');

    // أقصى حالة: إعادات الخطة + تحسين لكل منشور + رصيد التعديلات كله على أغلى تعديل (تصميم كاروسيل كامل) × المحاولات التانية
    $costliestEdit = max($c('write'), ($carousels > 0 ? $shape['slides'] : 1) * $design1);
    $worst = ($batches * (1 + $shape['plan_regens']) * $c('plan')
        + $shape['posts'] * 2 * $c('write')
        + $frames * $design1
        + $shape['edits'] * $costliestEdit
        + $shape['videos'] * $c('video')) * $shape['retry'];

    return ['avg_usd' => round($avg, 4), 'worst_usd' => round($worst, 4), 'unknown' => $unknown, 'defaulted' => $defaulted,
            'frames' => $frames, 'batches' => $batches, 'carousels' => $carousels];
}

/** سعر الباقة لـ 30 يوم بالجنيه (من مدد الأسعار لو موجودة، وإلا السعر الأساسي) */
function cc_package_month_price(array $pkg): ?float
{
    try {
        $p = db_one('SELECT price_egp, days FROM package_prices WHERE package_id = ? AND is_active = 1 ORDER BY ABS(days - 30), id LIMIT 1', [(int) $pkg['id']]);
        if ($p && (float) $p['price_egp'] > 0 && (int) $p['days'] > 0) return round((float) $p['price_egp'] * 30 / (int) $p['days'], 2);
    } catch (\Throwable $e) {}
    return isset($pkg['price_egp']) && (float) $pkg['price_egp'] > 0 ? (float) $pkg['price_egp'] : null;
}

/** التقرير كامل: لكل باقة شغالة ← التكلفة الشهرية (المتوسط وأقصى حالة) قدام السعر والهامش */
function cc_report(array $shapeIn = []): array
{
    $shape = cc_shape($shapeIn);
    $fx = usage_fx_rate();
    $byTier = [];
    $rows = [];
    try {
        $pkgs = db_all('SELECT * FROM credit_packages WHERE is_active = 1 ORDER BY order_num, id');
    } catch (\Throwable $e) {
        $pkgs = [];
    }
    foreach ($pkgs as $pkg) {
        $tier = (string) ($pkg['quality_tier'] ?? 'standard') ?: 'standard';
        $byTier[$tier] ??= cc_step_costs($tier);
        // الباقة اللي ليها حدود حملات (المرحلة 4) بتتحسب بأرقامها هي — غير كده بشكل الحملة من الفورم
        $ps = $shape;
        if ((int) ($pkg['campaigns_month'] ?? 0) > 0) {
            $ps['campaigns'] = (int) $pkg['campaigns_month'];
            $ps['posts'] = (int) $pkg['posts_per_campaign'];
            $ps['videos'] = (int) $pkg['videos_per_campaign'];
            $ps['edits'] = (int) $pkg['free_edits_per_campaign'];
            if ((int) $pkg['carousel_max_slides'] > 0) $ps['slides'] = (int) $pkg['carousel_max_slides'];
        }
        $cc = cc_campaign_cost($byTier[$tier], $ps);
        $month = cc_package_month_price($pkg);
        $avgM = $cc['avg_usd'] * $ps['campaigns'] * $fx;
        $worstM = $cc['worst_usd'] * $ps['campaigns'] * $fx;
        $rows[] = [
            'id' => (int) $pkg['id'], 'name' => (string) $pkg['name'], 'tier' => $tier, 'price' => $month,
            'shape_source' => (int) ($pkg['campaigns_month'] ?? 0) > 0 ? 'package' : 'form', 'campaigns' => $ps['campaigns'],
            'campaign_avg_egp' => round($cc['avg_usd'] * $fx, 2), 'campaign_worst_egp' => round($cc['worst_usd'] * $fx, 2),
            'month_avg_egp' => round($avgM, 2), 'month_worst_egp' => round($worstM, 2),
            'margin_avg' => $month ? round(($month - $avgM) / $month * 100, 1) : null,
            'margin_worst' => $month ? round(($month - $worstM) / $month * 100, 1) : null,
            'unknown' => $cc['unknown'], 'defaulted' => $cc['defaulted'], 'frames' => $cc['frames'],
        ];
    }
    return ['shape' => $shape, 'fx' => $fx, 'steps' => $byTier, 'rows' => $rows];
}

/**
 * قياس الكاش (خطة الحملات §7): من سجل الطلبات — طلبات الحملات (الأفكار · الكتابة · التقييم) آخر N يوم
 * @return array{runs: int, tokens_in: int, tokens_cached: int, pct: float, cost_usd: float, on: bool}
 */
function cc_cache_stats(int $days = 30): array
{
    $out = ['runs' => 0, 'tokens_in' => 0, 'tokens_cached' => 0, 'pct' => 0.0, 'cost_usd' => 0.0,
            'on' => function_exists('get_setting') && get_setting('campaign_prompt_cache', '1') === '1'];
    try {
        $r = db_one('SELECT COUNT(*) n, COALESCE(SUM(tokens_in), 0) tin, COALESCE(SUM(tokens_cached), 0) tc, COALESCE(SUM(cost_usd), 0) usd FROM ai_runs
                     WHERE task IN ("content", "ideas", "eval") AND ref_type IN ("campaign", "campaign_idea") AND status = "ok" AND COALESCE(sandbox, 0) = 0
                       AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)', [max(1, $days)]);
        $out['runs'] = (int) $r['n'];
        $out['tokens_in'] = (int) $r['tin'];
        $out['tokens_cached'] = min((int) $r['tc'], (int) $r['tin']);
        $out['pct'] = $out['tokens_in'] > 0 ? round(100 * $out['tokens_cached'] / $out['tokens_in'], 1) : 0.0;
        $out['cost_usd'] = round((float) $r['usd'], 4);
    } catch (\Throwable $e) {}
    return $out;
}
