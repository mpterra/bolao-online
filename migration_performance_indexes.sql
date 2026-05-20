-- =========================================================
-- MIGRACAO: indices de performance para concorrencia HostGator
-- Objetivo: reduzir tempo de uso de conexoes MySQL em telas de leitura/salvamento.
-- Execute uma vez no banco de producao. Se algum indice ja existir, ignore o erro
-- desse ALTER especifico e continue com os demais.
-- =========================================================

ALTER TABLE ranking
  ADD KEY idx_ranking_edicao_posicao (edicao_id, posicao, usuario_id);

ALTER TABLE edicoes
  ADD KEY idx_edicoes_ativo_ano_id (ativo, ano, id);

ALTER TABLE jogos
  ADD KEY idx_jogos_edicao_grupo_fase_data (edicao_id, grupo_id, fase, data_hora, id),
  ADD KEY idx_jogos_edicao_fase_grupo_data (edicao_id, fase, grupo_id, data_hora, id);

ALTER TABLE palpite_grupo_classificacao
  ADD KEY idx_pgc_usuario_grupo_times (usuario_id, grupo_id, primeiro_time_id, segundo_time_id, terceiro_time_id);

ALTER TABLE palpite_top4
  ADD KEY idx_pt4_edicao_usuario_times (edicao_id, usuario_id, primeiro_time_id, segundo_time_id, terceiro_time_id, quarto_time_id);

CREATE TABLE IF NOT EXISTS bet_update_notifications (
  usuario_id INT NOT NULL PRIMARY KEY,
  last_sent_at DATETIME NULL,
  pending_updates INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bet_update_notification_items (
  usuario_id INT NOT NULL,
  item_key VARCHAR(80) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (usuario_id, item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE bet_update_notifications
  ADD KEY idx_bet_notifications_updated (updated_at);

ALTER TABLE bet_update_notification_items
  ADD KEY idx_bet_items_usuario_created (usuario_id, created_at);
