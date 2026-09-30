<?php
// api/atualizar_status.php
header('Content-Type: application/json; charset=utf-8');

require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido.']);
    exit;
}

// Captura os dados via JSON [1]
$dados = json_decode(file_get_contents('php://input'), true);

$agendamento_id = isset($dados['id']) ? (int)$dados['id'] : 0;
$novo_status    = isset($dados['status']) ? trim($dados['status']) : '';

// Permite apenas os status válidos conforme mapeamos no banco
$status_permitidos = ['concluido', 'nao_compareceu'];

if ($agendamento_id <= 0 || !in_array($novo_status, $status_permitidos)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Dados inválidos ou status não permitido.']);
    exit;
}

try {
    $db = \Database::getConnection();

    // Executa a atualização no banco de dados [1]
    $stmt = $db->prepare("UPDATE agendamentos SET status = ? WHERE id = ?");
    $stmt->execute([$novo_status, $agendamento_id]);

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Status atualizado com sucesso!'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno ao atualizar: ' . $e->getMessage()]);
}
