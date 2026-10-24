<?php
/**
 * topper. admin — Google Calendar e Meet a partir do browser (§31.4), sempre pela camada inc/gcal.php
 *
 *   POST ?a=meet    «Criar Google Meet» numa tarefa com hora (task, guests: emails externos separados por vírgulas)
 *   GET  ?a=event   o Meet de uma tarefa: link, organizador e respostas dos convidados (lidas agora à Google)
 *   GET  ?a=slots   ao marcar: quem está ocupado nessa hora (plataforma + Google de quem tem o calendário ligado)
 *                   e os 3 próximos horários livres em comum (mid, uids, dur, date, time)
 * As mesmas regras de acesso das tarefas: privadas dos outros não existem; editar pede permissão de edição.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/tasks_view.php';
require_once __DIR__ . '/inc/team.php';
require_once __DIR__ . '/inc/gcal.php';
$user = require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function gj(int $code, array $b): void { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
if (!can('tarefas')) gj(403, ['error' => 'Sem permissão.']);
if (!gcal_ready($pdo)) gj(503, ['error' => 'A base de dados ainda não está no passo 35.']);
$a = (string)($_GET['a'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) gj(403, ['error' => 'Pedido inválido. Recarregue a página e tente outra vez.']);
}

$me = me_row($pdo);
$members = team_members($pdo);
$visible = task_visible_members($pdo, $members);
$myMember = member_for_user($pdo, (int)$me['id']);
/** Uma tarefa que esta pessoa pode ver (as privadas dos outros não existem). */
$reach = function (int $id) use ($pdo, $visible, $myMember): ?array {
    $t = task_by_id($pdo, $id);
    if (!$t || task_hidden_for($t, $myMember)) return null;
    if (!task_member_visible($t['member_id'] !== null ? (int)$t['member_id'] : null, $visible) && (int)$t['member_id'] !== (int)$myMember) {
        $parts = array_map('intval', explode(',', (string)($t['participants'] ?? '')));
        if (!in_array((int)$GLOBALS['user']['id'], $parts, true)) return null;
    }
    return $t;
};

try {
    if ($a === 'meet' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can('tarefas', PERM_EDIT)) gj(403, ['error' => 'Sem permissão para editar tarefas.']);
        $t = $reach((int)($_POST['task'] ?? 0));
        if (!$t) gj(404, ['error' => 'Essa tarefa já não existe.']);
        $guests = preg_split('/[\s,;]+/', (string)($_POST['guests'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $bad = array_filter($guests, fn($e) => !filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($bad) gj(422, ['error' => 'Email inválido: ' . implode(', ', array_slice($bad, 0, 3))]);
        if (count($guests) > 30) gj(422, ['error' => 'No máximo 30 convidados externos.']);
        $l = gcal_meet_for_task($pdo, (int)$t['id'], $guests, (int)$me['id']);
        gj(200, ['ok' => true, 'url' => (string)$l['meet_url'], 'html' => (string)$l['html_link'], 'organizer' => $l['account'] === 'topper' ? 'conta da topper.' : 'o seu Google Calendar',
                 'msg' => 'Google Meet criado. Os convidados recebem o convite do Google.']);
    }

    if ($a === 'event') {
        $t = $reach((int)($_GET['task'] ?? 0));
        if (!$t) gj(404, ['error' => 'Essa tarefa já não existe.']);
        $l = gcal_link($pdo, 'task', (int)$t['id'], 'meet');
        if (!$l) gj(200, ['ok' => true, 'url' => (string)($t['call_link'] ?? ''), 'linked' => false, 'can' => gcal_can_meet($pdo, (int)$me['id'])]);
        $resp = [];
        try { $resp = gcal_responses($pdo, $l); } catch (Throwable $e) { $resp = json_decode((string)$l['responses'], true) ?: []; }
        gj(200, ['ok' => true, 'linked' => true, 'url' => (string)$l['meet_url'], 'html' => (string)$l['html_link'],
                 'organizer' => $l['account'] === 'topper' ? 'conta da topper.' : 'Google Calendar de ' . (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)substr((string)$l['account'], 5))->fetchColumn(),
                 'responses' => array_map(fn($r) => ['email' => $r['email'], 'status' => GCAL_RESP[$r['status']] ?? $r['status'], 'k' => $r['status'], 'organizer' => !empty($r['organizer'])], $resp)]);
    }

    if ($a === 'slots') {
        $ids = array_filter(array_map('intval', explode(',', (string)($_GET['uids'] ?? ''))));
        $mid = (int)($_GET['mid'] ?? 0);
        if ($mid && isset($members[$mid]) && $members[$mid]['user_id'] !== null) $ids[] = (int)$members[$mid]['user_id'];
        $ids = array_values(array_unique($ids));
        if (!$ids || count($ids) > 12) gj(200, ['ok' => true, 'slots' => [], 'busy' => []]);
        $st = $pdo->prepare('SELECT * FROM users WHERE is_active = 1 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $people = $st->fetchAll();
        $dur = max(15, min(480, (int)($_GET['dur'] ?? 60) ?: 60));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? (string)$_GET['date'] : '';
        $time = preg_match('/^\d{2}:\d{2}$/', (string)($_GET['time'] ?? '')) ? (string)$_GET['time'] : '';
        team_busy_google(true);   // ao marcar, conta o Google de quem tem o calendário ligado
        // quem está ocupado na hora escolhida
        $busy = [];
        if ($date !== '' && $time !== '') {
            $a0 = team_ts($date . ' ' . $time); $b0 = $a0 + $dur * 60;
            foreach ($people as $u) {
                foreach (team_busy($pdo, $u, $date, (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')) as [$x, $y, $k]) {
                    if ($x < $b0 && $y > $a0) { $busy[] = first_name((string)$u['name']) . ($k === 'gbusy' ? ' (Google)' : ($k === 'absence' ? ' (ausente)' : '')); break; }
                }
            }
        }
        // os 3 próximos horários livres em comum (desta semana e da seguinte, a partir do dia escolhido ou de hoje)
        $base = new DateTimeImmutable(($date !== '' && $date >= date('Y-m-d') ? $date : 'today'), team_tz());
        $slots = [];
        foreach ([0, 1] as $w) {
            $monday = $base->modify('monday this week')->modify('+' . $w . ' week')->format('Y-m-d');
            foreach (team_find_time($pdo, $people, $monday, $dur, 40) as $s) {
                if (team_ts($s['date'] . ' ' . $s['time']) < max(time() + 900, $base->getTimestamp())) continue;
                if ($s['date'] === $date && $s['time'] === $time) continue;
                $slots[] = $s + ['label' => TEAM_DAYS[(int)date('N', strtotime($s['date']))] . ' ' . (int)date('j', strtotime($s['date'])) . ' · ' . $s['time']];
                if (count($slots) >= 3) break 2;
            }
        }
        $withG = 0;
        foreach ($people as $u) if (gcal_conn($pdo, (int)$u['id'])) $withG++;
        gj(200, ['ok' => true, 'busy' => $busy, 'slots' => $slots, 'google' => $withG, 'people' => count($people)]);
    }
    gj(400, ['error' => 'Ação desconhecida.']);
} catch (RuntimeException $e) {
    gj(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('gcal_api ' . $a . ': ' . $e->getMessage());
    gj(500, ['error' => 'Não foi possível falar com o Google agora. Tente outra vez.']);
}
