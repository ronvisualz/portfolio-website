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

/** Os separadores (um por aba com campos editáveis). Nova aba com definições = novo separador aqui. */
const SET_TABS = [
    'geral'        => ['Geral', 'dashboard'],
    'leads'        => ['Leads', 'leads'],
    'pipeline'     => ['Pipeline', 'pipeline'],
    'contactos'    => ['Contactos', 'contactos'],
    'orcamentacao' => ['Orçamentação', 'orcamentacao'],
    'negocios'     => ['Negócios', 'negocios'],
    'tarefas'      => ['Tarefas', 'tarefas'],
    'marketing'    => ['Marketing', 'marketing'],
    'financeiro'   => ['Financeiro', 'financeiro'],
    'drive'        => ['Drive', 'drive'],
    'integracoes'  => ['Integrações', 'link'],
    'seguranca'    => ['Segurança', 'eye'],
];

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

        // ── Pipeline ──
        'flow_editor'        => ['tab' => 'pipeline', 'group' => 'Funis, etapas e automações', 'label' => 'Funis, etapas, gates, automações e X-Ray', 'type' => 'link', 'href' => '/admin/funis.php',
                                 'show' => 'Abrir o editor', 'help' => 'Os funis e as etapas, os requisitos para avançar (gates), as tarefas automáticas de cada etapa, as perguntas do X-Ray por funil e as rondas de revisão incluídas.'],
        'xsell_editor'       => ['tab' => 'pipeline', 'group' => 'Crosselling', 'label' => 'Pacotes e crosselling depois da entrega', 'type' => 'link', 'href' => '/admin/funis.php?x=1',
                                 'show' => 'Abrir', 'help' => 'Os pacotes pré-definidos (serviços, sincronização das frentes e modelo de orçamento) e as regras «depois de entregar X, propor Y ao fim de N dias».'],
        'lost_reasons'       => ['tab' => 'pipeline', 'group' => 'Propostas e perdas', 'label' => 'Motivos de perda', 'type' => 'list', 'default' => implode("\n", LOST_REASONS),
                                 'help' => 'Os motivos a escolher ao marcar um negócio como perdido. «Outro» deixa escrever o detalhe.'],
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

        // ── Financeiro ──
        'fin_todo'         => ['tab' => 'financeiro', 'group' => 'Financeiro', 'label' => 'Definições do Financeiro', 'type' => 'info', 'show' => 'A definir quando a aba for desenhada.',
                               'help' => 'O IVA está em Geral. A faturação legal será ligada a um software certificado pela AT.'],

        // ── Drive ──
        'drive_base'       => ['tab' => 'drive', 'group' => 'Pastas', 'label' => 'Pastas base', 'type' => 'info', 'show' => 'Projetos/ · Empresa/ · Marketing/',
                               'help' => 'As pastas de topo do Drive.'],
        'drive_deal'       => ['tab' => 'drive', 'group' => 'Pastas', 'label' => 'Pasta de cada negócio', 'type' => 'info', 'show' => 'Projetos/<ID>_<NOME>/ com Faturas/ (documentos submetidos)',
                               'help' => 'A estrutura que a plataforma cria para cada negócio. Mudá-la exige mover as pastas existentes, por isso fica fixa por agora.'],

        // ── Integrações ──
        'int_meet'         => ['tab' => 'integracoes', 'group' => 'Reuniões', 'label' => 'Google Meet', 'type' => 'info', 'show' => '',
                               'help' => 'Cria o link do Meet nas reuniões. As credenciais da Google ficam só no servidor, fora do Git, e nunca vão para o browser.'],
        'discord_invite_url' => ['tab' => 'integracoes', 'group' => 'Reuniões', 'label' => 'Discord (link do canal)', 'type' => 'url', 'max' => 300, 'default' => '', 'pattern' => '~^https://(discord\.gg|discord\.com)/\S+$~',
                               'help' => 'O link de convite do canal de voz da topper., que vai nos convites das reuniões por Discord.'],
        'mail_from_name'   => ['tab' => 'integracoes', 'group' => 'Email de envio', 'label' => 'Nome do remetente', 'type' => 'text', 'max' => 80, 'default' => 'topper.',
                               'help' => 'O nome que aparece nos emails enviados pela plataforma (propostas, convites, reuniões).'],
        'mail_from'        => ['tab' => 'integracoes', 'group' => 'Email de envio', 'label' => 'Endereço do remetente', 'type' => 'email', 'max' => 190, 'default' => 'no-reply@topperdigital.xyz', 'domain' => 'topperdigital.xyz',
                               'help' => 'Tem de ser um endereço @topperdigital.xyz (o servidor só envia em nome do domínio).'],
        'int_ai'           => ['tab' => 'integracoes', 'group' => 'IA', 'label' => 'Claude (Anthropic)', 'type' => 'ai',
                               'help' => 'O estado, o uso e o custo da IA do Estúdio e do assistente do Drive. A chave fica só no servidor.'],
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
            if (!$lines) throw new RuntimeException('A lista precisa de pelo menos uma linha.');
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
    if (!$d || in_array($d['type'], ['info', 'link', 'services', 'templates', 'types', 'origins', 'pillars', 'ai'], true)) throw new RuntimeException('Esta definição não se muda aqui.');
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
