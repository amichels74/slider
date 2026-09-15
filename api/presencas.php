<?php
/**
 * api/presencas.php
 * Gerencia presenças de atletas em jogos de Mês de Férias.
 *
 * GET  ?jogo_id=X  → lista presenças do jogo
 * POST             → { jogo_id, atletas_ids: [] }  → salva presenças
 */

require_once 'config.php';
$user = checkAuth();
$db   = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $jogoId = intval($_GET['jogo_id'] ?? 0);
    if (!$jogoId) jsonResponse([]);
    $stmt = $db->prepare("SELECT atleta_id FROM jogo_presencas WHERE jogo_id = ?");
    $stmt->execute([$jogoId]);
    jsonResponse($stmt->fetchAll(PDO::FETCH_COLUMN));
}

if ($method === 'POST') {
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $jogoId  = intval($input['jogo_id'] ?? 0);
    $atletas = $input['atletas_ids'] ?? [];
    if (!$jogoId) jsonResponse(['erro' => 'jogo_id obrigatório'], 400);

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM jogo_presencas WHERE jogo_id = ?")->execute([$jogoId]);
        if (!empty($atletas)) {
            $ins = $db->prepare("INSERT INTO jogo_presencas (jogo_id, atleta_id) VALUES (?, ?)");
            foreach ($atletas as $aid) {
                $ins->execute([$jogoId, $aid]);
            }
        }
        $db->commit();
        jsonResponse(['ok' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['erro' => $e->getMessage()], 500);
    }
}

jsonResponse(['erro' => 'Método não suportado'], 405);
