<?php
// §32.2: o que cada partilha deixa fazer a quem tem o link — decidido no servidor, e «apagar» nunca.
require __DIR__ . '/lib.php';
define('TOPPER_ADMIN', true);
require __DIR__ . '/../admin/inc/drive_share.php';

$file = ['folder' => false, 'perm' => 'view', 'state' => 'ok'];
$folder = ['folder' => true, 'perm' => 'view', 'state' => 'ok'];
$want = [   // [partilha, ação, permitido?]
    [$file, 'view', true], [$file, 'download', true], [$file, 'zip', false], [$file, 'upload', false], [$file, 'mkdir', false], [$file, 'rename', false], [$file, 'replace', false], [$file, 'delete', false],
    [$folder, 'view', true], [$folder, 'download', true], [$folder, 'zip', true], [$folder, 'upload', false], [$folder, 'mkdir', false], [$folder, 'rename', false], [$folder, 'replace', false], [$folder, 'delete', false],
    [['perm' => 'edit'] + $file, 'replace', true], [['perm' => 'edit'] + $file, 'upload', false], [['perm' => 'edit'] + $file, 'rename', false], [['perm' => 'edit'] + $file, 'delete', false],
    [['perm' => 'edit'] + $folder, 'upload', true], [['perm' => 'edit'] + $folder, 'mkdir', true], [['perm' => 'edit'] + $folder, 'rename', true], [['perm' => 'edit'] + $folder, 'replace', true],
    [['perm' => 'edit'] + $folder, 'delete', false], [['perm' => 'edit'] + $folder, 'purge', false], [['perm' => 'edit'] + $folder, 'trash', false], [['perm' => 'edit'] + $folder, 'move', false],
    [['perm' => 'edit', 'state' => 'expired'] + $folder, 'upload', false], [['perm' => 'edit', 'state' => 'revoked'] + $folder, 'view', false],
];
foreach ($want as [$s, $a, $ok]) t_eq(drive_share_allows($s, $a), $ok, ($s['folder'] ? 'pasta' : 'ficheiro') . ' ' . $s['perm'] . ' (' . $s['state'] . ') → ' . $a);
t_done();
