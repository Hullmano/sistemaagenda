<?php
// views/plataforma_barbearia_form.php

require_once __DIR__ . '/../helpers/auth_plataforma.php';

$admin = exigirAdministradorPlataforma();
$csrf = gerarTokenCsrfPlataforma();
$erroCadastro = $_SESSION['plataforma_cadastro_erro'] ?? null;
unset($_SESSION['plataforma_cadastro_erro']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Nova Barbearia - Administração</title>

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
            margin-bottom: 24px;
        }

        .card {
            background: #1c2531;
            border: 1px solid #334155;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 20px;
        }

        h2 {
            font-size: 17px;
            margin: 0 0 20px;
            color: #e2e8f0;
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
            font-size: 12px;
            color: #94a3b8;
            margin: -12px 0 20px;
        }

        .botao {
            width: 100%;
            padding: 14px;
            border: 0;
            border-radius: 8px;
            background: #2563eb;
            color: #fff;
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

    <a class="voltar" href="/agenda/plataforma">
        &larr; Voltar ao painel
    </a>

    <h1>Cadastrar nova barbearia</h1>

    <p class="subtitulo">
        Informe os dados da barbearia e crie o acesso
        inicial do proprietário.
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
            <?= htmlspecialchars(
                $erroCadastro,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
    <?php endif; ?>
    <form method="POST"
          action="/agenda/api/cadastrar_barbearia_plataforma.php">

        <input type="hidden"
               name="csrf_token"
               value="<?= htmlspecialchars(
                   $csrf,
                   ENT_QUOTES,
                   'UTF-8'
               ) ?>">

        <div class="card">

            <h2>Dados da barbearia</h2>

            <label for="nome_barbearia">
                Nome da barbearia
            </label>

            <input type="text"
                   id="nome_barbearia"
                   name="nome_barbearia"
                   maxlength="100"
                   required>

            <label for="slug">
                Identificador (slug)
            </label>

            <input type="text"
                   id="slug"
                   name="slug"
                   maxlength="100"
                   pattern="[a-z0-9_-]+"
                   title="Use letras minúsculas, números, hífen ou underline."
                   autocapitalize="none"
                   required>

            <p class="ajuda">
                Exemplo: barbearia-central.
                Será usado no link público de agendamento.
            </p>

            <label for="whatsapp">
                WhatsApp da barbearia
            </label>

            <input type="tel"
                   id="whatsapp"
                   name="whatsapp"
                   maxlength="20"
                   placeholder="DDD + número"
                   required>

        </div>

        <div class="card">

            <h2>Acesso do proprietário</h2>

            <label for="nome_dono">
                Nome do proprietário
            </label>

            <input type="text"
                   id="nome_dono"
                   name="nome_dono"
                   maxlength="100"
                   required>

            <label for="email_dono">
                E-mail de acesso
            </label>

            <input type="email"
                   id="email_dono"
                   name="email_dono"
                   maxlength="100"
                   autocomplete="off"
                   required>

            <label for="senha_dono">
                Senha inicial
            </label>

            <input type="password"
                id="senha_dono"
                name="senha_dono"
                minlength="8"
                maxlength="128"
                pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,}"
                title="Mínimo de 8 caracteres, incluindo maiúscula, minúscula, número e símbolo."
                autocomplete="new-password"
                required>

            <p class="ajuda">
                Mínimo de 8 caracteres, com letra maiúscula,
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

        <button class="botao" type="submit">
            Cadastrar barbearia
        </button>

    </form>

</div>

</body>
</html>