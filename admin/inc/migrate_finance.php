<?php
/**
 * topper. admin — passo 30 da base de dados: Financeiro (§28)
 *
 *  · fin_params / fin_param_log: todas as taxas e limites (SS, IRS, IVA, IRC, viaturas, prazos, runway),
 *    cada um com a fonte legal, o ano e a data da última verificação. Os cálculos só leem daqui.
 *  · fin_irs: as tabelas de retenção na fonte (Despacho n.º 233-A/2026, continente, tabelas I a VII),
 *    guardadas como dados (escalão, taxa, parcela a abater — fixa ou com a fórmula —, parcela por dependente).
 *  · fin_docs: todo o documento ou movimento (fatura emitida ou recebida, recibo, nota de crédito, despesa,
 *    salário, imposto) com sentido, âmbito (empresa ou negócio), contraparte, valores, datas, ficheiros e estado.
 *  · fin_payments (pagamentos, também parciais), fin_fixed (custos fixos), fin_accounts / fin_bank_tx /
 *    fin_imports (tesouraria e extratos), fin_employees / fin_payroll (salários), fin_scenarios (simulador),
 *    fin_mail (os documentos que chegam às caixas de email), fin_done (obrigações fiscais já lançadas como tarefa).
 *  · Os custos da empresa que viviam em `costs` (sem negócio) passam para fin_docs, sem apagar nada
 *    (cada linha fica com a origem, legacy_cost_id, e não se repete). Sem a passagem da versão 10 feita,
 *    também os de company_costs.
 *
 * Idempotente: corre de novo sem duplicar.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_create, db_add_column

function migrate_finance(PDO $pdo): void {
    db_create($pdo, 'fin_params', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        pkey        VARCHAR(60)  NOT NULL,
        grp         VARCHAR(40)  NOT NULL DEFAULT '',
        label       VARCHAR(190) NOT NULL DEFAULT '',
        value       VARCHAR(500) NOT NULL DEFAULT '',
        unit        VARCHAR(12)  NOT NULL DEFAULT '',
        source      VARCHAR(255) NOT NULL DEFAULT '',
        year        SMALLINT     NULL,
        verified_on DATE         NULL,
        admin_only  TINYINT(1)   NOT NULL DEFAULT 0,
        position    INT          NOT NULL DEFAULT 0,
        updated_by  INT UNSIGNED NULL,
        updated_at  DATETIME     NULL,
        UNIQUE KEY uq_fin_param (pkey)");
    db_create($pdo, 'fin_param_log', "
        id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        pkey      VARCHAR(60)  NOT NULL,
        old_value VARCHAR(500) NOT NULL DEFAULT '',
        new_value VARCHAR(500) NOT NULL DEFAULT '',
        what      VARCHAR(30)  NOT NULL DEFAULT 'value',
        user_id   INT UNSIGNED NULL,
        at        DATETIME     NOT NULL,
        INDEX idx_fpl (pkey, at)");
    db_create($pdo, 'fin_irs', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        year        SMALLINT     NOT NULL,
        region      VARCHAR(10)  NOT NULL DEFAULT 'continente',
        tbl         VARCHAR(4)   NOT NULL,
        upto        DECIMAL(12,2) NULL,
        rate        DECIMAL(7,3) NOT NULL DEFAULT 0,
        deduct      DECIMAL(12,2) NOT NULL DEFAULT 0,
        deduct_k    DECIMAL(7,3) NULL,
        deduct_base DECIMAL(12,2) NULL,
        dep_deduct  DECIMAL(10,2) NOT NULL DEFAULT 0,
        position    INT          NOT NULL DEFAULT 0,
        source      VARCHAR(190) NOT NULL DEFAULT '',
        INDEX idx_firs (year, region, tbl, position)");
    db_create($pdo, 'fin_docs', "
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        direction       VARCHAR(3)   NOT NULL,
        doc_type        VARCHAR(12)  NOT NULL DEFAULT 'invoice',
        scope           VARCHAR(8)   NOT NULL DEFAULT 'company',
        deal_id         INT UNSIGNED NULL,
        line_id         INT UNSIGNED NULL,
        contact_id      INT UNSIGNED NULL,
        party_kind      VARCHAR(12)  NOT NULL DEFAULT '',
        party_name      VARCHAR(190) NOT NULL DEFAULT '',
        party_nif       VARCHAR(20)  NOT NULL DEFAULT '',
        party_email     VARCHAR(190) NOT NULL DEFAULT '',
        party_address   VARCHAR(300) NOT NULL DEFAULT '',
        category        VARCHAR(60)  NOT NULL DEFAULT '',
        number          VARCHAR(60)  NOT NULL DEFAULT '',
        description     VARCHAR(500) NOT NULL DEFAULT '',
        base            DECIMAL(12,2) NOT NULL DEFAULT 0,
        vat_rate        DECIMAL(5,2) NOT NULL DEFAULT 0,
        vat             DECIMAL(12,2) NOT NULL DEFAULT 0,
        vat_deductible  TINYINT(1)   NOT NULL DEFAULT 1,
        vat_note        VARCHAR(190) NOT NULL DEFAULT '',
        total           DECIMAL(12,2) NOT NULL DEFAULT 0,
        wh_rate         DECIMAL(5,2) NOT NULL DEFAULT 0,
        withholding     DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency        VARCHAR(3)   NOT NULL DEFAULT 'EUR',
        issue_date      DATE         NULL,
        due_date        DATE         NULL,
        paid_date       DATE         NULL,
        paid_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
        status          VARCHAR(12)  NOT NULL DEFAULT 'issued',
        installment     VARCHAR(60)  NOT NULL DEFAULT '',
        pdf_key         VARCHAR(400) NOT NULL DEFAULT '',
        pdf_name        VARCHAR(200) NOT NULL DEFAULT '',
        receipt_key     VARCHAR(400) NOT NULL DEFAULT '',
        receipt_name    VARCHAR(200) NOT NULL DEFAULT '',
        ai_data         TEXT         NULL,
        needs_review    TINYINT(1)   NOT NULL DEFAULT 0,
        req_code        VARCHAR(30)  NOT NULL DEFAULT '',
        req_sent_at     DATETIME     NULL,
        req_body        TEXT         NULL,
        ready_at        DATETIME     NULL,
        client_sent_at  DATETIME     NULL,
        reminder_draft  TEXT         NULL,
        billing_id      INT UNSIGNED NULL,
        fixed_id        INT UNSIGNED NULL,
        payroll_id      INT UNSIGNED NULL,
        legacy_cost_id  INT UNSIGNED NULL,
        legacy_cc_id    INT UNSIGNED NULL,
        notes           TEXT         NULL,
        created_by      INT UNSIGNED NULL,
        created_at      DATETIME     NOT NULL,
        updated_at      DATETIME     NOT NULL,
        deleted_at      DATETIME     NULL,
        INDEX idx_fd_dir (direction, status, due_date),
        INDEX idx_fd_deal (deal_id),
        INDEX idx_fd_nif (party_nif, number),
        INDEX idx_fd_req (req_code),
        INDEX idx_fd_fixed (fixed_id, issue_date),
        INDEX idx_fd_legacy (legacy_cost_id)");
    db_create($pdo, 'fin_payments', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        doc_id      INT UNSIGNED NOT NULL,
        amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
        paid_on     DATE         NOT NULL,
        method      VARCHAR(20)  NOT NULL DEFAULT '',
        proof_key   VARCHAR(400) NOT NULL DEFAULT '',
        proof_name  VARCHAR(200) NOT NULL DEFAULT '',
        bank_tx_id  INT UNSIGNED NULL,
        note        VARCHAR(255) NOT NULL DEFAULT '',
        created_by  INT UNSIGNED NULL,
        created_at  DATETIME     NOT NULL,
        deleted_at  DATETIME     NULL,
        INDEX idx_fp_doc (doc_id),
        INDEX idx_fp_date (paid_on)");
    db_create($pdo, 'fin_fixed', "
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name           VARCHAR(160) NOT NULL,
        category       VARCHAR(60)  NOT NULL DEFAULT '',
        party_name     VARCHAR(190) NOT NULL DEFAULT '',
        party_nif      VARCHAR(20)  NOT NULL DEFAULT '',
        contact_id     INT UNSIGNED NULL,
        amount         DECIMAL(12,2) NOT NULL DEFAULT 0,
        vat_rate       DECIMAL(5,2) NOT NULL DEFAULT 0,
        vat_deductible TINYINT(1)   NOT NULL DEFAULT 1,
        wh_rate        DECIMAL(5,2) NOT NULL DEFAULT 0,
        period_months  TINYINT      NOT NULL DEFAULT 1,
        due_day        TINYINT      NOT NULL DEFAULT 1,
        start_date     DATE         NOT NULL,
        end_date       DATE         NULL,
        pay_method     VARCHAR(30)  NOT NULL DEFAULT '',
        active         TINYINT(1)   NOT NULL DEFAULT 1,
        notes          VARCHAR(500) NOT NULL DEFAULT '',
        created_by     INT UNSIGNED NULL,
        created_at     DATETIME     NOT NULL,
        updated_at     DATETIME     NOT NULL");
    db_create($pdo, 'fin_accounts', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(120) NOT NULL,
        kind         VARCHAR(10)  NOT NULL DEFAULT 'bank',
        iban         VARCHAR(40)  NOT NULL DEFAULT '',
        balance      DECIMAL(14,2) NOT NULL DEFAULT 0,
        balance_date DATE         NULL,
        active       TINYINT(1)   NOT NULL DEFAULT 1,
        position     INT          NOT NULL DEFAULT 0,
        created_at   DATETIME     NOT NULL,
        updated_at   DATETIME     NOT NULL");
    db_create($pdo, 'fin_imports', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id  INT UNSIGNED NOT NULL,
        file_name   VARCHAR(200) NOT NULL DEFAULT '',
        fmt         VARCHAR(4)   NOT NULL DEFAULT 'csv',
        n_new       INT          NOT NULL DEFAULT 0,
        n_dup       INT          NOT NULL DEFAULT 0,
        balance     DECIMAL(14,2) NULL,
        created_by  INT UNSIGNED NULL,
        created_at  DATETIME     NOT NULL");
    db_create($pdo, 'fin_bank_tx', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id  INT UNSIGNED NOT NULL,
        import_id   INT UNSIGNED NULL,
        tx_date     DATE         NOT NULL,
        amount      DECIMAL(14,2) NOT NULL,
        description VARCHAR(400) NOT NULL DEFAULT '',
        ref         VARCHAR(120) NOT NULL DEFAULT '',
        hash        CHAR(40)     NOT NULL,
        status      VARCHAR(10)  NOT NULL DEFAULT 'open',
        doc_id      INT UNSIGNED NULL,
        payment_id  INT UNSIGNED NULL,
        created_at  DATETIME     NOT NULL,
        UNIQUE KEY uq_fbt_hash (account_id, hash),
        INDEX idx_fbt (account_id, status, tx_date)");
    db_create($pdo, 'fin_employees', "
        user_id        INT UNSIGNED PRIMARY KEY,
        base_salary    DECIMAL(10,2) NOT NULL DEFAULT 0,
        meal_value     DECIMAL(6,2) NOT NULL DEFAULT 0,
        meal_type      VARCHAR(5)   NOT NULL DEFAULT 'card',
        meal_days      TINYINT      NOT NULL DEFAULT 22,
        irs_status     VARCHAR(10)  NOT NULL DEFAULT 'single',
        dependents     TINYINT      NOT NULL DEFAULT 0,
        dep_disabled   TINYINT      NOT NULL DEFAULT 0,
        disability     TINYINT(1)   NOT NULL DEFAULT 0,
        residence      VARCHAR(10)  NOT NULL DEFAULT 'continente',
        duodecimos     TINYINT(1)   NOT NULL DEFAULT 0,
        iban           VARCHAR(40)  NOT NULL DEFAULT '',
        nif            VARCHAR(20)  NOT NULL DEFAULT '',
        niss           VARCHAR(20)  NOT NULL DEFAULT '',
        contract_type  VARCHAR(30)  NOT NULL DEFAULT '',
        start_date     DATE         NULL,
        active         TINYINT(1)   NOT NULL DEFAULT 1,
        updated_by     INT UNSIGNED NULL,
        updated_at     DATETIME     NOT NULL");
    db_create($pdo, 'fin_payroll', "
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id       INT UNSIGNED NOT NULL,
        year          SMALLINT     NOT NULL,
        month         TINYINT      NOT NULL,
        status        VARCHAR(10)  NOT NULL DEFAULT 'issued',
        gross         DECIMAL(12,2) NOT NULL DEFAULT 0,
        irs           DECIMAL(12,2) NOT NULL DEFAULT 0,
        ss_employee   DECIMAL(12,2) NOT NULL DEFAULT 0,
        ss_employer   DECIMAL(12,2) NOT NULL DEFAULT 0,
        net           DECIMAL(12,2) NOT NULL DEFAULT 0,
        company_cost  DECIMAL(12,2) NOT NULL DEFAULT 0,
        data          TEXT         NULL,
        pdf_key       VARCHAR(400) NOT NULL DEFAULT '',
        emailed_at    DATETIME     NULL,
        created_by    INT UNSIGNED NULL,
        created_at    DATETIME     NOT NULL,
        cancelled_at  DATETIME     NULL,
        INDEX idx_fpr (user_id, year, month)");
    db_create($pdo, 'fin_scenarios', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kind        VARCHAR(12)  NOT NULL,
        name        VARCHAR(160) NOT NULL DEFAULT '',
        inputs      TEXT         NULL,
        result      TEXT         NULL,
        applied_at  DATETIME     NULL,
        created_by  INT UNSIGNED NULL,
        created_at  DATETIME     NOT NULL,
        deleted_at  DATETIME     NULL");
    db_create($pdo, 'fin_mail', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uid          VARCHAR(40)  NOT NULL DEFAULT '',
        message_id   VARCHAR(255) NOT NULL DEFAULT '',
        from_addr    VARCHAR(190) NOT NULL DEFAULT '',
        subject      VARCHAR(300) NOT NULL DEFAULT '',
        received_at  DATETIME     NULL,
        code         VARCHAR(30)  NOT NULL DEFAULT '',
        status       VARCHAR(10)  NOT NULL DEFAULT 'new',
        doc_id       INT UNSIGNED NULL,
        suggestions  TEXT         NULL,
        att          TEXT         NULL,
        ai           TEXT         NULL,
        note         VARCHAR(300) NOT NULL DEFAULT '',
        done_by      INT UNSIGNED NULL,
        done_at      DATETIME     NULL,
        created_at   DATETIME     NOT NULL,
        INDEX idx_fm (status, created_at),
        INDEX idx_fm_msg (message_id(120))");
    db_create($pdo, 'fin_done', "
        okey        VARCHAR(80)  NOT NULL PRIMARY KEY,
        task_id     INT UNSIGNED NULL,
        created_at  DATETIME     NOT NULL");

    db_add_column($pdo, 'fin_fixed', 'wh_rate', 'DECIMAL(5,2) NOT NULL DEFAULT 0', 'REAL NOT NULL DEFAULT 0');
    // dispensa de retenção (recibos verdes) e isenção de IVA (art. 53.º), na ficha do fornecedor
    db_add_column($pdo, 'contacts', 'wh_exempt', 'TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column($pdo, 'contacts', 'vat_exempt', 'TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');

    $now = date('Y-m-d H:i:s');
    migrate_finance_params($pdo, $now);
    migrate_finance_irs($pdo);
    migrate_finance_legacy($pdo, $now);
}

/**
 * Os parâmetros iniciais: [chave, grupo, rótulo, valor, unidade, fonte legal, ano, verificado em (AAAA-MM-DD ou ''), só admins].
 * Só entram os que ainda não existem (o que se editou fica). «Verificado em» vazio = por confirmar.
 */
function migrate_finance_param_rows(): array {
    $d233 = 'Despacho n.º 233-A/2026 (DR 2.ª série, n.º 3, supl., 06-01-2026)';
    return [
        // Segurança Social e salários
        ['ss_employee', 'Segurança Social', 'Taxa do trabalhador', '11', '%', 'Código Contributivo (Lei n.º 110/2009), art. 53.º', 2026, '', 0],
        ['ss_employer', 'Segurança Social', 'Taxa da empresa (TSU)', '23.75', '%', 'Código Contributivo (Lei n.º 110/2009), art. 53.º', 2026, '', 0],
        ['fct_rate', 'Segurança Social', 'FCT / FGCT', '0', '%', 'Lei n.º 70/2013 (FCT/FGCT) — contribuições suspensas', 2026, '', 0],
        ['accident_ins_rate', 'Segurança Social', 'Seguro de acidentes de trabalho (taxa da apólice)', '0', '%', 'Lei n.º 98/2009 (seguro obrigatório) — taxa da apólice da empresa', 2026, '', 0],
        ['min_wage', 'Salários', 'Retribuição mínima mensal garantida', '920', '€', $d233 . ' — preâmbulo (RMMG de 2026)', 2026, '2026-10-23', 0],
        ['meal_cash_exempt', 'Salários', 'Subsídio de refeição isento — dinheiro', '6.15', '€/dia', 'CIRS, art. 2.º, n.º 3, al. b), 2) e limite anual da função pública', 2026, '', 0],
        ['meal_card_exempt', 'Salários', 'Subsídio de refeição isento — cartão/vale', '10.46', '€/dia', 'CIRS, art. 2.º, n.º 3, al. b), 2) e limite anual da função pública', 2026, '', 0],
        ['holiday_month', 'Salários', 'Mês em que se paga o subsídio de férias', '6', 'mês', 'Política da empresa (Código do Trabalho, art. 264.º: antes do início das férias)', 2026, '', 0],
        ['xmas_month', 'Salários', 'Mês em que se paga o subsídio de Natal', '11', 'mês', 'Política da empresa (Código do Trabalho, art. 263.º: até 15 de dezembro)', 2026, '', 0],
        ['irs_table_year', 'IRS (retenção na fonte)', 'Ano das tabelas em uso', '2026', '', $d233, 2026, '2026-10-23', 0],
        ['irs_dep_disabled_single', 'IRS (retenção na fonte)', 'Por dependente com incapacidade ≥ 60% (não casado / casado único titular)', '84.82', '€', $d233 . ', n.º 5, al. a)', 2026, '2026-10-23', 0],
        ['irs_dep_disabled_two', 'IRS (retenção na fonte)', 'Por dependente com incapacidade ≥ 60% (casado dois titulares)', '42.41', '€', $d233 . ', n.º 5, al. a)', 2026, '2026-10-23', 0],
        ['irs_spouse_disabled', 'IRS (retenção na fonte)', 'Cônjuge sem rendimentos A/H com incapacidade ≥ 60% (casado único titular)', '135.71', '€', $d233 . ', n.º 5, al. b)', 2026, '2026-10-23', 0],
        ['irs_three_dep_cut', 'IRS (retenção na fonte)', 'Redução da taxa com 3 ou mais dependentes', '1', 'p.p.', $d233 . ', n.º 5, al. h)', 2026, '2026-10-23', 0],
        // Retenções a terceiros
        ['wh_cat_b', 'Retenções a terceiros', 'Retenção de IRS a freelancers (atividades do art. 151.º)', '23', '%', 'CIRS, art. 101.º, n.º 1, al. c)', 2026, '', 0],
        ['wh_rent', 'Retenções a terceiros', 'Retenção de IRS em rendas', '25', '%', 'CIRS, art. 101.º, n.º 1, al. e)', 2026, '', 0],
        // IVA
        ['vat_normal', 'IVA', 'Taxa normal', '23', '%', 'CIVA, art. 18.º, n.º 1, al. c)', 2026, '', 0],
        ['vat_intermediate', 'IVA', 'Taxa intermédia', '13', '%', 'CIVA, art. 18.º, n.º 1, al. b)', 2026, '', 0],
        ['vat_reduced', 'IVA', 'Taxa reduzida', '6', '%', 'CIVA, art. 18.º, n.º 1, al. a)', 2026, '', 0],
        ['vat_period', 'IVA', 'Período do IVA (1 = mensal, 3 = trimestral)', '3', 'meses', 'CIVA, art. 41.º (volume de negócios)', 2026, '', 0],
        ['vat_exempt_note', 'IVA', 'Menção para clientes estrangeiros (serviços B2B na UE)', 'IVA - autoliquidação (art. 6.º, n.º 6, al. a) do CIVA)', '', 'CIVA, art. 6.º, n.º 6, al. a) e art. 36.º, n.º 13', 2026, '', 0],
        // IRC
        ['irc_sme_rate', 'IRC', 'Taxa PME (primeiros euros de matéria coletável)', '15', '%', 'CIRC, art. 87.º, n.º 2', 2026, '', 0],
        ['irc_sme_limit', 'IRC', 'Limite da taxa PME', '50000', '€', 'CIRC, art. 87.º, n.º 2', 2026, '', 0],
        ['irc_rate', 'IRC', 'Taxa geral', '19', '%', 'CIRC, art. 87.º, n.º 1 (Orçamento do Estado para 2026)', 2026, '', 0],
        ['derrama_rate', 'IRC', 'Derrama municipal', '1.5', '%', 'Lei n.º 73/2013, art. 18.º — taxa aprovada pelo município (Lisboa)', 2026, '', 0],
        // Viaturas (simulador)
        ['car_vat_ev_limit', 'Viaturas', 'IVA dedutível na compra — elétrico até', '62500', '€', 'CIVA, art. 21.º, n.º 2, al. a) e Portaria n.º 467/2010', 2026, '', 0],
        ['car_vat_ev_pct', 'Viaturas', 'IVA dedutível na compra — elétrico', '100', '%', 'CIVA, art. 21.º, n.º 2, al. a)', 2026, '', 0],
        ['car_vat_phev_limit', 'Viaturas', 'IVA dedutível na compra — híbrido plug-in até', '50000', '€', 'CIVA, art. 21.º, n.º 2, al. a) e Portaria n.º 467/2010', 2026, '', 0],
        ['car_vat_phev_pct', 'Viaturas', 'IVA dedutível na compra — híbrido plug-in', '100', '%', 'CIVA, art. 21.º, n.º 2, al. a)', 2026, '', 0],
        ['car_vat_gpl_limit', 'Viaturas', 'IVA dedutível na compra — GPL/GNV até', '37500', '€', 'CIVA, art. 21.º, n.º 2, al. a) e Portaria n.º 467/2010', 2026, '', 0],
        ['car_vat_gpl_pct', 'Viaturas', 'IVA dedutível na compra — GPL/GNV', '50', '%', 'CIVA, art. 21.º, n.º 2, al. a)', 2026, '', 0],
        ['car_vat_commercial_pct', 'Viaturas', 'IVA dedutível na compra — comercial', '100', '%', 'CIVA, art. 21.º, n.º 1, al. a) (viaturas de mercadorias)', 2026, '', 0],
        ['car_fuel_elec_pct', 'Viaturas', 'IVA dedutível — eletricidade', '100', '%', 'CIVA, art. 21.º, n.º 1, al. b)', 2026, '', 0],
        ['car_fuel_diesel_pct', 'Viaturas', 'IVA dedutível — gasóleo, GPL, GNV', '50', '%', 'CIVA, art. 21.º, n.º 1, al. b)', 2026, '', 0],
        ['car_fuel_petrol_pct', 'Viaturas', 'IVA dedutível — gasolina', '0', '%', 'CIVA, art. 21.º, n.º 1, al. b)', 2026, '', 0],
        ['car_depr_rate', 'Viaturas', 'Depreciação fiscal anual', '25', '%', 'Decreto Regulamentar n.º 25/2009, tabela II', 2026, '', 0],
        ['car_depr_limit_comb', 'Viaturas', 'Depreciação aceite até — combustão', '25000', '€', 'Portaria n.º 467/2010', 2026, '', 0],
        ['car_depr_limit_phev', 'Viaturas', 'Depreciação aceite até — híbrido plug-in', '50000', '€', 'Portaria n.º 467/2010', 2026, '', 0],
        ['car_depr_limit_ev', 'Viaturas', 'Depreciação aceite até — elétrico', '62500', '€', 'Portaria n.º 467/2010', 2026, '', 0],
        ['car_depr_limit_gpl', 'Viaturas', 'Depreciação aceite até — GPL/GNV', '37500', '€', 'Portaria n.º 467/2010', 2026, '', 0],
        ['ta_limit1', 'Viaturas', 'Tributação autónoma — 1.º limite de custo', '37500', '€', 'CIRC, art. 88.º, n.º 3', 2026, '', 0],
        ['ta_limit2', 'Viaturas', 'Tributação autónoma — 2.º limite de custo', '45000', '€', 'CIRC, art. 88.º, n.º 3', 2026, '', 0],
        ['ta_comb', 'Viaturas', 'Tributação autónoma — combustão / híbrido normal (por escalão)', '8;25;32', '%', 'CIRC, art. 88.º, n.º 3', 2026, '', 0],
        ['ta_phev', 'Viaturas', 'Tributação autónoma — híbrido plug-in (por escalão)', '2.5;7.5;15', '%', 'CIRC, art. 88.º, n.º 18', 2026, '', 0],
        ['ta_ev', 'Viaturas', 'Tributação autónoma — elétrico (até ao limite; acima)', '0;10', '%', 'CIRC, art. 88.º, n.º 3 e n.º 20', 2026, '', 0],
        ['ta_ev_limit', 'Viaturas', 'Tributação autónoma — limite do elétrico', '62500', '€', 'CIRC, art. 88.º e Portaria n.º 467/2010', 2026, '', 0],
        // Amortizações (simulador)
        ['depr_it', 'Amortizações', 'Equipamento informático', '33.33', '%', 'Decreto Regulamentar n.º 25/2009, tabela II', 2026, '', 0],
        ['depr_software', 'Amortizações', 'Programas de computador', '33.33', '%', 'Decreto Regulamentar n.º 25/2009, tabela II', 2026, '', 0],
        ['depr_furniture', 'Amortizações', 'Mobiliário', '12.5', '%', 'Decreto Regulamentar n.º 25/2009, tabela II', 2026, '', 0],
        ['depr_av', 'Amortizações', 'Equipamento audiovisual / câmaras', '25', '%', 'Decreto Regulamentar n.º 25/2009, tabela II', 2026, '', 0],
        // Runway e tendência
        ['reserve_months', 'Runway', 'Reserva de emergência (meses de custos fixos)', '6', 'meses', 'Política da empresa', 2026, '2026-10-23', 1],
        ['runway_alert', 'Runway', 'Alertar quando o runway útil desce abaixo de', '6', 'meses', 'Política da empresa', 2026, '2026-10-23', 1],
        ['trend_pess', 'Runway', 'Cenário pessimista (fator da tendência)', '0.5', '×', 'Política da empresa', 2026, '2026-10-23', 0],
        ['trend_opt', 'Runway', 'Cenário otimista (fator da tendência)', '1.5', '×', 'Política da empresa', 2026, '2026-10-23', 0],
        ['prob_xray', 'Runway', 'Probabilidade de ganhar — etapa X-Ray', '10', '%', 'Política da empresa', 2026, '2026-10-23', 0],
        ['pipe_prob', 'Runway', 'Probabilidade de ganhar — etapa Proposta', '30', '%', 'Política da empresa', 2026, '2026-10-23', 0],
        ['prob_feedback', 'Runway', 'Probabilidade de ganhar — etapa Aguardar feedback', '50', '%', 'Política da empresa', 2026, '2026-10-23', 0],
        // Calendário fiscal (dia do mês; nos anuais «mês-dia»)
        ['cal_dmr', 'Calendário fiscal', 'DMR (IRS e SS dos salários) — declaração', '10', 'dia', 'CIRS, art. 119.º, n.º 1, al. c)', 2026, '', 0],
        ['cal_ss_decl', 'Calendário fiscal', 'Segurança Social — declaração de remunerações', '10', 'dia', 'Código Contributivo, art. 40.º', 2026, '', 0],
        ['cal_ss_pay', 'Calendário fiscal', 'Segurança Social — pagamento (até)', '20', 'dia', 'Código Contributivo, art. 43.º', 2026, '', 0],
        ['cal_irs_pay', 'Calendário fiscal', 'IRS retido — pagamento (até)', '20', 'dia', 'CIRS, art. 98.º, n.º 3', 2026, '', 0],
        ['cal_vat_day', 'Calendário fiscal', 'IVA — declaração periódica (dia do 2.º mês seguinte ao período)', '20', 'dia', 'CIVA, art. 41.º', 2026, '', 0],
        ['cal_vat_pay', 'Calendário fiscal', 'IVA — pagamento (dia do 2.º mês seguinte ao período)', '25', 'dia', 'CIVA, art. 27.º', 2026, '', 0],
        ['cal_annual', 'Calendário fiscal', 'Obrigações anuais (nome|mês-dia, separadas por ;)', 'Modelo 10|02-10;Modelo 22 (IRC)|05-31;IES|07-15;1.º pagamento por conta|07-31;2.º pagamento por conta|09-30;3.º pagamento por conta|12-15', '', 'CIRS art. 119.º; CIRC arts. 104.º, 105.º, 120.º, 121.º', 2026, '', 0],
    ];
}

function migrate_finance_params(PDO $pdo, string $now): void {
    $have = array_flip($pdo->query('SELECT pkey FROM fin_params')->fetchAll(PDO::FETCH_COLUMN));
    $ins = $pdo->prepare('INSERT INTO fin_params (pkey, grp, label, value, unit, source, year, verified_on, admin_only, position, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach (migrate_finance_param_rows() as $i => [$k, $g, $l, $v, $u, $src, $y, $ver, $adm]) {
        if (isset($have[$k])) continue;
        $ins->execute([$k, $g, $l, $v, $u, $src, $y, $ver !== '' ? $ver : null, $adm, $i, $now]);
    }
}

/**
 * As tabelas I a VII do continente (trabalho dependente), tal como publicadas no Despacho n.º 233-A/2026.
 * Linha: [até (null = «superior a»), taxa %, parcela a abater, fator k, base da fórmula, por dependente].
 * Com k: parcela a abater = taxa × k × (base − R).
 */
function migrate_finance_irs_rows(): array {
    return [
        'I' => [[920, 0, 0, null, null, 0], [1042, 12.5, 0, 2.6, 1273.85, 21.43], [1108, 15.7, 0, 1.35, 1554.83, 21.43], [1154, 15.7, 94.71, null, null, 21.43],
                [1212, 21.2, 158.18, null, null, 21.43], [1819, 24.1, 193.33, null, null, 21.43], [2119, 31.1, 320.66, null, null, 21.43], [2499, 34.9, 401.19, null, null, 21.43],
                [3305, 38.36, 487.66, null, null, 21.43], [5547, 39.69, 531.62, null, null, 21.43], [20221, 44.95, 823.40, null, null, 21.43], [null, 47.17, 1272.31, null, null, 21.43]],
        'II' => [[920, 0, 0, null, null, 0], [1042, 12.5, 0, 2.6, 1273.85, 34.29], [1108, 15.7, 0, 1.35, 1554.83, 34.29], [1154, 15.7, 94.71, null, null, 34.29],
                 [1212, 21.2, 158.18, null, null, 34.29], [1819, 24.1, 193.33, null, null, 34.29], [2119, 31.1, 320.66, null, null, 34.29], [2499, 34.9, 401.19, null, null, 34.29],
                 [3305, 38.36, 487.66, null, null, 34.29], [5547, 39.69, 531.62, null, null, 34.29], [20221, 44.95, 823.40, null, null, 34.29], [null, 47.17, 1272.31, null, null, 34.29]],
        'III' => [[991, 0, 0, null, null, 0], [1042, 12.5, 0, 2.6, 1372.15, 42.86], [1108, 12.5, 0, 1.35, 1677.85, 42.86], [1119, 12.5, 96.17, null, null, 42.86],
                  [1432, 12.72, 98.64, null, null, 42.86], [1962, 15.7, 141.32, null, null, 42.86], [2240, 19.38, 213.53, null, null, 42.86], [2773, 22.77, 289.47, null, null, 42.86],
                  [3389, 25.7, 370.72, null, null, 42.86], [5965, 28.81, 476.12, null, null, 42.86], [20265, 38.43, 1049.96, null, null, 42.86], [null, 47.17, 2821.13, null, null, 42.86]],
        'IV' => [[1694, 0, 0, null, null, 0], [2063, 21.2, 359.13, null, null, 0], [2492, 31.1, 563.37, null, null, 0], [4487, 34.9, 658.07, null, null, 0],
                 [4753, 38.36, 813.33, null, null, 0], [6687, 39.69, 876.55, null, null, 0], [20468, 44.95, 1228.29, null, null, 0], [null, 47.17, 1682.68, null, null, 0]],
        'V' => [[1938, 0, 0, null, null, 0], [2063, 21.32, 413.19, null, null, 42.86], [2854, 31.1, 614.96, null, null, 42.86], [4504, 34.9, 723.42, null, null, 42.86],
                [6826, 38.36, 879.26, null, null, 42.86], [7048, 39.69, 970.05, null, null, 42.86], [20468, 44.95, 1340.78, null, null, 42.86], [null, 47.17, 1795.17, null, null, 42.86]],
        'VI' => [[1668, 0, 0, null, null, 0], [2068, 20.49, 341.78, null, null, 21.43], [2497, 24.1, 416.44, null, null, 21.43], [3107, 31.1, 591.23, null, null, 21.43],
                 [4504, 34.9, 709.30, null, null, 21.43], [6826, 38.36, 865.14, null, null, 21.43], [7048, 39.69, 955.93, null, null, 21.43], [20468, 44.95, 1326.66, null, null, 21.43], [null, 47.17, 1781.05, null, null, 21.43]],
        'VII' => [[2325, 0, 0, null, null, 0], [3494, 22.77, 529.41, null, null, 42.86], [3761, 25.7, 631.79, null, null, 42.86], [6687, 28.81, 748.76, null, null, 42.86],
                  [20468, 42.44, 1660.20, null, null, 42.86], [null, 47.17, 2628.34, null, null, 42.86]],
    ];
}

function migrate_finance_irs(PDO $pdo): void {
    if ((int)$pdo->query("SELECT COUNT(*) FROM fin_irs WHERE year = 2026 AND region = 'continente'")->fetchColumn() > 0) return;
    $ins = $pdo->prepare('INSERT INTO fin_irs (year, region, tbl, upto, rate, deduct, deduct_k, deduct_base, dep_deduct, position, source) VALUES (2026, \'continente\', ?,?,?,?,?,?,?,?,?)');
    foreach (migrate_finance_irs_rows() as $t => $rows) {
        foreach ($rows as $i => [$upto, $rate, $ded, $k, $base, $dep]) {
            $ins->execute([$t, $upto, $rate, $ded, $k, $base, $dep, $i, 'Despacho n.º 233-A/2026']);
        }
    }
}

/** Os custos da empresa de antes passam a lançamentos (despesa, empresa), sem apagar nem repetir. */
function migrate_finance_legacy(PDO $pdo, string $now): void {
    $rate = (float)(($pdo->query("SELECT svalue FROM settings WHERE skey = 'vat_rate'")->fetchColumn()) ?: 23);
    $ins = $pdo->prepare('INSERT INTO fin_docs (direction, doc_type, scope, category, description, base, vat_rate, vat, total, issue_date, due_date, paid_date, paid_amount, status,
                                                legacy_cost_id, legacy_cc_id, notes, created_by, created_at, updated_at)
                          VALUES (\'out\', \'expense\', \'company\', \'Outros\', ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $add = function (array $c, string $paidCol, ?int $costId, ?int $ccId) use ($ins, $rate, $now): void {
        $amount = (float)($c['amount'] ?? 0);
        $mode = (string)($c['vat_mode'] ?? 'plus');
        $base = $mode === 'incl' ? round($amount / (1 + $rate / 100), 2) : round($amount, 2);
        $vat = $mode === 'plus' ? round($amount * $rate / 100, 2) : ($mode === 'incl' ? round($amount - $base, 2) : 0.0);
        $total = round($base + $vat, 2);
        $y = (int)($c['year'] ?? 0) ?: (int)date('Y');
        $m = (int)($c['month'] ?? 0);
        $date = sprintf('%04d-%02d-01', $y, $m >= 1 && $m <= 12 ? $m : 1);
        $paid = (int)($c[$paidCol] ?? 0) === 1;
        $ins->execute([mb_substr((string)$c['name'], 0, 500), $base, $mode === 'none' ? 0 : $rate, $vat, $total, $date, $date, $paid ? $date : null, $paid ? $total : 0,
                       $paid ? 'paid' : 'to_pay', $costId, $ccId, (string)($c['notes'] ?? ''), $c['created_by'] ?? null, $now, $now]);
    };
    if (db_has_table($pdo, 'costs')) {
        $done = array_flip($pdo->query('SELECT legacy_cost_id FROM fin_docs WHERE legacy_cost_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
        foreach ($pdo->query('SELECT * FROM costs WHERE deal_id IS NULL ORDER BY id')->fetchAll() as $c) {
            if (!isset($done[(int)$c['id']])) $add($c, 'is_closed', (int)$c['id'], null);
        }
    }
    // a tabela da versão 10 só conta se a passagem para a plataforma não foi feita (senão já está em costs)
    $migrated = (string)($pdo->query("SELECT svalue FROM settings WHERE skey = 'platform_migrated_at'")->fetchColumn() ?: '');
    if ($migrated === '' && db_has_table($pdo, 'company_costs')) {
        $done = array_flip($pdo->query('SELECT legacy_cc_id FROM fin_docs WHERE legacy_cc_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
        foreach ($pdo->query('SELECT * FROM company_costs ORDER BY id')->fetchAll() as $c) {
            if (!isset($done[(int)$c['id']])) $add($c, 'is_paid', null, (int)$c['id']);
        }
    }
}

/**
 * Passo 31 da base de dados: Tesouraria e reservas dos sócios.
 *  · fin_accounts: responsável próprio (opcional; senão o de Definições), quem e quando atualizou o saldo;
 *    fin_balance_log guarda cada atualização. Nasce a «Conta principal» se ainda não houver nenhuma.
 *  · fin_reserve_rules: por negócio, a parte da faturação reservada a um sócio (% ou valor fixo) — conta
 *    como custo do negócio e acumula à medida que o cliente paga; fin_reserve_payouts: o que já lhe foi
 *    transferido, com data e comprovativo. O que está reservado não conta no saldo disponível.
 */
function migrate_finance_31(PDO $pdo): void {
    db_add_column($pdo, 'fin_accounts', 'owner_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'fin_accounts', 'balance_by', 'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'fin_accounts', 'balance_at', 'DATETIME NULL', 'TEXT NULL');
    db_create($pdo, 'fin_balance_log', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id   INT UNSIGNED NOT NULL,
        balance      DECIMAL(14,2) NOT NULL,
        balance_date DATE         NOT NULL,
        user_id      INT UNSIGNED NULL,
        at           DATETIME     NOT NULL,
        INDEX idx_fbl (account_id, at)");
    db_create($pdo, 'fin_reserve_rules', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id     INT UNSIGNED NOT NULL,
        user_id     INT UNSIGNED NOT NULL,
        mode        VARCHAR(6)   NOT NULL DEFAULT 'pct',
        value       DECIMAL(12,2) NOT NULL DEFAULT 0,
        note        VARCHAR(200) NOT NULL DEFAULT '',
        created_by  INT UNSIGNED NULL,
        created_at  DATETIME     NOT NULL,
        deleted_at  DATETIME     NULL,
        INDEX idx_frr (deal_id), INDEX idx_frr_u (user_id)");
    db_create($pdo, 'fin_reserve_payouts', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL,
        amount      DECIMAL(12,2) NOT NULL,
        paid_on     DATE         NOT NULL,
        account_id  INT UNSIGNED NULL,
        proof_key   VARCHAR(400) NOT NULL DEFAULT '',
        proof_name  VARCHAR(200) NOT NULL DEFAULT '',
        note        VARCHAR(255) NOT NULL DEFAULT '',
        created_by  INT UNSIGNED NULL,
        created_at  DATETIME     NOT NULL,
        deleted_at  DATETIME     NULL,
        INDEX idx_frp (user_id, paid_on)");
    if (!(int)$pdo->query('SELECT COUNT(*) FROM fin_accounts')->fetchColumn()) {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO fin_accounts (name, kind, iban, balance, balance_date, active, position, created_at, updated_at) VALUES ('Conta principal', 'bank', '', 0, NULL, 1, 1, ?, ?)")->execute([$now, $now]);
    }
}

/**
 * Passo 32 (§28.14, revisto a 24 out 2026): a leitura passa a ser das caixas existentes, várias, só leitura.
 * fin_mail guarda de que caixa veio cada mensagem e como foi lida (qr, xml, ai, code).
 */
function migrate_finance_32(PDO $pdo): void {
    db_add_column($pdo, 'fin_mail', 'box', "VARCHAR(190) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'fin_mail', 'source', "VARCHAR(4) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
}

/**
 * Passo 38 · «Por confirmar · emails» revisto (§28.16):
 *   fin_mail.body_text   o texto do email (até ~1 000 caracteres), só nos emails que ficam como documento financeiro
 *   fin_mail.direction   a sugestão: 'out' despesa (A pagar), 'in' receita (A receber), '' indeciso (aparece nos dois)
 *   fin_class_rules      a classificação escolhida à mão para um emitente (NIF) ou remetente: os próximos vão igual
 */
function migrate_finance_38(PDO $pdo): void {
    if (!db_has_table($pdo, 'fin_mail')) return;
    db_add_column($pdo, 'fin_mail', 'body_text', 'TEXT NULL', 'TEXT NULL');
    db_add_column($pdo, 'fin_mail', 'direction', "VARCHAR(3) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_create($pdo, 'fin_class_rules', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nif          VARCHAR(20)   NOT NULL DEFAULT '',
        email        VARCHAR(190)  NOT NULL DEFAULT '',
        direction    VARCHAR(3)    NOT NULL,
        created_by   INT UNSIGNED  NULL,
        created_at   DATETIME      NOT NULL,
        UNIQUE KEY uq_fcr (nif, email)");
}

/**
 * Passo 39 · Comprovativos de pagamento e recibos por email (§28.17):
 *   fin_docs.pay_iban / mb_entity / mb_ref   o IBAN para pagar e a referência Multibanco lidos da fatura (as pistas
 *                                            mais fortes para associar o comprovativo)
 *   fin_mail.kind / proof                    'proof' comprovativo, 'receipt' recibo, '' documento; os dados lidos
 *   fin_payments.mail_id                     o email (comprovativo ou recibo) que deu origem ao pagamento
 *   fin_ibans                                os IBANs de quem pagamos/nos paga, aprendidos ao validar
 */
function migrate_finance_39(PDO $pdo): void {
    if (!db_has_table($pdo, 'fin_docs')) return;
    db_add_column($pdo, 'fin_docs', 'pay_iban', "VARCHAR(40) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'fin_docs', 'mb_entity', "VARCHAR(8) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'fin_docs', 'mb_ref', "VARCHAR(20) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'fin_mail', 'kind', "VARCHAR(8) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'fin_mail', 'proof', 'TEXT NULL', 'TEXT NULL');
    db_add_column($pdo, 'fin_payments', 'mail_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
    db_create($pdo, 'fin_ibans', "
        iban         VARCHAR(40)   NOT NULL PRIMARY KEY,
        party_nif    VARCHAR(20)   NOT NULL DEFAULT '',
        party_name   VARCHAR(190)  NOT NULL DEFAULT '',
        contact_id   INT UNSIGNED  NULL,
        uses         INT           NOT NULL DEFAULT 1,
        updated_at   DATETIME      NOT NULL");
}
