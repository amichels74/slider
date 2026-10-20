<?php
require_once 'config.php';
checkAuth();
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Garante coluna saldo_caixa
    $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
    $chk = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='campeonatos' AND COLUMN_NAME='saldo_caixa'");
    $chk->execute([$dbName]);
    $hasSaldo = $chk->fetchColumn() > 0;

    $sql = "
        SELECT c.id, c.nome, c.local_jogo, c.ativo,
               COALESCE(c.tipo,'mensal') AS tipo,
               c.custo_total, c.custo_tecnico, c.n_jogos, c.n_parcelas,
               c.valor_matricula, c.valor_avulsa,
               " . ($hasSaldo ? "COALESCE(c.saldo_caixa,0) AS saldo_caixa," : "0 AS saldo_caixa,") . "
               COUNT(i.id) AS total_atletas,
               SUM(i.tipo='mensalista') AS total_mensalistas,
               SUM(i.tipo='avulsa')     AS total_avulsas,
               SUM(i.tipo='experimental') AS total_experimentais
        FROM campeonatos c
        LEFT JOIN inscricoes i ON i.campeonato_id = c.id AND i.status = 'ativa'
        WHERE c.ativo = 1
        GROUP BY c.id
        ORDER BY c.nome
    ";
    $camps = $db->query($sql)->fetchAll();
    jsonResponse($camps);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id    = preg_replace('/\s+/', '', $input['id']   ?? '');
    $nome  = trim($input['nome']              ?? '');
    $local = trim($input['local_jogo']        ?? '');
    $inscricoes = $input['inscricoes']        ?? [];

    if (!$id || !$nome) jsonResponse(['erro' => 'ID e nome obrigatórios'], 400);

    $stmt = $db->prepare("SELECT id FROM campeonatos WHERE id = ?");
    $stmt->execute([$id]);
    if ($stmt->fetch()) jsonResponse(['erro' => 'ID já existe'], 409);

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO campeonatos (id, nome, local_jogo, ativo, tipo) VALUES (?, ?, ?, 1, 'mensal')")
           ->execute([$id, $nome, $local]);

        foreach ($inscricoes as $insc) {
            if (($insc['tipo'] ?? 'nao') === 'nao') continue;
            $db->prepare("INSERT INTO inscricoes (atleta_id, campeonato_id, tipo, status, data_entrada) VALUES (?, ?, ?, 'ativa', CURDATE())")
               ->execute([$insc['atleta_id'], $id, $insc['tipo']]);
        }
        $db->commit();

        $camps = $db->query("SELECT id, nome, local_jogo, ativo, COALESCE(tipo,'mensal') AS tipo FROM campeonatos WHERE ativo=1 ORDER BY nome")->fetchAll();
        jsonResponse(['ok' => true, 'camps' => $camps]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['erro' => $e->getMessage()], 500);
    }
}
