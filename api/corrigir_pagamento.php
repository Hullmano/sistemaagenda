<?php
// api/corrigir_pagamento.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

function responderCorrecao(int $codigo, array $dados): void
{
    http_response_code($codigo);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

// Somente requisições POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderCorrecao(405, [
        'erro' => 'Método não permitido.'
    ]);
}

// Verifica a sessão
$usuario = usuarioLogado();

if (!$usuario) {
    responderCorrecao(401, [
        'erro' => 'Sessão expirada.'
    ]);
}

// Somente o dono pode corrigir pagamentos
if ($usuario['nivel'] !== 'dono') {
    responderCorrecao(403, [
        'erro' => 'Somente o dono pode corrigir pagamentos.'
    ]);
}

// Proteção CSRF
if (!validarTokenCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    responderCorrecao(403, [
        'erro' => 'Token de segurança inválido.'
    ]);
}

// Dados recebidos
$dados = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($dados)) {
    responderCorrecao(400, [
        'erro' => 'Dados inválidos.'
    ]);
}

$pagamentoId = filter_var(
    $dados['pagamento_id'] ?? null,
    FILTER_VALIDATE_INT
);

$novaForma = $dados['nova_forma'] ?? null;
$motivo = $dados['motivo'] ?? null;

$formasPermitidas = [
    'pix',
    'dinheiro',
    'debito',
    'credito',
    'outro'
];

if (!$pagamentoId || $pagamentoId <= 0) {
    responderCorrecao(400, [
        'erro' => 'Pagamento inválido.'
    ]);
}

if (
    !is_string($novaForma) ||
    !in_array($novaForma, $formasPermitidas, true)
) {
    responderCorrecao(400, [
        'erro' => 'Forma de pagamento inválida.'
    ]);
}

if (!is_string($motivo)) {
    responderCorrecao(400, [
        'erro' => 'Informe o motivo da correção.'
    ]);
}

$motivo = trim($motivo);

if (
    mb_strlen($motivo, 'UTF-8') < 5 ||
    mb_strlen($motivo, 'UTF-8') > 255
) {
    responderCorrecao(400, [
        'erro' => 'O motivo deve conter entre 5 e 255 caracteres.'
    ]);
}

try {
    $db = \Database::getConnection();
    $db->beginTransaction();

    $barbeariaId = (int)$usuario['barbearia_id'];
    $usuarioId = (int)$usuario['id'];

    // Mantém a mesma ordem de bloqueio utilizada
    // nas outras operações administrativas.
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

    // Busca e bloqueia o pagamento e a comanda.
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

    if ($pagamento['status_comanda'] !== 'fechada') {
        throw new RuntimeException(
            'A comanda precisa estar fechada.',
            409
        );
    }

    if ($pagamento['estornado_em'] !== null) {
        throw new RuntimeException(
            'Não é permitido corrigir um pagamento já estornado.',
            409
        );
    }

    $formaAnterior = $pagamento['forma_pagamento'];

    if ($formaAnterior === $novaForma) {
        throw new RuntimeException(
            'A forma selecionada já é a atual.',
            409
        );
    }

    // Protege conferências de dinheiro já realizadas.
    // Nesta primeira versão, correções que envolvam
    // dinheiro precisam ocorrer antes da conferência.
    if (
        $formaAnterior === 'dinheiro' ||
        $novaForma === 'dinheiro'
    ) {
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
                'Já existe conferência de caixa nesta data. '
                . 'Esta correção exige tratamento específico.',
                409
            );
        }
    }

    // Corrige somente a forma de pagamento.
    $stmt = $db->prepare("
        UPDATE pagamentos
        SET forma_pagamento = ?
        WHERE id = ?
          AND forma_pagamento = ?
    ");

    $stmt->execute([
        $novaForma,
        $pagamentoId,
        $formaAnterior
    ]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Não foi possível corrigir o pagamento.',
            409
        );
    }

    // Guarda os dados anteriores e novos para auditoria.
    $dadosAnteriores = json_encode([
        'pagamento_id' => (int)$pagamentoId,
        'forma_pagamento' => $formaAnterior,
        'valor' => (string)$pagamento['valor']
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $dadosNovos = json_encode([
        'pagamento_id' => (int)$pagamentoId,
        'forma_pagamento' => $novaForma,
        'valor' => (string)$pagamento['valor'],
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
            ?, ?, 'correcao_pagamento',
            ?, ?, ?
        )
    ");

    $stmt->execute([
        (int)$pagamento['comanda_id'],
        $usuarioId,
        'Correção da forma de pagamento.',
        $dadosAnteriores,
        $dadosNovos
    ]);

    $db->commit();

    responderCorrecao(200, [
        'sucesso' => true,
        'mensagem' => 'Pagamento corrigido com sucesso.',
        'pagamento_id' => (int)$pagamentoId,
        'forma_anterior' => $formaAnterior,
        'forma_nova' => $novaForma
    ]);

} catch (Throwable $e) {

    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    if (
        $e instanceof RuntimeException &&
        in_array((int)$e->getCode(), [404, 409], true)
    ) {
        responderCorrecao(
            (int)$e->getCode(),
            ['erro' => $e->getMessage()]
        );
    }

    error_log(
        'Erro ao corrigir pagamento: ' . $e->getMessage()
    );

    responderCorrecao(500, [
        'erro' => 'Erro interno ao corrigir pagamento.'
    ]);
}