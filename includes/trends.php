<?php
/**
 * Spread AI v2 — Trend Studio (المرحلة ⑤-ب)
 * الترندات بيختارها فريق Spread AI من الأدمن، والـ AI بيفصّلها على براند كل عميل
 * «هنستخدم هيكل الترند — مش نسخة منه»
 */

function trend_types(): array      { return ['Meme', 'Visual', 'Reel', 'Carousel']; }
function trend_platforms(): array  { return ['Instagram', 'Facebook', 'TikTok', 'LinkedIn', 'Snapchat']; }
function trend_industries(): array { return ['مطاعم', 'عيادات', 'تجميل', 'عقارات', 'متاجر', 'تعليم', 'خدمات']; }

/** الترندات الافتراضية (من ملف التصميم) */
function trends_defaults(): array
{
    return [
        ['code' => 'T142', 'name' => 'الباب المفاجأة', 'type' => 'Reel', 'platforms' => ['Instagram', 'TikTok'], 'industries' => ['مطاعم', 'تجميل', 'عيادات', 'متاجر'],
         'description' => 'شخص يفتح الباب ويتفاجئ بالمنتج قدامه.', 'adapt' => 'بدّل المنتج بأهم خدمة أو منتج عند البراند، وخلي رد الفعل طبيعي.',
         'structure' => ['Hook', 'مفاجأة', 'كشف المنتج', 'CTA'], 'visual' => ['لقطة قريبة', 'انتقال سريع', 'المنتج'], 'tone' => ['مضحك', 'سريع'],
         'allowed' => ['الشخص', 'المنتج', 'الخلفية', 'النص'], 'locked' => ['ترتيب المشاهد', 'الحركة الأساسية'], 'status' => 'active', 'start' => '2026-09-15', 'end' => '2026-10-15', 'sort' => 10],
        ['code' => 'T138', 'name' => 'التحول في 3 ثواني', 'type' => 'Carousel', 'platforms' => ['Instagram', 'Facebook'], 'industries' => ['عيادات', 'تجميل', 'عقارات'],
         'description' => 'مقارنة سريعة بين الوضع قبل وبعد بإيقاع ثابت.', 'adapt' => 'استخدم صور حقيقية بإذن العميل، والنتيجة في آخر شريحة.',
         'structure' => ['الوضع قبل', 'لحظة التحول', 'النتيجة', 'CTA'], 'visual' => ['صورة قبل', 'فاصل حركة', 'صورة بعد'], 'tone' => ['ملهم', 'واضح'],
         'allowed' => ['الصور', 'النص', 'الألوان'], 'locked' => ['عدد الشرائح', 'ترتيب قبل ثم بعد'], 'status' => 'active', 'start' => '2026-09-01', 'end' => '2026-10-30', 'sort' => 20],
        ['code' => 'T131', 'name' => 'POV: أول زيارة', 'type' => 'Meme', 'platforms' => ['TikTok', 'Instagram'], 'industries' => ['مطاعم', 'عيادات', 'تعليم', 'متاجر'],
         'description' => 'مشهد من وجهة نظر العميل في أول زيارة، بلمسة كوميدية.', 'adapt' => 'ركّز على لحظة حقيقية بتحصل مع العملاء فعلًا.',
         'structure' => ['POV', 'موقف', 'رد فعل', 'CTA'], 'visual' => ['كاميرا أولى', 'لقطة رد فعل'], 'tone' => ['مضحك', 'قريب'],
         'allowed' => ['الموقف', 'النص', 'المكان'], 'locked' => ['زاوية POV'], 'status' => 'active', 'start' => '2026-09-10', 'end' => '2026-10-10', 'sort' => 30],
        ['code' => 'T125', 'name' => 'السعر المفاجئ', 'type' => 'Visual', 'platforms' => ['Facebook', 'Instagram'], 'industries' => ['متاجر', 'مطاعم'],
         'description' => 'السعر بيظهر في الآخر بشكل مفاجئ وكبير.', 'adapt' => 'استخدمه مع عرض حقيقي بس.',
         'structure' => ['المنتج', 'تشويق', 'السعر', 'CTA'], 'visual' => ['منتج', 'رقم كبير'], 'tone' => ['جريء'],
         'allowed' => ['المنتج', 'السعر'], 'locked' => ['السعر في الآخر'], 'status' => 'hidden', 'start' => '2026-09-05', 'end' => '2026-10-05', 'sort' => 40],
        ['code' => 'T119', 'name' => 'جهّز معايا', 'type' => 'Reel', 'platforms' => ['TikTok'], 'industries' => ['تجميل', 'متاجر'],
         'description' => 'تحضير خطوة بخطوة قبل مناسبة.', 'adapt' => 'اعرض المنتج كجزء طبيعي من التحضير.',
         'structure' => ['البداية', 'خطوات', 'النتيجة'], 'visual' => ['مرآة', 'قطعات سريعة'], 'tone' => ['خفيف'],
         'allowed' => ['الخطوات', 'المنتجات'], 'locked' => ['الإيقاع'], 'status' => 'active', 'start' => '2026-07-01', 'end' => '2026-08-15', 'sort' => 50],
    ];
}

/**
 * زرع الافتراضي — أول مرة لوحده، أو «استرجاع الافتراضي» من الأدمن
 * ($restore = true: بيرجّع الـ 5 لحالتهم الأصلية، ومابيلمسش ترندات الأدمن التانية)
 */
function trends_seed(bool $restore = false): int
{
    if (!$restore && get_setting('trends_seeded', '0') === '1') return 0;
    $n = 0;
    foreach (trends_defaults() as $t) {
        $vals = [$t['name'], $t['type'], json_encode($t['platforms'], JSON_UNESCAPED_UNICODE), json_encode($t['industries'], JSON_UNESCAPED_UNICODE),
                 $t['description'], $t['adapt'], json_encode($t['structure'], JSON_UNESCAPED_UNICODE), json_encode($t['visual'], JSON_UNESCAPED_UNICODE),
                 json_encode($t['tone'], JSON_UNESCAPED_UNICODE), json_encode($t['allowed'], JSON_UNESCAPED_UNICODE), json_encode($t['locked'], JSON_UNESCAPED_UNICODE),
                 $t['status'], $t['start'], $t['end'], $t['sort']];
        $exists = db_one('SELECT id FROM trends WHERE code = ?', [$t['code']]);
        if ($exists && $restore) {
            db_run('UPDATE trends SET name=?, type=?, platforms=?, industries=?, description=?, adapt=?, structure=?, visual=?, tone=?, allowed=?, locked=?,
                    status=?, start_date=?, end_date=?, sort_order=? WHERE id=?', array_merge($vals, [$exists['id']]));
            $n++;
        } elseif (!$exists) {
            db_insert('INSERT INTO trends (name, type, platforms, industries, description, adapt, structure, visual, tone, allowed, locked,
                       status, start_date, end_date, sort_order, code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [$t['code']]));
            $n++;
        }
    }
    set_setting('trends_seeded', '1');
    return $n;
}

function trend_list_field($v): array
{
    $a = is_array($v) ? $v : (json_decode((string) $v, true) ?: []);
    return array_values(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 60), $a), fn($x) => $x !== ''));
}

/** الحالة الفعلية: active · hidden · expired (عدّى تاريخ الانتهاء) · scheduled (لسه مابداش) */
function trend_effective_status(array $r): string
{
    if ($r['status'] === 'hidden') return 'hidden';
    $today = date('Y-m-d');
    if (!empty($r['end_date']) && $r['end_date'] < $today) return 'expired';
    if (!empty($r['start_date']) && $r['start_date'] > $today) return 'scheduled';
    return 'active';
}

function trend_decode(array $r): array
{
    return [
        'id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'type' => $r['type'],
        'platforms' => trend_list_field($r['platforms']), 'industries' => trend_list_field($r['industries']),
        'description' => (string) ($r['description'] ?? ''), 'adapt' => (string) ($r['adapt'] ?? ''),
        'structure' => trend_list_field($r['structure']), 'visual' => trend_list_field($r['visual']), 'tone' => trend_list_field($r['tone']),
        'allowed' => trend_list_field($r['allowed']), 'locked' => trend_list_field($r['locked']),
        'prompt_template' => (string) ($r['prompt_template'] ?? ''),
        'ref' => !empty($r['ref_image_path']) ? (function_exists('upload_url') ? upload_url($r['ref_image_path']) : null) : null,
        'ref_path' => $r['ref_image_path'] ?? null,
        'status' => $r['status'], 'effective' => trend_effective_status($r),
        'start' => $r['start_date'], 'end' => $r['end_date'], 'sort' => (int) $r['sort_order'],
    ];
}

function trends_all(): array
{
    try {
        trends_seed(false);
        return array_map('trend_decode', db_all('SELECT * FROM trends ORDER BY sort_order, id DESC'));
    } catch (\Throwable $e) {
        return [];   // قبل الترحيل
    }
}

/** اللي بيظهر للعملاء: شغال + بدأ + ماانتهاش */
function trends_live(): array
{
    return array_values(array_filter(trends_all(), fn($t) => $t['effective'] === 'active'));
}

function trend_get_live(int $id): ?array
{
    foreach (trends_live() as $t) if ($t['id'] === $id) return $t;
    return null;
}

/** مجال البراند ← مجال من قايمة الترندات (بالكلمات) */
function trend_brand_industry(?array $brand): ?string
{
    $txt = mb_strtolower(trim(($brand['industry'] ?? '') . ' ' . ($brand['business_name'] ?? '') . ' ' . mb_substr((string) ($brand['description'] ?? ''), 0, 200)));
    if ($txt === '') return null;
    $map = [
        'عيادات'  => ['عياد', 'دكتور', 'طب', 'أسنان', 'اسنان', 'مستشفى', 'علاج', 'جراح', 'صيدل', 'clinic', 'dental', 'medical'],
        'مطاعم'   => ['مطعم', 'أكل', 'اكل', 'كافيه', 'كافي', 'حلويات', 'مشويات', 'بيتزا', 'برجر', 'restaurant', 'cafe', 'food'],
        'تجميل'   => ['تجميل', 'بيوتي', 'صالون', 'ميكب', 'ميك اب', 'بشرة', 'شعر', 'سبا', 'beauty', 'salon', 'spa'],
        'عقارات'  => ['عقار', 'شقق', 'شقة', 'كمبوند', 'فيلا', 'تطوير عقاري', 'real estate'],
        'متاجر'   => ['متجر', 'محل', 'ستور', 'منتجات', 'ملابس', 'عطور', 'اكسسوار', 'shop', 'store'],
        'تعليم'   => ['تعليم', 'أكاديمية', 'اكاديمية', 'كورس', 'مدرس', 'مدرسة', 'سنتر', 'دروس', 'academy', 'course'],
    ];
    foreach ($map as $ind => $words) {
        foreach ($words as $w) if (mb_strpos($txt, $w) !== false) return $ind;
    }
    return 'خدمات';
}

/** «مناسب لبراندك 95%» — مجال البراند في مجالات الترند = عالي */
function trend_fit(array $t, ?string $brandInd): int
{
    if (!$brandInd) return 70;
    if (in_array($brandInd, $t['industries'], true)) return 95;
    if (in_array('خدمات', $t['industries'], true)) return 80;
    return 60;
}

/** الـ Blueprint بالعربي — بيتبعت للـ AI */
function trend_blueprint_text(array $t): string
{
    $j = fn(array $a, string $sep = ' · ') => implode($sep, $a);
    return "الترند: «{$t['name']}» (نوعه: {$t['type']})\n"
        . "الوصف: {$t['description']}\n"
        . ($t['adapt'] ? "إزاي يتطبّق على أي براند: {$t['adapt']}\n" : '')
        . ($t['structure'] ? "هيكل الترند بالترتيب: " . $j($t['structure'], ' ← ') . "\n" : '')
        . ($t['visual'] ? "الهيكل البصري: " . $j($t['visual']) . "\n" : '')
        . ($t['tone'] ? "النبرة: " . $j($t['tone']) . "\n" : '')
        . ($t['allowed'] ? "مسموح تغييره: " . $j($t['allowed']) . "\n" : '')
        . ($t['locked'] ? "ثابت لا يتغير: " . $j($t['locked']) . "\n" : '');
}
