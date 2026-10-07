<?php
/** الأسئلة الشائعة — قسم في الرئيسية + بلوك FAQ في الصفحات */
require_once __DIR__ . '/crud.php';
$__cats = array_values(array_filter(array_column(s_all('SELECT DISTINCT category FROM site_faqs WHERE category IS NOT NULL AND category <> "" ORDER BY category'), 'category')));
crud_page([
    'perm' => 'faq',
    'icon' => 'help',
    'en' => 'FAQ',
    'item' => 'سؤال',
    'unit' => 'سؤال',
    'table' => 'site_faqs',
    'title' => 'الأسئلة الشائعة',
    'intro' => 'الأسئلة اللي بتتكرر من العملاء — بتظهر في قسم «الأسئلة الشائعة» في الرئيسية، وتقدر تضيفها لأي صفحة كبلوك.',
    'add_label' => 'إضافة سؤال',
    'list_label' => 'أسئلة',
    'title_col' => 'question',
    'filters' => $__cats ? ['category' => ['التصنيف', array_combine($__cats, $__cats)]] : [],
    'fields' => [
        ['name' => 'question',   'label' => 'السؤال', 'req' => true, 'max' => 300],
        ['name' => 'answer',     'label' => 'الإجابة', 'type' => 'textarea', 'req' => true, 'rows' => 5, 'max' => 4000, 'not_null' => true],
        ['name' => 'category',   'label' => 'التصنيف (اختياري)', 'max' => 80, 'ph' => 'الدفع / الاستخدام / الباقات'],
        ['name' => 'sort_order', 'label' => 'الترتيب', 'type' => 'number'],
        ['name' => 'is_active',  'label' => 'الظهور', 'type' => 'checkbox', 'cb_label' => 'ظاهر', 'default' => 1],
    ],
    'list_cols' => [['question', 'السؤال', 70], ['category', 'التصنيف', 20]],
]);
