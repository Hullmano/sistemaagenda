<?php
// helpers/autorizacao_saudacao.php

/**
 * Autoriza o envio real de saudacao somente para
 * o telefone explicitamente liberado para teste.
 *
 * Na ausencia das configuracoes, bloqueia o envio.
 */
function envioSaudacaoAutorizado(
    array $config,
    string $whatsapp
): bool {

    if (($config['whatsapp_saudacao_envio_ativo'] ?? false) !== true) {
        return false;
    }

    $telefoneAutorizado = $config['whatsapp_saudacao_telefone_teste'] ?? null;

    if (!is_string($telefoneAutorizado)) {
        return false;
    }

    if (!preg_match('/^55[0-9]{10,11}$/', $telefoneAutorizado)) {
        return false;
    }

    if (!preg_match('/^55[0-9]{10,11}$/', $whatsapp)) {
        return false;
    }

    return hash_equals($telefoneAutorizado, $whatsapp);
}