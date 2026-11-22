<?php
// helpers/auth_plataforma.php

function iniciarSessaoPlataforma(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (session_name() !== 'agenda_plataforma') {
            throw new RuntimeException(
                'Outra sessão está ativa.'
            );
        }
        return;
    }

    $https = !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');

    session_name('agenda_plataforma');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/agenda',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// Verifica a sessão e confirma que o administrador
// continua ativo no banco de dados.
function administradorPlataformaLogado(): ?array
{
    iniciarSessaoPlataforma();

    $id = (int)($_SESSION['admin_plataforma_id'] ?? 0);

    if ($id <= 0) {
        return null;
    }

    $db = Database::getConnection();

    $stmt = $db->prepare("
        SELECT id, nome, email
        FROM administradores_plataforma
        WHERE id = ?
          AND ativo = 1
        LIMIT 1
    ");

    $stmt->execute([$id]);

    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    return $admin ?: null;
}

function exigirAdministradorPlataforma(): array
{
    $admin = administradorPlataformaLogado();

    if (!$admin) {
        http_response_code(401);
        exit('Acesso não autorizado.');
    }

    return $admin;
}

function gerarTokenCsrfPlataforma(): string
{
    iniciarSessaoPlataforma();

    if (empty($_SESSION['csrf_plataforma'])) {
        $_SESSION['csrf_plataforma'] =
            bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_plataforma'];
}

function validarTokenCsrfPlataforma(?string $token): bool
{
    iniciarSessaoPlataforma();

    return is_string($token)
        && isset($_SESSION['csrf_plataforma'])
        && hash_equals(
            $_SESSION['csrf_plataforma'],
            $token
        );
}