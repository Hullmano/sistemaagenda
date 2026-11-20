
<?php
// views/barbeiro_financeiro.php

$usuario = exigirLogin();

// Página exclusiva do barbeiro.
if ($usuario['nivel'] !== 'barbeiro') {
    http_response_code(403);
    exit('Esta área é exclusiva do barbeiro.');
}

$db = \Database::getConnection();

$barbeariaId = (int)$usuario['barbearia_id'];
$barbeiroId = (int)$usuario['id'];

// Data selecionada.
$dataSelecionada = $_GET['data'] ?? date('Y-m-d');

if (!is_string($dataSelecionada)) {
    $dataSelecionada = date('Y-m-d');
}

$data = DateTimeImmutable::createFromFormat(
    '!Y-m-d',
    $dataSelecionada
);

if (
    !$data ||
    $data->format('Y-m-d') !== $dataSelecionada
) {
    $dataSelecionada = date('Y-m-d');
}

// Recebimentos associados aos atendimentos do barbeiro.
$stmt = $db->prepare("
    SELECT COALESCE(SUM(p.valor), 0)
    FROM pagamentos p
    JOIN comandas c
        ON c.id = p.comanda_id
    JOIN agendamentos a
        ON a.id = c.agendamento_id
       AND a.barbearia_id = c.barbearia_id
    WHERE c.barbearia_id = ?
      AND a.usuario_id = ?
      AND p.estornado_em IS NULL
      AND p.pago_em >= ?
      AND p.pago_em < DATE_ADD(?, INTERVAL 1 DAY)
");

$stmt->execute([
    $barbeariaId,
    $barbeiroId,
    $dataSelecionada,
    $dataSelecionada
]);

$totalRecebido = (float)$stmt->fetchColumn();

// Saldo pendente dos atendimentos do dia.
$stmt = $db->prepare("
    SELECT COALESCE(
        SUM(
            GREATEST(
                c.valor_total - COALESCE(pg.total_pago, 0),
                0
            )
        ),
        0
    )
    FROM comandas c
    JOIN agendamentos a
        ON a.id = c.agendamento_id
       AND a.barbearia_id = c.barbearia_id
    LEFT JOIN (
        SELECT
            comanda_id,
            SUM(valor) AS total_pago
        FROM pagamentos
        WHERE estornado_em IS NULL
        GROUP BY comanda_id
    ) pg
        ON pg.comanda_id = c.id
    WHERE c.barbearia_id = ?
      AND a.usuario_id = ?
      AND a.data_agendamento = ?
      AND a.status = 'concluido'
      AND c.status = 'aberta'
");

$stmt->execute([
    $barbeariaId,
    $barbeiroId,
    $dataSelecionada
]);

$totalPendente = (float)$stmt->fetchColumn();

// Recebimentos do barbeiro agrupados por forma de pagamento.
$recebimentosPorForma = [
    'pix' => 0.00,
    'dinheiro' => 0.00,
    'debito' => 0.00,
    'credito' => 0.00,
    'outro' => 0.00
];

$stmt = $db->prepare("
    SELECT
        p.forma_pagamento,
        SUM(p.valor) AS total
    FROM pagamentos p
    JOIN comandas c
        ON c.id = p.comanda_id
    JOIN agendamentos a
        ON a.id = c.agendamento_id
       AND a.barbearia_id = c.barbearia_id
    WHERE c.barbearia_id = ?
      AND a.usuario_id = ?
      AND p.estornado_em IS NULL
      AND p.pago_em >= ?
      AND p.pago_em < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY p.forma_pagamento
");

$stmt->execute([
    $barbeariaId,
    $barbeiroId,
    $dataSelecionada,
    $dataSelecionada
]);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
    $forma = $linha['forma_pagamento'];

    if (array_key_exists($forma, $recebimentosPorForma)) {
        $recebimentosPorForma[$forma] = (float)$linha['total'];
    }
}

// Histórico dos recebimentos associados aos atendimentos
// do barbeiro autenticado na data selecionada.
$stmt = $db->prepare("
    SELECT
        p.id AS pagamento_id,
        p.pago_em,
        p.forma_pagamento,
        p.valor,
        c.id AS comanda_id,
        COALESCE(
            NULLIF(a.nome_cliente, ''),
            cl.nome
        ) AS cliente_nome,
        u.nome AS recebido_por
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
      AND a.usuario_id = ?
      AND p.estornado_em IS NULL
      AND p.pago_em >= ?
      AND p.pago_em < DATE_ADD(?, INTERVAL 1 DAY)
    ORDER BY p.pago_em DESC, p.id DESC
");

$stmt->execute([
    $barbeariaId,
    $barbeiroId,
    $dataSelecionada,
    $dataSelecionada
]);

$historicoRecebimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Meu Financeiro</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
</head>

<body class="bg-dark text-white">

<main class="container py-4" style="max-width: 480px;">

    <div class="d-flex justify-content-between
                align-items-center mb-4">

        <h4 class="mb-0">Meu Financeiro</h4>

        <a
            href="/agenda/<?= rawurlencode($slug_filtrado) ?>/admin"
            class="btn btn-outline-light btn-sm"
        >
            Voltar
        </a>

    </div>

    <form method="get" class="mb-4">
        <label for="data" class="form-label">
            Selecionar data
        </label>

        <input
            type="date"
            id="data"
            name="data"
            class="form-control"
            value="<?= htmlspecialchars(
                $dataSelecionada,
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
            onchange="this.form.submit()"
        >
    </form>

    <div class="card bg-success text-white mb-3">
        <div class="card-body">

            <small>Recebido no dia</small>

            <h3 class="mb-0">
                R$ <?= number_format(
                    $totalRecebido,
                    2,
                    ',',
                    '.'
                ) ?>
            </h3>

        </div>
    </div>

    <div class="card bg-secondary text-white mb-3">
        <div class="card-body">

            <small>Pendente dos atendimentos do dia</small>

            <h3 class="mb-0">
                R$ <?= number_format(
                    $totalPendente,
                    2,
                    ',',
                    '.'
                ) ?>
            </h3>

        </div>
    </div>

    <p class="text-secondary small mt-3">
        Recebido: pagamentos registrados na data selecionada,
        referentes aos seus atendimentos.

        Pendente: saldo das comandas abertas de atendimentos
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
    ?>

    <div class="card bg-dark text-white border-secondary">
        <div class="card-body">

            <?php foreach ($recebimentosPorForma as $forma => $valor): ?>

                <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-secondary">
                    <span>
                        <?= htmlspecialchars($nomesFormas[$forma], ENT_QUOTES, 'UTF-8') ?>
                    </span>

                    <strong class="<?= $valor > 0 ? 'text-success' : 'text-secondary' ?>">
                        R$ <?= number_format($valor, 2, ',', '.') ?>
                    </strong>
                </div>

            <?php endforeach; ?>

            <div class="d-flex justify-content-between align-items-center pt-3">
                <strong>Total recebido</strong>

                <strong class="text-success">
                    R$ <?= number_format($totalRecebido, 2, ',', '.') ?>
                </strong>
            </div>

        </div>
    </div>

</section>


<!-- Histórico individual de recebimentos -->
<section class="mt-4 mb-4">

    <h5 class="mb-3">Histórico de recebimentos</h5>

    <?php if (empty($historicoRecebimentos)): ?>

        <div class="card bg-dark text-white border-secondary">
            <div class="card-body text-center py-4">
                <p class="text-secondary mb-0">
                    Nenhum recebimento registrado nesta data.
                </p>
            </div>
        </div>

    <?php else: ?>

        <?php foreach ($historicoRecebimentos as $pagamento): ?>

            <div class="card bg-dark text-white border-secondary mb-3">
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

                            <span class="small text-end">
                                <?= htmlspecialchars(
                                    $nomesFormas[$pagamento['forma_pagamento']]
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
                                    $pagamento['recebido_por'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>
                        </div>

                    </div>

                </div>
            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</section>

</main>

</body>
</html>
