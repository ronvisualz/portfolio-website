<?php
/**
 * topper. admin — o separador «Drive», agora no Cloudflare R2.
 *
 * Ajudas de apresentação e a estrutura de pastas. As operações S3 estão
 * em r2.php; os ficheiros vão do browser diretamente para o R2.
 */

declare(strict_types=1);

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/r2.php';

/**
 * As pastas de topo (aparecem sempre na raiz, mesmo sem marcador). Não se movem nem vão para o Lixo.
 * Leads, Marketing, Biblioteca criativa e Arquivo são as do registo de ficheiros (inc/files.php).
 */
const DRIVE_ROOTS = ['Empresa/', 'Leads/', 'Projetos/', 'Marketing/', 'Biblioteca criativa/', 'Showreel e Portfolio/', 'Arquivo/'];

/** O prefixo de sistema: Lixo e miniaturas. Nunca aparece no Drive nem é alvo de ações. */
const DRIVE_SYS    = '.topper/';
const DRIVE_TRASH  = DRIVE_SYS . 'lixo/';     // .topper/lixo/<id>/<chave original>
const DRIVE_THUMBS = DRIVE_SYS . 'thumbs/';   // .topper/thumbs/<etag>.jpg
const DRIVE_ETAG_RE = '/^[a-f0-9]{32}(-\d{1,5})?$/';
const DRIVE_CATALOG_TTL = 30;                 // segundos
const DRIVE_PROJECT_SUBS = [];   // por agora sem subpastas: os ficheiros ficam soltos na pasta do projeto

/** drive.php?p=<prefixo> (com extras já codificados, «&a=b»). */
function drive_page(string $p, string $extra = ''): string {
    $q = $p !== '' ? 'p=' . rawurlencode($p) : '';
    $extra = ltrim($extra, '&');
    if ($extra !== '') $q .= ($q !== '' ? '&' : '') . $extra;
    return '/admin/drive.php' . ($q !== '' ? '?' . $q : '');
}

/** O prefixo da pasta de um trabalho: Projetos/<ano>/<nome>/. */
/** Nomes das pastas de projeto já usados pela equipa (trabalho na folha → nome da pasta). */
const DRIVE_PROJECT_ALIASES = [
    'Wuant The One'   => 'THE_ONE',
    'Delta Y (Video)' => 'DELTAY',
    'Casino Solverde' => 'SOLVERDE',
    'LING'            => 'WIZZY_TEYKAN_DISTANTE',
    'Richie Campbell' => 'RICHIE_LOYAL',
];

/** «Casino Solverde» → «CASINO_SOLVERDE». */
function drive_slug(string $s): string {
    $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $t = strtoupper((string)($t === false ? $s : $t));
    return trim(preg_replace('/[^A-Z0-9]+/', '_', $t), '_') ?: 'PROJETO';
}

/** «03_SOLVERDE_PROJECT» → «SOLVERDE». */
function drive_project_core(string $folder): string {
    $f = rtrim($folder, '/');
    $f = preg_replace('/^\d+_/', '', $f);
    return preg_replace('/_(PROJECT|PROJETO)$/', '', $f);
}

/**
 * Pasta do trabalho: «Projetos/NN_NOME_PROJECT/», numerada pela ordem de criação.
 * Se já existir uma pasta para este trabalho (pelo nome ou pelo alias), usa essa.
 */
function drive_project_prefix(string $name, int $year): string {
    $core = DRIVE_PROJECT_ALIASES[$name] ?? drive_slug($name);
    $max = 0;
    foreach (r2_list('Projetos/')['folders'] ?? [] as $f) {
        $leaf = substr(rtrim($f, '/'), strlen('Projetos/'));
        if (drive_project_core($leaf) === $core) return 'Projetos/' . $leaf . '/';
        if (preg_match('/^(\d+)_/', $leaf, $m)) $max = max($max, (int)$m[1]);
    }
    return 'Projetos/' . sprintf('%02d', $max + 1) . '_' . $core . '_PROJECT/';
}

/** Cria (se faltar) a pasta do trabalho e as subpastas. Devolve o prefixo. */
function drive_project(string $name, int $year): string {
    $p = drive_project_prefix($name, $year);
    r2_mkdir_p($p);
    foreach (DRIVE_PROJECT_SUBS as $s) {
        if (r2_head($p . $s . '/') === null) r2_mkdir($p . $s . '/');
    }
    drive_catalog_invalidate();   // o Drive abre logo a seguir nesta pasta
    return $p;
}

/** «1,2 MB». */
function drive_size(int $b): string {
    if ($b <= 0) return $b === 0 ? '0 B' : '—';
    $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; $n = (float)$b;
    while ($n >= 1024 && $i < 4) { $n /= 1024; $i++; }
    return ($i === 0 ? (string)$b : number_format($n, $n < 10 ? 1 : 0, ',', ' ')) . ' ' . $u[$i];
}

/** [etiqueta, ícone, tipo de pré-visualização (video|image|pdf|audio|'')] pelo nome. */
function drive_kind(string $name, bool $folder): array {
    if ($folder) return ['Pasta', '▣', ''];
    $mime = r2_mime($name);
    if ($mime === 'application/pdf') return ['PDF', '▤', 'pdf'];
    if ($mime === 'application/zip') return ['ZIP', '◫', ''];
    if (in_array($mime, ['video/mp4', 'video/webm', 'video/quicktime'], true)) return ['Vídeo', '▶', 'video'];
    if (strpos($mime, 'video/') === 0) return ['Vídeo', '▶', ''];
    if ($mime === 'image/vnd.adobe.photoshop') return ['Imagem', '◩', ''];
    if (strpos($mime, 'image/') === 0) return ['Imagem', '◩', 'image'];
    if (strpos($mime, 'audio/') === 0) return ['Áudio', '♪', 'audio'];
    if (strpos($mime, 'text/') === 0 || strpos($mime, 'word') !== false) return ['Documento', '≡', ''];
    if (strpos($mime, 'sheet') !== false) return ['Folha', '▦', ''];
    if (strpos($mime, 'presentation') !== false) return ['Slides', '▭', ''];
    return ['Ficheiro', '□', ''];
}

/** A política CORS a colar no bucket. */
function drive_cors_json(): string {
    return json_encode([[
        'AllowedOrigins' => ['https://topperdigital.xyz'],
        'AllowedMethods' => ['GET', 'PUT', 'POST', 'DELETE', 'HEAD'],
        'AllowedHeaders' => ['*'],
        'ExposeHeaders'  => ['ETag', 'Content-Range'],   // ETag: envios multipart e miniaturas; Content-Range: retomar descargas do ZIP
        'MaxAgeSeconds'  => 3600,
    ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

// ── sistema, pastas base e catálogo ─────────────────────────

/**
 * Documentos de faturação: qualquer pasta «Faturação» (ou as antigas «Faturas») abaixo do
 * primeiro nível — a da pasta de cada negócio, também no Arquivo e nas cópias. Só quem vê o
 * Financeiro os vê no Drive, no assistente e nas fichas.
 */
function drive_billing(string $k): bool {
    $seg = explode('/', $k);
    array_shift($seg);
    if (substr($k, -1) !== '/') array_pop($seg);   // o nome do ficheiro não conta
    foreach ($seg as $s) {
        if ($s === '') continue;
        $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($s), 'UTF-8'));
        $t = preg_replace('/[^a-z]/', '', (string)($t === false ? $s : $t));
        if ($t === 'faturacao' || $t === 'faturas' || $t === 'facturacao' || $t === 'facturas') return true;
    }
    return false;
}

/** Esta conta pode ver esta chave? (a faturação pede a aba Financeiro) */
function drive_can_see(string $k): bool {
    return !drive_billing($k) || (function_exists('can') && can('financeiro'));
}

/** Debaixo de .topper/ (ou a própria «.topper»)? */
function drive_hidden(string $k): bool {
    return strncmp($k, DRIVE_SYS, strlen(DRIVE_SYS)) === 0 || $k === rtrim(DRIVE_SYS, '/');
}

/** Uma das pastas base (Empresa/, Projetos/, Showreel e Portfolio/). */
function drive_is_root(string $k): bool {
    return in_array($k, DRIVE_ROOTS, true);
}

/** «a/b/c.pdf» → «a/b/»; «a/b/» → «a/»; «a» → «». */
function drive_parent(string $k): string {
    $t = rtrim($k, '/');
    $p = strrpos($t, '/');
    return $p === false ? '' : substr($t, 0, $p + 1);
}

/** «a/b/c.pdf» → «c.pdf»; «a/b/» → «b»; «» → «Drive». */
function drive_base(string $k): string {
    $t = rtrim($k, '/');
    if ($t === '') return 'Drive';
    $p = strrpos($t, '/');
    return $p === false ? $t : substr($t, $p + 1);
}

/** Há alguma coisa com este prefixo (o marcador ou um ficheiro lá dentro)? As pastas base existem sempre. */
function drive_folder_exists(string $prefix): bool {
    if (drive_is_root($prefix)) return true;
    $x = r2_xml(r2_expect(r2_request('GET', '', ['list-type' => '2', 'prefix' => $prefix, 'max-keys' => '1']), 'Listar a pasta')['body']);
    return count($x->Contents) > 0;
}

/** Existe? (ficheiro: HEAD; pasta: prefixo com conteúdo ou pasta base) */
function drive_exists(string $k): bool {
    return substr($k, -1) === '/' ? drive_folder_exists($k) : r2_head($k) !== null;
}

function drive_catalog_file(): ?string {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) return null;
        // inc/.htaccess já recusa tudo aqui dentro; isto é uma segunda tranca.
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return is_writable($dir) ? $dir . '/drive_catalog.json' : null;
}

/** Esquece o catálogo — a próxima leitura vai ao R2. Chamar depois de qualquer alteração. */
function drive_catalog_invalidate(): void {
    $GLOBALS['__drive_cat'] = null;
    $f = drive_catalog_file();
    if ($f !== null && is_file($f)) @unlink($f);
}

/**
 * Tudo o que está no Drive, de uma só listagem do bucket (com cache de 30 s em disco):
 * ['objects' => [['key','size','modified','etag']…] (sem .topper/, inclui marcadores «x/»),
 *  'thumbs' => [etag => true], 'listed_at' => unix, 'truncated' => bool]
 */
function drive_catalog(bool $fresh = false): array {
    if (!$fresh && ($c = drive_catalog_cached()) !== null) return $c;
    $file = drive_catalog_file();
    $pages = 200;
    $all = r2_list_all('', $pages);
    $objects = []; $thumbs = [];
    $tl = strlen(DRIVE_THUMBS);
    foreach ($all as $o) {
        $k = $o['key'];
        if (strncmp($k, DRIVE_THUMBS, $tl) === 0) {
            $e = substr($k, $tl, -4);
            if (substr($k, -4) === '.jpg' && preg_match(DRIVE_ETAG_RE, $e)) $thumbs[$e] = true;
            continue;
        }
        // O sistema fica de fora; chaves que não são UTF-8 (postas por outras ferramentas) também.
        if (drive_hidden($k) || !mb_check_encoding($k, 'UTF-8')) continue;
        $objects[] = $o;
    }
    $c = ['objects' => $objects, 'thumbs' => $thumbs, 'listed_at' => time(), 'truncated' => count($all) >= $pages * 1000];
    if ($file !== null) {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            @chmod($tmp, 0600);
            if (!@rename($tmp, $file)) @unlink($tmp);
        }
    }
    return $GLOBALS['__drive_cat'] = $c;
}

/** O catálogo se ainda estiver válido (memória ou disco), sem ir ao R2; senão null. */
function drive_catalog_cached(): ?array {
    if (!empty($GLOBALS['__drive_cat'])) return $GLOBALS['__drive_cat'];
    $file = drive_catalog_file();
    if ($file === null || !is_file($file)) return null;
    $c = json_decode((string)@file_get_contents($file), true);
    $age = is_array($c) ? time() - (int)($c['listed_at'] ?? 0) : -1;
    if (!is_array($c) || !isset($c['objects']) || !is_array($c['objects']) || $age < 0 || $age >= DRIVE_CATALOG_TTL) return null;
    $c['thumbs'] = is_array($c['thumbs'] ?? null) ? $c['thumbs'] : [];
    return $GLOBALS['__drive_cat'] = $c;
}

/**
 * drive_folder_stats() de um prefixo sem listar o bucket inteiro: usa o catálogo se
 * estiver em cache, senão lista só esse prefixo.
 */
function drive_prefix_stats(string $prefix): array {
    $cat = drive_catalog_cached() ?? ['objects' => r2_list_all($prefix, 100)];
    return drive_folder_stats($cat, $prefix);
}

/** Ficheiros (sem marcadores) e tamanho total debaixo de um prefixo, pelo catálogo. */
function drive_folder_stats(array $cat, string $prefix): array {
    $files = 0; $size = 0; $mod = 0; $folders = 0; $longest = 0;
    $l = strlen($prefix);
    foreach ($cat['objects'] as $o) {
        if (strncmp($o['key'], $prefix, $l) !== 0) continue;
        $longest = max($longest, strlen($o['key']));
        if (substr($o['key'], -1) === '/') { if ($o['key'] !== $prefix) $folders++; continue; }
        $files++; $size += (int)$o['size']; $mod = max($mod, (int)$o['modified']);
    }
    return ['files' => $files, 'size' => $size, 'folders' => $folders, 'modified' => $mod, 'longest' => $longest];
}

/** Um objeto do catálogo pela chave, ou null. */
function drive_catalog_get(array $cat, string $key): ?array {
    foreach ($cat['objects'] as $o) if ($o['key'] === $key) return $o;
    return null;
}

/**
 * Um nome livre ao lado de $key: «nome (2).ext», «nome (3).ext»…, «Pasta (2)/».
 * Um nome que já acabe em « (n)» continua a contar a partir daí.
 */
function drive_unique_key(string $key, callable $exists): string {
    if (!$exists($key)) return $key;
    $folder = substr($key, -1) === '/';
    $body = $folder ? substr($key, 0, -1) : $key;
    $p = strrpos($body, '/');
    $dir = $p === false ? '' : substr($body, 0, $p + 1);
    $name = $p === false ? $body : substr($body, $p + 1);
    $ext = '';
    if (!$folder) {
        $d = strrpos($name, '.');
        if ($d !== false && $d > 0) { $ext = substr($name, $d); $name = substr($name, 0, $d); }
    }
    $n = 2;
    if (preg_match('/^(.*\S) \((\d{1,6})\)$/u', $name, $m)) { $name = $m[1]; $n = (int)$m[2] + 1; }
    for ($i = 0; $i < 500; $i++, $n++) {
        $cand = $dir . $name . ' (' . $n . ')' . $ext . ($folder ? '/' : '');
        if (strlen($cand) > R2_KEY_MAX) break;
        if (!$exists($cand)) return $cand;
    }
    throw new R2Exception('Não foi possível arranjar um nome livre para «' . drive_base($key) . '».');
}

// ── mover / copiar objetos (o motor de move, copy, trash e restore) ──

/**
 * Passa um objeto para $dst. Copia (salta se o destino já é igual: mesmo tamanho e
 * ETag), confirma com HEAD que o destino tem o mesmo tamanho e só então, se $move,
 * apaga a origem. Os marcadores de pasta («x/», 0 bytes) recriam-se no destino.
 */
function drive_put_one(array $o, string $dst, bool $move, bool $check): void {
    // Já está no destino: não há nada a copiar e, sobretudo, nada a apagar.
    if ($o['key'] === $dst) return;
    if (strlen($dst) > R2_KEY_MAX) throw new R2Exception('O caminho de destino fica comprido demais («' . drive_base($o['key']) . '»).');
    if (substr($o['key'], -1) === '/' && (int)$o['size'] === 0) {
        r2_expect(r2_request('PUT', $dst, [], '', ['content-type' => 'application/x-directory']), 'Criar a pasta');
        if ($move) r2_delete($o['key']);
        return;
    }
    $h = null;
    if ($check) {
        $h = r2_head($dst);
        if ($h !== null && !drive_same_object($h, $o)) $h = null;
    }
    if ($h === null) {
        r2_copy_any($o['key'], $dst, (int)$o['size']);
        $h = r2_head($dst);
    }
    if ($h === null || !drive_copy_ok($h, $o)) {
        throw new R2Exception('A cópia de «' . drive_base($o['key']) . '» não ficou completa. O original não foi apagado.');
    }
    if ($move) r2_delete($o['key']);
}

/** O destino já é este objeto (mesmo tamanho e mesmo ETag)? */
function drive_same_object(array $h, array $o): bool {
    return $h['size'] === (int)$o['size'] && $h['etag'] !== '' && $h['etag'] === strtolower((string)($o['etag'] ?? ''));
}

/**
 * A cópia confere com a origem? O tamanho tem de bater; quando os dois ETags são MD5 simples
 * (objetos enviados de uma vez, copiados com CopyObject) também o ETag — assim uma origem que
 * mudou entretanto não passa por cópia certa. ETags multipart («…-N») não se comparam: o R2
 * pode dar outro à cópia.
 */
function drive_copy_ok(array $h, array $o): bool {
    if ($h['size'] !== (int)$o['size']) return false;
    $a = strtolower((string)($o['etag'] ?? '')); $b = $h['etag'];
    if (preg_match('/^[a-f0-9]{32}$/', $a) && preg_match('/^[a-f0-9]{32}$/', $b)) return $a === $b;
    return true;
}

const DRIVE_PAR = 16;   // objetos tratados ao mesmo tempo nas operações em lote (pedidos ao R2 em paralelo)

/**
 * O mesmo que drive_put_one para vários objetos de uma vez: [[objeto, destino], …].
 * As cópias e as confirmações (HEAD) vão em paralelo; no fim, se $move, as origens
 * confirmadas apagam-se num só DeleteObjects. Uma origem só se apaga com a cópia
 * confirmada. Devolve [i => null (feito) | mensagem de erro (a origem ficou)].
 * Lança só se o R2 falhar ao apagar as origens (as cópias já estão confirmadas).
 */
function drive_put_many(array $jobs, bool $move, bool $check): array {
    $c = r2_config();
    if (!$c) throw new R2Exception('O armazenamento R2 não está configurado.');
    $err = []; $markers = []; $copy = []; $del = [];
    foreach ($jobs as $i => [$o, $dst]) {
        $err[$i] = null;
        if ($o['key'] === $dst) continue;   // já está lá
        if (strlen($dst) > R2_KEY_MAX) { $err[$i] = 'O caminho de destino fica comprido demais («' . drive_base($o['key']) . '»).'; continue; }
        if (substr($o['key'], -1) === '/' && (int)$o['size'] === 0) $markers[$i] = $dst;
        elseif ((int)$o['size'] > 5368709120) {
            // acima de 5 GiB: cópia em partes, uma de cada vez (drive_put_one já apaga a origem)
            try { drive_put_one($o, $dst, $move, $check); } catch (R2Exception $e) { $err[$i] = $e->getMessage(); }
        } else $copy[$i] = $dst;
    }
    // marcadores de pasta: recriam-se no destino
    $reqs = [];
    foreach ($markers as $i => $dst) $reqs[$i] = ['PUT', $dst, [], '', ['content-type' => 'application/x-directory']];
    foreach (r2_request_many($reqs) as $i => $r) {
        if (isset($r['error']) || $r['code'] < 200 || $r['code'] >= 300) $err[$i] = 'Criar a pasta: ' . ($r['error'] ?? 'o R2 devolveu um erro (HTTP ' . $r['code'] . ').');
        elseif ($move) $del[] = $jobs[$i][0]['key'];
    }
    // continuações e fusões: o que já está igual no destino não se copia outra vez
    $have = [];
    if ($check && $copy) {
        foreach (r2_head_many($copy) as $i => $h) if ($h !== null && drive_same_object($h, $jobs[$i][0])) $have[$i] = true;
    }
    $reqs = [];
    foreach ($copy as $i => $dst) {
        if (isset($have[$i])) continue;
        $reqs[$i] = ['PUT', $dst, [], '', ['x-amz-copy-source' => '/' . $c['bucket'] . '/' . r2_enc($jobs[$i][0]['key'], true)], 900];
    }
    $copied = [];
    foreach (r2_request_many($reqs) as $i => $r) {
        // muitos pedidos ao mesmo tempo: um 429/5xx ou uma ligação cortada tenta-se outra vez, sozinho
        $again = isset($r['error']) || $r['code'] === 429 || $r['code'] >= 500;
        try {
            if ($again) r2_copy_any($jobs[$i][0]['key'], $copy[$i], (int)$jobs[$i][0]['size']);
            elseif ($r['code'] === 404) throw new R2Exception('O ficheiro já não existe.');
            else r2_expect_copy($r, 'Copiar');
            $copied[$i] = $copy[$i];
        } catch (R2Exception $e) {
            $err[$i] = 'Não foi possível copiar «' . drive_base($jobs[$i][0]['key']) . '»: ' . $e->getMessage();
        }
    }
    // confirmar cada cópia antes de apagar a origem
    foreach ($copied ? r2_head_many($copied) : [] as $i => $h) {
        if ($h === null || !drive_copy_ok($h, $jobs[$i][0])) $err[$i] = 'A cópia de «' . drive_base($jobs[$i][0]['key']) . '» não ficou completa. O original não foi apagado.';
    }
    if ($move) {
        foreach ($copy as $i => $dst) if ($err[$i] === null) $del[] = $jobs[$i][0]['key'];
        if ($del) r2_delete_many($del);
    }
    return $err;
}

/**
 * Passa $from para $to (ficheiro → ficheiro ou «pasta/» → «pasta/»), objeto a objeto,
 * enquanto $more() deixar. $move apaga cada origem depois de confirmada a cópia.
 * $after (só cópias de pastas): a última chave relativa já feita — as continuações
 * seguem dali. $check: comparar com o destino antes de copiar (continuações e fusões).
 * Devolve ['done' => bool, 'after' => string, 'n' => ficheiros feitos neste pedido].
 * Um ficheiro de origem que não exista lança «O ficheiro já não existe.».
 */
function drive_transfer(string $from, string $to, bool $move, string $after, bool $check, callable $more): array {
    if (substr($from, -1) !== '/') {
        $h = r2_head($from);
        if ($h === null) throw new R2Exception('O ficheiro já não existe.', 404);
        $more();
        drive_put_one(['key' => $from] + $h, $to, $move, $check);
        return ['done' => true, 'after' => '', 'n' => 1];
    }
    if ($from === $to) return ['done' => true, 'after' => $after, 'n' => 0];   // nunca «para cima de si própria»
    $n = 0; $fl = strlen($from);
    while (true) {
        // Ao mover, o que já foi tratado desaparece da origem; ao copiar, segue-se depois de $after.
        $page = r2_list_all($from, 1, !$move && $after !== '' ? $from . $after : '');
        $todo = array_values(array_filter($page, static fn($o) => $o['key'] !== $from));
        $cnt = count($todo);
        for ($i = 0; $i < $cnt; ) {
            // um lote de até DRIVE_PAR objetos (cada um conta no orçamento do pedido)
            $batch = []; $stop = false;
            while ($i < $cnt && count($batch) < DRIVE_PAR) {
                if (!$more()) { $stop = true; break; }
                $batch[] = $todo[$i++];
            }
            if ($batch) {
                $errs = drive_put_many(array_map(static fn($o) => [$o, $to . substr($o['key'], $fl)], $batch), $move, $check);
                foreach ($batch as $j => $o) {
                    if ($errs[$j] !== null) throw new R2Exception($errs[$j]);
                    $after = substr($o['key'], $fl);
                    if (substr($o['key'], -1) !== '/') $n++;
                }
            }
            if ($stop) return ['done' => false, 'after' => $after, 'n' => $n];
        }
        if (count($page) < 1000 || !$todo) break;
    }
    if ($move) r2_delete($from);   // o marcador da própria pasta, no fim (não faz mal se não existir)
    return ['done' => true, 'after' => $after, 'n' => $n];
}

/**
 * Apaga de vez tudo debaixo de $prefix (Lixo), em lotes, enquanto $more($quantos) deixar.
 * Devolve ['done' => bool, 'n' => ficheiros apagados].
 */
function drive_purge_prefix(string $prefix, callable $more, int $batch = 1000): array {
    $n = 0;
    while (true) {
        $page = r2_list_all($prefix, 1);
        if (!$page) return ['done' => true, 'n' => $n];
        foreach (array_chunk($page, max(1, $batch)) as $chunk) {
            if (!$more()) return ['done' => false, 'n' => $n];
            r2_delete_many(array_column($chunk, 'key'));
            foreach ($chunk as $o) if (substr($o['key'], -1) !== '/') $n++;
        }
    }
}
