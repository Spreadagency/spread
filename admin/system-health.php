<?php
/**
 * Spread AI — الأدمن: «صحة النظام» (خريطة التطوير · المرحلة 5) — للأدمن الكامل بس
 * صفحة واحدة: آخر تشغيل لكل كرون · الطوابير · الترحيلات المستنية · لينك «فحص التحديث». قراءة بس — مفيش أي تغيير.
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/admin-today.php';

require_admin();
require_admin_can('site_settings');

$h = admin_system_health();
$bad = count(array_filter($h['crons'], fn($c) => !$c['ok'])) + count($h['migrations']);

$active = 'system-health';
$page_title = 'صحة النظام';
include __DIR__ . '/../templates/admin-header.php';
?>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head with-actions">
            <div>
                <h1>صحة النظام <?= $bad ? '<span class="chip chip-coral">' . $bad . ' محتاج نظرة</span>' : '<span class="chip chip-mint">تمام</span>' ?></h1>
                <div class="sub">الكرون والطوابير والترحيلات في مكان واحد — قراءة بس</div>
            </div>
            <a class="btn" href="<?= url('admin/ui-diagnose.php') ?>">فحص التحديث ←</a>
        </div>

        <?php foreach ($h['warnings'] ?? [] as $w): ?><div class="alert warning"><?= e($w) ?></div><?php endforeach; ?>
        <section class="card">
            <div class="a2-h"><h3>الكرون (آخر تشغيل)</h3></div>
            <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>المهمة</th><th>الملف</th><th>آخر تشغيل</th><th>الحالة</th></tr></thead><tbody>
            <?php foreach ($h['crons'] as $c): ?>
                <tr><td><b><?= e($c['label']) ?></b></td><td dir="ltr"><code><?= e($c['file']) ?></code></td>
                    <td><?= $c['never'] ? '<span class="a2-muted">ماشتغلش ولا مرة</span>' : e($c['at']) . ' <small class="a2-muted">(' . e(time_ago($c['at'])) . ')</small>' ?></td>
                    <td><?= $c['ok'] ? '<span class="chip chip-mint">شغال</span>' : '<span class="chip chip-coral">متأخر — المفروض كل ' . ($c['max_min'] >= 60 ? round($c['max_min'] / 60) . ' ساعة' : $c['max_min'] . ' دقيقة') . ' بالكتير</span>' ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>

        <section class="card">
            <div class="a2-h"><h3>الطوابير</h3></div>
            <div class="a2-tw"><table class="a2-tbl"><thead><tr><th>الطابور</th><th>العدد</th><th></th></tr></thead><tbody>
            <?php foreach ($h['queues'] as [$label, $n, $url]): ?>
                <tr><td><?= e($label) ?></td><td class="num"><?= $n === null ? '<span class="a2-muted">—</span>' : (int) $n ?></td>
                    <td><a class="btn sm ghost" href="<?= url($url) ?>">افتح</a></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>

        <section class="card">
            <div class="a2-h"><h3>الترحيلات المستنية</h3></div>
            <?php if (!$h['migrations']): ?>
                <p class="a2-muted">كل الترحيلات متشغّلة ✓</p>
            <?php else: ?>
                <ul><?php foreach ($h['migrations'] as $f): ?><li dir="ltr" style="text-align:start"><code><?= e($f) ?></code></li><?php endforeach; ?></ul>
                <a class="btn" href="<?= url('admin/migrations.php') ?>">شغّلها من «الترحيلات» ←</a>
            <?php endif; ?>
        </section>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
