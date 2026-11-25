<?php
// helpers/processamento_saudacao.php

require_once __DIR__ . '/controle_saudacao.php';
require_once __DIR__ . '/envio_saudacao.php';

/**
 * Coordena o processamento de uma saudacao.
 *
 * Por enquanto, apenas o modo de diagnostico
 * esta implementado. Nenhum envio ou reserva e feito.
 */
function processarSaudacaoWhatsapp(
    PDO $db,
    int $barbeariaId,
    string $whatsapp,
    string $mensagem,
    string $apiUrl,
    string $instancia,
    string $apiKey,
    bool $modoDiagnostico = true,
    bool $permitirEnvioReal = false
    ): array {

    // Bloqueia qualquer processamento real
    // enquanto estamos desenvolvendo.
    if ($modoDiagnostico) {
        return [
            'status' => 'diagnostico',
            'processado' => false
        ];
    }

    // Terceira trava: impede o envio real enquanto
    // a integracao nao estiver completamente validada.
    $integracaoLiberada = false;

    if (!$integracaoLiberada) {
        return [
            'status' => 'nao_implementado',
            'processado' => false
        ];
    }

    // Confere a chave antes de criar uma reserva.
    if (trim($apiKey) === '') {
        throw new InvalidArgumentException(
            'Chave da Evolution API nao configurada.'
        );
    }

    // Reserva -> envio -> finalizacao.
    return executarProcessamentoReservadoSaudacao(
        $db,
        $barbeariaId,
        $whatsapp,
        function () use (
            $requisicao,
            $apiKey
        ): array {
            return executarEnvioSaudacao(
                $requisicao['url'],
                $requisicao['payload'],
                $apiKey,
                true
            );
        }
    );

    // Prepara e valida os dados antes de qualquer reserva.
    // Nenhuma chamada HTTP e realizada nesta etapa.
    $requisicao = prepararEnvioSaudacao(
        $apiUrl,
        $instancia,
        $whatsapp,
        $mensagem
    );

    // Trava de seguranca: o envio real ainda nao esta habilitado.
    return [
        'status' => 'nao_implementado',
        'processado' => false
    ];
}


/**
 * Finaliza uma tentativa de saudacao que ja possui
 * uma reserva transacional valida.
 */
function finalizarProcessamentoSaudacao(
    PDO $db,
    int $barbeariaId,
    string $whatsapp,
    string $execucaoId,
    string $status
): bool {

    switch ($status) {
        case 'enviado':
            return confirmarSaudacaoWhatsapp(
                $db,
                $barbeariaId,
                $whatsapp,
                $execucaoId
            );

        case 'incerto':
            return marcarSaudacaoIncertaWhatsapp(
                $db,
                $barbeariaId,
                $whatsapp,
                $execucaoId
            );

        case 'falhou':
            return marcarSaudacaoFalhouWhatsapp(
                $db,
                $barbeariaId,
                $whatsapp,
                $execucaoId
            );

        default:
            throw new InvalidArgumentException(
                'Status de envio nao reconhecido.'
            );
    }
}

/**
 * Coordena uma reserva e sua finalizacao.
 *
 * O callback de envio permite testar o fluxo
 * sem realizar requisicoes HTTP.
 *
 * Esta funcao ainda nao esta ligada ao webhook.
 */
function executarProcessamentoReservadoSaudacao(
    PDO $db,
    int $barbeariaId,
    string $whatsapp,
    callable $operacaoEnvio
): array {

    // A reserva e feita antes da operacao de envio.
    $execucaoId = reservarSaudacaoWhatsapp(
        $db,
        $barbeariaId,
        $whatsapp
    );

    if ($execucaoId === null) {
        return [
            'status' => 'bloqueado',
            'processado' => false
        ];
    }

    try {
        // Nos testes, esta operacao sera simulada.
        $resultado = $operacaoEnvio();

        $status = $resultado['status'] ?? null;

        if (!in_array(
            $status,
            ['enviado', 'falhou', 'incerto'],
            true
        )) {
            throw new RuntimeException(
                'Resultado de envio nao reconhecido.'
            );
        }

        $finalizado = finalizarProcessamentoSaudacao(
            $db,
            $barbeariaId,
            $whatsapp,
            $execucaoId,
            $status
        );

        if (!$finalizado) {
            throw new RuntimeException(
                'Nao foi possivel finalizar a reserva.'
            );
        }

        return [
            'status' => $status,
            'processado' => true
        ];

    } catch (Throwable $e) {

        // Se o resultado nao foi confirmado,
        // mantemos a tentativa bloqueada para revisao.
        try {
            marcarSaudacaoIncertaWhatsapp(
                $db,
                $barbeariaId,
                $whatsapp,
                $execucaoId
            );
        } catch (Throwable $erroProtecao) {
            error_log(
                'Falha ao proteger reserva de saudacao.'
            );
        }

        throw $e;
    }
}