<?php
require_once __DIR__ . '/crud.php';
crud_page([
    'table' => 'site_services',
    'title' => 'الخدمات',
    'intro' => 'بتظهر في الرئيسية وفي صفحة «الخدمات» الداخلية.',
    'add_label' => 'خدمة جديدة',
    'list_label' => 'الخدمات',
    'fields' => [
        ['name' => 'title', 'label' => 'اسم الخدمة', 'req' => true, 'max' => 200],
        ['name' => 'body',  'label' => 'الوصف', 'type' => 'textarea', 'rows' => 3],
        ['name' => 'image', 'label' => 'صورة (اختياري)', 'type' => 'image'],
        ['name' => 'icon',  'label' => 'الأيقونة', 'type' => 'icon'],
        ['name' => 'sort_order','label' => 'الترتيب', 'type' => 'number'],
        ['name' => 'is_active', 'label' => 'الظهور', 'type' => 'checkbox', 'cb_label' => 'ظاهرة', 'default' => 1],
    ],
    'list_cols' => [['icon', '', 4], ['title', 'الخدمة', 40], ['body', 'الوصف', 45]],
]);
