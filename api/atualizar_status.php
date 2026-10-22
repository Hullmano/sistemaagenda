<?php
// api/atualizar_status.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

function responderErro(int $codigo, string $mensagem): void
{
    http_response_code($codigo);
    echo json_encode(['erro' => $mensagem]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderErro(405, 'Método não permitido.');
}

// Verifica a sessão administrativa
$usuario = usuarioLogado();

if (!$usuario) {
    responderErro(401, 'Sessão expirada. Faça login novamente.');
}

// Lê os dados JSON
$dados = json_decode(file_get_contents('php://input'), true);

if (!is_array($dados)) {
    responderErro(400, 'JSON inválido.');
}

// Proteção contra CSRF
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

if (!validarTokenCsrf($token)) {
    responderErro(403, 'Token de segurança inválido.');
}

$agendamento_id = (int) ($dados['id'] ?? 0);
$novo_status = $dados['status'] ?? '';

$status_permitidos = [
    'concluido',
    'nao_compareceu',
    'cancelado'
];

if (
    $agendamento_id <= 0 ||
    !is_string($novo_status) ||
    !in_array($novo_status, $status_permitidos, true)
) {
    responderErro(400, 'Dados inválidos.');
}

try {
    $db = Database::getConnection();

    // Busca o agendamento e seu responsável
    $stmt = $db->prepare("
        SELECT id, barbearia_id, usuario_id, status
        FROM agendamentos
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$agendamento_id]);
    $agendamento = $stmt->fetch();

    if (!$agendamento) {
        responderErro(404, 'Agendamento não encontrado.');
    }

    // Valida a barbearia e as permissões do usuário
    if (!podeGerenciarAgendamento($usuario, $agendamento)) {
        responderErro(403, 'Você não tem permissão para alterar este agendamento.');
    }

    if ($agendamento['status'] !== 'agendado') {
        responderErro(409, 'Este agendamento já foi finalizado ou cancelado.');
    }

    // Atualiza somente se o status ainda for agendado
    $stmt = $db->prepare("
        UPDATE agendamentos
        SET status = ?
        WHERE id = ?
          AND barbearia_id = ?
          AND status = 'agendado'
    ");

    $stmt->execute([
        $novo_status,
        $agendamento_id,
        (int) $usuario['barbearia_id']
    ]);

    if ($stmt->rowCount() !== 1) {
        responderErro(409, 'O agendamento foi alterado por outra operação.');
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Status atualizado com sucesso!'
    ]);

} catch (Throwable $e) {
    error_log('Erro ao atualizar agendamento: ' . $e->getMessage());
    responderErro(500, 'Erro interno ao atualizar o agendamento.');
}
