<?php
// api/salvar_servico.php
header('Content-Type: application/json; charset=utf-8');
require_once '../db.php';

$dados = json_decode(file_get_contents('php://input'), true);
$barbearia_id = (int)($dados['barbearia_id'] ?? 0);
$nome = trim($dados['nome'] ?? '');
$preco = (float)($dados['preco'] ?? 0);
$duracao = (int)($dados['duracao'] ?? 0);

if ($barbearia_id <= 0 || empty($nome) || $preco <= 0 || $duracao <= 0) {
    echo json_encode(['erro' => 'Preencha todos os campos corretamente.']);
    exit;
}

try {
    $db = \Database::getConnection();
    $stmt = $db->prepare("INSERT INTO servicos (barbearia_id, nome, preco, duracao_minutos, ativo) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([$barbearia_id, $nome, $preco, $duracao]);
    echo json_encode(['sucesso' => true]);
} catch (Exception $e) {
    echo json_encode(['erro' => $e->getMessage()]);
}
