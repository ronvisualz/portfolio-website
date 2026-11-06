<?php
// Pipeline v2, fase 1 — base de testes: tests/pipe2_v2_lib.php (base privada migrada, funis, negócios, frentes, passos,
// tarefas e regras de teste, sessão, assinaturas), tests/pipe2_probe.php (chamadas noutro processo, com a sessão de um
// utilizador), as corridas (p2v_race / p2v_go_wait), as opções novas e a limpeza de tests/pipe2_lib.php e as árvores
// de tests/pipe2_tree.php. Escreve na bancada só um funil «P2T · lib» (p2v_bench_funnel), por isso pede o pipe2_lock.
//   php tests/pipe2_v2_lib_test.php
require __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/pipe2_tree.php';
require_once __DIR__ . '/../admin/inc/pipeline_view.php';   // flow_can_edit, para o p2v_probe_args no próprio processo
pipe2_lock($pdo);
$bench = $pdo;   // depois de p2v_db(), o $pdo global passa a ser o da base privada
pipe2_cleanup($bench);

/** A mensagem da exceção de $fn (classe: mensagem), ou ''. */
function p2l_err(callable $fn): string {
    try { $fn(); } catch (Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); }
    return '';
}

// ── p2v_db ──────────────────────────────────────────────────
[$db, $tp] = p2v_db();
t_eq(preg_match(PIPE2_TMPDB_RE, $db), 1, 'p2v_db: devolve uma base privada (' . $db . ')');
t_eq(setting($tp, 'schema_version'), (string)SCHEMA_VERSION, 'p2v_db: a base privada está na SCHEMA_VERSION');
t_true($GLOBALS['pdo'] === $tp, 'p2v_db: o $pdo global passa a ser o da base privada');
t_true(db_has_table($tp, 'pipe2_notices') && db_has_column($tp, 'tasks', 'gate_stage_id'), 'p2v_db: a base privada tem o esquema da 44');

$deptId = fn(string $name) => (int)p2v_col($tp, 'SELECT id FROM departments WHERE name = ? ORDER BY id LIMIT 1', [$name]);
$stageRows = fn(int $fid, string $level) => p2v_rows($tp, 'SELECT id, name, kind, position, role, rounds_included, plan_days, unit_label FROM funnel_stages
                                                           WHERE funnel_id = ? AND level = ? ORDER BY position, id', [$fid, $level]);

// ── p2v_funnel ──────────────────────────────────────────────
$f = p2v_funnel($tp, 'vfx');
t_eq(array_map(fn($s) => [$s['name'], $s['kind'], (int)$s['position'], $s['role']], $stageRows($f, 'deal')),
    [['X-Ray', 'xray', 10, ''], ['Proposta', 'commercial', 20, 'proposal'], ['Aguardar feedback', 'commercial', 21, 'feedback'],
     ['Kickoff', 'kickoff', 50, ''], ['Em execução', 'production', 70, 'exec'], ['Entrega e pagamento final', 'production', 80, 'delivery'],
     ['Concluído', 'done', 90, '']],
    'p2v_funnel(vfx): as 7 etapas de negócio');
t_eq(array_map(fn($s) => [$s['name'], $s['kind'], (int)$s['position'], $s['role'], $s['rounds_included'] === null ? null : (int)$s['rounds_included']], $stageRows($f, 'front')),
    [['Turnover e breakdown', 'production', 110, '', null], ['Look dev / shot de referência', 'production', 120, '', null],
     ['Produção de shots', 'production', 130, '', null], ['Finais e QC', 'production', 140, '', null],
     ['Revisões', 'production', 150, 'revisions', 2], ['Pronto', 'production', 160, 'ready', null]],
    'p2v_funnel(vfx): as 6 sub-etapas do VFX, 2 rondas incluídas em Revisões');
$frow = p2v_rows($tp, 'SELECT name, service_code, is_active, is_base, v2_only, department_id, auto_advance FROM funnels WHERE id = ?', [$f])[0] ?? [];
t_eq([$frow['name'] ?? null, $frow['service_code'] ?? null, (int)($frow['is_active'] ?? -1), (int)($frow['is_base'] ?? -1), (int)($frow['auto_advance'] ?? -1)],
    ['P2T · vfx', 'vfx', 1, 0, 1], 'p2v_funnel(vfx): «P2T · vfx», service_code vfx, ativo, fora da base, avanço automático ligado');
t_eq((int)($frow['department_id'] ?? 0), $deptId('Pós-Produção e VFX'), 'p2v_funnel(vfx): departamento «Pós-Produção e VFX»');
t_true($deptId('Pós-Produção e VFX') > 0, 'a base privada tem o departamento «Pós-Produção e VFX»');

$f3d = p2v_funnel($tp, '3d', ['code' => false, 'active' => 0, 'plan_days' => [0 => 3, 2 => 5], 'units' => [2 => 'planos'], 'dept' => null, 'auto' => 0]);
$frow = p2v_rows($tp, 'SELECT service_code, is_active, department_id, auto_advance FROM funnels WHERE id = ?', [$f3d])[0] ?? [];
t_eq([$frow['service_code'] ?? null, (int)($frow['is_active'] ?? -1), array_key_exists('department_id', $frow) ? $frow['department_id'] : 'x', (int)($frow['auto_advance'] ?? -1)], ['', 0, null, 0],
    "p2v_funnel(3d, code false, active 0, dept null, auto 0): sem código, inativo, sem departamento, avanço desligado");
$fr = $stageRows($f3d, 'front');
t_eq(array_map(fn($s) => $s['plan_days'] === null ? null : (int)$s['plan_days'], $fr), [3, null, 5, null, null, null], 'p2v_funnel: plan_days por posição');
t_eq(array_column($fr, 'unit_label'), ['', '', 'planos', '', '', ''], 'p2v_funnel: units por posição');
t_eq(array_column($fr, 'name'), ['Styleframes', 'Modelação e lookdev', 'Animação', 'Lighting e render', 'Revisões', 'Pronto'], 'p2v_funnel(3d): as sub-etapas do 3D');
t_eq((int)($fr[4]['rounds_included'] ?? 0), 1, 'p2v_funnel(3d): 1 ronda incluída em Revisões');
$fcam = p2v_funnel($tp, 'cam', ['plan_days' => 4]);
t_eq(array_map(fn($s) => (int)$s['plan_days'], $stageRows($fcam, 'front')), [4, 4, 4, 4, 4, 4], 'p2v_funnel: plan_days inteiro vale para as 6 sub-etapas');
t_eq((int)p2v_col($tp, 'SELECT department_id FROM funnels WHERE id = ?', [$fcam]), $deptId('Campanhas'), 'p2v_funnel(cam): departamento «Campanhas»');
t_eq(p2l_err(fn() => p2v_funnel($tp, 'vfx', ['xpto' => 1])), 'InvalidArgumentException: p2v_funnel: opção desconhecida «xpto».', 'p2v_funnel recusa uma opção desconhecida');
t_true(str_starts_with(p2l_err(fn() => p2v_funnel($tp, 'xx')), 'InvalidArgumentException: p2v_funnel: serviço desconhecido'), 'p2v_funnel recusa um serviço desconhecido');

// ── p2v_stages ──────────────────────────────────────────────
$byName = [];
foreach (p2v_rows($tp, 'SELECT id, name FROM funnel_stages WHERE funnel_id = ? ORDER BY position, id', [$f]) as $s) $byName[$s['name']] = (int)$s['id'];
$S = p2v_stages($tp, $f);
t_eq([$S['xray'], $S['proposal'], $S['feedback'], $S['kickoff'], $S['exec'], $S['delivery'], $S['done']],
    [$byName['X-Ray'], $byName['Proposta'], $byName['Aguardar feedback'], $byName['Kickoff'], $byName['Em execução'], $byName['Entrega e pagamento final'], $byName['Concluído']],
    'p2v_stages: as etapas do negócio pelo kind/role');
t_eq($S['fronts'], [$byName['Turnover e breakdown'], $byName['Look dev / shot de referência'], $byName['Produção de shots'], $byName['Finais e QC'], $byName['Revisões'], $byName['Pronto']],
    'p2v_stages: fronts = as sub-etapas pela ordem');
t_eq([$S['revisions'], $S['ready']], [$byName['Revisões'], $byName['Pronto']], 'p2v_stages: revisions e ready');
t_eq($S['name'], $byName, 'p2v_stages: name = [nome => id] de todas as etapas do funil');

// ── p2v_world ───────────────────────────────────────────────
$w = p2v_world($tp);
t_eq(array_keys($w), ['vfx', '3d', 'md', 'cmp', 'ed', 'adv', 'cam'], 'p2v_world: os 7 serviços, pela ordem');
$codes = [];
foreach ($w as $code => $fid) $codes[$code] = (string)p2v_col($tp, 'SELECT service_code FROM funnels WHERE id = ?', [$fid]);
t_eq($codes, array_combine(array_keys($w), array_keys($w)), 'p2v_world: cada funil tem o seu service_code');
t_eq(count(array_unique(array_values($w))), 7, 'p2v_world: 7 funis diferentes');

$sql1 = fn(string $sql, array $a) => (int)p2v_col($tp, $sql, $a);
$stageOf = fn(int $fid, string $where) => $sql1("SELECT id FROM funnel_stages WHERE funnel_id = ? AND $where ORDER BY position, id LIMIT 1", [$fid]);
$lineRows = fn(int $dealId) => array_map(fn($l) => [(int)$l['funnel_id'], (int)$l['is_main'], (int)$l['position'], $l['status'], $l['stage_id'] === null ? null : (int)$l['stage_id']],
    p2v_rows($tp, 'SELECT * FROM deal_lines WHERE deal_id = ? ORDER BY position, id', [$dealId]));

// ── p2v_deal ────────────────────────────────────────────────
$d = p2v_deal($tp, $w, ['vfx', '3d']);
$row = deal_get($tp, $d['id']);
t_eq([$row['phase'] ?? null, (int)($row['funnel_id'] ?? 0), (int)($row['stage_id'] ?? 0), (int)($row['owner_id'] ?? 0)],
    ['production', $w['vfx'], $stageOf($w['vfx'], "kind = 'kickoff'"), 99], 'p2v_deal: no Kickoff do funil de teste vfx, dono 99');
t_true(str_starts_with((string)($row['code'] ?? ''), 'P2T') && str_starts_with((string)($row['name'] ?? ''), 'P2T · '), 'p2v_deal: code P2T e nome «P2T · …»');
t_eq(array_keys($d['lines']), ['vfx', '3d'], 'p2v_deal: lines = [código => frente]');
t_eq($lineRows($d['id']), [[$w['vfx'], 1, 0, 'queued', null], [$w['3d'], 0, 1, 'queued', null]], 'p2v_deal: 2 frentes Por começar, a vfx principal');
t_eq(array_values($d['lines']), array_map('intval', array_column(p2v_rows($tp, 'SELECT id FROM deal_lines WHERE deal_id = ? ORDER BY position', [$d['id']]), 'id')),
    'p2v_deal: os ids das frentes');

$e = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec']);
$S1vfx = $stageOf($w['vfx'], "level = 'front'");
$S13d = $stageOf($w['3d'], "level = 'front'");
t_eq((int)deal_get($tp, $e['id'])['stage_id'], $stageOf($w['vfx'], "role = 'exec'"), "p2v_deal(at exec): o negócio em «Em execução»");
t_eq($lineRows($e['id']), [[$w['vfx'], 1, 0, 'running', $S1vfx], [$w['3d'], 0, 1, 'running', $S13d]], 'p2v_deal(at exec): frentes Em curso na 1.ª sub-etapa');
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM deal_lines WHERE deal_id = ? AND started_at IS NOT NULL AND stage_entered_at IS NOT NULL', [$e['id']]), 2,
    'p2v_deal(at exec): frentes começadas (started_at, stage_entered_at)');

$p = p2v_deal($tp, $w, ['ed'], ['at' => 'proposal']);
$prow = deal_get($tp, $p['id']);
t_eq([$prow['phase'], (int)$prow['stage_id']], ['pipeline', $stageOf($w['ed'], "role = 'proposal'")], 'p2v_deal(at proposal): fase pipeline, na Proposta');
t_eq($lineRows($p['id']), [[$w['ed'], 1, 0, 'planned', null]], 'p2v_deal(at proposal): frente planned, sem sub-etapa');

$dn = p2v_deal($tp, $w, ['vfx'], ['at' => 'done']);
$dnrow = deal_get($tp, $dn['id']);
t_eq([$dnrow['phase'], (int)$dnrow['stage_id'], $dnrow['done_at'] !== null], ['done', $stageOf($w['vfx'], "kind = 'done'"), true], 'p2v_deal(at done): Concluído, com done_at');
t_eq($lineRows($dn['id']), [[$w['vfx'], 1, 0, 'ready', $stageOf($w['vfx'], "role = 'ready'")]], 'p2v_deal(at done): frente Pronta na sub-etapa Pronto');

$S33d = (int)p2v_col($tp, "SELECT id FROM funnel_stages WHERE funnel_id = ? AND level = 'front' ORDER BY position, id LIMIT 1 OFFSET 2", [$w['3d']]);
$m = p2v_deal($tp, $w, ['cam', 'vfx', '3d'], ['at' => 'exec', 'billing' => ['cam' => [1500, 3]], 'front_at' => ['3d' => $S33d], 'owner' => 1, 'exec' => 3,
    'delivery_date' => '2026-12-01', 'budget' => 1000, 'value_estimate' => 900]);
$mrow = deal_get($tp, $m['id']);
t_eq([$mrow['billing'], (int)$mrow['owner_id'], (int)$mrow['exec_owner_id'], $mrow['delivery_date'], (float)$mrow['budget'], (float)$mrow['value_estimate']],
    ['monthly', 1, 3, '2026-12-01', 1000.0, 900.0], 'p2v_deal: billing do negócio monthly, owner, exec, delivery_date, budget, value_estimate');
$cam = p2v_rows($tp, 'SELECT billing, monthly_fee, fee_months, cycle_ym, billing_end FROM deal_lines WHERE id = ?', [$m['lines']['cam']])[0];
t_eq([$cam['billing'], (float)$cam['monthly_fee'], (int)$cam['fee_months'], $cam['cycle_ym'], $cam['billing_end']],
    ['monthly', 1500.0, 3, date('Y-m'), date('Y-m-t', strtotime(date('Y-m-01') . ' +2 months'))],
    'p2v_deal(billing): a frente avença com mensalidade, meses, ciclo do mês de início e fim do período');
t_eq(p2v_rows($tp, 'SELECT billing, cycle_ym FROM deal_lines WHERE id = ?', [$m['lines']['vfx']])[0], ['billing' => '', 'cycle_ym' => ''], 'p2v_deal(billing): a frente pontual fica sem avença nem ciclo');
t_eq((int)p2v_col($tp, 'SELECT stage_id FROM deal_lines WHERE id = ?', [$m['lines']['3d']]), $S33d, 'p2v_deal(front_at): a frente 3d na sub-etapa pedida');

$nl = p2v_deal($tp, $w, ['vfx', '3d'], ['lines' => false]);
t_eq([$nl['lines'], (int)p2v_col($tp, 'SELECT COUNT(*) FROM deal_lines WHERE deal_id = ?', [$nl['id']])], [[], 0], "p2v_deal(lines false): sem deal_lines");
$dw = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'status' => 'depwait']);
t_eq($lineRows($dw['id']), [[$w['vfx'], 1, 0, 'depwait', null], [$w['3d'], 0, 1, 'depwait', null]], "p2v_deal(status depwait): À espera, sem sub-etapa");
t_eq(p2l_err(fn() => p2v_deal($tp, $w, ['vfx'], ['xpto' => 1])), 'InvalidArgumentException: p2v_deal: opção desconhecida «xpto».', 'p2v_deal recusa uma opção desconhecida');

// ── p2v_front ───────────────────────────────────────────────
p2v_front($tp, $d['lines']['3d'], ['status' => 'ready', 'cycle_ym' => '2026-10', 'auto_advance' => 0]);
t_eq(p2v_rows($tp, 'SELECT status, cycle_ym, auto_advance FROM deal_lines WHERE id = ?', [$d['lines']['3d']])[0], ['status' => 'ready', 'cycle_ym' => '2026-10', 'auto_advance' => 0],
    'p2v_front: UPDATE direto de deal_lines');

// ── p2v_steps ───────────────────────────────────────────────
$Svfx = p2v_stages($tp, $w['vfx']);
[$s1, $s2, $s3] = $Svfx['fronts'];
p2v_front($tp, $e['lines']['vfx'], ['cycle_ym' => '2026-11']);
$st = p2v_steps($tp, $e['lines']['vfx'], ['plan_days' => 3, 'hold' => [$s2 => 'undo'], 'units' => [$s3 => [10, 4]]]);
t_eq(array_keys($st), $Svfx['fronts'], 'p2v_steps: uma linha por sub-etapa, pela ordem');
$rows = p2v_rows($tp, 'SELECT id, deal_id, line_id, stage_id, cycle_ym, plan_days, units_plan, units_done, auto_hold_why, auto_hold_at IS NOT NULL AS held,
                              started_at IS NOT NULL AS started FROM deal_line_steps WHERE line_id = ? ORDER BY id', [$e['lines']['vfx']]);
t_eq(array_map(fn($r) => (int)$r['id'], $rows), array_values($st), 'p2v_steps: devolve [sub-etapa => id]');
t_eq(array_map(fn($r) => [(int)$r['deal_id'], (int)$r['stage_id'], $r['cycle_ym'], (int)$r['plan_days'], $r['units_plan'] === null ? null : (int)$r['units_plan'], (int)$r['units_done'],
                          $r['auto_hold_why'], (int)$r['held'], (int)$r['started']], $rows),
    [[$e['id'], $s1, '2026-11', 3, null, 0, '', 0, 1], [$e['id'], $s2, '2026-11', 3, null, 0, 'undo', 1, 0], [$e['id'], $s3, '2026-11', 3, 10, 4, '', 0, 0],
     [$e['id'], $Svfx['fronts'][3], '2026-11', 3, null, 0, '', 0, 0], [$e['id'], $Svfx['revisions'], '2026-11', 3, null, 0, '', 0, 0], [$e['id'], $Svfx['ready'], '2026-11', 3, null, 0, '', 0, 0]],
    'p2v_steps: ciclo da frente, plan_days, suspensão, unidades e started_at só na sub-etapa atual');
$st3 = p2v_steps($tp, $e['lines']['3d'], ['plan_days' => [$S13d => 2], 'start' => '2026-11-02 09:00:00']);
$rows3 = p2v_rows($tp, 'SELECT stage_id, plan_days, started_at FROM deal_line_steps WHERE line_id = ? ORDER BY id', [$e['lines']['3d']]);
t_eq(array_map(fn($r) => $r['plan_days'] === null ? null : (int)$r['plan_days'], $rows3), [2, null, null, null, null, null], 'p2v_steps: plan_days por sub-etapa');
t_eq(array_column($rows3, 'started_at'), ['2026-11-02 09:00:00', null, null, null, null, null], "p2v_steps(start): started_at da sub-etapa atual");

// ── p2v_task ────────────────────────────────────────────────
$key = sha1('p2t');
$t = p2v_task($tp, $e['id'], ['line_id' => $e['lines']['vfx'], 'stage_id' => $s1, 'is_gate' => 1, 'cycle_ym' => '2026-11', 'gate_stage_id' => $Svfx['delivery'],
    'dedup_key' => $key, 'repeat_rule' => 'weekly', 'created_at' => '2026-11-01 10:00:00', 'status' => 'done', 'done_at' => '2026-11-03 10:00:00',
    'checklist' => '[{"t":"a","d":0}]', 'attachments' => '[]', 'member_id' => 1, 'help_by' => 3, 'doc_kind' => 'payment']);
$trow = task_by_id($tp, $t);
t_eq([(int)$trow['deal_id'], (int)$trow['line_id'], (int)$trow['stage_id'], (int)$trow['is_gate'], $trow['cycle_ym'], (int)$trow['gate_stage_id'], $trow['dedup_key'],
      $trow['repeat_rule'], $trow['created_at'], $trow['status'], $trow['done_at'], $trow['checklist'], $trow['attachments'], (int)$trow['member_id'], (int)$trow['help_by'], $trow['doc_kind']],
    [$e['id'], $e['lines']['vfx'], $s1, 1, '2026-11', $Svfx['delivery'], $key, 'weekly', '2026-11-01 10:00:00', 'done', '2026-11-03 10:00:00', '[{"t":"a","d":0}]', '[]', 1, 3, 'payment'],
    'p2v_task: tarefa de frente com as colunas v2 e as novas da v1');
t_true(str_starts_with((string)$trow['title'], 'P2T · '), 'p2v_task: título «P2T · …»');

// ── p2v_rule e p2v_closing_rules ────────────────────────────
$rcols = 'stage_id, title, trig, kind, assign_to, due_mode, is_gate, is_active, action, trig_n, doc_kind';
$r1 = p2v_rule($tp, $s2);
t_eq(p2v_rows($tp, "SELECT $rcols FROM stage_automations WHERE id = ?", [$r1])[0],
    ['stage_id' => $s2, 'title' => 'P2T · regra', 'trig' => 'f_enter', 'kind' => 'task', 'assign_to' => 'execution', 'due_mode' => 'none', 'is_gate' => 1,
     'is_active' => 1, 'action' => 'task', 'trig_n' => null, 'doc_kind' => ''], 'p2v_rule: os valores por defeito');
$r2 = p2v_rule($tp, $s2, ['title' => 'Aprovação final', 'trig' => 'f_stale', 'trig_n' => 3, 'action' => 'notify', 'is_gate' => 0, 'assign_to' => 'svc_head']);
t_eq(p2v_rows($tp, 'SELECT title, trig, trig_n, action, is_gate, assign_to FROM stage_automations WHERE id = ?', [$r2])[0],
    ['title' => 'Aprovação final', 'trig' => 'f_stale', 'trig_n' => 3, 'action' => 'notify', 'is_gate' => 0, 'assign_to' => 'svc_head'],
    'p2v_rule: o que se passa sobrepõe (o título fica como foi dado)');
$cr = p2v_closing_rules($tp, $Svfx['delivery']);
t_eq(count($cr), 5, 'p2v_closing_rules: 5 regras');
t_eq(array_map(fn($r) => [(int)$r['stage_id'], $r['title'], $r['kind'], $r['assign_to'], $r['trig'], $r['due_mode'], (int)$r['due_days'], (int)$r['is_gate'], $r['doc_kind'], $r['action'], (int)$r['is_active']],
        p2v_rows($tp, 'SELECT * FROM stage_automations WHERE id IN (' . implode(',', array_map('intval', $cr)) . ') ORDER BY id')),
    [[$Svfx['delivery'], 'Aprovação final do cliente por escrito', 'email', 'execution', 'enter', 'entry', 2, 1, '', 'task', 1],
     [$Svfx['delivery'], 'Enviar entregáveis finais com comprovativo de download', 'delivery', 'execution', 'enter', 'entry', 1, 1, '', 'task', 1],
     [$Svfx['delivery'], 'Documento de direitos e licenças (música, stock, talento: âmbito e validade)', 'document', 'commercial', 'enter', 'entry', 2, 1, '', 'task', 1],
     [$Svfx['delivery'], 'Emitir fatura final · {ID}', 'quote', 'commercial', 'enter', 'entry', 1, 1, 'invoice', 'task', 1],
     [$Svfx['delivery'], 'Validar pagamento final · {ID}', 'task', 'commercial', 'enter', 'entry', 30, 1, 'payment', 'task', 1]],
    'p2v_closing_rules: as 4 regras de fecho e o pagamento final, na Entrega');

// ── p2v_as ──────────────────────────────────────────────────
p2v_as(99, $tp);
$u = current_user();
t_eq([$u['id'] ?? null, $u['role'] ?? null, array_keys($u ?? [])],
    [99, (string)p2v_col($tp, 'SELECT role FROM users WHERE id = 99'), ['id', 'name', 'email', 'role', 'department_id']], 'p2v_as(99): a sessão do utilizador 99, com os campos do login');
p2v_as(null);
t_eq(current_user(), null, 'p2v_as(null): sem sessão');
t_true(str_starts_with(p2l_err(fn() => p2v_as(424242, $tp)), 'InvalidArgumentException'), 'p2v_as recusa um utilizador que não existe');

// ── p2v_sig, p2v_rows, p2v_col ──────────────────────────────
$sig = p2v_sig($tp, ['deals', 'tasks' => ['id', 'title']], 'id > 0');
t_eq(array_keys($sig), ['deals', 'tasks'], 'p2v_sig: uma assinatura por tabela');
t_eq($sig['tasks'][0], (int)p2v_col($tp, 'SELECT COUNT(*) FROM tasks WHERE id > 0'), 'p2v_sig: o 1.º elemento é o COUNT(*)');
$tp->prepare("UPDATE tasks SET status = 'todo' WHERE id = ?")->execute([$t]);
t_eq(p2v_sig($tp, ['tasks' => ['id', 'title']], 'id > 0'), ['tasks' => $sig['tasks']], 'p2v_sig: uma coluna fora da lista não conta');
t_true(p2v_sig($tp, ['tasks'], 'id > 0') !== p2v_sig($tp, ['tasks'], 'id > ' . $t), 'p2v_sig: o where conta');
$sigAll = p2v_sig($tp, ['tasks']);
$tp->prepare("UPDATE tasks SET title = 'P2T · outra' WHERE id = ?")->execute([$t]);
t_true(p2v_sig($tp, ['tasks']) !== $sigAll, 'p2v_sig: mudar um valor muda a assinatura');
$tp->prepare('UPDATE tasks SET help_by = NULL WHERE id = ?')->execute([$t]);
$sigNull = p2v_sig($tp, ['tasks']);
$tp->prepare('UPDATE tasks SET help_by = 0 WHERE id = ?')->execute([$t]);
t_true(p2v_sig($tp, ['tasks']) !== $sigNull, 'p2v_sig: NULL é diferente de 0');

// ── opções novas do pipe2_lib ───────────────────────────────
$contact = (int)p2v_col($tp, 'SELECT MIN(id) FROM contacts');
$d2 = pipe2_deal($tp, ['phase' => 'done', 'funnel_id' => $w['vfx'], 'origin_funnel_id' => $w['3d'], 'last_touch' => '2026-10-01',
    'stage_entered_at' => '2026-10-02 10:00:00', 'delivered_at' => '2026-10-03 10:00:00', 'is_paid' => 1, 'done_at' => '2026-10-04 10:00:00', 'contact_id' => $contact]);
$r = deal_get($tp, $d2);
t_eq([(int)$r['origin_funnel_id'], $r['last_touch'], $r['stage_entered_at'], $r['delivered_at'], (int)$r['is_paid'], $r['done_at'], (int)$r['contact_id']],
    [$w['3d'], '2026-10-01', '2026-10-02 10:00:00', '2026-10-03 10:00:00', 1, '2026-10-04 10:00:00', $contact],
    'pipe2_deal: origin_funnel_id, stage_entered_at e done_at sobrepõem os por defeito; last_touch, delivered_at, is_paid, contact_id');
$l1 = pipe2_front($tp, $d2, $w['vfx']);
$l2 = pipe2_front($tp, $d2, $w['3d'], ['status' => 'active', 'stage_id' => $S13d, 'started_at' => '2026-10-05 10:00:00', 'done_at' => '2026-10-06 10:00:00',
    'stage_entered_at' => '2026-10-07 10:00:00', 'start_mode' => 'after', 'start_after_id' => $l1]);
$r = xsell_line_get($tp, $l2);
t_eq([$r['started_at'], $r['done_at'], $r['stage_entered_at'], $r['start_mode'], (int)$r['start_after_id']],
    ['2026-10-05 10:00:00', '2026-10-06 10:00:00', '2026-10-07 10:00:00', 'after', $l1], 'pipe2_front: started_at e stage_entered_at sobrepõem os por defeito; done_at, start_mode, start_after_id');

$db42 = pipe2_tmpdb_create(pipe2_base_dump());
$t42 = pipe2_tmpdb_pdo($db42);
$dd42 = pipe2_deal($t42, ['phase' => 'pipeline']);
t_eq(p2l_err(fn() => pipe2_task($t42, $dd42, ['gate_stage_id' => 1])), 'InvalidArgumentException: pipe2_task: a coluna tasks.gate_stage_id não existe nesta base.',
    'pipe2_task recusa gate_stage_id numa base na 42');
$t42id = pipe2_task($t42, $dd42, ['created_at' => '2026-10-01 08:00:00', 'repeat_rule' => 'daily', 'help_by' => 3]);
t_eq(p2v_rows($t42, 'SELECT created_at, repeat_rule, help_by FROM tasks WHERE id = ?', [$t42id])[0], ['created_at' => '2026-10-01 08:00:00', 'repeat_rule' => 'daily', 'help_by' => 3],
    'pipe2_task: as colunas novas da v1 também numa base na 42');
pipe2_cleanup($t42);
t_eq((int)p2v_col($t42, 'SELECT COUNT(*) FROM deals WHERE id = ?', [$dd42]), 0, 'pipe2_cleanup numa base na 42 (sem as tabelas da v2) corre sem erro');
$t42 = null;
pipe2_tmpdb_drop($db42);

// ── limpeza das tabelas novas ───────────────────────────────
$x1 = $stageOf($w['vfx'], "kind = 'xray'");
$tp->exec("INSERT INTO deals (code, phase, name, funnel_id, stage_id) VALUES ('P2T0000000000001', 'pipeline', 'P2T · sobra v2', {$w['vfx']}, $x1)");
$sobra = (int)$tp->lastInsertId();
$now = date('Y-m-d H:i:s');
$extraTables = ['deal_line_steps', 'deal_line_deps', 'deal_line_execs', 'task_deletions', 'pipe2_notices', 'cc_rv_rounds', 'cc_rv_items', 'cc_boards', 'fin_docs'];
$foreign = [];
foreach ($extraTables as $tb) $foreign[$tb] = (int)p2v_col($tp, "SELECT COUNT(*) FROM $tb WHERE deal_id NOT IN ({$e['id']}, $sobra)");
$foreign['cc_cards'] = (int)p2v_col($tp, 'SELECT COUNT(*) FROM cc_cards');
foreach ([$e['id'], $sobra] as $did) {
    $tp->exec("INSERT INTO deal_line_steps (deal_id, line_id, stage_id) VALUES ($did, 1, $s1)");
    $tp->exec("INSERT INTO deal_line_deps (deal_id, from_line_id, from_stage_id, to_line_id, to_stage_id) VALUES ($did, 1, $s1, 2, $s2)");
    $tp->exec("INSERT INTO deal_line_execs (deal_id, line_id, user_id) VALUES ($did, 1, 3)");
    $tp->exec("INSERT INTO task_deletions (task_id, deal_id, title) VALUES (1, $did, 'P2T')");
    $tp->exec("INSERT INTO pipe2_notices (deal_id, user_id, nkey) VALUES ($did, 99, 'p2t:$did')");
    $tp->exec("INSERT INTO cc_rv_items (deal_id, title, created_at, updated_at) VALUES ($did, 'P2T', '$now', '$now')");
    $item = (int)$tp->lastInsertId();
    $tp->exec("INSERT INTO cc_rv_rounds (item_id, deal_id, version_id, opened_at) VALUES ($item, $did, 0, '$now')");
    $tp->exec("INSERT INTO cc_boards (deal_id, title, created_at, updated_at) VALUES ($did, 'P2T', '$now', '$now')");
    $board = (int)$tp->lastInsertId();
    $tp->exec("INSERT INTO cc_cards (board_id, created_at, updated_at) VALUES ($board, '$now', '$now')");
    $tp->exec("INSERT INTO fin_docs (direction, deal_id, created_at, updated_at) VALUES ('out', $did, '$now', '$now')");
}
$boards = array_map('intval', array_column(p2v_rows($tp, "SELECT id FROM cc_boards WHERE deal_id IN ({$e['id']}, $sobra)"), 'id'));
pipe2_cleanup($tp);
$left = [];
foreach ($extraTables as $tb) $left[$tb] = (int)p2v_col($tp, "SELECT COUNT(*) FROM $tb WHERE deal_id IN ({$e['id']}, $sobra)");
$left['cc_cards'] = (int)p2v_col($tp, 'SELECT COUNT(*) FROM cc_cards WHERE board_id IN (' . implode(',', $boards ?: [0]) . ')');
t_eq($left, array_fill_keys(array_merge($extraTables, ['cc_cards']), 0), 'pipe2_cleanup: apaga as linhas das tabelas novas dos negócios P2T (registado e sobra)');
$after = [];
foreach ($extraTables as $tb) $after[$tb] = (int)p2v_col($tp, "SELECT COUNT(*) FROM $tb WHERE deal_id NOT IN ({$e['id']}, $sobra)");
$after['cc_cards'] = (int)p2v_col($tp, 'SELECT COUNT(*) FROM cc_cards');
t_eq($after, $foreign, 'pipe2_cleanup: as linhas de outros negócios ficam');
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM stage_automations WHERE id IN (?, ?)', [$r1, $r2]), 0, 'pipe2_cleanup: apaga as regras criadas por p2v_rule');

// ── o probe (outro processo, com a sessão de um utilizador) ─
// os dados são criados depois da limpeza (o probe vê-os na base privada)
$w2 = p2v_world($tp, ['vfx', '3d']);
$pd = p2v_deal($tp, $w2, ['vfx', '3d'], ['at' => 'exec']);
$pt = p2v_task($tp, $pd['id'], ['line_id' => $pd['lines']['3d']]);

t_eq(p2v_probe($db, 1, [['fn' => 'can', 'args' => ['financeiro', 1]]]), [true], 'probe: o admin (1) vê o Financeiro');
t_eq(p2v_probe($db, 3, [['fn' => 'can', 'args' => ['financeiro', 1]]]), [false], 'probe: o 3 não vê o Financeiro');
t_eq(p2v_probe($db, 0, [['fn' => 'can', 'args' => ['pipeline', 1]]]), [false], 'probe: sem sessão (uid 0), can é falso');
$al = p2v_probe($db, 99, [['fn' => 'my_alerts', 'args' => []]]);
t_true(isset($al[0]) && is_array($al[0]) && array_key_exists('deal', $al[0]), 'probe: my_alerts do 99 (o PDO vai à frente) tem o grupo deal (' . substr(pipe2_json($al), 0, 200) . ')');
$no = p2v_probe($db, 99, [['fn' => 'notifications', 'args' => []]]);
t_true(isset($no[0]) && is_array($no[0]) && !isset($no['error']), 'probe: notifications do 99 corre (' . substr(pipe2_json($no), 0, 200) . ')');
$x = p2v_probe($db, 1, [['fn' => 'exec', 'args' => ['ls']]]);
t_true(isset($x['error']) && ($x['code'] ?? null) === 1, 'probe: uma função fora da lista dá {"error"} e código 1 (' . pipe2_json($x) . ')');
$x = p2v_probe($db, 1, [['fn' => 'pipe2_cleanup', 'args' => []]]);
t_true(isset($x['error']) && ($x['code'] ?? null) === 1, 'probe: uma função pipe2_* dos testes (fora de admin/inc) também é recusada');
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM deals WHERE id = ?', [$pd['id']]), 1, 'probe: a função recusada não correu');
$x = p2v_probe($db, 1, [['fn' => 'pipe2_xpto_inexistente', 'args' => []]]);
t_true(isset($x['error']) && ($x['code'] ?? null) === 1, 'probe: uma função indefinida dá {"error"} e código 1');
$x = p2v_probe($db, 424242, [['fn' => 'is_admin', 'args' => []]]);
t_true(isset($x['error']) && ($x['code'] ?? null) === 1, 'probe: um utilizador que não existe dá {"error"}');
$many = p2v_probe($db, 1, [['fn' => 'is_admin', 'args' => []], ['fn' => 'current_user', 'args' => []], ['fn' => 'pipe2_on', 'args' => []],
    ['fn' => 'record_reach', 'args' => [['deal' => $pd['id']]]], ['fn' => 'flow_can_edit', 'args' => [['deal' => $pd['id']]]],
    ['fn' => 'flow_is_commercial', 'args' => [['deal' => $pd['id']]]], ['fn' => 'record_reach', 'args' => [['deal' => 999999999]]],
    ['fn' => 'pipe2_stage_live', 'args' => [['line' => $pd['lines']['vfx']]]], ['fn' => 'reach_sql', 'args' => ['negocios']]]);
t_eq(array_slice($many, 0, 8), [true, ['id' => 1, 'name' => (string)p2v_col($tp, 'SELECT name FROM users WHERE id = 1'), 'email' => (string)p2v_col($tp, 'SELECT email FROM users WHERE id = 1'),
        'role' => (string)p2v_col($tp, 'SELECT role FROM users WHERE id = 1'), 'department_id' => ($dep1 = p2v_col($tp, 'SELECT department_id FROM users WHERE id = 1')) === null ? null : (int)$dep1],
    false, true, true, true, false, true],
    'probe: várias chamadas pela ordem; {"deal": id} vira a linha; o PDO vai à frente (pipe2_on, record_reach, flow_can_edit)');
t_true(is_array($many[8] ?? null) && is_string($many[8][0] ?? null) && is_array($many[8][1] ?? null), 'probe: reach_sql devolve [sql, args]');
$sub = p2v_probe($db, 99, [['fn' => 'current_user', 'args' => []]]);
t_eq($sub[0]['id'] ?? null, 99, 'probe: cada probe é um processo com a sua sessão');

// p2v_probe_args: as substituições, no próprio processo
$pa = p2v_probe_args($tp, 'flow_can_edit', [['deal' => $pd['id']]]);
t_true(($pa[0] ?? null) === $tp && (int)($pa[1]['id'] ?? 0) === $pd['id'] && ($pa[1]['phase'] ?? null) === 'production', 'p2v_probe_args: PDO à frente e {"deal": id} → deal_get');
$pa = p2v_probe_args($tp, 'pipe2_stage_live', [['line' => $pd['lines']['3d']], ['task' => $pt], ['deal' => 999999999], ['deal' => $pd['id'], 'x' => 1]]);
t_eq([(int)($pa[0]['id'] ?? 0), $pa[0]['funnel_name'] ?? null, (int)($pa[0]['funnel_id'] ?? 0), (int)($pa[1]['id'] ?? 0), $pa[2], $pa[3]],
    [$pd['lines']['3d'], 'P2T · 3d', $w2['3d'], $pt, null, ['deal' => $pd['id'], 'x' => 1]],
    'p2v_probe_args: {"line": id} → frente com funnel_name; {"task": id} → task_by_id; inexistente → null; outro array fica');
t_eq(p2v_probe_args($tp, 'can', ['negocios', 1]), ['negocios', 1], 'p2v_probe_args: sem PDO no 1.º parâmetro, os args ficam');
t_eq(p2l_err(fn() => p2v_probe_args($tp, 'p2t_nao_existe', [])), 'InvalidArgumentException: p2v_probe_args: a função «p2t_nao_existe» não está definida.',
    'p2v_probe_args: uma função indefinida dá InvalidArgumentException');

// --calls vazio
$r = pipe2_sub('pipe2_probe.php', ['--db=' . $db, '--uid=1', '--calls=']);
t_eq([$r['code'], $r['json']], [0, ['results' => []]], 'probe: --calls vazio → {"results": []} (' . trim($r['err']) . ')');

// ── corridas ────────────────────────────────────────────────
$empty = tempnam(sys_get_temp_dir(), 'p2l');
file_put_contents($empty, '[]');
$t0 = microtime(true);
$rr = p2v_race([['pipe2_probe.php', ['--db=' . $db, '--uid=1', '--calls=' . $empty]], ['pipe2_probe.php', ['--db=' . $db, '--uid=99', '--calls=' . $empty]]]);
t_eq(array_map(fn($x) => [$x['code'], $x['timeout'], $x['json'], $x['ready']], $rr), [[0, false, ['results' => []], true], [0, false, ['results' => []], true]],
    'p2v_race: duas corridas do eco, código 0, sem timeout, as duas com .ready antes do sinal (' . substr(trim(($rr[0]['err'] ?? '') . ' ' . ($rr[1]['err'] ?? '')), 0, 300) . ')');
t_true(($rr[0]['go_mtime'] ?? 0) >= ($rr[0]['ready_mtime'] ?? PHP_INT_MAX) && ($rr[1]['go_mtime'] ?? 0) >= ($rr[1]['ready_mtime'] ?? PHP_INT_MAX),
    'p2v_race: o mtime do sinal ≥ o dos dois .ready');
t_true(isset($rr[0]['go']) && str_starts_with($rr[0]['go'], rtrim(sys_get_temp_dir(), '/') . '/') && $rr[0]['go'] === ($rr[1]['go'] ?? null), 'p2v_race: um só sinal F, no sys_get_temp_dir()');
t_eq(glob(($rr[0]['go'] ?? '/nada') . '*'), [], 'p2v_race: no fim não fica nenhum F*');

$t0 = microtime(true);
$rr = p2v_race([['pipe2_probe.php', ['--db=p2_inexistente', '--uid=1', '--calls=' . $empty]], ['pipe2_probe.php', ['--db=' . $db, '--uid=1', '--calls=' . $empty]]]);
$dt = microtime(true) - $t0;
t_true($dt < 16, sprintf('p2v_race: uma corrida que sai antes do .ready não prende (%.1f s)', $dt));
t_true(($rr[0]['code'] ?? 0) !== 0 && isset($rr[0]['json']['error']) && ($rr[0]['ready'] ?? true) === false && ($rr[0]['timeout'] ?? true) === false,
    'p2v_race: a corrida que falhou sai com código ≠ 0, {"error"} e sem .ready (' . pipe2_json($rr[0] ?? null) . ')');
t_eq([($rr[1]['code'] ?? null), ($rr[1]['json'] ?? null), ($rr[1]['timeout'] ?? null)], [0, ['results' => []], false], 'p2v_race: a outra corrida sai com 0');
t_eq(glob(($rr[0]['go'] ?? '/nada') . '*'), [], 'p2v_race: no fim não fica nenhum F*');

// p2v_go_wait sem sinal: escreve o .ready, espera 10 s e sai com 2
$go = rtrim(sys_get_temp_dir(), '/') . '/p2l_go_' . getmypid() . '_nunca';
$t0 = microtime(true);
$r = pipe2_sub('pipe2_probe.php', ['--db=' . $db, '--uid=1', '--calls=' . $empty, '--go=' . $go]);
$dt = microtime(true) - $t0;
$readies = glob($go . '.ready.*');
t_eq([$r['code'], trim($r['err']), count($readies)], [2, 'sem sinal de partida', 1], 'p2v_go_wait: sem F a tempo → .ready escrito, stderr «sem sinal de partida», código 2');
t_true($dt >= 9.5 && $dt < 20, sprintf('p2v_go_wait: espera cerca de 10 s pelo sinal (%.1f s)', $dt));
foreach ($readies as $f0) @unlink($f0);
$argv0 = ['x.php', '--db=y'];
$t0 = microtime(true);
p2v_go_wait($argv0);
t_true(microtime(true) - $t0 < 0.5, 'p2v_go_wait: sem --go não faz nada');
@unlink($empty);

// ── árvores (tests/pipe2_tree.php) ──────────────────────────
$root = dirname(__DIR__);
$dir = pipe2_tree('8c581ae', ['admin/inc/migrate.php' => '428480f'], ['tests/lib.php'], 'libtest');
t_eq(dirname($dir) . '|' . basename($dir), rtrim(sys_get_temp_dir(), '/') . '|p2t_' . getmypid() . '_libtest', 'pipe2_tree: p2t_<pid>_<tag> no sys_get_temp_dir()');
t_true(str_contains((string)@file_get_contents($dir . '/admin/inc/migrate.php'), 'const SCHEMA_VERSION = 42;'), 'pipe2_tree: o overlay vem do commit pedido (migrate.php de 428480f)');
t_true(is_file($dir . '/admin/inc/migrate_pipeline2.php') && !file_exists($dir . '/admin/inc/migrate_pipeline2_44.php'), 'pipe2_tree: o resto é o admin/ do commit (8c581ae: passo 43, sem o 44)');
t_eq((string)@file_get_contents($dir . '/tests/lib.php'), (string)file_get_contents($root . '/tests/lib.php'), 'pipe2_tree: os ficheiros de teste vêm da cópia de trabalho');
t_true(is_link($dir . '/admin/inc/config.php') && realpath($dir . '/admin/inc/config.php') === realpath($root . '/admin/inc/config.php'),
    'pipe2_tree: a configuração local é uma ligação simbólica (não se copia)');
t_true(in_array(realpath($root . '/admin/inc/config.php'), pipe2_tree_links($dir), true), 'pipe2_tree_links: lista as ligações da árvore');
file_put_contents($dir . '/tests/p2l_echo.php', "<?php\necho json_encode(['dir' => __DIR__, 'cwd' => getcwd(), 'args' => array_slice(\$argv, 1)]), \"\\n\";\nfwrite(STDERR, 'e');\nexit(3);\n");
$r = pipe2_tree_php($dir, 'tests/p2l_echo.php', ['a', 7]);
t_eq([$r['code'], $r['json'], $r['err']], [3, ['dir' => $dir . '/tests', 'cwd' => $dir, 'args' => ['a', '7']], 'e'], 'pipe2_tree_php: corre o script da árvore, a partir dela; code, json e err');
t_true(str_contains($r['out'], '"args"'), 'pipe2_tree_php: devolve também a saída em bruto');
$p = proc_open([PHP_BINARY, '-r', 'require ' . var_export($root . '/tests/pipe2_tree.php', true) . '; echo pipe2_tree("8c581ae", [], [], "sub");'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
$subDir = trim((string)stream_get_contents($pipes[1]));
$subErr = trim((string)stream_get_contents($pipes[2]));
$code = proc_close($p);
t_true($code === 0 && str_contains($subDir, '/p2t_') && !file_exists($subDir), 'pipe2_tree: a árvore é apagada no fim do processo que a criou (' . $subDir . ' ' . $subErr . ')');

// ── na bancada ──────────────────────────────────────────────
$n0 = (int)$bench->query("SELECT COUNT(*) FROM funnels WHERE name LIKE 'P2T%'")->fetchColumn();
t_true(str_starts_with(p2l_err(fn() => p2v_funnel($bench, 'vfx')), 'InvalidArgumentException'), 'na bancada, p2v_funnel com service_code lança InvalidArgumentException');
t_eq((int)$bench->query("SELECT COUNT(*) FROM funnels WHERE name LIKE 'P2T%'")->fetchColumn(), $n0, 'na bancada, p2v_funnel recusado não cria nada');
$bf = p2v_bench_funnel($bench, 'lib');
$brow = $bench->query('SELECT name, service_code, v2_only, is_base FROM funnels WHERE id = ' . $bf)->fetch();
t_eq([$brow['name'] ?? null, $brow['service_code'] ?? null, (int)($brow['v2_only'] ?? -1), (int)($brow['is_base'] ?? -1)], ['P2T · lib', '', 1, 0],
    'p2v_bench_funnel: «P2T · lib» sem service_code, só da v2, fora da base');
$bs = fn(string $level) => array_map(fn($s) => [$s['name'], $s['role']], $bench->query("SELECT name, role FROM funnel_stages WHERE funnel_id = $bf AND level = '$level' ORDER BY position, id")->fetchAll());
t_eq(array_column($bs('deal'), 1), ['', 'proposal', 'feedback', '', 'exec', 'delivery', ''], 'p2v_bench_funnel: as 7 etapas de negócio (com Em execução e Entrega)');
t_eq($bs('front'), [['P2T · s1', ''], ['P2T · s2', ''], ['P2T · s3', 'revisions'], ['P2T · s4', 'ready']], 'p2v_bench_funnel: 4 sub-etapas, as 2 últimas revisions e ready');
$bf3 = p2v_bench_funnel($bench, 'lib3', 3);
t_eq(count($bench->query("SELECT id FROM funnel_stages WHERE funnel_id = $bf3 AND level = 'front'")->fetchAll()), 3, 'p2v_bench_funnel(…, 3): 3 sub-etapas');
pipe2_cleanup($bench);
t_eq((int)$bench->query("SELECT COUNT(*) FROM funnels WHERE id IN ($bf, $bf3)")->fetchColumn() + (int)$bench->query("SELECT COUNT(*) FROM funnel_stages WHERE funnel_id IN ($bf, $bf3)")->fetchColumn(), 0,
    'na bancada, a limpeza apaga os funis de p2v_bench_funnel e as etapas');

t_done();
