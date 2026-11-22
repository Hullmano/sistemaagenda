<?php
// views/admin_barbeiros.php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth.php';

$usuario = exigirDono();
$barbeariaId = (int)$usuario['barbearia_id'];
$sucessoCadastro = $_SESSION['barbeiro_cadastro_sucesso'] ?? null;
unset($_SESSION['barbeiro_cadastro_sucesso']);
$sucessoEdicao = $_SESSION['barbeiro_edicao_sucesso'] ?? null;
unset($_SESSION['barbeiro_edicao_sucesso']);

$db = Database::getConnection();

$stmt = $db->prepare("
    SELECT id, nome, email, ativo
    FROM usuarios
    WHERE barbearia_id = ?
      AND nivel = 'barbeiro'
    ORDER BY nome ASC, id ASC
");

$stmt->execute([$barbeariaId]);
$barbeiros = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($barbeiros);
$ativos = count(array_filter(
    $barbeiros,
    fn($barbeiro) => (int)$barbeiro['ativo'] === 1
));
$inativos = $total - $ativos;

function escaparBarbeiros($valor): string
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Gerenciar barbeiros</title>

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
            max-width: 900px;
            margin: 0 auto;
        }

        .voltar {
            display: inline-block;
            margin-bottom: 22px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 24px;
        }

        .subtitulo {
            margin: 0 0 24px;
            color: #94a3b8;
            font-size: 14px;
        }

        .resumo {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 26px;
        }

        .card {
            padding: 20px;
            background: #1c2531;
            border: 1px solid #334155;
            border-radius: 12px;
        }

        .card span {
            color: #94a3b8;
            font-size: 13px;
        }

        .card strong {
            display: block;
            margin-top: 8px;
            font-size: 26px;
        }

        .lista {
            background: #1c2531;
            border: 1px solid #334155;
            border-radius: 12px;
            overflow: hidden;
        }

        .item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 18px 20px;
            border-bottom: 1px solid #334155;
        }

        .item:last-child {
            border-bottom: none;
        }

        .nome {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .email {
            color: #94a3b8;
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            white-space: nowrap;
        }

        .ativo {
            background: #14532d;
            color: #bbf7d0;
        }

        .inativo {
            background: #7f1d1d;
            color: #fecaca;
        }

        .vazio {
            padding: 24px;
            color: #94a3b8;
            font-size: 14px;
        }

        @media (max-width: 480px) {
            body { padding: 12px; }
            h1 { font-size: 21px; }
            .card { padding: 12px; }
            .card strong { font-size: 22px; }
            .item { padding: 15px 12px; }
        }
    </style>
</head>
<body>

<div class="container">

    <a class="voltar" href="/agenda/<?= rawurlencode($slug_filtrado) ?>/admin">
        &larr; Voltar ao painel
    </a>

    <h1>Gerenciar barbeiros</h1>

    <p class="subtitulo">
        Consulte os profissionais cadastrados na sua barbearia.
    </p>

    <?php if (is_string($sucessoCadastro) && $sucessoCadastro !== ''): ?>
        <div style="
            background: #14532d;
            color: #dcfce7;
            border: 1px solid #166534;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        ">
            <?= htmlspecialchars(
                $sucessoCadastro,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
    <?php endif; ?>

    <?php if (is_string($sucessoEdicao) && $sucessoEdicao !== ''): ?>
        <div style="
            background: #14532d;
            color: #dcfce7;
            border: 1px solid #166534;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        ">
            <?= htmlspecialchars($sucessoEdicao, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="resumo">
        <div class="card">
            <span>Barbeiros</span>
            <strong><?= $total ?></strong>
        </div>

        <div class="card">
            <span>Ativos</span>
            <strong><?= $ativos ?></strong>
        </div>

        <div class="card">
            <span>Inativos</span>
            <strong><?= $inativos ?></strong>
        </div>
    </div>

    <div style="margin-bottom: 24px;">
        <a href="/agenda/<?= rawurlencode($slug_filtrado) ?>/admin/barbeiros-novo"
        style="
            display: inline-block;
            padding: 12px 20px;
            background: #2563eb;
            color: #ffffff;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
            border-radius: 8px;
        ">
            + Novo barbeiro
        </a>
    </div>

    <h2>Profissionais cadastrados</h2>

    <div class="lista">
        <?php if (!$barbeiros): ?>
            <div class="vazio">
                Nenhum barbeiro cadastrado nesta barbearia.
            </div>
        <?php endif; ?>

        <?php foreach ($barbeiros as $barbeiro): ?>
            <div class="item">
                <div>
                    <div class="nome">
                        <?= escaparBarbeiros($barbeiro['nome']) ?>
                    </div>

                    <div class="email">
                        <?= escaparBarbeiros($barbeiro['email']) ?>
                    </div>
                </div>

                <div style="
                    display: flex;
                    align-items: center;
                    justify-content: flex-end;
                    gap: 10px;
                    flex-wrap: wrap;
                ">
                    <span class="status <?= (int)$barbeiro['ativo'] === 1 ? 'ativo' : 'inativo' ?>">
                        <?= (int)$barbeiro['ativo'] === 1 ? 'Ativo' : 'Inativo' ?>
                    </span>

                    <a href="/agenda/<?= rawurlencode($slug_filtrado) ?>/admin/barbeiros-editar?id=<?= (int)$barbeiro['id'] ?>"
                    style="
                        padding: 7px 12px;
                        background: #334155;
                        color: #f1f5f9;
                        text-decoration: none;
                        font-size: 12px;
                        font-weight: bold;
                        border-radius: 7px;
                    ">
                        Editar
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>

</body>
</html>