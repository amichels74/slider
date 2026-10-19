<?php
require_once 'config.php';
checkAuth();
if (!checkNivel(['admin'])) jsonResponse(['erro' => 'Sem permissão'], 403);

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'DELETE') jsonResponse(['erro' => 'Método inválido'], 405);

$input = json_decode(file_get_contents('php://input'), true);
$id    = $input['id'] ?? '';
if (!$id) jsonResponse(['erro' => 'ID obrigatório'], 400);

// Verifica se tem jogos registrados
$stmt = $db->prepare("SELECT COUNT(*) FROM jogos WHERE campeonato_id = ?");
$stmt->execute([$id]);
$nJogos = $stmt->fetchColumn();
if ($nJogos > 0) {
    jsonResponse(['erro' => "Este campeonato tem $nJogos jogo(s) registrado(s). Exclua os jogos antes de excluir o campeonato."], 409);
}

// Remove inscrições e depois o campeonato
$db->prepare("DELETE FROM inscricoes WHERE campeonato_id = ?")->execute([$id]);
$db->prepare("DELETE FROM campeonatos WHERE id = ?")->execute([$id]);
jsonResponse(['ok' => true]);
