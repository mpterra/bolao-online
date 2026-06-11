<?php
declare(strict_types=1);

const REGISTRATION_LOCK_TIMEZONE = 'America/Sao_Paulo';
const REGISTRATION_LOCK_DEADLINE = '2026-06-11 12:00:00';
const REGISTRATION_CLOSED_PATH = '/cadastro_encerrado.php';

function registration_lock_deadline(): DateTimeImmutable
{
    return new DateTimeImmutable(
        REGISTRATION_LOCK_DEADLINE,
        new DateTimeZone(REGISTRATION_LOCK_TIMEZONE)
    );
}

function registration_is_closed(?DateTimeImmutable $now = null): bool
{
    $timezone = new DateTimeZone(REGISTRATION_LOCK_TIMEZONE);
    $now = $now instanceof DateTimeImmutable ? $now->setTimezone($timezone) : new DateTimeImmutable('now', $timezone);

    return $now >= registration_lock_deadline();
}

function redirect_if_registration_closed(): void
{
    if (!registration_is_closed()) {
        return;
    }

    header('Location: ' . REGISTRATION_CLOSED_PATH, true, 302);
    exit;
}
