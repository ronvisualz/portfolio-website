<?php
/**
 * topper. admin — equipa e tarefas
 *
 * As tarefas vivem em pilhas: uma por pessoa e a pilha «Por atribuir»
 * (member_id NULL). A ordem dentro de cada pilha é a coluna position.
 *
 * As pessoas vêm da aba Equipa: cada utilizador tem a sua pilha
 * (team_members, ligada por users.team_member_id). team_sync() cria e
 * liga as pilhas que faltam — a primeira vez pelo nome, para as tarefas
 * antigas seguirem a pessoa certa. Quem vê que pilhas segue o âmbito da
 * aba Tarefas; atribuir segue as regras de atribuição (inc/perm.php).
 * Uma tarefa liga-se a um negócio pelo ID (deal_id), nunca pelo nome.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

const TASK_STATUS = [
    'todo'   => 'A fazer',
    'doing'  => 'Em curso',
    'review' => 'Em revisão',
    'done'   => 'Feito',
];

/** Quantas feitas mostra a coluna «Feito» de cada vez («Ver mais» soma outras tantas). */
const TASK_DONE_PAGE = 30;

const TASK_PRIO = [
    'alta'  => 'Alta',
    'media' => 'Média',
    'baixa' => 'Baixa',
];

/**
 * Liga cada utilizador à sua pilha: primeiro pelo nome (pilhas antigas),
 * senão cria uma. Nome e cor seguem sempre o utilizador.
 */
function team_sync(PDO $pdo): void {
    $users = $pdo->query('SELECT id, name, color, team_member_id FROM users ORDER BY position, id')->fetchAll();
    $members = $pdo->query('SELECT id, name FROM team_members')->fetchAll();
    $linked = array_filter(array_map(fn($u) => $u['team_member_id'] !== null ? (int)$u['team_member_id'] : null, $users));
    foreach ($users as $u) {
        $mid = $u['team_member_id'] !== null ? (int)$u['team_member_id'] : null;
        if ($mid === null) {
            foreach ($members as $m) {
                if (!in_array((int)$m['id'], $linked, true)
                    && mb_strtolower(trim($m['name'])) === mb_strtolower(trim($u['name']))) {
                    $mid = (int)$m['id'];
                    break;
                }
            }
            if ($mid === null) {
                $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) FROM team_members')->fetchColumn() + 1;
                $pdo->prepare('INSERT INTO team_members (name, color, position) VALUES (?,?,?)')
                    ->execute([$u['name'], $u['color'] ?: TEAM_COLORS[$pos % count(TEAM_COLORS)], $pos]);
                $mid = (int)$pdo->lastInsertId();
            }
            $linked[] = $mid;
            $pdo->prepare('UPDATE users SET team_member_id = ? WHERE id = ?')->execute([$mid, $u['id']]);
        }
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$u['color']) ? $u['color'] : null;
        $pdo->prepare('UPDATE team_members SET name = ?, color = COALESCE(?, color) WHERE id = ?')->execute([$u['name'], $color, $mid]);
        if (!$color) {
            $pdo->prepare("UPDATE users SET color = (SELECT color FROM team_members WHERE id = ?) WHERE id = ? AND color = ''")
                ->execute([$mid, $u['id']]);
        }
    }
}

/**
 * As pilhas, pela ordem. Indexadas pelo id, com user_id (null numa pilha
 * antiga ainda sem utilizador). Quem foi desativado só aparece enquanto
 * tiver tarefas por fazer.
 */
function team_members(PDO $pdo): array {
    team_sync($pdo);
    $rows = $pdo->query(
        "SELECT tm.*, u.id AS user_id, u.is_active AS user_active, u.role AS user_role,
                (SELECT COUNT(*) FROM tasks t WHERE t.member_id = tm.id AND t.status <> 'done') AS open_tasks
           FROM team_members tm LEFT JOIN users u ON u.team_member_id = tm.id
          ORDER BY tm.position, tm.id"
    )->fetchAll();
    $out = [];
    foreach ($rows as $m) {
        if ($m['user_role'] === 'superadmin') continue;   // o superadmin não é operador (§18.2)
        $gone = $m['user_id'] === null || (int)$m['user_active'] !== 1;
        if ($gone && (int)$m['open_tasks'] === 0) continue;
        $out[(int)$m['id']] = $m;
    }
    return $out;
}

/**
 * Que pilhas posso ver, pelo âmbito da aba Tarefas: null = todas.
 * Uma pilha antiga sem utilizador só se vê com âmbito «todos».
 */
function task_visible_members(PDO $pdo, array $members): ?array {
    $scope = my_scope('tarefas');
    if ($scope === 'all') return null;
    $users = $scope === 'dept' ? dept_user_ids($pdo, current_user()) : [(int)current_user()['id']];
    $ids = [];
    foreach ($members as $mid => $m) {
        if ($m['user_id'] !== null && in_array((int)$m['user_id'], $users, true)) $ids[] = (int)$mid;
    }
    return $ids;
}

/** Posso mexer numa tarefa desta pilha? (null = Por atribuir: toda a gente) */
function task_member_visible(?int $mid, ?array $visible): bool {
    return $mid === null || $visible === null || in_array($mid, $visible, true);
}

/**
 * Posso pôr uma tarefa na pilha $to? A pilha tem de estar à vista e,
 * sendo de outra pessoa, as regras de atribuição têm de o permitir.
 */
function task_can_assign(PDO $pdo, ?int $to, array $members, ?array $visible): bool {
    if ($to === null) return true;
    if (!task_member_visible($to, $visible)) return false;
    $uid = $members[$to]['user_id'] ?? null;
    if ($uid === null) return is_admin();
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$uid]);
    $target = $st->fetch();
    $me = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $me->execute([(int)current_user()['id']]);
    return $target && can_assign_to($me->fetch(), $target);
}

/** Negócios a que se pode ligar uma tarefa ou um evento: os ativos, mais o atual (que pode ser uma lead). */
function task_deal_options(PDO $pdo, ?int $current = null): array {
    $st = $pdo->prepare("SELECT id, code, name FROM deals WHERE (code IS NOT NULL AND phase IN ('pipeline','production')) OR id = ?
                          ORDER BY code IS NULL, code DESC");
    $st->execute([(int)$current]);
    $out = [];
    foreach ($st->fetchAll() as $d) $out[(int)$d['id']] = ($d['code'] ? $d['code'] : 'Lead') . ' · ' . $d['name'];
    return $out;
}

/** Um negócio (com ID) ou uma lead: as tarefas das leads ficam ligadas à lead (§12.7). */
function task_deal_in($raw, PDO $pdo): ?int {
    $id = (int)$raw;
    if ($id <= 0) return null;
    $st = $pdo->prepare("SELECT COUNT(*) FROM deals WHERE id = ? AND (code IS NOT NULL OR phase IN ('lead','archived'))");
    $st->execute([$id]);
    return (int)$st->fetchColumn() > 0 ? $id : null;
}

/** Primeiro nome — chega para os cartões e para o calendário. */
function first_name(string $name): string {
    return explode(' ', trim($name))[0];
}

/** Cor guardada, ou a da paleta pela ordem, se estiver vazia ou estragada. */
function member_color(array $m): string {
    $c = (string)($m['color'] ?? '');
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) return $c;
    return TEAM_COLORS[((int)$m['id'] - 1) % count(TEAM_COLORS)];
}

function task_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM tasks WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** null = «Por atribuir». Um id que não existe também cai aí. */
function task_member_in($raw, array $members): ?int {
    $id = (int)$raw;
    return isset($members[$id]) ? $id : null;
}

function task_now(): string {
    return date('Y-m-d H:i:s');
}

/** Lugar no fim de uma pilha. */
function task_next_position(PDO $pdo, ?int $member): int {
    $st = $member === null
        ? $pdo->query('SELECT COALESCE(MAX(position), 0) FROM tasks WHERE member_id IS NULL')
        : $pdo->prepare('SELECT COALESCE(MAX(position), 0) FROM tasks WHERE member_id = ?');
    if ($member !== null) $st->execute([$member]);
    return (int)$st->fetchColumn() + 1;
}

/**
 * Valida o formulário de uma tarefa. Devolve [valores, erro].
 * O erro vem em português, pronto para o flash.
 */
function task_input(array $in, array $members, ?PDO $pdo = null): array {
    $title = clip(trim((string)($in['title'] ?? '')), 200);
    if ($title === '') return [null, 'A tarefa precisa de um título.'];

    $due = trim((string)($in['due_date'] ?? ''));
    if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
        return [null, 'Indique um prazo válido.'];
    }
    $time = trim((string)($in['due_time'] ?? ''));
    if ($time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) return [null, 'Indique uma hora válida.'];
    if ($time !== '' && $due === '') return [null, 'Uma hora precisa de um dia: indique o prazo.'];
    $dur = (int)($in['duration_min'] ?? 0);
    $prio   = (string)($in['priority'] ?? 'media');
    $status = (string)($in['status'] ?? 'todo');

    return [[
        'title'       => $title,
        'description' => trim((string)($in['description'] ?? '')),
        'member_id'   => task_member_in($in['member_id'] ?? 0, $members),
        'job_name'    => clip(trim((string)($in['job_name'] ?? '')), 160),
        'deal_id'     => $pdo ? task_deal_in($in['deal_id'] ?? 0, $pdo) : null,
        'due_date'    => $due !== '' ? $due : null,
        // Com hora: Ocupado bloqueia o bloco na agenda (§17.7); Livre fica só como lembrete.
        'due_time'    => $time !== '' ? substr($time, 0, 5) . ':00' : null,
        'duration_min'=> $time !== '' && $dur > 0 ? min(720, $dur) : null,
        'busy'        => ($in['busy'] ?? '1') === '0' ? 0 : 1,
        'priority'    => isset(TASK_PRIO[$prio]) ? $prio : 'media',
        'status'      => isset(TASK_STATUS[$status]) ? $status : 'todo',
    ], null];
}

/** A tarefa já tem Ocupado/Livre (passo 19 da base de dados)? */
function task_has_busy(PDO $pdo): bool {
    static $has = null;
    return $has ??= db_has_column($pdo, 'tasks', 'busy');
}

/** Concluir a chamada de uma lead pede o resultado: abre-se a ficha da lead («Como correu?»). */
class TaskNeedsOutcome extends RuntimeException {
    public int $lead;
    public function __construct(int $lead) {
        parent::__construct('Registe como correu a chamada (não atendida, Follow-Up, arquivar ou converter): a tarefa fica feita com o resultado.');
        $this->lead = $lead;
    }
}

/**
 * Uma tarefa feita à mão «Emitir fatura…» ligada a um negócio segue a mesma regra das
 * automáticas: só fica feita quando o PDF da fatura é submetido no negócio. Devolve o doc_kind.
 */
function task_doc_kind_for(string $title, $dealId): string {
    return $dealId && preg_match('/^\s*emitir\s+(a\s+)?fatura/iu', $title) ? 'invoice' : '';
}

function task_create(PDO $pdo, array $v): int {
    $now = task_now();
    $pdo->prepare(
        'INSERT INTO tasks (title, description, member_id, job_name, deal_id, due_date, due_time, duration_min, priority, status, position, done_at, created_at, updated_at, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $v['title'], $v['description'], $v['member_id'], $v['job_name'], $v['deal_id'], $v['due_date'], $v['due_time'] ?? null, $v['duration_min'] ?? null,
        $v['priority'], $v['status'], task_next_position($pdo, $v['member_id']),
        $v['status'] === 'done' ? $now : null, $now, $now, current_user()['id'] ?? null,
    ]);
    $id = (int)$pdo->lastInsertId();
    if (task_has_busy($pdo)) $pdo->prepare('UPDATE tasks SET busy = ? WHERE id = ?')->execute([(int)($v['busy'] ?? 1), $id]);
    return $id;
}

function task_update(PDO $pdo, array $old, array $v): void {
    // Mudou de pilha: vai para o fim da nova.
    $pos = (int)$old['position'];
    $oldMember = $old['member_id'] !== null ? (int)$old['member_id'] : null;
    if ($oldMember !== $v['member_id']) $pos = task_next_position($pdo, $v['member_id']);

    // Ficou feita agora: data de hoje. Já estava: mantém. Reaberta: limpa.
    $doneAt = null;
    if ($v['status'] === 'done') {
        $doneAt = $old['status'] === 'done' && !empty($old['done_at']) ? $old['done_at'] : task_now();
    }

    $pdo->prepare(
        'UPDATE tasks SET title = ?, description = ?, member_id = ?, job_name = ?, deal_id = ?, due_date = ?, due_time = ?, duration_min = ?,
                          priority = ?, status = ?, position = ?, done_at = ?, updated_at = ?
          WHERE id = ?'
    )->execute([
        $v['title'], $v['description'], $v['member_id'], $v['job_name'], $v['deal_id'], $v['due_date'], $v['due_time'] ?? null, $v['duration_min'] ?? null,
        $v['priority'], $v['status'], $pos, $doneAt, task_now(), (int)$old['id'],
    ]);
    if (task_has_busy($pdo)) $pdo->prepare('UPDATE tasks SET busy = ? WHERE id = ?')->execute([(int)($v['busy'] ?? 1), (int)$old['id']]);
}

/**
 * Marca como feita. A pessoa fica a mesma (sem dono continua sem dono):
 * é ela que dá a cor ao cartão na coluna «Feito». Fica no histórico do
 * negócio ou da lead ligados, e uma tarefa que se repete cria logo a
 * seguinte. Devolve o id dessa seguinte (ou null).
 */
function task_done(PDO $pdo, int $id): ?int {
    $t = task_by_id($pdo, $id);
    // A chamada de uma lead só fica feita com o resultado («Como correu?»), registado na ficha da lead.
    if ($t && $t['status'] !== 'done' && $t['kind'] === 'call' && !empty($t['deal_id']) && empty($GLOBALS['LEAD_OUTCOME_OK'])) {
        $ph = $pdo->prepare('SELECT phase FROM deals WHERE id = ?');
        $ph->execute([(int)$t['deal_id']]);
        if ($ph->fetchColumn() === 'lead') throw new TaskNeedsOutcome((int)$t['deal_id']);
    }
    // Faturas, pagamentos e X-Ray (§19.6 E) só ficam feitos pelo documento/validação no negócio.
    if ($t && $t['status'] !== 'done' && ($t['doc_kind'] ?? '') !== '') {
        require_once __DIR__ . '/pipeline_flow.php';
        $why = flow_task_needs_doc($t);
        if ($why !== '') throw new RuntimeException($why);
    }
    $now = task_now();
    $st = $pdo->prepare("UPDATE tasks SET status = 'done', done_at = ?, updated_at = ? WHERE id = ? AND status <> 'done'");
    $st->execute([$now, $now, $id]);
    if (!$t || $t['status'] === 'done' || $st->rowCount() === 0) return null;
    if (!empty($t['deal_id']) && function_exists('deal_log')) {
        $kind = defined('LEAD_TASK_KINDS') && isset(LEAD_TASK_KINDS[$t['kind'] ?? '']) ? LEAD_TASK_KINDS[$t['kind']][0] . ' · ' : '';
        deal_log($pdo, (int)$t['deal_id'], 'task_done', ['note' => $kind . $t['title']]);
    }
    // Tarefa de um contacto (§13): fica no histórico dele; numa relação acompanhada, um
    // follow-up, chamada, reunião, email, almoço ou jantar feito conta como toque.
    if (!empty($t['contact_id'])) {
        require_once __DIR__ . '/contacts.php';
        if (($t['kind'] ?? '') !== 'relation') contact_log($pdo, (int)$t['contact_id'], 'task_done', task_kind_info($t['kind'] ?? 'task')[0] . ' · ' . $t['title']);
        if (in_array($t['kind'] ?? '', ['relation', 'call', 'meeting', 'email', 'lunch', 'dinner'], true) && relation_get($pdo, (int)$t['contact_id'])) {
            relation_touch($pdo, (int)$t['contact_id'], null, ($t['kind'] ?? '') === 'relation' ? 'Follow-up feito' : task_kind_info($t['kind'])[0] . ' feita');
        }
    }
    return task_repeat_next($pdo, $t);
}

/** A próxima vez de uma tarefa que se repete (todos os dias, semanas ou meses). */
function task_repeat_next(PDO $pdo, array $t): ?int {
    $step = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month'][$t['repeat_rule'] ?? ''] ?? null;
    if (!$step) return null;
    $base = $t['due_date'] ?: date('Y-m-d');
    $next = (new DateTimeImmutable($base))->modify($step)->format('Y-m-d');
    $member = $t['member_id'] !== null ? (int)$t['member_id'] : null;
    $now = task_now();
    $pdo->prepare('INSERT INTO tasks (title, description, member_id, job_name, deal_id, contact_id, due_date, due_time, duration_min, kind, repeat_rule,
                                      priority, status, position, created_at, updated_at, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'todo\',?,?,?,?)')
        ->execute([$t['title'], $t['description'], $member, $t['job_name'], $t['deal_id'], $t['contact_id'] ?? null, $next, $t['due_time'], $t['duration_min'],
                   $t['kind'] ?: 'task', $t['repeat_rule'], $t['priority'], task_next_position($pdo, $member), $now, $now, $t['created_by']]);
    return (int)$pdo->lastInsertId();
}

/** Reabre uma feita na pilha $member (null = Por atribuir), no fim. */
function task_reopen(PDO $pdo, int $id, ?int $member): array {
    return task_move($pdo, $id, $member);
}

/**
 * Põe a tarefa na pilha $member e grava a ordem dessa pilha.
 * $order são os ids da pilha de destino, de cima para baixo, já com a
 * tarefa no sítio onde foi largada. Ids que não pertencem à pilha são
 * ignorados; sem ordem, a tarefa vai para o fim. Se estava feita,
 * reabre (A fazer). As feitas não contam para a ordem das pilhas.
 * Devolve a ordem gravada.
 */
function task_move(PDO $pdo, int $id, ?int $member, array $order = []): array {
    $pdo->beginTransaction();
    try {
        $old = $pdo->prepare('SELECT member_id, status FROM tasks WHERE id = ?');
        $old->execute([$id]);
        $row = $old->fetch();
        if (!$row) throw new RuntimeException('Essa tarefa já não existe. Recarregue a página.');
        $oldMember = $row['member_id'] !== null ? (int)$row['member_id'] : null;

        // Tirada da coluna «Feito» para uma pilha: volta a «A fazer», no fim.
        if ($row['status'] === 'done') {
            $pdo->prepare("UPDATE tasks SET status = 'todo', done_at = NULL, member_id = ?, position = ?, updated_at = ? WHERE id = ?")
                ->execute([$member, task_next_position($pdo, $member), task_now(), $id]);
            $oldMember = $member;
        }

        if ($oldMember !== $member) {
            $pdo->prepare('UPDATE tasks SET member_id = ?, position = ?, updated_at = ? WHERE id = ?')
                ->execute([$member, task_next_position($pdo, $member), task_now(), $id]);
        }

        // Só os ids que estão mesmo nesta pilha; os que faltarem ficam depois.
        $st = $member === null
            ? $pdo->query("SELECT id FROM tasks WHERE member_id IS NULL AND status <> 'done' ORDER BY position, id")
            : $pdo->prepare("SELECT id FROM tasks WHERE member_id = ? AND status <> 'done' ORDER BY position, id");
        if ($member !== null) $st->execute([$member]);
        $inStack = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

        $final = [];
        foreach ($order as $o) {
            $o = (int)$o;
            if (in_array($o, $inStack, true) && !in_array($o, $final, true)) $final[] = $o;
        }
        foreach ($inStack as $o) {
            if (!in_array($o, $final, true)) $final[] = $o;
        }

        $up = $pdo->prepare('UPDATE tasks SET position = ? WHERE id = ?');
        foreach ($final as $i => $o) $up->execute([$i + 1, $o]);

        $pdo->commit();
        return $final;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** «12 out» para os cartões; vazio sem prazo. */
function task_due_label(?string $d): string {
    if (!$d) return '';
    return (int)substr($d, 8, 2) . ' ' . strtolower(substr(month_name((int)substr($d, 5, 2)), 0, 3));
}

/**
 * As feitas, das mais recentes para as mais antigas, até $limit.
 * Devolve [lista, total]. $member limita a uma pessoa.
 */
function task_done_list(PDO $pdo, int $limit, ?int $member = null, ?array $visible = null): array {
    $where = "status = 'done'" . ($member ? ' AND member_id = ' . (int)$member : '');
    if ($visible !== null) {
        $where .= ' AND (member_id IS NULL' . ($visible ? ' OR member_id IN (' . implode(',', array_map('intval', $visible)) . ')' : '') . ')';
    }
    $total = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE $where")->fetchColumn();
    $list = $pdo->query(
        "SELECT * FROM tasks WHERE $where ORDER BY COALESCE(done_at, updated_at) DESC, id DESC LIMIT " . max(1, $limit)
    )->fetchAll();
    return [$list, $total];
}

// ════════════════════════════════════════════════════════════
// Aba Tarefas (§18): tipos, origens, urgência, grupos automáticos, privacidade
// ════════════════════════════════════════════════════════════

/** Os 19 tipos (§18.6; Almoço, Jantar e Evento vêm da ficha da lead): chave → [nome, ícone]. A cor vem do CSS (.ty-<chave>, tokens claro/escuro). */
/** Os tipos com participantes (recebem o convite no chat). */
const TASK_GUEST_KINDS = ['meeting', 'shoot', 'visit'];

const TASK_TYPES = [
    'call'     => ['Chamada', 'phone'],
    'meeting'  => ['Reunião', 'meeting'],
    'lunch'    => ['Almoço', 'utensils'],
    'dinner'   => ['Jantar', 'wine'],
    'event'    => ['Evento', 'calendar'],
    'email'    => ['Email', 'mail'],
    'message'  => ['Mensagem / WhatsApp', 'bubble'],
    'followup' => ['Follow-up', 'refresh'],
    'visit'    => ['Visita / deslocação', 'pin'],
    'shoot'    => ['Gravação', 'camera'],
    'edit'     => ['Edição', 'scissors'],
    'render'   => ['Render', 'cog'],
    'review'   => ['Revisão / aprovação', 'eye'],
    'delivery' => ['Entrega', 'package'],
    'quote'    => ['Orçamento / proposta', 'euro'],
    'document' => ['Documento', 'file'],
    'task'     => ['Tarefa', 'checklist'],
    'personal' => ['Pessoal', 'user'],
    'block'    => ['Bloqueio', 'ban'],
];

/** As origens (§18.6): o mesmo ícone da aba de origem no menu. */
const TASK_ORIGINS = [
    'lead'     => ['Lead', 'leads'],
    'deal'     => ['Negócio', 'negocios'],
    'mkt'      => ['Marketing', 'marketing'],
    'relation' => ['Relação', 'contactos'],
    'fin'      => ['Financeiro', 'financeiro'],
    'personal' => ['Pessoal', 'user'],
    'manual'   => ['Manual', 'tarefas'],
];

const TASK_BUCKETS = ['late' => 'Em atraso', 'today' => 'Hoje', 'week' => 'Esta semana', 'later' => 'Mais tarde', 'none' => 'Sem data'];
const TASK_DURATIONS = [0 => '1 h (por defeito)', 15 => '15 min', 30 => '30 min', 45 => '45 min', 90 => '1 h 30', 120 => '2 h', 180 => '3 h', 240 => '4 h', 480 => 'Dia inteiro (8 h)'];
/** Quantos cartões cabem numa coluna do quadro (§18.4). */
const TASK_BOARD_MAX = 6;

/** O tipo de uma tarefa (os antigos: relação → follow-up, almoço/jantar/evento → reunião). */
function task_type(array $t): string {
    $k = (string)($t['kind'] ?? 'task');
    if (isset(TASK_TYPES[$k])) return $k;
    return ['relation' => 'followup'][$k] ?? 'task';
}

/** A origem: pessoal, relação, Marketing, lead, negócio, Financeiro ou manual (feita aqui). */
function task_origin(array $t): string {
    if (!empty($t['private']) || in_array($t['kind'] ?? '', ['personal', 'block'], true)) return 'personal';
    if (($t['kind'] ?? '') === 'relation') return 'relation';
    if (!empty($t['mkt_piece_id'])) return 'mkt';
    if (!empty($t['deal_id'])) return in_array($t['deal_phase'] ?? '', ['lead', 'archived'], true) ? 'lead' : 'deal';
    if (!empty($t['fin_key'])) return 'fin';   // saldos da tesouraria e calendário fiscal (o cron do Financeiro)
    return 'manual';
}

function task_type_badge(array $t, string $size = ''): string {
    $k = task_type($t);
    [$l, $i] = TASK_TYPES[$k];
    return '<span class="tty ty-' . $k . ($size ? ' ' . $size : '') . '" data-tip="' . e($l) . '" aria-label="' . e($l) . '" role="img">' . icon($i) . '</span>';
}

function task_origin_badge(array $t): string {
    $k = task_origin($t);
    [$l, $i] = TASK_ORIGINS[$k];
    return '<span class="tor or-' . $k . '" data-tip="Origem: ' . e($l) . '" aria-label="Origem: ' . e($l) . '" role="img">' . icon($i) . '</span>';
}

/** Em atraso · Hoje · Esta semana · Mais tarde · Sem data. Com hora, «em atraso» conta a hora. */
function task_bucket(array $t, ?string $today = null): string {
    $today ??= today_lisbon();
    $d = (string)($t['due_date'] ?? '');
    if ($d === '') return 'none';
    if ($d < $today) return 'late';
    if ($d === $today) {
        if ($t['due_time'] && team_ts_local($d . ' ' . substr((string)$t['due_time'], 0, 5)) + 60 * (int)($t['duration_min'] ?: 60) < time()) return 'late';
        return 'today';
    }
    $sunday = (new DateTimeImmutable($today))->modify('sunday this week')->format('Y-m-d');
    return $d <= $sunday ? 'week' : 'later';
}

/** Instante unix de «aaaa-mm-dd hh:mm» em Lisboa (sem depender da aba Equipa). */
function team_ts_local(string $local): int {
    return (new DateTimeImmutable($local, new DateTimeZone('Europe/Lisbon')))->getTimestamp();
}

/**
 * O prazo de uma linha (§18.3): [«20/09/26 · 12:00», «30 min · vencido há 32d 5h», em atraso?].
 * Sem hora, o prazo é o fim desse dia.
 */
function task_due_parts(array $t, ?int $now = null): array {
    $d = (string)($t['due_date'] ?? '');
    if ($d === '') return ['Sem prazo', '', false];
    $now ??= time();
    $time = $t['due_time'] ? substr((string)$t['due_time'], 0, 5) : '';
    $abs = date('d/m/y', strtotime($d)) . ($time !== '' ? ' · ' . $time : '');
    $at = $time !== '' ? team_ts_local($d . ' ' . $time) : team_ts_local($d . ' 00:00') + 86400;
    $late = task_bucket($t) === 'late';
    $span = function (int $s): string {
        $m = intdiv($s, 60);
        if ($m < 60) return max(1, $m) . ' min';
        if ($m < 1440) return intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'min' : '');
        return intdiv($m, 1440) . 'd' . (intdiv($m % 1440, 60) ? ' ' . intdiv($m % 1440, 60) . 'h' : '');
    };
    $today = today_lisbon();
    if ($late) $rel = 'vencido há ' . $span(max(60, $now - $at));
    elseif ($d === $today) $rel = $time !== '' && $at > $now ? 'daqui a ' . $span($at - $now) : 'hoje';
    elseif ($d === (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d')) $rel = 'amanhã';
    else $rel = 'em ' . (int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable($d))->format('%a') . ' dias';
    $dur = $time !== '' && (int)($t['duration_min'] ?? 0) ? (TASK_DURATIONS[(int)$t['duration_min']] ?? $t['duration_min'] . ' min') : '';
    return [$abs, trim($dur . ($dur !== '' ? ' · ' : '') . $rel), $late];
}

/** «hoje 15:00», «ontem», «sex 30/10», «12/11». */
function task_when(array $t, ?string $today = null): string {
    $today ??= today_lisbon();
    $d = (string)($t['due_date'] ?? '');
    if ($d === '') return '';
    $time = $t['due_time'] ? substr((string)$t['due_time'], 0, 5) : '';
    $diff = (int)(new DateTimeImmutable($today))->diff(new DateTimeImmutable($d))->format('%r%a');
    $days = [1 => 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];
    $day = match (true) {
        $diff === 0 => $time !== '' ? '' : 'hoje',
        $diff === -1 => 'ontem',
        $diff === 1 => 'amanhã',
        $diff > 1 && $diff < 7 => $days[(int)date('N', strtotime($d))] . ' ' . date('d/m', strtotime($d)),
        $diff < -1 && $diff > -7 => 'há ' . -$diff . ' dias',
        default => date('d/m', strtotime($d)) . (substr($d, 0, 4) !== substr($today, 0, 4) ? '/' . substr($d, 2, 2) : ''),
    };
    return trim($day . ' ' . $time);
}

/** As tarefas automáticas iguais que se juntam numa linha (§18.3): chamadas de leads e follow-ups de relação. */
function task_auto_group(array $t): ?string {
    if (($t['kind'] ?? '') === 'relation') return 'relation';
    if (($t['kind'] ?? '') === 'call' && ($t['deal_phase'] ?? '') === 'lead' && str_starts_with((string)$t['title'], 'Ligar a ')) return 'leadcall';
    return null;
}

const TASK_AUTO_GROUPS = [
    'leadcall' => ['Chamadas de leads', 'call', 'lead', '/admin/leads.php?urgente=1'],
    'relation' => ['Follow-ups de relação', 'followup', 'relation', '/admin/contactos.php?t=relacoes'],
];

/** A tarefa é privada para quem a vê? (pessoal ou bloqueio de outra pessoa: só «Ocupado»). */
function task_hidden_for(array $t, ?int $myMember): bool {
    return !empty($t['private']) && ($t['member_id'] === null || (int)$t['member_id'] !== (int)$myMember);
}

function task_log(PDO $pdo, int $taskId, string $event, string $note = ''): void {
    if (!db_has_table($pdo, 'task_log')) return;
    $pdo->prepare('INSERT INTO task_log (task_id, event, note, user_id, created_at) VALUES (?,?,?,?,?)')
        ->execute([$taskId, $event, mb_substr($note, 0, 255), current_user()['id'] ?? null, now_php()]);
}

/** O histórico de uma tarefa, mais recente primeiro. */
function task_history(PDO $pdo, int $taskId): array {
    if (!db_has_table($pdo, 'task_log')) return [];
    $st = $pdo->prepare('SELECT l.*, u.name FROM task_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.task_id = ? ORDER BY l.created_at DESC, l.id DESC LIMIT 50');
    $st->execute([$taskId]);
    return $st->fetchAll();
}

/** A checklist: [[texto, feito]…], a partir do JSON guardado. */
function task_checklist(array $t): array {
    $j = json_decode((string)($t['checklist'] ?? ''), true);
    return is_array($j) ? array_values(array_filter($j, fn($i) => is_array($i) && isset($i[0]))) : [];
}

/** Do texto do formulário («[x] item» por linha) para a lista. */
function task_checklist_parse(string $raw): ?string {
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $done = (bool)preg_match('/^\[(x|X|✓)\]\s*/u', $line);
        $line = trim(preg_replace('/^\[( |x|X|✓)?\]\s*/u', '', $line));
        if ($line !== '') $out[] = [mb_substr($line, 0, 200), $done];
    }
    return $out ? json_encode(array_slice($out, 0, 40), JSON_UNESCAPED_UNICODE) : null;
}

/** Os anexos: links (Drive, WeTransfer…), um por linha. */
function task_links_parse(string $raw): ?string {
    $out = [];
    foreach (preg_split('/\s+/', trim($raw)) as $u) if (preg_match('~^https?://\S+$~', $u)) $out[] = mb_substr($u, 0, 500);
    return $out ? json_encode(array_slice(array_values(array_unique($out)), 0, 20)) : null;
}

/** Todas as tarefas abertas que posso ver (sem as privadas dos outros), com o necessário para a lista e o quadro. */
function tasks_open(PDO $pdo, ?array $visible, ?int $myMember, bool $done = false, int $doneLimit = 200): array {
    $vis = $visible === null ? '' : ' AND (t.member_id IS NULL' . ($visible ? ' OR t.member_id IN (' . implode(',', array_map('intval', $visible)) . ')' : '') . ')';
    $hasPriv = db_has_column($pdo, 'tasks', 'private');
    // as tarefas do Financeiro (saldos, calendário fiscal) ficam em fin_done: dizem de onde vieram
    $fin = db_has_table($pdo, 'fin_done');
    $sql = 'SELECT t.*, d.code AS deal_code, d.name AS deal_name, d.phase AS deal_phase, d.company AS deal_company, d.person AS deal_person, c.name AS contact_name, h.name AS help_name'
         . ($fin ? ', (SELECT fd.okey FROM fin_done fd WHERE fd.task_id = t.id LIMIT 1) AS fin_key' : '') . '
              FROM tasks t LEFT JOIN deals d ON d.id = t.deal_id LEFT JOIN contacts c ON c.id = t.contact_id
              LEFT JOIN users h ON h.id = ' . ($hasPriv ? 't.help_by' : 'NULL') . '
             WHERE ' . ($done ? "t.status = 'done'" : "t.status <> 'done'") . $vis
         . ($hasPriv ? ' AND (t.private = 0 OR t.member_id = ' . (int)$myMember . ')' : '')
         . ($done ? ' ORDER BY t.done_at DESC, t.id DESC LIMIT ' . (int)$doneLimit : ' ORDER BY t.due_date IS NULL, t.due_date, t.due_time IS NULL, t.due_time, t.id');
    return $pdo->query($sql)->fetchAll();
}

/** A ligação de uma tarefa (a origem, fora da aba Tarefas): [texto, endereço]. */
function task_link(array $t): array {
    // um plano tirado de um quadro do Centro Criativo leva ao quadro (o negócio fica na ficha do quadro)
    if (preg_match('~/admin/criativo\.php\?b=(\d+)~', (string)($t['description'] ?? ''), $m)) return ['Centro Criativo · quadro', '/admin/criativo.php?b=' . (int)$m[1]];
    if (!empty($t['deal_id'])) {
        if (in_array($t['deal_phase'] ?? '', ['lead', 'archived'], true)) {
            $n = trim((string)($t['deal_person'] ?: $t['deal_company'] ?: $t['deal_name']));
            return ['Lead · ' . $n, '/admin/lead.php?id=' . (int)$t['deal_id']];
        }
        return [trim(($t['deal_code'] ? $t['deal_code'] . ' · ' : '') . $t['deal_name']), '/admin/negocio.php?id=' . (int)$t['deal_id']];
    }
    if (!empty($t['contact_id']) && !empty($t['contact_name'])) return [(($t['kind'] ?? '') === 'relation' ? 'Relação · ' : '') . $t['contact_name'], '/admin/contact.php?id=' . (int)$t['contact_id']];
    if (!empty($t['mkt_piece_id'])) return ['Marketing', '/admin/marketing_peca.php?id=' . (int)$t['mkt_piece_id']];
    if (!empty($t['fin_key'])) return str_starts_with((string)$t['fin_key'], 'bal:') ? ['Financeiro · Tesouraria', '/admin/financeiro.php?tab=tesouraria'] : ['Financeiro · Impostos', '/admin/financeiro.php?tab=impostos'];
    if (($t['job_name'] ?? '') !== '') return [(string)$t['job_name'], ''];
    return ['', ''];
}

/** Ordem de urgência: em atraso primeiro, depois a data e a hora, depois a prioridade. */
function task_urgency_sort(array &$list): void {
    $rank = ['late' => 0, 'today' => 1, 'week' => 2, 'later' => 3, 'none' => 4];
    $pr = ['alta' => 0, 'media' => 1, 'baixa' => 2];
    $today = today_lisbon();
    usort($list, fn($a, $b) => [$rank[task_bucket($a, $today)], (string)$a['due_date'] ?: '9', (string)$a['due_time'] ?: '99', $pr[$a['priority']] ?? 1, (int)$a['id']]
                           <=> [$rank[task_bucket($b, $today)], (string)$b['due_date'] ?: '9', (string)$b['due_time'] ?: '99', $pr[$b['priority']] ?? 1, (int)$b['id']]);
}

/**
 * Junta as tarefas automáticas iguais (§18.3): devolve itens ['task' => t] ou
 * ['group' => chave, 'member' => id, 'tasks' => [...], 'late' => n], pela ordem de urgência.
 */
function task_aggregate(array $list): array {
    $out = []; $groups = [];
    foreach ($list as $t) {
        $g = task_auto_group($t);
        if ($g === null) { $out[] = ['task' => $t]; continue; }
        $key = $g . ':' . (int)$t['member_id'];
        if (!isset($groups[$key])) { $groups[$key] = count($out); $out[] = ['group' => $g, 'member' => $t['member_id'] !== null ? (int)$t['member_id'] : null, 'tasks' => [], 'late' => 0]; }
        $i = $groups[$key];
        $out[$i]['tasks'][] = $t;
        if (task_bucket($t) === 'late') $out[$i]['late']++;
    }
    // Um grupo de uma só tarefa fica como tarefa normal.
    return array_map(fn($x) => isset($x['group']) && count($x['tasks']) === 1 ? ['task' => $x['tasks'][0]] : $x, $out);
}
