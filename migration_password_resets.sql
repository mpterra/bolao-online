-- =========================================================
-- MIGRAÇÃO: tabela password_resets
-- Execute este script no banco antes de usar o recurso
-- "Esqueci minha senha".
-- =========================================================

CREATE TABLE IF NOT EXISTS password_resets (
  id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED  NOT NULL,
  token       CHAR(64)         NOT NULL,
  expires_at  DATETIME         NOT NULL,
  created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY  uk_token   (token),
  KEY         idx_user   (user_id),
  KEY         idx_expiry (expires_at),

  CONSTRAINT fk_pr_user
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
