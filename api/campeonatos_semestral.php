<?php
require_once 'config.php';
checkAuth();
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// GET ?id=X → detalhes do campeonato semestral (atletas + parcelas)
if ($method === 'GET') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonResponse(['erro' => 'ID obrigatório'], 400);

    $stmt = $db->prepare("SELECT * FROM campeonatos WHERE id = ?");
    $stmt->execute([$id]);
    $camp = $stmt->fetch();
    if (!$camp) jsonResponse(['erro' => 'Campeonato não encontrado'], 404);

    $stmt = $db->prepare("
        SELECT i.id AS inscricao_id, i.atleta_id, a.nome, i.tipo,
               ps.id AS parcela_id, ps.tipo AS ptipo, ps.numero, ps.valor, ps.pago, ps.data_pagamento
        FROM inscricoes i
        JOIN atletas a ON a.id = i.atleta_id
        LEFT JOIN parcelas_semestral ps ON ps.inscricao_id = i.id
        WHERE i.campeonato_id = ? AND i.status = 'ativa'
        ORDER BY a.nome, ps.tipo DESC, ps.numero
    ");
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll();

    // Agrupa parcelas por atleta
    $atletasMap = [];
    foreach ($rows as $r) {
        $aid = $r['atleta_id'];
        if (!isset($atletasMap[$aid])) {
            $atletasMap[$aid] = [
                'atleta_id'   => $aid,
                'inscricao_id'=> $r['inscricao_id'],
                'nome'        => $r['nome'],
                'tipo'        => $r['tipo'],
                'parcelas'    => [],
            ];
        }
        if ($r['parcela_id']) {
            $atletasMap[$aid]['parcelas'][] = [
                'id'             => $r['parcela_id'],
                'tipo'           => $r['ptipo'],
                'numero'         => $r['numero'],
                'valor'          => $r['valor'],
                'pago'           => $r['pago'],
                'data_pagamento' => $r['data_pagamento'],
            ];
        }
    }
    $camp['atletas'] = array_values($atletasMap);

    // Receita de avulsas convocadas em jogos deste campeonato
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(p.valor), 0) AS receita_avulsas, COUNT(p.id) AS n_avulsas
        FROM participacoes p
        JOIN jogos j ON j.id = p.jogo_id
        WHERE j.campeonato_id = ? AND p.tipo = 'avulsa'
    ");
    $stmt->execute([$id]);
    $fin = $stmt->fetch();
    $camp['receita_avulsas'] = floatval($fin['receita_avulsas']);
    $camp['n_participacoes_avulsa'] = intval($fin['n_avulsas']);

    jsonResponse($camp);
}

// POST → criar campeonato semestral
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id              = preg_replace('/\s+/', '', $input['id'] ?? '');
    $nome            = trim($input['nome'] ?? '');
    $local           = trim($input['local_jogo'] ?? '');
    $custo_total     = floatval($input['custo_total'] ?? 0);
    $custo_tecnico   = floatval($input['custo_tecnico'] ?? 0);
    $n_jogos         = intval($input['n_jogos'] ?? 0);
    $n_parcelas      = intval($input['n_parcelas'] ?? 3);
    $valor_matricula = floatval($input['valor_matricula'] ?? 0);
    $valor_avulsa    = floatval($input['valor_avulsa'] ?? 55);
    $inscricoes      = $input['inscricoes'] ?? [];

    if (!$id || !$nome) jsonResponse(['erro' => 'ID e nome obrigatórios'], 400);

    $stmt = $db->prepare("SELECT id FROM campeonatos WHERE id = ?");
    $stmt->execute([$id]);
    if ($stmt->fetch()) jsonResponse(['erro' => 'ID já existe'], 409);

    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO campeonatos (id, nome, local_jogo, ativo, tipo, custo_total, custo_tecnico, n_jogos, n_parcelas, valor_matricula, valor_avulsa)
            VALUES (?, ?, ?, 1, 'semestral', ?, ?, ?, ?, ?, ?)
        ")->execute([$id, $nome, $local, $custo_total, $custo_tecnico, $n_jogos, $n_parcelas, $valor_matricula ?: null, $valor_avulsa]);

        $nMensalistas = count(array_filter($inscricoes, fn($i) => $i['tipo'] === 'mensalista'));
        $valorParcela = $nMensalistas > 0
            ? round(($custo_total + $custo_tecnico) / $nMensalistas / $n_parcelas, 2)
            : 0;

        foreach ($inscricoes as $insc) {
            if (($insc['tipo'] ?? 'nao') === 'nao') continue;
            $db->prepare("
                INSERT INTO inscricoes (atleta_id, campeonato_id, tipo, status, data_entrada)
                VALUES (?, ?, ?, 'ativa', CURDATE())
            ")->execute([$insc['atleta_id'], $id, $insc['tipo']]);
            $inscId = $db->lastInsertId();

            if ($insc['tipo'] === 'mensalista') {
                // Matrícula (número 0, tipo 'matricula')
                if ($valor_matricula > 0) {
                    $db->prepare("
                        INSERT INTO parcelas_semestral (inscricao_id, tipo, numero, valor, pago)
                        VALUES (?, 'matricula', 0, ?, 0)
                    ")->execute([$inscId, $valor_matricula]);
                }
                // Parcelas do semestre
                for ($p = 1; $p <= $n_parcelas; $p++) {
                    $db->prepare("
                        INSERT INTO parcelas_semestral (inscricao_id, tipo, numero, valor, pago)
                        VALUES (?, 'parcela', ?, ?, 0)
                    ")->execute([$inscId, $p, $valorParcela]);
                }
            }
        }
        $db->commit();

        $camps = $db->query("SELECT * FROM campeonatos WHERE ativo = 1 ORDER BY nome")->fetchAll();
        jsonResponse(['ok' => true, 'camps' => $camps]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['erro' => $e->getMessage()], 500);
    }
}

// PUT → marcar parcela como paga/pendente
if ($method === 'PUT') {
    $input     = json_decode(file_get_contents('php://input'), true);
    $parcelaId = intval($input['parcela_id'] ?? 0);
    $pago      = intval($input['pago'] ?? 0);
    if (!$parcelaId) jsonResponse(['erro' => 'parcela_id obrigatório'], 400);
    $db->prepare("UPDATE parcelas_semestral SET pago = ?, data_pagamento = ? WHERE id = ?")
       ->execute([$pago, $pago ? date('Y-m-d') : null, $parcelaId]);
    jsonResponse(['ok' => true]);
}
