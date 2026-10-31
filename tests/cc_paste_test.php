<?php
// Colar no Topperboard (§33.6): cards_paste cria as cópias (conteúdo e referência, nunca o ficheiro), refaz as setas e as
// colunas com os ids novos; referências que não existem ficam de fora; sem editar → 403.   php tests/cc_paste_test.php
require __DIR__ . '/boot.php';
require_once __DIR__ . '/../admin/inc/cc.php';
$BID = (int)trim((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/ui/seed_board.php')));
$dst = cc_board_create($pdo, ['title' => 'Teste33 · destino', 'kind' => 'free'], 1);
$aid = (int)$pdo->query("SELECT asset_id FROM cc_cards WHERE board_id = $BID AND type = 'image'")->fetchColumn();
$sub = (int)$pdo->query("SELECT ref_board_id FROM cc_cards WHERE board_id = $BID AND type = 'board'")->fetchColumn();
$assets0 = (int)$pdo->query('SELECT COUNT(*) FROM cc_assets')->fetchColumn();
$items = [
    ['ref' => 1, 'type' => 'column', 'x' => 100, 'y' => 100, 'w' => 300, 'h' => 0, 'c' => ['title' => 'Coluna'], 'col' => null, 'ord' => 0],
    ['ref' => 2, 'type' => 'note', 'x' => 0, 'y' => 0, 'w' => 240, 'h' => 0, 'c' => ['text' => 'Dentro'], 'col' => 1, 'ord' => 0],
    ['ref' => 3, 'type' => 'image', 'x' => 500, 'y' => 100, 'w' => 280, 'h' => 0, 'c' => ['caption' => 'Img'], 'asset' => $aid, 'col' => null, 'ord' => 0],
    ['ref' => 4, 'type' => 'board', 'x' => 800, 'y' => 100, 'w' => 240, 'h' => 0, 'c' => ['title' => 'Luz'], 'sub' => $sub, 'col' => null, 'ord' => 0],
    ['ref' => 5, 'type' => 'image', 'x' => 900, 'y' => 100, 'w' => 280, 'h' => 0, 'c' => [], 'asset' => 99999999, 'col' => null, 'ord' => 0],
    ['ref' => 6, 'type' => 'xpto', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 0, 'c' => [], 'col' => null, 'ord' => 0],
];
$arrows = [['from' => 2, 'to' => 3, 'c' => ['label' => 'liga']], ['from' => 3, 'to' => 77, 'c' => []]];
$r = tests_http('POST', '/admin/cc_api.php?a=cards_paste', ['board' => $dst, 'items' => json_encode($items), 'arrows' => json_encode($arrows)], 1, true);
t_eq($r['code'], 200, 'colar responde 200 (' . substr($r['body'], 0, 200) . ')');
$rows = $pdo->query("SELECT * FROM cc_cards WHERE board_id = $dst AND deleted_at IS NULL ORDER BY id")->fetchAll();
$by = []; foreach ($rows as $x) $by[$x['type']][] = $x;
t_eq(count($by['column'] ?? []) . count($by['note'] ?? []) . count($by['image'] ?? []) . count($by['board'] ?? []), '1111', 'coluna, nota, imagem e sub-Topperboard criados (a referência inexistente e o tipo inválido ficam de fora)');
t_eq((int)$by['note'][0]['column_id'], (int)$by['column'][0]['id'], 'a nota fica dentro da coluna nova');
t_eq((int)$by['image'][0]['asset_id'], $aid, 'a imagem aponta para a mesma referência');
t_eq((int)$pdo->query('SELECT COUNT(*) FROM cc_assets')->fetchColumn(), $assets0, 'nenhum ficheiro copiado');
t_eq((int)$by['board'][0]['ref_board_id'], $sub, 'o sub-Topperboard copiado aponta para o mesmo (não o duplica)');
$ar = $by['arrow'] ?? [];
$ac = $ar ? json_decode($ar[0]['content'], true) : [];
t_eq([count($ar), (int)($ac['from'] ?? 0), (int)($ac['to'] ?? 0), $ac['label'] ?? ''], [1, (int)$by['note'][0]['id'], (int)$by['image'][0]['id'], 'liga'], 'a seta é refeita com os ids novos; a que aponta para fora não vem');
t_eq(count($r['json']['cards'] ?? []), 5, 'devolve os cartões criados');
// sem editar → 403
$pdo->prepare("DELETE FROM permissions WHERE role = '' AND user_id = 3 AND tab = 'criativo'")->execute();
$pdo->prepare("INSERT INTO permissions (role, user_id, tab, level, scope) VALUES ('', 3, 'criativo', 1, NULL)")->execute();
try {
    $r = tests_http('POST', '/admin/cc_api.php?a=cards_paste', ['board' => $dst, 'items' => json_encode($items)], 3, true);
    t_eq($r['code'], 403, 'quem só vê não cola (403)');
} finally {
    $pdo->prepare("DELETE FROM permissions WHERE role = '' AND user_id = 3 AND tab = 'criativo'")->execute();
}
t_done();
