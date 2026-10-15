<?php
// Configuracao privada: servidor /etc/agenda-app.php; Laragon config.local.php (ignorado no Git).
function agendaConfig(): array {
    static $config = null;
    if ($config !== null) return $config;
    $path = is_file('/etc/agenda-app.php') ? '/etc/agenda-app.php' : __DIR__ . '/config.local.php';
    if (!is_file($path)) throw new RuntimeException('Configuracao privada ausente.');
    $config = require $path;
    if (!is_array($config)) throw new RuntimeException('Configuracao privada invalida.');
    return $config;
}
