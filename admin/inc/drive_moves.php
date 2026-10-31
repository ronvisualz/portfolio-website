<?php
/**
 * topper. admin — Drive: mover instantâneo (§32.1)
 *
 * Mover deixou de esperar pela cópia no R2: o pedido decide os conflitos (como antes), grava uma linha em
 * drive_moves e responde logo. A cópia corre depois, no servidor — a seguir à resposta (litespeed/fastcgi_finish_
 * request), nos pedidos de estado da Drive e no cron —, por ordem de id, uma de cada vez (GET_LOCK).
 *
 * Enquanto não acaba:
 *  · o catálogo mostra os itens já no destino (drive_moves_overlay);
 *  · abrir/descarregar um ficheiro no destino assina a chave onde ele ainda está (drive_resolve);
 *  · as referências da plataforma (favoritos, partilhas, registo de ficheiros) só passam para o destino quando a
 *    cópia acaba (drive_store_moved), por isso os outros módulos continuam a abrir a origem, que ainda existe.
 * «Anular»: na fila, cancela (nada mexeu); já a correr ou feito, enfileira o movimento inverso.
 *
 * Ponto de troca para o armazenamento por ID imutável: drive_resolve() é o único sítio que traduz o caminho que o
 * utilizador vê para a chave física.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/drive.php';
require_once __DIR__ . '/drive_store.php';

const DRIVE_MOVE_HOPS = 10;

// ── partes puras ────────────────────────────────────────────

/** A cadeia de chaves por onde $key passou (do destino para a origem), no máximo 10 saltos. [0] é a própria. */
function drive_resolve_chain_with(array $pending, string $key): array {
    usort($pending, static fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
    $chain = [$key];
    for ($i = count($pending) - 1; $i >= 0 && count($chain) <= DRIVE_MOVE_HOPS; $i--) {
        $m = $pending[$i];
        $src = (string)$m['src']; $dst = (string)$m['dst'];
        if (!empty($m['is_folder'])) {
            if (strncmp($key, $dst, strlen($dst)) !== 0) continue;
            $key = $src . substr($key, strlen($dst));
        } elseif ($key === $dst) {
            $key = $src;
        } else continue;
        $chain[] = $key;
    }
    return $chain;
}

/** Onde estava originalmente o que o utilizador vê em $key (a origem mais antiga da fila). */
function drive_resolve_with(array $pending, string $key): string {
    $c = drive_resolve_chain_with($pending, $key);
    return $c[count($c) - 1];
}

/** O mesmo, no sentido da cópia: onde $key vai parar quando a fila acabar. */
function drive_forward_with(array $pending, string $key): string {
    return drive_forward_info($pending, $key)[0];
}

/** [onde vai parar, se algum dos movimentos que o levam lá é «Substituir»]. */
function drive_forward_info(array $pending, string $key): array {
    $over = false;
    foreach ($pending as $m) {
        $src = (string)$m['src']; $dst = (string)$m['dst'];
        if (!empty($m['is_folder'])) {
            if (strncmp($key, $src, strlen($src)) !== 0) continue;
            $key = $dst . substr($key, strlen($src));
        } elseif ($key === $src) {
            $key = $dst;
        } else continue;
        if (($m['policy'] ?? '') === 'overwrite') $over = true;
    }
    return [$key, $over];
}

/** Os objetos do catálogo como ficam depois da fila: cada item já no destino (marcado «pending»), sem duplicados. */
function drive_moves_overlay(array $objects, array $pending): array {
    if (!$pending) return $objects;
    usort($pending, static fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
    $out = []; $moved = [];
    foreach ($objects as $o) {
        [$k, $over] = drive_forward_info($pending, (string)$o['key']);
        if ($k === $o['key']) { $out[$k] = $o; continue; }
        $moved[$k] = ['key' => $k, 'pending' => true, 'over' => $over] + $o;
    }
    // o que já lá está ganha ao «a caminho» (já foi copiado), salvo com «Substituir»: aí o novo é o que vale
    foreach ($moved as $k => $o) if (!isset($out[$k]) || $o['over']) { unset($o['over']); $out[$k] = $o; }
    return array_values($out);
}

// ── com a base de dados ─────────────────────────────────────

function drive_moves_ready(PDO $pdo): bool {
    static $ok = null;
    return $ok ??= db_has_table($pdo, 'drive_moves');
}

/** Os movimentos por acabar (na fila ou a meio), por ordem. */
function drive_moves_pending(PDO $pdo): array {
    if (!drive_moves_ready($pdo)) return [];
    return $pdo->query("SELECT id, src, dst, is_folder, policy, status FROM drive_moves WHERE status IN ('queued','running') ORDER BY id")->fetchAll();
}

/** A chave física de $key agora (o próprio, ou a origem por onde ainda está a passar). */
function drive_resolve(PDO $pdo, string $key): string {
    $pending = drive_moves_pending($pdo);
    if (!$pending) return $key;
    $chain = drive_resolve_chain_with($pending, $key);
    if (count($chain) === 1) return $key;
    // do mais antigo para o mais recente: a origem só desaparece depois de a cópia estar confirmada, por isso
    // enquanto existe é ela a versão certa (também com «Substituir», em que o destino ainda tem o ficheiro antigo)
    foreach (array_reverse($chain) as $k) if (drive_exists($k)) return $k;
    return $key;
}

/** Existe para o utilizador (no sítio ou a caminho de lá)? */
function drive_moves_exists(PDO $pdo, string $key): bool {
    if (drive_exists($key)) {
        // ainda lá está fisicamente mas já vai a caminho de outro sítio: para o utilizador já não está aqui
        $pending = drive_moves_pending($pdo);
        return !$pending || drive_forward_with($pending, $key) === $key;
    }
    $r = drive_resolve($pdo, $key);
    return $r !== $key && drive_exists($r);
}

function drive_move_enqueue(PDO $pdo, string $from, string $to, bool $folder, string $policy, int $by, string $batch, int $total = 0): int {
    $pdo->prepare('INSERT INTO drive_moves (batch, src, dst, is_folder, policy, status, total, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$batch, $from, $to, $folder ? 1 : 0, $policy, 'queued', $total, $by ?: null, drive_now()]);
    return (int)$pdo->lastInsertId();
}

/**
 * «Anular» um lote: o que ainda está na fila é cancelado; o que já começou ou acabou volta para trás (movimento
 * inverso, também em fila). Só quem moveu ou um admin. Devolve o lote novo (o inverso) ou '' se só cancelou.
 */
function drive_move_undo(PDO $pdo, string $batch, int $uid, bool $admin): array {
    $st = $pdo->prepare('SELECT * FROM drive_moves WHERE batch = ? ' . ($admin ? '' : 'AND created_by = ? ') . 'ORDER BY id DESC');
    $st->execute($admin ? [$batch] : [$batch, $uid]);
    $rows = $st->fetchAll();
    if (!$rows) throw new RuntimeException('Não há nada para anular.', 404);
    $back = bin2hex(random_bytes(8)); $cancelled = 0; $reversed = 0;
    foreach ($rows as $r) {
        if ($r['status'] === 'queued') {
            $u = $pdo->prepare("UPDATE drive_moves SET status = 'cancelled', done_at = ? WHERE id = ? AND status = 'queued'");
            $u->execute([drive_now(), $r['id']]);
            if ($u->rowCount() === 1) { $cancelled++; continue; }
            $r['status'] = 'running';   // começou entretanto
        }
        if ($r['status'] === 'running') {
            $pdo->prepare("UPDATE drive_moves SET status = 'cancelled', done_at = ? WHERE id = ? AND status IN ('queued','running')")->execute([drive_now(), $r['id']]);
        }
        if (in_array($r['status'], ['running', 'done'], true)) {
            drive_move_enqueue($pdo, (string)$r['dst'], (string)$r['src'], (bool)$r['is_folder'], 'merge', $uid, $back, (int)$r['total']);
            $reversed++;
        }
    }
    return ['batch' => $reversed ? $back : '', 'cancelled' => $cancelled, 'reversed' => $reversed];
}

/** O que falta arrumar (do utilizador, ou de todos com $uid = 0) e os que falharam na última hora. */
function drive_moves_status(PDO $pdo, int $uid): array {
    if (!drive_moves_ready($pdo)) return ['moves' => 0, 'files' => 0, 'failed' => []];
    $w = $uid ? ' AND created_by = ' . (int)$uid : '';
    $r = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(GREATEST(total - moved, 0)), 0) f FROM drive_moves WHERE status IN ('queued','running')$w")->fetch();
    $st = $pdo->prepare("SELECT id, src, dst, error FROM drive_moves WHERE status = 'failed' AND done_at >= ?$w ORDER BY id DESC LIMIT 5");
    $st->execute([drive_now(-3600)]);
    return ['moves' => (int)$r['n'], 'files' => (int)$r['f'], 'failed' => $st->fetchAll()];
}

/**
 * Faz a fila: por ordem de id, uma linha de cada vez, até $budget segundos. Uma só execução de cada vez (GET_LOCK).
 * Um movimento cancelado a meio para (o inverso, se houver, vem a seguir na fila).
 */
function drive_moves_run(PDO $pdo, float $budget): string {
    if (!drive_moves_ready($pdo) || !r2_config()) return '';
    if ((int)$pdo->query("SELECT GET_LOCK('topper_drive_moves', 0)")->fetchColumn() !== 1) return 'Drive: a fila já está a correr noutro pedido.';
    $end = microtime(true) + max(1.0, $budget);
    $done = 0; $failed = 0;
    try {
        while (microtime(true) < $end) {
            $r = $pdo->query("SELECT * FROM drive_moves WHERE status IN ('queued','running') ORDER BY id LIMIT 1")->fetch();
            if (!$r) break;
            $id = (int)$r['id'];
            $src = (string)$r['src']; $dst = (string)$r['dst'];
            $calls = 0; $stopped = false;
            $more = static function () use ($pdo, $id, $end, &$calls, &$stopped): bool {
                if (microtime(true) >= $end) return false;
                if (++$calls % 50 === 0) {
                    $s = $pdo->query('SELECT status FROM drive_moves WHERE id = ' . $id)->fetchColumn();
                    if ($s === 'cancelled') { $stopped = true; return false; }
                }
                return true;
            };
            try {
                if ($r['status'] === 'queued') {
                    // a decisão de conflito foi tomada ao pôr na fila: se entretanto alguém ocupou o destino, não se
                    // escreve por cima (só «Substituir» e o «Anular», que junta de volta, o fazem)
                    if (!in_array($r['policy'], ['overwrite', 'merge'], true) && drive_exists($dst)) {
                        throw new RuntimeException('Já existe «' . drive_base(rtrim($dst, '/')) . '» no destino. Ficou onde estava.');
                    }
                    $pdo->prepare("UPDATE drive_moves SET status = 'running' WHERE id = ? AND status = 'queued'")->execute([$id]);
                    $mk = $r['is_folder'] ? $dst : drive_parent($dst);
                    if ($mk !== '') r2_mkdir_p($mk);
                }
                if (!$r['is_folder'] && r2_head($src) === null && r2_head($dst) !== null) {
                    $res = ['done' => true, 'n' => 0];   // já tinha sido copiado (pedido anterior caiu depois da cópia)
                } else {
                    $res = drive_transfer($src, $dst, true, '', true, $more);
                }
                if ($res['done']) {
                    $pdo->prepare("UPDATE drive_moves SET status = 'done', moved = moved + ?, done_at = ? WHERE id = ? AND status = 'running'")->execute([(int)$res['n'], drive_now(), $id]);
                    try { drive_store_moved($pdo, $src, $dst); } catch (Throwable $e) { error_log('drive_moves refs: ' . $e->getMessage()); }
                    $done++;
                } else {
                    $pdo->prepare("UPDATE drive_moves SET moved = moved + ? WHERE id = ?")->execute([(int)$res['n'], $id]);
                    if (!$stopped) break;   // acabou o tempo: continua na próxima vez
                }
            } catch (Throwable $e) {
                $pdo->prepare("UPDATE drive_moves SET status = 'failed', error = ?, done_at = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 300), drive_now(), $id]);
                error_log('drive_moves #' . $id . ': ' . $e->getMessage());
                $failed++;
            } finally {
                drive_catalog_invalidate();
            }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('topper_drive_moves')");
    }
    $left = drive_moves_status($pdo, 0);
    return 'Drive: ' . $done . ' movimentos concluídos' . ($failed ? ', ' . $failed . ' falharam' : '') . ' · ' . $left['moves'] . ' por acabar';
}

/**
 * Depois de a resposta seguir para o browser, adianta a fila (~20 s). Só onde dá para fechar a resposta antes
 * (LiteSpeed/FPM); no servidor embutido de testes não faz nada (o pedido ficaria à espera).
 */
function drive_moves_after_response(PDO $pdo): void {
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else return;
    ignore_user_abort(true);
    @set_time_limit(90);
    try { drive_moves_run($pdo, 20.0); } catch (Throwable $e) { error_log('drive_moves_after_response: ' . $e->getMessage()); }
}
