<?php
// views/admin_whatsapp.php

// Configurações da sua Evolution API rodando no Docker do Droplet
$api_url_base = "http://127.0.0.1:8080";
$api_key_global = "mY@pikey"; // A senha secreta configurada no Docker
$instancia_nome = $slug_filtrado; // O próprio slug da barbearia vira o nome da instância

$status_conexao = "DESCONECTADO";
$qrcode_imagem = "";

// 1. CHECAGEM SÊNIOR: Verifica se a instância já existe e está conectada (Rota corrigida para v2)
$ch = curl_init("$api_url_base/instance/connectionStatus/$instancia_nome");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
// ADICIONADO: Envio dos dois headers para garantir compatibilidade total na v2
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "X-API-Key: $api_key_global",
    "apikey: $api_key_global"
]);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code === 200) {
    $dados_instancia = json_decode($response, true);
    // Ajustado para o padrão de retorno da v2 (compara se o status é 'open' ou 'connected')
    $status_atual = $dados_instancia['status'] ?? $dados_instancia['connectionStatus'] ?? '';
    if ($status_atual === 'open' || $status_atual === 'connected') {
        $status_conexao = "CONECTADO";
    }
}

// 2. LÓGICA DE SOLICITAÇÃO DE QR CODE (Se o barbeiro clicar no botão)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gerar_qr'])) {
    
    // Se a instância não existe (HTTP 404), manda criar automaticamente primeiro
    if ($http_code === 404) {
        $ch = curl_init("$api_url_base/instance/create");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            "instanceName" => $instancia_nome,
            "integration" => "WHATSAPP-BAILEYS", // ADICIONADO: Obrigatório na v2
            "qrcode" => true
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json", 
            "X-API-Key: $api_key_global",
            "apikey: $api_key_global"
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // Solicita o QR Code para a Evolution API
    $ch = curl_init("$api_url_base/instance/connect/$instancia_nome");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-API-Key: $api_key_global",
        "apikey: $api_key_global"
    ]);
    $res_qr = curl_exec($ch);
    curl_close($ch);

    $dados_qr = json_decode($res_qr, true);
    
    // Captura o QR Code em formato Base64 (texto que vira imagem)
    if (isset($dados_qr['base64'])) {
        $qrcode_imagem = $dados_qr['base64'];
    } elseif (isset($dados_qr['qrcode']['base64'])) { // Fallback para variação de nós da v2
        $qrcode_imagem = $dados_qr['qrcode']['base64'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conectar WhatsApp - <?php echo htmlspecialchars($barbearia_nome); ?></title>
    <!-- Links Homologados via cdnjs -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        :root { --bg-main: #0B0F19; --bg-card: #151B2C; --border-color: #222B45; --text-primary: #F4F6F9; --text-secondary: #8F9BB3; }
        body { background-color: var(--bg-main); color: var(--text-primary); font-family: 'Plus Jakarta Sans', sans-serif; }
        .navbar-custom { background-color: var(--bg-card); border-bottom: 1px solid var(--border-color); }
        .wa-card { background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar navbar-custom sticky-top py-3">
        <div class="container-fluid mx-auto d-flex justify-content-between align-items-center" style="max-width: 480px;">
            <a href="/agenda/<?php echo $slug_filtrado; ?>/admin" class="text-secondary text-decoration-none small">
                <i class="fa-solid fa-chevron-left me-1"></i> Painel
            </a>
            <span class="fw-bold text-white">Integração WhatsApp</span>
            <div></div>
        </div>
    </nav>

    <!-- Conteúdo -->
    <main class="container py-4" style="max-width: 480px;">
        <div class="wa-card p-4 shadow-sm text-center">
            
            <div class="mb-4">
                <i class="fa-brands fa-whatsapp display-1 <?php echo $status_conexao === 'CONECTADO' ? 'text-success' : 'text-secondary'; ?>"></i>
            </div>

            <h5 class="fw-bold mb-1">Status do Robô de Lembretes</h5>
            
            <?php if($status_conexao === 'CONECTADO'): ?>
                <div class="badge bg-success-subtle text-success fs-6 rounded-pill px-3 py-2 mt-2 border border-success border-opacity-25 mb-4">
                    <i class="fa-solid fa-circle-check me-1"></i> SISTEMA ATIVO
                </div>
                <p class="text-secondary small">O WhatsApp do seu salão está pareado com o nosso Droplet [INDEX]. Os lembretes automáticos de 1 hora de antecedência estão sendo disparados em background para seus clientes [INDEX]!</p>
            <?php else: ?>
                <div class="badge bg-danger-subtle text-danger fs-6 rounded-pill px-3 py-2 mt-2 border border-danger border-opacity-25 mb-4">
                    <i class="fa-solid fa-circle-exclamation me-1"></i> DESCONECTADO
                </div>
                <p class="text-secondary small mb-4">Para ativar os envios automatizados de mensagens, você precisa vincular o WhatsApp da sua barbearia ao nosso sistema.</p>

                <!-- Formulário para Gerar o QR Code -->
                <form method="POST">
                    <button type="submit" name="gerar_qr" class="btn btn-success fw-bold px-4 py-2 rounded-3 shadow w-100">
                        <i class="fa-solid fa-qrcode me-2"></i> Gerar QR Code de Conexão
                    </button>
                </form>
            <?php endif; ?>

            <!-- Exibição Dinâmica do QR Code Gerado pela Evolution -->
            <?php if(!empty($qrcode_imagem)): ?>
                <div class="mt-4 p-3 bg-white rounded-4 inline-block mx-auto shadow" style="max-width: 280px;">
                    <p class="text-dark small fw-bold mb-2"><i class="fa-solid fa-camera me-1"></i> Abra o WhatsApp e escaneie:</p>
                    <img src="<?php echo $qrcode_imagem; ?>" alt="QR Code Evolution API" class="img-fluid rounded">
                    <small class="text-muted d-block mt-2 font-monospace" style="font-size: 0.65rem;">Aguardando pareamento...</small>
                </div>
                <button class="btn btn-sm btn-outline-light border-secondary mt-3 w-100 text-xs py-2" onclick="window.location.reload();">
                    <i class="fa-solid fa-rotate me-1"></i> Já escaneei, atualizar status
                </button>
            <?php endif; ?>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0"></script>
</body>
</html>
