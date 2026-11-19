<?php
/**
 * Spread AI — مركز الجودة: الإعدادات (المرحلة 5)
 * أوزان التقييم · حد «الضعيف» · A/B · التوليد التجريبي · حد طلبات الـ Internal API · زرار البلاغ · تفعيل/إيقاف كل أداة من أدوات الـ MCP
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/ai-center.php';

require_admin();
require_admin_can('ai_settings');

$cat = aic_route_catalog();
/** [المفتاح، الافتراضي، أقل، أكبر] */
$ints = [
    'ab_min_sample' => [30, 5, 10000],
    'ab_duration_days' => [14, 1, 90],
    'sandbox_daily_cap' => [20, 0, 500],
    'iapi_rate_per_min' => [300, 30, 2000],
    'issue_rate_per_hour' => [5, 1, 50],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $errs = [];
    $changed = [];
    $put = function (string $k, string $v) use (&$changed) {
        if ((string) get_setting($k, '') !== $v) { set_setting($k, $v); $changed[] = $k; }
    };

    // الأوزان: المحتوى % والتصميم = الباقي
    $cw = (int) ($_POST['w_content'] ?? 60);
    if ($cw < 0 || $cw > 100) $errs[] = 'وزن المحتوى لازم من 0 لـ 100';
    else $put('mcp_rubric_weights', json_encode(['content' => round($cw / 100, 2), 'design' => round(1 - $cw / 100, 2)]));

    $low = (float) ($_POST['eval_low_threshold'] ?? 6);
    if ($low < 1 || $low > 10) $errs[] = 'حد التقييم الضعيف لازم من 1 لـ 10';
    else $put('eval_low_threshold', (string) round($low, 1));

    foreach ($ints as $k => [$def, $min, $max]) {
        $v = filter_var($_POST[$k] ?? '', FILTER_VALIDATE_INT);
        if ($v === false || $v < $min || $v > $max) { $errs[] = "«$k» لازم رقم من $min لـ $max"; continue; }
        $put($k, (string) $v);
    }
    $put('issues_enabled', !empty($_POST['issues_enabled']) ? '1' : '0');

    // الأدوات: المتعلّم عليه = شغال
    $on = is_array($_POST['routes'] ?? null) ? $_POST['routes'] : [];
    $off = array_values(array_filter(array_keys($cat), fn($r) => !in_array($r, $on, true)));
    $put('mcp_disabled_routes', json_encode($off));

    if ($errs) {
        flash_set('danger', 'ماتحفظش كله — ' . implode(' · ', $errs));
    } else {
        $msg = $changed ? 'اتحفظ ✓ — ' . count($changed) . ' إعداد اتغيّر' : 'مفيش تغيير';
        // إعادة حساب الإجمالي للتقييمات القديمة بالأوزان الجديدة (اختياري)
        if (!empty($_POST['recompute']) && aic_has('ai_evaluations')) {
            $w = aic_weights();
            $st = db()->prepare('UPDATE ' . mcp_t('ai_evaluations') . ' SET overall = IF(design_total IS NULL, content_total, ROUND(? * content_total + ? * design_total, 2)) WHERE content_total IS NOT NULL');
            $st->execute([$w['content'], $w['design']]);
            $msg .= ' · اتعاد حساب ' . $st->rowCount() . ' تقييم';
            $changed[] = 'recompute';
        }
        flash_set('success', $msg);
    }
    if ($changed) admin_log('ai_quality_settings', 'settings', null, json_encode($changed));
    redirect('admin/ai-quality-settings.php');
}

$w = aic_weights();
$off = aic_disabled_routes();
$val = fn(string $k) => (int) get_setting($k, (string) $ints[$k][0]);
$groups = ['read' => ['قراءة', 'بيقرا بس (من غير بيانات شخصية كاملة)'], 'ai_write' => ['تقييم وتسجيل', 'بيكتب في قاعدة الـ AI بس'], 'draft' => ['مسودات', 'مسودات مستنية موافقتك (بتتفعّل في المرحلة 6)']];

$active = 'ai-quality-settings';
$page_title = 'إعدادات الجودة';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div>
                <h1>مركز الجودة: الإعدادات</h1>
                <div class="sub">أوزان التقييم وحدود الـ A/B والبلاغات، وتشغيل أو إيقاف كل أداة من أدوات Claude</div>
            </div>
            <?= aic_tabs('ai-quality-settings') ?>
        </div>

        <form method="post">
            <?= csrf_field() ?>
            <div class="split split-2" style="align-items:flex-start">
                <div>
                    <div class="card" style="margin-bottom:14px">
                        <div class="a2-h"><h3>التقييم</h3></div>
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;color:#27324A">وزن المحتوى في الإجمالي: <b id="wc"><?= round($w['content'] * 100) ?>%</b> · التصميم: <b id="wd"><?= round($w['design'] * 100) ?>%</b></label>
                        <input type="range" name="w_content" min="0" max="100" step="5" value="<?= round($w['content'] * 100) ?>" style="width:100%"
                               oninput="document.getElementById('wc').textContent=this.value+'%';document.getElementById('wd').textContent=(100-this.value)+'%'">
                        <p class="a2-muted" style="font-size:12.5px;margin:4px 0 10px">الإجمالي = المحتوى × الوزن + التصميم × الباقي. بيتطبّق على التقييمات الجديدة (الموقع هو اللي بيحسبه، مش Claude).</p>
                        <label style="display:flex;gap:6px;align-items:center;font-size:13px;margin-bottom:12px"><input type="checkbox" name="recompute" value="1"> طبّق الأوزان على التقييمات القديمة كمان (إعادة حساب)</label>
                        <label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;color:#27324A" for="low">حد التقييم الضعيف</label>
                        <input class="input" id="low" type="number" name="eval_low_threshold" min="1" max="10" step="0.5" value="<?= e((string) aic_low()) ?>" style="width:120px">
                        <p class="a2-muted" style="font-size:12.5px;margin:4px 0 0">أقل من الرقم ده = أحمر في التقييمات ويتعدّ «ضعيف».</p>
                    </div>

                    <div class="card" style="margin-bottom:14px">
                        <div class="a2-h"><h3>A/B والتوليد التجريبي</h3></div>
                        <div class="a2-grid a2-g3">
                            <div><label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;color:#27324A">أقل عدد منشورات للحكم</label><input class="input" type="number" name="ab_min_sample" min="5" max="10000" value="<?= $val('ab_min_sample') ?>"><div class="a2-muted" style="font-size:12px">Claude مش هينصح بتغيير قبله</div></div>
                            <div><label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;color:#27324A">مدة التجربة (أيام)</label><input class="input" type="number" name="ab_duration_days" min="1" max="90" value="<?= $val('ab_duration_days') ?>"><div class="a2-muted" style="font-size:12px">المرحلة 6</div></div>
                            <div><label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;color:#27324A">توليد تجريبي في اليوم</label><input class="input" type="number" name="sandbox_daily_cap" min="0" max="500" value="<?= $val('sandbox_daily_cap') ?>"><div class="a2-muted" style="font-size:12px">المرحلة 6 · 0 = مقفول</div></div>
                        </div>
                    </div>

                    <div class="card" style="margin-bottom:14px">
                        <div class="a2-h"><h3>البلاغات والحماية</h3></div>
                        <label style="display:flex;gap:6px;align-items:center;font-size:13.5px;margin-bottom:10px"><input type="checkbox" name="issues_enabled" value="1" <?= get_setting('issues_enabled', '1') === '1' ? 'checked' : '' ?>> زرار «⚑ بلّغ عن مشكلة» ظاهر للعملاء</label>
                        <div class="a2-grid a2-g3">
                            <div><label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;color:#27324A">بلاغات للعميل في الساعة</label><input class="input" type="number" name="issue_rate_per_hour" min="1" max="50" value="<?= $val('issue_rate_per_hour') ?>"></div>
                            <div><label style="display:block;font-weight:600;font-size:13px;margin-bottom:4px;color:#27324A">طلبات الـ MCP في الدقيقة</label><input class="input" type="number" name="iapi_rate_per_min" min="30" max="2000" value="<?= $val('iapi_rate_per_min') ?>"></div>
                        </div>
                        <p class="a2-muted" style="font-size:12.5px;margin:8px 0 0;line-height:1.8">التقرير اليومي بيوصل في شات Claude (زي ما اتفقنا)، مش على الواتساب — فمفيش رقم محتاج يتحط هنا.</p>
                    </div>
                </div>

                <div class="card">
                    <div class="a2-h"><h3>أدوات Claude</h3><span class="a2-grow"></span><span class="chip <?= $off ? 'chip-amber' : 'chip-mint' ?>"><?= count($cat) - count($off) ?> / <?= count($cat) ?> شغالة</span></div>
                    <p class="a2-muted" style="font-size:12.5px;margin:0 0 10px;line-height:1.8">الأداة المقفولة بيرفضها الموقع نفسه (حتى لو حد طلبها مباشرة)، وخادم الـ MCP بيخفيها من Claude خلال دقيقة. «الحالة» (health) دايمًا شغالة.</p>
                    <?php foreach ($groups as $g => [$gl, $gd]): ?>
                        <div style="margin:10px 0 4px"><b><?= e($gl) ?></b> <span class="a2-muted" style="font-size:12px"><?= e($gd) ?></span></div>
                        <?php foreach ($cat as $r => [$rg, $rl, $tool]): if ($rg !== $g) continue; ?>
                            <label style="display:flex;gap:8px;align-items:center;padding:5px 0;font-size:13.5px;border-bottom:1px solid #F1F4F8">
                                <input type="checkbox" name="routes[]" value="<?= e($r) ?>" <?= in_array($r, $off, true) ? '' : 'checked' ?>>
                                <span style="flex:1"><?= e($rl) ?></span><code dir="ltr" style="font-size:11px;color:#8391A6"><?= e($tool) ?></code>
                            </label>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div style="margin-top:14px"><button class="btn" type="submit">حفظ الإعدادات</button></div>
        </form>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
