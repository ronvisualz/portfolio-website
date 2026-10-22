<?php
/**
 * topper. admin — a foto de perfil de uma pessoa (§17.10). Só com sessão: as fotos
 * ficam em inc/avatars/, que nunca é servido diretamente.
 */
require_once __DIR__ . '/inc/bootstrap.php';
if (current_user() === null) { http_response_code(401); exit; }
$st = $pdo->prepare('SELECT photo FROM users WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$name = (string)$st->fetchColumn();
$file = __DIR__ . '/inc/avatars/' . $name;
if (!preg_match('/^u\d+-[0-9a-f]{12}\.jpg$/', $name) || !is_file($file)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=604800');
header('X-Content-Type-Options: nosniff');
readfile($file);
