<?php
declare(strict_types=1);

/** التتبع: Meta Pixel + CAPI, GA4, GTM, custom code, event toggles, test event. Owner only. */
require dirname(__DIR__, 2) . '/app/admin/bootstrap.php';

admin_require('owner');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_post('owner');
    $pixel = preg_replace('/\D/', '', (string) ($_POST['meta_pixel_id'] ?? ''));
    $_POST['meta_pixel_id'] = $pixel;
    foreach (['ga4_id' => '/^(G-[A-Z0-9]+)?$/i', 'gtm_id' => '/^(GTM-[A-Z0-9]+)?$/i', 'meta_graph_version' => '/^v\d+\.\d+$/'] as $k => $re) {
        $_POST[$k] = trim((string) ($_POST[$k] ?? ''));
        if (!preg_match($re, $_POST[$k])) {
            flash('err', 'القيمة في ' . $k . ' مش بالشكل الصح.');
            redirect('tracking.php');
        }
    }
    $changed = save_settings_from_post(
        ['meta_pixel_id', 'meta_graph_version', 'meta_test_event_code', 'ga4_id', 'gtm_id', 'custom_head_code', 'custom_body_code', 'csp_extra_hosts'],
        ['pixel_event_lead', 'pixel_event_viewcontent', 'pixel_event_contact', 'pixel_event_share'],
        ['meta_capi_token']
    );
    admin_log('tracking_update', implode(', ', $changed));
    flash('ok', 'اتحفظت إعدادات التتبع');
    redirect('tracking.php');
}

function test_status(string $key, bool $configured): string
{
    if (!$configured) {
        return '<span class="status"><i></i>مش متضاف</span>';
    }
    $t = json_decode((string) Settings::get('test_' . $key, ''), true);
    if (!$t) {
        return '<span class="status warn"><i></i>متضاف · لسه ما اتجربش</span>';
    }
    return $t['ok'] ? '<span class="status ok"><i></i>Connected</span>' : '<span class="status err"><i></i>آخر تجربة فشلت</span>';
}

$pixelOn = Settings::get('meta_pixel_id', '') !== '';
$capiOn = $pixelOn && Settings::get('meta_capi_token', '') !== '';
$lastTest = json_decode((string) Settings::get('test_pixel', ''), true);
$events7 = array_column(q("SELECT type, COUNT(*) n FROM events WHERE created_at >= ? AND type IN ('lead','view_result','whatsapp_click','share') GROUP BY type", [utc_now('-7 days')])->fetchAll(), 'n', 'type');
$capiErrors = 0;
foreach (glob(LOG_PATH . '/app-' . date('Y-m-d') . '.log') ?: [] as $file) {
    $capiErrors += substr_count((string) file_get_contents($file), 'CAPI ');
}

admin_page_start('التتبع (Pixel)', 'tracking.php');
?>
<form method="post" class="stack" style="gap:24px">
<?= csrf_field() ?>
<div class="page-head"><div><p>Meta Pixel + Conversions API و GA4 و GTM — لو الحقل فاضي الكود مش بيتحط في الصفحة</p></div><?= save_bar() ?></div>
<div class="grid g3" style="gap:16px">
  <div class="card" style="padding:16px"><div class="row between"><b class="ltr" style="color:var(--ink)">Meta Pixel</b><?= $pixelOn ? '<span class="status ok"><i></i>متضاف</span>' : '<span class="status"><i></i>مش متضاف</span>' ?></div><p class="small muted" style="margin-top:6px">آخر 7 أيام: <?= (int) ($events7['lead'] ?? 0) ?> Lead · <?= (int) ($events7['whatsapp_click'] ?? 0) ?> Contact · <?= (int) ($events7['view_result'] ?? 0) ?> ViewContent</p></div>
  <div class="card" style="padding:16px"><div class="row between"><b class="ltr" style="color:var(--ink)">Conversions API</b><?= test_status('pixel', $capiOn) ?></div><p class="small muted" style="margin-top:6px"><?= $capiErrors ? '<span style="color:var(--danger)">' . $capiErrors . ' خطأ النهارده — راجع storage/logs</span>' : 'مفيش أخطاء النهارده' ?></p></div>
  <div class="card" style="padding:16px"><div class="row between"><b class="ltr" style="color:var(--ink)">Google</b><?= Settings::get('ga4_id', '') || Settings::get('gtm_id', '') ? '<span class="status ok"><i></i>متضاف</span>' : '<span class="status"><i></i>مش متضاف</span>' ?></div><p class="small muted ltr" style="margin-top:6px;text-align:right"><?= e(trim(Settings::get('ga4_id', '') . ' ' . Settings::get('gtm_id', '')) ?: '—') ?></p></div>
</div>
<div class="grid g-1-1" style="align-items:start">
  <div class="stack" style="gap:24px">
    <section class="card"><div class="card-h"><div><h2>Meta Pixel + Conversions API</h2><p>نفس الـ event_id في المتصفح والسيرفر عشان ميتحسبش مرتين</p></div></div>
      <div class="card-b form-grid">
        <?= f_text('meta_pixel_id', 'Pixel ID', Settings::get('meta_pixel_id', ''), ['ltr' => 1, 'mono' => 1, 'inputmode' => 'numeric']) ?>
        <?= f_select('meta_graph_version', 'Graph API version', (string) Settings::get('meta_graph_version', 'v21.0'), ['v21.0' => 'v21.0', 'v22.0' => 'v22.0', 'v23.0' => 'v23.0', 'v20.0' => 'v20.0']) ?>
        <div class="full"><?= f_secret('meta_capi_token', 'Conversions API access token', 'Events Manager ← Settings ← Generate access token') ?></div>
        <?= f_text('meta_test_event_code', 'Test event code', Settings::get('meta_test_event_code', ''), ['ltr' => 1, 'mono' => 1, 'placeholder' => 'TEST12345', 'hint' => 'امسحه قبل ما تبدأ الإعلانات الحقيقية']) ?>
      </div>
      <div class="list" style="border-top:1px solid var(--line)">
        <?php foreach ([['pixel_event_lead', 'Lead', 'لما يسجل اسمه ورقمه · متصفح + سيرفر'], ['pixel_event_viewcontent', 'ViewContent', 'لما النتيجة تظهر · متصفح + سيرفر'], ['pixel_event_contact', 'Contact', 'ضغطة اسأل الدكتور على واتساب · متصفح + سيرفر'], ['pixel_event_share', 'ShareResult', 'لما يشارك اللينك · متصفح + سيرفر']] as [$key, $name, $desc]): ?>
        <div class="li"><div class="grow"><b class="ltr mono"><?= $name ?></b><small><?= e($desc) ?></small></div><?= f_toggle($key, '', Settings::bool($key)) ?></div>
        <?php endforeach; ?>
      </div>
      <div class="card-f" style="justify-content:space-between"><span class="small muted" id="out-pixel"><?= $lastTest ? '<span class="status ' . ($lastTest['ok'] ? 'ok' : 'err') . '"><i></i>' . e($lastTest['message']) . '</span> <span class="muted">· ' . e(rel_time($lastTest['at'])) . '</span>' : 'ابعت حدث تجريبي وشوفه في Events Manager ← Test events' ?></span><button class="btn btn-secondary" type="button" data-test="pixel"><?= ic('send', 'sm') ?>ابعت حدث تجريبي</button></div>
    </section>
    <section class="card"><div class="card-h"><h2>Google</h2></div><div class="card-b form-grid">
      <?= f_text('ga4_id', 'GA4 Measurement ID', Settings::get('ga4_id', ''), ['ltr' => 1, 'mono' => 1, 'placeholder' => 'G-XXXXXXXXXX', 'hint' => 'نفس الأحداث بتتبعت لـ gtag و dataLayer']) ?>
      <?= f_text('gtm_id', 'GTM Container ID', Settings::get('gtm_id', ''), ['ltr' => 1, 'mono' => 1, 'placeholder' => 'GTM-XXXXXXX']) ?>
    </div></section>
  </div>
  <div class="stack" style="gap:24px">
    <section class="card"><div class="card-h"><div><h2>كود مخصص</h2><p>بيتحط زي ما هو — اتأكد إنه من مصدر موثوق</p></div></div>
      <div class="card-b stack">
        <?= f_textarea('custom_head_code', 'داخل <head>', Settings::get('custom_head_code', ''), ['code' => 1, 'rows' => 9]) ?>
        <?= f_textarea('custom_body_code', 'بعد <body>', Settings::get('custom_body_code', ''), ['code' => 1, 'rows' => 5]) ?>
        <?= f_text('csp_extra_hosts', 'دومينات إضافية للكود (CSP)', Settings::get('csp_extra_hosts', ''), ['ltr' => 1, 'mono' => 1, 'placeholder' => 'https://analytics.tiktok.com https://*.snapchat.com', 'hint' => 'لو الكود المخصص بيحمّل سكريبت من دومين تاني، ضيفه هنا وإلا المتصفح هيمنعه.']) ?>
        <p class="hint"><?= ic('shield', 'sm') ?> الـ Owner بس يقدر يعدّل هنا، وكل تعديل بيتسجل في سجل النشاط.</p>
      </div></section>
  </div>
</div>
</form>
<?php
admin_page_end();
