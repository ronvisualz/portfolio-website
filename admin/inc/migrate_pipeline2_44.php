<?php
/**
 * topper. admin — passo 44 · Pipeline v2, fase 1
 *
 * Só acrescenta, sem dados:
 *   · stage_automations.action — o que a regra faz: 'task' (criar tarefa, como hoje; todas as regras existentes ficam
 *     com este valor) ou 'notify' (só um aviso, sem tarefa);
 *   · stage_automations.trig_n — o N dos gatilhos novos: «frente parada há N dias» (f_stale) e o dia da avença
 *     (fee_day); NULL nas regras existentes;
 *   · tasks.gate_stage_id — uma tarefa de frente que também conta para o gate de uma etapa do negócio (a tarefa de
 *     negócio que não se criou por já existir na frente); NULL nas tarefas existentes;
 *   · a tabela pipe2_notices — os avisos da v2, guardados uma vez por chave (nkey) e para um destinatário.
 *
 * Até ao «Aplicar» da conversão nada lê estas colunas fora dos ramos v2 (a guarda dos leitores continua a ser
 * pipe2_schema_ready, settings.pipe2_seeded, gravada pelo passo 43). Nada de UNIQUE, ENUM nem NOT NULL sem DEFAULT,
 * para que o ALTER nunca falhe com os dados que lá estão.
 *
 * Ordem: GET_LOCK('topper_pipe2_' . DATABASE(), 30), o mesmo do passo 43 → DDL (db_add_column e db_create; o DDL faz
 * commit implícito no MySQL, por isso não há transação) → RELEASE_LOCK no finally. Se o lock falhar, lança: um return
 * deixava o run_migrations marcar a 44 sem as colunas.
 *
 * Reversão: este passo não tem desfazer em produção. Repor o código da fase 0 deixa a base na 44: o migrate.php da
 * fase 0 (43) não volta a migrar e ignora as colunas e a tabela novas. Prova: tests/pipe2_rollback_test.php
 * (::phase1_rollback).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_create, db_add_column

/** Lock → esquema; o lock solta-se sempre. */
function migrate_pipeline2_44(PDO $pdo): void {
    $lock = migrate_pipeline2_44_lock($pdo);
    try {
        migrate_pipeline2_44_schema($pdo);
    } finally {
        if ($lock !== '') $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}

/**
 * Um passo da v2 de cada vez por base (o mesmo nome de lock do passo 43). Devolve o nome do lock, ou '' em SQLite
 * (uma ligação só). Lança se não o obtiver em 30 s.
 */
function migrate_pipeline2_44_lock(PDO $pdo): string {
    if (db_is_sqlite($pdo)) return '';
    $name = 'topper_pipe2_' . (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if (strlen($name) > 64) $name = 'topper_pipe2_' . md5($name);   // o MySQL não aceita nomes de lock maiores
    $st = $pdo->prepare('SELECT GET_LOCK(?, 30)');
    $st->execute([$name]);
    $got = $st->fetchColumn();
    $st->closeCursor();
    if ((string)$got !== '1') throw new RuntimeException('Passo 44: não foi possível obter o lock ' . $name . ' (outra migração a correr?).');
    return $name;
}

/** Só DDL: as três colunas com db_add_column e a tabela dos avisos com db_create. */
function migrate_pipeline2_44_schema(PDO $pdo): void {
    foreach ([
        // regras: a ação (tarefa ou aviso) e o N dos gatilhos f_stale e fee_day
        ['stage_automations', 'action', "VARCHAR(8) NOT NULL DEFAULT 'task'", "TEXT NOT NULL DEFAULT 'task'"],
        ['stage_automations', 'trig_n', 'SMALLINT NULL', 'INTEGER NULL'],
        // tarefas: o gate partilhado (uma tarefa de frente que conta para o gate de uma etapa do negócio)
        ['tasks', 'gate_stage_id', 'INT UNSIGNED NULL', 'INTEGER NULL'],
    ] as [$table, $col, $my, $sq]) {
        if (db_has_table($pdo, $table)) db_add_column($pdo, $table, $col, $my, $sq);
    }

    // os avisos da v2: um por chave (replan:<frente>:<sub-etapa>:<ciclo>, …), para um destinatário
    db_create($pdo, 'pipe2_notices', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id    INT UNSIGNED NOT NULL,
        line_id    INT UNSIGNED NULL,
        stage_id   INT UNSIGNED NULL,
        cycle_ym   VARCHAR(7)   NOT NULL DEFAULT '',
        kind       VARCHAR(10)  NOT NULL DEFAULT 'rule',
        rule_id    INT UNSIGNED NULL,
        user_id    INT UNSIGNED NULL,
        title      VARCHAR(200) NOT NULL DEFAULT '',
        body       VARCHAR(255) NOT NULL DEFAULT '',
        nkey       VARCHAR(64)  NOT NULL DEFAULT '',
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_p2n_user (user_id, created_at),
        INDEX idx_p2n_key (nkey),
        INDEX idx_p2n_deal (deal_id)");
}
