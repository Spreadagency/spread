<?php
/**
 * Spread AI v2 — «رحلتك الأولى» (المرحلة ⑦)
 *
 * 8 خطوات من الهوية لحد النشر — مش شرح نظري: كل خطوة بتعمل الفعل الحقيقي
 * (تحليل الموقع · حملة · أفكار · محتوى · تقييم · تصميم · جدولة · نشر)
 * والتقدم محسوب من الداتا الفعلية — مش علامة بيحطها العميل، فلو عمل الخطوة من
 * صفحتها الأصلية بتتعلّم هنا كمان.
 *
 * «حملة الرحلة» = الحملة المتعلّمة journey في prefs_json (لو موجودة)، وإلا آخر حملة نشطة.
 * أول ما الرحلة تخلص بتتعلّم — فحملة جديدة بعد كده مابترجّعش الرحلة لورا.
 */

require_once __DIR__ . '/db.php';

function journey_steps_meta(): array
{
    return [
        1 => ['key' => 'brand',    't' => 'إنشاء الهوية',   'tip' => 'ضيف موقع مشروعك (أو صفحته) والـ AI يستخرج منه هوية براندك.', 'act' => 'حلّل المشروع'],
        2 => ['key' => 'campaign', 't' => 'أول حملة',       'tip' => 'اختار هدف الحملة، والباقي الـ AI هيجيبه من هوية مشروعك.', 'act' => 'ابدأ الحملة'],
        3 => ['key' => 'ideas',    't' => 'توليد الأفكار',  'tip' => 'قول للـ AI كام فكرة عايز، وهو هيبنيها من هوية البراند.', 'act' => 'ولّد الأفكار'],
        4 => ['key' => 'content',  't' => 'إنشاء المحتوى',  'tip' => 'كل فكرة بتتحول لمنشور وسكريبت فيديو وكابشن وهاشتاجات.', 'act' => 'حوّل لمحتوى'],
        5 => ['key' => 'eval',     't' => 'تقييم المحتوى',  'tip' => 'الـ AI بيدي كل منشور درجة من 100 ويقولك تحسّن إيه.', 'act' => 'قيّم المحتوى'],
        6 => ['key' => 'design',   't' => 'إنشاء التصميم',  'tip' => 'التصميم بيتعمل بألوان ولوجو براندك تلقائيًا.', 'act' => 'صمّم المنشورات'],
        7 => ['key' => 'schedule', 't' => 'جدولة المحتوى',  'tip' => 'وزّع المنشورات على الشهر بنفسك أو سيب الـ AI يوزّعها.', 'act' => 'وزّع تلقائيًا'],
        8 => ['key' => 'publish',  't' => 'النشر',          'tip' => 'اربط صفحتك واجدول الحملة كلها — أو صدّرها وانشرها بنفسك.', 'act' => 'جهّز النشر'],
    ];
}

/** حملة الرحلة: المتعلّمة (أي حالة) وإلا آخر حملة نشطة */
function journey_campaign(int $uid): ?array
{
    try {
        return db_one('SELECT * FROM campaigns WHERE user_id = ? AND prefs_json LIKE ? ORDER BY id DESC LIMIT 1', [$uid, '%"journey":1%'])
            ?: (db_one('SELECT * FROM campaigns WHERE user_id = ? AND status = "active" ORDER BY id DESC LIMIT 1', [$uid]) ?: null);
    } catch (\Throwable $e) {
        return null;
    }
}

/** علّم الحملة إنها حملة الرحلة */
function journey_flag(array $c): void
{
    require_once __DIR__ . '/campaign-flow.php';
    $prefs = campaign_prefs($c);
    if (!empty($prefs['journey'])) return;
    $prefs['journey'] = 1;
    db_run('UPDATE campaigns SET prefs_json = ? WHERE id = ?', [json_encode($prefs, JSON_UNESCAPED_UNICODE), $c['id']]);
}

/**
 * حالة الرحلة كاملة
 * @return array{steps:array, done:int, total:int, pct:int, current:int, finished:bool, campaign:?array, counts:array, health:array, publish:array}
 */
function journey_state(int $uid): array
{
    require_once __DIR__ . '/brand-brain.php';
    require_once __DIR__ . '/campaign-flow.php';
    require_once __DIR__ . '/social.php';

    $health = brand_health(brand_for_user($uid));
    $c = journey_campaign($uid);
    $q = $c ? campaign_flow_counts((int) $c['id']) : array_fill_keys(['ideas', 'selected', 'posts', 'scripts', 'evaluated', 'planned', 'days', 'designed', 'published', 'queued'], 0);
    $prefs = $c ? campaign_prefs($c) : [];
    $allowed = feature_allows($uid);
    $pages = $allowed ? count(user_connections($uid)) : 0;

    $done = [
        1 => !empty($health['unlocked']),
        2 => (bool) $c,
        3 => $q['selected'] > 0,
        4 => $q['posts'] > 0,
        5 => $q['evaluated'] > 0 || !empty($c['eval_skipped']),
        6 => $q['designed'] > 0,
        7 => $q['planned'] > 0,
        8 => ($q['published'] + $q['queued']) > 0 || !empty($prefs['journey_exported']),
    ];
    $meta = journey_steps_meta();
    $current = 0;
    foreach ($done as $n => $d) {
        if (!$d) { $current = $n; break; }
    }
    $steps = [];
    foreach ($meta as $n => $m) {
        // خطوة مفتوحة = اتعملت، أو كل اللي قبلها اتعمل
        $open = $done[$n];
        if (!$open) {
            $open = true;
            for ($k = 1; $k < $n; $k++) if (!$done[$k]) { $open = false; break; }
        }
        $steps[] = $m + ['n' => $n, 'done' => $done[$n], 'open' => $open];
    }
    $count = count(array_filter($done));
    if ($count === 8 && $c) journey_flag($c);   // خلصت ← نثبّتها على الحملة دي
    $state = [
        'steps' => $steps, 'done' => $count, 'total' => 8, 'pct' => (int) round($count / 8 * 100),
        'current' => $current ?: 8, 'finished' => $count === 8,
        'campaign' => $c ? ['id' => (int) $c['id'], 'title' => (string) $c['title'], 'basis' => (string) ($c['basis'] ?? ''),
                          'eval_skipped' => !empty($c['eval_skipped']), 'max_stage' => (int) $c['max_stage']] : null,
        'counts' => $q,
        'health' => ['pct' => $health['pct'], 'gate' => $health['gate'], 'unlocked' => $health['unlocked'],
                     'missing' => array_slice(array_column($health['missing'], 'label'), 0, 4)],
        'publish' => ['allowed' => $allowed, 'pages' => $pages],
    ];
    $_SESSION['journey_pct'] = ['pct' => $state['pct'], 'at' => time()];
    return $state;
}

/** للقايمة الجانبية: الرحلة خلصت؟ (مخزّنة 5 دقايق علشان مانعدّش مع كل صفحة) */
function journey_finished_cached(int $uid): bool
{
    $c = $_SESSION['journey_pct'] ?? null;
    if (!$c || time() - (int) $c['at'] > 300) {
        try {
            journey_state($uid);
        } catch (\Throwable $e) {
            return false;
        }
        $c = $_SESSION['journey_pct'];
    }
    return (int) $c['pct'] >= 100;
}
