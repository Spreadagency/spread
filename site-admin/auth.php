<?php
/** حماية لوحة تحكم الموقع */
require_once dirname(__DIR__) . '/site/functions.php';

function sa_admin(): ?array
{
    if (empty($_SESSION['site_admin_id'])) return null;
    static $a = null;
    if ($a === null) {
        $a = s_one('SELECT * FROM site_admins WHERE id = ? AND status = "active"', [$_SESSION['site_admin_id']]);
    }
    return $a ?: null;
}

function sa_require(): void
{
    if (!sa_admin()) {
        s_redirect('site-admin/login.php');
    }
}

function sa_login(string $email, string $pass): bool
{
    $u = s_one('SELECT * FROM site_admins WHERE email = ? AND status = "active"', [mb_strtolower(trim($email))]);
    if (!$u || !password_verify($pass, $u['password'])) return false;
    session_regenerate_id(true);
    $_SESSION['site_admin_id'] = (int) $u['id'];
    return true;
}
