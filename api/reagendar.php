<?php
// api/reagendar.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/agenda.php';
require_once __DIR__ . '/../helpers/auth.php';

function erroReagendamento(int $codigo, string $mensagem): void
{
    http_response_code($codigo);
    echo json_encode(['erro' => $mensagem]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    erroReagendamento(405, 'Método não permitido.');
}

$usuario = usuarioLogado();

if (!$usuario) {
    erroReagendamento(401, 'Sessão expirada. Faça login novamente.');
}

if (!validarTokenCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    erroReagendamento(403, 'Token de segurança inválido.');
}

$dados = json_decode(file_get_contents('php://input'), true);

if (!is_array($dados)) {
    erroReagendamento(400, 'JSON inválido.');
}

$id = filter_var($dados['id'] ?? null, FILTER_VALIDATE_INT);
$data = $dados['data'] ?? '';
$horario = $dados['horario'] ?? '';

if (
    !$id || $id <= 0 ||
    !is_string($data) ||
    !is_string($horario)
) {
    erroReagendamento(400, 'Dados inválidos.');
}

$fuso = new DateTimeZone('America/Sao_Paulo');

if (
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ||
    !preg_match('/^\d{2}:\d{2}(?::00)?$/', $horario)
) {
    erroReagendamento(400, 'Data ou horário inválido.');
}

$inicio = DateTimeImmutable::createFromFormat(
    '!Y-m-d H:i',
    $data . ' ' . substr($horario, 0, 5),
    $fuso
);

if (
    !$inicio ||
    $inicio->format('Y-m-d H:i') !==
        $data . ' ' . substr($horario, 0, 5)
) {
    erroReagendamento(400, 'Data ou horário inválido.');
}

if ($inicio <= (new DateTimeImmutable('now', $fuso))->modify('+15 minutes')) {
    erroReagendamento(
        400,
        'Escolha um horário com pelo menos 15 minutos de antecedência.'
    );
}

try {
    $db = Database::getConnection();
    $db->beginTransaction();

    // Usa o mesmo bloqueio adotado na criação de agendamentos.
    // Serializa as operações da mesma barbearia.
    $stmt = $db->prepare("
        SELECT id
        FROM barbearias
        WHERE id = ?
        FOR UPDATE
    ");

    $stmt->execute([(int) $usuario['barbearia_id']]);

    if (!$stmt->fetch()) {
        throw new RuntimeException('Barbearia não encontrada.', 404);
    }

    // Mantém o bloqueio até COMMIT ou ROLLBACK.

    // Bloqueia o registro durante o reagendamento.
    $stmt = $db->prepare("
        SELECT
            id,
            barbearia_id,
            usuario_id,
            servico_id,
            status
        FROM agendamentos
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([$id]);
    $agendamento = $stmt->fetch();

    if (!$agendamento) {
        throw new RuntimeException('Agendamento não encontrado.', 404);
    }

    if (!podeGerenciarAgendamento($usuario, $agendamento)) {
        throw new RuntimeException(
            'Você não tem permissão para reagendar este atendimento.',
            403
        );
    }

    if ($agendamento['status'] !== 'agendado') {
        throw new RuntimeException(
            'Somente agendamentos pendentes podem ser reagendados.',
            409
        );
    }

    // Serviço precisa pertencer à mesma barbearia.
    $stmt = $db->prepare("
        SELECT duracao_minutos
        FROM servicos
        WHERE id = ?
          AND barbearia_id = ?
        LIMIT 1
    ");
    $stmt->execute([
        $agendamento['servico_id'],
        $agendamento['barbearia_id']
    ]);

    $servico = $stmt->fetch();

    if (!$servico || (int) $servico['duracao_minutos'] <= 0) {
        throw new RuntimeException('Serviço inválido.', 400);
    }

    $duracao = (int) $servico['duracao_minutos'];

    // Mantém o algoritmo de disponibilidade existente.
    $horariosLivres = obterHorariosLivres(
        $db,
        (int) $agendamento['barbearia_id'],
        $data,
        $duracao,
        (int) $agendamento['id']
    );


    $horarioNormalizado = $inicio->format('H:i');

    if (!in_array($horarioNormalizado, $horariosLivres, true)) {
        throw new RuntimeException(
            'Este horário não está disponível.',
            409
        );
    }

    $fim = $inicio->modify("+{$duracao} minutes");

    $stmt = $db->prepare("
        UPDATE agendamentos
        SET
            data_agendamento = ?,
            horario_inicio = ?,
            horario_fim = ?,
            notificacao_enviada = 0
        WHERE id = ?
          AND barbearia_id = ?
          AND status = 'agendado'
    ");

    $stmt->execute([
        $inicio->format('Y-m-d'),
        $inicio->format('H:i:s'),
        $fim->format('H:i:s'),
        $id,
        (int) $usuario['barbearia_id']
    ]);

    if ($stmt->rowCount() === 0) {

        // Confirma se o agendamento continua existindo
        // e se está no horário solicitado.
        $verificar = $db->prepare("
            SELECT id
            FROM agendamentos
            WHERE id = ?
            AND barbearia_id = ?
            AND status = 'agendado'
            AND data_agendamento = ?
            AND horario_inicio = ?
            AND horario_fim = ?
            LIMIT 1
        ");

        $verificar->execute([
            $id,
            (int) $usuario['barbearia_id'],
            $inicio->format('Y-m-d'),
            $inicio->format('H:i:s'),
            $fim->format('H:i:s')
        ]);

        if (!$verificar->fetch()) {
            throw new RuntimeException(
                'Não foi possível atualizar o agendamento.',
                409
            );
        }
    }

    $db->commit();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Agendamento atualizado com sucesso.'
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    $codigo = (int) $e->getCode();

    if (!in_array($codigo, [400, 403, 404, 409], true)) {
        error_log('Erro no reagendamento: ' . $e->getMessage());
        $codigo = 500;
        $mensagem = 'Erro interno ao reagendar.';
    } else {
        $mensagem = $e->getMessage();
    }

    erroReagendamento($codigo, $mensagem);
}
