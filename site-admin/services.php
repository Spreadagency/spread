<?php
require_once __DIR__ . '/crud.php';
crud_page([
    'table' => 'site_services',
    'title' => 'الخدمات',
    'intro' => 'بتظهر في الرئيسية وفي صفحة «الخدمات» الداخلية.',
    'add_label' => 'خدمة جديدة',
    'list_label' => 'الخدمات',
    'fields' => [
        ['name' => 'title', 'label' => 'اسم الخدمة', 'req' => true, 'max' => 200, 'ph' => 'Brand Brain'],
        ['name' => 'subtitle', 'label' => 'عنوان فرعي (جنب الاسم)', 'max' => 150, 'ph' => 'هوية البراند'],
        ['name' => 'body',  'label' => 'الوصف', 'type' => 'textarea', 'rows' => 3],
        ['name' => 'bullets', 'label' => 'النقاط (سطر لكل نقطة)', 'type' => 'textarea', 'rows' => 3],
        ['name' => 'image', 'label' => 'صورة الشرح (اختياري — وإلا الأيقونة)', 'type' => 'image'],
        ['name' => 'link_text', 'label' => 'نص الزرار (فاضي = «جرّب + الاسم»)', 'max' => 80],
        ['name' => 'link_url', 'label' => 'رابط الزرار (صفحة في المنصة مثلًا brand-brain.php)', 'type' => 'url', 'max' => 500],
        ['name' => 'icon',  'label' => 'الأيقونة', 'type' => 'icon'],
        ['name' => 'sort_order','label' => 'الترتيب', 'type' => 'number'],
        ['name' => 'is_active', 'label' => 'الظهور', 'type' => 'checkbox', 'cb_label' => 'ظاهرة', 'default' => 1],
    ],
    'list_cols' => [['icon', '', 4], ['title', 'الخدمة', 40], ['body', 'الوصف', 45]],
]);
