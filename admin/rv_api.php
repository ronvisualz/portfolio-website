<?php
/**
 * topper. admin — pedidos do Review e do link do portal (Centro Criativo, fase 2). Respostas em JSON.
 *
 * Ler (aba Centro Criativo, ver):
 *   a=items        deal, q, status, open — as peças (separador Review, cartão da ficha)
 *   a=room         item — a sala: peça, versões, comentários, rondas, histórico
 *   a=poll         item, rev — mudou entretanto?
 *   a=media        v, k (proxy|poster|strip|page|orig|wm), p — redireciona para um URL curto
 *   a=att          c, i — um anexo de um comentário
 *   a=export       v, fmt (pdf|csv|edl|xml|avid), client — os comentários para a edição
 *   a=deals        q · a=lines deal — para criar uma peça
 *   a=share        deal — o link do portal e os acessos
 * Mudar (editar o Centro Criativo; o link do portal também com «editar» em Negócios), com o token CSRF:
 *   item_new, item_save, upload_init, upload_done, version_retry, att_init
 *   comment_add, comment_resolve, comment_del, comment_restore, comment_vis
 *   send, changes, approve
 *   share_create, share_save, share_revoke, share_link
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/cc_review.php';

function rout(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function rfail(string $msg, int $code = 400): void {
    rout($code, ['ok' => false, 'error' => $msg]);
}

if (current_user() === null) rout(401, ['ok' => false, 'error' => 'A sessão terminou. Entre outra vez.']);
$user = require_login();
$a = (string)($_REQUEST['a'] ?? '');
$by = (int)$user['id'];
$shareActs = ['share', 'share_create', 'share_save', 'share_revoke', 'share_link'];
$canShare = can('criativo', PERM_EDIT) || can('negocios', PERM_EDIT);
if (in_array($a, $shareActs, true) ? !(can('criativo') || can('negocios')) : !can('criativo')) rfail('A sua conta não tem acesso ao Centro Criativo.', 403);
if (!cc_rv_ready($pdo)) rfail('O Review ainda não está pronto (a base de dados vai atualizar no próximo acesso).', 503);

$reads = ['items', 'room', 'poll', 'media', 'att', 'export', 'deals', 'lines', 'share'];
if (!in_array($a, $reads, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') rfail('Pedido inválido.', 405);
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) rfail('Pedido inválido. Recarregue a página e tente outra vez.');
    if (in_array($a, $shareActs, true) ? !$canShare : !can('criativo', PERM_EDIT)) rfail('A sua conta vê o Review mas não o muda.', 403);
}
$EDIT = can('criativo', PERM_EDIT);

function q(string $k, $d = '') { return $_REQUEST[$k] ?? $d; }
function qi(string $k): int { return (int)($_REQUEST[$k] ?? 0); }

function item_req(PDO $pdo): array {
    $i = cc_rv_item($pdo, qi('item'));
    if (!$i) rfail('Esta peça já não existe.', 404);
    return $i;
}
function version_req(PDO $pdo, ?array $item = null): array {
    $v = cc_rv_version($pdo, qi('v'));
    if (!$v || ($item && (int)$v['item_id'] !== (int)$item['id'])) rfail('Esta versão já não existe.', 404);
    return $v;
}
function deal_req(PDO $pdo): array {
    $d = files_deal_row($pdo, qi('deal'));
    if (!$d) rfail('Negócio não encontrado.', 404);
    return $d;
}

/** O endereço de um ficheiro de uma versão, para a equipa (passa por aqui: revalida e redireciona). */
function team_url(array $v, string $k, int $p = 0): string {
    return '/admin/rv_api.php?a=media&v=' . (int)$v['id'] . '&k=' . $k . ($p ? '&p=' . $p : '');
}

/** A sala inteira (peça, versões, comentários, rondas, histórico, pessoas para @menções). */
function room(PDO $pdo, array $item): array {
    $deal = files_deal_row($pdo, (int)$item['deal_id']);
    $st = $pdo->prepare("SELECT d.code, d.name, COALESCE(co.name, NULLIF(d.company, ''), d.name) AS client FROM deals d LEFT JOIN contacts co ON co.id = d.company_id WHERE d.id = ?");
    $st->execute([(int)$item['deal_id']]);
    $dd = $st->fetch() ?: ['code' => '', 'name' => '', 'client' => ''];
    $lines = [];
    if (db_has_table($pdo, 'deal_lines')) {
        $st = $pdo->prepare('SELECT l.id, f.name FROM deal_lines l LEFT JOIN funnels f ON f.id = l.funnel_id WHERE l.deal_id = ? ORDER BY l.position, l.id');
        $st->execute([(int)$item['deal_id']]);
        $lines = array_map(fn($l) => ['id' => (int)$l['id'], 'name' => (string)$l['name']], $st->fetchAll());
    }
    $share = cc_share_for_deal($pdo, (int)$item['deal_id']);
    $folder = cc_rv_drive_folder($pdo, $item);
    return [
        'item' => cc_rv_item_out($pdo, $item, false),
        'deal' => ['id' => (int)$item['deal_id'], 'code' => (string)$dd['code'], 'name' => (string)$dd['name'], 'client' => (string)$dd['client'], 'lines' => $lines],
        'versions' => array_map(fn($v) => cc_rv_version_out($v, 'team_url', false), cc_rv_versions($pdo, (int)$item['id'])),
        'comments' => cc_rv_comments($pdo, $item, null, 'team'),
        'rounds' => cc_rv_round_info($pdo, $item),
        'tasks' => array_map(fn($t) => (string)$t['title'], cc_rv_approval_tasks($pdo, $item)),
        'log' => cc_rv_item_log($pdo, $item),
        'portal' => $share && cc_share_valid($share) ? ['until' => $share['expires_on'] ? date('d/m/Y', strtotime((string)$share['expires_on'])) : ''] : null,
        'drive' => $folder !== null && can('drive') ? drive_page($folder) : '',
        'ffmpeg' => cc_rv_ffmpeg_known(),
        'users' => array_map(fn($u) => ['id' => (int)$u['id'], 'name' => (string)$u['name']],
            $pdo->query("SELECT id, name FROM users WHERE is_active = 1 AND deactivated_at IS NULL AND role <> 'superadmin' ORDER BY name")->fetchAll()),
        'me' => (int)current_user()['id'], 'admin' => role_is_admin((string)current_user()['role']),
    ];
}

try {
switch ($a) {

// ── ler ─────────────────────────────────────────────────────
case 'items': {
    $rows = cc_rv_overview($pdo, ['deal' => qi('deal'), 'q' => (string)q('q'), 'status' => (string)q('status'), 'open' => q('open') === '1']);
    rout(200, ['ok' => true, 'items' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'title' => (string)$r['title'], 'stage' => CC_RV_STAGES[$r['stage_key']] ?? '', 'kind' => (string)$r['kind'],
        'status' => (string)$r['status'], 'statusLabel' => (CC_RV_STATUS[$r['status']] ?? CC_RV_STATUS['prep'])[0], 'color' => (CC_RV_STATUS[$r['status']] ?? CC_RV_STATUS['prep'])[2],
        'deal' => (int)$r['deal_id'], 'code' => (string)$r['code'], 'dealName' => (string)$r['deal_name'], 'client' => (string)$r['client'],
        'version' => $r['cur_num'] ? cc_rv_vlabel((int)$r['cur_num']) : '', 'proc' => (string)($r['cur_proc'] ?? ''),
        'poster' => $r['cur_poster'] ? team_url(['id' => $r['cur_id']], 'poster') : '', 'open' => (int)$r['open_n'], 'shared' => (int)$r['shared'] === 1,
        'updated' => date('d/m H:i', strtotime((string)$r['updated_at'])),
    ], $rows)]);
}

case 'room': {
    $item = item_req($pdo);
    rout(200, ['ok' => true] + room($pdo, $item));
}

case 'poll': {
    session_write_close();
    $item = item_req($pdo);
    rout(200, ['ok' => true, 'rev' => (int)$item['rev'], 'changed' => (int)$item['rev'] !== qi('rev')]);
}

case 'media': {
    session_write_close();
    $v = version_req($pdo);
    $k = (string)q('k');
    $key = match ($k) {
        'proxy' => (string)$v['proxy_key'], 'poster' => (string)$v['poster_key'], 'strip' => (string)$v['strip_key'],
        'wm' => (string)$v['wm_key'], 'orig' => (string)$v['file_key'],
        'page' => (string)((json_decode((string)$v['pages'], true) ?: [])[max(1, qi('p')) - 1]['k'] ?? ''),
        default => '',
    };
    if ($key === '') { http_response_code(404); exit; }
    $video = in_array($k, ['proxy', 'wm'], true) && cc_rv_kind_of((string)$v['mime'], (string)$v['file_name']) === 'video';
    header('Cache-Control: private, max-age=' . ($video ? 1500 : 500));
    header('Location: ' . ($k === 'orig' ? cc_rv_url($key, false, '', 300, (string)$v['file_name']) : cc_rv_url($key, true, $video ? (str_ends_with($key, '.webm') ? 'video/webm' : 'video/mp4') : '', $video ? 1800 : 600)), true, 302);
    exit;
}

case 'att': {
    session_write_close();
    $st = $pdo->prepare('SELECT attachments, item_id FROM cc_comments WHERE id = ? AND deleted_at IS NULL');
    $st->execute([qi('c')]);
    $c = $st->fetch();
    $att = $c ? (json_decode((string)$c['attachments'], true) ?: [])[qi('i')] ?? null : null;
    if (!$att || empty($att['k'])) { http_response_code(404); exit; }
    header('Location: ' . cc_rv_url((string)$att['k'], false, '', 300, (string)($att['n'] ?? 'anexo')), true, 302);
    exit;
}

case 'export': {
    $v = version_req($pdo);
    $item = cc_rv_item($pdo, (int)$v['item_id']);
    if (!$item) rfail('Esta peça já não existe.', 404);
    [$name, $mime, $bytes] = cc_rv_export($pdo, $item, $v, (string)q('fmt'), q('client') === '1');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store');
    echo $bytes;
    exit;
}

case 'deals': {
    $q = trim((string)q('q'));
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $st = $pdo->prepare("SELECT id, code, name, phase FROM deals WHERE phase IN ('pipeline','production','done') AND (code LIKE ? OR name LIKE ?) ORDER BY id DESC LIMIT 20");
    $st->execute([$like, $like]);
    rout(200, ['ok' => true, 'deals' => array_map(fn($d) => ['id' => (int)$d['id'], 'label' => trim($d['code'] . ' · ' . $d['name'], ' ·')], $st->fetchAll())]);
}

case 'lines': {
    $d = deal_req($pdo);
    $out = [];
    if (db_has_table($pdo, 'deal_lines')) {
        $st = $pdo->prepare('SELECT l.id, f.name FROM deal_lines l LEFT JOIN funnels f ON f.id = l.funnel_id WHERE l.deal_id = ? ORDER BY l.position, l.id');
        $st->execute([(int)$d['id']]);
        $out = array_map(fn($l) => ['id' => (int)$l['id'], 'name' => (string)$l['name']], $st->fetchAll());
    }
    rout(200, ['ok' => true, 'lines' => count($out) > 1 ? $out : []]);
}

case 'share': {
    $d = deal_req($pdo);
    $s = cc_share_for_deal($pdo, (int)$d['id']);
    $boards = [];
    foreach ($pdo->query('SELECT id, title, kind FROM cc_boards WHERE deal_id = ' . (int)$d['id'] . " AND status <> 'deleted' ORDER BY id")->fetchAll() as $b) {
        $boards[] = ['id' => (int)$b['id'], 'title' => (string)$b['title']];
    }
    $log = array_map(fn($l) => ['when' => date('d/m H:i', strtotime((string)$l['at'])), 'icon' => (CC_SHARE_ACTIONS[$l['action']] ?? ['eye'])[0], 'c' => (CC_SHARE_ACTIONS[$l['action']] ?? ['', 'neutral'])[1],
        'tip' => (CC_SHARE_ACTIONS[$l['action']] ?? ['', '', $l['action']])[2], 'who' => (string)$l['client_name'], 'email' => (string)$l['client_email'], 'ip' => (string)$l['ip'],
        'txt' => trim((CC_SHARE_ACTIONS[$l['action']] ?? ['', '', $l['action']])[2] . ($l['title'] ? ' · ' . $l['title'] : '') . ($l['detail'] !== '' ? ' · ' . $l['detail'] : ''))], cc_share_log_rows($pdo, (int)$d['id'], 40));
    rout(200, ['ok' => true, 'can' => $canShare, 'boards' => $boards, 'log' => $log, 'share' => $s ? [
        'valid' => cc_share_valid($s), 'until' => (string)$s['expires_on'], 'tabs' => cc_share_tabs($s), 'lang' => (string)$s['lang'], 'boards' => cc_share_boards($s),
        'created' => date('d/m/Y', strtotime((string)$s['created_at'])), 'last' => $s['last_access_at'] ? date('d/m H:i', strtotime((string)$s['last_access_at'])) : '',
    ] : null]);
}

// ── peças e versões ─────────────────────────────────────────
case 'item_new': {
    $d = deal_req($pdo);
    $line = qi('line') ?: null;
    if ($line) {
        $st = $pdo->prepare('SELECT 1 FROM deal_lines WHERE id = ? AND deal_id = ?');
        $st->execute([$line, (int)$d['id']]);
        if (!$st->fetchColumn()) $line = null;
    }
    $id = cc_rv_item_create($pdo, $d, (string)q('title'), (string)q('stage'), 'video', $line, $by);
    rout(200, ['ok' => true, 'id' => $id, 'url' => '/admin/criativo.php?rv=' . $id]);
}

case 'item_save': {
    $item = item_req($pdo);
    $in = [];
    foreach (['title', 'stage', 'watermark', 'download', 'line'] as $k) if (isset($_POST[$k])) $in[$k] = $_POST[$k];
    $item = cc_rv_item_save($pdo, $item, $in, $by);
    rout(200, ['ok' => true, 'item' => cc_rv_item_out($pdo, $item, false)]);
}

case 'upload_init': {
    $item = item_req($pdo);
    if (!r2_config() || !files_on()) rfail('O Drive não está ligado neste servidor: o Review guarda os originais no Drive.');
    $size = qi('size');
    if ($size <= 0) rfail('O ficheiro está vazio.');
    if ($size > CC_RV_MAX_BYTES) rfail('Este ficheiro tem mais de 5 GB. Exporte uma versão mais leve para o review.');
    $folder = cc_rv_drive_folder($pdo, $item);
    if ($folder === null) rfail('Não foi possível encontrar a pasta do negócio no Drive.');
    $num = (int)$pdo->query('SELECT COALESCE(MAX(num), 0) + 1 FROM cc_rv_versions WHERE item_id = ' . (int)$item['id'])->fetchColumn();
    $name = r2_clean_name((string)q('name')) ?: 'versao';
    $key = drive_unique_key($folder . cc_rv_vlabel($num) . '_' . $name, fn($k) => r2_head($k) !== null);
    rout(200, ['ok' => true, 'key' => $key, 'put' => r2_presign('PUT', $key, R2_URL_TTL)]);
}

case 'upload_done': {
    $item = item_req($pdo);
    $key = (string)q('key');
    $folder = cc_rv_drive_folder($pdo, $item);
    if ($folder === null || !str_starts_with($key, $folder) || str_contains(substr($key, strlen($folder)), '/')) rfail('Pedido inválido.');
    session_write_close();
    @set_time_limit(180);
    $vid = cc_rv_version_create($pdo, $item, $key, ['name' => (string)q('name'), 'mime' => (string)q('mime'), 'w' => qi('w'), 'h' => qi('h'),
                                                    'dur' => (float)q('dur'), 'note' => (string)q('note')], $by);
    $v = cc_rv_version($pdo, $vid);
    $kind = cc_rv_kind_of((string)$v['mime'], (string)$v['file_name']);
    try {
        if ($kind === 'image') {
            cc_rv_prepare_image($pdo, $v);
        } elseif ($kind === 'pdf') {
            $pages = $_FILES['pages']['tmp_name'] ?? [];
            if (!$pages) throw new RuntimeException('Não foi possível ler as páginas deste PDF no browser. Tente outra vez.');
            cc_rv_store_pages($pdo, $v, (array)$pages);
        } else {
            cc_rv_store_preview($pdo, $v, $_FILES['poster']['tmp_name'] ?? null, $_FILES['strip']['tmp_name'] ?? null, qi('strip_n'));
            if (cc_rv_ffmpeg_known()) cc_rv_queue_video($pdo, $v, $by);
        }
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE cc_rv_versions SET proc_status = 'error', proc_error = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 250), $vid]);
    }
    cc_rv_touch($pdo, (int)$item['id']);
    $v = cc_rv_version($pdo, $vid);
    rout(200, ['ok' => true, 'version' => $vid, 'msg' => cc_rv_vlabel((int)$v['num']) . ($v['proc_status'] === 'ready' ? ' pronta.' : ($v['proc_status'] === 'error' ? ': ' . $v['proc_error'] : ' carregada. O servidor está a preparar o vídeo (proxy, fps e timecode).'))]);
}

case 'version_retry': {
    $item = item_req($pdo);
    $v = version_req($pdo, $item);
    if (cc_rv_kind_of((string)$v['mime'], (string)$v['file_name']) !== 'video' || !cc_rv_ffmpeg_known()) rfail('Só os vídeos voltam a ser preparados pelo servidor.');
    $pdo->prepare("UPDATE cc_rv_versions SET proc_status = 'queued', proc_error = '' WHERE id = ?")->execute([(int)$v['id']]);
    cc_rv_queue_video($pdo, $v, $by);
    cc_rv_touch($pdo, (int)$item['id']);
    rout(200, ['ok' => true, 'msg' => 'Volta a ser preparada no próximo minuto.']);
}

case 'att_init': {
    $item = item_req($pdo);
    $size = qi('size');
    if ($size <= 0 || $size > 524288000) rfail('Os anexos vão até 500 MB.');
    $folder = cc_rv_drive_folder($pdo, $item);
    if ($folder === null || !r2_config()) rfail('O Drive não está ligado neste servidor.');
    $name = r2_clean_name((string)q('name')) ?: 'anexo';
    $key = drive_unique_key($folder . 'Anexos/' . $name, fn($k) => r2_head($k) !== null);
    rout(200, ['ok' => true, 'key' => $key, 'put' => r2_presign('PUT', $key, R2_URL_TTL)]);
}

// ── comentários ─────────────────────────────────────────────
case 'comment_add': {
    $item = item_req($pdo);
    $v = version_req($pdo, $item);
    $att = [];
    $folder = cc_rv_drive_folder($pdo, $item);
    foreach ((json_decode((string)q('att'), true) ?: []) as $x) {
        $k = (string)($x['k'] ?? '');
        if ($folder === null || !str_starts_with($k, $folder . 'Anexos/') || !($h = r2_head($k))) continue;
        files_register($pdo, $k, ['etag' => $h['etag'], 'size' => $h['size'], 'deal_id' => (int)$item['deal_id'], 'section' => 'review', 'origin' => 'review',
                                  'label' => $item['title'] . ' · anexo', 'by' => $by]);
        $att[] = ['k' => $k, 'n' => drive_base($k), 's' => (int)$h['size']];
        if (count($att) >= 10) break;
    }
    $id = cc_rv_comment_add($pdo, $item, $v, ['body' => q('body'), 'fi' => q('fi', null), 'fo' => q('fo', null), 'x' => q('x', null), 'y' => q('y', null),
        'page' => q('page', null), 'drawing' => q('drawing', null), 'vis' => q('vis'), 'parent' => qi('parent'), 'attachments' => $att], ['kind' => 'team', 'user' => $by]);
    rout(200, ['ok' => true, 'id' => $id, 'comments' => cc_rv_comments($pdo, cc_rv_item($pdo, (int)$item['id']), null, 'team')]);
}

case 'comment_resolve': {
    $item = item_req($pdo);
    cc_rv_comment_resolve($pdo, $item, qi('id'), q('on') === '1', $by);
    rout(200, ['ok' => true, 'comments' => cc_rv_comments($pdo, $item, null, 'team')]);
}

case 'comment_vis': {
    $item = item_req($pdo);
    $vis = q('vis') === 'client' ? 'client' : 'internal';
    $st = $pdo->prepare("UPDATE cc_comments SET visibility = ? WHERE id = ? AND item_id = ? AND author_kind = 'team' AND deleted_at IS NULL AND parent_id IS NULL");
    $st->execute([$vis, qi('id'), (int)$item['id']]);
    if ($st->rowCount()) {
        // as respostas acompanham o comentário
        $pdo->prepare("UPDATE cc_comments SET visibility = ? WHERE parent_id = ? AND author_kind = 'team'")->execute([$vis, qi('id')]);
    }
    cc_rv_touch($pdo, (int)$item['id']);
    rout(200, ['ok' => true, 'comments' => cc_rv_comments($pdo, $item, null, 'team')]);
}

case 'comment_del':
case 'comment_restore': {
    $item = item_req($pdo);
    cc_rv_comment_delete($pdo, $item, qi('id'), ['kind' => 'team', 'user' => $by, 'admin' => role_is_admin((string)$user['role'])], $a === 'comment_restore');
    rout(200, ['ok' => true, 'comments' => cc_rv_comments($pdo, $item, null, 'team')]);
}

// ── enviar, pedir alterações, aprovar ───────────────────────
case 'send': {
    $item = item_req($pdo);
    $msg = cc_rv_send($pdo, $item, $by);
    $share = cc_share_for_deal($pdo, (int)$item['deal_id']);
    if (!$share || !cc_share_valid($share)) $msg .= ' Falta o link do portal: crie-o na ficha do negócio.';
    rout(200, ['ok' => true, 'msg' => $msg, 'portal' => $share && cc_share_valid($share)]);
}

case 'changes': {
    $item = item_req($pdo);
    cc_rv_request_changes($pdo, $item, $by, (string)q('note'));
    rout(200, ['ok' => true, 'msg' => 'Alterações pedidas: a peça volta à equipa.']);
}

case 'approve': {
    $item = item_req($pdo);
    rout(200, ['ok' => true, 'msg' => cc_rv_approve($pdo, $item, ['kind' => 'team', 'user' => $by])]);
}

// ── o link do portal ────────────────────────────────────────
case 'share_create': {
    $d = deal_req($pdo);
    [, $token] = cc_share_create($pdo, $d, $by);
    rout(200, ['ok' => true, 'url' => cc_share_url($token), 'msg' => 'Link do portal criado. Copie-o e envie ao cliente.']);
}

case 'share_link': {
    $d = deal_req($pdo);
    $s = cc_share_for_deal($pdo, (int)$d['id']);
    $t = $s ? cc_share_decrypt($pdo, (string)$s['token_enc']) : null;
    if (!$t) rfail('Não há um link ativo. Crie um novo.');
    rout(200, ['ok' => true, 'url' => cc_share_url($t)]);
}

case 'share_save': {
    $d = deal_req($pdo);
    $s = cc_share_for_deal($pdo, (int)$d['id']);
    if (!$s) rfail('Crie primeiro o link do portal.');
    $set = [];
    if (isset($_POST['until'])) {
        $u = (string)$_POST['until'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $u) || $u < date('Y-m-d') || $u > date('Y-m-d', strtotime('+2 years'))) rfail('Escolha uma data entre hoje e daqui a dois anos.');
        $set['expires_on'] = $u;
    }
    if (isset($_POST['tabs'])) $set['tabs'] = implode(',', array_values(array_intersect(['proposal', 'boards', 'review'], (array)$_POST['tabs']))) ?: 'review';
    if (isset($_POST['lang'])) $set['lang'] = $_POST['lang'] === 'en' ? 'en' : 'pt';
    if (isset($_POST['boards'])) {
        $ids = array_values(array_filter(array_map('intval', (array)$_POST['boards'])));
        $ok = $ids ? array_map('intval', $pdo->query('SELECT id FROM cc_boards WHERE deal_id = ' . (int)$d['id'] . ' AND id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN)) : [];
        $set['boards'] = implode(',', $ok);
    }
    if ($set) $pdo->prepare('UPDATE cc_share SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), (int)$s['id']]);
    rout(200, ['ok' => true, 'msg' => 'Portal atualizado.']);
}

case 'share_revoke': {
    $d = deal_req($pdo);
    $pdo->prepare('UPDATE cc_share SET revoked_at = ? WHERE deal_id = ? AND revoked_at IS NULL')->execute([cc_rv_now(), (int)$d['id']]);
    deal_log($pdo, (int)$d['id'], 'portal', ['note' => 'Link do portal do cliente revogado', 'user_id' => $by]);
    rout(200, ['ok' => true, 'msg' => 'Link revogado: deixou de funcionar.']);
}

default:
    rfail('Pedido desconhecido.');
}
} catch (RuntimeException $e) {
    rfail($e->getMessage());
} catch (Throwable $e) {
    error_log('rv_api ' . $a . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    rfail('Não foi possível concluir. Tente outra vez.', 500);
}
