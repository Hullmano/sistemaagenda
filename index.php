<?php
// index.php
// Garante que o relógio do sistema use o fuso horário oficial de Brasília
date_default_timezone_set('America/Sao_Paulo');

require_once 'db.php';

// 1. Captura e limpa a URL enviada pelo .htaccess
$url = isset($_GET['url']) ? trim($_GET['url'], '/') : '';

if ($url === '') {
    echo "<h1>Bem-vindo ao SaaS de Barbearias (Landing Page)</h1>";
    exit;
}

// 2. Quebra a URL por barras para identificar os comandos
// Ex no Droplet: barbearia_do_tiao/admin/servicos
// $partes[0] = 'barbearia_do_tiao'
// $partes[1] = 'admin'
// $partes[2] = 'servicos'
$partes = explode('/', $url);
$slug_filtrado = $partes[0] ?? ''; 

// 3. Busca a barbearia ativa no banco de dados do Droplet
$db = \Database::getConnection();
$stmt = $db->prepare("SELECT id, nome, whatsapp_num FROM barbearias WHERE slug = ? AND ativo = 1 LIMIT 1");
$stmt->execute([$slug_filtrado]);
$barbearia = $stmt->fetch();

if ($barbearia) {
    $barbearia_id = $barbearia['id'];
    $barbearia_nome = $barbearia['nome'];
    
    // 4. VALIDAÇÃO DE ROTAS ADMINISTRATIVAS
    // Verifica se o segundo segmento da URL existe e é estritamente 'admin'
    // Ex: /agenda/barbearia_do_tiao/admin
    if (isset($partes[1]) && $partes[1] === 'admin') {

        require_once __DIR__ . '/helpers/auth.php';

        iniciarSessaoAdmin();

        $sub_acao = $partes[2] ?? '';

        // A tela de login pode ser acessada sem autenticação.
        if ($sub_acao === 'login') {
            $usuario = usuarioLogado();
            if (
                $usuario &&
                (int) $usuario['barbearia_id'] === (int) $barbearia_id
            ) {
                header(
                    'Location: /agenda/' .
                    rawurlencode($slug_filtrado) .
                    '/admin'
                );
                exit;
            }
            include __DIR__ . '/views/admin_login.php';
            exit;
        }

        // Todas as demais páginas administrativas exigem login.
        $usuario = usuarioLogado();
        if (!$usuario) {
            header(
                'Location: /agenda/' .
                rawurlencode($slug_filtrado) .
                '/admin/login'
            );
            exit;
        }

        // Impede acesso administrativo a outra barbearia.
        if ((int) $usuario['barbearia_id'] !== (int) $barbearia_id) {
            http_response_code(403);
            exit('Acesso negado a esta barbearia.');
        }

        // Áreas restritas ao proprietário.
        $rotas_dono = ['servicos', 'horarios', 'whatsapp'];

        if (
            in_array($sub_acao, $rotas_dono, true) &&
            $usuario['nivel'] !== 'dono'
        ) {
            http_response_code(403);
            exit('Esta área é exclusiva do proprietário.');
        }
        if ($sub_acao === 'servicos') {
            include __DIR__ . '/views/admin_servicos.php';
        } elseif ($sub_acao === 'horarios') {
            include __DIR__ . '/views/admin_horarios.php';
        } elseif ($sub_acao === 'whatsapp') {
            include __DIR__ . '/views/admin_whatsapp.php';
        } elseif ($sub_acao === '') {
            include __DIR__ . '/views/admin_painel.php';
        } else {
            http_response_code(404);
            exit('Página não encontrada.');
        }
    } else {
        include __DIR__ . '/views/cliente_agendamento.php';
    }


} else {
    http_response_code(404);
    echo "<h1>404 - Barbearia não encontrada</h1>";
    echo "<p>O link que você acessou não existe no nosso servidor de produção.</p>";
}
