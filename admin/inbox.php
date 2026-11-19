<?php
/**
 * Spread AI — الأدمن: «صندوق الوارد» (خريطة التطوير · المرحلة 3)
 * ردود العملاء على الواتساب اللي لسه محدش اتعامل معاها — المساعد بيشوف حساباته بس.
 * الرد نفسه من «مساحة العميل ← الرسائل». الرسالة بتتعرض كنص (escaped) ومابتتنفّذش.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/messaging.php';

require_admin();
require_admin_can('view_users');

$me = current_admin();
$senior = in_array(($me['role'] ?? 'super'), ['manager', 'super'], true);
$ready = msg_ready();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'toggle_inbound' && $senior && $ready) {
        $new = get_setting('wa_inbound_enabled', '0') === '1' ? '0' : '1';
        set_setting('wa_inbound_enabled', $new);
        admin_log('toggle_wa_inbound', 'settings', null, $new);
        flash_set('success', $new === '1' ? 'استقبال الردود اتفتح ✓' : 'استقبال الردود اتقفل');
    } elseif ($action === 'handled' && $ready) {
        $in = db_one('SELECT * FROM message_inbound WHERE id = ?', [(int) ($_POST['inbound_id'] ?? 0)]);
        if ($in && msg_can_handle($in)) {
            msg_mark_handled((int) $in['id'], (int) $me['id']);
            flash_set('success', 'اتقفلت ✓');
        } else {
            flash_set('danger', 'الرسالة دي مش في حساباتك');
        }
    }
    redirect('admin/inbox.php');
}

$mineOnly = !$senior || isset($_GET['mine']);
$rows = $ready ? msg_unhandled($mineOnly ? (int) $me['id'] : null) : [];
$on = $ready && get_setting('wa_inbound_enabled', '0') === '1';

$active = 'inbox';
$page_title = 'صندوق الوارد';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head with-actions">
            <div>
                <h1>الرسائل 💬</h1>
                <div class="sub">ردود العملاء على الواتساب اللي مستنية حد يتعامل معاها</div>
            </div>
            <?php if ($senior && $ready): ?>
            <div class="a2-row" style="gap:8px;flex-wrap:wrap">
                <a class="btn ghost" href="<?= url('admin/inbox.php' . ($mineOnly ? '' : '?mine=1')) ?>"><?= $mineOnly ? 'كل الرسائل' : 'حساباتي بس' ?></a>
                <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_inbound">
                    <button class="btn <?= $on ? 'ghost' : '' ?>" type="submit"><?= $on ? '⏸ اقفل استقبال الردود' : '▶ افتح استقبال الردود' ?></button></form>
            </div>
            <?php endif; ?>
        </div>

        <?php $msgTab = 'inbox'; include __DIR__ . '/../templates/admin-messages-tabs.php'; ?>
        <?= render_flash() ?>

        <?php if (!$ready): ?>
            <div class="alert warning">شغّل ترحيل <b dir="ltr">2026_messages_inbound.sql</b> من «فحص التحديث» الأول.</div>
        <?php else: ?>
            <div class="alert <?= $on ? 'success' : 'warning' ?>">
                <?php if ($on): ?>استقبال الردود <b>شغال</b> — n8n بيبعت رد العميل على:
                <?php else: ?>استقبال الردود <b>مقفول</b>. لما تجهّز الـ workflow في n8n افتحه. العنوان:<?php endif; ?>
                <code dir="ltr" style="user-select:all"><?= e(rtrim(APP_URL, '/') . '/api/wa/inbound.php') ?></code>
                <div style="font-size:12px;margin-top:6px">POST · Header <code dir="ltr">X-Spread-Key</code> (نفس مفتاح الواتساب) · Body <code dir="ltr">{"phone":"2010…","text":"…","id":"…"}</code> · الكلمات اللي بتتنفّذ: «موافق» و«إيقاف» بس.</div>
            </div>
            <div class="card">
                <?php if (!$rows): ?>
                    <div class="empty" style="padding:28px;text-align:center">مفيش رسائل مستنية ✓</div>
                <?php else: ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>العميل</th><th>الرسالة</th><th>الوقت</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <?php if ($r['user_id']): ?><b><?= e((string) $r['user_name']) ?></b><?php else: ?><span class="text-mute">رقم مش مربوط بعميل</span><?php endif; ?>
                                <div class="text-mute" dir="ltr" style="font-size:12px;text-align:start"><?= e($r['wa_phone']) ?></div>
                                <?php if ($r['manager_name']): ?><div class="text-mute" style="font-size:11.5px">المسؤول: <?= e($r['manager_name']) ?></div><?php endif; ?>
                            </td>
                            <td style="max-width:460px;white-space:pre-wrap;overflow-wrap:anywhere"><?= e(mb_substr((string) $r['body'], 0, 600)) ?>
                                <?php if ($r['action_result']): ?><div class="text-mute" style="font-size:12px"><?= e($r['action_result']) ?></div><?php endif; ?></td>
                            <td class="text-mute" style="white-space:nowrap"><?= e(substr((string) $r['created_at'], 0, 16)) ?></td>
                            <td style="white-space:nowrap">
                                <?php if ($r['managed']): ?><a class="btn sm" href="<?= url('admin/workspace.php?id=' . (int) $r['user_id'] . '&tab=messages') ?>">رد ←</a>
                                <?php elseif ($r['user_id']): ?><a class="btn sm ghost" href="<?= url('admin/user-view.php?id=' . (int) $r['user_id']) ?>">ملف العميل</a><?php endif; ?>
                                <form method="POST" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="handled"><input type="hidden" name="inbound_id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn sm ghost" type="submit">اتقفلت ✓</button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
