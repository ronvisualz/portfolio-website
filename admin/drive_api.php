<?php
/**
 * topper. admin — pedidos do Drive (Cloudflare R2)
 *
 * Só POST com corpo JSON e o token CSRF no cabeçalho X-CSRF-Token.
 * O servidor só assina os envios: os bytes vão do browser diretamente para
 * o R2. Mover, copiar e o Lixo são cópias feitas dentro do próprio R2.
 * Responde {"ok":true,…} ou {"ok":false,"error":"…"} (português).
 * Objeto no JSON: {"k": chave, "s": tamanho, "m": unix, "e": etag}.
 *
 * Operações longas (move, copy, trash, restore, purge, purge_all) trabalham
 * ~20 s por pedido e respondem {done:false, next:{…}, progress:{done,total}}:
 * o browser reenvia «next» tal como veio até receber {done:true}. Nunca se
 * apaga uma origem antes de a cópia estar confirmada (HEAD, mesmo tamanho).
 *
 *   catalog      fresh?                               → tudo o que a página precisa
 *   sign         prefix, name, type, size, overwrite?, on_conflict?, avoid? → envio simples (PUT) ou multipart
 *   mp_sign      key, uploadId, parts[]               → URLs das partes
 *   mp_parts     key, uploadId                        → partes já recebidas (retomar)
 *   mp_complete  key, uploadId, parts[{n,etag}]       → etag final
 *   mp_abort     key, uploadId
 *   upload_done  keys[]                               → atualiza o catálogo e os recentes
 *   url          key, inline?, recent?                → URL GET (anexo 1 h, em linha 4 h)
 *   urls         keys[], inline?                      → vários URLs (ZIP)
 *   info         key                                  → detalhes de um ficheiro ou pasta
 *   thumb_sign   etag   ·   thumb_urls etags[]        → miniaturas (.topper/thumbs/)
 *   mkdir        prefix, name  |  key                 → nova pasta (com key, todos os níveis)
 *   move, copy   from, to, on_conflict?, cont?        → ficheiro ou pasta (renomear = mover)
 *   trash        key, id?  |  keys[]   ·   trash_list   ·   restore id, on_conflict?  |  ids[], on_conflict?
 *   purge        id  |  ids[]   ·   purge_all          → apagar de vez
 *   fav          key, on   ·   recent key
 *   share_create key, hours (ou forever:true, só pastas), password?   ·   share_list key?   ·   share_revoke id
 *   search       q, prefix?, limit?   ·   index_tick   ·   index_status  (índice de conteúdo)
 *   delete, rename                                    → as ações antigas (compatibilidade)
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/drive.php';
require __DIR__ . '/inc/drive_store.php';
require __DIR__ . '/inc/drive_moves.php';
if (is_file(__DIR__ . '/inc/drive_index.php')) {
    // O índice de conteúdo é opcional: sem ele a pesquisa por conteúdo fica vazia.
    // (Traz também o inc/ai.php: ai_private() diz que ficheiros são privados, para as partilhas de pastas.)
    try { require_once __DIR__ . '/inc/drive_index.php'; } catch (Throwable $e) { error_log('drive_index.php: ' . $e->getMessage()); }
}

header('Content-Type: application/json; charset=utf-8');

const DRIVE_BUDGET = 20.0;   // segundos de trabalho por pedido nas operações longas

function api_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function api_err(string $msg, int $code = 400, array $extra = []): void {
    api_out($code, ['ok' => false, 'error' => $msg] + $extra);
}

if (current_user() === null) api_err('A sessão terminou. Entre outra vez.', 401);
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_err('Só POST.', 405);
$tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $tok) || $tok === '') {
    api_err('Pedido inválido. Recarregue a página e tente outra vez.', 403);
}
$raw = (string)file_get_contents('php://input', false, null, 0, 1024 * 1024);
$in = json_decode($raw, true);
if (!is_array($in)) api_err('Pedido inválido (JSON).');

$do = (string)($in['do'] ?? '');
// sign e mp_* guardam os envios em $_SESSION['r2_up']; o resto larga já a sessão,
// senão um pedido lento (mover uma pasta grande) bloqueia todos os outros do browser.
if (!in_array($do, ['sign', 'mp_sign', 'mp_parts', 'mp_complete', 'mp_abort'], true)) session_write_close();
if (!r2_config()) api_err('O armazenamento R2 ainda não está configurado (falta inc/r2_config.php).', 503);

$str = static fn(string $k): string => is_string($in[$k] ?? null) ? $in[$k] : '';

// A faturação (as pastas «Faturação» e as antigas «Faturas» dos negócios) só para quem vê o
// Financeiro: nenhuma chave pedida pode lá estar, os itens do Lixo também não, e uma pasta que
// os tenha lá dentro não se apaga nem se partilha por quem não os vê.
$FIN = can('financeiro');
const DRIVE_BILLING_ERR = 'Os documentos de faturação só estão disponíveis para quem tem acesso ao Financeiro.';
/** Há documentos de faturação debaixo desta pasta? */
function api_has_billing(string $folder): bool {
    if (substr($folder, -1) !== '/') return false;
    $l = strlen($folder);
    foreach (drive_catalog()['objects'] as $o) if (strncmp($o['key'], $folder, $l) === 0 && drive_billing($o['key'])) return true;
    return false;
}
if (!$FIN) {
    $asked = [];
    foreach (['key', 'from', 'to', 'prefix'] as $f) if (is_string($in[$f] ?? null)) $asked[] = $in[$f];
    if (is_string($in['prefix'] ?? null) && is_string($in['name'] ?? null)) $asked[] = $in['prefix'] . $in['name'] . '/';
    if (is_string($in['key'] ?? null) && is_string($in['name'] ?? null)) $asked[] = drive_parent($in['key']) . $in['name'];
    foreach ((array)($in['keys'] ?? []) as $k) if (is_string($k)) $asked[] = $k;
    foreach ($asked as $k) if (drive_billing($k)) api_err(DRIVE_BILLING_ERR, 403);
    $ids = [];
    if (in_array($do, ['restore', 'purge'], true)) {
        if (isset($in['id'])) $ids[] = (int)$in['id'];
        foreach ((array)($in['ids'] ?? []) as $i) $ids[] = (int)$i;
        if (isset($in['cur']['id'])) $ids[] = (int)$in['cur']['id'];
    }
    foreach ($ids ? drive_trash_get_many($pdo, array_values(array_filter($ids))) : [] as $t) if (drive_billing((string)$t['k'])) api_err(DRIVE_BILLING_ERR, 403);
    if (in_array($do, ['trash', 'delete', 'share_create'], true) && r2_config()) {
        foreach ($asked as $k) {
            if (api_has_billing($k)) api_err($do === 'share_create' ? 'Esta pasta tem documentos de faturação. Partilhe uma subpasta ou os ficheiros.'
                                                                    : 'Esta pasta tem documentos de faturação: só quem tem acesso ao Financeiro a pode enviar para o Lixo.', 403);
        }
    }
}

/** Uma chave vinda do browser, validada (nunca dentro de .topper/). */
function want_key(string $k, bool $folder = false): string {
    if (!r2_key_ok($k, $folder) || drive_hidden($k)) api_err('Caminho inválido.');
    return $k;
}
function want_prefix(string $p): string {
    if (drive_hidden($p)) api_err('Caminho inválido.');
    if (!r2_prefix_ok($p)) api_err('Pasta inválida.');
    return $p;
}
/** Ficheiro ou pasta, pela «/» final. */
function want_any(string $k): string {
    return want_key($k, substr($k, -1) === '/');
}
/** O uploadId tem de ter sido criado nesta sessão para esta chave. */
function want_upload(string $key, string $id): void {
    if ($id === '' || strlen($id) > 1024 || (($_SESSION['r2_up'][$id] ?? null) !== $key)) {
        api_err('Este envio não pertence a esta sessão. Comece outra vez.');
    }
}
function safe_type(string $t): string {
    return preg_match('~^[\w.+-]+/[\w.+-]+$~', $t) && strlen($t) < 128 ? $t : 'application/octet-stream';
}
/** Uma lista de strings vinda do browser (keys, etags). */
function want_list($v, int $max, string $tooMany): array {
    $v = is_array($v) ? array_values($v) : [];
    if (count($v) > $max) api_err($tooMany);
    return array_values(array_filter($v, 'is_string'));
}
function want_conflict(array $in): string {
    $oc = is_string($in['on_conflict'] ?? null) && $in['on_conflict'] !== '' ? $in['on_conflict'] : 'error';
    if (!in_array($oc, ['error', 'rename', 'overwrite', 'skip'], true)) api_err('Opção de conflito inválida.');
    return $oc;
}
/** 409 «Já existe «x» em «pasta».» */
function api_conflict(string $to): void {
    api_err('Já existe «' . drive_base($to) . '» em «' . drive_base(drive_parent($to)) . '».', 409, ['conflict' => true, 'key' => $to]);
}
function api_obj(array $o): array {
    return ['k' => (string)$o['key'], 's' => (int)$o['size'], 'm' => (int)$o['modified'], 'e' => (string)($o['etag'] ?? '')];
}
function api_progress(int $done, int $total): array {
    return ['done' => $done, 'total' => max($total, $done)];
}

// ── orçamento de trabalho das operações longas ──────────────
$T0 = microtime(true);
// Só na bancada de testes (o servidor embutido do PHP, que nunca serve produção):
// «_test_steps» limita os objetos por pedido, para exercitar as continuações.
$TEST_STEPS = PHP_SAPI === 'cli-server' && is_int($in['_test_steps'] ?? null) ? max(1, (int)$in['_test_steps']) : 0;
// … e «_test_orphan_age» encurta a hora de espera antes de o purge_all apagar restos sem linha no Lixo.
$TEST_ORPHAN_AGE = PHP_SAPI === 'cli-server' && is_int($in['_test_orphan_age'] ?? null) ? max(0, (int)$in['_test_orphan_age']) : null;
$STEPS = 0;
/** Mais um objeto neste pedido? O primeiro passa sempre (há sempre progresso). */
$more = static function () use (&$STEPS, $T0, $TEST_STEPS): bool {
    if ($STEPS > 0 && (microtime(true) - $T0 > DRIVE_BUDGET || ($TEST_STEPS > 0 && $STEPS >= $TEST_STEPS))) return false;
    $STEPS++;
    return true;
};
/** O corpo do próximo pedido (o browser reenvia-o tal como está). */
$nextBody = static function (array $b) use ($TEST_STEPS, $TEST_ORPHAN_AGE): array {
    if ($TEST_STEPS > 0) $b['_test_steps'] = $TEST_STEPS;
    if ($TEST_ORPHAN_AGE !== null && ($b['do'] ?? '') === 'purge_all') $b['_test_orphan_age'] = $TEST_ORPHAN_AGE;
    return $b;
};

/**
 * Favoritos, recentes e partilhas depois de mover/apagar. O R2 já mudou: se a
 * base de dados falhar aqui, regista-se e a resposta continua a ser a verdade.
 */
function api_books(callable $fn): void {
    try { $fn(); } catch (Throwable $e) { error_log('drive bookkeeping: ' . $e->getMessage()); }
}

/** Purga preguiçosa do Lixo (> 30 dias): no máximo 5 s, e só de 10 em 10 minutos. */
function api_lazy_purge(PDO $pdo): void {
    try {
        if (drive_ts(drive_setting_get($pdo, 'drive_purge_at')) > time() - 600) return;
        drive_setting_set($pdo, 'drive_purge_at', drive_now());
        $end = microtime(true) + 5;
        $more = static fn(): bool => microtime(true) < $end;
        $n = 0;
        api_purge_rows($pdo, drive_trash_expired($pdo), $more, 1000, $n);   // o que ficar a meio segue na próxima vez
    } catch (Throwable $e) {
        error_log('drive purge: ' . $e->getMessage());   // a página abre na mesma
    }
}

/**
 * Mover instantâneo (§32.1): as mesmas verificações do mover de sempre, mas a existência e os conflitos contam com o
 * que está na fila (o utilizador já vê os itens no destino). Grava a linha e responde; a cópia vem a seguir.
 */
function api_move_instant(PDO $pdo, array $in, string $from, string $to, bool $folder, string $oc): void {
    $phys = drive_resolve($pdo, $from);
    if (!drive_moves_exists($pdo, $from)) api_err($folder ? 'A pasta já não existe.' : 'O ficheiro já não existe.', 404);
    $newName = drive_base($to);
    if ($newName !== drive_base($from) && r2_clean_name($newName) !== $newName) api_err('Nome inválido.');
    $policy = 'error';
    if (drive_moves_exists($pdo, $to)) {
        if ($oc === 'skip') api_out(200, ['ok' => true, 'done' => true, 'skipped' => true, 'from' => $from, 'to' => $to, 'moved' => 0]);
        if ($oc === 'error') api_conflict($to);
        if ($oc === 'rename') $to = drive_unique_key($to, static fn(string $k): bool => drive_moves_exists($pdo, $k));
        else $policy = 'overwrite';
    }
    if ($folder && $from !== $to && strncmp($from, $to, strlen($to)) === 0) {
        api_err('Não é possível juntar «' . drive_base($from) . '» à pasta «' . drive_base($to) . '», que a contém.');
    }
    $st = $folder ? drive_prefix_stats($phys) : null;
    if ($folder ? $st['longest'] - strlen($phys) + strlen($to) > R2_KEY_MAX : strlen($to) > R2_KEY_MAX) {
        api_err('Há caminhos dentro de «' . drive_base($from) . '» que ficariam compridos demais no destino. Encurte primeiro o nome de uma das pastas.');
    }
    $batch = is_string($in['batch'] ?? null) && preg_match('/^[a-f0-9]{16}$/', $in['batch']) ? $in['batch'] : bin2hex(random_bytes(8));
    $total = $folder ? (int)$st['files'] : 1;
    $id = drive_move_enqueue($pdo, $from, $to, $folder, $policy, (int)(current_user()['id'] ?? 0), $batch, $total);
    register_shutdown_function(static fn() => drive_moves_after_response($pdo));
    api_out(200, ['ok' => true, 'done' => true, 'queued' => true, 'id' => $id, 'batch' => $batch, 'from' => $from, 'to' => $to, 'moved' => $total,
                  'progress' => api_progress($total, $total)]);
}

const TRASH_LONG = 'O caminho é comprido demais para ir para o Lixo. Encurte primeiro o nome de uma das pastas.';

/** Os ids (inteiros > 0) de uma lista vinda do browser. */
function want_ids($v, int $max): array {
    $v = is_array($v) ? array_values($v) : [];
    if (count($v) > $max) api_err('Demasiados itens de uma vez (máximo ' . $max . ').');
    $out = [];
    foreach ($v as $x) {
        if (is_int($x) || (is_string($x) && ctype_digit($x))) { $x = (int)$x; if ($x > 0 && !in_array($x, $out, true)) $out[] = $x; }
    }
    return $out;
}

/**
 * Enviar vários itens para o Lixo: {keys:[…≤500]}. Cada item tem a sua linha (restaura-se
 * sozinho); os ficheiros soltos vão DRIVE_PAR de cada vez e as pastas com cópias em paralelo.
 * Continuações: {keys:[os que faltam], cur:{key,id} (uma pasta a meio), n, total}.
 * Cada resposta traz items:[{k,id}] e failed:[{k,error}] do que se fez nesse pedido (também
 * num erro, para o browser saber o que já foi).
 */
function api_trash_many(PDO $pdo, array $in, callable $more, callable $nextBody): void {
    $keys = want_list($in['keys'] ?? null, 500, 'Demasiados itens de uma vez (máximo 500).');
    foreach ($keys as $k) {
        want_any($k);
        if (drive_is_root($k)) api_err('As pastas base não podem ser movidas.');
    }
    // uma pasta e algo lá dentro (seleções numa pesquisa): basta a pasta; e cada chave uma vez
    $dirs = array_values(array_filter($keys, static fn($k) => substr($k, -1) === '/'));
    $keys = array_values(array_unique(array_filter($keys, static function ($k) use ($dirs) {
        foreach ($dirs as $d) if ($k !== $d && strncmp($k, $d, strlen($d)) === 0) return false;
        return true;
    })));
    $cur = null;
    if (is_array($in['cur'] ?? null)) {
        $ck = is_string($in['cur']['key'] ?? null) ? $in['cur']['key'] : '';
        $cid = (int)($in['cur']['id'] ?? 0);
        if ($cid < 1 || substr($ck, -1) !== '/') api_err('Pedido inválido.');
        $cur = ['key' => want_any($ck), 'id' => $cid, 'fresh' => false];
    }
    if (!$keys && !$cur) api_err('Nada para enviar para o Lixo.');
    $n = max(0, (int)($in['n'] ?? 0));
    $total = isset($in['total']) ? max(0, (int)$in['total']) : count($keys);
    $items = []; $failed = []; $books = [];
    $reply = static function (bool $done, ?Throwable $e = null) use (&$items, &$failed, &$books, &$keys, &$cur, &$n, $total, $pdo, $nextBody): void {
        if ($books) api_books(static fn() => drive_store_trashed_many($pdo, $books));
        drive_catalog_invalidate();
        $out = ['items' => $items, 'failed' => $failed, 'progress' => api_progress($n, $total)];
        if ($e !== null) {
            if (!$e instanceof R2Exception) error_log('drive_api trash: ' . $e);
            api_err($e instanceof R2Exception ? $e->getMessage() : 'Erro inesperado no servidor. Tente outra vez.', $e instanceof R2Exception ? 502 : 500, $out);
        }
        if ($done) api_out(200, ['ok' => true, 'done' => true] + $out);
        $next = ['do' => 'trash', 'keys' => $keys, 'n' => $n, 'total' => $total];
        if ($cur) $next['cur'] = ['key' => $cur['key'], 'id' => $cur['id']];
        api_out(200, ['ok' => true, 'done' => false, 'next' => $nextBody($next)] + $out);
    };
    @set_time_limit(300);
    try {
        while (true) {
            if ($cur !== null) {   // uma pasta a caminho do Lixo
                $row = drive_trash_get($pdo, $cur['id']);
                if (!$row || $row['k'] !== $cur['key']) {
                    $failed[] = ['k' => $cur['key'], 'error' => 'Este item já não está no Lixo.'];
                    $cur = null; $n++;
                    continue;
                }
                try {
                    $r = drive_transfer($cur['key'], drive_trash_prefix($cur['id']) . $cur['key'], true, '', !$cur['fresh'], $more);
                } catch (Throwable $e) {
                    // Se nada chegou a ir para o Lixo, não fica lá uma linha vazia.
                    try { if (!drive_folder_exists(drive_trash_prefix($cur['id']))) drive_trash_remove($pdo, $cur['id']); } catch (Throwable $e2) { /* o erro que interessa é o primeiro */ }
                    throw $e;
                }
                if (!$r['done']) $reply(false);
                $items[] = ['k' => $cur['key'], 'id' => $cur['id']];
                $books[] = $cur['key'];
                $cur = null; $n++;
                continue;
            }
            if (!$keys) break;
            if (substr($keys[0], -1) === '/') {   // uma pasta: cria a linha e segue no ramo de cima
                if (!$more()) $reply(false);
                $key = array_shift($keys);
                if (!drive_folder_exists($key)) { $failed[] = ['k' => $key, 'error' => 'A pasta já não existe.']; $n++; continue; }
                $st = drive_prefix_stats($key);
                if (strlen(DRIVE_TRASH) + 21 + max($st['longest'], strlen($key)) > R2_KEY_MAX) { $failed[] = ['k' => $key, 'error' => TRASH_LONG]; $n++; continue; }
                $cur = ['key' => $key, 'id' => drive_trash_add($pdo, $key, true, $st['size'], $st['files']), 'fresh' => true];
                continue;
            }
            // ficheiros soltos, DRIVE_PAR de cada vez
            $group = [];
            while ($keys && substr($keys[0], -1) !== '/' && count($group) < DRIVE_PAR) {
                if (!$more()) break;
                $group[] = array_shift($keys);
            }
            if (!$group) $reply(false);
            $heads = r2_head_many($group);
            $jobs = []; $ids = [];
            $pdo->beginTransaction();
            try {
                foreach ($group as $i => $key) {
                    $h = $heads[$i];
                    if ($h === null) { $failed[] = ['k' => $key, 'error' => 'O ficheiro já não existe.']; $n++; continue; }
                    if (strlen(DRIVE_TRASH) + 21 + strlen($key) > R2_KEY_MAX) { $failed[] = ['k' => $key, 'error' => TRASH_LONG]; $n++; continue; }
                    $ids[$i] = drive_trash_add($pdo, $key, false, $h['size'], 1);
                    $jobs[$i] = [['key' => $key] + $h, drive_trash_prefix($ids[$i]) . $key];
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            $errs = $jobs ? drive_put_many($jobs, true, false) : [];
            $stray = [];
            foreach ($jobs as $i => $job) {
                if ($errs[$i] === null) {
                    $items[] = ['k' => $group[$i], 'id' => $ids[$i]];
                    $books[] = $group[$i];
                } else {
                    // A origem ficou onde estava: a cópia (se chegou a haver) e a linha saem do Lixo.
                    $failed[] = ['k' => $group[$i], 'error' => $errs[$i]];
                    $stray[] = $job[1];
                    drive_trash_remove($pdo, $ids[$i]);
                }
                $n++;
            }
            if ($stray) { try { r2_delete_many($stray); } catch (Throwable $e) { error_log('drive trash stray: ' . $e->getMessage()); } }
        }
    } catch (Throwable $e) {
        $reply(false, $e);
    }
    $reply(true);
}

/**
 * Restaurar vários itens: {ids:[…≤500], on_conflict?}, pela ordem dos ids; os ficheiros vão
 * DRIVE_PAR de cada vez, as pastas com cópias em paralelo. Um conflito com «error» pára aí:
 * 409 {conflict:true, id, key, items, failed, skipped} — o browser pergunta e continua.
 * Continuações: {ids:[os que faltam], cur:{id,to} (uma pasta a meio), on_conflict, n, total, skipped}.
 * Cada resposta traz items:[{id,k}] e failed:[{id,error}] do que se fez nesse pedido.
 */
function api_restore_many(PDO $pdo, array $in, callable $more, callable $nextBody): void {
    $ids = want_ids($in['ids'] ?? null, 500);
    $oc = want_conflict($in);
    $cur = null;
    if (is_array($in['cur'] ?? null)) {
        $row = drive_trash_get($pdo, (int)($in['cur']['id'] ?? 0));
        $to = is_string($in['cur']['to'] ?? null) ? $in['cur']['to'] : '';
        if ($row && $row['folder']) $cur = ['row' => $row, 'to' => want_key($to, true), 'check' => true];
    }
    if (!$ids && !$cur && !isset($in['cur'])) api_err('Nada para restaurar.');
    $n = max(0, (int)($in['n'] ?? 0));
    $total = isset($in['total']) ? max(0, (int)$in['total']) : count($ids);
    $skipped = max(0, (int)($in['skipped'] ?? 0));
    $items = []; $failed = [];
    $reply = static function (bool $done, ?Throwable $e = null, ?array $conflict = null) use (&$items, &$failed, &$ids, &$cur, &$n, &$skipped, $total, $oc, $nextBody): void {
        drive_catalog_invalidate();
        $out = ['items' => $items, 'failed' => $failed, 'skipped' => $skipped, 'progress' => api_progress($n, $total)];
        if ($conflict !== null) {
            api_err('Já existe «' . drive_base($conflict['key']) . '» em «' . drive_base(drive_parent($conflict['key'])) . '».', 409,
                    ['conflict' => true, 'id' => $conflict['id'], 'key' => $conflict['key']] + $out);
        }
        if ($e !== null) {
            if (!$e instanceof R2Exception) error_log('drive_api restore: ' . $e);
            api_err($e instanceof R2Exception ? $e->getMessage() : 'Erro inesperado no servidor. Tente outra vez.', $e instanceof R2Exception ? 502 : 500, $out);
        }
        if ($done) api_out(200, ['ok' => true, 'done' => true] + $out);
        $next = ['do' => 'restore', 'ids' => $ids, 'on_conflict' => $oc, 'n' => $n, 'total' => $total, 'skipped' => $skipped];
        if ($cur) $next['cur'] = ['id' => $cur['row']['id'], 'to' => $cur['to']];
        api_out(200, ['ok' => true, 'done' => false, 'next' => $nextBody($next)] + $out);
    };
    $rows = drive_trash_get_many($pdo, $ids);
    $made = []; $taken = [];
    $mkdir = static function (string $p) use (&$made): void {
        if ($p !== '' && !isset($made[$p])) { r2_mkdir_p($p); $made[$p] = true; }
    };
    // um destino já usado neste pedido conta como ocupado (dois itens do Lixo com a mesma chave)
    $exists = static function (string $k) use (&$taken): bool { return isset($taken[$k]) || drive_exists($k); };
    $gone = static function (int $id) use ($pdo, &$failed, &$n): void {
        // Uma linha sem nada no Lixo (apagado por fora, ou uma operação interrompida): sai da lista.
        error_log('drive restore: o item ' . $id . ' do Lixo não tem objetos; a linha foi removida.');
        drive_trash_remove($pdo, $id);
        $failed[] = ['id' => $id, 'error' => 'Este item já não está no Lixo.'];
        $n++;
    };
    @set_time_limit(300);
    try {
        while (true) {
            if ($cur !== null) {   // uma pasta a voltar ao sítio
                $id = $cur['row']['id'];
                $r = drive_transfer(drive_trash_prefix($id) . $cur['row']['k'], $cur['to'], true, '', $cur['check'], $more);
                if (!$r['done']) $reply(false);
                drive_trash_remove($pdo, $id);
                $items[] = ['id' => $id, 'k' => $cur['to']];
                $cur = null; $n++;
                continue;
            }
            if (!$ids) break;
            $id = $ids[0];
            $row = $rows[$id] ?? null;
            if (!$row) { array_shift($ids); $failed[] = ['id' => $id, 'error' => 'Este item já não está no Lixo.']; $n++; continue; }
            if ($row['folder']) {
                if (!$more()) $reply(false);
                if (!drive_folder_exists(drive_trash_prefix($id))) { array_shift($ids); $gone($id); continue; }
                $dest = $row['k'];
                $check = $exists($dest);
                if ($check) {
                    if ($oc === 'error') $reply(false, null, ['id' => $id, 'key' => $dest]);
                    if ($oc === 'skip') { array_shift($ids); $skipped++; $n++; continue; }
                    if ($oc === 'rename') { $dest = drive_unique_key($dest, $exists); $check = false; }
                }
                array_shift($ids);
                $taken[$dest] = true;
                $mkdir($dest);
                $cur = ['row' => $row, 'to' => $dest, 'check' => $check];
                continue;
            }
            // ficheiros seguidos, DRIVE_PAR de cada vez
            $group = [];
            while ($ids && count($group) < DRIVE_PAR) {
                $g = $rows[$ids[0]] ?? null;
                if (!$g || $g['folder'] || !$more()) break;
                $group[] = $g;
                array_shift($ids);
            }
            if (!$group) $reply(false);
            $srcs = []; $dsts = [];
            foreach ($group as $i => $g) { $srcs[$i] = drive_trash_prefix($g['id']) . $g['k']; $dsts[$i] = $g['k']; }
            $hs = r2_head_many($srcs);
            $hd = r2_head_many($dsts);
            $jobs = []; $conflict = null; $overwrite = false;
            foreach ($group as $i => $g) {
                if ($hs[$i] === null) { $gone($g['id']); continue; }
                $dest = $g['k'];
                if ($hd[$i] !== null || isset($taken[$dest])) {
                    if ($oc === 'error') {
                        // pára aqui: este e os seguintes voltam para a fila
                        $conflict = ['id' => $g['id'], 'key' => $dest];
                        $ids = array_merge(array_column(array_slice($group, $i), 'id'), $ids);
                        break;
                    }
                    if ($oc === 'skip') { $skipped++; $n++; continue; }
                    if ($oc === 'rename') $dest = drive_unique_key($dest, $exists);
                    else $overwrite = true;
                }
                $taken[$dest] = true;
                $jobs[$i] = [['key' => $srcs[$i]] + $hs[$i], $dest];
            }
            foreach (array_unique(array_map(static fn($j) => drive_parent($j[1]), $jobs)) as $p) $mkdir($p);
            $errs = $jobs ? drive_put_many($jobs, true, $overwrite) : [];
            foreach ($jobs as $i => $job) {
                if ($errs[$i] === null) {
                    drive_trash_remove($pdo, $group[$i]['id']);
                    $items[] = ['id' => $group[$i]['id'], 'k' => $job[1]];
                } else $failed[] = ['id' => $group[$i]['id'], 'error' => $errs[$i]];
                $n++;
            }
            if ($conflict !== null) $reply(false, null, $conflict);
        }
    } catch (Throwable $e) {
        $reply(false, $e);
    }
    $reply(true);
}

/**
 * Apaga de vez as linhas do Lixo dadas, pela ordem: os ficheiros (a chave no Lixo é conhecida)
 * em lotes de DeleteObjects, as pastas prefixo a prefixo. $n soma os ficheiros apagados.
 * Devolve ['done' => bool, 'removed' => [ids]] (as linhas que saíram neste pedido).
 */
function api_purge_rows(PDO $pdo, array $rows, callable $more, int $batch, int &$n): array {
    $removed = [];
    $rows = array_values($rows);
    for ($i = 0, $c = count($rows); $i < $c; ) {
        $t = $rows[$i];
        if ($t['folder']) {
            $r = drive_purge_prefix(drive_trash_prefix($t['id']), $more, $batch);
            $n += $r['n'];
            if (!$r['done']) return ['done' => false, 'removed' => $removed];
            drive_trash_remove($pdo, $t['id']);
            $removed[] = $t['id'];
            $i++;
            continue;
        }
        $group = [];
        while ($i < $c && !$rows[$i]['folder'] && count($group) < $batch) {
            if (!$more()) break;
            $group[] = $rows[$i++];
        }
        if (!$group) return ['done' => false, 'removed' => $removed];
        r2_delete_many(array_map(static fn($t) => drive_trash_prefix($t['id']) . $t['k'], $group));
        $pdo->beginTransaction();
        foreach ($group as $t) { drive_trash_remove($pdo, $t['id']); $removed[] = $t['id']; }
        $pdo->commit();
        $n += count($group);
    }
    return ['done' => true, 'removed' => $removed];
}

// §32.1: um item movido de forma instantânea já aparece no destino, mas ainda está a ser copiado. Mover/renomear
// outra vez e abrir funcionam (fila e resolvedor); o resto espera que fique arrumado, com uma mensagem clara.
if (in_array($do, ['copy', 'trash', 'share_create', 'share_quick', 'versions', 'index_one', 'zip_list'], true) && ($pendingMoves = drive_moves_pending($pdo))) {
    $keysAsked = [];
    foreach (['key', 'from'] as $f) if (is_string($in[$f] ?? null)) $keysAsked[] = $in[$f];
    foreach ((array)($in['keys'] ?? []) as $k) if (is_string($k)) $keysAsked[] = $k;
    foreach ($keysAsked as $k) {
        $moving = drive_resolve_with($pendingMoves, $k) !== $k || drive_forward_with($pendingMoves, $k) !== $k;
        if (!$moving) {   // uma pasta que contém (ou está dentro de) algo a caminho também espera
            foreach ($pendingMoves as $m) if (strncmp((string)$m['dst'], $k, strlen($k)) === 0 && substr($k, -1) === '/') { $moving = true; break; }
        }
        if ($moving) api_err('«' . drive_base(rtrim($k, '/')) . '» ainda está a ser arrumado no armazenamento. Tente daqui a um minuto.', 409);
    }
}

try {
    switch ($do) {
    case 'catalog': {
        api_lazy_purge($pdo);
        $cat = drive_catalog(!empty($in['fresh']));
        $cat['objects'] = drive_moves_overlay($cat['objects'], drive_moves_pending($pdo));   // mover instantâneo (§32.1)
        $objs = []; $have = [];
        foreach ($cat['objects'] as $o) {
            if (!$FIN && drive_billing($o['key'])) continue;
            $objs[] = api_obj($o);
            $have[$o['key']] = true;
            // as pastas que só existem por terem coisas lá dentro
            for ($p = drive_parent($o['key']); $p !== '' && !isset($have[$p]); $p = drive_parent($p)) $have[$p] = true;
        }
        foreach (DRIVE_ROOTS as $r) $have[$r] = true;
        $index = null;
        if (function_exists('drive_index_status')) {
            try { $index = drive_index_status($pdo); } catch (Throwable $e) { error_log('drive_index_status: ' . $e->getMessage()); }
        }
        api_out(200, [
            'ok'        => true,
            'objects'   => $objs,
            'roots'     => DRIVE_ROOTS,
            'thumbs'    => array_map('strval', array_keys($cat['thumbs'])),
            'favs'      => array_values(array_filter(drive_favs($pdo), static fn($k) => isset($have[$k]))),
            'recents'   => array_values(array_filter(drive_recents($pdo, 50), static fn($r) => isset($have[$r['k']]))),
            'trash'     => drive_trash_stats($pdo),
            'shares'    => array_map('drive_share_out', array_values(array_filter(drive_shares_active($pdo), static fn($x) => $FIN || !drive_billing((string)$x['k'])))),
            'index'     => $index,
            'listed_at' => (int)$cat['listed_at'],
            'truncated' => !empty($cat['truncated']),
        ]);
    }

    // ── envios ──────────────────────────────────────────────
    case 'sign': {
        $prefix = want_prefix($str('prefix'));
        $name = r2_clean_name($str('name'));
        if ($name === '') api_err('Nome de ficheiro inválido.');
        $key = want_key($prefix . $name);
        $size = (int)($in['size'] ?? -1);
        if ($size < 0) api_err('Tamanho inválido.');
        if ($size > 5 * 1024 * 1024 * 1024 * 1024) api_err('O ficheiro é maior do que o máximo do R2 (5 TB).');
        $type = safe_type($str('type'));
        if ($type === 'application/octet-stream') $type = r2_mime($name);
        $oc = $str('on_conflict');
        if (empty($in['overwrite']) && $oc !== 'overwrite' && r2_head($key) !== null) {
            if ($oc === 'rename') {
                // Os envios em curso também ocupam nomes, mesmo sem nada no R2 ainda: os que o browser
                // diz que tem na fila ou a enviar (avoid) e os multipart abertos nesta sessão.
                $busy = [];
                foreach (want_list($in['avoid'] ?? null, 1000, 'Demasiados envios em curso de uma vez.') as $a) $busy[$a] = true;
                foreach ((array)($_SESSION['r2_up'] ?? []) as $k) if (is_string($k)) $busy[$k] = true;
                $key = want_key(drive_unique_key($key, static fn(string $k): bool => isset($busy[$k]) || r2_head($k) !== null));
            } else {
                api_out(409, ['ok' => false, 'exists' => true, 'conflict' => true, 'key' => $key, 'error' => 'Já existe «' . $name . '» nesta pasta.']);
            }
        }
        if ($size <= R2_SINGLE_MAX) {
            api_out(200, ['ok' => true, 'mode' => 'single', 'key' => $key, 'type' => $type, 'url' => r2_presign('PUT', $key, R2_URL_TTL)]);
        }
        $id = r2_mp_create($key, $type);
        $_SESSION['r2_up'][$id] = $key;
        if (count($_SESSION['r2_up']) > 50) array_shift($_SESSION['r2_up']);
        api_out(200, ['ok' => true, 'mode' => 'multipart', 'key' => $key, 'type' => $type, 'uploadId' => $id, 'partSize' => R2_PART_SIZE]);
    }
    case 'mp_sign': {
        $key = want_key($str('key')); $id = $str('uploadId'); want_upload($key, $id);
        $parts = is_array($in['parts'] ?? null) ? $in['parts'] : [];
        if (!$parts || count($parts) > 100) api_err('Partes inválidas.');
        $urls = [];
        foreach ($parts as $n) {
            $n = (int)$n;
            if ($n < 1 || $n > 10000) api_err('Número de parte inválido.');
            $urls[(string)$n] = r2_mp_part_url($key, $id, $n);
        }
        api_out(200, ['ok' => true, 'urls' => (object)$urls]);
    }
    case 'mp_parts': {
        $key = want_key($str('key')); $id = $str('uploadId'); want_upload($key, $id);
        api_out(200, ['ok' => true, 'parts' => (object)r2_mp_parts($key, $id)]);
    }
    case 'mp_complete': {
        $key = want_key($str('key')); $id = $str('uploadId'); want_upload($key, $id);
        $parts = [];
        foreach ((array)($in['parts'] ?? []) as $p) {
            $n = (int)($p['n'] ?? 0); $etag = is_string($p['etag'] ?? null) ? $p['etag'] : '';
            if ($n < 1 || $n > 10000 || !preg_match('/^"?[A-Za-z0-9-]{1,128}"?$/', $etag)) api_err('Partes inválidas.');
            $parts[$n] = [$n, $etag];
        }
        if (!$parts) api_err('Partes inválidas.');
        $etag = r2_mp_complete($key, $id, array_values($parts));
        unset($_SESSION['r2_up'][$id]);
        drive_catalog_invalidate();
        api_out(200, ['ok' => true, 'key' => $key, 'etag' => $etag]);
    }
    case 'mp_abort': {
        $key = want_key($str('key')); $id = $str('uploadId'); want_upload($key, $id);
        r2_mp_abort($key, $id);
        unset($_SESSION['r2_up'][$id]);
        api_out(200, ['ok' => true]);
    }
    case 'upload_done': {
        $keys = want_list($in['keys'] ?? null, 500, 'Demasiados ficheiros de uma vez (máximo 500).');
        $done = 0;
        foreach ($keys as $k) {
            if (!r2_key_ok($k) || drive_hidden($k)) continue;
            drive_recent_touch($pdo, $k, false);
            $done++;
        }
        drive_recent_trim($pdo);
        drive_catalog_invalidate();
        api_out(200, ['ok' => true, 'count' => $done]);
    }

    // ── ler ─────────────────────────────────────────────────
    case 'url': {
        $key = want_key($str('key'));
        $inline = !empty($in['inline']);
        if (!empty($in['recent'])) drive_recent_touch($pdo, $key);
        $phys = drive_resolve($pdo, $key);   // ainda a caminho do destino: lê de onde está (§32.1)
        $u = $phys === $key ? r2_get_url($key, $inline, $inline ? r2_mime($key) : '', $inline ? 4 * 3600 : 3600)
                            : r2_presign('GET', $phys, $inline ? 4 * 3600 : 3600, ['response-content-disposition' => r2_disposition(drive_base($key), $inline)] + ($inline ? ['response-content-type' => r2_mime($key)] : []));
        api_out(200, ['ok' => true, 'url' => $u]);
    }
    case 'urls': {
        $keys = want_list($in['keys'] ?? null, 200, 'Demasiados ficheiros de uma vez (máximo 200).');
        $inline = !empty($in['inline']);
        $urls = []; $skipped = [];
        foreach ($keys as $k) {
            if (!r2_key_ok($k) || drive_hidden($k)) { $skipped[] = $k; continue; }
            $phys = drive_resolve($pdo, $k);
            $urls[$k] = $phys === $k ? r2_get_url($k, $inline, $inline ? r2_mime($k) : '', 3600)
                                     : r2_presign('GET', $phys, 3600, ['response-content-disposition' => r2_disposition(drive_base($k), $inline)] + ($inline ? ['response-content-type' => r2_mime($k)] : []));
        }
        api_out(200, ['ok' => true, 'urls' => (object)$urls, 'skipped' => $skipped]);
    }
    case 'info': {
        $key = want_any($str('key'));
        $folder = substr($key, -1) === '/';
        $cat = drive_catalog();
        $cat['objects'] = drive_moves_overlay($cat['objects'], drive_moves_pending($pdo));
        $obj = null; $fst = null; $index = null;
        if ($folder) {
            $st = drive_folder_stats($cat, $key);
            if (drive_is_root($key) || $st['longest'] > 0 || drive_folder_exists($key)) {
                $fst = ['files' => $st['files'], 'size' => $st['size'], 'folders' => $st['folders'], 'modified' => $st['modified']];
            }
        } else {
            $o = drive_catalog_get($cat, $key);
            if ($o === null && ($h = r2_head($key)) !== null) $o = ['key' => $key] + $h;   // acabado de chegar
            if ($o !== null) $obj = api_obj($o);
            if (function_exists('drive_index_get')) {
                try {
                    $ix = drive_index_get($pdo, $key);
                    if (is_array($ix)) {
                        $at = $ix['indexed_at'] ?? null;
                        $index = ['status' => (string)($ix['status'] ?? ''), 'method' => (string)($ix['method'] ?? ''),
                                  'summary' => (string)($ix['summary'] ?? ''),
                                  'indexed_at' => is_numeric($at) ? (int)$at : drive_ts($at)];
                    }
                } catch (Throwable $e) { error_log('drive_index_get: ' . $e->getMessage()); }
            }
        }
        api_out(200, ['ok' => true, 'object' => $obj, 'folder' => $fst, 'index' => $index,
                      'shares' => array_map('drive_share_out', drive_shares_active($pdo, $key)), 'fav' => drive_is_fav($pdo, $key)]);
    }
    case 'thumb_sign': {
        $etag = strtolower($str('etag'));
        if (!preg_match(DRIVE_ETAG_RE, $etag)) api_err('Miniatura inválida.');
        $k = DRIVE_THUMBS . $etag . '.jpg';
        $hour = intdiv(time(), 3600) * 3600;
        api_out(200, ['ok' => true, 'url' => r2_presign('PUT', $k, 600), 'type' => 'image/jpeg',
                      'get' => r2_presign('GET', $k, 7 * 3600, [], $hour)]);
    }
    case 'thumb_urls': {
        $etags = want_list($in['etags'] ?? null, 300, 'Demasiadas miniaturas de uma vez (máximo 300).');
        $thumbs = drive_catalog()['thumbs'];
        // Com a hora certa como data da assinatura, o URL é o mesmo durante uma hora (o browser
        // aproveita a cache) e vale sempre pelo menos 6 h.
        $hour = intdiv(time(), 3600) * 3600;
        $urls = [];
        foreach ($etags as $e) {
            $e = strtolower($e);
            if (!preg_match(DRIVE_ETAG_RE, $e) || empty($thumbs[$e])) continue;
            $urls[$e] = r2_presign('GET', DRIVE_THUMBS . $e . '.jpg', 7 * 3600, [], $hour);
        }
        api_out(200, ['ok' => true, 'urls' => (object)$urls]);
    }

    // ── pastas ──────────────────────────────────────────────
    case 'mkdir': {
        if (array_key_exists('key', $in)) {
            $key = want_key($str('key'), true);
            $acc = '';
            foreach (explode('/', rtrim($key, '/')) as $seg) {
                $acc .= $seg . '/';
                // os níveis novos têm de ter um nome limpo; os que já existem ficam como estão
                if (r2_clean_name($seg) !== $seg && !drive_folder_exists($acc)) api_err('Nome de pasta inválido: «' . $seg . '».');
            }
            if (drive_exists($key)) api_err('Já existe uma pasta chamada «' . drive_base($key) . '» aqui.', 409);
            r2_mkdir_p($key);
            drive_catalog_invalidate();
            api_out(200, ['ok' => true, 'key' => $key, 'message' => 'Pasta «' . drive_base($key) . '» criada.']);
        }
        $prefix = want_prefix($str('prefix'));
        $name = r2_clean_name($str('name'));
        if ($name === '') api_err('Escreva o nome da pasta.');
        $key = want_key($prefix . $name . '/', true);
        if (drive_exists($key)) api_err('Já existe uma pasta chamada «' . $name . '» aqui.', 409);
        r2_mkdir_p($key);
        drive_catalog_invalidate();
        api_out(200, ['ok' => true, 'key' => $key, 'message' => 'Pasta «' . $name . '» criada.']);
    }

    // ── mover / copiar (renomear = mover na mesma pasta) ────
    case 'move':
    case 'copy': {
        $move = $do === 'move';
        $from = $str('from'); $to = $str('to');
        $folder = substr($from, -1) === '/';
        if ($folder !== (substr($to, -1) === '/')) api_err('A origem e o destino têm de ser os dois ficheiros ou as duas pastas.');
        want_key($from, $folder); want_key($to, $folder);
        if ($move && drive_is_root($from)) api_err('As pastas base não podem ser movidas.');
        if ($folder && $from !== $to && strncmp($to, $from, strlen($from)) === 0) {
            api_err($move ? 'Não é possível mover uma pasta para dentro dela própria.' : 'Não é possível copiar uma pasta para dentro dela própria.');
        }
        $oc = want_conflict($in);
        $cont = !empty($in['cont']);
        $n = max(0, (int)($in['n'] ?? 0));
        $total = max(0, (int)($in['total'] ?? 0));
        $check = true;
        // Para cima de si próprio, em qualquer pedido (também nas continuações): não há nada a fazer.
        // Só a cópia com «Manter ambos» faz sentido aqui (duplicar na mesma pasta: «nome (2)»).
        if ($from === $to && ($move || $cont || $oc === 'overwrite')) {
            api_out(200, ['ok' => true, 'done' => true, 'from' => $from, 'to' => $to, 'moved' => 0]);
        }
        // §32.1 mover instantâneo: decide os conflitos como sempre, põe na fila e responde já
        if ($move && !$cont && !empty($in['instant']) && drive_moves_ready($pdo)) api_move_instant($pdo, $in, $from, $to, $folder, $oc);
        if (!$cont) {
            $newName = drive_base($to);
            if ($newName !== drive_base($from) && r2_clean_name($newName) !== $newName) api_err('Nome inválido.');
            if ($folder ? !drive_folder_exists($from) : r2_head($from) === null) {
                api_err($folder ? 'A pasta já não existe.' : 'O ficheiro já não existe.', 404);
            }
            $check = drive_exists($to);
            if ($check) {
                if ($oc === 'skip') api_out(200, ['ok' => true, 'done' => true, 'skipped' => true, 'from' => $from, 'to' => $to, 'moved' => 0]);
                if ($oc === 'error') api_conflict($to);
                if ($oc === 'rename') {
                    $to = drive_unique_key($to, 'drive_exists');
                    $check = false;
                }
            }
        }
        // Juntar uma pasta a uma pasta que a contém (Projetos/Fotos/Fotos/ → Projetos/Fotos/) escreveria
        // por cima do que ainda falta ler da origem: recusa-se sempre, também nas continuações.
        if ($folder && $from !== $to && strncmp($from, $to, strlen($to)) === 0) {
            api_err('Não é possível juntar «' . drive_base($from) . '» à pasta «' . drive_base($to) . '», que a contém.');
        }
        if (!$cont) {
            $st = $folder ? drive_prefix_stats($from) : null;
            // Antes de mexer em alguma coisa: nenhum caminho pode passar o limite do R2 no destino.
            if ($folder ? $st['longest'] - strlen($from) + strlen($to) > R2_KEY_MAX : strlen($to) > R2_KEY_MAX) {
                api_err('Há caminhos dentro de «' . drive_base($from) . '» que ficariam compridos demais no destino. Encurte primeiro o nome de uma das pastas.');
            }
            $mk = $folder ? $to : drive_parent($to);
            if ($mk !== '') r2_mkdir_p($mk);   // os marcadores do destino
            $total = $folder ? $st['files'] : 1;
        }
        @set_time_limit(300);
        try {
            $r = drive_transfer($from, $to, $move, $move ? '' : $str('after'), $check, $more);
        } finally {
            drive_catalog_invalidate();
        }
        $n += $r['n'];
        if (!$r['done']) {
            api_out(200, ['ok' => true, 'done' => false, 'from' => $from, 'to' => $to, 'progress' => api_progress($n, $total),
                'next' => $nextBody(['do' => $do, 'from' => $from, 'to' => $to, 'on_conflict' => $oc, 'cont' => true,
                                     'after' => $r['after'], 'n' => $n, 'total' => $total])]);
        }
        if ($move) api_books(static fn() => drive_store_moved($pdo, $from, $to));
        api_out(200, ['ok' => true, 'done' => true, 'from' => $from, 'to' => $to, 'moved' => $n, 'progress' => api_progress($n, $total)]);
    }

    // ── mover instantâneo (§32.1): anular e estado da fila ──
    case 'move_undo': {
        $batch = $str('batch');
        if (!preg_match('/^[a-f0-9]{16}$/', $batch)) api_err('Pedido inválido.');
        try { $r = drive_move_undo($pdo, $batch, (int)(current_user()['id'] ?? 0), is_admin()); }
        catch (RuntimeException $e) { api_err($e->getMessage(), $e->getCode() === 404 ? 404 : 400); }
        drive_catalog_invalidate();
        register_shutdown_function(static fn() => drive_moves_after_response($pdo));
        api_out(200, ['ok' => true] + $r);
    }
    case 'moves_status': {
        $st = drive_moves_status($pdo, (int)(current_user()['id'] ?? 0));
        if ($st['moves'] > 0) register_shutdown_function(static fn() => drive_moves_after_response($pdo));   // e adianta a fila
        api_out(200, ['ok' => true, 'moves' => $st['moves'], 'files' => $st['files'],
                      'failed' => array_map(static fn($f) => ['from' => $f['src'], 'to' => $f['dst'], 'error' => $f['error']], $st['failed'])]);
    }

    // ── Lixo ────────────────────────────────────────────────
    case 'trash': {
        if (array_key_exists('keys', $in)) api_trash_many($pdo, $in, $more, $nextBody);
        $key = want_any($str('key'));
        $folder = substr($key, -1) === '/';
        if (drive_is_root($key)) api_err('As pastas base não podem ser movidas.');
        $id = (int)($in['id'] ?? 0);
        $n = max(0, (int)($in['n'] ?? 0));
        $total = max(0, (int)($in['total'] ?? 0));
        $first = $id <= 0;
        if (!$first) {
            $row = drive_trash_get($pdo, $id);
            if (!$row || $row['k'] !== $key) api_err('Este item já não está no Lixo.', 404);
        } else {
            if ($folder) {
                if (!drive_folder_exists($key)) api_err('A pasta já não existe.', 404);
                $st = drive_prefix_stats($key);
                $size = $st['size']; $files = $st['files']; $longest = max($st['longest'], strlen($key));
            } else {
                $h = r2_head($key);
                if ($h === null) api_err('O ficheiro já não existe.', 404);
                $size = $h['size']; $files = 1; $longest = strlen($key);
            }
            if (strlen(DRIVE_TRASH) + 21 + $longest > R2_KEY_MAX) api_err(TRASH_LONG);
            $id = drive_trash_add($pdo, $key, $folder, $size, $files);
            $total = $files;
        }
        @set_time_limit(300);
        try {
            $r = drive_transfer($key, drive_trash_prefix($id) . $key, true, '', !$first, $more);
        } catch (Throwable $e) {
            // Se nada chegou a ir para o Lixo, não fica lá uma linha vazia.
            try {
                if ($first && !r2_list_all(drive_trash_prefix($id), 1)) drive_trash_remove($pdo, $id);
            } catch (Throwable $e2) { /* fica a linha; o erro que interessa é o primeiro */ }
            throw $e;
        } finally {
            drive_catalog_invalidate();
        }
        $n += $r['n'];
        if (!$r['done']) {
            api_out(200, ['ok' => true, 'done' => false, 'id' => $id, 'k' => $key, 'progress' => api_progress($n, $total),
                'next' => $nextBody(['do' => 'trash', 'key' => $key, 'id' => $id, 'n' => $n, 'total' => $total])]);
        }
        api_books(static fn() => drive_store_trashed($pdo, $key));
        api_out(200, ['ok' => true, 'done' => true, 'id' => $id, 'k' => $key, 'moved' => $n, 'progress' => api_progress($n, $total)]);
    }
    case 'trash_list': {
        $st = drive_trash_stats($pdo);
        $items = array_values(array_filter(drive_trash_all($pdo), static fn($t) => $FIN || !drive_billing((string)$t['k'])));
        api_out(200, ['ok' => true, 'items' => array_map('drive_trash_out', $items), 'count' => $FIN ? $st['count'] : count($items), 'size' => $FIN ? $st['size'] : array_sum(array_column($items, 's'))]);
    }
    case 'restore': {
        if (array_key_exists('ids', $in)) api_restore_many($pdo, $in, $more, $nextBody);
        $id = (int)($in['id'] ?? 0);
        $row = $id > 0 ? drive_trash_get($pdo, $id) : null;
        if (!$row) api_err('Este item já não está no Lixo.', 404);
        $src = drive_trash_prefix($id) . $row['k'];
        $oc = want_conflict($in);
        $n = max(0, (int)($in['n'] ?? 0));
        $total = max(0, (int)($in['total'] ?? 0));
        if (!empty($in['cont'])) {
            $dest = want_key($str('to'), $row['folder']);
            $check = true;
        } else {
            // Nada no Lixo para esta linha (apagado por fora, uma operação interrompida): dizê-lo,
            // em vez de «restaurado» sem ter restaurado nada.
            if (!drive_folder_exists(drive_trash_prefix($id))) {
                error_log('drive restore: o item ' . $id . ' do Lixo não tem objetos; a linha foi removida.');
                drive_trash_remove($pdo, $id);
                api_err('Este item já não está no Lixo.', 404);
            }
            $dest = $row['k'];
            $check = drive_exists($dest);
            if ($check) {
                if ($oc === 'skip') api_out(200, ['ok' => true, 'done' => true, 'skipped' => true, 'id' => $id, 'k' => $dest]);
                if ($oc === 'error') api_conflict($dest);
                if ($oc === 'rename') { $dest = drive_unique_key($dest, 'drive_exists'); $check = false; }
            }
            $mk = $row['folder'] ? $dest : drive_parent($dest);
            if ($mk !== '') r2_mkdir_p($mk);
            $total = $row['files'];
        }
        @set_time_limit(300);
        try {
            $r = drive_transfer($src, $dest, true, '', $check, $more);
        } finally {
            drive_catalog_invalidate();
        }
        $n += $r['n'];
        if (!$r['done']) {
            api_out(200, ['ok' => true, 'done' => false, 'id' => $id, 'k' => $dest, 'progress' => api_progress($n, $total),
                'next' => $nextBody(['do' => 'restore', 'id' => $id, 'cont' => true, 'to' => $dest, 'on_conflict' => $oc, 'n' => $n, 'total' => $total])]);
        }
        drive_trash_remove($pdo, $id);
        api_out(200, ['ok' => true, 'done' => true, 'id' => $id, 'k' => $dest, 'moved' => $n, 'progress' => api_progress($n, $total)]);
    }
    case 'purge': {
        if (array_key_exists('ids', $in)) {
            // vários itens: {ids:[…≤1000]}; as continuações levam os que faltam
            $ids = want_ids($in['ids'], 1000);
            $rows = drive_trash_get_many($pdo, $ids);
            $n = max(0, (int)($in['n'] ?? 0));
            $total = isset($in['total']) ? max(0, (int)$in['total']) : array_sum(array_column($rows, 'files'));
            @set_time_limit(300);
            try {
                $r = api_purge_rows($pdo, array_values(array_filter(array_map(static fn($id) => $rows[$id] ?? null, $ids))), $more, $TEST_STEPS > 0 ? 1 : 1000, $n);
            } finally {
                drive_catalog_invalidate();
            }
            // os que já não estavam no Lixo também saíram da lista
            $gone = array_values(array_merge($r['removed'], array_diff($ids, array_keys($rows))));
            if (!$r['done']) {
                api_out(200, ['ok' => true, 'done' => false, 'items' => $gone, 'progress' => api_progress($n, $total),
                    'next' => $nextBody(['do' => 'purge', 'ids' => array_values(array_diff($ids, $gone)), 'n' => $n, 'total' => $total])]);
            }
            api_out(200, ['ok' => true, 'done' => true, 'items' => $gone, 'purged' => $n, 'progress' => api_progress($n, $total)]);
        }
        $id = (int)($in['id'] ?? 0);
        $row = $id > 0 ? drive_trash_get($pdo, $id) : null;
        if (!$row) api_err('Este item já não está no Lixo.', 404);
        $n = max(0, (int)($in['n'] ?? 0));
        $total = isset($in['total']) ? max(0, (int)$in['total']) : $row['files'];
        @set_time_limit(300);
        $r = drive_purge_prefix(drive_trash_prefix($id), $more, $TEST_STEPS > 0 ? 1 : 1000);
        $n += $r['n'];
        if (!$r['done']) {
            api_out(200, ['ok' => true, 'done' => false, 'id' => $id, 'progress' => api_progress($n, $total),
                'next' => $nextBody(['do' => 'purge', 'id' => $id, 'n' => $n, 'total' => $total])]);
        }
        drive_trash_remove($pdo, $id);
        api_out(200, ['ok' => true, 'done' => true, 'id' => $id, 'purged' => $n, 'progress' => api_progress($n, $total)]);
    }
    case 'purge_all': {
        // Só o que já estava no Lixo quando se carregou em «Esvaziar Lixo» (ids até «upto»): o que
        // outro sócio enviar para o Lixo entretanto fica lá.
        $upto = isset($in['upto']) ? max(0, (int)$in['upto']) : drive_trash_max_id($pdo);
        $rows = array_reverse(array_values(array_filter(drive_trash_all($pdo), static fn($t) => $t['id'] <= $upto && ($FIN || !drive_billing((string)$t['k'])))));   // os mais antigos primeiro
        $n = max(0, (int)($in['n'] ?? 0));
        $total = isset($in['total']) ? max(0, (int)$in['total']) : array_sum(array_column($rows, 'files'));
        $batch = $TEST_STEPS > 0 ? 1 : 1000;
        $stop = static function () use (&$n, $total, $upto, $nextBody): void {
            drive_catalog_invalidate();
            api_out(200, ['ok' => true, 'done' => false, 'progress' => api_progress($n, $total),
                'next' => $nextBody(['do' => 'purge_all', 'upto' => $upto, 'n' => $n, 'total' => $total])]);
        };
        @set_time_limit(300);
        if (!api_purge_rows($pdo, $rows, $more, $batch, $n)['done']) $stop();
        // Restos sem linha (operações interrompidas). Primeiro a listagem, depois as linhas: um
        // objeto listado foi copiado para o Lixo depois de a sua linha existir, por isso a linha de
        // um item vivo aparece sempre. E, por cautela, nada com menos de uma hora.
        $listed = r2_list_all(DRIVE_TRASH);
        $live = array_flip(array_column(drive_trash_all($pdo), 'id'));
        $old = time() - ($TEST_ORPHAN_AGE ?? 3600);
        $orphans = [];
        foreach ($listed as $o) {
            $id = (int)explode('/', substr($o['key'], strlen(DRIVE_TRASH)))[0];
            if (isset($live[$id]) || (int)$o['modified'] > $old) continue;
            if (!$FIN && drive_billing(substr($o['key'], strlen(DRIVE_TRASH . $id . '/')))) continue;
            $orphans[] = $o['key'];
        }
        foreach (array_chunk($orphans, $batch) as $chunk) {
            if (!$more()) $stop();
            r2_delete_many($chunk);
        }
        api_out(200, ['ok' => true, 'done' => true, 'purged' => $n, 'left' => drive_trash_stats($pdo)['count'], 'progress' => api_progress($n, $total)]);
    }

    // ── favoritos e recentes ────────────────────────────────
    case 'fav': {
        $key = want_any($str('key'));
        $on = !empty($in['on']);
        if ($on && !drive_exists($key)) api_err(substr($key, -1) === '/' ? 'A pasta já não existe.' : 'O ficheiro já não existe.', 404);
        drive_fav_set($pdo, $key, $on);
        api_out(200, ['ok' => true, 'favs' => drive_favs($pdo)]);
    }
    case 'recent': {
        drive_recent_touch($pdo, want_any($str('key')));
        api_out(200, ['ok' => true]);
    }

    // ── links de partilha ───────────────────────────────────
    case 'share_create': {
        $key = want_any($str('key'));
        $folder = substr($key, -1) === '/';
        // «forever»: o link da pasta fica ativo até ser revogado (só pastas; um ficheiro tem sempre prazo)
        $forever = ($in['forever'] ?? false) === true;
        if ($forever) {
            if (!$folder) api_err('Só uma pasta pode ficar partilhada sem prazo. Escolha uma duração entre 1 hora e 7 dias.');
            $hours = DRIVE_SHARE_MAX_HOURS;
        } else {
            $hours = filter_var($in['hours'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($hours) || is_bool($in['hours'] ?? null) || $hours < 1 || $hours > DRIVE_SHARE_MAX_HOURS) {
                api_err('Escolha uma duração entre 1 hora e 7 dias.');
            }
        }
        if (drive_is_root($key)) api_err('As pastas base não podem ser partilhadas. Partilhe uma subpasta ou os ficheiros.');
        if (!drive_exists($key)) api_err($folder ? 'A pasta já não existe.' : 'O ficheiro já não existe.', 404);
        // Uma pasta que tem lá dentro documentos privados (Documentos legais) só se partilha se
        // quem partilha o confirmar (include_private, depois de ver a lista na interface).
        if ($folder && empty($in['include_private']) && function_exists('ai_private') && !ai_private($key)) {
            $priv = [];
            $l = strlen($key);
            foreach (drive_catalog()['objects'] as $o) {
                $k = (string)$o['key'];
                if (strncmp($k, $key, $l) === 0 && substr($k, -1) !== '/' && ai_private($k)) $priv[] = substr($k, $l);
            }
            if ($priv) {
                api_err('Esta pasta contém documentos privados (' . (count($priv) === 1 ? '«' . $priv[0] . '»' : count($priv) . ' ficheiros, por exemplo «' . $priv[0] . '»')
                    . '). Quem tiver o link também os pode descarregar.', 409, ['code' => 'private', 'private' => array_slice($priv, 0, 20), 'count' => count($priv)]);
            }
        }
        // password opcional (quem abre o link tem de a escrever); guarda-se só o hash
        $pw = $in['password'] ?? '';
        if (!is_string($pw)) api_err('Password inválida.');
        if ($pw !== '' && (mb_strlen($pw) < DRIVE_SHARE_PW_MIN || mb_strlen($pw) > DRIVE_SHARE_PW_MAX)) {
            api_err('A password do link tem de ter entre ' . DRIVE_SHARE_PW_MIN . ' e ' . DRIVE_SHARE_PW_MAX . ' caracteres.');
        }
        // §32.2: «Só ver» ou «Pode editar» (este só para quem pode editar a Drive)
        $perm = (string)($in['perm'] ?? 'view');
        if (!in_array($perm, ['view', 'edit'], true)) api_err('Permissão inválida.');
        if ($perm === 'edit' && !can('drive', PERM_EDIT)) api_err('Só quem pode editar a Drive cria links «Pode editar».', 403);
        $s = drive_share_create($pdo, $key, $folder, $hours, $forever, $pw, $perm, (int)(current_user()['id'] ?? 0), !empty($in['include_private']));
        api_out(200, ['ok' => true, 'share' => drive_share_out($s)]);
    }
    case 'share_update': {   // §32.2: mudar a permissão, a validade ou a password sem mudar o link
        require_once __DIR__ . '/inc/drive_share.php';
        $id = (int)($in['id'] ?? 0);
        $cur = $id > 0 ? drive_share_get($pdo, $id) : null;
        if (!$cur) api_err('Este link já não existe.', 404);
        want_any((string)$cur['k']);   // a mesma regra de acesso da chave (faturação)
        if (($in['perm'] ?? '') === 'edit' && !can('drive', PERM_EDIT)) api_err('Só quem pode editar a Drive dá permissão para editar.', 403);
        try { $s = drive_share_update($pdo, $id, array_intersect_key($in, array_flip(['perm', 'hours', 'forever', 'password', 'clear_password']))); }
        catch (RuntimeException $e) { api_err($e->getMessage(), $e->getCode() ?: 400); }
        api_out(200, ['ok' => true, 'share' => drive_share_out($s)]);
    }
    case 'share_quick': {   // «Copiar link de partilha» (§32.6): reaproveita ou cria um link «Só ver» com a validade por defeito
        $key = want_any($str('key'));
        $folder = substr($key, -1) === '/';
        if (drive_is_root($key)) api_err('As pastas base não podem ser partilhadas. Partilhe uma subpasta ou os ficheiros.');
        if (!drive_exists($key)) api_err($folder ? 'A pasta já não existe.' : 'O ficheiro já não existe.', 404);
        if ($folder && function_exists('ai_private') && !ai_private($key)) {
            $l = strlen($key);
            foreach (drive_catalog()['objects'] as $o) {
                $k = (string)$o['key'];
                if (strncmp($k, $key, $l) === 0 && substr($k, -1) !== '/' && ai_private($k)) api_err('Esta pasta contém documentos privados: use «Partilhar link…» para confirmar.', 409, ['code' => 'private']);
            }
        }
        [$s, $reused] = drive_share_quick($pdo, $key, $folder, (int)(current_user()['id'] ?? 0));
        $o = drive_share_out($s);
        api_out(200, ['ok' => true, 'url' => $o['url'], 'share' => $o, 'reused' => $reused]);
    }
    case 'versions': {   // §32.2: as versões anteriores de um ficheiro (substituídas por um link «Pode editar»)
        require_once __DIR__ . '/inc/drive_share.php';
        $key = want_key($str('key'));
        $items = array_map(fn($v) => ['id' => (int)$v['id'], 'size' => (int)$v['size'], 'by' => (string)$v['by_label'], 'at' => drive_ts($v['created_at']),
                                     'url' => r2_get_url((string)$v['ver_key'], false, '', 3600)], drive_versions($pdo, $key));
        api_out(200, ['ok' => true, 'items' => $items]);
    }
    case 'share_list': {
        $key = $str('key') !== '' ? want_any($str('key')) : null;
        api_out(200, ['ok' => true, 'items' => array_map('drive_share_out', drive_shares_active($pdo, $key))]);
    }
    case 'share_revoke': {
        $id = (int)($in['id'] ?? 0);
        if ($id < 1 || !drive_share_revoke($pdo, $id)) api_err('Este link já não existe.', 404);
        api_out(200, ['ok' => true]);
    }

    // ── índice de conteúdo (inc/drive_index.php, se existir) ─
    case 'search': {
        $q = trim($str('q'));
        if (mb_strlen($q) > 200) $q = mb_substr($q, 0, 200);
        $prefix = want_prefix($str('prefix'));
        $limit = max(1, min(100, (int)($in['limit'] ?? 30)));
        $items = [];
        if ($q !== '' && function_exists('drive_index_search')) {
            $items = array_values(array_filter(drive_index_search($pdo, $q, ['limit' => $limit, 'prefix' => $prefix, 'for_ai' => false]), static fn($r) => $FIN || !drive_billing((string)$r['k'])));
        }
        api_out(200, ['ok' => true, 'items' => $items]);
    }
    case 'index_tick': {
        if (!function_exists('drive_index_tick')) api_out(200, ['ok' => true, 'index' => null, 'processed' => 0, 'pending' => 0]);
        @set_time_limit(120);
        $s = drive_index_tick($pdo, 15);
        api_out(200, ['ok' => true] + $s + ['index' => $s]);
    }
    case 'index_status': {
        if (!function_exists('drive_index_status')) api_out(200, ['ok' => true, 'index' => null]);
        $s = drive_index_status($pdo);
        api_out(200, ['ok' => true] + $s + ['index' => $s]);
    }

    // ── as ações antigas (compatibilidade) ──────────────────
    case 'delete': {
        $k = $str('key');
        if (substr($k, -1) === '/') {
            $k = want_key($k, true);
            if (drive_is_root($k)) api_err('As pastas base não podem ser apagadas.');
            $l = r2_list($k);
            if ($l['folders'] || $l['files']) api_err('A pasta não está vazia. Apague primeiro o que está lá dentro.', 409);
            r2_delete($k);
        } else {
            r2_delete(want_key($k));
        }
        drive_catalog_invalidate();
        api_books(static fn() => drive_store_trashed($pdo, $k));
        api_out(200, ['ok' => true, 'message' => 'Apagado.']);
    }
    case 'rename': {
        $key = want_key($str('key'));
        $name = r2_clean_name($str('name'));
        if ($name === '') api_err('Nome inválido.');
        $to = want_key(drive_parent($key) . $name);
        if ($to === $key) api_out(200, ['ok' => true, 'key' => $key]);
        if (r2_head($to) !== null) api_err('Já existe «' . $name . '» nesta pasta.', 409);
        $h = r2_head($key);
        if ($h === null) api_err('O ficheiro já não existe.', 404);
        @set_time_limit(300);
        drive_put_one(['key' => $key] + $h, $to, true, false);
        drive_catalog_invalidate();
        api_books(static fn() => drive_store_moved($pdo, $key, $to));
        api_out(200, ['ok' => true, 'key' => $to, 'message' => 'Renomeado para «' . $name . '».']);
    }
    default:
        api_err('Pedido desconhecido.');
    }
} catch (R2Exception $e) {
    api_err($e->getMessage(), $e->getCode() === 404 ? 404 : 502);
} catch (Throwable $e) {
    error_log('drive_api ' . $do . ': ' . $e);
    // As exceções dos módulos (índice, assistente) trazem mensagens para mostrar; as da base de dados e do PHP não.
    $show = $e instanceof RuntimeException && !($e instanceof PDOException);
    api_err($show ? $e->getMessage() : 'Erro inesperado no servidor. Tente outra vez.', 500);
}
