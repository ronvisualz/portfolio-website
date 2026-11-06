<?php
/**
 * Pipeline v2, fase 0 — o modelo das etapas depois do passo 43 (M1, parte da fase 0).
 *
 * Sem kind novo: «Em execução» e «Entrega e pagamento final» são kind 'production' com role 'exec' e 'delivery', e
 * só existem nos funis novos (nos ativos criam-se na conversão, D2). As sub-etapas são sempre kind 'production'
 * (nunca 'done', por causa de funnel_fixed_stage). Corre numa base privada do dump v42, migrada.
 *
 * Fase 1 (tarefa 3, D2 e D21): funnel_create cria as 7 etapas de negócio da v2 (as 6 comuns e Concluído, sem
 * sub-etapas, sem regras e sem as etapas variáveis de produção) só com a bandeira ligada ou com $v2 = true; sem ela,
 * as 5 de sempre. A bandeira só se grava numa transação desfeita. pipe2_stage_delete_block recusa apagar uma etapa com
 * frentes (deal_lines.stage_id) ou tarefas (tasks.stage_id, qualquer estado).
 *
 * Casos: ::roles ::funnel_create_six ::del_stage_guards
 *
 * Uso: php tests/pipe2_model_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
pipe2_lock($pdo);
require_once __DIR__ . '/../admin/inc/migrate_pipeline2.php';
require_once __DIR__ . '/../admin/inc/pipe2_fronts.php';

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

// ── ::funnel_create_six (D2) ────────────────────────────────
$c = '::funnel_create_six';
$stages = fn(int $fid) => array_map(fn($r) => [$r['name'], $r['kind'], (int)$r['position'], $r['role'], $r['level']],
    $q('SELECT name, kind, position, role, level FROM funnel_stages WHERE funnel_id = ? ORDER BY position, id', [$fid]));
$seven = [['X-Ray', 'xray', 10, '', 'deal'], ['Proposta', 'commercial', 20, 'proposal', 'deal'], ['Aguardar feedback', 'commercial', 21, 'feedback', 'deal'],
          ['Kickoff', 'kickoff', 50, '', 'deal'], ['Em execução', 'production', 70, 'exec', 'deal'],
          ['Entrega e pagamento final', 'production', 80, 'delivery', 'deal'], ['Concluído', 'done', 90, '', 'deal']];
$five = [['X-Ray', 'xray', 10, '', 'deal'], ['Proposta', 'commercial', 20, 'proposal', 'deal'], ['Aguardar feedback', 'commercial', 21, 'feedback', 'deal'],
         ['Kickoff', 'kickoff', 50, '', 'deal'], ['Concluído', 'done', 90, '', 'deal']];
$rulesOf = fn(int $fid) => (int)$q('SELECT COUNT(*) AS n FROM stage_automations a JOIN funnel_stages s ON s.id = a.stage_id WHERE s.funnel_id = ?', [$fid])[0]['n'];
$six = funnel_create($tp, 'P2T · seis', [], '', true);
t_eq($stages($six), $seven, "$c com \$v2 = true: as 7 etapas de negócio (nome, kind, posição, role, nível)");
t_eq($rulesOf($six), 0, "$c sem regras");
t_true(pipe2_funnel_is_v2($tp, $six), "$c o funil criado é v2 (D1)");
t_eq($stages(funnel_create($tp, 'P2T · cinco')), $five, "$c sem a bandeira: as 5 de antes");
t_eq($stages(funnel_create($tp, 'P2T · cinco falso', [], '', false)), $five, "$c com \$v2 = false: as 5 de antes");
$var = [['P2T · produção', 'production'], ['P2T · comercial', 'commercial']];
t_eq($stages(funnel_create($tp, 'P2T · seis var', $var, '', true)),
    array_merge(array_slice($seven, 0, 3), [['P2T · comercial', 'commercial', 22, '', 'deal']], array_slice($seven, 3)),
    "$c com \$v2 = true: ignora as variáveis de produção, mantém as comerciais");
t_eq($stages(funnel_create($tp, 'P2T · cinco var', $var)),
    array_merge(array_slice($five, 0, 3), [['P2T · comercial', 'commercial', 22, '', 'deal'], ['Kickoff', 'kickoff', 50, '', 'deal'],
                ['P2T · produção', 'production', 60, '', 'deal'], ['Concluído', 'done', 90, '', 'deal']]),
    "$c sem a bandeira: as variáveis de produção ficam como antes");
t_true(!pipe2_on($tp), "$c a bandeira está desligada antes da transação");
$tp->beginTransaction();
$tp->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)')->execute(['pipeline_v2_at', date('Y-m-d H:i:s')]);
$flag = funnel_create($tp, 'P2T · bandeira');
$withFlag = $stages($flag);
$tp->rollBack();
t_eq($withFlag, $seven, "$c com pipeline_v2_at (numa transação desfeita): 7");
t_true(!pipe2_on($tp), "$c a bandeira ficou desligada (rollBack)");
t_eq((int)$q("SELECT COUNT(*) AS n FROM funnels WHERE name = 'P2T · bandeira'")[0]['n'], 0, "$c o funil da transação desfeita não ficou");

// ── ::del_stage_guards (D21) ────────────────────────────────
$c = '::del_stage_guards';
$gf = funnel_create($tp, 'P2T · guarda');
$ins = $tp->prepare("INSERT INTO funnel_stages (funnel_id, name, kind, position) VALUES (?, ?, 'production', ?)");
foreach ([['P2T · livre', 60], ['P2T · frente', 61], ['P2T · tarefa', 62], ['P2T · tarefa aberta', 63]] as [$n, $p]) $ins->execute([$gf, $n, $p]);
$sid = fn(string $n) => (int)$q('SELECT id FROM funnel_stages WHERE funnel_id = ? AND name = ?', [$gf, $n])[0]['id'];
$gd = pipe2_deal($tp, ['phase' => 'pipeline', 'funnel_id' => $gf]);
pipe2_front($tp, $gd, $gf, ['stage_id' => $sid('P2T · frente')]);
pipe2_task($tp, $gd, ['stage_id' => $sid('P2T · tarefa'), 'status' => 'done']);
pipe2_task($tp, $gd, ['stage_id' => $sid('P2T · tarefa aberta')]);
$msg = 'Há frentes ou tarefas nesta etapa: mova-as ou apague-as primeiro.';
t_eq(pipe2_stage_delete_block($tp, $sid('P2T · livre')), '', "$c etapa sem nada → ''");
t_eq(pipe2_stage_delete_block($tp, $sid('P2T · frente')), $msg, "$c com uma frente → recusa");
t_eq(pipe2_stage_delete_block($tp, $sid('P2T · tarefa')), $msg, "$c com uma tarefa feita → recusa");
t_eq(pipe2_stage_delete_block($tp, $sid('P2T · tarefa aberta')), $msg, "$c com uma tarefa por fazer → recusa");

pipe2_tmpdb_drop($db);
t_done();
