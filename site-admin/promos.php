<?php
require_once __DIR__ . '/crud.php';
crud_page([
    'table' => 'site_promos',
    'title' => 'الإعلانات والعروض',
    'intro' => 'بانرات العروض اللي بتظهر في الرئيسية وصفحة الأسعار. سيب التواريخ فاضية عشان يفضل ظاهر دايمًا.',
    'add_label' => 'عرض جديد',
    'list_label' => 'العروض',
    'fields' => [
        ['name' => 'title',     'label' => 'العنوان', 'req' => true, 'max' => 250],
        ['name' => 'body',      'label' => 'نص العرض', 'type' => 'textarea', 'rows' => 2, 'max' => 600],
        ['name' => 'image',     'label' => 'صورة الخلفية', 'type' => 'image'],
        ['name' => 'link_url',  'label' => 'رابط عند الضغط', 'type' => 'url', 'max' => 500],
        ['name' => 'starts_at', 'label' => 'يبدأ من', 'type' => 'date'],
        ['name' => 'ends_at',   'label' => 'ينتهي في', 'type' => 'date'],
        ['name' => 'sort_order','label' => 'الترتيب', 'type' => 'number'],
        ['name' => 'is_active', 'label' => 'الظهور', 'type' => 'checkbox', 'cb_label' => 'ظاهر', 'default' => 1],
    ],
    'list_cols' => [['title', 'العنوان', 35], ['starts_at', 'من', 12], ['ends_at', 'إلى', 12]],
]);
