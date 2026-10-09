<?php
declare(strict_types=1);

/** الصور: original/result pairs, filters, download, delete, retention banner. */
require dirname(__DIR__, 2) . '/app/admin/bootstrap.php';

admin_require('view');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_post('edit');
    $id = (int) ($_POST['id'] ?? 0);
    $g = q_row('SELECT * FROM generations WHERE id = ?', [$id]);
    if ($g) {
        ImageService::deleteFile(ORIGINALS_PATH, $g['original_path']);
        if ($g['result_path']) {
            ImageService::deleteFile(RESULTS_PATH, $g['result_path']);
            @unlink(ImageService::ogPath($g['result_path']));
        }
        q('UPDATE generations SET original_path = NULL, result_path = NULL WHERE id = ?', [$id]);
        admin_log('images_delete', "generation #$id");
        flash('ok', 'اتمسحت الصورتين. الليد نفسه لسه موجود.');
    }
    redirect(back_url('images.php'));
}

$status = in_array($_GET['status'] ?? '', ['done', 'failed', 'rejected'], true) ? $_GET['status'] : '';
$where = "g.status IN ('done','failed','rejected') AND (g.original_path IS NOT NULL OR g.result_path IS NOT NULL)";
$params = [];
if ($status) {
    $where .= ' AND g.status = ?';
    $params[] = $status;
}
$counts = array_column(q("SELECT status, COUNT(*) n FROM generations WHERE status IN ('done','failed','rejected') AND (original_path IS NOT NULL OR result_path IS NOT NULL) GROUP BY status")->fetchAll(), 'n', 'status');
$total = (int) q_value("SELECT COUNT(*) FROM generations g WHERE $where", $params);
$per = 24;
$pages = max(1, (int) ceil($total / $per));
$page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
$items = q("SELECT g.*, l.name FROM generations g JOIN leads l ON l.id = g.lead_id WHERE $where ORDER BY g.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params)->fetchAll();

$last = json_decode((string) Settings::get('cleanup_last_run', ''), true);
$cronCmd = (PHP_BINDIR ? PHP_BINDIR . '/php' : '/usr/local/bin/php') . ' ' . ROOT_PATH . '/cron/cleanup.php >/dev/null 2>&1';
$canEdit = AdminAuth::can('edit');

admin_page_start('الصور', 'images.php');
?>
<div class="page-head"><div><p>الصور الأصلية والمتولدة · محفوظة برّه الفولدر العام ومتاحة بلينكات موقّعة بس</p></div>
  <nav class="seg" aria-label="فلتر"><?php foreach (['' => 'الكل', 'done' => 'اتولدت', 'failed' => 'فشلت', 'rejected' => 'اترفضت'] as $k => $l): ?><a class="<?= $status === $k ? 'on' : '' ?>" href="?<?= e(http_build_query(array_filter(['status' => $k]))) ?>"><?= e($l) ?> <span class="ltr muted"><?= $k ? (int) ($counts[$k] ?? 0) : array_sum($counts) ?></span></a><?php endforeach; ?></nav></div>

<div class="banner"><span class="bi"><?= ic('clock') ?></span>
  <div style="flex:1;min-width:0" class="stack-8"><b style="color:var(--ink)">المسح التلقائي</b>
    <span class="small muted">الصور الأصلية بتتمسح بعد <b style="color:var(--ink)"><?= Settings::int('retention_originals_days', 30) ?> يوم</b> والمتولدة بعد <b style="color:var(--ink)"><?= Settings::int('retention_results_days', 90) ?> يوم</b>.
    <?= $last ? 'آخر تشغيل: ' . e(rel_time(gmdate('Y-m-d H:i:s', strtotime((string) $last['at'])))) . ' — اتمسح ' . ((int) ($last['originals'] ?? 0) + (int) ($last['results'] ?? 0)) . ' صورة.' : '<b style="color:var(--danger)">الـ Cron لسه ما اشتغلش.</b> ضيفه من cPanel ← Cron Jobs (مرة في اليوم):' ?></span>
    <code class="cron">0 3 * * * <?= e($cronCmd) ?></code></div>
  <?php if (AdminAuth::can('owner')): ?><a class="btn btn-secondary btn-sm" href="settings.php#retention"><?= ic('cog', 'sm') ?>غيّر المدة</a><?php endif; ?></div>

<?php if (!$items): ?>
  <div class="card"><?= empty_block('image', 'مفيش صور', $status ? 'مفيش صور بالحالة دي.' : 'أول ما حد يرفع صورة ويولّد المحاكاة، الصورتين هيظهروا هنا جنب بعض.') ?></div>
<?php else: ?>
<div class="gallery">
  <?php foreach ($items as $g): ?>
  <article class="card gcard">
    <div class="pair">
      <figure><?php if ($g['original_path']): ?><img src="<?= e(image_url((int) $g['id'], 'before', 3600)) ?>" alt="قبل — <?= e($g['name']) ?>" loading="lazy" data-zoom><?php else: ?><span class="expired"><?= ic('clock') ?>اتمسحت</span><?php endif; ?><figcaption>قبل</figcaption></figure>
      <figure class="after"><?php if ($g['result_path']): ?><img src="<?= e(image_url((int) $g['id'], 'after', 3600)) ?>" alt="بعد — <?= e($g['name']) ?>" loading="lazy" data-zoom><?php else: ?><span class="expired"><?= ic($g['status'] === 'done' ? 'clock' : 'x') ?><?= $g['status'] === 'done' ? 'اتمسحت' : e(GEN_LABELS[$g['status']]) ?></span><?php endif; ?><figcaption>بعد</figcaption></figure>
    </div>
    <div class="meta"><div><b><?= e($g['name']) ?></b><small><?= e(local_dt($g['created_at'])) ?></small></div><?= gen_badge($g['status']) ?></div>
    <?php if ($g['error_message'] && $g['status'] !== 'done'): ?><p class="small muted" style="padding:0 12px 8px" title="<?= e($g['error_message']) ?>"><?= e(mb_strimwidth($g['error_message'], 0, 60, '…')) ?></p><?php endif; ?>
    <div class="row" style="padding:0 12px 12px">
      <?php if ($g['result_path']): ?><a class="btn btn-sm btn-secondary" style="flex:1" href="<?= e(image_url((int) $g['id'], 'after', 3600)) ?>&amp;dl=1"><?= ic('dl', 'sm') ?>حمّل</a><?php else: ?><span style="flex:1"></span><?php endif; ?>
      <a class="btn btn-sm btn-icon btn-secondary" href="leads.php?open=<?= (int) $g['lead_id'] ?>" aria-label="تفاصيل الليد"><?= ic('users', 'sm') ?></a>
      <?php if ($canEdit): ?>
      <form method="post" data-confirm-title="تمسح الصورتين؟" data-confirm-body="هتتمسح الصورة الأصلية والمتولدة نهائيًا من السيرفر. الليد نفسه هيفضل موجود."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $g['id'] ?>"><button class="btn btn-sm btn-icon btn-danger-ghost" type="submit" aria-label="امسح الصور"><?= ic('trash', 'sm') ?></button></form>
      <?php endif; ?>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<div class="pager"><span>عرض <span class="ltr"><?= count($items) ?></span> من <span class="ltr"><?= $total ?></span></span><?= pager($page, $pages, array_filter(['status' => $status])) ?></div>
<?php endif; ?>
<?php
admin_page_end();
