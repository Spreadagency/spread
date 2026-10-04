<?php
/**
 * Spread AI v2 — «رحلتك الأولى» (المرحلة ⑦): 8 خطوات من الهوية للنشر — بتعمل الفعل الحقيقي
 * القالب: templates/v2/journey.php · الحالة: includes/journey.php + /api/journey.php
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/journey.php';

require_login();
$user = current_user();

if (!function_exists('ui_v2_enabled') || !ui_v2_enabled() || get_setting('campaigns_enabled', '1') !== '1') {
    redirect('dashboard.php');
}
require_once __DIR__ . '/../includes/ui-v2.php';

$jrInit = ['journey' => journey_state((int) $user['id']), 'balance' => credits_balance((int) $user['id'])];

$active = 'journey';
$page_title = 'رحلتك الأولى';
$use_app = true;
$body_class = 'jr-page';
include __DIR__ . '/../templates/header.php';
echo '<div class="app">';
include __DIR__ . '/../templates/sidebar.php';
echo '<main class="main">';
include __DIR__ . '/../templates/topbar.php';
include __DIR__ . '/../templates/v2/journey.php';
echo '</main></div>';
include __DIR__ . '/../templates/footer.php';
