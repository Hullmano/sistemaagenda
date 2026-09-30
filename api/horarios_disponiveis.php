<?php
// api/horarios_disponiveis.php

// 1. Configura o cabeçalho para responder no formato JSON
header('Content-Type: application/json; charset=utf-8');

require_once '../db.php';
require_once '../helpers/agenda.php';

// 2. Captura os parâmetros enviados via GET
$barbearia_id = isset($_GET['barbearia_id']) ? (int)$_GET['barbearia_id'] : 0;
$data_desejada = isset($_GET['data']) ? $_GET['data'] : '';
$servico_id = isset($_GET['servico_id']) ? (int)$_GET['servico_id'] : 0;

// Validação básica dos dados recebidos
if ($barbearia_id <= 0 || empty($data_desejada) || $servico_id <= 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetros inválidos ou ausentes.']);
    exit;
}

try {
    $db = \Database::getConnection();

    // 3. Busca a duração do serviço selecionado no banco de dados
    $stmt = $db->prepare("SELECT duracao_minutos FROM servicos WHERE id = ? AND barbearia_id = ? AND ativo = 1 LIMIT 1");
    $stmt->execute([$servico_id, $barbearia_id]);
    $servico = $stmt->fetch();

    if (!$servico) {
        http_response_code(404);
        echo json_encode(['erro' => 'Serviço não encontrado.']);
        exit;
    }

    // 4. Executa o nosso algoritmo que já corrigimos contra colisões
    $duracao_minutos = (int)$servico['duracao_minutos'];
    $horarios_livres = obterHorariosLivres($db, $barbearia_id, $data_desejada, $duracao_minutos);

    // 5. Devolve a resposta de sucesso em JSON
    echo json_encode([
        'sucesso' => true,
        'horarios' => $horarios_livres
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno no servidor: ' . $e->getMessage()]);
}
