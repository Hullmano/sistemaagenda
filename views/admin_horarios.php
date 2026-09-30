<?php
// views/admin_horarios.php
$db = \Database::getConnection();

// Busca as configurações de horários já existentes desta barbearia
$stmt = $db->prepare("SELECT dia_semana, hora_abertura, hora_fechamento, hora_almoco_inicio, hora_almoco_fim FROM horarios_funcionamento WHERE barbearia_id = ?");
$stmt->execute([$barbearia_id]);
$horarios_banco = $stmt->fetchAll(PDO::FETCH_UNIQUE); // Organiza o array pelo ID do dia_semana

// Array auxiliar para traduzir os dias
$dias_nome = [
    0 => 'Domingo', 1 => 'Segunda-feira', 2 => 'Terça-feira', 
    3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado'
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horários de Funcionamento - <?php echo htmlspecialchars($barbearia_nome); ?></title>
    <link href="https://jsdelivr.net" rel="stylesheet">
    <link href="https://googleapis.com" rel="stylesheet">
    <link href="https://cloudflare.com" rel="stylesheet">
    <style>
        :root { --bg-main: #0B0F19; --bg-card: #151B2C; --border-color: #222B45; --text-primary: #F4F6F9; --text-secondary: #8F9BB3; --accent: #FF9F43; }
        body { background-color: var(--bg-main); color: var(--text-primary); font-family: 'Plus Jakarta Sans', sans-serif; }
        .navbar-custom { background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        .horario-card { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; }
        .input-custom { background-color: var(--bg-main) !important; border: 1px solid var(--border-color) !important; color: white !important; border-radius: 10px; font-size: 0.85rem; }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-custom sticky-top py-3">
        <div class="container-fluid mx-auto d-flex justify-content-between align-items-center" style="max-width: 480px;">
            <a href="/sistemaagenda/<?php echo $slug_filtrado; ?>/admin" class="text-secondary text-decoration-none small">
                <i class="fa-solid fa-chevron-left me-1"></i> Painel
            </a>
            <span class="fw-bold text-white">Horários de Trabalho</span>
            <div></div>
        </div>
    </nav>

    <!-- Conteúdo Principal -->
    <main class="container py-4" style="max-width: 480px;">
        <h5 class="fw-bold mb-4">Configurar Turnos</h5>

        <div class="space-y-4">
            <?php foreach($dias_nome as $num_dia => $nome_dia): 
                // Verifica se já existe configuração para este dia no banco
                $config = $horarios_banco[$num_dia] ?? null;
                $aberto = $config ? true : false;
            ?>
                <div class="horario-card p-3 mb-3 shadow-sm">
                    <form class="form-horario">
                        <input type="hidden" name="barbearia_id" value="<?php echo $barbearia_id; ?>">
                        <input type="hidden" name="dia_semana" value="<?php echo $num_dia; ?>">

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold m-0 text-warning"><?php echo $nome_dia; ?></h6>
                            <div class="form-check form-switch">
                                <input class="form-check-input check-aberto" type="checkbox" role="switch" <?php echo $aberto ? 'checked' : ''; ?>>
                                <small class="text-secondary label-status"><?php echo $aberto ? 'Abre' : 'Fechado'; ?></small>
                            </div>
                        </div>

                        <!-- Campos de Horário (Só aparecem/habilitam se o switch estiver ativo) -->
                        <div class="campos-turno <?php echo $aberto ? '' : 'd-none'; ?>">
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="text-secondary text-xs d-block mb-1">Abertura</label>
                                    <input type="time" name="hora_abertura" class="form-control input-custom" value="<?php echo $config ? date('H:i', strtotime($config['hora_abertura'])) : '08:00'; ?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="text-secondary text-xs d-block mb-1">Fechamento</label>
                                    <input type="time" name="hora_fechamento" class="form-control input-custom" value="<?php echo $config ? date('H:i', strtotime($config['hora_fechamento'])) : '18:00'; ?>" required>
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="text-secondary text-xs d-block mb-1">Início Almoço</label>
                                    <input type="time" name="hora_almoco_inicio" class="form-control input-custom" value="<?php echo ($config && $config['hora_almoco_inicio']) ? date('H:i', strtotime($config['hora_almoco_inicio'])) : ''; ?>">
                                </div>
                                <div class="col-6">
                                    <label class="text-secondary text-xs d-block mb-1">Fim Almoço</label>
                                    <input type="time" name="hora_almoco_fim" class="form-control input-custom" value="<?php echo ($config && $config['hora_almoco_fim']) ? date('H:i', strtotime($config['hora_almoco_fim'])) : ''; ?>">
                                </div>
                            </div>
                            <div class="d-flex justify-content-end mt-3">
                                <button type="submit" class="btn btn-sm btn-warning fw-bold text-dark px-3 rounded-2">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> Salvar Dia
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <script src="https://jsdelivr.net"></script>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // Controla a exibição dos campos ao ligar/desligar o switch
        document.querySelectorAll('.check-aberto').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const card = this.closest('.horario-card');
                const campos = card.querySelector('.campos-turno');
                const label = card.querySelector('.label-status');
                
                if (this.checked) {
                    campos.classList.remove('d-none');
                    label.textContent = 'Abre';
                } else {
                    // Se desmarcar, avisa o banco que a barbearia não abre neste dia (Deleta a config)
                    campos.classList.add('d-none');
                    label.textContent = 'Fechado';
                    
                    const barbeariaId = card.querySelector('input[name="barbearia_id"]').value;
                    const diaSemana = card.querySelector('input[name="dia_semana"]').value;
                    
                    fetch('/sistemaagenda/api/salvar_horario.php', {
                        method: 'POST',
                        body: JSON.stringify({ barbearia_id: barbeariaId, dia_semana: diaSemana, acao: 'fechar' }),
                        headers: { 'Content-Type': 'application/json' }
                    });
                }
            });
        });

        // Envia as alterações via AJAX ao clicar em Salvar
        document.querySelectorAll('.form-horario').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = Object.fromEntries(new FormData(this));
                formData.acao = 'salvar';

                fetch('/sistemaagenda/api/salvar_horario.php', {
                    method: 'POST',
                    body: JSON.stringify(formData),
                    headers: { 'Content-Type': 'application/json' }
                })
                .then(r => r.json())
                .then(res => {
                    if (res.sucesso) alert('Horário atualizado com sucesso!');
                    else alert('Erro: ' + res.erro);
                });
            });
        });
    });
    </script>
</body>
</html>
