<?php
/**
 * تكاليف الـ AI (المرحلة 8-أ) — التكلفة الفعلية مقابل الإيراد · تسعير العمليات · أسعار الموديلات · سعر الدولار
 * كل رقم بيتحفظ وقت العملية بالسعر وسعر الصرف الساريين وقتها — التغيير هنا بيسري على اللي بعده بس.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';

require_admin();
require_admin_can('view_costs');
$ready = usage_ready();
$canEdit = admin_can('ai_settings');
$tab = in_array($_GET['tab'] ?? '', ['overview', 'ops', 'prices', 'fx'], true) ? $_GET['tab'] : 'overview';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    require_csrf();
    decode_b64_fields();
    if (!$canEdit) {
        flash_set('danger', 'التعديل هنا للأدمن الكامل بس');
        redirect('admin/ai-costs.php?tab=' . $tab);
    }
    $action = (string) ($_POST['action'] ?? '');
    $aid = (int) (current_admin()['id'] ?? 0) ?: null;
    $when = function (string $v): ?string {
        $v = trim($v);
        if ($v === '') return date('Y-m-d H:i:s');
        $t = strtotime(str_replace('T', ' ', $v));
        return $t ? date('Y-m-d H:i:s', $t) : null;
    };

    if ($action === 'add_fx') {
        $rate = (float) str_replace(',', '.', (string) ($_POST['rate'] ?? '0'));
        $at = $when((string) ($_POST['effective_from'] ?? ''));
        if ($rate < 1 || $rate > 10000 || !$at) {
            flash_set('danger', 'اكتب سعر صحيح (مثلًا 50) وتاريخ سريان صحيح');
        } elseif (strtotime($at) < time() - 120) {
            flash_set('danger', 'مينفعش سعر بتاريخ فات — التغيير بيسري على اللي جاي بس');
        } else {
            db_insert('INSERT INTO fx_rates (currency, rate_egp, effective_from, note, created_by) VALUES ("USD", ?, ?, ?, ?)',
                [round($rate, 4), $at, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 160) ?: null, $aid]);
            admin_log('fx_rate_add', 'fx_rates', null, json_encode(['rate' => $rate, 'from' => $at]));
            flash_set('success', 'سعر الدولار الجديد ' . a2n($rate, 2) . ' ج.م يسري من ' . $at . ' — العمليات اللي قبله محسوبة بسعرها');
        }
        redirect('admin/ai-costs.php?tab=fx');
    }

    if ($action === 'add_price') {
        $prov = trim((string) ($_POST['provider'] ?? '*')) ?: '*';
        $model = trim((string) ($_POST['model'] ?? ''));
        $unit = in_array($_POST['unit'] ?? '', ['in_1m', 'out_1m', 'image', 'request'], true) ? $_POST['unit'] : '';
        $size = in_array($_POST['size'] ?? '', ['', '1024x1024', '1024x1536', '1536x1024'], true) ? (string) $_POST['size'] : '';
        $price = (float) str_replace(',', '.', (string) ($_POST['price_usd'] ?? '-1'));
        $at = $when((string) ($_POST['effective_from'] ?? ''));
        if (!preg_match('#^[A-Za-z0-9._:/@+-]{1,120}$#', $model) || !preg_match('/^(\*|[a-z0-9_-]{2,40})$/', $prov) || $unit === '' || $price < 0 || $price > 1000 || !$at) {
            flash_set('danger', 'راجع البيانات: الموديل · الوحدة · السعر (0–1000$) · التاريخ');
        } elseif (strtotime($at) < time() - 120) {
            flash_set('danger', 'مينفعش سعر بتاريخ فات — ضيف سعر جديد يسري من دلوقتي');
        } else {
            db_insert('INSERT INTO ai_prices (provider, model, unit, size, price_usd, effective_from, note, created_by) VALUES (?,?,?,?,?,?,?,?)',
                [$prov, $model, $unit, $unit === 'image' ? $size : '', round($price, 6), $at, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 160) ?: null, $aid]);
            admin_log('ai_price_add', 'ai_prices', null, json_encode(compact('prov', 'model', 'unit', 'size', 'price', 'at')));
            flash_set('success', 'اتضاف السعر ✓ — بيسري من ' . $at);
        }
        redirect('admin/ai-costs.php?tab=prices');
    }

    if ($action === 'del_scheduled') {
        $tbl = ($_POST['what'] ?? '') === 'fx' ? 'fx_rates' : 'ai_prices';
        db_run("DELETE FROM $tbl WHERE id = ? AND effective_from > NOW()", [(int) ($_POST['id'] ?? 0)]);
        admin_log('delete_scheduled_' . $tbl, $tbl, (int) ($_POST['id'] ?? 0), null);
        flash_set('success', 'اتلغى السعر المجدول');
        redirect('admin/ai-costs.php?tab=' . ($tbl === 'fx_rates' ? 'fx' : 'prices'));
    }

    if ($action === 'ops_settings') {
        $cv = trim((string) ($_POST['credit_value_egp'] ?? ''));
        set_setting('credit_value_egp', $cv === '' ? '0' : (string) max(0, min(10000, (float) str_replace(',', '.', $cv))));
        set_setting('margin_alert_pct', (string) max(0, min(100, (int) ($_POST['margin_alert_pct'] ?? 40))));
        admin_log('ops_pricing_settings', 'settings', null, null);
        flash_set('success', 'اتحفظ ✓');
        redirect('admin/ai-costs.php?tab=ops');
    }
    redirect('admin/ai-costs.php?tab=' . $tab);
}

$per = a2_period((string) ($_GET['p'] ?? '30d'), $_GET['from'] ?? null, $_GET['to'] ?? null);
$F = $per['from']; $T = $per['to'];
$fx = $ready ? usage_fx_rate() : 50.0;
$taskLabels = [];
foreach (a2_all('SELECT task, label FROM ai_task_routes') as $r) $taskLabels[$r['task']] = $r['label'];
$taskLabels += ['research' => 'البحث العميق (كامل)'];
$costLbl = ['provider' => 'فعلية من المزود', 'calculated' => 'محسوبة', 'estimated' => 'تقديرية', 'mixed' => 'مختلطة', 'none' => '—'];

$active = 'ai-costs';
$page_title = 'تكاليف الـ AI';
include __DIR__ . '/../templates/admin-header.php';
$tabs = ['overview' => 'التكلفة مقابل الإيراد', 'ops' => 'تسعير العمليات', 'prices' => 'أسعار الموديلات', 'fx' => 'سعر الدولار'];
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>

        <div class="page-head with-actions">
            <div>
                <h1>تكاليف الـ AI</h1>
                <div class="sub">AI Costs · اللي بيتصرف فعليًا عند المزودين مقابل اللي بيدخل من الكريدت — سعر الدولار الحالي: <b><?= a2n($fx, 2) ?> ج.م</b></div>
            </div>
            <?php if ($tab === 'overview'): ?>
            <div class="a2-seg">
                <?php foreach (['today' => 'النهارده', '7d' => '7 أيام', '30d' => '30 يوم', 'month' => 'الشهر ده', 'last_month' => 'الشهر اللي فات'] as $k => $l): ?>
                    <a href="?tab=overview&p=<?= $k ?>" class="<?= $per['key'] === $k ? 'on' : '' ?>"><?= e($l) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <nav class="a2-tabs" aria-label="أقسام التكاليف">
            <?php foreach ($tabs as $k => $l): ?><a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($l) ?></a><?php endforeach; ?>
        </nav>

        <?php if (!$ready): ?>
            <div class="alert warning">لازم تشغّل ترحيل «8-أ» الأول من <a href="<?= url('admin/migrations.php') ?>">الترحيلات</a>.</div>
        <?php elseif ($tab === 'overview'):
            $us = usage_summary($F, $T);
            $rev = usage_credit_revenue($F, $T);
            $after = $rev['revenue'] - $us['cost_egp'];
            $byTask = a2_all('SELECT task, COUNT(*) runs, SUM(status = "ok") ok, SUM(failovers > 0) fo, SUM(cost_usd) cu, SUM(cost_egp) ce, SUM(cost_failed_usd) cf,
                    SUM(credits_charged) cr, SUM(revenue_egp) rv, SUM(tokens_in) ti, SUM(tokens_out) tou, SUM(images_count) im, SUM(cost_source IN ("estimated","mixed")) est
                FROM ai_runs WHERE created_at >= ? AND created_at < ? GROUP BY task ORDER BY ce DESC', [$F, $T]);
            $byModel = a2_all('SELECT provider, model, COUNT(*) n, SUM(status = "ok") ok, SUM(cost_usd) cu, SUM(cost_egp) ce, SUM(tokens_in) ti, SUM(tokens_out) tou,
                    SUM(images_count) im, AVG(duration_ms) ms, MAX(cost_source) src
                FROM ai_attempts WHERE created_at >= ? AND created_at < ? GROUP BY provider, model ORDER BY ce DESC LIMIT 30', [$F, $T]);
            $byUser = a2_all('SELECT r.user_id, u.name, u.email, COUNT(*) n, SUM(r.cost_egp) ce, SUM(r.credits_charged) cr, SUM(r.revenue_egp) rv
                FROM ai_runs r JOIN users u ON u.id = r.user_id WHERE r.created_at >= ? AND r.created_at < ? GROUP BY r.user_id ORDER BY ce DESC LIMIT 10', [$F, $T]);
            $byItem = a2_all('SELECT ref_type, ref_id, COUNT(*) n, SUM(cost_egp) ce, SUM(cost_failed_usd) cf, SUM(credits_charged) cr, SUM(revenue_egp) rv, MAX(user_id) uid
                FROM ai_runs WHERE ref_id IS NOT NULL AND created_at >= ? AND created_at < ? GROUP BY ref_type, ref_id ORDER BY ce DESC LIMIT 10', [$F, $T]);
        ?>
            <div class="a2-grid a2-g6" style="margin-bottom:16px">
                <div class="a2-kpi"><small>تكلفة المزودين</small><b><?= a2egp($us['cost_egp']) ?></b><em><?= a2usd($us['cost_usd'], 3) ?></em></div>
                <div class="a2-kpi"><small>منها محاولات فشلت</small><b><?= a2egp($us['cost_failed_usd'] * $fx) ?></b><em>على المنصة — مش على العميل</em></div>
                <div class="a2-kpi"><small>إيراد الكريدت المستهلك</small><b><?= a2egp($rev['revenue']) ?></b><em><?= a2n($rev['consumed']) ?> كريدت · مسترد <?= a2n($rev['refunded']) ?></em></div>
                <div class="a2-kpi"><small>الإيراد بعد تكلفة الـ AI</small><b style="color:<?= $after >= 0 ? '#0B8F83' : '#C2362E' ?>"><?= a2egp($after) ?></b><em><?= $rev['revenue'] > 0 ? round($after / $rev['revenue'] * 100) . '% من الإيراد' : '—' ?></em></div>
                <div class="a2-kpi"><small>العمليات</small><b><?= a2n($us['runs']) ?></b><em>نجاح <?= $us['runs'] ? round($us['ok'] / $us['runs'] * 100) : 0 ?>% · بديل <?= a2n($us['failovers']) ?></em></div>
                <div class="a2-kpi"><small>التوكنز / الصور</small><b><?= a2n(($us['tokens_in'] + $us['tokens_out']) / 1000, 1) ?>K</b><em><?= a2n($us['images']) ?> صورة</em></div>
            </div>
            <div class="a2-note" style="margin-bottom:16px">
                الإيراد = قيمة الكريدت <b>المدفوع</b> اللي اتصرف (الكريدت المجاني/الهدايا/التعويض إيراده صفر ويظهر كتكلفة تسويق). التكلفة = كل محاولات المزودين بما فيها الفاشلة، بسعر الدولار وقت كل عملية.
                <?php if ($us['estimated']): ?><br>⚠ <b><?= (int) $us['estimated'] ?></b> عملية تكلفتها <b>تقديرية</b> لأن الموديل مالوش سعر — <a href="?tab=prices">ضيف أسعارها</a> علشان الأرقام تبقى دقيقة.<?php endif; ?>
            </div>

            <div class="card" style="margin-bottom:16px">
                <div class="a2-h"><h3>حسب المهمة</h3><span class="a2-en"><?= e($per['label']) ?></span></div>
                <?php if (!$byTask): ?><div class="a2-empty">مفيش عمليات في الفترة دي</div><?php else: ?>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>المهمة</th><th>عمليات</th><th>نجاح</th><th>تكلفة (ج.م)</th><th>متوسط العملية الناجحة</th><th>كريدت</th><th>إيراد</th><th>بعد التكلفة</th></tr></thead><tbody>
                <?php foreach ($byTask as $r): $a = $r['rv'] - $r['ce']; ?>
                    <tr>
                        <td><b><?= e($taskLabels[$r['task']] ?? $r['task']) ?></b><?= $r['est'] ? ' <span class="chip chip-amber" title="فيها تكلفة تقديرية">تقديري</span>' : '' ?></td>
                        <td class="num"><?= a2n($r['runs']) ?></td>
                        <td class="num"><?= $r['runs'] ? round($r['ok'] / $r['runs'] * 100) : 0 ?>%<?= $r['fo'] ? ' · بديل ' . (int) $r['fo'] : '' ?></td>
                        <td class="num"><?= a2n($r['ce'], 2) ?></td>
                        <td class="num"><?= $r['ok'] ? a2n($r['ce'] / $r['ok'], 3) : '—' ?></td>
                        <td class="num"><?= a2n($r['cr']) ?></td>
                        <td class="num"><?= a2n($r['rv'], 2) ?></td>
                        <td class="num" style="color:<?= $a >= 0 ? '#0B8F83' : '#C2362E' ?>;font-weight:700"><?= a2n($a, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <p class="a2-muted" style="margin:8px 0 0">«كريدت» و«إيراد» هنا مربوطين بالعملية من نفس الطلب — بعض المزايا بتخصم في طلب وتولّد في طلب تاني (زي البحث العميق) فبتظهر في الإجمالي فوق بس.</p>
                <?php endif; ?>
            </div>

            <div class="a2-grid a2-g2" style="margin-bottom:16px">
                <div class="card">
                    <div class="a2-h"><h3>حسب المزود والموديل</h3></div>
                    <?php if (!$byModel): ?><div class="a2-empty">—</div><?php else: ?>
                    <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الموديل</th><th>محاولات</th><th>نجاح</th><th>توكنز</th><th>تكلفة</th><th>المصدر</th></tr></thead><tbody>
                    <?php foreach ($byModel as $m): ?>
                        <tr><td><b><?= e($m['provider']) ?></b><br><span class="a2-muted" dir="ltr"><?= e($m['model']) ?></span></td>
                            <td class="num"><?= a2n($m['n']) ?></td><td class="num"><?= $m['n'] ? round($m['ok'] / $m['n'] * 100) : 0 ?>%</td>
                            <td class="num"><?= a2n(($m['ti'] + $m['tou']) / 1000, 1) ?>K<?= $m['im'] ? ' · ' . (int) $m['im'] . '🖼' : '' ?></td>
                            <td class="num"><?= a2egp($m['ce']) ?></td><td><span class="a2-muted"><?= e($costLbl[$m['src']] ?? $m['src']) ?></span></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <div class="a2-h"><h3>أعلى العملاء تكلفة</h3></div>
                    <?php if (!$byUser): ?><div class="a2-empty">—</div><?php else: ?>
                    <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>العميل</th><th>عمليات</th><th>تكلفة</th><th>إيراد</th><th>بعد التكلفة</th></tr></thead><tbody>
                    <?php foreach ($byUser as $u): $a = $u['rv'] - $u['ce']; ?>
                        <tr><td><a href="<?= url('admin/user-view.php?id=' . (int) $u['user_id']) ?>"><?= e($u['name']) ?></a><br><span class="a2-muted"><?= e($u['email']) ?></span></td>
                            <td class="num"><?= a2n($u['n']) ?></td><td class="num"><?= a2n($u['ce'], 2) ?></td><td class="num"><?= a2n($u['rv'], 2) ?></td>
                            <td class="num" style="color:<?= $a >= 0 ? '#0B8F83' : '#C2362E' ?>;font-weight:700"><?= a2n($a, 2) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="a2-h"><h3>أغلى العناصر (منشور · تصميم · بحث)</h3><span class="a2-en">Cost per item</span></div>
                <?php if (!$byItem): ?><div class="a2-empty">—</div><?php else: ?>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>العنصر</th><th>عمليات</th><th>تكلفة المنصة</th><th>منها فاشل</th><th>كريدت العميل</th><th>إيراد</th><th></th></tr></thead><tbody>
                <?php foreach ($byItem as $it):
                    $link = in_array($it['ref_type'], ['contents', 'content'], true) ? 'admin/content-view.php?id=' . (int) $it['ref_id'] : null; ?>
                    <tr><td><b><?= e(['contents' => 'منشور', 'content' => 'منشور', 'research' => 'بحث', 'campaign' => 'حملة', 'studio_designs' => 'تصميم استوديو', 'brand_profiles' => 'Brand Brain'][$it['ref_type']] ?? $it['ref_type']) ?> #<?= (int) $it['ref_id'] ?></b></td>
                        <td class="num"><?= a2n($it['n']) ?></td><td class="num"><?= a2egp($it['ce']) ?></td><td class="num"><?= a2egp($it['cf'] * $fx) ?></td>
                        <td class="num"><?= a2n($it['cr']) ?></td><td class="num"><?= a2n($it['rv'], 2) ?></td>
                        <td><a href="<?= url('admin/ai-runs.php?ref_type=' . urlencode($it['ref_type']) . '&ref_id=' . (int) $it['ref_id']) ?>">المحاولات</a><?= $link ? ' · <a href="' . e(url($link)) . '">فتح</a>' : '' ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </div>

        <?php elseif ($tab === 'ops'):
            [$cv, $cvSrc] = a2_credit_value();
            $alertPct = (int) get_setting('margin_alert_pct', 40);
        ?>
            <form method="post" class="card" style="margin-bottom:16px">
                <?= csrf_field() ?><input type="hidden" name="action" value="ops_settings">
                <div class="a2-grid a2-g3" style="align-items:end">
                    <div>
                        <label class="a2-lbl">قيمة الـ Credit الواحد (ج.م) — لحساب الهامش</label>
                        <input class="input" type="number" step="0.01" min="0" name="credit_value_egp" value="<?= (float) get_setting('credit_value_egp', '0') > 0 ? e((string) get_setting('credit_value_egp')) : '' ?>" placeholder="تلقائي: <?= a2n($cv, 2) ?>" style="width:100%" <?= $canEdit ? '' : 'disabled' ?>>
                        <div class="a2-muted" style="margin-top:4px">المستخدم دلوقتي: <b><?= a2n($cv, 2) ?> ج.م</b> — <?= e(['manual' => 'يدوي', 'paid_90d' => 'متوسط المدفوع فعلًا آخر 90 يوم', 'packages' => 'متوسط أسعار الباقات', 'none' => 'مفيش بيانات'][$cvSrc]) ?></div>
                    </div>
                    <div>
                        <label class="a2-lbl">نبّهني لو الهامش أقل من (%)</label>
                        <input class="input" type="number" min="0" max="100" name="margin_alert_pct" value="<?= $alertPct ?>" style="width:100%" <?= $canEdit ? '' : 'disabled' ?>>
                    </div>
                    <div><?php if ($canEdit): ?><button class="btn" type="submit">حفظ</button><?php endif; ?></div>
                </div>
            </form>
            <div class="card">
                <div class="a2-h"><h3>كل عملية: بتتسعّر بكام وبتتكلف كام</h3><span class="a2-en">متوسط آخر 30 يوم · شامل المحاولات الفاشلة</span><span class="a2-grow"></span><a class="btn sm soft" href="<?= url('admin/pricing.php') ?>">تعديل الكريدت ←</a></div>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>العملية</th><th>Credits للعملية</th><th>إيراد العملية</th><th>تكلفة المزود (ج.م)</th><th>هامش الربح</th><th>العينة</th></tr></thead><tbody>
                <?php foreach (a2_op_catalog() as $key => [$lbl, $task, $def]):
                    $credits = (int) get_setting($key, (string) $def);
                    $c = a2_task_avg_cost($task);
                    $costE = $c['avg_usd'] * $fx;
                    $revE = $credits * $cv;
                    $mE = $revE - $costE;
                    $mPct = $revE > 0 ? round($mE / $revE * 100) : null;
                    $bad = $c['n'] > 0 && ($mPct === null ? $costE > 0 : $mPct < $alertPct);
                ?>
                    <tr>
                        <td><b><?= e($lbl) ?></b></td>
                        <td class="num"><?= $credits ?></td>
                        <td class="num"><?= a2n($revE, 2) ?></td>
                        <td class="num"><?= $c['n'] ? a2n($costE, 3) : '<span class="a2-muted">لسه مفيش</span>' ?></td>
                        <td class="num" style="font-weight:700;color:<?= !$c['n'] ? '#8391A6' : ($bad ? '#C2362E' : '#0B8F83') ?>">
                            <?= $c['n'] ? a2n($mE, 2) . ($mPct !== null ? ' (' . $mPct . '%)' : '') : '—' ?><?= $bad ? ' ⚠' : '' ?></td>
                        <td class="a2-muted"><?= $c['n'] ? a2n($c['n']) . ' عملية' : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <p class="a2-muted" style="margin:10px 0 0">العمليات اللي Credits بتاعتها 0 (مجانية للعميل) بتظهر تكلفتها بس. الكاروسيل = تصميم لكل شريحة، فبيتحسب بسعر «توليد تصميم» × عدد الشرائح.</p>
            </div>

        <?php elseif ($tab === 'prices'):
            $current = a2_all('SELECT p.* FROM ai_prices p
                WHERE p.effective_from <= NOW() AND NOT EXISTS (SELECT 1 FROM ai_prices q WHERE q.provider = p.provider AND q.model = p.model AND q.unit = p.unit AND q.size = p.size
                    AND q.effective_from <= NOW() AND (q.effective_from > p.effective_from OR (q.effective_from = p.effective_from AND q.id > p.id)))
                ORDER BY p.model, p.unit, p.size');
            $scheduled = a2_all('SELECT * FROM ai_prices WHERE effective_from > NOW() ORDER BY effective_from');
            $missing = a2_all('SELECT provider, model, MAX(capability) cap, COUNT(*) n FROM ai_attempts WHERE cost_source = "estimated" AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY provider, model ORDER BY n DESC LIMIT 12');
            $history = isset($_GET['all']) ? a2_all('SELECT * FROM ai_prices ORDER BY id DESC LIMIT 200') : [];
            $unitLbl = ['in_1m' => 'Input / مليون توكن', 'out_1m' => 'Output / مليون توكن', 'image' => 'لكل صورة', 'request' => 'لكل طلب (بحث)'];
        ?>
            <?php if ($missing): ?>
            <div class="a2-alerts" style="margin-bottom:16px">
                <h3>موديلات اتستخدمت ومالهاش سعر (التكلفة تقديرية)</h3>
                <div class="a2-grid a2-g3">
                    <?php foreach ($missing as $m): ?>
                        <a href="?tab=prices&amp;pm=<?= urlencode($m['model']) ?>&amp;pu=<?= $m['cap'] === 'image' ? 'image' : 'in_1m' ?>#addp"><span><?= e($m['provider']) ?> · <b dir="ltr"><?= e($m['model']) ?></b> (<?= (int) $m['n'] ?>)</span>+ سعر</a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <div class="a2-grid a2-side">
                <div class="card">
                    <div class="a2-h"><h3>الأسعار السارية</h3><span class="a2-en">USD</span><span class="a2-grow"></span><a class="a2-muted" href="?tab=prices&amp;all=1">كل السجل</a></div>
                    <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الموديل</th><th>المزود</th><th>الوحدة</th><th>السعر</th><th>من</th></tr></thead><tbody>
                    <?php foreach ($current as $p): ?>
                        <tr><td dir="ltr" style="text-align:start"><b><?= e($p['model']) ?></b></td><td><?= $p['provider'] === '*' ? 'أي مزود' : e($p['provider']) ?></td>
                            <td><?= e($unitLbl[$p['unit']] ?? $p['unit']) ?><?= $p['size'] ? ' · ' . e($p['size']) : '' ?></td>
                            <td class="num">$<?= rtrim(rtrim(number_format((float) $p['price_usd'], 6, '.', ''), '0'), '.') ?></td>
                            <td class="a2-muted"><?= e(date('Y-m-d', strtotime($p['effective_from']))) ?><?= $p['note'] ? '<br>' . e($p['note']) : '' ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <?php if ($scheduled): ?>
                        <h4 style="margin:16px 0 8px">مجدولة (لسه ماسرتش)</h4>
                        <?php foreach ($scheduled as $s): ?>
                            <div class="a2-kv"><span dir="ltr"><?= e($s['model'] . ' · ' . $s['unit'] . ' · $' . $s['price_usd']) ?></span>
                                <span class="a2-row"><small class="a2-muted">من <?= e($s['effective_from']) ?></small>
                                <?php if ($canEdit): ?><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="del_scheduled"><input type="hidden" name="what" value="price"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="btn sm danger" type="submit">إلغاء</button></form><?php endif; ?></span></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ($history): ?>
                        <h4 style="margin:16px 0 8px">السجل الكامل</h4>
                        <div class="a2-tw"><table class="a2-tbl"><tbody>
                        <?php foreach ($history as $h): ?><tr><td dir="ltr" style="text-align:start"><?= e($h['provider'] . ' / ' . $h['model']) ?></td><td><?= e($h['unit'] . ($h['size'] ? ' ' . $h['size'] : '')) ?></td><td class="num">$<?= e((string) (float) $h['price_usd']) ?></td><td class="a2-muted"><?= e($h['effective_from']) ?></td></tr><?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </div>
                <form method="post" class="card" id="addp" data-safe-post>
                    <?= csrf_field() ?><input type="hidden" name="action" value="add_price">
                    <div class="a2-h"><h3>سعر جديد</h3></div>
                    <p class="a2-muted" style="margin-top:0">الأسعار مابتتعدّلش — بتضيف سعر جديد بتاريخ سريان، والعمليات القديمة بتفضل محسوبة بسعرها.</p>
                    <div class="a2-grid" style="gap:10px">
                        <div><label class="a2-lbl">الموديل</label><input class="input" type="text" name="model" required value="<?= e((string) ($_GET['pm'] ?? '')) ?>" placeholder="gpt-4o-mini" dir="ltr" data-no-encode="1" style="width:100%"></div>
                        <div><label class="a2-lbl">المزود</label>
                            <select class="input" name="provider" style="width:100%"><option value="*">أي مزود بنفس اسم الموديل</option>
                                <?php foreach (a2_all('SELECT provider_name FROM ai_providers ORDER BY priority') as $pp): ?><option value="<?= e($pp['provider_name']) ?>"><?= e($pp['provider_name']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div><label class="a2-lbl">الوحدة</label>
                            <select class="input" name="unit" style="width:100%">
                                <?php foreach ($unitLbl as $k => $l): ?><option value="<?= $k ?>" <?= ($_GET['pu'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                            </select></div>
                        <div><label class="a2-lbl">المقاس (للصور)</label>
                            <select class="input" name="size" style="width:100%"><option value="">أي مقاس</option><option>1024x1024</option><option>1024x1536</option><option>1536x1024</option></select></div>
                        <div><label class="a2-lbl">السعر ($)</label><input class="input" type="number" name="price_usd" min="0" max="1000" step="0.000001" required style="width:100%"></div>
                        <div><label class="a2-lbl">يسري من</label><input class="input" type="datetime-local" name="effective_from" style="width:100%"><div class="a2-muted">فاضي = من دلوقتي</div></div>
                        <div><label class="a2-lbl">ملاحظة</label><input class="input" type="text" name="note" maxlength="160" style="width:100%"></div>
                        <div><button class="btn" type="submit" <?= $canEdit ? '' : 'disabled' ?>>إضافة السعر</button></div>
                    </div>
                </form>
            </div>

        <?php else: /* fx */
            $rates = a2_all('SELECT r.*, a.name admin_name FROM fx_rates r LEFT JOIN admin_users a ON a.id = r.created_by WHERE currency = "USD" ORDER BY effective_from DESC, id DESC LIMIT 50');
        ?>
            <div class="a2-grid a2-side">
                <div class="card">
                    <div class="a2-h"><h3>سجل سعر الدولار</h3><span class="a2-en">USD → EGP</span></div>
                    <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>السعر</th><th>يسري من</th><th>الحالة</th><th>بواسطة</th><th></th></tr></thead><tbody>
                    <?php $shownCurrent = false; foreach ($rates as $r):
                        $future = strtotime($r['effective_from']) > time();
                        $isCur = !$future && !$shownCurrent; if ($isCur) $shownCurrent = true; ?>
                        <tr><td class="num"><b><?= a2n($r['rate_egp'], 2) ?></b> ج.م</td><td class="a2-muted"><?= e($r['effective_from']) ?></td>
                            <td><?= $future ? '<span class="chip chip-amber">مجدول</span>' : ($isCur ? '<span class="chip chip-mint">ساري</span>' : '<span class="chip chip-line">قديم</span>') ?></td>
                            <td class="a2-muted"><?= e($r['admin_name'] ?? '—') ?><?= $r['note'] ? ' · ' . e($r['note']) : '' ?></td>
                            <td><?php if ($future && $canEdit): ?><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="del_scheduled"><input type="hidden" name="what" value="fx"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn sm danger" type="submit">إلغاء</button></form><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                </div>
                <form method="post" class="card" data-safe-post>
                    <?= csrf_field() ?><input type="hidden" name="action" value="add_fx">
                    <div class="a2-h"><h3>تغيير سعر الدولار</h3></div>
                    <p class="a2-muted" style="margin-top:0">السعر الجديد بيسري على العمليات اللي بعده بس — كل عملية قديمة محفوظ معاها سعر الدولار وقتها.</p>
                    <div class="a2-grid" style="gap:10px">
                        <div><label class="a2-lbl">سعر الدولار (ج.م)</label><input class="input" type="number" name="rate" min="1" max="10000" step="0.01" required placeholder="<?= a2n($fx, 2) ?>" style="width:100%"></div>
                        <div><label class="a2-lbl">يسري من</label><input class="input" type="datetime-local" name="effective_from" style="width:100%"><div class="a2-muted">فاضي = من دلوقتي</div></div>
                        <div><label class="a2-lbl">ملاحظة</label><input class="input" type="text" name="note" maxlength="160" placeholder="مثلًا: سعر البنك" style="width:100%"></div>
                        <div><button class="btn" type="submit" <?= $canEdit ? '' : 'disabled' ?>>حفظ السعر</button></div>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
