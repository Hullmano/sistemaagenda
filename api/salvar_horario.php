
<?php
// api/salvar_horario.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

function responderErroHorario(int $codigo, string $mensagem): void
{
    http_response_code($codigo);
    echo json_encode(['erro' => $mensagem]);
    exit;
}

// Apenas requisições POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderErroHorario(405, 'Método não permitido.');
}

// Exige autenticação
$usuario = usuarioLogado();

if (!$usuario) {
    responderErroHorario(401, 'Sessão expirada. Faça login novamente.');
}

// Somente o proprietário pode alterar horários
if ($usuario['nivel'] !== 'dono') {
    responderErroHorario(
        403,
        'Somente o proprietário pode alterar os horários.'
    );
}

// Proteção CSRF
if (!validarTokenCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    responderErroHorario(403, 'Token de segurança inválido.');
}

// Recebe o JSON
$dados = json_decode(file_get_contents('php://input'), true);

if (!is_array($dados)) {
    responderErroHorario(400, 'Dados inválidos.');
}

// A barbearia é identificada pela sessão
$barbearia_id = (int) $usuario['barbearia_id'];

$dia_semana = filter_var(
    $dados['dia_semana'] ?? null,
    FILTER_VALIDATE_INT
);

$acao = $dados['acao'] ?? '';

if (
    $dia_semana === false ||
    $dia_semana === null ||
    $dia_semana < 0 ||
    $dia_semana > 6 ||
    !is_string($acao)
) {
    responderErroHorario(400, 'Parâmetros inválidos.');
}

// Mantém a ação de fechar o dia
if ($acao === 'fechar') {
    try {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            DELETE FROM horarios_funcionamento
            WHERE barbearia_id = ?
              AND dia_semana = ?
        ");

        $stmt->execute([$barbearia_id, $dia_semana]);

        echo json_encode(['sucesso' => true]);
        exit;

    } catch (Throwable $e) {
        error_log('Erro ao fechar horário: ' . $e->getMessage());
        responderErroHorario(500, 'Erro interno ao fechar o dia.');
    }
}

if ($acao !== 'abrir' && $acao !== 'salvar') {
    responderErroHorario(400, 'Ação inválida.');
}

$abertura = $dados['hora_abertura'] ?? '';
$fechamento = $dados['hora_fechamento'] ?? '';
$almoco_ini = $dados['hora_almoco_inicio'] ?? null;
$almoco_fim = $dados['hora_almoco_fim'] ?? null;

if (
    !is_string($abertura) ||
    !is_string($fechamento) ||
    !is_string($almoco_ini) && $almoco_ini !== null ||
    !is_string($almoco_fim) && $almoco_fim !== null
) {
    responderErroHorario(400, 'Horários inválidos.');
}

$abertura = trim($abertura);
$fechamento = trim($fechamento);
$almoco_ini = $almoco_ini !== null ? trim($almoco_ini) : null;
$almoco_fim = $almoco_fim !== null ? trim($almoco_fim) : null;

$almoco_ini = $almoco_ini === '' ? null : $almoco_ini;
$almoco_fim = $almoco_fim === '' ? null : $almoco_fim;

// Aceita HH:MM ou HH:MM:SS
function minutosHorario(string $hora): ?int
{
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::00)?$/', $hora, $m)) {
        return null;
    }

    return ((int) $m[1] * 60) + (int) $m[2];
}

$minAbertura = minutosHorario($abertura);
$minFechamento = minutosHorario($fechamento);

if (
    $minAbertura === null ||
    $minFechamento === null ||
    $minAbertura >= $minFechamento
) {
    responderErroHorario(400, 'Abertura e fechamento inválidos.');
}

// Almoço: ambos preenchidos ou ambos vazios
if (($almoco_ini === null) !== ($almoco_fim === null)) {
    responderErroHorario(
        400,
        'Preencha o início e o fim do intervalo de almoço.'
    );
}

if ($almoco_ini !== null) {
    $minAlmocoIni = minutosHorario($almoco_ini);
    $minAlmocoFim = minutosHorario($almoco_fim);

    if (
        $minAlmocoIni === null ||
        $minAlmocoFim === null ||
        $minAlmocoIni < $minAbertura ||
        $minAlmocoFim > $minFechamento ||
        $minAlmocoIni >= $minAlmocoFim
    ) {
        responderErroHorario(400, 'Intervalo de almoço inválido.');
    }
}

try {
    $db = Database::getConnection();

    $stmt = $db->prepare("
        INSERT INTO horarios_funcionamento (
            barbearia_id,
            dia_semana,
            hora_abertura,
            hora_fechamento,
            hora_almoco_inicio,
            hora_almoco_fim
        )
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            hora_abertura = VALUES(hora_abertura),
            hora_fechamento = VALUES(hora_fechamento),
            hora_almoco_inicio = VALUES(hora_almoco_inicio),
            hora_almoco_fim = VALUES(hora_almoco_fim)
    ");

    $stmt->execute([
        $barbearia_id,
        $dia_semana,
        $abertura,
        $fechamento,
        $almoco_ini,
        $almoco_fim
    ]);

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Horário atualizado com sucesso.'
    ]);

} catch (Throwable $e) {
    error_log('Erro ao salvar horário: ' . $e->getMessage());
    responderErroHorario(500, 'Erro interno ao salvar horário.');
}
