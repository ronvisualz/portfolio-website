<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/paginate.php';
require_once __DIR__ . '/inc/finance.php';
require_once __DIR__ . '/inc/alerts.php';
require_once __DIR__ . '/inc/undo.php';
require_once __DIR__ . '/inc/billing.php';
require_once __DIR__ . '/inc/fin.php';
require_once __DIR__ . '/inc/fin_view.php';
require_once __DIR__ . '/inc/charts.php';
$user = require_login();
require_perm('financeiro');   // ver; cada ação pede o nível dela (calcular no simulador e o saldo do responsável não pedem «editar»)

// Financeiro (§28): Visão geral · A receber · A pagar · Custos fixos · Salários · Tesouraria · Simulador · Impostos.
// Acesso: superadmin, admins e quem tem a aba Financeiro (require_page acima; cada ação vê o nível de novo).
// A plataforma não emite faturas: regista as do programa certificado (ou do contabilista) com o PDF.

if (!fin_ready($pdo)) {
    render_header('Financeiro', 'financeiro');
    echo '<h1>Financeiro</h1><p class="muted">A base de dados ainda não está no passo 30. Abra o Dashboard uma vez para a atualizar.</p>';
    render_footer();
    exit;
}

const FIN_TABS = ['geral' => 'Visão geral', 'receber' => 'A receber', 'pagar' => 'A pagar', 'fixos' => 'Custos fixos', 'salarios' => 'Salários',
                  'tesouraria' => 'Tesouraria', 'simulador' => 'Simulador', 'impostos' => 'Impostos'];
// os separadores de antes (Negócios, Por receber, Fluxo de caixa, Custos, IVA) passaram para dentro destes
const FIN_TABS_OLD = ['negocios' => 'geral', 'fluxo' => 'tesouraria', 'custos' => 'fixos', 'iva' => 'impostos'];

$rate = vat_rate($pdo);
$years = finance_years($pdo);
$year = (int)($_GET['year'] ?? $_POST['year'] ?? $years[0]);
if (!in_array($year, $years, true)) $year = $years[0];
$tab = (string)($_GET['tab'] ?? 'geral');
$tab = FIN_TABS_OLD[$tab] ?? $tab;
if (!isset(FIN_TABS[$tab])) $tab = 'geral';
$canEdit = can('financeiro', PERM_EDIT);
$canManage = can('financeiro', PERM_MANAGE);
$uid = (int)$user['id'];

/** Para onde voltar depois de uma ação (só páginas da plataforma). */
function fin_back(string $def): string {
    $b = (string)($_POST['back'] ?? '');
    return preg_match('~^/admin/(financeiro|equipa|negocio|conta)\.php(\?[^#\s]*)?(#[A-Za-z0-9_-]*)?$~', $b) ? $b : $def;
}

/** O lançamento sem as colunas do negócio (para o «Anular»). */
function fin_snap(array $d): array {
    unset($d['deal_code'], $d['deal_name'], $d['deal_owner'], $d['left'], $d['overdue']);
    return $d;
}

// ── ações ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $back = fin_back(fin_url(['tab' => $tab]));
    if ($do === 'sim_calc') {   // calcular não grava nada: basta ver o Financeiro
        require_once __DIR__ . '/inc/fin_sim.php';
        $kind = (string)($_POST['kind'] ?? '');
        if (isset(FIN_SIM_KINDS[$kind])) $_SESSION['fin_sim'][$kind] = fin_sim_inputs($kind, $_POST);
        redirect(fin_url(['tab' => 'simulador', 'kind' => $kind]));
    }
    if ($do === 'acc_balance') {   // o saldo semanal: quem edita o Financeiro ou o responsável pela conta
        require_once __DIR__ . '/inc/fin_cash.php';
        try {
            $acc = fin_account($pdo, (int)($_POST['id'] ?? 0));
            if (!$acc) throw new RuntimeException('Essa conta já não existe.');
            if (!fin_can_balance($pdo, $acc, $user)) { http_response_code(403); exit('Sem permissão para esta conta.'); }
            $old = fin_balance_set($pdo, $acc, $_POST['balance'] ?? '', (string)($_POST['balance_date'] ?? ''), $uid);
            $log = (int)$pdo->query('SELECT MAX(id) FROM fin_balance_log WHERE account_id = ' . (int)$acc['id'])->fetchColumn();
            flash('Saldo de ' . $acc['name'] . ' atualizado.', 'ok', undo_push('fin', ['op' => 'balance', 'log' => $log,
                  'row' => array_intersect_key($old, array_flip(['id', 'balance', 'balance_date', 'balance_by', 'balance_at']))]));
        } catch (RuntimeException $e) { flash($e->getMessage(), 'err'); }
        redirect(fin_url(['tab' => 'tesouraria']));
    }
    require_perm('financeiro', PERM_EDIT);
    try {
        switch ($do) {
            case 'doc_save': {
                $id = (int)($_POST['id'] ?? 0);
                $old = $id ? fin_doc($pdo, $id) : null;
                if ($id && !$old) throw new RuntimeException('Esse lançamento já não existe.');
                $_SESSION['fin_form'] = array_diff_key($_POST, ['csrf' => 1]);
                $v = fin_doc_input($pdo, $_POST, $old);
                $file = fin_upload($_FILES['file'] ?? null);
                if (!$file && ($t = (string)($_POST['tmp'] ?? '')) !== '') $file = fin_tmp_take($t, $uid);
                if ($v['direction'] === 'in' && in_array($v['doc_type'], ['invoice', 'receipt'], true) && !$file && (!$old || $old['pdf_key'] === '') && ($old['status'] ?? '') !== 'requested') {
                    throw new RuntimeException('Junte o PDF da fatura emitida (o documento do programa de faturação ou do contabilista).');
                }
                if ($v['direction'] === 'out' && ($dup = fin_doc_dup($pdo, $v['party_nif'], $v['number'], 'out', $id))) {
                    throw new RuntimeException('Duplicado: já existe ' . fin_doc_label($dup) . ' de ' . $dup['party_name'] . ' com o mesmo NIF e número.');
                }
                if ($v['direction'] === 'out' && in_array($v['party_kind'], ['supplier', 'freelancer'], true) && $v['scope'] === 'deal' && !$v['deal_id']) throw new RuntimeException('Escolha o negócio.');
                if (!$v['contact_id'] && $v['party_nif'] !== '') {
                    $st = $pdo->prepare("SELECT id FROM contacts WHERE REPLACE(nif, ' ', '') = ? LIMIT 1");
                    $st->execute([$v['party_nif']]);
                    $v['contact_id'] = ((int)$st->fetchColumn()) ?: null;
                }
                if (!empty($_POST['wh_exempt_save']) && $v['party_kind'] === 'freelancer') {
                    $v['wh_rate'] = 0; $v['withholding'] = 0;
                    if ($v['contact_id']) $pdo->prepare('UPDATE contacts SET wh_exempt = 1 WHERE id = ?')->execute([$v['contact_id']]);
                }
                if ($file) {
                    [$key, $name] = fin_file_store($pdo, $file[0], fin_file_name($v, $v['direction'] === 'in' ? 'Fatura-emitida' : 'Fatura', $file[1]), $file[2], $v, $v['direction'] === 'in' ? 'invoice' : 'attach');
                    $v['pdf_key'] = $key; $v['pdf_name'] = $name;
                }
                if ($old) {
                    if ($old['status'] === 'review') { $v['status'] = 'to_pay'; $v['needs_review'] = 0; }
                    if ($old['status'] === 'requested' && $file) { $v['status'] = 'issued'; $v['ready_at'] = fin_now(); }
                    fin_doc_update($pdo, $id, $v);
                    if ($v['direction'] === 'in' && in_array((string)fin_doc($pdo, $id)['status'], ['issued', 'partial', 'paid', 'archived'], true)) fin_doc_issued($pdo, $id, $uid);
                    unset($_SESSION['fin_form']);
                    flash($old['status'] === 'review' ? 'Confirmado: passou a «Por pagar».' : 'Lançamento guardado.', 'ok', undo_push('fin', ['op' => 'doc', 'row' => fin_snap($old)]));
                } else {
                    $v['status'] = $v['direction'] === 'in' ? 'issued' : 'to_pay';
                    if ($v['direction'] === 'in') $v['ready_at'] = fin_now();
                    $id = fin_doc_create($pdo, $v, $uid);
                    if ($v['direction'] === 'in') fin_doc_issued($pdo, $id, $uid);
                    unset($_SESSION['fin_form']);
                    flash(($v['direction'] === 'in' ? 'Fatura lançada em «A receber»' : 'Lançado em «A pagar»') . ': ' . fin_doc_label($v + ['id' => $id, 'req_code' => '']) . '.', 'ok', undo_push('fin', ['op' => 'doc_new', 'id' => $id]));
                }
                redirect(fin_url(['tab' => $v['direction'] === 'in' ? 'receber' : 'pagar', 'doc' => $id]));
            }
            case 'doc_status': {
                $d = fin_doc($pdo, (int)($_POST['id'] ?? 0));
                if (!$d) throw new RuntimeException('Esse lançamento já não existe.');
                $msg = fin_doc_set_status($pdo, $d, (string)($_POST['to'] ?? ''), trim((string)($_POST['note'] ?? '')), $uid);
                flash($msg, 'ok', undo_push('fin', ['op' => 'doc', 'row' => fin_snap($d)]));
                break;
            }
            case 'doc_del': {
                require_perm('financeiro', PERM_MANAGE);
                $d = fin_doc($pdo, (int)($_POST['id'] ?? 0));
                if (!$d) throw new RuntimeException('Esse lançamento já não existe.');
                $pdo->prepare('UPDATE fin_docs SET deleted_at = ? WHERE id = ?')->execute([fin_now(), (int)$d['id']]);
                flash('Lançamento apagado.', 'ok', undo_push('fin', ['op' => 'doc_del', 'id' => (int)$d['id']]));
                redirect(fin_url(['tab' => $d['direction'] === 'in' ? 'receber' : 'pagar']));
            }
            case 'pay_add': {
                $d = fin_doc($pdo, (int)($_POST['id'] ?? 0));
                if (!$d) throw new RuntimeException('Esse lançamento já não existe.');
                $pid = fin_pay_add($pdo, $d, $_POST, fin_upload($_FILES['proof'] ?? null), null, $uid);
                $d = fin_doc($pdo, (int)$d['id']);
                flash(($d['direction'] === 'in' ? 'Recebimento' : 'Pagamento') . ' registado: ' . FIN_ST[$d['status']] . '.', 'ok', undo_push('fin', ['op' => 'pay', 'id' => $pid]));
                break;
            }
            case 'pay_del': {
                $p = fin_pay_del($pdo, (int)($_POST['pay'] ?? 0));
                if (!$p) throw new RuntimeException('Esse pagamento já não existe.');
                flash('Pagamento anulado.', 'ok', undo_push('fin', ['op' => 'pay_del', 'id' => (int)$p['id'], 'doc' => (int)$p['doc_id'], 'tx' => (int)$p['bank_tx_id']]));
                break;
            }
            case 'receipt_add': {
                $d = fin_doc($pdo, (int)($_POST['id'] ?? 0));
                $f = fin_upload($_FILES['receipt'] ?? null, false);
                if (!$d || !$f) throw new RuntimeException('Escolha o PDF do recibo.');
                [$key, $name] = fin_file_store($pdo, $f[0], fin_file_name($d, 'Recibo', $f[1]), $f[2], $d, 'receipt');
                $pdo->prepare('UPDATE fin_docs SET receipt_key = ?, receipt_name = ?, updated_at = ? WHERE id = ?')->execute([$key, $name, fin_now(), (int)$d['id']]);
                fin_doc_refresh($pdo, (int)$d['id']);
                flash('Recibo junto.', 'ok', undo_push('fin', ['op' => 'doc', 'row' => fin_snap($d)]));
                break;
            }
            case 'receipt_request': {
                require_once __DIR__ . '/inc/fin_mail.php';
                $d = fin_doc($pdo, (int)($_POST['id'] ?? 0));
                if (!$d) throw new RuntimeException('Esse lançamento já não existe.');
                flash('Recibo pedido ao contabilista (' . fin_request_receipt($pdo, $d, $uid) . '). Quando ele responder, junta-se aqui sozinho.');
                break;
            }
            case 'request_invoice': {
                require_once __DIR__ . '/inc/fin_mail.php';
                $_SESSION['fin_form'] = array_diff_key($_POST, ['csrf' => 1]);
                [$id, $code] = fin_request_invoice($pdo, $_POST, $uid);
                unset($_SESSION['fin_form']);
                flash('Pedido ' . $code . ' enviado ao contabilista. Fica «Fatura pedida» até a fatura chegar à caixa.');
                redirect(fin_url(['tab' => 'receber', 'doc' => $id]));
            }
            case 'client_send': case 'reminder_send': {
                require_once __DIR__ . '/inc/fin_mail.php';
                $d = fin_doc($pdo, (int)($_POST['id'] ?? 0));
                if (!$d) throw new RuntimeException('Esse lançamento já não existe.');
                fin_client_send($pdo, $d, trim((string)($_POST['to'] ?? '')), trim((string)($_POST['subject'] ?? '')), (string)($_POST['body'] ?? ''), $uid, $do === 'client_send' ? 'invoice' : 'reminder');
                flash($do === 'client_send' ? 'Fatura enviada ao cliente. Fica no histórico do negócio.' : 'Lembrete enviado.');
                break;
            }
            case 'reminder_draft': {
                require_once __DIR__ . '/inc/fin_ai.php';
                $d = fin_doc($pdo, (int)($_POST['id'] ?? 0));
                if (!$d) throw new RuntimeException('Esse lançamento já não existe.');
                $r = fin_ai_reminder($pdo, $d);
                $pdo->prepare('UPDATE fin_docs SET reminder_draft = ? WHERE id = ?')->execute([json_encode($r, JSON_UNESCAPED_UNICODE), (int)$d['id']]);
                flash('Rascunho pronto: reveja antes de enviar.');
                break;
            }
            case 'mail_confirm': {
                require_once __DIR__ . '/inc/fin_mail.php';
                $mid = (int)($_POST['mail'] ?? 0);
                $d = fin_doc($pdo, (int)($_POST['doc'] ?? 0));
                if (!$d) throw new RuntimeException('Escolha o pedido.');
                $msg = fin_mail_confirm($pdo, $mid, (int)$d['id'], $uid);
                flash($msg, 'ok', undo_push('fin', ['op' => 'mail', 'id' => $mid, 'status' => 'review', 'doc' => null, 'doc_row' => fin_snap($d)]));
                redirect(fin_url(['tab' => 'receber', 'doc' => (int)$d['id']]));
            }
            case 'mail_new': {
                // um email por confirmar vira lançamento novo (receita ou despesa), para rever no formulário
                $mid = (int)($_POST['mail'] ?? 0);
                $st = $pdo->prepare("SELECT * FROM fin_mail WHERE id = ? AND status = 'review'");
                $st->execute([$mid]);
                $m = $st->fetch();
                if (!$m) throw new RuntimeException('Esse email já foi tratado.');
                $ai = json_decode((string)$m['ai'], true) ?: [];
                $att = (json_decode((string)$m['att'], true) ?: [])[0] ?? ['key' => '', 'name' => ''];
                $dir = ($_POST['dir'] ?? ($ai['direction'] ?? 'out')) === 'in' ? 'in' : 'out';
                $id = fin_doc_create($pdo, ['direction' => $dir, 'doc_type' => 'invoice', 'scope' => 'company', 'party_kind' => $dir === 'in' ? 'client' : 'supplier',
                    'party_name' => (string)($ai['party_name'] ?? $m['from_addr']), 'party_nif' => (string)($ai['party_nif'] ?? ''), 'category' => $dir === 'out' ? (($ai['category'] ?? '') ?: 'Outros') : '',
                    'number' => (string)($ai['number'] ?? ''), 'description' => (string)($ai['description'] ?? $m['subject']), 'base' => (float)($ai['base'] ?? 0), 'vat_rate' => (float)($ai['vat_rate'] ?? 0),
                    'vat' => (float)($ai['vat'] ?? 0), 'total' => (float)($ai['total'] ?? 0), 'issue_date' => ($ai['issue_date'] ?? '') ?: null, 'due_date' => ($ai['due_date'] ?? '') ?: null,
                    'pdf_key' => (string)$att['key'], 'pdf_name' => (string)$att['name'], 'status' => 'review', 'needs_review' => 1, 'ai_data' => $m['ai'], 'notes' => 'Da caixa das faturas: ' . $m['subject']], $uid);
                $pdo->prepare("UPDATE fin_mail SET status = 'linked', doc_id = ?, done_by = ?, done_at = ? WHERE id = ?")->execute([$id, $uid, fin_now(), $mid]);
                flash('Lançamento criado a partir do email: confirme os dados.', 'ok', undo_push('fin', ['op' => 'mail', 'id' => $mid, 'status' => 'review', 'doc' => null, 'doc_new' => $id]));
                redirect(fin_url(['tab' => $dir === 'in' ? 'receber' : 'pagar', 'edit' => $id]));
            }
            case 'mail_ignore': {
                $mid = (int)($_POST['mail'] ?? 0);
                $pdo->prepare("UPDATE fin_mail SET status = 'ignored', done_by = ?, done_at = ? WHERE id = ? AND status IN ('review','noattach','dup')")->execute([$uid, fin_now(), $mid]);
                flash('Email posto de parte (continua na pasta «Processadas» da caixa).', 'ok', undo_push('fin', ['op' => 'mail', 'id' => $mid, 'status' => 'review', 'doc' => null]));
                break;
            }
            case 'fixed_save': {
                $id = (int)($_POST['id'] ?? 0);
                $v = fin_fixed_input($pdo, $_POST);
                if ($id) {
                    $st = $pdo->prepare('SELECT * FROM fin_fixed WHERE id = ?');
                    $st->execute([$id]);
                    $old = $st->fetch();
                    if (!$old) throw new RuntimeException('Esse custo fixo já não existe.');
                    $v['updated_at'] = fin_now();
                    $pdo->prepare('UPDATE fin_fixed SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($v))) . ' WHERE id = ?')->execute([...array_values($v), $id]);
                    flash('Custo fixo guardado.', 'ok', undo_push('fin', ['op' => 'fixed', 'row' => $old]));
                } else {
                    $v += ['created_by' => $uid, 'created_at' => fin_now(), 'updated_at' => fin_now()];
                    $cols = array_keys($v);
                    $pdo->prepare('INSERT INTO fin_fixed (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($v));
                    $id = (int)$pdo->lastInsertId();
                    flash('Custo fixo criado: ' . eur(fin_fixed_monthly($v)) . '/mês. O lançamento «Por pagar» aparece em cada vencimento.', 'ok', undo_push('fin', ['op' => 'fixed', 'new' => 1, 'id' => $id, 'row' => []]));
                }
                fin_fixed_tick($pdo);
                redirect(fin_url(['tab' => 'fixos']));
            }
            case 'fixed_toggle': {
                $st = $pdo->prepare('SELECT * FROM fin_fixed WHERE id = ?');
                $st->execute([(int)($_POST['id'] ?? 0)]);
                $old = $st->fetch();
                if (!$old) throw new RuntimeException('Esse custo fixo já não existe.');
                $pdo->prepare('UPDATE fin_fixed SET active = 1 - active, updated_at = ? WHERE id = ?')->execute([fin_now(), (int)$old['id']]);
                flash((int)$old['active'] ? 'Custo fixo desligado (deixa de gerar lançamentos).' : 'Custo fixo ligado.', 'ok', undo_push('fin', ['op' => 'fixed', 'row' => $old]));
                redirect(fin_url(['tab' => 'fixos']));
            }
            case 'emp_save': {
                require_once __DIR__ . '/inc/fin_pay.php';
                $target = (int)($_POST['uid'] ?? 0);
                if (!(int)$pdo->query('SELECT COUNT(*) FROM users WHERE id = ' . $target)->fetchColumn()) throw new RuntimeException('Essa pessoa não existe.');
                [$old, $new] = fin_employee_save($pdo, $target, fin_employee_input($_POST), $uid);
                $w = fin_employee_warnings($pdo, fin_employee($pdo, $target));
                flash('Ficha de salário guardada.' . ($w ? ' Atenção: ' . implode(' ', $w) : ''), 'ok', undo_push('fin', ['op' => 'employee', 'uid' => $target, 'new' => $new ? 1 : 0, 'row' => $old ?? []]));
                break;
            }
            case 'payroll_issue': {
                require_once __DIR__ . '/inc/fin_pay.php';
                [$py, $pm] = array_map('intval', explode('-', (string)($_POST['ym'] ?? '') . '-0'));
                if ($py < 2000 || $pm < 1 || $pm > 12) throw new RuntimeException('Escolha o mês.');
                $ok = []; $err = []; $last = 0;
                foreach (array_map('intval', (array)($_POST['uids'] ?? [])) as $u) {
                    try { $last = fin_payroll_issue($pdo, $u, $py, $pm, $uid); $ok[] = $last; } catch (RuntimeException $e) { $err[] = $e->getMessage(); }
                }
                if (!$ok && !$err) throw new RuntimeException('Escolha pelo menos um colaborador.');
                if ($ok) flash(count($ok) . (count($ok) === 1 ? ' recibo emitido' : ' recibos emitidos') . ' de ' . month_year($pm, $py) . ': lançamentos do líquido, IRS e Segurança Social em «A pagar».', 'ok',
                               count($ok) === 1 ? undo_push('fin', ['op' => 'payroll', 'what' => 'issue', 'id' => $last]) : '');
                foreach ($err as $e) flash($e, 'err');
                redirect(fin_url(['tab' => 'salarios', 'ym' => sprintf('%04d-%02d', $py, $pm)]));
            }
            case 'payroll_cancel': {
                require_once __DIR__ . '/inc/fin_pay.php';
                $p = fin_payroll_cancel($pdo, (int)($_POST['id'] ?? 0));
                flash('Processamento de ' . $p['name'] . ' anulado (os lançamentos ficam anulados).', 'ok', undo_push('fin', ['op' => 'payroll', 'what' => 'cancel', 'id' => (int)$p['id']]));
                break;
            }
            case 'acc_save': {
                require_once __DIR__ . '/inc/fin_cash.php';
                $id = (int)($_POST['id'] ?? 0);
                $old = $id ? fin_account($pdo, $id) : null;
                if ($id && !$old) throw new RuntimeException('Essa conta já não existe.');
                $v = fin_account_input($_POST, !$old);
                // o responsável pela conta só o mudam superadmin e admins
                if (role_is_admin((string)$user['role']) && isset($_POST['owner_id'])) {
                    $o = (int)$_POST['owner_id'];
                    $v['owner_id'] = $o && fin_user_active($pdo, $o) ? $o : null;
                }
                $v['updated_at'] = fin_now();
                $bal = isset($v['balance']) ? [$v['balance'], $v['balance_date']] : null;
                unset($v['balance'], $v['balance_date']);
                if ($old) {
                    $pdo->prepare('UPDATE fin_accounts SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($v))) . ' WHERE id = ?')->execute([...array_values($v), $id]);
                    flash('Conta guardada.', 'ok', undo_push('fin', ['op' => 'account', 'row' => array_intersect_key($old, $v + ['id' => 1])]));
                } else {
                    $v['created_at'] = fin_now();
                    $v['position'] = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM fin_accounts')->fetchColumn();
                    $cols = array_keys($v);
                    $pdo->prepare('INSERT INTO fin_accounts (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($v));
                    $id = (int)$pdo->lastInsertId();
                    if ($bal) fin_balance_write($pdo, $id, (float)$bal[0], (string)$bal[1], $uid);
                    flash('Conta criada: ' . $v['name'] . '.');
                }
                redirect(fin_url(['tab' => 'tesouraria']));
            }
            case 'res_rule': case 'res_rule_del': case 'payout_add': case 'payout_del': {
                // reservas dos sócios: só superadmin e admins
                if (!role_is_admin((string)$user['role'])) { http_response_code(403); exit('Só superadmin e admins.'); }
                require_once __DIR__ . '/inc/fin_res.php';
                if ($do === 'res_rule') {
                    [$rid, $old] = fin_res_rule_save($pdo, fin_res_rule_input($pdo, $_POST), $uid);
                    flash($old ? 'Reserva atualizada.' : 'Reserva criada: entra como custo do negócio e acumula à medida que o cliente paga.', 'ok',
                          undo_push('fin', $old ? ['op' => 'res_rule', 'row' => $old] : ['op' => 'res_rule', 'new' => 1, 'id' => $rid]));
                } elseif ($do === 'res_rule_del') {
                    $r = fin_res_rule($pdo, (int)($_POST['id'] ?? 0));
                    if (!$r || $r['deleted_at']) throw new RuntimeException('Essa reserva já não existe.');
                    $pdo->prepare('UPDATE fin_reserve_rules SET deleted_at = ? WHERE id = ?')->execute([fin_now(), (int)$r['id']]);
                    flash('Reserva apagada.', 'ok', undo_push('fin', ['op' => 'res_rule', 'row' => $r]));
                } elseif ($do === 'payout_add') {
                    $pid = fin_res_payout_add($pdo, $_POST, fin_upload($_FILES['proof'] ?? null), $uid);
                    flash('Levantamento registado.', 'ok', undo_push('fin', ['op' => 'payout', 'new' => 1, 'id' => $pid]));
                } else {
                    $pid = (int)($_POST['id'] ?? 0);
                    $pdo->prepare('UPDATE fin_reserve_payouts SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL')->execute([fin_now(), $pid]);
                    flash('Levantamento anulado.', 'ok', undo_push('fin', ['op' => 'payout', 'id' => $pid]));
                }
                redirect(fin_url(['tab' => 'tesouraria']) . '#reservas');
            }
            case 'import': {
                require_once __DIR__ . '/inc/fin_cash.php';
                $f = $_FILES['statement'] ?? null;
                if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Escolha o ficheiro do extrato (CSV ou OFX).');
                if ((int)$f['size'] > 5 * 1048576) throw new RuntimeException('O extrato tem mais de 5 MB.');
                $r = fin_import($pdo, (int)($_POST['account'] ?? 0), (string)file_get_contents($f['tmp_name']), (string)$f['name'], $uid);
                flash('Extrato ' . $r['fmt'] . ' importado: ' . $r['new'] . ' movimentos novos' . ($r['dup'] ? ', ' . $r['dup'] . ' já estavam' : '') . ($r['balance'] !== null ? ' · saldo ' . eur($r['balance']) : '') . '. Concilie abaixo.');
                redirect(fin_url(['tab' => 'tesouraria']) . '#conciliar');
            }
            case 'tx_match': case 'tx_new': case 'tx_ignore': {
                require_once __DIR__ . '/inc/fin_cash.php';
                $t = fin_tx($pdo, (int)($_POST['tx'] ?? 0));
                if (!$t || $t['status'] !== 'open') throw new RuntimeException('Esse movimento já foi tratado.');
                if ($do === 'tx_ignore') {
                    $pdo->prepare("UPDATE fin_bank_tx SET status = 'ignored' WHERE id = ?")->execute([(int)$t['id']]);
                    flash('Movimento posto de parte.', 'ok', undo_push('fin', ['op' => 'tx', 'id' => (int)$t['id']]));
                } elseif ($do === 'tx_match') {
                    $d = fin_doc($pdo, (int)($_POST['doc'] ?? 0));
                    if (!$d) throw new RuntimeException('Escolha o lançamento.');
                    $pid = fin_tx_match($pdo, $t, $d, $uid);
                    flash('Conciliado com ' . fin_doc_label($d) . ': conta como comprovativo.', 'ok', undo_push('fin', ['op' => 'tx', 'id' => (int)$t['id'], 'pay' => $pid]));
                } else {
                    $id = fin_tx_new_doc($pdo, $t, $_POST, $uid);
                    $pid = (int)$pdo->query('SELECT payment_id FROM fin_bank_tx WHERE id = ' . (int)$t['id'])->fetchColumn();
                    flash('Lançamento criado e conciliado.', 'ok', undo_push('fin', ['op' => 'tx', 'id' => (int)$t['id'], 'pay' => $pid, 'doc_new' => $id]));
                }
                redirect(fin_url(['tab' => 'tesouraria']) . '#conciliar');
            }
            case 'sim_save': {
                require_once __DIR__ . '/inc/fin_sim.php';
                $kind = (string)($_POST['kind'] ?? '');
                $id = fin_scenario_save($pdo, $kind, fin_sim_inputs($kind, $_POST), $uid);
                flash('Cenário guardado.');
                redirect(fin_url(['tab' => 'simulador', 'kind' => $kind, 'sc' => $id]));
            }
            case 'sim_del': {
                $pdo->prepare('UPDATE fin_scenarios SET deleted_at = ? WHERE id = ?')->execute([fin_now(), (int)($_POST['id'] ?? 0)]);
                flash('Cenário apagado.', 'ok', undo_push('fin', ['op' => 'scenario', 'id' => (int)($_POST['id'] ?? 0)]));
                redirect(fin_url(['tab' => 'simulador']));
            }
            case 'sim_apply': {
                require_once __DIR__ . '/inc/fin_sim.php';
                $s = fin_scenario($pdo, (int)($_POST['id'] ?? 0));
                if (!$s) throw new RuntimeException('Esse cenário já não existe.');
                $ids = fin_scenario_apply($pdo, $s, $uid);
                flash('Cenário aplicado: ' . count($ids) . (count($ids) === 1 ? ' custo fixo previsto criado' : ' custos fixos previstos criados') . ' (entram no runway).', 'ok', undo_push('fin', ['op' => 'apply', 'id' => (int)$s['id'], 'fixed' => $ids]));
                redirect(fin_url(['tab' => 'fixos']));
            }
            case 'toggle_paid': {
                // negócios de antes das faturas: o «pago» do negócio (sem lançamentos no Financeiro)
                $st = $pdo->prepare('SELECT code, is_paid FROM deals WHERE id = ? AND code IS NOT NULL');
                $st->execute([(int)($_POST['id'] ?? 0)]);
                if ($d = $st->fetch()) {
                    $pdo->prepare('UPDATE deals SET is_paid = 1 - is_paid WHERE id = ? AND code IS NOT NULL')->execute([(int)$_POST['id']]);
                    flash($d['code'] . ((int)$d['is_paid'] ? ' voltou a estar por pagar.' : ' marcado como pago.'), 'ok', undo_push('deal_paid', ['id' => (int)$_POST['id'], 'from' => (int)$d['is_paid']]));
                }
                break;
            }
            case 'goal': {
                require_perm('financeiro', PERM_MANAGE);
                $pdo->prepare(db_is_sqlite($pdo) ? 'INSERT OR REPLACE INTO settings (skey, svalue) VALUES (?, ?)' : 'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
                    ->execute(['meta_anual_' . $year, (string)money_in($_POST['goal'] ?? '0')]);
                flash('Meta de ' . $year . ' guardada.');
                redirect(fin_url(['tab' => 'geral', 'year' => $year]) . '#faturacao');
            }
            default:
                throw new RuntimeException('Ação desconhecida.');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
        if ($do === 'doc_save') redirect(fin_url(['tab' => ($_POST['direction'] ?? '') === 'in' ? 'receber' : 'pagar', (int)($_POST['id'] ?? 0) ? 'edit' : 'new' => (int)($_POST['id'] ?? 0) ?: 1]));
        if ($do === 'request_invoice') redirect(fin_url(['tab' => 'receber', 'request' => 1, 'deal' => (int)($_POST['deal_id'] ?? 0)]));
    }
    redirect($back);
}

// ── página ──────────────────────────────────────────────────
$form = $_SESSION['fin_form'] ?? null;
unset($_SESSION['fin_form']);
render_header('Financeiro', 'financeiro', '', [FIN_TABS[$tab]]);
render_flashes();
echo paginate_css();
?>
<div class="fin-head">
  <h1>Financeiro</h1>
  <?php if ($canEdit): ?>
  <div class="fin-tools">
    <a class="btn sm" href="<?= e(fin_url(['tab' => 'pagar', 'new' => 1])) ?>"><?= icon('plus') ?> Despesa</a>
    <a class="btn ghost sm" href="<?= e(fin_url(['tab' => 'receber', 'new' => 1])) ?>"><?= icon('plus') ?> Fatura emitida</a>
    <a class="btn ghost sm" href="<?= e(fin_url(['tab' => 'receber', 'request' => 1])) ?>"><?= icon('send') ?> Pedir fatura</a>
  </div>
  <?php endif; ?>
</div>
<?= fin_kpis($pdo) ?>
<nav class="tabs fin-tabs" aria-label="Separadores do Financeiro">
  <?php foreach (FIN_TABS as $k => $label): ?>
    <a href="<?= e(fin_url(['tab' => $k])) ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php
require __DIR__ . '/inc/fin_tabs.php';
?>
<script src="<?= asset_url('fin.js') ?>" defer></script>
<?php render_footer();
