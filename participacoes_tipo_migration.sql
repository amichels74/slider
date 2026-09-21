-- Verifica tipo atual da coluna:
-- DESCRIBE participacoes;

-- Se a coluna 'tipo' for ENUM e não incluir 'ferias',
-- execute este ALTER para aceitar o novo valor:
ALTER TABLE participacoes
  MODIFY COLUMN tipo VARCHAR(20) NOT NULL DEFAULT 'avulsa';

-- Verificar resultado:
-- DESCRIBE participacoes;
