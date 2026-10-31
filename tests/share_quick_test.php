<?php
// §32.6: «Copiar link de partilha» cria (ou reaproveita) uma partilha «Só ver» com a validade por defeito.
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';

$k = tests_put('Empresa/Teste32/rapido-' . bin2hex(random_bytes(3)) . '.txt');
$q = fn(string $key, int $uid = 1) => tests_http('POST', '/admin/drive_api.php', ['do' => 'share_quick', 'key' => $key], $uid);

$r1 = $q($k);
t_eq($r1['code'], 200, 'cria a partilha rápida');
t_true(($r1['json']['reused'] ?? null) === false, 'a primeira vez cria uma nova');
t_true((bool)preg_match('~/admin/partilha\.php\?t=[\w-]{43}$~', (string)($r1['json']['url'] ?? '')), 'devolve o URL da partilha');
$left = (int)($r1['json']['share']['exp'] ?? 0) - time();
t_true($left > 23 * 3600 && $left <= 24 * 3600 + 60, 'validade por defeito de 24 h (' . $left . ' s)');

$r2 = $q($k);
t_eq($r2['json']['reused'] ?? null, true, 'a segunda vez reaproveita');
t_eq($r2['json']['share']['id'] ?? 0, $r1['json']['share']['id'] ?? -1, 'mesma partilha');

// uma partilha com password não serve para a partilha rápida
$k2 = tests_put('Empresa/Teste32/rapido-pw-' . bin2hex(random_bytes(3)) . '.txt');
$pw = tests_http('POST', '/admin/drive_api.php', ['do' => 'share_create', 'key' => $k2, 'hours' => 24, 'password' => 'segredo1']);
$r3 = $q($k2);
t_true(($r3['json']['share']['id'] ?? 0) !== ($pw['json']['share']['id'] ?? 0) && ($r3['json']['reused'] ?? null) === false, 'não reaproveita uma partilha com password');

// a expirar dentro de 1 h: cria outra
$k3 = tests_put('Empresa/Teste32/rapido-curto-' . bin2hex(random_bytes(3)) . '.txt');
$short = tests_http('POST', '/admin/drive_api.php', ['do' => 'share_create', 'key' => $k3, 'hours' => 1]);
$r4 = $q($k3);
t_true(($r4['json']['share']['id'] ?? 0) !== ($short['json']['share']['id'] ?? 0), 'não reaproveita uma partilha prestes a expirar');

// documentos de faturação: só quem tem o Financeiro
$k4 = tests_put('Empresa/Financeiro/2026/10/rapido-' . bin2hex(random_bytes(3)) . '.pdf', '%PDF-1.4', 'application/pdf');
$noFin = (int)$pdo->query("SELECT id FROM users WHERE is_active = 1 AND role NOT IN ('admin','superadmin') ORDER BY id LIMIT 1")->fetchColumn();
if ($noFin) t_eq($q($k4, $noFin)['code'], 403, 'sem acesso ao Financeiro não partilha documentos de faturação');
foreach ([$k, $k2, $k3, $k4] as $x) r2_delete($x);   // limpa a bancada
drive_catalog_invalidate();
t_done();
