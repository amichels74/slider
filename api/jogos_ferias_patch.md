# Patch para api/jogos.php — Mês de Férias

Adicione estas mudanças ao arquivo existente `api/jogos.php` no servidor.

## No GET (buscar jogos):

Após o fetch de avulsas, adicione também as presenças do jogo de férias:

```php
// Após carregar avulsas, adicione:
foreach ($jogos as &$j) {
    if (!empty($j['mes_ferias'])) {
        $sp = $db->prepare("
            SELECT jp.atleta_id, a.nome
            FROM jogo_presencas jp
            JOIN atletas a ON a.id = jp.atleta_id
            WHERE jp.jogo_id = ?
        ");
        $sp->execute([$j['id']]);
        $j['presencas'] = $sp->fetchAll(PDO::FETCH_ASSOC);
    }
}
```

## No POST (salvar novo jogo):

Após o INSERT do jogo, adicione:

```php
// Pega campos novos
$mesFerias   = intval($input['mes_ferias'] ?? 0);
$presencasIds = $input['presencas_ids'] ?? [];

// No INSERT, inclua mes_ferias:
// INSERT INTO jogos (..., mes_ferias) VALUES (..., ?)
// e passe $mesFerias como parâmetro

// Após o INSERT, se for férias, salva presenças:
if ($mesFerias && !empty($presencasIds)) {
    $ins = $db->prepare("INSERT IGNORE INTO jogo_presencas (jogo_id, atleta_id) VALUES (?,?)");
    foreach ($presencasIds as $aid) {
        $ins->execute([$jogoId, $aid]);
    }
}
```

## No PUT (editar jogo):

Mesmo padrão do POST — receber `mes_ferias` e `presencas_ids`, atualizar a coluna, recriar presenças:

```php
$mesFerias    = intval($input['mes_ferias'] ?? 0);
$presencasIds = $input['presencas_ids'] ?? [];

// No UPDATE: include mes_ferias = ?

// Depois do UPDATE:
if ($mesFerias) {
    $db->prepare("DELETE FROM jogo_presencas WHERE jogo_id = ?")->execute([$id]);
    if (!empty($presencasIds)) {
        $ins = $db->prepare("INSERT IGNORE INTO jogo_presencas (jogo_id, atleta_id) VALUES (?,?)");
        foreach ($presencasIds as $aid) { $ins->execute([$id, $aid]); }
    }
}
```

## No fechamento.php — lógica de cobrança de férias:

Para jogos com `mes_ferias=1`, em vez de cobrar cota fixa de mensalistas:

```php
if ($jogo['mes_ferias']) {
    // Carrega quem foi
    $sp = $db->prepare("SELECT atleta_id FROM jogo_presencas WHERE jogo_id = ?");
    $sp->execute([$jogo['id']]);
    $presentes = $sp->fetchAll(PDO::FETCH_COLUMN);
    $nPresentes = count($presentes);
    
    if ($nPresentes > 0) {
        $custoJogo = $jogo['custo_jogo'] + $jogo['custo_tecnico'];
        $rateioPorAtleta = $custoJogo / $nPresentes;
        
        // Cada presente paga o rateio (independente de ser mensalista ou avulsa)
        foreach ($presentes as $atletaId) {
            // adiciona $rateioPorAtleta ao valor a cobrar de $atletaId
        }
    }
    // Quem não foi NÃO paga nada neste jogo de férias
}
```
