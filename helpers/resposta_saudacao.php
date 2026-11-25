<?php
// helpers/resposta_saudacao.php

/**
 * Traduz o resultado do processamento para
 * uma resposta segura do webhook.
 *
 * Nao revela telefones, nomes ou credenciais.
 */
function respostaProcessamentoSaudacao(array $resultado): array
{
    $status = $resultado['status'] ?? null;
    $processado = $resultado['processado'] ?? null;

    $permitidos = [
        'enviado' => [true, 'saudacao_aceita'],
        'falhou' => [true, 'envio_rejeitado'],
        'incerto' => [true, 'envio_incerto'],
        'bloqueado' => [false, 'saudacao_bloqueada'],
        'nao_implementado' => [false, 'envio_ainda_bloqueado']
    ];

    if (
        !is_string($status) ||
        !array_key_exists($status, $permitidos)
    ) {
        throw new RuntimeException(
            'Resultado de saudacao desconhecido.'
        );
    }

    [$processadoEsperado, $motivo] = $permitidos[$status];

    if ($processado !== $processadoEsperado) {
        throw new RuntimeException(
            'Estado de processamento inconsistente.'
        );
    }

    return [
        'sucesso' => true,
        'processado' => $processado,
        'motivo' => $motivo
    ];
}