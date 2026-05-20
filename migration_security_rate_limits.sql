-- MIGRACAO: rate limit persistente para login/recuperacao de senha

CREATE TABLE IF NOT EXISTS security_rate_limits (
  bucket_key       VARCHAR(120) NOT NULL PRIMARY KEY,
  attempts         INT UNSIGNED NOT NULL DEFAULT 0,
  first_attempt_at DATETIME NOT NULL,
  locked_until     DATETIME NULL,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
