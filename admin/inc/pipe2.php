<?php
/**
 * topper. admin — Pipeline v2: bandeira, serviços e filtros da v1
 *
 * A v2 liga-se com o «Aplicar» da conversão, que grava settings.pipeline_v2_at. Até lá a plataforma funciona
 * exatamente como na v1, e é este ficheiro que o garante nos leitores:
 *   · pipe2_on() lê a bandeira diretamente, sem cache (a conversão e o «Reverter» mudam-na a meio de um pedido);
 *   · pipe2_stage_cond() esconde as sub-etapas (level 'front') e as etapas retiradas;
 *   · pipe2_funnel_cond() e pipe2_funnel_hidden() escondem os funis só da v2 (v2_only = 1) enquanto a bandeira
 *     estiver vazia.
 * Os leitores publicam-se antes da migração (passo 43): numa base ainda na 42 os fragmentos dão '1=1' e os
 * predicados dão o resultado da v1, porque as colunas novas ainda não existem.
 *
 * Não inclui nenhum ficheiro: quem o inclui já tem a ligação.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

/** O passo da migração que cria as colunas level, retired_at e v2_only (informativo: a guarda é pipe2_seeded). */
const PIPE2_SCHEMA = 43;

/** Os 7 serviços, pela ordem da v2. v1 = xray_kind do funil base que mantém o id (null = funil novo). */
const PIPE2_SERVICES = [
    'vfx' => ['name' => 'VFX',           'token' => '--s-vfx', 'v1' => 'vfx'],
    '3d'  => ['name' => '3D',            'token' => '--s-3d',  'v1' => 'cgi'],
    'md'  => ['name' => 'Motion Design', 'token' => '--s-md',  'v1' => null],
    'cmp' => ['name' => 'Composit',      'token' => '--s-cmp', 'v1' => null],
    'ed'  => ['name' => 'Edição',        'token' => '--s-ed',  'v1' => null],
    'adv' => ['name' => 'Adverts',       'token' => '--s-adv', 'v1' => 'adverts'],
    'cam' => ['name' => 'Campanhas',     'token' => '--s-cam', 'v1' => 'campanhas'],
];

/** xray_kind de um funil v1 → código do serviço v2 (conversão). */
const PIPE2_V1_MAP = ['vfx' => 'vfx', 'cgi' => '3d', 'adverts' => 'adv', 'ads' => 'adv', 'campanhas' => 'cam', 'buddy' => 'cam'];

/** funnel_stages.level: etapa do negócio ou sub-etapa da frente. */
const PIPE2_LEVELS = ['deal' => 'Negócio', 'front' => 'Frente'];

/** funnel_stages.role novos (sem kind novo). */
const PIPE2_ROLES = [
    'exec'      => 'Em execução',
    'delivery'  => 'Entrega e pagamento final',
    'revisions' => 'Revisões',
    'ready'     => 'Pronto',
];

/** Estados da frente na v2; diferentes dos do crosselling v1 (planned, waiting, active, done). */
const PIPE2_LINE_STATUS = [
    'queued'  => 'Por começar',
    'running' => 'Em curso',
    'depwait' => 'À espera',
    'ready'   => 'Pronto',
    'ongoing' => 'Ativa',
    'merged'  => 'Juntada',
];

/** Gatilhos novos das automações de dois níveis. */
const PIPE2_TRIGGERS = [
    'f_enter'   => 'A frente entra na sub-etapa',
    'f_ready'   => 'A frente fica Pronta',
    'all_ready' => 'Todas as frentes Prontas',
    'dep_met'   => 'Dependência cumprida',
    'f_stale'   => 'Frente parada há N dias',
    'rounds'    => 'Rondas de revisão ultrapassadas',
    'fee_day'   => 'Avença mensal (dia N)',
];

/**
 * Coluna => códigos novos que lá se gravam. O teste de larguras percorre esta lista (a produção corta valores sem
 * avisar); as fases seguintes acrescentam aqui os códigos que criarem.
 */
const PIPE2_CODES = [
    'funnels.service_code'          => ['vfx', '3d', 'md', 'cmp', 'ed', 'adv', 'cam'],
    'funnel_stages.level'           => ['deal', 'front'],
    'funnel_stages.role'            => ['exec', 'delivery', 'revisions', 'ready'],
    'deal_lines.status'             => ['queued', 'running', 'depwait', 'ready', 'ongoing', 'merged'],
    'deal_lines.billing'            => ['monthly'],
    'deal_lines.exec_mode'          => ['internal', 'external'],
    'deal_lines.cycle_ym'           => ['2026-11'],
    'deals.billing'                 => ['monthly'],
    'deals.category'                => ['postprod', 'cgi'],
    'deals.service_codes'           => ['vfx,3d,md,cmp,ed,adv,cam'],
    'stage_automations.trig'        => ['f_enter', 'f_ready', 'all_ready', 'dep_met', 'f_stale', 'rounds', 'fee_day'],
    'stage_automations.action'      => ['task', 'notify'],
    'stage_automations.assign_to'   => ['svc_head'],
    'stage_events.step'             => ['p2_front', 'p2_wait'],
    'pipe2_notices.kind'            => ['replan', 'stale', 'dep_met', 'rule', 'cycle'],
    'tasks.cycle_ym'                => ['2026-11'],
    'deal_line_deps.source'         => ['model', 'manual', 'convert'],
    'deal_line_steps.auto_hold_why' => ['undo', 'convert'],
    'pipe2_dep_models.category'     => ['postprod', 'cgi'],
    'pipe2_categories.code'         => ['postprod', 'cgi'],
    'pipe2_convert_batches.status'  => ['review', 'applied', 'reverted', 'failed'],
    'pipe2_convert_log.op'          => ['update', 'insert'],
    'deal_history.event'            => ['v2_convert', 'p2_front', 'p2_wait', 'p2_undo', 'p2_auto', 'p2_units', 'p2_dep', 'p2_replan',
                                        'p2_billing', 'p2_services', 'p2_category'],
    'metric_goals.scope'            => ['service'],
    'metric_goals.metric'           => ['concluidos', 'valor_concluido'],
];

/** A v2 está ligada: settings.pipeline_v2_at preenchido. Lê sempre a base; um erro conta como desligada. */
function pipe2_on(PDO $pdo): bool {
    try {
        $v = $pdo->query("SELECT svalue FROM settings WHERE skey = 'pipeline_v2_at'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
    return is_string($v) && trim($v) !== '';
}

function pipe2_services(): array {
    return PIPE2_SERVICES;
}

/**
 * A base já tem as colunas da v2: a semente do passo da v2 correu (settings.pipe2_seeded, gravada na mesma transação
 * da semente, depois do DDL). Nunca pelo número da versão: o main pode ganhar outro passo com o mesmo número antes
 * desta publicação, e os leitores (que chegam ao servidor antes da migração) pediriam colunas que não existem.
 * Guarda-se só o verdadeiro, por ligação: um pedido que começou antes da semente vê o filtro assim que ela acaba. A
 * referência fraca impede que uma ligação nova, que herde o spl_object_id de uma que já morreu, herde o verdadeiro dela.
 */
function pipe2_schema_ready(PDO $pdo): bool {
    static $ready = [];
    $id = spl_object_id($pdo);
    if (isset($ready[$id]) && $ready[$id]->get() === $pdo) return true;
    try {
        $ok = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE skey = 'pipe2_seeded'")->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
    if ($ok) $ready[$id] = WeakReference::create($pdo);
    else unset($ready[$id]);
    return $ok;
}

/** A etapa existe na v1: é do negócio e não foi retirada. Lê a própria linha; sem as colunas novas (42), está viva. */
function pipe2_stage_live(array $stage): bool {
    $retired = $stage['retired_at'] ?? null;
    return ($stage['level'] ?? 'deal') === 'deal' && ($retired === null || $retired === '');
}

/** 's' ou 's.' → 's.'; '' → ''. */
function pipe2_alias(string $alias): string {
    return $alias === '' ? '' : rtrim($alias, '.') . '.';
}

/** Fragmento SQL que deixa só as etapas da v1. '1=1' enquanto a base não tiver as colunas. */
function pipe2_stage_cond(PDO $pdo, string $alias = ''): string {
    if (!pipe2_schema_ready($pdo)) return '1=1';
    $a = pipe2_alias($alias);
    return "{$a}level = 'deal' AND {$a}retired_at IS NULL";
}

/** Fragmento SQL que esconde os funis só da v2 enquanto a bandeira estiver vazia. */
function pipe2_funnel_cond(PDO $pdo, string $alias = ''): string {
    if (!pipe2_schema_ready($pdo) || pipe2_on($pdo)) return '1=1';
    return pipe2_alias($alias) . 'v2_only = 0';
}

/** O funil é só da v2 e a v2 ainda não está ligada: trata-se como inexistente. Sem v2_only (42), não está escondido. */
function pipe2_funnel_hidden(PDO $pdo, ?array $funnel): bool {
    return $funnel !== null && (int)($funnel['v2_only'] ?? 0) === 1 && !pipe2_on($pdo);
}
