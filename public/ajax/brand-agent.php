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

require_login();
require_csrf();
decode_b64_fields();

$user = current_user();
$action = $_POST['action'] ?? 'load';

const AGENT_GREETING = "أهلًا بيك! 👋 أنا مساعد الهوية — هسألك شوية أسئلة بسيطة وأظبطلك هوية براندك كاملة على المنصة.\n\nخلينا نبدأ: **إيه اسم البيزنس بتاعك، وشغال في أنهي مجال؟** (مثلًا: عيادة أسنان، مطعم، براند ملابس...)";

function agent_session(int $userId): array
{
    $s = db_one('SELECT * FROM agent_sessions WHERE user_id = ? AND status = "active" ORDER BY id DESC LIMIT 1', [$userId]);
    if (!$s) {
        $messages = [['role' => 'assistant', 'content' => AGENT_GREETING]];
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

$system = "أنت «مساعد الهوية» — خبير استراتيجية براندات مصري ودود على منصة محتوى بالذكاء الاصطناعي. مهمتك تبني هوية العميل بالكامل عبر المحادثة.\n\n"
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
