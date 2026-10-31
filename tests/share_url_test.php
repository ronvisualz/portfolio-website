<?php
// §32.5: o link de partilha é sempre o URL público absoluto do domínio da plataforma, venha o pedido de onde vier.
require __DIR__ . '/boot.php';
require_once __DIR__ . '/../admin/inc/drive_store.php';

$_SERVER['HTTP_HOST'] = 'topperdigital.xyz';
$CONFIG['app_url'] = 'https://topperhub.xyz';
t_eq(drive_share_url('abc123'), 'https://topperhub.xyz/admin/partilha.php?t=abc123', 'link com o domínio da plataforma, não o do pedido');
$_SERVER['HTTP_HOST'] = 'evil.example';
t_eq(drive_share_url('abc123'), 'https://topperhub.xyz/admin/partilha.php?t=abc123', 'um Host forjado não muda o link');
t_done();
