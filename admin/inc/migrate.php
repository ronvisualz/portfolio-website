<?php
/**
 * topper. admin — actualização automática da base de dados
 *
 * Corre sozinho, uma vez, quando o site nota que a base está
 * atrasada em relação ao código. Não há nada a fazer no phpMyAdmin.
 *
 * Cada passo verifica primeiro se já está feito, por isso pode
 * correr as vezes que forem: nunca duplica nem estraga nada. No
 * fim guarda a versão em settings.schema_version e deixa de correr.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

/** A versão que este código espera encontrar. */
const SCHEMA_VERSION = 32;

/** MySQL em produção; SQLite só na bancada de testes local. */
function db_is_sqlite(PDO $pdo): bool {
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

function db_has_table(PDO $pdo, string $table): bool {
    if (db_is_sqlite($pdo)) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$table]);
        return (int)$st->fetchColumn() > 0;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function db_has_column(PDO $pdo, string $table, string $column): bool {
    if (db_is_sqlite($pdo)) {
        foreach ($pdo->query('PRAGMA table_info(' . $pdo->quote($table) . ')')->fetchAll() as $c) {
            if ($c['name'] === $column) return true;
        }
        return false;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * $from é a versão que a base tinha. Os passos 01–05 já estavam
 * todos feitos numa base na versão 5, por isso só correm abaixo dela.
 */
function run_migrations(PDO $pdo, int $from = 0): void {

  if ($from < 5) {

    // ── 01 · «Tem IVA» em cada registo ──────────────────────
    if (!db_has_column($pdo, 'projects', 'has_vat')) {
        $pdo->exec(
            'ALTER TABLE projects
               ADD COLUMN has_vat TINYINT(1) NOT NULL DEFAULT 1 AFTER is_international'
        );
        // Os trabalhos de exportação não levavam IVA: preserva-se o que
        // o campo «internacional» significava até aqui.
        $pdo->exec('UPDATE projects SET has_vat = 0 WHERE is_international = 1');
    }

    // ── 02 · o valor de cada custo diz o que é ──────────────
    if (!db_has_column($pdo, 'crew', 'vat_mode')) {
        $pdo->exec(
            "ALTER TABLE crew
               ADD COLUMN vat_mode ENUM('none','plus','incl') NOT NULL DEFAULT 'plus' AFTER fee"
        );
        if (db_has_column($pdo, 'crew', 'invoices_vat')) {
            $pdo->exec("UPDATE crew SET vat_mode = 'none' WHERE invoices_vat = 0");
        }
    }
    if (db_has_column($pdo, 'crew', 'invoices_vat')) {
        $pdo->exec('ALTER TABLE crew DROP COLUMN invoices_vat');
    }

    // ── 03 · custos da empresa ──────────────────────────────
    if (!db_has_table($pdo, 'company_costs')) {
        $pdo->exec(
            "CREATE TABLE company_costs (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               year       SMALLINT     NOT NULL,
               month      TINYINT      NULL,
               name       VARCHAR(160) NOT NULL,
               amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
               vat_mode   ENUM('none','plus','incl') NOT NULL DEFAULT 'plus',
               is_paid    TINYINT(1)   NOT NULL DEFAULT 0,
               notes      TEXT         NULL,
               created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
               INDEX idx_year (year)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // ── 04 · calendário ─────────────────────────────────────
    if (!db_has_table($pdo, 'events')) {
        $pdo->exec(
            "CREATE TABLE events (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               title      VARCHAR(160) NOT NULL,
               event_date DATE         NOT NULL,
               start_time TIME         NULL,
               end_time   TIME         NULL,
               project_id INT UNSIGNED NULL,
               location   VARCHAR(160) NOT NULL DEFAULT '',
               notes      TEXT         NULL,
               created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
               CONSTRAINT fk_event_project FOREIGN KEY (project_id)
                 REFERENCES projects(id) ON DELETE SET NULL,
               INDEX idx_date (event_date),
               INDEX idx_project (project_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // ── 05 · os gastos antigos mudam-se para a estrutura ────
    //
    // A Carteira passou a mostrar só trabalhos. Os registos antigos
    // com kind='expense' — contabilidade, alojamento, abertura da
    // empresa — deixariam de aparecer em lado nenhum, por isso
    // passam para company_costs, que é onde vivem agora.
    $antigos = $pdo->query(
        "SELECT id, year, start_month, name, expenses_manual, has_vat, notes
           FROM projects WHERE kind = 'expense'"
    )->fetchAll();

    if ($antigos) {
        $ins = $pdo->prepare(
            'INSERT INTO company_costs (year, month, name, amount, vat_mode, is_paid, notes)
             VALUES (?,?,?,?,?,0,?)'
        );
        $del = $pdo->prepare('DELETE FROM projects WHERE id = ?');

        foreach ($antigos as $g) {
            $ins->execute([
                (int)$g['year'],
                $g['start_month'] !== null ? (int)$g['start_month'] : null,
                $g['name'],
                (float)$g['expenses_manual'],
                (int)$g['has_vat'] === 1 ? 'plus' : 'none',
                $g['notes'],
            ]);
            $del->execute([(int)$g['id']]);
        }
    }

  } // fim dos passos 01–05

    // ── 06 · equipa e tarefas ───────────────────────────────
    //
    // Só cria o que falta. As tabelas antigas não são tocadas, e a
    // equipa só é semeada quando a tabela está vazia.
    migrate_tasks($pdo);

    // ── 07 · quando a tarefa ficou feita ────────────────────
    //
    // Coluna nova, vazia por omissão. As que já estavam feitas ficam
    // com a última alteração como data, para a coluna «Feito» as ordenar.
    migrate_done_at($pdo);

    // ── 08 · Drive avançado e assistente ────────────────────
    //
    // Favoritos, recentes, Lixo, links de partilha, índice de conteúdo
    // e as tabelas do assistente (conversas, ações, gastos). Só cria
    // as tabelas que faltam; nada do que existe é tocado.
    migrate_drive($pdo);

    // ── 09 · password opcional nos links de partilha ────────
    migrate_share_password($pdo);

    // ── 10 · os dados da folha Google passam para a base ────
    //
    // Trabalhos, equipa, custos da empresa, leads e fornecedores ganham
    // tabelas próprias (até aqui viviam na folha). As tabelas antigas da
    // primeira versão do admin (projects, crew, company_costs, leads)
    // ficam guardadas com o prefixo legacy_, sem nada apagado.
    migrate_data($pdo);

    // ── 11 · a plataforma: utilizadores, negócios, funis ────
    //
    // Tabelas novas desenhadas a partir da especificação
    // (docs/ESPECIFICACAO-PLATAFORMA.md). Os dados da versão 10 (jobs,
    // job_crew, company_costs, leads, suppliers) passam para aqui pela
    // página Migração e ficam guardados, sem nada apagado.
    require_once __DIR__ . '/migrate_platform.php';
    migrate_platform($pdo);

    // ── 12 · a linha de design (§11) ────────────────────────
    //
    // Preferências na conta (modo, menu, nome), histórico de interações,
    // notificações lidas e recuperação de palavra-passe.
    require_once __DIR__ . '/migrate_design.php';
    migrate_design($pdo);

    // ── 13 · aba Leads v2 (§12) ─────────────────────────────
    //
    // Empresa e pessoa em cada lead, estados Novo · Sem resposta ·
    // Follow-Up com prazo em horas, tarefas com tipo e hora. As leads
    // antigas passam para a v2 (contactos separados, empresas criadas
    // em Contactos sem duplicar).
    require_once __DIR__ . '/migrate_leads_v2.php';
    migrate_leads_v2($pdo);

    // ── 14 · tipos de tarefa ────────────────────────────────
    //
    // Sai «Visita» e entram «Almoço» e «Jantar»: as visitas que existam
    // passam a Reunião (também conta como contacto com a lead).
    $pdo->exec("UPDATE tasks SET kind = 'meeting' WHERE kind = 'visit'");

    // ── 15 · aba Contactos v2 (§13) ─────────────────────────
    //
    // Pessoa e empresa, serviços e preços com histórico, avaliações por
    // trabalho, relações acompanhadas, «Trazido por» e tarefas de um
    // contacto. Os contactos atuais passam ao modelo novo uma vez.
    require_once __DIR__ . '/migrate_contacts_v2.php';
    migrate_contacts_v2($pdo);

    // ── 16 · aba Orçamentação (§14) ─────────────────────────
    //
    // Uma folha por negócio com versões, linhas de custo e histórico,
    // modelos, custo/hora e horas por mês de cada pessoa da Equipa e as
    // definições globais (MSO, margem, horas por dia, meta mensal).
    require_once __DIR__ . '/migrate_budget.php';
    migrate_budget($pdo);

    // ── 17 · aba Negócios — ficha central (§15) ─────────────
    //
    // A etapa de produção (enquanto o funil não tiver etapas próprias) e o
    // que a checklist de fecho regista: entregas, portfólio, testemunho.
    require_once __DIR__ . '/migrate_deal_file.php';
    migrate_deal_file($pdo);

    // ── 18 · aba Marketing (§16) ────────────────────────────
    //
    // Pilares, peças (ideias que avançam para Produção), versões por rede,
    // publicações com link com rastreio e métricas, dias de gravação, uso da
    // IA, «Pode ser divulgado?» nos negócios e a estrutura da fase 2.
    require_once __DIR__ . '/migrate_marketing.php';
    migrate_marketing($pdo);

    // ── 19 · aba Equipa (§17) ───────────────────────────────
    //
    // Cargos, sócio, «responde a», subdepartamentos, horário, presença, Foco,
    // convites, chat 1:1, reuniões, férias e ausências, checklists de entrada e
    // saída, Ocupado/Livre nas tarefas; estrutura inicial e contas dos sócios.
    require_once __DIR__ . '/migrate_team.php';
    migrate_team($pdo);

    // ── 20 · aba Tarefas (§18) ──────────────────────────────
    //
    // admin@ passa a info@ (Superadmin, mesma palavra-passe); o superadmin deixa
    // de ser operador e as leads dele são redistribuídas uma vez pelos 5 sócios;
    // nas tarefas: local, plataforma, privada, checklist, anexos, participantes,
    // pedido de ajuda, histórico e vistas guardadas.
    require_once __DIR__ . '/migrate_tasks.php';
    migrate_tasks_tab($pdo);

    // ── 21 · Pipeline, funis e automações (§19) ─────────────
    //
    // 6 funis (Adverts separa-se de Campanhas), Proposta e Aguardar feedback em todos,
    // etapas de produção por funil, gates, motor de automações com as tarefas da §19.6
    // como valores iniciais, X-Ray, Opção A/B no orçamento, propostas com resposta do
    // cliente e documentos de faturação submetidos.
    require_once __DIR__ . '/migrate_pipeline.php';
    migrate_pipeline_flow($pdo);

    // ── 22 · Perfil e Definições (§20) ──────────────────────
    //
    // Registo de alterações das Definições e as preferências pessoais do Perfil.
    require_once __DIR__ . '/migrate_settings.php';
    migrate_settings_tab($pdo);

    // ── 23 · Crosselling: um negócio, várias frentes (§22) ──
    //
    // Os serviços de cada negócio (frentes no Kickoff), pacotes, oportunidades, regras
    // de crosselling depois da entrega e o serviço de cada linha do orçamento.
    require_once __DIR__ . '/migrate_xsell.php';
    migrate_xsell($pdo);

    // ── 24 · Centro Criativo (§23) ─────────────────────────
    //
    // Quadros, biblioteca (referências sem cópia), frames de vídeo, cartões, comentários,
    // páginas do pré-criativo, fila e gasto da IA; Review, portal e captura preparados.
    require_once __DIR__ . '/migrate_creative.php';
    migrate_creative($pdo);

    // ── 25 · O registo de ficheiros (§10.15) ───────────────
    //
    // Cada ficheiro que entra ou é gerado fica uma vez no Drive, na pasta do negócio, da
    // lead ou da origem; a fila das pastas que mudam de sítio (arquivar, reabrir, converter).
    // ── 26 · Reorganizar o Drive (§25, fase 2): a fila do que já existia ──
    require_once __DIR__ . '/migrate_files.php';
    migrate_files($pdo);

    // ── 27 · Centro Criativo, fase 2: Review e Portal do cliente (§24) ──
    //
    // Peças de review com versões (nunca se apagam), comentários no frame/intervalo/pino com
    // desenho, internos ou para o cliente, rondas por envio consolidado, portal por negócio.
    require_once __DIR__ . '/migrate_review.php';
    migrate_review($pdo);
    require_once __DIR__ . '/migrate_rt.php';
    migrate_rt($pdo);
    // ── 29 · Centro Criativo: áudio (§27) — tipo próprio, categoria, forma de onda; os áudios antigos convertidos ──
    require_once __DIR__ . '/migrate_audio.php';
    migrate_audio($pdo);
    // ── 30 · Financeiro (§28): parâmetros com fonte legal, tabelas do IRS, documentos, pagamentos, custos fixos,
    //        tesouraria, salários, simulador e caixa das faturas; os custos da empresa passam para os lançamentos ──
    require_once __DIR__ . '/migrate_finance.php';
    migrate_finance($pdo);
    // ── 31 · Tesouraria (saldo semanal, responsável por conta) e reservas dos sócios por negócio ──
    migrate_finance_31($pdo);
    migrate_finance_32($pdo);

    if (db_is_sqlite($pdo)) {
        $pdo->prepare("INSERT OR REPLACE INTO settings (skey, svalue) VALUES ('schema_version', ?)")
            ->execute([(string)SCHEMA_VERSION]);
    } else {
        $pdo->prepare(
            "INSERT INTO settings (skey, svalue) VALUES ('schema_version', ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)"
        )->execute([(string)SCHEMA_VERSION]);
    }
}

/** As cinco cores da equipa, da paleta da casa. Novos membros repetem o ciclo. */
const TEAM_COLORS = ['#81CFE3', '#FBAE9C', '#FFC718', '#9CCB8F', '#B9A6E3'];

function migrate_tasks(PDO $pdo): void {
    $lite = db_is_sqlite($pdo);

    if (!db_has_table($pdo, 'team_members')) {
        $pdo->exec($lite
            ? "CREATE TABLE team_members (
                 id         INTEGER PRIMARY KEY AUTOINCREMENT,
                 name       TEXT    NOT NULL,
                 color      TEXT    NOT NULL DEFAULT '',
                 position   INTEGER NOT NULL DEFAULT 0,
                 created_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
               )"
            : "CREATE TABLE team_members (
                 id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                 name       VARCHAR(120) NOT NULL,
                 color      VARCHAR(16)  NOT NULL DEFAULT '',
                 position   INT          NOT NULL DEFAULT 0,
                 created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM team_members')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO team_members (name, color, position) VALUES (?,?,?)');
        $seed = ['Diogo Vasconcelos', 'Rodrigo Nicolau', 'Valdir Furtado', 'Guilherme Albano', 'Santiago Apolónio'];
        foreach ($seed as $i => $name) {
            $ins->execute([$name, TEAM_COLORS[$i], $i + 1]);
        }
    }

    if (!db_has_table($pdo, 'tasks')) {
        $pdo->exec($lite
            ? "CREATE TABLE tasks (
                 id          INTEGER PRIMARY KEY AUTOINCREMENT,
                 title       TEXT    NOT NULL,
                 description TEXT    NULL,
                 member_id   INTEGER NULL REFERENCES team_members(id) ON DELETE SET NULL,
                 job_name    TEXT    NOT NULL DEFAULT '',
                 due_date    TEXT    NULL,
                 priority    TEXT    NOT NULL DEFAULT 'media',
                 status      TEXT    NOT NULL DEFAULT 'todo',
                 position    INTEGER NOT NULL DEFAULT 0,
                 created_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                 updated_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
               )"
            : "CREATE TABLE tasks (
                 id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                 title       VARCHAR(200) NOT NULL,
                 description TEXT         NULL,
                 member_id   INT UNSIGNED NULL,
                 job_name    VARCHAR(160) NOT NULL DEFAULT '',
                 due_date    DATE         NULL,
                 priority    ENUM('alta','media','baixa') NOT NULL DEFAULT 'media',
                 status      ENUM('todo','doing','review','done') NOT NULL DEFAULT 'todo',
                 position    INT          NOT NULL DEFAULT 0,
                 created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                 updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                 CONSTRAINT fk_task_member FOREIGN KEY (member_id)
                   REFERENCES team_members(id) ON DELETE SET NULL,
                 INDEX idx_stack (member_id, position),
                 INDEX idx_due (due_date)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

function migrate_done_at(PDO $pdo): void {
    if (!db_has_table($pdo, 'tasks') || db_has_column($pdo, 'tasks', 'done_at')) return;
    $pdo->exec(db_is_sqlite($pdo)
        ? 'ALTER TABLE tasks ADD COLUMN done_at TEXT NULL'
        : 'ALTER TABLE tasks ADD COLUMN done_at DATETIME NULL AFTER position'
    );
    $pdo->exec("UPDATE tasks SET done_at = updated_at WHERE status = 'done' AND done_at IS NULL");
}

/**
 * As tabelas do Drive e do assistente.
 *
 * Datas em UTC escritas pelo PHP (sem DEFAULT do SGBD). As chaves do R2
 * vão até 1024 bytes, mais do que o MySQL consegue indexar em utf8mb4:
 * guarda-se a chave (obj_key, comparação exata) e o sha256 dela
 * (key_hash), que é a chave primária. Colunas TEXT sem DEFAULT (o MySQL
 * não aceita) e NULL quando podem faltar.
 */
function migrate_drive(PDO $pdo): void {
    $lite = db_is_sqlite($pdo);
    $T  = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $K  = 'VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin';   // chave do R2, exata
    $H  = 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin';             // sha256 em hex

    $tables = [
        'drive_favs' => [
            "CREATE TABLE drive_favs (
               key_hash   TEXT NOT NULL PRIMARY KEY,
               obj_key    TEXT NOT NULL,
               created_at TEXT NOT NULL
             )",
            "CREATE TABLE drive_favs (
               key_hash   $H       NOT NULL,
               obj_key    $K       NOT NULL,
               created_at DATETIME NOT NULL,
               PRIMARY KEY (key_hash)
             )$T",
        ],
        'drive_recent' => [
            "CREATE TABLE drive_recent (
               key_hash  TEXT NOT NULL PRIMARY KEY,
               obj_key   TEXT NOT NULL,
               opened_at TEXT NOT NULL
             )",
            "CREATE TABLE drive_recent (
               key_hash  $H       NOT NULL,
               obj_key   $K       NOT NULL,
               opened_at DATETIME NOT NULL,
               PRIMARY KEY (key_hash),
               INDEX idx_opened (opened_at)
             )$T",
        ],
        'drive_trash' => [
            "CREATE TABLE drive_trash (
               id         INTEGER PRIMARY KEY AUTOINCREMENT,
               orig_key   TEXT    NOT NULL,
               is_folder  INTEGER NOT NULL DEFAULT 0,
               size       INTEGER NOT NULL DEFAULT 0,
               files      INTEGER NOT NULL DEFAULT 0,
               deleted_at TEXT    NOT NULL
             )",
            "CREATE TABLE drive_trash (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               orig_key   $K         NOT NULL,
               is_folder  TINYINT(1) NOT NULL DEFAULT 0,
               size       BIGINT     NOT NULL DEFAULT 0,
               files      INT        NOT NULL DEFAULT 0,
               deleted_at DATETIME   NOT NULL,
               INDEX idx_deleted (deleted_at)
             )$T",
        ],
        'drive_shares' => [
            "CREATE TABLE drive_shares (
               id         INTEGER PRIMARY KEY AUTOINCREMENT,
               token      TEXT    NOT NULL UNIQUE,
               obj_key    TEXT    NOT NULL,
               is_folder  INTEGER NOT NULL DEFAULT 0,
               expires_at TEXT    NOT NULL,
               created_at TEXT    NOT NULL,
               downloads  INTEGER NOT NULL DEFAULT 0,
               revoked    INTEGER NOT NULL DEFAULT 0
             )",
            "CREATE TABLE drive_shares (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               token      CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
               obj_key    $K         NOT NULL,
               is_folder  TINYINT(1) NOT NULL DEFAULT 0,
               expires_at DATETIME   NOT NULL,
               created_at DATETIME   NOT NULL,
               downloads  INT        NOT NULL DEFAULT 0,
               revoked    TINYINT(1) NOT NULL DEFAULT 0,
               UNIQUE KEY uq_token (token),
               INDEX idx_expires (expires_at)
             )$T",
        ],
        'drive_index' => [
            "CREATE TABLE drive_index (
               key_hash   TEXT    NOT NULL PRIMARY KEY,
               obj_key    TEXT    NOT NULL,
               etag       TEXT    NOT NULL DEFAULT '',
               size       INTEGER NOT NULL DEFAULT 0,
               mime       TEXT    NOT NULL DEFAULT '',
               status     TEXT    NOT NULL DEFAULT 'pending',
               method     TEXT    NOT NULL DEFAULT 'none',
               summary    TEXT    NULL,
               body       TEXT    NULL,
               norm       TEXT    NULL,
               error      TEXT    NULL,
               tries      INTEGER NOT NULL DEFAULT 0,
               indexed_at TEXT    NULL,
               updated_at TEXT    NOT NULL
             )",
            "CREATE TABLE drive_index (
               key_hash   $H           NOT NULL,
               obj_key    $K           NOT NULL,
               etag       VARCHAR(80)  NOT NULL DEFAULT '',
               size       BIGINT       NOT NULL DEFAULT 0,
               mime       VARCHAR(120) NOT NULL DEFAULT '',
               status     VARCHAR(16)  NOT NULL DEFAULT 'pending',
               method     VARCHAR(16)  NOT NULL DEFAULT 'none',
               summary    TEXT         NULL,
               body       MEDIUMTEXT   NULL,
               norm       MEDIUMTEXT   NULL,
               error      VARCHAR(255) NULL,
               tries      INT          NOT NULL DEFAULT 0,
               indexed_at DATETIME     NULL,
               updated_at DATETIME     NOT NULL,
               PRIMARY KEY (key_hash),
               INDEX idx_etag (etag),
               INDEX idx_status (status)
             )$T",
        ],
        'ai_usage' => [
            "CREATE TABLE ai_usage (
               id            INTEGER PRIMARY KEY AUTOINCREMENT,
               created_at    TEXT    NOT NULL,
               purpose       TEXT    NOT NULL DEFAULT '',
               model         TEXT    NOT NULL DEFAULT '',
               input_tokens  INTEGER NOT NULL DEFAULT 0,
               output_tokens INTEGER NOT NULL DEFAULT 0,
               cache_read    INTEGER NOT NULL DEFAULT 0,
               cache_write   INTEGER NOT NULL DEFAULT 0,
               cost_usd      REAL    NOT NULL DEFAULT 0
             )",
            "CREATE TABLE ai_usage (
               id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               created_at    DATETIME      NOT NULL,
               purpose       VARCHAR(16)   NOT NULL DEFAULT '',
               model         VARCHAR(64)   NOT NULL DEFAULT '',
               input_tokens  INT           NOT NULL DEFAULT 0,
               output_tokens INT           NOT NULL DEFAULT 0,
               cache_read    INT           NOT NULL DEFAULT 0,
               cache_write   INT           NOT NULL DEFAULT 0,
               cost_usd      DECIMAL(12,6) NOT NULL DEFAULT 0,
               INDEX idx_created (created_at)
             )$T",
        ],
        'ai_conversations' => [
            "CREATE TABLE ai_conversations (
               id            INTEGER PRIMARY KEY AUTOINCREMENT,
               title         TEXT    NOT NULL DEFAULT '',
               created_at    TEXT    NOT NULL,
               updated_at    TEXT    NOT NULL,
               prompt_tokens INTEGER NOT NULL DEFAULT 0
             )",
            "CREATE TABLE ai_conversations (
               id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               title         VARCHAR(200) NOT NULL DEFAULT '',
               created_at    DATETIME     NOT NULL,
               updated_at    DATETIME     NOT NULL,
               prompt_tokens INT          NOT NULL DEFAULT 0,
               INDEX idx_updated (updated_at)
             )$T",
        ],
        'ai_messages' => [
            "CREATE TABLE ai_messages (
               id         INTEGER PRIMARY KEY AUTOINCREMENT,
               conv_id    INTEGER NOT NULL,
               role       TEXT    NOT NULL,
               content    TEXT    NOT NULL,
               display    TEXT    NULL,
               created_at TEXT    NOT NULL
             )",
            "CREATE TABLE ai_messages (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               conv_id    INT UNSIGNED NOT NULL,
               role       VARCHAR(16)  NOT NULL,
               content    MEDIUMTEXT   NOT NULL,
               display    MEDIUMTEXT   NULL,
               created_at DATETIME     NOT NULL,
               INDEX idx_conv (conv_id, id)
             )$T",
        ],
        'ai_actions' => [
            "CREATE TABLE ai_actions (
               id         INTEGER PRIMARY KEY AUTOINCREMENT,
               conv_id    INTEGER NOT NULL,
               token      TEXT    NOT NULL UNIQUE,
               kind       TEXT    NOT NULL,
               payload    TEXT    NOT NULL,
               status     TEXT    NOT NULL DEFAULT 'pending',
               result     TEXT    NULL,
               created_at TEXT    NOT NULL,
               decided_at TEXT    NULL,
               noted      INTEGER NOT NULL DEFAULT 0
             )",
            "CREATE TABLE ai_actions (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               conv_id    INT UNSIGNED NOT NULL,
               token      CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
               kind       VARCHAR(16)  NOT NULL,
               payload    MEDIUMTEXT   NOT NULL,
               status     VARCHAR(12)  NOT NULL DEFAULT 'pending',
               result     TEXT         NULL,
               created_at DATETIME     NOT NULL,
               decided_at DATETIME     NULL,
               noted      TINYINT(1)   NOT NULL DEFAULT 0,
               UNIQUE KEY uq_token (token),
               INDEX idx_conv (conv_id)
             )$T",
        ],
    ];
    foreach ($tables as $name => [$sqlite, $mysql]) {
        if (!db_has_table($pdo, $name)) $pdo->exec($lite ? $sqlite : $mysql);
    }

    // No SQLite os índices são instruções à parte.
    if ($lite) {
        foreach ([
            'CREATE INDEX IF NOT EXISTS idx_drive_recent_opened ON drive_recent (opened_at)',
            'CREATE INDEX IF NOT EXISTS idx_drive_trash_deleted ON drive_trash (deleted_at)',
            'CREATE INDEX IF NOT EXISTS idx_drive_shares_expires ON drive_shares (expires_at)',
            'CREATE INDEX IF NOT EXISTS idx_drive_index_etag ON drive_index (etag)',
            'CREATE INDEX IF NOT EXISTS idx_drive_index_status ON drive_index (status)',
            'CREATE INDEX IF NOT EXISTS idx_ai_usage_created ON ai_usage (created_at)',
            'CREATE INDEX IF NOT EXISTS idx_ai_conversations_updated ON ai_conversations (updated_at)',
            'CREATE INDEX IF NOT EXISTS idx_ai_messages_conv ON ai_messages (conv_id, id)',
            'CREATE INDEX IF NOT EXISTS idx_ai_actions_conv ON ai_actions (conv_id)',
        ] as $sql) $pdo->exec($sql);
    }
}

/**
 * Links de partilha com password: pw_hash (password_hash, vazio = sem password) e o contador de
 * tentativas falhadas (pw_fails desde pw_fail_at) para travar quem tenta adivinhar.
 */
function migrate_share_password(PDO $pdo): void {
    if (!db_has_table($pdo, 'drive_shares') || db_has_column($pdo, 'drive_shares', 'pw_hash')) return;
    $lite = db_is_sqlite($pdo);
    $pdo->exec('ALTER TABLE drive_shares ADD COLUMN pw_hash ' . ($lite ? 'TEXT' : 'VARCHAR(255)') . ' NULL');
    $pdo->exec('ALTER TABLE drive_shares ADD COLUMN pw_fails ' . ($lite ? 'INTEGER' : 'INT') . ' NOT NULL DEFAULT 0');
    $pdo->exec('ALTER TABLE drive_shares ADD COLUMN pw_fail_at ' . ($lite ? 'TEXT' : 'DATETIME') . ' NULL');
}

/**
 * 10 · as cinco tabelas dos dados da empresa (jobs, job_crew, company_costs, leads, suppliers)
 * e events.job_id. As tabelas da primeira versão com os mesmos nomes mudam para legacy_*.
 */
function migrate_data(PDO $pdo): void {
    $lite = db_is_sqlite($pdo);
    $T = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $rename = static function (string $from, string $to) use ($pdo, $lite): void {
        if (!db_has_table($pdo, $from) || db_has_table($pdo, $to)) return;
        $pdo->exec($lite ? "ALTER TABLE $from RENAME TO $to" : "RENAME TABLE $from TO $to");
    };
    // As antigas: projects e crew sempre; company_costs e leads só se forem do esquema antigo (sem «position»).
    $rename('projects', 'legacy_projects');
    $rename('crew', 'legacy_crew');
    if (db_has_table($pdo, 'company_costs') && !db_has_column($pdo, 'company_costs', 'position')) $rename('company_costs', 'legacy_company_costs');
    if (db_has_table($pdo, 'leads') && !db_has_column($pdo, 'leads', 'position')) $rename('leads', 'legacy_leads');

    $money = $lite ? 'REAL' : 'DECIMAL(12,2)';
    $id    = $lite ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $ref   = $lite ? 'INTEGER' : 'INT UNSIGNED';
    $int   = $lite ? 'INTEGER' : 'INT';
    $bool  = $lite ? 'INTEGER' : 'TINYINT(1)';
    $tiny  = $lite ? 'INTEGER' : 'TINYINT UNSIGNED';
    $str   = static fn(int $n) => $lite ? 'TEXT' : "VARCHAR($n)";
    $txt   = $lite ? 'TEXT' : 'TEXT';
    $dt    = $lite ? 'TEXT' : 'DATETIME';
    $date  = $lite ? 'TEXT' : 'DATE';
    $tail  = $lite ? '' : $T;

    if (!db_has_table($pdo, 'jobs')) {
        $pdo->exec("CREATE TABLE jobs (
            $id,
            name             {$str(255)} NOT NULL,
            year             $int        NOT NULL DEFAULT 0,
            month            $tiny       NULL,
            type_label       {$str(120)} NOT NULL DEFAULT '',
            budget_net       $money      NOT NULL DEFAULT 0,
            is_international $bool       NOT NULL DEFAULT 0,
            has_vat          $bool       NOT NULL DEFAULT 1,
            expenses_manual  $money      NULL,
            is_paid          $bool       NOT NULL DEFAULT 0,
            notes            $txt        NULL,
            position         $int        NOT NULL DEFAULT 0,
            created_at       $dt         NOT NULL,
            updated_at       $dt         NOT NULL" . ($lite ? '' : ",
            UNIQUE KEY uq_name (name),
            INDEX idx_year (year, month)") . "
        )$tail");
        if ($lite) $pdo->exec('CREATE UNIQUE INDEX uq_jobs_name ON jobs (name)');
    }
    if (!db_has_table($pdo, 'job_crew')) {
        $pdo->exec("CREATE TABLE job_crew (
            $id,
            job_id       $ref        NOT NULL" . ($lite ? ' REFERENCES jobs(id) ON DELETE CASCADE' : '') . ",
            person       {$str(255)} NOT NULL DEFAULT '',
            role         {$str(255)} NOT NULL DEFAULT '',
            fee          $money      NOT NULL DEFAULT 0,
            vat_mode     {$str(4)}   NOT NULL DEFAULT 'plus',
            is_confirmed $bool       NOT NULL DEFAULT 0,
            contact      $txt        NULL,
            notes        $txt        NULL,
            position     $int        NOT NULL DEFAULT 0,
            created_at   $dt         NOT NULL,
            updated_at   $dt         NOT NULL" . ($lite ? '' : ",
            INDEX idx_job (job_id, position),
            CONSTRAINT fk_job_crew_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE") . "
        )$tail");
    }
    if (!db_has_table($pdo, 'company_costs')) {
        $pdo->exec("CREATE TABLE company_costs (
            $id,
            year       $int        NOT NULL DEFAULT 0,
            month      $tiny       NULL,
            name       {$str(255)} NOT NULL DEFAULT '',
            amount     $money      NOT NULL DEFAULT 0,
            vat_mode   {$str(4)}   NOT NULL DEFAULT 'plus',
            is_paid    $bool       NOT NULL DEFAULT 0,
            notes      $txt        NULL,
            position   $int        NOT NULL DEFAULT 0,
            created_at $dt         NOT NULL,
            updated_at $dt         NOT NULL" . ($lite ? '' : ",
            INDEX idx_year (year, month)") . "
        )$tail");
    }
    if (!db_has_table($pdo, 'leads')) {
        $pdo->exec("CREATE TABLE leads (
            $id,
            name       {$str(255)} NOT NULL DEFAULT '',
            types      $txt        NULL,
            contacts   $txt        NULL,
            phone      {$str(120)} NOT NULL DEFAULT '',
            stage      {$str(20)}  NOT NULL DEFAULT 'novo',
            service    {$str(120)} NOT NULL DEFAULT '',
            priority   {$str(40)}  NOT NULL DEFAULT '',
            detail     $txt        NULL,
            why        $txt        NULL,
            email      {$str(255)} NOT NULL DEFAULT '',
            notes      $txt        NULL,
            last_touch $date       NULL,
            position   $int        NOT NULL DEFAULT 0,
            created_at $dt         NOT NULL,
            updated_at $dt         NOT NULL
        )$tail");
    }
    if (!db_has_table($pdo, 'suppliers')) {
        $pdo->exec("CREATE TABLE suppliers (
            $id,
            name       {$str(255)} NOT NULL DEFAULT '',
            category   {$str(120)} NOT NULL DEFAULT '',
            service    $txt        NULL,
            contact    {$str(255)} NOT NULL DEFAULT '',
            email      {$str(255)} NOT NULL DEFAULT '',
            phone      {$str(120)} NOT NULL DEFAULT '',
            links      $txt        NULL,
            nif        {$str(60)}  NOT NULL DEFAULT '',
            rate       $txt        NULL,
            rating     $tiny       NOT NULL DEFAULT 0,
            last_job   {$str(255)} NOT NULL DEFAULT '',
            notes      $txt        NULL,
            position   $int        NOT NULL DEFAULT 0,
            created_at $dt         NOT NULL,
            updated_at $dt         NOT NULL
        )$tail");
    }
    // Os eventos do calendário passam a apontar para um trabalho (o import liga os antigos pelo nome).
    if (db_has_table($pdo, 'events') && !db_has_column($pdo, 'events', 'job_id')) {
        $pdo->exec($lite ? 'ALTER TABLE events ADD COLUMN job_id INTEGER NULL'
                         : 'ALTER TABLE events ADD COLUMN job_id INT UNSIGNED NULL, ADD INDEX idx_job (job_id)');
    }
}
