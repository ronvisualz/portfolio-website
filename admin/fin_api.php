<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fin.php';
$user = require_login();

// Pedidos do browser para o Financeiro (§28), sempre com a permissão vista aqui:
//   read      PDF/imagem → a IA lê e devolve os campos (nada fica gravado; o ficheiro guarda-se à parte
//             com um token para o formulário o juntar quando o utilizador confirmar)
//   sim       o resultado do simulador para os campos atuais (HTML)
//   param     mudar um parâmetro (só superadmin e admins)
//   mail_test «Testar» uma caixa de email (só leitura; só superadmin e admins)

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
    if ($a === 'mail_test') {
        if (!is_admin()) fj(403, ['error' => 'Só superadmin e admins.']);
        require_once __DIR__ . '/inc/fin_mail.php';
        fj(200, ['ok' => true, 'msg' => fin_mail_test($pdo, (string)($_POST['box'] ?? ''))]);
    }
    fj(400, ['error' => 'Ação desconhecida.']);
} catch (RuntimeException $e) {
    fj(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('fin_api ' . $a . ': ' . $e->getMessage());
    fj(500, ['error' => 'Algo correu mal. Tente outra vez.']);
}
