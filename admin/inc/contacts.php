<?php
/**
 * topper. admin — aba Contactos v2 (docs/ESPECIFICACAO-PLATAFORMA.md §13)
 *
 * Pessoas e empresas (uma empresa tem várias pessoas; freelancer = pessoa
 * sem empresa), serviços e preços de fornecedores e freelancers com
 * histórico, avaliação por trabalho, «Trazido por» (fontes de negócio),
 * relações acompanhadas com nível A/B/C e follow-ups automáticos, juntar
 * duplicados e importar CSV/vCard. As páginas e o ui_api.php verificam as
 * permissões antes de chamar estas funções.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/leads.php';   // telefones, emails, duplicados, tarefas, horas de Lisboa

/** Tipos (bandeiras na tabela contacts). A empresa não é freelancer. */
const CONTACT_TYPES = ['is_client' => 'Cliente', 'is_supplier' => 'Fornecedor', 'is_freelancer' => 'Freelancer', 'is_partner' => 'Parceiro'];
const COMPANY_TYPES = ['is_client', 'is_supplier', 'is_partner'];

/** Unidades dos preços (§13.3). */
const SERVICE_UNITS = ['hour' => 'hora', 'day' => 'dia', 'half_day' => 'meio dia', 'project' => 'projeto', 'anim_second' => 'segundo de animação', 'piece' => 'peça'];
const SERVICES_DEFAULT = ['3D Animation', '2D Animation', 'VFX', 'CGI', 'Edição', 'Compositing'];

/** Relações (§13.5). */
const REL_FORMALITY = ['proxima' => 'Próxima', 'cordial' => 'Cordial', 'formal' => 'Formal'];
const REL_BUSINESS  = ['corporate' => 'Corporate', 'medio' => 'Médio', 'pequeno' => 'Pequeno'];
const REL_CADENCE_DEFAULTS = ['A' => 7, 'B' => 15, 'C' => 30];
/** Tipo de tarefa só das relações (não aparece na Nova ação). */
const TASK_KIND_RELATION = ['relation' => ['Follow-up de relação', 'heart']];

/** Nome e ícone de um tipo de tarefa (os da Nova ação e o das relações). */
function task_kind_info(?string $k): array {
    return LEAD_TASK_KINDS[$k ?? ''] ?? TASK_KIND_RELATION[$k ?? ''] ?? LEAD_TASK_KINDS['task'];
}

// ── registos ───────────────────────────────────────────────

function contact_get(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT c.*, co.name AS company_name FROM contacts c LEFT JOIN contacts co ON co.id = c.company_id WHERE c.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** As pessoas de uma empresa. */
function contact_people(PDO $pdo, int $companyId): array {
    $st = $pdo->prepare("SELECT * FROM contacts WHERE company_id = ? AND kind = 'person' ORDER BY name");
    $st->execute([$companyId]);
    return $st->fetchAll();
}

/** Os tipos que valem para o contacto: os seus e, numa pessoa, os da empresa (menos freelancer). */
function contact_types(array $c, ?array $company = null): array {
    $out = [];
    foreach (CONTACT_TYPES as $f => $label) {
        if ((int)($c[$f] ?? 0) === 1 || ($company && $f !== 'is_freelancer' && (int)($company[$f] ?? 0) === 1)) $out[$f] = $label;
    }
    return $out;
}

/** Fornecedores e freelancers têm serviços, preços, avaliação e estado Ativo/Inativo. */
function contact_is_provider(array $c): bool {
    return (int)($c['is_supplier'] ?? 0) === 1 || (int)($c['is_freelancer'] ?? 0) === 1;
}

/** O que aparece por baixo do nome: a empresa (pessoa), «Freelancer», «Empresa». */
function contact_sub(array $c): string {
    if ($c['kind'] === 'company') return 'Empresa';
    if (!empty($c['company_name'])) return (string)$c['company_name'];
    return (int)$c['is_freelancer'] === 1 ? 'Freelancer' : 'Pessoa';
}

/** Regista um acontecimento no histórico do contacto. */
function contact_log(PDO $pdo, int $id, string $event, string $note = '', ?int $by = null): void {
    $u = current_user();
    $pdo->prepare('INSERT INTO contact_history (contact_id, event, note, user_id) VALUES (?,?,?,?)')
        ->execute([$id, $event, mb_substr($note, 0, 255), $by ?? ($u['id'] ?? null)]);
}

/** Superadmin e admins (confirmado na base de dados): Ativo/Inativo, serviços, cadências. */
function contacts_admin(PDO $pdo): bool {
    $st = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $st->execute([(int)(current_user()['id'] ?? 0)]);
    return role_is_admin((string)$st->fetchColumn());
}

// ── serviços e preços (§13.3) ──────────────────────────────

function services_list(PDO $pdo, bool $activeOnly = true): array {
    return $pdo->query('SELECT * FROM services' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY position, name')->fetchAll();
}

/** As linhas em vigor (ou todas, com o histórico) de um fornecedor/freelancer. */
function contact_service_lines(PDO $pdo, int $cid, bool $history = false): array {
    $st = $pdo->prepare('SELECT cs.*, s.name AS service_name FROM contact_services cs LEFT JOIN services s ON s.id = cs.service_id
                          WHERE cs.contact_id = ?' . ($history ? '' : ' AND cs.ended_at IS NULL') . '
                          ORDER BY COALESCE(s.position, 999), COALESCE(s.name, cs.label), cs.valid_from DESC, cs.id DESC');
    $st->execute([$cid]);
    return $st->fetchAll();
}

function service_line_label(array $r): string {
    return $r['service_name'] ?? ($r['label'] !== '' ? $r['label'] : 'Serviço por rever');
}

/** «280 €/dia», «desde 1 500 €», «por rever». */
function service_line_price(array $r): string {
    if ($r['price'] === null) return 'por rever';
    return number_format((float)$r['price'], (float)$r['price'] == floor((float)$r['price']) ? 0 : 2, ',', ' ') . ' €'
         . ($r['unit'] ? ' / ' . (SERVICE_UNITS[$r['unit']] ?? $r['unit']) : '');
}

/**
 * Grava uma linha de serviço. Mudar o preço, a unidade ou o IVA de uma linha em vigor fecha-a
 * (fica no histórico) e abre outra, válida desde a data indicada. Devolve o id da linha em vigor.
 */
function service_line_save(PDO $pdo, int $cid, ?int $lineId, array $v, ?int $by = null): int {
    $old = null;
    if ($lineId) {
        $st = $pdo->prepare('SELECT * FROM contact_services WHERE id = ? AND contact_id = ? AND ended_at IS NULL');
        $st->execute([$lineId, $cid]);
        $old = $st->fetch() ?: null;
        if (!$old) throw new RuntimeException('Esta linha já não está em vigor.');
    }
    $priceChanged = $old && ((string)$old['price'] !== (string)$v['price'] || (string)$old['unit'] !== (string)$v['unit']
                             || (int)$old['vat'] !== (int)$v['vat'] || (int)$old['service_id'] !== (int)$v['service_id']);
    if ($old && !$priceChanged) {
        $pdo->prepare('UPDATE contact_services SET notes = ?, valid_from = ? WHERE id = ?')->execute([$v['notes'], $v['valid_from'], $old['id']]);
        return (int)$old['id'];
    }
    if ($old) $pdo->prepare('UPDATE contact_services SET ended_at = ? WHERE id = ?')->execute([$v['valid_from'], $old['id']]);
    $pdo->prepare('INSERT INTO contact_services (contact_id, service_id, label, unit, price, vat, notes, valid_from, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$cid, $v['service_id'], $old['label'] ?? '', $v['unit'], $v['price'], $v['vat'], $v['notes'], $v['valid_from'], $by]);
    $new = (int)$pdo->lastInsertId();
    $name = (string)$pdo->query('SELECT name FROM services WHERE id = ' . (int)$v['service_id'])->fetchColumn();
    $now = service_line_price(['price' => $v['price'], 'unit' => $v['unit']]);
    contact_log($pdo, $cid, 'c_price', $old ? $name . ': ' . service_line_price($old) . ' → ' . $now : $name . ' · ' . $now, $by);
    return $new;
}

/** Os dados de uma linha vindos de um formulário (valida a lista de serviços e a unidade). */
function service_line_input(PDO $pdo, array $src): array {
    $sid = (int)($src['service_id'] ?? 0);
    $st = $pdo->prepare('SELECT COUNT(*) FROM services WHERE id = ?');
    $st->execute([$sid]);
    if (!(int)$st->fetchColumn()) throw new RuntimeException('Escolha o serviço da lista.');
    $unit = (string)($src['unit'] ?? '');
    if (!isset(SERVICE_UNITS[$unit])) throw new RuntimeException('Escolha a unidade.');
    // «1 500», «1.500», «1.500,50», «280», «32,5» → número; vazio = por rever.
    $raw = preg_replace('/[\s€\x{00A0}]/u', '', (string)($src['price'] ?? ''));
    if (str_contains($raw, ',') && str_contains($raw, '.')) $raw = str_replace(['.', ','], ['', '.'], $raw);
    elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $raw)) $raw = str_replace('.', '', $raw);   // «1.500» = mil e quinhentos
    else $raw = str_replace(',', '.', $raw);
    if ($raw !== '' && !is_numeric($raw)) throw new RuntimeException('Indique um preço válido.');
    $price = $raw === '' ? null : round((float)$raw, 2);
    if ($price !== null && ($price < 0 || $price > 10000000)) throw new RuntimeException('Indique um preço válido.');
    $from = (string)($src['valid_from'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = today_lisbon();
    return ['service_id' => $sid, 'unit' => $unit, 'price' => $price, 'vat' => !empty($src['vat']) ? 1 : 0,
            'notes' => mb_substr(trim((string)($src['notes'] ?? '')), 0, 2000), 'valid_from' => $from];
}

/**
 * Para a Orçamentação (a desenhar): os fornecedores e freelancers ATIVOS que prestam um
 * serviço, com o preço em vigor, a unidade e a avaliação média.
 */
function suppliers_for_service(PDO $pdo, int $serviceId): array {
    $st = $pdo->prepare('SELECT c.id, c.name, c.kind, cs.price, cs.unit, cs.vat,
                                (SELECT AVG(stars) FROM contact_ratings r WHERE r.contact_id = c.id) AS rating
                           FROM contact_services cs JOIN contacts c ON c.id = cs.contact_id
                          WHERE cs.service_id = ? AND cs.ended_at IS NULL AND c.is_active = 1
                            AND (c.is_supplier = 1 OR c.is_freelancer = 1)
                          ORDER BY rating DESC, c.name');
    $st->execute([$serviceId]);
    return $st->fetchAll();
}

// ── avaliação (§13.3: média real das avaliações por trabalho) ──

function contact_rating(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT AVG(stars), COUNT(*) FROM contact_ratings WHERE contact_id = ?');
    $st->execute([$cid]);
    [$avg, $n] = $st->fetch(PDO::FETCH_NUM);
    return [$avg !== null ? round((float)$avg, 1) : null, (int)$n];
}

/** «★★★★☆ 4,3 (6)». */
function rating_html(?float $avg, int $n): string {
    if ($avg === null) return '<span class="muted">—</span>';
    $full = (int)round($avg);
    return '<span class="stars" aria-label="Avaliação ' . number_format($avg, 1, ',', '') . ' de 5 em ' . $n . '">'
         . str_repeat('★', $full) . '<span class="muted">' . str_repeat('☆', 5 - $full) . '</span></span> '
         . '<span class="mono">' . number_format($avg, 1, ',', '') . '</span> <span class="muted">(' . $n . ')</span>';
}

/** A avaliação de um custo de fornecedor pago (1–5); uma por custo. */
function cost_rate(PDO $pdo, array $cost, int $stars, ?int $by = null): void {
    if ($stars < 1 || $stars > 5) throw new RuntimeException('Escolha de 1 a 5 estrelas.');
    if (!$cost['contact_id']) throw new RuntimeException('Este custo não tem fornecedor.');
    $st = $pdo->prepare('SELECT COUNT(*) FROM contact_ratings WHERE cost_id = ?');
    $st->execute([(int)$cost['id']]);
    if ((int)$st->fetchColumn()) throw new RuntimeException('Este trabalho já foi avaliado.');
    $pdo->prepare('INSERT INTO contact_ratings (contact_id, cost_id, deal_id, stars, user_id) VALUES (?,?,?,?,?)')
        ->execute([(int)$cost['contact_id'], (int)$cost['id'], $cost['deal_id'], $stars, $by]);
    $code = $cost['deal_id'] ? (string)$pdo->query('SELECT code FROM deals WHERE id = ' . (int)$cost['deal_id'])->fetchColumn() : '';
    contact_log($pdo, (int)$cost['contact_id'], 'c_rating', 'Avaliado ' . $stars . '★' . ($code !== '' ? ' · ' . $code : '') . ' · ' . $cost['name'], $by);
}

/** Um custo fechado de fornecedor/freelancer que ainda não foi avaliado (para pedir as estrelas). */
function cost_needs_rating(PDO $pdo, int $costId): ?array {
    $st = $pdo->prepare('SELECT co.*, c.name AS contact_name FROM costs co JOIN contacts c ON c.id = co.contact_id
                          WHERE co.id = ? AND co.is_closed = 1 AND (c.is_supplier = 1 OR c.is_freelancer = 1)
                            AND NOT EXISTS (SELECT 1 FROM contact_ratings r WHERE r.cost_id = co.id)');
    $st->execute([$costId]);
    return $st->fetch() ?: null;
}

// ── relações (§13.5) ───────────────────────────────────────

function relation_get(PDO $pdo, int $cid): ?array {
    $st = $pdo->prepare('SELECT r.*, u.name AS owner_name FROM relations r LEFT JOIN users u ON u.id = r.owner_id WHERE r.contact_id = ?');
    $st->execute([$cid]);
    return $st->fetch() ?: null;
}

/**
 * Nível automático: pontos = potencial × 2 + tipo de negócio (Corporate 3 · Médio 2 ·
 * Pequeno 1) + formalidade (Próxima 2 · Cordial 1 · Formal 0). A ≥ 11 · B ≥ 7 · C abaixo.
 */
function relation_level(string $formality, int $potential, string $business): string {
    $pts = max(1, min(5, $potential)) * 2 + (['corporate' => 3, 'medio' => 2, 'pequeno' => 1][$business] ?? 1)
         + (['proxima' => 2, 'cordial' => 1, 'formal' => 0][$formality] ?? 0);
    return $pts >= 11 ? 'A' : ($pts >= 7 ? 'B' : 'C');
}

/** A linha da relação tal como está na tabela (para o «Anular» a repor). */
function relation_snapshot(PDO $pdo, int $cid): ?array {
    $st = $pdo->prepare('SELECT contact_id, formality, potential, business, level, owner_id, cadence_days, next_at, last_touch_at, last_touch_prev,
                                touches, paused_until, reviewed_at, created_at FROM relations WHERE contact_id = ?');
    $st->execute([$cid]);
    return $st->fetch() ?: null;
}

/** Dias entre toques: a exceção do contacto ou a do nível (Definições, globais). */
function relation_cadence(PDO $pdo, array $r): int {
    if ((int)($r['cadence_days'] ?? 0) > 0) return (int)$r['cadence_days'];
    $lvl = (string)$r['level'];
    return max(1, min(365, (int)lead_setting($pdo, 'rel_cadence_' . strtolower($lvl), REL_CADENCE_DEFAULTS[$lvl] ?? 30)));
}

/**
 * Saúde da relação: o tempo desde o último toque face à próxima data (anel como o prazo das
 * leads: até 50 % amarelo, até 100 % laranja, depois vermelho). Pausada: sem anel.
 */
function relation_health(PDO $pdo, array $r, ?int $now = null): ?array {
    if (!empty($r['paused_until']) && $r['paused_until'] > today_lisbon()) return null;
    $now ??= time();
    $since = lisbon_ts((string)($r['last_touch_at'] ?: $r['created_at']));
    $due = $r['next_at'] ? lisbon_ts($r['next_at'] . ' 23:59:59') : $since + relation_cadence($pdo, $r) * 86400;
    $span = max(3600, $due - $since);
    $pct = ($now - $since) / $span;
    $late = $now > $due;
    $days = (int)floor(($now - $since) / 86400);
    $total = max(1, (int)round($span / 86400));
    return ['pct' => $pct, 'level' => $late ? 'late' : ($pct >= 0.5 ? 'near' : 'ok'), 'start' => $since, 'due' => $due,
            'label' => $late ? '+' . max(1, (int)ceil(($now - $due) / 86400)) . ' d' : $days . ' / ' . $total . ' d',
            'tip' => ($late ? 'Toque em atraso desde ' : 'Próximo toque até ') . lisbon_fmt($due, 'd/m/Y')];
}

/** Marca um toque (follow-up feito): último toque agora e a próxima data. */
function relation_touch(PDO $pdo, int $cid, ?string $next = null, string $note = '', ?int $by = null): void {
    $r = relation_get($pdo, $cid);
    if (!$r) return;
    $next ??= (new DateTimeImmutable(today_lisbon()))->modify('+' . relation_cadence($pdo, $r) . ' days')->format('Y-m-d');
    $pdo->prepare('UPDATE relations SET last_touch_prev = last_touch_at, last_touch_at = ?, next_at = ?, touches = touches + 1 WHERE contact_id = ?')
        ->execute([now_php(), $next, $cid]);
    contact_log($pdo, $cid, 'rel_touch', ($note !== '' ? $note . ' · ' : '') . 'próximo toque ' . lisbon_fmt(lisbon_ts($next . ' 12:00'), 'd/m'), $by);
}

/**
 * Na data, a tarefa «Follow-up de relação: <nome>» para o responsável (uma de cada vez).
 * Corre na tarefa agendada diária e quando se abre Contactos ou Tarefas. Barato: só
 * olha para as relações com data já chegada e sem tarefa aberta.
 */
function relation_tick(PDO $pdo): int {
    static $done = false;
    if ($done || !db_has_table($pdo, 'relations')) return 0;
    $done = true;
    $today = today_lisbon();
    // Pausas que acabaram: a relação volta e o próximo toque é na data de regresso.
    $pdo->prepare('UPDATE relations SET next_at = paused_until, paused_until = NULL WHERE paused_until IS NOT NULL AND paused_until <= ?')->execute([$today]);
    $st = $pdo->prepare("SELECT r.*, c.name FROM relations r JOIN contacts c ON c.id = r.contact_id
                          WHERE r.paused_until IS NULL AND r.next_at IS NOT NULL AND r.next_at <= ?
                            AND NOT EXISTS (SELECT 1 FROM tasks t WHERE t.contact_id = r.contact_id AND t.kind = 'relation' AND t.status <> 'done')");
    $st->execute([$today]);
    $n = 0;
    foreach ($st->fetchAll() as $r) {
        contact_task_create($pdo, (int)$r['contact_id'], 'relation', 'Follow-up de relação: ' . $r['name'], relation_suggestion($pdo, $r),
                            $today, null, $r['owner_id'] !== null ? (int)$r['owner_id'] : null, '', null);
        $n++;
    }
    return $n;
}

/**
 * Variação do toque: uma sugestão diferente de cada vez (projeto novo, café, almoço…) e o
 * aniversário quando está perto.
 */
function relation_suggestion(PDO $pdo, array $r): string {
    $c = contact_get($pdo, (int)$r['contact_id']);
    if ($c && $c['birthday']) {
        $bd = new DateTimeImmutable($c['birthday']);
        $today = new DateTimeImmutable(today_lisbon());
        $next = $today->setDate((int)$today->format('Y'), (int)$bd->format('m'), (int)$bd->format('d'));
        if ($next < $today) $next = $next->modify('+1 year');
        $days = (int)$today->diff($next)->days;
        if ($days <= 14) return 'Aniversário ' . ($days === 0 ? 'hoje' : 'a ' . $next->format('d/m')) . ': mandar uma mensagem.';
    }
    $ideas = ['Enviar um projeto novo do portefólio.', 'Convite para um café.', 'Partilhar uma novidade ou um making-of.',
              'Convite para almoço.', 'Perguntar pelos projetos que têm em mãos.'];
    return 'Sugestão: ' . $ideas[(int)($r['touches'] ?? 0) % count($ideas)];
}

/**
 * Revisão de nível a cada 6 meses: propõe subir (negócios gerados nesse tempo) ou descer
 * (nenhum). Devolve null quando ainda não é altura.
 */
function relation_review(PDO $pdo, array $r): ?array {
    $since = (string)($r['reviewed_at'] ?: substr((string)$r['created_at'], 0, 10));
    if ($since > (new DateTimeImmutable(today_lisbon()))->modify('-6 months')->format('Y-m-d')) return null;
    $ids = contact_source_ids($pdo, (int)$r['contact_id']);
    $st = $pdo->prepare('SELECT COUNT(*), SUM(phase IN (\'production\',\'done\')) FROM deals WHERE brought_by_id IN (' . implode(',', $ids) . ') AND created_at >= ?');
    $st->execute([$since . ' 00:00:00']);
    [$gen, $won] = array_map('intval', $st->fetch(PDO::FETCH_NUM));
    $pot = (int)$r['potential'];
    if (($won >= 1 || $gen >= 2) && $pot < 5) return ['dir' => 'up', 'potential' => $pot + 1, 'why' => "gerou $gen " . ($gen === 1 ? 'negócio' : 'negócios') . " nos últimos 6 meses"];
    if ($gen === 0 && $pot > 1) return ['dir' => 'down', 'potential' => $pot - 1, 'why' => 'não gerou negócios nos últimos 6 meses'];
    return ['dir' => 'keep', 'potential' => $pot, 'why' => "gerou $gen " . ($gen === 1 ? 'negócio' : 'negócios') . ' nos últimos 6 meses'];
}

/** As classificações da relação vindas de um formulário (validadas). */
function relation_input(PDO $pdo, array $src): array {
    $f = (string)($src['formality'] ?? '');
    $b = (string)($src['business'] ?? '');
    $p = (int)($src['potential'] ?? 0);
    if (!isset(REL_FORMALITY[$f])) throw new RuntimeException('Escolha a formalidade.');
    if (!isset(REL_BUSINESS[$b])) throw new RuntimeException('Escolha o tipo de negócio.');
    if ($p < 1 || $p > 5) throw new RuntimeException('Escolha o potencial (1 a 5).');
    $owner = (int)($src['owner_id'] ?? 0) ?: null;
    if ($owner !== null) {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$owner]);
        $t = $st->fetch();
        if (!$t || ((int)$t['id'] !== (int)current_user()['id'] && !can_assign_to(me_row($pdo), $t))) throw new RuntimeException('Não pode dar esta relação a essa pessoa.');
    }
    $cad = trim((string)($src['cadence_days'] ?? ''));
    if ($cad !== '' && (!ctype_digit($cad) || (int)$cad < 1 || (int)$cad > 365)) throw new RuntimeException('A exceção de cadência é um número de dias (1 a 365).');
    return ['formality' => $f, 'potential' => $p, 'business' => $b, 'level' => relation_level($f, $p, $b),
            'owner_id' => $owner, 'cadence_days' => $cad === '' ? null : (int)$cad];
}

/** Começa a acompanhar (ou muda as classificações). A primeira data vem da cadência do nível. */
function relation_set(PDO $pdo, int $cid, array $v, ?int $by = null): array {
    $old = relation_get($pdo, $cid);
    if (!$old) {
        $next = (new DateTimeImmutable(today_lisbon()))->modify('+' . relation_cadence($pdo, $v) . ' days')->format('Y-m-d');
        $pdo->prepare('INSERT INTO relations (contact_id, formality, potential, business, level, owner_id, cadence_days, next_at, reviewed_at, created_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $v['formality'], $v['potential'], $v['business'], $v['level'], $v['owner_id'], $v['cadence_days'], $next, today_lisbon(), now_php()]);
        contact_log($pdo, $cid, 'rel_start', 'Nível ' . $v['level'] . ' · ' . REL_FORMALITY[$v['formality']] . ' · potencial ' . $v['potential'] . ' · '
                    . REL_BUSINESS[$v['business']] . ' · a cada ' . relation_cadence($pdo, $v) . ' dias', $by);
        return relation_get($pdo, $cid);
    }
    // Com a cadência nova, a próxima data conta a partir do último toque (ou de hoje).
    $cadOld = relation_cadence($pdo, $old);
    $cadNew = relation_cadence($pdo, $v);
    $next = $old['next_at'];
    if ($cadOld !== $cadNew) {
        $base = $old['last_touch_at'] ? substr((string)$old['last_touch_at'], 0, 10) : today_lisbon();
        $next = (new DateTimeImmutable($base))->modify('+' . $cadNew . ' days')->format('Y-m-d');
        if ($next < today_lisbon()) $next = today_lisbon();
    }
    $pdo->prepare('UPDATE relations SET formality = ?, potential = ?, business = ?, level = ?, owner_id = ?, cadence_days = ?, next_at = ? WHERE contact_id = ?')
        ->execute([$v['formality'], $v['potential'], $v['business'], $v['level'], $v['owner_id'], $v['cadence_days'], $next, $cid]);
    // A tarefa aberta passa para o novo responsável.
    if ((int)$old['owner_id'] !== (int)$v['owner_id']) {
        $pdo->prepare("UPDATE tasks SET member_id = ? WHERE contact_id = ? AND kind = 'relation' AND status <> 'done'")
            ->execute([member_for_user($pdo, $v['owner_id']), $cid]);
    }
    $ch = [];
    if ($old['level'] !== $v['level']) $ch[] = 'nível ' . $old['level'] . ' → ' . $v['level'];
    if ($cadOld !== $cadNew) $ch[] = 'a cada ' . $cadNew . ' dias';
    if ((int)$old['owner_id'] !== (int)$v['owner_id']) $ch[] = 'novo responsável';
    contact_log($pdo, $cid, 'rel_edit', $ch ? ucfirst(implode(' · ', $ch)) : 'Classificações revistas', $by);
    return relation_get($pdo, $cid);
}

/** Pausa até uma data (a relação volta sozinha nesse dia). */
function relation_pause(PDO $pdo, int $cid, string $until, ?int $by = null): void {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until <= today_lisbon()) throw new RuntimeException('Escolha uma data de regresso no futuro.');
    $pdo->prepare('UPDATE relations SET paused_until = ? WHERE contact_id = ?')->execute([$until, $cid]);
    // O follow-up que estava aberto sai do Foco (volta a ser criado no regresso).
    $pdo->prepare("DELETE FROM tasks WHERE contact_id = ? AND kind = 'relation' AND status <> 'done'")->execute([$cid]);
    contact_log($pdo, $cid, 'rel_pause', 'Até ' . lisbon_fmt(lisbon_ts($until . ' 12:00'), 'd/m/Y'), $by);
}

function relation_resume(PDO $pdo, int $cid, ?int $by = null): void {
    $pdo->prepare('UPDATE relations SET paused_until = NULL, next_at = ? WHERE contact_id = ?')->execute([today_lisbon(), $cid]);
    contact_log($pdo, $cid, 'rel_resume', 'Próximo toque hoje', $by);
}

/** Deixa de acompanhar: a relação e o follow-up aberto saem (o histórico fica). */
function relation_stop(PDO $pdo, int $cid, ?int $by = null): void {
    $pdo->prepare("DELETE FROM tasks WHERE contact_id = ? AND kind = 'relation' AND status <> 'done'")->execute([$cid]);
    $pdo->prepare('DELETE FROM relations WHERE contact_id = ?')->execute([$cid]);
    contact_log($pdo, $cid, 'rel_stop', '', $by);
}

/** O valor gerado (faturado) por contacto, para a lista das Relações. */
function relation_generated(PDO $pdo, array $cids): array {
    $out = [];
    foreach ($cids as $cid) {
        $ids = contact_source_ids($pdo, (int)$cid);
        $rows = $pdo->query("SELECT * FROM deals WHERE phase IN ('production','done') AND brought_by_id IN (" . implode(',', $ids) . ')')->fetchAll();
        $out[(int)$cid] = array_sum(array_map(fn($d) => record_reach($pdo, $d) ? (float)$d['budget'] : 0.0, $rows));
    }
    return $out;
}

// ── tarefas ligadas a um contacto (Nova ação, follow-ups) ──

function contact_task_create(PDO $pdo, int $cid, string $kind, string $title, string $notes, ?string $due, ?int $duration,
                             ?int $assigneeUserId, string $repeat = '', ?int $by = null): int {
    require_once __DIR__ . '/tasks.php';
    $kind = isset(LEAD_TASK_KINDS[$kind]) || isset(TASK_KIND_RELATION[$kind]) ? $kind : 'task';
    $member = member_for_user($pdo, $assigneeUserId);
    $date = $due ? substr($due, 0, 10) : null;
    $time = $due && strlen($due) >= 16 ? substr($due, 11, 5) . ':00' : null;
    $now = task_now();
    $pdo->prepare(
        'INSERT INTO tasks (title, description, member_id, job_name, contact_id, due_date, due_time, duration_min, kind, repeat_rule,
                            priority, status, position, created_at, updated_at, created_by)
         VALUES (?,?,?,\'\',?,?,?,?,?,?,\'media\',\'todo\',?,?,?,?)'
    )->execute([mb_substr($title, 0, 200), $notes, $member, $cid, $date, $time, $duration, $kind,
                isset(TASK_REPEAT[$repeat]) ? $repeat : '', task_next_position($pdo, $member), $now, $now, $by]);
    $tid = (int)$pdo->lastInsertId();
    $label = task_kind_info($kind)[0];
    contact_log($pdo, $cid, 'task_new', str_starts_with($title, $label) ? $title : $label . ' · ' . $title, $by);
    return $tid;
}

function contact_open_tasks(PDO $pdo, int $cid): array {
    $st = $pdo->prepare('SELECT t.*, tm.name AS who FROM tasks t LEFT JOIN team_members tm ON tm.id = t.member_id
                          WHERE t.status <> \'done\' AND t.contact_id = ?
                          ORDER BY t.due_date IS NULL, t.due_date, t.due_time IS NULL, t.due_time, t.id');
    $st->execute([$cid]);
    return $st->fetchAll();
}

// ── fontes de negócio (§13.4) ──────────────────────────────

/** O contacto e quem conta com ele: a empresa da pessoa, ou as pessoas da empresa. */
function contact_source_ids(PDO $pdo, int $cid): array {
    $c = contact_get($pdo, $cid);
    $ids = [$cid];
    if ($c && $c['kind'] === 'person' && $c['company_id']) $ids[] = (int)$c['company_id'];
    if ($c && $c['kind'] === 'company') foreach (contact_people($pdo, $cid) as $p) $ids[] = (int)$p['id'];
    return array_values(array_unique(array_map('intval', $ids)));
}

/**
 * Leads e negócios trazidos pelo contacto (só os que quem pede pode ver), com os totais:
 * gerados, faturado (budget s/ IVA dos ganhos — só para quem vê o Financeiro) e conversão
 * (ganhos ÷ fechados: ganhos + perdidos + leads arquivadas).
 */
function contact_sources(PDO $pdo, int $cid): array {
    $ids = contact_source_ids($pdo, $cid);
    $st = $pdo->query('SELECT d.*, cl.name AS client_name FROM deals d LEFT JOIN contacts cl ON cl.id = d.contact_id
                        WHERE d.brought_by_id IN (' . implode(',', $ids) . ') ORDER BY d.created_at DESC');
    $rows = array_values(array_filter($st->fetchAll(), fn($d) => record_reach($pdo, $d)));
    $won = $lost = 0; $billed = 0.0;
    foreach ($rows as $d) {
        if (in_array($d['phase'], ['production', 'done'], true)) { $won++; $billed += (float)$d['budget']; }
        if (in_array($d['phase'], ['lost', 'archived'], true)) $lost++;
    }
    return ['rows' => $rows, 'generated' => count($rows), 'billed' => $billed,
            'conversion' => $won + $lost > 0 ? (int)round($won * 100 / ($won + $lost)) : null];
}

/** «Lead · Novo», «X-Ray», «Concluído», «Perdido». */
function source_state(PDO $pdo, array $d): string {
    if ($d['phase'] === 'lead') return 'Lead · ' . (LEAD_STATE[$d['lead_state']] ?? $d['lead_state']);
    if ($d['phase'] === 'archived') return 'Lead arquivada';
    if ($d['phase'] === 'lost') return 'Perdido';
    if ($d['phase'] === 'done') return 'Concluído';
    if ($d['phase'] === 'production') return 'Em produção';
    $s = $d['stage_id'] ? (string)$pdo->query('SELECT name FROM funnel_stages WHERE id = ' . (int)$d['stage_id'])->fetchColumn() : '';
    return $s !== '' ? $s : 'Pipeline';
}

// ── duplicados e juntar (§13.6) ────────────────────────────

/** Outros contactos com o mesmo email ou telefone (sem contar a empresa e as pessoas ligadas). */
function contact_duplicates(PDO $pdo, array $c): array {
    $d = find_duplicates($pdo, (string)$c['email'], (string)$c['phone']);
    $linked = [(int)$c['id'], (int)($c['company_id'] ?? 0)];
    if ($c['kind'] === 'company') foreach (contact_people($pdo, (int)$c['id']) as $p) $linked[] = (int)$p['id'];
    return array_values(array_filter($d['contacts'], fn($x) => !in_array((int)$x['id'], $linked, true) && $x['kind'] === $c['kind']));
}

/**
 * Junta $otherId em $keepId: tudo o que apontava para o outro (negócios, leads, custos,
 * histórico, tarefas, serviços, avaliações, pessoas da empresa) passa para o que fica; os
 * campos vazios do que fica são preenchidos; o outro é apagado. Tudo ou nada.
 */
function contact_merge(PDO $pdo, int $keepId, int $otherId, ?int $by = null): void {
    $k = contact_get($pdo, $keepId);
    $o = contact_get($pdo, $otherId);
    if (!$k || !$o || $keepId === $otherId) throw new RuntimeException('Contactos não encontrados.');
    if ($k['kind'] !== $o['kind']) throw new RuntimeException('Só se juntam contactos do mesmo tipo (pessoa com pessoa, empresa com empresa).');
    $pdo->beginTransaction();
    try {
        $set = [];
        foreach (['email', 'nif', 'address', 'role', 'birthday', 'company_id'] as $f) {
            if ((string)$k[$f] === '' && (string)$o[$f] !== '') $set[$f] = $o[$f];
        }
        $phones = array_values(array_unique(array_merge(lead_phones((string)$k['phone']), lead_phones((string)$o['phone']))));
        if ($phones) $set['phone'] = mb_substr(implode(' | ', $phones), 0, 255);
        elseif ((string)$k['phone'] === '' && (string)$o['phone'] !== '') $set['phone'] = $o['phone'];
        $links = array_values(array_unique(array_filter(array_merge(preg_split('/\s*[|\n]\s*/', (string)$k['links']), preg_split('/\s*[|\n]\s*/', (string)$o['links'])), 'strlen')));
        $set['links'] = implode("\n", $links);
        if (trim((string)$o['notes']) !== '') $set['notes'] = trim((string)$k['notes'] . "\n\n[Juntado de «" . $o['name'] . "»] " . $o['notes']);
        foreach (array_keys(CONTACT_TYPES) as $f) if ((int)$o[$f] === 1) $set[$f] = 1;
        if ($set) $pdo->prepare('UPDATE contacts SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($set))) . ' WHERE id = ?')
                      ->execute([...array_values($set), $keepId]);
        foreach ([['deals', 'contact_id'], ['deals', 'company_id'], ['deals', 'person_id'], ['deals', 'brought_by_id'], ['costs', 'contact_id'],
                  ['interactions', 'contact_id'], ['contact_history', 'contact_id'], ['contact_services', 'contact_id'],
                  ['contact_ratings', 'contact_id'], ['tasks', 'contact_id'], ['contacts', 'company_id']] as [$t, $col]) {
            $pdo->prepare("UPDATE $t SET $col = ? WHERE $col = ?")->execute([$keepId, $otherId]);
        }
        $pdo->prepare((db_is_sqlite($pdo) ? 'UPDATE OR IGNORE' : 'UPDATE IGNORE') . ' deal_contacts SET contact_id = ? WHERE contact_id = ?')->execute([$keepId, $otherId]);
        $pdo->prepare('DELETE FROM deal_contacts WHERE contact_id = ?')->execute([$otherId]);
        if (!relation_get($pdo, $keepId)) $pdo->prepare('UPDATE relations SET contact_id = ? WHERE contact_id = ?')->execute([$keepId, $otherId]);
        $pdo->prepare('DELETE FROM relations WHERE contact_id = ?')->execute([$otherId]);
        $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$otherId]);
        contact_log($pdo, $keepId, 'c_merge', 'Juntado com «' . $o['name'] . '» (#' . $otherId . '): o histórico dos dois fica aqui', $by);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── criar e gravar ─────────────────────────────────────────

/** Os campos de um contacto vindos de um formulário (criar ou Editar/Gravar). */
function contact_input(array $src, string $kind): array {
    $t = static fn(string $k, int $n) => mb_substr(trim(str_replace("\r\n", "\n", (string)($src[$k] ?? ''))), 0, $n);
    $phones = lead_phones($t('phone', 255));
    $links = array_values(array_unique(array_filter(array_map('trim', preg_split('/\s*[|\n]\s*/', $t('links', 4000))), 'strlen')));
    $bday = $t('birthday', 10);
    $v = [
        'name'     => $t('name', 160),
        'role'     => $kind === 'person' ? $t('role', 120) : '',
        'email'    => $t('email', 190),
        'phone'    => $phones ? mb_substr(implode(' | ', $phones), 0, 255) : $t('phone', 255),
        'links'    => implode("\n", $links),
        'nif'      => $t('nif', 20),
        'address'  => $t('address', 255),
        'birthday' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $bday) ? $bday : null,
        'notes'    => $t('notes', 8000),
    ];
    foreach (array_keys(CONTACT_TYPES) as $f) {
        $v[$f] = !empty($src[$f]) && ($kind === 'person' || $f !== 'is_freelancer') ? 1 : 0;
    }
    return $v;
}

/**
 * Cria um contacto. Numa pessoa, «Empresa» procura uma existente pelo nome ou cria-a.
 * Devolve o id.
 */
function contact_create(PDO $pdo, string $kind, array $v, string $companyName = '', ?int $by = null): int {
    if ($v['name'] === '') throw new RuntimeException('Indique o nome.');
    $companyId = null;
    if ($kind === 'person' && trim($companyName) !== '') {
        $companyId = lead_company_contact($pdo, $companyName, [], $by);
    }
    $cols = array_merge(['kind', 'company_id', 'is_active', 'created_by'], array_keys($v));
    $pdo->prepare('INSERT INTO contacts (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute([$kind, $companyId, 1, $by, ...array_values($v)]);
    $id = (int)$pdo->lastInsertId();
    contact_log($pdo, $id, 'c_create', $kind === 'person' ? ($companyId ? 'Pessoa · ' . $companyName : 'Pessoa') : 'Empresa', $by);
    return $id;
}

/** Muda a empresa de uma pessoa (fica no histórico). '' = sem empresa. */
function contact_set_company(PDO $pdo, array $c, string $companyName, ?int $by = null): void {
    $companyName = trim($companyName);
    $new = $companyName === '' ? null : lead_company_contact($pdo, $companyName, [], $by);
    if ((int)$new === (int)$c['company_id']) return;
    $pdo->prepare('UPDATE contacts SET company_id = ? WHERE id = ?')->execute([$new, $c['id']]);
    contact_log($pdo, (int)$c['id'], 'c_company', (($c['company_name'] ?? '') !== '' ? $c['company_name'] : 'sem empresa') . ' → ' . ($companyName !== '' ? $companyName : 'sem empresa'), $by);
}

/** «Ana Ribeiro · AIM Creative Studios», «Brandlab (empresa)». */
function contact_label(array $c): string {
    return $c['name'] . ($c['kind'] === 'person' ? (!empty($c['company_name']) ? ' · ' . $c['company_name'] : '') : ' (empresa)');
}

/** Para escolher um contacto escrevendo (Trazido por): pelo nome, email ou empresa. */
function contact_pick_search(PDO $pdo, string $q, int $limit = 8): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $l = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $st = $pdo->prepare("SELECT c.id, c.kind, c.name, c.email, co.name AS company_name FROM contacts c LEFT JOIN contacts co ON co.id = c.company_id
                          WHERE c.name LIKE ? ESCAPE '!' OR c.email LIKE ? ESCAPE '!' OR co.name LIKE ? ESCAPE '!'
                          ORDER BY c.name LIKE ? ESCAPE '!' DESC, c.name LIMIT " . (int)$limit);
    $st->execute([$l, $l, $l, strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%']);
    return array_map(fn($c) => ['id' => (int)$c['id'], 'label' => contact_label($c), 'kind' => $c['kind']], $st->fetchAll());
}

/**
 * O contacto escolhido num campo «Trazido por»: o id escondido (posto pelo JavaScript ao escolher
 * da lista) ou, sem ele, o nome escrito tal e qual (pessoa ou empresa). Campo vazio = nenhum (null).
 */
function contact_pick_resolve(PDO $pdo, $id, string $text): ?int {
    $text = trim($text);
    if ($text === '') return null;
    $id = (int)$id;
    if ($id > 0 && ($c = contact_get($pdo, $id)) && in_array(mb_strtolower($text), [mb_strtolower(contact_label($c)), mb_strtolower($c['name'])], true)) return $id;
    $name = trim(preg_replace('/\s*(·.*|\(empresa\))$/u', '', $text));
    $st = $pdo->prepare('SELECT id FROM contacts WHERE LOWER(TRIM(name)) = ? ORDER BY kind = \'person\' DESC, id LIMIT 1');
    $st->execute([mb_strtolower($name)]);
    $found = $st->fetchColumn();
    if (!$found) throw new RuntimeException('«' . $name . '» não está em Contactos. Escolha da lista ou crie o contacto primeiro.');
    return (int)$found;
}

/** O campo para escolher um contacto escrevendo (o app.js sugere a partir de Contactos). */
function contact_pick_html(PDO $pdo, string $field, string $idField, ?int $current, string $inputId, string $placeholder = 'Procurar em Contactos…'): string {
    $label = '';
    if ($current && ($c = contact_get($pdo, $current))) $label = contact_label($c);
    return '<span class="cpick" data-contact-pick><input type="text" id="' . e($inputId) . '" name="' . e($field) . '" list="' . e($inputId) . '-dl" maxlength="200" autocomplete="off"'
         . ' value="' . e($label) . '" placeholder="' . e($placeholder) . '"><input type="hidden" name="' . e($idField) . '" value="' . ($current ?: '') . '">'
         . '<datalist id="' . e($inputId) . '-dl">' . ($label !== '' ? '<option value="' . e($label) . '">' : '') . '</datalist></span>';
}

/** Muda o «Trazido por» de uma lead ou negócio (fica no histórico dos dois lados). */
function deal_set_brought_by(PDO $pdo, array $d, ?int $to, ?int $by = null): bool {
    $from = $d['brought_by_id'] !== null ? (int)$d['brought_by_id'] : null;
    if ($from === $to) return false;
    $pdo->prepare('UPDATE deals SET brought_by_id = ? WHERE id = ?')->execute([$to, $d['id']]);
    $name = fn(?int $id) => $id ? (string)(contact_get($pdo, $id)['name'] ?? '?') : 'ninguém';
    deal_log($pdo, (int)$d['id'], 'brought', ['note' => 'Trazido por: ' . $name($from) . ' → ' . $name($to), 'user_id' => $by]);
    $ref = $d['code'] ?: lead_display_name($d);
    if ($to) contact_log($pdo, $to, 'c_source', ($d['phase'] === 'lead' || $d['phase'] === 'archived' ? 'Lead ' : 'Negócio ') . $ref, $by);
    return true;
}

/** Os contactos com um possível duplicado do mesmo tipo (mesmo email ou telefone). */
function contact_dup_ids(PDO $pdo): array {
    $by = [];
    foreach ($pdo->query('SELECT id, kind, email, phone FROM contacts')->fetchAll() as $c) {
        $keys = [];
        if (($ek = email_key((string)$c['email'])) !== '') $keys[] = 'e:' . $ek;
        foreach (lead_phones((string)$c['phone']) as $p) if (strlen($k = phone_key($p)) >= 6) $keys[] = 'p:' . $k;
        foreach (array_unique($keys) as $k) $by[$c['kind'] . '|' . $k][] = (int)$c['id'];
    }
    $out = [];
    // Pessoa com pessoa e empresa com empresa (uma empresa e as suas pessoas partilham o telefone geral).
    foreach ($by as $ids) if (count($ids) > 1) foreach ($ids as $i) $out[$i] = true;
    return array_keys($out);
}

/** O que impede eliminar um contacto (negócios, custos, fontes, pessoas). */
function contact_refs(PDO $pdo, int $cid): int {
    $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM deal_contacts WHERE contact_id = ?)
                              + (SELECT COUNT(*) FROM deals WHERE contact_id = ? OR company_id = ? OR person_id = ? OR brought_by_id = ?)
                              + (SELECT COUNT(*) FROM costs WHERE contact_id = ?)
                              + (SELECT COUNT(*) FROM contacts WHERE company_id = ?)');
    $st->execute([$cid, $cid, $cid, $cid, $cid, $cid, $cid]);
    return (int)$st->fetchColumn();
}

/** Os campos que «Editar» grava (para o «Anular» repor). */
const CONTACT_EDIT_FIELDS = ['name', 'role', 'email', 'phone', 'links', 'nif', 'address', 'birthday', 'notes',
                             'is_client', 'is_supplier', 'is_freelancer', 'is_partner', 'company_id'];

function contact_snapshot(array $c): array {
    $o = [];
    foreach (CONTACT_EDIT_FIELDS as $f) $o[$f] = $c[$f] ?? null;
    return $o;
}

// ── importar CSV e vCard (§13.6) ───────────────────────────

/** Lê um CSV (cabeçalho na 1.ª linha; «;» ou «,») ou um vCard. Devolve as linhas normalizadas. */
function contact_import_parse(string $raw, string $filename): array {
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $rows = preg_match('/\.vcf$/i', $filename) || stripos($raw, 'BEGIN:VCARD') !== false ? vcard_rows($raw) : csv_rows($raw);
    $out = [];
    foreach (array_slice($rows, 0, 2000) as $r) {
        $r = array_map(fn($x) => trim((string)$x), $r + ['name' => '', 'company' => '', 'role' => '', 'email' => '', 'phone' => '', 'links' => '',
                                                          'nif' => '', 'address' => '', 'notes' => '', 'types' => '', 'birthday' => '']);
        if ($r['name'] === '' && $r['company'] === '') continue;
        $types = mb_strtolower($r['types']);
        $out[] = [
            'kind' => $r['name'] !== '' ? 'person' : 'company', 'name' => mb_substr($r['name'] !== '' ? $r['name'] : $r['company'], 0, 160),
            'company' => $r['name'] !== '' ? mb_substr($r['company'], 0, 160) : '', 'role' => mb_substr($r['role'], 0, 120),
            'email' => mb_substr(strtolower($r['email']), 0, 190), 'phone' => mb_substr($r['phone'], 0, 255), 'links' => $r['links'],
            'nif' => mb_substr($r['nif'], 0, 20), 'address' => mb_substr($r['address'], 0, 255), 'notes' => mb_substr($r['notes'], 0, 4000),
            'birthday' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['birthday']) ? $r['birthday'] : null,
            'is_client' => (int)str_contains($types, 'client'), 'is_supplier' => (int)(str_contains($types, 'fornec') || str_contains($types, 'supplier')),
            'is_partner' => (int)(str_contains($types, 'parc') || str_contains($types, 'partner')), 'is_freelancer' => (int)str_contains($types, 'freelanc'),
        ];
    }
    return $out;
}

function csv_rows(string $raw): array {
    $lines = preg_split('/\r\n|\n|\r/', trim($raw));
    if (count($lines) < 2) return [];
    $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
    $map = ['nome' => 'name', 'name' => 'name', 'full name' => 'name', 'nome completo' => 'name', 'empresa' => 'company', 'company' => 'company',
            'organização' => 'company', 'organizacao' => 'company', 'organization' => 'company', 'cargo' => 'role', 'role' => 'role', 'title' => 'role',
            'job title' => 'role', 'email' => 'email', 'e-mail' => 'email', 'telefone' => 'phone', 'phone' => 'phone', 'telemóvel' => 'phone',
            'telemovel' => 'phone', 'mobile' => 'phone', 'tel' => 'phone', 'nif' => 'nif', 'vat' => 'nif', 'site' => 'links', 'website' => 'links',
            'url' => 'links', 'redes' => 'links', 'redes sociais' => 'links', 'links' => 'links', 'morada' => 'address', 'address' => 'address',
            'notas' => 'notes', 'notes' => 'notes', 'tipo' => 'types', 'tipos' => 'types', 'type' => 'types', 'aniversário' => 'birthday',
            'aniversario' => 'birthday', 'birthday' => 'birthday'];
    $head = array_map(fn($h) => $map[mb_strtolower(trim($h))] ?? null, str_getcsv($lines[0], $sep));
    $out = [];
    foreach (array_slice($lines, 1) as $line) {
        if (trim($line) === '') continue;
        $r = [];
        foreach (str_getcsv($line, $sep) as $i => $val) {
            $k = $head[$i] ?? null;
            if ($k === null || trim($val) === '') continue;
            $r[$k] = isset($r[$k]) && in_array($k, ['phone', 'links', 'notes'], true) ? $r[$k] . ($k === 'phone' ? ' | ' : "\n") . trim($val) : trim($val);
        }
        $out[] = $r;
    }
    return $out;
}

function vcard_rows(string $raw): array {
    $raw = preg_replace("/\r\n[ \t]|\n[ \t]/", '', $raw);   // linhas dobradas
    $out = [];
    foreach (preg_split('/BEGIN:VCARD/i', $raw) as $card) {
        if (stripos($card, 'END:VCARD') === false) continue;
        $r = [];
        foreach (preg_split('/\r\n|\n|\r/', $card) as $line) {
            if (!preg_match('/^(?:item\d+\.)?([A-Z\-]+)((?:;[^:]*)?):(.*)$/i', $line, $m)) continue;
            [$prop, $params, $val] = [strtoupper($m[1]), strtoupper($m[2]), $m[3]];
            if (str_contains($params, 'QUOTED-PRINTABLE')) $val = quoted_printable_decode($val);
            $val = str_replace(['\\,', '\\;', '\\n', '\\N'], [',', ';', "\n", "\n"], $val);
            switch ($prop) {
                case 'FN': $r['name'] = $val; break;
                case 'N': if (empty($r['name'])) { $p = explode(';', $val); $r['name'] = trim(($p[1] ?? '') . ' ' . ($p[0] ?? '')); } break;
                case 'ORG': $r['company'] = trim(explode(';', $val)[0]); break;
                case 'TITLE': $r['role'] = $val; break;
                case 'TEL': $r['phone'] = isset($r['phone']) ? $r['phone'] . ' | ' . $val : $val; break;
                case 'EMAIL': $r['email'] ??= $val; break;
                case 'URL': $r['links'] = isset($r['links']) ? $r['links'] . "\n" . $val : $val; break;
                case 'NOTE': $r['notes'] = $val; break;
                case 'BDAY': $d = preg_replace('/[^\d]/', '', $val); if (strlen($d) >= 8) $r['birthday'] = substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2); break;
                case 'ADR': $r['address'] = trim(implode(', ', array_filter(array_map('trim', explode(';', $val)))), ', '); break;
            }
        }
        if (($r['name'] ?? '') === ($r['company'] ?? null)) unset($r['name']);   // cartão de empresa
        $out[] = $r;
    }
    return $out;
}
