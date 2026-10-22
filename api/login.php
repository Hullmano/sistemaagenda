<?php
// api/login.php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

iniciarSessaoAdmin();

function voltarLogin(string $slug, string $mensagem): void
{
    $_SESSION['login_erro'] = $mensagem;

    header(
        'Location: /agenda/' .
        rawurlencode($slug) .
        '/admin/login',
        true,
        303
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

$slug = trim((string) ($_POST['slug'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$senha = (string) ($_POST['senha'] ?? '');
$csrf = $_POST['csrf_token'] ?? null;

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    http_response_code(400);
    exit('Barbearia inválida.');
}

if (!validarTokenCsrf($csrf)) {
    http_response_code(403);
    exit('Sessão expirada. Atualize a página de login.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $senha === '') {
    voltarLogin($slug, 'Informe um e-mail e uma senha válidos.');
}

try {
    $db = Database::getConnection();

    $stmt = $db->prepare("
        SELECT
            u.id,
            u.nome,
            u.email,
            u.senha,
            u.nivel,
            u.barbearia_id
        FROM usuarios u
        INNER JOIN barbearias b
            ON b.id = u.barbearia_id
        WHERE u.email = ?
          AND b.slug = ?
          AND b.ativo = 1
        LIMIT 1
    ");

    $stmt->execute([$email, $slug]);
    $usuario = $stmt->fetch();

    if (
        !$usuario ||
        !password_verify($senha, $usuario['senha'])
    ) {
        voltarLogin($slug, 'E-mail ou senha incorretos.');
    }

    if (!in_array($usuario['nivel'], ['dono', 'barbeiro'], true)) {
        voltarLogin($slug, 'Usuário sem permissão de acesso.');
    }

    session_regenerate_id(true);

    $_SESSION['usuario'] = [
        'id' => (int) $usuario['id'],
        'nome' => $usuario['nome'],
        'email' => $usuario['email'],
        'nivel' => $usuario['nivel'],
        'barbearia_id' => (int) $usuario['barbearia_id']
    ];

    unset($_SESSION['login_erro']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    header(
        'Location: /agenda/' .
        rawurlencode($slug) .
        '/admin',
        true,
        303
    );
    exit;

} catch (Throwable $e) {
    error_log('Erro no login administrativo: ' . $e->getMessage());

    http_response_code(500);
    exit('Erro interno ao realizar login.');
}
