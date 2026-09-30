<?php
// index.php
require_once 'db.php';

$url = isset($_GET['url']) ? trim($_GET['url'], '/') : '';

if ($url === '') {
    echo "<h1>Bem-vindo ao SaaS de Barbearias (Landing Page)</h1>";
    exit;
}

// Quebra a URL para entender os segmentos (Ex: barbearia_do_tiao/admin)
$partes = explode('/', $url);
$slug_filtrado = $partes[0]; // Sempre o primeiro segmento é a barbearia

// Busca a barbearia ativa no banco de dados
$db = \Database::getConnection();
$stmt = $db->prepare("SELECT id, nome, whatsapp_num FROM barbearias WHERE slug = ? AND ativo = 1 LIMIT 1");
$stmt->execute([$slug_filtrado]);
$barbearia = $stmt->fetch();

if ($barbearia) {
    $barbearia_id = $barbearia['id'];
    $barbearia_nome = $barbearia['nome'];
    
    // Dentro do if ($barbearia) no seu index.php
    if (isset($partes[1]) && $partes[1] === 'admin') {
        
        if (isset($partes[2]) && $partes[2] === 'servicos') {
            include 'views/admin_servicos.php';
        } elseif (isset($partes[2]) && $partes[2] === 'horarios') {
            include 'views/admin_horarios.php';
        } else {
            include 'views/admin_painel.php';
        }
        
    } else {
        include 'views/cliente_agendamento.php';
    }

} else {
    http_response_code(404);
    echo "<h1>404 - Barbearia não encontrada</h1>";
}
