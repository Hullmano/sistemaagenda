<?php
// api/cancelar_agendamento_cliente.php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/../db.php';

function responderCancelamento(int $codigo, string $mensagem): void
{
    http_response_code($codigo);

    echo json_encode(
        $codigo === 200
            ? ['sucesso' => true, 'mensagem' => $mensagem]
            : ['erro' => $mensagem],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responderCancelamento(405, 'Método não permitido.');
}

// Bloqueia chamadas originadas de outros sites.
$origem = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origem !== '') {
    $hostOrigem = parse_url($origem, PHP_URL_HOST);
    $portaOrigem = parse_url($origem, PHP_URL_PORT);
    $hostRequisicao = $_SERVER['HTTP_HOST'] ?? '';

    $autoridadeOrigem = $hostOrigem;
    if ($portaOrigem !== null && $portaOrigem !== false) {
        $autoridadeOrigem .= ':' . $portaOrigem;
    }

    if (
        !$hostOrigem ||
        strcasecmp($autoridadeOrigem, $hostRequisicao) !== 0
    ) {
        responderCancelamento(403, 'Origem não autorizada.');
    }
}

if (
    ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site'
) {
    responderCancelamento(403, 'Origem não autorizada.');
}

$tipoConteudo = strtolower(
    trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])
);

if ($tipoConteudo !== 'application/json') {
    responderCancelamento(415, 'Formato de dados não permitido.');
}

$dados = json_decode(file_get_contents('php://input'), true);

if (!is_array($dados)) {
    responderCancelamento(400, 'Dados inválidos.');
}

$token = $dados['token_consulta'] ?? '';
$barbeariaId = $dados['barbearia_id'] ?? null;

if (
    !is_string($token) ||
    !preg_match('/^[a-f0-9]{64}$/', $token) ||
    !is_int($barbeariaId) ||
    $barbeariaId <= 0
) {
    responderCancelamento(400, 'Solicitação inválida.');
}

$tokenHash = hash('sha256', $token);

try {
    $db = Database::getConnection();
    $db->beginTransaction();

    // Mesmo padrão de bloqueio utilizado na criação de reservas.
    $stmt = $db->prepare("
        SELECT id, antecedencia_cancelamento_minutos
        FROM barbearias
        WHERE id = ?
          AND ativo = 1
        FOR UPDATE
    ");

    $stmt->execute([$barbeariaId]);
    $barbearia = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$barbearia) {
        throw new RuntimeException('Reserva não encontrada.', 404);
    }

    // Bloqueia o agendamento identificado pelo token.
    $stmt = $db->prepare("
        SELECT id, status, data_agendamento, horario_inicio
        FROM agendamentos
        WHERE barbearia_id = ?
          AND token_consulta_hash = ?
        FOR UPDATE
    ");

    $stmt->execute([$barbeariaId, $tokenHash]);
    $agendamento = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$agendamento) {
        throw new RuntimeException('Reserva não encontrada.', 404);
    }

    if ($agendamento['status'] !== 'agendado') {
        throw new RuntimeException(
            'Este agendamento não está disponível para cancelamento.',
            409
        );
    }

    $antecedencia = (int)$barbearia[
        'antecedencia_cancelamento_minutos'
    ];

    if (!in_array($antecedencia, [30, 60], true)) {
        $antecedencia = 30;
    }

    $fuso = new DateTimeZone('America/Sao_Paulo');

    $dataHora = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $agendamento['data_agendamento'] . ' ' .
        $agendamento['horario_inicio'],
        $fuso
    );

    if (!$dataHora) {
        throw new RuntimeException(
            'Não foi possível validar o horário da reserva.',
            409
        );
    }

    $limite = $dataHora->modify(
        '-' . $antecedencia . ' minutes'
    );

    $agora = new DateTimeImmutable('now', $fuso);

    if ($agora >= $limite) {
        throw new RuntimeException(
            'O prazo para cancelamento online já terminou. Caso precise alterar ou cancelar seu agendamento, entre em contato diretamente com a barbearia.',
            409
        );
    }

    // Cancela o agendamento e invalida o processamento
    // de confirmações ainda pendentes.
    $stmt = $db->prepare("
        UPDATE agendamentos
        SET
            status = 'cancelado',
            confirmacao_processando_em = NULL
        WHERE id = ?
          AND barbearia_id = ?
          AND status = 'agendado'
    ");

    $stmt->execute([
        (int)$agendamento['id'],
        $barbeariaId
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Não foi possível cancelar a reserva.',
            409
        );
    }

    $db->commit();

    responderCancelamento(
        200,
        'Agendamento cancelado com sucesso.'
    );

} catch (Throwable $e) {

    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    $codigo = (int)$e->getCode();

    if (in_array($codigo, [404, 409], true)) {
        responderCancelamento($codigo, $e->getMessage());
    }

    error_log(
        'Erro no cancelamento público: ' . $e->getMessage()
    );

    responderCancelamento(
        500,
        'Não foi possível processar o cancelamento.'
    );
}
