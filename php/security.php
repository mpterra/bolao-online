<?php
declare(strict_types=1);

function app_debug_enabled(): bool
{
    return getenv('APP_DEBUG') === '1';
}

function app_configure_errors(): void
{
    error_reporting(E_ALL);
    ini_set('display_errors', app_debug_enabled() ? '1' : '0');
    ini_set('display_startup_errors', app_debug_enabled() ? '1' : '0');
    ini_set('log_errors', '1');
}

function app_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $forwardedProto === 'https';
}

function app_configure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = app_is_https();

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', $https ? '1' : '0');
        ini_set('session.cookie_samesite', 'Lax');
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
}

function app_start_session(): void
{
    app_configure_errors();
    app_configure_session();

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function app_send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    if (app_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function app_client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return $ip !== '' ? $ip : '0.0.0.0';
}

function app_csrf_token(): string
{
    app_start_session();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function app_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(app_csrf_token(), ENT_QUOTES, 'UTF-8') .
        '">';
}

function app_request_csrf_token(): string
{
    $headerToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($headerToken !== '') {
        return $headerToken;
    }

    $postToken = (string)($_POST['csrf_token'] ?? '');
    if ($postToken !== '') {
        return $postToken;
    }

    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $payload = json_decode($raw, true);
        if (is_array($payload) && isset($payload['csrf_token'])) {
            return (string)$payload['csrf_token'];
        }
    }

    return '';
}

function app_require_csrf(bool $json = false): void
{
    $sent = app_request_csrf_token();
    $expected = app_csrf_token();

    if ($sent !== '' && hash_equals($expected, $sent)) {
        return;
    }

    http_response_code(419);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Sessao expirada. Recarregue a pagina e tente novamente.']);
    } else {
        echo 'Sessao expirada. Recarregue a pagina e tente novamente.';
    }
    exit;
}

function app_rate_limit_key(string $scope, string $subject): string
{
    return $scope . ':' . hash('sha256', mb_strtolower(trim($subject), 'UTF-8'));
}

function app_sql_int_in_clause(array $values): array
{
    $ids = [];

    foreach ($values as $value) {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    $ids = array_values($ids);
    if (count($ids) === 0) {
        return ['NULL', []];
    }

    return [implode(',', array_fill(0, count($ids), '?')), $ids];
}

function app_rate_limit_ensure_table(PDO $pdo): bool
{
    static $checked = false;
    static $ok = false;

    if ($checked) {
        return $ok;
    }

    $checked = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS security_rate_limits (
                bucket_key VARCHAR(120) NOT NULL PRIMARY KEY,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                first_attempt_at DATETIME NOT NULL,
                locked_until DATETIME NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $ok = true;
    } catch (Throwable $e) {
        error_log('[security] rate limit table unavailable: ' . $e->getMessage());
        $ok = false;
    }

    return $ok;
}

function app_rate_limit_is_locked(PDO $pdo, string $bucketKey): bool
{
    if (!app_rate_limit_ensure_table($pdo)) {
        return app_session_rate_limit_is_locked($bucketKey);
    }

    try {
        $stmt = $pdo->prepare('
            SELECT locked_until
            FROM security_rate_limits
            WHERE bucket_key = ?
            LIMIT 1
        ');
        $stmt->execute([$bucketKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['locked_until'])) {
            return false;
        }

        return strtotime((string)$row['locked_until']) > time();
    } catch (Throwable $e) {
        error_log('[security] rate limit check failed: ' . $e->getMessage());
        return app_session_rate_limit_is_locked($bucketKey);
    }
}

function app_rate_limit_register_failure(PDO $pdo, string $bucketKey, int $maxAttempts, int $windowSeconds, int $cooldownSeconds): void
{
    if (!app_rate_limit_ensure_table($pdo)) {
        app_session_rate_limit_register_failure($bucketKey, $maxAttempts, $windowSeconds, $cooldownSeconds);
        return;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('
            SELECT attempts, first_attempt_at, locked_until
            FROM security_rate_limits
            WHERE bucket_key = ?
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute([$bucketKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $now = time();
        $nowSql = date('Y-m-d H:i:s', $now);
        $attempts = 1;
        $firstAttemptAt = $nowSql;
        $lockedUntil = null;

        if ($row) {
            $firstTs = strtotime((string)$row['first_attempt_at']);
            if ($firstTs !== false && ($now - $firstTs) <= $windowSeconds) {
                $attempts = ((int)$row['attempts']) + 1;
                $firstAttemptAt = (string)$row['first_attempt_at'];
            }
        }

        if ($attempts >= $maxAttempts) {
            $lockedUntil = date('Y-m-d H:i:s', $now + $cooldownSeconds);
        }

        $upsert = $pdo->prepare('
            INSERT INTO security_rate_limits (bucket_key, attempts, first_attempt_at, locked_until)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                attempts = VALUES(attempts),
                first_attempt_at = VALUES(first_attempt_at),
                locked_until = VALUES(locked_until)
        ');
        $upsert->execute([$bucketKey, $attempts, $firstAttemptAt, $lockedUntil]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[security] rate limit failure register failed: ' . $e->getMessage());
        app_session_rate_limit_register_failure($bucketKey, $maxAttempts, $windowSeconds, $cooldownSeconds);
    }
}

function app_rate_limit_clear(PDO $pdo, string $bucketKey): void
{
    unset($_SESSION['security_rate_limits'][$bucketKey]);

    if (!app_rate_limit_ensure_table($pdo)) {
        return;
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM security_rate_limits WHERE bucket_key = ?');
        $stmt->execute([$bucketKey]);
    } catch (Throwable $e) {
        error_log('[security] rate limit clear failed: ' . $e->getMessage());
    }
}

function app_session_rate_limit_is_locked(string $bucketKey): bool
{
    app_start_session();
    $bucket = $_SESSION['security_rate_limits'][$bucketKey] ?? null;

    return is_array($bucket) && !empty($bucket['locked_until']) && (int)$bucket['locked_until'] > time();
}

function app_session_rate_limit_register_failure(string $bucketKey, int $maxAttempts, int $windowSeconds, int $cooldownSeconds): void
{
    app_start_session();

    if (!isset($_SESSION['security_rate_limits']) || !is_array($_SESSION['security_rate_limits'])) {
        $_SESSION['security_rate_limits'] = [];
    }

    $now = time();
    $bucket = $_SESSION['security_rate_limits'][$bucketKey] ?? [
        'attempts' => 0,
        'first_attempt_at' => $now,
        'locked_until' => 0,
    ];

    if (($now - (int)$bucket['first_attempt_at']) > $windowSeconds) {
        $bucket = [
            'attempts' => 0,
            'first_attempt_at' => $now,
            'locked_until' => 0,
        ];
    }

    $bucket['attempts'] = (int)$bucket['attempts'] + 1;

    if ((int)$bucket['attempts'] >= $maxAttempts) {
        $bucket['locked_until'] = $now + $cooldownSeconds;
    }

    $_SESSION['security_rate_limits'][$bucketKey] = $bucket;
}

function app_require_login(string $loginUrl = '/index.php'): void
{
    app_start_session();

    if (empty($_SESSION['usuario_id'])) {
        header('Location: ' . $loginUrl);
        exit;
    }
}

function app_require_admin(): void
{
    app_require_login();

    $tipo = isset($_SESSION['tipo_usuario']) ? (string)$_SESSION['tipo_usuario'] : '';
    if (mb_strtoupper($tipo, 'UTF-8') !== 'ADMIN') {
        http_response_code(403);
        echo 'Acesso negado.';
        exit;
    }
}
