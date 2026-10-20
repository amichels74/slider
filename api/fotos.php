<?php
require_once 'config.php';
// Permite token por query string para <img src>
if (!empty($_GET['token'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $_GET['token'];
}
checkAuth();
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Garante tabela
$db->exec("CREATE TABLE IF NOT EXISTS fotos_jogos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    jogo_id VARCHAR(20) NOT NULL,
    mime VARCHAR(50) NOT NULL DEFAULT 'image/jpeg',
    dados LONGBLOB NOT NULL,
    criado_em DATETIME DEFAULT NOW(),
    INDEX idx_jogo (jogo_id)
)");

// GET ?jogo_id=X  → lista fotos (sem dados, só id/mime/criado_em)
// GET ?id=X       → retorna dados de uma foto (inline image)
if ($method === 'GET') {
    $jogoId = $_GET['jogo_id'] ?? '';
    $fotoId = $_GET['id']      ?? '';

    if ($fotoId) {
        $stmt = $db->prepare("SELECT mime, dados FROM fotos_jogos WHERE id = ?");
        $stmt->execute([$fotoId]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$f) { http_response_code(404); exit; }
        header('Content-Type: ' . $f['mime']);
        header('Cache-Control: max-age=86400');
        echo $f['dados'];
        exit;
    }

    if ($jogoId) {
        $stmt = $db->prepare("SELECT id, mime, criado_em FROM fotos_jogos WHERE jogo_id = ? ORDER BY criado_em");
        $stmt->execute([$jogoId]);
        jsonResponse($stmt->fetchAll());
    }

    jsonResponse([]);
}

// POST (multipart) → upload de foto
if ($method === 'POST') {
    $jogoId = $_POST['jogo_id'] ?? '';
    if (!$jogoId) jsonResponse(['erro' => 'jogo_id obrigatório'], 400);

    if (empty($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['erro' => 'Arquivo não recebido'], 400);
    }

    $f    = $_FILES['foto'];
    $mime = $f['type'];
    if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'])) {
        jsonResponse(['erro' => 'Tipo não permitido'], 415);
    }
    if ($f['size'] > 8 * 1024 * 1024) {
        jsonResponse(['erro' => 'Arquivo muito grande (máx 8MB)'], 413);
    }

    $dados = file_get_contents($f['tmp_name']);
    $stmt = $db->prepare("INSERT INTO fotos_jogos (jogo_id, mime, dados) VALUES (?,?,?)");
    $stmt->execute([$jogoId, $mime, $dados]);
    jsonResponse(['ok' => true, 'id' => $db->lastInsertId()]);
}

// DELETE ?id=X
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if (!$id) jsonResponse(['erro' => 'id obrigatório'], 400);
    $db->prepare("DELETE FROM fotos_jogos WHERE id = ?")->execute([$id]);
    jsonResponse(['ok' => true]);
}
