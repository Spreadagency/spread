<?php
require_once __DIR__ . '/crud.php';
crud_page([
    'table' => 'site_brands',
    'title' => 'العلامات التجارية',
    'intro' => 'لوجوهات العملاء اللي بتشتغل معاهم — بتظهر في شريط «بيثقوا فينا». لو مفيش لوجو هيظهر الاسم كنص.',
    'add_label' => 'علامة جديدة',
    'list_label' => 'العلامات',
    'fields' => [
        ['name' => 'name',      'label' => 'اسم العلامة', 'req' => true, 'max' => 200],
        ['name' => 'logo',      'label' => 'اللوجو', 'type' => 'image'],
        ['name' => 'link_url',  'label' => 'رابط (اختياري)', 'type' => 'url', 'max' => 500],
        ['name' => 'sort_order','label' => 'الترتيب', 'type' => 'number'],
        ['name' => 'is_active', 'label' => 'الظهور', 'type' => 'checkbox', 'cb_label' => 'ظاهرة', 'default' => 1],
    ],
    'list_cols' => [['name', 'الاسم', 40], ['link_url', 'الرابط', 40]],
]);
