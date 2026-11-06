<?php
/**
 * Pipeline v2, fase 1 — totais do Pipeline e «Ganhos a faturar» (admin/inc/pipe2_metrics.php; tarefa 8: X2, D4).
 *
 * A frase do cabeçalho mostra o total em pipeline (todos os negócios abertos; na vista «Negócios» do Pipeline também
 * os de produção); o cartão «Ganhos a faturar» mostra os que chegaram ao Kickoff e ainda não concluíram (Kickoff, Em
 * execução, Entrega e pagamento final), sem ponderado. Valores: budget senão value_estimate; um negócio só de avenças
 * vale a soma das mensalidades × meses (pipe2_deal_values, por lote, igual a pipe2_deal_value). As métricas de meta
 * novas (concluidos, valor_concluido) ficam fora de MX_GOAL_METRICS: o formulário de Metas não muda.
 *
 * Corre numa base privada do dump da 42, migrada até à SCHEMA_VERSION (p2v_db). Não escreve na bancada.
 *
 * Casos: ::header_and_won ::without_negocios_excludes_production ::avenca_value ::values_batch ::v1_production_stage
 *        ::values_fallback ::concluidos
 *
 * Uso: php tests/pipe2_totals_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/metrics.php';
require_once __DIR__ . '/../admin/inc/pipe2_metrics.php';

// ── a base privada; os funis e os negócios todos antes da primeira leitura de mx_stages/metrics_deals ──
[$db, $tp] = p2v_db();
$w = p2v_world($tp, ['vfx', 'cam', 'adv']);
$realVfx = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY id LIMIT 1");
$v1Prod = (int)p2v_col($tp, "SELECT id FROM funnel_stages WHERE funnel_id = ? AND level = 'deal' AND kind = 'production' ORDER BY position, id LIMIT 1", [$realVfx]);
t_true($realVfx > 0 && $v1Prod > 0, 'a base privada tem o funil VFX real com uma etapa de produção da v1');

$val = fn(int $budget, int $estimate = 0) => ['budget' => $budget, 'value_estimate' => $estimate];
$p1 = p2v_deal($tp, $w, ['vfx'], ['at' => 'xray'] + $val(1000))['id'];
$p2 = p2v_deal($tp, $w, ['vfx'], ['at' => 'proposal'] + $val(2000))['id'];
$p3 = p2v_deal($tp, $w, ['vfx'], ['at' => 'feedback'] + $val(0, 3000))['id'];   // budget 0.00: conta o value_estimate
$k  = p2v_deal($tp, $w, ['vfx'], ['at' => 'kickoff'] + $val(4000))['id'];
$e  = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec'] + $val(5000))['id'];
$dl = p2v_deal($tp, $w, ['vfx'], ['at' => 'delivery'] + $val(6000))['id'];
$dn = p2v_deal($tp, $w, ['vfx'], ['at' => 'done'] + $val(7000))['id'];
$ld = pipe2_deal($tp, ['phase' => 'lead', 'value_estimate' => 999]);
$lo = pipe2_deal($tp, ['funnel_id' => $w['vfx'], 'phase' => 'lost', 'budget' => 888]);
$header = [$p1, $p2, $p3, $k, $e, $dl, $dn, $ld, $lo];
// avenças
$av   = p2v_deal($tp, $w, ['cam'], ['at' => 'exec', 'budget' => 0, 'value_estimate' => 10, 'billing' => ['cam' => [500, 12]]])['id'];
$mix  = p2v_deal($tp, $w, ['vfx', 'cam'], ['at' => 'exec', 'budget' => 9000, 'billing' => ['cam' => [500, 12]]])['id'];
$av2  = p2v_deal($tp, $w, ['cam', 'adv'], ['at' => 'exec', 'budget' => 0, 'value_estimate' => 10, 'billing' => ['cam' => [500, 12], 'adv' => [100, 3]]])['id'];
$avm  = p2v_deal($tp, $w, ['cam', 'vfx'], ['at' => 'exec', 'budget' => 50, 'billing' => ['cam' => [100, 3]], 'status' => ['vfx' => 'merged']])['id'];
$none = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec', 'budget' => 0, 'value_estimate' => 1500, 'lines' => false])['id'];
// v1 em produção, numa etapa de produção da v1
$v1 = pipe2_deal($tp, ['funnel_id' => $realVfx, 'phase' => 'production', 'stage_id' => $v1Prod, 'budget' => 2500]);

$all = metrics_deals($tp, true);
$pick = fn(array $ids) => array_intersect_key($all, array_flip($ids));
$h = $pick($header);
$hv = pipe2_deal_values($tp, $h);
$tot = fn(int $n, float $v) => ['n' => $n, 'value' => $v];
$won = fn(int $n, float $v, int $kick, int $exec, int $deliv) => ['n' => $n, 'value' => $v, 'by' => ['kickoff' => $kick, 'exec' => $exec, 'delivery' => $deliv]];

// ── ::header_and_won (X2) ───────────────────────────────────
$c = '::header_and_won';
t_eq(count($h), 9, "$c os 9 registos estão nas métricas");
t_eq(pipe2_totals($h, $hv, true), ['open' => $tot(6, 21000), 'won_to_bill' => $won(3, 15000, 1, 1, 1)],
    "$c com produção: 6 · 21 000 abertos; ganhos a faturar 3 · 15 000 (Kickoff 1, Em execução 1, Entrega 1); sem concluído, lead nem perdido");

// ── ::without_negocios_excludes_production ──────────────────
$c = '::without_negocios_excludes_production';
t_eq(pipe2_totals($h, $hv, false), ['open' => $tot(3, 6000), 'won_to_bill' => $won(3, 15000, 1, 1, 1)],
    "$c sem a aba Negócios: 3 · 6000 (só o Pipeline); ganhos a faturar iguais (quem chama não os mostra)");

// ── ::avenca_value (D4) ─────────────────────────────────────
$c = '::avenca_value';
$a = $pick([$av]);
t_eq(pipe2_deal_values($tp, $a), [$av => 6000.0], "$c só avença 500 × 12 → 6000 (não o value_estimate)");
t_eq(pipe2_totals($a, pipe2_deal_values($tp, $a), true), ['open' => $tot(1, 6000), 'won_to_bill' => $won(1, 6000, 0, 1, 0)], "$c conta 6000 nos totais");

// ── ::values_batch (por lote = pipe2_deal_value de cada um) ──
$c = '::values_batch';
$want = [$p1 => 1000.0, $p3 => 3000.0, $av => 6000.0, $mix => 9000.0, $av2 => 6300.0, $avm => 300.0, $none => 1500.0];
$got = pipe2_deal_values($tp, $pick(array_keys($want)));
ksort($got);
t_eq($got, $want, "$c pontual, budget 0.00, só avença, avença + pontual, duas avenças, avença com a pontual juntada, sem frentes");
foreach ($want as $id => $v) t_eq(pipe2_deal_value($tp, deal_get($tp, $id)), $v, "$c pipe2_deal_value($id) = $v, como o lote");
t_eq(pipe2_deal_values($tp, []), [], "$c sem negócios → []");

// ── ::v1_production_stage (antes da conversão: uma etapa de produção da v1 conta como Em execução) ──
$c = '::v1_production_stage';
$p = $pick([$k, $e, $dl, $v1]);
t_eq(pipe2_totals($p, pipe2_deal_values($tp, $p), true)['won_to_bill'], $won(4, 17500, 1, 2, 1), "$c o negócio v1 em «Produção de shots» conta em Em execução");

// ── ::values_fallback (sem valor do lote, o do registo) ─────
$c = '::values_fallback';
t_eq(pipe2_totals($a, [], true)['open'], $tot(1, 10), "$c sem pipe2_deal_values, o value do metrics_deals (10)");

// ── ::concluidos (o formulário de Metas não muda) ───────────
$c = '::concluidos';
t_eq(array_keys(PIPE2_GOAL_METRICS), ['concluidos', 'valor_concluido'], "$c as métricas de meta novas");
t_eq(array_intersect_key(PIPE2_GOAL_METRICS, MX_GOAL_METRICS), [], "$c nenhuma está em MX_GOAL_METRICS");
$err = '';
try { mx_goal_save($tp, 'company', 0, 'month', date('Y-m-d'), 'concluidos', 5, false, null); } catch (Throwable $ex) { $err = $ex->getMessage(); }
t_eq($err, 'Meta inválida.', "$c a gravação das Metas da v1 continua a recusar concluidos");
t_eq((int)p2v_col($tp, "SELECT COUNT(*) FROM metric_goals WHERE metric IN ('concluidos','valor_concluido')"), 0, "$c nada gravado");

t_done();
