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

        /* Navegação por etapas do agendamento */
        #etapa-data-horario {
            display: none;
        }
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
            <section id="etapa-servico">
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
                                    <input
                                        type="radio"
                                        name="servico"
                                        value="<?= (int)$servico['id'] ?>"
                                        data-nome="<?= htmlspecialchars($servico['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-preco="<?= htmlspecialchars((string)$servico['preco'], ENT_QUOTES, 'UTF-8') ?>"
                                        class="form-check-input seletor-servico m-0"
                                    >
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
            </section>
            <!-- PASSO 2: Escolha da Data -->
            <section id="etapa-data-horario">
                <div class="mb-3">
                    <button type="button"
                            id="btn-voltar-servico"
                            class="btn btn-outline-secondary btn-sm">
                        <i class="fa-solid fa-arrow-left me-1"></i>
                        Voltar aos serviços
                    </button>
                </div>
                <div class="card-custom p-3 shadow-sm">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="badge bg-warning text-dark fw-bold">2</span>
                        <h6 class="m-0 fw-bold text-white">Escolha o Dia</h6>
                    </div>
                    <!-- Calendário compacto -->
                    <div id="calendario-compacto" class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <button type="button"
                                    id="btn-semana-anterior"
                                    class="btn btn-outline-secondary btn-sm">
                                <i class="fa-solid fa-chevron-left"></i>
                            </button>

                            <span id="periodo-calendario"
                                class="small fw-bold text-white">
                                Selecione uma data
                            </span>

                            <button type="button"
                                    id="btn-proxima-semana"
                                    class="btn btn-outline-secondary btn-sm">
                                <i class="fa-solid fa-chevron-right"></i>
                            </button>
                        </div>

                        <div id="dias-calendario"
                            class="row row-cols-5 g-2 text-center">
                        </div>
                    </div>

                    <!-- Mantém a data selecionada para o sistema atual -->
                    <input type="hidden" id="campo-data" value="<?php echo date('Y-m-d'); ?>">
                </div>

                <!-- PASSO 3: Horários Livres -->
                <div class="card-custom p-3 shadow-sm">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="badge bg-warning text-dark fw-bold">2</span>
                        <h6 class="m-0 fw-bold text-white">Horários Disponíveis</h6>
                    </div>
                    <div id="container-horarios" class="row row-cols-4 g-2 px-2">
                        <p class="text-xs text-secondary text-center w-100 py-2 m-0">Buscando horários...</p>
                    </div>
                </div>

                <!-- Resumo do Agendamento -->
                <div id="secao-resumo" class="card-custom p-3 shadow-sm mb-3">

                    <h6 class="fw-bold text-white mb-3">
                        <i class="fa-solid fa-clipboard-list text-warning me-2"></i>
                        Resumo do Agendamento
                    </h6>

                    <div class="small">

                        <div class="d-flex justify-content-between gap-2 mb-2">
                            <span class="text-secondary">Serviço</span>
                            <strong id="resumo-servico" class="text-white text-end">—</strong>
                        </div>

                        <div class="d-flex justify-content-between gap-2 mb-2">
                            <span class="text-secondary">Data</span>
                            <strong id="resumo-data" class="text-white">—</strong>
                        </div>

                        <div class="d-flex justify-content-between gap-2 mb-2">
                            <span class="text-secondary">Horário</span>
                            <strong id="resumo-horario" class="text-white">—</strong>
                        </div>

                        <div class="border-top border-secondary pt-3 mt-3
                                    d-flex justify-content-between align-items-center">
                            <span class="fw-bold">Valor do serviço</span>
                            <strong id="resumo-valor" class="text-warning fs-5">—</strong>
                        </div>

                    </div>
                </div>

                <!-- PASSO 4: Identificação do Cliente -->
                <div id="secao-dados-cliente" class="card-custom p-3 shadow-sm">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <span class="badge bg-warning text-dark fw-bold">3</span>
                        <h6 class="m-0 fw-bold text-white">Seus Dados</h6>
                    </div>
                    <div class="d-flex flex-column gap-2">
                        <input type="text" id="cliente-nome" placeholder="Seu Nome Completo" class="form-control input-custom" required>
                        <input type="tel" id="cliente-whatsapp" placeholder="DDD + telefone (ex.: 62999999999)" inputmode="tel" autocomplete="tel-national" class="form-control input-custom" required>
                        <div class="small text-secondary">🇧🇷 +55 sera acrescentado automaticamente.</div>
                        <label class="small text-secondary"><input type="checkbox" id="lembrar-cliente" checked> Lembrar meus dados neste navegador</label>
                        <button type="button" id="esquecer-cliente" class="btn btn-outline-secondary btn-sm">Nao sou eu / Limpar dados salvos</button>
                    </div>
                </div>

                <!-- Botão Confirmar -->
                <div class="pt-2">
                    <button id="btn-finalizar" class="btn btn-warning w-100 py-3 fw-bold text-dark text-uppercase shadow shadow-lg" style="border-radius: 12px; letter-spacing: 0.5px;">
                        ⚡ Confirmar Agendamento
                    </button>
                </div>
            </section>
        </div>
    </main>

    <!-- Bootstrap 5 JavaScript via CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Motor AJAX Otimizado para a subpasta /agenda -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
                document.getElementById('btn-voltar-servico').addEventListener('click', function() {
            document.getElementById('etapa-data-horario').style.display = 'none';
            document.getElementById('etapa-servico').style.display = 'block';

            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        const campoData = document.getElementById('campo-data');

        // Calendário compacto: 7 dias por página, até 30 dias incluindo hoje.
        const diasCalendario = document.getElementById('dias-calendario');
        const periodoCalendario = document.getElementById('periodo-calendario');
        const btnAnterior = document.getElementById('btn-semana-anterior');
        const btnProxima = document.getElementById('btn-proxima-semana');

        const LIMITE_DIAS = 30;
        let inicioPagina = 0;

        const hoje = new Date();
        hoje.setHours(12, 0, 0, 0);

        const nomesDias = ['DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB'];

        function dataDoIndice(indice) {
            const data = new Date(hoje);
            data.setDate(hoje.getDate() + indice);
            return data;
        }

        function formatarDataLocal(data) {
            const ano = data.getFullYear();
            const mes = String(data.getMonth() + 1).padStart(2, '0');
            const dia = String(data.getDate()).padStart(2, '0');

            return `${ano}-${mes}-${dia}`;
        }

        function renderizarCalendario() {
            diasCalendario.innerHTML = '';

            const ultimoIndice = Math.min(inicioPagina + 4, LIMITE_DIAS - 1);

            for (let i = inicioPagina; i <= ultimoIndice; i++) {
                const data = dataDoIndice(i);
                const valor = formatarDataLocal(data);

                const coluna = document.createElement('div');
                coluna.className = 'col';

                const botao = document.createElement('button');
                botao.type = 'button';
                botao.className = 'btn btn-outline-light w-100 p-1';
                botao.style.fontSize = '0.7rem';
                botao.style.minHeight = '65px';

                botao.innerHTML = `
                    <small class="d-block">${nomesDias[data.getDay()]}</small>
                    <strong class="d-block">
                        ${String(data.getDate()).padStart(2, '0')}/${String(data.getMonth() + 1).padStart(2, '0')}
                    </strong>
                `;


                if (campoData.value === valor) {
                    botao.classList.remove('btn-outline-light');
                    botao.classList.add('btn-warning', 'text-dark');
                }

                botao.addEventListener('click', function() {
                    campoData.value = valor;
                    renderizarCalendario();
                    carregarHorarios();
                });

                coluna.appendChild(botao);
                diasCalendario.appendChild(coluna);
            }

            const primeira = dataDoIndice(inicioPagina);
            const ultima = dataDoIndice(ultimoIndice);

            periodoCalendario.textContent =
                `${primeira.toLocaleDateString('pt-BR')} a ${ultima.toLocaleDateString('pt-BR')}`;

            btnAnterior.disabled = inicioPagina === 0;
            btnProxima.disabled = inicioPagina + 5 >= LIMITE_DIAS;
        }

        btnAnterior.addEventListener('click', function() {
            inicioPagina = Math.max(0, inicioPagina - 5);
            renderizarCalendario();
        });

        btnProxima.addEventListener('click', function() {
            if (inicioPagina + 5 < LIMITE_DIAS) {
                inicioPagina += 5;
                renderizarCalendario();
            }
        });

        campoData.value = formatarDataLocal(hoje);
        renderizarCalendario();

        const containerHorarios = document.getElementById('container-horarios');
        const barbeariaId = document.getElementById('barbearia_id').value;
        const btnFinalizar = document.getElementById('btn-finalizar');
        const campoNome = document.getElementById('cliente-nome');
        const campoWhats = document.getElementById('cliente-whatsapp');
        let horarioSelecionado = '';

        function atualizarResumo() {
            const servico = document.querySelector('input[name="servico"]:checked');

            const nomeServico = servico?.dataset.nome || '—';
            const precoServico = Number(servico?.dataset.preco || 0);

            const partesData = campoData.value.split('-');

            const dataFormatada = partesData.length === 3
                ? `${partesData[2]}/${partesData[1]}/${partesData[0]}`
                : '—';

            document.getElementById('resumo-servico').textContent = nomeServico;
            document.getElementById('resumo-data').textContent = dataFormatada;
            document.getElementById('resumo-horario').textContent =
                horarioSelecionado || 'Selecione um horário';

            document.getElementById('resumo-valor').textContent =
                precoServico.toLocaleString('pt-BR', {
                    style: 'currency',
                    currency: 'BRL'
                });
        }

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
            atualizarResumo();

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
                            atualizarResumo();

                            // Após selecionar o horário, mostra os dados do cliente.
                            const secaoDados = document.getElementById('secao-resumo');

                            if (secaoDados) {
                                const alturaCabecalho =
                                    document.querySelector('.header-custom')?.offsetHeight || 0;

                                const destino =
                                    secaoDados.getBoundingClientRect().top +
                                    window.scrollY -
                                    alturaCabecalho -
                                    12;

                                window.scrollTo({
                                    top: Math.max(0, destino),
                                    behavior: 'smooth'
                                });
                            }
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
            radio.addEventListener('change', function() {
                document.getElementById('etapa-servico').style.display = 'none';
                document.getElementById('etapa-data-horario').style.display = 'block';

                carregarHorarios();

                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
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
                        const agendamento = res.agendamento;

                        if (!agendamento) {
                            alert('Reserva registrada, mas não foi possível exibir o resumo.');
                            window.location.reload();
                            return;
                        }

                        const partesData = agendamento.data.split('-');
                        const dataFormatada = partesData.length === 3
                            ? `${partesData[2]}/${partesData[1]}/${partesData[0]}`
                            : agendamento.data;

                        document.querySelector('main').innerHTML = `
                            <div class="card-custom p-4 shadow-lg">

                                <div class="text-center mb-4">
                                    <i class="fa-solid fa-circle-check text-success"
                                    style="font-size: 3.5rem;"></i>

                                    <h4 class="text-success fw-bold mt-3">
                                        Agendamento confirmado!
                                    </h4>

                                    <p class="text-secondary small">
                                        Seu horário foi reservado com sucesso.
                                    </p>
                                </div>

                                <div class="card-custom p-3 mb-3">
                                    <h6 class="fw-bold text-warning mb-3">
                                        <i class="fa-solid fa-calendar-check me-2"></i>
                                        Detalhes do agendamento
                                    </h6>

                                    <div class="small d-flex flex-column gap-3">

                                        <div class="d-flex justify-content-between gap-2">
                                            <span class="text-secondary">Cliente</span>
                                            <strong id="sucesso-cliente"
                                                    class="text-end"></strong>
                                        </div>

                                        <div class="d-flex justify-content-between gap-2">
                                            <span class="text-secondary">Serviço</span>
                                            <strong id="sucesso-servico"
                                                    class="text-end"></strong>
                                        </div>

                                        <div class="d-flex justify-content-between gap-2">
                                            <span class="text-secondary">Data</span>
                                            <strong id="sucesso-data"></strong>
                                        </div>

                                        <div class="d-flex justify-content-between gap-2">
                                            <span class="text-secondary">Horário</span>
                                            <strong id="sucesso-horario"></strong>
                                        </div>

                                        <div class="d-flex justify-content-between
                                                    border-top border-secondary pt-3">
                                            <span class="fw-bold">Valor</span>
                                            <strong id="sucesso-valor"
                                                    class="text-warning fs-5"></strong>
                                        </div>

                                    </div>
                                </div>

                                <div class="alert alert-success small">
                                    <i class="fa-solid fa-check-circle me-1"></i>
                                    Sua reserva foi registrada.
                                </div>

                                <a id="link-consultar-agendamento"
                                href="#"
                                class="btn btn-outline-warning w-100 py-3 fw-bold mb-3"
                                style="display: none;"
                                rel="noreferrer">
                                    <i class="fa-solid fa-calendar-check me-2"></i>
                                    Consultar meu agendamento
                                </a>

                                <button type="button"
                                        id="btn-novo-agendamento"
                                        class="btn btn-warning w-100 py-3 fw-bold text-dark">
                                    <i class="fa-solid fa-calendar-plus me-2"></i>
                                    Fazer novo agendamento
                                </button>

                            </div>
                        `;

                        // Utiliza os dados confirmados pelo servidor.
                        document.getElementById('sucesso-cliente').textContent =
                            agendamento.cliente;

                        document.getElementById('sucesso-servico').textContent =
                            agendamento.servico;

                        document.getElementById('sucesso-data').textContent =
                            dataFormatada;

                        document.getElementById('sucesso-horario').textContent =
                            agendamento.horario;

                        document.getElementById('sucesso-valor').textContent =
                            Number(agendamento.valor).toLocaleString('pt-BR', {
                                style: 'currency',
                                currency: 'BRL'
                            });

                            // Monta o endereço de consulta a partir da página pública atual.
                            const tokenConsulta = agendamento.token_consulta;

                            if (
                                typeof tokenConsulta === 'string' &&
                                /^[a-f0-9]{64}$/.test(tokenConsulta)
                            ) {
                                const caminhoAtual = window.location.pathname.replace(/\/+$/, '');

                                const linkConsulta =
                                    window.location.origin +
                                    caminhoAtual +
                                    '/agendamento/' +
                                    tokenConsulta;

                                const botaoConsulta = document.getElementById(
                                    'link-consultar-agendamento'
                                );

                                botaoConsulta.href = linkConsulta;
                                botaoConsulta.style.display = 'block';
                            }

                        // Permite iniciar outro agendamento.
                        document.getElementById('btn-novo-agendamento')
                            .addEventListener('click', function () {
                                window.location.reload();
                            });

                        window.scrollTo({ top: 0, behavior: 'smooth' });

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

        carregarHorarios();
    });
    </script>
</body>
</html>
