<?php
/** اتصال قاعدة بيانات الموقع + دوال مساعدة */
require_once __DIR__ . '/config.php';

function sdb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . SITE_DB_HOST . ';dbname=' . SITE_DB_NAME . ';charset=utf8mb4',
                SITE_DB_USER,
                SITE_DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            exit('تعذّر الاتصال بقاعدة بيانات الموقع. راجع site/config.php');
        }
    }
    return $pdo;
}

function s_all(string $sql, array $p = []): array
{
    try { $st = sdb()->prepare($sql); $st->execute($p); return $st->fetchAll(); }
    catch (\Throwable $e) { return []; }
}

function s_one(string $sql, array $p = []): ?array
{
    try { $st = sdb()->prepare($sql); $st->execute($p); $r = $st->fetch(); return $r ?: null; }
    catch (\Throwable $e) { return null; }
}

function s_run(string $sql, array $p = []): bool
{
    try { return sdb()->prepare($sql)->execute($p); }
    catch (\Throwable $e) { return false; }
}

function s_insert(string $sql, array $p = []): int
{
    try { sdb()->prepare($sql)->execute($p); return (int) sdb()->lastInsertId(); }
    catch (\Throwable $e) { return 0; }
}
