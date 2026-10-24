<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fin.php';
$user = require_login();

// Pedidos do browser para o Financeiro (§28), sempre com a permissão vista aqui:
//   read      PDF/imagem → a IA lê e devolve os campos (nada fica gravado; o ficheiro guarda-se à parte
//             com um token para o formulário o juntar quando o utilizador confirmar)
//   sim       o resultado do simulador para os campos atuais (HTML)
//   param     mudar um parâmetro (só superadmin e admins)
//   mail_test «Testar» uma caixa de email (só superadmin e admins): pela Cloudflare, um email com código que a
//             página vê chegar (mail_test_seen); no modo IMAP, entra só para ler
//   mail_secret, mail_api(_del), mail_send_test   o segredo do Worker, a chave do serviço de envio e um envio de teste

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function fj(int $code, array $b): void { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
if (!fin_ready($pdo)) fj(503, ['error' => 'A base de dados ainda não está no passo 30.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fj(405, ['error' => 'Método inválido.']);
$sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) fj(403, ['error' => 'Pedido inválido. Recarregue a página e tente outra vez.']);
$a = (string)($_GET['a'] ?? '');

try {
    if ($a === 'read') {
        if (!can('financeiro', PERM_EDIT)) fj(403, ['error' => 'Sem permissão.']);
        $f = fin_upload($_FILES['file'] ?? null);
        if (!$f) fj(400, ['error' => 'Escolha o PDF.']);
        $tok = fin_tmp_put($f, (int)$user['id']);
        require_once __DIR__ . '/inc/fin_ai.php';
        $out = ['token' => $tok, 'name' => $f[1]];
        try {
            $r = fin_ai_read($pdo, $f[0], $f[2]);
            $dup = $r['direction'] === 'out' ? fin_doc_dup($pdo, $r['party_nif'], $r['number']) : null;
            $out += ['fields' => $r, 'dup' => $dup ? ['id' => (int)$dup['id'], 'label' => fin_doc_label($dup) . ' · ' . $dup['party_name']] : null];
        } catch (AiException $e) {
            $out['note'] = $e->getMessage();
        }
        fj(200, $out);
    }
    if ($a === 'sim') {
        require_perm('financeiro');
        require_once __DIR__ . '/inc/fin_sim.php';
        require_once __DIR__ . '/inc/fin_view.php';
        $kind = (string)($_POST['kind'] ?? 'hire');
        if (!isset(FIN_SIM_KINDS[$kind])) fj(400, ['error' => 'Tipo inválido.']);
        $in = fin_sim_inputs($kind, $_POST);
        fj(200, ['html' => fin_sim_result_html($pdo, $kind, fin_sim($pdo, $kind, $in))]);
    }
    if ($a === 'param') {
        if (!is_admin()) fj(403, ['error' => 'Só superadmin e admins mudam os parâmetros do Financeiro.']);
        $k = (string)($_POST['key'] ?? '');
        $field = (string)($_POST['field'] ?? 'value');
        if (!in_array($field, ['value', 'source', 'year', 'verified_on'], true)) fj(400, ['error' => 'Campo inválido.']);
        require_once __DIR__ . '/inc/settings_def.php';
        [$old] = [(string)(fin_params($pdo)[$k][$field] ?? '')];
        fin_param_save($pdo, $user, $k, [$field => (string)($_POST['v'] ?? '')]);
        $p = fin_params($pdo, true)[$k];
        require_once __DIR__ . '/inc/undo.php';
        fj(200, ['ok' => true, 'value' => (string)$p[$field], 'msg' => $p['label'] . ' guardado.',
                 'undo' => undo_push('fin_param', ['key' => $k, 'field' => $field, 'v' => $old])]);
    }
    if ($a === 'mail_pass' || $a === 'mail_pass_del') {   // a palavra-passe de uma caixa: testada e gravada só no servidor
        if (!is_admin()) fj(403, ['error' => 'Só superadmin e admins.']);
        require_once __DIR__ . '/inc/fin_mail.php';
        $box = strtolower(trim((string)($_POST['box'] ?? '')));
        if (!in_array($box, array_merge(fin_mail_boxes($pdo), [mail_general($pdo)]), true)) fj(422, ['error' => 'Essa caixa não está na lista.']);
        fin_mail_save_pass($box, $a === 'mail_pass' ? (string)($_POST['pass'] ?? '') : null);
        require_once __DIR__ . '/inc/settings_def.php';
        setting_log($pdo, 'int_fin_mail', 'Palavra-passe de ' . $box, null, $a === 'mail_pass' ? 'guardada no servidor' : 'tirada do servidor');   // nunca o valor
        fj(200, ['ok' => true, 'msg' => $a === 'mail_pass' ? 'Palavra-passe de ' . $box . ' certa e guardada no servidor.' : 'Palavra-passe de ' . $box . ' tirada do servidor.']);
    }
    if ($a === 'mail_test') {
        if (!is_admin()) fj(403, ['error' => 'Só superadmin e admins.']);
        require_once __DIR__ . '/inc/fin_mail.php';
        if (fin_mail_mode($pdo) === 'cloudflare') {
            // pela Cloudflare: um email com um código vai para a caixa; a página espera vê-lo chegar
            $t = mail_inbound_test_start($pdo, (string)($_POST['box'] ?? ''));
            fj(200, ['ok' => true, 'code' => $t['code'], 'wait' => true,
                     'msg' => $t['sent'] ? 'Enviei um email de teste («' . $t['subject'] . '»). À espera que chegue pela Cloudflare…'
                                         : 'Envie um email para ' . (string)$_POST['box'] . ' com o assunto «' . $t['subject'] . '» (de qualquer endereço). À espera que chegue…'
                                           . ($t['msg'] !== '' ? ' (o envio automático falhou: ' . $t['msg'] . ')' : '')]);
        }
        fj(200, ['ok' => true, 'msg' => fin_mail_test($pdo, (string)($_POST['box'] ?? ''))]);
    }
    if ($a === 'mail_test_seen') {   // o email de teste já chegou?
        if (!is_admin()) fj(403, ['error' => 'Só superadmin e admins.']);
        require_once __DIR__ . '/inc/mail_inbound.php';
        $r = mail_inbound_test_seen($pdo, strtoupper((string)($_POST['code'] ?? '')));
        fj(200, ['ok' => true, 'seen' => (bool)$r, 'msg' => $r ? 'Chegou às ' . date('H:i:s', strtotime((string)$r['created_at'])) . ($r['box'] !== '' ? ' · reencaminhado por ' . $r['box'] . '. Está tudo ligado.' : ' · mas não consegui dizer de que caixa veio: confirme o reencaminhamento (com cópia) nessa caixa.') : '']);
    }
    if ($a === 'mail_secret') {   // um segredo novo para o Worker do email: mostrado uma vez, nunca guardado na base
        if (!is_admin()) fj(403, ['error' => 'Só superadmin e admins.']);
        require_once __DIR__ . '/inc/mailer.php';
        $sec = mailer_inbound_secret_new();
        require_once __DIR__ . '/inc/settings_def.php';
        setting_log($pdo, 'int_mail_cf', 'Segredo do Worker do email', null, 'gerado (o anterior deixou de valer)');   // nunca o valor
        fj(200, ['ok' => true, 'secret' => $sec, 'msg' => 'Segredo novo gerado. Copie-o agora para o Worker (não volta a aparecer).']);
    }
    if ($a === 'mail_api' || $a === 'mail_api_del') {   // a chave do serviço de envio: confirmada e gravada só no servidor
        if (!is_admin()) fj(403, ['error' => 'Só superadmin e admins.']);
        require_once __DIR__ . '/inc/mailer.php';
        $prov = (string)($_POST['provider'] ?? '');
        if ($a === 'mail_api' && !isset(MAILER_PROVIDERS[$prov])) fj(422, ['error' => 'Escolha o serviço (Resend ou Brevo).']);
        $msg = mailer_api_save($prov, $a === 'mail_api' ? (string)($_POST['key'] ?? '') : null);
        require_once __DIR__ . '/inc/settings_def.php';
        setting_log($pdo, 'int_mail_cf', 'Chave do serviço de envio', null, $a === 'mail_api' ? MAILER_PROVIDERS[$prov] . ': guardada no servidor' : 'tirada do servidor');   // nunca o valor
        fj(200, ['ok' => true, 'msg' => $msg]);
    }
    if ($a === 'mail_send_test') {   // um email de teste para quem está a configurar, pelo caminho normal de envio
        if (!is_admin()) fj(403, ['error' => 'Só superadmin e admins.']);
        require_once __DIR__ . '/inc/fin_mail.php';
        $to = (string)$user['email'];
        fin_smtp_send($to, 'topper. — teste de envio', "Este email saiu da plataforma topper. pelo " . (mailer_mode($pdo) === 'api' ? 'serviço de envio' : 'SMTP do Hostinger') . ".\nSe o recebeu, o envio está pronto.\n");
        fj(200, ['ok' => true, 'msg' => 'Enviado para ' . $to . '. Veja a sua caixa de entrada (e o spam, da primeira vez).']);
    }
    fj(400, ['error' => 'Ação desconhecida.']);
} catch (RuntimeException $e) {
    fj(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('fin_api ' . $a . ': ' . $e->getMessage());
    fj(500, ['error' => 'Algo correu mal. Tente outra vez.']);
}
