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

$pdo->prepare('UPDATE drive_shares SET revoked = 1 WHERE id IN (?, ?, ?)')->execute([(int)$s3['id'], (int)$s1['id'], (int)$s3f['id']]);
r2_delete($pub); r2_delete($bill); r2_delete(DRIVE_THUMBS . $eB . '.jpg'); drive_catalog_invalidate();
t_done();
