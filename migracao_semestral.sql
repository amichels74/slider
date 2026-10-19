-- Migração: suporte a campeonato semestral
-- Execute no phpMyAdmin antes de usar a nova funcionalidade

-- 1. Adiciona campos na tabela campeonatos
ALTER TABLE campeonatos
  ADD COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'mensal' AFTER ativo,
  ADD COLUMN custo_total DECIMAL(10,2) DEFAULT NULL AFTER tipo,
  ADD COLUMN custo_tecnico DECIMAL(10,2) DEFAULT NULL AFTER custo_total,
  ADD COLUMN n_jogos INT DEFAULT NULL AFTER custo_tecnico,
  ADD COLUMN n_parcelas INT DEFAULT 3 AFTER n_jogos,
  ADD COLUMN valor_matricula DECIMAL(10,2) DEFAULT NULL AFTER n_parcelas,
  ADD COLUMN valor_avulsa DECIMAL(10,2) DEFAULT 55.00 AFTER valor_matricula;

-- 2. Tabela de parcelas para campeonato semestral
-- tipo: 'matricula' (cobrada antes de iniciar) ou 'parcela' (parcelamento do semestre)
CREATE TABLE IF NOT EXISTS parcelas_semestral (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  inscricao_id  INT NOT NULL,
  tipo          VARCHAR(20) NOT NULL DEFAULT 'parcela', -- 'matricula' | 'parcela'
  numero        INT NOT NULL,           -- matricula=0, parcelas=1,2,3
  valor         DECIMAL(10,2) NOT NULL,
  pago          TINYINT(1) DEFAULT 0,
  data_pagamento DATE DEFAULT NULL,
  FOREIGN KEY (inscricao_id) REFERENCES inscricoes(id) ON DELETE CASCADE
);
