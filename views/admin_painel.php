<?php
// views/admin_painel.php
$db = \Database::getConnection();
$data_hoje = date('Y-m-d');

$stmt = $db->prepare("
    SELECT a.id, a.horario_inicio, c.nome AS cliente_nome, c.whatsapp AS cliente_whats, s.nome AS servico_nome, s.preco, a.status 
    FROM agendamentos a
    JOIN clientes c ON a.cliente_id = c.id
    JOIN servicos s ON a.servico_id = s.id
    WHERE a.barbearia_id = ? AND a.data_agendamento = ?
    ORDER BY a.horario_inicio ASC
");
$stmt->execute([$barbearia_id, $data_hoje]);
$agendamentos_hoje = $stmt->fetchAll();

$total_cortes = count($agendamentos_hoje);
$faturamento_previsto = 0;
foreach ($agendamentos_hoje as $ag) {
    if ($ag['status'] !== 'cancelado') {
        $faturamento_previsto += $ag['preco'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel Admin - <?php echo htmlspecialchars($barbearia_nome); ?></title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://jsdelivr.net" rel="stylesheet">
    <!-- Google Fonts & FontAwesome -->
    <link href="https://googleapis.com" rel="stylesheet">
    <link href="https://cloudflare.com" rel="stylesheet">
    
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
            <span class="badge bg-dark border border-secondary text-secondary rounded-pill px-2 py-1" style="font-size: 0.7rem;">PRO</span>
        </div>
    </nav>

    <!-- Container Otimizado para o Celular do Barbeiro -->
    <main class="container py-4" style="max-width: 480px;">
        
        <!-- Cabeçalho Dinâmico -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="fw-bold m-0" style="letter-spacing: -0.5px;">Fluxo de Hoje</h4>
                <p class="text-muted small m-0"><i class="fa-regular fa-calendar me-1"></i><?php echo date('d/m/Y'); ?></p>
            </div>
            <div class="d-flex gap-2">
                <!-- LINK PARA OS SERVIÇOS -->
                <a href="/sistemaagenda/<?php echo $slug_filtrado; ?>/admin/servicos" class="btn btn-sm btn-dark border-secondary text-warning rounded-3 px-3 py-2 text-decoration-none d-flex align-items-center">
                    <i class="fa-solid fa-tags me-1"></i> Serviços
                </a>
                <button class="btn btn-sm btn-dark border-secondary text-white rounded-3 px-3 py-2" onclick="window.location.reload();">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
                <a href="/sistemaagenda/<?php echo $slug_filtrado; ?>/admin/horarios" class="btn btn-sm btn-dark border-secondary text-warning rounded-3 px-3 py-2 text-decoration-none d-flex align-items-center">
                    <i class="fa-solid fa-clock me-1"></i> Horários
                </a>
            </div>
        </div>


        Dashboard Cards (Estilo FinTech)
        <div class="row g-3 mb-4">
            <div class="col-6">
                <div class="card-stats shadow-sm">
                    <span class="text-secondary d-block mb-1 text-uppercase fw-bold" style="font-size: 0.65rem; letter-spacing: 0.5px;">Agendamentos</span>
                    <h3 class="fw-extrabold m-0 text-white"><?php echo $total_cortes; ?> <small class="fs-6 text-muted fw-normal">atendimentos</small></h3>
                </div>
            </div>
            <div class="col-6">
                <div class="card-stats shadow-sm">
                    <span class="text-secondary d-block mb-1 text-uppercase fw-bold" style="font-size: 0.65rem; letter-spacing: 0.5px;">Faturamento</span>
                    <h3 class="fw-extrabold m-0 text-success">R$ <?php echo number_format($faturamento_previsto, 0, ',', '.'); ?></h3>
                </div>
            </div>
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
                                <h6 class="fw-bold m-0 text-white mb-1 fs-5"><?php echo htmlspecialchars($ag['cliente_nome']); ?></h6>
                                <p class="text-secondary small m-0">
                                    <i class="fa-solid fa-mustache me-1 text-warning"></i> <?php echo htmlspecialchars($ag['servico_nome']); ?>
                                    <span class="text-white fw-bold ms-2">R$ <?php echo number_format($ag['preco'], 2, ',', '.'); ?></span>
                                </p>
                            </div>
                            <a href="https://wa.me<?php echo $ag['cliente_whats']; ?>" target="_blank" class="btn-whatsapp">
                                <i class="fa-brands fa-whatsapp fs-5"></i>
                            </a>
                        </div>

                        <!-- Painel de Ações Inteligentes rápidas -->
                        <?php if($ag['status'] === 'agendado'): ?>
                            <div class="d-flex gap-2 mt-3 pt-3 border-top border-secondary" style="--bs-border-opacity: .2;" id="acoes-<?php echo $ag['id']; ?>">
                                <button class="btn btn-success btn-action flex-grow-1 btn-mudar-status" data-id="<?php echo $ag['id']; ?>" data-status="concluido">
                                    <i class="fa-solid fa-check me-1"></i> Concluir
                                </button>
                                <button class="btn btn-outline-danger btn-action btn-mudar-status" data-id="<?php echo $ag['id']; ?>" data-status="nao_compareceu">
                                    <i class="fa-solid fa-user-slash"></i> Faltou
                                </button>
                            </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </main>

    <!-- <script src="https://jsdelivr.net"></script> -->

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Escuta cliques em qualquer botão que possua a classe de mudança de status [1]
            document.querySelectorAll('.btn-mudar-status').forEach(botao => {
                botao.addEventListener('click', function() {
                    const agendamentoId = this.dataset.id;
                    const novoStatus = this.dataset.status;

                    if (!confirm(`Deseja alterar o status para ${novoStatus === 'concluido' ? 'Concluído' : 'Faltou'}?`)) {
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
                        headers: { 'Content-Type': 'application/json' },
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
    </script>

</body>
</html>
