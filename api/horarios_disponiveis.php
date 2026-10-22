<?php
// api/horarios_disponiveis.php
// Força a API a ler o relógio de Brasília no AJAX
date_default_timezone_set('America/Sao_Paulo');

// 1. Configura o cabeçalho para responder no formato JSON
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/agenda.php';
require_once __DIR__ . '/../helpers/auth.php';

// 2. Captura os parâmetros enviados via GET
$barbearia_id = isset($_GET['barbearia_id']) ? (int)$_GET['barbearia_id'] : 0;
$data_desejada = isset($_GET['data']) ? $_GET['data'] : '';
$servico_id = isset($_GET['servico_id']) ? (int)$_GET['servico_id'] : 0;

$ignorar_agendamento_id = 0;

if (isset($_GET['agendamento_id'])) {

    $agendamento_id = filter_var(
        $_GET['agendamento_id'],
        FILTER_VALIDATE_INT
    );

    if (!$agendamento_id || $agendamento_id <= 0) {
        http_response_code(400);
        echo json_encode(['erro' => 'Agendamento inválido.']);
        exit;
    }

    // O visitante público não pode ignorar agendamentos.
    $usuario = usuarioLogado();

    if (!$usuario) {
        http_response_code(401);
        echo json_encode(['erro' => 'Faça login para consultar este reagendamento.']);
        exit;
    }

    $db_auth = Database::getConnection();
    $stmt_auth = $db_auth->prepare("
        SELECT id, barbearia_id, usuario_id, servico_id, status
        FROM agendamentos
        WHERE id = ?
          AND barbearia_id = ?
        LIMIT 1
    ");

    $stmt_auth->execute([$agendamento_id, (int) $usuario['barbearia_id']]);

    $agendamento_auth = $stmt_auth->fetch();

    if (
        !$agendamento_auth ||
        !podeGerenciarAgendamento($usuario, $agendamento_auth)
    ) {
        http_response_code(403);
        echo json_encode(['erro' => 'Sem permissão para consultar este agendamento.']);
        exit;
    }

    if ($agendamento_auth['status'] !== 'agendado') {
        http_response_code(409);
        echo json_encode(['erro' => 'Este agendamento não pode ser reagendado.']);
        exit;
    }

    // Impede utilizar outro serviço ou outra barbearia
    // para consultar a disponibilidade do agendamento.
    if (
        (int) $agendamento_auth['servico_id'] !== $servico_id ||
        (int) $agendamento_auth['barbearia_id'] !== $barbearia_id
    ) {
        http_response_code(403);
        echo json_encode(['erro' => 'Dados do agendamento não correspondem.']);
        exit;
    }
    $ignorar_agendamento_id = (int) $agendamento_auth['id'];
}

// Validação básica dos dados recebidos
if ($barbearia_id <= 0 || empty($data_desejada) || $servico_id <= 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetros inválidos ou ausentes.']);
    exit;
}

$fuso = new DateTimeZone('America/Sao_Paulo');
$data_valida = DateTimeImmutable::createFromFormat('!Y-m-d', $data_desejada, $fuso);
if (!$data_valida || $data_valida->format('Y-m-d') !== $data_desejada || $data_valida < new DateTimeImmutable('today', $fuso)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Escolha uma data de hoje em diante.']);
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
    $horarios_livres = obterHorariosLivres($db, $barbearia_id, $data_desejada, $duracao_minutos, $ignorar_agendamento_id);

    // 5. Devolve a resposta de sucesso em JSON
    echo json_encode([
        'sucesso' => true,
        'horarios' => $horarios_livres
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno no servidor: ' . $e->getMessage()]);
}
