<?php
// helpers/auth.php

function iniciarSessaoAdmin(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off';

    session_name('agenda_admin');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/agenda',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

function usuarioLogado(): ?array
{
    iniciarSessaoAdmin();

    $usuario = $_SESSION['usuario'] ?? null;

    if (!is_array($usuario)) {
        return null;
    }

    $id = (int) ($usuario['id'] ?? 0);
    $barbeariaId = (int) ($usuario['barbearia_id'] ?? 0);
    $nivel = $usuario['nivel'] ?? '';

    if (
        $id <= 0 ||
        $barbeariaId <= 0 ||
        !in_array($nivel, ['dono', 'barbeiro'], true)
    ) {
        return null;
    }

    return $usuario;
}

function exigirLogin(): array
{
    $usuario = usuarioLogado();

    if (!$usuario) {
        http_response_code(401);
        exit('Acesso não autorizado. Faça login.');
    }

    return $usuario;
}

function exigirDono(): array
{
    $usuario = exigirLogin();

    if ($usuario['nivel'] !== 'dono') {
        http_response_code(403);
        exit('Você não possui permissão para acessar esta área.');
    }

    return $usuario;
}

function podeGerenciarAgendamento(
    array $usuario,
    array $agendamento
): bool {
    if (
        (int) $usuario['barbearia_id'] !==
        (int) $agendamento['barbearia_id']
    ) {
        return false;
    }

    if ($usuario['nivel'] === 'dono') {
        return true;
    }

    return (int) $usuario['id'] ===
           (int) $agendamento['usuario_id'];
}

function gerarTokenCsrf(): string
{
    iniciarSessaoAdmin();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function validarTokenCsrf(?string $token): bool
{
    iniciarSessaoAdmin();

    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}
