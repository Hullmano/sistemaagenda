
<?php
// api/salvar_servico.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

function responderErroServico(int $codigo, string $mensagem): void
{
    http_response_code($codigo);
    echo json_encode(['erro' => $mensagem]);
    exit;
}

// Permite somente requisições POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderErroServico(405, 'Método não permitido.');
}

// Verifica se existe sessão autenticada
$usuario = usuarioLogado();

if (!$usuario) {
    responderErroServico(401, 'Sessão expirada. Faça login novamente.');
}

// Apenas o dono pode cadastrar serviços
if ($usuario['nivel'] !== 'dono') {
    responderErroServico(403, 'Somente o proprietário pode cadastrar serviços.');
}

// Verifica o token CSRF
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

if (!validarTokenCsrf($token)) {
    responderErroServico(403, 'Token de segurança inválido.');
}

// Recebe os dados JSON
$dados = json_decode(file_get_contents('php://input'), true);

if (!is_array($dados)) {
    responderErroServico(400, 'Dados inválidos.');
}

// O ID da barbearia vem da sessão, não do navegador
$barbearia_id = (int) $usuario['barbearia_id'];

$nome = $dados['nome'] ?? '';
$preco = $dados['preco'] ?? null;
$duracao = $dados['duracao'] ?? null;

if (!is_string($nome)) {
    responderErroServico(400, 'Nome do serviço inválido.');
}

$nome = trim($nome);

$preco_valido = filter_var($preco, FILTER_VALIDATE_FLOAT);
$duracao_valida = filter_var($duracao, FILTER_VALIDATE_INT);

if (
    $nome === '' ||
    mb_strlen($nome) > 150 ||
    $preco_valido === false ||
    $preco_valido <= 0 ||
    !is_finite((float) $preco_valido) ||
    $duracao_valida === false ||
    $duracao_valida <= 0 ||
    $duracao_valida > 1440
) {
    responderErroServico(400, 'Preencha os campos corretamente.');
}

try {
    $db = Database::getConnection();

    $stmt = $db->prepare("
        INSERT INTO servicos (
            barbearia_id,
            nome,
            preco,
            duracao_minutos,
            ativo
        )
        VALUES (?, ?, ?, ?, 1)
    ");

    $stmt->execute([
        $barbearia_id,
        $nome,
        $preco_valido,
        $duracao_valida
    ]);

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Serviço cadastrado com sucesso.'
    ]);

} catch (Throwable $e) {
    error_log('Erro ao cadastrar serviço: ' . $e->getMessage());

    responderErroServico(
        500,
        'Erro interno ao cadastrar serviço.'
    );
}
