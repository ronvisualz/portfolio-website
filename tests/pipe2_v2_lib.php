<?php
/**
 * Base de testes da fase 1 do Pipeline v2 (incluir depois de tests/boot.php e tests/pipe2_lib.php).
 *
 * - p2v_db: uma base privada do dump da 42, migrada num subprocesso até à SCHEMA_VERSION, que passa a ser a ligação da
 *   plataforma (pipe2_use). Os funis, negócios e regras de cada teste criam-se TODOS antes da primeira leitura de
 *   flow_order/stage_match/mx_stages (caches estáticas da v1).
 * - Funis v2 de teste: p2v_funnel (base privada, com o service_code do serviço), p2v_bench_funnel (bancada, sem
 *   service_code e só da v2), p2v_world (os 7). Negócios v2 com frentes (p2v_deal), passos (p2v_steps), tarefas
 *   (p2v_task), regras (p2v_rule, p2v_closing_rules).
 * - Sessão no próprio processo (p2v_as: a my_grid() e a me_row() guardam o primeiro utilizador numa cache estática;
 *   para comparar utilizadores, um processo por utilizador: p2v_probe).
 * - Subprocessos: p2v_probe (tests/pipe2_probe.php, as chamadas de leitura com a sessão de um utilizador) e as corridas
 *   (p2v_race lança vários scripts que esperam o mesmo sinal de partida, p2v_go_wait é o lado de quem espera).
 * - Assinaturas das linhas (p2v_sig) e consultas (p2v_rows, p2v_col).
 *
 * Nunca inclui um admin/inc/pipe2_*.php (as tarefas que os criam testam-nos; esta base corre antes deles existirem).
 */

if (!defined('TOPPER_ADMIN') || !function_exists('pipe2_tmpdb_create')) {
    fwrite(STDERR, "Inclua tests/boot.php e tests/pipe2_lib.php antes de tests/pipe2_v2_lib.php.\n");
    exit(1);
}

require_once __DIR__ . '/../admin/inc/migrate_pipeline2.php';   // PIPE2_SEED_FRONTS, PIPE2_SEED_DEPTS
require_once __DIR__ . '/../admin/inc/migrate_pipeline.php';    // PIPE_RULES_DELIVERY, PIPE_RULES_DONE, migrate_pipeline_rule
require_once __DIR__ . '/../admin/inc/tasks.php';               // task_by_id

/** A regra das chamadas do probe (lista fechada; as pipe2_* só se forem de admin/inc). */
const P2V_PROBE_FN = '/^(can|is_admin|current_user|record_reach|reach_sql|flow_can_edit|flow_can_bill|flow_is_commercial|my_alerts|notifications|pipe2_[a-z0-9_]+)$/';
const P2V_RACE_READY_S = 15;   // p2v_race: espera pelos .ready
const P2V_GO_WAIT_S = 10;      // p2v_go_wait: espera pelo sinal
const P2V_RACE_KILL_S = 300;   // p2v_race: uma corrida que não acaba é terminada ao fim deste tempo

// ── base privada ────────────────────────────────────────────

/** [$db, $tp]: base privada do dump 42, migrada num subprocesso até à SCHEMA_VERSION (senão FALHA e exit 1); pipe2_use($tp). */
function p2v_db(): array {
    $db = pipe2_tmpdb_create(pipe2_base_dump());
    $r = pipe2_sub('pipe2_v1_snap.php', ['--migrate', '--db=' . $db]);
    if ($r['code'] !== 0 || ($r['json']['to'] ?? null) !== SCHEMA_VERSION) {
        fwrite(STDERR, 'FALHA: a base privada ' . $db . ' não migrou até à ' . SCHEMA_VERSION . ' (' . trim($r['out'] . ' ' . $r['err']) . ")\n");
        exit(1);
    }
    $tp = pipe2_tmpdb_pdo($db);
    pipe2_use($tp);
    return [$db, $tp];
}

// ── consultas ───────────────────────────────────────────────

function p2v_rows(PDO $pdo, string $sql, array $a = []): array {
    $st = $pdo->prepare($sql);
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** A 1.ª coluna da 1.ª linha (false se não houver). */
function p2v_col(PDO $pdo, string $sql, array $a = []) {
    $st = $pdo->prepare($sql);
    $st->execute($a);
    $v = $st->fetchColumn();
    $st->closeCursor();
    return $v;
}

/**
 * Assinatura das linhas: tabela => [COUNT(*), SUM(CRC32(CONCAT_WS('|', colunas)))] (NULL distinto de ''). $tables é
 * uma lista de tabelas (todas as colunas) ou tabela => [colunas] (só essas, ex.: as da v1 antes de um passo).
 */
function p2v_sig(PDO $pdo, array $tables, string $where = '1=1'): array {
    $out = [];
    foreach ($tables as $k => $v) {
        [$t, $cols] = is_int($k) ? [(string)$v, null] : [(string)$k, (array)$v];
        if (!preg_match('/^\w+$/', $t)) throw new InvalidArgumentException('p2v_sig: tabela inválida ' . $t);
        $cols ??= array_column(p2v_rows($pdo, 'SELECT COLUMN_NAME AS c FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$t]), 'c');
        if (!$cols) throw new InvalidArgumentException('p2v_sig: a tabela ' . $t . ' não existe nesta base.');
        $parts = array_map(fn($c) => "COALESCE(CAST(`" . str_replace('`', '', (string)$c) . "` AS CHAR), '<null>')", $cols);
        $r = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|', " . implode(', ', $parts) . "))), 0) AS crc FROM `$t` WHERE $where")->fetch(PDO::FETCH_ASSOC);
        $out[$t] = [(int)$r['n'], (string)$r['crc']];
    }
    return $out;
}

// ── funis ───────────────────────────────────────────────────

/** Recusa opções que o helper não conhece. */
function p2v_opts(string $fn, array $o, array $known): void {
    foreach (array_keys($o) as $k) {
        if (!in_array($k, $known, true)) throw new InvalidArgumentException("{$fn}: opção desconhecida «{$k}».");
    }
}

/** Acrescenta Em execução (70, exec) e Entrega e pagamento final (80, delivery) às etapas do negócio de um funil. */
function p2v_add_exec_delivery(PDO $pdo, int $fid): void {
    $ins = $pdo->prepare("INSERT INTO funnel_stages (funnel_id, name, kind, position, role, level) VALUES (?, ?, 'production', ?, ?, 'deal')");
    $ins->execute([$fid, 'Em execução', 70, 'exec']);
    $ins->execute([$fid, 'Entrega e pagamento final', 80, 'delivery']);
}

/**
 * Um funil v2 de teste «P2T · <code>» numa base privada: pipe2_funnel (X-Ray, Proposta, Aguardar feedback, Kickoff,
 * Concluído) + Em execução (70, exec) + Entrega e pagamento final (80, delivery) + as 6 sub-etapas de
 * PIPE2_SEED_FRONTS[$code] (level 'front', kind 'production', 110…160; Revisões e Pronto com role revisions/ready e as
 * rondas incluídas). Opções: 'code' (true: service_code = $code — lança InvalidArgumentException fora de uma base
 * privada), 'active' (1), 'plan_days' (int para as 6, ou [posição 0–5 => dias]), 'units' ([posição => rótulo]),
 * 'dept' (?int; por defeito o departamento de PIPE2_SEED_DEPTS[$code] pelo nome, NULL se não houver), 'auto' (1:
 * funnels.auto_advance).
 */
function p2v_funnel(PDO $pdo, string $code, array $o = []): int {
    p2v_opts('p2v_funnel', $o, ['code', 'active', 'plan_days', 'units', 'dept', 'auto']);
    if (!isset(PIPE2_SEED_FRONTS[$code])) throw new InvalidArgumentException("p2v_funnel: serviço desconhecido «{$code}».");
    $withCode = (bool)($o['code'] ?? true);
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($withCode && !preg_match(PIPE2_TMPDB_RE, $db)) {
        throw new InvalidArgumentException("p2v_funnel: um funil com service_code só numa base privada (esta é «{$db}»; na bancada, p2v_bench_funnel).");
    }
    if (array_key_exists('dept', $o)) {
        $dept = $o['dept'] === null ? null : (int)$o['dept'];
    } else {
        $d = p2v_col($pdo, 'SELECT id FROM departments WHERE name = ? ORDER BY id LIMIT 1', [PIPE2_SEED_DEPTS[$code] ?? '']);
        $dept = $d === false ? null : (int)$d;
    }
    $fid = pipe2_funnel($pdo, $code, (bool)($o['active'] ?? 1));
    $pdo->prepare('UPDATE funnels SET service_code = ?, department_id = ?, auto_advance = ? WHERE id = ?')
        ->execute([$withCode ? $code : '', $dept, (int)($o['auto'] ?? 1), $fid]);
    p2v_add_exec_delivery($pdo, $fid);
    [$names, $rounds] = PIPE2_SEED_FRONTS[$code];
    $days = $o['plan_days'] ?? null;
    $units = (array)($o['units'] ?? []);
    $ins = $pdo->prepare("INSERT INTO funnel_stages (funnel_id, name, kind, position, role, rounds_included, plan_days, unit_label, level)
                          VALUES (?, ?, 'production', ?, ?, ?, ?, ?, 'front')");
    foreach ($names as $i => $name) {
        $role = $i === 4 ? 'revisions' : ($i === 5 ? 'ready' : '');
        $pd = is_array($days) ? ($days[$i] ?? null) : $days;
        $ins->execute([$fid, $name, 110 + 10 * $i, $role, $i === 4 ? $rounds : null, $pd === null ? null : (int)$pd, (string)($units[$i] ?? '')]);
    }
    return $fid;
}

/**
 * Um funil v2 de teste na bancada (ou noutra base): «P2T · <rótulo>» sem service_code, v2_only 1 (escondido da v1),
 * is_base 0, inativo, com Em execução e Entrega e $n sub-etapas «P2T · s1»… (as 2 últimas revisions e ready). A
 * limpeza (pipe2_cleanup) apaga-o com as etapas.
 */
function p2v_bench_funnel(PDO $pdo, string $label, int $n = 4): int {
    if ($n < 2) throw new InvalidArgumentException('p2v_bench_funnel: pelo menos 2 sub-etapas (Revisões e Pronto).');
    $fid = pipe2_funnel($pdo, $label);
    $pdo->prepare("UPDATE funnels SET v2_only = 1, service_code = '' WHERE id = ?")->execute([$fid]);
    p2v_add_exec_delivery($pdo, $fid);
    $ins = $pdo->prepare("INSERT INTO funnel_stages (funnel_id, name, kind, position, role, rounds_included, level) VALUES (?, ?, 'production', ?, ?, ?, 'front')");
    for ($i = 0; $i < $n; $i++) {
        $role = $i === $n - 2 ? 'revisions' : ($i === $n - 1 ? 'ready' : '');
        $ins->execute([$fid, PIPE2_FUNNEL_PREFIX . 's' . ($i + 1), 110 + 10 * $i, $role, $role === 'revisions' ? 1 : null]);
    }
    return $fid;
}

/**
 * As etapas vivas de um funil: 'xray', 'proposal', 'feedback', 'kickoff', 'exec', 'delivery', 'done' => id (ou null);
 * 'fronts' => [ids das sub-etapas pela ordem]; 'revisions', 'ready' => id da sub-etapa; 'name' => [nome => id].
 */
function p2v_stages(PDO $pdo, int $funnelId): array {
    $out = ['xray' => null, 'proposal' => null, 'feedback' => null, 'kickoff' => null, 'exec' => null, 'delivery' => null, 'done' => null,
            'fronts' => [], 'revisions' => null, 'ready' => null, 'name' => []];
    foreach (p2v_rows($pdo, 'SELECT id, name, kind, role, level FROM funnel_stages WHERE funnel_id = ? AND retired_at IS NULL ORDER BY position, id', [$funnelId]) as $s) {
        $id = (int)$s['id'];
        $out['name'][(string)$s['name']] ??= $id;
        if ($s['level'] === 'front') {
            $out['fronts'][] = $id;
            if (in_array($s['role'], ['revisions', 'ready'], true)) $out[$s['role']] ??= $id;
            continue;
        }
        if (in_array($s['kind'], ['xray', 'kickoff', 'done'], true)) $out[$s['kind']] ??= $id;
        if (in_array($s['role'], ['proposal', 'feedback', 'exec', 'delivery'], true)) $out[$s['role']] ??= $id;
    }
    return $out;
}

/** Os funis v2 de teste: código => funil (p2v_funnel com as opções por defeito). */
function p2v_world(PDO $pdo, array $codes = ['vfx', '3d', 'md', 'cmp', 'ed', 'adv', 'cam']): array {
    $out = [];
    foreach ($codes as $code) $out[$code] = p2v_funnel($pdo, $code);
    return $out;
}

// ── negócios, frentes, passos ───────────────────────────────

/** O último dia do mês de início + $months − 1 (D15). */
function p2v_billing_end(string $startYm, int $months): string {
    return date('Y-m-t', strtotime($startYm . '-01 +' . max(0, $months - 1) . ' months'));
}

/**
 * Um negócio v2 de teste com uma frente por código (a principal = $codes[0], no funil do negócio):
 * ['id' => negócio, 'lines' => [código => frente]]. Opções:
 *   'at'            xray | proposal | feedback | kickoff (por defeito) | exec | delivery | done — a etapa do negócio
 *                   (fase pipeline / production / done);
 *   'owner' (99), 'exec' (null: deals.exec_owner_id), 'delivery_date', 'budget', 'value_estimate', 'name';
 *   'status'        das frentes (texto, ou [código => estado]): planned antes do Kickoff, queued no Kickoff, running em
 *                   exec/delivery, ready no Concluído;
 *   'front_at'      [código => sub-etapa]; sem ele, running/ongoing ficam na 1.ª sub-etapa, ready na Pronto, os outros
 *                   estados sem sub-etapa;
 *   'billing'       [código => [mensalidade, meses]]: frente avença (e deals.billing = 'monthly'); começada, fica com o
 *                   ciclo do mês de hoje e o fim do período (billing_end);
 *   'lines'         false = sem deal_lines.
 * As frentes começadas (running, depwait, ready, ongoing) levam started_at; as que estão numa sub-etapa, stage_entered_at.
 */
function p2v_deal(PDO $pdo, array $world, array $codes, array $o = []): array {
    p2v_opts('p2v_deal', $o, ['at', 'owner', 'exec', 'delivery_date', 'budget', 'value_estimate', 'name', 'status', 'front_at', 'billing', 'lines']);
    if (!$codes) throw new InvalidArgumentException('p2v_deal: pelo menos um serviço.');
    foreach ($codes as $c) if (!isset($world[$c])) throw new InvalidArgumentException("p2v_deal: o mundo não tem o serviço «{$c}».");
    $at = (string)($o['at'] ?? 'kickoff');
    $phase = match ($at) {
        'xray', 'proposal', 'feedback' => 'pipeline',
        'kickoff', 'exec', 'delivery'  => 'production',
        'done'                         => 'done',
        default => throw new InvalidArgumentException("p2v_deal: etapa desconhecida «{$at}»."),
    };
    $mainStages = p2v_stages($pdo, (int)$world[$codes[0]]);
    if ($mainStages[$at] === null) throw new InvalidArgumentException("p2v_deal: o funil de {$codes[0]} não tem a etapa «{$at}».");
    $billing = (array)($o['billing'] ?? []);
    $deal = ['phase' => $phase, 'funnel_id' => (int)$world[$codes[0]], 'stage_id' => $mainStages[$at], 'owner_id' => $o['owner'] ?? 99,
             'exec_owner_id' => $o['exec'] ?? null];
    foreach (['delivery_date', 'budget', 'value_estimate', 'name'] as $k) if (array_key_exists($k, $o)) $deal[$k] = $o[$k];
    if ($billing) $deal['billing'] = 'monthly';
    $dealId = pipe2_deal($pdo, $deal);
    if (($o['lines'] ?? true) === false) return ['id' => $dealId, 'lines' => []];

    $defStatus = match ($at) { 'kickoff' => 'queued', 'exec', 'delivery' => 'running', 'done' => 'ready', default => 'planned' };
    $now = date('Y-m-d H:i:s');
    $lines = [];
    foreach (array_values($codes) as $i => $code) {
        $fid = (int)$world[$code];
        $status = is_array($o['status'] ?? null) ? (string)($o['status'][$code] ?? $defStatus) : (string)($o['status'] ?? $defStatus);
        $fs = p2v_stages($pdo, $fid);
        $stage = $o['front_at'][$code] ?? match ($status) {
            'running', 'ongoing' => $fs['fronts'][0] ?? null,
            'ready'              => $fs['ready'],
            default              => null,
        };
        $line = ['is_main' => $i === 0 ? 1 : 0, 'position' => $i, 'status' => $status, 'stage_id' => $stage];
        $started = in_array($status, ['running', 'depwait', 'ready', 'ongoing'], true);
        if ($started) $line['started_at'] = $now;
        if (isset($billing[$code])) {
            [$fee, $months] = array_values((array)$billing[$code]) + [0, 1];
            $line += ['billing' => 'monthly', 'monthly_fee' => $fee, 'fee_months' => (int)$months];
            if ($started) $line += ['cycle_ym' => date('Y-m'), 'billing_end' => p2v_billing_end(date('Y-m'), (int)$months)];
        }
        $lines[$code] = pipe2_front($pdo, $dealId, $fid, $line);
    }
    return ['id' => $dealId, 'lines' => $lines];
}

/** UPDATE direto de deal_lines (preparar o estado de uma frente). Refresca a cache do crosselling. */
function p2v_front(PDO $pdo, int $lineId, array $set): void {
    if (!$set) return;
    $cols = array_keys($set);
    foreach ($cols as $c) if (!preg_match('/^[a-z_]+$/', (string)$c)) throw new InvalidArgumentException('p2v_front: coluna inválida ' . $c);
    $pdo->prepare('UPDATE deal_lines SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')
        ->execute(array_merge(array_map(fn($v) => is_bool($v) ? (int)$v : $v, array_values($set)), [$lineId]));
    $GLOBALS['XSELL_FRESH'] = true;
}

/**
 * As linhas de deal_line_steps de uma frente, uma por sub-etapa do funil dela, no ciclo atual da frente. Opções:
 * 'plan_days' (int para todas, ou [sub-etapa => dias]), 'hold' ([sub-etapa => motivo]: auto_hold_at = agora),
 * 'units' ([sub-etapa => [previstas, feitas]]), 'start' (started_at da linha da sub-etapa onde a frente está; por
 * defeito o stage_entered_at da frente, senão agora). As outras linhas ficam sem started_at nem done_at.
 * Devolve [sub-etapa => id].
 */
function p2v_steps(PDO $pdo, int $lineId, array $o = []): array {
    p2v_opts('p2v_steps', $o, ['plan_days', 'start', 'hold', 'units']);
    $line = p2v_rows($pdo, 'SELECT * FROM deal_lines WHERE id = ?', [$lineId])[0] ?? null;
    if (!$line) throw new InvalidArgumentException("p2v_steps: a frente {$lineId} não existe.");
    $days = $o['plan_days'] ?? null;
    $hold = (array)($o['hold'] ?? []);
    $units = (array)($o['units'] ?? []);
    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare('INSERT INTO deal_line_steps (deal_id, line_id, stage_id, cycle_ym, plan_days, units_plan, units_done, started_at, auto_hold_at, auto_hold_why, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $out = [];
    foreach (p2v_stages($pdo, (int)$line['funnel_id'])['fronts'] as $sid) {
        $pd = is_array($days) ? ($days[$sid] ?? null) : $days;
        [$up, $ud] = isset($units[$sid]) ? array_values((array)$units[$sid]) + [null, 0] : [null, 0];
        $started = (int)($line['stage_id'] ?? 0) === $sid ? (string)($o['start'] ?? $line['stage_entered_at'] ?? $now) : null;
        $ins->execute([(int)$line['deal_id'], $lineId, $sid, (string)$line['cycle_ym'], $pd === null ? null : (int)$pd, $up === null ? null : (int)$up, (int)$ud,
                       $started, isset($hold[$sid]) ? $now : null, (string)($hold[$sid] ?? ''), $now]);
        $out[$sid] = (int)$pdo->lastInsertId();
    }
    return $out;
}

/** Uma tarefa de teste (pipe2_task, com as colunas v2: line_id, stage_id, is_gate, cycle_ym, gate_stage_id, dedup_key, …). */
function p2v_task(PDO $pdo, int $dealId, array $o = []): int {
    return pipe2_task($pdo, $dealId, $o);
}

/**
 * Uma regra em stage_automations: title 'P2T · regra', trig 'f_enter', kind 'task', assign_to 'execution',
 * due_mode 'none', is_gate 1, is_active 1, action 'task', trig_n NULL, doc_kind '' ($o sobrepõe qualquer coluna; o
 * título fica como foi dado). A limpeza apaga-a.
 */
function p2v_rule(PDO $pdo, int $stageId, array $o = []): int {
    foreach (array_keys($o) as $k) if (!preg_match('/^[a-z_]+$/', (string)$k)) throw new InvalidArgumentException('p2v_rule: coluna inválida ' . $k);
    $pos = (int)p2v_col($pdo, 'SELECT COALESCE(MAX(position), 0) FROM stage_automations WHERE stage_id = ?', [$stageId]) + 10;
    $row = array_merge([
        'stage_id' => $stageId, 'title' => PIPE2_FUNNEL_PREFIX . 'regra', 'description' => '', 'trig' => 'f_enter', 'kind' => 'task',
        'assign_to' => 'execution', 'due_mode' => 'none', 'due_unit' => 'wd', 'due_days' => null, 'is_gate' => 1, 'is_active' => 1,
        'action' => 'task', 'trig_n' => null, 'doc_kind' => '', 'repeat_rule' => '', 'position' => $pos, 'updated_at' => date('Y-m-d H:i:s'),
    ], $o);
    $id = pipe2_insert($pdo, 'stage_automations', $row);
    pipe2_reg($pdo, 'stage_automations', $id);
    return $id;
}

/**
 * As regras de fecho na Entrega: as 4 de PIPE_RULES_DELIVERY e «Validar pagamento final · {ID}» (obrigatória, pay),
 * pelo migrate_pipeline_rule, como na semente da v1. Devolve os ids pela ordem.
 */
function p2v_closing_rules(PDO $pdo, int $deliveryStageId): array {
    $ids = [];
    foreach (array_merge(PIPE_RULES_DELIVERY, [PIPE_RULES_DONE[0]]) as $r) {
        $p = migrate_pipeline_rule($r);
        $ids[] = p2v_rule($pdo, $deliveryStageId, [
            'title' => $p['title'], 'kind' => $p['kind'], 'trig' => $p['trig'], 'assign_to' => $p['assign_to'], 'due_mode' => $p['due_mode'],
            'due_unit' => $p['due_unit'], 'due_days' => $p['due_days'], 'is_gate' => $p['is_gate'], 'repeat_rule' => $p['repeat_rule'], 'doc_kind' => $p['doc_kind'],
        ]);
    }
    return $ids;
}

// ── sessão ──────────────────────────────────────────────────

/**
 * $_SESSION['user'] = os campos que o login grava (session_user_fields) do utilizador $uid; null = sem sessão. A
 * my_grid() guarda numa cache estática o primeiro utilizador que vê: para comparar permissões, um processo por
 * utilizador (p2v_probe).
 */
function p2v_as(?int $uid, ?PDO $pdo = null): void {
    if ($uid === null) {
        unset($_SESSION['user']);
        return;
    }
    $pdo ??= $GLOBALS['pdo'];
    $row = p2v_rows($pdo, 'SELECT * FROM users WHERE id = ?', [$uid])[0] ?? null;
    if (!$row) throw new InvalidArgumentException("p2v_as: o utilizador {$uid} não existe.");
    $_SESSION['user'] = session_user_fields($row);
}

// ── probe ───────────────────────────────────────────────────

/**
 * Os argumentos de uma chamada do probe: {"deal": id} → deal_get, {"line": id} → a frente com funnel_name,
 * {"task": id} → task_by_id (null se não existir); e a ligação à frente quando o 1.º parâmetro da função é PDO.
 */
function p2v_probe_args(PDO $pdo, string $fn, array $args): array {
    if (!function_exists($fn)) throw new InvalidArgumentException("p2v_probe_args: a função «{$fn}» não está definida.");
    $out = [];
    foreach (array_values($args) as $a) {
        if (is_array($a) && count($a) === 1 && in_array(array_key_first($a), ['deal', 'line', 'task'], true) && is_int($a[array_key_first($a)])) {
            $id = (int)reset($a);
            $a = match (array_key_first($a)) {
                'deal' => deal_get($pdo, $id),
                'task' => task_by_id($pdo, $id),
                'line' => p2v_rows($pdo, 'SELECT l.*, f.name AS funnel_name, f.xray_kind, f.service_code FROM deal_lines l JOIN funnels f ON f.id = l.funnel_id WHERE l.id = ?', [$id])[0] ?? null,
            };
        }
        $out[] = $a;
    }
    $params = (new ReflectionFunction($fn))->getParameters();
    $t = $params ? $params[0]->getType() : null;
    if ($t instanceof ReflectionNamedType && $t->getName() === 'PDO') array_unshift($out, $pdo);
    return $out;
}

/**
 * As chamadas $calls ([['fn' => …, 'args' => […]], …]) noutro processo (tests/pipe2_probe.php), na base privada $db,
 * com a sessão do utilizador $uid (0 = sem sessão). Devolve os resultados pela ordem; num erro (função fora da lista,
 * indefinida, exceção…), ['error' => mensagem, 'code' => código de saída, 'err' => stderr].
 */
function p2v_probe(string $db, int $uid, array $calls): array {
    $f = tempnam(sys_get_temp_dir(), 'p2c');
    file_put_contents($f, json_encode(array_values($calls), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    try {
        $r = pipe2_sub('pipe2_probe.php', ['--db=' . $db, '--uid=' . $uid, '--calls=' . $f]);
    } finally {
        @unlink($f);
    }
    if ($r['code'] === 0 && is_array($r['json']) && array_key_exists('results', $r['json'])) return $r['json']['results'];
    return ['error' => (string)($r['json']['error'] ?? trim($r['out']) ?: '(sem saída)'), 'code' => $r['code'], 'err' => $r['err']];
}

// ── corridas ────────────────────────────────────────────────

/**
 * Para os scripts das corridas: com --go=F em $argv, escreve «F.ready.<pid>» e espera (até 10 s, em passos de 10 ms)
 * que F exista; sem F a tempo, «sem sinal de partida» no stderr e exit(2). Sem --go não faz nada.
 */
function p2v_go_wait(array $argv): void {
    $go = null;
    foreach ($argv as $a) if (preg_match('/^--go=(.+)$/', (string)$a, $m)) $go = $m[1];   // o nome do script nunca começa por --go=
    if ($go === null) return;
    file_put_contents($go . '.ready.' . getmypid(), (string)microtime(true));
    $until = microtime(true) + P2V_GO_WAIT_S;
    while (microtime(true) < $until) {
        clearstatcache(true, $go);
        if (file_exists($go)) return;
        usleep(10000);
    }
    fwrite(STDERR, "sem sinal de partida\n");
    exit(2);
}

/**
 * Lança as corridas [[script, args], …] (scripts de tests/) ao mesmo tempo, cada uma com --go=F (F único no
 * sys_get_temp_dir()); espera, até 15 s, que cada corrida ainda viva tenha escrito «F.ready.<pid>» (uma que já saiu
 * deixa de contar); cria F (também no fim do prazo, para ninguém ficar à espera) e espera que todas acabem. Devolve,
 * pela ordem, ['code', 'out', 'err', 'json', 'timeout' (a corrida estava viva sem .ready no fim do prazo), 'ready'
 * (o .ready dela existia quando F foi criado), 'ready_mtime', 'go_mtime', 'go' (o caminho de F)]. Apaga F e os .ready.
 */
function p2v_race(array $runs): array {
    static $n = 0;
    $go = rtrim(sys_get_temp_dir(), '/') . '/p2go_' . getmypid() . '_' . (++$n) . '_' . bin2hex(random_bytes(3));
    $procs = [];
    foreach (array_values($runs) as $i => $run) {
        [$script, $args] = $run + [1 => []];
        $path = __DIR__ . '/' . $script;
        if (!preg_match('/^[\w\-]+\.php$/', (string)$script) || !is_file($path)) throw new InvalidArgumentException('Script de teste inválido: ' . $script);
        $outF = tempnam(sys_get_temp_dir(), 'p2o');
        $errF = tempnam(sys_get_temp_dir(), 'p2e');
        $cmd = array_merge([PHP_BINARY, $path], array_map('strval', $args), ['--go=' . $go]);
        $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outF, 'w'], 2 => ['file', $errF, 'w']], $pipes, dirname(__DIR__));
        if (!is_resource($p)) throw new RuntimeException('Não foi possível lançar ' . $script);
        $procs[$i] = ['p' => $p, 'out' => $outF, 'err' => $errF, 'pid' => (int)proc_get_status($p)['pid'], 'exit' => null];
    }
    // O código de saída só vem na primeira leitura de proc_get_status que vê o processo parado (o proc_close dá -1).
    $poll = function (array &$pr): bool {
        if ($pr['exit'] !== null) return false;
        $s = proc_get_status($pr['p']);
        if ($s['running']) return true;
        $pr['exit'] = $s['signaled'] ? 128 + (int)$s['termsig'] : (int)$s['exitcode'];
        return false;
    };
    $readyOf = fn(array $pr): string => $go . '.ready.' . $pr['pid'];
    $deadline = microtime(true) + P2V_RACE_READY_S;
    while (true) {
        clearstatcache();
        $waiting = 0;
        foreach ($procs as &$pr) if (!file_exists($readyOf($pr)) && $poll($pr)) $waiting++;
        unset($pr);
        if ($waiting === 0 || microtime(true) >= $deadline) break;
        usleep(10000);
    }
    clearstatcache();
    $state = [];
    foreach ($procs as $i => $pr) {
        $has = file_exists($readyOf($pr));
        $state[$i] = ['ready' => $has, 'timeout' => !$has && $poll($procs[$i]), 'ready_mtime' => $has ? filemtime($readyOf($pr)) : null];
    }
    touch($go);
    clearstatcache();
    $goMtime = filemtime($go);
    $kill = microtime(true) + P2V_RACE_KILL_S;
    $res = [];
    foreach ($procs as $i => &$pr) {
        while ($poll($pr)) {
            if (microtime(true) >= $kill) {
                proc_terminate($pr['p'], 9);
                usleep(100000);
                $poll($pr);
                if ($pr['exit'] === null) $pr['exit'] = -1;
                file_put_contents($pr['err'], "\n[p2v_race] terminada ao fim de " . P2V_RACE_KILL_S . " s\n", FILE_APPEND);
                break;
            }
            usleep(10000);
        }
        proc_close($pr['p']);
        $out = (string)file_get_contents($pr['out']);
        $err = (string)file_get_contents($pr['err']);
        @unlink($pr['out']);
        @unlink($pr['err']);
        $res[$i] = ['code' => (int)$pr['exit'], 'out' => $out, 'err' => $err, 'json' => json_decode($out, true)] + $state[$i] + ['go_mtime' => $goMtime, 'go' => $go];
    }
    unset($pr);
    foreach (glob($go . '*') ?: [] as $f) @unlink($f);
    return $res;
}
