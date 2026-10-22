<?php
// views/admin_login.php
require_once __DIR__ . '/../helpers/auth.php';

iniciarSessaoAdmin();

$erro = $_SESSION['login_erro'] ?? '';
unset($_SESSION['login_erro']);

$csrf = gerarTokenCsrf();

$slugSeguro = htmlspecialchars(
    $slug_filtrado,
    ENT_QUOTES,
    'UTF-8'
);

$nomeSeguro = htmlspecialchars(
    $barbearia_nome,
    ENT_QUOTES,
    'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Login - <?= $nomeSeguro ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        body {
            background: #f4f6f9;
            min-height: 100vh;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            border-radius: 16px;
        }

        .login-icon {
            width: 65px;
            height: 65px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>

<body class="d-flex align-items-center justify-content-center p-3">

    <div class="card login-card border-0 shadow-lg">

        <div class="card-body p-4 p-md-5">

            <div class="text-center mb-4">

                <div class="login-icon bg-dark text-white mx-auto mb-3">
                    <i class="fa-solid fa-scissors fa-2x"></i>
                </div>

                <h3 class="fw-bold mb-1">
                    <?= $nomeSeguro ?>
                </h3>

                <p class="text-muted">
                    Acesso administrativo
                </p>

            </div>

            <?php if ($erro): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-2"></i>
                    <?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="post" action="/agenda/api/login.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"
                >

                <input
                    type="hidden"
                    name="slug"
                    value="<?= $slugSeguro ?>"
                >

                <div class="mb-3">
                    <label for="email" class="form-label fw-semibold">
                        E-mail
                    </label>

                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fa-solid fa-envelope"></i>
                        </span>

                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            name="email"
                            placeholder="seuemail@exemplo.com"
                            autocomplete="username"
                            required
                        >
                    </div>
                </div>

                <div class="mb-3">
                    <label for="senha" class="form-label fw-semibold">
                        Senha
                    </label>

                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fa-solid fa-lock"></i>
                        </span>

                        <input
                            type="password"
                            class="form-control"
                            id="senha"
                            name="senha"
                            placeholder="Digite sua senha"
                            autocomplete="current-password"
                            required
                        >
                    </div>
                </div>

                <div class="form-check mb-4">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="lembrar"
                        checked
                    >

                    <label class="form-check-label" for="lembrar">
                        Lembrar meus dados
                    </label>
                </div>

                <button type="submit" class="btn btn-dark w-100 py-2 fw-semibold">
                    <i class="fa-solid fa-right-to-bracket me-2"></i>
                    Entrar no painel
                </button>

            </form>

            <div class="text-center mt-4">
                <small class="text-muted">
                    Sistema de Gestão para Barbearias
                </small>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const email = document.getElementById('email');
        const lembrar = document.getElementById('lembrar');

        const chave = 'agenda_admin_email';

        email.value = localStorage.getItem(chave) || '';

        lembrar.addEventListener('change', () => {
            if (!lembrar.checked) {
                localStorage.removeItem(chave);
            }
        });

        document.querySelector('form').addEventListener('submit', () => {
            if (lembrar.checked) {
                localStorage.setItem(chave, email.value.trim());
            } else {
                localStorage.removeItem(chave);
            }
        });
    </script>

</body>
</html>
