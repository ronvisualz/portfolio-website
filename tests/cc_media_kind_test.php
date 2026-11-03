<?php
// Áudio que chega como vídeo (o «WhatsApp Audio … .mpeg» de produção: um MP3 com ID3 que o browser dá como
// video/mpeg) vira cartão de áudio — envio do dispositivo, «Da Drive», captura, importação e o que já estava
// gravado (migração 42 + trabalho media_sniff). O servidor decide pelo conteúdo; um vídeo verdadeiro continua vídeo.
//   php tests/cc_media_kind_test.php
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';
require_once __DIR__ . '/../admin/inc/cc.php';
require_once __DIR__ . '/../admin/inc/capture.php';
require_once __DIR__ . '/../admin/inc/cc_import.php';
require_once __DIR__ . '/../admin/inc/migrate_creative.php';

$TAG = 'mk' . bin2hex(random_bytes(4));

// ── bytes feitos à mão (sem ffmpeg), únicos em cada corrida (a bancada reaproveita pela impressão digital) ──
$ss = fn(int $n) => chr(($n >> 21) & 0x7F) . chr(($n >> 14) & 0x7F) . chr(($n >> 7) & 0x7F) . chr($n & 0x7F);   // ID3v2.4: «synchsafe»
$id3 = function (string $text) use ($ss) { $f = 'TSSE' . $ss(strlen($text) + 1) . "\0\0" . "\x03" . $text; return "ID3\x04\0\0" . $ss(strlen($f)) . $f; };
$frame = fn(string $h, int $len) => $h . str_repeat("\0", $len - 4);
$mp3 = fn(string $tag) => $id3('Lavf60.3.100 ' . $tag) . str_repeat($frame("\xFF\xFB\x90\x64", 417), 24);   // como o ficheiro de produção (49 44 33 04)
$box = fn(string $type, string $body) => pack('N', 8 + strlen($body)) . $type . $body;
$hdlr = fn(string $h) => $box('hdlr', "\0\0\0\0" . "\0\0\0\0" . $h . str_repeat("\0", 12) . "\0");
$trak = fn(string $h) => $box('trak', $box('tkhd', str_repeat("\0", 84)) . $box('mdia', $box('mdhd', str_repeat("\0", 24)) . $hdlr($h) . $box('minf', $box('stbl', str_repeat("\0", 64)))));
$mvhd = $box('mvhd', "\0\0\0\0" . pack('NNNN', 0, 0, 1000, 4000) . str_repeat("\0", 80));
$ftyp = fn(string $brand) => $box('ftyp', $brand . "\0\0\2\0" . $brand . 'mp41');
// um MP4 com imagem (H.264+Opus: o Chromium só descodifica o som) e um só com som; com o moov no fim e mais de 2 MB
$vid = fn(string $tag, int $pad = 2000) => $ftyp('isom') . $box('free', $tag) . $box('mdat', str_repeat("\0", $pad)) . $box('moov', $mvhd . $trak('vide') . $trak('soun'));
$aud = fn(string $tag, int $pad = 2000) => $ftyp('M4A ') . $box('free', $tag) . $box('mdat', str_repeat("\0", $pad)) . $box('moov', $mvhd . $trak('soun'));

$BID = cc_board_create($pdo, ['title' => 'Teste33 · áudio como vídeo', 'kind' => 'free'], 1);
$rev = fn(int $b) => (int)$pdo->query("SELECT rev FROM cc_boards WHERE id = $b")->fetchColumn();
$asset = fn(int $id) => $pdo->query("SELECT * FROM cc_assets WHERE id = $id")->fetch();
$card = fn(int $id) => $pdo->query("SELECT type, h FROM cc_cards WHERE id = $id")->fetch();

// ── 1. pelo nome e pelo tipo MIME: as extensões de áudio passam à frente do video/* (o .ogg com video/ogg é vídeo) ──
t_eq(cc_kind_for('video/mpeg', 'faixa.mp3'), 'audio', '.mp3 dado como video/mpeg → áudio');
t_eq(cc_kind_for('video/mp4', 'voz.m4a'), 'audio', '.m4a dado como video/mp4 → áudio');
t_eq(cc_kind_for('video/ogg', 'clip.ogg'), 'video', '.ogg com video/ogg continua vídeo (pode ser Theora)');
t_eq(cc_kind_for('', 'som.ogg'), 'audio', '.ogg sem tipo → áudio');
t_eq(cc_kind_for('video/mp4', 'clip.mp4'), 'video', '.mp4 com video/mp4 → vídeo');
t_eq(cc_kind_for('', 'FAIXA.MP3'), 'audio', '.MP3 sem tipo → áudio');
t_eq(cc_kind_for('audio/mpeg', 'x'), 'audio', 'audio/mpeg sem extensão → áudio');
t_eq(cc_kind_for('image/png', 'x.png'), 'image', 'imagem continua imagem');

if (!function_exists('cc_media_kind')) { t_true(false, 'falta cc_media_kind()'); t_done(); }

// ── 2. pelo conteúdo: cc_media_kind (bytes) e cc_media_kind_key (leituras por intervalo no R2) ──
$k = cc_media_kind('video/mpeg', 'WhatsApp Audio x.mpeg', $mp3($TAG), '', 0);
t_eq([$k['kind'], $k['mime']], ['audio', 'audio/mpeg'], 'MP3 com ID3 chamado .mpeg (video/mpeg) → áudio audio/mpeg');
$k = cc_media_kind('video/mp4', 'clip.mp4', $vid($TAG), '', 0);
t_eq([$k['kind'], $k['mime']], ['video', 'video/mp4'], 'MP4 com pista de vídeo (H.264+Opus) → vídeo, tipo MIME intacto');
$k = cc_media_kind('video/mp4', 'so-som.mp4', $aud($TAG), '', 0);
t_eq([$k['kind'], $k['mime']], ['audio', 'audio/mp4'], 'MP4 só com som → áudio audio/mp4');
$k = cc_media_kind('video/mpeg', 'filme.mpeg', "\0\0\x01\xBA" . str_repeat("\0", 3000), '', 0);
t_eq($k['kind'], 'video', 'MPEG-PS (não se sabe) chamado .mpeg com video/mpeg → continua vídeo');
$k = cc_media_kind('audio/x-m4a', 'voz.m4a', $aud($TAG), '', 0);
t_eq([$k['kind'], $k['mime']], ['audio', 'audio/x-m4a'], 'um áudio já com tipo audio/* guarda o tipo que tinha');
$k = cc_media_kind('application/octet-stream', 'som.flac', random_bytes(300), '', 0);
t_eq([$k['kind'], $k['mime']], ['audio', 'audio/flac'], 'áudio pelo nome sem bytes conhecidos: o tipo MIME pela extensão (.flac → audio/flac, não octet-stream)');
$k = cc_media_kind('video/ogg', 'x.oga', '', '', 0);
t_eq($k['kind'], 'video', 'sem bytes e sem extensão de áudio: fica pelo tipo MIME');
$k = cc_media_kind('application/pdf', 'x.pdf', '%PDF-1.7' . str_repeat("\0", 100), '', 0);
t_eq([$k['kind'], $k['mime']], ['file', 'application/pdf'], 'PDF → ficheiro');
$k = cc_media_kind('application/octet-stream', 'nota.mpga', $mp3($TAG), '', 0);
t_eq([$k['kind'], $k['mime']], ['audio', 'audio/mpeg'], 'ficheiro sem tipo conhecido com bytes de MP3 → áudio');
$big = $vid($TAG, 3000000);   // moov no fim, depois de 3 MB: só se vê lendo o fim
tests_put($kv = ".topper/cc/test/$TAG-v.mov", $big, 'video/quicktime');
$k = cc_media_kind_key('video/quicktime', 'clip.mov', $kv, strlen($big));
t_eq($k['kind'], 'video', 'R2: MOV com o moov no fim → vídeo (lê o fim)');
$bigA = $aud($TAG, 3000000);
tests_put($ka = ".topper/cc/test/$TAG-a.mp4", $bigA, 'video/mp4');
$k = cc_media_kind_key('video/mp4', 'so-som.mp4', $ka, strlen($bigA));
t_eq([$k['kind'], $k['mime']], ['audio', 'audio/mp4'], 'R2: MP4 só com som e o moov no fim → áudio');
tests_put($km = ".topper/cc/test/$TAG-w.mpeg", $mp3($TAG), 'video/mpeg');
$k = cc_media_kind_key('video/mpeg', 'WhatsApp Audio.mpeg', $km, strlen($mp3($TAG)));
t_eq([$k['kind'], $k['mime']], ['audio', 'audio/mpeg'], 'R2: o MP3 .mpeg → áudio');
$k = cc_media_kind_key('video/mpeg', 'perdido.mpeg', ".topper/cc/test/$TAG-nao-existe.mpeg", 5000);
t_eq($k['kind'], 'video', 'R2: objeto que não existe → fica pelo nome (vídeo), sem erro');

// ── 3. o envio do dispositivo (upload_done): o servidor decide, seja o que for que o browser diga ──
$up = function (string $bytes, string $name, string $mime, string $kind) use ($BID) {
    $fp = cc_fingerprint($bytes);
    $key = cc_r2_orig_key($fp, $name);
    r2_put($key, $bytes, $mime);
    return tests_http('POST', '/admin/cc_api.php?a=upload_done', ['board' => $BID, 'name' => $name, 'mime' => $mime, 'kind' => $kind, 'fp' => $fp,
        'size' => strlen($bytes), 'key' => $key, 'x' => 0, 'y' => 0, 'cw' => 280], 1, true);
};
$r = $up($mp3($TAG . 'up'), "WhatsApp Audio $TAG.mpeg", 'video/mpeg', 'video');
$x = $asset((int)($r['json']['asset']['id'] ?? 0));
t_eq([$x['kind'] ?? null, $x['mime'] ?? null], ['audio', 'audio/mpeg'], 'Do dispositivo: o MP3 .mpeg (o browser diz video) → referência áudio audio/mpeg (' . substr($r['body'], 0, 160) . ')');
$c = $card((int)($r['json']['card']['id'] ?? 0));
t_eq([$c['type'] ?? null, (float)($c['h'] ?? -1)], ['audio', 0.0], 'Do dispositivo: cartão de áudio com h=0');
$r = $up($vid($TAG . 'up'), "clip $TAG.mp4", 'video/mp4', 'audio');
$x = $asset((int)($r['json']['asset']['id'] ?? 0));
t_eq($x['kind'] ?? null, 'video', 'Do dispositivo: um MP4 com imagem que o browser não descodifica continua vídeo (mesmo que o browser diga áudio)');
t_eq($card((int)($r['json']['card']['id'] ?? 0))['type'] ?? null, 'video', '… e o cartão é de vídeo');
$r = $up($aud($TAG . 'up'), "so-som $TAG.mp4", 'video/mp4', 'video');
$x = $asset((int)($r['json']['asset']['id'] ?? 0));
t_eq([$x['kind'] ?? null, $x['mime'] ?? null], ['audio', 'audio/mp4'], 'Do dispositivo: MP4 só com som → áudio audio/mp4');

// ── 4. «Da Drive» (drive_add): lê o princípio do ficheiro no R2 ──
$dk = tests_put("Empresa/Teste33/$TAG/WhatsApp Audio $TAG.mpeg", $mp3($TAG . 'drive'), 'video/mpeg');
$r = tests_http('POST', '/admin/cc_api.php?a=drive_add', ['board' => $BID, 'keys' => json_encode([$dk]), 'x' => 600, 'y' => 0], 1, true);
$x = $asset((int)($r['json']['cards'][0]['asset']['id'] ?? 0));
t_eq([$x['kind'] ?? null, $x['mime'] ?? null], ['audio', 'audio/mpeg'], 'Da Drive: o MP3 .mpeg → referência áudio audio/mpeg (' . substr($r['body'], 0, 160) . ')');
$c = $card((int)($r['json']['cards'][0]['id'] ?? 0));
t_eq([$c['type'] ?? null, (float)($c['h'] ?? -1)], ['audio', 0.0], 'Da Drive: cartão de áudio com h=0');
$dv = tests_put("Empresa/Teste33/$TAG/clip $TAG.mp4", $vid($TAG . 'drive'), 'video/mp4');
$r = tests_http('POST', '/admin/cc_api.php?a=drive_add', ['board' => $BID, 'keys' => json_encode([$dv]), 'x' => 900, 'y' => 0], 1, true);
t_eq($asset((int)($r['json']['cards'][0]['asset']['id'] ?? 0))['kind'] ?? null, 'video', 'Da Drive: um MP4 com imagem continua vídeo');

// ── 5. captura (extensão, Partilhar, Atalho) e importação: os bytes chegam ao servidor ──
$u1 = $pdo->query('SELECT * FROM users WHERE id = 1')->fetch();
$cap = cap_save($pdo, $u1, ['kind' => 'file', 'board' => 0, 'via' => 'ios'], ['bytes' => $mp3($TAG . 'cap'), 'name' => "WhatsApp Audio $TAG.mpeg", 'mime' => 'video/mpeg']);
t_eq([$cap['asset']['kind'], $cap['asset']['mime']], ['audio', 'audio/mpeg'], 'Captura: o MP3 .mpeg → áudio audio/mpeg (o cartão usa cc_card_type_for)');
$cell = 0;
cc_import_asset($pdo, $BID, $mp3($TAG . 'imp'), "importado $TAG.mp3", $cell, 1);
$ic = $pdo->query("SELECT c.type, c.h FROM cc_cards c JOIN cc_assets a ON a.id = c.asset_id WHERE c.board_id = $BID AND a.file_name = 'importado $TAG.mp3'")->fetch();
t_eq([$ic['type'] ?? null, (float)($ic['h'] ?? -1)], ['audio', 0.0], 'Importação: um MP3 dá cartão de áudio (antes «ficheiro»)');

// ── 6. cc_card_add: com uma referência, o tipo do cartão é o da referência ──
[$au] = cc_asset_create($pdo, $mp3($TAG . 'card'), ['name' => "faixa $TAG.mp3", 'mime' => 'audio/mpeg', 'by' => 1]);
$cid = cc_card_add($pdo, $BID, ['type' => 'video', 'asset_id' => (int)$au['id'], 'x' => 0, 'y' => 400, 'w' => 280, 'h' => 174], 1);
t_eq([$card($cid)['type'], (float)$card($cid)['h']], ['audio', 0.0], 'cc_card_add: um cartão «vídeo» de um áudio nasce áudio com h=0');
$cid = cc_card_add($pdo, $BID, ['type' => 'note', 'x' => 0, 'y' => 600, 'content' => ['text' => 'nota']], 1);
t_eq($card($cid)['type'], 'note', 'cc_card_add: sem referência o tipo fica');

// ── 7. o que já estava gravado: migração 42 (sem ler o R2) + trabalho media_sniff (lê o R2) ──
t_eq(SCHEMA_VERSION, 42, 'SCHEMA_VERSION 42');
$old = function (string $name, string $mime, string $key, ?string $thumbs = null) use ($pdo, $BID, $TAG) {
    [$a] = cc_asset_create($pdo, null, ['name' => $name, 'mime' => $mime, 'kind' => 'video', 'file_key' => 'r2:' . $key, 'fp' => sha1($TAG . $name), 'by' => 1, 'no_ai' => 1]);
    if ($thumbs !== null) $pdo->prepare('UPDATE cc_assets SET thumbs = ? WHERE id = ?')->execute([$thumbs, (int)$a['id']]);
    $pdo->prepare("INSERT INTO cc_cards (board_id, type, asset_id, x, y, w, h, z, content, created_by, created_at, updated_by, updated_at) VALUES (?, 'video', ?, 0, 900, 280, 174, 1, '{}', 1, NOW(), 1, NOW())")
        ->execute([$BID, (int)$a['id']]);
    return [(int)$a['id'], (int)$pdo->lastInsertId()];
};
$k1 = tests_put("Empresa/Teste33/$TAG/m1.mp3", $mp3($TAG . 'm1'), 'audio/mpeg');
[$m1, $c1] = $old("m1 $TAG.mp3", 'video/mpeg', $k1);                        // extensão de áudio óbvia: muda já
$k2 = tests_put("Empresa/Teste33/$TAG/WhatsApp Audio 2026-10-23 at 19.32.36 $TAG.mpeg", $mp3($TAG . 'm2'), 'video/mpeg');
[$m2, $c2] = $old("WhatsApp Audio 2026-10-23 at 19.32.36 $TAG.mpeg", 'video/mpeg', $k2);   // o caso de produção: só o conteúdo o diz
$k3 = tests_put("Empresa/Teste33/$TAG/v $TAG.mp4", $vid($TAG . 'm3'), 'video/mp4');
[$m3, $c3] = $old("v $TAG.mp4", 'video/mp4', $k3);                           // vídeo verdadeiro sem miniaturas (o browser não o descodificou)
[$m4, $c4] = $old("t $TAG.mp4", 'video/mp4', $k3, '{"256":"x"}');           // com miniaturas: não é candidato
$k5 = tests_put("Empresa/Teste33/$TAG/r $TAG.mpeg", random_bytes(3000), 'video/mpeg');
[$m5, $c5] = $old("r $TAG.mpeg", 'video/mpeg', $k5);                          // conteúdo desconhecido
[$m6, $c6] = $old("g $TAG.mpeg", 'video/mpeg', "Empresa/Teste33/$TAG/nao-existe.mpeg");   // o objeto já não existe
[$m7, $c7] = $old("voz $TAG.aac", 'video/mp4', "Empresa/Teste33/$TAG/voz.aac");                // extensão de áudio óbvia (AAC)
$rev0 = $rev($BID);
$jobs = fn(int $id) => (int)$pdo->query("SELECT COUNT(*) FROM cc_jobs WHERE kind = 'media_sniff' AND asset_id = $id")->fetchColumn();
if (!function_exists('migrate_cc_42')) { t_true(false, 'falta migrate_cc_42()'); t_done(); }
migrate_cc_42($pdo);
migrate_cc_42($pdo);   // idempotente
t_eq([$asset($m1)['kind'], $asset($m1)['mime']], ['audio', 'audio/mpeg'], 'migração: .mp3 gravado como vídeo → áudio audio/mpeg já');
t_eq([$card($c1)['type'], (float)$card($c1)['h']], ['audio', 0.0], 'migração: o cartão dele → áudio h=0');
t_eq([$asset($m7)['kind'], $asset($m7)['mime']], ['audio', 'audio/aac'], 'migração: .aac gravado como video/mp4 → áudio audio/aac (o tipo pela extensão)');
t_eq($asset($m2)['kind'], 'video', 'migração: o .mpeg (só o conteúdo o diz) não muda dentro da migração (nada de ler o R2)');
t_eq($jobs($m2), 1, 'migração: um trabalho media_sniff para o .mpeg (uma só vez, mesmo a correr duas)');
t_eq($jobs($m3), 1, 'migração: e para o vídeo sem miniaturas');
t_eq($jobs($m4), 0, 'migração: nada para o vídeo com miniaturas');
t_eq($jobs($m1), 0, 'migração: nada para o que já mudou');
t_true($rev($BID) > $rev0, 'migração: o Topperboard do cartão que mudou foi tocado (os abertos recarregam)');

// o trabalho (cc_jobs_run pela ação «work» ou pelo cron)
$run = function (int $id) use ($pdo) {
    $j = $pdo->query("SELECT * FROM cc_jobs WHERE kind = 'media_sniff' AND asset_id = $id ORDER BY id DESC LIMIT 1")->fetch();
    if (!$j) { $jid = cc_job_add($pdo, 'media_sniff', $id, null, null, 1); $j = $pdo->query("SELECT * FROM cc_jobs WHERE id = $jid")->fetch(); }
    cc_job_exec($pdo, $j);
};
$rev1 = $rev($BID);
$run($m2);
$x = $asset($m2);
t_eq([$x['kind'], $x['mime']], ['audio', 'audio/mpeg'], 'trabalho: o MP3 .mpeg → áudio audio/mpeg');
t_eq([$card($c2)['type'], (float)$card($c2)['h']], ['audio', 0.0], 'trabalho: o cartão → áudio h=0');
t_true($rev($BID) > $rev1, 'trabalho: o Topperboard foi tocado');
$run($m3);
t_eq([$asset($m3)['kind'], $card($c3)['type']], ['video', 'video'], 'trabalho: o vídeo verdadeiro (H.264+Opus) continua vídeo');
$run($m5);
t_eq([$asset($m5)['kind'], $asset($m5)['mime'], $card($c5)['type']], ['video', 'video/mpeg', 'video'], 'trabalho: conteúdo desconhecido não muda nada');
$e = null;
try { $run($m6); } catch (Throwable $ex) { $e = $ex->getMessage(); }
t_eq([$e, $asset($m6)['kind'], (string)$asset($m6)['ai_status']], [null, 'video', ''], 'trabalho: objeto que já não existe → nada muda, sem erro nem «IA ✕»');

t_done();
