<?php
/**
 * Spread AI v2 — الحملة (6 مراحل) — كل حاجة جوه الشاشة وبالجملة (المرحلة ⑥-ب)
 * القالب: templates/v2/campaign.php · الداتا: includes/campaign-flow.php + /api/campaign-flow.php
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/credits.php';
require_once __DIR__ . '/../includes/lifecycle.php';
require_once __DIR__ . '/../includes/brand-brain.php';

require_login();
$user = current_user();

if (!ui_v2_enabled() || get_setting('campaigns_enabled', '1') !== '1') {
    flash_set('info', 'الحملات جزء من الشكل الجديد — فعّله من ملفك الشخصي ✨');
    redirect('profile.php');
}

$c = db_one('SELECT * FROM campaigns WHERE id = ? AND user_id = ?', [(int) ($_GET['id'] ?? 0), (int) $user['id']]);
if (!$c) {
    flash_set('warning', 'الحملة دي مش موجودة');
    redirect('campaigns.php');
}

// فتح مرحلة معيّنة من الرابط (مثلًا الرجوع من إنشاء منشور)
$reqStage = (int) ($_GET['stage'] ?? 0);
if ($reqStage >= 1 && $reqStage <= min(6, (int) $c['max_stage'] + 1) && $reqStage !== (int) $c['stage']) {
    db_run('UPDATE campaigns SET stage = ?, max_stage = GREATEST(max_stage, ?) WHERE id = ?', [$reqStage, $reqStage, $c['id']]);
    $c = db_one('SELECT * FROM campaigns WHERE id = ?', [$c['id']]);
}

// ⑥-ب: الحملة كاملة جوه الشاشة — الأفكار ← المحتوى ← التقييم ← التصميم ← الجدولة ← النشر (جماعي)
require_once __DIR__ . '/../includes/campaign-flow.php';
require_once __DIR__ . '/../includes/ui-v2.php';
$cfInit = campaign_board_payload($c, (int) $user['id']);

$active = 'campaigns';
$page_title = $c['title'];
$use_app = true;
$body_class = 'cf-page';
$__no_tabbar = true;   // شريط التنقل بتاع الحملة تحت بدل التاب بار
include __DIR__ . '/../templates/header.php';
include __DIR__ . '/../templates/v2/campaign.php';
include __DIR__ . '/../templates/footer.php';
