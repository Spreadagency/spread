<?php
/**
 * Spread AI — واجهة التجربة المجانية (اصنع منشورك الآن)
 * بتتنادى من الموقع التعريفي بدون تسجيل دخول.
 * action=ideas → 4 أفكار · action=post → منشور كامل
 * محمية بحد لكل IP، ومش بتخصم كريدت من حد.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/credits.php';
require_once __DIR__ . '/../../includes/rate-limit.php';
require_once __DIR__ . '/../../includes/ai.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// نفس نطاق الموقع فقط
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url(APP_URL, PHP_URL_HOST)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'origin not allowed']));
}

function trial_out(array $d): void
{
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if (function_exists('decode_b64_fields')) {
    decode_b64_fields();
}

if (get_setting('trial_enabled', '1') !== '1') {
    trial_out(['ok' => false, 'error' => 'التجربة المجانية موقوفة حاليًا']);
}

$ip = mb_substr((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
$action = (string) ($_POST['action'] ?? '');

/* ═══════════ 0) مرحلة التصميم: التحقق من الاشتراك (قبل أي تصميم) ═══════════
 * مابيولّدش حاجة ولا بيخصم ولا بيعمل Job — بيرجّع بس: مشترك ولا لأ + لينك الكمال.
 * مشترك ← trial-continue.php بينقل المنشور لحسابه ويفتح مرحلة التصميم جوه المنصة.
 * مش مشترك (أو زائر) ← شاشة الاشتراك على الموقع. */
if ($action === 'gate') {
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $u = $uid ? db_one('SELECT id, status, email_verified_at, approval_status FROM users WHERE id = ?', [$uid]) : null;
    $active = $u && $u['status'] === 'active' && !empty($u['email_verified_at']) && ($u['approval_status'] ?? 'approved') === 'approved';
    $subscribed = $active && function_exists('plan_subscribed') && (!design_requires_subscription() || plan_subscribed($uid));
    trial_out([
        'ok' => true,
        'logged_in' => (bool) $active,
        'subscribed' => (bool) $subscribed,
        'continue_url' => $subscribed && $token !== '' ? url('trial-continue.php?trial=' . $token) : null,
        // المسجّل ومش مشترك ← صفحة الباقات جوه المنصة · الزائر ← قسم الأسعار في الموقع
        'packages_url' => $active ? url('packages.php') : null,
    ]);
}

/* ═══════════ 1) توليد الأفكار ═══════════ */
if ($action === 'ideas') {
    $perHour = max(1, (int) get_setting('trial_per_ip_hour', 5));
    if (!rate_limit('trial_ideas', 'ip' . $ip, $perHour, 3600)) {
        trial_out(['ok' => false, 'error' => 'جرّبت كذا مرة خلال ساعة — سجّل حساب مجاني وكمّل من جوه المنصة 🙂', 'signup' => true]);
    }

    $biz = [
        'business_name' => mb_substr(trim((string) ($_POST['business_name'] ?? '')), 0, 200),
        'industry'      => mb_substr(trim((string) ($_POST['industry'] ?? '')), 0, 150),
        'audience'      => mb_substr(trim((string) ($_POST['audience'] ?? '')), 0, 300),
        'services'      => mb_substr(trim((string) ($_POST['services'] ?? '')), 0, 1000),
        'tone'          => mb_substr(trim((string) ($_POST['tone'] ?? 'ودّي')), 0, 80),
        'dialect'       => in_array($_POST['dialect'] ?? '', ['egyptian', 'msa'], true) ? $_POST['dialect'] : 'egyptian',
        'goal'          => mb_substr(trim((string) ($_POST['goal'] ?? '')), 0, 150),
    ];
    if ($biz['business_name'] === '' || $biz['industry'] === '') {
        trial_out(['ok' => false, 'error' => 'اكتب اسم البيزنس والمجال على الأقل']);
    }

    $count = max(3, min(6, (int) get_setting('trial_ideas_count', 4)));
    $dialectLabel = $biz['dialect'] === 'egyptian' ? 'العامية المصرية' : 'العربية الفصحى المبسّطة';

    $prompt = "أنت استراتيجي محتوى سوشيال ميديا محترف.\n"
        . "اقترح {$count} أفكار منشورات متنوعة ومحددة للبيزنس ده:\n\n"
        . "- الاسم: {$biz['business_name']}\n"
        . "- المجال: {$biz['industry']}\n"
        . ($biz['audience'] !== '' ? "- الجمهور: {$biz['audience']}\n" : '')
        . ($biz['services'] !== '' ? "- الخدمات/المنتجات: {$biz['services']}\n" : '')
        . ($biz['goal'] !== '' ? "- الهدف: {$biz['goal']}\n" : '')
        . "- النبرة: {$biz['tone']}\n"
        . "- اللهجة: {$dialectLabel}\n\n"
        . "خليها متنوعة (توعوي · إعلاني · تفاعلي · قصصي) ومناسبة للمجال ده تحديدًا — مش أفكار عامة.\n"
        . "أرجع النتيجة بصيغة JSON فقط بدون أي كلام قبلها أو بعدها:\n"
        . '{"ideas":[{"title":"عنوان قصير جذاب","angle":"نوع المحتوى","desc":"وصف الفكرة في سطر واحد"}]}';

    $ai = ai_generate($prompt);
    if (!$ai['ok']) {
        trial_out(['ok' => false, 'error' => 'المولّد مش متاح دلوقتي — جرّب كمان شوية']);
    }

    $raw = trim((string) $ai['response']);
    $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw);
    if (preg_match('/\{.*\}/s', $raw, $m)) {
        $raw = $m[0];
    }
    $parsed = json_decode($raw, true);
    $ideas = $parsed['ideas'] ?? [];

    // احتياطي: لو الموديل ما التزمش بالـ JSON، نقسّم بالأسطر
    if (!is_array($ideas) || !$ideas) {
        $ideas = [];
        foreach (preg_split('/[\r\n]+/', trim((string) $ai['response'])) as $line) {
            $line = trim(preg_replace('/^[\-\*\d\.\)\s]+/u', '', $line));
            if (mb_strlen($line) > 12) {
                $ideas[] = ['title' => mb_substr($line, 0, 90), 'angle' => '', 'desc' => ''];
            }
            if (count($ideas) >= $count) break;
        }
    }
    $ideas = array_slice($ideas, 0, $count);
    if (!$ideas) {
        trial_out(['ok' => false, 'error' => 'تعذّر توليد الأفكار — جرّب تاني']);
    }

    $token = bin2hex(random_bytes(20));
    db_insert(
        'INSERT INTO trial_sessions (token, business_name, industry, audience, services, tone, dialect, goal, ideas_json, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$token, $biz['business_name'], $biz['industry'], $biz['audience'], $biz['services'],
         $biz['tone'], $biz['dialect'], $biz['goal'], json_encode($ideas, JSON_UNESCAPED_UNICODE), $ip]
    );

    trial_out(['ok' => true, 'token' => $token, 'ideas' => $ideas]);
}

/* ═══════════ 2) توليد المنشور من الفكرة المختارة ═══════════ */
if ($action === 'post') {
    $token = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));
    $t = $token ? db_one('SELECT * FROM trial_sessions WHERE token = ?', [$token]) : null;
    if (!$t) {
        trial_out(['ok' => false, 'error' => 'الجلسة انتهت — ابدأ من الأول']);
    }
    if (!rate_limit('trial_post', 'ip' . $ip, max(1, (int) get_setting('trial_per_ip_hour', 5)) * 2, 3600)) {
        trial_out(['ok' => false, 'error' => 'وصلت للحد — سجّل حساب مجاني وكمّل من جوه', 'signup' => true]);
    }

    $ideas = json_decode((string) $t['ideas_json'], true) ?: [];
    $idx = (int) ($_POST['idea_index'] ?? 0);
    $idea = $ideas[$idx] ?? null;
    if (!$idea) {
        trial_out(['ok' => false, 'error' => 'الفكرة غير موجودة']);
    }

    $dialectLabel = $t['dialect'] === 'egyptian' ? 'العامية المصرية' : 'العربية الفصحى المبسّطة';
    $prompt = "أنت كاتب محتوى تسويقي محترف. اكتب منشور سوشيال ميديا كامل وجاهز للنشر.\n\n"
        . "البيزنس: {$t['business_name']} — {$t['industry']}\n"
        . ($t['audience'] ? "الجمهور: {$t['audience']}\n" : '')
        . ($t['services'] ? "الخدمات: {$t['services']}\n" : '')
        . "النبرة: {$t['tone']} · اللهجة: {$dialectLabel}\n\n"
        . "الفكرة المطلوبة: " . ($idea['title'] ?? '') . "\n"
        . (!empty($idea['desc']) ? "تفاصيلها: {$idea['desc']}\n" : '')
        . "\nالمنشور يكون متوسط الطول، يبدأ بجملة تشد الانتباه، ومناسب لفيسبوك وانستجرام.\n"
        . "أرجع بالصيغة دي بالظبط:\n"
        . "[CONTENT]\nنص المنشور\n[/CONTENT]\n[HASHTAGS]\nالهاشتاجات\n[/HASHTAGS]\n[CTA]\nجملة الحث على التفاعل\n[/CTA]";

    $ai = ai_generate($prompt);
    if (!$ai['ok']) {
        trial_out(['ok' => false, 'error' => 'المولّد مش متاح دلوقتي — جرّب كمان شوية']);
    }

    $r = (string) $ai['response'];
    $grab = static function (string $tag) use ($r): string {
        return preg_match('/\[' . $tag . '\](.*?)\[\/' . $tag . '\]/s', $r, $m) ? trim($m[1]) : '';
    };
    $content = $grab('CONTENT') ?: trim(preg_replace('/\[\/?[A-Z]+\]/', '', $r));
    $hashtags = $grab('HASHTAGS');
    $cta = $grab('CTA');

    db_run(
        'UPDATE trial_sessions SET chosen_idea = ?, generated_text = ?, hashtags = ?, cta = ? WHERE id = ?',
        [json_encode($idea, JSON_UNESCAPED_UNICODE), mb_substr($content, 0, 8000), mb_substr($hashtags, 0, 600), mb_substr($cta, 0, 400), $t['id']]
    );

    trial_out([
        'ok' => true,
        'content' => $content,
        'hashtags' => $hashtags,
        'cta' => $cta,
        'business_name' => $t['business_name'],
    ]);
}

trial_out(['ok' => false, 'error' => 'إجراء غير معروف']);
