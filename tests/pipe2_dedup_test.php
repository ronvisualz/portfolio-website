<?php
/**
 * Pipeline v2, fase 1 — regras de dois níveis e deduplicação (admin/inc/pipe2_tasks.php, D10, D11, D12; §34.11 T2).
 *
 * A chave de uma tarefa é sha1(título normalizado | negócio | âmbito | ciclo), com âmbito 'L<frente>:S<sub-etapa>' nas
 * de frente e '' nas de negócio. Antes de criar: (1) a regra já criou tarefa para este negócio, frente e ciclo → não
 * cria; (2) há uma tarefa com a mesma chave (aberta ou feita) → não cria e, se a regra é obrigatória, a existente
 * passa a obrigatória; (3) só nas de negócio: tarefas das frentes, abertas ou feitas, com o mesmo título normalizado e
 * o mesmo ciclo → não cria e, se a regra é obrigatória, todas ficam com gate_stage_id = etapa do negócio e contam para
 * o gate dela. Numa etapa do negócio correm também as regras da etapa com o mesmo papel nos funis das outras frentes
 * (nunca as de fatura/pagamento), e a tarefa fica sempre na etapa do negócio. Num negócio v1, flow_task_from_rule faz
 * o mesmo de antes.
 *
 * Corre numa base privada (p2v_db: dump da 42 migrado até à SCHEMA_VERSION). Não escreve na bancada.
 *   php tests/pipe2_dedup_test.php
 * Casos: ::keys ::deal_deal ::deal_deal_upgrade ::deal_front ::delivery_reentry ::skipped_counts_for_gate
 *        ::other_funnel_rule_on_deal_stage ::stage_match ::front_front ::cycle ::rule_dedup_line_cycle
 *        ::invoice_only_deal ::notify_action ::assignee_never_invalid ::svc_head ::v1_noop ::strict
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/pipe2_tasks.php';

[$db, $tp] = p2v_db();

/** O valor de uma coluna (NULL incluído); '(falta)' se a chave não existir. */
function p2_val(array $row, string $k) {
    return array_key_exists($k, $row) ? $row[$k] : '(falta)';
}

const P2D_AF = 'Aprovação final do cliente por escrito';
const P2D_REVER = 'P2T · Rever com o cliente';
const P2D_3D = 'P2T · só no 3D';
const P2D_CENA = 'P2T · Preparar a cena';

/** Uma tarefa de frente com o título exato (INSERT direto; a limpeza apaga-a). */
function p2d_ftask(PDO $pdo, int $deal, int $line, int $stage, string $title, array $o = []): int {
    $now = date('Y-m-d H:i:s');
    $status = (string)($o['status'] ?? 'todo');
    $id = pipe2_insert($pdo, 'tasks', [
        'title' => $title, 'status' => $status, 'deal_id' => $deal, 'line_id' => $line, 'stage_id' => $stage, 'kind' => 'task',
        'cycle_ym' => (string)($o['cycle_ym'] ?? ''), 'is_gate' => (int)($o['is_gate'] ?? 0), 'done_at' => $status === 'done' ? $now : null,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    pipe2_reg($pdo, 'tasks', $id);
    return $id;
}

/** As tarefas do negócio (line_id NULL) com um título. */
function p2d_deal_tasks(PDO $pdo, int $deal, string $title): array {
    return p2v_rows($pdo, 'SELECT * FROM tasks WHERE deal_id = ? AND line_id IS NULL AND title = ? ORDER BY id', [$deal, $title]);
}

function p2d_task(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM tasks WHERE id = ?', [$id])[0] ?? [];
}

function p2d_rule(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM stage_automations WHERE id = ?', [$id])[0];
}

/** O item do gate de uma tarefa (ou null). */
function p2d_gate_item(array $items, int $task): ?array {
    foreach ($items as $i) if ((int)($i['task'] ?? 0) === $task) return $i;
    return null;
}

// ── o mundo (todos os funis e regras antes da primeira leitura das caches da v1) ──
$w = p2v_world($tp, ['vfx', '3d', 'adv']);
$sv = p2v_stages($tp, $w['vfx']);
$s3 = p2v_stages($tp, $w['3d']);
$sa = p2v_stages($tp, $w['adv']);
$R = [];
// a etapa comum Entrega: a mesma regra nos dois funis (obrigatória no VFX, não no 3D), uma só do 3D, faturas
$R['af_vfx'] = p2v_rule($tp, $sv['delivery'], ['title' => P2D_AF, 'trig' => 'enter', 'kind' => 'email', 'assign_to' => 'commercial', 'is_gate' => 1]);
$R['af_3d'] = p2v_rule($tp, $s3['delivery'], ['title' => P2D_AF, 'trig' => 'enter', 'kind' => 'email', 'assign_to' => 'commercial', 'is_gate' => 0]);
$R['rever'] = p2v_rule($tp, $sv['delivery'], ['title' => P2D_REVER, 'trig' => 'enter', 'assign_to' => 'commercial', 'is_gate' => 0]);
$R['3d_only'] = p2v_rule($tp, $s3['delivery'], ['title' => P2D_3D, 'trig' => 'enter', 'assign_to' => 'commercial', 'is_gate' => 1]);
$R['inv_main'] = p2v_rule($tp, $sv['delivery'], ['title' => 'P2T · Emitir fatura · {ID}', 'trig' => 'enter', 'kind' => 'quote', 'assign_to' => 'commercial', 'doc_kind' => 'invoice']);
$R['inv_3d'] = p2v_rule($tp, $s3['delivery'], ['title' => 'P2T · Emitir fatura 3D · {ID}', 'trig' => 'enter', 'kind' => 'quote', 'assign_to' => 'commercial', 'doc_kind' => 'invoice']);
// as sub-etapas
$R['cena_enter'] = p2v_rule($tp, $sv['fronts'][0], ['title' => P2D_CENA, 'trig' => 'f_enter']);
$R['cena_dep'] = p2v_rule($tp, $sv['fronts'][0], ['title' => P2D_CENA, 'trig' => 'dep_met']);
$R['v1_enter'] = p2v_rule($tp, $sv['fronts'][0], ['title' => 'P2T · Entrar (gatilho da v1)', 'trig' => 'enter', 'is_gate' => 0]);
$R['cena_3d'] = p2v_rule($tp, $s3['fronts'][0], ['title' => P2D_CENA, 'trig' => 'f_enter']);
$R['inv_sub'] = p2v_rule($tp, $sv['fronts'][1], ['title' => 'P2T · Emitir fatura da frente', 'trig' => 'f_enter', 'kind' => 'quote', 'doc_kind' => 'invoice']);
$R['notify'] = p2v_rule($tp, $sv['fronts'][2], ['title' => 'P2T · Avisar o responsável', 'trig' => 'f_enter', 'action' => 'notify', 'assign_to' => 'execution', 'is_gate' => 0]);
$R['cycle'] = p2v_rule($tp, $sv['fronts'][3], ['title' => 'P2T · Relatório do mês', 'trig' => 'f_enter']);
$R['svc'] = p2v_rule($tp, $sv['fronts'][4], ['title' => 'P2T · Para o chefe', 'trig' => 'f_enter', 'assign_to' => 'svc_head']);
$R['user_sa'] = p2v_rule($tp, $sv['fronts'][4], ['title' => 'P2T · Para o superadmin', 'trig' => 'f_enter', 'assign_to' => 'user', 'assign_ref' => 2]);
$R['exec'] = p2v_rule($tp, $sv['fronts'][4], ['title' => 'P2T · Para a execução', 'trig' => 'f_enter', 'assign_to' => 'execution']);
// a v1: o VFX real (funil base) e uma regra no Kickoff dele
$v1f = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY id LIMIT 1");
$v1k = (int)p2v_col($tp, "SELECT id FROM funnel_stages WHERE funnel_id = ? AND kind = 'kickoff' ORDER BY position, id LIMIT 1", [$v1f]);
$R['v1'] = p2v_rule($tp, $v1k, ['title' => 'P2T · regra v1', 'trig' => 'enter', 'assign_to' => 'commercial']);

$entrega = stage_get($tp, $sv['delivery']);
/** Um negócio VFX + 3D na Entrega, com as frentes Prontas. */
$newDeal = fn(array $codes = ['vfx', '3d'], array $o = []) => p2v_deal($tp, $w, $codes, $o + ['at' => 'delivery', 'status' => 'ready']);
$titleOf = fn(array $ids) => array_map(fn($id) => (string)p2d_task($tp, $id)['title'], $ids);

// ── ::keys ──────────────────────────────────────────────────
$c = '::keys';
t_eq(pipe2_title_norm("  Aprovação   FINAL do\tcliente  por escrito "), 'aprovação final do cliente por escrito', "$c minúsculas, espaços reduzidos a um, sem espaços nas pontas");
t_eq(pipe2_dedup_key('  Relatório  X ', 5, 'L1:S2', '2026-11'), sha1('relatório x|5|L1:S2|2026-11'), "$c sha1 do título normalizado, negócio, âmbito e ciclo");
t_eq(strlen(pipe2_dedup_key('a', 1, '', '')), 40, "$c a chave tem 40 caracteres");

// ── ::deal_deal ─────────────────────────────────────────────
$c = '::deal_deal';
$d1 = $newDeal();
$deal1 = deal_get($tp, $d1['id']);
$r1 = pipe2_run_rules($tp, $deal1, $entrega, 'enter', null, 1);
$af = p2d_deal_tasks($tp, $d1['id'], P2D_AF);
t_eq(count($af), 1, "$c a mesma regra nos dois funis cria uma tarefa");
t_eq((int)($af[0]['is_gate'] ?? -1), 1, "$c obrigatória (a do VFX é)");
t_eq((int)($af[0]['stage_id'] ?? 0), $sv['delivery'], "$c na Entrega do negócio");
t_eq(p2_val($af[0] ?? [], 'line_id'), null, "$c do negócio (sem frente)");
t_eq((string)($af[0]['dedup_key'] ?? ''), pipe2_dedup_key(P2D_AF, $d1['id'], '', ''), "$c com a chave D12");
t_eq((string)($af[0]['cycle_ym'] ?? 'x'), '', "$c ciclo vazio");
t_true(in_array((int)($af[0]['id'] ?? 0), $r1['tasks'], true), "$c pipe2_run_rules devolve o id");
t_eq((int)p2v_col($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'auto'", [$d1['id']]), 1, "$c uma linha 'auto' no histórico");
$r1b = pipe2_run_rules($tp, $deal1, $entrega, 'enter', null, 1);
t_eq($r1b['tasks'], [], "$c correr outra vez não cria nada");
t_eq(count(p2d_deal_tasks($tp, $d1['id'], P2D_AF)), 1, "$c continua uma");

// ── ::deal_deal_upgrade ─────────────────────────────────────
$c = '::deal_deal_upgrade';
$du = $newDeal(['3d', 'vfx']);   // a principal é a 3D: a não obrigatória corre primeiro
pipe2_run_rules($tp, deal_get($tp, $du['id']), stage_get($tp, $s3['delivery']), 'enter', null, 1);
$afu = p2d_deal_tasks($tp, $du['id'], P2D_AF);
t_eq(count($afu), 1, "$c uma tarefa");
t_eq((int)($afu[0]['is_gate'] ?? -1), 1, "$c a regra obrigatória do outro funil torna a existente obrigatória");
t_eq((int)($afu[0]['auto_rule_id'] ?? 0), $R['af_3d'], "$c a tarefa é a da 1.ª regra (3D)");

// ── ::deal_front ────────────────────────────────────────────
$c = '::deal_front';
$cases = [];
// a) aberta na frente 3D
$da = $newDeal();
$ta = p2d_ftask($tp, $da['id'], $da['lines']['3d'], $s3['ready'], P2D_AF);
pipe2_run_rules($tp, deal_get($tp, $da['id']), $entrega, 'enter', null, 1);
t_eq(count(p2d_deal_tasks($tp, $da['id'], P2D_AF)), 0, "$c aberta na frente: não cria a do negócio");
t_eq((int)(p2d_task($tp, $ta)['gate_stage_id'] ?? 0), $sv['delivery'], "$c aberta: conta para o gate da Entrega");
// b) feita na frente 3D
$db_ = $newDeal();
$tb = p2d_ftask($tp, $db_['id'], $db_['lines']['3d'], $s3['ready'], P2D_AF, ['status' => 'done']);
pipe2_run_rules($tp, deal_get($tp, $db_['id']), $entrega, 'enter', null, 1);
t_eq(count(p2d_deal_tasks($tp, $db_['id'], P2D_AF)), 0, "$c feita na frente: não cria a do negócio");
t_eq((int)(p2d_task($tp, $tb)['gate_stage_id'] ?? 0), $sv['delivery'], "$c feita: conta para o gate da Entrega");
// c) o título só igual depois de normalizar
$dc = $newDeal();
$tc = p2d_ftask($tp, $dc['id'], $dc['lines']['3d'], $s3['ready'], '  aprovação FINAL do cliente  por escrito ');
pipe2_run_rules($tp, deal_get($tp, $dc['id']), $entrega, 'enter', null, 1);
t_eq(count(p2d_deal_tasks($tp, $dc['id'], P2D_AF)), 0, "$c título normalizado: não cria");
t_eq((int)(p2d_task($tp, $tc)['gate_stage_id'] ?? 0), $sv['delivery'], "$c título normalizado: conta para o gate");
// d) a da frente noutro ciclo
$dd = $newDeal();
$td = p2d_ftask($tp, $dd['id'], $dd['lines']['3d'], $s3['ready'], P2D_AF, ['status' => 'done', 'cycle_ym' => '2026-11']);
pipe2_run_rules($tp, deal_get($tp, $dd['id']), $entrega, 'enter', null, 1);
$afd = p2d_deal_tasks($tp, $dd['id'], P2D_AF);
t_eq(count($afd), 1, "$c outro ciclo: cria a do negócio");
t_eq(p2_val($afd[0] ?? [], 'line_id'), null, "$c outro ciclo: a do negócio sem frente");
t_eq(p2_val(p2d_task($tp, $td), 'gate_stage_id'), null, "$c outro ciclo: a da frente fica sem gate_stage_id");
// e) as duas frentes têm-na (VFX feita, 3D aberta)
$de = $newDeal();
$te1 = p2d_ftask($tp, $de['id'], $de['lines']['vfx'], $sv['ready'], P2D_AF, ['status' => 'done']);
$te2 = p2d_ftask($tp, $de['id'], $de['lines']['3d'], $s3['ready'], P2D_AF);
pipe2_run_rules($tp, deal_get($tp, $de['id']), $entrega, 'enter', null, 1);
t_eq(count(p2d_deal_tasks($tp, $de['id'], P2D_AF)), 0, "$c duas frentes: não cria");
t_eq([(int)(p2d_task($tp, $te1)['gate_stage_id'] ?? 0), (int)(p2d_task($tp, $te2)['gate_stage_id'] ?? 0)], [$sv['delivery'], $sv['delivery']],
     "$c duas frentes: as duas contam para o gate");
// f) regra não obrigatória com a da frente feita
$df = $newDeal();
$tf = p2d_ftask($tp, $df['id'], $df['lines']['3d'], $s3['ready'], P2D_REVER, ['status' => 'done']);
pipe2_run_rules($tp, deal_get($tp, $df['id']), $entrega, 'enter', null, 1);
t_eq(count(p2d_deal_tasks($tp, $df['id'], P2D_REVER)), 0, "$c não obrigatória: não cria");
t_eq(p2_val(p2d_task($tp, $tf), 'gate_stage_id'), null, "$c não obrigatória: não muda gate_stage_id");

// ── ::delivery_reentry ──────────────────────────────────────
$c = '::delivery_reentry';
$dr = $newDeal();
$tr = p2d_ftask($tp, $dr['id'], $dr['lines']['3d'], $s3['ready'], P2D_AF, ['status' => 'done']);
pipe2_run_rules($tp, deal_get($tp, $dr['id']), $entrega, 'enter', null, null);
p2v_front($tp, $dr['lines']['3d'], ['status' => 'running', 'stage_id' => $s3['revisions']]);   // a frente sai de Pronto…
p2v_front($tp, $dr['lines']['3d'], ['status' => 'ready', 'stage_id' => $s3['ready']]);         // … e volta
pipe2_run_rules($tp, deal_get($tp, $dr['id']), $entrega, 'enter', null, null);
t_eq(count(p2d_deal_tasks($tp, $dr['id'], P2D_AF)), 0, "$c a reentrada na Entrega não cria a do negócio");
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND gate_stage_id = ?', [$dr['id'], $sv['delivery']]), 1, "$c um só gate_stage_id posto");
t_eq((int)(p2d_task($tp, $tr)['gate_stage_id'] ?? 0), $sv['delivery'], "$c é o da tarefa feita da frente");

// ── ::skipped_counts_for_gate ───────────────────────────────
$c = '::skipped_counts_for_gate';
$ga = pipe2_deal_gate($tp, deal_get($tp, $da['id']), $entrega);
t_eq(p2d_gate_item($ga, $ta)['ok'] ?? null, false, "$c a aberta da frente aparece por cumprir");
t_eq(p2d_gate_item($ga, $ta)['label'] ?? null, P2D_AF, "$c com o título");
$tp->prepare("UPDATE tasks SET status = 'done', done_at = NOW() WHERE id = ?")->execute([$ta]);
t_eq(p2d_gate_item(pipe2_deal_gate($tp, deal_get($tp, $da['id']), $entrega), $ta)['ok'] ?? null, true, "$c feita: cumprida");
t_eq(p2d_gate_item(pipe2_deal_gate($tp, deal_get($tp, $db_['id']), $entrega), $tb)['ok'] ?? null, true, "$c a feita do caso «feita» conta logo como cumprida");
$ge = pipe2_deal_gate($tp, deal_get($tp, $de['id']), $entrega);
t_eq([p2d_gate_item($ge, $te1)['ok'] ?? null, p2d_gate_item($ge, $te2)['ok'] ?? null], [true, false], "$c duas frentes: dois itens (VFX feita, 3D por fazer)");
// as outras obrigatórias do negócio na Entrega feitas: só falta a da frente 3D
$tp->prepare("UPDATE tasks SET status = 'done', done_at = NOW() WHERE deal_id = ? AND line_id IS NULL AND stage_id = ?")->execute([$de['id'], $sv['delivery']]);
$miss = fn() => array_values(array_map(fn($i) => $i['label'], array_filter(pipe2_deal_gate($tp, deal_get($tp, $de['id']), $entrega), fn($i) => !$i['ok'])));
t_eq($miss(), [P2D_AF], "$c duas frentes: o gate espera pela aberta (3D)");
$tp->prepare("UPDATE tasks SET status = 'done', done_at = NOW() WHERE id = ?")->execute([$te2]);
t_eq($miss(), [], "$c duas frentes: feita a 3D, o gate fica cumprido");
t_eq(p2d_gate_item($ge, $te1)['task'] ?? null, $te1, "$c o item tem o id da tarefa (formato de deal_gate)");

// ── ::other_funnel_rule_on_deal_stage ───────────────────────
$c = '::other_funnel_rule_on_deal_stage';
$o3 = p2d_deal_tasks($tp, $d1['id'], P2D_3D);
t_eq(count($o3), 1, "$c a regra obrigatória só do funil 3D cria uma tarefa");
t_eq((int)($o3[0]['stage_id'] ?? 0), $sv['delivery'], "$c na Entrega do negócio (deals.stage_id), nunca na do funil 3D");
t_true((int)($o3[0]['stage_id'] ?? 0) !== $s3['delivery'], "$c não na Entrega do funil 3D");
t_eq(p2_val($o3[0] ?? [], 'line_id'), null, "$c do negócio");
t_eq((int)($o3[0]['is_gate'] ?? -1), 1, "$c obrigatória");
t_eq(p2d_gate_item(pipe2_deal_gate($tp, $deal1, $entrega), (int)($o3[0]['id'] ?? 0))['ok'] ?? null, false, "$c pipe2_deal_gate lista-a por cumprir");

// ── ::stage_match ───────────────────────────────────────────
$c = '::stage_match';
t_eq((int)(pipe2_stage_match($tp, $entrega, $w['3d'])['id'] ?? 0), $s3['delivery'], "$c pelo papel (delivery)");
t_eq((int)(pipe2_stage_match($tp, stage_get($tp, $sv['kickoff']), $w['3d'])['id'] ?? 0), $s3['kickoff'], "$c pelo tipo (kickoff)");
t_eq((int)(pipe2_stage_match($tp, stage_get($tp, $sv['proposal']), $w['3d'])['id'] ?? 0), $s3['proposal'], "$c pelo papel (proposal)");
t_eq(pipe2_stage_match($tp, stage_get($tp, $sv['fronts'][0]), $w['3d']), null, "$c uma sub-etapa não tem igual noutro funil");

// ── ::front_front ───────────────────────────────────────────
$c = '::front_front';
$dff = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec']);
$lv = pipe2_front_get($tp, $dff['lines']['vfx']);
$l3 = pipe2_front_get($tp, $dff['lines']['3d']);
$dealff = deal_get($tp, $dff['id']);
$rf = pipe2_run_rules($tp, $dealff, stage_get($tp, $sv['fronts'][0]), 'f_enter', $lv, null);
$cena = p2v_rows($tp, 'SELECT * FROM tasks WHERE deal_id = ? AND title = ? ORDER BY id', [$dff['id'], P2D_CENA]);
t_eq(count($cena), 1, "$c f_enter cria a tarefa da frente");
t_eq((int)($cena[0]['line_id'] ?? 0), $dff['lines']['vfx'], "$c com a frente");
t_eq((int)($cena[0]['stage_id'] ?? 0), $sv['fronts'][0], "$c na sub-etapa");
t_eq((string)($cena[0]['dedup_key'] ?? ''), pipe2_dedup_key(P2D_CENA, $dff['id'], 'L' . $dff['lines']['vfx'] . ':S' . $sv['fronts'][0], ''), "$c com a chave da frente");
t_eq($titleOf($rf['tasks']), [P2D_CENA, 'P2T · Entrar (gatilho da v1)'], "$c f_enter apanha também o 'enter' da sub-etapa");
$rd = pipe2_run_rules($tp, $dealff, stage_get($tp, $sv['fronts'][0]), 'dep_met', $lv, null);
t_eq($rd['tasks'], [], "$c dep_met com o mesmo título: não cria outra");
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND title = ?', [$dff['id'], P2D_CENA]), 1, "$c continua uma");
$r3 = pipe2_run_rules($tp, $dealff, stage_get($tp, $s3['fronts'][0]), 'f_enter', $l3, null);
t_eq(count($r3['tasks']), 1, "$c a frente 3D tem a sua (âmbito diferente)");
t_eq((int)(p2d_task($tp, $r3['tasks'][0] ?? 0)['line_id'] ?? 0), $dff['lines']['3d'], "$c com a frente 3D");
t_eq((int)p2v_col($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'auto'", [$dff['id']]), 2, "$c uma linha 'auto' por corrida que criou");

// ── ::cycle ─────────────────────────────────────────────────
$c = '::cycle';
$dcy = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec', 'front_at' => ['vfx' => $sv['fronts'][3]]]);
p2v_front($tp, $dcy['lines']['vfx'], ['cycle_ym' => '2026-11']);
$dealcy = deal_get($tp, $dcy['id']);
$s4 = stage_get($tp, $sv['fronts'][3]);
$run = fn() => pipe2_run_rules($tp, $dealcy, $s4, 'f_enter', pipe2_front_get($tp, $dcy['lines']['vfx']), null)['tasks'];
$a = $run();
t_eq(count($a), 1, "$c cria uma no ciclo");
t_eq((string)(p2d_task($tp, $a[0] ?? 0)['cycle_ym'] ?? ''), '2026-11', "$c com o ciclo da frente");
t_eq($run(), [], "$c outra vez (aberta): nada");
$tp->prepare("UPDATE tasks SET status = 'done', done_at = NOW() WHERE id = ?")->execute([$a[0] ?? 0]);
t_eq($run(), [], "$c outra vez (feita): nada");
p2v_front($tp, $dcy['lines']['vfx'], ['cycle_ym' => '2026-12']);
$b = $run();
t_eq(count($b), 1, "$c ciclo novo: nova");
t_eq((string)(p2d_task($tp, $b[0] ?? 0)['cycle_ym'] ?? ''), '2026-12', "$c com o ciclo novo");

// ── ::rule_dedup_line_cycle ─────────────────────────────────
$c = '::rule_dedup_line_cycle';
$dl = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec']);
$dealdl = deal_get($tp, $dl['id']);
$rule = p2d_rule($tp, $R['cena_enter']);
$x1 = pipe2_task_from_rule($tp, $rule, pipe2_vdeal($dealdl, pipe2_front_get($tp, $dl['lines']['vfx'])), stage_get($tp, $sv['fronts'][0]), today_lisbon(), null);
$x2 = pipe2_task_from_rule($tp, $rule, pipe2_vdeal($dealdl, pipe2_front_get($tp, $dl['lines']['3d'])), stage_get($tp, $s3['fronts'][0]), today_lisbon(), null);
t_true($x1 !== null && $x2 !== null && $x1 !== $x2, "$c a mesma regra para duas frentes cria duas tarefas");
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$dl['id'], $R['cena_enter']]), 2, "$c as duas com a regra");
t_eq(pipe2_task_from_rule($tp, $rule, pipe2_vdeal($dealdl, pipe2_front_get($tp, $dl['lines']['vfx'])), stage_get($tp, $sv['fronts'][0]), today_lisbon(), null), null,
     "$c a mesma frente e o mesmo ciclo: não repete");

// ── ::invoice_only_deal ─────────────────────────────────────
$c = '::invoice_only_deal';
$di = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'front_at' => ['vfx' => $sv['fronts'][1]]]);
$dealdi = deal_get($tp, $di['id']);
$ri = pipe2_run_rules($tp, $dealdi, stage_get($tp, $sv['fronts'][1]), 'f_enter', pipe2_front_get($tp, $di['lines']['vfx']), null);
t_eq($ri['tasks'], [], "$c fatura numa sub-etapa: nada");
pipe2_run_rules($tp, $dealdi, $entrega, 'enter', null, null);
t_eq((int)p2v_col($tp, "SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND title LIKE 'P2T · Emitir fatura 3D%'", [$di['id']]), 0, "$c fatura na Entrega do funil não principal: nada");
$inv = p2v_rows($tp, "SELECT * FROM tasks WHERE deal_id = ? AND title LIKE 'P2T · Emitir fatura · %'", [$di['id']]);
t_eq(count($inv), 1, "$c fatura na Entrega do principal: uma");
t_eq((string)($inv[0]['doc_kind'] ?? ''), 'invoice', "$c com o pedido de documento");
t_eq((string)($inv[0]['title'] ?? ''), 'P2T · Emitir fatura · ' . $dealdi['code'], "$c com o {ID} preenchido");

// ── ::notify_action ─────────────────────────────────────────
$c = '::notify_action';
$dn = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec', 'front_at' => ['vfx' => $sv['fronts'][2]]]);
p2v_front($tp, $dn['lines']['vfx'], ['exec_owner_id' => 6]);
$s3v = stage_get($tp, $sv['fronts'][2]);
$rn = pipe2_run_rules($tp, deal_get($tp, $dn['id']), $s3v, 'f_enter', pipe2_front_get($tp, $dn['lines']['vfx']), null);
t_eq((int)p2v_col($tp, "SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND title = 'P2T · Avisar o responsável'", [$dn['id']]), 0, "$c notificar não cria tarefa");
$nn = p2v_rows($tp, 'SELECT * FROM pipe2_notices WHERE deal_id = ? ORDER BY id', [$dn['id']]);
t_eq(count($nn), 1, "$c um aviso");
t_eq([(string)($nn[0]['kind'] ?? ''), (int)($nn[0]['user_id'] ?? 0), (int)($nn[0]['rule_id'] ?? 0), (int)($nn[0]['line_id'] ?? 0), (int)($nn[0]['stage_id'] ?? 0)],
     ['rule', 6, $R['notify'], $dn['lines']['vfx'], $sv['fronts'][2]], "$c kind 'rule', para o responsável da frente, com a regra, a frente e a sub-etapa");
t_eq((string)($nn[0]['title'] ?? ''), 'P2T · Avisar o responsável', "$c com o título da regra");
t_eq($rn['notices'], [(int)($nn[0]['id'] ?? 0)], "$c pipe2_run_rules devolve o aviso");
t_eq($rn['tasks'], [], "$c e nenhuma tarefa");
$rn2 = pipe2_run_rules($tp, deal_get($tp, $dn['id']), $s3v, 'f_enter', pipe2_front_get($tp, $dn['lines']['vfx']), null);
t_eq($rn2['notices'], [], "$c outra vez: nenhum aviso novo");
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM pipe2_notices WHERE deal_id = ?', [$dn['id']]), 1, "$c continua um");

// ── ::assignee_never_invalid ────────────────────────────────
$c = '::assignee_never_invalid';
$dx = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec', 'exec' => null]);
$dealdx = deal_get($tp, $dx['id']);
$vx = pipe2_vdeal($dealdx, pipe2_front_get($tp, $dx['lines']['vfx']));
t_eq(pipe2_assignee($tp, p2d_rule($tp, $R['svc']), $vx), 6, "$c chefe do serviço ativo: o responsável do departamento");
$tp->exec('UPDATE users SET deactivated_at = NOW() WHERE id = 6');
t_eq(pipe2_assignee($tp, p2d_rule($tp, $R['svc']), $vx), null, "$c chefe desativado e sem pai com chefe: por atribuir");
$tsvc = pipe2_task_from_rule($tp, p2d_rule($tp, $R['svc']), $vx, stage_get($tp, $sv['fronts'][4]), today_lisbon(), null);
t_eq(p2_val(p2d_task($tp, (int)$tsvc), 'member_id'), null, "$c a tarefa fica Por atribuir");
p2v_front($tp, $dx['lines']['vfx'], ['exec_owner_id' => 6]);
t_eq(pipe2_assignee($tp, p2d_rule($tp, $R['exec']), pipe2_vdeal($dealdx, pipe2_front_get($tp, $dx['lines']['vfx']))), null, "$c responsável da frente desativado: por atribuir");
$tp->exec('UPDATE users SET deactivated_at = NULL WHERE id = 6');
p2v_front($tp, $dx['lines']['vfx'], ['exec_owner_id' => null]);
t_eq(pipe2_assignee($tp, p2d_rule($tp, $R['user_sa']), $vx), null, "$c a pessoa da regra é o superadmin: por atribuir");
t_eq(pipe2_assignee($tp, ['assign_to' => 'user', 'assign_ref' => 99] + p2d_rule($tp, $R['user_sa']), $vx), 99, "$c a pessoa da regra (válida)");
t_eq(pipe2_assignee($tp, p2d_rule($tp, $R['exec']), $vx), null, "$c execução numa frente sem responsável e negócio sem execução: por atribuir");
t_eq(pipe2_assignee($tp, p2d_rule($tp, $R['exec']), pipe2_vdeal(['exec_owner_id' => 8] + $dealdx, pipe2_front_get($tp, $dx['lines']['vfx']))), 8,
     "$c frente sem responsável: a execução do negócio");
p2v_front($tp, $dx['lines']['vfx'], ['exec_owner_id' => 7]);
t_eq(pipe2_assignee($tp, p2d_rule($tp, $R['exec']), pipe2_vdeal(['exec_owner_id' => 8] + $dealdx, pipe2_front_get($tp, $dx['lines']['vfx']))), 7,
     "$c execução numa tarefa de frente = responsável da frente");
t_eq(pipe2_assignee($tp, ['assign_to' => 'commercial'] + p2d_rule($tp, $R['exec']), ['owner_id' => 2] + $vx), null, "$c comercial superadmin: por atribuir");
t_eq(pipe2_assignee($tp, ['assign_to' => 'none'] + p2d_rule($tp, $R['exec']), $vx), null, "$c 'none': por atribuir");

// ── ::svc_head ──────────────────────────────────────────────
$c = '::svc_head';
$da2 = p2v_deal($tp, $w, ['adv'], ['at' => 'exec']);
t_eq(pipe2_assignee($tp, ['assign_to' => 'svc_head'] + p2d_rule($tp, $R['svc']), pipe2_vdeal(deal_get($tp, $da2['id']), pipe2_front_get($tp, $da2['lines']['adv']))), 7,
     "$c Adverts → o chefe do departamento-pai (7)");
t_eq(pipe2_assignee($tp, ['assign_to' => 'svc_head'] + p2d_rule($tp, $R['svc']), pipe2_vdeal(deal_get($tp, $da2['id']), null)), 7, "$c tarefa de negócio: o chefe do serviço do funil do negócio");

// ── ::v1_noop ───────────────────────────────────────────────
$c = '::v1_noop';
$dv = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1f]);
$dealv = deal_get($tp, $dv);
t_eq(pipe2_deal_is_v2($tp, $dealv), false, "$c o negócio é v1");
$idv = flow_task_from_rule($tp, p2d_rule($tp, $R['v1']), $dealv, stage_get($tp, $v1k), today_lisbon(), 1);
$tv = p2d_task($tp, (int)$idv);
t_true($idv !== null, "$c cria a tarefa");
t_eq([(string)$tv['dedup_key'], (string)$tv['cycle_ym'], $tv['line_id'], (int)$tv['auto_rule_id'], (int)$tv['stage_id'], (int)$tv['is_gate'], $tv['gate_stage_id']],
     ['', '', null, $R['v1'], $v1k, 1, null], "$c como antes: sem chave, sem ciclo, sem frente, com a regra e a etapa");
t_eq((string)$tv['title'], 'P2T · regra v1', "$c o título da regra");
t_eq(flow_task_from_rule($tp, p2d_rule($tp, $R['v1']), $dealv, stage_get($tp, $v1k), today_lisbon(), 1), null, "$c a 2.ª chamada: null (negócio + regra)");
$lv1 = pipe2_front($tp, $dv, $v1f, ['status' => 'active', 'stage_id' => $v1k]);
t_eq(flow_task_from_rule($tp, p2d_rule($tp, $R['v1']), xsell_vdeal($dealv, xsell_line_get($tp, $lv1)), stage_get($tp, $v1k), today_lisbon(), 1), null,
     "$c numa frente v1 a regra também não repete (a v1 não conta a frente)");
t_eq(pipe2_reassign_scope_sql($tp, $dealv), '', "$c reatribuição: sem filtro por frente");
t_eq(pipe2_reassign_scope_sql($tp, $deal1), ' AND line_id IS NULL', "$c reatribuição num v2: só as do negócio");

// ── ::strict ────────────────────────────────────────────────
$c = '::strict';
$ds = $newDeal();
$ts = p2d_ftask($tp, $ds['id'], $ds['lines']['3d'], $s3['ready'], P2D_AF);
pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES');
try {
    $dealds = deal_get($tp, $ds['id']);
    $rs = pipe2_run_rules($tp, $dealds, $entrega, 'enter', null, 1);
    $ls = pipe2_front_get($tp, $ds['lines']['vfx']);
    pipe2_run_rules($tp, $dealds, stage_get($tp, $sv['fronts'][0]), 'f_enter', $ls, null);
    pipe2_run_rules($tp, $dealds, $s3v, 'f_enter', $ls, null);
    $gs = pipe2_deal_gate($tp, $dealds, $entrega);
    $ss = pipe2_step_gate($tp, $ls, stage_get($tp, $sv['fronts'][0]));
    $err = null;
} catch (Throwable $e) {
    $err = get_class($e) . ': ' . $e->getMessage();
}
pipe2_sql_mode($tp, '');
t_eq($err, null, "$c regras, gates e avisos em ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES sem exceção");
t_true(count($rs['tasks'] ?? []) > 0, "$c criou as tarefas do negócio");
t_eq(p2d_gate_item($gs ?? [], $ts)['ok'] ?? null, false, "$c o gate lista a da frente");
t_eq([$ss['total'] ?? null, $ss['done'] ?? null], [1, 0], "$c o gate da sub-etapa conta a obrigatória criada");

t_done();
