<?php
/**
 * topper. — a agenda de uma pessoa em iCalendar (§31.5): ical.php?t=<token>. Sem sessão: o token é a chave
 * (gerado e revogado em Perfil › Ligações › «Subscrever a agenda»). Só leitura; um token errado dá sempre 404.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/ical.php';
session_write_close();

$u = ical_user($pdo, is_string($_GET['t'] ?? null) ? $_GET['t'] : '');
if (!$u) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo "Este link de agenda não existe ou foi revogado.\n"; exit; }
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="topper.ics"');
header('Cache-Control: private, max-age=300');
header('X-Robots-Tag: noindex, nofollow');
echo ical_feed($pdo, $u);
