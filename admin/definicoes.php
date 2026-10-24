<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/settings_def.php';
require_once __DIR__ . '/inc/undo.php';
$user = require_login();

// Definições (§20): um separador por aba com campos editáveis. Grava sozinho (sem botão
// Gravar), com «Anular»; avisa antes de mexer em dados existentes; «Repor valor de origem»
// em cada campo; registo de alterações com «Repor» por linha; ⓘ em cada definição.
// Só superadmin e admins (verificado aqui e em cada gravação, no servidor).
// Utilizadores, papéis, permissões, cargos e departamentos continuam na aba Equipa.

if (!is_admin()) {
    http_response_code(403);
    render_header('Definições', 'definicoes');
    echo '<h1>Definições</h1><p class="muted">Só superadmin e admins mudam as Definições. As suas preferências estão no <a href="/admin/conta.php">Perfil</a>.</p>';
    render_footer();
    exit;
}

// Modelos de orçamento: apagar (com «Anular»).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['ut_revoke'])) {   // tokens pessoais (§26): um admin pode revogar o de qualquer pessoa
        require_once __DIR__ . '/inc/cc_rt.php';
        try { ut_revoke($pdo, (int)$_POST['ut_revoke'], $user); flash('Token revogado: deixa de funcionar já.'); }
        catch (RuntimeException $e) { flash($e->getMessage(), 'err'); }
        redirect('/admin/definicoes.php?t=integracoes#set-int_tokens');
    }
    if (isset($_POST['tpl_del'])) {
        $st = $pdo->prepare('SELECT * FROM budget_templates WHERE id = ?');
        $st->execute([(int)$_POST['tpl_del']]);
        if ($t = $st->fetch()) {
            $pdo->prepare('DELETE FROM budget_templates WHERE id = ?')->execute([$t['id']]);
            setting_log($pdo, 'budget_templates', 'Orçamentação · Modelo apagado', $t['name'], '');
            flash('Modelo «' . $t['name'] . '» apagado.', 'ok', undo_push('budget_tpl', $t));
        }
    }
    if (isset($_POST['irs_import'])) {   // Financeiro: tabelas de retenção novas (§28.6)
        require_once __DIR__ . '/inc/fin_pay.php';
        try {
            $n = fin_irs_import($pdo, (int)($_POST['year'] ?? 0), (string)($_POST['region'] ?? 'continente'), (string)($_POST['rows'] ?? ''), trim((string)($_POST['source'] ?? '')));
            setting_log($pdo, 'fin_irs', 'Financeiro · Tabelas de retenção do IRS importadas', '', (int)$_POST['year'] . ' · ' . (string)$_POST['region'] . ' · ' . $n . ' linhas');
            flash($n . ' linhas importadas. Para as usar, mude «Ano das tabelas em uso».');
        } catch (RuntimeException $e) { flash($e->getMessage(), 'err'); }
        redirect('/admin/definicoes.php?t=financeiro#set-fin_irs');
    }
    if (isset($_POST['google_client'])) {   // o cliente OAuth da Google (§31): testado no formato e gravado só no servidor
        require_once __DIR__ . '/inc/secrets.php';
        try {
            google_client_save((string)($_POST['client_id'] ?? ''), (string)($_POST['client_secret'] ?? ''));
            setting_log($pdo, 'int_google', 'Ligação à Google', null, 'cliente OAuth guardado no servidor');   // nunca o segredo
            flash('Cliente OAuth da Google guardado no servidor. Experimente «Entrar com Google» e «Ligar Google Calendar» no Perfil.');
        } catch (RuntimeException $e) { flash($e->getMessage(), 'err'); }
        redirect('/admin/definicoes.php?t=integracoes#set-int_google');
    }
    redirect('/admin/definicoes.php?t=orcamentacao#set-budget_templates');
}

$tab = (string)($_GET['t'] ?? 'geral');
if (!isset(SET_TABS[$tab]) && $tab !== 'registo') $tab = 'geral';

/** ⓘ com a frase da definição. */
function set_ii(string $help): string {
    return '<span class="ii" tabindex="0" role="img" aria-label="Ajuda: ' . e($help) . '" data-tip="' . e($help) . '">i</span>';
}

/** Uma linha: nome e ⓘ · o campo · «Repor (valor de origem)». */
function set_row_html(PDO $pdo, string $key, array $d): string {
    $id = 'set-' . $key;
    $label = '<span class="set-l"><label for="in-' . e($key) . '">' . e($d['label']) . '</label>' . set_ii($d['help']) . '</span>';
    $v = setting_get($pdo, $key);
    $def = (string)($d['default'] ?? '');
    $unit = (string)($d['unit'] ?? '');
    $attrs = ' id="in-' . e($key) . '" data-set="' . e($key) . '" data-was="' . e($v) . '"';
    $field = '';
    switch ($d['type']) {
        case 'int': case 'num':
            $shown = $d['type'] === 'num' ? str_replace('.', ',', $v) : $v;
            $field = '<span class="set-in"><input type="text" inputmode="decimal" value="' . e($shown) . '"' . $attrs . ' autocomplete="off">' . ($unit !== '' ? '<em>' . e($unit) . '</em>' : '') . '</span>';
            break;
        case 'text': case 'email': case 'url':
            $field = '<input type="' . ($d['type'] === 'email' ? 'email' : ($d['type'] === 'url' ? 'url' : 'text')) . '" maxlength="' . (int)($d['max'] ?? 200) . '" value="' . e($v) . '"' . $attrs . ' autocomplete="off">';
            break;
        case 'time':
            $field = '<input type="time" step="900" value="' . e($v) . '"' . $attrs . '>';
            break;
        case 'textarea': case 'list':
            $rows = $d['type'] === 'list' ? min(12, max(3, substr_count($v, "\n") + 2)) : 4;
            $field = '<textarea rows="' . $rows . '"' . ($d['type'] === 'list' ? ' placeholder="Uma por linha"' : '') . ' maxlength="' . (int)($d['max'] ?? 4000) . '"' . $attrs . '>' . e($v) . '</textarea>'
                   . ($d['type'] === 'list' ? '<small class="muted">Uma por linha. Grava ao sair do campo.</small>' : '');
            break;
        case 'select':
            $o = '';
            foreach ($d['opts'] as $k => $l) $o .= '<option value="' . e((string)$k) . '"' . ((string)$k === $v ? ' selected' : '') . '>' . e($l) . '</option>';
            $field = '<select' . $attrs . '>' . $o . '</select>';
            break;
        case 'link':
            $field = '<a class="btn ghost sm" href="' . e($d['href']) . '">' . icon('external') . ' ' . e($d['show']) . '</a>';
            break;
        default:
            $field = set_special_html($pdo, $key, $d);
    }
    $editable = in_array($d['type'], ['int', 'num', 'text', 'email', 'url', 'time', 'textarea', 'list', 'select'], true);
    $reset = $editable ? '<button type="button" class="set-reset" data-reset="' . e($key) . '" data-def="' . e($def) . '"' . ($v === $def ? ' hidden' : '') . ' data-tip="Repor o valor de origem">Repor (' . e(setting_show($d, $def)) . ')</button>' : '';
    $wide = in_array($d['type'], ['textarea', 'list', 'services', 'pillars', 'templates', 'types', 'origins', 'ai', 'cc_ai', 'cc_funnels', 'rt', 'tokens', 'fin_params', 'fin_irs', 'fin_mail', 'fin_cat_types', 'mail_cf', 'google_int'], true);
    return '<div class="set-row' . ($wide ? ' wide' : '') . '" id="' . $id . '" data-row="' . e($key) . '">' . $label . '<div class="set-f">' . $field . '</div>' . $reset
         . '<div class="set-warn" hidden role="alert"><p></p><div class="actions"><button type="button" class="btn sm" data-impact-ok>Confirmar</button><button type="button" class="btn ghost sm" data-impact-no>Cancelar</button></div></div></div>';
}

/** Os campos que não são um valor só: tabelas, listas de tipos, estado da IA. */
function set_special_html(PDO $pdo, string $key, array $d): string {
    switch ($d['type']) {
        case 'fin_params':
            // Financeiro (§28): cada parâmetro com valor, fonte legal, ano e data da verificação (gravam ao sair do campo)
            if (!db_has_table($pdo, 'fin_params')) return '<span class="set-info">Disponível depois do passo 30 da base de dados.</span>';
            require_once __DIR__ . '/inc/fin.php';
            $h = '<div class="tablewrap"><table class="set-t fin-params"><thead><tr><th>Parâmetro</th><th class="num">Valor</th><th>Fonte legal</th><th class="hide-sm">Ano</th><th class="hide-sm">Verificado em</th></tr></thead><tbody>';
            $grp = '';
            foreach (fin_params($pdo, true) as $k => $p) {
                if ($p['grp'] !== $grp) { $grp = $p['grp']; $h .= '<tr class="grp"><th colspan="5">' . e($grp) . '</th></tr>'; }
                $text = $p['unit'] === '' || in_array($k, ['ta_comb', 'ta_phev', 'ta_ev', 'cal_annual', 'vat_exempt_note'], true);
                $v = $text ? (string)$p['value'] : str_replace('.', ',', (string)$p['value']);
                $h .= '<tr><td>' . e($p['label']) . ((int)$p['admin_only'] ? ' <span class="chip" data-tip="Só superadmin e admins (com registo)">admins</span>' : '') . '</td>'
                    . '<td class="v' . ($text ? ' wide' : '') . '"><input type="text" value="' . e($v) . '" data-fparam="' . e($k) . '" aria-label="' . e($p['label']) . '"' . ($text ? ' style="max-width:none;text-align:left"' : ' inputmode="decimal"') . '>'
                    . ($p['unit'] !== '' ? ' <span class="muted small">' . e($p['unit']) . '</span>' : '') . '</td>'
                    . '<td><input type="text" value="' . e((string)$p['source']) . '" data-fparam="' . e($k) . '" data-ffield="source" maxlength="255" title="' . e((string)$p['source']) . '" aria-label="Fonte legal de ' . e($p['label']) . '"></td>'
                    . '<td class="hide-sm"><input type="number" min="2000" max="2100" value="' . e((string)$p['year']) . '" data-fparam="' . e($k) . '" data-ffield="year" style="width:5.5em" aria-label="Ano"></td>'
                    . '<td class="hide-sm' . ($p['verified_on'] ? '' : ' unv') . '"><input type="date" value="' . e((string)$p['verified_on']) . '" data-fparam="' . e($k) . '" data-ffield="verified_on" aria-label="Verificado em"' . ($p['verified_on'] ? '' : ' title="Por confirmar com o contabilista"') . '></td></tr>';
            }
            return $h . '</tbody></table></div><p class="small muted">Sem data de verificação = por confirmar. Nada disto está escrito no código: os cálculos leem estes valores.</p>';
        case 'fin_cat_types':
            // Dashboard Executivo (§30.2): onde cada categoria entra no resultado (grava ao mudar)
            if (!db_has_table($pdo, 'exec_months')) return '<span class="set-info">Disponível depois do passo 34 da base de dados.</span>';
            require_once __DIR__ . '/inc/exec.php';
            $h = '<div class="tablewrap"><table class="set-t" data-table="fin_cat_types"><thead><tr><th>Categoria</th><th>Tipo</th><th class="hide-sm">Entra em</th></tr></thead><tbody>';
            foreach (exec_cat_types($pdo) as $cat => $t) {
                $fixed = in_array($cat, ['Salários', EXEC_LOAN_CAT], true);
                $o = '';
                foreach (EXEC_TYPES as $k => $l) $o .= '<option value="' . $k . '"' . ($k === $t ? ' selected' : '') . '>' . e($l) . '</option>';
                $h .= '<tr data-id="' . e($cat) . '"><td>' . e($cat) . '</td><td>' . ($fixed ? '<span class="muted small">Automático</span>' : '<select data-cell="type" aria-label="Tipo de ' . e($cat) . '">' . $o . '</select>') . '</td>'
                    . '<td class="hide-sm small muted">' . e($fixed ? ($cat === 'Salários' ? 'Operacionais (o tempo declarado na Orçamentação passa a custo direto)' : 'Só o juro conta (Net Profit); o capital não é custo') : EXEC_TYPES_HELP[$t]) . '</td></tr>';
            }
            return $h . '</tbody></table></div>';
        case 'fin_irs':
            if (!db_has_table($pdo, 'fin_irs')) return '<span class="set-info">Disponível depois do passo 30 da base de dados.</span>';
            require_once __DIR__ . '/inc/fin_pay.php';
            $years = fin_irs_years($pdo);
            $use = (int)fin_p($pdo, 'irs_table_year', (float)date('Y'));
            $h = '<p class="small">Em uso: <b>' . $use . '</b> (continente)' . ($years ? ' · carregadas: ' . e(implode(', ', $years)) : '') . '. Açores e Madeira ainda sem tabelas (a retenção usa as do continente, com aviso).</p>';
            $h .= '<details><summary class="small">Ver as tabelas de ' . $use . '</summary>';
            foreach (['I', 'II', 'III', 'IV', 'V', 'VI', 'VII'] as $t) {
                $rows = fin_irs_rows($pdo, $use, $t);
                if (!$rows) continue;
                $h .= '<h4 class="small">Tabela ' . $t . '</h4><div class="tablewrap"><table class="set-t"><thead><tr><th>Remuneração até</th><th class="num">Taxa</th><th>Parcela a abater</th><th class="num">Por dependente</th></tr></thead><tbody>';
                foreach ($rows as $r) {
                    $h .= '<tr><td>' . ($r['upto'] === null ? 'Superior' : eur((float)$r['upto'])) . '</td><td class="num">' . e(fin_num((float)$r['rate'])) . '%</td>'
                        . '<td>' . ($r['deduct_k'] !== null ? e(fin_num((float)$r['rate'])) . '% × ' . e(fin_num((float)$r['deduct_k'])) . ' × (' . e(fin_num((float)$r['deduct_base'])) . ' − R)' : eur((float)$r['deduct'])) . '</td>'
                        . '<td class="num">' . eur((float)$r['dep_deduct']) . '</td></tr>';
                }
                $h .= '</tbody></table></div>';
            }
            $h .= '</details><details><summary class="small">Importar tabelas novas</summary>'
                . '<form method="post" class="fin-form">' . csrf_field() . '<input type="hidden" name="irs_import" value="1">'
                . '<div class="grid"><div class="field"><label>Ano</label><input type="number" name="year" min="2020" max="2100" value="' . ((int)date('Y') + 1) . '" required></div>'
                . '<div class="field"><label>Região</label><select name="region"><option value="continente">Continente</option><option value="acores">Açores</option><option value="madeira">Madeira</option></select></div>'
                . '<div class="field"><label>Fonte (despacho)</label><input type="text" name="source" maxlength="190" required placeholder="Despacho n.º …"></div>'
                . '<div class="field wide"><label>Linhas: tabela;até;taxa;parcela;k;base;por dependente (até vazio = superior; k e base só nas linhas com fórmula)</label>'
                . '<textarea name="rows" rows="8" required placeholder="I;920;0;0;;;0&#10;I;1042;12,5;0;2,6;1273,85;21,43&#10;I;;47,17;1272,31;;;21,43"></textarea></div></div>'
                . '<div class="actions"><button class="btn sm" type="submit">Importar</button></div></form>'
                . '<p class="small muted">Substitui as tabelas desse ano e região que vierem no texto. Para as usar, mude «Ano das tabelas em uso» nos parâmetros.</p></details>';
            return $h;
        case 'google_int':
            require_once __DIR__ . '/inc/gcal.php';
            $gcl = google_client();
            $top = gcal_topper();
            $nConn = gcal_ready($pdo) ? (int)$pdo->query('SELECT COUNT(*) FROM user_google')->fetchColumn() : 0;
            $nSub = db_has_column($pdo, 'users', 'google_sub') ? (int)$pdo->query('SELECT COUNT(*) FROM users WHERE google_sub IS NOT NULL')->fetchColumn() : 0;
            $chip = fn(bool $b, string $y, string $n) => '<span class="chip ' . ($b ? 'ok' : 'wait') . '">' . e($b ? $y : $n) . '</span>';
            $h = '<p>' . $chip((bool)$gcl, 'Cliente OAuth ligado', 'Cliente OAuth por ligar') . ' ' . $chip((bool)$top, 'Conta da topper. ligada (Meet)', 'Conta da topper. por ligar')
               . ' <span class="small muted">' . $nConn . ($nConn === 1 ? ' pessoa com o calendário ligado' : ' pessoas com o calendário ligado') . ' · ' . $nSub . ' já entraram com Google</span></p>';
            $h .= '<ol class="cf-steps"><li>Em <b>console.cloud.google.com</b>: crie um projeto (ex.: «topper.»), ative a <b>Google Calendar API</b> (APIs e serviços › Biblioteca).</li>'
                . '<li><b>Ecrã de consentimento OAuth</b>: tipo «Externo», nome «topper.», domínio autorizado <code>' . e(parse_url(app_base_url(), PHP_URL_HOST) ?: 'topperhub.xyz') . '</code>, scopes <code>openid</code>, <code>email</code>, <code>calendar.events</code> e <code>calendar.freebusy</code>; publique a app (ou junte cada pessoa como utilizador de teste).</li>'
                . '<li><b>Credenciais › Criar ID de cliente OAuth › Aplicação Web</b>, com o URI de redirecionamento autorizado:<pre class="cf-cmd">' . e(google_redirect_uri()) . '</pre></li>'
                . '<li>Cole aqui o ID e o segredo do cliente:</li></ol>'
                . '<form method="post" class="fin-pass cf-api" autocomplete="off">' . csrf_field() . '<input type="hidden" name="google_client" value="1">'
                . '<input type="text" name="client_id" maxlength="200" required placeholder="' . ($gcl ? e(substr($gcl['client_id'], 0, 12)) . '… (guardado)' : '…apps.googleusercontent.com') . '" aria-label="ID de cliente">'
                . '<input type="password" name="client_secret" maxlength="200" required autocomplete="new-password" placeholder="' . ($gcl ? '•••••••• guardado no servidor' : 'Segredo do cliente') . '" aria-label="Segredo do cliente">'
                . '<button class="btn sm" type="submit">' . ($gcl ? 'Mudar' : 'Guardar') . '</button></form>'
                . '<p class="small muted">Fica só no servidor (admin/inc/auth_config.php), nunca na base de dados nem nesta página. O mesmo cliente serve para entrar, para o calendário de cada pessoa e para o Meet.'
                . ($top ? '' : ' Para criar Meet também por quem não liga o calendário, a conta da topper. continua em admin/inc/meet_config.php (o refresh_token dessa conta).') . '</p>';
            return $h;
        case 'mail_cf':
            require_once __DIR__ . '/inc/mail_inbound.php';
            $g = mail_inbound_guide($pdo);
            $ok = fn(bool $b, string $yes, string $no) => '<span class="chip ' . ($b ? 'ok' : 'wait') . '">' . e($b ? $yes : $no) . '</span>';
            $agoT = function (string $t): string {
                if ($t === '') return '';
                $m = max(0, (int)round((time() - strtotime($t)) / 60));
                return $m < 1 ? 'agora mesmo' : 'há ' . ($m < 60 ? $m . ' min' : ($m < 2880 ? round($m / 60) . ' h' : round($m / 1440) . ' dias'));
            };
            $boxes = fin_mail_boxes_safe($pdo);
            $h = '<ol class="cf-steps">';
            $h .= '<li><b>O domínio ' . e($g['domain']) . ' na Cloudflare.</b> ' . ($g['mx'] ? $ok($g['mx_ok'], 'MX da Cloudflare', 'MX ainda não é da Cloudflare') . ' <span class="muted small">' . e(implode(', ', $g['mx'])) . '</span>' : '<span class="muted small">Ative o Email Routing e confirme aqui que os MX passam a ser *.mx.cloudflare.net.</span>')
                . '<div class="small muted">Cloudflare › ' . e($g['domain']) . ' › DNS: os servidores de nomes do domínio têm de ser os da Cloudflare.</div></li>';
            $h .= '<li><b>Email Routing.</b> Cloudflare › ' . e($g['domain']) . ' › Email › Email Routing › <i>Enable</i>. Em <i>Routing rules</i> › <i>Custom address</i>: <code>' . e($g['address']) . '</code> com a ação <i>Send to a Worker</i> › <code>topper-mail</code>.</li>';
            $h .= '<li><b>Publicar o Worker</b> (uma vez; na mesma conta do tempo real):<pre class="cf-cmd">cd mail &amp;&amp; npm ci &amp;&amp; npx wrangler deploy' . "\n" . 'npx wrangler secret put INBOUND_SECRET</pre>'
                . '<div class="cf-secret">' . ($g['secret'] ? $ok(true, 'Segredo gerado', '') . ' <button type="button" class="btn ghost sm" data-mail-secret data-again="1">Gerar outro</button> <span class="muted small">(o anterior deixa de valer: volte a pôr o novo no Worker)</span>'
                                                         : '<button type="button" class="btn sm" data-mail-secret>Gerar o segredo</button> <span class="muted small">É o que o comando <code>secret put</code> pede. Aparece uma vez.</span>')
                . '<div class="cf-secret-out" hidden><code></code> <button type="button" class="btn ghost sm" data-copy-secret>Copiar</button></div></div>'
                . '<div class="small">' . ($g['last'] !== '' ? $ok(true, 'A funcionar', '') . ' último email recebido ' . e($agoT($g['last'])) : '<span class="muted">Ainda não chegou nenhum email pela Cloudflare.</span>') . '</div></li>';
            $h .= '<li><b>Em cada caixa ligada do Hostinger:</b> hPanel › Emails › a caixa › <i>Reencaminhamento</i> › para <code>' . e($g['address']) . '</code>, com <i>manter uma cópia</i> na caixa. '
                . ($boxes ? '<span class="small">Caixas ligadas: ' . e(implode(', ', $boxes)) . '.</span>' : '<span class="small neg">Ainda não há caixas ligadas (campo «Caixas ligadas», abaixo).</span>') . '</li>';
            $h .= '<li><b>Testar:</b> em «Estado das caixas», abaixo, «Testar» manda um email com um código para a caixa e mostra-o a chegar.</li>';
            $h .= '</ol>';
            // envio pelo serviço transacional
            $api = $g['api'];
            $po = '';
            foreach (MAILER_PROVIDERS as $k => $l) $po .= '<option value="' . $k . '"' . (($api['provider'] ?? 'resend') === $k ? ' selected' : '') . '>' . e($l) . '</option>';
            $h .= '<div class="cf-send"><h4>Serviço de envio</h4>'
                . '<p class="small muted">Crie uma conta no Resend (resend.com) ou no Brevo (brevo.com), verifique o domínio topperdigital.xyz (registos DNS que o serviço indica) e crie uma chave API. A chave é confirmada no serviço e fica só no servidor.</p>'
                . '<form class="fin-pass cf-api" data-mail-api autocomplete="off"><select name="provider" aria-label="Serviço">' . $po . '</select>'
                . '<input type="password" name="key" autocomplete="new-password" maxlength="300" required placeholder="' . ($api ? '•••••••• guardada no servidor' : 'A chave API') . '" aria-label="Chave API">'
                . '<button class="btn sm" type="submit">' . ($api ? 'Mudar' : 'Guardar') . '</button>'
                . ($api ? '<button class="btn ghost sm" type="button" data-mail-api-del>Tirar</button>' : '') . '</form>'
                . '<p class="small">' . ($api ? $ok(true, MAILER_PROVIDERS[$api['provider']] . ' ligado', '') : $ok(false, '', 'Sem serviço de envio')) . ' '
                . ($g['send'] === 'smtp' ? '<span class="muted">Em uso: SMTP do Hostinger («Como saem os emails»).</span>' : '')
                . ' <button type="button" class="btn ghost sm" data-mail-send-test>Enviar um email de teste para mim</button></p>'
                . '<p class="small cf-out" aria-live="polite" hidden></p></div>';
            return $h;
        case 'fin_mail':
            require_once __DIR__ . '/inc/fin_mail.php';
            $s = fin_mail_status($pdo);
            $cf = $s['mode'] === 'cloudflare';
            $needPass = !$cf || $s['send'] === 'smtp';   // palavras-passe só no modo IMAP ou no envio por SMTP
            $ago = function (string $t, string $none = 'ainda não lida', string $verb = 'lida'): string {
                if ($t === '') return $none;
                $m = max(0, (int)round((time() - strtotime($t)) / 60));
                return $m < 1 ? $verb . ' agora mesmo' : $verb . ' há ' . ($m < 60 ? $m . ' min' : ($m < 2880 ? round($m / 60) . ' h' : round($m / 1440) . ' dias'));
            };
            $h = '<div class="tablewrap"><table class="set-t fin-boxes"><thead><tr><th>Caixa</th><th>' . ($cf ? 'Último email' : 'Estado') . '</th><th class="hide-sm">' . ($cf ? 'Pela Cloudflare' : 'Lidas') . '</th><th></th></tr></thead><tbody>';
            foreach ($s['boxes'] as $b) {
                $role = ($b['read'] ? ($cf ? 'ligada' : 'lida') : '') . ($b['read'] && $b['general'] ? ' · ' : '') . ($b['general'] ? 'email geral (respostas)' : '');
                if ($cf) {
                    $chip = !$b['read'] ? '<span class="chip info">não ligada</span>' : '<span class="chip ' . ($b['last_in'] !== '' ? 'ok' : 'wait') . '">' . e($ago($b['last_in'], 'ainda nenhum', 'recebido')) . '</span>';
                    $mid = $b['read'] ? ($b['last_in'] !== '' ? e(date('d/m H:i', strtotime($b['last_in']))) : '<span class="muted">confirme o reencaminhamento</span>') : '—';
                    $btn = $b['read'] ? '<button type="button" class="btn ghost sm" data-fin-mail-test="' . e($b['addr']) . '">Testar</button>' : '';
                } else {
                    $chip = !$b['pass'] ? '<span class="chip wait">sem palavra-passe</span>'
                          : ($b['error'] !== '' ? '<span class="chip no">erro</span>' : (!$b['read'] ? '<span class="chip ok">pronta a enviar</span>' : '<span class="chip ' . ($b['last'] !== '' ? 'ok' : 'info') . '">' . e($ago($b['last'])) . '</span>'));
                    $mid = $b['read'] && $b['last'] !== '' ? $b['seen'] . ' vistas · ' . $b['docs'] . ' com documento' . ($b['uid'] ? ' · UID ' . $b['uid'] : '') : '—';
                    $btn = $b['pass'] ? '<button type="button" class="btn ghost sm" data-fin-mail-test="' . e($b['addr']) . '">Testar</button>' : '';
                }
                $h .= '<tr><td class="b">' . e($b['addr']) . '<div class="muted small">' . e($role) . '</div></td>'
                    . '<td>' . $chip . (!$cf && $b['error'] !== '' ? '<div class="small neg">' . e($b['error']) . '</div>' : '') . '</td>'
                    . '<td class="hide-sm small">' . $mid . '</td>'
                    . '<td class="num nowrap">' . $btn . '</td></tr>';
                if ($needPass) {
                    $h .= '<tr class="fin-box-pass"><td colspan="4"><form class="fin-pass" data-fin-mail-pass="' . e($b['addr']) . '" autocomplete="off"><label class="small muted" for="fp-' . md5($b['addr']) . '">Palavra-passe' . ($cf ? ' (só para o envio por SMTP)' : '') . '</label>'
                        . '<input type="password" id="fp-' . md5($b['addr']) . '" name="pass" autocomplete="new-password" maxlength="200" required placeholder="' . ($b['pass'] ? '•••••••• guardada no servidor' : 'A palavra-passe desta caixa') . '">'
                        . '<button class="btn sm" type="submit">' . ($b['pass'] ? 'Mudar' : 'Guardar') . '</button>'
                        . ($b['pass'] ? '<button class="btn ghost sm" type="button" data-fin-mail-pass-del="' . e($b['addr']) . '">Tirar</button>' : '') . '</form></td></tr>';
                }
                $h .= '<tr class="fin-box-out" hidden><td colspan="4" class="small" aria-live="polite"></td></tr>';
            }
            $h .= '</tbody></table></div>';
            $h .= $needPass ? '<p class="small muted">A palavra-passe é testada e, se estiver certa, fica só no servidor, no ficheiro admin/inc/fin_mail_config.php: nunca na base de dados, no registo nem nesta página.' . (!$cf ? ' Cada pessoa põe a do seu próprio email no Perfil › Email de envio.' : '') . '</p>'
                            : '<p class="small muted">Sem palavras-passe: cada caixa ligada reencaminha uma cópia; os emails de caixas que não estão ligadas são ignorados e o corpo dos emails nunca é guardado.</p>';
            $h .= '<p class="small">' . ($s['pending'] ? '<a class="chip info" href="/admin/financeiro.php?tab=receber">' . $s['pending'] . ' por confirmar</a> ' : '')
                . 'Contabilista: ' . ($s['accountant'] !== '' ? e($s['accountant']) : '<span class="muted">por indicar em Definições › Financeiro</span>') . '</p>';
            if ($s['company_nif'] === '') $h .= '<p class="small neg">Falta o NIF da empresa em Definições › Geral: sem ele não sei se um documento é despesa (o nosso NIF como adquirente) ou receita (como emitente), e fica tudo por confirmar.</p>';
            return $h;
        case 'info':
            $show = (string)$d['show'];
            if ($key === 'mkt_templates') { require_once __DIR__ . '/inc/marketing.php'; $show = implode(' · ', MKT_FORMATS); }
            if ($key === 'int_meet') { require_once __DIR__ . '/inc/gcal.php'; $show = gcal_topper() ? 'Conta da topper. ligada: cria o Meet quando quem marca não tem o Google Calendar ligado' : 'Conta da topper. por ligar: o Meet só é criado por quem tem o Google Calendar ligado; senão, link colado à mão'; }
            return '<span class="set-info">' . e($show) . '</span>';
        case 'services':
            $rows = services_list($pdo, false);
            $used = $pdo->query('SELECT service_id, COUNT(DISTINCT contact_id) FROM contact_services WHERE ended_at IS NULL AND service_id IS NOT NULL GROUP BY service_id')->fetchAll(PDO::FETCH_KEY_PAIR);
            $h = '<div class="tablewrap"><table class="set-t" data-table="services"><thead><tr><th>Serviço</th><th class="num">Ordem</th><th>Ativo</th><th class="num hide-sm">Em uso</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $h .= '<tr data-id="' . $id . '"><td><input type="text" maxlength="80" value="' . e($r['name']) . '" data-cell="name" aria-label="Nome do serviço"></td>'
                    . '<td class="num"><input type="number" min="0" max="999" value="' . (int)$r['position'] . '" data-cell="position" style="width:5.5em" aria-label="Ordem de ' . e($r['name']) . '"></td>'
                    . '<td><label class="check"><input type="checkbox" value="1" data-cell="is_active"' . ((int)$r['is_active'] ? ' checked' : '') . '> Ativo</label></td>'
                    . '<td class="num hide-sm">' . ((int)($used[$id] ?? 0) ?: '<span class="muted">—</span>') . '</td></tr>';
            }
            return $h . '<tr data-id="0"><td colspan="4"><input type="text" maxlength="80" placeholder="+ Novo serviço (ex.: Motion Design) e Enter" data-cell="name" aria-label="Novo serviço"></td></tr></tbody></table></div>';
        case 'pillars':
            if (!db_has_table($pdo, 'mkt_pillars')) return '<span class="set-info">Disponível depois do passo 18 da base de dados.</span>';
            require_once __DIR__ . '/inc/marketing.php';
            $h = '<div class="tablewrap"><table class="set-t" data-table="mkt_pillars"><thead><tr><th>Pilar</th><th class="hide-sm">Nome curto</th><th>Cor</th><th class="num">Ordem</th><th>Ativo</th><th class="hide-sm">Talento</th></tr></thead><tbody>';
            foreach (mkt_pillars($pdo, false) as $pl) {
                $id = (int)$pl['id'];
                $col = '';
                foreach (MKT_PILLAR_COLORS as $ck => $cl) $col .= '<option value="' . $ck . '"' . ($pl['color'] === $ck ? ' selected' : '') . '>' . e($cl) . '</option>';
                $h .= '<tr data-id="' . $id . '"><td><input type="text" maxlength="80" value="' . e($pl['name']) . '" data-cell="name" aria-label="Nome do pilar">'
                    . '<input type="text" maxlength="500" value="' . e($pl['description']) . '" placeholder="Descrição (a IA usa-a)" data-cell="description" aria-label="Descrição de ' . e($pl['name']) . '" class="sub-in"></td>'
                    . '<td class="hide-sm"><input type="text" maxlength="24" value="' . e($pl['short_name']) . '" data-cell="short_name" style="width:9em" aria-label="Nome curto"></td>'
                    . '<td><span class="mpil p-' . e($pl['color']) . '" aria-hidden="true">Aa</span> <select data-cell="color" aria-label="Cor de ' . e($pl['name']) . '">' . $col . '</select></td>'
                    . '<td class="num"><input type="number" min="0" max="999" value="' . (int)$pl['position'] . '" data-cell="position" style="width:5em" aria-label="Ordem"></td>'
                    . '<td><label class="check"><input type="checkbox" value="1" data-cell="is_active"' . ((int)$pl['is_active'] ? ' checked' : '') . '> Ativo</label></td>'
                    . '<td class="hide-sm"><label class="check"><input type="checkbox" value="employer" data-cell="kind"' . ($pl['kind'] === 'employer' ? ' checked' : '') . '> Talento</label></td></tr>';
            }
            return $h . '<tr data-id="0"><td colspan="6"><input type="text" maxlength="80" placeholder="+ Novo pilar e Enter" data-cell="name" aria-label="Novo pilar"></td></tr></tbody></table></div>';
        case 'templates':
            require_once __DIR__ . '/inc/budget.php';
            $tpls = budget_templates($pdo);
            if (!$tpls) return '<span class="set-info">Ainda não há modelos. Numa folha, «Guardar como modelo» guarda as linhas e a duração (sem preços).</span>';
            $h = '<ul class="set-list">';
            foreach ($tpls as $t) {
                $h .= '<li><b>' . e($t['name']) . '</b> <span class="muted small">' . e($t['author'] ?? '—') . ' · ' . e(date('d/m/Y', strtotime((string)$t['created_at']))) . '</span>'
                    . '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="tpl_del" value="' . (int)$t['id'] . '">'
                    . icon_button('x', 'Apagar o modelo «' . $t['name'] . '»', ['type' => 'submit', 'class' => 'ibtn del']) . '</form></li>';
            }
            return $h . '</ul>';
        case 'types':
            $h = '<div class="set-chips">';
            foreach (TASK_TYPES as $k => [$l, $i]) $h .= '<span class="set-chip ty-' . $k . '"><i>' . icon($i) . '</i>' . e($l) . '</span>';
            return $h . '</div>';
        case 'origins':
            $h = '<div class="set-chips">';
            foreach (TASK_ORIGINS as $k => [$l, $i]) $h .= '<span class="set-chip or-' . $k . '"><i>' . icon($i) . '</i>' . e($l) . '</span>';
            return $h . '</div>';
        case 'cc_funnels':
            if (!db_has_column($pdo, 'funnels', 'cc_pre_mode')) return '<span class="set-info">Disponível depois do passo 24 da base de dados.</span>';
            require_once __DIR__ . '/inc/cc.php';
            $modes = ['recommended' => 'Recomendado', 'required' => 'Obrigatório', 'off' => 'Desligado'];
            $h = '<div class="tablewrap"><table class="set-t" data-table="funnels"><thead><tr><th>Funil</th><th>Pré-criativo</th><th>Sub-quadros do projeto (Kickoff)</th></tr></thead><tbody>';
            foreach (funnels_active($pdo) as $f) {
                $o = '';
                foreach ($modes as $k => $l) $o .= '<option value="' . $k . '"' . (($f['cc_pre_mode'] ?? 'recommended') === $k ? ' selected' : '') . '>' . $l . '</option>';
                $on = cc_funnel_stages($pdo, (int)$f['id']);
                $ck = '';
                foreach (CC_STAGES as $k => $l) $ck .= '<label class="check"><input type="checkbox" value="1" data-cell="cc_stage_' . $k . '"' . (in_array($k, $on, true) ? ' checked' : '') . '> ' . e($l) . '</label> ';
                $h .= '<tr data-id="' . (int)$f['id'] . '"><td><b>' . e($f['name']) . '</b></td><td><select data-cell="cc_pre_mode" aria-label="Pré-criativo em ' . e($f['name']) . '">' . $o . '</select></td><td class="set-checks">' . $ck . '</td></tr>';
            }
            return $h . '</tbody></table></div>';
        case 'cc_ai':
            if (!db_has_table($pdo, 'cc_ai_usage')) return '<span class="set-info">Disponível depois do passo 24 da base de dados.</span>';
            require_once __DIR__ . '/inc/cc_stt.php';
            $cfg = cc_ai_config();
            $emb = cc_embed_config();
            $stt = cc_stt_config();
            $sum = cc_ai_usage_summary($pdo);
            $usd = fn($v) => number_format((float)$v, 2, ',', ' ') . ' US$';
            $h = '<div class="set-ai"><span class="chip ' . ($cfg ? 'ok' : 'wait') . '">' . ($cfg ? 'Claude ligado · ' . e($cfg['model']) . ' · esforço ' . e($cfg['effort']) : 'Claude sem chave neste servidor') . '</span> '
               . '<span class="chip ' . ($emb ? 'ok' : 'wait') . '">' . ($emb ? 'Embeddings ligados · ' . e($emb['model']) : 'Embeddings por ligar (semelhança por etiquetas e paleta)') . '</span> '
               . '<span class="chip ' . ($stt ? 'ok' : 'wait') . '">' . ($stt ? 'Transcrição ligada · ' . e($stt['model']) . ' · ' . e(number_format($stt['usd_min'], 3, ',', ' ')) . ' US$/min' : 'Transcrição (áudio e vídeo): por ligar') . '</span></div>'
               . ($stt ? '<p class="small muted">A fala do áudio e dos vídeos passa a texto com o tempo de cada frase (pesquisável e clicável). O custo conta no mesmo limite mensal.'
                       . (cc_ffmpeg_ok($pdo) ? '' : ' <b>Sem ffmpeg no servidor:</b> só os áudios até 25 MB em MP3, M4A, WAV, OGG ou FLAC; os vídeos e os AIFF esperam pelo ffmpeg.') . '</p>'
                      : '<p class="small">Proposta: <b>OpenAI Whisper</b> (whisper-1, cerca de 0,006 US$ por minuto, com marcas de tempo por frase), ou o <b>Groq</b> (whisper-large-v3, mais barato, a mesma API). '
                       . '<span class="muted">Para ligar: criar a chave na conta do serviço e pô-la no servidor, em admin/inc/cc_config.php (transcribe_key; o modelo, o endereço e o preço por minuto ao lado, como no cc_config.example.php). Nunca no código nem nesta página.</span></p>')
               . '<p><span class="mono">' . e($usd($sum['spend'])) . '</span> <span class="muted small">este mês · limite ' . e(number_format($sum['cap'], 0, ',', ' ')) . ' € (contado como US$)'
               . ($cfg ? ' · fallbacks do servidor ligados' . ($cfg['auto'] ? ' · análise automática de cada referência nova' : ' · análise só a pedido') : '') . '</span></p>'
               . '<p class="small muted">' . $sum['analyzed'] . ' referências analisadas · ' . $sum['embedded'] . ' com embedding · ' . $sum['queued'] . ' na fila' . ($sum['errors'] ? ' · <b>' . $sum['errors'] . ($sum['errors'] === 1 ? ' erro' : ' erros') . ' nos últimos 7 dias</b>' : '') . '</p>';
            if ($sum['rows']) {
                $h .= '<div class="tablewrap"><table class="set-t"><thead><tr><th>Uso</th><th>Modelo</th><th class="num">Pedidos</th><th class="num hide-sm">Tokens (entrada / saída)</th><th class="num">Custo</th></tr></thead><tbody>';
                foreach ($sum['rows'] as $r) {
                    $h .= '<tr><td>' . e(CC_AI_PURPOSES[$r['purpose']] ?? $r['purpose']) . '</td><td class="mono small">' . e($r['model']) . '</td><td class="num">' . (int)$r['n'] . '</td>'
                        . '<td class="num hide-sm mono small">' . number_format((int)$r['tin'], 0, ',', ' ') . ' / ' . number_format((int)$r['tout'], 0, ',', ' ') . '</td><td class="num mono">' . e($usd($r['usd'])) . '</td></tr>';
                }
                $h .= '</tbody></table></div>';
            }
            return $h;
        case 'rt':
            require_once __DIR__ . '/inc/cc_rt.php';
            if (!cc_rt_ready($pdo)) return '<span class="set-info">Disponível depois do passo 28 da base de dados.</span>';
            $url = cc_rt_url($pdo);
            $u = cc_rt_usage_today($pdo);
            $keys = cc_rt_keys($pdo);
            $on = $url !== '' && setting($pdo, 'rt_enabled', '1') !== '0';
            $lvl = $u['pct'] >= 100 ? 'bad' : ($u['pct'] >= 80 ? 'warn' : 'ok');
            $h = '<div class="set-ai"><span class="chip ' . ($on ? 'ok' : 'wait') . '">' . ($on ? 'Ligado · ' . e(preg_replace('~^https://~', '', $url)) : ($url === '' ? 'Por publicar: os quadros atualizam a cada 8 s' : 'Desligado')) . '</span>'
               . ' <span class="chip">Plano gratuito · 0 €</span></div>'
               . '<div class="rt-meter ' . $lvl . '" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . min(100, $u['pct']) . '" aria-label="Uso do dia">'
               . '<i style="width:' . min(100, $u['pct']) . '%"></i></div>'
               . '<p class="small"><b>' . $u['pct'] . '%</b> do plano gratuito hoje <span class="muted">· ' . number_format($u['requests'], 0, ',', ' ') . ' de 100 000 pedidos · '
               . number_format($u['gbs'], 0, ',', ' ') . ' de 13 000 GB-s · ' . $u['boards'] . ($u['boards'] === 1 ? ' quadro' : ' quadros') . ' · repõe às 00:00 UTC (estimativa contada pelos browsers)</span></p>'
               . ($u['pct'] >= 80 ? '<p class="small"><b>' . ($u['pct'] >= 100 ? 'Limite atingido: o tempo real está em pausa até às 00:00 UTC; os quadros continuam e sincronizam depois.' : 'Acima de 80% do limite diário.') . '</b> <span class="muted">Passar ao plano pago (5 US$/mês) é uma decisão manual, na conta Cloudflare da topper.</span></p>' : '');
            if ($keys) {
                $cmd = 'cd rt && npm ci && npx wrangler deploy';
                $h .= '<details class="rt-deploy"><summary>Publicar ou atualizar o serviço</summary>'
                    . '<p class="small muted">Na pasta do repositório, numa máquina com sessão iniciada na conta Cloudflare da topper. (o wrangler pede-a no browser) ou com as variáveis CLOUDFLARE_API_TOKEN e CLOUDFLARE_ACCOUNT_ID. O serviço lê daqui a chave pública (<span class="mono">/admin/rt_key.php</span>, não é segredo) para confirmar os tokens que este servidor assina; a privada nunca sai do servidor.</p>'
                    . '<pre class="mono small rt-cmd" tabindex="0">' . e($cmd) . '</pre>'
                    . '<p class="small muted">No fim, o wrangler mostra o endereço (…workers.dev): cole-o em «Endereço do serviço».</p></details>';
            }
            return $h;
        case 'tokens':
            require_once __DIR__ . '/inc/cc_rt.php';
            if (!cc_rt_ready($pdo)) return '<span class="set-info">Disponível depois do passo 28 da base de dados.</span>';
            $rows = ut_list($pdo, null);
            if (!$rows) return '<span class="set-info">Ninguém criou ainda um token. Cada pessoa cria o seu em Perfil › Ligações.</span>';
            $h = '<div class="tablewrap"><table class="set-t"><thead><tr><th>Pessoa</th><th>Nome</th><th class="hide-sm">Último uso</th><th class="num hide-sm">Usos</th><th></th></tr></thead><tbody>';
            foreach ($rows as $t) {
                $h .= '<tr><td>' . e($t['who']) . '</td><td>' . e($t['name']) . ' <span class="mono muted small">…' . e($t['last4']) . '</span></td>'
                    . '<td class="hide-sm small">' . ($t['last_used_at'] ? e(lisbon_fmt(strtotime((string)$t['last_used_at']), 'd/m H:i')) : '<span class="muted">nunca</span>') . '</td>'
                    . '<td class="num hide-sm">' . (int)$t['uses'] . '</td>'
                    . '<td><form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="ut_revoke" value="' . (int)$t['id'] . '">'
                    . '<button type="submit" class="btn ghost sm" aria-label="Revogar o token «' . e($t['name']) . '» de ' . e($t['who']) . '">Revogar</button></form></td></tr>';
            }
            return $h . '</tbody></table></div>';
        case 'ai':
            require_once __DIR__ . '/inc/ai.php';
            [$ma, $mb, $mLabel] = ai_month_bounds();
            $spent = ai_month_spend_usd($pdo);
            $usd = fn($v) => number_format((float)$v, 4, ',', ' ') . ' US$';
            $log = db_has_table($pdo, 'mkt_ai_log') ? $pdo->query('SELECT l.*, u.name AS who FROM mkt_ai_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 8')->fetchAll() : [];
            $h = '<div class="set-ai"><span class="chip ' . (ai_config() ? 'ok' : 'wait') . '">' . (ai_config() ? 'Ligada' : 'Sem chave neste servidor') . '</span>'
               . ' <span class="mono">' . e($usd($spent)) . '</span> <span class="muted small">em ' . e($mLabel) . ' · limite ' . e(ai_eur(ai_cap_usd())) . ' (contado como US$)</span></div>';
            if ($log) {
                $h .= '<ul class="set-list small">';
                foreach ($log as $l) $h .= '<li><span class="mono">' . e(lisbon_fmt(strtotime((string)$l['created_at']), 'd/m H:i')) . '</span> ' . e($l['who'] ?? '—') . ' · ' . e((string)$l['action']) . ' · <span class="mono">' . e($usd($l['cost_usd'])) . '</span></li>';
                $h .= '</ul>';
            }
            return $h;
    }
    return '';
}

render_header('Definições', 'definicoes', '', [$tab === 'registo' ? 'Registo de alterações' : SET_TABS[$tab][0]]);
render_flashes();
?>
<div class="page-head">
  <div><h1>Definições</h1>
    <p class="sub">Gravam sozinhas. Cada alteração tem «Anular» e fica no registo.</p></div>
</div>

<div class="set-wrap">
  <nav class="set-nav" aria-label="Separadores das Definições">
    <div class="set-search">
      <?= icon('search') ?><input type="search" placeholder="Procurar definição…" aria-label="Procurar definição" data-set-search autocomplete="off">
      <div class="set-res" data-set-res hidden role="listbox" aria-label="Definições encontradas"></div>
    </div>
    <?php foreach (SET_TABS as $k => [$l, $i]): ?>
      <a href="/admin/definicoes.php?t=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= icon($i) ?> <?= e($l) ?></a>
    <?php endforeach; ?>
    <span class="set-sep" aria-hidden="true"></span>
    <a href="/admin/definicoes.php?t=registo" class="<?= $tab === 'registo' ? 'on' : '' ?>"><?= icon('undo') ?> Registo de alterações</a>
    <a href="/admin/equipa.php?t=acessos" class="set-out"><?= icon('equipa') ?> <span>Utilizadores e permissões<small>na aba Equipa ↗</small></span></a>
  </nav>

  <section class="set-main" data-set-main>
  <?php if ($tab === 'registo'):
      $rows = settings_log_rows($pdo, 150); ?>
    <div class="set-blk"><h2 class="set-h">Registo de alterações</h2>
      <?php if (!$rows): ?><p class="muted">Ainda não há alterações.</p><?php endif; ?>
      <div class="set-log">
      <?php foreach ($rows as $r): $d = setting_def((string)$r['skey']); ?>
        <div class="set-lr">
          <span class="mono muted"><?= e(lisbon_fmt(strtotime((string)$r['created_at']), 'd/m H:i')) ?></span>
          <span><?= e(first_name((string)($r['name'] ?? 'Sistema'))) ?></span>
          <span><b><?= e($r['label']) ?></b> <span class="muted"><?= $d && $d['type'] === 'list' ? e(setting_list_diff((string)$r['old_value'], (string)$r['new_value'])) : ($d ? e(setting_show($d, (string)$r['old_value'])) . ' → ' . e(setting_show($d, (string)$r['new_value'])) : e(mb_strimwidth((string)$r['old_value'], 0, 40, '…')) . ($r['old_value'] !== '' && $r['new_value'] !== '' ? ' → ' : '') . e(mb_strimwidth((string)$r['new_value'], 0, 40, '…'))) ?></span></span>
          <?php if ($d && !str_ends_with((string)$r['label'], '(anulado)')): ?><button type="button" class="set-reset" data-restore="<?= (int)$r['id'] ?>" data-tip="Volta ao valor de antes desta alteração">Repor</button><?php else: ?><span></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  <?php else:
      $groups = [];
      foreach (settings_defs() as $k => $d) if ($d['tab'] === $tab) $groups[$d['group']][$k] = $d;
      $configure = ['pipeline' => '/admin/pipeline.php', 'leads' => '/admin/leads.php', 'contactos' => '/admin/contactos.php', 'orcamentacao' => '/admin/orcamentacao.php',
                    'negocios' => '/admin/negocios.php', 'criativo' => '/admin/criativo.php', 'tarefas' => '/admin/calendar.php', 'marketing' => '/admin/marketing.php', 'financeiro' => '/admin/financeiro.php', 'drive' => '/admin/drive.php'][$tab] ?? '';
      foreach ($groups as $g => $items): ?>
    <div class="set-blk">
      <h2 class="set-h"><?= e($g) ?></h2>
      <?php foreach ($items as $k => $d) echo set_row_html($pdo, $k, $d); ?>
    </div>
  <?php endforeach; ?>
    <?php if ($configure !== ''): ?><p class="muted small">Estas definições valem para <a href="<?= e($configure) ?>">a aba <?= e(SET_TABS[$tab][0]) ?></a> e para toda a equipa.</p><?php endif; ?>
  <?php endif; ?>
  </section>
</div>
<script type="application/json" id="set-index"><?= json_encode(settings_index(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= asset_url('settings.js') ?>" defer></script>
<?php if (in_array($tab, ['financeiro', 'integracoes'], true)): ?><script src="<?= asset_url('fin.js') ?>" defer></script><?php endif; ?>
<?php render_footer();
