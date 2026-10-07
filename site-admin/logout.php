<?php
require_once __DIR__ . '/auth.php';
if (sa_admin()) sa_log('logout', 'system', 'تسجيل خروج من اللوحة');
unset($_SESSION['site_admin_id']);
s_redirect('site-admin/login.php');
