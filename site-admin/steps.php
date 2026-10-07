<?php
require_once __DIR__ . '/crud.php';
crud_page([
    'perm' => 'content',
    'icon' => 'list',
    'en' => 'How it works',
    'item' => 'خطوة',
    'unit' => 'خطوة',
    'table' => 'site_steps',
    'title' => 'خطوات العمل',
    'intro' => 'الخطوات بتترقّم تلقائيًا حسب الترتيب: صناعة الهوية ← خطة ← أفكار ← محتوى ← تصميم ← نشر.',
    'add_label' => 'خطوة جديدة',
    'list_label' => 'الخطوات',
    'fields' => [
        ['name' => 'title', 'label' => 'عنوان الخطوة', 'req' => true, 'max' => 200],
        ['name' => 'body',  'label' => 'الشرح', 'type' => 'textarea', 'rows' => 3],
        ['name' => 'icon',  'label' => 'الأيقونة', 'type' => 'icon'],
        ['name' => 'sort_order','label' => 'الترتيب', 'type' => 'number'],
        ['name' => 'is_active', 'label' => 'الظهور', 'type' => 'checkbox', 'cb_label' => 'ظاهرة', 'default' => 1],
    ],
    'list_cols' => [['icon', '', 4], ['title', 'الخطوة', 40], ['body', 'الشرح', 45]],
]);
