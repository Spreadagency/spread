<?php
require_once __DIR__ . '/crud.php';
crud_page([
    'table' => 'site_slides',
    'title' => 'سلايدر الخدمات',
    'intro' => 'الصور دي بتظهر في أول الصفحة الرئيسية وبتتبدل تلقائيًا كل ٤ ثواني — كل صورة معاها عنوان ووصف.',
    'add_label' => 'شريحة جديدة',
    'list_label' => 'الشرائح',
    'fields' => [
        ['name' => 'title',    'label' => 'العنوان',  'req' => true, 'max' => 200, 'ph' => 'تصميمات بالذكاء الاصطناعي'],
        ['name' => 'subtitle', 'label' => 'وصف قصير', 'max' => 400, 'ph' => 'بألوان هويتك ولوجوك'],
        ['name' => 'image',    'label' => 'الصورة',   'type' => 'image'],
        ['name' => 'cta_text', 'label' => 'نص الزرار (اختياري)', 'max' => 100],
        ['name' => 'cta_url',  'label' => 'رابط الزرار', 'type' => 'url', 'max' => 500],
        ['name' => 'sort_order','label' => 'الترتيب', 'type' => 'number'],
        ['name' => 'is_active','label' => 'الظهور',   'type' => 'checkbox', 'cb_label' => 'ظاهرة', 'default' => 1],
    ],
    'list_cols' => [['title', 'العنوان', 40], ['subtitle', 'الوصف', 45]],
]);
