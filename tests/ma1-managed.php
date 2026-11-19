<?php
/**
 * Spread AI — أساس إدارة الحسابات (خريطة التطوير · المرحلة 2)
 * للتطوير المحلي بس — بيعمل عميل وأدمن مؤقتين ويمسحهم، وبيرجّع الإعدادات زي ما كانت.
 *
 *   php tests/ma1-managed.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/managed-clients.php';
require_once __DIR__ . '/../includes/managed-accounts.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, $post = [], ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $raw = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$c, substr($raw, $hs), substr($raw, 0, $hs)];
}
function loc(string $h): string { return preg_match('/^Location:\s*(\S+)/mi', $h, $m) ? $m[1] : ''; }
function csrf_of(string $html): string
{
    if (preg_match('/name="csrf" value="([^"]+)"/', $html, $m)) return $m[1];
    return preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : '';
}
function admin_login(string $base, string $email, string $jar): void
{
    [, $h] = http('GET', "$base/admin/login.php", [], $jar);
    http('POST', "$base/admin/login.php", ['csrf' => csrf_of($h), 'email' => $email, 'password' => 'Test12345!'], $jar);
}
function content_row(int $id): array { return db_one('SELECT * FROM contents WHERE id = ?', [$id]) ?: []; }
/** التعليم باسم الفريق بيحصل بعد ما الرد يتبعت (shutdown) — نستناه لحظة */
function stamped_row(int $id): array
{
    for ($i = 0; $i < 30; $i++) {
        $r = content_row($id);
        if (!$r || !empty($r['acted_by_admin_id'])) return $r;
        usleep(100000);
    }
    return content_row($id);
}

if (!ma_ready()) exit("شغّل ترحيل 2026_managed_accounts.sql الأول (php tests/dev-setup.php)\n");
$savedMc = db_one('SELECT setting_value v FROM settings WHERE setting_key = "managed_client_ids"')['v'] ?? null;
$savedImp = db_one('SELECT setting_value v FROM settings WHERE setting_key = "managed_accounts_imported"')['v'] ?? null;
db_run('DELETE FROM rate_limits WHERE action IN ("login", "admin_login", "generate", "ma_token", "ma_decide")');

$tag = bin2hex(random_bytes(3));
$mkUser = function (string $n) use ($tag): int {
    $uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at, phone) VALUES (?,?,?, "active", NOW(), "approved", NOW(), ?)',
        ["MA1 $n $tag", "ma1-$n-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT), '010' . random_int(10000000, 99999999)]);
    db_insert('INSERT INTO brand_profiles (user_id, business_name, industry) VALUES (?,?,?)', [$uid, "براند MA1 $n", 'عيادة أسنان']);
    db_run('INSERT INTO credit_wallets (user_id, balance) VALUES (?, 60)', [$uid]);
    return $uid;
};
$uid = $mkUser('a');
$other = $mkUser('b');
$asst = (int) db_insert('INSERT INTO admin_users (name, email, password, role, status) VALUES (?,?,?, "assistant", "active")', ["MA1 مساعد $tag", "ma1-asst-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT)]);
$superId = (int) db_one('SELECT id FROM admin_users WHERE email = "owner@app.test"')['id'];
$jar = tempnam(sys_get_temp_dir(), 'ma'); $jarA = tempnam(sys_get_temp_dir(), 'ma'); $jarC = tempnam(sys_get_temp_dir(), 'ma'); $jarT = tempnam(sys_get_temp_dir(), 'ma');
admin_login($base, 'owner@app.test', $jar);

/* ─── 1) العلامة ⭐ ← الجدول ─── */
echo "الحساب المُدار\n";
db_run('DELETE FROM settings WHERE setting_key = "managed_accounts_imported"');
set_setting('managed_client_ids', json_encode([$other]));
ma_ready(true);
t('علامات ⭐ القديمة اتنقلت للجدول (مرة واحدة)', (bool) ma_get($other) && get_setting('managed_accounts_imported') === '1');
[, $h] = http('GET', "$base/admin/users.php", [], $jar);
http('POST', "$base/admin/users.php", ['csrf' => csrf_of($h), 'action' => 'toggle_managed', 'uid' => $uid], $jar);
t('☆ من صفحة العملاء بتعمل حساب مُدار', (bool) ma_get($uid) && in_array($uid, mc_ids(true), true));
[$c, $h] = http('GET', "$base/admin/users.php?managed=1", [], $jar);
t('فلتر «بنديرهم» القديم لسه شغال', $c === 200 && str_contains($h, 'user-view.php?id=' . $uid . '"'));
[$c, $h] = http('GET', "$base/admin/my-accounts.php?all=1", [], $jar);
t('«حساباتي» بتعرض الكارت ولينك مساحة العميل', $c === 200 && str_contains($h, 'workspace.php?id=' . $uid), (string) $c);
[$c, $h] = http('GET', "$base/admin/workspace.php?id=$uid", [], $jar);
$csrf = csrf_of($h);
t('مساحة العميل بتفتح بـ 5 تبويبات', $c === 200 && substr_count($h, 'role="tab"') === 5, (string) $c);
http('POST', "$base/admin/workspace.php", ['csrf' => $csrf, 'id' => $uid, 'action' => 'save_settings', 'manager_admin_id' => $superId, 'service_level' => 'approval', 'monthly_posts' => 8], $jar);
$m = ma_get($uid);
t('تعيين المسؤول ومستوى الخدمة والمطلوب في الشهر', (int) $m['manager_admin_id'] === $superId && $m['service_level'] === 'approval' && (int) $m['monthly_posts'] === 8, json_encode($m));
[$c] = http('POST', "$base/admin/workspace.php", ['id' => $uid, 'action' => 'save_settings', 'monthly_posts' => 99], $jar);
t('من غير CSRF مرفوض', $c === 403 && (int) ma_get($uid)['monthly_posts'] === 8, (string) $c);
[$c, , $hd] = http('GET', "$base/admin/workspace.php?id=" . (int) db_one('SELECT id FROM users WHERE id NOT IN (SELECT user_id FROM managed_accounts) ORDER BY id LIMIT 1')['id'], [], $jar);
t('عميل مش مُدار ← رجوع لملفه', $c === 302 && str_contains(loc($hd), 'user-view.php'), loc($hd));

/* ─── 2) صلاحية المساعد ─── */
echo "الصلاحيات\n";
admin_login($base, "ma1-asst-$tag@test.local", $jarA);
[$c] = http('GET', "$base/admin/workspace.php?id=$uid", [], $jarA);
t('مساعد مش مسؤول الحساب ← 403', $c === 403, (string) $c);
[$c, $h] = http('GET', "$base/admin/my-accounts.php?all=1", [], $jarA);
t('المساعد مايشوفش حسابات غيره حتى بـ all=1', $c === 200 && !str_contains($h, 'workspace.php?id=' . $uid));
ma_update($other, $asst, 'auto', 0);
[$c, $h] = http('GET', "$base/admin/workspace.php?id=$other", [], $jarA);
t('المساعد بيفتح حسابه', $c === 200, (string) $c);
http('POST', "$base/admin/workspace.php", ['csrf' => csrf_of($h), 'id' => $other, 'action' => 'save_settings', 'manager_admin_id' => 0, 'service_level' => 'approval', 'monthly_posts' => 50], $jarA);
t('المساعد مايقدرش يغيّر المسؤول أو مستوى الخدمة', (int) ma_get($other)['manager_admin_id'] === $asst && ma_get($other)['service_level'] === 'auto');

/* ─── 3) الفريق بيعمل منشور باسم العميل ─── */
echo "اتعمل بواسطة الفريق + الموافقة\n";
[$c, , $hd] = http('GET', "$base/admin/impersonate.php?user_id=$uid", [], $jar);
t('الدخول بحساب العميل زي ما هو', $c === 302 && str_contains(loc($hd), 'dashboard.php'), loc($hd));
[, $h] = http('GET', "$base/public/create-content.php", [], $jar);
$ccsrf = csrf_of($h);
[$c, $b] = http('POST', "$base/public/ajax/generate-content.php", ['csrf' => $ccsrf, 'content_type' => 'introductory', 'platform' => 'both', 'length' => 'medium', 'dialect' => 'egyptian'], $jar);
$cid = (int) (json_decode($b, true)['content_id'] ?? 0);
$row = stamped_row($cid);
t('المنشور اتعلّم باسم الأدمن ودخل مسار الموافقة (draft)', $cid > 0 && (int) ($row['acted_by_admin_id'] ?? 0) === $superId && ($row['approval_status'] ?? '') === 'draft', substr($b, 0, 160) . ' ' . json_encode([$row['acted_by_admin_id'] ?? null, $row['approval_status'] ?? null]));
[$c, $b] = http('POST', "$base/public/ajax/schedule-content.php", ['csrf' => $ccsrf, 'content_id' => $cid, 'scheduled_at' => date('Y-m-d H:i', time() + 86400), 'publish_platform' => 'facebook'], $jar);
t('الجدولة قبل موافقة العميل مرفوضة', (json_decode($b, true)['code'] ?? '') === 'approval', substr($b, 0, 160));
[$c, $b] = http('POST', "$base/public/ajax/publish-direct.php", ['csrf' => $ccsrf, 'content_id' => $cid, 'connection_id' => 1], $jar);
t('النشر المباشر قبل الموافقة مرفوض', in_array(json_decode($b, true)['code'] ?? '', ['approval'], true) || str_contains($b, 'غير مفعّلة'), substr($b, 0, 160));
// منشور قديم للعميل نفسه (بره مسار الموافقة) — الفريق مايقدرش ينشره من غير موافقة برضه
$old = (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, status) VALUES (?,?,?,?,?,?)', [$uid, (int) db_one('SELECT id FROM brand_profiles WHERE user_id = ?', [$uid])['id'], 'introductory', 'both', 'منشور قديم للعميل', 'generated']);
$_SESSION = ['impersonator_admin_id' => $superId, 'user_id' => $uid];
t('الفريق مايجدولش منشور العميل من غير موافقة (حساب بموافقة)', ma_publish_block(content_row($old)) !== null);
$_SESSION = [];
t('والعميل نفسه بينشر منشوره عادي', ma_publish_block(content_row($old)) === null);
http('GET', "$base/admin/impersonate.php?stop=1", [], $jar);
admin_login($base, 'owner@app.test', $jar);

[, $h] = http('GET', "$base/admin/workspace.php?id=$uid", [], $jar);
t('المساحة بتعرض المنشور بحالة «لسه ماتبعتش» ولينك الموافقة', str_contains($h, 'لسه ماتبعتش') && str_contains($h, 'approvals.php?t='));
http('POST', "$base/admin/workspace.php", ['csrf' => csrf_of($h), 'id' => $uid, 'action' => 'send_approval', 'ids' => [$cid, $old, 999999]], $jar);
t('«ابعت للموافقة» ← pending (للمنشورين)', content_row($cid)['approval_status'] === 'pending' && content_row($old)['approval_status'] === 'pending');
t('العميل وصله إشعار جوه المنصة', (bool) db_one('SELECT id FROM user_notifications WHERE user_id = ? AND kind = "approval_request"', [$uid]));
t('شرط الـ cron بيستبعد اللي مستني موافقة', (int) db_one('SELECT COUNT(*) n FROM contents WHERE id IN (?, ?)' . ma_publish_sql(), [$cid, $old])['n'] === 0);

/* ─── 4) العميل بيوافق ─── */
echo "موافقة العميل\n";
$tok = ma_token($uid);
[$c, $h] = http('GET', "$base/public/approvals.php?t=$tok", [], $jarT);
t('لينك الموافقة بيفتح من غير دخول وفيه المنشورين', $c === 200 && str_contains($h, 'id="c' . $cid . '"') && str_contains($h, 'id="c' . $old . '"'), (string) $c);
[$c, , $hd] = http('GET', "$base/public/dashboard.php", [], $jarT);
t('اللينك مابيدخّلش الحساب (لوحة التحكم ← دخول)', $c === 302 && str_contains(loc($hd), 'login.php'), loc($hd));
[$c] = http('POST', "$base/public/approvals.php", ['t' => $tok, 'content_id' => $cid, 'decision' => 'approve'], $jarT);
t('موافقة من غير CSRF مرفوضة', $c === 403 && content_row($cid)['approval_status'] === 'pending', (string) $c);
$tcsrf = csrf_of($h);
http('POST', "$base/public/approvals.php", ['csrf' => $tcsrf, 't' => $tok, 'content_id' => $cid, 'decision' => 'approve'], $jarT);
t('«موافق» ← approved', content_row($cid)['approval_status'] === 'approved' && !empty(content_row($cid)['approval_at']));
http('POST', "$base/public/approvals.php", ['csrf' => $tcsrf, 't' => $tok, 'content_id' => $old, 'decision' => 'changes', 'note' => ''], $jarT);
t('«عايز تعديل» من غير نص مابيتحفظش', content_row($old)['approval_status'] === 'pending');
http('POST', "$base/public/approvals.php", ['csrf' => $tcsrf, 't' => $tok, 'content_id' => $old, 'decision' => 'changes', 'note' => 'غيّروا العنوان'], $jarT);
t('«عايز تعديل» ← changes + الملاحظة', content_row($old)['approval_status'] === 'changes' && content_row($old)['approval_note'] === 'غيّروا العنوان');
t('الموافق عليه بيعدّي شرط الـ cron والمطلوب تعديله لأ', (int) db_one('SELECT COUNT(*) n FROM contents WHERE id IN (?, ?)' . ma_publish_sql(), [$cid, $old])['n'] === 1);
$_SESSION = ['impersonator_admin_id' => $superId, 'user_id' => $uid];
t('بعد الموافقة الفريق يقدر يجدول', ma_publish_block(content_row($cid)) === null);
$_SESSION = [];
// توكن عميل تاني مايقررش على منشور ده
$o2 = (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, status, approval_status) VALUES (?,?,?,?,?,?, "pending")', [$uid, (int) db_one('SELECT id FROM brand_profiles WHERE user_id = ?', [$uid])['id'], 'introductory', 'both', 'منشور تالت', 'generated']);
$tokB = ma_token($other); $jarB = tempnam(sys_get_temp_dir(), 'ma');
[, $hB] = http('GET', "$base/public/approvals.php?t=$tokB", [], $jarB);
http('POST', "$base/public/approvals.php", ['csrf' => csrf_of($hB), 't' => $tokB, 'content_id' => $o2, 'decision' => 'approve'], $jarB);
t('توكن عميل تاني مايوافقش على منشور مش بتاعه', content_row($o2)['approval_status'] === 'pending');
t('توكن منتهي أو متلاعب فيه مرفوض', ma_token_uid(ma_token($uid, time() - 10)) === 0 && ma_token_uid(substr($tok, 0, -1) . (substr($tok, -1) === 'a' ? 'b' : 'a')) === 0 && ma_token_uid($tok) === $uid);
[$c, , $hd] = http('GET', "$base/public/approvals.php?t=" . ma_token($uid, time() - 10), [], tempnam(sys_get_temp_dir(), 'ma'));
t('لينك منتهي ← صفحة الدخول', $c === 302 && str_contains(loc($hd), 'login.php'), loc($hd));
// العميل داخل بحسابه
[, $h] = http('GET', "$base/public/login.php", [], $jarC);
http('POST', "$base/public/login.php", ['csrf' => csrf_of($h), 'email' => "ma1-a-$tag@test.local", 'password' => 'Test12345!'], $jarC);
[$c, $h] = http('GET', "$base/public/dashboard.php?onb=skip", [], $jarC);
t('لوحة العميل فيها تنبيه «جهّزلك منشور»', $c === 200 && str_contains($h, 'approvals.php') && str_contains($h, 'جهّزلك'), (string) $c);
[$c, $h] = http('GET', "$base/public/approvals.php", [], $jarC);
http('POST', "$base/public/approvals.php", ['csrf' => csrf_of($h), 'all' => '1'], $jarC);
t('«موافق على الكل» من حساب العميل', $c === 200 && content_row($o2)['approval_status'] === 'approved');
[$c, $h] = http('GET', "$base/public/content-view.php?id=$cid", [], $jarC);
t('العميل شايف «اتعمل بواسطة فريق Spread»', $c === 200 && str_contains($h, 'اتعمل بواسطة فريق Spread'), (string) $c);

/* ─── 5) مستوى «نشر مباشر» + ملاحظات + تقرير ─── */
echo "نشر مباشر · ملاحظات · تقرير\n";
ma_update($uid, $superId, 'auto', 8);
http('GET', "$base/admin/impersonate.php?user_id=$uid", [], $jar);
[, $h] = http('GET', "$base/public/create-content.php", [], $jar);
db_run('DELETE FROM rate_limits WHERE action = "generate"');
[, $b] = http('POST', "$base/public/ajax/generate-content.php", ['csrf' => csrf_of($h), 'content_type' => 'introductory', 'platform' => 'both', 'length' => 'medium', 'dialect' => 'egyptian'], $jar);
$c2 = stamped_row((int) (json_decode($b, true)['content_id'] ?? 0));
t('مستوى «نشر مباشر»: متعلّم باسم الفريق ومن غير مسار موافقة', (int) ($c2['acted_by_admin_id'] ?? 0) === $superId && array_key_exists('approval_status', $c2) && $c2['approval_status'] === null, substr($b, 0, 120));
$_SESSION = ['impersonator_admin_id' => $superId, 'user_id' => $uid];
t('والفريق يقدر يجدوله', $c2 && ma_publish_block($c2) === null);
$_SESSION = [];
http('GET', "$base/admin/impersonate.php?stop=1", [], $jar);
admin_login($base, 'owner@app.test', $jar);
[, $h] = http('GET', "$base/admin/workspace.php?id=$uid&tab=notes", [], $jar);
$wc = csrf_of($h);
http('POST', "$base/admin/workspace.php", ['csrf' => $wc, 'id' => $uid, 'action' => 'note_add', 'kind' => 'task', 'body' => 'كلّم العميل عن عرض رمضان', 'due_date' => date('Y-m-d', time() + 86400)], $jar);
$task = db_one('SELECT * FROM managed_notes WHERE user_id = ? AND kind = "task"', [$uid]);
t('إضافة مهمة', $task && $task['body'] === 'كلّم العميل عن عرض رمضان' && (int) $task['admin_id'] === $superId);
[, $h] = http('GET', "$base/admin/my-accounts.php?all=1", [], $jar);
t('الكارت بيعرض المهام المفتوحة والتعديل المطلوب', str_contains($h, 'مهام 1') && str_contains($h, 'تعديل مطلوب 1'));
http('POST', "$base/admin/workspace.php", ['csrf' => $wc, 'id' => $uid, 'action' => 'task_done', 'note_id' => (int) $task['id']], $jar);
t('تقفيل المهمة', !empty(db_one('SELECT done_at FROM managed_notes WHERE id = ?', [(int) $task['id']])['done_at']));
http('POST', "$base/admin/workspace.php", ['csrf' => $wc, 'id' => $other, 'action' => 'task_done', 'note_id' => (int) $task['id']], $jar);
t('مهمة عميل تاني ماتتغيّرش من مساحة غيره', !empty(db_one('SELECT done_at FROM managed_notes WHERE id = ?', [(int) $task['id']])['done_at']));
$st = ma_month_stats($uid);
t('أرقام الشهر صح', $st['made'] === 4 && $st['by_team'] === 3 && $st['approved'] === 2 && $st['changes'] === 1, json_encode($st));
[$c, $h] = http('GET', "$base/admin/workspace.php?id=$uid&tab=report", [], $jar);
t('تبويب التقرير بيفتح', $c === 200 && str_contains($h, 'id="ma-report"'), (string) $c);
foreach (['content', 'brand', 'messages', 'notes'] as $tb) { [$c] = http('GET', "$base/admin/workspace.php?id=$uid&tab=$tb", [], $jar); if ($c !== 200) t("تبويب $tb", false, (string) $c); }
t('الكريدت ماتلمسش من الإدارة (غير تكلفة التوليد)', (int) db_one('SELECT COUNT(*) n FROM credit_transactions WHERE user_id = ? AND action_type = "add"', [$uid])['n'] === 0);

/* ─── تنضيف ─── */
foreach ([$uid, $other] as $u) {
    foreach (['content_versions' => 'content_id IN (SELECT id FROM contents WHERE user_id = ?)', 'contents' => 'user_id = ?', 'managed_notes' => 'user_id = ?', 'managed_accounts' => 'user_id = ?',
              'user_notifications' => 'user_id = ?', 'brand_kits' => 'user_id = ?', 'brand_profiles' => 'user_id = ?', 'credit_transactions' => 'user_id = ?', 'credit_wallets' => 'user_id = ?',
              'impersonation_logs' => 'user_id = ?'] as $tb => $w) {
        try { db_run("DELETE FROM $tb WHERE $w", [$u]); } catch (\Throwable $e) {}
    }
    try { db_run('DELETE FROM users WHERE id = ?', [$u]); } catch (\Throwable $e) { echo "  (تنضيف: " . $e->getMessage() . ")\n"; }
}
try { db_run('DELETE FROM admin_logs WHERE admin_id = ?', [$asst]); } catch (\Throwable $e) {}
db_run('DELETE FROM admin_users WHERE id = ?', [$asst]);
foreach (['managed_client_ids' => $savedMc, 'managed_accounts_imported' => $savedImp ?? '1'] as $k => $v) {
    db_run('DELETE FROM settings WHERE setting_key = ?', [$k]);
    if ($v !== null) set_setting($k, $v);
}
db_run('DELETE FROM rate_limits WHERE action IN ("login", "admin_login", "generate", "ma_token", "ma_decide")');
foreach ([$jar, $jarA, $jarC, $jarT, $jarB] as $f) @unlink($f);

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);
