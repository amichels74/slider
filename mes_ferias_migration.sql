-- ============================================================
-- Migração: Mês de Férias
-- Execute este SQL no banco de dados do sistemavolei
-- ============================================================

-- 1. Adiciona coluna mes_ferias na tabela jogos
ALTER TABLE jogos
  ADD COLUMN mes_ferias TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = jogo de mês de férias (rateio por presença)';

-- 2. Cria tabela de presenças para jogos de férias
CREATE TABLE IF NOT EXISTS jogo_presencas (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    jogo_id     INT NOT NULL,
    atleta_id   VARCHAR(50) NOT NULL,
    criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_jogo_atleta (jogo_id, atleta_id),
    KEY idx_jogo (jogo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. (Opcional) Se quiser verificar as colunas adicionadas:
-- DESCRIBE jogos;
-- DESCRIBE jogo_presencas;
