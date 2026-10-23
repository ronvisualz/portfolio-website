<?php
/**
 * topper. admin — guardar de fora para o Centro Criativo (§26.2–26.3). Respostas em JSON.
 *
 * Quem entra: a extensão «Guardar na topper.» e o Atalho do iOS com o token pessoal
 * (Authorization: Bearer tpk_… ou X-Topper-Token; Perfil › Ligações, revogável); a página
 * Partilhar do Android (PWA) com a sessão normal e o token CSRF. Nunca a palavra-passe.
 * Precisa da aba Centro Criativo com edição; os quadros respeitam o acesso ao negócio.
 *
 *   GET  a=me                 quem sou, os quadros recentes e o destino por defeito
 *   GET  a=boards  q          procurar um quadro
 *   POST a=save               kind (image|video|link|page|text|area|frame|file), url, src, title, text, note,
 *                             t (segundos do vídeo), author, site, board (0 = Caixa de entrada, -1 = por defeito), via;
 *                             file (multipart, opcional: área, frame ou ficheiro partilhado), prev (frame de um vídeo)
 */

/** O token pessoal do pedido (cabeçalho ou campo), ou ''. */
function cap_req_token(): string {
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(tpk_[A-Za-z0-9_-]+)$/', trim($h), $m)) return $m[1];
    $x = trim((string)($_SERVER['HTTP_X_TOPPER_TOKEN'] ?? $_POST['token'] ?? ''));
    return preg_match('/^tpk_[A-Za-z0-9_-]+$/', $x) ? $x : '';
}
$capToken = cap_req_token();
if ($capToken !== '') define('TOPPER_NO_SESSION', true);   // com token não há sessão nem cookie

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/cc.php';
require_once __DIR__ . '/inc/capture.php';

function capout(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// a extensão (Chrome/Edge/Brave/Arc) chama de chrome-extension://…; só com token, sem cookies
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if (preg_match('~^(chrome|moz|safari-web)-extension://[a-z0-9-]+$~i', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Authorization, X-Topper-Token, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if (!cc_ready($pdo) || !cc_rt_ready($pdo)) capout(503, ['ok' => false, 'error' => 'A plataforma está a atualizar. Tente daqui a um minuto.']);

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($capToken !== '') {
    $row = ut_auth($pdo, $capToken);
    if (!$row) capout(401, ['ok' => false, 'error' => 'O token não é válido ou foi revogado. Crie outro em Perfil › Ligações.', 'code' => 'token']);
    $_SESSION = ['user' => session_user_fields($row)];   // só para este pedido (não há sessão)
    $user = $_SESSION['user'];
} else {
    if (current_user() === null) capout(401, ['ok' => false, 'error' => 'A sessão terminou. Entre outra vez.', 'code' => 'login']);
    $user = require_login();
    if ($post) {
        $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) capout(400, ['ok' => false, 'error' => 'Pedido inválido. Recarregue a página e tente outra vez.']);
    }
}
if (!can('criativo', PERM_EDIT)) capout(403, ['ok' => false, 'error' => 'A sua conta não guarda no Centro Criativo (precisa de edição na aba Centro Criativo).']);

$a = (string)($_REQUEST['a'] ?? '');
try {
    switch ($a) {
    case 'me': {
        $def = cap_default_board($pdo, (int)$user['id']);
        capout(200, ['ok' => true, 'user' => ['name' => (string)$user['name']], 'boards' => cap_boards($pdo, '', 12),
                     'default' => $def ? ['id' => (int)$def['id'], 'title' => (string)$def['title']] : null,
                     'inbox' => '/admin/criativo.php?t=entrada']);
    }
    case 'boards':
        capout(200, ['ok' => true, 'boards' => cap_boards($pdo, mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80), 20)]);
    case 'save': {
        if (!$post) capout(405, ['ok' => false, 'error' => 'Pedido inválido.']);
        $file = null;
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            if ((int)$_FILES['file']['size'] > CAP_MAX_BYTES) capout(413, ['ok' => false, 'error' => 'O ficheiro passa dos 40 MB.']);
            $file = ['bytes' => (string)file_get_contents($_FILES['file']['tmp_name']), 'name' => (string)$_FILES['file']['name'], 'mime' => (string)$_FILES['file']['type']];
            // a pré-visualização de um vídeo (um frame feito no browser), para as miniaturas e a análise
            if (!empty($_FILES['prev']['tmp_name']) && is_uploaded_file($_FILES['prev']['tmp_name']) && (int)$_FILES['prev']['size'] < 12582912) $file['preview'] = (string)file_get_contents($_FILES['prev']['tmp_name']);
        } elseif (!empty($_FILES['file']['error']) && (int)$_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
            capout(400, ['ok' => false, 'error' => 'O ficheiro não chegou (demasiado grande?).']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // a IA e as miniaturas não seguram a sessão
        // o Atalho do iOS manda tudo como «ficheiro»: um link ou um texto chegam como ficheiro de texto
        if ($file && strlen($file['bytes']) < 20000 && (str_starts_with((string)$file['mime'], 'text/') || preg_match('/\.(txt|url|webloc)$/i', (string)$file['name']))) {
            $txt = trim((string)$file['bytes']);
            if (preg_match('~<string>(https?://[^<]+)</string>~', $txt, $m)) $txt = html_entity_decode($m[1]);   // .webloc
            elseif (preg_match('~^URL=(https?://\S+)~m', $txt, $m)) $txt = $m[1];                            // .url
            if (preg_match('~^https?://\S+$~', $txt)) { $_POST['url'] = $_POST['url'] ?? $txt; $_POST['kind'] = 'link'; }
            else { $_POST['text'] = trim(($_POST['text'] ?? '') . "\n" . $txt); $_POST['kind'] = 'text'; }
            $file = null;
        }
        $in = [];
        foreach (['kind', 'url', 'src', 'title', 'text', 'note', 't', 'author', 'site', 'board', 'via'] as $k) if (isset($_POST[$k])) $in[$k] = (string)$_POST[$k];
        if ($capToken !== '' && empty($in['via'])) $in['via'] = 'extension';
        $r = cap_save($pdo, $user, $in, $file);
        $x = $r['asset'];
        capout(200, ['ok' => true, 'new' => $r['new'],
                     'msg' => ($r['board'] ? 'Guardado em «' . $r['board']['title'] . '» e na Caixa de entrada.' : 'Guardado na Caixa de entrada.') . ' A IA analisa a seguir.',
                     'asset' => ['id' => (int)$x['id'], 'title' => (string)$x['title'], 'kind' => (string)$x['kind'], 'thumb' => $x['thumbs'] ? cc_file_url($x, 256) : ''],
                     'board' => $r['board'] ? ['id' => (int)$r['board']['id'], 'title' => (string)$r['board']['title'], 'url' => '/admin/criativo.php?b=' . (int)$r['board']['id'] . ($r['card'] ? '&card=' . $r['card'] : '')] : null,
                     'inbox' => '/admin/criativo.php?t=entrada']);
    }
    }
    capout(400, ['ok' => false, 'error' => 'Pedido inválido.']);
} catch (RuntimeException $e) {
    capout(400, ['ok' => false, 'error' => $e->getMessage()]);
}
