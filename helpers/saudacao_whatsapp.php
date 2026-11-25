<?php
// helpers/saudacao_whatsapp.php

function montarSaudacaoWhatsapp(
    string $nomeBarbearia,
    string $slug,
    ?string $nomeCliente,
    string $appUrl
): string {

    $nomeBarbearia = trim($nomeBarbearia);
    $slug = trim($slug);
    $appUrl = rtrim($appUrl, '/');

    // Usa somente o primeiro nome do cliente.
    $primeiroNome = '';

    if ($nomeCliente !== null) {
        $partes = preg_split('/\s+/', trim($nomeCliente));
        $primeiroNome = $partes[0] ?? '';
    }

    $saudacao = $primeiroNome !== ''
        ? "Olá, {$primeiroNome}! 👋💈\n\n"
        : "Olá! 👋💈\n\n";

    $saudacao .= "Seja bem-vindo à {$nomeBarbearia}!\n\n";
    $saudacao .= "Para agendar seu horário, acesse:\n";
    $saudacao .= "{$appUrl}/{$slug}\n\n";
    $saudacao .= "Será um prazer atender você!";

    return $saudacao;
}