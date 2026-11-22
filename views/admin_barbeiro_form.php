<?php
// views/admin_barbeiro_form.php

require_once __DIR__ . '/../helpers/auth.php';

$usuario = exigirDono();
$csrf = gerarTokenCsrf();
$erroCadastro = $_SESSION['barbeiro_cadastro_erro'] ?? null;
unset($_SESSION['barbeiro_cadastro_erro']);

$slugBarbearia = $slug_filtrado;

function escaparFormularioBarbeiro($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Novo barbeiro</title>

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
            font-size: 24px;
            margin: 0 0 8px;
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

        input {
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

        .ajuda {
            margin: -12px 0 20px;
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.5;
        }

        .botao {
            display: block;
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

        .botao:hover {
            background: #1d4ed8;
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
       href="/agenda/<?= rawurlencode($slugBarbearia) ?>/admin/barbeiros">
        &larr; Voltar aos barbeiros
    </a>

    <h1>Cadastrar barbeiro</h1>

    <p class="subtitulo">
        Cadastre um profissional para trabalhar nesta barbearia.
        Ele terá seu próprio acesso ao sistema.
    </p>

    <?php if (is_string($erroCadastro) && $erroCadastro !== ''): ?>
        <div style="
            background: #7f1d1d;
            color: #fee2e2;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        ">
            <?= htmlspecialchars($erroCadastro, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST"
          action="/agenda/api/cadastrar_barbeiro.php">

        <input type="hidden"
               name="csrf_token"
               value="<?= escaparFormularioBarbeiro($csrf) ?>">

        <div class="card">

            <label for="nome">
                Nome do barbeiro
            </label>

            <input type="text"
                   id="nome"
                   name="nome"
                   maxlength="100"
                   autocomplete="name"
                   required>

            <label for="email">
                E-mail de acesso
            </label>

            <input type="email"
                   id="email"
                   name="email"
                   maxlength="100"
                   autocomplete="off"
                   required>

            <label for="senha">
                Senha inicial
            </label>

            <input type="password"
                   id="senha"
                   name="senha"
                   minlength="8"
                   maxlength="128"
                   pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}"
                   title="Mínimo de 8 caracteres, com maiúscula, minúscula, número e símbolo."
                   autocomplete="new-password"
                   required>

            <p class="ajuda">
                Mínimo de 8 caracteres, incluindo letra maiúscula,
                minúscula, número e símbolo.
            </p>

            <label for="confirmar_senha">
                Confirmar senha
            </label>

            <input type="password"
                   id="confirmar_senha"
                   name="confirmar_senha"
                   minlength="8"
                   maxlength="128"
                   autocomplete="new-password"
                   required>

        </div>

        <button type="submit" class="botao">
            Cadastrar barbeiro
        </button>

    </form>

</div>

</body>
</html>