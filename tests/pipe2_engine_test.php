<?php
/**
 * Pipeline v2, fase 1 — o motor do negócio (admin/inc/pipe2_deal.php e os ganchos em deal_move, deal_change_funnel,
 * flow_on_enter, flow_gate_check e metrics_on_log; tarefa 11: D6, D9, D10, D16; M4, E1, E2, E3, E4, E6, T5; Review
 * Focus 1, 6 e 7).
 *
 * O Kickoff cria as frentes (Por começar), as linhas de passos, as ligações do modelo da categoria, a categoria e os
 * valores, uma vez — também com dois deal_move ao mesmo tempo (lock do negócio, transação com FOR UPDATE, releitura).
 * O negócio move-se sozinho («Sistema», com nota): Kickoff → Em execução quando a primeira frente entra numa
 * sub-etapa (o sistema começa-as com o gate do Kickoff cumprido e pelo menos uma obrigatória); Em execução → Entrega
 * quando todas as frentes estão concluídas (regras all_ready antes, regras de fecho ao entrar); Entrega → Em execução
 * quando uma frente sai de Pronto; Entrega → Concluído com o gate cumprido e com a aprovação final e o pagamento final
 * entre as obrigatórias (senão só um humano conclui). Um humano não entra nem sai de Em execução/Entrega (salvo
 * Entrega → Concluído), a não ser um admin a forçar com motivo. Num negócio v1 nada muda.
 *
 * Corre só em bases privadas (p2v_db) e em subprocessos sobre elas (p2v_race, p2v_probe).
 *   php tests/pipe2_engine_test.php
 * Casos: ::metrics_system_line ::system_moves_only ::change_funnel_refused ::v1_noop ::kickoff_creates_fronts
 *        ::kickoff_idempotent ::kickoff_race ::kickoff_reread_after_lock ::kickoff_gate_starts_fronts
 *        ::multi_front_deal_moves ::all_ready_rules ::blocked_gate_message ::done_gate ::done_needs_mandatory
 *        ::auto_done_delivered_at ::back_from_delivery ::concurrent_fronts_ready ::stale_move_refused
 *        ::deferred_in_transaction ::system_actor_deal ::no_gate_alert
 *        ganchos do crosselling (tarefa 13): ::front_move_bypasses_deal_move ::close_missing_hook ::proposal_gate_v2
 *        ::xsell_tick_ignores_v2
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/tasks.php';
require_once __DIR__ . '/../admin/inc/pipeline.php';
require_once __DIR__ . '/../admin/inc/pipeline_flow.php';
require_once __DIR__ . '/../admin/inc/metrics.php';
require_once __DIR__ . '/../admin/inc/pipe2_engine.php';
if (is_file(__DIR__ . '/../admin/inc/pipe2_deal.php')) require_once __DIR__ . '/../admin/inc/pipe2_deal.php';

const P2E_SYS_MSG = 'Em execução e Entrega mudam sozinhas, pelas frentes; só um admin pode forçar, com motivo.';
const P2E_CLOSE_TITLES = ['Aprovação final do cliente por escrito', 'Enviar entregáveis finais com comprovativo de download',
                          'Documento de direitos e licenças (música, stock, talento: âmbito e validade)'];

/** [classe, mensagem, exceção] de $fn, ou ['', '', null]. */
function p2e_err(callable $fn): array {
    try { $fn(); } catch (Throwable $e) { return [get_class($e), $e->getMessage(), $e]; }
    return ['', '', null];
}

/** O valor de uma coluna (NULL incluído); '(falta)' se a chave não existir. */
function p2e_v(array $row, string $k) {
    return array_key_exists($k, $row) ? $row[$k] : '(falta)';
}

function p2e_n(PDO $pdo, string $sql, array $a = []): int {
    return (int)p2v_col($pdo, $sql, $a);
}

function p2e_line(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM deal_lines WHERE id = ?', [$id])[0] ?? [];
}

function p2e_deal(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM deals WHERE id = ?', [$id])[0] ?? [];
}

/** Os movimentos 'stage' do negócio (por id). */
function p2e_stage_rows(PDO $pdo, int $deal): array {
    return p2v_rows($pdo, "SELECT * FROM deal_history WHERE deal_id = ? AND event = 'stage' ORDER BY id", [$deal]);
}

/** Uma obrigatória do negócio numa etapa (título exato: sem o prefixo do pipe2_task). */
function p2e_deal_gate(PDO $pdo, int $deal, int $stage, string $title, array $o = []): int {
    $id = p2v_task($pdo, $deal, ['stage_id' => $stage, 'is_gate' => 1] + $o);
    $pdo->prepare('UPDATE tasks SET title = ? WHERE id = ?')->execute([$title, $id]);
    return $id;
}

/** O id da tarefa do negócio com este título (normalizado ao início), ou 0. */
function p2e_task_like(PDO $pdo, int $deal, string $start): int {
    return (int)p2v_col($pdo, 'SELECT id FROM tasks WHERE deal_id = ? AND title LIKE ? ORDER BY id LIMIT 1', [$deal, $start . '%']);
}

/** Contagens de um negócio: frentes, linhas de passos, ligações. */
function p2e_counts(PDO $pdo, int $deal): array {
    return [p2e_n($pdo, 'SELECT COUNT(*) FROM deal_lines WHERE deal_id = ?', [$deal]),
            p2e_n($pdo, 'SELECT COUNT(*) FROM deal_line_steps WHERE deal_id = ?', [$deal]),
            p2e_n($pdo, 'SELECT COUNT(*) FROM deal_line_deps WHERE deal_id = ?', [$deal])];
}

// ── a base privada, os funis e as regras (tudo antes das leituras de flow_order/stage_match/mx_stages) ──
[$db, $tp] = p2v_db();
$w = p2v_world($tp, ['vfx', '3d', 'ed', 'md']);   // com regras (Kickoff, all_ready, fecho)
$wn = p2v_world($tp, ['vfx', '3d']);              // sem regras (como os de funnel_create com $v2 = true)
$S = p2v_stages($tp, $w['vfx']);
$S3 = p2v_stages($tp, $w['3d']);
$SE = p2v_stages($tp, $w['ed']);
$N = p2v_stages($tp, $wn['vfx']);
$N3 = p2v_stages($tp, $wn['3d']);
$K = $S['kickoff'];
$rKick = p2v_rule($tp, $K, ['title' => 'P2T · obrigatória do Kickoff', 'trig' => 'enter', 'assign_to' => 'owner']);
$rAll = p2v_rule($tp, $S['exec'], ['title' => 'P2T · todas prontas', 'trig' => 'all_ready', 'assign_to' => 'owner', 'is_gate' => 0]);
$closeV = p2v_closing_rules($tp, $S['delivery']);
$close3 = p2v_closing_rules($tp, $S3['delivery']);
$tp->prepare('INSERT INTO xsell_rules (from_funnel_id, to_funnel_id, days, is_active, created_at) VALUES (?, ?, 10, 1, ?)')
   ->execute([$w['vfx'], $w['md'], date('Y-m-d H:i:s')]);
$v1a = pipe2_funnel($tp, 'v1 a', true);
$v1b = pipe2_funnel($tp, 'v1 b', true);
$v1f = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY id LIMIT 1");
$v1k = (int)p2v_col($tp, "SELECT id FROM funnel_stages WHERE funnel_id = ? AND kind = 'kickoff' ORDER BY position, id LIMIT 1", [$v1f]);
$v1p = (int)p2v_col($tp, "SELECT id FROM funnel_stages WHERE funnel_id = ? AND kind = 'production' AND level = 'deal' AND retired_at IS NULL ORDER BY position, id LIMIT 1", [$v1f]);
$v13d = (int)p2v_col($tp, "SELECT id FROM funnels WHERE service_code = '3d' AND is_base = 1 ORDER BY id LIMIT 1");
$today = today_lisbon();
$sysDeals = [];   // negócios com movimentos do sistema (para o ::no_gate_alert)

// ── ::metrics_system_line (D9: metrics_on_log) ──────────────
$c = '::metrics_system_line';
p2v_as(99);
$dm = p2v_deal($tp, $w, ['vfx'], ['at' => 'kickoff']);
metrics_on_log($tp, $dm['id'], 'stage', ['from_stage' => $K, 'to_stage' => $S['exec'], 'to_funnel' => $w['vfx'], 'line' => $dm['lines']['vfx'], 'system' => true]);
$ev = p2v_rows($tp, 'SELECT * FROM stage_events WHERE deal_id = ? ORDER BY id', [$dm['id']]);
t_eq(count($ev), 1, "$c um stage_events");
t_eq([$ev[0]['step'] ?? null, (int)($ev[0]['line_id'] ?? 0), array_key_exists('user_id', $ev[0] ?? []) ? $ev[0]['user_id'] : '(falta)'], ['producao', $dm['lines']['vfx'], null],
     "$c com 'system', sem utilizador (mesmo com sessão) e com a frente de 'line'");
metrics_on_log($tp, $dm['id'], 'stage', ['from_stage' => $K, 'to_stage' => $S['exec'], 'to_funnel' => $w['vfx']]);
t_eq((int)p2v_col($tp, 'SELECT user_id FROM stage_events WHERE deal_id = ? ORDER BY id DESC LIMIT 1', [$dm['id']]), 99, "$c sem 'system' nem user_id, a sessão (como antes)");

// ── ::system_moves_only (E1, D9) ────────────────────────────
$c = '::system_moves_only';
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'kickoff']);
p2v_as(99);
t_eq(array_slice(p2e_err(fn() => deal_move($tp, deal_get($tp, $d['id']), $S['exec'])), 0, 2), ['RuntimeException', P2E_SYS_MSG], "$c humano para Em execução: recusa com a mensagem");
t_eq(array_slice(p2e_err(fn() => deal_move($tp, deal_get($tp, $d['id']), $S['exec'], 'quero')), 0, 2), ['RuntimeException', P2E_SYS_MSG], "$c um não-admin com motivo também não");
p2v_as(1);
t_eq(array_slice(p2e_err(fn() => deal_move($tp, deal_get($tp, $d['id']), $S['exec'])), 0, 2), ['RuntimeException', P2E_SYS_MSG], "$c um admin sem motivo também não");
t_eq((int)p2e_deal($tp, $d['id'])['stage_id'], $K, "$c o negócio fica no Kickoff");
[$cls] = p2e_err(fn() => deal_move($tp, deal_get($tp, $d['id']), $S['exec'], 'teste de força'));
t_eq($cls, '', "$c um admin com motivo passa");
t_eq((int)p2e_deal($tp, $d['id'])['stage_id'], $S['exec'], "$c e o negócio fica em Em execução");
$gf = p2v_rows($tp, "SELECT * FROM deal_history WHERE deal_id = ? AND event = 'gate_force'", [$d['id']]);
t_eq(count($gf), 1, "$c com um gate_force no histórico");
t_true(str_contains((string)($gf[0]['note'] ?? ''), 'Passagem forçada') && str_contains((string)($gf[0]['note'] ?? ''), 'teste de força'), "$c com a nota da passagem forçada e o motivo");
p2v_as(99);
t_eq(array_slice(p2e_err(fn() => deal_move($tp, deal_get($tp, $d['id']), $K)), 0, 2), ['RuntimeException', P2E_SYS_MSG], "$c sair de Em execução para trás também não");

// ── ::change_funnel_refused ─────────────────────────────────
$c = '::change_funnel_refused';
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
t_eq(array_slice(p2e_err(fn() => deal_change_funnel($tp, deal_get($tp, $d['id']), $w['3d'], $S3['exec'])), 0, 2),
     ['RuntimeException', 'Neste negócio em execução, mudar de funil ainda não está disponível.'], "$c v2 em produção: a mensagem");
t_eq((int)p2e_deal($tp, $d['id'])['funnel_id'], $w['vfx'], "$c e o funil não muda");
$dv = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1a, 'owner_id' => 99]);
deal_change_funnel($tp, deal_get($tp, $dv), $v1b);
$Dv = p2e_deal($tp, $dv);
t_eq([(int)$Dv['funnel_id'], (int)$Dv['stage_id']], [$v1b, (int)funnel_fixed_stage($tp, $v1b, 'kickoff')], "$c v1: muda de funil como antes (Kickoff do funil novo)");

// ── ::v1_noop (regra de ouro) ───────────────────────────────
$c = '::v1_noop';
pipe2_request_reset();
$dv = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1f, 'stage_id' => $v1k, 'owner_id' => 99, 'sync_mode' => 'sync']);
$lv1 = pipe2_front($tp, $dv, $v1f, ['is_main' => 1, 'status' => 'planned']);
$lv2 = pipe2_front($tp, $dv, $v13d, ['is_main' => 0, 'status' => 'planned']);
t_eq(pipe2_deal_is_v2($tp, deal_get($tp, $dv)), false, "$c o negócio é v1");
flow_on_enter($tp, $dv, $v1k);
t_eq([p2e_line($tp, $lv1)['status'] ?? '', p2e_line($tp, $lv2)['status'] ?? ''], ['active', 'active'], "$c as frentes v1 ficam em curso pelo xsell_kickoff (sincronizadas)");
t_eq([p2e_n($tp, 'SELECT COUNT(*) FROM deal_line_steps WHERE deal_id = ?', [$dv]), (string)p2e_deal($tp, $dv)['category'], p2e_deal($tp, $dv)['category_at']],
     [0, '', null], "$c sem linhas de passos e sem categoria");
p2v_as(99);
t_eq(array_slice(p2e_err(fn() => deal_move($tp, deal_get($tp, $dv), $v1p)), 0, 2),
     ['RuntimeException', 'Neste negócio de crosselling a produção anda por frente (cada serviço tem a sua etapa).'], "$c deal_move v1 com 2 frentes: o bloqueio de sempre");
t_eq(pipe2_moves(), [], "$c nenhum movimento no registo do pedido");

// ── ::kickoff_creates_fronts (M4, D8) ───────────────────────
$c = '::kickoff_creates_fronts';
pipe2_request_reset();
$dk = p2v_deal($tp, $w, ['vfx', '3d', 'ed'], ['at' => 'feedback', 'budget' => 9000]);
t_eq(array_map(fn($l) => p2e_line($tp, $l)['status'] ?? '', array_values($dk['lines'])), ['planned', 'planned', 'planned'], "$c antes: as frentes planned");
p2v_as(1);
[$cls, $msg] = p2e_err(fn() => $GLOBALS['p2e_msg'] = deal_move($tp, deal_get($tp, $dk['id']), $K, 'teste'));
t_eq($cls, '', "$c deal_move de Aguardar feedback para o Kickoff (forçado por admin) passa" . ($msg !== '' ? ': ' . $msg : ''));
t_true(str_starts_with((string)($GLOBALS['p2e_msg'] ?? ''), 'Negócio fechado!'), "$c com a mensagem de sempre");
$L = array_map(fn($l) => p2e_line($tp, $l), $dk['lines']);
t_eq(array_map(fn($r) => [$r['status'] ?? '', p2e_v($r, 'stage_id')], array_values($L)), [['queued', null], ['queued', null], ['queued', null]], "$c as 3 frentes Por começar, sem sub-etapa");
t_eq(p2v_rows($tp, "SELECT line_id, COUNT(*) AS n FROM deal_line_steps WHERE deal_id = ? AND cycle_ym = '' GROUP BY line_id ORDER BY line_id", [$dk['id']]),
     array_map(fn($l) => ['line_id' => $l, 'n' => 6], array_values($dk['lines'])), "$c 6 linhas de passos por frente (ciclo '')");
$deps = array_map(fn($r) => [(int)$r['from_line_id'], (int)$r['from_stage_id'], (int)$r['to_line_id'], (int)$r['to_stage_id'], $r['source']],
                  p2v_rows($tp, 'SELECT * FROM deal_line_deps WHERE deal_id = ? ORDER BY id', [$dk['id']]));
t_eq($deps, [[$dk['lines']['3d'], $S3['name']['Lighting e render'], $dk['lines']['ed'], $SE['name']['Picture lock'], 'model'],
             [$dk['lines']['vfx'], $S['name']['Finais e QC'], $dk['lines']['ed'], $SE['name']['Picture lock'], 'model']],
     "$c as ligações do modelo postprod (3D:Lighting e render → Edição:Picture lock, VFX:Finais e QC → Edição:Picture lock)");
$Dk = p2e_deal($tp, $dk['id']);
t_eq([(string)$Dk['category'], $Dk['category_at'] !== null], ['postprod', true], "$c deals.category 'postprod' com category_at");
t_eq(array_map(fn($r) => $r['value'], array_values($L)), ['9000.00', '0.00', '0.00'], "$c os valores das frentes (sem orçamento: tudo na principal)");
t_eq(p2e_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$dk['id'], $rKick]), 1, "$c a regra enter do Kickoff criada uma vez");
t_eq(p2v_rows($tp, 'SELECT stage_id, line_id, is_gate FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$dk['id'], $rKick]),
     [['stage_id' => $K, 'line_id' => null, 'is_gate' => 1]], "$c no Kickoff, do negócio, obrigatória");
t_eq(p2e_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'p2_category'", [$dk['id']]), 1, "$c a categoria no histórico uma vez");

// ── ::kickoff_idempotent ────────────────────────────────────
$c = '::kickoff_idempotent';
$before = [p2e_counts($tp, $dk['id']), p2e_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ?', [$dk['id']]),
           p2e_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'p2_category'", [$dk['id']])];
flow_on_enter($tp, $dk['id'], $K);
flow_on_enter($tp, $dk['id'], $K);
t_eq([p2e_counts($tp, $dk['id']), p2e_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ?', [$dk['id']]),
      p2e_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'p2_category'", [$dk['id']])], $before,
     "$c flow_on_enter do Kickoff outra vez (duas): as mesmas frentes, passos, ligações, tarefas e categoria");

// ── ::kickoff_race (Review Focus 7, M4) ─────────────────────
$c = '::kickoff_race';
for ($round = 1; $round <= 5; $round++) {
    $dr = p2v_deal($tp, $w, ['vfx', '3d', 'ed'], ['at' => 'feedback']);
    // sem a principal: o Kickoff tem de a criar (o INSERT que o UNIQUE deal_id, funnel_id faria rebentar numa corrida)
    $tp->prepare('DELETE FROM deal_lines WHERE id = ?')->execute([$dr['lines']['vfx']]);
    $args = ['--db=' . $db, '--uid=1', '--do=deal_move', '--deal=' . $dr['id'], '--stage=' . $K, '--force=teste'];
    $r = p2v_race([['pipe2_race.php', $args], ['pipe2_race.php', $args]]);
    $why = ' (ronda ' . $round . ': ' . trim(($r[0]['err'] ?? '') . ' ' . ($r[1]['err'] ?? '') . ' ' . ($r[0]['json']['error'] ?? '') . ' ' . ($r[1]['json']['error'] ?? '')) . ')';
    t_eq([$r[0]['code'], $r[1]['code'], $r[0]['json']['ok'] ?? null, $r[1]['json']['ok'] ?? null], [0, 0, true, true], "$c ronda $round: os dois deal_move saem bem" . $why);
    t_true(!preg_match('/Warning|Notice|Deprecated|Duplicate entry/', $r[0]['err'] . $r[1]['err'] . ($r[0]['json']['error'] ?? '') . ($r[1]['json']['error'] ?? '')),
           "$c ronda $round: sem avisos de PHP nem «Duplicate entry»" . $why);
    $lines = p2v_rows($tp, 'SELECT id, funnel_id, is_main, status FROM deal_lines WHERE deal_id = ? ORDER BY id', [$dr['id']]);
    t_eq([count($lines), array_sum(array_map(fn($l) => (int)$l['is_main'], $lines)), array_values(array_unique(array_column($lines, 'status')))], [3, 1, ['queued']],
         "$c ronda $round: exatamente 3 frentes (1 principal), todas Por começar");
    t_eq(p2v_rows($tp, "SELECT line_id, COUNT(*) AS n FROM deal_line_steps WHERE deal_id = ? AND cycle_ym = '' GROUP BY line_id ORDER BY line_id", [$dr['id']]),
         array_map(fn($l) => ['line_id' => (int)$l['id'], 'n' => 6], $lines), "$c ronda $round: 6 linhas de passos por frente");
    t_eq(p2v_rows($tp, 'SELECT line_id, stage_id, cycle_ym, COUNT(*) AS n FROM deal_line_steps WHERE deal_id = ? GROUP BY line_id, stage_id, cycle_ym HAVING COUNT(*) > 1', [$dr['id']]), [],
         "$c ronda $round: sem passos repetidos por (frente, sub-etapa, ciclo)");
    t_eq(p2e_n($tp, 'SELECT COUNT(*) FROM deal_line_deps WHERE deal_id = ?', [$dr['id']]), 2, "$c ronda $round: as 2 ligações do modelo");
    t_eq(p2e_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$dr['id'], $rKick]), 1, "$c ronda $round: a tarefa da regra do Kickoff uma vez");
    t_eq(p2e_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'p2_category'", [$dr['id']]), 1, "$c ronda $round: a categoria uma vez");
}

// ── ::kickoff_reread_after_lock (Review Focus 7) ────────────
$c = '::kickoff_reread_after_lock';
$dd = p2v_deal($tp, $w, ['vfx', '3d', 'ed'], ['at' => 'kickoff', 'status' => 'planned']);
$velho = deal_get($tp, $dd['id']);
$r1 = pipe2_kickoff($tp, $velho, 1);
t_eq([count($r1['queued'] ?? []), $r1['created'] ?? null, count($r1['deps'] ?? []), $r1['category'] ?? null], [3, [], 2, 'postprod'], "$c a 1.ª chamada: 3 Por começar, 2 ligações, a categoria");
$cnt = p2e_counts($tp, $dd['id']);
t_eq($cnt, [3, 18, 2], "$c 3 frentes, 18 linhas de passos, 2 ligações");
[$cls, $msg] = p2e_err(fn() => $GLOBALS['p2e_r2'] = pipe2_kickoff($tp, $velho, 1));
t_eq($cls, '', "$c a 2.ª com o mesmo negócio velho não lança" . ($msg !== '' ? ': ' . $msg : ''));
$r2 = $GLOBALS['p2e_r2'] ?? [];
t_eq([$r2['created'] ?? null, $r2['queued'] ?? null, $r2['deps'] ?? null], [[], [], []], "$c e devolve created, queued e deps vazios");
t_eq(p2e_counts($tp, $dd['id']), $cnt, "$c as contagens não mudam");
$de = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'kickoff', 'status' => 'planned']);
$velhoE = deal_get($tp, $de['id']);
$tp->prepare('UPDATE deals SET stage_id = ? WHERE id = ?')->execute([$S['exec'], $de['id']]);
t_eq(pipe2_kickoff($tp, $velhoE, 1), ['created' => [], 'queued' => [], 'deps' => [], 'category' => ''], "$c com o negócio já em Em execução e um velho do Kickoff: não faz nada");
t_eq([p2e_counts($tp, $de['id']), array_map(fn($l) => p2e_line($tp, $l)['status'], array_values($de['lines'])), p2e_deal($tp, $de['id'])['category_at']],
     [[2, 0, 0], ['planned', 'planned'], null], "$c as frentes ficam planned, sem passos, ligações nem categoria");

// ── ::kickoff_gate_starts_fronts (D6) ───────────────────────
$c = '::kickoff_gate_starts_fronts';
pipe2_request_reset();
p2v_as(99);
$dg = p2v_deal($tp, $w, ['vfx', '3d', 'ed'], ['at' => 'kickoff']);
$tg = p2v_task($tp, $dg['id'], ['title' => 'obrigatória do Kickoff', 'stage_id' => $K, 'is_gate' => 1]);
task_done($tp, $tg);
$first = [$dg['lines']['vfx'] => $S['fronts'][0], $dg['lines']['3d'] => $S3['fronts'][0], $dg['lines']['ed'] => $SE['fronts'][0]];
t_eq(array_map(fn($l) => [p2e_line($tp, $l)['status'] ?? '', (int)(p2e_line($tp, $l)['stage_id'] ?? 0)], array_keys($first)),
     array_map(fn($s) => ['running', $s], array_values($first)), "$c fechar a obrigatória do Kickoff: as 3 frentes entram na 1.ª sub-etapa");
t_eq((int)p2e_deal($tp, $dg['id'])['stage_id'], $S['exec'], "$c e o negócio passa a Em execução");
$h = p2e_stage_rows($tp, $dg['id']);
t_eq(array_map(fn($r) => [(int)$r['from_stage_id'], (int)$r['to_stage_id'], $r['user_id'], $r['note']], $h), [[$K, $S['exec'], null, 'Uma frente começou']],
     "$c um 'stage' do «Sistema» (user_id NULL, mesmo com sessão) com a nota «Uma frente começou»");
$sysDeals[] = $dg['id'];
// com uma ligação manual para a 1.ª sub-etapa de Ed, Ed fica À espera sem sub-etapa
pipe2_request_reset();
$dw = p2v_deal($tp, $w, ['vfx', '3d', 'ed'], ['at' => 'kickoff']);
pipe2_dep_add($tp, deal_get($tp, $dw['id']), $dw['lines']['vfx'], $S['fronts'][1], $dw['lines']['ed'], $SE['fronts'][0], 1);
$tw = p2v_task($tp, $dw['id'], ['title' => 'obrigatória do Kickoff', 'stage_id' => $K, 'is_gate' => 1]);
task_done($tp, $tw);
$Le = p2e_line($tp, $dw['lines']['ed']);
t_eq([$Le['status'] ?? '', p2e_v($Le, 'stage_id')], ['depwait', null], "$c com uma ligação para a 1.ª sub-etapa de Ed: Ed À espera, sem sub-etapa");
t_eq([p2e_line($tp, $dw['lines']['vfx'])['status'] ?? '', (int)p2e_deal($tp, $dw['id'])['stage_id']], ['running', $S['exec']], "$c as outras começam e o negócio passa a Em execução");
// sem obrigatórias no Kickoff, fechar uma tarefa normal não começa nada; começar à mão começa
pipe2_request_reset();
$dz = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'kickoff']);
$tz = p2v_task($tp, $dz['id'], ['title' => 'normal do Kickoff', 'stage_id' => $K, 'is_gate' => 0]);
task_done($tp, $tz);
t_eq([p2e_line($tp, $dz['lines']['vfx'])['status'] ?? '', p2e_line($tp, $dz['lines']['3d'])['status'] ?? '', (int)p2e_deal($tp, $dz['id'])['stage_id']], ['queued', 'queued', $K],
     "$c sem obrigatórias no Kickoff, fechar uma tarefa normal não começa nada");
t_eq(pipe2_deal_reevaluate($tp, $dz['id']), 'idle', "$c e a reavaliação não faz nada");
pipe2_request_reset();
t_eq(pipe2_front_start($tp, deal_get($tp, $dz['id']), pipe2_front_get($tp, $dz['lines']['vfx']), 99), 'entered', "$c pipe2_front_start à mão começa a frente");
t_eq([p2e_line($tp, $dz['lines']['3d'])['status'] ?? '', (int)p2e_deal($tp, $dz['id'])['stage_id']], ['queued', $S['exec']],
     "$c a outra fica Por começar e o negócio passa a Em execução (a primeira frente entrou numa sub-etapa)");

// ── ::multi_front_deal_moves (E1) ───────────────────────────
$c = '::multi_front_deal_moves';
pipe2_request_reset();
$dmf = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'status' => 'ready']);
t_eq(pipe2_deal_reevaluate($tp, $dmf['id']), 'to_delivery', "$c v2 com 2 frentes Prontas: Em execução → Entrega pelo sistema");
$h = p2e_stage_rows($tp, $dmf['id']);
t_eq(array_map(fn($r) => [(int)$r['from_stage_id'], (int)$r['to_stage_id'], $r['user_id'], $r['note']], $h), [[$S['exec'], $S['delivery'], null, 'Todas as frentes prontas']],
     "$c um 'stage' do sistema com a nota «Todas as frentes prontas» (sem o bloqueio da v1)");
$mv = pipe2_moves();
t_eq([count($mv), $mv[0]['kind'] ?? '', (int)($mv[0]['deal'] ?? 0), $mv[0]['to'][0] ?? null, $mv[0]['undoable'] ?? null], [1, 'deal', $dmf['id'], $S['delivery'], true],
     "$c o movimento fica no registo do pedido (desfazível)");
$sysDeals[] = $dmf['id'];

// ── ::all_ready_rules (nota 6, T1) ──────────────────────────
$c = '::all_ready_rules';
pipe2_request_reset();
$da = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'status' => ['vfx' => 'ready', '3d' => 'running'], 'front_at' => ['3d' => $S3['revisions']]]);
$ta = p2v_task($tp, $da['id'], ['title' => 'última do 3D', 'line_id' => $da['lines']['3d'], 'stage_id' => $S3['revisions'], 'is_gate' => 1]);
task_done($tp, $ta);
t_eq([p2e_line($tp, $da['lines']['3d'])['status'] ?? '', (int)(p2e_line($tp, $da['lines']['3d'])['stage_id'] ?? 0)], ['ready', $S3['ready']], "$c a última frente fica Pronta");
t_eq((int)p2e_deal($tp, $da['id'])['stage_id'], $S['delivery'], "$c o negócio passa à Entrega");
t_eq(p2v_rows($tp, 'SELECT stage_id, line_id FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$da['id'], $rAll]), [['stage_id' => $S['exec'], 'line_id' => null]],
     "$c a regra all_ready da Em execução criada uma vez (tarefa do negócio)");
t_eq(array_map(fn($r) => p2e_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$da['id'], $r]), $closeV), [1, 1, 1, 1, 1],
     "$c as 4 regras de fecho e «Validar pagamento final» criadas uma vez");
t_eq(p2e_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND stage_id = ? AND line_id IS NULL', [$da['id'], $S['delivery']]), 5, "$c 5 tarefas na Entrega (as do 3D deduplicadas)");
pipe2_request_reset();
pipe2_deal_reevaluate($tp, $da['id'], 'done');
t_eq([p2e_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$da['id'], $rAll]), count(p2e_stage_rows($tp, $da['id']))], [1, 1],
     "$c outra reavaliação não cria mais nem move outra vez");
$sysDeals[] = $da['id'];

// ── ::blocked_gate_message (E4) ─────────────────────────────
$c = '::blocked_gate_message';
pipe2_request_reset();
$db2 = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'front_at' => ['vfx' => $S['revisions'], '3d' => $S3['name']['Animação']]]);
$tb = p2v_task($tp, $db2['id'], ['title' => 'última da VFX', 'line_id' => $db2['lines']['vfx'], 'stage_id' => $S['revisions'], 'is_gate' => 1]);
task_done($tp, $tb);
t_eq(p2e_line($tp, $db2['lines']['vfx'])['status'] ?? '', 'ready', "$c a VFX fica Pronta");
t_true(in_array('Pronto para a Entrega — falta: 3D (Animação)', pipe2_note(), true), "$c a nota «Pronto para a Entrega — falta: 3D (Animação)» — obtido " . var_export(pipe2_note(), true));
t_eq((int)p2e_deal($tp, $db2['id'])['stage_id'], $S['exec'], "$c o negócio fica em Em execução");
t_eq(p2e_n($tp, "SELECT COUNT(*) FROM tasks WHERE id = ? AND status = 'done'", [$tb]), 1, "$c e a tarefa continua feita");

// ── ::done_gate (E2) ────────────────────────────────────────
$c = '::done_gate';
pipe2_request_reset();
$dq = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'delivery', 'status' => 'ready']);
p2e_deal_gate($tp, $dq['id'], $S['delivery'], 'P2T · fecho A');
p2e_deal_gate($tp, $dq['id'], $S['delivery'], 'P2T · fecho B');
p2v_as(99);
[$cls, $msg, $ex] = p2e_err(fn() => deal_move($tp, deal_get($tp, $dq['id']), $S['done']));
t_eq([$cls, $ex instanceof GateBlocked ? $ex->missing : null], ['GateBlocked', ['P2T · fecho A', 'P2T · fecho B']], "$c humano para Concluído com obrigatórias por fazer: GateBlocked com os títulos");
t_eq(pipe2_close_missing($tp, deal_get($tp, $dq['id'])), ['P2T · fecho A', 'P2T · fecho B'], "$c pipe2_close_missing lista-as");
pipe2_request_reset();
$dav = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'status' => ['vfx' => 'ongoing', '3d' => 'ready'], 'front_at' => ['vfx' => $S['ready']], 'billing' => ['vfx' => [500, 12]]]);
$end = (string)p2e_line($tp, $dav['lines']['vfx'])['billing_end'];
t_eq(pipe2_fronts_missing($tp, deal_get($tp, $dav['id']), $today), ['VFX (avença até ' . date('d/m/Y', strtotime($end)) . ')'], "$c uma avença por terminar: «VFX (avença até …)»");
t_eq(pipe2_deal_reevaluate($tp, $dav['id']), 'blocked', "$c e mantém o negócio em Em execução");
t_eq((int)p2e_deal($tp, $dav['id'])['stage_id'], $S['exec'], "$c (o negócio continua em Em execução)");
$dfm = p2v_deal($tp, $w, ['vfx', '3d', 'ed'], ['at' => 'exec', 'status' => ['vfx' => 'queued', '3d' => 'depwait', 'ed' => 'running'], 'front_at' => ['3d' => $S3['fronts'][1], 'ed' => $SE['fronts'][2]]]);
pipe2_dep_add($tp, deal_get($tp, $dfm['id']), $dfm['lines']['ed'], $SE['fronts'][2], $dfm['lines']['3d'], $S3['fronts'][2], 1);
t_eq(pipe2_fronts_missing($tp, deal_get($tp, $dfm['id']), $today), ['VFX (por começar)', '3D (à espera de Edição)', 'Edição (Picture lock)'], "$c por começar, à espera e na sub-etapa");

// ── ::done_needs_mandatory (D16) ────────────────────────────
$c = '::done_needs_mandatory';
pipe2_request_reset();
$dn = p2v_deal($tp, $wn, ['vfx', '3d'], ['at' => 'delivery', 'status' => 'ready']);
t_eq(pipe2_close_missing($tp, deal_get($tp, $dn['id'])), [], "$c sem regras na Entrega e as frentes Prontas: nada por cumprir");
t_eq(pipe2_close_auto_missing($tp, deal_get($tp, $dn['id'])), ['Aprovação final', 'Pagamento final'], "$c mas faltam as duas obrigatórias do fecho automático");
t_eq(pipe2_deal_reevaluate($tp, $dn['id'], 'done'), 'no_mandatory', "$c pipe2_deal_reevaluate → no_mandatory");
$Dn = p2e_deal($tp, $dn['id']);
t_eq([(int)$Dn['stage_id'], $Dn['phase'], $Dn['delivered_at'], count(p2e_stage_rows($tp, $dn['id']))], [$N['delivery'], 'production', null, 0], "$c o negócio fica na Entrega, sem movimento nem delivered_at");
t_true(in_array('Pronto para concluir: conclui-se à mão (a Entrega não tem como obrigatória: Aprovação final, Pagamento final).', pipe2_note(), true),
       "$c a nota «Pronto para concluir: conclui-se à mão (…: Aprovação final, Pagamento final).» — obtido " . var_export(pipe2_note(), true));
pipe2_request_reset();
t_eq([pipe2_deal_reevaluate($tp, $dn['id'], 'cron'), pipe2_note(), count(p2e_stage_rows($tp, $dn['id']))], ['no_mandatory', [], 0], "$c o relógio também não move e não deixa nota");
// só as 4 de PIPE_RULES_DELIVERY, todas feitas
pipe2_request_reset();
$d4 = p2v_deal($tp, $wn, ['vfx', '3d'], ['at' => 'delivery', 'status' => 'ready']);
foreach (P2E_CLOSE_TITLES as $t) p2e_deal_gate($tp, $d4['id'], $N['delivery'], $t, ['status' => 'done']);
p2e_deal_gate($tp, $d4['id'], $N['delivery'], 'Emitir fatura final · X', ['status' => 'done', 'doc_kind' => 'invoice']);
t_eq(pipe2_deal_reevaluate($tp, $d4['id'], 'done'), 'no_mandatory', "$c com só as 4 regras da Entrega (feitas): no_mandatory");
t_true(in_array('Pronto para concluir: conclui-se à mão (a Entrega não tem como obrigatória: Pagamento final).', pipe2_note(), true), "$c e a nota só com «Pagamento final»");
// com o pagamento mas sem a aprovação
pipe2_request_reset();
$dp = p2v_deal($tp, $wn, ['vfx', '3d'], ['at' => 'delivery', 'status' => 'ready']);
p2e_deal_gate($tp, $dp['id'], $N['delivery'], 'Validar pagamento final · X', ['status' => 'done', 'doc_kind' => 'payment']);
p2e_deal_gate($tp, $dp['id'], $N['delivery'], P2E_CLOSE_TITLES[1], ['status' => 'done']);
t_eq(pipe2_deal_reevaluate($tp, $dp['id'], 'done'), 'no_mandatory', "$c com o pagamento e sem a aprovação: no_mandatory");
t_true(in_array('Pronto para concluir: conclui-se à mão (a Entrega não tem como obrigatória: Aprovação final).', pipe2_note(), true), "$c e a nota só com «Aprovação final»");
// a aprovação conta também numa tarefa de frente que conta para o gate da Entrega (gate_stage_id)
$tf = p2v_task($tp, $dp['id'], ['line_id' => $dp['lines']['3d'], 'stage_id' => $N3['fronts'][4], 'is_gate' => 1, 'status' => 'done', 'gate_stage_id' => $N['delivery']]);
$tp->prepare('UPDATE tasks SET title = ? WHERE id = ?')->execute(['  Aprovação FINAL do cliente  por escrito', $tf]);
t_eq(pipe2_close_auto_missing($tp, deal_get($tp, $dp['id'])), [], "$c a aprovação de uma frente com gate_stage_id = Entrega conta (título normalizado)");
// um humano conclui (o gate está cumprido)
p2v_as(99);
[$cls, $msg] = p2e_err(fn() => deal_move($tp, deal_get($tp, $dn['id']), $N['done']));
t_eq($cls, '', "$c deal_move humano para Concluído passa (gate cumprido)" . ($msg !== '' ? ': ' . $msg : ''));
$Dn = p2e_deal($tp, $dn['id']);
t_eq([$Dn['phase'], (int)$Dn['stage_id']], ['done', $N['done']], "$c e o negócio fica Concluído");
t_eq(array_map(fn($r) => [(int)$r['to_stage_id'], (int)$r['user_id']], p2e_stage_rows($tp, $dn['id'])), [[$N['done'], 99]], "$c o movimento humano grava a pessoa");

// ── ::auto_done_delivered_at (E6, D16) ──────────────────────
$c = '::auto_done_delivered_at';
pipe2_request_reset();
p2v_as(99);
$dd6 = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'status' => 'ready']);
t_eq(pipe2_deal_reevaluate($tp, $dd6['id']), 'to_delivery', "$c Em execução → Entrega");
$code = (string)p2e_deal($tp, $dd6['id'])['code'];
$tApr = p2e_task_like($tp, $dd6['id'], 'Aprovação final');
$tEnv = p2e_task_like($tp, $dd6['id'], 'Enviar entregáveis finais');
$tDir = p2e_task_like($tp, $dd6['id'], 'Documento de direitos');
t_true($tApr > 0 && $tEnv > 0 && $tDir > 0 && p2e_task_like($tp, $dd6['id'], 'Emitir fatura final') > 0 && p2e_task_like($tp, $dd6['id'], 'Validar pagamento final') > 0,
       "$c as 5 de fecho existem");
foreach ([$tApr, $tEnv, $tDir] as $t) { pipe2_request_reset(); task_done($tp, $t); }
$tp->prepare('UPDATE tasks SET done_at = ? WHERE id = ?')->execute([$today . ' 10:00:00', $tEnv]);
pipe2_request_reset();
flow_complete_doc_tasks($tp, $dd6['id'], 'invoice');
t_eq((int)p2e_deal($tp, $dd6['id'])['stage_id'], $S['delivery'], "$c com o pagamento por validar, fica na Entrega");
pipe2_request_reset();
flow_complete_doc_tasks($tp, $dd6['id'], 'payment');
$D6 = p2e_deal($tp, $dd6['id']);
t_eq([$D6['phase'], (int)$D6['stage_id']], ['done', $S['done']], "$c a última («Validar pagamento final») leva ao Concluído automático");
t_eq($D6['delivered_at'], $today . ' 10:00:00', "$c delivered_at = done_at de «Enviar entregáveis finais…»");
t_eq([$D6['portfolio_at'], $D6['testimonial_at']], [null, null], "$c com portfolio_at e testimonial_at vazios (a checklist não bloqueia)");
$h = p2e_stage_rows($tp, $dd6['id']);
$last = end($h) ?: [];
t_eq([(int)($last['to_stage_id'] ?? 0), p2e_v($last, 'user_id'), $last['note'] ?? ''], [$S['done'], null, 'Concluído automático: aprovação final e pagamento final validados'],
     "$c o 'stage' do sistema com a nota do Concluído automático");
t_eq(p2v_rows($tp, "SELECT user_id FROM deal_history WHERE deal_id = ? AND event = 'delivered'", [$dd6['id']]), [['user_id' => null]], "$c deal_history 'delivered' do «Sistema»");
t_eq(p2e_n($tp, "SELECT COUNT(*) FROM xsell_opps WHERE deal_id = ? AND source = 'delivery' AND funnel_id = ?", [$dd6['id'], $w['md']]), 1, "$c xsell_after_delivery correu (oportunidade de Motion Design)");
$mv = pipe2_moves();
$lastMv = end($mv) ?: [];
t_eq([$lastMv['kind'] ?? '', $lastMv['to'][0] ?? null, $lastMv['undoable'] ?? null], ['deal', $S['done'], false], "$c o Concluído automático fica no registo como não desfazível");
$sysDeals[] = $dd6['id'];

// ── ::back_from_delivery (D16) ──────────────────────────────
$c = '::back_from_delivery';
pipe2_request_reset();
p2v_as(99);
$dbk = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'delivery', 'status' => 'ready']);
t_eq(pipe2_front_undo($tp, deal_get($tp, $dbk['id']), pipe2_front_get($tp, $dbk['lines']['vfx']), 99), 'VFX: voltou a «Revisões».', "$c «Desfazer» da VFX na Entrega");
t_eq((int)p2e_deal($tp, $dbk['id'])['stage_id'], $S['exec'], "$c o negócio volta a Em execução");
t_eq(array_map(fn($r) => [(int)$r['from_stage_id'], (int)$r['to_stage_id'], $r['user_id'], $r['note']], p2e_stage_rows($tp, $dbk['id'])),
     [[$S['delivery'], $S['exec'], null, 'Voltou a Em execução: VFX saiu de Pronto']], "$c pelo sistema, com a nota «Voltou a Em execução: VFX saiu de Pronto»");
$sysDeals[] = $dbk['id'];

// ── ::concurrent_fronts_ready (Review Focus 6) ──────────────
$c = '::concurrent_fronts_ready';
for ($round = 1; $round <= 5; $round++) {
    $dc = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'front_at' => ['vfx' => $S['revisions'], '3d' => $S3['revisions']]]);
    $ga = p2v_task($tp, $dc['id'], ['title' => 'corrida VFX', 'line_id' => $dc['lines']['vfx'], 'stage_id' => $S['revisions'], 'is_gate' => 1]);
    $gb = p2v_task($tp, $dc['id'], ['title' => 'corrida 3D', 'line_id' => $dc['lines']['3d'], 'stage_id' => $S3['revisions'], 'is_gate' => 1]);
    $r = p2v_race([['pipe2_race.php', ['--db=' . $db, '--uid=99', '--task=' . $ga]], ['pipe2_race.php', ['--db=' . $db, '--uid=1', '--task=' . $gb]]]);
    $why = ' (ronda ' . $round . ': ' . trim(($r[0]['err'] ?? '') . ' ' . ($r[1]['err'] ?? '')) . ')';
    t_eq([$r[0]['code'], $r[1]['code'], $r[0]['json']['ok'] ?? null, $r[1]['json']['ok'] ?? null], [0, 0, true, true], "$c ronda $round: os dois saem bem" . $why);
    t_true(!preg_match('/Warning|Notice|Deprecated/', $r[0]['err'] . $r[1]['err']), "$c ronda $round: sem avisos de PHP" . $why);
    t_eq([p2e_line($tp, $dc['lines']['vfx'])['status'] ?? '', p2e_line($tp, $dc['lines']['3d'])['status'] ?? ''], ['ready', 'ready'], "$c ronda $round: as duas frentes Prontas");
    t_eq(p2e_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'stage' AND from_stage_id = ? AND to_stage_id = ?", [$dc['id'], $S['exec'], $S['delivery']]), 1,
         "$c ronda $round: exatamente um movimento Em execução → Entrega");
    t_eq(p2e_n($tp, 'SELECT COUNT(*) FROM stage_events WHERE deal_id = ? AND line_id IS NULL', [$dc['id']]), 1, "$c ronda $round: um stage_events do negócio");
    t_eq(count(p2e_stage_rows($tp, $dc['id'])), 1, "$c ronda $round: nenhum 'stage' repetido nem para Concluído");
    t_eq((int)p2e_deal($tp, $dc['id'])['stage_id'], $S['delivery'], "$c ronda $round: o negócio na Entrega");
    t_eq(p2e_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$dc['id'], $rAll]), 1, "$c ronda $round: a tarefa all_ready uma vez");
    t_eq(array_map(fn($x) => p2e_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$dc['id'], $x]), $closeV), [1, 1, 1, 1, 1],
         "$c ronda $round: as 5 de fecho uma vez cada");
}

// ── ::stale_move_refused (Review Focus 6) ───────────────────
$c = '::stale_move_refused';
pipe2_request_reset();
$ds = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'status' => 'ready']);
$lido = deal_get($tp, $ds['id']);
$tp->prepare('UPDATE deals SET stage_id = ? WHERE id = ?')->execute([$S['delivery'], $ds['id']]);
t_eq(pipe2_deal_system_move($tp, $lido, stage_get($tp, $S['delivery']), 'x', $S['exec']), 'stale', "$c o negócio já não está em Em execução: 'stale'");
t_eq(count(p2e_stage_rows($tp, $ds['id'])), 0, "$c sem 'stage' novo");
t_eq(pipe2_deal_reevaluate($tp, $ds['id']), 'no_mandatory', "$c a reavaliação relê a Entrega");
t_eq([p2e_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$ds['id'], $rAll]), count(p2e_stage_rows($tp, $ds['id'])),
      p2e_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ?', [$ds['id']])], [0, 0, 0], "$c e não cria regras all_ready nem outro movimento para a Entrega");

// ── ::deferred_in_transaction (Review Focus 1) ──────────────
$c = '::deferred_in_transaction';
pipe2_request_reset();
$dt = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'status' => ['vfx' => 'ready', '3d' => 'running'], 'front_at' => ['3d' => $S3['revisions']]]);
$tt = p2v_task($tp, $dt['id'], ['title' => 'na transação', 'line_id' => $dt['lines']['3d'], 'stage_id' => $S3['revisions'], 'is_gate' => 1]);
$tp->beginTransaction();
[$cls, $msg] = p2e_err(fn() => task_done($tp, $tt));
t_eq($cls, '', "$c task_done dentro de uma transação aberta não lança" . ($msg !== '' ? ': ' . $msg : ''));
t_eq(p2e_line($tp, $dt['lines']['3d'])['status'] ?? '', 'ready', "$c a frente fica Pronta (na transação de quem chamou)");
t_eq(pipe2_deal_reevaluate($tp, $dt['id']), 'deferred', "$c o negócio adia: 'deferred'");
t_eq([(int)p2e_deal($tp, $dt['id'])['stage_id'], $tp->inTransaction()], [$S['exec'], true], "$c fica em Em execução e a transação continua aberta");
$tp->commit();
t_eq(pipe2_deal_reevaluate($tp, $dt['id']), 'to_delivery', "$c depois do commit, passa à Entrega");
t_eq((int)p2e_deal($tp, $dt['id'])['stage_id'], $S['delivery'], "$c (na Entrega)");

// ── ::system_actor_deal (D9) ────────────────────────────────
$c = '::system_actor_deal';
pipe2_request_reset();
p2v_as(99);
$dsa = p2v_deal($tp, $w, ['vfx'], ['at' => 'kickoff']);
$tsa = p2v_task($tp, $dsa['id'], ['title' => 'obrigatória', 'stage_id' => $K, 'is_gate' => 1]);
task_done($tp, $tsa);
t_eq((int)p2e_deal($tp, $dsa['id'])['stage_id'], $S['exec'], "$c com sessão 99, o sistema move o negócio para Em execução");
t_eq(array_map(fn($r) => $r['user_id'], p2e_stage_rows($tp, $dsa['id'])), [null], "$c deal_history 'stage' com user_id NULL");
t_eq(p2v_rows($tp, 'SELECT step, user_id FROM stage_events WHERE deal_id = ? AND line_id IS NULL', [$dsa['id']]), [['step' => 'producao', 'user_id' => null]],
     "$c stage_events do negócio com user_id NULL");
t_eq(p2e_n($tp, 'SELECT COUNT(*) FROM stage_events WHERE deal_id = ? AND line_id IS NOT NULL AND user_id IS NOT NULL', [$dsa['id']]), 0, "$c e os da frente também sem utilizador");
$sysDeals[] = $dsa['id'];

// ── ::no_gate_alert (T5) ────────────────────────────────────
$c = '::no_gate_alert';
$dctl = p2v_deal($tp, $w, ['vfx'], ['at' => 'delivery', 'status' => 'ready']);
$tp->prepare("INSERT INTO deal_history (deal_id, event, from_funnel_id, from_stage_id, to_funnel_id, to_stage_id, note, user_id, created_at) VALUES (?, 'stage', ?, ?, ?, ?, '', 1, ?)")
   ->execute([$dctl['id'], $w['vfx'], $S['exec'], $w['vfx'], $S['delivery'], date('Y-m-d H:i:s')]);
$res = p2v_probe($db, 99, [['fn' => 'my_alerts', 'args' => []]]);
$titles = array_map(fn($a) => (string)($a['title'] ?? ''), (array)($res[0]['deal'] ?? []));
t_true(in_array('Gate de produção passado · ' . p2e_deal($tp, $dctl['id'])['code'], $titles, true), "$c (controlo) um 'stage' humano sem nota de uma etapa de produção avisa — obtido " . var_export($res, true));
$sysCodes = array_map(fn($id) => 'Gate de produção passado · ' . p2e_deal($tp, $id)['code'], $sysDeals);
t_eq(array_values(array_intersect($titles, $sysCodes)), [], "$c os movimentos do sistema (com nota) nunca avisam «Gate de produção passado»");
t_eq(count($sysDeals) >= 6, true, "$c (houve movimentos do sistema a verificar)");

// ── ganchos do crosselling (tarefa 13) ──────────────────────
require_once __DIR__ . '/../admin/inc/xsell.php';
$fname = fn(int $fid): string => (string)p2v_col($tp, 'SELECT name FROM funnels WHERE id = ?', [$fid]);
$sname = fn(int $sid): string => (string)p2v_col($tp, 'SELECT name FROM funnel_stages WHERE id = ?', [$sid]);

// ── ::front_move_bypasses_deal_move (E1) ────────────────────
$c = '::front_move_bypasses_deal_move';
pipe2_request_reset();
p2v_as(99);
$dfb = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec']);
[$cls, $msg] = p2e_err(fn() => $GLOBALS['p2e_lm'] = xsell_line_move($tp, deal_get($tp, $dfb['id']), xsell_line_get($tp, $dfb['lines']['vfx']), $S['fronts'][1]));
t_eq($cls, '', "$c xsell_line_move de uma frente v2 não lança" . ($msg !== '' ? ': ' . $msg : ''));
t_eq((string)($GLOBALS['p2e_lm'] ?? ''), 'VFX: passou a «' . $sname($S['fronts'][1]) . '».', "$c a mensagem da v2 (pipe2_front_move)");
$Lf = p2e_line($tp, $dfb['lines']['vfx']);
t_eq([(int)$Lf['stage_id'], $Lf['status']], [$S['fronts'][1], 'running'], "$c a frente fica na 2.ª sub-etapa, Em curso (estado v2, não 'active')");
t_eq((int)p2e_deal($tp, $dfb['id'])['stage_id'], $S['exec'], "$c deals.stage_id igual (Em execução)");
t_eq(p2v_rows($tp, "SELECT event, user_id, to_stage_id FROM deal_history WHERE deal_id = ? AND event IN ('p2_front', 'stage', 'gate_force') ORDER BY id", [$dfb['id']]),
     [['event' => 'p2_front', 'user_id' => 99, 'to_stage_id' => $S['fronts'][1]]], "$c um p2_front de quem moveu, nenhum 'stage' nem 'gate_force'");
t_eq(p2e_n($tp, "SELECT COUNT(*) FROM deal_line_steps WHERE line_id = ? AND stage_id = ? AND started_at IS NOT NULL", [$dfb['lines']['vfx'], $S['fronts'][1]]), 1,
     "$c a linha de passos da sub-etapa nova começou");

// ── ::close_missing_hook (E2) ───────────────────────────────
$c = '::close_missing_hook';
pipe2_request_reset();
$dcm = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'delivery', 'status' => ['vfx' => 'ready', '3d' => 'running'], 'front_at' => ['3d' => $S3['name']['Animação']]]);
p2e_deal_gate($tp, $dcm['id'], $S['delivery'], 'P2T · fecho A');
t_eq(pipe2_close_missing($tp, deal_get($tp, $dcm['id'])), ['P2T · fecho A', '3D (Animação)'], "$c (o que falta pela v2)");
t_eq(xsell_close_missing($tp, deal_get($tp, $dcm['id'])), ['P2T · fecho A', '3D (Animação)'], "$c xsell_close_missing de um v2 = pipe2_close_missing");
$dcv = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1f, 'stage_id' => $v1k, 'owner_id' => 99, 'sync_mode' => 'sync']);
pipe2_front($tp, $dcv, $v1f, ['is_main' => 1, 'status' => 'planned']);
pipe2_front($tp, $dcv, $v13d, ['is_main' => 0, 'status' => 'planned']);
t_eq(xsell_close_missing($tp, deal_get($tp, $dcv)), [$fname($v1f) . ': ainda não arrancou', $fname($v13d) . ': ainda não arrancou'], "$c de um v1: como antes");

// ── ::proposal_gate_v2 (M4, E7) ─────────────────────────────
$c = '::proposal_gate_v2';
$xsItem = 'Crosselling: frentes sincronizadas ou em timelines diferentes';
$budItem = 'Orçamento: cada linha com o serviço';
$dpg = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'proposal']);
$labels = array_column(proposal_requirements($tp, deal_get($tp, $dpg['id'])), 'label');
t_eq([in_array($xsItem, $labels, true), in_array($budItem, $labels, true)], [false, true], "$c v2 com 2 serviços: sem o item da sincronização, com o do orçamento — " . json_encode($labels, JSON_UNESCAPED_UNICODE));
$dpv = pipe2_deal($tp, ['phase' => 'pipeline', 'funnel_id' => $v1f, 'owner_id' => 99]);
pipe2_front($tp, $dpv, $v1f, ['is_main' => 1, 'status' => 'planned']);
pipe2_front($tp, $dpv, $v13d, ['is_main' => 0, 'status' => 'planned']);
$labels = array_column(proposal_requirements($tp, deal_get($tp, $dpv)), 'label');
t_eq([in_array($xsItem, $labels, true), in_array($budItem, $labels, true)], [true, false], "$c v1 com 2 serviços: com o item da sincronização (como antes)");

// ── ::xsell_tick_ignores_v2 (E7) ────────────────────────────
$c = '::xsell_tick_ignores_v2';
$dxt = p2v_deal($tp, $w, ['vfx', '3d', 'ed'], ['at' => 'exec', 'status' => ['vfx' => 'queued', '3d' => 'depwait', 'ed' => 'running']]);
foreach ($dxt['lines'] as $lid) p2v_front($tp, $lid, ['start_mode' => 'date', 'start_date' => date('Y-m-d', strtotime($today . ' -1 day'))]);
$sigX = p2v_sig($tp, ['deal_lines', 'deal_history', 'tasks', 'deal_line_steps'], 'deal_id = ' . (int)$dxt['id']);
xsell_tick($tp, $today);
t_eq(p2v_sig($tp, ['deal_lines', 'deal_history', 'tasks', 'deal_line_steps'], 'deal_id = ' . (int)$dxt['id']), $sigX,
     "$c xsell_tick (arranques numa data) não mexe nas frentes v2 (Por começar, À espera, Em curso)");

t_done();
