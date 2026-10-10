<?php
/**
 * Spread AI v2 — مساعد الهوية الذكي (AJAX)
 * محادثة: الايجنت يسأل سؤال سؤال → يجمع التفاصيل → يحفظ الهوية كاملة
 * actions: load | send | restart
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/ai.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/brand-brain.php';

require_login();
require_csrf();
session_release();   // العملية طويلة — مانقفلش باقي صفحات العميل لحد ما تخلص
decode_b64_fields();

$user = current_user();
$action = $_POST['action'] ?? 'load';

const AGENT_GREETING = "أهلًا بيك! 👋 أنا مساعد الهوية — هسألك شوية أسئلة بسيطة وأظبطلك هوية براندك كاملة على المنصة.\n\nخلينا نبدأ: **إيه اسم البيزنس بتاعك، وشغال في أنهي مجال؟** (مثلًا: عيادة أسنان، مطعم، براند ملابس...)";

/**
 * أول رسالة: ملخص اللي اتعمل في الهوية + يبدأ من أول معلومة ناقصة (بالترتيب الأساسي)
 * — مش بيسأل من الأول عن حاجات العميل كاتبها قبل كده
 */
function agent_greeting(int $userId): string
{
    $brand = brand_for_user($userId);
    if (!$brand || !brand_filled($brand['business_name'] ?? '')) {
        return AGENT_GREETING;
    }
    $ov = brand_overview($brand, $userId);
    $known = [];
    foreach (brand_fields() as $k => [$label, $w]) {
        if (!brand_filled($brand[$k] ?? '') || $k === 'logo_path') continue;
        $v = trim(preg_replace('/\s+/u', ' ', (string) $brand[$k]));
        $v = brand_field_options()[$k][$v] ?? $v;
        $known[] = '• ' . $label . ': ' . (mb_strlen($v) > 70 ? mb_substr($v, 0, 70) . '…' : $v);
        if (count($known) >= 8) break;
    }
    $d = $ov['done'];
    $msg = "أهلًا بيك تاني! 👋 ده ملخص اللي اتعمل في هوية **" . $brand['business_name'] . "** لحد دلوقتي (" . $d['pct'] . "%):\n"
        . implode("\n", $known)
        . ($d['researches'] ? "\n• أبحاث عميقة: " . $d['researches'] : '')
        . ($d['inspirations'] ? "\n• تصميمات بتعجبك: " . $d['inspirations'] : '')
        . ($d['sources'] ? "\n• ملفات ومصادر: " . $d['sources'] : '');
    if (!$ov['missing']) {
        return $msg . "\n\n✅ كل المعلومات الأساسية موجودة. تحب نعدّل حاجة أو نضيف تفاصيل زيادة (قواعد التصميم · كلمات ممنوعة)؟";
    }
    $labels = array_map(fn($m) => $m['label'], array_slice($ov['missing'], 0, 4));
    return $msg . "\n\nفاضل: " . implode('، ', $labels) . (count($ov['missing']) > 4 ? '…' : '')
        . "\n\nخلينا نكمّل من الناقص: **" . $ov['missing'][0]['question'] . "**";
}

function agent_session(int $userId): array
{
    $s = db_one('SELECT * FROM agent_sessions WHERE user_id = ? AND status = "active" ORDER BY id DESC LIMIT 1', [$userId]);
    if (!$s) {
        $messages = [['role' => 'assistant', 'content' => agent_greeting($userId)]];
        $id = db_insert(
            'INSERT INTO agent_sessions (user_id, messages) VALUES (?, ?)',
            [$userId, json_encode($messages, JSON_UNESCAPED_UNICODE)]
        );
        return ['id' => $id, 'messages' => $messages, 'status' => 'active'];
    }
    return ['id' => (int) $s['id'], 'messages' => json_decode($s['messages'] ?: '[]', true) ?: [], 'status' => $s['status']];
}

function agent_save(int $sessionId, array $messages, string $status = 'active'): void
{
    db_run(
        'UPDATE agent_sessions SET messages = ?, status = ? WHERE id = ?',
        [json_encode($messages, JSON_UNESCAPED_UNICODE), $status, $sessionId]
    );
}

// ═══ load: رجّع المحادثة الحالية ═══
if ($action === 'load') {
    $s = agent_session((int) $user['id']);
    // محادثة لسه مابدأتش (التحية بس): نحدّث التحية باللي اتعمل في الهوية دلوقتي
    if (count($s['messages']) === 1 && ($s['messages'][0]['role'] ?? '') === 'assistant') {
        $s['messages'] = [['role' => 'assistant', 'content' => agent_greeting((int) $user['id'])]];
        agent_save($s['id'], $s['messages']);
    }
    json_response(['ok' => true, 'messages' => $s['messages'], 'status' => $s['status']]);
}

// ═══ restart: جلسة جديدة ═══
if ($action === 'restart') {
    db_run('UPDATE agent_sessions SET status = "done" WHERE user_id = ? AND status = "active"', [$user['id']]);
    $s = agent_session((int) $user['id']);
    json_response(['ok' => true, 'messages' => $s['messages'], 'status' => 'active']);
}

// ═══ send: رسالة من العميل ═══
$text = mb_substr(trim($_POST['message'] ?? ''), 0, 1500);
if ($text === '') {
    json_response(['ok' => false, 'error' => 'اكتب رسالتك الأول']);
}
if (!rate_limit('brand_agent', 'u' . $user['id'], 30, 600)) {
    json_response(['ok' => false, 'error' => 'وصلت للحد الأقصى — استنى شوية وكمّل']);
}

$cost = (int) get_setting('brand_agent_cost', 0);
if ($cost > 0 && !credits_consume((int) $user['id'], $cost, 'مساعد الهوية')) {
    json_response(['ok' => false, 'error' => credits_short_msg($cost)]);
}

$s = agent_session((int) $user['id']);
$messages = $s['messages'];
$messages[] = ['role' => 'user', 'content' => $text];

// ── بناء البرومبت: نظام + المحادثة كاملة ──
$transcript = '';
foreach ($messages as $m) {
    $transcript .= ($m['role'] === 'user' ? "العميل: " : "المساعد: ") . $m['content'] . "\n\n";
}

// اللي معروف والناقص (بالترتيب الأساسي) — المساعد مايسألش عن حاجة موجودة
$__brand = brand_for_user((int) $user['id']);
$__known = [];
$__missing = [];
if ($__brand) {
    foreach (brand_fields() as $k => [$label]) {
        if ($k === 'logo_path') continue;
        if (brand_filled($__brand[$k] ?? '')) $__known[] = '- ' . $label . ': ' . mb_substr(trim((string) $__brand[$k]), 0, 200);
    }
    foreach (brand_overview($__brand, (int) $user['id'])['missing'] as $m) $__missing[] = $m['label'];
}

$system = "أنت «مساعد الهوية» — خبير استراتيجية براندات مصري ودود على منصة محتوى بالذكاء الاصطناعي. مهمتك تبني هوية العميل بالكامل عبر المحادثة.\n\n"
    . ($__known ? "المعلومات الموجودة فعلًا في هوية العميل (ماتسألش عنها تاني إلا لو طلب يعدّلها):\n" . implode("\n", $__known) . "\n\n" : '')
    . ($__missing ? "المعلومات الناقصة — ابدأ منها بالترتيب ده: " . implode('، ', $__missing) . "\n\n" : '')
    . "القواعد:\n"
    . "1. اسأل سؤال واحد بس في كل رد — قصير وواضح بالعامية المصرية، وممكن تدي مثال يساعده.\n"
    . "2. لازم تجمع بالترتيب: اسم البيزنس والمجال، وصف النشاط وأهم الخدمات/المنتجات، الجمهور المستهدف، نبرة الكلام (رسمي/ودود/شبابي...)، الألوان المفضلة، اللهجة (مصري/فصحى/خليجي)، كلمات أو عبارات يحب تظهر، كلمات ممنوعة، شروط التصميم (مكان اللوجو، ألوان ممنوعة، الستايل).\n"
    . "3. لو العميل جاوب على أكتر من حاجة في رد واحد، متسألش عنها تاني.\n"
    . "4. لما تكون جمعت كل التفاصيل المهمة (على الأقل: الاسم، المجال، الوصف، الجمهور، النبرة، الألوان)، اختم بالظبط بالصيغة دي:\n"
    . "رسالة قصيرة تلخص اللي فهمته + السطر [PROFILE] ثم JSON بالمفاتيح: business_name, industry, description, audience, tone, colors, dialect, keywords_use, keywords_avoid, design_rules, summary (ملخص شامل 100-150 كلمة للهوية) ثم [/PROFILE]\n"
    . "5. متكتبش [PROFILE] غير لما تكون فعلًا خلصت كل الأسئلة الأساسية.\n\n"
    . "المحادثة حتى الآن:\n" . $transcript
    . "المساعد:";

$ai = ai_generate($system);
if (!$ai['ok']) {
    if ($cost > 0) {
        credits_add((int) $user['id'], $cost, 'استرداد: مساعد الهوية', null, 'refund');
    }
    json_response(['ok' => false, 'error' => $ai['error']]);
}

$reply = trim($ai['response']);
$profileSaved = false;

// ── هل الايجنت خلّص وطلع البروفايل؟ ──
if (preg_match('/\[PROFILE\](.*?)\[\/PROFILE\]/s', $reply, $m)) {
    $jsonRaw = trim($m[1]);
    $jsonRaw = preg_replace('/^```(json)?|```$/m', '', $jsonRaw);
    $profile = json_decode(trim($jsonRaw), true);

    if (is_array($profile)) {
        $brand = user_brand();
        $fields = ['business_name', 'industry', 'description', 'audience', 'tone', 'colors', 'dialect', 'keywords_use', 'keywords_avoid', 'design_rules'];
        $sets = [];
        $vals = [];
        foreach ($fields as $f) {
            if (!empty($profile[$f]) && is_string($profile[$f])) {
                $sets[] = "`{$f}` = ?";
                $vals[] = mb_substr($profile[$f], 0, 2000);
            }
        }
        if (!empty($profile['summary']) && is_string($profile['summary'])) {
            $sets[] = '`ai_summary` = ?';
            $vals[] = mb_substr($profile['summary'], 0, 4000);
        }
        if ($sets) {
            if ($brand) {
                $vals[] = $brand['id'];
                db_run('UPDATE brand_profiles SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
            } else {
                db_run('INSERT INTO brand_profiles (user_id, business_name) VALUES (?, ?)', [$user['id'], mb_substr($profile['business_name'] ?? 'براندي', 0, 180)]);
                $brand = user_brand();
                if ($brand) {
                    $vals[] = $brand['id'];
                    db_run('UPDATE brand_profiles SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
                }
            }
            $profileSaved = true;
        }
    }

    // شيل البلوك من الرد الظاهر للعميل
    $reply = trim(preg_replace('/\[PROFILE\].*?\[\/PROFILE\]/s', '', $reply));
    if ($reply === '') {
        $reply = 'تمام كده! 🎉';
    }
    if ($profileSaved) {
        $reply .= "\n\n✅ **حفظتلك الهوية كاملة!** راجعها وعدّل أي حاجة من صفحة «هوية البراند» — ودلوقتي أي محتوى أو تصميم هيتبني عليها. يلا نبدأ أول خطة؟ 🗓";
    }
}

$messages[] = ['role' => 'assistant', 'content' => $reply];
agent_save($s['id'], $messages, $profileSaved ? 'done' : 'active');
ai_log_usage((int) $user['id'], 'brand_agent', $ai['model'] ?? 'router', $ai['usage'] ?? []);

json_response([
    'ok' => true,
    'reply' => $reply,
    'profile_saved' => $profileSaved,
]);
