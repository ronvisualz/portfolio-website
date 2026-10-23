<?php
/**
 * topper. admin — Centro Criativo, fase 2: Review e Portal do cliente (§24)
 *
 * Peças de review (por negócio e frente, numa etapa de aprovação) com versões v001, v002… que
 * nunca se apagam. O original fica no Drive, em «<pasta do negócio>/Review/<peça>/»; o que o
 * browser mostra fica no sistema (.topper/cc/rv/<versão>/): proxy H.264, poster, tira de
 * miniaturas, páginas de um PDF e o proxy com marca de água. O vídeo é preparado pelo cron
 * (ffmpeg/ffprobe pela linha de comandos): fps, frames, duração e timecode de início lidos no
 * servidor. Sem ffmpeg, a versão fica com o poster e a tira feitos no browser e o próprio
 * original como proxy (se o browser o tocar).
 *
 * Comentários presos a um frame (ou intervalo) ou a um pino (imagem, página do PDF), com desenho
 * vetorial, em fio, com @menções e anexos; «Internos» (só a equipa) ou «Para o cliente» — os
 * internos nunca saem do servidor para o portal. Enviar ao cliente abre uma ronda; o envio
 * consolidado do cliente fecha-a e conta nas rondas da etapa (§19.6 D). Aprovar conclui a
 * tarefa de aprovação do Pipeline (mapa em Definições) e guarda a versão na Biblioteca.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/cc.php';
require_once __DIR__ . '/files.php';

/** As etapas de aprovação de uma peça: chave => rótulo. */
const CC_RV_STAGES = [
    'styleframes' => 'Styleframes',
    'animatic'    => 'Animatic',
    'offline'     => 'Offline',
    'lock'        => 'Picture lock',
    'finais'      => 'Finais',
    'lote'        => 'Lote',
    'outro'       => 'Outra',
];
/** Os estados de uma peça: chave => [rótulo, rótulo em inglês, cor]. */
const CC_RV_STATUS = [
    'prep'     => ['Em preparação', 'In preparation', 'neutral'],
    'sent'     => ['Enviada ao cliente', 'Sent for review', 'blue'],
    'comments' => ['Com comentários', 'Comments received', 'sky'],
    'changes'  => ['Alterações pedidas', 'Changes requested', 'orange'],
    'approved' => ['Aprovada', 'Approved', 'green'],
];
/** O mapa por defeito etapa de review → padrões do título da tarefa de aprovação no Pipeline. */
const CC_RV_TASK_MAP = [
    'styleframes' => 'aprovação do look | aprovação do estilo | styleframes',
    'animatic'    => 'aprovação do animatic',
    'offline'     => 'aprovação do tratamento | aprovação do offline | offline',
    'lock'        => 'aprovação do lock',
    'finais'      => 'aprovação final',
    'lote'        => 'aprovação final do lote | aprovação dos guiões | aprovação ·',
];
const CC_RV_MAX_BYTES = 5368709120;   // um PUT do S3 (5 GB); acima disso, pelo Drive
const CC_RV_SYS = '.topper/cc/rv/';

function cc_rv_ready(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (int)$pdo->query("SELECT svalue FROM settings WHERE skey = 'schema_version'")->fetchColumn() >= 27;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

function cc_rv_now(): string {
    return date('Y-m-d H:i:s');
}

// ── peças e versões ─────────────────────────────────────────

function cc_rv_item(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM cc_rv_items WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** As peças de um negócio (as partilhadas, para o portal). */
function cc_rv_items_for_deal(PDO $pdo, int $dealId, bool $sharedOnly = false): array {
    $st = $pdo->prepare('SELECT i.*, v.num AS cur_num, v.proc_status AS cur_proc, v.poster_key AS cur_poster FROM cc_rv_items i
                          LEFT JOIN cc_rv_versions v ON v.id = i.current_version_id
                          WHERE i.deal_id = ?' . ($sharedOnly ? ' AND i.shared = 1' : '') . ' ORDER BY i.position, i.id');
    $st->execute([$dealId]);
    return $st->fetchAll();
}

function cc_rv_version(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM cc_rv_versions WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** As versões de uma peça (a mais recente primeiro); para o portal, só as já enviadas. */
function cc_rv_versions(PDO $pdo, int $itemId, bool $sentOnly = false): array {
    $st = $pdo->prepare('SELECT * FROM cc_rv_versions WHERE item_id = ?' . ($sentOnly ? ' AND sent_at IS NOT NULL' : '') . ' ORDER BY num DESC');
    $st->execute([$itemId]);
    return $st->fetchAll();
}

/** «v001». */
function cc_rv_vlabel(int $num): string {
    return 'v' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
}

/** A etapa do funil onde contam as rondas: a da frente (crosselling) ou a do negócio. */
function cc_rv_stage_for(PDO $pdo, array $deal, ?int $lineId): ?int {
    if ($lineId && db_has_table($pdo, 'deal_lines')) {
        $st = $pdo->prepare('SELECT stage_id FROM deal_lines WHERE id = ? AND deal_id = ?');
        $st->execute([$lineId, (int)$deal['id']]);
        if ($s = (int)$st->fetchColumn()) return $s;
    }
    return $deal['stage_id'] ? (int)$deal['stage_id'] : null;
}

/** Uma peça nova. */
function cc_rv_item_create(PDO $pdo, array $deal, string $title, string $stageKey, string $kind, ?int $lineId, int $by): int {
    $title = mb_substr(trim($title), 0, 200);
    if ($title === '') throw new RuntimeException('Dê um nome à peça.');
    if (!isset(CC_RV_STAGES[$stageKey])) $stageKey = 'outro';
    if (!in_array($kind, ['video', 'image', 'pdf'], true)) $kind = 'video';
    $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM cc_rv_items WHERE deal_id = ' . (int)$deal['id'])->fetchColumn();
    $now = cc_rv_now();
    $pdo->prepare('INSERT INTO cc_rv_items (deal_id, line_id, title, stage_key, stage_id, kind, watermark, download, position, created_by, created_at, updated_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$deal['id'], $lineId ?: null, $title, $stageKey, cc_rv_stage_for($pdo, $deal, $lineId), $kind,
                   (int)setting($pdo, 'cc_portal_wm', '1') ? 1 : 0, (int)setting($pdo, 'cc_portal_dl', '0') ? 1 : 0, $pos, $by, $now, $now]);
    $id = (int)$pdo->lastInsertId();
    deal_log($pdo, (int)$deal['id'], 'review', ['note' => 'Peça de review criada: ' . $title . ' (' . CC_RV_STAGES[$stageKey] . ')', 'user_id' => $by]);
    return $id;
}

/** Marca a peça como mudada (os separadores abertos e o portal atualizam-se). */
function cc_rv_touch(PDO $pdo, int $itemId): void {
    $pdo->prepare('UPDATE cc_rv_items SET rev = rev + 1, updated_at = ? WHERE id = ?')->execute([cc_rv_now(), $itemId]);
}

/** A pasta do Drive de uma peça: «<pasta do negócio>/Review/<peça>/». */
function cc_rv_drive_folder(PDO $pdo, array $item): ?string {
    $d = files_deal_row($pdo, (int)$item['deal_id']);
    $f = $d ? files_folder($pdo, $d) : null;
    return $f === null ? null : $f . 'Review/' . (r2_clean_name((string)$item['title']) ?: 'Peça') . '/';
}

/** O tipo de uma peça pelo ficheiro. */
function cc_rv_kind_of(string $mime, string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($mime === 'application/pdf' || $ext === 'pdf') return 'pdf';
    if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'tif', 'tiff'], true)) return 'image';
    return 'video';
}

/** Uma versão nova a partir de um original que já chegou ao Drive. Devolve o id. */
function cc_rv_version_create(PDO $pdo, array $item, string $key, array $meta, int $by): int {
    $h = r2_head($key);
    if ($h === null) throw new RuntimeException('O ficheiro não chegou ao Drive. Tente outra vez.');
    $num = (int)$pdo->query('SELECT COALESCE(MAX(num), 0) + 1 FROM cc_rv_versions WHERE item_id = ' . (int)$item['id'])->fetchColumn();
    $name = drive_base($key);
    $mime = (string)($meta['mime'] ?? '') ?: r2_mime($name);
    $kind = cc_rv_kind_of($mime, $name);
    $now = cc_rv_now();
    $pdo->prepare('INSERT INTO cc_rv_versions (item_id, num, file_key, file_name, mime, bytes, proc_status, fps_num, fps_den, duration, width, height, note, created_by, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$item['id'], $num, $key, mb_substr((string)($meta['name'] ?? $name), 0, 255), $mime, (int)$h['size'], $kind === 'video' ? 'queued' : 'running',
                   max(1, (int)($meta['fps_num'] ?? 25)), max(1, (int)($meta['fps_den'] ?? 1)), round((float)($meta['dur'] ?? 0), 3),
                   (int)($meta['w'] ?? 0), (int)($meta['h'] ?? 0), mb_substr(trim((string)($meta['note'] ?? '')), 0, 255), $by, $now]);
    $vid = (int)$pdo->lastInsertId();
    // o original fica no registo de ficheiros (secção Review da pasta do negócio)
    files_register($pdo, $key, ['etag' => $h['etag'], 'size' => $h['size'], 'mime' => $mime, 'deal_id' => (int)$item['deal_id'], 'section' => 'review',
                                'origin' => 'review', 'label' => $item['title'] . ' · ' . cc_rv_vlabel($num), 'by' => $by]);
    // uma versão nova volta a «Em preparação» (a aprovação anterior fica registada)
    $pdo->prepare("UPDATE cc_rv_items SET current_version_id = ?, status = 'prep', kind = ?, updated_at = ? WHERE id = ?")
        ->execute([$vid, $kind, $now, (int)$item['id']]);
    cc_rv_touch($pdo, (int)$item['id']);
    deal_log($pdo, (int)$item['deal_id'], 'review', ['note' => $item['title'] . ' · ' . cc_rv_vlabel($num) . ' carregada', 'user_id' => $by]);
    return $vid;
}

// ── o que o browser mostra (sistema) ────────────────────────

/** Guarda um derivado da versão no sistema: «.topper/cc/rv/<versão>/<nome>». */
function cc_rv_put(int $vid, string $name, string $bytes, string $mime): string {
    $key = CC_RV_SYS . $vid . '/' . $name;
    r2_put($key, $bytes, $mime);
    return $key;
}

/** Um URL temporário (curto) para um derivado ou para o original. */
function cc_rv_url(string $key, bool $inline = true, string $type = '', int $ttl = 600, string $name = ''): string {
    if ($name !== '') return r2_presign('GET', $key, $ttl, ['response-content-disposition' => r2_disposition($name, $inline)] + ($type !== '' ? ['response-content-type' => $type] : []));
    return r2_get_url($key, $inline, $type, $ttl);
}

/** Uma imagem: o proxy (2048 px), o poster (1280 px) e as dimensões, já no pedido. */
function cc_rv_prepare_image(PDO $pdo, array $v): void {
    $bytes = r2_get((string)$v['file_key'], null, 157286400);
    $img = cc_gd_open($bytes, 120000000);
    if (!$img) throw new RuntimeException('Não foi possível ler esta imagem (formato não suportado).');
    $w = imagesx($img); $h = imagesy($img);
    $proxy = cc_rv_put((int)$v['id'], 'proxy.jpg', cc_gd_jpeg(cc_gd_fit($img, min(2048, $w)), 86), 'image/jpeg');
    $poster = cc_rv_put((int)$v['id'], 'poster.jpg', cc_gd_jpeg(cc_gd_fit($img, min(1280, $w)), 82), 'image/jpeg');
    $pdo->prepare("UPDATE cc_rv_versions SET proxy_key = ?, poster_key = ?, width = ?, height = ?, proc_status = 'ready', proc_error = '' WHERE id = ?")
        ->execute([$proxy, $poster, $w, $h, (int)$v['id']]);
}

/** Um PDF: as páginas chegam do browser já em imagem (pdf.js); aqui guardam-se e a primeira faz de poster. */
function cc_rv_store_pages(PDO $pdo, array $v, array $files): int {
    $pages = []; $n = 0;
    foreach ($files as $i => $tmp) {
        if ($n >= 300 || !is_uploaded_file($tmp)) continue;
        $img = cc_gd_open((string)file_get_contents($tmp), 60000000);
        if (!$img) continue;
        $n++;
        $key = cc_rv_put((int)$v['id'], 'p' . $n . '.jpg', cc_gd_jpeg(cc_gd_fit($img, min(2000, imagesx($img))), 85), 'image/jpeg');
        $pages[] = ['k' => $key, 'w' => imagesx($img), 'h' => imagesy($img)];
        if ($n === 1) {
            $poster = cc_rv_put((int)$v['id'], 'poster.jpg', cc_gd_jpeg(cc_gd_fit($img, min(1280, imagesx($img))), 82), 'image/jpeg');
            $pdo->prepare('UPDATE cc_rv_versions SET poster_key = ?, width = ?, height = ? WHERE id = ?')->execute([$poster, imagesx($img), imagesy($img), (int)$v['id']]);
        }
    }
    if (!$pages) throw new RuntimeException('Não foi possível ler as páginas deste PDF.');
    $pdo->prepare("UPDATE cc_rv_versions SET pages = ?, proc_status = 'ready', proc_error = '' WHERE id = ?")->execute([json_encode($pages), (int)$v['id']]);
    return $n;
}

/**
 * Vídeo, enquanto o servidor não o prepara: o poster e a tira feitos no browser (canvas), a duração
 * e as dimensões. Sem ffmpeg no servidor, a versão fica pronta com o original como proxy.
 */
function cc_rv_store_preview(PDO $pdo, array $v, ?string $posterTmp, ?string $stripTmp, int $stripN): void {
    $set = [];
    if ($posterTmp && is_uploaded_file($posterTmp) && ($img = cc_gd_open((string)file_get_contents($posterTmp)))) {
        $set['poster_key'] = cc_rv_put((int)$v['id'], 'poster.jpg', cc_gd_jpeg(cc_gd_fit($img, min(1280, imagesx($img))), 82), 'image/jpeg');
    }
    if ($stripTmp && is_uploaded_file($stripTmp) && $stripN > 0 && ($img = cc_gd_open((string)file_get_contents($stripTmp)))) {
        $set['strip_key'] = cc_rv_put((int)$v['id'], 'strip.jpg', cc_gd_jpeg($img, 78), 'image/jpeg');
        $set['strip_n'] = min(120, $stripN);
        $set['strip_w'] = (int)round(imagesx($img) / max(1, $stripN));
        $set['strip_h'] = imagesy($img);
    }
    if (!cc_rv_ffmpeg_known()) {
        // sem ffmpeg: o original faz de proxy (o browser toca MP4/MOV em H.264)
        $set['proxy_key'] = (string)$v['file_key'];
        $set['proc_status'] = 'ready';
        $set['proc_error'] = 'Preparado no browser (o servidor ainda não tem ffmpeg): fps e timecode por confirmar.';
    }
    if (!$set) return;
    $pdo->prepare('UPDATE cc_rv_versions SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($set))) . ' WHERE id = ?')
        ->execute([...array_values($set), (int)$v['id']]);
}

// ── ffmpeg (pela linha de comandos, no cron) ────────────────

/** O caminho de um binário (ffmpeg, ffprobe): ~/bin/ do alojamento, ou o do sistema. */
function cc_rv_bin(string $name): ?string {
    $cands = [];
    if (preg_match('~^(/home/[^/]+)/~', __DIR__, $m)) $cands[] = $m[1] . '/bin/' . $name;
    $home = getenv('HOME');
    if ($home) $cands[] = rtrim($home, '/') . '/bin/' . $name;
    array_push($cands, '/usr/local/bin/' . $name, '/usr/bin/' . $name);
    foreach (array_unique($cands) as $c) if (@is_file($c) && @is_executable($c)) return $c;
    return null;
}

/** O cron diz se há ffmpeg (as páginas não podem correr programas; o cron pode). */
function cc_rv_ffmpeg_known(): bool {
    global $pdo;
    return (string)setting($pdo, 'cc_ffmpeg', '') !== '';
}

/** Corre um comando (sem shell) e devolve [código, stdout, stderr]. */
function cc_rv_run(array $cmd, int $timeout = 3600): array {
    if (!function_exists('proc_open')) throw new RuntimeException('O servidor não deixa correr o ffmpeg (proc_open).');
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('Não foi possível arrancar ' . basename($cmd[0]) . '.');
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $out = ''; $err = ''; $t0 = time();
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        $s = proc_get_status($p);
        if (!$s['running']) break;
        if (time() - $t0 > $timeout) { proc_terminate($p, 9); throw new RuntimeException(basename($cmd[0]) . ' demorou demasiado.'); }
        usleep(100000);
    }
    $out .= (string)stream_get_contents($pipes[1]);
    $err .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    return [$s['exitcode'] >= 0 ? (int)$s['exitcode'] : $code, $out, mb_substr($err, -2000)];
}

/** «30000/1001» → [30000, 1001]. */
function cc_rv_rate(string $r): array {
    if (preg_match('~^(\d+)/(\d+)$~', $r, $m) && (int)$m[2] > 0 && (int)$m[1] > 0) return [(int)$m[1], (int)$m[2]];
    return [25, 1];
}

/**
 * Lê o vídeo com o ffprobe: fps, frames, duração, dimensões, timecode de início (e se é drop frame).
 */
function cc_rv_probe(string $url): array {
    $probe = cc_rv_bin('ffprobe');
    if (!$probe) throw new RuntimeException('Falta o ffprobe no servidor.');
    [$code, $out, $err] = cc_rv_run([$probe, '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $url], 300);
    $j = json_decode($out, true);
    if ($code !== 0 || !is_array($j)) throw new RuntimeException('O ffprobe não leu o ficheiro: ' . trim(mb_substr($err, 0, 200)));
    $v = null; $tc = '';
    foreach ((array)($j['streams'] ?? []) as $s) {
        if (($s['codec_type'] ?? '') === 'video' && $v === null && empty($s['disposition']['attached_pic'])) $v = $s;
        if ($tc === '' && !empty($s['tags']['timecode'])) $tc = (string)$s['tags']['timecode'];
    }
    if ($tc === '' && !empty($j['format']['tags']['timecode'])) $tc = (string)$j['format']['tags']['timecode'];
    if (!$v) throw new RuntimeException('Este ficheiro não tem vídeo.');
    [$fn, $fd] = cc_rv_rate((string)($v['r_frame_rate'] ?? $v['avg_frame_rate'] ?? '25/1'));
    if ($fn / $fd > 120) [$fn, $fd] = cc_rv_rate((string)($v['avg_frame_rate'] ?? '25/1'));
    $dur = (float)($v['duration'] ?? $j['format']['duration'] ?? 0);
    $frames = (int)($v['nb_frames'] ?? 0) ?: (int)round($dur * $fn / $fd);
    $hasAudio = false;
    foreach ((array)($j['streams'] ?? []) as $s) if (($s['codec_type'] ?? '') === 'audio') $hasAudio = true;
    return ['fps_num' => $fn, 'fps_den' => $fd, 'duration' => round($dur, 3), 'frames' => $frames, 'width' => (int)($v['width'] ?? 0), 'height' => (int)($v['height'] ?? 0),
            'tc_start' => preg_match('/^\d{2}:\d{2}:\d{2}[:;.]\d{2}$/', $tc) ? str_replace('.', ':', $tc) : '00:00:00:00', 'audio' => $hasAudio];
}

/**
 * Prepara um vídeo (cron): lê-o, faz o proxy H.264 (CFR, GOP curto: procura ao frame), o poster
 * e a tira de miniaturas, e guarda tudo no sistema.
 */
function cc_rv_process_video(PDO $pdo, array $v): void {
    $ff = cc_rv_bin('ffmpeg');
    if (!$ff) throw new RuntimeException('Falta o ffmpeg no servidor.');
    $url = r2_presign('GET', (string)$v['file_key'], 6 * 3600);
    $m = cc_rv_probe($url);
    $tmp = sys_get_temp_dir() . '/ccrv_' . (int)$v['id'] . '_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    try {
        $fps = $m['fps_num'] . '/' . $m['fps_den'];
        $gop = (string)max(1, (int)round($m['fps_num'] / $m['fps_den'] / 2));
        $cmd = [$ff, '-hide_banner', '-loglevel', 'error', '-y', '-i', $url, '-map', '0:v:0', '-map', '0:a:0?',
                '-vf', "scale='min(1920,iw)':-2:flags=lanczos,format=yuv420p", '-r', $fps, '-fps_mode', 'cfr',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21', '-profile:v', 'high', '-g', $gop, '-keyint_min', $gop, '-sc_threshold', '0',
                '-c:a', 'aac', '-b:a', '160k', '-ac', '2', '-movflags', '+faststart', $tmp . '/proxy.mp4'];
        [$code, , $err] = cc_rv_run($cmd);
        if ($code !== 0 || !is_file($tmp . '/proxy.mp4')) throw new RuntimeException('O ffmpeg não fez o proxy: ' . trim(mb_substr($err, -300)));
        $at = (string)max(0, min($m['duration'] * 0.2, $m['duration'] - 0.1));
        cc_rv_run([$ff, '-hide_banner', '-loglevel', 'error', '-y', '-ss', $at, '-i', $tmp . '/proxy.mp4', '-frames:v', '1', '-vf', "scale='min(1280,iw)':-2", '-q:v', '3', $tmp . '/poster.jpg'], 300);
        $n = (int)max(8, min(60, round($m['duration'])));
        $tw = 160;
        $th = (int)max(2, round($tw * max(1, $m['height']) / max(1, $m['width']) / 2) * 2);
        cc_rv_run([$ff, '-hide_banner', '-loglevel', 'error', '-y', '-i', $tmp . '/proxy.mp4',
                   '-vf', 'fps=' . $n . '/' . max(0.1, $m['duration']) . ",scale={$tw}:{$th},tile={$n}x1", '-frames:v', '1', '-q:v', '4', $tmp . '/strip.jpg'], 900);
        $set = ['proxy_key' => cc_rv_put((int)$v['id'], 'proxy.mp4', (string)file_get_contents($tmp . '/proxy.mp4'), 'video/mp4')];
        if (is_file($tmp . '/poster.jpg')) $set['poster_key'] = cc_rv_put((int)$v['id'], 'poster.jpg', (string)file_get_contents($tmp . '/poster.jpg'), 'image/jpeg');
        if (is_file($tmp . '/strip.jpg')) $set += ['strip_key' => cc_rv_put((int)$v['id'], 'strip.jpg', (string)file_get_contents($tmp . '/strip.jpg'), 'image/jpeg'), 'strip_n' => $n, 'strip_w' => $tw, 'strip_h' => $th];
        $set += ['fps_num' => $m['fps_num'], 'fps_den' => $m['fps_den'], 'frames' => $m['frames'], 'duration' => $m['duration'], 'width' => $m['width'], 'height' => $m['height'],
                 'tc_start' => $m['tc_start'], 'proc_status' => 'ready', 'proc_error' => ''];
        $pdo->prepare('UPDATE cc_rv_versions SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($set))) . ' WHERE id = ?')
            ->execute([...array_values($set), (int)$v['id']]);
    } finally {
        foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($tmp);
    }
}

/** A imagem da marca de água (PNG transparente com o texto, sem depender de fontes no servidor). */
function cc_rv_wm_png(string $label, int $videoW): string {
    $label = mb_strimwidth(trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label), 0, 60, '…');
    $font = 5; $cw = imagefontwidth($font); $ch = imagefontheight($font);
    $small = imagecreatetruecolor(strlen($label) * $cw + 8, $ch + 6);
    imagesavealpha($small, true);
    imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
    imagestring($small, $font, 5, 4, $label, imagecolorallocatealpha($small, 0, 0, 0, 80));
    imagestring($small, $font, 4, 3, $label, imagecolorallocatealpha($small, 255, 255, 255, 45));
    $scale = max(2, (int)round($videoW / 640));
    $big = imagecreatetruecolor(imagesx($small) * $scale, imagesy($small) * $scale);
    imagesavealpha($big, true); imagealphablending($big, false);
    imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));
    imagecopyresized($big, $small, 0, 0, 0, 0, imagesx($big), imagesy($big), imagesx($small), imagesy($small));
    ob_start(); imagepng($big); return (string)ob_get_clean();
}

/** O proxy com a marca de água do cliente (cron). */
function cc_rv_process_wm(PDO $pdo, array $v, string $label): void {
    $ff = cc_rv_bin('ffmpeg');
    if (!$ff) throw new RuntimeException('Falta o ffmpeg no servidor.');
    if ((string)$v['proxy_key'] === '' || (string)$v['proxy_key'] === (string)$v['file_key']) throw new RuntimeException('O proxy ainda não está pronto.');
    $tmp = sys_get_temp_dir() . '/ccwm_' . (int)$v['id'] . '_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0700, true);
    try {
        file_put_contents($tmp . '/wm.png', cc_rv_wm_png($label, (int)$v['width'] ?: 1280));
        [$code, , $err] = cc_rv_run([$ff, '-hide_banner', '-loglevel', 'error', '-y', '-i', r2_presign('GET', (string)$v['proxy_key'], 6 * 3600), '-i', $tmp . '/wm.png',
            '-filter_complex', '[0:v][1:v]overlay=W-w-W/40:H-h-H/30,format=yuv420p', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22',
            '-g', (string)max(1, (int)round($v['fps_num'] / $v['fps_den'] / 2)), '-sc_threshold', '0', '-c:a', 'copy', '-movflags', '+faststart', $tmp . '/wm.mp4']);
        if ($code !== 0 || !is_file($tmp . '/wm.mp4')) throw new RuntimeException('O ffmpeg não fez a marca de água: ' . trim(mb_substr($err, -300)));
        $key = cc_rv_put((int)$v['id'], 'wm.mp4', (string)file_get_contents($tmp . '/wm.mp4'), 'video/mp4');
        $pdo->prepare("UPDATE cc_rv_versions SET wm_key = ?, wm_label = ?, wm_status = 'ready' WHERE id = ?")->execute([$key, mb_substr($label, 0, 160), (int)$v['id']]);
    } finally {
        foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
        @rmdir($tmp);
    }
}

/** A fila (cc_jobs): rv_proc (preparar o vídeo) e rv_wm (marca de água). Só pela linha de comandos. */
function cc_rv_job(PDO $pdo, array $job): void {
    $p = json_decode((string)$job['payload'], true) ?: [];
    $v = cc_rv_version($pdo, (int)($p['version'] ?? 0));
    if (!$v) return;
    try {
        if ($job['kind'] === 'rv_proc') {
            $pdo->prepare("UPDATE cc_rv_versions SET proc_status = 'running' WHERE id = ?")->execute([(int)$v['id']]);
            cc_rv_process_video($pdo, $v);
        } else {
            cc_rv_process_wm($pdo, $v, (string)($p['label'] ?? ''));
        }
        cc_rv_touch($pdo, (int)$v['item_id']);
    } catch (Throwable $e) {
        if ($job['kind'] === 'rv_proc') {
            $pdo->prepare("UPDATE cc_rv_versions SET proc_status = 'error', proc_error = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 250), (int)$v['id']]);
        } else {
            $pdo->prepare("UPDATE cc_rv_versions SET wm_status = 'error' WHERE id = ?")->execute([(int)$v['id']]);
        }
        cc_rv_touch($pdo, (int)$v['item_id']);
        throw $e;
    }
}

/** Põe uma versão de vídeo a preparar (o cron faz o resto). */
function cc_rv_queue_video(PDO $pdo, array $v, ?int $by): void {
    cc_job_add($pdo, 'rv_proc', null, null, ['version' => (int)$v['id']], $by);
}

/** O texto da marca de água: o cliente e o ID do negócio. */
function cc_rv_wm_label(PDO $pdo, array $item): string {
    $st = $pdo->prepare('SELECT d.code, d.name, COALESCE(co.name, NULLIF(d.company, \'\'), d.name) AS client FROM deals d LEFT JOIN contacts co ON co.id = d.company_id WHERE d.id = ?');
    $st->execute([(int)$item['deal_id']]);
    $d = $st->fetch() ?: ['code' => '', 'client' => ''];
    return trim($d['client'] . ' · review · ' . $d['code'], ' ·');
}

// ── timecode SMPTE ──────────────────────────────────────────

/** [fps nominal, drop frame?] — 29,97 e 59,94 contam em drop frame. */
function cc_rv_tc_base(int $fn, int $fd): array {
    $fps = $fn / max(1, $fd);
    $nom = (int)round($fps);
    return [$nom, abs($fps - $nom) > 0.001 && in_array($nom, [30, 60], true)];
}

/** «01:00:04;12» → número de frames. */
function cc_rv_tc_frames(string $tc, int $fn, int $fd): int {
    if (!preg_match('/^(\d{2}):(\d{2}):(\d{2})[:;.](\d{2})$/', $tc, $m)) return 0;
    [$nom, $df] = cc_rv_tc_base($fn, $fd);
    $f = (((int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3]) * $nom) + (int)$m[4];
    if ($df) { $mins = (int)$m[1] * 60 + (int)$m[2]; $f -= ($nom === 30 ? 2 : 4) * ($mins - intdiv($mins, 10)); }
    return $f;
}

/** O timecode SMPTE de um frame (a contar do início do vídeo), somado ao timecode de início. */
function cc_rv_tc(int $frame, int $fn, int $fd, string $start = '00:00:00:00'): string {
    [$nom, $df] = cc_rv_tc_base($fn, $fd);
    $t = max(0, cc_rv_tc_frames($start, $fn, $fd) + $frame);
    if ($df) {
        $drop = $nom === 30 ? 2 : 4;
        $per10 = $nom * 600 - $drop * 9;
        $perMin = $nom * 60 - $drop;
        $d = intdiv($t, $per10); $m = $t % $per10;
        $t += $drop * 9 * $d + ($m > $drop ? $drop * intdiv($m - $drop, $perMin) : 0);
    }
    $ff = $t % $nom; $s = intdiv($t, $nom);
    return sprintf('%02d:%02d:%02d%s%02d', intdiv($s, 3600) % 24, intdiv($s, 60) % 60, $s % 60, $df ? ';' : ':', $ff);
}

// ── comentários ─────────────────────────────────────────────

/** O desenho de um comentário, limpo: até 60 formas, coordenadas entre 0 e 1, cores da paleta. */
function cc_rv_drawing_clean($raw): ?string {
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return null;
    $ok = [];
    $colors = ['#FFC718', '#81CFE3', '#FBAE9C', '#FFFFFF', '#111111', '#2A7928', '#D9443A'];
    $num = static fn($v): float => round(max(-0.2, min(1.2, (float)$v)), 4);
    foreach (array_slice($d, 0, 60) as $sh) {
        if (!is_array($sh) || !in_array($sh['t'] ?? '', ['arrow', 'rect', 'ellipse', 'path', 'text'], true)) continue;
        $pts = array_map($num, array_slice(array_values((array)($sh['p'] ?? [])), 0, $sh['t'] === 'path' ? 600 : 4));
        if (count($pts) < 2 || count($pts) % 2) continue;
        $c = strtoupper((string)($sh['c'] ?? '#FFC718'));
        $o = ['t' => $sh['t'], 'p' => $pts, 'c' => in_array($c, $colors, true) ? $c : '#FFC718'];
        if ($sh['t'] === 'text') $o['s'] = mb_substr(trim((string)($sh['s'] ?? '')), 0, 120);
        $ok[] = $o;
    }
    return $ok ? json_encode($ok) : null;
}

/** Um comentário para fora. Para o cliente: sem e-mails da equipa, sem nada interno. */
function cc_rv_comment_out(array $c, array $v, bool $forClient): array {
    $fn = (int)$v['fps_num']; $fd = (int)$v['fps_den'];
    $out = [
        'id' => (int)$c['id'], 'parent' => $c['parent_id'] ? (int)$c['parent_id'] : null, 'version' => (int)$c['version_id'],
        'client' => $c['author_kind'] === 'client', 'who' => $c['author_kind'] === 'client' ? (string)$c['client_name'] : (string)($c['who'] ?? 'topper.'),
        'body' => (string)$c['body'], 'vis' => (string)$c['visibility'], 'draft' => (int)$c['draft'] === 1,
        'fi' => $c['frame_in'] !== null ? (int)$c['frame_in'] : null, 'fo' => $c['frame_out'] !== null ? (int)$c['frame_out'] : null,
        'x' => $c['pin_x'] !== null ? (float)$c['pin_x'] : null, 'y' => $c['pin_y'] !== null ? (float)$c['pin_y'] : null, 'page' => $c['page'] !== null ? (int)$c['page'] : null,
        'drawing' => $c['drawing'] ? json_decode((string)$c['drawing'], true) : null,
        'resolved' => $c['resolved_at'] !== null, 'at' => date('d/m/Y H:i', strtotime((string)$c['created_at'])),
    ];
    if ($out['fi'] !== null) {
        $out['tc'] = cc_rv_tc($out['fi'], $fn, $fd, (string)$v['tc_start']) . ($out['fo'] !== null && $out['fo'] > $out['fi'] ? '–' . cc_rv_tc($out['fo'], $fn, $fd, (string)$v['tc_start']) : '');
    }
    if (!$forClient) {
        $out['mine'] = (int)($c['user_id'] ?? 0) === (int)(current_user()['id'] ?? -1);
        $out['email'] = $c['author_kind'] === 'client' ? (string)$c['client_email'] : '';
        $out['att'] = $c['attachments'] ? json_decode((string)$c['attachments'], true) : [];
        $out['round'] = $c['round_id'] ? (int)$c['round_id'] : null;
    }
    return $out;
}

/**
 * Os comentários de uma peça (de todas as versões, ou de uma).
 * $mode team: todos (menos apagados e rascunhos do cliente por enviar).
 * $mode client: só «Para o cliente», enviados — e os rascunhos deste browser ($clientKey).
 */
function cc_rv_comments(PDO $pdo, array $item, ?int $versionId, string $mode, string $clientKey = ''): array {
    $sql = 'SELECT c.*, u.name AS who FROM cc_comments c LEFT JOIN users u ON u.id = c.user_id WHERE c.item_id = ? AND c.deleted_at IS NULL';
    $args = [(int)$item['id']];
    if ($versionId) { $sql .= ' AND c.version_id = ?'; $args[] = $versionId; }
    if ($mode === 'client') {
        $sql .= " AND c.visibility = 'client' AND (c.draft = 0 OR (c.client_key = ? AND c.client_key <> ''))";
        $args[] = $clientKey;
    } else {
        $sql .= ' AND c.draft = 0';
    }
    $st = $pdo->prepare($sql . ' ORDER BY c.id');
    $st->execute($args);
    $rows = $st->fetchAll();
    // uma resposta a um comentário que o cliente não vê também não aparece
    if ($mode === 'client') {
        $ids = array_flip(array_map(fn($r) => (int)$r['id'], $rows));
        $rows = array_values(array_filter($rows, fn($r) => !$r['parent_id'] || isset($ids[(int)$r['parent_id']])));
    }
    $vers = [];
    foreach (cc_rv_versions($pdo, (int)$item['id']) as $v) $vers[(int)$v['id']] = $v;
    $out = [];
    foreach ($rows as $r) {
        $v = $vers[(int)$r['version_id']] ?? null;
        if ($v) $out[] = cc_rv_comment_out($r, $v, $mode === 'client');
    }
    return $out;
}

/**
 * Um comentário novo. $a: ['kind' => team, 'user' => id] ou ['kind' => client, 'name', 'email', 'key'].
 * $in: body, fi, fo (frames), x, y, page (pino), drawing, vis (internal|client), parent.
 */
function cc_rv_comment_add(PDO $pdo, array $item, array $v, array $in, array $a): int {
    $body = mb_substr(trim((string)($in['body'] ?? '')), 0, 4000);
    $drawing = cc_rv_drawing_clean($in['drawing'] ?? null);
    if ($body === '' && $drawing === null) throw new RuntimeException('Escreva o comentário (ou desenhe no frame).');
    $client = $a['kind'] === 'client';
    $parent = (int)($in['parent'] ?? 0) ?: null;
    if ($parent) {
        $st = $pdo->prepare('SELECT * FROM cc_comments WHERE id = ? AND item_id = ? AND deleted_at IS NULL');
        $st->execute([$parent, (int)$item['id']]);
        $p = $st->fetch();
        if (!$p || ($client && ($p['visibility'] !== 'client' || ((int)$p['draft'] && $p['client_key'] !== $a['key'])))) throw new RuntimeException('Esse comentário já não existe.');
        $in['fi'] = $p['frame_in']; $in['fo'] = $p['frame_out']; $in['x'] = $p['pin_x']; $in['y'] = $p['pin_y']; $in['page'] = $p['page'];
    }
    $max = max(0, (int)$v['frames'] - 1);
    $fi = isset($in['fi']) && $in['fi'] !== '' && $in['fi'] !== null ? max(0, (int)$in['fi']) : null;
    $fo = isset($in['fo']) && $in['fo'] !== '' && $in['fo'] !== null ? max(0, (int)$in['fo']) : null;
    if ($max > 0) { if ($fi !== null) $fi = min($fi, $max); if ($fo !== null) $fo = min($fo, $max); }
    if ($fi !== null && $fo !== null && $fo < $fi) [$fi, $fo] = [$fo, $fi];
    if ($fo !== null && $fo === $fi) $fo = null;
    $pin = static fn($x) => $x === null || $x === '' ? null : round(max(0, min(1, (float)$x)), 5);
    $vis = $client ? 'client' : (($in['vis'] ?? '') === 'client' ? 'client' : 'internal');
    $mentions = $client ? [] : cc_mentions($pdo, $body);
    $pdo->prepare('INSERT INTO cc_comments (board_id, item_id, version_id, parent_id, user_id, author_kind, client_name, client_email, client_key, body, mentions,
                                            frame_in, frame_out, pin_x, pin_y, page, drawing, visibility, draft, attachments, created_at)
                   VALUES (0,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$item['id'], (int)$v['id'], $parent, $client ? null : (int)$a['user'], $client ? 'client' : 'team',
                   $client ? mb_substr((string)$a['name'], 0, 160) : '', $client ? mb_substr((string)$a['email'], 0, 190) : '', $client ? (string)$a['key'] : '',
                   $body, $mentions ? ',' . implode(',', $mentions) . ',' : '', $fi, $fo, $pin($in['x'] ?? null), $pin($in['y'] ?? null),
                   isset($in['page']) && $in['page'] !== '' && $in['page'] !== null ? max(1, (int)$in['page']) : null, $drawing, $vis, $client ? 1 : 0,
                   !empty($in['attachments']) ? json_encode($in['attachments']) : null, cc_rv_now()]);
    $id = (int)$pdo->lastInsertId();
    // um comentário da equipa «Para o cliente» numa peça já enviada também aparece no portal
    cc_rv_touch($pdo, (int)$item['id']);
    return $id;
}

/** Resolver / Reabrir (equipa). */
function cc_rv_comment_resolve(PDO $pdo, array $item, int $id, bool $on, int $by): void {
    $pdo->prepare('UPDATE cc_comments SET resolved_at = ?, resolved_by = ? WHERE id = ? AND item_id = ? AND deleted_at IS NULL')
        ->execute([$on ? cc_rv_now() : null, $on ? $by : null, $id, (int)$item['id']]);
    cc_rv_touch($pdo, (int)$item['id']);
}

/** Apagar (com «Anular»): a equipa apaga os seus; o cliente só os seus rascunhos. */
function cc_rv_comment_delete(PDO $pdo, array $item, int $id, array $a, bool $restore = false): void {
    if ($a['kind'] === 'client') {
        $st = $pdo->prepare('UPDATE cc_comments SET deleted_at = ? WHERE id = ? AND item_id = ? AND draft = 1 AND client_key = ? AND client_key <> \'\'');
        $st->execute([$restore ? null : cc_rv_now(), $id, (int)$item['id'], (string)$a['key']]);
    } else {
        $own = !empty($a['admin']) ? '' : ' AND user_id = ' . (int)$a['user'];
        $st = $pdo->prepare('UPDATE cc_comments SET deleted_at = ? WHERE id = ? AND item_id = ? AND author_kind = \'team\'' . $own);
        $st->execute([$restore ? null : cc_rv_now(), $id, (int)$item['id']]);
    }
    if ($st->rowCount() !== 1) throw new RuntimeException($restore ? 'Não foi possível repor.' : 'Só pode apagar os seus comentários.');
    cc_rv_touch($pdo, (int)$item['id']);
}

// ── rondas ──────────────────────────────────────────────────

/** A ronda aberta (enviada ao cliente, ainda sem o envio consolidado). */
function cc_rv_open_round(PDO $pdo, int $itemId): ?array {
    $st = $pdo->prepare('SELECT * FROM cc_rv_rounds WHERE item_id = ? AND submitted_at IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$itemId]);
    return $st->fetch() ?: null;
}

/** «Ronda X de Y incluídas» na etapa da peça: [feitas, incluídas|null, aberta?, nome da etapa]. */
function cc_rv_round_info(PDO $pdo, array $item): array {
    require_once __DIR__ . '/pipeline_flow.php';
    $stage = $item['stage_id'] ? stage_get($pdo, (int)$item['stage_id']) : null;
    $done = $stage ? rounds_count($pdo, (int)$item['deal_id'], (int)$stage['id']) : (int)$pdo->query('SELECT COUNT(*) FROM cc_rv_rounds WHERE submitted_at IS NOT NULL AND item_id = ' . (int)$item['id'])->fetchColumn();
    return ['done' => $done, 'included' => $stage && $stage['rounds_included'] !== null ? (int)$stage['rounds_included'] : null,
            'open' => cc_rv_open_round($pdo, (int)$item['id']) !== null, 'stage' => $stage['name'] ?? ''];
}

/**
 * «Enviar ao cliente»: a versão atual fica visível no portal e abre-se uma ronda. Com marca de água,
 * o servidor prepara o proxy com o nome do cliente.
 */
function cc_rv_send(PDO $pdo, array $item, int $by): string {
    $v = $item['current_version_id'] ? cc_rv_version($pdo, (int)$item['current_version_id']) : null;
    if (!$v) throw new RuntimeException('Carregue primeiro uma versão.');
    if ($v['proc_status'] !== 'ready') throw new RuntimeException('A versão ainda está a ser preparada. Envie quando estiver pronta.');
    if ($item['status'] === 'approved' && (int)$item['approved_version_id'] === (int)$v['id']) throw new RuntimeException('Esta versão já está aprovada.');
    $now = cc_rv_now();
    $pdo->prepare('UPDATE cc_rv_versions SET sent_at = COALESCE(sent_at, ?) WHERE id = ?')->execute([$now, (int)$v['id']]);
    $deal = files_deal_row($pdo, (int)$item['deal_id']);
    $stageId = $deal ? cc_rv_stage_for($pdo, $deal, $item['line_id'] ? (int)$item['line_id'] : null) : null;
    $pdo->prepare("UPDATE cc_rv_items SET shared = 1, status = 'sent', stage_id = COALESCE(?, stage_id), updated_at = ? WHERE id = ?")->execute([$stageId, $now, (int)$item['id']]);
    $open = cc_rv_open_round($pdo, (int)$item['id']);
    if ($open) {
        $pdo->prepare('UPDATE cc_rv_rounds SET version_id = ? WHERE id = ?')->execute([(int)$v['id'], (int)$open['id']]);
    } else {
        $num = (int)$pdo->query('SELECT COUNT(*) + 1 FROM cc_rv_rounds WHERE item_id = ' . (int)$item['id'])->fetchColumn();
        $pdo->prepare('INSERT INTO cc_rv_rounds (item_id, deal_id, stage_id, num, version_id, opened_at, opened_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([(int)$item['id'], (int)$item['deal_id'], $stageId, $num, (int)$v['id'], $now, $by]);
    }
    if ((int)$item['watermark'] && cc_rv_kind_of((string)$v['mime'], (string)$v['file_name']) === 'video' && cc_rv_ffmpeg_known()) {
        $label = cc_rv_wm_label($pdo, $item);
        if ($v['wm_status'] !== 'ready' || $v['wm_label'] !== $label) {
            $pdo->prepare("UPDATE cc_rv_versions SET wm_status = 'queued' WHERE id = ?")->execute([(int)$v['id']]);
            cc_job_add($pdo, 'rv_wm', null, null, ['version' => (int)$v['id'], 'label' => $label], $by);
        }
    }
    cc_rv_touch($pdo, (int)$item['id']);
    deal_log($pdo, (int)$item['deal_id'], 'review', ['note' => $item['title'] . ' · ' . cc_rv_vlabel((int)$v['num']) . ' enviada ao cliente', 'user_id' => $by]);
    return cc_rv_vlabel((int)$v['num']) . ' enviada ao cliente: já aparece no portal.';
}

/** «Pedir alterações» (equipa): a peça volta à equipa. */
function cc_rv_request_changes(PDO $pdo, array $item, int $by, string $note = ''): void {
    $pdo->prepare("UPDATE cc_rv_items SET status = 'changes', updated_at = ? WHERE id = ?")->execute([cc_rv_now(), (int)$item['id']]);
    cc_rv_touch($pdo, (int)$item['id']);
    deal_log($pdo, (int)$item['deal_id'], 'review', ['note' => $item['title'] . ' · alterações pedidas' . (trim($note) !== '' ? ': ' . trim($note) : ''), 'user_id' => $by]);
}

/**
 * O envio consolidado do cliente («Enviar comentários»): os rascunhos deste browser passam a
 * comentários, a ronda fecha e conta nas rondas da etapa. Acima do incluído: aviso ao comercial
 * e uma tarefa «Pedido de alteração».
 */
function cc_rv_client_submit(PDO $pdo, array $share, array $item, array $a): string {
    $st = $pdo->prepare("SELECT id FROM cc_comments WHERE item_id = ? AND draft = 1 AND client_key = ? AND client_key <> '' AND deleted_at IS NULL");
    $st->execute([(int)$item['id'], (string)$a['key']]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) throw new RuntimeException('Ainda não escreveu nenhum comentário.');
    $round = cc_rv_open_round($pdo, (int)$item['id']);
    $now = cc_rv_now();
    if (!$round) {
        // a equipa ainda não reabriu a ronda (ex.: comentários depois do envio): conta como uma nova
        $num = (int)$pdo->query('SELECT COUNT(*) + 1 FROM cc_rv_rounds WHERE item_id = ' . (int)$item['id'])->fetchColumn();
        $pdo->prepare('INSERT INTO cc_rv_rounds (item_id, deal_id, stage_id, num, version_id, opened_at) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$item['id'], (int)$item['deal_id'], $item['stage_id'], $num, (int)$item['current_version_id'], $now]);
        $round = cc_rv_open_round($pdo, (int)$item['id']);
    }
    $pdo->prepare('UPDATE cc_comments SET draft = 0, round_id = ?, created_at = ? WHERE id IN (' . implode(',', $ids) . ')')->execute([(int)$round['id'], $now]);
    $v = cc_rv_version($pdo, (int)$round['version_id']);
    $note = 'Review · ' . $item['title'] . ' ' . cc_rv_vlabel((int)($v['num'] ?? 0)) . ' · ' . count($ids) . (count($ids) === 1 ? ' comentário' : ' comentários') . ' de ' . $a['name'];
    require_once __DIR__ . '/pipeline_flow.php';
    $deal = files_deal_row($pdo, (int)$item['deal_id']);
    $stage = $item['stage_id'] ? stage_get($pdo, (int)$item['stage_id']) : null;
    $over = false; $dealRound = null;
    if ($deal && $stage) {
        rounds_add($pdo, $deal, $stage, $note, 0);
        $dealRound = (int)$pdo->lastInsertId() ?: null;
        $n = rounds_count($pdo, (int)$deal['id'], (int)$stage['id']);
        $over = $stage['rounds_included'] !== null && $n > (int)$stage['rounds_included'];
    } elseif ($deal) {
        deal_log($pdo, (int)$deal['id'], 'round', ['note' => $note]);
    }
    $pdo->prepare('UPDATE cc_rv_rounds SET submitted_at = ?, client_name = ?, client_email = ?, comments_n = ?, deal_round_id = ?, over_included = ? WHERE id = ?')
        ->execute([$now, mb_substr((string)$a['name'], 0, 160), mb_substr((string)$a['email'], 0, 190), count($ids), $dealRound, $over ? 1 : 0, (int)$round['id']]);
    $pdo->prepare("UPDATE cc_rv_items SET status = 'comments', updated_at = ? WHERE id = ?")->execute([$now, (int)$item['id']]);
    if ($over && $deal) cc_rv_change_order_task($pdo, $deal, $item, (int)$round['num']);
    cc_rv_touch($pdo, (int)$item['id']);
    return count($ids) === 1 ? 'Comentário enviado. Obrigado!' : count($ids) . ' comentários enviados. Obrigado!';
}

/** Acima do incluído: uma tarefa para o comercial — «Pedido de alteração» (§19.6 D). */
function cc_rv_change_order_task(PDO $pdo, array $deal, array $item, int $round): void {
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/leads.php';   // member_for_user
    $title = 'Pedido de alteração · ' . $item['title'] . ' (ronda ' . $round . ', acima do incluído)';
    // uma tarefa aberta por peça chega (as rondas seguintes juntam-se a ela)
    $st = $pdo->prepare("SELECT 1 FROM tasks WHERE deal_id = ? AND title LIKE ? AND status <> 'done'");
    $st->execute([(int)$deal['id'], 'Pedido de alteração · ' . addcslashes((string)$item['title'], '%_\\') . ' (%']);
    if ($st->fetchColumn()) return;
    $member = $deal['owner_id'] ? member_for_user($pdo, (int)$deal['owner_id']) : null;
    $now = task_now();
    $pdo->prepare("INSERT INTO tasks (title, description, member_id, job_name, deal_id, due_date, kind, priority, status, position, created_at, updated_at, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, 'quote', 'alta', 'todo', 0, ?, ?, NULL)")
        ->execute([$title, 'O cliente enviou mais uma ronda de comentários do que as incluídas na proposta. Orçamente o pedido de alteração e peça a aprovação por escrito antes de executar.',
                   $member, (string)$deal['code'], (int)$deal['id'], date('Y-m-d', strtotime('+2 days')), $now, $now]);
}

// ── aprovação ───────────────────────────────────────────────

/** As tarefas de aprovação do Pipeline que esta peça fecha (mapa em Definições). */
function cc_rv_approval_tasks(PDO $pdo, array $item): array {
    $map = [];
    foreach (setting_lines($pdo, 'cc_rv_map', array_map(fn($k, $v) => $k . ' = ' . $v, array_keys(CC_RV_TASK_MAP), CC_RV_TASK_MAP)) as $ln) {
        if (!str_contains($ln, '=')) continue;
        [$k, $pats] = array_map('trim', explode('=', $ln, 2));
        $map[cc_fold($k)] = array_values(array_filter(array_map(fn($p) => cc_fold(trim($p)), explode('|', $pats))));
    }
    $pats = $map[cc_fold((string)$item['stage_key'])] ?? $map[cc_fold(CC_RV_STAGES[$item['stage_key']] ?? '')] ?? [];
    if (!$pats) return [];
    $st = $pdo->prepare("SELECT * FROM tasks WHERE deal_id = ? AND kind = 'review' AND status <> 'done'" . ($item['line_id'] ? ' AND (line_id IS NULL OR line_id = ' . (int)$item['line_id'] . ')' : ''));
    $st->execute([(int)$item['deal_id']]);
    $out = [];
    foreach ($st->fetchAll() as $t) {
        $title = cc_fold((string)$t['title']);
        foreach ($pats as $p) if ($p !== '' && str_contains($title, $p)) { $out[] = $t; break; }
    }
    return $out;
}

/**
 * Aprovar a versão atual (equipa ou cliente pelo portal): a peça fica Aprovada, a tarefa de aprovação
 * correspondente no Pipeline fica feita e a versão entra na Biblioteca como referência da casa.
 * $a: ['kind' => team, 'user' => id] ou ['kind' => client, 'name', 'email', 'ip'].
 */
function cc_rv_approve(PDO $pdo, array $item, array $a): string {
    $v = $item['current_version_id'] ? cc_rv_version($pdo, (int)$item['current_version_id']) : null;
    if (!$v || $v['proc_status'] !== 'ready') throw new RuntimeException('Não há uma versão pronta para aprovar.');
    if ($item['status'] === 'approved' && (int)$item['approved_version_id'] === (int)$v['id']) throw new RuntimeException('Esta versão já está aprovada.');
    $client = $a['kind'] === 'client';
    if ($client) {
        $name = trim(preg_replace('/\s+/u', ' ', (string)$a['name']));
        if (mb_strlen($name) < 3) throw new RuntimeException('Escreva o seu nome para aprovar.');
        if ($v['sent_at'] === null) throw new RuntimeException('Esta versão ainda não foi enviada.');
    }
    $now = cc_rv_now();
    $who = $client ? $name : (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$a['user'])->fetchColumn();
    $pdo->prepare("UPDATE cc_rv_items SET status = 'approved', approved_version_id = ?, approved_kind = ?, approved_by = ?, approved_name = ?, approved_email = ?, approved_ip = ?, approved_at = ?, updated_at = ? WHERE id = ?")
        ->execute([(int)$v['id'], $client ? 'client' : 'team', $client ? null : (int)$a['user'], mb_substr($who, 0, 160), $client ? mb_substr((string)$a['email'], 0, 190) : '',
                   $client ? mb_substr((string)$a['ip'], 0, 45) : '', $now, $now, (int)$item['id']]);
    // a ronda aberta (se houver) fecha sem contar: aprovar não é pedir alterações
    $pdo->prepare('UPDATE cc_rv_rounds SET submitted_at = ?, client_name = ?, comments_n = 0 WHERE item_id = ? AND submitted_at IS NULL')->execute([$now, $client ? mb_substr($who, 0, 160) : '', (int)$item['id']]);
    // a tarefa de aprovação do Pipeline
    require_once __DIR__ . '/tasks.php';
    $closed = [];
    foreach (cc_rv_approval_tasks($pdo, $item) as $t) {
        try { task_done($pdo, (int)$t['id']); $closed[] = $t['title']; } catch (Throwable $e) { error_log('cc_rv_approve task ' . $t['id'] . ': ' . $e->getMessage()); }
    }
    // a Biblioteca (referência da casa)
    $assetId = null;
    try { $assetId = cc_rv_to_library($pdo, $item, $v, $client ? null : (int)$a['user']); } catch (Throwable $e) { error_log('cc_rv_to_library: ' . $e->getMessage()); }
    if ($assetId) $pdo->prepare('UPDATE cc_rv_items SET library_asset_id = ? WHERE id = ?')->execute([$assetId, (int)$item['id']]);
    cc_rv_touch($pdo, (int)$item['id']);
    deal_log($pdo, (int)$item['deal_id'], 'review', ['note' => $item['title'] . ' · ' . cc_rv_vlabel((int)$v['num']) . ' aprovada por ' . $who . ($client ? ' (cliente, no portal)' : '')
        . ($closed ? ' · tarefa feita: ' . implode(', ', $closed) : ''), 'user_id' => $client ? null : (int)$a['user']]);
    return cc_rv_vlabel((int)$v['num']) . ' aprovada.' . ($closed ? ' A tarefa «' . $closed[0] . '» ficou feita.' : '');
}

/** A versão aprovada entra na Biblioteca: referência da casa (topper.), ligada ao negócio. */
function cc_rv_to_library(PDO $pdo, array $item, array $v, ?int $by): ?int {
    $kind = cc_rv_kind_of((string)$v['mime'], (string)$v['file_name']);
    $poster = (string)$v['poster_key'] !== '' ? r2_get((string)$v['poster_key'], null, 20971520) : null;
    [$a, $new] = cc_asset_create($pdo, null, [
        'name' => (string)$v['file_name'], 'mime' => $kind === 'pdf' ? 'image/jpeg' : (string)$v['mime'], 'kind' => $kind === 'video' ? 'video' : 'image',
        'title' => $item['title'] . ' · ' . cc_rv_vlabel((int)$v['num']) . ' (aprovada)', 'file_key' => 'r2:' . $v['file_key'], 'fp' => sha1('rv|' . $v['id']),
        'preview' => $poster, 'w' => (int)$v['width'], 'h' => (int)$v['height'], 'duration' => $kind === 'video' ? (float)$v['duration'] : null, 'size' => (int)$v['bytes'],
        'origin' => 'topper', 'deal_id' => (int)$item['deal_id'], 'by' => $by,
    ]);
    if ($new) {
        $pdo->prepare('UPDATE cc_assets SET tags = ? WHERE id = ?')->execute(['aprovado, ' . mb_strtolower(CC_RV_STAGES[$item['stage_key']] ?? ''), (int)$a['id']]);
        cc_asset_reindex($pdo, (int)$a['id']);
    }
    return (int)$a['id'];
}

// ── exportar os comentários ─────────────────────────────────

/**
 * Os comentários de uma versão para a edição: csv · pdf · edl (marcadores DaVinci Resolve) ·
 * xml (FCP7 xmeml: Premiere, DaVinci) · avid (lista de marcadores do Avid). $clientOnly: só os do cliente.
 * Devolve [nome do ficheiro, tipo MIME, bytes].
 */
function cc_rv_export(PDO $pdo, array $item, array $v, string $fmt, bool $clientOnly): array {
    $deal = files_deal_row($pdo, (int)$item['deal_id']);
    $rows = array_values(array_filter(cc_rv_comments($pdo, $item, (int)$v['id'], 'team'), fn($c) => !$clientOnly || $c['vis'] === 'client'));
    $fn = (int)$v['fps_num']; $fd = (int)$v['fps_den']; $fps = $fn / max(1, $fd);
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim(($deal['code'] ?? '') . '_' . $item['title'] . '_' . cc_rv_vlabel((int)$v['num']), '_')) . '_comentarios';
    $who = static fn($c) => $c['who'] . ($c['client'] ? ' (cliente)' : '');
    $text = static fn($c) => trim(preg_replace('/\s+/u', ' ', $c['body'] . ($c['drawing'] ? ' [desenho]' : '')));
    $tc = static fn(int $f) => cc_rv_tc($f, $fn, $fd, (string)$v['tc_start']);
    [, $df] = cc_rv_tc_base($fn, $fd);
    switch ($fmt) {
        case 'csv': {
            $h = fopen('php://temp', 'w+');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['#', 'Versão', 'TC entrada', 'TC saída', 'Frame', 'Pino', 'Autor', 'Visibilidade', 'Comentário', 'Resolvido', 'Data'], ';');
            foreach ($rows as $i => $c) {
                fputcsv($h, [$i + 1, cc_rv_vlabel((int)$v['num']), $c['fi'] !== null ? $tc($c['fi']) : '', $c['fo'] !== null ? $tc($c['fo']) : '', $c['fi'] ?? '',
                             $c['x'] !== null ? sprintf('%s%.0f%% × %.0f%%', $c['page'] ? 'p.' . $c['page'] . ' · ' : '', $c['x'] * 100, $c['y'] * 100) : '',
                             $who($c), $c['vis'] === 'client' ? 'Para o cliente' : 'Interno', $text($c), $c['resolved'] ? 'sim' : 'não', $c['at']], ';');
            }
            rewind($h);
            return [$base . '.csv', 'text/csv; charset=utf-8', (string)stream_get_contents($h)];
        }
        case 'edl': {
            // marcadores para o DaVinci Resolve (Timeline › Import › Timeline Markers from EDL); as respostas não fazem marcador
            $rows = array_values(array_filter($rows, fn($c) => !$c['parent']));
            $o = 'TITLE: ' . mb_strimwidth($item['title'] . ' ' . cc_rv_vlabel((int)$v['num']), 0, 60) . "\r\nFCM: " . ($df ? 'DROP FRAME' : 'NON-DROP FRAME') . "\r\n\r\n";
            $n = 0;
            foreach ($rows as $c) {
                if ($c['fi'] === null) continue;
                $n++;
                $in = $tc($c['fi']); $out = $tc(($c['fo'] ?? $c['fi']) + 1);
                $o .= sprintf("%03d  001      V     C        %s %s %s %s\r\n", $n, $in, $out, $in, $out);
                $o .= ' |C:' . ($c['client'] ? 'ResolveColorYellow' : 'ResolveColorBlue') . ' |M:' . str_replace(['|', "\r", "\n"], ' ', mb_strimwidth($who($c) . ': ' . $text($c), 0, 240)) . ' |D:' . max(1, ($c['fo'] ?? $c['fi']) - $c['fi'] + 1) . "\r\n\r\n";
            }
            return [$base . '.edl', 'text/plain; charset=utf-8', $o];
        }
        case 'xml': {
            // FCP7 (xmeml): marcadores numa sequência — Premiere Pro e DaVinci importam
            $x = static fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $rows = array_values(array_filter($rows, fn($c) => !$c['parent']));
            $ntsc = abs($fps - round($fps)) > 0.001 ? 'TRUE' : 'FALSE';
            $rate = '<rate><timebase>' . (int)round($fps) . '</timebase><ntsc>' . $ntsc . '</ntsc></rate>';
            $m = '';
            foreach ($rows as $c) {
                if ($c['fi'] === null) continue;
                $m .= '<marker><name>' . $x(mb_strimwidth($who($c), 0, 60)) . '</name><comment>' . $x($text($c)) . '</comment><in>' . $c['fi'] . '</in><out>' . ($c['fo'] ?? -1) . '</out></marker>';
            }
            $o = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<!DOCTYPE xmeml>\n<xmeml version=\"5\"><sequence><name>" . $x($item['title'] . ' ' . cc_rv_vlabel((int)$v['num'])) . '</name>'
               . '<duration>' . max(1, (int)$v['frames']) . '</duration>' . $rate . '<timecode>' . $rate . '<string>' . $x((string)$v['tc_start']) . '</string><frame>' . cc_rv_tc_frames((string)$v['tc_start'], $fn, $fd) . '</frame><displayformat>' . ($df ? 'DF' : 'NDF') . '</displayformat></timecode>'
               . $m . '<media><video><format><samplecharacteristics>' . $rate . '<width>' . (int)$v['width'] . '</width><height>' . (int)$v['height'] . '</height></samplecharacteristics></format></video></media></sequence></xmeml>';
            return [$base . '.xml', 'application/xml; charset=utf-8', $o];
        }
        case 'avid': {
            // Avid Media Composer: Markers › Import Markers (texto separado por tabulações)
            $rows = array_values(array_filter($rows, fn($c) => !$c['parent']));
            $o = '';
            foreach ($rows as $c) {
                if ($c['fi'] === null) continue;
                $o .= implode("\t", [preg_replace('/\s+/', '_', mb_substr($who($c), 0, 30)), $tc($c['fi']), 'V1', $c['client'] ? 'yellow' : 'blue', str_replace("\t", ' ', $text($c)),
                                     max(1, ($c['fo'] ?? $c['fi']) - $c['fi'] + 1), '', $c['client'] ? 'yellow' : 'blue']) . "\n";
            }
            return [$base . '_avid.txt', 'text/plain; charset=utf-8', $o];
        }
        case 'pdf': {
            require_once __DIR__ . '/budget_pdf.php';
            $pdf = new BudgetPdf();
            $L = 56.0; $max = BudgetPdf::W - 112.0;
            $ref = trim(($deal['code'] ?? '') . ' · ' . $item['title'] . ' · ' . cc_rv_vlabel((int)$v['num']), ' ·');
            budget_pdf_sheet($pdf, $pdo, 'COMENTÁRIOS', $ref);
            $y = 112.0;
            $need = function (float $h) use (&$y, $pdf, $pdo, $ref) { if ($y + $h <= BudgetPdf::H - 70) return; $pdf->page(); budget_pdf_sheet($pdf, $pdo, 'COMENTÁRIOS', $ref); $y = 100; };
            foreach (BudgetPdf::wrap($item['title'] . ' · ' . cc_rv_vlabel((int)$v['num']), 'F2', 20, $max) as $ln) { $pdf->text($L, $y, $ln, 20, true); $y += 24; }
            $meta = (CC_RV_STAGES[$item['stage_key']] ?? '') . ' · ' . count($rows) . (count($rows) === 1 ? ' comentário' : ' comentários')
                  . ((int)$v['frames'] ? ' · ' . round($fps, 3) . ' fps · início ' . $v['tc_start'] : '') . ($clientOnly ? ' · só os do cliente' : '');
            $pdf->text($L, $y, $meta, 10, false, '#5C5C5C');
            $y += 26;
            foreach ($rows as $i => $c) {
                $head = '#' . ($i + 1) . '  ' . ($c['tc'] ?? ($c['x'] !== null ? ($c['page'] ? 'Página ' . $c['page'] . ' · ' : '') . 'pino' : 'geral')) . '  ·  ' . $who($c)
                      . '  ·  ' . ($c['vis'] === 'client' ? 'para o cliente' : 'interno') . ($c['resolved'] ? '  ·  resolvido' : '') . ($c['parent'] ? '  ·  resposta' : '');
                $need(40);
                $pdf->rect($L, $y - 10, 3, 13, $c['client'] ? '#FFC718' : '#81CFE3');
                $pdf->text($L + 10, $y, $head, 9, true, '#333333');
                $y += 16;
                foreach (BudgetPdf::wrap($text($c), 'F1', 10.5, $max - 10) as $ln) { $need(15); if ($ln !== '') $pdf->text($L + 10, $y, $ln, 10.5); $y += 15; }
                $y += 10;
            }
            $pdf->page();
            return [$base . '.pdf', 'application/pdf', $pdf->output('Comentários ' . $ref)];
        }
    }
    throw new RuntimeException('Formato desconhecido.');
}

// ── portal do cliente: o link ───────────────────────────────

/** A chave que cifra os links guardados (para «Copiar link»): derivada do segredo do servidor. */
function cc_share_key(PDO $pdo): string {
    $sec = (string)setting($pdo, 'proposal_secret', '');
    if ($sec === '') throw new RuntimeException('Falta o segredo do servidor.');
    return hash('sha256', 'portal|' . $sec, true);
}

function cc_share_encrypt(PDO $pdo, string $token): string {
    $iv = random_bytes(12);
    $ct = openssl_encrypt($token, 'aes-256-gcm', cc_share_key($pdo), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}

function cc_share_decrypt(PDO $pdo, string $enc): ?string {
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 29) return null;
    $t = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', cc_share_key($pdo), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $t === false ? null : $t;
}

function cc_share_url(string $token): string {
    require_once __DIR__ . '/team.php';
    return team_app_url() . '/admin/portal.php?t=' . $token;
}

/** O link ativo (não revogado) de um negócio, ou null. */
function cc_share_for_deal(PDO $pdo, int $dealId): ?array {
    $st = $pdo->prepare('SELECT * FROM cc_share WHERE deal_id = ? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$dealId]);
    return $st->fetch() ?: null;
}

/** A validade por defeito: a entrega (ou hoje) + N dias (Definições). */
function cc_share_default_expiry(PDO $pdo, array $deal): string {
    $base = (string)($deal['delivered_at'] ?: ($deal['delivery_date'] ?: date('Y-m-d')));
    $base = substr($base, 0, 10) < date('Y-m-d') ? date('Y-m-d') : substr($base, 0, 10);
    return date('Y-m-d', strtotime($base . ' +' . max(1, (int)setting($pdo, 'cc_portal_days', 60)) . ' days'));
}

/** Cria (ou renova, revogando o anterior) o link do portal. Devolve [linha, token]. */
function cc_share_create(PDO $pdo, array $deal, int $by): array {
    $old = cc_share_for_deal($pdo, (int)$deal['id']);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pdo->prepare('UPDATE cc_share SET revoked_at = ? WHERE deal_id = ? AND revoked_at IS NULL')->execute([cc_rv_now(), (int)$deal['id']]);
    $lang = in_array((string)setting($pdo, 'cc_portal_lang', 'pt'), ['pt', 'en'], true) ? (string)setting($pdo, 'cc_portal_lang', 'pt') : 'pt';
    $pdo->prepare('INSERT INTO cc_share (deal_id, token_hash, token_enc, boards, tabs, lang, expires_on, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$deal['id'], hash('sha256', $token), cc_share_encrypt($pdo, $token), $old['boards'] ?? '', $old['tabs'] ?? 'proposal,boards,review',
                   $old['lang'] ?? $lang, cc_share_default_expiry($pdo, $deal), $by, cc_rv_now()]);
    deal_log($pdo, (int)$deal['id'], 'portal', ['note' => $old ? 'Link do portal do cliente renovado (o anterior deixou de funcionar)' : 'Link do portal do cliente criado', 'user_id' => $by]);
    return [cc_share_for_deal($pdo, (int)$deal['id']), $token];
}

function cc_share_by_token(PDO $pdo, string $token): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{40,60}$/', $token)) return null;
    $st = $pdo->prepare('SELECT * FROM cc_share WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    return $st->fetch() ?: null;
}

/** O link funciona? (não revogado, dentro da validade) */
function cc_share_valid(array $s): bool {
    return $s['revoked_at'] === null && ($s['expires_on'] === null || (string)$s['expires_on'] >= date('Y-m-d'));
}

/** Os separadores que o cliente vê: proposal, boards, review. */
function cc_share_tabs(array $s): array {
    return array_values(array_intersect(['proposal', 'boards', 'review'], explode(',', (string)$s['tabs'])));
}

/** Os quadros partilhados (ids). */
function cc_share_boards(array $s): array {
    return array_values(array_filter(array_map('intval', explode(',', (string)$s['boards']))));
}

/** Regista um acesso/ação do cliente. As aberturas repetidas do mesmo IP em 30 min contam uma vez. */
function cc_share_log(PDO $pdo, array $s, string $action, array $o = []): void {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($action === 'open') {
        $st = $pdo->prepare("SELECT 1 FROM cc_share_log WHERE share_id = ? AND action = 'open' AND ip = ? AND at > ?");
        $st->execute([(int)$s['id'], $ip, date('Y-m-d H:i:s', time() - 1800)]);
        if ($st->fetchColumn()) return;
    }
    $pdo->prepare('INSERT INTO cc_share_log (share_id, deal_id, at, ip, ua, client_name, client_email, action, item_id, detail) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$s['id'], (int)$s['deal_id'], cc_rv_now(), mb_substr($ip, 0, 45), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                   mb_substr((string)($o['name'] ?? ''), 0, 160), mb_substr((string)($o['email'] ?? ''), 0, 190), $action, $o['item'] ?? null, mb_substr((string)($o['detail'] ?? ''), 0, 255)]);
    $pdo->prepare('UPDATE cc_share SET last_access_at = ? WHERE id = ?')->execute([cc_rv_now(), (int)$s['id']]);
}

/** Limite de pedidos por IP e por minuto (o portal é público). Devolve true se ainda pode. */
function cc_portal_hit(PDO $pdo, int $limit): bool {
    $h = hash('sha256', 'portal|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $win = intdiv(time(), 60);
    try {
        if (db_is_sqlite($pdo)) {
            $pdo->prepare('INSERT OR IGNORE INTO cc_portal_hits (ip_hash, win, n) VALUES (?, ?, 0)')->execute([$h, $win]);
            $pdo->prepare('UPDATE cc_portal_hits SET n = n + 1 WHERE ip_hash = ? AND win = ?')->execute([$h, $win]);
        } else {
            $pdo->prepare('INSERT INTO cc_portal_hits (ip_hash, win, n) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$h, $win]);
        }
        $st = $pdo->prepare('SELECT n FROM cc_portal_hits WHERE ip_hash = ? AND win = ?');
        $st->execute([$h, $win]);
        $n = (int)$st->fetchColumn();
        if (random_int(1, 50) === 1) $pdo->prepare('DELETE FROM cc_portal_hits WHERE win < ?')->execute([$win - 60]);
        return $n <= $limit;
    } catch (Throwable $e) {
        return true;
    }
}

/** O formulário do portal, assinado pelo servidor (o portal não tem sessão). */
function cc_portal_sig(PDO $pdo, array $s, int $ts): string {
    return hash_hmac('sha256', 'portal|' . $s['token_hash'] . '|' . $ts, (string)setting($pdo, 'proposal_secret', ''));
}
