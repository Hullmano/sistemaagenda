<?php
// cron/lembrete_whatsapp.php

// FORÇA O ROBÔ CLI A USAR O HORÁRIO DE BRASÍLIA
date_default_timezone_set('America/Sao_Paulo');

// Como o Cron roda direto na linha de comando (CLI) do Linux, 
// removemos o limite de tempo de execução por segurança
set_time_limit(0);

require_once __DIR__ . '/../db.php';

try {
    $db = \Database::getConnection();

    // 1. Definição da janela de tempo (Buscar agendamentos daqui a exatamente 1 hora)
    // Se agora são 14:00, a janela buscará cortes agendados entre 14:50 e 15:00
    $data_hoje = date('Y-m-d');
    $hora_inicio_janela = date('H:i:s', strtotime('+50 minutes')); 
    $hora_fim_janela    = date('H:i:s', strtotime('+1 hour'));


    $stmt = $db->prepare("
        SELECT a.id, a.horario_inicio, c.nome AS cliente_nome, c.whatsapp AS cliente_whats, b.nome AS barbearia_nome
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

    // 2. Configurações da Evolution API (Substitua pelos dados do Docker no seu Droplet)
    $api_url = "http://127.0.0"; 
    $api_key = "sua_apikey_da_evolution_api";

    foreach ($agendamentos as $ag) {
        $numero_whats = $ag['cliente_whats'];
        $horario_formatado = date('H:i', strtotime($ag['horario_inicio']));
        
        // Texto personalizado comercial focado em conversão e redução de faltas
        $mensagem = "Olá, *{$ag['cliente_nome']}*! ✂️\n\n";
        $mensagem .= "Passando para lembrar que seu horário na *{$ag['barbearia_nome']}* está confirmado hoje às *{$horario_formatado}*.\n\n";
        $mensagem .= "Se tiver algum imprevisto, avise com antecedência. Estamos te esperando!";

        // Payload exigido pela Evolution API
        $payload = [
            "number" => $numero_whats,
            "options" => [
                "delay" => 1200,
                "presence" => "composing"
            ],
            "textMessage" => [
                "text" => $mensagem
            ]
        ];

        // Disparo via cURL (Mais performático no PHP para APIs externas)
            // ... código anterior do cURL igual (curl_init, curl_setopt, etc) ...
    
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // DEBUG SÊNIOR: Transforma a resposta em array para ler o erro real da v2.x
        $res_decodificada = json_decode($response, true);

        if ($http_code === 200 || $http_code === 201) {
            // Altere a query abaixo de acordo com o nome exato da sua coluna de controle
            $stmtUpdate = $db->prepare("UPDATE agendamentos SET notificacao_enviada = 1 WHERE id = ?");
            $stmtUpdate->execute([$ag['id']]);
            
            echo "[2026-10-08 Dinâmico] 🚀 Enviado com sucesso para: {$ag['cliente_nome']} ({$numero_whats})\n";
        } else {
            // Se der erro, o PHP vai cuspir EXATAMENTE o motivo do bloqueio na tela do terminal!
            echo "[ERRO HTTP {$http_code}] Falha ao disparar para {$ag['cliente_nome']}. Motivo: ";
            echo isset($res_decodificada['message']) ? json_encode($res_decodificada['message']) : $response;
            echo "\n";
        }

    }

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERRO NO CRON: " . $e->getMessage() . "\n";
}
