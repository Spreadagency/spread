<?php
/**
 * Spread AI — الرسائل الواردة والمحادثة (خريطة التطوير · المرحلة 3)
 * فوق includes/Notifier.php (الطالع: القوالب · البث · الطابور) من غير تغيير في سلوكه.
 *
 *   - n8n بيبعت رد العميل على public/api/wa/inbound.php (نفس مفتاح X-Spread-Key)
 *   - الرسالة الواردة بيانات مش أوامر: الكلمتين الوحيدتين اللي بيتنفّذوا «موافق» و«إيقاف» — وأي حاجة تانية بتروح لصندوق وارد الفريق
 *   - الرد بيتربط بالعميل من رقمه المتحقق بس (واحد بالظبط)
 * كله واقف لحد ما ترحيل 2026_messages_inbound.sql يتشغّل والإعداد wa_inbound_enabled يتفتح.
 */
require_once __DIR__ . '/Notifier.php';
require_once __DIR__ . '/managed-accounts.php';

function msg_ready(bool $fresh = false): bool
{
    static $ok = null;
    if ($ok !== null && !$fresh) return $ok;
    try {
        db_one('SELECT id FROM message_inbound LIMIT 1');
        $ok = true;
    } catch (\Throwable $e) {
        $ok = false;
    }
    return $ok;
}

function msg_inbound_enabled(): bool
{
    return msg_ready() && get_setting('wa_inbound_enabled', '0') === '1';
}

/** العميل صاحب الرقم ده — من رقم الواتساب المتحقق بس، ولو أكتر من عميل بنفس الرقم مانربطش بحد */
function msg_user_by_phone(string $phone): ?int
{
    $n = wa_normalize_phone($phone);
    if ($n === '') return null;
    $local = strpos($n, '20') === 0 ? '0' . substr($n, 2) : $n;
    $rows = db_all('SELECT id FROM users WHERE whatsapp_verified = 1 AND status = "active" AND whatsapp_phone IN (?, ?, ?) LIMIT 2', [$n, '+' . $n, $local]);
    return count($rows) === 1 ? (int) $rows[0]['id'] : null;
}

/** الكلمة المنفّذة في الرسالة: approve · stop · none (الرسالة كلها لازم تكون الكلمة — مش جزء من جملة) */
function msg_keyword(string $text): string
{
    $t = mb_strtolower(trim(preg_replace('/[\s\.\!،,؟\?✅👍]+/u', ' ', $text)));
    $t = str_replace(['أ', 'إ', 'آ'], 'ا', $t);
    if (in_array($t, ['موافق', 'موافقه', 'موافقة', 'اوافق', 'تمام موافق', 'ok', 'approve'], true)) return 'approve';
    if (in_array($t, ['ايقاف', 'وقف', 'الغاء', 'stop', 'unsubscribe'], true)) return 'stop';
    return 'none';
}

/**
 * استقبال رسالة واردة.
 * @return array{ok:bool, id?:int, user_id?:?int, action?:string, duplicate?:bool, error?:string}
 */
function msg_inbound_receive(string $phone, string $text, string $providerId = ''): array
{
    if (!msg_ready()) return ['ok' => false, 'error' => 'not_ready'];
    $n = wa_normalize_phone($phone);
    $text = mb_substr(trim($text), 0, 4000);
    if ($n === '' || $text === '') return ['ok' => false, 'error' => 'bad_input'];
    $providerId = mb_substr(trim($providerId), 0, 100) ?: null;
    if ($providerId !== null && ($dup = db_one('SELECT id FROM message_inbound WHERE provider_id = ?', [$providerId]))) {
        return ['ok' => true, 'id' => (int) $dup['id'], 'duplicate' => true];
    }
    $uid = msg_user_by_phone($n);
    $action = $uid ? msg_keyword($text) : 'none';
    $result = null;
    $handled = false;
    if ($action === 'approve') {
        $cnt = 0;
        foreach (ma_pending_for_user($uid) as $p) if (ma_client_decide($uid, (int) $p['id'], 'approve')) $cnt++;
        if ($cnt > 0) { $result = 'وافق على ' . $cnt . ' منشور'; $handled = true; }
        else { $action = 'none'; $result = 'كتب «موافق» ومفيش منشورات مستنية'; }
    } elseif ($action === 'stop') {
        msg_marketing_stop($uid);
        $result = 'وقّف الرسائل التسويقية على الواتساب (رسائل الحساب المهمة مستمرة)';
        $handled = true;
    }
    try {
        $id = db_insert('INSERT INTO message_inbound (user_id, wa_phone, body, provider_id, action, action_result, handled_at) VALUES (?,?,?,?,?,?,?)',
            [$uid, $n, $text, $providerId, $action, $result, $handled ? date('Y-m-d H:i:s') : null]);
    } catch (\Throwable $e) {
        // نفس provider_id وصل مرتين في نفس اللحظة
        if ($providerId !== null && ($dup = db_one('SELECT id FROM message_inbound WHERE provider_id = ?', [$providerId]))) return ['ok' => true, 'id' => (int) $dup['id'], 'duplicate' => true];
        throw $e;
    }
    return ['ok' => true, 'id' => $id, 'user_id' => $uid, 'action' => $action];
}

/**
 * «إيقاف» (المرحلة 6): بيوقف الرسائل التسويقية بس على الواتساب —
 *   كل حدث مش مهم (is_critical = 0) ← واتساب مقفول في تفضيلاته (جوه المنصة زي ما هو)
 *   + علامة __marketing ← البث الجماعي مايوصلوش على الواتساب
 * رسائل الحساب المهمة (كود التحقق · طلب الموافقة · فشل النشر …) ورسائل مسؤول الحساب المباشرة بتفضل توصل.
 * العميل يرجّعها من «الإشعارات» (تشغيل الواتساب بيشيل العلامة).
 */
function msg_marketing_stop(int $uid): void
{
    $keys = array_column(db_all('SELECT event_key FROM message_templates WHERE is_critical = 0'), 'event_key');
    $keys[] = WA_MARKETING_KEY;
    foreach ($keys as $k) {
        db_run('INSERT INTO user_notification_prefs (user_id, event_key, whatsapp, inapp) VALUES (?, ?, 0, 1)
                ON DUPLICATE KEY UPDATE whatsapp = 0', [$uid, (string) $k]);
    }
}

/** محادثة عميل: الطالع (messages) + الوارد (message_inbound) مرتبين بالوقت — الأحدث في الآخر */
function msg_conversation(int $uid, int $limit = 60): array
{
    $out = [];
    try {
        foreach (db_all('SELECT id, type, event_key, title, body, channel, wa_status, wa_error, admin_id, created_at FROM messages WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$uid]) as $m) {
            $out[] = ['dir' => 'out', 'at' => $m['created_at'], 'body' => trim((string) $m['body']), 'title' => (string) $m['title'], 'type' => (string) $m['type'],
                      'channel' => (string) $m['channel'], 'wa_status' => (string) $m['wa_status'], 'wa_error' => (string) ($m['wa_error'] ?? ''), 'admin_id' => (int) ($m['admin_id'] ?? 0)];
        }
    } catch (\Throwable $e) {}
    if (msg_ready()) {
        foreach (db_all('SELECT id, body, action, action_result, handled_at, created_at FROM message_inbound WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit, [$uid]) as $m) {
            $out[] = ['dir' => 'in', 'at' => $m['created_at'], 'body' => (string) $m['body'], 'id' => (int) $m['id'], 'action' => (string) $m['action'],
                      'action_result' => (string) ($m['action_result'] ?? ''), 'handled' => !empty($m['handled_at'])];
        }
    }
    usort($out, fn($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
    return array_slice($out, -$limit);
}

/** صندوق الوارد: الرسائل اللي لسه محدش رد عليها. $managerId = حسابات المسؤول ده بس (null = الكل، ومعاهم الأرقام غير المربوطة) */
function msg_unhandled(?int $managerId, int $limit = 100): array
{
    if (!msg_ready()) return [];
    $w = 'i.handled_at IS NULL';
    $p = [];
    if ($managerId !== null) { $w .= ' AND m.manager_admin_id = ?'; $p[] = $managerId; }
    return db_all("SELECT i.*, u.name user_name, m.user_id managed, a.name manager_name
                   FROM message_inbound i LEFT JOIN users u ON u.id = i.user_id
                   LEFT JOIN managed_accounts m ON m.user_id = i.user_id
                   LEFT JOIN admin_users a ON a.id = m.manager_admin_id
                   WHERE $w ORDER BY i.id DESC LIMIT " . (int) $limit, $p);
}

function msg_unhandled_count(): int
{
    if (!msg_ready()) return 0;
    try {
        return (int) (db_one('SELECT COUNT(*) n FROM message_inbound WHERE handled_at IS NULL')['n'] ?? 0);
    } catch (\Throwable $e) {
        return 0;
    }
}

function msg_mark_handled(int $inboundId, int $adminId): void
{
    if (msg_ready()) db_run('UPDATE message_inbound SET handled_at = NOW(), handled_by = ? WHERE id = ? AND handled_at IS NULL', [$adminId, $inboundId]);
}

/** الأدمن يقدر يتعامل مع الرسالة دي؟ (نفس قاعدة المساحة: المساعد = حساباته بس) */
function msg_can_handle(array $inbound): bool
{
    $a = function_exists('current_admin') ? current_admin() : null;
    if (!$a) return false;
    if (in_array(($a['role'] ?? 'super'), ['manager', 'super'], true)) return true;
    return !empty($inbound['user_id']) && ma_can_access((int) $inbound['user_id']);
}

/** تذكير بالمنشورات المستنية موافقة من أكتر من 24 ساعة (مرة كل 24 ساعة بالكتير لكل عميل) — بيتنادى من الكرون اليومي */
function msg_approval_reminders(): int
{
    if (!ma_ready()) return 0;
    $n = 0;
    $rows = db_all("SELECT c.user_id, COUNT(*) cnt FROM contents c JOIN managed_accounts m ON m.user_id = c.user_id
                    WHERE c.approval_status = 'pending' AND c.updated_at < NOW() - INTERVAL 24 HOUR
                      AND NOT EXISTS (SELECT 1 FROM messages x WHERE x.user_id = c.user_id AND x.event_key IN ('approval_request', 'approval_reminder') AND x.created_at >= NOW() - INTERVAL 24 HOUR)
                    GROUP BY c.user_id LIMIT 500");
    foreach ($rows as $r) {
        $uid = (int) $r['user_id'];
        if (Notifier::notify($uid, 'approval_reminder', ['count' => (int) $r['cnt']], ['url' => 'approvals.php?t=' . ma_token($uid), 'queue_only' => true])) $n++;
    }
    return $n;
}
