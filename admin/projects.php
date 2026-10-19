<?php
// A Carteira passou para o Painel (dashboard.php#carteira).
// Este endereço fica para os POST antigos e para links guardados.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/sheet.php';
require __DIR__ . '/inc/carteira.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    carteira_handle_post();   // redireciona para o Painel
}

$q = $_GET;
$tab = (string)($q['tab'] ?? 'todos');
unset($q['tab']);
$q = ['tab' => isset(CARTEIRA_TABS[$tab]) ? $tab : 'todos'] + $q;
redirect('/admin/dashboard.php?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) . '#carteira');
