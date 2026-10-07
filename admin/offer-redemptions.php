<?php
/** Spread AI v2 — الأدمن: سجل استخدام العروض + الاسترجاع */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/offers.php';

require_admin();
require_admin_can('manage_packages');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    if (($_POST['action'] ?? '') === 'reverse') {
        $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 191);
        if ($reason === '') {
            flash_set('danger', 'اكتب سبب الاسترجاع');
        } else {
            $res = reverse_redemption((int) $_POST['redemption_id'], $reason, current_admin()['id']);
            admin_log('reverse_redemption', 'redemption', (int) $_POST['redemption_id'], $reason);
            flash_set($res['ok'] ? 'success' : 'danger',
                $res['ok'] ? 'تم الاسترجاع — اتخصم ' . $res['credits'] . ' كريدت' : ($res['message'] ?? 'فشل'));
        }
    }
    redirect('admin/offer-redemptions.php' . (!empty($_POST['back_offer']) ? '?offer=' . (int) $_POST['back_offer'] : ''));
}

$offerId = (int) ($_GET['offer'] ?? 0);
$status  = $_GET['status'] ?? '';

$w = ['1=1']; $p = [];
if ($offerId) { $w[] = 'r.offer_id = ?'; $p[] = $offerId; }
if ($status === 'granted' || $status === 'reversed') { $w[] = 'r.status = ?'; $p[] = $status; }

// تصدير CSV
if (($_GET['export'] ?? '') === 'csv') {
    $rows = db_all('SELECT r.*, o.code, u.name, u.email FROM offer_redemptions r
                    LEFT JOIN offers o ON o.id = r.offer_id LEFT JOIN users u ON u.id = r.user_id
                    WHERE ' . implode(' AND ', $w) . ' ORDER BY r.id DESC LIMIT 5000', $p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=redemptions-' . date('Ymd') . '.csv');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'الكود', 'المستخدم', 'الإيميل', 'الدور', 'الكريدت', 'الحالة', 'IP', 'التاريخ']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['code'], $r['name'], $r['email'],
            $r['role'] === 'referrer' ? 'مُحيل' : 'جديد', $r['credits_given'],
            $r['status'] === 'granted' ? 'ممنوح' : 'مسترجع', $r['ip'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

$rows = db_all('SELECT r.*, o.code, o.title, u.name, u.email FROM offer_redemptions r
                LEFT JOIN offers o ON o.id = r.offer_id LEFT JOIN users u ON u.id = r.user_id
                WHERE ' . implode(' AND ', $w) . ' ORDER BY r.id DESC LIMIT 300', $p);
$offer = $offerId ? db_one('SELECT * FROM offers WHERE id = ?', [$offerId]) : null;
$tot = db_one('SELECT COUNT(*) c, SUM(status="granted") g, SUM(CASE WHEN status="granted" THEN credits_given ELSE 0 END) cr
               FROM offer_redemptions r WHERE ' . implode(' AND ', $w), $p) ?: [];

$active = 'offers';
$page_title = 'سجل استخدام العروض';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>سجل الاستخدام 📊<?= $offer ? ' — ' . e($offer['code']) : '' ?></h1>
            <div class="sub">
                <?= (int) ($tot['c'] ?? 0) ?> واقعة · <?= (int) ($tot['g'] ?? 0) ?> ممنوحة ·
                <?= number_format((int) ($tot['cr'] ?? 0)) ?> كريدت مصروف
            </div>
        </div>
        <?= render_flash() ?>

        <div class="card" style="margin-bottom:16px">
            <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
                <?php if ($offerId): ?><input type="hidden" name="offer" value="<?= $offerId ?>"><?php endif; ?>
                <div class="field" style="margin:0">
                    <label>الحالة</label>
                    <select name="status" class="input">
                        <option value="">الكل</option>
                        <option value="granted"  <?= $status === 'granted' ? 'selected' : '' ?>>ممنوح</option>
                        <option value="reversed" <?= $status === 'reversed' ? 'selected' : '' ?>>مسترجع</option>
                    </select>
                </div>
                <button class="btn">فلترة</button>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn ghost">⬇ تصدير CSV</a>
                <a href="<?= url('admin/offers.php') ?>" class="btn ghost">رجوع للعروض</a>
            </form>
        </div>

        <div class="card">
            <?php if (!$rows): ?><p class="sub">مفيش وقائع</p><?php else: ?>
            <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>العرض</th><th>المستخدم</th><th>الدور</th><th>كريدت</th><th>IP</th><th>التاريخ</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr style="<?= $r['status'] === 'granted' ? '' : 'opacity:.55' ?>">
                        <td><?= (int) $r['id'] ?></td>
                        <td><code dir="ltr"><?= e($r['code'] ?? '—') ?></code></td>
                        <td><?= e($r['name'] ?? '#' . $r['user_id']) ?><div class="sub" style="font-size:11px"><?= e($r['email'] ?? '') ?></div></td>
                        <td><?= $r['role'] === 'referrer' ? '🔗 مُحيل' : '🎁 جديد' ?></td>
                        <td><b><?= (int) $r['credits_given'] ?></b> ◇</td>
                        <td class="sub" style="font-size:11.5px" dir="ltr"><?= e($r['ip'] ?? '') ?></td>
                        <td class="sub" style="font-size:11.5px"><?= e(fmt_date($r['created_at'], true)) ?></td>
                        <td>
                            <span class="chip <?= $r['status'] === 'granted' ? 'chip-primary' : '' ?>"><?= $r['status'] === 'granted' ? 'ممنوح' : 'مسترجع' ?></span>
                            <?php if ($r['reversed_reason']): ?><div class="sub" style="font-size:10.5px"><?= e($r['reversed_reason']) ?></div><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['status'] === 'granted'): ?>
                                <button type="button" class="btn ghost sm" style="color:#c0392b"
                                        onclick="rev(<?= (int) $r['id'] ?>, '<?= e($r['name'] ?? '') ?>', <?= (int) $r['credits_given'] ?>)">↩ استرجاع</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <div id="rev-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:900;align-items:center;justify-content:center;padding:20px">
            <form class="card" method="POST" data-safe-post style="max-width:430px;width:100%">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reverse">
                <input type="hidden" name="redemption_id" id="rev-id">
                <input type="hidden" name="back_offer" value="<?= $offerId ?>">
                <div class="card-head"><h3>↩ استرجاع مكافأة</h3></div>
                <p id="rev-info" class="sub" style="font-size:13.5px"></p>
                <div class="field">
                    <label>سبب الاسترجاع <span class="req">*</span></label>
                    <input type="text" name="reason" class="input" required placeholder="اشتراك ملغي / إحالة وهمية">
                </div>
                <p class="field-help">الكريدت هيتخصم بقيد عكسي — مفيش مسح، السجل بيفضل للتدقيق.</p>
                <button class="btn" style="background:#c0392b">تأكيد الاسترجاع</button>
                <button type="button" class="btn ghost" onclick="document.getElementById('rev-modal').style.display='none'">إلغاء</button>
            </form>
        </div>

        <script>
        function rev(id, name, credits) {
            document.getElementById('rev-id').value = id;
            document.getElementById('rev-info').textContent = 'هيتخصم ' + credits + ' كريدت من ' + (name || 'المستخدم') + '.';
            document.getElementById('rev-modal').style.display = 'flex';
        }
        </script>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
