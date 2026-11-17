<?php
/** مساعد اختبار «اللوجو والتصميمات من الصفحة» (tests/e2e/pa-journey.mjs) — للتطوير المحلي بس: make <email> <base> · check <email> · clean <email> */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول\n");
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/social.php';
$cmd = $argv[1] ?? '';
$email = (string) ($argv[2] ?? '');
if (!preg_match('/^pa-[0-9a-f]+@test\.local$/', $email)) exit("bad email\n");
$stateFile = sys_get_temp_dir() . '/spread-mock-graph.json';
$u = db_one('SELECT id FROM users WHERE email = ?', [$email]);
if ($cmd === 'make') {
    $base = rtrim((string) ($argv[3] ?? 'http://localhost:8080'), '/');
    db_run('DELETE FROM rate_limits WHERE action IN ("login", "login_acct", "onb_name", "bk_autofill", "bk_draft", "bk_palette", "bk_personas", "bk_page_assets", "brand_insp", "upload_image")');
    $saved = get_setting('graph_base_url', null);
    file_put_contents($stateFile . '.saved', json_encode(['graph_base_url' => $saved]));
    set_setting('graph_base_url', 'http://127.0.0.1:8093/v21.0');
    $id = (int) db_insert('INSERT INTO users (name, email, phone, password, status, email_verified_at, approval_status, approved_at) VALUES (?,?,?,?, "active", NOW(), "approved", NOW())',
        ['Page Test', $email, '010' . random_int(10000000, 99999999), password_hash('Test12345!', PASSWORD_DEFAULT)]);
    db_insert('INSERT INTO brand_profiles (user_id, business_name) VALUES (?, ?)', [$id, 'Page Test']);
    db_insert('INSERT INTO social_connections (user_id, platform, provider_page_id, page_name, ig_user_id, ig_username, access_token_enc, token_type, scopes, status, last_verified_at)
               VALUES (?, "facebook", "7771", "صفحة الاختبار", "7772", "test_ig", ?, "page", "pages_read_engagement", "active", NOW())', [$id, social_token_encrypt('tok_ok')]);
    // صور بيفتحها المتصفح (من سيرفر التطوير) — التحميل من السيرفر نفسه ممنوع (عنوان داخلي) وده بيختبر رسالة «ارفعه من جهازك»
    $img = $base . '/assets/img/';
    file_put_contents($stateFile, json_encode([
        'pictures' => ['7771' => $img . 'spread-mark-128.png'],
        'ig_pictures' => ['7772' => $img . 'favicon-v2.png'],
        'pages' => ['7771' => [['id' => '7771_1', 'message' => 'a', 'created_time' => '2026-11-01T10:00:00+0000', 'full_picture' => $img . 'robot.png'],
                               ['id' => '7771_2', 'message' => 'b', 'created_time' => '2026-11-02T10:00:00+0000', 'full_picture' => $img . 'spread-mark.png']]],
        'ig' => ['7772' => [['id' => 'i1', 'caption' => 'x', 'timestamp' => '2026-11-01T10:00:00+0000', 'media_type' => 'IMAGE', 'media_url' => $img . 'robot.webp']]],
    ]));
    echo $id;
} elseif ($cmd === 'check' && $u) {
    $bp = db_one('SELECT logo_path, id FROM brand_profiles WHERE user_id = ?', [$u['id']]);
    echo json_encode(['logo' => (string) ($bp['logo_path'] ?? ''), 'insp' => (int) (db_one('SELECT COUNT(*) n FROM brand_inspirations WHERE brand_profile_id = ?', [$bp['id']])['n'] ?? 0)]);
} elseif ($cmd === 'clean') {
    if ($u) {
        $uid = (int) $u['id'];
        $bp = db_one('SELECT id FROM brand_profiles WHERE user_id = ?', [$uid]);
        if ($bp) foreach (['brand_inspirations', 'brand_sources', 'brand_facts'] as $tb) { try { db_run("DELETE FROM $tb WHERE brand_profile_id = ?", [$bp['id']]); } catch (\Throwable $e) {} }
        foreach (['social_connections', 'brand_personas', 'brand_services', 'contents', 'user_plans', 'brand_kits', 'brand_profiles', 'credit_lots', 'credit_transactions', 'credit_wallets', 'user_sessions', 'remember_tokens'] as $tb) {
            try { db_run("DELETE FROM $tb WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}
        }
        db_run('DELETE FROM users WHERE id = ?', [$uid]);
    }
    $s = json_decode((string) @file_get_contents($stateFile . '.saved'), true) ?: [];
    if (($s['graph_base_url'] ?? null) === null) db_run('DELETE FROM settings WHERE setting_key = "graph_base_url"'); else set_setting('graph_base_url', (string) $s['graph_base_url']);
    @unlink($stateFile);
    @unlink($stateFile . '.saved');
}
