<?php
/**
 * topper. admin — Pipeline v2: categorias e códigos dos serviços
 *
 * As categorias não são funis: calculam-se a partir dos serviços do negócio (as frentes vivas), pelas regras de
 * pipe2_categories, avaliadas por posição — 1) Pós-produção, 2) senão CGI, 3) senão sem categoria. Por isso VFX + 3D +
 * Motion Design é CGI (dois de VFX/3D/Composit, sem Edição) e Motion Design + 3D fica sem categoria. Um só serviço
 * nunca tem categoria.
 *
 * Os códigos dos serviços são os de PIPE2_SERVICES, sempre pela ordem da v2 e sem repetir. Um funil dá o seu
 * service_code; um funil da v1 sem código dá o do mapa da v1 (PIPE2_V1_MAP) de cada xray_kind. Blaine não é serviço:
 * nunca aparece.
 *
 * A categoria grava-se no negócio uma vez (no Kickoff e na conversão): pipe2_category_store só escreve se category_at
 * estiver vazio, por isso mudar as regras não reescreve o histórico; o «Recalcular» (pipe2_category_recalc) grava
 * sempre. Nenhuma função daqui verifica permissões: quem chama (o motor, como «Sistema», ou a ação do «Recalcular»,
 * que a verifica no servidor) decide. Sem o esquema da v2 (pipe2_schema_ready), as leituras dão vazio e as gravações
 * não fazem nada.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/deals.php';   // deal_log

/** Uma lista de códigos (CSV ou array) → códigos sem espaços, em minúsculas, sem vazios nem repetidos (pela ordem dada). */
function pipe2_category_list($codes): array {
    $parts = is_array($codes) ? $codes : explode(',', (string)$codes);
    $out = [];
    foreach ($parts as $c) {
        $c = strtolower(trim((string)$c));
        if ($c !== '' && !in_array($c, $out, true)) $out[] = $c;
    }
    return $out;
}

/** Só os códigos que são serviços, pela ordem da v2 e sem repetir. */
function pipe2_category_services(array $codes): array {
    $have = pipe2_category_list($codes);
    return array_values(array_filter(array_keys(PIPE2_SERVICES), fn($c) => in_array((string)$c, $have, true)));
}

/**
 * As regras ativas, por position e id: [['code', 'name', 'need_all' => [], 'need_any' => [], 'need_min' => int,
 * 'exclude_any' => []], …]. Sem o esquema da v2, [].
 */
function pipe2_category_rules(PDO $pdo): array {
    if (!pipe2_schema_ready($pdo)) return [];
    $out = [];
    $rows = $pdo->query('SELECT code, name, need_all, need_any, need_min, exclude_any FROM pipe2_categories
                         WHERE is_active = 1 ORDER BY position, id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $out[] = [
            'code'        => (string)$r['code'],
            'name'        => (string)$r['name'],
            'need_all'    => pipe2_category_list($r['need_all']),
            'need_any'    => pipe2_category_list($r['need_any']),
            'need_min'    => (int)$r['need_min'],
            'exclude_any' => pipe2_category_list($r['exclude_any']),
        ];
    }
    return $out;
}

/**
 * A categoria de uma combinação de serviços (puro). Menos de 2 serviços distintos → ''. Senão, a 1.ª regra da lista
 * que bate: todos os need_all, pelo menos need_min dos need_any e nenhum dos exclude_any. Nenhuma → ''.
 */
function pipe2_category_of(array $codes, array $rules): string {
    $codes = pipe2_category_services($codes);
    if (count($codes) < 2) return '';
    foreach ($rules as $r) {
        if (array_diff((array)($r['need_all'] ?? []), $codes)) continue;
        if (count(array_intersect((array)($r['need_any'] ?? []), $codes)) < (int)($r['need_min'] ?? 0)) continue;
        if (array_intersect((array)($r['exclude_any'] ?? []), $codes)) continue;
        return (string)$r['code'];
    }
    return '';
}

/**
 * O nome de uma categoria ('' → ''). Também o de uma regra desligada (a categoria gravada continua a mostrar-se); um
 * código sem regra (ou sem o esquema da v2) mostra-se como está.
 */
function pipe2_category_name(PDO $pdo, string $code): string {
    if ($code === '') return '';
    if (!pipe2_schema_ready($pdo)) return $code;
    $st = $pdo->prepare('SELECT name FROM pipe2_categories WHERE code = ? ORDER BY is_active DESC, position, id LIMIT 1');
    $st->execute([$code]);
    $name = $st->fetchColumn();
    $st->closeCursor();
    return is_string($name) && $name !== '' ? $name : $code;
}

/** Os nomes dos serviços, pela ordem da v2, juntos com « + » (ex.: «3D + Motion Design»). */
function pipe2_services_label(array $codes): string {
    return implode(' + ', array_map(fn($c) => PIPE2_SERVICES[$c]['name'], pipe2_category_services($codes)));
}

/**
 * Os códigos dos serviços de um funil: [service_code] se for um serviço; senão os do mapa da v1 de cada xray_kind da
 * lista (vírgulas), sem repetir, pela ordem da v2.
 */
function pipe2_funnel_codes(array $funnel): array {
    $own = strtolower(trim((string)($funnel['service_code'] ?? '')));
    if (isset(PIPE2_SERVICES[$own])) return [$own];
    $mapped = [];
    foreach (pipe2_category_list($funnel['xray_kind'] ?? '') as $kind) {
        if (isset(PIPE2_V1_MAP[$kind])) $mapped[] = PIPE2_V1_MAP[$kind];
    }
    return pipe2_category_services($mapped);
}

/**
 * Os códigos dos serviços do negócio: os dos funis das deal_lines vivas (status ≠ merged), também as planned antes do
 * Kickoff; sem linhas vivas, os do funil do negócio. Pela ordem da v2. Só lê.
 */
function pipe2_deal_codes(PDO $pdo, array $deal): array {
    $cols = pipe2_schema_ready($pdo) ? 'f.service_code, f.xray_kind' : "'' AS service_code, f.xray_kind";
    $st = $pdo->prepare("SELECT $cols FROM deal_lines l JOIN funnels f ON f.id = l.funnel_id
                          WHERE l.deal_id = ? AND l.status <> 'merged' ORDER BY l.position, l.id");
    $st->execute([(int)($deal['id'] ?? 0)]);
    $funnels = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$funnels && !empty($deal['funnel_id'])) {
        $st = $pdo->prepare("SELECT $cols FROM funnels f WHERE f.id = ?");
        $st->execute([(int)$deal['funnel_id']]);
        $funnels = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $codes = [];
    foreach ($funnels as $f) $codes = array_merge($codes, pipe2_funnel_codes($f));
    return pipe2_category_services($codes);
}

/** A categoria do negócio calculada agora (não grava). */
function pipe2_deal_category(PDO $pdo, array $deal): string {
    return pipe2_category_of(pipe2_deal_codes($pdo, $deal), pipe2_category_rules($pdo));
}

/** «CGI», ou «sem categoria». */
function pipe2_category_label(PDO $pdo, string $code): string {
    return $code === '' ? 'sem categoria' : pipe2_category_name($pdo, $code);
}

/** Uma linha p2_category no histórico; sem $by, de «Sistema» (user_id NULL). */
function pipe2_category_log(PDO $pdo, int $dealId, string $note, ?int $by): void {
    deal_log($pdo, $dealId, 'p2_category', ['note' => $note, 'user_id' => $by, 'system' => $by === null]);
}

/**
 * Grava a categoria no negócio uma só vez: só se category_at estiver vazio (UPDATE condicional, por isso dois pedidos
 * ao mesmo tempo gravam uma vez). Devolve a categoria gravada — a de agora ou a que já lá estava. Regista
 * p2_category quando há o que dizer (uma categoria, ou deixar de ter a que havia); a ausência de categoria também fica
 * gravada (congelada), sem linha no histórico.
 */
function pipe2_category_store(PDO $pdo, array $deal, ?int $by = null): string {
    if (!pipe2_schema_ready($pdo)) return '';
    $id = (int)($deal['id'] ?? 0);
    $st = $pdo->prepare('SELECT id, funnel_id, category, category_at FROM deals WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return '';
    if ($row['category_at'] !== null) return (string)$row['category'];
    $cat = pipe2_deal_category($pdo, $row);
    $up = $pdo->prepare('UPDATE deals SET category = ?, category_at = ? WHERE id = ? AND category_at IS NULL');
    $up->execute([$cat, date('Y-m-d H:i:s'), $id]);
    if ($up->rowCount() !== 1) {
        // outro pedido gravou entretanto: vale a dele
        $st->execute([$id]);
        return (string)($st->fetch(PDO::FETCH_ASSOC)['category'] ?? '');
    }
    $prev = (string)$row['category'];
    if ($cat !== '' || $prev !== '') pipe2_category_log($pdo, $id, 'Categoria: ' . pipe2_category_label($pdo, $cat), $by);
    return $cat;
}

/** O «Recalcular»: calcula agora, grava sempre (com category_at) e regista a mudança, ou que não mudou. */
function pipe2_category_recalc(PDO $pdo, array $deal, ?int $by = null): string {
    if (!pipe2_schema_ready($pdo)) return '';
    $id = (int)($deal['id'] ?? 0);
    $st = $pdo->prepare('SELECT id, funnel_id, category FROM deals WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return '';
    $cat = pipe2_deal_category($pdo, $row);
    $pdo->prepare('UPDATE deals SET category = ?, category_at = ? WHERE id = ?')->execute([$cat, date('Y-m-d H:i:s'), $id]);
    $prev = (string)$row['category'];
    $note = $prev === $cat
        ? 'Categoria recalculada: ' . pipe2_category_label($pdo, $cat) . ' (sem alteração)'
        : 'Categoria recalculada: ' . pipe2_category_label($pdo, $prev) . ' → ' . pipe2_category_label($pdo, $cat);
    pipe2_category_log($pdo, $id, $note, $by);
    return $cat;
}
