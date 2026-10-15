<?php
// Canoniza DDD + numero brasileiro (10 ou 11 digitos) como 55 + DDD + numero.
function normalizarWhatsappBR(string $telefone): ?string {
    $n = preg_replace('/\D+/', '', $telefone);
    if (str_starts_with($n, '55') && in_array(strlen($n), [12, 13], true)) $n = substr($n, 2);
    if (!in_array(strlen($n), [10, 11], true)) return null;
    $ddd = (int)substr($n, 0, 2);
    if ($ddd < 11 || $ddd > 99) return null;
    return '55' . $n;
}
