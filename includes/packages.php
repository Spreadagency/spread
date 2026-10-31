<?php
/**
 * Spread AI — الباقات الجديدة (المرحلة 3)
 *
 * المدد والأسعار (package_prices): لكل باقة مدد (شهري · 3 شهور · سنة · أي عدد أيام) — لكل مدة سعرها وكريدتها وتصميماتها
 *   والكريدت والتصميمات بينزلوا مرة واحدة أول المدة.
 * الحد الأسبوعي (تراكمي): في الأسبوع رقم N العميل يقدر يكون استخدم لحد N × الحد — اللي ماتصرفش بيفضل للأيام اللي بعدها.
 *   الإضافات المشتراة بتزوّد الحد بقيمتها.
 * مستوى الجودة · البحث مسموح ولا لأ: من الباقة وقت الاشتراك (لقطة في user_plans).
 * الإضافات (addon_packs):
 *   تصميمات زيادة ← quota_overrides لحد آخر الباقة
 *   كريدت مساعد ← دفعة كريدت صالحة validity_days يوم (addon_until) — لو الباقة خلصت بتقف، ولما يجدد بتكمل بالأيام اللي فاضلة
 * باقة التسجيل: الأدمن بيختار باقة (ومدة) بتتفعّل مجانًا لكل حساب جديد — بدل الكريدت الترحيبي القديم.
 */
require_once __DIR__ . '/credits.php';

const PKG_TIERS = ['standard' => 'عادي', 'pro' => 'احترافي', 'premium' => 'بريميوم'];

function pkg_v2_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM package_prices LIMIT 1');
        db_one('SELECT id FROM addon_packs LIMIT 1');
        db_one('SELECT weekly_credits FROM user_plans LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

function pkg_tiers(): array
{
    return PKG_TIERS;
}

/** «شهر» · «3 شهور» · «سنة» · «45 يوم» */
function pkg_duration_text(int $days): string
{
    $m = [30 => 'شهر', 31 => 'شهر', 60 => 'شهرين', 90 => '3 شهور', 180 => '6 شهور', 365 => 'سنة', 366 => 'سنة'];
    return $m[$days] ?? ($days . ' يوم');
}

/* ═══════════════════ المدد والأسعار ═══════════════════ */

function pkg_prices(int $pkgId, bool $activeOnly = true): array
{
    if (!pkg_v2_ready()) return [];
    return db_all('SELECT * FROM package_prices WHERE package_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, days, id', [$pkgId]);
}

/**
 * الباقة بمدة معيّنة: السعر والكريدت والمدة والحصص من صف المدة (أو أول مدة)
 * @return array|null null = المدة مش موجودة/مش متاحة
 */
function pkg_variant(array $pkg, ?int $priceId = null): ?array
{
    $pkg += ['price_id' => null, 'duration_label' => pkg_duration_text((int) ($pkg['validity_days'] ?? 30)), 'compare_egp' => null];
    if (!pkg_v2_ready()) return $pkg;
    $prices = pkg_prices((int) $pkg['id']);
    if (!$prices) return $priceId ? null : $pkg;   // باقة قديمة من غير مدد ← بسعرها زي ما هي
    $p = null;
    foreach ($prices as $x) if (!$priceId || (int) $x['id'] === $priceId) { $p = $x; break; }
    if (!$p) return null;
    $q = function_exists('plan_quotas_decode') ? plan_quotas_decode($pkg['quotas_json'] ?? null) : [];
    $q['designs'] = (int) $p['designs'];
    $q['research'] = (int) $p['research'];
    return array_merge($pkg, [
        'price_egp' => (float) $p['price_egp'], 'validity_days' => max(1, (int) $p['days']), 'credits' => (int) $p['credits'] > 0 ? (int) $p['credits'] : (int) $pkg['credits'],
        'quotas_json' => json_encode($q), 'price_id' => (int) $p['id'], 'duration_label' => (string) $p['label'],
        'compare_egp' => $p['compare_egp'] !== null ? (float) $p['compare_egp'] : null,
    ]);
}

/* ═══════════════════ الإضافات ═══════════════════ */

function addon_packs(bool $activeOnly = true): array
{
    if (!pkg_v2_ready()) return [];
    return db_all('SELECT * FROM addon_packs' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY kind, sort_order, price_egp, id');
}

function addon_pack(int $id, bool $activeOnly = true): ?array
{
    if (!pkg_v2_ready() || $id <= 0) return null;
    return db_one('SELECT * FROM addon_packs WHERE id = ?' . ($activeOnly ? ' AND is_active = 1' : ''), [$id]);
}

function addon_label(array $a): string
{
    return $a['kind'] === 'designs' ? '🎨 ' . (int) $a['amount'] . ' تصميم زيادة' : '◇ ' . (int) $a['amount'] . ' كريدت مساعد';
}

/** الإضافة بتشتغل مع باقة شغالة بس ← null = تمام */
function addon_blocked(int $uid): ?string
{
    if (!function_exists('plan_active')) return 'الباقات مش متاحة';
    $p = plan_active($uid);
    if (!empty($p['synthetic']) || empty($p['id'])) return 'الإضافات بتشتغل مع باقة شغالة — اشترك في باقة الأول';
    return null;
}

/** اعتماد طلب إضافة (من billing_approve) */
function addon_apply(array $req, ?int $adminId): array
{
    $a = addon_pack((int) $req['addon_id'], false);
    if (!$a) return ['ok' => false, 'error' => 'الإضافة اتمسحت'];
    $uid = (int) $req['user_id'];
    $plan = plan_active($uid);
    $ends = !empty($plan['ends']) ? (string) $plan['ends'] : date('Y-m-d H:i:s', strtotime('+30 days'));
    if ($a['kind'] === 'designs') {
        if (db_one('SELECT id FROM quota_overrides WHERE user_id = ? AND reason = ?', [$uid, 'إضافة — طلب #' . (int) $req['id']])) return ['ok' => true];
        db_insert('INSERT INTO quota_overrides (user_id, unit, extra, reason, expires_at, created_by) VALUES (?, "designs", ?, ?, ?, ?)',
            [$uid, (int) $a['amount'], 'إضافة — طلب #' . (int) $req['id'], $ends, $adminId]);
        return ['ok' => true];
    }
    // الكريدت المساعد: صلاحية المحفظة ماتتمدش بعد آخر الباقة (الإضافة بتقف معاها) — والدفعة صالحة validity_days يوم استخدام
    $left = max(1, (int) ceil((strtotime($ends) - time()) / 86400));
    $ok = credits_add($uid, (int) $a['amount'], 'إضافة: ' . addon_label($a) . ' — طلب دفع #' . (int) $req['id'], $adminId, 'payment_request', (int) $req['id'], $left,
        ['source' => (float) $req['final_amount'] > 0 ? 'paid' : 'promo', 'amount_egp' => (float) $req['final_amount'], 'idem_key' => 'pa:' . (int) $req['id'],
         'addon_days' => max(1, (int) $a['validity_days'])]);
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'تعذّر إضافة الكريدت'];
}

/** الإضافات اللي اتضافت في الدورة الحالية (عشان الحد الأسبوعي يزيد بيها) */
function addon_totals(int $uid, string $since): array
{
    $cr = 0;
    $ds = 0;
    try {
        $cr = (int) (db_one('SELECT COALESCE(SUM(credits), 0) n FROM credit_lots WHERE user_id = ? AND addon_until IS NOT NULL AND created_at >= ?', [$uid, $since])['n'] ?? 0);
        $ds = (int) (db_one('SELECT COALESCE(SUM(extra), 0) n FROM quota_overrides WHERE user_id = ? AND unit = "designs" AND reason LIKE "إضافة — طلب%" AND created_at >= ?', [$uid, $since])['n'] ?? 0);
    } catch (\Throwable $e) {}
    return ['credits' => $cr, 'designs' => $ds];
}

/* ═══════════════════ الحد الأسبوعي (تراكمي) ═══════════════════ */

/** رقم الأسبوع في الدورة (1 من أول يوم) ومعاد بداية الأسبوع الجاي */
function pkg_week(array $plan): array
{
    $start = strtotime((string) $plan['starts']) ?: time();
    $n = (int) floor(max(0, time() - $start) / 604800) + 1;
    return [$n, date('Y-m-d', $start + $n * 604800)];
}

/** حالة الحد الأسبوعي للكريدت ← null = مفيش حد */
function pkg_weekly_credits(int $uid): ?array
{
    if (!pkg_v2_ready() || !function_exists('plan_active')) return null;
    $plan = plan_active($uid);
    $w = (int) ($plan['weekly_credits'] ?? 0);
    if ($w <= 0 || !empty($plan['synthetic'])) return null;
    [$n, $next] = pkg_week($plan);
    $allow = $w * $n + addon_totals($uid, (string) $plan['starts'])['credits'];
    $r = db_one('SELECT COALESCE(SUM(IF(action_type = "consume", amount, 0)), 0) c, COALESCE(SUM(IF(action_type = "add" AND reference_type = "refund", amount, 0)), 0) r
                 FROM credit_transactions WHERE user_id = ? AND created_at >= ?', [$uid, $plan['starts']]);
    $used = max(0, (int) $r['c'] - (int) $r['r']);
    return ['weekly' => $w, 'week' => $n, 'allow' => $allow, 'used' => $used, 'left' => max(0, $allow - $used), 'next' => $next];
}

/** الخصم ده هيعدّي حد الأسبوع؟ ← رسالة (null = تمام) */
function pkg_weekly_block(int $uid, int $amount): ?string
{
    $s = pkg_weekly_credits($uid);
    if (!$s || $s['used'] + $amount <= $s['allow']) return null;
    return 'وصلت لحد الأسبوع ده في باقتك (فاضل ' . $s['left'] . ' كريدت) — الحد بيزيد ' . $s['weekly'] . ' كريدت يوم '
        . (function_exists('fmt_date') ? fmt_date($s['next']) : $s['next']) . '، أو زوّد بباقة كريدت مساعدة.';
}

/** حالة الحد الأسبوعي للتصميمات ← null = مفيش حد */
function pkg_weekly_designs(int $uid): ?array
{
    if (!pkg_v2_ready() || !function_exists('plan_active')) return null;
    $plan = plan_active($uid);
    $w = (int) ($plan['weekly_designs'] ?? 0);
    if ($w <= 0 || !empty($plan['synthetic'])) return null;
    [$n, $next] = pkg_week($plan);
    $allow = $w * $n + addon_totals($uid, (string) $plan['starts'])['designs'];
    $to = date('Y-m-d H:i:s', max(time() + 60, strtotime((string) $plan['ends'])));
    $used = plan_used($uid, 'designs', (string) $plan['starts'], $to);
    return ['weekly' => $w, 'week' => $n, 'allow' => $allow, 'used' => $used, 'left' => max(0, $allow - $used), 'next' => $next];
}

/** البحث في باقة العميل؟ */
function pkg_research_allowed(int $uid): bool
{
    if (!pkg_v2_ready() || !function_exists('plan_active')) return true;
    $p = plan_active($uid);
    return !empty($p['synthetic']) || (int) ($p['allow_research'] ?? 1) === 1;
}

/** مستوى الجودة في باقة العميل */
function pkg_quality_tier(int $uid): string
{
    if (!pkg_v2_ready() || !function_exists('plan_active')) return 'standard';
    $t = (string) (plan_active($uid)['quality_tier'] ?? 'standard');
    return isset(PKG_TIERS[$t]) ? $t : 'standard';
}

/* ═══════════════════ باقة التسجيل ═══════════════════ */

/** الباقة المجانية للحسابات الجديدة (لو الأدمن اختارها) */
function pkg_signup_package(): ?array
{
    $id = (int) get_setting('signup_package_id', '');
    if ($id <= 0) return null;
    $pkg = db_one('SELECT * FROM credit_packages WHERE id = ?', [$id]);
    if (!$pkg) return null;
    return pkg_variant($pkg, (int) get_setting('signup_package_price_id', '') ?: null) ?? pkg_variant($pkg);
}

/** الكريدت الترحيبي في المحفظة وقت التسجيل (لو فيه باقة تسجيل ← الكريدت بييجي منها) */
function pkg_signup_starter(): int
{
    return pkg_v2_ready() && pkg_signup_package() ? 0 : (int) STARTER_CREDITS;
}

/** تفعيل باقة التسجيل لحساب جديد */
function pkg_signup_grant(int $uid): bool
{
    if (!pkg_v2_ready()) return false;
    $pkg = pkg_signup_package();
    if (!$pkg) return false;
    $days = max(1, (int) $pkg['validity_days']);
    if ((int) $pkg['credits'] > 0) credits_add($uid, (int) $pkg['credits'], 'باقة التسجيل: ' . $pkg['name'], null, 'signup', $uid, $days, ['source' => 'free', 'idem_key' => 'signup:' . $uid]);
    if (function_exists('plan_start')) plan_start($uid, (int) $pkg['id'], (int) $pkg['credits'], $days, 'signup', null, 'باقة التسجيل', ['quotas_json' => $pkg['quotas_json'], 'price_id' => $pkg['price_id']]);
    return true;
}
