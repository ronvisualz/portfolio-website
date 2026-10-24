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
 * Valores por receber (só para quem vê o Financeiro): as faturas emitidas em aberto e os negócios de antes
 * das faturas ainda por pagar (fin_receivables, inc/fin.php). age = dias de atraso (0 = em dia).
 * Antes do passo 30: os negócios por pagar, com o atraso contado depois do prazo de «Por receber em atraso».
 */
function receivables(PDO $pdo): array {
    if (!can('financeiro')) return [];
    if (db_has_table($pdo, 'fin_docs')) {
        require_once __DIR__ . '/fin.php';
        return fin_receivables($pdo);
    }
    $rate = (float)setting($pdo, 'vat_rate', 23);
    $lim = (int)setting($pdo, 'invoice_overdue_days', 30);
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
        $r['age']   = max(0, days_since($ref) - $lim);
        $r['bucket'] = aging_bucket($r['age']);
        $r['net']   = round((float)$r['budget'], 2);
        $r['gross'] = (int)$r['has_vat'] ? round($r['net'] * (1 + $rate / 100), 2) : $r['net'];
        $r['url']   = '/admin/financeiro.php?tab=receber&deal=' . (int)$r['id'];
        $r['doc_id'] = 0; $r['number'] = 'Sem fatura registada'; $r['legacy'] = true;
    }
    unset($r);
    usort($rows, fn($a, $b) => $b['age'] <=> $a['age']);
    return $rows;
}

/** As faixas de antiguidade do Financeiro: em dia · 1–30 · 31–60 · 61–90 · +90 dias de atraso. */
const AGING = ['ok' => [PHP_INT_MIN, 0], '1-30' => [1, 30], '31-60' => [31, 60], '61-90' => [61, 90], '90+' => [91, PHP_INT_MAX]];

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
    $out = ['task' => [], 'lead' => [], 'deal' => [], 'invoice' => [], 'queue' => [], 'team' => [], 'report' => []];
    $me = current_user();
    if ($me === null) return $out;
    $uid = (int)$me['id'];
    $lim = alert_limits($pdo);

    // O resumo semanal (Relatórios, segunda às 9h): as 3 coisas a fazer, durante a semana.
    if (can('relatorios') && db_has_table($pdo, 'metrics_weekly')) {
        $st = $pdo->prepare('SELECT week, todo FROM metrics_weekly WHERE user_id = 0 AND week >= ? ORDER BY week DESC LIMIT 1');
        $st->execute([date('Y-m-d', strtotime(today_lisbon() . ' -6 days'))]);
        if ($w = $st->fetch()) {
            $todo = json_decode((string)$w['todo'], true) ?: [];
            $out['report'][] = ['key' => 'wk:' . $w['week'], 'kind' => 'report', 'icon' => 'relatorios', 'sev' => 'warn', 'date' => $w['week'],
                                'title' => 'Resumo da semana: 3 coisas a fazer', 'sub' => mb_strimwidth((string)($todo[0] ?? ''), 0, 110, '…'),
                                'url' => '/admin/relatorios.php?tab=atencao#resumo'];
        }
    }

    // Os follow-ups de relação que chegaram à data entram nas tarefas de quem as acompanha (§13.5).
    require_once __DIR__ . '/contacts.php';
    relation_tick($pdo);

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
                'url' => '/admin/calendar.php?edit=' . (int)$t['id'],
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

    // Pipeline (§19.4, §19.10): respostas do cliente por validar, gates de produção passados e
    // rondas de revisão esgotadas — para o responsável comercial do negócio.
    if (can('pipeline') && db_has_table($pdo, 'proposals')) {
        $st = $pdo->prepare("SELECT p.id, p.decision, p.choice, p.has_b, p.reason, p.answered_at, p.signer_name, d.id AS deal_id, d.code, d.name, d.funnel_id
                               FROM proposals p JOIN deals d ON d.id = p.deal_id
                              WHERE p.status = 'answered' AND d.owner_id = ? AND d.phase = 'pipeline'");
        $st->execute([$uid]);
        foreach ($st->fetchAll() as $p) {
            $what = $p['decision'] === 'accept' ? 'aceitou ' . ((int)$p['has_b'] ? 'a Opção ' . $p['choice'] : 'a proposta') : 'recusou';
            $out['deal'][] = ['key' => 'prop:' . $p['id'] . ':' . $p['answered_at'], 'kind' => 'deal', 'icon' => 'user', 'sev' => 'warn', 'date' => substr((string)$p['answered_at'], 0, 10),
                              'title' => 'Resposta do cliente: ' . $what . ' · ' . $p['code'], 'sub' => $p['name'] . ' · ' . $p['signer_name'] . ' · falta validar',
                              'url' => '/admin/pipeline.php?' . http_build_query(['f' => $p['funnel_id'], 'deal' => $p['deal_id']]) . '#proposta'];
        }
        $since = (new DateTimeImmutable(today_lisbon()))->modify('-7 days')->format('Y-m-d 00:00:00');
        $st = $pdo->prepare("SELECT h.id, h.event, h.note, h.created_at, d.id AS deal_id, d.code, d.name, fs.name AS from_name, ts.name AS to_name
                               FROM deal_history h JOIN deals d ON d.id = h.deal_id
                               LEFT JOIN funnel_stages fs ON fs.id = h.from_stage_id LEFT JOIN funnel_stages ts ON ts.id = h.to_stage_id
                              WHERE d.owner_id = ? AND h.created_at >= ? AND (h.user_id IS NULL OR h.user_id <> ?)
                                AND ((h.event = 'stage' AND fs.kind = 'production' AND h.note = '') OR h.event = 'rounds')");
        $st->execute([$uid, $since, $uid]);
        foreach ($st->fetchAll() as $h) {
            $gate = $h['event'] === 'stage';
            $out['deal'][] = ['key' => 'flow:' . $h['id'], 'kind' => 'deal', 'icon' => $gate ? 'stage' : 'alert', 'sev' => 'warn', 'date' => substr((string)$h['created_at'], 0, 10),
                              'title' => ($gate ? 'Gate de produção passado · ' : (str_contains((string)$h['note'], 'acima do incluído') ? 'Pedido de alteração · ' : 'Rondas incluídas esgotadas · ')) . $h['code'],
                              'sub' => $gate ? $h['from_name'] . ' → ' . $h['to_name'] . ' · ' . $h['name'] : $h['note'],
                              'url' => can('negocios') ? '/admin/negocio.php?id=' . (int)$h['deal_id'] : '/admin/pipeline.php?deal=' . (int)$h['deal_id']];
        }
    }

    // Portal do cliente (Centro Criativo, fase 2): comentários enviados e aprovações do cliente, para o
    // responsável comercial e o de execução do negócio (dos últimos 7 dias).
    if (db_has_table($pdo, 'cc_share_log') && (can('criativo') || can('negocios'))) {
        $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        $st = $pdo->prepare("SELECT l.id, l.action, l.at, l.client_name, l.detail, l.item_id, i.title, d.id AS deal_id, d.code FROM cc_share_log l
                               JOIN deals d ON d.id = l.deal_id LEFT JOIN cc_rv_items i ON i.id = l.item_id
                              WHERE l.action IN ('submit', 'approve') AND l.at >= ? AND (d.owner_id = ? OR d.exec_owner_id = ?) ORDER BY l.id DESC LIMIT 30");
        $st->execute([$since, $uid, $uid]);
        foreach ($st->fetchAll() as $l) {
            $ap = $l['action'] === 'approve';
            $out['deal'][] = ['key' => 'rvc:' . $l['id'], 'kind' => 'deal', 'icon' => $ap ? 'check' : 'bubble', 'sev' => 'warn', 'date' => substr((string)$l['at'], 0, 10),
                              'title' => ($ap ? 'O cliente aprovou' : 'Comentários do cliente') . ' · ' . $l['code'] . ($l['title'] ? ' · ' . $l['title'] : ''),
                              'sub' => trim(($l['client_name'] ?: 'Cliente') . ($l['detail'] !== '' ? ' · ' . $l['detail'] : '') . ' · no portal'),
                              'url' => $l['item_id'] && can('criativo') ? '/admin/criativo.php?rv=' . (int)$l['item_id'] : '/admin/negocio.php?id=' . (int)$l['deal_id'] . '#c-portal'];
        }
        // @menções nos comentários do Review
        if (can('criativo')) {
            $st = $pdo->prepare("SELECT c.id, c.item_id, c.body, c.created_at, i.title, u.name FROM cc_comments c JOIN cc_rv_items i ON i.id = c.item_id LEFT JOIN users u ON u.id = c.user_id
                                  WHERE c.item_id IS NOT NULL AND c.mentions LIKE ? AND c.deleted_at IS NULL AND c.user_id <> ? AND c.created_at >= ? ORDER BY c.id DESC LIMIT 20");
            $st->execute(['%,' . $uid . ',%', $uid, date('Y-m-d H:i:s', time() - 14 * 86400)]);
            foreach ($st->fetchAll() as $c) {
                $out['team'][] = ['key' => 'rvm:' . $c['id'], 'kind' => 'mention', 'icon' => 'criativo', 'sev' => 'warn', 'date' => substr((string)$c['created_at'], 0, 10),
                                  'title' => ($c['name'] ?: 'Alguém') . ' mencionou-o no Review «' . $c['title'] . '»', 'sub' => mb_strimwidth((string)$c['body'], 0, 90, '…'),
                                  'url' => '/admin/criativo.php?rv=' . (int)$c['item_id']];
            }
        }
    }

    // Por receber em atraso (quem vê o Financeiro): faturas vencidas e negócios de antes das faturas.
    foreach (receivables($pdo) as $r) {
        if ($r['age'] <= 0) continue;
        $out['invoice'][] = [
            'key' => 'inv:' . ($r['doc_id'] ? 'd' . $r['doc_id'] : $r['id']) . ':' . aging_bucket($r['age']), 'kind' => 'invoice', 'icon' => 'financeiro',
            'title' => $r['code'] . ' · ' . $r['name'], 'sev' => $r['age'] > 30 ? 'bad' : 'warn', 'date' => $r['ref'],
            'sub' => 'Em atraso há ' . $r['age'] . ($r['age'] === 1 ? ' dia' : ' dias') . ' · ' . eur0($r['gross']) . ' por receber' . ($r['legacy'] ? ' · sem fatura registada' : ''),
            'url' => $r['url'],
        ];
    }
    // Financeiro (§28): faturas prontas a enviar, por confirmar, a pagar vencidas, calendário fiscal, runway.
    if (db_has_table($pdo, 'fin_docs')) {
        require_once __DIR__ . '/fin.php';
        foreach (fin_alerts($pdo, $me) as $a) $out['invoice'][] = $a;
    }

    // Equipa (§17): mensagens por ler, pedidos de reunião e férias por aprovar (admins).
    // Durante o modo Foco ficam em silêncio.
    if (current_user() && db_has_table($pdo, 'chat_messages') && db_has_column($pdo, 'users', 'focus_until')) {
        require_once __DIR__ . '/team.php';
        $meRow = team_person($pdo, $uid);
        if ($meRow && !team_focus_until($meRow)) {
            $st = $pdo->prepare('SELECT m.from_id, u.name, COUNT(*) AS n, MAX(m.id) AS last, MAX(m.created_at) AS at FROM chat_messages m JOIN users u ON u.id = m.from_id
                                  WHERE m.to_id = ? AND m.read_at IS NULL GROUP BY m.from_id, u.name');
            $st->execute([$uid]);
            foreach ($st->fetchAll() as $c) {
                $out['team'][] = ['key' => 'chat:' . $c['from_id'] . ':' . $c['last'], 'kind' => 'chat', 'icon' => 'bubble', 'sev' => 'warn', 'date' => substr((string)$c['at'], 0, 10),
                                  'title' => 'Mensagem de ' . $c['name'], 'sub' => (int)$c['n'] === 1 ? '1 mensagem por ler' : (int)$c['n'] . ' mensagens por ler',
                                  'url' => '/admin/equipa.php?chat=' . (int)$c['from_id']];
            }
            foreach (team_meetings_pending($pdo, $meRow) as $m) {
                $out['team'][] = ['key' => 'meet:' . $m['id'], 'kind' => 'meeting', 'icon' => 'meeting', 'sev' => 'warn', 'date' => substr((string)$m['starts_at'], 0, 10),
                                  'title' => 'Pedido de reunião: ' . $m['subject'], 'sub' => 'De ' . $m['requester_name'] . ' · ' . team_when((string)$m['starts_at'], (int)$m['duration_min']),
                                  'url' => '/admin/equipa.php?chat=' . (int)$m['requester_id']];
            }
            // «Pedir ajuda» no quadro das Tarefas (§18.4): avisa os operadores.
            if ($meRow['role'] !== 'superadmin' && db_has_column($pdo, 'tasks', 'help_by') && can('tarefas')) {
                $st = $pdo->prepare('SELECT t.id, t.title, t.due_date, u.name FROM tasks t JOIN users u ON u.id = t.help_by
                                      WHERE t.member_id IS NULL AND t.help_by IS NOT NULL AND t.help_by <> ? AND t.status <> \'done\' AND t.private = 0');
                $st->execute([$uid]);
                foreach ($st->fetchAll() as $t) {
                    $out['team'][] = ['key' => 'help:' . $t['id'], 'kind' => 'help', 'icon' => 'hand', 'sev' => 'warn', 'date' => $t['due_date'] ?: today_lisbon(),
                                      'title' => '🙋 Pedido de ajuda: ' . $t['title'], 'sub' => 'De ' . $t['name'] . ' · está em «Por atribuir»',
                                      'url' => '/admin/calendar.php?t=quadro'];
                }
            }
            // @menções no Centro Criativo (§23), dos últimos 14 dias.
            if (db_has_table($pdo, 'cc_comments') && can('criativo')) {
                $st = $pdo->prepare("SELECT c.id, c.board_id, c.card_id, c.body, c.created_at, b.title, u.name FROM cc_comments c
                                       JOIN cc_boards b ON b.id = c.board_id LEFT JOIN users u ON u.id = c.user_id
                                      WHERE c.mentions LIKE ? AND c.deleted_at IS NULL AND c.user_id <> ? AND c.created_at >= ? ORDER BY c.id DESC LIMIT 20");
                $st->execute(['%,' . $uid . ',%', $uid, date('Y-m-d H:i:s', time() - 14 * 86400)]);
                foreach ($st->fetchAll() as $c) {
                    $out['team'][] = ['key' => 'ccm:' . $c['id'], 'kind' => 'mention', 'icon' => 'criativo', 'sev' => 'warn', 'date' => substr((string)$c['created_at'], 0, 10),
                                      'title' => ($c['name'] ?: 'Alguém') . ' mencionou-o em «' . $c['title'] . '»', 'sub' => mb_strimwidth((string)$c['body'], 0, 90, '…'),
                                      'url' => '/admin/criativo.php?b=' . (int)$c['board_id'] . ($c['card_id'] ? '&card=' . (int)$c['card_id'] : '')];
                }
            }
            if (role_is_admin((string)$meRow['role'])) {
                // o serviço de tempo real dos quadros perto do limite do plano gratuito (§26)
                if (db_has_table($pdo, 'rt_usage')) {
                    require_once __DIR__ . '/cc_rt.php';
                    $ru = cc_rt_usage_today($pdo);
                    if ($ru['pct'] >= 80) {
                        $full = $ru['pct'] >= 100;
                        $out['team'][] = ['key' => 'rtu:' . $ru['day'] . ':' . ($full ? 100 : 80), 'kind' => 'help', 'icon' => 'alert', 'sev' => $full ? 'bad' : 'warn', 'date' => today_lisbon(),
                                          'title' => $full ? 'Tempo real dos quadros em pausa até às 00:00 UTC' : 'Tempo real dos quadros a ' . $ru['pct'] . '% do limite de hoje',
                                          'sub' => $full ? 'Os quadros continuam e sincronizam depois. Passar ao plano pago é uma decisão manual.' : 'Plano gratuito da Cloudflare: repõe às 00:00 UTC.',
                                          'url' => '/admin/definicoes.php?t=integracoes#set-int_rt'];
                    }
                }
                foreach ($pdo->query("SELECT a.id, a.kind, a.start_date, a.end_date, u.name FROM absences a JOIN users u ON u.id = a.user_id WHERE a.status = 'pending' ORDER BY a.start_date")->fetchAll() as $a) {
                    $out['team'][] = ['key' => 'abs:' . $a['id'], 'kind' => 'absence', 'icon' => 'plane', 'sev' => 'warn', 'date' => $a['start_date'],
                                      'title' => (ABSENCE_KINDS[$a['kind']] ?? 'Ausência') . ' por aprovar: ' . $a['name'],
                                      'sub' => date('d/m', strtotime($a['start_date'])) . ' a ' . date('d/m', strtotime($a['end_date'])),
                                      'url' => '/admin/equipa.php?v=ausencias'];
                }
            }
        }
    }
    return $out;
}

/** O centro de notificações: todos os alertas, com o estado lido/por ler. */
function notifications(PDO $pdo): array {
    // O Perfil (§20.1): cada um escolhe as categorias que quer na plataforma.
    $uid = (int)(current_user()['id'] ?? 0);
    $groups = array_filter(my_alerts($pdo), fn($k) => !isset(NOTIF_CATS[$k]) || user_wants($pdo, $uid, $k, 'app'), ARRAY_FILTER_USE_KEY);
    $all = $groups ? array_merge(...array_values($groups)) : [];
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
    $abs = count(array_filter($a['team'], fn($t) => $t['kind'] === 'absence'));
    if ($abs) $out['equipa'] = ['n' => $abs, 'al' => false, 'tip' => $abs === 1 ? '1 pedido de férias por aprovar' : "$abs pedidos de férias por aprovar"];
    if ($a['invoice']) $out['financeiro'] = ['n' => count($a['invoice']), 'al' => true, 'tip' => count($a['invoice']) . ' por receber em atraso'];
    // Relatórios › Atenção (§29.3): o mesmo número do separador
    if (can('relatorios')) {
        try {
            require_once __DIR__ . '/metrics_view.php';
            if ($n = mx_attention_count($pdo)) $out['relatorios'] = ['n' => $n, 'al' => true, 'tip' => $n === 1 ? '1 coisa pede atenção' : "$n coisas pedem atenção"];
        } catch (Throwable $e) { error_log('nav relatorios: ' . $e->getMessage()); }
    }
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
