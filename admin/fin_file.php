<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/fin.php';
$user = require_login();

// Os ficheiros e as exportações do Financeiro (§28): a permissão vê-se aqui, no servidor, para cada pedido.
// Faturas, comprovativos, recibos, anexos da caixa e exportações: só quem tem a aba Financeiro.
// Recibos de vencimento: quem tem Financeiro, ou a própria pessoa (Perfil).

if (!fin_ready($pdo)) { http_response_code(404); exit('Não encontrado.'); }
$dl = !empty($_GET['dl']);
$deny = function (): void { http_response_code(404); exit('Não encontrado.'); };

if (isset($_GET['payslip'])) {
    require_once __DIR__ . '/inc/fin_pay.php';
    $p = fin_payroll_get($pdo, (int)$_GET['payslip']);
    if (!$p || $p['pdf_key'] === '' || ($p['cancelled_at'] && !can('financeiro'))) $deny();
    if ((int)$p['user_id'] !== (int)$user['id'] && !can('financeiro')) $deny();
    fin_file_send((string)$p['pdf_key'], sprintf('Recibo-vencimento_%04d-%02d.pdf', $p['year'], $p['month']), $dl);
}

if (!can('financeiro')) $deny();

if (isset($_GET['doc'])) {
    $d = fin_doc($pdo, (int)$_GET['doc']);
    $k = ($_GET['k'] ?? 'pdf') === 'receipt' ? 'receipt' : 'pdf';
    if (!$d || (string)$d[$k . '_key'] === '') $deny();
    fin_file_send((string)$d[$k . '_key'], (string)$d[$k . '_name'] ?: 'documento.pdf', $dl);
}
if (isset($_GET['pay'])) {
    $st = $pdo->prepare('SELECT * FROM fin_payments WHERE id = ?');
    $st->execute([(int)$_GET['pay']]);
    $p = $st->fetch();
    if (!$p || $p['proof_key'] === '') $deny();
    fin_file_send((string)$p['proof_key'], (string)$p['proof_name'] ?: 'comprovativo.pdf', $dl);
}
if (isset($_GET['payout'])) {   // levantamentos das reservas dos sócios: só superadmin e admins
    if (!role_is_admin((string)$user['role'])) $deny();
    $st = $pdo->prepare('SELECT * FROM fin_reserve_payouts WHERE id = ?');
    $st->execute([(int)$_GET['payout']]);
    $p = $st->fetch();
    if (!$p || $p['proof_key'] === '') $deny();
    fin_file_send((string)$p['proof_key'], (string)$p['proof_name'] ?: 'comprovativo.pdf', $dl);
}
if (isset($_GET['mail'])) {
    $st = $pdo->prepare('SELECT att FROM fin_mail WHERE id = ?');
    $st->execute([(int)$_GET['mail']]);
    $a = (json_decode((string)$st->fetchColumn(), true) ?: [])[(int)($_GET['n'] ?? 0)] ?? null;
    if (!$a || ($a['key'] ?? '') === '') $deny();
    fin_file_send((string)$a['key'], (string)$a['name'], $dl);
}

// exportações para o contabilista
$ym = (string)($_GET['ym'] ?? '');
if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m) || (int)$m[2] < 1 || (int)$m[2] > 12) $deny();
[$y, $mo] = [(int)$m[1], (int)$m[2]];
$from = sprintf('%04d-%02d-01', $y, $mo);
$to = date('Y-m-t', strtotime($from));
$what = (string)($_GET['export'] ?? '');
@set_time_limit(300);
if ($what === 'zip') {
    require_once __DIR__ . '/inc/fin_tax.php';
    $zip = fin_export_zip($pdo, $y, $mo);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="Financeiro_' . $ym . '.zip"');
    header('Content-Length: ' . strlen($zip));
    echo $zip;
    exit;
}
if ($what === 'docs' || $what === 'payroll') {
    require_once __DIR__ . '/inc/fin_tax.php';
    require_once __DIR__ . '/inc/fin_pay.php';
    $csv = $what === 'docs' ? fin_docs_csv($pdo, $from, $to) : fin_payroll_csv($pdo, $y, $mo);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . ($what === 'docs' ? 'Lancamentos_' : 'Salarios_') . $ym . '.csv"');
    echo $csv;
    exit;
}
$deny();
