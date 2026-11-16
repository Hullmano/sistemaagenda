
<?php
// cron/confirmacao_whatsapp.php
// Confirmacao automatica de agendamentos pelo WhatsApp.

date_default_timezone_set('America/Sao_Paulo');
set_time_limit(120);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../helpers/token_consulta.php';
require_once __DIR__ . '/../helpers/telefone.php';

$db = null;
$bloqueioObtido = false;

try {

    $config = agendaConfig();

    // Diagnostico ativado por padrao.
    // Somente um booleano false permite envio real.
    $modoDiagnostico = $config['whatsapp_diagnostico'] ?? true;

    if (!is_bool($modoDiagnostico)) {
        $modoDiagnostico = true;
    }

    $appUrl = rtrim(
        (string) ($config['app_url'] ?? ''),
        '/'
    );

    if (
        !filter_var($appUrl, FILTER_VALIDATE_URL) ||
        !in_array(
            parse_url($appUrl, PHP_URL_SCHEME),
            ['http', 'https'],
            true
        )
    ) {
        throw new RuntimeException(
            'Endereco publico do sistema nao configurado.'
        );
    }

    // Configuracoes da Evolution API.
    $apiUrlBase = rtrim(
        (string) ($config['evolution_url'] ?? ''),
        '/'
    );

    $apiKey = trim(
        (string) ($config['evolution_api_key'] ?? '')
    );

    // Credenciais obrigatorias somente no envio real.
    if (!$modoDiagnostico) {

        // Links com token devem usar HTTPS em producao.
        if (parse_url($appUrl, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException(
                'Envio real bloqueado: app_url deve usar HTTPS.'
            );
        }

        if (
            !filter_var($apiUrlBase, FILTER_VALIDATE_URL) ||
            !in_array(
                parse_url($apiUrlBase, PHP_URL_SCHEME),
                ['http', 'https'],
                true
            ) ||
            $apiKey === ''
        ) {
            throw new RuntimeException(
                'Configuracao da Evolution API incompleta.'
            );
        }
    }

    $db = Database::getConnection();

    // Bloqueia execucoes simultaneas do cron.
    $stmtLock = $db->query("
        SELECT GET_LOCK('agenda_confirmacao_whatsapp', 0)
    ");

    $bloqueioObtido = (int) $stmtLock->fetchColumn() === 1;

    $stmtLock->closeCursor();

    if (!$bloqueioObtido) {

        echo "Outra execucao de confirmacoes ja esta em andamento.\n";

    } else {

        // Busca agendamentos elegiveis.
        $stmt = $db->prepare("
            SELECT
                a.id,
                COALESCE(
                    NULLIF(a.nome_cliente, ''),
                    c.nome
                ) AS cliente_nome,
                c.whatsapp AS cliente_whats,
                a.token_consulta_hash,
                a.token_consulta_cifrado,
                a.data_agendamento,
                a.horario_inicio,
                b.nome AS barbearia_nome,
                b.slug AS barbearia_slug

            FROM agendamentos a

            JOIN clientes c
                ON c.id = a.cliente_id
                AND c.barbearia_id = a.barbearia_id

            JOIN barbearias b
                ON b.id = a.barbearia_id

            WHERE a.status = 'agendado'
              AND b.ativo = 1
              AND a.confirmacao_enviada = 0
              AND a.confirmacao_tentativas < 3

              AND (
                  a.confirmacao_processando_em IS NULL
                  OR a.confirmacao_processando_em <= DATE_SUB(
                      NOW(),
                      INTERVAL 5 MINUTE
                  )
              )

              AND a.token_consulta_cifrado IS NOT NULL
              AND a.token_consulta_cifrado <> ''

              AND TIMESTAMP(
                  a.data_agendamento,
                  a.horario_inicio
              ) > NOW()

            ORDER BY a.id ASC
            LIMIT 10
        ");

        $stmt->execute();

        $agendamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "Confirmacoes pendentes: "
            . count($agendamentos) . PHP_EOL;

        foreach ($agendamentos as $ag) {

            $id = (int) $ag['id'];
            $execucaoId = bin2hex(random_bytes(16));
            $reservaPersistida = false;
            $envioIniciado = false;

            try {

                // 1. Recupera e valida o token.
                $token = decifrarTokenConsulta(
                    $ag['token_consulta_cifrado']
                );

                if (
                    $token === null ||
                    empty($ag['token_consulta_hash']) ||
                    !hash_equals(
                        $ag['token_consulta_hash'],
                        hash('sha256', $token)
                    )
                ) {
                    echo "Agendamento #{$id}: token invalido.\n";
                    continue;
                }

                // 2. Valida o telefone.
                $numero = normalizarWhatsappBR(
                    $ag['cliente_whats']
                );

                if ($numero === null) {
                    echo "Agendamento #{$id}: telefone invalido.\n";
                    continue;
                }

                // 3. Monta o link individual.
                $linkConsulta = $appUrl
                    . '/agenda/'
                    . rawurlencode($ag['barbearia_slug'])
                    . '/agendamento/'
                    . $token;

                // 4. Formata data e horario.
                $data = DateTimeImmutable::createFromFormat(
                    '!Y-m-d',
                    $ag['data_agendamento'],
                    new DateTimeZone('America/Sao_Paulo')
                );

                $dataFormatada = $data
                    ? $data->format('d/m/Y')
                    : $ag['data_agendamento'];

                $horario = substr(
                    $ag['horario_inicio'],
                    0,
                    5
                );

                // 5. Prepara a mensagem.
                $mensagem =
                    "Olá, *{$ag['cliente_nome']}*! ✂️\n\n" .
                    "Seu agendamento na *{$ag['barbearia_nome']}* " .
                    "foi confirmado! ✅\n\n" .
                    "📅 {$dataFormatada} às *{$horario}*\n\n" .
                    "🔗 Consulte ou cancele:\n" .
                    $linkConsulta;

                // 6. Identifica a instancia da barbearia.
                $instancia = 'barber_' . $ag['barbearia_slug'];

                // 7. No envio real, verifica a conexao antes
                // de consumir uma tentativa.
                if (!$modoDiagnostico) {

                    $urlStatus = $apiUrlBase
                        . '/instance/connectionState/'
                        . rawurlencode($instancia);

                    $chStatus = curl_init($urlStatus);

                    if ($chStatus === false) {
                        echo "Agendamento #{$id}: "
                            . "falha ao iniciar verificacao.\n";
                        continue;
                    }

                    curl_setopt_array($chStatus, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CONNECTTIMEOUT => 3,
                        CURLOPT_TIMEOUT => 5,
                        CURLOPT_HTTPHEADER => [
                            'apikey: ' . $apiKey
                        ]
                    ]);

                    $respostaStatus = curl_exec($chStatus);

                    $httpStatus = curl_getinfo(
                        $chStatus,
                        CURLINFO_HTTP_CODE
                    );

                    $erroStatus = curl_error($chStatus);

                    curl_close($chStatus);

                    $dadosStatus = json_decode(
                        $respostaStatus ?: '',
                        true
                    );

                    $estado = $dadosStatus['instance']['state']
                        ?? $dadosStatus['status']
                        ?? '';

                    if (
                        $httpStatus !== 200 ||
                        !in_array(
                            $estado,
                            ['open', 'connected'],
                            true
                        )
                    ) {
                        echo "Agendamento #{$id}: "
                            . "WhatsApp indisponivel. "
                            . ($erroStatus ?: "Estado: {$estado}")
                            . "\n";

                        continue;
                    }
                }

                // 8. Reserva atomica.
                // No diagnostico, a transacao sera desfeita.
                $reservaObtida = false;

                $db->beginTransaction();

                try {

                    $stmtReserva = $db->prepare("
                        UPDATE agendamentos
                            SET
                                confirmacao_processando_em = NOW(),
                                confirmacao_execucao_id = ?,
                                confirmacao_tentativas =
                                    confirmacao_tentativas + 1

                        WHERE id = ?
                          AND status = 'agendado'
                          AND confirmacao_enviada = 0
                          AND confirmacao_tentativas < 3

                          AND (
                              confirmacao_processando_em IS NULL
                              OR confirmacao_processando_em <= DATE_SUB(
                                  NOW(),
                                  INTERVAL 5 MINUTE
                              )
                          )
                    ");

                    $stmtReserva->execute([$execucaoId, $id]);

                    $reservaObtida =
                        $stmtReserva->rowCount() === 1;

                    if ($modoDiagnostico) {
                        $db->rollBack();
                    } else {
                        $db->commit();

                        if ($reservaObtida) {
                            $reservaPersistida = true;
                        }
                    }

                } catch (Throwable $e) {

                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }

                    throw $e;
                }

                if (!$reservaObtida) {
                    echo "Agendamento #{$id}: "
                        . "reserva indisponivel.\n";
                    continue;
                }

                // 9. Diagnostico: nao envia e nao grava tentativas.
                if ($modoDiagnostico) {

                    echo "Agendamento #{$id}: "
                        . "reserva atomica validada (simulacao).\n";

                    echo "Agendamento #{$id}: "
                        . "confirmacao pronta para envio (modo teste).\n";

                    continue;
                }

                // 10. Verifica novamente o status antes do envio.
                $stmtStatus = $db->prepare("
                    SELECT status, confirmacao_enviada
                    FROM agendamentos
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmtStatus->execute([$id]);

                $statusAtual = $stmtStatus->fetch(PDO::FETCH_ASSOC);

                if (
                    !$statusAtual ||
                    $statusAtual['status'] !== 'agendado' ||
                    (int) $statusAtual['confirmacao_enviada'] === 1
                ) {

                    // Libera a reserva e devolve a tentativa,
                    // pois nenhuma mensagem foi enviada.
                    $stmtLiberar = $db->prepare("
                        UPDATE agendamentos
                        SET
                            confirmacao_processando_em = NULL,
                            confirmacao_execucao_id = NULL,
                            confirmacao_tentativas = GREATEST(
                                confirmacao_tentativas - 1,
                                0
                            )
                        WHERE id = ?
                        AND confirmacao_execucao_id = ?
                        AND confirmacao_enviada = 0
                    ");

                    $stmtLiberar->execute([$id, $execucaoId]);

                    $reservaPersistida = false;

                    echo "Agendamento #{$id}: "
                        . "envio cancelado, reserva liberada.\n";

                    continue;
                }

                // 11. Prepara o envio pela Evolution API.
                $payload = json_encode([
                    'number' => $numero,
                    'text' => $mensagem
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                $urlEnvio = $apiUrlBase
                    . '/message/sendText/'
                    . rawurlencode($instancia);

                $ch = curl_init($urlEnvio);

                if ($ch === false) {
                    throw new RuntimeException(
                        'Falha ao iniciar envio da mensagem.'
                    );
                }

                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'apikey: ' . $apiKey
                    ]
                ]);

                // A partir daqui, uma falha pode ocorrer apos a API
                // ter recebido a mensagem.
                $envioIniciado = true;
                $resposta = curl_exec($ch);

                $httpCode = curl_getinfo(
                    $ch,
                    CURLINFO_HTTP_CODE
                );

                $erroEnvio = curl_error($ch);

                curl_close($ch);

                $dadosResposta = json_decode(
                    $resposta ?: '',
                    true
                );

                $aceito = in_array(
                    $httpCode,
                    [200, 201],
                    true
                ) && !empty($dadosResposta['key']['id']);

                // Uma falha de transporte pode ocorrer depois que
                // a Evolution API ja recebeu a mensagem.
                $respostaIncerta = $resposta === false
                    || $httpCode >= 500
                    || $httpCode === 400
                    || (
                        in_array($httpCode, [200, 201], true)
                        && !$aceito
                    );

                // 12. Registra o resultado.
                if ($aceito) {

                    $stmtSucesso = $db->prepare("
                        UPDATE agendamentos
                        SET
                            confirmacao_enviada = 1,
                            confirmacao_processando_em = NULL,
                            confirmacao_execucao_id = NULL
                        WHERE id = ?
                        AND confirmacao_execucao_id = ?
                        AND status = 'agendado'
                        AND confirmacao_enviada = 0
                    ");

                    $stmtSucesso->execute([$id, $execucaoId]);

                    $reservaPersistida = false;

                    if ($stmtSucesso->rowCount() === 1) {

                        echo "Agendamento #{$id}: "
                            . "confirmacao aceita pela API.\n";

                    } else {

                        echo "Agendamento #{$id}: "
                            . "API aceitou, mas o registro precisa "
                            . "de verificacao no banco.\n";
                    }

                } else {

                    // Se a API pode ter recebido a mensagem,
                    // nao realizamos novas tentativas automaticamente.
                    if ($respostaIncerta) {

                        $stmtIncerto = $db->prepare("
                            UPDATE agendamentos
                            SET
                                confirmacao_tentativas = 3,
                                confirmacao_processando_em = NULL,
                                confirmacao_execucao_id = NULL
                            WHERE id = ?
                            AND confirmacao_execucao_id = ?
                            AND status = 'agendado'
                            AND confirmacao_enviada = 0
                        ");

                        $stmtIncerto->execute([$id, $execucaoId]);

                        $reservaPersistida = false;

                        if ($httpCode === 400) {
                            error_log(
                                "Confirmacao rejeitada (HTTP 400) no agendamento #{$id}. "
                                . "Revisar os dados da requisicao antes de liberar novo envio."
                            );

                            echo "Agendamento #{$id}: "
                                . "requisicao rejeitada (HTTP 400); "
                                . "reenvio automatico bloqueado.\n";
                        } else {
                            error_log(
                                "Confirmacao com resultado incerto no agendamento #{$id}. "
                                . "Verificar manualmente na Evolution API antes de reenviar."
                            );

                            echo "Agendamento #{$id}: "
                                . "resultado incerto; "
                                . "reenvio automatico bloqueado.\n";
                        }

                        continue;
                    }

                    // Mantem o horario da tentativa para que
                    // a proxima tentativa ocorra apos 5 minutos.
                    $stmtFalha = $db->prepare("
                        UPDATE agendamentos
                        SET confirmacao_processando_em = NOW()
                        WHERE id = ?
                        AND confirmacao_execucao_id = ?
                        AND status = 'agendado'
                        AND confirmacao_enviada = 0
                    ");

                    $stmtFalha->execute([$id, $execucaoId]);

                    $reservaPersistida = false;

                    echo "Agendamento #{$id}: falha no envio.\n";
                    echo "HTTP: {$httpCode}\n";

                    if ($erroEnvio !== '') {
                        echo "Detalhes: {$erroEnvio}\n";
                    }
                }

            } catch (Throwable $e) {

                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                // Depois do inicio do HTTP, uma excecao tem
                // resultado potencialmente incerto: nao reenviar.
                // Antes do HTTP, conserva a recuperacao de 5 minutos.
                if ($reservaPersistida && !$modoDiagnostico) {

                    try {

                        if ($envioIniciado) {
                            $stmtRecuperacao = $db->prepare("
                                UPDATE agendamentos
                                SET confirmacao_tentativas = 3,
                                    confirmacao_processando_em = NULL,
                                    confirmacao_execucao_id = NULL
                                WHERE id = ?
                                  AND confirmacao_execucao_id = ?
                                  AND status = 'agendado'
                                  AND confirmacao_enviada = 0
                            ");
                        } else {
                            $stmtRecuperacao = $db->prepare("
                                UPDATE agendamentos
                                SET confirmacao_processando_em = NOW()
                                WHERE id = ?
                                  AND confirmacao_execucao_id = ?
                                  AND status = 'agendado'
                                  AND confirmacao_enviada = 0
                            ");
                        }

                        $stmtRecuperacao->execute([$id, $execucaoId]);

                        if ($envioIniciado) {
                            error_log(
                                'Confirmacao com resultado incerto no agendamento #'
                                . $id . '. Reenvio automatico bloqueado se '
                                . 'a atualizacao foi aplicada. Conferir na API.'
                            );
                        }

                    } catch (Throwable $erroRecuperacao) {

                        error_log(
                            'Falha ao atualizar recuperacao do agendamento '
                            . $id . ': '
                            . $erroRecuperacao->getMessage()
                        );
                    }
                }

                error_log(
                    'Erro na confirmacao do agendamento '
                    . $id . ': '
                    . $e->getMessage()
                );

                echo "Agendamento #{$id}: "
                    . "falha no processamento. Consulte o log.\n";
            }
        }
    }

} catch (Throwable $e) {

    error_log(
        'Erro no cron de confirmacoes: '
        . $e->getMessage()
    );

    echo "Falha ao executar confirmacoes. Consulte o log.\n";

} finally {

    // Sempre tenta liberar o bloqueio global.
    if ($db instanceof PDO && $bloqueioObtido) {

        try {

            $stmtUnlock = $db->query("
                SELECT RELEASE_LOCK('agenda_confirmacao_whatsapp')
            ");

            $stmtUnlock->closeCursor();

        } catch (Throwable $e) {

            error_log(
                'Falha ao liberar bloqueio do cron: '
                . $e->getMessage()
            );
        }
    }
}
