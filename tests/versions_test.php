<?php
// §32.2: substituir guarda a versão anterior; a Drive mostra as versões anteriores de um ficheiro, para descarregar.
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';
require_once __DIR__ . '/../admin/inc/drive_share.php';

$k = tests_put('Empresa/Teste32/versoes-' . bin2hex(random_bytes(3)) . '.txt', 'v1');
drive_version_keep($pdo, $k, 'Ana', null);
tests_put($k, 'v2');
$r = tests_http('POST', '/admin/drive_api.php', ['do' => 'versions', 'key' => $k], 1);
t_eq($r['code'], 200, 'lista as versões');
$v = $r['json']['items'][0] ?? [];
t_eq($v['by'] ?? '', 'Ana', 'com quem substituiu');
t_true(str_contains((string)($v['url'] ?? ''), 'X-Amz-Signature') || str_contains((string)($v['url'] ?? ''), 'Signature'), 'com um link para descarregar');
$c = curl_init((string)($v['url'] ?? '')); curl_setopt($c, CURLOPT_RETURNTRANSFER, true); $body = curl_exec($c); curl_close($c);
t_eq($body, 'v1', 'o link descarrega a versão anterior');
foreach ($pdo->query('SELECT ver_key FROM drive_versions WHERE obj_key = ' . $pdo->quote($k))->fetchAll(PDO::FETCH_COLUMN) as $vk) r2_delete($vk);
r2_delete($k); drive_catalog_invalidate();
t_done();
