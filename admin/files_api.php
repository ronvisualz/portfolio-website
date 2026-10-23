<?php
/**
 * topper. admin — anexar e abrir os ficheiros do registo (§10.15)
 *
 * Os bytes vão do browser diretamente para o Drive (URL pré-assinado, como no próprio Drive);
 * aqui só se decide a pasta, se verificam as permissões e se regista o que chegou.
 *
 *   POST a=sign   target=deal|piece, id, name, size, type, section?  → {key, url}: enviar com PUT
 *   POST a=done   key                                              → regista o ficheiro que chegou
 *   GET  a=open   f=<id> [dl=1]  ·  d=<negócio>&k=<chave> [dl=1]  → abre (se mudou de sítio, encontra-o)
 *   GET  a=show   f=<id>  ·  d=<negócio>&k=<chave>                 → o Drive, na pasta, com o ficheiro selecionado
 *
 * A faturação só para quem vê o Financeiro; o resto, para quem vê o registo a que o ficheiro pertence.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/files.php';
require_once __DIR__ . '/inc/reach.php';
require_once __DIR__ . '/inc/layout.php';

function fout(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function ffail(string $msg, int $code = 400): void {
    fout($code, ['ok' => false, 'error' => $msg]);
}
/** Num GET (abrir um ficheiro): volta à página de onde veio com o aviso. */
function fback(string $msg): void {
    flash($msg, 'err');
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    redirect($ref !== '' && parse_url($ref, PHP_URL_HOST) === $host ? (string)parse_url($ref, PHP_URL_PATH) . (($q = parse_url($ref, PHP_URL_QUERY)) ? '?' . $q : '') : home_url());
}

$a = (string)($_REQUEST['a'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && current_user() === null) fout(401, ['ok' => false, 'error' => 'A sessão terminou. Entre outra vez.']);
$user = require_login();
if (!files_ready($pdo)) $post ? ffail('A base de dados está a atualizar. Tente daqui a pouco.', 503) : fback('A base de dados está a atualizar. Tente daqui a pouco.');
if (!files_on()) $post ? ffail('O Drive não está ligado neste servidor.', 503) : fback('O Drive não está ligado neste servidor.');
if ($post) {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) ffail('Pedido inválido. Recarregue a página e tente outra vez.', 403);
}

/** O ficheiro pedido: uma linha do registo (f=) ou uma chave dentro da pasta de um negócio (d= & k=). Null se não puder ver. */
function file_req(PDO $pdo): ?array {
    if (!empty($_GET['f'])) {
        $row = files_get($pdo, (int)$_GET['f']);
        return $row && files_can_see($pdo, $row) ? $row : null;
    }
    $d = record_get($pdo, (int)($_GET['d'] ?? 0));
    $k = (string)($_GET['k'] ?? '');
    $folder = $d ? (string)$d['drive_folder'] : '';
    if (!$d || !record_reach($pdo, $d) || $folder === '' || !r2_key_ok($k) || strncmp($k, $folder, strlen($folder)) !== 0 || !drive_can_see($k)) return null;
    $st = $pdo->prepare('SELECT * FROM files WHERE key_hash = ?');
    $st->execute([files_hash($k)]);
    return $st->fetch() ?: ['id' => 0, 'obj_key' => $k, 'etag' => '', 'size' => 0, 'status' => 'ok', 'mime' => r2_mime($k), 'deal_id' => (int)$d['id'], 'section' => ''];
}

try {
switch ($a) {

case 'sign': {
    $target = (string)($_POST['target'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $name = r2_clean_name((string)($_POST['name'] ?? ''));
    $size = (int)($_POST['size'] ?? -1);
    if ($name === '') ffail('Nome de ficheiro inválido.');
    if ($size < 0) ffail('Tamanho inválido.');
    if ($size > FILES_PUT_MAX) ffail('«' . $name . '» passa de 5 GB: envie-o pelo Drive, que o divide em partes.');
    $reg = ['by' => (int)$user['id'], 'name' => $name];
    if ($target === 'deal') {
        $d = record_get($pdo, $id);
        if (!$d || !record_reach($pdo, $d, PERM_EDIT)) ffail('Sem permissão para anexar ficheiros a este registo.', 403);
        $lead = in_array($d['phase'], ['lead', 'archived'], true);
        $section = $lead ? '' : (string)($_POST['section'] ?? '');
        if (!isset(FILES_SECTIONS[$section]) || $section === 'lead') $section = '';
        if ($section === 'faturacao' && !can('financeiro', PERM_EDIT)) ffail('Os documentos de faturação só podem ser anexados por quem edita o Financeiro.', 403);
        $folder = files_folder($pdo, $d);
        if ($folder === null) ffail('Não foi possível preparar a pasta deste registo.');
        $folder = files_section_folder($folder, $section);
        $reg += ['deal_id' => (int)$d['id'], 'section' => $section, 'origin' => 'attach'];
    } elseif ($target === 'piece') {
        if (!can('marketing', PERM_EDIT)) ffail('Sem permissão para anexar ficheiros a esta peça.', 403);
        require_once __DIR__ . '/inc/marketing.php';
        $p = mkt_piece($pdo, $id);
        if (!$p) ffail('Peça não encontrada.', 404);
        $folder = mkt_drive_folder($pdo, $p);
        if ($folder === null) ffail('Não foi possível preparar a pasta desta peça.');
        $reg += ['mkt_piece_id' => (int)$p['id'], 'deal_id' => $p['deal_id'] ? (int)$p['deal_id'] : null, 'section' => 'marketing', 'origin' => 'marketing', 'label' => (string)$p['title']];
    } else {
        ffail('Pedido inválido.');
    }
    $key = files_free_key($folder, $name);
    $_SESSION['files_up'][$key] = $reg;
    if (count($_SESSION['files_up']) > 50) array_shift($_SESSION['files_up']);
    fout(200, ['ok' => true, 'key' => $key, 'url' => r2_presign('PUT', $key, R2_URL_TTL), 'type' => r2_mime($name)]);
}

case 'done': {
    $key = (string)($_POST['key'] ?? '');
    $reg = $_SESSION['files_up'][$key] ?? null;
    if (!is_array($reg)) ffail('Este envio não pertence a esta sessão. Comece outra vez.');
    unset($_SESSION['files_up'][$key]);
    session_write_close();
    $h = r2_head($key);
    if ($h === null) ffail('O ficheiro não chegou ao Drive. Tente outra vez.');
    $fid = files_register($pdo, $key, ['etag' => $h['etag'], 'size' => $h['size'], 'mime' => r2_mime($key)] + $reg);
    drive_catalog_invalidate();
    if (!empty($reg['deal_id']) && empty($reg['mkt_piece_id'])) {
        deal_log($pdo, (int)$reg['deal_id'], 'file', ['note' => drive_base($key) . ($reg['section'] !== '' ? ' · ' . FILES_SECTIONS[$reg['section']][1] : '')]);
    }
    if (!empty($reg['mkt_piece_id'])) {
        require_once __DIR__ . '/inc/marketing.php';
        if (function_exists('mkt_log')) mkt_log($pdo, (int)$reg['mkt_piece_id'], 'm_edit', 'Ficheiro anexado: ' . drive_base($key), (int)$user['id']);
    }
    fout(200, ['ok' => true, 'id' => $fid, 'key' => $key, 'name' => drive_base($key), 'msg' => '«' . drive_base($key) . '» guardado em ' . drive_parent($key)]);
}

case 'open':
case 'show': {
    $row = file_req($pdo);
    if (!$row) { http_response_code(404); fback('Ficheiro não encontrado, ou sem acesso a ele.'); }
    if ($a === 'show' && !can('drive')) fback('A sua conta não tem acesso ao Drive.');
    $loc = (int)$row['id'] ? files_locate($pdo, $row) : (r2_head((string)$row['obj_key']) ? ['state' => 'ok', 'key' => (string)$row['obj_key']] : ['state' => 'missing', 'key' => (string)$row['obj_key']]);
    if ($loc['state'] === 'moving') fback('Este ficheiro está a mudar de pasta com o registo. Tente outra vez daqui a um minuto.');
    if ($loc['state'] === 'trash') {
        if (can('drive')) { flash('«' . drive_base($loc['key']) . '» está no Lixo do Drive. Pode repô-lo daqui.', 'err'); redirect('/admin/drive.php?v=trash'); }
        fback('Este ficheiro está no Lixo do Drive. Peça a quem tem acesso ao Drive para o repor.');
    }
    if ($loc['state'] !== 'ok') fback('Este ficheiro já não está no Drive.');
    if (!drive_can_see($loc['key'])) fback('Os documentos de faturação só estão disponíveis para quem tem acesso ao Financeiro.');
    if ($a === 'show') redirect(files_drive_href($loc['key']));
    $dl = !empty($_GET['dl']);
    redirect(r2_get_url($loc['key'], !$dl, $dl ? '' : (string)($row['mime'] ?: r2_mime($loc['key'])), 600));
}

default:
    $post ? ffail('Pedido desconhecido.', 404) : fback('Pedido desconhecido.');
}
} catch (Throwable $e) {
    error_log('files_api ' . $a . ': ' . $e->getMessage());
    $msg = $e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Não foi possível concluir. Tente outra vez.';
    $post ? ffail($msg, 500) : fback($msg);
}
