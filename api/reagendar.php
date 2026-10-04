<?php
// api/reagendar.php
header('Content-Type: application/json; charset=utf-8');
require_once '../db.php';
require_once '../helpers/agenda.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido.']);
    exit;
}

$dados = json_decode(file_get_contents('php://input'), true);

$agendamento_id = isset($dados['id']) ? (int)$dados['id'] : 0;
$nova_data       = isset($dados['data']) ? trim($dados['data']) : '';
$novo_horario    = isset($dados['horario']) ? trim($dados['horario']) : '';

if ($agendamento_id <= 0 || empty($nova_data) || empty($novo_horario)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Dados incompletos para o reagendamento.']);
    exit;
}

try {
    $db = \Database::getConnection();
    $db->beginTransaction();

    // 1. Busca os detalhes originais do agendamento (Barbearia e Serviço para saber a duração)
    $stmt = $db->prepare("SELECT barbearia_id, servico_id FROM agendamentos WHERE id = ? LIMIT 1");
    $stmt->execute([$agendamento_id]);
    $agendamento = $stmt->fetch();

    if (!$agendamento) {
        throw new Exception('Agendamento não localizado.');
    }

    $barbearia_id = $agendamento['barbearia_id'];

    // 2. Busca a duração do serviço
    $stmt = $db->prepare("SELECT duracao_minutos FROM servicos WHERE id = ? LIMIT 1");
    $stmt->execute([$agendamento['servico_id']]);
    $servico = $stmt->fetch();
    $duracao_minutos = (int)$servico['duracao_minutos'];

    // 3. Double-Check: Roda o algoritmo para ver se o horário está realmente livre na nova data
    // 3. Double-Check de Segurança: O horário ainda está de fato livre?
    // CORREÇÃO: Passando explicitamente os 4 argumentos que a função exige no helpers/agenda.php
    $horarios_livres = obterHorariosLivres($db, $barbearia_id, $nova_data, $duracao_minutos);
    
    // Converte o novo horário recebido para o formato comparável 'H:i' (Ex: '14:30')
    $horario_teste = date('H:i', strtotime($novo_horario));

    if (!in_array($horario_teste, $horarios_livres)) {
        throw new Exception('Infelizmente, este horário acabou de ser ocupado. Por favor, escolha outro slot.');
    }


    // 4. Calcula o novo horário de término
    $timestamp_inicio = strtotime($novo_horario);
    $horario_fim = date('H:i:s', $timestamp_inicio + ($duracao_minutos * 60));
    $horario_inicio_formatado = date('H:i:s', $timestamp_inicio);

    // 5. Atualiza o agendamento e reseta o controle do WhatsApp para o robô reenviar o lembrete na nova hora
    $stmt = $db->prepare("
        UPDATE agendamentos 
        SET data_agendamento = ?, horario_inicio = ?, horario_fim = ?, notificacao_enviada = 0 
        WHERE id = ?
    ");
    $stmt->execute([$nova_data, $horario_inicio_formatado, $horario_fim, $agendamento_id]);

    $db->commit();
    echo json_encode(['sucesso' => true]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    http_response_code(400);
    echo json_encode(['erro' => $e->getMessage()]);
}
