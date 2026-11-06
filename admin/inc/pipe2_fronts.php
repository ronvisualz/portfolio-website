<?php
/**
 * topper. admin — Pipeline v2: frentes e modelo
 *
 * Todas as frentes vivem em deal_lines: a principal, a de um negócio de um só serviço e as outras. Antes do Kickoff
 * os serviços do negócio são as frentes em 'planned' (o código da v1); no Kickoff passam a frentes 'queued' (Por
 * começar), cada uma com uma linha de deal_line_steps por sub-etapa do seu funil.
 *
 * Um negócio é v2 quando o funil dele tem etapas de negócio vivas com role 'exec' e 'delivery' (D1). Antes do
 * «Aplicar» só os funis novos (Motion Design, Composit, Edição: inativos, só da v2, sem negócios) e os funis dos
 * testes cumprem a condição; nunca se decide pelo service_code (os funis reais já o têm) nem pela bandeira. Numa função
 * pura (sem ligação), uma frente é v2 pelo estado (PIPE2_LINE_STATUS) ou pela avença.
 *
 * Aqui também: o chefe do serviço (o responsável do departamento do funil, senão o do departamento-pai), os valores
 * (avença = mensalidade × meses), o período da avença, o requisito do orçamento por serviço e a guarda das etapas em
 * uso (del_stage, para todas as etapas).
 *
 * pipe2_kickoff_fronts não toma lock nem abre transação: é uma peça de pipe2_kickoff, que a chama dentro do lock do
 * negócio, numa transação com a linha do negócio bloqueada (FOR UPDATE) e depois de reler o negócio e as frentes. O
 * SELECT antes de cada INSERT só é idempotente aí. Nenhum outro código de produção a chama.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/pipeline.php';   // deal_get, funnel_get

/** Os estados de uma frente viva (todas menos 'merged', D4). */
const PIPE2_FRONT_LIVE = ['planned', 'queued', 'running', 'depwait', 'ready', 'ongoing'];

/** As chaves das etapas do negócio: kind ou role que as identifica. */
const PIPE2_DEAL_STAGE_KEYS = [
    'xray'     => ['kind', 'xray'],
    'proposal' => ['role', 'proposal'],
    'feedback' => ['role', 'feedback'],
    'kickoff'  => ['kind', 'kickoff'],
    'exec'     => ['role', 'exec'],
    'delivery' => ['role', 'delivery'],
    'done'     => ['kind', 'done'],
];

const PIPE2_STAGE_IN_USE = 'Há frentes ou tarefas nesta etapa: mova-as ou apague-as primeiro.';

// ── o predicado v2 (D1) ─────────────────────────────────────

/** Os funis v2, como subconsulta (para «funnel_id IN (…)»). */
function pipe2_v2_funnels_sql(): string {
    return "SELECT s.funnel_id FROM funnel_stages s WHERE s.level = 'deal' AND s.retired_at IS NULL AND s.role IN ('exec','delivery')"
         . ' GROUP BY s.funnel_id HAVING COUNT(DISTINCT s.role) = 2';
}

/** O funil tem etapas de negócio vivas com role 'exec' e 'delivery'. Sem as colunas da v2, nunca. Sem cache. */
function pipe2_funnel_is_v2(PDO $pdo, ?int $funnelId): bool {
    if (!$funnelId || !pipe2_schema_ready($pdo)) return false;
    $st = $pdo->prepare("SELECT COUNT(DISTINCT role) FROM funnel_stages
                          WHERE funnel_id = ? AND level = 'deal' AND retired_at IS NULL AND role IN ('exec','delivery')");
    $st->execute([$funnelId]);
    return (int)$st->fetchColumn() === 2;
}

/** O negócio é v2: o funil dele é v2 (D1). */
function pipe2_deal_is_v2(PDO $pdo, ?array $deal): bool {
    if (!$deal || empty($deal['funnel_id'])) return false;
    return pipe2_funnel_is_v2($pdo, (int)$deal['funnel_id']);
}

/** Puro: a frente é v2 pelo estado (um da v2) ou pela avença (a v1 nunca grava nenhum dos dois). */
function pipe2_line_is_v2(array $line): bool {
    return isset(PIPE2_LINE_STATUS[(string)($line['status'] ?? '')]) || pipe2_line_is_monthly($line);
}

// ── etapas ──────────────────────────────────────────────────

/** As sub-etapas vivas de um funil, pela ordem (sem cache). */
function pipe2_front_stages(PDO $pdo, int $funnelId): array {
    $st = $pdo->prepare("SELECT * FROM funnel_stages WHERE funnel_id = ? AND level = 'front' AND retired_at IS NULL ORDER BY position, id");
    $st->execute([$funnelId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Uma etapa viva do negócio pela chave (xray, proposal, feedback, kickoff, exec, delivery, done); sem cache. */
function pipe2_deal_stage(PDO $pdo, int $funnelId, string $key): ?array {
    if (!isset(PIPE2_DEAL_STAGE_KEYS[$key])) throw new InvalidArgumentException('Etapa desconhecida: ' . $key);
    [$col, $val] = PIPE2_DEAL_STAGE_KEYS[$key];
    $st = $pdo->prepare("SELECT * FROM funnel_stages WHERE funnel_id = ? AND level = 'deal' AND retired_at IS NULL AND $col = ? ORDER BY position, id LIMIT 1");
    $st->execute([$funnelId, $val]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── frentes ─────────────────────────────────────────────────

const PIPE2_FRONT_SELECT = 'SELECT l.*, f.name AS funnel_name, f.service_code, f.xray_kind, s.name AS stage_name, s.kind AS stage_kind,
                                   s.role AS stage_role, s.position AS stage_position
                              FROM deal_lines l JOIN funnels f ON f.id = l.funnel_id LEFT JOIN funnel_stages s ON s.id = l.stage_id';

/** As frentes do negócio (vivas, salvo $liveOnly = false), a principal primeiro, com o funil e a sub-etapa. */
function pipe2_fronts(PDO $pdo, int $dealId, bool $liveOnly = true): array {
    $st = $pdo->prepare(PIPE2_FRONT_SELECT . ' WHERE l.deal_id = ?' . ($liveOnly ? " AND l.status <> 'merged'" : '') . ' ORDER BY l.is_main DESC, l.position, l.id');
    $st->execute([$dealId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pipe2_front_get(PDO $pdo, int $lineId): ?array {
    if ($lineId <= 0) return null;
    $st = $pdo->prepare(PIPE2_FRONT_SELECT . ' WHERE l.id = ?');
    $st->execute([$lineId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── pessoas ─────────────────────────────────────────────────

/** Uma pessoa que pode ficar com uma frente ou uma tarefa: ativa, sem desativação e não superadmin. */
function pipe2_user_ok(PDO $pdo, $uid): ?int {
    if (!$uid || !is_numeric($uid) || (int)$uid <= 0) return null;
    $st = $pdo->prepare('SELECT id, role, is_active, deactivated_at FROM users WHERE id = ?');
    $st->execute([(int)$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || (int)$u['is_active'] !== 1 || ($u['deactivated_at'] ?? null) !== null || $u['role'] === 'superadmin') return null;
    return (int)$u['id'];
}

/** O chefe do serviço: o responsável do departamento do funil, senão o do departamento-pai; senão ninguém. */
function pipe2_service_head(PDO $pdo, int $funnelId): ?int {
    $st = $pdo->prepare('SELECT d.head_user_id, p.head_user_id AS parent_head
                           FROM funnels f JOIN departments d ON d.id = f.department_id LEFT JOIN departments p ON p.id = d.parent_id
                          WHERE f.id = ?');
    $st->execute([$funnelId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return pipe2_user_ok($pdo, $r['head_user_id']) ?? pipe2_user_ok($pdo, $r['parent_head']);
}

// ── os serviços antes do Kickoff ────────────────────────────

/** A frente principal (funil do negócio): o id e se foi criada agora. Só para negócios v2. */
function pipe2_main_line(PDO $pdo, array $deal): array {
    $dealId = (int)$deal['id'];
    $fid = (int)$deal['funnel_id'];
    $st = $pdo->prepare('SELECT id, is_main FROM deal_lines WHERE deal_id = ? AND funnel_id = ?');
    $st->execute([$dealId, $fid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if (!(int)$row['is_main']) {
            // a principal acompanha o funil do negócio (como em deal_change_funnel)
            $pdo->prepare('UPDATE deal_lines SET is_main = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE deal_id = ?')->execute([(int)$row['id'], $dealId]);
            $GLOBALS['XSELL_FRESH'] = true;
        }
        return [(int)$row['id'], false];
    }
    $pdo->prepare('UPDATE deal_lines SET is_main = 0 WHERE deal_id = ? AND is_main = 1')->execute([$dealId]);
    $pdo->prepare("INSERT INTO deal_lines (deal_id, funnel_id, is_main, position, status, created_at) VALUES (?, ?, 1, 0, 'planned', ?)")
        ->execute([$dealId, $fid, date('Y-m-d H:i:s')]);
    $GLOBALS['XSELL_FRESH'] = true;
    return [(int)$pdo->lastInsertId(), true];
}

/** Num negócio v2, garante a frente principal (funil do negócio, planned) e devolve o id; num v1, 0 sem escrever. */
function pipe2_services_ensure(PDO $pdo, array $deal): int {
    if (empty($deal['id']) || !pipe2_deal_is_v2($pdo, $deal)) return 0;
    return pipe2_main_line($pdo, $deal)[0];
}

/**
 * Junta um serviço (funil v2) a um negócio v2 antes do Kickoff, como frente 'planned'. Idempotente: o serviço que já
 * existe devolve a frente que tem. Não abre transação (a conversão da lead chama-a dentro da sua). Devolve o id.
 */
function pipe2_service_add(PDO $pdo, array $deal, int $funnelId, ?int $by = null): int {
    if (empty($deal['id']) || !pipe2_deal_is_v2($pdo, $deal)) throw new RuntimeException('Este negócio não é da Pipeline v2.');
    if (!in_array((string)($deal['phase'] ?? ''), ['lead', 'pipeline'], true)) {
        throw new RuntimeException('Depois do Kickoff não se juntam serviços: um serviço novo é um negócio novo, ligado a este.');
    }
    if (!pipe2_funnel_is_v2($pdo, $funnelId)) throw new RuntimeException('Este serviço não é um funil da Pipeline v2.');
    $main = pipe2_main_line($pdo, $deal)[0];
    if ($funnelId === (int)$deal['funnel_id']) return $main;
    $find = $pdo->prepare('SELECT id FROM deal_lines WHERE deal_id = ? AND funnel_id = ?');
    $find->execute([(int)$deal['id'], $funnelId]);
    if ($id = $find->fetchColumn()) return (int)$id;
    $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) FROM deal_lines WHERE deal_id = ' . (int)$deal['id'])->fetchColumn() + 1;
    try {
        $pdo->prepare("INSERT INTO deal_lines (deal_id, funnel_id, is_main, position, status, created_at) VALUES (?, ?, 0, ?, 'planned', ?)")
            ->execute([(int)$deal['id'], $funnelId, $pos, date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        // UNIQUE (deal_id, funnel_id): outro pedido juntou-o entretanto
        if ((string)$e->getCode() !== '23000') throw $e;
        $find->execute([(int)$deal['id'], $funnelId]);
        if ($id = $find->fetchColumn()) return (int)$id;
        throw $e;
    }
    $id = (int)$pdo->lastInsertId();
    $GLOBALS['XSELL_FRESH'] = true;
    $name = (string)(funnel_get($pdo, $funnelId)['name'] ?? '');
    deal_log($pdo, (int)$deal['id'], 'p2_services', ['note' => 'Serviço acrescentado: ' . $name, 'user_id' => $by]);
    return $id;
}

// ── o Kickoff ───────────────────────────────────────────────

/**
 * As frentes no Kickoff (D4/D6): garante a principal; cada frente 'planned' passa a 'queued' (sem sub-etapa), com o
 * responsável vazio preenchido (principal: deals.exec_owner_id; senão o chefe do serviço; nunca o superadmin nem uma
 * conta desativada); cria as linhas de deal_line_steps em falta (uma por sub-etapa, ciclo '', plan_days da
 * sub-etapa). Não toma lock nem abre transação (ver o cabeçalho). $by: quem fez (o histórico do Kickoff é de quem a
 * chama). Devolve ['created' => [frentes criadas], 'queued' => [frentes que passaram a Por começar]].
 */
function pipe2_kickoff_fronts(PDO $pdo, array $deal, ?int $by = null): array {
    $out = ['created' => [], 'queued' => []];
    if (empty($deal['id']) || !pipe2_deal_is_v2($pdo, $deal)) return $out;
    $dealId = (int)$deal['id'];
    [$mainId, $created] = pipe2_main_line($pdo, $deal);
    if ($created) $out['created'][] = $mainId;

    $queue = $pdo->prepare("UPDATE deal_lines SET status = 'queued', stage_id = NULL, exec_owner_id = ? WHERE id = ? AND status = 'planned'");
    $hasStep = $pdo->prepare("SELECT COUNT(*) FROM deal_line_steps WHERE line_id = ? AND stage_id = ? AND cycle_ym = ''");
    $addStep = $pdo->prepare("INSERT INTO deal_line_steps (deal_id, line_id, stage_id, cycle_ym, plan_days, created_at) VALUES (?, ?, ?, '', ?, ?)");
    $stages = [];
    foreach (pipe2_fronts($pdo, $dealId) as $l) {
        $lid = (int)$l['id'];
        if ($l['status'] === 'planned') {
            $owner = pipe2_user_ok($pdo, $l['exec_owner_id']);
            if ($owner === null && (int)$l['is_main']) $owner = pipe2_user_ok($pdo, $deal['exec_owner_id'] ?? null);
            $owner ??= pipe2_service_head($pdo, (int)$l['funnel_id']);
            $queue->execute([$owner, $lid]);
            if ($queue->rowCount() === 1) $out['queued'][] = $lid;
        }
        $fid = (int)$l['funnel_id'];
        $stages[$fid] ??= pipe2_front_stages($pdo, $fid);
        foreach ($stages[$fid] as $s) {
            $hasStep->execute([$lid, (int)$s['id']]);
            if ((int)$hasStep->fetchColumn() > 0) continue;
            $addStep->execute([$dealId, $lid, (int)$s['id'], $s['plan_days'] === null ? null : (int)$s['plan_days'], date('Y-m-d H:i:s')]);
        }
    }
    $GLOBALS['XSELL_FRESH'] = true;
    return $out;
}

// ── valores e avença (D15) ──────────────────────────────────

function pipe2_line_is_monthly(array $line): bool {
    return ($line['billing'] ?? '') === 'monthly';
}

/** O valor da frente: avença = mensalidade × meses; senão deal_lines.value (null sem valor). */
function pipe2_line_value(array $line): ?float {
    if (pipe2_line_is_monthly($line)) {
        if (($line['monthly_fee'] ?? null) === null || ($line['fee_months'] ?? null) === null) return null;
        return round((float)$line['monthly_fee'] * (int)$line['fee_months'], 2);
    }
    return ($line['value'] ?? null) === null ? null : (float)$line['value'];
}

/** O total do negócio: budget (> 0), senão value_estimate (o «0.00» do budget é verdadeiro em PHP). */
function pipe2_deal_total(array $deal): float {
    return (float)($deal['budget'] ?? 0) > 0 ? (float)$deal['budget'] : (float)($deal['value_estimate'] ?? 0);
}

/** O valor do negócio: só com avenças, a soma delas; com outros serviços (ou sem frentes), o total. */
function pipe2_deal_value(PDO $pdo, array $deal): float {
    $fronts = !empty($deal['id']) ? pipe2_fronts($pdo, (int)$deal['id']) : [];
    if ($fronts && count(array_filter($fronts, 'pipe2_line_is_monthly')) === count($fronts)) {
        return round(array_sum(array_map(fn($l) => (float)pipe2_line_value($l), $fronts)), 2);
    }
    return pipe2_deal_total($deal);
}

/**
 * Grava deal_lines.value das frentes vivas de um negócio v2 (X3): uma frente → o valor do negócio; 2+ → a divisão do
 * total pelas linhas do orçamento (xsell_values), e as avenças com mensalidade × meses. Num v1 não faz nada.
 */
function pipe2_store_values(PDO $pdo, array $deal): void {
    if (empty($deal['id'])) return;
    $deal = deal_get($pdo, (int)$deal['id']) ?? $deal;
    if (!pipe2_deal_is_v2($pdo, $deal)) return;
    $fronts = pipe2_fronts($pdo, (int)$deal['id']);
    if (!$fronts) return;
    $up = $pdo->prepare('UPDATE deal_lines SET value = ? WHERE id = ?');
    if (count($fronts) === 1) {
        $up->execute([pipe2_deal_value($pdo, $deal), (int)$fronts[0]['id']]);
        return;
    }
    require_once __DIR__ . '/xsell.php';
    $split = xsell_values($pdo, ['budget' => pipe2_deal_total($deal)] + $deal);
    foreach ($fronts as $l) {
        $v = pipe2_line_is_monthly($l) ? pipe2_line_value($l) : ($split[(int)$l['funnel_id']] ?? null);
        $up->execute([$v, (int)$l['id']]);
    }
}

/** O período da avença: do 1.º dia do mês de $start ao último dia do mês início + fee_months − 1. */
function pipe2_billing_period(array $line, string $start): array {
    $ym = substr($start, 0, 7);
    $months = max(1, (int)($line['fee_months'] ?? 1));
    $first = new DateTimeImmutable($ym . '-01');
    return ['start' => $first->format('Y-m-d'), 'end' => $first->modify('+' . ($months - 1) . ' months')->format('Y-m-t')];
}

/** A avença terminou: cancelada, ou $today depois de billing_end. */
function pipe2_billing_ended(array $line, string $today): bool {
    if (($line['cancelled_at'] ?? null) !== null && $line['cancelled_at'] !== '') return true;
    $end = (string)($line['billing_end'] ?? '');
    return $end !== '' && substr($today, 0, 10) > substr($end, 0, 10);
}

// ── requisitos (M4) ─────────────────────────────────────────

/**
 * O requisito do orçamento num negócio v2 com 2+ serviços vivos: cada linha da versão que conta (a aceite, senão a
 * última; a opção que conta) tem o serviço de uma das frentes. Noutros negócios, nenhum. Substitui o xsell_requirements.
 */
function pipe2_requirements(PDO $pdo, array $deal): array {
    if (empty($deal['id']) || !pipe2_deal_is_v2($pdo, $deal)) return [];
    $fronts = pipe2_fronts($pdo, (int)$deal['id']);
    if (count($fronts) < 2) return [];
    $funnels = array_map(fn($l) => (int)$l['funnel_id'], $fronts);
    $missing = 0;
    require_once __DIR__ . '/budget.php';
    $sheet = budget_sheet_get($pdo, (int)$deal['id']);
    $v = $sheet ? budget_current_version($pdo, $sheet) : null;
    if ($v) {
        foreach (budget_lines($pdo, (int)$v['id']) as $bl) {
            if (!in_array((int)($bl['funnel_id'] ?? 0), $funnels, true)) $missing++;
        }
    }
    return [['label' => 'Orçamento: cada linha com o serviço', 'ok' => $missing === 0,
             'hint' => $missing === 0 ? '' : 'Falta o serviço em ' . $missing . ' linha(s) do orçamento.']];
}

// ── etapas em uso (D21) ─────────────────────────────────────

/** Porque não se apaga a etapa: frentes (deal_lines.stage_id) ou tarefas (tasks.stage_id, qualquer estado); '' = pode. */
function pipe2_stage_delete_block(PDO $pdo, int $stageId): string {
    foreach ([['deal_lines', 'stage_id'], ['tasks', 'stage_id']] as [$table, $col]) {
        if (!db_has_column($pdo, $table, $col)) continue;
        $st = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $col = ?");
        $st->execute([$stageId]);
        if ((int)$st->fetchColumn() > 0) return PIPE2_STAGE_IN_USE;
    }
    return '';
}
