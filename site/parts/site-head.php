<?php
/**
 * <head> الموحّد — العنوان · الوصف · Open Graph · canonical · robots · الخطوط · الـ CSS · الهوية البصرية · التتبّع
 * المتوقع: $meta = [title, description, og_title, og_description, og_image, canonical, noindex] · $logo · $extraCss (اختياري)
 */
$__v = fn(string $f) => @filemtime(dirname(__DIR__, 2) . '/site-assets/' . $f) ?: 1;
$__noindex = !empty($meta['noindex']) || s_setting('seo_noindex_site', '0') === '1';
$__og = (string) ($meta['og_image'] ?? '') ?: (s_setting('seo_og_image') ?: s_url('site-assets/img/bot-wave.webp'));
if ($__og !== '' && $__og[0] === '/') $__og = rtrim(SITE_URL, '/') . $__og;
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($meta['title']) ?></title>
<meta name="description" content="<?= e($meta['description'] ?? '') ?>">
<?php if ($__noindex): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<?php if (!empty($meta['canonical'])): ?><link rel="canonical" href="<?= e($meta['canonical']) ?>"><?php endif; ?>
<meta name="theme-color" content="#0B1526">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e(s_setting('site_name', 'Spread AI')) ?>">
<meta property="og:title" content="<?= e(($meta['og_title'] ?? '') ?: $meta['title']) ?>">
<meta property="og:description" content="<?= e(($meta['og_description'] ?? '') ?: ($meta['description'] ?? '')) ?>">
<meta property="og:image" content="<?= e($__og) ?>">
<?php if (!empty($meta['canonical'])): ?><meta property="og:url" content="<?= e($meta['canonical']) ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<?php if ($gv = s_setting('seo_gsc_verify')): ?><meta name="google-site-verification" content="<?= e($gv) ?>"><?php endif; ?>
<link rel="icon" href="<?= e($logo) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Readex+Pro:wght@400;500;600;700&display=swap" rel="stylesheet">
<?= $preload ?? '' ?>
<link rel="stylesheet" href="<?= e(s_url('site-assets/css/home-v2.css')) ?>?v=<?= $__v('css/home-v2.css') ?>">
<link rel="stylesheet" href="<?= e(s_url('site-assets/css/pages.css')) ?>?v=<?= $__v('css/pages.css') ?>">
<?= s_design_css() ?>
<?php if (($ga = s_setting('seo_ga_id')) && preg_match('/^G-[A-Z0-9]{4,16}$/i', $ga) && !$__noindex): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($ga) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','<?= e($ga) ?>');</script>
<?php endif; ?>
</head>
