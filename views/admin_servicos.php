<?php
// views/admin_servicos.php

// 1. Busca todos os serviços cadastrados e ativos desta barbearia
$db = \Database::getConnection();
$stmt = $db->prepare("SELECT id, nome, preco, duracao_minutos FROM servicos WHERE barbearia_id = ? AND ativo = 1 ORDER BY nome ASC");
$stmt->execute([$barbearia_id]);
$servicos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Serviços - <?php echo htmlspecialchars($barbearia_nome); ?></title>
    <!-- Bootstrap 5 & Ícones -->
    <link href="https://jsdelivr.net" rel="stylesheet">
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
        .navbar-custom { background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        .servico-card { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; }
        .modal-custom { background-color: var(--bg-card); border: 1px solid var(--border-color); color: white; border-radius: 20px; }
        .input-custom { background-color: var(--bg-main) !important; border: 1px solid var(--border-color) !important; color: white !important; border-radius: 10px; }
    </style>
</head>
<body>

    <!-- Topo da Página -->
    <nav class="navbar navbar-custom sticky-top py-3">
        <div class="container-fluid mx-auto d-flex justify-content-between align-items-center" style="max-width: 480px;">
            <a href="/sistemaagenda/<?php echo $slug_filtrado; ?>/admin" class="text-secondary text-decoration-none small">
                <i class="fa-solid fa-chevron-left me-1"></i> Painel
            </a>
            <span class="fw-bold text-white">Serviços</span>
            <div></div>
        </div>
    </nav>

    <!-- Lista de Serviços -->
    <main class="container py-4" style="max-width: 480px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="fw-bold m-0">Lista de Serviços</h5>
            <button class="btn btn-sm btn-warning fw-bold rounded-3 px-3 py-2 text-dark" data-bs-toggle="modal" data-bs-target="#modalNovoServico">
                <i class="fa-solid fa-plus me-1"></i> Adicionar
            </button>
        </div>

        <div id="lista-servicos">
            <?php if(empty($servicos)): ?>
                <div class="text-center p-5 border rounded-4 border-dashed border-secondary opacity-50">
                    <i class="fa-solid fa-tags fs-1 mb-3"></i>
                    <p class="m-0 small">Nenhum serviço cadastrado ainda.</p>
                </div>
            <?php else: ?>
                <?php foreach($servicos as $s): ?>
                    <div class="servico-card p-3 mb-3 d-flex justify-content-between align-items-center shadow-sm">
                        <div>
                            <h6 class="fw-bold text-white mb-1"><?php echo htmlspecialchars($s['nome']); ?></h6>
                            <p class="text-secondary small m-0">
                                <i class="fa-regular fa-clock text-warning me-1"></i> <?php echo $s['duracao_minutos']; ?> min
                                <span class="text-success fw-bold ms-3">R$ <?php echo number_format($s['preco'], 2, ',', '.'); ?></span>
                            </p>
                        </div>
                        <button class="btn btn-sm btn-link text-danger p-0 btn-deletar-servico" data-id="<?php echo $s['id']; ?>">
                            <i class="fa-regular fa-trash-can fs-5"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Janela Flutuante (Modal) de Cadastro -->
    <div class="modal fade" id="modalNovoServico" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered mx-auto px-3" style="max-width: 400px;">
            <div class="modal-content modal-custom p-3">
                <div class="modal-header border-0 p-0 mb-3">
                    <h5 class="modal-title fw-bold">Novo Serviço</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="form-add-servico">
                    <input type="hidden" name="barbearia_id" value="<?php echo $barbearia_id; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label small text-secondary fw-semibold">Nome do Serviço</label>
                        <input type="text" name="nome" class="form-control input-custom" placeholder="Ex: Corte Degradê" required>
                    </div>
                    
                    <div class="row g-2 mb-4">
                        <div class="col-6">
                            <label class="form-label small text-secondary fw-semibold">Preço (R$)</label>
                            <input type="number" step="0.01" name="preco" class="form-control input-custom" placeholder="35.00" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-secondary fw-semibold">Duração (Minutos)</label>
                            <input type="number" name="duracao" class="form-control input-custom" placeholder="30" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-warning w-100 py-2 fw-bold text-dark rounded-3 shadow">Salvar Serviço</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts AJAX -->
    <script src="https://jsdelivr.net"></script>
    <script>
    // Envio do formulário via AJAX para salvar
    document.getElementById('form-add-servico').addEventListener('submit', function(e) {
        e.preventDefault();
        const dadosForm = Object.fromEntries(new FormData(this));

        fetch('/agenda/api/salvar_servico.php', {
            method: 'POST',
            body: JSON.stringify(dadosForm),
            headers: { 'Content-Type': 'application/json' }
        })
        .then(r => r.json())
        .then(res => {
            if (res.sucesso) window.location.reload();
            else alert('Erro: ' + res.erro);
        });
    });

    // Clique na lixeira para desativar o serviço
    document.querySelectorAll('.btn-deletar-servico').forEach(btn => {
        btn.addEventListener('click', function() {
            if (!confirm('Deseja realmente remover este serviço da listagem?')) return;

            fetch('/agenda/api/deletar_servico.php', {
                method: 'POST',
                body: JSON.stringify({ id: this.dataset.id }),
                headers: { 'Content-Type': 'application/json' }
            })
            .then(r => r.json())
            .then(res => {
                if (res.sucesso) window.location.reload();
                else alert('Erro: ' + res.erro);
            });
        });
    });
    </script>
</body>
</html>
