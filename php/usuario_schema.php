<?php
declare(strict_types=1);

function usuario_schema_cache_key(PDO $pdo): string
{
    return spl_object_id($pdo) . ':usuarios.data_nascimento';
}

function usuario_supports_birth_date(PDO $pdo, bool $refresh = false): bool
{
    static $cache = [];

    $cacheKey = usuario_schema_cache_key($pdo);
    if (!$refresh && array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `usuarios` LIKE ?");
        $stmt->execute(['data_nascimento']);
        $cache[$cacheKey] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[usuario_schema] ' . $e->getMessage());
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function usuario_mysql_error_code(Throwable $e): int
{
    if ($e instanceof PDOException && isset($e->errorInfo[1]) && is_numeric($e->errorInfo[1])) {
        return (int)$e->errorInfo[1];
    }

    return 0;
}

function usuario_ensure_birth_date(PDO $pdo): bool
{
    if (usuario_supports_birth_date($pdo)) {
        return true;
    }

    try {
        $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `data_nascimento` DATE NULL AFTER `nome`");
    } catch (Throwable $e) {
        $errorCode = usuario_mysql_error_code($e);
        if ($errorCode !== 1060) {
            error_log('[usuario_schema] Falha ao adicionar coluna data_nascimento: ' . $e->getMessage());
        }
    }

    if (!usuario_supports_birth_date($pdo, true)) {
        return false;
    }

    try {
        $pdo->exec("ALTER TABLE `usuarios` ADD INDEX `idx_usuarios_data_nascimento` (`data_nascimento`)");
    } catch (Throwable $e) {
        $errorCode = usuario_mysql_error_code($e);
        if ($errorCode !== 1061) {
            error_log('[usuario_schema] Falha ao adicionar índice de data_nascimento: ' . $e->getMessage());
        }
    }

    return true;
}