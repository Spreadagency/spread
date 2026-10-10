<?php
/**
 * مركز الإعدادات (المرحلة 8-ج)
 * كل الإعدادات العامة في صفحة واحدة بتبويبات · بحث · سجل تغييرات · تصدير/استيراد من غير أسرار.
 * الحقول جاية من includes/settings-registry.php — والصفحات المتخصصة (المظهر، البرومبتات، …) لينكات جوه كل تبويب.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/admin-ui.php';
if (is_file(__DIR__ . '/../includes/social.php')) require_once __DIR__ . '/../includes/social.php';
if (is_file(__DIR__ . '/../includes/crypto.php')) require_once __DIR__ . '/../includes/crypto.php';

require_admin();
require_admin_can('site_settings');
$admin = current_admin();

$REG = settings_registry();
$TABS = [];
foreach ($REG as $k => $t) $TABS[$k] = $t['label'];
$TABS['log'] = 'سجل التغييرات';
$TABS['backup'] = 'تصدير واستيراد';
$tab = (string) ($_GET['tab'] ?? 'general');
if (!isset($TABS[$tab])) $tab = 'general';
$back = fn($t) => 'admin/settings.php?tab=' . $t;

/* ═══════════ تصدير (POST بتوكن) ═══════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    require_csrf();
    $data = ['app' => 'spread-ai', 'build' => defined('SPREAD_BUILD') ? SPREAD_BUILD : null, 'exported_at' => date('c'),
        'note' => 'الأسرار (المفاتيح والتوكنات) مش موجودة في الملف ده', 'settings' => settings_export()];
    admin_log('settings_export', 'settings', null, (string) count($data['settings']));
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="spread-settings-' . date('Y-m-d') . '.json"');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ═══════════ الحفظ ═══════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $t = (string) ($_POST['tab'] ?? '');
        if (!isset($REG[$t])) redirect($back('general'));
        $errs = $set = [];
        $clear = is_array($_POST['clear'] ?? null) ? $_POST['clear'] : [];
        foreach ($REG[$t]['sections'] as $sec) {
            foreach ($sec['fields'] as $f) {
                if (($f['type'] ?? '') === 'info') continue;
                $in = $f['input'] ?? $f['key'];
                if (($f['type'] ?? '') !== 'bool' && ($f['type'] ?? '') !== 'secret' && !array_key_exists($in, $_POST)) continue;
                // قيمة قديمة ماتغيرتش: مانرفضهاش حتى لو مش مطابقة للقواعد الجديدة (علشان ماتقفلش حفظ التبويب كله)
                if (($f['type'] ?? '') !== 'secret' && ($f['type'] ?? '') !== 'bool' && trim((string) ($_POST[$in] ?? '')) === (string) get_setting($f['key'], '')) continue;
                [$ok, $val, $err] = settings_clean($f, $_POST[$in] ?? '', !empty($clear[$f['key']]));
                if (!$ok) { $errs[] = '«' . $f['label'] . '»: ' . $err; continue; }
                if ($val === null) continue;
                $cur = get_setting($f['key'], null);
                if ($cur !== null && (string) $cur === $val) continue;
                if ($cur === null && $val === (string) ($f['default'] ?? '') && ($f['type'] ?? '') !== 'secret') continue;
                $set[$f['key']] = $val;
            }
        }
        if ($errs) {
            flash_set('danger', 'ماتحفظش — صحّح: ' . implode(' · ', $errs));
            redirect($back($t));
        }
        foreach ($set as $k => $v) set_setting($k, $v);
        admin_log('settings_save', 'settings', null, json_encode(['tab' => $t, 'keys' => array_keys($set)], JSON_UNESCAPED_UNICODE));
        flash_set('success', $set ? 'اتحفظ ✓ — ' . count($set) . ' إعداد اتغير' : 'مفيش تغيير');
        redirect($back($t));
    }

    if ($action === 'import_preview') {
        $raw = '';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name']) && (int) $_FILES['file']['size'] <= 512 * 1024) {
            $raw = (string) file_get_contents($_FILES['file']['tmp_name']);
        }
        $j = json_decode($raw, true);
        $in = is_array($j['settings'] ?? null) ? $j['settings'] : null;
        if (!is_array($in) || !$in) {
            flash_set('danger', 'الملف مش ملف إعدادات صحيح (JSON من «تصدير»، أقل من 512KB)');
            redirect($back('backup'));
        }
        // في الجلسة بنخزن التغييرات المفلترة بس (مش الملف كله)
        [$chg, $skp] = settings_import_diff($in);
        $keep = [];
        foreach ($chg as $c) $keep[$c[0]] = $c[2];
        $_SESSION['settings_import'] = ['at' => time(), 'data' => $keep, 'skipped' => array_slice($skp, 0, 80)];
        redirect($back('backup') . '&preview=1');
    }

    if ($action === 'import_apply') {
        $imp = $_SESSION['settings_import'] ?? null;
        unset($_SESSION['settings_import']);
        if (!$imp || time() - (int) $imp['at'] > 1800) {
            flash_set('danger', 'انتهت مهلة المعاينة — ارفع الملف تاني');
            redirect($back('backup'));
        }
        [$changes] = settings_import_diff((array) $imp['data']); // تحقق تاني وقت التطبيق
        $pick = is_array($_POST['keys'] ?? null) ? array_flip(array_map('strval', $_POST['keys'])) : [];
        $n = 0;
        foreach ($changes as [$k, $old, $new]) {
            if (!isset($pick[$k])) continue;
            set_setting($k, $new);
            $n++;
        }
        admin_log('settings_import', 'settings', null, (string) $n);
        flash_set('success', 'اتستورد ' . $n . ' إعداد ✓');
        redirect($back('log'));
    }

    if ($action === 'import_cancel') {
        unset($_SESSION['settings_import']);
        redirect($back('backup'));
    }
    redirect($back($tab));
}

/* ═══════════ بيانات التبويبات الخاصة ═══════════ */
$auditOn = settings_audit_ready();
$log = [];
$logTotal = 0;
$lq = trim((string) ($_GET['q'] ?? ''));
$lpage = max(1, min(100000, (int) ($_GET['page'] ?? 1)));
if ($tab === 'log' && $auditOn) {
    $w = '1=1';
    $p = [];
    if ($lq !== '') { $w .= ' AND a.setting_key LIKE ?'; $p[] = '%' . $lq . '%'; }
    $logTotal = (int) (db_one("SELECT COUNT(*) c FROM settings_audit a WHERE $w", $p)['c'] ?? 0);
    $log = db_all("SELECT a.*, u.name admin_name FROM settings_audit a LEFT JOIN admin_users u ON u.id = a.admin_id WHERE $w ORDER BY a.id DESC LIMIT 50 OFFSET " . (($lpage - 1) * 50), $p);
}
$preview = null;
if (!empty($_SESSION['settings_import']) && time() - (int) ($_SESSION['settings_import']['at'] ?? 0) > 1800) unset($_SESSION['settings_import']);
if ($tab === 'backup' && !empty($_GET['preview']) && !empty($_SESSION['settings_import'])) {
    $preview = [settings_import_diff((array) $_SESSION['settings_import']['data'])[0], (array) ($_SESSION['settings_import']['skipped'] ?? [])];
}
$fieldsAll = settings_fields();
$lastChange = [];
if ($auditOn) {
    try {
        foreach (db_all('SELECT setting_key, MAX(created_at) t FROM settings_audit GROUP BY setting_key') as $r) $lastChange[$r['setting_key']] = $r['t'];
    } catch (\Throwable $e) {}
}
@include_once __DIR__ . '/../includes/version.php';

$active = 'settings';
$page_title = 'مركز الإعدادات';
include __DIR__ . '/../templates/admin-header.php';
$norm = fn($s) => mb_strtolower((string) $s);
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div>
                <h1>مركز الإعدادات</h1>
                <div class="sub">Settings · كل إعدادات المنصة في مكان واحد — وأي تغيير بيتسجل مين عمله وإمتى</div>
            </div>
            <div class="st-search">
                <input type="search" id="stQ" class="input" placeholder="دوّر على إعداد… (مثلًا: واتساب، Paymob، موديل)" autocomplete="off">
            </div>
        </div>

        <?php if (!$auditOn): ?>
            <div class="alert warning">سجل التغييرات لسه مش شغال — شغّل ترحيل «8-ج» من <a href="<?= url('admin/ui-diagnose.php') ?>">فحص التحديث</a>. الإعدادات نفسها بتتحفظ عادي.</div>
        <?php endif; ?>

        <div class="st-wrap">
            <nav class="st-nav" id="stNav" aria-label="أقسام الإعدادات">
                <?php foreach ($TABS as $tk => $tl): $ico = $REG[$tk]['icon'] ?? ($tk === 'log' ? 'list' : 'download'); ?>
                    <a href="<?= url($back($tk)) ?>" data-tab="<?= e($tk) ?>" class="<?= $tab === $tk ? 'on' : '' ?>"><?= admin_icon($ico, 18) ?><span><?= e($tl) ?></span></a>
                <?php endforeach; ?>
                <div class="st-meta">
                    نسخة <?= defined('SPREAD_BUILD') ? e(SPREAD_BUILD) : '—' ?> · PHP <?= e(PHP_VERSION) ?><br>
                    <?= e(date_default_timezone_get()) ?> · رفع حتى <?= e((string) ini_get('upload_max_filesize')) ?><br>
                    <a href="<?= url('admin/ui-diagnose.php') ?>">فحص التحديث ←</a>
                </div>
            </nav>

            <div class="st-body">
                <div class="st-empty" id="stNone" hidden>مفيش إعداد بالاسم ده — جرّب كلمة تانية، أو دوّر في الأقسام من البحث اللي فوق خالص.</div>

                <?php foreach ($REG as $tk => $T): ?>
                <section class="st-panel" data-panel="<?= e($tk) ?>" <?= $tab === $tk ? '' : 'hidden' ?>>
                    <form method="post" data-safe-post class="st-form" autocomplete="off">
                        <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="tab" value="<?= e($tk) ?>">
                        <div class="st-panel-h"><h2><?= e($T['label']) ?></h2><p class="a2-muted"><?= e($T['desc']) ?></p></div>
                        <?php foreach ($T['sections'] as $sec): ?>
                            <div class="card st-sec">
                                <div class="a2-h"><h3><?= e($sec['title']) ?></h3></div>
                                <?php foreach ($sec['fields'] as $f):
                                    $type = $f['type'] ?? 'text';
                                    $in = $f['input'] ?? $f['key'];
                                    $sIdx = $norm($f['label'] . ' ' . $f['key'] . ' ' . ($f['help'] ?? '') . ' ' . $sec['title'] . ' ' . $T['label']);
                                    if ($type === 'info') {
                                        $iv = is_callable($f['value'] ?? null) ? (string) call_user_func($f['value']) : '';
                                        ?>
                                        <div class="st-f" data-s="<?= e($sIdx) ?>">
                                            <label class="a2-lbl"><?= e($f['label']) ?></label>
                                            <input class="input" dir="ltr" readonly value="<?= e($iv) ?>" onclick="this.select()" data-no-encode="1">
                                        </div>
                                        <?php continue;
                                    }
                                    [$val, $has] = settings_current($f);
                                    ?>
                                    <div class="st-f<?= $type === 'bool' ? ' st-bool' : '' ?>" id="f-<?= e($f['key']) ?>" data-s="<?= e($sIdx) ?>">
                                        <?php if ($type === 'bool'): ?>
                                            <label class="st-sw">
                                                <input type="hidden" name="<?= e($in) ?>" value="0">
                                                <input type="checkbox" name="<?= e($in) ?>" value="1" <?= $val === '1' ? 'checked' : '' ?>>
                                                <span><b><?= e($f['label']) ?></b><?php if (!empty($f['help'])): ?><small><?= e($f['help']) ?></small><?php endif; ?></span>
                                            </label>
                                        <?php else: ?>
                                            <label class="a2-lbl" for="i-<?= e($f['key']) ?>"><?= e($f['label']) ?>
                                                <?php if ($type === 'secret'): ?><span class="chip <?= $has ? 'chip-mint' : 'chip-line' ?>"><?= $has ? '🔒 محفوظ' : 'مش متحط' ?></span><?php endif; ?>
                                            </label>
                                            <?php if ($type === 'select'): ?>
                                                <select class="input" name="<?= e($in) ?>" id="i-<?= e($f['key']) ?>">
                                                    <?php foreach ($f['options'] as $ok => $ol): ?><option value="<?= e((string) $ok) ?>" <?= (string) $ok === $val ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?>
                                                </select>
                                            <?php elseif ($type === 'textarea'): ?>
                                                <textarea class="input" name="<?= e($in) ?>" id="i-<?= e($f['key']) ?>" rows="3"><?= e($val) ?></textarea>
                                            <?php elseif ($type === 'secret'): ?>
                                                <div class="st-secret">
                                                    <?php if (!empty($f['reveal']) && $has): // مفتاح لازم الأدمن ينسخه (أمر الكرون / ميتا) — مخفي لحد ما يدوس «إظهار» ?>
                                                    <input class="input" type="password" name="<?= e($in) ?>" id="i-<?= e($f['key']) ?>" dir="ltr" value="<?= e((string) get_setting($f['key'], '')) ?>" autocomplete="new-password" data-no-encode="1">
                                                    <button type="button" class="btn sm ghost" onclick="var i=document.getElementById('i-<?= e($f['key']) ?>');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'👁 إظهار':'إخفاء'">👁 إظهار</button>
                                                    <?php else: ?>
                                                    <input class="input" type="password" name="<?= e($in) ?>" id="i-<?= e($f['key']) ?>" dir="ltr" value="" autocomplete="new-password" data-no-encode="1"
                                                           placeholder="<?= $has ? 'محفوظ — اكتب قيمة جديدة بس لو عايز تغيّره' : 'اكتب القيمة' ?>">
                                                    <?php endif; ?>
                                                    <?php if ($has): ?><label class="st-clear"><input type="checkbox" name="clear[<?= e($f['key']) ?>]" value="1"> امسحه</label><?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <input class="input" id="i-<?= e($f['key']) ?>" name="<?= e($in) ?>"
                                                       type="<?= $type === 'number' ? 'number' : ($type === 'url' ? 'url' : ($type === 'email' ? 'email' : ($type === 'tel' || $type === 'digits' ? 'tel' : 'text'))) ?>"
                                                       value="<?= e($val) ?>" <?= !empty($f['ph']) ? 'placeholder="' . e($f['ph']) . '"' : '' ?>
                                                       <?= isset($f['min']) ? 'min="' . (int) $f['min'] . '"' : '' ?> <?= isset($f['max']) ? 'max="' . (int) $f['max'] . '"' : '' ?>
                                                       <?= !empty($f['required']) ? 'required' : '' ?> <?= (!empty($f['ltr']) || in_array($type, ['url', 'email', 'tel', 'digits', 'number'], true)) ? 'dir="ltr"' : '' ?>>
                                            <?php endif; ?>
                                            <?php if (!empty($f['help'])): ?><div class="st-help"><?= e($f['help']) ?></div><?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (isset($lastChange[$f['key']])): ?><div class="st-when">آخر تغيير <?= e(time_ago($lastChange[$f['key']])) ?> · <a href="<?= url('admin/settings.php?tab=log&q=' . rawurlencode($f['key'])) ?>">السجل</a></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="st-save"><button class="btn" type="submit">💾 حفظ «<?= e($T['label']) ?>»</button><span class="a2-muted">الحفظ بيغيّر اللي في التبويب ده بس</span></div>
                    </form>
                    <?php $links = array_filter($T['links'] ?? [], fn($l) => !$l[3] || admin_can($l[3])); if ($links): ?>
                        <div class="st-links">
                            <div class="a2-lbl" style="margin-bottom:8px">صفحات متخصصة في القسم ده</div>
                            <div class="a2-grid a2-g3" style="gap:10px">
                                <?php foreach ($links as [$ll, $lu, $ld]): ?>
                                    <a class="st-link" href="<?= url($lu) ?>" data-s="<?= e($norm($ll . ' ' . $ld . ' ' . $T['label'])) ?>"><b><?= e($ll) ?> ←</b><small><?= e($ld) ?></small></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
                <?php endforeach; ?>

                <!-- سجل التغييرات -->
                <section class="st-panel" data-panel="log" <?= $tab === 'log' ? '' : 'hidden' ?>>
                    <div class="st-panel-h"><h2>سجل التغييرات</h2><p class="a2-muted">كل تغيير في إعداد من أي صفحة في لوحة الأدمن — الأسرار مابتظهرش قيمتها.</p></div>
                    <?php if (!$auditOn): ?><div class="card a2-empty">السجل بيشتغل بعد ترحيل «8-ج».</div><?php else: ?>
                    <form method="get" class="card st-logf">
                        <input type="hidden" name="tab" value="log">
                        <input class="input" name="q" value="<?= e($lq) ?>" placeholder="فلتر باسم الإعداد (مثلًا paymob)" dir="ltr">
                        <button class="btn">فلتر</button>
                        <?php if ($lq !== ''): ?><a class="btn ghost" href="<?= url($back('log')) ?>">مسح</a><?php endif; ?>
                        <span class="a2-muted"><?= a2n($logTotal) ?> تغيير</span>
                    </form>
                    <div class="card" style="padding:0;margin-top:12px">
                        <?php if (!$log): ?><div class="a2-empty">مفيش تغييرات متسجلة لسه</div><?php else: ?>
                        <div class="a2-tw" style="border:0"><table class="a2-tbl st-logt">
                            <thead><tr><th>الإعداد</th><th>قبل</th><th>بعد</th><th>مين</th><th>من</th><th>إمتى</th></tr></thead>
                            <tbody>
                            <?php foreach ($log as $r): $lbl = settings_label((string) $r['setting_key']); ?>
                                <tr>
                                    <td><?= $lbl ? '<b>' . e($lbl) . '</b><div class="a2-muted" dir="ltr" style="font-size:11.5px">' . e($r['setting_key']) . '</div>' : '<b dir="ltr">' . e($r['setting_key']) . '</b>' ?><?= $r['is_secret'] ? ' <span class="chip chip-line">🔒 سري</span>' : '' ?></td>
                                    <td class="st-v"><?= $r['old_value'] === null ? '<span class="a2-muted">جديد</span>' : ($r['old_value'] === '' ? '<span class="a2-muted">فاضي</span>' : e($r['old_value'])) ?></td>
                                    <td class="st-v"><?= $r['new_value'] === '' ? '<span class="a2-muted">فاضي</span>' : e((string) $r['new_value']) ?></td>
                                    <td><?= e($r['admin_name'] ?? '—') ?></td>
                                    <td class="a2-muted" dir="ltr" style="font-size:12px"><?= e((string) $r['source']) ?></td>
                                    <td class="a2-muted" style="white-space:nowrap" title="<?= e($r['created_at']) ?>"><?= e(time_ago($r['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($logTotal > 50): $pages = (int) ceil($logTotal / 50); ?>
                        <div class="pagination"><?php for ($i = 1; $i <= min($pages, 30); $i++): ?><a class="<?= $i === $lpage ? 'active' : '' ?>" href="<?= url('admin/settings.php?tab=log&page=' . $i . ($lq !== '' ? '&q=' . rawurlencode($lq) : '')) ?>"><?= $i ?></a><?php endfor; ?></div>
                    <?php endif; ?>
                    <?php endif; ?>
                </section>

                <!-- تصدير واستيراد -->
                <section class="st-panel" data-panel="backup" <?= $tab === 'backup' ? '' : 'hidden' ?>>
                    <div class="st-panel-h"><h2>تصدير واستيراد</h2><p class="a2-muted">نسخة من الإعدادات في ملف JSON — تنقلها لسيرفر تاني أو ترجّع بيها. <b>الأسرار (المفاتيح والتوكنات وكلمات السر) مابتتصدّرش ولا بتتستورد.</b></p></div>
                    <?php if ($preview): [$chg, $skp] = $preview; ?>
                        <form method="post" class="card">
                            <?= csrf_field() ?><input type="hidden" name="action" value="import_apply">
                            <div class="a2-h"><h3>معاينة الاستيراد</h3><span class="chip <?= $chg ? 'chip-amber' : 'chip-mint' ?>"><?= count($chg) ?> هيتغير</span></div>
                            <?php if (!$chg): ?><div class="a2-muted">الملف مطابق للإعدادات الحالية — مفيش حاجة تتغير.</div><?php else: ?>
                            <div class="a2-tw"><table class="a2-tbl">
                                <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.st-pick').forEach(function(c){c.checked=this.checked}.bind(this))"></th><th>الإعداد</th><th>الحالي</th><th>الجديد</th></tr></thead>
                                <tbody>
                                <?php foreach ($chg as [$k, $old, $new, $lbl, $sens]): ?>
                                    <tr class="<?= $sens ? 'st-sens' : '' ?>"><td><input type="checkbox" class="st-pick" name="keys[]" value="<?= e($k) ?>" <?= $sens ? '' : 'checked' ?>></td>
                                        <td><b><?= e($lbl) ?></b><?= $lbl !== $k ? '<div class="a2-muted" dir="ltr" style="font-size:11.5px">' . e($k) . '</div>' : '' ?><?= $sens ? '<div class="st-sens-n">⚠️ حساس — راجعه قبل ما تعلّم عليه</div>' : '' ?></td>
                                        <td class="st-v"><?= $old === null ? '<span class="a2-muted">مش موجود</span>' : e(mb_strimwidth((string) $old, 0, 160, '…')) ?></td>
                                        <td class="st-v"><?= e(mb_strimwidth((string) $new, 0, 160, '…')) ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table></div>
                            <?php endif; ?>
                            <?php if ($skp): ?><details style="margin-top:10px"><summary class="a2-muted">اتجاهل <?= count($skp) ?> إعداد</summary>
                                <div class="dg-files" style="margin-top:8px"><?php foreach (array_slice($skp, 0, 80) as [$k, $why]): ?><code dir="ltr" title="<?= e($why) ?>"><?= e($k) ?></code><?php endforeach; ?></div></details><?php endif; ?>
                            <div class="a2-row" style="margin-top:14px">
                                <?php if ($chg): ?><button class="btn" type="submit" onclick="return confirm('تطبيق التغييرات المختارة؟')">طبّق المختار</button><?php endif; ?>
                            </div>
                        </form>
                        <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="import_cancel"><button class="btn ghost sm">إلغاء</button></form>
                    <?php else: ?>
                        <div class="a2-grid a2-g2">
                            <div class="card">
                                <div class="a2-h"><h3>⬇ تصدير</h3></div>
                                <p class="a2-muted" style="margin-top:0">الإعدادات العامة (<?= count(settings_export()) ?> إعداد) — من غير أسرار، ولا مسارات ملفات، ولا عناوين الـ API الداخلية.</p>
                                <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="export"><button class="btn" type="submit">تحميل ملف الإعدادات</button></form>
                            </div>
                            <form method="post" enctype="multipart/form-data" class="card">
                                <?= csrf_field() ?><input type="hidden" name="action" value="import_preview">
                                <div class="a2-h"><h3>⬆ استيراد</h3></div>
                                <p class="a2-muted" style="margin-top:0">ارفع ملف اتعمل من «تصدير». هتشوف معاينة باللي هيتغير قبل ما يتطبق.</p>
                                <input class="input" type="file" name="file" accept=".json,application/json" required>
                                <div style="margin-top:10px"><button class="btn soft" type="submit">معاينة</button></div>
                            </form>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>
</div>
<script>
(function () {
    var nav = document.getElementById('stNav'), q = document.getElementById('stQ'), none = document.getElementById('stNone');
    var panels = document.querySelectorAll('.st-panel');
    var cur = <?= json_encode($tab) ?>;
    function show(t) {
        cur = t;
        panels.forEach(function (p) { p.hidden = p.dataset.panel !== t; });
        nav.querySelectorAll('a').forEach(function (a) { a.classList.toggle('on', a.dataset.tab === t); });
    }
    nav.addEventListener('click', function (e) {
        var a = e.target.closest('a[data-tab]');
        if (!a || e.metaKey || e.ctrlKey || (a.dataset.tab === 'log' || a.dataset.tab === 'backup')) return; // السجل والنسخ بيتحمّلوا من السيرفر
        e.preventDefault();
        if (q.value) { q.value = ''; filter(); }
        show(a.dataset.tab);
        try { history.replaceState(null, '', a.href); } catch (x) {}
    });
    function norm(s) { return String(s || '').toLowerCase().replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي'); }
    function filter() {
        var v = norm(q.value.trim());
        document.body.classList.toggle('st-searching', !!v);
        if (!v) {
            document.querySelectorAll('.st-f, .st-sec, .st-link, .st-links, .st-save, .st-panel-h').forEach(function (el) { el.hidden = false; });
            none.hidden = true; show(cur); return;
        }
        var any = false;
        panels.forEach(function (p) {
            if (p.dataset.panel === 'log' || p.dataset.panel === 'backup') { p.hidden = true; return; }
            var pHit = false;
            p.querySelectorAll('.st-sec').forEach(function (sec) {
                var sHit = false;
                sec.querySelectorAll('.st-f').forEach(function (f) { var h = norm(f.dataset.s).indexOf(v) > -1; f.hidden = !h; if (h) sHit = true; });
                sec.hidden = !sHit; if (sHit) pHit = true;
            });
            var lHit = false;
            p.querySelectorAll('.st-link').forEach(function (l) { var h = norm(l.dataset.s).indexOf(v) > -1; l.hidden = !h; if (h) lHit = true; });
            var ls = p.querySelector('.st-links'); if (ls) ls.hidden = !lHit;
            var sv = p.querySelector('.st-save'); if (sv) sv.hidden = !pHit;
            p.hidden = !(pHit || lHit); if (pHit || lHit) any = true;
        });
        none.hidden = any;
        nav.querySelectorAll('a').forEach(function (a) { a.classList.remove('on'); });
    }
    q.addEventListener('input', filter);
    // جاي من البحث العام: settings.php?tab=x#f-key → نوّر الحقل
    if (location.hash && location.hash.indexOf('#f-') === 0) {
        var el = document.getElementById(location.hash.slice(1));
        if (el) { el.classList.add('st-hl'); setTimeout(function () { el.scrollIntoView({ block: 'center' }); var i = el.querySelector('input:not([type=hidden]),select,textarea'); if (i) i.focus({ preventScroll: true }); }, 60); }
    }
})();
</script>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
