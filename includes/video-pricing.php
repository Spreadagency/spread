<?php
/**
 * Spread AI — الفيديو بالفلوس (المرحلة 6)
 *
 * الأدمن بيحدد أنواع الفيديو (ريلز · إعلان · موشن …) ولكل نوع مدد بأسعارها بالجنيه ومدة التنفيذ،
 * ولينكات أمثلة («شوف فيديوهات زيه»). كل باقة ليها خصم على الفيديو (لقطة وقت الاشتراك).
 *
 * الرحلة: السكريبت ← العميل يعتمده ← يختار النوع والمدة ويشوف السعر بعد خصمه ← «اطلب وادفع»
 *         ← طلب دفع عادي (checkout.php?video=ID) ← الأدمن يعتمد الدفع ← الفيديو بيروح لفريق التنفيذ (sent)
 *         ← بعدها نفس مسار «طلبات الفيديو» القديم (قيد التنفيذ · جاهز · اتسلّم).
 * الفيديو مالوش كريدت ولا بيلمس المحفظة. مفيش أنواع متفعّلة ← الرحلة القديمة بواتساب زي ما هي.
 */

const VP_MAX_DURATIONS = 8;
const VP_MAX_SAMPLES = 8;

function vp_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM video_types LIMIT 1');
        db_one('SELECT id FROM video_orders LIMIT 1');
        db_one('SELECT video_order_id FROM payment_requests LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/** طلب الفيديو بالدفع شغال؟ (جاهز + متفعّل + فيه نوع واحد على الأقل بسعر) */
function vp_on(): bool
{
    return vp_ready() && get_setting('video_orders_enabled', '1') === '1' && vp_types() !== [];
}

/* ═══════════════════ الأنواع ═══════════════════ */

/** المدد: [{label, seconds, price_egp, days}] — من غير سعر أو اسم بتتشال */
function vp_clean_durations($in): array
{
    $out = [];
    foreach (is_array($in) ? $in : [] as $d) {
        if (!is_array($d)) continue;
        $label = mb_substr(trim((string) ($d['label'] ?? '')), 0, 60);
        $price = round(max(0, min(1000000, (float) ($d['price_egp'] ?? 0))), 2);
        if ($label === '' || $price <= 0) continue;
        $out[] = ['label' => $label, 'seconds' => max(0, min(3600, (int) ($d['seconds'] ?? 0))),
                  'price_egp' => $price, 'days' => max(0, min(90, (int) ($d['days'] ?? 0)))];
        if (count($out) >= VP_MAX_DURATIONS) break;
    }
    return $out;
}

/** الأمثلة: [{title, url}] — http/https بس */
function vp_clean_samples($in): array
{
    $out = [];
    foreach (is_array($in) ? $in : [] as $s) {
        if (!is_array($s)) continue;
        $url = trim((string) ($s['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL) || strlen($url) > 500) continue;
        $out[] = ['title' => mb_substr(trim((string) ($s['title'] ?? '')), 0, 120), 'url' => $url];
        if (count($out) >= VP_MAX_SAMPLES) break;
    }
    return $out;
}

function vp_decode(array $r): array
{
    $r['id'] = (int) $r['id'];
    $r['durations'] = vp_clean_durations(json_decode((string) ($r['durations_json'] ?? ''), true));
    $r['samples'] = vp_clean_samples(json_decode((string) ($r['samples_json'] ?? ''), true));
    return $r;
}

/** الأنواع (المتفعّلة بس = اللي ليها مدة بسعر) · $fresh بعد التعديل في نفس الطلب */
function vp_types(bool $activeOnly = true, bool $fresh = false): array
{
    static $cache = null;
    if (!vp_ready()) return [];
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (db_all('SELECT * FROM video_types ORDER BY sort_order, id') as $r) $cache[(int) $r['id']] = vp_decode($r);
    }
    return $activeOnly ? array_filter($cache, fn($t) => (int) $t['is_active'] === 1 && $t['durations'] !== []) : $cache;
}

function vp_type(int $id, bool $activeOnly = true): ?array
{
    return vp_types($activeOnly)[$id] ?? null;
}

/* ═══════════════════ السعر ═══════════════════ */

/** خصم الفيديو في باقة العميل الشغالة (لقطة وقت الاشتراك) */
function vp_discount_pct(int $uid): float
{
    if (!function_exists('plan_active')) return 0.0;
    $p = plan_active($uid);
    if (!$p || !empty($p['synthetic'])) return 0.0;
    return max(0.0, min(100.0, (float) ($p['video_discount_pct'] ?? 0)));
}

/** سعر نوع + مدة للعميل ← null لو المدة مش موجودة */
function vp_quote(int $uid, array $type, int $durIdx): ?array
{
    $d = $type['durations'][$durIdx] ?? null;
    if (!$d) return null;
    $pct = vp_discount_pct($uid);
    $base = (float) $d['price_egp'];
    $disc = round($base * $pct / 100, 2);
    return ['type_id' => (int) $type['id'], 'type_name' => (string) $type['name'], 'dur' => $durIdx, 'label' => $d['label'],
            'seconds' => (int) $d['seconds'], 'days' => (int) $d['days'],
            'base' => $base, 'pct' => $pct, 'discount' => $disc, 'final' => max(0.0, round($base - $disc, 2))];
}

function vp_money(float $n): string
{
    return number_format($n, fmod($n, 1.0) == 0.0 ? 0 : 2) . ' جنيه';
}

/* ═══════════════════ الطلبات ═══════════════════ */

function vp_order(int $id): ?array
{
    return vp_ready() ? (db_one('SELECT * FROM video_orders WHERE id = ?', [$id]) ?: null) : null;
}

/** آخر طلب شغال للمحتوى (مدفوع أو مستني الدفع) */
function vp_order_for_content(int $contentId): ?array
{
    if (!vp_ready()) return null;
    return db_one('SELECT * FROM video_orders WHERE content_id = ? AND status IN ("paid","pending_payment") ORDER BY status = "paid" DESC, id DESC LIMIT 1', [$contentId]) ?: null;
}

/** طلب الدفع اللي لسه بيتراجع للطلب ده */
function vp_order_pending_payment(array $o): ?array
{
    if (!vp_ready()) return null;
    return db_one('SELECT id, status FROM payment_requests WHERE video_order_id = ? AND status IN ("pending","under_review") ORDER BY id DESC LIMIT 1', [(int) $o['id']]) ?: null;
}

function vp_order_label(array $o): string
{
    return (string) $o['type_name'] . ' — ' . (string) $o['duration_label'];
}

/**
 * العميل اختار النوع والمدة ← طلب مستني الدفع (أو تحديث الطلب اللي لسه مادفعش)
 * @return array{ok:bool, order?:array, error?:string, code?:string, payment_id?:int}
 */
function vp_order_create(int $uid, array $content, int $typeId, int $durIdx): array
{
    if (!vp_on()) return ['ok' => false, 'error' => 'طلب الفيديو بالدفع مش متاح دلوقتي', 'code' => 'off'];
    if ((int) $content['user_id'] !== $uid) return ['ok' => false, 'error' => 'المحتوى مش موجود', 'code' => 'not_found'];
    $st = (string) ($content['video_status'] ?: 'script');
    if ($st === 'script') return ['ok' => false, 'error' => 'اعتمد السكريبت الأول', 'code' => 'state'];
    if ($st !== 'approved') return ['ok' => false, 'error' => 'الفيديو ده اتبعت للتنفيذ خلاص', 'code' => 'state'];
    $type = vp_type($typeId);
    if (!$type) return ['ok' => false, 'error' => 'نوع الفيديو ده مش متاح', 'code' => 'invalid'];
    $q = vp_quote($uid, $type, $durIdx);
    if (!$q) return ['ok' => false, 'error' => 'اختار مدة الفيديو', 'code' => 'invalid'];

    $cur = vp_order_for_content((int) $content['id']);
    if ($cur && $cur['status'] === 'paid') return ['ok' => false, 'error' => 'الفيديو ده اتدفع خلاص وبيتنفّذ', 'code' => 'paid'];
    if ($cur && ($pr = vp_order_pending_payment($cur))) {
        return ['ok' => false, 'error' => 'عندك طلب دفع للفيديو ده لسه بيتراجع (#' . (int) $pr['id'] . ')', 'code' => 'pending', 'payment_id' => (int) $pr['id']];
    }
    $row = [$typeId, $q['type_name'], $q['label'], $q['seconds'], $q['days'], $q['base'], $q['pct'], $q['final']];
    if ($cur) {
        db_run('UPDATE video_orders SET type_id = ?, type_name = ?, duration_label = ?, seconds = ?, delivery_days = ?, base_egp = ?, discount_pct = ?, final_egp = ?, payment_request_id = NULL
                 WHERE id = ? AND status = "pending_payment"', array_merge($row, [(int) $cur['id']]));
        $id = (int) $cur['id'];
    } else {
        $id = (int) db_insert('INSERT INTO video_orders (type_id, type_name, duration_label, seconds, delivery_days, base_egp, discount_pct, final_egp, user_id, content_id)
                               VALUES (?,?,?,?,?,?,?,?,?,?)', array_merge($row, [$uid, (int) $content['id']]));
    }
    return ['ok' => true, 'order' => vp_order($id)];
}

/** طلب دفع اتعمل للطلب */
function vp_order_link_payment(int $orderId, int $reqId): void
{
    db_run('UPDATE video_orders SET payment_request_id = ? WHERE id = ?', [$reqId, $orderId]);
}

/**
 * الأدمن اعتمد الدفع ← الطلب «مدفوع» والفيديو بيروح لفريق التنفيذ
 * @return array{ok:bool, error?:string}
 */
function vp_order_paid(array $pr, int $adminId): array
{
    $o = vp_order((int) $pr['video_order_id']);
    if (!$o) return ['ok' => false, 'error' => 'طلب الفيديو مش موجود'];
    if ($o['status'] === 'cancelled') return ['ok' => false, 'error' => 'طلب الفيديو اتلغى'];
    if ($o['status'] !== 'paid') {
        db_run('UPDATE video_orders SET status = "paid", paid_at = NOW(), payment_request_id = ? WHERE id = ? AND status = "pending_payment"', [(int) $pr['id'], (int) $o['id']]);
    }
    $c = db_one('SELECT id, video_status FROM contents WHERE id = ?', [(int) $o['content_id']]);
    if ($c && in_array((string) ($c['video_status'] ?: 'script'), ['script', 'approved'], true) && function_exists('video_set_status')) {
        video_set_status((int) $c['id'], 'sent');
    }
    if (function_exists('admin_alert')) {
        try { admin_alert('info', 'video_order', 'فيديو مدفوع #' . (int) $o['id'], vp_order_label($o) . ' · ' . vp_money((float) $o['final_egp']), 'admin/video-requests.php?status=sent', 'vo:' . (int) $o['id']); } catch (\Throwable $e) {}
    }
    return ['ok' => true];
}

/* ═══════════════════ للواجهة ═══════════════════ */

/** الأنواع بأسعار العميل + الطلب الحالي — بيترجع جوه d.video */
function vp_client(int $uid, array $content): array
{
    if (!vp_on()) return ['on' => false];
    $pct = vp_discount_pct($uid);
    $types = [];
    foreach (vp_types() as $t) {
        $durs = [];
        foreach ($t['durations'] as $i => $_) {
            $q = vp_quote($uid, $t, $i);
            $durs[] = ['i' => $i, 'label' => $q['label'], 'seconds' => $q['seconds'], 'days' => $q['days'], 'base' => $q['base'], 'final' => $q['final']];
        }
        $types[] = ['id' => $t['id'], 'name' => (string) $t['name'], 'desc' => (string) ($t['description'] ?? ''), 'durations' => $durs, 'samples' => $t['samples']];
    }
    $o = vp_order_for_content((int) $content['id']);
    $order = null;
    if ($o) {
        $pr = $o['status'] === 'pending_payment' ? vp_order_pending_payment($o) : null;
        $order = ['id' => (int) $o['id'], 'status' => (string) $o['status'], 'type_id' => (int) $o['type_id'], 'label' => vp_order_label($o),
                  'final' => (float) $o['final_egp'], 'base' => (float) $o['base_egp'], 'pct' => (float) $o['discount_pct'], 'days' => (int) $o['delivery_days'],
                  'payment_id' => $pr ? (int) $pr['id'] : null,
                  'checkout' => url('checkout.php?video=' . (int) $o['id']), 'payment_url' => $pr ? url('payments.php?id=' . (int) $pr['id']) : null];
    }
    return ['on' => true, 'discount_pct' => $pct, 'types' => $types, 'order' => $order];
}

/** خصم الفيديو بتاع الباقة (للعرض في صفحة الباقات) */
function vp_package_discount(array $pkg): float
{
    return vp_ready() ? max(0.0, min(100.0, (float) ($pkg['video_discount_pct'] ?? 0))) : 0.0;
}
