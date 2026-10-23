<?php
/**
 * topper. admin — aba Marketing (§16): o motor de conteúdo para Instagram, LinkedIn e X
 *
 * Uma peça nasce como ideia (Ideias: nova · guardada · descartada) e, ao
 * avançar, entra em Produção (Ideia aprovada → Guião → Gravação → Edição →
 * Revisão → Agendado → Publicado). Cada peça tem uma versão por rede, uma
 * publicação por rede (com o link com rastreio e as métricas), pasta no
 * Drive em Marketing/AAAA-MM/<peça>/ e histórico.
 *
 * Regras que só o servidor decide (§16.3, §16.5):
 *   - aprovar na Revisão: só superadmin e admins (o papel lê-se da base);
 *   - Agendado e Publicado pedem a peça aprovada e, se estiver ligada a um
 *     negócio, que esse negócio possa ser divulgado nessa data;
 *   - depois de aprovada, os textos das redes ficam fechados (voltar a
 *     Edição reabre e pede nova aprovação).
 * A IA (Claude, inc/ai.php) só propõe: uma pessoa escolhe o que usar.
 * A publicação é assistida (fase 1); a fase 2 (APIs) fica preparada em
 * mkt_accounts e nas colunas external_id / sync_source das publicações.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/leads.php';          // now_php, lisbon_fmt, member_for_user, LEAD_ORIGIN
require_once __DIR__ . '/interactions.php';   // HISTORY_TYPES, timeline_html

const MKT_NETWORKS = [
    'ig' => ['Instagram', 'IG', 'instagram'],
    'li' => ['LinkedIn',  'in', 'linkedin'],
    'x'  => ['X',         'X',  'xlogo'],
];
const MKT_FORMATS = ['reel' => 'Reel', 'carrossel' => 'Carrossel', 'post' => 'Post', 'thread' => 'Thread', 'video' => 'Vídeo longo'];
const MKT_IDEA_STATUS = ['nova' => 'Nova', 'guardada' => 'Guardada', 'descartada' => 'Descartada', 'producao' => 'Em produção'];
const MKT_STAGES = [
    'aprovada' => 'Ideia aprovada',
    'guiao'    => 'Guião',
    'gravacao' => 'Gravação',
    'edicao'   => 'Edição',
    'revisao'  => 'Revisão',
    'agendado' => 'Agendado',
    'publicado'=> 'Publicado',
];
const MKT_SOURCES = ['manual' => 'Manual', 'portfolio' => 'Do portfólio de um negócio', 'repeat' => 'Parte 2 / repetição'];
const MKT_PILLAR_COLORS = ['blue' => 'Azul', 'orange' => 'Laranja', 'green' => 'Verde', 'sky' => 'Céu', 'coral' => 'Coral', 'yellow' => 'Amarelo', 'neutral' => 'Cinzento'];
const MKT_X_LIMIT = 280;
const MKT_TABS = ['ideias' => 'Ideias', 'estudio' => 'Estúdio', 'producao' => 'Produção', 'calendario' => 'Calendário', 'desempenho' => 'Desempenho'];

/**
 * Modelos de formato (§16.4): nome, pilar de partida (posição), formato, redes,
 * checklist da peça, material para o dia de gravação e a pista para a IA.
 */
const MKT_TEMPLATES = [
    'breakdown' => ['Breakdown VFX (antes/depois)', 1, 'reel', 'ig,li,x',
        ['Escolher o plano final e o plano original', 'Exportar antes/depois lado a lado', 'Legendar as camadas ou passos', 'Versão vertical 9:16', 'Capa / thumbnail'],
        ['Render final e plates originais', 'Capturas do software (camadas, nós)'],
        'Mostra o plano original e o final, e 2–4 passos do processo. O gancho promete a transformação.'],
    'bastidores' => ['Bastidores', 2, 'reel', 'ig,li',
        ['Combinar o dia de gravação', 'Planos gerais e de detalhe', 'Escolher música', 'Montagem curta (até 30 s)'],
        ['Câmara ou telemóvel + estabilizador', 'Microfone de lapela', 'Luz portátil'],
        'Conta o que acontece por trás do trabalho, com um momento humano.'],
    'dica' => ['Dica técnica', 3, 'carrossel', 'ig,li,x',
        ['Escolher uma dica concreta', 'Exemplo visual antes/depois', 'Slides com texto curto', 'Revisão técnica por um artista'],
        ['Capturas de ecrã', 'Ficheiro de exemplo'],
        'Uma dica prática e verificável, explicada de forma simples, com um exemplo.'],
    'caso' => ['Caso de cliente', 4, 'carrossel', 'li,ig',
        ['Problema, solução e resultado', 'Números do resultado (se houver)', 'Citação do cliente (testemunho)'],
        ['Imagens finais', 'Testemunho do cliente'],
        'Estrutura problema → solução → resultado. Só números e citações que estejam na ideia.'],
    'antes3d' => ['Antes e depois 3D', 1, 'reel', 'ig,x',
        ['Render final', 'Viewport / wireframe', 'Transição antes → depois', 'Versões 1:1 e 9:16'],
        ['Ficheiros 3D', 'Renders'],
        'Do wireframe ao render final; o gancho é a revelação.'],
    'servico' => ['Apresentação de serviço', 5, 'video', 'li,ig',
        ['Para quem é o serviço', 'Três benefícios', 'Exemplo real', 'CTA para contacto'],
        ['Trabalhos de exemplo', 'Logótipo e grafismo'],
        'Para quem é, o que resolve, um exemplo real e um convite claro a falar connosco.'],
    'pessoas' => ['Pessoas da topper.', 6, 'reel', 'ig,li',
        ['Escolher a pessoa e combinar', 'Perguntas da entrevista', 'Gravação (10–15 min)', 'Autorização de imagem', 'Montagem com legendas'],
        ['Câmara + tripé', 'Microfone de lapela', 'Luz', 'Autorização de imagem assinada'],
        'Employer branding: a pessoa, o projeto de topo em que trabalha, o que aprendeu e porque gosta de trabalhar aqui. Quem vê deve querer trabalhar na topper.'],
    'dia' => ['Um dia na topper.', 6, 'reel', 'ig,li,x',
        ['Guião da chegada à saída', 'Planos: chegada, briefing, render, almoço, review, saída', 'Autorização de imagem de quem aparece', 'Montagem 30–45 s com legendas'],
        ['Telemóvel ou câmara + gimbal', 'Microfone', 'Lista de planos do dia'],
        'Employer branding: um dia real, trabalho de topo sem perder a vida. Quem vê deve querer trabalhar na topper.'],
];

/** Ações da IA no Estúdio (§16.4): nome do botão, precisa de rede? */
const MKT_AI_ACTIONS = [
    'adapt'    => ['Adaptar às redes', false],
    'hooks'    => ['5 ganchos', false],
    'script'   => ['Guião cena a cena', false],
    'shorten'  => ['Encurtar', true],
    'lengthen' => ['Alongar', true],
    'tone'     => ['Mudar o tom', true],
    'cta'      => ['Sugerir CTA', true],
    'thread'   => ['Criar thread', false],
    'hashtags' => ['Hashtags', true],
];
const MKT_TONES = ['descontraido' => 'mais descontraído', 'profissional' => 'mais profissional', 'entusiasmado' => 'mais entusiasmado', 'tecnico' => 'mais técnico', 'curto' => 'mais direto'];

// ── leitura ─────────────────────────────────────────────────

function mkt_pillars(PDO $pdo, bool $activeOnly = true): array {
    $rows = $pdo->query('SELECT * FROM mkt_pillars' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY position, id')->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[(int)$r['id']] = $r;
    return $out;
}

/** Uma peça com o pilar, o responsável e o negócio ligado (e a autorização de divulgação). */
function mkt_piece(PDO $pdo, int $id): ?array {
    $r = mkt_pieces_where($pdo, 'p.id = ?', [$id]);
    return $r[0] ?? null;
}

function mkt_pieces_where(PDO $pdo, string $where, array $args = [], string $order = 'p.id DESC'): array {
    $st = $pdo->prepare(
        "SELECT p.*, pl.name AS pillar_name, pl.short_name AS pillar_short, pl.color AS pillar_color, pl.kind AS pillar_kind,
                o.name AS owner_name, o.color AS owner_color, a.name AS author_name,
                d.code AS deal_code, d.name AS deal_name, d.disclose, d.disclose_from, d.drive_folder AS deal_drive,
                sh.day AS shoot_day, sh.title AS shoot_title,
                (SELECT COUNT(*) FROM mkt_votes v WHERE v.piece_id = p.id) AS votes
           FROM mkt_pieces p
           LEFT JOIN mkt_pillars pl ON pl.id = p.pillar_id
           LEFT JOIN users o ON o.id = p.owner_id
           LEFT JOIN users a ON a.id = p.author_id
           LEFT JOIN deals d ON d.id = p.deal_id
           LEFT JOIN mkt_shoots sh ON sh.id = p.shoot_id
          WHERE $where ORDER BY $order");
    $st->execute($args);
    return $st->fetchAll();
}

/** As redes da peça, pela ordem de MKT_NETWORKS. */
function mkt_nets(array $p): array {
    $have = array_filter(explode(',', (string)$p['networks']));
    return array_values(array_filter(array_keys(MKT_NETWORKS), fn($n) => in_array($n, $have, true)));
}

function mkt_checklist(array $p): array {
    $c = json_decode((string)$p['checklist'], true);
    return is_array($c) ? array_values(array_filter($c, fn($x) => is_array($x) && isset($x['t']))) : [];
}

function mkt_versions(PDO $pdo, int $pieceId): array {
    $st = $pdo->prepare('SELECT * FROM mkt_versions WHERE piece_id = ?');
    $st->execute([$pieceId]);
    $out = [];
    foreach ($st->fetchAll() as $v) $out[$v['network']] = $v;
    return $out;
}

/** As publicações da peça, por rede (com os cliques no link com rastreio). */
function mkt_posts(PDO $pdo, int $pieceId): array {
    $st = $pdo->prepare('SELECT mp.*, u.name AS publisher_name, (SELECT COUNT(*) FROM mkt_clicks c WHERE c.post_id = mp.id) AS tracked
                           FROM mkt_posts mp LEFT JOIN users u ON u.id = mp.publisher_id WHERE mp.piece_id = ?');
    $st->execute([$pieceId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['network']] = $r;
    return $out;
}

/** As pessoas a quem se pode dar uma peça (utilizadores ativos com acesso à Marketing). */
function mkt_people(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query('SELECT * FROM users WHERE is_active = 1 ORDER BY name')->fetchAll() as $u) {
        if (perm_grid($pdo, $u)['marketing']['level'] >= PERM_VIEW) $out[(int)$u['id']] = $u;
    }
    return $out;
}

/** Superadmin ou admin, lido na base (não só na sessão): só estes aprovam na Revisão (§16.5). */
function mkt_can_approve(PDO $pdo): bool {
    $st = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ?');
    $st->execute([(int)(current_user()['id'] ?? 0)]);
    $r = $st->fetch();
    return $r && (int)$r['is_active'] === 1 && role_is_admin((string)$r['role']);
}

function mkt_setting(PDO $pdo, string $k, string $default = ''): string {
    $st = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? $default : (string)$v;
}

function mkt_setting_set(PDO $pdo, string $k, string $v): void {
    $pdo->prepare(db_is_sqlite($pdo)
        ? 'INSERT OR REPLACE INTO settings (skey, svalue) VALUES (?, ?)'
        : 'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute([$k, $v]);
}

/** Cadência alvo por semana (§16.6): rede → [mínimo, máximo]. */
function mkt_targets(PDO $pdo): array {
    $n = fn(string $k, int $d) => max(0, min(99, (int)mkt_setting($pdo, $k, (string)$d)));
    $igMin = $n('mkt_target_ig_min', 2);
    return ['ig' => [$igMin, max($igMin, $n('mkt_target_ig_max', 3))], 'li' => [$n('mkt_target_li', 5), $n('mkt_target_li', 5)], 'x' => [$n('mkt_target_x', 10), $n('mkt_target_x', 10)]];
}

function mkt_target_label(array $t): string {
    return $t[0] === $t[1] ? (string)$t[0] : $t[0] . '–' . $t[1];
}

/** A voz da topper. (Centro de Conhecimento), usada sempre pela IA. */
function mkt_voice(PDO $pdo): string {
    return trim(mkt_setting($pdo, 'mkt_voice'));
}

// ── divulgação (§16.3) ──────────────────────────────────────

/** «Pode ser divulgado?» do negócio: ['key' => yes|no|from|unset, 'label', 'from'] */
function mkt_disclose_state(?string $disclose, ?string $from): array {
    return match ($disclose) {
        'yes'  => ['key' => 'yes', 'label' => 'Pode ser divulgado', 'from' => null],
        'no'   => ['key' => 'no', 'label' => 'Não pode ser divulgado', 'from' => null],
        'from' => ['key' => 'from', 'label' => 'Pode ser divulgado a partir de ' . ($from ? date('d/m/Y', strtotime($from)) : '—'), 'from' => $from],
        default => ['key' => 'unset', 'label' => 'Divulgação por decidir', 'from' => null],
    };
}

/** O negócio pode ser divulgado na data (aaaa-mm-dd)? */
function mkt_disclose_ok(?string $disclose, ?string $from, string $date): bool {
    if ($disclose === 'yes') return true;
    if ($disclose === 'from' && $from) return $date >= substr($from, 0, 10);
    return false;
}

/**
 * Porque é que a peça não pode ser agendada/publicada nesta data (null = pode).
 * Aprovação na Revisão e autorização do negócio ligado.
 */
function mkt_block_reason(array $p, string $date): ?string {
    if ($p['review_state'] !== 'approved') return 'A peça ainda não foi aprovada na Revisão (aprovam superadmin e admins).';
    if ($p['deal_id'] && !mkt_disclose_ok($p['disclose'], $p['disclose_from'], $date)) {
        $s = mkt_disclose_state($p['disclose'], $p['disclose_from']);
        return 'O negócio ' . $p['deal_code'] . ' não pode ser divulgado em ' . date('d/m/Y', strtotime($date)) . ' («' . $s['label'] . '»). '
             . 'Mude «Pode ser divulgado?» na ficha do negócio.';
    }
    return null;
}

// ── escrita ─────────────────────────────────────────────────

function mkt_log(PDO $pdo, int $pieceId, string $event, string $note = '', ?int $by = null): void {
    $pdo->prepare('INSERT INTO mkt_log (piece_id, event, note, user_id, created_at) VALUES (?,?,?,?,?)')
        ->execute([$pieceId, $event, mb_substr($note, 0, 255), $by ?? (current_user()['id'] ?? null), now_php()]);
}

/** Cada mudança à peça sobe a revisão (o «Anular» confirma que ninguém mexeu entretanto). */
function mkt_touch(PDO $pdo, int $id): void {
    $pdo->prepare('UPDATE mkt_pieces SET rev = rev + 1, updated_at = ? WHERE id = ?')->execute([now_php(), $id]);
}

/** Os campos de uma ideia/peça vindos de um formulário. Só o texto é obrigatório. */
function mkt_input(PDO $pdo, array $src, array $people = []): array {
    $txt = static fn(string $k, int $n) => mb_substr(trim((string)($src[$k] ?? '')), 0, $n);
    $idea = $txt('idea', 6000);
    $title = $txt('title', 200);
    if ($title === '' && $idea !== '') $title = mb_substr(preg_split('/\R/u', $idea)[0], 0, 120);
    if ($title === '') throw new RuntimeException('Escreva a ideia.');
    $pillars = mkt_pillars($pdo, false);
    $pillar = (int)($src['pillar_id'] ?? 0);
    $format = (string)($src['format'] ?? '');
    $tpl = (string)($src['template'] ?? '');
    $nets = array_values(array_intersect(array_keys(MKT_NETWORKS), (array)($src['networks'] ?? [])));
    $owner = (int)($src['owner_id'] ?? 0);
    $due = (string)($src['due_date'] ?? '');
    return [
        'title'    => $title,
        'idea'     => $idea,
        'pillar_id'=> isset($pillars[$pillar]) ? $pillar : null,
        'format'   => isset(MKT_FORMATS[$format]) ? $format : '',
        'template' => isset(MKT_TEMPLATES[$tpl]) ? $tpl : '',
        'networks' => implode(',', $nets),
        'owner_id' => $people && isset($people[$owner]) ? $owner : null,
        'due_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null,
    ];
}

/** O pilar de partida de um modelo: o ativo nessa posição (ou o de employer branding, para os modelos de pessoas). */
function mkt_template_pillar(PDO $pdo, string $tpl): ?int {
    if (!isset(MKT_TEMPLATES[$tpl])) return null;
    $pos = MKT_TEMPLATES[$tpl][1];
    $pillars = mkt_pillars($pdo);
    if ($pos === 6) foreach ($pillars as $p) if ($p['kind'] === 'employer') return (int)$p['id'];
    foreach ($pillars as $p) if ((int)$p['position'] === $pos) return (int)$p['id'];
    return null;
}

/** «+ Ideia»: cria e devolve o id. Sem pilar/formato/redes escolhidos, o modelo (se houver) dá-os. */
function mkt_idea_create(PDO $pdo, array $v, int $by, string $source = 'manual', ?int $dealId = null, ?int $parentId = null): int {
    if ($v['template'] !== '') {
        $t = MKT_TEMPLATES[$v['template']];
        $v['pillar_id'] ??= mkt_template_pillar($pdo, $v['template']);
        if ($v['format'] === '') $v['format'] = $t[2];
        if ($v['networks'] === '') $v['networks'] = $t[3];
    }
    $now = now_php();
    $pdo->prepare('INSERT INTO mkt_pieces (title, idea, pillar_id, format, networks, template, status, source, author_id, owner_id, due_date, deal_id, parent_id, rev, created_at, updated_at)
                   VALUES (?,?,?,?,?,?,\'nova\',?,?,?,?,?,?,1,?,?)')
        ->execute([$v['title'], $v['idea'], $v['pillar_id'], $v['format'], $v['networks'], $v['template'], $source, $by, $v['owner_id'], $v['due_date'], $dealId, $parentId, $now, $now]);
    $id = (int)$pdo->lastInsertId();
    mkt_log($pdo, $id, 'm_create', MKT_SOURCES[$source] ?? '', $by);
    return $id;
}

/**
 * «Enviar para o portfólio» na ficha do negócio (§15.5 → §16.3): a ideia «Breakdown: <negócio>»,
 * ligada ao negócio (e aos ficheiros dele no Drive). Uma por negócio; devolve o id (null se já havia).
 */
function mkt_idea_from_deal(PDO $pdo, array $deal, int $by): ?int {
    $st = $pdo->prepare("SELECT id FROM mkt_pieces WHERE deal_id = ? AND source = 'portfolio' AND status <> 'descartada'");
    $st->execute([(int)$deal['id']]);
    if ($st->fetchColumn()) return null;
    $v = ['title' => 'Breakdown: ' . $deal['name'], 'idea' => 'Breakdown do projeto ' . $deal['code'] . ' · ' . $deal['name']
            . ': mostrar o antes/depois e como foi feito. Material na pasta do negócio no Drive.',
          'pillar_id' => null, 'format' => '', 'template' => 'breakdown', 'networks' => '', 'owner_id' => null, 'due_date' => null];
    return mkt_idea_create($pdo, $v, $by, 'portfolio', (int)$deal['id']);
}

/** Uma peça com bom desempenho → ideia de parte 2 (§16.3). */
function mkt_idea_repeat(PDO $pdo, array $p, int $by): int {
    $v = ['title' => 'Parte 2: ' . preg_replace('/^Parte \d+: /u', '', (string)$p['title']),
          'idea' => 'Continuação de «' . $p['title'] . '», que correu bem. ' . (string)$p['idea'],
          'pillar_id' => $p['pillar_id'] !== null ? (int)$p['pillar_id'] : null, 'format' => (string)$p['format'],
          'template' => (string)$p['template'], 'networks' => (string)$p['networks'], 'owner_id' => null, 'due_date' => null];
    $v['idea'] = mb_substr($v['idea'], 0, 6000);
    return mkt_idea_create($pdo, $v, $by, 'repeat', $p['deal_id'] !== null ? (int)$p['deal_id'] : null, (int)$p['id']);
}

/** O código do link com rastreio: 8 letras e números, único. */
function mkt_new_code(PDO $pdo): string {
    $abc = 'abcdefghijkmnpqrstuvwxyz23456789';
    $st = $pdo->prepare('SELECT COUNT(*) FROM mkt_posts WHERE code = ?');
    do {
        $c = '';
        for ($i = 0; $i < 8; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)];
        $st->execute([$c]);
    } while ((int)$st->fetchColumn() > 0);
    return $c;
}

/** Uma publicação (com o seu link com rastreio) por cada rede da peça; as que saíram e ainda não tinham data vão-se. */
function mkt_sync_posts(PDO $pdo, array $p): void {
    $nets = mkt_nets($p);
    $have = mkt_posts($pdo, (int)$p['id']);
    $ins = $pdo->prepare('INSERT INTO mkt_posts (piece_id, network, code, created_at) VALUES (?,?,?,?)');
    foreach ($nets as $n) if (!isset($have[$n])) $ins->execute([(int)$p['id'], $n, mkt_new_code($pdo), now_php()]);
    foreach ($have as $n => $post) {
        if (!in_array($n, $nets, true) && !$post['scheduled_at'] && !$post['published_at']) {
            $pdo->prepare('DELETE FROM mkt_posts WHERE id = ?')->execute([$post['id']]);
        }
    }
}

/** O endereço do site (para os links com rastreio). */
function mkt_site_base(): string {
    global $CONFIG;
    $u = rtrim((string)($CONFIG['site_url'] ?? ''), '/');
    if (preg_match('~^https?://[A-Za-z0-9.-]+(:\d+)?$~', $u)) return $u;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (preg_match('~^(localhost|127\.0\.0\.1)(:\d+)?$~', $host)) return 'http://' . $host;
    return 'https://topperdigital.xyz';
}

function mkt_track_url(string $code): string {
    return mkt_site_base() . '/go.php?c=' . rawurlencode($code);
}

/**
 * A pasta da peça no Drive (§16.5, §10.15): Marketing/AAAA-MM/<peça>/, com o mês em que entrou em
 * Produção; uma peça de um negócio fica com ele, em «<pasta do negócio>/Marketing/<peça>/» (e muda
 * de sítio com a pasta do negócio). Com $create, cria-a (e guarda a ligação). Sem o Drive: null.
 */
function mkt_drive_folder(PDO $pdo, array $p, bool $create = true): ?string {
    if ((string)$p['drive_folder'] !== '') return (string)$p['drive_folder'];
    require_once __DIR__ . '/drive.php';
    if (!function_exists('r2_config') || !r2_config()) return null;
    $month = lisbon_fmt(strtotime((string)($p['advanced_at'] ?: now_php())), 'Y-m');
    $slug = substr(drive_slug((string)$p['title']), 0, 60) ?: 'peca';
    $path = 'Marketing/' . $month . '/' . $slug . '/';
    if (!empty($p['deal_id'])) {
        require_once __DIR__ . '/files.php';
        $deal = files_deal_row($pdo, (int)$p['deal_id']);
        $df = $deal ? files_folder($pdo, $deal, $create) : null;
        if ($df !== null) $path = $df . 'Marketing/' . $slug . '/';
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM mkt_pieces WHERE drive_folder = ? AND id <> ?');
    $st->execute([$path, (int)$p['id']]);
    if ((int)$st->fetchColumn() > 0) $path = substr($path, 0, -1) . '-' . (int)$p['id'] . '/';
    if (!$create) return null;
    if (!drive_folder_exists($path)) {
        r2_mkdir_p($path);
        drive_catalog_invalidate();
    }
    $pdo->prepare('UPDATE mkt_pieces SET drive_folder = ? WHERE id = ?')->execute([$path, (int)$p['id']]);
    return $path;
}

/** Uma tarefa ligada à peça (aparece também na aba Tarefas). $due aaaa-mm-dd, $time hh:mm ou null. */
function mkt_task_create(PDO $pdo, array $p, ?int $userId, string $title, string $notes, ?string $due, ?string $time = null, ?int $by = null): int {
    require_once __DIR__ . '/tasks.php';
    $member = member_for_user($pdo, $userId);
    $now = task_now();
    $pdo->prepare(
        "INSERT INTO tasks (title, description, member_id, job_name, deal_id, mkt_piece_id, due_date, due_time, kind, priority, status, position, created_at, updated_at, created_by)
         VALUES (?,?,?,'Marketing',?,?,?,?,'task','media','todo',?,?,?,?)"
    )->execute([mb_substr($title, 0, 200), $notes, $member, $p['deal_id'] !== null ? (int)$p['deal_id'] : null, (int)$p['id'], $due,
                $time ? $time . ':00' : null, task_next_position($pdo, $member), $now, $now, $by ?? (current_user()['id'] ?? null)]);
    return (int)$pdo->lastInsertId();
}

/** Uma tarefa por fazer sai (agendamento anulado, publicação feita…). */
function mkt_task_close(PDO $pdo, ?int $taskId, bool $done): void {
    if (!$taskId) return;
    if ($done) {
        $pdo->prepare("UPDATE tasks SET status = 'done', done_at = ?, updated_at = ? WHERE id = ? AND status <> 'done'")->execute([now_php(), now_php(), $taskId]);
    } else {
        $pdo->prepare("DELETE FROM tasks WHERE id = ? AND status <> 'done'")->execute([$taskId]);
    }
}

// ── «Anular» (§11.10) ───────────────────────────────────────

/** O estado da peça e das suas publicações, para o poder repor. */
function mkt_snapshot(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT * FROM mkt_pieces WHERE id = ?');
    $st->execute([$id]);
    $piece = $st->fetch() ?: [];
    $st = $pdo->prepare('SELECT * FROM mkt_posts WHERE piece_id = ?');
    $st->execute([$id]);
    $posts = $st->fetchAll();
    $v = $pdo->prepare('SELECT * FROM mkt_versions WHERE piece_id = ?');
    $v->execute([$id]);
    $t = $pdo->prepare('SELECT * FROM tasks WHERE mkt_piece_id = ?');
    $t->execute([$id]);
    return ['piece' => $piece, 'posts' => $posts, 'versions' => $v->fetchAll(), 'tasks' => $t->fetchAll()];
}

/** O código do «Anular» de uma mudança à peça: repõe o estado de $snap se ninguém mexeu depois. */
function mkt_undo_push(PDO $pdo, array $snap, string $msg, array $extra = []): string {
    require_once __DIR__ . '/undo.php';
    $id = (int)$snap['piece']['id'];
    $rev = (int)$pdo->query('SELECT rev FROM mkt_pieces WHERE id = ' . $id)->fetchColumn();
    return undo_push('mkt', $extra + ['snap' => $snap, 'rev' => $rev, 'msg' => $msg]);
}

/** Chamado por undo_apply (inc/undo.php). */
function mkt_undo_apply(PDO $pdo, array $d): string {
    require_perm('marketing', PERM_EDIT);
    $changed = 'A peça mudou entretanto; não foi anulada.';
    if (!empty($d['delete'])) {
        // Uma ideia acabada de criar sai, se ninguém mexeu nela.
        $id = (int)$d['delete'];
        $st = $pdo->prepare('SELECT rev FROM mkt_pieces WHERE id = ?');
        $st->execute([$id]);
        $rev = $st->fetchColumn();
        if ($rev === false || (int)$rev !== (int)$d['rev']) throw new RuntimeException($changed);
        foreach (['mkt_votes', 'mkt_versions', 'mkt_posts', 'mkt_log'] as $t) $pdo->prepare("DELETE FROM $t WHERE piece_id = ?")->execute([$id]);
        $pdo->prepare('DELETE FROM mkt_pieces WHERE id = ?')->execute([$id]);
        return $d['msg'] ?? 'Ideia anulada.';
    }
    $snap = $d['snap'];
    $id = (int)$snap['piece']['id'];
    $st = $pdo->prepare('SELECT rev FROM mkt_pieces WHERE id = ?');
    $st->execute([$id]);
    $rev = $st->fetchColumn();
    if ($rev === false || (int)$rev !== (int)$d['rev']) throw new RuntimeException($changed);
    $pdo->beginTransaction();
    try {
        $p = $snap['piece'];
        $cols = array_keys($p);
        $p['rev'] = (int)$rev + 1;
        $p['updated_at'] = now_php();
        $pdo->prepare('UPDATE mkt_pieces SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')
            ->execute([...array_map(fn($c) => $p[$c], $cols), $id]);
        // Publicações e tarefas da peça: as que a ação criou saem; as outras voltam ao que eram.
        mkt_restore_rows($pdo, 'mkt_posts', 'piece_id', $id, $snap['posts']);
        mkt_restore_rows($pdo, 'tasks', 'mkt_piece_id', $id, $snap['tasks'] ?? []);
        if (!empty($d['versions'])) mkt_restore_rows($pdo, 'mkt_versions', 'piece_id', $id, $snap['versions']);
        mkt_log($pdo, $id, 'm_stage', 'Anulado: ' . ($d['msg'] ?? ''));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $d['msg'] ?? 'Reposto.';
}

/** Repõe as linhas de uma tabela ligadas à peça tal como estavam (apaga as novas, volta a pôr as que saíram). */
function mkt_restore_rows(PDO $pdo, string $table, string $col, int $id, array $rows): void {
    $keep = array_map(fn($r) => (int)$r['id'], $rows);
    $pdo->prepare("DELETE FROM $table WHERE $col = ?" . ($keep ? ' AND id NOT IN (' . implode(',', $keep) . ')' : ''))->execute([$id]);
    $has = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE id = ?");
    foreach ($rows as $r) {
        $c = array_keys($r);
        $has->execute([(int)$r['id']]);
        if ((int)$has->fetchColumn()) {
            $pdo->prepare("UPDATE $table SET " . implode(', ', array_map(fn($x) => "$x = ?", $c)) . ' WHERE id = ?')->execute([...array_values($r), (int)$r['id']]);
        } else {
            $pdo->prepare("INSERT INTO $table (" . implode(',', $c) . ') VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($r));
        }
    }
}

// ── ideias: triagem e votos (§16.3) ─────────────────────────

/** Avançar (→ Produção, Ideia aprovada) · Guardar · Descartar · voltar a Nova. Devolve a mensagem. */
function mkt_triage(PDO $pdo, array $p, string $to, int $by): string {
    if (!isset(MKT_IDEA_STATUS[$to])) throw new RuntimeException('Escolha uma opção.');
    if ($p['status'] === 'producao') throw new RuntimeException('Esta ideia já está em Produção.');
    if ($to === 'producao') {
        $check = [];
        if ($p['template'] !== '') foreach (MKT_TEMPLATES[$p['template']][4] as $t) $check[] = ['t' => $t, 'done' => false];
        if ($p['deal_id']) $check[] = ['t' => 'Confirmar «Pode ser divulgado?» no negócio ' . $p['deal_code'], 'done' => false];
        $nets = $p['networks'] !== '' ? $p['networks'] : 'ig,li,x';
        $pdo->prepare("UPDATE mkt_pieces SET status = 'producao', stage = 'aprovada', advanced_at = ?, networks = ?, owner_id = COALESCE(owner_id, ?),
                              checklist = COALESCE(checklist, ?) WHERE id = ?")
            ->execute([now_php(), $nets, $p['author_id'] ?: $by, $check ? json_encode($check, JSON_UNESCAPED_UNICODE) : null, (int)$p['id']]);
        mkt_touch($pdo, (int)$p['id']);
        $p = mkt_piece($pdo, (int)$p['id']);
        mkt_sync_posts($pdo, $p);
        try { mkt_drive_folder($pdo, $p); } catch (Throwable $e) { /* a pasta cria-se depois, ao abrir */ }
        mkt_log($pdo, (int)$p['id'], 'm_triage', 'Avançou para Produção', $by);
        return 'Avançou para Produção.';
    }
    $pdo->prepare('UPDATE mkt_pieces SET status = ? WHERE id = ?')->execute([$to, (int)$p['id']]);
    mkt_touch($pdo, (int)$p['id']);
    mkt_log($pdo, (int)$p['id'], 'm_triage', MKT_IDEA_STATUS[$to], $by);
    return ['nova' => 'Voltou às novas.', 'guardada' => 'Ideia guardada.', 'descartada' => 'Ideia descartada.'][$to];
}

/** Votar / tirar o voto. Devolve [votou?, total]. */
function mkt_vote(PDO $pdo, int $pieceId, int $userId): array {
    $st = $pdo->prepare('SELECT COUNT(*) FROM mkt_votes WHERE piece_id = ? AND user_id = ?');
    $st->execute([$pieceId, $userId]);
    if ((int)$st->fetchColumn()) {
        $pdo->prepare('DELETE FROM mkt_votes WHERE piece_id = ? AND user_id = ?')->execute([$pieceId, $userId]);
        $on = false;
    } else {
        $pdo->prepare('INSERT INTO mkt_votes (piece_id, user_id, created_at) VALUES (?,?,?)')->execute([$pieceId, $userId, now_php()]);
        $on = true;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM mkt_votes WHERE piece_id = ?');
    $st->execute([$pieceId]);
    return [$on, (int)$st->fetchColumn()];
}

// ── produção: etapas, revisão, agendar, publicar (§16.5–16.7) ──

/**
 * Mudar de etapa no kanban. Agendado precisa de datas (agendar), Publicado dos links (publicar);
 * entrar em Revisão pede aprovação; sair dela para trás tira a aprovação. Devolve [mensagem, tarefas criadas].
 */
function mkt_move(PDO $pdo, array $p, string $stage, int $by): array {
    if (!isset(MKT_STAGES[$stage])) throw new RuntimeException('Etapa desconhecida.');
    if ($p['status'] !== 'producao') throw new RuntimeException('Esta peça ainda é uma ideia: avance-a primeiro.');
    if ($p['stage'] === $stage) return ['Já estava em ' . MKT_STAGES[$stage] . '.', []];
    if ($p['stage'] === 'publicado') throw new RuntimeException('Já publicada: fica em Publicado (o histórico e as métricas ficam com ela).');
    $order = array_keys(MKT_STAGES);
    $back = array_search($stage, $order, true) < array_search($p['stage'], $order, true);
    if ($stage === 'agendado') throw new RuntimeException('Para agendar, escolha a data e a hora de cada rede.');
    if ($stage === 'publicado') throw new RuntimeException('Para marcar como publicada, cole o link da publicação de cada rede.');
    $review = $p['review_state'];
    $tasks = [];
    if ($stage === 'revisao') $review = 'pending';
    elseif ($back) $review = null;   // voltar atrás reabre os textos e pede nova aprovação
    // Sair de Agendado para trás: as datas e os lembretes por publicar saem.
    if ($p['stage'] === 'agendado' && $back) {
        foreach (mkt_posts($pdo, (int)$p['id']) as $post) {
            if ($post['published_at']) continue;
            mkt_task_close($pdo, $post['task_id'] !== null ? (int)$post['task_id'] : null, false);
            $pdo->prepare('UPDATE mkt_posts SET scheduled_at = NULL, task_id = NULL WHERE id = ?')->execute([$post['id']]);
        }
    }
    $pdo->prepare('UPDATE mkt_pieces SET stage = ?, review_state = ? WHERE id = ?')->execute([$stage, $review, (int)$p['id']]);
    mkt_touch($pdo, (int)$p['id']);
    mkt_log($pdo, (int)$p['id'], 'm_stage', MKT_STAGES[$p['stage']] . ' → ' . MKT_STAGES[$stage], $by);
    return [$stage === 'revisao' ? 'Em Revisão: aprovam superadmin e admins.' : 'Passou para ' . MKT_STAGES[$stage] . '.', $tasks];
}

/** Aprovar · Pedir alterações (com comentário). Só superadmin e admins. Devolve [mensagem, tarefas criadas]. */
function mkt_review(PDO $pdo, array $p, string $decision, string $note, int $by): array {
    if (!mkt_can_approve($pdo)) throw new RuntimeException('Só superadmin e admins aprovam na Revisão.');
    if ($p['stage'] !== 'revisao') throw new RuntimeException('A peça não está em Revisão.');
    $note = mb_substr(trim($note), 0, 2000);
    if ($decision === 'approve') {
        $pdo->prepare("UPDATE mkt_pieces SET review_state = 'approved', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?")
            ->execute([$by, now_php(), $note !== '' ? $note : null, (int)$p['id']]);
        // As alterações pedidas antes ficam feitas com a aprovação.
        $pdo->prepare("UPDATE tasks SET status = 'done', done_at = ?, updated_at = ? WHERE mkt_piece_id = ? AND status <> 'done' AND title LIKE 'Alterações:%'")
            ->execute([now_php(), now_php(), (int)$p['id']]);
        mkt_touch($pdo, (int)$p['id']);
        mkt_log($pdo, (int)$p['id'], 'm_approve', $note, $by);
        return ['Aprovada. Já pode ser agendada no Calendário.', []];
    }
    if ($decision !== 'changes') throw new RuntimeException('Escolha Aprovar ou Pedir alterações.');
    if ($note === '') throw new RuntimeException('Escreva o que é preciso mudar.');
    $pdo->prepare("UPDATE mkt_pieces SET stage = 'edicao', review_state = 'changes', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ?")
        ->execute([$by, now_php(), $note, (int)$p['id']]);
    mkt_touch($pdo, (int)$p['id']);
    mkt_log($pdo, (int)$p['id'], 'm_changes', $note, $by);
    $tid = mkt_task_create($pdo, $p, $p['owner_id'] !== null ? (int)$p['owner_id'] : $by, 'Alterações: ' . $p['title'], $note,
                           (new DateTimeImmutable(today_lisbon()))->modify('+1 day')->format('Y-m-d'), null, $by);
    return ['Alterações pedidas: voltou a Edição e o responsável tem uma tarefa.', [$tid]];
}

/**
 * Agendar as redes (data e hora de Lisboa): «aaaa-mm-dd hh:mm» por rede ('' tira a data).
 * Cria (ou muda) o lembrete de quem publica nesse dia. A peça passa a Agendado. Devolve [mensagem, tarefas criadas, tarefas fechadas].
 */
function mkt_schedule(PDO $pdo, array $p, array $when, int $by): array {
    if ($p['status'] !== 'producao') throw new RuntimeException('Esta peça ainda é uma ideia.');
    if ($p['stage'] === 'publicado') throw new RuntimeException('Já publicada.');
    mkt_sync_posts($pdo, $p);
    $posts = mkt_posts($pdo, (int)$p['id']);
    $created = $closed = [];
    $any = false;
    foreach ($when as $net => $raw) {
        if (!isset($posts[$net])) continue;
        $post = $posts[$net];
        if ($post['published_at']) continue;
        $raw = trim((string)$raw);
        if ($raw === '') {
            if ($post['scheduled_at']) {
                mkt_task_close($pdo, $post['task_id'] !== null ? (int)$post['task_id'] : null, false);
                if ($post['task_id']) $closed[] = (int)$post['task_id'];
                $pdo->prepare('UPDATE mkt_posts SET scheduled_at = NULL, task_id = NULL WHERE id = ?')->execute([$post['id']]);
            }
            continue;
        }
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})$/', $raw, $m)) throw new RuntimeException('Data ou hora inválida em ' . MKT_NETWORKS[$net][0] . '.');
        if ($why = mkt_block_reason($p, $m[1])) {
            mkt_log($pdo, (int)$p['id'], 'm_blocked', MKT_NETWORKS[$net][0] . ' · ' . $why, $by);
            throw new RuntimeException($why);
        }
        $at = $m[1] . ' ' . $m[2] . ':00';
        $who = $p['owner_id'] !== null ? (int)$p['owner_id'] : $by;
        if ($post['task_id']) { mkt_task_close($pdo, (int)$post['task_id'], false); $closed[] = (int)$post['task_id']; }
        $tid = mkt_task_create($pdo, $p, $who, 'Publicar no ' . MKT_NETWORKS[$net][0] . ': ' . $p['title'],
            "Publicação assistida (Marketing): copiar a legenda e o link com rastreio na peça, publicar o ficheiro final e colar o link da publicação.\n"
            . mkt_site_base() . '/admin/marketing_peca.php?id=' . (int)$p['id'], $m[1], $m[2], $by);
        $created[] = $tid;
        $pdo->prepare('UPDATE mkt_posts SET scheduled_at = ?, publisher_id = ?, task_id = ? WHERE id = ?')->execute([$at, $who, $tid, $post['id']]);
        mkt_log($pdo, (int)$p['id'], 'm_schedule', MKT_NETWORKS[$net][0] . ' · ' . date('d/m H:i', strtotime($at)), $by);
        $any = true;
    }
    $posts = mkt_posts($pdo, (int)$p['id']);
    $scheduled = array_filter($posts, fn($x) => $x['scheduled_at'] || $x['published_at']);
    $stage = $scheduled ? 'agendado' : ($p['stage'] === 'agendado' ? 'revisao' : $p['stage']);
    if ($stage !== $p['stage']) $pdo->prepare('UPDATE mkt_pieces SET stage = ? WHERE id = ?')->execute([$stage, (int)$p['id']]);
    mkt_touch($pdo, (int)$p['id']);
    return [$any ? 'Agendada. Quem publica tem o lembrete nesse dia.' : ($scheduled ? 'Datas atualizadas.' : 'Datas retiradas.'), $created, $closed];
}

/**
 * Marcar uma rede como publicada (fase 1, assistida): o link da publicação. Fecha o lembrete,
 * cria o das métricas (7 dias depois) e, com todas as redes publicadas, a peça passa a Publicado.
 * Devolve [mensagem, tarefas criadas, tarefas fechadas].
 */
function mkt_publish(PDO $pdo, array $p, string $net, string $url, int $by): array {
    if ($p['status'] !== 'producao') throw new RuntimeException('Esta peça ainda é uma ideia.');
    $url = trim($url);
    if (!preg_match('~^https?://\S+$~i', $url) || strlen($url) > 500) throw new RuntimeException('Cole o link da publicação (começa por https://).');
    if ($why = mkt_block_reason($p, today_lisbon())) {
        mkt_log($pdo, (int)$p['id'], 'm_blocked', (MKT_NETWORKS[$net][0] ?? $net) . ' · ' . $why, $by);
        throw new RuntimeException($why);
    }
    mkt_sync_posts($pdo, $p);
    $posts = mkt_posts($pdo, (int)$p['id']);
    if (!isset($posts[$net])) throw new RuntimeException('Esta peça não é para ' . (MKT_NETWORKS[$net][0] ?? $net) . '.');
    $post = $posts[$net];
    $created = $closed = [];
    if ($post['task_id']) { mkt_task_close($pdo, (int)$post['task_id'], true); $closed[] = (int)$post['task_id']; }
    $mt = $post['metrics_task_id'];
    if (!$post['published_at']) {
        $who = $post['publisher_id'] !== null ? (int)$post['publisher_id'] : ($p['owner_id'] !== null ? (int)$p['owner_id'] : $by);
        $mt = mkt_task_create($pdo, $p, $who, 'Métricas do ' . MKT_NETWORKS[$net][0] . ': ' . $p['title'],
            'Passaram 7 dias: registar alcance, interações, guardados, partilhas e cliques na peça (Marketing › Desempenho).',
            (new DateTimeImmutable(today_lisbon()))->modify('+7 days')->format('Y-m-d'), null, $by);
        $created[] = $mt;
    }
    $pdo->prepare('UPDATE mkt_posts SET published_at = COALESCE(published_at, ?), url = ?, publisher_id = COALESCE(publisher_id, ?), metrics_task_id = ? WHERE id = ?')
        ->execute([now_php(), $url, $by, $mt, $post['id']]);
    mkt_log($pdo, (int)$p['id'], 'm_publish', MKT_NETWORKS[$net][0] . ' · ' . $url, $by);
    $posts = mkt_posts($pdo, (int)$p['id']);
    $all = !array_filter(mkt_nets($p), fn($n) => empty($posts[$n]['published_at']));
    if ($all) $pdo->prepare("UPDATE mkt_pieces SET stage = 'publicado', published_at = COALESCE(published_at, ?) WHERE id = ?")->execute([now_php(), (int)$p['id']]);
    elseif ($p['stage'] !== 'agendado') $pdo->prepare("UPDATE mkt_pieces SET stage = 'agendado' WHERE id = ?")->execute([(int)$p['id']]);
    mkt_touch($pdo, (int)$p['id']);
    return [$all ? 'Publicada em todas as redes. O lembrete das métricas fica para daqui a 7 dias.' : 'Publicada no ' . MKT_NETWORKS[$net][0] . '.', $created, $closed];
}

/** As métricas de uma publicação (à mão na fase 1). Fecha o lembrete das métricas. */
function mkt_metrics_save(PDO $pdo, array $p, string $net, array $in, int $by): string {
    $posts = mkt_posts($pdo, (int)$p['id']);
    $post = $posts[$net] ?? null;
    if (!$post || !$post['published_at']) throw new RuntimeException('Só há métricas depois de publicada.');
    $num = static function ($v): ?int {
        $v = trim(str_replace([' ', '.', ','], '', (string)$v));
        if ($v === '') return null;
        if (!ctype_digit($v) || strlen($v) > 9) throw new RuntimeException('As métricas são números inteiros.');
        return (int)$v;
    };
    $vals = [];
    foreach (['reach', 'interactions', 'saves', 'shares', 'clicks'] as $k) $vals[$k] = $num($in[$k] ?? '');
    $pdo->prepare('UPDATE mkt_posts SET reach = ?, interactions = ?, saves = ?, shares = ?, clicks = ?, metrics_at = ?, metrics_by = ? WHERE id = ?')
        ->execute([...array_values($vals), now_php(), $by, $post['id']]);
    mkt_task_close($pdo, $post['metrics_task_id'] !== null ? (int)$post['metrics_task_id'] : null, true);
    mkt_touch($pdo, (int)$p['id']);
    mkt_log($pdo, (int)$p['id'], 'm_metrics', MKT_NETWORKS[$net][0] . ' · alcance ' . ($vals['reach'] ?? '—') . ' · interações ' . ($vals['interactions'] ?? '—'), $by);
    return 'Métricas guardadas.';
}

/** Os textos ficam fechados depois de aprovada (e quando agendada ou publicada). */
function mkt_locked(array $p): bool {
    return $p['review_state'] === 'approved' || in_array($p['stage'], ['agendado', 'publicado'], true);
}

/** Guardar a versão de uma rede: hook, body, hashtags, cta. */
function mkt_version_save(PDO $pdo, array $p, string $net, array $fields, int $by): void {
    if (!isset(MKT_NETWORKS[$net])) throw new RuntimeException('Rede desconhecida.');
    if (mkt_locked($p)) throw new RuntimeException('Aprovada: os textos ficam como foram aprovados. Para mudar, volte a peça a Edição (pede nova aprovação).');
    $lim = ['hook' => 300, 'body' => 8000, 'hashtags' => 500, 'cta' => 300];
    $set = [];
    foreach ($fields as $k => $v) if (isset($lim[$k])) $set[$k] = mb_substr(trim((string)$v), 0, $lim[$k]);
    if (!$set) return;
    $cur = mkt_versions($pdo, (int)$p['id'])[$net] ?? null;
    if ($cur) {
        $pdo->prepare('UPDATE mkt_versions SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ', updated_by = ?, updated_at = ? WHERE id = ?')
            ->execute([...array_values($set), $by, now_php(), $cur['id']]);
    } else {
        $set += ['hook' => '', 'body' => '', 'hashtags' => '', 'cta' => ''];
        $pdo->prepare('INSERT INTO mkt_versions (piece_id, network, hook, body, hashtags, cta, updated_by, updated_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([(int)$p['id'], $net, $set['hook'], $set['body'], $set['hashtags'], $set['cta'], $by, now_php()]);
    }
    mkt_touch($pdo, (int)$p['id']);
}

/** O texto pronto a copiar para uma rede: gancho, texto, CTA, link com rastreio e hashtags. */
function mkt_caption(array $v, ?array $post): string {
    $parts = [];
    foreach (['hook', 'body', 'cta'] as $k) if (trim((string)($v[$k] ?? '')) !== '') $parts[] = trim((string)$v[$k]);
    if ($post) $parts[] = mkt_track_url((string)$post['code']);
    if (trim((string)($v['hashtags'] ?? '')) !== '') $parts[] = trim((string)$v['hashtags']);
    return implode("\n\n", $parts);
}

/** Uma thread do X guarda-se como posts separados por uma linha «---». */
function mkt_thread_parts(string $body): array {
    return array_values(array_filter(array_map('trim', preg_split('/^\s*-{3,}\s*$/m', $body)), fn($x) => $x !== ''));
}

/** Dias de gravação (§16.5): os próximos, com as peças. */
function mkt_shoots(PDO $pdo, bool $upcoming = true): array {
    $rows = $pdo->query('SELECT s.*, (SELECT COUNT(*) FROM mkt_pieces p WHERE p.shoot_id = s.id) AS pieces FROM mkt_shoots s'
                        . ($upcoming ? " WHERE s.day >= '" . today_lisbon() . "'" : '') . ' ORDER BY s.day, s.id')->fetchAll();
    return $rows;
}

/** A lista de planos e de material de um dia de gravação: dos guiões e dos modelos das peças. */
function mkt_shot_list(PDO $pdo, int $shootId): array {
    $pieces = mkt_pieces_where($pdo, 'p.shoot_id = ?', [$shootId], 'p.id');
    $shots = $material = [];
    foreach ($pieces as $p) {
        $scenes = json_decode((string)$p['script'], true);
        $list = [];
        if (is_array($scenes)) foreach ($scenes as $s) if (is_array($s) && trim((string)($s['scene'] ?? '')) !== '') $list[] = trim(($s['time'] ?? '') . ' ' . $s['scene']);
        if (!$list && $p['template'] !== '') $list = array_values(array_filter(MKT_TEMPLATES[$p['template']][4], fn($t) => preg_match('/plano|grav|entrevista|montagem/iu', $t)));
        $shots[] = ['piece' => $p, 'shots' => $list];
        if ($p['template'] !== '') foreach (MKT_TEMPLATES[$p['template']][5] as $m) $material[$m] = true;
    }
    return ['pieces' => $pieces, 'shots' => $shots, 'material' => array_keys($material)];
}

// ── IA do Estúdio (§16.4) ───────────────────────────────────

/** As regras de cada rede para a IA. */
const MKT_NET_RULES = [
    'ig' => 'Instagram: gancho forte na primeira linha, legenda curta (até ~600 caracteres), 3–8 hashtags relevantes; num reel, roteiro com tempos (0–3 s gancho…).',
    'li' => 'LinkedIn: tom profissional, conta uma história e uma aprendizagem, 600–1300 caracteres, parágrafos curtos, no máximo 3 hashtags, sem emojis.',
    'x'  => 'X: post curto até 280 caracteres, ou thread de 3–7 posts com até 280 caracteres cada.',
];

function mkt_ai_system(PDO $pdo): string {
    return "És o assistente de conteúdo da topper. para Instagram, LinkedIn e X. Propões textos; uma pessoa da equipa decide o que usar.\n\n"
         . "Voz da topper. (Centro de Conhecimento — segue-a sempre):\n" . (mkt_voice($pdo) ?: 'Confiante, próxima, clara. Frases curtas.') . "\n\n"
         . "Regras por rede:\n- " . implode("\n- ", MKT_NET_RULES) . "\n\n"
         . "Nunca inventes factos, clientes, números, prémios ou nomes que não estejam na ideia. Escreve em português de Portugal, salvo se a ideia estiver noutra língua.\n"
         . "Responde só com um objeto JSON válido, no formato pedido, sem texto antes nem depois e sem blocos de código.";
}

/** O contexto da peça para a IA (sem dados do Financeiro nem de pessoas além do que está na ideia). */
function mkt_ai_context(PDO $pdo, array $p, array $versions): string {
    $tpl = $p['template'] !== '' ? MKT_TEMPLATES[$p['template']] : null;
    $c = "Peça: " . $p['title'] . "\n"
       . 'Pilar: ' . ($p['pillar_name'] ?? '—') . "\n"
       . ($tpl ? 'Modelo: ' . $tpl[0] . ' — ' . $tpl[6] . "\n" : '')
       . 'Formato: ' . (MKT_FORMATS[$p['format']] ?? '—') . "\n"
       . 'Redes: ' . implode(', ', array_map(fn($n) => MKT_NETWORKS[$n][0], mkt_nets($p) ?: array_keys(MKT_NETWORKS))) . "\n\n"
       . "Ideia base:\n" . trim((string)($p['idea'] ?: $p['title'])) . "\n";
    if ($p['pillar_kind'] === 'employer') $c .= "\nEste é conteúdo de employer branding: quem vê deve querer trabalhar na topper.\n";
    foreach ($versions as $n => $v) {
        $t = trim(implode("\n", array_filter([(string)$v['hook'], (string)$v['body'], (string)$v['cta'], (string)$v['hashtags']])));
        if ($t !== '') $c .= "\nTexto atual no " . MKT_NETWORKS[$n][0] . ":\n" . $t . "\n";
    }
    return $c;
}

/** O pedido de cada ação, com o formato JSON da resposta. */
function mkt_ai_task(string $action, array $p, string $net, string $tone): string {
    $netName = $net !== '' ? MKT_NETWORKS[$net][0] : '';
    $nets = mkt_nets($p) ?: array_keys(MKT_NETWORKS);
    switch ($action) {
        case 'adapt':
            $shape = [];
            if (in_array('ig', $nets, true)) $shape[] = '"ig": {"hook": "…", "body": "legenda", "hashtags": "#a #b", "reel": "0–3 s … (se for reel; senão vazio)"}';
            if (in_array('li', $nets, true)) $shape[] = '"li": {"hook": "primeira linha", "body": "texto do post"}';
            if (in_array('x', $nets, true)) $shape[] = '"x": {"body": "post até 280 caracteres", "thread": ["1/…", "2/…"] (vazio se não fizer sentido)}';
            return "Escreve a versão da peça para cada rede, no formato e no tom certos. Responde com:\n{" . implode(', ', $shape) . '}';
        case 'hooks':
            return 'Propõe 5 ganchos diferentes (primeira linha ou primeiros 3 segundos), curtos e concretos. Responde com: {"hooks": ["…", "…", "…", "…", "…"]}';
        case 'script':
            return 'Escreve o guião cena a cena, com a duração de cada cena e o texto/legenda no ecrã. Responde com: {"duration": "30 s", "scenes": [{"time": "0–3 s", "scene": "o que se vê", "text": "texto ou fala"}]}';
        case 'shorten':
            return "Encurta o texto do $netName para cerca de metade, mantendo o gancho e a mensagem. Responde com: {\"body\": \"…\"}";
        case 'lengthen':
            return "Alonga o texto do $netName com mais contexto e um exemplo concreto da ideia (sem inventar factos). Responde com: {\"body\": \"…\"}";
        case 'tone':
            return "Reescreve o texto do $netName com um tom " . (MKT_TONES[$tone] ?? 'diferente') . ". Responde com: {\"body\": \"…\"}";
        case 'cta':
            return "Propõe 3 CTA (chamadas à ação) curtos para o $netName. Responde com: {\"ctas\": [\"…\", \"…\", \"…\"]}";
        case 'thread':
            return 'Escreve uma thread para o X com 3 a 7 posts, cada um com até 280 caracteres, numerados (1/, 2/…). Responde com: {"thread": ["1/ …", "2/ …"]}';
        case 'hashtags':
            return "Propõe hashtags para o $netName (" . ($net === 'ig' ? '5 a 8' : 'até 3') . "), relevantes e não genéricas. Responde com: {\"hashtags\": [\"#…\"]}";
    }
    throw new RuntimeException('Ação desconhecida.');
}

/** Tira o JSON da resposta (mesmo que venha com texto ou ``` à volta). */
function mkt_ai_json(string $text): array {
    $t = trim($text);
    $t = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t);
    $j = json_decode($t, true);
    if (!is_array($j) && preg_match('/\{.*\}/s', $t, $m)) $j = json_decode($m[0], true);
    if (!is_array($j)) throw new RuntimeException('A IA respondeu num formato que não se percebe. Tente outra vez.');
    return $j;
}

/**
 * As propostas normalizadas: cada uma [network, field (hook|body|hashtags|cta|script|thread), label, value].
 * O cliente mostra-as; «Usar» grava (mkt_version_save ou o guião).
 */
function mkt_ai_proposals(string $action, array $j, array $p, string $net): array {
    $s = static fn($x) => trim(is_string($x) ? $x : '');
    $out = [];
    switch ($action) {
        case 'adapt':
            foreach (mkt_nets($p) ?: array_keys(MKT_NETWORKS) as $n) {
                $v = is_array($j[$n] ?? null) ? $j[$n] : [];
                if ($n === 'x' && is_array($v['thread'] ?? null) && count(array_filter($v['thread'], 'is_string')) > 1) {
                    $out[] = ['network' => 'x', 'field' => 'thread', 'label' => 'Thread', 'value' => implode("\n---\n", array_map('trim', array_filter($v['thread'], 'is_string')))];
                }
                $body = $s($v['body'] ?? '');
                if ($n === 'ig' && $s($v['reel'] ?? '') !== '') $out[] = ['network' => 'ig', 'field' => 'script', 'label' => 'Roteiro do reel', 'value' => $s($v['reel'])];
                if ($s($v['hook'] ?? '') !== '' || $body !== '' || $s($v['hashtags'] ?? '') !== '') {
                    $out[] = ['network' => $n, 'field' => 'all', 'label' => 'Versão completa', 'value' => $body,
                              'parts' => ['hook' => $s($v['hook'] ?? ''), 'body' => $body, 'hashtags' => $s($v['hashtags'] ?? '')]];
                }
            }
            break;
        case 'hooks':
            foreach ((array)($j['hooks'] ?? []) as $h) if ($s($h) !== '') $out[] = ['network' => $net ?: (mkt_nets($p)[0] ?? 'ig'), 'field' => 'hook', 'label' => 'Gancho', 'value' => $s($h)];
            break;
        case 'script':
            $sc = [];
            foreach ((array)($j['scenes'] ?? []) as $x) if (is_array($x) && $s($x['scene'] ?? '') !== '') $sc[] = ['time' => $s($x['time'] ?? ''), 'scene' => $s($x['scene']), 'text' => $s($x['text'] ?? '')];
            if ($sc) $out[] = ['network' => '', 'field' => 'script', 'label' => 'Guião' . ($s($j['duration'] ?? '') !== '' ? ' · ' . $s($j['duration']) : ''),
                               'value' => implode("\n", array_map(fn($x) => trim($x['time'] . ' · ' . $x['scene'] . ($x['text'] !== '' ? ' — «' . $x['text'] . '»' : ''), ' ·'), $sc)), 'scenes' => $sc];
            break;
        case 'shorten': case 'lengthen': case 'tone':
            if ($s($j['body'] ?? '') !== '') $out[] = ['network' => $net, 'field' => 'body', 'label' => 'Texto', 'value' => $s($j['body'])];
            break;
        case 'cta':
            foreach ((array)($j['ctas'] ?? []) as $c) if ($s($c) !== '') $out[] = ['network' => $net, 'field' => 'cta', 'label' => 'CTA', 'value' => $s($c)];
            break;
        case 'thread':
            $t = array_values(array_filter(array_map($s, (array)($j['thread'] ?? [])), fn($x) => $x !== ''));
            if ($t) $out[] = ['network' => 'x', 'field' => 'thread', 'label' => 'Thread · ' . count($t) . ' posts', 'value' => implode("\n---\n", $t)];
            break;
        case 'hashtags':
            $h = array_values(array_filter(array_map(fn($x) => '#' . ltrim($s($x), '#'), (array)($j['hashtags'] ?? [])), fn($x) => $x !== '#'));
            if ($h) $out[] = ['network' => $net, 'field' => 'hashtags', 'label' => 'Hashtags', 'value' => implode(' ', $h)];
            break;
    }
    if (!$out) throw new RuntimeException('A IA não devolveu propostas. Tente outra vez.');
    return $out;
}

/** Corre uma ação da IA sobre a peça e regista o uso. Devolve as propostas. */
function mkt_ai_run(PDO $pdo, array $p, string $action, string $net, string $tone, int $by): array {
    require_once __DIR__ . '/ai.php';
    if (!isset(MKT_AI_ACTIONS[$action])) throw new RuntimeException('Ação desconhecida.');
    if (MKT_AI_ACTIONS[$action][1] && !isset(MKT_NETWORKS[$net])) throw new RuntimeException('Escolha a rede.');
    if (!MKT_AI_ACTIONS[$action][1] && $action !== 'hooks') $net = '';   // a rede só conta nas ações de uma rede (e nos ganchos)
    if ($action === 'hooks' && !isset(MKT_NETWORKS[$net])) $net = mkt_nets($p)[0] ?? 'ig';
    if (in_array($action, ['shorten', 'lengthen', 'tone'], true)) {
        $v = mkt_versions($pdo, (int)$p['id'])[$net] ?? null;
        if (!$v || trim((string)$v['body']) === '') throw new RuntimeException('O ' . MKT_NETWORKS[$net][0] . ' ainda não tem texto: comece por «Adaptar às redes».');
    }
    $versions = mkt_versions($pdo, (int)$p['id']);
    $body = ['max_tokens' => 2000, 'system' => mkt_ai_system($pdo),
             'messages' => [['role' => 'user', 'content' => mkt_ai_context($pdo, $p, $versions) . "\n\n" . mkt_ai_task($action, $p, $net, $tone)]]];
    $log = $pdo->prepare('INSERT INTO mkt_ai_log (user_id, piece_id, action, network, ok, input_tokens, output_tokens, cost_usd, payload, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    try {
        $r = ai_call($pdo, $body, 'mkt');
    } catch (Throwable $e) {
        $log->execute([$by, (int)$p['id'], $action, $net, 0, 0, 0, 0, mb_substr($e->getMessage(), 0, 1000), now_php()]);
        throw $e instanceof AiException ? new RuntimeException($e->getMessage()) : $e;
    }
    $text = implode("\n", ai_texts($r['_content']));
    $u = $r['usage'] ?? [];
    try {
        $props = mkt_ai_proposals($action, mkt_ai_json($text), $p, $net);
    } catch (RuntimeException $e) {
        $log->execute([$by, (int)$p['id'], $action, $net, 0, (int)($u['input_tokens'] ?? 0), (int)($u['output_tokens'] ?? 0), $r['_cost_usd'], mb_substr($text, 0, 20000), now_php()]);
        throw $e;
    }
    $log->execute([$by, (int)$p['id'], $action, $net, 1, (int)($u['input_tokens'] ?? 0), (int)($u['output_tokens'] ?? 0), $r['_cost_usd'],
                   json_encode($props, JSON_UNESCAPED_UNICODE), now_php()]);
    return $props;
}

/** «Usar» uma proposta da IA (ou qualquer texto): grava no sítio certo. */
function mkt_ai_use(PDO $pdo, array $p, string $net, string $field, string $value, array $parts, int $by): string {
    if ($field === 'script') {
        if (mkt_locked($p)) throw new RuntimeException('Aprovada: o guião fica como foi aprovado.');
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $value))));
        $scenes = [];
        foreach ($lines as $l) {
            $bits = array_map('trim', explode(' · ', $l, 2));
            $scenes[] = count($bits) === 2 && mb_strlen($bits[0]) <= 16 ? ['time' => $bits[0], 'scene' => $bits[1]] : ['time' => '', 'scene' => $l];
        }
        $pdo->prepare('UPDATE mkt_pieces SET script = ? WHERE id = ?')->execute([json_encode($scenes, JSON_UNESCAPED_UNICODE), (int)$p['id']]);
        mkt_touch($pdo, (int)$p['id']);
        mkt_log($pdo, (int)$p['id'], 'm_ai', 'Guião', $by);
        return 'Guião guardado.';
    }
    if (!isset(MKT_NETWORKS[$net])) throw new RuntimeException('Rede desconhecida.');
    $fields = match ($field) {
        'all'    => array_intersect_key($parts, ['hook' => 1, 'body' => 1, 'hashtags' => 1]),
        'thread' => ['body' => $value],
        'hook', 'body', 'hashtags', 'cta' => [$field => $value],
        default  => throw new RuntimeException('Campo desconhecido.'),
    };
    mkt_version_save($pdo, $p, $net, $fields, $by);
    mkt_log($pdo, (int)$p['id'], 'm_ai', MKT_NETWORKS[$net][0] . ' · ' . ['all' => 'versão', 'thread' => 'thread', 'hook' => 'gancho', 'body' => 'texto', 'hashtags' => 'hashtags', 'cta' => 'CTA'][$field], $by);
    return 'Usado no ' . MKT_NETWORKS[$net][0] . '.';
}

// ── desempenho (§16.8) ──────────────────────────────────────

/** [início, fim) de um mês «aaaa-mm» (datas de Lisboa). */
function mkt_month_bounds(string $ym): array {
    $a = new DateTimeImmutable($ym . '-01');
    return [$a->format('Y-m-d'), $a->modify('+1 month')->format('Y-m-d')];
}

/** As publicações feitas no mês, com a peça e o pilar, cliques e leads. */
function mkt_month_posts(PDO $pdo, string $ym): array {
    [$a, $b] = mkt_month_bounds($ym);
    $st = $pdo->prepare(
        "SELECT mp.*, p.title, p.format, p.pillar_id, p.id AS piece_id, p.deal_id AS piece_deal, pl.name AS pillar_name, pl.color AS pillar_color, pl.kind AS pillar_kind,
                (SELECT COUNT(*) FROM mkt_clicks c WHERE c.post_id = mp.id) AS tracked,
                (SELECT COUNT(*) FROM deals d WHERE d.mkt_post_id = mp.id) AS leads,
                (SELECT COUNT(*) FROM mkt_pieces k WHERE k.parent_id = p.id) AS children
           FROM mkt_posts mp JOIN mkt_pieces p ON p.id = mp.piece_id LEFT JOIN mkt_pillars pl ON pl.id = p.pillar_id
          WHERE mp.published_at >= ? AND mp.published_at < ? ORDER BY mp.published_at DESC");
    $st->execute([$a . ' 00:00:00', $b . ' 00:00:00']);
    return $st->fetchAll();
}

/** A interação de uma publicação para comparar (interações; sem elas, nada). */
function mkt_eng(array $r): ?float {
    return $r['interactions'] !== null ? (float)$r['interactions'] : null;
}

/** Médias de interações por chave (pilar, formato, rede, dia/hora). */
function mkt_avg_by(array $rows, callable $key): array {
    $acc = [];
    foreach ($rows as $r) {
        $e = mkt_eng($r);
        if ($e === null) continue;
        $k = $key($r);
        $acc[$k] ??= ['n' => 0, 'sum' => 0.0, 'reach' => 0];
        $acc[$k]['n']++;
        $acc[$k]['sum'] += $e;
        $acc[$k]['reach'] += (int)$r['reach'];
    }
    foreach ($acc as $k => $v) $acc[$k]['avg'] = $v['sum'] / max(1, $v['n']);
    uasort($acc, fn($a, $b) => $b['avg'] <=> $a['avg']);
    return $acc;
}

/** Os números do mês: alcance, interações, cliques, leads, negócios e € (só com Financeiro), talento. */
function mkt_month_stats(PDO $pdo, string $ym, array $rows): array {
    [$a, $b] = mkt_month_bounds($ym);
    $leads = $pdo->prepare("SELECT d.*, mp.network, p.title AS piece_title, pl.kind AS pillar_kind FROM deals d
                              JOIN mkt_posts mp ON mp.id = d.mkt_post_id JOIN mkt_pieces p ON p.id = mp.piece_id LEFT JOIN mkt_pillars pl ON pl.id = p.pillar_id
                             WHERE d.created_at >= ? AND d.created_at < ?");
    $leads->execute([$a . ' 00:00:00', $b . ' 00:00:00']);
    $L = $leads->fetchAll();
    $won = array_filter($L, fn($d) => in_array($d['phase'], ['production', 'done'], true));
    $talent = $pdo->prepare("SELECT COUNT(*) FROM deals WHERE origin = 'talento' AND created_at >= ? AND created_at < ?");
    $talent->execute([$a . ' 00:00:00', $b . ' 00:00:00']);
    $clicks = $pdo->prepare('SELECT COUNT(*) FROM mkt_clicks WHERE created_at >= ? AND created_at < ?');
    $clicks->execute([$a . ' 00:00:00', $b . ' 00:00:00']);
    return [
        'posts'   => count($rows),
        'reach'   => array_sum(array_map(fn($r) => (int)$r['reach'], $rows)),
        'inter'   => array_sum(array_map(fn($r) => (int)$r['interactions'], $rows)),
        'saves'   => array_sum(array_map(fn($r) => (int)$r['saves'], $rows)),
        'shares'  => array_sum(array_map(fn($r) => (int)$r['shares'], $rows)),
        'clicks'  => (int)$clicks->fetchColumn() + array_sum(array_map(fn($r) => (int)$r['clicks'], $rows)),
        'leads'   => count($L),
        'won'     => count($won),
        'value'   => array_sum(array_map(fn($d) => (float)($d['budget'] ?: $d['value_estimate']), $won)),
        'talent'  => (int)$talent->fetchColumn(),
        'no_metrics' => count(array_filter($rows, fn($r) => $r['metrics_at'] === null && strtotime((string)$r['published_at']) < time() - 7 * 86400)),
    ];
}

/** Resumo mensal escrito pela IA (§16.8), a partir dos números (sem dados pessoais). */
function mkt_ai_summary(PDO $pdo, string $ym, array $stats, array $rows, int $by): string {
    require_once __DIR__ . '/ai.php';
    $money = can('financeiro', PERM_VIEW);
    $lines = [];
    foreach (array_slice($rows, 0, 40) as $r) {
        $lines[] = '- ' . MKT_NETWORKS[$r['network']][0] . ' · ' . ($r['pillar_name'] ?? '—') . ' · ' . (MKT_FORMATS[$r['format']] ?? '—') . ' · «' . $r['title'] . '» · '
                 . lisbon_fmt(strtotime((string)$r['published_at']), 'D H') . 'h · alcance ' . ($r['reach'] ?? '?') . ' · interações ' . ($r['interactions'] ?? '?')
                 . ' · cliques ' . ((int)$r['tracked'] + (int)$r['clicks']) . ' · leads ' . (int)$r['leads'];
    }
    $prompt = "Escreve o resumo do mês de " . $ym . " das redes sociais da topper. em 4 a 6 frases: o que correu melhor (pilar, rede, formato, dia/hora), "
            . "o que trouxe leads e negócios, o employer branding (talento) e 2 sugestões concretas para o mês seguinte. Só com base nestes números:\n"
            . "Publicações: {$stats['posts']} · alcance {$stats['reach']} · interações {$stats['inter']} · cliques {$stats['clicks']} · leads {$stats['leads']} · negócios {$stats['won']}"
            . ($money ? ' · valor ' . round($stats['value']) . ' €' : '') . " · talento {$stats['talent']}\n" . implode("\n", $lines)
            . "\n\nResponde com: {\"summary\": \"…\"}";
    $r = ai_call($pdo, ['max_tokens' => 900, 'system' => mkt_ai_system($pdo), 'messages' => [['role' => 'user', 'content' => $prompt]]], 'mkt');
    $u = $r['usage'] ?? [];
    $j = mkt_ai_json(implode("\n", ai_texts($r['_content'])));
    $sum = trim((string)($j['summary'] ?? ''));
    if ($sum === '') throw new RuntimeException('A IA não devolveu o resumo. Tente outra vez.');
    $pdo->prepare('INSERT INTO mkt_ai_log (user_id, piece_id, action, network, ok, input_tokens, output_tokens, cost_usd, payload, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$by, null, 'summary', '', 1, (int)($u['input_tokens'] ?? 0), (int)($u['output_tokens'] ?? 0), $r['_cost_usd'], $sum, now_php()]);
    $pdo->prepare('DELETE FROM mkt_summaries WHERE month = ?')->execute([$ym]);
    $pdo->prepare('INSERT INTO mkt_summaries (month, body, created_by, created_at) VALUES (?,?,?,?)')->execute([$ym, $sum, $by, now_php()]);
    return $sum;
}

/** As peças com bom desempenho (≥ 1,5× a média de interações da rede) e ainda sem parte 2. */
function mkt_repeat_suggestions(PDO $pdo, int $limit = 4): array {
    $rows = $pdo->query("SELECT mp.network, mp.interactions, p.id, p.title, (SELECT COUNT(*) FROM mkt_pieces k WHERE k.parent_id = p.id) AS children
                           FROM mkt_posts mp JOIN mkt_pieces p ON p.id = mp.piece_id WHERE mp.interactions IS NOT NULL")->fetchAll();
    $avg = [];
    foreach ($rows as $r) { $avg[$r['network']][] = (int)$r['interactions']; }
    foreach ($avg as $n => $xs) $avg[$n] = array_sum($xs) / max(1, count($xs));
    $out = [];
    foreach ($rows as $r) {
        if ((int)$r['children'] > 0 || count(array_filter($rows, fn($x) => $x['network'] === $r['network'])) < 2) continue;
        if ((int)$r['interactions'] >= 1.5 * $avg[$r['network']] && (int)$r['interactions'] > 0) {
            $out[(int)$r['id']] = ['id' => (int)$r['id'], 'title' => $r['title'], 'network' => $r['network'], 'x' => $avg[$r['network']] > 0 ? (int)$r['interactions'] / $avg[$r['network']] : 0];
        }
    }
    usort($out, fn($a, $b) => $b['x'] <=> $a['x']);
    return array_slice($out, 0, $limit);
}

// ── HTML partilhado ─────────────────────────────────────────

function mkt_pill(?array $p): string {
    if (!$p || empty($p['pillar_name'])) return '';
    return '<span class="mpil p-' . e((string)($p['pillar_color'] ?: 'neutral')) . '" data-tip="Pilar: ' . e($p['pillar_name']) . '">' . e($p['pillar_short'] ?: $p['pillar_name']) . '</span>';
}

function mkt_net_chip(string $n, string $extra = ''): string {
    if (!isset(MKT_NETWORKS[$n])) return '';
    return '<span class="mnet n-' . $n . $extra . '" data-tip="' . e(MKT_NETWORKS[$n][0]) . '" aria-label="' . e(MKT_NETWORKS[$n][0]) . '">' . e(MKT_NETWORKS[$n][1]) . '</span>';
}

function mkt_nets_html(array $p): string {
    return implode('', array_map(fn($n) => mkt_net_chip($n), mkt_nets($p)));
}

/** «autorizado» / «sem autorização» / «a partir de …» para o cartão de uma peça ligada a um negócio. */
function mkt_deal_line(array $p): string {
    if (!$p['deal_id']) return '';
    $s = mkt_disclose_state($p['disclose'], $p['disclose_from']);
    $cls = $s['key'] === 'yes' ? 'pos' : ($s['key'] === 'from' ? '' : 'neg');
    $txt = ['yes' => 'autorizado', 'no' => 'sem autorização', 'from' => 'a partir de ' . ($s['from'] ? date('d/m', strtotime($s['from'])) : '—'), 'unset' => 'divulgação por decidir'][$s['key']];
    return '<div class="mdeal">↳ ' . e((string)$p['deal_code']) . ' · <span class="' . $cls . '" data-tip="' . e($s['label']) . '">' . e($txt) . '</span></div>';
}

function mkt_review_chip(array $p): string {
    return match ($p['review_state']) {
        'approved' => '<span class="chip ok" data-tip="Aprovada' . ($p['reviewed_at'] ? ' a ' . e(lisbon_fmt(strtotime((string)$p['reviewed_at']), 'd/m H:i')) : '') . '">aprovada</span>',
        'pending'  => '<span class="chip wait" data-tip="Aprovam superadmin e admins">por aprovar</span>',
        'changes'  => '<span class="chip no" data-tip="' . e((string)$p['review_note']) . '">alterações pedidas</span>',
        default    => '',
    };
}

function mkt_due_html(array $p): string {
    if (!$p['due_date']) return '';
    $late = $p['due_date'] < today_lisbon() && $p['stage'] !== 'publicado';
    return '<span class="mono' . ($late ? ' neg' : ' muted') . '" data-tip="Prazo' . ($late ? ' (em atraso)' : '') . '">' . e(date('d/m', strtotime((string)$p['due_date']))) . '</span>';
}

/** A cronologia da peça (ícones com cor e dica, §11). */
function mkt_history_html(PDO $pdo, int $id): string {
    $st = $pdo->prepare('SELECT l.*, u.name AS who FROM mkt_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.piece_id = ? ORDER BY l.created_at DESC, l.id DESC LIMIT 200');
    $st->execute([$id]);
    $items = [];
    foreach ($st->fetchAll() as $h) {
        $items[] = ['when' => $h['created_at'], 'type' => isset(HISTORY_TYPES[$h['event']]) ? $h['event'] : 'm_edit',
                    'title' => HISTORY_TYPES[$h['event']][0] ?? $h['event'], 'text' => (string)$h['note'], 'who' => $h['who'] ?? 'Sistema', 'sys' => true];
    }
    return timeline_html($items);
}

/** O cartão de uma peça no kanban (e no painel do dia de gravação). */
function mkt_card_html(array $p, bool $canEdit): string {
    $check = mkt_checklist($p);
    $done = count(array_filter($check, fn($c) => !empty($c['done'])));
    $h = '<article class="dcard mcard" data-id="' . (int)$p['id'] . '" draggable="' . ($canEdit && $p['stage'] !== 'publicado' ? 'true' : 'false') . '"'
       . ' data-stage="' . e((string)$p['stage']) . '" data-review="' . e((string)$p['review_state']) . '">'
       . '<div class="mtop">' . mkt_pill($p) . mkt_review_chip($p) . '</div>'
       . '<a class="dname" href="/admin/marketing_peca.php?id=' . (int)$p['id'] . '" data-panel="mkt:' . (int)$p['id'] . '">' . e($p['title']) . '</a>'
       . mkt_deal_line($p)
       . ($p['shoot_day'] ? '<div class="muted small">' . icon('camera') . ' Dia de gravação · ' . e(date('d/m', strtotime((string)$p['shoot_day']))) . '</div>' : '')
       . '<div class="drow"><span class="mnets">' . mkt_nets_html($p) . '</span>'
       . ($check ? '<span class="mono muted small" data-tip="Checklist">' . $done . '/' . count($check) . '</span>' : '')
       . mkt_due_html($p)
       . '<span class="owner" data-tip="Responsável"><span class="dot" style="background:' . e($p['owner_color'] ?: '#ccc') . '"></span> ' . e($p['owner_name'] ? first_word((string)$p['owner_name']) : 'Sem dono') . '</span></div>'
       . '</article>';
    return $h;
}

function first_word(string $s): string {
    return explode(' ', trim($s))[0] ?? $s;
}

/** O painel lateral de uma peça (ui_api a=panel&type=mkt). */
function mkt_panel(PDO $pdo, array $p): array {
    $posts = mkt_posts($pdo, (int)$p['id']);
    $kv = '<div class="kv">'
        . '<span>Estado</span><div>' . ($p['status'] === 'producao' ? e(MKT_STAGES[$p['stage']] ?? '') . ' ' . mkt_review_chip($p) : e(MKT_IDEA_STATUS[$p['status']] ?? '')) . '</div>'
        . '<span>Pilar</span><div>' . (mkt_pill($p) ?: '<span class="muted">—</span>') . '</div>'
        . '<span>Formato</span><div>' . e(MKT_FORMATS[$p['format']] ?? '—') . '</div>'
        . '<span>Redes</span><div class="mnets">' . (mkt_nets_html($p) ?: '<span class="muted">—</span>') . '</div>'
        . '<span>Responsável</span><div>' . e($p['owner_name'] ?? 'Sem dono') . '</div>'
        . '<span>Prazo</span><div>' . ($p['due_date'] ? e(date('d/m/Y', strtotime((string)$p['due_date']))) : '<span class="muted">—</span>') . '</div>'
        . ($p['deal_id'] ? '<span>Negócio</span><div>' . e($p['deal_code'] . ' · ' . $p['deal_name']) . '<br><span class="muted small">' . e(mkt_disclose_state($p['disclose'], $p['disclose_from'])['label']) . '</span></div>' : '')
        . '<span>Votos</span><div>' . (int)$p['votes'] . '</div>'
        . '</div>';
    $pub = '';
    foreach (mkt_nets($p) as $n) {
        $x = $posts[$n] ?? null;
        $pub .= '<li>' . mkt_net_chip($n) . ' ' . ($x && $x['published_at'] ? '<span class="pos">publicada ' . e(lisbon_fmt(strtotime((string)$x['published_at']), 'd/m')) . '</span>'
              : ($x && $x['scheduled_at'] ? 'agendada ' . e(date('d/m H:i', strtotime((string)$x['scheduled_at']))) : '<span class="muted">por agendar</span>')) . '</li>';
    }
    $html = '<h3 class="sp-name">' . e($p['title']) . '</h3>'
          . ($p['idea'] ? '<p class="mid">' . nl2br(e((string)$p['idea'])) . '</p>' : '')
          . $kv
          . ($p['status'] === 'producao' ? '<h4>Publicação</h4><ul class="mpubs">' . $pub . '</ul>' : '')
          . '<p class="sp-acts"><a class="btn sm" href="/admin/marketing_peca.php?id=' . (int)$p['id'] . '">Abrir a peça</a> '
          . '<a class="btn ghost sm" href="/admin/marketing.php?t=estudio&amp;peca=' . (int)$p['id'] . '">' . icon('sparkle') . ' Estúdio</a></p>'
          . '<h4>Histórico</h4>' . mkt_history_html($pdo, (int)$p['id']);
    return ['title' => 'Peça · Marketing', 'html' => $html, 'url' => '/admin/marketing_peca.php?id=' . (int)$p['id']];
}
