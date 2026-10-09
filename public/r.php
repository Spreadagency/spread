<?php
declare(strict_types=1);

/**
 * Shared result page: /r/{token}
 * Shows the "after" image (or before/after when enabled), first name only,
 * never the phone. Not indexed. OG image = branded 1200×630 card.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

send_page_headers();
header('X-Robots-Tag: noindex, nofollow');

$token = (string) ($_GET['t'] ?? '');
$gen = preg_match('/^[a-f0-9]{32}$/', $token)
    ? q_row("SELECT g.*, l.name FROM generations g JOIN leads l ON l.id = g.lead_id WHERE g.share_token = ? AND g.status = 'done' AND g.result_path IS NOT NULL", [$token])
    : null;

$tryUrl = url('?' . http_build_query(['utm_source' => 'share', 'utm_medium' => 'referral', 'utm_campaign' => 'shared_result']));
$doctorName = (string) Settings::get('doctor_name');
$showBefore = $gen && Settings::bool('share_show_before') && $gen['original_path'] && is_file(ORIGINALS_PATH . '/' . $gen['original_path']);
$resultExists = $gen && is_file(RESULTS_PATH . '/' . $gen['result_path']);

if (!$resultExists) {
    http_response_code(404);
}
$firstName = $gen ? first_name((string) $gen['name']) : '';
$vars = ['{name}' => $firstName, '{doctor}' => $doctorName];
$title = $resultExists ? t('t_sharepage_og', $vars) : (string) Settings::get('og_title', 'شوف نفسك بعد التخسيس');
$ogImage = $resultExists ? shared_image_url($token, 'og') : (Settings::get('og_image', '') ? url((string) Settings::get('og_image')) : url((string) Settings::get('doctor_photo')));
$S = Settings::all();
$pageViewEventId = new_event_id();
if ($gen) {
    log_event('page_view', null, (int) $gen['id'], $pageViewEventId, ['page' => 'share']);
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e(t('t_sharepage_og_desc', $vars)) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="<?= e(Settings::get('locale', 'ar_EG')) ?>">
<meta property="og:url" content="<?= e($resultExists ? share_url($token) : url()) ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e(t('t_sharepage_og_desc', $vars)) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="<?= e(asset((string) Settings::get('logo'))) ?>">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('assets/css/site.css')) ?>">
<style>:root{--primary:<?= css_color('color_primary', '#1F73B7') ?>;--ink:<?= css_color('color_secondary', '#0E2B45') ?>}
.share-wrap{max-width:560px;margin:0 auto;padding:24px 20px 56px;display:flex;flex-direction:column;gap:20px;text-align:center}
.share-img{border-radius:24px;overflow:hidden;background:var(--sky-100);aspect-ratio:4/5;position:relative}
.share-img img{width:100%;height:100%;object-fit:cover}
.share-img .ba-label{z-index:2}
.share-two{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.share-two .share-img{aspect-ratio:3/4}</style>
<?php require APP_PATH . '/views/partials/tracking_head.php'; ?>
</head>
<body>
<?php require APP_PATH . '/views/partials/icons.php'; ?>
<header class="site-header"><div class="container" style="justify-content:center"><a href="<?= e($tryUrl) ?>" class="logo"><img src="<?= e(asset((string) Settings::get('logo'))) ?>" alt="<?= e($doctorName) ?>" width="152" height="76"></a></div></header>
<main class="share-wrap">
<?php if ($resultExists): ?>
  <?php if (t('t_sharepage_chip') !== ''): ?><span class="chip" style="align-self:center"><svg class="ic sm"><use href="#i-spark"/></svg><?= e(t('t_sharepage_chip')) ?></span><?php endif; ?>
  <h1 class="h2"><?= e(t('t_sharepage_title', $vars)) ?></h1>
  <?php if ($showBefore): ?>
  <div class="share-two">
    <div class="share-img"><span class="ba-label b"><?= e(t('t_label_before')) ?></span><img src="<?= e(shared_image_url($token, 'before')) ?>" alt="<?= e(t('t_label_before')) ?>"></div>
    <div class="share-img"><span class="ba-label a"><?= e(t('t_label_after')) ?></span><img src="<?= e(shared_image_url($token, 'after')) ?>" alt="<?= e(t('t_label_after')) ?>"></div>
  </div>
  <?php else: ?>
  <div class="card stage"><div class="share-img"><span class="ba-label a"><?= e(t('t_label_after')) ?></span><img src="<?= e(shared_image_url($token, 'after')) ?>" alt="<?= e(t('t_label_after')) ?>"></div></div>
  <?php endif; ?>
  <p class="alert info" style="text-align:right"><svg class="ic sm"><use href="#i-info"/></svg><?= e(t('disclaimer_text')) ?></p>
<?php else: ?>
  <h1 class="h2"><?= e(t('t_sharepage_expired_title')) ?></h1>
  <p class="lead"><?= e(t('t_sharepage_expired_text')) ?></p>
<?php endif; ?>
  <a href="<?= e($tryUrl) ?>" class="btn btn-primary btn-lg btn-block"><svg class="ic"><use href="#i-spark"/></svg><?= e(t('t_sharepage_try')) ?></a>
  <p class="muted small"><?= e($doctorName) ?> — <?= e(Settings::get('doctor_title')) ?></p>
  <p class="small muted made-by" style="justify-content:center">صنعت بواسطة <a href="https://spreadagency.net" target="_blank" rel="noopener">Spread</a></p>
</main>
</body>
</html>
