<?php
// Balão de comentários (§33.4): comments&last=3 devolve só os 3 mais recentes (por ordem) e o total.   php tests/cc_comments_last_test.php
require __DIR__ . '/boot.php';
require_once __DIR__ . '/../admin/inc/cc.php';
$BID = (int)trim((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/ui/seed_board.php')));
$card = (int)$pdo->query("SELECT id FROM cc_cards WHERE board_id = $BID AND type = 'note'")->fetchColumn();
$b = cc_board_get($pdo, $BID);
$u = $pdo->query('SELECT * FROM users WHERE id = 1')->fetch();
for ($i = 1; $i <= 5; $i++) cc_comment_add($pdo, $b, ['id' => $card], "Comentário $i", $u);
$j = tests_http('GET', "/admin/cc_api.php?a=comments&board=$BID&card=$card&last=3", [], 1)['json'];
t_eq(array_column($j['comments'] ?? [], 'body'), ['Comentário 3', 'Comentário 4', 'Comentário 5'], 'os 3 mais recentes, por ordem');
t_eq($j['total'] ?? null, 5, 'o total vem junto');
$j = tests_http('GET', "/admin/cc_api.php?a=comments&board=$BID&card=$card", [], 1)['json'];
t_eq(count($j['comments'] ?? []), 5, 'sem last vêm todos (como antes)');
t_done();
