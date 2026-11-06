<?php
/**
 * topper. admin — Pipeline v2: «Em risco»
 *
 * Uma só função para o Pipeline, a aba Negócios, o Dashboard e a vista guardada «Em risco» (ainda não ligada a ecrã
 * nenhum: isso é a fase 2). Serve os negócios v1 e v2 e tem só os 4 motivos da decisão dos sócios (D25):
 *
 *  - «Sem movimento há N d» (stale): num negócio aberto (Pipeline ou produção), N dias ou mais (comparação ≥) desde o
 *    movimento mais recente — last_touch, converted_at, created_at, uma tarefa concluída, a entrada ou a saída de uma
 *    sub-etapa (deal_line_steps). Não se aplica a um negócio em Em execução sem nenhuma frente em curso (todas À
 *    espera, Por começar ou Prontas, também a avença Ativa em Pronto entre ciclos). Limite: deal_stale_days.
 *  - «Pagamento em atraso» (late_pay): uma fatura a receber emitida (issued ou partial; nem pedida, nem nota de
 *    crédito, nem apagada) com saldo (como o A receber: total − retenção − pago) e vencida (o vencimento, senão 30 dias
 *    depois da emissão, como fin_overdue_days, mas em relação a $today). Vale também para um negócio concluído. Os
 *    negócios de antes das faturas (o «legacy» do A receber) não contam.
 *  - «Entrega ultrapassada há N d» / «<Serviço>: entrega ultrapassada há N d» (late_delivery): num negócio aberto e
 *    por entregar (sem delivered_at e sem a tarefa do negócio «Enviar entregáveis finais…» feita, a mesma que marca o
 *    delivered_at no Concluído automático), a data comum (deals.delivery_date) passou; ou a data própria de uma frente
 *    por concluir passou. A frente não repete o negócio: quando a data comum também passou, só aparece a frente com a
 *    sua data mais cedo do que a comum (D17 grava a comum como a maior das frentes).
 *  - «<Serviço>: o atraso empurra a entrega» (push): a sub-etapa atual de uma frente em curso passou o fim do plano e,
 *    somando os dias úteis desse atraso que o replaneamento ainda não deslizou, o fim do trabalho da frente (o maior
 *    plan_end das sub-etapas daqui em diante, sem a Pronto) passa a entrega da frente (a dela, senão a comum). Uma
 *    sub-etapa atrasada que não empurra é só aviso (o replaneamento); com a entrega já passada é «entrega ultrapassada».
 *
 * Tarefas atrasadas e «Fecho incompleto» não são risco. Os rótulos nunca têm euros nem números de faturas. Cada motivo:
 * ['code', 'label', 'sev', 'icon', 'line' => id da frente ou null, 'days' => dias (para ordenar e para o detalhe)]; o
 * mais grave primeiro (gravidade, depois a ordem de PIPE2_RISK, o do negócio antes dos das frentes, mais dias primeiro).
 *
 * Sem N+1: pipe2_risk_many lê tudo em 5 consultas agregadas, seja qual for o número de negócios, compatíveis com
 * ONLY_FULL_GROUP_BY. Só leitura; sem sessão nem permissões (quem mostra decide o que pode mostrar: o motivo
 * «Pagamento em atraso» não revela valores). Sem o esquema da v2 (antes do passo 43) não calcula nada.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/pipe2_deps.php';   // pipe2_front_delivery, pipe2_front_late_days, pipe2_workdays_between, pipe2_dep_service, flow_add_workdays
require_once __DIR__ . '/alerts.php';       // alert_limits

/** Os motivos: código => [rótulo, ícone, gravidade], pela ordem de desempate. */
const PIPE2_RISK = [
    'late_pay'      => ['Pagamento em atraso', 'euro', 3],
    'late_delivery' => ['Entrega ultrapassada', 'clock', 3],
    'push'          => ['Atraso empurra a entrega', 'clock', 2],
    'stale'         => ['Sem movimento', 'pause', 1],
];

/** O início do título (normalizado) da tarefa do negócio que conta como entrega feita (D16). */
const PIPE2_RISK_SENT = 'enviar entregáveis finais';

/** As fases em que um negócio pode ficar parado ou atrasar a entrega. */
const PIPE2_RISK_OPEN = ['pipeline', 'production'];

/** Os estados de uma frente concluída (v2 e v1). */
const PIPE2_RISK_FRONT_DONE = ['ready', 'merged', 'done'];

/** O limite de «sem movimento» (dias): o dos alertas dos negócios (deal_stale_days). */
function pipe2_risk_limit(PDO $pdo): int {
    return (int)alert_limits($pdo)['deal'];
}

/** Os dias de calendário de $from a $to (AAAA-MM-DD…); negativo se $to for antes. */
function pipe2_risk_days(string $from, string $to): int {
    return (int)(new DateTimeImmutable(substr($from, 0, 10)))->diff(new DateTimeImmutable(substr($to, 0, 10)))->format('%r%a');
}

/**
 * Uma data válida (AAAA-MM-DD; de um DATETIME, o dia), ou null: NULL, '' e a «0000-00-00» que o MySQL sem modo estrito
 * grava no lugar de uma data inválida (contaria como entregue, vencida há séculos, ou rebentaria nos dias úteis).
 */
function pipe2_risk_date($v): ?string {
    $s = substr(trim((string)($v ?? '')), 0, 10);
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : null;
}

/** A mais recente das datas (AAAA-MM-DD ou null), ou null. */
function pipe2_risk_latest(array $dates): ?string {
    $dates = array_filter($dates, fn($d) => $d !== null);
    return $dates ? max($dates) : null;
}

function pipe2_risk_reason(string $code, string $label, ?int $line, int $days): array {
    [, $icon, $sev] = PIPE2_RISK[$code];
    return ['code' => $code, 'label' => $label, 'sev' => $sev, 'icon' => $icon, 'line' => $line, 'days' => $days];
}

/** Os motivos, o mais grave primeiro. */
function pipe2_risk_sort(array $reasons): array {
    $order = array_flip(array_keys(PIPE2_RISK));
    $key = fn(array $r) => [-(int)$r['sev'], $order[$r['code']] ?? 99, $r['line'] === null ? 0 : 1, -(int)($r['days'] ?? 0), (int)$r['line']];
    usort($reasons, fn($a, $b) => $key($a) <=> $key($b));
    return $reasons;
}

/** Uma frente em curso: Em curso (também a v1) ou avença Ativa fora da Pronto. */
function pipe2_risk_front_running(array $f): bool {
    $st = (string)$f['status'];
    return in_array($st, ['running', 'active'], true) || ($st === 'ongoing' && (string)$f['stage_role'] !== 'ready');
}

/** Uma frente por concluir: nem Pronta, nem juntada, nem concluída (v1), nem avença Ativa em Pronto entre ciclos. */
function pipe2_risk_front_pending(array $f): bool {
    $st = (string)$f['status'];
    return !in_array($st, PIPE2_RISK_FRONT_DONE, true) && !($st === 'ongoing' && (string)$f['stage_role'] === 'ready');
}

/**
 * O motivo «o atraso empurra a entrega» de uma frente, ou null. $steps = as linhas de passos do ciclo da frente, pela
 * ordem das sub-etapas (uma por sub-etapa).
 */
function pipe2_risk_push(array $deal, array $f, array $steps, string $today): ?array {
    if (empty($f['stage_id'])) return null;
    $due = pipe2_front_delivery($deal, $f);
    if ($due === null || $due < $today) return null;   // sem entrega, ou já passou (é «entrega ultrapassada»)
    $i = null;
    foreach ($steps as $k => $s) if ((int)$s['stage_id'] === (int)$f['stage_id']) { $i = $k; break; }
    if ($i === null) return null;
    $cur = $steps[$i];
    $late = pipe2_front_late_days($f, $cur, $today);
    if ($late <= 0) return null;
    $end = (string)$cur['plan_end'];
    $next = null;
    foreach (array_slice($steps, $i + 1) as $s) {
        if ($next === null && $s['plan_start'] !== null) $next = $s;
        if ($s['role'] !== 'ready' && $s['plan_end'] !== null && (string)$s['plan_end'] > $end) $end = (string)$s['plan_end'];
    }
    // o que o replaneamento já deslizou é o intervalo entre o fim da atual e o início da seguinte (pipe2_replan)
    $slid = $next ? pipe2_workdays_between((string)$cur['plan_end'], (string)$next['plan_start']) : 0;
    $inc = max(0, $late - $slid);
    $projected = $inc > 0 ? flow_add_workdays($end, $inc) : $end;
    if ($projected <= $due) return null;
    return pipe2_risk_reason('push', pipe2_dep_service($f) . ': o atraso empurra a entrega', (int)$f['id'], pipe2_workdays_between($due, $projected));
}

/**
 * Os motivos de risco de cada negócio: id => [motivo, …] (o mais grave primeiro; [] sem risco ou se o negócio não
 * existir), pela ordem de $deals (linhas com 'id'; os campos leem-se da base). $today: AAAA-MM-DD (por defeito, hoje
 * em Lisboa). Cinco consultas, seja qual for o número de negócios.
 */
function pipe2_risk_many(PDO $pdo, array $deals, ?string $today = null): array {
    $today = substr($today ?? today_lisbon(), 0, 10);
    $out = [];
    foreach ($deals as $d) $out[(int)(is_array($d) ? ($d['id'] ?? 0) : $d)] = [];
    $ids = array_values(array_filter(array_keys($out), fn($id) => $id > 0));
    if (!$ids || !pipe2_schema_ready($pdo)) return $out;
    $in = implode(',', $ids);
    $limit = pipe2_risk_limit($pdo);

    $deal = [];
    foreach ($pdo->query("SELECT d.id, d.phase, d.stage_id, d.last_touch, d.converted_at, d.created_at, d.delivery_date, d.delivered_at,
                                 COALESCE(s.role, '') AS stage_role
                            FROM deals d LEFT JOIN funnel_stages s ON s.id = d.stage_id
                           WHERE d.id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach (['last_touch', 'converted_at', 'created_at', 'delivery_date', 'delivered_at'] as $k) $r[$k] = pipe2_risk_date($r[$k]);
        $deal[(int)$r['id']] = $r;
    }
    if (!$deal) return $out;

    $done = [];   // negócio => [a tarefa feita mais recente, a «Enviar entregáveis finais» do negócio feita]
    $sent = $pdo->quote(PIPE2_RISK_SENT . '%');
    foreach ($pdo->query("SELECT deal_id, MAX(done_at) AS last_done,
                                 MAX(CASE WHEN line_id IS NULL AND LOWER(TRIM(title)) LIKE $sent THEN done_at END) AS sent_at
                            FROM tasks
                           WHERE deal_id IN ($in) AND status = 'done' AND done_at IS NOT NULL
                           GROUP BY deal_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $done[(int)$r['deal_id']] = [pipe2_risk_date($r['last_done']), pipe2_risk_date($r['sent_at'])];
    }

    $fronts = [];
    foreach ($pdo->query("SELECT l.id, l.deal_id, l.status, l.stage_id, l.delivery_date, l.cycle_ym,
                                 f.service_code, f.name AS funnel_name, COALESCE(s.role, '') AS stage_role
                            FROM deal_lines l JOIN funnels f ON f.id = l.funnel_id LEFT JOIN funnel_stages s ON s.id = l.stage_id
                           WHERE l.deal_id IN ($in) AND l.status <> 'merged'
                           ORDER BY l.deal_id, l.is_main DESC, l.position, l.id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['delivery_date'] = pipe2_risk_date($r['delivery_date']);
        $fronts[(int)$r['deal_id']][] = $r;
    }

    $moved = [];   // negócio => o dia mais recente em que uma frente entrou ou saiu de uma sub-etapa
    $steps = [];   // frente => ciclo => sub-etapa => linha (a primeira), pela ordem das sub-etapas
    foreach ($pdo->query("SELECT st.id, st.deal_id, st.line_id, st.stage_id, st.cycle_ym, st.plan_start, st.plan_end, st.started_at, st.done_at,
                                 COALESCE(fs.role, '') AS role
                            FROM deal_line_steps st LEFT JOIN funnel_stages fs ON fs.id = st.stage_id
                           WHERE st.deal_id IN ($in)
                           ORDER BY st.line_id, fs.position, st.stage_id, st.id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        foreach (['plan_start', 'plan_end', 'started_at', 'done_at'] as $k) $r[$k] = pipe2_risk_date($r[$k]);
        $did = (int)$r['deal_id'];
        $moved[$did] = pipe2_risk_latest([$moved[$did] ?? null, $r['started_at'], $r['done_at']]);
        $steps[(int)$r['line_id']][(string)$r['cycle_ym']][(int)$r['stage_id']] ??= $r;
    }

    $overdue = [];   // negócio => maior atraso (dias) das faturas emitidas por receber
    foreach ($pdo->query("SELECT deal_id, due_date, issue_date, total, withholding, paid_amount
                            FROM fin_docs
                           WHERE deal_id IN ($in) AND direction = 'in' AND status IN ('issued','partial')
                             AND deleted_at IS NULL AND doc_type <> 'credit'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (round((float)$r['total'] - (float)$r['withholding'] - (float)$r['paid_amount'], 2) <= 0.005) continue;
        $issued = pipe2_risk_date($r['issue_date']);
        $due = pipe2_risk_date($r['due_date']) ?? ($issued !== null ? date('Y-m-d', strtotime($issued . ' +30 days')) : null);
        if ($due === null) continue;
        $n = pipe2_risk_days($due, $today);
        if ($n > 0) $overdue[(int)$r['deal_id']] = max($n, $overdue[(int)$r['deal_id']] ?? 0);
    }

    foreach ($deal as $id => $d) {
        $reasons = [];
        $open = in_array((string)$d['phase'], PIPE2_RISK_OPEN, true);
        $lines = $fronts[$id] ?? [];

        if (isset($overdue[$id])) $reasons[] = pipe2_risk_reason('late_pay', PIPE2_RISK['late_pay'][0], null, $overdue[$id]);

        if ($open && $d['delivered_at'] === null && ($done[$id][1] ?? null) === null) {
            $common = $d['delivery_date'];
            $commonLate = $common !== null && $common < $today;
            if ($commonLate) {
                $n = pipe2_risk_days($common, $today);
                $reasons[] = pipe2_risk_reason('late_delivery', 'Entrega ultrapassada há ' . $n . ' d', null, $n);
            }
            foreach ($lines as $f) {
                $own = $f['delivery_date'];
                if ($own !== null && $own < $today && pipe2_risk_front_pending($f) && !($commonLate && $own >= $common)) {
                    $n = pipe2_risk_days($own, $today);
                    $reasons[] = pipe2_risk_reason('late_delivery', pipe2_dep_service($f) . ': entrega ultrapassada há ' . $n . ' d', (int)$f['id'], $n);
                }
                $push = pipe2_risk_push($d, $f, array_values($steps[(int)$f['id']][(string)$f['cycle_ym']] ?? []), $today);
                if ($push) $reasons[] = $push;
            }
        }

        if ($open && !((string)$d['stage_role'] === 'exec' && !array_filter($lines, 'pipe2_risk_front_running'))) {
            $last = pipe2_risk_latest([$d['last_touch'], $d['converted_at'], $d['created_at'], $done[$id][0] ?? null, $moved[$id] ?? null]);
            if ($last !== null && ($n = pipe2_risk_days($last, $today)) >= $limit) {
                $reasons[] = pipe2_risk_reason('stale', 'Sem movimento há ' . $n . ' d', null, $n);
            }
        }

        $out[$id] = pipe2_risk_sort($reasons);
    }
    return $out;
}

/** Os motivos de risco de um negócio (pipe2_risk_many de um). */
function pipe2_risk(PDO $pdo, array $deal, ?string $today = null): array {
    $id = (int)($deal['id'] ?? 0);
    return pipe2_risk_many($pdo, [$deal], $today)[$id] ?? [];
}

/** O rótulo curto do cartão: o do motivo mais grave e, havendo mais, « +n». Sem motivos, ''. */
function pipe2_risk_label(array $reasons): string {
    if (!$reasons) return '';
    $r = pipe2_risk_sort(array_values($reasons));
    return (string)$r[0]['label'] . (count($r) > 1 ? ' +' . (count($r) - 1) : '');
}
