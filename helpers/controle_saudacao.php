<?php
// helpers/controle_saudacao.php

/**
 * Verifica se ja transcorreram 12 horas desde a ultima saudacao.
 *
 * Recebe a data/hora da ultima saudacao confirmada.
 * A protecao contra envios simultaneos sera feita
 * posteriormente, utilizando transacoes no MySQL.
 */
function intervaloSaudacaoLiberado(
    ?string $ultimaSaudacaoEm,
    DateTimeImmutable $agora
): bool {

    // Nenhuma saudacao anterior.
    if ($ultimaSaudacaoEm === null) {
        return true;
    }

    $fuso = new DateTimeZone('America/Sao_Paulo');

    $ultima = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $ultimaSaudacaoEm,
        $fuso
    );

    // Por seguranca, dados invalidos nao liberam envio.
    if (
        !$ultima ||
        $ultima->format('Y-m-d H:i:s') !== $ultimaSaudacaoEm
    ) {
        return false;
    }

    $proximaPermitida = $ultima->modify('+12 hours');

    return $agora >= $proximaPermitida;
}


/**
 * Reserva uma tentativa de saudacao no banco.
 *
 * Retorna o identificador da execucao se a reserva for
 * autorizada, ou null quando o envio estiver bloqueado.
 *
 * Esta funcao NAO envia mensagens.
 */
function reservarSaudacaoWhatsapp(
    PDO $db,
    int $barbeariaId,
    string $whatsapp
): ?string {

    if ($barbeariaId <= 0 ||
        !preg_match('/^55[0-9]{10,11}$/', $whatsapp)) {
        throw new InvalidArgumentException(
            'Dados invalidos para reserva de saudacao.'
        );
    }

    $db->beginTransaction();

    try {
        // Cria o registro somente se ainda nao existir.
        // A chave unica protege contra duplicidades.
        $stmt = $db->prepare("
            INSERT INTO whatsapp_saudacoes (
                barbearia_id,
                whatsapp,
                status
            )
            VALUES (?, ?, 'pendente')
            ON DUPLICATE KEY UPDATE id = id
        ");

        $stmt->execute([$barbeariaId, $whatsapp]);

        // Bloqueia o registro ate COMMIT ou ROLLBACK.
        $stmt = $db->prepare("
            SELECT
                status,
                ultima_saudacao_em,
                NOW() AS agora
            FROM whatsapp_saudacoes
            WHERE barbearia_id = ?
              AND whatsapp = ?
            FOR UPDATE
        ");

        $stmt->execute([$barbeariaId, $whatsapp]);
        $registro = $stmt->fetch();

        if (!$registro) {
            throw new RuntimeException(
                'Registro de saudacao nao encontrado.'
            );
        }

        // Uma tentativa em andamento ou de resultado
        // incerto nao pode gerar um segundo envio.
        if (in_array(
            $registro['status'],
            ['enviando', 'incerto'],
            true
        )) {
            $db->commit();
            return null;
        }

        // Um registro enviado sem data exige verificacao.
        if ($registro['status'] === 'enviado' &&
            $registro['ultima_saudacao_em'] === null) {
            $db->commit();
            return null;
        }

        $fuso = new DateTimeZone('America/Sao_Paulo');

        $agora = new DateTimeImmutable(
            $registro['agora'],
            $fuso
        );

        if (!intervaloSaudacaoLiberado(
            $registro['ultima_saudacao_em'],
            $agora
        )) {
            $db->commit();
            return null;
        }

        // Identifica exclusivamente esta tentativa.
        $execucaoId = bin2hex(random_bytes(16));

        $stmt = $db->prepare("
            UPDATE whatsapp_saudacoes
            SET
                status = 'enviando',
                execucao_id = ?,
                reservado_em = NOW()
            WHERE barbearia_id = ?
              AND whatsapp = ?
        ");

        $stmt->execute([
            $execucaoId,
            $barbeariaId,
            $whatsapp
        ]);

        $db->commit();

        return $execucaoId;

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $e;
    }
}

/**
 * Confirma uma saudacao aceita pela Evolution API.
 *
 * Somente a execucao que reservou o envio pode
 * alterar o registro.
 *
 * Retorna true quando a confirmacao foi registrada.
 */
function confirmarSaudacaoWhatsapp(
    PDO $db,
    int $barbeariaId,
    string $whatsapp,
    string $execucaoId
): bool {

    if (
        $barbeariaId <= 0 ||
        !preg_match('/^55[0-9]{10,11}$/', $whatsapp) ||
        !preg_match('/^[a-f0-9]{32}$/', $execucaoId)
    ) {
        throw new InvalidArgumentException(
            'Dados invalidos para confirmacao de saudacao.'
        );
    }

    $stmt = $db->prepare("
        UPDATE whatsapp_saudacoes
        SET
            status = 'enviado',
            ultima_saudacao_em = NOW(),
            execucao_id = NULL,
            reservado_em = NULL
        WHERE barbearia_id = ?
          AND whatsapp = ?
          AND execucao_id = ?
          AND status = 'enviando'
    ");

    $stmt->execute([
        $barbeariaId,
        $whatsapp,
        $execucaoId
    ]);

    return $stmt->rowCount() === 1;
}

/**
 * Marca uma tentativa como incerta.
 *
 * Usar quando a solicitacao de envio pode ter chegado
 * a Evolution API, mas nao houve confirmacao confiavel.
 *
 * A tentativa permanece bloqueada ate verificacao.
 */
function marcarSaudacaoIncertaWhatsapp(
    PDO $db,
    int $barbeariaId,
    string $whatsapp,
    string $execucaoId
): bool {

    if (
        $barbeariaId <= 0 ||
        !preg_match('/^55[0-9]{10,11}$/', $whatsapp) ||
        !preg_match('/^[a-f0-9]{32}$/', $execucaoId)
    ) {
        throw new InvalidArgumentException(
            'Dados invalidos para controle de saudacao.'
        );
    }

    $stmt = $db->prepare("
        UPDATE whatsapp_saudacoes
        SET status = 'incerto'
        WHERE barbearia_id = ?
          AND whatsapp = ?
          AND execucao_id = ?
          AND status = 'enviando'
    ");

    $stmt->execute([
        $barbeariaId,
        $whatsapp,
        $execucaoId
    ]);

    return $stmt->rowCount() === 1;
}

/**
 * Registra uma tentativa que comprovadamente nao foi enviada.
 *
 * Esta funcao deve ser chamada apenas quando for seguro
 * afirmar que a Evolution API nao aceitou a mensagem.
 *
 * Falhas de rede ou respostas ambiguas devem permanecer
 * no estado 'incerto'.
 */
function marcarSaudacaoFalhouWhatsapp(
    PDO $db,
    int $barbeariaId,
    string $whatsapp,
    string $execucaoId
): bool {

    if (
        $barbeariaId <= 0 ||
        !preg_match('/^55[0-9]{10,11}$/', $whatsapp) ||
        !preg_match('/^[a-f0-9]{32}$/', $execucaoId)
    ) {
        throw new InvalidArgumentException(
            'Dados invalidos para controle de saudacao.'
        );
    }

    $stmt = $db->prepare("
        UPDATE whatsapp_saudacoes
        SET
            status = 'falhou',
            execucao_id = NULL,
            reservado_em = NULL
        WHERE barbearia_id = ?
          AND whatsapp = ?
          AND execucao_id = ?
          AND status = 'enviando'
    ");

    $stmt->execute([
        $barbeariaId,
        $whatsapp,
        $execucaoId
    ]);

    return $stmt->rowCount() === 1;
}


/**
 * Consulta reservas em andamento ha mais tempo
 * que o limite informado.
 *
 * Somente leitura: nao altera os registros.
 * O retorno nao inclui telefones nem nomes.
 */
function contarSaudacoesEmAndamentoAntigas(
    PDO $db,
    int $barbeariaId,
    int $minutos = 10
): int {

    if ($barbeariaId <= 0 || $minutos < 1) {
        throw new InvalidArgumentException(
            'Parametros invalidos para consulta.'
        );
    }

    $limite = (new DateTimeImmutable(
        'now',
        new DateTimeZone('America/Sao_Paulo')
    ))
        ->modify("-{$minutos} minutes")
        ->format('Y-m-d H:i:s');

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM whatsapp_saudacoes
        WHERE barbearia_id = ?
          AND status = 'enviando'
          AND reservado_em <= ?
    ");

    $stmt->execute([
        $barbeariaId,
        $limite
    ]);

    return (int) $stmt->fetchColumn();
}