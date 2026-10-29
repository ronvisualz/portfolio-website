<?php
/**
 * topper. admin — OAuth da Google (§31.2 e §31.3)
 *
 * Um só cliente OAuth (Definições › Integrações › Google; credenciais em inc/auth_config.php, fora do Git) para:
 *  · «Ligar conta Google» (Perfil › Ligações, §31.5): com a sessão iniciada, a pessoa associa qualquer conta Google
 *    sua (users.google_sub + google_email), mesmo com outro email — os emails de trabalho não têm conta Google.
 *  · «Entrar com Google» (OpenID Connect): entra a conta associada; sem associação, só quem tem utilizador com o
 *    mesmo email, verificado pela Google (e essa conta fica associada). Nunca cria contas.
 *  · «Ligar conta da topper.» (Definições › Integrações › Google, só admins): a conta Gmail da empresa que cria os
 *    Meet de quem não tem o calendário ligado; o token de atualização fica em inc/meet_config.php.
 *  · «Ligar Google Calendar» (Perfil › Ligações): scopes de eventos e de ocupado/livre, token de atualização cifrado
 *    (inc/secrets.php) e revogável. Funciona com contas Google gratuitas.
 * Proteções: state (uma vez, 10 min, guardado na sessão), PKCE S256 e nonce no id_token.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/secrets.php';

const GOOGLE_SCOPES_LOGIN = 'openid email';
/** A conta Gmail gratuita da empresa para o Meet (§31.5); só sugere a conta no ecrã da Google. */
const TOPPER_GOOGLE_ACCOUNT = 'geraltopperdigital@gmail.com';

/** O endereço de uma API da Google (simulável só na bancada local: ver ext_url em inc/secrets.php). */
function google_url(string $url): string {
    return ext_url($url);
}
const GOOGLE_SCOPES_CAL = 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy';

function google_redirect_uri(): string {
    return app_base_url() . '/admin/google_auth.php';
}

/** O endereço da Google para começar ($purpose: 'login' | 'link' | 'linkcal' (associar e ligar o calendário de uma
 *  vez) | 'calendar' | 'topper'). */
function google_auth_url(string $purpose, string $hint = ''): string {
    $c = google_client();
    if (!$c) throw new RuntimeException('A ligação à Google ainda não está configurada (Definições › Integrações › Google).');
    $state = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['g_oauth'] = array_slice(array_filter((array)($_SESSION['g_oauth'] ?? []), fn($s) => ($s['t'] ?? 0) > time() - 600), -4, null, true);
    $_SESSION['g_oauth'][$state] = ['p' => $purpose, 'v' => $verifier, 'n' => $nonce, 't' => time(), 'u' => (int)(current_user()['id'] ?? 0)];
    $q = ['client_id' => $c['client_id'], 'redirect_uri' => google_redirect_uri(), 'response_type' => 'code', 'state' => $state, 'nonce' => $nonce,
          'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
    if (in_array($purpose, ['calendar', 'linkcal', 'topper'], true)) {
        $q += ['scope' => GOOGLE_SCOPES_CAL, 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'];
    } else {
        $q += ['scope' => GOOGLE_SCOPES_LOGIN, 'prompt' => 'select_account'];
    }
    if ($hint !== '') $q['login_hint'] = $hint;
    return google_url('https://accounts.google.com/o/oauth2/v2/auth') . '?' . http_build_query($q);
}

/** Recebe a volta da Google: confere o state e troca o código pelos tokens. Devolve [propósito, tokens, id_token (conteúdo), uid]. */
function google_callback(string $state, string $code): array {
    $s = $_SESSION['g_oauth'][$state] ?? null;
    unset($_SESSION['g_oauth'][$state]);
    if (!is_array($s) || ($s['t'] ?? 0) < time() - 600) throw new RuntimeException('O pedido à Google expirou. Tente outra vez.');
    $c = google_client();
    if (!$c) throw new RuntimeException('A ligação à Google não está configurada.');
    [$st, $tok] = http_call('POST', google_url('https://oauth2.googleapis.com/token'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'code' => $code, 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'redirect_uri' => google_redirect_uri(),
        'grant_type' => 'authorization_code', 'code_verifier' => $s['v']]));
    if ($st !== 200 || empty($tok['id_token'])) throw new RuntimeException('A Google não aceitou o pedido (' . $st . '). Tente outra vez.');
    $id = google_id_token((string)$tok['id_token'], $c['client_id'], (string)$s['n']);
    return [(string)$s['p'], $tok, $id, (int)$s['u']];
}

/**
 * O conteúdo do id_token. Veio diretamente do servidor de tokens da Google por HTTPS (troca do código com o
 * segredo do cliente), por isso basta conferir emissor, destinatário, validade e nonce (OpenID Connect, 3.1.3.7).
 */
function google_id_token(string $jwt, string $clientId, string $nonce): array {
    $p = explode('.', $jwt);
    if (count($p) !== 3) throw new RuntimeException('Resposta da Google inválida.');
    $c = json_decode((string)base64_decode(strtr($p[1], '-_', '+/') . str_repeat('=', (4 - strlen($p[1]) % 4) % 4)), true);
    if (!is_array($c)) throw new RuntimeException('Resposta da Google inválida.');
    if (!in_array($c['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)) throw new RuntimeException('Resposta da Google inválida (emissor).');
    $aud = (array)($c['aud'] ?? []);
    if (!in_array($clientId, $aud, true)) throw new RuntimeException('Resposta da Google inválida (destinatário).');
    if ((int)($c['exp'] ?? 0) < time() - 60) throw new RuntimeException('A resposta da Google expirou. Tente outra vez.');
    if (!hash_equals($nonce, (string)($c['nonce'] ?? ''))) throw new RuntimeException('Resposta da Google inválida (nonce).');
    return $c;
}

/** Revoga um token na Google (ao desligar). Não falha se a Google já o tiver esquecido. */
function google_revoke(string $token): void {
    try { http_call('POST', google_url('https://oauth2.googleapis.com/revoke'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $token]), 10); }
    catch (Throwable $e) { error_log('[topper] revogar token Google: ' . $e->getMessage()); }
}

/** Troca um token de atualização por um de acesso (1 h). */
function google_access_token(string $refresh): array {
    $c = google_client();
    if (!$c) throw new RuntimeException('A ligação à Google não está configurada.');
    [$st, $tok] = http_call('POST', google_url('https://oauth2.googleapis.com/token'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'refresh_token' => $refresh, 'grant_type' => 'refresh_token']), 15);
    if ($st !== 200 || empty($tok['access_token'])) {
        $err = (string)($tok['error'] ?? $st);
        throw new RuntimeException($err === 'invalid_grant' ? 'A Google revogou o acesso: volte a ligar o calendário no Perfil.' : 'A Google recusou o token (' . $err . ').');
    }
    return $tok;
}

// ── a conta Google associada (§31.5) ─────────────────────────

/** A conta Google associada: ['sub', 'email', 'at'] ou null. */
function google_linked(PDO $pdo, int $uid): ?array {
    if (!db_has_column($pdo, 'users', 'google_sub')) return null;
    $mail = db_has_column($pdo, 'users', 'google_email');
    $st = $pdo->prepare('SELECT google_sub' . ($mail ? ', google_email, google_linked_at' : '') . ' FROM users WHERE id = ?');
    $st->execute([$uid]);
    $r = $st->fetch();
    if (!$r || (string)$r['google_sub'] === '') return null;
    return ['sub' => (string)$r['google_sub'], 'email' => (string)($r['google_email'] ?? ''), 'at' => (string)($r['google_linked_at'] ?? '')];
}

/** Associa a conta Google a esta pessoa. Uma conta Google só entra numa pessoa. */
function google_link_save(PDO $pdo, int $uid, string $sub, string $email): void {
    if ($sub === '') throw new RuntimeException('A Google não devolveu o identificador da conta.');
    $st = $pdo->prepare('SELECT id FROM users WHERE google_sub = ? AND id <> ?');
    $st->execute([$sub, $uid]);
    if ($st->fetchColumn()) throw new RuntimeException('Esta conta Google já está associada a outra pessoa da topper.');
    $mail = db_has_column($pdo, 'users', 'google_email');
    $pdo->prepare('UPDATE users SET google_sub = ?' . ($mail ? ', google_email = ?, google_linked_at = ?' : '') . ' WHERE id = ?')
        ->execute($mail ? [$sub, mb_substr($email, 0, 190), date('Y-m-d H:i:s'), $uid] : [$sub, $uid]);
    if (function_exists('auth_log_event')) auth_log_event($pdo, $uid, 'g_link');
}

/** Tira a associação (o login por email e palavra-passe e as chaves de acesso ficam como estão). */
function google_unlink(PDO $pdo, int $uid): void {
    $mail = db_has_column($pdo, 'users', 'google_email');
    $pdo->prepare('UPDATE users SET google_sub = NULL' . ($mail ? ', google_email = NULL, google_linked_at = NULL' : '') . ' WHERE id = ?')->execute([$uid]);
    if (function_exists('auth_log_event')) auth_log_event($pdo, $uid, 'g_unlink');
}

/** Guarda a ligação ao Google Calendar de uma pessoa (o token de atualização cifrado). */
function google_calendar_store(PDO $pdo, int $uid, array $tok, string $email): void {
    if (empty($tok['refresh_token'])) throw new RuntimeException('A Google não devolveu o acesso permanente. Em myaccount.google.com › Segurança › Ligações de terceiros, retire a topper. e volte a ligar.');
    $scopes = (string)($tok['scope'] ?? '');
    if (!str_contains($scopes, 'calendar.events')) throw new RuntimeException('Falta a autorização para os eventos do calendário: volte a ligar e deixe a caixa marcada.');
    $enc = secret_seal(json_encode(['refresh_token' => (string)$tok['refresh_token']]));
    $now = date('Y-m-d H:i:s');
    $exists = $pdo->prepare('SELECT COUNT(*) FROM user_google WHERE user_id = ?');
    $exists->execute([$uid]);
    if ((int)$exists->fetchColumn()) {
        $pdo->prepare("UPDATE user_google SET google_email = ?, token_enc = ?, scopes = ?, last_error = '', busy_cache = NULL, busy_at = NULL, connected_at = ? WHERE user_id = ?")
            ->execute([$email, $enc, mb_substr($scopes, 0, 500), $now, $uid]);
    } else {
        $pdo->prepare('INSERT INTO user_google (user_id, google_email, token_enc, scopes, calendar_id, connected_at) VALUES (?,?,?,?,?,?)')
            ->execute([$uid, $email, $enc, mb_substr($scopes, 0, 500), 'primary', $now]);
    }
}

/** Guarda a conta da topper. (Meet) em inc/meet_config.php, mantendo o resto do ficheiro (ex.: Discord). */
function google_topper_store(string $refresh, string $email): void {
    $f = __DIR__ . '/meet_config.php';
    $raw = config_file_read($f) ?? [];
    $g = (array)($raw['google'] ?? []);
    foreach (['client_id', 'client_secret'] as $k) if (!config_filled($g[$k] ?? null)) unset($g[$k]);   // o cliente vem de auth_config.php
    $g['refresh_token'] = $refresh;
    $g['calendar_id'] = 'primary';
    $g['account'] = mb_substr($email, 0, 190);
    $raw['google'] = $g;
    config_file_write($f, "<?php\n// A conta da topper. para o Google Meet (§31.4/§31.5), escrita pela plataforma (Definições › Integrações › Google).\n"
        . "// Só no servidor: fora do Git e fechado à web. Ver meet_config.example.php.\nreturn " . var_export($raw, true) . ";\n");
}

/** Desliga a conta da topper.: revoga na Google e tira o token do ficheiro. */
function google_topper_remove(): void {
    $f = __DIR__ . '/meet_config.php';
    $raw = config_file_read($f) ?? [];
    $tok = (string)($raw['google']['refresh_token'] ?? '');
    if (config_filled($tok)) google_revoke($tok);
    unset($raw['google']['refresh_token'], $raw['google']['account']);
    config_file_write($f, "<?php\n// A conta da topper. para o Google Meet (§31.4/§31.5), escrita pela plataforma (Definições › Integrações › Google).\n"
        . "// Só no servidor: fora do Git e fechado à web. Ver meet_config.example.php.\nreturn " . var_export($raw, true) . ";\n");
}

/** O email da conta da topper. ligada ('' se não houver). */
function google_topper_account(): string {
    $raw = config_file_read(__DIR__ . '/meet_config.php') ?? [];
    return config_filled($raw['google']['refresh_token'] ?? null) ? (string)($raw['google']['account'] ?? '') : '';
}
