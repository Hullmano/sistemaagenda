<?php
// api/estornar_pagamento.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

function responderEstorno(int $codigo, array $dados): void
{
    http_response_code($codigo);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

// Somente POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderEstorno(405, [
        'erro' => 'Método não permitido.'
    ]);
}

// Autenticação.
$usuario = usuarioLogado();

if (!$usuario) {
    responderEstorno(401, [
        'erro' => 'Sessão expirada.'
    ]);
}

// Somente o dono pode estornar.
if ($usuario['nivel'] !== 'dono') {
    responderEstorno(403, [
        'erro' => 'Somente o dono pode estornar pagamentos.'
    ]);
}

// Proteção CSRF.
if (!validarTokenCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    responderEstorno(403, [
        'erro' => 'Token de segurança inválido.'
    ]);
}

// Dados enviados pelo navegador.
$dados = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($dados)) {
    responderEstorno(400, [
        'erro' => 'Dados inválidos.'
    ]);
}

$pagamentoId = filter_var(
    $dados['pagamento_id'] ?? null,
    FILTER_VALIDATE_INT
);

$motivo = $dados['motivo'] ?? null;

if (!$pagamentoId || $pagamentoId <= 0) {
    responderEstorno(400, [
        'erro' => 'Pagamento inválido.'
    ]);
}

if (!is_string($motivo)) {
    responderEstorno(400, [
        'erro' => 'Informe o motivo do estorno.'
    ]);
}

$motivo = trim($motivo);

if (
    mb_strlen($motivo, 'UTF-8') < 5 ||
    mb_strlen($motivo, 'UTF-8') > 255
) {
    responderEstorno(400, [
        'erro' => 'O motivo deve conter entre 5 e 255 caracteres.'
    ]);
}


try {
    $db = Database::getConnection();
    $db->beginTransaction();

    $barbeariaId = (int)$usuario['barbearia_id'];
    $usuarioId = (int)$usuario['id'];

    // Mantém a mesma ordem de bloqueio das outras APIs financeiras.
    $stmt = $db->prepare("
        SELECT id
        FROM barbearias
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([$barbeariaId]);

    if (!$stmt->fetchColumn()) {
        throw new RuntimeException(
            'Barbearia não encontrada.',
            404
        );
    }

    // Busca e bloqueia o pagamento e sua comanda.
    $stmt = $db->prepare("
        SELECT
            p.id,
            p.comanda_id,
            p.usuario_id,
            p.forma_pagamento,
            p.valor,
            p.estornado_em,
            DATE(p.pago_em) AS data_pagamento,
            c.status AS status_comanda
        FROM pagamentos p
        JOIN comandas c
            ON c.id = p.comanda_id
        WHERE p.id = ?
          AND c.barbearia_id = ?
        FOR UPDATE
    ");

    $stmt->execute([
        $pagamentoId,
        $barbeariaId
    ]);

    $pagamento = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pagamento) {
        throw new RuntimeException(
            'Pagamento não encontrado.',
            404
        );
    }

    if ($pagamento['estornado_em'] !== null) {
        throw new RuntimeException(
            'Este pagamento já foi estornado.',
            409
        );
    }

    if ($pagamento['status_comanda'] !== 'fechada') {
        throw new RuntimeException(
            'Somente pagamentos de comandas fechadas podem ser estornados.',
            409
        );
    }

    // Nesta versão, só trabalhamos com pagamento integral.
    // Impede estorno em comandas com múltiplos pagamentos ativos.
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM pagamentos
        WHERE comanda_id = ?
          AND estornado_em IS NULL
    ");

    $stmt->execute([
        (int)$pagamento['comanda_id']
    ]);

    if ((int)$stmt->fetchColumn() !== 1) {
        throw new RuntimeException(
            'Esta comanda possui uma situação de pagamentos '
            . 'que exige análise específica.',
            409
        );
    }

    // Impede alteração de dinheiro já conferido.
    if ($pagamento['forma_pagamento'] === 'dinheiro') {
        $stmt = $db->prepare("
            SELECT id
            FROM conferencias_caixa
            WHERE barbearia_id = ?
              AND usuario_responsavel_id = ?
              AND data_referencia = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $barbeariaId,
            (int)$pagamento['usuario_id'],
            $pagamento['data_pagamento']
        ]);

        if ($stmt->fetchColumn()) {
            throw new RuntimeException(
                'Este pagamento em dinheiro já possui '
                . 'conferência de caixa. O estorno foi bloqueado.',
                409
            );
        }
    }

    // Marca o pagamento como estornado sem excluí-lo.
    $stmt = $db->prepare("
        UPDATE pagamentos
        SET
            estornado_em = NOW(),
            usuario_estorno_id = ?,
            motivo_estorno = ?
        WHERE id = ?
          AND estornado_em IS NULL
    ");

    $stmt->execute([
        $usuarioId,
        $motivo,
        $pagamentoId
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Não foi possível estornar o pagamento.',
            409
        );
    }

    // Reabre a comanda para permitir novo recebimento.
    $stmt = $db->prepare("
        UPDATE comandas
        SET
            status = 'aberta',
            usuario_fechamento_id = NULL,
            fechado_em = NULL
        WHERE id = ?
          AND barbearia_id = ?
          AND status = 'fechada'
    ");

    $stmt->execute([
        (int)$pagamento['comanda_id'],
        $barbeariaId
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Não foi possível reabrir a comanda.',
            409
        );
    }

    // Registra o estorno no histórico de auditoria.
    $dadosAnteriores = json_encode([
        'pagamento_id' => $pagamentoId,
        'forma_pagamento' => $pagamento['forma_pagamento'],
        'valor' => (string)$pagamento['valor'],
        'estornado' => false,
        'status_comanda' => 'fechada'
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $dadosNovos = json_encode([
        'pagamento_id' => $pagamentoId,
        'forma_pagamento' => $pagamento['forma_pagamento'],
        'valor' => (string)$pagamento['valor'],
        'estornado' => true,
        'status_comanda' => 'aberta',
        'motivo' => $motivo
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $stmt = $db->prepare("
        INSERT INTO comanda_movimentacoes (
            comanda_id,
            usuario_id,
            acao,
            descricao,
            dados_anteriores,
            dados_novos
        )
        VALUES (
            ?, ?, 'estorno_pagamento',
            ?, ?, ?
        )
    ");

    $stmt->execute([
        (int)$pagamento['comanda_id'],
        $usuarioId,
        'Pagamento estornado e comanda reaberta.',
        $dadosAnteriores,
        $dadosNovos
    ]);

    $db->commit();

    responderEstorno(200, [
        'sucesso' => true,
        'mensagem' => 'Pagamento estornado com sucesso.',
        'pagamento_id' => $pagamentoId,
        'comanda_id' => (int)$pagamento['comanda_id'],
        'status_comanda' => 'aberta'
    ]);

} catch (Throwable $e) {

    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    if (
        $e instanceof RuntimeException &&
        in_array((int)$e->getCode(), [404, 409], true)
    ) {
        responderEstorno(
            (int)$e->getCode(),
            ['erro' => $e->getMessage()]
        );
    }

    error_log('Erro ao estornar pagamento: ' . $e->getMessage());

    responderEstorno(500, [
        'erro' => 'Erro interno ao estornar pagamento.'
    ]);
}
