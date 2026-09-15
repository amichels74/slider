<?php
require_once 'config.php';
checkAuth();
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// GET ?camp=X&mes=2026-06  → jogos do mês
// POST                      → registrar jogo + avulsas/presenças
// PUT                       → editar jogo
// DELETE ?id=X              → remover jogo

if ($method === 'GET') {
    $campId = $_GET['camp'] ?? null;
    $mes    = $_GET['mes']  ?? date('Y-m');
    $params = [$mes];
    $where  = "WHERE j.mes_referencia = ?";
    if ($campId) { $where .= " AND j.campeonato_id = ?"; $params[] = $campId; }

    $stmt = $db->prepare("
        SELECT j.id, j.campeonato_id, c.nome AS camp_nome, j.data_jogo,
               j.mes_referencia, j.custo_jogo, j.custo_tecnico, j.valor_avulsa, j.observacoes,
               (j.custo_jogo + j.custo_tecnico) AS custo_total,
               COALESCE(j.mes_ferias, 0) AS mes_ferias
        FROM jogos j
        JOIN campeonatos c ON c.id = j.campeonato_id
        $where
        ORDER BY j.data_jogo DESC
    ");
    $stmt->execute($params);
    $jogos = $stmt->fetchAll();

    foreach ($jogos as &$j) {
        if (!empty($j['mes_ferias'])) {
            // Mês de férias: carrega presenças (tipo='ferias')
            $s = $db->prepare("
                SELECT p.id, p.atleta_id, a.nome
                FROM participacoes p JOIN atletas a ON a.id = p.atleta_id
                WHERE p.jogo_id = ? AND p.tipo = 'ferias' ORDER BY a.nome
            ");
            $s->execute([$j['id']]);
            $j['presencas'] = $s->fetchAll();
            $j['avulsas']   = [];
        } else {
            $s = $db->prepare("
                SELECT p.id, p.atleta_id, a.nome, p.valor
                FROM participacoes p JOIN atletas a ON a.id = p.atleta_id
                WHERE p.jogo_id = ? AND p.tipo = 'avulsa' ORDER BY a.nome
            ");
            $s->execute([$j['id']]);
            $j['avulsas']   = $s->fetchAll();
            $j['presencas'] = [];
        }
    }
    jsonResponse($jogos);
}

if ($method === 'POST') {
    $input       = json_decode(file_get_contents('php://input'), true);
    $campId      = $input['campeonato_id'] ?? '';
    $data        = $input['data_jogo']     ?? '';
    $custoJogo   = floatval($input['custo_jogo']    ?? 0);
    $custoTec    = floatval($input['custo_tecnico'] ?? 0);
    $valAvulsa   = floatval($input['valor_avulsa']  ?? 55);
    $avulsasIds  = $input['avulsas_ids']   ?? [];
    $obs         = $input['observacoes']   ?? '';
    $mesFerias   = intval($input['mes_ferias'] ?? 0);
    $presencasIds= $input['presencas_ids'] ?? [];

    if (!$campId || !$data) jsonResponse(['erro' => 'campeonato_id e data_jogo obrigatórios'], 400);

    $mes    = substr($data, 0, 7);
    $jogoId = 'j'.substr(md5(uniqid()), 0, 8);

    // Tenta com mes_ferias; se a coluna não existir ainda, insere sem ela
    try {
        $db->prepare("INSERT INTO jogos (id, campeonato_id, data_jogo, mes_referencia, custo_jogo, custo_tecnico, valor_avulsa, observacoes, mes_ferias)
                      VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$jogoId, $campId, $data, $mes, $custoJogo, $custoTec, $valAvulsa, $obs, $mesFerias]);
    } catch (Exception $e) {
        $db->prepare("INSERT INTO jogos (id, campeonato_id, data_jogo, mes_referencia, custo_jogo, custo_tecnico, valor_avulsa, observacoes)
                      VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$jogoId, $campId, $data, $mes, $custoJogo, $custoTec, $valAvulsa, $obs]);
    }

    if ($mesFerias && !empty($presencasIds)) {
        // Férias: registra presenças com tipo='ferias' e valor=0
        $stmtP = $db->prepare("INSERT IGNORE INTO participacoes (jogo_id, atleta_id, tipo, valor) VALUES (?,?,'ferias',0)");
        foreach ($presencasIds as $aid) {
            $stmtP->execute([$jogoId, $aid]);
        }
    } else {
        // Normal: registra avulsas
        $stmtP = $db->prepare("INSERT IGNORE INTO participacoes (jogo_id, atleta_id, tipo, valor) VALUES (?,?,'avulsa',?)");
        foreach ($avulsasIds as $aid) {
            $stmtP->execute([$jogoId, $aid, $valAvulsa]);
        }
    }

    jsonResponse(['ok' => true, 'jogo_id' => $jogoId]);
}

if ($method === 'PUT') {
    $input       = json_decode(file_get_contents('php://input'), true);
    $jogoId      = $input['id']            ?? '';
    $data        = $input['data_jogo']     ?? '';
    $custoJogo   = floatval($input['custo_jogo']    ?? 0);
    $custoTec    = floatval($input['custo_tecnico'] ?? 0);
    $valAvulsa   = floatval($input['valor_avulsa']  ?? 55);
    $avulsasIds  = $input['avulsas_ids']   ?? [];
    $obs         = $input['observacoes']   ?? '';
    $mesFerias   = intval($input['mes_ferias'] ?? 0);
    $presencasIds= $input['presencas_ids'] ?? [];

    if (!$jogoId) jsonResponse(['erro' => 'id obrigatório'], 400);

    $mes = substr($data, 0, 7);

    try {
        $db->prepare("UPDATE jogos SET data_jogo=?, mes_referencia=?, custo_jogo=?, custo_tecnico=?, valor_avulsa=?, observacoes=?, mes_ferias=? WHERE id=?")
           ->execute([$data, $mes, $custoJogo, $custoTec, $valAvulsa, $obs, $mesFerias, $jogoId]);
    } catch (Exception $e) {
        $db->prepare("UPDATE jogos SET data_jogo=?, mes_referencia=?, custo_jogo=?, custo_tecnico=?, valor_avulsa=?, observacoes=? WHERE id=?")
           ->execute([$data, $mes, $custoJogo, $custoTec, $valAvulsa, $obs, $jogoId]);
    }

    // Apaga participações antigas e insere novas
    $db->prepare("DELETE FROM participacoes WHERE jogo_id=?")->execute([$jogoId]);

    if ($mesFerias && !empty($presencasIds)) {
        $stmtP = $db->prepare("INSERT IGNORE INTO participacoes (jogo_id, atleta_id, tipo, valor) VALUES (?,?,'ferias',0)");
        foreach ($presencasIds as $aid) {
            $stmtP->execute([$jogoId, $aid]);
        }
    } else {
        $stmtP = $db->prepare("INSERT IGNORE INTO participacoes (jogo_id, atleta_id, tipo, valor) VALUES (?,?,'avulsa',?)");
        foreach ($avulsasIds as $aid) {
            $stmtP->execute([$jogoId, $aid, $valAvulsa]);
        }
    }

    jsonResponse(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonResponse(['erro' => 'id obrigatório'], 400);
    $db->prepare("DELETE FROM participacoes WHERE jogo_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM jogos WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}
