<?php
/**
 * topper. admin — «Reorganizar o Drive» (§25, fase 2)
 *
 * O que já existia antes do registo de ficheiros passa para a estrutura nova, sem apagar
 * nada: só muda de sítio (ou passa a ficar registado). Primeiro o plano (pré-visualização,
 * sem mexer em nada), depois a execução dos itens escolhidos, em segundo plano:
 *
 *   register  propostas e faturas que já estavam no Drive passam a ficar registadas (não mudam)
 *   faturas   as pastas antigas «Faturas/» de cada negócio → «Faturação/»
 *   piece     peças de marketing de um negócio: «Marketing/AAAA-MM/<peça>/» → pasta do negócio
 *   folder    negócios perdidos e leads arquivadas → «Arquivo/» (a pasta inteira)
 *   cc        originais do Centro Criativo guardados no sistema → o seu sítio no Drive
 *
 * Cada item escolhido é uma linha em file_jobs (um «run» por vez que se carrega em Reorganizar).
 * As pastas vão pela fila file_moves (a mesma da fase 1, com o gancho que atualiza os caminhos);
 * os originais do Centro Criativo copiam-se aqui, um a um, até ao fim do tempo de cada volta.
 * Corre pela página (enquanto está aberta) e pelo cron de 5 em 5 minutos.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/files.php';

/** A ordem de execução e os rótulos de cada tipo: chave => [ordem, título, explicação]. */
const FILES_REORG_KINDS = [
    'register' => [0, 'Documentos que passam a ficar registados', 'Propostas e faturas que já estavam no Drive. Não mudam de sítio: passam a aparecer na ficha, com a origem.'],
    'faturas'  => [1, 'Pastas «Faturas» antigas → «Faturação»', 'Dentro da pasta de cada negócio. Os links das faturas na plataforma acompanham.'],
    'piece'    => [2, 'Peças de marketing para a pasta do negócio', 'Peças ligadas a um negócio que estavam em Marketing/AAAA-MM/.'],
    'folder'   => [3, 'Negócios perdidos e leads arquivadas → Arquivo', 'A pasta inteira, com tudo o que tem dentro. Se o registo for reaberto, volta.'],
    'cc'       => [4, 'Originais do Centro Criativo', 'Estavam guardados no sistema (invisíveis no Drive). Passam para a pasta do negócio do quadro, ou para a Biblioteca criativa.'],
];

function files_reorg_ready(PDO $pdo): bool {
    try {
        return (int)$pdo->query("SELECT svalue FROM settings WHERE skey = 'schema_version'")->fetchColumn() >= 26;
    } catch (Throwable $e) {
        return false;
    }
}

/** Objetos (sem marcadores) debaixo de um prefixo, pelo catálogo: [n, bytes]. */
function files_reorg_stats(array $objs, string $prefix): array {
    $n = 0; $b = 0; $l = strlen($prefix);
    foreach ($objs as $k => $o) {
        if (strncmp($k, $prefix, $l) !== 0 || substr($k, -1) === '/') continue;
        $n++; $b += (int)$o['size'];
    }
    return [$n, $b];
}

/** A pasta de um registo: a ligada, ou uma que exista pelo ID em Projetos/ ou Arquivo/Negócios/. */
function files_reorg_folder(array $d, array $tops): string {
    if ((string)$d['drive_folder'] !== '') return (string)$d['drive_folder'];
    $code = (string)$d['code'];
    if ($code === '') return '';
    foreach ([FILES_DEALS, FILES_ARCH_DEALS] as $root) {
        foreach ($tops[$root] ?? [] as $leaf) if (str_starts_with($leaf, $code . '_') || $leaf === $code) return $root . $leaf . '/';
    }
    return '';
}

/** A subpasta de primeiro nível com este nome (sem maiúsculas nem acentos), ou ''. */
function files_reorg_sub(array $objs, string $folder, string $fold): string {
    $l = strlen($folder);
    foreach ($objs as $k => $o) {
        if (strncmp($k, $folder, $l) !== 0) continue;
        $rest = substr($k, $l);
        $p = strpos($rest, '/');
        if ($p !== false && files_fold(substr($rest, 0, $p)) === $fold) return $folder . substr($rest, 0, $p) . '/';
    }
    return '';
}

/**
 * O plano: [['id', 'kind', 'label', 'from', 'to', 'files', 'size', 'note', 'deal', …], …], pela ordem de execução.
 * Não mexe em nada (nem cria pastas).
 */
function files_reorg_plan(PDO $pdo): array {
    if (!files_on()) return [];
    $cat = drive_catalog(true);
    $objs = [];
    foreach ($cat['objects'] as $o) $objs[$o['key']] = $o;
    $tops = [];
    foreach ($objs as $k => $o) {
        foreach ([FILES_DEALS, FILES_ARCH_DEALS] as $root) {
            if (strncmp($k, $root, strlen($root)) !== 0) continue;
            $rest = substr($k, strlen($root));
            if (($p = strpos($rest, '/')) !== false) $tops[$root][substr($rest, 0, $p)] = substr($rest, 0, $p);
        }
    }
    $registered = array_flip($pdo->query('SELECT key_hash FROM files')->fetchAll(PDO::FETCH_COLUMN));
    $phases = ['lost' => 'perdido', 'archived' => 'lead arquivada', 'lead' => 'lead', 'pipeline' => 'no Pipeline', 'production' => 'em produção', 'done' => 'concluído'];
    $items = [];
    $deals = $pdo->query("SELECT * FROM deals WHERE (code IS NOT NULL AND code <> '') OR drive_folder <> '' ORDER BY id")->fetchAll();
    foreach ($deals as $d) {
        $folder = files_reorg_folder($d, $tops);
        if ($folder === '') continue;
        [$n, $b] = files_reorg_stats($objs, $folder);
        $label = trim(($d['code'] ? $d['code'] . ' · ' : '') . ($d['name'] ?: $d['company']));
        // documentos que passam a ficar registados
        $reg = [];
        $st = $pdo->prepare("SELECT id, kind, number, file_key FROM deal_billing WHERE deal_id = ? AND file_key LIKE 'r2:%'");
        $st->execute([(int)$d['id']]);
        foreach ($st->fetchAll() as $bill) {
            $k = substr((string)$bill['file_key'], 3);
            if (!isset($registered[files_hash($k)]) && isset($objs[$k])) $reg[] = $k;
        }
        $props = files_reorg_sub($objs, $folder, 'propostas');
        if ($props !== '') foreach ($objs as $k => $o) {
            if (strncmp($k, $props, strlen($props)) === 0 && substr($k, -1) !== '/' && !isset($registered[files_hash($k)])) $reg[] = $k;
        }
        if ($reg) $items[] = ['id' => 'reg:' . $d['id'], 'kind' => 'register', 'deal' => (int)$d['id'], 'label' => $label, 'from' => $folder, 'to' => '',
                              'files' => count($reg), 'size' => 0, 'note' => count($reg) === 1 ? '1 documento' : count($reg) . ' documentos'];
        // «Faturas/» antigas
        $fat = files_reorg_sub($objs, $folder, 'faturas');
        if ($fat !== '') {
            [$fn, $fb] = files_reorg_stats($objs, $fat);
            $to = $folder . 'Faturação/';
            if (files_reorg_stats($objs, $to)[0] > 0) $to .= 'Anteriores/';
            $items[] = ['id' => 'fat:' . $d['id'], 'kind' => 'faturas', 'deal' => (int)$d['id'], 'label' => $label, 'from' => $fat, 'to' => $to, 'files' => $fn, 'size' => $fb, 'note' => ''];
        }
        // a pasta no sítio da fase
        $t = files_target($pdo, ['drive_folder' => $folder] + $d);
        if ($t !== null) {
            $items[] = ['id' => 'dir:' . $d['id'], 'kind' => 'folder', 'deal' => (int)$d['id'], 'label' => $label, 'from' => $folder, 'to' => $t['to'],
                        'files' => $n, 'size' => $b, 'note' => $phases[$d['phase']] ?? $d['phase']];
        }
    }
    // peças de marketing de um negócio fora da pasta dele
    if (db_has_table($pdo, 'mkt_pieces')) {
        $rows = $pdo->query("SELECT p.id, p.title, p.drive_folder, d.id AS did FROM mkt_pieces p JOIN deals d ON d.id = p.deal_id
                              WHERE p.drive_folder LIKE 'Marketing/%'")->fetchAll();
        foreach ($rows as $p) {
            $d = files_deal_row($pdo, (int)$p['did']);
            $folder = $d ? files_reorg_folder($d, $tops) : '';
            $dest = ($folder !== '' ? $folder : ($d ? files_place($d) . files_leaf($d) . '/' : '')) . 'Marketing/' . drive_base((string)$p['drive_folder']) . '/';
            [$n, $b] = files_reorg_stats($objs, (string)$p['drive_folder']);
            $items[] = ['id' => 'mkt:' . $p['id'], 'kind' => 'piece', 'deal' => (int)$p['did'], 'piece' => (int)$p['id'], 'label' => (string)$p['title'],
                        'from' => (string)$p['drive_folder'], 'to' => $dest, 'files' => $n, 'size' => $b, 'note' => trim(($d['code'] ?? '') . ' · ' . ($d['name'] ?? ''), ' ·')];
        }
    }
    // originais do Centro Criativo ainda no sistema, agrupados pelo destino
    if (db_has_table($pdo, 'cc_assets')) {
        require_once __DIR__ . '/cc.php';
        $group = [];
        $first = $pdo->prepare('SELECT board_id FROM cc_cards WHERE asset_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
        foreach ($pdo->query("SELECT id, inbox, bytes FROM cc_assets WHERE file_key LIKE 'r2:.topper/cc/o/%' AND deleted_at IS NULL ORDER BY id")->fetchAll() as $a) {
            $first->execute([(int)$a['id']]);
            $bid = (int)$first->fetchColumn();
            $board = $bid ? cc_board_get($pdo, $bid) : null;
            $place = cc_drive_place($pdo, $board, (int)$a['inbox'] === 1, false);
            $g = &$group[$place['prefix']];
            $g = ($g ?? ['ids' => [], 'size' => 0, 'deal' => (int)($place['deal_id'] ?? 0)]);
            $g['ids'][] = (int)$a['id'];
            $g['size'] += (int)$a['bytes'];
            unset($g);
        }
        foreach ($group as $prefix => $g) {
            $items[] = ['id' => 'cc:' . substr(sha1($prefix), 0, 16), 'kind' => 'cc', 'deal' => $g['deal'], 'assets' => $g['ids'], 'label' => count($g['ids']) === 1 ? '1 referência' : count($g['ids']) . ' referências',
                        'from' => '.topper/cc/', 'to' => $prefix, 'files' => count($g['ids']), 'size' => $g['size'], 'note' => ''];
        }
    }
    usort($items, fn($a, $b) => [FILES_REORG_KINDS[$a['kind']][0], $a['label']] <=> [FILES_REORG_KINDS[$b['kind']][0], $b['label']]);
    return $items;
}

/** Começa uma reorganização com os itens escolhidos do plano. Devolve o número do run. */
function files_reorg_start(PDO $pdo, array $plan, array $chosen, int $by): int {
    $chosen = array_flip($chosen);
    $run = (int)$pdo->query('SELECT COALESCE(MAX(run_id), 0) + 1 FROM file_jobs')->fetchColumn();
    $ins = $pdo->prepare('INSERT INTO file_jobs (run_id, item, kind, label, payload, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $n = 0;
    foreach ($plan as $it) {
        if (!isset($chosen[$it['id']])) continue;
        $ins->execute([$run, $it['id'], $it['kind'], mb_substr($it['label'] . ($it['note'] !== '' ? ' · ' . $it['note'] : ''), 0, 255), json_encode($it, JSON_UNESCAPED_UNICODE), $by, files_now()]);
        $n++;
    }
    return $n ? $run : 0;
}

/** O estado do último run: [run, total, done, error, queued, moves (pastas ainda a mudar), jobs]. */
function files_reorg_status(PDO $pdo): array {
    $run = (int)$pdo->query('SELECT COALESCE(MAX(run_id), 0) FROM file_jobs')->fetchColumn();
    $jobs = $run ? $pdo->query('SELECT * FROM file_jobs WHERE run_id = ' . $run . ' ORDER BY id')->fetchAll() : [];
    $c = ['done' => 0, 'error' => 0, 'queued' => 0];
    foreach ($jobs as $j) $c[$j['status'] === 'done' ? 'done' : ($j['status'] === 'error' ? 'error' : 'queued')]++;
    $moves = (int)$pdo->query("SELECT COUNT(*) FROM file_moves WHERE why = 'reorg' AND status IN ('queued','running')")->fetchColumn();
    $moveErr = $pdo->query("SELECT from_prefix, to_prefix, error FROM file_moves WHERE why = 'reorg' AND status = 'error'")->fetchAll();
    return ['run' => $run, 'total' => count($jobs), 'moves' => $moves, 'move_errors' => $moveErr, 'jobs' => $jobs] + $c;
}

/** Há alguma coisa por fazer (itens ou pastas a mudar)? */
function files_reorg_busy(PDO $pdo): bool {
    if (!files_reorg_ready($pdo)) return false;
    $s = files_reorg_status($pdo);
    return $s['queued'] > 0 || $s['moves'] > 0;
}

/** Põe uma pasta a mudar (a mesma fila da fase 1). */
function files_reorg_move(PDO $pdo, ?int $dealId, string $from, string $to): void {
    $pdo->prepare("INSERT INTO file_moves (deal_id, why, from_prefix, to_prefix, created_at) VALUES (?, 'reorg', ?, ?, ?)")
        ->execute([$dealId ?: null, $from, $to, files_now()]);
}

/** Um item. Devolve [feito?, resultado]. Os originais do Centro Criativo podem ficar a meio (continuam na volta seguinte). */
function files_reorg_job(PDO $pdo, array $job, float $end): array {
    $it = json_decode((string)$job['payload'], true) ?: [];
    $by = $job['created_by'] ? (int)$job['created_by'] : null;
    $d = !empty($it['deal']) ? files_deal_row($pdo, (int)$it['deal']) : null;
    switch ($job['kind']) {
        case 'register': {
            if (!$d) return [true, 'O registo já não existe.'];
            $folder = (string)$d['drive_folder'] !== '' ? (string)$d['drive_folder'] : (string)$it['from'];
            $n = 0;
            $st = $pdo->prepare("SELECT * FROM deal_billing WHERE deal_id = ? AND file_key LIKE 'r2:%'");
            $st->execute([(int)$d['id']]);
            foreach ($st->fetchAll() as $bill) {
                $k = substr((string)$bill['file_key'], 3);
                if (($h = r2_head($k)) === null) continue;
                $receipt = $bill['kind'] === 'payment';
                files_register($pdo, $k, ['etag' => $h['etag'], 'size' => $h['size'], 'mime' => 'application/pdf', 'deal_id' => (int)$d['id'], 'section' => 'faturacao',
                                           'origin' => $receipt ? 'receipt' : 'invoice', 'label' => $receipt ? 'Comprovativo de pagamento' : 'Fatura ' . $bill['number'], 'by' => $bill['created_by'] ? (int)$bill['created_by'] : null]);
                $n++;
            }
            foreach (r2_list_all($folder, 20) as $o) {
                $k = $o['key'];
                if (substr($k, -1) === '/' || files_section_of($folder, $k) !== 'propostas') continue;
                $st2 = $pdo->prepare('SELECT 1 FROM files WHERE key_hash = ?');
                $st2->execute([files_hash($k)]);
                if ($st2->fetchColumn()) continue;
                $v = preg_match('/_v(\d+)/', drive_base($k), $m) ? ' v' . $m[1] : '';
                files_register($pdo, $k, ['etag' => $o['etag'], 'size' => $o['size'], 'mime' => r2_mime($k), 'deal_id' => (int)$d['id'], 'section' => 'propostas', 'origin' => 'proposal', 'label' => 'Proposta' . $v]);
                $n++;
            }
            return [true, $n === 1 ? '1 documento registado.' : $n . ' documentos registados.'];
        }
        case 'faturas': {
            if (!$d) return [true, 'O registo já não existe.'];
            if (!drive_folder_exists((string)$it['from'])) return [true, 'A pasta já não existe (já foi mudada?).'];
            files_reorg_move($pdo, (int)$d['id'], (string)$it['from'], (string)$it['to']);
            return [true, 'A mudar para ' . $it['to']];
        }
        case 'piece': {
            $p = $pdo->query('SELECT * FROM mkt_pieces WHERE id = ' . (int)$it['piece'])->fetch();
            if (!$p || !$d) return [true, 'A peça ou o negócio já não existem.'];
            $from = (string)$p['drive_folder'];
            if (strncmp($from, 'Marketing/', 10) !== 0) return [true, 'Já está noutro sítio: ' . $from];
            $folder = files_folder($pdo, $d);
            if ($folder === null) return [false, 'Sem pasta do negócio.'];
            $to = drive_unique_key($folder . 'Marketing/' . drive_base($from) . '/', 'drive_folder_exists');
            if (drive_folder_exists($from)) files_reorg_move($pdo, (int)$d['id'], $from, $to);
            else $pdo->prepare('UPDATE mkt_pieces SET drive_folder = ? WHERE id = ?')->execute([$to, (int)$p['id']]);   // pasta vazia: só a ligação
            return [true, 'A mudar para ' . $to];
        }
        case 'folder': {
            if (!$d) return [true, 'O registo já não existe.'];
            if ((string)$d['drive_folder'] === '') $pdo->prepare('UPDATE deals SET drive_folder = ? WHERE id = ?')->execute([(string)$it['from'], (int)$d['id']]);
            $to = files_sync($pdo, (int)$d['id'], 'reorg', 0);
            return [true, $to ? 'A mudar para ' . $to : 'Já estava no sítio.'];
        }
        case 'cc': {
            require_once __DIR__ . '/cc.php';
            $left = array_values(array_map('intval', (array)($it['assets'] ?? [])));
            $first = $pdo->prepare('SELECT board_id FROM cc_cards WHERE asset_id = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
            $moved = (int)($it['moved'] ?? 0);
            while ($left && microtime(true) < $end) {
                $a = cc_asset_get($pdo, array_shift($left));
                if (!$a || strncmp((string)$a['file_key'], 'r2:.topper/cc/o/', 16) !== 0) continue;
                $first->execute([(int)$a['id']]);
                $bid = (int)$first->fetchColumn();
                $a2 = cc_place_original($pdo, $a, $bid ? cc_board_get($pdo, $bid) : null, (int)$a['inbox'] === 1, $by);
                if ($a2['file_key'] !== $a['file_key']) $moved++;
            }
            if ($left) {
                $it['assets'] = $left; $it['moved'] = $moved;
                $pdo->prepare('UPDATE file_jobs SET payload = ? WHERE id = ?')->execute([json_encode($it, JSON_UNESCAPED_UNICODE), (int)$job['id']]);
                return [false, $moved . ' passadas, ' . count($left) . ' por passar'];
            }
            return [true, ($moved === 1 ? '1 referência passou' : $moved . ' referências passaram') . ' para ' . $it['to']];
        }
    }
    return [true, 'Tipo desconhecido.'];
}

/**
 * Uma volta: os itens por fazer (pela ordem dos tipos) e, com o tempo que sobrar, as pastas
 * na fila. Devolve o estado.
 */
function files_reorg_run(PDO $pdo, float $seconds = 20.0): array {
    if (!files_reorg_ready($pdo) || !files_on()) return files_reorg_ready($pdo) ? files_reorg_status($pdo) : [];
    $end = microtime(true) + $seconds;
    $order = "CASE kind WHEN 'register' THEN 0 WHEN 'faturas' THEN 1 WHEN 'piece' THEN 2 WHEN 'folder' THEN 3 ELSE 4 END";
    while (microtime(true) < $end) {
        $job = $pdo->query("SELECT * FROM file_jobs WHERE status = 'queued' ORDER BY run_id, $order, id LIMIT 1")->fetch();
        if (!$job) break;
        try {
            [$done, $msg] = files_reorg_job($pdo, $job, $end);
            if ($done) $pdo->prepare("UPDATE file_jobs SET status = 'done', result = ?, done_at = ? WHERE id = ?")->execute([mb_substr($msg, 0, 255), files_now(), (int)$job['id']]);
            else { $pdo->prepare('UPDATE file_jobs SET result = ? WHERE id = ?')->execute([mb_substr($msg, 0, 255), (int)$job['id']]); break; }
        } catch (Throwable $e) {
            error_log('files_reorg ' . $job['id'] . ': ' . $e->getMessage());
            $pdo->prepare("UPDATE file_jobs SET status = 'error', result = ?, done_at = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 255), files_now(), (int)$job['id']]);
        }
    }
    $left = $end - microtime(true);
    if ($left > 1) files_moves_run($pdo, $left);
    drive_catalog_invalidate();
    return files_reorg_status($pdo);
}
