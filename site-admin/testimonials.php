<?php
/** آراء العملاء — بتظهر في الرئيسية (قسم «آراء العملاء») وفي أي صفحة فيها بلوك آراء */
require_once __DIR__ . '/crud.php';

function sa_stars(int $n): string
{
    if ($n <= 0) return '';
    return '<span class="ad-stars" aria-label="' . $n . ' من 5">' . str_repeat('★', $n) . '<i>' . str_repeat('★', 5 - $n) . '</i></span>';
}

crud_page([
    'perm' => 'testimonials',
    'icon' => 'quote',
    'en' => 'Testimonials',
    'item' => 'رأي',
    'unit' => 'رأي',
    'table' => 'site_testimonials',
    'title' => 'آراء العملاء',
    'intro' => 'آراء حقيقية من عملائك — بتظهر في قسم «آراء العملاء» في الرئيسية. الآراء المخفية مش بتظهر للزوار، و«المميز» بيظهر الأول.',
    'add_label' => 'إضافة رأي',
    'list_label' => 'آراء',
    'search_ph' => 'ابحث في آراء العملاء...',
    'empty_hint' => 'ضيف أول رأي عميل — الاسم والنص كفاية، والصورة والتقييم اختياري.',
    'title_col' => 'name',
    'view' => 'cards',
    'filters' => ['rating' => ['التقييم', ['5' => '5 نجوم', '4' => '4 نجوم', '3' => '3 نجوم', '0' => 'بدون']], 'is_featured' => ['مميز', ['1' => 'مميز', '0' => 'عادي']]],
    'card' => function (array $r): string {
        $av = s_img($r, 'avatar_path', 'avatar_url');
        $who = trim(implode(' · ', array_filter([(string) ($r['role_title'] ?? ''), (string) ($r['company'] ?? '')])));
        return '<div class="ad-ic-h">' . ($av ? '<img class="ad-av" src="' . e($av) . '" alt="" loading="lazy">' : '<span class="ad-av">' . e(mb_substr((string) $r['name'], 0, 1)) . '</span>')
            . '<div style="min-width:0;flex:1"><b>' . e($r['name']) . '</b>' . ($who !== '' ? '<small class="ad-hint" style="display:block">' . e($who) . '</small>' : '') . '</div>'
            . ($r['is_featured'] ? sa_chip('مميز', 'violet') : '') . '</div>'
            . sa_stars((int) $r['rating'])
            . '<p class="sa-clamp3">' . e(mb_substr((string) $r['content'], 0, 400)) . '</p>'
            . '<div style="display:flex;gap:6px;flex-wrap:wrap">' . sa_chip($r['is_active'] ? 'منشور' : 'مخفي', $r['is_active'] ? 'ok' : 'off')
            . (!empty($r['video_url']) ? sa_chip('فيديو', 'info') : '') . '</div>';
    },
    'fields' => [
        ['name' => 'name',       'label' => 'اسم العميل', 'req' => true, 'max' => 150],
        ['name' => 'company',    'label' => 'الشركة / النشاط', 'max' => 150],
        ['name' => 'role_title', 'label' => 'المنصب', 'max' => 150, 'ph' => 'صاحب المشروع'],
        ['name' => 'avatar',     'label' => 'الصورة', 'type' => 'image'],
        ['name' => 'content',    'label' => 'الرأي', 'type' => 'textarea', 'req' => true, 'rows' => 4, 'max' => 2000, 'not_null' => true],
        ['name' => 'rating',     'label' => 'التقييم (اختياري)', 'type' => 'select', 'options' => ['0' => 'بدون', '5' => '★★★★★ 5', '4' => '★★★★ 4', '3' => '★★★ 3', '2' => '★★ 2', '1' => '★ 1']],
        ['name' => 'video_url',  'label' => 'فيديو (اختياري)', 'type' => 'url', 'max' => 700, 'ph' => 'https://youtube.com/watch?v=…'],
        ['name' => 'is_featured','label' => 'مميز', 'type' => 'checkbox', 'cb_label' => 'يظهر الأول'],
        ['name' => 'sort_order', 'label' => 'ترتيب الظهور', 'type' => 'number'],
        ['name' => 'is_active',  'label' => 'الحالة', 'type' => 'checkbox', 'cb_label' => 'منشور في الموقع', 'default' => 1],
    ],
    'list_cols' => [['name', 'الاسم', 40], ['company', 'الشركة', 30]],
]);
