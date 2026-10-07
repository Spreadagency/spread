<?php
/** هيدر الموقع المشترك */
require_once __DIR__ . '/functions.php';
$__logo = s_setting('logo_path');
$__pages = s_menu_pages();
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($__pageTitle ?? s_setting('site_name', 'Spread AI')) ?><?= isset($__pageTitle) ? ' — ' . e(s_setting('site_name', 'Spread AI')) : '' ?></title>
<meta name="description" content="<?= e($__pageDesc ?? s_setting('tagline')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(s_url('site-assets/css/site.css')) ?>?v=<?= @filemtime(dirname(__DIR__) . '/site-assets/css/site.css') ?: time() ?>">
<style>:root{
--blue:<?= e(s_setting('color_blue', '#0F3CC9')) ?>;
--turq:<?= e(s_setting('color_turquoise', '#14B8A6')) ?>;
--sky:<?= e(s_setting('color_sky', '#8AD9F5')) ?>;
--ink:<?= e(s_setting('color_ink', '#0B0B0F')) ?>;
--bg:<?= e(s_setting('color_beige', '#F5F2EC')) ?>;
}</style>
</head>
<body>

<header class="site-header">
  <div class="nav-pill">
    <a href="<?= e(s_url('index.php')) ?>" class="nav-logo">
      <?php if ($__logo): ?>
        <img src="<?= e(SITE_UPLOAD_URL . '/' . $__logo) ?>" alt="<?= e(s_setting('site_name')) ?>">
      <?php else: ?>
        <b><?= e(mb_strtoupper(s_setting('site_name', 'SPREAD AI'))) ?></b>
      <?php endif; ?>
    </a>

    <nav class="nav-links" id="navLinks">
      <a href="<?= e(s_url('index.php')) ?>">الرئيسية</a>
      <?php foreach ($__pages as $pg): ?>
        <a href="<?= e(s_page_url($pg['slug'])) ?>"><?= e($pg['title']) ?></a>
      <?php endforeach; ?>
    </nav>

    <div class="nav-cta">
      <a href="<?= e(s_setting('platform_login_url', PLATFORM_LOGIN)) ?>" class="btn-pill btn-ghost-d">دخول</a>
      <a href="<?= e(s_setting('platform_register_url', PLATFORM_REGISTER)) ?>" class="btn-pill btn-light">
        <span>ابدأ مجانًا</span><span>←</span>
      </a>
      <button class="menu-btn" onclick="document.getElementById('navLinks').classList.toggle('open')" aria-label="القائمة">☰</button>
    </div>
  </div>
</header>
