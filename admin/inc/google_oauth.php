<?php
/**
 * topper. admin — OAuth da Google (§31.2 e §31.3)
 *
 * Um só cliente OAuth (Definições › Integrações › Google; credenciais em inc/auth_config.php, fora do Git) para:
 *  · «Entrar com Google» (OpenID Connect): só entra quem já tem utilizador com o mesmo email, verificado pela
 *    Google. Nunca cria contas. Da primeira vez guarda o identificador da conta Google (users.google_sub); daí em
 *    diante só essa conta entra por esta via.
 *  · «Ligar Google Calendar» (Perfil › Ligações): scopes de eventos e de ocupado/livre, token de atualização cifrado
 *    (inc/secrets.php) e revogável. Funciona com contas Google gratuitas.
 * Proteções: state (uma vez, 10 min, guardado na sessão), PKCE S256 e nonce no id_token.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/secrets.php';

const GOOGLE_SCOPES_LOGIN = 'openid email';

/** O endereço de uma API da Google (simulável só na bancada local: ver ext_url em inc/secrets.php). */
function google_url(string $url): string {
    return ext_url($url);
}
const GOOGLE_SCOPES_CAL = 'openid email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy';

function google_redirect_uri(): string {
    return app_base_url() . '/admin/google_auth.php';
}

/** O endereço da Google para começar ($purpose: 'login' | 'calendar'). */
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
    if ($purpose === 'calendar') {
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
