<?php
/**
 * Spread AI v2 — بيانات الرئيسية الجديدة (زي التصميم)
 * يحتاج منك إجراء · رحلتك الأولى · حملة غير مكتملة · ملخص الشهر · ابدأ بسرعة · أحدث المحتويات
 *
 * كل الأرقام من بيانات المنصة الحقيقية — مفيش أرقام تفاعل وهمية.
 */
require_once __DIR__ . '/ui-v2.php';
if (is_file(__DIR__ . '/lifecycle.php')) require_once __DIR__ . '/lifecycle.php';
if (is_file(__DIR__ . '/brand-brain.php')) require_once __DIR__ . '/brand-brain.php';
if (is_file(__DIR__ . '/social.php')) require_once __DIR__ . '/social.php';   // feature_allows
require_once __DIR__ . '/announcements.php';   // سلايدر الإعلانات (مكان «حملة غير مكتملة» القديم)

function dash_safe(callable $fn, $fallback)
{
    try { return $fn(); } catch (\Throwable $e) { error_log('[dashboard-v2] ' . $e->getMessage()); return $fallback; }
}

function dashboard_v2_data(array $user): array
{
    $uid = (int) $user['id'];
    $hour = (int) date('G');
    $first = explode(' ', trim((string) ($user['name'] ?? '')))[0] ?: 'صديقي';

    // ─── صحة الهوية ───
    $brand = function_exists('brand_for_user') ? brand_for_user($uid) : null;
    $health = function_exists('brand_health') ? brand_health($brand) : ['pct' => 0, 'gate' => 75, 'unlocked' => true];

    // ─── يحتاج منك إجراء (من الحالات الموحّدة) ───
    $st = dash_safe(function () use ($uid) {
        if (!function_exists('content_status_sql')) return [];
        $rows = db_all('SELECT ' . content_status_sql('c') . ' st, COUNT(*) n FROM contents c WHERE c.user_id = ? GROUP BY st', [$uid]);
        return array_map('intval', array_column($rows, 'n', 'st'));
    }, []);
    $needDesign = ($st['needs_design'] ?? 0) + ($st['content_ready'] ?? 0);
    $needReview = $st['needs_review'] ?? 0;
    $failed = $st['publish_failed'] ?? 0;
    $readySched = $st['design_ready'] ?? 0;
    $needSchedCampaigns = dash_safe(fn() => (int) (db_one(
        'SELECT COUNT(DISTINCT c.campaign_id) n FROM contents c
         WHERE c.user_id = ? AND c.campaign_id IS NOT NULL AND ' . content_status_sql('c') . ' = "design_ready"', [$uid])['n'] ?? 0), 0);
    $actions = [];
    if ($failed)      $actions[] = ['n' => $failed,     'label' => $failed === 1 ? 'منشور فشل نشره' : 'منشورات فشل نشرها', 'tone' => 'danger', 'url' => 'content-history.php'];
    if ($needDesign)  $actions[] = ['n' => $needDesign, 'label' => $needDesign === 1 ? 'منشور يحتاج تصميم' : 'منشورات تحتاج تصميم', 'tone' => 'violet', 'url' => 'content-history.php'];
    if ($needReview)  $actions[] = ['n' => $needReview, 'label' => 'تحتاج مراجعة', 'tone' => 'amber', 'url' => 'content-history.php'];
    if ($needSchedCampaigns) {
        $actions[] = ['n' => $needSchedCampaigns, 'label' => $needSchedCampaigns === 1 ? 'حملة تحتاج جدولة' : 'حملات تحتاج جدولة', 'tone' => 'blue', 'url' => 'campaigns.php'];
    } elseif ($readySched) {
        $actions[] = ['n' => $readySched, 'label' => 'جاهزة للجدولة', 'tone' => 'blue', 'url' => 'content-history.php'];
    }

    // ─── رحلتك الأولى ───
    $counts = dash_safe(fn() => db_one('SELECT
            (SELECT COUNT(*) FROM campaigns WHERE user_id = ?) camps,
            (SELECT COUNT(*) FROM contents WHERE user_id = ?) posts,
            (SELECT COUNT(*) FROM content_designs WHERE user_id = ?) designs,
            (SELECT COUNT(*) FROM social_connections WHERE user_id = ? AND status = "active") social,
            (SELECT COUNT(*) FROM contents WHERE user_id = ? AND publish_status = "published") published',
        [$uid, $uid, $uid, $uid, $uid]) ?: [], []);
    $campOn = ui_campaigns_on();
    $journey = [
        ['done' => !empty($health['unlocked']), 'label' => 'كمّل هوية براندك', 'url' => 'brand-brain.php'],
        ['done' => ($counts['camps'] ?? 0) > 0 || !$campOn, 'label' => 'ابدأ أول حملة', 'url' => $campOn ? 'campaign-new.php' : 'content-plan.php'],
        ['done' => ($counts['posts'] ?? 0) > 0, 'label' => 'اعمل أول منشور', 'url' => 'create-content.php'],
        ['done' => ($counts['designs'] ?? 0) > 0, 'label' => 'صمّم أول تصميم', 'url' => 'design-studio.php'],
        ['done' => ($counts['social'] ?? 0) > 0, 'label' => 'اربط صفحتك', 'url' => 'social-accounts.php'],
        ['done' => ($counts['published'] ?? 0) > 0, 'label' => 'انشر أول منشور', 'url' => 'content-history.php'],
    ];
    // ربط السوشيال ممكن يكون مقفول للحساب
    if (function_exists('feature_allows') && !dash_safe(fn() => feature_allows($uid, 'social_publishing'), true)) {
        $journey = array_values(array_filter($journey, fn($j) => !in_array($j['url'], ['social-accounts.php'], true)));
    }
    // ⑦ رحلتك الأولى (8 خطوات حقيقية) — لو الحملات شغالة؛ وإلا الرحلة القديمة فوق
    if ($campOn && is_file(__DIR__ . '/journey.php')) {
        $js = dash_safe(function () use ($uid) { require_once __DIR__ . '/journey.php'; return journey_state($uid); }, null);
        if ($js) {
            $journey = array_map(fn($st) => ['done' => $st['done'], 'label' => $st['t'], 'url' => 'journey.php'], $js['steps']);
        }
    }
    $jDone = count(array_filter($journey, fn($j) => $j['done']));
    $jNext = null;
    foreach ($journey as $j) { if (!$j['done']) { $jNext = $j; break; } }
    $jPct = count($journey) ? (int) round($jDone / count($journey) * 100) : 100;
    // 10: «رحلتك الأولى» بتختفي من الرئيسية لما التقدّم الحقيقي يوصل journey_hide_pct (افتراضي 60%)
    //     وكارت Brand Brain بيختفي لما الهوية توصل brand_card_hide_pct (افتراضي 90%) — ويفضل متاح من «الهوية»
    $jHide = max(1, min(100, (int) (function_exists('get_setting') ? get_setting('journey_hide_pct', 60) : 60)));
    $bHide = max(1, min(100, (int) (function_exists('get_setting') ? get_setting('brand_card_hide_pct', 90) : 90)));

    // ─── حملة غير مكتملة ───
    $campaign = null;
    if ($campOn && function_exists('campaign_to_api')) {
        $campaign = dash_safe(function () use ($uid) {
            foreach (db_all('SELECT * FROM campaigns WHERE user_id = ? AND status = "active" ORDER BY updated_at DESC LIMIT 5', [$uid]) as $c) {
                $a = campaign_to_api($c);
                if ($a['progress'] < 100) return $a;
            }
            return null;
        }, null);
    }

    // ─── ملخص الشهر ───
    $monthStart = date('Y-m-01 00:00:00');
    $month = dash_safe(fn() => db_one('SELECT
            (SELECT COUNT(*) FROM contents WHERE user_id = ? AND created_at >= ?) posts,
            (SELECT COUNT(*) FROM content_designs WHERE user_id = ? AND created_at >= ?) designs,
            (SELECT COUNT(*) FROM campaigns WHERE user_id = ? AND status = "active") active',
        [$uid, $monthStart, $uid, $monthStart, $uid]) ?: [], []);
    $usage = ui_credits_usage($uid);

    // ─── أحدث المحتويات ───
    $recent = dash_safe(fn() => db_all(
        'SELECT c.id, c.content_type, c.generated_text, c.created_at, c.selected_image_id, ' .
        (function_exists('content_status_sql') ? content_status_sql('c') : '"draft"') . ' st,
         COALESCE(
            (SELECT image_path FROM content_designs d WHERE d.id = c.selected_image_id AND d.content_id = c.id),
            (SELECT image_path FROM content_designs d WHERE d.content_id = c.id ORDER BY d.id DESC LIMIT 1)
         ) cover
         FROM contents c WHERE c.user_id = ? ORDER BY c.created_at DESC LIMIT 6', [$uid]), []);

    return [
        'greeting' => $hour < 12 ? 'صباح الخير' : ($hour < 18 ? 'مساء الخير' : 'مساء النور'),
        'first'    => $first,
        'business' => (string) ($brand['business_name'] ?? ''),
        'health'   => $health,
        'showBrand' => (int) ($health['pct'] ?? 0) < $bHide,
        'actions'  => $actions,
        'actionsTotal' => array_sum(array_column($actions, 'n')),
        'journey'  => ['steps' => $journey, 'done' => $jDone, 'total' => count($journey),
                       'pct' => $jPct, 'next' => $jNext, 'show' => $jNext !== null && $jPct < $jHide],
        'campaign' => $campaign,
        'campOn'   => $campOn,
        'month'    => ['posts' => (int) ($month['posts'] ?? 0), 'designs' => (int) ($month['designs'] ?? 0),
                       'active' => (int) ($month['active'] ?? 0), 'usage' => $usage],
        'recent'   => $recent,
        // سلايدر الإعلانات: إعلانات الأدمن (للكل أو لباقة العميل) + الأساسي «تواصل مع خدمة العملاء»
        'slides'   => dash_safe(fn() => ann_slides_for_user($uid), [ann_default_slide()]),
    ];
}
