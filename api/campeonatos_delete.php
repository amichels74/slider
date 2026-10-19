<?php
require_once 'config.php';
checkAuth();

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'DELETE') jsonResponse(['erro' => 'Método inválido'], 405);

$input = json_decode(file_get_contents('php://input'), true);
$id    = $input['id'] ?? '';
if (!$id) jsonResponse(['erro' => 'ID obrigatório'], 400);

// Verifica se tem jogos registrados — retorna a lista para o frontend
$stmt = $db->prepare("SELECT id, data_jogo, mes_referencia, custo_jogo FROM jogos WHERE campeonato_id = ? ORDER BY data_jogo DESC");
$stmt->execute([$id]);
$jogos = $stmt->fetchAll();
if (count($jogos) > 0) {
    jsonResponse(['bloqueado' => true, 'jogos' => $jogos], 409);
}

$db->beginTransaction();
try {
    // tabelas opcionais — só deleta se existirem E tiverem coluna campeonato_id
    $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
    $tabelas = ['pagamentos','fechamentos','historico_status','historico_tipo','cobrancas','ajustes'];
    foreach ($tabelas as $t) {
        $chk = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME='campeonato_id'");
        $chk->execute([$dbName, $t]);
        if ($chk->fetchColumn() > 0) {
            $db->prepare("DELETE FROM $t WHERE campeonato_id = ?")->execute([$id]);
        }
    }
    // participações → jogos
    $db->prepare("DELETE p FROM participacoes p JOIN jogos j ON j.id = p.jogo_id WHERE j.campeonato_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM jogos WHERE campeonato_id = ?")->execute([$id]);
    // parcelas semestral → inscrições
    $db->prepare("DELETE ps FROM parcelas_semestral ps JOIN inscricoes i ON i.id = ps.inscricao_id WHERE i.campeonato_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM inscricoes WHERE campeonato_id = ?")->execute([$id]);
    // campeonato
    $db->prepare("DELETE FROM campeonatos WHERE id = ?")->execute([$id]);
    $db->commit();
    jsonResponse(['ok' => true]);
} catch (Exception $e) {
    $db->rollBack();
    jsonResponse(['erro' => $e->getMessage()], 500);
}
