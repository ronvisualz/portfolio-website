<?php
// Um ficheiro com uma versão anterior (substituído por um link «Pode editar»), para o painel de detalhes.
require __DIR__ . '/../boot.php';
require __DIR__ . '/../fixtures.php';
require_once __DIR__ . '/../../admin/inc/drive_share.php';
$k = tests_put('Empresa/Teste32/com-versoes.txt', 'v1');
if (!drive_versions($pdo, $k)) drive_version_keep($pdo, $k, 'Ana Visitante', null);
tests_put($k, 'v2');
