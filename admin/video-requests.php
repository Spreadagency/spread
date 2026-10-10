<?php
/**
 * Spread AI v2 — الأدمن: طلبات تنفيذ الفيديو (⑦-ج)
 * الـ AI بيجهّز السكريبت ← العميل يعتمد ← يبعت الطلب على واتساب ← فريقنا ينفّذ يدوي ← جاهز ← اتسلّم
 * هنا الفريق بيتابع كل طلب وبيحرّك حالته (والعميل بيشوفها في المكتبة + جرس «الفيديو جاهز»)
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/content-formats.php';

require_admin();
require_admin_can('view_content');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $c = db_one('SELECT id, format, video_status FROM contents WHERE id = ?', [$id]);
        $back = ($_POST['back'] ?? '') === 'content' ? 'admin/content-view.php?id=' . $id . '#format' : 'admin/video-requests.php' . (!empty($_POST['f']) ? '?f=' . urlencode((string) $_POST['f']) : '');
        if (!$c || $c['format'] !== 'video') {
            flash_set('danger', 'الطلب مش موجود');
            redirect('admin/video-requests.php');
        }
        $st = (string) ($_POST['status'] ?? '');
        if (!isset(video_statuses()[$st])) {
            flash_set('danger', 'حالة غير معروفة');
            redirect($back);
        }
        $urlIn = trim((string) ($_POST['delivery_url'] ?? ''));
        if ($urlIn !== '' && !preg_match('#^https?://#i', $urlIn)) {
            flash_set('danger', 'رابط الفيديو لازم يبدأ بـ https://');
            redirect($back);
        }
        if ($st === 'ready' && $urlIn === '') {
            flash_set('warning', 'اتحفظ «جاهز» من غير رابط — العميل مش هيلاقي الفيديو. ضيف الرابط لو متاح.');
        }
        db_run('UPDATE contents SET video_status = ?, video_status_at = IF(video_status = ?, video_status_at, NOW()), video_delivery_url = ?, video_admin_note = ? WHERE id = ?',
            [$st, $st, $urlIn !== '' ? mb_substr($urlIn, 0, 500) : null, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500) ?: null, $id]);
        admin_log('video_status', 'content', $id, ($c['video_status'] ?: 'script') . ' → ' . $st);
        flash_set('success', 'اتحدثت حالة الفيديو #' . $id . ' ✓');
        redirect($back);
    }

    if ($action === 'settings' && admin_can('site_settings')) {
        $n = preg_replace('/\D+/', '', (string) ($_POST['video_whatsapp'] ?? ''));
        if ($n !== '' && strlen($n) < 8) {
            flash_set('danger', 'الرقم لازم يكون بالكود الدولي (مثال 2010xxxxxxxx)');
            redirect('admin/video-requests.php');
        }
        set_setting('video_whatsapp', $n);
        admin_log('video_whatsapp', 'settings', null, $n);
        flash_set('success', 'اتحفظ رقم واتساب التنفيذ ✓');
        redirect('admin/video-requests.php');
    }
}

$filters = ['open' => 'المفتوحة', 'sent' => 'اتبعت للتنفيذ', 'in_production' => 'قيد التنفيذ', 'ready' => 'جاهز', 'delivered' => 'اتسلّم', 'script' => 'سكريبت لسه', 'all' => 'الكل'];
$f = isset($filters[$_GET['f'] ?? '']) ? $_GET['f'] : 'open';
$where = match ($f) {
    'open' => 'c.video_status IN ("approved","sent","in_production","ready")',
    'all' => '1',
    'script' => 'c.video_status IN ("script","approved") OR c.video_status IS NULL',
    default => 'c.video_status = ' . db()->quote($f),
};
$rows = db_all("SELECT c.id, c.user_id, c.video_status, c.video_status_at, c.video_brief_json, c.video_delivery_url, c.video_admin_note, c.created_at,
                       u.name user_name, u.email user_email,
                       (SELECT b.business_name FROM brand_profiles b WHERE b.user_id = c.user_id ORDER BY b.id LIMIT 1) business_name
                FROM contents c JOIN users u ON u.id = c.user_id
                WHERE c.format = 'video' AND ({$where})
                ORDER BY FIELD(c.video_status, 'sent', 'in_production', 'approved', 'ready', 'script', 'delivered'), c.video_status_at DESC LIMIT 200");
$counts = [];
foreach (db_all('SELECT COALESCE(video_status, "script") s, COUNT(*) n FROM contents WHERE format = "video" GROUP BY s') as $r) $counts[$r['s']] = (int) $r['n'];

$active = 'video-requests';
$page_title = 'طلبات الفيديو';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>🎬 طلبات تنفيذ الفيديو</h1>
            <div class="sub">الـ AI بيكتب السكريبت · العميل يعتمده ويبعت الطلب على واتساب · الفريق ينفّذ يدوي ويحدّث الحالة هنا</div>
        </div>

        <?= render_flash() ?>

        <div class="kpis">
            <?php foreach (['sent' => ['📨', 'اتبعت للتنفيذ'], 'in_production' => ['🎬', 'قيد التنفيذ'], 'ready' => ['✅', 'جاهز'], 'delivered' => ['📦', 'اتسلّم']] as $k => [$ic, $l]): ?>
                <a class="kpi" href="?f=<?= $k ?>" style="text-decoration:none;color:inherit">
                    <div class="ico" style="background:var(--primary-soft);color:var(--primary-ink)"><?= $ic ?></div>
                    <div><b><?= (int) ($counts[$k] ?? 0) ?></b><small><?= $l ?></small></div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="card" style="margin-top:16px">
            <div class="card-head" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <h3 style="margin-inline-end:auto">الطلبات</h3>
                <?php foreach ($filters as $k => $l): ?>
                    <a href="?f=<?= $k ?>" class="chip <?= $f === $k ? 'chip-primary' : 'chip-line' ?>"><?= e($l) ?></a>
                <?php endforeach; ?>
            </div>
            <?php if (!$rows): ?><p class="sub" style="text-align:center;padding:20px">مفيش طلبات هنا.</p><?php endif; ?>
            <div style="display:grid;gap:12px">
                <?php foreach ($rows as $r): $b = video_brief($r); $vs = video_status_meta($r['video_status']); ?>
                    <div style="border:1px solid var(--line);border-radius:14px;padding:14px;display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:14px">
                        <div style="min-width:0">
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                <b>#<?= (int) $r['id'] ?></b>
                                <span class="chip <?= in_array($vs['key'], ['ready', 'delivered'], true) ? 'chip-mint' : ($vs['key'] === 'in_production' ? 'chip-amber' : 'chip-primary') ?>"><?= e($vs['label']) ?></span>
                                <a href="<?= url('admin/user-view.php?id=' . (int) $r['user_id']) ?>"><?= e($r['user_name']) ?></a>
                                <span class="text-mute">· <?= e($r['business_name'] ?? '') ?></span>
                                <span class="text-mute" style="margin-inline-start:auto;font-size:11.5px"><?= $r['video_status_at'] ? e(time_ago($r['video_status_at'])) : '' ?></span>
                            </div>
                            <div class="text-mute" style="font-size:12px;margin:6px 0"><?= e($b['type']) ?> · <?= e($b['duration']) ?> · <?= e($b['platform']) ?> · <?= e($b['ratio']) ?></div>
                            <details><summary style="cursor:pointer;font-size:12.5px">السكريبت<?= $b['notes'] !== '' ? ' + ملاحظات العميل' : '' ?></summary>
                                <div style="white-space:pre-wrap;font-size:12.5px;line-height:1.8;background:var(--surface-2);border-radius:10px;padding:10px;margin-top:6px"><?= e(video_script_text($b)) ?></div>
                                <?php if ($b['notes'] !== ''): ?><div class="cta-box" style="margin-top:6px"><?= e($b['notes']) ?></div><?php endif; ?>
                            </details>
                            <a href="<?= url('admin/content-view.php?id=' . (int) $r['id'] . '#format') ?>" class="btn ghost sm" style="margin-top:8px">فتح المحتوى</a>
                        </div>
                        <form method="POST" style="display:grid;gap:6px;align-content:start">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="f" value="<?= e($f) ?>">
                            <select name="status" class="select">
                                <?php foreach (video_statuses() as $k => [$l]): ?><option value="<?= $k ?>" <?= $k === $vs['key'] ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                            </select>
                            <input name="delivery_url" class="input" dir="ltr" placeholder="رابط الفيديو النهائي" value="<?= e($r['video_delivery_url'] ?? '') ?>">
                            <input name="note" class="input" maxlength="500" placeholder="ملاحظة للعميل (اختياري)" value="<?= e($r['video_admin_note'] ?? '') ?>">
                            <button class="btn sm">حفظ</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (admin_can('site_settings')): ?>
        <div class="card" style="margin-top:16px;max-width:560px">
            <div class="card-head"><h3>📲 رقم واتساب التنفيذ</h3></div>
            <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="settings">
                <div class="field" style="flex:1;min-width:220px;margin:0">
                    <label>الرقم بالكود الدولي</label>
                    <input name="video_whatsapp" class="input" dir="ltr" placeholder="2010xxxxxxxx" value="<?= e((string) get_setting('video_whatsapp', '')) ?>">
                    <div class="field-help">فاضي = رقم الواتساب العائم أو رقم الدفع. الرقم المستخدم دلوقتي: <b dir="ltr"><?= e(video_whatsapp_number() ?: '✕ مش مضبوط') ?></b></div>
                </div>
                <button class="btn">حفظ</button>
            </form>
        </div>
        <?php endif; ?>
    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
