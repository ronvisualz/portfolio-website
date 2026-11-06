<?php
/**
 * topper. admin — Pipeline v2: serviços da lead
 *
 * Uma lead pode ter vários serviços: deals.service_codes guarda-os como lista de códigos de PIPE2_SERVICES (CSV pela
 * ordem da v2, sem espaços nem repetidos; o máximo, os 7, tem 24 caracteres). Só este ficheiro escreve a coluna;
 * deals.service (o rótulo da v1: «VFX / 3D», «Marketing Buddy», «Ambos») e os formulários não mudam nesta fase.
 *
 *   · Os rótulos da v1 e os valores do site traduzem-se em códigos (pipe2_v1_service_codes, pipe2_site_service_codes).
 *     «Marketing Buddy» é Campanhas; «VFX / 3D» e «Ambos» são ambíguos (o admin escolhe na conversão). No site, o
 *     valor sozinho 'vfx' é o antigo «VFX / 3D»; num array (a escolha múltipla do formulário novo) 'vfx' é o código.
 *   · A categoria da lead calcula-se na hora a partir dos códigos (pipe2_lead_category) e nunca se grava antes do
 *     Kickoff.
 *   · lead_convert para um funil v2 cria, na mesma transação, as frentes 'planned': a principal (funil escolhido) e
 *     uma por cada outro código com funil v2 (pipe2_lead_convert_lines). Depois disso a fonte dos serviços são as
 *     deal_lines. Num funil v1 (ou numa base sem o esquema da v2) não faz nada.
 *
 * Nenhuma função daqui verifica permissões: quem chama (a ação da lead, o formulário do site, a conversão) verifica-as
 * no servidor. Sem o esquema da v2 (pipe2_schema_ready), as leituras dão vazio.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/pipe2_category.php';   // pipe2_category_services, pipe2_category_of, pipe2_funnel_codes, deal_log
require_once __DIR__ . '/pipe2_fronts.php';     // pipe2_funnel_is_v2, pipe2_v2_funnels_sql, pipe2_services_ensure, pipe2_service_add

/** Rótulo da v1 (deals.service) → códigos e se é ambíguo (o admin escolhe na conversão). */
const PIPE2_V1_SERVICE_LABELS = [
    'VFX / 3D'        => [['vfx', '3d'], true],
    'Marketing Buddy' => [['cam'], false],
    'Ambos'           => [['vfx', '3d', 'cam'], true],
];

/** Valores antigos do formulário do site (contacto.php) → rótulo da v1. */
const PIPE2_SITE_LEGACY = ['vfx' => 'VFX / 3D', 'marketing' => 'Marketing Buddy', 'both' => 'Ambos'];

// ── códigos ─────────────────────────────────────────────────

/** CSV ou array → só os códigos de PIPE2_SERVICES, sem repetidos, pela ordem da v2, sem espaços ('' se nenhum). */
function pipe2_codes_clean($codes): string {
    if ($codes === null) return '';
    $parts = is_array($codes) ? array_filter($codes, 'is_scalar') : explode(',', (string)$codes);
    return implode(',', pipe2_category_services($parts));
}

/** A lista de códigos de um CSV (limpa, pela ordem da v2). */
function pipe2_codes_list(string $csv): array {
    return pipe2_category_services(explode(',', $csv));
}

/** Os códigos de um rótulo da v1: ['codes' => [...], 'ambiguous' => bool]. Um rótulo desconhecido fica para o admin. */
function pipe2_v1_service_codes(string $label): array {
    $label = trim($label);
    if ($label === '') return ['codes' => [], 'ambiguous' => false];
    if (!isset(PIPE2_V1_SERVICE_LABELS[$label])) return ['codes' => [], 'ambiguous' => true];
    [$codes, $ambiguous] = PIPE2_V1_SERVICE_LABELS[$label];
    return ['codes' => $codes, 'ambiguous' => $ambiguous];
}

/**
 * Os códigos de um valor do site. Uma string sozinha 'vfx', 'marketing' ou 'both' é o valor antigo («VFX / 3D» →
 * vfx,3d; Campanhas; os três); outra string lê-se como lista de códigos. Num array (escolha múltipla) cada valor é um
 * dos 7 códigos tal e qual, ou 'marketing'/'both' (o 'vfx' é aqui o código VFX); juntam-se, sem repetir, pela ordem da
 * v2. Valores que não são texto ignoram-se.
 */
function pipe2_site_service_codes($value): array {
    if (!is_array($value)) {
        $v = strtolower(trim(is_scalar($value) ? (string)$value : ''));
        if (isset(PIPE2_SITE_LEGACY[$v])) return pipe2_v1_service_codes(PIPE2_SITE_LEGACY[$v])['codes'];
        $value = explode(',', $v);
    }
    $codes = [];
    foreach ($value as $part) {
        if (!is_scalar($part)) continue;
        $p = strtolower(trim((string)$part));
        if (isset(PIPE2_SERVICES[$p])) $codes[] = $p;
        elseif (isset(PIPE2_SITE_LEGACY[$p])) $codes = array_merge($codes, pipe2_v1_service_codes(PIPE2_SITE_LEGACY[$p])['codes']);
    }
    return pipe2_category_services($codes);
}

// ── a lead ──────────────────────────────────────────────────

/**
 * Grava os serviços da lead (deals.service_codes) e devolve-os limpos. Só na lead e no Pipeline: em produção e
 * concluído os serviços são as frentes; arquivado e perdido não mudam. deals.service não muda. Regista p2_services
 * quando mudam (sem $by, do «Sistema»: o formulário do site, sem sessão).
 */
function pipe2_lead_services_set(PDO $pdo, int $leadId, $codes, ?int $by = null): string {
    $st = $pdo->prepare('SELECT id, phase, service_codes FROM deals WHERE id = ?');
    $st->execute([$leadId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    if (!$row) throw new RuntimeException('Lead não encontrada.');
    if (in_array($row['phase'], ['production', 'done'], true)) throw new RuntimeException('Os serviços mudam-se nas frentes.');
    if (!in_array($row['phase'], ['lead', 'pipeline'], true)) throw new RuntimeException('Este registo já não está ativo: os serviços não mudam.');
    $csv = pipe2_codes_clean($codes);
    if ($csv === (string)$row['service_codes']) return $csv;
    $pdo->prepare('UPDATE deals SET service_codes = ? WHERE id = ?')->execute([$csv, $leadId]);
    $label = pipe2_services_label(pipe2_codes_list($csv));
    deal_log($pdo, $leadId, 'p2_services', ['note' => 'Serviços: ' . ($label !== '' ? $label : 'nenhum'), 'user_id' => $by, 'system' => $by === null]);
    return $csv;
}

/** A categoria da lead pelos códigos dos serviços, calculada agora; nunca grava. */
function pipe2_lead_category(PDO $pdo, array $lead): string {
    return pipe2_category_of(pipe2_codes_list((string)($lead['service_codes'] ?? '')), pipe2_category_rules($pdo));
}

/** O funil v2 de um código: o ativo primeiro, depois o que não é só da v2, depois a posição e o id. Nenhum → null. */
function pipe2_funnel_for_code(PDO $pdo, string $code): ?int {
    if (!isset(PIPE2_SERVICES[$code]) || !pipe2_schema_ready($pdo)) return null;
    $st = $pdo->prepare('SELECT f.id FROM funnels f WHERE f.service_code = ? AND f.id IN (' . pipe2_v2_funnels_sql() . ')
                          ORDER BY f.is_active DESC, f.v2_only ASC, f.position, f.id LIMIT 1');
    $st->execute([$code]);
    $id = $st->fetchColumn();
    $st->closeCursor();
    return $id === false ? null : (int)$id;
}

// ── a conversão ─────────────────────────────────────────────

/**
 * A conversão da lead para um funil v2 (dentro da transação de lead_convert, depois do UPDATE para o Pipeline): a
 * frente principal 'planned' (o funil escolhido) e uma 'planned' por cada outro código da lead com funil v2 (os
 * códigos que o funil escolhido já cobre e os que não têm funil v2 ficam de fora). Relê o negócio (o $lead de quem
 * chama ainda é o da lead). Num funil v1 não faz nada. Devolve quantas frentes criou.
 */
function pipe2_lead_convert_lines(PDO $pdo, array $lead, int $funnelId): int {
    if (empty($lead['id']) || !pipe2_funnel_is_v2($pdo, $funnelId)) return 0;
    $st = $pdo->prepare('SELECT * FROM deals WHERE id = ?');
    $st->execute([(int)$lead['id']]);
    $deal = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    if (!$deal || (int)$deal['funnel_id'] !== $funnelId) return 0;

    $count = $pdo->prepare('SELECT COUNT(*) FROM deal_lines WHERE deal_id = ?');
    $count->execute([(int)$deal['id']]);
    $before = (int)$count->fetchColumn();

    pipe2_services_ensure($pdo, $deal);
    $f = $pdo->prepare('SELECT service_code, xray_kind FROM funnels WHERE id = ?');
    $f->execute([$funnelId]);
    $covered = pipe2_funnel_codes($f->fetch(PDO::FETCH_ASSOC) ?: []);
    foreach (pipe2_codes_list((string)$deal['service_codes']) as $code) {
        if (in_array($code, $covered, true)) continue;
        $fid = pipe2_funnel_for_code($pdo, $code);
        if ($fid === null || $fid === $funnelId) continue;
        pipe2_service_add($pdo, $deal, $fid);
    }
    $GLOBALS['XSELL_FRESH'] = true;

    $count->execute([(int)$deal['id']]);
    return (int)$count->fetchColumn() - $before;
}
