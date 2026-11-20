
<?php
// api/salvar_conferencia_caixa.php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

function responderConferencia(int $codigo, array $dados): void
{
    http_response_code($codigo);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

// Somente requisições POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderConferencia(405, [
        'erro' => 'Método não permitido.'
    ]);
}

// Usuário autenticado
$usuario = usuarioLogado();

if (!$usuario) {
    responderConferencia(401, [
        'erro' => 'Sessão expirada.'
    ]);
}

// Conferência exclusiva do dono
if ($usuario['nivel'] !== 'dono') {
    responderConferencia(403, [
        'erro' => 'Somente o dono pode conferir o caixa.'
    ]);
}

// Validação do token CSRF
if (!validarTokenCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    responderConferencia(403, [
        'erro' => 'Token de segurança inválido.'
    ]);
}

// Recebe os dados do navegador
$dados = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($dados)) {
    responderConferencia(400, [
        'erro' => 'Dados inválidos.'
    ]);
}

$responsavelId = filter_var(
    $dados['usuario_responsavel_id'] ?? null,
    FILTER_VALIDATE_INT
);

$dataReferencia = $dados['data_referencia'] ?? '';

$valorConferido = $dados['valor_conferido'] ?? null;

// Validações básicas
if (!$responsavelId || $responsavelId <= 0) {
    responderConferencia(400, [
        'erro' => 'Responsável inválido.'
    ]);
}

if (!is_string($dataReferencia)) {
    responderConferencia(400, [
        'erro' => 'Data inválida.'
    ]);
}

$data = DateTimeImmutable::createFromFormat(
    '!Y-m-d',
    $dataReferencia
);

if (!$data || $data->format('Y-m-d') !== $dataReferencia) {
    responderConferencia(400, [
        'erro' => 'Data inválida.'
    ]);
}

// O valor deve ser enviado como string decimal.
// Exemplo: "140.00"
if (
    !is_string($valorConferido) ||
    !preg_match('/^\d{1,8}(\.\d{1,2})?$/', $valorConferido)
) {
    responderConferencia(400, [
        'erro' => 'Valor conferido inválido.'
    ]);
}

// Converte para centavos, sem usar float.
[$reais, $centavos] = array_pad(
    explode('.', $valorConferido, 2),
    2,
    '0'
);

$valorConferidoCentavos =
    ((int)$reais * 100) +
    (int)str_pad($centavos, 2, '0');

if ($valorConferidoCentavos > 999999999) {
    responderConferencia(400, [
        'erro' => 'Valor acima do limite permitido.'
    ]);
}

try {
    $db = \Database::getConnection();
    $db->beginTransaction();

    $barbeariaId = (int)$usuario['barbearia_id'];

    // Bloqueia a barbearia durante o registro da conferência.
    $stmt = $db->prepare("
        SELECT id
        FROM barbearias
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([$barbeariaId]);

    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Barbearia não encontrada.', 404);
    }

    // Verifica se o responsável pertence à mesma barbearia.
    $stmt = $db->prepare("
        SELECT id
        FROM usuarios
        WHERE id = ?
          AND barbearia_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $responsavelId,
        $barbeariaId
    ]);

    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Responsável não encontrado.', 404);
    }

    // Impede conferência de datas futuras.
    if ($dataReferencia > date('Y-m-d')) {
        throw new RuntimeException(
            'Não é permitido conferir datas futuras.',
            400
        );
    }

    // Verifica se já existe conferência para o mesmo dia.
    $stmt = $db->prepare("
        SELECT id
        FROM conferencias_caixa
        WHERE barbearia_id = ?
          AND usuario_responsavel_id = ?
          AND data_referencia = ?
        FOR UPDATE
    ");

    $stmt->execute([
        $barbeariaId,
        $responsavelId,
        $dataReferencia
    ]);

    if ($stmt->fetch()) {
        throw new RuntimeException(
            'Este responsável já possui conferência nesta data.',
            409
        );
    }

    // Calcula somente os recebimentos em dinheiro não estornados.
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(p.valor), 0) AS total_dinheiro,
            MAX(p.id) AS ultimo_pagamento_id
        FROM pagamentos p
        JOIN comandas c
            ON c.id = p.comanda_id
        WHERE c.barbearia_id = ?
        AND p.usuario_id = ?
        AND p.forma_pagamento = 'dinheiro'
        AND p.estornado_em IS NULL
        AND p.pago_em >= ?
        AND p.pago_em < DATE_ADD(?, INTERVAL 1 DAY)
    ");


    $stmt->execute([
        $barbeariaId,
        $responsavelId,
        $dataReferencia,
        $dataReferencia
    ]);

    $resultadoDinheiro = $stmt->fetch(PDO::FETCH_ASSOC);

    $valorRegistrado = (string)$resultadoDinheiro['total_dinheiro'];

    $ultimoPagamentoId = $resultadoDinheiro['ultimo_pagamento_id'] !== null
        ? (int)$resultadoDinheiro['ultimo_pagamento_id']
        : null;

    // Converte o DECIMAL retornado pelo MySQL para centavos.
    $partes = explode('.', $valorRegistrado, 2);

    $registradoCentavos =
        ((int)$partes[0] * 100) +
        (int)str_pad($partes[1] ?? '0', 2, '0');

    if ($registradoCentavos <= 0) {
        throw new RuntimeException(
            'Não existem recebimentos em dinheiro para conferir.',
            409
        );
    }

    // Calcula a diferença em centavos.
    $diferencaCentavos =
        $valorConferidoCentavos - $registradoCentavos;

    $limite = 9999999999;

    if (
        $registradoCentavos > $limite ||
        abs($diferencaCentavos) > $limite
    ) {
        throw new RuntimeException(
            'Valor fora do limite permitido.',
            409
        );
    }

    $registradoDecimal = number_format(
        $registradoCentavos / 100, 2, '.', ''
    );

    $conferidoDecimal = number_format(
        $valorConferidoCentavos / 100, 2, '.', ''
    );

    $diferencaDecimal = number_format(
        $diferencaCentavos / 100, 2, '.', ''
    );

    // Registra a conferência sem modificar os pagamentos.
    $stmt = $db->prepare("
        INSERT INTO conferencias_caixa (
            barbearia_id,
            usuario_responsavel_id,
            usuario_conferencia_id,
            data_referencia,
            valor_registrado,
            ultimo_pagamento_id,
            valor_conferido,
            diferenca
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $barbeariaId,
        $responsavelId,
        (int)$usuario['id'],
        $dataReferencia,
        $registradoDecimal,
        $ultimoPagamentoId,
        $conferidoDecimal,
        $diferencaDecimal
    ]);

    $conferenciaId = (int)$db->lastInsertId();

    $db->commit();

    responderConferencia(200, [
        'sucesso' => true,
        'mensagem' => 'Conferência registrada com sucesso.',
        'conferencia_id' => $conferenciaId,
        'valor_registrado' => $registradoDecimal,
        'valor_conferido' => $conferidoDecimal,
        'diferenca' => $diferencaDecimal
    ]);

} catch (Throwable $e) {

    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    if ($e instanceof RuntimeException &&
        in_array((int)$e->getCode(), [400, 404, 409], true)) {

        responderConferencia(
            (int)$e->getCode(),
            ['erro' => $e->getMessage()]
        );
    }

    error_log(
        'Erro ao salvar conferência: ' . $e->getMessage()
    );

    responderConferencia(500, [
        'erro' => 'Erro interno ao salvar conferência.'
    ]);
}
