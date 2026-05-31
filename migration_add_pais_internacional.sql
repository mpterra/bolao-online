-- =========================================================
-- MIGRATION: Adiciona suporte a país e internacionalização
-- Tabela afetada: usuarios
--
-- SEGURANÇA DOS DADOS EXISTENTES:
--   - ADD COLUMN pais DEFAULT 'Brasil'  → todos os registros
--     existentes recebem 'Brasil' automaticamente. Nenhum dado perdido.
--   - MODIFY estado VARCHAR(100)        → era CHAR(2); 'SP', 'RJ' etc.
--     são preservados sem alteração. Só amplia o limite.
--   - MODIFY telefone VARCHAR(30)       → era VARCHAR(20). Todos os
--     valores atuais cabem com folga.
--   - ADD INDEX pais                    → não afeta dados, só performance.
--
-- USUÁRIOS INTERNACIONAIS JÁ CADASTRADOS:
--   Receberão pais='Brasil' (valor padrão). Se precisar corrigir,
--   faça um UPDATE manual depois de identificar os registros.
--
-- COMO RODAR:
--   mysql -u SEU_USUARIO -p bolao_copa < migration_add_pais_internacional.sql
--
-- REVERSÃO (se precisar desfazer):
--   ALTER TABLE `usuarios` DROP COLUMN `pais`;
--   ALTER TABLE `usuarios` MODIFY COLUMN `estado` CHAR(2) NOT NULL;
--   ALTER TABLE `usuarios` MODIFY COLUMN `telefone` VARCHAR(20) NOT NULL;
--   DROP INDEX `idx_usuarios_pais` ON `usuarios`;
-- =========================================================

USE bolao_copa;

-- 1) Adiciona coluna país com default 'Brasil' para não quebrar
--    registros existentes (todos viram 'Brasil' automaticamente)
ALTER TABLE `usuarios`
  ADD COLUMN `pais` VARCHAR(80) NOT NULL DEFAULT 'Brasil'
  AFTER `nome`;

-- 2) Amplia estado de CHAR(2) para VARCHAR(100)
--    para comportar nomes como "California", "Bavaria", etc.
--    Dados existentes (ex: 'SP', 'RJ') são preservados.
ALTER TABLE `usuarios`
  MODIFY COLUMN `estado` VARCHAR(100) NOT NULL;

-- 3) Amplia telefone para comportar formato internacional
--    ex: +1 (212) 555-1234  →  precisa de mais que 20 chars
--    Dados existentes preservados.
ALTER TABLE `usuarios`
  MODIFY COLUMN `telefone` VARCHAR(30) NOT NULL;

-- 4) Índice em pais para queries futuras de filtro
ALTER TABLE `usuarios`
  ADD INDEX `idx_usuarios_pais` (`pais`);
