<?php
/**
 * topper. admin — Pipeline, funis e automações (§19)
 *
 *  · gates: para sair de uma etapa (para a frente) os requisitos têm de estar cumpridos. São
 *    verificados aqui, no servidor, em cada movimento (deal_move). Os requisitos vêm do papel
 *    da etapa (X-Ray validado, proposta enviada, resposta do cliente validada) e das tarefas
 *    obrigatórias criadas pelas automações. Só admins forçam a passagem, com motivo (histórico).
 *  · automações: ao entrar numa etapa (ou numa data, todos os meses, ao validar a aceitação ou
 *    um pagamento) criam-se as tarefas das regras da etapa. Uma regra nunca cria duas vezes a
 *    mesma tarefa no mesmo negócio; mudar uma regra não mexe nas tarefas já criadas.
 *  · responsáveis: comercial (deals.owner_id, fica até ao fim) e execução (exec_owner_id).
 *    O superadmin nunca fica com tarefas (§18.2): sem responsável válido vão para «Por atribuir».
 *  · X-Ray (campos comuns + perguntas do funil), proposta com Opção A/B e resposta do cliente
 *    numa página pública segura (proposta.php), faturas e pagamentos submetidos (§19.9: a
 *    plataforma não emite faturas legais) e rondas de revisão contadas por etapa.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/tasks.php';

const FLOW_TRIGGERS = [
    'enter'    => 'entra na etapa',
    'date'     => 'chega a uma data do negócio',
    'monthly'  => 'todos os meses (avença)',
    'accepted' => 'a aceitação do cliente é validada',
    'paid'     => 'um pagamento é validado',
];
const FLOW_ASSIGN = [
    'commercial' => 'responsável comercial',
    'execution'  => 'responsável de execução',
    'both'       => 'comercial, com a execução a participar',
    'dept'       => 'chefe do departamento',
    'user'       => 'pessoa concreta',
    'none'       => 'por atribuir',
];
const FLOW_DUE_MODES = [
    'entry'    => 'desde o gatilho',
    'delivery' => 'da data de entrega',
    'shoot'    => 'da data de rodagem',
    'validity' => 'da validade da proposta',
    'none'     => 'sem prazo',
];
const FLOW_DOC_KINDS = [
    ''        => 'Nenhum',
    'invoice' => 'Fatura: só fica feita com o documento submetido',
    'payment' => 'Pagamento: pede a data de receção',
    'xray'    => 'X-Ray: fica feita ao validar o X-Ray',
    'noreply' => 'Sem resposta: sugere «Perdido» com «Sem resposta»',
];
const FLOW_REPEAT = ['' => 'não se repete', 'daily' => 'repete todos os dias', 'weekly' => 'repete todas as semanas', 'monthly' => 'repete todos os meses'];

/** X-Ray (§19.2): os campos comuns a todos os funis. */
const XRAY_COMMON = [
    'decisor'      => ['Decisor e aprovadores', 'text', true],
    'objetivo'     => ['Objetivo / problema', 'textarea', true],
    'entregaveis'  => ['Entregáveis e quantidades', 'textarea', true],
    'formatos'     => ['Formatos e canais', 'text', false],
    'referencias'  => ['Referências', 'textarea', false],
    'prazo'        => ['Prazo e datas fixas', 'text', true],
    'orcamento'    => ['Faixa de orçamento', 'select', true],
    'ativos'       => ['Ativos disponíveis', 'text', false],
    'origem'       => ['Como nos encontrou', 'text', false],
    'concorrencia' => ['Outros fornecedores em concurso', 'text', false],
    'uso'          => ['Uso, território e duração', 'text', false],
];
const XRAY_BUDGET_RANGES = ['Até 2 500 €', '2 500 – 5 000 €', '5 000 – 10 000 €', '10 000 – 20 000 €', '20 000 – 40 000 €', '40 000 – 80 000 €', 'Mais de 80 000 €', 'Ainda não sabe'];
/** Pontuação de qualificação: 1 a 3 cada. */
const XRAY_SCORES = ['s_budget' => 'Orçamento', 's_authority' => 'Autoridade', 's_need' => 'Necessidade', 's_timing' => 'Prazo'];
const XRAY_FINAL = ['pedido' => ['Pedido do cliente', 'O âmbito tal como foi pedido.'],
                    'valor'  => ['Proposta de valor', 'Melhorias e upsell: cutdowns e formatos sociais, reels da mesma rodagem, consultoria, ronda de revisão extra, licença de assets, uso alargado, urgência…']];

/** O motivo de um bloqueio: a lista do que falta, para mostrar e (admins) forçar. */
class GateBlocked extends RuntimeException {
    public array $missing;
    public function __construct(array $missing, string $stageName) {
        $this->missing = $missing;
        parent::__construct('Para sair de «' . $stageName . '» falta: ' . implode(' · ', $missing) . '.');
    }
}

// ── utilitários ─────────────────────────────────────────────

/** Soma (ou tira) dias úteis, sem sábados nem domingos. */
function flow_add_workdays(string $from, int $n): string {
    $d = new DateTimeImmutable($from);
    $step = $n < 0 ? '-1 day' : '+1 day';
    for ($left = abs($n); $left > 0;) {
        $d = $d->modify($step);
        if ((int)$d->format('N') < 6) $left--;
    }
    return $d->format('Y-m-d');
}

/** O nome do cliente: o contacto ligado, a empresa, a pessoa, ou o nome do negócio. */
function flow_client_name(PDO $pdo, array $deal): string {
    if (!empty($deal['contact_id'])) {
        $n = $pdo->query('SELECT name FROM contacts WHERE id = ' . (int)$deal['contact_id'])->fetchColumn();
        if ($n) return (string)$n;
    }
    foreach (['company', 'person', 'name'] as $k) if (trim((string)($deal[$k] ?? '')) !== '') return trim((string)$deal[$k]);
    return '';
}

/** {cliente} {negócio} {ID} {etapa} num título. */
function flow_title(PDO $pdo, string $title, array $deal, ?array $stage): string {
    $t = strtr($title, [
        '{cliente}' => flow_client_name($pdo, $deal), '{negócio}' => (string)$deal['name'], '{negocio}' => (string)$deal['name'],
        '{ID}' => (string)$deal['code'], '{etapa}' => (string)($stage['name'] ?? ''),
    ]);
    return mb_substr(trim(preg_replace('/\s*·\s*$/u', '', $t)), 0, 200);
}

/** Uma pessoa que pode ficar com tarefas: ativa e não superadmin (§18.2). */
function flow_user_ok(PDO $pdo, $uid): ?int {
    if (!$uid) return null;
    $st = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = ?');
    $st->execute([(int)$uid]);
    $u = $st->fetch();
    return $u && (int)$u['is_active'] === 1 && $u['role'] !== 'superadmin' ? (int)$u['id'] : null;
}

function flow_user_name(PDO $pdo, $uid): string {
    if (!$uid) return '';
    return (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$uid)->fetchColumn();
}

/** As etapas do funil pela ordem certa, indexadas pela posição (0, 1, 2…). */
function flow_order(PDO $pdo, int $funnelId): array {
    static $cache = [];
    return $cache[$funnelId] ??= array_values(funnel_stages($pdo, $funnelId));
}

function flow_index(array $order, int $stageId): ?int {
    foreach ($order as $i => $s) if ((int)$s['id'] === $stageId) return $i;
    return null;
}

/** A etapa do funil com um papel (proposal, feedback) ou de um tipo fixo (xray, kickoff, done). */
function flow_stage_with(PDO $pdo, int $funnelId, string $roleOrKind): ?array {
    foreach (flow_order($pdo, $funnelId) as $s) {
        if ($s['role'] === $roleOrKind || ($s['role'] === '' && $s['kind'] === $roleOrKind)) return $s;
    }
    return null;
}

// ── os responsáveis (§19.6, §19.10) ─────────────────────────

/** [comercial, execução] — ids (ou null). */
function deal_roles(array $deal): array {
    return [$deal['owner_id'] !== null ? (int)$deal['owner_id'] : null,
            ($deal['exec_owner_id'] ?? null) !== null ? (int)$deal['exec_owner_id'] : null];
}

/** Quem fica com a tarefa de uma regra neste negócio (null = Por atribuir). */
function flow_assignee(PDO $pdo, array $rule, array $deal): ?int {
    [$c, $e] = deal_roles($deal);
    switch ($rule['assign_to']) {
        case 'owner': case 'commercial': case 'both': return flow_user_ok($pdo, $c);
        case 'execution': return flow_user_ok($pdo, $e);
        case 'user': return flow_user_ok($pdo, $rule['assign_ref']);
        case 'dept':
            if (!$rule['assign_ref']) return null;
            return flow_user_ok($pdo, $pdo->query('SELECT head_user_id FROM departments WHERE id = ' . (int)$rule['assign_ref'])->fetchColumn());
    }
    return null;
}

// ── regras ──────────────────────────────────────────────────

function flow_rules(PDO $pdo, int $stageId, bool $activeOnly = false): array {
    $st = $pdo->prepare('SELECT * FROM stage_automations WHERE stage_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY position, id');
    $st->execute([$stageId]);
    return $st->fetchAll();
}

function flow_rule_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT a.*, s.funnel_id, s.name AS stage_name, s.kind AS stage_kind, s.role AS stage_role FROM stage_automations a JOIN funnel_stages s ON s.id = a.stage_id WHERE a.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** «+3 dias úteis», «5 dias antes da entrega», «no dia 5», «sem prazo». */
function flow_due_label(array $r): string {
    if ($r['trig'] === 'monthly') return 'no dia ' . max(1, (int)$r['due_days']) . ' de cada mês';
    if ($r['due_mode'] === 'none' || $r['due_days'] === null && $r['due_mode'] === 'entry') return 'sem prazo';
    $n = (int)$r['due_days'];
    $u = $r['due_unit'] === 'wd' ? (abs($n) === 1 ? 'dia útil' : 'dias úteis') : (abs($n) === 1 ? 'dia' : 'dias');
    if ($r['due_mode'] === 'entry') return '+' . $n . ' ' . $u;
    $what = ['delivery' => 'da entrega', 'shoot' => 'da rodagem', 'validity' => 'da validade da proposta'][$r['due_mode']] ?? '';
    if ($n === 0) return 'no dia ' . $what;
    return abs($n) . ' ' . $u . ' ' . ($n < 0 ? 'antes ' : 'depois ') . $what;
}

/** A regra em frase legível (§19.7): «Quando o negócio entra em Kickoff → criar Documento «…» para …». */
function flow_rule_parts(PDO $pdo, array $r, string $stageName): array {
    $when = match ($r['trig']) {
        'enter'    => 'o negócio entra em ' . $stageName,
        'date'     => 'o negócio chega a ' . flow_due_label($r) . ' (em ' . $stageName . ')',
        'monthly'  => 'o negócio está em ' . $stageName . ': ' . flow_due_label($r),
        'accepted' => 'a aceitação é validada',
        'paid'     => 'um pagamento é validado',
        default    => $r['trig'],
    };
    $who = FLOW_ASSIGN[$r['assign_to']] ?? ($r['assign_to'] === 'owner' ? FLOW_ASSIGN['commercial'] : $r['assign_to']);
    if ($r['assign_to'] === 'user' && $r['assign_ref']) $who = 'pessoa: ' . (flow_user_name($pdo, $r['assign_ref']) ?: '—');
    if ($r['assign_to'] === 'dept' && $r['assign_ref']) $who = 'chefe de ' . ((string)$pdo->query('SELECT name FROM departments WHERE id = ' . (int)$r['assign_ref'])->fetchColumn() ?: '—');
    return ['when' => $when, 'kind' => TASK_TYPES[$r['kind']][0] ?? 'Tarefa', 'icon' => TASK_TYPES[$r['kind']][1] ?? 'checklist',
            'title' => $r['title'], 'who' => $who, 'due' => $r['trig'] === 'monthly' ? '' : flow_due_label($r),
            'gate' => (int)$r['is_gate'] === 1, 'repeat' => $r['repeat_rule'] !== '' ? FLOW_REPEAT[$r['repeat_rule']] ?? '' : '',
            'doc' => $r['doc_kind'] !== '' ? strtok(FLOW_DOC_KINDS[$r['doc_kind']] ?? '', ':') : ''];
}

/** A data de referência de um negócio para o prazo («entrega», «rodagem», validade). */
function flow_ref_date(PDO $pdo, array $deal, string $mode): ?string {
    if ($mode === 'delivery') return $deal['delivery_date'] ?? null;
    if ($mode === 'shoot') return $deal['shoot_date'] ?? null;
    if ($mode === 'validity') {
        $st = $pdo->prepare('SELECT v.valid_until FROM budget_versions v JOIN budget_sheets s ON s.id = v.sheet_id WHERE s.deal_id = ? AND v.valid_until IS NOT NULL ORDER BY v.num DESC LIMIT 1');
        $st->execute([(int)$deal['id']]);
        return $st->fetchColumn() ?: null;
    }
    return null;
}

/** O prazo de uma tarefa da regra: [data ou null, nota]. */
function flow_due(PDO $pdo, array $r, array $deal, string $from): array {
    if ($r['trig'] === 'monthly') return [$from, ''];
    if ($r['due_mode'] === 'none' || $r['due_days'] === null) return [null, ''];
    $n = (int)$r['due_days'];
    $base = $r['due_mode'] === 'entry' ? $from : flow_ref_date($pdo, $deal, $r['due_mode']);
    if ($base === null) {
        $what = ['delivery' => 'data de entrega', 'shoot' => 'data de rodagem', 'validity' => 'validade da proposta'][$r['due_mode']] ?? 'data';
        return [null, 'O prazo conta a partir da ' . $what . ' do negócio, que ainda não está definida.'];
    }
    $date = $r['due_unit'] === 'wd' ? flow_add_workdays($base, $n) : (new DateTimeImmutable($base))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    return [$date, ''];
}

// ── criar as tarefas ────────────────────────────────────────

/** Os ids criados no último movimento (para o «Anular» os retirar). */
function flow_created(?array $add = null, bool $reset = false): array {
    static $ids = [];
    if ($reset) $ids = [];
    if ($add) $ids = array_merge($ids, $add);
    return $ids;
}

/**
 * Cria a tarefa de uma regra para um negócio (uma vez por regra e negócio; nas mensais,
 * uma vez por mês). Devolve o id ou null (já existia).
 */
function flow_task_from_rule(PDO $pdo, array $r, array $deal, ?array $stage, string $from, ?int $by = null): ?int {
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/leads.php';
    // Nas mensais, uma por mês (o prazo é o dia do mês em que foi criada).
    $dup = 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?' . ($r['trig'] === 'monthly' ? ' AND due_date BETWEEN ? AND ?' : '');
    $st = $pdo->prepare($dup);
    $st->execute(array_merge([(int)$deal['id'], (int)$r['id']], $r['trig'] === 'monthly' ? [substr($from, 0, 7) . '-01', substr($from, 0, 7) . '-31'] : []));
    if ((int)$st->fetchColumn() > 0) return null;

    $uid = flow_assignee($pdo, $r, $deal);
    $member = $uid ? member_for_user($pdo, $uid) : null;
    [$due, $note] = flow_due($pdo, $r, $deal, $from);
    [, $e] = deal_roles($deal);
    $parts = $r['assign_to'] === 'both' && ($e = flow_user_ok($pdo, $e)) && $e !== $uid ? (string)$e : '';
    $desc = trim(trim((string)$r['description']) . ($note !== '' ? "\n\n" . $note : '')
               . "\n\nCriada pela automação «" . flow_rule_parts($pdo, $r, (string)($stage['name'] ?? ''))['when'] . '».'
               . ((int)$r['is_gate'] === 1 ? ' Obrigatória: tem de estar feita para o negócio sair desta etapa.' : ''));
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO tasks (title, description, member_id, job_name, deal_id, due_date, kind, repeat_rule, priority, status, position,
                                      created_at, updated_at, created_by, checklist, participants, auto_rule_id, stage_id, is_gate, doc_kind)
                   VALUES (?,?,?,\'\',?,?,?,?,?,\'todo\',?,?,?,?,?,?,?,?,?,?)')
        ->execute([flow_title($pdo, (string)$r['title'], $deal, $stage), $desc, $member, (int)$deal['id'], $due, $r['kind'] ?: 'task', $r['repeat_rule'],
                   (int)$r['is_gate'] === 1 ? 'alta' : 'media', task_next_position($pdo, $member), $now, $now, $by,
                   $r['checklist'] ? task_checklist_parse((string)$r['checklist']) : null, $parts, (int)$r['id'],
                   $stage ? (int)$stage['id'] : null, (int)$r['is_gate'], (string)$r['doc_kind']]);
    $id = (int)$pdo->lastInsertId();
    flow_created([$id]);
    return $id;
}

/** O negócio entrou numa etapa: regista a hora e corre as regras «entra na etapa». Devolve quantas tarefas criou. */
function flow_on_enter(PDO $pdo, int $dealId, int $stageId): int {
    $pdo->prepare('UPDATE deals SET stage_entered_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $dealId]);
    $deal = deal_get($pdo, $dealId);
    $stage = stage_get($pdo, $stageId);
    if (!$deal || !$stage) return 0;
    $n = 0;
    foreach (flow_rules($pdo, $stageId, true) as $r) {
        if ($r['trig'] !== 'enter') continue;
        if (flow_task_from_rule($pdo, $r, $deal, $stage, today_lisbon(), current_user()['id'] ?? null)) $n++;
    }
    if ($n) deal_log($pdo, $dealId, 'auto', ['note' => $n === 1 ? '1 tarefa automática criada (' . $stage['name'] . ')' : $n . ' tarefas automáticas criadas (' . $stage['name'] . ')']);
    // Depois da entrega o cliente entra nas Relações com o comercial como dono (§19.10).
    if ($stage['kind'] === 'done') flow_relation_after_delivery($pdo, $deal);
    return $n;
}

/** Os gatilhos de evento (§19.7): «accepted» ao validar a aceitação, «paid» ao validar um pagamento. */
function flow_on_event(PDO $pdo, int $dealId, string $event): int {
    $deal = deal_get($pdo, $dealId);
    if (!$deal || !$deal['funnel_id']) return 0;
    $n = 0;
    foreach (flow_order($pdo, (int)$deal['funnel_id']) as $s) {
        foreach (flow_rules($pdo, (int)$s['id'], true) as $r) {
            if ($r['trig'] !== $event) continue;
            if (flow_task_from_rule($pdo, $r, $deal, $s, today_lisbon(), current_user()['id'] ?? null)) $n++;
        }
    }
    if ($n) deal_log($pdo, $dealId, 'auto', ['note' => $n . ' tarefa' . ($n === 1 ? '' : 's') . ' automática' . ($n === 1 ? '' : 's') . ' (' . FLOW_TRIGGERS[$event] . ')']);
    return $n;
}

/**
 * O relógio diário (cron): regras «todos os meses» (no dia escolhido, para os negócios nessa
 * etapa) e «chega a uma data» (no dia da data ± N, para os negócios nessa etapa). Devolve quantas criou.
 */
function flow_tick(PDO $pdo, ?string $today = null): int {
    $today ??= today_lisbon();
    $day = (int)substr($today, 8, 2);
    $last = (int)(new DateTimeImmutable($today))->format('t');
    $n = 0;
    $rules = $pdo->query("SELECT a.*, s.funnel_id FROM stage_automations a JOIN funnel_stages s ON s.id = a.stage_id
                           WHERE a.is_active = 1 AND a.trig IN ('monthly','date')")->fetchAll();
    foreach ($rules as $r) {
        $st = $pdo->prepare("SELECT * FROM deals WHERE stage_id = ? AND phase IN ('pipeline','production','done')");
        $st->execute([(int)$r['stage_id']]);
        $stage = stage_get($pdo, (int)$r['stage_id']);
        foreach ($st->fetchAll() as $deal) {
            if ($r['trig'] === 'monthly') {
                if ($day !== min($last, max(1, (int)$r['due_days']))) continue;
            } else {
                [$due] = flow_due($pdo, $r, $deal, $today);
                if ($due === null || $due > $today) continue;
            }
            if (flow_task_from_rule($pdo, $r, $deal, $stage, $today)) {
                $n++;
                deal_log($pdo, (int)$deal['id'], 'auto', ['note' => 'Tarefa automática: ' . flow_title($pdo, (string)$r['title'], $deal, $stage), 'user_id' => null]);
            }
        }
    }
    return $n;
}

/** As datas do negócio mudaram: as tarefas automáticas abertas que contam a partir delas acompanham. */
function flow_dates_changed(PDO $pdo, int $dealId): int {
    $deal = deal_get($pdo, $dealId);
    $st = $pdo->prepare("SELECT t.id, a.* FROM tasks t JOIN stage_automations a ON a.id = t.auto_rule_id
                          WHERE t.deal_id = ? AND t.status <> 'done' AND a.due_mode IN ('delivery','shoot','validity')");
    $st->execute([$dealId]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        [$due] = flow_due($pdo, $row, $deal, today_lisbon());
        $pdo->prepare('UPDATE tasks SET due_date = ? WHERE id = ?')->execute([$due, (int)$row['id']]);
        $n++;
    }
    return $n;
}

/** §19.10: depois da entrega, o cliente passa a ser uma relação acompanhada pelo comercial. */
function flow_relation_after_delivery(PDO $pdo, array $deal): void {
    if (empty($deal['contact_id']) || !db_has_table($pdo, 'relations')) return;
    require_once __DIR__ . '/contacts.php';
    if (relation_get($pdo, (int)$deal['contact_id'])) return;
    $owner = flow_user_ok($pdo, $deal['owner_id']);
    relation_set($pdo, (int)$deal['contact_id'], ['formality' => 'cordial', 'potential' => 3, 'business' => 'medio',
                 'level' => relation_level('cordial', 3, 'medio'), 'owner_id' => $owner, 'cadence_days' => null], current_user()['id'] ?? null);
    deal_log($pdo, (int)$deal['id'], 'auto', ['note' => 'Cliente passou para as Relações' . ($owner ? ' (dono: ' . flow_user_name($pdo, $owner) . ')' : '')]);
}

// ── gates (§19.1, §19.5) ────────────────────────────────────

/**
 * Os requisitos para sair de uma etapa: [['label', 'ok', 'hint', 'task' => id|null], …].
 * Os da própria etapa (pelo papel) e as tarefas obrigatórias criadas nela.
 */
function deal_gate(PDO $pdo, array $deal, array $stage): array {
    $items = [];
    if ((int)($stage['gate_on'] ?? 1) === 1) {
        if ($stage['kind'] === 'xray') {
            $x = xray_data($deal);
            $miss = xray_missing($pdo, $deal, $x);
            $items[] = ['label' => 'Formulário X-Ray preenchido', 'ok' => !$miss, 'hint' => $miss ? 'Falta: ' . implode(', ', array_slice($miss, 0, 4)) . (count($miss) > 4 ? '…' : '') : ''];
            $items[] = ['label' => 'X-Ray validado pelo responsável', 'ok' => !empty($deal['xray_validated_at']), 'hint' => ''];
        } elseif ($stage['role'] === 'proposal') {
            $p = proposal_latest($pdo, (int)$deal['id']);
            foreach (proposal_requirements($pdo, $deal) as $req) $items[] = $req;
            $items[] = ['label' => 'Proposta enviada ao cliente', 'ok' => $p && $p['status'] !== 'void', 'hint' => 'Enviar move o negócio para «Aguardar feedback».'];
        } elseif ($stage['role'] === 'feedback') {
            $p = proposal_latest($pdo, (int)$deal['id']);
            $ok = $p && $p['status'] === 'validated' && $p['decision'] === 'accept';
            $hint = !$p ? 'Ainda não há proposta enviada.' : match ($p['status']) {
                'sent'      => 'A aguardar a resposta do cliente.',
                'answered'  => 'Resposta do cliente: ' . proposal_answer_label($p) . ' — falta validar.',
                'contact'   => 'Em contacto com o cliente sobre a resposta.',
                'validated' => $p['decision'] === 'refuse' ? 'O cliente recusou.' : '',
                default     => '',
            };
            $items[] = ['label' => 'Resposta do cliente validada internamente (aceite)', 'ok' => $ok, 'hint' => $hint];
        }
    }
    $st = $pdo->prepare('SELECT id, title, status FROM tasks WHERE deal_id = ? AND stage_id = ? AND is_gate = 1 ORDER BY id');
    $st->execute([(int)$deal['id'], (int)$stage['id']]);
    foreach ($st->fetchAll() as $t) $items[] = ['label' => $t['title'], 'ok' => $t['status'] === 'done', 'hint' => '', 'task' => (int)$t['id']];
    return $items;
}

/** O que falta (só os rótulos). */
function deal_gate_missing(PDO $pdo, array $deal, array $stage): array {
    return array_values(array_map(fn($i) => $i['label'], array_filter(deal_gate($pdo, $deal, $stage), fn($i) => !$i['ok'])));
}

/**
 * Pode o negócio passar da etapa atual para $to? Para trás passa sempre; para a frente, uma
 * etapa de cada vez e com os requisitos cumpridos. Com $force (motivo), só admins passam por
 * cima — e fica no histórico. Devolve a nota a registar (forçada) ou ''.
 */
function flow_gate_check(PDO $pdo, array $deal, array $to, string $force = ''): string {
    $from = $deal['stage_id'] ? stage_get($pdo, (int)$deal['stage_id']) : null;
    if (!$from || (int)$from['funnel_id'] !== (int)$to['funnel_id']) return '';
    $order = flow_order($pdo, (int)$deal['funnel_id']);
    $i = flow_index($order, (int)$from['id']);
    $j = flow_index($order, (int)$to['id']);
    if ($i === null || $j === null || $j <= $i) return '';
    $missing = deal_gate_missing($pdo, $deal, $from);
    if ($j > $i + 1) $missing[] = 'avançar uma etapa de cada vez (salta ' . implode(', ', array_map(fn($s) => '«' . $s['name'] . '»', array_slice($order, $i + 1, $j - $i - 1))) . ')';
    if (!$missing) return '';
    $force = trim($force);
    if ($force === '') throw new GateBlocked($missing, (string)$from['name']);
    if (!is_admin()) throw new RuntimeException('Só os admins podem forçar a passagem.');
    return mb_substr('Passagem forçada (' . $from['name'] . ' → ' . $to['name'] . '): ' . $force . ' · faltava: ' . implode(' · ', $missing), 0, 255);
}

// ── X-Ray (§19.2) ───────────────────────────────────────────

function xray_data(array $deal): array {
    $x = json_decode((string)($deal['xray'] ?? ''), true);
    return is_array($x) ? $x : [];
}

/** As perguntas do funil (uma por linha em Definições). */
function xray_questions(PDO $pdo, ?int $funnelId): array {
    if (!$funnelId) return [];
    $raw = (string)$pdo->query('SELECT xray_fields FROM funnels WHERE id = ' . (int)$funnelId)->fetchColumn();
    return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw))));
}

/** O que falta para o X-Ray contar como preenchido (os obrigatórios). */
function xray_missing(PDO $pdo, array $deal, ?array $x = null): array {
    $x ??= xray_data($deal);
    $miss = [];
    foreach (XRAY_COMMON as $k => [$label, , $req]) if ($req && trim((string)($x[$k] ?? '')) === '') $miss[] = mb_strtolower($label);
    foreach (XRAY_SCORES as $k => $label) if (!in_array((int)($x[$k] ?? 0), [1, 2, 3], true)) $miss[] = 'pontuação: ' . mb_strtolower($label);
    if (trim((string)($x['pedido'] ?? '')) === '') $miss[] = 'pedido do cliente';
    return $miss;
}

/** A pontuação de qualificação (4 a 12) ou null. */
function xray_score(array $x): ?int {
    $s = 0;
    foreach (array_keys(XRAY_SCORES) as $k) { $v = (int)($x[$k] ?? 0); if ($v < 1 || $v > 3) return null; $s += $v; }
    return $s;
}

/** Grava o X-Ray. Mudar um X-Ray validado tira a validação (fica no histórico). Devolve a mensagem. */
function xray_save(PDO $pdo, array $deal, array $in): string {
    $x = [];
    foreach (XRAY_COMMON as $k => [, $type]) {
        $v = trim((string)($in[$k] ?? ''));
        if ($type === 'select' && $v !== '' && !in_array($v, XRAY_BUDGET_RANGES, true)) $v = '';
        $x[$k] = mb_substr($v, 0, $type === 'textarea' ? 4000 : 500);
    }
    foreach (array_keys(XRAY_SCORES) as $k) { $v = (int)($in[$k] ?? 0); $x[$k] = in_array($v, [1, 2, 3], true) ? $v : 0; }
    $q = [];
    foreach (xray_questions($pdo, (int)$deal['funnel_id']) as $i => $label) {
        $q[$label] = mb_substr(trim((string)($in['q'][$i] ?? '')), 0, 2000);
    }
    $x['funil'] = $q;
    foreach (array_keys(XRAY_FINAL) as $k) $x[$k] = mb_substr(trim((string)($in[$k] ?? '')), 0, 6000);
    $old = xray_data($deal);
    if ($old == $x) return 'Sem alterações.';
    $unvalidate = !empty($deal['xray_validated_at']);
    $pdo->prepare('UPDATE deals SET xray = ?' . ($unvalidate ? ', xray_validated_at = NULL, xray_validated_by = NULL' : '') . ', last_touch = ? WHERE id = ?')
        ->execute([json_encode($x, JSON_UNESCAPED_UNICODE), today_lisbon(), $deal['id']]);
    deal_log($pdo, (int)$deal['id'], 'xray', ['note' => 'X-Ray atualizado' . ($unvalidate ? ' (a validação saiu: valide outra vez)' : '')]);
    return 'X-Ray guardado.' . ($unvalidate ? ' Como mudou, precisa de ser validado outra vez.' : '');
}

/** Validar o X-Ray: só preenchido; marca feitas as tarefas «X-Ray» do negócio. */
function xray_validate(PDO $pdo, array $deal, int $by): string {
    $miss = xray_missing($pdo, $deal);
    if ($miss) throw new RuntimeException('Antes de validar, preencha: ' . implode(', ', $miss) . '.');
    $pdo->prepare('UPDATE deals SET xray_validated_at = ?, xray_validated_by = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $by, $deal['id']]);
    $done = flow_complete_doc_tasks($pdo, (int)$deal['id'], 'xray');
    deal_log($pdo, (int)$deal['id'], 'xray', ['note' => 'X-Ray validado' . ($done ? ' · tarefa concluída' : '')]);
    return 'X-Ray validado.';
}

/** Conclui as tarefas abertas de um negócio que esperam por um documento/evento. Devolve quantas. */
function flow_complete_doc_tasks(PDO $pdo, int $dealId, string $docKind, ?int $taskId = null): int {
    require_once __DIR__ . '/tasks.php';
    $st = $pdo->prepare("SELECT id, title FROM tasks WHERE deal_id = ? AND doc_kind = ? AND status <> 'done'" . ($taskId ? ' AND id = ' . (int)$taskId : ''));
    $st->execute([$dealId, $docKind]);
    $n = 0;
    foreach ($st->fetchAll() as $t) {
        $GLOBALS['FLOW_DOC_OK'] = true;   // a guarda de tasks_api/calendar deixa passar
        task_done($pdo, (int)$t['id']);
        unset($GLOBALS['FLOW_DOC_OK']);
        task_log($pdo, (int)$t['id'], 'done', 'Concluída pelo documento submetido');
        $n++;
    }
    return $n;
}

/**
 * Uma tarefa que só se conclui com um documento (fatura, pagamento, X-Ray)? Devolve a explicação
 * para quem tenta marcá-la como feita à mão, ou ''.
 */
function flow_task_needs_doc(array $t): string {
    if (!empty($GLOBALS['FLOW_DOC_OK'])) return '';
    return match ((string)($t['doc_kind'] ?? '')) {
        'invoice' => 'Esta tarefa fica feita quando o documento da fatura (PDF, número, data e valores) é submetido no negócio.',
        'payment' => 'Esta tarefa fica feita ao validar o pagamento no negócio (com a data de receção).',
        'xray'    => 'Esta tarefa fica feita ao validar o X-Ray do negócio.',
        default   => '',
    };
}

// ── proposta e resposta do cliente (§19.3, §19.4) ───────────

/** Condições de pagamento por defeito (Definições), pelo valor e pelo funil. */
function flow_payment_default(PDO $pdo, float $price, ?int $funnelId): string {
    $kind = $funnelId ? (string)$pdo->query('SELECT xray_kind FROM funnels WHERE id = ' . (int)$funnelId)->fetchColumn() : '';
    if ($kind === 'buddy') return (string)setting($pdo, 'pay_terms_buddy', '');
    return $price > (float)setting($pdo, 'pay_terms_threshold', 10000) ? (string)setting($pdo, 'pay_terms_large', '') : (string)setting($pdo, 'pay_terms_default', '');
}

/** A última proposta enviada de um negócio (a que conta), ou null. */
function proposal_latest(PDO $pdo, int $dealId): ?array {
    if (!db_has_table($pdo, 'proposals')) return null;
    $st = $pdo->prepare("SELECT * FROM proposals WHERE deal_id = ? AND status <> 'void' ORDER BY id DESC LIMIT 1");
    $st->execute([$dealId]);
    return $st->fetch() ?: null;
}

/** «aceitou a Opção B», «recusou». */
function proposal_answer_label(array $p): string {
    if ($p['decision'] === 'accept') return 'aceitou ' . ((int)$p['has_b'] ? 'a Opção ' . $p['choice'] : 'a proposta');
    if ($p['decision'] === 'refuse') return 'recusou' . (trim((string)$p['reason']) !== '' ? ' («' . mb_substr(trim((string)$p['reason']), 0, 80) . '»)' : '');
    return 'sem resposta';
}

/** A versão de orçamento a enviar: a última da folha. [folha, versão] ou [null, null]. */
function proposal_version(PDO $pdo, array $deal): array {
    require_once __DIR__ . '/budget.php';
    $sheet = budget_sheet_get($pdo, (int)$deal['id']);
    if (!$sheet) return [null, null];
    $vs = budget_versions($pdo, (int)$sheet['id']);
    return [$sheet, $vs ? end($vs) : null];
}

/** Os requisitos da etapa Proposta antes de enviar (§19.1). */
function proposal_requirements(PDO $pdo, array $deal): array {
    [$sheet, $v] = proposal_version($pdo, $deal);
    $hasA = $v && budget_lines($pdo, (int)$v['id'], 'A');
    $f = fn(string $k) => $v && trim((string)$v[$k]) !== '';
    $pdf = false;
    if ($sheet && $v) {
        foreach (budget_history($pdo, (int)$sheet['id']) as $h) if ($h['event'] === 'b_pdf' && str_starts_with((string)$h['note'], 'v' . (int)$v['num'] . ' ')) $pdf = true;
    }
    $validOk = $v && $v['valid_until'] && $v['valid_until'] >= today_lisbon();
    return [
        ['label' => 'Opção A («Pedido») com linhas na folha de orçamento', 'ok' => (bool)$hasA, 'hint' => ''],
        ['label' => 'Prazo, condições de pagamento, revisões incluídas e direitos de uso', 'ok' => $f('term_text') && $f('payment_terms') && $f('revisions_text') && $f('rights_text'), 'hint' => 'Na folha de orçamento, em «Proposta».'],
        ['label' => 'Validade da proposta', 'ok' => (bool)$validOk, 'hint' => $v && $v['valid_until'] && !$validOk ? 'A validade já passou.' : ''],
        ['label' => 'PDF da proposta gerado (v' . ($v ? (int)$v['num'] : 1) . ')', 'ok' => $pdf, 'hint' => ''],
    ];
}

/** O que o cliente vê (sem custos, margens nem fornecedores): para o hash e para a página. */
function proposal_public_content(PDO $pdo, array $deal, array $v): array {
    require_once __DIR__ . '/budget.php';
    $rate = vat_rate($pdo);
    $b = (float)$v['price_b'] > 0 && budget_lines($pdo, (int)$v['id'], 'B');
    $opt = fn(string $o, float $price, string $scope) => ['key' => $o, 'label' => $o === 'A' ? 'Opção A · Pedido' : 'Opção B · Proposta de valor',
        'scope' => $scope, 'price' => round($price, 2), 'vat' => round($price * $rate / 100, 2), 'total' => round($price * (1 + $rate / 100), 2)];
    $opts = [$opt('A', (float)$v['price'], trim((string)$v['description']))];
    if ($b) $opts[] = $opt('B', (float)$v['price_b'], trim((string)$v['scope_b']));
    return [
        'code' => (string)$deal['code'], 'name' => (string)$deal['name'], 'version' => (int)$v['num'], 'vat_rate' => $rate,
        'options' => $opts, 'term' => (string)$v['term_text'], 'payment' => (string)$v['payment_terms'],
        'revisions' => (string)$v['revisions_text'], 'rights' => (string)$v['rights_text'], 'conditions' => trim((string)$v['conditions']),
        'valid_until' => (string)$v['valid_until'],
    ];
}

function proposal_hash(array $content): string {
    return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE));
}

function proposal_url(string $token): string {
    require_once __DIR__ . '/team.php';
    return team_app_url() . '/admin/proposta.php?t=' . $token;
}

/**
 * O email da proposta, por partes: o mesmo texto no envio e na pré-visualização (§19.4).
 * A mensagem opcional de quem envia entra a seguir à introdução.
 */
function proposal_email(PDO $pdo, array $deal, array $v, array $content, int $by, string $note = ''): array {
    $client = flow_client_name($pdo, $deal);
    $who = flow_user_name($pdo, $deal['owner_id'] ?: $by);
    return [
        'subject' => 'Proposta ' . $deal['code'] . ' · ' . $deal['name'],
        'hello'   => 'Olá' . ($client !== '' ? ' ' . $client : '') . ',',
        'intro'   => 'Segue a nossa proposta para «' . $deal['name'] . '»' . (count($content['options']) > 1 ? ', com duas opções' : '') . '.',
        'note'    => trim(mb_substr(str_replace("\r", '', $note), 0, 2000)),
        'valid'   => 'O link é válido até ' . date('d/m/Y', strtotime((string)$v['valid_until'])) . '. Abrir a página não altera nada: a resposta só conta quando a confirmar.',
        'sign'    => ($who !== '' ? $who . "\n" : '') . 'topper.',
    ];
}

/**
 * Envia a proposta: um link com token (só o hash fica na base), válido até à validade da
 * proposta. O email tem UM botão «Ver e responder à proposta». Move para «Aguardar feedback».
 * Devolve [mensagem, link].
 */
function proposal_send(PDO $pdo, array $deal, string $email, int $by, string $note = ''): array {
    require_once __DIR__ . '/budget.php';
    require_once __DIR__ . '/team.php';
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Indique o email do cliente.');
    $missing = array_values(array_filter(proposal_requirements($pdo, $deal), fn($r) => !$r['ok']));
    if ($missing) throw new RuntimeException('Antes de enviar: ' . implode(' · ', array_map(fn($r) => mb_strtolower($r['label']), $missing)) . '.');
    [$sheet, $v] = proposal_version($pdo, $deal);
    $old = proposal_latest($pdo, (int)$deal['id']);
    if ($old && $old['status'] === 'validated') throw new RuntimeException('A resposta do cliente já foi validada. Para mudar, crie uma nova versão do orçamento e envie de novo.');
    budget_freeze($pdo, $v);
    $v = budget_version_get($pdo, (int)$v['id']);
    $content = proposal_public_content($pdo, $deal, $v);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pdo->prepare("UPDATE proposals SET status = 'void' WHERE deal_id = ? AND status IN ('sent','answered','contact')")->execute([$deal['id']]);
    $pdo->prepare('INSERT INTO proposals (deal_id, version_id, token_hash, content_hash, has_b, sent_to, sent_at, sent_by, expires_on, status)
                   VALUES (?,?,?,?,?,?,?,?,?,\'sent\')')
        ->execute([$deal['id'], $v['id'], hash('sha256', $token), proposal_hash($content), count($content['options']) > 1 ? 1 : 0,
                   mb_substr($email, 0, 190), date('Y-m-d H:i:s'), $by, $v['valid_until']]);
    $link = proposal_url($token);
    $mail = proposal_email($pdo, $deal, $v, $content, $by, $note);
    $sent = team_mail($email, $mail['subject'], $mail['hello'] . "\n\n" . $mail['intro'] . ($mail['note'] !== '' ? "\n\n" . $mail['note'] : '')
        . "\n\nVer e responder à proposta:\n" . $link . "\n\n" . $mail['valid'] . "\n\n" . $mail['sign']);
    budget_log($pdo, (int)$sheet['id'], 'b_send', 'v' . $v['num'] . ' enviada a ' . $email, $by);
    deal_log($pdo, (int)$deal['id'], 'proposal', ['note' => 'Proposta v' . $v['num'] . ' enviada a ' . $email . ($sent ? '' : ' (o email não saiu: envie o link)'), 'user_id' => $by]);
    // Enviar move para «Aguardar feedback» (o requisito ficou cumprido).
    $msg = 'Proposta enviada a ' . $email . '.';
    $fb = flow_stage_with($pdo, (int)$deal['funnel_id'], 'feedback');
    $now = deal_get($pdo, (int)$deal['id']);
    $cur = stage_get($pdo, (int)$now['stage_id']);
    if ($fb && $cur && $cur['role'] === 'proposal' && $now['phase'] === 'pipeline') {
        try { deal_move($pdo, $now, (int)$fb['id']); $msg .= ' O negócio passou para «' . $fb['name'] . '».'; }
        catch (GateBlocked $e) { $msg .= ' ' . $e->getMessage(); }
    }
    return [$msg . ($sent ? '' : ' O email não saiu deste servidor: copie o link e envie-o.'), $link];
}

/** A proposta pelo token do link (só o hash está na base). */
function proposal_by_token(PDO $pdo, string $token): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{40,60}$/', $token)) return null;
    $st = $pdo->prepare('SELECT * FROM proposals WHERE token_hash = ?');
    $st->execute([hash('sha256', $token)]);
    return $st->fetch() ?: null;
}

/** O estado do link: ok, expired, void, validated, changed. */
function proposal_state(PDO $pdo, array $p, array $content): string {
    if ($p['status'] === 'void') return 'void';
    if ($p['status'] === 'validated') return 'validated';
    if ($p['expires_on'] < today_lisbon()) return 'expired';
    if (!hash_equals((string)$p['content_hash'], proposal_hash($content))) return 'changed';
    return 'ok';
}

/** Assinatura do formulário público (sem sessão): token do link + hora, com o segredo do servidor. */
function proposal_form_sig(PDO $pdo, string $tokenHash, int $ts): string {
    return hash_hmac('sha256', 'proposal|' . $tokenHash . '|' . $ts, (string)setting($pdo, 'proposal_secret', ''));
}

/**
 * A resposta do cliente (POST): escolha, «Li e aceito», nome escrito. Nada passa sozinho:
 * fica «respondida» até alguém da topper. validar. Pode mudar até lá. Devolve a mensagem.
 */
function proposal_answer(PDO $pdo, array $p, array $content, string $decision, string $choice, string $name, bool $agreed, string $reason, string $ip, string $ua): string {
    require_once __DIR__ . '/team.php';
    if (!in_array($p['status'], ['sent', 'answered', 'contact'], true)) throw new RuntimeException('Esta proposta já não aceita respostas.');
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($decision === 'accept') {
        if (!(int)$p['has_b']) $choice = 'A';
        if (!in_array($choice, ['A', 'B'], true)) throw new RuntimeException('Escolha a opção.');
        if (!$agreed) throw new RuntimeException('Para aceitar, marque «Li e aceito as condições».');
        if (mb_strlen($name) < 3) throw new RuntimeException('Escreva o seu nome para confirmar.');
    } elseif ($decision === 'refuse') {
        $choice = '';
        if (mb_strlen($name) < 3) throw new RuntimeException('Escreva o seu nome para confirmar.');
    } else {
        throw new RuntimeException('Pedido inválido.');
    }
    $pdo->prepare("UPDATE proposals SET status = 'answered', decision = ?, choice = ?, reason = ?, signer_name = ?, signer_ip = ?, signer_ua = ?,
                                        answered_at = ?, answers = answers + 1 WHERE id = ?")
        ->execute([$decision, $choice, mb_substr(trim($reason), 0, 2000), mb_substr($name, 0, 160), mb_substr($ip, 0, 45), mb_substr($ua, 0, 255), date('Y-m-d H:i:s'), $p['id']]);
    $p = $pdo->query('SELECT * FROM proposals WHERE id = ' . (int)$p['id'])->fetch();
    $deal = deal_get($pdo, (int)$p['deal_id']);
    $label = proposal_answer_label($p);
    deal_log($pdo, (int)$deal['id'], 'client', ['note' => 'Resposta do cliente: ' . $label . ' (' . $name . ') — falta validar', 'user_id' => null]);
    // Confirmações: ao cliente e ao responsável comercial.
    $opt = $decision === 'accept' && (int)$p['has_b'] ? ' (Opção ' . $choice . ')' : '';
    team_mail((string)$p['sent_to'], 'Recebemos a sua resposta · ' . $deal['code'],
        'Olá ' . $name . ",\n\nRecebemos a sua resposta à proposta «" . $content['name'] . '» v' . $content['version'] . ': '
        . ($decision === 'accept' ? 'aceite' . $opt : 'recusada') . ".\nA equipa vai confirmar e entrar em contacto. Até lá, pode mudar a resposta na mesma página.\n\ntopper.");
    if ($owner = flow_user_ok($pdo, $deal['owner_id'])) {
        $to = (string)$pdo->query('SELECT email FROM users WHERE id = ' . $owner)->fetchColumn();
        if ($to !== '') team_mail($to, 'Resposta do cliente: ' . $label . ' · ' . $deal['code'],
            'Resposta do cliente a ' . $deal['code'] . ' · ' . $deal['name'] . ': ' . $label . ".\nNome escrito: " . $name
            . "\n\nNada avança sozinho: abra o negócio e carregue em «Validar» ou «Contactar cliente».\n" . team_app_url() . '/admin/pipeline.php?deal=' . (int)$deal['id'] . '#negocio');
    }
    return $decision === 'accept' ? 'Obrigado! Registámos a sua aceitação' . $opt . '. Vai receber uma confirmação por email.' : 'Registámos a sua resposta. Obrigado pelo tempo dedicado à nossa proposta.';
}

/**
 * «Validar» a resposta (§19.4): aceite → versão aceite com a opção escolhida e o negócio passa a
 * Kickoff (gatilhos «ao validar a aceitação»); recusa → Perdido com o motivo. Devolve a mensagem.
 */
function proposal_validate(PDO $pdo, array $p, int $by): string {
    require_once __DIR__ . '/budget.php';
    if ($p['status'] !== 'answered' && !($p['status'] === 'contact' && $p['decision'] !== '')) throw new RuntimeException('Não há resposta do cliente por validar.');
    $deal = deal_get($pdo, (int)$p['deal_id']);
    $pdo->prepare("UPDATE proposals SET status = 'validated', validated_at = ?, validated_by = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $by, $p['id']]);
    if ($p['decision'] === 'refuse') {
        deal_log($pdo, (int)$deal['id'], 'client', ['note' => 'Recusa do cliente validada', 'user_id' => $by]);
        if ($deal['phase'] === 'pipeline') deal_mark_lost($pdo, $deal, 'Recusou a proposta' . (trim((string)$p['reason']) !== '' ? ': ' . trim((string)$p['reason']) : ''));
        return 'Recusa validada: ' . $deal['code'] . ' passou para Perdidos.';
    }
    $v = budget_version_get($pdo, (int)$p['version_id']);
    $sheet = $v ? budget_sheet_by_id($pdo, (int)$v['sheet_id']) : null;
    if ($v && $sheet) {
        $pdo->prepare('UPDATE budget_versions SET accepted_opt = ? WHERE id = ?')->execute([$p['choice'] ?: 'A', $v['id']]);
        budget_accept($pdo, $sheet, budget_version_get($pdo, (int)$v['id']), $by);
    }
    deal_log($pdo, (int)$deal['id'], 'client', ['note' => 'Aceitação validada: ' . proposal_answer_label($p) . ' · ' . $p['signer_name'], 'user_id' => $by]);
    $msg = 'Aceitação validada.';
    $deal = deal_get($pdo, (int)$deal['id']);
    $k = funnel_fixed_stage($pdo, (int)$deal['funnel_id'], 'kickoff');
    if ($k && $deal['phase'] === 'pipeline') {
        try { $msg .= ' ' . deal_move($pdo, $deal, $k); }
        catch (GateBlocked $e) { $msg .= ' ' . $e->getMessage(); }
    }
    flow_on_event($pdo, (int)$deal['id'], 'accepted');
    return $msg;
}

/** «Contactar cliente» (clique por engano, dúvida): cria a chamada e o cliente pode responder de novo. */
function proposal_contact(PDO $pdo, array $p, int $by): string {
    require_once __DIR__ . '/leads.php';
    if ($p['status'] !== 'answered') throw new RuntimeException('Não há resposta do cliente por validar.');
    $deal = deal_get($pdo, (int)$p['deal_id']);
    $pdo->prepare("UPDATE proposals SET status = 'contact', contact_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $p['id']]);
    $who = flow_user_ok($pdo, $deal['owner_id']) ?? flow_user_ok($pdo, $by);
    lead_task_create($pdo, $deal, 'call', 'Contactar ' . (flow_client_name($pdo, $deal) ?: 'o cliente') . ' sobre a resposta à proposta',
                     'Resposta registada: ' . proposal_answer_label($p) . ' (' . $p['signer_name'] . '). Confirmar com o cliente antes de validar. O cliente pode mudar a resposta na mesma página.',
                     today_lisbon(), null, $who, '', $by);
    deal_log($pdo, (int)$deal['id'], 'client', ['note' => 'Contactar cliente sobre a resposta (' . proposal_answer_label($p) . ')', 'user_id' => $by]);
    return 'Tarefa «Contactar cliente» criada. A resposta fica por validar até falarem.';
}

// ── faturação (§19.6 E, §19.9) ──────────────────────────────

const BILL_MAX_BYTES = 10485760;

/** As faturas e pagamentos submetidos de um negócio, do mais recente para o mais antigo. */
function billing_list(PDO $pdo, int $dealId): array {
    if (!db_has_table($pdo, 'deal_billing')) return [];
    $st = $pdo->prepare('SELECT b.*, u.name AS who FROM deal_billing b LEFT JOIN users u ON u.id = b.created_by WHERE b.deal_id = ? ORDER BY b.id DESC');
    $st->execute([$dealId]);
    return $st->fetchAll();
}

/** A pasta local (fora da web) para os documentos quando o Drive não está ligado. */
function billing_local_dir(): string {
    $d = __DIR__ . '/billing';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}

/** Guarda o PDF: no Drive (<ID>_<NOME>/Faturas/) ou, sem Drive, numa pasta privada. Devolve [chave, nome]. */
function billing_store_file(PDO $pdo, array $deal, array $file, string $prefix): array {
    if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Escolha o PDF.');
    }
    if ((int)$file['size'] > BILL_MAX_BYTES) throw new RuntimeException('O PDF tem mais de 10 MB.');
    $bytes = (string)file_get_contents($file['tmp_name']);
    if (strncmp($bytes, '%PDF-', 5) !== 0) throw new RuntimeException('O ficheiro tem de ser um PDF.');
    $name = $prefix . '_' . preg_replace('/[^A-Za-z0-9-]/', '', (string)$deal['code']) . '_' . date('Ymd-His') . '.pdf';
    require_once __DIR__ . '/r2.php';
    if (r2_config()) {
        require_once __DIR__ . '/deal_drive.php';
        $folder = drive_deal_folder($pdo, $deal);
        r2_put($folder . 'Faturas/' . $name, $bytes, 'application/pdf');
        if (function_exists('drive_catalog_invalidate')) drive_catalog_invalidate();
        return ['r2:' . $folder . 'Faturas/' . $name, $name];
    }
    if (file_put_contents(billing_local_dir() . '/' . $name, $bytes) === false) throw new RuntimeException('Não foi possível guardar o PDF. Tente outra vez.');
    return ['local:' . $name, $name];
}

/** «Emitir fatura»: o documento emitido fora da plataforma é submetido aqui e a tarefa fica feita. */
function billing_submit_invoice(PDO $pdo, array $deal, ?array $task, array $in, array $file, int $by): string {
    $number = mb_substr(trim((string)($in['number'] ?? '')), 0, 60);
    $date = (string)($in['doc_date'] ?? '');
    if ($number === '') throw new RuntimeException('Indique o número da fatura.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) throw new RuntimeException('Indique a data da fatura.');
    $net = money_in($in['net'] ?? '');
    $vat = money_in($in['vat'] ?? '');
    $total = money_in($in['total'] ?? '');
    if ($net <= 0) throw new RuntimeException('Indique o valor sem IVA.');
    if ($total <= 0) $total = round($net + $vat, 2);
    if (abs($net + $vat - $total) > 0.05) throw new RuntimeException('Valor s/ IVA + IVA não dá o total. Confirme os valores.');
    [$key, $fname] = billing_store_file($pdo, $deal, $file, 'Fatura-' . preg_replace('/[^A-Za-z0-9-]/', '-', $number));
    $pdo->prepare('INSERT INTO deal_billing (deal_id, task_id, kind, number, doc_date, net, vat, total, file_key, file_name, note, created_by, created_at)
                   VALUES (?,?,\'invoice\',?,?,?,?,?,?,?,\'\',?,?)')
        ->execute([$deal['id'], $task ? (int)$task['id'] : null, $number, $date, $net, $vat, $total, $key, $fname, $by, date('Y-m-d H:i:s')]);
    if ($task) flow_complete_doc_tasks($pdo, (int)$deal['id'], 'invoice', (int)$task['id']);
    deal_log($pdo, (int)$deal['id'], 'billing', ['note' => 'Fatura ' . $number . ' submetida · ' . number_format($total, 2, ',', ' ') . ' € c/ IVA', 'user_id' => $by]);
    return 'Fatura ' . $number . ' submetida' . ($task ? ': a tarefa ficou feita.' : '.');
}

/** «Validar pagamento»: a data de receção (e o comprovativo, opcional); dispara as regras «ao validar um pagamento». */
function billing_validate_payment(PDO $pdo, array $deal, ?array $task, array $in, ?array $file, int $by): string {
    $date = (string)($in['doc_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) throw new RuntimeException('Indique a data em que o pagamento foi recebido.');
    if ($date > today_lisbon()) throw new RuntimeException('A data de receção não pode ser no futuro.');
    $amount = money_in($in['total'] ?? '');
    [$key, $fname] = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? billing_store_file($pdo, $deal, $file, 'Comprovativo') : ['', ''];
    $pdo->prepare('INSERT INTO deal_billing (deal_id, task_id, kind, number, doc_date, net, vat, total, file_key, file_name, note, created_by, created_at)
                   VALUES (?,?,\'payment\',\'\',?,0,0,?,?,?,?,?,?)')
        ->execute([$deal['id'], $task ? (int)$task['id'] : null, $date, $amount, $key, $fname, mb_substr(trim((string)($in['note'] ?? '')), 0, 255), $by, date('Y-m-d H:i:s')]);
    if ($task) flow_complete_doc_tasks($pdo, (int)$deal['id'], 'payment', (int)$task['id']);
    // O pagamento final (pedido em Concluído) marca o negócio como pago.
    $final = $task && $task['stage_id'] && ($s = stage_get($pdo, (int)$task['stage_id'])) && $s['kind'] === 'done';
    if ($final) $pdo->prepare('UPDATE deals SET is_paid = 1 WHERE id = ?')->execute([$deal['id']]);
    deal_log($pdo, (int)$deal['id'], 'billing', ['note' => 'Pagamento recebido a ' . date('d/m/Y', strtotime($date)) . ($amount > 0 ? ' · ' . number_format($amount, 2, ',', ' ') . ' €' : '') . ($final ? ' · negócio pago' : ''), 'user_id' => $by]);
    flow_on_event($pdo, (int)$deal['id'], 'paid');
    return 'Pagamento validado' . ($task ? ': a tarefa ficou feita.' : '.');
}

// ── rondas de revisão (§19.6 D) ─────────────────────────────

function rounds_count(PDO $pdo, int $dealId, int $stageId): int {
    if (!db_has_table($pdo, 'deal_rounds')) return 0;
    $st = $pdo->prepare('SELECT COUNT(*) FROM deal_rounds WHERE deal_id = ? AND stage_id = ?');
    $st->execute([$dealId, $stageId]);
    return (int)$st->fetchColumn();
}

/** Mais uma ronda nesta etapa; acima das incluídas fica o aviso (e o comercial é notificado). */
function rounds_add(PDO $pdo, array $deal, array $stage, string $note, int $by): string {
    $pdo->prepare('INSERT INTO deal_rounds (deal_id, stage_id, note, user_id, created_at) VALUES (?,?,?,?,?)')
        ->execute([$deal['id'], $stage['id'], mb_substr(trim($note), 0, 255), $by, date('Y-m-d H:i:s')]);
    $n = rounds_count($pdo, (int)$deal['id'], (int)$stage['id']);
    $inc = $stage['rounds_included'] !== null ? (int)$stage['rounds_included'] : null;
    $over = $inc !== null && $n > $inc;
    deal_log($pdo, (int)$deal['id'], $over ? 'rounds' : 'round', ['note' => 'Ronda ' . $n . ($inc !== null ? ' de ' . $inc . ' incluídas' : '') . ' (' . $stage['name'] . ')'
        . ($over ? ' · acima do incluído: pedido de alteração orçamentado' : '') . (trim($note) !== '' ? ' · ' . trim($note) : ''), 'user_id' => $by]);
    return 'Ronda ' . $n . ' registada' . ($inc !== null ? ' (' . $inc . ' incluídas)' : '') . '.' . ($over ? ' Acima do incluído: é um pedido de alteração orçamentado e o comercial foi avisado.' : '');
}
