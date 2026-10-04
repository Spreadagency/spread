<?php
/** بدء تسجيل الدخول بجوجل */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/google-auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

if (!google_enabled()) {
    flash_set('danger', 'تسجيل الدخول بجوجل مش مفعّل حاليًا');
    redirect('login.php');
}

$ip = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
if (!rate_limit('google_start', 'ip' . $ip, 12, 300)) {
    flash_set('danger', 'محاولات كتير — استنى شوية');
    redirect('login.php');
}

$intent = ($_GET['intent'] ?? 'login') === 'register' ? 'register' : 'login';
$ref = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['ref'] ?? '')));

header('Location: ' . google_auth_url($intent, $ref));
exit;
