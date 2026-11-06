<?php
/**
 * topper. admin — passo 43 · Pipeline v2, fase 0
 *
 * Só acrescenta: colunas e tabelas novas e uma semente que corre uma vez. Até ao «Aplicar» da conversão a plataforma
 * funciona em modo v1, por isso este passo:
 *   · não mexe em negócios, frentes, tarefas, histórico nem regras de automação (nenhum UPDATE a deals, deal_lines,
 *     tasks; nenhuma regra nova em stage_automations; settings.pipeline_v2_at fica por gravar);
 *   · cria os funis Motion Design, Composit e Edição inativos e só da v2 (is_active = 0, v2_only = 1), com as 7
 *     etapas de negócio e as 6 sub-etapas;
 *   · dá o código do serviço (service_code) aos funis base VFX, CGI, Adverts e Campanhas, que mantêm o id, o nome,
 *     a posição e o xray_kind, e semeia-lhes só as 6 sub-etapas (level 'front', escondidas na v1). «Em execução» e
 *     «Entrega e pagamento final» só se criam neles na conversão (mudavam o flow_order da v1);
 *   · liga cada serviço ao departamento do chefe (sem criar departamentos), as categorias e os modelos de
 *     dependências.
 *
 * Ordem (D6): GET_LOCK('topper_pipe2_' . DATABASE(), 30) → DDL (só db_add_column e db_create, fora da transação, porque
 * o DDL faz commit implícito no MySQL) → semente numa transação, reclamada no fim com INSERT IGNORE de pipe2_seeded
 * → RELEASE_LOCK. Se o lock falhar, lança: um return deixava o run_migrations marcar a 43 sem esquema.
 *
 * As procuras são pelo service_code e pelo xray_kind exato dos funis base (comparação em PHP, sem a colação da base),
 * nunca pelo id nem pelo nome: a produção pode ter outros ids, um CGI combinado sem funil base, um funil da equipa
 * chamado «Edição» ou não ter o departamento «Adverts». O que falta salta-se sem falhar.
 *
 * Reversão (10.33): este passo não tem desfazer em produção (nada se apaga). Depois de correr, se for preciso repor o
 * código anterior, nunca se repõem as versões antigas de inc/pipe2.php nem dos 7 leitores e ecrãs (inc/deals.php,
 * inc/pipeline.php, inc/pipeline_flow.php, inc/xsell.php, funis.php, negocios.php, relatorios.php): não filtram e
 * mostravam as sub-etapas e os funis só da v2. O migrate.php antigo (42) não volta a migrar e a base fica na 43; ao
 * regressar a 43, a semente não volta a correr. Prova: tests/pipe2_rollback_test.php.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_create, db_add_column
require_once __DIR__ . '/pipe2.php';              // PIPE2_SERVICES

/** As 7 etapas de negócio dos funis novos: [nome, kind, posição, role]. */
const PIPE2_SEED_DEAL_STAGES = [
    ['X-Ray', 'xray', 10, ''],
    ['Proposta', 'commercial', 20, 'proposal'],
    ['Aguardar feedback', 'commercial', 21, 'feedback'],
    ['Kickoff', 'kickoff', 50, ''],
    ['Em execução', 'production', 70, 'exec'],
    ['Entrega e pagamento final', 'production', 80, 'delivery'],
    ['Concluído', 'done', 90, ''],
];

/**
 * As sub-etapas de cada serviço: [as 6 sub-etapas, rondas incluídas em Revisões]. Ficam com kind 'production',
 * level 'front' e posições 110, 120, …, 160; as duas últimas levam role 'revisions' e 'ready'.
 */
const PIPE2_SEED_FRONTS = [
    'vfx' => [['Turnover e breakdown', 'Look dev / shot de referência', 'Produção de shots', 'Finais e QC', 'Revisões', 'Pronto'], 2],
    '3d'  => [['Styleframes', 'Modelação e lookdev', 'Animação', 'Lighting e render', 'Revisões', 'Pronto'], 1],
    'md'  => [['Guião visual e styleframes', 'Animatic', 'Animação', 'Som e ritmo', 'Revisões', 'Pronto'], 1],
    'cmp' => [['Preparação de plates', 'Composição', 'Integração e look', 'Finais e QC', 'Revisões', 'Pronto'], 1],
    'ed'  => [['Seleção e assembly', 'Montagem offline', 'Picture lock', 'Finalização (cor, som, legendas)', 'Revisões', 'Pronto'], 2],
    'adv' => [['Conceito e tratamento', 'Pré-produção (PPM)', 'Rodagem', 'Montagem e pós', 'Revisões', 'Pronto'], 2],
    'cam' => [['Estratégia e calendário', 'Guiões do lote', 'Gravação do lote', 'Edição do lote', 'Revisões', 'Pronto'], 1],
];

/** O departamento do chefe de cada serviço (nome exato em departments; se não existir, fica NULL). */
const PIPE2_SEED_DEPTS = [
    'vfx' => 'Pós-Produção e VFX',
    '3d'  => 'Pós-Produção e VFX',
    'md'  => 'Pós-Produção e VFX',
    'cmp' => 'Pós-Produção e VFX',
    'ed'  => 'Pós-Produção e VFX',
    'adv' => 'Adverts',
    'cam' => 'Campanhas',
];

/**
 * As categorias: [code, name, position, need_all, need_any, need_min, exclude_any]. Aplica-se quando estão todos os
 * need_all, pelo menos need_min dos need_any e nenhum dos exclude_any; ganha a primeira por position (fase 1).
 */
const PIPE2_SEED_CATEGORIES = [
    ['postprod', 'Pós-produção', 1, 'ed', 'vfx,3d,md,cmp', 1, ''],
    ['cgi', 'CGI', 2, '', 'vfx,3d,cmp', 2, 'ed'],
];

/**
 * Os modelos de dependências: [categoria, serviço de, sub-etapa de, serviço para, sub-etapa para]. A posição é a
 * ordem dentro da categoria (1, 2, …). Uma ligação a um serviço sem funil salta-se.
 */
const PIPE2_SEED_DEP_MODELS = [
    ['postprod', '3d', 'Lighting e render', 'cmp', 'Preparação de plates'],
    ['postprod', 'vfx', 'Finais e QC', 'cmp', 'Integração e look'],
    ['postprod', 'cmp', 'Composição', 'ed', 'Picture lock'],
    ['postprod', 'md', 'Revisões', 'ed', 'Picture lock'],
    ['cgi', '3d', 'Modelação e lookdev', 'vfx', 'Look dev / shot de referência'],
    ['cgi', '3d', 'Lighting e render', 'cmp', 'Preparação de plates'],
    ['cgi', 'vfx', 'Finais e QC', 'cmp', 'Integração e look'],
];

/** Lock → esquema → semente; o lock solta-se sempre. */
function migrate_pipeline2_43(PDO $pdo): void {
    $lock = migrate_pipeline2_lock($pdo);
    try {
        migrate_pipeline2_schema($pdo);
        migrate_pipeline2_seed($pdo);
    } finally {
        if ($lock !== '') $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}

/**
 * Um passo 43 de cada vez por base (os pedidos que chegam durante a migração esperam aqui). Devolve o nome do lock,
 * ou '' em SQLite (uma ligação só). Lança se não o obtiver em 30 s.
 */
function migrate_pipeline2_lock(PDO $pdo): string {
    if (db_is_sqlite($pdo)) return '';
    $name = 'topper_pipe2_' . (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if (strlen($name) > 64) $name = 'topper_pipe2_' . md5($name);   // o MySQL não aceita nomes de lock maiores
    $st = $pdo->prepare('SELECT GET_LOCK(?, 30)');
    $st->execute([$name]);
    $got = $st->fetchColumn();
    $st->closeCursor();
    if ((string)$got !== '1') throw new RuntimeException('Passo 43: não foi possível obter o lock ' . $name . ' (outra migração a correr?).');
    return $name;
}

/** Só DDL (sem dados): colunas com db_add_column, tabelas com db_create e o índice da deduplicação das tarefas. */
function migrate_pipeline2_schema(PDO $pdo): void {
    $lite = db_is_sqlite($pdo);

    foreach ([
        // funis: código do serviço, só da v2, chefe do serviço, interruptor do serviço
        ['funnels', 'service_code', "VARCHAR(8) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        ['funnels', 'v2_only', 'TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
        ['funnels', 'department_id', 'INT UNSIGNED NULL', 'INTEGER NULL'],
        ['funnels', 'auto_advance', 'TINYINT(1) NOT NULL DEFAULT 1', 'INTEGER NOT NULL DEFAULT 1'],
        // etapas: nível (negócio ou frente), retirada, avanço automático, dias previstos, unidades
        ['funnel_stages', 'level', "VARCHAR(8) NOT NULL DEFAULT 'deal'", "TEXT NOT NULL DEFAULT 'deal'"],
        ['funnel_stages', 'retired_at', 'DATETIME NULL', 'TEXT NULL'],
        ['funnel_stages', 'auto_advance', 'TINYINT(1) NOT NULL DEFAULT 1', 'INTEGER NOT NULL DEFAULT 1'],
        ['funnel_stages', 'plan_days', 'SMALLINT NULL', 'INTEGER NULL'],
        ['funnel_stages', 'unit_label', "VARCHAR(20) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        // frentes: avença, ciclo, junção, execução e interruptor (NULL herda o do serviço)
        ['deal_lines', 'billing', "VARCHAR(8) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        ['deal_lines', 'monthly_fee', 'DECIMAL(12,2) NULL', 'REAL NULL'],
        ['deal_lines', 'fee_months', 'SMALLINT NULL', 'INTEGER NULL'],
        ['deal_lines', 'billing_end', 'DATE NULL', 'TEXT NULL'],
        ['deal_lines', 'cancelled_at', 'DATETIME NULL', 'TEXT NULL'],
        ['deal_lines', 'cycle_ym', "VARCHAR(7) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        ['deal_lines', 'merged_into_id', 'INT UNSIGNED NULL', 'INTEGER NULL'],
        ['deal_lines', 'exec_mode', "VARCHAR(8) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        ['deal_lines', 'auto_advance', 'TINYINT(1) NULL', 'INTEGER NULL'],
        // negócios: serviços da lead ou do negócio, categoria e avença antes do Kickoff
        ['deals', 'service_codes', "VARCHAR(40) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        ['deals', 'category', "VARCHAR(8) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        ['deals', 'category_at', 'DATETIME NULL', 'TEXT NULL'],
        ['deals', 'billing', "VARCHAR(8) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        // tarefas: deduplicação e ciclo
        ['tasks', 'dedup_key', "VARCHAR(64) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        ['tasks', 'cycle_ym', "VARCHAR(7) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        // rondas por frente e ciclo, pedido de fatura ↔ tarefa, margem por frente
        ['deal_rounds', 'line_id', 'INT UNSIGNED NULL', 'INTEGER NULL'],
        ['deal_rounds', 'cycle_ym', "VARCHAR(7) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        ['cc_rv_rounds', 'line_id', 'INT UNSIGNED NULL', 'INTEGER NULL'],
        ['fin_docs', 'task_id', 'INT UNSIGNED NULL', 'INTEGER NULL'],
        ['costs', 'line_id', 'INT UNSIGNED NULL', 'INTEGER NULL'],
    ] as [$table, $col, $my, $sq]) {
        if (db_has_table($pdo, $table)) db_add_column($pdo, $table, $col, $my, $sq);
    }

    if (!$lite && db_has_table($pdo, 'tasks')) {
        $idx = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'tasks' AND index_name = 'idx_task_dedup'")->fetchColumn();
        if (!(int)$idx) $pdo->exec('ALTER TABLE tasks ADD INDEX idx_task_dedup (deal_id, dedup_key)');
    }

    // frente × sub-etapa: prazo, unidades e suspensão do avanço automático
    db_create($pdo, 'deal_line_steps', "
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id        INT UNSIGNED NOT NULL,
        line_id        INT UNSIGNED NOT NULL,
        stage_id       INT UNSIGNED NOT NULL,
        cycle_ym       VARCHAR(7)   NOT NULL DEFAULT '',
        plan_days      SMALLINT     NULL,
        units_plan     SMALLINT     NULL,
        units_done     SMALLINT     NOT NULL DEFAULT 0,
        plan_start     DATE         NULL,
        plan_end       DATE         NULL,
        replanned_days SMALLINT     NOT NULL DEFAULT 0,
        started_at     DATETIME     NULL,
        done_at        DATETIME     NULL,
        auto_hold_at   DATETIME     NULL,
        auto_hold_why  VARCHAR(8)   NOT NULL DEFAULT '',
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dls_line (line_id, stage_id),
        INDEX idx_dls_deal (deal_id)");

    // vários executores (internos ou externos) por frente
    db_create($pdo, 'deal_line_execs', "
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id        INT UNSIGNED NOT NULL,
        line_id        INT UNSIGNED NOT NULL,
        user_id        INT UNSIGNED NULL,
        contact_id     INT UNSIGNED NULL,
        budget_line_id INT UNSIGNED NULL,
        created_by     INT UNSIGNED NULL,
        position       INT          NOT NULL DEFAULT 0,
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dle_line (line_id),
        INDEX idx_dle_contact (contact_id)");

    // as ligações (dependências) de um negócio: a frente B espera pela sub-etapa X da frente A
    db_create($pdo, 'deal_line_deps', "
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id       INT UNSIGNED NOT NULL,
        from_line_id  INT UNSIGNED NOT NULL,
        from_stage_id INT UNSIGNED NOT NULL,
        to_line_id    INT UNSIGNED NOT NULL,
        to_stage_id   INT UNSIGNED NOT NULL,
        source        VARCHAR(8)   NOT NULL DEFAULT 'model',
        met_at        DATETIME     NULL,
        removed_at    DATETIME     NULL,
        removed_by    INT UNSIGNED NULL,
        created_by    INT UNSIGNED NULL,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dld_deal (deal_id),
        INDEX idx_dld_to (to_line_id)");

    // os modelos de dependências por categoria
    db_create($pdo, 'pipe2_dep_models', "
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category      VARCHAR(8)   NOT NULL,
        from_stage_id INT UNSIGNED NOT NULL,
        to_stage_id   INT UNSIGNED NOT NULL,
        position      INT          NOT NULL DEFAULT 0,
        is_active     TINYINT(1)   NOT NULL DEFAULT 1,
        updated_at    DATETIME     NULL,
        updated_by    INT UNSIGNED NULL,
        INDEX idx_p2dm_cat (category)");

    // as regras das categorias (CSV de códigos de serviço)
    db_create($pdo, 'pipe2_categories', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code        VARCHAR(8)   NOT NULL,
        name        VARCHAR(60)  NOT NULL,
        position    INT          NOT NULL DEFAULT 0,
        need_all    VARCHAR(40)  NOT NULL DEFAULT '',
        need_any    VARCHAR(40)  NOT NULL DEFAULT '',
        exclude_any VARCHAR(40)  NOT NULL DEFAULT '',
        need_min    TINYINT      NOT NULL DEFAULT 0,
        is_active   TINYINT(1)   NOT NULL DEFAULT 1,
        updated_at  DATETIME     NULL,
        updated_by  INT UNSIGNED NULL");

    // os lotes da conversão (fase 3) e o registo linha a linha que o «Reverter» repõe
    db_create($pdo, 'pipe2_convert_batches', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        status      VARCHAR(10)  NOT NULL DEFAULT 'review',
        backup_key  VARCHAR(255) NOT NULL DEFAULT '',
        note        TEXT         NULL,
        created_by  INT UNSIGNED NULL,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        applied_at  DATETIME     NULL,
        reverted_at DATETIME     NULL");
    db_create($pdo, 'pipe2_convert_log', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        batch_id   INT UNSIGNED NOT NULL,
        op         VARCHAR(8)   NOT NULL DEFAULT 'update',
        tbl        VARCHAR(40)  NOT NULL,
        row_id     INT UNSIGNED NOT NULL,
        col        VARCHAR(40)  NOT NULL DEFAULT '',
        old_val    TEXT         NULL,
        new_val    TEXT         NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_p2cl_batch (batch_id),
        INDEX idx_p2cl_row (tbl, row_id)");

    // os apagamentos humanos de tarefas, registados antes do DELETE (medir o ruído das automações)
    db_create($pdo, 'task_deletions', "
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id         INT UNSIGNED NOT NULL,
        deal_id         INT UNSIGNED NULL,
        line_id         INT UNSIGNED NULL,
        stage_id        INT UNSIGNED NULL,
        auto_rule_id    INT UNSIGNED NULL,
        deleted_by      INT UNSIGNED NULL,
        title           VARCHAR(200) NOT NULL DEFAULT '',
        task_created_at DATETIME     NULL,
        deleted_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_td_rule (auto_rule_id)");
}

/** A semente já correu nesta base. */
function migrate_pipeline2_seeded(PDO $pdo): bool {
    return (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE skey = 'pipe2_seeded'")->fetchColumn() > 0;
}

/** Os funis com código: service_code => id (o primeiro por posição; comparação exata, em PHP). */
function migrate_pipeline2_coded(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT id, service_code FROM funnels WHERE service_code <> '' ORDER BY position, id")->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $code = (string)$f['service_code'];
        if (isset(PIPE2_SERVICES[$code]) && !isset($out[$code])) $out[$code] = (int)$f['id'];
    }
    return $out;
}

/**
 * A semente, uma vez por base (D6): true = semeou agora; false = já estava. Cada operação é idempotente por código,
 * e a bandeira pipe2_seeded é reclamada no fim, na mesma transação: uma falha a meio desfaz tudo e não deixa a
 * bandeira gravada, e a corrida seguinte semeia.
 */
function migrate_pipeline2_seed(PDO $pdo): bool {
    if (migrate_pipeline2_seeded($pdo)) return false;
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $coded = migrate_pipeline2_coded($pdo);

        // 1. Funis novos (Motion Design, Composit, Edição): inativos, só da v2, com as 7 etapas de negócio.
        $insFunnel = $pdo->prepare("INSERT INTO funnels (name, is_base, made_from, position, is_active, xray_kind, service_code, v2_only, created_at)
                                    VALUES (?, 1, '', ?, 0, '', ?, 1, ?)");
        $insDeal = $pdo->prepare("INSERT INTO funnel_stages (funnel_id, name, kind, position, role, level, created_at) VALUES (?, ?, ?, ?, ?, 'deal', ?)");
        foreach (PIPE2_SERVICES as $code => $svc) {
            if ($svc['v1'] !== null || isset($coded[$code])) continue;
            $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) FROM funnels')->fetchColumn() + 1;
            $insFunnel->execute([$svc['name'], $pos, $code, $now]);
            $fid = (int)$pdo->lastInsertId();
            foreach (PIPE2_SEED_DEAL_STAGES as [$name, $kind, $spos, $role]) $insDeal->execute([$fid, $name, $kind, $spos, $role, $now]);
            $coded[$code] = $fid;
        }

        // 2. Os funis base que mantêm o id: o primeiro com is_base = 1 e o xray_kind exato, se ainda ninguém tiver o código.
        $base = $pdo->query("SELECT id, xray_kind, service_code FROM funnels WHERE is_base = 1 ORDER BY position, id")->fetchAll(PDO::FETCH_ASSOC);
        $setCode = $pdo->prepare("UPDATE funnels SET service_code = ? WHERE id = ? AND service_code = ''");
        foreach (PIPE2_SERVICES as $code => $svc) {
            if ($svc['v1'] === null || isset($coded[$code])) continue;
            foreach ($base as $i => $f) {
                if ((string)$f['xray_kind'] !== $svc['v1'] || (string)$f['service_code'] !== '') continue;
                $setCode->execute([$code, (int)$f['id']]);
                if ($setCode->rowCount() === 1) {
                    $coded[$code] = (int)$f['id'];
                    $base[$i]['service_code'] = $code;
                }
                break;
            }
        }

        // 3. As 6 sub-etapas de cada funil com código que ainda não tenha nenhuma.
        $hasFront = $pdo->prepare("SELECT COUNT(*) FROM funnel_stages WHERE funnel_id = ? AND level = 'front'");
        $insFront = $pdo->prepare("INSERT INTO funnel_stages (funnel_id, name, kind, position, role, rounds_included, level, created_at)
                                   VALUES (?, ?, 'production', ?, ?, ?, 'front', ?)");
        foreach (PIPE2_SEED_FRONTS as $code => [$names, $rounds]) {
            if (!isset($coded[$code])) continue;
            $hasFront->execute([$coded[$code]]);
            $n = (int)$hasFront->fetchColumn();
            $hasFront->closeCursor();
            if ($n > 0) continue;
            foreach ($names as $i => $name) {
                $role = $i === 4 ? 'revisions' : ($i === 5 ? 'ready' : '');
                $insFront->execute([$coded[$code], $name, 110 + 10 * $i, $role, $i === 4 ? $rounds : null, $now]);
            }
        }

        // 4. O departamento do chefe do serviço (nome exato, o primeiro por id), só se ainda estiver vazio.
        $depts = $pdo->query('SELECT id, name FROM departments ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $setDept = $pdo->prepare('UPDATE funnels SET department_id = ? WHERE id = ? AND department_id IS NULL');
        foreach (PIPE2_SEED_DEPTS as $code => $dname) {
            if (!isset($coded[$code])) continue;
            foreach ($depts as $d) {
                if ((string)$d['name'] !== $dname) continue;
                $setDept->execute([(int)$d['id'], $coded[$code]]);
                break;
            }
        }

        // 5. As categorias que ainda não existem (pelo código).
        $have = array_map('strval', $pdo->query('SELECT code FROM pipe2_categories')->fetchAll(PDO::FETCH_COLUMN));
        $insCat = $pdo->prepare('INSERT INTO pipe2_categories (code, name, position, need_all, need_any, need_min, exclude_any, is_active, updated_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)');
        foreach (PIPE2_SEED_CATEGORIES as [$code, $name, $pos, $all, $any, $min, $excl]) {
            if (in_array($code, $have, true)) continue;
            $insCat->execute([$code, $name, $pos, $all, $any, $min, $excl, $now]);
            $have[] = $code;
        }

        // 6. Os modelos de dependências, resolvidos pela sub-etapa com esse nome no funil desse código.
        $fronts = [];
        foreach ($pdo->query("SELECT id, funnel_id, name FROM funnel_stages WHERE level = 'front' ORDER BY position, id")->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $fronts[(int)$s['funnel_id']][(string)$s['name']] ??= (int)$s['id'];
        }
        $stageOf = fn(string $code, string $name): ?int => isset($coded[$code]) ? ($fronts[$coded[$code]][$name] ?? null) : null;
        $hasModel = $pdo->prepare('SELECT COUNT(*) FROM pipe2_dep_models WHERE category = ? AND from_stage_id = ? AND to_stage_id = ?');
        $insModel = $pdo->prepare('INSERT INTO pipe2_dep_models (category, from_stage_id, to_stage_id, position, is_active, updated_at) VALUES (?, ?, ?, ?, 1, ?)');
        $posIn = [];
        foreach (PIPE2_SEED_DEP_MODELS as [$cat, $fa, $na, $fb, $nb]) {
            $pos = $posIn[$cat] = ($posIn[$cat] ?? 0) + 1;
            $from = $stageOf($fa, $na);
            $to = $stageOf($fb, $nb);
            if ($from === null || $to === null) continue;
            $hasModel->execute([$cat, $from, $to]);
            $n = (int)$hasModel->fetchColumn();
            $hasModel->closeCursor();
            if ($n > 0) continue;
            $insModel->execute([$cat, $from, $to, $pos, $now]);
        }

        // 7. A bandeira, reclamada por último: se outro pedido já a gravou, nada do que se fez aqui fica.
        $flag = $pdo->prepare(db_is_sqlite($pdo) ? 'INSERT OR IGNORE INTO settings (skey, svalue) VALUES (?, ?)'
                                                 : 'INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)');
        $flag->execute(['pipe2_seeded', $now]);
        if ($flag->rowCount() !== 1) {
            $pdo->rollBack();
            return false;
        }
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
