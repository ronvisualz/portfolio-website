<?php
/**
 * topper. admin — «Exportar para Excel»: os dados da plataforma num .xlsx.
 *
 * Quatro folhas — Negócios, Custos, Leads, Contactos — já com as contas
 * feitas (inc/export.php). Serve para o contabilista. Só para admins.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/export.php';
require __DIR__ . '/inc/xlsx.php';
$user = require_login();
if (!is_admin()) { http_response_code(403); exit('Só os administradores exportam os dados.'); }
session_write_close();

try {
    $file = xlsx_build(export_sheets($pdo));
} catch (Throwable $e) {
    error_log('export: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Não foi possível criar o ficheiro Excel.');
}
$name = 'TOPPER DIGITAL ' . (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . filesize($file));
readfile($file);
@unlink($file);
