<?php
// views/admin_barbeiro_editar.php

require_once __DIR__ . '/../helpers/auth.php';

// A rota do index.php deve carregar o barbeiro da barbearia autenticada.
$usuario = exigirDono();

if (!isset($barbeiro) || !is_array($barbeiro)) {
    http_response_code(404);
    exit('Barbeiro não encontrado.');
}

$csrf = gerarTokenCsrf();
$erroEdicao = $_SESSION['barbeiro_edicao_erro'] ?? null;
unset($_SESSION['barbeiro_edicao_erro']);

function escaparEdicaoBarbeiro($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Editar barbeiro</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 20px;
            background: #10151d;
            color: #f1f5f9;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 650px;
            margin: 0 auto;
        }

        .voltar {
            display: inline-block;
            margin-bottom: 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 24px;
        }

        .subtitulo {
            color: #94a3b8;
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 24px;
        }

        .card {
            background: #1c2531;
            border: 1px solid #334155;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-size: 13px;
            font-weight: bold;
        }

        input[type="text"],
        input[type="email"] {
            display: block;
            width: 100%;
            padding: 12px;
            margin-bottom: 19px;
            background: #111827;
            border: 1px solid #475569;
            border-radius: 8px;
            color: #fff;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #38bdf8;
        }

        .status {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px;
            background: #111827;
            border-radius: 8px;
        }

        .status input {
            width: 18px;
            height: 18px;
        }

        .status label {
            margin: 0;
            cursor: pointer;
        }

        .aviso {
            color: #94a3b8;
            font-size: 12px;
            line-height: 1.5;
            margin-top: 12px;
        }

        .botao {
            width: 100%;
            padding: 14px;
            border: 0;
            border-radius: 8px;
            background: #2563eb;
            color: white;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        @media (max-width: 480px) {
            body { padding: 12px; }
            .card { padding: 17px; }
            h1 { font-size: 21px; }
        }
    </style>
</head>
<body>

<div class="container">

    <a class="voltar"
       href="/agenda/<?= rawurlencode($slug_filtrado) ?>/admin/barbeiros">
        &larr; Voltar aos barbeiros
    </a>

    <h1>Editar barbeiro</h1>

    <p class="subtitulo">
        Atualize os dados do profissional e controle seu acesso ao sistema.
    </p>

    <?php if (is_string($erroEdicao) && $erroEdicao !== ''): ?>
        <div style="
            background: #7f1d1d;
            color: #fee2e2;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        ">
            <?= htmlspecialchars($erroEdicao, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST"
          action="/agenda/api/editar_barbeiro.php">

        <input type="hidden"
               name="csrf_token"
               value="<?= escaparEdicaoBarbeiro($csrf) ?>">

        <input type="hidden"
               name="barbeiro_id"
               value="<?= (int)$barbeiro['id'] ?>">

        <div class="card">

            <label for="nome">Nome do barbeiro</label>

            <input type="text"
                   id="nome"
                   name="nome"
                   maxlength="100"
                   value="<?= escaparEdicaoBarbeiro($barbeiro['nome']) ?>"
                   required>

            <label for="email">E-mail de acesso</label>

            <input type="email"
                   id="email"
                   name="email"
                   maxlength="100"
                   value="<?= escaparEdicaoBarbeiro($barbeiro['email']) ?>"
                   required>

            <div class="status">
                <input type="checkbox"
                       id="ativo"
                       name="ativo"
                       value="1"
                       <?= (int)$barbeiro['ativo'] === 1 ? 'checked' : '' ?>>

                <label for="ativo">Barbeiro ativo</label>
            </div>

            <p class="aviso">
                Ao desativar o barbeiro, seu acesso ao sistema será bloqueado.
                Os agendamentos e históricos de atendimentos serão preservados.
            </p>

        </div>

        <button type="submit" class="botao">
            Salvar alterações
        </button>

    </form>

</div>
</body>
</html>