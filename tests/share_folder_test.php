<?php
// §32.3 (segurança): uma pasta partilhada sem «Partilhar também os documentos privados» nunca os mostra nem os entrega;
// e a navegação por subpastas não sai da pasta partilhada.
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';
require_once __DIR__ . '/../admin/inc/drive_store.php';

$priv = tests_put('Empresa/Documentos legais/contrato-sh-' . bin2hex(random_bytes(2)) . '.pdf', '%PDF-1.4', 'application/pdf');
$pub = tests_put('Empresa/Teste32/publico-sh.txt', 'ok');
$rel = substr($priv, strlen('Empresa/'));
$s = drive_share_create($pdo, 'Empresa/', true, 24, false, '', 'view', 1, false);
$P = '/admin/partilha.php?t=' . $s['token'];
$page = tests_http('GET', $P, [], 0)['body'];
t_true(!str_contains($page, 'Documentos legais'), 'a pasta privada não aparece');
t_eq(tests_http('GET', $P . '&p=' . rawurlencode('Documentos legais/'), [], 0)['code'], 404, 'entrar na pasta privada → 404');
t_eq(tests_http('GET', $P . '&f=' . rawurlencode($rel), [], 0)['code'], 404, 'descarregar um documento privado → 404');
t_eq(tests_http('GET', $P . '&pv=1&f=' . rawurlencode($rel), [], 0)['code'], 404, 'pré-visualizar um documento privado → 404');
t_eq(tests_http('GET', $P . '&p=' . rawurlencode('../'), [], 0)['code'], 404, 'subir acima da pasta partilhada → 404');
t_eq(tests_http('GET', $P . '&p=' . rawurlencode('Teste32/'), [], 0)['code'], 200, 'uma subpasta normal abre');
// «Pode editar» sem os privados: também não escreve lá dentro
$e = drive_share_create($pdo, 'Empresa/', true, 24, false, '', 'edit', 1, false);
$E = '/admin/partilha.php?t=' . $e['token'];
$ft = preg_match('/data-ft="([^"]+)"/', tests_http('GET', $E, [], 0)['body'], $m) ? $m[1] : '';
$post = fn(array $b) => tests_http('POST', $E, $b + ['name' => 'Ana'], 0, false, ['X-Share-Form: ' . $ft])['code'];
t_eq($post(['a' => 'sign', 'rel' => 'Documentos legais/novo.pdf', 'size' => 5]), 404, '«Pode editar» não carrega para a pasta privada');
t_eq($post(['a' => 'sign', 'rel' => $rel, 'size' => 5, 'replace' => true]), 404, '«Pode editar» não substitui um documento privado');
t_eq($post(['a' => 'rename', 'rel' => $rel, 'to' => 'x.pdf']), 404, '«Pode editar» não renomeia um documento privado');
t_eq($post(['a' => 'rename', 'rel' => 'Teste32/publico-sh.txt', 'to' => '../Documentos legais/y.txt']), 400, 'nem renomeia para lá');
t_eq($post(['a' => 'mkdir', 'rel' => 'Documentos legais/Nova']), 404, 'nem cria pastas lá dentro');
t_eq(tests_http('GET', $P . '&th=1&f=' . rawurlencode($rel), [], 0)['code'], 404, 'nem a miniatura de um documento privado');
$pdo->prepare('UPDATE drive_shares SET revoked = 1 WHERE id = ?')->execute([$e['id']]);
$s2 = drive_share_create($pdo, 'Empresa/', true, 24, false, '', 'view', 1, true);
t_true(str_contains(tests_http('GET', '/admin/partilha.php?t=' . $s2['token'], [], 0)['body'], 'Documentos legais'), 'com «incluir privados» aparece');
$pdo->prepare('UPDATE drive_shares SET revoked = 1 WHERE id IN (?, ?)')->execute([$s['id'], $s2['id']]);
r2_delete($priv); r2_delete($pub); drive_catalog_invalidate();
t_done();
