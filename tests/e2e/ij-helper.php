<?php
/** مساعد اختبار رحلة الهوية (tests/e2e/ij-journey.mjs) — للتطوير المحلي بس: make <email> [phone] · check <email> · clean <email> */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول\n");
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/plans.php';
require_once __DIR__ . '/../../includes/trial.php';
require_once __DIR__ . '/../../includes/onboarding.php';
$cmd = $argv[1] ?? '';
$email = (string) ($argv[2] ?? '');
if (!preg_match('/^ij-[0-9a-f]+@test\.local$/', $email)) exit("bad email\n");
$u = db_one('SELECT id FROM users WHERE email = ?', [$email]);
if ($cmd === 'make') {
    db_run('DELETE FROM rate_limits WHERE action IN ("login", "login_acct", "onb_name", "bk_autofill", "bk_draft", "bk_palette", "bk_personas", "brand_insp", "upload_image", "brand_save")');
    $id = (int) db_insert('INSERT INTO users (name, email, phone, password, status, email_verified_at, approval_status, approved_at) VALUES (?,?,?,?, "active", NOW(), "approved", NOW())',
        ['Mona Test', $email, (string) ($argv[3] ?? ('010' . random_int(10000000, 99999999))), password_hash('Test12345!', PASSWORD_DEFAULT)]);
    db_insert('INSERT INTO brand_profiles (user_id, business_name) VALUES (?, ?)', [$id, 'Mona Test']);
    echo $id;
} elseif ($cmd === 'check' && $u) {
    $uid = (int) $u['id'];
    $bp = db_one('SELECT * FROM brand_profiles WHERE user_id = ?', [$uid]);
    $kit = json_decode((string) (db_one('SELECT data FROM brand_kits WHERE user_id = ?', [$uid])['data'] ?? '{}'), true) ?: [];
    echo json_encode([
        'done' => ij_done($uid), 'approved' => !empty($bp['brand_approved_at']), 'name' => $bp['business_name'], 'description' => $bp['description'],
        'address' => $bp['address'], 'phones' => $bp['phones'], 'whatsapp' => $bp['whatsapp'], 'hours' => $bp['working_hours'],
        'instagram' => $bp['social_instagram'], 'logo' => (bool) $bp['logo_path'],
        'services' => array_column(db_all('SELECT name FROM brand_services WHERE user_id = ?', [$uid]), 'name'),
        'personas' => (int) (db_one('SELECT COUNT(*) n FROM brand_personas WHERE user_id = ?', [$uid])['n'] ?? 0),
        'characters' => array_column(db_all('SELECT name FROM brand_characters WHERE user_id = ? AND consent_confirmed = 1', [$uid]), 'name'),
        'char_images' => (int) (db_one('SELECT COUNT(*) n FROM brand_character_images i JOIN brand_characters c ON c.id = i.character_id WHERE c.user_id = ?', [$uid])['n'] ?? 0),
        'insp' => (int) (db_one('SELECT COUNT(*) n FROM brand_inspirations WHERE brand_profile_id = ?', [$bp['id']])['n'] ?? 0),
        'colors' => $kit['colors']['primary'] ?? '', 'style' => $kit['design_style']['notes'] ?? '', 'voice' => $kit['voice']['sample_lines'][0] ?? '',
        'trial' => trial_status($uid), 'contents' => (int) (db_one('SELECT COUNT(*) n FROM contents WHERE user_id = ?', [$uid])['n'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
} elseif ($cmd === 'clean' && $u) {
    $uid = (int) $u['id'];
    $bp = db_one('SELECT id FROM brand_profiles WHERE user_id = ?', [$uid]);
    if ($bp) foreach (['brand_inspirations', 'brand_sources', 'brand_facts'] as $tb) { try { db_run("DELETE FROM $tb WHERE brand_profile_id = ?", [$bp['id']]); } catch (\Throwable $e) {} }
    try { db_run('DELETE i FROM brand_character_images i JOIN brand_characters c ON c.id = i.character_id WHERE c.user_id = ?', [$uid]); } catch (\Throwable $e) {}
    foreach (['brand_characters', 'brand_personas', 'brand_topics', 'brand_services', 'contents', 'user_plans', 'brand_kit_versions', 'brand_kits', 'brand_profiles',
              'credit_lots', 'credit_transactions', 'credit_wallets', 'user_sessions', 'remember_tokens'] as $tb) {
        try { $tb === 'brand_kit_versions' ? db_run('DELETE v FROM brand_kit_versions v JOIN brand_kits k ON k.id = v.brand_kit_id WHERE k.user_id = ?', [$uid]) : db_run("DELETE FROM $tb WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}
    }
    db_run('DELETE FROM users WHERE id = ?', [$uid]);
}
