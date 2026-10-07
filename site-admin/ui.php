<?php
/**
 * Spread AI — Website OS · مكوّنات الواجهة (Design System)
 * أي صفحة إدارة جديدة بتتبني من الدوال دي فبتاخد نفس الشكل تلقائيًا:
 *   sa_icon · sa_page_head · sa_btn · sa_chip · sa_status_chip · sa_switch · sa_empty · sa_card_open/close
 *   sa_field (input/textarea/select/checkbox/image) · sa_search_bar · sa_tabs · sa_pager
 * الـ CSS: site-assets/css/site-admin.css · الـ JS: site-assets/js/site-admin.js
 */

function sa_icon_paths(): array
{
    return [
        'home'     => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/>',
        'layout'   => '<rect x="3" y="3" width="18" height="18" rx="2.5"/><path d="M3 9h18"/><path d="M9 21V9"/>',
        'sparkle'  => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>',
        'puzzle'   => '<path d="M14 4h-4v3a2 2 0 1 1-4 0V4H4v6h3a2 2 0 1 1 0 4H4v6h6v-3a2 2 0 1 1 4 0v3h6v-6h-3a2 2 0 1 1 0-4h3V4z"/>',
        'file'     => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/>',
        'compass'  => '<circle cx="12" cy="12" r="9"/><path d="M15.5 8.5l-2 5-5 2 2-5z"/>',
        'palette'  => '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.6-.8 1.6-1.6 0-.5-.2-.8-.4-1.1-.2-.3-.4-.6-.4-1 0-.9.7-1.6 1.6-1.6H16a5 5 0 0 0 5-5C21 6.5 17 3 12 3z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10" cy="7" r="1"/><circle cx="15" cy="7.5" r="1"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'star'     => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
        'briefcase'=> '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/><path d="M3 12h18"/>',
        'wand'     => '<path d="M15 4V2"/><path d="M15 10V8"/><path d="M12 5h2"/><path d="M16 5h2"/><path d="M3 21l12-12"/>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
        'video'    => '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="M16 10l5-3v10l-5-3"/>',
        'tag'      => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.3"/>',
        'quote'    => '<path d="M7 7h4v4c0 3-2 5-4 6"/><path d="M15 7h4v4c0 3-2 5-4 6"/>',
        'help'     => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6"/><path d="M12 17h.01"/>',
        'rocket'   => '<path d="M5 15c-1.5 1.5-2 5-2 5s3.5-.5 5-2"/><path d="M9 11a17 17 0 0 1 11-8 17 17 0 0 1-8 11l-3 1-1-1z"/><path d="M9 11l-4-1 3-3h4"/><path d="M13 15l1 4 3-3v-4"/>',
        'gift'     => '<rect x="3" y="8" width="18" height="13" rx="2"/><path d="M12 8v13"/><path d="M3 12h18"/><path d="M12 8c-2-4-6-4-6-1s6 1 6 1 6 2 6-1-4-3-6 1z"/>',
        'card'     => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="M3 10h18"/><path d="M7 15h4"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14a6.5 6.5 0 0 1 3.5 6"/>',
        'chart'    => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M3 20h18"/>',
        'folder'   => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'shield'   => '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/>',
        'list'     => '<path d="M9 6h12"/><path d="M9 12h12"/><path d="M9 18h12"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/>',
        'chev-r'   => '<path d="M9 6l6 6-6 6"/>',
        'chev-l'   => '<path d="M15 6l-6 6 6 6"/>',
        'chev-d'   => '<path d="M6 9l6 6 6-6"/>',
        'menu'     => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>',
        'x'        => '<path d="M6 6l12 12"/><path d="M18 6L6 18"/>',
        'plus'     => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'edit'     => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
        'copy'     => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
        'trash'    => '<path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/>',
        'up'       => '<path d="M12 19V5"/><path d="M6 11l6-6 6 6"/>',
        'down'     => '<path d="M12 5v14"/><path d="M6 13l6 6 6-6"/>',
        'eye'      => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'  => '<path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.1"/><path d="M6.6 6.6A17 17 0 0 0 2 12s3.5 7 10 7a10 10 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        'grip'     => '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
        'send'     => '<path d="M21 3L10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
        'bell'     => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/>',
        'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'upload'   => '<path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'link'     => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18"/><path d="M12 3a14 14 0 0 0 0 18"/>',
        'code'     => '<path d="M8 7l-5 5 5 5"/><path d="M16 7l5 5-5 5"/><path d="M14 4l-4 16"/>',
        'type'     => '<path d="M4 7V5h16v2"/><path d="M12 5v14"/><path d="M9 19h6"/>',
        'columns'  => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M12 4v16"/>',
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'play'     => '<circle cx="12" cy="12" r="9"/><path d="M10 8.5l6 3.5-6 3.5z"/>',
        'lock'     => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'mega'     => '<path d="M3 11v2a2 2 0 0 0 2 2h2l6 4V5L7 9H5a2 2 0 0 0-2 2z"/><path d="M17 8a5 5 0 0 1 0 8"/>',
        'alert'    => '<path d="M12 3l10 18H2z"/><path d="M12 10v4"/><path d="M12 18h.01"/>',
        'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><path d="M12 7.5h.01"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'pen'      => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
        'filter'   => '<path d="M3 5h18l-7 8v6l-4-2v-4z"/>',
        'drag'     => '<path d="M12 3v18"/><path d="M8 7l4-4 4 4"/><path d="M8 17l4 4 4-4"/>',
        'refresh'  => '<path d="M20 12a8 8 0 1 1-2.3-5.7"/><path d="M20 4v5h-5"/>',
        'smile'    => '<circle cx="12" cy="12" r="9"/><path d="M8.5 14a4 4 0 0 0 7 0"/><path d="M9 9.5h.01"/><path d="M15 9.5h.01"/>',
    ];
}

function sa_icon(string $name, int $size = 18, float $stroke = 1.8, string $cls = ''): string
{
    $p = sa_icon_paths()[$name] ?? sa_icon_paths()['sparkle'];
    return '<svg class="ad-ico ' . e($cls) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="'
        . $stroke . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** رأس الصفحة: أيقونة داكنة + العنوان + عنوان إنجليزي صغير + وصف + أزرار */
function sa_page_head(string $icon, string $title, string $en = '', string $lead = '', string $actions = ''): string
{
    return '<div class="ad-ph sa-in"><div class="ad-ph-t"><span class="ad-ph-ic">' . sa_icon($icon, 22, 1.9) . '</span><div><h1 class="ad-h1">'
        . e($title) . '</h1>' . ($en !== '' ? '<span class="ad-en" dir="ltr">' . e($en) . '</span>' : '') . '</div></div>'
        . ($actions !== '' ? '<div class="ad-ph-a">' . $actions . '</div>' : '') . '</div>'
        . ($lead !== '' ? '<p class="ad-lead">' . e($lead) . '</p>' : '');
}

/** زرار — $kind: pri | sec | soft | ghost | danger · $href = لينك، وإلا button */
function sa_btn(string $label, string $kind = 'pri', ?string $href = null, string $icon = '', array $attrs = []): string
{
    $a = '';
    foreach ($attrs as $k => $v) $a .= ' ' . e($k) . '="' . e($v) . '"';
    $inner = ($icon !== '' ? sa_icon($icon, 17, 2) : '') . '<span>' . e($label) . '</span>';
    $cls = 'ad-btn ad-' . $kind;
    if ($href !== null) return '<a class="' . $cls . '" href="' . e($href) . '"' . $a . '>' . $inner . '</a>';
    return '<button type="' . e($attrs['type'] ?? 'button') . '" class="' . $cls . '"' . $a . '>' . $inner . '</button>';
}

/** شارة — $tone: ok | warn | off | info | violet | danger */
function sa_chip(string $label, string $tone = 'off'): string
{
    return '<span class="ad-chip t-' . e($tone) . '">' . e($label) . '</span>';
}

function sa_status_chip(string $status): string
{
    $map = ['published' => ['منشورة', 'ok'], 'draft' => ['مسودة', 'warn'], 'hidden' => ['مخفية', 'off'],
            'active' => ['مفعّل', 'ok'], 'inactive' => ['متوقف', 'off']];
    [$l, $t] = $map[$status] ?? [$status, 'off'];
    return sa_chip($l, $t);
}

/** مفتاح تشغيل (للفورم: name + checked) */
function sa_switch(string $name, bool $on, string $label = '', array $attrs = []): string
{
    $a = '';
    foreach ($attrs as $k => $v) $a .= ' ' . e($k) . '="' . e($v) . '"';
    return '<label class="ad-swl"><input type="checkbox" class="ad-swi" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . $a
        . '><span class="ad-sw" aria-hidden="true"><span></span></span>' . ($label !== '' ? '<span class="ad-swt">' . e($label) . '</span>' : '') . '</label>';
}

function sa_empty(string $icon, string $title, string $body = '', string $actions = ''): string
{
    return '<div class="ad-empty sa-in"><span class="ad-empty-ic">' . sa_icon($icon, 28, 1.6) . '</span><b>' . e($title) . '</b>'
        . ($body !== '' ? '<p>' . e($body) . '</p>' : '') . ($actions !== '' ? '<div class="ad-empty-a">' . $actions . '</div>' : '') . '</div>';
}

/** شريط بحث + عدّاد + أزرار (البحث بيفلتر العناصر الظاهرة لحظيًا، ولو فيه name بيتبعت للسيرفر) */
function sa_search_bar(string $placeholder, int $count, string $unit = 'عنصر', string $actions = '', string $filters = '', string $q = ''): string
{
    return '<div class="ad-bar"><form class="ad-search" method="GET" role="search" onsubmit="return true"><span>' . sa_icon('search', 17) . '</span>'
        . '<input type="search" name="q" value="' . e($q) . '" placeholder="' . e($placeholder) . '" data-filter-list autocomplete="off" aria-label="' . e($placeholder) . '">'
        . '</form>' . $filters . '<span class="ad-count"><b data-count>' . $count . '</b> ' . e($unit) . '</span><span class="ad-sp"></span>' . $actions . '</div>';
}

/** تبويبات (لينكات) — $items: [key => label] */
function sa_tabs(array $items, string $current, string $param = 'tab', array $counts = []): string
{
    $h = '<nav class="ad-tabs ad-noscroll" aria-label="تبويبات">';
    foreach ($items as $k => $l) {
        $h .= '<a class="ad-tab' . ($k === $current ? ' on' : '') . '" href="?' . e($param) . '=' . e($k) . '"' . ($k === $current ? ' aria-current="page"' : '') . '>'
            . e($l) . (isset($counts[$k]) ? ' <small>' . (int) $counts[$k] . '</small>' : '') . '</a>';
    }
    return $h . '</nav>';
}

/** ترقيم صفحات */
function sa_pager(int $page, int $pages, array $keep = []): string
{
    if ($pages <= 1) return '';
    $q = function (int $p) use ($keep) { return '?' . http_build_query(array_merge($keep, ['page' => $p])); };
    $h = '<nav class="ad-pager" aria-label="الصفحات">';
    $h .= $page > 1 ? '<a class="ad-ib" href="' . e($q($page - 1)) . '" aria-label="السابق">' . sa_icon('chev-r') . '</a>' : '<span class="ad-ib" aria-hidden="true"></span>';
    $h .= '<span>صفحة <b>' . $page . '</b> من ' . $pages . '</span>';
    $h .= $page < $pages ? '<a class="ad-ib" href="' . e($q($page + 1)) . '" aria-label="التالي">' . sa_icon('chev-l') . '</a>' : '';
    return $h . '</nav>';
}

/**
 * حقل فورم موحّد
 *   $f = ['name','label','type'=>text|textarea|number|date|url|email|select|checkbox|image|icon|color|password,'req','options','hint','ph','rows','wide','dir']
 *   image: عمودين {name}_path (رفع) و {name}_url (لينك أو من مكتبة الوسائط)
 */
function sa_field(array $f, $val = '', ?array $row = null): string
{
    $n = (string) $f['name'];
    $type = $f['type'] ?? 'text';
    $id = 'f-' . preg_replace('/[^a-z0-9_-]/i', '-', $n);
    $req = !empty($f['req']);
    $wide = in_array($type, ['textarea', 'image'], true) || !empty($f['wide']);
    $lbl = '<label class="ad-label" for="' . e($id) . '">' . e($f['label']) . ($req ? ' <i class="ad-req">*</i>' : '') . '</label>';
    $hint = !empty($f['hint']) ? '<span class="ad-hint">' . e($f['hint']) . '</span>' : '';
    $ph = e($f['ph'] ?? '');
    $dir = !empty($f['dir']) ? ' dir="' . e($f['dir']) . '"' : (in_array($type, ['url', 'email'], true) ? ' dir="ltr"' : '');
    $h = '<div class="ad-f' . ($wide ? ' wide' : '') . '">';

    switch ($type) {
        case 'textarea':
            $h .= $lbl . '<textarea class="ad-in" id="' . e($id) . '" name="' . e($n) . '" rows="' . (int) ($f['rows'] ?? 3) . '" placeholder="' . $ph . '"'
                . ($req ? ' required' : '') . $dir . (!empty($f['code']) ? ' data-code="1"' : '') . '>' . e($val) . '</textarea>';
            break;
        case 'select':
            $h .= $lbl . '<select class="ad-in" id="' . e($id) . '" name="' . e($n) . '">';
            foreach (($f['options'] ?? []) as $ov => $ol) {
                $h .= '<option value="' . e($ov) . '"' . ((string) $val === (string) $ov ? ' selected' : '') . '>' . e($ol) . '</option>';
            }
            $h .= '</select>';
            break;
        case 'checkbox':
            $h .= '<div class="ad-swrow"><span class="ad-label">' . e($f['label']) . '</span>' . sa_switch($n, (bool) $val, (string) ($f['cb_label'] ?? 'مفعّل')) . '</div>';
            break;
        case 'image':
            $curPath = (string) ($row[$n . '_path'] ?? '');
            $curUrl = (string) ($row[$n . '_url'] ?? '');
            $preview = $curUrl !== '' ? $curUrl : ($curPath !== '' ? s_img(['p' => $curPath], 'p', 'x') : '');
            $h .= $lbl . '<div class="ad-drop' . ($preview ? ' has' : '') . '" data-drop>'
                . '<span class="ad-drop-pv">' . ($preview ? '<img src="' . e($preview) . '" alt="">' : sa_icon('image', 22)) . '</span>'
                . '<span class="ad-drop-t"><b data-drop-name>' . ($preview ? 'الصورة الحالية' : 'لسه مفيش ملف') . '</b><small>اضغط للرفع أو اختار من مكتبة الوسائط</small></span>'
                . '<input type="file" name="' . e($n) . '" accept="image/*" id="' . e($id) . '" aria-label="' . e($f['label']) . '">'
                . '</div>'
                . '<div class="ad-drop-x"><input class="ad-in sm" type="text" dir="ltr" name="' . e($n) . '_url" value="' . e($curUrl) . '" placeholder="أو لينك صورة https://…" data-media-target>'
                . '<button type="button" class="ad-btn ad-soft sm" data-media-pick>' . sa_icon('folder', 16) . '<span>المكتبة</span></button></div>'
                . ($curPath !== '' ? '<label class="ad-mini"><input type="checkbox" name="__clear_' . e($n) . '" value="1"> امسح الصورة المرفوعة</label>' : '');
            break;
        case 'icon':
            $h .= $lbl . '<input class="ad-in" type="text" id="' . e($id) . '" name="' . e($n) . '" value="' . e($val) . '" placeholder="pen · brain · image · 🚀" data-icon-input>'
                . '<div class="ad-iconset" role="group" aria-label="اختار أيقونة">';
            foreach (['pen', 'clock', 'calendar', 'shuffle', 'wallet', 'brain', 'search', 'bulb', 'image', 'megaphone', 'send', 'layers', 'sparkle', 'gift'] as $ic) {
                $h .= '<button type="button" data-icon="' . $ic . '" title="' . $ic . '"' . ((string) $val === $ic ? ' aria-pressed="true"' : '') . '>'
                    . (function_exists('s_icon') ? s_icon($ic, 18) : e($ic)) . '</button>';
            }
            $h .= '</div>';
            break;
        case 'color':
            $v = (string) ($val ?: ($f['default'] ?? '#0A6FD8'));
            $h .= $lbl . '<div class="ad-color"><input type="color" value="' . e($v) . '" aria-label="' . e($f['label']) . '" data-color-sync>'
                . '<input class="ad-in" type="text" id="' . e($id) . '" name="' . e($n) . '" value="' . e($val) . '" dir="ltr" placeholder="' . e($v) . '"></div>';
            break;
        default:
            $it = in_array($type, ['number', 'date', 'email', 'password'], true) ? $type : 'text';
            $h .= $lbl . '<input class="ad-in" type="' . $it . '" id="' . e($id) . '" name="' . e($n) . '" value="' . e($val) . '" placeholder="' . $ph . '"'
                . ($req ? ' required' : '') . $dir . (isset($f['max']) && $it === 'text' ? ' maxlength="' . (int) $f['max'] . '"' : '') . '>';
    }
    return $h . $hint . '</div>';
}

/** درج جانبي (ديسكتوب) / شيت من تحت (موبايل) — $open: يظهر مفتوح من السيرفر */
function sa_drawer_open(string $id, string $title, bool $open = false, string $formAttrs = ''): string
{
    return '<div class="ad-ov' . ($open ? ' open' : '') . '" data-drawer="' . e($id) . '" ' . ($open ? '' : 'hidden') . '>'
        . '<div class="ad-ov-bg" data-close></div>'
        . '<aside class="ad-drawer" role="dialog" aria-modal="true" aria-labelledby="' . e($id) . '-t">'
        . '<span class="ad-handle" aria-hidden="true"></span>'
        . '<header class="ad-dh"><h2 id="' . e($id) . '-t">' . e($title) . '</h2><button type="button" class="ad-ib" data-close aria-label="إغلاق">' . sa_icon('x', 20) . '</button></header>'
        . '<form class="ad-dform" method="POST" data-safe-post ' . $formAttrs . '><div class="ad-db">';
}

function sa_drawer_close(string $saveLabel = 'حفظ', string $extra = ''): string
{
    return '</div><footer class="ad-df">' . sa_btn($saveLabel, 'pri wide', null, 'check', ['type' => 'submit'])
        . $extra . '<button type="button" class="ad-btn ad-sec" data-close>إلغاء</button></footer></form></aside></div>';
}

/** وقت نسبي بالعربي */
function sa_ago(?string $dt): string
{
    if (!$dt) return '—';
    $d = time() - strtotime($dt);
    if ($d < 60) return 'دلوقتي';
    if ($d < 3600) return 'من ' . (int) floor($d / 60) . ' د';
    if ($d < 86400) return 'من ' . (int) floor($d / 3600) . ' س';
    if ($d < 86400 * 2) return 'امبارح';
    if ($d < 86400 * 30) return 'من ' . (int) floor($d / 86400) . ' يوم';
    return date('Y/m/d', strtotime($dt));
}

/** خط بياني صغير (SVG) من أرقام */
function sa_spark(array $vals, string $color = '#0C87EF', int $w = 240, int $h = 34): string
{
    $n = count($vals);
    if ($n < 2) return '';
    $max = max($vals) ?: 1;
    $min = min($vals);
    $rng = max(1, $max - $min);
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($i / ($n - 1) * $w, 1) . ',' . round($h - 3 - (($v - $min) / $rng) * ($h - 6), 1);
    }
    return '<svg class="ad-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true"><polyline fill="none" stroke="' . e($color)
        . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" points="' . implode(' ', $pts) . '"/></svg>';
}
