
<?php
// api/logout.php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

iniciarSessaoAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

$usuario = usuarioLogado();

if (!$usuario) {
    http_response_code(401);
    exit('Sessão não encontrada.');
}

$token = $_POST['csrf_token'] ?? null;

if (!validarTokenCsrf($token)) {
    http_response_code(403);
    exit('Token de segurança inválido.');
}

// Descobre o slug a partir da barbearia da sessão.
$db = Database::getConnection();

$stmt = $db->prepare("
    SELECT slug
    FROM barbearias
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([(int) $usuario['barbearia_id']]);

$slug = $stmt->fetchColumn();

// Limpa os dados da sessão.
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax'
    ]);
}

session_destroy();

if ($slug) {
    header(
        'Location: /agenda/' . rawurlencode($slug) . '/admin/login',
        true,
        303
    );
    exit;
}

header('Location: /agenda/', true, 303);
exit;