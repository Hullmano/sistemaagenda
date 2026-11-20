<?php
// views/admin_financeiro.php

$usuario = exigirLogin();

if ($usuario['nivel'] !== 'dono') {
    http_response_code(403);
    exit('Acesso negado.');
}

$db = \Database::getConnection();
$barbeariaId = (int)$usuario['barbearia_id'];

// Data selecionada ou data atual
$dataSelecionada = $_GET['data'] ?? date('Y-m-d');

$data = DateTimeImmutable::createFromFormat(
    '!Y-m-d',
    $dataSelecionada
);

if (!$data || $data->format('Y-m-d') !== $dataSelecionada) {
    $dataSelecionada = date('Y-m-d');
}

// Total efetivamente recebido na data selecionada
$stmt = $db->prepare("
    SELECT COALESCE(SUM(p.valor), 0)
    FROM pagamentos p
    JOIN comandas c ON c.id = p.comanda_id
    WHERE c.barbearia_id = ?
    AND p.estornado_em IS NULL
    AND p.pago_em >= ?
    AND p.pago_em < DATE_ADD(?, INTERVAL 1 DAY)
");

$stmt->execute([
    $barbeariaId,
    $dataSelecionada,
    $dataSelecionada
]);

$totalRecebido = (float)$stmt->fetchColumn();

// Recebimentos agrupados por forma de pagamento
$stmt = $db->prepare("
    SELECT
        p.forma_pagamento,
        COUNT(*) AS quantidade,
        SUM(p.valor) AS total
    FROM pagamentos p
    JOIN comandas c
        ON c.id = p.comanda_id
    WHERE c.barbearia_id = ?
        AND p.estornado_em IS NULL
        AND p.pago_em >= ?
        AND p.pago_em < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY p.forma_pagamento
");

$stmt->execute([
    $barbeariaId,
    $dataSelecionada,
    $dataSelecionada
]);

$recebimentosPorForma = [
    'pix' => 0.00,
    'dinheiro' => 0.00,
    'debito' => 0.00,
    'credito' => 0.00,
    'outro' => 0.00
];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
    $forma = $linha['forma_pagamento'];

    if (array_key_exists($forma, $recebimentosPorForma)) {
        $recebimentosPorForma[$forma] = (float)$linha['total'];
    }
}

// Saldo pendente das comandas abertas com atendimento concluído
$stmt = $db->prepare("
    SELECT
        COUNT(*) AS comandas_abertas,
        COALESCE(SUM(
            GREATEST(
                c.valor_total - COALESCE(pg.total_pago, 0),
                0
            )
        ), 0) AS total_pendente
    FROM comandas c
    JOIN agendamentos a ON a.id = c.agendamento_id
    LEFT JOIN (
        SELECT
            comanda_id,
            SUM(valor) AS total_pago
        FROM pagamentos
        WHERE estornado_em IS NULL
        GROUP BY comanda_id
    ) pg ON pg.comanda_id = c.id
    WHERE c.barbearia_id = ?
      AND a.barbearia_id = c.barbearia_id
      AND c.status = 'aberta'
      AND a.status = 'concluido'
      AND c.valor_total > COALESCE(pg.total_pago, 0)
      AND a.data_agendamento = ?
");

$stmt->execute([
    $barbeariaId,
    $dataSelecionada
]);

$pendencias = $stmt->fetch(PDO::FETCH_ASSOC);

$totalPendente = (float)$pendencias['total_pendente'];
$comandasAbertas = (int)$pendencias['comandas_abertas'];

// Lista de comandas pendentes na data selecionada
$stmt = $db->prepare("
    SELECT
        c.id AS comanda_id,
        a.id AS agendamento_id,
        COALESCE(NULLIF(a.nome_cliente, ''), cl.nome) AS cliente_nome,
        s.nome AS servico_nome,
        a.horario_inicio,
        c.valor_total,
        COALESCE(pg.total_pago, 0) AS total_pago,
        GREATEST(
            c.valor_total - COALESCE(pg.total_pago, 0),
            0
        ) AS saldo_pendente
    FROM comandas c
    JOIN agendamentos a
        ON a.id = c.agendamento_id
       AND a.barbearia_id = c.barbearia_id
    JOIN clientes cl
        ON cl.id = a.cliente_id
       AND cl.barbearia_id = c.barbearia_id
    JOIN servicos s
        ON s.id = a.servico_id
       AND s.barbearia_id = c.barbearia_id
    LEFT JOIN (
        SELECT
            comanda_id,
            SUM(valor) AS total_pago
        FROM pagamentos
        WHERE estornado_em IS NULL
        GROUP BY comanda_id
    ) pg ON pg.comanda_id = c.id
    WHERE c.barbearia_id = ?
      AND a.data_agendamento = ?
      AND a.status = 'concluido'
      AND c.status = 'aberta'
      AND c.valor_total > COALESCE(pg.total_pago, 0)
    ORDER BY a.horario_inicio ASC
");

$stmt->execute([
    $barbeariaId,
    $dataSelecionada
]);

$comandasPendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Histórico de pagamentos registrados na data selecionada
$stmt = $db->prepare("
    SELECT
        p.id AS pagamento_id,
        p.pago_em,
        p.forma_pagamento,
        p.valor,
        p.usuario_id,
        u.nome AS responsavel_nome,
        c.id AS comanda_id,
        a.id AS agendamento_id,
        COALESCE(NULLIF(a.nome_cliente, ''), cl.nome) AS cliente_nome
    FROM pagamentos p
    JOIN comandas c
        ON c.id = p.comanda_id
    JOIN agendamentos a
        ON a.id = c.agendamento_id
       AND a.barbearia_id = c.barbearia_id
    JOIN clientes cl
        ON cl.id = a.cliente_id
       AND cl.barbearia_id = c.barbearia_id
    JOIN usuarios u
        ON u.id = p.usuario_id
       AND u.barbearia_id = c.barbearia_id
    WHERE c.barbearia_id = ?
        AND p.estornado_em IS NULL
        AND p.pago_em >= ?
        AND p.pago_em < DATE_ADD(?, INTERVAL 1 DAY)
        ORDER BY p.pago_em DESC, p.id DESC
");

$stmt->execute([
    $barbeariaId,
    $dataSelecionada,
    $dataSelecionada
]);

$historicoPagamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Histórico de estornos dos pagamentos originalmente
// registrados na data selecionada.
$stmt = $db->prepare("
    SELECT
        p.id AS pagamento_id,
        p.valor,
        p.forma_pagamento,
        p.pago_em,
        p.estornado_em,
        p.motivo_estorno,
        c.id AS comanda_id,
        COALESCE(NULLIF(a.nome_cliente, ''), cl.nome) AS cliente_nome,
        u.nome AS estornado_por
    FROM pagamentos p
    JOIN comandas c
        ON c.id = p.comanda_id
    JOIN agendamentos a
        ON a.id = c.agendamento_id
       AND a.barbearia_id = c.barbearia_id
    JOIN clientes cl
        ON cl.id = a.cliente_id
       AND cl.barbearia_id = c.barbearia_id
    LEFT JOIN usuarios u
        ON u.id = p.usuario_estorno_id
       AND u.barbearia_id = c.barbearia_id
    WHERE c.barbearia_id = ?
      AND p.estornado_em IS NOT NULL
      AND p.pago_em >= ?
      AND p.pago_em < DATE_ADD(?, INTERVAL 1 DAY)
    ORDER BY p.estornado_em DESC, p.id DESC
");

$stmt->execute([
    $barbeariaId,
    $dataSelecionada,
    $dataSelecionada
]);

$historicoEstornos = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Total recebido por usuário na data selecionada
$stmt = $db->prepare("
    SELECT
        u.id AS usuario_id,
        u.nome AS profissional,
        COUNT(p.id) AS quantidade_pagamentos,
        COALESCE(SUM(p.valor), 0) AS total_recebido
    FROM pagamentos p
    JOIN comandas c
        ON c.id = p.comanda_id
    JOIN usuarios u
        ON u.id = p.usuario_id
       AND u.barbearia_id = c.barbearia_id
    WHERE c.barbearia_id = ?
      AND p.estornado_em IS NULL
      AND p.pago_em >= ?
      AND p.pago_em < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY u.id, u.nome
    ORDER BY total_recebido DESC, u.nome ASC
");

$stmt->execute([
    $barbeariaId,
    $dataSelecionada,
    $dataSelecionada
]);


$recebimentosPorProfissional = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Valores registrados em dinheiro por responsável
$stmt = $db->prepare("
    SELECT
        u.id AS usuario_id,
        u.nome AS responsavel,
        COUNT(p.id) AS quantidade_pagamentos,
        COALESCE(SUM(p.valor), 0) AS valor_dinheiro
    FROM pagamentos p
    JOIN comandas c
        ON c.id = p.comanda_id
    JOIN usuarios u
        ON u.id = p.usuario_id
       AND u.barbearia_id = c.barbearia_id
    WHERE c.barbearia_id = ?
      AND p.estornado_em IS NULL
      AND p.forma_pagamento = 'dinheiro'
      AND p.pago_em >= ?
      AND p.pago_em < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY u.id, u.nome
    ORDER BY u.nome ASC
");

$stmt->execute([
    $barbeariaId,
    $dataSelecionada,
    $dataSelecionada
]);

$dinheiroPorResponsavel = $stmt->fetchAll(PDO::FETCH_ASSOC);


 // Conferências de caixa já realizadas na data selecionada
$stmt = $db->prepare("
    SELECT
        cc.id,
        cc.usuario_responsavel_id,
        cc.valor_registrado,
        cc.ultimo_pagamento_id,
        cc.valor_conferido,
        cc.diferenca,
        cc.criado_em,
        (
            SELECT COUNT(*)
            FROM pagamentos p
            JOIN comandas c
                ON c.id = p.comanda_id
            WHERE c.barbearia_id = cc.barbearia_id
            AND p.usuario_id = cc.usuario_responsavel_id
            AND p.forma_pagamento = 'dinheiro'
            AND p.pago_em >= cc.data_referencia
            AND p.pago_em < DATE_ADD(cc.data_referencia, INTERVAL 1 DAY)
            AND (
                (
                    cc.ultimo_pagamento_id IS NOT NULL
                    AND p.id > cc.ultimo_pagamento_id
                )
                OR (
                    cc.ultimo_pagamento_id IS NULL
                    AND p.criado_em > cc.criado_em
                )
            )
        ) AS pagamentos_posteriores,
        u.nome AS conferido_por
    FROM conferencias_caixa cc
    JOIN usuarios u
        ON u.id = cc.usuario_conferencia_id
       AND u.barbearia_id = cc.barbearia_id
    WHERE cc.barbearia_id = ?
      AND cc.data_referencia = ?
");

$stmt->execute([
    $barbeariaId,
    $dataSelecionada
]);

$conferenciasRealizadas = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
    $conferenciasRealizadas[
        (int)$linha['usuario_responsavel_id']
    ] = $linha;
}

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financeiro</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body class="bg-dark text-white">

<main class="container py-4" style="max-width: 700px;">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <h3 class="mb-0">Financeiro</h3>

        <a
            href="/agenda/<?= rawurlencode($slug_filtrado) ?>/admin"
            class="btn btn-outline-light btn-sm"
        >
            <i class="fa-solid fa-arrow-left me-1"></i>
            Voltar
        </a>

    </div>

    <form method="get" class="mb-4">
        <label for="data" class="form-label">Selecionar data</label>
        <input
            type="date"
            id="data"
            name="data"
            class="form-control bg-dark text-white"
            value="<?= htmlspecialchars($dataSelecionada, ENT_QUOTES, 'UTF-8') ?>"
            onchange="this.form.submit()"
        >
    </form>

    <div class="row g-3">

        <div class="col-12">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <small>Recebido no dia</small>
                    <h3>
                        R$ <?= number_format($totalRecebido, 2, ',', '.') ?>
                    </h3>
                </div>
            </div>
        </div>

        <div class="col-6">
            <div class="card bg-secondary text-white h-100">
                <div class="card-body">
                    <small>Pendente no dia</small>
                    <h4>
                        R$ <?= number_format($totalPendente, 2, ',', '.') ?>
                    </h4>
                </div>
            </div>
        </div>

        <div class="col-6">
            <div class="card bg-secondary text-white h-100">
                <div class="card-body">
                    <small>Comandas pendentes</small>
                    <h4><?= $comandasAbertas ?></h4>
                </div>
            </div>
        </div>

    </div>

    <p class="text-secondary small mt-4">
        Recebido: pagamentos registrados na data selecionada.
        Pendente: saldo das comandas abertas com atendimentos
        concluídos na data selecionada.
    </p>

<!-- Resumo por forma de pagamento -->
<section class="mt-4">

    <h5 class="mb-3">Recebimentos por forma de pagamento</h5>

    <?php
    $nomesFormas = [
        'pix' => 'Pix',
        'dinheiro' => 'Dinheiro',
        'debito' => 'Cartão de débito',
        'credito' => 'Cartão de crédito',
        'outro' => 'Outros'
    ];

    $iconesFormas = [
        'pix' => 'fa-qrcode',
        'dinheiro' => 'fa-money-bill-wave',
        'debito' => 'fa-credit-card',
        'credito' => 'fa-credit-card',
        'outro' => 'fa-wallet'
    ];
    ?>

    <div class="card bg-dark text-white border-secondary">
        <div class="card-body">

            <?php foreach ($recebimentosPorForma as $forma => $valor): ?>

                <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-secondary">

                    <span>
                        <i class="fa-solid <?= $iconesFormas[$forma] ?> text-warning me-2"></i>
                        <?= htmlspecialchars($nomesFormas[$forma], ENT_QUOTES, 'UTF-8') ?>
                    </span>

                    <strong class="<?= $valor > 0 ? 'text-success' : 'text-secondary' ?>">
                        R$ <?= number_format($valor, 2, ',', '.') ?>
                    </strong>

                </div>

            <?php endforeach; ?>

            <div class="d-flex justify-content-between align-items-center pt-3">
                <strong>Total recebido</strong>
                <strong class="text-success fs-5">
                    R$ <?= number_format($totalRecebido, 2, ',', '.') ?>
                </strong>
            </div>

        </div>
    </div>

</section>


<!-- Conferência de caixa opcional -->
<section class="mt-4 mb-4">

    <button
        type="button"
        class="btn btn-outline-secondary w-100 d-flex justify-content-between align-items-center py-3"
        data-bs-toggle="collapse"
        data-bs-target="#conteudoConferenciaCaixa"
        aria-expanded="false"
        aria-controls="conteudoConferenciaCaixa"
    >
        <span>
            <i class="fa-solid fa-cash-register text-warning me-2"></i>
            Conferência de Caixa
        </span>

        <span class="badge bg-secondary">Opcional</span>
    </button>

    <div class="collapse mt-3" id="conteudoConferenciaCaixa">

    <h5 class="mb-3">
        <i class="fa-solid fa-cash-register text-warning me-2"></i>
        Conferência de Caixa
    </h5>

    <p class="text-secondary small">
        Confira os valores recebidos em dinheiro por cada responsável.
    </p>

    <?php if (empty($dinheiroPorResponsavel)): ?>

        <div class="card bg-dark border-secondary text-white">
            <div class="card-body text-center py-4">
                <i class="fa-solid fa-money-bill-wave text-secondary fs-3 mb-2"></i>

                <p class="text-secondary mb-0">
                    Nenhum recebimento em dinheiro nesta data.
                </p>
            </div>
        </div>

    <?php else: ?>

        <?php foreach ($dinheiroPorResponsavel as $responsavel): ?>

            <?php
                $usuarioId = (int)$responsavel['usuario_id'];

                $conferencia = $conferenciasRealizadas[$usuarioId] ?? null;

                $valorAtualCentavos = (int)round(
                    (float)$responsavel['valor_dinheiro'] * 100
                );

                $valorConferidoRegistradoCentavos = $conferencia
                    ? (int)round((float)$conferencia['valor_registrado'] * 100)
                    : 0;

                // Detecta alterações no total registrado
                $valoresMudaram = $conferencia !== null &&
                    $valorAtualCentavos !== $valorConferidoRegistradoCentavos;

                // Detecta lançamentos criados após a conferência
                $pagamentosPosteriores = $conferencia !== null
                    ? (int)($conferencia['pagamentos_posteriores'] ?? 0)
                    : 0;

                // Exibe o alerta se qualquer uma das condições ocorrer
                $exibirAlertaConferencia = $valoresMudaram ||
                    $pagamentosPosteriores > 0;
            ?>

            <div class="card bg-dark border-secondary text-white mb-3">
                <div class="card-body">

                    <h6 class="fw-bold mb-3">
                        <?= htmlspecialchars(
                            $responsavel['responsavel'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </h6>

                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-secondary">
                            Dinheiro registrado
                        </span>

                        <strong class="text-success">
                            R$ <?= number_format(
                                (float)$responsavel['valor_dinheiro'],
                                2,
                                ',',
                                '.'
                            ) ?>
                        </strong>
                    </div>

                    <?php if ($conferencia): ?>

                        <div class="alert alert-success mb-3">
                            <i class="fa-solid fa-circle-check me-1"></i>
                            Conferência realizada
                        </div>

                        <?php if ($exibirAlertaConferencia): ?>

                            <div class="alert alert-warning">
                                <i class="fa-solid fa-triangle-exclamation me-1"></i>

                                <strong>
                                    <?= $pagamentosPosteriores > 0
                                        ? 'Existem pagamentos registrados após a conferência.'
                                        : 'O total registrado mudou após a conferência.' ?>
                                </strong>

                                <?php if ($pagamentosPosteriores > 0): ?>
                                    <div class="small mt-2">
                                        <?= $pagamentosPosteriores ?>
                                        novo(s) pagamento(s) em dinheiro identificado(s).
                                    </div>
                                <?php endif; ?>
                                <div class="small mt-2">
                                    Valor considerado na conferência:
                                    R$ <?= number_format(
                                        (float)$conferencia['valor_registrado'],
                                        2, ',', '.'
                                    ) ?>

                                    <br>

                                    Total registrado atualmente:
                                    R$ <?= number_format(
                                        (float)$responsavel['valor_dinheiro'],
                                        2, ',', '.'
                                    ) ?>
                                </div>
                            </div>

                        <?php endif; ?>

                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary">Valor conferido</span>

                            <strong>
                                R$ <?= number_format(
                                    (float)$conferencia['valor_conferido'],
                                    2,
                                    ',',
                                    '.'
                                ) ?>
                            </strong>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-secondary">Diferença</span>

                            <strong class="<?= (float)$conferencia['diferenca'] < 0
                                ? 'text-danger'
                                : ((float)$conferencia['diferenca'] > 0
                                    ? 'text-warning'
                                    : 'text-success') ?>">
                                R$ <?= number_format(
                                    (float)$conferencia['diferenca'],
                                    2,
                                    ',',
                                    '.'
                                ) ?>
                            </strong>
                        </div>

                        <div class="text-secondary small mt-3">
                            Conferido por:
                            <?= htmlspecialchars(
                                $conferencia['conferido_por'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>

                            <br>

                            Em:
                            <?= date(
                                'd/m/Y H:i',
                                strtotime($conferencia['criado_em'])
                            ) ?>
                        </div>

                    <?php else: ?>

                    <label class="form-label small">
                        Valor contado em dinheiro
                    </label>

                    <div class="input-group mb-3">
                        <span class="input-group-text">R$</span>

                        <input
                            type="number"
                            class="form-control campo-valor-conferido"
                            min="0"
                            max="99999999.99"
                            step="0.01"
                            placeholder="0,00"
                            data-usuario-id="<?= (int)$responsavel['usuario_id'] ?>"
                            data-data-referencia="<?= htmlspecialchars($dataSelecionada, ENT_QUOTES, 'UTF-8') ?>"
                            data-valor-registrado="<?= htmlspecialchars((string)$responsavel['valor_dinheiro'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                    </div>

                    <button
                        type="button"
                        class="btn btn-warning w-100 fw-bold btn-salvar-conferencia"
                        disabled
                    >
                        Salvar conferência
                    </button>

                    <div class="alert alert-danger mt-3 d-none erro-conferencia"
                        role="alert"></div>

                    <small class="text-secondary d-block mt-2">
                        Conferência opcional. Confira o valor contado
                        antes de salvar. O registro não altera os pagamentos.
                    </small>

                    <?php endif; ?>

                </div>
            </div>

        <?php endforeach; ?>

    <?php endif; ?>
    </div>
</section>

<!-- Recebimentos por responsável -->
<section class="mt-4 mb-4">

    <h5 class="mb-3">Recebimentos por responsável</h5>

    <?php if (empty($recebimentosPorProfissional)): ?>

        <div class="card bg-dark border-secondary text-white">
            <div class="card-body text-center py-4">
                <p class="text-secondary mb-0">
                    Nenhum recebimento registrado nesta data.
                </p>
            </div>
        </div>

    <?php else: ?>

        <div class="card bg-dark border-secondary text-white">
            <div class="card-body">

                <?php foreach ($recebimentosPorProfissional as $responsavel): ?>

                    <div class="d-flex justify-content-between
                                align-items-center gap-3 py-3
                                border-bottom border-secondary">

                        <div>
                            <div class="fw-bold">
                                <?= htmlspecialchars(
                                    $responsavel['profissional'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </div>

                            <small class="text-secondary">
                                <?= (int)$responsavel['quantidade_pagamentos'] ?>
                                pagamento(s) registrado(s)
                            </small>
                        </div>

                        <strong class="text-success text-nowrap">
                            R$ <?= number_format(
                                (float)$responsavel['total_recebido'],
                                2,
                                ',',
                                '.'
                            ) ?>
                        </strong>

                    </div>

                <?php endforeach; ?>

            </div>
        </div>

    <?php endif; ?>

</section>

<!-- Histórico de recebimentos -->
<section class="mt-4 mb-4">

    <h5 class="mb-3">Histórico de recebimentos</h5>

    <?php if (empty($historicoPagamentos)): ?>

        <div class="card bg-dark border-secondary text-white">
            <div class="card-body text-center py-4">
                <p class="text-secondary mb-0">
                    Nenhum pagamento registrado nesta data.
                </p>
            </div>
        </div>

    <?php else: ?>

        <?php
        $nomesPagamento = [
            'pix' => 'Pix',
            'dinheiro' => 'Dinheiro',
            'debito' => 'Cartão de débito',
            'credito' => 'Cartão de crédito',
            'outro' => 'Outros'
        ];
        ?>

        <?php foreach ($historicoPagamentos as $pagamento): ?>

            <div class="card bg-dark border-secondary text-white mb-3">
                <div class="card-body">

                    <div class="d-flex justify-content-between align-items-start gap-2">

                        <div>
                            <h6 class="fw-bold mb-1">
                                <?= htmlspecialchars(
                                    $pagamento['cliente_nome'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </h6>

                            <small class="text-secondary">
                                Comanda #<?= (int)$pagamento['comanda_id'] ?>
                                ·
                                <?= date(
                                    'd/m/Y H:i',
                                    strtotime($pagamento['pago_em'])
                                ) ?>
                            </small>
                        </div>

                        <strong class="text-success text-nowrap">
                            R$ <?= number_format(
                                (float)$pagamento['valor'],
                                2,
                                ',',
                                '.'
                            ) ?>
                        </strong>

                    </div>

                    <div class="border-top border-secondary mt-3 pt-3">

                        <div class="d-flex justify-content-between gap-2 mb-2">
                            <span class="text-secondary small">
                                Forma de pagamento
                            </span>

                            <span class="small">
                                <?= htmlspecialchars(
                                    $nomesPagamento[$pagamento['forma_pagamento']]
                                        ?? $pagamento['forma_pagamento'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>
                        </div>

                        <div class="d-flex justify-content-between gap-2">
                            <span class="text-secondary small">
                                Recebido por
                            </span>

                            <span class="small text-end">
                                <?= htmlspecialchars(
                                    $pagamento['responsavel_nome'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>
                        </div>
                        <div class="mt-3 pt-3 border-top border-secondary">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-warning w-100 btn-corrigir-pagamento"
                                data-pagamento-id="<?= (int)$pagamento['pagamento_id'] ?>"
                                data-cliente="<?= htmlspecialchars(
                                    $pagamento['cliente_nome'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                data-forma="<?= htmlspecialchars(
                                    $pagamento['forma_pagamento'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                data-valor="<?= htmlspecialchars(
                                    (string)$pagamento['valor'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >
                                <i class="fa-solid fa-pen-to-square me-1"></i>
                                Corrigir forma de pagamento
                            </button>

                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger w-100 mt-2 btn-estornar-pagamento"
                                data-pagamento-id="<?= (int)$pagamento['pagamento_id'] ?>"
                                data-cliente="<?= htmlspecialchars(
                                    $pagamento['cliente_nome'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                data-valor="<?= htmlspecialchars(
                                    (string)$pagamento['valor'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >
                                <i class="fa-solid fa-rotate-left me-1"></i>
                                Estornar pagamento
                            </button>

                        </div>

                    </div>

                </div>
            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</section>

    <!-- Histórico de estornos -->
    <section class="mt-4 mb-4">

        <button
            type="button"
            class="btn btn-outline-secondary w-100 d-flex justify-content-between align-items-center py-3"
            data-bs-toggle="collapse"
            data-bs-target="#conteudoHistoricoEstornos"
            aria-expanded="false"
            aria-controls="conteudoHistoricoEstornos"
        >
            <span>
                <i class="fa-solid fa-clock-rotate-left text-danger me-2"></i>
                Histórico de estornos
            </span>

            <span class="badge bg-secondary">
                <?= count($historicoEstornos) ?>
            </span>
        </button>

        <div class="collapse mt-3" id="conteudoHistoricoEstornos">

            <p class="text-secondary small">
                Estornos de pagamentos originalmente registrados
                na data selecionada.
            </p>

            <?php if (empty($historicoEstornos)): ?>

                <div class="card bg-dark border-secondary text-white">
                    <div class="card-body text-center py-4">
                        <p class="text-secondary mb-0">
                            Nenhum estorno encontrado para esta data.
                        </p>
                    </div>
                </div>

            <?php else: ?>

                <?php foreach ($historicoEstornos as $estorno): ?>

                    <div class="card bg-dark border-secondary text-white mb-3">
                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                                <div>
                                    <h6 class="fw-bold mb-1">
                                        <?= htmlspecialchars(
                                            $estorno['cliente_nome'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </h6>

                                    <small class="text-secondary">
                                        Pagamento #<?= (int)$estorno['pagamento_id'] ?>
                                        · Comanda #<?= (int)$estorno['comanda_id'] ?>
                                    </small>
                                </div>

                                <strong class="text-danger text-nowrap">
                                    R$ <?= number_format(
                                        (float)$estorno['valor'],
                                        2,
                                        ',',
                                        '.'
                                    ) ?>
                                </strong>
                            </div>

                            <div class="border-top border-secondary pt-3">

                                <p class="small mb-2">
                                    <span class="text-secondary">Estornado em:</span>
                                    <?= date(
                                        'd/m/Y H:i',
                                        strtotime($estorno['estornado_em'])
                                    ) ?>
                                </p>

                                <p class="small mb-2">
                                    <span class="text-secondary">Estornado por:</span>
                                    <?= htmlspecialchars(
                                        $estorno['estornado_por'] ?? 'Não identificado',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                                <p class="small mb-0">
                                    <span class="text-secondary">Motivo:</span>
                                    <?= htmlspecialchars(
                                        $estorno['motivo_estorno'] ?? '',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </p>

                            </div>

                        </div>
                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </section>


    <!-- Lista de comandas pendentes -->
    <section class="mt-4">

        <h5 class="mb-3">Comandas pendentes</h5>

        <?php if (empty($comandasPendentes)): ?>

            <div class="card bg-dark border-secondary text-white">
                <div class="card-body text-center py-4">
                    <p class="text-success mb-0">
                        <i class="fa-solid fa-circle-check me-1"></i>
                        Nenhuma comanda pendente nesta data.
                    </p>
                </div>
            </div>

        <?php else: ?>

            <?php foreach ($comandasPendentes as $comanda): ?>

                <div class="card bg-dark border-secondary text-white mb-3">
                    <div class="card-body">

                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h6 class="fw-bold mb-1">
                                    <?= htmlspecialchars(
                                        $comanda['cliente_nome'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </h6>

                                <small class="text-secondary">
                                    <?= htmlspecialchars(
                                        $comanda['servico_nome'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                    ·
                                    <?= htmlspecialchars(
                                        substr($comanda['horario_inicio'], 0, 5),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </small>
                            </div>

                            <div class="d-flex flex-column align-items-end gap-2">
                                <span class="badge bg-warning text-dark">
                                    A receber
                                </span>

                                <a
                                    href="/agenda/<?= rawurlencode($slug_filtrado) ?>/admin?data=<?= urlencode($dataSelecionada) ?>&receber=<?= (int)$comanda['comanda_id'] ?>"
                                    class="btn btn-sm btn-outline-success"
                                >
                                    Receber
                                </a>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center
                                    border-top border-secondary pt-3 mt-3">

                            <span class="text-secondary small">
                                Saldo pendente
                            </span>

                            <strong class="text-warning fs-5">
                                R$ <?= number_format(
                                    (float)$comanda['saldo_pendente'],
                                    2,
                                    ',',
                                    '.'
                                ) ?>
                            </strong>
                        </div>

                    </div>
                </div>

            <?php endforeach; ?>

        <?php endif; ?>

</section>

<!-- Modal de correção de pagamento -->
<div class="modal fade" id="modalCorrigirPagamento"
     tabindex="-1" aria-labelledby="tituloCorrigirPagamento"
     aria-hidden="true" data-bs-theme="dark">

    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">

            <div class="modal-header">
                <h5 class="modal-title" id="tituloCorrigirPagamento">
                    Corrigir pagamento
                </h5>
                <button type="button" class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Fechar"></button>
            </div>

            <div class="modal-body">
                <p class="text-secondary small mb-1">Cliente</p>
                <h6 id="corrigirCliente" class="mb-3"></h6>

                <p class="text-secondary small mb-1">Valor registrado</p>
                <h4 id="corrigirValor" class="text-success mb-3"></h4>

                <p class="text-secondary small mb-1">Forma atual</p>
                <p id="corrigirFormaAtual" class="fw-bold"></p>

                <label for="corrigirNovaForma" class="form-label">
                    Nova forma de pagamento
                </label>

                <select id="corrigirNovaForma" class="form-select mb-3">
                    <option value="pix">Pix</option>
                    <option value="dinheiro">Dinheiro</option>
                    <option value="debito">Cartão de débito</option>
                    <option value="credito">Cartão de crédito</option>
                    <option value="outro">Outros</option>
                </select>

                <label for="corrigirMotivo" class="form-label">
                    Motivo da correção
                </label>

                <textarea
                    id="corrigirMotivo"
                    class="form-control"
                    rows="3"
                    minlength="5"
                    maxlength="255"
                    placeholder="Ex.: Forma de pagamento selecionada incorretamente."
                ></textarea>

                <div id="corrigirErro"
                     class="alert alert-danger mt-3 d-none"
                     role="alert"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">
                    Cancelar
                </button>

                <button type="button" class="btn btn-warning fw-bold"
                        id="btnConfirmarCorrecao" disabled>
                    Salvar correção
                </button>
            </div>

        </div>
    </div>
</div>

<!-- Modal de estorno de pagamento -->
<div class="modal fade" id="modalEstornarPagamento"
     tabindex="-1" aria-labelledby="tituloEstornarPagamento"
     aria-hidden="true" data-bs-theme="dark">

    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">

            <div class="modal-header">
                <h5 class="modal-title" id="tituloEstornarPagamento">
                    Estornar pagamento
                </h5>

                <button type="button" class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Fechar"></button>
            </div>

            <div class="modal-body">

                <div class="alert alert-warning">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    O estorno retirará este valor dos recebimentos
                    e reabrirá a comanda para um novo pagamento.
                </div>

                <p class="text-secondary small mb-1">Cliente</p>
                <h6 id="estornarCliente" class="mb-3"></h6>

                <p class="text-secondary small mb-1">Valor do pagamento</p>
                <h4 id="estornarValor" class="text-danger mb-3"></h4>

                <label for="estornarMotivo" class="form-label">
                    Motivo do estorno
                </label>

                <textarea
                    id="estornarMotivo"
                    class="form-control"
                    rows="3"
                    minlength="5"
                    maxlength="255"
                    placeholder="Ex.: Pagamento registrado por engano."
                ></textarea>

                <div id="estornarErro"
                     class="alert alert-danger mt-3 d-none"
                     role="alert"></div>

            </div>

            <div class="modal-footer">

                <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">
                    Cancelar
                </button>

                <button type="button" class="btn btn-danger fw-bold"
                        id="btnConfirmarEstorno" disabled>
                    Confirmar estorno
                </button>

            </div>
        </div>
    </div>
</div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('.campo-valor-conferido').forEach(campo => {

        const card = campo.closest('.card');
        const botao = card.querySelector('.btn-salvar-conferencia');
        const erro = card.querySelector('.erro-conferencia');

        campo.addEventListener('input', function () {
            erro.classList.add('d-none');
            botao.disabled = true;

            const valor = campo.value.trim();

            // Aceita valores como 30, 30.5 e 30.50
            if (!/^\d{1,8}(\.\d{1,2})?$/.test(valor)) {
                return;
            }

            const numero = Number(valor);

            if (!Number.isFinite(numero) || numero > 9999999.99) {
                return;
            }

            botao.disabled = false;
        });


        botao.addEventListener('click', async function () {
            if (botao.disabled) return;

            const valor = campo.value.trim();

            if (!/^\d{1,8}(\.\d{1,2})?$/.test(valor)) {
                return;
            }

            const usuarioId = Number(campo.dataset.usuarioId);
            const dataReferencia = campo.dataset.dataReferencia;

            if (!Number.isInteger(usuarioId) || usuarioId <= 0) {
                return;
            }

            const confirmado = window.confirm(
                'Confirmar conferência de caixa?\n\n' +
                'Valor contado: R$ ' + Number(valor).toFixed(2) +
                '\n\nEssa operação será registrada no histórico.'
            );

            if (!confirmado) return;

            botao.disabled = true;
            botao.textContent = 'Salvando...';
            erro.classList.add('d-none');

            try {
                const resposta = await fetch(
                    '/agenda/api/salvar_conferencia_caixa.php',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': <?= json_encode(
                                gerarTokenCsrf(),
                                JSON_HEX_TAG | JSON_HEX_AMP |
                                JSON_HEX_APOS | JSON_HEX_QUOT
                            ) ?>
                        },
                        body: JSON.stringify({
                            usuario_responsavel_id: usuarioId,
                            data_referencia: dataReferencia,
                            valor_conferido: valor
                        })
                    }
                );

                const dados = await resposta.json();

                if (!resposta.ok || !dados.sucesso) {
                    throw new Error(
                        dados.erro || 'Não foi possível salvar a conferência.'
                    );
                }

                alert(
                    'Conferência registrada com sucesso!\n' +
                    'Diferença: R$ ' + dados.diferenca
                );

                window.location.reload();

            } catch (falha) {
                erro.textContent = falha.message;
                erro.classList.remove('d-none');
                botao.disabled = false;
                botao.textContent = 'Salvar conferência';
            }
        });

    });

});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const modalElemento = document.getElementById('modalCorrigirPagamento');
    const btnConfirmar = document.getElementById('btnConfirmarCorrecao');
    const campoNovaForma = document.getElementById('corrigirNovaForma');
    const campoMotivo = document.getElementById('corrigirMotivo');
    const campoErro = document.getElementById('corrigirErro');

    if (!modalElemento || !window.bootstrap) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalElemento);

    const nomesFormas = {
        pix: 'Pix',
        dinheiro: 'Dinheiro',
        debito: 'Cartão de débito',
        credito: 'Cartão de crédito',
        outro: 'Outros'
    };

    let pagamentoSelecionado = null;
    let formaOriginal = null;

    document.querySelectorAll('.btn-corrigir-pagamento').forEach(botao => {

        botao.addEventListener('click', function () {

            const pagamentoId = Number(this.dataset.pagamentoId);
            const valor = Number(this.dataset.valor);
            const forma = this.dataset.forma;
            const cliente = this.dataset.cliente;

            if (
                !Number.isInteger(pagamentoId) ||
                pagamentoId <= 0 ||
                !Number.isFinite(valor) ||
                valor <= 0 ||
                !Object.hasOwn(nomesFormas, forma)
            ) {
                return;
            }

            pagamentoSelecionado = pagamentoId;
            formaOriginal = forma;

            document.getElementById('corrigirCliente').textContent = cliente;

            document.getElementById('corrigirValor').textContent =
                valor.toLocaleString('pt-BR', {
                    style: 'currency',
                    currency: 'BRL'
                });

            document.getElementById('corrigirFormaAtual').textContent =
                nomesFormas[forma];

            campoNovaForma.value = forma;
            campoMotivo.value = '';
            campoErro.textContent = '';
            campoErro.classList.add('d-none');

            btnConfirmar.disabled = true;

            modal.show();
        });
    });

    function validarCorrecao() {
        const motivo = campoMotivo.value.trim();

        btnConfirmar.disabled = !(
            pagamentoSelecionado !== null &&
            campoNovaForma.value !== formaOriginal &&
            motivo.length >= 5 &&
            motivo.length <= 255
        );
    }

    campoNovaForma.addEventListener('change', validarCorrecao);
    campoMotivo.addEventListener('input', validarCorrecao);

    let processandoCorrecao = false;

    btnConfirmar.addEventListener('click', async function () {
        if (processandoCorrecao) return;

        validarCorrecao();

        if (btnConfirmar.disabled) return;

        const novaForma = campoNovaForma.value;
        const motivo = campoMotivo.value.trim();

        const confirmado = window.confirm(
            'Confirmar correção da forma de pagamento?\n\n' +
            'De: ' + nomesFormas[formaOriginal] + '\n' +
            'Para: ' + nomesFormas[novaForma] + '\n\n' +
            'Esta alteração ficará registrada no histórico.'
        );

        if (!confirmado) return;

        processandoCorrecao = true;
        btnConfirmar.disabled = true;
        btnConfirmar.textContent = 'Salvando...';
        campoErro.classList.add('d-none');

        try {
            const resposta = await fetch(
                '/agenda/api/corrigir_pagamento.php',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': <?= json_encode(
                            gerarTokenCsrf(),
                            JSON_HEX_TAG | JSON_HEX_AMP |
                            JSON_HEX_APOS | JSON_HEX_QUOT
                        ) ?>
                    },
                    body: JSON.stringify({
                        pagamento_id: pagamentoSelecionado,
                        nova_forma: novaForma,
                        motivo: motivo
                    })
                }
            );

            const dados = await resposta.json();

            if (!resposta.ok || !dados.sucesso) {
                throw new Error(
                    dados.erro || 'Não foi possível corrigir o pagamento.'
                );
            }

            alert('Forma de pagamento corrigida com sucesso!');
            window.location.reload();

        } catch (falha) {
            campoErro.textContent = falha.message;
            campoErro.classList.remove('d-none');

            btnConfirmar.textContent = 'Salvar correção';
            processandoCorrecao = false;
            validarCorrecao();
        }
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const modalElemento = document.getElementById('modalEstornarPagamento');
    const btnConfirmar = document.getElementById('btnConfirmarEstorno');
    const campoMotivo = document.getElementById('estornarMotivo');
    const campoErro = document.getElementById('estornarErro');

    if (!modalElemento || !window.bootstrap) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalElemento);

    let pagamentoSelecionado = null;

    document.querySelectorAll('.btn-estornar-pagamento').forEach(botao => {

        botao.addEventListener('click', function () {

            const pagamentoId = Number(this.dataset.pagamentoId);
            const valor = Number(this.dataset.valor);
            const cliente = this.dataset.cliente;

            if (
                !Number.isInteger(pagamentoId) ||
                pagamentoId <= 0 ||
                !Number.isFinite(valor) ||
                valor <= 0
            ) {
                return;
            }

            pagamentoSelecionado = pagamentoId;

            document.getElementById('estornarCliente').textContent = cliente;

            document.getElementById('estornarValor').textContent =
                valor.toLocaleString('pt-BR', {
                    style: 'currency',
                    currency: 'BRL'
                });

            campoMotivo.value = '';
            campoErro.textContent = '';
            campoErro.classList.add('d-none');

            btnConfirmar.disabled = true;

            modal.show();
        });
    });

    campoMotivo.addEventListener('input', function () {

        const motivo = campoMotivo.value.trim();

        btnConfirmar.disabled = !(
            pagamentoSelecionado !== null &&
            motivo.length >= 5 &&
            motivo.length <= 255
        );
    });

    let processandoEstorno = false;

    btnConfirmar.addEventListener('click', async function () {

        if (processandoEstorno) return;

        const motivo = campoMotivo.value.trim();

        if (
            pagamentoSelecionado === null ||
            motivo.length < 5 ||
            motivo.length > 255
        ) {
            return;
        }

        const confirmado = window.confirm(
            'ATENÇÃO: confirmar o estorno deste pagamento?\n\n' +
            'O valor será retirado dos recebimentos e a comanda ' +
            'será reaberta.\n\n' +
            'Esta operação ficará registrada na auditoria.'
        );

        if (!confirmado) return;

        processandoEstorno = true;
        btnConfirmar.disabled = true;
        btnConfirmar.textContent = 'Estornando...';
        campoErro.classList.add('d-none');

        try {
            const resposta = await fetch(
                '/agenda/api/estornar_pagamento.php',
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': <?= json_encode(
                            gerarTokenCsrf(),
                            JSON_HEX_TAG | JSON_HEX_AMP |
                            JSON_HEX_APOS | JSON_HEX_QUOT
                        ) ?>
                    },
                    body: JSON.stringify({
                        pagamento_id: pagamentoSelecionado,
                        motivo: motivo
                    })
                }
            );

            const dados = await resposta.json();

            if (!resposta.ok || !dados.sucesso) {
                throw new Error(
                    dados.erro || 'Não foi possível estornar o pagamento.'
                );
            }

            alert('Pagamento estornado com sucesso!');
            window.location.reload();

        } catch (falha) {
            campoErro.textContent = falha.message;
            campoErro.classList.remove('d-none');

            processandoEstorno = false;
            btnConfirmar.textContent = 'Confirmar estorno';
            btnConfirmar.disabled = false;
        }
    });

});
</script>

</body>

</html>