<?php
/**
 * Pipeline v2, fase 1 — «Pedir fatura» pela tarefa (§34.11 T3, D24; tarefa 12).
 *
 * pipe2_invoice_src monta o pedido ao contabilista no servidor: o NIF, o nome e o email do cliente vêm de billing_deal,
 * nunca do pedido; só se pedem tarefas de fatura abertas do próprio negócio v2. pipe2_invoice_request guarda a tarefa
 * em fin_docs.task_id; uma fatura emitida (fin_doc_issued) fecha só a tarefa do seu pedido, e a submetida na tarefa
 * (fin_from_billing com a tarefa) passa a «Emitida» o pedido dessa tarefa, não o mais antigo. Um pedido sem tarefa
 * (v1) fecha todas as tarefas de fatura abertas, como hoje. pipe2_invoice_state dá o estado sem NIF nem valores.
 * Numa base na 42 (sem fin_docs.task_id) a v1 faz o mesmo de hoje, sem exceção nem aviso de PHP.
 *
 * Nunca chega ao envio de email: os pedidos que seguiriam para o contabilista param antes (sem email do contabilista,
 * ou pedido repetido).
 *
 * Corre só em bases privadas (p2v_db e uma base na 42).
 *   php tests/pipe2_invoice_test.php
 * Casos: ::request_src ::request_refused ::closes_own_task ::submit_prefers_task_request ::state_without_nif ::v1_at_42
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/tasks.php';
require_once __DIR__ . '/../admin/inc/pipeline.php';
require_once __DIR__ . '/../admin/inc/pipeline_flow.php';
require_once __DIR__ . '/../admin/inc/fin.php';
if (is_file(__DIR__ . '/../admin/inc/pipe2_billing.php')) require_once __DIR__ . '/../admin/inc/pipe2_billing.php';

const P2I_NIF = '501234567';

/** [classe, mensagem] da exceção de $fn, ou ['', '']. */
function p2i_err(callable $fn): array {
    try { $fn(); } catch (Throwable $e) { return [get_class($e), $e->getMessage()]; }
    return ['', ''];
}

/** Um pedido de fatura «requested» do negócio (INSERT direto), com a tarefa se a coluna existir e $task vier. */
function p2i_request(PDO $pdo, int $deal, ?int $task, string $code): int {
    $row = ['direction' => 'in', 'doc_type' => 'invoice', 'scope' => 'deal', 'deal_id' => $deal, 'party_kind' => 'client', 'party_name' => 'P2T · Cliente',
            'party_nif' => P2I_NIF, 'base' => 100, 'vat_rate' => 23, 'vat' => 23, 'total' => 123, 'status' => 'requested', 'req_code' => $code,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
    if ($task !== null) $row['task_id'] = $task;
    return pipe2_insert($pdo, 'fin_docs', $row);
}

/** Uma tarefa de documento do negócio (fatura, por defeito), por fazer. */
function p2i_task(PDO $pdo, int $deal, array $o = []): int {
    return pipe2_task($pdo, $deal, $o + ['doc_kind' => 'invoice', 'title' => 'Emitir fatura']);
}

function p2i_status(PDO $pdo, string $table, int $id): string {
    return (string)p2v_col($pdo, "SELECT status FROM $table WHERE id = ?", [$id]);
}

// ── a base privada e os dados (tudo antes das leituras) ─────
[$db, $tp] = p2v_db();
$tp->prepare("DELETE FROM settings WHERE skey = 'fin_accountant_email'")->execute();   // nada segue para o contabilista
pipe2_use($tp);
$w = p2v_world($tp, ['cam']);
$S = p2v_stages($tp, $w['cam']);
$client = pipe2_insert($tp, 'contacts', ['name' => 'P2T · Cliente da avença', 'nif' => P2I_NIF, 'email' => 'cliente@p2t.invalid', 'created_at' => date('Y-m-d H:i:s')]);
$d = p2v_deal($tp, $w, ['cam'], ['at' => 'exec', 'status' => 'ongoing', 'billing' => ['cam' => [500, 12]]]);
$tp->prepare('UPDATE deals SET contact_id = ? WHERE id = ?')->execute([$client, $d['id']]);
$dOther = p2v_deal($tp, $w, ['cam'], ['at' => 'exec', 'status' => 'ongoing']);
$v1f = pipe2_funnel($tp, 'v1 faturas');
$dV1 = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1f, 'contact_id' => $client]);

$tSrc = p2i_task($tp, $d['id'], ['stage_id' => $S['exec'], 'cycle_ym' => '2026-11']);
$tPay = p2i_task($tp, $d['id'], ['stage_id' => $S['delivery'], 'doc_kind' => 'payment', 'title' => 'Validar pagamento']);
$tDone = p2i_task($tp, $d['id'], ['stage_id' => $S['exec'], 'status' => 'done', 'cycle_ym' => '2026-10']);
$tElse = p2i_task($tp, $dOther['id'], ['stage_id' => $S['exec']]);
$tV1 = p2i_task($tp, $dV1);
$tDup = p2i_task($tp, $d['id'], ['stage_id' => $S['exec'], 'cycle_ym' => '2027-01']);
$tA = p2i_task($tp, $d['id'], ['stage_id' => $S['exec'], 'cycle_ym' => '2026-11', 'title' => 'Emitir fatura A']);   // ::closes_own_task
$tB = p2i_task($tp, $d['id'], ['stage_id' => $S['exec'], 'cycle_ym' => '2026-12', 'title' => 'Emitir fatura B']);
$tState = p2i_task($tp, $d['id'], ['stage_id' => $S['exec'], 'cycle_ym' => '2027-02', 'title' => 'Emitir fatura C']);
p2v_as(99);

// ── ::request_src (T3: o NIF é do servidor) ─────────────────
$c = '::request_src';
$in = ['party_nif' => '999', 'party_name' => 'Outro', 'party_email' => 'outro@p2t.invalid', 'base' => '500', 'vat_rate' => '23', 'description' => 'Avença de novembro',
       'scope' => 'company', 'deal_id' => $dOther['id']];
$src = pipe2_invoice_src($tp, deal_get($tp, $d['id']), task_by_id($tp, $tSrc), $in);
t_eq([$src['scope'] ?? '', (int)($src['deal_id'] ?? 0)], ['deal', $d['id']], "$c âmbito do negócio, o deste negócio (o do pedido é ignorado)");
t_eq([$src['party_nif'] ?? '', $src['party_name'] ?? '', $src['party_email'] ?? ''], [P2I_NIF, 'P2T · Cliente da avença', 'cliente@p2t.invalid'],
     "$c NIF, nome e email do cliente de billing_deal ('party_nif' => '999' ignorado)");
t_eq([(string)($src['base'] ?? ''), (string)($src['vat_rate'] ?? ''), $src['description'] ?? ''], ['500', '23', 'Avença de novembro'], "$c valor, IVA e descrição do pedido");
t_eq(p2i_err(fn() => pipe2_invoice_src($tp, deal_get($tp, $d['id']), task_by_id($tp, $tPay), $in)), ['RuntimeException', 'Esta tarefa não é um pedido de fatura.'], "$c recusa uma tarefa de pagamento");
t_eq(p2i_err(fn() => pipe2_invoice_src($tp, deal_get($tp, $d['id']), task_by_id($tp, $tDone), $in)), ['RuntimeException', 'Esta tarefa já está feita.'], "$c recusa uma tarefa feita");
t_eq(p2i_err(fn() => pipe2_invoice_src($tp, deal_get($tp, $d['id']), task_by_id($tp, $tElse), $in)), ['RuntimeException', 'Esta tarefa não é deste negócio.'], "$c recusa uma tarefa de outro negócio");
t_eq(p2i_err(fn() => pipe2_invoice_src($tp, deal_get($tp, $dV1), task_by_id($tp, $tV1), $in)), ['RuntimeException', 'Este negócio não é da Pipeline v2.'], "$c recusa um negócio v1");

// ── ::request_refused (o pedido não fica a meio) ────────────
$c = '::request_refused';
$n0 = (int)p2v_col($tp, 'SELECT COUNT(*) FROM fin_docs WHERE deal_id = ?', [$d['id']]);
[$cls, $msg] = p2i_err(fn() => pipe2_invoice_request($tp, deal_get($tp, $d['id']), task_by_id($tp, $tSrc), $in, 99));
t_eq($cls, 'RuntimeException', "$c sem email do contabilista: recusa");
t_true(str_contains($msg, 'email do contabilista'), "$c a mensagem do Financeiro — obtido «{$msg}»");
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM fin_docs WHERE deal_id = ?', [$d['id']]), $n0, "$c nenhum lançamento fica");
$fDup = p2i_request($tp, $d['id'], $tDup, 'FAT-2026-0901');
t_eq(p2i_err(fn() => pipe2_invoice_request($tp, deal_get($tp, $d['id']), task_by_id($tp, $tDup), $in, 99)),
     ['RuntimeException', 'Já há um pedido de fatura para esta tarefa (FAT-2026-0901).'], "$c um 2.º pedido para a mesma tarefa: recusa, antes do envio");
t_eq(p2i_err(fn() => pipe2_invoice_request($tp, deal_get($tp, $d['id']), task_by_id($tp, $tPay), $in, 99)), ['RuntimeException', 'Esta tarefa não é um pedido de fatura.'], "$c as recusas do pipe2_invoice_src");

// ── ::closes_own_task (T3) ──────────────────────────────────
$c = '::closes_own_task';
$fA = p2i_request($tp, $d['id'], $tA, 'FAT-2026-0911');
$fB = p2i_request($tp, $d['id'], $tB, 'FAT-2026-0912');
pipe2_request_reset();
fin_doc_issued($tp, $fA, 1);
t_eq([p2i_status($tp, 'tasks', $tA), p2i_status($tp, 'tasks', $tB)], ['done', 'todo'], "$c a fatura do pedido da 1.ª fecha só a 1.ª");
t_eq(p2i_status($tp, 'tasks', $tState), 'todo', "$c as outras tarefas de fatura ficam abertas");
$fV1 = p2i_request($tp, $d['id'], null, 'FAT-2026-0913');
pipe2_request_reset();
fin_doc_issued($tp, $fV1, 1);
t_eq([p2i_status($tp, 'tasks', $tB), p2i_status($tp, 'tasks', $tState), p2i_status($tp, 'tasks', $tDup)], ['done', 'done', 'done'], "$c um pedido sem tarefa (v1) fecha todas as abertas, como hoje");

// ── ::submit_prefers_task_request (T3) ──────────────────────
$c = '::submit_prefers_task_request';
$dS = p2v_deal($tp, $w, ['cam'], ['at' => 'exec', 'status' => 'ongoing']);
$tS1 = p2i_task($tp, $dS['id'], ['stage_id' => $S['exec'], 'cycle_ym' => '2026-11']);
$tS2 = p2i_task($tp, $dS['id'], ['stage_id' => $S['exec'], 'cycle_ym' => '2026-12']);
$g1 = p2i_request($tp, $dS['id'], $tS1, 'FAT-2026-0921');
$g2 = p2i_request($tp, $dS['id'], $tS2, 'FAT-2026-0922');
$bill = fn(int $deal) => pipe2_insert($tp, 'deal_billing', ['deal_id' => $deal, 'kind' => 'invoice', 'number' => 'FT P2T', 'doc_date' => today_lisbon(), 'net' => 100, 'vat' => 23,
                                                         'total' => 123, 'file_key' => '', 'file_name' => '', 'note' => '', 'created_at' => date('Y-m-d H:i:s')]);
fin_from_billing($tp, deal_get($tp, $dS['id']), $bill($dS['id']), 'FT 2026/2', today_lisbon(), 100, 23, 123, '', '', 1, $tS2);
t_eq([p2i_status($tp, 'fin_docs', $g1), p2i_status($tp, 'fin_docs', $g2)], ['requested', 'issued'], "$c com a tarefa da 2.ª: passa a «Emitida» o pedido dela, não o mais antigo");
fin_from_billing($tp, deal_get($tp, $dS['id']), $bill($dS['id']), 'FT 2026/3', today_lisbon(), 100, 23, 123, '', '', 1);
t_eq(p2i_status($tp, 'fin_docs', $g1), 'issued', "$c sem tarefa: o mais antigo, como hoje");
$dS3 = p2v_deal($tp, $w, ['cam'], ['at' => 'exec', 'status' => 'ongoing']);
$h1 = p2i_request($tp, $dS3['id'], null, 'FAT-2026-0931');
fin_from_billing($tp, deal_get($tp, $dS3['id']), $bill($dS3['id']), 'FT 2026/4', today_lisbon(), 100, 23, 123, '', '', 1, 999999);
t_eq(p2i_status($tp, 'fin_docs', $h1), 'issued', "$c com uma tarefa sem pedido: o mais antigo, como hoje");
$fn = new ReflectionFunction('billing_submit_invoice');
$code = implode('', array_slice(file($fn->getFileName()), $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1));
t_true((bool)preg_match('/fin_from_billing\([^;]*\$by,\s*\$task \? \(int\)\$task\[\'id\'\] : null\)/', $code), "$c billing_submit_invoice passa a tarefa a fin_from_billing");

// ── ::state_without_nif (T3, «sem Financeiro vê-se o estado, não o NIF») ──
$c = '::state_without_nif';
$tSt = p2i_task($tp, $d['id'], ['stage_id' => $S['exec'], 'cycle_ym' => '2027-03', 'title' => 'Emitir fatura D']);
p2i_request($tp, $d['id'], $tSt, 'FAT-2026-0942');
$st = pipe2_invoice_state($tp, $tSt);
t_eq($st, ['status' => 'requested', 'label' => 'Fatura pedida', 'code' => 'FAT-2026-0942'], "$c estado, rótulo e código");
t_eq(array_key_exists('nif', (array)$st) || array_key_exists('party_nif', (array)$st), false, "$c sem a chave do NIF");
$js = json_encode($st, JSON_UNESCAPED_UNICODE);
t_true(!str_contains($js, P2I_NIF) && !str_contains($js, '123') && !str_contains($js, '100'), "$c o JSON não tem o NIF nem valores — obtido $js");
t_eq(pipe2_invoice_state($tp, $tSrc), null, "$c uma tarefa sem pedido → null");

// ── ::v1_at_42 (o acesso guardado do caminho v1) ────────────
$c = '::v1_at_42';
$db42 = pipe2_tmpdb_create(pipe2_base_dump());
$p42 = pipe2_tmpdb_pdo($db42);
t_eq(db_has_column($p42, 'fin_docs', 'task_id'), false, "$c a base na 42 não tem fin_docs.task_id");
$f42 = (int)$p42->query('SELECT id FROM funnels ORDER BY id LIMIT 1')->fetchColumn();
$d42 = pipe2_deal($p42, ['phase' => 'production', 'funnel_id' => $f42]);
$t42a = p2i_task($p42, $d42);
$t42b = p2i_task($p42, $d42);
$r42a = p2i_request($p42, $d42, null, 'FAT-2026-0951');
$r42b = p2i_request($p42, $d42, null, 'FAT-2026-0952');
pipe2_use($p42);
$warn = [];
set_error_handler(function (int $no, string $msg) use (&$warn) { $warn[] = $msg; return true; });
$e1 = p2i_err(fn() => fin_from_billing($p42, deal_get($p42, $d42), 0, 'FT 42/1', today_lisbon(), 100, 23, 123, '', '', 1, $t42a));
$s1 = [p2i_status($p42, 'fin_docs', $r42a), p2i_status($p42, 'fin_docs', $r42b)];
$e2 = p2i_err(fn() => fin_doc_issued($p42, $r42b, 1));
$e3 = p2i_err(fn() => t_eq(pipe2_invoice_state($p42, $t42a), null, "$c pipe2_invoice_state sem a coluna → null"));
restore_error_handler();
pipe2_use($tp);
t_eq([$e1, $e2, $e3], [['', ''], ['', ''], ['', '']], "$c sem exceção");
t_eq($warn, [], "$c sem avisos de PHP");
t_eq($s1, ['issued', 'requested'], "$c fin_from_billing com a tarefa passa a «Emitida» o pedido mais antigo, como hoje");
t_eq([p2i_status($p42, 'tasks', $t42a), p2i_status($p42, 'tasks', $t42b)], ['done', 'done'], "$c fin_doc_issued fecha as tarefas de fatura abertas do negócio, como hoje");

t_done();
