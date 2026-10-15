<?php
/**
 * Spread AI — الأدمن: الرسايل والواتساب
 *   ?tab=send       إرسال رسالة (عميل واحد · الكل · فلتر) — داخل المنصة / واتساب / الاتنين — الآن أو مجدول
 *   ?tab=campaigns  الحملات: الإجمالي · اتبعت/فشل/في الطابور · نسبة القراءة · إلغاء
 *   ?tab=log        سجل كل الرسايل + إعادة إرسال الفاشل
 *   ?tab=templates  قوالب الإشعارات التلقائية + شروط الإرسال (مين يستلم)
 *   ?tab=settings   الربط مع n8n · الحد اليومي · ساعات الهدوء · رسالة تجريبية
 */
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/uploader.php';
require_once __DIR__ . '/../includes/Notifier.php';

require_admin();
require_admin_can('view_users');
$admin = current_admin();
$adminId = (int) ($admin['id'] ?? 0);
wa_ensure_schema();

$tabs = ['send' => 'إرسال رسالة', 'campaigns' => 'الحملات', 'log' => 'سجل الرسايل', 'templates' => 'القوالب والشروط', 'settings' => 'الإعدادات والربط'];
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'send';
$canSettings = admin_can('site_settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    decode_b64_fields();
    $action = $_POST['action'] ?? '';

    // ── الإعدادات ──
    if ($action === 'save_settings' || $action === 'gen_secret') {
        require_admin_can('site_settings');
        if ($action === 'gen_secret') {
            set_setting('wa_shared_secret', bin2hex(random_bytes(24)));
            admin_log('wa_secret_regenerate', 'settings');
            flash_set('success', 'اتعمل مفتاح جديد — حطه في n8n (نود Config) قبل ما الرسايل تقف');
            redirect('admin/whatsapp.php?tab=settings');
        }
        $hook = trim((string) ($_POST['wa_n8n_webhook_url'] ?? ''));
        if ($hook !== '' && !preg_match('#^https?://[^\s]+$#i', $hook)) {
            flash_set('danger', 'لينك الـ webhook مش صحيح');
            redirect('admin/whatsapp.php?tab=settings');
        }
        $secret = trim((string) ($_POST['wa_shared_secret'] ?? ''));
        if ($secret !== '' && strlen($secret) < 16) {
            flash_set('danger', 'المفتاح لازم يكون 16 حرف على الأقل');
            redirect('admin/whatsapp.php?tab=settings');
        }
        $hm = fn($v, $d) => preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', trim((string) $v)) ? trim((string) $v) : $d;
        set_setting('wa_enabled', !empty($_POST['wa_enabled']) ? '1' : '0');
        set_setting('wa_mirror_notifications', !empty($_POST['wa_mirror_notifications']) ? '1' : '0');
        set_setting('wa_n8n_webhook_url', mb_substr($hook, 0, 500));
        if ($secret !== '') set_setting('wa_shared_secret', $secret);
        set_setting('wa_daily_limit', (string) max(1, min(50, (int) ($_POST['wa_daily_limit'] ?? 3))));
        set_setting('wa_quiet_from', $hm($_POST['wa_quiet_from'] ?? '', '23:00'));
        set_setting('wa_quiet_to', $hm($_POST['wa_quiet_to'] ?? '', '09:00'));
        admin_log('wa_settings', 'settings');
        flash_set('success', 'اتحفظت إعدادات الواتساب ✓');
        redirect('admin/whatsapp.php?tab=settings');
    }

    if ($action === 'test_message') {
        require_admin_can('site_settings');
        $phone = wa_normalize_phone((string) ($_POST['test_phone'] ?? ''));
        $text = trim((string) ($_POST['test_text'] ?? '')) ?: 'رسالة تجريبية من Spread AI ✅';
        if ($phone === '') {
            flash_set('danger', 'الرقم مش صحيح');
            redirect('admin/whatsapp.php?tab=settings');
        }
        if (!wa_enabled()) {
            flash_set('danger', 'فعّل الإرسال الأول وبعدين جرّب');
            redirect('admin/whatsapp.php?tab=settings');
        }
        $id = db_insert('INSERT INTO messages (user_id, type, admin_id, title, body, channel, wa_phone, wa_status) VALUES (0, "direct", ?, "رسالة تجريبية", ?, "whatsapp", ?, "pending")',
            [$adminId, mb_substr($text, 0, 1000), $phone]);
        Notifier::dispatch([$id]);
        $st = (string) (db_one('SELECT wa_status FROM messages WHERE id = ?', [$id])['wa_status'] ?? '');
        admin_log('wa_test', 'message', $id, $phone);
        flash_set($st === 'processing' ? 'success' : 'warning', $st === 'processing'
            ? 'n8n استلم الرسالة التجريبية #' . $id . ' — شوف حالتها في السجل خلال ثواني'
            : 'الرسالة #' . $id . ' في الطابور — n8n مارجّعش رد على الـ webhook، وهيسحبها في الجدولة (كل دقيقتين)');
        redirect('admin/whatsapp.php?tab=log');
    }

    // ── القوالب ──
    if ($action === 'save_template') {
        require_admin_can('site_settings');
        $key = preg_replace('/[^a-z_]/', '', (string) ($_POST['event_key'] ?? ''));
        $cat = wa_event_catalog();
        if (!isset($cat[$key])) {
            flash_set('danger', 'القالب مش موجود');
            redirect('admin/whatsapp.php?tab=templates');
        }
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 150);
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($title === '' || $body === '') {
            flash_set('danger', 'العنوان والنص مطلوبين');
            redirect('admin/whatsapp.php?tab=templates&edit=' . $key);
        }
        $ch = in_array($_POST['default_channel'] ?? '', ['inapp', 'whatsapp', 'both'], true) ? $_POST['default_channel'] : 'both';
        if ($key === 'otp') $ch = 'whatsapp';
        $aud = wa_audience_from_form($_POST, 'c_');
        $audJson = count($aud) > 1 ? json_encode($aud, JSON_UNESCAPED_UNICODE) : null;
        db_run('UPDATE message_templates SET title = ?, body = ?, default_channel = ?, is_active = ?, audience_json = ? WHERE event_key = ?',
            [$title, mb_substr($body, 0, 4000), $ch, ($key === 'otp' || !empty($_POST['is_active'])) ? 1 : 0, $key === 'otp' ? null : $audJson, $key]);
        admin_log('wa_template', 'template', null, $key);
        flash_set('success', 'اتحفظ القالب ✓');
        redirect('admin/whatsapp.php?tab=templates');
    }

    // ── الإرسال ──
    if ($action === 'send') {
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 150);
        $body = trim((string) ($_POST['body'] ?? ''));
        $channel = in_array($_POST['channel'] ?? '', ['inapp', 'whatsapp', 'both'], true) ? $_POST['channel'] : 'both';
        $back = 'admin/whatsapp.php?tab=send';
        if ($title === '' || $body === '') { flash_set('danger', 'اكتب العنوان والنص'); redirect($back); }
        if (mb_strlen($body) > 4000) { flash_set('danger', 'النص طويل جدًا (أقصى 4000 حرف)'); redirect($back); }
        $link = trim((string) ($_POST['link'] ?? ''));
        if ($link !== '' && !wa_clean_link($link)) { flash_set('danger', 'الرابط لازم يبدأ بـ https://'); redirect($back); }
        $media = null;
        if (!empty($_FILES['media']['name'])) {
            $r = upload_image($_FILES['media'], 'whatsapp');
            if (!$r['ok']) { flash_set('danger', 'فشل رفع الصورة: ' . ($r['error'] ?? '')); redirect($back); }
            $media = wa_abs_link((string) upload_url($r['path']));
        } elseif (trim((string) ($_POST['media_url'] ?? '')) !== '') {
            $media = wa_clean_link((string) $_POST['media_url']);
            if (!$media) { flash_set('danger', 'رابط الصورة لازم يبدأ بـ https://'); redirect($back); }
        }
        $when = (string) ($_POST['when'] ?? 'now');
        $sched = null;
        if ($when === 'later') {
            $dt = str_replace('T', ' ', (string) ($_POST['scheduled_at'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dt) || strtotime($dt) === false) { flash_set('danger', 'اختار ميعاد صحيح'); redirect($back); }
            $sched = $dt . ':00';
        }
        $atype = (string) ($_POST['aud_type'] ?? 'user');
        if ($atype === 'user') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            if ($uid <= 0 || !db_one('SELECT id FROM users WHERE id = ?', [$uid])) { flash_set('danger', 'اختار العميل من القايمة'); redirect($back); }
            if ($sched === null) {
                $id = Notifier::direct($uid, $title, $body, $channel, $media, $link ?: null, $adminId);
                $m = db_one('SELECT wa_status, wa_error FROM messages WHERE id = ?', [$id]);
                admin_log('wa_direct', 'message', $id, (string) $uid);
                flash_set('success', 'اتبعتت الرسالة #' . $id . ($channel !== 'inapp' ? ' · واتساب: ' . wa_status_label((string) $m['wa_status'], $m['wa_error']) : ''));
                redirect('admin/whatsapp.php?tab=log');
            }
            $aud = ['type' => 'user', 'user_id' => $uid];
        } elseif ($atype === 'all') {
            $aud = ['type' => 'all'];
        } else {
            $aud = wa_audience_from_form($_POST);
        }
        [$n, $nWa] = wa_audience_count($aud);
        if ($n === 0) { flash_set('danger', 'مفيش عملاء مطابقين للفلتر ده'); redirect($back); }
        $bid = Notifier::broadcast(['title' => $title, 'body' => $body, 'channel' => $channel, 'audience' => $aud,
            'media_url' => $media, 'link' => $link ?: null, 'scheduled_at' => $sched], $adminId);
        admin_log('wa_broadcast', 'broadcast', $bid, $n . ' users');
        flash_set('success', 'اتعملت الحملة #' . $bid . ' لـ ' . $n . ' عميل' . ($channel !== 'inapp' ? ' (' . $nWa . ' منهم واتساب — بتتبعت بالتدريج)' : '') . ($sched ? ' · ميعادها ' . $sched : ''));
        redirect('admin/whatsapp.php?tab=campaigns');
    }

    if ($action === 'resend') {
        $id = (int) ($_POST['id'] ?? 0);
        db_run("UPDATE messages SET wa_status = 'pending', wa_attempts = 0, wa_error = NULL, locked_at = NULL, scheduled_at = NOW()
                WHERE id = ? AND wa_status IN ('failed','skipped') AND wa_phone IS NOT NULL AND wa_phone <> ''", [$id]);
        $b = db_one('SELECT broadcast_id FROM messages WHERE id = ?', [$id]);
        if (!empty($b['broadcast_id'])) db_run("UPDATE message_broadcasts SET status = 'sending' WHERE id = ? AND status = 'done'", [(int) $b['broadcast_id']]);
        Notifier::dispatch([$id]);
        admin_log('wa_resend', 'message', $id);
        flash_set('success', 'الرسالة #' . $id . ' رجعت للطابور');
        redirect('admin/whatsapp.php?tab=log' . (!empty($_POST['qs']) ? '&' . preg_replace('/[^a-z0-9_=&%\-.]/i', '', (string) $_POST['qs']) : ''));
    }

    if ($action === 'cancel_broadcast') {
        $bid = (int) ($_POST['id'] ?? 0);
        db_run("UPDATE message_broadcasts SET status = 'cancelled' WHERE id = ? AND status IN ('draft','queued','sending')", [$bid]);
        db_run("UPDATE messages SET wa_status = 'skipped', wa_error = 'cancelled' WHERE broadcast_id = ? AND wa_status = 'pending'", [$bid]);
        admin_log('wa_cancel', 'broadcast', $bid);
        flash_set('success', 'اتلغت الحملة #' . $bid . ' — الرسايل اللي لسه ماتبعتتش مش هتتبعت');
        redirect('admin/whatsapp.php?tab=campaigns');
    }
    redirect('admin/whatsapp.php');
}

$packages = db_all('SELECT id, name FROM credit_packages ORDER BY order_num ASC, id ASC');
$enabled = wa_enabled();
$connected = wa_setting('wa_n8n_webhook_url') !== '' && wa_setting('wa_shared_secret') !== '';
$queue = db_one("SELECT SUM(wa_status = 'pending') p, SUM(wa_status = 'processing') pr, SUM(wa_status = 'failed' AND wa_attempts >= " . WA_MAX_ATTEMPTS . ") f,
                        SUM(wa_status = 'sent' AND sent_at >= CURDATE()) s FROM messages") ?: [];
$verifiedUsers = (int) (db_one('SELECT COUNT(*) n FROM users WHERE whatsapp_verified = 1 AND whatsapp_opt_in = 1')['n'] ?? 0);

$active = 'whatsapp';
$page_title = 'الرسايل والواتساب';
include __DIR__ . '/../templates/admin-header.php';

/** حقول فلتر الجمهور (للإرسال وشروط القوالب) */
function wa_filter_fields(array $packages, array $val = [], string $prefix = ''): string
{
    $h = '<div class="a2-grid a2-g3" style="gap:10px">';
    $h .= '<div class="field"><label>باقة الاشتراك</label><select class="input" name="' . $prefix . 'plan_ids[]" multiple size="3">';
    foreach ($packages as $p) {
        $h .= '<option value="' . (int) $p['id'] . '"' . (in_array((int) $p['id'], (array) ($val['plan_ids'] ?? []), true) ? ' selected' : '') . '>' . e($p['name']) . '</option>';
    }
    $h .= '</select><div class="field-help">Ctrl لاختيار أكتر من باقة · ولا واحدة = الكل</div></div>';
    $sub = (string) ($val['subscribed'] ?? '');
    $h .= '<div class="field"><label>الاشتراك</label><select class="input" name="' . $prefix . 'subscribed">'
        . '<option value="">الكل</option><option value="yes"' . ($sub === 'yes' ? ' selected' : '') . '>مشتركين بس</option>'
        . '<option value="no"' . ($sub === 'no' ? ' selected' : '') . '>غير مشتركين بس</option></select></div>';
    $h .= '<div class="field"><label>رصيد أقل من</label><input class="input" type="number" min="0" name="' . $prefix . 'credits_lt" value="' . e((string) ($val['credits_lt'] ?? '')) . '" placeholder="مثلًا 20"></div>';
    $h .= '<div class="field"><label>مسجّل من</label><input class="input" type="date" name="' . $prefix . 'registered_from" value="' . e((string) ($val['registered_from'] ?? '')) . '"></div>';
    $h .= '<div class="field"><label>مسجّل لحد</label><input class="input" type="date" name="' . $prefix . 'registered_to" value="' . e((string) ($val['registered_to'] ?? '')) . '"></div>';
    $h .= '<div class="field"><label>مدخلش من (يوم)</label><input class="input" type="number" min="0" name="' . $prefix . 'inactive_days" value="' . e((string) ($val['inactive_days'] ?? '')) . '" placeholder="مثلًا 7"></div>';
    $h .= '</div><label style="display:flex;gap:8px;align-items:center;margin-top:6px;cursor:pointer"><input type="checkbox" name="' . $prefix . 'wa_verified" value="1"'
        . (!empty($val['wa_verified']) ? ' checked' : '') . ' style="width:auto"> عنده رقم واتساب متحقق بس</label>';
    return $h;
}
?>
<style>
.wa-tabs { margin-bottom: 14px; flex-wrap: wrap; }
.wa-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 14px; }
.wa-kpi { background: #fff; border: 1px solid var(--line, #E3E8EF); border-radius: 14px; padding: 12px 14px; }
.wa-kpi b { display: block; font-size: 22px; }
.wa-kpi span { color: #667085; font-size: 13px; }
.wa-preview { background: #E7DDD3; border-radius: 14px; padding: 14px; min-height: 120px; }
.wa-bubble { background: #DCF8C6; border-radius: 10px 10px 2px 10px; padding: 8px 10px 18px; max-width: 320px; margin-inline-start: auto; white-space: pre-wrap;
    font-size: 14px; line-height: 1.6; position: relative; box-shadow: 0 1px 1px rgba(0,0,0,.12); overflow-wrap: anywhere; }
.wa-bubble img { display: block; max-width: 100%; border-radius: 8px; margin-bottom: 6px; }
.wa-bubble small { position: absolute; bottom: 3px; inset-inline-start: 8px; color: #667781; font-size: 11px; }
.wa-reach { font-weight: 700; margin: 10px 0; }
.wa-sugg { position: absolute; z-index: 20; background: #fff; border: 1px solid #E3E8EF; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,.08);
    width: 100%; max-height: 260px; overflow: auto; top: 100%; inset-inline-start: 0; }
.wa-sugg button { display: block; width: 100%; text-align: start; padding: 9px 12px; border: 0; background: none; cursor: pointer; font: inherit; }
.wa-sugg button:hover, .wa-sugg button:focus { background: #F2F6FB; }
.wa-vars code { background: #F2F6FB; padding: 1px 6px; border-radius: 6px; margin: 2px; display: inline-block; cursor: pointer; }
.wa-body { white-space: pre-wrap; max-width: 380px; font-size: 13px; color: #344054; }
.wa-hide { display: none !important; }
</style>
<div class="app">
    <?php include __DIR__ . '/../templates/admin-sidebar.php'; ?>
    <main class="main">
        <div class="page-head with-actions">
            <div>
                <h1>الرسايل والواتساب 💬</h1>
                <div class="sub">إشعارات تلقائية + رسايل من الإدارة — جوه المنصة وعلى الواتساب (عبر n8n → Evolution API)</div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <span class="chip <?= $enabled && $connected ? 'chip-line' : 'chip-amber' ?>"><?= $enabled ? ($connected ? '● الإرسال شغال' : '● محتاج ربط n8n') : '● الإرسال متوقف' ?></span>
            </div>
        </div>
        <?= render_flash() ?>

        <div class="wa-kpis">
            <div class="wa-kpi"><b><?= (int) ($queue['p'] ?? 0) + (int) ($queue['pr'] ?? 0) ?></b><span>في الطابور / بيتبعت</span></div>
            <div class="wa-kpi"><b><?= (int) ($queue['s'] ?? 0) ?></b><span>اتبعت النهارده</span></div>
            <div class="wa-kpi"><b><?= (int) ($queue['f'] ?? 0) ?></b><span>فشل نهائي</span></div>
            <div class="wa-kpi"><b><?= $verifiedUsers ?></b><span>عميل رقمه متحقق</span></div>
        </div>

        <div class="seg wa-tabs">
            <?php foreach ($tabs as $k => $lbl): ?>
                <a href="?tab=<?= $k ?>"><button type="button" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($lbl) ?></button></a>
            <?php endforeach; ?>
        </div>

<?php if ($tab === 'send'): ?>
        <form method="POST" enctype="multipart/form-data" data-safe-post id="wa-send">
            <?= csrf_field() ?><input type="hidden" name="action" value="send">
            <div class="a2-grid a2-side" style="gap:14px;align-items:start">
                <div>
                    <div class="card">
                        <div class="card-head"><h3>الجمهور</h3></div>
                        <div class="seg" style="margin-bottom:10px">
                            <?php foreach (['user' => 'عميل واحد', 'all' => 'كل العملاء', 'filter' => 'فلتر'] as $k => $l): ?>
                                <label style="cursor:pointer"><input type="radio" name="aud_type" value="<?= $k ?>" <?= $k === 'user' ? 'checked' : '' ?> style="width:auto"> <?= e($l) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <div data-aud="user" style="position:relative">
                            <input type="hidden" name="user_id" id="wa-uid">
                            <input class="input" id="wa-usearch" placeholder="ابحث بالاسم · الإيميل · الموبايل" autocomplete="off" aria-label="بحث عن عميل">
                            <div class="wa-sugg wa-hide" id="wa-sugg" role="listbox"></div>
                            <div class="field-help" id="wa-upicked"></div>
                        </div>
                        <div data-aud="filter" class="wa-hide"><?= wa_filter_fields($packages) ?></div>
                        <div class="wa-reach" id="wa-reach" aria-live="polite"></div>
                    </div>

                    <div class="card">
                        <div class="card-head"><h3>الرسالة</h3></div>
                        <div class="field"><label>القناة</label>
                            <select class="input" name="channel" id="wa-channel">
                                <option value="both">داخل المنصة + واتساب</option><option value="inapp">داخل المنصة بس</option><option value="whatsapp">واتساب بس</option>
                            </select></div>
                        <div class="field"><label>العنوان (بيظهر جوه المنصة)</label><input class="input" name="title" id="wa-title" maxlength="150" required></div>
                        <div class="field"><label>النص <span class="a2-muted" id="wa-count">0</span></label>
                            <textarea class="input" name="body" id="wa-text" rows="6" maxlength="4000" required placeholder="أهلًا {{first_name}} 👋 …"></textarea>
                            <div class="field-help wa-vars">متغيرات: <code>{{name}}</code><code>{{first_name}}</code><code>{{credits}}</code> · *نص عريض* على الواتساب</div></div>
                        <div class="a2-grid a2-g3" style="gap:10px">
                            <div class="field"><label>صورة (اختياري)</label><input class="input" type="file" name="media" accept="image/jpeg,image/png,image/webp" id="wa-file"></div>
                            <div class="field"><label>أو رابط صورة</label><input class="input" name="media_url" dir="ltr" placeholder="https://…" id="wa-murl"></div>
                            <div class="field"><label>رابط (اختياري)</label><input class="input" name="link" dir="ltr" placeholder="https://…" id="wa-link"></div>
                        </div>
                        <div class="field"><label>التوقيت</label>
                            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                                <label style="cursor:pointer"><input type="radio" name="when" value="now" checked style="width:auto"> الآن</label>
                                <label style="cursor:pointer"><input type="radio" name="when" value="later" style="width:auto"> جدولة</label>
                                <input class="input wa-hide" type="datetime-local" name="scheduled_at" id="wa-at" style="max-width:240px">
                            </div>
                            <div class="field-help">عميل واحد + الآن = فوري. أكتر من عميل أو مجدول = حملة بتتبعت بالتدريج (5–10 ثواني بين كل رسالة) علشان الرقم مايتحظرش.</div>
                        </div>
                        <button class="btn" id="wa-submit">📤 إرسال</button>
                    </div>
                </div>
                <div class="card" style="position:sticky;top:12px">
                    <div class="card-head"><h3>شكلها على الواتساب</h3></div>
                    <div class="wa-preview"><div class="wa-bubble" id="wa-bubble"><span class="a2-muted">اكتب الرسالة…</span></div></div>
                    <p class="a2-muted" style="font-size:12px;margin-top:8px">المتغيرات بتتملي ببيانات كل عميل وقت الإرسال. العنوان مابيتبعتش على الواتساب.</p>
                </div>
            </div>
        </form>
        <script>
        (function () {
          var API = <?= json_encode(url('admin/whatsapp-api.php')) ?>, f = document.getElementById('wa-send');
          var txt = document.getElementById('wa-text'), bub = document.getElementById('wa-bubble'), reach = document.getElementById('wa-reach');
          function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
          function fmt(s) { return esc(s).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/\{\{\s*first_name\s*\}\}/g, 'أحمد').replace(/\{\{\s*name\s*\}\}/g, 'أحمد محمد').replace(/\{\{\s*credits\s*\}\}/g, '40% من باقتك'); }
          function preview() {
            document.getElementById('wa-count').textContent = txt.value.length + ' / 4000';
            var t = txt.value, l = document.getElementById('wa-link').value.trim(), m = document.getElementById('wa-murl').value.trim(), fi = document.getElementById('wa-file');
            if (l && t.indexOf(l) < 0) t += '\n' + l;
            var img = '';
            if (fi.files && fi.files[0]) img = '<img src="' + URL.createObjectURL(fi.files[0]) + '" alt="">';
            else if (/^https:\/\//.test(m)) img = '<img src="' + esc(m) + '" alt="">';
            bub.innerHTML = t ? img + fmt(t) + '<small>' + new Date().toTimeString().slice(0, 5) + '</small>' : '<span class="a2-muted">اكتب الرسالة…</span>';
          }
          ['input', 'change'].forEach(function (ev) { f.addEventListener(ev, function (e) { if (['wa-text', 'wa-link', 'wa-murl', 'wa-file'].indexOf(e.target.id) >= 0) preview(); }); });
          function audType() { return (f.querySelector('[name=aud_type]:checked') || {}).value; }
          var tmr;
          function count() {
            clearTimeout(tmr);
            tmr = setTimeout(function () {
              var t = audType();
              if (t === 'user') { var u = document.getElementById('wa-uid').value; reach.textContent = u ? 'هيوصل لعميل واحد' : ''; return; }
              var fd = new FormData(f); fd.delete('media'); fd.delete('body'); fd.delete('title'); fd.set('action', 'count');
              fetch(API + '?action=count', { method: 'POST', body: new URLSearchParams(fd), credentials: 'same-origin' }).then(function (r) { return r.json(); })
                .then(function (d) { if (d.ok) reach.textContent = 'هيوصل لـ ' + d.total + ' عميل (' + d.wa + ' منهم واتساب)'; });
            }, 300);
          }
          f.addEventListener('change', function (e) {
            if (e.target.name === 'aud_type') {
              f.querySelectorAll('[data-aud]').forEach(function (b) { b.classList.toggle('wa-hide', b.dataset.aud !== audType()); });
            }
            if (e.target.name === 'when') document.getElementById('wa-at').classList.toggle('wa-hide', e.target.value !== 'later');
            if (!/^(title|body|media_url|link|channel|scheduled_at|when)$/.test(e.target.name)) count();
          });
          f.addEventListener('input', function (e) { if (e.target.closest('[data-aud="filter"]')) count(); });
          // البحث عن عميل
          var us = document.getElementById('wa-usearch'), sg = document.getElementById('wa-sugg'), st;
          us.addEventListener('input', function () {
            clearTimeout(st); var q = us.value.trim(); document.getElementById('wa-uid').value = '';
            if (q.length < 2) { sg.classList.add('wa-hide'); return; }
            st = setTimeout(function () {
              fetch(API + '?action=users&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d.ok) return;
                sg.innerHTML = d.users.length ? d.users.map(function (u) {
                  return '<button type="button" role="option" data-id="' + u.id + '" data-label="' + esc(u.name + ' — ' + u.email) + '"><b>' + esc(u.name) + '</b> <span class="a2-muted" dir="ltr">' + esc(u.email) + '</span>' +
                    (u.wa ? ' <span class="chip chip-line">واتساب ✓</span>' : ' <span class="a2-muted">بدون واتساب</span>') + '</button>';
                }).join('') : '<div class="a2-muted" style="padding:10px">مفيش نتايج</div>';
                sg.classList.remove('wa-hide');
              });
            }, 250);
          });
          sg.addEventListener('click', function (e) {
            var b = e.target.closest('button[data-id]'); if (!b) return;
            document.getElementById('wa-uid').value = b.dataset.id; us.value = b.dataset.label; sg.classList.add('wa-hide');
            document.getElementById('wa-upicked').textContent = 'اخترت: ' + b.dataset.label; count();
          });
          f.addEventListener('submit', function (e) {
            if (audType() === 'user' && !document.getElementById('wa-uid').value) { e.preventDefault(); alert('اختار العميل من القايمة'); return; }
            if (audType() !== 'user' && !confirm((reach.textContent || 'هتتبعت لعملاء كتير') + ' — متأكد؟')) e.preventDefault();
          });
        })();
        </script>

<?php elseif ($tab === 'campaigns'): ?>
        <?php $bs = db_all('SELECT * FROM message_broadcasts ORDER BY id DESC LIMIT 100'); ?>
        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>#</th><th>العنوان</th><th>الجمهور</th><th>القناة</th><th>الإجمالي</th><th>اتبعت / فشل / طابور</th><th>قراءة</th><th>الميعاد</th><th>الحالة</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($bs as $b): $s = wa_broadcast_stats((int) $b['id']); $aud = json_decode((string) $b['audience_json'], true) ?: []; ?>
                    <tr>
                        <td><?= (int) $b['id'] ?></td>
                        <td><b><?= e($b['title']) ?></b><?= $b['source'] !== 'manual' ? '<br><small class="chip">' . e($b['source']) . '</small>' : '' ?><div class="wa-body"><?= e(mb_substr((string) $b['body'], 0, 120)) ?></div></td>
                        <td><small><?= e(wa_audience_label($aud)) ?></small></td>
                        <td><small><?= ['both' => 'الاتنين', 'inapp' => 'المنصة', 'whatsapp' => 'واتساب'][$b['channel']] ?? e($b['channel']) ?></small></td>
                        <td><?= (int) $b['total'] ?></td>
                        <td><?= $s['sent'] ?> / <?= $s['failed'] ?> / <?= $s['queued'] ?><?= $s['skipped'] ? '<br><small class="a2-muted">اتخطى ' . $s['skipped'] . '</small>' : '' ?></td>
                        <td><?= $s['inapp'] ? $s['read_pct'] . '%' : '—' ?></td>
                        <td><small><?= e(fmt_date((string) $b['scheduled_at'], true)) ?></small></td>
                        <td><span class="chip"><?= e(['draft' => 'مسودة', 'queued' => 'في الطابور', 'sending' => 'بيتبعت', 'done' => 'خلصت', 'cancelled' => 'اتلغت'][$b['status']] ?? $b['status']) ?></span></td>
                        <td><?php if (in_array($b['status'], ['queued', 'sending', 'draft'], true)): ?>
                            <form method="POST" onsubmit="return confirm('إلغاء الحملة؟ الرسايل اللي لسه ماتبعتتش مش هتتبعت')"><?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel_broadcast"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                <button class="btn sm danger">إلغاء</button></form>
                        <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$bs): ?><tr><td colspan="10" class="sub" style="text-align:center;padding:24px">مفيش حملات لسه</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

<?php elseif ($tab === 'log'): ?>
        <?php
        $fs = (string) ($_GET['st'] ?? ''); $ft = (string) ($_GET['type'] ?? ''); $fq = trim((string) ($_GET['q'] ?? ''));
        $ff = (string) ($_GET['from'] ?? ''); $fto = (string) ($_GET['to'] ?? '');
        $w = ['1=1']; $p = [];
        if (in_array($fs, ['none', 'pending', 'processing', 'sent', 'failed', 'skipped'], true)) { $w[] = 'm.wa_status = ?'; $p[] = $fs; }
        if (in_array($ft, ['notification', 'direct', 'broadcast', 'otp'], true)) { $w[] = 'm.type = ?'; $p[] = $ft; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ff)) { $w[] = 'm.created_at >= ?'; $p[] = $ff . ' 00:00:00'; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fto)) { $w[] = 'm.created_at <= ?'; $p[] = $fto . ' 23:59:59'; }
        if ($fq !== '') { $w[] = '(u.name LIKE ? OR u.email LIKE ? OR m.wa_phone LIKE ? OR m.event_key = ?)'; array_push($p, "%$fq%", "%$fq%", '%' . preg_replace('/\D/', '', $fq) . '%', $fq); }
        $where = implode(' AND ', $w);
        $per = 50; $total = (int) (db_one("SELECT COUNT(*) n FROM messages m LEFT JOIN users u ON u.id = m.user_id WHERE $where", $p)['n'] ?? 0);
        $pages = max(1, (int) ceil($total / $per)); $pg = max(1, min($pages, (int) ($_GET['p'] ?? 1)));
        $rows = db_all("SELECT m.*, u.name uname, u.email uemail, un.read_at nread FROM messages m LEFT JOIN users u ON u.id = m.user_id
                        LEFT JOIN user_notifications un ON un.id = m.notif_id WHERE $where ORDER BY m.id DESC LIMIT $per OFFSET " . (($pg - 1) * $per), $p);
        $qs = http_build_query(array_filter(['tab' => 'log', 'st' => $fs, 'type' => $ft, 'q' => $fq, 'from' => $ff, 'to' => $fto]));
        $types = ['notification' => 'إشعار', 'direct' => 'خاصة', 'broadcast' => 'حملة', 'otp' => 'كود تحقق'];
        ?>
        <form method="GET" class="card" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
            <input type="hidden" name="tab" value="log">
            <div class="field" style="margin:0"><label>حالة الواتساب</label><select class="input" name="st"><option value="">الكل</option>
                <?php foreach (['pending' => 'في الطابور', 'processing' => 'بيتبعت', 'sent' => 'اتبعت', 'failed' => 'فشل', 'skipped' => 'اتخطى', 'none' => 'بدون واتساب'] as $k => $l): ?>
                    <option value="<?= $k ?>" <?= $fs === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="field" style="margin:0"><label>النوع</label><select class="input" name="type"><option value="">الكل</option>
                <?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" <?= $ft === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="field" style="margin:0"><label>من</label><input class="input" type="date" name="from" value="<?= e($ff) ?>"></div>
            <div class="field" style="margin:0"><label>لحد</label><input class="input" type="date" name="to" value="<?= e($fto) ?>"></div>
            <div class="field" style="margin:0;flex:1;min-width:180px"><label>العميل / الرقم / الحدث</label><input class="input" name="q" value="<?= e($fq) ?>"></div>
            <button class="btn ghost">فلترة</button>
        </form>
        <div class="a2-muted" style="margin:8px 0"><?= $total ?> رسالة</div>
        <div class="table-wrap">
            <table class="tbl">
                <thead><tr><th>#</th><th>العميل</th><th>النوع / الحدث</th><th>الرسالة</th><th>القناة</th><th>واتساب</th><th>محاولات</th><th>مقروءة</th><th>التاريخ</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int) $r['id'] ?></td>
                        <td><?= $r['user_id'] ? '<b>' . e((string) $r['uname']) . '</b><br><small class="sub" dir="ltr">' . e((string) $r['uemail']) . '</small>' : '<span class="a2-muted">تجربة</span>' ?>
                            <?= $r['wa_phone'] ? '<br><small dir="ltr">+' . e($r['wa_phone']) . '</small>' : '' ?></td>
                        <td><?= e($types[$r['type']] ?? $r['type']) ?><?= $r['event_key'] ? '<br><small class="chip" dir="ltr">' . e($r['event_key']) . '</small>' : '' ?><?= $r['broadcast_id'] ? '<br><small>حملة #' . (int) $r['broadcast_id'] . '</small>' : '' ?></td>
                        <td><b><?= e($r['title']) ?></b><div class="wa-body"><?= e(mb_substr($r['type'] === 'otp' ? preg_replace('/\d{6}/', '••••••', (string) $r['body']) : (string) $r['body'], 0, 160)) ?></div></td>
                        <td><small><?= ['both' => 'الاتنين', 'inapp' => 'المنصة', 'whatsapp' => 'واتساب'][$r['channel']] ?? e($r['channel']) ?></small></td>
                        <td><span class="chip <?= $r['wa_status'] === 'failed' ? 'chip-coral' : ($r['wa_status'] === 'sent' ? 'chip-line' : '') ?>"><?= e(wa_status_label((string) $r['wa_status'], $r['wa_error'])) ?></span>
                            <?= $r['wa_status'] === 'failed' && $r['wa_error'] ? '<br><small class="sub">' . e(mb_substr((string) $r['wa_error'], 0, 120)) . '</small>' : '' ?>
                            <?= $r['scheduled_at'] && strtotime((string) $r['scheduled_at']) > time() && $r['wa_status'] === 'pending' ? '<br><small class="sub">ميعادها ' . e(fmt_date((string) $r['scheduled_at'], true)) . '</small>' : '' ?></td>
                        <td><?= (int) $r['wa_attempts'] ?></td>
                        <td><?= $r['notif_id'] ? ($r['nread'] ? '✓' : '—') : '' ?></td>
                        <td><small><?= e(fmt_date((string) $r['created_at'], true)) ?></small></td>
                        <td><?php if (in_array($r['wa_status'], ['failed', 'skipped'], true) && $r['wa_phone'] && $r['wa_error'] !== 'cancelled'): ?>
                            <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <input type="hidden" name="qs" value="<?= e(substr($qs, 8)) ?>"><button class="btn sm soft">إعادة إرسال</button></form>
                        <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="10" class="sub" style="text-align:center;padding:24px">مفيش رسايل</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <div class="pagination" style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
                <?php for ($i = max(1, $pg - 4); $i <= min($pages, $pg + 4); $i++): ?>
                    <a class="btn sm <?= $i === $pg ? '' : 'ghost' ?>" href="?<?= e($qs) ?>&p=<?= $i ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

<?php elseif ($tab === 'templates'): ?>
        <?php
        $cat = wa_event_catalog();
        $tpls = [];
        foreach (db_all('SELECT * FROM message_templates') as $t) $tpls[$t['event_key']] = $t;
        $edit = preg_replace('/[^a-z_]/', '', (string) ($_GET['edit'] ?? ''));
        ?>
        <?php if ($edit && isset($cat[$edit], $tpls[$edit])): $t = $tpls[$edit]; $c = $cat[$edit]; $cond = json_decode((string) ($t['audience_json'] ?? ''), true) ?: []; ?>
            <form method="POST" data-safe-post class="card">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_template"><input type="hidden" name="event_key" value="<?= e($edit) ?>">
                <div class="card-head"><h3><?= e($c[0]) ?> <small class="chip" dir="ltr"><?= e($edit) ?></small><?= (int) $t['is_critical'] ? ' <small class="chip chip-amber">مهم: بيتخطى الحد اليومي وساعات الهدوء</small>' : '' ?></h3></div>
                <div class="a2-grid a2-side" style="gap:14px;align-items:start">
                    <div>
                        <div class="field"><label>العنوان (جوه المنصة)</label><input class="input" name="title" id="tp-title" maxlength="150" value="<?= e($t['title']) ?>" <?= $canSettings ? '' : 'disabled' ?>></div>
                        <div class="field"><label>النص</label><textarea class="input" name="body" id="tp-body" rows="5" <?= $canSettings ? '' : 'disabled' ?>><?= e($t['body']) ?></textarea>
                            <div class="field-help wa-vars">المتغيرات: <?php foreach (array_merge(['name', 'first_name', 'credits', 'link'], $c[1]) as $v): ?><code data-v="<?= e($v) ?>">{{<?= e($v) ?>}}</code><?php endforeach; ?></div></div>
                        <?php if ($edit !== 'otp'): ?>
                        <div class="a2-grid a2-g3" style="gap:10px">
                            <div class="field"><label>القناة الافتراضية</label><select class="input" name="default_channel">
                                <?php foreach (['both' => 'المنصة + واتساب', 'inapp' => 'المنصة بس', 'whatsapp' => 'واتساب بس'] as $k => $l): ?>
                                    <option value="<?= $k ?>" <?= $t['default_channel'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
                                <?php if ($edit === 'notification'): ?><div class="field-help">الإشعار نفسه بيظهر في المنصة دايمًا — ده بيتحكم في نسخة الواتساب بس</div><?php endif; ?></div>
                            <div class="field"><label>الحالة</label><label style="display:flex;gap:8px;align-items:center;cursor:pointer;font-weight:400"><input type="checkbox" name="is_active" value="1" <?= (int) $t['is_active'] ? 'checked' : '' ?> style="width:auto"> مفعّل</label></div>
                        </div>
                        <div class="card" style="background:#F8FAFC;margin-top:10px">
                            <div class="card-head"><h3>الشروط — مين يستلم الإشعار ده؟</h3></div>
                            <p class="a2-muted" style="margin-top:0">سيبها فاضية = كل العملاء. مثال: العروض الجديدة للي مش مشتركين بس، أو «وحشتنا» للي رصيدهم أكبر من صفر.</p>
                            <?= wa_filter_fields($packages, $cond, 'c_') ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($canSettings): ?><button class="btn" style="margin-top:10px">💾 حفظ القالب</button><?php endif; ?>
                        <a class="btn ghost" href="?tab=templates" style="margin-top:10px">رجوع</a>
                    </div>
                    <div class="card" style="position:sticky;top:12px">
                        <div class="card-head"><h3>معاينة ببيانات وهمية</h3></div>
                        <p style="margin:0 0 8px"><b id="tp-ptitle"></b></p>
                        <div class="wa-preview"><div class="wa-bubble" id="tp-bubble"></div></div>
                    </div>
                </div>
            </form>
            <script>
            (function () {
              var demo = { name: 'أحمد محمد', first_name: 'أحمد', credits: '40% من باقتك', link: <?= json_encode(rtrim(APP_URL, '/') . '/dashboard.php') ?>, title: 'اتفعّلت باقة «Pro» 🎉',
                body: 'تقدر تبدأ تستخدمها دلوقتي', count: '5', campaign: 'حملة الشتا', page_name: 'Spread Agency', date: '20 أكتوبر', plan: 'Pro', reward: '100 كريدت',
                days: '7', offer_title: 'خصم 30% على الباقات', offer_desc: 'لفترة محدودة', code: 'WINTER30', ann_title: 'ميزة جديدة 🎨', ann_body: 'جرّب استوديو التصميم الجديد', code6: '123456' };
              function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
              function rend(s) { return s.replace(/\{\{\s*([a-z_]+)\s*\}\}/gi, function (m, k) { k = k.toLowerCase(); return k === 'code' && <?= json_encode($edit === 'otp') ?> ? demo.code6 : (demo[k] || ''); }); }
              var tb = document.getElementById('tp-body'), tt = document.getElementById('tp-title');
              function upd() {
                document.getElementById('tp-ptitle').textContent = rend(tt.value);
                document.getElementById('tp-bubble').innerHTML = esc(rend(tb.value)).replace(/\*([^*\n]+)\*/g, '<b>$1</b>');
              }
              tb.addEventListener('input', upd); tt.addEventListener('input', upd); upd();
              document.querySelectorAll('[data-v]').forEach(function (c) { c.addEventListener('click', function () {
                if (tb.disabled) return; var p = tb.selectionStart || tb.value.length; tb.value = tb.value.slice(0, p) + '{{' + c.dataset.v + '}}' + tb.value.slice(p); upd(); tb.focus(); }); });
            })();
            </script>
        <?php else: ?>
            <div class="table-wrap">
                <table class="tbl">
                    <thead><tr><th>الحدث</th><th>العنوان</th><th>النص</th><th>القناة</th><th>شروط</th><th>الحالة</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($cat as $k => $c): $t = $tpls[$k] ?? null; if (!$t) continue; ?>
                        <tr>
                            <td><b><?= e($c[0]) ?></b><br><small class="chip" dir="ltr"><?= e($k) ?></small><?= (int) $t['is_critical'] ? ' <small class="chip chip-amber">مهم</small>' : '' ?></td>
                            <td><?= e($t['title']) ?></td>
                            <td><div class="wa-body"><?= e(mb_substr((string) $t['body'], 0, 140)) ?></div></td>
                            <td><small><?= ['both' => 'الاتنين', 'inapp' => 'المنصة', 'whatsapp' => 'واتساب'][$t['default_channel']] ?? '' ?></small></td>
                            <td><small><?= $t['audience_json'] ? e(wa_audience_label(json_decode((string) $t['audience_json'], true) ?: [])) : '—' ?></small></td>
                            <td><?= (int) $t['is_active'] ? '<span class="chip chip-line">مفعّل</span>' : '<span class="chip">مقفول</span>' ?></td>
                            <td><a class="btn sm" href="?tab=templates&edit=<?= e($k) ?>"><?= $canSettings ? 'تعديل' : 'عرض' ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="a2-muted" style="margin-top:10px">«أي إشعار جديد في المنصة» = كل إشعار بيظهر للعميل في الجرس (تفعيل الباقة · رفض الدفع · …) بيتبعتله كمان على الواتساب.
                «عرض جديد» و«إعلان جديد» بيتبعتوا لوحدهم لما تضيف كود خصم/حملة جديدة أو إعلان جديد — حسب الشروط اللي تحطها.</p>
        <?php endif; ?>

<?php elseif ($tab === 'settings'): ?>
        <?php $secret = wa_setting('wa_shared_secret'); ?>
        <form method="POST" data-safe-post class="card">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_settings">
            <div class="card-head"><h3>الإرسال والربط مع n8n</h3></div>
            <div class="a2-grid a2-g3" style="gap:12px">
                <div class="field"><label>الإرسال على الواتساب</label><label style="display:flex;gap:8px;align-items:center;cursor:pointer;font-weight:400">
                    <input type="checkbox" name="wa_enabled" value="1" <?= $enabled ? 'checked' : '' ?> <?= $canSettings ? '' : 'disabled' ?> style="width:auto"> مفعّل</label>
                    <div class="field-help">لو مقفول: الإشعارات بتظهر جوه المنصة بس</div></div>
                <div class="field"><label>كل إشعار في المنصة يروح واتساب</label><label style="display:flex;gap:8px;align-items:center;cursor:pointer;font-weight:400">
                    <input type="checkbox" name="wa_mirror_notifications" value="1" <?= wa_setting('wa_mirror_notifications') === '1' ? 'checked' : '' ?> <?= $canSettings ? '' : 'disabled' ?> style="width:auto"> مفعّل</label></div>
                <div class="field"><label>الحد اليومي لكل عميل</label><input class="input" type="number" min="1" max="50" name="wa_daily_limit" value="<?= e(wa_setting('wa_daily_limit')) ?>" <?= $canSettings ? '' : 'disabled' ?>>
                    <div class="field-help">الإشعارات المهمة والرسايل الإدارية مش بتتحسب</div></div>
                <div class="field"><label>ساعات الهدوء من</label><input class="input" type="time" name="wa_quiet_from" value="<?= e(wa_setting('wa_quiet_from')) ?>" <?= $canSettings ? '' : 'disabled' ?>></div>
                <div class="field"><label>لحد</label><input class="input" type="time" name="wa_quiet_to" value="<?= e(wa_setting('wa_quiet_to')) ?>" <?= $canSettings ? '' : 'disabled' ?>>
                    <div class="field-help">توقيت القاهرة — الإشعار العادي بيتأجل لآخرها</div></div>
            </div>
            <div class="field"><label>لينك الـ Webhook (Production URL من n8n)</label>
                <input class="input" dir="ltr" name="wa_n8n_webhook_url" value="<?= e(wa_setting('wa_n8n_webhook_url')) ?>" placeholder="https://n8n.example.com/webhook/spread-wa-send" <?= $canSettings ? '' : 'disabled' ?>></div>
            <div class="field"><label>المفتاح المشترك (X-Spread-Key)</label>
                <input class="input" dir="ltr" name="wa_shared_secret" value="" autocomplete="off" placeholder="<?= $secret !== '' ? '•••••••• ' . e(substr($secret, -4)) . ' — سيبه فاضي علشان مايتغيّرش' : 'الصق نفس المفتاح اللي في n8n' ?>" <?= $canSettings ? '' : 'disabled' ?>>
                <div class="field-help">لازم يبقى نفس القيمة في نود Config في n8n. الموقع بيبعته مع كل طلب، وn8n بيبعته لما يسحب الرسايل ويرجّع النتيجة.</div></div>
            <?php if ($canSettings): ?><button class="btn">💾 حفظ</button><?php endif; ?>
        </form>
        <?php if ($canSettings): ?>
        <div class="a2-grid a2-g3" style="gap:14px;align-items:start">
            <form method="POST" data-safe-post class="card">
                <?= csrf_field() ?><input type="hidden" name="action" value="test_message">
                <div class="card-head"><h3>رسالة تجريبية</h3></div>
                <div class="field"><label>الرقم</label><input class="input" dir="ltr" name="test_phone" placeholder="01xxxxxxxxx"></div>
                <div class="field"><label>النص</label><input class="input" name="test_text" value="رسالة تجريبية من Spread AI ✅"></div>
                <button class="btn soft">📲 ابعت</button>
            </form>
            <form method="POST" class="card" onsubmit="return confirm('مفتاح جديد؟ لازم تحطه في n8n بعدها وإلا الرسايل هتقف')">
                <?= csrf_field() ?><input type="hidden" name="action" value="gen_secret">
                <div class="card-head"><h3>مفتاح جديد</h3></div>
                <p class="a2-muted" style="margin-top:0">لو المفتاح اتسرّب. الحالي بينتهي بـ <b dir="ltr"><?= $secret !== '' ? e(substr($secret, -4)) : '—' ?></b>.</p>
                <button class="btn ghost">🔑 اعمل مفتاح جديد</button>
            </form>
            <div class="card">
                <div class="card-head"><h3>روابط n8n</h3></div>
                <p class="a2-muted" style="margin-top:0">n8n بيكلّم الموقع على:</p>
                <p dir="ltr" style="font-size:12px;word-break:break-all">GET <?= e(rtrim(APP_URL, '/')) ?>/api/wa/pending.php?limit=20<br>POST <?= e(rtrim(APP_URL, '/')) ?>/api/wa/callback.php</p>
            </div>
        </div>
        <?php endif; ?>
<?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../templates/admin-footer.php'; ?>
