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
        "SELECT tm.*, u.id AS user_id, u.is_active AS user_active,
                (SELECT COUNT(*) FROM tasks t WHERE t.member_id = tm.id AND t.status <> 'done') AS open_tasks
           FROM team_members tm LEFT JOIN users u ON u.team_member_id = tm.id
          ORDER BY tm.position, tm.id"
    )->fetchAll();
    $out = [];
    foreach ($rows as $m) {
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
    $prio   = (string)($in['priority'] ?? 'media');
    $status = (string)($in['status'] ?? 'todo');

    return [[
        'title'       => $title,
        'description' => trim((string)($in['description'] ?? '')),
        'member_id'   => task_member_in($in['member_id'] ?? 0, $members),
        'job_name'    => clip(trim((string)($in['job_name'] ?? '')), 160),
        'deal_id'     => $pdo ? task_deal_in($in['deal_id'] ?? 0, $pdo) : null,
        'due_date'    => $due !== '' ? $due : null,
        'priority'    => isset(TASK_PRIO[$prio]) ? $prio : 'media',
        'status'      => isset(TASK_STATUS[$status]) ? $status : 'todo',
    ], null];
}

function task_create(PDO $pdo, array $v): int {
    $now = task_now();
    $pdo->prepare(
        'INSERT INTO tasks (title, description, member_id, job_name, deal_id, due_date, priority, status, position, done_at, created_at, updated_at, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $v['title'], $v['description'], $v['member_id'], $v['job_name'], $v['deal_id'], $v['due_date'],
        $v['priority'], $v['status'], task_next_position($pdo, $v['member_id']),
        $v['status'] === 'done' ? $now : null, $now, $now, current_user()['id'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
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
        'UPDATE tasks SET title = ?, description = ?, member_id = ?, job_name = ?, deal_id = ?, due_date = ?,
                          priority = ?, status = ?, position = ?, done_at = ?, updated_at = ?
          WHERE id = ?'
    )->execute([
        $v['title'], $v['description'], $v['member_id'], $v['job_name'], $v['deal_id'], $v['due_date'],
        $v['priority'], $v['status'], $pos, $doneAt, task_now(), (int)$old['id'],
    ]);
}

/**
 * Marca como feita. A pessoa fica a mesma (sem dono continua sem dono):
 * é ela que dá a cor ao cartão na coluna «Feito». Fica no histórico do
 * negócio ou da lead ligados, e uma tarefa que se repete cria logo a
 * seguinte. Devolve o id dessa seguinte (ou null).
 */
function task_done(PDO $pdo, int $id): ?int {
    $t = task_by_id($pdo, $id);
    $now = task_now();
    $st = $pdo->prepare("UPDATE tasks SET status = 'done', done_at = ?, updated_at = ? WHERE id = ? AND status <> 'done'");
    $st->execute([$now, $now, $id]);
    if (!$t || $t['status'] === 'done' || $st->rowCount() === 0) return null;
    if (!empty($t['deal_id']) && function_exists('deal_log')) {
        $kind = defined('LEAD_TASK_KINDS') && isset(LEAD_TASK_KINDS[$t['kind'] ?? '']) ? LEAD_TASK_KINDS[$t['kind']][0] . ' · ' : '';
        deal_log($pdo, (int)$t['deal_id'], 'task_done', ['note' => $kind . $t['title']]);
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
    $pdo->prepare('INSERT INTO tasks (title, description, member_id, job_name, deal_id, due_date, due_time, duration_min, kind, repeat_rule,
                                      priority, status, position, created_at, updated_at, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,\'todo\',?,?,?,?)')
        ->execute([$t['title'], $t['description'], $member, $t['job_name'], $t['deal_id'], $next, $t['due_time'], $t['duration_min'],
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
        if (!$row) throw new RuntimeException('not found');
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
