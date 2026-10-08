<?php
declare(strict_types=1);

/**
 * Shared PDO connection. Prepared statements only, exceptions on error,
 * and the session time zone pinned to UTC: every DATETIME column is UTC
 * and converted to the site time zone only for display / day boundaries.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int) ($c['port'] ?? 3306), $c['name'], $c['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

/** Run a statement and return it. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function q_row(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_value(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Current UTC time formatted for DATETIME columns. */
function utc_now(string $modify = ''): string
{
    $d = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($modify !== '') {
        $d = $d->modify($modify);
    }
    return $d->format('Y-m-d H:i:s');
}
