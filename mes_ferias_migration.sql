-- ============================================================
-- Migração: Mês de Férias
-- Execute este SQL no banco de dados do sistemavolei
-- Apenas UMA linha necessária — reutiliza tabela participacoes
-- com tipo='ferias' para registrar presenças
-- ============================================================

ALTER TABLE jogos
  ADD COLUMN mes_ferias TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = jogo de mes de ferias (rateio por presenca)';

-- Verificar:
-- DESCRIBE jogos;
