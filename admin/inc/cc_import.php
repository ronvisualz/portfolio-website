<?php
/**
 * topper. admin — importação para o Toppernote (§23.7). Mecanismo pronto; nada importado.
 *
 * Aceita os formatos de exportação do Milanote e afins:
 *   · ZIP   imagens, vídeos, PDFs, Markdown/texto (as imagens referidas no Markdown ficam junto da nota);
 *   · PDF   as imagens JPEG embebidas e o texto de cada página (melhor esforço, sem bibliotecas);
 *   · Markdown / texto   cada secção (título ou parágrafo) vira uma nota; imagens remotas são descarregadas;
 *   · imagem solta (a «pasta de imagens» envia-se pelo browser, uma a uma, para o mesmo Topperboard).
 * Cria um Topperboard novo com os cartões em grelha (notas como notas) e as imagens entram na biblioteca
 * (com a análise da IA, se estiver ligada). Corre pela fila, em pedaços de ~15 s.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/cc.php';

const CC_IMPORT_COLS = 5;
const CC_IMPORT_CELL = 300;
const CC_IMPORT_MAX  = 524288000;   // 500 MB

/** O tipo de um ficheiro de importação (pelo conteúdo, não só pelo nome). */
function cc_import_kind(string $path, string $name): string {
    $head = (string)@file_get_contents($path, false, null, 0, 8);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (strncmp($head, "PK\x03\x04", 4) === 0) return 'zip';
    if (strncmp($head, '%PDF-', 5) === 0) return 'pdf';
    if (in_array($ext, ['md', 'markdown', 'txt'], true)) return 'md';
    if (@getimagesize($path)) return 'image';
    return '';
}

/** Recebe o ficheiro, cria o Topperboard e põe a importação na fila (e começa já). */
function cc_import_upload(PDO $pdo, array $file, string $title, int $by): array {
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('O envio falhou. Tente outra vez.');
    if ((int)$file['size'] > CC_IMPORT_MAX) throw new RuntimeException('O ficheiro tem mais de 500 MB.');
    $name = r2_clean_name((string)$file['name']) ?: 'importacao';
    $kind = cc_import_kind((string)$file['tmp_name'], $name);
    if ($kind === '') throw new RuntimeException('Formato não suportado: use ZIP, PDF, Markdown ou imagens.');
    if ($kind === 'zip' && !class_exists('ZipArchive')) throw new RuntimeException('Este servidor não abre ficheiros ZIP (falta a extensão zip do PHP).');
    $title = mb_substr(trim($title), 0, 200) ?: 'Importado · ' . preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $name);
    $board = cc_board_create($pdo, ['title' => $title, 'kind' => 'free'], $by);
    $key = cc_store_put('imp/' . date('Ym') . '/' . $board . '_' . sha1($name . microtime(true)) . '.' . ($kind === 'md' ? 'md' : $kind), (string)file_get_contents((string)$file['tmp_name']), (string)($file['type'] ?: 'application/octet-stream'));
    cc_job_add($pdo, 'import', null, $board, ['key' => $key, 'kind' => $kind, 'name' => $name, 'pos' => 0, 'cell' => 0, 'by' => $by], $by);
    cc_jobs_run($pdo, 15.0);
    $n = (int)$pdo->query('SELECT COUNT(*) FROM cc_cards WHERE deleted_at IS NULL AND board_id = ' . $board)->fetchColumn();
    $left = (int)$pdo->query("SELECT COUNT(*) FROM cc_jobs WHERE kind = 'import' AND board_id = $board AND status IN ('queued','running')")->fetchColumn();
    return ['board' => $board, 'msg' => $left ? $n . ' cartões até agora; o resto continua em segundo plano.' : ($n === 1 ? '1 cartão importado.' : $n . ' cartões importados.')];
}

/** O lugar seguinte na grelha. */
function cc_import_cell(int $i): array {
    return [80 + ($i % CC_IMPORT_COLS) * CC_IMPORT_CELL, 80 + intdiv($i, CC_IMPORT_COLS) * CC_IMPORT_CELL];
}

/** Um cartão de referência a partir de bytes (imagem, vídeo ou ficheiro). */
function cc_import_asset(PDO $pdo, int $board, string $bytes, string $name, int &$cell, int $by): void {
    $mime = r2_mime($name);
    [$a, $new] = cc_asset_create($pdo, $bytes, ['name' => $name, 'mime' => $mime, 'by' => $by, 'inbox' => false]);
    if ($new) $a = cc_place_original($pdo, $a, cc_board_get($pdo, $board), false, $by);   // «Biblioteca criativa/<Topperboard>/»
    [$x, $y] = cc_import_cell($cell++);
    $ratio = $a['width'] && $a['height'] ? (float)$a['height'] / (float)$a['width'] : 0.62;
    cc_card_add($pdo, $board, ['type' => in_array($a['kind'], ['image', 'video'], true) ? $a['kind'] : 'file', 'asset_id' => (int)$a['id'],
                               'x' => $x, 'y' => $y, 'w' => 280, 'h' => $a['kind'] === 'file' ? 0 : round(280 * min(1.6, $ratio)), 'content' => []], $by);
}

function cc_import_note(PDO $pdo, int $board, string $text, int &$cell, int $by): void {
    $text = trim($text);
    if ($text === '') return;
    [$x, $y] = cc_import_cell($cell++);
    cc_card_add($pdo, $board, ['type' => 'note', 'x' => $x, 'y' => $y, 'w' => 280, 'content' => ['text' => mb_substr($text, 0, 20000)]], $by);
}

/** Markdown → blocos: [['note', texto] | ['img', caminho ou URL]]. Títulos começam blocos novos. */
function cc_md_blocks(string $md): array {
    $md = str_replace("\r\n", "\n", $md);
    $out = [];
    $buf = '';
    $flush = function () use (&$buf, &$out) { if (trim($buf) !== '') $out[] = ['note', trim($buf)]; $buf = ''; };
    foreach (explode("\n", $md) as $line) {
        if (preg_match_all('/!\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $line, $m)) {
            $rest = trim(preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $line));
            foreach ($m[1] as $src) { $flush(); $out[] = ['img', urldecode($src)]; }
            if ($rest !== '') $buf .= $rest . "\n";
            continue;
        }
        if (preg_match('/^#{1,6}\s+/', $line)) { $flush(); $buf = preg_replace('/^#{1,6}\s+/', '', $line) . "\n"; continue; }
        if (trim($line) === '' && mb_strlen($buf) > 600) { $flush(); continue; }
        $buf .= $line . "\n";
    }
    $flush();
    // limpa a marcação simples (negrito, itálico, links → «texto (url)»)
    foreach ($out as &$b) {
        if ($b[0] !== 'note') continue;
        $b[1] = preg_replace(['/\*\*(.+?)\*\*/u', '/(?<!\*)\*(?!\*)(.+?)\*/u', '/\[([^\]]+)\]\(([^)]+)\)/u'], ['$1', '$1', '$1 ($2)'], $b[1]);
    }
    unset($b);
    return $out;
}

/** O texto e as imagens JPEG de um PDF (melhor esforço: streams DCTDecode e operadores Tj/TJ). */
function cc_pdf_extract(string $pdf): array {
    $images = [];
    $texts = [];
    if (!preg_match_all('/(\d+)\s+0\s+obj\s*<<(.*?)>>\s*stream\r?\n/s', $pdf, $m, PREG_OFFSET_CAPTURE)) return [[], []];
    foreach ($m[0] as $i => $whole) {
        $dict = $m[2][$i][0];
        $start = $whole[1] + strlen($whole[0]);
        $end = strpos($pdf, 'endstream', $start);
        if ($end === false) continue;
        $data = rtrim(substr($pdf, $start, $end - $start), "\r\n");
        if (strpos($dict, '/DCTDecode') !== false && strpos($dict, '/Image') !== false) {
            if (preg_match('/\/Width\s+(\d+)/', $dict, $w) && (int)$w[1] >= 200) $images[] = $data;
            continue;
        }
        if (strpos($dict, '/Subtype') !== false) continue;   // fontes, imagens noutros formatos
        if (strpos($dict, '/FlateDecode') !== false) { $data = @gzuncompress($data); if ($data === false) continue; }
        if (!preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*Tj|\[(?:[^\]]*)\]\s*TJ/s', $data, $tm)) continue;
        $page = '';
        foreach ($tm[0] as $op) {
            preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/s', $op, $parts);
            $page .= implode('', array_map(fn($s) => stripcslashes($s), $parts[1])) . (str_ends_with(trim($op), 'TJ') ? "\n" : ' ');
        }
        $page = trim(preg_replace('/[ \t]+/', ' ', $page));
        if ($page !== '' && mb_check_encoding($page, 'UTF-8')) $texts[] = $page;
        elseif ($page !== '') $texts[] = trim((string)@mb_convert_encoding($page, 'UTF-8', 'Windows-1252'));
    }
    return [$images, array_values(array_filter($texts))];
}

/** Um pedaço da importação (≈15 s). Se ficar por fazer, volta à fila com a posição seguinte. */
function cc_import_run(PDO $pdo, array $job): void {
    $p = json_decode((string)$job['payload'], true);
    $board = (int)$job['board_id'];
    if (!is_array($p) || !$board || !cc_board_get($pdo, $board)) throw new RuntimeException('Importação inválida.');
    $by = (int)($p['by'] ?? 0) ?: null;
    $pos = (int)$p['pos'];
    $cell = (int)$p['cell'];
    $end = microtime(true) + 15;
    $bytes = cc_store_get((string)$p['key'], CC_IMPORT_MAX);
    $more = false;
    switch ($p['kind']) {
        case 'image':
            cc_import_asset($pdo, $board, $bytes, (string)$p['name'], $cell, (int)$by);
            break;
        case 'md':
            $blocks = cc_md_blocks($bytes);
            for ($i = $pos; $i < count($blocks); $i++) {
                if (microtime(true) > $end) { $more = true; break; }
                [$t, $v] = $blocks[$i];
                if ($t === 'note') cc_import_note($pdo, $board, $v, $cell, (int)$by);
                elseif (preg_match('~^https?://~i', $v) && ($r = cc_fetch($v, 41943040, 15)) && str_starts_with($r[1], 'image/')) {
                    cc_import_asset($pdo, $board, $r[0], basename((string)parse_url($v, PHP_URL_PATH)) ?: 'imagem.jpg', $cell, (int)$by);
                }
            }
            $pos = $i;
            break;
        case 'pdf':
            [$imgs, $texts] = cc_pdf_extract($bytes);
            $items = array_merge(array_map(fn($t) => ['note', $t], $texts), array_map(fn($b) => ['img', $b], $imgs));
            for ($i = $pos; $i < count($items); $i++) {
                if (microtime(true) > $end) { $more = true; break; }
                if ($items[$i][0] === 'note') cc_import_note($pdo, $board, $items[$i][1], $cell, (int)$by);
                else cc_import_asset($pdo, $board, $items[$i][1], 'pdf_' . ($i + 1) . '.jpg', $cell, (int)$by);
            }
            $pos = $i;
            if (!$more && $pos >= count($items)) {   // o próprio PDF fica no Topperboard, como ficheiro
                cc_import_asset($pdo, $board, $bytes, (string)$p['name'], $cell, (int)$by);
            }
            break;
        case 'zip':
            $tmp = tempnam(sys_get_temp_dir(), 'ccz');
            file_put_contents($tmp, $bytes);
            unset($bytes);
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) { @unlink($tmp); throw new RuntimeException('O ZIP não abre.'); }
            // ordem estável: Markdown primeiro (as imagens que refere vão junto), depois o resto por nome
            $names = [];
            for ($k = 0; $k < $zip->numFiles; $k++) {
                $n = (string)$zip->getNameIndex($k);
                if ($n === '' || str_ends_with($n, '/') || preg_match('~(^|/)(__MACOSX|\.)~', $n)) continue;
                $names[] = $n;
            }
            usort($names, fn($a, $b) => [!preg_match('/\.(md|markdown|txt)$/i', $a), $a] <=> [!preg_match('/\.(md|markdown|txt)$/i', $b), $b]);
            $used = array_flip((array)($p['used'] ?? []));
            for ($i = $pos; $i < count($names); $i++) {
                if (microtime(true) > $end) { $more = true; break; }
                $n = $names[$i];
                if (isset($used[$n])) continue;
                $st = $zip->statName($n);
                if (!$st || $st['size'] > 209715200) continue;
                $data = (string)$zip->getFromName($n);
                if (preg_match('/\.(md|markdown|txt)$/i', $n)) {
                    foreach (cc_md_blocks($data) as [$t, $v]) {
                        if ($t === 'note') { cc_import_note($pdo, $board, $v, $cell, (int)$by); continue; }
                        $rel = ltrim(dirname($n) === '.' ? $v : dirname($n) . '/' . $v, './');
                        $img = $zip->getFromName($rel);
                        if ($img !== false) { cc_import_asset($pdo, $board, $img, basename($rel), $cell, (int)$by); $used[$rel] = true; }
                    }
                    continue;
                }
                $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'm4v', 'webm', 'pdf'], true)) {
                    cc_import_asset($pdo, $board, $data, basename($n), $cell, (int)$by);
                }
            }
            $pos = $i;
            $p['used'] = array_keys($used);
            $zip->close();
            @unlink($tmp);
            break;
    }
    if ($more) {
        $p['pos'] = $pos;
        $p['cell'] = $cell;
        cc_job_add($pdo, 'import', null, $board, $p, $by);
    } else {
        // o ficheiro importado fica guardado no Drive, em «Empresa/Importações/AAAA-MM/»
        $kept = false;
        if (strncmp((string)$p['key'], 'r2:', 3) === 0 && r2_config()) {
            try {
                require_once __DIR__ . '/files.php';
                $to = files_adopt($pdo, substr((string)$p['key'], 3), ['prefix' => FILES_IMPORTS . date('Y-m') . '/', 'name' => date('Y-m-d') . '_Centro-Criativo_' . (string)$p['name'],
                                                                        'origin' => 'import', 'label' => 'Importação para o Topperboard «' . (string)(cc_board_get($pdo, $board)['title'] ?? '') . '»', 'by' => $by]);
                $kept = $to !== substr((string)$p['key'], 3);
            } catch (Throwable $e) {
                error_log('cc_import keep: ' . $e->getMessage());
            }
        }
        if (!$kept) cc_store_delete((string)$p['key']);
    }
    cc_board_touch($pdo, $board);
}
