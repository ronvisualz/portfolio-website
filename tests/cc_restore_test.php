<?php
// Repor cartões (Anular depois da borracha / de apagar, §33.10): cards_restore marca a hora e quem repôs (updated_at,
// updated_by) — senão, com o tempo real ligado, o marcador de apagado do documento é mais recente do que o cartão e
// volta a escondê-lo — e devolve os cartões repostos como o Topperboard os mostra (sem os de referências apagadas;
// «sem acesso» a quem não tem o Financeiro), para o browser os publicar no tempo real.
//   php tests/cc_restore_test.php
require __DIR__ . '/boot.php';
require_once __DIR__ . '/../admin/inc/cc.php';

$NAME = 'rs' . bin2hex(random_bytes(4));
$OLD = '2020-01-01 00:00:00';
$OLDMS = strtotime('2020-01-02 00:00:00') * 1000;   // um dia de folga: o fuso do servidor não muda o resultado
$BID = cc_board_create($pdo, ['title' => 'Teste33 · restaurar', 'kind' => 'free'], 1);
$add = fn(array $d) => cc_card_add($pdo, $BID, $d, 1);
$D = $add(['type' => 'drawing', 'x' => 0, 'y' => 0, 'w' => 200, 'h' => 100, 'content' => ['paths' => [['c' => '#111111', 'w' => 4, 'pts' => [0, 0, 50, 50, 100, 20]]], 'vw' => 200, 'vh' => 100]]);
$N = $add(['type' => 'note', 'x' => 400, 'y' => 0, 'content' => ['text' => 'Nota']]);
$A = $add(['type' => 'arrow', 'content' => ['from' => $D, 'to' => $N]]);
$asset = function (string $key, string $title) use ($pdo): int {
    $pdo->prepare("INSERT INTO cc_assets (kind, title, file_key, file_name, mime, created_at, inbox) VALUES ('image', ?, ?, ?, 'image/png', NOW(), 0)")
        ->execute([$title, $key, basename($key)]);
    return (int)$pdo->lastInsertId();
};
$gone = $asset('', 'Referência que sai da biblioteca');
$I = $add(['type' => 'image', 'asset_id' => $gone, 'x' => 0, 'y' => 300, 'w' => 200, 'h' => 150]);
$bill = $asset("r2:Empresa/Financeiro/2026/11/$NAME-recibo.png", "$NAME recibo");
$F = $add(['type' => 'image', 'asset_id' => $bill, 'x' => 300, 'y' => 300, 'w' => 200, 'h' => 150]);
$pdo->prepare('INSERT INTO cc_comments (board_id, card_id, user_id, body, created_at) VALUES (?, ?, 1, ?, NOW())')->execute([$BID, $D, 'Comentário']);
$old = fn(array $ids) => $pdo->prepare("UPDATE cc_cards SET updated_at = ?, updated_by = NULL WHERE id IN (" . implode(',', $ids) . ')')->execute([$OLD]);
$row = fn(int $id) => $pdo->query("SELECT deleted_at, updated_at, updated_by FROM cc_cards WHERE id = $id")->fetch();
$api = fn(string $a, array $ids, int $uid) => tests_http('POST', "/admin/cc_api.php?a=$a", ['board' => $BID, 'ids' => $ids], $uid, true);
$byId = fn(array $r) => array_column($r['json']['cards'] ?? [], null, 'id');

// ── apagar o desenho (a seta presa a ele também sai): fica a hora e quem apagou ──
$old([$D, $N, $A, $I, $F]);
$r = $api('cards_delete', [$D], 1);
t_eq($r['code'], 200, 'apagar responde 200 (' . substr($r['body'], 0, 160) . ')');
$ids = $r['json']['ids'] ?? [];
sort($ids);
t_eq($ids, [$D, $A], 'apagar o desenho leva a seta presa a ele');
foreach ([$D, $A] as $id) {
    $x = $row($id);
    t_true($x['deleted_at'] !== null && $x['updated_at'] > $OLD && (int)$x['updated_by'] === 1, "cartão $id apagado com a hora e quem apagou (" . json_encode($x) . ')');
}

// ── repor (outra pessoa): fica mais recente do que o marcador de apagado, e vem na resposta ──
$old([$D, $A]);
$r = $api('cards_restore', [$D, $A], 3);
t_eq($r['code'], 200, 'repor responde 200 (' . substr($r['body'], 0, 160) . ')');
foreach ([$D, $A] as $id) {
    $x = $row($id);
    t_true($x['deleted_at'] === null, "cartão $id reposto");
    t_true($x['updated_at'] > $OLD, "cartão $id: updated_at passa a ser a hora de repor (" . $x['updated_at'] . ')');
    t_eq((int)$x['updated_by'], 3, "cartão $id: updated_by é quem repôs");
}
$cards = $byId($r);
t_eq(array_keys($cards) == [$D, $A] || array_keys($cards) == [$A, $D], true, 'a resposta traz os cartões repostos, a seta incluída (' . json_encode(array_keys($cards)) . ')');
$cd = $cards[$D] ?? [];
t_true(($cd['ut'] ?? 0) > $OLDMS, 'o «ut» do desenho é o da hora de repor (' . ($cd['ut'] ?? 'nada') . ')');
t_eq(count($cd['c']['paths'] ?? []), 1, 'o desenho vem com o traço');
t_eq([$cd['type'] ?? '', (float)($cd['x'] ?? -1), (float)($cd['w'] ?? -1)], ['drawing', 0.0, 200.0],'o desenho vem como o Topperboard o mostra');
t_eq($cd['nc'] ?? null, 1, 'com o número de comentários');
t_eq((int)(($cards[$A]['c']['from'] ?? 0)), $D, 'a seta vem ligada ao desenho');
t_true(!empty($r['json']['rev']) && ($r['json']['msg'] ?? '') === 'Reposto.', 'continua a trazer rev e «Reposto.»');

// ── uma referência apagada da biblioteca: o cartão volta na base, mas não vem (o Topperboard também não o mostra) ──
$api('cards_delete', [$I], 1);
$pdo->exec("UPDATE cc_assets SET deleted_at = NOW() WHERE id = $gone");
$r = $api('cards_restore', [$I], 1);
t_eq($r['code'], 200, 'repor o cartão da referência apagada responde 200');
t_true(in_array($I, $r['json']['ids'] ?? [], true) && !isset($byId($r)[$I]), 'o cartão da referência apagada não vem na resposta (' . substr($r['body'], 0, 200) . ')');

// ── um recibo da Faturação: quem não tem o Financeiro recebe-o «sem acesso», sem o nome ──
$api('cards_delete', [$F], 1);
$r = $api('cards_restore', [$F], 3);
$c3 = $byId($r)[$F] ?? null;
t_true($c3 !== null && !empty($c3['noaccess']) && !empty($c3['asset']['noaccess']), 'quem não tem o Financeiro recebe o cartão «sem acesso» (' . substr($r['body'], 0, 200) . ')');
t_true(!str_contains($r['body'], $NAME), 'a resposta não traz o nome do recibo a quem não tem o Financeiro');
$api('cards_delete', [$F], 1);
$r = $api('cards_restore', [$F], 1);
$c1 = $byId($r)[$F] ?? null;
t_true($c1 !== null && empty($c1['noaccess']) && str_contains((string)($c1['asset']['title'] ?? ''), $NAME) && !empty($c1['asset']['restricted']),
       'quem tem o Financeiro recebe o cartão inteiro, marcado «restrito» (o tempo real não o publica)');

// ── outro Topperboard: nada ──
$other = cc_board_create($pdo, ['title' => 'Teste33 · restaurar (outro)', 'kind' => 'free'], 1);
$r = tests_http('POST', '/admin/cc_api.php?a=cards_restore', ['board' => $other, 'ids' => [$D]], 1, true);
t_true($r['code'] !== 200 || !($r['json']['cards'] ?? []), 'cartões de outro Topperboard não são repostos nem devolvidos');

t_done();
