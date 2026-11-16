
<?php
// api/salvar_configuracao_lembrete.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

function responderConfiguracao(int $codigo, array $dados): void
{
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderConfiguracao(405, [
        'erro' => 'Método não permitido.'
    ]);
}

$usuario = usuarioLogado();

if (!$usuario) {
    responderConfiguracao(401, [
        'erro' => 'Sessão expirada.'
    ]);
}

// Configuração exclusiva do dono.
if ($usuario['nivel'] !== 'dono') {
    responderConfiguracao(403, [
        'erro' => 'Somente o dono pode alterar esta configuração.'
    ]);
}

if (!validarTokenCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    responderConfiguracao(403, [
        'erro' => 'Token de segurança inválido.'
    ]);
}

$dados = json_decode(file_get_contents('php://input'), true);

if (!is_array($dados)) {
    responderConfiguracao(400, [
        'erro' => 'Dados inválidos.'
    ]);
}

$antecedencia = $dados['antecedencia_cancelamento_minutos'] ?? null;

// Aceita somente os valores 30 e 60.
if (!in_array($antecedencia, [30, 60], true)) {
    responderConfiguracao(400, [
        'erro' => 'Selecione 30 ou 60 minutos.'
    ]);
}

try {
    $db = Database::getConnection();

    $stmt = $db->prepare("
        UPDATE barbearias
        SET antecedencia_cancelamento_minutos = ?
        WHERE id = ?
          AND ativo = 1
    ");

    $stmt->execute([
        $antecedencia,
        (int)$usuario['barbearia_id']
    ]);

    // rowCount pode ser zero quando o valor já está salvo.
    $stmt = $db->prepare("
        SELECT antecedencia_cancelamento_minutos
        FROM barbearias
        WHERE id = ?
          AND ativo = 1
    ");

    $stmt->execute([(int)$usuario['barbearia_id']]);

    $valorSalvo = $stmt->fetchColumn();

    if ($valorSalvo === false) {
        responderConfiguracao(404, [
            'erro' => 'Barbearia não encontrada.'
        ]);
    }

    responderConfiguracao(200, [
        'sucesso' => true,
        'mensagem' => 'Configuração salva com sucesso.',
        'antecedencia_cancelamento_minutos' => (int)$valorSalvo,
        'antecedencia_lembrete_minutos' => (int)$valorSalvo + 5
    ]);

} catch (Throwable $e) {
    error_log('Erro ao salvar configuração: ' . $e->getMessage());

    responderConfiguracao(500, [
        'erro' => 'Erro interno ao salvar a configuração.'
    ]);
}
