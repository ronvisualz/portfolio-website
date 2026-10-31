<?php
// A plataforma em modo local (bancada), no âmbito global: $pdo, $CONFIG e as funções. Incluir no topo do teste.
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../admin/inc/bootstrap.php';
