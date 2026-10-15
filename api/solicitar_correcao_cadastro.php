<?php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/telefone.php';
function resposta($codigo, $dados) {
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') resposta(405, ['erro'=>'Método não permitido.']);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) resposta(413, ['erro'=>'Dados muito grandes.']);
$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) resposta(400, ['erro'=>'Dados inválidos.']);
$id = filter_var($d['barbearia_id'] ?? null, FILTER_VALIDATE_INT);
$atual = normalizarWhatsappBR((string)($d['whatsapp_atual'] ?? ''));
$novo = normalizarWhatsappBR((string)($d['whatsapp_corrigido'] ?? ''));
$nome = trim((string)($d['nome_corrigido'] ?? ''));
$obs = trim((string)($d['observacao'] ?? ''));
if (!$id || $atual === null || $novo === null ||
    strlen($nome)<2 || strlen($nome)>120 || strlen($obs)>500) {
    resposta(400, ['erro'=>'Confira nome e números com DDD.']);
}
try {
    $db = Database::getConnection();
    $s=$db->prepare('SELECT id FROM barbearias WHERE id=? AND ativo=1');
    $s->execute([$id]);
    if (!$s->fetch()) resposta(404, ['erro'=>'Barbearia não encontrada.']);
    $s=$db->prepare("SELECT COUNT(*) FROM cliente_alteracoes WHERE barbearia_id=? AND whatsapp_informado=? AND criado_em >= NOW()-INTERVAL 1 HOUR");
    $s->execute([$id,$atual]);
    if ((int)$s->fetchColumn()>=3) resposta(429, ['erro'=>'Aguarde antes de enviar outra solicitação.']);
    $s=$db->prepare('SELECT id FROM clientes WHERE barbearia_id=? AND whatsapp IN (?, ?) LIMIT 1');
    $s->execute([$id,$atual,substr($atual, 2)]);
    $cliente=$s->fetchColumn() ?: null;
    $s=$db->prepare("INSERT INTO cliente_alteracoes (barbearia_id,cliente_id,whatsapp_informado,nome_solicitado,whatsapp_solicitado,observacao,status) VALUES (?,?,?,?,?,?,'pendente')");
    $s->execute([$id,$cliente,$atual,$nome,$novo,$obs]);
    resposta(200,['sucesso'=>true,'mensagem'=>'Solicitação recebida para análise da barbearia.']);
} catch (Throwable $e) {
    error_log('Erro solicitação cadastral: '.$e->getMessage());
    resposta(500,['erro'=>'Não foi possível enviar agora.']);
}
