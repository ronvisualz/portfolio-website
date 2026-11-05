<?php
// Juntar da Drive (§33.9): o servidor volta a verificar cada chave que chega (o arrasto pode vir de qualquer página) e diz
// à parte quando se largam pastas e quando um pedido passa das 30 chaves.   php tests/cc_drive_add_msg_test.php
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';
require_once __DIR__ . '/../admin/inc/cc.php';
$tag = 'dam' . bin2hex(random_bytes(3));
$BID = cc_board_create($pdo, ['title' => 'T6 · Drive · ' . $tag, 'kind' => 'free'], 1);
$dir = 'Projetos/T6-' . $tag . '/';
$ok = tests_put($dir . $tag . '-notas.txt', 'notas ' . $tag);
$bill = tests_put('Empresa/Financeiro/2026/11/' . $tag . '-recibo.pdf', '%PDF-1.4 ' . $tag, 'application/pdf');
$add = fn(array $keys, int $uid) => tests_http('POST', '/admin/cc_api.php?a=drive_add', ['board' => $BID, 'keys' => json_encode($keys), 'x' => 0, 'y' => 0], $uid, true);
$assetsFor = fn(string $k) => (int)$pdo->query('SELECT COUNT(*) FROM cc_assets WHERE file_key = ' . $pdo->quote('r2:' . $k))->fetchColumn();
$FOLDERS = 'As pastas não entram: abra a pasta e escolha os ficheiros.';

// quem não tem o Financeiro: só o ficheiro válido entra; a pasta é dita à parte
$untrusted = [$bill, '.topper/thumbs/' . str_repeat('a', 32) . '.jpg', $dir, $dir . 'nao-existe.txt', '../' . $tag . '.txt', ['x'], 42, $ok];
$r = $add($untrusted, 3);
t_eq($r['code'], 200, 'drive_add como uid 3 (' . substr($r['body'], 0, 200) . ')');
t_eq(count($r['json']['cards'] ?? []), 1, 'uid 3: só o ficheiro válido vira cartão');
t_eq($assetsFor($bill), 0, 'uid 3: nada criado para o recibo da Faturação');
t_eq((int)$pdo->query("SELECT COUNT(*) FROM cc_assets WHERE file_key LIKE 'r2:.topper/thumbs/%'")->fetchColumn(), 0, 'uid 3: nada criado para uma chave .topper');
t_eq($r['json']['msg'] ?? '', '1 ficheiro da Drive no Topperboard. ' . $FOLDERS . ' 6 sem acesso ou já não existem.', 'uid 3: a pasta vem à parte, não como «sem acesso»');
t_eq([$r['json']['added'] ?? null, $r['json']['folders'] ?? null, $r['json']['skipped'] ?? null, $r['json']['over'] ?? null], [1, 1, 6, false], 'uid 3: as contagens vêm na resposta (para somar pedidos)');

// quem tem o Financeiro: o recibo também entra
$r1 = $add($untrusted, 1);
t_eq(count($r1['json']['cards'] ?? []), 2, 'uid 1: o recibo e o ficheiro válido viram cartões (' . substr($r1['body'], 0, 200) . ')');
t_eq($r1['json']['msg'] ?? '', '2 ficheiros da Drive no Topperboard. ' . $FOLDERS . ' 5 sem acesso ou já não existem.', 'uid 1: mensagem');

// só uma pasta: só a frase das pastas
$r2 = $add([$dir], 1);
t_eq([count($r2['json']['cards'] ?? []), $r2['json']['msg'] ?? ''], [0, $FOLDERS], 'só uma pasta: nenhum cartão e a frase das pastas');

// mais de 30 num só pedido: entram 30 e diz que os restantes ficaram de fora (o 31.º, válido, não entra)
$many = array_map(fn($i) => $dir . 'falta-' . $i . '.txt', range(1, 30));
$many[] = $ok;
$r3 = $add($many, 1);
t_eq(count($r3['json']['cards'] ?? []), 0, 'mais de 30: o 31.º fica de fora');
t_eq($r3['json']['msg'] ?? '', '0 ficheiros da Drive no Topperboard. 30 sem acesso ou já não existem. Entram até 30 de cada vez: os restantes ficaram de fora.', 'mais de 30: a mensagem diz que os restantes ficaram de fora');
t_eq($r3['json']['over'] ?? null, true, 'mais de 30: over na resposta');
t_done();
