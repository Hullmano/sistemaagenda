<?php
// api/salvar_horario.php
header('Content-Type: application/json; charset=utf-8');
require_once '../db.php';

$dados = json_decode(file_get_contents('php://input'), true);

$barbearia_id = (int)($dados['barbearia_id'] ?? 0);
$dia_semana   = isset($dados['dia_semana']) ? (int)$dados['dia_semana'] : -1;
$acao         = trim($dados['acao'] ?? '');

if ($barbearia_id <= 0 || $dia_semana < 0 || $dia_semana > 6) {
    echo json_encode(['erro' => 'Parâmetros inválidos.']);
    exit;
}

try {
    $db = \Database::getConnection();

    if ($acao === 'fechar') {
        // Se o barbeiro desligar o switch, remove o dia da tabela (dia fechado)
        $stmt = $db->prepare("DELETE FROM horarios_funcionamento WHERE barbearia_id = ? AND dia_semana = ?");
        $stmt->execute([$barbearia_id, $dia_semana]);
        echo json_encode(['sucesso' => true]);
        exit;
    }

    // Coleta as horas informadas
    $abertura = trim($dados['hora_abertura'] ?? '');
    $fechamento = trim($dados['hora_fechamento'] ?? '');
    $almoco_ini = !empty($dados['hora_almoco_inicio']) ? trim($dados['hora_almoco_inicio']) : null;
    $almoco_fim = !empty($dados['hora_almoco_fim']) ? trim($dados['hora_almoco_fim']) : null;

    if (empty($abertura) || empty($fechamento)) {
        echo json_encode(['erro' => 'Abertura e Fechamento são obrigatórios.']);
        exit;
    }

    // ON DUPLICATE KEY UPDATE: Salva se for novo, atualiza se já existir
    $stmt = $db->prepare("
        INSERT INTO horarios_funcionamento 
        (barbearia_id, dia_semana, hora_abertura, hora_fechamento, hora_almoco_inicio, hora_almoco_fim) 
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        hora_abertura = VALUES(hora_abertura),
        hora_fechamento = VALUES(hora_fechamento),
        hora_almoco_inicio = VALUES(hora_almoco_inicio),
        hora_almoco_fim = VALUES(hora_almoco_fim)
    ");
    $stmt->execute([$barbearia_id, $dia_semana, $abertura, $fechamento, $almoco_ini, $almoco_fim]);

    echo json_encode(['sucesso' => true]);

} catch (Exception $e) {
    echo json_encode(['erro' => 'Erro operacional no banco: ' . $e->getMessage()]);
}
