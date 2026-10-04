<?php
require_once __DIR__ . '/../includes/auth.php';

logout_user();
flash_set('success', 'تم تسجيل خروجك بنجاح');
redirect('login.php');
