<?php
// Links de partilha e a Faturação (segurança): «Partilhar também os documentos privados» (include_private) nunca leva a
// Faturação a quem abre o link quando quem o criou não tem o Financeiro — também quando a Faturação chega à pasta
// depois do link (as faturas de um negócio entram sozinhas em Projetos/<ID>/Faturação/). Com o Financeiro, o link que a
// inclui de propósito continua como a §32 decidiu (até o dono perder o Financeiro). E quem não tem o Financeiro não
// recebe por share_list / catalog.shares / share_quick o endereço de um link que mostra Faturação.
//   php tests/share_billing_private_test.php
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';
require_once __DIR__ . '/../admin/inc/drive_store.php';
require_once __DIR__ . '/../admin/inc/drive_moves.php';

$tag = bin2hex(random_bytes(3));
$F = 'Projetos/T7SH-' . $tag . '/';
$pub = tests_put($F . 'briefing.txt', 'ok');
$png = function (): string { $im = imagecreatetruecolor(40, 30); ob_start(); imagepng($im); return (string)ob_get_clean(); };
$api = fn(array $b, int $uid) => tests_http('POST', '/admin/drive_api.php', $b, $uid);
$fin = 1; $nofin = 3;
t_eq(tests_http('GET', '/admin/financeiro.php', [], $nofin)['code'], 403, 'o utilizador 3 não tem o Financeiro');
$visit = function (array $s, string $q = '') { return tests_http('GET', '/admin/partilha.php?t=' . $s['token'] . $q, [], 0); };
$rel = 'Faturação/recibo-' . $tag . '.png';

// 1) quem não tem o Financeiro cria um link «com os privados» numa pasta sem Faturação; a fatura chega depois
$r = $api(['do' => 'share_create', 'key' => $F, 'hours' => 24, 'include_private' => true], $nofin);
t_eq($r['code'], 200, 'sem o Financeiro: link «com os privados» numa pasta sem Faturação (' . substr($r['body'], 0, 100) . ')');
$s3 = drive_share_get($pdo, (int)($r['json']['share']['id'] ?? 0)) ?? ['token' => 'x', 'id' => 0];
$bill = tests_put($F . $rel, $png(), 'image/png');
$eB = (string)(r2_head($bill)['etag'] ?? '');
tests_put(DRIVE_THUMBS . $eB . '.jpg', $png(), 'image/jpeg');
$page = $visit($s3)['body'];
t_true(!str_contains($page, 'Faturação') && !str_contains($page, 'recibo-' . $tag), 'o visitante não vê a pasta Faturação nem o recibo');
t_eq($visit($s3, '&p=' . rawurlencode('Faturação/'))['code'], 404, 'entrar na Faturação → 404');
t_eq($visit($s3, '&f=' . rawurlencode($rel))['code'], 404, 'descarregar o recibo → 404');
t_eq($visit($s3, '&pv=1&f=' . rawurlencode($rel))['code'], 404, 'pré-visualizar o recibo → 404');
t_eq($visit($s3, '&th=1&f=' . rawurlencode($rel))['code'], 404, 'a miniatura do recibo → 404');
$zip = $visit($s3, '&zip=1');
t_true(!str_contains($zip['body'], 'recibo-' . $tag), 'o ZIP não leva o recibo (' . $zip['code'] . ')');
t_eq($visit($s3, '&f=' . rawurlencode('briefing.txt'))['code'] === 404, false, 'o resto da pasta continua a abrir');

// 2) com o Financeiro, o link que inclui de propósito continua a mostrar a Faturação (§32.3)
$s1 = drive_share_create($pdo, $F, true, 24, false, '', 'view', $fin, true);
t_true(str_contains($visit($s1)['body'], 'Faturação'), 'com o Financeiro e «com os privados»: a Faturação aparece');
t_true(in_array($visit($s1, '&f=' . rawurlencode($rel))['code'], [200, 302], true), 'e o recibo abre');

// 3) quem não tem o Financeiro não recebe o endereço desse link (que mostra Faturação)
$tok1 = $s1['token'];
t_true(!str_contains($api(['do' => 'share_list', 'key' => $F], $nofin)['body'], $tok1), 'share_list (da pasta) sem o Financeiro não traz o link que mostra Faturação');
t_true(!str_contains($api(['do' => 'share_list'], $nofin)['body'], $tok1), 'share_list (todos) também não');
t_true(!str_contains($api(['do' => 'catalog'], $nofin)['body'], $tok1), 'nem catalog.shares');
$q = $api(['do' => 'share_quick', 'key' => $F], $nofin);
t_true($q['code'] === 403 && !str_contains($q['body'], $tok1), 'share_quick numa pasta com Faturação → 403 (como share_create), sem reaproveitar o link (' . $q['code'] . ')');
t_true(str_contains($api(['do' => 'share_list', 'key' => $F], $fin)['body'], $tok1), 'com o Financeiro o link continua na lista');

// 4) o dono do link perde o Financeiro: a Faturação deixa de aparecer no link dele
$pdo->prepare("DELETE FROM permissions WHERE role = '' AND user_id = ? AND tab = 'financeiro'")->execute([$nofin]);
$pdo->prepare("INSERT INTO permissions (role, user_id, tab, level, scope) VALUES ('', ?, 'financeiro', 1, NULL)")->execute([$nofin]);
try {
    $s3f = drive_share_create($pdo, $F, true, 24, false, '', 'view', $nofin, true);
    t_true(in_array($visit($s3f, '&f=' . rawurlencode($rel))['code'], [200, 302], true), 'o 3 com o Financeiro: o link dele com os privados mostra o recibo');
} finally {
    $pdo->prepare("DELETE FROM permissions WHERE role = '' AND user_id = ? AND tab = 'financeiro'")->execute([$nofin]);
}
t_eq($visit($s3f, '&f=' . rawurlencode($rel))['code'], 404, 'sem o Financeiro (retirado), o mesmo link já não mostra o recibo');

// 5) o «info» da pasta (painel de detalhes da Drive) também não dá o endereço do link que mostra Faturação
t_true(!str_contains($api(['do' => 'info', 'key' => $F], $nofin)['body'], $tok1), 'sem o Financeiro: info da pasta não traz o link que mostra Faturação');
t_true(str_contains($api(['do' => 'info', 'key' => $F], $fin)['body'], $tok1), 'com o Financeiro: info traz o link');

// 6) o endereço obtido ANTES de a fatura chegar: a regra não pode depender do que a pasta tem no momento
$G = 'Projetos/T7SH2-' . $tag . '/';
$pubG = tests_put($G . 'briefing.txt', 'ok');
$sG = drive_share_create($pdo, $G, true, 24, false, '', 'view', $fin, true);   // de quem tem o Financeiro, «com os privados», ainda sem Faturação
t_true(!str_contains($api(['do' => 'share_list', 'key' => $G], $nofin)['body'], $sG['token']), 'sem o Financeiro: share_list não traz o link «com os privados» de quem tem o Financeiro, mesmo sem Faturação ainda');
t_true(!str_contains($api(['do' => 'catalog'], $nofin)['body'], $sG['token']), 'nem catalog.shares');
t_true(!str_contains($api(['do' => 'info', 'key' => $G], $nofin)['body'], $sG['token']), 'nem info');
$qG = $api(['do' => 'share_quick', 'key' => $G], $nofin);
t_true($qG['code'] === 200 && !str_contains($qG['body'], $sG['token']), 'share_quick não reaproveita o link «com os privados» (cria um simples) (' . $qG['code'] . ')');
$billG = tests_put($G . $rel, $png(), 'image/png');   // a fatura do negócio chega depois
$qGs = drive_share_get($pdo, (int)($qG['json']['share']['id'] ?? 0)) ?? ['token' => 'x', 'id' => 0];
t_eq($visit($qGs, '&f=' . rawurlencode($rel))['code'], 404, 'o link rápido de quem não tem o Financeiro não mostra a fatura que chegou depois');

// 7) um link de quem não tem o Financeiro que passa a estar dentro da Faturação (os links seguem os movimentos)
$H = 'Projetos/T7SH3-' . $tag . '/';
$docH = tests_put($H . 'Docs/notas.txt', 'ok');
$rH = $api(['do' => 'share_create', 'key' => $H . 'Docs/', 'hours' => 24], $nofin);
$sH = drive_share_get($pdo, (int)($rH['json']['share']['id'] ?? 0)) ?? ['token' => 'x', 'id' => 0, 'k' => ''];
$mv = $api(['do' => 'move', 'from' => $H . 'Docs/', 'to' => $H . 'Faturação/Docs/', 'on_conflict' => 'error'], $fin);
t_eq($mv['code'], 200, 'com o Financeiro: a pasta partilhada vai para dentro da Faturação (' . substr($mv['body'], 0, 80) . ')');
for ($i = 0; $i < 5 && drive_moves_pending($pdo); $i++) drive_moves_run($pdo, 30);
$sH = drive_share_get($pdo, (int)$sH['id']) ?? $sH;
t_eq((string)($sH['k'] ?? ''), $H . 'Faturação/Docs/', 'o link acompanhou a pasta');
$billH = tests_put($H . 'Faturação/Docs/recibo-' . $tag . '.png', $png(), 'image/png');
t_eq($visit($sH, '&f=' . rawurlencode('recibo-' . $tag . '.png'))['code'], 404, 'o link de quem não tem o Financeiro, agora dentro da Faturação, não mostra a fatura');
t_true(!str_contains($visit($sH)['body'], 'recibo-' . $tag), 'nem a lista');

$pdo->prepare('UPDATE drive_shares SET revoked = 1 WHERE id IN (?, ?, ?, ?, ?, ?)')->execute([(int)$s3['id'], (int)$s1['id'], (int)$s3f['id'], (int)$sG['id'], (int)$qGs['id'], (int)$sH['id']]);
foreach ([$pub, $bill, DRIVE_THUMBS . $eB . '.jpg', $pubG, $billG, $billH, $H . 'Faturação/Docs/notas.txt', $docH] as $k) r2_delete($k);
drive_catalog_invalidate();
t_done();
