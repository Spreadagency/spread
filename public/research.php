<?php
/**
 * Spread AI v2 — البحث العميق (المرحلة ⑦-ب)
 * سؤال ← نطاق ← خطة ← تنفيذ على خطوات (بحث ويب حقيقي) ← نتائج بمصادر ← Brand Brain · تقرير HTML
 * القالب: templates/v2/research.php · المنطق: includes/research.php + /api/research.php
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/research.php';
require_once __DIR__ . '/../includes/brand-brain.php';

require_login();
$user = current_user();
$uid = (int) $user['id'];

if (!function_exists('ui_v2_enabled') || !ui_v2_enabled() || get_setting('research_enabled', '1') !== '1') {
    redirect('dashboard.php');
}
require_once __DIR__ . '/../includes/ui-v2.php';

$brand = brand_for_user($uid);
$engine = research_engine();
$open = null;
if (!empty($_GET['id'])) {
    $r = research_row((int) $_GET['id'], $uid);
    if ($r) $open = research_to_api($r);
}
$depths = [];
foreach (research_depths() as $k => [$name]) $depths[] = ['k' => $k, 't' => $name, 'cost' => research_cost($k)];
$markets = [];
foreach (research_markets() as $name => [, $cities]) $markets[] = ['t' => $name, 'cities' => $cities];
$types = [];
foreach (research_types() as $k => [$e, $t, $d]) $types[] = ['k' => $k, 'e' => $e, 't' => $t, 'd' => $d];
$kinds = [];
foreach (research_src_kinds() as $k => [$e, $t]) $kinds[] = ['k' => $k, 'e' => $e, 't' => $t];

$rsInit = [
    'open' => $open,
    'count' => (int) (db_one('SELECT COUNT(*) n FROM researches WHERE user_id = ? AND status <> "draft"', [$uid])['n'] ?? 0),
    'brand' => ['name' => trim((string) ($brand['business_name'] ?? '')) ?: 'مشروعك', 'industry' => trim((string) ($brand['industry'] ?? '')) ?: 'نشاطك', 'has' => (bool) $brand],
    'scope' => research_default_scope($brand),
    'types' => $types, 'markets' => $markets, 'periods' => research_periods(), 'langs' => research_langs(),
    'depths' => $depths, 'kinds' => $kinds, 'cats' => research_insight_cats(),
    'engine' => ['ok' => $engine['ok'], 'msg' => $engine['msg']],
    'balance' => credits_balance($uid),
];

$active = 'research';
$page_title = 'البحث العميق';
$use_app = true;
$body_class = 'rs-page';
include __DIR__ . '/../templates/header.php';
echo '<div class="app">';
include __DIR__ . '/../templates/sidebar.php';
echo '<main class="main">';
include __DIR__ . '/../templates/topbar.php';
include __DIR__ . '/../templates/v2/research.php';
echo '</main></div>';
include __DIR__ . '/../templates/footer.php';
