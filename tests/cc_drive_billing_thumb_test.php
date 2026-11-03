<?php
// Ficheiros da Faturação num Topperboard (§33.9): quem não tem o Financeiro não recebe as miniaturas, o original,
// os frames nem o nome de um ficheiro da Faturação que outra pessoa pôs no Topperboard; a página do cliente também
// não (o resto da Drive continua). Juntar da Drive um ficheiro com o mover instantâneo ainda na fila também funciona.
//   php tests/cc_drive_billing_thumb_test.php
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';
require_once __DIR__ . '/../admin/inc/cc.php';
require_once __DIR__ . '/../admin/inc/drive_moves.php';

$NAME = 'rc' . bin2hex(random_bytes(4));   // só aparece no nome do ficheiro (o título do Topperboard não o tem)
$BID = cc_board_create($pdo, ['title' => 'Teste33 · faturação', 'kind' => 'free'], 1);
$png = function (int $w, int $h, array $rgb): array {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, ...$rgb));
    imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    ob_start(); imagepng($im); return [$im, (string)ob_get_clean()];
};
$code = fn(string $url, int $uid) => tests_http('GET', $url, [], $uid)['code'];

// ── uid 1 (Financeiro) põe um recibo da Faturação no Topperboard ──
[, $bytes] = $png(1200, 1600, [40, 120, 200]);
$bill = tests_put("Empresa/Financeiro/2026/11/$NAME-recibo.png", $bytes, 'image/png');
$r = tests_http('POST', '/admin/cc_api.php?a=drive_add', ['board' => $BID, 'keys' => json_encode([$bill]), 'x' => 0, 'y' => 0], 1, true);
$cid = (int)($r['json']['cards'][0]['id'] ?? 0);
$aid = (int)($r['json']['cards'][0]['asset']['id'] ?? 0);
t_true($cid > 0 && $aid > 0, 'quem tem o Financeiro junta o recibo (' . substr($r['body'], 0, 160) . ')');
$thumbs = (string)$pdo->query("SELECT thumbs FROM cc_assets WHERE id = $aid")->fetchColumn();
t_true(trim($thumbs) !== '' && $thumbs !== '[]', 'as miniaturas do recibo foram feitas (sem elas o 404 não provaria nada)');

// ── cc_file.php: quem tem o Financeiro vê tudo; quem não tem recebe 404 em qualquer tamanho ──
foreach ([0, 256, 640, 1600] as $s) {
    t_true(in_array($code("/admin/cc_file.php?a=$aid&s=$s", 1), [200, 302], true), "quem tem o Financeiro abre s=$s");
}
foreach ([0, 256, 640, 1600] as $s) {
    t_eq($code("/admin/cc_file.php?a=$aid&s=$s", 3), 404, "quem não tem o Financeiro não recebe s=$s");
}
t_eq($code("/admin/cc_file.php?a=$aid", 3), 404, 'nem a miniatura por omissão');
t_eq($code("/admin/cc_file.php?a=$aid&s=256&px=1", 3), 404, 'nem os píxeis do conta-gotas');
// um frame-chave do recibo (as miniaturas do frame são do mesmo ficheiro)
$pdo->prepare('INSERT INTO cc_frames (asset_id, t, file_key, thumbs) VALUES (?, 1, ?, ?)')->execute([$aid, '', $thumbs]);
$fid = (int)$pdo->lastInsertId();
t_true(in_array($code("/admin/cc_file.php?f=$fid&s=256", 1), [200, 302], true), 'quem tem o Financeiro vê o frame');
t_eq($code("/admin/cc_file.php?f=$fid&s=256", 3), 404, 'quem não tem o Financeiro não recebe o frame');

// ── o JSON do Topperboard ──
$j3 = tests_http('GET', "/admin/cc_api.php?a=board&id=$BID", [], 3);
t_eq($j3['code'], 200, 'quem não tem o Financeiro abre o Topperboard');
t_true(!str_contains($j3['body'], $NAME), 'o JSON do Topperboard não traz o nome do recibo');
$c3 = array_column($j3['json']['cards'] ?? [], null, 'id')[$cid] ?? null;
t_true($c3 !== null && !empty($c3['noaccess']), 'o cartão vem marcado «sem acesso»');
$a3 = $c3['asset'] ?? [];
t_eq([$a3['thumb'] ?? null, $a3['orig'] ?? null, $a3['title'] ?? null, $a3['url'] ?? null, $a3['file'] ?? null, $a3['tw'] ?? null, $a3['pal'] ?? null],
     ['', '', '', '', '', [], []], 'sem miniatura, original, título, endereço, nome, tamanhos nem paleta');
t_true(!empty($a3['noaccess']), 'a referência também vem marcada «sem acesso»');
$j1 = tests_http('GET', "/admin/cc_api.php?a=board&id=$BID", [], 1);
$c1 = array_column($j1['json']['cards'] ?? [], null, 'id')[$cid] ?? null;
t_true(str_contains($j1['body'], $NAME) && $c1 && empty($c1['noaccess']) && ($c1['asset']['thumb'] ?? '') !== '' && ($c1['asset']['orig'] ?? '') !== '',
       'quem tem o Financeiro continua a ver o cartão inteiro');
// para o tempo real não espalhar o cartão a quem não o pode ver: quem o vê recebe a marca «restrito»
t_true(!empty($c1['asset']['restricted']), 'quem tem o Financeiro recebe o cartão marcado «restrito» (o tempo real não o publica inteiro)');
t_true(empty($c3['asset']['restricted']) || !empty($c3['noaccess']), 'quem não tem o Financeiro só tem a marca «sem acesso»');

// ── os outros caminhos para a mesma referência ──
$d3 = tests_http('GET', "/admin/cc_api.php?a=asset&id=$aid", [], 3);
t_true($d3['code'] === 200 && !str_contains($d3['body'], $NAME) && ($d3['json']['asset']['frames'] ?? null) === [], 'a ficha da referência não traz o nome nem os frames');
t_true(str_contains(tests_http('GET', "/admin/cc_api.php?a=asset&id=$aid", [], 1)['body'], $NAME), 'quem tem o Financeiro vê a ficha');
t_true(!str_contains(tests_http('GET', '/admin/cc_api.php?a=library&q=' . $NAME, [], 3)['body'], $NAME), 'a Biblioteca não traz o nome');
t_true(!str_contains(tests_http('GET', "/admin/cc_api.php?a=jobs&ids=$aid", [], 3)['body'], $NAME), 'o estado das análises não traz o nome');

// ── quem não vê o ficheiro também não o muda: «Guardar» no Detalhe (com os campos vazios) apagava o título e as
//    etiquetas; «Analisar outra vez», a paleta, «Mais como este», «Trocar» e apagar/repor também ficam de fora ──
$sv = tests_http('POST', '/admin/cc_api.php?a=asset_save', ['id' => $aid, 'title' => "Recibo $NAME", 'caption' => 'legenda humana', 'tags' => "fatura, $NAME"], 1, true);
t_eq($sv['code'], 200, 'quem tem o Financeiro dá um título, uma legenda e etiquetas ao recibo');
$pdo->exec("DELETE FROM cc_jobs WHERE asset_id = $aid AND status IN ('queued', 'running')");
$pdo->exec("UPDATE cc_assets SET ai_status = 'error', ai_error = 'marca do teste' WHERE id = $aid");
$snap = fn() => $pdo->query("SELECT title, tags, analysis, palette, ai_status, ai_error, edited_by, deleted_at FROM cc_assets WHERE id = $aid")->fetch(PDO::FETCH_ASSOC);
$jobsN = fn() => (int)$pdo->query("SELECT COUNT(*) FROM cc_jobs WHERE asset_id = $aid")->fetchColumn();
$before = $snap();
$jobs0 = $jobsN();
$hex0 = cc_palette_hex($before['palette'])[0]['hex'] ?? '#000000';
foreach ([
    ['asset_save', ['id' => $aid, 'title' => '', 'caption' => '', 'tags' => '', 'why' => '', 'composition' => '', 'color' => '']],
    ['analyze', ['id' => $aid]],
    ['palette_add', ['asset' => $aid, 'hex' => '#123456', 'name' => 'do teste']],
    ['palette_edit', ['asset' => $aid, 'hex' => $hex0, 'op' => 'hide']],
    ['card_swap', ['board' => $BID, 'card' => $cid]],
    ['asset_delete', ['id' => $aid]],
    ['asset_restore', ['id' => $aid]],
] as [$act, $body]) {
    $r = tests_http('POST', "/admin/cc_api.php?a=$act", $body, 3, true);
    t_eq($r['code'], 403, "quem não tem o Financeiro não pode «{$act}» no recibo (" . substr($r['body'], 0, 120) . ')');
    t_true(!str_contains($r['body'], $NAME), "a resposta de «{$act}» não traz o nome");
}
t_eq($snap(), $before, 'o recibo ficou como estava (título, etiquetas, análise, paleta, estado da IA, quem editou, não apagado)');
t_eq($jobsN(), $jobs0, 'nenhuma análise nova na fila');
t_eq((int)$pdo->query("SELECT asset_id FROM cc_cards WHERE id = $cid")->fetchColumn(), $aid, 'o cartão continua a apontar para o recibo');
$s3 = tests_http('GET', "/admin/cc_api.php?a=similar&id=$aid", [], 3);
t_eq($s3['code'], 403, '«Mais como este» não corre sobre o recibo (' . substr($s3['body'], 0, 120) . ')');
t_eq(tests_http('GET', "/admin/cc_api.php?a=similar&id=$aid", [], 1)['code'], 200, 'quem tem o Financeiro pede «Mais como este»');
t_eq(tests_http('POST', '/admin/cc_api.php?a=palette_add', ['asset' => $aid, 'hex' => '#123456', 'name' => 'do teste'], 1, true)['code'], 200, 'quem tem o Financeiro junta uma cor à paleta');
// um áudio da Faturação: a forma de onda e a duração vindas do browser também não entram
$au = tests_put("Empresa/Financeiro/2026/11/$NAME-chamada.mp3", random_bytes(4096), 'audio/mpeg');
$ra = tests_http('POST', '/admin/cc_api.php?a=drive_add', ['board' => $BID, 'keys' => json_encode([$au]), 'x' => 0, 'y' => 1800], 1, true);
$auId = (int)($ra['json']['cards'][0]['asset']['id'] ?? 0);
t_true($auId > 0 && (string)$pdo->query("SELECT kind FROM cc_assets WHERE id = $auId")->fetchColumn() === 'audio', 'o áudio da Faturação entra (' . substr($ra['body'], 0, 120) . ')');
$durBefore = $pdo->query("SELECT duration FROM cc_assets WHERE id = $auId")->fetchColumn();
$rm3 = tests_http('POST', '/admin/cc_api.php?a=audio_meta', ['id' => $auId, 'dur' => '12.5'], 3, true);
t_eq($rm3['code'], 403, 'quem não tem o Financeiro não manda a forma de onda do áudio (' . substr($rm3['body'], 0, 120) . ')');
t_eq($pdo->query("SELECT duration FROM cc_assets WHERE id = $auId")->fetchColumn(), $durBefore, 'a duração do áudio ficou como estava');

// ── apagado da Drive: o aviso do Topperboard também não diz o nome ──
$t = tests_http('POST', '/admin/drive_api.php', ['do' => 'trash', 'key' => $bill], 1);
t_eq($t['code'], 200, 'o recibo vai para o Lixo (' . substr($t['body'], 0, 120) . ')');
$g3 = tests_http('GET', "/admin/cc_api.php?a=board&id=$BID", [], 3);
t_true(count($g3['json']['gone'] ?? []) === 1 && !str_contains($g3['body'], $NAME), 'o aviso de apagado não traz o nome a quem não tem o Financeiro');
$g1 = tests_http('GET', "/admin/cc_api.php?a=board&id=$BID", [], 1);
t_true(str_contains(json_encode($g1['json']['gone'] ?? []), $NAME), 'quem tem o Financeiro vê o nome no aviso');
$tid = (int)$pdo->query("SELECT drive_trash_id FROM cc_assets WHERE id = $aid")->fetchColumn();
if ($tid) tests_http('POST', '/admin/drive_api.php', ['do' => 'restore', 'id' => $tid], 1);

// ── a página do cliente (proposta): nunca um ficheiro da Faturação; as outras referências continuam ──
[$imOk, $okBytes] = $png(800, 600, [200, 60, 60]);
[$ok] = tests_cc_asset($pdo, $okBytes, ['name' => "ok-$NAME.png", 'mime' => 'image/png']);
$okId = (int)$ok['id'];
if (trim((string)$ok['thumbs']) === '' || (string)$ok['thumbs'] === '[]') {
    $pdo->prepare('UPDATE cc_assets SET thumbs = ? WHERE id = ?')->execute([json_encode(cc_thumbs_make($imOk, 't/' . substr((string)$ok['sha1'], 0, 2) . '/' . $ok['sha1'])), $okId]);
}
$token = bin2hex(random_bytes(24));
$ver = 900000000 + random_int(1, 99999999);
$pdo->prepare("INSERT INTO proposals (deal_id, version_id, token_hash, content_hash, sent_at, expires_on, status) VALUES (0, ?, ?, ?, NOW(), ?, 'sent')")
    ->execute([$ver, hash('sha256', $token), str_repeat('0', 64), date('Y-m-d', time() + 86400 * 30)]);
$pid = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO cc_pages (board_id, lane, version_id, sent_data) VALUES (?, 'A', ?, ?)")->execute([$BID, $ver, json_encode(['refs' => [$aid, $okId]])]);
t_eq($code("/admin/cc_file.php?pt=$token&a=$okId&s=256", 0), 302, 'a página do cliente mostra uma referência do Toppernote');
// os originais enviados para o Toppernote vivem na Drive (cc_place_original: «Biblioteca criativa/…», «Projetos/…/Criativo/…»)
$okKey = tests_put("Biblioteca criativa/Teste33-$NAME/ok.png", $okBytes, 'image/png');
$pdo->prepare('UPDATE cc_assets SET file_key = ? WHERE id = ?')->execute(['r2:' . $okKey, $okId]);
t_eq($code("/admin/cc_file.php?pt=$token&a=$okId&s=256", 0), 302, 'a página do cliente mostra uma referência cujo original está na Drive (fora da Faturação)');
t_eq($code("/admin/cc_file.php?pt=$token&a=$aid&s=256", 0), 404, 'a página do cliente não serve a miniatura de um ficheiro da Faturação');
$pdo->exec("DELETE FROM cc_pages WHERE board_id = $BID");
$pdo->exec("DELETE FROM proposals WHERE id = $pid");
$pdo->exec("DELETE FROM cc_frames WHERE id = $fid");

// ── juntar um ficheiro movido com o mover instantâneo, ainda na fila (sem drive_moves_run) ──
$base = "Empresa/Teste33/mv-$NAME/";
$mvOld = tests_put($base . 'a/mover.txt', 'mover ' . microtime(true));
$mv = tests_http('POST', '/admin/drive_api.php', ['do' => 'move', 'from' => $base . 'a/', 'to' => $base . 'b/', 'on_conflict' => 'error', 'instant' => true, 'batch' => bin2hex(random_bytes(8))], 1);
$queued = array_filter(drive_moves_pending($pdo), fn($m) => (string)$m['src'] === $base . 'a/');
t_true($mv['code'] === 200 && count($queued) === 1, 'o movimento ficou na fila (' . substr($mv['body'], 0, 120) . ')');
$mvNew = $base . 'b/mover.txt';
$rm = tests_http('POST', '/admin/cc_api.php?a=drive_add', ['board' => $BID, 'keys' => json_encode([$mvNew]), 'x' => 0, 'y' => 900], 1, true);
t_eq(count($rm['json']['cards'] ?? []), 1, 'o ficheiro a caminho do sítio novo entra no Topperboard (' . ($rm['json']['msg'] ?? substr($rm['body'], 0, 160)) . ')');
$mAid = (int)($rm['json']['cards'][0]['asset']['id'] ?? 0);
t_true(empty($rm['json']['cards'][0]['asset']['restricted']), 'um ficheiro da Drive fora da Faturação não é «restrito»');
t_eq((string)$pdo->query("SELECT file_key FROM cc_assets WHERE id = $mAid")->fetchColumn(), 'r2:' . $mvNew, 'o cartão aponta para o sítio novo');
drive_moves_run($pdo, 60);
t_eq((string)$pdo->query("SELECT file_key FROM cc_assets WHERE id = $mAid")->fetchColumn(), 'r2:' . $mvNew, 'e continua lá depois de a fila correr');

// ── chaves que não são texto contam como ignoradas (sem avisos do PHP na resposta) ──
$ok2 = tests_put($base . 'c/valida.txt', 'valida ' . microtime(true));
$rk = tests_http('POST', '/admin/cc_api.php?a=drive_add', ['board' => $BID, 'keys' => json_encode([['x'], 42, $ok2]), 'x' => 0, 'y' => 1300], 3, true);
t_true(is_array($rk['json']) && count($rk['json']['cards'] ?? []) === 1 && str_contains((string)($rk['json']['msg'] ?? ''), '2 sem acesso'),
       'só a chave válida entra; as outras duas contam como ignoradas (' . substr($rk['body'], 0, 200) . ')');
t_true(!preg_match('/Warning|Notice|Array to string/i', $rk['body']), 'sem avisos do PHP na resposta');
t_done();
