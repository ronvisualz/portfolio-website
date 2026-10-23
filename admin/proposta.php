<?php
/**
 * topper. — a proposta para o cliente responder (§19.4). Página pública, sem login e sem sessão.
 *
 *   GET  proposta.php?t=<token>   mostra a proposta: as opções, o preço, as condições e a validade.
 *                                 NUNCA altera nada (os filtros de segurança do email abrem os links).
 *   POST proposta.php             a resposta: aceitar (opção + «Li e aceito» + nome escrito) ou recusar
 *                                 (nome escrito, motivo opcional). Formulário assinado pelo servidor.
 *
 * Nada avança sozinho: a resposta fica «por validar» até alguém da topper. carregar em Validar.
 * Até lá o cliente pode mudar a resposta aqui. Um token inválido, sem efeito ou expirado mostra
 * sempre a mesma página curta, sem dizer mais nada.
 */
define('TOPPER_NO_SESSION', true);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/pipeline_flow.php';
require_once __DIR__ . '/inc/budget.php';

const PROPOSAL_FORM_TTL = 86400;   // um formulário aberto vale 24 h

function pp_h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function pp_eur(float $v): string { return number_format($v, 2, ',', ' ') . ' €'; }
function pp_pct(float $v): string { return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',') . '%'; }

/** A página (o mesmo esqueleto para tudo). */
function pp_page(string $title, string $body, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow"><title>' . pp_h($title) . ' · topper.</title>'
       . '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Jost:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">'
       . '<style>' . pp_css() . '</style></head><body><main class="pp">'
       . '<header class="pp-top"><span class="pp-logo">topper<b>.</b></span></header>' . $body
       . '<footer class="pp-foot">topper. · Esta página é só para si: não a partilhe.</footer></main></body></html>';
    exit;
}

function pp_css(): string {
    return ':root{--bg:#F6F6F4;--fg:#111;--mute:#5c5c5c;--line:#E3E3E0;--card:#fff;--hi:#FFC718;--ok:#2A7928;--bad:#C0362C;color-scheme:light}'
         . '@media (prefers-color-scheme:dark){:root{--bg:#0E0E0F;--fg:#EDEDEA;--mute:#a2a29e;--line:#2a2a2e;--card:#161618;--ok:#5cbf59;--bad:#ef6a5f;color-scheme:dark}}'
         . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.6 Inter,system-ui,sans-serif}'
         . '.pp{max-width:860px;margin:0 auto;padding:24px 16px 64px}.pp-top{padding:8px 0 24px}.pp-logo{font:700 28px Jost,sans-serif}.pp-logo b{color:var(--hi)}'
         . 'h1{font:700 clamp(26px,5vw,36px)/1.15 Jost,sans-serif;margin:0 0 4px}h2{font:600 13px Inter;letter-spacing:.1em;text-transform:uppercase;color:var(--mute);margin:28px 0 8px}'
         . '.sub{color:var(--mute);margin:0 0 8px}.opts{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}'
         . '.opt{display:block;background:var(--card);border:1.5px solid var(--line);border-radius:14px;padding:16px;cursor:pointer;position:relative}'
         . '.opt input{position:absolute;top:18px;right:16px;width:20px;height:20px;accent-color:#111}.opt:has(input:checked){border-color:var(--hi);box-shadow:0 0 0 3px color-mix(in srgb,var(--hi) 35%,transparent)}'
         . '.opt small{display:block;font:600 11px Inter;letter-spacing:.1em;color:var(--mute)}.opt .p{font:700 26px Jost,sans-serif;margin:6px 0 2px}.opt .v{color:var(--mute);font-size:14px}'
         . '.opt .s{white-space:pre-line;margin-top:10px;font-size:15px}.box{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px}'
         . 'dl{display:grid;grid-template-columns:max-content 1fr;gap:8px 16px;margin:0}dt{color:var(--mute)}dd{margin:0;white-space:pre-line}'
         . 'label.ck{display:flex;gap:10px;align-items:flex-start;margin:14px 0}label.ck input{width:20px;height:20px;margin-top:3px}'
         . '.fld{display:block;margin:10px 0}.fld span{display:block;font-size:14px;color:var(--mute);margin-bottom:4px}'
         . 'input[type=text],textarea{width:100%;font:inherit;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--fg)}'
         . 'button{font:600 16px Inter;border:0;border-radius:10px;padding:12px 18px;cursor:pointer}.yes{background:var(--hi);color:#111}.no{background:transparent;color:var(--fg);border:1px solid var(--line)}'
         . 'button:focus-visible,input:focus-visible,textarea:focus-visible,summary:focus-visible{outline:2px solid var(--hi);outline-offset:2px}'
         . 'details{margin-top:18px}summary{cursor:pointer;color:var(--mute)}.msg{border-left:4px solid var(--hi);background:var(--card);padding:12px 16px;border-radius:8px;margin:16px 0}'
         . '.msg.ok{border-color:var(--ok)}.msg.err{border-color:var(--bad)}.pp-foot{margin-top:48px;color:var(--mute);font-size:13px}.sr{position:absolute;left:-9999px}'
         . '.optcol{display:flex;flex-direction:column;gap:12px;min-width:0}.ccp{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px}'
         . '.ccp small{display:block;font:600 11px Inter;letter-spacing:.1em;color:var(--mute)}.ccp h3{font:700 22px/1.2 Jost,sans-serif;margin:6px 0 6px}'
         . '.cc-c{font-size:17px;font-weight:500;margin:0 0 8px}.cc-a{color:var(--mute);font-size:15px;margin:0 0 12px;white-space:pre-line}'
         . '.cc-g{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}.cc-g img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:8px;background:var(--line);display:block}'
         . '.cc-f{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-top:12px;font-size:13px;color:var(--mute)}'
         . '.cc-pal{display:inline-flex;gap:4px}.cc-pal i{display:block;width:26px;height:26px;border-radius:6px;box-shadow:inset 0 0 0 1px rgba(0,0,0,.08)}';
}

/** Uma página do pré-criativo (§23.3) ao lado da opção: título, conceito, abordagem, 6 referências, paleta, técnica. */
function pp_cc_page(array $pg, string $token): string {
    $imgs = '';
    foreach ($pg['refs'] as $i => $aid) {
        $imgs .= '<img src="/admin/cc_file.php?pt=' . pp_h(rawurlencode($token)) . '&amp;a=' . (int)$aid . '&amp;s=768" alt="Referência ' . ($i + 1) . '" loading="lazy" decoding="async">';
    }
    $pal = '';
    foreach ($pg['palette'] as $hex) $pal .= '<i style="background:' . pp_h($hex) . '" title="' . pp_h($hex) . '"></i>';
    $meta = array_filter([$pg['technique'], $pg['complexity'] !== '' ? 'Complexidade ' . mb_strtolower($pg['complexity']) : '']);
    return '<section class="ccp" aria-label="Pré-criativo"><small>PRÉ-CRIATIVO' . ($pg['front'] !== '' ? ' · ' . pp_h(mb_strtoupper($pg['front'])) : '') . '</small>'
         . ($pg['title'] !== '' ? '<h3>' . pp_h($pg['title']) . '</h3>' : '')
         . ($pg['concept'] !== '' ? '<p class="cc-c">' . pp_h($pg['concept']) . '</p>' : '')
         . ($pg['approach'] !== '' ? '<p class="cc-a">' . pp_h($pg['approach']) . '</p>' : '')
         . ($imgs !== '' ? '<div class="cc-g">' . $imgs . '</div>' : '')
         . ($pal !== '' || $meta ? '<div class="cc-f">' . ($pal !== '' ? '<span class="cc-pal" aria-label="Paleta">' . $pal . '</span>' : '') . '<span>' . pp_h(implode(' · ', $meta)) . '</span></div>' : '')
         . '</section>';
}

function pp_gone(): void {
    pp_page('Proposta', '<h1>Este link expirou ou não existe.</h1><p class="sub">Se precisa da proposta, responda ao email que recebeu e enviamos uma nova.</p>', 404);
}

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$p = proposal_by_token($pdo, $token);
if (!$p) pp_gone();
$deal = deal_get($pdo, (int)$p['deal_id']);
$v = budget_version_get($pdo, (int)$p['version_id']);
if (!$deal || !$v) pp_gone();
$content = proposal_public_content($pdo, $deal, $v);
$state = proposal_state($pdo, $p, $content);
if ($state === 'void') pp_page('Proposta', '<h1>Este link já não é válido.</h1><p class="sub">Entretanto enviámos uma versão nova da proposta: use o link do email mais recente.</p>', 410);

$err = '';
// ── a resposta (só por POST, com o formulário assinado) ────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ts = (int)($_POST['ts'] ?? 0);
    $sig = (string)($_POST['sig'] ?? '');
    $host = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $sameOrigin = $origin === '' || $origin === 'null' || strtolower((string)parse_url($origin, PHP_URL_HOST)) === $host;
    if (!$sameOrigin || $ts < time() - PROPOSAL_FORM_TTL || $ts > time() + 300 || !hash_equals(proposal_form_sig($pdo, (string)$p['token_hash'], $ts), $sig)) {
        $err = 'O formulário expirou. A página foi recarregada: confirme outra vez.';
    } elseif ($state !== 'ok') {
        $err = 'Esta proposta já não aceita respostas.';
    } elseif ((int)$p['answers'] >= 20) {
        $err = 'Já recebemos várias respostas a esta proposta. Fale connosco por email para mudar.';
    } else {
        try {
            $msg = proposal_answer($pdo, $p, $content, (string)($_POST['decision'] ?? ''), (string)($_POST['choice'] ?? ''),
                                   (string)($_POST['name'] ?? ''), !empty($_POST['agree']), (string)($_POST['reason'] ?? ''),
                                   (string)($_SERVER['REMOTE_ADDR'] ?? ''), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            // PRG: volta ao GET (recarregar não reenvia).
            header('Location: /admin/proposta.php?t=' . rawurlencode($token) . '&r=1', true, 303);
            exit;
        } catch (RuntimeException $e) {
            $err = $e->getMessage();
        }
    }
    $p = proposal_by_token($pdo, $token);
}

// ── a página ───────────────────────────────────────────────
$c = $content;
$opts = '';
require_once __DIR__ . '/inc/cc_flow.php';
foreach ($c['options'] as $i => $o) {
    $sel = $p['choice'] === $o['key'] || ($p['choice'] === '' && $i === count($c['options']) - 1);
    $cc = '';
    foreach (cc_pages_for_opt($c['creative'] ?? [], $o['key']) as $pg) $cc .= pp_cc_page($pg, $token);
    $opts .= '<div class="optcol"><label class="opt"><small>' . pp_h(mb_strtoupper($o['label'])) . '</small>'
           . (count($c['options']) > 1 && $state === 'ok' && $p['status'] !== 'validated' ? '<input type="radio" name="choice" value="' . $o['key'] . '"' . ($sel ? ' checked' : '') . ' aria-label="Escolher ' . pp_h($o['label']) . '">' : '')
           . '<div class="p">' . pp_eur($o['price']) . '</div><div class="v">s/ IVA · IVA (' . pp_pct((float)$c['vat_rate']) . ') ' . pp_eur($o['vat']) . ' · total ' . pp_eur($o['total']) . '</div>'
           . ($o['scope'] !== '' ? '<div class="s">' . pp_h($o['scope']) . '</div>' : '') . '</label>' . $cc . '</div>';
}
$terms = '';
foreach (['Prazo' => $c['term'], 'Pagamento' => $c['payment'], 'Revisões incluídas' => $c['revisions'], 'Direitos de utilização' => $c['rights'], 'Outras condições' => $c['conditions']] as $k => $txt) {
    if (trim((string)$txt) !== '') $terms .= '<dt>' . pp_h($k) . '</dt><dd>' . pp_h($txt) . '</dd>';
}
$terms .= '<dt>Válida até</dt><dd>' . pp_h(date('d/m/Y', strtotime($c['valid_until']))) . '</dd>';

$body = '<p class="sub">Proposta ' . pp_h($c['code']) . ' · v' . (int)$c['version'] . '</p><h1>' . pp_h($c['name']) . '</h1>';
if (!empty($_GET['r']) && $p['decision'] !== '') $body .= '<div class="msg ok">Obrigado: registámos a sua resposta e enviámos uma confirmação por email.</div>';
if ($err !== '') $body .= '<div class="msg err" role="alert">' . pp_h($err) . '</div>';
if ($p['decision'] !== '') {
    $body .= '<div class="msg' . ($p['status'] === 'validated' ? ' ok' : '') . '"><b>A sua resposta: ' . pp_h(proposal_answer_label($p)) . '</b> · ' . pp_h($p['signer_name'])
           . ' · ' . pp_h(date('d/m/Y H:i', strtotime((string)$p['answered_at'])))
           . ($p['status'] === 'validated' ? '<br>Confirmada pela topper.' : '<br>Pode mudar a resposta abaixo até a confirmarmos.') . '</div>';
}
if ($state === 'expired') $body .= '<div class="msg err">Esta proposta expirou a ' . pp_h(date('d/m/Y', strtotime((string)$p['expires_on']))) . '. Responda ao email e enviamos uma atualizada.</div>';
if ($state === 'changed') $body .= '<div class="msg err">Esta proposta foi atualizada entretanto. Aguarde o novo envio (ou responda ao email).</div>';

$canAnswer = $state === 'ok' && $p['status'] !== 'validated';
$ts = time();
$hidden = '<input type="hidden" name="t" value="' . pp_h($token) . '"><input type="hidden" name="ts" value="' . $ts . '"><input type="hidden" name="sig" value="' . pp_h(proposal_form_sig($pdo, (string)$p['token_hash'], $ts)) . '">';
$body .= '<form method="post" action="/admin/proposta.php">' . $hidden
       . '<h2>' . (count($c['options']) > 1 ? 'Escolha a opção' : 'A proposta') . '</h2><div class="opts">' . $opts . '</div>'
       . '<h2>Condições</h2><div class="box"><dl>' . $terms . '</dl></div>';
if ($canAnswer) {
    $body .= '<h2>Aceitar</h2><div class="box">'
           . '<label class="ck"><input type="checkbox" name="agree" value="1" required><span>Li e aceito as condições desta proposta.</span></label>'
           . '<label class="fld"><span>O seu nome (escrito por extenso, vale como confirmação)</span><input type="text" name="name" required minlength="3" maxlength="160" autocomplete="name" value="' . pp_h($p['signer_name']) . '"></label>'
           . '<button class="yes" type="submit" name="decision" value="accept">Confirmar aceitação</button></div>';
}
$body .= '</form>';
if ($canAnswer) {
    $body .= '<details><summary>Prefere recusar a proposta?</summary><form method="post" action="/admin/proposta.php" class="box" style="margin-top:10px">' . $hidden
           . '<label class="fld"><span>Motivo (opcional, ajuda-nos a melhorar)</span><textarea name="reason" rows="3" maxlength="2000"></textarea></label>'
           . '<label class="fld"><span>O seu nome</span><input type="text" name="name" required minlength="3" maxlength="160" autocomplete="name"></label>'
           . '<button class="no" type="submit" name="decision" value="refuse">Recusar a proposta</button></form></details>';
}
pp_page('Proposta ' . $c['code'], $body);
