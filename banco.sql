-- Active: 1767988838949@@127.0.0.1@3306@bolao_copa
-- =========================================================
-- BANCO: Bolão Copa (multi-edição), com zebra por jogo
-- =========================================================

CREATE DATABASE IF NOT EXISTS bolao_copa
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_0900_ai_ci;

USE bolao_copa;

-- =========================================================
-- 1) USUÁRIOS
-- =========================================================
CREATE TABLE usuarios (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  nome            VARCHAR(120) NOT NULL,
  email           VARCHAR(190) NOT NULL,
  telefone        VARCHAR(20)  NOT NULL,
  cidade          VARCHAR(120) NOT NULL,
  estado          CHAR(2)      NOT NULL,

  senha_hash      VARCHAR(255) NOT NULL,

  tipo_usuario    ENUM('ADMIN','APOSTADOR') NOT NULL DEFAULT 'APOSTADOR',

  ativo           TINYINT(1) NOT NULL DEFAULT 1,

  criado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  UNIQUE KEY uk_usuarios_email (email),

  KEY idx_usuarios_tipo   (tipo_usuario),
  KEY idx_usuarios_estado (estado),
  KEY idx_usuarios_cidade (cidade),
  KEY idx_usuarios_ativo  (ativo)
) ENGINE=InnoDB;


-- =========================================================
-- 2) EDIÇÕES
-- =========================================================
CREATE TABLE IF NOT EXISTS edicoes (
  id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome            VARCHAR(80) NOT NULL,              -- "Copa do Mundo 2026"
  ano             SMALLINT UNSIGNED NOT NULL,        -- 2026
  ativo           TINYINT(1) NOT NULL DEFAULT 1,
  criado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_edicoes_ano (ano),
  KEY idx_edicoes_ativo (ativo)
) ENGINE=InnoDB;

-- =========================================================
-- 3) TIMES
-- =========================================================
CREATE TABLE IF NOT EXISTS times (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome          VARCHAR(80) NOT NULL,
  sigla         CHAR(3) NOT NULL,
  confederacao  VARCHAR(20) NULL,
  criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_times_sigla (sigla),
  UNIQUE KEY uk_times_nome  (nome)
) ENGINE=InnoDB;

-- =========================================================
-- 4) GRUPOS (por edição)
-- =========================================================
CREATE TABLE IF NOT EXISTS grupos (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  edicao_id     SMALLINT UNSIGNED NOT NULL,
  codigo        CHAR(1) NOT NULL,                    -- 'A','B',...
  nome          VARCHAR(40) NULL,
  criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_grupos_edicao FOREIGN KEY (edicao_id) REFERENCES edicoes(id) ON DELETE CASCADE,
  UNIQUE KEY uk_grupo_por_edicao (edicao_id, codigo),
  KEY idx_grupos_edicao (edicao_id)
) ENGINE=InnoDB;

-- =========================================================
-- 5) GRUPO_TIME (pivô) - times em grupos
-- =========================================================
CREATE TABLE IF NOT EXISTS grupo_time (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  edicao_id     SMALLINT UNSIGNED NOT NULL,
  grupo_id      SMALLINT UNSIGNED NOT NULL,
  time_id       SMALLINT UNSIGNED NOT NULL,
  criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_grupo_time_edicao FOREIGN KEY (edicao_id) REFERENCES edicoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_grupo_time_grupo  FOREIGN KEY (grupo_id)  REFERENCES grupos(id)  ON DELETE CASCADE,
  CONSTRAINT fk_grupo_time_time   FOREIGN KEY (time_id)   REFERENCES times(id)   ON DELETE CASCADE,
  UNIQUE KEY uk_time_unico_no_grupo (grupo_id, time_id),
  KEY idx_grupo_time_edicao (edicao_id),
  KEY idx_grupo_time_grupo  (grupo_id),
  KEY idx_grupo_time_time   (time_id)
) ENGINE=InnoDB;

-- =========================================================
-- 6) JOGOS (com zebra por jogo, definida pelo organizador)
--
-- zebra_time_id:
--   NULL => este jogo NÃO tem zebra
--   time_id (casa ou fora) => time marcado como zebra
-- =========================================================
CREATE TABLE IF NOT EXISTS jogos (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  edicao_id       SMALLINT UNSIGNED NOT NULL,

  fase            VARCHAR(30) NOT NULL,
  grupo_id        SMALLINT UNSIGNED NULL,
  rodada          SMALLINT UNSIGNED NULL,

  data_hora       DATETIME NOT NULL,

  time_casa_id    SMALLINT UNSIGNED NOT NULL,
  time_fora_id    SMALLINT UNSIGNED NOT NULL,

  zebra_time_id   SMALLINT UNSIGNED NULL,

  status          ENUM('AGENDADO','EM_ANDAMENTO','ENCERRADO') NOT NULL DEFAULT 'AGENDADO',

  -- placar real
  gols_casa       TINYINT UNSIGNED NULL,
  gols_fora       TINYINT UNSIGNED NULL,

  criado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  CONSTRAINT fk_jogos_edicao    FOREIGN KEY (edicao_id)     REFERENCES edicoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_jogos_grupo     FOREIGN KEY (grupo_id)      REFERENCES grupos(id)  ON DELETE SET NULL,
  CONSTRAINT fk_jogos_casa      FOREIGN KEY (time_casa_id)  REFERENCES times(id),
  CONSTRAINT fk_jogos_fora      FOREIGN KEY (time_fora_id)  REFERENCES times(id),
  CONSTRAINT fk_jogos_zebra     FOREIGN KEY (zebra_time_id) REFERENCES times(id),

  CONSTRAINT chk_jogos_times_diferentes CHECK (time_casa_id <> time_fora_id),

  -- zebra, se definida, precisa ser um dos times do jogo
  CONSTRAINT chk_jogo_zebra_valida CHECK (
    zebra_time_id IS NULL OR zebra_time_id IN (time_casa_id, time_fora_id)
  ),

  KEY idx_jogos_edicao_data (edicao_id, data_hora),
  KEY idx_jogos_status (status),
  KEY idx_jogos_fase (fase),
  KEY idx_jogos_grupo (grupo_id),
  KEY idx_jogos_zebra (zebra_time_id),

  UNIQUE KEY uk_jogo_unico (edicao_id, data_hora, time_casa_id, time_fora_id)
) ENGINE=InnoDB;

-- =========================================================
-- 7) PALPITES
-- (Usuário só palpita placar. Zebra é informação do jogo.)
-- =========================================================
CREATE TABLE IF NOT EXISTS palpites (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario_id      BIGINT UNSIGNED NOT NULL,
  jogo_id         BIGINT UNSIGNED NOT NULL,

  gols_casa       TINYINT UNSIGNED NOT NULL,
  gols_fora       TINYINT UNSIGNED NOT NULL,

  criado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  CONSTRAINT fk_palpites_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_palpites_jogo    FOREIGN KEY (jogo_id)    REFERENCES jogos(id)    ON DELETE CASCADE,

  UNIQUE KEY uk_palpite_unico (usuario_id, jogo_id),
  KEY idx_palpites_usuario (usuario_id),
  KEY idx_palpites_jogo (jogo_id)
) ENGINE=InnoDB;

-- =========================================================
-- (OPCIONAL) Cache de pontuação por palpite
-- Se quiser ranking rápido sem recalcular toda hora.
-- =========================================================
CREATE TABLE IF NOT EXISTS pontos_palpite (
  palpite_id      BIGINT UNSIGNED NOT NULL,
  pontos          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  calculado_em    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (palpite_id),
  CONSTRAINT fk_pontos_palpite FOREIGN KEY (palpite_id) REFERENCES palpites(id) ON DELETE CASCADE,
  KEY idx_pontos (pontos)
) ENGINE=InnoDB;

USE bolao_copa;

ALTER TABLE jogos
ADD COLUMN codigo_fifa VARCHAR(20) NULL AFTER edicao_id;
ALTER TABLE jogos
ADD UNIQUE KEY uk_jogos_codigo_fifa (edicao_id, codigo_fifa),
ADD KEY idx_jogos_codigo_fifa (codigo_fifa);


-- =========================================================
-- NOVO: PALPITE DE CLASSIFICAÇÃO DO GRUPO (1º/2º/3º)
-- 1 registro por (usuario_id, grupo_id)
-- =========================================================
CREATE TABLE IF NOT EXISTS palpite_grupo_classificacao (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  edicao_id          SMALLINT UNSIGNED NOT NULL,
  grupo_id           SMALLINT UNSIGNED NOT NULL,
  usuario_id         BIGINT  UNSIGNED NOT NULL,

  primeiro_time_id   SMALLINT UNSIGNED NOT NULL,
  segundo_time_id    SMALLINT UNSIGNED NOT NULL,
  terceiro_time_id   SMALLINT UNSIGNED NOT NULL,

  criado_em          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  CONSTRAINT fk_pgc_edicao   FOREIGN KEY (edicao_id)  REFERENCES edicoes(id)  ON DELETE CASCADE,
  CONSTRAINT fk_pgc_grupo    FOREIGN KEY (grupo_id)   REFERENCES grupos(id)   ON DELETE CASCADE,
  CONSTRAINT fk_pgc_usuario  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,

  CONSTRAINT fk_pgc_time1    FOREIGN KEY (primeiro_time_id) REFERENCES times(id),
  CONSTRAINT fk_pgc_time2    FOREIGN KEY (segundo_time_id)  REFERENCES times(id),
  CONSTRAINT fk_pgc_time3    FOREIGN KEY (terceiro_time_id) REFERENCES times(id),

  -- garante 1 palpite por usuário por grupo
  UNIQUE KEY uk_pgc_unico (usuario_id, grupo_id),

  KEY idx_pgc_edicao (edicao_id),
  KEY idx_pgc_grupo (grupo_id),
  KEY idx_pgc_usuario (usuario_id),

  -- garante que 1º/2º/3º não sejam iguais
  CONSTRAINT chk_pgc_times_distintos CHECK (
    primeiro_time_id <> segundo_time_id
    AND primeiro_time_id <> terceiro_time_id
    AND segundo_time_id <> terceiro_time_id
  )
) ENGINE=InnoDB;

-- Índice de apoio (para validar na aplicação se o time pertence ao grupo/edição)
CREATE INDEX idx_grupo_time_edicao_grupo_time
ON grupo_time (edicao_id, grupo_id, time_id);

-- =========================================================
-- NOVO: PALPITE DE CAMPEÃO (1 por usuário por edição)
-- =========================================================
CREATE TABLE IF NOT EXISTS palpite_campeao (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  edicao_id      SMALLINT UNSIGNED NOT NULL,
  usuario_id     BIGINT  UNSIGNED NOT NULL,
  time_id        SMALLINT UNSIGNED NOT NULL,

  criado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  CONSTRAINT fk_pc_edicao   FOREIGN KEY (edicao_id)  REFERENCES edicoes(id)  ON DELETE CASCADE,
  CONSTRAINT fk_pc_usuario  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_time     FOREIGN KEY (time_id)    REFERENCES times(id),

  UNIQUE KEY uk_pc_unico (usuario_id, edicao_id),

  KEY idx_pc_edicao (edicao_id),
  KEY idx_pc_usuario (usuario_id),
  KEY idx_pc_time (time_id)
) ENGINE=InnoDB;

use bolao_copa;

create table if not exists ranking (
  id                      bigint unsigned not null auto_increment,

  edicao_id               smallint unsigned not null,
  usuario_id              bigint unsigned not null,

  posicao                 int not null default 0,
  pontos                  int not null default 0,
  placares_acertados      int not null default 0,
  resultados_acertados    int not null default 0,
  pontos_primeira_fase    int not null default 0,
  pontos_mata_mata        int not null default 0,

  acertou_campeao         tinyint(1) not null default 0,
  acertou_vice            tinyint(1) not null default 0,
  acertou_terceiro        tinyint(1) not null default 0,
  acertou_quarto          tinyint(1) not null default 0,

  selecoes_classificadas  int not null default 0,
  pontos_com_brasil       int not null default 0,
  pontos_com_campeao      int not null default 0,

  campeao_no_inicio       varchar(80) not null default '',
  placar_na_final         varchar(20) not null default '',

  primary key (id),

  constraint fk_ranking_edicao
    foreign key (edicao_id) references edicoes(id) on delete cascade,

  constraint fk_ranking_usuario
    foreign key (usuario_id) references usuarios(id) on delete cascade,

  unique key uk_ranking_unico (edicao_id, usuario_id)

) engine=innodb;


CREATE TABLE `palpite_top4` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `edicao_id` SMALLINT UNSIGNED NOT NULL,
  `usuario_id` BIGINT UNSIGNED NOT NULL,

  `primeiro_time_id` SMALLINT UNSIGNED NOT NULL,
  `segundo_time_id`  SMALLINT UNSIGNED NOT NULL,
  `terceiro_time_id` SMALLINT UNSIGNED NOT NULL,
  `quarto_time_id`   SMALLINT UNSIGNED NOT NULL,

  `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` TIMESTAMP NOT NULL 
      DEFAULT CURRENT_TIMESTAMP 
      ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uk_pt4_unico` (`usuario_id`, `edicao_id`),

  KEY `idx_pt4_edicao` (`edicao_id`),
  KEY `idx_pt4_usuario` (`usuario_id`),

  KEY `idx_pt4_time1` (`primeiro_time_id`),
  KEY `idx_pt4_time2` (`segundo_time_id`),
  KEY `idx_pt4_time3` (`terceiro_time_id`),
  KEY `idx_pt4_time4` (`quarto_time_id`),

  CONSTRAINT `fk_pt4_edicao`
    FOREIGN KEY (`edicao_id`)
    REFERENCES `edicoes` (`id`)
    ON DELETE CASCADE,

  CONSTRAINT `fk_pt4_usuario`
    FOREIGN KEY (`usuario_id`)
    REFERENCES `usuarios` (`id`)
    ON DELETE CASCADE,

  CONSTRAINT `fk_pt4_time1`
    FOREIGN KEY (`primeiro_time_id`)
    REFERENCES `times` (`id`),

  CONSTRAINT `fk_pt4_time2`
    FOREIGN KEY (`segundo_time_id`)
    REFERENCES `times` (`id`),

  CONSTRAINT `fk_pt4_time3`
    FOREIGN KEY (`terceiro_time_id`)
    REFERENCES `times` (`id`),

  CONSTRAINT `fk_pt4_time4`
    FOREIGN KEY (`quarto_time_id`)
    REFERENCES `times` (`id`),

  CONSTRAINT `chk_pt4_times_distintos`
    CHECK (
      `primeiro_time_id` <> `segundo_time_id` AND
      `primeiro_time_id` <> `terceiro_time_id` AND
      `primeiro_time_id` <> `quarto_time_id` AND
      `segundo_time_id`  <> `terceiro_time_id` AND
      `segundo_time_id`  <> `quarto_time_id` AND
      `terceiro_time_id` <> `quarto_time_id`
    )

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;

  ALTER TABLE palpites
  ADD COLUMN passa_time_id SMALLINT UNSIGNED NULL AFTER gols_fora,
  ADD KEY idx_palpites_passa (passa_time_id),
  ADD CONSTRAINT fk_palpites_passa
    FOREIGN KEY (passa_time_id) REFERENCES times(id);



-- =========================================================
-- SEED: cria a edição 2026
-- =========================================================
INSERT INTO edicoes (nome, ano, ativo)
VALUES ('Copa do Mundo 2026', 2026, 1)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), ativo = VALUES(ativo);


INSERT INTO usuarios
(id, nome, email, telefone, cidade, estado, senha_hash, tipo_usuario, ativo, criado_em, atualizado_em)
VALUES
(2,
 'Maurício Terra',
 'mauriciopterra@gmail.com',
 '53981203614',
 'Rio Grande',
 'RS',
 '$2y$10$ApdmEihf2DVmgSFuvyjHquxII.lvm6thGNrMKmNbb/Z76CHEw74Ki',
 'ADMIN',
 1,
 '2026-02-15 10:56:27',
 '2026-02-15 10:56:27');


