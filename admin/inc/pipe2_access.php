<?php
/**
 * topper. admin — Pipeline v2: leitura por frente e ações de frente
 *
 * Funções de sessão, só de leitura, como record_reach (D23); ainda não estão ligadas a ecrãs (a fase 2 junta
 * pipe2_front_reach_sql em OR com reach_sql e usa as outras nos pontos de entrada). record_reach e reach_sql não mudam.
 *
 * Leitura pela frente: quem está ligado a uma frente depois do Kickoff (PIPE2_FRONT_READ: nem planned nem merged) de um
 * negócio v2 em produção ou concluído lê esse negócio, sempre com Negócios (can('negocios')). Ligado = responsável da
 * frente (deal_lines.exec_owner_id), executor interno (deal_line_execs.user_id) ou com tarefas da frente (member_id →
 * users.team_member_id, assignee_id, help_by). Ler nunca dá edição.
 *
 * Ações de frente: admin, quem edita o negócio (flow_can_edit) ou o responsável da frente com Negócios EDIT. Interruptor
 * do avanço automático da frente: admin ou o responsável da frente com Negócios EDIT. «Responsável da frente» é só
 * deal_lines.exec_owner_id: um executor interno lê, mas não age nem muda o interruptor. Pedir fatura: flow_can_bill
 * num negócio v2.
 *
 * Nenhuma função lê $_GET/$_POST: o utilizador vem sempre de current_user(), o negócio e a frente relêem-se da base
 * (a frente tem de ser do negócio). Sem sessão, tudo é falso e a condição SQL é '0=1'.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/pipe2_fronts.php';    // pipe2_deal_is_v2, pipe2_v2_funnels_sql
require_once __DIR__ . '/pipeline_view.php';   // flow_can_edit, flow_can_bill (e reach.php: record_reach)

/** As frentes que se leem: as do Kickoff em diante (nem planned nem merged). */
const PIPE2_FRONT_READ = ['queued', 'running', 'depwait', 'ready', 'ongoing'];

/** As fases do negócio em que se lê pela frente. */
const PIPE2_FRONT_READ_PHASES = ['production', 'done'];

/** Os aliases usados dentro das condições (o do negócio não pode ser um deles). */
const PIPE2_ACCESS_ALIASES = ['p2l', 'p2x', 'p2t', 's'];

/** «IN (…)» de uma lista de códigos fixos (constantes deste ficheiro, nunca dados do pedido). */
function pipe2_access_in(array $codes): string {
    return "('" . implode("','", $codes) . "')";
}

/** O id do utilizador da sessão (0 sem sessão). */
function pipe2_access_uid(): int {
    $u = current_user();
    return $u ? max(0, (int)($u['id'] ?? 0)) : 0;
}

/**
 * A condição «o utilizador $uid está ligado à frente de alias $l» (D23): [sql, args]. Executor interno só com a tabela
 * deal_line_execs; tarefas só pelas colunas que existirem, e só as do negócio da frente.
 */
function pipe2_access_link_sql(PDO $pdo, string $l, int $uid): array {
    if ($uid <= 0) return ['0=1', []];
    $or = ["$l.exec_owner_id = ?"];
    $args = [$uid];
    if (db_has_table($pdo, 'deal_line_execs')) {
        $or[] = "EXISTS (SELECT 1 FROM deal_line_execs p2x WHERE p2x.line_id = $l.id AND p2x.user_id = ?)";
        $args[] = $uid;
    }
    $t = [];
    $targs = [];
    foreach (['assignee_id', 'help_by'] as $col) {
        if (db_has_column($pdo, 'tasks', $col)) {
            $t[] = "p2t.$col = ?";
            $targs[] = $uid;
        }
    }
    if (db_has_column($pdo, 'tasks', 'member_id') && db_has_column($pdo, 'users', 'team_member_id')) {
        $st = $pdo->prepare('SELECT team_member_id FROM users WHERE id = ?');
        $st->execute([$uid]);
        $tm = (int)$st->fetchColumn();
        if ($tm > 0) {
            $t[] = 'p2t.member_id = ?';
            $targs[] = $tm;
        }
    }
    if ($t) {
        $or[] = "EXISTS (SELECT 1 FROM tasks p2t WHERE p2t.line_id = $l.id AND p2t.deal_id = $l.deal_id AND (" . implode(' OR ', $t) . '))';
        array_push($args, ...$targs);
    }
    return ['(' . implode(' OR ', $or) . ')', $args];
}

/**
 * Os ids das frentes em PIPE2_FRONT_READ de um negócio v2 em produção ou concluído a que $uid está ligado (D23), a
 * principal primeiro. Sem as colunas da v2, ou sem utilizador, [].
 */
function pipe2_front_lines_of(PDO $pdo, int $dealId, int $uid): array {
    if ($dealId <= 0 || $uid <= 0 || !pipe2_schema_ready($pdo)) return [];
    [$link, $args] = pipe2_access_link_sql($pdo, 'p2l', $uid);
    $st = $pdo->prepare('SELECT p2l.id FROM deal_lines p2l JOIN deals d ON d.id = p2l.deal_id
                          WHERE p2l.deal_id = ? AND d.phase IN ' . pipe2_access_in(PIPE2_FRONT_READ_PHASES) . '
                            AND d.funnel_id IN (' . pipe2_v2_funnels_sql() . ')
                            AND p2l.status IN ' . pipe2_access_in(PIPE2_FRONT_READ) . " AND $link
                          ORDER BY p2l.is_main DESC, p2l.position, p2l.id");
    $st->execute(array_merge([$dealId], $args));
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Leio este negócio pela frente? Negócios, v2, em produção ou concluído e ligado a uma frente. Nunca dá edição. */
function pipe2_front_reader(PDO $pdo, array $deal): bool {
    $uid = pipe2_access_uid();
    if ($uid <= 0 || !can('negocios')) return false;
    if (!in_array((string)($deal['phase'] ?? ''), PIPE2_FRONT_READ_PHASES, true)) return false;
    if (!pipe2_deal_is_v2($pdo, $deal)) return false;
    return pipe2_front_lines_of($pdo, (int)($deal['id'] ?? 0), $uid) !== [];
}

/**
 * A condição SQL dos negócios que leio pela frente, para juntar em OR com reach_sql: [sql, args]. Sem sessão, sem
 * Negócios ou sem as colunas da v2, ['0=1', []]. $dealAlias é o alias da tabela deals na consulta de quem chama.
 */
function pipe2_front_reach_sql(PDO $pdo, string $dealAlias = 'd'): array {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $dealAlias) || in_array(strtolower($dealAlias), PIPE2_ACCESS_ALIASES, true)) {
        throw new InvalidArgumentException('pipe2_front_reach_sql: alias inválido «' . $dealAlias . '».');
    }
    $uid = pipe2_access_uid();
    if ($uid <= 0 || !can('negocios') || !pipe2_schema_ready($pdo)) return ['0=1', []];
    $a = $dealAlias;
    [$link, $args] = pipe2_access_link_sql($pdo, 'p2l', $uid);
    $sql = "($a.phase IN " . pipe2_access_in(PIPE2_FRONT_READ_PHASES)
         . " AND $a.funnel_id IN (" . pipe2_v2_funnels_sql() . ')'
         . " AND EXISTS (SELECT 1 FROM deal_lines p2l WHERE p2l.deal_id = $a.id AND p2l.status IN " . pipe2_access_in(PIPE2_FRONT_READ) . " AND $link))";
    return [$sql, $args];
}

/** O negócio e a frente relidos da base: [negócio, frente] se a frente for desse negócio; senão null. */
function pipe2_access_pair(PDO $pdo, array $deal, array $line): ?array {
    $dealId = (int)($deal['id'] ?? 0);
    $lineId = (int)($line['id'] ?? 0);
    if ($dealId <= 0 || $lineId <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM deals WHERE id = ?');
    $st->execute([$dealId]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) return null;
    $st = $pdo->prepare('SELECT * FROM deal_lines WHERE id = ? AND deal_id = ?');
    $st->execute([$lineId, $dealId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    return $l ? [$d, $l] : null;
}

/**
 * Sou o responsável desta frente? Só deal_lines.exec_owner_id (um executor interno não conta), numa frente em
 * PIPE2_FRONT_READ de um negócio v2 em produção. Não olha às permissões (quem chama junta can('negocios', PERM_EDIT)).
 */
function pipe2_front_owner(PDO $pdo, array $deal, array $line): bool {
    $uid = pipe2_access_uid();
    if ($uid <= 0 || !($p = pipe2_access_pair($pdo, $deal, $line))) return false;
    [$d, $l] = $p;
    if ((string)$d['phase'] !== 'production' || !in_array((string)$l['status'], PIPE2_FRONT_READ, true)) return false;
    if (!pipe2_deal_is_v2($pdo, $d)) return false;
    return (int)($l['exec_owner_id'] ?? 0) === $uid;
}

/** Posso agir nesta frente (mover, começar, unidades…)? Admin, quem edita o negócio ou o responsável com Negócios EDIT. */
function pipe2_can_front_act(PDO $pdo, array $deal, array $line): bool {
    if (pipe2_access_uid() <= 0 || !($p = pipe2_access_pair($pdo, $deal, $line))) return false;
    [$d, $l] = $p;
    if (!pipe2_deal_is_v2($pdo, $d) || (string)$l['status'] === 'merged') return false;
    return is_admin() || flow_can_edit($pdo, $d) || (pipe2_front_owner($pdo, $d, $l) && can('negocios', PERM_EDIT));
}

/** Posso mudar o interruptor do avanço automático desta frente? Admin ou o responsável da frente com Negócios EDIT. */
function pipe2_can_front_switch(PDO $pdo, array $deal, array $line): bool {
    if (pipe2_access_uid() <= 0 || !($p = pipe2_access_pair($pdo, $deal, $line))) return false;
    [$d, $l] = $p;
    if (!pipe2_deal_is_v2($pdo, $d) || (string)$l['status'] === 'merged') return false;
    return is_admin() || (pipe2_front_owner($pdo, $d, $l) && can('negocios', PERM_EDIT));
}

/** Posso pedir uma fatura deste negócio pela v2? Negócio v2 e flow_can_bill (o comercial, o Financeiro EDIT ou admin). */
function pipe2_can_request_invoice(PDO $pdo, array $deal): bool {
    if (pipe2_access_uid() <= 0 || (int)($deal['id'] ?? 0) <= 0) return false;
    $st = $pdo->prepare('SELECT * FROM deals WHERE id = ?');
    $st->execute([(int)$deal['id']]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    return $d && pipe2_deal_is_v2($pdo, $d) && flow_can_bill($pdo, $d);
}
