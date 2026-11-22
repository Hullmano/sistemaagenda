<?php
// api/cadastrar_barbeiro.php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

// Aceita apenas POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

// Somente o proprietário pode cadastrar barbeiros.
$usuario = exigirDono();

// O ID da barbearia vem da sessão, nunca do formulário.
$barbeariaId = (int)$usuario['barbearia_id'];

if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Token de segurança inválido.');
}

try {
    $db = Database::getConnection();

    // Busca o endereço atual da própria barbearia.
    $stmt = $db->prepare("
        SELECT slug
        FROM barbearias
        WHERE id = ?
          AND ativo = 1
        LIMIT 1
    ");

    $stmt->execute([$barbeariaId]);
    $slug = $stmt->fetchColumn();

    if (!$slug) {
        http_response_code(403);
        exit('Barbearia não disponível.');
    }

    $urlCadastro = '/agenda/' .
        rawurlencode($slug) .
        '/admin/barbeiros-novo';

    $urlListagem = '/agenda/' .
        rawurlencode($slug) .
        '/admin/barbeiros';

    function retornarErroBarbeiro(
        string $mensagem,
        string $url
    ): void {
        $_SESSION['barbeiro_cadastro_erro'] = $mensagem;
        header('Location: ' . $url, true, 303);
        exit;
    }

    $nome = trim((string)($_POST['nome'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $senha = $_POST['senha'] ?? null;
    $confirmarSenha = $_POST['confirmar_senha'] ?? null;

    // Validar nome.
    if ($nome === '' || mb_strlen($nome) > 100) {
        retornarErroBarbeiro(
            'Informe um nome válido.',
            $urlCadastro
        );
    }

    // Validar e-mail.
    if (
        mb_strlen($email) > 100 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        retornarErroBarbeiro(
            'Informe um e-mail válido.',
            $urlCadastro
        );
    }

    // Validar política de senhas.
    if (
        !is_string($senha) ||
        strlen($senha) < 8 ||
        strlen($senha) > 128 ||
        !preg_match('/[a-z]/', $senha) ||
        !preg_match('/[A-Z]/', $senha) ||
        !preg_match('/[0-9]/', $senha) ||
        !preg_match('/[^A-Za-z0-9]/', $senha)
    ) {
        retornarErroBarbeiro(
            'A senha deve ter entre 8 e 128 caracteres, '
            . 'com maiúscula, minúscula, número e símbolo.',
            $urlCadastro
        );
    }

    if ($senha !== $confirmarSenha) {
        retornarErroBarbeiro(
            'As senhas não coincidem.',
            $urlCadastro
        );
    }

    // O e-mail é único em todo o SaaS.
    $stmt = $db->prepare("
        SELECT id
        FROM usuarios
        WHERE email = ?
        LIMIT 1
    ");

    $stmt->execute([$email]);

    if ($stmt->fetchColumn()) {
        retornarErroBarbeiro(
            'Este e-mail já está cadastrado no sistema.',
            $urlCadastro
        );
    }

    // Armazenar apenas o hash da senha.
    $hashSenha = password_hash(
        $senha,
        PASSWORD_DEFAULT
    );

    // Cria somente um usuário do tipo barbeiro.
    $stmt = $db->prepare("
        INSERT INTO usuarios
            (barbearia_id, nome, email, senha, nivel, ativo)
        VALUES
            (?, ?, ?, ?, 'barbeiro', 1)
    ");

    $stmt->execute([
        $barbeariaId,
        $nome,
        $email,
        $hashSenha
    ]);

    $_SESSION['barbeiro_cadastro_sucesso'] =
        'Barbeiro cadastrado com sucesso.';

    header('Location: ' . $urlListagem, true, 303);
    exit;

} catch (PDOException $e) {

    error_log(
        'Erro ao cadastrar barbeiro: ' . $e->getMessage()
    );

    if (
        isset($urlCadastro) &&
        $e->getCode() === '23000'
    ) {
        retornarErroBarbeiro(
            'Não foi possível cadastrar: e-mail já utilizado.',
            $urlCadastro
        );
    }

    http_response_code(500);
    exit('Erro interno ao cadastrar barbeiro.');

} catch (Throwable $e) {

    error_log(
        'Erro ao cadastrar barbeiro: ' . $e->getMessage()
    );

    http_response_code(500);
    exit('Erro interno ao cadastrar barbeiro.');
}