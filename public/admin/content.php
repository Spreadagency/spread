<?php
declare(strict_types=1);

/**
 * محتوى الصفحة: doctor info, texts & contact, stats / experience / steps / FAQ / loading facts (CRUD + reorder),
 * branches (CRUD + reorder). Live mobile preview of the public page.
 */
require dirname(__DIR__, 2) . '/app/admin/bootstrap.php';

admin_require('view');

const ITEM_TABS = [
    'stats' => ['stat', 'الأرقام', 'رقم', 'الأرقام بتظهر في قسم الدكتور. الرقم الفاضي أو المقفول مش بيظهر — اتأكد من الأرقام مع الدكتور قبل التفعيل.'],
    'experience' => ['experience', 'الخبرات والشهادات', 'خبرة', 'بتظهر كـ Timeline. خانة "السنة" اختيارية (مثلًا 2015 أو حاليًا).'],
    'steps' => ['step', 'خطوات العملية', 'خطوة', 'الخطوات اللي بتظهر في قسم "رحلتك مع العملية".'],
    'faq' => ['faq', 'الأسئلة الشائعة', 'سؤال', 'بتظهر كـ Accordion وبتتضاف لـ FAQPage schema.'],
    'facts' => ['fact', 'معلومات التحميل', 'معلومة', 'بتتبدل كل 4 ثواني والصورة بتتولد.'],
];
const TABS = ['doctor' => 'الدكتور', 'texts' => 'كل النصوص', 'contact' => 'التواصل والروابط', 'stats' => 'الأرقام', 'experience' => 'الخبرات', 'steps' => 'الخطوات', 'faq' => 'الأسئلة', 'facts' => 'معلومات التحميل', 'branches' => 'الفروع'];
const ICONS = ['chat' => 'محادثة', 'flask' => 'تحاليل', 'pulse' => 'نبض', 'clock' => 'ساعة', 'heart' => 'قلب', 'smile' => 'ابتسامة', 'check' => 'صح', 'spark' => 'نجمة', 'user' => 'شخص', 'lock' => 'قفل', 'pin' => 'مكان', 'phone' => 'تليفون'];

$tab = array_key_exists($_GET['tab'] ?? '', TABS) ? $_GET['tab'] : 'doctor';

/* ---------------- POST ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_post('edit');
    $action = (string) ($_POST['action'] ?? '');
    $tab = array_key_exists($_POST['tab'] ?? '', TABS) ? $_POST['tab'] : $tab;
    $back = 'content.php?tab=' . $tab;

    try {
        switch ($action) {
            case 'settings':
                if ($tab === 'doctor') {
                    foreach (['doctor_photo' => 1800, 'logo' => 1200] as $field => $max) {
                        if ($path = store_site_image($field, $max)) {
                            Settings::set($field, $path);
                        }
                    }
                    $changed = save_settings_from_post(['doctor_name', 'doctor_title', 'doctor_bio'], ['show_logo_on_result']);
                } elseif ($tab === 'texts') {
                    $keys = array_keys(texts_defaults());
                    $defaults = texts_defaults();
                    foreach ($keys as $k) {
                        if (isset($_POST[$k])) {
                            $v = trim(mb_substr(str_replace("\r\n", "\n", (string) $_POST[$k]), 0, 2000));
                            $_POST[$k] = $v === $defaults[$k] ? '' : $v; // unchanged default → stored empty, so it follows future defaults
                        }
                    }
                    $changed = save_settings_from_post($keys);
                } else {
                    $wa = preg_replace('/\D/', '', (string) ($_POST['whatsapp_number'] ?? ''));
                    if ($wa !== '' && str_starts_with($wa, '01')) {
                        $wa = '2' . $wa; // 01xxxxxxxxx → 201xxxxxxxxx
                    }
                    $_POST['whatsapp_number'] = $wa;
                    foreach (['website_url', 'social_facebook', 'social_instagram', 'social_youtube', 'social_tiktok'] as $k) {
                        $v = trim((string) ($_POST[$k] ?? ''));
                        if ($v !== '' && !preg_match('#^https://#', $v)) {
                            throw new RuntimeException('الروابط لازم تبدأ بـ https://');
                        }
                    }
                    $changed = save_settings_from_post(['whatsapp_number', 'whatsapp_message', 'website_url', 'social_facebook', 'social_instagram', 'social_youtube', 'social_tiktok']);
                }
                admin_log('content_update', $tab . ': ' . implode(', ', $changed));
                flash('ok', 'اتحفظ واتنشر على الصفحة');
                break;

            case 'item_save':
                $type = ITEM_TABS[$tab][0] ?? null;
                $title = trim((string) ($_POST['title'] ?? ''));
                if (!$type || $title === '') {
                    throw new RuntimeException('العنوان مطلوب.');
                }
                $data = [
                    mb_substr($title, 0, 255),
                    ($b = trim((string) ($_POST['body'] ?? ''))) === '' ? null : $b,
                    array_key_exists($_POST['icon'] ?? '', ICONS) ? $_POST['icon'] : null,
                    ($v = trim((string) ($_POST['value'] ?? ''))) === '' ? null : mb_substr($v, 0, 100),
                    !empty($_POST['is_active']) ? 1 : 0,
                ];
                $id = (int) ($_POST['id'] ?? 0);
                if ($id) {
                    q('UPDATE content_items SET title = ?, body = ?, icon = ?, value = ?, is_active = ? WHERE id = ? AND type = ?', array_merge($data, [$id, $type]));
                } else {
                    $order = (int) q_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM content_items WHERE type = ?', [$type]);
                    q('INSERT INTO content_items (title, body, icon, value, is_active, type, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)', array_merge($data, [$type, $order]));
                }
                admin_log('content_item_save', "$type #" . ($id ?: db()->lastInsertId()));
                flash('ok', $id ? 'اتحفظ' : 'اتضاف');
                break;

            case 'item_delete':
                q('DELETE FROM content_items WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
                admin_log('content_item_delete', '#' . (int) ($_POST['id'] ?? 0));
                flash('ok', 'اتمسح');
                break;

            case 'branch_save':
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') {
                    throw new RuntimeException('اسم الفرع مطلوب.');
                }
                $mapUrl = trim((string) ($_POST['map_url'] ?? ''));
                $embed = trim((string) ($_POST['map_embed'] ?? ''));
                if (preg_match('/src="([^"]+)"/', $embed, $m)) {
                    $embed = html_entity_decode($m[1]); // pasted the whole <iframe> code
                }
                if ($mapUrl !== '' && !preg_match('#^https://#', $mapUrl)) {
                    throw new RuntimeException('لينك الخريطة لازم يبدأ بـ https://');
                }
                if ($embed !== '' && !preg_match('#^https://(www\.)?(google\.[a-z.]+|maps\.google\.[a-z.]+)/#', $embed)) {
                    throw new RuntimeException('كود الخريطة لازم يكون من Google Maps (مشاركة ← تضمين خريطة).');
                }
                $data = [mb_substr($name, 0, 150), trim((string) ($_POST['address'] ?? '')) ?: null, $mapUrl ?: null, $embed ?: null,
                    trim((string) ($_POST['phone'] ?? '')) ?: null, trim((string) ($_POST['working_hours'] ?? '')) ?: null, !empty($_POST['is_active']) ? 1 : 0];
                $id = (int) ($_POST['id'] ?? 0);
                if ($id) {
                    q('UPDATE branches SET name = ?, address = ?, map_url = ?, map_embed = ?, phone = ?, working_hours = ?, is_active = ? WHERE id = ?', array_merge($data, [$id]));
                } else {
                    $order = (int) q_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM branches');
                    q('INSERT INTO branches (name, address, map_url, map_embed, phone, working_hours, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', array_merge($data, [$order]));
                }
                admin_log('branch_save', $name);
                flash('ok', $id ? 'اتحفظ الفرع' : 'اتضاف الفرع');
                break;

            case 'branch_delete':
                q('DELETE FROM branches WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
                admin_log('branch_delete', '#' . (int) ($_POST['id'] ?? 0));
                flash('ok', 'اتمسح الفرع');
                break;

            case 'reorder': // AJAX: ids in the new order
                $table = ($_POST['kind'] ?? '') === 'branches' ? 'branches' : 'content_items';
                foreach (array_values((array) ($_POST['ids'] ?? [])) as $i => $id) {
                    q("UPDATE $table SET sort_order = ? WHERE id = ?", [$i + 1, (int) $id]);
                }
                admin_log('content_reorder', $table);
                json_response(['ok' => true, 'message' => 'اتحفظ الترتيب']);

            case 'toggle': // AJAX
                $table = ($_POST['kind'] ?? '') === 'branches' ? 'branches' : 'content_items';
                q("UPDATE $table SET is_active = ? WHERE id = ?", [!empty($_POST['on']) ? 1 : 0, (int) ($_POST['id'] ?? 0)]);
                json_response(['ok' => true, 'message' => !empty($_POST['on']) ? 'اتفعّل' : 'اتقفل']);
        }
    } catch (RuntimeException $e) {
        if (is_ajax()) {
            json_response(['error' => $e->getMessage()], 422);
        }
        flash('err', $e->getMessage());
    }
    redirect($back);
}

/* ---------------- view ---------------- */
$canEdit = AdminAuth::can('edit');

function item_form(string $tab, ?array $it): string
{
    $kind = ITEM_TABS[$tab][0];
    $id = (int) ($it['id'] ?? 0);
    $valueLabel = ['stat' => 'الرقم (مثلًا +12 أو 98%)', 'experience' => 'السنة (اختياري)'][$kind] ?? null;
    $bodyLabel = ['stat' => null, 'fact' => null, 'experience' => 'الجهة / التفاصيل', 'step' => 'الوصف', 'faq' => 'الإجابة'][$kind] ?? null;
    $html = '<form method="post" class="item-form form-grid">' . csrf_field()
        . '<input type="hidden" name="action" value="item_save"><input type="hidden" name="tab" value="' . e($tab) . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<div class="field' . ($valueLabel ? '' : ' full') . '"><label>' . ($kind === 'faq' ? 'السؤال' : ($kind === 'fact' ? 'المعلومة' : 'العنوان')) . '</label><input class="input" name="title" required maxlength="255" value="' . e($it['title'] ?? '') . '"' . ro() . '></div>';
    if ($valueLabel) {
        $html .= '<div class="field"><label>' . e($valueLabel) . '</label><input class="input" name="value" maxlength="100" value="' . e($it['value'] ?? '') . '"' . ro() . '></div>';
    }
    if ($bodyLabel) {
        $html .= '<div class="field full"><label>' . e($bodyLabel) . '</label><textarea class="textarea" name="body" rows="' . ($kind === 'faq' ? 3 : 2) . '"' . ro() . '>' . e($it['body'] ?? '') . '</textarea></div>';
    }
    if (in_array($kind, ['step', 'stat'], true)) {
        $html .= '<div class="field"><label>الأيقونة</label><select class="select" name="icon"' . ro() . '>';
        foreach (ICONS as $k => $l) {
            $html .= '<option value="' . $k . '"' . (($it['icon'] ?? '') === $k ? ' selected' : '') . '>' . e($l) . '</option>';
        }
        $html .= '</select></div>';
    }
    $html .= '<div class="field full row between wrap">' . f_toggle('is_active', 'ظاهر في الصفحة', (bool) ($it['is_active'] ?? 1));
    if (AdminAuth::can('edit')) {
        $html .= '<div class="row"><button class="btn btn-primary btn-sm" type="submit" data-loading>' . ic('check', 'sm') . ($id ? 'احفظ' : 'ضيف') . '</button></div>';
    }
    return $html . '</div></form>';
}

admin_page_start('محتوى الصفحة', 'content.php');
?>
<div class="page-head"><div><p>كل اللي بيتغير هنا بيظهر في الصفحة على طول من غير ما حد يلمس الكود</p></div><a class="btn btn-secondary" href="../" target="_blank" rel="noopener"><?= ic('globe', 'sm') ?>افتح الصفحة</a></div>
<div class="content-grid">
  <section class="card">
    <nav class="tabs" aria-label="أقسام المحتوى"><?php foreach (TABS as $k => $l): ?><a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>

    <?php if ($tab === 'doctor'): ?>
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="settings"><input type="hidden" name="tab" value="doctor">
      <div class="card-b form-grid">
        <?= f_image('doctor_photo', 'صورة الدكتور', (string) Settings::get('doctor_photo'), 'PNG بخلفية شفافة أو JPG — رأسية') ?>
        <?= f_image('logo', 'اللوجو', (string) Settings::get('logo'), 'PNG بخلفية شفافة') ?>
        <?= f_text('doctor_name', 'الاسم', Settings::get('doctor_name'), ['required' => 1]) ?>
        <?= f_text('doctor_title', 'اللقب', Settings::get('doctor_title')) ?>
        <?= f_textarea('doctor_bio', 'نبذة', Settings::get('doctor_bio'), ['full' => 1, 'rows' => 4]) ?>
        <div class="full"><?= f_toggle('show_logo_on_result', 'اعرض لوجو الدكتور على صورة النتيجة', Settings::bool('show_logo_on_result')) ?></div>
      </div>
      <div class="card-f"><?= save_bar('انشر التغييرات') ?></div>
    </form>

    <?php elseif ($tab === 'texts'): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="settings"><input type="hidden" name="tab" value="texts">
      <div class="card-b stack" style="gap:12px">
        <p class="hint"><?= ic('info', 'sm') ?> كل كلمة في الصفحة موجودة هنا. لو سبت خانة فاضية هيرجع النص الأصلي، ولو كتبت <b class="ltr mono">-</b> بس النص ده هيختفي من الصفحة.</p>
        <?php $first = true; foreach (texts_registry() as $gid => [$gTitle, $gHint, $items]): ?>
        <details class="tgroup"<?= $first ? ' open' : '' ?> id="tg-<?= e($gid) ?>"><summary><span><?= e($gTitle) ?> <small>· <?= e($gHint) ?></small></span></summary>
          <div class="tg-body"><div class="form-grid">
          <?php foreach ($items as $key => $def): $o = $def[2] ?? []; $val = (string) (Settings::all()[$key] ?? ''); $val = $val === '' ? $def[1] : $val; $hint = ($o['hint'] ?? '');
              if ($val !== $def[1]) { $hint .= ($hint ? ' · ' : '') . 'متغيّر — الأصلي: <span class="muted">' . e(mb_strimwidth($def[1], 0, 90, '…')) . '</span>'; } ?>
            <?= !empty($o['rows']) || mb_strlen($def[1]) > 70
                ? f_textarea($key, $def[0], $val, ['full' => 1, 'rows' => $o['rows'] ?? 2, 'hint' => $hint])
                : f_text($key, $def[0], $val, ['placeholder' => $def[1], 'hint' => $hint]) ?>
          <?php endforeach; ?>
          </div></div></details>
        <?php $first = false; endforeach; ?>
      </div>
      <div class="card-f"><?= save_bar('انشر التغييرات') ?></div>
    </form>

    <?php elseif ($tab === 'contact'): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="settings"><input type="hidden" name="tab" value="contact">
      <div class="card-b form-grid">
        <?= f_text('whatsapp_number', 'رقم الواتساب (دولي)', Settings::get('whatsapp_number', ''), ['ltr' => 1, 'mono' => 1, 'placeholder' => '2010XXXXXXXX', 'hint' => 'لو كتبته 01… هيتحول لـ 201… تلقائي']) ?>
        <?= f_text('website_url', 'رابط موقع الدكتور', Settings::get('website_url', ''), ['ltr' => 1, 'mono' => 1, 'placeholder' => 'https://']) ?>
        <?= f_textarea('whatsapp_message', 'رسالة واتساب الجاهزة', Settings::get('whatsapp_message'), ['full' => 1, 'rows' => 2, 'hint' => '<span class="ltr mono">{name}</span> بيتبدل باسم المسجل']) ?>
        <?= f_text('social_facebook', 'فيسبوك', Settings::get('social_facebook', ''), ['ltr' => 1, 'placeholder' => 'https://facebook.com/…']) ?>
        <?= f_text('social_instagram', 'إنستجرام', Settings::get('social_instagram', ''), ['ltr' => 1, 'placeholder' => 'https://instagram.com/…']) ?>
        <?= f_text('social_youtube', 'يوتيوب', Settings::get('social_youtube', ''), ['ltr' => 1, 'placeholder' => 'https://youtube.com/…']) ?>
        <?= f_text('social_tiktok', 'تيك توك', Settings::get('social_tiktok', ''), ['ltr' => 1, 'placeholder' => 'https://tiktok.com/@…']) ?>
      </div>
      <div class="card-f"><?= save_bar('انشر التغييرات') ?></div>
    </form>

    <?php elseif ($tab === 'branches'): $branches = q('SELECT * FROM branches ORDER BY sort_order, id')->fetchAll(); ?>
    <div class="card-b"><p class="hint" style="margin-bottom:12px"><?= ic('info', 'sm') ?> أول فرع ظاهر هو اللي الخريطة بتاعته بتتعرض في الصفحة. اسحب الكروت عشان ترتّبهم.</p>
      <div class="branch-list" data-sortable="branches">
      <?php foreach ($branches as $b): ?>
        <details class="card branch-card" data-id="<?= (int) $b['id'] ?>"<?= $b['is_active'] ? '' : ' style="border-style:dashed"' ?>>
          <summary class="row between"><span class="row"><?php if ($canEdit): ?><span class="drag" title="اسحب لإعادة الترتيب"><?= ic('drag') ?></span><?php endif; ?><b style="color:var(--ink)"><?= ic('pin', 'sm') ?> <?= e($b['name']) ?></b></span><span class="badge <?= $b['is_active'] ? 'b-booked' : 'b-lost' ?>"><?= $b['is_active'] ? 'ظاهر' : 'مخفي' ?></span></summary>
          <p class="small muted"><?= e($b['address'] ?: 'العنوان مش متكتب') ?> · <?= e($b['working_hours'] ?: 'المواعيد مش متكتبة') ?></p>
          <form method="post" class="form-grid" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="branch_save"><input type="hidden" name="tab" value="branches"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <?= f_text('name', 'اسم الفرع', $b['name'], ['required' => 1]) ?>
            <?= f_text('phone', 'رقم التليفون', $b['phone'], ['ltr' => 1, 'placeholder' => '+20 10 XXXX XXXX']) ?>
            <?= f_text('address', 'العنوان', $b['address'], ['full' => 1]) ?>
            <?= f_text('working_hours', 'مواعيد العمل', $b['working_hours'], ['full' => 1, 'placeholder' => 'السبت – الخميس · 5م – 10م']) ?>
            <?= f_text('map_url', 'لينك الاتجاهات (Google Maps)', $b['map_url'], ['full' => 1, 'ltr' => 1]) ?>
            <?= f_textarea('map_embed', 'كود تضمين الخريطة (اختياري)', $b['map_embed'], ['full' => 1, 'rows' => 2, 'ltr' => 1, 'hint' => 'من Google Maps ← مشاركة ← تضمين خريطة. ممكن تلزق كود الـ iframe كله.']) ?>
            <div class="full row between wrap"><?= f_toggle('is_active', 'ظاهر في الصفحة', (bool) $b['is_active']) ?>
              <?php if ($canEdit): ?><div class="row"><button class="btn btn-primary btn-sm" type="submit" data-loading><?= ic('check', 'sm') ?>احفظ</button></div><?php endif; ?></div>
          </form>
          <?php if ($canEdit): ?><form method="post" data-confirm-title="تمسح الفرع؟" data-confirm-body="هيختفي من الصفحة على طول." class="row" style="justify-content:flex-end;margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="branch_delete"><input type="hidden" name="tab" value="branches"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn btn-danger-ghost btn-sm" type="submit"><?= ic('trash', 'sm') ?>امسح الفرع</button></form><?php endif; ?>
        </details>
      <?php endforeach; ?>
      </div>
      <?php if (!$branches): ?><?= empty_block('pin', 'مفيش فروع', 'ضيف أول فرع عشان قسم "تعالى زورنا" يظهر في الصفحة.') ?><?php endif; ?>
      <?php if ($canEdit): ?>
      <details class="card add-details"<?= $branches ? '' : ' open' ?>><summary class="add-card"><?= ic('plus', 'lg') ?><span>إضافة فرع</span></summary>
        <form method="post" class="form-grid" style="padding:16px"><?= csrf_field() ?><input type="hidden" name="action" value="branch_save"><input type="hidden" name="tab" value="branches">
          <?= f_text('name', 'اسم الفرع', '', ['required' => 1]) ?><?= f_text('phone', 'رقم التليفون', '', ['ltr' => 1]) ?>
          <?= f_text('address', 'العنوان', '', ['full' => 1]) ?><?= f_text('working_hours', 'مواعيد العمل', '', ['full' => 1]) ?>
          <?= f_text('map_url', 'لينك الاتجاهات (Google Maps)', '', ['full' => 1, 'ltr' => 1]) ?>
          <?= f_textarea('map_embed', 'كود تضمين الخريطة (اختياري)', '', ['full' => 1, 'rows' => 2, 'ltr' => 1]) ?>
          <div class="full row between"><?= f_toggle('is_active', 'ظاهر في الصفحة', true) ?><button class="btn btn-primary btn-sm" type="submit" data-loading><?= ic('plus', 'sm') ?>ضيف الفرع</button></div>
        </form></details>
      <?php endif; ?>
    </div>

    <?php else: [$kind, $title, $single, $help] = ITEM_TABS[$tab]; $items = q('SELECT * FROM content_items WHERE type = ? ORDER BY sort_order, id', [$kind])->fetchAll(); ?>
    <div class="card-b" style="padding-bottom:8px"><p class="hint"><?= ic('info', 'sm') ?> <?= e($help) ?></p></div>
    <div class="list" data-sortable="items">
      <?php foreach ($items as $it): ?>
      <details class="li-details" data-id="<?= (int) $it['id'] ?>">
        <summary class="li"><?php if ($canEdit): ?><span class="drag" title="اسحب لإعادة الترتيب"><?= ic('drag') ?></span><?php endif; ?>
          <div class="grow"><b><?= e($it['title']) ?><?= $it['value'] !== null && $it['value'] !== '' ? ' <span class="badge b-gold plain ltr">' . e($it['value']) . '</span>' : ($kind === 'stat' ? ' <span class="badge b-danger plain">الرقم فاضي</span>' : '') ?></b><?php if ($it['body']): ?><small><?= e(mb_strimwidth((string) $it['body'], 0, 90, '…')) ?></small><?php endif; ?></div>
          <label class="switch" title="ظاهر"><input type="checkbox" data-toggle-item="<?= (int) $it['id'] ?>" <?= $it['is_active'] ? 'checked' : '' ?><?= ro() ?> aria-label="ظاهر"><span class="tr"></span></label>
          <span class="btn btn-icon btn-ghost" aria-hidden="true"><?= ic('edit', 'sm') ?></span>
        </summary>
        <div class="li-body"><?= item_form($tab, $it) ?>
          <?php if ($canEdit): ?><form method="post" data-confirm-title="تمسح العنصر ده؟" data-confirm-body="هيختفي من الصفحة على طول." class="row" style="justify-content:flex-end"><?= csrf_field() ?><input type="hidden" name="action" value="item_delete"><input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><button class="btn btn-danger-ghost btn-sm" type="submit"><?= ic('trash', 'sm') ?>امسح</button></form><?php endif; ?>
        </div>
      </details>
      <?php endforeach; ?>
    </div>
    <?php if (!$items): ?><?= empty_block('list', 'القائمة فاضية', 'ضيف أول ' . $single . ' من تحت.') ?><?php endif; ?>
    <?php if ($canEdit): ?>
    <details class="add-details" style="margin:16px"><summary class="add-card"><?= ic('plus', 'lg') ?><span>إضافة <?= e($single) ?></span></summary><div style="padding:16px"><?= item_form($tab, null) ?></div></details>
    <?php endif; ?>
    <?php endif; ?>
  </section>

  <aside class="stack preview-col" style="gap:12px"><div class="row between"><span class="h-sec">معاينة موبايل</span><button class="btn btn-ghost btn-sm" type="button" data-reload-preview><?= ic('refresh', 'sm') ?>تحديث</button></div>
    <div class="phone-frame"><div class="screen"><span class="notch"></span><iframe src="../?preview=1" title="معاينة الصفحة على الموبايل" loading="lazy" id="prevFrame"></iframe></div></div></aside>
</div>
<?php
admin_page_end();
