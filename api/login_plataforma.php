<?php
// api/login_plataforma.php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth_plataforma.php';

iniciarSessaoPlataforma();

function voltarLoginPlataforma(string $mensagem): void
{
    $_SESSION['plataforma_login_erro'] = $mensagem;

    header(
        'Location: /agenda/plataforma/login',
        true,
        303
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

$email = trim((string)($_POST['email'] ?? ''));
$senha = $_POST['senha'] ?? null;
$csrf = $_POST['csrf_token'] ?? null;

if (!validarTokenCsrfPlataforma($csrf)) {
    http_response_code(403);
    exit('Sessão expirada. Atualize a página de login.');
}

if (
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !is_string($senha) ||
    $senha === ''
) {
    voltarLoginPlataforma('Informe um e-mail e uma senha válidos.');
}

try {
    $db = Database::getConnection();

    $stmt = $db->prepare("
        SELECT id, nome, email, senha
        FROM administradores_plataforma
        WHERE email = ?
          AND ativo = 1
        LIMIT 1
    ");

    $stmt->execute([$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$admin ||
        !password_verify($senha, $admin['senha'])
    ) {
        voltarLoginPlataforma('E-mail ou senha incorretos.');
    }

    session_regenerate_id(true);

    $_SESSION['admin_plataforma_id'] = (int)$admin['id'];

    unset($_SESSION['plataforma_login_erro']);

    $_SESSION['csrf_plataforma'] = bin2hex(random_bytes(32));

    header(
        'Location: /agenda/plataforma',
        true,
        303
    );
    exit;

} catch (Throwable $e) {
    error_log('Erro no login da plataforma: ' . $e->getMessage());

    http_response_code(500);
    exit('Erro interno ao realizar login.');
}