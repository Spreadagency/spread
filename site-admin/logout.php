<?php
require_once __DIR__ . '/auth.php';
unset($_SESSION['site_admin_id']);
s_redirect('site-admin/login.php');
