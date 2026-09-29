<?php
/**
 * Spread AI v2 — الأدمن: قائمة العروض
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/offers.php';

require_admin();
require_admin_can('manage_packages');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int) ($_POST['offer_id'] ?? 0);

    if (($_POST['action'] ?? '') === 'toggle') {
        db_run('UPDATE offers SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?', [$id]);
        admin_log('toggle_offer', 'offer', $id);
        flash_set('success', 'تم تحديث حالة العرض');
    }

    if (($_POST['action'] ?? '') === 'duplicate') {
        $o = db_one('SELECT * FROM offers WHERE id = ?', [$id]);
        if ($o) {
            $new = offer_generate_code(6);
            db_insert(
                'INSERT INTO offers (code, title, description, type, offer_group, referrer_credits, referee_credits,
                    credit_validity_days, trigger_event, priority, stackable, rules_json, starts_at, expires_at,
                    max_uses, is_active, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)',
                [$new, $o['title'] . ' (نسخة)', $o['description'], $o['type'], $o['offer_group'],
                 $o['referrer_credits'], $o['referee_credits'], $o['credit_validity_days'], $o['trigger_event'],
                 $o['priority'], $o['stackable'], $o['rules_json'], $o['starts_at'], $o['expires_at'],
                 $o['max_uses'], current_admin()['id']]
            );
            flash_set('success', 'تم نسخ العرض بكود ' . $new . ' (موقوف — فعّله بعد المراجعة)');
        }
    }

    if (($_POST['action'] ?? '') === 'delete') {
        require_admin_can('delete_user');
        $used = (int) (db_one('SELECT COUNT(*) c FROM offer_redemptions WHERE offer_id = ?', [$id])['c'] ?? 0);
        if ($used > 0) {
            flash_set('danger', 'مينفعش تحذف عرض عليه ' . $used . ' استخدام — أوقفه بدل الحذف');
        } else {
            db_run('DELETE FROM offers WHERE id = ?', [$id]);
            admin_log('delete_offer', 'offer', $id);
            flash_set('success', 'تم حذف العرض');
        }
    }
    redirect('admin/offers.php');
}

$type   = in_array($_GET['type'] ?? '', ['referral', 'campaign', 'promo'], true) ? $_GET['type'] : '';
$status = $_GET['status'] ?? '';
$q      = trim((string) ($_GET['q'] ?? ''));

$where = ['1=1'];
$params = [];
if ($type)   { $where[] = 'o.type = ?';      $params[] = $type; }
if ($status === 'active')   { $where[] = 'o.is_active = 1'; }
if ($status === 'inactive') { $where[] = 'o.is_active = 0'; }
if ($status === 'expired')  { $where[] = 'o.expires_at IS NOT NULL AND o.expires_at < NOW()'; }
if ($status === 'ending')   { $where[] = 'o.max_uses IS NOT NULL AND o.used_count >= o.max_uses * 0.8'; }
if ($q !== '') {
    $where[] = '(o.code LIKE ? OR o.title LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$offers = db_all(
    'SELECT o.*, u.name AS owner_name FROM offers o
     LEFT JOIN users u ON u.id = o.owner_user_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY o.is_active DESC, o.id DESC LIMIT 200',
    $params
);

$stats = db_one('SELECT COUNT(*) total,
    SUM(is_active = 1) active,
    SUM(type = "referral") refs,
    SUM(used_count) uses,
    SUM(credits_spent) spent FROM offers') ?: [];

$typeLabels = ['referral' => '🔗 أفلييت', 'campaign' => '📣 حملة', 'promo' => '🎁 عرض'];

$active = 'offers';
$page_title = 'العروض والأفلييت';
include __DIR__ . '/../templates/admin-header.php';
?>

<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head">
            <h1>العروض والأفلييت 🎟</h1>
            <div class="sub">أكواد الدعوة وحملات الكريدت والعروض — كلها من هنا</div>
        </div>

        <?= render_flash() ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px">
            <?php foreach ([
                ['🎟', 'كل العروض', (int) ($stats['total'] ?? 0)],
                ['✅', 'شغّالة', (int) ($stats['active'] ?? 0)],
                ['🔗', 'أكواد أفلييت', (int) ($stats['refs'] ?? 0)],
                ['📊', 'مرات الاستخدام', (int) ($stats['uses'] ?? 0)],
                ['◇', 'كريدت مصروف', (int) ($stats['spent'] ?? 0)],
            ] as [$ic, $lb, $v]): ?>
                <div class="card" style="margin:0;display:flex;gap:11px;align-items:center">
                    <span style="font-size:23px"><?= $ic ?></span>
                    <span><b style="font-size:20px;font-family:Almarai"><?= number_format($v) ?></b>
                        <br><span style="font-size:12px;color:var(--mute)"><?= e($lb) ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card" style="margin-bottom:16px">
            <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
                <div class="field" style="margin:0;flex:1;min-width:170px">
                    <label>بحث</label>
                    <input type="text" name="q" class="input" value="<?= e($q) ?>" placeholder="كود أو عنوان">
                </div>
                <div class="field" style="margin:0">
                    <label>النوع</label>
                    <select name="type" class="input">
                        <option value="">الكل</option>
                        <?php foreach ($typeLabels as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="margin:0">
                    <label>الحالة</label>
                    <select name="status" class="input">
                        <option value="">الكل</option>
                        <option value="active"   <?= $status === 'active' ? 'selected' : '' ?>>شغّال</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>موقوف</option>
                        <option value="expired"  <?= $status === 'expired' ? 'selected' : '' ?>>منتهي</option>
                        <option value="ending"   <?= $status === 'ending' ? 'selected' : '' ?>>أوشك على النفاد</option>
                    </select>
                </div>
                <button class="btn">🔍 فلترة</button>
                <a href="<?= url('admin/offer-edit.php') ?>" class="btn">＋ عرض جديد</a>
                <a href="<?= url('admin/offer-test.php') ?>" class="btn ghost">🔧 اختبار عرض</a>
                <a href="<?= url('admin/offer-settings.php') ?>" class="btn ghost">⚙️ الإعدادات</a>
            </form>
        </div>

        <div class="card">
            <div class="card-head"><h3>العروض (<?= count($offers) ?>)</h3></div>
            <?php if (!$offers): ?>
                <p class="sub">مفيش عروض — <a href="<?= url('admin/offer-edit.php') ?>" style="color:var(--primary)">أضف أول عرض</a></p>
            <?php else: ?>
            <div class="table-wrap">
            <table class="table">
                <thead><tr>
                    <th>الكود</th><th>العرض</th><th>الكريدت</th><th>الاستخدام</th>
                    <th>مصروف</th><th>الفترة</th><th>الحالة</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($offers as $o):
                    $expired = !empty($o['expires_at']) && $o['expires_at'] < date('Y-m-d H:i:s');
                    $pct = $o['max_uses'] ? min(100, round($o['used_count'] / max(1, (int) $o['max_uses']) * 100)) : 0;
                    $link = rtrim(APP_URL, '/') . '/register.php?ref=' . urlencode($o['code']);
                ?>
                    <tr style="<?= $o['is_active'] && !$expired ? '' : 'opacity:.55' ?>">
                        <td>
                            <code style="font-weight:700;font-size:13px" dir="ltr"><?= e($o['code']) ?></code>
                            <button type="button" class="btn ghost sm" style="padding:3px 7px"
                                    onclick="navigator.clipboard.writeText('<?= e($link) ?>');this.textContent='✓'">📋</button>
                        </td>
                        <td>
                            <b><?= e($o['title']) ?></b>
                            <div class="sub" style="font-size:11px">
                                <?= e($typeLabels[$o['type']] ?? $o['type']) ?>
                                <?= $o['owner_name'] ? ' · ' . e($o['owner_name']) : '' ?>
                                <?= $o['offer_group'] ? ' · مجموعة: ' . e($o['offer_group']) : '' ?>
                            </div>
                        </td>
                        <td style="font-size:12.5px;white-space:nowrap">
                            <?php if ((int) $o['referrer_credits'] > 0): ?>مُحيل: <b><?= (int) $o['referrer_credits'] ?></b><br><?php endif; ?>
                            جديد: <b><?= (int) $o['referee_credits'] ?></b>
                        </td>
                        <td style="min-width:96px">
                            <?= (int) $o['used_count'] ?><?= $o['max_uses'] ? ' / ' . (int) $o['max_uses'] : '' ?>
                            <?php if ($o['max_uses']): ?>
                                <div style="height:5px;background:var(--line);border-radius:9px;margin-top:4px;overflow:hidden">
                                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct >= 90 ? '#c0392b' : 'var(--primary)' ?>"></div>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format((int) $o['credits_spent']) ?> ◇</td>
                        <td style="font-size:11.5px;white-space:nowrap">
                            <?= $o['starts_at'] ? e(substr($o['starts_at'], 0, 10)) : '—' ?><br>
                            <?= $o['expires_at'] ? e(substr($o['expires_at'], 0, 10)) : 'بلا نهاية' ?>
                            <?php if ($expired): ?><br><span style="color:#c0392b">منتهي</span><?php endif; ?>
                        </td>
                        <td>
                            <span class="chip <?= $o['is_active'] ? 'chip-primary' : '' ?>"><?= $o['is_active'] ? 'شغّال' : 'موقوف' ?></span>
                        </td>
                        <td style="white-space:nowrap">
                            <a href="<?= url('admin/offer-edit.php?id=' . $o['id']) ?>" class="btn ghost sm">✎</a>
                            <a href="<?= url('admin/offer-redemptions.php?offer=' . $o['id']) ?>" class="btn ghost sm" title="سجل الاستخدام">📊</a>
                            <a href="<?= url('admin/offer-test.php?code=' . urlencode($o['code'])) ?>" class="btn ghost sm" title="اختبار">🔧</a>
                            <button type="submit" form="tg-<?= $o['id'] ?>" class="btn ghost sm"><?= $o['is_active'] ? '🚫' : '✔' ?></button>
                            <button type="submit" form="dp-<?= $o['id'] ?>" class="btn ghost sm" title="نسخ">⧉</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php foreach ($offers as $o): ?>
                <form id="tg-<?= $o['id'] ?>" method="POST" style="display:none">
                    <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                </form>
                <form id="dp-<?= $o['id'] ?>" method="POST" style="display:none">
                    <?= csrf_field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="offer_id" value="<?= $o['id'] ?>">
                </form>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
