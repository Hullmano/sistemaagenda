<?php
// api/editar_barbeiro.php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

// Somente o proprietário autenticado.
$usuario = exigirDono();
$barbeariaId = (int)$usuario['barbearia_id'];

if (!validarTokenCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Token de segurança inválido.');
}

$barbeiroId = filter_var(
    $_POST['barbeiro_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($barbeiroId === false || $barbeiroId === null) {
    http_response_code(400);
    exit('Identificador do barbeiro inválido.');
}

$nome = trim((string)($_POST['nome'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$ativo = isset($_POST['ativo']) && $_POST['ativo'] === '1' ? 1 : 0;

if ($nome === '' || mb_strlen($nome) > 100) {
    http_response_code(400);
    exit('Informe um nome válido.');
}

if (
    mb_strlen($email) > 100 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    http_response_code(400);
    exit('Informe um e-mail válido.');
}

try {
    $db = Database::getConnection();

    // Identifica a própria barbearia para o redirecionamento.
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

    $urlEdicao = '/agenda/' .
        rawurlencode($slug) .
        '/admin/barbeiros-editar?id=' . $barbeiroId;

    $urlListagem = '/agenda/' .
        rawurlencode($slug) .
        '/admin/barbeiros';

    // Confirma que o usuário é um barbeiro desta barbearia.
    $stmt = $db->prepare("
        SELECT id
        FROM usuarios
        WHERE id = ?
          AND barbearia_id = ?
          AND nivel = 'barbeiro'
        LIMIT 1
    ");

    $stmt->execute([$barbeiroId, $barbeariaId]);

    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        exit('Barbeiro não encontrado.');
    }

    // Atualiza somente os campos autorizados.
    $stmt = $db->prepare("
        UPDATE usuarios
        SET nome = ?,
            email = ?,
            ativo = ?
        WHERE id = ?
          AND barbearia_id = ?
          AND nivel = 'barbeiro'
    ");

    $stmt->execute([
        $nome,
        $email,
        $ativo,
        $barbeiroId,
        $barbeariaId
    ]);

    $_SESSION['barbeiro_edicao_sucesso'] =
        'Barbeiro atualizado com sucesso.';

    header('Location: ' . $urlListagem, true, 303);
    exit;

} catch (PDOException $e) {
    error_log('Erro ao editar barbeiro: ' . $e->getMessage());

    if (
        isset($urlEdicao) &&
        $e->getCode() === '23000'
    ) {
        $_SESSION['barbeiro_edicao_erro'] =
            'Este e-mail já está cadastrado no sistema.';

        header('Location: ' . $urlEdicao, true, 303);
        exit;
    }

    http_response_code(500);
    exit('Erro interno ao atualizar barbeiro.');

} catch (Throwable $e) {
    error_log('Erro ao editar barbeiro: ' . $e->getMessage());

    http_response_code(500);
    exit('Erro interno ao atualizar barbeiro.');
}