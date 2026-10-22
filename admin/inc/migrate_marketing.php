<?php
/**
 * topper. admin — passo 18 da base de dados: aba Marketing (§16)
 *
 * Pilares de conteúdo (editáveis em Definições), peças (uma ideia que pode
 * avançar para Produção), votos da equipa, versões por rede, publicações
 * (uma por rede, com o link com rastreio e as métricas), cliques nesse link,
 * dias de gravação, histórico, registo de uso da IA e resumos mensais.
 * Nos negócios: «Pode ser divulgado?» e a publicação de onde a lead veio.
 * A fase 2 (APIs das redes) fica só preparada: mkt_accounts e as colunas
 * external_id / sync_source das publicações. Como os outros passos,
 * verifica antes de mudar e pode correr as vezes que forem.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_create, db_add_column

/** Os seis pilares de partida (§16.2). [nome, curto, cor, tipo, descrição] */
const MKT_PILLARS_SEED = [
    ['Portfólio e breakdowns', 'Breakdown', 'blue', '', 'Trabalhos feitos e como foram feitos: antes/depois, camadas, processo.'],
    ['Bastidores', 'Bastidores', 'orange', '', 'O estúdio, as rodagens, a equipa a trabalhar.'],
    ['Educação', 'Educação', 'green', '', 'Dicas de VFX, 3D e pós-produção.'],
    ['Casos de cliente', 'Caso', 'sky', '', 'O problema do cliente, a solução e o resultado.'],
    ['Serviços', 'Serviços', 'coral', '', 'Marketing Buddy, VFX, CGI: o que fazemos e para quem.'],
    ['Employer branding', 'Employer', 'yellow', 'employer', 'Mostrar que é excelente e exclusivo trabalhar na topper.: pessoas, projetos de topo, ferramentas e condições, crescimento, cultura, «um dia na topper.». Quem vê deve querer trabalhar aqui.'],
];

function migrate_marketing(PDO $pdo): void {
    db_create($pdo, 'mkt_pillars', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(80)  NOT NULL,
        short_name  VARCHAR(24)  NOT NULL DEFAULT '',
        color       VARCHAR(12)  NOT NULL DEFAULT 'neutral',
        kind        VARCHAR(12)  NOT NULL DEFAULT '',
        description VARCHAR(500) NOT NULL DEFAULT '',
        position    INT          NOT NULL DEFAULT 0,
        is_active   TINYINT(1)   NOT NULL DEFAULT 1");
    if ((int)$pdo->query('SELECT COUNT(*) FROM mkt_pillars')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO mkt_pillars (name, short_name, color, kind, description, position) VALUES (?,?,?,?,?,?)');
        foreach (MKT_PILLARS_SEED as $i => $p) $ins->execute([$p[0], $p[1], $p[2], $p[3], $p[4], $i + 1]);
    }

    // Uma peça começa como ideia (status nova · guardada · descartada) e, ao avançar,
    // entra em Produção (status producao) numa das etapas do kanban (stage).
    db_create($pdo, 'mkt_pieces', "
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title         VARCHAR(200) NOT NULL,
        idea          TEXT         NULL,
        pillar_id     INT UNSIGNED NULL,
        format        VARCHAR(12)  NOT NULL DEFAULT '',
        networks      VARCHAR(16)  NOT NULL DEFAULT '',
        template      VARCHAR(16)  NOT NULL DEFAULT '',
        status        VARCHAR(12)  NOT NULL DEFAULT 'nova',
        stage         VARCHAR(12)  NULL,
        source        VARCHAR(12)  NOT NULL DEFAULT 'manual',
        author_id     INT UNSIGNED NULL,
        owner_id      INT UNSIGNED NULL,
        due_date      DATE         NULL,
        deal_id       INT UNSIGNED NULL,
        parent_id     INT UNSIGNED NULL,
        shoot_id      INT UNSIGNED NULL,
        checklist     TEXT         NULL,
        script        TEXT         NULL,
        drive_folder  VARCHAR(255) NULL,
        link_target   VARCHAR(255) NULL,
        review_state  VARCHAR(10)  NULL,
        review_note   TEXT         NULL,
        reviewed_by   INT UNSIGNED NULL,
        reviewed_at   DATETIME     NULL,
        rev           INT          NOT NULL DEFAULT 0,
        advanced_at   DATETIME     NULL,
        published_at  DATETIME     NULL,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME     NULL,
        INDEX idx_mp_status (status, stage),
        INDEX idx_mp_deal (deal_id)");

    db_create($pdo, 'mkt_votes', "
        piece_id   INT UNSIGNED NOT NULL,
        user_id    INT UNSIGNED NOT NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (piece_id, user_id)");

    // A versão de cada rede: gancho, texto (legenda, post ou thread), hashtags e CTA.
    db_create($pdo, 'mkt_versions', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        piece_id   INT UNSIGNED NOT NULL,
        network    VARCHAR(4)   NOT NULL,
        hook       VARCHAR(300) NOT NULL DEFAULT '',
        body       TEXT         NULL,
        hashtags   VARCHAR(500) NOT NULL DEFAULT '',
        cta        VARCHAR(300) NOT NULL DEFAULT '',
        updated_by INT UNSIGNED NULL,
        updated_at DATETIME     NULL,
        UNIQUE KEY uq_mv (piece_id, network)");

    // Uma publicação por rede: data agendada, publicada, link, código do link com rastreio
    // e métricas (à mão na fase 1; external_id / sync_source para as APIs da fase 2).
    db_create($pdo, 'mkt_posts', "
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        piece_id        INT UNSIGNED NOT NULL,
        network         VARCHAR(4)   NOT NULL,
        code            VARCHAR(12)  NOT NULL,
        scheduled_at    DATETIME     NULL,
        published_at    DATETIME     NULL,
        url             VARCHAR(500) NOT NULL DEFAULT '',
        publisher_id    INT UNSIGNED NULL,
        task_id         INT UNSIGNED NULL,
        metrics_task_id INT UNSIGNED NULL,
        reach           INT          NULL,
        interactions    INT          NULL,
        saves           INT          NULL,
        shares          INT          NULL,
        clicks          INT          NULL,
        metrics_at      DATETIME     NULL,
        metrics_by      INT UNSIGNED NULL,
        external_id     VARCHAR(100) NULL,
        sync_source     VARCHAR(8)   NOT NULL DEFAULT 'manual',
        synced_at       DATETIME     NULL,
        created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_mpost (piece_id, network),
        UNIQUE KEY uq_mcode (code),
        INDEX idx_mpost_when (scheduled_at)");

    db_create($pdo, 'mkt_clicks', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id    INT UNSIGNED NOT NULL,
        ip_hash    CHAR(16)     NOT NULL DEFAULT '',
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mclick (post_id, created_at)");

    db_create($pdo, 'mkt_shoots', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        day        DATE         NOT NULL,
        title      VARCHAR(160) NOT NULL DEFAULT '',
        place      VARCHAR(160) NOT NULL DEFAULT '',
        notes      TEXT         NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP");

    db_create($pdo, 'mkt_log', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        piece_id   INT UNSIGNED NOT NULL,
        event      VARCHAR(16)  NOT NULL,
        note       VARCHAR(255) NOT NULL DEFAULT '',
        user_id    INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mlog (piece_id, created_at)");

    // Registo de uso da IA do Estúdio: quem, em que peça, que ação, quanto custou e o que propôs.
    // O gasto conta também em ai_usage (purpose «mkt»), com o mesmo limite mensal do assistente.
    db_create($pdo, 'mkt_ai_log', "
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id       INT UNSIGNED NULL,
        piece_id      INT UNSIGNED NULL,
        action        VARCHAR(16)  NOT NULL,
        network       VARCHAR(4)   NOT NULL DEFAULT '',
        ok            TINYINT(1)   NOT NULL DEFAULT 1,
        input_tokens  INT          NOT NULL DEFAULT 0,
        output_tokens INT          NOT NULL DEFAULT 0,
        cost_usd      DECIMAL(12,6) NOT NULL DEFAULT 0,
        payload       MEDIUMTEXT   NULL,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mai (created_at)");

    db_create($pdo, 'mkt_summaries', "
        month      CHAR(7)      NOT NULL PRIMARY KEY,
        body       TEXT         NOT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP");

    // Fase 2 (§16.7): as contas ligadas às APIs das redes. Por agora só a estrutura —
    // as chaves, quando existirem, ficam num ficheiro de configuração no servidor.
    db_create($pdo, 'mkt_accounts', "
        network      VARCHAR(4)   NOT NULL PRIMARY KEY,
        handle       VARCHAR(120) NOT NULL DEFAULT '',
        api_status   VARCHAR(12)  NOT NULL DEFAULT 'off',
        last_sync_at DATETIME     NULL");

    // «Pode ser divulgado?» (yes · no · from + data) e a publicação de onde a lead veio.
    db_add_column($pdo, 'deals', 'disclose',      'VARCHAR(8) NULL',   'TEXT NULL');
    db_add_column($pdo, 'deals', 'disclose_from', 'DATE NULL',         'TEXT NULL');
    db_add_column($pdo, 'deals', 'mkt_post_id',   'INT UNSIGNED NULL', 'INTEGER NULL');
    // A origem da lead ganha «Redes sociais» (link com rastreio) e «Talento» (employer branding).
    if (!db_is_sqlite($pdo)) {
        $type = (string)($pdo->query("SHOW COLUMNS FROM deals LIKE 'origin'")->fetch()['Type'] ?? '');
        if (stripos($type, 'enum(') === 0 && stripos($type, "'talento'") === false) {
            $pdo->exec("ALTER TABLE deals MODIFY origin ENUM('manual','site','anuncio','rede','talento') NOT NULL DEFAULT 'manual'");
        }
    }
    db_add_column($pdo, 'tasks', 'mkt_piece_id',  'INT UNSIGNED NULL', 'INTEGER NULL');

    mkt_settings_text($pdo);

    // Cadência alvo por semana (§16.6) e a voz da topper. (Centro de Conhecimento).
    $defaults = ['mkt_target_ig_min' => '2', 'mkt_target_ig_max' => '3', 'mkt_target_li' => '5', 'mkt_target_x' => '10',
                 'mkt_voice' => MKT_VOICE_SEED];
    $has = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE skey = ?');
    $ins = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)');
    foreach ($defaults as $k => $v) {
        $has->execute([$k]);
        if (!(int)$has->fetchColumn()) $ins->execute([$k, $v]);
    }
}

/**
 * Algumas bases têm settings.svalue como VARCHAR(255): a voz da topper. (e as condições da
 * proposta, até 4000) não cabem e o MySQL sem modo estrito corta-as sem erro. Passa a TEXT e,
 * se a voz ficou cortada no texto de partida, repõe-na inteira. Pode correr as vezes que forem.
 */
function mkt_settings_text(PDO $pdo): void {
    if (db_is_sqlite($pdo)) return;
    $type = strtolower((string)($pdo->query("SHOW COLUMNS FROM settings LIKE 'svalue'")->fetch()['Type'] ?? ''));
    if (str_starts_with($type, 'varchar')) $pdo->exec('ALTER TABLE settings MODIFY svalue TEXT NOT NULL');
    $st = $pdo->prepare("SELECT svalue FROM settings WHERE skey = 'mkt_voice'");
    $st->execute();
    $v = $st->fetchColumn();
    if (is_string($v) && $v !== '' && mb_strlen($v) < mb_strlen(MKT_VOICE_SEED) && str_starts_with(MKT_VOICE_SEED, $v)) {
        $pdo->prepare("UPDATE settings SET svalue = ? WHERE skey = 'mkt_voice'")->execute([MKT_VOICE_SEED]);
    }
}

const MKT_VOICE_SEED = "Quem somos: a topper. é uma boutique de 3D, VFX e pós-produção em Lisboa que trabalha para marcas e produtoras de todo o mundo.\n"
    . "Tom: confiante sem ser arrogante, próximo, claro. Frases curtas. Mostramos o trabalho em vez de o elogiar.\n"
    . "Falamos de: craft, processo, detalhe técnico explicado de forma simples, a equipa e o que se aprende em cada projeto.\n"
    . "Evitar: jargão vazio («sinergias», «soluções 360»), exageros, emojis em excesso (no máximo 1–2 no Instagram e no X; nenhum no LinkedIn), hashtags a mais.\n"
    . "Assinatura: escrever sempre «topper.» em minúsculas com ponto.\n"
    . "Idioma: português de Portugal por defeito; inglês quando a peça for para o público internacional.";
