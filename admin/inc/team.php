<?php
/**
 * topper. admin — aba Equipa (§17): pessoas, estrutura, presença, agenda,
 * reuniões, chat 1:1, férias e ausências, convites, entrada e saída, carga.
 *
 * Princípios (§17.10): sem registo de horas e sem vigiar atividade. A presença
 * guarda só a hora do último sinal da sessão (online = sinal nos últimos
 * ~75 s) — nunca a página, a ação ou o que a pessoa está a fazer.
 * Custo/hora e horas/mês só saem daqui para admins (o papel lê-se da base).
 * Horas: as da agenda (tarefas, reuniões, ausências) são de Lisboa, como nas
 * Tarefas; os carimbos (último sinal, mensagens) usam now_php().
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/leads.php';          // now_php, lisbon_fmt, member_for_user, lead_setting
require_once __DIR__ . '/interactions.php';   // timeline_html, HISTORY_TYPES

const TEAM_WORK_DEFAULT = [1 => ['09:00', '18:00'], 2 => ['09:00', '18:00'], 3 => ['09:00', '18:00'], 4 => ['09:00', '18:00'], 5 => ['09:00', '18:00']];
const TEAM_DAYS = [1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sáb', 7 => 'dom'];
const TEAM_DAYS_LONG = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
const TEAM_ONLINE_SECONDS = 75;        // sinal a cada ~30 s: dois sinais falhados = offline
const TEAM_SLOT_MIN = 30;              // os blocos da disponibilidade
const TEAM_TASK_DEFAULT_MIN = 60;      // uma tarefa com hora e sem duração ocupa 1 h
const TEAM_INVITE_DAYS = 7;
const ABSENCE_KINDS = ['ferias' => 'Férias', 'ausencia' => 'Ausência', 'doenca' => 'Doença', 'formacao' => 'Formação'];
const ABSENCE_STATUS = ['pending' => 'Por aprovar', 'approved' => 'Aprovada', 'rejected' => 'Recusada', 'cancelled' => 'Cancelada'];
const MEETING_PLATFORMS = ['meet' => 'Google Meet', 'discord' => 'Discord', 'local' => 'Presencial'];
const MEETING_DURATIONS = [15 => '15 min', 30 => '30 min', 45 => '45 min', 60 => '1 h', 90 => '1 h 30', 120 => '2 h'];
const TEAM_FOCUS_OPTIONS = [30 => '30 minutos', 60 => '1 hora', 120 => '2 horas', 240 => '4 horas', 0 => 'Até ao fim do dia'];
const TEAM_TABS = ['equipa' => 'Equipa', 'organograma' => 'Organograma', 'acessos' => 'Utilizadores e acessos', 'departamentos' => 'Departamentos'];

/** Itens das checklists de entrada e de saída (§17.10): chave → texto. */
const TEAM_CHECK_IN = [
    'conta'   => 'Conta criada e convite enviado',
    'acessos' => 'Acessos revistos (papel, departamento e permissões)',
    'drive'   => 'Pasta pessoal no Drive (Equipa/<nome>/)',
    'apres'   => 'Apresentação à equipa',
    'tarefa'  => 'Primeira tarefa atribuída',
];
const TEAM_CHECK_OUT = [
    'trabalho' => 'Trabalho reatribuído (leads, negócios, tarefas, relações)',
    'acessos'  => 'Acessos retirados (conta desativada: sai logo de todas as sessões)',
    'material' => 'Material e ficheiros entregues (Drive)',
    'despedida'=> 'Conversa de saída',
];

// ── quem sou e o que vejo ───────────────────────────────────

/** O papel de quem pede, lido na base (não só na sessão). */
function team_role(PDO $pdo): string {
    static $r = null;
    if ($r !== null) return $r;
    $st = $pdo->prepare('SELECT role FROM users WHERE id = ? AND is_active = 1');
    $st->execute([(int)(current_user()['id'] ?? 0)]);
    return $r = (string)($st->fetchColumn() ?: '');
}

function team_is_admin(PDO $pdo): bool {
    return role_is_admin(team_role($pdo));
}

/** Os separadores que esta pessoa vê (§17.1). */
function team_tabs(PDO $pdo): array {
    $r = team_role($pdo);
    $out = ['equipa' => TEAM_TABS['equipa']];
    if (role_is_admin($r) || $r === 'lead') $out['organograma'] = TEAM_TABS['organograma'];
    if (role_is_admin($r)) { $out['acessos'] = TEAM_TABS['acessos']; $out['departamentos'] = TEAM_TABS['departamentos']; }
    return $out;
}

// ── pessoas, cargos, departamentos ──────────────────────────

function team_positions(PDO $pdo, bool $all = false): array {
    $out = [];
    foreach ($pdo->query('SELECT * FROM positions' . ($all ? '' : ' WHERE is_active = 1') . ' ORDER BY position, name')->fetchAll() as $p) $out[(int)$p['id']] = $p;
    return $out;
}

function team_depts(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT d.*, u.name AS head_name FROM departments d LEFT JOIN users u ON u.id = d.head_user_id ORDER BY d.position, d.name')->fetchAll() as $d) $out[(int)$d['id']] = $d;
    return $out;
}

/** «Campanhas e Adverts › Adverts». */
function team_dept_label(array $depts, ?int $id): string {
    if (!$id || !isset($depts[$id])) return '';
    $d = $depts[$id];
    $p = $d['parent_id'] ? ($depts[(int)$d['parent_id']]['name'] ?? '') : '';
    return ($p !== '' ? $p . ' › ' : '') . $d['name'];
}

/** Os departamentos em árvore: [id => [dep, filhos]] pela ordem. */
function team_dept_tree(array $depts): array {
    $kids = [];
    foreach ($depts as $id => $d) $kids[(int)($d['parent_id'] ?? 0)][] = $id;
    $walk = function (int $pid, int $lvl) use (&$walk, $kids, $depts): array {
        $out = [];
        foreach ($kids[$pid] ?? [] as $id) {
            $out[] = ['d' => $depts[$id], 'lvl' => $lvl];
            array_push($out, ...$walk($id, $lvl + 1));
        }
        return $out;
    };
    return $walk(0, 0);
}

/**
 * As pessoas, com cargos e departamento. Sem $admin, o custo/hora e as horas/mês
 * não vêm (§17.5: só admins, também na API).
 */
function team_people(PDO $pdo, bool $activeOnly = true, bool $admin = false): array {
    $rows = $pdo->query('SELECT * FROM users' . ($activeOnly ? ' WHERE is_active = 1 AND deactivated_at IS NULL' : '') . ' ORDER BY (is_active = 0), position, name')->fetchAll();
    $pos = [];
    foreach ($pdo->query('SELECT up.user_id, p.id, p.name FROM user_positions up JOIN positions p ON p.id = up.position_id ORDER BY p.position, p.name')->fetchAll() as $r) {
        $pos[(int)$r['user_id']][(int)$r['id']] = $r['name'];
    }
    $out = [];
    foreach ($rows as $u) {
        unset($u['password_hash']);
        if (!$admin) { unset($u['hourly_cost'], $u['monthly_hours']); }
        $u['positions'] = $pos[(int)$u['id']] ?? [];
        $out[(int)$u['id']] = $u;
    }
    return $out;
}

function team_person(PDO $pdo, int $id, bool $admin = false): ?array {
    return team_people($pdo, false, $admin)[$id] ?? null;
}

function team_positions_label(array $u): string {
    return implode(' · ', $u['positions'] ?? []);
}

function team_initials(string $name): string {
    $p = preg_split('/\s+/u', trim($name));
    return mb_strtoupper(mb_substr($p[0] ?? '', 0, 1) . (count($p) > 1 ? mb_substr(end($p), 0, 1) : ''));
}

/** O avatar (foto ou iniciais na cor da pessoa), com o ponto de presença. */
function team_avatar(array $u, string $size = '', bool $presence = true): string {
    $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)$u['color']) ? $u['color'] : '#81CFE3';
    $inner = $u['photo'] ? '<img src="/admin/avatar.php?id=' . (int)$u['id'] . '&amp;v=' . e(substr((string)$u['photo'], -8)) . '" alt="" loading="lazy">' : e(team_initials((string)$u['name']));
    $pr = '';
    if ($presence) {
        $p = team_presence($u);
        $pr = '<i class="pres ' . ($p['online'] ? 'on' : 'off') . '" data-pres="' . (int)$u['id'] . '" aria-hidden="true"></i>';
    }
    // Iniciais brancas em cores escuras, pretas nas claras.
    [$r, $g, $b] = sscanf($color, '#%02x%02x%02x');
    $dark = (0.299 * $r + 0.587 * $g + 0.114 * $b) < 140;
    return '<span class="tav' . ($size !== '' ? ' ' . $size : '') . ($dark ? ' dk' : '') . '" style="--who:' . e($color) . '" aria-hidden="true">' . $inner . $pr . '</span>';
}

// ── horário, presença, foco ─────────────────────────────────

/** O horário da pessoa: dia (1=seg … 7=dom) → [início, fim]. Por defeito seg–sex 09:00–18:00. */
function team_work_hours(array $u): array {
    $j = json_decode((string)($u['work_hours'] ?? ''), true);
    if (!is_array($j)) return TEAM_WORK_DEFAULT;
    $out = [];
    foreach ($j as $d => $r) {
        if (!isset(TEAM_DAYS[(int)$d]) || !is_array($r) || count($r) !== 2) continue;
        if (preg_match('/^\d{2}:\d{2}$/', (string)$r[0]) && preg_match('/^\d{2}:\d{2}$/', (string)$r[1]) && $r[0] < $r[1]) $out[(int)$d] = [$r[0], $r[1]];
    }
    ksort($out);
    return $out;
}

function team_work_label(array $u): string {
    $h = team_work_hours($u);
    if ($h === TEAM_WORK_DEFAULT) return 'seg–sex 09:00–18:00';
    $parts = [];
    foreach ($h as $d => [$a, $b]) $parts[] = TEAM_DAYS[$d] . ' ' . $a . '–' . $b;
    return $parts ? implode(' · ', $parts) : 'sem horário';
}

/** Online = sinal da sessão nos últimos ~75 s. Nunca diz o que a pessoa faz. */
function team_presence(array $u): array {
    $ts = $u['last_seen_at'] ? strtotime((string)$u['last_seen_at']) : 0;
    $online = $ts && time() - $ts <= TEAM_ONLINE_SECONDS;
    return ['online' => $online, 'seen' => $ts ?: null, 'label' => $online ? 'Online' : ($ts ? 'Offline · visto ' . team_ago($ts) : 'Offline')];
}

function team_ago(int $ts): string {
    $s = max(0, time() - $ts);
    if ($s < 3600) return 'há ' . max(1, (int)round($s / 60)) . ' min';
    if ($s < 86400) return 'há ' . (int)floor($s / 3600) . ' h';
    $d = (int)floor($s / 86400);
    return $d === 1 ? 'ontem' : 'há ' . $d . ' dias';
}

/** O sinal da sessão (~30 s): só a hora, mais nada. */
function team_ping(PDO $pdo, int $uid): void {
    $pdo->prepare('UPDATE users SET last_seen_at = ? WHERE id = ?')->execute([now_php(), $uid]);
}

/** Modo Foco: o instante (unix) até quando, ou 0. */
function team_focus_until(array $u): int {
    $t = $u['focus_until'] ? strtotime((string)$u['focus_until']) : 0;
    return $t > time() ? $t : 0;
}

/** Ligar o modo Foco por N minutos (0 = até ao fim do horário de hoje) ou desligar (null). */
function team_focus_set(PDO $pdo, array $u, ?int $minutes): ?string {
    if ($minutes === null) {
        $pdo->prepare('UPDATE users SET focus_until = NULL WHERE id = ?')->execute([$u['id']]);
        return null;
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon'));
    if ($minutes === 0) {
        $h = team_work_hours($u)[(int)$now->format('N')][1] ?? '19:00';
        $end = new DateTimeImmutable($now->format('Y-m-d') . ' ' . $h, new DateTimeZone('Europe/Lisbon'));
        if ($end <= $now) $end = $now->modify('+1 hour');
    } else {
        $end = $now->modify('+' . max(5, min(600, $minutes)) . ' minutes');
    }
    $until = date('Y-m-d H:i:s', $end->getTimestamp());
    $pdo->prepare('UPDATE users SET focus_until = ? WHERE id = ?')->execute([$until, $u['id']]);
    return $end->format('H:i');
}

// ── agenda: blocos ocupados, estado, disponibilidade ────────

function team_tz(): DateTimeZone {
    static $tz = null;
    return $tz ??= new DateTimeZone('Europe/Lisbon');
}

/** Instante unix de «aaaa-mm-dd hh:mm» em Lisboa. */
function team_ts(string $local): int {
    return (new DateTimeImmutable($local, team_tz()))->getTimestamp();
}

/**
 * Os blocos ocupados de uma pessoa entre dois dias (aaaa-mm-dd, fim exclusivo):
 * tarefas com hora marcadas «Ocupado», ausências aprovadas e o modo Foco.
 * Cada bloco: [a (unix), b (unix), kind (task|absence|focus), title].
 */
function team_busy(PDO $pdo, array $u, string $from, string $to): array {
    $out = [];
    if ($u['team_member_id']) {
        $priv = db_has_column($pdo, 'tasks', 'private') ? 'private' : '0';
        $st = $pdo->prepare("SELECT title, due_date, due_time, duration_min, kind, $priv AS private FROM tasks
                              WHERE member_id = ? AND busy = 1 AND due_time IS NOT NULL AND status <> 'done' AND due_date >= ? AND due_date < ?");
        $st->execute([(int)$u['team_member_id'], $from, $to]);
        foreach ($st->fetchAll() as $t) {
            $a = team_ts($t['due_date'] . ' ' . substr((string)$t['due_time'], 0, 5));
            // Tarefas pessoais e bloqueios são privados (§18.8): todos, admins incluídos, veem «Ocupado».
            $out[] = [$a, $a + 60 * (int)($t['duration_min'] ?: TEAM_TASK_DEFAULT_MIN), $t['kind'] === 'meeting' ? 'meeting' : 'task', (int)$t['private'] ? 'Ocupado' : (string)$t['title']];
        }
    }
    $st = $pdo->prepare("SELECT kind, start_date, end_date FROM absences WHERE user_id = ? AND status = 'approved' AND end_date >= ? AND start_date < ?");
    $st->execute([(int)$u['id'], $from, $to]);
    foreach ($st->fetchAll() as $ab) {
        $out[] = [team_ts($ab['start_date'] . ' 00:00'), team_ts($ab['end_date'] . ' 00:00') + 86400, 'absence', ABSENCE_KINDS[$ab['kind']] ?? 'Ausência'];
    }
    if ($f = team_focus_until($u)) $out[] = [time(), $f, 'focus', 'Foco'];
    usort($out, fn($x, $y) => $x[0] <=> $y[0]);
    return $out;
}

/** O estado da agenda agora (§17.5): [key, label]. key: absent | focus | busy | off | free. */
function team_agenda_state(PDO $pdo, array $u): array {
    $now = time();
    $today = (new DateTimeImmutable('now', team_tz()))->format('Y-m-d');
    $blocks = team_busy($pdo, $u, $today, (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d'));
    foreach ($blocks as [$a, $b, $k]) if ($k === 'absence' && $a <= $now && $now < $b) return ['absent', 'Ausente'];
    if ($f = team_focus_until($u)) return ['focus', 'Em foco até ' . lisbon_fmt($f, 'H:i')];
    // Ocupado agora: junta os blocos seguidos para dizer até quando.
    $cur = $now;
    do {
        $moved = false;
        foreach ($blocks as [$a, $b, $k]) {
            if ($k !== 'absence' && $a <= $cur && $b > $cur) { $cur = $b; $moved = true; }
        }
    } while ($moved);
    if ($cur > $now) return ['busy', 'Ocupado até ' . lisbon_fmt($cur, 'H:i')];
    $wh = team_work_hours($u)[(int)lisbon_fmt($now, 'N')] ?? null;
    $hm = lisbon_fmt($now, 'H:i');
    if (!$wh || $hm < $wh[0] || $hm >= $wh[1]) return ['off', 'Fora do horário'];
    return ['free', 'Disponível'];
}

/**
 * A semana de uma pessoa em blocos de 30 min dentro do horário dela (§17.7).
 * Devolve ['days' => [aaaa-mm-dd => [hh:mm => [state, label]]], 'rows' => [hh:mm…]].
 * state: free | busy | absent | past. Os outros veem só «Ocupado»; admins veem o título.
 */
function team_week(PDO $pdo, array $u, string $monday, bool $titles): array {
    $wh = team_work_hours($u);
    $from = $monday;
    $to = (new DateTimeImmutable($monday))->modify('+7 days')->format('Y-m-d');
    $blocks = team_busy($pdo, $u, $from, $to);
    $rows = [];
    foreach ($wh as [$a, $b]) for ($t = strtotime('2000-01-01 ' . $a); $t < strtotime('2000-01-01 ' . $b); $t += TEAM_SLOT_MIN * 60) $rows[date('H:i', $t)] = true;
    ksort($rows);
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $d = (new DateTimeImmutable($monday))->modify("+$i days");
        $dow = (int)$d->format('N');
        if (!isset($wh[$dow])) continue;
        $date = $d->format('Y-m-d');
        foreach (array_keys($rows) as $hm) {
            if ($hm < $wh[$dow][0] || $hm >= $wh[$dow][1]) { $days[$date][$hm] = ['off', '']; continue; }
            $a = team_ts($date . ' ' . $hm);
            $b = $a + TEAM_SLOT_MIN * 60;
            $state = ['free', 'Livre'];
            foreach ($blocks as [$x, $y, $k, $title]) {
                if ($x < $b && $y > $a) {
                    $state = $k === 'absence' ? ['absent', $titles ? $title : 'Ausente'] : ['busy', $titles ? $title : ($k === 'focus' ? 'Foco' : 'Ocupado')];
                    if ($k === 'absence') break;
                }
            }
            if ($state[0] === 'free' && $b <= time()) $state = ['past', ''];
            $days[$date][$hm] = $state;
        }
    }
    return ['days' => $days, 'rows' => array_keys($rows)];
}

/** «Encontrar hora» (§17.7): os inícios em que todas as pessoas estão livres durante $minutes. */
function team_find_time(PDO $pdo, array $people, string $monday, int $minutes, int $limit = 24): array {
    $weeks = [];
    foreach ($people as $u) $weeks[(int)$u['id']] = team_week($pdo, $u, $monday, false)['days'];
    $need = max(1, (int)ceil($minutes / TEAM_SLOT_MIN));
    $out = [];
    $first = reset($weeks) ?: [];
    foreach ($first as $date => $slots) {
        $keys = array_keys($slots);
        foreach ($keys as $i => $hm) {
            $ok = true;
            for ($k = 0; $k < $need && $ok; $k++) {
                $s = $keys[$i + $k] ?? null;
                if ($s === null) { $ok = false; break; }
                foreach ($weeks as $w) if (($w[$date][$s][0] ?? 'off') !== 'free') { $ok = false; break; }
            }
            if ($ok) { $out[] = ['date' => $date, 'time' => $hm]; if (count($out) >= $limit) return $out; }
        }
    }
    return $out;
}

// ── histórico da conta ──────────────────────────────────────

function team_log(PDO $pdo, int $uid, string $event, string $note = '', ?int $by = null): void {
    $pdo->prepare('INSERT INTO user_history (user_id, event, note, by_user, created_at) VALUES (?,?,?,?,?)')
        ->execute([$uid, $event, mb_substr($note, 0, 255), $by ?? (current_user()['id'] ?? null), now_php()]);
}

function team_history_html(PDO $pdo, int $uid): string {
    $st = $pdo->prepare('SELECT h.*, u.name AS who FROM user_history h LEFT JOIN users u ON u.id = h.by_user WHERE h.user_id = ? ORDER BY h.created_at DESC, h.id DESC LIMIT 100');
    $st->execute([$uid]);
    $items = [];
    foreach ($st->fetchAll() as $h) {
        $t = 'u_' . $h['event'];
        $items[] = ['when' => $h['created_at'], 'type' => isset(HISTORY_TYPES[$t]) ? $t : 'u_edit', 'title' => HISTORY_TYPES[$t][0] ?? $h['event'],
                    'text' => (string)$h['note'], 'who' => $h['who'] ?? 'Sistema', 'sys' => true];
    }
    return timeline_html($items);
}

// ── email e convites ────────────────────────────────────────

/** O endereço da plataforma (os links dos emails), nunca tirado só do cabeçalho Host. */
function team_app_url(): string {
    global $CONFIG;
    $base = (string)($CONFIG['app_url'] ?? '');
    if ($base === '') {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $base = defined('IS_LOCAL') && IS_LOCAL && $host !== '' ? 'http://' . $host : 'https://topperhub.xyz';
    }
    return rtrim($base, '/');
}

/** Um email simples da plataforma. Na bancada fica no registo de erros. */
function team_mail(string $to, string $subject, string $body): bool {
    $headers = "From: topper. <no-reply@topperdigital.xyz>\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Auto-Response-Suppress: All";
    $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    if (defined('IS_LOCAL') && IS_LOCAL) error_log('[topper] email para ' . $to . ' (só na bancada): ' . $subject . "\n" . $body);
    elseif (!$sent) error_log('[topper] o email «' . $subject . '» não saiu para ' . $to);
    return (bool)$sent;
}

/** O convite (§17.8): um link para definir a palavra-passe, válido 7 dias. */
function team_send_invite(PDO $pdo, int $uid, ?int $by): string {
    $st = $pdo->prepare('SELECT id, name, email, is_active FROM users WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch();
    if (!$u || !(int)$u['is_active']) throw new RuntimeException('Conta não encontrada ou desativada.');
    $raw = bin2hex(random_bytes(32));
    $lite = db_is_sqlite($pdo);
    $pdo->prepare("UPDATE password_resets SET used_at = " . ($lite ? "datetime('now')" : 'NOW()') . " WHERE user_id = ? AND kind = 'invite' AND used_at IS NULL")->execute([$uid]);
    $pdo->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, kind) VALUES (?, ?, "
                . ($lite ? "datetime('now','+" . TEAM_INVITE_DAYS . " days')" : 'NOW() + INTERVAL ' . TEAM_INVITE_DAYS . ' DAY') . ", 'invite')")
        ->execute([$uid, hash('sha256', $raw)]);
    $link = team_app_url() . '/admin/index.php?reset=' . $raw;
    $first = explode(' ', trim((string)$u['name']))[0];
    team_mail((string)$u['email'], 'topper. — o seu acesso à plataforma',
        "Olá $first,\n\nFoi criada a sua conta na plataforma da topper.\n"
        . "Para entrar, defina a sua palavra-passe neste link (vale " . TEAM_INVITE_DAYS . " dias):\n\n$link\n\n"
        . "O seu login é este email: " . $u['email'] . "\n\nAté já,\ntopper.\n");
    $pdo->prepare('UPDATE users SET invite_pending = 1, invited_at = ? WHERE id = ?')->execute([now_php(), $uid]);
    team_log($pdo, $uid, 'invite', 'Convite enviado para ' . $u['email'], $by);
    return $link;
}

// ── checklists de entrada e saída ───────────────────────────

function team_checklist(PDO $pdo, int $uid, string $kind): ?array {
    $st = $pdo->prepare('SELECT * FROM team_checklists WHERE user_id = ? AND kind = ?');
    $st->execute([$uid, $kind]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['items'] = json_decode((string)$r['items'], true) ?: [];
    return $r;
}

function team_checklist_start(PDO $pdo, int $uid, string $kind, array $done = []): void {
    if (team_checklist($pdo, $uid, $kind)) return;
    $items = [];
    foreach ($kind === 'saida' ? TEAM_CHECK_OUT : TEAM_CHECK_IN as $k => $t) $items[] = ['k' => $k, 't' => $t, 'done' => in_array($k, $done, true)];
    $pdo->prepare('INSERT INTO team_checklists (user_id, kind, items, created_at) VALUES (?,?,?,?)')
        ->execute([$uid, $kind, json_encode($items, JSON_UNESCAPED_UNICODE), now_php()]);
}

function team_checklist_set(PDO $pdo, int $uid, string $kind, string $key, bool $done): array {
    $c = team_checklist($pdo, $uid, $kind);
    if (!$c) throw new RuntimeException('Checklist não encontrada.');
    foreach ($c['items'] as &$i) if ($i['k'] === $key) $i['done'] = $done;
    unset($i);
    $all = !array_filter($c['items'], fn($i) => empty($i['done']));
    $pdo->prepare('UPDATE team_checklists SET items = ?, completed_at = ? WHERE id = ?')
        ->execute([json_encode($c['items'], JSON_UNESCAPED_UNICODE), $all ? now_php() : null, $c['id']]);
    return $c['items'];
}

/** Marca sozinho o que já se vê feito: a primeira tarefa atribuída. */
function team_checklist_auto(PDO $pdo, array $u): void {
    $c = team_checklist($pdo, (int)$u['id'], 'entrada');
    if (!$c || !$u['team_member_id']) return;
    foreach ($c['items'] as $i) {
        if ($i['k'] === 'tarefa' && empty($i['done']) && (int)$pdo->query('SELECT COUNT(*) FROM tasks WHERE member_id = ' . (int)$u['team_member_id'])->fetchColumn() > 0) {
            team_checklist_set($pdo, (int)$u['id'], 'entrada', 'tarefa', true);
        }
    }
}

// ── saída: reatribuir o trabalho aberto ─────────────────────

/** O trabalho aberto de uma pessoa: leads, negócios, tarefas, relações e peças de Marketing. */
function team_open_work(PDO $pdo, array $u): array {
    $id = (int)$u['id'];
    $n = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
    return [
        'leads'     => $n("SELECT COUNT(*) FROM deals WHERE owner_id = $id AND phase = 'lead'"),
        'negocios'  => $n("SELECT COUNT(*) FROM deals WHERE owner_id = $id AND phase IN ('pipeline','production')"),
        'tarefas'   => $u['team_member_id'] ? $n("SELECT COUNT(*) FROM tasks WHERE member_id = " . (int)$u['team_member_id'] . " AND status <> 'done'") : 0,
        'relacoes'  => db_has_table($pdo, 'relations') ? $n("SELECT COUNT(*) FROM relations WHERE owner_id = $id") : 0,
        'marketing' => db_has_table($pdo, 'mkt_pieces') ? $n("SELECT COUNT(*) FROM mkt_pieces WHERE owner_id = $id AND (stage IS NULL OR stage <> 'publicado')") : 0,
    ];
}

/** Passa o trabalho aberto de $from para $to. Devolve as contagens. */
function team_reassign(PDO $pdo, array $from, array $to): array {
    $before = team_open_work($pdo, $from);
    $f = (int)$from['id']; $t = (int)$to['id'];
    $pdo->prepare("UPDATE deals SET owner_id = ? WHERE owner_id = ? AND phase IN ('lead','pipeline','production')")->execute([$t, $f]);
    if ($from['team_member_id']) {
        $mt = member_for_user($pdo, $t);
        $pdo->prepare("UPDATE tasks SET member_id = ? WHERE member_id = ? AND status <> 'done'")->execute([$mt, (int)$from['team_member_id']]);
    }
    if (db_has_table($pdo, 'relations')) $pdo->prepare('UPDATE relations SET owner_id = ? WHERE owner_id = ?')->execute([$t, $f]);
    if (db_has_table($pdo, 'mkt_pieces')) $pdo->prepare("UPDATE mkt_pieces SET owner_id = ? WHERE owner_id = ? AND (stage IS NULL OR stage <> 'publicado')")->execute([$t, $f]);
    $pdo->prepare('UPDATE users SET reports_to = ? WHERE reports_to = ?')->execute([$t, $f]);
    $pdo->prepare('UPDATE departments SET head_user_id = NULL WHERE head_user_id = ?')->execute([$f]);
    return $before;
}

// ── férias e ausências ──────────────────────────────────────

function team_absence_request(PDO $pdo, int $uid, string $kind, string $from, string $to, string $note): int {
    if (!isset(ABSENCE_KINDS[$kind])) throw new RuntimeException('Escolha o tipo.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) throw new RuntimeException('Escolha as datas (o fim não pode ser antes do início).');
    if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 92) throw new RuntimeException('No máximo 3 meses de cada vez.');
    $pdo->prepare('INSERT INTO absences (user_id, kind, start_date, end_date, note, status, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$uid, $kind, $from, $to, mb_substr(trim($note), 0, 255), 'pending', now_php()]);
    $id = (int)$pdo->lastInsertId();
    team_log($pdo, $uid, 'absence', ABSENCE_KINDS[$kind] . ' pedida: ' . date('d/m', strtotime($from)) . ' a ' . date('d/m', strtotime($to)));
    return $id;
}

function team_absence(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT a.*, u.name, u.email FROM absences a JOIN users u ON u.id = a.user_id WHERE a.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Aprovar · Recusar (admins). Avisa a pessoa por email. */
function team_absence_decide(PDO $pdo, array $a, string $decision, int $by): string {
    if (!in_array($decision, ['approved', 'rejected'], true)) throw new RuntimeException('Escolha Aprovar ou Recusar.');
    if ($a['status'] !== 'pending') throw new RuntimeException('Este pedido já foi decidido.');
    $pdo->prepare('UPDATE absences SET status = ?, decided_by = ?, decided_at = ? WHERE id = ?')->execute([$decision, $by, now_php(), $a['id']]);
    $when = date('d/m', strtotime((string)$a['start_date'])) . ' a ' . date('d/m', strtotime((string)$a['end_date']));
    team_log($pdo, (int)$a['user_id'], 'absence', (ABSENCE_KINDS[$a['kind']] ?? 'Ausência') . ' ' . $when . ': ' . mb_strtolower(ABSENCE_STATUS[$decision]), $by);
    team_mail((string)$a['email'], 'topper. — ' . (ABSENCE_KINDS[$a['kind']] ?? 'Ausência') . ' ' . ($decision === 'approved' ? 'aprovada' : 'recusada'),
        'Olá ' . explode(' ', trim((string)$a['name']))[0] . ",\n\nO seu pedido (" . (ABSENCE_KINDS[$a['kind']] ?? 'Ausência') . ", $when) foi "
        . ($decision === 'approved' ? 'aprovado. A agenda fica bloqueada nesses dias.' : 'recusado. Fale com a direção se precisar.') . "\n\ntopper.\n");
    return $decision === 'approved' ? 'Aprovada: a agenda fica bloqueada nesses dias.' : 'Recusada. A pessoa foi avisada por email.';
}

/** As ausências aprovadas que tocam [de, até]. */
function team_absences_between(PDO $pdo, string $from, string $to, string $status = 'approved'): array {
    $st = $pdo->prepare('SELECT a.*, u.name, u.color FROM absences a JOIN users u ON u.id = a.user_id WHERE a.status = ? AND a.end_date >= ? AND a.start_date <= ? ORDER BY a.start_date');
    $st->execute([$status, $from, $to]);
    return $st->fetchAll();
}

// ── chat privado 1:1 (§17.6) ────────────────────────────────

/** As conversas de $me: a outra pessoa, a última mensagem e as não lidas. */
function team_chat_list(PDO $pdo, int $me): array {
    $st = $pdo->prepare('SELECT CASE WHEN from_id = ? THEN to_id ELSE from_id END AS other, MAX(id) AS last_id,
                                SUM(CASE WHEN to_id = ? AND read_at IS NULL THEN 1 ELSE 0 END) AS unread
                           FROM chat_messages WHERE from_id = ? OR to_id = ? GROUP BY other ORDER BY last_id DESC');
    $st->execute([$me, $me, $me, $me]);
    $out = [];
    $get = $pdo->prepare('SELECT * FROM chat_messages WHERE id = ?');
    foreach ($st->fetchAll() as $r) {
        $get->execute([(int)$r['last_id']]);
        $out[(int)$r['other']] = ['last' => $get->fetch(), 'unread' => (int)$r['unread']];
    }
    return $out;
}

/** Não lidas (0 durante o modo Foco: o chat fica em silêncio). */
function team_chat_unread(PDO $pdo, array $me): int {
    if (team_focus_until($me)) return 0;
    $st = $pdo->prepare('SELECT COUNT(*) FROM chat_messages WHERE to_id = ? AND read_at IS NULL');
    $st->execute([(int)$me['id']]);
    return (int)$st->fetchColumn();
}

/** As mensagens entre $me e $other depois de $after (e marca as recebidas como lidas). */
function team_chat_thread(PDO $pdo, int $me, int $other, int $after = 0, int $limit = 120): array {
    $st = $pdo->prepare('SELECT * FROM chat_messages WHERE ((from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?)) AND id > ? ORDER BY id DESC LIMIT ' . (int)$limit);
    $st->execute([$me, $other, $other, $me, $after]);
    $rows = array_reverse($st->fetchAll());
    $pdo->prepare('UPDATE chat_messages SET read_at = ? WHERE to_id = ? AND from_id = ? AND read_at IS NULL')->execute([now_php(), $me, $other]);
    return $rows;
}

function team_chat_send(PDO $pdo, int $me, int $to, string $body, ?int $meeting = null): int {
    $body = trim(mb_substr($body, 0, 4000));
    if ($body === '') throw new RuntimeException('Escreva a mensagem.');
    if ($to === $me) throw new RuntimeException('Escolha outra pessoa.');
    $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1');
    $st->execute([$to]);
    if (!(int)$st->fetchColumn()) throw new RuntimeException('Essa pessoa já não está na equipa.');
    $pdo->prepare('INSERT INTO chat_messages (from_id, to_id, body, meeting_id, created_at) VALUES (?,?,?,?,?)')->execute([$me, $to, $body, $meeting, now_php()]);
    return (int)$pdo->lastInsertId();
}

/**
 * Os links de registos numa mensagem viram cartões (§17.6): só se quem lê os pode ver.
 * Lead, negócio, contacto, tarefa, orçamento e peça de Marketing.
 */
function team_chat_cards(PDO $pdo, string $body): array {
    require_once __DIR__ . '/reach.php';
    $out = [];
    if (!preg_match_all('~(?:https?://[^\s/]+)?/admin/(lead|leads|negocio|contact|orcamento|marketing_peca|calendar)\.php\?(?:[^\s#]*?&)?(id|open|task|edit)=(\d+)~', $body, $m, PREG_SET_ORDER)) return [];
    foreach (array_slice($m, 0, 4) as [$url, $page, , $id]) {
        $id = (int)$id;
        $card = null;
        if (in_array($page, ['lead', 'leads', 'negocio', 'orcamento'], true)) {
            $d = record_get($pdo, $id);
            if ($d && record_reach($pdo, $d)) {
                $isLead = in_array($d['phase'], ['lead', 'archived'], true);
                $card = ['icon' => $isLead ? 'leads' : 'negocios', 'kind' => $isLead ? 'Lead' : ($page === 'orcamento' ? 'Orçamento' : 'Negócio'),
                         'title' => trim(($d['code'] ? $d['code'] . ' · ' : '') . ($isLead ? lead_display_name($d) : $d['name'])),
                         'url' => $isLead ? '/admin/lead.php?id=' . $id : ($page === 'orcamento' ? '/admin/orcamento.php?id=' . $id : '/admin/negocio.php?id=' . $id),
                         'panel' => $isLead ? 'lead:' . $id : 'deal:' . $id];
            }
        } elseif ($page === 'contact' && can('contactos')) {
            $st = $pdo->prepare('SELECT name FROM contacts WHERE id = ?'); $st->execute([$id]);
            if ($n = $st->fetchColumn()) $card = ['icon' => 'contactos', 'kind' => 'Contacto', 'title' => $n, 'url' => '/admin/contact.php?id=' . $id, 'panel' => 'contact:' . $id];
        } elseif ($page === 'marketing_peca' && can('marketing') && db_has_table($pdo, 'mkt_pieces')) {
            $st = $pdo->prepare('SELECT title FROM mkt_pieces WHERE id = ?'); $st->execute([$id]);
            if ($n = $st->fetchColumn()) $card = ['icon' => 'marketing', 'kind' => 'Peça', 'title' => $n, 'url' => '/admin/marketing_peca.php?id=' . $id, 'panel' => 'mkt:' . $id];
        } elseif ($page === 'calendar' && can('tarefas')) {
            $st = $pdo->prepare('SELECT title, due_date FROM tasks WHERE id = ?'); $st->execute([$id]);
            if ($t = $st->fetch()) $card = ['icon' => 'tarefas', 'kind' => 'Tarefa', 'title' => $t['title'] . ($t['due_date'] ? ' · ' . date('d/m', strtotime($t['due_date'])) : ''), 'url' => '/admin/calendar.php?edit=' . $id, 'panel' => ''];
        }
        if ($card) $out[] = $card;
    }
    return $out;
}

/** O texto de uma mensagem em HTML: escapado, links clicáveis, quebras de linha. */
function team_chat_html(string $body): string {
    $h = e($body);
    $h = preg_replace('~(https?://[^\s<]+|/admin/[a-z_]+\.php\?[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>', $h);
    return nl2br((string)$h);
}

// ── reuniões (§17.7) ────────────────────────────────────────

/** A configuração das integrações (inc/meet_config.php, fora do Git): google / discord. */
function team_meet_config(): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = ['google' => null, 'discord' => null];
    $f = __DIR__ . '/meet_config.php';
    if (!is_file($f)) return $c;
    $raw = require $f;
    if (!is_array($raw)) return $c;
    $g = $raw['google'] ?? [];
    if (is_array($g) && !empty($g['client_id']) && !empty($g['client_secret']) && !empty($g['refresh_token']) && stripos((string)$g['client_id'], 'COLE_') !== 0) {
        $c['google'] = ['client_id' => (string)$g['client_id'], 'client_secret' => (string)$g['client_secret'],
                        'refresh_token' => (string)$g['refresh_token'], 'calendar_id' => (string)($g['calendar_id'] ?? 'primary')];
    }
    $d = trim((string)($raw['discord']['invite_url'] ?? ''));
    if (preg_match('~^https://(discord\.gg|discord\.com)/\S+$~', $d)) $c['discord'] = ['invite_url' => $d];
    return $c;
}

function team_meeting(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT m.*, r.name AS requester_name, r.email AS requester_email, i.name AS invitee_name, i.email AS invitee_email,
                                d.code AS deal_code, d.name AS deal_name
                           FROM meetings m JOIN users r ON r.id = m.requester_id JOIN users i ON i.id = m.invitee_id LEFT JOIN deals d ON d.id = m.deal_id WHERE m.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** «ter 28 out · 11:00–11:30». */
function team_when(string $startsAt, int $minutes): string {
    $a = strtotime($startsAt);
    $m = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    return TEAM_DAYS[(int)date('N', $a)] . ' ' . (int)date('j', $a) . ' ' . $m[(int)date('n', $a)] . ' · ' . date('H:i', $a) . '–' . date('H:i', $a + $minutes * 60);
}

/**
 * Pedir reunião: chega no chat como cartão (Aceitar / Recusar / Propor outra hora).
 * $startLocal «aaaa-mm-dd hh:mm» (Lisboa). Devolve o id.
 */
function team_meeting_request(PDO $pdo, int $from, int $to, string $startLocal, int $minutes, string $subject, ?int $dealId, string $platform, string $link, string $group = '', ?int $parent = null, string $location = ''): int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $startLocal)) throw new RuntimeException('Escolha o dia e a hora.');
    if (team_ts($startLocal) < time() - 60) throw new RuntimeException('Essa hora já passou.');
    if ($minutes < 5 || $minutes > 720) throw new RuntimeException('Escolha a duração.');
    if (!isset(MEETING_PLATFORMS[$platform])) throw new RuntimeException('Escolha a plataforma (Google Meet, Discord ou presencial).');
    $subject = trim(mb_substr($subject, 0, 200));
    if ($subject === '') throw new RuntimeException('Escreva o assunto.');
    $link = trim($link);
    if ($link !== '' && !preg_match('~^https://\S+$~', $link)) throw new RuntimeException('O link da chamada começa por https://.');
    if ($from === $to) throw new RuntimeException('Escolha outra pessoa.');
    if ($dealId) {
        require_once __DIR__ . '/reach.php';
        $d = record_get($pdo, $dealId);
        if (!$d || !record_reach($pdo, $d)) $dealId = null;
    }
    $pdo->prepare('INSERT INTO meetings (requester_id, invitee_id, group_key, starts_at, duration_min, subject, deal_id, platform, link, status, parent_id, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,\'pending\',?,?)')
        ->execute([$from, $to, $group, $startLocal . ':00', $minutes, $subject, $dealId, $platform, mb_substr($link, 0, 500), $parent, now_php()]);
    $id = (int)$pdo->lastInsertId();
    if (trim($location) !== '' && db_has_column($pdo, 'meetings', 'location')) $pdo->prepare('UPDATE meetings SET location = ? WHERE id = ?')->execute([mb_substr(trim($location), 0, 200), $id]);
    team_chat_send($pdo, $from, $to, '📅 Pedido de reunião: ' . $subject . ' · ' . team_when($startLocal . ':00', $minutes) . ' · ' . MEETING_PLATFORMS[$platform] . (trim($location) !== '' ? ' · ' . trim($location) : ''), $id);
    return $id;
}

/** O link da chamada: Google Meet pela integração, Discord pelo convite configurado, ou o manual. */
function team_meeting_link(array $m, string $manual): string {
    $manual = trim($manual);
    if ($manual !== '' && !preg_match('~^https://\S+$~', $manual)) throw new RuntimeException('O link da chamada começa por https://.');
    if ($manual !== '') return mb_substr($manual, 0, 500);
    if ($m['link'] !== '') return (string)$m['link'];
    if ($m['platform'] === 'local') return '';   // presencial: sem link, segue o local
    $cfg = team_meet_config();
    if ($m['platform'] === 'discord' && $cfg['discord']) return $cfg['discord']['invite_url'];
    if ($m['platform'] === 'meet' && $cfg['google']) return team_google_meet($cfg['google'], $m);
    throw new RuntimeException('Cole o link da chamada (' . MEETING_PLATFORMS[$m['platform']] . '): a integração ainda não está configurada.');
}

/** Cria o evento com Google Meet pela API do Google Calendar (credenciais só no servidor). Devolve o link. */
function team_google_meet(array $g, array $m): string {
    if (!function_exists('curl_init')) throw new RuntimeException('Este servidor não tem o cURL do PHP.');
    $post = function (string $url, $body, array $headers) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]);
        $r = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, json_decode((string)$r, true)];
    };
    [$c, $tok] = $post('https://oauth2.googleapis.com/token', http_build_query(['client_id' => $g['client_id'], 'client_secret' => $g['client_secret'],
                       'refresh_token' => $g['refresh_token'], 'grant_type' => 'refresh_token']), ['Content-Type: application/x-www-form-urlencoded']);
    if ($c !== 200 || empty($tok['access_token'])) throw new RuntimeException('O Google recusou as credenciais (meet_config.php). Cole o link à mão.');
    $a = new DateTimeImmutable((string)$m['starts_at'], team_tz());
    $ev = ['summary' => (string)$m['subject'], 'start' => ['dateTime' => $a->format(DATE_RFC3339), 'timeZone' => 'Europe/Lisbon'],
           'end' => ['dateTime' => $a->modify('+' . (int)$m['duration_min'] . ' minutes')->format(DATE_RFC3339), 'timeZone' => 'Europe/Lisbon'],
           'attendees' => [['email' => (string)$m['requester_email']], ['email' => (string)$m['invitee_email']]],
           'conferenceData' => ['createRequest' => ['requestId' => 'topper-' . (int)$m['id'] . '-' . bin2hex(random_bytes(4)), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]]];
    [$c, $res] = $post('https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($g['calendar_id']) . '/events?conferenceDataVersion=1',
                       json_encode($ev), ['Authorization: Bearer ' . $tok['access_token'], 'Content-Type: application/json']);
    $link = (string)($res['hangoutLink'] ?? '');
    if ($c >= 300 || $link === '') throw new RuntimeException('O Google não criou a chamada. Cole o link à mão.');
    return $link;
}

/** Aceitar (§17.7): tarefa «Ocupado» nos dois, histórico do negócio e email com o link. Devolve [mensagem, tarefas]. */
function team_meeting_accept(PDO $pdo, array $m, int $by, string $manualLink): array {
    if ((int)$m['invitee_id'] !== $by) throw new RuntimeException('Só a pessoa convidada aceita.');
    if ($m['status'] !== 'pending') throw new RuntimeException('Este pedido já foi respondido.');
    $link = team_meeting_link($m, $manualLink);
    $date = substr((string)$m['starts_at'], 0, 10);
    $time = substr((string)$m['starts_at'], 11, 5);
    $title = 'Reunião: ' . $m['subject'];
    $where = $m['platform'] === 'local' ? trim((string)($m['location'] ?? '')) : $link;
    $notes = 'Com ' . $m['requester_name'] . ' e ' . $m['invitee_name'] . ' · ' . MEETING_PLATFORMS[$m['platform']] . "\n" . $where;
    $mk = function (int $uid) use ($pdo, $m, $title, $notes, $date, $time): int {
        require_once __DIR__ . '/tasks.php';
        $mid = member_for_user($pdo, $uid);
        $now = task_now();
        $pdo->prepare("INSERT INTO tasks (title, description, member_id, job_name, deal_id, due_date, due_time, duration_min, kind, busy, priority, status, position, created_at, updated_at, created_by)
                       VALUES (?,?,?,'',?,?,?,?,'meeting',1,'media','todo',?,?,?,?)")
            ->execute([mb_substr($title, 0, 200), $notes, $mid, $m['deal_id'], $date, $time . ':00', (int)$m['duration_min'], task_next_position($pdo, $mid), $now, $now, $uid]);
        return (int)$pdo->lastInsertId();
    };
    // Num pedido para várias pessoas, quem pediu fica com uma só tarefa.
    $rt = null;
    if ($m['group_key'] !== '') {
        $st = $pdo->prepare("SELECT requester_task_id FROM meetings WHERE group_key = ? AND requester_task_id IS NOT NULL LIMIT 1");
        $st->execute([$m['group_key']]);
        $rt = $st->fetchColumn() ?: null;
    }
    $rt = $rt ? (int)$rt : $mk((int)$m['requester_id']);
    $it = $mk((int)$m['invitee_id']);
    $pdo->prepare("UPDATE meetings SET status = 'accepted', link = ?, decided_at = ?, requester_task_id = ?, invitee_task_id = ? WHERE id = ?")
        ->execute([$link, now_php(), $rt, $it, $m['id']]);
    if ($m['deal_id'] && function_exists('deal_log')) deal_log($pdo, (int)$m['deal_id'], 'meeting', ['note' => 'Reunião interna: ' . $m['subject'] . ' · ' . team_when((string)$m['starts_at'], (int)$m['duration_min'])]);
    $body = "Reunião: " . $m['subject'] . "\nQuando: " . team_when((string)$m['starts_at'], (int)$m['duration_min']) . " (hora de Lisboa)\n"
          . "Com: " . $m['requester_name'] . ' e ' . $m['invitee_name'] . "\n" . ($m['deal_code'] ? 'Negócio: ' . $m['deal_code'] . ' · ' . $m['deal_name'] . "\n" : '')
          . MEETING_PLATFORMS[$m['platform']] . ($where !== '' ? ": $where" : '') . "\n\nFicou na agenda dos dois como «Ocupado».\n\ntopper.\n";
    foreach ([$m['requester_email'], $m['invitee_email']] as $to) team_mail((string)$to, 'topper. — reunião: ' . $m['subject'], $body);
    team_chat_send($pdo, $by, (int)$m['requester_id'], '✓ Aceite · ' . team_when((string)$m['starts_at'], (int)$m['duration_min']) . ' · ' . ($m['platform'] === 'local' ? 'detalhes' : 'link') . ' enviado por email', null);
    // A tarefa da reunião guarda a plataforma e o local (§18.7).
    if (db_has_column($pdo, 'tasks', 'platform')) {
        $pdo->prepare("UPDATE tasks SET platform = ?, location = ? WHERE id IN (?, ?) AND platform = ''")
            ->execute([$m['platform'] === 'local' ? '' : $m['platform'], $m['platform'] === 'local' ? mb_substr($where, 0, 200) : '', $rt, $it]);
    }
    return ['Aceite: está na agenda dos dois e ' . ($m['platform'] === 'local' ? 'os detalhes seguiram' : 'o link seguiu') . ' por email.', [$rt, $it]];
}

function team_meeting_decline(PDO $pdo, array $m, int $by, string $note): string {
    if ((int)$m['invitee_id'] !== $by && (int)$m['requester_id'] !== $by) throw new RuntimeException('Este pedido não é seu.');
    if ($m['status'] !== 'pending') throw new RuntimeException('Este pedido já foi respondido.');
    $cancel = (int)$m['requester_id'] === $by;
    $pdo->prepare('UPDATE meetings SET status = ?, decided_at = ? WHERE id = ?')->execute([$cancel ? 'cancelled' : 'declined', now_php(), $m['id']]);
    $other = $cancel ? (int)$m['invitee_id'] : (int)$m['requester_id'];
    team_chat_send($pdo, $by, $other, ($cancel ? '✕ Pedido cancelado' : '✕ Recusado') . ' · ' . $m['subject'] . (trim($note) !== '' ? ' — ' . trim($note) : ''), null);
    return $cancel ? 'Pedido cancelado.' : 'Recusado. A outra pessoa vê no chat.';
}

/** «Propor outra hora»: o pedido fica como proposto e sai um novo, no sentido contrário. */
function team_meeting_propose(PDO $pdo, array $m, int $by, string $startLocal): int {
    if ((int)$m['invitee_id'] !== $by) throw new RuntimeException('Só a pessoa convidada propõe outra hora.');
    if ($m['status'] !== 'pending') throw new RuntimeException('Este pedido já foi respondido.');
    $pdo->prepare("UPDATE meetings SET status = 'proposed', decided_at = ? WHERE id = ?")->execute([now_php(), $m['id']]);
    return team_meeting_request($pdo, $by, (int)$m['requester_id'], $startLocal, (int)$m['duration_min'], (string)$m['subject'],
                                $m['deal_id'] ? (int)$m['deal_id'] : null, (string)$m['platform'], (string)$m['link'], '', (int)$m['id']);
}

/** Os pedidos por responder de $me (os que chegam durante o Foco ficam em espera até ao fim). */
function team_meetings_pending(PDO $pdo, array $me): array {
    if (team_focus_until($me)) return [];
    $st = $pdo->prepare("SELECT m.*, r.name AS requester_name FROM meetings m JOIN users r ON r.id = m.requester_id WHERE m.invitee_id = ? AND m.status = 'pending' ORDER BY m.starts_at");
    $st->execute([(int)$me['id']]);
    return $st->fetchAll();
}

// ── carga da equipa (§17.10) ────────────────────────────────

/**
 * A semana de cada pessoa: horas disponíveis (horário menos ausências) e horas «Ocupado»
 * (tarefas com hora). Não é registo de horas: serve para distribuir trabalho.
 */
function team_load(PDO $pdo, array $people, string $monday): array {
    $to = (new DateTimeImmutable($monday))->modify('+7 days')->format('Y-m-d');
    $out = [];
    foreach ($people as $u) {
        $wh = team_work_hours($u);
        $avail = 0.0; $busy = 0.0;
        $blocks = team_busy($pdo, $u, $monday, $to);
        for ($i = 0; $i < 7; $i++) {
            $d = (new DateTimeImmutable($monday))->modify("+$i days");
            $w = $wh[(int)$d->format('N')] ?? null;
            if (!$w) continue;
            $a = team_ts($d->format('Y-m-d') . ' ' . $w[0]);
            $b = team_ts($d->format('Y-m-d') . ' ' . $w[1]);
            $absent = false;
            foreach ($blocks as [$x, $y, $k]) if ($k === 'absence' && $x < $b && $y > $a) $absent = true;
            if ($absent) continue;
            $avail += ($b - $a) / 3600;
            foreach ($blocks as [$x, $y, $k]) {
                if (in_array($k, ['task', 'meeting'], true)) $busy += max(0, min($y, $b) - max($x, $a)) / 3600;
            }
        }
        $pct = $avail > 0 ? $busy / $avail * 100 : 0;
        $out[(int)$u['id']] = ['avail' => $avail, 'busy' => $busy, 'pct' => $pct, 'level' => $pct > 100 ? 'bad' : ($pct >= 80 ? 'warn' : 'ok')];
    }
    return $out;
}

// ── «Hoje na equipa» (§17.10) ───────────────────────────────

function team_today(PDO $pdo, array $people): array {
    $today = (new DateTimeImmutable('now', team_tz()))->format('Y-m-d');
    $out = ['absent' => [], 'meeting' => [], 'focus' => [], 'birthday' => []];
    $abs = [];
    foreach (team_absences_between($pdo, $today, $today) as $a) $abs[(int)$a['user_id']] = ABSENCE_KINDS[$a['kind']] ?? 'Ausente';
    foreach ($people as $u) {
        $id = (int)$u['id'];
        if (isset($abs[$id])) { $out['absent'][] = [$u, $abs[$id]]; continue; }
        if ($f = team_focus_until($u)) { $out['focus'][] = [$u, 'em foco até ' . lisbon_fmt($f, 'H:i')]; }
        if ($u['team_member_id']) {
            $st = $pdo->prepare("SELECT due_time, duration_min FROM tasks WHERE member_id = ? AND kind = 'meeting' AND busy = 1 AND status <> 'done' AND due_date = ? AND due_time IS NOT NULL");
            $st->execute([(int)$u['team_member_id'], $today]);
            foreach ($st->fetchAll() as $t) {
                $a = team_ts($today . ' ' . substr((string)$t['due_time'], 0, 5));
                $b = $a + 60 * (int)($t['duration_min'] ?: TEAM_TASK_DEFAULT_MIN);
                if ($a <= time() && time() < $b) { $out['meeting'][] = [$u, 'em reunião até ' . lisbon_fmt($b, 'H:i')]; break; }
            }
        }
        if ($u['birthday'] && substr((string)$u['birthday'], 5) === substr($today, 5)) $out['birthday'][] = [$u, 'faz anos hoje'];
    }
    return $out;
}

// ── formulários (contas e O meu perfil) ─────────────────────

/** O horário vindo de um formulário (wd[d], wa[d], wb[d]). Null = o padrão seg–sex 09:00–18:00. */
function team_work_parse(array $post): ?string {
    $out = [];
    foreach (TEAM_DAYS as $d => $_) {
        if (empty($post['wd'][$d])) continue;
        $a = (string)($post['wa'][$d] ?? '');
        $b = (string)($post['wb'][$d] ?? '');
        if (!preg_match('/^\d{2}:\d{2}$/', $a) || !preg_match('/^\d{2}:\d{2}$/', $b) || $a >= $b) {
            throw new RuntimeException('Horário de ' . mb_strtolower(TEAM_DAYS_LONG[$d]) . ': o fim tem de ser depois do início.');
        }
        $out[$d] = [$a, $b];
    }
    if ($out == TEAM_WORK_DEFAULT) return null;
    return $out ? json_encode($out) : '{}';
}

/** Um número escrito à portuguesa («12,5»), entre 0 e $max; vazio = null. */
function team_num(string $raw, float $max): ?float {
    $raw = trim(str_replace([' ', '€', 'h'], '', $raw));
    if ($raw === '') return null;
    $raw = str_contains($raw, ',') ? str_replace(['.', ','], ['', '.'], $raw) : $raw;
    if (!is_numeric($raw)) throw new RuntimeException('Número inválido: ' . $raw . '.');
    return max(0, min($max, round((float)$raw, 2)));
}

/** As competências: lista separada por vírgulas, sem repetidos. */
function team_skills_parse(string $raw): string {
    $out = [];
    foreach (preg_split('/[,;\n]+/', $raw) as $s) {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if ($s !== '' && !in_array(mb_strtolower($s), array_map('mb_strtolower', $out), true)) $out[] = mb_substr($s, 0, 40);
    }
    return mb_substr(implode(', ', array_slice($out, 0, 20)), 0, 500);
}

/**
 * Os campos de uma conta vindos do formulário de Utilizadores e acessos (§17.8), validados.
 * Custo/hora e horas/mês só chegam aqui por admins (a página já o exige).
 */
function team_user_input(PDO $pdo, array $actor, ?array $editing, array $post): array {
    $name  = trim((string)($post['name'] ?? ''));
    $email = strtolower(trim((string)($post['email'] ?? '')));
    if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Indique o nome (até 120 caracteres).');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new RuntimeException('Email inválido.');
    $role = (string)($post['role'] ?? 'member');
    if ($email === SUPERADMIN_EMAIL) {
        // A conta de superadmin (info@) cria-se uma vez, por um admin, se ainda não existir.
        if ($editing || (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn() > 0) {
            throw new RuntimeException('Esse email é reservado ao superadmin.');
        }
        $role = 'superadmin';
    } elseif (!in_array($role, assignable_roles($actor), true)) {
        throw new RuntimeException('Só o superadmin dá o papel de admin.');
    }
    $st = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? AND id <> ?');
    $st->execute([$email, (int)($editing['id'] ?? 0)]);
    if ($st->fetchColumn() !== false) throw new RuntimeException('Já existe uma conta com esse email.');

    $depts = team_depts($pdo);
    $dept = (string)($post['department_id'] ?? '') === '' ? null : (int)$post['department_id'];
    if ($dept !== null && !isset($depts[$dept])) throw new RuntimeException('Departamento inválido.');

    $rep = (string)($post['reports_to'] ?? '') === '' ? null : (int)$post['reports_to'];
    if ($rep !== null) {
        $chain = $rep; $seen = [];
        $st = $pdo->prepare('SELECT id, reports_to, is_active FROM users WHERE id = ?');
        while ($chain && !isset($seen[$chain])) {
            if ($editing && $chain === (int)$editing['id']) throw new RuntimeException('«Responde a» faria um círculo (a pessoa ficaria a responder a si própria).');
            $seen[$chain] = true;
            $st->execute([$chain]);
            $r = $st->fetch();
            if (!$r) throw new RuntimeException('«Responde a» inválido.');
            $chain = $r['reports_to'] !== null ? (int)$r['reports_to'] : 0;
        }
    }
    $pos = array_values(array_intersect(array_map('intval', (array)($post['positions'] ?? [])), array_keys(team_positions($pdo, true))));
    $color = (string)($post['color'] ?? '');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = $editing['color'] ?? '#81CFE3';

    return [
        'row' => ['name' => $name, 'email' => $email, 'role' => $role, 'department_id' => $dept, 'reports_to' => $rep,
                  'is_partner' => empty($post['is_partner']) ? 0 : 1, 'color' => strtoupper($color),
                  'hourly_cost' => team_num((string)($post['hourly_cost'] ?? ''), 10000), 'monthly_hours' => team_num((string)($post['monthly_hours'] ?? ''), 744),
                  'work_hours' => team_work_parse($post)],
        'positions' => $pos,
    ];
}

function team_set_positions(PDO $pdo, int $uid, array $ids): void {
    $pdo->prepare('DELETE FROM user_positions WHERE user_id = ?')->execute([$uid]);
    $ins = $pdo->prepare('INSERT INTO user_positions (user_id, position_id) VALUES (?, ?)');
    foreach (array_unique($ids) as $p) $ins->execute([$uid, (int)$p]);
}

/** Onde ficam as fotos de perfil: dentro de inc/, que nunca é servido diretamente. */
function team_avatar_dir(): string {
    $d = __DIR__ . '/avatars';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

/** Guarda a foto (JPEG/PNG/WebP até 5 MB) recortada ao quadrado, 256 px. Devolve o nome do ficheiro. */
function team_photo_save(int $uid, array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('A foto não chegou. Tente outra vez.');
    if ($file['size'] > 5 * 1024 * 1024) throw new RuntimeException('A foto pode ter até 5 MB.');
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
    if (!$info || !isset($types[$info[2]])) throw new RuntimeException('Use uma foto JPG, PNG ou WebP.');
    $name = 'u' . $uid . '-' . bin2hex(random_bytes(6)) . '.jpg';
    $dest = team_avatar_dir() . '/' . $name;
    $fn = $types[$info[2]];
    if (function_exists($fn) && function_exists('imagecreatetruecolor') && ($src = @$fn($file['tmp_name']))) {
        [$w, $h] = [imagesx($src), imagesy($src)];
        $s = min($w, $h);
        $out = imagecreatetruecolor(256, 256);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $src, 0, 0, (int)(($w - $s) / 2), (int)(($h - $s) / 2), 256, 256, $s, $s);
        imagejpeg($out, $dest, 86);
        imagedestroy($src); imagedestroy($out);
    } elseif ($info[2] === IMAGETYPE_JPEG) {
        if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('Não foi possível guardar a foto.');
    } else {
        throw new RuntimeException('Este servidor só aceita fotos JPG.');
    }
    return $name;
}

function team_photo_delete(?string $name): void {
    if ($name && preg_match('/^u\d+-[0-9a-f]{12}\.jpg$/', $name)) @unlink(team_avatar_dir() . '/' . $name);
}

// ── «Anular» (§11.10) ───────────────────────────────────────

/** Chamado por undo_apply (inc/undo.php). */
function team_undo_apply(PDO $pdo, array $d): string {
    if (!team_is_admin($pdo) && empty($d['self'])) throw new RuntimeException('Sem permissão.');
    switch ($d['what']) {
        case 'user':
            // Campos de uma conta (e os cargos) como estavam.
            $u = $d['row'];
            $cols = array_keys($u);
            $pdo->prepare('UPDATE users SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')->execute([...array_values($u), (int)$d['id']]);
            if (isset($d['positions'])) {
                $pdo->prepare('DELETE FROM user_positions WHERE user_id = ?')->execute([(int)$d['id']]);
                foreach ($d['positions'] as $pid) $pdo->prepare('INSERT INTO user_positions (user_id, position_id) VALUES (?, ?)')->execute([(int)$d['id'], (int)$pid]);
            }
            team_log($pdo, (int)$d['id'], 'edit', 'Anulado');
            return $d['msg'] ?? 'Reposto.';
        case 'absence':
            $pdo->prepare('UPDATE absences SET status = ?, decided_by = ?, decided_at = ? WHERE id = ?')->execute([$d['status'], $d['decided_by'], $d['decided_at'], (int)$d['id']]);
            return 'Decisão anulada: o pedido volta a estar por aprovar.';
        case 'opening':
            if (!empty($d['delete'])) $pdo->prepare('DELETE FROM team_openings WHERE id = ?')->execute([(int)$d['delete']]);
            if (!empty($d['row'])) {
                $r = $d['row']; $c = array_keys($r);
                $pdo->prepare('INSERT INTO team_openings (' . implode(',', $c) . ') VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($r));
            }
            return 'Reposto.';
    }
    throw new RuntimeException('Já não é possível anular.');
}
