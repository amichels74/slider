-- Migração: suporte a campeonato semestral
-- Execute no phpMyAdmin antes de usar a nova funcionalidade

-- 1. Adiciona campos na tabela campeonatos
ALTER TABLE campeonatos
  ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'mensal' AFTER ativo,
  ADD COLUMN custo_total DECIMAL(10,2) DEFAULT NULL AFTER tipo,
  ADD COLUMN custo_tecnico DECIMAL(10,2) DEFAULT NULL AFTER custo_total,
  ADD COLUMN n_jogos INT DEFAULT NULL AFTER custo_tecnico,
  ADD COLUMN n_parcelas INT DEFAULT 3 AFTER n_jogos;

-- 2. Tabela de parcelas para campeonato semestral
CREATE TABLE IF NOT EXISTS parcelas_semestral (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  inscricao_id  INT NOT NULL,
  numero        INT NOT NULL,           -- 1, 2, 3
  valor         DECIMAL(10,2) NOT NULL,
  pago          TINYINT(1) DEFAULT 0,
  data_pagamento DATE DEFAULT NULL,
  FOREIGN KEY (inscricao_id) REFERENCES inscricoes(id) ON DELETE CASCADE
);
