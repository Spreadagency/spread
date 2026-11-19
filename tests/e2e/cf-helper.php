<?php
/** مساعد اختبار التنقّل بين مراحل الحملة (tests/e2e/cf-nav.mjs) — للتطوير المحلي بس: make <email> · check <email> · clean <email> */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول\n");
require_once __DIR__ . '/../../includes/functions.php';
$cmd = $argv[1] ?? '';
$email = (string) ($argv[2] ?? '');
if (!preg_match('/^cf-[0-9a-f]+@test\.local$/', $email)) exit("bad email\n");
$u = db_one('SELECT id FROM users WHERE email = ?', [$email]);
if ($cmd === 'make') {
    db_run('DELETE FROM rate_limits WHERE action IN ("login", "login_acct")');
    $id = (int) db_insert('INSERT INTO users (name,email,phone,password,status,email_verified_at,approval_status,approved_at) VALUES (?,?,?,?,"active",NOW(),"approved",NOW())',
        ['Camp Nav', $email, '015' . random_int(10000000, 99999999), password_hash('Test12345!', PASSWORD_DEFAULT)]);
    $b = (int) db_insert('INSERT INTO brand_profiles (user_id,business_name,industry,brand_approved_at) VALUES (?,?,?,NOW())', [$id, 'محل التجربة', 'ملابس']);
    credits_add($id, 100, 'اختبار التنقّل', null, 'test', null, 30);
    $c = (int) db_insert('INSERT INTO campaigns (user_id,title,ideas_count,period_month,basis,stage,max_stage) VALUES (?,?,?,?,?,2,2)', [$id, 'حملة التنقّل', 3, date('Y-m'), 'services']);
    foreach (['فكرة الشتا', 'فكرة العرض', 'فكرة الجودة'] as $k => $title) {
        $co = (int) db_insert('INSERT INTO contents (user_id,brand_profile_id,content_type,platform,generated_text,status,campaign_id) VALUES (?,?,?,?,?,?,?)',
            [$id, $b, 'introductory', 'both', "منشور $k: لبس الشتا وصل والأسعار حلوة", 'generated', $c]);
        db_insert('INSERT INTO campaign_ideas (campaign_id,user_id,title,selected,content_id,eval_score,approved,sort_order) VALUES (?,?,?,1,?,?,?,?)', [$c, $id, $title, $co, 88, 1, $k]);
    }
    echo $c;
} elseif ($cmd === 'check' && $u) {
    echo json_encode(db_one('SELECT stage, max_stage, prefs_json LIKE "%plan_eval%" AS evaluated FROM campaigns WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$u['id']]));
} elseif ($cmd === 'clean' && $u) {
    $uid = (int) $u['id'];
    foreach (['campaign_ideas', 'campaigns', 'content_designs', 'contents', 'brand_kits', 'brand_profiles', 'credit_lots', 'credit_transactions', 'credit_wallets', 'user_sessions', 'remember_tokens'] as $t) {
        try { db_run("DELETE FROM $t WHERE user_id = ?", [$uid]); } catch (\Throwable $e) {}
    }
    db_run('DELETE FROM users WHERE id = ?', [$uid]);
}
