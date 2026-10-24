<?php
/**
 * topper. admin — Definições (§20): o registo de todas as definições da plataforma.
 *
 * Cada definição: separador, grupo, nome, tipo, valor de origem e uma frase (ⓘ) a
 * explicar para que serve. A página (definicoes.php), a pesquisa, a gravação
 * automática (ui_api.php?a=setting_save), «Repor valor de origem», o aviso de
 * impacto e o registo de alterações leem tudo daqui. Os valores ficam na tabela
 * settings (skey/svalue), as mesmas chaves que os módulos já liam.
 *
 * Tipos: int · num (decimal, aceita vírgula) · text · textarea · list (um por linha) ·
 * select (opts) · email · url · info (só leitura; o valor vem de 'show').
 * Só superadmin e admins mudam (verificado no servidor em setting_save()).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/contacts.php';
require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/cc.php';
require_once __DIR__ . '/cc_review.php';

/** Os separadores (um por aba com campos editáveis). Nova aba com definições = novo separador aqui. */
const SET_TABS = [
    'geral'        => ['Geral', 'dashboard'],
    'leads'        => ['Leads', 'leads'],
    'pipeline'     => ['Pipeline', 'pipeline'],
    'contactos'    => ['Contactos', 'contactos'],
    'orcamentacao' => ['Orçamentação', 'orcamentacao'],
    'negocios'     => ['Negócios', 'negocios'],
    'criativo'     => ['Centro Criativo', 'criativo'],
    'tarefas'      => ['Tarefas', 'tarefas'],
    'marketing'    => ['Marketing', 'marketing'],
    'financeiro'   => ['Financeiro', 'financeiro'],
    'drive'        => ['Drive', 'drive'],
    'integracoes'  => ['Integrações', 'link'],
    'seguranca'    => ['Segurança', 'eye'],
];

/** As categorias do Financeiro por omissão (a mesma lista de inc/fin.php, sem o carregar aqui). */
const FIN_CATS_DEFAULT_LIST = ['Software', 'Equipamento', 'Renda', 'Telecomunicações', 'Contabilidade', 'Seguros', 'Viaturas', 'Deslocações',
                               'Freelancers', 'Fornecedores de produção', 'Marketing', 'Formação', 'Impostos', 'Salários', 'Outros'];

/** As pessoas ativas como opções de uma definição ('' = o valor por omissão, com o rótulo dado); com $tab, só quem vê essa aba. */
function settings_user_opts(string $none, string $tab = ''): array {
    global $pdo;
    $o = ['' => $none];
    if ($pdo instanceof PDO) {
        try {
            foreach ($pdo->query('SELECT id, name, role FROM users WHERE is_active = 1 ORDER BY name')->fetchAll() as $u) {
                if ($tab !== '' && (perm_grid($pdo, $u)[$tab]['level'] ?? PERM_NONE) < PERM_VIEW) continue;
                $o[(string)$u['id']] = (string)$u['name'];
            }
        } catch (Throwable $e) {}
    }
    return $o;
}

/** O impacto de uma alteração que mexe em dados existentes: texto do aviso, ou '' se não há. */
class SettingImpact extends RuntimeException {}

/** Todas as definições, por ordem de apresentação. */
function settings_defs(): array {
    static $d = null;
    if ($d !== null) return $d;
    $d = [
        // ── Geral ──
        'company_name'    => ['tab' => 'geral', 'group' => 'Empresa', 'label' => 'Nome da empresa', 'type' => 'text', 'max' => 120, 'default' => 'topper.',
                              'help' => 'Aparece no rodapé das propostas em PDF e nos emails enviados pela plataforma.'],
        'company_nif'     => ['tab' => 'geral', 'group' => 'Empresa', 'label' => 'NIF', 'type' => 'text', 'max' => 20, 'default' => '',
                              'help' => 'O número de contribuinte da empresa, para o rodapé das propostas.'],
        'company_address' => ['tab' => 'geral', 'group' => 'Empresa', 'label' => 'Morada', 'type' => 'textarea', 'max' => 300, 'default' => '',
                              'help' => 'A morada da empresa, para o rodapé das propostas.'],
        'company_logo'    => ['tab' => 'geral', 'group' => 'Empresa', 'label' => 'Logótipo', 'type' => 'info', 'show' => 'O logótipo topper. da marca (menu e propostas)',
                              'help' => 'O logótipo vem da marca e é o mesmo no menu e nas propostas; muda-se com o desenho da marca.'],
        'vat_rate'        => ['tab' => 'geral', 'group' => 'Valores', 'label' => 'IVA', 'type' => 'num', 'min' => 0, 'max' => 50, 'unit' => '%', 'default' => 23, 'impact' => 'vat',
                              'help' => 'A taxa de IVA usada nos preços c/ IVA das propostas, na faturação e no Financeiro.'],
        'work_days'       => ['tab' => 'geral', 'group' => 'Horário', 'label' => 'Dias úteis', 'type' => 'select', 'opts' => ['5' => 'Segunda a sexta', '6' => 'Segunda a sábado'], 'default' => '5',
                              'help' => 'Os dias que contam como úteis nos prazos das tarefas automáticas e no horário por defeito da equipa.'],
        'work_start'      => ['tab' => 'geral', 'group' => 'Horário', 'label' => 'Início do dia', 'type' => 'time', 'default' => '09:00',
                              'help' => 'O horário padrão de quem ainda não definiu o seu no Perfil (disponibilidade e agenda).'],
        'work_end'        => ['tab' => 'geral', 'group' => 'Horário', 'label' => 'Fim do dia', 'type' => 'time', 'default' => '18:00',
                              'help' => 'O fim do horário padrão de quem ainda não definiu o seu no Perfil.'],
        'budget_day_hours'=> ['tab' => 'geral', 'group' => 'Horário', 'label' => '1 dia de trabalho', 'type' => 'num', 'min' => 1, 'max' => 24, 'unit' => 'h', 'default' => 8,
                              'help' => 'Converte dias em horas nas folhas de orçamento novas (1 dia = 8 h). As folhas que já existem guardam o seu.'],
        'timezone'        => ['tab' => 'geral', 'group' => 'Horário', 'label' => 'Fuso horário', 'type' => 'info', 'show' => 'Europa/Lisboa',
                              'help' => 'Prazos, agenda e notificações contam na hora de Lisboa (com a mudança de hora de verão e de inverno).'],

        // ── Leads ──
        'lead_deadline_novo'         => ['tab' => 'leads', 'group' => 'Prazos por estado', 'label' => 'Novo', 'type' => 'int', 'min' => 1, 'max' => 720, 'unit' => 'h', 'default' => 24, 'impact' => 'lead_deadline',
                                         'help' => 'Tempo máximo para o primeiro contacto com uma lead nova (24/7, conta desde a criação).'],
        'lead_deadline_sem_resposta' => ['tab' => 'leads', 'group' => 'Prazos por estado', 'label' => 'Sem resposta', 'type' => 'int', 'min' => 1, 'max' => 720, 'unit' => 'h', 'default' => 24, 'impact' => 'lead_deadline',
                                         'help' => 'Tempo até à próxima tentativa depois de uma chamada não atendida (recomeça a cada tentativa).'],
        'lead_deadline_follow_up'    => ['tab' => 'leads', 'group' => 'Prazos por estado', 'label' => 'Follow-Up', 'type' => 'int', 'min' => 1, 'max' => 720, 'unit' => 'h', 'default' => 48, 'impact' => 'lead_deadline',
                                         'help' => 'Tempo até ao próximo contacto depois de uma conversa (recomeça a cada follow-up).'],
        'lead_attempts_archive'      => ['tab' => 'leads', 'group' => 'Chamadas', 'label' => 'Sugerir arquivar ao fim de', 'type' => 'int', 'min' => 2, 'max' => 10, 'unit' => 'tentativas', 'default' => 3,
                                         'help' => 'Depois deste número de chamadas não atendidas seguidas, «Como correu?» sugere arquivar a lead com o motivo «Sem resposta».'],
        'lead_types'                 => ['tab' => 'leads', 'group' => 'Listas', 'label' => 'Tipos de lead', 'type' => 'list', 'default' => implode("\n", LEAD_TYPES_LIST),
                                         'help' => 'As opções de «Tipo» ao criar e editar uma lead, e o filtro da lista. Retirar um tipo não o tira das leads que já o têm.'],
        'lead_archive_reasons'       => ['tab' => 'leads', 'group' => 'Listas', 'label' => 'Motivos de arquivo', 'type' => 'list', 'default' => implode("\n", array_diff_key(ARCHIVE_REASONS, ['perdido_migracao' => 1])),
                                         'help' => 'Os motivos a escolher ao arquivar uma lead. «Outro» pede sempre uma nota. As leads já arquivadas guardam o motivo que tinham.'],
        'lead_campaigns'             => ['tab' => 'leads', 'group' => 'Listas', 'label' => 'Campanhas sugeridas', 'type' => 'list', 'default' => '',
                                         'help' => 'Sugestões para o campo «Campanha» das leads (continua a poder escrever outra).'],
        'lead_origins'               => ['tab' => 'leads', 'group' => 'Listas', 'label' => 'Origens', 'type' => 'info', 'show' => implode(' · ', LEAD_ORIGIN),
                                         'help' => 'As origens são fixas porque têm regras próprias (formulário do site, links com rastreio das redes, carreiras).'],
        'spam_min_seconds'           => ['tab' => 'leads', 'group' => 'Quarentena (formulário do site)', 'label' => 'Tempo mínimo de preenchimento', 'type' => 'int', 'min' => 0, 'max' => 60, 'unit' => 's', 'default' => 3,
                                         'help' => 'Um envio mais rápido do que isto é quase sempre um robô: a lead entra em quarentena.'],
        'spam_max_links'             => ['tab' => 'leads', 'group' => 'Quarentena (formulário do site)', 'label' => 'Links na mensagem (máximo)', 'type' => 'int', 'min' => 0, 'max' => 20, 'default' => 2,
                                         'help' => 'Uma mensagem com mais links do que isto entra em quarentena.'],
        'form_rate_hour'             => ['tab' => 'leads', 'group' => 'Quarentena (formulário do site)', 'label' => 'Envios por hora (mesmo IP)', 'type' => 'int', 'min' => 1, 'max' => 100, 'default' => 5,
                                         'help' => 'Acima deste número de envios numa hora a partir do mesmo endereço, o formulário recusa até passar a hora.'],
        'sla_hours'                  => ['tab' => 'leads', 'group' => 'Prazo da primeira resposta (horas úteis)', 'label' => 'Geral', 'type' => 'num', 'min' => 0.25, 'max' => 200, 'unit' => 'h úteis', 'default' => 4,
                                         'help' => 'Do registo da lead até à primeira chamada, email, WhatsApp ou reunião, contado no horário de trabalho (Geral › Horário). Fora do prazo, a lead aparece em Relatórios › Atenção.'],

        // ── Pipeline ──
        'flow_editor'        => ['tab' => 'pipeline', 'group' => 'Funis, etapas e automações', 'label' => 'Funis, etapas, gates, automações e X-Ray', 'type' => 'link', 'href' => '/admin/funis.php',
                                 'show' => 'Abrir o editor', 'help' => 'Os funis e as etapas, os requisitos para avançar (gates), as tarefas automáticas de cada etapa, as perguntas do X-Ray por funil e as rondas de revisão incluídas.'],
        'xsell_editor'       => ['tab' => 'pipeline', 'group' => 'Crosselling', 'label' => 'Pacotes e crosselling depois da entrega', 'type' => 'link', 'href' => '/admin/funis.php?x=1',
                                 'show' => 'Abrir', 'help' => 'Os pacotes pré-definidos (serviços, sincronização das frentes e modelo de orçamento) e as regras «depois de entregar X, propor Y ao fim de N dias».'],
        'lost_reasons'       => ['tab' => 'pipeline', 'group' => 'Propostas e perdas', 'label' => 'Motivos de perda', 'type' => 'list', 'default' => implode("\n", LOST_REASONS),
                                 'help' => 'Os motivos a escolher ao marcar um negócio como perdido. «Outro» deixa escrever o detalhe.'],
        'prob_xray'          => ['tab' => 'pipeline', 'group' => 'Relatórios', 'label' => 'Probabilidade de fecho em X-Ray', 'type' => 'num', 'min' => 0, 'max' => 100, 'unit' => '%', 'default' => 15,
                                 'help' => 'Para o pipeline ponderado (valor × probabilidade da etapa) nos Relatórios e no Dashboard.'],
        'prob_proposta'      => ['tab' => 'pipeline', 'group' => 'Relatórios', 'label' => 'Probabilidade de fecho em Proposta', 'type' => 'num', 'min' => 0, 'max' => 100, 'unit' => '%', 'default' => 35,
                                 'help' => 'Para o pipeline ponderado (valor × probabilidade da etapa).'],
        'prob_feedback'      => ['tab' => 'pipeline', 'group' => 'Relatórios', 'label' => 'Probabilidade de fecho em Aguardar feedback', 'type' => 'num', 'min' => 0, 'max' => 100, 'unit' => '%', 'default' => 55,
                                 'help' => 'Para o pipeline ponderado (valor × probabilidade da etapa).'],
        'feedback_days'      => ['tab' => 'pipeline', 'group' => 'Relatórios', 'label' => 'Proposta sem seguimento ao fim de', 'type' => 'int', 'min' => 1, 'max' => 90, 'unit' => 'dias', 'default' => 7,
                                 'help' => 'Uma proposta em «Aguardar feedback» há mais destes dias, sem nenhuma interação registada desde que lá entrou, aparece em Relatórios › Atenção.'],
        'review_wait_days'   => ['tab' => 'pipeline', 'group' => 'Relatórios', 'label' => 'Review à espera do cliente ao fim de', 'type' => 'int', 'min' => 1, 'max' => 90, 'unit' => 'dias', 'default' => 5,
                                 'help' => 'Uma peça enviada ao cliente no Review sem resposta há mais destes dias aparece em Relatórios › Atenção.'],
        'proposal_valid_days'=> ['tab' => 'pipeline', 'group' => 'Propostas e perdas', 'label' => 'Validade das propostas', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'dias', 'default' => 30,
                                 'help' => 'A validade com que uma versão nova do orçamento começa. Muda-se em cada proposta, na folha de orçamento.'],
        'pay_terms_default'  => ['tab' => 'pipeline', 'group' => 'Condições de pagamento', 'label' => 'Até ao limite', 'type' => 'text', 'max' => 500, 'default' => '50% na adjudicação, 50% na entrega.',
                                 'help' => 'Sugeridas na folha de orçamento, em «Proposta», para propostas até ao limite.'],
        'pay_terms_threshold'=> ['tab' => 'pipeline', 'group' => 'Condições de pagamento', 'label' => 'Limite', 'type' => 'num', 'min' => 0, 'max' => 10000000, 'unit' => '€ s/ IVA', 'default' => 10000,
                                 'help' => 'A partir deste valor sugerem-se as condições «Acima do limite».'],
        'pay_terms_large'    => ['tab' => 'pipeline', 'group' => 'Condições de pagamento', 'label' => 'Acima do limite', 'type' => 'text', 'max' => 500, 'default' => '40% na adjudicação, 40% a meio, 20% na entrega.',
                                 'help' => 'Sugeridas para propostas acima do limite.'],
        'pay_terms_buddy'    => ['tab' => 'pipeline', 'group' => 'Condições de pagamento', 'label' => 'Marketing Buddy (avença)', 'type' => 'text', 'max' => 500, 'default' => 'Mensal, adiantado; mínimo de 3 meses.',
                                 'help' => 'Sugeridas nas propostas do funil Marketing Buddy.'],
        'proposal_mail_subject' => ['tab' => 'pipeline', 'group' => 'Email da proposta', 'label' => 'Assunto', 'type' => 'text', 'max' => 160, 'default' => 'Proposta {ID} · {negócio}',
                                 'help' => 'O assunto do email que leva a proposta ao cliente. Variáveis: {cliente} {negócio} {ID}.'],
        'proposal_mail_intro'   => ['tab' => 'pipeline', 'group' => 'Email da proposta', 'label' => 'Introdução', 'type' => 'textarea', 'max' => 600, 'default' => 'Segue a nossa proposta para «{negócio}».',
                                 'help' => 'A frase de abertura do email, a seguir à saudação. Variáveis: {cliente} {negócio} {ID}. A mensagem de quem envia entra a seguir.'],

        // ── Contactos ──
        'services'          => ['tab' => 'contactos', 'group' => 'Serviços', 'label' => 'Serviços e unidades', 'type' => 'services',
                                'help' => 'A lista comum de «Serviços e preços» dos fornecedores e freelancers, também usada na calculadora da Orçamentação. Unidades: ' . implode(', ', SERVICE_UNITS) . '.'],
        'supplier_categories' => ['tab' => 'contactos', 'group' => 'Serviços', 'label' => 'Categorias de fornecedor', 'type' => 'info', 'show' => implode(' · ', CONTACT_TYPES),
                                'help' => 'As categorias dos contactos são fixas (cada uma muda o que a ficha mostra); a especialidade de cada um vem dos serviços.'],
        'rel_cadence_a'     => ['tab' => 'contactos', 'group' => 'Relações: cadência dos follow-ups', 'label' => 'Nível A', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'dias', 'default' => 7, 'impact' => 'cadence',
                                'help' => 'Dias entre toques nas relações de nível A (as mais próximas e com mais potencial). Cada contacto pode ter uma exceção.'],
        'rel_cadence_b'     => ['tab' => 'contactos', 'group' => 'Relações: cadência dos follow-ups', 'label' => 'Nível B', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'dias', 'default' => 15, 'impact' => 'cadence',
                                'help' => 'Dias entre toques nas relações de nível B.'],
        'rel_cadence_c'     => ['tab' => 'contactos', 'group' => 'Relações: cadência dos follow-ups', 'label' => 'Nível C', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'dias', 'default' => 30, 'impact' => 'cadence',
                                'help' => 'Dias entre toques nas relações de nível C.'],

        // ── Orçamentação ──
        'budget_mso_pct'          => ['tab' => 'orcamentacao', 'group' => 'Calculadora', 'label' => 'MSO', 'type' => 'num', 'min' => 0, 'max' => 50, 'unit' => '%', 'default' => 5,
                                      'help' => 'Margem de segurança operacional, em % dos custos diretos. Entra nas folhas novas.'],
        'budget_margin_pct'       => ['tab' => 'orcamentacao', 'group' => 'Calculadora', 'label' => 'Margem', 'type' => 'num', 'min' => 0, 'max' => 95, 'unit' => '%', 'default' => 65,
                                      'help' => 'A margem com que as folhas novas começam (preço = base ÷ (1 − margem)). Muda-se em cada folha, com histórico.'],
        'budget_month_target'     => ['tab' => 'orcamentacao', 'group' => 'Calculadora', 'label' => 'Meta de lucro por mês', 'type' => 'num', 'min' => 0, 'max' => 10000000, 'unit' => '€', 'default' => 8000,
                                      'help' => 'O semáforo de cada folha: verde na meta, laranja abaixo, vermelho abaixo de 75%. Vale já para todas as folhas.'],
        'budget_occupancy_default'=> ['tab' => 'orcamentacao', 'group' => 'Calculadora', 'label' => '% de ocupação padrão', 'type' => 'num', 'min' => 0, 'max' => 100, 'unit' => '%', 'default' => 0,
                                      'help' => 'Usada quando não há horas da equipa para sugerir a ocupação (pessoas sem horas mensais na Equipa).'],
        'budget_fixed_override'   => ['tab' => 'orcamentacao', 'group' => 'Calculadora', 'label' => 'Custos fixos mensais (correção)', 'type' => 'num', 'min' => 0, 'max' => 10000000, 'unit' => '€/mês', 'default' => 0,
                                      'help' => 'Com 0, as folhas novas usam a média dos últimos 3 meses do Financeiro. Com outro valor, usam este (ex.: quando a média ainda não é representativa).'],
        'budget_templates'        => ['tab' => 'orcamentacao', 'group' => 'Modelos', 'label' => 'Modelos de orçamento', 'type' => 'templates',
                                      'help' => 'Os modelos guardam-se a partir de uma folha («Guardar como modelo») e aplicam-se numa versão vazia. Aqui pode apagá-los.'],
        'budget_conditions'       => ['tab' => 'orcamentacao', 'group' => 'Modelo do PDF', 'label' => 'Condições por defeito da proposta', 'type' => 'textarea', 'max' => 4000, 'default' => '',
                                      'help' => 'O texto de condições com que as folhas novas começam, no fim do PDF da proposta.'],

        // ── Negócios ──
        'close_checklist'  => ['tab' => 'negocios', 'group' => 'Fecho', 'label' => 'Checklist de fecho', 'type' => 'info', 'show' => 'Entregas feitas · Faturado e recebido · Custos fechados · Portfólio decidido · Testemunho pedido · Ficheiros arquivados',
                               'help' => 'O que o negócio pede antes de ir para Concluído. As tarefas de fecho de cada funil (aprovação final, faturas, direitos…) editam-se em Pipeline › Funis e automações.'],
        'deal_stale_days'  => ['tab' => 'negocios', 'group' => 'Avisos «Precisa de atenção»', 'label' => 'Negócio parado', 'type' => 'int', 'min' => 1, 'max' => 90, 'unit' => 'dias', 'default' => 10, 'impact' => 'stale',
                               'help' => 'Dias sem movimento até um negócio aparecer como «em risco» e o responsável ser avisado.'],
        'task_due_days'    => ['tab' => 'negocios', 'group' => 'Avisos «Precisa de atenção»', 'label' => 'Tarefa a aproximar-se do prazo', 'type' => 'int', 'min' => 0, 'max' => 30, 'unit' => 'dias', 'default' => 2,
                               'help' => 'Com quantos dias de antecedência uma tarefa entra nos avisos.'],
        'invoice_overdue_days' => ['tab' => 'negocios', 'group' => 'Avisos «Precisa de atenção»', 'label' => 'Por receber em atraso', 'type' => 'int', 'min' => 1, 'max' => 180, 'unit' => 'dias', 'default' => 30,
                               'help' => 'Dias desde a fatura até um valor por receber aparecer nos avisos (para quem vê o Financeiro).'],

        // ── Tarefas ──
        'invoice_request_days' => ['tab' => 'negocios', 'group' => 'Avisos «Precisa de atenção»', 'label' => 'Fatura pedida sem resposta', 'type' => 'int', 'min' => 1, 'max' => 60, 'unit' => 'dias', 'default' => 3,
                                   'help' => 'Uma fatura pedida ao contabilista sem resposta há mais destes dias aparece em Relatórios › Atenção (só para quem vê o Financeiro).'],
        'task_types'       => ['tab' => 'tarefas', 'group' => 'Tipos e origens', 'label' => 'Tipos (ícone e cor)', 'type' => 'types',
                               'help' => 'Cada tipo tem um ícone e uma cor, afinada para o modo claro e para o escuro (é o que mantém a lista legível de relance).'],
        'task_origins'     => ['tab' => 'tarefas', 'group' => 'Tipos e origens', 'label' => 'Cores das origens', 'type' => 'origins',
                               'help' => 'A marca pequena de cada tarefa diz de onde veio, com o ícone da aba de origem.'],
        'task_board_max'   => ['tab' => 'tarefas', 'group' => 'Quadro e lista', 'label' => 'Máximo de cartões por coluna', 'type' => 'int', 'min' => 3, 'max' => 30, 'default' => TASK_BOARD_MAX,
                               'help' => 'No Quadro da equipa, cada pessoa mostra no máximo este número de cartões (os mais urgentes); os outros ficam em «+ N tarefas».'],
        'task_group_auto'  => ['tab' => 'tarefas', 'group' => 'Quadro e lista', 'label' => 'Agrupar tarefas automáticas', 'type' => 'select', 'opts' => ['1' => 'Sim (ex.: «Chamadas de leads · 22»)', '0' => 'Não, uma a uma'], 'default' => '1',
                               'help' => 'As chamadas de leads e os follow-ups de relação aparecem numa linha com «Abrir fila», em vez de uma linha cada.'],

        // ── Marketing ──
        'mkt_pillars'      => ['tab' => 'marketing', 'group' => 'Pilares', 'label' => 'Pilares de conteúdo', 'type' => 'pillars',
                               'help' => 'Os temas das publicações (cor no Calendário e equilíbrio do mês). Um pilar inativo deixa de aparecer para escolher.'],
        'mkt_target_ig_min'=> ['tab' => 'marketing', 'group' => 'Cadência por rede (por semana)', 'label' => 'Instagram (mínimo)', 'type' => 'int', 'min' => 0, 'max' => 99, 'default' => 2,
                               'help' => 'No Calendário, as semanas abaixo do alvo ficam destacadas.'],
        'mkt_target_ig_max'=> ['tab' => 'marketing', 'group' => 'Cadência por rede (por semana)', 'label' => 'Instagram (máximo)', 'type' => 'int', 'min' => 0, 'max' => 99, 'default' => 3,
                               'help' => 'Acima disto, a semana aparece como carregada demais.'],
        'mkt_target_li'    => ['tab' => 'marketing', 'group' => 'Cadência por rede (por semana)', 'label' => 'LinkedIn', 'type' => 'int', 'min' => 0, 'max' => 99, 'default' => 5,
                               'help' => 'Publicações por semana no LinkedIn.'],
        'mkt_target_x'     => ['tab' => 'marketing', 'group' => 'Cadência por rede (por semana)', 'label' => 'X', 'type' => 'int', 'min' => 0, 'max' => 99, 'default' => 10,
                               'help' => 'Publicações por semana no X.'],
        'mkt_voice'        => ['tab' => 'marketing', 'group' => 'Voz da topper.', 'label' => 'Voz da topper.', 'type' => 'textarea', 'max' => 8000, 'default' => '', 'required' => true,
                               'help' => 'Como a topper. escreve. A IA do Estúdio segue-a sempre; aparece também no Centro de Conhecimento.'],
        'mkt_templates'    => ['tab' => 'marketing', 'group' => 'Modelos de formato', 'label' => 'Modelos de formato', 'type' => 'info', 'show' => '',
                               'help' => 'As estruturas de cada formato (reel, carrossel, post, thread, vídeo) que o Estúdio propõe.'],

        // ── Centro Criativo (§23) ──
        'cc_pre_days'      => ['tab' => 'criativo', 'group' => 'Pré-criativo', 'label' => 'Prazo da tarefa «Pré-criativo»', 'type' => 'int', 'min' => 0, 'max' => 30, 'unit' => 'dias úteis', 'default' => 2,
                               'help' => 'Ao validar o X-Ray nasce o quadro do pré-criativo e uma tarefa para o responsável de execução, com este prazo.'],
        'cc_funnels'       => ['tab' => 'criativo', 'group' => 'Por funil', 'label' => 'Pré-criativo e sub-quadros do projeto', 'type' => 'cc_funnels',
                               'help' => 'Por funil: se o pré-criativo é recomendado (não trava a proposta), obrigatório ou desligado, e que sub-quadros nascem no quadro do projeto no Kickoff.'],
        'cc_vocab_style'   => ['tab' => 'criativo', 'group' => 'Vocabulário da casa (a IA classifica com estes termos)', 'label' => 'Estilo', 'type' => 'list', 'default' => implode("\n", CC_VOCAB['style'][1]),
                               'help' => 'Os estilos que a IA usa para classificar as referências e que aparecem nos filtros da Biblioteca. Um por linha.'],
        'cc_vocab_technique' => ['tab' => 'criativo', 'group' => 'Vocabulário da casa (a IA classifica com estes termos)', 'label' => 'Técnica', 'type' => 'list', 'default' => implode("\n", CC_VOCAB['technique'][1]),
                               'help' => 'As técnicas de produção (CG, tabletop, stop motion…). Um por linha.'],
        'cc_vocab_camera'  => ['tab' => 'criativo', 'group' => 'Vocabulário da casa (a IA classifica com estes termos)', 'label' => 'Câmara', 'type' => 'list', 'default' => implode("\n", CC_VOCAB['camera'][1]),
                               'help' => 'Os movimentos e posições de câmara. Um por linha.'],
        'cc_vocab_environment' => ['tab' => 'criativo', 'group' => 'Vocabulário da casa (a IA classifica com estes termos)', 'label' => 'Ambiente', 'type' => 'list', 'default' => implode("\n", CC_VOCAB['environment'][1]),
                               'help' => 'Os ambientes (estúdio, exterior urbano, natureza…). Um por linha.'],
        'cc_vocab_light'   => ['tab' => 'criativo', 'group' => 'Vocabulário da casa (a IA classifica com estes termos)', 'label' => 'Luz', 'type' => 'list', 'default' => implode("\n", CC_VOCAB['light'][1]),
                               'help' => 'Os tipos de luz. Um por linha.'],
        'cc_import'        => ['tab' => 'criativo', 'group' => 'Importação', 'label' => 'Importar do Milanote (ZIP, PDF, Markdown) ou imagens', 'type' => 'link', 'href' => '/admin/criativo.php?t=biblioteca', 'show' => 'Biblioteca › Importar',
                               'help' => 'Cria um quadro novo com os cartões em grelha; as imagens entram na biblioteca com a análise da IA.'],

        // ── Review e Portal do cliente (§24) ──
        'cc_portal_days'   => ['tab' => 'criativo', 'group' => 'Review e Portal do cliente', 'label' => 'Validade do link do portal', 'type' => 'int', 'min' => 1, 'max' => 365, 'unit' => 'dias depois da entrega', 'default' => 60,
                               'help' => 'Um link por negócio. Deixa de funcionar este número de dias depois da data de entrega (ou de hoje, se a entrega já passou). Pode ser renovado ou revogado na ficha do negócio.'],
        'cc_portal_wm'     => ['tab' => 'criativo', 'group' => 'Review e Portal do cliente', 'label' => 'Marca de água nas peças novas', 'type' => 'select', 'opts' => ['1' => 'Sim: logótipo topper. e nome do cliente', '0' => 'Não'], 'default' => '1',
                               'help' => 'O que o portal mostra leva o logótipo topper. e o nome do cliente (gravados no vídeo e nas imagens). Muda-se peça a peça no Review.'],
        'cc_portal_dl'     => ['tab' => 'criativo', 'group' => 'Review e Portal do cliente', 'label' => 'Download nas peças novas', 'type' => 'select', 'opts' => ['0' => 'Não', '1' => 'Sim, o original'], 'default' => '0',
                               'help' => 'Se o cliente pode descarregar o original pelo portal. Muda-se peça a peça no Review.'],
        'cc_portal_lang'   => ['tab' => 'criativo', 'group' => 'Review e Portal do cliente', 'label' => 'Idioma do portal', 'type' => 'select', 'opts' => ['pt' => 'Português', 'en' => 'English'], 'default' => 'pt',
                               'help' => 'O idioma dos links novos. Muda-se negócio a negócio na ficha; o cliente também pode trocar no portal.'],
        'cc_rv_rounds'     => ['tab' => 'criativo', 'group' => 'Review e Portal do cliente', 'label' => 'Rondas incluídas por etapa', 'type' => 'link', 'href' => '/admin/funis.php', 'show' => 'Funis › etapas',
                               'help' => 'Cada envio consolidado do cliente conta uma ronda na etapa do negócio. As rondas incluídas são as de cada etapa do funil; acima disso, o comercial é avisado e nasce a tarefa «Pedido de alteração».'],
        'cc_rv_map'        => ['tab' => 'criativo', 'group' => 'Review e Portal do cliente', 'label' => 'Etapa de review → tarefa de aprovação', 'type' => 'list',
                               'default' => implode("\n", array_map(fn($k, $v) => $k . ' = ' . $v, array_keys(CC_RV_TASK_MAP), CC_RV_TASK_MAP)),
                               'help' => 'Ao aprovar uma peça, fica feita a tarefa de aprovação do Pipeline cujo título tem um destes textos. Uma etapa por linha: «etapa = texto | texto». Etapas: styleframes, animatic, offline, lock, finais, lote.'],

        // ── Tempo real, histórico e captura (§26) ──
        'cc_ver_minutes'   => ['tab' => 'criativo', 'group' => 'Histórico dos quadros', 'label' => 'Versão automática a cada', 'type' => 'int', 'min' => 5, 'max' => 1440, 'unit' => 'min de trabalho', 'default' => 30,
                               'help' => 'Uma versão por sessão de trabalho: o quadro fica guardado antes da primeira alteração, se a última versão tem mais do que este tempo. Também se guarda antes das ações grandes (Arrumar o quadro, Preparar com IA, apagar muitos cartões).'],
        'cc_ver_keep'      => ['tab' => 'criativo', 'group' => 'Histórico dos quadros', 'label' => 'Versões guardadas por quadro', 'type' => 'int', 'min' => 5, 'max' => 500, 'default' => 50,
                               'help' => 'As mais antigas saem quando o quadro passa este número. Cada versão pode ser vista e restaurada no Histórico do quadro.'],
        'cap_default'      => ['tab' => 'criativo', 'group' => 'Captura (extensão e telemóvel)', 'label' => 'Destino por defeito', 'type' => 'select', 'opts' => ['inbox' => 'Caixa de entrada', 'last' => 'O último quadro usado (e a Caixa de entrada)'], 'default' => 'inbox',
                               'help' => 'Onde fica o que se guarda com a extensão «Guardar na topper.», o menu Partilhar do Android ou o Atalho do iOS. Tudo passa pela análise da IA e entra na Caixa de entrada; com um quadro, também fica lá como cartão.'],

        // ── Financeiro ──
        'fin_accountant_email' => ['tab' => 'financeiro', 'group' => 'Contabilista e pagamentos', 'label' => 'Email do contabilista', 'type' => 'email', 'max' => 190, 'default' => '',
                               'help' => 'Para onde vão os pedidos de fatura e de recibo («Pedir fatura»), com o código de referência no assunto. A resposta é lida nas caixas de email (Definições › Integrações) e liga-se sozinha ao pedido.'],
        'fin_iban'         => ['tab' => 'financeiro', 'group' => 'Contabilista e pagamentos', 'label' => 'IBAN para os clientes', 'type' => 'text', 'max' => 40, 'default' => '',
                               'help' => 'Vai nos emails das faturas enviadas ao cliente e nos lembretes de pagamento.'],
        'fin_terms_days'   => ['tab' => 'financeiro', 'group' => 'Contabilista e pagamentos', 'label' => 'Prazo de pagamento por omissão', 'type' => 'int', 'min' => 0, 'max' => 180, 'unit' => 'dias', 'default' => 30,
                               'help' => 'O vencimento proposto nas faturas novas e nos pedidos ao contabilista (data da fatura + este prazo).'],
        'fin_owner'        => ['tab' => 'financeiro', 'group' => 'Contabilista e pagamentos', 'label' => 'Responsável financeiro', 'type' => 'select', 'opts' => settings_user_opts('O primeiro superadmin', 'financeiro'), 'default' => '',
                               'help' => 'Recebe as tarefas do calendário fiscal (DMR, Segurança Social, IVA, IRC, IES, Modelo 10), 10 dias antes de cada prazo.'],
        'fin_cash_owner'   => ['tab' => 'financeiro', 'group' => 'Tesouraria', 'label' => 'Responsável pelos saldos', 'type' => 'select', 'opts' => settings_user_opts('O responsável financeiro', 'financeiro'), 'default' => '',
                               'help' => 'Atualiza uma vez por semana o saldo das contas da empresa e recebe a tarefa no dia escolhido abaixo. Uma conta pode ter um responsável próprio (em Tesouraria › Contas), que fica com as tarefas dessa conta.'],
        'fin_cash_day'     => ['tab' => 'financeiro', 'group' => 'Tesouraria', 'label' => 'Dia da atualização', 'type' => 'select',
                               'opts' => ['1' => 'Segunda-feira', '2' => 'Terça-feira', '3' => 'Quarta-feira', '4' => 'Quinta-feira', '5' => 'Sexta-feira', '6' => 'Sábado', '7' => 'Domingo'], 'default' => '1',
                               'help' => 'Nesse dia, quem é responsável recebe a tarefa «Atualizar os saldos das contas». Um saldo com mais de 7 dias aparece como desatualizado na Tesouraria e nos alertas.'],
        'fin_categories'   => ['tab' => 'financeiro', 'group' => 'Categorias', 'label' => 'Categorias de despesa', 'type' => 'list', 'default' => implode("\n", FIN_CATS_DEFAULT_LIST),
                               'help' => 'As categorias dos lançamentos e dos custos fixos (os já lançados guardam a sua). Os custos fixos aparecem agrupados por elas na Visão geral.'],
        'fin_params'       => ['tab' => 'financeiro', 'group' => 'Taxas, limites e prazos', 'label' => 'Parâmetros legais e da empresa', 'type' => 'fin_params',
                               'help' => 'Todas as taxas e limites que os cálculos usam (Segurança Social, IRS, IVA, IRC, viaturas, amortizações, runway, calendário fiscal), cada um com a fonte legal, o ano e a data da última verificação. O contabilista confirma-os no início de cada ano. Mudam só superadmin e admins, com registo.'],
        'fin_irs'          => ['tab' => 'financeiro', 'group' => 'Tabelas de retenção do IRS', 'label' => 'Tabelas em uso', 'type' => 'fin_irs',
                               'help' => 'As tabelas de retenção na fonte do trabalho dependente, guardadas como dados (escalão, taxa, parcela a abater, parcela por dependente). Importam-se de novo a cada ano ou quando sair um despacho novo.'],

        // ── Drive ──
        'drive_base'       => ['tab' => 'drive', 'group' => 'Pastas', 'label' => 'Pastas base', 'type' => 'info', 'show' => 'Empresa/ · Leads/ · Projetos/ · Marketing/ · Biblioteca criativa/ · Showreel e Portfolio/ · Arquivo/',
                               'help' => 'As pastas de topo do Drive. Não se movem nem vão para o Lixo.'],
        'drive_deal'       => ['tab' => 'drive', 'group' => 'Pastas', 'label' => 'Pasta de cada negócio', 'type' => 'info', 'show' => 'Projetos/<ID>_<NOME>/ com Propostas/ · Faturação/ · Criativo/ · Marketing/ · Produção/ · Entregas/ · Lead/',
                               'help' => 'As subpastas nascem com o primeiro ficheiro. Negócio perdido ou lead arquivada → Arquivo/; reabertos voltam. A Faturação só aparece a quem vê o Financeiro.'],
        'drive_reorg'      => ['tab' => 'drive', 'group' => 'Pastas', 'label' => 'Reorganizar o que já existia', 'type' => 'link', 'href' => '/admin/reorganizar.php', 'show' => 'Reorganizar o Drive',
                               'help' => 'Pré-visualização do que vai mudar (negócios perdidos para o Arquivo, faturas antigas, peças de marketing, originais do Centro Criativo) e execução em segundo plano. Só administradores.'],

        // ── Integrações ──
        'int_meet'         => ['tab' => 'integracoes', 'group' => 'Reuniões', 'label' => 'Google Meet', 'type' => 'info', 'show' => '',
                               'help' => 'Cria o link do Meet nas reuniões. As credenciais da Google ficam só no servidor, fora do Git, e nunca vão para o browser.'],
        'discord_invite_url' => ['tab' => 'integracoes', 'group' => 'Reuniões', 'label' => 'Discord (link do canal)', 'type' => 'url', 'max' => 300, 'default' => '', 'pattern' => '~^https://(discord\.gg|discord\.com)/\S+$~',
                               'help' => 'O link de convite do canal de voz da topper., que vai nos convites das reuniões por Discord.'],
        'mail_from_name'   => ['tab' => 'integracoes', 'group' => 'Emails automáticos', 'label' => 'Nome nos emails automáticos', 'type' => 'text', 'max' => 80, 'default' => 'topper.',
                               'help' => 'Só nos emails que a plataforma envia sozinha (recuperar a palavra-passe, confirmações ao cliente, avisos do cron). Os que alguém envia pela plataforma levam o nome dessa pessoa e saem do email dela ou do email geral (escolha no Perfil).'],
        'mail_from'        => ['tab' => 'integracoes', 'group' => 'Emails automáticos', 'label' => 'Endereço dos emails automáticos', 'type' => 'email', 'max' => 190, 'default' => 'no-reply@topperdigital.xyz', 'domain' => 'topperdigital.xyz',
                               'help' => 'Tem de ser um endereço @topperdigital.xyz (o servidor só envia em nome do domínio).'],
        'mail_general'     => ['tab' => 'integracoes', 'group' => 'Email', 'label' => 'Email geral', 'type' => 'email', 'max' => 190, 'default' => 'info@topperdigital.xyz', 'domain' => 'topperdigital.xyz',
                               'help' => 'O email da empresa. Quem envia pela plataforma escolhe (no Perfil, ou em cada envio) se o email sai do seu próprio email ou deste; o nome é sempre o da pessoa. A palavra-passe escreve-se em «Estado das caixas» e fica só no servidor.'],
        'fin_mail_boxes'   => ['tab' => 'integracoes', 'group' => 'Email', 'label' => 'Caixas a ler', 'type' => 'list', 'default' => '', 'empty_ok' => true, 'emails' => true,
                               'help' => 'As caixas de email existentes da topper. no Hostinger onde chegam faturas (ex.: info@topperdigital.xyz), uma por linha. São lidas a cada 5 minutos, só para leitura: nada é marcado como lido, movido ou apagado. A palavra-passe de cada uma escreve-se em «Estado das caixas»: é testada e fica só no servidor (nunca na base de dados nem nesta página).'],
        'int_fin_mail'     => ['tab' => 'integracoes', 'group' => 'Email', 'label' => 'Estado das caixas', 'type' => 'fin_mail',
                               'help' => 'Só se analisam as mensagens com anexo PDF, XML ou imagem: primeiro o QR code fiscal / ATCUD (sem custo), a IA só quando não há QR claro. Faturas passadas à topper. entram em «A pagar» por confirmar; as emitidas pela topper. ligam-se ao pedido «Fatura pedida»; o resto é ignorado. O corpo dos emails nunca é guardado.'],
        'fin_mail_ignore'  => ['tab' => 'integracoes', 'group' => 'Email', 'label' => 'Remetentes a ignorar', 'type' => 'list', 'default' => '', 'empty_ok' => true,
                               'help' => 'Um endereço (newsletter@loja.pt) ou um domínio (@loja.pt) por linha. As mensagens destes remetentes nem chegam a ser descarregadas.'],
        'int_ai'           => ['tab' => 'integracoes', 'group' => 'IA', 'label' => 'Claude (Anthropic)', 'type' => 'ai',
                               'help' => 'O estado, o uso e o custo da IA do Estúdio e do assistente do Drive. A chave fica só no servidor.'],
        'int_cc_ai'        => ['tab' => 'integracoes', 'group' => 'IA', 'label' => 'Centro Criativo (Claude + embeddings + transcrição)', 'type' => 'cc_ai',
                               'help' => 'A IA de qualidade máxima do Centro Criativo: o modelo Claude mais capaz, embeddings multimodais pagos e o serviço de fala que transcreve áudio e vídeo com marcas de tempo. Chaves só no servidor (admin/inc/cc_config.php); gasto e limite à parte do assistente.'],
        'int_rt'           => ['tab' => 'integracoes', 'group' => 'Tempo real (quadros do Centro Criativo)', 'label' => 'Serviço de tempo real', 'type' => 'rt',
                               'help' => 'Cloudflare Workers + Durable Objects, no plano gratuito (100 000 pedidos e 13 000 GB-s por dia, repõe às 00:00 UTC). Os admins são avisados aos 80%. Se o limite for atingido, o quadro continua a funcionar no browser e sincroniza depois.'],
        'rt_url'           => ['tab' => 'integracoes', 'group' => 'Tempo real (quadros do Centro Criativo)', 'label' => 'Endereço do serviço', 'type' => 'url', 'max' => 200, 'default' => '', 'pattern' => '~^https://[a-z0-9.-]+/?$~i',
                               'help' => 'O endereço do Worker depois de publicado: https://rt.topperhub.xyz (se o domínio estiver na Cloudflare) ou https://topper-rt.<conta>.workers.dev. Vazio: os quadros atualizam-se como antes (a cada 8 s).'],
        'rt_enabled'       => ['tab' => 'integracoes', 'group' => 'Tempo real (quadros do Centro Criativo)', 'label' => 'Tempo real nos quadros', 'type' => 'select', 'opts' => ['1' => 'Ligado', '0' => 'Desligado (atualiza a cada 8 s)'], 'default' => '1',
                               'help' => 'Desligar não apaga nada: os quadros voltam a atualizar-se a cada 8 s.'],
        'int_tokens'       => ['tab' => 'integracoes', 'group' => 'Captura (extensão e telemóvel)', 'label' => 'Tokens pessoais', 'type' => 'tokens',
                               'help' => 'Os tokens que cada pessoa criou em Perfil › Ligações para a extensão «Guardar na topper.» e o Atalho do iOS. Os admins podem revogar qualquer um; a pessoa cria outro quando precisar. Nunca se guarda a palavra-passe.'],
        'ios_shortcut_url' => ['tab' => 'integracoes', 'group' => 'Captura (extensão e telemóvel)', 'label' => 'Atalho do iOS (link do iCloud)', 'type' => 'url', 'max' => 300, 'default' => '', 'pattern' => '~^https://(www\.)?icloud\.com/shortcuts/\S+$~',
                               'help' => 'Opcional: o link de partilha do Atalho «Guardar na topper.» depois de criado uma vez num iPhone (Perfil › Ligações explica os passos). Com o link, as outras pessoas instalam-no com um toque.'],
        'int_future'       => ['tab' => 'integracoes', 'group' => 'Futuras', 'label' => 'Por ligar', 'type' => 'info', 'show' => 'Meta (Instagram) · LinkedIn · software de faturação certificado',
                               'help' => 'Ligam-se aqui quando existirem.'],

        // ── Segurança ──
        'session_idle_min' => ['tab' => 'seguranca', 'group' => 'Sessão', 'label' => 'Terminar sessão inativa ao fim de', 'type' => 'int', 'min' => 5, 'max' => 480, 'unit' => 'min', 'default' => min(480, (int)($GLOBALS['CONFIG']['idle_timeout_minutes'] ?? 60)),
                               'help' => 'Sem atividade durante este tempo, a sessão termina e é preciso entrar outra vez.'],
        'session_max_hours'=> ['tab' => 'seguranca', 'group' => 'Sessão', 'label' => 'Duração máxima da sessão', 'type' => 'int', 'min' => 1, 'max' => 72, 'unit' => 'h', 'default' => (int)($GLOBALS['CONFIG']['absolute_timeout_hours'] ?? 12),
                               'help' => 'Mesmo com atividade, a sessão termina ao fim deste tempo.'],
        'login_max_attempts' => ['tab' => 'seguranca', 'group' => 'Entrada', 'label' => 'Bloquear após', 'type' => 'int', 'min' => 3, 'max' => 20, 'unit' => 'tentativas falhadas', 'default' => (int)($GLOBALS['CONFIG']['max_attempts'] ?? 6),
                               'help' => 'Depois deste número de palavras-passe erradas seguidas, a entrada fica bloqueada por um período.'],
        'two_factor'       => ['tab' => 'seguranca', 'group' => 'Entrada', 'label' => 'Autenticação de dois passos', 'type' => 'info', 'show' => 'Não usada',
                               'help' => 'Decisão da empresa: a entrada é só com email e palavra-passe.'],
    ];
    // o prazo da primeira resposta por canal (os formulários e os anúncios respondem-se mais depressa)
    $ch = [];
    foreach (LEAD_ORIGIN as $k => $label) {
        if ($k === 'talento') continue;
        $ch['sla_hours_' . $k] = ['tab' => 'leads', 'group' => 'Prazo da primeira resposta (horas úteis)', 'label' => $label, 'type' => 'num', 'min' => 0.25, 'max' => 200,
                                  'unit' => 'h úteis', 'default' => LEAD_SLA_DEFAULT[$k] ?? 4, 'help' => 'O prazo da primeira resposta às leads deste canal (Relatórios › Velocidade e Atenção).'];
    }
    $pos = array_search('sla_hours', array_keys($d), true) + 1;
    $d = array_slice($d, 0, $pos, true) + $ch + array_slice($d, $pos, null, true);
    return $d;
}

function setting_def(string $key): ?array {
    return settings_defs()[$key] ?? null;
}

/** O valor atual (texto), ou o de origem. */
function setting_get(PDO $pdo, string $key): string {
    $d = setting_def($key);
    $st = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? (string)($d['default'] ?? '') : (string)$v;
}

/** As linhas de uma definição do tipo lista (com o valor de origem se nunca mudou). */
function setting_list(PDO $pdo, string $key): array {
    return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', setting_get($pdo, $key))), fn($x) => $x !== ''));
}

/** «72 h», «65%», «Segunda a sexta». */
function setting_show(array $d, string $v): string {
    if ($d['type'] === 'select') return (string)($d['opts'][$v] ?? $v);
    if ($d['type'] === 'list') { $n = count(array_filter(array_map('trim', explode("\n", $v)))); return $n . ($n === 1 ? ' item' : ' itens'); }
    if (in_array($d['type'], ['textarea'], true)) return $v === '' ? '(vazio)' : mb_strimwidth(preg_replace('/\s+/', ' ', $v), 0, 60, '…');
    if ($v === '') return '(vazio)';
    if ($d['type'] === 'num') $v = rtrim(rtrim(number_format((float)$v, 2, ',', ' '), '0'), ',');
    $u = (string)($d['unit'] ?? '');
    return $v . ($u === '%' ? '%' : ($u !== '' ? ' ' . $u : ''));
}

/** O que mudou numa lista: «+ Marca · − Empresa Particular» (vazio se só mudou a ordem). */
function setting_list_diff(string $old, string $new): string {
    $split = fn($v) => array_values(array_filter(array_map('trim', explode("\n", $v)), fn($x) => $x !== ''));
    $a = $split($old); $b = $split($new);
    $parts = [];
    foreach (array_diff($b, $a) as $x) $parts[] = '+ ' . $x;
    foreach (array_diff($a, $b) as $x) $parts[] = '− ' . $x;
    if (!$parts && $a !== $b) return 'ordem alterada';
    return mb_strimwidth(implode(' · ', $parts), 0, 90, '…');
}

/** Valida e normaliza o que foi escrito; o texto a guardar. */
function setting_clean(array $d, string $raw): string {
    $raw = str_replace("\r", '', $raw);
    switch ($d['type']) {
        case 'int':
            $t = trim($raw);
            if (!preg_match('/^-?\d+$/', $t)) throw new RuntimeException('Escreva um número inteiro.');
            $n = (int)$t;
            if ($n < $d['min'] || $n > $d['max']) throw new RuntimeException('Tem de estar entre ' . $d['min'] . ' e ' . $d['max'] . '.');
            return (string)$n;
        case 'num':
            $t = str_replace([' ', '€', '%'], '', trim($raw));
            if (str_contains($t, ',')) $t = str_replace(['.', ','], ['', '.'], $t);
            if (!is_numeric($t)) throw new RuntimeException('Escreva um número.');
            $n = round((float)$t, 2);
            if ($n < $d['min'] || $n > $d['max']) throw new RuntimeException('Tem de estar entre ' . $d['min'] . ' e ' . $d['max'] . '.');
            return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        case 'time':
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($raw))) throw new RuntimeException('Escreva a hora como 09:00.');
            return trim($raw);
        case 'select':
            if (!isset($d['opts'][$raw])) throw new RuntimeException('Escolha uma das opções.');
            return $raw;
        case 'list':
            $lines = [];
            foreach (preg_split('/\n/', $raw) as $l) {
                $l = trim(mb_substr($l, 0, 80));
                if ($l !== '' && !in_array(mb_strtolower($l), array_map('mb_strtolower', $lines), true)) $lines[] = $l;
            }
            if (!empty($d['emails'])) foreach ($lines as $l) if (!filter_var($l, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('«' . $l . '» não é um endereço de email.');
            if (!$lines && empty($d['empty_ok'])) throw new RuntimeException('A lista precisa de pelo menos uma linha.');
            return implode("\n", array_slice($lines, 0, 60));
        case 'email':
            $t = trim($raw);
            if (!filter_var($t, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Escreva um email válido.');
            if (!empty($d['domain']) && !str_ends_with(mb_strtolower($t), '@' . $d['domain'])) throw new RuntimeException('Tem de ser um endereço @' . $d['domain'] . '.');
            return $t;
        case 'url':
            $t = trim($raw);
            if ($t !== '' && !preg_match($d['pattern'] ?? '~^https://\S+$~', $t)) throw new RuntimeException('Escreva o link completo (https://…).');
            return mb_substr($t, 0, $d['max'] ?? 300);
        case 'text':
        case 'textarea':
            $t = trim(mb_substr($raw, 0, $d['max'] ?? 500));
            if (!empty($d['required']) && $t === '') throw new RuntimeException('Não pode ficar vazio.');
            return $t;
    }
    throw new RuntimeException('Esta definição não se muda aqui.');
}

/** O que muda em dados que já existem (aviso antes de gravar). '' = nada a avisar. */
function setting_impact(PDO $pdo, string $key, string $old, string $new): string {
    $d = setting_def($key);
    switch ($d['impact'] ?? '') {
        case 'lead_deadline':
            $state = substr($key, strlen('lead_deadline_'));
            $st = $pdo->prepare("SELECT * FROM deals WHERE phase = 'lead' AND is_quarantined = 0 AND lead_state = ?");
            $st->execute([$state]);
            $rows = $st->fetchAll();
            if (!$rows) return '';
            $hours = lead_deadline_hours($pdo);
            $before = count(array_filter($rows, fn($l) => ($c = lead_clock($l, $hours)) && $c['level'] === 'late'));
            $hours[$state] = (int)$new;
            $after = count(array_filter($rows, fn($l) => ($c = lead_clock($l, $hours)) && $c['level'] === 'late'));
            $diff = $after - $before;
            return 'Mudar o prazo de ' . $d['label'] . ' de ' . $old . ' h para ' . $new . ' h recalcula ' . count($rows) . (count($rows) === 1 ? ' lead ativa' : ' leads ativas')
                 . ($diff < 0 ? ' (' . -$diff . ' deixa' . (-$diff === 1 ? '' : 'm') . ' de estar em atraso)' : ($diff > 0 ? ' (' . $diff . ' passa' . ($diff === 1 ? '' : 'm') . ' a estar em atraso)' : '')) . '.';
        case 'cadence':
            if (!db_has_table($pdo, 'relations')) return '';
            $level = strtoupper(substr($key, -1));
            $st = $pdo->prepare('SELECT COUNT(*) FROM relations WHERE level = ? AND cadence_days IS NULL AND paused_until IS NULL');
            try { $st->execute([$level]); $n = (int)$st->fetchColumn(); } catch (Throwable $e) { $n = 0; }
            return $n ? 'Muda a data do próximo toque de ' . $n . ($n === 1 ? ' relação' : ' relações') . ' de nível ' . $level . ' (as que não têm exceção).' : '';
        case 'vat':
            $n = (int)$pdo->query("SELECT COUNT(*) FROM deals WHERE phase IN ('pipeline','production')")->fetchColumn();
            return 'Muda os valores c/ IVA mostrados em ' . $n . ($n === 1 ? ' negócio ativo' : ' negócios ativos') . ', nas propostas por enviar e no Financeiro. As propostas já enviadas guardam o valor que o cliente viu.';
        case 'stale':
            require_once __DIR__ . '/alerts.php';
            $rows = $pdo->query("SELECT * FROM deals WHERE phase = 'pipeline'")->fetchAll();
            $count = fn(int $days) => count(array_filter($rows, fn($x) => days_since(deal_last_move($x)) > $days));
            $a = $count((int)$old); $b = $count((int)$new);
            return $a === $b ? '' : 'Os negócios «em risco» passam de ' . $a . ' para ' . $b . '.';
    }
    return '';
}

/**
 * Grava uma definição (só superadmin e admins). Sem $confirm, uma alteração com
 * impacto em dados existentes devolve o aviso (SettingImpact) em vez de gravar.
 * Devolve [mensagem, valor mostrado, token de «Anular»].
 */
function setting_save(PDO $pdo, string $key, string $raw, bool $confirm = false, string $why = ''): array {
    if (!is_admin()) throw new RuntimeException('Só superadmin e admins mudam as Definições.');
    $d = setting_def($key);
    if (!$d || in_array($d['type'], ['info', 'link', 'services', 'templates', 'types', 'origins', 'pillars', 'ai', 'fin_params', 'fin_irs', 'fin_mail'], true)) throw new RuntimeException('Esta definição não se muda aqui.');
    $old = setting_get($pdo, $key);
    $new = setting_clean($d, $raw);
    if ($new === $old) return ['Sem alterações.', setting_show($d, $new), ''];
    if ($key === 'work_end' && $new <= setting_get($pdo, 'work_start')) throw new RuntimeException('O fim do dia tem de ser depois do início.');
    if ($key === 'work_start' && $new >= setting_get($pdo, 'work_end')) throw new RuntimeException('O início do dia tem de ser antes do fim.');
    if (!$confirm && ($imp = setting_impact($pdo, $key, $old, $new)) !== '') throw new SettingImpact($imp);
    setting_write($pdo, $key, $new);
    setting_log($pdo, $key, $d['tab'] !== '' ? SET_TABS[$d['tab']][0] . ' · ' . $d['label'] : $d['label'], $old, $new);
    require_once __DIR__ . '/undo.php';
    $token = undo_push('setting', ['key' => $key, 'from' => $old, 'to' => $new]);
    $msg = $why !== '' ? $why : ($d['type'] === 'list' ? $d['label'] . ': ' . setting_list_diff($old, $new) : $d['label'] . ' alterado para ' . setting_show($d, $new));
    return [$msg, setting_show($d, $new), $token];
}

function setting_write(PDO $pdo, string $key, string $value): void {
    $pdo->prepare(db_is_sqlite($pdo)
        ? 'INSERT OR REPLACE INTO settings (skey, svalue) VALUES (?, ?)'
        : 'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute([$key, $value]);
}

function setting_log(PDO $pdo, string $key, string $label, ?string $old, ?string $new): void {
    if (!db_has_table($pdo, 'settings_log')) return;
    $pdo->prepare('INSERT INTO settings_log (skey, label, old_value, new_value, user_id, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$key, mb_substr($label, 0, 160), $old, $new, current_user()['id'] ?? null, date('Y-m-d H:i:s')]);
}

/** O registo de alterações, do mais recente. */
function settings_log_rows(PDO $pdo, int $limit = 100, string $key = ''): array {
    if (!db_has_table($pdo, 'settings_log')) return [];
    $st = $pdo->prepare('SELECT l.*, u.name FROM settings_log l LEFT JOIN users u ON u.id = l.user_id' . ($key !== '' ? ' WHERE l.skey = ?' : '') . ' ORDER BY l.id DESC LIMIT ' . max(1, $limit));
    $st->execute($key !== '' ? [$key] : []);
    return $st->fetchAll();
}

/** O índice da pesquisa (nome, grupo, separador, frase) de todas as definições. */
function settings_index(): array {
    $out = [];
    foreach (settings_defs() as $k => $d) {
        $out[] = ['k' => $k, 't' => $d['tab'], 'tab' => SET_TABS[$d['tab']][0], 'g' => $d['group'], 'l' => $d['label'], 'h' => $d['help']];
    }
    return $out;
}
