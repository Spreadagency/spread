<?php
/**
 * Spread AI — Credits System
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/usage.php';

/**
 * Get user balance
 */
function credits_balance(int $userId): int
{
    credits_check_expiry($userId);
    $row = db_one('SELECT balance FROM credit_wallets WHERE user_id = ?', [$userId]);
    return $row ? (int) $row['balance'] : 0;
}

/**
 * صلاحية الكريدت (شهر): لو الرصيد منتهي → يتجمّد في expired_balance.
 * لو المستخدم جدّد خلال 3 أيام من الانتهاء → المجمّد بيرجع مع الرصيد الجديد (carryover).
 * بعد 3 أيام → المجمّد بيسقط نهائيًا.
 */
function credits_check_expiry(int $userId): void
{
    $began = false;
    try {
        $w = db_one('SELECT * FROM credit_wallets WHERE user_id = ?', [$userId]);
        if (!$w) return;

        // 1) رصيد منتهي الصلاحية → تجميد
        if (!empty($w['expires_at']) && strtotime($w['expires_at']) < time() && (int) $w['balance'] > 0) {
            $began = credits_tx_begin();
            $w = db_one('SELECT * FROM credit_wallets WHERE user_id = ? FOR UPDATE', [$userId]) ?: $w;
            if (!empty($w['expires_at']) && strtotime($w['expires_at']) < time() && (int) $w['balance'] > 0) {
                db_run(
                    'UPDATE credit_wallets SET expired_balance = expired_balance + balance, balance = 0,
                     expired_at = NOW(), expires_at = NULL WHERE user_id = ?',
                    [$userId]
                );
                if (credits_lots_ready()) {
                    credits_lots_reconcile($userId, (int) $w['balance']);
                    db_run('UPDATE credit_lots SET frozen = frozen + remaining, remaining = 0 WHERE user_id = ? AND remaining > 0', [$userId]);
                }
                credits_tx_insert([
                    'user_id' => $userId, 'action_type' => 'deduct', 'amount' => (int) $w['balance'],
                    'notes' => 'انتهاء صلاحية الكريدت (شهر) — قابل للاسترجاع عند التجديد خلال 3 أيام',
                    'source' => 'expiry', 'balance_after' => 0,
                ]);
            }
            if ($began) db()->commit();
            $began = false;
            $w = db_one('SELECT * FROM credit_wallets WHERE user_id = ?', [$userId]);
        }

        // 2) رصيد مجمّد عدّى عليه أكتر من 3 أيام → سقوط نهائي
        if ((int) ($w['expired_balance'] ?? 0) > 0 && !empty($w['expired_at'])
            && strtotime($w['expired_at']) < strtotime('-3 days')) {
            db_run('UPDATE credit_wallets SET expired_balance = 0, expired_at = NULL WHERE user_id = ?', [$userId]);
            if (credits_lots_ready()) {
                db_run('UPDATE credit_lots SET forfeited = forfeited + frozen, frozen = 0 WHERE user_id = ? AND frozen > 0', [$userId]);
            }
        }
    } catch (\Throwable $e) {
        if ($began && db()->inTransaction()) db()->rollBack();
        // أعمدة الصلاحية لسه متضافتش — النظام يكمل عادي
    }
}

/* ═══════════ 8-أ: دفعات الكريدت حسب المصدر (credit_lots) ═══════════
 * الرصيد في credit_wallets لسه هو المرجع للسماح/المنع — الدفعات «ظل محاسبي» بيعرف
 * الكريدت اللي اتصرف كان مدفوع ولا مجاني، فالإيراد يتحسب صح:
 *   paid (باقة مدفوعة) → قيمة الكريدت = المبلغ ÷ عدد الكريدت · free/bonus/promo/compensation → صفر
 * الاستهلاك بيسحب من الأقدم للأحدث (FIFO)، والاسترداد بيرجّع لنفس الدفعات اللي اتسحب منها.
 */

/**
 * بداية transaction آمنة: لو فيه transaction مفتوح أصلًا (من الكود اللي نادى) بنشتغل جواه
 * ومش بنعمل commit/rollback بتاعه. بترجع true لو إحنا اللي فتحناه.
 */
function credits_tx_begin(): bool
{
    if (db()->inTransaction()) return false;
    db()->beginTransaction();
    return true;
}

function credits_lots_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM credit_lots LIMIT 1');
        db_one('SELECT source FROM credit_transactions LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/** قيمة الكريدت المدفوع في المتوسط لعميل (من طلبات الدفع) — للرصيد القديم اللي مصدره مش معروف */
function credits_user_avg_unit(int $userId): float
{
    try {
        $r = db_one('SELECT COALESCE(SUM(amount_egp),0) a, COALESCE(SUM(credits),0) c FROM payment_orders WHERE user_id = ? AND status = "paid"', [$userId]);
        return (int) ($r['c'] ?? 0) > 0 ? round((float) $r['a'] / (int) $r['c'], 4) : 0.0;
    } catch (\Throwable $e) {
        return 0.0;
    }
}

/** تسجيل حركة (بالأعمدة الجديدة لو الترحيل اتشغل) → id */
function credits_tx_insert(array $f): int
{
    if (credits_lots_ready()) {
        $id = db_insert(
            'INSERT INTO credit_transactions (user_id, action_type, amount, reference_type, reference_id, notes, created_by,
                source, request_id, idem_key, revenue_egp, alloc_json, balance_after)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $f['user_id'], $f['action_type'], $f['amount'], $f['reference_type'] ?? null, $f['reference_id'] ?? null,
                $f['notes'] ?? '', $f['created_by'] ?? null,
                $f['source'] ?? null, usage_request_id(), $f['idem_key'] ?? null,
                isset($f['revenue_egp']) ? round((float) $f['revenue_egp'], 4) : null,
                isset($f['alloc']) ? json_encode($f['alloc'], JSON_UNESCAPED_UNICODE) : null,
                $f['balance_after'] ?? null,
            ]
        );
    } else {
        $id = db_insert(
            'INSERT INTO credit_transactions (user_id, action_type, amount, reference_type, reference_id, notes, created_by) VALUES (?,?,?,?,?,?,?)',
            [$f['user_id'], $f['action_type'], $f['amount'], $f['reference_type'] ?? null, $f['reference_id'] ?? null, $f['notes'] ?? '', $f['created_by'] ?? null]
        );
    }
    if ($id && function_exists('usage_note_credit_tx')) usage_note_credit_tx($id);
    return $id;
}

/** مصدر إضافة الكريدت → [source, unit_egp, estimated] */
function credits_lot_source(int $userId, int $amount, ?string $refType, ?int $refId, array $meta): array
{
    $src = (string) ($meta['source'] ?? '');
    if ($refType === 'purchase' && $refId) {
        try {
            $o = db_one('SELECT amount_egp, credits FROM payment_orders WHERE id = ?', [$refId]);
            if ($o && (int) $o['credits'] > 0) {
                return ['paid', round((float) $o['amount_egp'] / (int) $o['credits'], 4), 0];
            }
        } catch (\Throwable $e) {
        }
        return ['paid', credits_user_avg_unit($userId), 1];
    }
    if ($src === 'paid') {
        $egp = (float) ($meta['amount_egp'] ?? 0);
        return $egp > 0 ? ['paid', round($egp / max(1, $amount), 4), 0] : ['paid', credits_user_avg_unit($userId), 1];
    }
    if (in_array($src, ['free', 'bonus', 'promo', 'compensation'], true)) return [$src, 0.0, 0];
    if ($refType === 'offer_redemption') return ['promo', 0.0, 0];
    if ($refType === 'signup') return ['free', 0.0, 0];
    if ($refType === 'referral') return ['bonus', 0.0, 0];
    return ['bonus', 0.0, 0];
}

/**
 * مطابقة الدفعات مع الرصيد (قبل أي سحب): رصيد قديم من قبل الترحيل → دفعة «legacy».
 * لو الدفعات أكتر من الرصيد (خصم قديم مباشر) → بتتقص من الأقدم.
 */
function credits_lots_reconcile(int $userId, int $balance): void
{
    $sum = (int) (db_one('SELECT COALESCE(SUM(remaining),0) s FROM credit_lots WHERE user_id = ?', [$userId])['s'] ?? 0);
    if ($sum < $balance) {
        $unit = credits_user_avg_unit($userId);
        db_insert('INSERT INTO credit_lots (user_id, source, credits, remaining, unit_egp, estimated) VALUES (?,?,?,?,?,1)',
            [$userId, $unit > 0 ? 'legacy' : 'free', $balance - $sum, $balance - $sum, $unit]);
    } elseif ($sum > $balance) {
        credits_lots_draw($userId, $sum - $balance);
    }
}

/**
 * سحب من الدفعات (الأقدم أولًا) → [[lot, n, unit, src, est], …]
 * $prefer: مصادر تتسحب الأول (مثلًا استرجاع مكافأة عرض → promo)
 */
function credits_lots_draw(int $userId, int $amount, array $prefer = []): array
{
    $alloc = [];
    if ($amount <= 0) return $alloc;
    $prefer = array_values(array_filter($prefer, fn($x) => in_array($x, ['paid', 'free', 'bonus', 'promo', 'compensation', 'legacy'], true)));
    $order = $prefer ? 'FIELD(source, ' . implode(',', array_map(fn($x) => "'$x'", array_reverse($prefer))) . ') DESC, id ASC' : 'id ASC';
    $lots = db_all('SELECT id, remaining, unit_egp, source, estimated FROM credit_lots WHERE user_id = ? AND remaining > 0 ORDER BY ' . $order . ' FOR UPDATE', [$userId]);
    foreach ($lots as $l) {
        if ($amount <= 0) break;
        $n = min($amount, (int) $l['remaining']);
        db_run('UPDATE credit_lots SET remaining = remaining - ? WHERE id = ?', [$n, (int) $l['id']]);
        $alloc[] = [(int) $l['id'], $n, (float) $l['unit_egp'], (string) $l['source'], (int) $l['estimated']];
        $amount -= $n;
    }
    return $alloc;
}

function credits_alloc_revenue(array $alloc): float
{
    $r = 0.0;
    foreach ($alloc as $a) $r += (int) $a[1] * (float) $a[2];
    return round($r, 4);
}

/**
 * Add credits to user (positive amount)
 * $meta (8-أ): source (paid|free|bonus|promo|compensation) · amount_egp (للمدفوع) · idem_key
 */
function credits_add(int $userId, int $amount, string $notes = '', ?int $createdBy = null, ?string $referenceType = null, ?int $referenceId = null, ?int $validityDays = null, array $meta = []): bool
{
    if ($amount <= 0) return false;
    $lots = credits_lots_ready();
    // الاسترداد بيتعرف من النوع بس (مش من نص الملاحظة) — كل نداءات الاسترداد بتبعت 'refund'
    $isRefund = $referenceType === 'refund';

    $began = false;
    try {
        $began = credits_tx_begin();
        // منع التكرار: نفس المفتاح اتنفّذ قبل كده → تمام من غير ما نضيف تاني
        $idem = isset($meta['idem_key']) ? mb_substr((string) $meta['idem_key'], 0, 100) : null;
        if ($lots && $idem && db_one('SELECT id FROM credit_transactions WHERE idem_key = ?', [$idem])) {
            if ($began) db()->commit();
            return true;
        }

        // Ensure wallet exists
        db_run('INSERT IGNORE INTO credit_wallets (user_id, balance) VALUES (?, 0)', [$userId]);
        $w0 = db_one('SELECT balance FROM credit_wallets WHERE user_id = ? FOR UPDATE', [$userId]);
        $before = (int) ($w0['balance'] ?? 0);

        // مدة الصلاحية: المحددة أو الافتراضية من الإعدادات
        if ($validityDays === null && function_exists('get_setting')) {
            $validityDays = (int) get_setting('default_credit_validity_days', 30);
        }
        $validityDays = max(1, (int) ($validityDays ?: 30));

        // الاسترداد: بيرجع لنفس دفعات آخر خصم (مرة واحدة لكل خصم)
        $alloc = null;
        $revenue = null;
        $source = null;
        $consumeTx = null;
        if ($lots) {
            credits_lots_reconcile($userId, $before);
            if ($isRefund) {
                // الخصم اللي بيتسترد: نفس الطلب، أو نفس المرجع (غير فاضي) خلال ساعتين — ومش مسترد بالكامل
                $consumeTx = null;
                $cands = db_all(
                    'SELECT id, amount, alloc_json FROM credit_transactions
                     WHERE user_id = ? AND action_type = "consume" AND alloc_json IS NOT NULL
                       AND (request_id = ? OR (? IS NOT NULL AND reference_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)))
                     ORDER BY (request_id = ?) DESC, id DESC LIMIT 10 FOR UPDATE',
                    [$userId, usage_request_id(), $referenceId, $referenceId, usage_request_id()]
                );
                foreach ($cands as $c) {
                    $j = json_decode((string) $c['alloc_json'], true) ?: [];
                    if ((int) ($j['r'] ?? 0) < (int) $c['amount'] && empty($j['refunded'])) { $consumeTx = $c; break; }
                }
                if (!$consumeTx && db_one(
                    'SELECT id FROM credit_transactions WHERE user_id = ? AND request_id = ? AND action_type = "add" AND reference_type = "refund"
                       AND amount = ? AND reference_id <=> ? LIMIT 1',
                    [$userId, usage_request_id(), $amount, $referenceId]
                )) {
                    // نفس الاسترداد اتنفّذ في نفس الطلب ومفيش خصم تاني يقابله → تكرار
                    if ($began) db()->commit();
                    return true;
                }
                if ($consumeTx) {
                    $prevR = (int) ((json_decode((string) $consumeTx['alloc_json'], true) ?: [])['r'] ?? 0);
                    $idem = $idem ?: ('rf:' . (int) $consumeTx['id'] . ':' . $prevR);
                    if (db_one('SELECT id FROM credit_transactions WHERE idem_key = ?', [$idem])) {
                        if ($began) db()->commit();
                        return true;
                    }
                }
            }
        }

        db_run('UPDATE credit_wallets SET balance = balance + ? WHERE user_id = ?', [$amount, $userId]);

        if ($lots) {
            if ($isRefund && $consumeTx) {
                $orig = json_decode((string) $consumeTx['alloc_json'], true) ?: [];
                $parts = $orig['a'] ?? [];
                $already = (int) ($orig['r'] ?? 0);           // اللي اترد قبل كده من الخصم ده (استرداد جزئي)
                $canBack = max(0, (int) $consumeTx['amount'] - $already);
                $left = min($amount, $canBack);
                $alloc = [];
                $skip = $already;
                foreach (array_reverse($parts) as $pt) {
                    if ($left <= 0) break;
                    $avail = (int) $pt[1];
                    if ($skip > 0) { $d = min($skip, $avail); $avail -= $d; $skip -= $d; }
                    if ($avail <= 0) continue;
                    $n = min($left, $avail);
                    db_run('UPDATE credit_lots SET remaining = remaining + ? WHERE id = ? AND user_id = ?', [$n, (int) $pt[0], $userId]);
                    $alloc[] = [(int) $pt[0], $n, (float) $pt[2], (string) ($pt[3] ?? ''), (int) ($pt[4] ?? 0)];
                    $left -= $n;
                }
                $restored = min($amount, $canBack) - $left;
                $extra = $amount - $restored;
                if ($extra > 0) { // أكتر من الخصم الأصلي → الباقي تعويض
                    $lid = db_insert('INSERT INTO credit_lots (user_id, source, credits, remaining, unit_egp) VALUES (?,?,?,?,0)', [$userId, 'compensation', $extra, $extra]);
                    $alloc[] = [$lid, $extra, 0.0, 'compensation', 0];
                }
                $revenue = -credits_alloc_revenue($alloc);
                $source = 'refund';
                $orig['r'] = $already + $restored;
                db_run('UPDATE credit_transactions SET alloc_json = ? WHERE id = ?', [json_encode($orig, JSON_UNESCAPED_UNICODE), (int) $consumeTx['id']]);
            } else {
                [$source, $unit, $est] = $isRefund ? ['compensation', 0.0, 0] : credits_lot_source($userId, $amount, $referenceType, $referenceId, $meta);
                $lid = db_insert('INSERT INTO credit_lots (user_id, source, credits, remaining, unit_egp, estimated) VALUES (?,?,?,?,?,?)',
                    [$userId, $source, $amount, $amount, $unit, $est]);
                $alloc = [[$lid, $amount, $unit, $source, $est]];
            }
        }

        $txId = credits_tx_insert([
            'user_id' => $userId, 'action_type' => 'add', 'amount' => $amount,
            'reference_type' => $referenceType, 'reference_id' => $referenceId, 'notes' => $notes, 'created_by' => $createdBy,
            'source' => $source, 'idem_key' => $idem, 'revenue_egp' => $revenue,
            'alloc' => $alloc !== null ? ['a' => $alloc] + ($consumeTx ? ['of' => (int) $consumeTx['id']] : []) : null,
            'balance_after' => $before + $amount,
        ]);
        if ($lots && $txId && !$isRefund && $alloc) {
            db_run('UPDATE credit_lots SET tx_id = ? WHERE id = ?', [$txId, (int) $alloc[0][0]]);
        }

        // ─── صلاحية شهر + استرجاع المجمّد عند التجديد خلال 3 أيام ───
        try {
            if (!$isRefund) {
                $w = db_one('SELECT expired_balance, expired_at FROM credit_wallets WHERE user_id = ?', [$userId]);
                // carryover: تجديد خلال 3 أيام من انتهاء الصلاحية
                if ($w && (int) $w['expired_balance'] > 0 && !empty($w['expired_at'])) {
                    if (strtotime($w['expired_at']) >= strtotime('-3 days')) {
                        // تجديد خلال فترة السماح → استرجاع
                        $carry = (int) $w['expired_balance'];
                        db_run(
                            'UPDATE credit_wallets SET balance = balance + ?, expired_balance = 0, expired_at = NULL WHERE user_id = ?',
                            [$carry, $userId]
                        );
                        if ($lots) {
                            db_run('UPDATE credit_lots SET remaining = remaining + frozen, frozen = 0 WHERE user_id = ? AND frozen > 0', [$userId]);
                        }
                        credits_tx_insert([
                            'user_id' => $userId, 'action_type' => 'add', 'amount' => $carry,
                            'notes' => 'استرجاع رصيد سابق — تجديد خلال فترة السماح (3 أيام)', 'source' => 'carryover',
                            'balance_after' => $before + $amount + $carry,
                        ]);
                    } else {
                        // تجديد متأخر → المجمّد بيسقط نهائيًا
                        db_run('UPDATE credit_wallets SET expired_balance = 0, expired_at = NULL WHERE user_id = ?', [$userId]);
                        if ($lots) {
                            db_run('UPDATE credit_lots SET forfeited = forfeited + frozen, frozen = 0 WHERE user_id = ? AND frozen > 0', [$userId]);
                        }
                    }
                }
                // صلاحية جديدة حسب المدة المحددة (أو الافتراضية)
                db_run('UPDATE credit_wallets SET expires_at = DATE_ADD(NOW(), INTERVAL ? DAY) WHERE user_id = ?', [$validityDays, $userId]);
            }
        } catch (\Throwable $e) {
            // أعمدة الصلاحية غير موجودة — تجاهل
        }

        if ($began) db()->commit();
        return true;
    } catch (\Throwable $e) {
        if ($began && db()->inTransaction()) db()->rollBack();
        error_log('[credits_add] ' . $e->getMessage());
        return false;
    }
}

/**
 * Consume credits (subtract). Fails if insufficient balance.
 * (8-أ) بيسحب من الدفعات ويسجل الإيراد الحقيقي للخصم ده
 */
function credits_consume(int $userId, int $amount, string $notes = '', ?string $referenceType = null, ?int $referenceId = null): bool
{
    if ($amount <= 0) return true;
    credits_check_expiry($userId);
    $began = false;
    try {
        $began = credits_tx_begin();
        $row = db_one('SELECT balance FROM credit_wallets WHERE user_id = ? FOR UPDATE', [$userId]);
        $balance = $row ? (int) $row['balance'] : 0;
        if ($balance < $amount) {
            if ($began) db()->rollBack();
            return false;
        }
        db_run('UPDATE credit_wallets SET balance = balance - ? WHERE user_id = ?', [$amount, $userId]);
        $alloc = null;
        if (credits_lots_ready()) {
            credits_lots_reconcile($userId, $balance);
            $alloc = credits_lots_draw($userId, $amount);
        }
        credits_tx_insert([
            'user_id' => $userId, 'action_type' => 'consume', 'amount' => $amount,
            'reference_type' => $referenceType, 'reference_id' => $referenceId, 'notes' => $notes,
            'source' => 'usage', 'revenue_egp' => $alloc !== null ? credits_alloc_revenue($alloc) : null,
            'alloc' => $alloc !== null ? ['a' => $alloc] : null, 'balance_after' => $balance - $amount,
        ]);
        if ($began) db()->commit();
        return true;
    } catch (\Throwable $e) {
        if ($began && db()->inTransaction()) db()->rollBack();
        error_log('[credits_consume] ' . $e->getMessage());
        return false;
    }
}

/**
 * Admin deduct credits (تعديل يدوي — مالهوش إيراد)
 * $prefer: المصادر اللي تتخصم الأول (الافتراضي: المجاني/الهدايا قبل المدفوع)
 */
function credits_deduct(int $userId, int $amount, string $notes = '', ?int $createdBy = null, array $prefer = ['promo', 'bonus', 'compensation', 'free', 'legacy']): bool
{
    if ($amount <= 0) return false;
    $began = false;
    try {
        $began = credits_tx_begin();
        $row = db_one('SELECT balance FROM credit_wallets WHERE user_id = ? FOR UPDATE', [$userId]);
        $balance = $row ? (int) $row['balance'] : 0;
        $take = min($amount, $balance);
        db_run('UPDATE credit_wallets SET balance = GREATEST(0, balance - ?) WHERE user_id = ?', [$amount, $userId]);
        $alloc = null;
        if (credits_lots_ready()) {
            credits_lots_reconcile($userId, $balance);
            $alloc = credits_lots_draw($userId, $take, $prefer);
        }
        credits_tx_insert([
            'user_id' => $userId, 'action_type' => 'deduct', 'amount' => $amount, 'notes' => $notes, 'created_by' => $createdBy,
            'source' => 'adjust', 'alloc' => $alloc !== null ? ['a' => $alloc] : null, 'balance_after' => $balance - $take,
        ]);
        if ($began) db()->commit();
        return true;
    } catch (\Throwable $e) {
        if ($began && db()->inTransaction()) db()->rollBack();
        return false;
    }
}

/**
 * Get transaction history
 */
function credits_history(int $userId, int $limit = 50): array
{
    return db_all(
        'SELECT * FROM credit_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . (int) $limit,
        [$userId]
    );
}

/**
 * Get setting (e.g., generation cost from settings table)
 */
function get_setting(string $key, $default = null)
{
    // آخر قيمة دايمًا — حتى لو الجدول فيه صفوف مكررة من نسخ قديمة
    $row = db_one('SELECT setting_value FROM settings WHERE setting_key = ? ORDER BY id DESC LIMIT 1', [$key]);
    return $row ? $row['setting_value'] : $default;
}

function set_setting(string $key, string $value): void
{
    // 8-ج: تغييرات الأدمن بتتسجل في سجل الإعدادات (الأسرار مخفية)
    $audit = function_exists('settings_audit_wanted') && settings_audit_wanted($key);
    $old = null;
    if ($audit) {
        $o = db_one('SELECT setting_value v FROM settings WHERE setting_key = ? ORDER BY id DESC LIMIT 1', [$key]);
        $old = $o ? (string) $o['v'] : null;
    }
    // Bulletproof upsert: بيشتغل حتى لو setting_key مش UNIQUE في قواعد بيانات قديمة
    $affected = db_run('UPDATE settings SET setting_value = ? WHERE setting_key = ?', [$value, $key]);
    $exists = db_one('SELECT id FROM settings WHERE setting_key = ? LIMIT 1', [$key]);
    if (!$exists) {
        db_run('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)', [$key, $value]);
    }
    if ($audit && $old !== $value && !($old === null && $value === '')) settings_audit_record($key, $old, $value);
}

function cost_for(string $action): int
{
    $defaults = [
        'content_generation_cost' => COST_GENERATE,
        'content_regeneration_cost' => COST_REGENERATE,
        'design_cost' => COST_DESIGN,
        'content_design_cost' => COST_DESIGN
    ];
    return (int) get_setting($action, $defaults[$action] ?? 1);
}

// 8-ب: الحصص الشهرية والاستهلاك بالنسبة %
require_once __DIR__ . '/plans.php';
require_once __DIR__ . '/settings-registry.php';
