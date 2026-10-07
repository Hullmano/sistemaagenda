<?php
// views/admin_painel.php

$db = \Database::getConnection();

// 1. CAPTURA A DATA DINÂMICA: Se vier via URL (?data=2026-10-07) usa ela, senão usa HOJE
$data_selecionada = isset($_GET['data']) ? trim($_GET['data']) : date('Y-m-d');

// 2. Consulta agendamentos filtrando pela DATA SELECIONADA na barbearia conectada
$stmt = $db->prepare("
    SELECT a.id, a.servico_id, a.horario_inicio, c.nome AS cliente_nome, c.whatsapp AS cliente_whats, s.nome AS servico_nome, s.preco, a.status 
    FROM agendamentos a
    JOIN clientes c ON a.cliente_id = c.id
    JOIN servicos s ON a.servico_id = s.id
    WHERE a.barbearia_id = ? AND a.data_agendamento = ?
    ORDER BY a.horario_inicio ASC
");
$stmt->execute([$barbearia_id, $data_selecionada]);
$agendamentos_hoje = $stmt->fetchAll();

// 3. Cálculos de faturamento baseados no dia selecionado
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
            <span class="badge bg-dark border border-secondary text-secondary rounded-pill px-2 py-1" style="font-size: 0.7rem;">PRO</span>
        </div>
    </nav>

    <!-- Container Otimizado para o Celular do Barbeiro -->
    <main class="container py-4" style="max-width: 480px;">
        
        <!-- Cabeçalho Dinâmico Com Navegação de Datas -->
        <div class="card p-3 mb-4 shadow-sm border border-secondary" style="background-color: var(--bg-card); --bs-border-opacity: .15; border-radius: 16px;">
            <div class="row g-2 align-items-center">
                <div class="col-7">
                    <label class="text-secondary fw-bold text-uppercase d-block mb-1" style="font-size: 0.65rem; letter-spacing: 0.5px;">Visualizar Data</label>
                    <!-- O id="filtro-data-painel" é monitorado pelo JavaScript abaixo -->
                    <input type="date" id="filtro-data-painel" class="form-control input-custom py-2 fw-bold text-warning" value="<?php echo $data_selecionada; ?>" style="background-color: var(--bg-main) !important; border: 1px solid var(--border-color) !important; color: #FF9F43 !important; border-radius: 10px; font-size: 0.9rem;">
                </div>
                <div class="col-5 d-flex justify-content-end gap-1 mt-auto">
                    <a href="/agenda/<?php echo $slug_filtrado; ?>/admin/servicos" class="btn btn-sm btn-dark border-secondary text-white rounded-3 p-2 text-decoration-none d-flex align-items-center" title="Catálogo de Serviços">
                        <i class="fa-solid fa-tags"></i>
                    </a>
                    <a href="/agenda/<?php echo $slug_filtrado; ?>/admin/horarios" class="btn btn-sm btn-dark border-secondary text-white rounded-3 p-2 text-decoration-none d-flex align-items-center" title="Horários de Trabalho">
                        <i class="fa-solid fa-clock"></i>
                    </a>
                    <button class="btn btn-sm btn-dark border-secondary text-white rounded-3 p-2" onclick="window.location.reload();" title="Atualizar Lista">
                        <i class="fa-solid fa-arrows-rotate"></i>
                    </button>

                    <a href="/agenda/<?php echo $slug_filtrado; ?>/admin/whatsapp" class="btn btn-sm btn-dark border-secondary text-success rounded-3 p-2 text-decoration-none d-flex align-items-center" title="Configurar WhatsApp">
                        <i class="fa-brands fa-whatsapp fs-6"></i>
                    </a>

                </div>
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
                        <input type="date" id="reagendar-data" class="form-control input-custom" value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>" style="background-color: var(--bg-main) !important; border: 1px solid var(--border-color) !important; color: white !important; border-radius: 10px;">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0"></script>

    <script>
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

        // --- LÓGICA DE REAGENDAMENTO (AJAX) ---
        const modalReagendarEl = new bootstrap.Modal(document.getElementById('modalReagendar'));
        const campoReagendarId = document.getElementById('reagendar-id');
        const campoReagendarServicoId = document.getElementById('reagendar-servicoid');
        const campoReagendarData = document.getElementById('reagendar-data');
        const containerReagendarHorarios = document.getElementById('reagendar-container-horarios');
        const btnConfirmarReagendamento = document.getElementById('btn-confirmar-reagendamento');
        let horarioSelecionadoFinal = '';

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
            const urlApi = '/agenda/api/horarios_disponiveis.php?barbearia_id=' + bId + '&data=' + dataSel + '&servico_id=' + servicoId;

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
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id: agendamentoId,
                    data: dataDestino,
                    horario: horarioParaGravar
                })
            })
            .then(r => {
                if (!r.ok) throw new Error('API retornou status de erro');
                return r.json();
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
                alert('Erro de comunicação. Verifique se o arquivo api/reagendar.php foi enviado para a nuvem.');
                btnConfirmarReagendamento.disabled = false;
                btnConfirmarReagendamento.textContent = 'Confirmar Novo Horário';
            });
        });


    </script>

</body>
</html>
