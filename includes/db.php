<?php
/**
 * Spread AI — Database Connection (PDO)
 */

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci"
            ]);
            // توقيت MySQL = توقيت PHP (Africa/Cairo في config.php).
            // من غيرها: لو السيرفر على UTC، NOW() بيتأخر 3 ساعات عن مواعيد PHP —
            // والكرون (scheduled_at <= NOW()) كان بينشر المجدول متأخر 3 ساعات،
            // و«منذ...» بيطلع غلط. لو التوقيتين متطابقين أصلًا السطر ده مابيغيّرش حاجة.
            try {
                $pdo->exec("SET time_zone = '" . date('P') . "'");
            } catch (\Throwable $e) {
                error_log('[db] time_zone: ' . $e->getMessage());
            }
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                die('DB connection failed: ' . $e->getMessage());
            }
            die('Database connection error. Please contact administrator.');
        }
    }
    return $pdo;
}

/**
 * Quick query helpers
 */
function db_one(string $sql, array $params = [])
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    // PDO بيرجّع false لو مفيش صف — بنرجّع null عشان الدوال اللي نوعها ?array
    // ماتقعش (PHP 8 كان بيوقف الصفحة بـ 500 فاضية: get_user_content · user_brand · …)
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_run(string $sql, array $params = []): bool
{
    $stmt = db()->prepare($sql);
    return $stmt->execute($params);
}

function db_insert(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) db()->lastInsertId();
}

function db_count(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * Insert a row using table name + associative array (safe column building).
 * Usage: db_insert_row('contents', ['user_id' => 1, ...])
 */
function db_insert_row(string $table, array $data): int
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        throw new InvalidArgumentException('Invalid table name');
    }
    if (empty($data)) {
        throw new InvalidArgumentException('No data to insert');
    }
    $cols = array_keys($data);
    foreach ($cols as $c) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $c)) {
            throw new InvalidArgumentException('Invalid column name: ' . $c);
        }
    }
    $colSql = '`' . implode('`, `', $cols) . '`';
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $stmt = db()->prepare("INSERT INTO `$table` ($colSql) VALUES ($placeholders)");
    $stmt->execute(array_values($data));
    return (int) db()->lastInsertId();
}
