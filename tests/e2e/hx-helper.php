<?php
/** مساعد اختبار الهيرو (tests/e2e/hx-hero.mjs) — للتطوير المحلي بس: prep · check <email> · clean <email> */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول\n");
require_once __DIR__ . '/../../includes/functions.php';
$cmd = $argv[1] ?? '';
$email = (string) ($argv[2] ?? '');
if ($cmd === 'prep') {
    db_run('DELETE FROM rate_limits WHERE action IN ("trial_analyze", "trial_resume", "register", "login", "login_acct")');
    exit;
}
$u = preg_match('/^hx-[0-9a-f]+@test\.local$/', $email) ? db_one('SELECT id FROM users WHERE email = ?', [$email]) : null;
if ($cmd === 'verify' && $u) {
    db_run('UPDATE users SET email_verified_at = NOW() WHERE id = ?', [$u['id']]);   // زي ما العميل فتح لينك التفعيل
    exit;
}
if ($cmd === 'check') {
    $b = $u ? db_one('SELECT business_name, industry FROM brand_profiles WHERE user_id = ?', [$u['id']]) : null;
    echo json_encode(['user' => (bool) $u, 'business_name' => $b['business_name'] ?? null, 'industry' => $b['industry'] ?? null,
        'claimed' => $u ? (bool) db_one('SELECT id FROM trial_sessions WHERE claimed_user_id = ?', [$u['id']]) : false], JSON_UNESCAPED_UNICODE);
} elseif ($cmd === 'clean' && $u) {
    db_run('DELETE FROM trial_sessions WHERE claimed_user_id = ?', [$u['id']]);
    foreach (['brand_sources', 'brand_kits', 'brand_profiles', 'credit_transactions', 'credit_wallets', 'user_sessions', 'email_verifications'] as $tb) {
        try { db_run("DELETE FROM $tb WHERE user_id = ?", [$u['id']]); } catch (\Throwable $e) {}
    }
    try { db_run('DELETE FROM users WHERE id = ?', [$u['id']]); } catch (\Throwable $e) { echo $e->getMessage(); }
}
if ($cmd === 'clean') {
    db_run('DELETE FROM trial_sessions WHERE business_name LIKE ? AND claimed_user_id IS NULL', ['عيادة الابتسامة %']);
}
