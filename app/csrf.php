<?php
declare(strict_types=1);

/**
 * Per-session CSRF token. Pages print it in <meta name="csrf">; the API
 * accepts it in the X-CSRF-Token header or a "csrf" field (sendBeacon and
 * multipart uploads cannot always set headers).
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && $token !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** Reject the request unless it carries the session token. */
function csrf_require(array $body = []): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf'] ?? ($_POST['csrf'] ?? null));
    if (!csrf_valid($token)) {
        json_response(['error' => 'انتهت صلاحية الصفحة، اعمل تحديث (Refresh) وجرّب تاني.', 'code' => 'csrf'], 419);
    }
}
