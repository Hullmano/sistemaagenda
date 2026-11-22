<?php
// views/plataforma_painel.php

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/auth_plataforma.php';

$admin = exigirAdministradorPlataforma();
$sucessoCadastro = $_SESSION['plataforma_cadastro_sucesso'] ?? null;
unset($_SESSION['plataforma_cadastro_sucesso']);
$sucessoEdicao = $_SESSION['plataforma_edicao_sucesso'] ?? null;
unset($_SESSION['plataforma_edicao_sucesso']);

$db = Database::getConnection();

$stmt = $db->query("
    SELECT
        id,
        nome,
        slug,
        ativo
    FROM barbearias
    ORDER BY id DESC
");

$barbearias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($barbearias);
$ativas = count(array_filter(
    $barbearias,
    fn($b) => (int)$b['ativo'] === 1
));
$inativas = $total - $ativas;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administração da Plataforma</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px;
            background: #10151d;
            color: #f1f5f9;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 960px;
            margin: 0 auto;
        }

        .topo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
        }

        h1 {
            margin: 0;
            font-size: 24px;
        }

        .subtitulo {
            color: #94a3b8;
            margin-top: 6px;
            font-size: 14px;
        }

        .resumo {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }

        .card {
            background: #1c2531;
            padding: 20px;
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
            padding: 18px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid #334155;
        }

        .item:last-child {
            border-bottom: none;
        }

        .nome {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .slug {
            color: #94a3b8;
            font-size: 13px;
        }

        .status {
            font-size: 12px;
            font-weight: bold;
            padding: 6px 10px;
            border-radius: 20px;
        }

        .ativo {
            background: #14532d;
            color: #bbf7d0;
        }

        .inativo {
            background: #7f1d1d;
            color: #fecaca;
        }

        @media (max-width: 480px) {
            body { padding: 14px; }
            .resumo { gap: 8px; }
            .card { padding: 12px; }
            .card strong { font-size: 22px; }
            h1 { font-size: 20px; }
        }
    </style>
</head>
<body>
<div class="container">

    <div class="topo">
        <div>
            <h1>Administração da Plataforma</h1>
            <div class="subtitulo">
                Olá, <?= htmlspecialchars($admin['nome'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
    </div>

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
            <span>Barbearias</span>
            <strong><?= $total ?></strong>
        </div>

        <div class="card">
            <span>Ativas</span>
            <strong><?= $ativas ?></strong>
        </div>

        <div class="card">
            <span>Inativas</span>
            <strong><?= $inativas ?></strong>
        </div>
    </div>
    <div style="margin-bottom: 24px;">
        <a href="/agenda/plataforma/nova"
        style="
            display: inline-block;
            padding: 12px 20px;
            background: #2563eb;
            color: white;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
            border-radius: 8px;
        ">
            + Nova barbearia
        </a>
    </div>

    <h2>Barbearias cadastradas</h2>

    <div class="lista">
        <?php if (!$barbearias): ?>
            <div class="item">
                Nenhuma barbearia cadastrada.
            </div>
        <?php endif; ?>

        <?php foreach ($barbearias as $barbearia): ?>
            <div class="item">
                <div>
                    <div class="nome">
                        <?= htmlspecialchars(
                            $barbearia['nome'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </div>

                    <div class="slug">
                        /agenda/<?= htmlspecialchars(
                            $barbearia['slug'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </div>
                </div>

                <div style="
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    flex-wrap: wrap;
                    justify-content: flex-end;
                ">
                    <span class="status <?= (int)$barbearia['ativo'] === 1 ? 'ativo' : 'inativo' ?>">
                        <?= (int)$barbearia['ativo'] === 1 ? 'Ativa' : 'Inativa' ?>
                    </span>

                    <a href="/agenda/plataforma/editar?id=<?= (int)$barbearia['id'] ?>"
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