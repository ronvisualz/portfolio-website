<?php
/**
 * Pipeline v2, fase 0 — o modelo das etapas depois do passo 43 (M1, parte da fase 0).
 *
 * Sem kind novo: «Em execução» e «Entrega e pagamento final» são kind 'production' com role 'exec' e 'delivery', e
 * só existem nos funis novos (nos ativos criam-se na conversão, D2). As sub-etapas são sempre kind 'production'
 * (nunca 'done', por causa de funnel_fixed_stage). Corre numa base privada do dump v42, migrada.
 *
 * Casos: ::roles
 *
 * Uso: php tests/pipe2_model_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
pipe2_lock($pdo);
require_once __DIR__ . '/../admin/inc/migrate_pipeline2.php';

$db = pipe2_tmpdb_create(pipe2_base_dump());
$tp = pipe2_tmpdb_pdo($db);
$err = null;
try { migrate_pipeline2_43($tp); } catch (Throwable $e) { $err = $e->getMessage(); }
t_eq($err, null, '::roles migrar sem exceção');

$q = function (string $sql, array $a = []) use ($tp): array {
    $st = $tp->prepare($sql);
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};

// em cada funil novo, exec e delivery uma vez cada, kind production, level deal, posições 70 e 80
$funnels = array_values(array_filter($q("SELECT id, service_code FROM funnels WHERE service_code <> ''"), fn($f) => in_array($f['service_code'], ['md', 'cmp', 'ed'], true)));
t_eq(count($funnels), 3, '::roles há os 3 funis novos');
foreach ($funnels as $f) {
    $rows = $q("SELECT role, kind, level, position FROM funnel_stages WHERE funnel_id = ? AND role IN ('exec', 'delivery') ORDER BY position, id", [(int)$f['id']]);
    t_eq(array_map(fn($r) => [$r['role'], $r['kind'], $r['level'], (int)$r['position']], $rows),
        [['exec', 'production', 'deal', 70], ['delivery', 'production', 'deal', 80]], "::roles {$f['service_code']}: Em execução e Entrega uma vez cada");
}

// sem kind novo, em toda a base
$kinds = array_column($q('SELECT DISTINCT kind FROM funnel_stages'), 'kind');
t_eq(array_values(array_diff($kinds, array_keys(STAGE_KINDS))), [], '::roles nenhum kind fora de STAGE_KINDS');

// os funis ativos não ganham exec nem delivery (D2)
t_eq((int)$q("SELECT COUNT(*) AS n FROM funnel_stages s JOIN funnels f ON f.id = s.funnel_id WHERE f.is_active = 1 AND s.role IN ('exec', 'delivery')")[0]['n'], 0,
    '::roles nenhuma etapa de um funil ativo tem role exec ou delivery');

// as sub-etapas são sempre production
$front = $q("SELECT kind, COUNT(*) AS n FROM funnel_stages WHERE level = 'front' GROUP BY kind");
t_eq(array_map(fn($r) => [$r['kind'], (int)$r['n']], $front), [['production', 42]], '::roles as 42 sub-etapas são todas kind production');
t_eq((int)$q("SELECT COUNT(*) AS n FROM funnel_stages WHERE level = 'front' AND kind = 'done'")[0]['n'], 0, '::roles nenhuma sub-etapa é done');

// a etapa done de cada funil continua a ser uma só (funnel_fixed_stage)
foreach ($q('SELECT f.id, f.name FROM funnels f ORDER BY f.id') as $f) {
    $n = (int)$q("SELECT COUNT(*) AS n FROM funnel_stages WHERE funnel_id = ? AND kind = 'done'", [(int)$f['id']])[0]['n'];
    t_eq($n, 1, "::roles o funil {$f['id']} tem uma só etapa done");
}

pipe2_tmpdb_drop($db);
t_done();
