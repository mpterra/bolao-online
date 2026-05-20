<?php
declare(strict_types=1);

function app_cache_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bolao-online-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function app_cache_file(string $key): string
{
    return app_cache_dir() . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
}

function app_cache_get(string $key, int $ttlSeconds)
{
    if ($ttlSeconds <= 0) {
        return null;
    }

    $file = app_cache_file($key);
    if (!is_file($file)) {
        return null;
    }

    $mtime = @filemtime($file);
    if ($mtime === false || (time() - $mtime) > $ttlSeconds) {
        @unlink($file);
        return null;
    }

    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload) || !array_key_exists('value', $payload)) {
        return null;
    }

    return $payload['value'];
}

function app_cache_set(string $key, $value): void
{
    $dir = app_cache_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        return;
    }

    $file = app_cache_file($key);
    $tmp = $file . '.' . getmypid() . '.tmp';
    $payload = json_encode(['value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return;
    }

    if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
        @rename($tmp, $file);
    } else {
        @unlink($tmp);
    }
}

function app_cache_remember(string $key, int $ttlSeconds, callable $resolver)
{
    $cached = app_cache_get($key, $ttlSeconds);
    if ($cached !== null) {
        return $cached;
    }

    $value = $resolver();
    app_cache_set($key, $value);
    return $value;
}
