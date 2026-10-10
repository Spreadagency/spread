<?php
require_once __DIR__ . '/crud.php';
crud_page([
    'perm' => 'homepage',
    'icon' => 'sparkle',
    'en' => 'Hero & Slider',
    'item' => 'شريحة',
    'unit' => 'شريحة',
    'table' => 'site_slides',
    'title' => 'شرائح الهيرو',
    'intro' => 'أول الصفحة الرئيسية: كل شريحة = شارة صغيرة + عنوان كبير + وصف + وضع الروبوت، وبتتبدل تلقائيًا كل ٦ ثواني ونص.',
    'add_label' => 'شريحة جديدة',
    'list_label' => 'الشرائح',
    'fields' => [
        ['name' => 'title',    'label' => 'العنوان',  'req' => true, 'max' => 200, 'ph' => 'تصميمات بالذكاء الاصطناعي'],
        ['name' => 'tag',      'label' => 'الشارة فوق العنوان', 'max' => 150, 'ph' => 'فريق تسويق كامل بالذكاء الاصطناعي'],
        ['name' => 'subtitle', 'label' => 'الوصف', 'max' => 400, 'ph' => 'من فهم البراند لحد النشر…'],
        ['name' => 'bot',      'label' => 'وضع الروبوت', 'type' => 'select', 'options' => ['wave' => '👋 بيسلّم', 'think' => '🤔 بيفكّر', 'sit' => '😌 قاعد مرتاح']],
        ['name' => 'cta_text', 'label' => 'نص الزرار الأساسي (فاضي = «اصنع منشورك الآن»)', 'max' => 100],
        ['name' => 'cta_url',  'label' => 'رابط الزرار الأساسي (فاضي = قسم التجربة)', 'type' => 'url', 'max' => 500],
        ['name' => 'cta2_text','label' => 'نص الزرار التاني (فاضي = «اكتشف المنصة»)', 'max' => 100],
        ['name' => 'sort_order','label' => 'الترتيب', 'type' => 'number'],
        ['name' => 'is_active','label' => 'الظهور',   'type' => 'checkbox', 'cb_label' => 'ظاهرة', 'default' => 1],
    ],
    'list_cols' => [['title', 'العنوان', 40], ['tag', 'الشارة', 25], ['bot', 'الروبوت', 10]],
]);
