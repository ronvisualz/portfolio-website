<?php
/**
 * Pipeline v2, fase 1 — Review e rondas por frente (admin/inc/pipe2_cc.php e os ganchos em admin/inc/cc_review.php e
 * admin/deal_flow.php; tarefa 16: D28, C2, §34.11).
 *
 * Num negócio v2: uma peça de Review tem frente obrigatória quando o negócio tem duas ou mais frentes vivas, e só fecha
 * tarefas dessa frente; as rondas contam por frente e por ciclo, na sub-etapa Revisões do funil da frente
 * (deal_rounds.line_id/cycle_ym, cc_rv_rounds.line_id); o registo à mão recusa uma frente que já tem rondas do portal
 * no ciclo; acima das incluídas correm as regras com o gatilho «rondas ultrapassadas» (uma vez por ciclo) em vez da
 * tarefa «Pedido de alteração · …» da v1. Num negócio v1 tudo fica como hoje.
 *
 * ::deal_flow_round e ::deal_flow_v1 correm na bancada (HTTP em 127.0.0.1:8765, uid 1, com pipe2_lock) e só escrevem
 * em funis e negócios «P2T» deste teste; os outros casos correm numa base privada (p2v_db).
 *   php tests/pipe2_review_test.php
 * Casos: ::deal_flow_round ::deal_flow_v1 ::piece_needs_front ::rounds_per_front_cycle ::portal_not_manual
 *        ::over_included_uses_rounds_trigger ::approval_only_own_front ::v1_noop ::strict
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/cc_review.php';
require_once __DIR__ . '/../admin/inc/pipe2_fronts.php';   // pipe2_deal_is_v2, pipe2_front_get
if (is_file(__DIR__ . '/../admin/inc/pipe2_cc.php')) require_once __DIR__ . '/../admin/inc/pipe2_cc.php';

const P2R_PICK = 'Escolha a frente desta peça (o negócio tem várias frentes).';
const P2R_PICK_ROUND = 'Escolha a frente desta ronda (o negócio tem várias frentes).';
const P2R_PORTAL = 'As rondas desta frente contam-se pelo portal do cliente.';
const P2R_LOCK = 'P2T · Aprovação do lock';
const P2R_RULE = 'P2T · Orçamentar as rondas a mais';

/** [classe, mensagem] de $fn, ou ['', '']. */
function p2r_err(callable $fn): array {
    try { $fn(); } catch (Throwable $e) { return [get_class($e), $e->getMessage()]; }
    return ['', ''];
}

/** Chama uma função de admin/inc/pipe2_cc.php (que ainda pode não existir): sem ela, devolve «(falta <nome>)» e o caso FALHA. */
function p2r_call(string $fn, ...$args) {
    if (!function_exists($fn)) return "(falta $fn)";
    return $fn(...$args);
}

/** As mensagens (flash) da página. */
function p2r_flashes(string $html): array {
    preg_match_all('/<div class="flash (\w+)"[^>]*>(.*?)<\/div>/s', $html, $m, PREG_SET_ORDER);
    return array_map(fn($x) => [$x[1], html_entity_decode(strip_tags($x[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')], $m);
}

/** POST do=round para um negócio «P2T» deste teste (recusa outro); [resposta, html da página seguinte]. */
function p2r_round_post(PDO $pdo, int $deal, array $data): array {
    $code = (string)p2v_col($pdo, 'SELECT code FROM deals WHERE id = ?', [$deal]);
    if (!str_starts_with($code, PIPE2_T)) { t_true(false, "p2r_round_post recusou o negócio $deal (não é de teste)"); t_done(); }
    $post = ['do' => 'round', 'id' => $deal, 'back' => '/admin/pipeline.php'] + $data;
    $r = tests_http('POST', '/admin/deal_flow.php', $post, 1, true);
    if ($r['code'] >= 500 || $r['code'] === 0) $r = tests_http('POST', '/admin/deal_flow.php', $post, 1, true);   // uma falha transitória repete-se uma vez
    $loc = (string)($r['headers']['location'] ?? '');
    return [$r, $loc !== '' ? tests_http('GET', $loc, [], 1)['body'] : ''];
}

/** As rondas de um negócio (as colunas da v2 e as de sempre). */
function p2r_rounds(PDO $pdo, int $deal): array {
    return array_map(fn($r) => ['line_id' => $r['line_id'] === null ? null : (int)$r['line_id'], 'stage_id' => (int)$r['stage_id'],
                                'cycle_ym' => (string)$r['cycle_ym'], 'user_id' => $r['user_id'] === null ? null : (int)$r['user_id']],
                     p2v_rows($pdo, 'SELECT * FROM deal_rounds WHERE deal_id = ? ORDER BY id', [$deal]));
}

// ── ::deal_flow_round (bancada) ─────────────────────────────
$bench = $pdo;
pipe2_lock($bench);
pipe2_cleanup($bench);   // sobras «P2T» de uma corrida interrompida
$c = '::deal_flow_round';
$bfA = p2v_bench_funnel($bench, 'rondas A');
$bfB = p2v_bench_funnel($bench, 'rondas B');
$bA = p2v_stages($bench, $bfA);
$bB = p2v_stages($bench, $bfB);
$bd = pipe2_deal($bench, ['phase' => 'production', 'funnel_id' => $bfA, 'stage_id' => $bA['exec'], 'owner_id' => 1]);
$blA = pipe2_front($bench, $bd, $bfA, ['status' => 'running', 'stage_id' => $bA['fronts'][0], 'is_main' => 1]);
$blB = pipe2_front($bench, $bd, $bfB, ['status' => 'running', 'stage_id' => $bB['fronts'][0], 'is_main' => 0]);
t_true(pipe2_deal_is_v2($bench, deal_get($bench, $bd)), "$c o negócio de teste é v2");
[$r, $page] = p2r_round_post($bench, $bd, ['line' => $blA, 'note' => 'P2T · à mão']);
t_eq($r['code'], 302, "$c o POST redireciona");
t_eq(p2r_rounds($bench, $bd), [['line_id' => $blA, 'stage_id' => $bA['revisions'], 'cycle_ym' => '', 'user_id' => 1]],
     "$c a ronda fica na frente, na Revisões do funil dela, no ciclo da frente, com quem a registou");
t_true((bool)array_filter(p2r_flashes($page), fn($f) => $f[0] === 'ok' && str_contains($f[1], 'ronda 1 registada')), "$c mostra «… ronda 1 registada …»");
[$r, $page] = p2r_round_post($bench, $bd, ['note' => 'P2T · sem frente']);
t_true(in_array(['err', P2R_PICK_ROUND], p2r_flashes($page), true), "$c sem frente num negócio de várias frentes: «" . P2R_PICK_ROUND . "»");
t_eq(count(p2r_rounds($bench, $bd)), 1, "$c e não regista nada");
pipe2_insert($bench, 'deal_rounds', ['deal_id' => $bd, 'stage_id' => $bB['revisions'], 'line_id' => $blB, 'cycle_ym' => '', 'note' => 'P2T · do portal',
                                     'user_id' => 0, 'created_at' => date('Y-m-d H:i:s')]);
[$r, $page] = p2r_round_post($bench, $bd, ['line' => $blB, 'note' => 'P2T · à mão também']);
t_true(in_array(['err', P2R_PORTAL], p2r_flashes($page), true), "$c a frente com rondas do portal recusa o registo à mão: «" . P2R_PORTAL . "»");
t_eq(count(p2r_rounds($bench, $bd)), 2, "$c e não regista nada");

// ── ::deal_flow_v1 (bancada) ────────────────────────────────
$c = '::deal_flow_v1';
$vfb = pipe2_funnel($bench, 'rondas v1');
$vpb = pipe2_insert($bench, 'funnel_stages', ['funnel_id' => $vfb, 'name' => 'P2T · Produção', 'kind' => 'production', 'position' => 60, 'rounds_included' => 2]);
$vdb = pipe2_deal($bench, ['phase' => 'production', 'funnel_id' => $vfb, 'stage_id' => $vpb, 'owner_id' => 1]);
t_eq(pipe2_deal_is_v2($bench, deal_get($bench, $vdb)), false, "$c o negócio de teste é v1");
[$r, $page] = p2r_round_post($bench, $vdb, ['note' => 'P2T · v1']);
t_eq(p2r_rounds($bench, $vdb), [['line_id' => null, 'stage_id' => $vpb, 'cycle_ym' => '', 'user_id' => 1]], "$c a ronda na etapa do negócio, como hoje");
t_true(in_array(['ok', 'Ronda 1 registada (2 incluídas).'], p2r_flashes($page), true), "$c com a mensagem de sempre");
pipe2_cleanup($bench);

// ── a base privada: todos os funis, negócios e regras antes da primeira leitura das caches da v1 ──
[$db, $tp] = p2v_db();
$w = p2v_world($tp, ['vfx', '3d']);
$sv = p2v_stages($tp, $w['vfx']);   // Revisões vfx: 2 incluídas
$s3 = p2v_stages($tp, $w['3d']);    // Revisões 3D: 1 incluída
$rRounds = p2v_rule($tp, $sv['revisions'], ['title' => P2R_RULE, 'trig' => 'rounds', 'kind' => 'quote', 'assign_to' => 'commercial', 'is_gate' => 0]);
$d2 = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec']);
$d1 = p2v_deal($tp, $w, ['3d'], ['at' => 'exec']);
$dS = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec']);   // ::strict
p2v_front($tp, $d2['lines']['vfx'], ['cycle_ym' => '2026-11']);
// v1: um funil sem Em execução/Entrega, com uma etapa de produção de 0 rondas incluídas, e duas frentes v1
$vf = pipe2_funnel($tp, 'review v1');
$vf2 = pipe2_funnel($tp, 'review v1 b');
$vp = pipe2_insert($tp, 'funnel_stages', ['funnel_id' => $vf, 'name' => 'P2T · Produção', 'kind' => 'production', 'position' => 60, 'rounds_included' => 0]);
$vp2 = pipe2_insert($tp, 'funnel_stages', ['funnel_id' => $vf2, 'name' => 'P2T · Produção b', 'kind' => 'production', 'position' => 60]);
$dv = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $vf, 'stage_id' => $vp, 'owner_id' => 99]);
$lvA = pipe2_front($tp, $dv, $vf, ['status' => 'active', 'stage_id' => $vp]);
$lvB = pipe2_front($tp, $dv, $vf2, ['status' => 'active', 'stage_id' => $vp2]);
// as tarefas de aprovação (o mapa por defeito: «lock» → «aprovação do lock»)
$tA = [];
foreach (['vfx' => $d2['lines']['vfx'], '3d' => $d2['lines']['3d'], 'none' => null] as $k => $lid) {
    $tA['d2'][$k] = p2v_task($tp, $d2['id'], ['title' => P2R_LOCK, 'kind' => 'review', 'line_id' => $lid]);
}
$tA['d1']['line'] = p2v_task($tp, $d1['id'], ['title' => P2R_LOCK, 'kind' => 'review', 'line_id' => $d1['lines']['3d']]);
$tA['d1']['none'] = p2v_task($tp, $d1['id'], ['title' => P2R_LOCK, 'kind' => 'review']);
$tA['dv']['A'] = p2v_task($tp, $dv, ['title' => P2R_LOCK, 'kind' => 'review', 'line_id' => $lvA]);
$tA['dv']['B'] = p2v_task($tp, $dv, ['title' => P2R_LOCK, 'kind' => 'review', 'line_id' => $lvB]);
$tA['dv']['none'] = p2v_task($tp, $dv, ['title' => P2R_LOCK, 'kind' => 'review']);

/** Uma versão pronta da peça (passa a atual). */
function p2r_version(PDO $pdo, int $item): int {
    $num = (int)p2v_col($pdo, 'SELECT COUNT(*) + 1 FROM cc_rv_versions WHERE item_id = ?', [$item]);
    $id = pipe2_insert($pdo, 'cc_rv_versions', ['item_id' => $item, 'num' => $num, 'file_name' => 'p2t.mp4', 'mime' => 'video/mp4', 'proc_status' => 'ready',
                                               'created_at' => date('Y-m-d H:i:s')]);
    $pdo->prepare('UPDATE cc_rv_items SET current_version_id = ? WHERE id = ?')->execute([$id, $item]);
    return $id;
}

/** O envio consolidado do cliente no portal: um rascunho e «Enviar comentários». */
function p2r_submit(PDO $pdo, int $item): string {
    static $n = 0;
    $key = 'p2tkey' . (++$n);
    $i = cc_rv_item($pdo, $item);
    pipe2_insert($pdo, 'cc_comments', ['board_id' => 0, 'item_id' => $item, 'version_id' => (int)$i['current_version_id'], 'author_kind' => 'client',
                                       'client_name' => 'Cliente P2T', 'body' => 'P2T · comentário ' . $n, 'visibility' => 'client', 'draft' => 1,
                                       'client_key' => $key, 'created_at' => date('Y-m-d H:i:s')]);
    return cc_rv_client_submit($pdo, [], $i, ['key' => $key, 'name' => 'Cliente P2T', 'email' => 'p2t@example.com']);
}

function p2r_item(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM cc_rv_items WHERE id = ?', [$id])[0] ?? [];
}

function p2r_ids(array $tasks): array {
    $ids = array_map(fn($t) => (int)$t['id'], $tasks);
    sort($ids);
    return $ids;
}

// ── ::piece_needs_front ─────────────────────────────────────
$c = '::piece_needs_front';
$deal2 = deal_get($tp, $d2['id']);
$before = (int)p2v_col($tp, 'SELECT COUNT(*) FROM cc_rv_items WHERE deal_id = ?', [$d2['id']]);
t_eq(p2r_err(fn() => cc_rv_item_create($tp, $deal2, 'P2T · peça sem frente', 'lock', 'video', null, 1)), ['RuntimeException', P2R_PICK],
     "$c duas frentes e sem frente: «" . P2R_PICK . "»");
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM cc_rv_items WHERE deal_id = ?', [$d2['id']]), $before, "$c e não cria a peça");
$iV = cc_rv_item_create($tp, $deal2, 'P2T · peça VFX', 'lock', 'video', $d2['lines']['vfx'], 1);
$i3 = cc_rv_item_create($tp, $deal2, 'P2T · peça 3D', 'lock', 'video', $d2['lines']['3d'], 1);
t_eq([(int)p2r_item($tp, $iV)['stage_id'], (int)p2r_item($tp, $iV)['line_id']], [$sv['revisions'], $d2['lines']['vfx']], "$c com a frente VFX: a Revisões do funil VFX");
t_eq((int)p2r_item($tp, $i3)['stage_id'], $s3['revisions'], "$c com a frente 3D: a Revisões do funil 3D");
$i1 = cc_rv_item_create($tp, deal_get($tp, $d1['id']), 'P2T · peça de uma frente', 'lock', 'video', null, 1);
t_eq([(int)p2r_item($tp, $i1)['stage_id'], p2r_item($tp, $i1)['line_id']], [$s3['revisions'], null], "$c uma só frente e sem frente: a Revisões dela");
t_eq(p2r_call('pipe2_cc_revisions_stage', $tp, deal_get($tp, $d1['id']), null), $s3['revisions'], "$c pipe2_cc_revisions_stage de uma só frente, sem frente");
t_eq(p2r_call('pipe2_cc_revisions_stage', $tp, $deal2, null), null, "$c pipe2_cc_revisions_stage de duas frentes, sem frente: nenhuma");
t_eq(p2r_err(fn() => cc_rv_item_save($tp, cc_rv_item($tp, $iV), ['line' => '0'], 1)), ['RuntimeException', P2R_PICK], "$c tirar a frente de uma peça: recusa");
t_eq(p2r_err(fn() => cc_rv_item_save($tp, cc_rv_item($tp, $iV), ['line' => (string)$d1['lines']['3d']], 1)), ['RuntimeException', P2R_PICK],
     "$c uma frente de outro negócio conta como nenhuma: recusa");
t_eq((int)p2r_item($tp, $iV)['line_id'], $d2['lines']['vfx'], "$c e a peça fica como estava");
cc_rv_item_save($tp, cc_rv_item($tp, $iV), ['line' => (string)$d2['lines']['3d']], 1);
t_eq([(int)p2r_item($tp, $iV)['line_id'], (int)p2r_item($tp, $iV)['stage_id']], [$d2['lines']['3d'], $s3['revisions']], "$c mudar para a frente 3D: a Revisões do 3D");
cc_rv_item_save($tp, cc_rv_item($tp, $iV), ['line' => (string)$d2['lines']['vfx']], 1);
t_eq((int)p2r_item($tp, $iV)['stage_id'], $sv['revisions'], "$c e de volta à VFX");
t_eq(p2r_err(fn() => cc_rv_item_save($tp, cc_rv_item($tp, $iV), ['title' => 'P2T · peça VFX'], 1)), ['', ''], "$c gravar o nome não pede a frente outra vez");

// ── ::rounds_per_front_cycle ────────────────────────────────
$c = '::rounds_per_front_cycle';
p2r_version($tp, $iV);
cc_rv_send($tp, cc_rv_item($tp, $iV), 1);
$rv = p2v_rows($tp, 'SELECT * FROM cc_rv_rounds WHERE item_id = ? ORDER BY id', [$iV]);
t_eq(array_map(fn($r) => [(int)$r['stage_id'], $r['line_id'] === null ? null : (int)$r['line_id']], $rv), [[$sv['revisions'], $d2['lines']['vfx']]],
     "$c «Enviar ao cliente» abre a ronda na Revisões VFX, com a frente");
p2r_submit($tp, $iV);
t_eq(p2r_rounds($tp, $d2['id']), [['line_id' => $d2['lines']['vfx'], 'stage_id' => $sv['revisions'], 'cycle_ym' => '2026-11', 'user_id' => 0]],
     "$c o envio do cliente conta 1 ronda na frente VFX, no ciclo dela, na Revisões VFX, do portal (user_id 0)");
$rv = p2v_rows($tp, 'SELECT * FROM cc_rv_rounds WHERE item_id = ? ORDER BY id', [$iV])[0];
t_eq([(int)$rv['deal_round_id'], (int)$rv['over_included'], $rv['submitted_at'] !== null],
     [(int)p2v_col($tp, 'SELECT MAX(id) FROM deal_rounds WHERE deal_id = ?', [$d2['id']]), 0, true], "$c a ronda do Review fecha, ligada à ronda do negócio");
t_eq([p2r_call('pipe2_rounds_count', $tp, $d2['lines']['vfx'], '2026-11'), p2r_call('pipe2_rounds_count', $tp, $d2['lines']['vfx'], ''),
      p2r_call('pipe2_rounds_count', $tp, $d2['lines']['3d'], '')], [1, 0, 0], "$c pipe2_rounds_count por frente e ciclo");
$info = cc_rv_round_info($tp, cc_rv_item($tp, $iV));
t_eq([$info['done'], $info['included']], [1, 2], "$c «Ronda 1 de 2 incluídas» na peça");
p2v_front($tp, $d2['lines']['vfx'], ['cycle_ym' => '2026-12']);
t_eq(cc_rv_round_info($tp, cc_rv_item($tp, $iV))['done'], 0, "$c noutro ciclo da frente, as rondas recomeçam");
p2v_front($tp, $d2['lines']['vfx'], ['cycle_ym' => '2026-11']);

// ── ::portal_not_manual ─────────────────────────────────────
$c = '::portal_not_manual';
$fV = pipe2_front_get($tp, $d2['lines']['vfx']);
$f3 = pipe2_front_get($tp, $d2['lines']['3d']);
t_eq(p2r_err(fn() => p2r_call('pipe2_round_manual_check', $tp, $deal2, $fV)), ['RuntimeException', P2R_PORTAL], "$c a frente VFX tem rondas do portal no ciclo: «" . P2R_PORTAL . "»");
t_eq(p2r_err(fn() => p2r_call('pipe2_round_manual_check', $tp, $deal2, $f3)), ['', ''], "$c a frente 3D não: passa");
$res = p2r_call('pipe2_rounds_add', $tp, $deal2, $f3, 'P2T · à mão', 99, 'manual');
t_eq(array_slice(p2r_rounds($tp, $d2['id']), -1), [['line_id' => $d2['lines']['3d'], 'stage_id' => $s3['revisions'], 'cycle_ym' => '', 'user_id' => 99]],
     "$c a ronda à mão fica na frente 3D, com quem a registou");
t_eq([$res['round_id'] ?? null, $res['count'] ?? null, $res['over'] ?? null], [(int)p2v_col($tp, 'SELECT MAX(id) FROM deal_rounds'), 1, false], "$c devolve a ronda, a contagem e se passou");
t_eq($res['msg'] ?? '', '3D: ronda 1 registada (1 incluída). As rondas incluídas esgotaram: a próxima é um pedido de alteração orçamentado.', "$c a mensagem");
t_eq(p2r_err(fn() => p2r_call('pipe2_round_manual_check', $tp, $deal2, pipe2_front_get($tp, $d2['lines']['3d']))), ['', ''], "$c as rondas à mão não bloqueiam o registo à mão");
p2v_front($tp, $d2['lines']['vfx'], ['cycle_ym' => '2026-12']);
t_eq(p2r_err(fn() => p2r_call('pipe2_round_manual_check', $tp, $deal2, pipe2_front_get($tp, $d2['lines']['vfx']))), ['', ''], "$c noutro ciclo sem rondas do portal: passa");
p2v_front($tp, $d2['lines']['vfx'], ['cycle_ym' => '2026-11']);

// ── ::over_included_uses_rounds_trigger ─────────────────────
$c = '::over_included_uses_rounds_trigger';
$ruleTasks = fn(string $cycle) => (int)p2v_col($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ? AND line_id = ? AND cycle_ym = ?',
                                               [$d2['id'], $rRounds, $d2['lines']['vfx'], $cycle]);
$changeOrders = fn() => (int)p2v_col($tp, "SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND title LIKE 'Pedido de alteração · %'", [$d2['id']]);
p2r_submit($tp, $iV);   // 2.ª ronda: a última incluída
t_eq($ruleTasks('2026-11'), 0, "$c a 2.ª ronda (incluída) não corre a regra");
p2r_submit($tp, $iV);   // 3.ª: acima das incluídas
t_eq($ruleTasks('2026-11'), 1, "$c a 3.ª ronda cria a tarefa da regra «rondas ultrapassadas»");
t_eq(p2v_rows($tp, 'SELECT stage_id, line_id, cycle_ym FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$d2['id'], $rRounds]),
     [['stage_id' => $sv['revisions'], 'line_id' => $d2['lines']['vfx'], 'cycle_ym' => '2026-11']], "$c na frente VFX, na Revisões, no ciclo dela");
t_eq($changeOrders(), 0, "$c e nenhuma «Pedido de alteração · …» (cc_rv_change_order_task não corre)");
$h = p2v_rows($tp, "SELECT event, note FROM deal_history WHERE deal_id = ? AND event IN ('round','rounds') ORDER BY id DESC LIMIT 1", [$d2['id']])[0] ?? [];
t_eq($h['event'] ?? '', 'rounds', "$c o histórico regista «rondas» (o aviso ao comercial)");
t_true(str_contains((string)($h['note'] ?? ''), 'acima do incluído'), "$c com «acima do incluído» na nota");
t_eq((int)p2v_col($tp, 'SELECT over_included FROM cc_rv_rounds WHERE item_id = ? ORDER BY id DESC LIMIT 1', [$iV]), 1, "$c a ronda do Review fica acima do incluído");
p2r_submit($tp, $iV);   // 4.ª, no mesmo ciclo
t_eq([$ruleTasks('2026-11'), $changeOrders()], [1, 0], "$c a 4.ª no mesmo ciclo não cria outra");
p2v_front($tp, $d2['lines']['vfx'], ['cycle_ym' => '2026-12']);
p2r_submit($tp, $iV);
p2r_submit($tp, $iV);
t_eq($ruleTasks('2026-12'), 0, "$c ciclo novo: as duas primeiras ficam dentro das incluídas");
p2r_submit($tp, $iV);
t_eq([$ruleTasks('2026-12'), $ruleTasks('2026-11')], [1, 1], "$c a 3.ª do ciclo novo cria a do ciclo novo (uma por ciclo)");

// ── ::approval_only_own_front ───────────────────────────────
$c = '::approval_only_own_front';
t_eq(p2r_ids(cc_rv_approval_tasks($tp, cc_rv_item($tp, $iV))), [$tA['d2']['vfx']], "$c a peça VFX só fecha a tarefa da frente VFX (nem a da 3D nem a sem frente)");
t_eq(p2r_ids(cc_rv_approval_tasks($tp, cc_rv_item($tp, $i3))), [$tA['d2']['3d']], "$c a peça 3D só a da 3D");
t_eq(p2r_ids(cc_rv_approval_tasks($tp, cc_rv_item($tp, $i1))), p2r_ids([['id' => $tA['d1']['line']], ['id' => $tA['d1']['none']]]),
     "$c uma só frente: a da frente e a sem frente");
t_eq(p2r_call('pipe2_cc_approval_line_sql', $tp, cc_rv_item($tp, $iV)), ' AND line_id = ' . $d2['lines']['vfx'], "$c pipe2_cc_approval_line_sql com várias frentes");
t_eq(p2r_call('pipe2_cc_approval_line_sql', $tp, cc_rv_item($tp, $i1)), ' AND (line_id IS NULL OR line_id = ' . $d1['lines']['3d'] . ')', "$c com uma só");

// ── ::v1_noop ───────────────────────────────────────────────
$c = '::v1_noop';
$dealv = deal_get($tp, $dv);
t_eq(pipe2_deal_is_v2($tp, $dealv), false, "$c o negócio é v1");
t_eq([cc_rv_stage_for($tp, $dealv, null), cc_rv_stage_for($tp, $dealv, $lvB)], [$vp, $vp2], "$c cc_rv_stage_for: a etapa do negócio, ou a da frente v1, como hoje");
t_eq(p2r_call('pipe2_cc_revisions_stage', $tp, $dealv, null), null, "$c pipe2_cc_revisions_stage num v1: null");
t_eq(p2r_err(fn() => p2r_call('pipe2_cc_item_line_check', $tp, $dealv, null)), ['', ''], "$c pipe2_cc_item_line_check num v1 com duas frentes e sem frente: passa");
$iv = cc_rv_item_create($tp, $dealv, 'P2T · peça v1', 'lock', 'video', null, 1);
t_eq([(int)p2r_item($tp, $iv)['stage_id'], p2r_item($tp, $iv)['line_id']], [$vp, null], "$c a peça v1 sem frente nasce na etapa do negócio");
p2r_version($tp, $iv);
cc_rv_send($tp, cc_rv_item($tp, $iv), 1);
p2r_submit($tp, $iv);
t_eq(p2r_rounds($tp, $dv), [['line_id' => null, 'stage_id' => $vp, 'cycle_ym' => '', 'user_id' => 0]], "$c a ronda do portal na etapa do negócio, sem frente nem ciclo");
t_eq(p2v_col($tp, 'SELECT line_id FROM cc_rv_rounds WHERE item_id = ?', [$iv]), null, "$c a ronda do Review sem frente");
t_eq((int)p2v_col($tp, "SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND title = 'Pedido de alteração · P2T · peça v1 (ronda 1, acima do incluído)' AND kind = 'quote'", [$dv]), 1,
     "$c acima do incluído: cc_rv_change_order_task cria a «Pedido de alteração · …», como hoje");
t_eq((string)p2v_col($tp, "SELECT event FROM deal_history WHERE deal_id = ? AND event IN ('round','rounds') ORDER BY id DESC LIMIT 1", [$dv]), 'rounds', "$c com «rondas» no histórico");
$ivA = cc_rv_item_create($tp, $dealv, 'P2T · peça v1 A', 'lock', 'video', $lvA, 1);
t_eq(p2r_ids(cc_rv_approval_tasks($tp, cc_rv_item($tp, $ivA))), p2r_ids([['id' => $tA['dv']['A']], ['id' => $tA['dv']['none']]]),
     "$c cc_rv_approval_tasks de uma peça da frente A: a da frente A e a sem frente (line_id IS NULL), como hoje");
t_eq(p2r_ids(cc_rv_approval_tasks($tp, cc_rv_item($tp, $iv))), p2r_ids([['id' => $tA['dv']['A']], ['id' => $tA['dv']['B']], ['id' => $tA['dv']['none']]]),
     "$c e a da peça sem frente: todas, como hoje");
t_eq([p2r_call('pipe2_cc_approval_line_sql', $tp, cc_rv_item($tp, $ivA)), p2r_call('pipe2_cc_approval_line_sql', $tp, cc_rv_item($tp, $iv))],
     [' AND (line_id IS NULL OR line_id = ' . $lvA . ')', ''], "$c pipe2_cc_approval_line_sql num v1: o filtro de hoje");

// ── ::strict ────────────────────────────────────────────────
$c = '::strict';
pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES');
try {
    $dealS = deal_get($tp, $dS['id']);
    $iS = cc_rv_item_create($tp, $dealS, 'P2T · peça estrita', 'lock', 'video', $dS['lines']['vfx'], 1);
    p2r_version($tp, $iS);
    cc_rv_send($tp, cc_rv_item($tp, $iS), 1);
    for ($k = 0; $k < 3; $k++) p2r_submit($tp, $iS);
    p2r_call('pipe2_rounds_add', $tp, $dealS, pipe2_front_get($tp, $dS['lines']['3d']), str_repeat('P2T · nota longa ', 40), 1, 'manual');
    p2r_call('pipe2_round_manual_check', $tp, $dealS, pipe2_front_get($tp, $dS['lines']['3d']));
    cc_rv_approval_tasks($tp, cc_rv_item($tp, $iS));
    cc_rv_round_info($tp, cc_rv_item($tp, $iS));
    $err = '';
} catch (Throwable $e) {
    $err = get_class($e) . ': ' . $e->getMessage();
}
pipe2_sql_mode($tp, '');
t_eq($err, '', "$c criar, enviar, 3 envios do cliente, ronda à mão (nota longa), verificação e aprovação em ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES");
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM deal_rounds WHERE deal_id = ?', [$dS['id']]), 4, "$c as 4 rondas gravadas");

t_done();
