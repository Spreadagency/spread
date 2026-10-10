<?php
/**
 * Spread AI v2 — نظام الحالات الموحّد + مراحل الحملة
 *
 * ⚠️ مصدر الحقيقة الواحد للحالة.
 * الحالة بتتحسب من البيانات الفعلية (النص · التصميم · حالة النشر)
 * بدل ما نحدّثها يدويًا في كل مكان بيغيّر حالة النشر (16 مكان).
 * العمود workflow_flag بيخزّن بس القرارات اليدوية.
 */

if (!function_exists('db_one')) {
    require_once __DIR__ . '/db.php';
}

/* ═══════════ الحالات التسعة ═══════════ */

function content_statuses(): array
{
    // key => [label, color, icon, completion%, next-step hint]
    return [
        'draft'            => ['مسودة',          '#8391A6', '✎',  10, 'اكتب المحتوى'],
        'content_ready'    => ['المحتوى جاهز',   '#0C87EF', '✓',  35, 'راجعه أو صمّم له'],
        'needs_review'     => ['يحتاج مراجعة',   '#D98A1F', '⚑',  45, 'راجع ملاحظات التقييم'],
        'needs_design'     => ['يحتاج تصميم',    '#6E5BE0', '🎨', 55, 'اعمل التصميم'],
        'design_ready'     => ['التصميم جاهز',   '#13B5C1', '◈',  75, 'جدوله أو انشره'],
        'scheduled'        => ['مجدول',          '#0A5FCF', '🗓', 90, 'هيتنشر في موعده'],
        'ready_to_publish' => ['جاهز للنشر',     '#10A8A0', '🚀', 90, 'انشره دلوقتي'],
        'published'        => ['منشور',          '#10A8A0', '●', 100, ''],
        'publish_failed'   => ['فشل النشر',      '#E0526A', '!',  80, 'أعد المحاولة'],
    ];
}

/**
 * تعبير SQL بيحسب الحالة — للاستخدام في أي SELECT على contents
 * @param string $alias اسم جدول المحتوى في الاستعلام
 */
function content_status_sql(string $alias = 'c'): string
{
    $a = preg_replace('/[^a-z_]/i', '', $alias) ?: 'c';
    // ⑦-ج الفيديو مابيتصممش — بيفضل «المحتوى جاهز» لحد ما يتنشر (حالة التنفيذ في video_status)
    require_once __DIR__ . '/content-formats.php';
    $video = formats_ready() ? "WHEN {$a}.format = 'video' THEN 'content_ready'\n        " : '';
    return "(CASE
        WHEN {$a}.publish_status = 'published' THEN 'published'
        WHEN {$a}.publish_status = 'failed'    THEN 'publish_failed'
        WHEN {$a}.publish_status IN ('pending','processing') AND {$a}.scheduled_at IS NOT NULL THEN 'scheduled'
        WHEN {$a}.workflow_flag = 'ready_to_publish' THEN 'ready_to_publish'
        WHEN {$a}.workflow_flag = 'needs_review'     THEN 'needs_review'
        {$video}WHEN EXISTS (SELECT 1 FROM content_designs cd WHERE cd.content_id = {$a}.id) THEN 'design_ready'
        WHEN {$a}.workflow_flag = 'needs_design'     THEN 'needs_design'
        WHEN {$a}.generated_text IS NOT NULL AND {$a}.generated_text <> '' THEN 'content_ready'
        ELSE 'draft'
    END)";
}

/** نفس المنطق في PHP لصف واحد */
function content_status(array $row, ?bool $hasDesign = null): string
{
    $ps = (string) ($row['publish_status'] ?? 'draft');
    $flag = (string) ($row['workflow_flag'] ?? 'none');

    if ($ps === 'published') return 'published';
    if ($ps === 'failed') return 'publish_failed';
    if (in_array($ps, ['pending', 'processing'], true) && !empty($row['scheduled_at'])) return 'scheduled';
    if ($flag === 'ready_to_publish') return 'ready_to_publish';
    if ($flag === 'needs_review') return 'needs_review';
    if (($row['format'] ?? '') === 'video') return 'content_ready';

    if ($hasDesign === null && !empty($row['id'])) {
        $hasDesign = (bool) db_one('SELECT 1 x FROM content_designs WHERE content_id = ? LIMIT 1', [(int) $row['id']]);
    }
    if ($hasDesign) return 'design_ready';
    if ($flag === 'needs_design') return 'needs_design';
    if (trim((string) ($row['generated_text'] ?? '')) !== '') return 'content_ready';
    return 'draft';
}

/** وصف الحالة للواجهة */
function content_status_meta(string $key): array
{
    $all = content_statuses();
    [$label, $color, $icon, $pct, $next] = $all[$key] ?? $all['draft'];
    return ['key' => $key, 'label' => $label, 'color' => $color, 'icon' => $icon, 'pct' => $pct, 'next' => $next];
}

/**
 * تحديد قرار يدوي (مراجعة · تصميم · جاهز للنشر)
 * @return bool false لو القرار مش مسموح
 */
function content_set_flag(int $contentId, int $userId, string $flag, ?string $note = null): bool
{
    if (!in_array($flag, ['none', 'needs_review', 'needs_design', 'ready_to_publish'], true)) {
        return false;
    }
    $n = db_run(
        'UPDATE contents SET workflow_flag = ?, workflow_note = ? WHERE id = ? AND user_id = ?',
        [$flag, $note !== null ? mb_substr($note, 0, 255) : null, $contentId, $userId]
    );
    return $n !== false;
}

/* ═══════════ مراحل الحملة ═══════════ */

function campaign_stages(): array
{
    return [
        1 => ['key' => 'ideas',    'name' => 'الأفكار',  'desc' => 'اختيار الزاوية وتوليد الأفكار', 'icon' => '💡'],
        2 => ['key' => 'content',  'name' => 'المحتوى',  'desc' => 'منشورات وسكريبتات جاهزة',     'icon' => '✎'],
        3 => ['key' => 'review',   'name' => 'التقييم',  'desc' => 'مراجعة الجودة قبل التصميم',    'icon' => '⭐'],
        4 => ['key' => 'design',   'name' => 'التصميم',  'desc' => 'تصميمات بهوية البراند',       'icon' => '🎨'],
        5 => ['key' => 'schedule', 'name' => 'الجدولة',  'desc' => 'توزيع المحتوى على الشهر',     'icon' => '🗓'],
        6 => ['key' => 'publish',  'name' => 'النشر',    'desc' => 'مراجعة نهائية ونشر',          'icon' => '🚀'],
    ];
}

function campaign_goals(): array
{
    return [
        'bookings'   => ['زيادة الحجوزات', '📅'],
        'awareness'  => ['التوعية',        '💡'],
        'trust'      => ['بناء الثقة',     '🤝'],
        'offer'      => ['عرض أو خصم',     '🏷'],
        'launch'     => ['إطلاق خدمة',     '🚀'],
        'engagement' => ['تفاعل الجمهور',  '💬'],
    ];
}

function campaign_bases(): array
{
    return [
        'services'  => 'خدماتي',
        'audience'  => 'مشاكل جمهوري',
        'season'    => 'مناسبة أو موسم',
        'questions' => 'أسئلة العملاء',
        'results'   => 'نتائج وتجارب',
        'free'      => 'فكرة حرة',
    ];
}

/**
 * ملخص حملة: عدد المحتوى في كل حالة + نسبة الإنجاز
 */
function campaign_summary(int $campaignId): array
{
    $sql = 'SELECT ' . content_status_sql('c') . ' AS st, COUNT(*) n
            FROM contents c WHERE c.campaign_id = ? GROUP BY st';
    $counts = array_fill_keys(array_keys(content_statuses()), 0);
    $total = 0;
    foreach (db_all($sql, [$campaignId]) as $r) {
        $counts[$r['st']] = (int) $r['n'];
        $total += (int) $r['n'];
    }
    $pct = 0;
    if ($total > 0) {
        $sum = 0;
        foreach ($counts as $k => $n) {
            $sum += content_status_meta($k)['pct'] * $n;
        }
        $pct = (int) round($sum / $total);
    }
    return ['counts' => $counts, 'total' => $total, 'pct' => $pct];
}

/** نسبة تقدّم الحملة: المرحلة + اكتمال المحتوى */
function campaign_progress(array $c, ?array $summary = null): int
{
    $stagePct = (int) round(((int) ($c['max_stage'] ?? 1) - 1) / 6 * 100);
    if (($c['status'] ?? '') === 'completed') return 100;
    if (!$summary || $summary['total'] === 0) return max(5, $stagePct);
    return (int) round($stagePct * .5 + $summary['pct'] * .5);
}

/** تحويل صف حملة لشكل الـ API */
function campaign_to_api(array $c, bool $withSummary = true): array
{
    $sum = $withSummary ? campaign_summary((int) $c['id']) : null;
    $stages = campaign_stages();
    $stage = max(1, min(6, (int) $c['stage']));
    return [
        'id'          => (int) $c['id'],
        'title'       => (string) $c['title'],
        'goal'        => $c['goal'],
        'basis'       => $c['basis'],
        'topic'       => (string) ($c['topic'] ?? ''),
        'ideas_count' => (int) $c['ideas_count'],
        'notes'       => (string) ($c['notes'] ?? ''),
        'stage'       => $stage,
        'max_stage'   => max($stage, (int) $c['max_stage']),
        'stage_name'  => $stages[$stage]['name'],
        'status'      => $c['status'],
        'revision'    => (int) $c['revision'],
        'progress'    => campaign_progress($c, $sum),
        'contents'    => $sum,
        // ⑥-ب: أرقام الأفكار ← المحتوى ← التصميم ← النشر (للقايمة)
        'flow'        => function_exists('campaign_flow_counts') ? campaign_flow_counts((int) $c['id']) : null,
        'updated_at'  => $c['updated_at'],
        'created_at'  => $c['created_at'],
    ];
}

/** اسم الشهر بالعربي (1-12) */
if (!function_exists('arabic_month_name')) {
    function arabic_month_name(int $m): string
    {
        $names = [1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
                  'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
        return $names[max(1, min(12, $m))];
    }
}
