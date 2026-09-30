<?php
// views/cliente_agendamento.php
// Busca os serviços reais da barbearia para listar no Passo 1
$db = \Database::getConnection();
$stmt = $db->prepare("SELECT id, nome, preco, duracao_minutos FROM servicos WHERE barbearia_id = ? AND ativo = 1");
$stmt->execute([$barbearia_id]);
$servicos_reais = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agendamento - <?php echo htmlspecialchars($barbearia_nome); ?></title>
    <link href="https://jsdelivr.net" rel="stylesheet" type="text/css" />
    <script src="https://tailwindcss.com"></script>
</head>
<body class="bg-base-300 min-h-screen font-sans antialiased text-base-content pb-10">

    <header class="bg-base-100 shadow-lg sticky top-0 z-50 px-4 py-4 border-b border-base-200">
        <div class="max-w-md mx-auto flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold tracking-tight text-primary"><?php echo htmlspecialchars($barbearia_nome); ?></h1>
                <p class="text-xs text-base-content/60">Agendamento Online Mobile</p>
            </div>
            <div class="badge badge-success gap-1 text-xs py-2 font-semibold">
                <span class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></span> Aberto
            </div>
        </div>
    </header>

    <!-- Guardamos o ID da barbearia em um campo oculto para o JavaScript ler -->
    <input type="hidden" id="barbearia_id" value="<?php echo $barbearia_id; ?>">

    <main class="max-w-md mx-auto p-4 space-y-6">

        <!-- PASSO 1: Escolha do Serviço Dinâmico -->
        <section class="card bg-base-100 shadow-xl">
            <div class="card-body p-4">
                <div class="flex items-center gap-2 mb-3">
                    <span class="badge badge-primary font-bold">1</span>
                    <h2 class="card-title text-base font-bold">Selecione o Serviço</h2>
                </div>
                
                <div class="space-y-3">
                    <?php if(empty($servicos_reais)): ?>
                        <p class="text-sm text-error">Nenhum serviço cadastrado.</p>
                    <?php else: ?>
                        <?php foreach($servicos_reais as $index => $servico): ?>
                            <label class="label cursor-pointer p-3 rounded-xl border border-base-200 bg-base-200/50 hover:bg-base-200 transition-all flex justify-between items-center">
                                <div class="flex items-center gap-3">
                                    <input type="radio" name="servico" value="<?php echo $servico['id']; ?>" class="radio radio-primary seletor-servico" <?php echo $index === 0 ? 'checked' : ''; ?> />
                                    <div>
                                        <span class="font-bold text-sm block"><?php echo htmlspecialchars($servico['nome']); ?></span>
                                        <span class="text-xs text-base-content/60">⏱️ <?php echo $servico['duracao_minutos']; ?> min</span>
                                    </div>
                                </div>
                                <span class="font-extrabold text-sm text-primary">R\$<?php echo number_format($servico['preco'], 2, ',', '.'); ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- PASSO 2: Escolha da Data -->
        <section class="card bg-base-100 shadow-xl">
            <div class="card-body p-4">
                <div class="flex items-center gap-2 mb-3">
                    <span class="badge badge-primary font-bold">2</span>
                    <h2 class="card-title text-base font-bold">Escolha o Dia</h2>
                </div>
                <input type="date" id="campo-data" class="input input-bordered w-full font-medium" value="<?php echo date('Y-m-d'); ?>" min="<?php echo date('Y-m-d'); ?>" />
            </div>
        </section>

        <!-- PASSO 3: Horários Disponíveis Gerados via AJAX -->
        <section class="card bg-base-100 shadow-xl">
            <div class="card-body p-4">
                <div class="flex items-center gap-2 mb-3">
                    <span class="badge badge-primary font-bold">3</span>
                    <h2 class="card-title text-base font-bold">Horários Disponíveis</h2>
                </div>
                
                <!-- O JavaScript vai injetar os botões de horários reais dentro desta div -->
                <div id="container-horarios" class="grid grid-cols-4 gap-2">
                    <p class="text-xs text-base-content/50 col-span-4 text-center py-2">Carregando horários...</p>
                </div>
            </div>
        </section>

        <!-- PASSO 4: Identificação do Cliente -->
        <section class="card bg-base-100 shadow-xl">
            <div class="card-body p-4">
                <div class="flex items-center gap-2 mb-3">
                    <span class="badge badge-primary font-bold">4</span>
                    <h2 class="card-title text-base font-bold">Seus Dados</h2>
                </div>
                <div class="space-y-3">
                    <input type="text" id="cliente-nome" placeholder="Seu Nome Completo" class="input input-bordered w-full text-sm" required   />
                    <input type="tel" id="cliente-whatsapp" placeholder="WhatsApp com DDD (Ex: 62999999999)" class="input input-bordered w-full text-sm" required />
                </div>
            </div>
        </section>

        <div class="pt-2">
            <button id="btn-finalizar" class="btn btn-primary btn-block shadow-lg text-base font-bold tracking-wide uppercase py-3 h-auto">
                ⚡ Confirmar Agendamento
            </button>
        </div>
    </main>

    <!-- SCRIPT AJAX COM FETCH API -->
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const campoData = document.getElementById('campo-data');
        const containerHorarios = document.getElementById('container-horarios');
        const barbeariaId = document.getElementById('barbearia_id').value;

        // Função responsável por buscar os horários na API via AJAX
        function carregarHorarios() {
            const dataSelecionada = campoData.value;
            const servicoSelecionado = document.querySelector('input[name="servico"]:checked')?.value;

            if (!dataSelecionada || !servicoSelecionado) return;

            containerHorarios.innerHTML = '<p class="text-xs text-base-content/50 col-span-4 text-center py-2">Buscando vagas...</p>';

            // Faz a requisição em segundo plano para nossa API PHP
            fetch(`api/horarios_disponiveis.php?barbearia_id=${barbeariaId}&data=${dataSelecionada}&servico_id=${servicoSelecionado}`)
                .then(response => response.json())
                .then(dados => {
                    containerHorarios.innerHTML = ''; // Limpa o carregando

                    if (dados.erro || !dados.horarios || dados.horarios.length === 0) {
                        containerHorarios.innerHTML = '<p class="text-xs text-error col-span-4 text-center py-2 font-semibold">Nenhum horário livre para este dia.</p>';
                        return;
                    }

                    // Cria um botão bonito em Tailwind/DaisyUI para cada horário retornado pelo algoritmo
                    dados.horarios.forEach(horario => {
                        const botao = document.createElement('button');
                        botao.type = 'button';
                        botao.className = 'btn btn-outline btn-sm font-bold text-xs hover:btn-primary btn-horario';
                        botao.textContent = horario;
                        
                        // Evento de clique para marcar o botão como ativo
                        botao.addEventListener('click', function() {
                            document.querySelectorAll('.btn-horario').forEach(b => b.classList.remove('btn-primary', 'text-white'));
                            botao.classList.add('btn-primary', 'text-white');
                            botao.dataset.selecionado = "true";
                        });

                        containerHorarios.appendChild(botao);
                    });
                })
                .catch(erro => {
                    console.error('Erro no AJAX:', erro);
                    containerHorarios.innerHTML = '<p class="text-xs text-error col-span-4 text-center py-2">Erro ao carregar agenda.</p>';
                });
        }

        // --- CÓDIGO DE ENVIO DO AGENDAMENTO ---
        const btnFinalizar = document.getElementById('btn-finalizar');
        const campoNome = document.getElementById('cliente-nome');
        const campoWhats = document.getElementById('cliente-whatsapp');

        btnFinalizar.addEventListener('click', function() {
            // Captura o botão de horário que está marcado com a classe do DaisyUI 'btn-primary'
            const botaoHorarioSelecionado = document.querySelector('.btn-horario.btn-primary');
            const servicoSelecionado = document.querySelector('input[name="servico"]:checked')?.value;

            if (!servicoSelecionado) {
                alert('Por favor, selecione um serviço.');
                return;
            }
            if (!botaoHorarioSelecionado) {
                alert('Por favor, escolha um horário disponível da lista.');
                return;
            }
            if (!campoNome.value.trim() || !campoWhats.value.trim()) {
                alert('Por favor, preencha seu nome e seu WhatsApp.');
                return;
            }

            // Desabilita o botão para o cliente não clicar duas vezes por ansiedade
            btnFinalizar.disabled = true;
            btnFinalizar.textContent = 'Processando...';

            // Monta o payload de dados
            const dadosAgendamento = {
                barbearia_id: barbeariaId,
                servico_id: servicoSelecionado,
                data: campoData.value,
                horario: botaoHorarioSelecionado.textContent,
                nome: campoNome.value,
                whatsapp: campoWhats.value
            };

            // Dispara a requisição POST via AJAX
            fetch('api/criar_agendamento.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(dadosAgendamento)
            })
            .then(response => response.json())
            .then(res => {
                if (res.sucesso) {
                    // Substitui o conteúdo por uma mensagem elegante de sucesso
                    document.querySelector('main').innerHTML = `
                        <div class="card bg-base-100 shadow-xl text-center p-6 space-y-4 animate-bounce">
                            <div class="text-6xl">🎉</div>
                            <h2 class="text-2xl font-black text-success">Agendado!</h2>
                            <p class="text-sm text-base-content/80">Tudo certo, <strong>${campoNome.value}</strong>! Seu horário para o dia ${campoData.value} às ${botaoHorarioSelecionado.textContent} foi reservado.</p>
                            <div class="alert alert-info text-xs font-semibold py-2">
                                📱 Um lembrete será enviado no seu WhatsApp 1h antes do atendimento.
                            </div>
                        </div>
                    `;
                } else {
                    alert('Erro: ' + res.erro);
                    btnFinalizar.disabled = false;
                    btnFinalizar.textContent = '⚡ Confirmar Agendamento';
                }
            })
            .catch(erro => {
                console.error(erro);
                alert('Ocorreu um erro de comunicação com o servidor.');
                btnFinalizar.disabled = false;
                btnFinalizar.textContent = '⚡ Confirmar Agendamento';
            });
        });


        // Fica ouvindo quando o usuário muda a data
        campoData.addEventListener('change', carregarHorarios);

        // Fica ouvindo quando o usuário troca o serviço de rádio button
        document.querySelectorAll('.seletor-servico').forEach(radio => {
            radio.addEventListener('change', carregarHorarios);
        });

        // Carrega os horários automaticamente na primeira abertura da tela
        carregarHorarios();
    });
    </script>
</body>
</html>
