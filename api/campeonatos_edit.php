<?php
require_once 'config.php';
checkAuth();
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// PUT → atualizar campeonato (nome, local, tipo, campos semestral, ativo)
if ($method !== 'PUT') jsonResponse(['erro' => 'Método inválido'], 405);

$input = json_decode(file_get_contents('php://input'), true);
$id    = $input['id'] ?? '';
if (!$id) jsonResponse(['erro' => 'ID obrigatório'], 400);

$stmt = $db->prepare("SELECT * FROM campeonatos WHERE id = ?");
$stmt->execute([$id]);
$camp = $stmt->fetch();
if (!$camp) jsonResponse(['erro' => 'Campeonato não encontrado'], 404);

$nome    = trim($input['nome']      ?? $camp['nome']);
$local   = trim($input['local_jogo'] ?? $camp['local_jogo']);
$ativo   = isset($input['ativo'])   ? intval($input['ativo']) : intval($camp['ativo']);
$tipo    = $input['tipo']           ?? $camp['tipo'] ?? 'mensal';

try {
    if ($tipo === 'semestral') {
        $custo_total     = floatval($input['custo_total']     ?? $camp['custo_total']     ?? 0);
        $custo_tecnico   = floatval($input['custo_tecnico']   ?? $camp['custo_tecnico']   ?? 0);
        $n_jogos         = intval($input['n_jogos']           ?? $camp['n_jogos']         ?? 0);
        $n_parcelas      = intval($input['n_parcelas']        ?? $camp['n_parcelas']      ?? 3);
        $valor_matricula = floatval($input['valor_matricula'] ?? $camp['valor_matricula'] ?? 0);
        $valor_avulsa    = floatval($input['valor_avulsa']    ?? $camp['valor_avulsa']    ?? 55);

        $db->prepare("
            UPDATE campeonatos
            SET nome = ?, local_jogo = ?, ativo = ?, tipo = ?,
                custo_total = ?, custo_tecnico = ?, n_jogos = ?, n_parcelas = ?,
                valor_matricula = ?, valor_avulsa = ?
            WHERE id = ?
        ")->execute([$nome, $local, $ativo, $tipo,
                     $custo_total, $custo_tecnico, $n_jogos, $n_parcelas,
                     $valor_matricula ?: null, $valor_avulsa, $id]);
    } else {
        $db->prepare("
            UPDATE campeonatos
            SET nome = ?, local_jogo = ?, ativo = ?, tipo = 'mensal',
                custo_total = NULL, custo_tecnico = NULL, n_jogos = NULL,
                n_parcelas = NULL, valor_matricula = NULL, valor_avulsa = NULL
            WHERE id = ?
        ")->execute([$nome, $local, $ativo, $id]);
    }

    $camps = $db->query("SELECT id, nome, local_jogo, ativo, tipo, custo_total, custo_tecnico, n_jogos, n_parcelas, valor_matricula, valor_avulsa FROM campeonatos ORDER BY nome")->fetchAll();
    jsonResponse(['ok' => true, 'camps' => $camps]);
} catch (Exception $e) {
    jsonResponse(['erro' => $e->getMessage()], 500);
}
