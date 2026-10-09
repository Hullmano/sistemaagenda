<?php
// cron/lembrete_whatsapp.php
date_default_timezone_set('America/Sao_Paulo');
set_time_limit(0);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/agenda.php';

try {
    $db = \Database::getConnection();

    // 1. Define a janela temporal cirúrgica de 1 hora para frente
    $data_hoje = date('Y-m-d');
    $hora_inicio_janela = date('H:i:s', strtotime('+50 minutes'));
    $hora_fim_janela    = date('H:i:s', strtotime('+1 hour'));

    // 2. Coleta agendamentos pendentes injetando o slug dinâmico da barbearia
    $stmt = $db->prepare("
        SELECT 
            a.id, 
            c.nome AS cliente_nome, 
            c.whatsapp AS cliente_whats, 
            b.nome AS barbearia_nome, 
            b.slug AS barbearia_slug,
            a.horario_inicio
        FROM agendamentos a
        JOIN clientes c ON a.cliente_id = c.id
        JOIN barbearias b ON a.barbearia_id = b.id
        WHERE a.data_agendamento = ? 
          AND a.horario_inicio BETWEEN ? AND ?
          AND a.status = 'agendado'
          AND a.notificacao_enviada = 0
    ");
    $stmt->execute([$data_hoje, $hora_inicio_janela, $hora_fim_janela]);
    $agendamentos = $stmt->fetchAll();

    if (empty($agendamentos)) {
        echo "[" . date('Y-m-d H:i:s') . "] Nenhuma notificação pendente para disparar.\n";
        exit;
    }

    $api_url_base = "http://172.18.0.1:8080";
    $api_key_global = "mY@pikey"; // Sua chave de autenticação configurada no Docker

        // 3. Loop de disparos individuais
    foreach ($agendamentos as $ag) {
        $numero_whats = trim($ag['cliente_whats']);
        $instancia_nome = "barber_" . $ag['barbearia_slug'];
        $horario_formatado = date('H:i', strtotime($ag['horario_inicio']));

        // 1º: MONTA O TEXTO DA MENSAGEM
        $texto_mensagem = "Olá, *{$ag['cliente_nome']}*! ✂️\n\n";
        $texto_mensagem .= "Passando para lembrar que seu horário na *{$ag['barbearia_nome']}* está confirmado hoje às *{$horario_formatado}*.\n\n";
        $texto_mensagem .= "Se precisar reagendar ou cancelar, acesse o painel pelo link público. Te esperamos!";

        // 2º: CRIA O PAYLOAD QUE A EVOLUTION API EXIGE
        $payload = [
            "number" => $numero_whats,
            "text" => $texto_mensagem,
            "delay" => 1200,
            "linkPreview" => false
        ];

        // 3º: TRANSFORMA O PAYLOAD EM STRING JSON LIMPA
        $json_payload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // 4º: MONTA O COMANDO CURL DO LINUX (Usando 127.0.0.1 para falar com o Docker)
        $comando_linux = "curl -s -o /dev/null -w '%{http_code}' -X POST http://127.0.0{$instancia_nome} " .
                         "-H 'Content-Type: application/json' " .
                         "-H 'X-API-Key: {$api_key_global}' " .
                         "-H 'apikey: {$api_key_global}' " .
                         "-d " . escapeshellarg($json_payload);

        // 5º: EXECUTA O DISPARO DIRETO PELO TERMINAL DO UBUNTU
        $http_code = (int)exec($comando_linux);

        if ($http_code === 200 || $http_code === 201) {
            // Sucesso: Atualiza o status no banco de dados para marcar como enviado
            $stmtUpdate = $db->prepare("UPDATE agendamentos SET notificacao_enviada = 1 WHERE id = ?");
            $stmtUpdate->execute([$ag['id']]);
            
            echo "[" . date('H:i:s') . "] 🚀 Lembrete enviado com sucesso para: {$ag['cliente_nome']} ($numero_whats)\n";
        } else {
            // Falha: Mostra qual foi o retorno de erro da Evolution API
            echo "[" . date('H:i:s') . "] ❌ Falha no envio. Código de resposta da API: HTTP {$http_code}\n";
        }
    }

} catch (Exception $e) {
    echo "Erro crítico no Cron Job: " . $e->getMessage() . "\n";
}
