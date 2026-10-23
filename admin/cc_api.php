<?php
/**
 * topper. admin — pedidos do Centro Criativo (§23). Respostas em JSON.
 *
 * Ler (aba Centro Criativo, ver):
 *   a=boards            q, show (active|archived) — os quadros (Projetos)
 *   a=board             id — o quadro: cartões, caminho, sub-quadros, páginas, pessoas
 *   a=poll              id, rev — mudou entretanto? (outros separadores abertos)
 *   a=library           q, color, style…, origin, kind, inbox, offset — a Biblioteca / Caixa de entrada
 *   a=asset             id — a referência, com frames, quadros onde está e análise
 *   a=similar           id — «Mais como este»
 *   a=comments          board, card
 *   a=jobs              ids — estado da IA de várias referências
 *   a=deals             q — negócios para ligar um quadro
 * Mudar (editar; apagar quadros pede gerir). Todos pedem o token CSRF:
 *   board_new, board_save, board_archive, board_delete, project_create, pre_create
 *   card_add, cards_update, cards_delete, cards_restore, cards_dup, card_swap
 *   upload_init, upload_done, link_add, asset_save, asset_delete, asset_restore, inbox_done
 *   analyze, analyze_all (gerir), work
 *   ai (angles | refs | similar | tidy | treatment | shots | shots_tasks), prepare, page_gen, page_both, send_budget
 *   comment_add, comment_del, comment_resolve, import
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/cc.php';
require_once __DIR__ . '/inc/ui.php';

function cout(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function cfail(string $msg, int $code = 400): void {
    cout($code, ['ok' => false, 'error' => $msg]);
}

if (current_user() === null) cout(401, ['ok' => false, 'error' => 'A sessão terminou. Entre outra vez.']);
$user = require_login();
if (!can('criativo')) cfail('A sua conta não tem acesso ao Centro Criativo.', 403);
if (!cc_ready($pdo)) cfail('O Centro Criativo ainda não está pronto (a base de dados vai atualizar no próximo acesso).', 503);

$a = (string)($_REQUEST['a'] ?? '');
$by = (int)$user['id'];
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$reads = ['boards', 'board', 'poll', 'library', 'asset', 'similar', 'comments', 'jobs', 'deals'];
if (!in_array($a, $reads, true)) {
    if (!$post) cfail('Pedido inválido.', 405);
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) cfail('Pedido inválido. Recarregue a página e tente outra vez.');
    if (!can('criativo', PERM_EDIT)) cfail('A sua conta vê o Centro Criativo mas não o edita.', 403);
}

function rq(string $k, $d = '') { return $_REQUEST[$k] ?? $d; }
function rint(string $k): int { return (int)($_REQUEST[$k] ?? 0); }

/** O quadro do pedido (e que não está apagado). */
function board_req(PDO $pdo, string $k = 'board'): array {
    $b = cc_board_get($pdo, (int)($_REQUEST[$k] ?? $_REQUEST['id'] ?? 0));
    if (!$b || $b['status'] === 'deleted') cfail('Quadro não encontrado.', 404);
    return $b;
}

function board_editable(array $b): void {
    if ($b['status'] === 'archived') cfail('Este quadro está arquivado. Reabra-o para mudar.');
}

/** Os cartões de um pedido em lote, só deste quadro. */
function card_ids(PDO $pdo, int $boardId, $raw): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$raw))));
    if (!$ids) return [];
    $st = $pdo->prepare('SELECT id FROM cc_cards WHERE board_id = ? AND id IN (' . implode(',', $ids) . ')');
    $st->execute([$boardId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function users_light(PDO $pdo): array {
    return array_map(fn($u) => ['id' => (int)$u['id'], 'name' => (string)$u['name'], 'color' => (string)($u['color'] ?? '')],
        $pdo->query("SELECT id, name, color FROM users WHERE is_active = 1 AND deactivated_at IS NULL AND role <> 'superadmin' ORDER BY name")->fetchAll());
}

/** Os trabalhos longos (IA, miniaturas) não seguram a sessão: as outras páginas continuam a responder. */
function release_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

try {
switch ($a) {

// ── ler ─────────────────────────────────────────────────────

case 'boards': {
    $show = rq('show') === 'archived' ? 'archived' : 'active';
    $q = trim((string)rq('q'));
    $where = "b.parent_id IS NULL AND b.status = ?";
    $args = [$show];
    if ($q !== '') {
        $where .= ' AND (b.title LIKE ? OR d.code LIKE ? OR d.name LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        array_push($args, $like, $like, $like);
    }
    $st = $pdo->prepare("SELECT b.*, d.code, d.name AS deal_name, d.phase, u.name AS exec_name,
                                (SELECT COUNT(*) FROM cc_cards c WHERE c.board_id = b.id AND c.deleted_at IS NULL) AS n
                           FROM cc_boards b LEFT JOIN deals d ON d.id = b.deal_id LEFT JOIN users u ON u.id = b.exec_owner_id
                          WHERE $where ORDER BY b.updated_at DESC LIMIT 300");
    $st->execute($args);
    $rows = $st->fetchAll();
    $covers = [];
    if ($rows) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        // capa: as primeiras 4 imagens do quadro (ou dos sub-quadros)
        $cs = $pdo->query("SELECT COALESCE(p.parent_id, p.id) AS root, a.id, a.sha1 FROM cc_cards c JOIN cc_assets a ON a.id = c.asset_id JOIN cc_boards p ON p.id = c.board_id
                            WHERE (p.id IN ($ids) OR p.parent_id IN ($ids)) AND c.deleted_at IS NULL AND a.deleted_at IS NULL AND a.thumbs IS NOT NULL ORDER BY c.id LIMIT 2000");
        foreach ($cs->fetchAll() as $c) {
            $r = (int)$c['root'];
            if (count($covers[$r] ?? []) < 4) $covers[$r][] = '/admin/cc_file.php?a=' . (int)$c['id'] . '&s=256&v=' . substr((string)$c['sha1'], 0, 8);
        }
    }
    $pages = [];
    if ($rows) {
        foreach ($pdo->query('SELECT board_id, lane, sent_at FROM cc_pages WHERE board_id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')')->fetchAll() as $p) {
            $pages[(int)$p['board_id']][$p['lane']] = $p['sent_at'] ? 'sent' : 'made';
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int)$r['id'], 'kind' => $r['kind'], 'title' => (string)$r['title'], 'code' => (string)($r['code'] ?? ''),
                  'deal' => (string)($r['deal_name'] ?? ''), 'phase' => (string)($r['phase'] ?? ''), 'exec' => (string)($r['exec_name'] ?? ''),
                  'n' => (int)$r['n'], 'covers' => $covers[(int)$r['id']] ?? [], 'pages' => $pages[(int)$r['id']] ?? new stdClass(),
                  'ai' => (string)$r['ai_state'], 'why' => (string)$r['archived_why'],
                  'updated' => ago_days(substr((string)$r['updated_at'], 0, 10)), 'at' => (string)$r['updated_at']];
    }
    // negócios em produção (ou com X-Ray validado) ainda sem quadro
    $missing = [];
    if ($show === 'active' && $q === '') {
        $mq = $pdo->query("SELECT d.id, d.code, d.name, d.phase, d.xray_validated_at FROM deals d
                            WHERE ((d.phase = 'production') OR (d.phase = 'pipeline' AND d.xray_validated_at IS NOT NULL))
                              AND NOT EXISTS (SELECT 1 FROM cc_boards b WHERE b.deal_id = d.id AND b.parent_id IS NULL AND b.status <> 'deleted'
                                              AND b.kind = CASE WHEN d.phase = 'production' THEN 'project' ELSE 'precreative' END)
                            ORDER BY d.id DESC LIMIT 30");
        foreach ($mq->fetchAll() as $d) $missing[] = ['id' => (int)$d['id'], 'code' => (string)$d['code'], 'name' => (string)$d['name'], 'kind' => $d['phase'] === 'production' ? 'project' : 'precreative'];
    }
    cout(200, ['ok' => true, 'boards' => $out, 'missing' => $missing]);
}

case 'board': {
    $b = board_req($pdo, 'id');
    $path = array_map(fn($x) => ['id' => (int)$x['id'], 'title' => (string)$x['title'], 'kind' => $x['kind']], cc_board_path($pdo, $b));
    $kids = [];
    foreach (cc_board_children($pdo, (int)$b['id']) as $c) {
        if ($c['status'] === 'deleted') continue;
        $kids[] = ['id' => (int)$c['id'], 'title' => (string)$c['title'], 'kind' => $c['kind'], 'stage' => (string)$c['stage_key'],
                   'n' => (int)$pdo->query('SELECT COUNT(*) FROM cc_cards WHERE deleted_at IS NULL AND board_id = ' . (int)$c['id'])->fetchColumn()];
    }
    // as etapas do projeto (barra de cima): os sub-quadros de etapa do pai (ou deste, se for o projeto/frente)
    $stageHost = in_array($b['kind'], ['project', 'front'], true) ? $b : ($b['parent_id'] ? cc_board_get($pdo, (int)$b['parent_id']) : null);
    $stages = [];
    if ($stageHost) {
        foreach (cc_board_children($pdo, (int)$stageHost['id']) as $c) {
            if ($c['kind'] === 'stage' && $c['status'] !== 'deleted') $stages[] = ['id' => (int)$c['id'], 'key' => (string)$c['stage_key'], 'title' => (string)$c['title']];
        }
    }
    $pages = [];
    if (in_array($b['kind'], ['precreative', 'front'], true)) {
        foreach ($pdo->query("SELECT * FROM cc_pages WHERE board_id = " . (int)$b['id'] . " AND lane IN ('A','B')")->fetchAll() as $p) {
            $d = json_decode((string)$p['data'], true) ?: [];
            $d['refs_view'] = array_map(fn($x) => cc_asset_public($x), cc_assets_by_ids($pdo, (array)($d['refs'] ?? [])));
            $pages[$p['lane']] = ['data' => $d, 'both' => (int)$p['serves_both'] === 1, 'sent' => $p['sent_at'] ? date('d/m H:i', strtotime((string)$p['sent_at'])) : '',
                                  'at' => $p['generated_at'] ? date('d/m H:i', strtotime((string)$p['generated_at'])) : '', 'opt' => (string)$p['opt']];
        }
    }
    $treat = $pdo->query("SELECT data FROM cc_pages WHERE board_id = " . (int)$b['id'] . " AND lane = 'T'")->fetchColumn();
    require_once __DIR__ . '/inc/cc_ai.php';
    cout(200, ['ok' => true, 'board' => cc_board_public($pdo, $b), 'path' => $path, 'children' => $kids, 'stages' => $stages,
               'stage_host' => $stageHost ? (int)$stageHost['id'] : null, 'cards' => cc_board_cards_public($pdo, (int)$b['id']),
               'pages' => $pages ?: new stdClass(), 'treatment' => $treat ? json_decode((string)$treat, true) : null,
               'edit' => can('criativo', PERM_EDIT) && $b['status'] === 'active', 'manage' => can('criativo', PERM_MANAGE),
               'users' => users_light($pdo), 'ai' => (bool)cc_ai_config(), 'embed' => (bool)cc_embed_config(), 'me' => $by,
               'brief' => cc_brief_text($pdo, $b)]);
}

case 'poll': {
    $b = board_req($pdo, 'id');
    release_session();
    cout(200, ['ok' => true, 'rev' => (int)$b['rev'], 'changed' => (int)$b['rev'] !== rint('rev'), 'jobs' => cc_jobs_pending($pdo)]);
}

case 'library': {
    release_session();
    $f = ['q' => rq('q'), 'color' => rq('color'), 'origin' => rq('origin'), 'kind' => rq('kind'), 'inbox' => rq('inbox') === '1', 'deal' => rint('deal')];
    foreach (array_keys(CC_VOCAB) as $k) $f[$k] = (string)rq($k);
    [$items, $total] = cc_library_search($pdo, $f, 60, max(0, rint('offset')));
    $out = ['ok' => true, 'items' => $items, 'total' => $total];
    if (rint('offset') === 0) {
        $out['facets'] = cc_library_facets($pdo);
        $out['vocab'] = array_map(fn($k) => ['key' => $k, 'label' => CC_VOCAB[$k][0], 'values' => cc_vocab($pdo, $k)], array_keys(CC_VOCAB));
        $out['inbox_n'] = (int)$pdo->query('SELECT COUNT(*) FROM cc_assets WHERE inbox = 1 AND deleted_at IS NULL')->fetchColumn();
    }
    cout(200, $out);
}

case 'asset': {
    $x = cc_asset_get($pdo, rint('id'));
    if (!$x || $x['deleted_at']) cfail('Referência não encontrada.', 404);
    $pub = cc_asset_public($x, true);
    $pub['frames'] = array_map(fn($f) => ['id' => (int)$f['id'], 't' => (float)$f['t'], 'thumb' => cc_frame_url($f, 256), 'big' => cc_frame_url($f, 768),
                                          'caption' => (string)(json_decode((string)$f['analysis'], true)['caption'] ?? ''), 'pal' => array_column(cc_palette_hex($f['palette'], 5), 'hex')],
                               cc_frames($pdo, (int)$x['id']));
    $pub['palette'] = cc_palette_hex($x['palette'], 8);
    $st = $pdo->prepare("SELECT DISTINCT b.id, b.title, b.kind FROM cc_cards c JOIN cc_boards b ON b.id = c.board_id WHERE c.asset_id = ? AND c.deleted_at IS NULL AND b.status <> 'deleted' ORDER BY b.id DESC LIMIT 20");
    $st->execute([(int)$x['id']]);
    $pub['boards'] = array_map(fn($r) => ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'kind' => $r['kind']], $st->fetchAll());
    if ($x['deal_id']) {
        $d = $pdo->query('SELECT code, name, disclose FROM deals WHERE id = ' . (int)$x['deal_id'])->fetch();
        if ($d) $pub['deal'] = ['code' => (string)$d['code'], 'name' => (string)$d['name'], 'disclose' => (string)($d['disclose'] ?? '')];
    }
    $pub['vocab'] = array_map(fn($k) => ['key' => $k, 'label' => CC_VOCAB[$k][0], 'values' => cc_vocab($pdo, $k)], array_keys(CC_VOCAB));
    cout(200, ['ok' => true, 'asset' => $pub]);
}

case 'similar': {
    require_once __DIR__ . '/inc/cc_ai.php';
    $x = cc_asset_get($pdo, rint('id'));
    if (!$x) cfail('Referência não encontrada.', 404);
    release_session();
    $rows = cc_more_like($pdo, $x, 24);
    cout(200, ['ok' => true, 'items' => array_map(fn($r) => cc_asset_public($r[0]) + ['score' => round($r[1], 3)], $rows), 'by' => $x['embedding'] ? 'vetores' : 'etiquetas']);
}

case 'comments': {
    $b = board_req($pdo);
    cout(200, ['ok' => true, 'comments' => cc_comments($pdo, (int)$b['id'], rint('card') ?: null)]);
}

case 'jobs': {
    release_session();
    $ids = array_slice(array_values(array_filter(array_map('intval', explode(',', (string)rq('ids'))))), 0, 300);
    $out = [];
    if ($ids) foreach ($pdo->query('SELECT * FROM cc_assets WHERE id IN (' . implode(',', $ids) . ')')->fetchAll() as $x) $out[] = cc_asset_public($x);
    cout(200, ['ok' => true, 'items' => $out, 'pending' => cc_jobs_pending($pdo)]);
}

case 'deals': {
    $q = trim((string)rq('q'));
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $st = $pdo->prepare("SELECT id, code, name, phase FROM deals WHERE phase IN ('pipeline','production','done') AND (code LIKE ? OR name LIKE ?) ORDER BY id DESC LIMIT 20");
    $st->execute([$like, $like]);
    cout(200, ['ok' => true, 'deals' => array_map(fn($d) => ['id' => (int)$d['id'], 'label' => trim($d['code'] . ' · ' . $d['name'], ' ·'), 'phase' => $d['phase']], $st->fetchAll())]);
}

// ── quadros ─────────────────────────────────────────────────

case 'board_new': {
    $title = mb_substr(trim((string)rq('title')), 0, 200);
    if ($title === '') cfail('Dê um nome ao quadro.');
    $parent = rint('parent') ? cc_board_get($pdo, rint('parent')) : null;
    $deal = rint('deal') ? (int)$pdo->query('SELECT id FROM deals WHERE id = ' . rint('deal'))->fetchColumn() : 0;
    $id = cc_board_create($pdo, ['title' => $title, 'kind' => $parent ? 'stage' : 'free', 'parent_id' => $parent ? (int)$parent['id'] : null,
                                 'deal_id' => $parent ? $parent['deal_id'] : ($deal ?: null), 'line_id' => $parent ? $parent['line_id'] : null], $by);
    if ($parent) {   // o sub-quadro aparece como cartão no quadro de cima
        [$x, $y] = cc_board_free_spot($pdo, (int)$parent['id']);
        cc_card_add($pdo, (int)$parent['id'], ['type' => 'board', 'ref_board_id' => $id, 'x' => (float)rq('x', $x), 'y' => (float)rq('y', $y), 'w' => 240, 'content' => ['title' => $title]], $by);
        cc_board_touch($pdo, (int)$parent['id']);
    }
    cout(200, ['ok' => true, 'id' => $id, 'msg' => 'Quadro criado.']);
}

case 'board_save': {
    $b = board_req($pdo, 'id');
    board_editable($b);
    $set = [];
    if (isset($_POST['title'])) { $t = mb_substr(trim((string)$_POST['title']), 0, 200); if ($t === '') cfail('O nome não pode ficar vazio.'); $set['title'] = $t; }
    if (isset($_POST['brief'])) $set['brief'] = mb_substr(trim((string)$_POST['brief']), 0, 12000);
    if (!$set) cfail('Nada para gravar.');
    $old = ['title' => $b['title'], 'brief' => $b['brief']];
    $sql = implode(', ', array_map(fn($k) => $k . ' = ?', array_keys($set)));
    $pdo->prepare("UPDATE cc_boards SET $sql WHERE id = ?")->execute([...array_values($set), (int)$b['id']]);
    if (isset($set['title'])) {
        $pdo->prepare("UPDATE cc_cards SET content = ? WHERE ref_board_id = ? AND type = 'board'")->execute([json_encode(['title' => $set['title']], JSON_UNESCAPED_UNICODE), (int)$b['id']]);
    }
    cout(200, ['ok' => true, 'rev' => cc_board_touch($pdo, (int)$b['id']), 'old' => $old]);
}

case 'board_archive': {
    $b = board_req($pdo, 'id');
    $on = rq('on') === '1';
    $pdo->prepare('UPDATE cc_boards SET status = ?, archived_why = ?, updated_at = ? WHERE id = ?')
        ->execute([$on ? 'archived' : 'active', $on ? 'manual' : '', cc_now(), (int)$b['id']]);
    cout(200, ['ok' => true, 'msg' => $on ? 'Quadro arquivado.' : 'Quadro reaberto.']);
}

case 'board_delete': {
    if (!can('criativo', PERM_MANAGE)) cfail('Só quem gere o Centro Criativo apaga quadros.', 403);
    $b = board_req($pdo, 'id');
    $restore = rq('restore') === '1';
    $pdo->prepare('UPDATE cc_boards SET status = ?, updated_at = ? WHERE id = ?')->execute([$restore ? 'active' : 'deleted', cc_now(), (int)$b['id']]);
    $pdo->prepare('UPDATE cc_cards SET deleted_at = ' . ($restore ? 'NULL' : '?') . " WHERE ref_board_id = ? AND type = 'board'")
        ->execute($restore ? [(int)$b['id']] : [cc_now(), (int)$b['id']]);
    cout(200, ['ok' => true, 'msg' => $restore ? 'Quadro reposto.' : 'Quadro apagado.']);
}

case 'project_create': {
    require_once __DIR__ . '/inc/cc_flow.php';
    $deal = deal_get($pdo, rint('deal'));
    if (!$deal) cfail('Negócio não encontrado.', 404);
    if (rq('kind') === 'precreative') {
        if (empty($deal['xray_validated_at'])) cfail('O pré-criativo nasce com o X-Ray validado.');
        $id = cc_on_xray_validated($pdo, $deal, $by);
        if (!$id) cfail('O pré-criativo está desligado no funil deste negócio (Definições › Centro Criativo).');
    } else {
        $id = cc_on_kickoff($pdo, $deal, $by);
    }
    cout(200, ['ok' => true, 'id' => $id, 'msg' => 'Quadro criado.']);
}

// ── cartões ─────────────────────────────────────────────────

case 'card_add': {
    $b = board_req($pdo);
    board_editable($b);
    $type = (string)rq('type');
    if (!in_array($type, CC_CARD_TYPES, true)) cfail('Tipo de cartão inválido.');
    $d = ['type' => $type, 'x' => (float)rq('x'), 'y' => (float)rq('y'), 'w' => rq('w') !== '' ? (float)rq('w') : null, 'h' => (float)rq('h'),
          'lane' => (string)rq('lane'), 'content' => json_decode((string)rq('content', '{}'), true) ?: []];
    if ($d['w'] === null) unset($d['w']);
    if (rint('asset')) {
        $x = cc_asset_get($pdo, rint('asset'));
        if (!$x || $x['deleted_at']) cfail('Referência não encontrada.', 404);
        $d['asset_id'] = (int)$x['id'];
    }
    if (rint('column')) $d['column_id'] = card_ids($pdo, (int)$b['id'], [rint('column')])[0] ?? null;
    if ($type === 'board') {
        // um sub-quadro novo, debaixo deste
        $title = mb_substr(trim((string)($d['content']['title'] ?? '')), 0, 200) ?: 'Sub-quadro';
        $d['ref_board_id'] = cc_board_create($pdo, ['title' => $title, 'kind' => 'stage', 'parent_id' => (int)$b['id'], 'deal_id' => $b['deal_id'], 'line_id' => $b['line_id']], $by);
    }
    $id = cc_card_add($pdo, (int)$b['id'], $d, $by);
    $rev = cc_board_touch($pdo, (int)$b['id']);
    $c = cc_card_get($pdo, $id);
    cout(200, ['ok' => true, 'card' => cc_card_public($c, $c['asset_id'] ? cc_asset_get($pdo, (int)$c['asset_id']) : null), 'rev' => $rev]);
}

case 'cards_update': {
    $b = board_req($pdo);
    board_editable($b);
    $items = json_decode((string)rq('items', '[]'), true);
    if (!is_array($items) || count($items) > 2000) cfail('Pedido inválido.');
    $valid = array_flip(card_ids($pdo, (int)$b['id'], array_column($items, 'id')));
    $types = [];
    foreach ($pdo->query('SELECT id, type FROM cc_cards WHERE board_id = ' . (int)$b['id'])->fetchAll() as $r) $types[(int)$r['id']] = $r['type'];
    $pdo->beginTransaction();
    $now = cc_now();
    foreach ($items as $it) {
        $id = (int)($it['id'] ?? 0);
        if (!isset($valid[$id])) continue;
        $set = [];
        foreach (['x', 'y'] as $k) if (isset($it[$k]) && is_numeric($it[$k])) $set[$k] = round(max(-1e6, min(1e6, (float)$it[$k])), 1);
        if (isset($it['w']) && is_numeric($it['w'])) $set['w'] = max(40, min(4000, (float)$it['w']));
        if (isset($it['h']) && is_numeric($it['h'])) $set['h'] = max(0, min(6000, (float)$it['h']));
        if (isset($it['z']) && is_numeric($it['z'])) $set['z'] = (int)$it['z'];
        if (isset($it['ord']) && is_numeric($it['ord'])) $set['ord'] = (int)$it['ord'];
        if (array_key_exists('col', $it)) {
            $col = (int)$it['col'];
            $set['column_id'] = $col && $col !== $id && ($types[$col] ?? '') === 'column' ? $col : null;
        }
        if (isset($it['lane'])) $set['lane'] = in_array($it['lane'], ['A', 'B', ''], true) ? $it['lane'] : '';
        if (isset($it['content'])) $set['content'] = json_encode(cc_card_content($types[$id] ?? 'note', $it['content']), JSON_UNESCAPED_UNICODE);
        if (!$set) continue;
        $set['updated_by'] = $by;
        $set['updated_at'] = $now;
        $sql = implode(', ', array_map(fn($k) => $k . ' = ?', array_keys($set)));
        $pdo->prepare("UPDATE cc_cards SET $sql WHERE id = ?")->execute([...array_values($set), $id]);
        // o título de um sub-quadro segue o do cartão
        if (($types[$id] ?? '') === 'board' && isset($it['content']['title'])) {
            $ref = (int)$pdo->query('SELECT ref_board_id FROM cc_cards WHERE id = ' . $id)->fetchColumn();
            $t = mb_substr(trim((string)$it['content']['title']), 0, 200);
            if ($ref && $t !== '') $pdo->prepare('UPDATE cc_boards SET title = ? WHERE id = ?')->execute([$t, $ref]);
        }
    }
    $pdo->commit();
    cout(200, ['ok' => true, 'rev' => cc_board_touch($pdo, (int)$b['id'])]);
}

case 'cards_delete':
case 'cards_restore': {
    $b = board_req($pdo);
    board_editable($b);
    $ids = card_ids($pdo, (int)$b['id'], rq('ids', []));
    if (!$ids) cfail('Nada selecionado.');
    $del = $a === 'cards_delete';
    // apagar uma coluna solta os cartões dela (ficam onde estavam); repor volta a pô-los
    $pdo->prepare('UPDATE cc_cards SET deleted_at = ' . ($del ? '?' : 'NULL') . ' WHERE board_id = ? AND id IN (' . implode(',', $ids) . ')')
        ->execute($del ? [cc_now(), (int)$b['id']] : [(int)$b['id']]);
    // setas presas a cartões apagados também saem
    if ($del) {
        foreach ($pdo->query("SELECT id, content FROM cc_cards WHERE type = 'arrow' AND deleted_at IS NULL AND board_id = " . (int)$b['id'])->fetchAll() as $ar) {
            $c = cc_card_content('arrow', $ar['content']);
            if (in_array($c['from'], $ids, true) || in_array($c['to'], $ids, true)) {
                $pdo->prepare('UPDATE cc_cards SET deleted_at = ? WHERE id = ?')->execute([cc_now(), (int)$ar['id']]);
                $ids[] = (int)$ar['id'];
            }
        }
    }
    cout(200, ['ok' => true, 'ids' => $ids, 'rev' => cc_board_touch($pdo, (int)$b['id']),
               'msg' => $del ? (count($ids) === 1 ? 'Cartão apagado.' : count($ids) . ' cartões apagados.') : 'Reposto.']);
}

case 'cards_dup': {
    $b = board_req($pdo);
    board_editable($b);
    $ids = card_ids($pdo, (int)$b['id'], rq('ids', []));
    $new = [];
    foreach ($ids as $id) {
        $c = cc_card_get($pdo, $id);
        if (!$c || $c['deleted_at'] || in_array($c['type'], ['arrow', 'board'], true)) continue;
        $nid = cc_card_add($pdo, (int)$b['id'], ['type' => $c['type'], 'asset_id' => $c['asset_id'], 'lane' => $c['lane'], 'column_id' => $c['column_id'],
                                                 'x' => (float)$c['x'] + 30, 'y' => (float)$c['y'] + 30, 'w' => (float)$c['w'], 'h' => (float)$c['h'],
                                                 'ord' => (int)$c['ord'] + 1, 'content' => cc_card_content((string)$c['type'], $c['content'])], $by);
        $n = cc_card_get($pdo, $nid);
        $new[] = cc_card_public($n, $n['asset_id'] ? cc_asset_get($pdo, (int)$n['asset_id']) : null);
    }
    cout(200, ['ok' => true, 'cards' => $new, 'rev' => cc_board_touch($pdo, (int)$b['id'])]);
}

case 'card_swap': {   // «trocar» no pré-criativo: outra referência parecida, que ainda não esteja no quadro
    require_once __DIR__ . '/inc/cc_ai.php';
    $b = board_req($pdo);
    board_editable($b);
    $c = cc_card_get($pdo, rint('card'));
    if (!$c || (int)$c['board_id'] !== (int)$b['id'] || !$c['asset_id']) cfail('Cartão inválido.');
    $x = cc_asset_get($pdo, (int)$c['asset_id']);
    release_session();
    $have = array_map('intval', $pdo->query('SELECT asset_id FROM cc_cards WHERE board_id = ' . (int)$b['id'] . ' AND deleted_at IS NULL AND asset_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
    $vec = cc_vec_unpack($x['embedding'] ?? null);
    $sc = cc_nearest($pdo, $vec, 1, $have, $vec ? null : $x);
    if (!$sc) {
        $rows = cc_refs_for_text($pdo, cc_brief_text($pdo, $b), 1, $have);
        $new = $rows[0] ?? null;
    } else {
        $new = cc_asset_get($pdo, (int)array_key_first($sc));
    }
    if (!$new) cfail('Não há mais referências parecidas na biblioteca.');
    $content = cc_card_content((string)$c['type'], $c['content']);
    $content['state'] = '';
    $ratio = $new['width'] && $new['height'] ? (float)$new['height'] / (float)$new['width'] : 0.62;
    $pdo->prepare('UPDATE cc_cards SET asset_id = ?, type = ?, h = ?, content = ?, updated_by = ?, updated_at = ? WHERE id = ?')
        ->execute([(int)$new['id'], $new['kind'] === 'video' ? 'video' : ($new['kind'] === 'link' ? 'link' : 'image'), round((float)$c['w'] * min(1.4, $ratio)),
                   json_encode($content, JSON_UNESCAPED_UNICODE), $by, cc_now(), (int)$c['id']]);
    $c = cc_card_get($pdo, (int)$c['id']);
    cout(200, ['ok' => true, 'card' => cc_card_public($c, $new), 'old' => (int)$x['id'], 'rev' => cc_board_touch($pdo, (int)$b['id'])]);
}

// ── ficheiros e links ───────────────────────────────────────

case 'upload_init': {
    $size = max(0, rint('size'));
    $fp = (string)rq('fp');
    if (!preg_match('/^[a-f0-9]{40}$/', $fp)) cfail('Pedido inválido.');
    if ($dup = cc_asset_by_fp($pdo, $fp)) cout(200, ['ok' => true, 'dup' => cc_asset_public($dup)]);
    $name = r2_clean_name((string)rq('name')) ?: 'ficheiro';
    if (r2_config()) {
        $key = cc_r2_orig_key($fp, $name);
        cout(200, ['ok' => true, 'mode' => 'r2', 'key' => $key, 'put' => r2_presign('PUT', $key, R2_URL_TTL)]);
    }
    $max = min(cc_ini_bytes(ini_get('upload_max_filesize')), cc_ini_bytes(ini_get('post_max_size')) - 2097152);
    if ($size > $max) cfail('Este ficheiro tem ' . round($size / 1048576) . ' MB e, sem o Drive ligado, o servidor só aceita até ' . max(1, (int)floor($max / 1048576)) . ' MB.');
    cout(200, ['ok' => true, 'mode' => 'php', 'max' => $max]);
}

case 'upload_done': {
    $b = rint('board') ? board_req($pdo) : null;
    if ($b) board_editable($b);
    release_session();
    $name = r2_clean_name((string)rq('name')) ?: 'ficheiro';
    $o = ['name' => $name, 'mime' => (string)rq('mime'), 'kind' => (string)rq('kind'), 'by' => $by, 'inbox' => !$b && rq('inbox') === '1',
          'w' => rint('w'), 'h' => rint('h'), 'duration' => rq('dur'), 'size' => rint('size'), 'fp' => (string)rq('fp'),
          'origin' => rq('origin') === 'topper' ? 'topper' : 'external'];
    if (!empty($_FILES['prev']['tmp_name']) && is_uploaded_file($_FILES['prev']['tmp_name']) && $_FILES['prev']['size'] < 12582912) {
        $o['preview'] = (string)file_get_contents($_FILES['prev']['tmp_name']);
    }
    $bytes = null;
    if (!empty($_FILES['orig']['tmp_name']) && is_uploaded_file($_FILES['orig']['tmp_name'])) {
        $bytes = (string)file_get_contents($_FILES['orig']['tmp_name']);
    } elseif (r2_config() && ($key = (string)rq('key')) !== '') {
        if (!preg_match('~^\.topper/cc/o/[a-f0-9]{2}/[a-f0-9]{40}(\.[a-z0-9]{1,5})?$~', $key)) cfail('Pedido inválido.');
        $head = r2_head($key);
        if (!$head) cfail('O envio não chegou ao armazenamento. Tente outra vez.');
        $o['file_key'] = 'r2:' . $key;
        $o['size'] = (int)($head['size'] ?? $o['size']);
        // uma imagem sem pré-visualização: o servidor lê-a (até 40 MB) para fazer as miniaturas
        if (empty($o['preview']) && cc_kind_for($o['mime'], $name) === 'image' && $o['size'] <= 41943040) $o['preview'] = r2_get($key, null, 41943040);
    } else {
        cfail('Falta o ficheiro.');
    }
    if ($bytes !== null && strlen($bytes) === 0) cfail('O ficheiro está vazio.');
    [$x, $new] = cc_asset_create($pdo, $bytes, $o);
    // o original vai para o Drive: a pasta do negócio do quadro, ou a Biblioteca criativa
    if ($new) $x = cc_place_original($pdo, $x, $b, (bool)$o['inbox'], $by);
    // frames-chave de um vídeo (extraídos no browser por deteção de corte)
    if ($new && $x['kind'] === 'video' && !empty($_FILES['frames']['tmp_name']) && is_array($_FILES['frames']['tmp_name'])) {
        $ts = (array)rq('t', []);
        $n = 0;
        foreach ($_FILES['frames']['tmp_name'] as $i => $tmp) {
            if ($n >= 12 || !is_uploaded_file($tmp) || $_FILES['frames']['size'][$i] > 6291456) continue;
            if (cc_frame_add($pdo, $x, (string)file_get_contents($tmp), (float)($ts[$i] ?? 0), $n)) $n++;
        }
        require_once __DIR__ . '/inc/cc_ai.php';
        $cfg = cc_ai_config();
        if ($cfg && $cfg['auto'] && ($n || $x['thumbs'])) {
            $pdo->prepare("UPDATE cc_assets SET ai_status = 'queued' WHERE id = ?")->execute([(int)$x['id']]);
            cc_job_add($pdo, 'analyze', (int)$x['id'], null, null, $by);
        }
        $x = cc_asset_get($pdo, (int)$x['id']);
    }
    $card = null;
    if ($b) {
        $ratio = $x['width'] && $x['height'] ? (float)$x['height'] / (float)$x['width'] : 0.62;
        $w = (float)rq('cw', 280);
        $cid = cc_card_add($pdo, (int)$b['id'], ['type' => in_array($x['kind'], ['image', 'video', 'link'], true) ? $x['kind'] : 'file', 'asset_id' => (int)$x['id'],
            'x' => (float)rq('x'), 'y' => (float)rq('y'), 'w' => $w, 'h' => $x['kind'] === 'file' ? 0 : round($w * min(2.5, $ratio)),
            'lane' => (string)rq('lane'), 'column_id' => rint('column') ? (card_ids($pdo, (int)$b['id'], [rint('column')])[0] ?? null) : null, 'content' => []], $by);
        $c = cc_card_get($pdo, $cid);
        $card = cc_card_public($c, $x);
        cc_board_touch($pdo, (int)$b['id']);
    } elseif (!$new && rq('inbox') === '1' && !(int)$x['inbox']) {
        $pdo->prepare('UPDATE cc_assets SET inbox = 1 WHERE id = ?')->execute([(int)$x['id']]);
        $x = cc_asset_get($pdo, (int)$x['id']);
    }
    cout(200, ['ok' => true, 'asset' => cc_asset_public($x), 'card' => $card, 'new' => $new]);
}

case 'link_add': {
    $b = rint('board') ? board_req($pdo) : null;
    if ($b) board_editable($b);
    $url = trim((string)rq('url'));
    if (!preg_match('~^https?://\S+$~i', $url)) cfail('Endereço inválido.');
    release_session();
    [$x] = cc_asset_from_link($pdo, $url, ['by' => $by, 'inbox' => !$b && rq('inbox') === '1']);
    $card = null;
    if ($b) {
        $ratio = $x['width'] && $x['height'] ? (float)$x['height'] / (float)$x['width'] : 0.52;
        $cid = cc_card_add($pdo, (int)$b['id'], ['type' => 'link', 'asset_id' => (int)$x['id'], 'x' => (float)rq('x'), 'y' => (float)rq('y'), 'w' => 300,
                                                 'h' => $x['thumbs'] ? round(300 * min(1.2, $ratio)) : 0, 'lane' => (string)rq('lane'), 'content' => []], $by);
        $card = cc_card_public(cc_card_get($pdo, $cid), $x);
        cc_board_touch($pdo, (int)$b['id']);
    }
    cout(200, ['ok' => true, 'asset' => cc_asset_public($x), 'card' => $card]);
}

case 'asset_save': {
    $x = cc_asset_get($pdo, rint('id'));
    if (!$x || $x['deleted_at']) cfail('Referência não encontrada.', 404);
    $in = [];
    foreach (['title', 'caption', 'why', 'composition', 'color', 'movement', 'tags', 'origin', ...array_keys(CC_VOCAB)] as $k) if (isset($_POST[$k])) $in[$k] = (string)$_POST[$k];
    foreach (array_keys(CC_VOCAB) as $k) if (isset($in[$k]) && $in[$k] !== '' && !in_array($in[$k], cc_vocab($pdo, $k), true)) cfail('«' . $in[$k] . '» não está no vocabulário (Definições › Centro Criativo).');
    cc_asset_edit($pdo, $x, $in, $by);
    cout(200, ['ok' => true, 'asset' => cc_asset_public(cc_asset_get($pdo, (int)$x['id']), true), 'msg' => 'Guardado.']);
}

case 'asset_delete':
case 'asset_restore': {
    $x = cc_asset_get($pdo, rint('id'));
    if (!$x) cfail('Referência não encontrada.', 404);
    $del = $a === 'asset_delete';
    $pdo->prepare('UPDATE cc_assets SET deleted_at = ' . ($del ? '?' : 'NULL') . ' WHERE id = ?')->execute($del ? [cc_now(), (int)$x['id']] : [(int)$x['id']]);
    // os quadros onde estava atualizam-se (o cartão deixa de aparecer; repor volta a mostrá-lo)
    foreach ($pdo->query('SELECT DISTINCT board_id FROM cc_cards WHERE asset_id = ' . (int)$x['id'])->fetchAll(PDO::FETCH_COLUMN) as $bid) cc_board_touch($pdo, (int)$bid);
    cout(200, ['ok' => true, 'msg' => $del ? 'Referência apagada da biblioteca e dos quadros.' : 'Referência reposta.']);
}

case 'inbox_done': {
    $ids = array_values(array_filter(array_map('intval', (array)rq('ids', []))));
    if (!$ids) cfail('Nada selecionado.');
    $b = rint('board') ? board_req($pdo) : null;
    if ($b) board_editable($b);
    $pdo->prepare('UPDATE cc_assets SET inbox = ' . (rq('back') === '1' ? 1 : 0) . ' WHERE id IN (' . implode(',', $ids) . ')')->execute();
    $n = 0;
    if ($b) {
        [$x0, $y0] = cc_board_free_spot($pdo, (int)$b['id']);
        foreach (cc_assets_by_ids($pdo, $ids) as $i => $x) {
            $ratio = $x['width'] && $x['height'] ? (float)$x['height'] / (float)$x['width'] : 0.62;
            cc_card_add($pdo, (int)$b['id'], ['type' => in_array($x['kind'], ['image', 'video', 'link'], true) ? $x['kind'] : 'file', 'asset_id' => (int)$x['id'],
                'x' => $x0 + ($i % 5) * 300, 'y' => $y0 + intdiv($i, 5) * 300, 'w' => 280, 'h' => $x['kind'] === 'file' ? 0 : round(280 * min(1.6, $ratio)), 'content' => []], $by);
            $n++;
        }
        cc_board_touch($pdo, (int)$b['id']);
    }
    cout(200, ['ok' => true, 'msg' => $b ? ($n === 1 ? '1 referência no quadro «' . $b['title'] . '».' : $n . ' referências no quadro «' . $b['title'] . '».') : 'Arrumado na biblioteca.']);
}

// ── IA ──────────────────────────────────────────────────────

case 'analyze': {
    require_once __DIR__ . '/inc/cc_ai.php';
    $x = cc_asset_get($pdo, rint('id'));
    if (!$x) cfail('Referência não encontrada.', 404);
    if (!cc_ai_config()) cfail('A IA do Centro Criativo ainda não está ligada (Definições › Integrações).');
    $pdo->prepare("UPDATE cc_assets SET ai_status = 'queued', ai_error = '' WHERE id = ?")->execute([(int)$x['id']]);
    cc_job_add($pdo, 'analyze', (int)$x['id'], null, null, $by);
    cout(200, ['ok' => true, 'msg' => 'Na fila da IA.']);
}

case 'analyze_all': {
    require_once __DIR__ . '/inc/cc_ai.php';
    if (!can('criativo', PERM_MANAGE)) cfail('Só quem gere o Centro Criativo.', 403);
    if (!cc_ai_config()) cfail('A IA do Centro Criativo ainda não está ligada (Definições › Integrações).');
    $ids = $pdo->query("SELECT id FROM cc_assets WHERE deleted_at IS NULL AND thumbs IS NOT NULL AND ai_status IN ('', 'error', 'off') LIMIT 500")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->prepare("UPDATE cc_assets SET ai_status = 'queued' WHERE id = ?")->execute([(int)$id]);
        cc_job_add($pdo, 'analyze', (int)$id, null, null, $by);
    }
    cout(200, ['ok' => true, 'msg' => count($ids) === 1 ? '1 referência na fila.' : count($ids) . ' referências na fila.']);
}

case 'work': {   // o browser vai pedindo enquanto houver fila (um pedido de cada vez)
    release_session();
    $n = cc_jobs_run($pdo, 22.0);
    cout(200, ['ok' => true, 'done' => $n, 'pending' => cc_jobs_pending($pdo)]);
}

case 'ai': {
    require_once __DIR__ . '/inc/cc_ai.php';
    $b = board_req($pdo);
    $act = (string)rq('act');
    $edit = in_array($act, ['tidy', 'shots_tasks'], true);
    if ($edit) board_editable($b);
    release_session();
    switch ($act) {
        case 'angles':
            $angles = cc_ai_angles($pdo, $b, 3, mb_substr((string)rq('avoid'), 0, 3000));
            cout(200, ['ok' => true, 'angles' => $angles]);
        case 'refs':
            $have = array_map('intval', $pdo->query('SELECT asset_id FROM cc_cards WHERE board_id = ' . (int)$b['id'] . ' AND deleted_at IS NULL AND asset_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
            $text = trim((string)rq('text')) ?: cc_brief_text($pdo, $b);
            if ($text === '') cfail('Escreva primeiro o brief do quadro.');
            $rows = cc_refs_for_text($pdo, $text, 18, $have);
            cout(200, ['ok' => true, 'items' => array_map(fn($r) => cc_asset_public($r), $rows), 'by' => cc_embed_config() ? 'vetores' : 'texto']);
        case 'tidy':
            $n = cc_ai_tidy($pdo, $b, $by);
            cout(200, ['ok' => true, 'msg' => $n . ' cartões arrumados em colunas.']);
        case 'treatment':
            $doc = cc_ai_treatment($pdo, $b);
            cout(200, ['ok' => true, 'doc' => $doc, 'url' => '/admin/criativo.php?print=treatment&b=' . (int)$b['id']]);
        case 'shots':
            $shots = cc_ai_shotlist($pdo, $b);
            // a lista fica no quadro como cartão de lista
            [$x, $y] = cc_board_free_spot($pdo, (int)$b['id']);
            $items = array_map(fn($s, $i) => ['t' => ($i + 1) . '. ' . trim((string)$s['title']) . ' — ' . trim((string)$s['description']) . ' · ' . trim((string)$s['camera']) . ' · ' . rtrim(rtrim(number_format((float)$s['seconds'], 1, ',', ''), '0'), ',') . ' s', 'd' => false], $shots, array_keys($shots));
            $cid = cc_card_add($pdo, (int)$b['id'], ['type' => 'list', 'x' => $x, 'y' => $y, 'w' => 420, 'content' => ['title' => 'Lista de planos', 'items' => $items]], $by);
            cc_board_touch($pdo, (int)$b['id']);
            cout(200, ['ok' => true, 'shots' => $shots, 'card' => cc_card_public(cc_card_get($pdo, $cid))]);
        case 'shots_tasks':
            // «→ tarefas»: uma tarefa por plano, no negócio do quadro, para o responsável de execução
            require_once __DIR__ . '/inc/cc_flow.php';
            if (!$b['deal_id'] || !($deal = deal_get($pdo, (int)$b['deal_id']))) cfail('Este quadro não está ligado a um negócio.');
            if (!can('tarefas', PERM_EDIT)) cfail('A sua conta não cria tarefas.', 403);
            $c = cc_card_get($pdo, rint('card'));
            if (!$c || (int)$c['board_id'] !== (int)$b['id'] || $c['type'] !== 'list') cfail('Escolha a lista de planos.');
            [, $exec] = deal_roles($deal);
            $n = 0;
            foreach (cc_card_content('list', $c['content'])['items'] as $it) {
                if (trim($it['t']) === '') continue;
                cc_task_create($pdo, $deal, 'Plano ' . mb_strimwidth($it['t'], 0, 120, '…'), $it['t'] . "\n\nDo quadro «" . $b['title'] . '»: /admin/criativo.php?b=' . (int)$b['id'],
                               $b['exec_owner_id'] ? (int)$b['exec_owner_id'] : $exec, today_lisbon(), $by);
                $n++;
            }
            if ($n) deal_log($pdo, (int)$deal['id'], 'auto', ['note' => $n . ' tarefas criadas da lista de planos (Centro Criativo)']);
            cout(200, ['ok' => true, 'msg' => $n === 1 ? '1 tarefa criada.' : $n . ' tarefas criadas no negócio.']);
    }
    cfail('Ação desconhecida.');
}

case 'prepare': {
    require_once __DIR__ . '/inc/cc_ai.php';
    $b = board_req($pdo);
    board_editable($b);
    if (!in_array($b['kind'], ['precreative', 'front'], true)) cfail('«Preparar com IA» é do pré-criativo.');
    release_session();
    $r = cc_ai_prepare($pdo, $b, $by);
    cout(200, ['ok' => true, 'msg' => $r['angles'] . ' ângulos e ' . ($r['refs']['A'] + $r['refs']['B']) . ' referências nas duas faixas. Mantenha, tire ou troque.']);
}

case 'page_gen': {
    require_once __DIR__ . '/inc/cc_ai.php';
    $b = board_req($pdo);
    board_editable($b);
    $lane = (string)rq('lane');
    if (!in_array($b['kind'], ['precreative', 'front'], true) || !isset(CC_LANES[$lane])) cfail('Pedido inválido.');
    release_session();
    $d = cc_page_generate($pdo, $b, $lane, $by);
    $d['refs_view'] = array_map(fn($x) => cc_asset_public($x), cc_assets_by_ids($pdo, $d['refs']));
    cout(200, ['ok' => true, 'page' => $d, 'msg' => 'Página da ' . CC_LANES[$lane] . ' gerada' . ($d['ai'] ? '' : ' (sem IA: a partir das notas)') . '.']);
}

case 'page_both': {
    $b = board_req($pdo);
    board_editable($b);
    $lane = (string)rq('lane');
    if (!isset(CC_LANES[$lane])) cfail('Pedido inválido.');
    $on = rq('on') === '1';
    $other = $lane === 'A' ? 'B' : 'A';
    $st = $pdo->prepare('SELECT id FROM cc_pages WHERE board_id = ? AND lane = ?');
    $st->execute([(int)$b['id'], $lane]);
    if (!($pid = $st->fetchColumn())) cfail('Gere primeiro a página desta faixa.');
    $pdo->prepare('UPDATE cc_pages SET serves_both = ? WHERE id = ?')->execute([$on ? 1 : 0, (int)$pid]);
    if ($on) $pdo->prepare('UPDATE cc_pages SET serves_both = 0 WHERE board_id = ? AND lane = ?')->execute([(int)$b['id'], $other]);
    cc_board_touch($pdo, (int)$b['id']);
    cout(200, ['ok' => true, 'msg' => $on ? 'A página da ' . CC_LANES[$lane] . ' serve as duas opções.' : 'Cada faixa volta à sua opção.']);
}

case 'send_budget': {
    require_once __DIR__ . '/inc/cc_flow.php';
    $b = board_req($pdo);
    if (!in_array($b['kind'], ['precreative', 'front'], true)) cfail('Só o pré-criativo vai para a Orçamentação.');
    $msg = cc_send_to_budget($pdo, $b, $by);
    cc_board_touch($pdo, (int)$b['id']);
    cout(200, ['ok' => true, 'msg' => $msg]);
}

// ── comentários ─────────────────────────────────────────────

case 'comment_add': {
    $b = board_req($pdo);
    $c = rint('card') ? cc_card_get($pdo, rint('card')) : null;
    if ($c && (int)$c['board_id'] !== (int)$b['id']) cfail('Pedido inválido.');
    cc_comment_add($pdo, $b, $c, (string)rq('body'), $user);
    cc_board_touch($pdo, (int)$b['id']);
    cout(200, ['ok' => true, 'comments' => cc_comments($pdo, (int)$b['id'], $c ? (int)$c['id'] : null)]);
}

case 'comment_del':
case 'comment_resolve': {
    $st = $pdo->prepare('SELECT * FROM cc_comments WHERE id = ?');
    $st->execute([rint('id')]);
    $cm = $st->fetch();
    if (!$cm) cfail('Comentário não encontrado.', 404);
    if ($a === 'comment_del' && (int)$cm['user_id'] !== $by && !can('criativo', PERM_MANAGE)) cfail('Só quem escreveu (ou quem gere) apaga.', 403);
    if ($a === 'comment_del') $pdo->prepare('UPDATE cc_comments SET deleted_at = ? WHERE id = ?')->execute([cc_now(), (int)$cm['id']]);
    else $pdo->prepare('UPDATE cc_comments SET resolved_at = ' . ($cm['resolved_at'] ? 'NULL' : '?') . ' WHERE id = ?')->execute($cm['resolved_at'] ? [(int)$cm['id']] : [cc_now(), (int)$cm['id']]);
    cout(200, ['ok' => true, 'comments' => cc_comments($pdo, (int)$cm['board_id'], $cm['card_id'] ? (int)$cm['card_id'] : null)]);
}

// ── importação (mecanismo pronto; nada importado ainda) ─────

case 'import': {
    require_once __DIR__ . '/inc/cc_import.php';
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) cfail('Escolha o ficheiro (ZIP, PDF, Markdown ou imagens).');
    release_session();
    $r = cc_import_upload($pdo, $_FILES['file'], (string)rq('title'), $by);
    cout(200, ['ok' => true, 'board' => $r['board'], 'msg' => $r['msg']]);
}

default:
    cfail('Ação desconhecida.');
}
} catch (CcAiException $e) {
    cfail($e->getMessage(), in_array($e->kind, ['not_configured', 'budget'], true) ? 409 : 502);
} catch (RuntimeException $e) {
    cfail($e->getMessage());
} catch (Throwable $e) {
    error_log('cc_api ' . $a . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    cfail('Não foi possível concluir. Tente outra vez.', 500);
}

function cc_ini_bytes($v): int {
    $v = trim((string)$v);
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return $n;
}
