<?php
require __DIR__ . '/inc/bootstrap.php';
$user = require_login();

/* Ficheiros que só quem tem sessão iniciada pode descarregar.
   A pasta downloads/ está fechada ao público (.htaccess). */
const FILES = [
    'renderfarm' => 'Instalar-Render-Farm.cmd',
];

$name = FILES[$_GET['f'] ?? ''] ?? null;
$path = $name ? __DIR__ . '/downloads/' . $name : null;
if (!$path || !is_file($path)) { http_response_code(404); exit('Ficheiro não encontrado.'); }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
