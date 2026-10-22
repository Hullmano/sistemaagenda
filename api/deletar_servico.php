
<?php
// api/deletar_servico.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

function responderErroExclusao(int $codigo, string $mensagem): void
{
    http_response_code($codigo);
    echo json_encode(['erro' => $mensagem]);
    exit;
}

// Somente POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderErroExclusao(405, 'Método não permitido.');
}

// Exige usuário autenticado
$usuario = usuarioLogado();

if (!$usuario) {
    responderErroExclusao(401, 'Sessão expirada. Faça login novamente.');
}

// Apenas o proprietário pode excluir serviços
if ($usuario['nivel'] !== 'dono') {
    responderErroExclusao(
        403,
        'Somente o proprietário pode remover serviços.'
    );
}

// Proteção CSRF
if (!validarTokenCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    responderErroExclusao(403, 'Token de segurança inválido.');
}

// Recebe JSON
$dados = json_decode(file_get_contents('php://input'), true);

if (!is_array($dados)) {
    responderErroExclusao(400, 'Dados inválidos.');
}

$id = filter_var($dados['id'] ?? null, FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id <= 0) {
    responderErroExclusao(400, 'ID do serviço inválido.');
}

try {
    $db = Database::getConnection();

    // Desativa apenas serviços da barbearia autenticada.
    // Não exclui fisicamente para preservar o histórico.
    $stmt = $db->prepare("
        UPDATE servicos
        SET ativo = 0
        WHERE id = ?
          AND barbearia_id = ?
          AND ativo = 1
    ");

    $stmt->execute([
        $id,
        (int) $usuario['barbearia_id']
    ]);

    if ($stmt->rowCount() !== 1) {
        responderErroExclusao(
            404,
            'Serviço não encontrado ou já desativado.'
        );
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Serviço removido da listagem com sucesso.'
    ]);

} catch (Throwable $e) {
    error_log('Erro ao desativar serviço: ' . $e->getMessage());

    responderErroExclusao(
        500,
        'Erro interno ao remover serviço.'
    );
}
