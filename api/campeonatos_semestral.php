<?php
require_once 'config.php';
checkAuth();
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// GET ?id=X → detalhes do campeonato semestral (parcelas, jogos, restrições)
if ($method === 'GET') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonResponse(['erro' => 'ID obrigatório'], 400);

    $stmt = $db->prepare("SELECT * FROM campeonatos WHERE id = ?");
    $stmt->execute([$id]);
    $camp = $stmt->fetch();
    if (!$camp) jsonResponse(['erro' => 'Campeonato não encontrado'], 404);

    // Atletas inscritas com tipo e parcelas pagas
    $stmt = $db->prepare("
        SELECT i.atleta_id, a.nome, i.tipo,
               (SELECT COUNT(*) FROM parcelas_semestral ps WHERE ps.inscricao_id = i.id AND ps.pago = 1) AS parcelas_pagas,
               (SELECT COUNT(*) FROM parcelas_semestral ps WHERE ps.inscricao_id = i.id) AS total_parcelas
        FROM inscricoes i
        JOIN atletas a ON a.id = i.atleta_id
        WHERE i.campeonato_id = ? AND i.status = 'ativa'
        ORDER BY a.nome
    ");
    $stmt->execute([$id]);
    $atletas = $stmt->fetchAll();
    $camp['atletas'] = $atletas;

    jsonResponse($camp);
}

// POST → criar campeonato semestral
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id    = preg_replace('/\s+/', '', $input['id'] ?? '');
    $nome  = trim($input['nome'] ?? '');
    $local = trim($input['local_jogo'] ?? '');
    $custo_total  = floatval($input['custo_total'] ?? 0);
    $custo_tecnico= floatval($input['custo_tecnico'] ?? 0);
    $n_jogos      = intval($input['n_jogos'] ?? 0);
    $n_parcelas   = intval($input['n_parcelas'] ?? 3);
    $inscricoes   = $input['inscricoes'] ?? [];

    if (!$id || !$nome) jsonResponse(['erro' => 'ID e nome obrigatórios'], 400);

    // Verifica duplicata
    $stmt = $db->prepare("SELECT id FROM campeonatos WHERE id = ?");
    $stmt->execute([$id]);
    if ($stmt->fetch()) jsonResponse(['erro' => 'ID já existe'], 409);

    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO campeonatos (id, nome, local_jogo, ativo, tipo, custo_total, custo_tecnico, n_jogos, n_parcelas)
            VALUES (?, ?, ?, 1, 'semestral', ?, ?, ?, ?)
        ")->execute([$id, $nome, $local, $custo_total, $custo_tecnico, $n_jogos, $n_parcelas]);

        // Valor por atleta mensalista (custo total dividido)
        $nMensalistas = count(array_filter($inscricoes, fn($i) => $i['tipo'] === 'mensalista'));
        $valorParcela = $nMensalistas > 0 ? round(($custo_total + $custo_tecnico) / $nMensalistas / $n_parcelas, 2) : 0;

        foreach ($inscricoes as $insc) {
            if ($insc['tipo'] === 'nao') continue;
            $db->prepare("
                INSERT INTO inscricoes (atleta_id, campeonato_id, tipo, status, data_entrada)
                VALUES (?, ?, ?, 'ativa', CURDATE())
            ")->execute([$insc['atleta_id'], $id, $insc['tipo']]);
            $inscId = $db->lastInsertId();

            if ($insc['tipo'] === 'mensalista') {
                for ($p = 1; $p <= $n_parcelas; $p++) {
                    $db->prepare("
                        INSERT INTO parcelas_semestral (inscricao_id, numero, valor, pago)
                        VALUES (?, ?, ?, 0)
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
    $input = json_decode(file_get_contents('php://input'), true);
    $parcelaId = intval($input['parcela_id'] ?? 0);
    $pago      = intval($input['pago'] ?? 0);
    if (!$parcelaId) jsonResponse(['erro' => 'parcela_id obrigatório'], 400);
    $db->prepare("UPDATE parcelas_semestral SET pago = ?, data_pagamento = ? WHERE id = ?")
       ->execute([$pago, $pago ? date('Y-m-d') : null, $parcelaId]);
    jsonResponse(['ok' => true]);
}
