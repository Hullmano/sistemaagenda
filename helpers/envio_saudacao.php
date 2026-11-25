<?php
// helpers/envio_saudacao.php

/**
 * Prepara os dados para envio de uma saudacao.
 * Nesta etapa, nao realiza chamadas HTTP.
 */
function prepararEnvioSaudacao(
    string $apiUrl,
    string $instancia,
    string $numero,
    string $mensagem
): array {

    $apiUrl = rtrim(trim($apiUrl), '/');

    if (
        !filter_var($apiUrl, FILTER_VALIDATE_URL) ||
        !in_array(
            parse_url($apiUrl, PHP_URL_SCHEME),
            ['http', 'https'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            'URL da Evolution API invalida.'
        );
    }

    if (!preg_match('/^barber_[a-z0-9-]+$/', $instancia)) {
        throw new InvalidArgumentException(
            'Instancia invalida.'
        );
    }

    if (!preg_match('/^55[0-9]{10,11}$/', $numero)) {
        throw new InvalidArgumentException(
            'Numero de WhatsApp invalido.'
        );
    }

    if (trim($mensagem) === '') {
        throw new InvalidArgumentException(
            'Mensagem vazia.'
        );
    }

    $url = $apiUrl
        . '/message/sendText/'
        . rawurlencode($instancia);

    $payload = json_encode([
        'number' => $numero,
        'text' => $mensagem
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return [
        'url' => $url,
        'payload' => $payload
    ];
}

/**
 * Classifica a resposta da Evolution API.
 *
 * Retornos:
 * - enviado: API confirmou o aceite.
 * - falhou: rejeicao definitiva conhecida.
 * - incerto: nao e seguro tentar novamente.
 */
function classificarRespostaSaudacao(
    int $httpCode,
    string|false $resposta
): string {

    if ($resposta === false) {
        return 'incerto';
    }

    $dados = json_decode($resposta, true);

    if (
        in_array($httpCode, [200, 201], true)
        && is_array($dados)
        && !empty($dados['key']['id'])
    ) {
        return 'enviado';
    }

    // Rejeicao explicita de autenticacao ou permissao.
    if (in_array($httpCode, [401, 403], true)) {
        return 'falhou';
    }

    // Demais respostas exigem verificacao antes do reenvio.
    // Inclui HTTP 400, erros 5xx e respostas inesperadas.
    return 'incerto';
}

/**
 * Executa o envio de uma saudacao pela Evolution API.
 *
 * Por seguranca, o envio real fica bloqueado
 * ate ser explicitamente autorizado.
 */
function executarEnvioSaudacao(
    string $url,
    string $payload,
    string $apiKey,
    bool $permitirEnvio = false
): array {

    // Protecao contra envios durante o desenvolvimento.
    if (!$permitirEnvio) {
        return [
            'status' => 'diagnostico',
            'http_code' => 0
        ];
    }

    if (trim($apiKey) === '') {
        throw new InvalidArgumentException(
            'Chave da Evolution API nao configurada.'
        );
    }

    $ch = curl_init($url);

    if ($ch === false) {
        return [
            'status' => 'falhou',
            'http_code' => 0
        ];
    }
    $httpIniciado = false;
    try {
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

        // A partir daqui, o resultado pode ser incerto.
        $httpIniciado = true;
        $resposta = curl_exec($ch);

        $httpCode = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        return [
            'status' => classificarRespostaSaudacao(
                $httpCode,
                $resposta
            ),
            'http_code' => $httpCode
        ];

    } catch (Throwable $e) {
        // Antes do HTTP: nenhuma mensagem foi enviada.
        // Depois do inicio: o resultado pode ser incerto.
        return [
            'status' => $httpIniciado ? 'incerto' : 'falhou',
            'http_code' => 0
        ];

    } finally {
        curl_close($ch);
    }
}