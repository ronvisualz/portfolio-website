<?php
/**
 * topper. admin — o registo de ficheiros: tudo no Drive, organizado (§10.15)
 *
 * Cada ficheiro que entra na plataforma ou é gerado por ela fica uma só vez no Drive,
 * no sítio que a sua ligação diz, e tem uma linha em «files» (chave, ETag, tamanho,
 * origem, secção, negócio/lead, peça, referência). As pastas:
 *
 *   Leads/<NOME>_<AAAA-MM>/                  uma lead ainda não convertida
 *   Projetos/<ID>_<NOME>/                     negócios em curso e concluídos
 *     Propostas/ Faturação/ Criativo/ Marketing/ Produção/ Entregas/ Review/ Lead/
 *   Marketing/<AAAA-MM>/<peça>/               peças sem negócio
 *   Biblioteca criativa/<quadro>/             referências do Toppernote sem negócio
 *   Empresa/Importações/<AAAA-MM>/            os ficheiros importados (CSV, ZIP…)
 *   Arquivo/Negócios/<ID>_<NOME>/             negócios perdidos (a pasta inteira)
 *   Arquivo/Leads/<NOME>_<AAAA-MM>/           leads arquivadas
 *
 * As subpastas só nascem com o primeiro ficheiro. Quando o registo muda de fase (lead
 * convertida, arquivada, negócio perdido, reaberto), a pasta muda com ele: deals.drive_folder
 * passa logo para o destino (o que chega a seguir já vai para lá) e a cópia corre na fila
 * file_moves — um pouco no próprio pedido, o resto no cron. No fim, drive_store_moved()
 * (favoritos, recentes, partilhas, índice) chama files_moved(), que atualiza tudo o que
 * guarda caminhos: este registo, o Toppernote, a faturação, as peças e os negócios.
 * O mesmo acontece quando alguém move uma pasta à mão no Drive.
 *
 * Um ficheiro que já não está onde o registo diz volta a encontrar-se (files_locate): pela
 * mudança de pasta em curso, pela impressão digital (ETag e tamanho) no catálogo, ou no Lixo.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/drive.php';

const FILES_LEADS      = 'Leads/';
const FILES_DEALS      = 'Projetos/';
const FILES_ARCH_LEADS = 'Arquivo/Leads/';
const FILES_ARCH_DEALS = 'Arquivo/Negócios/';
const FILES_LIBRARY    = 'Biblioteca criativa/';
const FILES_IMPORTS    = 'Empresa/Importações/';
const FILES_PUT_MAX    = 5368709120;   // um PUT do S3: até 5 GB (acima disso, pelo Drive)

/** As secções da pasta de um negócio: chave => [pasta, rótulo]. «» = a raiz da pasta. */
const FILES_SECTIONS = [
    'propostas' => ['Propostas', 'Propostas'],
    'faturacao' => ['Faturação', 'Faturação'],
    'criativo'  => ['Criativo', 'Criativo'],
    'marketing' => ['Marketing', 'Marketing'],
    'producao'  => ['Produção', 'Produção'],
    'entregas'  => ['Entregas', 'Entregas'],
    'review'    => ['Review', 'Review'],
    'lead'      => ['Lead', 'Da lead'],
    ''          => ['', 'Outros'],
];

/** As origens: chave => rótulo. */
const FILES_ORIGINS = [
    'proposal'  => 'Proposta gerada',
    'invoice'   => 'Fatura emitida',
    'receipt'   => 'Comprovativo',
    'cc'        => 'Toppernote',
    'cc_page'   => 'Pré-criativo',
    'treatment' => 'Tratamento',
    'attach'    => 'Anexado',
    'marketing' => 'Marketing',
    'import'    => 'Importação',
    'review'    => 'Versão de review',
    'drive'     => 'Posto no Drive',
];

// ── estado ──────────────────────────────────────────────────

/** O registo existe depois do passo 25 da base. */
function files_ready(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (int)$pdo->query("SELECT svalue FROM settings WHERE skey = 'schema_version'")->fetchColumn() >= 25;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** O Drive está ligado (há onde guardar)? */
function files_on(): bool {
    return r2_config() !== null;
}

function files_now(): string {
    return date('Y-m-d H:i:s');
}

function files_hash(string $key): string {
    return hash('sha256', $key);
}

/** «a!%b_c» para LIKE … ESCAPE '!' (o mesmo nas duas bases). */
function files_like(string $s): string {
    return strtr($s, ['!' => '!!', '%' => '!%', '_' => '!_']);
}

/** «Casino Solverde» → «CASINO_SOLVERDE» (até $max caracteres). */
function files_slug(string $s, int $max = 60): string {
    return rtrim(substr(drive_slug($s), 0, $max), '_') ?: 'SEM_NOME';
}

/** Um nome de ficheiro com o padrão da casa: «ID2610-03_Proposta_v2_2026-10-23.pdf». */
function files_name(string $code, string $type, string $desc, string $ext): string {
    $parts = array_filter([preg_replace('/[^A-Za-z0-9-]/', '', $code), $type, trim(preg_replace('/[^\pL\pN.-]+/u', '-', $desc), '-')], fn($x) => $x !== '');
    return r2_clean_name(implode('_', $parts) . '.' . ltrim($ext, '.'));
}

// ── a pasta de cada registo ─────────────────────────────────

function files_deal_row(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM deals WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Onde a pasta deve estar, pela fase: Leads/, Arquivo/Leads/, Projetos/ ou Arquivo/Negócios/. */
function files_place(array $d): string {
    return match ((string)$d['phase']) {
        'lead'     => FILES_LEADS,
        'archived' => FILES_ARCH_LEADS,
        'lost'     => FILES_ARCH_DEALS,
        default    => (string)$d['code'] !== '' ? FILES_DEALS : FILES_LEADS,
    };
}

/** O nome da pasta: «ID2610-03_CASINO_SOLVERDE» (negócio) ou «CASINO_SOLVERDE_2026-10» (lead). */
function files_leaf(array $d): string {
    if ((string)$d['code'] !== '' && !in_array($d['phase'], ['lead', 'archived'], true)) {
        return $d['code'] . '_' . files_slug((string)$d['name']);
    }
    $name = trim((string)($d['company'] ?? '')) ?: (trim((string)($d['person'] ?? '')) ?: (string)$d['name']);
    $ym = substr((string)($d['created_at'] ?? ''), 0, 7);
    return files_slug($name) . (preg_match('/^\d{4}-\d{2}$/', $ym) ? '_' . $ym : '');
}

/** As quatro raízes onde vivem as pastas dos registos (a mais comprida primeiro). */
function files_record_roots(): array {
    return [FILES_ARCH_DEALS, FILES_ARCH_LEADS, FILES_DEALS, FILES_LEADS];
}

/** [raiz, resto] de uma pasta de registo, ou null se estiver noutro sítio. */
function files_split(string $folder): ?array {
    foreach (files_record_roots() as $r) {
        if (strncmp($folder, $r, strlen($r)) === 0 && strlen($folder) > strlen($r)) return [$r, substr($folder, strlen($r))];
    }
    return null;
}

/**
 * A pasta do negócio ou da lead no Drive (com «/» no fim). Cria-a com $create (e guarda a
 * ligação); sem $create devolve null se ainda não houver. Sem Drive ligado: null.
 * Uma pasta já ligada (deals.drive_folder — também as antigas NN_NOME_PROJECT) ganha sempre.
 */
function files_folder(PDO $pdo, array $deal, bool $create = true): ?string {
    if (!files_on()) return null;
    $d = files_deal_row($pdo, (int)$deal['id']) ?? $deal;
    $p = (string)$d['drive_folder'];
    $place = files_place($d);
    if ($p === '' && (string)$d['code'] !== '' && in_array($place, [FILES_DEALS, FILES_ARCH_DEALS], true)) {
        // uma pasta do negócio que já exista pelo ID (criada à mão, ou antes do registo)
        foreach (r2_list($place)['folders'] ?? [] as $f) {
            $leaf = substr(rtrim($f, '/'), strlen($place));
            if (str_starts_with($leaf, $d['code'] . '_') || $leaf === $d['code']) { $p = $place . $leaf . '/'; break; }
        }
    }
    if ($p === '') {
        if (!$create) return null;
        $p = drive_unique_key($place . files_leaf($d) . '/', 'drive_folder_exists');
    }
    if ($create && !drive_folder_exists($p)) {
        r2_mkdir_p($p);
        drive_catalog_invalidate();
    }
    if ($p !== (string)$d['drive_folder'] && ($create || (string)$d['drive_folder'] === '')) {
        $pdo->prepare('UPDATE deals SET drive_folder = ? WHERE id = ?')->execute([$p, (int)$d['id']]);
    }
    return $p;
}

/** A pasta de uma secção dentro da pasta do registo («…/Propostas/»); a raiz para «». */
function files_section_folder(string $folder, string $section): string {
    $dir = FILES_SECTIONS[$section][0] ?? '';
    return $dir === '' ? $folder : $folder . $dir . '/';
}

/** A secção de uma chave dentro da pasta do registo, pelo primeiro nível (Faturas antigas contam como Faturação). */
function files_section_of(string $folder, string $key): string {
    if ($folder === '' || strncmp($key, $folder, strlen($folder)) !== 0) return '';
    $rest = substr($key, strlen($folder));
    $p = strpos($rest, '/');
    if ($p === false) return '';
    $seg = files_fold(substr($rest, 0, $p));
    if ($seg === 'faturas') return 'faturacao';
    foreach (FILES_SECTIONS as $k => [$dir]) if ($dir !== '' && files_fold($dir) === $seg) return $k;
    return '';
}

/** Minúsculas e sem acentos («Faturação» → «faturacao»). */
function files_fold(string $s): string {
    $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($s), 'UTF-8'));
    return preg_replace('/[^a-z0-9 ]/', '', (string)($t === false ? $s : $t));
}

// ── gravar e registar ───────────────────────────────────────

/**
 * Regista (ou atualiza) um ficheiro pela chave. $o: etag, size, mime, origin, section, label,
 * deal_id, mkt_piece_id, cc_asset_id, by. Devolve o id.
 */
function files_register(PDO $pdo, string $key, array $o): int {
    if (!files_ready($pdo)) return 0;
    $h = files_hash($key);
    $now = files_now();
    $sec = (string)($o['section'] ?? '');
    $cols = ['etag' => strtolower(trim((string)($o['etag'] ?? ''), '"')), 'size' => (int)($o['size'] ?? 0), 'mime' => mb_substr((string)($o['mime'] ?? r2_mime($key)), 0, 100),
             'origin' => isset(FILES_ORIGINS[$o['origin'] ?? '']) ? $o['origin'] : 'attach', 'section' => isset(FILES_SECTIONS[$sec]) ? $sec : '',
             'label' => mb_substr((string)($o['label'] ?? ''), 0, 200),
             'deal_id' => !empty($o['deal_id']) ? (int)$o['deal_id'] : null, 'mkt_piece_id' => !empty($o['mkt_piece_id']) ? (int)$o['mkt_piece_id'] : null,
             'cc_asset_id' => !empty($o['cc_asset_id']) ? (int)$o['cc_asset_id'] : null];
    $st = $pdo->prepare('SELECT id FROM files WHERE key_hash = ?');
    $st->execute([$h]);
    if ($id = (int)$st->fetchColumn()) {
        $set = implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($cols)));
        $pdo->prepare('UPDATE files SET ' . $set . ", status = 'ok', updated_at = ? WHERE id = ?")->execute([...array_values($cols), $now, $id]);
        return $id;
    }
    $pdo->prepare('INSERT INTO files (obj_key, key_hash, ' . implode(', ', array_keys($cols)) . ', status, created_by, created_at, updated_at)
                   VALUES (?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ", 'ok', ?, ?, ?)")
        ->execute([$key, $h, ...array_values($cols), $o['by'] ?? null, $now, $now]);
    return (int)$pdo->lastInsertId();
}

/** A pasta de destino de um ficheiro: $o['prefix'] (explícita) ou a secção da pasta do registo. */
function files_target_folder(PDO $pdo, array $o): ?string {
    if (!empty($o['prefix'])) return (string)$o['prefix'];
    if (empty($o['deal_id'])) return null;
    $d = files_deal_row($pdo, (int)$o['deal_id']);
    if (!$d) return null;
    $f = files_folder($pdo, $d);
    return $f === null ? null : files_section_folder($f, (string)($o['section'] ?? ''));
}

/** Um nome livre nesta pasta («nome (2).pdf»), a menos que $overwrite. */
function files_free_key(string $folder, string $name, bool $overwrite = false): string {
    $key = $folder . (r2_clean_name($name) ?: 'ficheiro');
    return $overwrite ? $key : drive_unique_key($key, fn(string $k): bool => r2_head($k) !== null);
}

/**
 * Grava bytes no Drive e regista-os. $o: deal_id + section, ou prefix; name; mime; origin;
 * label; by; overwrite; mkt_piece_id; cc_asset_id. Devolve ['id', 'key', 'name'] ou null
 * (sem Drive ligado, ou sem pasta).
 */
function files_put(PDO $pdo, array $o, string $bytes): ?array {
    if (!files_on()) return null;
    $folder = files_target_folder($pdo, $o);
    if ($folder === null) return null;
    $mime = (string)($o['mime'] ?? '') ?: r2_mime((string)$o['name']);
    $key = files_free_key($folder, (string)$o['name'], !empty($o['overwrite']));
    r2_put($key, $bytes, $mime);
    $h = r2_head($key);
    drive_catalog_invalidate();
    $id = files_register($pdo, $key, ['etag' => $h['etag'] ?? md5($bytes), 'size' => strlen($bytes), 'mime' => $mime] + $o);
    return ['id' => $id, 'key' => $key, 'name' => drive_base($key)];
}

/**
 * Passa um objeto que já está no armazenamento (um original do Toppernote em .topper/cc/,
 * uma importação) para o seu sítio no Drive e regista-o. Devolve a chave nova (ou a antiga,
 * se não houver destino).
 */
function files_adopt(PDO $pdo, string $from, array $o): string {
    if (!files_on()) return $from;
    $folder = files_target_folder($pdo, $o);
    if ($folder === null) return $from;
    $h = r2_head($from);
    if ($h === null) return $from;
    $to = files_free_key($folder, (string)$o['name']);
    r2_mkdir_p($folder);
    drive_put_one(['key' => $from] + $h, $to, true, false);
    drive_catalog_invalidate();
    files_register($pdo, $to, ['etag' => $h['etag'], 'size' => $h['size'], 'mime' => (string)($o['mime'] ?? '') ?: r2_mime($to)] + $o);
    return $to;
}

// ── as pastas acompanham o registo ──────────────────────────

/**
 * Para onde a pasta do registo tem de ir, pela fase: ['from', 'to', 'folder' (o novo
 * drive_folder)] ou null (já está no sítio, ainda não existe, ou vive fora das raízes).
 * Lead convertida: a pasta inteira passa a «Projetos/<ID>_<NOME>/Lead/».
 */
function files_target(PDO $pdo, array $d): ?array {
    $cur = (string)$d['drive_folder'];
    $sp = $cur !== '' ? files_split($cur) : null;
    if ($sp === null) return null;
    [$root, $rest] = $sp;
    $want = files_place($d);
    if ($root === $want) return null;
    $leadRoot = in_array($root, [FILES_LEADS, FILES_ARCH_LEADS], true);
    $dealWant = in_array($want, [FILES_DEALS, FILES_ARCH_DEALS], true);
    if (!$leadRoot && !$dealWant) return null;   // um negócio nunca volta a lead
    // as pastas que vão ficar vazias (mudanças deste registo ainda na fila) não contam como ocupadas
    $st = $pdo->prepare("SELECT from_prefix FROM file_moves WHERE deal_id = ? AND status IN ('queued','running')");
    $st->execute([(int)$d['id']]);
    $leaving = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    $taken = static fn(string $k): bool => !isset($leaving[$k]) && drive_folder_exists($k);
    if ($leadRoot && $dealWant) {
        $folder = drive_unique_key($want . files_leaf($d) . '/', $taken);
        return ['from' => $cur, 'to' => $folder . 'Lead/', 'folder' => $folder];
    }
    $to = drive_unique_key($want . $rest, $taken);
    return ['from' => $cur, 'to' => $to, 'folder' => $to];
}

/**
 * Depois de uma mudança de fase: põe a pasta a caminho do sítio certo. O destino fica logo
 * em deals.drive_folder; a cópia corre na fila (aqui durante $budget segundos, o resto no
 * cron). Devolve o destino, ou null se não houver nada a mudar.
 */
function files_sync(PDO $pdo, int $dealId, string $why, float $budget = 6.0): ?string {
    if (!files_ready($pdo) || !files_on()) return null;
    try {
        $d = files_deal_row($pdo, $dealId);
        if (!$d) return null;
        $t = files_target($pdo, $d);
        if ($t === null) return null;
        // ida e volta antes de a cópia começar (perdido e logo reaberto): a ida desaparece
        $st = $pdo->prepare("SELECT id FROM file_moves WHERE deal_id = ? AND status = 'queued' AND started_at IS NULL AND from_prefix = ? AND to_prefix = ?");
        $st->execute([$dealId, $t['to'], $t['from']]);
        if ($back = (int)$st->fetchColumn()) {
            $pdo->prepare('DELETE FROM file_moves WHERE id = ?')->execute([$back]);
            $pdo->prepare('UPDATE deals SET drive_folder = ? WHERE id = ?')->execute([$t['to'], $dealId]);
            return $t['to'];
        }
        $pdo->prepare('UPDATE deals SET drive_folder = ? WHERE id = ?')->execute([$t['folder'], $dealId]);
        $pdo->prepare('INSERT INTO file_moves (deal_id, why, from_prefix, to_prefix, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$dealId, mb_substr($why, 0, 12), $t['from'], $t['to'], files_now()]);
        if (function_exists('deal_log')) deal_log($pdo, $dealId, 'folder', ['note' => 'A pasta passa para ' . $t['folder']]);
        if ($budget > 0) files_moves_run($pdo, $budget);
        return $t['folder'];
    } catch (Throwable $e) {
        error_log('files_sync ' . $dealId . ': ' . $e->getMessage());
        return null;
    }
}

/** Mudanças de pasta por fazer (para a ficha: «A arquivar a pasta…»). */
function files_moving(PDO $pdo, int $dealId): ?array {
    if (!files_ready($pdo)) return null;
    $st = $pdo->prepare("SELECT * FROM file_moves WHERE deal_id = ? AND status IN ('queued','running','error') ORDER BY id DESC LIMIT 1");
    $st->execute([$dealId]);
    return $st->fetch() ?: null;
}

function files_moves_pending(PDO $pdo): int {
    if (!files_ready($pdo)) return 0;
    return (int)$pdo->query("SELECT COUNT(*) FROM file_moves WHERE status IN ('queued','running')")->fetchColumn();
}

/**
 * Corre a fila das pastas durante $seconds. Uma de cada vez, pela ordem (as de um mesmo
 * registo nunca se cruzam). Devolve quantas acabaram.
 */
function files_moves_run(PDO $pdo, float $seconds = 20.0): int {
    if (!files_ready($pdo) || !files_on()) return 0;
    require_once __DIR__ . '/drive_store.php';
    if (is_file(__DIR__ . '/drive_index.php')) { try { require_once __DIR__ . '/drive_index.php'; } catch (Throwable $e) { error_log('drive_index.php: ' . $e->getMessage()); } }
    $end = microtime(true) + $seconds;
    $done = 0;
    $stale = date('Y-m-d H:i:s', time() - 900);
    while (microtime(true) < $end) {
        $rows = $pdo->query("SELECT * FROM file_moves WHERE status IN ('queued','running') ORDER BY id LIMIT 50")->fetchAll();
        $m = null; $busy = [];
        foreach ($rows as $r) {
            $dk = (int)$r['deal_id'];
            if (isset($busy[$dk])) continue;            // uma anterior do mesmo registo ainda não acabou
            $busy[$dk] = true;
            if ($r['status'] === 'running' && (string)$r['started_at'] > $stale) continue;   // outro pedido está nela
            $m = $r; break;
        }
        if (!$m) break;
        $up = $pdo->prepare("UPDATE file_moves SET status = 'running', started_at = ?, attempts = attempts + 1 WHERE id = ? AND status = ?");
        $up->execute([files_now(), (int)$m['id'], $m['status']]);
        if ($up->rowCount() !== 1) continue;
        $from = (string)$m['from_prefix']; $to = (string)$m['to_prefix'];
        try {
            if (strncmp($to, $from, strlen($from)) === 0 || strncmp($from, $to, strlen($to)) === 0) throw new RuntimeException('Origem e destino encaixam um no outro.');
            r2_mkdir_p($to);
            $more = static fn(): bool => microtime(true) < $end;
            try {
                $r = drive_transfer($from, $to, true, '', true, $more);
            } finally {
                drive_catalog_invalidate();
            }
            if ($r['done']) {
                // favoritos, recentes, partilhas, índice — e, pelo gancho, este registo e os caminhos guardados
                try { drive_store_moved($pdo, $from, $to); } catch (Throwable $e) { error_log('files move books: ' . $e->getMessage()); files_moved($pdo, $from, $to); }
                $pdo->prepare("UPDATE file_moves SET status = 'done', done_at = ?, moved = moved + ?, error = '' WHERE id = ?")->execute([files_now(), (int)$r['n'], (int)$m['id']]);
                $done++;
            } else {
                $pdo->prepare("UPDATE file_moves SET status = 'queued', moved = moved + ?, attempts = 0 WHERE id = ?")->execute([(int)$r['n'], (int)$m['id']]);
            }
        } catch (Throwable $e) {
            $fail = (int)$m['attempts'] + 1 >= 5;
            $pdo->prepare('UPDATE file_moves SET status = ?, error = ? WHERE id = ?')->execute([$fail ? 'error' : 'queued', mb_substr($e->getMessage(), 0, 250), (int)$m['id']]);
            error_log('files_moves_run ' . $m['id'] . ': ' . $e->getMessage());
            if (!$fail) break;   // tenta outra vez na próxima volta
        }
    }
    return $done;
}

/**
 * Uma chave (ou pasta inteira) mudou de $from para $to — pela fila ou à mão no Drive.
 * Atualiza tudo o que guarda caminhos: o registo, o Toppernote, a faturação, as peças,
 * as pastas dos negócios e as mudanças ainda na fila.
 */
function files_moved(PDO $pdo, string $from, string $to): void {
    if (!files_ready($pdo) || $from === $to || $from === '') return;
    $folder = substr($from, -1) === '/';
    $map = static fn(string $k): ?string => $k === $from ? $to : ($folder && strncmp($k, $from, strlen($from)) === 0 ? $to . substr($k, strlen($from)) : null);
    // o registo (a chave e o seu hash)
    $st = $pdo->prepare("SELECT id, obj_key FROM files WHERE obj_key = ? OR obj_key LIKE ? ESCAPE '!'");
    $st->execute([$from, files_like($from) . ($folder ? '%' : '')]);
    $del = $pdo->prepare('DELETE FROM files WHERE key_hash = ? AND id <> ?');
    $up = $pdo->prepare("UPDATE files SET obj_key = ?, key_hash = ?, status = 'ok', updated_at = ? WHERE id = ?");
    foreach ($st->fetchAll() as $r) {
        $n = $map((string)$r['obj_key']);
        if ($n === null) continue;
        $del->execute([files_hash($n), (int)$r['id']]);
        $up->execute([$n, files_hash($n), files_now(), (int)$r['id']]);
    }
    // os outros sítios que guardam caminhos: [tabela, coluna, prefixo do valor]
    $cols = [['deals', 'drive_folder', ''], ['mkt_pieces', 'drive_folder', ''], ['cc_assets', 'file_key', 'r2:'], ['deal_billing', 'file_key', 'r2:'],
             ['file_moves', 'to_prefix', '']];
    foreach ($cols as [$t, $c, $pre]) {
        if (!db_has_table($pdo, $t)) continue;
        $st = $pdo->prepare("SELECT id, $c AS v FROM $t WHERE $c = ? OR $c LIKE ? ESCAPE '!'" . ($t === 'file_moves' ? " AND status IN ('queued','running')" : ''));
        $st->execute([$pre . $from, files_like($pre . $from) . ($folder ? '%' : '')]);
        $u = $pdo->prepare("UPDATE $t SET $c = ? WHERE id = ?");
        foreach ($st->fetchAll() as $r) {
            $n = $map(substr((string)$r['v'], strlen($pre)));
            if ($n !== null) $u->execute([$pre . $n, (int)$r['id']]);
        }
    }
}

/** Foram para o Lixo do Drive (ou apagados): o registo fica a saber (volta a «ok» se forem repostos). */
function files_trashed(PDO $pdo, array $keys): void {
    if (!files_ready($pdo)) return;
    $up = $pdo->prepare("UPDATE files SET status = 'trash', updated_at = ? WHERE obj_key = ? OR obj_key LIKE ? ESCAPE '!'");
    foreach ($keys as $k) {
        $k = (string)$k;
        if ($k === '') continue;
        $up->execute([files_now(), $k, substr($k, -1) === '/' ? files_like($k) . '%' : files_like($k)]);
    }
}

// ── encontrar ───────────────────────────────────────────────

function files_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM files WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Onde está o ficheiro agora: ['state' => ok|moving|trash|missing, 'key' => chave atual,
 * 'trash' => id no Lixo]. Atualiza o registo quando o encontra noutro sítio.
 */
function files_locate(PDO $pdo, array $row): array {
    $key = (string)$row['obj_key'];
    $fix = static function (string $new, string $state = 'ok') use ($pdo, $row): void {
        $pdo->prepare('DELETE FROM files WHERE key_hash = ? AND id <> ?')->execute([files_hash($new), (int)$row['id']]);
        $pdo->prepare('UPDATE files SET obj_key = ?, key_hash = ?, status = ?, updated_at = ? WHERE id = ?')->execute([$new, files_hash($new), $state, files_now(), (int)$row['id']]);
    };
    if (r2_head($key) !== null) {
        if ($row['status'] !== 'ok') $fix($key);
        return ['state' => 'ok', 'key' => $key];
    }
    // a meio de uma mudança de pasta
    $st = $pdo->prepare("SELECT from_prefix, to_prefix FROM file_moves WHERE status IN ('queued','running') ORDER BY id");
    foreach ($st->execute() ? $st->fetchAll() : [] as $m) {
        if (strncmp($key, (string)$m['from_prefix'], strlen((string)$m['from_prefix'])) !== 0) continue;
        $n = $m['to_prefix'] . substr($key, strlen((string)$m['from_prefix']));
        if (r2_head($n) !== null) { $fix($n); return ['state' => 'ok', 'key' => $n]; }
        return ['state' => 'moving', 'key' => $key];
    }
    // movido à mão: a mesma impressão digital (ETag e tamanho) no catálogo, de preferência com o mesmo nome
    if ((string)$row['etag'] !== '') {
        $best = null;
        foreach (drive_catalog(true)['objects'] as $o) {
            if (strtolower((string)$o['etag']) !== (string)$row['etag'] || (int)$o['size'] !== (int)$row['size'] || substr($o['key'], -1) === '/') continue;
            if ($best === null || drive_base($o['key']) === drive_base($key)) $best = $o['key'];
        }
        if ($best !== null) { $fix($best); return ['state' => 'ok', 'key' => $best]; }
    }
    // no Lixo do Drive
    require_once __DIR__ . '/drive_store.php';
    foreach (drive_trash_all($pdo) as $t) {
        $tk = (string)$t['k'];
        if ($tk === $key || (substr($tk, -1) === '/' && strncmp($key, $tk, strlen($tk)) === 0)) {
            if ($row['status'] !== 'trash') $pdo->prepare("UPDATE files SET status = 'trash' WHERE id = ?")->execute([(int)$row['id']]);
            return ['state' => 'trash', 'key' => $key, 'trash' => (int)$t['id']];
        }
    }
    if ($row['status'] !== 'missing') $pdo->prepare("UPDATE files SET status = 'missing' WHERE id = ?")->execute([(int)$row['id']]);
    return ['state' => 'missing', 'key' => $key];
}

/** O Drive aberto na pasta do ficheiro, com ele selecionado. */
function files_drive_href(string $key): string {
    return drive_page(drive_parent($key), 'f=' . rawurlencode($key));
}

/** O ficheiro pela plataforma (abre com permissões verificadas): files_api.php?a=open&f=… */
function files_open_href(int $id, bool $download = false): string {
    return '/admin/files_api.php?a=open&f=' . $id . ($download ? '&dl=1' : '');
}

/** Posso ver este ficheiro do registo? (o registo a que pertence, e a faturação só com o Financeiro) */
function files_can_see(PDO $pdo, array $row): bool {
    if ($row['section'] === 'faturacao' || drive_billing((string)$row['obj_key'])) {
        if (!can('financeiro')) return false;
    }
    if ($row['mkt_piece_id'] && can('marketing')) return true;
    if ($row['cc_asset_id'] && can('criativo')) return true;
    if ($row['deal_id']) {
        require_once __DIR__ . '/reach.php';
        return record_reach($pdo, record_get($pdo, (int)$row['deal_id']));
    }
    if ($row['mkt_piece_id']) return can('marketing');
    if ($row['cc_asset_id']) return can('criativo');
    return can('drive');
}

/**
 * Os ficheiros de um negócio/lead para a ficha: o que está na pasta (também o que foi posto à
 * mão) e o que o registo liga ao negócio noutro sítio. Agrupados por secção, os mais recentes
 * primeiro: [secção => [['key','name','size','modified','origin','label','id','by'], …]].
 * A faturação só para quem vê o Financeiro.
 */
function files_for_deal(PDO $pdo, array $d): array {
    $folder = (string)$d['drive_folder'];
    $items = [];
    if (files_on() && $folder !== '') {
        try {
            $cat = drive_catalog_cached();
            $objs = $cat ? array_filter($cat['objects'], fn($o) => strncmp($o['key'], $folder, strlen($folder)) === 0) : r2_list_all($folder, 20);
        } catch (Throwable $e) {
            error_log('files_for_deal: ' . $e->getMessage());
            $objs = [];
        }
        foreach ($objs as $o) {
            if (substr($o['key'], -1) === '/') continue;
            $items[$o['key']] = ['key' => $o['key'], 'name' => drive_base($o['key']), 'size' => (int)$o['size'], 'modified' => (int)$o['modified'],
                                 'section' => files_section_of($folder, $o['key']), 'origin' => 'drive', 'label' => '', 'id' => 0, 'by' => ''];
        }
    }
    if (files_ready($pdo)) {
        $st = $pdo->prepare("SELECT f.*, u.name AS who FROM files f LEFT JOIN users u ON u.id = f.created_by WHERE f.deal_id = ? AND f.status <> 'gone'");
        $st->execute([(int)$d['id']]);
        $fix = $pdo->prepare("UPDATE files SET status = 'ok', updated_at = ? WHERE id = ?");
        foreach ($st->fetchAll() as $r) {
            $k = (string)$r['obj_key'];
            // está na pasta: voltou do Lixo (ou nunca saiu) — o registo fica em dia
            if (isset($items[$k]) && $r['status'] !== 'ok') { $fix->execute([files_now(), (int)$r['id']]); $r['status'] = 'ok'; }
            $base = $items[$k] ?? ['key' => $k, 'name' => drive_base($k), 'size' => (int)$r['size'], 'modified' => (int)strtotime((string)$r['created_at']),
                                   'section' => $folder !== '' && strncmp($k, $folder, strlen($folder)) === 0 ? files_section_of($folder, $k) : (string)$r['section']];
            $items[$k] = ['origin' => (string)$r['origin'], 'label' => (string)$r['label'], 'id' => (int)$r['id'], 'by' => (string)($r['who'] ?? ''),
                          'state' => $r['status']] + $base;
        }
    }
    $fin = can('financeiro');
    $out = [];
    foreach ($items as $it) {
        if (!$fin && ($it['section'] === 'faturacao' || drive_billing($it['key']))) continue;
        $out[$it['section']][] = $it;
    }
    foreach ($out as &$list) usort($list, fn($a, $b) => $b['modified'] <=> $a['modified']);
    unset($list);
    // pela ordem das secções
    $sorted = [];
    foreach (array_keys(FILES_SECTIONS) as $s) if (!empty($out[$s])) $sorted[$s] = $out[$s];
    return $sorted;
}
