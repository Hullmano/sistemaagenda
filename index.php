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
    if (isset($partes[1]) && $partes[1] === 'admin') {
        
        // Verifica se o terceiro segmento existe (Pode ser 'servicos' ou 'horarios')
        $sub_acao = $partes[2] ?? '';
        
        if ($sub_acao === 'servicos') {
            include 'views/admin_servicos.php';
        } elseif ($sub_acao === 'horarios') {
            include 'views/admin_horarios.php';
        } else {
            // Se for apenas slug/admin, abre o dashboard principal
            include 'views/admin_painel.php';
        }
        
    } else {
        // Se não houver 'admin' na URL, carrega a View de Agendamento do Cliente
        include 'views/cliente_agendamento.php';
    }
} else {
    http_response_code(404);
    echo "<h1>404 - Barbearia não encontrada</h1>";
    echo "<p>O link que você acessou não existe no nosso servidor de produção.</p>";
}
