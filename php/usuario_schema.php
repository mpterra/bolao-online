<?php
declare(strict_types=1);

function usuario_supports_birth_date(PDO $pdo): bool
{
    static $cache = [];

    $cacheKey = spl_object_id($pdo) . ':usuarios.data_nascimento';
    if (array_key_exists($cacheKey, $cache)) {
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