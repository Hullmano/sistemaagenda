<?php
// testar_agenda.php

// 1. Carrega as dependências necessárias
require_once 'db.php';
require_once 'helpers/agenda.php';

// 2. Parâmetros de simulação simulando a Barbearia do Tião
$barbearia_id = 1; 
$data_teste = '2026-09-29'; // Uma terça-feira
$duracao_servico = 60;       // Simulando um serviço longo de 60 minutos (Cabelo + Barba)

echo "<h2>🧪 Executando Teste do Algoritmo de Agenda</h2>";
echo "<p><strong>Barbearia ID:</strong> $barbearia_id</p>";
echo "<p><strong>Data do Teste:</strong> $data_teste (Terça-feira)</p>";
echo "<p><strong>Duração do Serviço Solicitado:</strong> $duracao_servico minutos</p>";
echo "<hr>";

try {
    // 3. Obtém a conexão com o banco de dados global
    $db = \Database::getConnection();

    // 4. Executa a nossa função inteligente
    $horarios_livres = obterHorariosLivres($db, $barbearia_id, $data_teste, $duracao_servico);

    // 5. Exibe o resultado na tela de forma amigável
    if (empty($horarios_livres)) {
        echo "<p style='color: red;'>⚠️ Nenhum horário disponível encontrado para este dia ou a barbearia está fechada.</p>";
    } else {
        echo "<h3>✅ Horários Disponíveis Gerados:</h3>";
        echo "<p>O algoritmo filtrou o horário de almoço e os agendamentos já ocupados.</p>";
        
        echo "<ul style='font-family: monospace; font-size: 16px; line-height: 1.6;'>";
        foreach ($horarios_livres as $horario) {
            echo "<li>[ ] $horario</li>";
        }
        echo "</ul>";
        
        echo "<p><strong>Total de brechas livres encontradas:</strong> " . count($horarios_livres) . "</p>";
    }

} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Erro ao testar:</strong> " . $e->getMessage() . "</p>";
}
