<?php
/**
 * Pipeline v2, fase 0 — larguras dos códigos e dos nomes que a v2 grava (F3, M5).
 *
 * A produção corre MySQL sem modo estrito e corta sem avisar um valor maior do que a coluna. Por isso cada código
 * novo (PIPE2_CODES) e cada nome semeado (PIPE2_SEED_*) tem de caber na coluna onde vai ficar, tal como ela está na
 * bancada `topper` depois do boot (que migra para a 43). Os estados e os gatilhos novos não podem colidir com os da v1.
 *
 * Casos: ::widths ::names ::no_clash
 *
 * Uso: php tests/pipe2_codes_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
pipe2_lock($pdo);
require_once __DIR__ . '/../admin/inc/migrate_pipeline2.php';

/** CHARACTER_MAXIMUM_LENGTH de tabela.coluna na bancada, ou null se a coluna não existir. */
function p2k_width(PDO $pdo, string $tc): ?int {
    [$t, $c] = explode('.', $tc, 2);
    $st = $pdo->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$t, $c]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (int)$v;
}

/** Todos os valores de $vals cabem em tabela.coluna. */
function p2k_fits(PDO $pdo, string $tc, array $vals, string $case): void {
    $w = p2k_width($pdo, $tc);
    $max = max(array_map('mb_strlen', array_map('strval', $vals)));
    $long = array_values(array_filter($vals, fn($v) => $w === null || mb_strlen((string)$v) > $w));
    t_true($w !== null && $max <= $w, "$case $tc (largura " . ($w ?? 'sem coluna') . ", maior valor $max)" . ($long ? ': ' . implode(', ', $long) : ''));
}

// ::widths — cada código novo cabe na sua coluna
foreach (PIPE2_CODES as $tc => $codes) p2k_fits($pdo, $tc, $codes, '::widths');

// ::names — cada nome e código semeado cabe na sua coluna (funnel_stages.name tem 120)
$frontNames = [];
foreach (PIPE2_SEED_FRONTS as [$names]) array_push($frontNames, ...$names);
$dealNames = array_column(PIPE2_SEED_DEAL_STAGES, 0);
foreach (array_merge($frontNames, $dealNames) as $n) t_true(mb_strlen($n) <= 120, "::names «{$n}» ≤ 120");
p2k_fits($pdo, 'funnel_stages.name', array_merge($frontNames, $dealNames), '::names');
p2k_fits($pdo, 'funnel_stages.role', array_merge(array_column(PIPE2_SEED_DEAL_STAGES, 3), ['revisions', 'ready']), '::names');
p2k_fits($pdo, 'funnel_stages.kind', array_column(PIPE2_SEED_DEAL_STAGES, 1), '::names');
p2k_fits($pdo, 'funnels.name', array_column(PIPE2_SERVICES, 'name'), '::names');
p2k_fits($pdo, 'funnels.service_code', array_keys(PIPE2_SEED_FRONTS), '::names');
p2k_fits($pdo, 'pipe2_categories.code', array_column(PIPE2_SEED_CATEGORIES, 0), '::names');
p2k_fits($pdo, 'pipe2_categories.name', array_column(PIPE2_SEED_CATEGORIES, 1), '::names');
foreach ([3 => 'need_all', 4 => 'need_any', 6 => 'exclude_any'] as $i => $col) {
    p2k_fits($pdo, 'pipe2_categories.' . $col, array_column(PIPE2_SEED_CATEGORIES, $i), '::names');
}
p2k_fits($pdo, 'pipe2_dep_models.category', array_column(PIPE2_SEED_DEP_MODELS, 0), '::names');
p2k_fits($pdo, 'departments.name', array_values(PIPE2_SEED_DEPTS), '::names');
t_eq(array_keys(PIPE2_SEED_FRONTS), array_keys(PIPE2_SERVICES), '::names há sub-etapas para os 7 serviços, pela ordem');
foreach (PIPE2_SEED_FRONTS as $code => [$names, $rounds]) {
    t_eq(count($names), 6, "::names $code tem 6 sub-etapas");
    t_eq(array_slice($names, 4), ['Revisões', 'Pronto'], "::names $code acaba em Revisões e Pronto");
}
foreach (PIPE2_SEED_DEP_MODELS as [$cat, $fa, $na, $fb, $nb]) {
    t_true(in_array($na, PIPE2_SEED_FRONTS[$fa][0] ?? [], true) && in_array($nb, PIPE2_SEED_FRONTS[$fb][0] ?? [], true),
        "::names a ligação $cat «{$fa} · {$na}» → «{$fb} · {$nb}» aponta para sub-etapas semeadas");
}

// ::no_clash — estados e gatilhos novos, diferentes dos da v1
t_eq(array_values(array_intersect(PIPE2_CODES['deal_lines.status'], array_keys(XSELL_STATUS))), [], '::no_clash estados da frente ≠ XSELL_STATUS');
t_eq(array_values(array_intersect(PIPE2_CODES['stage_automations.trig'], array_keys(FLOW_TRIGGERS))), [], '::no_clash gatilhos novos ≠ FLOW_TRIGGERS');

t_done();
