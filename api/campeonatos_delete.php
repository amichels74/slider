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

// Remove inscrições e depois o campeonato
$db->prepare("DELETE FROM inscricoes WHERE campeonato_id = ?")->execute([$id]);
$db->prepare("DELETE FROM campeonatos WHERE id = ?")->execute([$id]);
jsonResponse(['ok' => true]);
