<?php
require_once __DIR__ . '/crud.php';
crud_page([
    'table' => 'site_solutions',
    'title' => 'عن المنصة والحلول',
    'intro' => 'معلومات المنصة وإزاي بتحل مشاكل العملاء — تقدر تحط صورة لكل حل أو تكتفي بالأيقونة.',
    'add_label' => 'حل جديد',
    'list_label' => 'الحلول',
    'fields' => [
        ['name' => 'title', 'label' => 'العنوان', 'req' => true, 'max' => 250],
        ['name' => 'body',  'label' => 'الشرح', 'type' => 'textarea', 'rows' => 3],
        ['name' => 'image', 'label' => 'صورة (اختياري)', 'type' => 'image'],
        ['name' => 'icon',  'label' => 'الأيقونة', 'type' => 'icon'],
        ['name' => 'sort_order','label' => 'الترتيب', 'type' => 'number'],
        ['name' => 'is_active', 'label' => 'الظهور', 'type' => 'checkbox', 'cb_label' => 'ظاهر', 'default' => 1],
    ],
    'list_cols' => [['icon', '', 4], ['title', 'العنوان', 40], ['body', 'الشرح', 45]],
]);
