<?php
// views/plataforma_barbearia_editar.php

// A variável $barbearia vem da rota protegida no index.php.
if (!isset($barbearia) || !is_array($barbearia)) {
    http_response_code(404);
    exit('Barbearia não encontrada.');
}

$csrf = gerarTokenCsrfPlataforma();
$erroEdicao = $_SESSION['plataforma_edicao_erro'] ?? null;
unset($_SESSION['plataforma_edicao_erro']);

function escaparCampo($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar barbearia - Administração</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 20px;
            background: #10151d;
            color: #f1f5f9;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 650px;
            margin: 0 auto;
        }

        .voltar {
            display: inline-block;
            margin-bottom: 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 24px;
        }

        .subtitulo {
            color: #94a3b8;
            font-size: 14px;
            margin-bottom: 24px;
        }

        .card {
            background: #1c2531;
            border: 1px solid #334155;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-size: 13px;
            font-weight: bold;
        }

        input[type="text"],
        input[type="tel"] {
            display: block;
            width: 100%;
            padding: 12px;
            margin-bottom: 19px;
            background: #111827;
            border: 1px solid #475569;
            border-radius: 8px;
            color: #fff;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #38bdf8;
        }

        .ajuda {
            font-size: 12px;
            color: #94a3b8;
            margin: -12px 0 20px;
        }

        .status {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px;
            background: #111827;
            border-radius: 8px;
        }

        .status input {
            width: 18px;
            height: 18px;
        }

        .status label {
            margin: 0;
            cursor: pointer;
        }

        .aviso {
            color: #94a3b8;
            font-size: 12px;
            line-height: 1.5;
            margin-top: 12px;
        }

        .botao {
            width: 100%;
            padding: 14px;
            border: 0;
            border-radius: 8px;
            background: #2563eb;
            color: white;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
        }

        @media (max-width: 480px) {
            body { padding: 12px; }
            .card { padding: 17px; }
            h1 { font-size: 21px; }
        }
    </style>
</head>
<body>

<div class="container">

    <a class="voltar" href="/agenda/plataforma">
        &larr; Voltar ao painel
    </a>

    <h1>Editar barbearia</h1>

    <p class="subtitulo">
        Atualize as informações da barbearia selecionada.
    </p>

    <?php if (is_string($erroEdicao) && $erroEdicao !== ''): ?>
        <div style="
            background: #7f1d1d;
            color: #fee2e2;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        ">
            <?= htmlspecialchars($erroEdicao, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST"
          action="/agenda/api/editar_barbearia_plataforma.php">

        <input type="hidden"
               name="csrf_token"
               value="<?= escaparCampo($csrf) ?>">

        <input type="hidden"
               name="barbearia_id"
               value="<?= (int)$barbearia['id'] ?>">

        <div class="card">

            <label for="nome">
                Nome da barbearia
            </label>

            <input type="text"
                   id="nome"
                   name="nome"
                   maxlength="100"
                   value="<?= escaparCampo($barbearia['nome']) ?>"
                   required>

            <label for="slug">
                Identificador (slug)
            </label>

            <input type="text"
                   id="slug"
                   name="slug"
                   maxlength="100"
                   pattern="[a-z0-9][a-z0-9_-]+"
                   value="<?= escaparCampo($barbearia['slug']) ?>"
                   required>

            <p class="ajuda">
                Alterar o slug modifica o endereço público da barbearia.
            </p>

            <label for="whatsapp">
                WhatsApp da barbearia
            </label>

            <input type="tel"
                   id="whatsapp"
                   name="whatsapp"
                   maxlength="20"
                   value="<?= escaparCampo($barbearia['whatsapp_num']) ?>"
                   required>

            <div class="status">
                <input type="checkbox"
                       id="ativo"
                       name="ativo"
                       value="1"
                       <?= (int)$barbearia['ativo'] === 1 ? 'checked' : '' ?>>

                <label for="ativo">
                    Barbearia ativa
                </label>
            </div>

            <p class="aviso">
                Desativar uma barbearia impede novos acessos
                enquanto estiver inativa. Os dados e o histórico
                serão preservados.
            </p>

        </div>

        <button type="submit" class="botao">
            Salvar alterações
        </button>

    </form>

</div>

</body>
</html>