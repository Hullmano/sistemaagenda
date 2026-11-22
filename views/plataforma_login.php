<?php
// views/plataforma_login.php

require_once __DIR__ . '/../helpers/auth_plataforma.php';

iniciarSessaoPlataforma();

$csrf = gerarTokenCsrfPlataforma();

$erro = $_SESSION['plataforma_login_erro'] ?? null;
unset($_SESSION['plataforma_login_erro']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Administração da Plataforma</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #10151d;
            color: #f1f5f9;
            font-family: Arial, sans-serif;
            padding: 20px;
        }

        .login-card {
            width: 100%;
            max-width: 400px;
            padding: 32px;
            background: #1c2531;
            border: 1px solid #334155;
            border-radius: 16px;
        }

        h1 {
            font-size: 24px;
            margin: 0 0 8px;
        }

        .subtitle {
            color: #94a3b8;
            font-size: 14px;
            margin-bottom: 28px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            border: 1px solid #475569;
            border-radius: 8px;
            background: #111827;
            color: white;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #38bdf8;
        }

        button {
            width: 100%;
            padding: 13px;
            background: #2563eb;
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }

        .erro {
            background: #7f1d1d;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .rodape {
            text-align: center;
            margin-top: 24px;
            color: #64748b;
            font-size: 12px;
        }
    </style>
</head>
<body>

<div class="login-card">

    <h1>Administração do SaaS</h1>

    <p class="subtitle">
        Acesso exclusivo ao administrador da plataforma.
    </p>

    <?php if (is_string($erro) && $erro !== ''): ?>
        <div class="erro">
            <?= htmlspecialchars(
                $erro,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
    <?php endif; ?>

    <form method="POST"
          action="/agenda/api/login_plataforma.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars(
                $csrf,
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >

        <label for="email">E-mail</label>

        <input
            type="email"
            id="email"
            name="email"
            autocomplete="username"
            required
        >

        <label for="senha">Senha</label>

        <input
            type="password"
            id="senha"
            name="senha"
            autocomplete="current-password"
            required
        >

        <button type="submit">
            Entrar na plataforma
        </button>

    </form>

    <div class="rodape">
        Painel administrativo da plataforma
    </div>

</div>

</body>
</html>