<?php
/**
 * Spread AI — الرئيسية الجديدة للموقع الخارجي (أيقونات · محتوى افتراضي · ترقية · باقات المنصة · هندسة «خطوات العمل»)
 * كل المحتوى بيتقرا من قاعدة الموقع (site-admin)، والباقات من قاعدة المنصة (نظام الدفع الجديد).
 */
require_once __DIR__ . '/functions.php';

/* ═══════════════ الأيقونات (خطوط — نفس التصميم) ═══════════════ */

function s_icon_paths(): array
{
    return [
        'pen'      => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13.5 6.5l4 4"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18"/><path d="M8 3v4"/><path d="M16 3v4"/>',
        'shuffle'  => '<path d="M16 3h5v5"/><path d="M4 20L21 3"/><path d="M21 16v5h-5"/><path d="M15 15l6 6"/><path d="M4 4l5 5"/>',
        'wallet'   => '<rect x="3" y="6" width="18" height="12" rx="3"/><circle cx="12" cy="12" r="2.5"/>',
        'brain'    => '<path d="M9 4a3 3 0 0 0-3 3v1a3 3 0 0 0-2 5 3 3 0 0 0 2 5 3 3 0 0 0 6 1V5a2 2 0 0 0-3-1z"/><path d="M15 4a3 3 0 0 1 3 3v1a3 3 0 0 1 2 5 3 3 0 0 1-2 5 3 3 0 0 1-6 1"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'bulb'     => '<path d="M9 18h6"/><path d="M10 21h4"/><path d="M12 3a6 6 0 0 0-4 10.5c.7.7 1 1.5 1 2.5h6c0-1 .3-1.8 1-2.5A6 6 0 0 0 12 3z"/>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-9 9"/>',
        'megaphone'=> '<path d="M3 11v2a2 2 0 0 0 2 2h2l6 4V5L7 9H5a2 2 0 0 0-2 2z"/><path d="M17 8a5 5 0 0 1 0 8"/>',
        'send'     => '<path d="M21 3L10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
        'layers'   => '<path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/>',
        'sparkle'  => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/>',
        'gift'     => '<rect x="3" y="8" width="18" height="13" rx="2"/><path d="M12 8v13"/><path d="M3 12h18"/><path d="M12 8c-2-4-6-4-6-1s6 1 6 1 6 2 6-1-4-3-6 1z"/>',
        'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'x'        => '<path d="M6 6l12 12"/><path d="M18 6L6 18"/>',
        'arrow'    => '<path d="M19 12H5"/><path d="M11 6l-6 6 6 6"/>',
        'arrow-r'  => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
        'mouse'    => '<path d="M12 3v6"/><rect x="7" y="3" width="10" height="16" rx="5"/>',
        'heart'    => '<path d="M12 20s-7-4.4-9-9a5 5 0 0 1 9-3 5 5 0 0 1 9 3c-2 4.6-9 9-9 9z"/>',
        'chat'     => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
        'menu'     => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>',
        'compare'  => '<path d="M9 6l-6 6 6 6"/><path d="M15 6l6 6-6 6"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 7l9 6 9-6"/>',
        'phone'    => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        'pin'      => '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>',
    ];
}

/**
 * أيقونة: اسم من الطقم (pen · brain · …) ← SVG بنفس ستايل التصميم،
 * أو إيموجي/رمز مكتوب في لوحة الموقع ← بيظهر زي ما هو.
 */
function s_icon(?string $key, int $size = 24, float $stroke = 1.8, string $fallback = 'sparkle'): string
{
    $key = trim((string) $key);
    $paths = s_icon_paths();
    if ($key !== '' && !isset($paths[$key])) {
        return '<span class="h-emo" aria-hidden="true">' . e($key) . '</span>';
    }
    $p = $paths[$key !== '' ? $key : $fallback] ?? $paths['sparkle'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="'
        . $stroke . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/* ═══════════════ المحتوى الافتراضي (نصوص التصميم) ═══════════════ */

function s_home_defaults(): array
{
    return [
        'slides' => [
            ['bot' => 'wave',  'tag' => 'فريق تسويق كامل بالذكاء الاصطناعي', 'title' => 'خلّي Spread AI يبقى فريق التسويق بتاعك',
             'subtitle' => 'من فهم البراند لحد النشر — محتوى وتصميم وجدولة بصوت مشروعك، في مكان واحد.'],
            ['bot' => 'think', 'tag' => 'أفكار ومحتوى في دقايق', 'title' => 'من فكرة واحدة… لمنشور جاهز ومتصمم',
             'subtitle' => 'اكتب بيانات مشروعك، واختار من 4 أفكار، وخد منشور حقيقي بتصميمه قبل ما تسجّل.'],
            ['bot' => 'sit',   'tag' => 'هوية ثابتة ونشر مستمر', 'title' => 'اقعد ارتاح… الاستمرارية علينا',
             'subtitle' => 'Brand Brain بيحفظ هويتك، والجدولة بتنشر في ميعادها — وأنت مركّز في شغلك.'],
        ],
        'marquee' => ['محتوى بصوت براندك', 'تصميمات جاهزة للنشر', 'هوية ثابتة', 'أفكار ما بتخلصش', 'جدولة ونشر', 'بحث عميق', 'حملات كاملة', 'Brand Brain'],
        'problems' => [
            ['pen', 'مش عارف أكتب محتوى', 'بتقعد قدام الصفحة الفاضية ومش عارف تبدأ منين.'],
            ['clock', 'التصميم بياخد وقت', 'كل بوست محتاج مصمم، ومراجعات، وتأخير.'],
            ['calendar', 'مش عارف أنشر باستمرار', 'أسبوع نشاط وأسبوعين سكوت — والجمهور بينسى.'],
            ['shuffle', 'الهوية مش ثابتة', 'كل بوست بصوت وألوان شكل — البراند مش واضح.'],
            ['wallet', 'تكلفة المحتوى عالية', 'كاتب ومصمم ومدير سوشيال… والميزانية محدودة.'],
        ],
        'solutions' => [
            ['pen', 'AI Content', 'محتوى بيتكتب بصوت براندك في ثواني.', 'بدل: مش عارف أكتب'],
            ['image', 'Design Studio', 'تصميمات جاهزة بمقاسات كل منصة.', 'بدل: التصميم بياخد وقت'],
            ['brain', 'Brand Brain', 'هوية واحدة بتتطبق على كل حاجة.', 'بدل: الهوية مش ثابتة'],
            ['bulb', 'Content Ideas', 'أفكار جديدة كل ما تحتاج.', 'بدل: الصفحة الفاضية'],
            ['calendar', 'Scheduling', 'تقويم ومواعيد نشر مقترحة.', 'بدل: النشر المتقطع'],
            ['send', 'Publishing', 'نشر مباشر على حساباتك من مكان واحد.', 'بدل: فريق كامل وتكلفة عالية'],
        ],
        'steps' => [
            ['pen', 'اكتب بياناتك'], ['brain', 'ابنِ هويتك'], ['bulb', 'ولّد الأفكار'], ['layers', 'اصنع المحتوى'],
            ['image', 'صمّم'], ['calendar', 'جدولة'], ['send', 'نشر'],
        ],
        'services' => [
            ['brain', 'Brand Brain', 'هوية البراند', 'محادثة بسيطة بتبني بيها هوية مشروعك: الصوت، الألوان، الجمهور والرسائل — وكل محتوى بعد كده بيتكتب بيها.',
             "تحليل ملفاتك وروابطك\nلوجو وألوان من الإلهام\nنسبة اكتمال واضحة للهوية", 'brand-brain.php'],
            ['search', 'Deep Research', 'البحث العميق', 'بحث في السوق والمنافسين والجمهور بخطة واضحة قبل ما يبدأ، والنتيجة رؤى تقدر تحفظها في هويتك.',
             "خطة بحث قبل التنفيذ\nمصادر لكل معلومة\nحفظ الرؤى في Brand Brain", 'research.php'],
            ['bulb', 'Content Ideas', 'الأفكار والمحتوى', 'أفكار محتوى مبنية على هدفك وجمهورك، وكابشن جاهز بصوت البراند مع الهاشتاجات.',
             "4 أفكار في كل مرة\nكابشن بصوت براندك\nإعادة توليد بلمسة", 'create-content.php'],
            ['image', 'Design Studio', 'استوديو التصميم', 'تصميمات لبوستات وستوريز وبورتريه ولوجوهات بمقاسات متعددة، بألوان هويتك.',
             "6 أنواع تصميم\nمقاسات لكل منصة\nترندات جاهزة", 'design-studio.php'],
            ['megaphone', 'Campaigns', 'الحملات', 'حملة كاملة في 6 مراحل: الهدف، الجمهور، الرسائل، المحتوى، التصميم والجدول.',
             "6 مراحل واضحة\nSpread AI بيفكر معاك\nتقدر ترجع لأي مرحلة", 'campaigns.php'],
            ['calendar', 'Scheduling', 'الجدولة والنشر', 'جدول نشر بمواعيد مقترحة، ونشر مباشر على حساباتك من غير ما تفتح كل منصة.',
             "تقويم محتوى\nمواعيد مقترحة\nنشر من مكان واحد", 'content-history.php'],
        ],
        // عنوان · وصف كل قسم (لو لوحة الموقع لسه على القيم القديمة)
        'sections' => [
            'trial'    => ['اصنع منشورك الآن', 'بيانات مشروعك ← 4 أفكار ← منشور حقيقي ← تصميم ← سجّل واحفظ. التجربة كلها قدامك قبل ما تدفع أي حاجة.'],
            'brands'   => ['براندات بتشتغل مع Spread AI', 'براندات وشركات بتعمل محتواها وتصميماتها مع Spread AI.'],
            'promos'   => ['ابدأ بعرض يناسبك', 'عروض حالية لفترة محدودة — اختار اللي يناسب مشروعك.'],
            'problems' => ['التسويق مش صعب… بس متعب لوحدك', 'لو واحدة من دول بتحصل معاك، إنت في المكان الصح.'],
            'about'    => ['كل مشكلة ليها حل في Spread AI', 'أدوات بتشتغل مع بعض — من الفكرة لحد النشر.'],
            'steps'    => ['من بيانات مشروعك… لبوست منشور', 'الخط بيترسم وإنت نازل — كل نقطة خطوة Spread AI بيعملها معاك.'],
            'services' => ['كل اللي مشروعك محتاجه… في منصة واحدة', 'التبويبات بتتابعك وإنت نازل — دوس على أي خدمة وهتروحلها.'],
            'gallery'  => ['شغل طالع من Design Studio', 'بوستات وستوريز وبورتريه ولوجوهات — اتعملت على المنصة.'],
            'pricing'  => ['باقات على قد احتياجك', 'اختار الباقة، وأول ما الدفع يتأكد الكريدت بيوصل محفظتك.'],
        ],
        'cta' => ['جاهز نبدأ؟', 'أول منشور لمشروعك على بعد دقيقتين — وSpread AI هيكمّل معاك الباقي.', 'ابدأ الآن', 'جرّب مجانًا'],
    ];
}

/** القيم القديمة اللي اتزرعت مع أول تركيب للموقع — لو لسه زي ما هي بنستبدلها بمحتوى التصميم الجديد */
function s_home_old_seeds(): array
{
    return [
        'site_problems'  => ['مفيش وقت للمحتوى', 'المحتوى مش ثابت', 'التصميم مكلّف', 'مش عارف تكتب إيه', 'النشر بينسى', 'نتائج بدون قياس'],
        'site_solutions' => ['هوية بصرية ثابتة', 'محتوى في دقايق', 'تصميمات احترافية', 'نشر تلقائي'],
        'site_steps'     => ['صناعة الهوية', 'عمل خطة', 'إيجاد أفكار', 'إنشاء محتوى', 'صناعة تصميم', 'النشر على السوشيال'],
        'site_services'  => ['كتابة المحتوى', 'تصميم السوشيال', 'خطط المحتوى', 'الهوية البصرية', 'صناعة اللوجو', 'النشر والجدولة'],
        'sections' => [
            'brands' => 'بيثقوا فينا', 'promos' => 'عروض حالية', 'problems' => 'إيه اللي بيوقفك؟', 'about' => 'إحنا بنحلها إزاي؟',
            'steps' => 'إزاي بتشتغل؟', 'services' => 'خدماتنا', 'gallery' => 'شغل اتعمل بالمنصة', 'pricing' => 'الباقات', 'trial' => 'اصنع منشورك الآن',
        ],
        'section_order' => ['hero' => 1, 'ribbon' => 2, 'brands' => 3, 'trial' => 3, 'promos' => 4, 'problems' => 5, 'about' => 6,
                            'steps' => 7, 'services' => 8, 'gallery' => 9, 'pricing' => 10, 'cta' => 11],
        'ribbon_text' => 'SPREAD AI • محتوى بالذكاء الاصطناعي • تصميمات • خطط محتوى • نشر تلقائي •',
        'cta_body' => 'اعمل حساب دلوقتي وجرّب أول منشور وتصميم مجانًا.',
        'cta_btn' => 'ابدأ مجانًا',
    ];
}

/** ترتيب أقسام التصميم */
function s_home_order(): array
{
    return ['hero', 'ribbon', 'trial', 'brands', 'promos', 'problems', 'about', 'steps', 'services', 'gallery', 'pricing', 'testimonials', 'faq', 'cta'];
}

/**
 * ترقية مرة واحدة: أعمدة جديدة (sql/site-home-v2.sql) + استبدال المحتوى المزروع القديم بمحتوى التصميم.
 * أي حاجة اتعدّلت من لوحة الموقع مابتتلمسش.
 */
function s_home_upgrade(): void
{
    if (s_setting('home_v2_ready') === '2') return;
    $file = dirname(__DIR__) . '/sql/site-home-v2.sql';
    if (is_file($file)) {
        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            try { sdb()->exec($stmt); } catch (\Throwable $e) { error_log('[site-home] ' . $e->getMessage()); }
        }
    }
    $D = s_home_defaults();
    $O = s_home_old_seeds();
    $isOld = function (string $table) use ($O): bool {
        $titles = array_column(s_all("SELECT title FROM `{$table}`"), 'title');
        return !$titles || !array_diff($titles, $O[$table]);
    };
    try {
        if ($isOld('site_problems')) {
            s_run('DELETE FROM site_problems');
            foreach ($D['problems'] as $i => [$ic, $t, $b]) s_run('INSERT INTO site_problems (title, body, icon, sort_order) VALUES (?,?,?,?)', [$t, $b, $ic, $i + 1]);
        }
        if ($isOld('site_solutions')) {
            s_run('DELETE FROM site_solutions');
            foreach ($D['solutions'] as $i => [$ic, $t, $b, $ins]) s_run('INSERT INTO site_solutions (title, body, icon, instead_of, sort_order) VALUES (?,?,?,?,?)', [$t, $b, $ic, $ins, $i + 1]);
        }
        if ($isOld('site_steps')) {
            s_run('DELETE FROM site_steps');
            foreach ($D['steps'] as $i => [$ic, $t]) s_run('INSERT INTO site_steps (title, body, icon, sort_order) VALUES (?,?,?,?)', [$t, '', $ic, $i + 1]);
        }
        if ($isOld('site_services')) {
            s_run('DELETE FROM site_services');
            foreach ($D['services'] as $i => [$ic, $t, $st, $b, $bl, $url]) {
                s_run('INSERT INTO site_services (title, subtitle, body, bullets, icon, link_text, link_url, sort_order) VALUES (?,?,?,?,?,?,?,?)',
                    [$t, $st, $b, $bl, $ic, 'جرّب ' . $t, $url, $i + 1]);
            }
        }
        if (!s_all('SELECT id FROM site_slides LIMIT 1')) {
            foreach ($D['slides'] as $i => $sl) {
                s_run('INSERT INTO site_slides (title, subtitle, tag, bot, sort_order) VALUES (?,?,?,?,?)', [$sl['title'], $sl['subtitle'], $sl['tag'], $sl['bot'], $i + 1]);
            }
        }
        // عناوين الأقسام اللي لسه على القيمة القديمة (أو فاضية)
        foreach ($D['sections'] as $k => [$t, $sub]) {
            $old = $O['sections'][$k] ?? null;
            s_run('UPDATE site_sections SET title = ?, subtitle = ? WHERE section_key = ? AND (title IS NULL OR title = "" OR title = ?)', [$t, $sub, $k, (string) $old]);
        }
        // ترتيب الأقسام زي التصميم — لو لسه الترتيب الافتراضي القديم
        $cur = [];
        foreach (s_all('SELECT section_key, sort_order FROM site_sections') as $r) $cur[$r['section_key']] = (int) $r['sort_order'];
        $same = true;
        // «جاهز نبدأ» بيتزق لتحت لما «آراء العملاء» و«الأسئلة الشائعة» بيتضافوا (site/upgrade.php) — مش تعديل من الأدمن
        foreach ($cur as $k => $v) { if ($k !== 'cta' && isset($O['section_order'][$k]) && $O['section_order'][$k] !== $v) { $same = false; break; } }
        if ($same) {
            foreach (s_home_order() as $i => $k) s_run('UPDATE site_sections SET sort_order = ? WHERE section_key = ?', [$i + 1, $k]);
        }
        if (in_array(s_setting('ribbon_text'), ['', $O['ribbon_text']], true)) s_set('ribbon_text', implode("\n", $D['marquee']));
        if (in_array(s_setting('cta_body'), ['', $O['cta_body']], true)) s_set('cta_body', $D['cta'][1]);
        if (in_array(s_setting('cta_btn'), ['', $O['cta_btn']], true)) s_set('cta_btn', $D['cta'][2]);
        s_set('home_v2_ready', '2');
    } catch (\Throwable $e) {
        error_log('[site-home] upgrade ' . $e->getMessage());
    }
}

/* ═══════════════ باقات المنصة (نظام الدفع الجديد) ═══════════════ */

/**
 * الباقات النشطة من credit_packages — «اشترك» بيودّي صفحة الدفع على المنصة.
 * لو قاعدة المنصة مش متاحة: باقات لوحة الموقع القديمة (site_packages) كاحتياطي.
 */
function s_home_packages(): array
{
    $rows = s_platform_all('SELECT * FROM credit_packages WHERE is_active = 1 ORDER BY order_num ASC, id ASC');
    $out = [];
    if ($rows) {
        $showCredits = s_platform_setting('credits_display', 'percent') === 'visible';
        $units = ['posts' => 'منشور', 'designs' => 'تصميم', 'publishes' => 'نشر', 'research' => 'بحث', 'videos' => 'سكريبت فيديو'];
        foreach ($rows as $r) {
            $days = (int) ($r['validity_days'] ?? 30) ?: 30;
            $q = json_decode((string) ($r['quotas_json'] ?? ''), true);
            $quota = [];
            if (is_array($q)) foreach ($units as $k => $l) if ((int) ($q[$k] ?? 0) > 0) $quota[] = (int) $q[$k] . ' ' . $l;
            $out[] = [
                'id'       => (int) $r['id'],
                'name'     => (string) $r['name'],
                'desc'     => trim((string) ($r['description'] ?? '')),
                'price'    => (float) $r['price_egp'],
                'days'     => $days,
                'yearly'   => $days >= 300,
                'period'   => $days >= 300 ? '/ سنويًا' : ($days >= 28 && $days <= 31 ? '/ شهريًا' : '/ ' . $days . ' يوم'),
                'chip'     => ($showCredits || !$quota) ? number_format((int) $r['credits']) . ' Credits' : implode(' · ', array_slice($quota, 0, 3)),
                'features' => s_lines((string) ($r['features'] ?? '')),
                'bonus'    => (int) ($r['bonus_credits'] ?? 0),
                'badge'    => trim((string) ($r['badge'] ?? '')),
                'featured' => !empty($r['is_featured']),
                'url'      => s_url('checkout.php?package=' . (int) $r['id']),
                'platform' => true,
            ];
        }
        return $out;
    }
    foreach (s_all('SELECT * FROM site_packages WHERE is_active = 1 ORDER BY sort_order, id') as $r) {
        $out[] = [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'desc' => (string) ($r['description'] ?? ''),
            'price' => (string) $r['price'], 'days' => 30, 'yearly' => false, 'period' => $r['period'] ? '/ ' . $r['period'] : '',
            'chip' => '', 'features' => s_lines((string) $r['features']), 'bonus' => 0, 'badge' => (string) ($r['badge'] ?? ''),
            'featured' => !empty($r['is_featured']), 'url' => s_link((string) ($r['cta_url'] ?: s_setting('platform_register_url', PLATFORM_REGISTER))) ?: PLATFORM_REGISTER,
            'platform' => false,
        ];
    }
    return $out;
}

/* ═══════════════ «خطوات العمل»: الخط المتموّج بعدد الخطوات ═══════════════ */

/**
 * نفس منحنى التصميم بأي عدد خطوات:
 *   ديسكتوب 1240×420 (نقط تحت/فوق بالتبادل من اليمين للشمال) · موبايل 358×N (نقط يمين/شمال بالتبادل من فوق لتحت)
 */
function s_steps_geometry(int $n): array
{
    $n = max(2, $n);
    $f = fn($v) => number_format($v, 1, '.', '');
    $curve = function (array $pts, bool $horizontal) use ($f): string {
        $d = 'M ' . $f($pts[0][0]) . ' ' . $f($pts[0][1]);
        $last = count($pts) - 1;
        for ($i = 0; $i < $last; $i++) {
            [$x0, $y0] = $pts[$i]; [$x1, $y1] = $pts[$i + 1];
            $dx = $x1 - $x0; $dy = $y1 - $y0;
            $c1 = $i === 0 ? [$x0 + $dx / 6, $y0 + $dy / 6] : ($horizontal ? [$x0 + $dx / 3, $y0] : [$x0, $y0 + $dy / 3]);
            $c2 = $i === $last - 1 ? [$x1 - $dx / 6, $y1 - $dy / 6] : ($horizontal ? [$x1 - $dx / 3, $y1] : [$x1, $y1 - $dy / 3]);
            $d .= ' C ' . $f($c1[0]) . ' ' . $f($c1[1]) . ', ' . $f($c2[0]) . ' ' . $f($c2[1]) . ', ' . $f($x1) . ' ' . $f($y1);
        }
        return $d;
    };
    $dk = [];
    for ($i = 0; $i < $n; $i++) $dk[] = [1170 - $i * (1100 / ($n - 1)), $i % 2 ? 100 : 320];
    $mh = 50 + ($n - 1) * 110 + 60;
    $mb = [];
    for ($i = 0; $i < $n; $i++) $mb[] = [$i % 2 ? 110 : 60, 50 + $i * 110];
    return ['desk' => ['d' => $curve($dk, true), 'pts' => $dk], 'mob' => ['d' => $curve($mb, false), 'pts' => $mb, 'h' => $mh]];
}

/* ═══════════════ بيانات الأقسام (الرئيسية · الصفحات الداخلية · بلوكات الـ Page Builder) ═══════════════ */

/** ترتيب الأقسام الظاهرة من لوحة الموقع */
function s_home_visible_order(): array
{
    $order = [];
    foreach (s_all('SELECT section_key, is_visible, sort_order FROM site_sections ORDER BY sort_order, id') as $r) {
        if ((int) $r['is_visible'] === 1) $order[] = $r['section_key'];
    }
    if (!$order) $order = s_home_order();
    foreach (s_home_order() as $k) if (!in_array($k, $order, true) && !s_one('SELECT id FROM site_sections WHERE section_key = ?', [$k])) $order[] = $k;
    return $order;
}

/** آراء العملاء المنشورة (المميز الأول) */
function s_testimonials(int $limit = 12, bool $featuredOnly = false): array
{
    return s_all('SELECT * FROM site_testimonials WHERE is_active = 1' . ($featuredOnly ? ' AND is_featured = 1' : '') . ' ORDER BY is_featured DESC, sort_order, id LIMIT ' . max(1, min(60, $limit)));
}

function s_faqs(string $category = '', int $limit = 30): array
{
    return $category !== ''
        ? s_all('SELECT * FROM site_faqs WHERE is_active = 1 AND category = ? ORDER BY sort_order, id LIMIT ' . max(1, min(100, $limit)), [$category])
        : s_all('SELECT * FROM site_faqs WHERE is_active = 1 ORDER BY sort_order, id LIMIT ' . max(1, min(100, $limit)));
}

/**
 * كل المتغيرات اللي أقسام site/sections/*.php محتاجاها — بتتحمّل مرة واحدة لكل طلب.
 * $need: أسماء الأقسام المطلوبة (null = كله) علشان الصفحات الداخلية ماتحمّلش بيانات مش هتستخدمها.
 */
function s_home_ctx(?array $need = null): array
{
    static $memo = [];
    $all = $need === null;
    $want = fn(string $k) => $all || in_array($k, $need, true);
    $D = s_home_defaults();
    $c = $memo + [
        'D' => $D,
        // روابط من الإعدادات — s_link بيمنع javascript: وأي بروتوكول غريب
        'loginUrl' => s_link(s_setting('platform_login_url', PLATFORM_LOGIN)) ?: PLATFORM_LOGIN,
        'regUrl' => s_link(s_setting('platform_register_url', PLATFORM_REGISTER)) ?: PLATFORM_REGISTER,
        'siteName' => s_setting('site_name', 'Spread AI'),
        'logo' => s_setting('logo_path') !== '' ? SITE_UPLOAD_URL . '/' . s_setting('logo_path') : s_url('site-assets/img/spread-mark.png'),
        'img' => fn(string $f) => s_url('site-assets/img/' . $f),
        'appUrl' => fn(string $u) => preg_match('~^(https?:)?//|^/|^#|^mailto:|^tel:~', $u) ? $u : s_url($u),
        'svcIcons' => ['brain', 'search', 'bulb', 'image', 'megaphone', 'calendar'],
    ];
    if (!array_key_exists('trialOn', $c)) $c['trialOn'] = s_platform_pdo() === null || s_platform_setting('trial_enabled', '1') === '1';
    if (!array_key_exists('order', $c)) $c['order'] = s_home_visible_order();

    if (($want('hero') || $want('steps')) && !array_key_exists('slides', $c)) {
        $slides = s_all('SELECT * FROM site_slides WHERE is_active = 1 ORDER BY sort_order, id LIMIT 5');
        if (!$slides) $slides = $D['slides'];
        $bots = ['wave', 'think', 'sit'];
        foreach ($slides as $i => &$sl) {
            $sl['bot'] = in_array($sl['bot'] ?? '', $bots, true) ? $sl['bot'] : $bots[$i % 3];
            $sl['tag'] = trim((string) ($sl['tag'] ?? '')) ?: ($D['slides'][$i % 3]['tag']);
        }
        unset($sl);
        $c['slides'] = $slides;
    }
    $loaders = [
        'marquee'   => ['ribbon',   fn() => array_values(array_filter(array_map('trim', preg_split('/[\r\n•]+/u', s_setting('ribbon_text'))))) ?: $D['marquee']],
        'brands'    => ['brands',   fn() => s_all('SELECT * FROM site_brands WHERE is_active = 1 ORDER BY sort_order, id')],
        'promos'    => ['promos',   fn() => s_all('SELECT * FROM site_promos WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= CURDATE())
                                                   AND (ends_at IS NULL OR ends_at >= CURDATE()) ORDER BY sort_order, id LIMIT 4')],
        'problems'  => ['problems', fn() => s_all('SELECT * FROM site_problems WHERE is_active = 1 ORDER BY sort_order, id')],
        'solutions' => ['about',    fn() => s_all('SELECT * FROM site_solutions WHERE is_active = 1 ORDER BY sort_order, id')],
        'steps'     => ['steps',    fn() => s_all('SELECT * FROM site_steps WHERE is_active = 1 ORDER BY sort_order, id')],
        'services'  => ['services', fn() => s_all('SELECT * FROM site_services WHERE is_active = 1 ORDER BY sort_order, id')],
        'gallery'   => ['gallery',  fn() => s_all('SELECT * FROM site_gallery WHERE is_active = 1 ORDER BY sort_order, id LIMIT 24')],
        'packages'  => ['pricing',  fn() => s_home_packages()],
        'testimonials' => ['testimonials', fn() => s_testimonials(12)],
        'faqs'      => ['faq',      fn() => s_faqs()],
    ];
    foreach ($loaders as $var => [$sk, $fn]) {
        if (array_key_exists($var, $c)) continue;
        // الهيرو بيعرض عدد الخطوات · الهيدر بيحتاج يعرف لو فيه تصميمات/أسعار
        if ($want($sk) || ($all) || ($var === 'steps' && $want('hero'))) $c[$var] = $fn();
    }
    if (!array_key_exists('gItems', $c) && array_key_exists('gallery', $c)) {
        $dzCats = ['all' => 'الكل', 'post' => 'بوستات', 'story' => 'ستوريز', 'portrait' => 'بورتريه', 'ba' => 'قبل / بعد', 'logo' => 'لوجوهات'];
        $dzMap = function (?string $cat): string {
            $cat = mb_strtolower(trim((string) $cat));
            if ($cat === '') return 'post';
            foreach (['story' => ['story', 'ستوري', 'ستوريز', '9:16'], 'portrait' => ['portrait', 'بورتريه', '4:5'], 'logo' => ['logo', 'لوجو', 'لوجوهات'],
                      'ba' => ['ba', 'before', 'قبل', 'بعد'], 'post' => ['post', 'بوست', 'بوستات', '1:1']] as $k => $keys) {
                foreach ($keys as $w) if (mb_strpos($cat, $w) !== false) return $k;
            }
            return 'post';
        };
        $gItems = array_map(fn($g) => $g + ['cat' => $dzMap($g['category'] ?? '')], $c['gallery']);
        $baPairs = array_values(array_filter($gItems, fn($g) => $g['cat'] === 'ba'));
        $usedCats = array_unique(array_column($gItems, 'cat'));
        $dzCats = array_filter($dzCats, fn($l, $k) => $k === 'all' || in_array($k, $usedCats, true), ARRAY_FILTER_USE_BOTH);
        if (count($baPairs) < 2) unset($dzCats['ba']);
        $c += ['gItems' => $gItems, 'baPairs' => $baPairs, 'dzCats' => $dzCats];
    }
    if (!array_key_exists('hasToggle', $c) && array_key_exists('packages', $c)) {
        $hasYearly = count(array_filter($c['packages'], fn($p) => $p['yearly'])) > 0;
        $hasMonthly = count(array_filter($c['packages'], fn($p) => !$p['yearly'])) > 0;
        $c['hasToggle'] = $hasYearly && $hasMonthly;
    }
    $memo = $c; // المحمّل فعلًا بس — الباقي بيتحمّل لو اتطلب بعدين
    foreach (array_keys($loaders) as $var) $c[$var] = $c[$var] ?? [];
    return $c + ['gItems' => [], 'baPairs' => [], 'dzCats' => [], 'hasToggle' => false, 'slides' => $D['slides']];
}

/**
 * رسم قسم من الأقسام (site/sections/{key}.php) بنفس شكل الرئيسية.
 * $ovr: [title, subtitle] بديل لعنوان القسم (بلوكات الـ Page Builder)
 */
function s_render_section(string $key, array $ctx, ?array $ovr = null): string
{
    $file = __DIR__ . '/sections/' . preg_replace('/[^a-z_]/', '', $key) . '.php';
    if (!is_file($file)) return '';
    $D = $ctx['D'];
    $sec = function (string $k, int $i) use ($D, $key, $ovr): string {
        if ($ovr !== null && $k === $key && trim((string) ($ovr[$i] ?? '')) !== '') return (string) $ovr[$i];
        $s = s_section($k);
        return [(string) ($s['title'] ?: ($D['sections'][$k][0] ?? '')), (string) ($s['subtitle'] ?? ($D['sections'][$k][1] ?? ''))][$i];
    };
    $secHead = function (string $k, string $chip, string $icon = 'sparkle') use ($sec): string {
        $sub = $sec($k, 1);
        return '<div class="h-sh rv"><span class="ws-chip">' . s_icon($icon, 15) . e($chip) . '</span><h2 class="ws-h2">' . e($sec($k, 0)) . '</h2>'
            . ($sub !== '' ? '<p>' . e($sub) . '</p>' : '') . '</div>';
    };
    extract($ctx, EXTR_SKIP);
    ob_start();
    include $file;
    return (string) ob_get_clean();
}

/** إعدادات «اصنع منشورك» للـ JS (home-v2.js) */
function s_trial_js_config(string $regUrl, string $loginUrl): array
{
    return [
        'trialApi' => s_url('ajax/trial.php'),
        'reg' => $regUrl,
        'login' => $loginUrl,
        'tags' => ['مطعم أو كافيه' => '#أكل_بيتي #مطاعم #عروض_اليوم', 'عيادة أو مركز طبي' => '#صحتك_تهمنا #استشارة #عيادة', 'متجر ملابس' => '#ستايل #كولكشن_جديد #موضة',
                   'أكاديمية أو كورسات' => '#تعلم #كورسات #مهارات', 'خدمات' => '#خدمات #جودة #ثقة'],
    ];
}
