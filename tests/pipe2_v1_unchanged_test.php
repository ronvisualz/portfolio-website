<?php
/**
 * Pipeline v2, fases 0 e 1 — prova de que a v1 não muda (F1; M2 integrado; a regra de ouro da fase 1).
 *
 * Até ao «Aplicar» (fase 3) a plataforma funciona exatamente em modo v1. A prova compara a fotografia da v1
 * (tests/pipe2_v1_snap.php: leitores PHP e ecrãs) com a linha de base tirada na 42, antes de se subir o
 * SCHEMA_VERSION (tests/.pipe2/base42.json, fora do Git). Sem a linha de base o teste FALHA com a instrução; nunca
 * passa em silêncio. A linha de base não se mexe: uma diferença corrige-se no ficheiro que a causou.
 *
 *   ::invariants            na bancada topper: bandeira vazia, versão = SCHEMA_VERSION, funis só da v2 inativos, leitores filtrados,
 *                           nenhuma regra ativa escondida, LEAD_SERVICES_LIST e deal_stale_days iguais aos da 42;
 *   ::after_migration       base privada do dump da 42, migrada num subprocesso (--migrate, até à SCHEMA_VERSION) e
 *                           fotografada noutro (--php): diff [] com a linha de base;
 *   ::injected_rows_hidden  na mesma base, as 4 linhas da T4 (etapa de produção e X-Ray retirados, regra mensal numa
 *                           sub-etapa, regra por data na etapa retirada): diff [] outra vez; e o controlo — uma etapa
 *                           viva injetada aparece no diff (a fotografia vê as etapas, o diff [] não é cego);
 *   ::after_migration_http  a parte HTTP da fotografia, na bancada viva (127.0.0.1:8765): diff [].
 *
 * Uso: php tests/pipe2_v1_unchanged_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/../admin/inc/pipe2.php';
pipe2_lock($pdo);
pipe2_cleanup($pdo);   // sobras «P2T» de uma corrida interrompida (também apareciam na fotografia HTTP)

// Sem a linha de base, pipe2_base_json/pipe2_base_dump falham com «gere a linha de base na 42 (T1)» e saem com 1.
$base = pipe2_base_json();
$dump = pipe2_base_dump();
if (($base['meta']['schema_version'] ?? null) !== 42 || !is_array($base['php'] ?? null) || !is_array($base['http'] ?? null)) {
    t_true(false, 'a linha de base tests/.pipe2/base42.json tem meta.schema_version 42, php e http — gere a linha de base na 42 (T1)');
    t_done();
}

function p2u_col(PDO $p, string $sql, array $args = []) {
    $st = $p->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
}

/** A 1.ª coluna de todas as linhas, como texto (ids ou códigos), pela ordem da consulta. */
function p2u_list(PDO $p, string $sql, array $args = []): array {
    $st = $p->prepare($sql);
    $st->execute($args);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** O diff com a linha de base tem de ser vazio; na falha mostra quantas diferenças há e os caminhos das primeiras. */
function p2u_no_diff(string $what, $want, $got): void {
    $d = pipe2_diff($want, $got);
    t_true($d === [], $what . ($d === [] ? '' : ' — ' . count($d) . " diferença(s) (linha de base ≠ agora):\n  " . implode("\n  ", array_slice($d, 0, 25))
        . (count($d) > 25 ? "\n  …" : '')));
}

/** Um subprocesso da fotografia; na falha mostra o erro e devolve null. */
function p2u_snap(string $what, array $args): ?array {
    $r = pipe2_sub('pipe2_v1_snap.php', $args);
    $ok = $r['code'] === 0 && is_array($r['json']);
    t_true($ok, "$what: pipe2_v1_snap.php " . implode(' ', $args) . ' sai com 0 e JSON (código ' . $r['code'] . ': ' . substr(trim($r['err']), 0, 600) . ')');
    return $ok ? $r['json'] : null;
}

// ── ::invariants (F1), na bancada topper ───────────────────

settings($pdo, true);
t_eq(pipe2_on($pdo), false, '::invariants pipe2_on é falso (a v2 não está ligada)');
t_eq((int)p2u_col($pdo, "SELECT COUNT(*) FROM settings WHERE skey = 'pipeline_v2_at'"), 0, '::invariants a fase 0 não grava settings.pipeline_v2_at');
t_eq((string)p2u_col($pdo, "SELECT svalue FROM settings WHERE skey = 'schema_version'"), (string)SCHEMA_VERSION, '::invariants schema_version é a SCHEMA_VERSION');

$v2only = p2u_list($pdo, 'SELECT id FROM funnels WHERE v2_only = 1 ORDER BY id');
t_eq($v2only, p2u_list($pdo, "SELECT id FROM funnels WHERE service_code IN ('md','cmp','ed') ORDER BY id"),
    '::invariants os funis v2_only são exatamente os de service_code md, cmp e ed');
t_eq(p2u_list($pdo, 'SELECT service_code FROM funnels WHERE v2_only = 1 ORDER BY service_code'), ['cmp', 'ed', 'md'],
    '::invariants há um funil v2_only por código md, cmp e ed');
t_eq((int)p2u_col($pdo, 'SELECT COUNT(*) FROM funnels WHERE v2_only = 1 AND is_active <> 0'), 0, '::invariants os funis v2_only estão todos inativos');

$hidden = array_map('intval', p2u_list($pdo, "SELECT id FROM funnel_stages WHERE level <> 'deal' OR retired_at IS NOT NULL"));
t_true(count($hidden) >= 42, '::invariants a bancada tem as sub-etapas da semente (o filtro tem o que esconder)');
$leaks = [];
foreach (array_map('intval', p2u_list($pdo, 'SELECT id FROM funnels ORDER BY id')) as $fid) {
    foreach (funnel_stages($pdo, $fid) as $s) {
        if (!pipe2_stage_live($s) || in_array((int)$s['id'], $hidden, true)) $leaks[] = "funil $fid, etapa {$s['id']} ({$s['name']})";
    }
}
t_eq($leaks, [], '::invariants funnel_stages() só devolve etapas vivas (pipe2_stage_live) em todos os funis');
t_eq((int)p2u_col($pdo, "SELECT COUNT(*) FROM stage_automations a JOIN funnel_stages s ON s.id = a.stage_id
                          WHERE a.is_active = 1 AND (s.level <> 'deal' OR s.retired_at IS NOT NULL)"), 0,
    '::invariants nenhuma regra ativa numa sub-etapa ou numa etapa retirada');
t_eq(LEAD_SERVICES_LIST, ['VFX / 3D', 'Marketing Buddy', 'Ambos'], '::invariants LEAD_SERVICES_LIST não muda');
t_eq(LEAD_SERVICES_LIST, $base['php']['constants']['LEAD_SERVICES_LIST'] ?? null, '::invariants LEAD_SERVICES_LIST igual à da linha de base');
t_eq(setting($pdo, 'deal_stale_days'), $base['php']['settings']['deal_stale_days'] ?? '(falta na linha de base)',
    '::invariants deal_stale_days igual ao da linha de base');

// ── ::after_migration (F1), numa base privada do dump da 42 ─

$db = pipe2_tmpdb_create($dump);
$mig = p2u_snap('::after_migration', ['--migrate', '--db=' . $db]);
t_eq([$mig['from'] ?? null, $mig['to'] ?? null], [42, SCHEMA_VERSION], '::after_migration a base privada migra da 42 para a SCHEMA_VERSION');
$after = p2u_snap('::after_migration', ['--php', '--db=' . $db]);
t_eq($after['meta']['schema_version'] ?? null, SCHEMA_VERSION, '::after_migration a fotografia é da base já na SCHEMA_VERSION');
p2u_no_diff('::after_migration a fotografia PHP da v1 depois da migração é igual à da 42', $base['php'], $after['php'] ?? null);

// ── ::injected_rows_hidden (M2 integrado) ──────────────────
// As mesmas 4 linhas da T4 (pipe2_filters_test), no funil VFX da base privada.

$tp = pipe2_tmpdb_pdo($db);
$vfx = (int)p2u_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY position, id LIMIT 1");
$vfront = ($mig['to'] ?? null) === SCHEMA_VERSION
    ? (int)p2u_col($tp, "SELECT id FROM funnel_stages WHERE funnel_id = ? AND level = 'front' ORDER BY position, id LIMIT 1", [$vfx]) : 0;
t_true($vfx > 0 && $vfront > 0, '::injected_rows_hidden a base privada está migrada e tem o VFX e as sub-etapas dele');
if ($vfx > 0 && $vfront > 0) {
    $now = date('Y-m-d H:i:s');
    $ins = $tp->prepare('INSERT INTO funnel_stages (funnel_id, name, kind, position, retired_at) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$vfx, 'P2T · produção retirada', 'production', 67, $now]);
    $retProd = (int)$tp->lastInsertId();
    $ins->execute([$vfx, 'P2T · X-Ray retirado', 'xray', 5, $now]);
    $rule = $tp->prepare('INSERT INTO stage_automations (stage_id, title, trig, due_days, is_active) VALUES (?, ?, ?, ?, 1)');
    $rule->execute([$vfront, 'P2T · mensal na sub-etapa', 'monthly', 1]);
    $rule->execute([$retProd, 'P2T · por data na etapa retirada', 'date', 0]);
    $inj = p2u_snap('::injected_rows_hidden', ['--php', '--db=' . $db]);
    p2u_no_diff('::injected_rows_hidden com as etapas retiradas e as regras escondidas, a fotografia PHP é igual à da 42', $base['php'], $inj['php'] ?? null);

    // Controlo: uma etapa viva injetada tem de aparecer no diff — a fotografia vê as etapas do VFX.
    $tp->prepare('INSERT INTO funnel_stages (funnel_id, name, kind, position) VALUES (?, ?, ?, ?)')->execute([$vfx, 'P2T · etapa viva (controlo)', 'production', 68]);
    $ctl = p2u_snap('::injected_rows_hidden (controlo)', ['--php', '--db=' . $db]);
    $ctlDiff = pipe2_diff($base['php'], $ctl['php'] ?? null);
    t_true((bool)array_filter($ctlDiff, fn($l) => str_starts_with($l, "funnels.$vfx.stages")),
        '::injected_rows_hidden (controlo) uma etapa viva injetada no VFX aparece no diff em funnels.' . $vfx . '.stages');
}
$tp = null;
pipe2_tmpdb_drop($db);

// ── ::after_migration_http (F1), na bancada viva ───────────

$live = p2u_snap('::after_migration_http', ['--http']);
p2u_no_diff('::after_migration_http os ecrãs da v1 na bancada (' . SCHEMA_VERSION . ') são iguais aos da 42', $base['http'], $live['http'] ?? null);

t_done();
