<?php
/**
 * topper. admin — crosselling: um negócio, várias frentes (§22)
 *
 * Um negócio de crosselling tem o serviço principal (o funil do negócio) e serviços
 * adicionais, todos em deal_lines. A fase comercial é uma só; no Kickoff cada serviço passa
 * a uma frente com etapa própria no funil desse serviço — as etapas, gates e automações são
 * as do funil (sem cópias). O negócio fica parado no Kickoff enquanto as frentes andam e
 * conclui com a checklist de fecho:
 *   · sincronizadas: arrancam juntas, partilham a entrega e concluem juntas;
 *   · timelines diferentes: cada frente tem arranque e entrega próprios e conclui sozinha.
 * A faturação é do negócio: as tarefas de fatura e pagamento vêm só do funil principal.
 *
 * Para reaproveitar o motor do Pipeline, cada frente é vista como um «negócio virtual»
 * (xsell_vdeal): o mesmo negócio com o funil, a etapa, a entrega e a execução da frente.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/pipeline_flow.php';

const XSELL_SYNC = ['sync' => 'Sincronizadas', 'independent' => 'Timelines diferentes'];
const XSELL_SYNC_HINT = [
    'sync'        => 'Arrancam juntas no Kickoff, têm a mesma data de entrega e entregam juntas.',
    'independent' => 'Cada frente tem o seu arranque e a sua entrega e conclui sozinha; o negócio conclui quando todas terminam.',
];
const XSELL_START = ['kickoff' => 'No Kickoff', 'after' => 'Depois de outra frente', 'date' => 'Numa data'];
const XSELL_STATUS = ['planned' => 'Planeada', 'waiting' => 'À espera', 'active' => 'Em curso', 'done' => 'Concluída'];
const XSELL_OPP_SOURCE = ['xray' => 'X-Ray', 'delivery' => 'Depois da entrega', 'manual' => 'Manual'];
const XSELL_OPP_STATUS = ['open' => 'Aberta', 'won' => 'Ganha', 'lost' => 'Perdida', 'dropped' => 'Descartada', 'bundled' => 'No pacote'];

function xsell_ready(PDO $pdo): bool {
    static $ok = null;
    return $ok ??= db_has_table($pdo, 'deal_lines');
}

// ── os serviços de um negócio ───────────────────────────────

/** As linhas do negócio (o principal primeiro), com o nome do funil e da etapa. */
function xsell_lines(PDO $pdo, int $dealId): array {
    if (!xsell_ready($pdo)) return [];
    $st = $pdo->prepare('SELECT l.*, f.name AS funnel_name, f.xray_kind, s.name AS stage_name, s.kind AS stage_kind
                           FROM deal_lines l JOIN funnels f ON f.id = l.funnel_id LEFT JOIN funnel_stages s ON s.id = l.stage_id
                          WHERE l.deal_id = ? ORDER BY l.is_main DESC, l.position, l.id');
    $st->execute([$dealId]);
    return $st->fetchAll();
}

function xsell_line_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT l.*, f.name AS funnel_name, f.xray_kind FROM deal_lines l JOIN funnels f ON f.id = l.funnel_id WHERE l.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** É um negócio de crosselling (dois serviços ou mais)? */
function xsell_is_multi(PDO $pdo, array $deal): bool {
    if (!xsell_ready($pdo) || empty($deal['id'])) return false;
    static $cache = [];
    if (!array_key_exists((int)$deal['id'], $cache) || !empty($GLOBALS['XSELL_FRESH'])) {
        $cache[(int)$deal['id']] = (int)$pdo->query('SELECT COUNT(*) FROM deal_lines WHERE deal_id = ' . (int)$deal['id'])->fetchColumn() >= 2;
    }
    return $cache[(int)$deal['id']];
}

/** Uma avença (Marketing Buddy): a frente continua ativa depois de o negócio concluir. */
function xsell_is_recurring(array $line): bool {
    return ($line['xray_kind'] ?? '') === 'buddy';
}

/** Os ids dos funis do negócio (o principal primeiro); num negócio simples, só o do negócio. */
function xsell_funnel_ids(PDO $pdo, array $deal): array {
    $ids = array_map(fn($l) => (int)$l['funnel_id'], xsell_lines($pdo, (int)$deal['id']));
    return $ids ?: ($deal['funnel_id'] ? [(int)$deal['funnel_id']] : []);
}

/** As perguntas do X-Ray de todos os serviços do negócio, sem repetir (as do principal primeiro). */
function xray_questions_deal(PDO $pdo, array $deal): array {
    $out = []; $seen = [];
    foreach (xsell_funnel_ids($pdo, $deal) as $f) {
        foreach (xray_questions($pdo, $f) as $q) {
            $k = mb_strtolower(trim($q));
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $q;
        }
    }
    return $out;
}

/** Os nomes dos serviços, para mostrar: «VFX & Pós-produção + Ads». */
function xsell_label(PDO $pdo, array $deal): string {
    $lines = xsell_lines($pdo, (int)$deal['id']);
    return count($lines) >= 2 ? implode(' + ', array_map(fn($l) => $l['funnel_name'], $lines)) : '';
}

/**
 * Muda os serviços do negócio (só na fase comercial). $funnelIds: os adicionais (o principal é
 * o funil do negócio). As automações da etapa comercial atual dos serviços novos juntam-se.
 * Devolve a mensagem.
 */
function xsell_set_services(PDO $pdo, array $deal, array $funnelIds, ?int $by = null): string {
    if (!xsell_ready($pdo)) throw new RuntimeException('A base de dados ainda não foi atualizada.');
    if (!in_array($deal['phase'], ['pipeline', 'lead'], true)) throw new RuntimeException('Depois do Kickoff não se juntam serviços: um serviço novo é um negócio novo, ligado a este (crosselling posterior).');
    $main = (int)$deal['funnel_id'];
    $want = [];
    foreach ($funnelIds as $f) {
        $f = (int)$f;
        if ($f && $f !== $main && !in_array($f, $want, true) && ($fn = funnel_get($pdo, $f)) && (int)$fn['is_active']) $want[] = $f;
    }
    $have = xsell_lines($pdo, (int)$deal['id']);
    $haveIds = array_map(fn($l) => (int)$l['funnel_id'], array_filter($have, fn($l) => !(int)$l['is_main']));
    $add = array_values(array_diff($want, $haveIds));
    $del = array_values(array_diff($haveIds, $want));
    if (!$add && !$del && ($want || !$have)) return 'Sem alterações.';
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        if ($want) {
            // A linha do serviço principal (acompanha o funil do negócio).
            $pdo->prepare('DELETE FROM deal_lines WHERE deal_id = ? AND is_main = 1 AND funnel_id <> ?')->execute([$deal['id'], $main]);
            $st = $pdo->prepare('SELECT COUNT(*) FROM deal_lines WHERE deal_id = ? AND funnel_id = ?');
            $st->execute([$deal['id'], $main]);
            if (!(int)$st->fetchColumn()) {
                $pdo->prepare('INSERT INTO deal_lines (deal_id, funnel_id, is_main, position, created_at) VALUES (?,?,1,0,?)')->execute([$deal['id'], $main, $now]);
            }
            $pos = (int)$pdo->query('SELECT COALESCE(MAX(position),0) FROM deal_lines WHERE deal_id = ' . (int)$deal['id'])->fetchColumn();
            foreach ($add as $f) $pdo->prepare('INSERT INTO deal_lines (deal_id, funnel_id, is_main, position, created_at) VALUES (?,?,0,?,?)')->execute([$deal['id'], $f, ++$pos, $now]);
            if ($del) $pdo->prepare('DELETE FROM deal_lines WHERE deal_id = ? AND is_main = 0 AND funnel_id IN (' . implode(',', array_map('intval', $del)) . ')')->execute([$deal['id']]);
            // As oportunidades do X-Ray com estes serviços passam a «no pacote».
            if ($add && db_has_table($pdo, 'xsell_opps')) {
                $pdo->prepare("UPDATE xsell_opps SET status = 'bundled', result_deal_id = ?, closed_at = ? WHERE deal_id = ? AND status = 'open' AND funnel_id IN (" . implode(',', $add) . ')')
                    ->execute([$deal['id'], $now, $deal['id']]);
            }
        } else {
            $pdo->prepare('DELETE FROM deal_lines WHERE deal_id = ?')->execute([$deal['id']]);
            $pdo->prepare("UPDATE deals SET sync_mode = '' WHERE id = ?")->execute([$deal['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $GLOBALS['XSELL_FRESH'] = true;
    $names = fn(array $ids) => implode(', ', array_map(fn($f) => (string)(funnel_get($pdo, $f)['name'] ?? ''), $ids));
    deal_log($pdo, (int)$deal['id'], 'xsell', ['note' => trim(($add ? 'Serviços acrescentados: ' . $names($add) : '') . ($add && $del ? ' · ' : '') . ($del ? 'Serviços retirados: ' . $names($del) : '')), 'user_id' => $by]);
    // Os serviços novos trazem as automações da etapa comercial em que o negócio está.
    if ($add && $deal['phase'] === 'pipeline' && $deal['stage_id']) xsell_shared_rules($pdo, deal_get($pdo, (int)$deal['id']), (int)$deal['stage_id'], $add);
    return ($add ? 'Serviço' . (count($add) > 1 ? 's' : '') . ' acrescentado' . (count($add) > 1 ? 's' : '') . ': ' . $names($add) . '.' : '')
         . ($del ? ($add ? ' ' : '') . 'Retirado' . (count($del) > 1 ? 's' : '') . ': ' . $names($del) . '.' : '');
}

/** A sincronização (obrigatória num negócio de crosselling) e o arranque/entrega de cada frente. */
function xsell_save_plan(PDO $pdo, array $deal, array $in, ?int $by = null): string {
    if (!xsell_is_multi($pdo, $deal)) throw new RuntimeException('Este negócio só tem um serviço.');
    $mode = (string)($in['sync_mode'] ?? '');
    if (!isset(XSELL_SYNC[$mode])) throw new RuntimeException('Escolha se as frentes seguem sincronizadas ou em timelines diferentes.');
    if ($deal['phase'] === 'done') throw new RuntimeException('O negócio já está concluído.');
    $lines = xsell_lines($pdo, (int)$deal['id']);
    $ids = array_map(fn($l) => (int)$l['id'], $lines);
    $started = $deal['phase'] === 'production';
    if ($started && $mode !== $deal['sync_mode'] && $deal['sync_mode'] !== '') throw new RuntimeException('Depois do Kickoff a sincronização já não muda.');
    $pdo->prepare('UPDATE deals SET sync_mode = ? WHERE id = ?')->execute([$mode, $deal['id']]);
    foreach ($lines as $l) {
        $lid = (int)$l['id'];
        $set = [];
        if (is_admin() && array_key_exists('exec', $in) && isset($in['exec'][$lid])) $set['exec_owner_id'] = flow_user_ok($pdo, (int)$in['exec'][$lid]);
        if ($mode === 'independent' && !(int)$l['is_main'] && in_array($l['status'], ['planned', 'waiting'], true)) {
            $sm = (string)($in['start'][$lid] ?? 'kickoff');
            if (!isset(XSELL_START[$sm])) $sm = 'kickoff';
            $after = (int)($in['after'][$lid] ?? 0);
            if ($sm === 'after' && (!in_array($after, $ids, true) || $after === $lid)) throw new RuntimeException('Escolha depois de que frente arranca ' . $l['funnel_name'] . '.');
            $sd = (string)($in['start_date'][$lid] ?? '');
            if ($sm === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd)) throw new RuntimeException('Indique a data de arranque de ' . $l['funnel_name'] . '.');
            $set += ['start_mode' => $sm, 'start_after_id' => $sm === 'after' ? $after : null, 'start_date' => $sm === 'date' ? $sd : null];
        } elseif ($mode === 'sync') {
            $set += ['start_mode' => 'kickoff', 'start_after_id' => null, 'start_date' => null];
        }
        if ($mode === 'independent' && isset($in['delivery'][$lid])) {
            $dd = (string)$in['delivery'][$lid];
            $set['delivery_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd) ? $dd : null;
        }
        if ($set) $pdo->prepare('UPDATE deal_lines SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), $lid]);
    }
    // Uma frente «depois de» não pode acabar num círculo.
    foreach (xsell_lines($pdo, (int)$deal['id']) as $l) {
        $seen = []; $cur = $l;
        while ($cur && $cur['start_mode'] === 'after' && $cur['start_after_id']) {
            if (isset($seen[(int)$cur['id']])) throw new RuntimeException('As frentes não podem esperar umas pelas outras em círculo.');
            $seen[(int)$cur['id']] = true;
            $cur = xsell_line_get($pdo, (int)$cur['start_after_id']);
        }
    }
    if ($deal['sync_mode'] !== $mode) deal_log($pdo, (int)$deal['id'], 'xsell', ['note' => 'Frentes: ' . XSELL_SYNC[$mode], 'user_id' => $by]);
    if ($started) flow_dates_changed($pdo, (int)$deal['id']);
    return 'Plano das frentes guardado: ' . mb_strtolower(XSELL_SYNC[$mode]) . '.';
}

/** O requisito da proposta (§22.2): num negócio de crosselling, a sincronização está escolhida. */
function xsell_requirements(PDO $pdo, array $deal): array {
    if (!xsell_is_multi($pdo, $deal)) return [];
    return [['label' => 'Crosselling: frentes sincronizadas ou em timelines diferentes', 'ok' => isset(XSELL_SYNC[$deal['sync_mode'] ?? '']),
             'hint' => 'Escolha em «Serviços» (' . xsell_label($pdo, $deal) . ').']];
}

// ── as automações partilhadas da fase comercial ─────────────

/** Já há uma tarefa com este título no negócio? (a mesma tarefa não se cria duas vezes) */
function xsell_task_exists(PDO $pdo, int $dealId, string $title): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND LOWER(title) = ?');
    $st->execute([$dealId, mb_strtolower($title)]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * Nas etapas partilhadas (X-Ray, Proposta, Aguardar feedback e as comerciais com o mesmo nome),
 * as regras «entra na etapa» dos serviços adicionais juntam-se às do principal, na etapa do
 * negócio (contam para o mesmo gate). Fatura e pagamento só do principal. Devolve quantas criou.
 */
function xsell_shared_rules(PDO $pdo, array $deal, int $stageId, ?array $onlyFunnels = null): int {
    $stage = stage_get($pdo, $stageId);
    if (!$stage || !in_array($stage['kind'], ['xray', 'commercial'], true)) return 0;
    $n = 0;
    foreach (xsell_lines($pdo, (int)$deal['id']) as $l) {
        if ((int)$l['is_main'] || ($onlyFunnels !== null && !in_array((int)$l['funnel_id'], $onlyFunnels, true))) continue;
        $ms = stage_match($pdo, $stage, (int)$l['funnel_id']);
        if (!$ms) continue;
        foreach (flow_rules($pdo, (int)$ms['id'], true) as $r) {
            if ($r['trig'] !== 'enter' || in_array($r['doc_kind'], ['invoice', 'payment'], true)) continue;
            if (xsell_task_exists($pdo, (int)$deal['id'], flow_title($pdo, (string)$r['title'], $deal, $stage))) continue;
            if (flow_task_from_rule($pdo, $r, $deal, $stage, today_lisbon(), current_user()['id'] ?? null)) $n++;
        }
    }
    if ($n) deal_log($pdo, (int)$deal['id'], 'auto', ['note' => $n . ' tarefa' . ($n === 1 ? '' : 's') . ' dos serviços adicionais (' . $stage['name'] . ')']);
    return $n;
}

// ── as frentes (depois do Kickoff) ──────────────────────────

/** O negócio visto pela frente: o funil, a etapa, a entrega e a execução da frente. */
function xsell_vdeal(array $deal, array $line): array {
    $v = $deal;
    $v['funnel_id'] = (int)$line['funnel_id'];
    $v['stage_id'] = $line['stage_id'] !== null ? (int)$line['stage_id'] : null;
    $v['phase'] = $line['status'] === 'done' ? 'done' : 'production';
    if ($line['exec_owner_id']) $v['exec_owner_id'] = (int)$line['exec_owner_id'];
    if (($deal['sync_mode'] ?? '') === 'independent' && $line['delivery_date']) $v['delivery_date'] = $line['delivery_date'];
    $v['_line'] = (int)$line['id'];
    $v['_main'] = (int)$line['is_main'] === 1;
    $v['_service'] = (string)$line['funnel_name'];
    return $v;
}

/** A frente entrou numa etapa: regista e corre as regras «entra na etapa» do funil da frente. */
function xsell_line_on_enter(PDO $pdo, array $deal, array $line, array $stage): int {
    $pdo->prepare('UPDATE deal_lines SET stage_entered_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $line['id']]);
    $line['stage_id'] = (int)$stage['id'];
    $v = xsell_vdeal($deal, $line);
    $n = 0;
    foreach (flow_rules($pdo, (int)$stage['id'], true) as $r) {
        if ($r['trig'] !== 'enter') continue;
        if (!$v['_main'] && in_array($r['doc_kind'], ['invoice', 'payment'], true)) continue;   // um único plano de pagamento
        // No Kickoff e no fecho as frentes partilham o momento: a mesma tarefa não se repete.
        if (in_array($stage['kind'], ['kickoff', 'done'], true) && xsell_task_exists($pdo, (int)$deal['id'], flow_title($pdo, (string)$r['title'], $v, $stage))) continue;
        if (flow_task_from_rule($pdo, $r, $v, $stage, today_lisbon(), current_user()['id'] ?? null)) $n++;
    }
    if ($n) deal_log($pdo, (int)$deal['id'], 'auto', ['note' => $line['funnel_name'] . ': ' . ($n === 1 ? '1 tarefa automática' : $n . ' tarefas automáticas') . ' (' . $stage['name'] . ')']);
    return $n;
}

/** Arranca uma frente: passa ao Kickoff do seu funil (com as automações) e fica em curso. */
function xsell_line_start(PDO $pdo, array $deal, array $line, string $why = ''): void {
    $kick = flow_stage_with($pdo, (int)$line['funnel_id'], 'kickoff');
    if (!$kick) throw new RuntimeException('O funil ' . $line['funnel_name'] . ' não tem Kickoff.');
    $pdo->prepare("UPDATE deal_lines SET stage_id = ?, status = 'active', started_at = ? WHERE id = ?")->execute([$kick['id'], date('Y-m-d H:i:s'), $line['id']]);
    deal_log($pdo, (int)$deal['id'], 'stage', ['from_funnel' => $line['funnel_id'], 'from_stage' => null, 'to_funnel' => $line['funnel_id'], 'to_stage' => $kick['id'],
                                               'note' => $why !== '' ? $line['funnel_name'] . ' arrancou: ' . $why : '']);
    xsell_line_on_enter($pdo, $deal, ['status' => 'active'] + $line, $kick);
}

/** O negócio chegou ao Kickoff: abre as frentes (as que arrancam já) e as outras ficam à espera. */
function xsell_kickoff(PDO $pdo, array $deal): void {
    if (!xsell_is_multi($pdo, $deal)) return;
    $sync = ($deal['sync_mode'] ?? '') !== 'independent';
    foreach (xsell_lines($pdo, (int)$deal['id']) as $l) {
        if ($l['status'] !== 'planned') continue;
        if ((int)$l['is_main']) {
            // A frente principal parte do Kickoff do próprio negócio (as automações já correram).
            $pdo->prepare("UPDATE deal_lines SET stage_id = ?, status = 'active', started_at = ?, stage_entered_at = ? WHERE id = ?")
                ->execute([$deal['stage_id'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $l['id']]);
            continue;
        }
        if ($sync || $l['start_mode'] === 'kickoff' || ($l['start_mode'] === 'date' && $l['start_date'] && $l['start_date'] <= today_lisbon())) {
            xsell_line_start($pdo, $deal, $l);
        } else {
            $pdo->prepare("UPDATE deal_lines SET status = 'waiting' WHERE id = ?")->execute([$l['id']]);
        }
    }
    xsell_store_values($pdo, $deal);
}

/** Desfaz o Kickoff (Anular): as frentes voltam a ser só a lista de serviços. */
function xsell_unkickoff(PDO $pdo, int $dealId): void {
    if (!xsell_ready($pdo)) return;
    $pdo->prepare("UPDATE deal_lines SET stage_id = NULL, status = 'planned', started_at = NULL, stage_entered_at = NULL, done_at = NULL WHERE deal_id = ?")->execute([$dealId]);
}

/** As frentes que esperam por esta arrancam (timelines diferentes). */
function xsell_start_followers(PDO $pdo, array $deal, array $line): int {
    $st = $pdo->prepare("SELECT l.*, f.name AS funnel_name, f.xray_kind FROM deal_lines l JOIN funnels f ON f.id = l.funnel_id
                          WHERE l.deal_id = ? AND l.status = 'waiting' AND l.start_mode = 'after' AND l.start_after_id = ?");
    $st->execute([$deal['id'], $line['id']]);
    $n = 0;
    foreach ($st->fetchAll() as $f) { xsell_line_start($pdo, $deal, $f, 'depois de ' . $line['funnel_name']); $n++; }
    return $n;
}

/**
 * Move uma frente para outra etapa do funil dela, com os gates desse funil. Concluído só nas
 * timelines diferentes (as sincronizadas concluem juntas com o negócio). Devolve a mensagem.
 */
function xsell_line_move(PDO $pdo, array $deal, array $line, int $stageId, string $force = ''): string {
    if ($deal['phase'] !== 'production') throw new RuntimeException('As frentes andam depois do Kickoff, enquanto o negócio está em produção.');
    if ($line['status'] === 'waiting' || $line['status'] === 'planned') throw new RuntimeException('A frente ' . $line['funnel_name'] . ' ainda não arrancou.');
    $to = stage_get($pdo, $stageId);
    if (!$to || (int)$to['funnel_id'] !== (int)$line['funnel_id']) throw new RuntimeException('Etapa inválida.');
    if (in_array($to['kind'], ['xray', 'commercial'], true)) throw new RuntimeException('Uma frente em produção não volta à fase comercial.');
    if ((int)$line['stage_id'] === $stageId) return 'Sem alterações.';
    $sync = $deal['sync_mode'] !== 'independent';
    if ($to['kind'] === 'done' && $sync) throw new RuntimeException('Frentes sincronizadas entregam juntas: o negócio conclui todas de uma vez, na checklist de fecho.');
    $v = xsell_vdeal($deal, $line);
    $forced = flow_gate_check($pdo, $v, $to, $force);
    $done = $to['kind'] === 'done';
    $pdo->prepare('UPDATE deal_lines SET stage_id = ?, status = ?, done_at = ? WHERE id = ?')
        ->execute([$stageId, $done ? 'done' : 'active', $done ? date('Y-m-d H:i:s') : null, $line['id']]);
    deal_log($pdo, (int)$deal['id'], 'stage', ['from_funnel' => $line['funnel_id'], 'from_stage' => $line['stage_id'], 'to_funnel' => $line['funnel_id'], 'to_stage' => $stageId]);
    if ($forced !== '') deal_log($pdo, (int)$deal['id'], 'gate_force', ['from_stage' => $line['stage_id'], 'to_stage' => $stageId, 'note' => $line['funnel_name'] . ': ' . $forced]);
    $pdo->prepare('UPDATE deals SET last_touch = ? WHERE id = ?')->execute([today_lisbon(), $deal['id']]);
    xsell_line_on_enter($pdo, $deal, $line, $to);
    $msg = $line['funnel_name'] . ': ' . ($done ? 'frente concluída.' : 'passou a «' . $to['name'] . '».');
    if ($done) {
        $n = xsell_start_followers($pdo, $deal, $line);
        if ($n) $msg .= ' Arrancou ' . ($n === 1 ? 'a frente seguinte' : $n . ' frentes seguintes') . '.';
        xsell_after_delivery($pdo, $deal, [(int)$line['funnel_id']]);
        if (!xsell_close_missing($pdo, $deal)) $msg .= ' Todas as frentes estão prontas: conclua o negócio na checklist de fecho.';
    }
    return $msg;
}

/** O que falta para concluir um negócio de crosselling (rótulos); [] = pode concluir. */
function xsell_close_missing(PDO $pdo, array $deal): array {
    if (!xsell_is_multi($pdo, $deal)) return [];
    $sync = $deal['sync_mode'] !== 'independent';
    $miss = [];
    foreach (xsell_lines($pdo, (int)$deal['id']) as $l) {
        if (xsell_is_recurring($l) || $l['status'] === 'done') continue;   // a avença continua ativa
        if ($l['status'] !== 'active') { $miss[] = $l['funnel_name'] . ': ainda não arrancou'; continue; }
        if (!$sync) { $miss[] = $l['funnel_name'] . ': frente por concluir (' . $l['stage_name'] . ')'; continue; }
        // Sincronizadas: todas na última etapa de produção, com os requisitos cumpridos.
        $order = flow_order($pdo, (int)$l['funnel_id']);
        $last = null;
        foreach ($order as $s) if ($s['kind'] === 'production') $last = $s;
        if ($last && (int)$l['stage_id'] !== (int)$last['id']) { $miss[] = $l['funnel_name'] . ': falta chegar a «' . $last['name'] . '» (está em «' . $l['stage_name'] . '»)'; continue; }
        $cur = stage_get($pdo, (int)$l['stage_id']);
        foreach (deal_gate_missing($pdo, xsell_vdeal($deal, $l), $cur) as $m) $miss[] = $l['funnel_name'] . ': ' . $m;
    }
    return $miss;
}

/** O negócio concluiu: as frentes sincronizadas passam a Concluído (com as automações do fecho de cada funil). */
function xsell_close(PDO $pdo, array $deal): void {
    if (!xsell_is_multi($pdo, $deal)) return;
    $funnels = [];
    foreach (xsell_lines($pdo, (int)$deal['id']) as $l) {
        if ($l['status'] === 'done') { $funnels[] = (int)$l['funnel_id']; continue; }
        if (xsell_is_recurring($l)) continue;
        $done = flow_stage_with($pdo, (int)$l['funnel_id'], 'done');
        if (!$done) continue;
        $pdo->prepare("UPDATE deal_lines SET stage_id = ?, status = 'done', done_at = ? WHERE id = ?")->execute([$done['id'], date('Y-m-d H:i:s'), $l['id']]);
        if (!(int)$l['is_main']) xsell_line_on_enter($pdo, $deal, $l, $done);   // as do principal correm com o negócio
        $funnels[] = (int)$l['funnel_id'];
    }
    xsell_store_values($pdo, $deal);
    xsell_after_delivery($pdo, $deal, $funnels);
}

/** Reabrir um negócio concluído (Anular): as frentes concluídas no fecho voltam a estar em curso. */
function xsell_reopen(PDO $pdo, int $dealId, array $snapshot): void {
    foreach ($snapshot as $l) {
        $pdo->prepare('UPDATE deal_lines SET stage_id = ?, status = ?, done_at = ? WHERE id = ?')->execute([$l['stage_id'], $l['status'], $l['done_at'], $l['id']]);
    }
}

/** O estado das frentes (para o «Anular»). */
function xsell_snapshot(PDO $pdo, int $dealId): array {
    if (!xsell_ready($pdo)) return [];
    $st = $pdo->prepare('SELECT id, stage_id, status, done_at FROM deal_lines WHERE deal_id = ?');
    $st->execute([$dealId]);
    return $st->fetchAll();
}

/** O relógio diário: as frentes «numa data» arrancam no dia. Devolve quantas arrancaram. */
function xsell_tick(PDO $pdo, ?string $today = null): int {
    if (!xsell_ready($pdo)) return 0;
    $today ??= today_lisbon();
    $st = $pdo->prepare("SELECT l.*, f.name AS funnel_name, f.xray_kind FROM deal_lines l JOIN funnels f ON f.id = l.funnel_id JOIN deals d ON d.id = l.deal_id
                          WHERE l.status = 'waiting' AND l.start_mode = 'date' AND l.start_date <= ? AND d.phase = 'production'");
    $st->execute([$today]);
    $n = 0;
    foreach ($st->fetchAll() as $l) {
        xsell_line_start($pdo, deal_get($pdo, (int)$l['deal_id']), $l, 'data de arranque');
        $n++;
    }
    return $n;
}

/** As frentes ativas numa etapa (para as regras de data e mensais do relógio diário). */
function xsell_lines_in_stage(PDO $pdo, int $stageId): array {
    if (!xsell_ready($pdo)) return [];
    $st = $pdo->prepare("SELECT l.*, f.name AS funnel_name, f.xray_kind FROM deal_lines l JOIN funnels f ON f.id = l.funnel_id JOIN deals d ON d.id = l.deal_id
                          WHERE l.stage_id = ? AND l.status = 'active' AND l.is_main = 0 AND d.phase = 'production'");
    $st->execute([$stageId]);
    return $st->fetchAll();
}

// ── o valor de cada serviço ─────────────────────────────────

/**
 * O valor (s/ IVA) de cada serviço: o preço do negócio dividido pelo custo das linhas do
 * orçamento marcadas com cada serviço (as sem marca contam para o principal). Sem custos,
 * fica tudo no principal. Devolve [funnel_id => valor].
 */
function xsell_values(PDO $pdo, array $deal): array {
    $lines = xsell_lines($pdo, (int)$deal['id']);
    if (count($lines) < 2) return [];
    $total = (float)($deal['budget'] ?: $deal['value_estimate']);
    $main = (int)$deal['funnel_id'];
    $cost = array_fill_keys(array_map(fn($l) => (int)$l['funnel_id'], $lines), 0.0);
    require_once __DIR__ . '/budget.php';
    $sheet = budget_sheet_get($pdo, (int)$deal['id']);
    if ($sheet) {
        $vid = (int)($sheet['accepted_version_id'] ?: 0);
        if (!$vid) { $vs = budget_versions($pdo, (int)$sheet['id']); $last = end($vs); $vid = $last ? (int)$last['id'] : 0; }
        $v = $vid ? $pdo->query('SELECT * FROM budget_versions WHERE id = ' . $vid)->fetch() : null;
        if ($v) {
            $opt = (string)($v['accepted_opt'] ?? '') ?: 'A';
            $hours = (float)budget_setting($pdo, 'budget_day_hours', 8);
            foreach (budget_lines($pdo, $vid, $opt) as $bl) {
                $f = (int)($bl['funnel_id'] ?? 0);
                $cost[isset($cost[$f]) ? $f : $main] += budget_line_cost($bl, $hours);
            }
        }
    }
    $sum = array_sum($cost);
    $out = [];
    foreach ($cost as $f => $c) $out[$f] = $sum > 0 ? round($total * $c / $sum, 2) : ($f === $main ? $total : 0.0);
    return $out;
}

function xsell_store_values(PDO $pdo, array $deal): void {
    $deal = deal_get($pdo, (int)$deal['id']) ?? $deal;
    foreach (xsell_values($pdo, $deal) as $f => $v) $pdo->prepare('UPDATE deal_lines SET value = ? WHERE deal_id = ? AND funnel_id = ?')->execute([$v, $deal['id'], $f]);
}

// ── pacotes ─────────────────────────────────────────────────

function xsell_packages(PDO $pdo, bool $activeOnly = true): array {
    if (!db_has_table($pdo, 'packages')) return [];
    return $pdo->query('SELECT * FROM packages' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY position, name')->fetchAll();
}

function xsell_package_get(PDO $pdo, int $id): ?array {
    if (!$id || !db_has_table($pdo, 'packages')) return null;
    $st = $pdo->prepare('SELECT * FROM packages WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Os funis de um pacote (o primeiro é o principal). */
function xsell_package_funnels(array $p): array {
    return array_values(array_filter(array_map('intval', explode(',', (string)$p['funnels']))));
}

/** Aplica um pacote ao negócio (fase comercial): serviços, sincronização e, se a folha ainda não existir, o modelo. */
function xsell_apply_package(PDO $pdo, array $deal, int $packageId, ?int $by = null): string {
    $p = xsell_package_get($pdo, $packageId);
    if (!$p || !(int)$p['is_active']) throw new RuntimeException('Pacote não encontrado.');
    $fs = xsell_package_funnels($p);
    if (count($fs) < 2) throw new RuntimeException('Este pacote tem menos de dois serviços.');
    if ((int)$deal['funnel_id'] !== $fs[0] && $deal['phase'] === 'pipeline') {
        // O serviço principal do pacote passa a ser o funil do negócio (mesma etapa).
        if (!in_array((int)$deal['funnel_id'], $fs, true)) deal_change_funnel($pdo, $deal, $fs[0]);
        $deal = deal_get($pdo, (int)$deal['id']);
    }
    $msg = xsell_set_services($pdo, $deal, array_diff($fs, [(int)$deal['funnel_id']]), $by);
    $pdo->prepare('UPDATE deals SET package_id = ?, sync_mode = CASE WHEN sync_mode = \'\' THEN ? ELSE sync_mode END WHERE id = ?')
        ->execute([$p['id'], isset(XSELL_SYNC[$p['sync_mode']]) ? $p['sync_mode'] : 'sync', $deal['id']]);
    $tpl = '';
    if ($p['template_id']) {
        require_once __DIR__ . '/budget.php';
        if (!budget_sheet_get($pdo, (int)$deal['id']) && function_exists('budget_apply_template_new')) $tpl = budget_apply_template_new($pdo, deal_get($pdo, (int)$deal['id']), (int)$p['template_id']);
    }
    deal_log($pdo, (int)$deal['id'], 'xsell', ['note' => 'Pacote «' . $p['name'] . '»', 'user_id' => $by]);
    return 'Pacote «' . $p['name'] . '» aplicado. ' . ($msg !== 'Sem alterações.' ? $msg : '') . ($tpl !== '' ? ' ' . $tpl : '');
}

// ── oportunidades e crosselling depois da entrega ───────────

/** O contacto (cliente) de um negócio: o ligado ou nenhum. */
function xsell_client(array $deal): ?int {
    return !empty($deal['contact_id']) ? (int)$deal['contact_id'] : null;
}

/** Os serviços que um cliente já comprou (negócios que passaram o Kickoff): [funnel_id => [n, valor, última data]]. */
function xsell_bought(PDO $pdo, int $contactId): array {
    $out = [];
    $st = $pdo->prepare("SELECT d.* FROM deals d WHERE d.contact_id = ? AND d.phase IN ('production','done')");
    $st->execute([$contactId]);
    foreach ($st->fetchAll() as $d) {
        $vals = xsell_values($pdo, $d);
        $fs = $vals ? array_keys($vals) : [(int)$d['funnel_id']];
        foreach ($fs as $f) {
            $out[$f] ??= ['n' => 0, 'value' => 0.0, 'last' => '', 'deals' => []];
            $out[$f]['n']++;
            $out[$f]['value'] += $vals[$f] ?? (float)($d['budget'] ?: $d['value_estimate']);
            $when = substr((string)($d['done_at'] ?: $d['converted_at']), 0, 10);
            if ($when > $out[$f]['last']) $out[$f]['last'] = $when;
            $out[$f]['deals'][] = ['id' => (int)$d['id'], 'code' => (string)$d['code'], 'phase' => $d['phase']];
        }
    }
    return $out;
}

/** Regista uma oportunidade (sem repetir uma aberta do mesmo cliente e serviço). Devolve o id ou null. */
function xsell_opp_add(PDO $pdo, ?int $contactId, ?int $dealId, int $funnelId, string $source, string $note = '', ?int $taskId = null): ?int {
    if (!db_has_table($pdo, 'xsell_opps')) return null;
    $st = $pdo->prepare("SELECT id FROM xsell_opps WHERE status = 'open' AND funnel_id = ? AND " . ($contactId ? 'contact_id = ?' : 'deal_id = ?'));
    $st->execute([$funnelId, $contactId ?: $dealId]);
    if ($st->fetchColumn()) return null;
    $pdo->prepare('INSERT INTO xsell_opps (contact_id, deal_id, funnel_id, source, status, note, task_id, created_by, created_at) VALUES (?,?,?,?,\'open\',?,?,?,?)')
        ->execute([$contactId, $dealId, $funnelId, $source, mb_substr($note, 0, 255), $taskId, current_user()['id'] ?? null, date('Y-m-d H:i:s')]);
    return (int)$pdo->lastInsertId();
}

/** As oportunidades abertas do X-Ray de um negócio: [funnel_id => nota]. */
function xsell_xray_opps(PDO $pdo, int $dealId): array {
    if (!db_has_table($pdo, 'xsell_opps')) return [];
    $st = $pdo->prepare("SELECT funnel_id, note FROM xsell_opps WHERE deal_id = ? AND source = 'xray' AND status = 'open'");
    $st->execute([$dealId]);
    return array_column($st->fetchAll(), 'note', 'funnel_id');
}

/** Grava as oportunidades do X-Ray (os serviços falados e não incluídos). */
function xsell_xray_opps_save(PDO $pdo, array $deal, array $funnelIds, string $note): void {
    if (!db_has_table($pdo, 'xsell_opps')) return;
    $in = xsell_funnel_ids($pdo, $deal);
    $want = array_values(array_diff(array_filter(array_map('intval', $funnelIds)), $in));
    $cur = array_map('intval', array_keys(xsell_xray_opps($pdo, (int)$deal['id'])));
    foreach (array_diff($cur, $want) as $f) $pdo->prepare("DELETE FROM xsell_opps WHERE deal_id = ? AND funnel_id = ? AND source = 'xray' AND status = 'open'")->execute([$deal['id'], $f]);
    foreach (array_diff($want, $cur) as $f) {
        $pdo->prepare('INSERT INTO xsell_opps (contact_id, deal_id, funnel_id, source, status, note, created_by, created_at) VALUES (?,?,?,\'xray\',\'open\',?,?,?)')
            ->execute([xsell_client($deal), $deal['id'], $f, mb_substr($note, 0, 255), current_user()['id'] ?? null, date('Y-m-d H:i:s')]);
    }
    if ($want) $pdo->prepare("UPDATE xsell_opps SET note = ? WHERE deal_id = ? AND source = 'xray' AND status = 'open'")->execute([mb_substr($note, 0, 255), $deal['id']]);
}

/**
 * Depois de entregar (§22.6): as regras «depois de X, propor Y ao fim de N dias» dão ao comercial
 * a tarefa «Propor Y a {cliente}» e uma oportunidade — se o cliente ainda não tiver Y.
 */
function xsell_after_delivery(PDO $pdo, array $deal, array $funnelIds): int {
    if (!db_has_table($pdo, 'xsell_rules') || !$funnelIds) return 0;
    $contact = xsell_client($deal);
    $bought = $contact ? xsell_bought($pdo, $contact) : [];
    $have = array_merge(array_keys($bought), xsell_funnel_ids($pdo, $deal));
    $st = $pdo->query('SELECT r.*, f.name AS to_name FROM xsell_rules r JOIN funnels f ON f.id = r.to_funnel_id
                        WHERE r.is_active = 1 AND f.is_active = 1 AND r.from_funnel_id IN (' . implode(',', array_map('intval', $funnelIds)) . ')');
    $n = 0;
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/leads.php';
    foreach ($st->fetchAll() as $r) {
        $to = (int)$r['to_funnel_id'];
        if (in_array($to, $have, true)) continue;
        $client = flow_client_name($pdo, $deal);
        $title = 'Propor ' . $r['to_name'] . ' a ' . ($client !== '' ? $client : $deal['name']);
        if (xsell_task_exists($pdo, (int)$deal['id'], $title)) continue;
        $due = (new DateTimeImmutable(today_lisbon()))->modify('+' . max(0, (int)$r['days']) . ' days')->format('Y-m-d');
        $owner = flow_user_ok($pdo, $deal['owner_id']);
        $tid = lead_task_create($pdo, $deal, 'followup', $title,
                                'Crosselling depois da entrega de ' . $deal['code'] . ': o cliente ainda não tem ' . $r['to_name'] . '. A oportunidade está na ficha do contacto (Serviços comprados).',
                                $due, null, $owner, '', current_user()['id'] ?? null);
        xsell_opp_add($pdo, $contact, (int)$deal['id'], $to, 'delivery', 'Depois da entrega de ' . $deal['code'], $tid ?: null);
        $have[] = $to;
        $n++;
    }
    if ($n) deal_log($pdo, (int)$deal['id'], 'auto', ['note' => 'Crosselling: ' . $n . ' proposta' . ($n === 1 ? '' : 's') . ' a fazer ao cliente']);
    return $n;
}

/** Um negócio que nasceu de uma oportunidade chegou ao Kickoff (ganha) ou perdeu-se. */
function xsell_opp_result(PDO $pdo, int $dealId, string $status): void {
    if (!db_has_table($pdo, 'xsell_opps')) return;
    $pdo->prepare("UPDATE xsell_opps SET status = ?, closed_at = ? WHERE result_deal_id = ? AND status = 'open'")->execute([$status, date('Y-m-d H:i:s'), $dealId]);
}

function xsell_opp_get(PDO $pdo, int $id): ?array {
    if (!db_has_table($pdo, 'xsell_opps')) return null;
    $st = $pdo->prepare('SELECT o.*, f.name AS funnel_name FROM xsell_opps o JOIN funnels f ON f.id = o.funnel_id WHERE o.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** As oportunidades de um cliente (abertas primeiro). */
function xsell_opps_for_contact(PDO $pdo, int $contactId): array {
    if (!db_has_table($pdo, 'xsell_opps')) return [];
    $st = $pdo->prepare("SELECT o.*, f.name AS funnel_name, d.code AS deal_code, r.code AS result_code
                           FROM xsell_opps o JOIN funnels f ON f.id = o.funnel_id
                           LEFT JOIN deals d ON d.id = o.deal_id LEFT JOIN deals r ON r.id = o.result_deal_id
                          WHERE o.contact_id = ? ORDER BY o.status = 'open' DESC, o.created_at DESC LIMIT 30");
    $st->execute([$contactId]);
    return $st->fetchAll();
}
