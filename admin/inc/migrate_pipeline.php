<?php
/**
 * topper. admin — passo 21 da base de dados: Pipeline, funis e automações (§19)
 *
 *  · funis: os 5 passam a 6 (Adverts separa-se de Campanhas); VFX & Pós-produção e CGI / 3D
 *           mudam de nome; perguntas do X-Ray por funil; rondas de revisão incluídas por etapa.
 *  · etapas: Proposta e Aguardar feedback em todos os funis (papel fixo: os requisitos dessas
 *           etapas vêm dele); as etapas de produção da §19.5 nos funis base que ainda não as têm.
 *  · automações: gatilho, tipo de tarefa, responsável (comercial, execução, ambos, chefe de
 *           departamento, pessoa, por atribuir), prazo (dias úteis desde a entrada ou relativo a
 *           uma data do negócio), obrigatória (gate), checklist, repetição e documento pedido.
 *           As tarefas da §19.6 entram como valores iniciais, editáveis em Definições.
 *  · negócios: responsável de execução, datas de entrega e de rodagem, X-Ray, entrada na etapa.
 *  · tarefas: regra que a criou, etapa, obrigatória, documento pedido (fatura, pagamento, X-Ray).
 *  · orçamentos: Opção A (Pedido) e Opção B (Proposta de valor) em cada versão, com os campos da
 *           proposta (prazo, pagamento, revisões, direitos, validade).
 *  · propostas enviadas ao cliente (link com token, resposta, validação interna) e documentos de
 *           faturação submetidos (a topper. ainda não emite faturas aqui: §19.9).
 *
 * Tudo idempotente: corre de novo sem duplicar. Os valores iniciais entram uma vez
 * (settings.pipeline_seeded); depois disso são da equipa.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

/** As perguntas do X-Ray por funil (§19.2), uma por linha. */
const PIPE_XRAY_SEED = [
    'vfx'      => "Corte/EDL ou guião\nNº de shots e duração\nTipos de trabalho (cleanup, roto, comp, CG, grade, som)\nCâmara, resolução e pipeline de cor\nHandles\nDatas de turnover",
    'cgi'      => "CAD, fotos ou amostras do produto\nEstilo\nDuração e formatos\nPersonagem, líquidos ou simulação\nReutilização dos assets",
    'adverts'  => "Objetivo e KPI\nPúblico\nMensagem-chave\nCanais\nGuidelines da marca\nTalento, locais e música",
    'campanhas'=> "Pilares de conteúdo\nNº de peças por rede\nCalendário\nQuem publica\nRodagem existente reutilizável\nTempo de resposta",
    'ads'      => "Oferta\nPúblico\nPlataformas\nAnúncios atuais e resultados\nKPI (CPA, ROAS, hook rate)\nConceitos × variações\nSpecs\nQuem gere o media",
    'buddy'    => "Objetivos a 12 meses\nEquipa e ferramentas atuais\nCanais\nCapacidade mensal\nFluxo de aprovação\nRelatórios",
];

/** As etapas de produção (§19.5), com as rondas de revisão incluídas por defeito onde se aplicam. */
const PIPE_PROD_SEED = [
    'vfx'       => ['Turnover e breakdown', 'Look dev / shot de referência', 'Produção de shots', ['Revisões do cliente', 2], 'Finais e QC', 'Entrega'],
    'cgi'       => [['Styleframes', 2], ['Storyboard e animatic', 2], 'Modelação e lookdev', 'Animação', ['Render e composição', 1], 'Revisões', 'Entrega'],
    'adverts'   => ['Conceito e tratamento', 'Pré-produção (PPM)', 'Rodagem', ['Montagem offline', 2], 'Picture lock', ['Pós (cor, VFX, som, música)', 1], 'Versões e entrega'],
    'campanhas' => ['Estratégia e calendário', 'Guiões do lote', 'Gravação do lote', 'Edição do lote', ['Revisão', 1], 'Entrega e agendamento', 'Relatório'],
    'ads'       => ['Ângulos e ganchos', 'Guiões', 'Produção', 'Edição e variações', ['Revisão', 1], 'Exportação por plataforma', 'Lançamento e leitura'],
    'buddy'     => ['Onboarding', 'Configuração do agente', 'Ativo (otimizar e reportar)', 'Revisão trimestral e renovação'],
];

/**
 * As tarefas automáticas (§19.6), por etapa: «título|tipo|resp|prazo|marcas».
 *   resp:  C comercial · E execução · CE os dois · U por atribuir
 *   prazo: +N dias úteis desde a entrada · D±N dias da entrega · R±N dias da rodagem (R-15u = dias úteis)
 *          · M5 todos os meses, no dia 5 · vazio = sem prazo
 *   marcas: G obrigatória (gate) · inv fatura (pede o documento) · pay pagamento (pede a data) ·
 *          xray (fica feita ao validar o X-Ray) · noreply (sugere «Perdido» com «Sem resposta») ·
 *          daily / weekly (repete-se)
 */
const PIPE_RULES_COMMERCIAL = [
    'xray' => [
        'Chamada de descoberta (objetivo, público, orçamento, prazo, decisor)|call|C|+1|G',
        'Recolher brief, referências e materiais da marca · {cliente}|email|C|+2|',
        'Confirmar decisor, orçamento e data-alvo|task|C|+2|',
        'Validar viabilidade técnica e capacidade com a execução|meeting|E|+3|',
        'Preencher e validar o X-Ray · {ID}|document|C|+3|G,xray',
    ],
    'proposal' => [
        'Orçamento Opção A (linhas, IVA, extras, deslocações)|quote|C|+3|G',
        'Proposta de valor (Opção B)|quote|C|+3|',
        'Âmbito escrito: entregáveis, formatos, rondas de revisão incluídas e preço de ronda extra|document|C|+3|G',
        'Direitos de utilização propostos (meios, território, duração, orgânico/pago)|document|C|+3|G',
        'Cronograma com marcos de aprovação|document|E|+3|',
        'Enviar e apresentar a proposta numa chamada · {cliente}|meeting|C|+4|',
    ],
    'feedback' => [
        'Follow-up 1 da proposta · {cliente}|followup|C|+3|',
        'Follow-up 2 da proposta (lembrar a validade) · {cliente}|followup|C|+7|',
        'Sem resposta: decidir perder ou manter · {ID}|task|C|+15|noreply',
    ],
    'kickoff' => [
        'Contrato assinado (âmbito, rondas, alterações, direitos, pagamentos)|document|C|+2|G',
        'Emitir fatura de adjudicação · {ID}|quote|C|+1|G,inv',
        'Validar receção do pagamento de adjudicação · {ID}|task|C|+5|G,pay',
        'Reunião de passagem Comercial → Execução (brief, contrato, contactos, riscos)|meeting|CE|+3|G',
        'Reunião de arranque com o cliente (aprovadores e canal único de feedback)|meeting|E|+5|',
        'Criar pasta no Drive, canal e cronograma detalhado · {ID}|task|E|+3|',
    ],
];

/** B. Fecho: na última etapa de produção (antes de Concluído) e em Concluído. */
const PIPE_RULES_DELIVERY = [
    'Aprovação final do cliente por escrito|email|E|+2|G',
    'Enviar entregáveis finais com comprovativo de download|delivery|E|+1|G',
    'Documento de direitos e licenças (música, stock, talento: âmbito e validade)|document|C|+2|G',
    'Emitir fatura final · {ID}|quote|C|+1|G,inv',
];
const PIPE_RULES_DONE = [
    'Validar pagamento final · {ID}|task|C|+30|G,pay',
    'Arquivar projeto (masters, projetos, autorizações)|task|E|+10|',
    'Avaliar fornecedores · {ID}|task|E|+5|',
    'Pedir testemunho e autorização de portfólio · {cliente}|email|C|+5|',
    'Case study (após autorização) · {negócio}|document|C|+20|',
    'Follow-up de upsell · {cliente}|followup|C|+45|',
];

/** C. Produção por funil: etapa → regras. */
const PIPE_RULES_PROD = [
    'vfx' => [
        'Turnover e breakdown' => [
            'Receber turnover (EDL/XML, plates EXR, handles, corte de referência, LUT, specs)|task|E|+1|G',
            'Verificar lineup (frames, handles, timecode, formato)|review|E|+2|G',
            'Breakdown e orçamento por shot|document|E|+3|',
            'Teste de pipeline com um shot|render|E|+3|',
        ],
        'Look dev / shot de referência' => [
            'Produzir shot de referência|edit|E|+5|',
            'Aprovação do look por escrito|review|E|+7|G',
        ],
        'Produção de shots' => [
            'Tracker de shots (estado e versões) · {ID}|task|E||',
            'Dailies internos e QC por versão|review|E|+1|daily',
            'Status semanal ao cliente · {cliente}|email|E|+5|weekly',
        ],
        'Revisões do cliente' => [
            'Enviar versões com contador de rondas|review|E||',
            'Consolidar feedback numa lista única|document|E|+1|',
            'Fora do âmbito ou acima das rondas: pedido de alteração aprovado por escrito antes de executar|quote|C||G',
        ],
        'Finais e QC' => [
            'Render dos finais nas specs|render|E|D-5|',
            'QC técnico (frames, artefactos, espaço de cor, nomes)|review|E|D-3|G',
        ],
        'Entrega' => [
            'Entregar EXR/ProRes e documento de entrega · {cliente}|delivery|E|D0|',
        ],
    ],
    'cgi' => [
        'Styleframes' => [
            'Moodboard e referências|document|E|+2|',
            '2 a 3 styleframes|edit|E|+5|',
            'Aprovação do estilo por escrito|review|E|+7|G',
        ],
        'Storyboard e animatic' => [
            'Storyboard|document|E|+3|',
            'Animatic com timing e áudio guia|edit|E|+5|',
            'Aprovação do animatic (fecha câmara e duração)|review|E|+7|G',
        ],
        'Modelação e lookdev' => [
            'Receber CAD/ficheiros do produto · {cliente}|email|E|+1|',
            'Modelos e materiais|task|E||',
            'Turntables para aprovação|review|E||G',
        ],
        'Animação' => [
            'Blocking|edit|E||',
            'Revisão do blocking com o cliente|review|E||',
            'Polish|edit|E||',
        ],
        'Render e composição' => [
            'Teste de render e estimativa de tempo de farm|render|E|+1|',
            'Render final|render|E|D-5|',
            'Composição e cor|edit|E|D-3|',
        ],
        'Revisões' => [
            'Enviar versões com contador de rondas|review|E||',
            'Fora do âmbito ou acima das rondas: pedido de alteração aprovado por escrito antes de executar|quote|C||G',
        ],
        'Entrega' => [
            'QC técnico|review|E|D-1|G',
            'Entregar masters e versões · {cliente}|delivery|E|D0|',
        ],
    ],
    'adverts' => [
        'Conceito e tratamento' => [
            'Tratamento (conceito, look, referências)|document|E|+5|',
            'Guião|document|E|+5|',
            'Aprovação do tratamento e guião|review|E|+7|G',
        ],
        'Pré-produção (PPM)' => [
            'Casting com contratos e autorização de imagem por escrito (RGPD e art. 79.º do Código Civil; âmbito e duração)|document|E|R-5|G',
            'Licença de filmagem municipal (Lisboa Film Commission/CML; estradas e linhas de comboio ≥ 15 dias úteis antes)|document|E|R-15u|G',
            'Seguros (RC, equipamento, acidentes de trabalho)|document|E|R-5|G',
            'Autorizações dos locais|document|E|R-5|G',
            'Visita técnica ao local|visit|E|R-5|',
            'Reunião de PPM com o cliente (lista de planos, guarda-roupa, cronograma)|meeting|E|R-3|G',
            'Folha de serviço (call sheet)|document|E|R-1|',
        ],
        'Rodagem' => [
            'Rodagem · {negócio}|shoot|E|R0|',
            'Recolher autorizações assinadas no set (incl. figurantes)|document|E|R0|G',
            'Cópia de segurança dupla do material|task|E|R0|G',
        ],
        'Montagem offline' => [
            'Sincronizar e importar|edit|E|+1|',
            'Primeiro corte|edit|E|+5|',
            'Revisão do cliente|review|E||',
        ],
        'Picture lock' => [
            'Aprovação do lock por escrito (depois disto, mudanças = pedido de alteração)|review|E||G',
            'Turnover para a pós|task|E|+1|',
        ],
        'Pós (cor, VFX, som, música)' => [
            'Licença de música (library com licença para meios pagos, ou sync + master), antes da mistura|document|C||G',
            'Cor|edit|E||',
            'Mistura de som com loudness por plataforma|edit|E||',
            'VFX e grafismo|edit|E||',
        ],
        'Versões e entrega' => [
            'Cutdowns e formatos|render|E|D-3|',
            'QC final (legendas, ortografia, loudness, safe areas)|review|E|D-2|G',
            'Entregar · {cliente}|delivery|E|D0|',
        ],
    ],
    'campanhas' => [
        'Estratégia e calendário' => [
            'Workshop de pilares e formatos|meeting|E|+3|',
            'Calendário editorial aprovado (obrigatório no 1.º lote)|document|E|+5|G',
        ],
        'Guiões do lote' => [
            'Guiões e ganchos|document|E|+3|',
            'Aprovação dos guiões|review|E|R-3|G',
        ],
        'Gravação do lote' => [
            'Confirmar local, talento e autorizações de imagem válidas|document|E|R-2|G',
            'Gravação · {negócio}|shoot|E|R0|',
            'Cópia de segurança|task|E|R0|',
        ],
        'Edição do lote' => [
            'Edição com legendas e música licenciada|edit|E|+5|',
            'QC interno|review|E|+6|',
        ],
        'Revisão' => [
            'Enviar com contador de rondas|review|E||',
            'Aprovação final do lote · {cliente}|email|E||G',
        ],
        'Entrega e agendamento' => [
            'Exportar e entregar|delivery|E||',
            'Agendar publicação (se no âmbito)|task|E||',
        ],
        'Relatório' => [
            'Métricas e aprendizagens do lote|document|E|+7|',
            'Faturar o lote (se o contrato for por lote) · {ID}|quote|C||G,inv',
        ],
    ],
    'ads' => [
        'Ângulos e ganchos' => [
            'Pesquisa (anúncios da concorrência, reviews, dores do público)|task|E|+2|',
            'Matriz ângulos × ganchos aprovada|document|E|+3|G',
        ],
        'Guiões' => [
            'Guiões por ângulo com afirmações confirmadas pelo cliente (publicidade)|document|E|+3|G',
        ],
        'Produção' => [
            'Gravação/UGC com autorizações que cobrem meios pagos|shoot|E|R0|G',
        ],
        'Edição e variações' => [
            'Master por ângulo|edit|E||',
            'Variações de gancho, CTA e duração com convenção de nomes para testes|edit|E||',
        ],
        'Revisão' => [
            'Rondas de revisão|review|E||',
            'Aprovação · {cliente}|email|E||G',
        ],
        'Exportação por plataforma' => [
            'Formatos 9:16, 4:5, 1:1, 16:9, safe zones e legendas|render|E|D-1|',
            'QC|review|E||G',
        ],
        'Lançamento e leitura' => [
            'Confirmar upload/lançamento com quem gere o media|message|E|D0|',
            'Leitura de resultados (CTR, hook rate, CPA) e recomendações|document|E|+7|',
        ],
    ],
    'buddy' => [
        'Onboarding' => [
            'Acessos às contas de anúncios, redes e analytics do cliente · {cliente}|email|E|+2|G',
            'Reunião de objetivos e KPI (CPA, ROAS, orçamento de media)|meeting|E|+3|',
            'Faturação mensal recorrente configurada · {ID}|quote|C|+2|G',
            'Acordo de tratamento de dados (RGPD) para acesso às contas|document|C|+2|G',
        ],
        'Configuração do agente' => [
            'Ligar fontes de dados e definir regras e limites do agente|task|E|+5|',
            'Validar configuração com o cliente|review|E|+7|G',
        ],
        'Ativo (otimizar e reportar)' => [
            'Rever recomendações e otimizações do agente|review|E|+5|weekly',
            'Relatório mensal ao cliente · {cliente}|document|E|M5|',
            'Fatura mensal (submeter o documento) · {ID}|quote|C|M1|G,inv',
            'Oportunidades de cross-selling (ex.: Campanhas) · {cliente}|task|C|M15|',
        ],
        'Revisão trimestral e renovação' => [
            'Reunião de resultados · {cliente}|meeting|CE|+5|',
            'Proposta de renovação/upsell · {cliente}|quote|C|+5|',
            'Contrato renovado ou terminado|document|C||G',
        ],
    ],
];

/** Condições de pagamento por defeito (§19.3), em Definições. */
const PIPE_PAY_DEFAULTS = [
    'pay_terms_default'   => '50% na adjudicação · 50% na entrega',
    'pay_terms_large'     => '40% na adjudicação · 40% no início da pós-produção ou da rodagem · 20% na entrega',
    'pay_terms_threshold' => '10000',
    'pay_terms_buddy'     => 'Mensal, pago adiantado · mínimo de 3 meses',
];

function migrate_pipeline_flow(PDO $pdo): void {
    $lite = db_is_sqlite($pdo);

    // ── funis e etapas ──────────────────────────────────────
    db_add_column($pdo, 'funnels', 'xray_kind',   "VARCHAR(60) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'funnels', 'xray_fields', 'TEXT NULL', 'TEXT NULL');
    db_add_column($pdo, 'funnel_stages', 'role',            "VARCHAR(12) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'funnel_stages', 'gate_on',         'TINYINT(1) NOT NULL DEFAULT 1',   'INTEGER NOT NULL DEFAULT 1');
    db_add_column($pdo, 'funnel_stages', 'rounds_included', 'SMALLINT NULL',                   'INTEGER NULL');

    // ── automações (a estrutura existia desde o passo 11, vazia) ──
    db_add_column($pdo, 'stage_automations', 'trig',        "VARCHAR(10) NOT NULL DEFAULT 'enter'", "TEXT NOT NULL DEFAULT 'enter'");
    db_add_column($pdo, 'stage_automations', 'kind',        "VARCHAR(16) NOT NULL DEFAULT 'task'",  "TEXT NOT NULL DEFAULT 'task'");
    db_add_column($pdo, 'stage_automations', 'assign_ref',  'INT UNSIGNED NULL',                   'INTEGER NULL');
    db_add_column($pdo, 'stage_automations', 'due_mode',    "VARCHAR(10) NOT NULL DEFAULT 'entry'", "TEXT NOT NULL DEFAULT 'entry'");
    db_add_column($pdo, 'stage_automations', 'due_unit',    "VARCHAR(2) NOT NULL DEFAULT 'wd'",     "TEXT NOT NULL DEFAULT 'wd'");
    db_add_column($pdo, 'stage_automations', 'is_gate',     'TINYINT(1) NOT NULL DEFAULT 0',       'INTEGER NOT NULL DEFAULT 0');
    db_add_column($pdo, 'stage_automations', 'checklist',   'TEXT NULL',                           'TEXT NULL');
    db_add_column($pdo, 'stage_automations', 'repeat_rule', "VARCHAR(8) NOT NULL DEFAULT ''",       "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'stage_automations', 'doc_kind',    "VARCHAR(8) NOT NULL DEFAULT ''",       "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'stage_automations', 'updated_at',  'DATETIME NULL',                       'TEXT NULL');
    db_add_column($pdo, 'stage_automations', 'updated_by',  'INT UNSIGNED NULL',                   'INTEGER NULL');

    // ── negócios ────────────────────────────────────────────
    db_add_column($pdo, 'deals', 'exec_owner_id',     'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'deals', 'delivery_date',     'DATE NULL',         'TEXT NULL');
    db_add_column($pdo, 'deals', 'shoot_date',        'DATE NULL',         'TEXT NULL');
    db_add_column($pdo, 'deals', 'xray',              'MEDIUMTEXT NULL',   'TEXT NULL');
    db_add_column($pdo, 'deals', 'xray_validated_at', 'DATETIME NULL',     'TEXT NULL');
    db_add_column($pdo, 'deals', 'xray_validated_by', 'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'deals', 'stage_entered_at',  'DATETIME NULL',     'TEXT NULL');

    // ── tarefas ─────────────────────────────────────────────
    db_add_column($pdo, 'tasks', 'auto_rule_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'tasks', 'stage_id',     'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'tasks', 'is_gate',      'TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column($pdo, 'tasks', 'doc_kind',     "VARCHAR(8) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    if (!$lite) {
        $idx = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'tasks' AND index_name = 'idx_task_rule'")->fetchColumn();
        if (!(int)$idx) $pdo->exec('ALTER TABLE tasks ADD INDEX idx_task_rule (deal_id, auto_rule_id)');
    }

    // ── orçamento: Opção A e Opção B, campos da proposta ────
    db_add_column($pdo, 'budget_lines', 'opt', "CHAR(1) NOT NULL DEFAULT 'A'", "TEXT NOT NULL DEFAULT 'A'");
    foreach ([
        ['scope_b',        'TEXT NULL',                     'TEXT NULL'],
        ['margin_b',       'DECIMAL(5,2) NULL',             'REAL NULL'],
        ['price_b',        'DECIMAL(12,2) NOT NULL DEFAULT 0', 'REAL NOT NULL DEFAULT 0'],
        ['base_b',         'DECIMAL(12,2) NOT NULL DEFAULT 0', 'REAL NOT NULL DEFAULT 0'],
        ['profit_month_b', 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'REAL NOT NULL DEFAULT 0'],
        ['term_text',      "VARCHAR(255) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''"],
        ['payment_terms',  'TEXT NULL',                     'TEXT NULL'],
        ['revisions_text', 'TEXT NULL',                     'TEXT NULL'],
        ['rights_text',    'TEXT NULL',                     'TEXT NULL'],
        ['valid_until',    'DATE NULL',                     'TEXT NULL'],
        ['accepted_opt',   "CHAR(1) NOT NULL DEFAULT ''",   "TEXT NOT NULL DEFAULT ''"],
    ] as [$col, $my, $sq]) db_add_column($pdo, 'budget_versions', $col, $my, $sq);

    // ── propostas enviadas e respostas do cliente (§19.4) ───
    db_create($pdo, 'proposals', "
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id       INT UNSIGNED NOT NULL,
        version_id    INT UNSIGNED NOT NULL,
        token_hash    CHAR(64)     NOT NULL,
        content_hash  CHAR(64)     NOT NULL,
        has_b         TINYINT(1)   NOT NULL DEFAULT 0,
        sent_to       VARCHAR(190) NOT NULL DEFAULT '',
        sent_at       DATETIME     NOT NULL,
        sent_by       INT UNSIGNED NULL,
        expires_on    DATE         NOT NULL,
        status        VARCHAR(10)  NOT NULL DEFAULT 'sent',
        choice        CHAR(1)      NOT NULL DEFAULT '',
        decision      VARCHAR(8)   NOT NULL DEFAULT '',
        reason        TEXT NULL,
        signer_name   VARCHAR(160) NOT NULL DEFAULT '',
        signer_ip     VARCHAR(45)  NOT NULL DEFAULT '',
        signer_ua     VARCHAR(255) NOT NULL DEFAULT '',
        answered_at   DATETIME NULL,
        answers       SMALLINT     NOT NULL DEFAULT 0,
        views         INT          NOT NULL DEFAULT 0,
        last_view_at  DATETIME NULL,
        validated_at  DATETIME NULL,
        validated_by  INT UNSIGNED NULL,
        contact_at    DATETIME NULL,
        UNIQUE KEY uq_prop_token (token_hash),
        INDEX idx_prop_deal (deal_id, sent_at)");

    // ── faturas e pagamentos submetidos (§19.6 E) ───────────
    db_create($pdo, 'deal_billing', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id      INT UNSIGNED NOT NULL,
        task_id      INT UNSIGNED NULL,
        kind         VARCHAR(8)   NOT NULL,
        number       VARCHAR(60)  NOT NULL DEFAULT '',
        doc_date     DATE NULL,
        net          DECIMAL(12,2) NOT NULL DEFAULT 0,
        vat          DECIMAL(12,2) NOT NULL DEFAULT 0,
        total        DECIMAL(12,2) NOT NULL DEFAULT 0,
        file_key     VARCHAR(400) NOT NULL DEFAULT '',
        file_name    VARCHAR(200) NOT NULL DEFAULT '',
        note         VARCHAR(255) NOT NULL DEFAULT '',
        created_by   INT UNSIGNED NULL,
        created_at   DATETIME     NOT NULL,
        INDEX idx_bill_deal (deal_id, kind)");

    // ── rondas de revisão contadas por negócio e etapa (§19.6 D) ──
    db_create($pdo, 'deal_rounds', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id    INT UNSIGNED NOT NULL,
        stage_id   INT UNSIGNED NOT NULL,
        note       VARCHAR(255) NOT NULL DEFAULT '',
        user_id    INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL,
        INDEX idx_rounds (deal_id, stage_id)");

    // O segredo que assina os formulários da página pública da proposta (nunca sai do servidor).
    $has = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE skey = ?');
    $set = $pdo->prepare($lite ? 'INSERT OR IGNORE INTO settings (skey, svalue) VALUES (?, ?)' : 'INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)');
    $set->execute(['proposal_secret', bin2hex(random_bytes(32))]);
    foreach (PIPE_PAY_DEFAULTS as $k => $v) $set->execute([$k, $v]);

    $has->execute(['pipeline_seeded']);
    if ((int)$has->fetchColumn() > 0) return;
    migrate_pipeline_seed($pdo);
    $set->execute(['pipeline_seeded', date('Y-m-d H:i:s')]);
}

/** O funil base pelo nome (o atual ou o antigo). */
function migrate_pipeline_find(array $funnels, array $names): ?array {
    foreach ($funnels as $f) {
        if (in_array(mb_strtolower(trim($f['name'])), $names, true)) return $f;
    }
    return null;
}

function migrate_pipeline_seed(PDO $pdo): void {
    $funnels = $pdo->query('SELECT * FROM funnels ORDER BY position, id')->fetchAll();
    $base = [
        'vfx'       => [['vfx / 3d', 'vfx', 'vfx & pós-produção', 'vfx e pós-produção'], 'VFX & Pós-produção'],
        'cgi'       => [['cgi', 'cgi / 3d', 'cgi/3d'], 'CGI / 3D'],
        'adverts'   => [['adverts'], 'Adverts'],
        'campanhas' => [['campanhas'], 'Campanhas'],
        'ads'       => [['ads'], 'Ads'],
        'buddy'     => [['marketing buddy'], 'Marketing Buddy'],
    ];
    $byKind = [];
    $pos = 1;
    foreach ($base as $kind => [$names, $label]) {
        $f = migrate_pipeline_find($funnels, $names);
        if ($f === null) {
            $pdo->prepare('INSERT INTO funnels (name, is_base, made_from, position) VALUES (?, 1, \'\', ?)')->execute([$label, $pos]);
            $id = (int)$pdo->lastInsertId();
            $s = $pdo->prepare('INSERT INTO funnel_stages (funnel_id, name, kind, position) VALUES (?,?,?,?)');
            $s->execute([$id, 'X-Ray', 'xray', 10]);
            $s->execute([$id, 'Kickoff', 'kickoff', 50]);
            $s->execute([$id, 'Concluído', 'done', 90]);
        } else {
            $id = (int)$f['id'];
            if ((int)$f['is_base'] === 1) $pdo->prepare('UPDATE funnels SET name = ? WHERE id = ?')->execute([$label, $id]);
        }
        $pdo->prepare('UPDATE funnels SET position = ?, xray_kind = ?, xray_fields = COALESCE(xray_fields, ?) WHERE id = ?')
            ->execute([$pos, $kind, PIPE_XRAY_SEED[$kind], $id]);
        $byKind[$kind] = $id;
        $pos++;
    }
    // Os outros (combinados, criados pela equipa) vêm depois, pela ordem que tinham.
    foreach ($pdo->query('SELECT id FROM funnels WHERE id NOT IN (' . implode(',', $byKind) . ') ORDER BY position, id')->fetchAll(PDO::FETCH_COLUMN) as $fid) {
        $pdo->prepare('UPDATE funnels SET position = ? WHERE id = ?')->execute([$pos++, $fid]);
    }

    $stages = fn(int $fid) => $pdo->query('SELECT * FROM funnel_stages WHERE funnel_id = ' . $fid . ' ORDER BY position, id')->fetchAll();
    $insStage = $pdo->prepare('INSERT INTO funnel_stages (funnel_id, name, kind, position, role, rounds_included) VALUES (?,?,?,?,?,?)');

    // Proposta e Aguardar feedback em todos os funis (fase comercial igual em todos, §19.1).
    foreach ($pdo->query('SELECT id FROM funnels ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $fid) {
        $fid = (int)$fid;
        $all = $stages($fid);
        foreach (['proposal' => ['Proposta', ['proposta']], 'feedback' => ['Aguardar feedback', ['aguardar feedback', 'feedback']]] as $role => [$label, $alias]) {
            if (array_filter($all, fn($s) => $s['role'] === $role)) continue;
            $same = array_values(array_filter($all, fn($s) => $s['kind'] === 'commercial' && in_array(mb_strtolower(trim($s['name'])), $alias, true)));
            if ($same) {
                $pdo->prepare('UPDATE funnel_stages SET role = ? WHERE id = ?')->execute([$role, $same[0]['id']]);
                continue;
            }
            // As comerciais que já existam passam para depois destas duas.
            $pdo->prepare("UPDATE funnel_stages SET position = position + 2 WHERE funnel_id = ? AND kind = 'commercial' AND role = ''")->execute([$fid]);
            $insStage->execute([$fid, $label, 'commercial', $role === 'proposal' ? 20 : 21, $role, null]);
        }
    }

    // As etapas de produção da §19.5 nos funis base que ainda não têm nenhuma.
    foreach (PIPE_PROD_SEED as $kind => $list) {
        $fid = $byKind[$kind];
        if (array_filter($stages($fid), fn($s) => $s['kind'] === 'production')) continue;
        foreach ($list as $i => $st) {
            [$name, $rounds] = is_array($st) ? $st : [$st, null];
            $insStage->execute([$fid, $name, 'production', 60 + $i, '', $rounds]);
        }
    }

    // ── as tarefas automáticas ──────────────────────────────
    $ins = $pdo->prepare('INSERT INTO stage_automations (stage_id, title, description, assign_to, due_days, position, is_active, trig, kind,
                                                         due_mode, due_unit, is_gate, repeat_rule, doc_kind, updated_at)
                          VALUES (?,?,\'\',?,?,?,1,?,?,?,?,?,?,?,?)');
    $add = function (int $stageId, array $rules) use ($ins, $pdo) {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM stage_automations WHERE stage_id = ' . $stageId)->fetchColumn();
        foreach ($rules as $r) {
            $p = migrate_pipeline_rule($r);
            $ins->execute([$stageId, $p['title'], $p['assign_to'], $p['due_days'], ++$n * 10, $p['trig'], $p['kind'], $p['due_mode'], $p['due_unit'],
                           $p['is_gate'], $p['repeat_rule'], $p['doc_kind'], date('Y-m-d H:i:s')]);
        }
    };
    foreach ($pdo->query('SELECT id FROM funnels ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $fid) {
        $all = $stages((int)$fid);
        foreach ($all as $s) {
            $key = $s['role'] !== '' ? $s['role'] : $s['kind'];
            if (isset(PIPE_RULES_COMMERCIAL[$key]) && in_array($s['kind'], ['xray', 'commercial', 'kickoff'], true)) $add((int)$s['id'], PIPE_RULES_COMMERCIAL[$key]);
            if ($s['kind'] === 'done') $add((int)$s['id'], PIPE_RULES_DONE);
        }
    }
    foreach (PIPE_RULES_PROD as $kind => $map) {
        $prod = array_values(array_filter($stages($byKind[$kind]), fn($s) => $s['kind'] === 'production'));
        foreach ($prod as $s) {
            if (isset($map[$s['name']])) $add((int)$s['id'], $map[$s['name']]);
        }
        // B. Fecho: na última etapa de produção (o Marketing Buddy é uma avença, não tem entrega).
        if ($kind !== 'buddy' && $prod) $add((int)end($prod)['id'], PIPE_RULES_DELIVERY);
    }
}

/** Uma regra da semente («título|tipo|resp|prazo|marcas») em colunas. */
function migrate_pipeline_rule(string $r): array {
    [$title, $kind, $resp, $due, $flags] = array_pad(explode('|', $r), 5, '');
    $flags = array_filter(explode(',', $flags));
    $out = ['title' => $title, 'kind' => $kind, 'trig' => 'enter', 'due_mode' => 'none', 'due_unit' => 'wd', 'due_days' => null,
            'assign_to' => ['C' => 'commercial', 'E' => 'execution', 'CE' => 'both', 'U' => 'none'][$resp] ?? 'commercial',
            'is_gate' => in_array('G', $flags, true) ? 1 : 0, 'repeat_rule' => '', 'doc_kind' => ''];
    foreach (['inv' => 'invoice', 'pay' => 'payment', 'xray' => 'xray', 'noreply' => 'noreply'] as $f => $dk) if (in_array($f, $flags, true)) $out['doc_kind'] = $dk;
    foreach (['daily', 'weekly'] as $rep) if (in_array($rep, $flags, true)) $out['repeat_rule'] = $rep;
    if (preg_match('/^\+(\d+)$/', $due, $m)) {
        $out['due_mode'] = 'entry'; $out['due_days'] = (int)$m[1];
    } elseif (preg_match('/^([DR])([+-]?\d+)(u?)$/', $due, $m)) {
        $out['due_mode'] = $m[1] === 'D' ? 'delivery' : 'shoot'; $out['due_days'] = (int)$m[2]; $out['due_unit'] = $m[3] === 'u' ? 'wd' : 'd';
    } elseif (preg_match('/^M(\d+)$/', $due, $m)) {
        $out['trig'] = 'monthly'; $out['due_mode'] = 'none'; $out['due_days'] = (int)$m[1];
    }
    return $out;
}
