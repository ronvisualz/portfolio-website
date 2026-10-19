<?php
/**
 * topper. admin — equipa e tarefas
 *
 * As tarefas vivem em pilhas: uma por pessoa e a pilha «Por atribuir»
 * (member_id NULL). A ordem dentro de cada pilha é a coluna position.
 * Há um só login para todos os sócios, por isso não há permissões:
 * atribuir é só escolher a pessoa.
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

/** Toda a equipa, pela ordem das pilhas. Indexada pelo id. */
function team_members(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT * FROM team_members ORDER BY position, id')->fetchAll() as $m) {
        $out[(int)$m['id']] = $m;
    }
    return $out;
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
function task_input(array $in, array $members): array {
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
        'due_date'    => $due !== '' ? $due : null,
        'priority'    => isset(TASK_PRIO[$prio]) ? $prio : 'media',
        'status'      => isset(TASK_STATUS[$status]) ? $status : 'todo',
    ], null];
}

function task_create(PDO $pdo, array $v): int {
    $now = task_now();
    $pdo->prepare(
        'INSERT INTO tasks (title, description, member_id, job_name, due_date, priority, status, position, done_at, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $v['title'], $v['description'], $v['member_id'], $v['job_name'], $v['due_date'],
        $v['priority'], $v['status'], task_next_position($pdo, $v['member_id']),
        $v['status'] === 'done' ? $now : null, $now, $now,
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
        'UPDATE tasks SET title = ?, description = ?, member_id = ?, job_name = ?, due_date = ?,
                          priority = ?, status = ?, position = ?, done_at = ?, updated_at = ?
          WHERE id = ?'
    )->execute([
        $v['title'], $v['description'], $v['member_id'], $v['job_name'], $v['due_date'],
        $v['priority'], $v['status'], $pos, $doneAt, task_now(), (int)$old['id'],
    ]);
}

/**
 * Marca como feita. A pessoa fica a mesma (sem dono continua sem dono):
 * é ela que dá a cor ao cartão na coluna «Feito».
 */
function task_done(PDO $pdo, int $id): void {
    $now = task_now();
    $pdo->prepare("UPDATE tasks SET status = 'done', done_at = ?, updated_at = ? WHERE id = ? AND status <> 'done'")
        ->execute([$now, $now, $id]);
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

/** Nomes dos trabalhos na folha Google, para sugerir. Sem folha, lista vazia. */
function task_job_names(): array {
    if (!function_exists('sheet_data') || sheet_config_raw() === null) return [];
    try {
        $names = array_map(fn($j) => (string)$j['name'], sheet_data()['jobs'] ?? []);
    } catch (Throwable $e) {
        return [];
    }
    $names = array_values(array_unique(array_filter($names, fn($n) => $n !== '')));
    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return $names;
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
function task_done_list(PDO $pdo, int $limit, ?int $member = null): array {
    $where = "status = 'done'" . ($member ? ' AND member_id = ' . (int)$member : '');
    $total = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE $where")->fetchColumn();
    $list = $pdo->query(
        "SELECT * FROM tasks WHERE $where ORDER BY COALESCE(done_at, updated_at) DESC, id DESC LIMIT " . max(1, $limit)
    )->fetchAll();
    return [$list, $total];
}
