<?php
/**
 * Spread AI — أساس إدارة الحسابات (خريطة التطوير · المرحلة 2)
 *
 * 4 قواعد:
 *   1) كل حساب مُدار ليه مسؤول واحد (managed_accounts)
 *   2) كل منشور الفريق عمله باسم العميل متعلّم (contents.acted_by_admin_id) والعميل بيشوف ده
 *   3) مفيش نشر من غير موافقة العميل في مستوى الخدمة «approval» (contents.approval_status)
 *   4) الفلوس بره الصلاحية — مفيش أي حاجة هنا بتلمس الكريدت أو الباقات
 *
 * قبل ترحيل 2026_managed_accounts.sql كل الدوال دي بتتصرف كأن الميزة مش موجودة (ma_ready() = false)،
 * وعلامة ⭐ القديمة (الإعداد managed_client_ids) بتفضل شغالة زي ما هي.
 * الدخول بحساب العميل (admin/impersonate.php) زي ما هو — مفيش تغيير فيه.
 */

const MA_LEVELS = ['approval' => 'العميل يوافق قبل النشر', 'auto' => 'الفريق ينشر مباشرة'];
const MA_APPROVAL = ['draft' => 'جهّزه الفريق', 'pending' => 'مستني موافقتك', 'approved' => 'موافق عليه', 'changes' => 'مطلوب تعديل'];
const MA_TOKEN_TTL = 72 * 3600;

function ma_ready(bool $fresh = false): bool
{
    static $ok = null;
    if ($ok !== null && !$fresh) return $ok;
    try {
        db_one('SELECT user_id FROM managed_accounts LIMIT 1');
        db_one('SELECT approval_status, acted_by_admin_id FROM contents LIMIT 1');
        $ok = true;
    } catch (\Throwable $e) {
        $ok = false;
    }
    if ($ok) ma_import_once();
    return $ok;
}

/** نقل علامات ⭐ القديمة للجدول (مرة واحدة) — الإعداد القديم مابيتمسحش عشان الرجوع يفضل ممكن */
function ma_import_once(): void
{
    if (!function_exists('get_setting') || get_setting('managed_accounts_imported', '0') === '1') return;
    $v = json_decode((string) get_setting('managed_client_ids', '[]'), true);
    foreach (array_unique(array_filter(array_map('intval', is_array($v) ? $v : []))) as $uid) {
        try {
            if (db_one('SELECT id FROM users WHERE id = ?', [$uid])) {
                db_run('INSERT IGNORE INTO managed_accounts (user_id, started_at) VALUES (?, CURDATE())', [$uid]);
            }
        } catch (\Throwable $e) {}
    }
    set_setting('managed_accounts_imported', '1');
}

function ma_get(int $uid): ?array
{
    if (!ma_ready()) return null;
    return db_one('SELECT * FROM managed_accounts WHERE user_id = ?', [$uid]) ?: null;
}

function ma_ids(): array
{
    if (!ma_ready()) return [];
    return array_map('intval', array_column(db_all('SELECT user_id FROM managed_accounts'), 'user_id'));
}

function ma_add(int $uid, ?int $managerId = null): void
{
    if (!ma_ready()) return;
    db_run('INSERT IGNORE INTO managed_accounts (user_id, manager_admin_id, started_at) VALUES (?, ?, CURDATE())', [$uid, $managerId]);
}

function ma_remove(int $uid): void
{
    if (!ma_ready()) return;
    db_run('DELETE FROM managed_accounts WHERE user_id = ?', [$uid]);
}

/** تعديل إعدادات الحساب المُدار (المسؤول · مستوى الخدمة · المطلوب في الشهر) */
function ma_update(int $uid, ?int $managerId, string $level, int $monthly): void
{
    if (!ma_ready()) return;
    if ($managerId !== null && !db_one('SELECT id FROM admin_users WHERE id = ? AND status = "active"', [$managerId])) $managerId = null;
    db_run('UPDATE managed_accounts SET manager_admin_id = ?, service_level = ?, monthly_posts = ? WHERE user_id = ?',
        [$managerId, isset(MA_LEVELS[$level]) ? $level : 'approval', max(0, min(500, $monthly)), $uid]);
}

/** الأدمن الحالي يقدر يفتح مساحة العميل ده؟ assistant = حساباته بس · manager و super = الكل */
function ma_can_access(int $uid): bool
{
    $a = function_exists('current_admin') ? current_admin() : null;
    if (!$a) return false;
    if (in_array(($a['role'] ?? 'super'), ['manager', 'super'], true)) return true;
    $m = ma_get($uid);
    return $m && (int) $m['manager_admin_id'] === (int) $a['id'];
}

/* ═══════════════ لينك الموافقة (موقّع · 72 ساعة · بيفتح الموافقات بس) ═══════════════ */

function ma_secret(): string
{
    return defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '' ? hash('sha256', 'ma-approval|' . ENCRYPTION_KEY) : '';
}

function ma_token(int $uid, ?int $exp = null): string
{
    $k = ma_secret();
    if ($k === '') return '';
    $exp = $exp ?? time() + MA_TOKEN_TTL;
    $body = $uid . '.' . $exp;
    return $body . '.' . substr(hash_hmac('sha256', $body, $k), 0, 40);
}

/** رقم العميل من التوكن — 0 لو غلط أو منتهي */
function ma_token_uid(string $token): int
{
    $k = ma_secret();
    if ($k === '' || !preg_match('/^(\d{1,10})\.(\d{10})\.([0-9a-f]{40})$/', $token, $m)) return 0;
    if (!hash_equals(substr(hash_hmac('sha256', $m[1] . '.' . $m[2], $k), 0, 40), $m[3])) return 0;
    return (int) $m[2] >= time() ? (int) $m[1] : 0;
}

function ma_approval_link(int $uid): string
{
    $t = ma_token($uid);
    return $t === '' ? url('approvals.php') : url('approvals.php') . '?t=' . $t;
}

/* ═══════════════ الموافقة ═══════════════ */

/**
 * ليه المنشور ده ماينفعش يتنشر/يتجدول دلوقتي؟ (null = مفيش مانع)
 *   - المنشور في مسار الموافقة ولسه العميل ماوافقش
 *   - أو الفريق داخل بحساب عميل مُدار بمستوى «approval» وبيحاول ينشر حاجة العميل ماوافقش عليها
 */
function ma_publish_block(array $content): ?string
{
    if (!ma_ready()) return null;
    $st = (string) ($content['approval_status'] ?? '');
    if (in_array($st, ['draft', 'pending', 'changes'], true)) {
        return $st === 'changes' ? 'العميل طلب تعديل على المنشور ده — عدّله وابعته للموافقة تاني'
            : 'المنشور ده مستني موافقة العميل — مش هيتنشر قبل ما يوافق';
    }
    if ($st !== 'approved' && !empty($_SESSION['impersonator_admin_id'])) {
        $m = ma_get((int) ($content['user_id'] ?? 0));
        if ($m && $m['service_level'] === 'approval') return 'الحساب ده بموافقة العميل — ابعت المنشور للموافقة من «مساحة العميل» الأول';
    }
    return null;
}

/** شرط SQL للـ cron: المنشورات اللي في مسار الموافقة ماتتنشرش قبل الموافقة */
function ma_publish_sql(string $alias = ''): string
{
    if (!ma_ready()) return '';
    $c = ($alias !== '' ? $alias . '.' : '') . 'approval_status';
    return " AND ($c IS NULL OR $c = 'approved')";
}

/** الفريق بيبعت منشورات لموافقة العميل ← عدد اللي اتبعت */
function ma_send_for_approval(int $uid, array $contentIds, int $adminId): int
{
    if (!ma_ready()) return 0;
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $contentIds)))), 0, 100);
    if (!$ids) return 0;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    // المؤهّل بس: ملك العميل · لسه ماتنشرش · مش مستني موافقة أو متوافق عليه بالفعل
    $ok = array_map('intval', array_column(db_all("SELECT id FROM contents WHERE user_id = ? AND id IN ($ph)
                 AND (approval_status IS NULL OR approval_status IN ('draft', 'changes'))
                 AND (publish_status IS NULL OR publish_status NOT IN ('published', 'processing'))", array_merge([$uid], $ids)), 'id'));
    $n = count($ok);
    if ($n > 0) {
        db_run("UPDATE contents SET approval_status = 'pending', approval_note = NULL, approval_at = NULL, acted_by_admin_id = COALESCE(acted_by_admin_id, ?)
                WHERE user_id = ? AND id IN (" . implode(',', $ok) . ')', [$adminId, $uid]);
    }
    if ($n > 0) {
        try {
            // إشعار جوه المنصة + واتساب بلينك الموافقة الموقّع (قالب approval_request) — ولو القالب مش متاح: إشعار جوه المنصة بس
            if (!class_exists('Notifier') && is_file(__DIR__ . '/Notifier.php')) require_once __DIR__ . '/Notifier.php';
            $sent = class_exists('Notifier') ? Notifier::notify($uid, 'approval_request', ['count' => $n], ['url' => 'approvals.php?t=' . ma_token($uid)]) : null;
            if (!$sent && function_exists('wa_inapp_insert')
                && !db_one('SELECT id FROM user_notifications WHERE user_id = ? AND kind = "approval_request" AND created_at >= NOW() - INTERVAL 30 MINUTE', [$uid])) {
                wa_inapp_insert($uid, 'approval_request', 'منشورات جاهزة لمراجعتك', 'فريق Spread جهّزلك ' . $n . ' منشور — راجعهم ووافق بضغطة.', 'approvals.php');
            }
        } catch (\Throwable $e) {
            error_log('[managed] notify: ' . $e->getMessage());
        }
    }
    return $n;
}

/** قرار العميل على منشور مستني موافقته */
function ma_client_decide(int $uid, int $contentId, string $decision, string $note = ''): bool
{
    if (!ma_ready() || !in_array($decision, ['approve', 'changes'], true)) return false;
    $c = db_one("SELECT id FROM contents WHERE id = ? AND user_id = ? AND approval_status = 'pending'", [$contentId, $uid]);
    if (!$c) return false;
    if ($decision === 'approve') {
        db_run("UPDATE contents SET approval_status = 'approved', approval_note = NULL, approval_at = NOW() WHERE id = ?", [$contentId]);
    } else {
        db_run("UPDATE contents SET approval_status = 'changes', approval_note = ?, approval_at = NOW() WHERE id = ?", [mb_substr(trim($note), 0, 500) ?: null, $contentId]);
    }
    return true;
}

function ma_pending_for_user(int $uid): array
{
    if (!ma_ready()) return [];
    return db_all("SELECT id, generated_text, cta, hashtags, format, scheduled_at, created_at FROM contents
                   WHERE user_id = ? AND approval_status = 'pending' ORDER BY COALESCE(scheduled_at, created_at), id LIMIT 60", [$uid]);
}

/* ═══════════════ «اتعمل بواسطة الفريق» ═══════════════ */

/**
 * بتتنادى من require_login() و api_boot(): لو الفريق داخل بحساب عميل مُدار، أي منشور اتعمل في الطلب ده
 * بيتعلّم باسم الأدمن — وفي مستوى «approval» بيدخل مسار الموافقة كـ draft.
 * (المنشورات اللي بتتعمل في الخلفية بعد الطلب بتتبعت للموافقة من «مساحة العميل».)
 */
function ma_impersonation_hook(): void
{
    static $done = false;
    if ($done || empty($_SESSION['impersonator_admin_id']) || empty($_SESSION['user_id'])) return;
    $done = true;
    $uid = (int) $_SESSION['user_id'];
    $adminId = (int) $_SESSION['impersonator_admin_id'];
    $m = ma_get($uid);
    if (!$m) return;
    try {
        $mark = (int) (db_one('SELECT COALESCE(MAX(id), 0) n FROM contents WHERE user_id = ?', [$uid])['n'] ?? 0);
    } catch (\Throwable $e) {
        return;
    }
    $level = (string) $m['service_level'];
    register_shutdown_function(function () use ($uid, $adminId, $mark, $level) {
        try {
            db_run('UPDATE contents SET acted_by_admin_id = ?, approval_status = ? WHERE user_id = ? AND id > ? AND acted_by_admin_id IS NULL',
                [$adminId, $level === 'approval' ? 'draft' : null, $uid, $mark]);
        } catch (\Throwable $e) {
            error_log('[managed] stamp: ' . $e->getMessage());
        }
    });
}

/* ═══════════════ الأرقام ═══════════════ */

/** أرقام شهر لحساب واحد (YYYY-MM) */
function ma_month_stats(int $uid, ?string $ym = null): array
{
    $ym = preg_match('/^\d{4}-\d{2}$/', (string) $ym) ? $ym : date('Y-m');
    $from = $ym . '-01 00:00:00';
    $to = date('Y-m-d H:i:s', strtotime($from . ' +1 month'));
    $r = db_one("SELECT COUNT(*) made,
                        SUM(acted_by_admin_id IS NOT NULL) by_team,
                        SUM(approval_status = 'pending') pending,
                        SUM(approval_status = 'approved') approved,
                        SUM(approval_status = 'changes') changes,
                        SUM(publish_status = 'published') published,
                        SUM(publish_status = 'failed') failed,
                        SUM(publish_status IN ('pending', 'scheduled', 'processing')) scheduled
                 FROM contents WHERE user_id = ? AND created_at >= ? AND created_at < ?", [$uid, $from, $to]) ?: [];
    return array_map('intval', $r) + ['ym' => $ym];
}

/** كروت «حساباتي»: استعلام مجمّع واحد لكل مصدر (من غير N+1). $managerId = null ← كل الحسابات */
function ma_overview(?int $managerId): array
{
    if (!ma_ready()) return [];
    $w = $managerId === null ? '' : ' WHERE m.manager_admin_id = ?';
    $p = $managerId === null ? [] : [$managerId];
    $rows = db_all("SELECT m.*, u.name, u.email, u.phone, a.name manager_name, COALESCE(w.balance, 0) balance
                    FROM managed_accounts m JOIN users u ON u.id = m.user_id
                    LEFT JOIN admin_users a ON a.id = m.manager_admin_id
                    LEFT JOIN credit_wallets w ON w.user_id = m.user_id" . $w . ' ORDER BY u.name', $p);
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int) $r['user_id'], $rows);
    $in = implode(',', $ids);
    $from = date('Y-m-01 00:00:00');
    $stats = [];
    foreach (db_all("SELECT user_id,
                            SUM(created_at >= ?) made,
                            SUM(approval_status = 'pending') pending,
                            SUM(approval_status = 'changes') changes,
                            SUM(publish_status = 'failed') failed,
                            SUM(publish_status = 'published' AND created_at >= ?) published
                     FROM contents WHERE user_id IN ($in) GROUP BY user_id", [$from, $from]) as $s) {
        $stats[(int) $s['user_id']] = array_map('intval', $s);
    }
    $tasks = [];
    foreach (db_all("SELECT user_id, COUNT(*) n FROM managed_notes WHERE user_id IN ($in) AND kind = 'task' AND done_at IS NULL GROUP BY user_id") as $t) {
        $tasks[(int) $t['user_id']] = (int) $t['n'];
    }
    $low = max(0, (int) get_setting('wa_credits_low_at', '10'));
    foreach ($rows as &$r) {
        $s = $stats[(int) $r['user_id']] ?? [];
        $r['made'] = (int) ($s['made'] ?? 0);
        $r['pending'] = (int) ($s['pending'] ?? 0);
        $r['changes'] = (int) ($s['changes'] ?? 0);
        $r['failed'] = (int) ($s['failed'] ?? 0);
        $r['published'] = (int) ($s['published'] ?? 0);
        $r['tasks'] = $tasks[(int) $r['user_id']] ?? 0;
        $r['low_credit'] = (int) $r['balance'] <= $low;
    }
    unset($r);
    return $rows;
}
