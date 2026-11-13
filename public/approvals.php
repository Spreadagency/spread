<?php
/**
 * Spread AI — «موافقاتي» (خريطة التطوير · المرحلة 2)
 * المنشورات اللي الفريق جهّزها ومستنية موافقة العميل — زرارين: موافق / عايز تعديل.
 * بتتفتح بطريقتين:
 *   1) العميل داخل بحسابه (الجرس ← «منشورات جاهزة لمراجعتك»)
 *   2) لينك موقّع صالح 72 ساعة (?t=…) — بيفتح الصفحة دي بس، من غير دخول للحساب
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/rate-limit.php';
require_once __DIR__ . '/../includes/managed-accounts.php';
require_once __DIR__ . '/../includes/content-formats.php';

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$viaToken = false;
$uid = 0;
if ($token !== '') {
    if (!rate_limit('ma_token', client_ip(), 40, 600)) {
        http_response_code(429);
        exit('محاولات كتير — استنى شوية وجرّب تاني');
    }
    $uid = ma_token_uid($token);
    $viaToken = $uid > 0;
}
if (!$viaToken) {
    if ($token !== '' && !is_logged_in()) {
        flash_set('warning', 'لينك الموافقة انتهى — سجّل دخولك وهتلاقي المنشورات في «موافقاتي»');
    }
    require_login();
    $uid = (int) current_user()['id'];
}
$owner = db_one('SELECT id, name, status FROM users WHERE id = ?', [$uid]);
if (!$owner || $owner['status'] !== 'active' || !ma_ready()) {
    redirect($viaToken ? 'login.php' : 'dashboard.php');
}
$self = 'approvals.php' . ($viaToken ? '?t=' . urlencode($token) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!rate_limit('ma_decide', 'u' . $uid, 120, 600)) {
        flash_set('warning', 'استنى شوية وجرّب تاني');
        redirect($self);
    }
    $decision = ($_POST['decision'] ?? '') === 'changes' ? 'changes' : 'approve';
    $note = (string) ($_POST['note'] ?? '');
    if (!empty($_POST['all'])) {
        $n = 0;
        foreach (ma_pending_for_user($uid) as $p) if (ma_client_decide($uid, (int) $p['id'], 'approve')) $n++;
        flash_set($n ? 'success' : 'warning', $n ? 'وافقت على ' . $n . ' منشور ✓ — الفريق هيجدولهم.' : 'مفيش منشورات مستنية');
    } elseif ($decision === 'changes' && trim($note) === '') {
        flash_set('warning', 'اكتب التعديل اللي عايزه في كلمتين');
    } elseif (ma_client_decide($uid, (int) ($_POST['content_id'] ?? 0), $decision, $note)) {
        flash_set('success', $decision === 'approve' ? 'تمام — وافقت على المنشور ✓' : 'وصل طلب التعديل للفريق ✓');
    } else {
        flash_set('warning', 'المنشور ده مش مستني موافقة');
    }
    redirect($self);
}

$pending = ma_pending_for_user($uid);
$designs = [];
if ($pending) {
    $in = implode(',', array_map(fn($p) => (int) $p['id'], $pending));
    foreach (db_all("SELECT content_id, image_path FROM content_designs WHERE content_id IN ($in) AND user_id = ? ORDER BY slide_no, id DESC", [$uid]) as $d) {
        $designs[(int) $d['content_id']] = $designs[(int) $d['content_id']] ?? $d['image_path'];
    }
}

$page_title = 'موافقاتي';
if ($viaToken) { $__no_tabbar = true; $__no_assistant = true; }
include __DIR__ . '/../templates/header.php';
?>
<div class="<?= $viaToken ? '' : 'app' ?>">
    <?php if (!$viaToken) include __DIR__ . '/../templates/sidebar.php'; ?>
    <main class="main" style="<?= $viaToken ? 'max-width:640px;margin:0 auto;padding:20px 16px' : '' ?>">
        <?php if (!$viaToken) include __DIR__ . '/../templates/topbar.php'; ?>
        <div class="page-head">
            <h1>موافقاتي ✅</h1>
            <div class="sub"><?= $viaToken ? 'أهلًا ' . e(explode(' ', trim((string) $owner['name']))[0]) . ' — ' : '' ?>المنشورات اللي فريق Spread جهّزها ليك. مفيش حاجة هتتنشر قبل ما توافق.</div>
        </div>

        <?= render_flash() ?>

        <?php if (!$pending): ?>
            <div class="card"><div class="empty" style="padding:30px;text-align:center">مفيش منشورات مستنية موافقتك دلوقتي ✓</div></div>
        <?php else: ?>
            <?php if (count($pending) > 1): ?>
            <form method="POST" style="margin-bottom:14px">
                <?= csrf_field() ?>
                <?php if ($viaToken): ?><input type="hidden" name="t" value="<?= e($token) ?>"><?php endif; ?>
                <input type="hidden" name="all" value="1">
                <button class="btn lg full" type="submit">موافق على الكل (<?= count($pending) ?>)</button>
            </form>
            <?php endif; ?>
            <?php foreach ($pending as $p): $cid = (int) $p['id']; ?>
                <div class="card" style="padding:16px;margin-bottom:14px" id="c<?= $cid ?>">
                    <div class="text-mute" style="font-size:12px;margin-bottom:8px">
                        <?= e(content_format_label(content_format_key($p['format'] ?? 'post'))) ?>
                        <?= $p['scheduled_at'] ? ' · المعاد المقترح ' . e(substr((string) $p['scheduled_at'], 0, 16)) : '' ?>
                    </div>
                    <?php if (!empty($designs[$cid])): ?>
                        <img src="<?= e(upload_url($designs[$cid])) ?>" alt="تصميم المنشور" loading="lazy" style="max-width:100%;border-radius:12px;margin-bottom:10px">
                    <?php endif; ?>
                    <div style="white-space:pre-wrap;line-height:1.9;font-size:14.5px"><?= e(trim((string) $p['generated_text'])) ?></div>
                    <?php if (trim((string) $p['cta']) !== ''): ?><div style="margin-top:8px;font-weight:600"><?= e($p['cta']) ?></div><?php endif; ?>
                    <?php if (trim((string) $p['hashtags']) !== ''): ?><div class="text-mute" style="margin-top:6px;font-size:13px"><?= e($p['hashtags']) ?></div><?php endif; ?>
                    <form method="POST" style="margin-top:14px">
                        <?= csrf_field() ?>
                        <?php if ($viaToken): ?><input type="hidden" name="t" value="<?= e($token) ?>"><?php endif; ?>
                        <input type="hidden" name="content_id" value="<?= $cid ?>">
                        <div style="display:flex;gap:8px">
                            <button class="btn" style="flex:1" type="submit" name="decision" value="approve">موافق ✓</button>
                            <button class="btn ghost" style="flex:1" type="button" onclick="var b=document.getElementById('n<?= $cid ?>');b.hidden=!b.hidden;if(!b.hidden)b.querySelector('textarea').focus()">عايز تعديل</button>
                        </div>
                        <div id="n<?= $cid ?>" hidden style="margin-top:10px">
                            <textarea name="note" class="textarea" rows="2" maxlength="500" placeholder="إيه اللي عايز تغيّره؟"></textarea>
                            <button class="btn ghost full" style="margin-top:8px" type="submit" name="decision" value="changes">ابعت التعديل للفريق</button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/footer.php'; ?>
