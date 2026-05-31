<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/cadastro_mailer.php';

$limit = isset($argv[1]) ? (int)$argv[1] : 50;
$limit = max(1, min(200, $limit));

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Conexao PDO indisponivel.\n");
    exit(1);
}

if (!cadastro_mail_queue_ensure_schema($pdo)) {CREATE TABLE IF NOT EXISTS `cadastro_email_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `kind` VARCHAR(40) NOT NULL,
  `dedupe_key` VARCHAR(190) NOT NULL,
  `recipient_email` VARCHAR(190) NOT NULL,
  `recipient_name` VARCHAR(120) NOT NULL DEFAULT '',
  `subject` VARCHAR(255) NOT NULL,
  `fallback_subject` VARCHAR(255) NULL,
  `html_body` MEDIUMTEXT NOT NULL,
  `text_body` MEDIUMTEXT NOT NULL,
  `status` ENUM('PENDING','SENDING','SENT','FAILED') NOT NULL DEFAULT 'PENDING',
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_at` DATETIME NULL,
  `lock_token` VARCHAR(64) NULL,
  `sent_at` DATETIME NULL,
  `last_error` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),CREATE TABLE IF NOT EXISTS `cadastro_email_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `kind` VARCHAR(40) NOT NULL,
  `dedupe_key` VARCHAR(190) NOT NULL,
  `recipient_email` VARCHAR(190) NOT NULL,
  `recipient_name` VARCHAR(120) NOT NULL DEFAULT '',
  `subject` VARCHAR(255) NOT NULL,
  `fallback_subject` VARCHAR(255) NULL,
  `html_body` MEDIUMTEXT NOT NULL,CREATE TABLE IF NOT EXISTS `cadastro_email_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `kind` VARCHAR(40) NOT NULL,
  `dedupe_key` VARCHAR(190) NOT NULL,
  `recipient_email` VARCHAR(190) NOT NULL,
  `recipient_name` VARCHAR(120) NOT NULL DEFAULT '',
  `subject` VARCHAR(255) NOT NULL,
  `fallback_subject` VARCHAR(255) NULL,
  `html_body` MEDIUMTEXT NOT NULL,
  `text_body` MEDIUMTEXT NOT NULL,
  `status` ENUM('PENDING','SENDING','SENT','FAILED') NOT NULL DEFAULT 'PENDING',
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_at` DATETIME NULL,
  `lock_token` VARCHAR(64) NULL,
  `sent_at` DATETIME NULL,
  `last_error` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cadastro_email_queue_dedupe` (`dedupe_key`),
  KEY `idx_cadastro_email_queue_status_available` (`status`, `available_at`),
  KEY `idx_cadastro_email_queue_user_status` (`user_id`, `status`),
  KEY `idx_cadastro_email_queue_lock` (`lock_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  `text_body` MEDIUMTEXT NOT NULL,
  `status` ENUM('PENDING','SENDING','SENT','FAILED') NOT NULL DEFAULT 'PENDING',
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_at` DATETIME NULL,
  `lock_token` VARCHAR(64) NULL,
  `sent_at` DATETIME NULL,
  `last_error` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cadastro_email_queue_dedupe` (`dedupe_key`),
  KEY `idx_cadastro_email_queue_status_available` (`status`, `available_at`),
  KEY `idx_cadastro_email_queue_user_status` (`user_id`, `status`),
  KEY `idx_cadastro_email_queue_lock` (`lock_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  UNIQUE KEY `uk_cadastro_email_queue_dedupe` (`dedupe_key`),
  KEY `idx_cadastro_email_queue_status_available` (`status`, `available_at`),
  KEY `idx_cadastro_email_queue_user_status` (`user_id`, `status`),
  KEY `idx_cadastro_email_queue_lock` (`lock_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    fwrite(STDERR, "Nao foi possivel preparar a tabela cadastro_email_queue.\n");
    exit(1);
}

$stats = cadastro_mail_queue_process($pdo, $limit);

printf(
    "Fila de cadastro processada: reservados=%d enviados=%d falhas=%d\n",
    (int)$stats['claimed'],
    (int)$stats['sent'],
    (int)$stats['failed']
);
