<?php
/**
 * Spread AI v2 — محرك العروض والأفلييت
 *
 * ⚠️ الكريدت: بنستخدم credits_add() الموجودة في includes/credits.php
 *    (محفظة + لدجر + صلاحية). ممنوع نبني نظام كريدت موازي.
 */

if (!function_exists('db_one')) {
    require_once __DIR__ . '/db.php';
}

/* ═══════════════════ رسائل الرفض ═══════════════════ */

function offer_reject_messages(): array
{
    return [
        'OFFER_NOT_FOUND'            => 'الكود ده مش موجود',
        'OFFER_INACTIVE'             => 'العرض ده موقوف حاليًا',
        'OFFER_NOT_STARTED'          => 'العرض لسه مابدأش',
        'OFFER_EXPIRED'              => 'العرض ده انتهى',
        'OFFER_LIMIT_REACHED'        => 'العرض وصل لأقصى عدد استخدامات',
        'OFFER_DAILY_LIMIT'          => 'العرض وصل للسقف اليومي — جرّب بكرة',
        'USER_BLOCKED'               => 'الحساب ده مش مسموح له بالعرض',
        'USER_NOT_ELIGIBLE'          => 'العرض ده مخصص لمستخدمين محددين',
        'NOT_NEW_USER'               => 'العرض ده للحسابات الجديدة بس',
        'ALREADY_PURCHASED'           => 'العرض ده لمن لم يشترِ من قبل',
        'PACKAGE_NOT_ELIGIBLE'       => 'العرض ده مش شغال مع الباقة دي',
        'ALREADY_REDEEMED'           => 'استفدت من العرض ده قبل كده',
        'IDENTITY_ALREADY_REDEEMED'  => 'الرقم/الإيميل ده استفاد من العرض قبل كده',
        'GROUP_ALREADY_USED'         => 'استفدت من عرض من نفس المجموعة قبل كده',
        'NOT_STACKABLE'              => 'العرض ده مش بيتجمع مع عرض تاني',
        'SELF_REFERRAL'              => 'مينفعش تستخدم كودك بنفسك',
        'SAME_IP_BLOCKED'            => 'تم رفض العملية لأسباب أمنية',
        'MAX_PER_REFERRER'           => 'صاحب الكود وصل لأقصى عدد إحالات',
        'REFERRALS_DISABLED'         => 'نظام الإحالات موقوف حاليًا',
    ];
}

function offer_reason_text(string $code): string
{
    return offer_reject_messages()[$code] ?? $code;
}

function offer_fail(string $reason): array
{
    return ['ok' => false, 'reason' => $reason, 'message' => offer_reason_text($reason), 'offer' => null];
}

/* ═══════════════════ الهوية ═══════════════════ */

function normalize_phone(string $p): string
{
    $p = preg_replace('/\D+/', '', $p);
    if ($p === '') return '';
    if (strpos($p, '00') === 0) $p = substr($p, 2);
    if (strpos($p, '0') === 0)  $p = '20' . substr($p, 1);
    if (strpos($p, '20') !== 0) $p = '20' . $p;
    return $p;
}

/**
 * بصمة الهوية: الموبايل لو موجود، وإلا الإيميل المطبّع.
 * (الموبايل في نظامنا اختياري — فبنرجع للإيميل عشان الحماية تفضل شغالة)
 */
function identity_hash_for(?string $phone, ?string $email = null): ?string
{
    $salt = defined('ENCRYPTION_KEY') ? ENCRYPTION_KEY : (defined('APP_URL') ? APP_URL : 'spread');
    $ph = $phone ? normalize_phone($phone) : '';
    if ($ph !== '' && strlen($ph) >= 10) {
        return hash('sha256', 'p:' . $ph . $salt);
    }
    if ($email) {
        $e = mb_strtolower(trim($email));
        // تطبيع جيميل: النقط والـ +alias
        if (preg_match('/^([^@]+)@(gmail|googlemail)\.com$/', $e, $m)) {
            $local = str_replace('.', '', explode('+', $m[1])[0]);
            $e = $local . '@gmail.com';
        }
        return $e !== '' ? hash('sha256', 'e:' . $e . $salt) : null;
    }
    return null;
}

function offer_client_ip(): string
{
    return mb_substr((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/* ═══════════════════ قراءة العروض ═══════════════════ */

function offer_find_by_code(string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    try {
        return db_one('SELECT * FROM offers WHERE code = ?', [$code]) ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

function offer_rules(array $offer): array
{
    $r = json_decode((string) ($offer['rules_json'] ?? ''), true);
    return is_array($r) ? $r : [];
}

/** توليد كود فريد بدون حروف ملتبسة */
function offer_generate_code(int $len = 6, string $prefix = ''): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($try = 0; $try < 40; $try++) {
        $c = $prefix;
        for ($i = 0; $i < $len; $i++) {
            $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        if (!offer_find_by_code($c)) return $c;
    }
    return strtoupper($prefix . bin2hex(random_bytes(4)));
}

/* ═══════════════════ فحص الأهلية ═══════════════════ */

/**
 * @param array $user  صف المستخدم (أو ['id'=>0,...] لمستخدم جديد لسه ماتسجلش)
 * @param array $ctx   ['role'=>'referee|referrer', 'ip'=>..., 'package_id'=>..., 'identity_hash'=>...]
 * @return array ['ok'=>bool,'reason'=>string,'message'=>string,'offer'=>?array,'checks'=>array]
 */
function offer_eligibility(string $code, array $user, array $ctx = []): array
{
    $checks = [];
    $note = function (string $key, string $st, string $detail = '') use (&$checks) {
        $checks[] = ['rule' => $key, 'state' => $st, 'detail' => $detail];
    };

    $offer = offer_find_by_code($code);
    if (!$offer) { $r = offer_fail('OFFER_NOT_FOUND'); $r['checks'] = $checks; return $r; }

    $role     = ($ctx['role'] ?? 'referee') === 'referrer' ? 'referrer' : 'referee';
    $userId   = (int) ($user['id'] ?? 0);
    $idHash   = $ctx['identity_hash'] ?? ($user['identity_hash'] ?? null);
    $ip       = $ctx['ip'] ?? offer_client_ip();
    $rules    = offer_rules($offer);
    $now      = date('Y-m-d H:i:s');
    $fail     = function (string $reason) use ($offer, &$checks) {
        $r = offer_fail($reason);
        $r['offer'] = $offer;
        $r['checks'] = $checks;
        return $r;
    };

    // 2) مفعّل
    if ((int) $offer['is_active'] !== 1) { $note('is_active', 'fail'); return $fail('OFFER_INACTIVE'); }
    $note('is_active', 'ok');

    // 3) الفترة
    if (!empty($offer['starts_at']) && $now < $offer['starts_at']) { $note('starts_at', 'fail', $offer['starts_at']); return $fail('OFFER_NOT_STARTED'); }
    if (!empty($offer['expires_at']) && $now > $offer['expires_at']) { $note('expires_at', 'fail', $offer['expires_at']); return $fail('OFFER_EXPIRED'); }
    $note('period', 'ok');

    // 4) سقف الاستخدام
    if ($offer['max_uses'] !== null && (int) $offer['used_count'] >= (int) $offer['max_uses']) {
        $note('max_uses', 'fail', $offer['used_count'] . '/' . $offer['max_uses']);
        return $fail('OFFER_LIMIT_REACHED');
    }
    $note('max_uses', $offer['max_uses'] === null ? 'off' : 'ok',
        $offer['max_uses'] === null ? '' : $offer['used_count'] . '/' . $offer['max_uses']);

    // 5) السقف اليومي
    if (!empty($rules['max_uses_per_day'])) {
        $today = (int) (db_one('SELECT COUNT(*) c FROM offer_redemptions
                                WHERE offer_id = ? AND status = "granted" AND DATE(created_at) = CURDATE()',
                               [$offer['id']])['c'] ?? 0);
        if ($today >= (int) $rules['max_uses_per_day']) { $note('max_uses_per_day', 'fail', (string) $today); return $fail('OFFER_DAILY_LIMIT'); }
        $note('max_uses_per_day', 'ok', $today . '/' . $rules['max_uses_per_day']);
    } else {
        $note('max_uses_per_day', 'off');
    }

    // 6/7) القوائم
    if (!empty($rules['blacklist_user_ids']) && in_array($userId, (array) $rules['blacklist_user_ids'])) {
        $note('blacklist', 'fail'); return $fail('USER_BLOCKED');
    }
    if (!empty($rules['whitelist_user_ids']) && !in_array($userId, (array) $rules['whitelist_user_ids'])) {
        $note('whitelist', 'fail'); return $fail('USER_NOT_ELIGIBLE');
    }
    $note('lists', empty($rules['blacklist_user_ids']) && empty($rules['whitelist_user_ids']) ? 'off' : 'ok');

    // 8) مستخدمين جدد
    if (!empty($rules['new_users_only']) && $userId > 0) {
        $days = (int) ($rules['max_account_age_days'] ?? 7);
        $age = db_one('SELECT DATEDIFF(NOW(), created_at) d FROM users WHERE id = ?', [$userId]);
        if ($age && (int) $age['d'] > $days) { $note('new_users_only', 'fail', $age['d'] . ' يوم'); return $fail('NOT_NEW_USER'); }
        $note('new_users_only', 'ok');
    } else {
        $note('new_users_only', empty($rules['new_users_only']) ? 'off' : 'ok');
    }

    // 9) لم يشترِ من قبل
    if (!empty($rules['never_purchased']) && $userId > 0) {
        $u = db_one('SELECT has_purchased FROM users WHERE id = ?', [$userId]);
        if ($u && (int) $u['has_purchased'] === 1) { $note('never_purchased', 'fail'); return $fail('ALREADY_PURCHASED'); }
        $note('never_purchased', 'ok');
    } else {
        $note('never_purchased', 'off');
    }

    // 10) الباقات
    if (!empty($rules['allowed_packages']) && !empty($ctx['package_id'])) {
        if (!in_array((int) $ctx['package_id'], array_map('intval', (array) $rules['allowed_packages']), true)) {
            $note('allowed_packages', 'fail'); return $fail('PACKAGE_NOT_ELIGIBLE');
        }
        $note('allowed_packages', 'ok');
    } else {
        $note('allowed_packages', empty($rules['allowed_packages']) ? 'off' : 'skip');
    }

    // 11) استفاد قبل كده (نفس المستخدم)
    if ($userId > 0 && ($rules['once_per_user'] ?? true)) {
        $prev = db_one('SELECT id FROM offer_redemptions WHERE offer_id = ? AND user_id = ? AND role = ? AND status = "granted"',
                       [$offer['id'], $userId, $role]);
        if ($prev) { $note('once_per_user', 'fail'); return $fail('ALREADY_REDEEMED'); }
        $note('once_per_user', 'ok');
    } else {
        $note('once_per_user', 'off');
    }

    // 12) نفس البصمة
    if ($idHash && ($rules['once_per_identity'] ?? true)) {
        $prev = db_one('SELECT id FROM offer_redemptions WHERE offer_id = ? AND identity_hash = ? AND role = ? AND status = "granted"',
                       [$offer['id'], $idHash, $role]);
        if ($prev) { $note('once_per_identity', 'fail'); return $fail('IDENTITY_ALREADY_REDEEMED'); }
        $note('once_per_identity', 'ok');
    } else {
        $note('once_per_identity', $idHash ? 'off' : 'skip');
    }

    // 13) مجموعة العروض
    if (!empty($offer['offer_group']) && ($rules['once_per_group'] ?? true)) {
        $q = 'SELECT offer_id FROM user_offer_flags WHERE offer_group = ? AND (user_id = ?'
           . ($idHash ? ' OR identity_hash = ?' : '') . ')';
        $p = $idHash ? [$offer['offer_group'], $userId, $idHash] : [$offer['offer_group'], $userId];
        $g = $userId > 0 || $idHash ? db_one($q, $p) : null;
        if ($g && (int) $g['offer_id'] !== (int) $offer['id']) {
            $note('once_per_group', 'fail', 'مجموعة: ' . $offer['offer_group']);
            return $fail('GROUP_ALREADY_USED');
        }
        $note('once_per_group', 'ok');
    } else {
        $note('once_per_group', 'off');
    }

    // 15) إحالة ذاتية
    if ($role === 'referee' && !empty($offer['owner_user_id'])) {
        if ((int) $offer['owner_user_id'] === $userId) { $note('self_referral', 'fail'); return $fail('SELF_REFERRAL'); }
        if ($idHash) {
            $owner = db_one('SELECT identity_hash FROM users WHERE id = ?', [$offer['owner_user_id']]);
            if ($owner && !empty($owner['identity_hash']) && $owner['identity_hash'] === $idHash) {
                $note('self_referral', 'fail', 'نفس البصمة'); return $fail('SELF_REFERRAL');
            }
        }
        $note('self_referral', 'ok');
    }

    // 16) نفس الـ IP
    $blockIp = $rules['block_same_ip'] ?? (get_setting('referral_block_same_ip', '1') === '1');
    if ($role === 'referee' && $blockIp && !empty($offer['owner_user_id']) && $ip !== '') {
        $owner = db_one('SELECT signup_ip FROM users WHERE id = ?', [$offer['owner_user_id']]);
        if ($owner && !empty($owner['signup_ip']) && $owner['signup_ip'] === $ip) {
            $note('block_same_ip', 'fail', $ip); return $fail('SAME_IP_BLOCKED');
        }
        $note('block_same_ip', 'ok');
    } else {
        $note('block_same_ip', 'off');
    }

    // حد الإحالات لكل مُحيل
    if ($role === 'referee' && !empty($offer['owner_user_id'])) {
        $max = (int) ($rules['max_per_referrer'] ?? get_setting('referral_max_per_user', 20));
        if ($max > 0) {
            $c = (int) (db_one('SELECT COUNT(*) c FROM referrals WHERE referrer_user_id = ? AND status IN ("qualified","rewarded")',
                               [$offer['owner_user_id']])['c'] ?? 0);
            if ($c >= $max) { $note('max_per_referrer', 'fail', $c . '/' . $max); return $fail('MAX_PER_REFERRER'); }
            $note('max_per_referrer', 'ok', $c . '/' . $max);
        }
    }

    return ['ok' => true, 'reason' => '', 'message' => '', 'offer' => $offer, 'checks' => $checks];
}

/* ═══════════════════ الصرف الآمن ═══════════════════ */

function redeem_offer(int $offerId, int $userId, string $role = 'referee', array $ctx = []): array
{
    $role = $role === 'referrer' ? 'referrer' : 'referee';
    $pdo = db();
    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();

    try {
        $st = $pdo->prepare('SELECT * FROM offers WHERE id = ? FOR UPDATE');
        $st->execute([$offerId]);
        $offer = $st->fetch(PDO::FETCH_ASSOC);

        if (!$offer || (int) $offer['is_active'] !== 1) {
            throw new RuntimeException('OFFER_INACTIVE');
        }
        if ($offer['max_uses'] !== null && (int) $offer['used_count'] >= (int) $offer['max_uses']) {
            throw new RuntimeException('OFFER_LIMIT_REACHED');
        }

        $credits = $role === 'referrer' ? (int) $offer['referrer_credits'] : (int) $offer['referee_credits'];
        if ($credits <= 0) {
            throw new RuntimeException('NO_CREDITS_CONFIGURED');
        }

        // الـ UNIQUE index هو الحارس النهائي
        $ins = $pdo->prepare('INSERT INTO offer_redemptions
            (offer_id, user_id, identity_hash, offer_group, ip, device_hash, role, credits_given)
            VALUES (?,?,?,?,?,?,?,?)');
        $ins->execute([
            $offerId, $userId, $ctx['identity_hash'] ?? null, $offer['offer_group'],
            $ctx['ip'] ?? offer_client_ip(), $ctx['device_hash'] ?? null, $role, $credits,
        ]);
        $redemptionId = (int) $pdo->lastInsertId();

        if (!empty($offer['offer_group'])) {
            $pdo->prepare('INSERT IGNORE INTO user_offer_flags (user_id, identity_hash, offer_group, offer_id) VALUES (?,?,?,?)')
                ->execute([$userId, $ctx['identity_hash'] ?? null, $offer['offer_group'], $offerId]);
        }

        $pdo->prepare('UPDATE offers SET used_count = used_count + 1, credits_spent = credits_spent + ?, updated_at = NOW() WHERE id = ?')
            ->execute([$credits, $offerId]);

        if ($ownTx) $pdo->commit();

        // الكريدت خارج الترانزكشن — credits_add بتفتح ترانزكشن خاصة بيها
        $validity = $offer['credit_validity_days'] !== null
            ? (int) $offer['credit_validity_days']
            : (int) get_setting('offer_default_validity_days', 30);

        $note = ($role === 'referrer' ? 'مكافأة إحالة: ' : 'هدية عرض: ') . $offer['code'];
        $added = credits_add($userId, $credits, $note, null, 'offer_redemption', $redemptionId, $validity);

        if (!$added) {
            // فشل إضافة الكريدت → نلغي الواقعة عشان ميبقاش فيه صرف وهمي
            db_run('UPDATE offer_redemptions SET status = "reversed", reversed_reason = "فشل إضافة الكريدت" WHERE id = ?', [$redemptionId]);
            db_run('UPDATE offers SET used_count = GREATEST(used_count - 1, 0), credits_spent = GREATEST(credits_spent - ?, 0) WHERE id = ?', [$credits, $offerId]);
            return ['ok' => false, 'reason' => 'CREDIT_FAILED', 'message' => 'تعذّر إضافة الكريدت'];
        }

        return ['ok' => true, 'credits' => $credits, 'redemption_id' => $redemptionId, 'validity_days' => $validity];

    } catch (PDOException $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000') {
            return ['ok' => false, 'reason' => 'ALREADY_REDEEMED', 'message' => offer_reason_text('ALREADY_REDEEMED')];
        }
        return ['ok' => false, 'reason' => 'DB_ERROR', 'message' => 'خطأ في قاعدة البيانات'];
    } catch (\Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
        $r = $e->getMessage();
        return ['ok' => false, 'reason' => $r, 'message' => offer_reason_text($r)];
    }
}

/* ═══════════════════ الاسترجاع ═══════════════════ */

function reverse_redemption(int $redemptionId, string $reason, ?int $adminId = null): array
{
    $red = db_one('SELECT * FROM offer_redemptions WHERE id = ?', [$redemptionId]);
    if (!$red) return ['ok' => false, 'message' => 'الواقعة غير موجودة'];
    if ($red['status'] !== 'granted') return ['ok' => false, 'message' => 'الواقعة مسترجعة بالفعل'];

    $credits = (int) $red['credits_given'];
    if ($credits > 0) {
        // خصم بقيد عكسي — مش مسح
        credits_deduct((int) $red['user_id'], $credits,
            'استرجاع مكافأة عرض #' . $red['offer_id'] . ' — ' . mb_substr($reason, 0, 120), $adminId, ['promo', 'bonus']);
    }

    db_run('UPDATE offer_redemptions SET status = "reversed", reversed_reason = ? WHERE id = ?',
        [mb_substr($reason, 0, 191), $redemptionId]);
    db_run('UPDATE offers SET used_count = GREATEST(used_count - 1, 0), credits_spent = GREATEST(credits_spent - ?, 0) WHERE id = ?',
        [$credits, $red['offer_id']]);
    db_run('DELETE FROM user_offer_flags WHERE user_id = ? AND offer_id = ?', [$red['user_id'], $red['offer_id']]);

    if ($red['role'] === 'referrer') {
        db_run('UPDATE referrals SET status = "rejected", reject_reason = ? WHERE referrer_user_id = ? AND offer_id = ? AND status = "rewarded"',
            [mb_substr($reason, 0, 191), $red['user_id'], $red['offer_id']]);
    }

    return ['ok' => true, 'credits' => $credits];
}

/* ═══════════════════ كود الأفلييت لكل مستخدم ═══════════════════ */

/** بينشئ كود الأفلييت للمستخدم لو لسه مش موجود، وبيرجّع صف العرض */
function ensure_user_referral_offer(int $userId): ?array
{
    $u = db_one('SELECT id, name, referral_code FROM users WHERE id = ?', [$userId]);
    if (!$u) return null;

    $existing = db_one('SELECT * FROM offers WHERE type = "referral" AND owner_user_id = ?', [$userId]);
    if ($existing) return $existing;

    $code = !empty($u['referral_code']) ? strtoupper($u['referral_code']) : offer_generate_code(6);
    $rules = json_encode([
        'once_per_user'     => true,
        'once_per_identity' => true,
        'once_per_group'    => true,
        'new_users_only'    => true,
        'block_same_ip'     => get_setting('referral_block_same_ip', '1') === '1',
        'max_per_referrer'  => (int) get_setting('referral_max_per_user', 20),
    ], JSON_UNESCAPED_UNICODE);

    try {
        $id = db_insert(
            'INSERT INTO offers (code, title, type, offer_group, owner_user_id, referrer_credits, referee_credits,
                                 trigger_event, rules_json, is_active)
             VALUES (?, ?, "referral", "welcome_bonus", ?, ?, ?, ?, ?, 1)',
            [
                $code,
                'كود دعوة: ' . mb_substr((string) $u['name'], 0, 60),
                $userId,
                (int) get_setting('referral_default_reward', 100),
                (int) get_setting('referral_welcome_bonus', 100),
                (string) get_setting('referral_trigger', 'on_activation'),
                $rules,
            ]
        );
        db_run('UPDATE users SET referral_code = ? WHERE id = ?', [$code, $userId]);
        return db_one('SELECT * FROM offers WHERE id = ?', [$id]);
    } catch (\Throwable $e) {
        return db_one('SELECT * FROM offers WHERE type = "referral" AND owner_user_id = ?', [$userId]);
    }
}

/* ═══════════════════ استحقاق مكافأة المُحيل ═══════════════════ */

/**
 * بتتنادى عند الحدث المناسب (تفعيل الحساب / أول دفعة).
 * بتصرف مكافأة المُحيل لو الشروط متحققة.
 */
function referral_qualify(int $refereeUserId, string $event = 'on_activation'): array
{
    $ref = db_one('SELECT * FROM referrals WHERE referee_user_id = ? AND status = "pending"', [$refereeUserId]);
    if (!$ref) return ['ok' => false, 'reason' => 'NO_PENDING_REFERRAL'];

    $offer = db_one('SELECT * FROM offers WHERE id = ?', [$ref['offer_id']]);
    if (!$offer) return ['ok' => false, 'reason' => 'OFFER_NOT_FOUND'];

    // الحدث لازم يطابق لحظة الاستحقاق
    if ($offer['trigger_event'] !== $event) {
        return ['ok' => false, 'reason' => 'TRIGGER_MISMATCH'];
    }

    db_run('UPDATE referrals SET status = "qualified", qualified_at = NOW() WHERE id = ?', [$ref['id']]);

    $rules = offer_rules($offer);
    if (!empty($rules['require_admin_approval'])) {
        return ['ok' => true, 'pending_approval' => true];
    }

    return referral_reward((int) $ref['id']);
}

/** صرف مكافأة المُحيل فعليًا */
function referral_reward(int $referralId, ?int $adminId = null): array
{
    $ref = db_one('SELECT * FROM referrals WHERE id = ?', [$referralId]);
    if (!$ref) return ['ok' => false, 'reason' => 'NOT_FOUND'];
    if ($ref['status'] === 'rewarded') return ['ok' => false, 'reason' => 'ALREADY_REWARDED'];

    $referrer = db_one('SELECT * FROM users WHERE id = ?', [$ref['referrer_user_id']]);
    if (!$referrer) return ['ok' => false, 'reason' => 'REFERRER_NOT_FOUND'];

    $res = redeem_offer((int) $ref['offer_id'], (int) $ref['referrer_user_id'], 'referrer', [
        'identity_hash' => $referrer['identity_hash'] ?? null,
        'ip' => $ref['signup_ip'],
    ]);

    if ($res['ok']) {
        db_run('UPDATE referrals SET status = "rewarded", rewarded_at = NOW() WHERE id = ?', [$referralId]);
    }
    return $res;
}
