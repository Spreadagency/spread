<?php
require_once __DIR__ . '/../includes/admin-auth.php';

if (is_admin_logged_in()) {
    admin_log('logout', null, null, 'admin logout');
    logout_admin();
}

flash_set('info', 'تم تسجيل الخروج');
redirect('admin/login.php');
