<?php
/**
 * topper. admin — funis, etapas e movimentos dos negócios
 *
 * Fase comercial (aba Pipeline): X-Ray → [variáveis] → Kickoff.
 * Fase de produção (aba Negócios): Kickoff → [variáveis] → Concluído.
 * X-Ray, Kickoff e Concluído são fixas: não se apagam nem mudam de ordem.
 * Cada movimento fica em deal_history (origem, destino, quem, quando).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

const STAGE_KINDS = [
    'xray'       => 'X-Ray',
    'commercial' => 'Comercial',
    'kickoff'    => 'Kickoff',
    'production' => 'Produção',
    'done'       => 'Concluído',
];

/** Ordem das zonas: as variáveis comerciais ficam entre X-Ray e Kickoff, as de produção entre Kickoff e Concluído. */
const STAGE_ZONE = ['xray' => 1, 'commercial' => 2, 'kickoff' => 3, 'production' => 4, 'done' => 5];

const LOST_REASONS = ['Preço', 'Prazo', 'Escolheu outro fornecedor', 'Sem orçamento', 'Sem resposta', 'Projeto cancelado', 'Outro'];

function funnels_active(PDO $pdo): array {
    return $pdo->query('SELECT * FROM funnels WHERE is_active = 1 ORDER BY position, name')->fetchAll();
}

function funnel_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM funnels WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** As etapas de um funil pela ordem certa. */
function funnel_stages(PDO $pdo, int $funnelId): array {
    $st = $pdo->prepare('SELECT * FROM funnel_stages WHERE funnel_id = ? ORDER BY position, id');
    $st->execute([$funnelId]);
    $rows = $st->fetchAll();
    usort($rows, fn($a, $b) => [STAGE_ZONE[$a['kind']], (int)$a['position'], (int)$a['id']]
                           <=> [STAGE_ZONE[$b['kind']], (int)$b['position'], (int)$b['id']]);
    return $rows;
}

function stage_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM funnel_stages WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** As etapas da fase comercial (o que se vê no Pipeline). */
function commercial_stages(array $stages): array {
    return array_values(array_filter($stages, fn($s) => in_array($s['kind'], ['xray', 'commercial', 'kickoff'], true)));
}

function deal_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM deals WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** A fase do negócio que corresponde a um tipo de etapa. */
function phase_for_kind(string $kind): string {
    return match ($kind) {
        'xray', 'commercial' => 'pipeline',
        'kickoff', 'production' => 'production',
        'done' => 'done',
    };
}

/**
 * Move um negócio para outra etapa do mesmo funil. A fase acompanha a
 * etapa: chegar a Kickoff leva-o para Negócios, a Concluído para os
 * Concluídos. Devolve a mensagem para o utilizador.
 */
function deal_move(PDO $pdo, array $deal, int $stageId, string $force = ''): string {
    require_once __DIR__ . '/pipeline_flow.php';
    $to = stage_get($pdo, $stageId);
    if (!$to || (int)$to['funnel_id'] !== (int)$deal['funnel_id']) throw new RuntimeException('Etapa inválida.');
    if (!in_array($deal['phase'], ['pipeline', 'production', 'done'], true)) throw new RuntimeException('Este negócio não está ativo.');
    if ((int)$deal['stage_id'] === $stageId) return 'Sem alterações.';
    // Os gates (§19.1): para a frente só com os requisitos cumpridos; forçar só admins, com motivo.
    $forced = flow_gate_check($pdo, $deal, $to, $force);

    $phase = phase_for_kind($to['kind']);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE deals SET stage_id = ?, phase = ?, done_at = ' . ($phase === 'done' ? now_sql($pdo) : 'NULL') . ', last_touch = ? WHERE id = ?')
            ->execute([$stageId, $phase, today_lisbon(), $deal['id']]);
        // Fechado no Kickoff sem ano/mês: conta para o Financeiro no mês em que fechou.
        if ($phase === 'production' && $deal['year'] === null) {
            $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon'));
            $pdo->prepare('UPDATE deals SET year = ?, month = COALESCE(month, ?) WHERE id = ?')
                ->execute([(int)$now->format('Y'), (int)$now->format('n'), $deal['id']]);
        }
        deal_log($pdo, (int)$deal['id'], 'stage', [
            'from_funnel' => $deal['funnel_id'], 'from_stage' => $deal['stage_id'],
            'to_funnel'   => $deal['funnel_id'], 'to_stage'   => $stageId,
        ]);
        if ($forced !== '') deal_log($pdo, (int)$deal['id'], 'gate_force', ['from_stage' => $deal['stage_id'], 'to_stage' => $stageId, 'note' => $forced]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // As automações da etapa nova (§19.7): as tarefas ficam ligadas ao negócio e à etapa.
    flow_on_enter($pdo, (int)$deal['id'], $stageId);
    return match ($phase) {
        'production' => $to['kind'] === 'kickoff' && $deal['phase'] === 'pipeline'
            ? 'Negócio fechado! ' . $deal['code'] . ' passou para a aba Negócios.' : 'Etapa atualizada.',
        'done'       => $deal['code'] . ' concluído.',
        default      => 'Etapa atualizada.',
    };
}

/** Marca como perdido (só na fase comercial). Guarda motivo e etapa. */
function deal_mark_lost(PDO $pdo, array $deal, string $reason): void {
    if ($deal['phase'] !== 'pipeline') throw new RuntimeException('Só um negócio na fase comercial pode ser perdido.');
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') throw new RuntimeException('Indique o motivo.');
    $pdo->prepare('UPDATE deals SET phase = \'lost\', lost_reason = ?, lost_stage_id = stage_id, lost_at = ' . now_sql($pdo) . ' WHERE id = ?')
        ->execute([$reason, $deal['id']]);
    deal_log($pdo, (int)$deal['id'], 'lost', ['from_funnel' => $deal['funnel_id'], 'from_stage' => $deal['stage_id'], 'note' => $reason]);
}

/** Reabre um negócio perdido na etapa onde se perdeu (ou X-Ray, se essa etapa já não existir). */
function deal_reopen(PDO $pdo, array $deal): void {
    if ($deal['phase'] !== 'lost') throw new RuntimeException('Este negócio não está perdido.');
    $stage = $deal['lost_stage_id'] ? stage_get($pdo, (int)$deal['lost_stage_id']) : null;
    if (!$stage || (int)$stage['funnel_id'] !== (int)$deal['funnel_id'] || !in_array($stage['kind'], ['xray', 'commercial'], true)) {
        $stageId = funnel_fixed_stage($pdo, (int)$deal['funnel_id'], 'xray');
    } else {
        $stageId = (int)$stage['id'];
    }
    $pdo->prepare('UPDATE deals SET phase = \'pipeline\', stage_id = ?, lost_reason = \'\', lost_stage_id = NULL, lost_at = NULL, last_touch = ? WHERE id = ?')
        ->execute([$stageId, today_lisbon(), $deal['id']]);
    deal_log($pdo, (int)$deal['id'], 'reopen', ['to_funnel' => $deal['funnel_id'], 'to_stage' => $stageId]);
}

/**
 * Muda o negócio de funil. Mantém ID, dados e histórico; o funil de origem
 * (origin_funnel_id) nunca muda. X-Ray e Kickoff passam para a mesma etapa
 * no funil novo; de uma etapa variável, quem muda escolhe o destino.
 */
function deal_change_funnel(PDO $pdo, array $deal, int $funnelId, int $stageId = 0): void {
    if ((int)$deal['funnel_id'] === $funnelId) throw new RuntimeException('O negócio já está nesse funil.');
    $f = funnel_get($pdo, $funnelId);
    if (!$f || !(int)$f['is_active']) throw new RuntimeException('Funil inválido.');
    $cur = stage_get($pdo, (int)$deal['stage_id']);
    if ($cur && in_array($cur['kind'], ['xray', 'kickoff', 'done'], true)) {
        $stageId = (int)funnel_fixed_stage($pdo, $funnelId, $cur['kind']);
    } else {
        $to = $stageId ? stage_get($pdo, $stageId) : null;
        if (!$to || (int)$to['funnel_id'] !== $funnelId) throw new RuntimeException('Escolha a etapa de destino no funil novo.');
    }
    $to = stage_get($pdo, $stageId);
    $phase = $deal['phase'] === 'lost' ? 'lost' : phase_for_kind($to['kind']);
    $pdo->prepare('UPDATE deals SET funnel_id = ?, stage_id = ?, phase = ? WHERE id = ?')->execute([$funnelId, $stageId, $phase, $deal['id']]);
    deal_log($pdo, (int)$deal['id'], 'funnel', [
        'from_funnel' => $deal['funnel_id'], 'from_stage' => $deal['stage_id'],
        'to_funnel'   => $funnelId,          'to_stage'   => $stageId,
    ]);
    if ($phase !== 'lost') {
        require_once __DIR__ . '/pipeline_flow.php';
        flow_on_enter($pdo, (int)$deal['id'], $stageId);
    }
}

/** Cria um funil com as etapas fixas e, opcionalmente, as variáveis dadas [[name, kind], …]. */
function funnel_create(PDO $pdo, string $name, array $variable = [], string $madeFrom = ''): int {
    $pos = (int)$pdo->query('SELECT COALESCE(MAX(position),0) FROM funnels')->fetchColumn() + 1;
    $pdo->prepare('INSERT INTO funnels (name, is_base, made_from, position, created_by) VALUES (?, 0, ?, ?, ?)')
        ->execute([$name, mb_substr($madeFrom, 0, 255), $pos, current_user()['id'] ?? null]);
    $id = (int)$pdo->lastInsertId();
    $s = $pdo->prepare('INSERT INTO funnel_stages (funnel_id, name, kind, position) VALUES (?,?,?,?)');
    $s->execute([$id, 'X-Ray', 'xray', 10]);
    $s->execute([$id, 'Kickoff', 'kickoff', 50]);
    $s->execute([$id, 'Concluído', 'done', 90]);
    // A fase comercial é igual em todos os funis (§19.1): Proposta e Aguardar feedback com papel fixo.
    $r = db_has_column($pdo, 'funnel_stages', 'role') ? $pdo->prepare('INSERT INTO funnel_stages (funnel_id, name, kind, position, role) VALUES (?,?,?,?,?)') : null;
    if ($r) {
        $r->execute([$id, 'Proposta', 'commercial', 20, 'proposal']);
        $r->execute([$id, 'Aguardar feedback', 'commercial', 21, 'feedback']);
    }
    $n = ['commercial' => $r ? 2 : 0, 'production' => 0];
    foreach ($variable as [$sname, $kind]) {
        if (!isset($n[$kind])) continue;
        if ($r && $kind === 'commercial' && in_array(mb_strtolower(trim($sname)), ['proposta', 'aguardar feedback'], true)) continue;
        $s->execute([$id, $sname, $kind, ($kind === 'commercial' ? 20 : 60) + $n[$kind]++]);
    }
    return $id;
}

/**
 * As etapas variáveis de vários funis, juntas e sem repetir (pelo nome),
 * pela ordem em que aparecem — base para um funil de cross-selling.
 */
function funnels_combined_stages(PDO $pdo, array $funnelIds): array {
    $out = []; $seen = [];
    foreach ($funnelIds as $fid) {
        foreach (funnel_stages($pdo, (int)$fid) as $s) {
            if (!in_array($s['kind'], ['commercial', 'production'], true)) continue;
            $key = $s['kind'] . '|' . mb_strtolower(trim($s['name']));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = [$s['name'], $s['kind']];
        }
    }
    usort($out, fn($a, $b) => STAGE_ZONE[$a[1]] <=> STAGE_ZONE[$b[1]]);   // estável: mantém a ordem dentro de cada zona
    return $out;
}
