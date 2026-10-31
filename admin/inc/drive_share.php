<?php
/**
 * topper. admin — Drive: partilhas «Só ver» / «Pode editar» (§32.2)
 *
 *  · drive_share_allows()  o que quem tem o link pode fazer — a única porta, verificada no servidor em cada pedido.
 *                          «Só ver»: ver e descarregar (pasta: também o ZIP). «Pode editar» numa pasta: carregar,
 *                          criar subpastas, renomear e substituir versões; num ficheiro: substituir. Apagar (ficheiros,
 *                          pastas ou versões), mover ou mandar para o Lixo: nunca, com nenhuma permissão.
 *  · drive_share_update()  muda a permissão, a validade ou a password sem mudar o link (o token fica).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/drive.php';
require_once __DIR__ . '/drive_store.php';

const DRIVE_SHARE_PERMS = ['view' => 'Só ver', 'edit' => 'Pode editar'];
const DRIVE_SHARE_EDIT_ACTIONS = ['upload', 'mkdir', 'rename', 'replace'];

function drive_share_allows(array $share, string $action): bool {
    if (($share['state'] ?? 'ok') !== 'ok') return false;
    $folder = !empty($share['folder']);
    if ($action === 'view' || $action === 'download') return true;
    if ($action === 'zip') return $folder;
    if (($share['perm'] ?? 'view') !== 'edit') return false;
    return $folder ? in_array($action, DRIVE_SHARE_EDIT_ACTIONS, true) : $action === 'replace';
}

/**
 * Muda uma partilha ativa: perm ('view'|'edit'), hours (1–168, a contar de agora) ou forever (só pastas), password
 * (nova) ou clear_password. O link (token) nunca muda. Lança RuntimeException com o código HTTP.
 */
function drive_share_update(PDO $pdo, int $id, array $in): array {
    require_once __DIR__ . '/drive_store.php';
    $s = drive_share_get($pdo, $id);
    if (!$s || $s['revoked'] || (!$s['forever'] && $s['exp'] <= time())) throw new RuntimeException('Este link já não está ativo.', 404);
    $set = [];
    if (array_key_exists('perm', $in)) {
        if (!isset(DRIVE_SHARE_PERMS[$in['perm']])) throw new RuntimeException('Permissão inválida.', 400);
        $set['perm'] = $in['perm'];
    }
    if (!empty($in['forever'])) {
        if (!$s['folder']) throw new RuntimeException('Só uma pasta pode ficar partilhada sem prazo.', 400);
        $set['expires_at'] = DRIVE_SHARE_FOREVER;
    } elseif (array_key_exists('hours', $in)) {
        $h = filter_var($in['hours'], FILTER_VALIDATE_INT);
        if (!is_int($h) || $h < 1 || $h > DRIVE_SHARE_MAX_HOURS) throw new RuntimeException('Escolha uma duração entre 1 hora e 7 dias.', 400);
        $set['expires_at'] = drive_now($h * 3600);
    }
    if (!empty($in['clear_password'])) $set['pw_hash'] = null;
    elseif (isset($in['password']) && $in['password'] !== '') {
        $pw = (string)$in['password'];
        if (mb_strlen($pw) < DRIVE_SHARE_PW_MIN || mb_strlen($pw) > DRIVE_SHARE_PW_MAX) throw new RuntimeException('A password do link tem de ter entre ' . DRIVE_SHARE_PW_MIN . ' e ' . DRIVE_SHARE_PW_MAX . ' caracteres.', 400);
        $set['pw_hash'] = password_hash($pw, PASSWORD_DEFAULT);
    }
    if ($set) $pdo->prepare('UPDATE drive_shares SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), $id]);
    return drive_share_get($pdo, $id);
}

// ── quem tem um link «Pode editar» (partilha.php, sem sessão) ─────────────────────────────

/**
 * Um caminho relativo enviado pelo visitante → a chave dentro da pasta partilhada, ou null. Nada de «..», «/» inicial,
 * barras invertidas, segmentos vazios, «.topper», caracteres de controlo nem codificações que escondam «..».
 */
function drive_share_rel(string $base, string $rel, bool $folder = false): ?string {
    $body = $folder ? rtrim($rel, '/') : $rel;
    if ($body === '' || strpos($rel, "\0") !== false || strpos($rel, '\\') !== false || $rel[0] === '/') return null;
    foreach (explode('/', $body) as $seg) {
        $dec = rawurldecode($seg);
        if ($seg === '' || trim($dec) === '' || $dec === '.' || $dec === '..' || $dec === '.topper' || strlen($seg) > R2_NAME_MAX) return null;
    }
    $key = $base . $body . ($folder ? '/' : '');
    if (!r2_key_ok($key, $folder) || drive_hidden($key) || strncmp($key, $base, strlen($base)) !== 0) return null;
    return $key;
}

/**
 * O que quem tem o link vê (e onde pode escrever): nunca o sistema (.topper); os documentos privados e a faturação só
 * se a partilha os incluir de propósito, ou se a própria pasta partilhada já for privada / de faturação (aí a partilha
 * foi confirmada para isso mesmo).
 */
function drive_share_sees(array $s, string $key): bool {
    if (drive_hidden($key)) return false;
    if (!empty($s['inc_private'])) return true;
    require_once __DIR__ . '/ai.php';
    $root = (string)$s['k'];
    if (ai_private($key) && !ai_private($root)) return false;
    if (drive_billing($key) && !drive_billing($root)) return false;
    return true;
}

/** O IP sem os últimos bytes (IPv4 a.b.x.x; IPv6 os 3 primeiros grupos), para o registo. */
function drive_ip_part(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { $p = explode('.', $ip); return $p[0] . '.' . $p[1] . '.x.x'; }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return implode(':', array_slice(explode(':', $ip), 0, 3)) . ':…';
    return '?';
}

/** O token do formulário da página da partilha (as ações POST trazem-no no cabeçalho X-Share-Form). */
function drive_share_form_token(array $s): string {
    require_once __DIR__ . '/secrets.php';
    return hash_hmac('sha256', 'share-form|' . $s['id'] . '|' . $s['token'], app_key());
}

/** Um nome livre ao lado: «nome (2).ext», «nome (3).ext»… */
function drive_free_key(string $key): string {
    if (r2_head($key) === null) return $key;
    $dir = ($p = strrpos($key, '/')) === false ? '' : substr($key, 0, $p + 1);
    $name = substr($key, strlen($dir));
    $dot = strrpos($name, '.');
    [$stem, $ext] = $dot > 0 ? [substr($name, 0, $dot), substr($name, $dot)] : [$name, ''];
    for ($i = 2; $i < 1000; $i++) { $k = $dir . $stem . ' (' . $i . ')' . $ext; if (r2_head($k) === null) return $k; }
    throw new RuntimeException('Demasiados ficheiros com este nome.', 409);
}

/** Guarda a versão atual no histórico (.topper/versions/…) antes de ser substituída. Devolve o id da versão, ou null. */
function drive_version_keep(PDO $pdo, string $key, string $by, ?int $shareId): ?int {
    $h = r2_head($key);
    if ($h === null) return null;
    $ver = DRIVE_SYS . 'versions/' . sha1($key) . '/' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(2)) . '-' . drive_base($key);
    r2_copy_any($key, $ver, (int)$h['size']);
    $pdo->prepare('INSERT INTO drive_versions (obj_key, key_hash, ver_key, size, etag, by_label, share_id, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$key, hash('sha256', $key), $ver, (int)$h['size'], (string)($h['etag'] ?? ''), mb_substr($by, 0, 120), $shareId, drive_now()]);
    return (int)$pdo->lastInsertId();
}

/** As versões anteriores de um ficheiro (a mais recente primeiro). */
function drive_versions(PDO $pdo, string $key): array {
    if (!db_has_table($pdo, 'drive_versions')) return [];
    $st = $pdo->prepare('SELECT id, ver_key, size, by_label, created_at FROM drive_versions WHERE key_hash = ? ORDER BY id DESC LIMIT 50');
    $st->execute([hash('sha256', $key)]);
    return $st->fetchAll();
}

function drive_share_log_add(PDO $pdo, int $shareId, string $action, string $key, string $detail, string $visitor): void {
    $pdo->prepare('INSERT INTO drive_share_log (share_id, action, obj_key, detail, visitor, ip_part, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$shareId, $action, $key, mb_substr($detail, 0, 300), mb_substr($visitor, 0, 80), drive_ip_part(), drive_now()]);
    try { drive_share_notify($pdo, $shareId, $visitor, $action, $key); } catch (Throwable $e) { error_log('drive_share_notify: ' . $e->getMessage()); }
}

/**
 * Avisa por email quem criou a partilha — o primeiro movimento de cada meia hora (o resto fica nas notificações),
 * para um envio de 50 fotos não dar 50 emails.
 */
function drive_share_notify(PDO $pdo, int $shareId, string $visitor, string $action, string $key): void {
    $st = $pdo->prepare('SELECT COUNT(*) FROM drive_share_log WHERE share_id = ? AND created_at >= ?');
    $st->execute([$shareId, drive_now(-1800)]);
    if ((int)$st->fetchColumn() > 1) return;
    $st = $pdo->prepare('SELECT s.obj_key, u.email, u.name FROM drive_shares s JOIN users u ON u.id = s.created_by WHERE s.id = ? AND u.is_active = 1');
    $st->execute([$shareId]);
    $r = $st->fetch();
    if (!$r || !filter_var((string)$r['email'], FILTER_VALIDATE_EMAIL)) return;
    $verb = ['upload' => 'carregou', 'replace' => 'substituiu', 'mkdir' => 'criou a pasta', 'rename' => 'renomeou'][$action] ?? $action;
    $folder = rtrim((string)$r['obj_key'], '/');
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/team.php';
    $parent = substr($key, -1) === '/' ? $key : substr($key, 0, (int)strrpos($key, '/') + 1);
    app_mail($pdo, (string)$r['email'], $visitor . ' ' . $verb . ' «' . drive_base(rtrim($key, '/')) . '» no link partilhado',
        'Olá ' . (explode(' ', trim((string)$r['name']))[0] ?: 'olá') . ",

" . $visitor . ' ' . $verb . ' «' . drive_base(rtrim($key, '/')) . '» através do link «Pode editar» da pasta «' . drive_base($folder) . "».
"
        . "Nada é apagado por um link partilhado; as versões substituídas ficam no histórico.

Ver na Drive: " . team_app_url() . '/admin/drive.php?p=' . rawurlencode($parent)
        . "

(Os próximos movimentos da próxima meia hora ficam só nas notificações.)
");
}

/** Os avisos para quem criou a partilha: o que os visitantes fizeram nos últimos 7 dias (§32.2). */
function drive_share_alerts(PDO $pdo, int $uid): array {
    if (!db_has_table($pdo, 'drive_share_log')) return [];
    $st = $pdo->prepare("SELECT l.*, s.obj_key AS share_key FROM drive_share_log l JOIN drive_shares s ON s.id = l.share_id
                          WHERE s.created_by = ? AND l.created_at >= ? ORDER BY l.id DESC LIMIT 20");
    $st->execute([$uid, drive_now(-7 * 86400)]);
    $verb = ['upload' => 'carregou', 'replace' => 'substituiu', 'mkdir' => 'criou a pasta', 'rename' => 'renomeou'];
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $folder = rtrim((string)$r['share_key'], '/');
        $out[] = ['key' => 'dsl:' . $r['id'], 'kind' => 'drive', 'icon' => 'drive', 'sev' => 'info', 'date' => substr((string)$r['created_at'], 0, 10),
                  'title' => $r['visitor'] . ' ' . ($verb[$r['action']] ?? $r['action']) . ' «' . drive_base(rtrim((string)$r['obj_key'], '/')) . '»',
                  'sub' => 'Link partilhado de «' . drive_base($folder) . '»' . ($r['detail'] !== '' ? ' · ' . $r['detail'] : ''),
                  'url' => '/admin/drive.php?p=' . rawurlencode((string)(substr((string)$r['obj_key'], -1) === '/' ? $r['obj_key'] : substr((string)$r['obj_key'], 0, (int)strrpos((string)$r['obj_key'], '/') + 1)))];
    }
    return $out;
}

/**
 * Uma ação de quem tem o link (POST JSON a partilha.php). Devolve [código HTTP, resposta]. Ações: sign (pedir para
 * carregar/substituir), done (confirmar o envio), mkdir, rename. Não existe nenhuma ação que apague.
 */
function drive_share_visitor(PDO $pdo, array $s, array $in): array {
    $a = (string)($in['a'] ?? '');
    if (!in_array($a, ['sign', 'done', 'mkdir', 'rename'], true)) return [400, ['ok' => false, 'error' => 'Ação inválida.']];
    $who = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string)($in['name'] ?? '')));
    if ($who === '' || mb_strlen($who) > 80) return [400, ['ok' => false, 'error' => 'Escreva o seu nome (até 80 caracteres).']];
    $base = $s['folder'] ? $s['k'] : '';
    $rel = is_string($in['rel'] ?? null) ? $in['rel'] : '';
    $isReplace = !empty($in['replace']) || !empty($in['replaced']);
    // o alvo: dentro da pasta partilhada; num link de ficheiro, só o próprio ficheiro
    $key = $s['folder'] ? drive_share_rel($base, $rel, $a === 'mkdir') : ($rel === '' || $rel === drive_base($s['k']) ? $s['k'] : null);
    if ($key === null) return [400, ['ok' => false, 'error' => 'Caminho inválido.']];
    if ($s['folder'] && !drive_share_sees($s, $key)) return [404, ['ok' => false, 'error' => 'Esse item não existe neste link.']];
    $action =$a === 'sign' || $a === 'done' ? ($isReplace ? 'replace' : 'upload') : $a;
    if (!drive_share_allows($s, $action)) return [403, ['ok' => false, 'error' => 'Este link não deixa fazer isto.']];
    $relOf = fn(string $k) => $s['folder'] ? substr($k, strlen($base)) : drive_base($k);
    switch ($a) {
        case 'sign': {
            $size = filter_var($in['size'] ?? null, FILTER_VALIDATE_INT);
            $max = max(1, (int)setting($pdo, 'drive_share_upload_mb', 2048)) * 1048576;
            if (!is_int($size) || $size < 0) return [400, ['ok' => false, 'error' => 'Tamanho inválido.']];
            if ($size > $max) return [413, ['ok' => false, 'error' => 'O ficheiro passa o limite de ' . drive_size($max) . ' por envio.']];
            if ($isReplace) {
                if (r2_head($key) === null) return [404, ['ok' => false, 'error' => 'Esse ficheiro já não existe.']];
                drive_version_keep($pdo, $key, $who, (int)$s['id']);
            } else {
                $key = drive_free_key($key);
            }
            return [200, ['ok' => true, 'rel' => $relOf($key), 'url' => r2_presign('PUT', $key, 900), 'method' => 'PUT']];
        }
        case 'done': {
            $h = r2_head($key);
            if ($h === null) return [404, ['ok' => false, 'error' => 'O envio não chegou.']];
            $max = max(1, (int)setting($pdo, 'drive_share_upload_mb', 2048)) * 1048576;
            if ((int)$h['size'] > $max) return [413, ['ok' => false, 'error' => 'O ficheiro passa o limite por envio.']];
            drive_catalog_invalidate();
            drive_share_log_add($pdo, (int)$s['id'], $action, $key, drive_size((int)$h['size']), $who);
            return [200, ['ok' => true, 'rel' => $relOf($key)]];
        }
        case 'mkdir': {
            if (drive_folder_exists($key)) return [409, ['ok' => false, 'error' => 'Já existe uma pasta com esse nome.']];
            r2_mkdir($key);
            drive_catalog_invalidate();
            drive_share_log_add($pdo, (int)$s['id'], 'mkdir', $key, '', $who);
            return [200, ['ok' => true, 'rel' => $relOf($key)]];
        }
        case 'rename': {
            $to = is_string($in['to'] ?? null) ? trim($in['to']) : '';
            $isFolder = substr($rel, -1) === '/';
            if ($isFolder) $key = drive_share_rel($base, $rel, true) ?? '';
            if ($key === '' || $key === $base) return [400, ['ok' => false, 'error' => 'Caminho inválido.']];
            if (!drive_share_sees($s, $key)) return [404, ['ok' => false, 'error' => 'Esse item não existe neste link.']];
            if ($to === '' || strpos($to, '/') !== false || $to === '.' || $to === '..' || rawurldecode($to) === '..') return [400, ['ok' => false, 'error' => 'Nome inválido.']];
            $parent = substr(rtrim($key, '/'), 0, (int)strrpos(rtrim($key, '/'), '/') + 1);
            $new = drive_share_rel($base, substr($parent, strlen($base)) . $to, $isFolder);
            if ($new === null || !drive_share_sees($s, $new)) return [400, ['ok' => false, 'error' => 'Nome inválido.']];
            if ($new === $key) return [200, ['ok' => true, 'rel' => $relOf($new)]];
            if ($isFolder ? drive_folder_exists($new) : r2_head($new) !== null) return [409, ['ok' => false, 'error' => 'Já existe um item com esse nome: escolha outro.']];
            if (!$isFolder && r2_head($key) === null) return [404, ['ok' => false, 'error' => 'Esse ficheiro já não existe.']];
            drive_share_rename($pdo, $key, $new);
            drive_share_log_add($pdo, (int)$s['id'], 'rename', $new, 'antes: ' . drive_base(rtrim($key, '/')), $who);
            return [200, ['ok' => true, 'rel' => $relOf($new)]];
        }
    }
    return [400, ['ok' => false, 'error' => 'Ação inválida.']];
}

/** Renomear (sem sobrescrever — já confirmado): o objeto (ou a pasta) passa ao nome novo e as referências seguem-no. */
function drive_share_rename(PDO $pdo, string $from, string $to): void {
    $after = '';
    for ($i = 0; $i < 50; $i++) {   // uma pasta grande vai por lotes
        $r = drive_transfer($from, $to, true, $after, true, fn() => true);
        if (!empty($r['done'])) break;
        $after = (string)($r['after'] ?? '');
    }
    if (empty($r['done'])) throw new RuntimeException('Não foi possível renomear agora. Tente outra vez.', 503);
    require_once __DIR__ . '/drive_store.php';
    drive_store_moved($pdo, $from, $to);
    drive_catalog_invalidate();
}
