
<?php
// helpers/token_consulta.php

function chaveTokenConsulta(): string
{
    require_once __DIR__ . '/../config.php';

    $config = agendaConfig();

    $chaveBase64 = $config['token_encryption_key'] ?? null;

    if (!is_string($chaveBase64) || $chaveBase64 === '') {
        throw new RuntimeException(
            'Chave de criptografia dos tokens não configurada.'
        );
    }

    $chave = base64_decode($chaveBase64, true);

    if ($chave === false || strlen($chave) !== 32) {
        throw new RuntimeException(
            'Chave de criptografia dos tokens inválida.'
        );
    }

    return $chave;
}


function cifrarTokenConsulta(string $token): string
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new InvalidArgumentException('Token inválido.');
    }

    $iv = random_bytes(12);
    $tag = '';

    $cifrado = openssl_encrypt(
        $token,
        'aes-256-gcm',
        chaveTokenConsulta(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($cifrado === false) {
        throw new RuntimeException('Falha ao criptografar token.');
    }

    return base64_encode($iv . $tag . $cifrado);
}

function decifrarTokenConsulta(string $valor): ?string
{
    $dados = base64_decode($valor, true);

    if ($dados === false || strlen($dados) < 29) {
        return null;
    }

    $iv = substr($dados, 0, 12);
    $tag = substr($dados, 12, 16);
    $cifrado = substr($dados, 28);

    $token = openssl_decrypt(
        $cifrado,
        'aes-256-gcm',
        chaveTokenConsulta(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if (
        $token === false ||
        !preg_match('/^[a-f0-9]{64}$/', $token)
    ) {
        return null;
    }

    return $token;
}
