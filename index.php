<?php
// index.php
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
    // Verifica se o segundo segmento existe e é estritamente 'admin'
    // --- BLOCO CORRIGIDO DE ROTAS NO INDEX.PHP ---
    // Verifica se o segundo segmento da URL existe e é estritamente 'admin'
    // Ex: /agenda/barbearia_do_tiao/admin
    if (isset($partes[1]) && $partes[1] === 'admin') {
        
        // Verifica o terceiro segmento da URL para carregar as sub-telas
        $sub_acao = isset($partes[2]) ? $partes[2] : '';
        
        if ($sub_acao === 'servicos') {
            // Ex: /agenda/barbearia_do_tiao/admin/servicos
            include 'views/admin_servicos.php';
        } elseif ($sub_acao === 'horarios') {
            // Ex: /agenda/barbearia_do_tiao/admin/horarios
            include 'views/admin_horarios.php';
        } elseif ($sub_acao === 'whatsapp') {
            // Ex: /agenda/barbearia_do_tiao/admin/whatsapp
            include 'views/admin_whatsapp.php';
        } else {
            // Se for apenas /admin, abre o dashboard principal
            include 'views/admin_painel.php';
        }
        
    } else {
        // Se não houver 'admin' na URL, carrega a tela do Cliente
        include 'views/cliente_agendamento.php';
    }

} else {
    http_response_code(404);
    echo "<h1>404 - Barbearia não encontrada</h1>";
    echo "<p>O link que você acessou não existe no nosso servidor de produção.</p>";
}
