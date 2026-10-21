<?php
/**
 * topper. admin — alertas (§11.4, §11.6, §11.18)
 *
 * O que pede atenção a quem tem sessão: leads a aproximar do prazo ou em
 * atraso (§12.4), negócios sem movimento no Pipeline, tarefas a vencer ou atrasadas, valores por
 * receber em atraso e, para quem gere a fila, leads por atribuir.
 * Daqui saem o «O teu dia» do Dashboard, o centro de notificações e os
 * contadores do menu — sempre com as permissões verificadas aqui, no
 * servidor: só entra o que a pessoa pode ver.
 *
 * Ainda não há faturas na plataforma (§8.7): «por receber em atraso»
 * são os negócios por pagar, contados a partir da conclusão ou, sem
 * ela, do fim do mês do trabalho.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/leads.php';

/** Os limites dos alertas (dias). */
function alert_limits(PDO $pdo): array {
    return [
        'deal'    => max(1, (int)setting($pdo, 'deal_stale_days', 10)),
        'task'    => max(0, (int)setting($pdo, 'task_due_days', 2)),
        'invoice' => max(1, (int)setting($pdo, 'invoice_overdue_days', 30)),
    ];
}

/** Dias entre uma data (yyyy-mm-dd…) e hoje; negativo no futuro. */
function days_since(?string $date): int {
    if (!$date) return 0;
    $a = new DateTimeImmutable(substr($date, 0, 10));
    $b = new DateTimeImmutable(today_lisbon());
    return (int)$a->diff($b)->format('%r%a');
}

/** A pilha de tarefas da pessoa com sessão. */
function my_member_id(PDO $pdo): ?int {
    static $mid = false;
    if ($mid !== false) return $mid;
    $st = $pdo->prepare('SELECT team_member_id FROM users WHERE id = ?');
    $st->execute([(int)current_user()['id']]);
    $v = $st->fetchColumn();
    return $mid = ($v === false || $v === null) ? null : (int)$v;
}

/** Último movimento de um negócio no Pipeline. */
function deal_last_move(array $d): string {
    return (string)($d['last_touch'] ?: substr((string)($d['converted_at'] ?: $d['created_at']), 0, 10));
}

/** Para onde vai quem abre um negócio: a ficha completa (§11.7), ou o Pipeline sem acesso a Negócios. */
function deal_url(array $d): string {
    if (can('negocios')) return '/admin/negocio.php?id=' . (int)$d['id'];
    return '/admin/pipeline.php?' . http_build_query(['f' => $d['funnel_id'], 'deal' => $d['id']]) . '#negocio';
}

/**
 * Valores por receber: os negócios por pagar (em produção ou concluídos)
 * com a antiguidade em dias. Só para quem vê o Financeiro.
 */
function receivables(PDO $pdo): array {
    if (!can('financeiro')) return [];
    $rate = (float)setting($pdo, 'vat_rate', 23);
    $rows = $pdo->query(
        "SELECT d.id, d.code, d.name, d.phase, d.budget, d.has_vat, d.year, d.month, d.done_at, d.created_at, d.funnel_id,
                c.name AS contact_name
           FROM deals d LEFT JOIN contacts c ON c.id = d.contact_id
          WHERE d.code IS NOT NULL AND d.phase IN ('production','done') AND d.is_paid = 0 AND d.budget > 0"
    )->fetchAll();
    foreach ($rows as &$r) {
        if ($r['done_at']) {
            $ref = substr((string)$r['done_at'], 0, 10);
        } elseif ($r['year']) {
            $ref = (new DateTimeImmutable(sprintf('%04d-%02d-01', (int)$r['year'], (int)($r['month'] ?: 12))))->format('Y-m-t');
        } else {
            $ref = substr((string)$r['created_at'], 0, 10);
        }
        $r['ref']   = $ref;
        $r['age']   = max(0, days_since($ref));
        $r['net']   = round((float)$r['budget'], 2);
        $r['gross'] = (int)$r['has_vat'] ? round($r['net'] * (1 + $rate / 100), 2) : $r['net'];
    }
    unset($r);
    usort($rows, fn($a, $b) => $b['age'] <=> $a['age']);
    return $rows;
}

/** As faixas de antiguidade do Financeiro (§11.15). */
const AGING = ['0-30' => [0, 30], '31-60' => [31, 60], '61-90' => [61, 90], '90+' => [91, PHP_INT_MAX]];

function aging_bucket(int $age): string {
    foreach (AGING as $k => [$a, $b]) if ($age >= $a && $age <= $b) return $k;
    return '90+';
}

/**
 * Os alertas da pessoa com sessão, por tipo. Cada um:
 * key (única, muda quando o alerta volta a acontecer), kind, icon, title, sub, url, sev (warn|bad), date.
 */
function my_alerts(PDO $pdo): array {
    static $out = null;
    if ($out !== null) return $out;
    $out = ['task' => [], 'lead' => [], 'deal' => [], 'invoice' => [], 'queue' => []];
    $me = current_user();
    if ($me === null) return $out;
    $uid = (int)$me['id'];
    $lim = alert_limits($pdo);

    // Tarefas minhas, a vencer nos próximos dias ou atrasadas.
    if (can('tarefas') && ($mid = my_member_id($pdo)) !== null) {
        $until = (new DateTimeImmutable(today_lisbon()))->modify('+' . $lim['task'] . ' days')->format('Y-m-d');
        $st = $pdo->prepare("SELECT t.id, t.title, t.due_date, d.code FROM tasks t LEFT JOIN deals d ON d.id = t.deal_id
                              WHERE t.member_id = ? AND t.status <> 'done' AND t.due_date IS NOT NULL AND t.due_date <= ?
                              ORDER BY t.due_date, t.id");
        $st->execute([$mid, $until]);
        foreach ($st->fetchAll() as $t) {
            $late = $t['due_date'] < today_lisbon();
            $d = -days_since($t['due_date']);
            $out['task'][] = [
                'key' => 'task:' . $t['id'] . ':' . $t['due_date'], 'kind' => 'task', 'icon' => 'tarefas',
                'title' => $t['title'], 'sev' => $late ? 'bad' : 'warn', 'date' => $t['due_date'],
                'sub' => ($late ? 'Atrasada ' . ($d === -1 ? 'desde ontem' : 'há ' . -$d . ' dias') : ($d === 0 ? 'Vence hoje' : ($d === 1 ? 'Vence amanhã' : "Vence daqui a $d dias")))
                       . ($t['code'] ? ' · ' . $t['code'] : ''),
                'url' => '/admin/calendar.php?view=tarefas&edit=' . (int)$t['id'],
            ];
        }
    }

    // Leads minhas a aproximar do prazo ou em atraso (§12.4). A chave muda a cada
    // novo prazo e a cada nível, por isso cada passagem é uma notificação nova.
    if (can('leads')) {
        $st = $pdo->prepare("SELECT * FROM deals WHERE phase = 'lead' AND is_quarantined = 0 AND owner_id = ?");
        $st->execute([$uid]);
        $hours = lead_deadline_hours($pdo);
        foreach ($st->fetchAll() as $l) {
            $c = lead_clock($l, $hours);
            if (!$c || $c['level'] === 'ok') continue;
            $late = $c['level'] === 'late';
            $out['lead'][] = [
                'key' => 'ldl:' . $l['id'] . ':' . $c['start'] . ':' . $c['level'], 'kind' => 'lead', 'icon' => 'clock',
                'title' => lead_display_name($l), 'sev' => $late ? 'bad' : 'warn', 'date' => date('Y-m-d', $c['due']), 'due' => $c['due'],
                'sub' => ($late ? 'Em atraso há ' . lead_fmt_span(max(60, time() - $c['due'])) : 'A aproximar do prazo · faltam ' . $c['label']) . ' · ' . (LEAD_STATE[$l['lead_state']] ?? ''),
                'url' => '/admin/leads.php?open=' . (int)$l['id'],
            ];
        }
        usort($out['lead'], fn($a, $b) => $a['due'] <=> $b['due']);

        // Fila «Por atribuir» (admins e chefes de equipa).
        $full = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $full->execute([$uid]);
        if (($row = $full->fetch()) && sees_unassigned($row)) {
            $q = $pdo->query("SELECT COUNT(*) AS n, MAX(id) AS last FROM deals WHERE phase = 'lead' AND owner_id IS NULL AND is_quarantined = 0")->fetch();
            if ((int)$q['n'] > 0) {
                $out['queue'][] = [
                    'key' => 'queue:' . (int)$q['last'], 'kind' => 'queue', 'icon' => 'leads', 'sev' => 'warn', 'date' => today_lisbon(),
                    'title' => (int)$q['n'] === 1 ? '1 lead por atribuir' : (int)$q['n'] . ' leads por atribuir',
                    'sub' => 'Fila de entrada', 'url' => '/admin/leads.php?v=fila', 'n' => (int)$q['n'],
                ];
            }
        }
    }

    // Negócios meus sem movimento no Pipeline.
    if (can('pipeline')) {
        $st = $pdo->prepare("SELECT d.id, d.code, d.name, d.funnel_id, d.last_touch, d.converted_at, d.created_at, s.name AS stage
                               FROM deals d LEFT JOIN funnel_stages s ON s.id = d.stage_id
                              WHERE d.phase = 'pipeline' AND d.owner_id = ?");
        $st->execute([$uid]);
        foreach ($st->fetchAll() as $d) {
            $ref = deal_last_move($d);
            $n = days_since($ref);
            if ($n <= $lim['deal']) continue;
            $out['deal'][] = [
                'key' => 'deal:' . $d['id'] . ':' . $ref, 'kind' => 'deal', 'icon' => 'pipeline',
                'title' => $d['code'] . ' · ' . $d['name'], 'sev' => 'warn', 'date' => $ref,
                'sub' => 'Sem movimento há ' . $n . ' dias · ' . ($d['stage'] ?? '—'),
                'url' => deal_url($d),
            ];
        }
        usort($out['deal'], fn($a, $b) => strcmp($a['date'], $b['date']));
    }

    // Por receber em atraso (quem vê o Financeiro).
    foreach (receivables($pdo) as $r) {
        if ($r['age'] <= $lim['invoice']) continue;
        $out['invoice'][] = [
            'key' => 'inv:' . $r['id'] . ':' . aging_bucket($r['age']), 'kind' => 'invoice', 'icon' => 'financeiro',
            'title' => $r['code'] . ' · ' . $r['name'], 'sev' => $r['age'] > 60 ? 'bad' : 'warn', 'date' => $r['ref'],
            'sub' => 'Por receber há ' . $r['age'] . ' dias · ' . eur0($r['net']) . ' s/ IVA',
            'url' => '/admin/financeiro.php?tab=receber&doc=' . (int)$r['id'],
        ];
    }
    return $out;
}

/** O centro de notificações: todos os alertas, com o estado lido/por ler. */
function notifications(PDO $pdo): array {
    $all = array_merge(...array_values(my_alerts($pdo)));
    if (!$all) return [];
    $read = [];
    try {
        $st = $pdo->prepare('SELECT nkey FROM notification_reads WHERE user_id = ?');
        $st->execute([(int)current_user()['id']]);
        $read = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}
    foreach ($all as &$n) $n['unread'] = !isset($read[$n['key']]);
    unset($n);
    usort($all, fn($a, $b) => [$b['unread'], $b['sev'] === 'bad', $a['date']] <=> [$a['unread'], $a['sev'] === 'bad', $b['date']]);
    return array_slice($all, 0, 60);
}

function notifications_unread(PDO $pdo): int {
    return count(array_filter(notifications($pdo), fn($n) => $n['unread']));
}

/** Marca como lidas (as chaves dadas, ou todas as atuais). */
function notifications_mark(PDO $pdo, ?array $keys = null): void {
    $keys ??= array_column(notifications($pdo), 'key');
    $ins = $pdo->prepare((db_is_sqlite($pdo) ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . ' INTO notification_reads (user_id, nkey) VALUES (?, ?)');
    foreach ($keys as $k) {
        $k = (string)$k;
        if ($k === '' || strlen($k) > 120) continue;
        $ins->execute([(int)current_user()['id'], $k]);
    }
}

/** Contadores do menu: separador → [n, al (alerta), tip]. Só o que pede atenção. */
function nav_badges(PDO $pdo): array {
    $a = my_alerts($pdo);
    $out = [];
    // Leads em atraso: todas para superadmin e admins; as suas para os outros (§12.4).
    if (can('leads') && ($late = lead_late_count($pdo, current_user()))) {
        $out['leads'] = ['n' => $late, 'al' => true, 'tip' => $late === 1 ? '1 lead em atraso' : "$late leads em atraso"];
    }
    if ($a['deal']) $out['pipeline'] = ['n' => count($a['deal']), 'al' => true, 'tip' => count($a['deal']) . ' sem movimento'];
    if ($a['task']) {
        $late = count(array_filter($a['task'], fn($t) => $t['sev'] === 'bad'));
        $out['calendar'] = ['n' => count($a['task']), 'al' => $late > 0, 'tip' => $late ? "$late atrasadas" : count($a['task']) . ' a vencer'];
    }
    if ($a['invoice']) $out['financeiro'] = ['n' => count($a['invoice']), 'al' => true, 'tip' => count($a['invoice']) . ' por receber em atraso'];
    return $out;
}

/** Quantas leads em atraso conta o menu para $user (§12.4). */
function lead_late_count(PDO $pdo, array $user): int {
    static $memo = [];
    $k = (int)$user['id'];
    if (isset($memo[$k])) return $memo[$k];
    $hours = lead_deadline_hours($pdo);
    $n = 0;
    foreach (lead_counted($pdo, $user) as $l) {
        $c = lead_clock($l, $hours);
        if ($c && $c['level'] === 'late') $n++;
    }
    return $memo[$k] = $n;
}
