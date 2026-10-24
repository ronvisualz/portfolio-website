<?php
/**
 * topper. admin — a camada de métricas (§29.11): uma só biblioteca de cálculo para os Relatórios, o
 * Dashboard e os alertas, para os números baterem sempre certo.
 *
 *   etapas       Lead → Abordada → X-Ray → Proposta → Aguardar feedback → Kickoff (+ perdido em qualquer
 *                etapa). Cada passagem fica em stage_events (deal_log chama metrics_on_log); o que já existia
 *                foi reconstruído uma vez do deal_history e das interações (source = 'rebuilt').
 *   primeira     do registo da lead até à primeira interação de saída (chamada, email, WhatsApp, reunião),
 *   resposta     em horas úteis (Definições › Geral › Horário).
 *   registos     metrics_deals() lê os negócios e leads uma vez por pedido, com as datas a que chegaram a cada
 *                etapa; cada relatório filtra e conta em PHP (centenas a poucos milhares de registos).
 *   fotografia   metrics_snapshot() guarda todas as noites as métricas principais em metrics_daily.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/leads.php';

/** As etapas do funil comercial, por ordem. */
const MX_STEPS = ['lead' => 'Lead', 'abordada' => 'Abordada', 'xray' => 'X-Ray', 'proposta' => 'Proposta', 'feedback' => 'Aguardar feedback', 'kickoff' => 'Kickoff'];
/** Etapas depois do Kickoff (contam como «chegou a Kickoff»). */
const MX_AFTER = ['producao', 'concluido'];
/** Canais de uma resposta de saída. */
const MX_OUT = ['call' => 'Chamada', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'meeting' => 'Reunião'];
/** Probabilidade de fecho por etapa (pipeline ponderado), por omissão; Definições › Pipeline muda. */
const MX_PROB = ['xray' => 15, 'proposta' => 35, 'feedback' => 55];
/** Abaixo disto, a amostra é pequena (o número aparece com o aviso). */
const MX_SMALL = 10;

function metrics_ready(PDO $pdo): bool {
    static $ok = null;
    return $ok ??= db_has_table($pdo, 'stage_events');
}

/** A posição de uma etapa (0 = Lead … 5 = Kickoff); produção e concluído contam como Kickoff. */
function mx_step_i(string $step): ?int {
    static $i = null;
    $i ??= array_flip(array_keys(MX_STEPS));
    if (isset($i[$step])) return $i[$step];
    return in_array($step, MX_AFTER, true) ? 5 : null;
}

/** As etapas dos funis (id → linha), uma vez por pedido. */
function mx_stages(PDO $pdo): array {
    static $s = null;
    if ($s === null) {
        $s = [];
        foreach ($pdo->query('SELECT * FROM funnel_stages')->fetchAll() as $r) $s[(int)$r['id']] = $r;
    }
    return $s;
}

/** A etapa do relatório que corresponde a uma etapa de um funil. */
function mx_stage_step(?array $stage): ?string {
    if (!$stage) return null;
    return match ($stage['kind']) {
        'xray'       => 'xray',
        'commercial' => ($stage['role'] ?? '') === 'proposal' ? 'proposta' : (($stage['role'] ?? '') === 'feedback' ? 'feedback' : 'xray'),
        'kickoff'    => 'kickoff',
        'production' => 'producao',
        'done'       => 'concluido',
        default      => null,
    };
}

/** Um passo no histórico estruturado. */
function mx_event(PDO $pdo, int $dealId, string $step, array $o = []): void {
    $pdo->prepare('INSERT INTO stage_events (deal_id, line_id, step, funnel_id, from_stage_id, to_stage_id, at, user_id, reason, source) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$dealId, $o['line'] ?? null, $step, $o['funnel'] ?? null, $o['from'] ?? null, $o['to'] ?? null, $o['at'] ?? date('Y-m-d H:i:s'),
                   $o['user'] ?? null, mb_substr((string)($o['reason'] ?? ''), 0, 255), $o['source'] ?? 'live']);
}

/**
 * Chamada pelo deal_log: cada evento do histórico que muda a etapa vira um passo estruturado; uma chamada ou
 * um email registados contam como primeira resposta. Nunca falha o que a chamou.
 */
function metrics_on_log(PDO $pdo, int $dealId, string $event, array $d): void {
    try {
        if (!metrics_ready($pdo)) return;
        $uid = $d['user_id'] ?? (current_user()['id'] ?? null);
        $o = ['user' => $uid, 'from' => $d['from_stage'] ?? null, 'to' => $d['to_stage'] ?? null, 'funnel' => $d['to_funnel'] ?? ($d['from_funnel'] ?? null)];
        switch ($event) {
            case 'create':
                mx_event($pdo, $dealId, 'lead', $o);
                break;
            case 'convert': case 'stage': case 'funnel': case 'reopen':
                if ($event === 'reopen') mx_event($pdo, $dealId, 'reaberto', $o);
                $step = mx_stage_step(mx_stages($pdo)[(int)($d['to_stage'] ?? 0)] ?? stage_row_fresh($pdo, (int)($d['to_stage'] ?? 0)));
                if ($step && !($event === 'funnel' && $step === mx_stage_step(mx_stages($pdo)[(int)($d['from_stage'] ?? 0)] ?? null))) mx_event($pdo, $dealId, $step, $o);
                break;
            case 'lost':
                mx_event($pdo, $dealId, 'perdido', $o + ['reason' => (string)($d['note'] ?? '')]);
                break;
            case 'archive':
                mx_event($pdo, $dealId, 'arquivado', $o + ['reason' => (string)($d['note'] ?? '')]);
                break;
            case 'reactivate':
                mx_event($pdo, $dealId, 'reaberto', $o);
                break;
            case 'call_ok': case 'call_miss':
                metrics_first_response($pdo, $dealId, 'call', null, $uid);
                break;
            case 'email':
                metrics_first_response($pdo, $dealId, 'email', null, $uid);
                break;
        }
    } catch (Throwable $e) {
        error_log('metrics_on_log: ' . $e->getMessage());
    }
}

/** Uma etapa criada depois da cache deste pedido (funis novos). */
function stage_row_fresh(PDO $pdo, int $id): ?array {
    if (!$id) return null;
    $st = $pdo->prepare('SELECT * FROM funnel_stages WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * A primeira interação de saída de uma lead (só a primeira conta): guarda quando, por que canal e quem, e
 * marca a lead como «Abordada».
 */
function metrics_first_response(PDO $pdo, int $dealId, string $channel, ?string $at = null, $uid = null): void {
    try {
        if (!metrics_ready($pdo) || !isset(MX_OUT[$channel])) return;
        $st = $pdo->prepare('SELECT phase, created_at, first_response_at FROM deals WHERE id = ?');
        $st->execute([$dealId]);
        $d = $st->fetch();
        if (!$d || $d['first_response_at'] !== null) return;
        $at ??= date('Y-m-d H:i:s');
        if ($at < (string)$d['created_at']) $at = (string)$d['created_at'];
        $pdo->prepare('UPDATE deals SET first_response_at = ?, first_response_channel = ?, first_response_by = ? WHERE id = ? AND first_response_at IS NULL')
            ->execute([$at, $channel, $uid ?: null, $dealId]);
        if ($d['phase'] === 'lead') mx_event($pdo, $dealId, 'abordada', ['at' => $at, 'user' => $uid ?: null, 'reason' => MX_OUT[$channel]]);
    } catch (Throwable $e) {
        error_log('metrics_first_response: ' . $e->getMessage());
    }
}

/**
 * Reconstrói, uma vez, o histórico estruturado do que já existia: do deal_history (criada, conversão, etapas,
 * funil, perdido, arquivo, reaberto), das interações (primeira resposta) e, quando falta, dos campos do
 * negócio (convertido, ano/mês do trabalho, concluído, perdido, arquivado). Volta a correr só para os
 * registos que ainda não têm nenhum passo.
 */
function metrics_rebuild(PDO $pdo): int {
    $stages = mx_stages($pdo);
    $have = array_flip(array_map('intval', $pdo->query('SELECT DISTINCT deal_id FROM stage_events')->fetchAll(PDO::FETCH_COLUMN)));
    $hist = [];
    foreach ($pdo->query('SELECT * FROM deal_history ORDER BY created_at, id') as $h) $hist[(int)$h['deal_id']][] = $h;
    $inter = [];
    foreach ($pdo->query("SELECT deal_id, kind, happened_at, user_id FROM interactions WHERE deal_id IS NOT NULL AND kind IN ('call','email','meeting','whatsapp') ORDER BY happened_at") as $i) {
        $inter[(int)$i['deal_id']] ??= $i;
    }
    $n = 0;
    $deals = $pdo->query('SELECT id, phase, created_at, created_by, converted_at, funnel_id, stage_id, lost_at, lost_reason, lost_stage_id, archived_at, archived_reason,
                                 done_at, year, month, first_response_at FROM deals')->fetchAll();
    $pdo->beginTransaction();
    try {
        foreach ($deals as $d) {
            $id = (int)$d['id'];
            if (isset($have[$id])) continue;
            $ev = [];
            $add = function (string $step, ?string $at, array $o = []) use (&$ev) {
                if ($at === null || $at === '') return;
                $ev[] = ['step' => $step, 'at' => strlen($at) === 10 ? $at . ' 12:00:00' : $at] + $o;
            };
            // a primeira resposta: a primeira chamada ou email registados, ou a primeira interação de saída
            $first = null;
            foreach ($hist[$id] ?? [] as $h) {
                if (in_array($h['event'], ['call_ok', 'call_miss', 'email'], true)) { $first = ['at' => $h['created_at'], 'ch' => $h['event'] === 'email' ? 'email' : 'call', 'user' => $h['user_id']]; break; }
            }
            if (isset($inter[$id]) && ($first === null || $inter[$id]['happened_at'] < $first['at'])) {
                $first = ['at' => $inter[$id]['happened_at'], 'ch' => $inter[$id]['kind'], 'user' => $inter[$id]['user_id']];
            }
            if ($first && $d['first_response_at'] === null) {
                $at = max((string)$first['at'], (string)$d['created_at']);
                $pdo->prepare('UPDATE deals SET first_response_at = ?, first_response_channel = ?, first_response_by = ? WHERE id = ?')->execute([$at, $first['ch'], $first['user'], $id]);
                $add('abordada', $at, ['user' => $first['user'], 'reason' => MX_OUT[$first['ch']] ?? '']);
            }
            foreach ($hist[$id] ?? [] as $h) {
                $o = ['user' => $h['user_id'], 'from' => $h['from_stage_id'], 'to' => $h['to_stage_id'], 'funnel' => $h['to_funnel_id'] ?? $h['from_funnel_id']];
                switch ($h['event']) {
                    case 'convert': case 'stage': case 'funnel': case 'reopen':
                        if ($h['event'] === 'reopen') $add('reaberto', $h['created_at'], $o);
                        if ($s = mx_stage_step($stages[(int)$h['to_stage_id']] ?? null)) $add($s, $h['created_at'], $o);
                        break;
                    case 'lost':      $add('perdido', $h['created_at'], $o + ['reason' => (string)$h['note']]); break;
                    case 'archive':   $add('arquivado', $h['created_at'], $o + ['reason' => (string)$h['note']]); break;
                    case 'reactivate': $add('reaberto', $h['created_at'], $o); break;
                }
            }
            $has = fn(string $s) => (bool)array_filter($ev, fn($e) => $e['step'] === $s);
            $won = in_array($d['phase'], ['production', 'done'], true);
            if ($d['phase'] !== 'lead' && $d['phase'] !== 'archived' && !$has('xray') && ($d['converted_at'] || $won || $d['phase'] === 'lost')) {
                $add('xray', $d['converted_at'] ?: $d['created_at'], ['funnel' => $d['funnel_id']]);
            }
            if ($won && !$has('kickoff') && !$has('producao') && !$has('concluido')) {
                $add('kickoff', $d['year'] ? sprintf('%04d-%02d-01 12:00:00', (int)$d['year'], (int)($d['month'] ?: 1)) : ($d['converted_at'] ?: $d['created_at']), ['funnel' => $d['funnel_id']]);
            }
            if ($d['phase'] === 'done' && !$has('concluido') && $d['done_at']) $add('concluido', $d['done_at'], ['funnel' => $d['funnel_id']]);
            if ($d['phase'] === 'lost' && !$has('perdido')) $add('perdido', $d['lost_at'] ?: $d['created_at'], ['reason' => (string)$d['lost_reason'], 'from' => $d['lost_stage_id']]);
            if ($d['phase'] === 'archived' && !$has('arquivado')) $add('arquivado', $d['archived_at'] ?: $d['created_at'], ['reason' => lead_archive_label((string)$d['archived_reason'])]);
            // a lead entrou no máximo quando aconteceu o primeiro passo (os importados da folha têm a data da importação)
            $leadAt = (string)$d['created_at'];
            foreach ($ev as $e) if ($e['at'] < $leadAt) $leadAt = $e['at'];
            array_unshift($ev, ['step' => 'lead', 'at' => $leadAt, 'user' => $d['created_by']]);
            foreach ($ev as $e) {
                mx_event($pdo, $id, $e['step'], ['at' => $e['at'], 'user' => $e['user'] ?? null, 'from' => $e['from'] ?? null, 'to' => $e['to'] ?? null,
                                                 'funnel' => $e['funnel'] ?? null, 'reason' => $e['reason'] ?? '', 'source' => 'rebuilt']);
                $n++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $n;
}

// ── horas úteis ─────────────────────────────────────────────

/** O horário de trabalho (Definições › Geral › Horário): dias (5 ou 6), início e fim em minutos. */
function mx_work(PDO $pdo): array {
    static $w = null;
    if ($w === null) {
        $m = fn(string $t, int $d) => preg_match('/^(\d{1,2}):(\d{2})/', $t, $x) ? (int)$x[1] * 60 + (int)$x[2] : $d;
        $w = ['days' => (int)setting($pdo, 'work_days', '5') === 6 ? 6 : 5,
              'start' => $m((string)setting($pdo, 'work_start', '09:00'), 540), 'end' => $m((string)setting($pdo, 'work_end', '18:00'), 1080)];
        if ($w['end'] <= $w['start']) $w['end'] = $w['start'] + 480;
    }
    return $w;
}

/** Segundos úteis entre dois instantes (hora de Lisboa, dias e horário de trabalho; sem feriados). */
function mx_business_seconds(PDO $pdo, int $from, int $to): int {
    if ($to <= $from) return 0;
    $w = mx_work($pdo);
    $tz = new DateTimeZone('Europe/Lisbon');
    $day = (new DateTimeImmutable('@' . $from))->setTimezone($tz)->setTime(0, 0);
    $sum = 0;
    for ($i = 0; $i < 4000; $i++) {
        $dow = (int)$day->format('N');
        if ($dow <= $w['days']) {
            $a = $day->modify('+' . $w['start'] . ' minutes')->getTimestamp();
            $b = $day->modify('+' . $w['end'] . ' minutes')->getTimestamp();
            $lo = max($a, $from); $hi = min($b, $to);
            if ($hi > $lo) $sum += $hi - $lo;
        }
        $day = $day->modify('+1 day');
        if ($day->getTimestamp() >= $to) break;
    }
    return $sum;
}

/** Horas úteis entre duas datas da base. */
function mx_bhours(PDO $pdo, ?string $from, ?string $to): ?float {
    if (!$from || !$to) return null;
    return round(mx_business_seconds($pdo, strtotime($from), strtotime($to)) / 3600, 2);
}

/** O SLA da primeira resposta de um canal, em horas úteis (Definições › Leads). */
function mx_sla(PDO $pdo, string $channel): float {
    $v = setting($pdo, 'sla_hours_' . $channel, null);
    if ($v === null || $v === '') $v = LEAD_SLA_DEFAULT[$channel] ?? setting($pdo, 'sla_hours', 4);
    return max(0.25, (float)$v);
}

// ── os registos ─────────────────────────────────────────────

/**
 * Todas as leads e negócios que contam (sem quarentena nem spam), com as datas a que chegaram a cada etapa.
 * Cada registo: id, code, name, phase, ch (canal), source, campaign, partner, owner, fu (funil do relatório:
 * o de origem; 0 = ainda lead), cur_fu, stage_id, sv (serviços: funis das frentes), value, created, at[etapa],
 * reach (0–5), end (open|won|lost), lost_at, lost_reason, lost_i, fr_at, fr_ch, fr_by, entered (na etapa atual).
 */
function metrics_deals(PDO $pdo, bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    $ev = [];
    if (metrics_ready($pdo)) {
        foreach ($pdo->query('SELECT deal_id, step, at, reason FROM stage_events ORDER BY at, id') as $e) $ev[(int)$e['deal_id']][] = $e;
    }
    $lines = [];
    if (db_has_table($pdo, 'deal_lines')) {
        foreach ($pdo->query('SELECT deal_id, funnel_id FROM deal_lines') as $l) $lines[(int)$l['deal_id']][(int)$l['funnel_id']] = true;
    }
    $has = metrics_ready($pdo);
    $rows = $pdo->query('SELECT id, code, name, company, person, phase, lead_state, origin, campaign, ' . ($has ? 'source, utm, first_response_at, first_response_channel, first_response_by, ' : '')
        . 'brought_by_id, owner_id, created_by, funnel_id, origin_funnel_id, stage_id, stage_entered_at, state_since, value_estimate, budget, created_at, converted_at,
           lost_at, lost_reason, lost_stage_id, archived_at, archived_reason, done_at, delivered_at, delivery_date, year, month, is_quarantined, contact_id, exec_owner_id
           FROM deals')->fetchAll();
    $stages = mx_stages($pdo);
    $out = [];
    foreach ($rows as $d) {
        $id = (int)$d['id'];
        if ((int)$d['is_quarantined'] === 1) continue;
        if ($d['phase'] === 'archived' && in_array(mb_strtolower((string)$d['archived_reason']), ['spam'], true)) continue;
        $at = [];
        $lostAt = null; $lostReason = '';
        $last = null;   // a última entrada numa etapa (para «há quanto tempo está aqui»)
        foreach ($ev[$id] ?? [] as $e) {
            $i = mx_step_i($e['step']);
            if ($i !== null) {
                $k = array_keys(MX_STEPS)[$i];
                $at[$k] ??= $e['at'];
                $last = $e['at'];
            } elseif ($e['step'] === 'perdido' || $e['step'] === 'arquivado') {
                $lostAt = $e['at']; $lostReason = (string)$e['reason'];
            } elseif ($e['step'] === 'reaberto') {
                $lostAt = null; $lostReason = '';
            }
        }
        $at['lead'] ??= $d['created_at'];
        if (!isset($at['abordada']) && $has && $d['first_response_at']) $at['abordada'] = $d['first_response_at'];
        // o que chegou mais longe preenche as etapas que saltou (com a data da etapa seguinte)
        $keys = array_keys(MX_STEPS);
        $reach = 0;
        foreach ($keys as $i => $k) if (isset($at[$k])) $reach = $i;
        if (in_array($d['phase'], ['production', 'done'], true)) $reach = 5;
        elseif ($d['phase'] === 'pipeline' || ($d['phase'] === 'lost' && $d['converted_at'])) $reach = max($reach, 2);
        for ($i = $reach; $i >= 0; $i--) {
            if (!isset($at[$keys[$i]])) {
                $next = null;
                for ($j = $i + 1; $j <= $reach; $j++) if (isset($at[$keys[$j]])) { $next = $at[$keys[$j]]; break; }
                $at[$keys[$i]] = $next ?? ($keys[$i] === 'xray' ? ($d['converted_at'] ?: $d['created_at']) : $d['created_at']);
            }
        }
        foreach ($keys as $i => $k) if ($i > $reach) unset($at[$k]);
        if ($at['lead'] > min($at)) $at['lead'] = min($at);
        $end = in_array($d['phase'], ['production', 'done'], true) ? 'won' : (in_array($d['phase'], ['lost', 'archived'], true) ? 'lost' : 'open');
        if ($end === 'lost') {
            $lostAt ??= $d['phase'] === 'lost' ? ($d['lost_at'] ?: $d['created_at']) : ($d['archived_at'] ?: $d['created_at']);
            if ($lostReason === '') $lostReason = $d['phase'] === 'lost' ? (string)$d['lost_reason'] : lead_archive_label((string)$d['archived_reason']);
        }
        $fu = (int)($d['origin_funnel_id'] ?: $d['funnel_id']);
        $sv = array_keys($lines[$id] ?? []);
        if ($d['funnel_id'] && !in_array((int)$d['funnel_id'], $sv, true)) $sv[] = (int)$d['funnel_id'];
        $value = (float)$d['budget'] > 0 ? (float)$d['budget'] : (float)$d['value_estimate'];
        $out[$id] = [
            'id' => $id, 'code' => $d['code'], 'name' => lead_display_name($d) ?: (string)$d['name'], 'company' => (string)$d['company'], 'phase' => $d['phase'],
            'lead_state' => $d['lead_state'], 'ch' => isset(LEAD_ORIGIN[$d['origin']]) ? $d['origin'] : 'manual', 'source' => trim((string)($d['source'] ?? '')),
            'campaign' => trim((string)$d['campaign']), 'partner' => $d['brought_by_id'] ? (int)$d['brought_by_id'] : 0,
            'owner' => $d['owner_id'] ? (int)$d['owner_id'] : 0, 'exec' => $d['exec_owner_id'] ? (int)$d['exec_owner_id'] : 0,
            'fu' => $fu, 'cur_fu' => (int)$d['funnel_id'], 'stage_id' => (int)$d['stage_id'], 'stage' => $stages[(int)$d['stage_id']] ?? null, 'sv' => $sv,
            'value' => $value, 'budget' => (float)$d['budget'], 'contact' => $d['contact_id'] ? (int)$d['contact_id'] : 0,
            'created' => $d['created_at'], 'at' => $at, 'reach' => $reach, 'end' => $end,
            'lost_at' => $end === 'lost' ? $lostAt : null, 'lost_reason' => $end === 'lost' ? $lostReason : '', 'lost_i' => $end === 'lost' ? $reach : null,
            'won_at' => $end === 'won' ? $at['kickoff'] : null,
            'fr_at' => $has ? $d['first_response_at'] : null, 'fr_ch' => $has ? (string)$d['first_response_channel'] : '', 'fr_by' => $has && $d['first_response_by'] ? (int)$d['first_response_by'] : 0,
            'entered' => $d['stage_entered_at'] ?: ($last ?? $d['created_at']), 'state_since' => $d['state_since'] ?: $d['created_at'],
            'done_at' => $d['done_at'], 'delivered_at' => $d['delivered_at'], 'delivery_date' => $d['delivery_date'],
        ];
    }
    return $cache = $out;
}

/** O motivo de perda arrumado para agrupar («Recusou a proposta: caro» → «Recusou a proposta»). */
function mx_reason(string $r): string {
    $r = trim($r);
    if ($r === '') return 'Sem motivo registado';
    $r = trim((string)preg_replace('/\s*[:·].*$/u', '', $r));
    return $r === '' ? 'Sem motivo registado' : mb_strtoupper(mb_substr($r, 0, 1)) . mb_substr($r, 1);
}

/** Aplica os filtros globais (funil, origem, pessoa, serviço). */
function mx_filter(array $deals, array $f): array {
    return array_filter($deals, function ($d) use ($f) {
        if (!empty($f['fu']) && $d['fu'] !== (int)$f['fu']) return false;
        if (!empty($f['or']) && $d['ch'] !== $f['or']) return false;
        if (!empty($f['pe']) && $d['owner'] !== (int)$f['pe']) return false;
        if (!empty($f['sv']) && !in_array((int)$f['sv'], $d['sv'], true)) return false;
        return true;
    });
}

/** Um instante dentro de [de, até[ (datas aaaa-mm-dd). */
function mx_in(?string $ts, string $from, string $to): bool {
    return $ts !== null && $ts !== '' && $ts >= $from && $ts < $to;
}

// ── período ─────────────────────────────────────────────────

const MX_PERIODS = ['semana' => 'Esta semana', 'mes' => 'Este mês', 'trimestre' => 'Trimestre', 'ano' => 'Ano', 'custom' => 'Personalizado'];

/**
 * O período escolhido e o anterior com a mesma duração. Num período em curso, o anterior compara-se até ao
 * mesmo ponto («outubro até dia 24» contra «setembro até dia 24»). $p: semana|mes|trimestre|ano|custom,
 * $n: 0 = o atual, -1 = o anterior…; custom: $de e $ate (inclusive).
 */
function mx_period(string $p, int $n = 0, string $de = '', string $ate = ''): array {
    $tz = new DateTimeZone('Europe/Lisbon');
    $today = new DateTimeImmutable(today_lisbon(), $tz);
    if (!isset(MX_PERIODS[$p])) $p = 'mes';
    if ($p === 'custom') {
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $de, $tz) ?: $today->modify('first day of this month');
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', $ate, $tz) ?: $today;
        if ($b < $a) [$a, $b] = [$b, $a];
        $from = $a; $to = $b->modify('+1 day');
        $len = (int)$from->diff($to)->days;
        $pf = $from->modify('-' . $len . ' days');
        $label = $from->format('d/m/Y') . ' a ' . $b->format('d/m/Y');
        $prevLabel = $pf->format('d/m/Y') . ' a ' . $from->modify('-1 day')->format('d/m/Y');
        $pt = $from;
    } else {
        $n = max(-60, min(0, $n));
        if ($p === 'semana') {
            $from = $today->modify('-' . ((int)$today->format('N') - 1) . ' days')->modify(($n * 7) . ' days');
            $to = $from->modify('+7 days'); $pf = $from->modify('-7 days');
            $label = $n === 0 ? 'Esta semana' : 'Semana de ' . $from->format('d/m');
            $prevLabel = 'semana anterior';
        } elseif ($p === 'mes') {
            $from = $today->modify('first day of this month')->modify($n . ' months');
            $to = $from->modify('+1 month'); $pf = $from->modify('-1 month');
            $label = month_name((int)$from->format('n')) . ' ' . $from->format('Y');
            $prevLabel = mb_strtolower(month_name((int)$pf->format('n')));
        } elseif ($p === 'trimestre') {
            $q = intdiv((int)$today->format('n') - 1, 3);
            $from = $today->setDate((int)$today->format('Y'), $q * 3 + 1, 1)->modify(($n * 3) . ' months');
            $to = $from->modify('+3 months'); $pf = $from->modify('-3 months');
            $label = (intdiv((int)$from->format('n') - 1, 3) + 1) . '.º trimestre de ' . $from->format('Y');
            $prevLabel = 'trimestre anterior';
        } else {
            $from = $today->setDate((int)$today->format('Y') + $n, 1, 1);
            $to = $from->modify('+1 year'); $pf = $from->modify('-1 year');
            $label = 'Ano de ' . $from->format('Y');
            $prevLabel = $pf->format('Y');
        }
        $pt = $from;
    }
    // em curso: o anterior até ao mesmo ponto
    $tomorrow = $today->modify('+1 day');
    $partial = $tomorrow < $to && $tomorrow > $from;
    $elapsedDays = $partial ? (int)$from->diff($tomorrow)->days : (int)$from->diff($to)->days;
    $prevTo = $partial ? $pf->modify('+' . $elapsedDays . ' days') : $pt;
    if ($prevTo > $pt) $prevTo = $pt;
    return [
        'p' => $p, 'n' => $n, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'label' => $label,
        'prev_from' => $pf->format('Y-m-d'), 'prev_to' => $prevTo->format('Y-m-d'),
        'prev_label' => $prevLabel . ($partial ? ' (até ao mesmo dia)' : ''), 'partial' => $partial,
        'days' => (int)$from->diff($to)->days, 'elapsed' => $elapsedDays,
    ];
}

/** A variação contra o anterior: [texto «▲ 12%», classe up|down|flat, dica]; $good = 'up' quando subir é bom. */
function mx_delta(float $now, float $prev, string $good = 'up', bool $points = false): array {
    if ($points) {
        $d = round($now - $prev, 1);
        if (abs($d) < 0.05) return ['= 0 pp', 'flat'];
        $cls = ($d > 0) === ($good === 'up') ? 'good' : 'bad';
        return [($d > 0 ? '▲ ' : '▼ ') . str_replace('.', ',', (string)abs($d)) . ' pp', $cls];
    }
    if ($prev == 0.0) return $now == 0.0 ? ['=', 'flat'] : ['novo', 'flat'];
    $pct = ($now - $prev) / abs($prev) * 100;
    if (abs($pct) < 0.5) return ['= 0%', 'flat'];
    $cls = ($pct > 0) === ($good === 'up') ? 'good' : 'bad';
    return [($pct > 0 ? '▲ ' : '▼ ') . (abs($pct) >= 10 ? (int)round(abs($pct)) : str_replace('.', ',', (string)round(abs($pct), 1))) . '%', $cls];
}

// ── estatística ─────────────────────────────────────────────

function mx_median(array $v): ?float {
    $v = array_values(array_filter($v, fn($x) => $x !== null));
    if (!$v) return null;
    sort($v);
    $n = count($v);
    return $n % 2 ? (float)$v[intdiv($n, 2)] : ((float)$v[$n / 2 - 1] + (float)$v[$n / 2]) / 2;
}

function mx_pctile(array $v, float $p): ?float {
    $v = array_values(array_filter($v, fn($x) => $x !== null));
    if (!$v) return null;
    sort($v);
    $i = ($p / 100) * (count($v) - 1);
    $lo = (int)floor($i); $hi = (int)ceil($i);
    return (float)$v[$lo] + ((float)$v[$hi] - (float)$v[$lo]) * ($i - $lo);
}

function mx_avg(array $v): ?float {
    $v = array_values(array_filter($v, fn($x) => $x !== null));
    return $v ? array_sum($v) / count($v) : null;
}

/** Dias entre duas datas da base (fracionário). */
function mx_days(?string $a, ?string $b): ?float {
    if (!$a || !$b) return null;
    return max(0.0, (strtotime($b) - strtotime($a)) / 86400);
}

function mx_rate(int $a, int $b): ?float {
    return $b > 0 ? $a / $b * 100 : null;
}

// ── funil comercial ─────────────────────────────────────────

/**
 * Leitura por período: por etapa, quantos entraram no período, quantos desses já passaram à seguinte,
 * quantos se perderam nessa etapa (no período), os motivos, e o tempo na etapa (das passagens que
 * aconteceram no período). Devolve [etapa => [in, in_ids, next, next_ids, lost, lost_ids, reasons, days[]]].
 */
function mx_funnel_period(array $deals, string $from, string $to): array {
    $keys = array_keys(MX_STEPS);
    $out = [];
    foreach ($keys as $i => $k) $out[$k] = ['in' => [], 'next' => [], 'lost' => [], 'reasons' => [], 'days' => []];
    foreach ($deals as $d) {
        foreach ($keys as $i => $k) {
            if (!isset($d['at'][$k])) break;
            $nk = $keys[$i + 1] ?? null;
            if (mx_in($d['at'][$k], $from, $to)) {
                $out[$k]['in'][] = $d['id'];
                if ($nk && isset($d['at'][$nk])) $out[$k]['next'][] = $d['id'];
            }
            if ($nk && isset($d['at'][$nk]) && mx_in($d['at'][$nk], $from, $to)) $out[$k]['days'][] = mx_days($d['at'][$k], $d['at'][$nk]);
        }
        if ($d['end'] === 'lost' && mx_in($d['lost_at'], $from, $to)) {
            $k = $keys[$d['lost_i']];
            $out[$k]['lost'][] = $d['id'];
            $r = mx_reason($d['lost_reason']);
            $out[$k]['reasons'][$r][] = $d['id'];
        }
    }
    return $out;
}

/**
 * Leitura por coorte: das leads que entraram no período, quantas já chegaram a cada etapa (até hoje), quantas
 * se perderam em cada etapa (e porquê), quantas estão ainda abertas nela, e o tempo em cada etapa.
 */
function mx_funnel_cohort(array $deals, string $from, string $to, string $base = 'lead'): array {
    $keys = array_keys(MX_STEPS);
    $b = mx_step_i($base);
    $out = [];
    foreach ($keys as $i => $k) $out[$k] = ['in' => [], 'next' => [], 'lost' => [], 'open' => [], 'reasons' => [], 'days' => []];
    foreach ($deals as $d) {
        if (!isset($d['at'][$base]) || !mx_in($d['at'][$base], $from, $to)) continue;
        foreach ($keys as $i => $k) {
            if ($i < $b) continue;
            if (!isset($d['at'][$k])) break;
            $out[$k]['in'][] = $d['id'];
            $nk = $keys[$i + 1] ?? null;
            if ($nk && isset($d['at'][$nk])) {
                $out[$k]['next'][] = $d['id'];
                $out[$k]['days'][] = mx_days($d['at'][$k], $d['at'][$nk]);
            } elseif ($d['end'] === 'lost') {
                $out[$k]['lost'][] = $d['id'];
                $out[$k]['reasons'][mx_reason($d['lost_reason'])][] = $d['id'];
            } elseif ($d['end'] === 'open') {
                $out[$k]['open'][] = $d['id'];
            }
        }
    }
    return $out;
}

/** Coortes mensais (mês de entrada da lead): % que chegou a cada etapa. [aaaa-mm => [n, etapa => [n, ids]]]. */
function mx_cohorts(array $deals, int $months = 6, string $base = 'lead'): array {
    $tz = new DateTimeZone('Europe/Lisbon');
    $m0 = (new DateTimeImmutable(today_lisbon(), $tz))->modify('first day of this month');
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) $out[$m0->modify('-' . $i . ' months')->format('Y-m')] = [];
    $b = mx_step_i($base);
    foreach ($deals as $d) {
        if (!isset($d['at'][$base])) continue;
        $ym = substr($d['at'][$base], 0, 7);
        if (!isset($out[$ym])) continue;
        foreach (array_keys(MX_STEPS) as $i => $k) {
            if ($i < $b) continue;
            if (!isset($d['at'][$k])) break;
            $out[$ym][$k][] = $d['id'];
        }
    }
    return $out;
}

// ── dinheiro por negócio (só para quem vê o Financeiro) ─────

/**
 * Por negócio: valor (sem IVA), custos reais (custos do negócio, despesas do Financeiro com âmbito no negócio e
 * reservas dos sócios — como na ficha), gross profit, faturado e recebido (sem IVA). [id => [...]].
 */
function mx_money(PDO $pdo, array $ids): array {
    static $memo = [];
    require_once __DIR__ . '/finance.php';
    $rate = vat_rate($pdo);
    $out = [];
    $need = array_values(array_diff(array_map('intval', $ids), array_keys($memo)));
    if ($need) {
        $in = implode(',', $need);
        $rows = [];
        foreach ($pdo->query("SELECT id, budget, value_estimate, has_vat, expenses_mode, expenses_manual, is_paid FROM deals WHERE id IN ($in)") as $r) $rows[(int)$r['id']] = $r;
        $inv = []; $hasFin = db_has_table($pdo, 'fin_docs');
        if ($hasFin) {
            foreach ($pdo->query("SELECT deal_id, SUM(CASE WHEN doc_type = 'credit' THEN -base ELSE base END) AS b FROM fin_docs
                                   WHERE deal_id IN ($in) AND direction = 'in' AND deleted_at IS NULL AND status NOT IN ('void','requested','review') GROUP BY deal_id") as $r) $inv[(int)$r['deal_id']] = (float)$r['b'];
            require_once __DIR__ . '/fin.php';
            require_once __DIR__ . '/fin_res.php';
        }
        foreach ($need as $id) {
            $d = $rows[$id] ?? null;
            if (!$d) continue;
            $m = deal_money($d, deal_costs($pdo, $id, $rate), $rate);
            $value = (float)$d['budget'] > 0 ? (float)$d['budget'] : (float)$d['value_estimate'];
            $memo[$id] = [
                'value' => $value, 'costs' => $m['expenses'], 'gp' => round($value - $m['expenses'], 2),
                'invoiced' => $inv[$id] ?? ((int)$d['is_paid'] ? (float)$d['budget'] : 0.0),
                'received' => $hasFin ? fin_deal_received_base($pdo, $id) : ((int)$d['is_paid'] ? (float)$d['budget'] : 0.0),
            ];
        }
    }
    foreach ($ids as $id) if (isset($memo[(int)$id])) $out[(int)$id] = $memo[(int)$id];
    return $out;
}

/** A probabilidade de fecho de uma etapa aberta (pipeline ponderado), em %. */
function mx_prob(PDO $pdo, string $step): float {
    return max(0, min(100, (float)setting($pdo, 'prob_' . $step, MX_PROB[$step] ?? 0)));
}

/**
 * Desempenho por grupo (funil, origem, mês…), medido a partir da entrada em pipeline (X-Ray → Kickoff):
 * entradas em pipeline, ganhos, perdidos (depois do X-Ray), ids de cada um, valor ganho, ciclos (dias do
 * X-Ray ao Kickoff); com $money, também gross profit, faturado, recebido, pipeline aberto e ponderado.
 * $key: fn(registo) => chave do grupo (null = fica de fora).
 */
function mx_perf(PDO $pdo, array $deals, string $from, string $to, callable $key, bool $money): array {
    $g = [];
    $blank = ['in' => [], 'won' => [], 'lost' => [], 'value' => 0.0, 'cycle' => [], 'open' => [], 'open_value' => 0.0, 'weighted' => 0.0,
              'gp' => 0.0, 'invoiced' => 0.0, 'received' => 0.0, 'gp_n' => 0];
    foreach ($deals as $d) {
        if ($d['reach'] < 2) continue;
        $k = $key($d);
        if ($k === null) continue;
        $g[$k] ??= $blank;
        if (mx_in($d['at']['xray'], $from, $to)) $g[$k]['in'][] = $d['id'];
        if ($d['end'] === 'won' && mx_in($d['won_at'], $from, $to)) {
            $g[$k]['won'][] = $d['id'];
            $g[$k]['value'] += $d['value'];
            $g[$k]['cycle'][] = mx_days($d['at']['xray'], $d['won_at']);
        }
        if ($d['end'] === 'lost' && mx_in($d['lost_at'], $from, $to)) $g[$k]['lost'][] = $d['id'];
        if ($d['end'] === 'open' && $d['phase'] === 'pipeline') {
            $step = array_keys(MX_STEPS)[min(4, $d['reach'])];
            $g[$k]['open'][] = $d['id'];
            $g[$k]['open_value'] += $d['value'];
            $g[$k]['weighted'] += $d['value'] * mx_prob($pdo, $step) / 100;
        }
    }
    if ($money) {
        $won = array_merge(...array_values(array_map(fn($r) => $r['won'], $g ?: [['won' => []]])));
        $mm = mx_money($pdo, $won);
        foreach ($g as &$r) {
            foreach ($r['won'] as $id) {
                if (!isset($mm[$id])) continue;
                $r['gp'] += $mm[$id]['gp']; $r['invoiced'] += $mm[$id]['invoiced']; $r['received'] += $mm[$id]['received']; $r['gp_n']++;
            }
        }
        unset($r);
    }
    return $g;
}

// ── velocidade ──────────────────────────────────────────────

/**
 * Primeira resposta das leads que entraram no período: horas úteis até à primeira interação de saída. Por lead:
 * [id, ch, owner, by, h (null = sem resposta), sla, ok (true/false/null = ainda dentro do prazo)].
 */
function mx_first_responses(PDO $pdo, array $deals, string $from, string $to): array {
    $now = time();
    $out = [];
    foreach ($deals as $d) {
        if (!mx_in($d['created'], $from, $to)) continue;
        $sla = mx_sla($pdo, $d['ch']);
        if ($d['fr_at']) {
            $h = mx_bhours($pdo, $d['created'], $d['fr_at']);
            $ok = $h <= $sla;
        } else {
            // sem resposta: só conta como fora do prazo depois de o prazo passar (as convertidas sem interação registada não contam)
            if ($d['reach'] >= 2 || $d['end'] === 'lost') continue;
            $h = null;
            $el = mx_business_seconds($pdo, strtotime($d['created']), $now) / 3600;
            $ok = $el > $sla ? false : null;
        }
        $out[] = ['id' => $d['id'], 'ch' => $d['ch'], 'owner' => $d['owner'], 'by' => $d['fr_by'] ?: $d['owner'], 'h' => $h, 'sla' => $sla, 'ok' => $ok,
                  'reach' => $d['reach'], 'won' => $d['end'] === 'won'];
    }
    return $out;
}

/** Resumo de um conjunto de primeiras respostas: n, mediana, p90, % dentro do SLA, ids fora do SLA. */
function mx_fr_stats(array $rows): array {
    $h = array_values(array_filter(array_column($rows, 'h'), fn($x) => $x !== null));
    $judged = array_values(array_filter($rows, fn($r) => $r['ok'] !== null));
    $ok = array_values(array_filter($judged, fn($r) => $r['ok'] === true));
    return ['n' => count($rows), 'answered' => count($h), 'median' => mx_median($h), 'p90' => mx_pctile($h, 90),
            'sla_pct' => $judged ? count($ok) / count($judged) * 100 : null, 'judged' => count($judged),
            'late_ids' => array_column(array_filter($judged, fn($r) => $r['ok'] === false), 'id'), 'ids' => array_column($rows, 'id')];
}

/** Velocidade × conversão: por faixa de tempo de resposta, % que passou a pipeline. */
function mx_speed_conversion(array $rows): array {
    $bands = ['< 1 h' => [0, 1], '1–4 h' => [1, 4], '4–24 h' => [4, 24], '> 24 h' => [24, INF]];
    $out = [];
    foreach ($bands as $label => [$a, $b]) {
        $in = array_values(array_filter($rows, fn($r) => $r['h'] !== null && $r['h'] >= $a && $r['h'] < $b));
        $conv = array_values(array_filter($in, fn($r) => $r['reach'] >= 2));
        $out[$label] = ['n' => count($in), 'conv' => count($conv), 'rate' => mx_rate(count($conv), count($in)), 'ids' => array_column($in, 'id'), 'conv_ids' => array_column($conv, 'id')];
    }
    return $out;
}

/** «As leads respondidas em menos de 1 h passam a pipeline 2,3× mais» — só com amostra suficiente nas duas faixas. */
function mx_speed_sentence(array $sc): ?string {
    $fast = $sc['< 1 h'];
    $slow = ['n' => 0, 'conv' => 0];
    foreach (['1–4 h', '4–24 h', '> 24 h'] as $k) { $slow['n'] += $sc[$k]['n']; $slow['conv'] += $sc[$k]['conv']; }
    if ($fast['n'] < 5 || $slow['n'] < 5) return null;
    $rf = $fast['conv'] / $fast['n']; $rs = $slow['n'] ? $slow['conv'] / $slow['n'] : 0;
    if ($rs <= 0) return $rf > 0 ? 'As leads respondidas em menos de 1 h útil passam a pipeline; as mais lentas ainda não passaram nenhuma.' : null;
    $x = $rf / $rs;
    if ($x >= 1.15) return 'As leads respondidas em menos de 1 h útil passam a pipeline ' . str_replace('.', ',', (string)round($x, 1)) . '× mais do que as respondidas depois.';
    if ($x <= 0.87) return 'Curioso: as leads respondidas depois de 1 h útil passam mais a pipeline (' . str_replace('.', ',', (string)round(1 / $x, 1)) . '×). Vale a pena ver porquê.';
    return 'Por agora, responder em menos de 1 h útil não muda a passagem a pipeline (taxas parecidas).';
}

/** Tendência mensal: % dentro do SLA, mediana da primeira resposta, e mediana do ciclo Lead → Kickoff. */
function mx_speed_trend(PDO $pdo, array $deals, int $months = 6): array {
    $tz = new DateTimeZone('Europe/Lisbon');
    $m0 = (new DateTimeImmutable(today_lisbon(), $tz))->modify('first day of this month');
    $out = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $a = $m0->modify('-' . $i . ' months');
        $from = $a->format('Y-m-d'); $to = $a->modify('+1 month')->format('Y-m-d');
        $s = mx_fr_stats(mx_first_responses($pdo, $deals, $from, $to));
        $cyc = []; $steps = [];
        foreach ($deals as $d) {
            if ($d['end'] === 'won' && mx_in($d['won_at'], $from, $to)) $cyc[] = mx_days($d['at']['lead'], $d['won_at']);
        }
        $out[$a->format('Y-m')] = ['sla' => $s['sla_pct'], 'judged' => $s['judged'], 'median' => $s['median'], 'cycle' => mx_median($cyc), 'cycle_n' => count($cyc)];
    }
    return $out;
}

// ── origens ─────────────────────────────────────────────────

/** Os custos de aquisição registados no Financeiro por canal (e fonte) no período: [canal => [total, por fonte]]. */
function mx_acq_costs(PDO $pdo, string $from, string $to): array {
    if (!db_has_table($pdo, 'fin_docs') || !db_has_column($pdo, 'fin_docs', 'acq_channel')) return [];
    $st = $pdo->prepare("SELECT id, acq_channel, acq_source, base, total, doc_type FROM fin_docs WHERE direction = 'out' AND acq_channel <> '' AND deleted_at IS NULL
                          AND status NOT IN ('void','review') AND COALESCE(issue_date, DATE(created_at)) >= ? AND COALESCE(issue_date, DATE(created_at)) < ?");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $v = ($r['doc_type'] === 'credit' ? -1 : 1) * (float)$r['base'];
        $out[$r['acq_channel']]['total'] = ($out[$r['acq_channel']]['total'] ?? 0) + $v;
        $out[$r['acq_channel']]['ids'][] = (int)$r['id'];
        $src = trim((string)$r['acq_source']);
        if ($src !== '') $out[$r['acq_channel']]['src'][mb_strtolower($src)] = ($out[$r['acq_channel']]['src'][mb_strtolower($src)] ?? 0) + $v;
    }
    return $out;
}

/**
 * Qualidade por origem (coorte: as leads que entraram no período). $key: fn(registo) => chave. Por grupo: leads,
 * pipeline, kickoff (ids), valor ganho, ciclo (dias Lead → Kickoff); com $money, gross profit dos ganhos.
 */
function mx_origins(PDO $pdo, array $deals, string $from, string $to, callable $key, bool $money): array {
    $g = [];
    foreach ($deals as $d) {
        if (!mx_in($d['created'], $from, $to)) continue;
        $k = $key($d);
        if ($k === null || $k === '') continue;
        $g[$k] ??= ['leads' => [], 'pipe' => [], 'won' => [], 'value' => 0.0, 'cycle' => [], 'gp' => 0.0];
        $g[$k]['leads'][] = $d['id'];
        if ($d['reach'] >= 2) $g[$k]['pipe'][] = $d['id'];
        if ($d['end'] === 'won') { $g[$k]['won'][] = $d['id']; $g[$k]['value'] += $d['value']; $g[$k]['cycle'][] = mx_days($d['at']['lead'], $d['won_at']); }
    }
    if ($money) {
        foreach ($g as &$r) foreach (mx_money($pdo, $r['won']) as $m) $r['gp'] += $m['gp'];
        unset($r);
    }
    // índice de qualidade: conversão a kickoff × ticket médio × margem, de 0 a 100 (100 = a melhor origem)
    $raw = [];
    foreach ($g as $k => $r) {
        $n = count($r['leads']);
        $conv = $n ? count($r['won']) / $n : 0;
        $ticket = count($r['won']) ? $r['value'] / count($r['won']) : 0;
        $margin = $money && $r['value'] > 0 ? max(0.0, $r['gp'] / $r['value']) : 1.0;
        $raw[$k] = $conv * ($ticket > 0 ? $ticket : 1) * $margin;
    }
    $best = $raw ? max($raw) : 0;
    foreach ($g as $k => &$r) $r['quality'] = $best > 0 ? (int)round($raw[$k] / $best * 100) : 0;
    unset($r);
    uasort($g, fn($a, $b) => [$b['quality'], count($b['leads'])] <=> [$a['quality'], count($a['leads'])]);
    return $g;
}

// ── atividade ───────────────────────────────────────────────

const MX_ACT = ['call' => 'Chamadas', 'email' => 'Emails', 'meeting' => 'Reuniões', 'whatsapp' => 'WhatsApp', 'task_new' => 'Tarefas criadas',
                'task_done' => 'Tarefas concluídas', 'task_late' => 'Tarefas atrasadas', 'proposal' => 'Propostas enviadas', 'xray' => 'X-Rays feitos', 'followup' => 'Follow-ups'];

/** O utilizador de cada membro da equipa (as tarefas antigas guardam o membro). */
function mx_member_users(PDO $pdo): array {
    static $m = null;
    return $m ??= array_map('intval', $pdo->query('SELECT team_member_id, id FROM users WHERE team_member_id IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * Os acontecimentos de atividade no período: cada um [kind, at, user, ref (tipo:id para a lista)]. Chamadas
 * (registadas ou pelo «Como correu?»), emails, reuniões, WhatsApp, tarefas, propostas enviadas, X-Rays validados.
 */
function mx_activity(PDO $pdo, string $from, string $to): array {
    $out = [];
    $q = function (string $sql) use ($pdo, $from, $to) { $st = $pdo->prepare($sql); $st->execute([$from, $to]); return $st->fetchAll(); };
    foreach ($q("SELECT id, kind, happened_at AS at, user_id FROM interactions WHERE kind IN ('call','email','meeting','whatsapp') AND happened_at >= ? AND happened_at < ?") as $r) {
        $out[] = ['kind' => $r['kind'], 'at' => $r['at'], 'user' => (int)$r['user_id'], 'ref' => 'i:' . $r['id']];
    }
    foreach ($q("SELECT id, event, created_at AS at, user_id FROM deal_history WHERE event IN ('call_ok','call_miss','email') AND created_at >= ? AND created_at < ?") as $r) {
        $out[] = ['kind' => $r['event'] === 'email' ? 'email' : 'call', 'at' => $r['at'], 'user' => (int)$r['user_id'], 'ref' => 'h:' . $r['id']];
    }
    $mu = mx_member_users($pdo);
    $who = fn(array $t) => (int)($t['assignee_id'] ?: ($mu[(int)$t['member_id']] ?? 0));
    foreach ($q("SELECT id, kind, created_at AS at, assignee_id, member_id, created_by FROM tasks WHERE created_at >= ? AND created_at < ?") as $t) {
        $out[] = ['kind' => 'task_new', 'at' => $t['at'], 'user' => (int)($t['created_by'] ?: $who($t)), 'ref' => 't:' . $t['id']];
    }
    foreach ($q("SELECT id, kind, done_at AS at, due_date, assignee_id, member_id FROM tasks WHERE status = 'done' AND done_at >= ? AND done_at < ?") as $t) {
        $out[] = ['kind' => 'task_done', 'at' => $t['at'], 'user' => $who($t), 'ref' => 't:' . $t['id']];
        if ($t['kind'] === 'followup') $out[] = ['kind' => 'followup', 'at' => $t['at'], 'user' => $who($t), 'ref' => 't:' . $t['id']];
        if ($t['due_date'] && substr((string)$t['at'], 0, 10) > $t['due_date']) $out[] = ['kind' => 'task_late', 'at' => $t['at'], 'user' => $who($t), 'ref' => 't:' . $t['id']];
    }
    // as que estão em atraso agora e venceram no período (ainda por fazer)
    foreach ($q("SELECT id, due_date AS at, assignee_id, member_id FROM tasks WHERE status <> 'done' AND due_date >= ? AND due_date < ?") as $t) {
        if ($t['at'] < today_lisbon()) $out[] = ['kind' => 'task_late', 'at' => $t['at'] . ' 23:59:00', 'user' => $who($t), 'ref' => 't:' . $t['id']];
    }
    if (db_has_table($pdo, 'proposals')) {
        foreach ($q("SELECT id, deal_id, sent_at AS at, sent_by FROM proposals WHERE sent_at >= ? AND sent_at < ?") as $p) {
            $out[] = ['kind' => 'proposal', 'at' => $p['at'], 'user' => (int)$p['sent_by'], 'ref' => 'd:' . $p['deal_id']];
        }
    }
    foreach ($q("SELECT id, xray_validated_at AS at, xray_validated_by FROM deals WHERE xray_validated_at >= ? AND xray_validated_at < ?") as $x) {
        $out[] = ['kind' => 'xray', 'at' => $x['at'], 'user' => (int)$x['xray_validated_by'], 'ref' => 'd:' . $x['id']];
    }
    return $out;
}

/** A atividade agregada: por tipo, por pessoa e tipo, por semana (segunda) e tipo, e o mapa dia × hora (saídas). */
function mx_activity_agg(array $acts, ?int $user = null): array {
    $tz = new DateTimeZone('Europe/Lisbon');
    $by = ['kind' => [], 'user' => [], 'week' => [], 'heat' => []];
    foreach ($acts as $a) {
        if ($user !== null && $a['user'] !== $user) continue;
        $by['kind'][$a['kind']][] = $a['ref'];
        $by['user'][$a['user']][$a['kind']][] = $a['ref'];
        $t = new DateTimeImmutable($a['at']);
        $wk = $t->modify('-' . ((int)$t->format('N') - 1) . ' days')->format('Y-m-d');
        $by['week'][$wk][$a['kind']] = ($by['week'][$wk][$a['kind']] ?? 0) + 1;
        if (isset(MX_OUT[$a['kind']])) {
            $l = (new DateTimeImmutable('@' . strtotime($a['at'])))->setTimezone($tz);
            $by['heat'][(int)$l->format('N')][(int)$l->format('G')] = ($by['heat'][(int)$l->format('N')][(int)$l->format('G')] ?? 0) + 1;
        }
    }
    ksort($by['week']);
    return $by;
}

// ── equipa ──────────────────────────────────────────────────

/** As pessoas que entram nos números (ativas; as que têm leads ou negócios). id → nome. */
function mx_people(PDO $pdo): array {
    static $p = null;
    return $p ??= $pdo->query("SELECT id, COALESCE(NULLIF(display_name,''), name) FROM users WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Por pessoa (responsável): leads atribuídas no período, mediana da primeira resposta, % lead → pipeline,
 * % pipeline → kickoff (coorte do período), kickoffs (no período), valor ganho, margem média e atividade.
 */
function mx_team(PDO $pdo, array $deals, string $from, string $to, array $acts, bool $money): array {
    $out = [];
    $fr = mx_first_responses($pdo, $deals, $from, $to);
    foreach (mx_people($pdo) as $uid => $name) {
        $mine = array_filter($deals, fn($d) => $d['owner'] === (int)$uid);
        $leads = array_filter($mine, fn($d) => mx_in($d['created'], $from, $to));
        $pipe = array_filter($leads, fn($d) => $d['reach'] >= 2);
        $pipeIn = array_filter($mine, fn($d) => $d['reach'] >= 2 && mx_in($d['at']['xray'], $from, $to));
        $pipeWon = array_filter($pipeIn, fn($d) => $d['end'] === 'won');
        $won = array_filter($mine, fn($d) => $d['end'] === 'won' && mx_in($d['won_at'], $from, $to));
        $act = array_filter($acts, fn($a) => $a['user'] === (int)$uid && isset(MX_OUT[$a['kind']]));
        if (!$leads && !$pipeIn && !$won && !$act) continue;
        $f = mx_fr_stats(array_values(array_filter($fr, fn($r) => $r['owner'] === (int)$uid)));
        $row = ['uid' => (int)$uid, 'name' => $name, 'leads' => array_column($leads, 'id'), 'pipe' => array_column($pipe, 'id'),
                'pipe_in' => array_column($pipeIn, 'id'), 'pipe_won' => array_column($pipeWon, 'id'), 'won' => array_column($won, 'id'),
                'value' => array_sum(array_column($won, 'value')), 'fr' => $f['median'], 'sla' => $f['sla_pct'], 'act' => count($act), 'margin' => null];
        if ($money && $won) {
            $m = mx_money($pdo, $row['won']);
            $v = array_sum(array_column($m, 'value'));
            $row['margin'] = $v > 0 ? array_sum(array_column($m, 'gp')) / $v * 100 : null;
        }
        $out[(int)$uid] = $row;
    }
    return $out;
}

// ── operacional ─────────────────────────────────────────────

/** Produção: em execução por etapa, entregas no prazo, kickoff → entrega, rondas, aprovação à 1.ª, tarefas atrasadas e carga. */
function mx_ops(PDO $pdo, array $deals, string $from, string $to): array {
    $stages = mx_stages($pdo);
    $out = ['by_stage' => [], 'delivered' => [], 'on_time' => [], 'k2d' => [], 'rounds' => [], 'first_ok' => null, 'first_n' => 0, 'first_ids' => [],
            'late_by_stage' => [], 'load' => []];
    foreach ($deals as $d) {
        if ($d['phase'] === 'production') {
            $name = $d['stage']['name'] ?? 'Sem etapa';
            $out['by_stage'][$name][] = $d['id'];
        }
        if ($d['delivered_at'] && mx_in($d['delivered_at'], $from, $to)) {
            $out['delivered'][] = $d['id'];
            if ($d['delivery_date'] && substr($d['delivered_at'], 0, 10) <= $d['delivery_date']) $out['on_time'][] = $d['id'];
            if (isset($d['at']['kickoff'])) $out['k2d'][] = mx_days($d['at']['kickoff'], $d['delivered_at']);
        }
    }
    // rondas de revisão usadas vs incluídas, por etapa com rondas (negócios ativos e entregues no período)
    if (db_has_table($pdo, 'deal_rounds')) {
        foreach ($pdo->query('SELECT r.deal_id, r.stage_id, COUNT(*) AS n FROM deal_rounds r GROUP BY r.deal_id, r.stage_id') as $r) {
            $d = $deals[(int)$r['deal_id']] ?? null;
            if (!$d || !($d['phase'] === 'production' || in_array($d['id'], $out['delivered'], true))) continue;
            $inc = (int)($stages[(int)$r['stage_id']]['rounds_included'] ?? 0);
            $out['rounds'][] = ['deal' => $d['id'], 'stage' => $stages[(int)$r['stage_id']]['name'] ?? '—', 'used' => (int)$r['n'], 'inc' => $inc, 'over' => max(0, (int)$r['n'] - $inc)];
        }
    }
    // aprovação na 1.ª ronda (Review): peças aprovadas no período com uma só ronda enviada
    if (db_has_table($pdo, 'cc_rv_items')) {
        $st = $pdo->prepare('SELECT i.id, i.deal_id, (SELECT COUNT(*) FROM cc_rv_rounds r WHERE r.item_id = i.id AND r.submitted_at IS NOT NULL) AS rounds
                               FROM cc_rv_items i WHERE i.approved_at >= ? AND i.approved_at < ?');
        $st->execute([$from, $to]);
        $rows = $st->fetchAll();
        if ($rows) {
            $ok = array_filter($rows, fn($r) => (int)$r['rounds'] <= 1);
            $out['first_ok'] = count($ok) / count($rows) * 100;
            $out['first_n'] = count($rows);
            $out['first_ids'] = array_values(array_unique(array_map(fn($r) => (int)$r['deal_id'], $rows)));
        }
    }
    $mu = mx_member_users($pdo);
    foreach ($pdo->query("SELECT id, stage_id, deal_id, due_date, assignee_id, member_id FROM tasks WHERE status <> 'done'") as $t) {
        $u = (int)($t['assignee_id'] ?: ($mu[(int)$t['member_id']] ?? 0));
        $out['load'][$u]['open'][] = (int)$t['id'];
        if ($t['due_date'] && $t['due_date'] < today_lisbon()) {
            $out['load'][$u]['late'][] = (int)$t['id'];
            $name = $t['stage_id'] ? ($stages[(int)$t['stage_id']]['name'] ?? 'Etapa removida') : 'Sem etapa';
            $out['late_by_stage'][$name][] = (int)$t['id'];
        }
    }
    foreach ($deals as $d) if ($d['phase'] === 'production' && ($d['exec'] ?: $d['owner'])) $out['load'][$d['exec'] ?: $d['owner']]['deals'][] = $d['id'];
    return $out;
}

// ── financeiro (micro) ──────────────────────────────────────

/**
 * Margem por negócio (os ganhos no período), por funil, serviço e cliente; top clientes; prazo médio de
 * recebimento por cliente; faturas em atraso por cliente; custos por categoria contra o período anterior.
 */
function mx_fin(PDO $pdo, array $deals, string $from, string $to, string $pfrom, string $pto): array {
    $won = array_filter($deals, fn($d) => $d['end'] === 'won' && mx_in($d['won_at'], $from, $to));
    $m = mx_money($pdo, array_keys($won));
    $names = $pdo->query('SELECT id, name FROM contacts')->fetchAll(PDO::FETCH_KEY_PAIR);
    $funnels = $pdo->query('SELECT id, name FROM funnels')->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = ['deals' => [], 'funnel' => [], 'service' => [], 'client' => [], 'pay_days' => [], 'overdue' => [], 'cats' => [], 'cats_prev' => []];
    foreach ($won as $id => $d) {
        if (!isset($m[$id])) continue;
        $r = $m[$id] + ['id' => $id, 'code' => $d['code'], 'name' => $d['name']];
        $r['margin'] = $r['value'] > 0 ? $r['gp'] / $r['value'] * 100 : null;
        $out['deals'][$id] = $r;
        foreach (['funnel' => $funnels[$d['fu']] ?? 'Sem funil', 'client' => $names[$d['contact']] ?? ($d['company'] ?: $d['name'])] as $g => $k) {
            $out[$g][$k] ??= ['value' => 0.0, 'gp' => 0.0, 'ids' => []];
            $out[$g][$k]['value'] += $r['value']; $out[$g][$k]['gp'] += $r['gp']; $out[$g][$k]['ids'][] = $id;
        }
    }
    // por serviço: o valor de cada frente (crosselling) ou o negócio inteiro no serviço principal
    if ($won && db_has_table($pdo, 'deal_lines')) {
        $lv = [];
        foreach ($pdo->query('SELECT deal_id, funnel_id, value FROM deal_lines WHERE deal_id IN (' . implode(',', array_keys($won)) . ')') as $l) $lv[(int)$l['deal_id']][(int)$l['funnel_id']] = (float)$l['value'];
        foreach ($out['deals'] as $id => $r) {
            $parts = $lv[$id] ?? [];
            $sum = array_sum($parts);
            if (count($parts) < 2 || $sum <= 0) $parts = [$won[$id]['cur_fu'] ?: $won[$id]['fu'] => $r['value']];
            $sum = array_sum($parts) ?: 1;
            foreach ($parts as $fid => $v) {
                $k = $funnels[$fid] ?? 'Sem serviço';
                $out['service'][$k] ??= ['value' => 0.0, 'gp' => 0.0, 'ids' => []];
                $out['service'][$k]['value'] += $r['value'] * $v / $sum; $out['service'][$k]['gp'] += $r['gp'] * $v / $sum; $out['service'][$k]['ids'][] = $id;
            }
        }
    }
    foreach (['funnel', 'service', 'client'] as $g) uasort($out[$g], fn($a, $b) => $b['gp'] <=> $a['gp']);
    if (db_has_table($pdo, 'fin_docs')) {
        // prazo médio de recebimento (emissão → pagamento) das faturas pagas no período, por cliente
        $st = $pdo->prepare("SELECT id, party_name, contact_id, issue_date, paid_date FROM fin_docs WHERE direction = 'in' AND deleted_at IS NULL AND paid_date >= ? AND paid_date < ? AND issue_date IS NOT NULL");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $k = $names[(int)$r['contact_id']] ?? ($r['party_name'] ?: '—');
            $out['pay_days'][$k]['days'][] = mx_days($r['issue_date'], $r['paid_date']);
            $out['pay_days'][$k]['ids'][] = (int)$r['id'];
        }
        require_once __DIR__ . '/fin.php';
        foreach (fin_receivables($pdo) as $r) {
            if ($r['age'] <= 0) continue;
            $k = $r['contact_name'] ?: $r['name'];
            $out['overdue'][$k] ??= ['value' => 0.0, 'n' => 0, 'max_age' => 0, 'ids' => [], 'deals' => []];
            $out['overdue'][$k]['value'] += $r['gross']; $out['overdue'][$k]['n']++; $out['overdue'][$k]['max_age'] = max($out['overdue'][$k]['max_age'], $r['age']);
            if ($r['doc_id']) $out['overdue'][$k]['ids'][] = $r['doc_id']; elseif ($r['id']) $out['overdue'][$k]['deals'][] = $r['id'];
        }
        uasort($out['overdue'], fn($a, $b) => $b['value'] <=> $a['value']);
        $cats = function (string $a, string $b) use ($pdo) {
            $st = $pdo->prepare("SELECT id, category, base, doc_type FROM fin_docs WHERE direction = 'out' AND deleted_at IS NULL AND status NOT IN ('void','review')
                                   AND COALESCE(issue_date, DATE(created_at)) >= ? AND COALESCE(issue_date, DATE(created_at)) < ?");
            $st->execute([$a, $b]);
            $o = [];
            foreach ($st->fetchAll() as $r) {
                $k = $r['category'] !== '' ? $r['category'] : 'Sem categoria';
                $o[$k]['value'] = ($o[$k]['value'] ?? 0) + ($r['doc_type'] === 'credit' ? -1 : 1) * (float)$r['base'];
                $o[$k]['ids'][] = (int)$r['id'];
            }
            return $o;
        };
        $out['cats'] = $cats($from, $to);
        $out['cats_prev'] = $cats($pfrom, $pto);
        uasort($out['cats'], fn($a, $b) => $b['value'] <=> $a['value']);
    }
    return $out;
}

// ── atenção ─────────────────────────────────────────────────

/** Para onde vai «Abrir» num registo. */
function mx_url(array $d): string {
    if ($d['phase'] === 'lead' || $d['phase'] === 'archived') return '/admin/leads.php?open=' . (int)$d['id'];
    if (function_exists('can') && current_user() && can('negocios')) return '/admin/negocio.php?id=' . (int)$d['id'];
    return '/admin/pipeline.php?' . http_build_query(['f' => $d['cur_fu'] ?? $d['fu'], 'deal' => $d['id']]) . '#negocio';
}

/** «3 h úteis», «5 dias». */
function mx_age(float $hours, bool $business = false): string {
    if ($business) return ($hours < 1 ? max(1, (int)round($hours * 60)) . ' min' : str_replace('.', ',', (string)round($hours, $hours < 10 ? 1 : 0)) . ' h') . ' úteis';
    $d = $hours / 24;
    return $d < 1 ? max(1, (int)round($hours)) . ' h' : ((int)floor($d) === 1 ? '1 dia' : (int)floor($d) . ' dias');
}

/**
 * O que é urgente agora (§29.3): uma lista ordenada por gravidade e antiguidade. Cada linha: key, kind, sev
 * (3 grave · 2 atenção · 1 aviso), title, sub, owner (utilizador), since (instante), age, url, deal.
 * $money: inclui as faturas (só com Financeiro). Os alertas de metas entram com mx_goals_status.
 */
function mx_attention(PDO $pdo, bool $money, ?array $deals = null): array {
    $deals ??= metrics_deals($pdo);
    $now = time();
    $out = [];
    $stages = mx_stages($pdo);
    // 1. leads sem primeira resposta fora do prazo
    foreach ($deals as $d) {
        if ($d['phase'] !== 'lead' || $d['fr_at'] || $d['end'] !== 'open') continue;
        $h = mx_business_seconds($pdo, strtotime($d['created']), $now) / 3600;
        $sla = mx_sla($pdo, $d['ch']);
        if ($h <= $sla) continue;
        $out[] = ['key' => 'sla:' . $d['id'], 'kind' => 'sla', 'sev' => 3, 'title' => 'Lead sem primeira resposta · ' . $d['name'],
                  'sub' => (LEAD_ORIGIN[$d['ch']] ?? 'Manual') . ' · prazo ' . mx_age($sla, true) . ' · passou há ' . mx_age($h - $sla, true),
                  'owner' => $d['owner'], 'since' => strtotime($d['created']), 'age' => mx_age($h, true), 'url' => mx_url($d), 'deal' => $d['id']];
    }
    // 2. parados numa etapa acima do normal (limite do funil, ou média histórica × 1,5)
    $avg = [];
    foreach ($deals as $d) {
        $keys = array_keys(MX_STEPS);
        foreach ($keys as $i => $k) {
            $nk = $keys[$i + 1] ?? null;
            if ($nk && isset($d['at'][$k], $d['at'][$nk])) $avg[$k][] = mx_days($d['at'][$k], $d['at'][$nk]);
        }
    }
    $fbDays = max(1, (int)setting($pdo, 'feedback_days', 7));
    $staleDef = max(1, (int)setting($pdo, 'deal_stale_days', 10));
    $lastTouch = [];
    foreach ($pdo->query("SELECT deal_id, MAX(happened_at) FROM interactions WHERE deal_id IS NOT NULL GROUP BY deal_id")->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $t) $lastTouch[(int)$id] = $t;
    foreach ($deals as $d) {
        if ($d['phase'] !== 'pipeline' || $d['end'] !== 'open') continue;
        $step = mx_stage_step($d['stage']) ?? 'xray';
        $days = mx_days($d['entered'], date('Y-m-d H:i:s'));
        if ($step === 'feedback') {
            // 3. propostas à espera de resposta há mais de N dias sem seguimento
            $touch = $lastTouch[$d['id']] ?? null;
            if ($days > $fbDays && (!$touch || $touch < $d['entered'])) {
                $out[] = ['key' => 'fb:' . $d['id'], 'kind' => 'feedback', 'sev' => 2, 'title' => 'Proposta sem seguimento · ' . ($d['code'] ? $d['code'] . ' · ' : '') . $d['name'],
                          'sub' => 'Em «Aguardar feedback» há ' . mx_age($days * 24) . ' sem interação registada (limite ' . $fbDays . ' dias)',
                          'owner' => $d['owner'], 'since' => strtotime($d['entered']), 'age' => mx_age($days * 24), 'url' => mx_url($d), 'deal' => $d['id']];
            }
            continue;
        }
        $lim = $d['stage']['max_days'] ?? null;
        $why = 'limite da etapa';
        if (!$lim) {
            $hist = $avg[$step] ?? [];
            if (count($hist) >= 5) { $lim = max(1.0, mx_avg($hist) * 1.5); $why = 'normal: ' . str_replace('.', ',', (string)round(mx_avg($hist), 1)) . ' dias × 1,5'; }
            else { $lim = $staleDef; $why = 'sem histórico: ' . $staleDef . ' dias'; }
        }
        if ($days > $lim) {
            $out[] = ['key' => 'stall:' . $d['id'] . ':' . $d['stage_id'], 'kind' => 'stalled', 'sev' => $days > $lim * 2 ? 3 : 2,
                      'title' => 'Parado em ' . ($d['stage']['name'] ?? MX_STEPS[$step]) . ' · ' . ($d['code'] ? $d['code'] . ' · ' : '') . $d['name'],
                      'sub' => 'Há ' . mx_age($days * 24) . ' nesta etapa (' . $why . ')', 'owner' => $d['owner'], 'since' => strtotime($d['entered']),
                      'age' => mx_age($days * 24), 'url' => mx_url($d), 'deal' => $d['id']];
        }
    }
    // leads paradas no estado (prazo do estado em atraso), as que já tiveram resposta
    $hours = lead_deadline_hours($pdo);
    foreach ($deals as $d) {
        if ($d['phase'] !== 'lead' || !$d['fr_at'] || $d['end'] !== 'open') continue;
        $c = lead_clock(['phase' => 'lead', 'is_quarantined' => 0, 'lead_state' => $d['lead_state'], 'state_since' => $d['state_since'], 'created_at' => $d['created']], $hours, $now);
        if (!$c || $c['level'] !== 'late') continue;
        $out[] = ['key' => 'lst:' . $d['id'] . ':' . $c['start'], 'kind' => 'stalled', 'sev' => 2, 'title' => 'Lead parada · ' . $d['name'],
                  'sub' => (LEAD_STATE[$d['lead_state']] ?? '') . ' · prazo do estado passou há ' . lead_fmt_span(max(60, $now - $c['due'])),
                  'owner' => $d['owner'], 'since' => $c['start'], 'age' => lead_fmt_span(max(60, $now - $c['start'])), 'url' => mx_url($d), 'deal' => $d['id']];
    }
    // 4. gates bloqueados (tarefa de gate atrasada) e tarefas atrasadas
    $mu = mx_member_users($pdo);
    $st = $pdo->query("SELECT t.id, t.title, t.due_date, t.is_gate, t.deal_id, t.assignee_id, t.member_id, d.code FROM tasks t LEFT JOIN deals d ON d.id = t.deal_id
                        WHERE t.status <> 'done' AND t.due_date IS NOT NULL AND t.due_date < '" . today_lisbon() . "' ORDER BY t.due_date LIMIT 400");
    foreach ($st->fetchAll() as $t) {
        $days = mx_days($t['due_date'], today_lisbon());
        $gate = (int)$t['is_gate'] === 1;
        $out[] = ['key' => 'task:' . $t['id'], 'kind' => $gate ? 'gate' : 'task', 'sev' => $gate ? 3 : ($days > 7 ? 2 : 1),
                  'title' => ($gate ? 'Gate bloqueado · ' : 'Tarefa atrasada · ') . $t['title'],
                  'sub' => ($t['code'] ? $t['code'] . ' · ' : '') . 'venceu a ' . date('d/m', strtotime($t['due_date'])), 'owner' => (int)($t['assignee_id'] ?: ($mu[(int)$t['member_id']] ?? 0)),
                  'since' => strtotime($t['due_date']), 'age' => mx_age($days * 24), 'url' => '/admin/calendar.php?edit=' . (int)$t['id'], 'deal' => $t['deal_id'] ? (int)$t['deal_id'] : null];
    }
    // 5. kickoffs sem data de entrega
    foreach ($deals as $d) {
        if ($d['phase'] !== 'production' || $d['delivery_date']) continue;
        $days = mx_days($d['at']['kickoff'] ?? $d['entered'], date('Y-m-d H:i:s'));
        if ($days < 2) continue;
        $out[] = ['key' => 'kod:' . $d['id'], 'kind' => 'kickoff', 'sev' => 1, 'title' => 'Kickoff sem data de entrega · ' . ($d['code'] ? $d['code'] . ' · ' : '') . $d['name'],
                  'sub' => 'Em produção há ' . mx_age($days * 24) . ' sem data de entrega', 'owner' => $d['exec'] ?: $d['owner'],
                  'since' => strtotime($d['at']['kickoff'] ?? $d['entered']), 'age' => mx_age($days * 24), 'url' => mx_url($d), 'deal' => $d['id']];
    }
    // 6. reviews à espera do cliente há mais de N dias
    if (db_has_table($pdo, 'cc_rv_items')) {
        $rvDays = max(1, (int)setting($pdo, 'review_wait_days', 5));
        $st = $pdo->query("SELECT i.id, i.title, i.deal_id, i.updated_at, d.code, d.name, d.owner_id, d.exec_owner_id, d.phase, d.funnel_id,
                                  (SELECT MAX(v.sent_at) FROM cc_rv_versions v WHERE v.item_id = i.id) AS sent_at
                             FROM cc_rv_items i JOIN deals d ON d.id = i.deal_id WHERE i.status = 'sent'");
        foreach ($st->fetchAll() as $r) {
            $since = $r['sent_at'] ?: $r['updated_at'];
            $days = mx_days($since, date('Y-m-d H:i:s'));
            if ($days <= $rvDays) continue;
            $out[] = ['key' => 'rv:' . $r['id'], 'kind' => 'review', 'sev' => 2, 'title' => 'Review à espera do cliente · ' . $r['title'],
                      'sub' => $r['code'] . ' · ' . $r['name'] . ' · enviada há ' . mx_age($days * 24), 'owner' => (int)($r['exec_owner_id'] ?: $r['owner_id']),
                      'since' => strtotime($since), 'age' => mx_age($days * 24), 'url' => '/admin/criativo.php?rv=' . (int)$r['id'], 'deal' => (int)$r['deal_id']];
        }
    }
    // 7. faturas em atraso e pedidas ao contabilista sem resposta (só Financeiro)
    if ($money && db_has_table($pdo, 'fin_docs')) {
        require_once __DIR__ . '/fin.php';
        $owner = fin_owner_id($pdo) ?? 0;
        foreach (fin_receivables($pdo) as $r) {
            if ($r['age'] <= 0) continue;
            $out[] = ['key' => 'inv:' . ($r['doc_id'] ?: 'd' . $r['id']), 'kind' => 'invoice', 'sev' => $r['age'] > 60 ? 3 : 2, 'title' => 'Fatura em atraso · ' . ($r['legacy'] ? ($r['code'] ?: 'negócio') : $r['number']) . ' · ' . ($r['contact_name'] ?: $r['name']),
                      'sub' => eur0((float)$r['gross']) . ' · ' . $r['age'] . ' dias de atraso', 'owner' => $owner, 'since' => strtotime($r['ref']),
                      'age' => mx_age($r['age'] * 24), 'url' => $r['url'], 'deal' => $r['id'] ?: null];
        }
        $reqDays = max(1, (int)setting($pdo, 'invoice_request_days', 3));
        $st = $pdo->query("SELECT f.id, f.req_code, f.req_sent_at, f.party_name, f.deal_id, f.created_by, d.code FROM fin_docs f LEFT JOIN deals d ON d.id = f.deal_id
                            WHERE f.status = 'requested' AND f.deleted_at IS NULL AND f.req_sent_at IS NOT NULL");
        foreach ($st->fetchAll() as $r) {
            $days = mx_days($r['req_sent_at'], date('Y-m-d H:i:s'));
            if ($days <= $reqDays) continue;
            $out[] = ['key' => 'req:' . $r['id'], 'kind' => 'invoice', 'sev' => 2, 'title' => 'Fatura pedida sem resposta · ' . ($r['code'] ?: $r['party_name']),
                      'sub' => 'Pedido ' . $r['req_code'] . ' ao contabilista há ' . mx_age($days * 24), 'owner' => (int)($r['created_by'] ?: $owner),
                      'since' => strtotime($r['req_sent_at']), 'age' => mx_age($days * 24), 'url' => '/admin/financeiro.php?tab=receber&doc=' . (int)$r['id'], 'deal' => $r['deal_id'] ? (int)$r['deal_id'] : null];
        }
    }
    // 8. metas em risco
    foreach (mx_goals_status($pdo, $deals, $money) as $g) {
        if (!in_array($g['state'], ['risk', 'behind'], true) || $g['closed']) continue;
        $out[] = ['key' => 'goal:' . $g['id'] . ':' . $g['state'], 'kind' => 'goal', 'sev' => $g['state'] === 'behind' ? 3 : 2,
                  'title' => 'Meta ' . ($g['state'] === 'behind' ? 'atrás' : 'em risco') . ' · ' . $g['label'] . ' · ' . $g['scope_label'],
                  'sub' => $g['progress'], 'owner' => $g['scope'] === 'user' ? $g['scope_id'] : 0, 'since' => strtotime($g['from']), 'age' => $g['period_label'],
                  'url' => '/admin/relatorios.php?tab=metas', 'deal' => null];
    }
    usort($out, fn($a, $b) => [$b['sev'], $a['since']] <=> [$a['sev'], $b['since']]);
    return $out;
}

// ── metas ───────────────────────────────────────────────────

const MX_GOAL_METRICS = [
    'leads'      => ['Leads entradas', 'n'],
    'abordadas'  => ['Leads abordadas', 'n'],
    'pipeline'   => ['Passadas a pipeline', 'n'],
    'propostas'  => ['Propostas', 'n'],
    'kickoffs'   => ['Kickoffs', 'n'],
    'valor'      => ['Valor ganho', 'eur'],
    'taxa_pipe'  => ['% lead → pipeline', 'pct'],
    'taxa_kick'  => ['% pipeline → kickoff', 'pct'],
];
/** A etapa de cada meta de contagem. */
const MX_GOAL_STEP = ['leads' => 'lead', 'abordadas' => 'abordada', 'pipeline' => 'xray', 'propostas' => 'proposta', 'kickoffs' => 'kickoff'];

/** O início e o fim de um período de meta (mês ou trimestre) que contém $date. */
function mx_goal_bounds(string $period, string $date): array {
    $d = new DateTimeImmutable(substr($date, 0, 10));
    if ($period === 'quarter') {
        $a = $d->setDate((int)$d->format('Y'), intdiv((int)$d->format('n') - 1, 3) * 3 + 1, 1);
        return [$a->format('Y-m-d'), $a->modify('+3 months')->format('Y-m-d')];
    }
    $a = $d->modify('first day of this month');
    return [$a->format('Y-m-d'), $a->modify('+1 month')->format('Y-m-d')];
}

function mx_goal_period_label(string $period, string $start): string {
    $d = new DateTimeImmutable($start);
    return $period === 'quarter' ? (intdiv((int)$d->format('n') - 1, 3) + 1) . '.º trim. ' . $d->format('Y') : month_name((int)$d->format('n')) . ' ' . $d->format('Y');
}

/** O real de uma métrica de meta num período e âmbito: [valor, ids]. */
function mx_goal_actual(array $deals, string $metric, string $scope, int $scopeId, string $from, string $to): array {
    $deals = $scope === 'funnel' ? array_filter($deals, fn($d) => $d['fu'] === $scopeId) : ($scope === 'user' ? array_filter($deals, fn($d) => $d['owner'] === $scopeId) : $deals);
    $cnt = function (string $step) use ($deals, $from, $to) {
        return array_column(array_filter($deals, fn($d) => isset($d['at'][$step]) && mx_in($d['at'][$step], $from, $to)), 'id');
    };
    if (isset(MX_GOAL_STEP[$metric])) {
        $ids = $metric === 'kickoffs' ? array_column(array_filter($deals, fn($d) => $d['end'] === 'won' && mx_in($d['won_at'], $from, $to)), 'id') : $cnt(MX_GOAL_STEP[$metric]);
        return [count($ids), $ids];
    }
    if ($metric === 'valor') {
        $w = array_filter($deals, fn($d) => $d['end'] === 'won' && mx_in($d['won_at'], $from, $to));
        return [array_sum(array_column($w, 'value')), array_column($w, 'id')];
    }
    if ($metric === 'taxa_pipe') { $a = $cnt('xray'); $b = $cnt('lead'); return [$b ? count($a) / count($b) * 100 : 0.0, $a]; }
    if ($metric === 'taxa_kick') {
        $a = array_column(array_filter($deals, fn($d) => $d['end'] === 'won' && mx_in($d['won_at'], $from, $to)), 'id'); $b = $cnt('xray');
        return [$b ? count($a) / count($b) * 100 : 0.0, $a];
    }
    return [0, []];
}

/** As taxas reais dos últimos 6 meses entre etapas (para a meta em cascata): [de→para => [taxa, a, b]]. */
function mx_cascade_rates(array $deals, string $scope = 'company', int $scopeId = 0): array {
    $to = today_lisbon();
    $from = (new DateTimeImmutable($to))->modify('-6 months')->format('Y-m-d');
    $n = [];
    foreach (['leads', 'abordadas', 'pipeline', 'propostas', 'kickoffs'] as $m) $n[$m] = mx_goal_actual($deals, $m, $scope, $scopeId, $from, $to)[0];
    $out = [];
    $chain = ['leads', 'abordadas', 'pipeline', 'propostas', 'kickoffs'];
    for ($i = 0; $i < 4; $i++) {
        [$a, $b] = [$chain[$i], $chain[$i + 1]];
        $out[$b] = ['rate' => $n[$a] > 0 ? min(1.0, $n[$b] / $n[$a]) : null, 'from' => $n[$a], 'to' => $n[$b], 'prev' => $a];
    }
    return ['rates' => $out, 'counts' => $n, 'from' => $from];
}

/** A cascata: do objetivo de kickoffs para trás, com as taxas reais. [métrica => número sugerido]. */
function mx_cascade(array $rates, int $kickoffs): array {
    $out = ['kickoffs' => $kickoffs];
    $cur = $kickoffs;
    foreach (['kickoffs', 'propostas', 'pipeline', 'abordadas'] as $m) {
        $r = $rates['rates'][$m]['rate'] ?? null;
        $prev = $rates['rates'][$m]['prev'];
        $cur = $r ? (int)ceil($cur / $r) : null;
        $out[$prev] = $cur;
        if ($cur === null) break;
    }
    return $out;
}

/**
 * O estado das metas: as do período em curso (e as que acabaram há pouco). Cada uma com o real, o ritmo
 * esperado («dia 18 de 30 — 60%»), a previsão de fim de período e o estado: done (cumprida) · ok (em linha) ·
 * risk (em risco) · behind (atrás) · missed (não cumprida, período fechado).
 */
function mx_goals_status(PDO $pdo, array $deals, bool $money, ?string $at = null, bool $withClosed = false): array {
    if (!db_has_table($pdo, 'metric_goals')) return [];
    $at ??= today_lisbon();
    $rows = $pdo->query('SELECT * FROM metric_goals ORDER BY period_start DESC, scope, scope_id, metric')->fetchAll();
    $funnels = $pdo->query('SELECT id, name FROM funnels')->fetchAll(PDO::FETCH_KEY_PAIR);
    $people = mx_people($pdo);
    $out = [];
    foreach ($rows as $g) {
        if (!isset(MX_GOAL_METRICS[$g['metric']])) continue;
        if ($g['metric'] === 'valor' && !$money) continue;
        [$from, $to] = mx_goal_bounds($g['period'], $g['period_start']);
        $closed = $at >= $to;
        if (!$withClosed && ($closed || $at < $from)) continue;
        [$val, $ids] = mx_goal_actual($deals, $g['metric'], $g['scope'], (int)$g['scope_id'], $from, $to);
        $total = (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days;
        $day = $closed ? $total : max(1, (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($at))->days + 1);
        $frac = min(1.0, $day / max(1, $total));
        $target = (float)$g['target'];
        [$label, $unit] = MX_GOAL_METRICS[$g['metric']];
        $isRate = $unit === 'pct';
        $expected = $isRate ? $target : $target * $frac;
        $forecast = $isRate ? $val : ($frac > 0 ? $val / $frac : $val);
        $ratio = $expected > 0 ? $val / $expected : 1.0;
        if ($val >= $target && $target > 0 && ($isRate ? $closed : true)) $state = 'done';
        elseif ($closed) $state = 'missed';
        elseif ($isRate) $state = $val >= $target ? 'ok' : ($val >= $target * 0.85 ? 'risk' : 'behind');
        else $state = $ratio >= 0.95 ? 'ok' : ($ratio >= 0.8 ? 'risk' : 'behind');
        $fmt = fn(float $v) => $unit === 'eur' ? eur0($v) : ($isRate ? str_replace('.', ',', (string)round($v, 1)) . '%' : (string)(int)round($v));
        $pace = $isRate ? '' : 'dia ' . $day . ' de ' . $total . ' — ' . (int)round($frac * 100) . '% do período; ';
        $progress = $pace . mb_strtolower($label) . ' ' . $fmt($val) . '/' . $fmt($target) . ($isRate ? '' : ' (' . ($target > 0 ? (int)round($val / $target * 100) : 0) . '%)')
            . ' — ' . ['done' => 'cumprida', 'ok' => 'em linha', 'risk' => 'ligeiramente atrás', 'behind' => 'atrás', 'missed' => 'não cumprida'][$state];
        $out[] = [
            'id' => (int)$g['id'], 'metric' => $g['metric'], 'label' => $label, 'unit' => $unit, 'scope' => $g['scope'], 'scope_id' => (int)$g['scope_id'],
            'scope_label' => $g['scope'] === 'funnel' ? ($funnels[(int)$g['scope_id']] ?? 'Funil') : ($g['scope'] === 'user' ? ($people[(int)$g['scope_id']] ?? 'Pessoa') : 'Empresa'),
            'period' => $g['period'], 'from' => $from, 'to' => $to, 'period_label' => mx_goal_period_label($g['period'], $from), 'is_auto' => (int)$g['is_auto'],
            'target' => $target, 'value' => (float)$val, 'ids' => $ids, 'expected' => $expected, 'forecast' => $forecast, 'frac' => $frac, 'day' => $day, 'days' => $total,
            'state' => $state, 'closed' => $closed, 'progress' => $progress, 'fmt' => $fmt,
        ];
    }
    return $out;
}

/** Grava (ou atualiza) uma meta. Só superadmin e admins (verificado por quem chama). */
function mx_goal_save(PDO $pdo, string $scope, int $scopeId, string $period, string $start, string $metric, float $target, bool $auto, ?int $by): void {
    if (!in_array($scope, ['company', 'funnel', 'user'], true) || !in_array($period, ['month', 'quarter'], true) || !isset(MX_GOAL_METRICS[$metric])) throw new RuntimeException('Meta inválida.');
    if ($scope === 'company') $scopeId = 0;
    [$from] = mx_goal_bounds($period, $start);
    if ($target <= 0) {
        $pdo->prepare('DELETE FROM metric_goals WHERE scope = ? AND scope_id = ? AND period = ? AND period_start = ? AND metric = ?')->execute([$scope, $scopeId, $period, $from, $metric]);
        return;
    }
    $sql = db_is_sqlite($pdo)
        ? 'INSERT INTO metric_goals (scope, scope_id, period, period_start, metric, target, is_auto, created_by, updated_at) VALUES (?,?,?,?,?,?,?,?,?)
           ON CONFLICT(scope, scope_id, period, period_start, metric) DO UPDATE SET target = excluded.target, is_auto = excluded.is_auto, updated_at = excluded.updated_at'
        : 'INSERT INTO metric_goals (scope, scope_id, period, period_start, metric, target, is_auto, created_by, updated_at) VALUES (?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE target = VALUES(target), is_auto = VALUES(is_auto), updated_at = VALUES(updated_at)';
    $pdo->prepare($sql)->execute([$scope, $scopeId, $period, $from, $metric, round($target, 2), $auto ? 1 : 0, $by, date('Y-m-d H:i:s')]);
}

// ── fotografia diária (cron) ────────────────────────────────

/** As métricas principais de um dia, para a empresa e por funil (metrics_daily). Devolve quantas linhas. */
function metrics_snapshot(PDO $pdo, string $day, ?array $deals = null): int {
    $deals ??= metrics_deals($pdo);
    $to = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    $f30 = (new DateTimeImmutable($day))->modify('-29 days')->format('Y-m-d');
    $rows = [];
    $scopes = ['' => [0 => $deals]];
    foreach ($deals as $d) if ($d['fu']) $scopes['funnel'][$d['fu']][$d['id']] = $d;
    foreach ($scopes as $scope => $set) {
        foreach ($set as $sid => $ds) {
            $v = [];
            foreach (['lead' => 'leads', 'abordada' => 'abordadas', 'xray' => 'pipeline', 'proposta' => 'propostas'] as $step => $k) {
                $v[$k] = count(array_filter($ds, fn($d) => isset($d['at'][$step]) && mx_in($d['at'][$step], $day, $to)));
            }
            $won = array_filter($ds, fn($d) => $d['end'] === 'won' && mx_in($d['won_at'], $day, $to));
            $v['kickoffs'] = count($won);
            $v['valor_ganho'] = array_sum(array_column($won, 'value'));
            $v['perdidos'] = count(array_filter($ds, fn($d) => $d['end'] === 'lost' && mx_in($d['lost_at'], $day, $to)));
            // o estado no fim do dia (o que estava aberto nessa noite)
            $open = array_filter($ds, fn($d) => $d['reach'] >= 2 && $d['at']['xray'] < $to && !($d['end'] === 'won' && $d['won_at'] < $to) && !($d['end'] === 'lost' && $d['lost_at'] < $to));
            $v['pipeline_aberto'] = array_sum(array_column($open, 'value'));
            $w = 0.0;
            foreach ($open as $d) {
                $r = 0;
                foreach (['proposta' => 3, 'feedback' => 4] as $s => $i) if (isset($d['at'][$s]) && $d['at'][$s] < $to) $r = $i;
                $w += $d['value'] * mx_prob($pdo, $r === 4 ? 'feedback' : ($r === 3 ? 'proposta' : 'xray')) / 100;
            }
            $v['pipeline_ponderado'] = round($w, 2);
            $v['leads_abertas'] = count(array_filter($ds, fn($d) => $d['created'] < $to && $d['reach'] < 2 && !($d['end'] === 'lost' && $d['lost_at'] < $to)));
            if ($scope === '') {
                $s = mx_fr_stats(mx_first_responses($pdo, $ds, $f30, $to));
                $v['sla_30d'] = $s['sla_pct'] ?? 0;
                $v['resposta_mediana_30d'] = $s['median'] ?? 0;
            }
            foreach ($v as $k => $x) $rows[] = [$day, $k, $scope, (int)$sid, (float)$x];
        }
    }
    $sql = db_is_sqlite($pdo) ? 'INSERT OR REPLACE INTO metrics_daily (day, mkey, scope, scope_id, value) VALUES (?,?,?,?,?)'
                              : 'REPLACE INTO metrics_daily (day, mkey, scope, scope_id, value) VALUES (?,?,?,?,?)';
    $st = $pdo->prepare($sql);
    foreach ($rows as $r) $st->execute($r);
    return count($rows);
}

/** Uma série da fotografia diária: [dia => valor]. */
function metrics_series(PDO $pdo, string $key, string $from, string $to, string $scope = '', int $scopeId = 0): array {
    if (!db_has_table($pdo, 'metrics_daily')) return [];
    $st = $pdo->prepare('SELECT day, value FROM metrics_daily WHERE mkey = ? AND scope = ? AND scope_id = ? AND day >= ? AND day < ? ORDER BY day');
    $st->execute([$key, $scope, $scopeId, $from, $to]);
    return array_map('floatval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * O que o cron faz (de 5 em 5 minutos, leve): a fotografia de ontem uma vez por dia (e, da primeira vez, os
 * últimos 90 dias), e à segunda a partir das 9h o resumo semanal. Devolve uma linha para o registo do cron.
 */
function metrics_tick(PDO $pdo): string {
    if (!metrics_ready($pdo)) return '';
    $today = today_lisbon();
    $last = (string)setting($pdo, 'metrics_snap_day', '');
    $msg = [];
    if ($last < $today) {
        $deals = metrics_deals($pdo, true);
        $start = $last !== '' ? (new DateTimeImmutable($last))->modify('+1 day') : (new DateTimeImmutable($today))->modify('-90 days');
        $n = 0; $days = 0;
        for ($d = $start; $d->format('Y-m-d') < $today && $days < 120; $d = $d->modify('+1 day'), $days++) $n += metrics_snapshot($pdo, $d->format('Y-m-d'), $deals);
        metrics_setting_set($pdo, 'metrics_snap_day', $today);
        $msg[] = 'Fotografia diária: ' . $days . ' dia(s), ' . $n . ' valores';
    }
    $w = metrics_weekly_tick($pdo);
    if ($w !== '') $msg[] = $w;
    return $msg ? 'Métricas: ' . implode(' · ', $msg) : '';
}

function metrics_setting_set(PDO $pdo, string $k, string $v): void {
    $sql = db_is_sqlite($pdo) ? 'INSERT OR REPLACE INTO settings (skey, svalue) VALUES (?, ?)' : 'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)';
    $pdo->prepare($sql)->execute([$k, $v]);
    settings($pdo, true);
}

// ── resumo semanal (segunda às 9h) ──────────────────────────

/** Os números de uma semana (ou de qualquer intervalo) para o resumo: contagens, valor, SLA e resposta. */
function mx_week_numbers(PDO $pdo, array $deals, string $from, string $to): array {
    $n = [];
    foreach (['leads', 'abordadas', 'pipeline', 'propostas', 'kickoffs', 'valor'] as $m) $n[$m] = mx_goal_actual($deals, $m, 'company', 0, $from, $to)[0];
    $s = mx_fr_stats(mx_first_responses($pdo, $deals, $from, $to));
    $n['sla'] = $s['sla_pct']; $n['resposta'] = $s['median'];
    $n['perdidos'] = count(array_filter($deals, fn($d) => $d['end'] === 'lost' && mx_in($d['lost_at'], $from, $to)));
    return $n;
}

/** As linhas «melhorou / piorou» entre duas semanas. */
function mx_week_changes(array $now, array $prev, bool $money): array {
    $labels = ['leads' => 'Leads entradas', 'abordadas' => 'Leads abordadas', 'pipeline' => 'Passadas a pipeline', 'propostas' => 'Propostas', 'kickoffs' => 'Kickoffs',
               'perdidos' => 'Perdidos', 'valor' => 'Valor ganho', 'sla' => '% dentro do prazo de resposta', 'resposta' => 'Mediana da primeira resposta'];
    $up = []; $down = [];
    foreach ($labels as $k => $l) {
        if ($k === 'valor' && !$money) continue;
        $a = $now[$k]; $b = $prev[$k];
        if ($a === null || $b === null || abs((float)$a - (float)$b) < 0.01) continue;
        $good = in_array($k, ['perdidos', 'resposta'], true) ? $a < $b : $a > $b;
        $fmt = fn($v) => $k === 'valor' ? eur0((float)$v) : ($k === 'sla' ? (int)round((float)$v) . '%' : ($k === 'resposta' ? str_replace('.', ',', (string)round((float)$v, 1)) . ' h úteis' : (string)(int)$v));
        $line = $l . ': ' . $fmt($a) . ' (antes ' . $fmt($b) . ')';
        if ($good) $up[] = $line; else $down[] = $line;
    }
    return [$up, $down];
}

/** As 3 coisas a fazer: pela IA a partir do separador Atenção (se estiver configurada), senão as 3 mais graves. */
function mx_week_todo(PDO $pdo, array $items, array $numbers, bool $ai = true): array {
    $items = array_slice($items, 0, 25);
    if (!$items) return [['Manter o ritmo: nada urgente no separador Atenção.'], false];
    if ($ai) {
        try {
            require_once __DIR__ . '/ai.php';
            if (ai_config()) {
                $list = implode("\n", array_map(fn($i) => '- [' . ['', 'aviso', 'atenção', 'grave'][$i['sev']] . '] ' . $i['title'] . ' — ' . $i['sub'] . ' (há ' . $i['age'] . ')', $items));
                $prompt = "Lista do separador Atenção dos Relatórios da topper. (estúdio criativo em Portugal):\n$list\n\nNúmeros da semana passada: "
                    . json_encode($numbers, JSON_UNESCAPED_UNICODE) . "\n\nEscreve exatamente 3 coisas a fazer esta semana, por ordem de impacto, em português de Portugal, "
                    . "cada uma numa linha começada por «1.», «2.», «3.», com no máximo 25 palavras, concretas (quem/o quê), sem rodeios nem introdução.";
                $r = ai_call($pdo, ['max_tokens' => 500, 'messages' => [['role' => 'user', 'content' => $prompt]]], 'report');
                $lines = [];
                foreach (preg_split('/\r?\n/', implode("\n", ai_texts($r['_content'] ?? $r['content'] ?? []))) as $l) {
                    if (preg_match('/^\s*[1-3][.)]\s*(.+)$/u', $l, $m)) $lines[] = trim($m[1]);
                }
                if (count($lines) >= 3) return [array_slice($lines, 0, 3), true];
            }
        } catch (Throwable $e) {
            error_log('mx_week_todo: ' . $e->getMessage());
        }
    }
    // sem IA: as três mais graves, uma por tipo
    $out = []; $kinds = [];
    $verb = ['sla' => 'Responder já a', 'stalled' => 'Desbloquear', 'feedback' => 'Fazer seguimento de', 'gate' => 'Resolver o gate de', 'task' => 'Fechar a tarefa atrasada',
             'kickoff' => 'Marcar a data de entrega de', 'review' => 'Lembrar o cliente da review', 'invoice' => 'Cobrar', 'goal' => 'Recuperar a meta'];
    foreach ($items as $i) {
        if (isset($kinds[$i['kind']])) continue;
        $kinds[$i['kind']] = true;
        $n = count(array_filter($items, fn($x) => $x['kind'] === $i['kind']));
        $out[] = ($verb[$i['kind']] ?? 'Ver') . ': ' . preg_replace('/^[^·]*·\s*/u', '', $i['title']) . ($n > 1 ? ' (e mais ' . ($n - 1) . ' do mesmo tipo)' : '') . '.';
        if (count($out) === 3) break;
    }
    return [$out, false];
}

/** O texto do resumo (equipa ou pessoa). */
function mx_week_body(array $n, array $prev, array $goals, array $todo, bool $money, string $who = ''): string {
    [$up, $down] = mx_week_changes($n, $prev, $money);
    $t = ($who !== '' ? 'Resumo de ' . $who : 'Resumo da equipa') . " · semana passada\n\n";
    $t .= 'Leads ' . $n['leads'] . ' · abordadas ' . $n['abordadas'] . ' · pipeline ' . $n['pipeline'] . ' · propostas ' . $n['propostas'] . ' · kickoffs ' . $n['kickoffs']
        . ($money ? ' · valor ganho ' . eur0((float)$n['valor']) : '') . ($n['sla'] !== null ? ' · ' . (int)round($n['sla']) . '% respondidas dentro do prazo' : '') . "\n";
    if ($goals) {
        $t .= "\nMetas\n";
        foreach ($goals as $g) $t .= '· ' . $g['label'] . ' (' . $g['scope_label'] . '): ' . $g['progress'] . "\n";
    }
    if ($up) $t .= "\nMelhorou\n· " . implode("\n· ", $up) . "\n";
    if ($down) $t .= "\nPiorou\n· " . implode("\n· ", $down) . "\n";
    $t .= "\n3 coisas a fazer esta semana\n";
    foreach (array_values($todo) as $i => $l) $t .= ($i + 1) . '. ' . $l . "\n";
    return rtrim($t);
}

/**
 * À segunda, a partir das 9h (Lisboa), uma vez: o resumo da equipa (com as 3 coisas da IA) e o de cada pessoa
 * (os seus números e as suas 3), guardados em metrics_weekly; aparece nas notificações e no separador Atenção;
 * vai por email aos sócios (e a quem o pediu no Perfil). $force: gera já (para testar).
 */
function metrics_weekly_tick(PDO $pdo, bool $force = false, ?string $now = null): string {
    if (!db_has_table($pdo, 'metrics_weekly')) return '';
    $tz = new DateTimeZone('Europe/Lisbon');
    $t = new DateTimeImmutable($now ?? 'now', $tz);
    if (!$force && ((int)$t->format('N') !== 1 || (int)$t->format('G') < 9)) return '';
    $week = $t->modify('-' . ((int)$t->format('N') - 1) . ' days')->format('Y-m-d');
    $st = $pdo->prepare('SELECT COUNT(*) FROM metrics_weekly WHERE week = ? AND user_id = 0');
    $st->execute([$week]);
    if ((int)$st->fetchColumn() && !$force) return '';
    $from = (new DateTimeImmutable($week))->modify('-7 days')->format('Y-m-d');
    $pfrom = (new DateTimeImmutable($week))->modify('-14 days')->format('Y-m-d');
    $deals = metrics_deals($pdo, true);
    $items = mx_attention($pdo, false, $deals);
    $num = mx_week_numbers($pdo, $deals, $from, $week);
    $prev = mx_week_numbers($pdo, $deals, $pfrom, $from);
    // guardado sem euros (todos o veem); os sócios com Financeiro recebem por email a versão com valores
    $goals = array_values(array_filter(mx_goals_status($pdo, $deals, false, $week), fn($g) => $g['scope'] !== 'user'));
    $goalsMoney = array_values(array_filter(mx_goals_status($pdo, $deals, true, $week), fn($g) => $g['scope'] !== 'user'));
    [$todo, $byAi] = mx_week_todo($pdo, $items, ['esta' => $num, 'anterior' => $prev]);
    $save = $pdo->prepare(db_is_sqlite($pdo) ? 'INSERT OR REPLACE INTO metrics_weekly (week, user_id, body, todo, by_ai, created_at) VALUES (?,?,?,?,?,?)'
                                             : 'REPLACE INTO metrics_weekly (week, user_id, body, todo, by_ai, created_at) VALUES (?,?,?,?,?,?)');
    $body = mx_week_body($num, $prev, $goals, $todo, false);
    $save->execute([$week, 0, $body, json_encode($todo, JSON_UNESCAPED_UNICODE), $byAi ? 1 : 0, date('Y-m-d H:i:s')]);
    $sent = 0; $people = 0;
    require_once __DIR__ . '/team.php';
    $users = $pdo->query('SELECT * FROM users WHERE is_active = 1')->fetchAll();
    foreach ($users as $u) {
        $grid = perm_grid($pdo, $u);
        if (($grid['relatorios']['level'] ?? 0) < PERM_VIEW) continue;
        $uid = (int)$u['id'];
        $money = ($grid['financeiro']['level'] ?? 0) >= PERM_VIEW;
        $mine = array_filter($deals, fn($d) => $d['owner'] === $uid);
        $mi = array_values(array_filter($items, fn($i) => $i['owner'] === $uid));
        [$myTodo] = mx_week_todo($pdo, $mi, [], false);
        $pn = mx_week_numbers($pdo, $mine, $from, $week);
        $pp = mx_week_numbers($pdo, $mine, $pfrom, $from);
        $pg = array_values(array_filter(mx_goals_status($pdo, $deals, $money, $week), fn($g) => $g['scope'] === 'user' && $g['scope_id'] === $uid));
        $name = (string)($u['display_name'] ?: $u['name']);
        $save->execute([$week, $uid, mx_week_body($pn, $pp, $pg, $myTodo, $money, $name), json_encode($myTodo, JSON_UNESCAPED_UNICODE), 0, date('Y-m-d H:i:s')]);
        $people++;
        // por email: o da equipa aos sócios; o seu a quem não o desligou no Perfil
        $partner = (int)($u['is_partner'] ?? 0) === 1;
        if ($partner || user_wants($pdo, $uid, 'report', 'email')) {
            $text = ($partner ? mx_week_body($num, $prev, $money ? $goalsMoney : $goals, $todo, $money) . "\n\n— — —\n\n" : '') . mx_week_body($pn, $pp, $pg, $myTodo, $money, $name)
                . "\n\nVer no Hub: " . team_app_url() . '/admin/relatorios.php?tab=atencao';
            if (@team_mail((string)$u['email'], 'Resumo da semana · ' . date('d/m', strtotime($week)), $text)) $sent++;
        }
    }
    $pdo->prepare('UPDATE metrics_weekly SET sent_at = ? WHERE week = ?')->execute([date('Y-m-d H:i:s'), $week]);
    return 'Resumo semanal de ' . $week . ': equipa + ' . $people . ' pessoas · ' . $sent . ' emails' . ($byAi ? ' · 3 coisas pela IA' : '');
}

/** O resumo mais recente da equipa e o da pessoa: [team => linha|null, me => linha|null]. */
function mx_week_latest(PDO $pdo, int $uid): array {
    if (!db_has_table($pdo, 'metrics_weekly')) return ['team' => null, 'me' => null];
    $w = $pdo->query('SELECT MAX(week) FROM metrics_weekly')->fetchColumn();
    if (!$w) return ['team' => null, 'me' => null];
    $st = $pdo->prepare('SELECT * FROM metrics_weekly WHERE week = ? AND user_id IN (0, ?)');
    $st->execute([$w, $uid]);
    $out = ['team' => null, 'me' => null];
    foreach ($st->fetchAll() as $r) $out[(int)$r['user_id'] === 0 ? 'team' : 'me'] = $r;
    return $out;
}
