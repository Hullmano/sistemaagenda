<?php
// api/editar_barbearia_plataforma.php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth_plataforma.php';
require_once __DIR__ . '/../helpers/telefone.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

$admin = exigirAdministradorPlataforma();

if (!validarTokenCsrfPlataforma($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Token de segurança inválido.');
}

$barbeariaId = filter_var(
    $_POST['barbearia_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($barbeariaId === false || $barbeariaId === null) {
    http_response_code(400);
    exit('Identificador inválido.');
}

function retornarEdicao(int $id, string $mensagem): void
{
    $_SESSION['plataforma_edicao_erro'] = $mensagem;

    header(
        'Location: /agenda/plataforma/editar?id=' . $id,
        true,
        303
    );
    exit;
}

$nome = trim((string)($_POST['nome'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));
$whatsapp = trim((string)($_POST['whatsapp'] ?? ''));
$ativo = isset($_POST['ativo']) && $_POST['ativo'] === '1' ? 1 : 0;

// Validar nome
if ($nome === '' || mb_strlen($nome) > 100) {
    retornarEdicao($barbeariaId, 'Informe um nome válido.');
}

// Validar slug
if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,99}$/', $slug)) {
    retornarEdicao(
        $barbeariaId,
        'O slug deve conter de 2 a 100 caracteres, '
        . 'usando letras minúsculas, números, hífen ou underline.'
    );
}

// Slugs reservados
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
    retornarEdicao(
        $barbeariaId,
        'Este identificador é reservado pelo sistema.'
    );
}

// Normalizar WhatsApp
$whatsappNormalizado = normalizarWhatsappBR($whatsapp);

if ($whatsappNormalizado === null) {
    retornarEdicao(
        $barbeariaId,
        'Informe um WhatsApp brasileiro válido com DDD.'
    );
}

try {
    $db = Database::getConnection();

    // Confirma que a barbearia existe.
    $stmt = $db->prepare("
        SELECT id
        FROM barbearias
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$barbeariaId]);

    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        exit('Barbearia não encontrada.');
    }

    // Verifica se o slug pertence a outra barbearia.
    $stmt = $db->prepare("
        SELECT id
        FROM barbearias
        WHERE slug = ?
          AND id <> ?
        LIMIT 1
    ");
    $stmt->execute([$slug, $barbeariaId]);

    if ($stmt->fetchColumn()) {
        retornarEdicao(
            $barbeariaId,
            'Este identificador já pertence a outra barbearia.'
        );
    }

    // Atualiza apenas os campos permitidos.
    $stmt = $db->prepare("
        UPDATE barbearias
        SET nome = ?,
            slug = ?,
            whatsapp_num = ?,
            ativo = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $nome,
        $slug,
        $whatsappNormalizado,
        $ativo,
        $barbeariaId
    ]);

    $_SESSION['plataforma_edicao_sucesso'] =
        'Barbearia atualizada com sucesso.';

    header('Location: /agenda/plataforma', true, 303);
    exit;

} catch (Throwable $e) {

    error_log(
        'Erro ao editar barbearia: ' . $e->getMessage()
    );

    if (
        $e instanceof PDOException &&
        $e->getCode() === '23000'
    ) {
        retornarEdicao(
            $barbeariaId,
            'Este identificador já está sendo utilizado.'
        );
    }

    retornarEdicao(
        $barbeariaId,
        'Não foi possível atualizar a barbearia.'
    );
}