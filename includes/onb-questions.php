<?php
/**
 * Spread AI — أسئلة الهوية في أول دخول (صفحة «ابدأ» · public/start.php)
 *
 * الأسئلة بتتحفظ في الإعداد onb_questions (JSON) — الأدمن بيزوّد ويشيل ويعدّل ويرتّب من
 * admin/onboarding-questions.php. لو الإعداد فاضي ← الأسئلة الخمسة الأصلية زي ما كانت بالحرف.
 *
 * الأنواع: sector (مجال النشاط من قوالب القطاعات) · single (اختيار واحد) · multi (أكتر من اختيار) · text (كتابة)
 * كل سؤال ليه نسخة إنجليزي اختيارية (title_en · sub_en · opts_en · ph_en) للعميل اللي فاتح المنصة بالإنجليزي.
 * role = topic: إجابته موضوع أول بوست.
 *
 * الإجابات بتروح لمسودة الهوية (api/brand-kit.php action=draft) بنفس الشكل: [عنوان السؤال => الإجابة].
 */

const ONB_Q_KINDS = ['sector' => 'مجال النشاط (من القطاعات)', 'single' => 'اختيار واحد', 'multi' => 'أكتر من اختيار', 'text' => 'كتابة'];
const ONB_Q_MAX = 12;

/** الأسئلة الأصلية (زي ما كانت مكتوبة في start.php) */
function onb_q_defaults(): array
{
    return [
        ['id' => 'sector', 'kind' => 'sector', 'key' => 'نشاطك إيه', 'title' => 'نشاطك إيه؟', 'sub' => 'بنختار طريقة الكتابة المناسبة لمجالك.', 'active' => true],
        ['id' => 'audience', 'kind' => 'multi', 'key' => 'بتبيع لمين', 'title' => 'بتبيع لمين؟', 'sub' => 'اختار واحد أو أكتر.',
         'opts' => ['سيدات', 'رجال', 'شباب', 'أسر وأطفال', 'شركات وأصحاب أعمال', 'كبار السن'], 'active' => true],
        ['id' => 'topic', 'kind' => 'text', 'key' => 'أهم خدمة أو منتج عايز تبيعه دلوقتي', 'title' => 'أهم خدمة أو منتج عايز تبيعه دلوقتي؟',
         'sub' => 'اكتبه في كلمتين — أول بوست هيبقى عنه.', 'ph' => 'مثلًا: تبييض الأسنان', 'role' => 'topic', 'active' => true],
        ['id' => 'voice', 'kind' => 'single', 'key' => 'أسلوب الكلام مع العملاء', 'title' => 'بتكلم عملاءك بأنهي أسلوب؟', 'sub' => 'اختار الجملة الأقرب ليك.', 'block' => true,
         'opts' => ['«نسعد بخدمتكم ونلتزم بأعلى معايير الجودة.» — رسمي ومحترم',
                    '«تعالى نساعدك تختار الأنسب ليك، من غير تعقيد.» — ودود وبسيط',
                    '«جاهز تغيّر الروتين؟ يلا بينا! 😄» — مرح وخفيف'], 'active' => true],
        ['id' => 'contact', 'kind' => 'multi', 'key' => 'العميل بيتواصل إزاي', 'title' => 'العميل يتواصل معاك إزاي؟', 'sub' => 'اختار واحد أو أكتر.',
         'opts' => ['واتساب', 'اتصال تليفون', 'رسايل الصفحة', 'زيارة المكان', 'الموقع الإلكتروني'], 'active' => true],
    ];
}

/** صفحة «ابدأ» بأسئلة الهوية بتظهر لأي حساب جديد بعد أول دخول (حتى من غير «البداية السريعة») */
function onb_questions_on(): bool
{
    // صفحات زي login.php مابتحمّلش credits.php (get_setting) — من غيرها الفحص كان بيرجع «مقفول» غلط
    if (!function_exists('get_setting') && function_exists('db_one')) require_once __DIR__ . '/credits.php';
    return function_exists('get_setting') && get_setting('onb_questions_on', '1') === '1';
}

/** صفحة «ابدأ» شغالة؟ (البداية السريعة · أو أسئلة الهوية) */
function onb_start_enabled(): bool
{
    return (function_exists('onb_enabled') && onb_enabled()) || onb_questions_on();
}

/** كل الأسئلة (للأدمن) — بعد التنضيف */
function onb_q_all(): array
{
    $raw = function_exists('get_setting') ? (string) get_setting('onb_questions', '') : '';
    $list = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($list)) return onb_q_defaults();
    $out = [];
    foreach ($list as $q) if (is_array($q) && ($c = onb_q_clean($q))) $out[] = $c;
    return $out;
}

/** الأسئلة الشغالة بس (لصفحة «ابدأ») */
function onb_q_active(): array
{
    return array_values(array_filter(onb_q_all(), fn($q) => !empty($q['active'])));
}

/** تنضيف سؤال واحد (أطوال · أنواع · اختيارات) — null لو ناقص */
function onb_q_clean(array $q): ?array
{
    $s = fn($v, int $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v)), 0, $n);
    $lines = function ($v, int $max = 12): array {
        $a = is_array($v) ? $v : preg_split('/\r?\n/', (string) $v);
        $a = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 160), $a), fn($x) => $x !== ''));
        return array_slice(array_values(array_unique($a)), 0, $max);
    };
    $kind = isset(ONB_Q_KINDS[$q['kind'] ?? '']) ? $q['kind'] : 'text';
    $title = $s($q['title'] ?? '', 160);
    if (mb_strlen($title) < 2) return null;
    $opts = in_array($kind, ['single', 'multi'], true) ? $lines($q['opts'] ?? []) : [];
    if (in_array($kind, ['single', 'multi'], true) && count($opts) < 2) return null;   // الاختيار محتاج اختيارين على الأقل
    $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($q['id'] ?? ''))) ?: 'q' . substr(md5($title . microtime()), 0, 6);
    $out = [
        'id' => mb_substr($id, 0, 24), 'kind' => $kind, 'title' => $title,
        'key' => $s(($q['key'] ?? '') ?: rtrim($title, '؟?'), 120),
        'sub' => $s($q['sub'] ?? '', 200), 'active' => !empty($q['active']),
    ];
    if ($opts) $out['opts'] = $opts;
    if (!empty($q['block']) && $kind !== 'text') $out['block'] = true;
    if (($q['ph'] ?? '') !== '' && in_array($kind, ['text', 'sector'], true)) $out['ph'] = $s($q['ph'], 120);
    if (($q['role'] ?? '') === 'topic' && $kind === 'text') $out['role'] = 'topic';
    // النسخة الإنجليزي (اختيارية)
    foreach (['title_en' => 160, 'sub_en' => 200, 'ph_en' => 120] as $k => $n) if (trim((string) ($q[$k] ?? '')) !== '') $out[$k] = $s($q[$k], $n);
    if ($opts && ($oe = $lines($q['opts_en'] ?? []))) $out['opts_en'] = array_slice($oe, 0, count($opts));
    return $out;
}

/** حفظ القايمة كلها (ترتيبها زي ما هي) — موضوع أول بوست سؤال واحد بس */
function onb_q_save(array $list): array
{
    $out = [];
    $topic = false;
    foreach (array_slice($list, 0, ONB_Q_MAX) as $q) {
        if (!is_array($q) || !($c = onb_q_clean($q))) continue;
        if (($c['role'] ?? '') === 'topic') {
            if ($topic) unset($c['role']);
            $topic = true;
        }
        $out[] = $c;
    }
    // ids مايتكرروش
    $seen = [];
    foreach ($out as &$c) {
        while (isset($seen[$c['id']])) $c['id'] .= 'x';
        $seen[$c['id']] = true;
    }
    unset($c);
    set_setting('onb_questions', json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

/**
 * السؤال بلغة العميل (للجافاسكريبت): العنوان والشرح والاختيارات المعروضة — والإجابة اللي بتتبعت للهوية
 * بتفضل بنص الاختيار المعروض (عربي أو إنجليزي) علشان الـ AI يفهمها في الحالتين.
 */
function onb_q_for_client(array $q, bool $en): array
{
    if (!isset($q['kind'], $q['key'])) return [];
    $pick = fn(string $k) => $en && trim((string) ($q[$k . '_en'] ?? '')) !== '' ? (string) $q[$k . '_en'] : ($en ? __((string) ($q[$k] ?? ''), [], 'en') : (string) ($q[$k] ?? ''));
    $opts = (array) ($q['opts'] ?? []);
    if ($en) {
        $oe = (array) ($q['opts_en'] ?? []);
        foreach ($opts as $i => $o) $opts[$i] = trim((string) ($oe[$i] ?? '')) !== '' ? (string) $oe[$i] : __((string) $o, [], 'en');
    }
    return array_filter([
        'key' => (string) $q['key'], 'kind' => (string) $q['kind'], 'title' => $pick('title'), 'sub' => $pick('sub'),
        'ph' => isset($q['ph']) || isset($q['ph_en']) ? $pick('ph') : null,
        'opts' => $opts ?: null, 'multi' => $q['kind'] === 'multi' ?: null, 'block' => !empty($q['block']) ?: null,
        'topic' => ($q['role'] ?? '') === 'topic' ?: null,
    ], fn($v) => $v !== null);
}

/**
 * بعد الدخول: الحساب اللي لسه ماعملش أول بوست بيروح لـ «ابدأ» مرة واحدة في الجلسة
 * (مش لو الأدمن داخل الحساب · وفيه «تخطّي» في الصفحة نفسها).
 */
function onb_should_offer(int $uid): bool
{
    if (!onb_questions_on() || !empty($_SESSION['onb_offered']) || !empty($_SESSION['impersonator_admin_id'])) return false;
    $_SESSION['onb_offered'] = 1;
    // رحلة الهوية الجديدة: أي عميل (جديد أو حالي) لسه ما اعتمدش هويته منها بيتعرض عليه مرة في كل دخول
    return !ij_done($uid);
}

/* ═══════════ رحلة الهوية (public/start.php) ═══════════
 * العلامة في brand_kits.data._journey_done (مفيش أعمدة جديدة).
 */

/** العميل خلّص رحلة الهوية الجديدة (اعتمد الهوية)؟ */
function ij_done(int $uid): bool
{
    static $c = [];
    if (isset($c[$uid])) return $c[$uid];
    try {
        $r = db_one('SELECT data FROM brand_kits WHERE user_id = ? LIMIT 1', [$uid]);
        $d = $r ? json_decode((string) $r['data'], true) : null;
        return $c[$uid] = is_array($d) && !empty($d['_journey_done']);
    } catch (\Throwable $e) {
        return $c[$uid] = true;   // لو فيه مشكلة مانحبسش العميل في الرحلة
    }
}

/**
 * وجهة العميل بعد الدخول/التسجيل: رحلة الهوية لو لسه ما اعتمدش هويته (جديد أو حالي)، وإلا $fallback.
 * بتتنادى من كل طرق الدخول (عادي · كود التحقق · جوجل · التسجيل بالبداية السريعة) — حتى اللي جاي
 * من «اصنع منشورك» في الصفحة الرئيسية: منشوره بيتحفظ، والرحلة بتكمّل من البيانات اللي اتقرت منه.
 */
function ij_landing(int $uid, string $fallback = 'dashboard.php'): string
{
    if ($uid <= 0 || !onb_questions_on() || !empty($_SESSION['impersonator_admin_id']) || ij_done($uid)) return $fallback;
    $_SESSION['onb_offered'] = 1;   // الرئيسية مترجّعهوش تاني في نفس الجلسة (فيها «تخطّي»)
    return 'start.php';
}

/** علامة «خلّص الرحلة» + اعتماد الهوية */
function ij_mark_done(int $uid): void
{
    if (function_exists('bk_get')) bk_get($uid);   // يتأكد إن فيه Kit
    $r = db_one('SELECT id, data FROM brand_kits WHERE user_id = ? LIMIT 1', [$uid]);
    if (!$r) return;
    $d = json_decode((string) $r['data'], true) ?: [];
    $d['_journey_done'] = date('c');
    db_run('UPDATE brand_kits SET data = ? WHERE id = ?', [json_encode($d, JSON_UNESCAPED_UNICODE), $r['id']]);
    db_run('UPDATE brand_profiles SET brand_approved_at = COALESCE(brand_approved_at, NOW()) WHERE user_id = ?', [$uid]);
}

/** أسئلة الأدمن جوه الرحلة: المجال والأسلوب ليهم شاشات خاصة في الرحلة، فمابيتكرروش */
function ij_questions(): array
{
    return array_values(array_filter(onb_q_active(), fn($q) => ($q['kind'] ?? '') !== 'sector' && ($q['id'] ?? '') !== 'voice'));
}
