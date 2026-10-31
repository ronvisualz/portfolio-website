<?php
// §32.1: mover instantâneo — as partes puras: o resolvedor (onde está agora o que o utilizador já vê no destino) e a
// sobreposição do catálogo (o item aparece logo no destino, sem duplicar o que já foi copiado).
require __DIR__ . '/boot.php';
require_once __DIR__ . '/../admin/inc/drive_moves.php';

$mv = fn(int $id, string $src, string $dst) => ['id' => $id, 'src' => $src, 'dst' => $dst, 'is_folder' => substr($src, -1) === '/' ? 1 : 0];

// resolvedor
t_eq(drive_resolve_with([$mv(1, 'A/x.txt', 'B/x.txt')], 'B/x.txt'), 'A/x.txt', 'ficheiro: o destino aponta para a origem');
t_eq(drive_resolve_with([$mv(1, 'A/x.txt', 'B/x.txt')], 'B/y.txt'), 'B/y.txt', 'o que não foi movido fica igual');
t_eq(drive_resolve_with([$mv(1, 'A/', 'B/F/')], 'B/F/sub/y.jpg'), 'A/sub/y.jpg', 'pasta: o caminho de dentro segue');
t_eq(drive_resolve_with([$mv(1, 'A/', 'B/F/')], 'B/Fx/y.jpg'), 'B/Fx/y.jpg', 'um prefixo parecido não conta');
t_eq(drive_resolve_with([$mv(1, 'A/', 'B/'), $mv(2, 'B/', 'C/')], 'C/k.txt'), 'A/k.txt', 'cadeia A→B→C');
t_eq(drive_resolve_with([$mv(1, 'A/x.txt', 'B/x.txt'), $mv(2, 'B/x.txt', 'C/z.txt')], 'C/z.txt'), 'A/x.txt', 'cadeia com renomear');
$t = microtime(true);
$r = drive_resolve_with([$mv(1, 'A/', 'B/'), $mv(2, 'B/', 'A/')], 'A/k.txt');
t_true(is_string($r) && microtime(true) - $t < 0.1, 'ciclo A→B→A não prende (' . $r . ')');
$many = [];
for ($i = 1; $i <= 30; $i++) $many[] = $mv($i, "P$i/", 'P' . ($i + 1) . '/');
t_eq(drive_resolve_with($many, 'P31/k'), 'P21/k', 'no máximo 10 saltos');

// sobreposição do catálogo
$o = fn(string $k, int $s = 1) => ['key' => $k, 'size' => $s, 'modified' => 1, 'etag' => md5($k)];
$cat = [$o('A/'), $o('A/1.txt'), $o('A/2.txt'), $o('A/sub/3.txt'), $o('Z/outro.txt')];
$ov = array_column(drive_moves_overlay($cat, [$mv(1, 'A/', 'B/A/')]), null, 'key');
t_true(isset($ov['B/A/1.txt'], $ov['B/A/2.txt'], $ov['B/A/sub/3.txt'], $ov['B/A/']), 'os 3 ficheiros e a pasta aparecem no destino');
t_true(!isset($ov['A/1.txt']) && !isset($ov['A/']), 'e já não aparecem na origem');
t_true(isset($ov['Z/outro.txt']), 'o resto fica');
t_true(!empty($ov['B/A/1.txt']['pending']), 'marcados como «a arrumar»');
$cat2 = [$o('A/1.txt'), $o('A/2.txt'), $o('B/A/1.txt')];   // o 1 já foi copiado
$ov2 = drive_moves_overlay($cat2, [$mv(1, 'A/', 'B/A/')]);
t_eq(count(array_filter($ov2, fn($x) => $x['key'] === 'B/A/1.txt')), 1, 'o que já foi copiado não aparece duas vezes');
t_eq(count($ov2), 2, 'dois ficheiros no total');
$ov3 = array_column(drive_moves_overlay([$o('A/x.txt')], [$mv(1, 'A/x.txt', 'B/x.txt'), $mv(2, 'B/x.txt', 'C/y.txt')]), null, 'key');
t_true(isset($ov3['C/y.txt']) && count($ov3) === 1, 'em cadeia, aparece no último destino');
t_done();
