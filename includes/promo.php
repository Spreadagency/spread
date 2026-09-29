<?php
/**
 * Spread AI — أكواد الخصم في الشراء (Promo Codes) — امتداد لمحرك العروض (includes/offers.php)
 *
 * نفس جدول offers ونفس شروطه (الفترة · الحد الإجمالي · حسابات جديدة · لم يشترِ · الباقات …)
 * + جزء جديد في rules_json:
 *   reward: { discount_type: none|percent|fixed|special|free, discount_value, bonus_credits, extra_days }
 *   target: { user_ids:[], emails:[], phones:[] }          ← كود لشخص أو مجموعة أشخاص
 *   eligibility: all|new|existing                          ← كل المستخدمين / جدد بس / حاليين بس
 *   max_per_user, min_purchase (جنيه), allowed_methods:[]
 *
 * ⚠️ العرض بيتحسب من السيرفر بس — الواجهة بتعرض النتيجة، والسيرفر بيعيد الحساب وقت إرسال الطلب ووقت الاعتماد.
 * ⚠️ الاستخدام بيتسجّل في promo_usages لما الأدمن يعتمد الدفع (مش وقت الإدخال) — والطلبات المعلّقة
 *    بتتحسب في الحدود علشان محدش يستخدم كود مرة واحدة في 5 طلبات مع بعض.
 */

require_once __DIR__ . '/offers.php';

function promo_reward(array $offer): array
{
    $r = offer_rules($offer)['reward'] ?? [];
    $type = in_array($r['discount_type'] ?? 'none', ['none', 'percent', 'fixed', 'special', 'free'], true) ? ($r['discount_type'] ?? 'none') : 'none';
    return [
        'discount_type'  => $type,
        'discount_value' => max(0, (float) ($r['discount_value'] ?? 0)),
        'bonus_credits'  => max(0, (int) ($r['bonus_credits'] ?? 0)),
        'extra_days'     => max(0, (int) ($r['extra_days'] ?? 0)),
    ];
}

/** كود فيه أي مكافأة شراء؟ (غير كده = كود إحالة/هدية تسجيل مش كود خصم) */
function promo_has_reward(array $offer): bool
{
    $w = promo_reward($offer);
    return $w['discount_type'] !== 'none' || $w['bonus_credits'] > 0 || $w['extra_days'] > 0;
}

function promo_messages(): array
{
    return [
        'PROMO_NOT_CHECKOUT'   => 'الكود ده مش كود خصم على الباقات',
        'PROMO_NOT_FOR_YOU'    => 'الكود ده مخصص لحساب تاني',
        'PROMO_EXISTING_ONLY'  => 'الكود ده للعملاء الحاليين بس',
        'PROMO_PER_USER_LIMIT' => 'استخدمت الكود ده لأقصى عدد مرات مسموح',
        'PROMO_MIN_AMOUNT'     => 'الكود ده بيشتغل على باقات من %s جنيه وأكتر',
        'PROMO_METHOD'         => 'الكود ده بيشتغل مع طرق دفع معيّنة بس',
        'PROMO_PENDING_LIMIT'  => 'العرض وصل لأقصى عدد استخدامات',
    ];
}

function promo_fail(string $reason, string $extra = ''): array
{
    $m = promo_messages()[$reason] ?? offer_reason_text($reason);
    return ['ok' => false, 'reason' => $reason, 'message' => $extra !== '' ? sprintf($m, $extra) : $m];
}

/** عدد استخدامات العميل للكود: المعتمدة + الطلبات اللي لسه بتتراجع */
function promo_user_count(int $offerId, int $userId, int $exceptRequest = 0): int
{
    $n = 0;
    try {
        $n += (int) (db_one('SELECT COUNT(*) n FROM promo_usages WHERE offer_id = ? AND user_id = ? AND status = "granted"', [$offerId, $userId])['n'] ?? 0);
        $n += (int) (db_one('SELECT COUNT(*) n FROM payment_requests WHERE offer_id = ? AND user_id = ? AND status IN ("pending","under_review") AND id <> ?',
            [$offerId, $userId, $exceptRequest])['n'] ?? 0);
    } catch (\Throwable $e) {}
    return $n;
}

/** الاستخدامات المحجوزة (طلبات معلّقة) — بتتحسب مع used_count في الحد الإجمالي */
function promo_pending_count(int $offerId, int $exceptRequest = 0): int
{
    try {
        return (int) (db_one('SELECT COUNT(*) n FROM payment_requests WHERE offer_id = ? AND status IN ("pending","under_review") AND id <> ?',
            [$offerId, $exceptRequest])['n'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

/** العميل من المستهدفين؟ (رقم الحساب · الإيميل · الموبايل) */
function promo_target_match(array $target, array $user): bool
{
    $ids = array_map('intval', (array) ($target['user_ids'] ?? []));
    $emails = array_map(fn($e) => mb_strtolower(trim((string) $e)), (array) ($target['emails'] ?? []));
    $phones = array_map(fn($p) => normalize_phone((string) $p), (array) ($target['phones'] ?? []));
    if (!$ids && !$emails && !$phones) return true;
    if ($ids && in_array((int) ($user['id'] ?? 0), $ids, true)) return true;
    if ($emails && in_array(mb_strtolower(trim((string) ($user['email'] ?? ''))), $emails, true)) return true;
    $ph = normalize_phone((string) ($user['phone'] ?? ''));
    return $phones && $ph !== '' && in_array($ph, $phones, true);
}

/**
 * التحقق + حساب السعر النهائي — من السيرفر
 * @param array  $package صف credit_packages
 * @param string $method  طريقة الدفع (فاضي = لسه ماختارش؛ شرط الطريقة بيتفحص وقت الإرسال)
 * @return array ok · message · offer · original · discount · final · bonus_credits · extra_days · lines[]
 */
function promo_quote(string $code, array $user, array $package, string $method = '', int $exceptRequest = 0): array
{
    $code = strtoupper(trim($code));
    $offer = offer_find_by_code($code);
    if (!$offer) return promo_fail('OFFER_NOT_FOUND');
    if (($offer['type'] ?? '') !== 'promo' || !promo_has_reward($offer)) return promo_fail('PROMO_NOT_CHECKOUT');

    $rules = offer_rules($offer);
    // الحد الإجمالي بيحسب المحجوز في الطلبات المعلّقة كمان
    if ($offer['max_uses'] !== null && (int) $offer['used_count'] + promo_pending_count((int) $offer['id'], $exceptRequest) >= (int) $offer['max_uses']) {
        return promo_fail('PROMO_PENDING_LIMIT');
    }
    // الشروط العامة من محرك العروض (الفترة · مفعّل · الحد · جدد · لم يشترِ · الباقة · القوائم)
    $idh = $user['identity_hash'] ?? identity_hash_for($user['phone'] ?? null, $user['email'] ?? null);
    $base = offer_eligibility($code, $user, ['role' => 'referee', 'identity_hash' => $idh, 'package_id' => (int) $package['id']]);
    if (!$base['ok']) return ['ok' => false, 'reason' => $base['reason'], 'message' => $base['message']];

    // مخصص لحساب/مجموعة
    if (!promo_target_match((array) ($rules['target'] ?? []), $user)) return promo_fail('PROMO_NOT_FOR_YOU');
    // العملاء الحاليين بس
    if (($rules['eligibility'] ?? 'all') === 'existing') {
        $days = (int) ($rules['max_account_age_days'] ?? 7);
        $age = db_one('SELECT DATEDIFF(NOW(), created_at) d FROM users WHERE id = ?', [(int) $user['id']]);
        if (!$age || (int) $age['d'] <= $days) return promo_fail('PROMO_EXISTING_ONLY');
    }
    // مرات الاستخدام لكل عميل
    $perUser = !empty($rules['max_per_user']) ? (int) $rules['max_per_user'] : (($rules['once_per_user'] ?? true) ? 1 : 0);
    if ($perUser > 0 && promo_user_count((int) $offer['id'], (int) $user['id'], $exceptRequest) >= $perUser) {
        return promo_fail($perUser === 1 ? 'ALREADY_REDEEMED' : 'PROMO_PER_USER_LIMIT');
    }
    // الحد الأدنى للمبلغ
    $price = (float) $package['price_egp'];
    if (!empty($rules['min_purchase']) && $price < (float) $rules['min_purchase']) {
        return promo_fail('PROMO_MIN_AMOUNT', number_format((float) $rules['min_purchase'], 0));
    }
    // طريقة الدفع
    $methods = array_values(array_filter((array) ($rules['allowed_methods'] ?? [])));
    if ($methods && $method !== '' && !in_array($method, $methods, true)) return promo_fail('PROMO_METHOD');

    $w = promo_reward($offer);
    $final = $price;
    switch ($w['discount_type']) {
        case 'percent': $final = $price - round($price * min(100, $w['discount_value']) / 100, 2); break;
        case 'fixed':   $final = $price - $w['discount_value']; break;
        case 'special': $final = $w['discount_value']; break;
        case 'free':    $final = 0; break;
    }
    $final = max(0, min($price, round($final, 2)));
    $discount = round($price - $final, 2);

    $lines = [];
    if ($discount > 0) $lines[] = ['k' => 'discount', 't' => $w['discount_type'] === 'free' ? 'الباقة مجانًا' : ($w['discount_type'] === 'special' ? 'سعر خاص' : 'خصم'), 'v' => $discount];
    if ($w['bonus_credits'] > 0) $lines[] = ['k' => 'bonus', 't' => 'كريدت هدية', 'v' => $w['bonus_credits']];
    if ($w['extra_days'] > 0) $lines[] = ['k' => 'days', 't' => 'أيام زيادة', 'v' => $w['extra_days']];

    return [
        'ok' => true, 'message' => '', 'offer' => $offer, 'code' => $offer['code'],
        'original' => $price, 'discount' => $discount, 'final' => $final,
        'bonus_credits' => $w['bonus_credits'], 'extra_days' => $w['extra_days'], 'reward' => $w,
        'methods' => $methods, 'lines' => $lines,
    ];
}

/** تسجيل استخدام الكود بعد اعتماد الدفع (مرة واحدة لكل طلب — الـ UNIQUE هو الحارس) */
function promo_record_usage(array $req): void
{
    if (empty($req['offer_id'])) return;
    try {
        $st = db()->prepare('INSERT IGNORE INTO promo_usages (offer_id, user_id, payment_request_id, discount_amount, bonus_credits, extra_days) VALUES (?,?,?,?,?,?)');
        $st->execute([(int) $req['offer_id'], (int) $req['user_id'], (int) $req['id'], (float) $req['discount_amount'], (int) $req['bonus_credits'], (int) $req['extra_days']]);
        if ($st->rowCount() === 1) {
            db_run('UPDATE offers SET used_count = used_count + 1, credits_spent = credits_spent + ?, updated_at = NOW() WHERE id = ?',
                [(int) $req['bonus_credits'], (int) $req['offer_id']]);
        }
    } catch (\Throwable $e) {
        error_log('[promo] usage ' . $e->getMessage());
    }
}

/** شرح المكافأة في سطر (للأدمن والعميل) */
function promo_reward_label(array $offer): string
{
    $w = promo_reward($offer);
    $p = [];
    if ($w['discount_type'] === 'percent') $p[] = 'خصم ' . rtrim(rtrim(number_format($w['discount_value'], 2), '0'), '.') . '%';
    if ($w['discount_type'] === 'fixed') $p[] = 'خصم ' . number_format($w['discount_value'], 0) . ' جنيه';
    if ($w['discount_type'] === 'special') $p[] = 'سعر خاص ' . number_format($w['discount_value'], 0) . ' جنيه';
    if ($w['discount_type'] === 'free') $p[] = 'الباقة مجانًا';
    if ($w['bonus_credits']) $p[] = '+' . $w['bonus_credits'] . ' كريدت';
    if ($w['extra_days']) $p[] = '+' . $w['extra_days'] . ' يوم';
    return $p ? implode(' · ', $p) : '—';
}
