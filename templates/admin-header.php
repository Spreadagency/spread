<?php
/**
 * Spread AI — Admin Header (المرحلة 8 · تصميم PlatformAdmin)
 */
$page_title = $page_title ?? 'لوحة الأدمن';
$__a2v = static fn(string $f) => @filemtime(__DIR__ . '/../' . $f) ?: time();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($page_title) ?> — أدمن <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Readex+Pro:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('assets/css/main.css') ?>?v=<?= $__a2v('assets/css/main.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/admin-v2.css') ?>?v=<?= $__a2v('assets/css/admin-v2.css') ?>">
    <script>try{if(localStorage.getItem('a2-mini')==='1')document.documentElement.classList.add('a2-mini-pre')}catch(e){}</script>
</head>
<body class="adm2">
<script>if(document.documentElement.classList.contains('a2-mini-pre'))document.body.classList.add('a2-mini')</script>
