<?php
// cron/lembrete_whatsapp.php

date_default_timezone_set('America/Sao_Paulo');
set_time_limit(120);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/agenda.php';
require_once __DIR__ . '/../helpers/token_consulta.php';
require_once __DIR__ . '/../config.php';

$config = agendaConfig();

// Modo de diagnóstico: não envia mensagens reais.
$whatsapp_diagnostico = filter_var(
    $config['whatsapp_diagnostico'] ?? true,
    FILTER_VALIDATE_BOOLEAN
);

$appUrl = rtrim($config['app_url'] ?? '', '/');

if (
    !filter_var($appUrl, FILTER_VALIDATE_URL) ||
    !in_array(parse_url($appUrl, PHP_URL_SCHEME), ['http', 'https'], true)
) {
    throw new RuntimeException(
        'Endereço público do sistema não configurado.'
    );
}

$api_url_base = 'http://127.0.0.1:8080';
$api_key = getenv('EVOLUTION_API_KEY');

if (!$whatsapp_diagnostico && !$api_key) {
    exit("ERRO: EVOLUTION_API_KEY não configurada.\n");
}

try {
    $db = \Database::getConnection();

    // Enviar lembrete para qualquer agendamento futuro nos proximos 60 minutos.
    // Inclui agendamentos criados com apenas 15 minutos de antecedencia.
    // Horário de referência para os lembretes configuráveis.
    $agora = new DateTimeImmutable(
        'now',
        new DateTimeZone('America/Sao_Paulo')
    );

    $sql = "
        SELECT
            a.id,
            a.token_consulta_hash,
            a.token_consulta_cifrado,
            COALESCE(NULLIF(a.nome_cliente, ''), c.nome) AS cliente_nome,
            c.whatsapp AS cliente_whats,
            b.nome AS barbearia_nome,
            b.slug AS barbearia_slug,
            b.antecedencia_cancelamento_minutos,
            a.data_agendamento,
            a.horario_inicio
        FROM agendamentos a
        JOIN clientes c
            ON c.id = a.cliente_id
        AND c.barbearia_id = a.barbearia_id
        JOIN barbearias b
            ON b.id = a.barbearia_id
        WHERE a.status = 'agendado'
        AND a.notificacao_enviada = 0
        AND a.notificacao_tentada_em IS NULL
        AND b.ativo = 1

        -- O horário programado do lembrete já chegou.
        AND TIMESTAMP(
            a.data_agendamento,
            a.horario_inicio
        ) <= DATE_ADD(
            ?,
            INTERVAL (
                b.antecedencia_cancelamento_minutos + 5
            ) MINUTE
        )

        -- O prazo de cancelamento ainda não terminou.
        AND TIMESTAMP(
            a.data_agendamento,
            a.horario_inicio
        ) > DATE_ADD(
            ?,
            INTERVAL b.antecedencia_cancelamento_minutos MINUTE
        )
    ";

    $stmt = $db->prepare($sql);

    $stmt->execute([
        $agora->format('Y-m-d H:i:s'),
        $agora->format('Y-m-d H:i:s')
    ]);

    $agendamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$agendamentos) {
        echo "Nenhum lembrete pendente.\n";
        exit;
    }

    foreach ($agendamentos as $ag) {

        // Recupera o token necessário para montar o link individual.
        $tokenRecuperado = null;

        if (!empty($ag['token_consulta_cifrado'])) {
            $tokenRecuperado = decifrarTokenConsulta(
                $ag['token_consulta_cifrado']
            );
        }

        // Confirma que o token recuperado corresponde ao hash salvo.
        $tokenValido = (
            $tokenRecuperado !== null &&
            !empty($ag['token_consulta_hash']) &&
            hash_equals(
                $ag['token_consulta_hash'],
                hash('sha256', $tokenRecuperado)
            )
        );

        if (!$tokenValido) {
            // Agendamentos antigos podem não possuir token cifrado.
            // Não registrar tokens ou links individuais nos logs.
            echo "Agendamento {$ag['id']}: link individual indisponível.\n";
            continue;
        }

        // Monta o link individual de consulta e cancelamento.
        $linkConsulta = $appUrl
            . '/agenda/'
            . rawurlencode($ag['barbearia_slug'])
            . '/agendamento/'
            . $tokenRecuperado;

        require_once __DIR__ . '/../helpers/telefone.php';
        $numero = normalizarWhatsappBR($ag['cliente_whats']);

        if ($numero === null) {
            echo "Agendamento {$ag['id']}: telefone vazio.\n";
            continue;
        }

        $instancia = 'barber_' . $ag['barbearia_slug'];

        // Verifica se o WhatsApp da barbearia está conectado.

        if (!$whatsapp_diagnostico) {

        $url_status = $api_url_base .
            '/instance/connectionState/' .
            rawurlencode($instancia);

        $ch_status = curl_init($url_status);

        curl_setopt_array($ch_status, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . $api_key
            ]
        ]);

        $resposta_status = curl_exec($ch_status);
        $http_status = curl_getinfo($ch_status, CURLINFO_HTTP_CODE);
        $erro_status = curl_error($ch_status);

        curl_close($ch_status);

        $dados_status = json_decode($resposta_status ?: '', true);

        $estado = $dados_status['instance']['state']
            ?? $dados_status['status']
            ?? '';

        if (
            $http_status !== 200 ||
            !in_array($estado, ['open', 'connected'], true)
        ) {
            echo "Agendamento {$ag['id']}: WhatsApp não disponível "
                . "na instância {$instancia}. "
                . ($erro_status ?: "Estado: {$estado}")
                . "\n";

            continue;
        }
        }
        $horario = substr($ag['horario_inicio'], 0, 5);

        // Calcula o prazo de cancelamento da barbearia.
        $antecedencia = (int) $ag[
            'antecedencia_cancelamento_minutos'
        ];

        if (!in_array($antecedencia, [30, 60], true)) {
            $antecedencia = 30;
        }

        $dataHoraAtendimento = new DateTimeImmutable(
            $ag['data_agendamento'] . ' ' . $ag['horario_inicio'],
            new DateTimeZone('America/Sao_Paulo')
        );

        $limiteCancelamento = $dataHoraAtendimento->modify(
            '-' . $antecedencia . ' minutes'
        );

        $horarioLimite = $limiteCancelamento->format('H:i');


        // Identificar o dia do atendimento
        $dataAtendimento = new DateTimeImmutable(
            $ag['data_agendamento']
        );

        $hoje = new DateTimeImmutable('today');

        if ($dataAtendimento->format('Y-m-d') === $hoje->format('Y-m-d')) {
            $diaAtendimento = 'hoje';
        } elseif (
            $dataAtendimento->format('Y-m-d') ===
            $hoje->modify('+1 day')->format('Y-m-d')
        ) {
            $diaAtendimento = 'amanhã';
        } else {
            $diaAtendimento = 'no dia ' . $dataAtendimento->format('d/m/Y');
        }

        // Montar mensagem do WhatsApp
        $mensagem =
            "Olá, *{$ag['cliente_nome']}*! ✂️\n\n" .
            "Lembrando do seu horário na *{$ag['barbearia_nome']}*:\n\n" .
            "📅 {$diaAtendimento} às *{$horario}*\n" .
            "⏰ Cancelamento até *{$horarioLimite}*.\n\n" .
            "🔗 Consulte ou cancele:\n" .
            $linkConsulta;

        // Confirma novamente que o agendamento continua ativo.
        $stmtStatus = $db->prepare("
            SELECT status, notificacao_enviada
            FROM agendamentos
            WHERE id = ?
            LIMIT 1
        ");

        $stmtStatus->execute([(int)$ag['id']]);

        $statusAtual = $stmtStatus->fetch(PDO::FETCH_ASSOC);

        if (
            !$statusAtual ||
            $statusAtual['status'] !== 'agendado' ||
            (int)$statusAtual['notificacao_enviada'] === 1
        ) {
            echo "Agendamento {$ag['id']}: lembrete ignorado.\n";
            continue;
        }

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

        // Modo de diagnóstico: não altera o banco e não envia WhatsApp.
if ($whatsapp_diagnostico) {
    curl_close($ch);

    echo "DIAGNOSTICO: agendamento {$ag['id']}.\n";
    echo "Lembrete seria enviado. Nenhum dado alterado.\n";

    continue;
}

        // Reserva atomicamente a tentativa antes do envio.
        // Impede que outra execução envie o mesmo lembrete.
        $stmtTentativa = $db->prepare("
            UPDATE agendamentos
            SET notificacao_tentada_em = NOW()
            WHERE id = ?
            AND status = 'agendado'
            AND notificacao_enviada = 0
            AND notificacao_tentada_em IS NULL
        ");

        $stmtTentativa->execute([(int)$ag['id']]);

        if ($stmtTentativa->rowCount() !== 1) {
            curl_close($ch);

            echo "Agendamento {$ag['id']}: "
                . "lembrete ignorado ou ja reservado.\n";

            continue;
        }

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

            echo "ATENCAO: envio nao confirmado para o agendamento {$ag['id']}.\n";
            echo "HTTP: {$http_code}\n";
            echo "Detalhes: " . ($erro ?: $resposta ?: 'Sem resposta da API') . "\n";
            echo "Tentativa registrada no banco. Reenvio automatico bloqueado.\n";
            echo "Verificar o resultado na Evolution API antes de liberar nova tentativa.\n";
        }
    }

} catch (Throwable $e) {
    error_log('Erro no cron WhatsApp: ' . $e->getMessage());
    echo "Falha ao executar os lembretes. Consulte o log.\n";
}