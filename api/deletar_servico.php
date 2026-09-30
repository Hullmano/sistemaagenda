<?php
// api/deletar_servico.php
header('Content-Type: application/json; charset=utf-8');
require_once '../db.php';

$dados = json_decode(file_get_contents('php://input'), true);
$id = (int)($dados['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['erro' => 'ID inválido.']);
    exit;
}

try {
    $db = \Database::getConnection();
    // Em vez de dar DELETE físico e quebrar históricos, desativamos o serviço da listagem do cliente
    $stmt = $db->prepare("UPDATE servicos SET ativo = 0 WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['sucesso' => true]);
} catch (Exception $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}
