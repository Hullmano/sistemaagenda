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

    // Inicia transação para manter agendamento e comanda consistentes
    $db->beginTransaction();

    // Bloqueia a barbearia para coordenar operações simultâneas
    $stmt = $db->prepare("
        SELECT id
        FROM barbearias
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([(int)$usuario['barbearia_id']]);

    if (!$stmt->fetch()) {
        throw new RuntimeException('Barbearia não encontrada.');
    }

    // Busca e bloqueia o agendamento durante a transação
    $stmt = $db->prepare("
        SELECT
            id,
            barbearia_id,
            usuario_id,
            servico_id,
            status
        FROM agendamentos
        WHERE id = ?
        AND barbearia_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([
        $agendamento_id,
        (int)$usuario['barbearia_id']
    ]);

    $agendamento = $stmt->fetch();

    if (!$agendamento) {
        throw new RuntimeException('Agendamento não encontrado.', 404);
    }

    // Valida a barbearia e as permissões do usuário
    if (!podeGerenciarAgendamento($usuario, $agendamento)) {
        throw new RuntimeException(
            'Você não tem permissão para alterar este agendamento.', 403
);
    }

    if ($agendamento['status'] !== 'agendado') {
        throw new RuntimeException(
            'Este agendamento já foi finalizado ou cancelado.', 409
        );
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
    throw new RuntimeException(
        'O agendamento foi alterado por outra operação.',
        409
    );
}

// Cancelamento ou falta: cancelar a comanda, desde que não haja pagamento.
if (in_array($novo_status, ['cancelado', 'nao_compareceu'], true)) {

    $stmt = $db->prepare("
        SELECT id, status
        FROM comandas
        WHERE agendamento_id = ?
          AND barbearia_id = ?
        FOR UPDATE
    ");

    $stmt->execute([
        $agendamento_id,
        (int)$usuario['barbearia_id']
    ]);

    $comanda = $stmt->fetch();

    if ($comanda) {
        if ($comanda['status'] !== 'aberta') {
            throw new RuntimeException(
                'A comanda precisa ser conferida antes de cancelar o atendimento.',
                409
            );
        }

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM pagamentos
            WHERE comanda_id = ?
        ");
        $stmt->execute([(int)$comanda['id']]);

        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException(
                'Existem pagamentos registrados. Solicite a conferência financeira antes de cancelar.',
                409
            );
        }

        $stmt = $db->prepare("
            UPDATE comandas
            SET status = 'cancelada'
            WHERE id = ?
              AND barbearia_id = ?
              AND status = 'aberta'
        ");

        $stmt->execute([
            (int)$comanda['id'],
            (int)$usuario['barbearia_id']
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Não foi possível cancelar a comanda.',
                409
            );
        }
    }
}

// Ao concluir o atendimento, cria a comanda
// e registra o serviço realizado.
if ($novo_status === 'concluido') {

    // Não criar outra comanda para o mesmo agendamento.
    $stmt = $db->prepare("
        SELECT id
        FROM comandas
        WHERE agendamento_id = ?
          AND barbearia_id = ?
        FOR UPDATE
    ");

    $stmt->execute([
        $agendamento_id,
        (int)$usuario['barbearia_id']
    ]);

    if ($stmt->fetch()) {
        throw new RuntimeException(
            'Este agendamento já possui uma comanda. Verifique antes de concluir.',
            409
        );
    }

    // Busca o nome e o preço do serviço.
    $stmt = $db->prepare("
        SELECT id, nome, preco
        FROM servicos
        WHERE id = ?
          AND barbearia_id = ?
        FOR UPDATE
    ");

    $stmt->execute([
        (int)$agendamento['servico_id'],
        (int)$usuario['barbearia_id']
    ]);

    $servico = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$servico) {
        throw new RuntimeException(
            'Serviço do agendamento não encontrado.',
            409
        );
    }

    if ((float)$servico['preco'] <= 0) {
        throw new RuntimeException(
            'O serviço precisa possuir um valor maior que zero.',
            409
        );
    }

    // Cria a comanda aberta.
    $stmt = $db->prepare("
        INSERT INTO comandas
            (barbearia_id, agendamento_id, status, valor_total)
        VALUES (?, ?, 'aberta', ?)
    ");

    $stmt->execute([
        (int)$usuario['barbearia_id'],
        $agendamento_id,
        $servico['preco']
    ]);

    $comandaId = (int)$db->lastInsertId();

    // Registra o serviço como primeiro item.
    $stmt = $db->prepare("
        INSERT INTO comanda_itens
            (
                comanda_id,
                tipo,
                referencia_id,
                descricao,
                quantidade,
                valor_unitario,
                valor_total
            )
        VALUES (?, 'servico', ?, ?, 1.00, ?, ?)
    ");

    $stmt->execute([
        $comandaId,
        (int)$servico['id'],
        $servico['nome'],
        $servico['preco'],
        $servico['preco']
    ]);

    // Registra a abertura e a inclusão do serviço inicial
    // no histórico de auditoria da comanda.
    $stmt = $db->prepare("
        INSERT INTO comanda_movimentacoes
            (comanda_id, usuario_id, acao, descricao)
        VALUES (?, ?, ?, ?)
    ");

    $movimentacoes = [
        ['abertura', 'Comanda aberta após conclusão do atendimento.'],
        ['inclusao_item', 'Serviço inicial incluído na comanda.']
    ];

    foreach ($movimentacoes as [$acao, $descricao]) {
        $stmt->execute([
            $comandaId,
            (int)$usuario['id'],
            $acao,
            $descricao
        ]);
    }

}

$db->commit();

echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Status atualizado com sucesso!'
]);


} catch (Throwable $e) {

    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    $codigo = (int)$e->getCode();

    if (in_array($codigo, [403, 404, 409], true)) {
        responderErro($codigo, $e->getMessage());
    }

    error_log('Erro ao atualizar agendamento: ' . $e->getMessage());

    responderErro(
        500,
        'Erro interno ao atualizar o agendamento.'
    );
}
