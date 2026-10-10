<?php
// cron/lembrete_whatsapp.php

date_default_timezone_set('America/Sao_Paulo');
set_time_limit(120);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/agenda.php';

$api_url_base = 'http://127.0.0.1:8080';
$api_key = getenv('EVOLUTION_API_KEY');

if (!$api_key) {
    exit("ERRO: EVOLUTION_API_KEY não configurada.\n");
}

try {
    $db = \Database::getConnection();

    // Agendamentos entre 50 e 60 minutos à frente.
    $agora = new DateTimeImmutable('now');

    $inicio = $agora->modify('+50 minutes');
    $fim = $agora->modify('+60 minutes');

    $sql = "
        SELECT
            a.id,
            c.nome AS cliente_nome,
            c.whatsapp AS cliente_whats,
            b.nome AS barbearia_nome,
            b.slug AS barbearia_slug,
            a.data_agendamento,
            a.horario_inicio
        FROM agendamentos a
        JOIN clientes c ON a.cliente_id = c.id
        JOIN barbearias b ON a.barbearia_id = b.id
        WHERE TIMESTAMP(
            a.data_agendamento,
            a.horario_inicio
        ) >= ?
        AND TIMESTAMP(
            a.data_agendamento,
            a.horario_inicio
        ) < ?
        AND a.status = 'agendado'
        AND a.notificacao_enviada = 0
    ";

    $stmt = $db->prepare($sql);

    $stmt->execute([
        $inicio->format('Y-m-d H:i:s'),
        $fim->format('Y-m-d H:i:s')
    ]);

    $agendamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$agendamentos) {
        echo "Nenhum lembrete pendente.\n";
        exit;
    }

    foreach ($agendamentos as $ag) {

        $numero = preg_replace('/\D+/', '', $ag['cliente_whats']);

        if (!$numero) {
            echo "Agendamento {$ag['id']}: telefone vazio.\n";
            continue;
        }

        $instancia = 'barber_' . $ag['barbearia_slug'];

        $horario = substr($ag['horario_inicio'], 0, 5);

        $mensagem =
            "Olá, *{$ag['cliente_nome']}*! ✂️\n\n" .
            "Passando para lembrar que seu horário na " .
            "*{$ag['barbearia_nome']}* está confirmado " .
            "hoje às *{$horario}*.\n\n" .
            "Se precisar reagendar ou cancelar, " .
            "entre em contato com a barbearia.";

        $payload = json_encode([
            'number' => $numero,
            'text' => $mensagem
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $url = $api_url_base .
            '/message/sendText/' .
            rawurlencode($instancia);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'apikey: ' . $api_key
            ]
        ]);

        $resposta = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erro = curl_error($ch);

        curl_close($ch);

        $dados = json_decode($resposta ?: '', true);

        $aceito = in_array($http_code, [200, 201], true)
            && isset($dados['key']['id']);

        if ($aceito) {

            $update = $db->prepare("
                UPDATE agendamentos
                SET notificacao_enviada = 1
                WHERE id = ?
            ");

            $update->execute([$ag['id']]);

            echo "Lembrete aceito pela API: agendamento {$ag['id']}\n";

        } else {

            echo "ERRO no agendamento {$ag['id']}\n";
            echo "HTTP: {$http_code}\n";
            echo "Detalhes: " . ($erro ?: $resposta) . "\n";
        }
    }

} catch (Throwable $e) {
    error_log('Erro no cron WhatsApp: ' . $e->getMessage());
    echo "Falha ao executar os lembretes. Consulte o log.\n";
}