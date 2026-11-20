
<?php
// api/registrar_pagamento.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

function responderPagamento(int $codigo, array $dados): void
{
    http_response_code($codigo);
    echo json_encode($dados);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderPagamento(405, [
        'erro' => 'Método não permitido.'
    ]);
}

// Autenticação administrativa
$usuario = usuarioLogado();

if (!$usuario) {
    responderPagamento(401, [
        'erro' => 'Sessão expirada.'
    ]);
}

// Proteção CSRF
if (!validarTokenCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    responderPagamento(403, [
        'erro' => 'Token de segurança inválido.'
    ]);
}

// Dados enviados pelo navegador
$dados = json_decode(file_get_contents('php://input'), true);

if (!is_array($dados)) {
    responderPagamento(400, [
        'erro' => 'JSON inválido.'
    ]);
}

$comanda_id = filter_var(
    $dados['comanda_id'] ?? null,
    FILTER_VALIDATE_INT
);

$forma_pagamento = $dados['forma_pagamento'] ?? '';

$formas_permitidas = [
    'pix',
    'dinheiro',
    'debito',
    'credito',
    'outro'
];

if (
    !$comanda_id ||
    $comanda_id <= 0 ||
    !is_string($forma_pagamento) ||
    !in_array($forma_pagamento, $formas_permitidas, true)
) {
    responderPagamento(400, [
        'erro' => 'Dados de pagamento inválidos.'
    ]);
}



try {
    $db = Database::getConnection();
    $db->beginTransaction();

    // Bloqueia a barbearia para sincronizar pagamentos
    // com conferências de caixa e correções financeiras.
    $stmt = $db->prepare("
        SELECT id
        FROM barbearias
        WHERE id = ?
        FOR UPDATE
    ");

    $stmt->execute([
        (int)$usuario['barbearia_id']
    ]);

    if (!$stmt->fetchColumn()) {
        throw new RuntimeException(
            'Barbearia não encontrada.',
            404
        );
    }

    // Bloqueia a comanda para impedir recebimentos simultâneos.
    $stmt = $db->prepare("
        SELECT
            c.id,
            c.barbearia_id,
            c.valor_total,
            c.status,
            a.usuario_id,
            a.status AS status_agendamento
        FROM comandas c
        JOIN agendamentos a
            ON a.id = c.agendamento_id
           AND a.barbearia_id = c.barbearia_id
        WHERE c.id = ?
          AND c.barbearia_id = ?
        FOR UPDATE
    ");

    $stmt->execute([
        $comanda_id,
        (int)$usuario['barbearia_id']
    ]);

    $comanda = $stmt->fetch();

    if (!$comanda) {
        throw new RuntimeException('Comanda não encontrada.', 404);
    }

    if (!podeGerenciarAgendamento($usuario, $comanda)) {
        throw new RuntimeException('Acesso não autorizado.', 403);
    }

    if ($comanda['status'] !== 'aberta') {
        throw new RuntimeException('Esta comanda não está aberta.', 409);
    }

    if ($comanda['status_agendamento'] !== 'concluido') {
        throw new RuntimeException(
            'Confirme a conclusão do atendimento antes de receber.',
            409
        );
    }

    // Trabalha com centavos para evitar erros de arredondamento.
    $totalCentavos = (int)round((float)$comanda['valor_total'] * 100);

    if ($totalCentavos <= 0) {
        throw new RuntimeException('A comanda não possui valor válido.', 409);
    }

    // Total já recebido, desconsiderando pagamentos estornados.
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(valor), 0)
        FROM pagamentos
        WHERE comanda_id = ?
        AND estornado_em IS NULL
    ");
    $stmt->execute([$comanda_id]);


    $recebidoCentavos = (int)round((float)$stmt->fetchColumn() * 100);
    $saldoCentavos = $totalCentavos - $recebidoCentavos;

    if ($saldoCentavos <= 0) {
        throw new RuntimeException('Esta comanda não possui saldo pendente.', 409);
    }

    // Nesta primeira versão, recebe o saldo integral.
    $valorPagamento = number_format($saldoCentavos / 100, 2, '.', '');

    // Impede novos recebimentos em dinheiro após a
    // conferência do caixa do responsável no dia.
    if ($forma_pagamento === 'dinheiro') {

        $stmt = $db->prepare("
            SELECT id
            FROM conferencias_caixa
            WHERE barbearia_id = ?
              AND usuario_responsavel_id = ?
              AND data_referencia = CURDATE()
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            (int)$usuario['barbearia_id'],
            (int)$usuario['id']
        ]);

        if ($stmt->fetchColumn()) {
            throw new RuntimeException(
                'O caixa deste responsável já foi conferido hoje. '
                . 'Não é permitido registrar novos pagamentos em dinheiro.',
                409
            );
        }
    }


    $stmt = $db->prepare("
        INSERT INTO pagamentos
            (comanda_id, usuario_id, forma_pagamento, valor)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([
        $comanda_id,
        (int)$usuario['id'],
        $forma_pagamento,
        $valorPagamento
    ]);

    $pagamentoId = (int)$db->lastInsertId();

    // Registra a movimentação para auditoria.
    $stmt = $db->prepare("
        INSERT INTO comanda_movimentacoes
            (comanda_id, usuario_id, acao, descricao, dados_novos)
        VALUES (?, ?, 'registro_pagamento', ?, ?)
    ");

    $stmt->execute([
        $comanda_id,
        (int)$usuario['id'],
        'Pagamento integral registrado.',
        json_encode([
            'pagamento_id' => $pagamentoId,
            'forma_pagamento' => $forma_pagamento,
            'valor' => $valorPagamento
        ], JSON_UNESCAPED_UNICODE)
    ]);

    // Fechamento automático após pagamento integral.
    $stmt = $db->prepare("
        UPDATE comandas
        SET
            status = 'fechada',
            usuario_fechamento_id = ?,
            fechado_em = NOW()
        WHERE id = ?
          AND barbearia_id = ?
          AND status = 'aberta'
    ");

    $stmt->execute([
        (int)$usuario['id'],
        $comanda_id,
        (int)$usuario['barbearia_id']
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Não foi possível fechar a comanda.', 409);
    }

    $stmt = $db->prepare("
        INSERT INTO comanda_movimentacoes
            (comanda_id, usuario_id, acao, descricao)
        VALUES (?, ?, 'fechamento', ?)
    ");

    $stmt->execute([
        $comanda_id,
        (int)$usuario['id'],
        'Comanda fechada após pagamento integral.'
    ]);

    $db->commit();

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Pagamento registrado com sucesso!',
        'comanda_id' => $comanda_id,
        'pagamento_id' => $pagamentoId,
        'valor_pago' => $valorPagamento,
        'status' => 'fechada'
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    $codigo = (int)$e->getCode();

    if (in_array($codigo, [403, 404, 409], true)) {
        responderPagamento($codigo, ['erro' => $e->getMessage()]);
    }

    error_log('Erro ao registrar pagamento: ' . $e->getMessage());

    responderPagamento(500, [
        'erro' => 'Erro interno ao registrar pagamento.'
    ]);
}
