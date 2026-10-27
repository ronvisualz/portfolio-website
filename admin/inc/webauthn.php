<?php
/**
 * topper. admin — chaves de acesso (passkeys / WebAuthn) do lado do servidor (§31.2)
 *
 * O mínimo que é preciso, sem dependências: descodificador CBOR, leitura do authenticatorData, conversão da chave
 * COSE para PEM e verificação das assinaturas (ES256, RS256 com OpenSSL; EdDSA com sodium).
 * Pede-se atestação «none»: não interessa que marca de aparelho é, só que a chave pública é desta pessoa. O que
 * se verifica sempre: o tipo, o desafio (guardado na sessão, uma só vez), a origem, o hash do rpId, os sinais de
 * presença e de verificação do utilizador (Face ID, impressão digital ou PIN) e o contador (contra clones).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

const WA_ALGS = [-7, -8, -257];   // ES256, EdDSA, RS256 — por ordem de preferência

function wa_b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function wa_unb64u(string $s): string {
    $s = strtr($s, '-_', '+/');
    $r = base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    if ($r === false) throw new RuntimeException('Dados da chave inválidos.');
    return $r;
}

// ── CBOR (RFC 8949), só o necessário ────────────────────────

/** Descodifica um item CBOR a partir de $o; avança $o. Bytes e texto chegam como string. */
function wa_cbor(string $d, int &$o, int $depth = 0) {
    if ($depth > 16) throw new RuntimeException('CBOR demasiado fundo.');
    if ($o >= strlen($d)) throw new RuntimeException('CBOR truncado.');
    $ib = ord($d[$o++]);
    $mt = $ib >> 5; $ai = $ib & 0x1f;
    $len = function () use ($d, &$o, $ai): int {
        if ($ai < 24) return $ai;
        $n = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$ai] ?? 0;
        if (!$n || $o + $n > strlen($d)) throw new RuntimeException('CBOR inválido.');
        $v = 0;
        for ($i = 0; $i < $n; $i++) $v = ($v << 8) | ord($d[$o + $i]);
        $o += $n;
        if ($v < 0) throw new RuntimeException('CBOR: valor grande demais.');
        return $v;
    };
    switch ($mt) {
        case 0: return $len();
        case 1: return -1 - $len();
        case 2: case 3:
            $n = $len();
            if ($o + $n > strlen($d)) throw new RuntimeException('CBOR truncado.');
            $s = substr($d, $o, $n); $o += $n;
            return $s;
        case 4:
            $n = $len(); $a = [];
            for ($i = 0; $i < $n; $i++) $a[] = wa_cbor($d, $o, $depth + 1);
            return $a;
        case 5:
            $n = $len(); $m = [];
            for ($i = 0; $i < $n; $i++) { $k = wa_cbor($d, $o, $depth + 1); $m[is_int($k) || is_string($k) ? $k : (string)json_encode($k)] = wa_cbor($d, $o, $depth + 1); }
            return $m;
        case 6: $len(); return wa_cbor($d, $o, $depth + 1);   // etiqueta: fica o valor
        case 7:
            if ($ai === 20) return false;
            if ($ai === 21) return true;
            if ($ai === 22 || $ai === 23) return null;
            if ($ai === 25) { $o += 2; return 0.0; }
            if ($ai === 26) { $v = unpack('G', substr($d, $o, 4))[1]; $o += 4; return $v; }
            if ($ai === 27) { $v = unpack('E', substr($d, $o, 8))[1]; $o += 8; return $v; }
            throw new RuntimeException('CBOR: valor simples desconhecido.');
    }
    throw new RuntimeException('CBOR inválido.');
}

// ── authenticatorData ──────────────────────────────────────

/** Lê o authenticatorData: rpIdHash, sinais, contador e (no registo) a credencial com a chave COSE. */
function wa_auth_data(string $ad): array {
    if (strlen($ad) < 37) throw new RuntimeException('Dados do autenticador incompletos.');
    $out = ['rp' => substr($ad, 0, 32), 'flags' => ord($ad[32]), 'count' => unpack('N', substr($ad, 33, 4))[1]];
    if ($out['flags'] & 0x40) {   // AT: dados da credencial
        if (strlen($ad) < 55) throw new RuntimeException('Credencial incompleta.');
        $n = unpack('n', substr($ad, 53, 2))[1];
        $out['aaguid'] = bin2hex(substr($ad, 37, 16));
        $out['cred_id'] = substr($ad, 55, $n);
        $o = 55 + $n;
        $out['cose'] = wa_cbor($ad, $o);
    }
    return $out;
}

// ── chaves COSE → PEM ──────────────────────────────────────

function wa_der_len(int $n): string {
    if ($n < 0x80) return chr($n);
    $b = ltrim(pack('N', $n), "\0");
    return chr(0x80 | strlen($b)) . $b;
}
function wa_der(int $tag, string $v): string { return chr($tag) . wa_der_len(strlen($v)) . $v; }
function wa_der_uint(string $bin): string {
    $bin = ltrim($bin, "\0");
    if ($bin === '' || ord($bin[0]) & 0x80) $bin = "\0" . $bin;
    return wa_der(0x02, $bin);
}
function wa_pem(string $der): string {
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** A chave pública COSE em [alg, chave para guardar] (PEM; para Ed25519, os 32 bytes em base64). */
function wa_cose_key(array $k): array {
    $kty = $k[1] ?? null; $alg = $k[3] ?? null;
    if ($kty === 2 && ($k[-1] ?? null) === 1 && in_array($alg, [-7, null], true)) {   // EC2 P-256
        $x = (string)($k[-2] ?? ''); $y = (string)($k[-3] ?? '');
        if (strlen($x) !== 32 || strlen($y) !== 32) throw new RuntimeException('Chave EC inválida.');
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        return [-7, wa_pem($spki)];
    }
    if ($kty === 3 && in_array($alg, [-257, null], true)) {   // RSA
        $n = (string)($k[-1] ?? ''); $e = (string)($k[-2] ?? '');
        if (strlen($n) < 256 || $e === '') throw new RuntimeException('Chave RSA inválida.');
        $rsa = wa_der(0x30, wa_der_uint($n) . wa_der_uint($e));
        $spki = wa_der(0x30, wa_der(0x30, hex2bin('06092a864886f70d0101010500')) . wa_der(0x03, "\0" . $rsa));
        return [-257, wa_pem($spki)];
    }
    if ($kty === 1 && ($k[-1] ?? null) === 6 && in_array($alg, [-8, null], true)) {   // OKP Ed25519
        $x = (string)($k[-2] ?? '');
        if (strlen($x) !== 32) throw new RuntimeException('Chave Ed25519 inválida.');
        return [-8, base64_encode($x)];
    }
    throw new RuntimeException('Este tipo de chave não é suportado.');
}

function wa_verify_sig(int $alg, string $key, string $data, string $sig): bool {
    if ($alg === -8) {
        if (!function_exists('sodium_crypto_sign_verify_detached')) return false;
        $pk = base64_decode($key, true);
        return $pk !== false && strlen($sig) === 64 && sodium_crypto_sign_verify_detached($sig, $data, $pk);
    }
    $pub = openssl_pkey_get_public($key);
    return $pub !== false && openssl_verify($data, $sig, $pub, OPENSSL_ALGO_SHA256) === 1;
}

// ── as duas cerimónias ─────────────────────────────────────

/** Confere o clientDataJSON: tipo, desafio e origem. Devolve o texto em bruto (para o hash). */
function wa_client_data(string $cdB64, string $type, string $challenge, string $origin): string {
    $raw = wa_unb64u($cdB64);
    $c = json_decode($raw, true);
    if (!is_array($c) || ($c['type'] ?? '') !== $type) throw new RuntimeException('Resposta da chave inválida.');
    if (!hash_equals(wa_b64u($challenge), (string)($c['challenge'] ?? ''))) throw new RuntimeException('O pedido expirou. Tente outra vez.');
    if (!hash_equals($origin, (string)($c['origin'] ?? ''))) throw new RuntimeException('Origem errada.');
    if (!empty($c['crossOrigin'])) throw new RuntimeException('Origem errada.');
    return $raw;
}

/**
 * Registo: confere a resposta de navigator.credentials.create e devolve
 * ['cred_id' (b64url), 'alg', 'key', 'count'].
 */
function wa_register_verify(array $r, string $challenge, string $origin, string $rpId): array {
    wa_client_data((string)($r['clientDataJSON'] ?? ''), 'webauthn.create', $challenge, $origin);
    $o = 0;
    $att = wa_cbor(wa_unb64u((string)($r['attestationObject'] ?? '')), $o);
    if (!is_array($att) || !isset($att['authData'])) throw new RuntimeException('Resposta da chave inválida.');
    $ad = wa_auth_data((string)$att['authData']);
    if (!hash_equals(hash('sha256', $rpId, true), $ad['rp'])) throw new RuntimeException('A chave não é para este site.');
    if (!($ad['flags'] & 0x01)) throw new RuntimeException('Falta confirmar no aparelho.');
    if (!($ad['flags'] & 0x04)) throw new RuntimeException('O aparelho não confirmou quem é (Face ID, impressão digital ou PIN).');
    if (empty($ad['cred_id']) || !is_array($ad['cose'] ?? null)) throw new RuntimeException('Resposta da chave sem credencial.');
    [$alg, $key] = wa_cose_key($ad['cose']);
    return ['cred_id' => wa_b64u($ad['cred_id']), 'alg' => $alg, 'key' => $key, 'count' => (int)$ad['count']];
}

/**
 * Entrada: confere a resposta de navigator.credentials.get contra a chave guardada ($pk: alg, public_key,
 * sign_count). Devolve o contador novo.
 */
function wa_login_verify(array $r, array $pk, string $challenge, string $origin, string $rpId): int {
    $cd = wa_client_data((string)($r['clientDataJSON'] ?? ''), 'webauthn.get', $challenge, $origin);
    $adRaw = wa_unb64u((string)($r['authenticatorData'] ?? ''));
    $ad = wa_auth_data($adRaw);
    if (!hash_equals(hash('sha256', $rpId, true), $ad['rp'])) throw new RuntimeException('A chave não é para este site.');
    if (!($ad['flags'] & 0x01) || !($ad['flags'] & 0x04)) throw new RuntimeException('O aparelho não confirmou quem é (Face ID, impressão digital ou PIN).');
    if (!wa_verify_sig((int)$pk['alg'], (string)$pk['public_key'], $adRaw . hash('sha256', $cd, true), wa_unb64u((string)($r['signature'] ?? '')))) throw new RuntimeException('A assinatura da chave não confere.');
    $old = (int)$pk['sign_count']; $new = (int)$ad['count'];
    if (($old > 0 || $new > 0) && $new <= $old) throw new RuntimeException('Esta chave parece copiada (contador). Por segurança, entre de outra forma e remova-a no Perfil.');
    return $new;
}

/** O identificador da pessoa dentro da chave (opaco: não revela o id nem o email). */
function wa_user_handle(int $uid): string {
    require_once __DIR__ . '/secrets.php';
    return substr(hash_hmac('sha256', 'wa-user:' . $uid, app_key(), true), 0, 16);
}

/** O rpId (o domínio, sem porta) e a origem deste pedido. */
function wa_rp(): array {
    $host = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'topperhub.xyz'))[0]);
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $origin = ($https ? 'https://' : 'http://') . strtolower((string)($_SERVER['HTTP_HOST'] ?? 'topperhub.xyz'));
    return [$host, $origin];
}
