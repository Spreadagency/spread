<?php
/**
 * Spread AI — البداية السريعة (خريطة التطوير · المرحلة 1)
 *   التسجيل ← دخول تلقائي ← public/start.php (لينكات ← 5 أسئلة ← الهوية + أول بوست)
 *
 * كله ورا الإعداد `onboarding_quick` (مقفول افتراضيًا) — ومع «الموافقة اليدوية» بيتقفل لوحده.
 * الحساب اللي لسه ما أكّدش إيميله بيجرّب في صفحات محددة بس ولمدة محدودة؛ النشر والشراء وباقي
 * المنصة لسه محتاجين إيميل مؤكد.
 */

/** الصفحات اللي الحساب غير المؤكد يقدر يفتحها (تجربة بس — مفيش نشر ولا دفع ولا ربط صفحات) */
require_once __DIR__ . '/onb-questions.php';   // أسئلة الهوية (الأدمن بيزوّدها ويشيلها)

const ONB_UNVERIFIED_PAGES = [
    'start.php', 'dashboard.php', 'create-content.php', 'content-view.php', 'brand-kit.php',
    'generate-content.php', 'generate-stream.php', 'save-content.php', 'regenerate-content.php',
];

/** الـ API الوحيد اللي الحساب غير المؤكد يقدر يبعتله POST (بناء الهوية) */
const ONB_UNVERIFIED_API = ['brand-kit.php', 'brand.php'];

function onb_enabled(): bool
{
    if (!function_exists('get_setting')) return false;
    return get_setting('onboarding_quick', '0') === '1' && get_setting('manual_approval', '0') !== '1';
}

/** الحساب لسه في مهلة التجربة قبل تأكيد الإيميل؟ */
function onb_in_window(array $u): bool
{
    $days = max(1, min(30, (int) get_setting('onboarding_unverified_days', '3')));
    $created = strtotime((string) ($u['created_at'] ?? '')) ?: 0;
    return $created > time() - $days * 86400;
}

/** الحساب غير المؤكد مسموحله بالصفحة دي؟ */
function onb_unverified_ok(array $u, ?string $script = null): bool
{
    if (!empty($u['email_verified_at'])) return true;
    if (!onb_enabled() || !onb_in_window($u)) return false;
    $script = $script ?? basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return in_array($script, ONB_UNVERIFIED_PAGES, true);
}

/** نفس الفحص للـ API: القراءة (GET) مسموحة، والكتابة لبناء الهوية بس */
function onb_unverified_api_ok(array $u, string $method, string $script): bool
{
    if (!empty($u['email_verified_at'])) return true;
    if (!onb_enabled() || !onb_in_window($u)) return false;
    return $method === 'GET' || in_array($script, ONB_UNVERIFIED_API, true);
}

/** أرقام الموبايل بصيغها المختلفة (010… · 2010… · +2010…) */
function onb_phone_variants(string $phone): array
{
    $d = preg_replace('/\D+/', '', $phone);
    if ($d === '') return [];
    $local = preg_replace('/^(?:00)?20/', '0', $d);
    return array_values(array_unique([$phone, $d, '+' . $d, $local, '2' . $local, '+2' . $local]));
}

/** قيمة هدية البداية = تكلفة قراءة اللينك + مسودة الهوية + أول بوست (من إعدادات التكلفة الحالية) */
function onb_gift_amount(): int
{
    if (get_setting('onboarding_gift', '1') !== '1') return 0;
    return max(0, (int) get_setting('brand_analyze_cost', 1))
         + max(0, (int) get_setting('brand_kit_draft_cost', 2))
         + max(0, (int) get_setting('brand_kit_ai_cost', 1))        // ألوان مقترحة (رحلة الهوية)
         + max(0, (int) get_setting('brand_kit_persona_cost', '1'))  // العملاء المثاليين (رحلة الهوية)
         + max(0, (int) cost_for('content_generation_cost'));
}

/**
 * هدية البداية: مرة واحدة لكل حساب ولكل رقم موبايل.
 * بيرجّع عدد الكريدت اللي اتضاف (0 = مفيش هدية أو اتاخدت قبل كده).
 */
function onb_gift_grant(int $uid): int
{
    if (!onb_enabled() && !onb_questions_on()) return 0;   // البداية السريعة · أو رحلة الهوية
    $amount = onb_gift_amount();
    if ($amount <= 0) return 0;
    $lock = 'onb_gift_' . $uid;
    try {
        if ((int) (db_one('SELECT GET_LOCK(?, 3) l', [$lock])['l'] ?? 0) !== 1) return 0;
    } catch (\Throwable $e) {
        return 0;
    }
    $given = 0;
    try {
        $mine = db_one('SELECT id FROM credit_transactions WHERE user_id = ? AND reference_type = "onboarding_gift" LIMIT 1', [$uid]);
        $dup = false;
        if (!$mine) {
            $u = db_one('SELECT phone FROM users WHERE id = ?', [$uid]);
            $vars = onb_phone_variants((string) ($u['phone'] ?? ''));
            if ($vars) {
                $ph = implode(',', array_fill(0, count($vars), '?'));
                $dup = (bool) db_one("SELECT t.id FROM credit_transactions t JOIN users u ON u.id = t.user_id
                                      WHERE t.reference_type = 'onboarding_gift' AND u.id <> ? AND u.phone IN ($ph) LIMIT 1", array_merge([$uid], $vars));
            }
        }
        if (!$mine && !$dup && credits_add($uid, $amount, 'هدية البداية — بناء الهوية وأول بوست', null, 'onboarding_gift', null, null, ['idem_key' => 'onb:u' . $uid])) {
            $given = $amount;
        }
    } catch (\Throwable $e) {
        error_log('[onboarding] gift: ' . $e->getMessage());
    }
    try { db_one('SELECT RELEASE_LOCK(?) l', [$lock]); } catch (\Throwable $e) {}
    return $given;
}

/** العميل خلّص البداية؟ (عنده منشور واحد على الأقل) */
function onb_done(int $uid): bool
{
    return (bool) db_one('SELECT id FROM contents WHERE user_id = ? LIMIT 1', [$uid]);
}

/** متوسط الوقت من التسجيل لأول بوست (دقايق) لآخر N يوم — للوحة الأدمن */
function onb_time_to_first_post(int $days = 30): array
{
    try {
        $rows = db_all('SELECT TIMESTAMPDIFF(SECOND, u.created_at, MIN(c.created_at)) s
                        FROM users u JOIN contents c ON c.user_id = u.id
                        WHERE u.created_at >= (NOW() - INTERVAL ? DAY) GROUP BY u.id', [$days]);
        $signups = (int) (db_one('SELECT COUNT(*) n FROM users WHERE created_at >= (NOW() - INTERVAL ? DAY)', [$days])['n'] ?? 0);
    } catch (\Throwable $e) {
        return ['signups' => 0, 'with_post' => 0, 'median_min' => null];
    }
    $s = array_values(array_filter(array_map(fn($r) => (int) $r['s'], $rows), fn($x) => $x >= 0));
    sort($s);
    $n = count($s);
    return ['signups' => $signups, 'with_post' => $n, 'median_min' => $n ? round($s[intdiv($n, 2)] / 60, 1) : null];
}
