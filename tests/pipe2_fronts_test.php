<?php
/**
 * Pipeline v2, fase 1 — frentes e modelo (admin/inc/pipe2_fronts.php; tarefa 3: D1, D2, D4, D6, D15, D21; M3, M4).
 *
 * O predicado v2 (D1: o funil do negócio tem etapas vivas de negócio com role exec e delivery; nunca o service_code),
 * as frentes (todas em deal_lines, também a principal e a de um negócio de um só serviço), o chefe do serviço, os
 * valores (avença = mensalidade × meses), o período da avença, o requisito do orçamento por serviço e a guarda das
 * etapas em uso. pipe2_kickoff_fronts chama-se aqui em sequência: a idempotência em concorrência é da pipe2_kickoff
 * (tarefa 11), que a chama dentro do lock e da transação.
 *
 * Corre numa base privada do dump da 42, migrada até à SCHEMA_VERSION (p2v_db); o ::predicate_bench só lê a bancada,
 * depois de pipe2_lock e de pipe2_cleanup (sobras «P2T»).
 *
 * Casos: ::predicate ::predicate_bench ::line_v2 ::single_service_has_front ::service_add ::kickoff_idempotent
 *        ::main_not_special ::kickoff_owner ::service_head ::values ::deal_value ::billing_period
 *        ::budget_service_required ::stage_in_use ::stage_in_use_42 ::main_not_special_engine ::v1_noop ::strict
 *
 * Uso: php tests/pipe2_fronts_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/pipe2_fronts.php';
require_once __DIR__ . '/../admin/inc/pipe2_engine.php';   // ::main_not_special_engine
require_once __DIR__ . '/../admin/inc/tasks.php';
pipe2_lock($pdo);
$bench = $pdo;   // depois de p2v_db(), o $pdo global passa a ser o da base privada
pipe2_cleanup($bench);

const P2F_BLOCK = 'Há frentes ou tarefas nesta etapa: mova-as ou apague-as primeiro.';

/** A classe da exceção de $fn, ou ''. */
function p2f_err(callable $fn): string {
    try { $fn(); } catch (Throwable $e) { return get_class($e); }
    return '';
}

/** As frentes de um negócio, para comparar: [funil, is_main, estado, sub-etapa, responsável]. */
function p2f_lines(PDO $pdo, int $dealId): array {
    return array_map(fn($l) => [(int)$l['funnel_id'], (int)$l['is_main'], $l['status'], $l['stage_id'] === null ? null : (int)$l['stage_id'],
                                $l['exec_owner_id'] === null ? null : (int)$l['exec_owner_id']],
        p2v_rows($pdo, 'SELECT * FROM deal_lines WHERE deal_id = ? ORDER BY is_main DESC, position, id', [$dealId]));
}

/** As linhas de deal_line_steps de uma frente: [sub-etapa, ciclo, plan_days]. */
function p2f_steps(PDO $pdo, int $lineId): array {
    return array_map(fn($r) => [(int)$r['stage_id'], $r['cycle_ym'], $r['plan_days'] === null ? null : (int)$r['plan_days']],
        p2v_rows($pdo, 'SELECT stage_id, cycle_ym, plan_days FROM deal_line_steps WHERE line_id = ? ORDER BY id', [$lineId]));
}

/**
 * Uma folha de orçamento com versões: $versions = [[[funil|null, custo], …], …] (linhas «outros», preço fixo, opção A).
 * $accepted = índice da versão aceite (null: nenhuma). Devolve os ids das versões.
 */
function p2f_budget(PDO $pdo, int $dealId, array $versions, ?int $accepted = null): array {
    $sheet = pipe2_insert($pdo, 'budget_sheets', ['deal_id' => $dealId]);
    $vids = [];
    foreach (array_values($versions) as $i => $lines) {
        $vid = pipe2_insert($pdo, 'budget_versions', ['sheet_id' => $sheet, 'num' => $i + 1]);
        foreach (array_values($lines) as $j => [$fid, $cost]) {
            pipe2_insert($pdo, 'budget_lines', ['version_id' => $vid, 'line_key' => 'p2t' . $i . '_' . $j, 'position' => $j, 'kind' => 'other',
                'label' => PIPE2_FUNNEL_PREFIX . 'linha', 'price_unit' => 'fixed', 'unit_price' => $cost, 'qty' => 1, 'opt' => 'A', 'funnel_id' => $fid]);
        }
        $vids[] = $vid;
    }
    if ($accepted !== null) $pdo->prepare('UPDATE budget_sheets SET accepted_version_id = ? WHERE id = ?')->execute([$vids[$accepted], $sheet]);
    return $vids;
}

// ── a base privada e os funis (todos antes de qualquer leitura de flow_order/stage_match) ──
[$db, $tp] = p2v_db();
$w = p2v_world($tp);
$S = array_map(fn($fid) => p2v_stages($tp, $fid), $w);
$mdPlan = p2v_funnel($tp, 'md', ['plan_days' => [0 => 3, 2 => 5]]);
// ::main_not_special_engine: uma regra ao entrar na 2.ª sub-etapa de cada funil
$rEngVfx = p2v_rule($tp, $S['vfx']['fronts'][1], ['title' => 'P2T · entrada vfx', 'is_gate' => 0]);
$rEng3d = p2v_rule($tp, $S['3d']['fronts'][1], ['title' => 'P2T · entrada 3d', 'is_gate' => 0]);
$realVfx = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY id LIMIT 1");
$real3d = (int)p2v_col($tp, "SELECT id FROM funnels WHERE service_code = '3d' AND is_base = 1 ORDER BY id LIMIT 1");
$realMd = (int)p2v_col($tp, "SELECT id FROM funnels WHERE service_code = 'md' AND name NOT LIKE 'P2T%' ORDER BY id LIMIT 1");
// funis de teste que não são v2: só Em execução; Em execução retirada; Entrega numa sub-etapa
$soExec = pipe2_funnel($tp, 'so exec');
pipe2_insert($tp, 'funnel_stages', ['funnel_id' => $soExec, 'name' => 'Em execução', 'kind' => 'production', 'position' => 70, 'role' => 'exec', 'level' => 'deal']);
$retired = pipe2_funnel($tp, 'retirada');
p2v_add_exec_delivery($tp, $retired);
$tp->prepare("UPDATE funnel_stages SET retired_at = NOW() WHERE funnel_id = ? AND role = 'exec'")->execute([$retired]);
$frontRole = pipe2_funnel($tp, 'entrega na frente');
pipe2_insert($tp, 'funnel_stages', ['funnel_id' => $frontRole, 'name' => 'Em execução', 'kind' => 'production', 'position' => 70, 'role' => 'exec', 'level' => 'deal']);
pipe2_insert($tp, 'funnel_stages', ['funnel_id' => $frontRole, 'name' => 'Entrega', 'kind' => 'production', 'position' => 110, 'role' => 'delivery', 'level' => 'front']);
$free = pipe2_funnel($tp, 'livre');
$freeStage = pipe2_insert($tp, 'funnel_stages', ['funnel_id' => $free, 'name' => 'P2T · etapa livre', 'kind' => 'production', 'position' => 60, 'level' => 'deal']);
$usedLine = pipe2_insert($tp, 'funnel_stages', ['funnel_id' => $free, 'name' => 'P2T · com frente', 'kind' => 'production', 'position' => 61, 'level' => 'deal']);
$usedTask = pipe2_insert($tp, 'funnel_stages', ['funnel_id' => $free, 'name' => 'P2T · com tarefa', 'kind' => 'production', 'position' => 62, 'level' => 'deal']);
$dumpDeals = p2v_rows($tp, "SELECT * FROM deals WHERE CAST(COALESCE(code, '') AS BINARY) NOT LIKE 'P2T%'");
$v1Deal = deal_get($tp, (int)p2v_col($tp, "SELECT l.deal_id FROM deal_lines l JOIN deals d ON d.id = l.deal_id WHERE d.phase = 'production' ORDER BY l.deal_id LIMIT 1")
                      ?: (int)p2v_col($tp, 'SELECT deal_id FROM deal_lines ORDER BY deal_id LIMIT 1'));

// ── ::predicate (D1) ────────────────────────────────────────
$c = '::predicate';
t_true(pipe2_funnel_is_v2($tp, $w['vfx']), "$c um funil p2v_funnel é v2");
t_true(!pipe2_funnel_is_v2($tp, $realVfx), "$c o VFX real (xray_kind vfx, is_base 1) não é v2");
t_true($realMd > 0 && pipe2_funnel_is_v2($tp, $realMd), "$c o Motion Design real (sem negócios) é v2");
t_true(!pipe2_funnel_is_v2($tp, $soExec), "$c um funil só com Em execução (sem Entrega) não é v2");
t_true(!pipe2_funnel_is_v2($tp, $retired), "$c um funil com Em execução retirada não é v2");
t_true(!pipe2_funnel_is_v2($tp, $frontRole), "$c a Entrega numa sub-etapa (level front) não conta");
t_true(!pipe2_funnel_is_v2($tp, null) && !pipe2_funnel_is_v2($tp, 0), "$c funil null ou 0 → falso");
t_true(count($dumpDeals) > 0, "$c o dump tem negócios (o teste não é vazio)");
t_eq(array_values(array_filter($dumpDeals, fn($d) => pipe2_deal_is_v2($tp, $d))), [], "$c nenhum negócio do dump é v2");
t_true(!pipe2_deal_is_v2($tp, ['id' => 1, 'funnel_id' => null]), "$c negócio com funnel_id NULL → falso");
t_true(!pipe2_deal_is_v2($tp, null), "$c sem negócio → falso");
$pv = p2v_deal($tp, $w, ['vfx']);
t_true(pipe2_deal_is_v2($tp, deal_get($tp, $pv['id'])), "$c um negócio num funil p2v_funnel é v2");
$sql = pipe2_v2_funnels_sql();
$v2ids = array_map('intval', array_column(p2v_rows($tp, "SELECT id FROM funnels WHERE id IN ($sql) ORDER BY id"), 'id'));
$want = array_map('intval', array_column(p2v_rows($tp, "SELECT id FROM funnels WHERE (service_code IN ('md','cmp','ed') AND name NOT LIKE 'P2T%') OR id IN ("
    . implode(',', array_merge(array_values($w), [$mdPlan])) . ') ORDER BY id'), 'id'));
t_eq($v2ids, $want, "$c pipe2_v2_funnels_sql: os 3 funis novos e os funis v2 de teste, nenhum outro");
// numa fresca não migrada (42): falso, sem exceção
$db42 = pipe2_tmpdb_create(pipe2_base_dump());
$t42 = pipe2_tmpdb_pdo($db42);
$e42 = '';
try {
    $r42 = [pipe2_funnel_is_v2($t42, $realVfx), pipe2_deal_is_v2($t42, $dumpDeals[0])];
} catch (Throwable $e) { $e42 = $e->getMessage(); $r42 = null; }
t_eq([$e42, $r42], ['', [false, false]], "$c numa base na 42 dá falso sem exceção");

// ── ::predicate_bench (bancada, só leitura) ─────────────────
$c = '::predicate_bench';
$bv2 = array_map('intval', array_column(p2v_rows($bench, 'SELECT id FROM funnels WHERE id IN (' . pipe2_v2_funnels_sql() . ") AND name NOT LIKE 'P2T%' ORDER BY id"), 'id'));
$bwant = array_map('intval', array_column(p2v_rows($bench, "SELECT id FROM funnels WHERE service_code IN ('md','cmp','ed') ORDER BY id"), 'id'));
t_eq(count($bwant), 3, "$c a bancada tem os 3 funis novos");
t_eq($bv2, $bwant, "$c na bancada, os funis v2 (fora dos P2T) são exatamente os de md/cmp/ed");
t_eq((int)p2v_col($bench, 'SELECT COUNT(*) FROM deals WHERE funnel_id IN (' . pipe2_v2_funnels_sql() . ") AND CAST(COALESCE(code, '') AS BINARY) NOT LIKE 'P2T%'"), 0,
    "$c nenhum negócio real num funil v2");
$benchDeals = p2v_rows($bench, "SELECT * FROM deals WHERE CAST(COALESCE(code, '') AS BINARY) NOT LIKE 'P2T%'");
t_true(count($benchDeals) > 0, "$c a bancada tem negócios");
t_eq(array_values(array_map(fn($d) => (int)$d['id'], array_filter($benchDeals, fn($d) => pipe2_deal_is_v2($bench, $d)))), [], "$c nenhum negócio real da bancada é v2");

// ── ::line_v2 (puro) ────────────────────────────────────────
$c = '::line_v2';
t_eq([pipe2_line_is_v2(['status' => 'queued']), pipe2_line_is_v2(['status' => 'active']), pipe2_line_is_v2(['status' => 'planned']),
      pipe2_line_is_v2(['status' => 'planned', 'billing' => 'monthly']), pipe2_line_is_v2(['status' => 'merged']), pipe2_line_is_v2(['status' => 'waiting']),
      pipe2_line_is_v2(['status' => 'done']), pipe2_line_is_v2([])],
    [true, false, false, true, true, false, false, false], "$c queued/merged sim; active/planned/waiting/done não; planned com avença sim");
t_eq(PIPE2_FRONT_LIVE, ['planned', 'queued', 'running', 'depwait', 'ready', 'ongoing'], "$c PIPE2_FRONT_LIVE = todas menos merged");

// ── ::single_service_has_front (M3) ─────────────────────────
$c = '::single_service_has_front';
$one = p2v_deal($tp, $w, ['vfx'], ['at' => 'proposal', 'lines' => false]);
$oneDeal = deal_get($tp, $one['id']);
t_eq(p2f_lines($tp, $one['id']), [], "$c sem linhas antes");
$id1 = pipe2_services_ensure($tp, $oneDeal);
t_eq(p2f_lines($tp, $one['id']), [[$w['vfx'], 1, 'planned', null, null]], "$c cria 1 frente planned, principal, no funil do negócio");
t_true($id1 > 0 && (int)p2v_col($tp, 'SELECT id FROM deal_lines WHERE deal_id = ?', [$one['id']]) === $id1, "$c devolve o id da frente");
t_true(p2v_col($tp, 'SELECT created_at FROM deal_lines WHERE id = ?', [$id1]) !== null, "$c com created_at");
t_eq(pipe2_services_ensure($tp, $oneDeal), $id1, "$c a 2.ª chamada devolve o mesmo id");
t_eq(count(p2f_lines($tp, $one['id'])), 1, "$c e não cria outra");
$fr = pipe2_fronts($tp, $one['id']);
t_eq([count($fr), (int)($fr[0]['id'] ?? 0), $fr[0]['funnel_name'] ?? null, $fr[0]['service_code'] ?? null, array_key_exists('stage_name', $fr[0] ?? []) ? $fr[0]['stage_name'] : 'x'],
    [1, $id1, 'P2T · vfx', 'vfx', null], "$c pipe2_fronts: a frente com o funil e o serviço, sem sub-etapa");
$fg = pipe2_front_get($tp, $id1);
t_eq([(int)($fg['id'] ?? 0), $fg['funnel_name'] ?? null, $fg['service_code'] ?? null], [$id1, 'P2T · vfx', 'vfx'], "$c pipe2_front_get: a mesma forma");
t_eq(pipe2_front_get($tp, 0), null, "$c pipe2_front_get de uma frente inexistente → null");

// ── ::service_add ───────────────────────────────────────────
$c = '::service_add';
$sa = p2v_deal($tp, $w, ['vfx'], ['at' => 'proposal', 'lines' => false]);
$saDeal = deal_get($tp, $sa['id']);
$GLOBALS['XSELL_FRESH'] = false;
$l3 = pipe2_service_add($tp, $saDeal, $w['3d']);
t_true(!empty($GLOBALS['XSELL_FRESH']), "$c refresca a cache do crosselling");
t_eq(p2f_lines($tp, $sa['id']), [[$w['vfx'], 1, 'planned', null, null], [$w['3d'], 0, 'planned', null, null]], "$c garante a principal e junta o 3D planned");
t_eq(pipe2_service_add($tp, $saDeal, $w['3d']), $l3, "$c idempotente: o mesmo id");
t_eq(count(p2f_lines($tp, $sa['id'])), 2, "$c e não cria outra");
t_eq(pipe2_service_add($tp, $saDeal, $w['vfx']), (int)p2v_col($tp, 'SELECT id FROM deal_lines WHERE deal_id = ? AND is_main = 1', [$sa['id']]), "$c o funil do negócio é a principal");
t_eq((int)p2v_col($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'p2_services'", [$sa['id']]), 1, "$c regista p2_services uma vez");
t_eq(p2f_err(fn() => pipe2_service_add($tp, $saDeal, $real3d)), 'RuntimeException', "$c recusa um funil v1");
t_eq(count(p2f_lines($tp, $sa['id'])), 2, "$c a recusa não grava");
$kd = p2v_deal($tp, $w, ['vfx'], ['at' => 'kickoff']);
t_eq(p2f_err(fn() => pipe2_service_add($tp, deal_get($tp, $kd['id']), $w['3d'])), 'RuntimeException', "$c recusa depois do Kickoff");

// ── ::kickoff_idempotent (M4) ───────────────────────────────
$c = '::kickoff_idempotent';
$kw = ['vfx' => $w['vfx'], 'md' => $mdPlan];
$k = p2v_deal($tp, $kw, ['vfx', 'md'], ['at' => 'kickoff', 'status' => 'planned']);
$r = pipe2_kickoff_fronts($tp, deal_get($tp, $k['id']));
t_eq($r, ['created' => [], 'queued' => [$k['lines']['vfx'], $k['lines']['md']]], "$c 2 frentes planned → queued, nenhuma criada");
t_eq(array_map(fn($l) => [$l[2], $l[3]], p2f_lines($tp, $k['id'])), [['queued', null], ['queued', null]], "$c as duas Por começar, sem sub-etapa");
$mdS = p2v_stages($tp, $mdPlan)['fronts'];
t_eq(p2f_steps($tp, $k['lines']['vfx']), array_map(fn($s) => [$s, '', null], $S['vfx']['fronts']), "$c vfx: 6 linhas de passos, ciclo '', sem dias");
t_eq(p2f_steps($tp, $k['lines']['md']), [[$mdS[0], '', 3], [$mdS[1], '', null], [$mdS[2], '', 5], [$mdS[3], '', null], [$mdS[4], '', null], [$mdS[5], '', null]],
    "$c md: 6 linhas com os plan_days das sub-etapas");
$sigOf = fn(int $dealId) => p2v_sig($tp, ['deal_lines', 'deal_line_steps', 'deal_history'], 'deal_id = ' . $dealId) + p2v_sig($tp, ['deals'], 'id = ' . $dealId);
$sig = $sigOf($k['id']);
$r2 = pipe2_kickoff_fronts($tp, deal_get($tp, $k['id']));
t_eq($r2, ['created' => [], 'queued' => []], "$c a 2.ª chamada não cria nem passa nenhuma");
t_eq($sigOf($k['id']), $sig, "$c e não muda frentes, passos, negócio nem histórico");
// sem linhas: cria a principal
$k0 = p2v_deal($tp, $w, ['3d'], ['at' => 'kickoff', 'lines' => false]);
$r0 = pipe2_kickoff_fronts($tp, deal_get($tp, $k0['id']));
$main0 = (int)p2v_col($tp, 'SELECT id FROM deal_lines WHERE deal_id = ?', [$k0['id']]);
t_eq($r0['created'], [$main0], "$c num negócio sem linhas cria a principal (created = [id])");
t_true($main0 > 0 && in_array($main0, $r0['queued'], true), "$c e passa-a a Por começar");
t_eq(p2f_lines($tp, $k0['id']), [[$w['3d'], 1, 'queued', null, 6]], "$c a principal criada: queued, sem sub-etapa, chefe do serviço");
t_eq(count(p2f_steps($tp, $main0)), 6, "$c com as 6 linhas de passos");
t_eq(pipe2_kickoff_fronts($tp, deal_get($tp, $k0['id'])), ['created' => [], 'queued' => []], "$c 2.ª chamada no negócio sem linhas: nada");
// uma frente já começada não muda; uma merged não ganha passos
$kc = p2v_deal($tp, $w, ['vfx', '3d', 'ed'], ['at' => 'kickoff', 'status' => ['vfx' => 'planned', '3d' => 'running', 'ed' => 'merged']]);
$rc = pipe2_kickoff_fronts($tp, deal_get($tp, $kc['id']));
t_eq($rc['queued'], [$kc['lines']['vfx']], "$c só a planned passa a queued");
t_eq(p2f_lines($tp, $kc['id'])[1][2] . '|' . p2f_lines($tp, $kc['id'])[2][2], 'running|merged', "$c a running e a merged ficam");
t_eq([count(p2f_steps($tp, $kc['lines']['3d'])), count(p2f_steps($tp, $kc['lines']['ed']))], [6, 0], "$c a viva ganha as linhas em falta; a merged não");

// ── ::main_not_special (M3) ─────────────────────────────────
$c = '::main_not_special';
$ms = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'kickoff', 'status' => 'planned']);
pipe2_kickoff_fronts($tp, deal_get($tp, $ms['id']));
$ml = p2f_lines($tp, $ms['id']);
t_eq([$ml[0][2], $ml[0][3], $ml[0][4]], [$ml[1][2], $ml[1][3], $ml[1][4]], "$c a principal e a outra: mesmo estado, sub-etapa e responsável");
t_eq([$ml[0][2], $ml[0][4]], ['queued', 6], "$c queued e o chefe do serviço (6)");
t_eq([count(p2f_steps($tp, $ms['lines']['vfx'])), count(p2f_steps($tp, $ms['lines']['3d']))], [6, 6], "$c 6 linhas de passos em cada uma");

// ── ::kickoff_owner ─────────────────────────────────────────
$c = '::kickoff_owner';
$ko = p2v_deal($tp, $w, ['vfx', 'adv'], ['at' => 'kickoff', 'status' => 'planned', 'exec' => 8]);
pipe2_kickoff_fronts($tp, deal_get($tp, $ko['id']));
t_eq(array_column(p2f_lines($tp, $ko['id']), 4), [8, 7], "$c principal: deals.exec_owner_id (8); a outra: o chefe do serviço (adv → 7)");
$ks = p2v_deal($tp, $w, ['vfx'], ['at' => 'kickoff', 'status' => 'planned', 'exec' => 2]);
pipe2_kickoff_fronts($tp, deal_get($tp, $ks['id']));
t_eq(array_column(p2f_lines($tp, $ks['id']), 4), [6], "$c o superadmin em exec_owner_id não passa: fica o chefe do serviço");
$kk = p2v_deal($tp, $w, ['vfx'], ['at' => 'kickoff', 'status' => 'planned']);
p2v_front($tp, $kk['lines']['vfx'], ['exec_owner_id' => 3]);
pipe2_kickoff_fronts($tp, deal_get($tp, $kk['id']));
t_eq(array_column(p2f_lines($tp, $kk['id']), 4), [3], "$c um responsável já escolhido fica");

// ── ::service_head ──────────────────────────────────────────
$c = '::service_head';
t_eq([pipe2_user_ok($tp, 1), pipe2_user_ok($tp, '6'), pipe2_user_ok($tp, 2), pipe2_user_ok($tp, 0), pipe2_user_ok($tp, null), pipe2_user_ok($tp, 9999)],
    [1, 6, null, null, null, null], "$c pipe2_user_ok: ativos sim; superadmin, vazio e inexistente não");
t_eq([pipe2_service_head($tp, $w['vfx']), pipe2_service_head($tp, $w['3d']), pipe2_service_head($tp, $w['adv']), pipe2_service_head($tp, $w['cam'])], [6, 6, 7, 7],
    "$c vfx/3d → 6 (Pós-Produção e VFX); adv/cam → 7 (departamento-pai de Adverts/Campanhas)");
$noDept = p2v_funnel($tp, 'ed', ['dept' => null]);
t_eq(pipe2_service_head($tp, $noDept), null, "$c funil sem departamento → null");
$tp->exec('UPDATE users SET deactivated_at = NOW() WHERE id = 6');
t_eq([pipe2_user_ok($tp, 6), pipe2_service_head($tp, $w['vfx'])], [null, null], "$c com o 6 desativado → o do pai do 6 (nenhum) → null");
$tp->exec('UPDATE users SET deactivated_at = NULL, is_active = 0 WHERE id = 6');
t_eq(pipe2_service_head($tp, $w['vfx']), null, "$c com o 6 inativo → null");
$tp->exec('UPDATE users SET is_active = 1 WHERE id = 6');
$tp->exec('UPDATE departments SET head_user_id = 2 WHERE id = 6');
t_eq(pipe2_service_head($tp, $w['vfx']), null, "$c com um superadmin como responsável → null");
$tp->exec('UPDATE departments SET head_user_id = 6 WHERE id = 6');
$tp->exec('UPDATE departments SET head_user_id = 4 WHERE id = 8');
t_eq(pipe2_service_head($tp, $w['adv']), 4, "$c o responsável do próprio departamento vem antes do pai");
$tp->exec('UPDATE departments SET head_user_id = NULL WHERE id = 8');
t_eq(pipe2_service_head($tp, $w['vfx']), 6, "$c reposto → 6");

// ── ::values (X3, D15) ──────────────────────────────────────
$c = '::values';
$val = fn(int $line) => p2v_col($tp, 'SELECT value FROM deal_lines WHERE id = ?', [$line]);
$v1f = p2v_deal($tp, $w, ['vfx'], ['budget' => 0, 'value_estimate' => 1500]);
pipe2_store_values($tp, deal_get($tp, $v1f['id']));
t_eq($val($v1f['lines']['vfx']), '1500.00', "$c 1 frente, budget 0.00, value_estimate 1500 → 1500.00");
$v2f = p2v_deal($tp, $w, ['vfx', '3d'], ['budget' => 4000]);
p2f_budget($tp, $v2f['id'], [[[$w['vfx'], 300], [$w['3d'], 100]]]);
pipe2_store_values($tp, deal_get($tp, $v2f['id']));
t_eq([$val($v2f['lines']['vfx']), $val($v2f['lines']['3d'])], ['3000.00', '1000.00'], "$c 2 frentes, linhas 300 (vfx) e 100 (3d), total 4000 → 3000 e 1000");
$v2e = p2v_deal($tp, $w, ['vfx', '3d'], ['budget' => 0, 'value_estimate' => 4000]);
p2f_budget($tp, $v2e['id'], [[[$w['vfx'], 300], [$w['3d'], 100]]]);
pipe2_store_values($tp, deal_get($tp, $v2e['id']));
t_eq([$val($v2e['lines']['vfx']), $val($v2e['lines']['3d'])], ['3000.00', '1000.00'], "$c 2 frentes com budget 0.00: o total é o value_estimate (4000)");
$va = p2v_deal($tp, $w, ['cam'], ['billing' => ['cam' => [500, 12]]]);
pipe2_store_values($tp, deal_get($tp, $va['id']));
t_eq($val($va['lines']['cam']), '6000.00', "$c frente avença 500 × 12 → 6000.00");
$vm = p2v_deal($tp, $w, ['vfx', 'cam'], ['budget' => 9000, 'billing' => ['cam' => [500, 12]]]);
pipe2_store_values($tp, deal_get($tp, $vm['id']));
t_eq($val($vm['lines']['cam']), '6000.00', "$c com 2 frentes, a avença fica com mensalidade × meses");
t_eq([pipe2_line_value(['billing' => 'monthly', 'monthly_fee' => '500.00', 'fee_months' => 12, 'value' => '1.00']), pipe2_line_value(['billing' => '', 'value' => '250.50']),
      pipe2_line_value(['billing' => '', 'value' => null])], [6000.0, 250.5, null], "$c pipe2_line_value: avença, pontual e sem valor");
t_eq([pipe2_line_is_monthly(['billing' => 'monthly']), pipe2_line_is_monthly(['billing' => '']), pipe2_line_is_monthly([])], [true, false, false], "$c pipe2_line_is_monthly");

// ── ::deal_value (D15) ──────────────────────────────────────
$c = '::deal_value';
t_eq(pipe2_deal_value($tp, deal_get($tp, $va['id'])), 6000.0, "$c só avença 500 × 12 → 6000");
$va2 = p2v_deal($tp, $w, ['cam', 'adv'], ['budget' => 0, 'value_estimate' => 10, 'billing' => ['cam' => [500, 12], 'adv' => [100, 3]]]);
t_eq(pipe2_deal_value($tp, deal_get($tp, $va2['id'])), 6300.0, "$c só avenças: a soma (6000 + 300)");
t_eq(pipe2_deal_value($tp, deal_get($tp, $vm['id'])), 9000.0, "$c avença + pontual com budget 9000 → 9000");
t_eq(pipe2_deal_value($tp, deal_get($tp, $v2f['id'])), 4000.0, "$c sem avenças → budget");
t_eq(pipe2_deal_value($tp, deal_get($tp, $v1f['id'])), 1500.0, "$c sem avenças e budget 0.00 → value_estimate");

// ── ::billing_period (D15, puro) ────────────────────────────
$c = '::billing_period';
t_eq(pipe2_billing_period(['fee_months' => 12], '2026-11-15'), ['start' => '2026-11-01', 'end' => '2027-10-31'], "$c início 2026-11-15, 12 meses");
t_eq(pipe2_billing_period(['fee_months' => 1], '2026-11-15 10:30:00'), ['start' => '2026-11-01', 'end' => '2026-11-30'], "$c 1 mês (início com hora)");
t_eq(pipe2_billing_period(['fee_months' => 2], '2027-01-31'), ['start' => '2027-01-01', 'end' => '2027-02-28'], "$c 31 de janeiro, 2 meses → fim de fevereiro");
t_eq(pipe2_billing_ended(['cancelled_at' => '2026-12-01 10:00:00', 'billing_end' => '2027-10-31'], '2026-12-02'), true, "$c cancelada → terminada");
t_eq(pipe2_billing_ended(['cancelled_at' => null, 'billing_end' => '2027-10-31'], '2027-10-31'), false, "$c hoje = billing_end → ainda não");
t_eq(pipe2_billing_ended(['cancelled_at' => null, 'billing_end' => '2027-10-31'], '2027-11-01'), true, "$c o dia seguinte → terminada");
t_eq(pipe2_billing_ended(['cancelled_at' => null, 'billing_end' => null], '2030-01-01'), false, "$c sem fim nem cancelamento → não");

// ── ::budget_service_required (M4) ──────────────────────────
$c = '::budget_service_required';
$want = fn(bool $ok, int $n) => [['label' => 'Orçamento: cada linha com o serviço', 'ok' => $ok, 'hint' => $ok ? '' : "Falta o serviço em $n linha(s) do orçamento."]];
$br = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'proposal']);
$bv = p2f_budget($tp, $br['id'], [[[null, 50], [$w['vfx'], 300]]]);
t_eq(pipe2_requirements($tp, deal_get($tp, $br['id'])), $want(false, 1), "$c 2 serviços e uma linha sem funnel_id → ok false, 1 linha");
$tp->prepare('UPDATE budget_lines SET funnel_id = ? WHERE version_id = ? AND funnel_id IS NULL')->execute([$w['3d'], $bv[0]]);
t_eq(pipe2_requirements($tp, deal_get($tp, $br['id'])), $want(true, 0), "$c com o funnel_id → ok true");
$tp->prepare('UPDATE budget_lines SET funnel_id = ? WHERE version_id = ? AND funnel_id = ?')->execute([$w['cam'], $bv[0], $w['3d']]);
t_eq(pipe2_requirements($tp, deal_get($tp, $br['id'])), $want(false, 1), "$c um funil que não é de nenhuma frente também falta");
$bb = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'proposal']);
p2f_budget($tp, $bb['id'], [[[null, 1], [null, 2]], [[$w['vfx'], 1]]]);
t_eq(pipe2_requirements($tp, deal_get($tp, $bb['id'])), $want(true, 0), "$c sem versão aceite, conta a última");
$bc = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'proposal']);
p2f_budget($tp, $bc['id'], [[[null, 1], [null, 2]], [[$w['vfx'], 1]]], 0);
t_eq(pipe2_requirements($tp, deal_get($tp, $bc['id'])), $want(false, 2), "$c com versão aceite, conta a aceite");
$bs = p2v_deal($tp, $w, ['vfx'], ['at' => 'proposal']);
p2f_budget($tp, $bs['id'], [[[null, 1]]]);
t_eq(pipe2_requirements($tp, deal_get($tp, $bs['id'])), [], "$c um só serviço → []");
$bm = p2v_deal($tp, $w, ['vfx', '3d', 'ed'], ['at' => 'proposal', 'status' => ['ed' => 'merged']]);
p2f_budget($tp, $bm['id'], [[[$w['vfx'], 1], [$w['3d'], 1]]]);
t_eq(pipe2_requirements($tp, deal_get($tp, $bm['id'])), $want(true, 0), "$c a frente merged não conta como serviço");
$bv1 = pipe2_deal($tp, ['phase' => 'pipeline', 'funnel_id' => $realVfx]);
pipe2_front($tp, $bv1, $realVfx);
pipe2_front($tp, $bv1, $real3d);
p2f_budget($tp, $bv1, [[[null, 1]]]);
t_eq(pipe2_requirements($tp, deal_get($tp, $bv1)), [], "$c v1 (2 serviços, linha sem serviço) → []");

// ── ::stage_in_use (D21) ────────────────────────────────────
$c = '::stage_in_use';
t_eq(pipe2_stage_delete_block($tp, $freeStage), '', "$c etapa sem nada → ''");
$ud = pipe2_deal($tp, ['phase' => 'pipeline', 'funnel_id' => $free]);
pipe2_front($tp, $ud, $free, ['stage_id' => $usedLine, 'status' => 'merged']);
t_eq(pipe2_stage_delete_block($tp, $usedLine), P2F_BLOCK, "$c com uma deal_line (qualquer estado) → a mensagem de D21");
pipe2_task($tp, $ud, ['stage_id' => $usedTask, 'status' => 'done']);
t_eq(pipe2_stage_delete_block($tp, $usedTask), P2F_BLOCK, "$c com uma tarefa feita → a mesma");
t_eq(pipe2_stage_delete_block($tp, $freeStage), '', "$c a etapa livre continua livre");

// ── ::stage_in_use_42 (o caminho v1 numa base na 42) ────────
$c = '::stage_in_use_42';
$s42 = (int)p2v_col($t42, "SELECT id FROM funnel_stages WHERE kind = 'production' ORDER BY id LIMIT 1");
$e42 = '';
try { $b42 = pipe2_stage_delete_block($t42, 999999); } catch (Throwable $e) { $e42 = $e->getMessage(); $b42 = null; }
t_eq([$e42, $b42], ['', ''], "$c numa base na 42, uma etapa sem nada → '' sem exceção");
t_true($s42 > 0, "$c a 42 tem etapas de produção");
pipe2_tmpdb_drop($db42);

// ── ::main_not_special_engine (M3, tarefa 10) ───────────────
$c = '::main_not_special_engine';
pipe2_request_reset();
$me = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec']);
foreach ($me['lines'] as $lid) p2v_steps($tp, $lid);
$gv = p2v_task($tp, $me['id'], ['title' => 'P2T · fim S1 vfx', 'line_id' => $me['lines']['vfx'], 'stage_id' => $S['vfx']['fronts'][0], 'is_gate' => 1]);
$g3 = p2v_task($tp, $me['id'], ['title' => 'P2T · fim S1 3d', 'line_id' => $me['lines']['3d'], 'stage_id' => $S['3d']['fronts'][0], 'is_gate' => 1]);
task_done($tp, $gv);
task_done($tp, $g3);
$lines = p2f_lines($tp, $me['id']);
t_eq([$lines[0][1], $lines[0][3], $lines[1][1], $lines[1][3]], [1, $S['vfx']['fronts'][1], 0, $S['3d']['fronts'][1]],
     "$c a principal e a outra avançam pelo gate da sua sub-etapa");
$born = p2v_rows($tp, 'SELECT auto_rule_id, line_id, stage_id FROM tasks WHERE deal_id = ? AND auto_rule_id IN (?, ?) ORDER BY id', [$me['id'], $rEngVfx, $rEng3d]);
t_eq(array_map(fn($r) => [(int)$r['auto_rule_id'], (int)$r['line_id'], (int)$r['stage_id']], $born),
     [[$rEngVfx, $me['lines']['vfx'], $S['vfx']['fronts'][1]], [$rEng3d, $me['lines']['3d'], $S['3d']['fronts'][1]]],
     "$c as tarefas de cada uma, também as da principal, têm line_id");

// ── ::v1_noop (regra de ouro) ───────────────────────────────
$c = '::v1_noop';
t_true($v1Deal !== null && !pipe2_deal_is_v2($tp, $v1Deal), "$c há um negócio v1 do dump com frentes");
$id = (int)$v1Deal['id'];
$before = p2v_sig($tp, ['deal_lines', 'deal_line_steps', 'deal_history', 'tasks'], 'deal_id = ' . $id) + p2v_sig($tp, ['deals'], 'id = ' . $id);
t_eq(pipe2_services_ensure($tp, $v1Deal), 0, "$c pipe2_services_ensure → 0");
t_eq(pipe2_kickoff_fronts($tp, $v1Deal), ['created' => [], 'queued' => []], "$c pipe2_kickoff_fronts → nada");
pipe2_store_values($tp, $v1Deal);
t_eq(p2f_err(fn() => pipe2_service_add($tp, $v1Deal, $w['3d'])), 'RuntimeException', "$c pipe2_service_add recusa");
t_eq(pipe2_requirements($tp, $v1Deal), [], "$c pipe2_requirements → []");
$after = p2v_sig($tp, ['deal_lines', 'deal_line_steps', 'deal_history', 'tasks'], 'deal_id = ' . $id) + p2v_sig($tp, ['deals'], 'id = ' . $id);
t_eq($after, $before, "$c nada mudou no negócio v1 (frentes, passos, histórico, tarefas, negócio)");

// ── ::strict (ONLY_FULL_GROUP_BY, STRICT_ALL_TABLES) ────────
$c = '::strict';
$st = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'kickoff', 'status' => 'planned']);
pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES');
$es = '';
try {
    $n = count(p2v_rows($tp, 'SELECT id FROM funnels WHERE id IN (' . pipe2_v2_funnels_sql() . ')'));
    $nf = count(pipe2_fronts($tp, $st['id']));
    $rs = pipe2_kickoff_fronts($tp, deal_get($tp, $st['id']));
    pipe2_store_values($tp, deal_get($tp, $st['id']));
    $rq = pipe2_requirements($tp, deal_get($tp, $st['id']));
} catch (Throwable $e) { $es = $e->getMessage(); }
t_eq($es, '', "$c as consultas e escritas correm em modo estrito");
t_true(($n ?? 0) > 0 && ($nf ?? 0) === 2 && count($rs['queued'] ?? []) === 2, "$c com os resultados esperados");
pipe2_sql_mode($tp, '');

t_done();
