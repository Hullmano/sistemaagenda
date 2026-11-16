
<?php
// views/cliente_consultar_agendamento.php

// Acesso permitido somente pelo roteador index.php.
if (
    !isset($tokenConsulta, $barbearia_id, $barbearia_nome) ||
    !is_string($tokenConsulta) ||
    !preg_match('/^[a-f0-9]{64}$/', $tokenConsulta)
) {
    http_response_code(404);
    exit('Agendamento não encontrado.');
}

// Proteção dos dados exibidos e do token na URL.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

$nonceScript = base64_encode(random_bytes(16));

header(
    "Content-Security-Policy: default-src 'none'; " .
    "style-src 'unsafe-inline'; " .
    "script-src 'nonce-" . $nonceScript . "'; " .
    "connect-src 'self'; " .
    "base-uri 'none'; form-action 'none'; frame-ancestors 'none'"
);

$tokenHash = hash('sha256', $tokenConsulta);

// Busca somente agendamentos da barbearia da URL.
$stmt = $db->prepare("
    SELECT
        a.id,
        b.antecedencia_cancelamento_minutos,
        a.nome_cliente,
        a.data_agendamento,
        a.horario_inicio,
        a.status,
        s.nome AS servico_nome,
        s.preco AS valor_servico
    FROM agendamentos a
    JOIN barbearias b
        ON b.id = a.barbearia_id
    JOIN servicos s
        ON s.id = a.servico_id
       AND s.barbearia_id = a.barbearia_id
    WHERE a.barbearia_id = ?
      AND a.token_consulta_hash = ?
    LIMIT 1
");

$stmt->execute([
    (int)$barbearia_id,
    $tokenHash
]);

$agendamento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$agendamento) {
    http_response_code(404);
}

function escaparConsulta($valor): string
{
    return htmlspecialchars(
        (string)$valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$nomesStatus = [
    'agendado' => 'Confirmado',
    'concluido' => 'Atendimento concluído',
    'cancelado' => 'Cancelado',
    'nao_compareceu' => 'Não compareceu'
];

$status = $agendamento['status'] ?? '';

$dataFormatada = $agendamento
    ? date('d/m/Y', strtotime($agendamento['data_agendamento']))
    : '';

$horarioFormatado = $agendamento
    ? substr($agendamento['horario_inicio'], 0, 5)
    : '';
    // Verifica se o cancelamento ainda está dentro do prazo.
    $podeCancelar = false;
    $horarioLimiteCancelamento = '';

    if ($agendamento && $status === 'agendado') {

        $antecedencia = (int) $agendamento[
            'antecedencia_cancelamento_minutos'
        ];

        // Mantém somente os valores aceitos pelo sistema.
        if (!in_array($antecedencia, [30, 60], true)) {
            $antecedencia = 30;
        }

        $fusoHorario = new DateTimeZone('America/Sao_Paulo');

        $dataHoraAgendamento = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $agendamento['data_agendamento'] . ' ' .
            $agendamento['horario_inicio'],
            $fusoHorario
        );

        if ($dataHoraAgendamento) {

            $limiteCancelamento = $dataHoraAgendamento->modify(
                '-' . $antecedencia . ' minutes'
            );

            $agora = new DateTimeImmutable('now', $fusoHorario);

            $podeCancelar = $agora < $limiteCancelamento;

            $horarioLimiteCancelamento = $limiteCancelamento->format(
                'd/m/Y \à\s H:i'
            );
        }
    }

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>Consultar agendamento</title>

    <style>
        :root {
            color-scheme: dark;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px 16px;
            background: #0B0F19;
            color: #F4F6F9;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 450px;
            margin: 0 auto;
        }

        .card {
            background: #151B2C;
            border: 1px solid #222B45;
            border-radius: 16px;
            padding: 22px;
        }

        h1 {
            font-size: 22px;
            margin: 0 0 8px;
        }

        h2 {
            font-size: 18px;
            margin: 0 0 20px;
            color: #FF9F43;
        }

        .subtitulo {
            color: #8F9BB3;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .linha {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin: 15px 0;
            font-size: 14px;
        }

        .rotulo {
            color: #8F9BB3;
            flex-shrink: 0;
        }

        .valor {
            text-align: right;
            font-weight: bold;
            overflow-wrap: anywhere;
        }

        .separador {
            border: 0;
            border-top: 1px solid #30394E;
            margin: 22px 0;
        }

        .status {
            color: #22C55E;
            font-weight: bold;
        }

        .status.inativo {
            color: #FBBF24;
        }

        .botao {
            display: block;
            width: 100%;
            margin-top: 24px;
            padding: 15px;
            text-align: center;
            border-radius: 10px;
            background: #FF9F43;
            color: #111827;
            text-decoration: none;
            font-weight: bold;
        }

        .aviso {
            background: #252B39;
            border-radius: 10px;
            padding: 16px;
            font-size: 14px;
            line-height: 1.5;
            color: #CDD3DF;
        }
    </style>
</head>
<body>
<main class="container">
    <div class="card">

        <h1><?= escaparConsulta($barbearia_nome) ?></h1>
        <p class="subtitulo">Consulta de agendamento</p>

        <?php if (!$agendamento): ?>

            <h2>Agendamento não encontrado</h2>

            <p class="aviso">
                Não foi possível localizar uma reserva com este link.
                Verifique se o endereço está completo.
            </p>

        <?php else: ?>

            <h2>Detalhes do agendamento</h2>

            <div class="linha">
                <span class="rotulo">Cliente</span>
                <span class="valor">
                    <?= escaparConsulta($agendamento['nome_cliente']) ?>
                </span>
            </div>

            <div class="linha">
                <span class="rotulo">Serviço</span>
                <span class="valor">
                    <?= escaparConsulta($agendamento['servico_nome']) ?>
                </span>
            </div>

            <div class="linha">
                <span class="rotulo">Data</span>
                <span class="valor">
                    <?= escaparConsulta($dataFormatada) ?>
                </span>
            </div>

            <div class="linha">
                <span class="rotulo">Horário</span>
                <span class="valor">
                    <?= escaparConsulta($horarioFormatado) ?>
                </span>
            </div>

            <div class="linha">
                <span class="rotulo">Valor do serviço</span>
                <span class="valor">
                    <?php if ($agendamento['valor_servico'] !== null): ?>
                        R$ <?= number_format(
                            (float)$agendamento['valor_servico'],
                            2,
                            ',',
                            '.'
                        ) ?>
                    <?php else: ?>
                        Não informado
                    <?php endif; ?>
                </span>
            </div>

            <hr class="separador">

            <div class="linha">
                <span class="rotulo">Situação</span>
                <span class="status <?= $status === 'agendado' ? '' : 'inativo' ?>">
                    <?= escaparConsulta($nomesStatus[$status] ?? $status) ?>
                </span>
            </div>

            <?php if ($status === 'agendado'): ?>

                <?php if ($podeCancelar): ?>

                    <div class="aviso">
                        Seu agendamento está confirmado.

                        <p>
                            Você poderá cancelar esta reserva até
                            <strong>
                                <?= escaparConsulta($horarioLimiteCancelamento) ?>
                            </strong>.
                        </p>
                    </div>

                    <button
                        type="button"
                        id="btnCancelarAgendamento"
                        class="botao"
                        style="border: none; cursor: pointer;"
                    >
                        Cancelar agendamento
                    </button>


                <?php else: ?>

                    <div class="aviso">
                        Seu agendamento está confirmado.

                        <p>
                            O prazo para cancelamento online já terminou.
                            Caso precise alterar ou cancelar seu agendamento,
                            entre em contato diretamente com a barbearia.
                        </p>
                    </div>

                <?php endif; ?>

            <?php endif; ?>


        <?php endif; ?>

    </div>
</main>

<script nonce="<?= htmlspecialchars($nonceScript, ENT_QUOTES, 'UTF-8') ?>">
document.addEventListener('DOMContentLoaded', function () {

    const btnCancelar = document.getElementById(
        'btnCancelarAgendamento'
    );

    if (!btnCancelar) return;

    btnCancelar.addEventListener('click', function () {

        const confirmou = window.confirm(
            'Tem certeza de que deseja cancelar este agendamento?'
        );

        if (!confirmou) return;

        btnCancelar.disabled = true;
        btnCancelar.textContent = 'Cancelando...';

        fetch('/agenda/api/cancelar_agendamento_cliente.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                barbearia_id: <?= json_encode((int)$barbearia_id) ?>,
                token_consulta: <?= json_encode(
                    $tokenConsulta,
                    JSON_HEX_TAG | JSON_HEX_AMP |
                    JSON_HEX_APOS | JSON_HEX_QUOT
                ) ?>
            })
        })
        .then(async function (resposta) {
            const dados = await resposta.json();

            if (!resposta.ok || !dados.sucesso) {
                throw new Error(
                    dados.erro || 'Não foi possível cancelar.'
                );
            }

            window.alert('Agendamento cancelado com sucesso!');
            window.location.reload();
        })
        .catch(function (erro) {
            window.alert(erro.message);

            btnCancelar.disabled = false;
            btnCancelar.textContent = 'Cancelar agendamento';
        });


    });

});
</script>

</body>
</html>
