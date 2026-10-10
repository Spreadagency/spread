<?php
require_once __DIR__ . '/crud.php';
crud_page([
    'perm' => 'videos',
    'icon' => 'video',
    'en' => 'Videos',
    'item' => 'فيديو',
    'unit' => 'فيديو',
    'table' => 'site_videos',
    'title' => 'فيديوهات الشرح',
    'intro' => 'بتظهر في صفحة «شرح المنصة». حط لينك يوتيوب أو Vimeo عادي والنظام بيحوله تلقائيًا.',
    'add_label' => 'فيديو جديد',
    'list_label' => 'الفيديوهات',
    'fields' => [
        ['name' => 'title',       'label' => 'عنوان الفيديو', 'req' => true, 'max' => 250],
        ['name' => 'video_url',   'label' => 'رابط الفيديو', 'type' => 'url', 'req' => true, 'max' => 700,
         'ph' => 'https://youtube.com/watch?v=...', 'wide' => true],
        ['name' => 'description', 'label' => 'الوصف', 'type' => 'textarea', 'rows' => 2],
        ['name' => 'category',    'label' => 'التصنيف', 'max' => 80, 'ph' => 'البداية / التصميم / النشر'],
        ['name' => 'thumb',       'label' => 'صورة مصغّرة (اختياري)', 'type' => 'image'],
        ['name' => 'sort_order',  'label' => 'الترتيب', 'type' => 'number'],
        ['name' => 'is_active',   'label' => 'الظهور', 'type' => 'checkbox', 'cb_label' => 'ظاهر', 'default' => 1],
    ],
    'list_cols' => [['title', 'العنوان', 35], ['category', 'التصنيف', 18], ['video_url', 'الرابط', 30]],
]);
