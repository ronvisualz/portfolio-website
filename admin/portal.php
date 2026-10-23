<?php
/**
 * topper. — Portal do cliente (Centro Criativo, fase 2). Página pública, sem conta e sem sessão.
 *
 * Um link por negócio (token longo e aleatório; na base só o hash e uma cópia cifrada para «Copiar
 * link»), revogável e com validade. Separadores só os partilhados: Proposta (com o pré-criativo) ·
 * Quadros (só leitura: tratamento e rotas) · Review (as peças enviadas).
 *
 *   GET  portal.php?t=<token>[&tab=proposal|boards|review][&p=<peça>][&lang=pt|en]   a página
 *   GET  portal.php?t=…&j=room&p=<peça>&ck=<browser>                                os dados da sala (JSON)
 *   GET  portal.php?t=…&j=poll&p=<peça>&rev=<n>                                     mudou?
 *   GET  portal.php?t=…&j=rt&b=<quadro>                                             token do tempo real (só leitura, sem presença)
 *   GET  portal.php?t=…&m=<versão>&k=proxy|poster|strip|page[&n=<página>]           um ficheiro (URL curto)
 *   GET  portal.php?t=…&ref=<referência>                                            uma imagem do pré-criativo/quadros
 *   POST portal.php?t=…  a=seen|ident|comment_add|comment_del|comment_restore|submit|approve|download
 *        (formulário assinado pelo servidor, mesma origem; nome e e-mail na primeira ação)
 *
 * GET nunca altera nada. O cliente nunca recebe custos, margens, notas, tarefas, e-mails da equipa
 * nem comentários internos — o que sai daqui é escolhido campo a campo. Pedidos limitados por IP;
 * acessos e ações registados (ficha do negócio › Portal do cliente).
 */
define('TOPPER_NO_SESSION', true);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/cc_review.php';
require_once __DIR__ . '/inc/cc_flow.php';
require_once __DIR__ . '/inc/pipeline_flow.php';

const PORTAL_FORM_TTL = 43200;   // o formulário assinado vale 12 h (a página volta a pedir um novo)
const PT_STR = [
    'pt' => ['proposal' => 'Proposta', 'boards' => 'Quadros', 'review' => 'Review', 'gone' => 'Este link expirou ou não existe.',
             'goneSub' => 'Peça um link novo à equipa da topper.', 'private' => 'Esta página é privada: não a partilhe.', 'until' => 'Disponível até',
             'noProposal' => 'Ainda não há uma proposta para mostrar aqui.', 'noBoards' => 'Ainda não há quadros partilhados.', 'live' => 'A equipa atualizou este quadro.', 'refresh' => 'Atualizar', 'noReview' => 'Ainda não há peças para rever.',
             'sentOn' => 'Enviada a', 'accepted' => 'Aceite', 'refused' => 'Recusada', 'validated' => 'Confirmada pela topper.', 'waiting' => 'A aguardar a sua resposta',
             'answerHint' => 'Para responder à proposta use o link do e-mail em que a recebeu.', 'vat' => 'IVA', 'total' => 'total', 'noVat' => 's/ IVA',
             'term' => 'Prazo', 'payment' => 'Pagamento', 'revisions' => 'Revisões incluídas', 'rights' => 'Direitos de utilização', 'conditions' => 'Outras condições',
             'precreative' => 'Pré-criativo', 'treatment' => 'Tratamento', 'route' => 'Rota', 'back' => 'Todas as peças', 'version' => 'Versão',
             'comments' => 'comentários', 'limit' => 'Demasiados pedidos. Espere um minuto e tente outra vez.', 'expired' => 'A página esteve aberta muito tempo. Recarregue-a e tente outra vez.',
             'theme' => 'Mudar entre claro e escuro', 'download' => 'Descarregar', 'complexity' => 'Complexidade'],
    'en' => ['proposal' => 'Proposal', 'boards' => 'Boards', 'review' => 'Review', 'gone' => 'This link has expired or does not exist.',
             'goneSub' => 'Ask the topper. team for a new link.', 'private' => 'This page is private: please do not share it.', 'until' => 'Available until',
             'noProposal' => 'There is no proposal to show here yet.', 'noBoards' => 'No boards shared yet.', 'live' => 'The team has updated this board.', 'refresh' => 'Refresh', 'noReview' => 'Nothing to review yet.',
             'sentOn' => 'Sent on', 'accepted' => 'Accepted', 'refused' => 'Declined', 'validated' => 'Confirmed by topper.', 'waiting' => 'Waiting for your answer',
             'answerHint' => 'To answer the proposal, use the link in the email you received it with.', 'vat' => 'VAT', 'total' => 'total', 'noVat' => 'excl. VAT',
             'term' => 'Timeline', 'payment' => 'Payment', 'revisions' => 'Included revisions', 'rights' => 'Usage rights', 'conditions' => 'Other terms',
             'precreative' => 'Pre-creative', 'treatment' => 'Treatment', 'route' => 'Route', 'back' => 'All pieces', 'version' => 'Version',
             'comments' => 'comments', 'limit' => 'Too many requests. Please wait a minute and try again.', 'expired' => 'This page was open for a long time. Reload it and try again.',
             'theme' => 'Switch light and dark', 'download' => 'Download', 'complexity' => 'Complexity'],
];

function ph(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function portal_json(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** A página «expirou ou não existe» (a mesma para tudo: não diz mais nada). */
function portal_gone(string $lang = 'pt', int $code = 404): void {
    $T = PT_STR[$lang] ?? PT_STR['pt'];
    if (($_GET['j'] ?? '') !== '' || $_SERVER['REQUEST_METHOD'] === 'POST') portal_json($code, ['ok' => false, 'error' => $T['gone']]);
    if (isset($_GET['m']) || isset($_GET['ref'])) { http_response_code($code); exit; }
    portal_shell($T['gone'], '<section class="pt-gone"><h1>' . ph($T['gone']) . '</h1><p class="muted">' . ph($T['goneSub']) . '</p></section>', $lang, $code);
}

/** A moldura da página (marca, idioma, tema). */
function portal_shell(string $title, string $body, string $lang, int $code = 200, string $head = '', array $meta = []): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $T = PT_STR[$lang] ?? PT_STR['pt'];
    $q = $_GET; unset($q['lang']);
    $other = $lang === 'en' ? 'pt' : 'en';
    echo '<!DOCTYPE html><html lang="' . ($lang === 'en' ? 'en' : 'pt') . '"><head>';
    render_head($title);
    echo '<link rel="stylesheet" href="' . asset_url('review.css') . '"><link rel="stylesheet" href="' . asset_url('portal.css') . '">'
       . '<script>try{var t=localStorage.getItem("portal_theme");if(t)document.documentElement.setAttribute("data-theme",t);}catch(e){}</script>' . $head . '</head><body class="pt">'
       . '<header class="pt-top"><span class="pt-logo">topper<b>.</b></span>'
       . (!empty($meta['title']) ? '<span class="pt-deal"><b>' . ph($meta['title']) . '</b>' . (!empty($meta['sub']) ? '<span>' . ph($meta['sub']) . '</span>' : '') . '</span>' : '<span class="pt-deal"></span>')
       . '<nav class="pt-tools"><a class="pt-lang" href="?' . ph(http_build_query($q + ['lang' => $other])) . '" hreflang="' . $other . '" aria-label="' . ($other === 'en' ? 'English' : 'Português') . '">'
       . '<span' . ($lang === 'pt' ? ' class="on"' : '') . '>PT</span><span' . ($lang === 'en' ? ' class="on"' : '') . '>EN</span></a>'
       . '<button type="button" class="pt-theme" data-pt-theme aria-label="' . ph($T['theme']) . '" title="' . ph($T['theme']) . '">' . icon('moon') . '</button></nav></header>'
       . '<main class="pt-main">' . $body . '</main>'
       . '<footer class="pt-foot">topper. · ' . ph($T['private']) . (!empty($meta['until']) ? ' · ' . ph($T['until']) . ' ' . ph($meta['until']) : '') . '</footer>'
       . '<div class="toasts" aria-live="polite"></div>'
       . '<script>document.querySelector("[data-pt-theme]").addEventListener("click",function(){var r=document.documentElement,c=r.getAttribute("data-theme")||(matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"),n=c==="dark"?"light":"dark";r.setAttribute("data-theme",n);try{localStorage.setItem("portal_theme",n);}catch(e){}});</script>'
       . '</body></html>';
    exit;
}

// ── o link ──────────────────────────────────────────────────
$lang = in_array($_GET['lang'] ?? '', ['pt', 'en'], true) ? (string)$_GET['lang'] : '';
if (!cc_rv_ready($pdo)) portal_gone($lang ?: 'pt', 503);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$isMedia = isset($_GET['m']) || isset($_GET['ref']);
// limite por IP e por minuto: as páginas e os dados, as ações e os ficheiros (um vídeo pede vários)
if (!cc_portal_hit($pdo, $isPost ? 40 : ($isMedia ? 600 : 150))) {
    http_response_code(429);
    header('Retry-After: 60');
    $T = PT_STR[$lang ?: 'pt'];
    if ($isPost || ($_GET['j'] ?? '') !== '') portal_json(429, ['ok' => false, 'error' => $T['limit']]);
    if ($isMedia) exit;
    portal_shell('topper.', '<section class="pt-gone"><h1>' . ph($T['limit']) . '</h1></section>', $lang ?: 'pt', 429);
}
$token = (string)($_GET['t'] ?? '');
$share = cc_share_by_token($pdo, $token);
if (!$share || !cc_share_valid($share)) portal_gone($lang ?: 'pt');
$lang = $lang ?: ((string)$share['lang'] === 'en' ? 'en' : 'pt');
$T = PT_STR[$lang];
$deal = files_deal_row($pdo, (int)$share['deal_id']);
if (!$deal) portal_gone($lang);
$st = $pdo->prepare("SELECT COALESCE(co.name, NULLIF(d.company, ''), d.name) FROM deals d LEFT JOIN contacts co ON co.id = d.company_id WHERE d.id = ?");
$st->execute([(int)$deal['id']]);
$clientName = (string)$st->fetchColumn();
$tabs = cc_share_tabs($share);

/** Uma peça partilhada deste negócio, ou null. */
function portal_item(PDO $pdo, array $share, int $id): ?array {
    $i = $id ? cc_rv_item($pdo, $id) : null;
    return $i && (int)$i['deal_id'] === (int)$share['deal_id'] && (int)$i['shared'] === 1 ? $i : null;
}

/** Uma versão já enviada de uma peça partilhada. */
function portal_version(PDO $pdo, array $share, int $vid): ?array {
    $v = $vid ? cc_rv_version($pdo, $vid) : null;
    if (!$v || $v['sent_at'] === null) return null;
    $i = portal_item($pdo, $share, (int)$v['item_id']);
    return $i ? $v + ['_item' => $i] : null;
}

/** O endereço (pelo portal) de um ficheiro de uma versão. */
function portal_media_url(array $v, string $k, int $n = 0): string {
    global $token;
    return '/admin/portal.php?' . http_build_query(['t' => $token, 'm' => (int)$v['id'], 'k' => $k] + ($n ? ['n' => $n] : []));
}

/** O que mostra o separador Proposta: a última proposta (não anulada) e o conteúdo dela. */
function portal_proposal(PDO $pdo, array $deal): ?array {
    if (!db_has_table($pdo, 'proposals')) return null;
    $st = $pdo->prepare("SELECT * FROM proposals WHERE deal_id = ? AND status <> 'void' ORDER BY id DESC LIMIT 1");
    $st->execute([(int)$deal['id']]);
    $p = $st->fetch();
    if (!$p) return null;
    require_once __DIR__ . '/inc/budget.php';
    $v = budget_version_get($pdo, (int)$p['version_id']);
    if (!$v) return null;
    return ['p' => $p, 'c' => proposal_public_content($pdo, deal_get($pdo, (int)$deal['id']) ?: $deal, $v)];
}

/** Os quadros partilhados: [quadro, tratamento|null, [rotas]]. */
function portal_boards(PDO $pdo, array $share): array {
    $ids = cc_share_boards($share);
    if (!$ids) return [];
    $st = $pdo->prepare('SELECT * FROM cc_boards WHERE deal_id = ? AND id IN (' . implode(',', $ids) . ") AND status <> 'deleted' ORDER BY id");
    $st->execute([(int)$share['deal_id']]);
    $out = [];
    foreach ($st->fetchAll() as $b) {
        $pg = $pdo->prepare('SELECT lane, data FROM cc_pages WHERE board_id = ?');
        $pg->execute([(int)$b['id']]);
        $tr = null; $routes = [];
        foreach ($pg->fetchAll() as $r) {
            $d = json_decode((string)$r['data'], true);
            if (!is_array($d)) continue;
            if ($r['lane'] === 'T') $tr = $d; elseif (in_array($r['lane'], ['A', 'B'], true)) $routes[$r['lane']] = $d;
        }
        ksort($routes);
        $out[] = [$b, $tr, $routes];
    }
    return $out;
}

/** As referências (imagens) que o portal deste negócio pode mostrar. */
function portal_allowed_refs(PDO $pdo, array $share, array $deal): array {
    $ok = [];
    if (in_array('proposal', cc_share_tabs($share), true) && ($pp = portal_proposal($pdo, $deal))) {
        foreach ((array)($pp['c']['creative'] ?? []) as $pg) foreach ($pg['refs'] as $id) $ok[(int)$id] = true;
    }
    if (in_array('boards', cc_share_tabs($share), true)) {
        foreach (portal_boards($pdo, $share) as [, $tr, $routes]) {
            foreach ((array)($tr['sections'] ?? []) as $s) foreach ((array)($s['refs'] ?? []) as $id) $ok[(int)$id] = true;
            foreach ($routes as $d) foreach ((array)($d['refs'] ?? []) as $id) $ok[(int)$id] = true;
        }
    }
    return $ok;
}

// ── ficheiros: revalida e redireciona para um URL curto ─────
if (isset($_GET['m'])) {
    $v = portal_version($pdo, $share, (int)$_GET['m']);
    if (!$v) { http_response_code(404); exit; }
    $item = $v['_item'];
    $kind = cc_rv_kind_of((string)$v['mime'], (string)$v['file_name']);
    $k = (string)($_GET['k'] ?? '');
    $key = match ($k) {
        'proxy' => (string)$v['proxy_key'], 'poster' => (string)$v['poster_key'], 'strip' => (string)$v['strip_key'],
        'page' => (string)((json_decode((string)$v['pages'], true) ?: [])[max(1, (int)($_GET['n'] ?? 1)) - 1]['k'] ?? ''),
        default => '',
    };
    if ($key === '') { http_response_code(404); exit; }
    $video = $k === 'proxy' && $kind === 'video';
    if ((int)$item['watermark']) {
        if ($video && $v['wm_status'] === 'ready' && (string)$v['wm_key'] !== '') $key = (string)$v['wm_key'];
        elseif (!$video && in_array($k, ['proxy', 'page', 'poster'], true)) $key = cc_rv_wm_image($v, $key, cc_rv_wm_label($pdo, $item));
    }
    header('Cache-Control: private, max-age=' . ($video ? 840 : 240));
    header('Location: ' . cc_rv_url($key, true, $video ? (str_ends_with($key, '.webm') ? 'video/webm' : 'video/mp4') : 'image/jpeg', $video ? 900 : 300), true, 302);
    exit;
}
if (isset($_GET['ref'])) {
    $aid = (int)$_GET['ref'];
    if (!$aid || empty(portal_allowed_refs($pdo, $share, $deal)[$aid])) { http_response_code(404); exit; }
    $a = cc_asset_get($pdo, $aid);
    $key = $a ? cc_thumb_pick($a['thumbs'], 768) : null;
    if (!$key) { http_response_code(404); exit; }
    header('Cache-Control: private, max-age=240');
    if (strncmp($key, 'r2:', 3) === 0) {
        header('Location: ' . cc_rv_url(substr($key, 3), true, '', 300), true, 302);
        exit;
    }
    // miniaturas antigas, guardadas no próprio servidor
    $path = cc_local_path($key);
    if ($path === null || !is_file($path)) { http_response_code(404); exit; }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    header('Content-Type: ' . (['webp' => 'image/webp', 'png' => 'image/png'][$ext] ?? 'image/jpeg'));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── os dados da sala (só leitura) ───────────────────────────
/** O comentário para o cliente: a equipa aparece como «topper. · Nome». */
function portal_comments(PDO $pdo, array $item, string $ck): array {
    return array_map(function ($c) {
        if (!$c['client']) $c['who'] = 'topper. · ' . explode(' ', trim((string)$c['who']))[0];
        return $c;
    }, cc_rv_comments($pdo, $item, null, 'client', $ck));
}
function portal_room(PDO $pdo, array $share, array $item, string $ck, string $lang): array {
    $vers = array_map(fn($v) => cc_rv_version_out($v, 'portal_media_url', true), cc_rv_versions($pdo, (int)$item['id'], true));
    $out = cc_rv_item_out($pdo, $item, true, $lang);
    // a versão atual para o cliente é a última enviada
    $out['current'] = $vers ? $vers[0]['id'] : null;
    if ($out['approved'] && !in_array($out['approved']['version'], array_column($vers, 'id'), true)) $out['approved'] = null;
    return ['item' => $out, 'versions' => $vers, 'comments' => portal_comments($pdo, $item, $ck)];
}
$j = (string)($_GET['j'] ?? '');
if ($j === 'rt') {
    // os quadros partilhados em tempo real: só leitura e sem a presença da equipa (§26)
    require_once __DIR__ . '/inc/cc_rt.php';
    $bid = (int)($_GET['b'] ?? 0);
    $tok = in_array('boards', $tabs, true) && in_array($bid, cc_share_boards($share), true) && cc_rt_url($pdo) !== '' ? cc_rt_token($pdo, $bid, 0, 'portal') : null;
    portal_json($tok ? 200 : 404, $tok ? ['ok' => true, 'token' => $tok] : ['ok' => false, 'error' => $T['gone']]);
}
if ($j !== '') {
    $item = portal_item($pdo, $share, (int)($_GET['p'] ?? 0));
    if (!$item || !in_array('review', $tabs, true)) portal_json(404, ['ok' => false, 'error' => $T['gone']]);
    $ck = preg_match('/^[a-f0-9]{32}$/', (string)($_GET['ck'] ?? '')) ? (string)$_GET['ck'] : '';
    if ($j === 'poll') portal_json(200, ['ok' => true, 'changed' => (int)$item['rev'] !== (int)($_GET['rev'] ?? -1)]);
    portal_json(200, ['ok' => true] + portal_room($pdo, $share, $item, $ck, $lang));
}

// ── ações (POST, formulário assinado, mesma origem) ─────────
if ($isPost) {
    $ts = (int)($_POST['ts'] ?? 0);
    $host = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $same = $origin === '' || strtolower((string)parse_url($origin, PHP_URL_HOST)) === $host;
    if (!$same || $ts < time() - PORTAL_FORM_TTL || $ts > time() + 300 || !hash_equals(cc_portal_sig($pdo, $share, $ts), (string)($_POST['sig'] ?? ''))) {
        portal_json(403, ['ok' => false, 'error' => $T['expired']]);
    }
    $a = (string)($_POST['a'] ?? '');
    $name = trim(preg_replace('/\s+/u', ' ', mb_substr((string)($_POST['name'] ?? ''), 0, 160)));
    $email = trim(mb_substr((string)($_POST['email'] ?? ''), 0, 190));
    $ck = (string)($_POST['ck'] ?? '');
    $who = ['kind' => 'client', 'name' => $name, 'email' => $email, 'key' => $ck, 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')];
    $needId = !in_array($a, ['seen'], true);
    if ($needId && (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[a-f0-9]{32}$/', $ck))) {
        portal_json(400, ['ok' => false, 'error' => $lang === 'en' ? 'Please tell us your name and email first.' : 'Diga-nos primeiro o seu nome e e-mail.', 'ident' => true]);
    }
    $item = isset($_POST['item']) ? portal_item($pdo, $share, (int)$_POST['item']) : null;
    if (isset($_POST['item']) && (!$item || !in_array('review', $tabs, true))) portal_json(404, ['ok' => false, 'error' => $T['gone']]);
    $en = $lang === 'en';
    try {
        switch ($a) {
            case 'seen':
                cc_share_log($pdo, $share, $item ? 'view' : 'open', ['item' => $item ? (int)$item['id'] : null, 'name' => $name, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '']);
                portal_json(200, ['ok' => true]);
            case 'ident':
                cc_share_log($pdo, $share, 'ident', ['name' => $name, 'email' => $email]);
                portal_json(200, ['ok' => true]);
            case 'comment_add': {
                if (!$item) throw new RuntimeException($T['gone']);
                $v = portal_version($pdo, $share, (int)($_POST['v'] ?? 0));
                if (!$v || (int)$v['item_id'] !== (int)$item['id']) throw new RuntimeException($T['gone']);
                $n = $pdo->prepare('SELECT COUNT(*) FROM cc_comments WHERE item_id = ? AND client_key = ? AND draft = 1 AND deleted_at IS NULL');
                $n->execute([(int)$item['id'], $ck]);
                if ((int)$n->fetchColumn() >= 200) throw new RuntimeException($en ? 'Too many draft comments. Send them first.' : 'Tem muitos comentários por enviar. Envie-os primeiro.');
                cc_rv_comment_add($pdo, $item, $v, ['body' => $_POST['body'] ?? '', 'fi' => $_POST['fi'] ?? null, 'fo' => $_POST['fo'] ?? null, 'x' => $_POST['x'] ?? null, 'y' => $_POST['y'] ?? null,
                                                    'page' => $_POST['page'] ?? null, 'drawing' => $_POST['drawing'] ?? null, 'parent' => (int)($_POST['parent'] ?? 0)], $who);
                cc_share_log($pdo, $share, 'comment', ['item' => (int)$item['id'], 'name' => $name, 'email' => $email]);
                portal_json(200, ['ok' => true, 'comments' => portal_comments($pdo, cc_rv_item($pdo, (int)$item['id']), $ck)]);
            }
            case 'comment_del':
            case 'comment_restore':
                if (!$item) throw new RuntimeException($T['gone']);
                cc_rv_comment_delete($pdo, $item, (int)($_POST['id'] ?? 0), $who, $a === 'comment_restore');
                portal_json(200, ['ok' => true, 'comments' => portal_comments($pdo, $item, $ck)]);
            case 'submit': {
                if (!$item) throw new RuntimeException($T['gone']);
                $msg = cc_rv_client_submit($pdo, $share, $item, $who);
                $n = (int)preg_replace('/\D.*$/', '', $msg) ?: 1;
                cc_share_log($pdo, $share, 'submit', ['item' => (int)$item['id'], 'name' => $name, 'email' => $email, 'detail' => $n . ($n === 1 ? ' comentário' : ' comentários')]);
                portal_json(200, ['ok' => true, 'msg' => $en ? ($n === 1 ? 'Comment sent. Thank you!' : $n . ' comments sent. Thank you!') : $msg]);
            }
            case 'approve': {
                if (!$item) throw new RuntimeException($T['gone']);
                $vers = cc_rv_versions($pdo, (int)$item['id'], true);
                if (!$vers || (int)$vers[0]['id'] !== (int)($_POST['v'] ?? 0)) {
                    throw new RuntimeException($en ? 'There is a newer version. Reload the page.' : 'Há uma versão mais recente. Recarregue a página.');
                }
                $signed = trim(preg_replace('/\s+/u', ' ', mb_substr((string)($_POST['signed'] ?? ''), 0, 160)));
                if (mb_strlen($signed) < 3) throw new RuntimeException($en ? 'Type your full name to approve.' : 'Escreva o seu nome completo para aprovar.');
                cc_rv_approve($pdo, $item, ['kind' => 'client', 'name' => $signed, 'email' => $email, 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')], (int)$vers[0]['id']);
                cc_share_log($pdo, $share, 'approve', ['item' => (int)$item['id'], 'name' => $signed, 'email' => $email, 'detail' => cc_rv_vlabel((int)$vers[0]['num'])]);
                portal_json(200, ['ok' => true, 'msg' => $en ? 'Approved. Thank you!' : 'Aprovada. Obrigado!']);
            }
            case 'download': {
                $v = portal_version($pdo, $share, (int)($_POST['v'] ?? 0));
                if (!$v || !(int)$v['_item']['download']) throw new RuntimeException($T['gone']);
                cc_share_log($pdo, $share, 'download', ['item' => (int)$v['item_id'], 'name' => $name, 'email' => $email, 'detail' => cc_rv_vlabel((int)$v['num'])]);
                portal_json(200, ['ok' => true, 'url' => cc_rv_url((string)$v['file_key'], false, '', 120, (string)$v['file_name'])]);
            }
        }
        portal_json(400, ['ok' => false, 'error' => 'Pedido desconhecido.']);
    } catch (RuntimeException $e) {
        portal_json(400, ['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('portal ' . $a . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        portal_json(500, ['ok' => false, 'error' => $en ? 'Something went wrong. Please try again.' : 'Não foi possível concluir. Tente outra vez.']);
    }
}

// ── a página ────────────────────────────────────────────────
$items = in_array('review', $tabs, true) ? array_values(array_filter(cc_rv_items_for_deal($pdo, (int)$deal['id'], true), fn($i) => (int)$i['current_version_id'] > 0)) : [];
$tab = (string)($_GET['tab'] ?? '');
if (!in_array($tab, $tabs, true)) $tab = in_array('review', $tabs, true) && $items ? 'review' : ($tabs[0] ?? 'review');
$pid = (int)($_GET['p'] ?? 0);
$roomItem = $tab === 'review' && $pid ? portal_item($pdo, $share, $pid) : null;
$ts = time();
$sig = cc_portal_sig($pdo, $share, $ts);
$self = fn(array $q) => '/admin/portal.php?' . http_build_query(['t' => $token] + $q + ($lang !== (string)$share['lang'] ? ['lang' => $lang] : []));
$money = fn(float $n) => number_format($n, 2, ',', ' ') . ' €';

$body = '';
if (count($tabs) > 1) {
    $body .= '<nav class="pt-tabs" aria-label="Separadores">';
    foreach ($tabs as $k) $body .= '<a href="' . ph($self(['tab' => $k])) . '"' . ($tab === $k ? ' class="on" aria-current="page"' : '') . '>' . ph($T[$k]) . '</a>';
    $body .= '</nav>';
}

if ($tab === 'proposal') {
    $pp = portal_proposal($pdo, $deal);
    if (!$pp) {
        $body .= '<div class="pt-empty">' . ph($T['noProposal']) . '</div>';
    } else {
        [$p, $c] = [$pp['p'], $pp['c']];
        $stx = $p['status'] === 'validated' ? $T['validated'] : ($p['decision'] === 'accept' ? $T['accepted'] . ((int)$p['has_b'] && $p['choice'] ? ' · ' . ($lang === 'en' ? 'Option ' : 'Opção ') . $p['choice'] : '')
             : ($p['decision'] === 'refuse' ? $T['refused'] : $T['waiting']));
        $body .= '<section class="pt-sec"><div class="pt-h"><h1>' . ph($c['name']) . '</h1><span class="rv-st c-' . ($p['decision'] === 'accept' || $p['status'] === 'validated' ? 'green' : ($p['decision'] === 'refuse' ? 'orange' : 'blue')) . '">' . ph($stx) . '</span></div>'
               . '<p class="muted">' . ph($c['code']) . ' · v' . (int)$c['version'] . ($p['sent_at'] ? ' · ' . ph($T['sentOn']) . ' ' . ph(date('d/m/Y', strtotime((string)$p['sent_at']))) : '') . '</p><div class="pt-opts">';
        foreach ($c['options'] as $o) {
            $body .= '<article class="pt-opt"><small>' . ph(mb_strtoupper($lang === 'en' ? str_replace(['Opção', 'Pedido', 'Proposta de valor'], ['Option', 'Brief', 'Value proposal'], $o['label']) : $o['label'])) . '</small>'
                   . '<div class="pt-price">' . ph($money((float)$o['price'])) . '</div><div class="muted small">' . ph($T['noVat']) . ' · ' . ph($T['vat']) . ' ' . ph($money((float)$o['vat'])) . ' · ' . ph($T['total']) . ' ' . ph($money((float)$o['total'])) . '</div>'
                   . ($o['scope'] !== '' ? '<p class="pt-pre">' . ph($o['scope']) . '</p>' : '');
            foreach (cc_pages_for_opt($c['creative'] ?? [], $o['key']) as $pg) $body .= portal_page_html($pg, $T, $self);
            $body .= '</article>';
        }
        $body .= '</div><dl class="pt-terms">';
        foreach (['term', 'payment', 'revisions', 'rights', 'conditions'] as $k) if (trim((string)$c[$k]) !== '') $body .= '<dt>' . ph($T[$k]) . '</dt><dd>' . ph($c[$k]) . '</dd>';
        $body .= '</dl><p class="muted small">' . ph($T['answerHint']) . '</p></section>';
    }
} elseif ($tab === 'boards') {
    $boards = portal_boards($pdo, $share);
    if (!$boards) $body .= '<div class="pt-empty">' . ph($T['noBoards']) . '</div>';
    foreach ($boards as [$b, $tr, $routes]) {
        $body .= '<section class="pt-sec"><h2 class="pt-bt">' . ph((string)$b['title']) . '</h2>';
        if ($tr) {
            $body .= '<article class="pt-tr"><small>' . ph(mb_strtoupper($T['treatment'])) . '</small><h3>' . ph((string)($tr['title'] ?? '')) . '</h3>'
                   . (!empty($tr['logline']) ? '<p class="pt-log">' . ph((string)$tr['logline']) . '</p>' : '');
            foreach ((array)($tr['sections'] ?? []) as $s) {
                $body .= '<h4>' . ph((string)($s['heading'] ?? '')) . '</h4><p class="pt-pre">' . ph((string)($s['body'] ?? '')) . '</p>';
                $refs = array_slice(array_map('intval', (array)($s['refs'] ?? [])), 0, 6);
                if ($refs) { $body .= '<div class="pt-refs">'; foreach ($refs as $r) $body .= '<img src="' . ph($self(['ref' => $r])) . '" alt="" loading="lazy">'; $body .= '</div>'; }
            }
            $body .= '</article>';
        }
        if ($routes) {
            $body .= '<div class="pt-opts">';
            foreach ($routes as $lane => $d) {
                $pg = ['front' => '', 'title' => (string)($d['title'] ?? ''), 'concept' => (string)($d['concept'] ?? ''), 'approach' => (string)($d['approach'] ?? ''),
                       'technique' => (string)($d['technique'] ?? ''), 'complexity' => (string)($d['complexity'] ?? ''),
                       'palette' => array_values(array_filter((array)($d['palette'] ?? []), fn($h) => is_string($h) && preg_match('/^#[0-9A-Fa-f]{6}$/', $h))),
                       'refs' => array_slice(array_map('intval', (array)($d['refs'] ?? [])), 0, 6)];
                $body .= '<article class="pt-opt"><small>' . ph(mb_strtoupper($T['route'] . ' ' . $lane)) . '</small>' . portal_page_html($pg, $T, $self, false) . '</article>';
            }
            $body .= '</div>';
        }
        if (!$tr && !$routes) $body .= '<p class="muted">—</p>';
        $body .= '</section>';
    }
    // tempo real (só leitura): quando a equipa refaz as páginas, o cliente vê o aviso «Atualizar»
    require_once __DIR__ . '/inc/cc_rt.php';
    if ($boards && ($rtCsp = cc_rt_csp($pdo)) !== '' && setting($pdo, 'rt_enabled', '1') !== '0' && cc_rt_usage_today($pdo)['pct'] < 100) {
        csp_send($rtCsp);
        $url = cc_rt_url($pdo);
        $cfg = ['host' => preg_replace('~^https?://~', '', $url), 'secure' => str_starts_with($url, 'https://'), 'tok' => $self(['j' => 'rt']),
                'rooms' => array_map(fn($x) => (int)$x[0]['id'], $boards), 'msg' => $T['live'], 'btn' => $T['refresh']];
        $body .= '<div class="pt-live" data-pt-live hidden role="status"><span></span><button type="button"></button></div>'
               . '<script src="' . asset_url('vendor/rt/topper-rt.js') . '"></script>'
               . '<script>(function(c){if(!window.TopperRT)return;var R=TopperRT,box=document.querySelector("[data-pt-live]");'
               . 'box.querySelector("span").textContent=c.msg;var b=box.querySelector("button");b.textContent=c.btn;b.onclick=function(){location.reload();};'
               . 'c.rooms.forEach(function(id){var d=new R.Y.Doc(),ready=false,first=true;'
               . 'new R.YProvider(c.host,"b"+id,d,{party:"board",protocol:c.secure?"wss":"ws",disableBc:true,params:function(){return fetch(c.tok+"&b="+id,{cache:"no-store"}).then(function(r){return r.json();}).then(function(j){return {t:j.token};});}})'
               . '.on("sync",function(ok){if(ok)ready=true;});'
               . 'd.getMap("meta").observe(function(){if(ready)box.hidden=false;});});})(' . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ');</script>';
    }
} elseif ($roomItem) {
    $body .= '<p class="pt-back"><a href="' . ph($self(['tab' => 'review'])) . '">' . icon('chev-left') . ' ' . ph($T['back']) . '</a></p><div id="rv-room"></div>';
} else {
    if (!$items) $body .= '<div class="pt-empty">' . ph($T['noReview']) . '</div>';
    else {
        $body .= '<div class="rv-grid">';
        foreach ($items as $i) {
            $vers = cc_rv_versions($pdo, (int)$i['id'], true);
            if (!$vers) continue;
            $o = cc_rv_item_out($pdo, $i, true, $lang);
            $poster = (string)$vers[0]['poster_key'] !== '' ? portal_media_url($vers[0], 'poster') : '';
            $body .= '<a class="rv-card" href="' . ph($self(['tab' => 'review', 'p' => (int)$i['id']])) . '"><span class="rv-poster"' . ($poster !== '' ? ' style="background-image:url(\'' . ph($poster) . '\')"' : '') . '>'
                   . '<span class="rv-st c-' . ph($o['color']) . '">' . ph($o['statusLabel']) . '</span><span class="rv-v">' . ph(cc_rv_vlabel((int)$vers[0]['num'])) . '</span></span>'
                   . '<span class="rv-cb"><b>' . ph($o['title']) . '</b><span class="small">' . ph($lang === 'en' ? ucfirst($i['stage_key']) : $o['stageLabel']) . '</span></span></a>';
        }
        $body .= '</div>';
    }
}

function portal_wm_overlay(PDO $pdo, ?array $item): string {
    if (!$item || !(int)$item['watermark']) return '';
    $v = cc_rv_versions($pdo, (int)$item['id'], true)[0] ?? null;
    if (!$v || cc_rv_kind_of((string)$v['mime'], (string)$v['file_name']) !== 'video' || $v['wm_status'] === 'ready') return '';
    return cc_rv_wm_label($pdo, $item);
}

/** Uma página do pré-criativo / rota (título, conceito, abordagem, referências, paleta, técnica). */
function portal_page_html(array $pg, array $T, callable $self, bool $label = true): string {
    $imgs = '';
    foreach ($pg['refs'] as $aid) $imgs .= '<img src="' . ph($self(['ref' => (int)$aid])) . '" alt="" loading="lazy" decoding="async">';
    $pal = '';
    foreach ($pg['palette'] as $hex) $pal .= '<i style="background:' . ph($hex) . '" title="' . ph($hex) . '"></i>';
    $meta = array_filter([$pg['technique'], $pg['complexity'] !== '' ? $T['complexity'] . ' ' . mb_strtolower($pg['complexity']) : '']);
    return '<section class="pt-pc">' . ($label ? '<small>' . ph(mb_strtoupper($T['precreative'])) . ($pg['front'] !== '' ? ' · ' . ph(mb_strtoupper($pg['front'])) : '') . '</small>' : '')
         . ($pg['title'] !== '' ? '<h3>' . ph($pg['title']) . '</h3>' : '') . ($pg['concept'] !== '' ? '<p>' . ph($pg['concept']) . '</p>' : '')
         . ($pg['approach'] !== '' ? '<p class="muted">' . ph($pg['approach']) . '</p>' : '') . ($imgs !== '' ? '<div class="pt-refs">' . $imgs . '</div>' : '')
         . ($pal !== '' || $meta ? '<div class="pt-pal">' . ($pal !== '' ? '<span>' . $pal . '</span>' : '') . '<span class="muted small">' . ph(implode(' · ', $meta)) . '</span></div>' : '')
         . '</section>';
}

$cfg = [
    'url' => '/admin/portal.php?t=' . $token, 'ts' => $ts, 'sig' => $sig, 'lang' => $lang, 'item' => $roomItem ? (int)$roomItem['id'] : 0,
    'idKey' => 'topper_portal_' . substr((string)$share['token_hash'], 0, 12),
    // a marca de água por cima (CSS) só enquanto o vídeo com a marca gravada não está pronto (as imagens já a levam)
    'wm' => portal_wm_overlay($pdo, $roomItem),
];
$body .= '<script>window.PORTAL = ' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'
       . ($roomItem ? '<script src="' . asset_url('review.js') . '"></script>' : '') . '<script src="' . asset_url('portal.js') . '"></script>';
portal_shell($T[$tab] . ' · ' . (string)$deal['name'], $body, $lang, 200, '', ['title' => (string)$deal['name'], 'sub' => $clientName !== (string)$deal['name'] ? $clientName : '',
             'until' => $share['expires_on'] ? date('d/m/Y', strtotime((string)$share['expires_on'])) : '']);
