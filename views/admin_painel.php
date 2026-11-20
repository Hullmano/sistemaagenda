<?php
// views/admin_painel.php

$usuario = exigirLogin();
$db = \Database::getConnection();

// Data selecionada
$data_selecionada = isset($_GET['data'])
    ? trim($_GET['data'])
    : date('Y-m-d');

// Valida o formato da data
$data_valida = DateTimeImmutable::createFromFormat(
    '!Y-m-d',
    $data_selecionada
);

if (
    !$data_valida ||
    $data_valida->format('Y-m-d') !== $data_selecionada
) {
    $data_selecionada = date('Y-m-d');
}

// Parâmetros da consulta
$parametros = [
    (int) $barbearia_id,
    $data_selecionada
];

$filtro_profissional = '';

// Barbeiro visualiza apenas seus agendamentos
if ($usuario['nivel'] === 'barbeiro') {
    $filtro_profissional = ' AND a.usuario_id = ?';
    $parametros[] = (int) $usuario['id'];
}

$stmt = $db->prepare("
    SELECT
        a.id,
        a.servico_id,
        a.horario_inicio,
        co.id AS comanda_id,
        co.status AS comanda_status,
        co.valor_total AS comanda_valor,
        COALESCE(NULLIF(a.nome_cliente, ''), c.nome) AS cliente_nome,
        c.whatsapp AS cliente_whats,
        s.nome AS servico_nome,
        s.preco,
        a.status
    FROM agendamentos a
    JOIN clientes c ON a.cliente_id = c.id
    JOIN servicos s ON a.servico_id = s.id
    LEFT JOIN comandas co ON co.agendamento_id = a.id AND co.barbearia_id = a.barbearia_id
    WHERE a.barbearia_id = ?
      AND a.data_agendamento = ?
      $filtro_profissional
    ORDER BY a.horario_inicio ASC
");

$stmt->execute($parametros);
$agendamentos_hoje = $stmt->fetchAll();

// Serviços ativos disponíveis para adicionar às comandas.
$stmt = $db->prepare("
    SELECT id, nome, preco
    FROM servicos
    WHERE barbearia_id = ?
      AND ativo = 1
    ORDER BY nome ASC
");

$stmt->execute([
    (int)$usuario['barbearia_id']
]);

$servicosAdicionais = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Carrega os itens das comandas exibidas no painel.
$itensPorComanda = [];

$idsComandas = array_values(array_unique(array_map(
    'intval',
    array_filter(
        array_column($agendamentos_hoje, 'comanda_id'),
        static fn($id) => $id !== null
    )
)));

if (!empty($idsComandas)) {
    $marcadores = implode(',', array_fill(0, count($idsComandas), '?'));

    $stmt = $db->prepare("
        SELECT
            ci.comanda_id,
            ci.descricao,
            ci.quantidade,
            ci.valor_total
        FROM comanda_itens ci
        JOIN comandas co
            ON co.id = ci.comanda_id
        JOIN agendamentos a
            ON a.id = co.agendamento_id
        AND a.barbearia_id = co.barbearia_id
        WHERE co.barbearia_id = ?
        AND ci.comanda_id IN ($marcadores)
        ORDER BY ci.id ASC
    ");

    $stmt->execute(array_merge(
        [(int)$usuario['barbearia_id']],
        $idsComandas
    ));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $itensPorComanda[(int)$item['comanda_id']][] = $item;
    }
}

$total_cortes = count($agendamentos_hoje);


$faturamento_previsto = 0;
$producao_realizada = 0;
$total_recebido_dono = 0;

if ($usuario['nivel'] === 'dono') {

    // Previsão: serviços originalmente agendados.
    foreach ($agendamentos_hoje as $ag) {
        if (!in_array(
            $ag['status'],
            ['cancelado', 'nao_compareceu'],
            true
        )) {
            $faturamento_previsto += (float)$ag['preco'];
        }
    }

    // Produção: valor das comandas de atendimentos
    // concluídos na data selecionada.
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(co.valor_total), 0)
        FROM comandas co
        JOIN agendamentos a
            ON a.id = co.agendamento_id
           AND a.barbearia_id = co.barbearia_id
        WHERE co.barbearia_id = ?
          AND a.data_agendamento = ?
          AND a.status = 'concluido'
          AND co.status IN ('aberta', 'fechada')
    ");

    $stmt->execute([
        (int)$usuario['barbearia_id'],
        $data_selecionada
    ]);

    $producao_realizada = (float)$stmt->fetchColumn();

    // Recebido: pagamentos registrados na data selecionada,
    // independentemente da data do atendimento.
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(p.valor), 0)
        FROM pagamentos p
        JOIN comandas co
            ON co.id = p.comanda_id
        WHERE co.barbearia_id = ?
          AND p.estornado_em IS NULL
          AND p.pago_em >= ?
          AND p.pago_em < DATE_ADD(?, INTERVAL 1 DAY)
    ");

    $stmt->execute([
        (int)$usuario['barbearia_id'],
        $data_selecionada,
        $data_selecionada
    ]);

    $total_recebido_dono = (float)$stmt->fetchColumn();
}


// Indicadores financeiros individuais do barbeiro.
// Recebido: pagamentos na data selecionada.
// Pendente: comandas de atendimentos concluídos na data selecionada.

$recebido_barbeiro = 0.00;
$pendente_barbeiro = 0.00;

if ($usuario['nivel'] === 'barbeiro') {

    // Total recebido pelos atendimentos deste profissional.
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(p.valor), 0)
        FROM pagamentos p
        JOIN comandas co
            ON co.id = p.comanda_id
        JOIN agendamentos a
            ON a.id = co.agendamento_id
           AND a.barbearia_id = co.barbearia_id
        WHERE co.barbearia_id = ?
          AND a.usuario_id = ?
          AND p.estornado_em IS NULL
          AND p.pago_em >= ?
          AND p.pago_em < DATE_ADD(?, INTERVAL 1 DAY)
    ");

    $stmt->execute([
        (int)$usuario['barbearia_id'],
        (int)$usuario['id'],
        $data_selecionada,
        $data_selecionada
    ]);

    $recebido_barbeiro = (float)$stmt->fetchColumn();

    // Saldo das comandas abertas dos atendimentos
    // concluídos pelo barbeiro na data selecionada.
    $stmt = $db->prepare("
        SELECT COALESCE(
            SUM(
                GREATEST(
                    co.valor_total - COALESCE(pg.total_pago, 0),
                    0
                )
            ),
            0
        )
        FROM comandas co
        JOIN agendamentos a
            ON a.id = co.agendamento_id
           AND a.barbearia_id = co.barbearia_id
        LEFT JOIN (
            SELECT
                comanda_id,
                SUM(valor) AS total_pago
            FROM pagamentos
            WHERE estornado_em IS NULL
            GROUP BY comanda_id
        ) pg
            ON pg.comanda_id = co.id
        WHERE co.barbearia_id = ?
          AND a.usuario_id = ?
          AND a.data_agendamento = ?
          AND a.status = 'concluido'
          AND co.status = 'aberta'
    ");

    $stmt->execute([
        (int)$usuario['barbearia_id'],
        (int)$usuario['id'],
        $data_selecionada
    ]);

    $pendente_barbeiro = (float)$stmt->fetchColumn();
}

?>


<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Admin - <?php echo htmlspecialchars($barbearia_nome); ?></title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        :root {
            --bg-main: #0B0F19;
            --bg-card: #151B2C;
            --border-color: #222B45;
            --text-primary: #F4F6F9;
            --text-secondary: #8F9BB3;
            --accent: #FF9F43;
        }
        body { 
            background-color: var(--bg-main); 
            color: var(--text-primary);
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .navbar-custom {
            background-color: var(--bg-card);
            border-b: 1px solid var(--border-color);
        }
        .card-stats {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 16px;
        }
        .agenda-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            transition: transform 0.2s;
        }
        .agenda-card:active {
            transform: scale(0.98);
        }
        .time-badge {
            background: rgba(255, 159, 67, 0.15);
            color: var(--accent);
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
        }
        .btn-action {
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.8rem;
            padding: 8px 12px;
        }
        .btn-whatsapp {
            background-color: #25D366;
            color: white;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: opacity 0.2s;
        }
        .btn-whatsapp:hover { color: white; opacity: 0.9; }
    </style>
</head>
<body>

    <!-- Navbar Minimalista Premium -->
    <nav class="navbar navbar-custom sticky-top py-3 shadow-sm">
        <div class="container-fluid max-w-md mx-auto d-flex justify-content-between align-items-center" style="max-width: 480px;">
            <span class="navbar-brand fw-extrabold m-0 text-white" style="letter-spacing: -0.5px;">
                <i class="fa-solid fa-scissors text-warning me-2"></i><?php echo htmlspecialchars($barbearia_nome); ?>
            </span>
            <!-- Menu de Ações do Usuário -->
            <div class="d-flex align-items-center gap-2">

                <span class="badge bg-dark border border-secondary text-secondary rounded-pill px-2 py-1">
                    <?= htmlspecialchars($usuario['nome'], ENT_QUOTES, 'UTF-8') ?>
                </span>

                <form method="post" action="/agenda/api/logout.php" class="m-0">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(gerarTokenCsrf(), ENT_QUOTES, 'UTF-8') ?>"
                    >

                    <button
                        type="submit"
                        class="btn btn-sm btn-outline-danger"
                        title="Sair do sistema"
                    >
                        <i class="fa-solid fa-right-from-bracket"></i>
                        <span class="d-none d-sm-inline ms-1">Sair</span>
                    </button>

                </form>

            </div>

        </div>
    </nav>

    <!-- Container Otimizado para o Celular do Barbeiro -->
    <main class="container py-4" style="max-width: 480px;">
        
        <!-- Cabeçalho Dinâmico Com Navegação de Datas -->
        <div class="card p-3 mb-4 shadow-sm border border-secondary" style="background-color: var(--bg-card); --bs-border-opacity: .15; border-radius: 16px;">
            <div class="row g-2 align-items-center">
                <div class="col-12">
                    <label class="text-secondary fw-bold text-uppercase d-block mb-1" style="font-size: 0.65rem; letter-spacing: 0.5px;">Visualizar Data</label>
                    <!-- O id="filtro-data-painel" é monitorado pelo JavaScript abaixo -->
                    <input type="date" id="filtro-data-painel" class="form-control input-custom py-2 fw-bold text-warning" value="<?php echo $data_selecionada; ?>" style="background-color: var(--bg-main) !important; border: 1px solid var(--border-color) !important; color: #FF9F43 !important; border-radius: 10px; font-size: 0.9rem;">
                </div>
                <div class="col-12 d-flex flex-wrap justify-content-end gap-2 mt-2">
                    <?php if ($usuario['nivel'] === 'dono'): ?>
                        <a
                            href="/agenda/<?= rawurlencode($slug_filtrado) ?>/admin/financeiro"
                            class="btn btn-sm btn-dark border-secondary text-success rounded-3 p-2 text-decoration-none d-flex align-items-center"
                            title="Financeiro"
                            aria-label="Financeiro"
                        >
                            <i class="fa-solid fa-chart-line"></i>
                        </a>
                    <?php endif; ?>
                    <?php if ($usuario['nivel'] === 'barbeiro'): ?>
                        <a
                            href="/agenda/<?= rawurlencode($slug_filtrado) ?>/admin/meu-financeiro"
                            class="btn btn-sm btn-dark border-secondary text-success rounded-3 p-2 text-decoration-none d-flex align-items-center"
                            title="Meu Financeiro"
                            aria-label="Meu Financeiro"
                        >
                            <i class="fa-solid fa-wallet"></i>
                        </a>
                    <?php endif; ?>
                    <?php if ($usuario['nivel'] === 'dono'): ?>
                        <a href="/agenda/<?= rawurlencode($slug_filtrado) ?>/admin/clientes" class="btn btn-sm btn-dark border-secondary text-white rounded-3 p-2 text-decoration-none d-flex align-items-center" title="Clientes e Histórico" aria-label="Clientes e Histórico">
                            <i class="fa-solid fa-users"></i>
                        </a>
                        <a href="/agenda/<?php echo $slug_filtrado; ?>/admin/servicos" class="btn btn-sm btn-dark border-secondary text-white rounded-3 p-2 text-decoration-none d-flex align-items-center" title="Catálogo de Serviços">
                            <i class="fa-solid fa-tags"></i>
                        </a>
                        <a href="/agenda/<?php echo $slug_filtrado; ?>/admin/horarios" class="btn btn-sm btn-dark border-secondary text-white rounded-3 p-2 text-decoration-none d-flex align-items-center" title="Horários de Trabalho">
                            <i class="fa-solid fa-clock"></i>
                        </a>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-dark border-secondary text-white rounded-3 p-2" onclick="window.location.reload();" title="Atualizar Lista">
                        <i class="fa-solid fa-arrows-rotate"></i>
                    </button>
                    <?php if ($usuario['nivel'] === 'dono'): ?>
                        <a href="/agenda/<?php echo $slug_filtrado; ?>/admin/whatsapp" class="btn btn-sm btn-dark border-secondary text-success rounded-3 p-2 text-decoration-none d-flex align-items-center" title="Configurar WhatsApp">
                            <i class="fa-brands fa-whatsapp fs-6"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>


        <!-- Dashboard Cards (Estilo FinTech) -->
        <div class="row g-3 mb-4">
            <div class="col-6">
                <div class="card-stats shadow-sm">
                    <span class="text-secondary d-block mb-1 text-uppercase fw-bold" style="font-size: 0.65rem; letter-spacing: 0.5px;">Agendamentos</span>
                    <h3 class="fw-extrabold m-0 text-white"><?php echo $total_cortes; ?> <small class="fs-6 text-muted fw-normal">atendimentos</small></h3>
                </div>
            </div>
            <div class="col-6">
                <div class="card-stats shadow-sm">

                    <?php if ($usuario['nivel'] === 'dono'): ?>

                        <span class="text-secondary d-block mb-1 text-uppercase fw-bold"
                            style="font-size: 0.65rem; letter-spacing: 0.5px;">
                            Faturamento previsto
                        </span>

                        <h3 class="fw-extrabold m-0 text-success">
                            R$ <?= number_format($faturamento_previsto, 2, ',', '.') ?>
                        </h3>

                    <?php else: ?>

                        <span class="text-secondary d-block mb-1 text-uppercase fw-bold"
                            style="font-size: 0.65rem; letter-spacing: 0.5px;">
                            Recebido no dia
                        </span>

                        <h3 class="fw-extrabold m-0 text-success">
                            R$ <?= number_format($recebido_barbeiro, 2, ',', '.') ?>
                        </h3>

                    <?php endif; ?>

                </div>
            </div>

            <?php if ($usuario['nivel'] === 'dono'): ?>

                <div class="col-6">
                    <div class="card-stats shadow-sm">
                        <span class="text-secondary d-block mb-1 text-uppercase fw-bold"
                            style="font-size: 0.65rem; letter-spacing: 0.5px;">
                            Produção realizada
                        </span>

                        <h3 class="fw-extrabold m-0 text-white">
                            R$ <?= number_format($producao_realizada, 2, ',', '.') ?>
                        </h3>
                    </div>
                </div>

                <div class="col-6">
                    <div class="card-stats shadow-sm">
                        <span class="text-secondary d-block mb-1 text-uppercase fw-bold"
                            style="font-size: 0.65rem; letter-spacing: 0.5px;">
                            Recebido no dia
                        </span>

                        <h3 class="fw-extrabold m-0 text-success">
                            R$ <?= number_format($total_recebido_dono, 2, ',', '.') ?>
                        </h3>
                    </div>
                </div>

            <?php endif; ?>

            <?php if ($usuario['nivel'] === 'barbeiro'): ?>
                <div class="col-12">
                    <div class="card-stats shadow-sm">
                        <span class="text-secondary d-block mb-1 text-uppercase fw-bold"
                            style="font-size: 0.65rem; letter-spacing: 0.5px;">
                            Pendente dos atendimentos do dia
                        </span>

                        <h3 class="fw-extrabold m-0 text-warning">
                            R$ <?= number_format($pendente_barbeiro, 2, ',', '.') ?>
                        </h3>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Feed/Linha do Tempo dos Clientes -->
        <div class="space-y-3">
            <h6 class="text-secondary text-uppercase fw-bold mb-3" style="font-size: 0.7rem; letter-spacing: 1px;">Próximos Clientes</h6>
            
            <?php if(empty($agendamentos_hoje)): ?>
                <div class="text-center p-5 border rounded-4 border-dashed border-secondary opacity-50">
                    <i class="fa-regular fa-clock fs-1 mb-3 text-secondary"></i>
                    <p class="m-0 small">Nenhum cliente agendado para hoje.</p>
                </div>
            <?php else: ?>
                <?php foreach($agendamentos_hoje as $ag): ?>
                    <div class="agenda-card p-3 mb-3 shadow-sm">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="time-badge">
                                <i class="fa-regular fa-clock me-1"></i><?php echo date('H:i', strtotime($ag['horario_inicio'])); ?>
                            </span>
                            <?php
                            $status_badge = 'bg-secondary text-dark';
                            if($ag['status'] === 'agendado') $status_badge = 'bg-warning text-dark fw-bold';
                            if($ag['status'] === 'concluido') $status_badge = 'bg-success text-white fw-bold';
                            if($ag['status'] === 'nao_compareceu') $status_badge = 'bg-danger text-white fw-bold';
                            ?>
                            <span class="badge rounded-pill <?php echo $status_badge; ?> text-uppercase" style="font-size: 0.65rem; padding: 5px 10px;">
                                <?php echo $ag['status'] === 'nao_compareceu' ? 'Faltou' : $ag['status']; ?>
                            </span>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="fw-bold m-0 text-white mb-1 fs-5">
                                    <?= htmlspecialchars($ag['cliente_nome'], ENT_QUOTES, 'UTF-8') ?>
                                </h6>

                                <?php
                                $comandaIdAtual = (int)($ag['comanda_id'] ?? 0);
                                $itensComandaAtual = $itensPorComanda[$comandaIdAtual] ?? [];

                                $mostrarItensComanda =
                                    $ag['status'] === 'concluido' &&
                                    $comandaIdAtual > 0 &&
                                    !empty($itensComandaAtual);
                                ?>

                                <?php if ($mostrarItensComanda): ?>

                                    <div class="mt-2">
                                        <span class="text-secondary small d-block mb-2">
                                            Serviços realizados
                                        </span>

                                        <?php foreach ($itensComandaAtual as $item): ?>

                                            <div class="d-flex justify-content-between align-items-start gap-3 mb-1">
                                                <span class="text-secondary small">
                                                    <i class="fa-solid fa-scissors me-1 text-warning"></i>
                                                    <?= htmlspecialchars(
                                                        $item['descricao'],
                                                        ENT_QUOTES,
                                                        'UTF-8'
                                                    ) ?>

                                                    <?php if ((float)$item['quantidade'] != 1): ?>
                                                        (<?= number_format(
                                                            (float)$item['quantidade'],
                                                            2,
                                                            ',',
                                                            '.'
                                                        ) ?>x)
                                                    <?php endif; ?>
                                                </span>

                                                <span class="text-white fw-bold small text-nowrap">
                                                    R$ <?= number_format(
                                                        (float)$item['valor_total'],
                                                        2,
                                                        ',',
                                                        '.'
                                                    ) ?>
                                                </span>
                                            </div>

                                        <?php endforeach; ?>
                                    </div>

                                <?php else: ?>

                                    <p class="text-secondary small m-0">
                                        <i class="fa-solid fa-mustache me-1 text-warning"></i>

                                        <?= htmlspecialchars(
                                            $ag['servico_nome'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                        <span class="text-white fw-bold ms-2">
                                            R$ <?= number_format(
                                                (float)$ag['preco'],
                                                2,
                                                ',',
                                                '.'
                                            ) ?>
                                        </span>
                                    </p>

                                <?php endif; ?>
                            </div>
                            <a href="https://wa.me<?php echo $ag['cliente_whats']; ?>" target="_blank" class="btn-whatsapp">
                                <i class="fa-brands fa-whatsapp fs-5"></i> 
                            </a>
                        </div>

                        <!-- Painel de Ações Inteligentes rápidas -->
                        <?php if($ag['status'] === 'agendado'): ?>
                            <div class="d-flex gap-2 mt-3 pt-3 border-top border-secondary" style="--bs-border-opacity: .2;" id="acoes-<?php echo $ag['id']; ?>">
                                <!-- Procure o local dos botões de ação e substitua por este trio: -->
                                <button class="btn btn-success btn-action flex-grow-1 btn-mudar-status" data-id="<?php echo $ag['id']; ?>" data-status="concluido">
                                    <i class="fa-solid fa-check me-1"></i> Concluir
                                </button>
                                <button class="btn btn-outline-danger btn-action btn-mudar-status" data-id="<?php echo $ag['id']; ?>" data-status="nao_compareceu" title="Faltou">
                                    <i class="fa-solid fa-user-slash"></i>
                                </button>
                                <!-- NOVO BOTÃO REAGENDAR -->
                                <button class="btn btn-outline-warning btn-action btn-abrir-reagendar" data-id="<?php echo $ag['id']; ?>" data-servicoid="<?php echo $ag['servico_id']; ?>" title="Mudar Horário">
                                    <i class="fa-regular fa-calendar-plus"></i>
                                </button>
                                <button class="btn btn-dark border-secondary btn-action text-danger btn-mudar-status" data-id="<?php echo $ag['id']; ?>" data-status="cancelado" title="Cancelar Horário">
                                    <i class="fa-solid fa-trash-can"></i>
                                </button>
                            </div>
                        <?php endif; ?>
                        <!-- Se o agendamento estiver concluído e houver uma comanda aberta, exibe o botão de pagamento -->
                        <?php if (
                            $ag['status'] === 'concluido' &&
                            $ag['comanda_id'] !== null &&
                            $ag['comanda_status'] === 'aberta'
                        ): ?>
                            <div class="mt-3 pt-3 border-top border-secondary"
                                style="--bs-border-opacity: .2;">

                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small text-secondary">
                                        Total da comanda
                                    </span>

                                    <span class="fw-bold text-success">
                                        R$ <?= number_format(
                                            (float)$ag['comanda_valor'],
                                            2,
                                            ',',
                                            '.'
                                        ) ?>
                                    </span>
                                </div>

                                <button
                                    type="button"
                                    class="btn btn-warning w-100 fw-bold btn-receber"
                                    data-comanda-id="<?= (int)$ag['comanda_id'] ?>"
                                    data-cliente="<?= htmlspecialchars(
                                        $ag['cliente_nome'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    data-valor="<?= htmlspecialchars(
                                        $ag['comanda_valor'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                >
                                    <i class="fa-solid fa-wallet me-2"></i>
                                    Receber Pagamento
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline-info w-100 fw-bold mt-2 btn-adicionar-servico"
                                    data-comanda-id="<?= (int)$ag['comanda_id'] ?>"
                                    data-cliente="<?= htmlspecialchars(
                                        $ag['cliente_nome'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                >
                                    <i class="fa-solid fa-plus me-2"></i>
                                    Adicionar Serviço
                                </button>

                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Modal de Reagendamento -->
        <div class="modal fade" id="modalReagendar" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mx-auto px-3" style="max-width: 400px;">
                <div class="modal-content modal-custom p-3" style="background-color: var(--bg-card); border: 1px solid var(--border-color); color: white; border-radius: 20px;">
                    <div class="modal-header border-0 p-0 mb-3">
                        <h5 class="modal-title fw-bold text-warning"><i class="fa-regular fa-calendar-days me-2"></i>Alterar Horário</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <input type="hidden" id="reagendar-id">
                    <input type="hidden" id="reagendar-servicoid">
                    
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Selecione a Nova Data</label>

                        <div id="calendario-reagendar">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <button type="button" id="reagendar-anterior"
                                        class="btn btn-outline-secondary btn-sm">
                                    <i class="fa-solid fa-chevron-left"></i>
                                </button>

                                <span id="reagendar-periodo"
                                    class="small fw-bold text-white"></span>

                                <button type="button" id="reagendar-proximo"
                                        class="btn btn-outline-secondary btn-sm">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </button>
                            </div>

                            <div id="reagendar-dias"
                                class="row row-cols-5 g-2 text-center">
                            </div>
                        </div>

                        <input type="hidden"
                            id="reagendar-data"
                            value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Horários Disponíveis</label>
                        <div id="reagendar-container-horarios" class="grid grid-cols-4 gap-2 d-flex flex-wrap">
                            <!-- Injetado via AJAX -->
                        </div>
                    </div>
                    <button id="btn-confirmar-reagendamento" class="btn btn-warning w-100 py-2 fw-bold text-dark rounded-3 shadow mt-2">Confirmar Novo Horário</button>
                </div>
            </div>
        </div>


    </main>

    <!-- Modal de serviço adicional -->
    <div class="modal fade"
        id="modalAdicionarServico"
        tabindex="-1"
        data-bs-theme="dark"
        aria-labelledby="modalAdicionarServicoTitulo"
        aria-hidden="true">

        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-white border-secondary">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalAdicionarServicoTitulo">
                        Adicionar serviço
                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Fechar">
                    </button>
                </div>

                <div class="modal-body">

                    <p class="text-secondary small mb-1">Cliente</p>
                    <h6 id="adicionarServicoCliente" class="mb-3"></h6>

                    <label for="adicionarServicoId" class="form-label">
                        Selecione o serviço adicional
                    </label>

                    <select id="adicionarServicoId" class="form-select">
                        <option value="">Selecione um serviço</option>

                        <?php foreach ($servicosAdicionais as $servico): ?>
                            <option value="<?= (int)$servico['id'] ?>">
                                <?= htmlspecialchars(
                                    $servico['nome'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                                — R$ <?= number_format(
                                    (float)$servico['preco'],
                                    2,
                                    ',',
                                    '.'
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div id="adicionarServicoErro"
                        class="alert alert-danger mt-3 d-none"
                        role="alert">
                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">
                        Cancelar
                    </button>

                    <button type="button"
                            class="btn btn-info fw-bold"
                            id="btnConfirmarAdicionarServico"
                            disabled>
                        Adicionar serviço
                    </button>

                </div>

            </div>
        </div>
    </div>

    <!-- Modal de recebimento -->
    <div class="modal fade" id="modalReceber" tabindex="-1" data-bs-theme="dark"
        aria-labelledby="modalReceberTitulo" aria-hidden="true">

        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark text-white border-secondary">

                <div class="modal-header">
                    <h5 class="modal-title" id="modalReceberTitulo">
                        Receber pagamento
                    </h5>
                    <button type="button" class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Fechar"></button>
                </div>

                <div class="modal-body">
                    <p class="mb-1 text-secondary small">Cliente</p>
                    <h6 id="receberCliente" class="mb-3"></h6>

                    <p class="mb-1 text-secondary small">Valor a receber</p>
                    <h3 id="receberValor" class="text-success mb-4"></h3>

                    <label for="receberForma" class="form-label">
                        Forma de pagamento
                    </label>

                    <select id="receberForma" class="form-select">
                        <option value="pix">Pix</option>
                        <option value="dinheiro">Dinheiro</option>
                        <option value="debito">Cartão de débito</option>
                        <option value="credito">Cartão de crédito</option>
                        <option value="outro">Outro</option>
                    </select>

                    <div id="receberErro"
                        class="alert alert-danger mt-3 d-none"
                        role="alert"></div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                            data-bs-dismiss="modal">
                        Cancelar
                    </button>

                    <button type="button" class="btn btn-success"
                            id="btnConfirmarRecebimento" disabled>
                        Confirmar recebimento
                    </button>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const csrfToken = <?= json_encode(
            gerarTokenCsrf(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?>;

        // --- SCRIPT DE NAVEGAÇÃO DE DATAS NO PAINEL ---
        const filtroDataPainel = document.getElementById('filtro-data-painel');
        filtroDataPainel.addEventListener('change', function() {
            const dataEscolhida = this.value;
            // Redireciona a página passando a nova data na URL via GET
            window.location.href = '?data=' + dataEscolhida;
        });


        document.addEventListener("DOMContentLoaded", function() {
            // Escuta cliques em qualquer botão que possua a classe de mudança de status [1]
            document.querySelectorAll('.btn-mudar-status').forEach(botao => {
                botao.addEventListener('click', function() {
                    const agendamentoId = this.dataset.id;
                    const novoStatus = this.dataset.status;

                    // Dicionário de tradução sênior para exibir o texto exato na tela
                    const textosStatus = {
                        'concluido': 'Concluído',
                        'nao_compareceu': 'Faltou',
                        'cancelado': 'Cancelado'
                    };

                    const statusTraduzido = textosStatus[novoStatus] || novoStatus;

                    // Mensagem dinâmica e precisa baseada no botão clicado
                    if (!confirm(`Deseja realmente alterar o status deste agendamento para: ${statusTraduzido}?`)) {
                        return;
                    }

                    // Desabilita os botões do card para evitar múltiplos cliques [1]
                    const containerAcoes = document.getElementById(`acoes-${agendamentoId}`);
                    if (containerAcoes) {
                        containerAcoes.querySelectorAll('button').forEach(b => b.disabled = true);
                    }

                    // Envia a requisição AJAX via POST para a API [1]
                    // No dropet do fetch, usamos o caminho relativo correto para a API
                    fetch('/agenda/api/atualizar_status.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({ id: agendamentoId, status: novoStatus })
                    })
                    .then(response => response.json())
                    .then(res => {
                        if (res.sucesso) {
                            // Recarrega a página rapidamente para atualizar os contadores de faturamento e badges [1]
                            window.location.reload();
                        } else {
                            alert('Erro: ' + res.erro);
                            if (containerAcoes) {
                                containerAcoes.querySelectorAll('button').forEach(b => b.disabled = false);
                            }
                        }
                    })
                    .catch(erro => {
                        console.error(erro);
                        alert('Erro de comunicação com o servidor.');
                        if (containerAcoes) {
                            containerAcoes.querySelectorAll('button').forEach(b => b.disabled = false);
                        }
                    });
                });
            });
        });

        // --- LÓGICA DE REAGENDAMENTO (AJAX) ---
        const modalReagendarEl = new bootstrap.Modal(document.getElementById('modalReagendar'));
        const campoReagendarId = document.getElementById('reagendar-id');
        const campoReagendarServicoId = document.getElementById('reagendar-servicoid');
        const campoReagendarData = document.getElementById('reagendar-data');
        const containerReagendarHorarios = document.getElementById('reagendar-container-horarios');
        const btnConfirmarReagendamento = document.getElementById('btn-confirmar-reagendamento');
        let horarioSelecionadoFinal = '';
        // Calendário compacto de reagendamento:
        // 5 dias por página, limite de 30 dias incluindo hoje.
        const diasReagendar = document.getElementById('reagendar-dias');
        const periodoReagendar = document.getElementById('reagendar-periodo');
        const btnReagendarAnterior = document.getElementById('reagendar-anterior');
        const btnReagendarProximo = document.getElementById('reagendar-proximo');

        const LIMITE_REAGENDAR = 30;
        let paginaReagendar = 0;

        const hojeReagendar = new Date();
        hojeReagendar.setHours(12, 0, 0, 0);

        const nomesDiasReagendar = [
            'DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB'
        ];

        function dataReagendarPorIndice(indice) {
            const data = new Date(hojeReagendar);
            data.setDate(hojeReagendar.getDate() + indice);
            return data;
        }

        function formatarDataReagendar(data) {
            const ano = data.getFullYear();
            const mes = String(data.getMonth() + 1).padStart(2, '0');
            const dia = String(data.getDate()).padStart(2, '0');

            return `${ano}-${mes}-${dia}`;
        }

        function renderizarCalendarioReagendar() {
            diasReagendar.innerHTML = '';

            const ultimoIndice = Math.min(
                paginaReagendar + 4,
                LIMITE_REAGENDAR - 1
            );

            for (let i = paginaReagendar; i <= ultimoIndice; i++) {
                const data = dataReagendarPorIndice(i);
                const valor = formatarDataReagendar(data);

                const coluna = document.createElement('div');
                coluna.className = 'col';

                const botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'btn btn-outline-light w-100 p-1';
                botao.style.fontSize = '0.7rem';

                botao.innerHTML = `
                    <small class="d-block">${nomesDiasReagendar[data.getDay()]}</small>
                    <strong class="d-block">${data.getDate()}</strong>
                    <small>${String(data.getMonth() + 1).padStart(2, '0')}</small>
                `;

                if (campoReagendarData.value === valor) {
                    botao.classList.remove('btn-outline-light');
                    botao.classList.add('btn-warning', 'text-dark');
                }

                botao.addEventListener('click', function() {
                    campoReagendarData.value = valor;

                    renderizarCalendarioReagendar();

                    // Consulta os horários para a nova data.
                    buscarHorariosReagendamento();
                });

                coluna.appendChild(botao);
                diasReagendar.appendChild(coluna);
            }

            const primeira = dataReagendarPorIndice(paginaReagendar);
            const ultima = dataReagendarPorIndice(ultimoIndice);

            periodoReagendar.textContent =
                `${primeira.toLocaleDateString('pt-BR')} a ${ultima.toLocaleDateString('pt-BR')}`;

            btnReagendarAnterior.disabled = paginaReagendar === 0;
            btnReagendarProximo.disabled =
                paginaReagendar + 5 >= LIMITE_REAGENDAR;
        }

        btnReagendarAnterior.addEventListener('click', function() {
            paginaReagendar = Math.max(0, paginaReagendar - 5);
            renderizarCalendarioReagendar();
        });

        btnReagendarProximo.addEventListener('click', function() {
            if (paginaReagendar + 5 < LIMITE_REAGENDAR) {
                paginaReagendar += 5;
                renderizarCalendarioReagendar();
            }
        });

        campoReagendarData.value =
            formatarDataReagendar(hojeReagendar);

        renderizarCalendarioReagendar();


        // Captura cliques nos botões de abrir o modal
        document.querySelectorAll('.btn-abrir-reagendar').forEach(btn => {
            btn.addEventListener('click', function() {
                campoReagendarId.value = this.dataset.id;
                campoReagendarServicoId.value = this.dataset.servicoid;
                modalReagendarEl.show();
                buscarHorariosReagendamento();
            });
        });

        // Atualiza horários se mudar a data no modal
        campoReagendarData.addEventListener('change', buscarHorariosReagendamento);

        function buscarHorariosReagendamento() {
            const agendamentoId = campoReagendarId.value;
            const servicoId = campoReagendarServicoId.value;
            const dataSel = campoReagendarData.value;

            // Captura o ID da barbearia direto do seu escopo PHP com segurança
            const bId = "<?php echo $barbearia_id; ?>";

            // Mostra o carregando centralizado dentro do container
            containerReagendarHorarios.innerHTML = '<p class="text-xs text-muted py-2 w-100 text-center"><i class="fa-solid fa-spinner animate-spin me-1"></i> Buscando vagas...</p>';
            horarioSelecionadoFinal = '';

            // URL montada de forma limpa sem escapes que quebram o interpretador

            const urlApi = '/agenda/api/horarios_disponiveis.php'
                + '?barbearia_id=' + encodeURIComponent(bId)
                + '&data=' + encodeURIComponent(dataSel)
                + '&servico_id=' + encodeURIComponent(servicoId)
                + '&agendamento_id=' + encodeURIComponent(agendamentoId);

            fetch(urlApi)
                .then(r => {
                    if (!r.ok) throw new Error('Falha na resposta do servidor');
                    return r.json();
                })
                .then(dados => {
                    containerReagendarHorarios.innerHTML = '';
                    
                    if (dados.erro || !dados.horarios || dados.horarios.length === 0) {
                        containerReagendarHorarios.innerHTML = '<p class="text-xs text-danger py-2 w-100 text-center fw-bold">Sem horários livres para este dia.</p>';
                        return;
                    }

                    // Renderiza os botões de horário usando a estilização do Bootstrap 5 homologada
                    dados.horarios.forEach(horario => {
                        const b = document.createElement('button');
                        b.type = 'button';
                        // Classe utilitária d-inline-block adicionada para forçar o alinhamento
                        b.className = 'btn btn-outline-light btn-sm font-bold m-1 btn-slot-reagendar d-inline-block';
                        b.textContent = horario;
                        b.style.fontSize = '0.75rem';
                        b.style.padding = '6px 10px';
                        
                        b.addEventListener('click', function() {
                            document.querySelectorAll('.btn-slot-reagendar').forEach(btnDom => {
                                btnDom.classList.remove('btn-warning', 'text-dark');
                                btnDom.classList.add('btn-outline-light');
                            });
                            b.classList.remove('btn-outline-light');
                            b.classList.add('btn-warning', 'text-dark');
                            horarioSelecionadoFinal = horario;
                        });
                        containerReagendarHorarios.appendChild(b);
                    });
                })
                .catch(err => {
                    console.error('Erro no fetch de reagendamento:', err);
                    containerReagendarHorarios.innerHTML = '<p class="text-xs text-danger py-2 w-100 text-center">Erro ao processar horários.</p>';
                });
        }


        // Dispara o salvamento do novo horário
        // --- CORREÇÃO DO CLIQUE FINAL DE CONFIRMAÇÃO ---
        btnConfirmarReagendamento.addEventListener('click', function() {
            // Busca na tela o botão de horário que está dourado (ativo/marcado pelo usuário)
            const botaoAtivo = document.querySelector('.btn-slot-reagendar.btn-warning');
            
            if (!botaoAtivo) {
                alert('Por favor, selecione um horário disponível da lista antes de confirmar.');
                return;
            }

            const horarioParaGravar = botaoAtivo.textContent.trim();
            const agendamentoId = campoReagendarId.value;
            const dataDestino = campoReagendarData.value;

            // Desabilita o botão para evitar cliques duplos acidentais
            btnConfirmarReagendamento.disabled = true;
            btnConfirmarReagendamento.textContent = 'Processando...';

            // Dispara a requisição POST via AJAX para o Droplet
            fetch('/agenda/api/reagendar.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    id: agendamentoId,
                    data: dataDestino,
                    horario: horarioParaGravar
                })
            })
            .then(async r => {
                const resposta = await r.json();

                if (!r.ok) {
                    throw new Error(
                        resposta.erro || 'Não foi possível reagendar.'
                    );
                }

                return resposta;
            })
            .then(res => {
                if (res.sucesso) {
                    // Recarrega o dashboard com o cliente movido com sucesso para o novo dia/hora
                    window.location.reload();
                } else {
                    alert('Erro no Servidor: ' + res.erro);
                    btnConfirmarReagendamento.disabled = false;
                    btnConfirmarReagendamento.textContent = 'Confirmar Novo Horário';
                }
            })
            .catch(err => {
                console.error('Erro ao reagendar:', err);
                alert(err.message || 'Erro de comunicação com o servidor.');
                btnConfirmarReagendamento.disabled = false;
                btnConfirmarReagendamento.textContent = 'Confirmar Novo Horário';
            });
        });
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const modalElemento = document.getElementById('modalReceber');
        const btnConfirmar = document.getElementById('btnConfirmarRecebimento');
        const campoForma = document.getElementById('receberForma');
        const campoErro = document.getElementById('receberErro');

        let comandaSelecionada = null;
        let processandoPagamento = false;

        if (!modalElemento) return;

        document.querySelectorAll('.btn-receber').forEach(botao => {
            botao.addEventListener('click', function () {
                const cliente = this.dataset.cliente;
                const valor = Number(this.dataset.valor);
                const comandaId = Number(this.dataset.comandaId);

                if (!Number.isInteger(comandaId) || comandaId <= 0 ||
                    !Number.isFinite(valor) || valor <= 0) {
                    return;
                }

                comandaSelecionada = comandaId;
                btnConfirmar.disabled = false;
                btnConfirmar.textContent = 'Confirmar recebimento';
                campoErro.classList.add('d-none');

                document.getElementById('receberCliente').textContent = cliente;
                document.getElementById('receberValor').textContent =
                    valor.toLocaleString('pt-BR', {
                        style: 'currency',
                        currency: 'BRL'
                    });

                document.getElementById('receberForma').value = 'pix';
                document.getElementById('receberErro').classList.add('d-none');

                bootstrap.Modal.getOrCreateInstance(modalElemento).show();
            });
        });

        btnConfirmar.addEventListener('click', async function () {
            if (processandoPagamento || !comandaSelecionada) return;

            if (!window.confirm('Confirmar o recebimento deste pagamento?')) {
                return;
            }

            processandoPagamento = true;
            btnConfirmar.disabled = true;
            btnConfirmar.textContent = 'Registrando...';
            campoErro.classList.add('d-none');

            try {
                const resposta = await fetch('/agenda/api/registrar_pagamento.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        comanda_id: comandaSelecionada,
                        forma_pagamento: campoForma.value
                    })
                });

                const dados = await resposta.json();

                if (!resposta.ok || !dados.sucesso) {
                    throw new Error(dados.erro || 'Não foi possível registrar o pagamento.');
                }

                alert('Pagamento registrado com sucesso!');
                window.location.reload();

            } catch (erro) {
                campoErro.textContent = erro.message;
                campoErro.classList.remove('d-none');

                btnConfirmar.disabled = false;
                btnConfirmar.textContent = 'Confirmar recebimento';
            } finally {
                processandoPagamento = false;
            }
        });

        // Abre automaticamente o recebimento vindo do Financeiro
        const parametrosUrl = new URLSearchParams(window.location.search);
        const comandaParaReceber = parametrosUrl.get('receber');

        if (comandaParaReceber && /^\d+$/.test(comandaParaReceber)) {

            const botaoReceber = Array.from(
                document.querySelectorAll('.btn-receber')
            ).find(botao => {
                return Number(botao.dataset.comandaId) === Number(comandaParaReceber);
            });

            if (botaoReceber) {
                botaoReceber.click();

                // Remove o parâmetro somente depois de localizar o botão
                parametrosUrl.delete('receber');

                const novaUrl = window.location.pathname +
                    (parametrosUrl.toString() ? '?' + parametrosUrl.toString() : '');

                window.history.replaceState(null, '', novaUrl);
            }
        }
    });
    </script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {

        const modalElemento = document.getElementById('modalAdicionarServico');
        const campoServico = document.getElementById('adicionarServicoId');
        const campoCliente = document.getElementById('adicionarServicoCliente');
        const campoErro = document.getElementById('adicionarServicoErro');
        const btnConfirmar = document.getElementById('btnConfirmarAdicionarServico');

        if (!modalElemento || !window.bootstrap) return;

        const modal = bootstrap.Modal.getOrCreateInstance(modalElemento);

        let comandaSelecionada = null;
        let processando = false;

        // Abre o modal a partir da comanda selecionada.
        document.querySelectorAll('.btn-adicionar-servico').forEach(botao => {
            botao.addEventListener('click', function () {

                const comandaId = Number(this.dataset.comandaId);

                if (!Number.isInteger(comandaId) || comandaId <= 0) {
                    return;
                }

                comandaSelecionada = comandaId;
                campoCliente.textContent = this.dataset.cliente || '';

                campoServico.value = '';
                campoErro.textContent = '';
                campoErro.classList.add('d-none');

                btnConfirmar.disabled = true;
                btnConfirmar.textContent = 'Adicionar serviço';

                modal.show();
            });
        });

        // Habilita a confirmação após selecionar um serviço.
        campoServico.addEventListener('change', function () {
            const servicoId = Number(this.value);

            btnConfirmar.disabled = !(
                Number.isInteger(servicoId) &&
                servicoId > 0 &&
                comandaSelecionada !== null &&
                !processando
            );

            campoErro.classList.add('d-none');
        });

        // Envia o serviço selecionado para a API.
        btnConfirmar.addEventListener('click', async function () {

            if (processando || !comandaSelecionada) return;

            const servicoId = Number(campoServico.value);

            if (!Number.isInteger(servicoId) || servicoId <= 0) {
                return;
            }

            const confirmado = window.confirm(
                'Confirma a inclusão deste serviço na comanda?'
            );

            if (!confirmado) return;

            processando = true;
            btnConfirmar.disabled = true;
            btnConfirmar.textContent = 'Adicionando...';
            campoErro.classList.add('d-none');

            try {
                const resposta = await fetch(
                    '/agenda/api/adicionar_servico_comanda.php',
                    {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({
                            comanda_id: comandaSelecionada,
                            servico_id: servicoId
                        })
                    }
                );

                const dados = await resposta.json();

                if (!resposta.ok || !dados.sucesso) {
                    throw new Error(
                        dados.erro || 'Não foi possível adicionar o serviço.'
                    );
                }

                alert('Serviço adicionado com sucesso!');
                window.location.reload();

            } catch (erro) {
                campoErro.textContent = erro.message;
                campoErro.classList.remove('d-none');

                btnConfirmar.textContent = 'Adicionar serviço';
                btnConfirmar.disabled = false;

            } finally {
                processando = false;
            }
        });

    });
    </script>

</body>
</html>
