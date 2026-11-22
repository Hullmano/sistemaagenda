<?php
// api/cadastrar_barbearia_plataforma.php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth_plataforma.php';
require_once __DIR__ . '/../helpers/telefone.php';

$admin = exigirAdministradorPlataforma();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

if (!validarTokenCsrfPlataforma($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Token de segurança inválido.');
}

function retornarCadastro(string $mensagem): void
{
    $_SESSION['plataforma_cadastro_erro'] = $mensagem;

    header('Location: /agenda/plataforma/nova', true, 303);
    exit;
}

// Dados da barbearia
$nomeBarbearia = trim((string)($_POST['nome_barbearia'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));
$whatsapp = trim((string)($_POST['whatsapp'] ?? ''));

// Dados do proprietário
$nomeDono = trim((string)($_POST['nome_dono'] ?? ''));
$emailDono = trim((string)($_POST['email_dono'] ?? ''));
$senhaDono = $_POST['senha_dono'] ?? null;
$confirmarSenha = $_POST['confirmar_senha'] ?? null;

// Validação dos nomes
if (
    $nomeBarbearia === '' ||
    mb_strlen($nomeBarbearia) > 100 ||
    $nomeDono === '' ||
    mb_strlen($nomeDono) > 100
) {
    retornarCadastro('Informe nomes válidos para a barbearia e o proprietário.');
}

// Validação do slug
if (
    !preg_match('/^[a-z0-9][a-z0-9_-]{1,99}$/', $slug)
) {
    retornarCadastro(
        'O slug deve ter de 2 a 100 caracteres, '
        . 'usando letras minúsculas, números, hífen ou underline.'
    );
}

// Endereços reservados da plataforma
$slugsReservados = [
    'plataforma',
    'api',
    'assets',
    'helpers',
    'views',
    'cron',
    'sql',
    'testes'
];

if (in_array($slug, $slugsReservados, true)) {
    retornarCadastro('Este identificador é reservado pelo sistema.');
}

// Normalização do WhatsApp
$whatsappNormalizado = normalizarWhatsappBR($whatsapp);

if ($whatsappNormalizado === null) {
    retornarCadastro('Informe um WhatsApp brasileiro válido com DDD.');
}

// Validação do e-mail
if (
    mb_strlen($emailDono) > 100 ||
    !filter_var($emailDono, FILTER_VALIDATE_EMAIL)
) {
    retornarCadastro('Informe um e-mail válido para o proprietário.');
}

// Validação da senha
if (
    !is_string($senhaDono) ||
    strlen($senhaDono) < 8 ||
    strlen($senhaDono) > 128 ||
    !preg_match('/[a-z]/', $senhaDono) ||
    !preg_match('/[A-Z]/', $senhaDono) ||
    !preg_match('/[0-9]/', $senhaDono) ||
    !preg_match('/[^A-Za-z0-9]/', $senhaDono)
) {
    retornarCadastro(
        'A senha deve ter entre 8 e 128 caracteres, '
        . 'com maiúscula, minúscula, número e símbolo.'
    );
}

if ($senhaDono !== $confirmarSenha) {
    retornarCadastro('As senhas informadas não coincidem.');
}

try {
    $db = Database::getConnection();

    // Verifica duplicidades antes da transação.
    // Os índices UNIQUE do banco também protegem
    // contra cadastros simultâneos.
    $stmt = $db->prepare("
        SELECT id
        FROM barbearias
        WHERE slug = ?
        LIMIT 1
    ");
    $stmt->execute([$slug]);

    if ($stmt->fetchColumn()) {
        retornarCadastro('Este slug já está sendo utilizado.');
    }

    $stmt = $db->prepare("
        SELECT id
        FROM usuarios
        WHERE email = ?
        LIMIT 1
    ");
    $stmt->execute([$emailDono]);

    if ($stmt->fetchColumn()) {
        retornarCadastro('Este e-mail já está cadastrado.');
    }

    // Cadastro completo ou reversão total.
    $db->beginTransaction();

    $stmt = $db->prepare("
        INSERT INTO barbearias
            (nome, slug, whatsapp_num, ativo)
        VALUES (?, ?, ?, 1)
    ");

    $stmt->execute([
        $nomeBarbearia,
        $slug,
        $whatsappNormalizado
    ]);

    $barbeariaId = (int)$db->lastInsertId();

    $hashSenha = password_hash(
        $senhaDono,
        PASSWORD_DEFAULT
    );

    $stmt = $db->prepare("
        INSERT INTO usuarios
            (barbearia_id, nome, email, senha, nivel)
        VALUES (?, ?, ?, ?, 'dono')
    ");

    $stmt->execute([
        $barbeariaId,
        $nomeDono,
        $emailDono,
        $hashSenha
    ]);

    $db->commit();

    $_SESSION['plataforma_cadastro_sucesso'] =
        'Barbearia cadastrada com sucesso.';

    header('Location: /agenda/plataforma', true, 303);
    exit;

} catch (Throwable $e) {

    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    error_log(
        'Erro no cadastro da barbearia: ' . $e->getMessage()
    );

    if (
        $e instanceof PDOException &&
        $e->getCode() === '23000'
    ) {
        retornarCadastro(
            'O slug ou e-mail já está cadastrado.'
        );
    }

    retornarCadastro(
        'Não foi possível cadastrar a barbearia.'
    );
}