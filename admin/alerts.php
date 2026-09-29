<?php
/**
 * الإشعارات والتنبيهات (المرحلة 8-أ) — تنبيهات النظام (جرس + إيميل) وإعداداتها
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/admin-metrics.php';
require_once __DIR__ . '/../includes/admin-ui.php';

require_admin();
require_admin_can('view_costs');
$ready = admin_alerts_ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $back = (string) ($_POST['back'] ?? '');
    // مسار داخلي بس: يبدأ بـ / واحدة، من غير مسافات أو حروف تحكّم أو \\
    $backOk = $back !== '' && (bool) preg_match('#^/(?![/\\\\])[\x21-\x7E]*$#', $back) && !str_contains($back, '\\');

    if ($action === 'read_all') {
        db_run('UPDATE admin_alerts SET read_at = NOW() WHERE read_at IS NULL');
        admin_log('alerts_read_all', 'admin_alerts', null, null);
        flash_set('success', 'كل التنبيهات اتعلّمت كمقروءة');
        if ($backOk) { header('Location: ' . $back); exit; }
        redirect('admin/alerts.php');
    }
    if ($action === 'read') {
        db_run('UPDATE admin_alerts SET read_at = NOW() WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
        redirect('admin/alerts.php' . (!empty($_POST['all']) ? '?all=1' : ''));
    }
    if ($action === 'settings') {
        if (!admin_can('site_settings')) {
            flash_set('danger', 'الإعدادات للأدمن الكامل بس');
            redirect('admin/alerts.php');
        }
        $email = trim((string) ($_POST['alerts_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('danger', 'الإيميل مش صحيح');
            redirect('admin/alerts.php');
        }
        set_setting('alerts_email', $email);
        set_setting('alerts_email_min_level', ($_POST['min_level'] ?? '') === 'critical' ? 'critical' : 'warn');
        admin_log('alerts_settings', 'settings', null, null);
        if (!empty($_POST['send_test']) && $email !== '') {
            admin_alert('warn', 'test', 'تنبيه تجريبي من لوحة الأدمن', 'لو الرسالة دي وصلتك يبقى إيميل التنبيهات شغال ✓', 'admin/alerts.php', 'test:' . time());
            flash_set('success', 'اتحفظ ✓ — واتبعت تنبيه تجريبي على ' . $email);
        } else {
            flash_set('success', 'اتحفظ ✓');
        }
        redirect('admin/alerts.php');
    }
    redirect('admin/alerts.php');
}

$showAll = !empty($_GET['all']);
$rows = $ready ? a2_all('SELECT * FROM admin_alerts ' . ($showAll ? '' : 'WHERE read_at IS NULL ') . 'ORDER BY ' . ($showAll ? 'last_at DESC' : 'FIELD(level, "critical", "warn", "info"), last_at DESC') . ' LIMIT 200') : [];
$lvl = ['critical' => ['chip-coral', '🚨 حرج'], 'warn' => ['chip-amber', '⚠️ تحذير'], 'info' => ['chip-sky', 'ℹ️ معلومة']];

$active = 'alerts';
$page_title = 'الإشعارات والتنبيهات';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="topbar"><button class="menu-toggle" onclick="toggleSidebar()">☰</button></div>
        <?= render_flash() ?>
        <div class="page-head with-actions">
            <div>
                <h1>الإشعارات والتنبيهات</h1>
                <div class="sub">Notifications · أعطال المزودين · التحويل للبديل · أخطاء التكامل — بتظهر في الجرس وبتتبعت على الإيميل</div>
            </div>
            <div class="a2-seg">
                <a href="<?= url('admin/alerts.php') ?>" class="<?= $showAll ? '' : 'on' ?>">المفتوحة</a>
                <a href="<?= url('admin/alerts.php?all=1') ?>" class="<?= $showAll ? 'on' : '' ?>">الكل</a>
            </div>
        </div>

        <?php if (!$ready): ?>
            <div class="alert warning">لازم تشغّل ترحيل «8-أ» الأول من <a href="<?= url('admin/migrations.php') ?>">الترحيلات</a>.</div>
        <?php else: ?>
        <div class="a2-grid a2-side">
            <div class="card" style="padding:0">
                <?php if (!$rows): ?><div class="a2-empty">مفيش تنبيهات <?= $showAll ? '' : 'مفتوحة ' ?>🎉</div><?php else: ?>
                <div class="a2-tw" style="border:0"><table class="a2-tbl"><tbody>
                <?php foreach ($rows as $a): $l = $lvl[$a['level']] ?? $lvl['info']; ?>
                    <tr style="<?= $a['read_at'] ? 'opacity:.6' : '' ?>">
                        <td style="width:110px"><span class="chip <?= $l[0] ?>"><?= $l[1] ?></span></td>
                        <td>
                            <b><?= e($a['title']) ?></b><?= (int) $a['hits'] > 1 ? ' <span class="chip chip-line">×' . (int) $a['hits'] . '</span>' : '' ?>
                            <?php if ($a['body']): ?><div class="a2-muted" style="white-space:pre-line;margin-top:4px"><?= e($a['body']) ?></div><?php endif; ?>
                            <div class="a2-muted" style="margin-top:4px"><?= e(time_ago($a['last_at'])) ?> · أول مرة <?= e($a['created_at']) ?><?= $a['emailed_at'] ? ' · 📧 اتبعت' : '' ?></div>
                        </td>
                        <td style="white-space:nowrap">
                            <?php if ($a['link']): ?><a class="btn sm soft" href="<?= e(url($a['link'])) ?>">افتح</a><?php endif; ?>
                            <?php if (!$a['read_at']): ?>
                            <form method="post" style="display:inline;margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><?php if ($showAll): ?><input type="hidden" name="all" value="1"><?php endif; ?><button class="btn sm ghost" type="submit">تمام</button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
            </div>
            <form method="post" class="card">
                <?= csrf_field() ?><input type="hidden" name="action" value="settings">
                <div class="a2-h"><h3>إيميل التنبيهات</h3></div>
                <p class="a2-muted" style="margin-top:0">الجرس بيظهر كل التنبيهات. الإيميل بيتبعت للتنبيهات المهمة — مرة واحدة في الساعة بحد أقصى لكل تنبيه متكرر.</p>
                <div class="a2-grid" style="gap:10px">
                    <div><label class="a2-lbl">الإيميل</label><input class="input" type="email" name="alerts_email" value="<?= e((string) get_setting('alerts_email', '')) ?>" placeholder="you@company.com" dir="ltr" style="width:100%"></div>
                    <div><label class="a2-lbl">ابعت إيميل لـ</label>
                        <select class="input" name="min_level" style="width:100%">
                            <option value="warn" <?= get_setting('alerts_email_min_level', 'warn') === 'warn' ? 'selected' : '' ?>>التحذيرات والحرج</option>
                            <option value="critical" <?= get_setting('alerts_email_min_level', 'warn') === 'critical' ? 'selected' : '' ?>>الحرج بس (مفتاح/رصيد/مفيش مزود)</option>
                        </select></div>
                    <label style="display:flex;gap:6px;align-items:center;font-size:13px"><input type="checkbox" name="send_test" value="1"> ابعت تنبيه تجريبي بعد الحفظ</label>
                    <div><button class="btn" type="submit" <?= admin_can('site_settings') ? '' : 'disabled' ?>>حفظ</button></div>
                </div>
                <div class="a2-note" style="margin-top:14px">
                    <b>بيتبعت تنبيه لما:</b><br>
                    🚨 مفتاح مزود اترفض أو رصيده خلص · مفيش مزود شغال لمهمة<br>
                    ⚠️ مزود وقف مؤقتًا (أعطال متكررة) · التحويل للبديل زاد عن الحد · كل البدائل فشلت · خطأ تكامل (400) · موديل مش موجود<br>
                    ℹ️ مزود رجع يشتغل
                </div>
            </form>
        </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
