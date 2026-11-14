<?php
/**
 * Spread AI — نظام الـ Skills (خريطة التطوير · المرحلة 4)
 * للتطوير المحلي بس — بيعمل عملاء وبيانات أداء مؤقتة ويمسحهم، وبيرجّع الـ Skills والإعدادات زي ما كانت.
 *
 *   php tests/sk1-skill-system.php http://localhost:8080
 */
if (PHP_SAPI !== 'cli') exit("CLI only\n");
$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../includes/config.php';
if (!APP_DEBUG) exit("APP_DEBUG مقفول — الاختبارات دي للتطوير المحلي بس\n");
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/prompt-builder.php';
require_once __DIR__ . '/../includes/post-skills.php';
require_once __DIR__ . '/../includes/skill-system.php';
require_once __DIR__ . '/../includes/managed-accounts.php';
require_once __DIR__ . '/../includes/campaign-flow.php';

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
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true]);
    if ($jar) curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $raw = (string) curl_exec($ch);
    $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$c, substr($raw, $hs), substr($raw, 0, $hs)];
}
function csrf_of(string $html): string { return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) ? $m[1] : ''; }
function swap(string $k, ?string $v): void { db_run('DELETE FROM settings WHERE setting_key = ?', [$k]); if ($v !== null) set_setting($k, $v); }

if (!ce_skills_ready() || !ma_ready()) exit("شغّل الترحيلات الأول (php tests/dev-setup.php)\n");
$saved = [];
foreach (['skill_require_sections', 'post_skills_enabled', 'campaign_skills_enabled'] as $k) $saved[$k] = db_one('SELECT setting_value v FROM settings WHERE setting_key = ?', [$k])['v'] ?? null;
swap('skill_require_sections', '1'); swap('post_skills_enabled', '1'); swap('campaign_skills_enabled', '1');
$tag = bin2hex(random_bytes(3));
$skillsBefore = array_map('intval', array_column(db_all('SELECT id FROM ce_skills'), 'id'));
$activeBefore = array_map('intval', array_column(db_all('SELECT id FROM ce_skill_versions WHERE is_active = 1'), 'id'));
$versionsBefore = array_map('intval', array_column(db_all('SELECT id FROM ce_skill_versions'), 'id'));

/* ─── 1) الأقسام الأساسية ─── */
echo "الأقسام الأساسية\n";
$added = ce_skill_import_samples(null);
t('أمثلة الـ Skills لسه بتتستورد (فيها الأقسام الـ 6)', (bool) db_one('SELECT id FROM ce_skills WHERE skey = "dental"') && (bool) db_one('SELECT id FROM ce_skills WHERE skey = "general"'), implode(',', $added));
$bad = "---\nkey: sk1$tag\nname: تجربة $tag\nmatch: نجارة$tag\n---\n## المعرفة\nجمهور الورش.\n## القواعد\nاكتب ببساطة.\n## الزوايا\n- قبل وبعد\n## أمثلة Hooks\n- شغلك يستاهل\n## التصميم\nصور حقيقية.\n";
$r = ce_skill_save_md($bad, null, '', 'upload', null);
t('ملف ناقصه «الممنوعات» ← مرفوض وبيقول القسم الناقص', !$r['ok'] && ($r['code'] ?? '') === 'sections' && str_contains($r['error'], 'الممنوعات') && !db_one('SELECT id FROM ce_skills WHERE skey = ?', ["sk1$tag"]), json_encode($r, JSON_UNESCAPED_UNICODE));
$good = $bad . "## الممنوعات\n- أرخص سعر\n";
$r = ce_skill_save_md($good, null, '', 'upload', null);
t('بعد ما القسم اتضاف ← اتحفظت مسودة', !empty($r['ok']) && (int) $r['version'] === 1, json_encode($r, JSON_UNESCAPED_UNICODE));
$mySkill = (int) ($r['skill_id'] ?? 0); $myV1 = (int) ($r['id'] ?? 0);
$act = ce_skill_activate($mySkill, $myV1, null);
t('التفعيل من غير اختبار رجوع مرفوض (في السيرفر)', !$act['ok'] && ($act['code'] ?? '') === 'regression' && !(int) db_one('SELECT is_active a FROM ce_skill_versions WHERE id = ?', [$myV1])['a']);
swap('skill_require_sections', '0');
$r2 = ce_skill_save_md(str_replace('نجارة' . $tag, 'نجارة' . $tag . ', خشب' . $tag, $bad), $mySkill, '', 'upload', null);
t('ومفتاح الطوارئ بيقفل الشرط', !empty($r2['ok']));
swap('skill_require_sections', '1');

/* ─── 2) التعيين التلقائي والتغطية ─── */
echo "التعيين التلقائي والتغطية\n";
// للاختبار بس: نفعّل النسخ مباشرة (التفعيل الحقيقي بيعدّي من ce_skill_activate واختبار الرجوع)
foreach (['dental', 'general', "sk1$tag"] as $key) {
    $sid = (int) db_one('SELECT id FROM ce_skills WHERE skey = ?', [$key])['id'];
    if (!db_one('SELECT id FROM ce_skill_versions WHERE skill_id = ? AND is_active = 1', [$sid])) {
        db_run('UPDATE ce_skill_versions SET is_active = 1 WHERE skill_id = ? ORDER BY version LIMIT 1', [$sid]);
    }
}
ce_skill_cache_bust();
$mkUser = function (string $n, string $industry) use ($tag): array {
    $uid = (int) db_insert('INSERT INTO users (name, email, password, status, email_verified_at, approval_status, approved_at) VALUES (?,?,?, "active", NOW(), "approved", NOW())', ["SK1 $n $tag", "sk1-$n-$tag@test.local", password_hash('x', PASSWORD_DEFAULT)]);
    $bid = (int) db_insert('INSERT INTO brand_profiles (user_id, business_name, industry) VALUES (?,?,?)', [$uid, "براند SK1 $n", $industry]);
    return [$uid, db_one('SELECT * FROM brand_profiles WHERE id = ?', [$bid])];
};
[$uDent, $bDent] = $mkUser('dent', 'عيادة أسنان');
[$uDent2, $bDent2] = $mkUser('dent2', 'مركز تقويم أسنان');
[$uGap, $bGap] = $mkUser('gap', "ورشة حدادة $tag");
[$uWood, $bWood] = $mkUser('wood', "ورشة نجارة$tag");
t('قطاع «أسنان» ← Skill الأسنان من غير تدخل', (post_skill($bDent)['skey'] ?? '') === 'dental');
t('قطاع من غير Skill ← «تسويق عام»', (post_skill($bGap)['skey'] ?? '') === 'general');
t('كلمة التعرّف بتربط القطاع بالـ Skill الجديدة', (post_skill($bWood)['skey'] ?? '') === "sk1$tag");
$prompt = build_final_prompt($bDent, ['content_type' => 'introductory', 'platform' => 'both', 'length' => 'medium', 'tone' => '', 'dialect' => 'egyptian', 'template_id' => 0, 'extra_notes' => ''], '');
t('برومبت المنشور السريع فيه Skill الأسنان', str_contains($prompt, 'SECTOR SKILL') && str_contains($prompt, post_skill($bDent)['name']));
$cov = skill_coverage(2000);
t('التغطية: براندين على الأسنان على الأقل', ($cov['by_skill']['dental'] ?? 0) >= 2, json_encode($cov['by_skill']));
t('والقطاع اللي مالوش Skill ظاهر في القايمة', (bool) array_filter($cov['gaps'], fn($g) => $g['industry'] === "ورشة حدادة $tag") && !array_filter($cov['gaps'], fn($g) => str_contains($g['industry'], 'أسنان')), json_encode(array_slice($cov['gaps'], 0, 5), JSON_UNESCAPED_UNICODE));

/* ─── 3) ملاحظات العميل ─── */
echo "ملاحظات العميل\n";
$superId = (int) db_one('SELECT id FROM admin_users WHERE email = "owner@app.test"')['id'];
$plain = post_skill_prefix('quick_post', $bDent);
t('عميل مش مُدار ← الملاحظات مابتتحفظش', skill_client_notes_set($uDent2, 'x', $superId) === false);
ma_add($uDent, $superId);
t('من غير ملاحظات ← البرومبت زي ما هو بالحرف', post_skill_prefix('quick_post', $bDent) === $plain && !str_contains($plain, 'ملاحظات خاصة'));
$note = "دايمًا اذكر إن الكشف الأول مجاني $tag";
skill_client_notes_set($uDent, $note . str_repeat(' زيادة', 400), $superId);
$stored = (string) db_one('SELECT body FROM managed_notes WHERE user_id = ? AND kind = "skill"', [$uDent])['body'];
t('الملاحظات بتتقص عند ' . SKILL_CLIENT_NOTES_MAX . ' حرف', mb_strlen($stored) === SKILL_CLIENT_NOTES_MAX && str_starts_with($stored, $note));
// كل طلب = عملية PHP جديدة (الكاش جوه العملية) — نقرا من عملية تانية
$fresh = function (int $uid, string $stage) {
    $code = '$_SERVER["REMOTE_ADDR"]="127.0.0.1"; require "' . dirname(__DIR__) . '/includes/config.php"; require "' . dirname(__DIR__) . '/includes/functions.php"; require "' . dirname(__DIR__) . '/includes/post-skills.php"; require "' . dirname(__DIR__) . '/includes/campaign-flow.php";'
        . '$b = db_one("SELECT * FROM brand_profiles WHERE user_id = ?", [' . $uid . ']);'
        . 'echo json_encode(["post" => post_skill_prefix("' . $stage . '", $b), "camp" => ce_skill_block("campaign_content", campaign_skill($b, [])), "bound" => ce_skill_block("campaign_content", campaign_skill($b, ["skill_version_id" => post_skill($b)["version_id"], "user_id" => ' . $uid . '])), "design" => post_skill_visual($b)]);';
    return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code)), true) ?: [];
};
$f = $fresh($uDent, 'quick_post');
t('المنشور السريع فيه ملاحظات العميل بعد Skill القطاع', str_contains($f['post'] ?? '', $note) && strpos($f['post'], 'SECTOR SKILL') < strpos($f['post'], $note), substr($f['post'] ?? '', -200));
t('والحملات (الجديدة والمربوطة بنسخة) كمان', str_contains($f['camp'] ?? '', $note) && str_contains($f['bound'] ?? '', $note));
t('وبرومبت التصميم مابيتغيّرش', !str_contains($f['design'] ?? '', $note));
t('وعميل تاني في نفس القطاع مابياخدهاش', !str_contains($fresh($uDent2, 'quick_post')['post'] ?? '', $note));
skill_client_notes_set($uDent, '', $superId);
t('فاضية ← اتشالت', !db_one('SELECT id FROM managed_notes WHERE user_id = ? AND kind = "skill"', [$uDent]) && ($fresh($uDent, 'quick_post')['post'] ?? '') === $plain);

/* ─── 4) اقتراح الشهر ─── */
echo "اقتراح الشهر\n";
$dental = db_one('SELECT * FROM ce_skills WHERE skey = "dental"');
t('بيانات قليلة ← مفيش اقتراح', skill_suggestion_draft((int) $dental['id'], null)['ok'] === false || skill_month_suggestion(['skey' => 'dental'])['posts'] >= 6);
db_run('SET FOREIGN_KEY_CHECKS = 0');
$cids = [];
foreach ([[900, 90, 'الأعلى'], [800, 60, 'تاني'], [700, 40, 'تالت'], [600, 6, 'رابع'], [500, 3, 'خامس'], [400, 1, 'الأقل']] as $i => [$reach, $likes, $w]) {
    $cid = (int) db_insert('INSERT INTO ce_contents (campaign_id, slot_id, user_id, format, status, body_json) VALUES (?,?,?,?,?,?)', [990000 + $i, 990000 + random_int(1, 9999999), $uDent, 'post', 'pass', json_encode(['hook' => "هوك $w $tag", 'body' => "نص المنشور $w"], JSON_UNESCAPED_UNICODE)]);
    $cids[] = $cid;
    db_insert('INSERT INTO ce_perf_posts (user_id, content_id, platform, connection_id, provider_post_id, published_at, reach, likes, sync_status) VALUES (?,?,?,?,?, NOW() - INTERVAL 3 DAY, ?, ?, "ok")', [$uDent, $cid, 'facebook', 0, "sk1_{$tag}_$i", $reach, $likes]);
}
db_run('SET FOREIGN_KEY_CHECKS = 1');
$sug = skill_month_suggestion(['skey' => 'dental']);
t('الأعلى والأقل تفاعلًا للقطاع', str_contains($sug['top'][0]['text'] ?? '', "هوك الأعلى $tag") && str_contains($sug['low'][0]['text'] ?? '', "هوك الأقل $tag"), json_encode([$sug['top'][0]['text'] ?? null, $sug['low'][0]['text'] ?? null], JSON_UNESCAPED_UNICODE));
t('واقتراح قطاع تاني مافيهوش منشورات الأسنان', !array_filter(skill_month_suggestion(['skey' => "sk1$tag"])['top'], fn($p) => str_contains($p['text'], $tag)));
$activeV = (int) db_one('SELECT id FROM ce_skill_versions WHERE skill_id = ? AND is_active = 1', [(int) $dental['id']])['id'];
$d = skill_suggestion_draft((int) $dental['id'], $superId);
$dv = !empty($d['id']) ? db_one('SELECT * FROM ce_skill_versions WHERE id = ?', [(int) $d['id']]) : null;
t('المسودة اتعملت بالأمثلة ومش شغالة', !empty($d['ok']) && $dv && !(int) $dv['is_active'] && str_contains($dv['body_md'], "هوك الأعلى $tag") && str_contains($dv['body_md'], 'مثال ناجح'), json_encode($d, JSON_UNESCAPED_UNICODE));
t('والنسخة الشغالة زي ما هي', (int) db_one('SELECT id FROM ce_skill_versions WHERE skill_id = ? AND is_active = 1', [(int) $dental['id']])['id'] === $activeV);
t('والمسودة ماتتفعّلش من غير اختبار رجوع', $dv && ce_skill_activate((int) $dental['id'], (int) $dv['id'], $superId)['ok'] === false);

/* ─── 5) الشاشات ─── */
echo "الشاشات\n";
$jar = tempnam(sys_get_temp_dir(), 'sk');
db_run('DELETE FROM rate_limits WHERE action = "admin_login"');
[, $h] = http('GET', "$base/admin/login.php", [], $jar);
http('POST', "$base/admin/login.php", ['csrf' => csrf_of($h), 'email' => 'owner@app.test', 'password' => 'Test12345!'], $jar);
[$c, $h] = http('GET', "$base/admin/ce-skills.php", [], $jar);
t('صفحة الـ Skills: عدد البراندات + آخر اختبار + القطاعات من غير Skill', $c === 200 && str_contains($h, 'id="skill-gaps"') && str_contains($h, "ورشة حدادة $tag") && str_contains($h, 'آخر اختبار'), (string) $c);
[$c, $h] = http('GET', "$base/admin/ce-skills.php?id=" . (int) $dental['id'], [], $jar);
t('صفحة الـ Skill فيها اقتراح الشهر', $c === 200 && str_contains($h, 'id="skill-suggest"') && str_contains($h, "هوك الأعلى $tag"), (string) $c);
[$c, $h] = http('GET', "$base/admin/workspace.php?id=$uDent&tab=brand", [], $jar);
t('مساحة العميل: ملاحظات الكتابة + اسم Skill القطاع', $c === 200 && str_contains($h, 'id="skill-notes"') && str_contains($h, 'Skill القطاع: ' . $dental['name']), (string) $c);
[$c] = http('POST', "$base/admin/workspace.php", ['id' => $uDent, 'action' => 'skill_notes', 'notes' => 'من غير توكن'], $jar);
t('حفظ الملاحظات من غير CSRF مرفوض', $c === 403 && !db_one('SELECT id FROM managed_notes WHERE user_id = ? AND kind = "skill"', [$uDent]), (string) $c);
http('POST', "$base/admin/workspace.php", ['csrf' => csrf_of($h), 'id' => $uDent, 'action' => 'skill_notes', 'notes' => "ملاحظة من الشاشة $tag"], $jar);
t('وبالتوكن بتتحفظ', (db_one('SELECT body FROM managed_notes WHERE user_id = ? AND kind = "skill"', [$uDent])['body'] ?? '') === "ملاحظة من الشاشة $tag");
[$c, $h] = http('GET', "$base/admin/workspace.php?id=$uDent&tab=notes", [], $jar);
t('وملاحظات الكتابة مش ظاهرة في تبويب الملاحظات والمهام', $c === 200 && !str_contains($h, "ملاحظة من الشاشة $tag"));

/* ─── تنضيف ─── */
db_run('DELETE FROM ce_perf_posts WHERE provider_post_id LIKE ?', ["sk1_{$tag}_%"]);
if ($cids) db_run('DELETE FROM ce_contents WHERE id IN (' . implode(',', $cids) . ')');
foreach ([$uDent, $uDent2, $uGap, $uWood] as $u) {
    foreach (['managed_notes', 'managed_accounts', 'brand_kits', 'brand_profiles'] as $tb) { try { db_run("DELETE FROM $tb WHERE user_id = ?", [$u]); } catch (\Throwable $e) {} }
    try { db_run('DELETE FROM users WHERE id = ?', [$u]); } catch (\Throwable $e) { echo '  (تنضيف: ' . $e->getMessage() . ")\n"; }
}
// الـ Skills والنسخ اللي الاختبار عملها أو فعّلها ← ترجع زي ما كانت
$newV = array_diff(array_map('intval', array_column(db_all('SELECT id FROM ce_skill_versions'), 'id')), $versionsBefore);
if ($newV) db_run('DELETE FROM ce_skill_versions WHERE id IN (' . implode(',', $newV) . ')');
$newS = array_diff(array_map('intval', array_column(db_all('SELECT id FROM ce_skills'), 'id')), $skillsBefore);
if ($newS) db_run('DELETE FROM ce_skills WHERE id IN (' . implode(',', $newS) . ')');
db_run('UPDATE ce_skill_versions SET is_active = 0');
if ($activeBefore) db_run('UPDATE ce_skill_versions SET is_active = 1 WHERE id IN (' . implode(',', $activeBefore) . ')');
foreach ($saved as $k => $v) swap($k, $v);
db_run('DELETE FROM rate_limits WHERE action = "admin_login"');
@unlink($jar);

echo "\n" . ($fail ? "✗ $fail فشل · " : '✓ ') . "$pass نجح\n";
exit($fail ? 1 : 0);
