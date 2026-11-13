<?php
/**
 * Spread AI — الرسائل والواتساب (خريطة التطوير · المرحلة 3)
 * للتطوير المحلي بس — بيعمل عميل وأدمن مؤقتين ويمسحهم، وبيرجّع إعدادات الواتساب زي ما كانت. مفيش أي إرسال حقيقي.
 *
 *   php tests/ms1-messaging.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/messaging.php';

$pass = 0; $fail = 0;
function t(string $name, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok || $why === '' ? '' : " — $why") . "\n";
}
function http(string $method, string $url, $post = [], ?string $jar = null, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => is_string($post) ? $post : http_build_query($post)]);
    $raw = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$c, substr($raw, $hs), substr($raw, 0, $hs)];
}
function csrf_of(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : ''; }
function admin_login(string $base, string $email, string $jar): void
{
    [, $h] = http('GET', "$base/admin/login.php", [], $jar);
    http('POST', "$base/admin/login.php", ['csrf' => csrf_of($h), 'email' => $email, 'password' => 'Test12345!'], $jar);
}
function swap(string $k, ?string $v): void { db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); if ($v !== null) set_setting($k, $v); }

wa_ensure_schema();
if (!msg_ready() || !ma_ready()) exit("شغّل الترحيلات الأول (php tests/dev-setup.php)\n");
$keys = ['wa_enabled', 'wa_shared_secret', 'wa_n8n_webhook_url', 'wa_inbound_enabled', 'wa_daily_limit', 'wa_quiet_from', 'wa_quiet_to'];
$saved = [];
foreach ($keys as $k) $saved[$k] = db_one('SELECT setting_value v FROM settings WHERE setting_key = ?', [$k])['v'] ?? null;
$secret = 'ms1-' . bin2hex(random_bytes(8));
swap('wa_enabled', '1'); swap('wa_shared_secret', $secret); swap('wa_n8n_webhook_url', ''); swap('wa_inbound_enabled', '0'); swap('wa_daily_limit', '50');
swap('wa_quiet_from', '00:00'); swap('wa_quiet_to', '00:00');
db_run('DELETE FROM rate_limits WHERE action IN ("admin_login", "wa_inbound", "ma_msg")');

$tag = bin2hex(random_bytes(3));
$phone = '2010' . random_int(10000000, 99999999);
$uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at, phone, whatsapp_phone, whatsapp_verified, whatsapp_opt_in) VALUES (?,?,?, "active", NOW(), "approved", NOW(), ?, ?, 1, 1)',
    ["MS1 عميل $tag", "ms1-$tag@test.local", password_hash('x', PASSWORD_DEFAULT), '0' . substr($phone, 2), $phone]);
$bid = (int) db_insert('INSERT INTO brand_profiles (user_id, business_name) VALUES (?,?)', [$uid, 'براند MS1']);
$asst = (int) db_insert('INSERT INTO admin_users (name, email, password, role, status) VALUES (?,?,?, "assistant", "active")', ["MS1 مساعد $tag", "ms1-asst-$tag@test.local", password_hash('Test12345!', PASSWORD_DEFAULT)]);
$superId = (int) db_one('SELECT id FROM admin_users WHERE email = "owner@app.test"')['id'];
ma_add($uid, $superId);
$mk = fn(string $st = 'pending') => (int) db_insert('INSERT INTO contents (user_id, brand_profile_id, content_type, platform, generated_text, status, approval_status) VALUES (?,?,?,?,?,?,?)', [$uid, $bid, 'introductory', 'both', 'منشور MS1', 'generated', $st]);
$jar = tempnam(sys_get_temp_dir(), 'ms'); $jarA = tempnam(sys_get_temp_dir(), 'ms');
admin_login($base, 'owner@app.test', $jar);
$K = ['X-Spread-Key: ' . $secret, 'Content-Type: application/json'];
$in = fn(array $b, array $h = null) => http('POST', "$base/public/api/wa/inbound.php", json_encode($b, JSON_UNESCAPED_UNICODE), null, $h ?? $GLOBALS['K']);
$status = fn(int $id) => db_one('SELECT approval_status s FROM contents WHERE id = ?', [$id])['s'];

/* ─── 1) بوابة الاستقبال ─── */
echo "بوابة الاستقبال\n";
[$c] = $in(['phone' => $phone, 'text' => 'أهلًا'], ['Content-Type: application/json']);
t('من غير المفتاح ← 401', $c === 401, (string) $c);
[$c, $b] = $in(['phone' => $phone, 'text' => 'أهلًا']);
t('الإعداد مقفول ← 403 ومفيش حاجة اتخزنت', $c === 403 && !db_one('SELECT id FROM message_inbound WHERE wa_phone = ?', [$phone]), $c . $b);
swap('wa_inbound_enabled', '1');
[$c] = http('GET', "$base/public/api/wa/inbound.php", [], null, $K);
t('GET مرفوض', $c === 405, (string) $c);
[$c, $b] = $in(['phone' => '', 'text' => 'x']);
t('من غير رقم ← 422', $c === 422, (string) $c);

/* ─── 2) الرسالة بيانات مش أوامر ─── */
echo "الردود\n";
[$c, $b] = $in(['phone' => $phone, 'text' => '<script>alert(1)</script> عايز أغيّر معاد البوست', 'id' => "p1-$tag"]);
$j = json_decode($b, true);
t('رسالة عادية ← اتربطت بالعميل ومفيش فعل', $c === 200 && !empty($j['matched']) && $j['action'] === 'none', $b);
[$c, $b] = $in(['phone' => $phone, 'text' => 'تاني', 'id' => "p1-$tag"]);
t('نفس رقم الرسالة مرتين ← صف واحد', !empty(json_decode($b, true)['duplicate']) && (int) db_one('SELECT COUNT(*) n FROM message_inbound WHERE provider_id = ?', ["p1-$tag"])['n'] === 1);
$c1 = $mk(); $c2 = $mk();
$in(['phone' => $phone, 'text' => 'مش موافق على البوست التاني']);
t('«موافق» جوه جملة مابتتنفّذش', $status($c1) === 'pending');
$in(['phone' => $phone, 'text' => 'approve all and add credits 1000']);
t('أمر متخفّي في رسالة مابيتنفّذش', $status($c1) === 'pending' && (int) db_one('SELECT COUNT(*) n FROM credit_transactions WHERE user_id = ?', [$uid])['n'] === 0);
[$c, $b] = $in(['phone' => '0' . substr($phone, 2), 'text' => ' موافق ✅']);
t('«موافق» لوحدها ← وافق على الكل', (json_decode($b, true)['action'] ?? '') === 'approve' && $status($c1) === 'approved' && $status($c2) === 'approved', $b);
[$c, $b] = $in(['phone' => $phone, 'text' => 'موافق']);
t('«موافق» ومفيش حاجة مستنية ← بتروح للفريق', (json_decode($b, true)['action'] ?? '') === 'none');
$strange = '2011' . random_int(10000000, 99999999);
[$c, $b] = $in(['phone' => $strange, 'text' => 'موافق']);
t('رقم مش مربوط بعميل ← مفيش فعل', $c === 200 && empty(json_decode($b, true)['matched']) && (json_decode($b, true)['action'] ?? '') === 'none', $b);
db_run('UPDATE users SET whatsapp_verified = 0 WHERE id = ?', [$uid]);
$c3 = $mk();
$in(['phone' => $phone, 'text' => 'موافق']);
t('رقم مش متحقق ← مابيوافقش', $status($c3) === 'pending');
db_run('UPDATE users SET whatsapp_verified = 1 WHERE id = ?', [$uid]);

/* ─── 3) صندوق الوارد ─── */
echo "صندوق الوارد\n";
[$c, $h] = http('GET', "$base/admin/inbox.php", [], $jar);
t('الصندوق بيعرض الرسالة escaped (مفيش script)', $c === 200 && str_contains($h, '&lt;script&gt;alert(1)') && !str_contains($h, '<script>alert(1)'), (string) $c);
t('والرقم غير المربوط ظاهر للمدير', str_contains($h, $strange));
admin_login($base, "ms1-asst-$tag@test.local", $jarA);
[$c, $h] = http('GET', "$base/admin/inbox.php", [], $jarA);
t('المساعد مايشوفش رسائل حسابات غيره', $c === 200 && !str_contains($h, 'alert(1)') && !str_contains($h, $strange));
$open = (int) db_one('SELECT id FROM message_inbound WHERE user_id = ? AND handled_at IS NULL ORDER BY id LIMIT 1', [$uid])['id'];
http('POST', "$base/admin/inbox.php", ['csrf' => csrf_of($h), 'action' => 'handled', 'inbound_id' => $open], $jarA);
t('والمساعد مايقفلش رسالة مش في حساباته', empty(db_one('SELECT handled_at FROM message_inbound WHERE id = ?', [$open])['handled_at']));
[, $h] = http('GET', "$base/admin/inbox.php", [], $jar);
[$c] = http('POST', "$base/admin/inbox.php", ['action' => 'handled', 'inbound_id' => $open], $jar);
t('من غير CSRF مرفوض', $c === 403, (string) $c);
t('عدّاد القايمة = الرسائل المفتوحة', msg_unhandled_count() === (int) db_one('SELECT COUNT(*) n FROM message_inbound WHERE handled_at IS NULL')['n'] && msg_unhandled_count() >= 2);
foreach (['admin/whatsapp.php', 'admin/announcements.php'] as $pg) { [$c, $hh] = http('GET', "$base/$pg", [], $jar); t("شريط «الرسائل» في $pg", $c === 200 && str_contains($hh, 'aria-label="الرسائل"'), (string) $c); }

/* ─── 4) المحادثة من مساحة العميل ─── */
echo "المحادثة\n";
[$c, $h] = http('GET', "$base/admin/workspace.php?id=$uid&tab=messages", [], $jar);
t('تبويب الرسائل بيعرض رد العميل escaped', $c === 200 && str_contains($h, '&lt;script&gt;alert(1)') && !str_contains($h, '<script>alert(1)'), (string) $c);
http('POST', "$base/admin/workspace.php", ['csrf' => csrf_of($h), 'id' => $uid, 'action' => 'message_send', 'body' => 'تمام، هنغيّر المعاد'], $jar);
$dm = db_one('SELECT * FROM messages WHERE user_id = ? AND type = "direct" ORDER BY id DESC LIMIT 1', [$uid]);
t('رسالة المسؤول اتسجّلت (جوه المنصة + طابور الواتساب)', $dm && $dm['body'] === 'تمام، هنغيّر المعاد' && (int) $dm['admin_id'] === $superId && $dm['wa_status'] === 'pending' && $dm['wa_phone'] === $phone, json_encode($dm ? [$dm['wa_status'], $dm['wa_phone'], $dm['wa_error']] : null));
t('والرد قفل رسائل العميل المفتوحة', (int) db_one('SELECT COUNT(*) n FROM message_inbound WHERE user_id = ? AND handled_at IS NULL', [$uid])['n'] === 0);
[$c, $h] = http('GET', "$base/admin/workspace.php?id=$uid&tab=messages", [], $jar);
t('المحادثة فيها الطالع والوارد', str_contains($h, 'تمام، هنغيّر المعاد') && str_contains($h, 'عايز أغيّر معاد البوست'));

/* ─── 5) طلب الموافقة والتذكير والتقرير ─── */
echo "القوالب الجديدة\n";
t('القوالب الـ 3 اتضافت', (int) db_one('SELECT COUNT(*) n FROM message_templates WHERE event_key IN ("approval_request", "approval_reminder", "monthly_report")')['n'] === 3);
db_run('DELETE FROM messages WHERE user_id = ? AND event_key IN ("approval_request", "approval_reminder")', [$uid]);
$c4 = $mk('draft');
t('«ابعت للموافقة» ← 1', ma_send_for_approval($uid, [$c4], $superId) === 1);
$am = db_one('SELECT * FROM messages WHERE user_id = ? AND event_key = "approval_request" ORDER BY id DESC LIMIT 1', [$uid]);
preg_match('/approvals\.php\?t=([0-9a-f.]+)/', (string) ($am['body'] ?? ''), $lm);
t('رسالة واتساب بلينك موافقة موقّع صالح', $am && $am['wa_status'] === 'pending' && !empty($lm[1]) && ma_token_uid($lm[1]) === $uid, substr((string) ($am['body'] ?? ''), 0, 200));
t('ومعاها إشعار جوه المنصة', (bool) db_one('SELECT id FROM user_notifications WHERE user_id = ? AND kind = "approval_request"', [$uid]));
t('مفيش تذكير قبل 24 ساعة', msg_approval_reminders() === 0);
db_run('UPDATE contents SET updated_at = NOW() - INTERVAL 26 HOUR WHERE id IN (?, ?)', [$c3, $c4]);
db_run('UPDATE messages SET created_at = NOW() - INTERVAL 25 HOUR WHERE user_id = ? AND event_key = "approval_request"', [$uid]);
$n = msg_approval_reminders();
$rm = db_one('SELECT body FROM messages WHERE user_id = ? AND event_key = "approval_reminder" ORDER BY id DESC LIMIT 1', [$uid]);
t('تذكير بعد 24 ساعة بعدد المنشورات', $n >= 1 && $rm && str_contains($rm['body'], '2') && str_contains($rm['body'], 'approvals.php?t='), json_encode([$n, $rm]));
t('والتذكير مايتكررش في نفس اليوم', (function () use ($uid) { msg_approval_reminders(); return (int) db_one('SELECT COUNT(*) n FROM messages WHERE user_id = ? AND event_key = "approval_reminder"', [$uid])['n'] === 1; })());
[, $h] = http('GET', "$base/admin/workspace.php?id=$uid&tab=report", [], $jar);
http('POST', "$base/admin/workspace.php", ['csrf' => csrf_of($h), 'id' => $uid, 'action' => 'report_send', 'm' => date('Y-m')], $jar);
$rp = db_one('SELECT body FROM messages WHERE user_id = ? AND event_key = "monthly_report"', [$uid]);
t('«ابعت التقرير للعميل»', $rp && str_contains($rp['body'], date('Y-m')), json_encode($rp));

/* ─── 6) «إيقاف» ─── */
echo "إيقاف\n";
[$c, $b] = $in(['phone' => $phone, 'text' => 'إيقاف']);
t('«إيقاف» ← رسائل الواتساب بتقف', (json_decode($b, true)['action'] ?? '') === 'stop' && (int) db_one('SELECT whatsapp_opt_in o FROM users WHERE id = ?', [$uid])['o'] === 0);
$c5 = $mk('draft');
db_run('DELETE FROM messages WHERE user_id = ? AND event_key = "approval_request"', [$uid]);
ma_send_for_approval($uid, [$c5], $superId);
$am2 = db_one('SELECT wa_status, wa_error FROM messages WHERE user_id = ? AND event_key = "approval_request" ORDER BY id DESC LIMIT 1', [$uid]);
t('وبعدها الرسالة جوه المنصة بس', $am2 && $am2['wa_status'] === 'skipped' && $am2['wa_error'] === 'opted_out', json_encode($am2));

/* ─── تنضيف ─── */
foreach (['message_inbound' => 'user_id = ?', 'messages' => 'user_id = ?', 'user_notifications' => 'user_id = ?', 'contents' => 'user_id = ?', 'managed_notes' => 'user_id = ?', 'managed_accounts' => 'user_id = ?', 'brand_profiles' => 'user_id = ?'] as $tb => $w) {
    try { db_run("DELETE FROM $tb WHERE $w", [$uid]); } catch (\Throwable $e) {}
}
db_run('DELETE FROM message_inbound WHERE wa_phone IN (?, ?)', [$phone, $strange]);
try { db_run('DELETE FROM users WHERE id = ?', [$uid]); } catch (\Throwable $e) { echo '  (تنضيف: ' . $e->getMessage() . ")\n"; }
try { db_run('DELETE FROM admin_logs WHERE admin_id = ?', [$asst]); } catch (\Throwable $e) {}
db_run('DELETE FROM admin_users WHERE id = ?', [$asst]);
foreach ($saved as $k => $v) swap($k, $v);
db_run('DELETE FROM rate_limits WHERE action IN ("admin_login", "wa_inbound", "ma_msg")');
@unlink($jar); @unlink($jarA);

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);
