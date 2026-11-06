<?php
/**
 * Pipeline v2, fase 1 — dependências entre frentes (admin/inc/pipe2_deps.php; tarefa 5: D8; E7).
 *
 * Uma ligação é «a frente A conclui a sub-etapa X → a frente B, sub-etapa Y». Os modelos são por categoria (a gravada
 * no negócio; sem ela, a calculada), lidos por código do serviço + nome da sub-etapa (os ids dos modelos são das
 * sub-etapas reais) e resolvidos pelas frentes vivas do negócio. Nas combinações parciais, as ligações que passam por
 * um serviço ausente ligam-se diretamente ao seguinte (contração, sem poda, sem repetidos, pela ordem de D8). As
 * ligações à mão recusam frentes de outro negócio, a mesma frente, sub-etapas de outro funil e ciclos. Uma frente
 * «À espera» não está atrasada e não se replaneia.
 *
 * pipe2_deps_instantiate chama-se aqui em sequência: deal_line_deps não tem UNIQUE, por isso a idempotência em
 * concorrência é da pipe2_kickoff (tarefa 11), que a chama dentro do lock e da transação. Os casos do motor (tarefa
 * 10, pipe2_engine.php): ::waits_before_y (B fica À espera no fim da sub-etapa anterior a Y), ::tasks_born_on_release
 * (A conclui X: a ligação fica cumprida, B entra em Y, as regras de Y correm e o responsável de B é avisado uma vez) e
 * a parte do motor de ::no_replan_while_waiting.
 *
 * Corre numa base privada do dump da 42, migrada até à SCHEMA_VERSION (p2v_db); não escreve na bancada.
 *
 * Casos: ::models_for ::model_postprod_full ::partial_combination_links ::contraction_multi_hop
 *        ::instantiate_idempotent ::instantiate_stored_category ::removed_not_recreated ::manual_add_rules
 *        ::manual_add_met_when_done ::deps_into ::mark_met_releases_when_all_met ::waiting_not_late
 *        ::no_replan_while_waiting ::waits_before_y ::tasks_born_on_release ::v1_noop ::strict
 *
 * Uso: php tests/pipe2_deps_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/pipe2_deps.php';
require_once __DIR__ . '/../admin/inc/pipe2_engine.php';
require_once __DIR__ . '/../admin/inc/tasks.php';

/** A mensagem da exceção de $fn, ou ''. */
function p2d_err(callable $fn): string {
    try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
}

/** O negócio, como o recebem as funções. */
function p2d_deal(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM deals WHERE id = ?', [$id])[0];
}

/** As ligações de um negócio, para comparar: [frente de, sub-etapa de, frente para, sub-etapa para, source], por id. */
function p2d_links(PDO $pdo, int $dealId, bool $liveOnly = true): array {
    return array_map(fn($r) => [(int)$r['from_line_id'], (int)$r['from_stage_id'], (int)$r['to_line_id'], (int)$r['to_stage_id'], $r['source']],
        p2v_rows($pdo, 'SELECT * FROM deal_line_deps WHERE deal_id = ?' . ($liveOnly ? ' AND removed_at IS NULL' : '') . ' ORDER BY id', [$dealId]));
}

/** As linhas p2_dep do histórico de um negócio: [nota, user_id], por id. */
function p2d_hist(PDO $pdo, int $dealId): array {
    return array_map(fn($r) => [$r['note'], $r['user_id'] === null ? null : (int)$r['user_id']],
        p2v_rows($pdo, "SELECT note, user_id FROM deal_history WHERE deal_id = ? AND event = 'p2_dep' ORDER BY id", [$dealId]));
}

// ── a base privada, os funis e os negócios (tudo antes das leituras) ──
[$db, $tp] = p2v_db();
$w = p2v_world($tp);
$S = array_map(fn($fid) => p2v_stages($tp, $fid), $w);
$st = fn(string $code, string $name): int => (int)$S[$code]['name'][$name];   // a sub-etapa de teste pelo nome

$dFull   = p2v_deal($tp, $w, ['vfx', '3d', 'md', 'cmp', 'ed']);            // postprod, Kickoff, frentes queued
$dCgi    = p2v_deal($tp, $w, ['vfx', '3d']);                               // cgi
$d3dCmp  = p2v_deal($tp, $w, ['3d', 'cmp']);                               // cgi (sem VFX: contração)
$dStored = p2v_deal($tp, $w, ['vfx', '3d']);                               // categoria gravada postprod
$dNoCat  = p2v_deal($tp, $w, ['vfx', '3d']);                               // categoria gravada '' (congelada)
$dRemove = p2v_deal($tp, $w, ['vfx', '3d']);
$dMan    = p2v_deal($tp, $w, ['vfx', '3d']);                               // ligações à mão
$dModel  = p2v_deal($tp, $w, ['vfx', '3d']);                               // ciclo pelo modelo
$dOther  = p2v_deal($tp, $w, ['vfx', '3d']);                               // outro negócio
$dDone   = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'front_at' => ['vfx' => $S['vfx']['fronts'][3]]]);   // vfx em «Finais e QC»
$dMet    = p2v_deal($tp, $w, ['vfx', '3d', 'md', 'cmp', 'ed']);
$dWait   = p2v_deal($tp, $w, ['cmp', 'ed'], ['at' => 'exec', 'status' => ['cmp' => 'running', 'ed' => 'depwait'],
                                           'front_at' => ['ed' => $S['ed']['fronts'][1]]]);   // Ed à espera no fim de «Montagem offline»
$dStrict = p2v_deal($tp, $w, ['vfx', '3d', 'cmp']);
// o motor (tarefa 10): Composit em «Composição», Edição em «Montagem offline»; as regras de «Picture lock» da Edição
$dEng    = p2v_deal($tp, $w, ['cmp', 'ed'], ['at' => 'exec', 'front_at' => ['cmp' => $S['cmp']['fronts'][1], 'ed' => $S['ed']['fronts'][1]]]);
$rPlEnter = p2v_rule($tp, $S['ed']['name']['Picture lock'], ['title' => 'P2T · entrada em Picture lock', 'trig' => 'f_enter', 'is_gate' => 0]);
$rPlDep   = p2v_rule($tp, $S['ed']['name']['Picture lock'], ['title' => 'P2T · dependência de Picture lock', 'trig' => 'dep_met', 'is_gate' => 0]);
$tp->prepare("UPDATE deals SET category = 'postprod', category_at = NOW() WHERE id = ?")->execute([$dStored['id']]);
$tp->prepare("UPDATE deals SET category = '', category_at = NOW() WHERE id = ?")->execute([$dNoCat['id']]);

// um negócio v1 (VFX real, crosselling com o CGI real, frentes active)
$realVfx = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY id LIMIT 1");
$real3d = (int)p2v_col($tp, "SELECT id FROM funnels WHERE service_code = '3d' AND is_base = 1 ORDER BY id LIMIT 1");
$v1 = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $realVfx, 'owner_id' => 99, 'sync_mode' => 'sync']);
pipe2_front($tp, $v1, $realVfx, ['is_main' => 1, 'status' => 'active']);
pipe2_front($tp, $v1, $real3d, ['is_main' => 0, 'status' => 'active']);

const P2D_POSTPROD = [
    ['3d', 'Lighting e render', 'cmp', 'Preparação de plates'],
    ['vfx', 'Finais e QC', 'cmp', 'Integração e look'],
    ['cmp', 'Composição', 'ed', 'Picture lock'],
    ['md', 'Revisões', 'ed', 'Picture lock'],
];
const P2D_CGI = [
    ['3d', 'Modelação e lookdev', 'vfx', 'Look dev / shot de referência'],
    ['3d', 'Lighting e render', 'cmp', 'Preparação de plates'],
    ['vfx', 'Finais e QC', 'cmp', 'Integração e look'],
];

// ── ::models_for ────────────────────────────────────────────
t_eq(pipe2_models_for($tp, 'postprod'), P2D_POSTPROD, '::models_for postprod: as 4 ligações do modelo, por posição, com o código do serviço');
t_eq(pipe2_models_for($tp, 'cgi'), P2D_CGI, '::models_for cgi: as 3 ligações do modelo');
t_eq(pipe2_models_for($tp, ''), [], '::models_for sem categoria → []');
t_eq(pipe2_models_for($tp, 'p2tnada'), [], '::models_for categoria sem modelos → []');
$mid = (int)p2v_col($tp, "SELECT id FROM pipe2_dep_models WHERE category = 'cgi' ORDER BY position, id LIMIT 1");
$tp->prepare('UPDATE pipe2_dep_models SET is_active = 0 WHERE id = ?')->execute([$mid]);
t_eq(pipe2_models_for($tp, 'cgi'), array_slice(P2D_CGI, 1), '::models_for ignora um modelo desligado');
$tp->prepare('UPDATE pipe2_dep_models SET is_active = 1 WHERE id = ?')->execute([$mid]);

// ── ::model_postprod_full ───────────────────────────────────
t_eq(pipe2_model_links(P2D_POSTPROD, ['vfx', '3d', 'md', 'cmp', 'ed']), P2D_POSTPROD, '::model_postprod_full os 5 serviços → as 4 ligações do modelo, exatamente');
$full = $dFull['lines'];
t_eq(pipe2_deps_instantiate($tp, p2d_deal($tp, $dFull['id'])), 4, '::model_postprod_full cria as 4 ligações');
t_eq(p2d_links($tp, $dFull['id']), [
    [$full['3d'], $st('3d', 'Lighting e render'), $full['cmp'], $st('cmp', 'Preparação de plates'), 'model'],
    [$full['vfx'], $st('vfx', 'Finais e QC'), $full['cmp'], $st('cmp', 'Integração e look'), 'model'],
    [$full['cmp'], $st('cmp', 'Composição'), $full['ed'], $st('ed', 'Picture lock'), 'model'],
    [$full['md'], $st('md', 'Revisões'), $full['ed'], $st('ed', 'Picture lock'), 'model'],
], '::model_postprod_full com as frentes do negócio e as sub-etapas dos funis delas');
t_eq(count(p2d_hist($tp, $dFull['id'])), 1, '::model_postprod_full uma linha p2_dep no histórico');
t_eq(p2d_hist($tp, $dFull['id'])[0][1], null, '::model_postprod_full o histórico é do «Sistema» (user_id NULL)');

// ── ::partial_combination_links (D8; t_eq exato: a ordem faz parte do contrato) ──
t_eq(pipe2_model_links(P2D_POSTPROD, ['vfx', '3d', 'ed']),
     [['3d', 'Lighting e render', 'ed', 'Picture lock'], ['vfx', 'Finais e QC', 'ed', 'Picture lock']],
     '::partial_combination_links postprod VFX + 3D + Edição (o exemplo da especificação; o 3D primeiro)');
t_eq(pipe2_model_links(P2D_POSTPROD, ['3d', 'ed']), [['3d', 'Lighting e render', 'ed', 'Picture lock']], '::partial_combination_links postprod 3D + Edição');
t_eq(pipe2_model_links(P2D_CGI, ['vfx', '3d']), [['3d', 'Modelação e lookdev', 'vfx', 'Look dev / shot de referência']],
     '::partial_combination_links cgi VFX + 3D (as que entram em Composit não têm saída e caem)');
t_eq(pipe2_model_links(P2D_CGI, ['3d', 'cmp']),
     [['3d', 'Modelação e lookdev', 'cmp', 'Integração e look'], ['3d', 'Lighting e render', 'cmp', 'Preparação de plates']],
     '::partial_combination_links cgi 3D + Composit (a 1.ª contraída por VFX, a 2.ª direta; sem poda)');
t_eq(pipe2_model_links(P2D_CGI, ['cmp', '3d', '3d']), pipe2_model_links(P2D_CGI, ['3d', 'cmp']), '::partial_combination_links a ordem e os repetidos dos códigos não contam');
t_eq(pipe2_model_links([], ['vfx', '3d', 'ed']), [], '::partial_combination_links sem categoria (modelo vazio) → []');
t_eq(pipe2_model_links(P2D_POSTPROD, ['ed']), [], '::partial_combination_links um só serviço → []');

// ── ::contraction_multi_hop (puro, ligações sintéticas) ─────
$chain = [['vfx', 'A', '3d', 'B'], ['3d', 'C', 'md', 'D'], ['md', 'E', 'cmp', 'F']];
t_eq(pipe2_model_links($chain, ['vfx', 'cmp']), [['vfx', 'A', 'cmp', 'F']], '::contraction_multi_hop dois ausentes seguidos');
t_eq(pipe2_model_links($chain, ['vfx', 'md', 'cmp']), [['vfx', 'A', 'md', 'D'], ['md', 'E', 'cmp', 'F']], '::contraction_multi_hop um ausente no meio');
$rep = [['vfx', 'A', '3d', 'B'], ['vfx', 'Z', 'cmp', 'Y'], ['vfx', 'A', 'md', 'C'], ['3d', 'B', 'cmp', 'F'], ['md', 'C', 'cmp', 'F']];
t_eq(pipe2_model_links($rep, ['vfx', 'cmp']), [['vfx', 'A', 'cmp', 'F'], ['vfx', 'Z', 'cmp', 'Y']],
     '::contraction_multi_hop a mesma ligação por dois caminhos fica uma vez, na 1.ª posição');
$loop = [['vfx', 'A', '3d', 'B'], ['3d', 'C', 'md', 'D'], ['md', 'E', '3d', 'G'], ['md', 'H', 'cmp', 'F']];
$got = pipe2_model_links($loop, ['vfx', 'cmp']);
t_eq($got, [['vfx', 'A', 'cmp', 'F']], '::contraction_multi_hop um ciclo entre dois ausentes termina');
t_eq(array_values(array_filter(pipe2_model_links([['3d', 'C', 'md', 'D'], ['md', 'E', '3d', 'G']], ['3d']), fn($l) => $l[0] === $l[2])), [],
     '::contraction_multi_hop a contração não gera ligações de um serviço para si próprio');
t_eq(pipe2_model_links([['3d', 'C', 'md', 'D'], ['md', 'E', '3d', 'G']], ['3d']), [], '::contraction_multi_hop 3D → MD → 3D com MD ausente cai');

// ── ::instantiate_idempotent ────────────────────────────────
t_eq(pipe2_deps_instantiate($tp, p2d_deal($tp, $dCgi['id'])), 1, '::instantiate_idempotent cgi VFX + 3D cria 1 ligação');
t_eq(p2d_links($tp, $dCgi['id']), [[$dCgi['lines']['3d'], $st('3d', 'Modelação e lookdev'), $dCgi['lines']['vfx'], $st('vfx', 'Look dev / shot de referência'), 'model']],
     '::instantiate_idempotent com os ids das sub-etapas dos funis de teste');
t_eq(pipe2_deps_instantiate($tp, p2d_deal($tp, $dCgi['id'])), 0, '::instantiate_idempotent a 2.ª chamada cria 0');
t_eq(count(p2d_links($tp, $dCgi['id'], false)), 1, '::instantiate_idempotent continua 1 linha');
t_eq(count(p2d_hist($tp, $dCgi['id'])), 1, '::instantiate_idempotent a 2.ª chamada não regista nada');
t_eq(pipe2_deps_instantiate($tp, p2d_deal($tp, $d3dCmp['id'])), 2, '::instantiate_idempotent cgi 3D + Composit cria 2');
t_eq(p2d_links($tp, $d3dCmp['id']), [
    [$d3dCmp['lines']['3d'], $st('3d', 'Modelação e lookdev'), $d3dCmp['lines']['cmp'], $st('cmp', 'Integração e look'), 'model'],
    [$d3dCmp['lines']['3d'], $st('3d', 'Lighting e render'), $d3dCmp['lines']['cmp'], $st('cmp', 'Preparação de plates'), 'model'],
], '::instantiate_idempotent as 2 de ::partial_combination_links, pela ordem, source model');
$byRow = p2v_rows($tp, 'SELECT created_by, met_at, removed_at FROM deal_line_deps WHERE deal_id = ? ORDER BY id LIMIT 1', [$d3dCmp['id']])[0];
t_eq([$byRow['created_by'], $byRow['met_at'], $byRow['removed_at']], [null, null, null], '::instantiate_idempotent sem autor ($by null), por cumprir e viva');

// ── ::instantiate_stored_category ───────────────────────────
t_eq(pipe2_deps_instantiate($tp, p2d_deal($tp, $dStored['id'])), 0, '::instantiate_stored_category a categoria gravada (postprod) manda: VFX + 3D sem Edição nem Composit → 0');
t_eq(pipe2_deps_instantiate($tp, p2d_deal($tp, $dNoCat['id'])), 0, '::instantiate_stored_category categoria gravada vazia → sem modelo');
t_eq(p2d_hist($tp, $dNoCat['id']), [], '::instantiate_stored_category sem ligações, sem histórico');

// ── ::removed_not_recreated ─────────────────────────────────
pipe2_deps_instantiate($tp, p2d_deal($tp, $dRemove['id']));
$rid = (int)p2v_col($tp, 'SELECT id FROM deal_line_deps WHERE deal_id = ?', [$dRemove['id']]);
$h0 = count(p2d_hist($tp, $dRemove['id']));
pipe2_dep_remove($tp, p2d_deal($tp, $dRemove['id']), $rid, 99);
$rr = p2v_rows($tp, 'SELECT removed_at, removed_by FROM deal_line_deps WHERE id = ?', [$rid])[0];
t_true($rr['removed_at'] !== null && (int)$rr['removed_by'] === 99, '::removed_not_recreated removed_at e removed_by gravados');
$hist = p2d_hist($tp, $dRemove['id']);
t_eq(count($hist), $h0 + 1, '::removed_not_recreated a remoção fica no histórico');
t_true(str_contains((string)end($hist)[0], 'Ligação retirada') && end($hist)[1] === 99, '::removed_not_recreated nota «Ligação retirada» de quem retirou');
t_eq(pipe2_deps_instantiate($tp, p2d_deal($tp, $dRemove['id'])), 0, '::removed_not_recreated o modelo não recria a ligação retirada');
t_eq(count(p2d_hist($tp, $dRemove['id'])), $h0 + 1, '::removed_not_recreated e não regista nada');
t_eq(p2d_err(fn() => pipe2_dep_remove($tp, p2d_deal($tp, $dRemove['id']), $rid, 99)), 'Ligação não encontrada.', '::removed_not_recreated retirar outra vez → «Ligação não encontrada.»');
t_eq(p2d_err(fn() => pipe2_dep_remove($tp, p2d_deal($tp, $dOther['id']), (int)p2v_col($tp, 'SELECT id FROM deal_line_deps WHERE deal_id = ? LIMIT 1', [$dCgi['id']]), 99)),
     'Ligação não encontrada.', '::removed_not_recreated uma ligação de outro negócio → «Ligação não encontrada.»');

// ── ::manual_add_rules ──────────────────────────────────────
$mv = $dMan['lines']['vfx'];
$m3 = $dMan['lines']['3d'];
$fv = $S['vfx']['fronts'];
$f3 = $S['3d']['fronts'];
$deal = p2d_deal($tp, $dMan['id']);
$a1 = pipe2_dep_add($tp, $deal, $mv, $fv[2], $m3, $f3[1], 99);   // VFX conclui «Produção de shots» → 3D «Modelação e lookdev»
t_true($a1 > 0, '::manual_add_rules VFX → 3D aceite');
$r1 = p2v_rows($tp, 'SELECT * FROM deal_line_deps WHERE id = ?', [$a1])[0];
t_eq([$r1['source'], (int)$r1['created_by'], $r1['met_at']], ['manual', 99, null], '::manual_add_rules source manual, autor e por cumprir');
$hist = p2d_hist($tp, $dMan['id']);
t_eq(count($hist), 1, '::manual_add_rules uma linha p2_dep');
t_true(str_contains((string)$hist[0][0], 'Ligação acrescentada') && str_contains((string)$hist[0][0], 'Produção de shots')
       && str_contains((string)$hist[0][0], 'Modelação e lookdev') && $hist[0][1] === 99, '::manual_add_rules a nota diz a ligação e quem a fez');
t_eq(p2d_err(fn() => pipe2_dep_add($tp, $deal, $m3, $f3[3], $mv, $fv[0], 99)), 'Esta ligação criava um ciclo.',
     '::manual_add_rules 3D «Lighting e render» → VFX «Turnover» criava um ciclo');
$a2 = pipe2_dep_add($tp, $deal, $m3, $f3[0], $mv, $fv[3], 99);   // 3D conclui «Styleframes» → VFX «Finais e QC»: sem ciclo
t_true($a2 > 0, '::manual_add_rules 3D «Styleframes» → VFX «Finais e QC» não é ciclo');
$a3 = pipe2_dep_add($tp, $deal, $m3, $f3[2], $mv, $fv[3], 99);   // 3D conclui «Animação» → VFX «Finais e QC»: sem ciclo
t_true($a3 > 0, '::manual_add_rules 3D «Animação» → VFX «Finais e QC» não é ciclo');
t_eq(p2d_err(fn() => pipe2_dep_add($tp, $deal, $mv, $fv[3], $m3, $f3[0], 99)), 'Esta ligação criava um ciclo.',
     '::manual_add_rules VFX «Finais e QC» → 3D «Styleframes» criava um ciclo (pela ordem das sub-etapas)');
t_eq(p2d_err(fn() => pipe2_dep_add($tp, $deal, $mv, $fv[2], $m3, $f3[1], 99)), 'Esta ligação já existe.', '::manual_add_rules a mesma ligação outra vez');
t_eq(p2d_err(fn() => pipe2_dep_add($tp, $deal, $dOther['lines']['vfx'], $fv[2], $m3, $f3[1], 99)), 'Frente não encontrada.',
     '::manual_add_rules frente de outro negócio → «Frente não encontrada.»');
t_eq(p2d_err(fn() => pipe2_dep_add($tp, $deal, $mv, $fv[2], 999999, $f3[1], 99)), 'Frente não encontrada.', '::manual_add_rules frente que não existe');
t_eq(p2d_err(fn() => pipe2_dep_add($tp, $deal, $mv, $fv[2], $mv, $fv[4], 99)), 'Uma frente não pode esperar por ela própria.', '::manual_add_rules a mesma frente');
t_eq(p2d_err(fn() => pipe2_dep_add($tp, $deal, $mv, $f3[2], $m3, $f3[1], 99)), 'Sub-etapa não encontrada.',
     '::manual_add_rules uma sub-etapa de outro funil (a do 3D na frente VFX)');
t_eq(p2d_err(fn() => pipe2_dep_add($tp, $deal, $mv, $S['vfx']['exec'], $m3, $f3[1], 99)), 'Sub-etapa não encontrada.', '::manual_add_rules uma etapa do negócio não é sub-etapa');
t_eq(count(p2d_links($tp, $dMan['id'])), 3, '::manual_add_rules as recusadas não gravaram nada');
t_eq(count(p2d_hist($tp, $dMan['id'])), 3, '::manual_add_rules só as aceites no histórico');
// o ciclo também passa pelas ligações do modelo: 3D «Modelação e lookdev» → VFX «Look dev» (cgi)
pipe2_deps_instantiate($tp, p2d_deal($tp, $dModel['id']));
t_eq(p2d_err(fn() => pipe2_dep_add($tp, p2d_deal($tp, $dModel['id']), $dModel['lines']['vfx'], $fv[2], $dModel['lines']['3d'], $f3[0], 99)),
     'Esta ligação criava um ciclo.', '::manual_add_rules VFX «Produção de shots» → 3D «Styleframes» fecha um ciclo com a ligação do modelo');
// depois de retirada, a ligação já não conta para o ciclo
pipe2_dep_remove($tp, p2d_deal($tp, $dModel['id']), (int)p2v_col($tp, 'SELECT id FROM deal_line_deps WHERE deal_id = ?', [$dModel['id']]), 99);
t_true(pipe2_dep_add($tp, p2d_deal($tp, $dModel['id']), $dModel['lines']['vfx'], $fv[2], $dModel['lines']['3d'], $f3[0], 99) > 0,
       '::manual_add_rules sem a ligação do modelo (retirada) já não é ciclo');

// ── ::manual_add_met_when_done ──────────────────────────────
// a VFX já está em «Finais e QC»: concluiu «Look dev» — a ligação nasce cumprida (senão a 3D esperava para sempre)
$dd = p2d_deal($tp, $dDone['id']);
$m1 = pipe2_dep_add($tp, $dd, $dDone['lines']['vfx'], $fv[1], $dDone['lines']['3d'], $f3[2], 99);
t_true(p2v_col($tp, 'SELECT met_at FROM deal_line_deps WHERE id = ?', [$m1]) !== null, '::manual_add_met_when_done a origem já passou X → cumprida ao nascer');
$m2 = pipe2_dep_add($tp, $dd, $dDone['lines']['vfx'], $fv[3], $dDone['lines']['3d'], $f3[3], 99);
t_eq(p2v_col($tp, 'SELECT met_at FROM deal_line_deps WHERE id = ?', [$m2]), null, '::manual_add_met_when_done a origem ainda está em X → por cumprir');
p2v_front($tp, $dDone['lines']['vfx'], ['status' => 'depwait']);   // À espera no fim de «Finais e QC» (já concluída)
$m3d = pipe2_dep_add($tp, $dd, $dDone['lines']['vfx'], $fv[3], $dDone['lines']['3d'], $f3[4], 99);
t_true(p2v_col($tp, 'SELECT met_at FROM deal_line_deps WHERE id = ?', [$m3d]) !== null, '::manual_add_met_when_done À espera no fim de X → X concluída → cumprida');

// ── ::deps_into ─────────────────────────────────────────────
$into = pipe2_deps_into($tp, $full['ed'], $st('ed', 'Picture lock'));
t_eq(array_map(fn($d) => [(int)$d['from_line_id'], (int)$d['from_stage_id']], $into),
     [[$full['cmp'], $st('cmp', 'Composição')], [$full['md'], $st('md', 'Revisões')]], '::deps_into as duas ligações para (Ed, Picture lock), por id');
t_eq([$into[0]['from_stage_name'] ?? null, $into[0]['from_service'] ?? null], ['Composição', 'Composit'], '::deps_into com o nome da sub-etapa e do serviço de origem');
t_eq(pipe2_deps_into($tp, $full['ed'], $st('ed', 'Montagem offline')), [], '::deps_into nenhuma para outra sub-etapa');

// ── ::mark_met_releases_when_all_met ────────────────────────
$mm = $dMet['lines'];
pipe2_deps_instantiate($tp, p2d_deal($tp, $dMet['id']));
$depId = fn(string $a, string $x) => (int)p2v_col($tp, 'SELECT id FROM deal_line_deps WHERE deal_id = ? AND from_line_id = ? AND from_stage_id = ?', [$dMet['id'], $mm[$a], $st($a, $x)]);
$r = pipe2_deps_mark_met($tp, $dMet['id'], $mm['cmp'], $st('cmp', 'Composição'));
t_eq($r, ['met' => [$depId('cmp', 'Composição')], 'released' => []], '::mark_met_releases_when_all_met a 1.ª das duas: cumprida, Ed ainda espera');
t_eq(count(pipe2_deps_into($tp, $mm['ed'], $st('ed', 'Picture lock'))), 1, '::mark_met_releases_when_all_met falta 1 por cumprir');
t_eq(count(pipe2_deps_into($tp, $mm['ed'], $st('ed', 'Picture lock'), false)), 2, '::mark_met_releases_when_all_met com as cumpridas, 2');
$r = pipe2_deps_mark_met($tp, $dMet['id'], $mm['md'], $st('md', 'Revisões'));
t_eq($r, ['met' => [$depId('md', 'Revisões')], 'released' => [[$mm['ed'], $st('ed', 'Picture lock')]]], '::mark_met_releases_when_all_met a 2.ª: Ed libertada em Picture lock');
t_eq(pipe2_deps_mark_met($tp, $dMet['id'], $mm['md'], $st('md', 'Revisões')), ['met' => [], 'released' => []], '::mark_met_releases_when_all_met outra vez: nada');
t_eq(pipe2_deps_mark_met($tp, $dMet['id'], $mm['vfx'], $st('vfx', 'Turnover e breakdown')), ['met' => [], 'released' => []], '::mark_met_releases_when_all_met sub-etapa sem ligações: nada');
t_true(p2v_col($tp, 'SELECT met_at FROM deal_line_deps WHERE id = ?', [$depId('md', 'Revisões')]) !== null, '::mark_met_releases_when_all_met met_at gravado');
// uma retirada não conta como por cumprir: com a do 3D retirada, cumprir a da VFX liberta o Composit em «Integração e look»…
pipe2_dep_remove($tp, p2d_deal($tp, $dMet['id']), $depId('3d', 'Lighting e render'), 99);
t_eq(pipe2_deps_mark_met($tp, $dMet['id'], $mm['3d'], $st('3d', 'Lighting e render')), ['met' => [], 'released' => []], '::mark_met_releases_when_all_met uma retirada não se cumpre');
t_eq(pipe2_deps_mark_met($tp, $dMet['id'], $mm['vfx'], $st('vfx', 'Finais e QC'))['released'], [[$mm['cmp'], $st('cmp', 'Integração e look')]],
     '::mark_met_releases_when_all_met só ela para esse destino: libertada');

// ── ::waiting_not_late (puro) ───────────────────────────────
$step = ['plan_end' => '2026-11-02'];   // segunda-feira
t_eq(pipe2_front_late_days(['status' => 'depwait'], $step, '2026-11-06'), 0, '::waiting_not_late À espera → 0');
t_eq(pipe2_front_late_days(['status' => 'running'], $step, '2026-11-06'), 4, '::waiting_not_late em curso → 4 dias úteis depois do plan_end');
t_eq(pipe2_front_late_days(['status' => 'ongoing'], $step, '2026-11-06'), 4, '::waiting_not_late avença ativa também se atrasa');
t_eq(pipe2_front_late_days(['status' => 'queued'], $step, '2026-11-06'), 0, '::waiting_not_late Por começar → 0');
t_eq(pipe2_front_late_days(['status' => 'ready'], $step, '2026-11-06'), 0, '::waiting_not_late Pronto → 0');
t_eq(pipe2_front_late_days(['status' => 'ongoing', 'stage_role' => 'ready'], $step, '2026-11-06'), 0, '::waiting_not_late avença em Pronto entre ciclos → 0');
t_eq(pipe2_front_late_days(['status' => 'running'], null, '2026-11-06'), 0, '::waiting_not_late sem linha de passos → 0');
t_eq(pipe2_front_late_days(['status' => 'running'], ['plan_end' => null], '2026-11-06'), 0, '::waiting_not_late sem plano → 0');
t_eq(pipe2_front_late_days(['status' => 'running'], $step, '2026-11-02'), 0, '::waiting_not_late no próprio dia → 0');
t_eq(pipe2_front_late_days(['status' => 'running'], ['plan_end' => '2026-11-06'], '2026-11-08'), 0, '::waiting_not_late o fim de semana não conta');
t_eq(pipe2_front_late_days(['status' => 'running'], ['plan_end' => '2026-11-06'], '2026-11-09'), 1, '::waiting_not_late segunda-feira seguinte → 1');

// ── ::no_replan_while_waiting (dados) ───────────────────────
$edLine = $dWait['lines']['ed'];
$fe = $S['ed']['fronts'];
p2v_steps($tp, $edLine, ['plan_days' => 2]);
$tp->prepare("UPDATE deal_line_steps SET plan_start = '2026-10-01', plan_end = '2026-10-05', done_at = NOW() WHERE line_id = ? AND stage_id = ?")->execute([$edLine, $fe[1]]);
$tp->prepare("UPDATE deal_line_steps SET plan_start = '2026-10-05', plan_end = '2026-10-07' WHERE line_id = ? AND stage_id = ?")->execute([$edLine, $fe[2]]);
$sig = p2v_sig($tp, ['deal_line_steps'], 'line_id = ' . $edLine);
$wl = p2v_rows($tp, 'SELECT * FROM deal_lines WHERE id = ?', [$edLine])[0];
t_eq(pipe2_replan($tp, $wl, '2026-11-06'), 0, '::no_replan_while_waiting Ed À espera com a sub-etapa atrasada → 0');
t_eq(p2v_sig($tp, ['deal_line_steps'], 'line_id = ' . $edLine), $sig, '::no_replan_while_waiting as linhas de passos não mudam');
t_eq((int)p2v_col($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'p2_replan'", [$dWait['id']]), 0, '::no_replan_while_waiting sem histórico');
$wStep = p2v_rows($tp, 'SELECT * FROM deal_line_steps WHERE line_id = ? AND stage_id = ?', [$edLine, $fe[1]])[0];
t_eq(pipe2_front_late_days($wl, $wStep, '2026-11-06'), 0, '::no_replan_while_waiting e não conta como atrasada');

// ── ::no_replan_while_waiting (motor) ───────────────────────
pipe2_deps_instantiate($tp, p2d_deal($tp, $dWait['id']));   // Composit ainda em «Preparação de plates»: a ligação para Picture lock por cumprir
t_eq(count(pipe2_deps_into($tp, $edLine, $st('ed', 'Picture lock'))), 1, '::no_replan_while_waiting (motor) Ed espera por uma ligação');
pipe2_request_reset();
$re = pipe2_front_reevaluate($tp, p2d_deal($tp, $dWait['id']), pipe2_front_get($tp, $edLine));
t_true(in_array($re, ['idle', 'waiting'], true), '::no_replan_while_waiting (motor) a reavaliação de Ed À espera não a move (' . $re . ')');
t_eq([(string)p2v_col($tp, 'SELECT status FROM deal_lines WHERE id = ?', [$edLine]), (int)p2v_col($tp, 'SELECT stage_id FROM deal_lines WHERE id = ?', [$edLine])],
     ['depwait', $fe[1]], '::no_replan_while_waiting (motor) e Ed continua À espera em «Montagem offline»');
t_eq(pipe2_replan($tp, pipe2_front_get($tp, $edLine), '2026-11-06'), 0, '::no_replan_while_waiting (motor) e sem replaneamento');

// ── ::waits_before_y (D8, E7) ───────────────────────────────
$eg = $dEng['lines'];
foreach ($eg as $lid) p2v_steps($tp, $lid);
p2v_front($tp, $eg['ed'], ['exec_owner_id' => 99]);
pipe2_deps_instantiate($tp, p2d_deal($tp, $dEng['id']));
$engDep = p2d_links($tp, $dEng['id']);
t_eq($engDep, [[$eg['cmp'], $st('cmp', 'Composição'), $eg['ed'], $st('ed', 'Picture lock'), 'model']], '::waits_before_y a ligação «Composit conclui Composição → Edição Picture lock»');
$edGate = p2v_task($tp, $dEng['id'], ['title' => 'P2T · fim da montagem', 'line_id' => $eg['ed'], 'stage_id' => $st('ed', 'Montagem offline'), 'is_gate' => 1]);
pipe2_request_reset();
task_done($tp, $edGate);
$el = p2v_rows($tp, 'SELECT status, stage_id FROM deal_lines WHERE id = ?', [$eg['ed']])[0];
t_eq([$el['status'], (int)$el['stage_id']], ['depwait', $st('ed', 'Montagem offline')], '::waits_before_y fechar a última obrigatória de «Montagem offline»: Ed À espera, na sub-etapa');
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND stage_id = ?', [$dEng['id'], $st('ed', 'Picture lock')]), 0, '::waits_before_y sem tarefas de Picture lock');
t_eq((int)p2v_col($tp, "SELECT COUNT(*) FROM stage_events WHERE deal_id = ? AND line_id = ? AND step = 'p2_wait'", [$dEng['id'], $eg['ed']]), 1, '::waits_before_y p2_wait em stage_events');
t_true(p2v_col($tp, 'SELECT done_at FROM deal_line_steps WHERE line_id = ? AND stage_id = ?', [$eg['ed'], $st('ed', 'Montagem offline')]) !== null,
       '::waits_before_y «Montagem offline» concluída (done_at)');
t_eq((string)p2v_col($tp, "SELECT note FROM deal_history WHERE deal_id = ? AND event = 'p2_wait'", [$dEng['id']]), 'Edição: à espera de Composit («Composição»)',
     '::waits_before_y o histórico diz de quem espera');
t_eq(p2v_col($tp, "SELECT user_id FROM deal_history WHERE deal_id = ? AND event = 'p2_wait'", [$dEng['id']]), null, '::waits_before_y como «Sistema»');
t_eq(pipe2_front_reevaluate($tp, p2d_deal($tp, $dEng['id']), pipe2_front_get($tp, $eg['ed'])), 'waiting', '::waits_before_y a reavaliação diz waiting');

// ── ::tasks_born_on_release (D8, nota 4) ────────────────────
$cmpGate = p2v_task($tp, $dEng['id'], ['title' => 'P2T · fim da composição', 'line_id' => $eg['cmp'], 'stage_id' => $st('cmp', 'Composição'), 'is_gate' => 1]);
pipe2_request_reset();
task_done($tp, $cmpGate);
t_eq((int)p2v_col($tp, 'SELECT stage_id FROM deal_lines WHERE id = ?', [$eg['cmp']]), $st('cmp', 'Integração e look'), '::tasks_born_on_release Composit sai de «Composição»');
t_true(p2v_col($tp, 'SELECT met_at FROM deal_line_deps WHERE deal_id = ?', [$dEng['id']]) !== null, '::tasks_born_on_release a ligação fica cumprida (met_at)');
$el = p2v_rows($tp, 'SELECT status, stage_id FROM deal_lines WHERE id = ?', [$eg['ed']])[0];
t_eq([$el['status'], (int)$el['stage_id']], ['running', $st('ed', 'Picture lock')], '::tasks_born_on_release Ed entra em Picture lock');
$born = fn(int $rule) => (int)p2v_col($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND line_id = ? AND stage_id = ? AND auto_rule_id = ?',
                                      [$dEng['id'], $eg['ed'], $st('ed', 'Picture lock'), $rule]);
t_eq([$born($rPlEnter), $born($rPlDep)], [1, 1], '::tasks_born_on_release as tarefas f_enter e dep_met de Picture lock nascem');
$ev = p2v_rows($tp, "SELECT reason, user_id FROM stage_events WHERE deal_id = ? AND line_id = ? AND step = 'p2_front' ORDER BY id DESC LIMIT 1", [$dEng['id'], $eg['ed']]);
t_eq([$ev[0]['reason'] ?? '', array_key_exists('user_id', $ev[0] ?? []) ? $ev[0]['user_id'] : '(falta)'], ['dep', null], '::tasks_born_on_release o movimento de Ed é do «Sistema», por dependência');
$notes = p2v_rows($tp, "SELECT user_id, line_id, stage_id FROM pipe2_notices WHERE deal_id = ? AND kind = 'dep_met'", [$dEng['id']]);
t_eq(array_map(fn($r) => [(int)$r['user_id'], (int)$r['line_id'], (int)$r['stage_id']], $notes), [[99, $eg['ed'], $st('ed', 'Picture lock')]],
     '::tasks_born_on_release um aviso dep_met para o responsável de Ed');
// uma 2.ª libertação (Ed outra vez À espera, a ligação por cumprir) não avisa nem cria outra vez
p2v_front($tp, $eg['ed'], ['status' => 'depwait', 'stage_id' => $st('ed', 'Montagem offline')]);
$tp->prepare('UPDATE deal_line_deps SET met_at = NULL WHERE deal_id = ?')->execute([$dEng['id']]);
pipe2_request_reset();
t_eq(pipe2_front_release($tp, p2d_deal($tp, $dEng['id']), pipe2_front_get($tp, $eg['cmp']), $st('cmp', 'Composição')), [$eg['ed']], '::tasks_born_on_release pipe2_front_release devolve Ed');
t_eq((int)p2v_col($tp, 'SELECT stage_id FROM deal_lines WHERE id = ?', [$eg['ed']]), $st('ed', 'Picture lock'), '::tasks_born_on_release Ed outra vez em Picture lock');
t_eq((int)p2v_col($tp, "SELECT COUNT(*) FROM pipe2_notices WHERE deal_id = ? AND kind = 'dep_met'", [$dEng['id']]), 1, '::tasks_born_on_release e não outro aviso');
t_eq([$born($rPlEnter), $born($rPlDep)], [1, 1], '::tasks_born_on_release nem outras tarefas');

// ── ::v1_noop (regra de ouro) ───────────────────────────────
$sig = p2v_sig($tp, ['deal_line_deps', 'deal_history', 'deal_lines']);
t_eq(pipe2_deps_instantiate($tp, p2d_deal($tp, $v1)), 0, '::v1_noop um negócio v1 não recebe ligações');
$v1Lines = array_map('intval', array_column(p2v_rows($tp, 'SELECT id FROM deal_lines WHERE deal_id = ? ORDER BY is_main DESC, id', [$v1]), 'id'));
$realVfxStages = p2v_stages($tp, $realVfx)['fronts'];
$real3dStages = p2v_stages($tp, $real3d)['fronts'];
t_eq(p2d_err(fn() => pipe2_dep_add($tp, p2d_deal($tp, $v1), $v1Lines[0], $realVfxStages[0], $v1Lines[1], $real3dStages[1], 1)), 'Este negócio não é da Pipeline v2.',
     '::v1_noop pipe2_dep_add recusa um negócio v1');
t_eq(p2v_sig($tp, ['deal_line_deps', 'deal_history', 'deal_lines']), $sig, '::v1_noop nada mudou');

// ── ::strict (ONLY_FULL_GROUP_BY, STRICT_ALL_TABLES) ────────
pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES');
$err = p2d_err(function () use ($tp, $dStrict, $st, $S) {
    $deal = p2d_deal($tp, $dStrict['id']);
    pipe2_models_for($tp, 'cgi');
    pipe2_deps_instantiate($tp, $deal);
    pipe2_deps_into($tp, $dStrict['lines']['cmp'], $st('cmp', 'Integração e look'));
    pipe2_dep_add($tp, $deal, $dStrict['lines']['vfx'], $S['vfx']['fronts'][0], $dStrict['lines']['3d'], $S['3d']['fronts'][0], 99);
    pipe2_deps_mark_met($tp, $dStrict['id'], $dStrict['lines']['vfx'], $st('vfx', 'Finais e QC'));
    $id = (int)p2v_col($tp, 'SELECT id FROM deal_line_deps WHERE deal_id = ? ORDER BY id DESC LIMIT 1', [$dStrict['id']]);
    pipe2_dep_remove($tp, $deal, $id, 99);
});
t_eq($err, '', '::strict as consultas das dependências em modo estrito');
t_eq(count(p2d_links($tp, $dStrict['id'])), 3, '::strict vfx+3d+cmp (cgi): as 3 do modelo, a manual retirada');
pipe2_sql_mode($tp, '');

t_done();
