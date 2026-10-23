<?php
/**
 * topper. admin — captura (Centro Criativo, fase 3, §26.2–26.3)
 *
 * O que se guarda de fora — extensão «Guardar na topper.» (Chrome/Edge), o menu Partilhar do
 * Android (PWA) e o Atalho do iOS — entra sempre na Caixa de entrada e passa pela análise da IA
 * (como o que se carrega no Centro Criativo). Com um quadro escolhido, fica também lá como cartão.
 * Guarda a página de origem, o autor/créditos quando a página os diz, o tempo do vídeo e a nota.
 *
 * A extensão e o Atalho entram com um token pessoal (Perfil › Ligações, revogável); o Android usa
 * a sessão da própria plataforma. Nunca se guarda a palavra-passe.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/cc_rt.php';

const CAP_KINDS = ['image', 'video', 'link', 'page', 'text', 'area', 'frame', 'file'];
const CAP_MAX_BYTES = 41943040;   // 40 MB por ficheiro enviado

/** O endereço com o tempo do vídeo (YouTube: t=123s; Vimeo: #t=123s; outros: #t=123). */
function cap_url_at(string $url, ?float $t): string {
    if ($t === null || $t <= 0 || $url === '') return $url;
    $s = (int)floor($t);
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if (preg_match('/(^|\.)(youtube\.com|youtu\.be)$/', $host)) {
        $u = preg_replace('/([?&])t=\d+s?(&|$)/', '$1', $url);
        return rtrim($u, '?&') . (str_contains(rtrim($u, '?&'), '?') ? '&' : '?') . 't=' . $s . 's';
    }
    if (preg_match('/(^|\.)vimeo\.com$/', $host)) return preg_replace('/#.*$/', '', $url) . '#t=' . $s . 's';
    return preg_replace('/#.*$/', '', $url) . '#t=' . $s;
}

/** Os quadros a que esta pessoa pode juntar (os mais recentes primeiro), para a extensão escolher. */
function cap_boards(PDO $pdo, string $q = '', int $limit = 30): array {
    $where = "b.status = 'active'";
    $args = [];
    if ($q !== '') {
        $where .= ' AND (b.title LIKE ? OR d.code LIKE ? OR d.name LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        array_push($args, $like, $like, $like);
    }
    $st = $pdo->prepare("SELECT b.*, d.code FROM cc_boards b LEFT JOIN deals d ON d.id = b.deal_id WHERE $where ORDER BY b.updated_at DESC LIMIT 200");
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll() as $b) {
        if (!cc_board_access($pdo, $b)) continue;
        $out[] = ['id' => (int)$b['id'], 'title' => (string)$b['title'], 'code' => (string)($b['code'] ?? '')];
        if (count($out) >= $limit) break;
    }
    return $out;
}

/** O destino por defeito desta pessoa: null (só a Caixa de entrada) ou o último quadro usado. */
function cap_default_board(PDO $pdo, int $uid): ?array {
    if (setting($pdo, 'cap_default', 'inbox') !== 'last') return null;
    $id = (int)(user_prefs($pdo, $uid)['cap_board'] ?? 0);
    $b = $id ? cc_board_get($pdo, $id) : null;
    return $b && $b['status'] === 'active' && cc_board_access($pdo, $b) ? $b : null;
}

function cap_remember_board(PDO $pdo, int $uid, int $boardId): void {
    if (!db_has_column($pdo, 'users', 'prefs')) return;
    $p = user_prefs($pdo, $uid);
    if ((int)($p['cap_board'] ?? 0) === $boardId) return;
    $p['cap_board'] = $boardId;
    $pdo->prepare('UPDATE users SET prefs = ? WHERE id = ?')->execute([json_encode($p, JSON_UNESCAPED_UNICODE), $uid]);
}

/**
 * Guarda uma captura. $in: kind, url (página), src (imagem/vídeo), title, text (seleção), note,
 * t (segundos do vídeo), author, site, board (0 = só a Caixa de entrada; -1 = o destino por defeito),
 * via (extension|android|ios|web). $file: ['bytes', 'name', 'mime'] (área, frame ou ficheiro partilhado).
 * Devolve ['asset' => …, 'board' => …|null, 'card' => id|null, 'new' => bool].
 */
function cap_save(PDO $pdo, array $user, array $in, ?array $file): array {
    $uid = (int)$user['id'];
    $kind = in_array($in['kind'] ?? '', CAP_KINDS, true) ? (string)$in['kind'] : ($file ? 'file' : 'link');
    $page = mb_substr(trim((string)($in['url'] ?? '')), 0, 1000);
    if ($page !== '' && !preg_match('~^https?://\S+$~i', $page)) $page = '';
    $src = mb_substr(trim((string)($in['src'] ?? '')), 0, 2000);
    if ($src !== '' && !preg_match('~^https?://\S+$~i', $src)) $src = '';
    $t = isset($in['t']) && is_numeric($in['t']) && (float)$in['t'] > 0 ? round((float)$in['t'], 2) : null;
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 255);
    $text = mb_substr(trim((string)($in['text'] ?? '')), 0, 4000);
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 2000);
    $via = in_array($in['via'] ?? '', ['extension', 'android', 'ios', 'web'], true) ? (string)$in['via'] : 'web';
    // um link partilhado como texto (Android, iOS)
    if ($page === '' && $text !== '' && preg_match('~https?://\S+~i', $text, $m)) { $page = rtrim($m[0], '.,);'); if (trim($text) === $page) $text = ''; }

    // o destino
    $board = null;
    $bid = (int)($in['board'] ?? -1);
    if ($bid > 0) {
        $board = cc_board_get($pdo, $bid);
        if (!$board || $board['status'] !== 'active' || !cc_board_access($pdo, $board, $user)) throw new RuntimeException('Esse quadro não existe ou a sua conta não lhe tem acesso.');
    } elseif ($bid < 0) {
        $board = cap_default_board($pdo, $uid);
    }

    $cap = array_filter(['via' => $via, 'page' => cap_url_at($page, $t), 'src' => $src, 't' => $t, 'note' => $note, 'quote' => $text,
                         'author' => mb_substr(trim((string)($in['author'] ?? '')), 0, 160), 'site' => mb_substr(trim((string)($in['site'] ?? '')), 0, 120),
                         'kind' => $kind, 'at' => cc_now(), 'by' => $uid], fn($v) => $v !== '' && $v !== null);
    $o = ['by' => $uid, 'inbox' => true, 'origin' => 'external', 'title' => $title];
    $x = null; $new = false;

    if ($file && ($file['bytes'] ?? '') !== '') {
        if (strlen($file['bytes']) > CAP_MAX_BYTES) throw new RuntimeException('O ficheiro passa dos 40 MB.');
        $name = r2_clean_name((string)($file['name'] ?? '')) ?: ($kind === 'frame' ? 'frame.jpg' : ($kind === 'area' ? 'captura.png' : 'ficheiro'));
        if (!empty($file['preview'])) $o['preview'] = $file['preview'];
        [$x, $new] = cc_asset_create($pdo, $file['bytes'], $o + ['name' => $name, 'mime' => (string)($file['mime'] ?? ''), 'source_url' => cap_url_at($page ?: $src, $t),
                                                              'title' => $title ?: ($kind === 'frame' ? 'Frame' . ($t !== null ? ' · ' . gmdate($t >= 3600 ? 'G:i:s' : 'i:s', (int)$t) : '') : ($kind === 'area' ? 'Captura de ecrã' : ''))]);
    } elseif (in_array($kind, ['image'], true) && $src !== '' && ($r = cc_fetch($src, CAP_MAX_BYTES, 20)) && str_starts_with($r[1], 'image/')) {
        $name = r2_clean_name(basename((string)parse_url($src, PHP_URL_PATH))) ?: 'imagem';
        if (!preg_match('/\.[a-z0-9]{2,5}$/i', $name)) $name .= '.' . (explode('/', $r[1])[1] ?? 'jpg');
        [$x, $new] = cc_asset_create($pdo, $r[0], $o + ['name' => $name, 'mime' => $r[1], 'source_url' => $page !== '' ? $page : $src]);
    } elseif ($page === '' && $src === '' && $text !== '') {
        // só texto (sem página): fica como nota de texto na Caixa de entrada
        [$x, $new] = cc_asset_create($pdo, $text, $o + ['name' => 'texto.txt', 'mime' => 'text/plain', 'kind' => 'file',
                                                        'title' => $title ?: mb_strimwidth(preg_replace('/\s+/u', ' ', $text), 0, 80, '…')]);
    } else {
        $link = $kind === 'video' && $src !== '' && $page === '' ? $src : ($page !== '' ? $page : $src);
        if ($link === '') throw new RuntimeException('Nada para guardar: falta o endereço ou o ficheiro.');
        [$x, $new] = cc_asset_from_link($pdo, cap_url_at($link, $t), $o);
        if ($title !== '' && $new && trim((string)$x['title']) === '') $pdo->prepare('UPDATE cc_assets SET title = ? WHERE id = ?')->execute([$title, (int)$x['id']]);
    }
    if ($new) $x = cc_place_original($pdo, $x, $board, true, $uid);

    // tudo entra na Caixa de entrada (mesmo o que já existia) e guarda de onde veio
    // guardada outra vez: fica a origem nova (as notas juntam-se)
    $prev = json_decode((string)($x['capture'] ?? ''), true);
    $merged = $cap;
    if (!$new && is_array($prev) && !empty($prev['note']) && $prev['note'] !== $note) $merged['note'] = trim($prev['note'] . "\n" . $note);
    $pdo->prepare('UPDATE cc_assets SET inbox = 1, capture = ? WHERE id = ?')->execute([json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)$x['id']]);
    cc_asset_reindex($pdo, (int)$x['id']);

    // a análise da IA (§23.5): na fila, se ainda não foi feita
    require_once __DIR__ . '/cc_ai.php';
    $cfg = cc_ai_config();
    $x = cc_asset_get($pdo, (int)$x['id']);
    if ($cfg && $x['thumbs'] && !in_array((string)$x['ai_status'], ['queued', 'running', 'done'], true)) {
        $pdo->prepare("UPDATE cc_assets SET ai_status = 'queued' WHERE id = ?")->execute([(int)$x['id']]);
        cc_job_add($pdo, 'analyze', (int)$x['id'], null, null, $uid);
    }

    // e, com um quadro, um cartão (o texto selecionado vira nota ao lado)
    $cardId = null;
    if ($board) {
        [$cx, $cy] = cc_board_free_spot($pdo, (int)$board['id']);
        $w = 280.0;
        $ratio = $x['width'] && $x['height'] ? (float)$x['height'] / (float)$x['width'] : 0.62;
        $type = in_array($x['kind'], ['image', 'video', 'link'], true) ? (string)$x['kind'] : 'file';
        $cardId = cc_card_add($pdo, (int)$board['id'], ['type' => $type, 'asset_id' => (int)$x['id'], 'x' => $cx, 'y' => $cy, 'w' => $w,
                                                       'h' => $type === 'file' ? 0 : round($w * min(2.5, $ratio)), 'content' => $note !== '' ? ['caption' => mb_substr($note, 0, 300)] : []], $uid);
        if ($text !== '') {
            cc_card_add($pdo, (int)$board['id'], ['type' => 'note', 'x' => $cx + $w + 20, 'y' => $cy, 'w' => 260,
                                                 'content' => ['text' => '«' . $text . '»' . ($page !== '' ? "\n— " . (string)parse_url($page, PHP_URL_HOST) : '')]], $uid);
        }
        cc_board_touch($pdo, (int)$board['id']);
        cap_remember_board($pdo, $uid, (int)$board['id']);
    }
    return ['asset' => cc_asset_get($pdo, (int)$x['id']), 'board' => $board, 'card' => $cardId, 'new' => $new];
}
