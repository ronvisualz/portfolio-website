<?php
// Uma pasta com 30 ficheiros para mover (§32.1); imprime a origem e a pasta de destino.
require __DIR__ . '/../boot.php';
require __DIR__ . '/../fixtures.php';
$base = 'Empresa/Teste32/mover-ui/';
foreach (r2_list_all($base, 5) as $o) r2_delete($o['key']);
$pdo->exec("DELETE FROM drive_moves WHERE src LIKE 'Empresa/Teste32/mover-ui/%' OR dst LIKE 'Empresa/Teste32/mover-ui/%'");
for ($i = 1; $i <= 30; $i++) tests_put(sprintf('%sOrigem/Lote/f%02d.txt', $base, $i), 'x' . $i);
r2_mkdir($base . 'Destino/');
drive_catalog_invalidate();
echo $base, "\n";
