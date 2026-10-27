<?php
/**
 * Spread AI — الأدمن: محرك المحتوى v3 (المرحلة D)
 * تبويبات: الإعدادات (قاعدة الكريدت + التقدير) · العملاء (مين يشوف المحرك) · الشغل (الطابور + حجز الكريدت) · الحملات (بالتكلفة بالدولار)
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/ce-plan.php';
require_once __DIR__ . '/../includes/usage.php';

require_admin();
require_admin_can('ai_settings');
$admin = current_admin();
$ready = ce_ready();
$tab = in_array($_GET['tab'] ?? '', ['settings', 'plan', 'access', 'jobs', 'campaigns'], true) ? $_GET['tab'] : 'settings';
$FKEY = 'content_engine';

/** [المفتاح، الافتراضي، أقل، أكبر، الوصف] */
$nums = [
    'ce_margin_pct' => [100, 0, 1000, 'هامش الكريدت فوق التكلفة الفعلية (%)'],
    'ce_egp_per_credit' => [1.5, 0.05, 100, 'الكريدت الواحد = كام جنيه'],
    'ce_min_credits_piece' => [1, 0, 50, 'أقل كريدت لكل قطعة محتوى'],
    'ce_revision_factor' => [1.4, 1, 3, 'معامل إعادة الكتابة (الكاتب والناقد)'],
    'ce_dna_cost' => [0, 0, 100, 'بناء الـ Brand DNA (كريدت) — 0 = مجاني'],
    'ce_dna_max_tokens' => [1200, 400, 4000, 'أقصى حجم للـ Brand DNA (توكن)'],
    'ce_max_pieces' => [60, 1, 200, 'أقصى عدد قطع في حملة واحدة'],
    'ce_fallback_in_1m' => [3.0, 0, 200, 'سعر احتياطي — دخل لكل مليون توكن ($)'],
    'ce_fallback_out_1m' => [15.0, 0, 400, 'سعر احتياطي — خرج لكل مليون توكن ($)'],
    'ce_fallback_image' => [0.04, 0, 5, 'سعر احتياطي — الصورة الواحدة ($)'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    require_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'save_settings') {
        $errs = [];
        $changed = [];
        foreach ($nums as $k => [$def, $min, $max, $label]) {
            $v = str_replace(',', '.', trim((string) ($_POST[$k] ?? '')));
            if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) { $errs[] = "$label: من $min لـ $max"; continue; }
            $v = (string) (0 + $v);
            if ((string) get_setting($k, '') !== $v) { set_setting($k, $v); $changed[] = $k; }
        }
        $td = [];
        foreach (CE_TOKEN_DEFAULTS as $k => $_) {
            $td[$k] = [max(0, min(200000, (int) ($_POST['td_in'][$k] ?? 0))), max(0, min(100000, (int) ($_POST['td_out'][$k] ?? 0)))];
        }
        $tdj = json_encode($td);
        if ((string) get_setting('ce_token_defaults', '') !== $tdj) { set_setting('ce_token_defaults', $tdj); $changed[] = 'ce_token_defaults'; }
        if ($changed) admin_log('ce_settings', 'settings', null, implode(',', $changed));
        foreach ($errs as $e) flash_set('danger', $e);
        flash_set('success', $changed ? 'اتحفظ ✓' : 'مفيش تغيير');
        redirect('admin/ce-settings.php?tab=settings');
    }
    if ($a === 'save_plan') {
        $errs = [];
        $mix = [];
        foreach (array_keys(CE_FUNNEL_MIX) as $g) {
            $row = [(int) ($_POST['mix'][$g]['tofu'] ?? 0), (int) ($_POST['mix'][$g]['mofu'] ?? 0), (int) ($_POST['mix'][$g]['bofu'] ?? 0)];
            if (array_sum($row) !== 100 || min($row) < 0) { $errs[] = 'نسب «' . (CE_GOALS[$g][0] ?? $g) . '» لازم مجموعها 100'; continue; }
            $mix[$g] = $row;
        }
        if (!$errs) set_setting('ce_funnel_mix', json_encode($mix));
        $w = [];
        foreach (array_keys(CE_PLAN_WEIGHTS) as $d) $w[$d] = max(0, (int) ($_POST['w'][$d] ?? 0));
        if (array_sum($w) !== 100) $errs[] = 'أوزان المعايير لازم مجموعها 100';
        else set_setting('ce_plan_weights', json_encode($w));
        $th = (int) ($_POST['ce_plan_threshold'] ?? 80);
        $mr = (int) ($_POST['ce_plan_max_rounds'] ?? 2);
        if ($th < 50 || $th > 100) $errs[] = 'حد النجاح من 50 لـ 100'; else set_setting('ce_plan_threshold', (string) $th);
        if ($mr < 0 || $mr > 4) $errs[] = 'جولات التحسين من 0 لـ 4'; else set_setting('ce_plan_max_rounds', (string) $mr);
        $pm = (int) ($_POST['ce_plan_min_credits'] ?? 1);
        set_setting('ce_plan_min_credits', (string) max(0, min(50, $pm)));
        admin_log('ce_plan_settings', 'settings', null, json_encode(['th' => $th, 'rounds' => $mr]));
        foreach ($errs as $e) flash_set('danger', $e);
        if (!$errs) flash_set('success', 'اتحفظ ✓');
        redirect('admin/ce-settings.php?tab=plan');
    }
    if ($a === 'save_angle') {
        $id = (int) ($_POST['id'] ?? 0);
        $key = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string) ($_POST['akey'] ?? '')));
        $label = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 120);
        $pill = implode(',', array_values(array_intersect(array_keys(CE_PILLARS), (array) ($_POST['pillars'] ?? []))));
        $vals = [$label, mb_substr(trim((string) ($_POST['hint'] ?? '')), 0, 255) ?: null, mb_substr(trim((string) ($_POST['safeguard'] ?? '')), 0, 255) ?: null,
                 $pill ?: null, !empty($_POST['is_active']) ? 1 : 0, (int) ($_POST['sort_order'] ?? 0)];
        if ($label === '') { flash_set('danger', 'اكتب اسم الزاوية'); redirect('admin/ce-settings.php?tab=plan'); }
        if ($id) {
            db_run('UPDATE ce_angles SET label = ?, hint = ?, safeguard = ?, pillars = ?, is_active = ?, sort_order = ? WHERE id = ?', array_merge($vals, [$id]));
        } else {
            if ($key === '' || db_one('SELECT id FROM ce_angles WHERE akey = ?', [$key])) { flash_set('danger', 'المفتاح لازم يبقى إنجليزي ومش مكرر'); redirect('admin/ce-settings.php?tab=plan'); }
            db_insert('INSERT INTO ce_angles (label, hint, safeguard, pillars, is_active, sort_order, akey) VALUES (?,?,?,?,?,?,?)', array_merge($vals, [$key]));
        }
        admin_log('ce_angle_save', 'ce_angles', $id ?: null, $key ?: $label);
        flash_set('success', 'الزاوية اتحفظت ✓');
        redirect('admin/ce-settings.php?tab=plan');
    }
    if ($a === 'set_mode') {
        $mode = in_array($_POST['mode'] ?? '', ['off', 'on', 'allowlist'], true) ? $_POST['mode'] : 'allowlist';
        db_run('UPDATE feature_flags SET default_mode = ? WHERE feature_key = ?', [$mode, $FKEY]);
        admin_log('feature_mode', 'feature', null, "$FKEY=$mode");
        flash_set('success', 'اتغيّر — التأثير فوري');
        redirect('admin/ce-settings.php?tab=access');
    }
    if ($a === 'toggle_user') {
        $uid = (int) $_POST['uid'];
        $row = db_one('SELECT * FROM user_feature_access WHERE user_id = ? AND feature_key = ?', [$uid, $FKEY]);
        if ($row) db_run('UPDATE user_feature_access SET is_enabled = 1 - is_enabled, granted_by = ?, granted_at = NOW() WHERE id = ?', [$admin['id'], $row['id']]);
        else db_insert('INSERT INTO user_feature_access (user_id, feature_key, is_enabled, granted_by) VALUES (?, ?, 1, ?)', [$uid, $FKEY, $admin['id']]);
        admin_log('feature_toggle', 'user', $uid, $FKEY . '=' . ($row && $row['is_enabled'] ? 'off' : 'on'));
        flash_set('success', $row && $row['is_enabled'] ? 'اتقفل للعميل ده' : 'اتفتح للعميل ده ✓');
        redirect('admin/ce-settings.php?tab=access' . (!empty($_POST['q']) ? '&q=' . urlencode((string) $_POST['q']) : ''));
    }
    if ($a === 'cancel_job') {
        $j = ce_job_get((int) $_POST['id']);
        if ($j && ce_job_cancel((int) $j['id'], (int) $j['user_id'])) {
            admin_log('ce_job_cancel', 'ce_jobs', (int) $j['id'], $j['kind']);
            flash_set('success', 'الشغلانة اتلغت والكريدت المحجوز رجع للعميل');
        } else {
            flash_set('warning', 'مينفعش تتلغي (خلصت أو اتلغت قبل كده)');
        }
        redirect('admin/ce-settings.php?tab=jobs');
    }
    if ($a === 'retry_job') {
        $st = db()->prepare('UPDATE ce_jobs SET status = "queued", attempts = 0, error = NULL, run_after = NULL, lock_token = NULL, finished_at = NULL, stage_label = "في الطابور…"
                             WHERE id = ? AND status = "failed" AND hold_id IS NULL');
        $st->execute([(int) $_POST['id']]);
        if ($st->rowCount()) { admin_log('ce_job_retry', 'ce_jobs', (int) $_POST['id'], ''); flash_set('success', 'رجعت للطابور'); }
        else flash_set('warning', 'الإعادة متاحة بس للشغل الفاشل اللي مالوش حجز كريدت (اللي ليه حجز: العميل يبدأه من جديد)');
        redirect('admin/ce-settings.php?tab=jobs');
    }
}

$page_title = 'محرك المحتوى';
$active = 'ce-settings';
include __DIR__ . '/../templates/admin-header.php';
$secret = (string) get_setting('cron_secret', '');
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head">
            <h1>محرك المحتوى ✨</h1>
            <div class="sub">Brand DNA · الحملة بالاختيارات · التقدير وحجز الكريدت · طابور الشغل</div>
        </div>

        <?php if (!$ready): ?>
            <div class="alert warning">جداول المحرك لسه ماتعملتش — شغّل ترحيل «2026_ce_engine» من <a href="<?= e(url('admin/migrations.php')) ?>">الترحيلات</a>.</div>
        <?php else: ?>

        <div class="a2-seg" style="margin-bottom:14px">
            <?php foreach (['settings' => 'الإعدادات', 'plan' => 'الخطة والزوايا', 'access' => 'العملاء', 'jobs' => 'الشغل والكريدت', 'campaigns' => 'الحملات'] as $k => $l): ?>
                <a class="<?= $tab === $k ? 'on' : '' ?>" href="?tab=<?= $k ?>"><?= $l ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($tab === 'settings'): ?>
            <div class="card" style="margin-bottom:14px">
                <div class="a2-h"><h3>الـ worker (cron)</h3>
                    <span class="chip <?= ce_worker_alive() ? 'chip-mint' : 'chip-amber' ?>"><?= ce_worker_alive() ? 'شغال' : 'مش شغال' ?></span></div>
                <p class="a2-muted" style="line-height:1.9;margin:0">آخر دورة: <?= e((string) get_setting('ce_worker_last_run', '—')) ?>. من غيره الشغل بيكمّل برضه طول ما العميل فاتح الصفحة، بس أبطأ.<br>
                    cPanel ← Cron Jobs ← كل دقيقة (<code>* * * * *</code>):</p>
                <code dir="ltr" style="display:block;margin-top:6px;word-break:break-all;user-select:all">/usr/local/bin/php <?= e(dirname(__DIR__)) ?>/cron/ce-worker.php cron_secret=<?= $secret !== '' ? 'YOUR_CRON_SECRET' : '(حط cron_secret من التكاملات الأول)' ?></code>
            </div>

            <form method="post" class="card">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
                <div class="a2-h"><h3>قاعدة الكريدت</h3></div>
                <p class="a2-muted" style="margin:0 0 10px;line-height:1.9">الكريدت = التكلفة بالدولار × سعر الدولار (<?= e((string) (function_exists('usage_fx_rate') ? usage_fx_rate() : '')) ?> جنيه) × (1 + الهامش%) ÷ قيمة الكريدت بالجنيه — ومش أقل من «أقل كريدت لكل قطعة» × عدد القطع.
                    الكريدت بيتحجز قبل التشغيل، والفرق بيرجع للعميل بعده. مفيش أبدًا خصم أكتر من المحجوز.</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px">
                    <?php foreach ($nums as $k => [$def, $min, $max, $label]): ?>
                        <label class="form-group" style="margin:0"><span style="font-size:13px"><?= e($label) ?></span>
                            <input class="form-control" name="<?= $k ?>" inputmode="decimal" value="<?= e((string) get_setting($k, (string) $def)) ?>"></label>
                    <?php endforeach; ?>
                </div>

                <div class="a2-h" style="margin-top:18px"><h3>التوكنز الافتراضية لكل مرحلة</h3></div>
                <p class="a2-muted" style="margin:0 0 8px">بتُستخدم لحد ما يبقى فيه 5 تشغيلات ناجحة للمرحلة — بعدها التقدير بياخد متوسط آخر 50 تشغيل.</p>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>المرحلة</th><th>دخل</th><th>خرج</th><th>من السجل</th><th>الموديل · السعر</th></tr></thead><tbody>
                    <?php foreach (ce_token_defaults() as $k => [$i, $o]): $h = ce_stage_history($k); $pr = ce_stage_price($k); ?>
                        <tr><td><?= e(CE_STAGE_LABELS[$k] ?? $k) ?> <small class="a2-muted" dir="ltr"><?= e($k) ?></small></td>
                            <td><input class="form-control" style="width:110px" name="td_in[<?= $k ?>]" value="<?= $i ?>" inputmode="numeric"></td>
                            <td><input class="form-control" style="width:110px" name="td_out[<?= $k ?>]" value="<?= $o ?>" inputmode="numeric"></td>
                            <td class="num"><?= $h ? number_format($h['in']) . ' / ' . number_format($h['out']) . ' <small class="a2-muted">(' . $h['n'] . ')</small>' : '—' ?></td>
                            <td dir="ltr" style="text-align:start;font-size:12px"><?= e((string) ($pr['model'] ?? '—')) ?> · $<?= e((string) $pr['in_1m']) ?>/$<?= e((string) $pr['out_1m']) ?><?= $pr['priced'] ? '' : ' <b style="color:#d9534f">(احتياطي)</b>' ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <button class="btn" style="margin-top:12px">حفظ</button>
            </form>

        <?php elseif ($tab === 'plan'):
            $mixAll = []; foreach (array_keys(CE_FUNNEL_MIX) as $g) $mixAll[$g] = ce_funnel_mix($g);
            $w = ce_plan_weights(); $angles = ce_angles(false); ?>
            <form method="post" class="card" style="margin-bottom:14px">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_plan">
                <div class="a2-h"><h3>نسب المراحل لكل هدف (%)</h3></div>
                <p class="a2-muted" style="margin:0 0 8px">وعي (TOFU) · اهتمام (MOFU) · قرار (BOFU) — مجموع كل صف 100. الخطة بتتحاسب على البعد عن النسب دي (±5% مسموح).</p>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الهدف</th><th>وعي</th><th>اهتمام</th><th>قرار</th></tr></thead><tbody>
                    <?php foreach ($mixAll as $g => $m): ?>
                        <tr><td><?= e(CE_GOALS[$g][0] ?? $g) ?></td>
                            <?php foreach (['tofu', 'mofu', 'bofu'] as $f): ?><td><input class="form-control" style="width:80px" name="mix[<?= $g ?>][<?= $f ?>]" value="<?= (int) $m[$f] ?>" inputmode="numeric"></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <div class="a2-h" style="margin-top:16px"><h3>بوابة الخطة</h3></div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px">
                    <?php foreach (CE_PLAN_DIMS as $d => $l): ?>
                        <label class="form-group" style="margin:0"><span style="font-size:13px">وزن «<?= e($l) ?>»</span><input class="form-control" name="w[<?= $d ?>]" value="<?= (int) $w[$d] ?>" inputmode="numeric"></label>
                    <?php endforeach; ?>
                    <label class="form-group" style="margin:0"><span style="font-size:13px">حد النجاح</span><input class="form-control" name="ce_plan_threshold" value="<?= e((string) get_setting('ce_plan_threshold', '80')) ?>" inputmode="numeric"></label>
                    <label class="form-group" style="margin:0"><span style="font-size:13px">جولات التحسين لو أقل</span><input class="form-control" name="ce_plan_max_rounds" value="<?= e((string) get_setting('ce_plan_max_rounds', '2')) ?>" inputmode="numeric"></label>
                    <label class="form-group" style="margin:0"><span style="font-size:13px">أقل كريدت للخطة</span><input class="form-control" name="ce_plan_min_credits" value="<?= e((string) get_setting('ce_plan_min_credits', '1')) ?>" inputmode="numeric"></label>
                </div>
                <p class="a2-muted" style="margin:8px 0 0">مجموع الأوزان 100. الدرجة النهائية لكل معيار = الأقل بين قياس الكود ودرجة الناقد. القواعد الثابتة (التكرار ورا بعض · التغطية · مكان الـ CTA القوي · عدد الأشكال) لازم تبقى سليمة كمان.</p>
                <button class="btn" style="margin-top:12px">حفظ</button>
            </form>

            <div class="card">
                <div class="a2-h"><h3>مكتبة الزوايا</h3></div>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الزاوية</th><th>المعنى · القيد</th><th>الأعمدة</th><th>شغالة</th><th></th></tr></thead><tbody>
                    <?php foreach ($angles as $a): ?>
                        <tr><td colspan="5" style="padding:0"><form method="post" style="display:grid;grid-template-columns:1.2fr 2fr 1.6fr 60px 80px;gap:8px;padding:8px;align-items:start"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_angle"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <div><input class="form-control" name="label" value="<?= e($a['label']) ?>"><small class="a2-muted" dir="ltr"><?= e($a['akey']) ?></small></div>
                            <div><input class="form-control" name="hint" value="<?= e((string) $a['hint']) ?>" placeholder="المعنى"><input class="form-control" style="margin-top:4px" name="safeguard" value="<?= e((string) $a['safeguard']) ?>" placeholder="قيد (اختياري)"></div>
                            <div style="font-size:12.5px"><?php foreach (CE_PILLARS as $pk => $pl): ?><label style="display:inline-flex;gap:3px;margin:0 6px 4px 0"><input type="checkbox" name="pillars[]" value="<?= $pk ?>" <?= in_array($pk, $a['pillar_list'], true) ? 'checked' : '' ?>><?= e($pl) ?></label><?php endforeach; ?></div>
                            <label><input type="checkbox" name="is_active" value="1" <?= $a['is_active'] ? 'checked' : '' ?>></label>
                            <div><input type="hidden" name="sort_order" value="<?= (int) $a['sort_order'] ?>"><button class="btn sm ghost">حفظ</button></div>
                        </form></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <form method="post" style="display:grid;grid-template-columns:1fr 1.2fr 2fr auto;gap:8px;margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="save_angle"><input type="hidden" name="is_active" value="1"><input type="hidden" name="sort_order" value="<?= count($angles) + 1 ?>">
                    <input class="form-control" name="akey" dir="ltr" placeholder="key_in_english"><input class="form-control" name="label" placeholder="اسم الزاوية"><input class="form-control" name="hint" placeholder="المعنى"><button class="btn">+ إضافة</button></form>
                <p class="a2-muted" style="margin:8px 0 0">الأعمدة الفاضية = الزاوية تنفع مع أي عمود. الزاوية المقفولة مابتظهرش في الخطط الجديدة.</p>
            </div>

        <?php elseif ($tab === 'access'):
            $flag = db_one('SELECT * FROM feature_flags WHERE feature_key = ?', [$FKEY]);
            $mode = $flag['default_mode'] ?? 'allowlist';
            $q = trim((string) ($_GET['q'] ?? ''));
            $users = db_all('SELECT u.id, u.name, u.email, ufa.is_enabled FROM users u
                             LEFT JOIN user_feature_access ufa ON ufa.user_id = u.id AND ufa.feature_key = ?
                             WHERE (? = "" OR u.name LIKE ? OR u.email LIKE ?) ORDER BY (ufa.is_enabled = 1) DESC, u.id DESC LIMIT 50',
                [$FKEY, $q, "%$q%", "%$q%"]); ?>
            <div class="card" style="margin-bottom:14px">
                <div class="a2-h"><h3>مين يشوف المحرك الجديد؟</h3></div>
                <form method="post" style="display:flex;gap:8px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="set_mode">
                    <?php foreach (['off' => 'مقفول للكل', 'allowlist' => 'عملاء معيّنين بس', 'on' => 'مفتوح للكل'] as $k => $l): ?>
                        <button class="btn <?= $mode === $k ? '' : 'ghost' ?>" name="mode" value="<?= $k ?>"><?= $l ?></button>
                    <?php endforeach; ?>
                </form>
                <p class="a2-muted" style="margin:10px 0 0">الحملة القديمة شغالة زي ما هي في كل الأحوال. «مفتوح للكل» = الكل إلا اللي قفلته يدوي.</p>
            </div>
            <div class="card">
                <form method="get" style="display:flex;gap:8px;margin-bottom:10px"><input type="hidden" name="tab" value="access">
                    <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="اسم أو إيميل"><button class="btn ghost">بحث</button></form>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>العميل</th><th>الحالة</th><th></th></tr></thead><tbody>
                    <?php foreach ($users as $u): $on = $mode === 'on' ? $u['is_enabled'] !== '0' && $u['is_enabled'] !== 0 : (int) $u['is_enabled'] === 1; ?>
                        <tr><td><b><?= e($u['name']) ?></b><br><small class="a2-muted" dir="ltr"><?= e($u['email']) ?></small></td>
                            <td><?= $mode === 'off' ? '<span class="a2-muted">مقفول للكل</span>' : ($on ? '<b style="color:#2a9d76">مفتوح</b>' : '<span class="a2-muted">مقفول</span>') ?></td>
                            <td><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="uid" value="<?= (int) $u['id'] ?>"><input type="hidden" name="q" value="<?= e($q) ?>">
                                <button class="btn sm ghost"><?= (int) $u['is_enabled'] === 1 ? 'اقفل' : 'افتح' ?></button></form></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
            </div>

        <?php elseif ($tab === 'jobs'):
            $jobs = db_all('SELECT j.*, u.name, h.reserved, h.used, h.refunded, h.status hold_status FROM ce_jobs j
                            LEFT JOIN users u ON u.id = j.user_id LEFT JOIN ce_credit_holds h ON h.id = j.hold_id ORDER BY j.id DESC LIMIT 60');
            $held = db_one('SELECT COUNT(*) n, COALESCE(SUM(reserved),0) s FROM ce_credit_holds WHERE status = "held"');
            $stuck = (int) (db_one('SELECT COUNT(*) n FROM ce_jobs WHERE status IN ("queued","running") AND created_at < NOW() - INTERVAL 30 MINUTE')['n'] ?? 0);
            $stLabel = ['queued' => 'في الطابور', 'running' => 'شغال', 'done' => 'خلص', 'failed' => 'فشل', 'cancelled' => 'اتلغى']; ?>
            <div class="a2-kpis" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:14px">
                <div class="card"><div class="a2-muted">كريدت محجوز دلوقتي</div><b style="font-size:22px"><?= (int) $held['s'] ?></b> <small class="a2-muted">في <?= (int) $held['n'] ?> حجز</small></div>
                <div class="card"><div class="a2-muted">شغل واقف من أكتر من 30 دقيقة</div><b style="font-size:22px;color:<?= $stuck ? '#d9534f' : 'inherit' ?>"><?= $stuck ?></b>
                    <?php if ($stuck && !ce_worker_alive()): ?><small class="a2-muted"> — الـ cron مش شغال</small><?php endif; ?></div>
            </div>
            <div class="card"><div class="a2-tw"><table class="a2-tbl"><thead><tr><th>#</th><th>العميل</th><th>النوع</th><th>الحالة</th><th>المحاولات</th><th>الكريدت (محجوز/اتصرف/رجع)</th><th>الوقت</th><th></th></tr></thead><tbody>
                <?php foreach ($jobs as $j): ?>
                    <tr><td class="num"><?= (int) $j['id'] ?></td><td><?= e((string) $j['name']) ?></td>
                        <td><?= e(ce_job_handlers()[$j['kind']]['label'] ?? $j['kind']) ?></td>
                        <td><b><?= $stLabel[$j['status']] ?? e($j['status']) ?></b> <small class="a2-muted"><?= (int) $j['progress'] ?>%</small>
                            <?php if ($j['error']): ?><div style="color:#d9534f;font-size:12px"><?= e($j['error']) ?></div><?php endif; ?></td>
                        <td class="num"><?= (int) $j['attempts'] ?>/<?= (int) $j['max_attempts'] ?></td>
                        <td class="num"><?= $j['hold_id'] ? (int) $j['reserved'] . ' / ' . (int) $j['used'] . ' / ' . (int) $j['refunded'] . ' <small class="a2-muted">' . e((string) $j['hold_status']) . '</small>' : '—' ?></td>
                        <td class="a2-muted" style="font-size:12px;white-space:nowrap"><?= e((string) $j['created_at']) ?></td>
                        <td><?php if (in_array($j['status'], ['queued', 'running'], true)): ?>
                                <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_job"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>">
                                    <button class="btn sm ghost" data-confirm="تلغي الشغلانة دي؟ الكريدت المحجوز هيرجع للعميل.">إلغاء</button></form>
                            <?php elseif ($j['status'] === 'failed' && !$j['hold_id']): ?>
                                <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="retry_job"><input type="hidden" name="id" value="<?= (int) $j['id'] ?>">
                                    <button class="btn sm ghost">أعد</button></form>
                            <?php endif; ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$jobs): ?><tr><td colspan="8" class="a2-muted">مفيش شغل لسه</td></tr><?php endif; ?>
            </tbody></table></div></div>

        <?php else:
            $camps = db_all('SELECT c.*, u.name FROM ce_campaigns c LEFT JOIN users u ON u.id = c.user_id ORDER BY c.id DESC LIMIT 60'); ?>
            <div class="card"><div class="a2-tw"><table class="a2-tbl"><thead><tr><th>#</th><th>العميل</th><th>الحملة</th><th>الحالة</th><th>القطع</th><th>التوكنز</th><th>التكلفة المتوقعة</th><th>الكريدت</th><th>درجة الخطة</th></tr></thead><tbody>
                <?php foreach ($camps as $c): $e = json_decode((string) $c['estimate_json'], true) ?: []; ?>
                    <tr><td class="num"><?= (int) $c['id'] ?></td><td><?= e((string) $c['name']) ?></td><td><?= e((string) $c['title']) ?></td><td><?= e($c['status']) ?></td>
                        <td class="num"><?= (int) ($e['pieces'] ?? 0) ?></td><td class="num"><?= number_format((int) ($e['tokens_total'] ?? 0)) ?></td>
                        <td class="num" dir="ltr">$<?= number_format((float) ($e['usd'] ?? 0), 3) ?></td><td class="num"><?= (int) $c['est_credits'] ?></td>
                        <td class="num"><?= $c['plan_score'] !== null ? '<b style="color:' . ($c['plan_passed'] ? '#2a9d76' : '#d9534f') . '">' . e((string) $c['plan_score']) . '</b>' : '—' ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$camps): ?><tr><td colspan="9" class="a2-muted">مفيش حملات لسه</td></tr><?php endif; ?>
            </tbody></table></div></div>
        <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
