<?php
require_once __DIR__ . '/crud.php';
crud_page([
    'table' => 'site_problems',
    'title' => 'المشاكل',
    'intro' => 'أكتر المشاكل اللي بتقابل العملاء — بتظهر في القسم الأسود. عنوان القسم نفسه بتغيّره من «الأقسام والعناوين».',
    'add_label' => 'مشكلة جديدة',
    'list_label' => 'المشاكل',
    'fields' => [
        ['name' => 'title', 'label' => 'عنوان المشكلة', 'req' => true, 'max' => 250, 'ph' => 'مفيش وقت للمحتوى'],
        ['name' => 'body',  'label' => 'الشرح', 'type' => 'textarea', 'rows' => 3],
        ['name' => 'icon',  'label' => 'الأيقونة', 'type' => 'icon'],
        ['name' => 'sort_order','label' => 'الترتيب', 'type' => 'number'],
        ['name' => 'is_active', 'label' => 'الظهور', 'type' => 'checkbox', 'cb_label' => 'ظاهرة', 'default' => 1],
    ],
    'list_cols' => [['icon', '', 4], ['title', 'العنوان', 40], ['body', 'الشرح', 45]],
]);
