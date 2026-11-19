<?php
/**
 * مساعد اختبار «ابدأ» (tests/e2e/ob1-start.mjs) — للتطوير المحلي بس.
 *   on                 يشغّل البداية السريعة ويطبع الإعدادات القديمة (JSON)
 *   count <email>      عدد منشورات الحساب
 *   off <json> <email> يرجّع الإعدادات ويمسح حساب الاختبار
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول\n");
require_once __DIR__ . '/../../includes/functions.php';

$keys = ['onboarding_quick', 'manual_approval'];
$cmd = $argv[1] ?? '';
if ($cmd === 'on') {
    $old = [];
    foreach ($keys as $k) $old[$k] = db_one('SELECT setting_value v FROM settings WHERE setting_key = ?', [$k])['v'] ?? null;
    set_setting('onboarding_quick', '1');
    set_setting('manual_approval', '0');
    db_run('DELETE FROM rate_limits WHERE action IN ("register", "onb_name", "bk_autofill", "bk_draft", "generate")');
    echo json_encode($old);
} elseif ($cmd === 'count') {
    $u = db_one('SELECT id FROM users WHERE email = ?', [(string) ($argv[2] ?? '')]);
    echo $u ? (int) (db_one('SELECT COUNT(*) n FROM contents WHERE user_id = ?', [$u['id']])['n'] ?? 0) : -1;
} elseif ($cmd === 'off') {
    $old = json_decode((string) ($argv[2] ?? '{}'), true) ?: [];
    foreach ($keys as $k) {
        db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
        if (($old[$k] ?? null) !== null) set_setting($k, (string) $old[$k]);
    }
    $email = (string) ($argv[3] ?? '');
    $u = preg_match('/^ob1e-[0-9a-f]+@test\.local$/', $email) ? db_one('SELECT id FROM users WHERE email = ?', [$email]) : null;
    if ($u) {
        foreach (['contents', 'brand_services', 'brand_topics', 'brand_kits', 'brand_profiles', 'credit_transactions', 'credit_wallets'] as $tb) {
            try { db_run("DELETE FROM $tb WHERE user_id = ?", [$u['id']]); } catch (\Throwable $e) {}
        }
        try { db_run('DELETE FROM users WHERE id = ?', [$u['id']]); } catch (\Throwable $e) {}
    }
}
