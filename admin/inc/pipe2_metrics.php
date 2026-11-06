<?php
/**
 * topper. admin — Pipeline v2: métricas v2
 *
 * Funções novas, ainda não ligadas aos ecrãs (isso é a fase 4); as métricas da v1 (inc/metrics.php) não mudam. Leem os
 * registos de metrics_deals() e acrescentam o que a v2 precisa:
 *   · serviço v2 de um negócio = os códigos do funil atual (cur_fu) e das frentes (sv), nunca o funil de origem (fu,
 *     origin_funnel_id, que fica como «começou em»); um funil sem service_code dá os do mapa da v1 de cada xray_kind
 *     (PIPE2_V1_MAP; um combinado «vfx,cgi» conta em VFX e em 3D). O filtro (pipe2_mx_filter) e as metas
 *     (pipe2_goal_actual, âmbito 'service') usam-no;
 *   · categoria: a gravada no negócio (deals.category com category_at) prevalece; sem ela, calcula-se pelos códigos.
 *     Mudar as regras não reescreve as gravadas;
 *   · metas novas concluidos/valor_concluido, por done_at, numa constante própria (fora de MX_GOAL_METRICS: o formulário
 *     de Metas não as mostra);
 *   · totais do Pipeline: total em pipeline (abertos; com a aba Negócios, também os de produção) e «Ganhos a faturar»
 *     (os que chegaram ao Kickoff e não concluíram: Kickoff, Em execução, Entrega), sem ponderado. Valores: budget
 *     senão value_estimate; um negócio só de avenças vale a soma das mensalidades × meses (pipe2_deal_values, por lote);
 *   · tempo por sub-etapa, dos stage_events com frente (p2_front/p2_wait): a espera não conta, as reentradas somam-se e
 *     o intervalo ainda aberto não conta;
 *   · margem por frente: valor − custos com frente − equipa do serviço − a parte do que não tem frente.
 *
 * Permissões: nenhuma função daqui as verifica. A margem (pipe2_front_margin) é dado do Financeiro: quem a chama só a
 * pede com can('financeiro'), no servidor (na fase 1 nenhum ecrã a chama: tests/pipe2_metrics_test::margin_not_wired).
 * Os valores dos negócios veem-se com a aba Negócios; quem chama pipe2_totals decide se mostra os ganhos a faturar.
 * Sem o esquema da v2 (pipe2_schema_ready), as leituras das colunas novas dão vazio ou o resultado da v1.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/pipe2_category.php';   // pipe2_funnel_codes, pipe2_category_of, pipe2_category_services
require_once __DIR__ . '/pipe2_fronts.php';     // pipe2_fronts, pipe2_line_value, pipe2_line_is_monthly, pipe2_deal_total
require_once __DIR__ . '/metrics.php';          // mx_goal_actual, MX_GOAL_METRICS

/** As métricas de meta novas da v2 (por done_at); não entram em MX_GOAL_METRICS. */
const PIPE2_GOAL_METRICS = ['concluidos' => 'Concluídos', 'valor_concluido' => 'Valor concluído'];

// ── serviço v2 e categoria (X1, X3) ─────────────────────────

/** Os códigos dos serviços de cada funil: funnel_id => [códigos] (D26). Sem o esquema da v2, pelo mapa da v1. */
function pipe2_mx_funnel_codes(PDO $pdo): array {
    $cols = pipe2_schema_ready($pdo) ? 'id, service_code, xray_kind' : "id, '' AS service_code, xray_kind";
    $out = [];
    foreach ($pdo->query("SELECT $cols FROM funnels ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $out[(int)$f['id']] = pipe2_funnel_codes($f);
    }
    return $out;
}

/** Os códigos dos serviços de um registo de metrics_deals: do funil atual (cur_fu) e das frentes (sv); nunca de fu. */
function pipe2_mx_codes(array $d, array $fcodes): array {
    $codes = [];
    foreach (array_merge([(int)($d['cur_fu'] ?? 0)], array_map('intval', (array)($d['sv'] ?? []))) as $f) {
        if ($f > 0) $codes = array_merge($codes, (array)($fcodes[$f] ?? []));
    }
    return pipe2_category_services($codes);
}

/** As categorias gravadas: deal_id => deals.category, só dos negócios com category_at (também a gravada vazia). */
function pipe2_mx_categories(PDO $pdo): array {
    if (!pipe2_schema_ready($pdo)) return [];
    $out = [];
    foreach ($pdo->query('SELECT id, category FROM deals WHERE category_at IS NOT NULL ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = (string)$r['category'];
    }
    return $out;
}

/**
 * Os filtros globais pelo serviço v2: svc (código do serviço), cat (código da categoria: a gravada; sem gravada,
 * calculada pelos códigos com $rules = pipe2_category_rules), or (canal de origem) e pe (comercial), como mx_filter.
 * Um filtro vazio não filtra. Mantém as chaves.
 */
function pipe2_mx_filter(array $deals, array $fcodes, array $cats, array $f, array $rules = []): array {
    $svc = (string)($f['svc'] ?? '');
    $cat = (string)($f['cat'] ?? '');
    return array_filter($deals, function ($d) use ($fcodes, $cats, $f, $rules, $svc, $cat) {
        if (!empty($f['or']) && ($d['ch'] ?? '') !== $f['or']) return false;
        if (!empty($f['pe']) && (int)($d['owner'] ?? 0) !== (int)$f['pe']) return false;
        if ($svc === '' && $cat === '') return true;
        $codes = pipe2_mx_codes($d, $fcodes);
        if ($svc !== '' && !in_array($svc, $codes, true)) return false;
        if ($cat !== '') {
            $id = (int)$d['id'];
            $have = array_key_exists($id, $cats) ? (string)$cats[$id] : pipe2_category_of($codes, $rules);
            if ($have !== $cat) return false;
        }
        return true;
    });
}

// ── metas (X1, X2) ──────────────────────────────────────────

/**
 * O real de uma meta, na forma de mx_goal_actual: [valor, ids]. scope: company | service ($key = código) | user ($key =
 * id). As métricas da v1 (MX_GOAL_METRICS) contam como em mx_goal_actual, sobre os negócios do âmbito; concluidos e
 * valor_concluido contam os negócios com done_at no período (o valor é o do registo; quem chama pode trocá-lo pelo de
 * pipe2_deal_values). Métrica ou âmbito desconhecidos → [0, []].
 */
function pipe2_goal_actual(array $deals, array $fcodes, string $metric, string $scope, string $key, string $from, string $to): array {
    if ($scope === 'service') $deals = array_filter($deals, fn($d) => in_array($key, pipe2_mx_codes($d, $fcodes), true));
    elseif ($scope === 'user') $deals = array_filter($deals, fn($d) => (int)($d['owner'] ?? 0) === (int)$key);
    elseif ($scope !== 'company') return [0, []];
    if (isset(PIPE2_GOAL_METRICS[$metric])) {
        $done = array_filter($deals, fn($d) => mx_in($d['done_at'] ?? null, $from, $to));
        $ids = array_values(array_map(fn($d) => (int)$d['id'], $done));
        if ($metric === 'concluidos') return [count($ids), $ids];
        return [round(array_sum(array_map(fn($d) => (float)($d['value'] ?? 0), $done)), 2), $ids];
    }
    if (isset(MX_GOAL_METRICS[$metric])) return mx_goal_actual($deals, $metric, 'company', 0, $from, $to);
    return [0, []];
}

// ── valores e totais do Pipeline (X2, D4) ───────────────────

/** O total de um registo: de uma linha de deals, budget (> 0) senão value_estimate; de metrics_deals, o value. */
function pipe2_mx_total(array $d): float {
    return array_key_exists('value_estimate', $d) ? pipe2_deal_total($d) : (float)($d['value'] ?? 0);
}

/**
 * O valor de cada negócio, por lote (uma consulta por 500 negócios): id => pipe2_deal_value. Um negócio cujas frentes
 * vivas são todas avenças vale a soma das mensalidades × meses; os outros, o total.
 */
function pipe2_deal_values(PDO $pdo, array $deals): array {
    $out = [];
    foreach ($deals as $d) $out[(int)$d['id']] = pipe2_mx_total($d);
    if (!$out || !pipe2_schema_ready($pdo)) return $out;
    foreach (array_chunk(array_keys($out), 500) as $chunk) {
        $rows = $pdo->query("SELECT l.deal_id, l.billing, l.monthly_fee, l.fee_months, l.value FROM deal_lines l JOIN funnels f ON f.id = l.funnel_id
                              WHERE l.status <> 'merged' AND l.deal_id IN (" . implode(',', array_map('intval', $chunk)) . ')')->fetchAll(PDO::FETCH_ASSOC);
        $by = [];
        foreach ($rows as $l) $by[(int)$l['deal_id']][] = $l;
        foreach ($by as $id => $lines) {
            if (count(array_filter($lines, 'pipe2_line_is_monthly')) !== count($lines)) continue;
            $out[$id] = round(array_sum(array_map(fn($l) => (float)pipe2_line_value($l), $lines)), 2);
        }
    }
    return $out;
}

/**
 * A etapa de um negócio em produção nos «Ganhos a faturar»: kickoff, delivery (Entrega) ou exec (Em execução). Antes da
 * conversão, as etapas de produção da v1 contam como Em execução (o mapa v1→v2).
 */
function pipe2_mx_won_step(?array $stage): string {
    if ($stage && ($stage['kind'] ?? '') === 'kickoff') return 'kickoff';
    if ($stage && ($stage['role'] ?? '') === 'delivery') return 'delivery';
    return 'exec';
}

/**
 * Os totais do Pipeline sobre registos de metrics_deals: 'open' = os abertos no Pipeline (com $withProduction, também
 * os de produção) e 'won_to_bill' = os de produção (chegaram ao Kickoff e não concluíram), por etapa. Sem ponderado.
 * $values: id => valor (pipe2_deal_values); um negócio sem valor lá usa o value do registo.
 */
function pipe2_totals(array $deals, array $values, bool $withProduction): array {
    $open = ['n' => 0, 'value' => 0.0];
    $won = ['n' => 0, 'value' => 0.0, 'by' => ['kickoff' => 0, 'exec' => 0, 'delivery' => 0]];
    foreach ($deals as $d) {
        $phase = (string)($d['phase'] ?? '');
        $v = (float)($values[(int)$d['id']] ?? $d['value'] ?? 0);
        if ($phase === 'pipeline' || ($withProduction && $phase === 'production')) {
            $open['n']++;
            $open['value'] += $v;
        }
        if ($phase === 'production') {
            $won['n']++;
            $won['value'] += $v;
            $won['by'][pipe2_mx_won_step($d['stage'] ?? null)]++;
        }
    }
    $open['value'] = round($open['value'], 2);
    $won['value'] = round($won['value'], 2);
    return ['open' => $open, 'won_to_bill' => $won];
}

// ── tempo por sub-etapa (X3) ────────────────────────────────

/**
 * O tempo nas sub-etapas, por serviço → sub-etapa: [['code', 'stage_id', 'stage', 'n', 'secs', 'avg'], …] (segundos;
 * n = intervalos contados). Por frente, os passos p2_front/p2_wait por at, id: um p2_front para S abre um intervalo em S
 * que fecha no passo seguinte da frente; p2_wait abre uma espera (não conta); o intervalo ainda aberto não conta; as
 * reentradas somam-se. $o: 'from'/'to' (conta o intervalo que acaba em [from, to[), 'deal_ids'. Por ordem dos serviços
 * da v2 e da sub-etapa no funil; uma sub-etapa que já não existe fica de fora.
 */
function pipe2_substep_times(PDO $pdo, array $o = []): array {
    if (!pipe2_schema_ready($pdo)) return [];
    $where = "e.line_id IS NOT NULL AND e.step IN ('p2_front','p2_wait')";
    if (array_key_exists('deal_ids', $o)) {
        $ids = array_values(array_unique(array_map('intval', (array)$o['deal_ids'])));
        if (!$ids) return [];
        $where .= ' AND e.deal_id IN (' . implode(',', $ids) . ')';
    }
    $from = (string)($o['from'] ?? '');
    $to = (string)($o['to'] ?? '');
    $acc = [];   // sub-etapa => [n, segundos]
    $line = null;
    $open = null;   // [sub-etapa, início] do intervalo aberto da frente
    foreach ($pdo->query("SELECT e.id, e.line_id, e.step, e.to_stage_id, e.at FROM stage_events e WHERE $where ORDER BY e.line_id, e.at, e.id") as $e) {
        if ((int)$e['line_id'] !== $line) {
            $line = (int)$e['line_id'];
            $open = null;
        }
        $at = (string)$e['at'];
        if ($open !== null && ($from === '' || $at >= $from) && ($to === '' || $at < $to)) {
            [$sid, $start] = $open;
            $acc[$sid] ??= [0, 0];
            $acc[$sid][0]++;
            $acc[$sid][1] += max(0, strtotime($at) - strtotime($start));
        }
        $open = $e['step'] === 'p2_front' && (int)$e['to_stage_id'] > 0 ? [(int)$e['to_stage_id'], $at] : null;
    }
    if (!$acc) return [];
    $cols = 's.id, s.name, s.position, f.service_code, f.xray_kind';
    $stages = $pdo->query("SELECT $cols FROM funnel_stages s JOIN funnels f ON f.id = s.funnel_id WHERE s.id IN (" . implode(',', array_keys($acc)) . ')')
                  ->fetchAll(PDO::FETCH_ASSOC);
    $order = array_flip(array_keys(PIPE2_SERVICES));
    $rows = [];
    foreach ($stages as $s) {
        [$n, $secs] = $acc[(int)$s['id']];
        $code = (string)(pipe2_funnel_codes($s)[0] ?? '');
        $rows[] = ['sort' => [$order[$code] ?? count($order), (int)$s['position'], (int)$s['id']],
                   'row' => ['code' => $code, 'stage_id' => (int)$s['id'], 'stage' => (string)$s['name'], 'n' => $n, 'secs' => $secs, 'avg' => (int)round($secs / $n)]];
    }
    usort($rows, fn($a, $b) => $a['sort'] <=> $b['sort']);
    return array_column($rows, 'row');
}

// ── margem por frente (X3) ──────────────────────────────────

/**
 * A margem de cada frente viva do negócio: line_id => ['value', 'costs', 'team', 'shared', 'margin'] (€, sem IVA).
 * value = pipe2_line_value; costs = os custos reais do negócio (deal_costs: custos lançados na ficha e documentos de
 * despesa do Financeiro, não anulados nem apagados) com essa frente; team = custo das linhas 'team' da versão do
 * orçamento que conta (a aceite, senão a última, como em xsell_values) com o funil da frente (com uma só frente, também
 * as sem serviço); shared = a parte da frente, pelo valor, do que não tem frente (custos sem frente, reservas, linhas de
 * equipa sem serviço ou de um serviço que não é frente); sem valores, fica na principal. margin = value − costs − team
 * − shared. Dado do Financeiro: não verifica permissões, quem chama só a pede com can('financeiro').
 */
function pipe2_front_margin(PDO $pdo, int $dealId): array {
    if ($dealId <= 0 || !pipe2_schema_ready($pdo)) return [];
    $fronts = pipe2_fronts($pdo, $dealId);
    if (!$fronts) return [];
    $out = [];
    $byFunnel = [];
    foreach ($fronts as $l) {
        $out[(int)$l['id']] = ['value' => (float)(pipe2_line_value($l) ?? 0), 'costs' => 0.0, 'team' => 0.0, 'shared' => 0.0, 'margin' => 0.0];
        $byFunnel[(int)$l['funnel_id']] = (int)$l['id'];
    }
    $shared = 0.0;

    // os custos reais, como na ficha do negócio (a frente de um documento do Financeiro lê-se de fin_docs)
    require_once __DIR__ . '/finance.php';
    $finLine = [];
    if (db_has_table($pdo, 'fin_docs')) {
        $st = $pdo->prepare('SELECT id, line_id FROM fin_docs WHERE deal_id = ?');
        $st->execute([$dealId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $finLine[(int)$r['id']] = $r['line_id'] === null ? null : (int)$r['line_id'];
    }
    foreach (deal_costs($pdo, $dealId, vat_rate($pdo)) as $c) {
        if (!empty($c['fin_id'])) $lid = $finLine[(int)$c['fin_id']] ?? null;
        elseif (!empty($c['id'])) $lid = isset($c['line_id']) && $c['line_id'] !== null ? (int)$c['line_id'] : null;
        else $lid = null;   // reservas dos sócios: do negócio
        if ($lid !== null && isset($out[$lid])) $out[$lid]['costs'] += (float)$c['net'];
        else $shared += (float)$c['net'];
    }

    // a equipa declarada no orçamento
    require_once __DIR__ . '/budget.php';
    $sheet = budget_sheet_get($pdo, $dealId);
    $v = $sheet ? budget_current_version($pdo, $sheet) : null;
    if ($v) {
        $dh = (float)$v['day_hours'] ?: budget_setting($pdo, 'budget_day_hours');
        $single = count($fronts) === 1 ? (int)$fronts[0]['id'] : null;
        foreach (budget_lines($pdo, (int)$v['id']) as $bl) {
            if ($bl['kind'] !== 'team') continue;
            $lid = $byFunnel[(int)($bl['funnel_id'] ?? 0)] ?? $single;
            $cost = budget_line_cost($bl, $dh);
            if ($lid !== null) $out[$lid]['team'] += $cost;
            else $shared += $cost;
        }
    }

    // o que não tem frente, pelo valor de cada uma (o resto do arredondamento, e tudo se não houver valores, na principal)
    $ids = array_keys($out);
    $total = array_sum(array_column($out, 'value'));
    $shared = round($shared, 2);
    if ($shared != 0.0) {
        $left = $shared;
        foreach ($ids as $i => $id) {
            $part = $total > 0 ? round($shared * $out[$id]['value'] / $total, 2) : ($i === 0 ? $shared : 0.0);
            $out[$id]['shared'] = $part;
            $left -= $part;
        }
        $out[$ids[0]]['shared'] = round($out[$ids[0]]['shared'] + $left, 2);
    }
    foreach ($out as $id => $m) {
        $m['costs'] = round($m['costs'], 2);
        $m['team'] = round($m['team'], 2);
        $m['margin'] = round($m['value'] - $m['costs'] - $m['team'] - $m['shared'], 2);
        $out[$id] = $m;
    }
    return $out;
}
