<?php
// api/criar_agendamento.php
header('Content-Type: application/json; charset=utf-8');

require_once '../db.php';
require_once '../helpers/agenda.php';

// Permite apenas requisições POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido.']);
    exit;
}

// Captura os dados enviados via JSON pelo JavaScript
$dados = json_decode(file_get_contents('php://input'), true);

$barbearia_id     = isset($dados['barbearia_id']) ? (int)$dados['barbearia_id'] : 0;
$servico_id       = isset($dados['servico_id']) ? (int)$dados['servico_id'] : 0;
$data_agendamento = isset($dados['data']) ? trim($dados['data']) : '';
$horario_inicio   = isset($dados['horario']) ? trim($dados['horario']) : '';
$nome_cliente     = isset($dados['nome']) ? trim($dados['nome']) : '';
$whatsapp_cliente = isset($dados['whatsapp']) ? trim($dados['whatsapp']) : '';

// 1. Validação básica de campos vazios
if ($barbearia_id <= 0 || $servico_id <= 0 || empty($data_agendamento) || empty($horario_inicio) || empty($nome_cliente) || empty($whatsapp_cliente)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Por favor, preencha todos os campos e escolha um horário.']);
    exit;
}

// Limpa o WhatsApp para salvar apenas números (Padrão Evolution API)
$whatsapp_limpo = preg_replace('/[^0-9]/', '', $whatsapp_cliente);
if (strlen($whatsapp_limpo) < 10) { // Validação mínima de 10 dígitos (DDD + número)
    http_response_code(400);
    echo json_encode(['erro' => 'Por favor, insira um número de WhatsApp válido com DDD.']);
    exit;
}

try {
    $db = \Database::getConnection();
    $db->beginTransaction(); // Inicia transação para garantir consistência econômica/dados

    // 2. Busca dados do serviço (Preço e Duração)
    $stmt = $db->prepare("SELECT nome, duracao_minutos, preco FROM servicos WHERE id = ? AND barbearia_id = ? AND ativo = 1 LIMIT 1");
    $stmt->execute([$servico_id, $barbearia_id]);
    $servico = $stmt->fetch();

    if (!$servico) {
        throw new Exception('O serviço selecionado não está mais disponível.');
    }

    // 3. Calcula o horário de término do agendamento (Essencial para o nosso algoritmo de colisão)
    $timestamp_inicio = strtotime($horario_inicio);
    $duracao_segundos = (int)$servico['duracao_minutos'] * 60;
    $horario_fim = date('H:i:s', $timestamp_inicio + $duracao_segundos);
    $horario_inicio_formatado = date('H:i:s', $timestamp_inicio);

    // 4. Validação de segurança de última hora (Double-Check): O horário ainda está livre?
    $horarios_livres = obterHorariosLivres($db, $barbearia_id, $data_agendamento, (int)$servico['duracao_minutos']);
    if (!in_array(date('H:i', $timestamp_inicio), $horarios_livres)) {
        throw new Exception('Infelizmente, este horário foi reservado por outro cliente há poucos segundos. Por favor, escolha outro.');
    }

    // 5. Salva ou localiza o Cliente na tabela (Evita duplicar cadastros do mesmo número)
    $stmt = $db->prepare("SELECT id FROM clientes WHERE barbearia_id = ? AND whatsapp = ? LIMIT 1");
    $stmt->execute([$barbearia_id, $whatsapp_limpo]);
    $cliente = $stmt->fetch();

    if ($cliente) {
        $cliente_id = $cliente['id'];
    } else {
        $stmt = $db->prepare("INSERT INTO clientes (barbearia_id, nome, whatsapp) VALUES (?, ?, ?)");
        $stmt->execute([$barbearia_id, $nome_cliente, $whatsapp_limpo]);
        $cliente_id = $db->lastInsertId();
    }

    // 6. Busca um barbeiro/usuário disponível (Definimos temporariamente o primeiro barbeiro do ID da barbearia)
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE barbearia_id = ? LIMIT 1");
    $stmt->execute([$barbearia_id]);
    $barbeiro = $stmt->fetch();
    $usuario_id = $barbeiro ? $barbeiro['id'] : 1; // Fallback caso não ache

    // 7. Faz o INSERT final do Agendamento
    $stmt = $db->prepare("
        INSERT INTO agendamentos 
        (barbearia_id, cliente_id, servico_id, usuario_id, data_agendamento, horario_inicio, horario_fim, status, notificacao_enviada) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'agendado', 0)
    ");
    $stmt->execute([
        $barbearia_id,
        $cliente_id,
        $servico_id,
        $usuario_id,
        $data_agendamento,
        $horario_inicio_formatado,
        $horario_fim
    ]);

    $db->commit(); // Confirma as gravações no banco

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Agendamento realizado com sucesso!'
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack(); // Cancela tudo em caso de falha no meio do processo
    }
    http_response_code(400);
    echo json_encode(['erro' => $e->getMessage()]);
}
