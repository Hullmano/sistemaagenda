<?php
// api/webhook_whatsapp.php
// Primeira etapa: recebimento e validacao dos eventos.
// Nenhuma mensagem sera enviada nesta versao.

header('Content-Type: application/json; charset=utf-8');

function responderWebhook(int $codigo, array $dados): void {
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responderWebhook(405, ['erro' => 'Metodo nao permitido.']);
}

try {
    require_once __DIR__ . '/../config.php';

    $config = agendaConfig();

    // Segredo exclusivo para autenticar chamadas ao webhook.
    // Nao utilizar a chave global da Evolution API.
    $segredo = (string) ($config['whatsapp_webhook_secret'] ?? '');

    if (strlen($segredo) < 32) {
        responderWebhook(503, ['erro' => 'Webhook nao configurado.']);
    }

    $recebido = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';

    if (!is_string($recebido) ||
        !hash_equals($segredo, $recebido)) {
        responderWebhook(401, ['erro' => 'Nao autorizado.']);
    }

    $conteudo = file_get_contents('php://input');

    if ($conteudo === false || strlen($conteudo) > 65536) {
        responderWebhook(413, ['erro' => 'Conteudo invalido.']);
    }

    $evento = json_decode($conteudo, true);

    if (!is_array($evento)) {
        responderWebhook(400, ['erro' => 'JSON invalido.']);
    }

    // 1. Processa somente eventos de novas mensagens.
    $tipoEvento = strtolower(
        (string) ($evento['event'] ?? '')
    );

    if ($tipoEvento !== 'messages.upsert') {
        responderWebhook(200, [
            'sucesso' => true,
            'processado' => false,
            'motivo' => 'evento_ignorado'
        ]);
    }

    // 2. Verifica os dados basicos da mensagem.
    $instancia = $evento['instance'] ?? null;
    $dadosMensagem = $evento['data'] ?? null;

    if (
        !is_string($instancia) ||
        !preg_match('/^barber_[a-z0-9-]+$/', $instancia) ||
        !is_array($dadosMensagem) ||
        !isset($dadosMensagem['key']) ||
        !is_array($dadosMensagem['key'])
    ) {
        responderWebhook(200, [
            'sucesso' => true,
            'processado' => false,
            'motivo' => 'dados_invalidos'
        ]);
    }

    $chaveMensagem = $dadosMensagem['key'];

    // Diagnostico temporario dos identificadores @lid.
    // Registra somente nomes de campos, nunca seus valores.
    if (($config['whatsapp_diagnostico_lid'] ?? false) === true) {

        $jidDiagnostico = $chaveMensagem['remoteJid'] ?? null;

        if (
            is_string($jidDiagnostico) &&
            str_ends_with($jidDiagnostico, '@lid')
        ) {
            $filtrarCampos = static function (array $dados): array {
                return array_slice(
                    array_values(
                        array_filter(
                            array_keys($dados),
                            static fn($campo) =>
                                is_string($campo) &&
                                preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $campo)
                        )
                    ),
                    0,
                    30
                );
            };

            error_log(
                'SAUDACAO_LID_ESTRUTURA ' .
                json_encode([
                    'campos_data' => $filtrarCampos($dadosMensagem),
                    'campos_key' => $filtrarCampos($chaveMensagem)
                ])
            );
        }
    }

    // 3. Ignora mensagens enviadas pela propria barbearia.
    if (($chaveMensagem['fromMe'] ?? null) !== false) {
        responderWebhook(200, [
            'sucesso' => true,
            'processado' => false,
            'motivo' => 'mensagem_propria_ou_invalida'
        ]);
    }

    $remetente = $chaveMensagem['remoteJid'] ?? null;

    // 4. Aceita somente identificadores telefonicos individuais.
    // Grupos (@g.us) e identificadores @lid nao sao tratados.
    if (
        !is_string($remetente) ||
        !preg_match('/^[0-9]+@s\.whatsapp\.net$/', $remetente)
    ) {
        responderWebhook(200, [
            'sucesso' => true,
            'processado' => false,
            'motivo' => 'remetente_nao_suportado'
        ]);
    }

    // 5. Identifica o numero do cliente.
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../helpers/telefone.php';

    $numero = explode('@', $remetente, 2)[0];
    $whatsapp = normalizarWhatsappBR($numero);

    if ($whatsapp === null) {
        responderWebhook(200, [
            'sucesso' => true,
            'processado' => false,
            'motivo' => 'telefone_invalido'
        ]);
    }

    // 6. Identifica a barbearia pela instancia.
    $slug = substr($instancia, strlen('barber_'));

    $db = Database::getConnection();

    $stmt = $db->prepare("
        SELECT id, nome, slug
        FROM barbearias
        WHERE slug = ?
          AND ativo = 1
        LIMIT 1
    ");

    $stmt->execute([$slug]);
    $barbearia = $stmt->fetch();

    if (!$barbearia) {
        responderWebhook(200, [
            'sucesso' => true,
            'processado' => false,
            'motivo' => 'barbearia_nao_encontrada'
        ]);
    }
    // Autoriza testes somente para a barbearia
    // e o telefone definidos na configuracao privada.
    require_once __DIR__ . '/../helpers/autorizacao_saudacao.php';

    $envioRealAutorizado = envioSaudacaoAutorizado(
        $config,
        $whatsapp,
        (int) $barbearia['id']
    );

    // 7. Busca o cliente somente dentro da barbearia.
    $stmt = $db->prepare("
        SELECT nome
        FROM clientes
        WHERE barbearia_id = ?
          AND whatsapp = ?
        LIMIT 1
    ");

    $stmt->execute([
        (int) $barbearia['id'],
        $whatsapp
    ]);

    $cliente = $stmt->fetch();

    // 8. Prepara a saudacao personalizada.
    // Nesta etapa, nao envia mensagens e nao grava no banco.
    require_once __DIR__ . '/../helpers/saudacao_whatsapp.php';

    $appUrl = rtrim((string) ($config['app_url'] ?? ''), '/');

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
    // 8. Monta a saudacao personalizada.
    // Nenhuma mensagem e enviada nesta etapa.
    $mensagem = montarSaudacaoWhatsapp(
        (string) $barbearia['nome'],
        (string) $barbearia['slug'],
        $cliente ? (string) $cliente['nome'] : null,
        $appUrl
    );

    // 9. Protecao durante o desenvolvimento.
    // Enquanto o envio real nao estiver implementado,
    // o webhook funciona somente em modo diagnostico.
    $modoDiagnostico = $config['whatsapp_saudacao_diagnostico'] ?? false;

    // Teste real permitido somente com ativacao explicita
    // e telefone previamente autorizado.
    $modoTesteControlado = $envioRealAutorizado;

    // Se nenhum modo estiver habilitado, desativa a saudacao.
    if ($modoDiagnostico !== true && !$modoTesteControlado) {
        // Saudacao automatica ainda nao foi ativada.
        // Responde 200 para evitar tentativas repetidas
        // de entrega do mesmo evento.
        responderWebhook(200, [
            'sucesso' => true,
            'processado' => false,
            'motivo' => 'saudacao_desativada'
        ]);
    }


    // 10. Carrega o controle transacional de saudacoes.
    // Em diagnostico, nao executamos nenhuma reserva.
    require_once __DIR__ . '/../helpers/controle_saudacao.php';

    if (!function_exists('reservarSaudacaoWhatsapp')) {
        throw new RuntimeException(
            'Controle de saudacoes indisponivel.'
        );
    }

    $stmt = $db->prepare("
        SELECT
            status,
            ultima_saudacao_em,
            NOW() AS agora
        FROM whatsapp_saudacoes
        WHERE barbearia_id = ?
          AND whatsapp = ?
        LIMIT 1
    ");

    $stmt->execute([
        (int) $barbearia['id'],
        $whatsapp
    ]);

    $controle = $stmt->fetch();

    $saudacaoLiberada = false;

    if (!$controle) {
        // Cliente sem historico de saudacao.
        $saudacaoLiberada = true;
    } elseif (!in_array(
        $controle['status'],
        ['enviando', 'incerto'],
        true
    )) {
        // Um envio confirmado sem data exige verificacao.
        if (
            $controle['status'] !== 'enviado' ||
            $controle['ultima_saudacao_em'] !== null
        ) {
            $fuso = new DateTimeZone('America/Sao_Paulo');

            $agora = new DateTimeImmutable(
                $controle['agora'],
                $fuso
            );

            $saudacaoLiberada = intervaloSaudacaoLiberado(
                $controle['ultima_saudacao_em'],
                $agora
            );
        }
    }

    // 11. Executa o coordenador em modo de diagnostico.
    // Nenhuma reserva ou mensagem sera enviada.
    require_once __DIR__ . '/../helpers/processamento_saudacao.php';

    // Diagnostico tem prioridade sobre o teste controlado.
    // Fora do diagnostico, somente um telefone autorizado
    // podera prosseguir para o processamento.
    $diagnosticoEfetivo = $modoDiagnostico === true;

    // Liberacao final controlada por configuracao privada.
    // Exige telefone, barbearia e autorizacao explicitos.
    $liberacaoFinalEnvio =
        ($config['whatsapp_saudacao_liberacao_final'] ?? false) === true
        && $modoTesteControlado
        && !$diagnosticoEfetivo;

    // Configuracoes privadas da Evolution API.
    // Carregadas somente para o telefone autorizado
    // e fora do modo diagnostico.
    $apiUrlBase = '';
    $apiKey = '';

    if (!$diagnosticoEfetivo && $modoTesteControlado) {
        $apiUrlBase = rtrim(
            (string) ($config['evolution_url'] ?? ''),
            '/'
        );

        $apiKey = trim(
            (string) ($config['evolution_api_key'] ?? '')
        );
    }

    $resultadoProcessamento = processarSaudacaoWhatsapp(
        $db,
        (int) $barbearia['id'],
        $whatsapp,
        $mensagem,
        $apiUrlBase,
        $instancia,
        $apiKey,
        $diagnosticoEfetivo,
        $liberacaoFinalEnvio && $modoTesteControlado,
        $config
    );

    // Interpreta o resultado sem expor dados do cliente.
    if ($diagnosticoEfetivo) {
        if (
            ($resultadoProcessamento['status'] ?? null) !== 'diagnostico' ||
            ($resultadoProcessamento['processado'] ?? null) !== false
        ) {
            throw new RuntimeException(
                'Resultado inesperado do diagnostico.'
            );
        }

        responderWebhook(200, [
            'sucesso' => true,
            'processado' => false,
            'motivo' => 'diagnostico_concluido'
        ]);
    }

    // Interpreta os resultados do processamento
    // sem revelar informacoes do cliente.
    require_once __DIR__ . '/../helpers/resposta_saudacao.php';

    $resposta = respostaProcessamentoSaudacao(
        $resultadoProcessamento
    );

    responderWebhook(200, $resposta);

} catch (Throwable $e) {
    error_log('Falha no recebimento do webhook WhatsApp.');
    responderWebhook(500, ['erro' => 'Falha interna.']);
}