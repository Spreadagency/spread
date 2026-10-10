<?php
/**
 * Spread AI — الباقات والدفع اليدوي (المرحلة 10)
 *
 *   الباقة ← طلب دفع (إيصال) ← مراجعة الأدمن ← المحفظة (credits_add) ← دورة الباقة (plan_start) ← الاستخدام
 *
 * ⚠️ الكريدت مابيتعدّلش في جدول المستخدم أبدًا: credits_add() (المحفظة + اللدجر + الدفعات) هي مصدر الحقيقة.
 * ⚠️ الاعتماد «مرة واحدة»: تحديث مشروط للحالة (pending/under_review ← approved) + idem_key في credits_add.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/promo.php';

const BILLING_PROOF_MAX = 6 * 1024 * 1024;

/** الجداول جاهزة؟ — لو الترحيل ماتشغلش بنشغّله مرة (آمن للتكرار) */
function billing_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db()->query('SELECT 1 FROM payment_requests LIMIT 1');
        db()->query('SELECT 1 FROM user_remember_tokens LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {}
    try {
        require_once __DIR__ . '/migrations.php';
        $r = migration_run_file('2026_phase10_billing_promo_auth.sql', 'auto');
        return $ok = !empty($r['ok']);
    } catch (\Throwable $e) {
        error_log('[billing] ensure ' . $e->getMessage());
        return $ok = false;
    }
}

function billing_statuses(): array
{
    return [
        'pending'      => ['في انتظار المراجعة', '#D98A1F', 'Pending'],
        'under_review' => ['قيد المراجعة', '#0C87EF', 'Under Review'],
        'approved'     => ['تم التفعيل', '#10A8A0', 'Approved'],
        'rejected'     => ['مرفوض', '#E0475B', 'Rejected'],
        'cancelled'    => ['ملغي', '#8391A6', 'Cancelled'],
    ];
}

function billing_status_label(string $s): string
{
    return billing_statuses()[$s][0] ?? $s;
}

/* ═══════════ الباقات وطرق الدفع ═══════════ */

function billing_packages(bool $activeOnly = true): array
{
    return db_all('SELECT * FROM credit_packages' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY order_num ASC, id ASC');
}

function billing_package(int $id, bool $activeOnly = true): ?array
{
    return db_one('SELECT * FROM credit_packages WHERE id = ?' . ($activeOnly ? ' AND is_active = 1' : ''), [$id]) ?: null;
}

function billing_features(array $pkg): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) ($pkg['features'] ?? '')) ?: [])));
}

function billing_methods(bool $activeOnly = true): array
{
    if (!billing_ready()) return [];
    return db_all('SELECT * FROM payment_methods' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id');
}

function billing_method(string $key, bool $activeOnly = true): ?array
{
    if (!billing_ready()) return null;
    return db_one('SELECT * FROM payment_methods WHERE mkey = ?' . ($activeOnly ? ' AND is_active = 1' : ''), [$key]) ?: null;
}

/* ═══════════ السجل والإشعارات ═══════════ */

function billing_event(int $reqId, string $actorType, ?int $actorId, string $action, ?string $from, ?string $to, ?string $note = null): void
{
    try {
        db_insert('INSERT INTO payment_request_events (request_id, actor_type, actor_id, action, from_status, to_status, note) VALUES (?,?,?,?,?,?,?)',
            [$reqId, $actorType, $actorId, $action, $from, $to, $note !== null ? mb_substr($note, 0, 2000) : null]);
    } catch (\Throwable $e) {
        error_log('[billing] event ' . $e->getMessage());
    }
}

function billing_events(int $reqId): array
{
    try {
        return db_all('SELECT e.*, a.name AS admin_name FROM payment_request_events e
                       LEFT JOIN admin_users a ON e.actor_type = "admin" AND a.id = e.actor_id
                       WHERE e.request_id = ? ORDER BY e.id ASC', [$reqId]);
    } catch (\Throwable $e) {
        return [];
    }
}

function billing_action_label(string $a): string
{
    return [
        'created' => 'اتبعت الطلب', 'review' => 'بدأت المراجعة', 'info_requested' => 'طلب معلومات إضافية',
        'user_responded' => 'العميل رد', 'approved' => 'اتعتمد الدفع واتفعّلت الباقة', 'rejected' => 'اترفض الطلب',
        'cancelled' => 'اتلغى الطلب', 'note' => 'ملاحظة', 'credits_added' => 'اتضاف الكريدت للمحفظة', 'plan_started' => 'بدأت دورة الباقة',
    ][$a] ?? $a;
}

/** إشعار للعميل: جوه المنصة (الجرس) + إيميل (لو الإيميل مفعّل) */
function notify_user(int $userId, string $kind, string $title, string $body = '', string $url = '', string $tone = 'brand', bool $email = true): void
{
    try {
        db_insert('INSERT INTO user_notifications (user_id, kind, title, body, url, tone) VALUES (?,?,?,?,?,?)',
            [$userId, mb_substr($kind, 0, 40), mb_substr($title, 0, 190), $body !== '' ? mb_substr($body, 0, 2000) : null, $url !== '' ? mb_substr($url, 0, 255) : null, $tone]);
    } catch (\Throwable $e) {
        error_log('[notify] ' . $e->getMessage());
    }
    // تطبيق الموبايل: نفس الإشعار كـ push (لو العميل مسجّل من التطبيق)
    if (!function_exists('mobile_push_user') && is_file(__DIR__ . '/mobile-push.php')) {
        require_once __DIR__ . '/mobile-push.php';
    }
    if (function_exists('mobile_push_user')) {
        mobile_push_user($userId, $title, $body, ['screen' => 'notifications', 'kind' => $kind]);
    }
    if ($email && function_exists('get_setting') && get_setting('billing_email_notify', '1') === '1') {
        try {
            if (!function_exists('send_mail')) require_once __DIR__ . '/mailer.php';
            $u = db_one('SELECT name, email FROM users WHERE id = ?', [$userId]);
            if ($u && function_exists('build_email_template') && !empty($u['email'])) {
                send_mail($u['email'], $title, build_email_template($title, nl2br(e($body)), 'افتح المنصة', url($url !== '' ? $url : 'dashboard.php')));
            }
        } catch (\Throwable $e) {
            error_log('[notify] mail ' . $e->getMessage());
        }
    }
}

function user_notifications(int $userId, int $limit = 10, bool $unreadOnly = true): array
{
    try {
        return db_all('SELECT * FROM user_notifications WHERE user_id = ?' . ($unreadOnly ? ' AND read_at IS NULL' : '') . ' ORDER BY id DESC LIMIT ' . max(1, $limit), [$userId]);
    } catch (\Throwable $e) {
        return [];
    }
}

/* ═══════════ العميل: إنشاء الطلب ═══════════ */

/** حفظ الإيصال (صورة أو PDF) */
function billing_store_proof(array $file): array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'ارفع صورة الإيصال'];
    if ($file['size'] > BILLING_PROOF_MAX) return ['ok' => false, 'error' => 'صورة الإيصال أكبر من 6 ميجا'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
    if (!$ext) return ['ok' => false, 'error' => 'الإيصال لازم يكون صورة (JPG · PNG · WebP) أو PDF'];
    $dir = UPLOADS_PATH . '/payments';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    // ملفات الإيصالات مابتتفتحش مباشرة من المتصفح — بتتعرض من خلال صفحة محمية (payment-proof.php)
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    $name = bin2hex(random_bytes(14)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return ['ok' => false, 'error' => 'تعذّر حفظ الإيصال'];
    return ['ok' => true, 'path' => 'uploads/payments/' . $name];
}

/**
 * @param array $in [package_id, method, promo_code, transaction_ref, transferred_at, note, phone]
 * @return array ok · id · error
 */
function billing_create_request(array $user, array $in, ?array $proofFile): array
{
    if (!billing_ready()) return ['ok' => false, 'error' => 'الدفع مش متاح دلوقتي — كلّم الدعم'];
    $uid = (int) $user['id'];
    $pkg = billing_package((int) ($in['package_id'] ?? 0));
    if (!$pkg) return ['ok' => false, 'error' => 'الباقة دي مش متاحة'];

    // طلب معلّق لنفس الباقة؟ مايتكررش
    $dup = db_one('SELECT id FROM payment_requests WHERE user_id = ? AND package_id = ? AND status IN ("pending","under_review") LIMIT 1', [$uid, $pkg['id']]);
    if ($dup) return ['ok' => false, 'error' => 'عندك طلب لنفس الباقة لسه بيتراجع (#' . $dup['id'] . ') — استنى نتيجته أو ألغيه', 'existing' => (int) $dup['id']];

    // الكود: بيتحسب من السيرفر من الأول (مش من أرقام الواجهة)
    $quote = null;
    $code = strtoupper(trim((string) ($in['promo_code'] ?? '')));
    $methodKey = (string) ($in['method'] ?? '');
    if ($code !== '') {
        $quote = promo_quote($code, $user, $pkg, $methodKey);
        if (!$quote['ok']) return ['ok' => false, 'error' => 'كود الخصم: ' . $quote['message']];
    }
    $original = (float) $pkg['price_egp'];
    $final = $quote ? (float) $quote['final'] : $original;
    $free = $final <= 0;

    $method = null;
    if (!$free) {
        $method = billing_method($methodKey);
        if (!$method) return ['ok' => false, 'error' => 'اختار طريقة الدفع'];
    }
    // رقم الموبايل (مطلوب للتواصل بخصوص الدفع)
    $phone = trim((string) ($in['phone'] ?? '')) ?: (string) ($user['phone'] ?? '');
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) < 8 || strlen($digits) > 15) return ['ok' => false, 'error' => 'اكتب رقم موبايل صحيح علشان نقدر نتواصل معاك'];

    $proof = null;
    if (!$free && (int) ($method['needs_proof'] ?? 1) === 1) {
        if (!$proofFile || empty($proofFile['name'])) return ['ok' => false, 'error' => 'ارفع صورة إيصال التحويل'];
        $st = billing_store_proof($proofFile);
        if (!$st['ok']) return $st;
        $proof = $st['path'];
    }
    $at = trim((string) ($in['transferred_at'] ?? ''));
    $atSql = $at !== '' && strtotime($at) ? date('Y-m-d H:i:s', strtotime($at)) : null;
    if ($atSql && strtotime($atSql) > time() + 600) return ['ok' => false, 'error' => 'وقت التحويل في المستقبل'];

    $bonusPkg = (int) ($pkg['bonus_credits'] ?? 0);
    $id = db_insert(
        'INSERT INTO payment_requests (user_id, package_id, plan_name, credits, bonus_credits, validity_days, extra_days, original_amount, discount_amount, final_amount,
                                       method, method_label, transaction_ref, proof_path, transferred_at, promo_code, offer_id, promo_json,
                                       contact_name, contact_email, contact_phone, user_note, status)
         VALUES (?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?, ?,?,?,?, "pending")',
        [
            $uid, (int) $pkg['id'], (string) $pkg['name'], (int) $pkg['credits'], $bonusPkg + (int) ($quote['bonus_credits'] ?? 0),
            max(1, (int) ($pkg['validity_days'] ?? 30)), (int) ($quote['extra_days'] ?? 0), $original, (float) ($quote['discount'] ?? 0), $final,
            $free ? 'promo' : (string) $method['mkey'], $free ? 'مجانًا بكود العرض' : (string) $method['label'],
            mb_substr(trim((string) ($in['transaction_ref'] ?? '')), 0, 120) ?: null, $proof, $atSql,
            $quote ? $quote['code'] : null, $quote ? (int) $quote['offer']['id'] : null,
            $quote ? json_encode(['label' => promo_reward_label($quote['offer']), 'lines' => $quote['lines'], 'reward' => $quote['reward']], JSON_UNESCAPED_UNICODE) : null,
            mb_substr((string) ($user['name'] ?? ''), 0, 150), mb_substr((string) ($user['email'] ?? ''), 0, 150), mb_substr($phone, 0, 30),
            mb_substr(trim((string) ($in['note'] ?? '')), 0, 1000) ?: null,
        ]
    );
    // رقم الموبايل بيتحفظ في الحساب لو فاضي
    if (empty($user['phone'])) {
        try { db_run('UPDATE users SET phone = ? WHERE id = ? AND (phone IS NULL OR phone = "")', [mb_substr($phone, 0, 30), $uid]); } catch (\Throwable $e) {}
    }
    billing_event($id, 'user', $uid, 'created', null, 'pending',
        ($quote ? 'كود ' . $quote['code'] . ' · ' : '') . number_format($final, 2) . ' جنيه · ' . ($free ? 'مجانًا' : $method['label']));
    notify_user($uid, 'payment_pending', 'وصلنا طلب اشتراك «' . $pkg['name'] . '» ✓',
        'طلبك #' . $id . ' في انتظار المراجعة — هنفعّل الباقة أول ما نتأكد من التحويل.', 'payments.php?id=' . $id, 'brand', false);
    if (function_exists('admin_alert')) {
        try { admin_alert('warn', 'payment_request', 'طلب دفع جديد #' . $id, $user['name'] . ' · ' . $pkg['name'] . ' · ' . number_format($final, 0) . ' جنيه', 'admin/payment-view.php?id=' . $id, 'pr:' . $id); } catch (\Throwable $e) {}
    }
    return ['ok' => true, 'id' => $id, 'free' => $free];
}

/** إلغاء من العميل (طالما لسه بيتراجع) */
function billing_user_cancel(int $reqId, int $userId): array
{
    $st = db()->prepare('UPDATE payment_requests SET status = "cancelled" WHERE id = ? AND user_id = ? AND status IN ("pending","under_review")');
    $st->execute([$reqId, $userId]);
    if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'الطلب ده مايتلغيش دلوقتي'];
    billing_event($reqId, 'user', $userId, 'cancelled', null, 'cancelled');
    return ['ok' => true];
}

/** رد العميل على «طلب معلومات إضافية» (ملاحظة و/أو إيصال جديد) ← يرجع للمراجعة */
function billing_user_respond(int $reqId, int $userId, string $note, ?array $proofFile): array
{
    $r = db_one('SELECT * FROM payment_requests WHERE id = ? AND user_id = ?', [$reqId, $userId]);
    if (!$r || !in_array($r['status'], ['pending', 'under_review'], true)) return ['ok' => false, 'error' => 'الطلب ده مش مستني رد'];
    $note = trim($note);
    $proof = null;
    if ($proofFile && !empty($proofFile['name'])) {
        $st = billing_store_proof($proofFile);
        if (!$st['ok']) return $st;
        $proof = $st['path'];
    }
    if ($note === '' && !$proof) return ['ok' => false, 'error' => 'اكتب ردك أو ارفع إيصال جديد'];
    db_run('UPDATE payment_requests SET status = "pending", info_request = NULL, proof_path = COALESCE(?, proof_path),
            user_note = CONCAT(COALESCE(user_note, ""), IF(COALESCE(user_note, "") = "", "", "\n— "), ?) WHERE id = ?',
        [$proof, $note !== '' ? mb_substr($note, 0, 1000) : 'إيصال جديد', $reqId]);
    billing_event($reqId, 'user', $userId, 'user_responded', $r['status'], 'pending', ($note !== '' ? $note : '') . ($proof ? ' [إيصال جديد]' : ''));
    return ['ok' => true];
}

/* ═══════════ الأدمن ═══════════ */

function billing_request(int $id): ?array
{
    return db_one('SELECT r.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone, a.name AS reviewer_name
                   FROM payment_requests r JOIN users u ON u.id = r.user_id LEFT JOIN admin_users a ON a.id = r.reviewed_by
                   WHERE r.id = ?', [$id]) ?: null;
}

/** بدء المراجعة (pending ← under_review) */
function billing_mark_review(int $id, int $adminId): bool
{
    $st = db()->prepare('UPDATE payment_requests SET status = "under_review", reviewed_by = ? WHERE id = ? AND status = "pending"');
    $st->execute([$adminId, $id]);
    if ($st->rowCount() !== 1) return false;
    billing_event($id, 'admin', $adminId, 'review', 'pending', 'under_review');
    return true;
}

function billing_request_info(int $id, int $adminId, string $message): array
{
    $message = trim($message);
    if ($message === '') return ['ok' => false, 'error' => 'اكتب المعلومات المطلوبة من العميل'];
    $r = billing_request($id);
    if (!$r || !in_array($r['status'], ['pending', 'under_review'], true)) return ['ok' => false, 'error' => 'الطلب اتقفل خلاص'];
    db_run('UPDATE payment_requests SET status = "under_review", info_request = ?, reviewed_by = ? WHERE id = ?', [mb_substr($message, 0, 1000), $adminId, $id]);
    billing_event($id, 'admin', $adminId, 'info_requested', $r['status'], 'under_review', $message);
    notify_user((int) $r['user_id'], 'payment_info', 'محتاجين معلومة بخصوص طلب الدفع #' . $id, $message, 'payments.php?id=' . $id, 'warn');
    return ['ok' => true];
}

function billing_reject(int $id, int $adminId, string $reason): array
{
    $reason = trim($reason);
    if ($reason === '') return ['ok' => false, 'error' => 'اكتب سبب الرفض — العميل هيشوفه'];
    $r = billing_request($id);
    if (!$r) return ['ok' => false, 'error' => 'الطلب مش موجود'];
    $st = db()->prepare('UPDATE payment_requests SET status = "rejected", reject_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status IN ("pending","under_review")');
    $st->execute([mb_substr($reason, 0, 500), $adminId, $id]);
    if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'الطلب اتقفل خلاص'];
    billing_event($id, 'admin', $adminId, 'rejected', $r['status'], 'rejected', $reason);
    notify_user((int) $r['user_id'], 'payment_rejected', 'طلب الدفع #' . $id . ' اترفض', $reason . "\nلو فيه غلط، ابعت طلب جديد أو كلّم الدعم.", 'payments.php?id=' . $id, 'danger');
    return ['ok' => true];
}

/**
 * الاعتماد: تأكيد الدفع ← تفعيل الباقة ← الكريدت في المحفظة ← بداية ونهاية ← اللدجر ← سجل الاشتراك ← إشعار
 */
function billing_approve(int $id, int $adminId, string $note = ''): array
{
    if (!function_exists('credits_add')) require_once __DIR__ . '/credits.php';
    if (!function_exists('plan_start') && is_file(__DIR__ . '/plans.php')) require_once __DIR__ . '/plans.php';
    $r = billing_request($id);
    if (!$r) return ['ok' => false, 'error' => 'الطلب مش موجود'];

    // ① تأكيد الدفع — مرة واحدة بس (ضغطتين أو تابين = اعتماد واحد)
    $st = db()->prepare('UPDATE payment_requests SET status = "approved", reviewed_by = ?, reviewed_at = NOW(), admin_note = ? WHERE id = ? AND status IN ("pending","under_review")');
    $st->execute([$adminId, $note !== '' ? mb_substr($note, 0, 1000) : null, $id]);
    if ($st->rowCount() !== 1) return ['ok' => false, 'error' => 'الطلب اتقفل خلاص (اتعتمد أو اترفض قبل كده)'];
    billing_event($id, 'admin', $adminId, 'approved', $r['status'], 'approved', $note !== '' ? $note : null);

    $uid = (int) $r['user_id'];
    $days = max(1, (int) $r['validity_days'] + (int) $r['extra_days']);
    $label = 'باقة «' . $r['plan_name'] . '» — طلب دفع #' . $id;

    // ② + ③ + ⑥ الكريدت في المحفظة (اللدجر + دفعة بإيراد حقيقي) — idem_key يمنع الإضافة مرتين
    $okAdd = credits_add($uid, (int) $r['credits'], 'تفعيل ' . $label, $adminId, 'payment_request', $id, $days,
        (float) $r['final_amount'] > 0 ? ['source' => 'paid', 'amount_egp' => (float) $r['final_amount'], 'idem_key' => 'pr:' . $id]
                                       : ['source' => 'promo', 'amount_egp' => 0, 'idem_key' => 'pr:' . $id]);
    if (!$okAdd) {
        // نرجّع الطلب للمراجعة بدل ما يفضل «معتمد» من غير كريدت
        db_run('UPDATE payment_requests SET status = ? WHERE id = ?', [$r['status'], $id]);
        billing_event($id, 'system', null, 'note', 'approved', $r['status'], 'فشل إضافة الكريدت — الطلب رجع للمراجعة');
        return ['ok' => false, 'error' => 'تعذّر إضافة الكريدت — الطلب رجع للمراجعة، جرّب تاني'];
    }
    billing_event($id, 'system', null, 'credits_added', null, null, '+' . (int) $r['credits'] . ' كريدت · صلاحية ' . $days . ' يوم');
    if ((int) $r['bonus_credits'] > 0) {
        credits_add($uid, (int) $r['bonus_credits'], 'هدية ' . $label . ($r['promo_code'] ? ' (كود ' . $r['promo_code'] . ')' : ''), $adminId,
            'payment_request_bonus', $id, $days, ['source' => 'promo', 'amount_egp' => 0, 'idem_key' => 'prb:' . $id]);
        billing_event($id, 'system', null, 'credits_added', null, null, '+' . (int) $r['bonus_credits'] . ' كريدت هدية');
    }

    // ④ + ⑤ + ⑦ دورة الباقة (بداية · نهاية · حصص) = سجل الاشتراك
    $planId = function_exists('plan_start')
        ? plan_start($uid, $r['package_id'] ? (int) $r['package_id'] : null, (int) $r['credits'] + (int) $r['bonus_credits'], $days, 'paid', $adminId, 'طلب دفع #' . $id)
        : null;
    if ($planId) {
        db_run('UPDATE payment_requests SET user_plan_id = ? WHERE id = ?', [$planId, $id]);
        $pl = db_one('SELECT starts_at, ends_at FROM user_plans WHERE id = ?', [$planId]);
        billing_event($id, 'system', null, 'plan_started', null, null, 'من ' . ($pl['starts_at'] ?? '') . ' لحد ' . ($pl['ends_at'] ?? ''));
    }

    // أول شراء: علامة has_purchased + مكافأة المُحيل (لو العرض بتاعه «عند أول اشتراك مدفوع»)
    if ((float) $r['final_amount'] > 0 && function_exists('referral_on_first_payment')) {
        try { referral_on_first_payment($uid); } catch (\Throwable $e) {}
    } else {
        try { db_run('UPDATE users SET has_purchased = 1 WHERE id = ?', [$uid]); } catch (\Throwable $e) {}
    }
    promo_record_usage($r);

    // ⑧ إشعار العميل
    $end = $planId ? (db_one('SELECT ends_at FROM user_plans WHERE id = ?', [$planId])['ends_at'] ?? null) : date('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
    notify_user($uid, 'payment_approved', 'اتفعّلت باقة «' . $r['plan_name'] . '» 🎉',
        'اتأكدنا من الدفع واتضاف ' . ((int) $r['credits'] + (int) $r['bonus_credits']) . ' كريدت لمحفظتك — الباقة شغالة لحد ' . ($end ? date('Y-m-d', strtotime($end)) : '') . '.',
        'credits.php', 'ok');
    if (function_exists('admin_log')) admin_log('payment_approve', 'payment_request', $id, json_encode(['user' => $uid, 'credits' => (int) $r['credits'], 'bonus' => (int) $r['bonus_credits'], 'egp' => (float) $r['final_amount']], JSON_UNESCAPED_UNICODE));
    return ['ok' => true, 'plan_id' => $planId];
}

/** عدادات القائمة */
function billing_counts(): array
{
    $out = array_fill_keys(array_keys(billing_statuses()), 0);
    try {
        foreach (db_all('SELECT status, COUNT(*) n FROM payment_requests GROUP BY status') as $r) $out[$r['status']] = (int) $r['n'];
    } catch (\Throwable $e) {}
    return $out;
}

/** رابط عرض الإيصال (محمي — للأدمن أو صاحب الطلب) */
function billing_proof_url(int $reqId, bool $admin = false): string
{
    return url(($admin ? 'admin/' : '') . 'payment-proof.php?id=' . $reqId);
}

/** إرسال ملف الإيصال (بعد التحقق من الصلاحية في الصفحة المستدعية) */
function billing_send_proof(?string $path): void
{
    $path = (string) $path;
    $abs = $path !== '' && strpos($path, '..') === false && strpos($path, 'uploads/payments/') === 0 ? STORAGE_PATH . '/' . $path : '';
    if ($abs === '' || !is_file($abs)) {
        http_response_code(404);
        exit('الإيصال مش موجود');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($abs);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
        http_response_code(415);
        exit;
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: inline; filename="receipt-' . basename($abs) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=600');
    readfile($abs);
    exit;
}
