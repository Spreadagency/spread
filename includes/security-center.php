<?php
/**
 * Spread AI — الأمان وسلامة البيانات (MCP المرحلة 8)
 *
 *   sec_config_checks()  فحص إعدادات السيرفر والموقع (Debug · HTTPS · الأسرار · الـ Internal API · رفع الملفات · الباسوردات الافتراضية …)
 *   integrity_checks()   فحص ترابط البيانات (رصيد بالسالب · دفع اتعتمد من غير باقة · شغل واقف · فيديو مدفوع مااتبعتش …)
 *   sec_run()            الاتنين ← بيتسجلوا في قاعدة الـ AI (security_findings source=config · integrity_issues) ← Claude بيقراهم
 *                        والـ cron بيشغّله مرة في اليوم، والأدمن يقدر يشغّله من صفحة «الأمان»
 * كل الفحوصات قراءة بس — مفيش أي حاجة بتتصلّح لوحدها (الحل مكتوب جنب كل مشكلة).
 */

require_once __DIR__ . '/mcp-config.php';

function sec_ready(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        db_one('SELECT id FROM ' . mcp_t('security_findings') . ' LIMIT 1');
        db_one('SELECT id FROM ' . mcp_t('integrity_issues') . ' LIMIT 1');
        return $ok = true;
    } catch (\Throwable $e) {
        return $ok = false;
    }
}

/* ═══════════════════ فحص الإعدادات ═══════════════════ */

/**
 * @return array<int, array{key:string, title:string, ok:bool, severity:string, detail:string, fix:string}>
 */
function sec_config_checks(): array
{
    $root = dirname(__DIR__);
    $c = [];
    $add = function (string $key, string $title, bool $ok, string $sev, string $detail, string $fix) use (&$c) {
        $c[] = compact('key', 'title', 'ok', 'detail', 'fix') + ['severity' => $sev];
    };
    $const = fn(string $k, $d = '') => defined($k) ? constant($k) : $d;

    $add('app_debug', 'وضع التطوير (APP_DEBUG) مقفول', !$const('APP_DEBUG', false), 'high',
        'وضع التطوير بيعرض تفاصيل الأخطاء ومسارات الملفات للزوار وبيفتح صفحات الاختبار.', "في includes/config.php: define('APP_DEBUG', false);");
    $url = (string) $const('APP_URL', '');
    $add('https', 'الموقع على HTTPS', str_starts_with($url, 'https://'), 'high',
        'APP_URL = ' . ($url ?: '—'), 'فعّل SSL من cPanel (AutoSSL / Let\'s Encrypt) وخلي APP_URL يبدأ بـ https://');
    $add('display_errors', 'الأخطاء مش ظاهرة للزوار (display_errors)', !filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) || $const('APP_DEBUG', false), 'medium',
        'display_errors = ' . (ini_get('display_errors') ?: 'off'), 'cPanel ← MultiPHP INI Editor ← display_errors = Off');
    $cron = (string) get_setting('cron_secret', '');
    $add('cron_secret', 'سر الـ cron قوي', strlen($cron) >= 24, 'high',
        $cron === '' ? 'مش متحدد — الـ cron مش هيشتغل' : 'الطول ' . strlen($cron) . ' حرف', 'الأدمن ← التكاملات ← سر الـ cron (32 حرف عشوائي على الأقل)، وحدّث أوامر الـ cron بيه');
    $tok = (string) $const('INTERNAL_API_TOKEN', '');
    if ($tok !== '' || defined('INTERNAL_API_TOKEN')) {
        $add('iapi_token', 'توكن الـ Internal API قوي', strlen($tok) >= 48, 'critical', 'الطول ' . strlen($tok) . ' حرف', 'openssl rand -hex 32 ← INTERNAL_API_TOKEN في config.php وفي .env على الـ VPS');
        $ips = trim((string) $const('INTERNAL_API_IPS', ''));
        $add('iapi_ips', 'الـ Internal API مقفول على IP الـ VPS بس', $ips !== '', 'high', $ips !== '' ? 'IPs: ' . $ips : 'مفتوح لأي IP عنده التوكن', "define('INTERNAL_API_IPS', 'IP_الـVPS'); في config.php");
        $add('mcp_db_users', 'الـ MCP بمستخدمين MySQL محدودين', function_exists('iapi_secure_mode') && iapi_secure_mode(), 'high',
            'من غيرهم الـ Internal API بيستخدم مستخدم الموقع الكامل', 'الأدمن ← إعداد الـ MCP ← «مستخدمين MySQL» (الخطوات في sql/2026_mcp_db_users.example.sql)');
    }
    $enc = (string) $const('ENCRYPTION_KEY', '');
    $add('encryption_key', 'مفتاح التشفير موجود وقوي', strlen($enc) >= 32, 'critical', 'الطول ' . strlen($enc), 'ENCRYPTION_KEY في config.php (64 حرف) — ماتغيّروش بعد ما يتحفظ بيه مفاتيح');
    $up = $root . '/storage/uploads/.htaccess';
    $add('uploads_php', 'مجلد الرفع مايشغّلش PHP', is_file($up) && str_contains((string) @file_get_contents($up), 'php'), 'critical',
        is_file($up) ? 'storage/uploads/.htaccess موجود' : 'storage/uploads/.htaccess مش موجود', 'ارفع storage/uploads/.htaccess من الحزمة (بيمنع تشغيل أي سكريبت مرفوع)');
    $pay = (defined('UPLOADS_PATH') ? UPLOADS_PATH : $root . '/storage/uploads') . '/payments/.htaccess';
    $add('proofs_private', 'إيصالات الدفع مش مفتوحة للزوار', !is_dir(dirname($pay)) || (is_file($pay) && str_contains((string) @file_get_contents($pay), 'denied')), 'high',
        'storage/uploads/payments', 'الملف بيتعمل لوحده مع أول إيصال — لو اتمسح ارفع .htaccess فيه «Require all denied»');
    $cfg = $root . '/includes/config.php';
    $perm = is_file($cfg) ? (fileperms($cfg) & 0777) : 0;
    $add('config_perms', 'ملف الإعدادات مش متاح للكتابة للكل', $perm === 0 || ($perm & 0002) === 0, 'high', 'الصلاحيات ' . ($perm ? decoct($perm) : '—'), 'cPanel ← File Manager ← includes/config.php ← Permissions = 640 أو 644');
    $add('php_version', 'إصدار PHP مدعوم (8.1 أو أحدث)', version_compare(PHP_VERSION, '8.1.0', '>='), 'medium', 'PHP ' . PHP_VERSION, 'cPanel ← MultiPHP Manager ← PHP 8.2 أو 8.3');
    $add('session_cookie', 'كوكي الجلسة HttpOnly', (bool) ini_get('session.cookie_httponly') || session_get_cookie_params()['httponly'], 'medium', '', 'session_set_cookie_params في config.php (httponly + secure + SameSite)');
    // باسوردات أدمن سهلة/افتراضية
    $weak = [];
    try {
        foreach (db_all('SELECT id, email, password FROM admin_users WHERE status = "active"') as $a) {
            foreach (['admin', 'admin123', 'password', '123456', '12345678', 'Test12345!', 'spread123', 'Spread@123'] as $p) {
                if (password_verify($p, (string) $a['password'])) { $weak[] = $a['email']; break; }
            }
        }
    } catch (\Throwable $e) {}
    $add('admin_passwords', 'مفيش حساب أدمن بباسورد سهل أو افتراضي', !$weak, 'critical', $weak ? 'الحسابات: ' . implode('، ', $weak) : 'تمام', 'غيّر باسورد الحسابات دي من «الأدمنز» فورًا (12 حرف على الأقل)');
    // ترحيلات مستنية
    try {
        if (!function_exists('migrations_status')) require_once __DIR__ . '/migrations.php';
        $pending = array_values(array_filter(migrations_status(), fn($m) => ($m['state'] ?? '') === 'pending'));
        $add('migrations', 'كل الترحيلات اتشغّلت', !$pending, 'medium', $pending ? count($pending) . ' ترحيل مستني' : 'تمام', 'الأدمن ← فحص التحديث ← تشغيل الترحيلات');
    } catch (\Throwable $e) {}
    $add('cron_alive', 'الـ cron بتاع المحرك شغال', function_exists('ce_worker_alive') ? ce_worker_alive() : (time() - strtotime((string) get_setting('ce_worker_last_run', '2000-01-01')) < 180), 'medium',
        'آخر تشغيل: ' . (get_setting('ce_worker_last_run', '') ?: 'ولا مرة'), 'cPanel ← Cron Jobs ← كل دقيقة: php …/cron/ce-worker.php cron_secret=…');
    return $c;
}

/* ═══════════════════ ترابط البيانات ═══════════════════ */

/**
 * @return array<int, array{key:string, entity:string, ids:array, title:string, fix:string, severity:string}>
 */
function integrity_checks(): array
{
    $out = [];
    $q = function (string $key, string $entity, string $sql, string $title, string $fix, string $sev = 'medium') use (&$out) {
        try {
            $ids = array_map(fn($r) => (int) array_values($r)[0], db_all($sql . ' LIMIT 200'));
        } catch (\Throwable $e) {
            return;   // الجدول مش موجود (ترحيل لسه ماتشغّلش)
        }
        $out[] = ['key' => $key, 'entity' => $entity, 'ids' => $ids, 'title' => $title, 'fix' => $fix, 'severity' => $sev];
    };
    $q('wallet_negative', 'credit_wallets', 'SELECT user_id FROM credit_wallets WHERE balance < 0',
        'عملاء رصيدهم بالسالب', 'افتح العميل من الأدمن وراجع آخر حركات الكريدت — غالبًا خصم اتكرر أو استرداد ماحصلش', 'high');
    $q('payment_no_plan', 'payment_requests', 'SELECT id FROM payment_requests WHERE status = "approved" AND user_plan_id IS NULL AND addon_id IS NULL AND video_order_id IS NULL AND package_id IS NOT NULL AND reviewed_at > DATE_SUB(NOW(), INTERVAL 90 DAY)',
        'طلبات دفع اتعتمدت من غير ما الباقة تبدأ', 'افتح طلب الدفع: لو الكريدت اتضاف بس الباقة مااتسجلتش، ابدأ دورة للعميل من صفحته في الأدمن', 'high');
    $q('multi_active_plans', 'users', 'SELECT user_id FROM user_plans WHERE status = "active" AND ends_at > NOW() GROUP BY user_id HAVING COUNT(*) > 1',
        'عملاء ليهم أكتر من باقة شغالة في نفس الوقت', 'الأحدث هو اللي بيتحسب — الأقدم المفروض «replaced»؛ راجعه من صفحة العميل');
    $q('video_paid_not_sent', 'video_orders', 'SELECT o.id FROM video_orders o JOIN contents c ON c.id = o.content_id WHERE o.status = "paid" AND COALESCE(c.video_status, "script") IN ("script","approved")',
        'فيديو اتدفع ومااتبعتش لفريق التنفيذ', 'من «طلبات الفيديو» حط الحالة «اتبعت للتنفيذ»', 'high');
    $q('jobs_stuck', 'ce_jobs', 'SELECT id FROM ce_jobs WHERE status = "running" AND COALESCE(updated_at, created_at) < DATE_SUB(NOW(), INTERVAL 2 HOUR)',
        'شغل في المحرك واقف أكتر من ساعتين', 'اتأكد إن cron/ce-worker.php شغال كل دقيقة — الشغلانة بتكمّل أو تفشل والحجز بيرجع');
    $q('holds_open_finished', 'ce_credit_holds', 'SELECT h.id FROM ce_credit_holds h JOIN ce_jobs j ON j.hold_id = h.id WHERE h.status = "reserved" AND j.status IN ("done","failed","cancelled") AND j.finished_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)',
        'كريدت محجوز لشغل خلص (ماتسوّاش)', 'الحجز المفروض يتسوّى مع نهاية الشغلانة — بلّغ عن الأرقام دي في «طلبات التعديل» أو للمطوّر', 'high');
    $q('publish_stuck', 'ce_publish_queue', 'SELECT id FROM ce_publish_queue WHERE status = "processing" AND COALESCE(updated_at, created_at) < DATE_SUB(NOW(), INTERVAL 1 HOUR)',
        'منشورات واقفة في «بيتنشر» أكتر من ساعة', 'الـ worker بيرجّعها للطابور لوحده — لو فضلت، شوف الصفحة المربوطة (التوكن انتهى؟)');
    $q('agent_stuck', 'agent_runs', 'SELECT id FROM agent_runs WHERE status = "running" AND updated_at < DATE_SUB(NOW(), INTERVAL 1 DAY)',
        'مهام وكيل ماتحركتش من يوم', 'افتح «المساعدين» وشوف سجل المهمة — ممكن تلغيها والعميل يبدأ تاني');
    $q('prompts_multi_live', 'prompts', 'SELECT MIN(id) FROM prompts WHERE is_active = 1 GROUP BY prompt_type HAVING COUNT(*) > 1',
        'نوع برومبت فيه أكتر من إصدار مفعّل', 'من «البرومبتات» فعّل الإصدار الصح تاني (التفعيل بيقفل الباقي)');
    $q('orphan_designs', 'content_designs', 'SELECT d.id FROM content_designs d LEFT JOIN contents c ON c.id = d.content_id WHERE d.content_id IS NOT NULL AND c.id IS NULL',
        'تصميمات من غير منشور', 'مش مؤثرة على العملاء — بتاخد مساحة بس', 'low');
    $q('contents_no_user', 'contents', 'SELECT c.id FROM contents c LEFT JOIN users u ON u.id = c.user_id WHERE u.id IS NULL',
        'منشورات لعملاء اتمسحوا', 'مش ظاهرة لحد — ممكن تتمسح في التنظيف الدوري', 'low');
    $q('payments_waiting', 'payment_requests', 'SELECT id FROM payment_requests WHERE status IN ("pending","under_review") AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)',
        'طلبات دفع مستنية أكتر من 3 أيام', 'راجعها من «المدفوعات» — العميل مستني تفعيل الباقة');
    return $out;
}

/* ═══════════════════ السجلات ═══════════════════ */

function sec_logs(int $hours = 24): array
{
    $since = date('Y-m-d H:i:s', time() - $hours * 3600);
    $all = function (string $sql, array $p = []) { try { return db_all($sql, $p); } catch (\Throwable $e) { return []; } };
    $mcpDenied = [];
    try { $mcpDenied = db_all('SELECT result_status, COUNT(*) n FROM ' . mcp_t('mcp_audit_log') . ' WHERE created_at >= ? AND result_status NOT IN ("ok","200") GROUP BY result_status ORDER BY n DESC LIMIT 10', [$since]); } catch (\Throwable $e) {}
    return [
        'failed_logins' => $all('SELECT action, rate_key, COUNT(*) n FROM rate_limits WHERE action IN ("login","admin_login","iapi_auth") AND created_at >= ? GROUP BY action, rate_key ORDER BY n DESC LIMIT 15', [$since]),
        'rate_activity' => $all('SELECT action, COUNT(*) n, COUNT(DISTINCT rate_key) k FROM rate_limits WHERE created_at >= ? GROUP BY action ORDER BY n DESC LIMIT 10', [$since]),
        'mcp_denied' => $mcpDenied,
    ];
}

/* ═══════════════════ التشغيل والتسجيل ═══════════════════ */

/**
 * يشغّل الفحصين ويسجّلهم في قاعدة الـ AI
 * @return array{config_failed:int, config_total:int, integrity_open:int, integrity_total:int, critical:int}
 */
function sec_run(): array
{
    $cfg = sec_config_checks();
    $int = integrity_checks();
    if (sec_ready()) {
        foreach ($cfg as $c) {
            db_run('INSERT INTO ' . mcp_t('security_findings') . ' (source, title, severity, location, description, recommendation, status) VALUES ("config",?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE title = VALUES(title), severity = VALUES(severity), description = VALUES(description), recommendation = VALUES(recommendation), last_seen = NOW(),
                      status = IF(VALUES(status) = "fixed", "fixed", IF(status IN ("accepted_risk","false_positive","in_progress"), status, "open")),
                      fixed_at = IF(VALUES(status) = "fixed", COALESCE(fixed_at, NOW()), NULL)',
                [mb_substr($c['title'], 0, 255), $c['severity'], 'config:' . $c['key'], $c['detail'], $c['fix'], $c['ok'] ? 'fixed' : 'open']);
        }
        foreach ($int as $i) {
            db_run('INSERT INTO ' . mcp_t('integrity_issues') . ' (check_key, entity_type, entity_ids, count, description, suggested_fix, status) VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE entity_type = VALUES(entity_type), entity_ids = VALUES(entity_ids), count = VALUES(count), description = VALUES(description),
                      suggested_fix = VALUES(suggested_fix), detected_at = NOW(), status = IF(VALUES(count) = 0, "fixed", IF(status = "ignored", "ignored", "open"))',
                [$i['key'], $i['entity'], json_encode($i['ids']), count($i['ids']), $i['title'], $i['fix'], $i['ids'] ? 'open' : 'fixed']);
        }
    }
    set_setting('security_last_run', date('Y-m-d H:i:s'));
    $failed = array_filter($cfg, fn($c) => !$c['ok']);
    $crit = count(array_filter($failed, fn($c) => $c['severity'] === 'critical')) + count(array_filter($int, fn($i) => $i['ids'] && $i['severity'] === 'high'));
    return ['config_failed' => count($failed), 'config_total' => count($cfg), 'integrity_open' => count(array_filter($int, fn($i) => (bool) $i['ids'])),
            'integrity_total' => count($int), 'critical' => $crit];
}

/** من الـ cron: مرة في اليوم (بعد التقرير اليومي بساعة) — وتنبيه لو فيه حاجة خطيرة جديدة */
function sec_tick(?int $hourNow = null): ?array
{
    if (get_setting('security_auto', '1') !== '1') return null;
    $hour = max(0, min(23, (int) get_setting('daily_report_hour', '9') - 1));   // قبل التقرير بساعة علشان يدخل فيه
    if (($hourNow ?? (int) date('G')) < $hour || substr((string) get_setting('security_last_run', ''), 0, 10) === date('Y-m-d')) return null;
    $r = sec_run();
    // نتايج اختبار الموقع الأقدم من 90 يوم (قاعدة الـ AI بس)
    try { db_run('DELETE FROM ' . mcp_t('e2e_runs') . ' WHERE run_at < DATE_SUB(NOW(), INTERVAL 90 DAY)'); } catch (\Throwable $e) {}
    if ($r['critical'] > 0 && function_exists('admin_alert')) {
        try { admin_alert('danger', 'security', 'الأمان: ' . $r['critical'] . ' مشكلة خطيرة', 'افتح «الأمان» في مركز الجودة — الحل مكتوب جنب كل واحدة', 'admin/ai-security.php', 'sec:' . date('Y-m-d')); } catch (\Throwable $e) {}
    }
    return $r;
}

/** آخر نتيجة لكل سيناريو E2E */
function sec_e2e_latest(): array
{
    try {
        return db_all('SELECT e.* FROM ' . mcp_t('e2e_runs') . ' e JOIN (SELECT flow_key, MAX(id) mid FROM ' . mcp_t('e2e_runs') . ' GROUP BY flow_key) x ON x.mid = e.id ORDER BY e.status = "failed" DESC, e.flow_key');
    } catch (\Throwable $e) {
        return [];
    }
}
