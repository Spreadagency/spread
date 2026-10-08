<?php
declare(strict_types=1);

/**
 * Lead drawer.
 *   GET  lead.php?id=      → drawer HTML fragment
 *   POST lead.php          → action: save | regen | delete   (JSON)
 */
require dirname(__DIR__, 2) . '/app/admin/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_post('edit');
    $id = (int) ($_POST['id'] ?? 0);
    $lead = q_row('SELECT * FROM leads WHERE id = ?', [$id]);
    if (!$lead) {
        json_response(['error' => 'الليد مش موجود.'], 404);
    }
    switch ($_POST['action'] ?? '') {
        case 'save':
            $status = array_key_exists($_POST['status'] ?? '', STATUS_LABELS) ? $_POST['status'] : $lead['status'];
            $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 5000);
            q('UPDATE leads SET status = ?, notes = ?, updated_at = ? WHERE id = ?', [$status, $notes === '' ? null : $notes, utc_now(), $id]);
            admin_log('lead_update', "#$id status=$status");
            json_response(['ok' => true, 'message' => 'اتحفظت التغييرات', 'status' => $status, 'badge' => status_badge($status)]);
        case 'regen':
            q('UPDATE leads SET regen_allowed = 1 WHERE id = ?', [$id]);
            admin_log('lead_allow_regen', "#$id");
            json_response(['ok' => true, 'message' => 'المسجل يقدر يولّد صورة تانية دلوقتي']);
        case 'delete':
            foreach (q('SELECT original_path, result_path FROM generations WHERE lead_id = ?', [$id])->fetchAll() as $g) {
                ImageService::deleteFile(ORIGINALS_PATH, $g['original_path']);
                if ($g['result_path']) {
                    ImageService::deleteFile(RESULTS_PATH, $g['result_path']);
                    @unlink(ImageService::ogPath($g['result_path']));
                }
            }
            q('DELETE FROM events WHERE lead_id = ?', [$id]);
            q('DELETE FROM leads WHERE id = ?', [$id]);
            admin_log('lead_delete', "#$id {$lead['phone']}");
            json_response(['ok' => true, 'message' => 'اتمسح الليد وصوره', 'deleted' => $id]);
    }
    json_response(['error' => 'إجراء غير معروف'], 422);
}

admin_require('view');
$id = (int) ($_GET['id'] ?? 0);
$l = q_row('SELECT * FROM leads WHERE id = ?', [$id]);
if (!$l) {
    http_response_code(404);
    echo '<div class="drawer-b">' . empty_block('users', 'الليد ده مش موجود', 'يمكن اتمسح.') . '</div>';
    exit;
}
$gens = q('SELECT * FROM generations WHERE lead_id = ? ORDER BY id DESC', [$id])->fetchAll();
$done = null;
foreach ($gens as $g) {
    if ($g['status'] === 'done') {
        $done = $g;
        break;
    }
}
$events = q("SELECT type, meta, created_at FROM events WHERE lead_id = ? AND type <> 'page_view' ORDER BY id DESC LIMIT 40", [$id])->fetchAll();
$EV = [
    'lead' => ['check', 'سجّل اسمه ورقمه', ''], 'upload' => ['upload', 'رفع صورة', ''], 'generate' => ['spark', 'بدأ التوليد', ''],
    'view_result' => ['eye', 'شاف النتيجة', ''], 'whatsapp_click' => ['wa', 'ضغط اسأل الدكتور على واتساب', 'wa'], 'website_click' => ['globe', 'زار الموقع', ''],
    'share' => ['share', 'شارك اللينك', ''], 'download' => ['dl', 'حمّل الصورة', ''], 'call_click' => ['phone', 'ضغط اتصل', ''], 'directions_click' => ['pin', 'ضغط الاتجاهات', ''],
];
$timeline = [];
foreach ($events as $ev) {
    [$icon, $label, $cls] = $EV[$ev['type']] ?? ['info', $ev['type'], ''];
    $timeline[] = [$ev['created_at'], $icon, $label, $cls];
}
foreach ($gens as $g) {
    if (in_array($g['status'], ['failed', 'rejected'], true)) {
        $timeline[] = [$g['completed_at'] ?: $g['created_at'], 'x', ($g['status'] === 'rejected' ? 'الصورة اترفضت' : 'التوليد فشل') . ($g['error_message'] ? ' — ' . mb_strimwidth($g['error_message'], 0, 70, '…') : ''), 'fail'];
    } elseif ($g['status'] === 'done') {
        $timeline[] = [$g['completed_at'], 'spark', 'الصورة اتولدت' . ($g['duration_ms'] ? ' (' . number_format($g['duration_ms'] / 1000, 1) . ' ثانية)' : ''), ''];
    }
}
usort($timeline, static fn ($a, $b) => strcmp((string) $b[0], (string) $a[0]));
$canEdit = AdminAuth::can('edit');
$lastGen = $gens[0] ?? null;
?>
<div class="drawer-h"><span class="avatar"><?= e(initials($l['name'])) ?></span><div style="flex:1"><h3 id="dwT" style="font-size:18px;color:var(--ink)"><?= e($l['name']) ?></h3><p class="small muted">ليد #<?= (int) $l['id'] ?> · <?= e(local_dt($l['created_at'])) ?></p></div><button class="btn btn-icon btn-secondary" type="button" data-close aria-label="إغلاق"><?= ic('x', 'sm') ?></button></div>
<form class="drawer-b" id="leadForm" data-id="<?= (int) $l['id'] ?>">
  <div class="row wrap"><a class="btn btn-wa btn-sm" href="https://wa.me/<?= e(phone_international($l['phone'])) ?>" target="_blank" rel="noopener"><?= ic('wa', 'sm') ?>كلّمه واتساب</a><a class="btn btn-secondary btn-sm" href="tel:<?= e($l['phone']) ?>"><?= ic('phone', 'sm') ?>اتصل</a><button type="button" class="btn btn-secondary btn-sm" data-copy="<?= e($l['phone']) ?>"><?= ic('copy', 'sm') ?>انسخ الرقم</button></div>
  <div class="grid g2" style="gap:12px">
    <div class="field"><label for="dwS">الحالة</label><select class="select" id="dwS" name="status"<?= $canEdit ? '' : ' disabled' ?>><?php foreach (STATUS_LABELS as $k => $t): ?><option value="<?= $k ?>"<?= $l['status'] === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>الصورة</label><div style="height:40px;display:flex;align-items:center"><?= gen_badge($done ? 'done' : ($lastGen['status'] ?? 'none')) ?></div></div>
  </div>
  <dl class="kv">
    <dt>الرقم</dt><dd class="ltr mono"><?= e($l['phone']) ?></dd>
    <dt>المصدر</dt><dd class="ltr"><?= e($l['utm_source'] ?: 'مباشر') ?><?= $l['utm_medium'] ? ' / ' . e($l['utm_medium']) : '' ?></dd>
    <dt>الحملة</dt><dd class="ltr mono"><?= e($l['utm_campaign'] ?: '—') ?></dd>
    <?php if ($l['utm_content']): ?><dt>الإعلان</dt><dd class="ltr mono"><?= e($l['utm_content']) ?></dd><?php endif; ?>
    <dt>الجهاز</dt><dd class="small"><?= e(preg_match('/iPhone|iPad/i', (string) $l['user_agent']) ? 'iPhone / iOS' : (preg_match('/Android/i', (string) $l['user_agent']) ? 'Android' : (preg_match('/Windows|Macintosh/i', (string) $l['user_agent']) ? 'كمبيوتر' : '—'))) ?></dd>
    <dt>الموافقة</dt><dd><?= $l['consent'] ? ic('check', 'sm') . ' وافق على استخدام الصورة' : '—' ?></dd>
    <?php if ($l['regen_allowed']): ?><dt>إعادة التوليد</dt><dd><span class="badge b-gold">مسموح بمحاولة تانية</span></dd><?php endif; ?>
  </dl>
  <?php if ($done): ?>
  <div class="stack-8"><span class="h-sec">الصور</span>
    <div class="pair">
      <figure><?php if ($done['original_path']): ?><img src="<?= e(image_url((int) $done['id'], 'before', 3600)) ?>" alt="قبل" loading="lazy"><?php else: ?><span class="expired"><?= ic('clock') ?>اتمسحت</span><?php endif; ?><figcaption>قبل</figcaption></figure>
      <figure class="after"><?php if ($done['result_path']): ?><img src="<?= e(image_url((int) $done['id'], 'after', 3600)) ?>" alt="بعد" loading="lazy"><?php else: ?><span class="expired"><?= ic('clock') ?>اتمسحت</span><?php endif; ?><figcaption>بعد</figcaption></figure>
    </div>
    <div class="row"><?php if ($done['result_path']): ?><a class="btn btn-sm btn-secondary" href="<?= e(image_url((int) $done['id'], 'after', 3600)) ?>&amp;dl=1"><?= ic('dl', 'sm') ?>حمّل</a><?php endif; ?><a class="btn btn-sm btn-ghost" href="<?= e(share_url((string) $done['share_token'])) ?>" target="_blank" rel="noopener"><?= ic('link', 'sm') ?>لينك المشاركة</a></div>
  </div>
  <?php elseif (!$gens): ?>
  <div class="banner info"><span class="bi"><?= ic('image') ?></span><div class="small">لسه مرفعش صورة. ممكن تبعتله رسالة تفكير على واتساب.</div></div>
  <?php endif; ?>
  <?php if ($canEdit && $done && !$l['regen_allowed']): ?>
  <div class="banner"><span class="bi"><?= ic('refresh') ?></span><div class="small" style="flex:1">عايز تسمحله يجرّب صورة تانية؟ (هتتحسب من الحد اليومي)</div><button type="button" class="btn btn-sm btn-gold" data-lead-action="regen"><?= ic('refresh', 'sm') ?>اسمح بإعادة التوليد</button></div>
  <?php endif; ?>
  <div class="field"><label for="dwN">ملاحظات</label><textarea class="textarea" id="dwN" name="notes" placeholder="اكتب ملاحظة عن المكالمة…"<?= $canEdit ? '' : ' disabled' ?>><?= e($l['notes']) ?></textarea></div>
  <div class="stack-8"><span class="h-sec">الأحداث</span>
    <?php if (!$timeline): ?><p class="small muted">مفيش أحداث.</p><?php else: ?>
    <ol class="timeline"><?php foreach ($timeline as [$at, $icon, $label, $cls]): ?><li class="tl <?= $cls ?>"><i><?= ic($icon) ?></i><b><?= e($label) ?></b><small><?= e(local_dt($at)) ?></small></li><?php endforeach; ?></ol>
    <?php endif; ?>
  </div>
</form>
<div class="drawer-f">
  <?php if ($canEdit): ?>
  <button class="btn btn-primary" type="button" data-lead-action="save"><?= ic('check', 'sm') ?>احفظ</button>
  <button class="btn btn-secondary" type="button" data-close>إلغاء</button><span style="flex:1"></span>
  <button class="btn btn-danger-ghost" type="button" data-lead-action="delete" data-confirm-title="تمسح <?= e($l['name']) ?>؟" data-confirm-body="هيتمسح الليد وصوره (الأصلية والمتولدة) وكل الأحداث. مش هينفع ترجعه."><?= ic('trash', 'sm') ?>امسح الليد</button>
  <?php else: ?>
  <button class="btn btn-secondary" type="button" data-close>إغلاق</button>
  <?php endif; ?>
</div>
