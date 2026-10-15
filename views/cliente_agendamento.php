<?php
// views/cliente_agendamento.php

// 1. Busca os serviços reais da barbearia para listar no Passo 1
$db = \Database::getConnection();
$stmt = $db->prepare("SELECT id, nome, preco, duracao_minutos FROM servicos WHERE barbearia_id = ? AND ativo = 1 ORDER BY nome ASC");
$stmt->execute([$barbearia_id]);
$servicos_reais = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agendamento - <?php echo htmlspecialchars($barbearia_nome); ?></title>
    
    <!-- Links Estáveis via CDN (cdnjs) -->
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
        .header-custom { background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        .card-custom { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; }
        .input-custom { background-color: var(--bg-main) !important; border: 1px solid var(--border-color) !important; color: white !important; border-radius: 10px; }
        .btn-horario { font-weight: bold; font-size: 0.75rem; padding: 8px; border-radius: 8px; }
    </style>
</head>
<body class="pb-5">

    <!-- Header da Barbearia -->
    <header class="header-custom sticky-top py-3 px-3 shadow-lg">
        <div class="max-w-md mx-auto d-flex justify-content-between align-items-center" style="max-width: 450px;">
            <div>
                <h5 class="m-0 fw-bold text-warning"><?php echo htmlspecialchars($barbearia_nome); ?></h5>
                <small class="text-secondary text-xs">Agendamento Online Mobile</small>
            </div>
            <span class="badge bg-success-subtle text-success px-2 py-1 rounded-pill fw-bold text-uppercase" style="font-size: 0.65rem;">Aberto</span>
        </div>
    </header>

    <!-- ID Oculto para o JavaScript ler -->
    <input type="hidden" id="barbearia_id" value="<?php echo $barbearia_id; ?>">

    <!-- Container Otimizado para Celular -->
    <main class="container py-4" style="max-width: 450px;">
        <div class="d-flex flex-column gap-3">

            <!-- PASSO 1: Escolha do Serviço -->
            <div class="card-custom p-3 shadow-sm">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge bg-warning text-dark fw-bold">1</span>
                    <h6 class="m-0 fw-bold text-white">Selecione o Serviço</h6>
                </div>
                
                <div class="d-flex flex-column gap-2">
                    <?php if(empty($servicos_reais)): ?>
                        <p class="text-sm text-danger m-0">Nenhum serviço cadastrado nesta barbearia.</p>
                    <?php else: ?>
                        <?php foreach($servicos_reais as $index => $servico): ?>
                            <label class="d-flex justify-content-between align-items-center p-3 border border-secondary rounded-3" style="--bs-border-opacity: .15; cursor: pointer; background: rgba(255,255,255,0.02);">
                                <div class="d-flex align-items-center gap-3">
                                    <input type="radio" name="servico" value="<?php echo $servico['id']; ?>" class="form-check-input seletor-servico m-0" <?php echo $index === 0 ? 'checked' : ''; ?>>
                                    <div>
                                        <span class="fw-bold text-white text-sm d-block"><?php echo htmlspecialchars($servico['nome']); ?></span>
                                        <small class="text-secondary">⏱️ <?php echo $servico['duracao_minutos']; ?> min</small>
                                    </div>
                                </div>
                                <span class="fw-extrabold text-warning small">R$ <?php echo number_format($servico['preco'], 2, ',', '.'); ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <!-- PASSO 2: Escolha da Data -->
            <div class="card-custom p-3 shadow-sm">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge bg-warning text-dark fw-bold">2</span>
                    <h6 class="m-0 fw-bold text-white">Escolha o Dia</h6>
                </div>
                <input type="date" id="campo-data" class="form-control input-custom" value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>">
            </div>

            <!-- PASSO 3: Horários Livres -->
            <div class="card-custom p-3 shadow-sm">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge bg-warning text-dark fw-bold">3</span>
                    <h6 class="m-0 fw-bold text-white">Horários Disponíveis</h6>
                </div>
                <div id="container-horarios" class="row row-cols-4 g-2 px-2">
                    <p class="text-xs text-secondary text-center w-100 py-2 m-0">Buscando horários...</p>
                </div>
            </div>

            <!-- PASSO 4: Identificação do Cliente -->
            <div class="card-custom p-3 shadow-sm">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge bg-warning text-dark fw-bold">4</span>
                    <h6 class="m-0 fw-bold text-white">Seus Dados</h6>
                </div>
                <div class="d-flex flex-column gap-2">
                    <input type="text" id="cliente-nome" placeholder="Seu Nome Completo" class="form-control input-custom" required>
                    <input type="tel" id="cliente-whatsapp" placeholder="DDD + telefone (ex.: 62999999999)" inputmode="tel" autocomplete="tel-national" class="form-control input-custom" required>
                    <div class="small text-secondary">🇧🇷 +55 sera acrescentado automaticamente.</div>
                    <label class="small text-secondary"><input type="checkbox" id="lembrar-cliente"> Lembrar meus dados neste navegador</label>
                    <button type="button" id="esquecer-cliente" class="btn btn-outline-secondary btn-sm">Nao sou eu / Limpar dados salvos</button>
                </div>
            </div>

            <div class="card-custom p-3">
                <p class="small text-secondary">Se seu cadastro anterior tem algum dado incorreto, solicite a correção.</p>
                <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalCorrecao">Solicitar correção dos meus dados</button>
            </div>
            <!-- Botão Confirmar -->
            <div class="pt-2">
                <button id="btn-finalizar" class="btn btn-warning w-100 py-3 fw-bold text-dark text-uppercase shadow shadow-lg" style="border-radius: 12px; letter-spacing: 0.5px;">
                    ⚡ Confirmar Agendamento
                </button>
            </div>

        </div>
    </main>

    <div class="modal fade" id="modalCorrecao" tabindex="-1" aria-labelledby="tituloCorrecao" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered"><div class="modal-content" style="background:#151B2C;color:white">
        <form id="form-correcao">
          <div class="modal-header"><h5 class="modal-title" id="tituloCorrecao">Solicitar correção cadastral</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
          <div class="modal-body">
            <p class="small text-secondary">Informe os dados. A alteração dependerá de análise da barbearia.</p>
            <label for="correcao-atual" class="form-label">WhatsApp do cadastro atual</label>
            <input id="correcao-atual" type="tel" class="form-control input-custom mb-3" maxlength="20" required>
            <label for="correcao-nome" class="form-label">Nome correto</label>
            <input id="correcao-nome" class="form-control input-custom mb-3" maxlength="120" required>
            <label for="correcao-novo" class="form-label">WhatsApp correto</label>
            <input id="correcao-novo" type="tel" class="form-control input-custom mb-3" maxlength="20" required>
            <label for="correcao-obs" class="form-label">Observação (opcional)</label>
            <textarea id="correcao-obs" class="form-control input-custom mb-3" maxlength="500"></textarea>
            <p id="correcao-retorno" class="small" role="status" aria-live="polite"></p>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button type="submit" id="btn-correcao" class="btn btn-warning">Enviar solicitação</button></div>
        </form>
      </div></div>
    </div>
    <!-- Bootstrap 5 JavaScript via CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Motor AJAX Otimizado para a subpasta /agenda -->
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const campoData = document.getElementById('campo-data');
        const containerHorarios = document.getElementById('container-horarios');
        const barbeariaId = document.getElementById('barbearia_id').value;
        const btnFinalizar = document.getElementById('btn-finalizar');
        const campoNome = document.getElementById('cliente-nome');
        const campoWhats = document.getElementById('cliente-whatsapp');
        let horarioSelecionado = '';
        // Salva apenas no navegador, com consentimento. Nunca e prova de identidade.
        const chaveLocal = 'agenda_cliente_' + barbeariaId;
        const lembrar = document.getElementById('lembrar-cliente');
        try {
            const dadosSalvos = JSON.parse(localStorage.getItem(chaveLocal) || 'null');
            if (dadosSalvos && typeof dadosSalvos.nome === 'string' && typeof dadosSalvos.whatsapp === 'string') {
                campoNome.value = dadosSalvos.nome;
                campoWhats.value = dadosSalvos.whatsapp;
                lembrar.checked = true;
            }
        } catch (e) { /* armazenamento indisponivel */ }
        document.getElementById('esquecer-cliente').addEventListener('click', () => {
            try { localStorage.removeItem(chaveLocal); } catch(e) {}
            campoNome.value = ''; campoWhats.value = ''; lembrar.checked = false;
        });


        function carregarHorarios() {
            const dataSelecionada = campoData.value;
            const servicoSelecionado = document.querySelector('input[name="servico"]:checked')?.value;

            if (!dataSelecionada || !servicoSelecionado) return;

            containerHorarios.innerHTML = '<p class="text-xs text-muted text-center w-100 py-2 m-0"><i class="fa-solid fa-spinner fa-spin me-1"></i> Procurando vagas...</p>';
            horarioSelecionado = '';

            fetch('/agenda/api/horarios_disponiveis.php?barbearia_id=' + barbeariaId + '&data=' + dataSelecionada + '&servico_id=' + servicoSelecionado)
                .then(r => r.json())
                .then(dados => {
                    containerHorarios.innerHTML = '';

                    if (dados.erro || !dados.horarios || dados.horarios.length === 0) {
                        containerHorarios.innerHTML = '<p class="text-xs text-danger text-center w-100 py-2 m-0 fw-bold">Sem horários livres ou barbearia fechada neste dia.</p>';
                        return;
                    }

                    dados.horarios.forEach(horario => {
                        const col = document.createElement('div');
                        col.className = 'col px-1';
                        
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'btn btn-outline-light w-100 btn-horario btn-slot-cliente';
                        btn.textContent = horario;
                        
                        btn.addEventListener('click', function() {
                            document.querySelectorAll('.btn-slot-cliente').forEach(b => {
                                b.classList.remove('btn-warning', 'text-dark');
                                b.classList.add('btn-outline-light');
                            });
                            btn.classList.remove('btn-outline-light');
                            btn.classList.add('btn-warning', 'text-dark');
                            horarioSelecionado = horario;
                        });

                        col.appendChild(btn);
                        containerHorarios.appendChild(col);
                    });
                })
                .catch(err => {
                    console.error(err);
                    containerHorarios.innerHTML = '<p class="text-xs text-danger text-center w-100 py-2 m-0">Erro ao processar horários.</p>';
                });
        }

        campoData.addEventListener('change', carregarHorarios);
        document.querySelectorAll('.seletor-servico').forEach(radio => {
            radio.addEventListener('change', carregarHorarios);
        });

        // Evento de Gravação do Agendamento Final do Cliente
        btnFinalizar.addEventListener('click', function() {
            const servicoSelecionado = document.querySelector('input[name="servico"]:checked')?.value;

            if (!servicoSelecionado) { alert('Por favor, selecione um serviço.'); return; }
            if (!horarioSelecionado) { alert('Por favor, escolha um horário disponível da lista.'); return; }
            if (!campoNome.value.trim() || !campoWhats.value.trim()) { alert('Por favor, preencha seu nome e seu WhatsApp com DDD.'); return; }

            btnFinalizar.disabled = true;
            btnFinalizar.textContent = 'Processando reserva...';

            fetch('/agenda/api/criar_agendamento.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    barbearia_id: barbeariaId,
                    servico_id: servicoSelecionado,
                    data: campoData.value,
                    horario: horarioSelecionado,
                    nome: campoNome.value,
                    whatsapp: campoWhats.value
                })
            })
            .then(r => r.json())
            .then(res => {
                if (res.sucesso) {
                    try {
                        if (lembrar.checked) {
                            localStorage.setItem(chaveLocal, JSON.stringify({nome: campoNome.value.trim(), whatsapp: campoWhats.value.trim()}));
                        } else {
                            localStorage.removeItem(chaveLocal);
                        }
                    } catch (e) { /* navegador bloqueou armazenamento */ }
                    document.querySelector('main').innerHTML = `
                        <div class="card-custom text-center p-5 space-y-4 shadow shadow-lg border border-success" style="--bs-border-opacity: .3;">
                            <div class="text-success mb-3" style="font-size: 4rem;"><i class="fa-solid fa-circle-check animate-pulse"></i></div>
                            <h4 class="fw-extrabold text-success">Agendado com Sucesso!</h4>
                            <p class="text-white-50 text-sm">Tudo certo, <strong>${campoNome.value}</strong>! Seu horário foi reservado para o dia ${campoData.value} às ${horarioSelecionado}.</p>
                            <div class="alert alert-warning text-xs font-semibold py-2 m-0 mt-3 border-0 bg-warning bg-opacity-10 text-warning" style="font-size: 0.75rem;">
                                <i class="fa-brands fa-whatsapp me-1"></i> Um lembrete automático será enviado 1 hora antes do corte!
                            </div>
                        </div>
                    `;
                } else {
                    alert('Erro: ' + res.erro);
                    btnFinalizar.disabled = false;
                    btnFinalizar.textContent = '⚡ Confirmar Agendamento';
                }
            })
            .catch(err => {
                console.error(err);
                alert('Erro na comunicação com o servidor.');
                btnFinalizar.disabled = false;
                btnFinalizar.textContent = '⚡ Confirmar Agendamento';
            });
        });

        document.getElementById('form-correcao').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('btn-correcao');
            const aviso = document.getElementById('correcao-retorno');
            btn.disabled = true;
            aviso.textContent = 'Enviando...';
            try {
                const r = await fetch('/agenda/api/solicitar_correcao_cadastro.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({
                        barbearia_id: barbeariaId,
                        whatsapp_atual: document.getElementById('correcao-atual').value,
                        nome_corrigido: document.getElementById('correcao-nome').value,
                        whatsapp_corrigido: document.getElementById('correcao-novo').value,
                        observacao: document.getElementById('correcao-obs').value
                    })
                });
                const dados = await r.json();
                aviso.textContent = dados.mensagem || dados.erro || 'Falha no envio.';
                aviso.className = 'small ' + (dados.sucesso ? 'text-success' : 'text-danger');
                if (dados.sucesso) e.target.reset();
            } catch (err) {
                aviso.textContent = 'Falha de comunicação. Tente novamente.';
                aviso.className = 'small text-danger';
            } finally { btn.disabled = false; }
        });
        carregarHorarios();
    });
    </script>
</body>
</html>
