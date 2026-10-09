<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_valid($_POST['csrf'] ?? null) && AdminAuth::user()) {
    admin_log('logout');
    AdminAuth::logout();
}
redirect('login.php');
