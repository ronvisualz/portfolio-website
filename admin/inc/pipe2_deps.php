<?php
/**
 * topper. admin — Pipeline v2: dependências, plano e datas
 *
 * Dependências (D8). Uma ligação de deal_line_deps é «a frente A conclui a sub-etapa X → a frente B, sub-etapa Y»: B
 * corre até ao fim da sub-etapa anterior a Y e aí fica «À espera» (depwait) até A concluir X — sair de X para a
 * frente, pelo motor ou à mão. Então a ligação fica cumprida (met_at) e, sem mais nenhuma por cumprir para (B, Y), B
 * é libertada e entra em Y (o motor, tarefa 10). Os modelos são por categoria (a gravada no negócio, senão a
 * calculada) e guardam ids das sub-etapas dos funis reais: leem-se por código do serviço + nome da sub-etapa e
 * resolvem-se pelas frentes vivas do negócio. Numa combinação parcial, as ligações que passam por um serviço ausente
 * ligam-se diretamente ao seguinte (contração, sem poda). Uma ligação retirada (removed_at) nunca se recria; as
 * ligações à mão não podem fechar um ciclo.
 *
 * Plano e datas (D17). A entrega de uma frente é a dela, se estiver preenchida, senão a do negócio (sem depender de
 * sync_mode). O plano das sub-etapas é em dias úteis a partir do dia em que a frente começa: cada sub-etapa acaba N
 * dias úteis depois de começar (como o prazo «+N dias úteis» das tarefas) e a seguinte começa nesse dia; N é o
 * plan_days da linha de passos, senão o da sub-etapa (sem dias, sem plano). Replaneamento: quando a sub-etapa atual
 * passa o fim, as seguintes deslizam pelos dias úteis de atraso que ainda não deslizaram, somados em replanned_days;
 * a entrega não muda. Uma frente À espera, Por começar ou Pronta não se replaneia nem conta como atrasada.
 *
 * Quem chama: o motor (pipe2_engine, pipe2_deal, pipe2_tick), como «Sistema», e só em negócios v2; as ações humanas
 * (ligações à mão, datas, plano das frentes) chegam na fase 2 pelos pontos de entrada, que verificam as permissões no
 * servidor. Aqui só se decide por permissões numa coisa, como na v1 (xsell_save_plan): mudar o responsável de uma
 * frente em pipe2_plan_save exige is_admin(). Nenhuma função chama can().
 *
 * pipe2_deps_instantiate não toma lock nem abre transação: deal_line_deps não tem UNIQUE, por isso o SELECT antes do
 * INSERT só é idempotente dentro do lock do negócio e da transação de pipe2_kickoff (tarefa 11), que é quem a chama.
 * pipe2_dep_add bloqueia a linha do negócio (FOR UPDATE) na sua transação, para duas ligações à mão ao mesmo tempo não
 * fecharem um ciclo. pipe2_plan_front e pipe2_replan não criam linhas de passos (isso é do Kickoff e de pipe2_step_row).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/pipe2_fronts.php';     // pipe2_deal_is_v2, pipe2_fronts, pipe2_front_get, pipe2_front_stages, pipe2_user_ok, pipe2_line_is_v2
require_once __DIR__ . '/pipe2_category.php';   // pipe2_deal_category, pipe2_funnel_codes, pipe2_category_label
require_once __DIR__ . '/pipeline_flow.php';    // flow_add_workdays, flow_due
require_once __DIR__ . '/deals.php';            // deal_log, today_lisbon

const PIPE2_DEP_CYCLE = 'Esta ligação criava um ciclo.';
const PIPE2_DATE_BAD = 'Data de entrega inválida.';

// ── modelos e contração (D8) ────────────────────────────────

/**
 * As ligações do modelo de uma categoria, por posição: [[serviço de, sub-etapa de, serviço para, sub-etapa para], …].
 * Só as ativas; o serviço é o service_code do funil da sub-etapa (as que não o têm saltam-se). Sem categoria, [].
 */
function pipe2_models_for(PDO $pdo, string $category): array {
    if ($category === '' || !pipe2_schema_ready($pdo)) return [];
    $st = $pdo->prepare('SELECT fa.service_code AS fc, sa.name AS fn, fb.service_code AS tc, sb.name AS tn
                           FROM pipe2_dep_models m
                           JOIN funnel_stages sa ON sa.id = m.from_stage_id JOIN funnels fa ON fa.id = sa.funnel_id
                           JOIN funnel_stages sb ON sb.id = m.to_stage_id JOIN funnels fb ON fb.id = sb.funnel_id
                          WHERE m.category = ? AND m.is_active = 1
                          ORDER BY m.position, m.id');
    $st->execute([$category]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((string)$r['fc'] === '' || (string)$r['tc'] === '') continue;
        $out[] = [(string)$r['fc'], (string)$r['fn'], (string)$r['tc'], (string)$r['tn']];
    }
    return $out;
}

/**
 * As ligações de uma combinação de serviços (puro, D8). Para cada serviço ausente (pela ordem de PIPE2_SERVICES, uma
 * vez cada), cada ligação que entra nele liga-se a cada ligação que sai dele (origem da que entra → destino da que
 * sai) e as que lhe tocam caem; a que entra num ausente sem saída cai, e uma de um serviço para ele próprio também.
 * Sem poda. Ordem: pela posição no modelo da primeira ligação da cadeia e, em empate, pela da última; uma ligação
 * repetida (mesma origem e destino) fica uma vez, na primeira posição.
 */
function pipe2_model_links(array $links, array $codes): array {
    $present = [];
    foreach ($codes as $c) $present[(string)$c] = true;
    // [serviço de, sub-etapa de, serviço para, sub-etapa para, posição da 1.ª da cadeia, posição da última]
    $cur = [];
    foreach (array_values($links) as $i => $l) {
        [$fc, $fn, $tc, $tn] = array_map('strval', array_values($l));
        $cur[] = [$fc, $fn, $tc, $tn, $i, $i];
    }
    $seenAbsent = [];
    foreach ($cur as $l) {
        foreach ([$l[0], $l[2]] as $c) if (!isset($present[$c]) && !in_array($c, $seenAbsent, true)) $seenAbsent[] = $c;
    }
    $order = array_keys(PIPE2_SERVICES);
    $absent = array_merge(array_values(array_filter($order, fn($c) => in_array($c, $seenAbsent, true))), array_values(array_diff($seenAbsent, $order)));
    foreach ($absent as $x) {
        $in = $out = $keep = [];
        foreach ($cur as $l) {
            $toX = $l[2] === $x;
            $fromX = $l[0] === $x;
            if ($toX && !$fromX) $in[] = $l;
            elseif ($fromX && !$toX) $out[] = $l;
            elseif (!$toX && !$fromX) $keep[] = $l;   // de X para X cai
        }
        foreach ($in as $a) {
            foreach ($out as $b) {
                if ($a[0] !== $b[2]) $keep[] = [$a[0], $a[1], $b[2], $b[3], $a[4], $b[5]];
            }
        }
        $cur = $keep;
    }
    $cur = array_values(array_filter($cur, fn($l) => $l[0] !== $l[2]));
    usort($cur, fn($a, $b) => [$a[4], $a[5]] <=> [$b[4], $b[5]]);
    $res = [];
    $seen = [];
    foreach ($cur as $l) {
        $k = $l[0] . "\0" . $l[1] . "\0" . $l[2] . "\0" . $l[3];
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $res[] = [$l[0], $l[1], $l[2], $l[3]];
    }
    return $res;
}

// ── ligações do negócio ─────────────────────────────────────

/** O nome do serviço de uma frente: o da v2 pelo código; sem código, o do funil. */
function pipe2_dep_service(array $line): string {
    $code = (string)($line['service_code'] ?? '');
    return isset(PIPE2_SERVICES[$code]) ? PIPE2_SERVICES[$code]['name'] : (string)($line['funnel_name'] ?? '');
}

/** «VFX conclui «Finais e QC» → Composit «Integração e look»». */
function pipe2_dep_label(array $a, string $x, array $b, string $y): string {
    return pipe2_dep_service($a) . ' conclui «' . $x . '» → ' . pipe2_dep_service($b) . ' «' . $y . '»';
}

/**
 * Cria as ligações do modelo da categoria do negócio v2 (a gravada; sem category_at, a calculada agora) entre as
 * frentes vivas: cada código pela frente desse serviço, cada nome pela sub-etapa viva do funil dela. Idempotente por
 * SELECT antes de INSERT — só dentro do lock e da transação de pipe2_kickoff (ver o cabeçalho) — e nunca recria uma
 * ligação retirada. source 'model'; uma linha p2_dep quando cria alguma («Sistema» sem $by). Num v1, 0.
 */
function pipe2_deps_instantiate(PDO $pdo, array $deal, ?int $by = null): int {
    if (empty($deal['id']) || !pipe2_deal_is_v2($pdo, $deal)) return 0;
    $dealId = (int)$deal['id'];
    $st = $pdo->prepare('SELECT id, funnel_id, category, category_at FROM deals WHERE id = ?');
    $st->execute([$dealId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 0;
    $cat = $row['category_at'] !== null ? (string)$row['category'] : pipe2_deal_category($pdo, $row);
    $models = pipe2_models_for($pdo, $cat);
    if (!$models) return 0;

    $byCode = [];
    foreach (pipe2_fronts($pdo, $dealId) as $l) {
        foreach (pipe2_funnel_codes($l) as $c) $byCode[$c] ??= $l;
    }
    $names = [];
    $stageOf = function (array $line, string $name) use ($pdo, &$names): ?int {
        $fid = (int)$line['funnel_id'];
        if (!isset($names[$fid])) {
            $names[$fid] = [];
            foreach (pipe2_front_stages($pdo, $fid) as $s) $names[$fid][(string)$s['name']] ??= (int)$s['id'];
        }
        return $names[$fid][$name] ?? null;
    };

    $has = $pdo->prepare('SELECT COUNT(*) FROM deal_line_deps WHERE deal_id = ? AND from_line_id = ? AND from_stage_id = ? AND to_line_id = ? AND to_stage_id = ?');
    $ins = $pdo->prepare("INSERT INTO deal_line_deps (deal_id, from_line_id, from_stage_id, to_line_id, to_stage_id, source, created_by, created_at)
                          VALUES (?, ?, ?, ?, ?, 'model', ?, ?)");
    $n = 0;
    foreach (pipe2_model_links($models, array_keys($byCode)) as [$fc, $fn, $tc, $tn]) {
        $a = $byCode[$fc] ?? null;
        $b = $byCode[$tc] ?? null;
        if (!$a || !$b || (int)$a['id'] === (int)$b['id']) continue;
        $x = $stageOf($a, $fn);
        $y = $stageOf($b, $tn);
        if ($x === null || $y === null) continue;
        $key = [$dealId, (int)$a['id'], $x, (int)$b['id'], $y];
        $has->execute($key);
        $exists = (int)$has->fetchColumn() > 0;
        $has->closeCursor();
        if ($exists) continue;   // também a retirada: nunca se recria
        $ins->execute([...$key, $by, date('Y-m-d H:i:s')]);
        $n++;
    }
    if ($n > 0) {
        deal_log($pdo, $dealId, 'p2_dep', ['note' => 'Dependências do modelo ' . pipe2_category_label($pdo, $cat) . ': ' . $n . ($n === 1 ? ' ligação' : ' ligações'),
                                           'user_id' => $by, 'system' => $by === null]);
    }
    return $n;
}

/**
 * As ligações vivas (não retiradas) para (frente, sub-etapa), por id; só as por cumprir, salvo $unmetOnly = false.
 * Cada uma com from_stage_name, from_funnel_name, from_service_code e from_service (o nome do serviço de A).
 */
function pipe2_deps_into(PDO $pdo, int $lineId, int $stageId, bool $unmetOnly = true): array {
    $st = $pdo->prepare('SELECT d.*, s.name AS from_stage_name, f.name AS from_funnel_name, f.service_code AS from_service_code
                           FROM deal_line_deps d
                           LEFT JOIN deal_lines l ON l.id = d.from_line_id LEFT JOIN funnels f ON f.id = l.funnel_id
                           LEFT JOIN funnel_stages s ON s.id = d.from_stage_id
                          WHERE d.to_line_id = ? AND d.to_stage_id = ? AND d.removed_at IS NULL' . ($unmetOnly ? ' AND d.met_at IS NULL' : '') . '
                          ORDER BY d.id');
    $st->execute([$lineId, $stageId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['from_service'] = pipe2_dep_service(['service_code' => $r['from_service_code'], 'funnel_name' => $r['from_funnel_name']]);
        $out[] = $r;
    }
    return $out;
}

/**
 * A frente A concluiu X: as ligações vivas de (A, X) por cumprir ficam cumpridas. Devolve ['met' => [ids],
 * 'released' => [[frente, sub-etapa], …]] — só os destinos sem mais nenhuma ligação por cumprir. Não abre transação
 * (corre na do movimento da frente) nem move ninguém (isso é do motor).
 */
function pipe2_deps_mark_met(PDO $pdo, int $dealId, int $fromLineId, int $fromStageId): array {
    $out = ['met' => [], 'released' => []];
    $st = $pdo->prepare('SELECT id, to_line_id, to_stage_id FROM deal_line_deps
                          WHERE deal_id = ? AND from_line_id = ? AND from_stage_id = ? AND removed_at IS NULL AND met_at IS NULL ORDER BY id');
    $st->execute([$dealId, $fromLineId, $fromStageId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return $out;
    $up = $pdo->prepare('UPDATE deal_line_deps SET met_at = ? WHERE id = ? AND met_at IS NULL AND removed_at IS NULL');
    $now = date('Y-m-d H:i:s');
    $targets = [];
    foreach ($rows as $r) {
        $up->execute([$now, (int)$r['id']]);
        if ($up->rowCount() !== 1) continue;
        $out['met'][] = (int)$r['id'];
        $targets[$r['to_line_id'] . ':' . $r['to_stage_id']] = [(int)$r['to_line_id'], (int)$r['to_stage_id']];
    }
    $left = $pdo->prepare('SELECT COUNT(*) FROM deal_line_deps WHERE to_line_id = ? AND to_stage_id = ? AND removed_at IS NULL AND met_at IS NULL');
    foreach ($targets as [$l, $s]) {
        $left->execute([$l, $s]);
        $n = (int)$left->fetchColumn();
        $left->closeCursor();
        if ($n === 0) $out['released'][] = [$l, $s];
    }
    return $out;
}

/** Uma frente viva do negócio (com o funil e a sub-etapa), ou null. */
function pipe2_dep_front(PDO $pdo, int $dealId, int $lineId): ?array {
    $l = pipe2_front_get($pdo, $lineId);
    return $l && (int)$l['deal_id'] === $dealId && $l['status'] !== 'merged' ? $l : null;
}

/** Uma sub-etapa viva do funil da frente, ou null. */
function pipe2_dep_stage(PDO $pdo, array $line, int $stageId): ?array {
    $st = $pdo->prepare("SELECT * FROM funnel_stages WHERE id = ? AND funnel_id = ? AND level = 'front' AND retired_at IS NULL");
    $st->execute([$stageId, (int)$line['funnel_id']]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A frente já concluiu a sub-etapa: está numa sub-etapa a seguir, ou À espera no fim dela. */
function pipe2_dep_done(array $line, array $stage): bool {
    if (empty($line['stage_id'])) return false;
    if ((int)$line['stage_id'] === (int)$stage['id']) return $line['status'] === 'depwait';
    return [(int)$line['stage_position'], (int)$line['stage_id']] > [(int)$stage['position'], (int)$stage['id']];
}

/**
 * A ligação nova (A, X) → (B, Y) fecha um ciclo: concluir X em A exige (pela ordem das sub-etapas de cada frente e
 * pelas ligações vivas, cumpridas ou não) que B conclua uma sub-etapa em Y ou depois — e B só passa de Y depois de A
 * concluir X.
 */
function pipe2_dep_cycles(PDO $pdo, int $dealId, int $aLine, int $aStage, int $bLine, int $bStage): bool {
    $rank = [];
    $st = $pdo->prepare("SELECT s.id, s.funnel_id FROM funnel_stages s WHERE s.level = 'front'
                            AND s.funnel_id IN (SELECT l.funnel_id FROM deal_lines l WHERE l.deal_id = ?)
                          ORDER BY s.funnel_id, s.position, s.id");
    $st->execute([$dealId]);
    $n = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $f = (int)$s['funnel_id'];
        $rank[(int)$s['id']] = $n[$f] = ($n[$f] ?? -1) + 1;
    }
    $st = $pdo->prepare('SELECT from_line_id, from_stage_id, to_line_id, to_stage_id FROM deal_line_deps WHERE deal_id = ? AND removed_at IS NULL');
    $st->execute([$dealId]);
    $deps = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!isset($rank[$aStage], $rank[$bStage])) return false;
    // need[frente] = a última sub-etapa (pela ordem) que essa frente tem de concluir antes de A concluir X
    $need = [$aLine => $rank[$aStage]];
    $queue = [$aLine];
    while ($queue) {
        $l = array_shift($queue);
        foreach ($deps as $d) {
            if ((int)$d['to_line_id'] !== $l || ($rank[(int)$d['to_stage_id']] ?? PHP_INT_MAX) > $need[$l]) continue;
            $src = (int)$d['from_line_id'];
            $sp = $rank[(int)$d['from_stage_id']] ?? -1;
            if ($sp > ($need[$src] ?? -1)) {
                $need[$src] = $sp;
                $queue[] = $src;
            }
        }
    }
    return isset($need[$bLine]) && $need[$bLine] >= $rank[$bStage];
}

/**
 * Uma ligação à mão: A conclui X → B entra em Y (source 'manual'). Recusa um negócio v1, frentes de outro negócio (ou
 * juntadas), a mesma frente, sub-etapas que não são do funil da frente, uma ligação viva igual e os ciclos. Se A já
 * concluiu X, nasce cumprida (senão B esperava por um movimento que já passou). Na sua transação (ou na de quem
 * chamou), com a linha do negócio bloqueada. deal_log 'p2_dep'. Devolve o id.
 */
function pipe2_dep_add(PDO $pdo, array $deal, int $fromLineId, int $fromStageId, int $toLineId, int $toStageId, ?int $by = null): int {
    if (empty($deal['id']) || !pipe2_deal_is_v2($pdo, $deal)) throw new RuntimeException('Este negócio não é da Pipeline v2.');
    $dealId = (int)$deal['id'];
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if (!db_is_sqlite($pdo)) {
            $lk = $pdo->prepare('SELECT id FROM deals WHERE id = ? FOR UPDATE');
            $lk->execute([$dealId]);
            $lk->fetchAll();
        }
        $a = pipe2_dep_front($pdo, $dealId, $fromLineId);
        $b = pipe2_dep_front($pdo, $dealId, $toLineId);
        if (!$a || !$b) throw new RuntimeException('Frente não encontrada.');
        if ((int)$a['id'] === (int)$b['id']) throw new RuntimeException('Uma frente não pode esperar por ela própria.');
        $x = pipe2_dep_stage($pdo, $a, $fromStageId);
        $y = pipe2_dep_stage($pdo, $b, $toStageId);
        if (!$x || !$y) throw new RuntimeException('Sub-etapa não encontrada.');
        $dup = $pdo->prepare('SELECT COUNT(*) FROM deal_line_deps WHERE deal_id = ? AND from_line_id = ? AND from_stage_id = ? AND to_line_id = ? AND to_stage_id = ?
                                AND removed_at IS NULL');
        $dup->execute([$dealId, $fromLineId, $fromStageId, $toLineId, $toStageId]);
        if ((int)$dup->fetchColumn() > 0) throw new RuntimeException('Esta ligação já existe.');
        if (pipe2_dep_cycles($pdo, $dealId, $fromLineId, $fromStageId, $toLineId, $toStageId)) throw new RuntimeException(PIPE2_DEP_CYCLE);
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO deal_line_deps (deal_id, from_line_id, from_stage_id, to_line_id, to_stage_id, source, met_at, created_by, created_at)
                       VALUES (?, ?, ?, ?, ?, 'manual', ?, ?, ?)")
            ->execute([$dealId, $fromLineId, $fromStageId, $toLineId, $toStageId, pipe2_dep_done($a, $x) ? $now : null, $by, $now]);
        $id = (int)$pdo->lastInsertId();
        deal_log($pdo, $dealId, 'p2_dep', ['note' => 'Ligação acrescentada: ' . pipe2_dep_label($a, (string)$x['name'], $b, (string)$y['name']), 'user_id' => $by]);
        if ($own) $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Retira uma ligação viva do negócio (removed_at, removed_by; o modelo não a recria). deal_log 'p2_dep'. Não liberta
 * a frente que esperava só por ela: isso é do motor (pipe2_front_reevaluate), a chamar por quem retira.
 */
function pipe2_dep_remove(PDO $pdo, array $deal, int $depId, ?int $by = null): void {
    if (empty($deal['id']) || !pipe2_deal_is_v2($pdo, $deal)) throw new RuntimeException('Este negócio não é da Pipeline v2.');
    $dealId = (int)$deal['id'];
    $st = $pdo->prepare('SELECT * FROM deal_line_deps WHERE id = ? AND deal_id = ? AND removed_at IS NULL');
    $st->execute([$depId, $dealId]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) throw new RuntimeException('Ligação não encontrada.');
    $up = $pdo->prepare('UPDATE deal_line_deps SET removed_at = ?, removed_by = ? WHERE id = ? AND removed_at IS NULL');
    $up->execute([date('Y-m-d H:i:s'), $by, $depId]);
    if ($up->rowCount() !== 1) throw new RuntimeException('Ligação não encontrada.');
    $a = pipe2_front_get($pdo, (int)$d['from_line_id']) ?? [];
    $b = pipe2_front_get($pdo, (int)$d['to_line_id']) ?? [];
    $name = function (int $id) use ($pdo): string {
        $s = $pdo->prepare('SELECT name FROM funnel_stages WHERE id = ?');
        $s->execute([$id]);
        return (string)$s->fetchColumn();
    };
    deal_log($pdo, $dealId, 'p2_dep', ['note' => 'Ligação retirada: ' . pipe2_dep_label($a, $name((int)$d['from_stage_id']), $b, $name((int)$d['to_stage_id'])), 'user_id' => $by]);
}

// ── dias úteis ──────────────────────────────────────────────

/** Um dia útil: os mesmos de flow_add_workdays (segunda a sexta; também sábado com work_days = 6). */
function pipe2_is_workday(string $date): bool {
    $last = (string)setting($GLOBALS['pdo'], 'work_days', '5') === '6' ? 6 : 5;
    return (int)(new DateTimeImmutable(substr($date, 0, 10)))->format('N') <= $last;
}

/** O dia, se for útil; senão o dia útil seguinte. */
function pipe2_workday_from(string $date): string {
    $d = substr($date, 0, 10);
    return pipe2_is_workday($d) ? $d : flow_add_workdays($d, 1);
}

/** Os dias úteis depois de $from até $to, inclusive (0 se $to não for depois de $from). */
function pipe2_workdays_between(string $from, string $to): int {
    $from = substr($from, 0, 10);
    $to = substr($to, 0, 10);
    if ($to <= $from) return 0;
    $n = 0;
    $d = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    while ($d < $end) {
        $d = $d->modify('+1 day');
        if (pipe2_is_workday($d->format('Y-m-d'))) $n++;
    }
    return $n;
}

// ── plano e replaneamento (D17) ─────────────────────────────

/** As linhas de passos da frente no ciclo dela: sub-etapa => linha (a primeira, se houver duas). */
function pipe2_plan_steps(PDO $pdo, array $line): array {
    $st = $pdo->prepare('SELECT * FROM deal_line_steps WHERE line_id = ? AND cycle_ym = ? ORDER BY id');
    $st->execute([(int)$line['id'], (string)($line['cycle_ym'] ?? '')]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['stage_id']] ??= $r;
    return $out;
}

/**
 * O plano das sub-etapas da frente, a partir de $start (o dia em que começa; ao fim de semana, o dia útil seguinte):
 * cada sub-etapa com dias acaba N dias úteis depois de começar e a seguinte começa nesse dia (0 dias: começa e acaba
 * no mesmo dia); sem dias (nem na linha de passos nem na sub-etapa), sem plano. Recomeça o replaneamento
 * (replanned_days 0). Só as linhas que existem no ciclo da frente. Devolve quantas ficaram planeadas.
 */
function pipe2_plan_front(PDO $pdo, array $line, string $start): int {
    $line = pipe2_front_get($pdo, (int)($line['id'] ?? 0)) ?? $line;
    if (empty($line['id']) || empty($line['funnel_id'])) return 0;
    $steps = pipe2_plan_steps($pdo, $line);
    $up = $pdo->prepare('UPDATE deal_line_steps SET plan_start = ?, plan_end = ?, replanned_days = 0 WHERE id = ?');
    $cursor = pipe2_workday_from($start);
    $n = 0;
    foreach (pipe2_front_stages($pdo, (int)$line['funnel_id']) as $s) {
        $row = $steps[(int)$s['id']] ?? null;
        if (!$row) continue;
        $days = $row['plan_days'] ?? $s['plan_days'];
        if ($days === null || $days === '') {
            $up->execute([null, null, (int)$row['id']]);
            continue;
        }
        $days = max(0, (int)$days);
        $end = $days > 0 ? flow_add_workdays($cursor, $days) : $cursor;
        $up->execute([$cursor, $end, (int)$row['id']]);
        $cursor = $end;
        $n++;
    }
    return $n;
}

/**
 * Os dias úteis de atraso da sub-etapa atual: depois do plan_end da linha de passos dela, até $today. 0 numa frente
 * que não está em curso (À espera, Por começar, Pronta…), numa avença em Pronto entre ciclos, ou sem plano.
 */
function pipe2_front_late_days(array $line, ?array $step, string $today): int {
    if (!in_array((string)($line['status'] ?? ''), ['running', 'ongoing'], true)) return 0;
    if (($line['stage_role'] ?? '') === 'ready') return 0;
    $end = (string)($step['plan_end'] ?? '');
    return $end === '' ? 0 : pipe2_workdays_between($end, $today);
}

/**
 * O replaneamento (relógio): se a sub-etapa atual da frente em curso passou o plan_end, as sub-etapas seguintes com
 * plano deslizam pelos dias úteis de atraso que ainda não deslizaram (o que já deslizou é o intervalo entre o fim da
 * atual e o início da seguinte, que o plano deixa colado), somados em replanned_days. A entrega não muda. Devolve os
 * dias úteis deslizados agora (0 À espera, Por começar, Pronta, sem plano ou sem atraso novo); > 0 → p2_replan
 * («Sistema»). O aviso ao responsável é do relógio (tarefa 13).
 */
function pipe2_replan(PDO $pdo, array $line, string $today): int {
    $line = pipe2_front_get($pdo, (int)($line['id'] ?? 0));
    if (!$line || empty($line['stage_id'])) return 0;
    $steps = pipe2_plan_steps($pdo, $line);
    $cur = $steps[(int)$line['stage_id']] ?? null;
    $late = pipe2_front_late_days($line, $cur, $today);
    if ($late <= 0) return 0;
    $after = [];
    $past = false;
    foreach (pipe2_front_stages($pdo, (int)$line['funnel_id']) as $s) {
        if ((int)$s['id'] === (int)$line['stage_id']) { $past = true; continue; }
        $row = $steps[(int)$s['id']] ?? null;
        if ($past && $row && $row['plan_start'] !== null) $after[] = $row;
    }
    if (!$after) return 0;
    $inc = $late - pipe2_workdays_between((string)$cur['plan_end'], (string)$after[0]['plan_start']);
    if ($inc <= 0) return 0;

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('UPDATE deal_line_steps SET plan_start = ?, plan_end = ?, replanned_days = replanned_days + ? WHERE id = ?');
        foreach ($after as $r) {
            $up->execute([flow_add_workdays((string)$r['plan_start'], $inc), $r['plan_end'] !== null ? flow_add_workdays((string)$r['plan_end'], $inc) : null, $inc, (int)$r['id']]);
        }
        $wd = fn(int $n) => $n . ($n === 1 ? ' dia útil' : ' dias úteis');
        deal_log($pdo, (int)$line['deal_id'], 'p2_replan', [
            'note' => pipe2_dep_service($line) . ': «' . (string)$line['stage_name'] . '» com ' . $wd($late) . ' de atraso; as sub-etapas seguintes deslizaram '
                    . $wd($inc) . ' (replaneado; a entrega não muda)',
            'user_id' => null, 'system' => true]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $inc;
}

// ── datas de entrega (D17) ──────────────────────────────────

/** A entrega da frente (puro): a dela, se estiver preenchida; senão a do negócio; senão null. Não olha para sync_mode. */
function pipe2_front_delivery(array $deal, array $line): ?string {
    foreach ([$line['delivery_date'] ?? null, $deal['delivery_date'] ?? null] as $d) {
        if ($d !== null && (string)$d !== '') return substr((string)$d, 0, 10);
    }
    return null;
}

/** A entrega do negócio: deals.delivery_date; senão a maior das frentes vivas; senão null. */
function pipe2_deal_delivery(PDO $pdo, array $deal): ?string {
    $d = (string)($deal['delivery_date'] ?? '');
    if ($d !== '') return substr($d, 0, 10);
    if (empty($deal['id'])) return null;
    $st = $pdo->prepare("SELECT MAX(delivery_date) FROM deal_lines WHERE deal_id = ? AND status <> 'merged' AND delivery_date IS NOT NULL");
    $st->execute([(int)$deal['id']]);
    $v = $st->fetchColumn();
    $st->closeCursor();
    return $v ? substr((string)$v, 0, 10) : null;
}

/** Uma data de entrega do pedido: '' ou null → null; AAAA-MM-DD válida → ela; senão lança. */
function pipe2_date_in($v): ?string {
    if ($v === null || trim((string)$v) === '') return null;
    $v = trim((string)$v);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) throw new RuntimeException(PIPE2_DATE_BAD);
    return $v;
}

function pipe2_date_label(?string $d): string {
    return $d ? date('d/m/Y', strtotime($d)) : '—';
}

/**
 * Grava as datas de entrega de um negócio v2. Com $common (string; '' limpa): no negócio, e as das frentes ficam
 * limpas. Com $common null: as de $byLine ([frente => data|''], só frentes vivas do negócio) e o negócio fica com a
 * maior das frentes vivas (sem nenhuma, a do negócio fica e as frentes seguem-na). Valida tudo antes de gravar
 * («Data de entrega inválida.», «Frente não encontrada.»). deal_log 'dates' quando muda alguma. Depois, os prazos
 * das tarefas que contam da entrega acompanham (pipe2_due_refresh). Devolve quantas tarefas mudaram de prazo.
 */
function pipe2_dates_save(PDO $pdo, array $deal, ?string $common, array $byLine, ?int $by = null): int {
    if (empty($deal['id']) || !pipe2_deal_is_v2($pdo, $deal)) throw new RuntimeException('Este negócio não é da Pipeline v2.');
    $dealId = (int)$deal['id'];
    $cur = deal_get($pdo, $dealId) ?? $deal;
    $all = pipe2_fronts($pdo, $dealId, false);
    $old = [];
    foreach ($all as $l) $old[(int)$l['id']] = $l['delivery_date'] !== null && $l['delivery_date'] !== '' ? substr((string)$l['delivery_date'], 0, 10) : null;
    $live = array_filter($all, fn($l) => $l['status'] !== 'merged');
    $liveIds = array_map(fn($l) => (int)$l['id'], $live);
    $oldDeal = ($cur['delivery_date'] ?? null) ? substr((string)$cur['delivery_date'], 0, 10) : null;

    $new = $old;
    if ($common !== null) {
        $newDeal = pipe2_date_in($common);
        foreach ($new as $id => $v) $new[$id] = null;
    } else {
        foreach ($byLine as $lid => $v) {
            if (!in_array((int)$lid, $liveIds, true)) throw new RuntimeException('Frente não encontrada.');
            $new[(int)$lid] = pipe2_date_in($v);
        }
        $max = null;
        foreach ($liveIds as $id) if ($new[$id] !== null && ($max === null || $new[$id] > $max)) $max = $new[$id];
        $newDeal = $max ?? $oldDeal;
    }
    $changedLines = array_keys(array_filter($new, fn($v, $id) => $v !== $old[$id], ARRAY_FILTER_USE_BOTH));

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if ($newDeal !== $oldDeal) $pdo->prepare('UPDATE deals SET delivery_date = ? WHERE id = ?')->execute([$newDeal, $dealId]);
        $up = $pdo->prepare('UPDATE deal_lines SET delivery_date = ? WHERE id = ?');
        foreach ($changedLines as $id) $up->execute([$new[$id], $id]);
        if ($changedLines) $GLOBALS['XSELL_FRESH'] = true;
        if ($newDeal !== $oldDeal || $changedLines) {
            if ($common !== null) {
                $note = 'Entrega: ' . pipe2_date_label($newDeal) . ' (comum a todas as frentes)';
            } else {
                $parts = [];
                foreach ($live as $l) $parts[] = pipe2_dep_service($l) . ' ' . pipe2_date_label($new[(int)$l['id']]);
                $note = 'Entrega por frente: ' . implode(' · ', $parts) . ' · negócio ' . pipe2_date_label($newDeal);
            }
            deal_log($pdo, $dealId, 'dates', ['note' => $note, 'user_id' => $by]);
        }
        $n = pipe2_due_refresh($pdo, $dealId);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $n;
}

/**
 * Os prazos das tarefas abertas que contam a partir de uma data do negócio (entrega, rodagem, validade) acompanham,
 * como em flow_dates_changed: as de uma frente v2 pela entrega da frente (pipe2_front_delivery); as de uma frente v1
 * como na v1 (xsell_vdeal); as do negócio pela dele. Só as que mudam: due_date e updated_at = agora (o gcal_tick
 * envia-as ao Google). Devolve quantas mudaram. Os ids vêm com nome próprio (t_id): com «t.id, …, a.*» o id da regra
 * sobrepunha-se ao da tarefa.
 */
function pipe2_due_refresh(PDO $pdo, int $dealId): int {
    $deal = deal_get($pdo, $dealId);
    if (!$deal) return 0;
    $st = $pdo->prepare("SELECT a.*, t.id AS t_id, t.line_id AS t_line, t.due_date AS t_due
                           FROM tasks t JOIN stage_automations a ON a.id = t.auto_rule_id
                          WHERE t.deal_id = ? AND t.status <> 'done' AND a.due_mode IN ('delivery','shoot','validity')
                          ORDER BY t.id");
    $st->execute([$dealId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;
    require_once __DIR__ . '/xsell.php';   // xsell_line_get, xsell_vdeal
    $lines = [];
    $up = $pdo->prepare('UPDATE tasks SET due_date = ?, updated_at = ? WHERE id = ?');
    $today = today_lisbon();
    $n = 0;
    foreach ($rows as $row) {
        $lid = (int)($row['t_line'] ?? 0);
        if ($lid && !array_key_exists($lid, $lines)) $lines[$lid] = xsell_line_get($pdo, $lid);
        $line = $lid ? $lines[$lid] : null;
        $v = $deal;
        if ($line) $v = pipe2_line_is_v2($line) ? ['delivery_date' => pipe2_front_delivery($deal, $line)] + $deal : xsell_vdeal($deal, $line);
        [$due] = flow_due($pdo, $row, $v, $today);
        if ((string)($row['t_due'] ?? '') === (string)($due ?? '')) continue;
        $up->execute([$due, date('Y-m-d H:i:s'), (int)$row['t_id']]);
        $n++;
    }
    return $n;
}

/**
 * O ramo v2 de xsell_save_plan: as datas de entrega (comum ou por frente) e, só para admins (is_admin(), como na
 * v1), o responsável de cada frente (pipe2_user_ok: nunca o superadmin nem uma conta desativada; inválido → por
 * atribuir). Sem a validação da sincronização da v1: $in['delivery_mode'] 'common' | 'front' (sem ele, o sync_mode
 * da v1 dá o modo — independent → por frente, sync → comum —, e um pedido inválido segue pelas datas que traz).
 * Datas: comum em $in['delivery_date'] (sem ela, a do negócio fica); por frente em $in['line_delivery'][frente] (ou
 * $in['delivery'][frente], o nome da v1). Responsáveis em $in['line_exec'][frente] (ou $in['exec']). Devolve a
 * mensagem para quem guardou.
 */
function pipe2_plan_save(PDO $pdo, array $deal, array $in, ?int $by = null): string {
    if (empty($deal['id']) || !pipe2_deal_is_v2($pdo, $deal)) throw new RuntimeException('Este negócio não é da Pipeline v2.');
    $dealId = (int)$deal['id'];
    $deal = deal_get($pdo, $dealId) ?? $deal;
    if (($deal['phase'] ?? '') === 'done') throw new RuntimeException('O negócio já está concluído.');
    $lineDates = (array)($in['line_delivery'] ?? $in['delivery'] ?? []);
    $mode = (string)($in['delivery_mode'] ?? '');
    if (!in_array($mode, ['common', 'front'], true)) {
        $mode = match ((string)($in['sync_mode'] ?? '')) { 'independent' => 'front', 'sync' => 'common', default => '' };
        if ($mode === '') $mode = $lineDates ? 'front' : 'common';
    }
    $common = null;
    if ($mode === 'common') {
        $common = array_key_exists('delivery_date', $in) ? (string)($in['delivery_date'] ?? '') : (string)(pipe2_deal_delivery($pdo, $deal) ?? '');
        pipe2_date_in($common);
    }

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $execs = (array)($in['line_exec'] ?? $in['exec'] ?? []);
        if ($execs && is_admin()) {
            $up = $pdo->prepare('UPDATE deal_lines SET exec_owner_id = ? WHERE id = ? AND deal_id = ?');
            foreach (pipe2_fronts($pdo, $dealId) as $l) {
                $lid = (int)$l['id'];
                if (!array_key_exists($lid, $execs)) continue;
                $up->execute([pipe2_user_ok($pdo, $execs[$lid]), $lid, $dealId]);
            }
            $GLOBALS['XSELL_FRESH'] = true;
        }
        pipe2_dates_save($pdo, $deal, $common, $mode === 'front' ? $lineDates : [], $by);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return 'Plano das frentes guardado: data de entrega ' . ($mode === 'common' ? 'comum a todas as frentes.' : 'por frente.');
}
