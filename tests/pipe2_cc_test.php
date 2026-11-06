<?php
/**
 * Pipeline v2, fase 1 — Toppernote no Kickoff de um negócio v2 (C1, tarefa 16).
 *
 * As frentes criam-se antes do Topperboard do projeto (cc_on_kickoff): num negócio v2 de vários serviços, o quadro do
 * projeto tem um sub-quadro «front» por frente (com a frente e o responsável que o Kickoff lhe deu); num negócio v2 de
 * um só serviço, o quadro do projeto sem quadros de frente. funnels.cc_stages continua a escolher os sub-quadros
 * criativos (Brief, Conceito…), nunca as sub-etapas do serviço.
 *
 * Corre numa base privada (p2v_db). Não escreve na bancada.
 *   php tests/pipe2_cc_test.php
 * Casos: ::fronts_before_board ::single_service_board
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/pipeline.php';
require_once __DIR__ . '/../admin/inc/pipeline_flow.php';
require_once __DIR__ . '/../admin/inc/cc_flow.php';
require_once __DIR__ . '/../admin/inc/pipe2_fronts.php';

[$db, $tp] = p2v_db();

/** [classe, mensagem] de $fn, ou ['', '']. */
function p2c_err(callable $fn): array {
    try { $fn(); } catch (Throwable $e) { return [get_class($e), $e->getMessage()]; }
    return ['', ''];
}

/** Os sub-quadros de um quadro, pela ordem. */
function p2c_children(PDO $pdo, int $board): array {
    return p2v_rows($pdo, 'SELECT * FROM cc_boards WHERE parent_id = ? ORDER BY position, id', [$board]);
}

// ── o mundo (todos os funis e negócios antes da primeira leitura das caches da v1) ──
$w = p2v_world($tp, ['vfx', '3d']);
$sv = p2v_stages($tp, $w['vfx']);
$s3 = p2v_stages($tp, $w['3d']);
$tp->prepare('UPDATE funnels SET cc_stages = ? WHERE id = ?')->execute(['brief,conceito', $w['vfx']]);
$tp->prepare('UPDATE funnels SET cc_stages = ? WHERE id = ?')->execute(['refs', $w['3d']]);
$dk = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'feedback']);                     // dois serviços: as frentes planned
$ds = p2v_deal($tp, $w, ['3d'], ['at' => 'feedback', 'lines' => false]);          // um só serviço, ainda sem frente
$subNames = array_column(p2v_rows($tp, "SELECT name FROM funnel_stages WHERE funnel_id IN (?, ?) AND level = 'front'", [$w['vfx'], $w['3d']]), 'name');

// ── ::fronts_before_board (C1) ──────────────────────────────
$c = '::fronts_before_board';
t_true(pipe2_deal_is_v2($tp, deal_get($tp, $dk['id'])), "$c o negócio é v2");
t_eq(p2v_col($tp, 'SELECT exec_owner_id FROM deal_lines WHERE id = ?', [$dk['lines']['vfx']]), null, "$c antes do Kickoff a frente VFX não tem responsável");
p2v_as(1);
t_eq(p2c_err(fn() => deal_move($tp, deal_get($tp, $dk['id']), $sv['kickoff'], 'teste')), ['', ''], "$c deal_move para o Kickoff (forçado por admin) passa");
$project = cc_deal_board($tp, $dk['id'], 'project');
t_true($project !== null, "$c o Topperboard do projeto existe");
$fronts = array_values(array_filter(p2c_children($tp, (int)($project['id'] ?? 0)), fn($b) => $b['kind'] === 'front'));
t_eq(array_map(fn($b) => (int)$b['line_id'], $fronts), [$dk['lines']['vfx'], $dk['lines']['3d']], "$c um sub-quadro de frente por frente, com a frente");
$L = p2v_rows($tp, 'SELECT id, status, exec_owner_id FROM deal_lines WHERE deal_id = ? ORDER BY is_main DESC, position, id', [$dk['id']]);
t_eq(array_column($L, 'status'), ['queued', 'queued'], "$c as frentes ficaram Por começar no Kickoff");
t_true($L[0]['exec_owner_id'] !== null, "$c o Kickoff deu responsável à frente VFX (o chefe do serviço)");
t_eq(array_map(fn($b) => $b['exec_owner_id'] === null ? null : (int)$b['exec_owner_id'], $fronts), array_map(fn($l) => $l['exec_owner_id'] === null ? null : (int)$l['exec_owner_id'], $L),
     "$c o sub-quadro de cada frente leva o responsável da frente (as frentes já existiam quando o quadro nasceu)");
$stageKeys = array_map(fn($f) => array_column(p2c_children($tp, (int)$f['id']), 'stage_key'), $fronts);
t_eq($stageKeys, [['brief', 'conceito'], ['refs']], "$c os sub-quadros de cada frente são os criativos do cc_stages do funil dela");
$titles = array_merge(...array_map(fn($f) => array_column(p2c_children($tp, (int)$f['id']), 'title'), $fronts));
t_eq(array_values(array_intersect($titles, $subNames)), [], "$c nenhum sub-quadro tem o nome de uma sub-etapa do serviço");
t_eq((int)p2v_col($tp, "SELECT COUNT(*) FROM cc_boards WHERE deal_id = ? AND kind = 'project'", [$dk['id']]), 1, "$c um só quadro do projeto");

// ── ::single_service_board (C1) ─────────────────────────────
$c = '::single_service_board';
t_eq(p2c_err(fn() => deal_move($tp, deal_get($tp, $ds['id']), $s3['kickoff'], 'teste')), ['', ''], "$c deal_move para o Kickoff (forçado por admin) passa");
t_eq((int)p2v_col($tp, "SELECT COUNT(*) FROM deal_lines WHERE deal_id = ? AND status = 'queued' AND is_main = 1", [$ds['id']]), 1, "$c o Kickoff criou a frente principal");
$project = cc_deal_board($tp, $ds['id'], 'project');
t_true($project !== null, "$c o Topperboard do projeto existe");
$kids = p2c_children($tp, (int)($project['id'] ?? 0));
t_eq(array_values(array_filter(array_column($kids, 'kind'), fn($k) => $k === 'front')), [], "$c sem sub-quadros de frente");
t_eq(array_column($kids, 'stage_key'), ['refs'], "$c os sub-quadros do projeto são os criativos do cc_stages do funil");
p2v_as(null);

t_done();
