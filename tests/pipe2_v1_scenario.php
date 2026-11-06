<?php
/**
 * Pipeline v2, fase 1 — um cenário v1 inteiro, para provar que os ganchos da fase 1 não mudam nada num negócio v1.
 * Só usa funções da v1 que já existiam na fase 0 (8c581ae) e os helpers de tests/pipe2_lib.php, por isso corre com o
 * código de agora e na árvore da fase 0; tests/pipe2_v1_hooks_test.php corre-o nas duas e compara as saídas.
 *
 *   php tests/pipe2_v1_scenario.php --db=X [--cols=F] [--skip=repeat]
 *
 * X: base privada (topper_p2_<pid>_<n>) já migrada pelo código desta árvore (recusa outra versão). F: JSON
 * {tabela: [colunas]} com as colunas a comparar (as que existem nas duas bases); sem F, todas as desta base.
 * --skip=repeat: não fecha a tarefa semanal que se repete (o controlo do teste: tem de dar diferenças).
 *
 * Com a sessão do uid 1 (admin com Financeiro) e o relógio da v1 na data fixa P2S_TICK:
 *   1. uma lead convertida para o VFX real, que anda X-Ray → Proposta → Aguardar feedback → Kickoff → as etapas de
 *      produção → Concluído (deal_move, forçado por admin onde o gate pede), com uma recusa do gate, a obrigatória do
 *      X-Ray fechada, anulada e fechada outra vez, o Kickoff e o Concluído anulados e refeitos, o pagamento de
 *      adjudicação validado, a fatura de um pedido do Financeiro emitida, as repetições (diária e semanal) fechadas, a
 *      semanal reaberta, duas rondas, uma peça de Review e as tarefas de aprovação dela, e o pagamento final;
 *   2. um negócio de crosselling v1 (VFX + CGI, timelines diferentes, a CGI arranca numa data): Kickoff, as frentes
 *      pelas etapas (xsell_line_move, com uma anulação e uma recusa), o relógio (flow_tick) que arranca a CGI, o que
 *      falta para concluir (xsell_close_missing), duas peças de Review, as leituras da avença e das condições;
 *   3. um negócio que muda de funil (deal_change_funnel);
 *   4. a saída de um utilizador de teste (team_reassign);
 *   5. um funil novo (funnel_create) e, no fim, os alertas do dono (my_alerts).
 *
 * Imprime {"cmp": …, "meta": …}. cmp é o que se compara:
 *   calls   [rótulo, resultado] de cada passo, pela ordem (uma recusa: «!Classe: mensagem»);
 *   rows    tabela => as linhas criadas pelo cenário (id maior do que o de antes; relations pelos contactos criados),
 *           só com as colunas de F;
 *   dump    tabela => [linhas, soma dos CRC32] das linhas que já existiam, sem as colunas de horas (o relógio e a
 *           reatribuição correm sobre a base toda: nada mais pode mudar de outra maneira);
 *   alerts  as chaves de my_alerts() do dono, por grupo; undo [tipo, chaves dos dados, oferecido?] de cada «Anular».
 * Normalização: os ids das linhas criadas passam a «tabela#n», pela ordem de criação (também nas colunas que os
 * referem, nos resultados e nas chaves dos alertas); DATETIME/TIMESTAMP → «(hora)» (null fica null); DATE → «D+n»
 * (dias a partir de hoje). meta (não se compara): schema (SCHEMA_VERSION desta árvore), files (os ficheiros
 * carregados), deals (os ids reais dos negócios do cenário), touched (quantas linhas o cenário criou).
 */
ini_set('display_errors', 'stderr');
require __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
foreach (['tasks', 'undo', 'fin', 'team', 'cc_review', 'deal_file', 'reach', 'deals'] as $f) require_once __DIR__ . '/../admin/inc/' . $f . '.php';

const P2S_FORCE = 'P2T · cenário v1 (passagem forçada)';
const P2S_TICK = '2026-12-01';

/** As tabelas da saída (as mesmas do teste). */
const P2S_TABLES = ['deals', 'contacts', 'relations', 'deal_lines', 'tasks', 'task_log', 'deal_history', 'stage_events', 'deal_rounds',
                    'cc_rv_items', 'fin_docs', 'deal_billing', 'users', 'team_members', 'departments', 'funnels', 'funnel_stages'];

/** Coluna => tabela que ela refere (para trocar os ids das linhas criadas). */
const P2S_REFS = [
    'deal_id' => 'deals', 'xsell_from_id' => 'deals', 'result_deal_id' => 'deals',
    'line_id' => 'deal_lines', 'start_after_id' => 'deal_lines', 'merged_into_id' => 'deal_lines',
    'task_id' => 'tasks', 'item_id' => 'cc_rv_items', 'billing_id' => 'deal_billing',
    'contact_id' => 'contacts', 'company_id' => 'contacts', 'person_id' => 'contacts', 'brought_by_id' => 'contacts',
    'owner_id' => 'users', 'exec_owner_id' => 'users', 'created_by' => 'users', 'user_id' => 'users', 'assignee_id' => 'users',
    'help_by' => 'users', 'approved_by' => 'users', 'xray_validated_by' => 'users', 'first_response_by' => 'users',
    'head_user_id' => 'users', 'reports_to' => 'users',
    'member_id' => 'team_members', 'team_member_id' => 'team_members',
    'funnel_id' => 'funnels', 'origin_funnel_id' => 'funnels', 'from_funnel_id' => 'funnels', 'to_funnel_id' => 'funnels',
    'stage_id' => 'funnel_stages', 'from_stage_id' => 'funnel_stages', 'to_stage_id' => 'funnel_stages', 'lost_stage_id' => 'funnel_stages',
];

/** O prefixo das chaves dos alertas => a tabela do id que vem a seguir. */
const P2S_ALERT_REFS = ['task' => 'tasks', 'help' => 'tasks', 'deal' => 'deals', 'ldl' => 'deals', 'flow' => 'deal_history'];

function p2s_fail(string $msg): void {
    fwrite(STDERR, "pipe2_v1_scenario: $msg\n");
    exit(1);
}

$opt = getopt('', ['db:', 'cols:', 'skip:']);
$db = (string)($opt['db'] ?? '');
if (!preg_match(PIPE2_TMPDB_RE, $db)) p2s_fail('--db tem de ser uma base privada topper_p2_<pid>_<n>.');
$skip = array_filter(explode(',', (string)($opt['skip'] ?? '')));
$tp = pipe2_tmpdb_pdo($db);
pipe2_use($tp);
$ver = (int)$tp->query("SELECT svalue FROM settings WHERE skey = 'schema_version'")->fetchColumn();
if ($ver !== SCHEMA_VERSION) p2s_fail("a base está na $ver e este código espera a " . SCHEMA_VERSION . ' (migre-a com este código).');

// ── colunas, tipos e o «antes» ──────────────────────────────

$types = [];   // tabela => coluna => DATA_TYPE
$st = $tp->prepare('SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ORDINAL_POSITION');
foreach (P2S_TABLES as $t) {
    $st->execute([$t]);
    foreach ($st->fetchAll() as $c) $types[$t][$c['COLUMN_NAME']] = strtolower((string)$c['DATA_TYPE']);
}
$cols = [];
if (!empty($opt['cols'])) {
    $j = json_decode((string)@file_get_contents((string)$opt['cols']), true);
    if (!is_array($j)) p2s_fail('--cols não é um JSON {tabela: [colunas]}.');
    foreach (P2S_TABLES as $t) $cols[$t] = array_values(array_intersect((array)($j[$t] ?? []), array_keys($types[$t] ?? [])));
} else {
    foreach (P2S_TABLES as $t) $cols[$t] = array_keys($types[$t] ?? []);
}
$before = [];
foreach (P2S_TABLES as $t) if (isset($types[$t]['id'])) $before[$t] = (int)$tp->query("SELECT COALESCE(MAX(id), 0) FROM $t")->fetchColumn();

$today = today_lisbon();
$q = function (string $sql, array $a = []) use ($tp) { $s = $tp->prepare($sql); $s->execute($a); return $s; };
$col = fn(string $sql, array $a = []) => $q($sql, $a)->fetchColumn();

// ── os passos ───────────────────────────────────────────────

$GLOBALS['P2S_CALLS'] = [];
$GLOBALS['P2S_UNDO'] = [];

/** Corre um passo e guarda [rótulo, resultado, tabela dos ids do resultado]; uma exceção fica «!Classe: mensagem». */
function p2s_call(string $label, callable $fn, ?string $ref = null) {
    try {
        $r = $fn();
        $GLOBALS['P2S_CALLS'][] = [$label, $r, $ref];
        return $r;
    } catch (Throwable $e) {
        $GLOBALS['P2S_CALLS'][] = [$label, '!' . get_class($e) . ': ' . $e->getMessage(), null];
        return null;
    }
}

/** Como as páginas: guarda o «Anular» e devolve os dados guardados (com o que os ganchos lá tenham posto), ou null. */
function p2s_undo_push(string $label, string $kind, array $data): ?array {
    $token = undo_push($kind, $data);
    $saved = $token !== '' ? ($_SESSION['undo'][$token]['data'] ?? null) : null;
    $keys = is_array($saved) ? array_keys($saved) : [];
    sort($keys);
    $GLOBALS['P2S_UNDO'][] = [$label, $kind, $keys, $token !== ''];
    return $saved;
}

/** Mover o negócio como a ficha (deal_flow.php «move»): retrato, deal_move, «Anular». Devolve os dados do «Anular». */
function p2s_move(PDO $tp, string $label, int $dealId, int $to, string $force = P2S_FORCE): ?array {
    $deal = deal_get($tp, $dealId);
    $snap = undo_deal_snapshot($deal);
    flow_created(null, true);
    $msg = p2s_call($label, fn() => deal_move($tp, $deal, $to, $force));
    if (!is_string($msg) || (int)$deal['stage_id'] === $to) return null;
    return p2s_undo_push($label, 'deal_stage', $snap + ['to_stage' => $to, 'auto_tasks' => flow_created()]);
}

/** Mover uma frente como a ficha (deal_flow.php «move» com line): retrato, xsell_line_move, «Anular». */
function p2s_line_move(PDO $tp, string $label, int $dealId, int $lineId, int $to, string $force = P2S_FORCE): ?array {
    $snap = ['id' => $dealId, 'lines' => xsell_snapshot($tp, $dealId), 'line' => $lineId, 'to_stage' => $to];
    flow_created(null, true);
    $msg = p2s_call($label, fn() => xsell_line_move($tp, deal_get($tp, $dealId), xsell_line_get($tp, $lineId), $to, $force));
    if (!is_string($msg)) return null;
    return p2s_undo_push($label, 'line_stage', $snap + ['auto_tasks' => flow_created()]);
}

/** Fechar uma tarefa como a ficha (deal_flow.php «task_done»): retrato, task_done, task_log, «Anular». */
function p2s_done(PDO $tp, string $label, int $taskId): ?array {
    $t = task_by_id($tp, $taskId);
    if (!$t) { p2s_call($label, fn() => throw new RuntimeException('tarefa do cenário não encontrada')); return null; }
    $snap = ['id' => (int)$t['id'], 'status' => $t['status'], 'done_at' => $t['done_at'], 'member_id' => $t['member_id'], 'position' => $t['position'], 'help_by' => $t['help_by'] ?? null];
    $spawned = p2s_call($label, function () use ($tp, $taskId) {
        $s = task_done($tp, $taskId);
        task_log($tp, $taskId, 'done', 'Concluída');
        return $s;
    }, 'tasks');
    $snap['spawned'] = $spawned;
    return p2s_undo_push($label, 'task', $snap);
}

/** Uma lead nova (lead_create, como o formulário) convertida para $funnel (lead_convert, com a empresa dela). */
function p2s_lead(PDO $tp, string $label, string $company, int $funnel, ?int $owner = 1): int {
    $id = (int)p2s_call("$label: criar a lead", fn() => lead_create($tp, lead_input([
        'company' => $company, 'person' => 'P2T · Pessoa de ' . $company, 'email' => 'p2t.' . substr(md5($company), 0, 8) . '@exemplo.pt',
        'phone' => '+351 910 000 000', 'service' => 'VFX / 3D', 'priority' => 'alta', 'origin' => 'manual', 'notes' => 'P2T · cenário v1',
    ]), $owner, 1), 'deals');
    $lead = lead_get($tp, $id);
    p2s_call("$label: converter", fn() => lead_convert($tp, $lead, $funnel, (int)($lead['company_id'] ?? 0)));
    return $id;
}

/** A 1.ª tarefa aberta do negócio que cumpre $where (por id). */
function p2s_task(PDO $tp, int $dealId, string $where, array $a = []): int {
    $st = $tp->prepare("SELECT id FROM tasks WHERE deal_id = ? AND status <> 'done' AND ($where) ORDER BY id LIMIT 1");
    $st->execute(array_merge([$dealId], $a));
    return (int)$st->fetchColumn();
}

$u1 = $q('SELECT * FROM users WHERE id = 1')->fetch();
if (!$u1) p2s_fail('a base não tem o utilizador 1.');
$_SESSION = ['user' => session_user_fields($u1)];

$vfx = (int)$col("SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY position, id LIMIT 1");
$cgi = (int)$col("SELECT id FROM funnels WHERE xray_kind = 'cgi' AND is_base = 1 ORDER BY position, id LIMIT 1");
$buddy = (int)$col("SELECT id FROM funnels WHERE xray_kind = 'buddy' ORDER BY position, id LIMIT 1");
if (!$vfx || !$cgi) p2s_fail('a base não tem os funis VFX e CGI.');
$sv = [
    'xray' => (int)funnel_fixed_stage($tp, $vfx, 'xray'), 'proposal' => (int)(flow_stage_with($tp, $vfx, 'proposal')['id'] ?? 0),
    'feedback' => (int)(flow_stage_with($tp, $vfx, 'feedback')['id'] ?? 0), 'kickoff' => (int)funnel_fixed_stage($tp, $vfx, 'kickoff'),
    'done' => (int)funnel_fixed_stage($tp, $vfx, 'done'),
];
$prodV = array_map(fn($s) => (int)$s['id'], deal_funnel_prod_stages($tp, $vfx));
$prodC = array_map(fn($s) => (int)$s['id'], deal_funnel_prod_stages($tp, $cgi));
$cgiXray = (int)funnel_fixed_stage($tp, $cgi, 'xray');
$cgiProp = (int)(flow_stage_with($tp, $cgi, 'proposal')['id'] ?? 0);
$cgiDone = (int)funnel_fixed_stage($tp, $cgi, 'done');
if (in_array(0, $sv, true) || count($prodV) < 4 || count($prodC) < 4 || !$cgiXray || !$cgiProp || !$cgiDone) p2s_fail('o VFX ou o CGI não têm as etapas esperadas.');

// 0. o utilizador de teste (com o seu membro da equipa)
$tp->prepare('INSERT INTO team_members (name, color, position) VALUES (?, ?, ?)')->execute(['P2T · Membro de teste', '', 99]);
$memT = (int)$tp->lastInsertId();
$tp->prepare("INSERT INTO users (email, name, password_hash, role, is_active, team_member_id) VALUES (?, ?, '', 'member', 1, ?)")
    ->execute(['p2t.cenario@exemplo.pt', 'P2T · Utilizador de teste', $memT]);
$uidT = (int)$tp->lastInsertId();

// 1. a lead convertida para o VFX, do X-Ray ao Concluído
$L = p2s_lead($tp, 'lead', 'P2T · Cliente do cenário', $vfx);
p2s_call('lead: X-Ray → Kickoff sem forçar', fn() => deal_move($tp, deal_get($tp, $L), $sv['kickoff']));
$gate = p2s_task($tp, $L, "stage_id = ? AND is_gate = 1 AND doc_kind = ''", [$sv['xray']]);
$u = p2s_done($tp, 'lead: fechar a obrigatória do X-Ray', $gate);
if ($u) p2s_call('lead: anular o fecho da obrigatória', fn() => undo_apply($tp, 'task', $u));
p2s_done($tp, 'lead: fechar outra vez a obrigatória do X-Ray', $gate);
p2s_call('lead: mover para a etapa onde já está', fn() => deal_move($tp, deal_get($tp, $L), $sv['xray']));
p2s_move($tp, 'lead: X-Ray → Proposta', $L, $sv['proposal']);
p2s_move($tp, 'lead: Proposta → Aguardar feedback', $L, $sv['feedback']);
$u = p2s_move($tp, 'lead: Aguardar feedback → Kickoff', $L, $sv['kickoff']);
if ($u) p2s_call('lead: anular o Kickoff', fn() => undo_apply($tp, 'deal_stage', $u));
p2s_move($tp, 'lead: Kickoff outra vez', $L, $sv['kickoff']);
// o pagamento de adjudicação validado e a fatura de um pedido do Financeiro emitida
$pay = p2s_task($tp, $L, "stage_id = ? AND doc_kind = 'payment'", [$sv['kickoff']]);
p2s_call('lead: validar o pagamento de adjudicação', fn() => billing_validate_payment($tp, deal_get($tp, $L), task_by_id($tp, $pay),
    ['doc_date' => $today, 'total' => '1500,00', 'note' => 'P2T · adjudicação'], null, 1));
$now = date('Y-m-d H:i:s');
$tp->prepare("INSERT INTO fin_docs (direction, doc_type, deal_id, party_name, number, description, base, vat_rate, vat, total, issue_date, status, created_by, created_at, updated_at)
              VALUES ('in', 'invoice', ?, 'P2T · Cliente do cenário', 'P2T-FT 1/1', 'P2T · adjudicação', 1000, 23, 230, 1230, ?, 'requested', 1, ?, ?)")
    ->execute([$L, $today, $now, $now]);
$fdoc = (int)$tp->lastInsertId();
p2s_call('lead: a fatura pedida ao Financeiro fica emitida', fn() => fin_doc_issued($tp, $fdoc, 1));
foreach ($prodV as $i => $sid) {
    p2s_move($tp, 'lead: produção ' . ($i + 1), $L, $sid);
    if ($i === 1) {
        // a peça de Review e as tarefas de aprovação que ela fecha (o look)
        $item = p2s_call('lead: criar a peça de Review', fn() => cc_rv_item_create($tp, deal_get($tp, $L), 'P2T · Shot de referência', 'styleframes', 'video', null, 1), 'cc_rv_items');
        if ($item) {
            p2s_call('lead: as tarefas de aprovação da peça', fn() => array_map(fn($t) => (int)$t['id'], cc_rv_approval_tasks($tp, cc_rv_item($tp, (int)$item))), 'tasks');
            p2s_call('lead: as rondas da peça', fn() => cc_rv_round_info($tp, cc_rv_item($tp, (int)$item)));
        }
    }
    if ($i === 2) {
        // as repetições: a diária e a semanal fecham (nasce a seguinte); a semanal reabre
        $daily = p2s_task($tp, $L, "stage_id = ? AND repeat_rule = 'daily'", [$sid]);
        p2s_done($tp, 'lead: fechar a tarefa diária', $daily);
        if (!in_array('repeat', $skip, true)) {
            $weekly = p2s_task($tp, $L, "stage_id = ? AND repeat_rule = 'weekly'", [$sid]);
            p2s_done($tp, 'lead: fechar a tarefa semanal', $weekly);
            $wm = task_by_id($tp, $weekly)['member_id'] ?? null;
            p2s_call('lead: reabrir a tarefa semanal', fn() => task_reopen($tp, $weekly, $wm !== null ? (int)$wm : null), 'tasks');
        }
    }
    if ($i === 3) {
        $stg = stage_get($tp, $sid);
        p2s_call('lead: ronda 1', fn() => rounds_add($tp, deal_get($tp, $L), $stg, 'P2T · ronda 1', 1));
        p2s_call('lead: ronda 2', fn() => rounds_add($tp, deal_get($tp, $L), $stg, 'P2T · ronda 2', 1));
    }
}
$u = p2s_move($tp, 'lead: Entrega → Concluído', $L, $sv['done']);
if ($u) p2s_call('lead: anular o Concluído', fn() => undo_apply($tp, 'deal_stage', $u));
p2s_move($tp, 'lead: Concluído outra vez', $L, $sv['done']);
$fin = p2s_task($tp, $L, "stage_id = ? AND doc_kind = 'payment'", [$sv['done']]);
p2s_call('lead: validar o pagamento final', fn() => billing_validate_payment($tp, deal_get($tp, $L), task_by_id($tp, $fin),
    ['doc_date' => $today, 'total' => '2000', 'note' => 'P2T · final'], null, 1));
p2s_call('lead: pago e entregue', fn() => [(int)deal_get($tp, $L)['is_paid'], (string)deal_get($tp, $L)['phase']]);

// 2. o crosselling v1: VFX + CGI, timelines diferentes, a CGI arranca numa data
$X = p2s_lead($tp, 'crosselling', 'P2T · Cliente do crosselling', $vfx);
p2s_call('crosselling: juntar o CGI', fn() => xsell_set_services($tp, deal_get($tp, $X), [$cgi], 1));
$lines = array_column(xsell_lines($tp, $X), null, 'funnel_id');
$lv = (int)($lines[$vfx]['id'] ?? 0);
$lc = (int)($lines[$cgi]['id'] ?? 0);
p2s_call('crosselling: as frentes', fn() => [$lv > 0, $lc > 0, xsell_is_multi($tp, deal_get($tp, $X))]);
p2s_call('crosselling: requisitos antes do plano', fn() => xsell_requirements($tp, deal_get($tp, $X)));
p2s_call('crosselling: o plano das frentes', fn() => xsell_save_plan($tp, deal_get($tp, $X), [
    'sync_mode' => 'independent', 'start' => [$lc => 'date'], 'start_date' => [$lc => P2S_TICK], 'delivery' => [$lv => '2027-01-15', $lc => '2027-02-15'],
], 1));
p2s_call('crosselling: requisitos depois do plano', fn() => xsell_requirements($tp, deal_get($tp, $X)));
p2s_move($tp, 'crosselling: X-Ray → Proposta', $X, $sv['proposal']);
p2s_move($tp, 'crosselling: Proposta → Aguardar feedback', $X, $sv['feedback']);
p2s_move($tp, 'crosselling: Aguardar feedback → Kickoff', $X, $sv['kickoff']);
p2s_call('crosselling: o negócio para uma etapa de produção', fn() => deal_move($tp, deal_get($tp, $X), $prodV[0], P2S_FORCE));
$u = p2s_line_move($tp, 'crosselling: frente principal para a 1.ª etapa', $X, $lv, $prodV[0]);
if ($u) p2s_call('crosselling: anular o movimento da frente', fn() => undo_apply($tp, 'line_stage', $u));
p2s_line_move($tp, 'crosselling: frente principal para a 1.ª etapa outra vez', $X, $lv, $prodV[0]);
p2s_call('crosselling: frente principal para o Concluído antes das outras etapas, sem forçar', fn() => xsell_line_move($tp, deal_get($tp, $X), xsell_line_get($tp, $lv), $sv['done']));
p2s_call('crosselling: o que falta para concluir (antes do relógio)', fn() => xsell_close_missing($tp, deal_get($tp, $X)));
p2s_call('crosselling: a peça de Review sem frente', fn() => cc_rv_item_create($tp, deal_get($tp, $X), 'P2T · Peça do negócio', 'finais', 'video', null, 1), 'cc_rv_items');
p2s_call('crosselling: a etapa das rondas da frente CGI', fn() => cc_rv_stage_for($tp, deal_get($tp, $X), $lc), 'funnel_stages');
// o relógio da v1 (a frente CGI arranca na data dela), com a data fixa do cenário
p2s_call('relógio: flow_tick na data fixa', fn() => flow_tick($tp, P2S_TICK));
p2s_call('crosselling: as frentes depois do relógio', fn() => array_map(fn($l) => [(int)$l['funnel_id'], (string)$l['status'], $l['stage_id'] !== null ? (int)$l['stage_id'] : null],
    xsell_lines($tp, $X)), 'funnel_stages');
$ci = p2s_call('crosselling: a peça de Review da frente CGI', fn() => cc_rv_item_create($tp, deal_get($tp, $X), 'P2T · Peça do CGI', 'styleframes', 'image', $lc, 1), 'cc_rv_items');
if ($ci) p2s_call('crosselling: as tarefas de aprovação da peça CGI', fn() => array_map(fn($t) => (int)$t['id'], cc_rv_approval_tasks($tp, cc_rv_item($tp, (int)$ci))), 'tasks');
foreach ($prodV as $i => $sid) if ($i > 0) p2s_line_move($tp, 'crosselling: frente VFX, etapa ' . ($i + 1), $X, $lv, $sid);
foreach ($prodC as $i => $sid) p2s_line_move($tp, 'crosselling: frente CGI, etapa ' . ($i + 1), $X, $lc, $sid);
p2s_call('crosselling: o que falta para concluir (frentes nas entregas)', fn() => xsell_close_missing($tp, deal_get($tp, $X)));
p2s_line_move($tp, 'crosselling: frente VFX concluída', $X, $lv, $sv['done']);
p2s_line_move($tp, 'crosselling: frente CGI concluída', $X, $lc, $cgiDone);
p2s_call('crosselling: o que falta para concluir (frentes concluídas)', fn() => xsell_close_missing($tp, deal_get($tp, $X)));
p2s_move($tp, 'crosselling: Concluído', $X, $sv['done']);
p2s_call('crosselling: avença?', fn() => array_map(fn($l) => xsell_is_recurring($l), xsell_lines($tp, $X)));
p2s_call('condições de pagamento por defeito', fn() => [flow_payment_default($tp, 1000.0, $vfx), flow_payment_default($tp, 50000.0, $vfx),
    $buddy ? flow_payment_default($tp, 1000.0, $buddy) : '']);

// 3. mudar de funil (VFX → CGI) e andar no funil novo
$C = p2s_lead($tp, 'mudar de funil', 'P2T · Cliente que muda de funil', $vfx);
p2s_call('mudar de funil: VFX → CGI', fn() => deal_change_funnel($tp, deal_get($tp, $C), $cgi, $cgiXray));
p2s_move($tp, 'mudar de funil: X-Ray → Proposta no CGI', $C, $cgiProp);

// 4. a saída do utilizador de teste: os negócios, a execução e as tarefas dele passam para o uid 1
$D = p2s_lead($tp, 'saída', 'P2T · Cliente do utilizador de teste', $vfx, $uidT);
$tp->prepare('UPDATE deals SET exec_owner_id = ? WHERE id = ?')->execute([$uidT, $D]);
p2s_move($tp, 'saída: X-Ray → Proposta', $D, $sv['proposal']);
p2s_call('saída: reatribuir o trabalho', fn() => team_reassign($tp, $q('SELECT * FROM users WHERE id = ?', [$uidT])->fetch(), $u1));

// 5. um funil novo e os alertas do dono
p2s_call('funil novo', fn() => funnel_create($tp, 'P2T · Funil do cenário', [['Pré-produção', 'commercial'], ['Rodagem', 'production'], ['Montagem', 'production']]), 'funnels');
$alerts = p2s_call('alertas do dono', fn() => my_alerts($tp));

// ── normalizar e imprimir ───────────────────────────────────

/** «D+n» (dias a partir de hoje) de uma data AAAA-MM-DD; o resto fica. */
function p2s_day(string $d, string $today): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d === '0000-00-00') return $d;
    $n = (int)round((strtotime($d . ' 12:00:00') - strtotime($today . ' 12:00:00')) / 86400);
    return 'D' . ($n >= 0 ? '+' : '') . $n;
}

/** Um valor qualquer (resultado de um passo): os ids de $ref trocados, datas em «D+n», horas fora. */
function p2s_any($v, ?string $ref, array $map, string $today) {
    if (is_array($v)) {
        $o = [];
        foreach ($v as $k => $x) $o[$k] = p2s_any($x, $ref, $map, $today);
        return $o;
    }
    if ($ref !== null && (is_int($v) || (is_string($v) && ctype_digit($v))) && isset($map[$ref][(int)$v])) return $map[$ref][(int)$v];
    if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $v)) return '(hora)';
    if (is_string($v)) return p2s_day($v, $today);
    return $v;
}

// as linhas criadas (por id; relations pelos contactos criados) e o mapa dos ids
$raw = [];
$map = [];
foreach (P2S_TABLES as $t) {
    if (!isset($types[$t])) continue;
    if (isset($types[$t]['id'])) {
        $raw[$t] = $q("SELECT * FROM $t WHERE id > ? ORDER BY id", [$before[$t]])->fetchAll();
        foreach ($raw[$t] as $i => $r) $map[$t][(int)$r['id']] = $t . '#' . ($i + 1);
    }
}
$newContacts = array_keys($map['contacts'] ?? []);
$raw['relations'] = isset($types['relations']) && $newContacts
    ? $q('SELECT * FROM relations WHERE contact_id IN (' . pipe2_ids_sql($newContacts) . ') ORDER BY contact_id')->fetchAll() : [];

$rows = [];
$touched = 0;
foreach (P2S_TABLES as $t) {
    if (!isset($types[$t])) continue;
    $rows[$t] = [];
    foreach ($raw[$t] ?? [] as $r) {
        $o = [];
        foreach ($cols[$t] as $c) {
            $v = $r[$c] ?? null;
            $ty = $types[$t][$c] ?? '';
            if ($v === null) $o[$c] = null;
            elseif ($ty === 'datetime' || $ty === 'timestamp') $o[$c] = '(hora)';
            elseif ($ty === 'date') $o[$c] = p2s_day((string)$v, $today);
            elseif ($c === 'id') $o[$c] = $map[$t][(int)$v] ?? $v;
            elseif (isset(P2S_REFS[$c]) && isset($map[P2S_REFS[$c]][(int)$v]) && (string)(int)$v === (string)$v) $o[$c] = $map[P2S_REFS[$c]][(int)$v];
            else $o[$c] = $v;
        }
        $rows[$t][] = $o;
        $touched++;
    }
}

// as linhas que já existiam: [linhas, soma dos CRC32] sem as colunas de horas
$dump = [];
foreach (P2S_TABLES as $t) {
    if (!isset($types[$t])) continue;
    $cs = array_values(array_filter($cols[$t], fn($c) => !in_array($types[$t][$c] ?? '', ['datetime', 'timestamp'], true)));
    if (!$cs) continue;
    $expr = 'CONCAT_WS(\'|\', ' . implode(', ', array_map(fn($c) => "COALESCE(CAST(`$c` AS CHAR), '\\\\N')", $cs)) . ')';
    $where = isset($types[$t]['id']) ? 'id <= ' . (int)$before[$t] : ($newContacts ? 'contact_id NOT IN (' . pipe2_ids_sql($newContacts) . ')' : '1=1');
    $r = $tp->query("SELECT COUNT(*), COALESCE(SUM(CRC32($expr)), 0) FROM $t WHERE $where")->fetch(PDO::FETCH_NUM);
    $dump[$t] = [(int)$r[0], (string)$r[1]];
}

// os alertas: as chaves, por grupo, com os ids das linhas criadas trocados
$ak = [];
foreach ((array)$alerts as $g => $items) {
    $keys = [];
    foreach ((array)$items as $a) {
        $seg = explode(':', (string)($a['key'] ?? ''));
        $ref = P2S_ALERT_REFS[$seg[0]] ?? null;
        foreach ($seg as $i => $s) {
            if ($i === 1 && $ref !== null && ctype_digit($s) && isset($map[$ref][(int)$s])) $seg[$i] = $map[$ref][(int)$s];
            else $seg[$i] = p2s_day($s, $today);
        }
        $keys[] = implode(':', $seg);
    }
    sort($keys);
    $ak[$g] = $keys;
}

$calls = [];
foreach ($GLOBALS['P2S_CALLS'] as [$label, $r, $ref]) {
    if ($label === 'alertas do dono') $r = is_array($r) ? array_keys($r) : $r;
    $calls[] = [$label, p2s_any($r, $ref, $map, $today)];
}

$dealIds = array_keys($map['deals'] ?? []);
echo json_encode([
    'cmp'  => ['calls' => $calls, 'rows' => $rows, 'dump' => $dump, 'alerts' => $ak, 'undo' => $GLOBALS['P2S_UNDO']],
    'meta' => ['schema' => SCHEMA_VERSION, 'files' => get_included_files(), 'deals' => $dealIds, 'touched' => $touched],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
