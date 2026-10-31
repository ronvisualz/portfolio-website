<?php
/**
 * topper. admin — Toppernote, fase 3: tempo real e captura (§26)
 *
 * Tempo real: os Topperboards sincronizam por Yjs num Durable Object da Cloudflare (PartyServer,
 * rt/ no repositório). O PHP decide quem entra: emite um token curto (10 min) assinado com
 * ECDSA P-256 (ES256, pelo OpenSSL do PHP) — a chave privada fica no servidor (settings), a pública vai para o Worker
 * (RT_PUBLIC_KEY, não é segredo). Só recebe token quem tem acesso ao negócio do Topperboard.
 * A base de dados continua a ser o registo dos cartões: cada pessoa grava o que muda (cc_api)
 * e o tempo real leva as mudanças aos outros logo (< 200 ms).
 *
 * Histórico: versões automáticas do Topperboard (uma por sessão de trabalho, e antes das ações
 * grandes) com «Ver» e «Restaurar».
 *
 * Uso: o plano gratuito da Cloudflare dá 100 000 pedidos e 13 000 GB-s por dia. Os browsers
 * contam o que enviam (ligações, mensagens, janelas de 10 s com atividade) e o PHP soma.
 *
 * Captura: tokens pessoais (Perfil › Ligações) para a extensão e o Atalho do iOS.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/cc.php';
require_once __DIR__ . '/leads.php';   // lisbon_fmt

const CC_RT_DAY_REQUESTS = 100000;   // pedidos por dia (20 mensagens WebSocket contam 1)
const CC_RT_DAY_GBS = 13000;         // GB-s de Durable Objects por dia
const CC_RT_DO_GB = 0.128;           // memória de um Durable Object (128 MB)
const CC_RT_TOKEN_TTL = 600;         // o token do Topperboard vale 10 min (o browser pede outro ao religar)
const CC_REACTIONS = ['👍', '❤️', '🔥', '✅', '👀', '😂'];

function cc_rt_ready(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (int)$pdo->query("SELECT svalue FROM settings WHERE skey = 'schema_version'")->fetchColumn() >= 28;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

function cc_rt_b64u(string $b): string {
    return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
}

function cc_rt_unb64u(string $s): string {
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

/** O endereço do Worker (Definições › Integrações), sem «/» no fim; '' se ainda não está ligado. */
function cc_rt_url(PDO $pdo): string {
    $u = trim((string)setting($pdo, 'rt_url', ''));
    return preg_match('~^https://[a-z0-9.-]+(:\d+)?/?$~i', $u) || preg_match('~^http://(127\.0\.0\.1|localhost)(:\d+)?$~', $u) ? rtrim($u, '/') : '';
}

/** As origens do serviço para a política de conteúdo (connect-src), ou ''. */
function cc_rt_csp(PDO $pdo): string {
    $u = cc_rt_url($pdo);
    if ($u === '' || !cc_rt_ready($pdo)) return '';
    return $u . ' ' . preg_replace('~^http~', 'ws', $u);
}

/**
 * As chaves que assinam os tokens (ECDSA P-256 / ES256, criadas na primeira vez):
 * [privada (PEM), pública (SPKI em base64url, para o Worker)]. Usa o OpenSSL do PHP (o alojamento não tem sodium).
 */
function cc_rt_keys(PDO $pdo): ?array {
    static $keys = null;   // a cache de settings() não vê o que se grava a meio do pedido
    if ($keys !== null) return $keys;
    if (!function_exists('openssl_pkey_new')) return null;
    $sk = (string)setting($pdo, 'rt_es_sk', '');
    $pk = (string)setting($pdo, 'rt_es_pk', '');
    if ($sk === '' || $pk === '') {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$key || !openssl_pkey_export($key, $sk)) return null;
        $pem = (string)(openssl_pkey_get_details($key)['key'] ?? '');
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
        if (!$der) return null;
        $pk = cc_rt_b64u($der);
        require_once __DIR__ . '/ai.php';
        ai_setting_set($pdo, 'rt_es_sk', $sk);
        ai_setting_set($pdo, 'rt_es_pk', $pk);
    }
    return $keys = [$sk, $pk];
}

/** Assinatura ECDSA do OpenSSL (DER) → r||s com 32 bytes cada (o formato da WebCrypto). */
function cc_rt_der2raw(string $der): ?string {
    $o = 0;
    if (($der[$o++] ?? '') !== "\x30") return null;
    $len = ord($der[$o++]);
    if ($len & 0x80) $o += $len & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        if (($der[$o++] ?? '') !== "\x02") return null;
        $n = ord($der[$o++]);
        $v = ltrim(substr($der, $o, $n), "\x00");
        $o += $n;
        if (strlen($v) > 32) return null;
        $out .= str_pad($v, 32, "\x00", STR_PAD_LEFT);
    }
    return $out;
}

/** O token curto de um Topperboard: «<conteúdo>.<assinatura>» (base64url). */
function cc_rt_token(PDO $pdo, int $boardId, int $userId, string $role): ?string {
    $k = cc_rt_keys($pdo);
    if (!$k) return null;
    $payload = cc_rt_b64u(json_encode(['b' => $boardId, 'u' => $userId, 'r' => $role, 'exp' => time() + CC_RT_TOKEN_TTL]));
    if (!openssl_sign($payload, $der, $k[0], OPENSSL_ALGO_SHA256) || !($raw = cc_rt_der2raw($der))) return null;
    return $payload . '.' . cc_rt_b64u($raw);
}

/** O negócio de um Topperboard (o do próprio, ou o do Topperboard de topo). */
function cc_board_deal_id(PDO $pdo, array $b): ?int {
    if ($b['deal_id']) return (int)$b['deal_id'];
    $root = cc_board_path($pdo, $b)[0] ?? $b;
    return $root['deal_id'] ? (int)$root['deal_id'] : null;
}

/**
 * Pode esta pessoa entrar no Topperboard? Precisa da aba Toppernote; num Topperboard de um negócio,
 * também acesso ao negócio (§9.3): dentro do seu âmbito em Negócios, ou responsável de execução
 * (do negócio ou de uma frente), ou com tarefas no negócio, ou quem criou o Topperboard.
 */
function cc_board_access(PDO $pdo, array $b, ?array $user = null): bool {
    $user ??= current_user();
    if (!$user || !can('criativo')) return false;
    $dealId = cc_board_deal_id($pdo, $b);
    if (!$dealId) return true;
    $st = $pdo->prepare('SELECT owner_id, exec_owner_id FROM deals WHERE id = ?');
    $st->execute([$dealId]);
    $d = $st->fetch();
    if (!$d) return true;
    $uid = (int)$user['id'];
    if (in_my_scope($pdo, 'negocios', $d['owner_id'] ? (int)$d['owner_id'] : null)) return true;
    if ((int)$d['exec_owner_id'] === $uid || (int)$b['created_by'] === $uid || (int)($b['exec_owner_id'] ?? 0) === $uid) return true;
    if (db_has_table($pdo, 'deal_lines')) {
        $st = $pdo->prepare('SELECT 1 FROM deal_lines WHERE deal_id = ? AND exec_owner_id = ? LIMIT 1');
        $st->execute([$dealId, $uid]);
        if ($st->fetchColumn()) return true;
    }
    $st = $pdo->prepare('SELECT 1 FROM tasks t JOIN users u ON u.team_member_id = t.member_id WHERE t.deal_id = ? AND u.id = ? LIMIT 1');
    try { $st->execute([$dealId, $uid]); if ($st->fetchColumn()) return true; } catch (Throwable $e) {}
    return false;
}

/** O que o browser precisa para ligar ao Topperboard (ou null, se o tempo real não está ligado). */
function cc_rt_connect_info(PDO $pdo, array $b, array $user, bool $edit): ?array {
    $url = cc_rt_url($pdo);
    if ($url === '' || !cc_rt_ready($pdo) || setting($pdo, 'rt_enabled', '1') === '0') return null;
    $tok = cc_rt_token($pdo, (int)$b['id'], (int)$user['id'], $edit ? 'edit' : 'view');
    if (!$tok) return null;
    $pct = cc_rt_usage_today($pdo)['pct'];
    return ['host' => preg_replace('~^https?://~', '', $url), 'secure' => str_starts_with($url, 'https://'), 'room' => 'b' . (int)$b['id'], 'token' => $tok,
            'ttl' => CC_RT_TOKEN_TTL, 'me' => cc_rt_me($pdo, $user), 'pct' => $pct, 'paused' => $pct >= 100];
}

/** Quem sou no Topperboard: nome, iniciais e a cor da aba Equipa. */
function cc_rt_me(PDO $pdo, array $user): array {
    $st = $pdo->prepare('SELECT name, color FROM users WHERE id = ?');
    $st->execute([(int)$user['id']]);
    $u = $st->fetch() ?: ['name' => (string)($user['name'] ?? ''), 'color' => ''];
    $name = trim((string)$u['name']) ?: 'Alguém';
    $parts = preg_split('/\s+/u', $name);
    $ini = mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : mb_substr($parts[0], 1, 1)));
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$u['color']) ? (string)$u['color'] : '#2F77B1';
    return ['id' => (int)$user['id'], 'name' => $name, 'first' => $parts[0], 'ini' => $ini, 'color' => $color];
}

// ── uso do serviço de tempo real (plano gratuito) ───────────

/** Soma o que um browser contou: ligações, mensagens enviadas e as janelas de 10 s com atividade. */
function cc_rt_usage_add(PDO $pdo, int $boardId, int $conns, int $msgs, array $wins): void {
    $day = gmdate('Y-m-d');   // o plano repõe às 00:00 UTC
    $conns = max(0, min(50, $conns)); $msgs = max(0, min(20000, $msgs));
    $sqlite = db_is_sqlite($pdo);
    $pdo->prepare($sqlite ? 'INSERT OR IGNORE INTO rt_usage (day, board_id, conns, msgs) VALUES (?, ?, 0, 0)' : 'INSERT IGNORE INTO rt_usage (day, board_id, conns, msgs) VALUES (?, ?, 0, 0)')
        ->execute([$day, $boardId]);
    $pdo->prepare('UPDATE rt_usage SET conns = conns + ?, msgs = msgs + ? WHERE day = ? AND board_id = ?')->execute([$conns, $msgs, $day, $boardId]);
    $today0 = intdiv(strtotime($day . ' 00:00:00 UTC'), 10);
    $ins = $pdo->prepare(($sqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . ' INTO rt_usage_win (day, board_id, win) VALUES (?, ?, ?)');
    foreach (array_slice(array_unique(array_map('intval', $wins)), 0, 400) as $w) {
        if ($w >= $today0 && $w < $today0 + 8640) $ins->execute([$day, $boardId, $w]);
    }
}

/** O uso de hoje (estimado): pedidos, GB-s, percentagem do plano gratuito, Topperboards. */
function cc_rt_usage_today(PDO $pdo): array {
    $day = gmdate('Y-m-d');
    $r = ['requests' => 0, 'gbs' => 0.0, 'pct' => 0, 'boards' => 0, 'day' => $day];
    if (!cc_rt_ready($pdo)) return $r;
    $st = $pdo->prepare('SELECT COALESCE(SUM(conns), 0) AS c, COALESCE(SUM(msgs), 0) AS m, COUNT(*) AS b FROM rt_usage WHERE day = ?');
    $st->execute([$day]);
    $u = $st->fetch();
    $st = $pdo->prepare('SELECT COUNT(*) FROM rt_usage_win WHERE day = ?');
    $st->execute([$day]);
    $wins = (int)$st->fetchColumn();
    // pedidos: cada ligação (e o pedido do token) + 1 por cada 20 mensagens recebidas pelo Durable Object
    $r['requests'] = (int)$u['c'] * 2 + (int)ceil((int)$u['m'] / 20);
    // duração: um Durable Object acordado ~10 s por cada janela com atividade (hiberna depois)
    $r['gbs'] = round($wins * 10 * CC_RT_DO_GB, 1);
    $r['boards'] = (int)$u['b'];
    $r['pct'] = (int)floor(100 * max($r['requests'] / CC_RT_DAY_REQUESTS, $r['gbs'] / CC_RT_DAY_GBS));
    return $r;
}

/** Limpa o uso com mais de 40 dias (cron). */
function cc_rt_usage_prune(PDO $pdo): void {
    if (!cc_rt_ready($pdo)) return;
    $old = gmdate('Y-m-d', time() - 40 * 86400);
    $pdo->prepare('DELETE FROM rt_usage WHERE day < ?')->execute([$old]);
    $pdo->prepare('DELETE FROM rt_usage_win WHERE day < ?')->execute([$old]);
}

// ── histórico do Topperboard ─────────────────────────────────────

/** O estado dos cartões de um Topperboard (os que não estão apagados). */
function cc_board_snapshot(PDO $pdo, int $boardId): array {
    $st = $pdo->prepare('SELECT id, type, asset_id, ref_board_id, column_id, lane, x, y, w, h, z, ord, content FROM cc_cards WHERE board_id = ? AND deleted_at IS NULL ORDER BY id');
    $st->execute([$boardId]);
    return array_map(fn($c) => ['id' => (int)$c['id'], 'type' => (string)$c['type'], 'asset' => $c['asset_id'] ? (int)$c['asset_id'] : null,
        'ref' => $c['ref_board_id'] ? (int)$c['ref_board_id'] : null, 'col' => $c['column_id'] ? (int)$c['column_id'] : null, 'lane' => (string)$c['lane'],
        'x' => (float)$c['x'], 'y' => (float)$c['y'], 'w' => (float)$c['w'], 'h' => (float)$c['h'], 'z' => (int)$c['z'], 'ord' => (int)$c['ord'],
        'content' => (string)$c['content']], $st->fetchAll());
}

/**
 * Guarda uma versão do Topperboard. $kind: session (automática, uma por sessão de trabalho) · before (antes
 * de uma ação grande) · restore (antes de restaurar) · manual. Não repete uma versão igual à última.
 * Mantém só as últimas N (Definições). Devolve o id, ou null se não guardou.
 */
function cc_board_version_save(PDO $pdo, array $b, string $kind, string $label, ?int $by): ?int {
    if (!cc_rt_ready($pdo)) return null;
    $cards = cc_board_snapshot($pdo, (int)$b['id']);
    $data = json_encode($cards, JSON_UNESCAPED_UNICODE);
    $st = $pdo->prepare('SELECT data FROM cc_board_versions WHERE board_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([(int)$b['id']]);
    $last = $st->fetchColumn();
    if ($last !== false && hash('sha256', (string)$last) === hash('sha256', $data)) return null;
    if (!$cards && $last === false) return null;
    $pdo->prepare('INSERT INTO cc_board_versions (board_id, kind, label, data, cards_n, created_by, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([(int)$b['id'], $kind, mb_substr($label, 0, 200), $data, count($cards), $by, cc_now()]);
    $id = (int)$pdo->lastInsertId();
    $keep = max(5, min(500, (int)setting($pdo, 'cc_ver_keep', 50)));
    $st = $pdo->prepare('SELECT id FROM cc_board_versions WHERE board_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . ($keep - 1));
    $st->execute([(int)$b['id']]);
    if ($cut = (int)$st->fetchColumn()) $pdo->prepare('DELETE FROM cc_board_versions WHERE board_id = ? AND id < ?')->execute([(int)$b['id'], $cut]);
    return $id;
}

/**
 * A versão automática de uma sessão de trabalho: guarda o Topperboard como estava antes da primeira
 * alteração, se a última versão tem mais de N minutos (Definições). Devolve o id ou null.
 */
function cc_board_version_session(PDO $pdo, array $b, ?int $by): ?int {
    if (!cc_rt_ready($pdo)) return null;
    $min = max(5, min(1440, (int)setting($pdo, 'cc_ver_minutes', 30)));
    $st = $pdo->prepare('SELECT created_at FROM cc_board_versions WHERE board_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([(int)$b['id']]);
    $last = $st->fetchColumn();
    if ($last && strtotime((string)$last) > time() - $min * 60) return null;
    return cc_board_version_save($pdo, $b, 'session', 'Sessão de trabalho', $by);
}

const CC_VERSION_KINDS = ['session' => 'Sessão de trabalho', 'before' => 'Antes de uma ação', 'restore' => 'Antes de restaurar', 'manual' => 'Guardada à mão'];

/** As versões de um Topperboard (as mais recentes primeiro), sem o conteúdo. */
function cc_board_versions(PDO $pdo, int $boardId): array {
    $st = $pdo->prepare('SELECT v.id, v.kind, v.label, v.cards_n, v.created_at, u.name FROM cc_board_versions v LEFT JOIN users u ON u.id = v.created_by WHERE v.board_id = ? ORDER BY v.id DESC LIMIT 200');
    $st->execute([$boardId]);
    return array_map(fn($v) => ['id' => (int)$v['id'], 'kind' => (string)$v['kind'], 'label' => (string)$v['label'], 'n' => (int)$v['cards_n'],
        'who' => (string)($v['name'] ?? ''), 'when' => lisbon_fmt(strtotime((string)$v['created_at']), 'd/m H:i'), 'at' => (string)$v['created_at']], $st->fetchAll());
}

function cc_board_version_get(PDO $pdo, int $boardId, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM cc_board_versions WHERE id = ? AND board_id = ?');
    $st->execute([$id, $boardId]);
    $v = $st->fetch();
    if (!$v) return null;
    $v['cards'] = json_decode((string)$v['data'], true) ?: [];
    return $v;
}

/** Os cartões de uma versão, no formato do Topperboard (para «Ver»). */
function cc_board_version_cards_public(PDO $pdo, array $v): array {
    $ids = array_values(array_unique(array_filter(array_map(fn($c) => (int)($c['asset'] ?? 0), $v['cards']))));
    $assets = [];
    if ($ids) {
        foreach ($pdo->query('SELECT * FROM cc_assets WHERE id IN (' . implode(',', $ids) . ')')->fetchAll() as $a) $assets[(int)$a['id']] = $a;
    }
    $out = [];
    foreach ($v['cards'] as $c) {
        $a = !empty($c['asset']) ? ($assets[(int)$c['asset']] ?? null) : null;
        if (!empty($c['asset']) && !$a) continue;
        $out[] = cc_card_public(['id' => $c['id'], 'type' => $c['type'], 'x' => $c['x'], 'y' => $c['y'], 'w' => $c['w'], 'h' => $c['h'], 'z' => $c['z'],
            'column_id' => $c['col'], 'ord' => $c['ord'], 'lane' => $c['lane'], 'content' => $c['content'], 'ref_board_id' => $c['ref']], $a);
    }
    return $out;
}

/**
 * Restaura uma versão: o estado atual fica guardado antes («Antes de restaurar»); os cartões da
 * versão voltam (posição, tamanho, conteúdo; os apagados entretanto reaparecem) e os que não
 * existiam nela vão para o apagado (repõem-se com outra restauração). Devolve [voltaram, saíram].
 */
function cc_board_version_restore(PDO $pdo, array $b, int $vid, int $by): array {
    $v = cc_board_version_get($pdo, (int)$b['id'], $vid);
    if (!$v) throw new RuntimeException('Esta versão já não existe.');
    cc_board_version_save($pdo, $b, 'restore', 'Antes de restaurar a versão de ' . lisbon_fmt(strtotime((string)$v['created_at']), 'd/m H:i'), $by);
    $now = cc_now();
    $keep = [];
    $upd = $pdo->prepare('UPDATE cc_cards SET column_id = ?, lane = ?, x = ?, y = ?, w = ?, h = ?, z = ?, ord = ?, content = ?, deleted_at = NULL, updated_by = ?, updated_at = ? WHERE id = ? AND board_id = ?');
    $back = 0;
    foreach ($v['cards'] as $c) {
        $upd->execute([$c['col'], (string)$c['lane'], (float)$c['x'], (float)$c['y'], (float)$c['w'], (float)$c['h'], (int)$c['z'], (int)$c['ord'], (string)$c['content'], $by, $now, (int)$c['id'], (int)$b['id']]);
        if ($upd->rowCount()) $back++;
        $keep[(int)$c['id']] = true;
    }
    $gone = 0;
    foreach (cc_board_snapshot($pdo, (int)$b['id']) as $c) {
        if (isset($keep[$c['id']])) continue;
        $pdo->prepare('UPDATE cc_cards SET deleted_at = ?, updated_by = ?, updated_at = ? WHERE id = ?')->execute([$now, $by, $now, $c['id']]);
        $gone++;
    }
    cc_board_touch($pdo, (int)$b['id']);
    return [$back, $gone];
}

// ── reações aos comentários ─────────────────────────────────

/** Liga/desliga a reação desta pessoa. Devolve as reações do comentário. */
function cc_comment_react(PDO $pdo, int $commentId, string $emoji, int $userId): array {
    if (!in_array($emoji, CC_REACTIONS, true)) throw new RuntimeException('Reação desconhecida.');
    $st = $pdo->prepare('SELECT reactions FROM cc_comments WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$commentId]);
    $raw = $st->fetchColumn();
    if ($raw === false) throw new RuntimeException('Comentário não encontrado.');
    $r = json_decode((string)$raw, true) ?: [];
    $who = array_map('intval', (array)($r[$emoji] ?? []));
    $who = in_array($userId, $who, true) ? array_values(array_diff($who, [$userId])) : [...$who, $userId];
    if ($who) $r[$emoji] = $who; else unset($r[$emoji]);
    $pdo->prepare('UPDATE cc_comments SET reactions = ? WHERE id = ?')->execute([$r ? json_encode($r, JSON_UNESCAPED_UNICODE) : null, $commentId]);
    return $r;
}

// ── tokens pessoais (extensão, Atalho do iOS) ───────────────

/** Um token novo para esta pessoa. Devolve o token (só se mostra uma vez). */
function ut_create(PDO $pdo, int $userId, string $name): string {
    $n = (int)$pdo->query('SELECT COUNT(*) FROM user_tokens WHERE revoked_at IS NULL AND user_id = ' . $userId)->fetchColumn();
    if ($n >= 10) throw new RuntimeException('Já tem 10 tokens ativos. Revogue um que já não use.');
    $tok = 'tpk_' . cc_rt_b64u(random_bytes(30));
    $pdo->prepare('INSERT INTO user_tokens (user_id, name, token_hash, last4, scope, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$userId, mb_substr(trim($name) ?: 'Token', 0, 80), hash('sha256', $tok), substr($tok, -4), 'capture', cc_now()]);
    return $tok;
}

/** O utilizador de um token pessoal (ativo, conta ativa), ou null. Regista o uso. */
function ut_auth(PDO $pdo, string $tok): ?array {
    if (!preg_match('/^tpk_[A-Za-z0-9_-]{30,60}$/', $tok)) return null;
    $st = $pdo->prepare('SELECT t.id AS tid, u.* FROM user_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ? AND t.revoked_at IS NULL');
    $st->execute([hash('sha256', $tok)]);
    $u = $st->fetch();
    if (!$u || (int)$u['is_active'] !== 1 || !empty($u['deactivated_at'])) return null;
    $pdo->prepare('UPDATE user_tokens SET last_used_at = ?, last_ip = ?, last_ua = ?, uses = uses + 1 WHERE id = ?')
        ->execute([cc_now(), mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160), (int)$u['tid']]);
    return $u;
}

/** Os tokens (de uma pessoa, ou de todos para os admins). */
function ut_list(PDO $pdo, ?int $userId): array {
    $sql = 'SELECT t.*, u.name AS who FROM user_tokens t JOIN users u ON u.id = t.user_id WHERE t.revoked_at IS NULL' . ($userId ? ' AND t.user_id = ' . $userId : '') . ' ORDER BY t.id DESC';
    return $pdo->query($sql)->fetchAll();
}

/** Revoga um token (o próprio, ou qualquer um se for admin). */
function ut_revoke(PDO $pdo, int $id, array $by): void {
    $own = role_is_admin((string)$by['role']) ? '' : ' AND user_id = ' . (int)$by['id'];
    $st = $pdo->prepare('UPDATE user_tokens SET revoked_at = ?, revoked_by = ? WHERE id = ? AND revoked_at IS NULL' . $own);
    $st->execute([cc_now(), (int)$by['id'], $id]);
    if ($st->rowCount() !== 1) throw new RuntimeException('Token não encontrado.');
}
