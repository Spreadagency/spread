<?php
/**
 * Spread AI — إعدادات نظام الجودة (MCP): قاعدة الـ AI + اتصال MySQL لكل صلاحية
 * من غير أي تأثير على الجلسة — بيستخدمه الـ Internal API وصفحات الأدمن والاختبارات.
 */

require_once __DIR__ . '/db.php';

/* ═══════════ الإعدادات ═══════════ */

function iapi_conf(string $k, string $default = ''): string
{
    return defined($k) ? (string) constant($k) : $default;
}

/** قاعدة الـ AI (فاضي = نفس قاعدة المنصة — تطوير محلي) */
function mcp_ai_db(): string
{
    return iapi_conf('MCP_AI_DB_NAME') !== '' ? iapi_conf('MCP_AI_DB_NAME') : (defined('DB_NAME') ? DB_NAME : '');
}

/** اسم جدول في قاعدة الـ AI بالاسم الكامل */
function mcp_t(string $table): string
{
    return '`' . str_replace('`', '', mcp_ai_db()) . '`.`' . str_replace('`', '', $table) . '`';
}

/** هل المستخدمين المحدودين متظبطين؟ (لو لأ: تطوير محلي بمستخدم المنصة — صفحة الإعداد بتوضّح بالأحمر) */
function iapi_secure_mode(): bool
{
    return iapi_conf('MCP_DB_READ_USER') !== '' && iapi_conf('MCP_DB_AI_USER') !== '' && iapi_conf('MCP_DB_DRAFT_USER') !== '';
}

/** اتصال MySQL لكل صلاحية — كل واحد بمستخدم صلاحياته على قد شغله */
function iapi_pdo(string $scope): PDO
{
    static $pool = [];
    if (isset($pool[$scope])) return $pool[$scope];
    if (!iapi_secure_mode()) return $pool[$scope] = db();   // تطوير محلي
    [$user, $pass, $dbName] = match ($scope) {
        'read'     => [iapi_conf('MCP_DB_READ_USER'), iapi_conf('MCP_DB_READ_PASS'), DB_NAME],
        'ai_write' => [iapi_conf('MCP_DB_AI_USER'), iapi_conf('MCP_DB_AI_PASS'), mcp_ai_db()],
        'draft'    => [iapi_conf('MCP_DB_DRAFT_USER'), iapi_conf('MCP_DB_DRAFT_PASS'), mcp_ai_db()],
        default    => throw new \InvalidArgumentException('scope'),
    };
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4', $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    try { $pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (\Throwable $e) {}   // نفس توقيت المنصة (db.php)
    return $pool[$scope] = $pdo;
}

/**
 * إثبات حدود الصلاحيات: كل محاولة لازم MySQL يرفضها (الجمل كلها WHERE 1 = 0 — حتى لو اتنفذت مابتغيّرش حاجة)
 * @return array<int, array{label:string, scope:string, denied:bool, error:string}>
 */
function mcp_permission_probes(): array
{
    $main = '`' . str_replace('`', '', DB_NAME) . '`';
    $ai = '`' . str_replace('`', '', mcp_ai_db()) . '`';
    $probes = [
        ['read', "UPDATE $main.`credit_wallets` SET balance = balance WHERE 1 = 0", 'read: تعديل الكريدت'],
        ['read', "DELETE FROM $main.`contents` WHERE 1 = 0", 'read: حذف منشورات'],
        ['read', "INSERT INTO $ai.`change_requests` (type, target, reason, fingerprint) SELECT 'x','x','x','x' FROM DUAL WHERE 1 = 0", 'read: كتابة في قاعدة الـ AI'],
        ['ai_write', "SELECT 1 FROM $main.`users` LIMIT 1", 'ai_write: قراءة الحسابات'],
        ['ai_write', "UPDATE $main.`users` SET status = status WHERE 1 = 0", 'ai_write: تعديل الحسابات'],
        ['ai_write', "UPDATE $main.`credit_wallets` SET balance = balance WHERE 1 = 0", 'ai_write: تعديل الكريدت'],
        ['ai_write', "DELETE FROM $ai.`ai_evaluations` WHERE 1 = 0", 'ai_write: حذف (حتى في قاعدة الـ AI)'],
        ['ai_write', "UPDATE $main.`payment_orders` SET status = status WHERE 1 = 0", 'ai_write: الدفع'],
        ['ai_write', "UPDATE $main.`referrals` SET status = status WHERE 1 = 0", 'ai_write: الأفلييت'],
        ['ai_write', "DROP TABLE $ai.`mcp_audit_log`", 'ai_write: مسح سجل الـ MCP'],
        ['draft', "UPDATE $ai.`change_requests` SET status = 'approved' WHERE 1 = 0", 'draft: الموافقة على اقتراح'],
        ['draft', "DELETE FROM $ai.`change_requests` WHERE 1 = 0", 'draft: حذف'],
        ['draft', "INSERT INTO $main.`prompts` (prompt_type, title, prompt_text, version, is_active) SELECT 'x','x','x',1,0 FROM DUAL WHERE 1 = 0", 'draft: نشر برومبت على المنصة مباشرة'],
        ['draft', "UPDATE $main.`user_plans` SET status = status WHERE 1 = 0", 'draft: الاشتراكات'],
    ];
    $out = [];
    foreach ($probes as [$scope, $sql, $label]) {
        $denied = false; $err = '';
        try {
            iapi_pdo($scope)->exec($sql);
        } catch (\PDOException $e) {
            $err = $e->getMessage();
            $denied = (bool) preg_match('/denied|1142|1044|1045|command denied/i', $err);
        }
        $out[] = ['label' => $label, 'scope' => $scope, 'denied' => $denied, 'error' => mb_substr($err, 0, 160)];
    }
    return $out;
}

/** هل جداول قاعدة الـ AI موجودة؟ (اسم الجدول => true/false) */
function mcp_ai_tables_status(): array
{
    $want = ['ai_evaluations', 'issue_analysis', 'reply_drafts', 'change_requests', 'prompt_drafts', 'style_preset_drafts',
             'sandbox_runs', 'ai_reports', 'security_findings', 'integrity_issues', 'e2e_runs', 'mcp_audit_log'];
    $have = [];
    try {
        foreach (db_all('SELECT table_name t FROM information_schema.tables WHERE table_schema = ?', [mcp_ai_db()]) as $r) $have[strtolower((string) ($r['t'] ?? $r['TABLE_NAME'] ?? ''))] = true;
    } catch (\Throwable $e) {
    }
    $out = [];
    foreach ($want as $t) $out[$t] = isset($have[$t]);
    return $out;
}

/** إنشاء جداول قاعدة الـ AI بمستخدم المنصة (لازم يكون له صلاحية على قاعدة الـ AI) */
function mcp_ai_db_migrate(): array
{
    require_once __DIR__ . '/migrations.php';
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . mcp_ai_db() . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $n = 0;
    foreach (sql_split((string) file_get_contents(dirname(__DIR__) . '/sql/2026_mcp_ai_db.sql')) as $q) {
        $st = $pdo->query($q);
        if ($st) $st->closeCursor();
        $n++;
    }
    return ['statements' => $n];
}
