<?php
/**
 * Spread AI — الأدمن: محرك المحتوى v3 (المرحلة D)
 * تبويبات: الإعدادات (قاعدة الكريدت + التقدير) · الخطة والزوايا · الكتابة والجودة · التصميم والقوالب · العملاء (مين يشوف المحرك) · الشغل (الطابور + حجز الكريدت) · الحملات (بالتكلفة بالدولار)
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/ce-design.php';
require_once __DIR__ . '/../includes/usage.php';

require_admin();
require_admin_can('ai_settings');
$admin = current_admin();
$ready = ce_ready();
$tab = in_array($_GET['tab'] ?? '', ['settings', 'plan', 'write', 'design', 'access', 'jobs', 'campaigns'], true) ? $_GET['tab'] : 'settings';
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
    if ($a === 'save_write' && ce_write_ready()) {
        $errs = [];
        $w = [];
        foreach (array_keys(CE_QA_WEIGHTS) as $k) $w[$k] = max(0, (int) ($_POST['qw'][$k] ?? 0));
        if (array_sum($w) !== 100) $errs[] = 'أوزان معايير الجودة لازم مجموعها 100 (دلوقتي ' . array_sum($w) . ')';
        else set_setting('ce_qa_weights', json_encode($w));
        $rng = ['ce_qa_pass_total' => [50, 100, 'حد النجاح'], 'ce_qa_min_criterion' => [0, 100, 'أقل درجة لأي معيار'], 'ce_qa_max_rounds' => [0, 4, 'جولات التحسين'],
                'ce_select_min' => [0, 100, 'أقل درجة للفكرة المختارة'], 'ce_dedup_threshold' => [0.5, 1, 'حد التشابه']];
        foreach ($rng as $k => [$min, $max, $label]) {
            $v = str_replace(',', '.', trim((string) ($_POST[$k] ?? '')));
            if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) { $errs[] = "$label: من $min لـ $max"; continue; }
            set_setting($k, (string) (0 + $v));
        }
        set_setting('ce_critic_other_provider', !empty($_POST['ce_critic_other_provider']) ? '1' : '0');
        admin_log('ce_write_settings', 'settings', null, json_encode(['weights' => $w, 'pass' => $_POST['ce_qa_pass_total'] ?? null]));
        foreach ($errs as $e) flash_set('danger', $e);
        if (!$errs) flash_set('success', 'اتحفظ ✓');
        redirect('admin/ce-settings.php?tab=write');
    }
    if (in_array($a, ['save_design', 'save_template', 'upload_font', 'delete_font'], true) && !ce_design_ready()) {
        flash_set('danger', 'شغّل ترحيل 2026_ce_design الأول');
        redirect('admin/ce-settings.php?tab=design');
    }
    if ($a === 'save_design') {
        $errs = [];
        foreach (['ce_vision_max_regen' => [0, 4, 'إعادات الصورة'], 'ce_vision_min_idea' => [0, 100, 'أقل مطابقة للفكرة'], 'ce_vision_min_palette' => [0, 100, 'أقل مطابقة للألوان'],
                  'ce_carousel_ai_images' => [1, 10, 'شرايح الكاروسيل بصورة AI'], 'ce_min_credits_image' => [0, 50, 'أقل كريدت للصورة']] as $k => [$min, $max, $label]) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) { $errs[] = "$label: من $min لـ $max"; continue; }
            set_setting($k, (string) (int) $v);
        }
        set_setting('ce_reel_cover', !empty($_POST['ce_reel_cover']) ? '1' : '0');
        admin_log('ce_design_settings', 'settings', null, json_encode(array_intersect_key($_POST, array_flip(['ce_vision_max_regen', 'ce_vision_min_idea', 'ce_vision_min_palette', 'ce_carousel_ai_images']))));
        foreach ($errs as $e) flash_set('danger', $e);
        if (!$errs) flash_set('success', 'اتحفظ ✓');
        redirect('admin/ce-settings.php?tab=design');
    }
    if ($a === 'save_template') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
        if ($name === '') { flash_set('danger', 'اكتب اسم القالب'); redirect('admin/ce-settings.php?tab=design'); }
        $in = (array) ($_POST['st'] ?? []);
        if (($in['scrim_color'] ?? '') === 'hex') $in['scrim_color'] = (string) ($_POST['scrim_hex'] ?? '');
        if (($in['text_color'] ?? '') === 'hex') $in['text_color'] = (string) ($_POST['text_hex'] ?? '');
        $style = ce_tpl_clean($in);
        $formats = array_values(array_intersect(['post', 'carousel', 'reel'], (array) ($_POST['formats'] ?? [])));
        if (!$formats) $formats = ['post', 'carousel', 'reel'];
        $active = !empty($_POST['is_active']) ? 1 : 0;
        if ($id) {
            db_run('UPDATE ce_text_templates SET name = ?, style_json = ?, formats = ?, is_active = ?, sort_order = ? WHERE id = ?',
                [$name, json_encode($style), implode(',', $formats), $active, (int) ($_POST['sort_order'] ?? 0), $id]);
        } else {
            $id = (int) db_insert('INSERT INTO ce_text_templates (tkey, name, style_json, formats, is_active, sort_order) VALUES (?,?,?,?,?,?)',
                ['t' . date('ymdHis') . bin2hex(random_bytes(2)), $name, json_encode($style), implode(',', $formats), $active, (int) db_one('SELECT COALESCE(MAX(sort_order),0) + 1 n FROM ce_text_templates')['n']]);
        }
        if (!db_one('SELECT id FROM ce_text_templates WHERE is_active = 1')) {
            db_run('UPDATE ce_text_templates SET is_active = 1 WHERE id = ?', [$id]);
            flash_set('warning', 'لازم يفضل قالب واحد شغال على الأقل — القالب ده اتساب شغال');
        }
        admin_log('ce_template_save', 'ce_text_template', $id, $name);
        flash_set('success', 'اتحفظ ✓');
        redirect('admin/ce-settings.php?tab=design#tpl-' . $id);
    }
    if ($a === 'upload_font') {
        $f = $_FILES['font'] ?? null;
        $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !in_array($ext, ['ttf', 'otf'], true) || $f['size'] > 3 * 1024 * 1024) {
            flash_set('danger', 'ارفع ملف خط TTF أو OTF لحد 3 ميجا');
            redirect('admin/ce-settings.php?tab=design');
        }
        $magic = (string) file_get_contents($f['tmp_name'], false, null, 0, 4);
        if (!in_array($magic, ["\x00\x01\x00\x00", 'true', 'OTTO'], true)) {
            flash_set('danger', 'الملف ده مش خط حقيقي');
            redirect('admin/ce-settings.php?tab=design');
        }
        $key = preg_replace('/[^A-Za-z0-9_\-]/', '', pathinfo((string) $f['name'], PATHINFO_FILENAME)) ?: 'font' . time();
        if (isset(ce_fonts()[$key])) $key .= '-' . bin2hex(random_bytes(2));
        $dir = STORAGE_PATH . '/uploads/ce-fonts';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $dest = $dir . '/' . $key . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dest)) { flash_set('danger', 'مقدرناش نحفظ الملف'); redirect('admin/ce-settings.php?tab=design'); }
        if (!ce_font_has_arabic($dest)) {
            @unlink($dest);
            flash_set('danger', 'الخط ده مافيهوش أشكال الحروف العربية الموصولة — النص هيطلع مقطّع. جرّب خط تاني (مثلًا من Google Fonts: Cairo · Tajawal · Almarai)');
            redirect('admin/ce-settings.php?tab=design');
        }
        admin_log('ce_font_upload', 'ce_font', null, $key);
        flash_set('success', 'الخط «' . $key . '» اتضاف ✓ — اختاره من أي قالب');
        redirect('admin/ce-settings.php?tab=design#fonts');
    }
    if ($a === 'delete_font') {
        $key = (string) ($_POST['key'] ?? '');
        $font = ce_fonts()[$key] ?? null;
        if ($font && !$font['builtin'] && str_starts_with(realpath($font['path']) ?: '', realpath(STORAGE_PATH . '/uploads/ce-fonts') ?: '//')) {
            @unlink($font['path']);
            admin_log('ce_font_delete', 'ce_font', null, $key);
            flash_set('success', 'اتشال — القوالب اللي كانت بتستخدمه هترجع للخط الافتراضي');
        }
        redirect('admin/ce-settings.php?tab=design#fonts');
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
    <main class="main ce-adm">
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
            <?php foreach (['settings' => 'الإعدادات', 'plan' => 'الخطة والزوايا', 'write' => 'الكتابة والجودة', 'design' => 'التصميم والقوالب', 'access' => 'العملاء', 'jobs' => 'الشغل والكريدت', 'campaigns' => 'الحملات'] as $k => $l): ?>
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
                <div class="ce-fgrid">
                    <?php foreach ($nums as $k => [$def, $min, $max, $label]): ?>
                        <label class="ce-f"><span><?= e($label) ?></span>
                            <input class="form-control" name="<?= $k ?>" inputmode="decimal" value="<?= e((string) get_setting($k, (string) $def)) ?>">
                            <small>من <?= e((string) $min) ?> لـ <?= e((string) $max) ?> · الافتراضي <?= e((string) $def) ?></small></label>
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
                <div class="a2-h" style="margin-top:16px"><h3>بوابة الخطة</h3><span class="ce-sum" data-sum="w[">المجموع: 100</span></div>
                <div class="ce-fgrid">
                    <?php foreach (CE_PLAN_DIMS as $d => $l): ?>
                        <label class="ce-f"><span>وزن «<?= e($l) ?>»</span><input class="form-control" name="w[<?= $d ?>]" value="<?= (int) $w[$d] ?>" inputmode="numeric"></label>
                    <?php endforeach; ?>
                    <label class="ce-f"><span>حد النجاح</span><input class="form-control" name="ce_plan_threshold" value="<?= e((string) get_setting('ce_plan_threshold', '80')) ?>" inputmode="numeric"></label>
                    <label class="ce-f"><span>جولات التحسين لو أقل</span><input class="form-control" name="ce_plan_max_rounds" value="<?= e((string) get_setting('ce_plan_max_rounds', '2')) ?>" inputmode="numeric"></label>
                    <label class="ce-f"><span>أقل كريدت للخطة</span><input class="form-control" name="ce_plan_min_credits" value="<?= e((string) get_setting('ce_plan_min_credits', '1')) ?>" inputmode="numeric"></label>
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

        <?php elseif ($tab === 'write'):
            if (!ce_write_ready()): ?>
                <div class="card"><p>الكتابة لسه مش متركّبة — شغّل ترحيل <b>2026_ce_content</b> من <a href="<?= e(url('admin/migrations.php')) ?>">صفحة الترحيلات</a>.</p></div>
            <?php else:
            $qw = ce_qa_weights(); $rules = ce_qa_rules();
            $st = db_one('SELECT COUNT(*) n, SUM(status = "pass") p, SUM(status = "manual") m, SUM(status = "failed") f, AVG(qa_score) a, AVG(rounds) r FROM ce_contents WHERE created_at > NOW() - INTERVAL 30 DAY');
            $dups = db_one('SELECT COUNT(*) n, SUM(is_dup) d FROM ce_ideas WHERE created_at > NOW() - INTERVAL 30 DAY'); ?>
            <div class="ce-kpis">
                <div class="a2-kpi"><small>قطع آخر 30 يوم</small><b><?= (int) $st['n'] ?></b></div>
                <div class="a2-kpi"><small>عدّت من غير تدخل</small><b><?= $st['n'] ? round(100 * $st['p'] / $st['n']) : 0 ?>%</b></div>
                <div class="a2-kpi"><small>مراجعة يدوية</small><b><?= (int) $st['m'] ?></b></div>
                <div class="a2-kpi"><small>متوسط الجودة</small><b><?= $st['a'] !== null ? round((float) $st['a'], 1) : '—' ?></b></div>
                <div class="a2-kpi"><small>متوسط جولات التحسين</small><b><?= $st['r'] !== null ? round((float) $st['r'], 2) : '—' ?></b></div>
                <div class="a2-kpi"><small>أفكار اترفضت (تكرار)</small><b><?= (int) $dups['d'] ?> / <?= (int) $dups['n'] ?></b></div>
            </div>
            <form method="post" class="card">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_write">
                <div class="a2-h"><h3>أوزان معايير الجودة</h3><span class="ce-sum" data-sum="qw[">المجموع: 100</span></div>
                <div class="ce-fgrid">
                    <?php foreach (CE_QA_LABELS as $k => $l): ?>
                        <label class="ce-f"><span><?= e($l) ?></span><input class="form-control" name="qw[<?= $k ?>]" value="<?= (int) $qw[$k] ?>" inputmode="numeric"></label>
                    <?php endforeach; ?>
                </div>
                <div class="a2-h" style="margin-top:16px"><h3>البوابة والأفكار</h3></div>
                <div class="ce-fgrid">
                    <label class="ce-f"><span>حد النجاح (الإجمالي)</span><input class="form-control" name="ce_qa_pass_total" value="<?= e((string) $rules['pass_total']) ?>" inputmode="decimal"></label>
                    <label class="ce-f"><span>أقل درجة لأي معيار</span><input class="form-control" name="ce_qa_min_criterion" value="<?= e((string) $rules['min_criterion']) ?>" inputmode="decimal"></label>
                    <label class="ce-f"><span>جولات التحسين</span><input class="form-control" name="ce_qa_max_rounds" value="<?= (int) $rules['max_rounds'] ?>" inputmode="numeric"></label>
                    <label class="ce-f"><span>أقل درجة للفكرة المختارة</span><input class="form-control" name="ce_select_min" value="<?= e((string) $rules['select_min']) ?>" inputmode="decimal"></label>
                    <label class="ce-f"><span>حد التشابه (رفض الفكرة)</span><input class="form-control" name="ce_dedup_threshold" value="<?= e((string) $rules['dedup']) ?>" inputmode="decimal"></label>
                </div>
                <label class="st-sw ce-sw"><input type="checkbox" name="ce_critic_other_provider" value="1" <?= $rules['other_provider'] ? 'checked' : '' ?>><span><b>الناقد من مزود غير الكاتب</b><small>لو فيه مزود تاني شغال — ولو وقع بنرجع للسلسلة العادية</small></span></label>
                <p class="a2-muted" style="margin:8px 0 0">PASS = الإجمالي ≥ الحد ومفيش معيار أقل من الحد الأدنى ومفيش مخالفة (كلمات ممنوعة · وعود مضمونة · استثناءات الحملة). غير كده بنحسّن الأجزاء الضعيفة بس لحد عدد الجولات، وبعدها القطعة بتتعلّم «محتاجة مراجعة» بأحسن نسخة. التشابه بالحروف (0.85 = شبه متطابقة).</p>
                <button class="btn" style="margin-top:12px">حفظ</button>
            </form>
            <?php endif; ?>

        <?php elseif ($tab === 'design'):
            if (!ce_design_ready()): ?>
                <div class="card"><p>التصميم لسه مش متركّب — شغّل ترحيل <b>2026_ce_design</b> من <a href="<?= e(url('admin/migrations.php')) ?>">صفحة الترحيلات</a>.</p></div>
            <?php else:
            $dr = ce_design_rules(); $tpls = ce_templates(false); $fonts = ce_fonts();
            $ds = db_one('SELECT COUNT(*) n, SUM(status = "pass") p, SUM(status = "manual") m, SUM(status = "failed") f, AVG(CASE WHEN kind = "ai" THEN qa_score END) q, AVG(CASE WHEN kind = "ai" THEN attempts END) a FROM ce_designs WHERE created_at > NOW() - INTERVAL 30 DAY');
            $fontOpts = function (string $sel) use ($fonts) {
                $h = '';
                foreach ($fonts as $k => $f) $h .= '<option value="' . e($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . e($f['label']) . ($f['builtin'] ? '' : ' (مرفوع)') . '</option>';
                return $h;
            };
            $opts = function (array $list, string $sel) {
                $h = '';
                foreach ($list as $k => $l) $h .= '<option value="' . e($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . e($l) . '</option>';
                return $h;
            }; ?>
            <div class="ce-kpis">
                <div class="a2-kpi"><small>تصميمات آخر 30 يوم</small><b><?= (int) $ds['n'] ?></b></div>
                <div class="a2-kpi"><small>جاهزة من غير تدخل</small><b><?= $ds['n'] ? round(100 * $ds['p'] / $ds['n']) : 0 ?>%</b></div>
                <div class="a2-kpi"><small>محتاجة مراجعة</small><b><?= (int) $ds['m'] ?></b></div>
                <div class="a2-kpi"><small>فشلت</small><b><?= (int) $ds['f'] ?></b></div>
                <div class="a2-kpi"><small>متوسط مراجعة الصورة</small><b><?= $ds['q'] !== null ? round((float) $ds['q'], 1) : '—' ?></b></div>
                <div class="a2-kpi"><small>متوسط المحاولات للصورة</small><b><?= $ds['a'] !== null ? round((float) $ds['a'], 2) : '—' ?></b></div>
            </div>

            <form method="post" class="card" style="margin-bottom:14px">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_design">
                <div class="a2-h"><h3>مراجعة الصورة والكريدت</h3></div>
                <div class="ce-fgrid">
                    <label class="ce-f"><span>إعادة الصورة لو ماعدّتش</span><input class="form-control" name="ce_vision_max_regen" value="<?= (int) $dr['max_regen'] ?>" inputmode="numeric"><small>من 0 لـ 4 · الافتراضي 2</small></label>
                    <label class="ce-f"><span>أقل مطابقة للفكرة</span><input class="form-control" name="ce_vision_min_idea" value="<?= (int) $dr['min_idea'] ?>" inputmode="numeric"><small>الافتراضي 70</small></label>
                    <label class="ce-f"><span>أقل مطابقة لألوان البراند</span><input class="form-control" name="ce_vision_min_palette" value="<?= (int) $dr['min_palette'] ?>" inputmode="numeric"><small>الافتراضي 60</small></label>
                    <label class="ce-f"><span>شرايح الكاروسيل بصورة AI</span><input class="form-control" name="ce_carousel_ai_images" value="<?= (int) $dr['carousel_ai'] ?>" inputmode="numeric"><small>الباقي نص على خلفية البراند · الافتراضي 1</small></label>
                    <label class="ce-f"><span>أقل كريدت للصورة</span><input class="form-control" name="ce_min_credits_image" value="<?= (int) $dr['min_credits'] ?>" inputmode="numeric"><small>الافتراضي 1</small></label>
                </div>
                <label class="st-sw ce-sw"><input type="checkbox" name="ce_reel_cover" value="1" <?= $dr['reel_cover'] ? 'checked' : '' ?>><span><b>غلاف للريلز (9:16)</b><small>صورة بعنوان الريلز — من غيرها الريلز سكريبت بس</small></span></label>
                <p class="a2-muted" style="margin:8px 0 0">الموديل مابيكتبش أي نص جوه الصورة. المراجعة بتشوف: الألوان · المكان الفاضي للنص · العيوب (إيدين · أسنان · وشوش) · فيه كلام في الصورة · مطابقة الفكرة. موديل الصورة والمراجعة من «الموديلات ← خريطة المراحل» (ce_image · ce_vision_qa).</p>
                <button class="btn" style="margin-top:12px">حفظ</button>
            </form>

            <div class="card" style="margin-bottom:14px">
                <div class="a2-h"><h3>قوالب النص العربي</h3><span class="a2-grow"></span><small class="a2-muted">المعاينة على صورة تجريبية · العميل بيختار القالب لكل تصميم</small></div>
                <div class="ce-tpls">
                <?php foreach (array_merge($tpls, [['id' => 0, 'name' => '', 'style' => CE_TPL_DEFAULT, 'formats' => ['post', 'carousel', 'reel'], 'is_active' => 1, 'sort_order' => 0]]) as $t): $st = $t['style'];
                    $scHex = preg_match('/^#/', $st['scrim_color']) ? $st['scrim_color'] : '#0B1526'; $tcHex = preg_match('/^#/', $st['text_color']) ? $st['text_color'] : '#FFFFFF'; ?>
                    <form method="post" class="ce-tpl" id="tpl-<?= (int) $t['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="save_template"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><input type="hidden" name="sort_order" value="<?= (int) $t['sort_order'] ?>">
                        <?php if ($t['id']): ?>
                            <div class="ce-tpl-prev"><img src="<?= e(url('admin/ce-template-preview.php?id=' . $t['id'] . '&aspect=4:5&v=' . substr(md5(json_encode($st)), 0, 6))) ?>" alt="" loading="lazy">
                                <img src="<?= e(url('admin/ce-template-preview.php?id=' . $t['id'] . '&aspect=1:1&bg=text&v=' . substr(md5(json_encode($st)), 0, 6))) ?>" alt="" loading="lazy"></div>
                        <?php endif; ?>
                        <details class="ce-tpl-ed"><summary><?= $t['id'] ? '<b>' . e($t['name']) . '</b> · ' . ($t['is_active'] ? 'شغال' : '<span class="a2-muted">مقفول</span>') . ' — تعديل' : '<b>+ قالب جديد</b>' ?></summary>
                        <div class="ce-fgrid ce-fgrid-sm">
                            <label class="ce-f ce-f-wide"><span>الاسم</span><input class="form-control" name="name" value="<?= e($t['name']) ?>" placeholder="اسم القالب"></label>
                            <label class="ce-f"><span>مكان النص</span><select class="form-control" name="st[layout]"><?= $opts(CE_TPL_LAYOUTS, $st['layout']) ?></select></label>
                            <label class="ce-f"><span>خلفية النص</span><select class="form-control" name="st[scrim]"><?= $opts(CE_TPL_SCRIMS, $st['scrim']) ?></select></label>
                            <label class="ce-f"><span>لونها</span><select class="form-control" name="st[scrim_color]"><?= $opts(CE_TPL_COLORS + ['hex' => 'لون مخصوص'], preg_match('/^#/', $st['scrim_color']) ? 'hex' : $st['scrim_color']) ?></select><input type="color" name="scrim_hex" value="<?= e($scHex) ?>" aria-label="اللون المخصوص"></label>
                            <label class="ce-f"><span>الشفافية %</span><input class="form-control" name="st[scrim_opacity]" value="<?= e((string) $st['scrim_opacity']) ?>" inputmode="numeric"></label>
                            <label class="ce-f"><span>لون الكلام</span><select class="form-control" name="st[text_color]"><option value="auto"<?= $st['text_color'] === 'auto' ? ' selected' : '' ?>>تلقائي (حسب الخلفية)</option><option value="hex"<?= $st['text_color'] !== 'auto' ? ' selected' : '' ?>>لون مخصوص</option></select><input type="color" name="text_hex" value="<?= e($tcHex) ?>" aria-label="لون الكلام"></label>
                            <label class="ce-f"><span>المحاذاة</span><select class="form-control" name="st[align]"><?= $opts(['center' => 'في النص', 'right' => 'يمين'], $st['align']) ?></select></label>
                            <label class="ce-f"><span>خط العنوان</span><select class="form-control" name="st[font_h]"><?= $fontOpts($st['font_h']) ?></select></label>
                            <label class="ce-f"><span>خط السطر التاني</span><select class="form-control" name="st[font_s]"><?= $fontOpts($st['font_s']) ?></select></label>
                            <label class="ce-f"><span>حجم العنوان (% من العرض)</span><input class="form-control" name="st[h_size]" value="<?= e((string) $st['h_size']) ?>" inputmode="decimal"></label>
                            <label class="ce-f"><span>حجم السطر التاني</span><input class="form-control" name="st[s_size]" value="<?= e((string) $st['s_size']) ?>" inputmode="decimal"></label>
                            <label class="ce-f"><span>مساحة النص (% من الصورة)</span><input class="form-control" name="st[band]" value="<?= e((string) $st['band']) ?>" inputmode="numeric"></label>
                        </div>
                        <div class="ce-tpl-sw">
                            <label class="st-sw"><input type="checkbox" name="st[accent]" value="1" <?= $st['accent'] ? 'checked' : '' ?>><span>خط بلون البراند فوق العنوان</span></label>
                            <input type="hidden" name="st[logo]" value="none"><label class="st-sw"><input type="checkbox" name="st[logo]" value="corner" <?= $st['logo'] === 'corner' ? 'checked' : '' ?>><span>شعار العميل في الركن</span></label>
                            <?php foreach (['post' => 'بوست', 'carousel' => 'كاروسيل', 'reel' => 'ريلز'] as $fk => $fl): ?>
                                <label class="st-sw"><input type="checkbox" name="formats[]" value="<?= $fk ?>" <?= in_array($fk, $t['formats'], true) ? 'checked' : '' ?>><span><?= $fl ?></span></label>
                            <?php endforeach; ?>
                            <label class="st-sw"><input type="checkbox" name="is_active" value="1" <?= $t['is_active'] ? 'checked' : '' ?>><span>شغال</span></label>
                        </div>
                        <button class="btn sm"><?= $t['id'] ? 'حفظ ومعاينة' : 'إضافة' ?></button>
                        </details>
                    </form>
                <?php endforeach; ?>
                </div>
            </div>

            <div class="card" id="fonts">
                <div class="a2-h"><h3>الخطوط</h3></div>
                <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الخط</th><th>المصدر</th><th>الحروف العربية</th><th></th></tr></thead><tbody>
                    <?php foreach ($fonts as $k => $f): ?>
                        <tr><td><b><?= e($f['label']) ?></b> <small class="a2-muted" dir="ltr"><?= e($k) ?></small></td><td><?= $f['builtin'] ? 'مع المنصة (OFL)' : 'مرفوع' ?></td>
                            <td><?= ce_font_has_arabic($f['path']) ? '<span class="chip chip-mint">كاملة</span>' : '<span class="chip chip-coral">ناقصة</span>' ?></td>
                            <td><?php if (!$f['builtin']): ?><form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="delete_font"><input type="hidden" name="key" value="<?= e($k) ?>"><button class="btn sm ghost" data-confirm="تشيل الخط ده؟">شيل</button></form><?php endif; ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <form method="post" enctype="multipart/form-data" class="ce-upl"><?= csrf_field() ?><input type="hidden" name="action" value="upload_font">
                    <input type="file" name="font" accept=".ttf,.otf" required class="form-control"><button class="btn">رفع خط</button></form>
                <p class="a2-muted" style="margin:8px 0 0">TTF أو OTF لحد 3 ميجا. لو خط البراند (من الهوية) اسمه زي خط مرفوع، بيتستخدم في العنوان أوتوماتيك. لازم الخط يكون فيه أشكال الحروف الموصولة — بنتأكد قبل ما نقبله.</p>
            </div>
            <?php endif; ?>

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
            <div class="ce-kpis">
                <div class="a2-kpi"><small>كريدت محجوز دلوقتي</small><b><?= (int) $held['s'] ?></b><em>في <?= (int) $held['n'] ?> حجز</em></div>
                <div class="a2-kpi"><small>شغل واقف من أكتر من 30 دقيقة</small><b style="color:<?= $stuck ? '#C2362E' : 'inherit' ?>"><?= $stuck ?></b>
                    <em><?= $stuck && !ce_worker_alive() ? 'الـ cron مش شغال' : '&nbsp;' ?></em></div>
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
            <div class="card"><div class="a2-tw"><table class="a2-tbl"><thead><tr><th>#</th><th>العميل</th><th>الحملة</th><th>الحالة</th><th>القطع</th><th>التوكنز</th><th>التكلفة المتوقعة</th><th>الكريدت</th><th>درجة الخطة</th><th>الجودة</th></tr></thead><tbody>
                <?php foreach ($camps as $c): $e = json_decode((string) $c['estimate_json'], true) ?: []; $fq = json_decode((string) ($c['final_qa_json'] ?? ''), true) ?: null; ?>
                    <tr><td class="num"><?= (int) $c['id'] ?></td><td><?= e((string) $c['name']) ?></td><td><?= e((string) $c['title']) ?></td><td><?= e($c['status']) ?></td>
                        <td class="num"><?= (int) ($e['pieces'] ?? 0) ?></td><td class="num"><?= number_format((int) ($e['tokens_total'] ?? 0)) ?></td>
                        <td class="num" dir="ltr">$<?= number_format((float) ($e['usd'] ?? 0), 3) ?></td><td class="num"><?= (int) $c['est_credits'] ?></td>
                        <td class="num"><?= $c['plan_score'] !== null ? '<b style="color:' . ($c['plan_passed'] ? '#2a9d76' : '#d9534f') . '">' . e((string) $c['plan_score']) . '</b>' : '—' ?></td>
                        <td class="num"><?= $fq ? e(($fq['avg_qa'] ?? '—') . ' · ' . (int) $fq['pass'] . '✓' . ($fq['manual'] ? ' · ' . (int) $fq['manual'] . '⚠' : '')) : '—' ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$camps): ?><tr><td colspan="10" class="a2-muted">مفيش حملات لسه</td></tr><?php endif; ?>
            </tbody></table></div></div>
        <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<script>
// مجموع الأوزان لحظيًا (لازم 100)
document.querySelectorAll('.ce-sum[data-sum]').forEach(function (b) {
    var f = b.closest('form'), pre = b.dataset.sum;
    var calc = function () {
        var t = 0;
        f.querySelectorAll('input').forEach(function (i) { if (i.name.indexOf(pre) === 0) t += parseInt(i.value || 0, 10) || 0; });
        b.textContent = 'المجموع: ' + t; b.classList.toggle('bad', t !== 100);
    };
    f.addEventListener('input', calc); calc();
});
</script>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
