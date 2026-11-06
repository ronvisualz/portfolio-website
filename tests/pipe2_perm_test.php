<?php
// Pipeline v2, fase 1 — admin/inc/pipe2_access.php: a leitura por frente e as ações de frente (D23). Funções de sessão,
// só de leitura, ainda sem ecrãs (a fase 2 liga-as):
//   - ler pela frente = ligado a uma frente depois do Kickoff (responsável, executor interno ou com tarefas nela) de um
//     negócio v2 em produção ou concluído, e sempre com Negócios (também em pipe2_front_reach_sql); nunca dá edição;
//   - agir numa frente = admin, quem edita o negócio (flow_can_edit) ou o responsável da frente (só
//     deal_lines.exec_owner_id) com Negócios EDIT; o interruptor do avanço = admin ou esse responsável;
//   - pedir fatura = flow_can_bill num negócio v2.
// Corre numa base privada (p2v_db: dump da 42 migrado até à SCHEMA_VERSION). Todas as verificações com sessão vão por
// p2v_probe (um processo por utilizador: a my_grid() e a me_row() guardam o primeiro utilizador numa cache estática);
// os estados de cada caso preparam-se aqui e desfazem-se depois. Não escreve na bancada.
//   php tests/pipe2_perm_test.php
// Casos: ::premissas ::front_reader_read_only ::not_before_kickoff ::front_actions ::front_switch ::can_bill ::reach_sql
//        ::no_side_effects
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';

/** As tabelas cujo retrato não pode mudar com as chamadas (::no_side_effects). */
const P2P_SIG_TABLES = ['deals', 'deal_lines', 'deal_line_execs', 'tasks', 'permissions'];

$GLOBALS['p2p_diffs'] = [];
$GLOBALS['p2p_probes'] = 0;

/** Um utilizador de teste «P2T <nome>» (colaborador, sem departamento), com exceções de permissões [aba => nível]. */
function p2p_user(PDO $pdo, string $name, array $perm = []): int {
    $id = pipe2_insert($pdo, 'users', [
        'email' => 'p2t.' . bin2hex(random_bytes(4)) . '@p2t.invalid', 'name' => 'P2T ' . $name, 'password_hash' => '',
        'role' => 'member', 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'department_id' => null,
    ]);
    foreach ($perm as $tab => $lvl) {
        $pdo->prepare("INSERT INTO permissions (role, user_id, tab, level, scope) VALUES ('', ?, ?, ?, NULL)")->execute([$id, $tab, $lvl]);
    }
    return $id;
}

/**
 * As chamadas [rótulo => [fn, args…]] num processo com a sessão de $uid; devolve [rótulo => resultado]. Um erro do probe
 * (repetido uma vez: a bancada é partilhada) falha o caso e dá 'ERRO' a todos os rótulos. Tira o retrato das tabelas
 * antes e depois (::no_side_effects).
 */
function p2p_run(string $db, PDO $tp, int $uid, array $named, string $case): array {
    $calls = [];
    foreach ($named as $label => $c) $calls[] = ['fn' => $c[0], 'args' => array_slice($c, 1)];
    $before = p2v_sig($tp, P2P_SIG_TABLES);
    $r = p2v_probe($db, $uid, $calls);
    if (isset($r['error'])) $r = p2v_probe($db, $uid, $calls);
    $after = p2v_sig($tp, P2P_SIG_TABLES);
    $GLOBALS['p2p_probes']++;
    if ($before !== $after) $GLOBALS['p2p_diffs'][] = "uid {$uid} ({$case}): " . implode('; ', pipe2_diff($before, $after));
    if (isset($r['error'])) {
        t_true(false, "{$case}: o probe do utilizador {$uid} deu erro: {$r['error']}");
        return array_fill_keys(array_keys($named), 'ERRO');
    }
    return array_combine(array_keys($named), $r);
}

/** Os ids dos negócios que a condição devolve (SELECT <alias>.id FROM deals <alias> WHERE <sql>), em modo estrito. */
function p2p_reach_ids(PDO $tp, $res, string $alias = 'd'): ?array {
    if (!is_array($res) || count($res) !== 2 || !is_string($res[0] ?? null) || !is_array($res[1] ?? null)) return null;
    $mode = (string)$tp->query('SELECT @@SESSION.sql_mode')->fetchColumn();
    pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES');
    try {
        return array_map('intval', array_column(p2v_rows($tp, "SELECT {$alias}.id FROM deals {$alias} WHERE {$res[0]} ORDER BY {$alias}.id", $res[1]), 'id'));
    } finally {
        pipe2_sql_mode($tp, $mode);
    }
}

[$db, $tp] = p2v_db();

// ── ::premissas: utilizadores e permissões ──────────────────

$tp->prepare("INSERT INTO permissions (role, user_id, tab, level, scope) VALUES ('', 3, 'negocios', 1, NULL)")->execute();   // o 3 só VÊ Negócios
$M = p2p_user($tp, 'membro');                              // Negócios EDIT por defeito, âmbito own
$X = p2p_user($tp, 'executor');                            // Negócios EDIT por defeito, âmbito own
$S = p2p_user($tp, 'sem negócios', ['negocios' => 0]);     // sem Negócios
$level = function (int $uid) use ($tp): ?int {
    $row = p2v_rows($tp, 'SELECT * FROM users WHERE id = ?', [$uid])[0] ?? null;
    return $row ? (int)(perm_grid($tp, $row)['negocios']['level'] ?? -1) : null;
};
$want = [3 => PERM_VIEW, $M => PERM_EDIT, $X => PERM_EDIT, $S => PERM_NONE, 99 => PERM_EDIT];
$bad = [];
foreach ($want as $uid => $lvl) if ($level($uid) !== $lvl) $bad[] = "utilizador {$uid}: Negócios " . var_export($level($uid), true) . " (esperado {$lvl})";
$admin1 = p2v_rows($tp, 'SELECT role FROM users WHERE id = 1')[0]['role'] ?? '';
if (!role_is_admin((string)$admin1)) $bad[] = 'o utilizador 1 não é admin';
$tm3 = p2v_col($tp, 'SELECT team_member_id FROM users WHERE id = 3');
if (!$tm3) $bad[] = 'o utilizador 3 não tem team_member_id';
if ($bad) {
    t_true(false, '::premissas: premissa das permissões errada: ' . implode('; ', $bad));
    t_done();
}
$tm3 = (int)$tm3;
t_true(true, '::premissas: Negócios VER para o 3, EDIT para o membro, o executor e o 99, nenhum para o sem negócios');

// ── os negócios ─────────────────────────────────────────────

$w = p2v_world($tp, ['vfx', '3d']);
// A: o negócio v2 principal, em execução; vfx com o membro responsável e o executor interno, 3d com o 3 responsável.
$A = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'owner' => 99]);
$Av = $A['lines']['vfx'];
$A3 = $A['lines']['3d'];
p2v_front($tp, $Av, ['exec_owner_id' => $M]);
p2v_front($tp, $A3, ['exec_owner_id' => 3]);
pipe2_insert($tp, 'deal_line_execs', ['deal_id' => $A['id'], 'line_id' => $Av, 'user_id' => $X, 'position' => 0, 'created_at' => date('Y-m-d H:i:s')]);
// B: outro negócio v2 em execução, sem vínculos.
$B = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec', 'owner' => 99]);
$Bv = $B['lines']['vfx'];
// P: um negócio v2 ainda no Pipeline (Proposta), com o 3 responsável da frente 3d (planned).
$P = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'proposal', 'status' => 'planned', 'owner' => 99]);
p2v_front($tp, $P['lines']['3d'], ['exec_owner_id' => 3]);
// V: um negócio v1 em produção (funil de 5 etapas), com o mesmo vínculo do 3 numa frente com um estado da v2.
$fv1 = pipe2_funnel($tp, 'v1');
$V = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $fv1, 'owner_id' => 99]);
$Vl = pipe2_front($tp, $V, $fv1, ['status' => 'running', 'exec_owner_id' => 3, 'is_main' => 1]);

$dA = ['deal' => $A['id']];
$dB = ['deal' => $B['id']];
$dP = ['deal' => $P['id']];
$dV = ['deal' => $V];
$lAv = ['line' => $Av];
$lA3 = ['line' => $A3];
$lBv = ['line' => $Bv];
$lV = ['line' => $Vl];

// ── estado de base ──────────────────────────────────────────

$r1 = p2p_run($db, $tp, 1, [
    'v2_A' => ['pipe2_deal_is_v2', $dA], 'v2_B' => ['pipe2_deal_is_v2', $dB], 'v2_P' => ['pipe2_deal_is_v2', $dP], 'v2_V' => ['pipe2_deal_is_v2', $dV],
    'act_Av' => ['pipe2_can_front_act', $dA, $lAv], 'act_A3' => ['pipe2_can_front_act', $dA, $lA3],
    'act_A_Bv' => ['pipe2_can_front_act', $dA, $lBv], 'act_V' => ['pipe2_can_front_act', $dV, $lV],
    'sw_Av' => ['pipe2_can_front_switch', $dA, $lAv], 'sw_A_Bv' => ['pipe2_can_front_switch', $dA, $lBv], 'sw_V' => ['pipe2_can_front_switch', $dV, $lV],
    'bill_A' => ['pipe2_can_request_invoice', $dA], 'bill_V' => ['pipe2_can_request_invoice', $dV],
], 'base');
t_eq([$r1['v2_A'], $r1['v2_B'], $r1['v2_P'], $r1['v2_V']], [true, true, true, false], 'premissa: A, B e P são v2, V é v1');
t_eq($r1['act_Av'], true, '::front_actions: o admin age na frente vfx');
t_eq($r1['act_A3'], true, '::front_actions: o admin age na frente 3d');
t_eq($r1['act_A_Bv'], false, '::front_actions: a frente de outro negócio não conta (nem para o admin)');
t_eq($r1['act_V'], false, '::front_actions: num negócio v1, nem o admin age pela v2');
t_eq($r1['sw_Av'], true, '::front_switch: o admin muda o interruptor');
t_eq($r1['sw_A_Bv'], false, '::front_switch: a frente de outro negócio não conta (nem para o admin)');
t_eq($r1['sw_V'], false, '::front_switch: num negócio v1, nem o admin');
t_eq($r1['bill_A'], true, '::can_bill: o admin pede fatura num negócio v2');
t_eq($r1['bill_V'], false, '::can_bill: num negócio v1, nem o admin pede fatura pela v2');

$r99 = p2p_run($db, $tp, 99, [
    'fce' => ['flow_can_edit', $dA],
    'act_Av' => ['pipe2_can_front_act', $dA, $lAv], 'act_A3' => ['pipe2_can_front_act', $dA, $lA3],
    'owner_Av' => ['pipe2_front_owner', $dA, $lAv],
    'sw_Av' => ['pipe2_can_front_switch', $dA, $lAv], 'sw_A3' => ['pipe2_can_front_switch', $dA, $lA3],
    'bill_A' => ['pipe2_can_request_invoice', $dA], 'bill_V' => ['pipe2_can_request_invoice', $dV],
    'reader_A' => ['pipe2_front_reader', $dA], 'reach' => ['pipe2_front_reach_sql'],
], 'base');
t_eq($r99['fce'], true, 'premissa: o 99 (dono) edita o negócio');
t_eq($r99['act_Av'], true, '::front_actions: o dono (flow_can_edit) age na frente vfx');
t_eq($r99['act_A3'], true, '::front_actions: o dono (flow_can_edit) age na frente 3d');
t_eq($r99['owner_Av'], false, '::front_switch: o dono não é o responsável da frente');
t_eq($r99['sw_Av'], false, '::front_switch: o dono, não responsável, não muda o interruptor');
t_eq($r99['sw_A3'], false, '::front_switch: o dono, não responsável, não muda o interruptor (3d)');
t_eq($r99['bill_A'], true, '::can_bill: o comercial dono pede fatura');
t_eq($r99['bill_V'], false, '::can_bill: num negócio v1, o dono não pede fatura pela v2');
t_eq($r99['reader_A'], false, '::front_reader_read_only: o dono sem vínculo a uma frente não lê pela frente');
t_eq(p2p_reach_ids($tp, $r99['reach']), [], '::reach_sql: o dono sem vínculo a frentes não tem negócios pela frente');

$r3 = p2p_run($db, $tp, 3, [
    'can_neg' => ['can', 'negocios', PERM_VIEW], 'can_neg_e' => ['can', 'negocios', PERM_EDIT],
    'rr_A' => ['record_reach', $dA], 'rr_A2' => ['record_reach', $dA, PERM_EDIT], 'fce_A' => ['flow_can_edit', $dA],
    'reader_A' => ['pipe2_front_reader', $dA], 'reader_B' => ['pipe2_front_reader', $dB], 'reader_P' => ['pipe2_front_reader', $dP],
    'reader_V' => ['pipe2_front_reader', $dV],
    'lines_A' => ['pipe2_front_lines_of', $A['id'], 3], 'lines_P' => ['pipe2_front_lines_of', $P['id'], 3], 'lines_V' => ['pipe2_front_lines_of', $V, 3],
    'lines_A0' => ['pipe2_front_lines_of', $A['id'], 0],
    'owner_A3' => ['pipe2_front_owner', $dA, $lA3],
    'act_A3' => ['pipe2_can_front_act', $dA, $lA3], 'sw_A3' => ['pipe2_can_front_switch', $dA, $lA3],
    'bill_A' => ['pipe2_can_request_invoice', $dA],
    'reach' => ['pipe2_front_reach_sql'], 'reach_x' => ['pipe2_front_reach_sql', 'x'],
], 'base');
t_eq([$r3['can_neg'], $r3['can_neg_e']], [true, false], 'premissa: o 3 vê Negócios e não edita');
t_eq($r3['rr_A'], false, '::front_reader_read_only: o 3 não chega ao negócio pelo âmbito (record_reach)');
t_eq($r3['reader_A'], true, '::front_reader_read_only: o 3, responsável da frente 3d, lê o negócio pela frente');
t_eq($r3['rr_A2'], false, '::front_reader_read_only: ler pela frente não dá edição (record_reach EDIT)');
t_eq($r3['fce_A'], false, '::front_reader_read_only: ler pela frente não dá edição (flow_can_edit)');
t_eq($r3['reader_B'], false, '::front_reader_read_only: sem vínculo, não lê');
t_eq($r3['reader_V'], false, '::front_reader_read_only: o mesmo vínculo num negócio v1 não dá leitura');
t_eq($r3['lines_A'], [$A3], '::front_reader_read_only: as frentes do 3 no negócio A são a 3d');
t_eq($r3['lines_V'], [], '::front_reader_read_only: num negócio v1 não há frentes de leitura');
t_eq($r3['lines_A0'], [], '::front_reader_read_only: sem utilizador (0), nenhuma frente');
t_eq($r3['reader_P'], false, '::not_before_kickoff: no Pipeline, o responsável de uma frente planned não lê pela frente');
t_eq($r3['lines_P'], [], '::not_before_kickoff: no Pipeline, pipe2_front_lines_of é []');
t_eq($r3['owner_A3'], true, '::front_actions: o 3 é o responsável da frente 3d');
t_eq($r3['act_A3'], false, '::front_actions: o 3 responsável, só com VER Negócios, não age');
t_eq($r3['sw_A3'], false, '::front_switch: o 3 responsável, só com VER Negócios, não muda o interruptor');
t_eq($r3['bill_A'], false, '::can_bill: o 3 não pede fatura');
t_eq(p2p_reach_ids($tp, $r3['reach']), [$A['id']], '::reach_sql: para o 3, exatamente o negócio v2 em produção (nem o do Pipeline, nem o v1)');
t_eq(p2p_reach_ids($tp, $r3['reach_x'], 'x'), [$A['id']], '::reach_sql: com outro alias do negócio');

$rM = p2p_run($db, $tp, $M, [
    'fce_A' => ['flow_can_edit', $dA], 'reader_A' => ['pipe2_front_reader', $dA], 'lines_A' => ['pipe2_front_lines_of', $A['id'], $M],
    'owner_Av' => ['pipe2_front_owner', $dA, $lAv],
    'act_Av' => ['pipe2_can_front_act', $dA, $lAv], 'act_A3' => ['pipe2_can_front_act', $dA, $lA3],
    'sw_Av' => ['pipe2_can_front_switch', $dA, $lAv], 'sw_A3' => ['pipe2_can_front_switch', $dA, $lA3],
    'reach' => ['pipe2_front_reach_sql'],
], 'base');
t_eq($rM['fce_A'], false, 'premissa: o membro não edita o negócio (âmbito own, dono 99)');
t_eq($rM['reader_A'], true, '::front_reader_read_only: o membro responsável lê pela frente');
t_eq($rM['lines_A'], [$Av], '::front_reader_read_only: as frentes do membro no negócio A são a vfx');
t_eq($rM['owner_Av'], true, '::front_actions: o membro é o responsável da frente vfx');
t_eq($rM['act_Av'], true, '::front_actions: o membro responsável, com Negócios EDIT, age na frente');
t_eq($rM['act_A3'], false, '::front_actions: outra pessoa (o membro na frente 3d) não age');
t_eq($rM['sw_Av'], true, '::front_switch: o membro responsável muda o interruptor');
t_eq($rM['sw_A3'], false, '::front_switch: o membro não muda o interruptor de outra frente');
t_eq(p2p_reach_ids($tp, $rM['reach']), [$A['id']], '::reach_sql: para o responsável, o negócio da frente dele');

$rX = p2p_run($db, $tp, $X, [
    'reader_A' => ['pipe2_front_reader', $dA], 'fce_A' => ['flow_can_edit', $dA], 'lines_A' => ['pipe2_front_lines_of', $A['id'], $X],
    'owner_Av' => ['pipe2_front_owner', $dA, $lAv],
    'act_Av' => ['pipe2_can_front_act', $dA, $lAv], 'sw_Av' => ['pipe2_can_front_switch', $dA, $lAv],
    'reach' => ['pipe2_front_reach_sql'],
], 'base');
t_eq($rX['reader_A'], true, '::front_reader_read_only: o executor interno (não responsável) lê pela frente');
t_eq($rX['fce_A'], false, '::front_reader_read_only: o executor interno não edita o negócio');
t_eq($rX['lines_A'], [$Av], '::front_reader_read_only: as frentes do executor são a vfx');
t_eq($rX['owner_Av'], false, '::front_actions: o executor interno não é o responsável da frente');
t_eq($rX['act_Av'], false, '::front_actions: o executor interno, com Negócios EDIT, não age');
t_eq($rX['sw_Av'], false, '::front_switch: o executor interno não muda o interruptor');
t_eq(p2p_reach_ids($tp, $rX['reach']), [$A['id']], '::reach_sql: para o executor interno, o negócio da frente');

$r0 = p2p_run($db, $tp, 0, [
    'reach' => ['pipe2_front_reach_sql'], 'reader_A' => ['pipe2_front_reader', $dA], 'owner_A3' => ['pipe2_front_owner', $dA, $lA3],
    'act_A3' => ['pipe2_can_front_act', $dA, $lA3], 'sw_A3' => ['pipe2_can_front_switch', $dA, $lA3], 'bill_A' => ['pipe2_can_request_invoice', $dA],
], 'sem sessão');
t_eq($r0['reach'], ['0=1', []], '::reach_sql: sem sessão, [\'0=1\', []]');
t_eq([$r0['reader_A'], $r0['owner_A3'], $r0['act_A3'], $r0['sw_A3'], $r0['bill_A']], [false, false, false, false, false],
     '::front_actions: sem sessão, nada (leitura, responsável, ações, interruptor, fatura)');

// Um alias que não é um identificador nunca chega ao SQL.
$bad = p2v_probe($db, 3, [['fn' => 'pipe2_front_reach_sql', 'args' => ['d; DROP TABLE deals']]]);
t_true(isset($bad['error']) && str_contains((string)$bad['error'], 'InvalidArgumentException') && str_contains((string)$bad['error'], 'alias inválido'),
       '::reach_sql: um alias inválido é recusado (' . pipe2_json($bad) . ')');

// ── frente juntada (merged) ─────────────────────────────────

p2v_front($tp, $A3, ['status' => 'merged']);
$r = p2p_run($db, $tp, 3, ['reader_A' => ['pipe2_front_reader', $dA], 'lines_A' => ['pipe2_front_lines_of', $A['id'], 3], 'reach' => ['pipe2_front_reach_sql']], 'merged');
t_eq($r['reader_A'], false, '::front_reader_read_only: numa frente merged, não lê');
t_eq($r['lines_A'], [], '::front_reader_read_only: uma frente merged não conta');
t_eq(p2p_reach_ids($tp, $r['reach']), [], '::reach_sql: uma frente merged não conta');
$r = p2p_run($db, $tp, 1, ['act_A3' => ['pipe2_can_front_act', $dA, $lA3], 'sw_A3' => ['pipe2_can_front_switch', $dA, $lA3]], 'merged');
t_eq([$r['act_A3'], $r['sw_A3']], [false, false], '::front_actions/::front_switch: numa frente merged, nem o admin');
p2v_front($tp, $A3, ['status' => 'running']);

// ── vínculos por tarefa (no negócio B, onde o 3 não tem outro vínculo) ──

foreach (['member_id' => $tm3, 'assignee_id' => 3, 'help_by' => 3] as $col => $val) {
    $task = p2v_task($tp, $B['id'], ['line_id' => $Bv, $col => $val]);
    $r = p2p_run($db, $tp, 3, ['reader_B' => ['pipe2_front_reader', $dB], 'lines_B' => ['pipe2_front_lines_of', $B['id'], 3], 'reach' => ['pipe2_front_reach_sql'],
                               'rr_B2' => ['record_reach', $dB, PERM_EDIT]], "tarefa {$col}");
    t_eq($r['reader_B'], true, "::front_reader_read_only: uma tarefa da frente com {$col} do 3 dá leitura");
    t_eq($r['lines_B'], [$Bv], "::front_reader_read_only: a frente da tarefa ({$col})");
    t_eq($r['rr_B2'], false, "::front_reader_read_only: a tarefa ({$col}) não dá edição");
    t_eq(p2p_reach_ids($tp, $r['reach']), [$A['id'], $B['id']], "::reach_sql: com a tarefa ({$col}), os negócios A e B");
    $tp->prepare('DELETE FROM tasks WHERE id = ?')->execute([$task]);
}
// Uma tarefa de outro negócio com a frente de B não liga (a tarefa tem de ser do negócio da frente).
$task = p2v_task($tp, $A['id'], ['line_id' => $Bv, 'assignee_id' => 3]);
$r = p2p_run($db, $tp, 3, ['reader_B' => ['pipe2_front_reader', $dB], 'reach' => ['pipe2_front_reach_sql']], 'tarefa de outro negócio');
t_eq($r['reader_B'], false, '::front_reader_read_only: uma tarefa de outro negócio não liga à frente');
t_eq(p2p_reach_ids($tp, $r['reach']), [$A['id']], '::reach_sql: uma tarefa de outro negócio não liga à frente');
$tp->prepare('DELETE FROM tasks WHERE id = ?')->execute([$task]);

// ── o sem negócios como responsável ─────────────────────────

p2v_front($tp, $A3, ['exec_owner_id' => $S]);
$r = p2p_run($db, $tp, $S, [
    'can_neg' => ['can', 'negocios', PERM_VIEW], 'reader_A' => ['pipe2_front_reader', $dA], 'reach' => ['pipe2_front_reach_sql'],
    'owner_A3' => ['pipe2_front_owner', $dA, $lA3], 'act_A3' => ['pipe2_can_front_act', $dA, $lA3], 'sw_A3' => ['pipe2_can_front_switch', $dA, $lA3],
], 'sem negócios');
t_eq($r['can_neg'], false, 'premissa: o sem negócios não tem Negócios');
t_eq($r['reader_A'], false, '::front_reader_read_only: o sem negócios responsável da frente não lê');
t_eq($r['reach'], ['0=1', []], '::reach_sql: sem Negócios, [\'0=1\', []]');
t_eq(p2p_reach_ids($tp, $r['reach']), [], '::reach_sql: sem Negócios, a consulta dá 0 linhas');
t_eq($r['owner_A3'], true, '::front_actions: o sem negócios é o responsável da frente');
t_eq([$r['act_A3'], $r['sw_A3']], [false, false], '::front_actions/::front_switch: o sem negócios responsável não age nem muda o interruptor');
p2v_front($tp, $A3, ['exec_owner_id' => 3]);

// ── o negócio P ainda no Pipeline, com as frentes já queued (ex.: arrastado para trás depois do Kickoff) ──

foreach ($P['lines'] as $l) p2v_front($tp, $l, ['status' => 'queued']);
$r = p2p_run($db, $tp, 3, ['reader_P' => ['pipe2_front_reader', $dP], 'lines_P' => ['pipe2_front_lines_of', $P['id'], 3], 'reach' => ['pipe2_front_reach_sql']], 'pipeline queued');
t_eq($r['reader_P'], false, '::not_before_kickoff: no Pipeline, mesmo com a frente queued, não lê pela frente');
t_eq($r['lines_P'], [], '::not_before_kickoff: no Pipeline, mesmo com a frente queued, pipe2_front_lines_of é []');
t_eq(p2p_reach_ids($tp, $r['reach']), [$A['id']], '::reach_sql: no Pipeline, mesmo com a frente queued, o negócio não sai');

// ── o negócio P chega ao Kickoff ────────────────────────────

$kick = p2v_stages($tp, $w['vfx'])['kickoff'];
$tp->prepare("UPDATE deals SET phase = 'production', stage_id = ? WHERE id = ?")->execute([$kick, $P['id']]);
$r = p2p_run($db, $tp, 3, ['reader_P' => ['pipe2_front_reader', $dP], 'lines_P' => ['pipe2_front_lines_of', $P['id'], 3], 'reach' => ['pipe2_front_reach_sql']], 'kickoff');
t_eq($r['reader_P'], true, '::not_before_kickoff: no Kickoff (frente queued), o responsável lê pela frente');
t_eq($r['lines_P'], [$P['lines']['3d']], '::not_before_kickoff: no Kickoff, a frente queued conta');
t_eq(p2p_reach_ids($tp, $r['reach']), [$A['id'], $P['id']], '::reach_sql: no Kickoff, o negócio P passa a contar');

// ── o negócio A concluído ───────────────────────────────────

$done = p2v_stages($tp, $w['vfx'])['done'];
$tp->prepare("UPDATE deals SET phase = 'done', stage_id = ?, done_at = NOW() WHERE id = ?")->execute([$done, $A['id']]);
foreach ($A['lines'] as $l) p2v_front($tp, $l, ['status' => 'ready']);
$r = p2p_run($db, $tp, 3, ['reader_A' => ['pipe2_front_reader', $dA], 'reach' => ['pipe2_front_reach_sql']], 'concluído');
t_eq($r['reader_A'], true, '::front_reader_read_only: num negócio concluído, o responsável da frente lê');
t_eq(p2p_reach_ids($tp, $r['reach']), [$A['id'], $P['id']], '::reach_sql: os concluídos também contam');
$r = p2p_run($db, $tp, $M, ['owner_Av' => ['pipe2_front_owner', $dA, $lAv], 'act_Av' => ['pipe2_can_front_act', $dA, $lAv],
                            'sw_Av' => ['pipe2_can_front_switch', $dA, $lAv]], 'concluído');
t_eq([$r['owner_Av'], $r['act_Av'], $r['sw_Av']], [false, false, false], '::front_actions: num negócio concluído, o responsável já não age');

// ── ::no_side_effects ───────────────────────────────────────

t_true($GLOBALS['p2p_probes'] >= 14, '::no_side_effects: os retratos cobrem todos os probes (' . $GLOBALS['p2p_probes'] . ')');
t_eq($GLOBALS['p2p_diffs'], [], '::no_side_effects: deals, deal_lines, deal_line_execs, tasks e permissions iguais antes e depois de cada probe');

t_done();
