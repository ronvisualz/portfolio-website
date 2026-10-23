<?php
/**
 * topper. admin — pedidos da aba Equipa (§17) que correm sem recarregar
 *
 *   GET  a=ping[&ids=1,2]    sinal da sessão (~30 s): só a hora; devolve não lidas, Foco, pedidos de reunião
 *                            e (com ids) quem está online. NÃO prolonga a sessão: o «sair por inatividade» continua a valer.
 *   GET  a=chat_list         conversas e pessoas (com presença)
 *   GET  a=chat_thread&with=&after=   mensagens com essa pessoa (marca as recebidas como lidas)
 *   POST a=chat_send         to, body
 *   GET  a=availability&id=&w=aaaa-mm-dd   a semana de uma pessoa (outros veem só «Ocupado»; admins veem o título)
 *   GET  a=find_time&ids=&w=&d=            espaços livres comuns
 *   POST a=meeting_request   to | to[], start («aaaa-mm-dd hh:mm»), duration, subject, deal_id, platform, link
 *   POST a=meeting_accept    id, link   ·   meeting_decline id, note   ·   meeting_propose id, start
 *   POST a=focus             minutes (0 = até ao fim do dia) | off
 *   POST a=absence_decide    id, decision (approved | rejected) — admins, com «Anular»
 *
 * Os POST pedem o token CSRF. Custo/hora e horas/mês nunca saem daqui.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/team.php';
require_once __DIR__ . '/inc/undo.php';

function tout(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

$a = (string)($_REQUEST['a'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

// Os pedidos automáticos (sinal, chat aberto) não contam como atividade: verifica-se a
// sessão sem a prolongar, para o «sair por inatividade» continuar a valer.
$passive = !$post && in_array($a, ['ping', 'chat_list', 'chat_thread'], true);
if (current_user() === null) tout(401, ['ok' => false, 'error' => 'A sessão terminou. Entre outra vez.']);
if ($passive) {
    $now = time();
    [$idleMax, $hardMax] = session_limits();
    if ($now - (int)($_SESSION['last_seen'] ?? 0) > $idleMax || $now - (int)($_SESSION['started'] ?? 0) > $hardMax) {
        tout(401, ['ok' => false, 'error' => 'A sessão terminou. Entre outra vez.']);
    }
    $st = $pdo->prepare('SELECT is_active, deactivated_at FROM users WHERE id = ?');
    $st->execute([(int)current_user()['id']]);
    $row = $st->fetch();
    if (!$row || (int)$row['is_active'] !== 1 || $row['deactivated_at']) tout(401, ['ok' => false, 'error' => 'A sessão terminou.']);
    $user = current_user();
} else {
    $user = require_login();
}
if ($post) {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) tout(400, ['ok' => false, 'error' => 'Pedido inválido. Recarregue a página e tente outra vez.']);
}
if (!db_has_table($pdo, 'chat_messages')) tout(503, ['ok' => false, 'error' => 'A Equipa ainda não está pronta.']);

$me = (int)$user['id'];
$meRow = team_person($pdo, $me);
$admin = team_is_admin($pdo);

/** Uma pessoa ativa (sem custos). */
function person_req(PDO $pdo, int $id): array {
    $u = team_person($pdo, $id);
    if (!$u || !(int)$u['is_active'] || $u['deactivated_at']) throw new RuntimeException('Pessoa não encontrada.');
    return $u;
}

/** Uma mensagem para o cliente: texto, cartões de registos e o cartão de reunião. */
function msg_out(PDO $pdo, array $m, int $me): array {
    $out = ['id' => (int)$m['id'], 'mine' => (int)$m['from_id'] === $me, 'html' => team_chat_html((string)$m['body']),
            'at' => lisbon_fmt(strtotime((string)$m['created_at']), 'd/m H:i'), 'cards' => team_chat_cards($pdo, (string)$m['body'])];
    if ($m['meeting_id'] && ($mt = team_meeting($pdo, (int)$m['meeting_id']))) {
        $out['meeting'] = ['id' => (int)$mt['id'], 'status' => $mt['status'], 'subject' => $mt['subject'], 'when' => team_when((string)$mt['starts_at'], (int)$mt['duration_min']),
                           'platform' => MEETING_PLATFORMS[$mt['platform']] ?? '', 'deal' => $mt['deal_code'] ? $mt['deal_code'] . ' · ' . $mt['deal_name'] : '',
                           'link' => $mt['status'] === 'accepted' ? (string)$mt['link'] : '', 'can_answer' => (int)$mt['invitee_id'] === $me && $mt['status'] === 'pending',
                           'can_cancel' => (int)$mt['requester_id'] === $me && $mt['status'] === 'pending', 'start' => substr((string)$mt['starts_at'], 0, 16),
                           'needs_link' => $mt['link'] === '' && $mt['platform'] !== 'local' && !(($mt['platform'] === 'meet' && team_meet_config()['google']) || ($mt['platform'] === 'discord' && team_meet_settings()['discord']))];
    }
    return $out;
}

try {
    switch ($a) {

    case 'ping':
        team_ping($pdo, $me);
        $out = ['ok' => true, 'unread' => team_chat_unread($pdo, $meRow), 'meetings' => count(team_meetings_pending($pdo, $meRow)),
                'focus' => ($f = team_focus_until($meRow)) ? lisbon_fmt($f, 'H:i') : ''];
        // Quem está online (para os pontos dos cartões). Só sim/não e «visto há».
        $ids = array_slice(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? '')))), 0, 200);
        if ($ids) {
            $st = $pdo->query('SELECT id, last_seen_at FROM users WHERE id IN (' . implode(',', $ids) . ')');
            foreach ($st->fetchAll() as $r) { $p = team_presence($r + ['last_seen_at' => $r['last_seen_at']]); $out['presence'][(int)$r['id']] = ['on' => $p['online'], 'label' => $p['label']]; }
        }
        // A mensagem mais recente por ler (para o aviso «Nova mensagem de …»).
        if ($out['unread']) {
            $st = $pdo->prepare('SELECT m.id, u.name, m.from_id FROM chat_messages m JOIN users u ON u.id = m.from_id WHERE m.to_id = ? AND m.read_at IS NULL ORDER BY m.id DESC LIMIT 1');
            $st->execute([$me]);
            if ($l = $st->fetch()) $out['last'] = ['id' => (int)$l['id'], 'from' => (int)$l['from_id'], 'name' => $l['name']];
        }
        tout(200, $out);

    case 'chat_list':
        $people = team_people($pdo);
        $convs = team_chat_list($pdo, $me);
        $list = [];
        foreach ($people as $id => $u) {
            if ($id === $me) continue;
            $c = $convs[$id] ?? null;
            $p = team_presence($u);
            $list[] = ['id' => $id, 'name' => $u['name'], 'role' => team_positions_label($u), 'avatar' => team_avatar($u, 'sm'), 'online' => $p['online'], 'presence' => $p['label'],
                       'last' => $c ? mb_substr((string)$c['last']['body'], 0, 80) : '', 'last_id' => $c ? (int)$c['last']['id'] : 0, 'unread' => $c['unread'] ?? 0,
                       'state' => team_agenda_state($pdo, $u)[1]];
        }
        usort($list, fn($x, $y) => [$y['last_id'] > 0, $y['last_id'], $x['name']] <=> [$x['last_id'] > 0, $x['last_id'], $y['name']]);
        tout(200, ['ok' => true, 'people' => $list, 'focus' => ($f = team_focus_until($meRow)) ? lisbon_fmt($f, 'H:i') : '']);

    case 'chat_thread':
        $other = person_req($pdo, (int)($_GET['with'] ?? 0));
        $rows = team_chat_thread($pdo, $me, (int)$other['id'], (int)($_GET['after'] ?? 0));
        $p = team_presence($other);
        tout(200, ['ok' => true, 'with' => ['id' => (int)$other['id'], 'name' => $other['name'], 'online' => $p['online'], 'presence' => $p['label'],
                   'state' => team_agenda_state($pdo, $other)[1], 'avatar' => team_avatar($other, 'sm'), 'phone' => (string)$other['phone'], 'email' => (string)$other['email']],
                   'messages' => array_map(fn($m) => msg_out($pdo, $m, $me), $rows)]);

    case 'chat_send':
        if (!$post) break;
        $to = person_req($pdo, (int)($_POST['to'] ?? 0));
        $id = team_chat_send($pdo, $me, (int)$to['id'], (string)($_POST['body'] ?? ''));
        $st = $pdo->prepare('SELECT * FROM chat_messages WHERE id = ?');
        $st->execute([$id]);
        tout(200, ['ok' => true, 'message' => msg_out($pdo, $st->fetch(), $me), 'focus' => ($f = team_focus_until($to)) ? 'Está em foco até ' . lisbon_fmt($f, 'H:i') . ': vê a mensagem depois.' : '']);

    case 'availability':
        $u = person_req($pdo, (int)($_GET['id'] ?? 0));
        $w = (string)($_GET['w'] ?? '');
        $monday = preg_match('/^\d{4}-\d{2}-\d{2}$/', $w) ? (new DateTimeImmutable($w))->modify('monday this week') : (new DateTimeImmutable('now', team_tz()))->modify('monday this week');
        $week = team_week($pdo, $u, $monday->format('Y-m-d'), $admin);
        tout(200, ['ok' => true, 'person' => ['id' => (int)$u['id'], 'name' => $u['name'], 'hours' => team_work_label($u), 'state' => team_agenda_state($pdo, $u)[1]],
                   'monday' => $monday->format('Y-m-d'), 'prev' => $monday->modify('-7 days')->format('Y-m-d'), 'next' => $monday->modify('+7 days')->format('Y-m-d'),
                   'label' => 'Semana de ' . $monday->format('d/m') . ' a ' . $monday->modify('+6 days')->format('d/m'), 'me' => (int)$u['id'] === $me,
                   'rows' => $week['rows'], 'days' => array_map(fn($d) => $d, $week['days']), 'titles' => $admin]);

    case 'find_time':
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))))));
        if (!in_array($me, $ids, true)) $ids[] = $me;
        if (count($ids) < 2) throw new RuntimeException('Escolha pelo menos uma pessoa.');
        $people = array_map(fn($i) => person_req($pdo, $i), array_slice($ids, 0, 12));
        $d = (int)($_GET['d'] ?? 30);
        if (!isset(MEETING_DURATIONS[$d])) $d = 30;
        $w = (string)($_GET['w'] ?? '');
        $monday = preg_match('/^\d{4}-\d{2}-\d{2}$/', $w) ? (new DateTimeImmutable($w))->modify('monday this week') : (new DateTimeImmutable('now', team_tz()))->modify('monday this week');
        $slots = team_find_time($pdo, $people, $monday->format('Y-m-d'), $d);
        tout(200, ['ok' => true, 'slots' => array_map(fn($s) => $s + ['label' => TEAM_DAYS[(int)date('N', strtotime($s['date']))] . ' ' . date('d/m', strtotime($s['date'])) . ' · ' . $s['time']], $slots),
                   'monday' => $monday->format('Y-m-d'), 'names' => implode(', ', array_map(fn($u) => explode(' ', $u['name'])[0], array_filter($people, fn($u) => (int)$u['id'] !== $me)))]);

    case 'meeting_request':
        if (!$post) break;
        $tos = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['to'] ?? [])))));
        if (!$tos) throw new RuntimeException('Escolha com quem.');
        $start = trim((string)($_POST['start'] ?? ''));
        if ($start === '' && isset($_POST['day'])) $start = trim((string)$_POST['day']) . ' ' . trim((string)($_POST['time'] ?? ''));
        $group = count($tos) > 1 ? bin2hex(random_bytes(6)) : '';
        $held = [];
        $ids = [];
        foreach ($tos as $to) {
            $u = person_req($pdo, $to);
            $ids[] = team_meeting_request($pdo, $me, $to, $start, (int)($_POST['duration'] ?? 30), (string)($_POST['subject'] ?? ''),
                                          (int)($_POST['deal_id'] ?? 0) ?: null, (string)($_POST['platform'] ?? 'meet'), (string)($_POST['link'] ?? ''), $group);
            if ($f = team_focus_until($u)) $held[] = explode(' ', $u['name'])[0] . ' está em foco até ' . lisbon_fmt($f, 'H:i');
        }
        tout(200, ['ok' => true, 'ids' => $ids, 'with' => $tos[0],
                   'msg' => 'Pedido enviado no chat' . (count($tos) > 1 ? ' a ' . count($tos) . ' pessoas' : '') . '.' . ($held ? ' ' . implode('; ', $held) . ': fica em espera até lá.' : '')]);

    case 'meeting_accept':
    case 'meeting_decline':
    case 'meeting_propose':
        if (!$post) break;
        $m = team_meeting($pdo, (int)($_POST['id'] ?? 0));
        if (!$m || ((int)$m['invitee_id'] !== $me && (int)$m['requester_id'] !== $me)) throw new RuntimeException('Pedido não encontrado.');
        if ($a === 'meeting_accept') {
            [$msg] = team_meeting_accept($pdo, $m, $me, (string)($_POST['link'] ?? ''));
        } elseif ($a === 'meeting_decline') {
            $msg = team_meeting_decline($pdo, $m, $me, (string)($_POST['note'] ?? ''));
        } else {
            team_meeting_propose($pdo, $m, $me, trim((string)($_POST['start'] ?? '')));
            $msg = 'Outra hora proposta no chat.';
        }
        tout(200, ['ok' => true, 'msg' => $msg]);

    case 'deals':
        // Negócios que esta pessoa vê (para ligar a reunião a um negócio, opcional).
        require_once __DIR__ . '/inc/reach.php';
        [$sql, $args] = scope_sql($pdo, 'negocios', 'd.owner_id');
        $st = $pdo->prepare("SELECT d.id, d.code, d.name FROM deals d WHERE d.code IS NOT NULL AND d.phase IN ('pipeline','production') AND ($sql) ORDER BY d.code DESC LIMIT 200");
        $st->execute($args);
        tout(200, ['ok' => true, 'deals' => array_map(fn($d) => ['id' => (int)$d['id'], 'label' => $d['code'] . ' · ' . $d['name']], can('negocios') || can('pipeline') ? $st->fetchAll() : [])]);

    case 'focus':
        if (!$post) break;
        if (isset($_POST['off'])) { team_focus_set($pdo, $meRow, null); tout(200, ['ok' => true, 'focus' => '', 'msg' => 'Modo Foco desligado.']); }
        $min = (int)($_POST['minutes'] ?? 60);
        if (!isset(TEAM_FOCUS_OPTIONS[$min])) $min = 60;
        $until = team_focus_set($pdo, $meRow, $min);
        tout(200, ['ok' => true, 'focus' => $until, 'msg' => 'Em foco até ' . $until . ': chat e notificações em silêncio; os pedidos de reunião ficam em espera.']);

    case 'org_move':
        // Organograma (§17.4): arrastar muda «responde a». Admins; com «Anular».
        if (!$post) break;
        if (!$admin) tout(403, ['ok' => false, 'error' => 'Só superadmin e admins editam o organograma.']);
        $u = person_req($pdo, (int)($_POST['id'] ?? 0));
        $to = (int)($_POST['reports_to'] ?? 0) ?: null;
        if ($to !== null) {
            person_req($pdo, $to);
            for ($c = $to, $n = 0; $c && $n < 50; $n++) {
                if ($c === (int)$u['id']) throw new RuntimeException('Assim ficava um círculo: ' . explode(' ', $u['name'])[0] . ' responderia a alguém que lhe responde.');
                $st = $pdo->prepare('SELECT reports_to FROM users WHERE id = ?');
                $st->execute([$c]);
                $c = (int)$st->fetchColumn();
            }
        }
        $pdo->prepare('UPDATE users SET reports_to = ? WHERE id = ?')->execute([$to, $u['id']]);
        $toName = $to ? team_person($pdo, $to)['name'] : '';
        team_log($pdo, (int)$u['id'], 'edit', 'Responde a: ' . ($toName ?: 'ninguém (topo)'));
        tout(200, ['ok' => true, 'msg' => explode(' ', $u['name'])[0] . ($to ? ' responde agora a ' . $toName . '.' : ' passou para o topo.'),
                   'undo' => undo_push('team', ['what' => 'user', 'id' => (int)$u['id'], 'row' => ['reports_to' => $u['reports_to']], 'msg' => 'Organograma reposto.'])]);

    case 'absence_decide':
        if (!$post) break;
        if (!$admin) tout(403, ['ok' => false, 'error' => 'Só superadmin e admins aprovam férias e ausências.']);
        $ab = team_absence($pdo, (int)($_POST['id'] ?? 0));
        if (!$ab) throw new RuntimeException('Pedido não encontrado.');
        $msg = team_absence_decide($pdo, $ab, (string)($_POST['decision'] ?? ''), $me);
        tout(200, ['ok' => true, 'msg' => $msg, 'undo' => undo_push('team', ['what' => 'absence', 'id' => (int)$ab['id'], 'status' => 'pending', 'decided_by' => null, 'decided_at' => null])]);
    }
    tout(400, ['ok' => false, 'error' => 'Pedido desconhecido.']);
} catch (PDOException $e) {
    error_log('team_api ' . $a . ': ' . $e->getMessage());
    tout(500, ['ok' => false, 'error' => 'Não foi possível concluir. Tente outra vez.']);
} catch (RuntimeException $e) {
    tout(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('team_api ' . $a . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    tout(500, ['ok' => false, 'error' => 'Não foi possível concluir. Tente outra vez.']);
}
