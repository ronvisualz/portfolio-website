<?php
/**
 * topper. admin — Drive: o que fica na base de dados.
 *
 * Favoritos e recentes (partilhados pela equipa), o Lixo e os links de
 * partilha. As chaves do R2 têm até 1024 bytes: as tabelas guardam a chave
 * (obj_key) e o sha256 dela (key_hash) para os índices.
 * Datas sempre escritas aqui em UTC ('Y-m-d H:i:s'); para fora saem em unix.
 */

declare(strict_types=1);

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/drive.php';

const DRIVE_RECENT_MAX = 200;
const DRIVE_TRASH_DAYS = 30;
const DRIVE_SHARE_MAX_HOURS = 168;
/** Links de pasta «sem prazo»: a base exige uma data, por isso fica no último dia que o DATETIME aceita. */
const DRIVE_SHARE_PW_MIN = 4;
const DRIVE_SHARE_PW_MAX = 100;
const DRIVE_SHARE_PW_TRIES = 5;      // tentativas falhadas seguidas…
const DRIVE_SHARE_PW_LOCK = 900;     // …e a espera (s) até poder tentar outra vez
const DRIVE_SHARE_FOREVER = '9999-12-31 23:59:59';

function drive_key_hash(string $k): string {
    return hash('sha256', $k);
}

/** Agora (ou daqui a $plus segundos), em UTC, para a base de dados. */
function drive_now(int $plus = 0): string {
    return gmdate('Y-m-d H:i:s', time() + $plus);
}

/** Uma data da base (UTC) → unix. */
function drive_ts($s): int {
    if ($s === null || $s === '') return 0;
    $t = strtotime((string)$s . ' UTC');
    return $t === false ? 0 : $t;
}

/** $k é $from, ou está dentro da pasta $from? */
function drive_under(string $k, string $from): bool {
    return $k === $from || (substr($from, -1) === '/' && strncmp($k, $from, strlen($from)) === 0);
}

/** INSERT … ou substitui, pela chave primária (MySQL e SQLite). */
function drive_upsert(PDO $pdo, string $table, array $row, array $update): void {
    $cols = array_keys($row);
    $sql = 'INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    if (db_is_sqlite($pdo)) {
        $sql = 'INSERT OR REPLACE ' . $sql;
    } else {
        $sql = 'INSERT ' . $sql . ' ON DUPLICATE KEY UPDATE '
             . implode(', ', array_map(static fn($c) => $c . ' = VALUES(' . $c . ')', $update));
    }
    $pdo->prepare($sql)->execute(array_values($row));
}

// ── definições (tabela settings) ────────────────────────────

function drive_setting_get(PDO $pdo, string $k): ?string {
    $st = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function drive_setting_set(PDO $pdo, string $k, string $v): void {
    drive_upsert($pdo, 'settings', ['skey' => $k, 'svalue' => $v], ['svalue']);
}

// ── favoritos ───────────────────────────────────────────────

/** As chaves marcadas com estrela, as mais recentes primeiro. */
function drive_favs(PDO $pdo): array {
    return array_map('strval', $pdo->query('SELECT obj_key FROM drive_favs ORDER BY created_at DESC, obj_key')->fetchAll(PDO::FETCH_COLUMN));
}

function drive_is_fav(PDO $pdo, string $key): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM drive_favs WHERE key_hash = ?');
    $st->execute([drive_key_hash($key)]);
    return (int)$st->fetchColumn() > 0;
}

function drive_fav_set(PDO $pdo, string $key, bool $on): void {
    if (!$on) {
        $pdo->prepare('DELETE FROM drive_favs WHERE key_hash = ?')->execute([drive_key_hash($key)]);
        return;
    }
    if (drive_is_fav($pdo, $key)) return;
    drive_upsert($pdo, 'drive_favs', ['key_hash' => drive_key_hash($key), 'obj_key' => $key, 'created_at' => drive_now()], ['obj_key', 'created_at']);
}

// ── recentes ────────────────────────────────────────────────

/** Marca como aberto agora. A tabela guarda no máximo 200 linhas ($trim = false: quem chama apara no fim). */
function drive_recent_touch(PDO $pdo, string $key, bool $trim = true): void {
    drive_upsert($pdo, 'drive_recent', ['key_hash' => drive_key_hash($key), 'obj_key' => $key, 'opened_at' => drive_now()], ['obj_key', 'opened_at']);
    if ($trim) drive_recent_trim($pdo);
}

function drive_recent_trim(PDO $pdo): void {
    if ((int)$pdo->query('SELECT COUNT(*) FROM drive_recent')->fetchColumn() <= DRIVE_RECENT_MAX) return;
    $old = $pdo->query('SELECT key_hash FROM drive_recent ORDER BY opened_at DESC, key_hash')->fetchAll(PDO::FETCH_COLUMN);
    $del = $pdo->prepare('DELETE FROM drive_recent WHERE key_hash = ?');
    foreach (array_slice($old, DRIVE_RECENT_MAX) as $h) $del->execute([$h]);
}

/** [['k' => chave, 't' => unix]…], o mais recente primeiro. */
function drive_recents(PDO $pdo, int $limit = 50): array {
    $limit = max(1, min(DRIVE_RECENT_MAX, $limit));
    $out = [];
    foreach ($pdo->query('SELECT obj_key, opened_at FROM drive_recent ORDER BY opened_at DESC, obj_key LIMIT ' . $limit) as $r) {
        $out[] = ['k' => (string)$r['obj_key'], 't' => drive_ts($r['opened_at'])];
    }
    return $out;
}

// ── mudanças de sítio ───────────────────────────────────────

/**
 * Uma chave (ou uma pasta inteira) passou de $from para $to: favoritos, recentes
 * e partilhas ativas seguem-na. Depois avisa o índice de conteúdo, se existir.
 */
function drive_store_moved(PDO $pdo, string $from, string $to): void {
    if ($from === $to || $from === '') return;
    $map = static function (string $k) use ($from, $to): ?string {
        if ($k === $from) return $to;
        if (substr($from, -1) === '/' && strncmp($k, $from, strlen($from)) === 0) return $to . substr($k, strlen($from));
        return null;
    };
    $pdo->beginTransaction();
    try {
        foreach (['drive_favs' => 'created_at', 'drive_recent' => 'opened_at'] as $table => $col) {
            $moves = [];
            foreach ($pdo->query('SELECT obj_key, ' . $col . ' AS t FROM ' . $table) as $r) {
                $n = $map((string)$r['obj_key']);
                if ($n !== null) $moves[] = [(string)$r['obj_key'], $n, (string)$r['t']];
            }
            $del = $pdo->prepare('DELETE FROM ' . $table . ' WHERE key_hash = ?');
            foreach ($moves as [$old]) $del->execute([drive_key_hash($old)]);
            foreach ($moves as [, $new, $t]) {
                if (strlen($new) > R2_KEY_MAX) continue;
                drive_upsert($pdo, $table, ['key_hash' => drive_key_hash($new), 'obj_key' => $new, $col => $t], ['obj_key', $col]);
            }
        }
        $up = $pdo->prepare('UPDATE drive_shares SET obj_key = ? WHERE id = ?');
        $st = $pdo->prepare('SELECT id, obj_key FROM drive_shares WHERE revoked = 0 AND expires_at > ?');
        $st->execute([drive_now()]);
        foreach ($st->fetchAll() as $r) {
            $n = $map((string)$r['obj_key']);
            if ($n !== null) $up->execute([$n, (int)$r['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if (function_exists('drive_index_moved')) {
        // O índice reaproveita o texto já extraído; se falhar, a próxima reconciliação trata disso.
        try { drive_index_moved($pdo, $from, $to); } catch (Throwable $e) { error_log('drive_index_moved: ' . $e->getMessage()); }
    }
    // O registo de ficheiros da plataforma (propostas, faturação, Centro Criativo…) segue a mudança.
    if (is_file(__DIR__ . '/files.php')) {
        try { require_once __DIR__ . '/files.php'; files_moved($pdo, $from, $to); } catch (Throwable $e) { error_log('files_moved: ' . $e->getMessage()); }
    }
}

/** A chave (ou pasta) foi para o Lixo ou apagada: sai dos favoritos e recentes, as partilhas são revogadas. */
function drive_store_trashed(PDO $pdo, string $key): void {
    drive_store_trashed_many($pdo, [$key]);
}

/** O mesmo para várias chaves de uma vez (cada tabela lê-se uma só vez). */
function drive_store_trashed_many(PDO $pdo, array $keys): void {
    $keys = array_values(array_filter(array_map('strval', $keys), static fn($k) => $k !== ''));
    if (!$keys) return;
    $files = array_flip(array_filter($keys, static fn($k) => substr($k, -1) !== '/'));
    $folders = array_values(array_filter($keys, static fn($k) => substr($k, -1) === '/'));
    $hit = static function (string $k) use ($files, $folders): bool {
        if (isset($files[$k])) return true;
        foreach ($folders as $f) if (drive_under($k, $f)) return true;
        return false;
    };
    foreach (['drive_favs', 'drive_recent'] as $table) {
        $del = $pdo->prepare('DELETE FROM ' . $table . ' WHERE key_hash = ?');
        foreach ($pdo->query('SELECT obj_key FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN) as $k) {
            if ($hit((string)$k)) $del->execute([drive_key_hash((string)$k)]);
        }
    }
    $rev = $pdo->prepare('UPDATE drive_shares SET revoked = 1 WHERE id = ?');
    foreach ($pdo->query('SELECT id, obj_key FROM drive_shares WHERE revoked = 0')->fetchAll() as $r) {
        if ($hit((string)$r['obj_key'])) $rev->execute([(int)$r['id']]);
    }
    if (is_file(__DIR__ . '/files.php')) {
        try { require_once __DIR__ . '/files.php'; files_trashed($pdo, $keys); } catch (Throwable $e) { error_log('files_trashed: ' . $e->getMessage()); }
    }
}

// ── Lixo ────────────────────────────────────────────────────

/** Onde ficam os objetos de um item do Lixo. */
function drive_trash_prefix(int $id): string {
    return DRIVE_TRASH . $id . '/';
}

function drive_trash_add(PDO $pdo, string $key, bool $folder, int $size, int $files): int {
    $pdo->prepare('INSERT INTO drive_trash (orig_key, is_folder, size, files, deleted_at) VALUES (?,?,?,?,?)')
        ->execute([$key, $folder ? 1 : 0, $size, $files, drive_now()]);
    return (int)$pdo->lastInsertId();
}

function drive_trash_row(array $r): array {
    return ['id' => (int)$r['id'], 'k' => (string)$r['orig_key'], 'folder' => (int)$r['is_folder'] === 1,
            's' => (int)$r['size'], 'files' => (int)$r['files'], 'deleted' => drive_ts($r['deleted_at'])];
}

function drive_trash_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM drive_trash WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? drive_trash_row($r) : null;
}

/** Todos os itens, os apagados há menos tempo primeiro. */
function drive_trash_all(PDO $pdo): array {
    return array_map('drive_trash_row', $pdo->query('SELECT * FROM drive_trash ORDER BY deleted_at DESC, id DESC')->fetchAll());
}

function drive_trash_remove(PDO $pdo, int $id): void {
    $pdo->prepare('DELETE FROM drive_trash WHERE id = ?')->execute([$id]);
}

/** Vários itens do Lixo pelo id: [id => linha] (os que já não existem ficam de fora). */
function drive_trash_get_many(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $st = $pdo->prepare('SELECT * FROM drive_trash WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $st->execute($chunk);
        foreach ($st->fetchAll() as $r) { $t = drive_trash_row($r); $out[$t['id']] = $t; }
    }
    return $out;
}

/** O maior id do Lixo (0 se estiver vazio). */
function drive_trash_max_id(PDO $pdo): int {
    return (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM drive_trash')->fetchColumn();
}

/** ['count' => itens, 'size' => bytes] */
function drive_trash_stats(PDO $pdo): array {
    $r = $pdo->query('SELECT COUNT(*) AS n, COALESCE(SUM(size), 0) AS s FROM drive_trash')->fetch();
    return ['count' => (int)($r['n'] ?? 0), 'size' => (int)($r['s'] ?? 0)];
}

/** Os itens com mais de 30 dias, os mais antigos primeiro. */
function drive_trash_expired(PDO $pdo): array {
    $st = $pdo->prepare('SELECT * FROM drive_trash WHERE deleted_at < ? ORDER BY deleted_at, id');
    $st->execute([drive_now(-DRIVE_TRASH_DAYS * 86400)]);
    return array_map('drive_trash_row', $st->fetchAll());
}

/** Para o JSON: {id, k, folder, s, files, deleted, expires, days_left}. */
function drive_trash_out(array $t): array {
    $exp = $t['deleted'] + DRIVE_TRASH_DAYS * 86400;
    return $t + ['expires' => $exp, 'days_left' => max(0, (int)ceil(($exp - time()) / 86400))];
}

// ── links de partilha ───────────────────────────────────────

/** 32 bytes aleatórios em base64url: 43 caracteres. */
function drive_share_token(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/**
 * O URL público da partilha, sempre no domínio da plataforma (app_url; por defeito https://topperhub.xyz) — nunca o
 * domínio do pedido (§32.5: um Host diferente ou forjado dava links noutro domínio).
 */
function drive_share_url(string $token): string {
    require_once __DIR__ . '/team.php';
    return team_app_url() . '/admin/partilha.php?t=' . $token;
}

function drive_share_row(array $r): array {
    $forever = (string)$r['expires_at'] === DRIVE_SHARE_FOREVER;
    return ['id' => (int)$r['id'], 'token' => (string)$r['token'], 'k' => (string)$r['obj_key'], 'folder' => (int)$r['is_folder'] === 1,
            'forever' => $forever, 'exp' => $forever ? 0 : drive_ts($r['expires_at']), 'created' => drive_ts($r['created_at']),
            'downloads' => (int)$r['downloads'], 'revoked' => (int)$r['revoked'] === 1,
            'protected' => (string)($r['pw_hash'] ?? '') !== '', 'pw_hash' => (string)($r['pw_hash'] ?? ''),
            'pw_fails' => (int)($r['pw_fails'] ?? 0), 'pw_fail_at' => drive_ts($r['pw_fail_at'] ?? null)];
}

/** Para o JSON: {id, k, url, exp, forever, folder, downloads, created}. Sem prazo: forever=true e exp=0. */
function drive_share_out(array $s): array {
    return ['id' => $s['id'], 'k' => $s['k'], 'url' => drive_share_url($s['token']), 'exp' => $s['exp'], 'forever' => $s['forever'], 'protected' => $s['protected'],
            'folder' => $s['folder'], 'downloads' => $s['downloads'], 'created' => $s['created']];
}

/** $forever só vale para pastas (um ficheiro partilhado tem sempre prazo). */
function drive_share_create(PDO $pdo, string $key, bool $folder, int $hours, bool $forever = false, string $password = ''): array {
    $hours = max(1, min(DRIVE_SHARE_MAX_HOURS, $hours));
    $until = $forever && $folder ? DRIVE_SHARE_FOREVER : drive_now($hours * 3600);
    $tok = drive_share_token();
    $pdo->prepare('INSERT INTO drive_shares (token, obj_key, is_folder, expires_at, created_at, downloads, revoked, pw_hash) VALUES (?,?,?,?,?,0,0,?)')
        ->execute([$tok, $key, $folder ? 1 : 0, $until, drive_now(), $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null]);
    return drive_share_get($pdo, (int)$pdo->lastInsertId());
}

function drive_share_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM drive_shares WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? drive_share_row($r) : null;
}

/** As partilhas ainda válidas (não revogadas nem expiradas); com $key, só as dessa chave. */
function drive_shares_active(PDO $pdo, ?string $key = null): array {
    $st = $pdo->prepare('SELECT * FROM drive_shares WHERE revoked = 0 AND expires_at > ? ORDER BY expires_at, id');
    $st->execute([drive_now()]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $s = drive_share_row($r);
        if ($key === null || $s['k'] === $key) $out[] = $s;
    }
    return $out;
}

/** A partilha deste token, se ainda for válida; senão null. ['state'] diz porquê: ok|expired|revoked. */
function drive_share_by_token(PDO $pdo, string $token): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) return null;
    $st = $pdo->prepare('SELECT * FROM drive_shares WHERE token = ?');
    $st->execute([$token]);
    foreach ($st->fetchAll() as $r) {
        if (!hash_equals((string)$r['token'], $token)) continue;   // o MySQL pode comparar sem maiúsculas
        $s = drive_share_row($r);
        $s['state'] = $s['revoked'] ? 'revoked' : (!$s['forever'] && $s['exp'] <= time() ? 'expired' : 'ok');
        return $s;
    }
    return null;
}

function drive_share_revoke(PDO $pdo, int $id): bool {
    $st = $pdo->prepare('UPDATE drive_shares SET revoked = 1 WHERE id = ?');
    $st->execute([$id]);
    return drive_share_get($pdo, $id) !== null;
}

/** Mais um download por este link. */
function drive_share_hit(PDO $pdo, int $id): void {
    $pdo->prepare('UPDATE drive_shares SET downloads = downloads + 1 WHERE id = ?')->execute([$id]);
}

// ── password dos links ──────────────────────────────────────

/** Segundos que faltam até se poder tentar outra vez (0 = pode). */
function drive_share_pw_wait(array $s): int {
    if ($s['pw_fails'] < DRIVE_SHARE_PW_TRIES) return 0;
    return max(0, $s['pw_fail_at'] + DRIVE_SHARE_PW_LOCK - time());
}

/** Confere a password; conta as falhas (5 seguidas travam o link 15 min). */
function drive_share_pw_check(PDO $pdo, array $s, string $pw): bool {
    if (!$s['protected'] || drive_share_pw_wait($s) > 0) return false;
    if ($pw !== '' && strlen($pw) <= 1000 && password_verify($pw, $s['pw_hash'])) {
        if ($s['pw_fails'] > 0) $pdo->prepare('UPDATE drive_shares SET pw_fails = 0, pw_fail_at = NULL WHERE id = ?')->execute([$s['id']]);
        return true;
    }
    // passado o castigo, recomeça a contar
    $n = $s['pw_fails'] >= DRIVE_SHARE_PW_TRIES ? 1 : $s['pw_fails'] + 1;
    $pdo->prepare('UPDATE drive_shares SET pw_fails = ?, pw_fail_at = ? WHERE id = ?')->execute([$n, drive_now(), $s['id']]);
    return false;
}

/** O valor do cookie que prova que esta password foi dada (muda se a password mudar). */
function drive_share_pw_cookie(array $s): string {
    return hash_hmac('sha256', $s['token'], $s['pw_hash']);
}
