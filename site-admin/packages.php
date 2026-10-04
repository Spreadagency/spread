<?php
require_once __DIR__ . '/crud.php';
crud_page([
    'table' => 'site_packages',
    'title' => 'الباقات والأسعار',
    'intro' => 'ملحوظة: قسم «الأسعار» في الرئيسية الجديدة بيعرض باقات المنصة نفسها (لوحة المنصة ← الباقات) و«اشترك» بيودّي صفحة الدفع. الباقات هنا احتياطي بس لو قاعدة المنصة مش متاحة، ولصفحة الأسعار الداخلية. اكتب كل ميزة في سطر لوحدها.',
    'add_label' => 'باقة جديدة',
    'list_label' => 'الباقات',
    'fields' => [
        ['name' => 'name',        'label' => 'اسم الباقة', 'req' => true, 'max' => 150],
        ['name' => 'price',       'label' => 'السعر', 'max' => 80, 'ph' => '١٢٠٠'],
        ['name' => 'period',      'label' => 'المدة', 'max' => 80, 'ph' => 'جنيه / شهر'],
        ['name' => 'description', 'label' => 'وصف قصير', 'max' => 500],
        ['name' => 'features',    'label' => 'المميزات (ميزة في كل سطر)', 'type' => 'textarea', 'rows' => 6, 'max' => 2000],
        ['name' => 'badge',       'label' => 'شارة', 'max' => 80, 'ph' => 'الأكثر طلبًا'],
        ['name' => 'cta_text',    'label' => 'نص الزرار', 'max' => 100, 'ph' => 'ابدأ دلوقتي'],
        ['name' => 'cta_url',     'label' => 'رابط الزرار', 'type' => 'url', 'max' => 500, 'hint' => 'سيبه فاضي = صفحة التسجيل'],
        ['name' => 'is_featured', 'label' => 'باقة مميزة', 'type' => 'checkbox', 'cb_label' => 'تظهر بإطار بارز'],
        ['name' => 'sort_order',  'label' => 'الترتيب', 'type' => 'number'],
        ['name' => 'is_active',   'label' => 'الظهور', 'type' => 'checkbox', 'cb_label' => 'ظاهرة', 'default' => 1],
    ],
    'list_cols' => [['name', 'الباقة', 25], ['price', 'السعر', 12], ['period', 'المدة', 16], ['badge', 'شارة', 16]],
]);
