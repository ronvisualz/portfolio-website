# Pipeline v2 — Fase 1: frentes, motor de avanço e regras — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> Cada tarefa é feita por um agente diferente. Dois agentes nunca mexem no mesmo ficheiro ao mesmo tempo: as tarefas
> que partilham um ficheiro estão em sequência (ver «Ordem»). **Ninguém faz commit nem push**: o orquestrador faz o
> commit no fim da fase. Nada vai para o main nem para a Hostinger.

**Goal:** Pôr a funcionar, só nos negócios v2, as frentes em `deal_lines`, o motor de avanço (frente e negócio), as
dependências e os prazos, as regras de dois níveis com deduplicação, a avença, o «Anular» em cascata, a leitura por
frente, o «Em risco» e as métricas v2 — tudo com testes e sem que nenhum negócio real mude de comportamento.

**Architecture:** A lógica nova vive em 15 ficheiros novos `admin/inc/pipe2_*.php`, um por responsabilidade. Os
ficheiros existentes ganham ganchos de uma ou duas linhas que só atuam num negócio v2 (`pipe2_deal_is_v2`) e que, num
negócio v1, não fazem nada (cada tarefa prova-o, e a tarefa 17 compara um cenário v1 inteiro com o código da fase 0).
O motor nunca decide por permissões nem pela sessão: os movimentos automáticos ficam como «Sistema». Um passo 44
acrescenta a tabela dos avisos, a ação e o N das regras e o «gate partilhado» das tarefas.

**Tech Stack:** PHP 8 sem framework; MySQL em produção sem modo estrito; MariaDB 10.11 na bancada. Testes PHP simples
em `tests/*_test.php` (`tests/lib.php`: `t_true`, `t_eq`, `t_done`, `tests_http`), sobre bases privadas
(`tests/pipe2_lib.php`, `tests/pipe2_v2_lib.php`).

**Spec:** `docs/ESPECIFICACAO-PLATAFORMA.md` §34 (**§34.11 prevalece**; §34.3, 34.4, 34.6, 34.9 e 34.10 são o detalhe),
§15.1/§15.5, §19, §22, §10.33; notas `docs/superpowers/plans/2026-11-05-pipeline-v2-notas.md` (nota 00 = §34.11 e nota
0 prevalecem); plano geral `docs/superpowers/plans/2026-11-05-pipeline-v2.md` (colunas «Fase 1» da rastreabilidade,
«Decisões tomadas»); plano e código da fase 0 (`2026-11-05-pipeline-v2-fase0.md`, `admin/inc/migrate_pipeline2.php`,
`admin/inc/pipe2.php`, `tests/pipe2_lib.php`, `tests/pipe2_*_test.php`).

## Global Constraints

- **Regra de ouro.** Até ao «Aplicar» (fase 3) nenhum negócio real muda de comportamento. Cada gancho num ficheiro
  existente começa por `pipe2_deal_is_v2(...)` (ou por um estado de frente v2, numa função pura) e, num negócio v1,
  devolve o resultado de antes. Cada tarefa tem um caso `::v1_noop`; a tarefa 17 prova o conjunto contra o código da
  fase 0 (8c581ae).
- **Sem interface nova.** Nenhum ecrã, botão, campo de formulário, opção de Definições nem ação POST nova. As mensagens
  precisas (ex.: «Pronto para a Entrega — falta: …») são texto em pt-PT devolvido pelas funções (`pipe2_note()`), sem
  «§». Os eventos novos do histórico (`p2_*`) recebem rótulo na fase 2.
- **Os funis reais não mudam.** Os 4 funis ativos não recebem «Em execução»/«Entrega» nesta fase. `funnel_create` só
  cria as 6 etapas comuns com `pipe2_on()` ligado ou com o parâmetro explícito dos testes. `deal_stale_days` fica 10,
  `LEAD_SERVICES_LIST`, `FLOW_TRIGGERS`, `FLOW_ASSIGN`, `XSELL_STATUS` e `MX_GOAL_METRICS` não mudam. Nunca gravar
  `pipeline_v2_at` na bancada `topper` (só numa base privada ou numa transação desfeita).
- **Esquema:** passo 44 num ficheiro próprio (`admin/inc/migrate_pipeline2_44.php`), no fim de `run_migrations`,
  `SCHEMA_VERSION = 44`; só `db_add_column`/`db_create`/`db_has_column`/`db_has_table`; nada de UNIQUE, ENUM nem NOT
  NULL sem DEFAULT; idempotente; GET_LOCK com o mesmo nome do 43 (`'topper_pipe2_' . DATABASE()`). A guarda dos leitores
  continua a ser `pipe2_schema_ready` (`pipe2_seeded`); as colunas do 44 só se leem dentro dos ramos v2. Os acessos
  ao esquema da v2 (43 ou 44) que correm também no caminho v1 são exatamente quatro, e cada um tem a sua guarda: a
  lista dos avisos (`pipe2_notices_for`: `db_has_table`); o registo das eliminações na Agenda
  (`pipe2_task_delete_human`: `pipe2_schema_ready` e `db_has_table('task_deletions')`, INSERT em try/catch com
  `error_log`, o DELETE corre sempre); o pedido da tarefa em `fin_from_billing` (`$taskId !== null` e
  `db_has_column('fin_docs','task_id')`); e a reatribuição da equipa (`pipe2_team_reassign`: `pipe2_schema_ready`,
  `db_has_table('deal_line_execs')`, gancho em try/catch). Os três últimos têm um caso `::v1_at_42` numa base na 42
  (`pipe2_tmpdb_create(pipe2_base_dump())`, sem migrar: `pipe2_noise_test`, `pipe2_reassign_test`,
  `pipe2_invoice_test`) e o dos avisos o `pipe2_notify_test::v1_noop` (base sem a tabela): a ação v1 faz o mesmo de
  hoje, sem exceção nem aviso de PHP.
  Nenhum outro gancho lê colunas ou tabelas da v2 antes de `pipe2_deal_is_v2` (que começa por `pipe2_schema_ready`).
- **Larguras** (a produção corta sem avisar): `trig`/`status` ≤ 10, `doc_kind`/`repeat_rule`/`source`/`action` ≤ 8,
  `step` ≤ 12, `deal_history.event` ≤ 20, `assign_to` ≤ 20. Todos os códigos novos entram em `PIPE2_CODES`
  (`tests/pipe2_codes_test.php::widths`).
- **SQL compatível com `ONLY_FULL_GROUP_BY`**; as consultas agregadas novas têm um caso em modo estrito.
- **Includes.** Cada ficheiro que chama `pipe2_*()` tem `require_once __DIR__ . '/pipe2.php';` (ou `'/inc/pipe2.php'`)
  no topo (`tests/pipe2_includes_test.php`) e faz `require_once` do `pipe2_*.php` que define a função que chama — nos
  ficheiros existentes, dentro da função, logo antes da chamada (para a v1 não carregar o motor e para evitar ciclos de
  include). Os ficheiros `pipe2_*.php` novos têm a guarda `if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }`
  e o cabeçalho «topper. admin — Pipeline v2: …».
- **O motor** (`pipe2_engine.php`, `pipe2_deal.php`, `pipe2_tick.php`, `pipe2_billing.php`) nunca chama `can()`,
  `require_perm()`, `is_admin()` nem `record_reach()` para decidir um movimento automático: o cron e o portal não têm
  sessão. As permissões das ações humanas ficam em `pipe2_access.php` e nos pontos de entrada (fase 2). Exceções
  explícitas (como na v1): forçar uma passagem e mudar responsáveis de frentes exigem `is_admin()`.
- **Transações.** `deal_move` abre a sua; o motor nunca o chama com uma transação aberta (`$pdo->inTransaction()`):
  adia o movimento do negócio e o relógio apanha-o. Os ganchos de reavaliação correm depois do commit de quem chamou e
  nunca anulam a ação que os chamou (try/catch com `error_log('[topper] pipe2 …')`).
- **Permissões e euros:** sempre no servidor. A margem por frente é dado do Financeiro: quem chama só a pede com
  `can('financeiro')`. «Sem Financeiro vê-se o estado, não o NIF.» security-review (na fase de revisão do workflow) em
  `pipe2_access.php`, `pipe2_billing.php` (pedido de fatura) e `pipe2_metrics.php` (margem).
- **Topperspace fora** desta fase. Sem identificadores de modelos no código. Código, comentários e textos em pt-PT.
- **Padrão dos testes:** `require_once __DIR__ . '/boot.php'; require_once __DIR__ . '/pipe2_lib.php';
  require_once __DIR__ . '/pipe2_v2_lib.php'; [$db, $tp] = p2v_db();` — base privada do dump da 42, migrada num
  subprocesso até `SCHEMA_VERSION`, com `pipe2_use($tp)`. Os funis, negócios e regras de cada teste criam-se **todos
  antes** da primeira leitura de `flow_order`/`stage_match`/`mx_stages` (caches estáticas). Testes só com bases
  privadas não pedem `pipe2_lock`; os que escrevem na bancada (HTTP) pedem `pipe2_lock($pdo)` e só usam funis
  `P2T · ` sem `service_code` (`p2v_bench_funnel`). Os casos de permissões correm num subprocesso por utilizador
  (`p2v_probe`). Entre «pedidos» no mesmo processo, `pipe2_request_reset()`.
- **Ficheiros de teste partilhados:** `tests/pipe2_lib.php` e `tests/pipe2_v2_lib.php` só mudam na tarefa 1; as
  outras tarefas põem helpers novos no próprio teste. A bancada é partilhada por agentes em paralelo: cada ficheiro
  existente tem de ficar sempre sintaticamente válido (`php -l` depois de cada edição); uma falha HTTP transitória
  repete-se uma vez antes de se investigar.
- **Ordem de publicação** (fica na §10.34, tarefa 17). Pré-condição: a fase 0 publicada **e migrada** no servidor
  (`schema_version` ≥ 43 e `settings.pipe2_seeded` preenchido — confirmar antes de enviar o primeiro ficheiro). Se não
  estiver, publicam-se as duas juntas e a ordem começa por `inc/migrate_pipeline2.php` (passo 43). Depois:
  `inc/pipe2.php` → os 15 `inc/pipe2_*.php` novos → os ficheiros com ganchos → `inc/migrate_pipeline2_44.php` →
  `inc/migrate.php` por último. Mesmo que a ordem falhe, os quatro acessos guardados acima mantêm a v1 a funcionar.
- **Reversão da fase 1:** repor o código da fase 0 (8c581ae) inteiro; a base fica na 44 (o passo 44 não se desfaz e a
  fase 0 ignora as colunas novas); antes do «Aplicar» não há negócios v2, por isso nada ficou em estados v2. Prova:
  `pipe2_rollback_test::phase1_rollback` (tarefa 1).

## Review Focus

1. **Conclusão dentro de uma transação aberta** (o `'multi'` do «Anular», ou quem chame `task_done` dentro de uma) → a
   frente pode avançar (UPDATE condicional), o negócio não chama `deal_move` (devolve `'deferred'`, o relógio apanha) e
   a transação de quem chamou continua intacta. Teste: tarefa 11, `pipe2_engine_test::deferred_in_transaction`.
2. **Sem sessão** (portal: `cc_rv_approve`; cron; email do contabilista: `fin_mail_register`) a fechar uma obrigatória
   → o avanço corre como «Sistema», sem avisos de PHP e sem `can()`. Teste: tarefa 10,
   `pipe2_autoadvance_test::no_session` (subprocesso sem `$_SESSION` que chama `task_done`).
3. **Reentrada** numa sub-etapa cujas tarefas já existem (depois de «Desfazer» ou de arrastar para trás e voltar a
   avançar) → as tarefas não se recriam e a frente não avança em cadeia no mesmo pedido. Teste: tarefa 10,
   `pipe2_autoadvance_test::reentry_no_duplicates`.
4. **Avença com dia N maior do que o mês** (N = 31 em fevereiro) e cron que falhou esse dia → a fatura do mês abre no
   último dia do mês, ou no primeiro cron depois, uma só vez; a fatura do mês em que a frente começa também abre (a
   fatura e o recomeço do ciclo são condições separadas, D15). Teste: tarefa 12,
   `pipe2_monthly_test::short_month_and_missed_day`, `::one_invoice_per_month`, `::due_functions`.
5. **Responsável automático inválido** (chefe do serviço desativado, superadmin escolhido como pessoa da regra, frente
   sem responsável) → «Por atribuir», nunca o superadmin nem uma conta desativada. Teste: tarefa 9,
   `pipe2_dedup_test::assignee_never_invalid`.
6. **Concorrência ao nível do negócio** (duas frentes do mesmo negócio ficam Prontas em dois pedidos ao mesmo tempo;
   cron e pedido ao mesmo tempo) → `deal_move` não é um UPDATE condicional, por isso a correção depende de
   `pipe2_lock_deal`, de reler o negócio e as frentes **depois** do lock e de não mover quando `deals.stage_id` já não
   é o esperado: exatamente 1 movimento Em execução → Entrega e as regras `all_ready` e de fecho criadas uma vez.
   Testes: tarefa 11, `pipe2_engine_test::concurrent_fronts_ready`; tarefa 13, `pipe2_tick_test::cron_and_request`
   (5 rondas cada, com negócios novos, para não depender de uma só corrida).
7. **Dois `deal_move` para o Kickoff ao mesmo tempo** (duplo clique, duas pessoas, ou a validação da aceitação e um
   arrastar) → os dois fazem o UPDATE e os dois correm `flow_on_enter` (o `deal_move` da v1 não é condicional e não
   muda nesta fase). A correção está em `pipe2_on_deal_enter`, que corre inteiro dentro de `pipe2_lock_deal`, e em
   `pipe2_kickoff`, que **depois do lock** abre a sua transação, faz `SELECT id FROM deals WHERE id = ? FOR UPDATE`,
   relê o negócio e as frentes e só cria o que falta, com commit antes de soltar o lock: as frentes, as 6 linhas de
   passos por frente, as ligações e as tarefas `enter` do Kickoff nascem uma vez e o UNIQUE `deal_id, funnel_id` de
   `deal_lines` nunca rebenta. O mesmo vale para `pipe2_step_row($create = true)`, que toma ele próprio o lock
   (reentrante). Testes: tarefa 11, `pipe2_engine_test::kickoff_race` (5 rondas, negócios novos) e
   `::kickoff_reread_after_lock`; tarefa 10, `pipe2_autoadvance_test::concurrent_last_mandatory` (uma linha de passos
   por frente, sub-etapa e ciclo).

---

## Decisões (tomadas aqui; o resto do plano segue-as)

**D1 — Como se reconhece um negócio v2.** `pipe2_deal_is_v2($pdo, $deal)` = `pipe2_schema_ready($pdo)` **e** o funil do
negócio (`deals.funnel_id`) tem etapas vivas (`level 'deal'`, `retired_at IS NULL`) com `role 'exec'` **e** com
`role 'delivery'`. SQL direta, sem cache estática. Não usa `service_code` (os funis reais 1, 2, 3 e 6 já o têm) nem
`pipe2_on()`. Numa função pura (sem PDO) uma frente é v2 quando o estado está em `PIPE2_LINE_STATUS` ou
`billing = 'monthly'` (a v1 nunca grava nenhum dos dois). Porque é seguro antes do «Aplicar»: só os funis Motion Design,
Composit e Edição (inativos, `v2_only`, sem negócios) e os funis dos testes cumprem a condição; os 4 funis ativos só
ganham as duas etapas na conversão, e `funnel_create` só as cria com a bandeira ligada ou com `$v2 = true` (D2). Prova:
`pipe2_fronts_test::predicate` (base privada: nenhum negócio do dump é v2) e `::predicate_bench` (bancada: os funis v2
são exatamente os de `md`/`cmp`/`ed`, sem negócios), mais os `::v1_noop` e a tarefa 17.

**D2 — `funnel_create(PDO, string $name, array $variable = [], string $madeFrom = '', ?bool $v2 = null)`.** `null` =
`pipe2_on($pdo)`. Com `true` cria as 7 etapas de negócio (X-Ray 10, Proposta 20 `proposal`, Aguardar feedback 21
`feedback`, Kickoff 50, Em execução 70 `production`/`exec`, Entrega e pagamento final 80 `production`/`delivery`,
Concluído 90), ignora as etapas variáveis de produção e não cria sub-etapas (um funil sem `service_code` não as tem).
Também não cria regras: sem as regras de fecho na Entrega, um negócio desse funil nunca conclui sozinho (D16) — só um
humano o conclui, até alguém lhe pôr a aprovação final e o pagamento final como obrigatórias.

**D3 — Passo 44** (`migrate_pipeline2_44`): tabela `pipe2_notices` (avisos guardados uma vez por chave e a ação
«notificar»), `stage_automations.action VARCHAR(8) NOT NULL DEFAULT 'task'` (`task` | `notify`),
`stage_automations.trig_n SMALLINT NULL` (o N de «frente parada há N dias» e o dia da avença) e
`tasks.gate_stage_id INT UNSIGNED NULL` (uma tarefa de frente que também conta para o gate de uma etapa do negócio: a
tarefa de negócio que não se criou por já existir na frente, T2). Nada mais.

**D4 — Estados da frente.** `planned` (serviço antes do Kickoff, o código da v1), `queued` (Por começar), `running` (Em
curso), `depwait` (À espera: fica na sub-etapa anterior, cuja linha em `deal_line_steps` já tem `done_at`; com
`stage_id` NULL se Y for a 1.ª sub-etapa), `ready` (Pronto), `ongoing` (Ativa: avença dentro do período, também em
Pronto entre ciclos) e `merged` (fase 3). «Vivas» = todas menos `merged`.

**D5 — Interruptores do avanço automático:** E lógico `funnels.auto_advance` ∧ `funnel_stages.auto_advance` ∧
`deal_lines.auto_advance` (NULL = segue). Um 1 na frente não liga o que o serviço ou a sub-etapa desligam (o admin que
desliga um serviço nunca é contrariado pelo responsável de uma frente). O da frente muda-o o responsável da frente (só
`deal_lines.exec_owner_id`, com Negócios EDIT; um executor interno não) ou um admin (`pipe2_can_front_switch`, tarefa 6,
D23).

**D6 — Arranque das frentes.** No Kickoff as frentes ficam `queued`. O sistema começa-as todas quando o gate do Kickoff
fica cumprido **e** o Kickoff tem pelo menos uma obrigatória; sem obrigatórias, começa-as um humano
(`pipe2_front_start`, também com o gate cumprido). Começar = entrar na 1.ª sub-etapa (ou ficar `depwait` se ela tiver
uma dependência por cumprir). O negócio passa a «Em execução» (movimento do sistema) quando a primeira frente entra
numa sub-etapa.

**D7 — Avanço.** Uma sub-etapa completa-se quando todas as obrigatórias do ciclo atual (`tasks.line_id` = frente,
`stage_id` = sub-etapa, `is_gate = 1`, `cycle_ym` = ciclo da frente) estão feitas e, se houver unidades previstas,
`units_done ≥ units_plan`. Avança sozinha só se tiver pelo menos uma obrigatória ou unidades previstas (E3), os
interruptores deixarem, a sub-etapa não estiver suspensa e for a primeira vez no pedido (`pipe2_once('front:'.id)`).
O movimento é um UPDATE condicional (`WHERE id = ? AND COALESCE(stage_id, 0) = ? AND status = ?`) dentro de um
GET_LOCK por negócio; só quem tiver `rowCount() = 1` regista e corre as regras.

**D8 — Dependências.** «A conclui X» = A sai de X para a frente (automático ou à mão). Ao sair, marcam-se `met_at` as
ligações de (A, X); uma frente B `depwait` cujas ligações para Y ficaram todas cumpridas entra em Y (`dep_met`, aviso ao
responsável de B). O tempo de espera não conta para nenhuma sub-etapa e o replaneamento não corre em `depwait`.
Modelos: o da categoria gravada do negócio (`postprod`/`cgi`; sem categoria, sem modelo), lidos por código do serviço +
nome da sub-etapa (os ids dos modelos são das sub-etapas reais). **Combinações parciais** (§34.9: «as ligações que
passam por um serviço ausente ligam-se diretamente ao seguinte»): para cada serviço ausente, pela ordem de
`PIPE2_SERVICES`, cada ligação que entra nele liga-se a cada ligação que sai dele (origem da que entra → destino da
que sai) e as ligações que tocam o serviço ausente caem; repete-se até não haver serviços ausentes (cada serviço
contrai-se uma vez, por isso nunca entra em ciclo; a que entra num ausente sem nenhuma saída cai; uma ligação de um
serviço para ele próprio, que a contração possa gerar, cai). **Sem poda**: uma
ligação contraída que já está implicada por outra (pela ordem das sub-etapas da mesma frente) fica — no fluxo normal
nunca bloqueia mais do que a direta (só pesa se a direta for retirada à mão ou forçada) e é o que a §34.9 diz. **Ordem do resultado** (faz parte do contrato e os testes comparam com
`t_eq` exato): pela posição no modelo da primeira ligação da cadeia (a que sai de um serviço presente) e, em empate,
pela da última (a que entra num serviço presente); uma ligação repetida (mesma origem e destino) fica uma vez, na
primeira posição. Exemplos: `postprod` com VFX + 3D + Edição → `3D «Lighting e render» → Edição «Picture lock»` (via a
1.ª e a 3.ª do modelo) e `VFX «Finais e QC» → Edição «Picture lock»` (via a 2.ª e a 3.ª); `cgi` com 3D + Composit →
`3D «Modelação e lookdev» → Composit «Integração e look»` (contraída por VFX: 1.ª e 3.ª) e `3D «Lighting e render» →
Composit «Preparação de plates»` (direta, 2.ª). Uma ligação retirada à mão (`removed_at`) nunca se recria.

**D9 — «Sistema» e histórico.** Os movimentos automáticos gravam `user_id` NULL mesmo com sessão (chave nova
`'system' => true` em `deal_log` e `metrics_on_log`) e levam nota «Avanço automático…»; os humanos gravam a pessoa.
Os movimentos de frente gravam `deal_history.event = 'p2_front'` (nunca `'stage'`: não mexe em `budget_follow_deal`,
não gera passos `producao` do negócio, não dispara «Gate de produção passado») e `stage_events` com `line_id`,
`step 'p2_front'` (ou `'p2_wait'` ao ficar À espera), `to_stage_id` = sub-etapa. Os movimentos do sistema do negócio
passam por `deal_move` com `['system' => true, 'note' => …]` (nota não vazia: também não dispara o aviso). Todos os
movimentos mudam `deals.last_touch`.

**D10 — Regras das etapas do negócio.** Numa etapa comum correm as regras da etapa do funil principal e as da etapa com
o mesmo papel (ou tipo) nos funis das outras frentes vivas (também `planned`), juntas pela chave de deduplicação; as de
`doc_kind` `invoice`/`payment` só do funil principal. **Numa tarefa de negócio, `stage_id` = etapa do negócio** (a de
`deals.stage_id`, do funil do negócio — a `$stage` que `pipe2_run_rules` recebe), mesmo quando a regra vem da etapa de
outro funil: a «etapa da regra» que `pipe2_rules_for` devolve só serve para ler a regra (prazo, rótulo), nunca se
grava. Assim `pipe2_deal_gate` (que filtra `stage_id` = etapa do negócio) conta todas as obrigatórias, venham do funil
que vierem. Nas tarefas de frente, `stage_id` = sub-etapa da frente (a do funil dela, que é a da regra). O
armazenamento «definidas uma vez para todos os funis» é das Definições (fase 2) e entra em `pipe2_rules_for` sem mudar
o motor.

**D11 — Onde vivem e quando correm os gatilhos novos** (`stage_automations.stage_id`):

| Gatilho | Regras na etapa | Corre quando | Âmbito da tarefa |
|---|---|---|---|
| `f_enter` (e `enter` numa sub-etapa) | sub-etapa | a frente entra nela | frente |
| `f_ready` | sub-etapa `ready` | a frente fica Pronta | frente |
| `all_ready` | Em execução (negócio, D10) | todas as frentes concluídas, antes de passar à Entrega | negócio |
| `dep_met` | sub-etapa Y de B | B entra em Y por ter sido libertada | frente B |
| `f_stale` | sub-etapa | relógio: frente em curso parada há N dias (`trig_n`, senão `max_days` da etapa, senão `deal_stale_days`) | frente, uma vez por ciclo |
| `rounds` | sub-etapa `revisions` | as rondas da frente no ciclo passam as incluídas | frente, uma vez por ciclo |
| `fee_day` | Em execução do funil da avença | relógio: o mês de hoje está dentro do período da avença (do mês de início ao de `billing_end`, D15) e dia ≥ min(N, último dia do mês) (`trig_n`, senão 1); uma por regra e ciclo `AAAA-MM` de hoje (D12), por isso o 1.º cron depois de um dia falhado abre-a uma vez. Não depende do `cycle_ym` da frente (isso é o recomeço do ciclo, D15) | negócio, ciclo `AAAA-MM` |
| `date` numa sub-etapa | sub-etapa | relógio, com a entrega da frente | frente |

Os gatilhos v1 (`enter`, `date`, `monthly`, `accepted`, `paid`) continuam nas etapas do negócio pelo código v1; as
tarefas que criam num negócio v2 passam pela deduplicação v2 (gancho em `flow_task_from_rule`). Ações: criar tarefa
(com `doc_kind` = pedir documento) e `action = 'notify'` (aviso, sem tarefa). «Criar Topperboard / ligação de Review»
fica para a fase 2. Responsáveis: os da v1 (`commercial`/`owner`/`both`, `execution`, `user`, `dept`, `none`) e
`svc_head` (chefe do serviço); `execution` numa tarefa de frente = responsável da frente. Nunca o superadmin nem uma
conta desativada (`pipe2_user_ok`).

**D12 — Deduplicação.** `tasks.dedup_key = sha1(pipe2_title_norm(título) . '|' . deal_id . '|' . âmbito . '|' . ciclo)`
(40 caracteres), âmbito = `'L<frente>:S<sub-etapa>'` nas de frente e `''` nas de negócio; título normalizado = minúsculas,
espaços seguidos reduzidos a um, sem espaços nas pontas. Antes de criar: (1) a regra já criou tarefa para este negócio,
frente e ciclo (`auto_rule_id`, `line_id`, `cycle_ym`, qualquer estado) → não cria; (2) existe uma tarefa com a mesma
chave (aberta ou feita) → não cria e, se a regra é obrigatória e a existente não, a existente passa a `is_gate = 1`;
(3) só nas de negócio: há tarefas de frentes do negócio, **abertas ou feitas**, com o mesmo título normalizado e o
mesmo ciclo (`cycle_ym` da tarefa da frente = ciclo da tarefa de negócio; uma frente avença, de ciclo `AAAA-MM`, não
deduplica uma tarefa de negócio de ciclo `''`) → não cria e, se a regra é obrigatória, **todas** essas tarefas ficam com
`gate_stage_id` = etapa do negócio e contam para o gate dela: a feita conta logo como cumprida, uma aberta como por
fazer (se duas frentes têm a tarefa, o gate só fica cumprido com as duas feitas). Assim uma reentrada na Entrega,
depois de a tarefa da frente ter sido feita, não cria um duplicado. É a leitura da §34.11 T2 («a deduplicação considera
tarefas abertas **e concluídas** do mesmo ciclo»), que prevalece sobre a §34.9 («a mesma tarefa aberta noutra
frente»). A deduplicação v1 (`deal_id + auto_rule_id`) fica intacta nos negócios v1.

**D13 — «Anular» em cascata.** Os movimentos de um pedido (automáticos e os de frente feitos à mão) ficam em
`pipe2_moves()`, com tudo o que criaram ou mudaram (tarefas, histórico, `stage_events`, linhas e mudanças de
`deal_line_steps`, `met_at` das ligações, avisos, as instâncias de séries fechadas pelo sistema, D18). `undo_push` junta-os aos dados (`p2_moves`) e devolve `''` (sem «Anular») quando algum
não se desfaz (o Concluído automático). `undo_apply` verifica tudo primeiro e desfaz tudo ou nada (transação).

**D14 — «Desfazer» e arrastar para trás.** A frente volta uma sub-etapa e a sub-etapa de destino fica suspensa
(`deal_line_steps.auto_hold_at`, `auto_hold_why = 'undo'`) até alguém fechar, reabrir, apagar ou mudar o `is_gate` de
uma obrigatória dessa sub-etapa nessa frente. As unidades não levantam a suspensão.

**D15 — Avença (D4 dos sócios).** Frente avença = `deal_lines.billing = 'monthly'` com `monthly_fee` e `fee_months`
(`deals.billing = 'monthly'` quando uma frente o é). Período: do 1.º dia do mês em que a frente começa até ao último dia
do mês `início + fee_months − 1` (`billing_end`). Valor da frente = mensalidade × meses; valor de um negócio só com
avenças = soma; com outros serviços, `budget` (> 0) senão `value_estimate`. Ao começar a frente, `cycle_ym` = mês de
início. Há **duas condições separadas**; em ambas o dia efetivo de um N é min(N, último dia do mês) (`pipe2_billing_day_ok`):
(a) **Fatura do mês** (`pipe2_billing_invoice_due`, por regra `fee_day` com o seu `trig_n`, senão 1) — avença v2
começada, não cancelada, o mês de hoje entre o mês de início e o de `billing_end` (inclusive) e dia de hoje ≥ dia
efetivo; não olha para o `cycle_ym` da frente, por isso a fatura do mês de início também abre. «Uma fatura por mês» = as regras `fee_day` deduplicadas por regra e ciclo
`AAAA-MM` de hoje (D12). (b) **Recomeço do ciclo de produção** (`pipe2_billing_restart_due`) — frente em Pronto
(`ongoing`, sub-etapa `role 'ready'`), avença não terminada, `cycle_ym` < mês de hoje e dia ≥ dia efetivo de
`pipe2_billing_day` (o menor `trig_n` das regras `fee_day` ativas da Em execução do funil da frente, senão 1): volta à
1.ª sub-etapa com `cycle_ym` = mês de hoje. Se não estiver em Pronto com `cycle_ym` < mês de hoje e dia ≥ dia efetivo,
o ciclo atual continua e há um aviso `cycle` uma vez por mês. Conclui no fim do período (`hoje > billing_end`) ou ao
cancelar (`cancelled_at`): a frente passa a Pronto final (`ready`).

**D16 — Fecho.** Em execução → Entrega quando todas as frentes vivas estão concluídas (pontual: `ready`; avença:
terminada e `ready`). Na Entrega, se uma frente voltar atrás, o negócio volta a Em execução (sistema). Entrega →
Concluído (sistema, por `deal_move`) quando o gate da Entrega está cumprido (obrigatórias da Entrega, também as de
`gate_stage_id`), as frentes concluídas **e** o gate tem as duas obrigatórias da §34.9 (`pipe2_close_auto_missing`
vazio): uma com `doc_kind 'payment'` (o pagamento final) e uma cujo título normalizado começa por «aprovação final» (a
aprovação final), contadas entre as do negócio na Entrega e as de `gate_stage_id` = Entrega. É a mesma guarda do
Kickoff (D6: «≥ 1 obrigatória») e das sub-etapas (E3), mais forte porque a §34.9 só conclui «com as duas». Sem elas (um
funil sem regras de fecho, como os de `funnel_create` com `$v2 = true`, D2, ou com essas regras desligadas) o sistema
nunca conclui: `pipe2_deal_reevaluate` devolve `'no_mandatory'` e deixa a nota «Pronto para concluir: conclui-se à mão
(a Entrega não tem como obrigatória: Aprovação final, Pagamento final).» (só os itens em falta); só um humano conclui,
pelo `deal_move` de sempre, que passa o gate (`pipe2_close_missing` vazio). O Concluído automático marca `delivered_at` = `done_at` da tarefa feita (do
negócio na Entrega ou com `gate_stage_id` = Entrega) cujo título normalizado começa por «enviar entregáveis finais»
(senão agora), com `deal_log 'delivered'` de «Sistema»; a checklist da §15.5 não bloqueia. `billing_validate_payment` marca `is_paid` também quando a tarefa de pagamento é da
Entrega (`role 'delivery'`) de um negócio v2. As regras de fecho só existem nos funis dos testes nesta fase (a conversão
põe-nas nos reais).

**D17 — Datas (E8).** Entrega da frente = `deal_lines.delivery_date` se preenchida, senão `deals.delivery_date`.
Gravar uma data comum põe-na em `deals.delivery_date` e limpa as das frentes; gravar por frente põe as das frentes e
`deals.delivery_date` = a maior. As tarefas de frente com prazo pela entrega usam a da frente. Mudar prazos
automaticamente grava `updated_at` (o `gcal_tick` envia-os ao Google em até 5 minutos). Plano das sub-etapas: em dias
úteis (`flow_add_workdays`), a partir do dia em que a frente começa, com `deal_line_steps.plan_days` senão
`funnel_stages.plan_days` (sem dias, sem plano). Replaneamento (relógio): se a sub-etapa atual passou o `plan_end`, as
seguintes deslizam pelos dias úteis de atraso (soma em `replanned_days`, aviso `replan` uma vez); a entrega não muda.

**D18 — Repetições (T4).** Numa tarefa de frente v2, a repetição copia `line_id`, `stage_id`, `auto_rule_id`,
`doc_kind` e `cycle_ym` (o que a §34.11 T4 pede, mais o documento e o ciclo) e **não copia `is_gate`**: a instância
nova nasce com `is_gate 0`, por isso a série conta uma só vez para o gate da sub-etapa (pela instância obrigatória
original) e nunca bloqueia a frente para sempre. Em `task_done`, o gancho v2 (`pipe2_task_changed`, tarefa 10) corre
**antes** de `task_repeat_next`: se fechar a obrigatória completa o gate e a frente avança, `pipe2_repeat_extra` já vê
a frente fora da sub-etapa e não nasce a seguinte (a série fecha). Se a frente já saiu dessa sub-etapa (ou mudou de
ciclo), não nasce a seguinte (`pipe2_repeat_extra` → `null`).
**As repetições fecham ao sair da sub-etapa (§34.11 T4).** Quando a frente **conclui** uma sub-etapa S — avanço
automático, movimento humano para a frente (também forçado), passagem a À espera (`depwait`: A concluiu X, D8), Pronto
final de uma avença (cancelada ou no fim do período, vinda de qualquer sub-etapa) — `pipe2_front_enter` fecha, na mesma
transação do movimento, as instâncias abertas das séries dessa frente, S e ciclo (`line_id` = frente, `stage_id` = S,
`cycle_ym` = ciclo, `repeat_rule <> ''`, `status <> 'done'`); com um ciclo novo (`$o['cycle']`, o recomeço da avença)
fecha as de todas as sub-etapas da frente com o ciclo antigo. Fechar = UPDATE direto (`status 'done'`, `done_at` e `updated_at` = agora) e
um `INSERT` direto em `task_log` (`event 'done'`, `user_id` NULL — o `task_log()` grava a sessão —, nota «Fechada
pelo sistema: a frente saiu da sub-etapa»), sem `deal_log 'task_done'`, sem `task_repeat_next` e sem reavaliar (não
passa por `task_done` nem por `pipe2_task_changed`). Os ids e o estado de antes ficam em `pipe2_moves` (`'closed'`) e o
«Anular» reabre-as (D13). O medidor de ruído (fase 4) distingue-as pelo `task_log` (`user_id` NULL e esta nota).
**Para trás não fecha** («Desfazer», arrastar para trás: a sub-etapa não ficou concluída e a frente volta a ela, D14):
a instância aberta fica; feita com a frente noutra sub-etapa, não gera a seguinte; feita depois de a frente voltar a S,
a série continua; fecha quando a frente concluir S. (Decisão desta fase, para o utilizador confirmar: a alternativa é
fechar também para trás, e então a série não recomeça numa reentrada, porque a regra não volta a criar a tarefa, D12.)
Nas tarefas v1 nada muda (o gancho é no-op num negócio v1 e não escreve nada, por isso a ordem não muda o resultado;
`::v1_noop` prova-o).

**D19 — Apagamentos (T6).** Todo o apagamento humano (`calendar.php`) fica em `task_deletions` antes do DELETE, também
o das tarefas v1 (ninguém o lê na v1; serve o medidor de ruído depois da conversão). O registo só se tenta com
`pipe2_schema_ready` e `db_has_table('task_deletions')`, e o INSERT fica em try/catch (`error_log('[topper] pipe2
task_deletions: …')`): o DELETE de `tasks` e `task_log` corre **sempre**, fora do try, como hoje. A reavaliação só corre
em v2. Os DELETE automáticos (Anular, limpezas) não registam.

**D20 — Reatribuição (T5), só v2.** Em `deal_flow.php 'people'`, num negócio v2 só passam as tarefas do negócio
(`line_id IS NULL`). `team_reassign` passa também `deals.exec_owner_id` dos negócios v2, `deal_lines.exec_owner_id` das
frentes v2 vivas e `deal_line_execs.user_id` delas. `pipe2_team_reassign` começa por `pipe2_schema_ready` (falso →
zeros, sem consultas), só toca `deal_line_execs` com `db_has_table`, e o gancho em `team_reassign` fica em try/catch
com `error_log` (a reatribuição v1 acaba sempre). Nos negócios v1 nada muda.

**D21 — `del_stage` (M6) vale para todas as etapas:** além dos negócios, recusa uma etapa com frentes
(`deal_lines.stage_id`) ou tarefas (`tasks.stage_id`, qualquer estado) com «Há frentes ou tarefas nesta etapa: mova-as
ou apague-as primeiro.». Muda as Definições da v1 só para recusar apagamentos que deixavam registos órfãos (nenhum
negócio muda); fica para confirmação do utilizador.

**D22 — Avisos (T5).** Tabela `pipe2_notices`: um por `nkey` (ex.: `replan:<frente>:<sub-etapa>:<ciclo>`), para um
destinatário (sem destinatário não se grava). `my_alerts` junta-os ao grupo `'deal'` (chave `'p2n:<id>'`), por isso
`notifications()` aplica `user_wants(…, 'deal', 'app')`. Sem email (como os avisos de negócio de hoje).

**D23 — Leitura por frente (S1).** Funções de sessão (como `record_reach`), só de leitura, ainda não ligadas a ecrãs;
`record_reach`/`reach_sql` não mudam. **Leitura:** ligado a uma frente = responsável (`deal_lines.exec_owner_id`),
executor interno (`deal_line_execs.user_id`) ou com tarefas nela (`member_id` → `users.team_member_id`,
`assignee_id`, `help_by`); só frentes depois do Kickoff (`PIPE2_FRONT_READ` = `queued`, `running`, `depwait`, `ready`,
`ongoing`: nem `planned` nem `merged` — as frentes só nascem no Kickoff, §34.9) de negócios v2 em
`phase IN ('production','done')` (um negócio ainda no Pipeline não se lê pela frente); exige sempre `can('negocios')`,
**também** em `pipe2_front_reach_sql` (sem ele devolve `['0=1', []]`, para que a fase 2, ao juntá-la em OR com
`reach_sql`, nunca mostre negócios a quem não tem Negócios). **Ações e interruptor:** «responsável da frente» = só
`deal_lines.exec_owner_id` (a §36.2 separa os executores do responsável interno; a §34.11 dá as ações a «admin,
responsável da frente ou `flow_can_edit`» e a nota 4 o interruptor a «responsável da frente ou admin»); um executor
interno que não seja o responsável lê, mas não age nem muda o interruptor.

**D24 — «Pedir fatura» (T3)**: na fase 1 só as funções (o pedido lê o NIF no servidor e guarda a tarefa em
`fin_docs.task_id`; uma fatura emitida fecha só a tarefa do seu pedido). A ação em `deal_flow.php` e o botão são da
fase 2 (assim não nasce uma capacidade POST nova na v1 antes do «Aplicar»).

**D25 — «Em risco» (X4):** só os 4 motivos da §34.9 (sem movimento ≥ limite; pagamento em atraso só com faturas
emitidas vencidas; entrega do negócio ou de uma frente ultrapassada; sub-etapa atrasada só quando empurra a entrega).
«Fecho incompleto» e tarefas atrasadas não são risco. Limite = `alert_limits($pdo)['deal']` (lê `deal_stale_days`: 10
até à conversão), comparação ≥. Movimento = o mais recente de `last_touch`/`converted_at`/`created_at`, tarefas
feitas e entradas/saídas de sub-etapas; num negócio em execução sem nenhuma frente em curso (todas À espera, Por
começar ou Prontas) o motivo «sem movimento» não se aplica. Os rótulos nunca têm euros nem números de faturas.

**D26 — Métricas v2:** funções novas em `pipe2_metrics.php`, não ligadas aos ecrãs (fase 4); a v1 não muda. Serviço de
um negócio = códigos de `cur_fu` e das frentes (`sv`), nunca de `fu` (`origin_funnel_id`); funil sem `service_code` →
`PIPE2_V1_MAP` do `xray_kind` em lista (um combinado conta em cada serviço). Métricas de meta novas numa constante
própria (`PIPE2_GOAL_METRICS`), para não aparecerem no formulário de Metas. `fin_stage_prob` e `metrics_snapshot` por
serviço ficam para a fase 4.

**D27 — Leads (D2):** `deals.service_codes` (CSV pela ordem de `PIPE2_SERVICES`, sem espaços) escrito só por
`pipe2_leads.php`; `deals.service` e os formulários não mudam. «Marketing Buddy» → `cam`; «VFX / 3D» → `vfx,3d` e
«Ambos» → `vfx,3d,cam`, ambos marcados ambíguos (o admin escolhe na conversão). Categoria da lead calculada na hora,
nunca gravada antes do Kickoff. `lead_convert` para um funil v2 cria as frentes `planned` (principal + outros códigos
com funil v2) na mesma transação; depois disso, a fonte dos serviços são as `deal_lines`.

**D28 — Review e rondas (C2), só v2:** as rondas contam por frente e ciclo, na sub-etapa `revisions` do funil da
frente (`deal_rounds.line_id`/`cycle_ym`, `cc_rv_rounds.line_id`); uma peça tem frente obrigatória quando o negócio tem
2+ frentes vivas e só fecha tarefas dessa frente; o registo à mão recusa uma frente com rondas do portal no ciclo
(«As rondas desta frente contam-se pelo portal do cliente.»); acima das incluídas correm as regras `rounds` em vez de
`cc_rv_change_order_task`.

**D29 — Anular o Kickoff:** apaga as frentes criadas pelo Kickoff (as que não existiam no snapshot), as linhas de
`deal_line_steps` e `deal_line_deps` criadas depois do snapshot e os quadros `cc_boards` dessas frentes sem cartões;
as frentes que já existiam voltam a `planned`; a categoria volta ao que estava. Recusa («Já há trabalho no Toppernote
ou no Review destas frentes; não foi anulado.») se esses quadros tiverem cartões ou houver peças de Review nelas.

**D30 — Fora da fase 1:** interface (incl. rótulos dos eventos `p2_*`), execução Interna/Externa a partir do orçamento
(`deal_line_execs`, `exec_mode`), progresso das barras, Topperspace, ações «criar Topperboard/ligação de Review», etapas
comuns «uma vez» nas Definições, ligar métricas e «Em risco» aos ecrãs, `fin_stage_prob`, `deal_stale_days` 5, obrigatórias
condicionais (fase 3), `start_mode 'after'` → dependência (conversão, fase 3), `negocio.php`/`deal_in_reach`/
`pipeline.php` com `record_reach` (fase 2).

---

## Rastreabilidade da fase 1 (ponto → tarefa → teste)

| # | Tarefa | Teste |
|---|---|---|
| M3 | 3, 10 | `pipe2_fronts_test::single_service_has_front`, `::main_not_special` |
| M4 | 3, 11, 13 | `pipe2_fronts_test::kickoff_idempotent`, `::budget_service_required`; `pipe2_engine_test::kickoff_creates_fronts`, `::kickoff_race`, `::kickoff_reread_after_lock`, `::proposal_gate_v2` |
| M6 | 3 | `pipe2_model_test::funnel_create_six`, `::del_stage_guards`; `pipe2_stage_guard_test::*` |
| M7 | 12 | `pipe2_billing_test::recurring_reads_column` |
| E1 | 11, 13 | `pipe2_engine_test::multi_front_deal_moves`, `::front_move_bypasses_deal_move` |
| E2 | 11, 13 | `pipe2_engine_test::done_gate`, `::done_needs_mandatory`, `::close_missing_hook` |
| E3 | 10, 11, 13 | `pipe2_autoadvance_test::no_mandatory_no_advance`, `::one_step_per_request`, `::concurrent_last_mandatory` (as condicionais deixam de ser `is_gate` na fase 3); `pipe2_engine_test::concurrent_fronts_ready`, `::stale_move_refused`; `pipe2_tick_test::cron_and_request` |
| E4 | 10, 11, 13 | `pipe2_autoadvance_test::reevaluate_hooks`, `::system_actor`, `::never_cancels_completion`; `pipe2_engine_test::blocked_gate_message`; `pipe2_tick_test::reevaluate_cron`, `::cron_and_request` |
| E5 | 15 | `pipe2_undo_test::undo_cascades`, `::undo_reopens_closed_series`, `::undo_kickoff_removes_fronts`, `::not_offered_after_auto_done` |
| E6 | 11 | `pipe2_engine_test::auto_done_delivered_at` |
| E7 | 5, 10, 13 | `pipe2_deps_test::partial_combination_links`, `::contraction_multi_hop`, `::waits_before_y`, `::tasks_born_on_release`, `::no_replan_while_waiting`; `pipe2_engine_test::proposal_gate_v2`, `::xsell_tick_ignores_v2`; `pipe2_dates_test::save_plan_v2` |
| E8 | 5, 13 | `pipe2_dates_test::*` |
| T1 | 11, 12 | `pipe2_close_test::once_per_deal`, `::other_funnel_mandatory_blocks_done`, `::is_paid_on_delivery_rule` |
| T2 | 9, 12 | `pipe2_dedup_test::deal_deal`, `::deal_front` (abertas e feitas do mesmo ciclo), `::delivery_reentry`, `::front_front`, `::skipped_counts_for_gate`, `::other_funnel_rule_on_deal_stage`, `::cycle`; `pipe2_monthly_test::cycle_opens_once` |
| T3 | 12, 6 | `pipe2_invoice_test::closes_own_task`, `::request_src`, `::state_without_nif`, `::v1_at_42`; `pipe2_perm_test::can_bill` |
| T4 | 9, 10, 15 | `pipe2_repeat_test::copies_and_closes`, `::mandatory_series_closes_on_advance`, `::series_counts_once`, `::closes_on_leave`, `::back_move_keeps_open`; `pipe2_undo_test::undo_reopens_closed_series` |
| T5 | 4, 9, 11 | `pipe2_notify_test::once_per_step`; `pipe2_reassign_test::by_line`, `::team_reassign_exec`, `::v1_at_42`; `pipe2_engine_test::no_gate_alert` |
| T6 | 9, 10 | `pipe2_noise_test::deletion_logged`, `::insert_fails_delete_runs`, `::v1_at_42`; `pipe2_autoadvance_test::reevaluate_hooks` (apagar na Agenda) |
| C1 | 16 | `pipe2_cc_test::fronts_before_board` |
| C2 | 16 | `pipe2_review_test::*` |
| X1 | 8 | `pipe2_metrics_test::by_v2_service`, `::origin_untouched`, `::goal_service` |
| X2 | 8 | `pipe2_totals_test::header_and_won`, `::without_negocios_excludes_production`, `::concluidos` |
| X3 | 2, 8, 10 | `pipe2_metrics_test::substep_time`, `::front_margin`, `::category_frozen`, `::values_follow_accepted_budget`; `pipe2_category_test::stored_once_and_recalc`; `pipe2_autoadvance_test::advance_on_last_mandatory` (`stage_events` com `line_id`) |
| X4 | 7 | `pipe2_risk_test::*` |
| S1 | 6 | `pipe2_perm_test::front_reader_read_only`, `::not_before_kickoff`, `::front_actions`, `::front_switch`, `::reach_sql` |
| D2 | 14 | `pipe2_lead_services_test::*` |
| D4 | 12, 13 | `pipe2_monthly_test::value`, `::due_functions`, `::one_invoice_per_month`, `::cycle_restart`, `::ends` |
| Nota 2 (categorias) | 2 | `pipe2_category_test::*` (TDD) |
| Nota 4 (avanço, «Desfazer», interruptores) | 10 | `pipe2_autoadvance_test::*`, `pipe2_undo_test::undo_suspends_until_mandatory_touch` (TDD) |
| Dependências (§34.9) | 5, 10 | `pipe2_deps_test::*` (TDD) |
| Deduplicação | 9 | `pipe2_dedup_test::*` (TDD) |
| Nota 6 (gatilhos e ações) | 9–13, 16 | `pipe2_dedup_test::notify_action`, `pipe2_tick_test::stale_once`, `::date_rules_on_substages`, `pipe2_engine_test::all_ready_rules`, `pipe2_review_test::over_included_uses_rounds_trigger` |
| Chefe do serviço | 3, 9 | `pipe2_fronts_test::service_head`, `pipe2_dedup_test::assignee_never_invalid` |
| Regra de ouro | todas, 17 | `::v1_noop` de cada tarefa; `::v1_at_42` (tarefas 9 e 12: os acessos guardados do caminho v1); `pipe2_v1_hooks_test::same_as_phase0`; `pipe2_v1_unchanged_test` |
| Reversão | 1, 17 | `pipe2_rollback_test::phase1_rollback`, `::documented` |

## Ficheiros

| Ficheiro | Tarefa | Responsabilidade |
|---|---|---|
| `admin/inc/migrate_pipeline2_44.php` (novo) | 1 | passo 44 |
| `admin/inc/pipe2_category.php` (novo) | 2 | categorias e códigos dos serviços |
| `admin/inc/pipe2_fronts.php` (novo) | 3 | predicado v2, frentes, sub-etapas, chefe do serviço, valores, avença (puro) |
| `admin/inc/pipe2_notify.php` (novo) | 4 | avisos |
| `admin/inc/pipe2_deps.php` (novo) | 5 | dependências, plano, replaneamento e datas |
| `admin/inc/pipe2_access.php` (novo) | 6 | leitura por frente e ações de frente |
| `admin/inc/pipe2_risk.php` (novo) | 7 | «Em risco» |
| `admin/inc/pipe2_metrics.php` (novo) | 8 | métricas v2 |
| `admin/inc/pipe2_tasks.php` (novo) | 9 | regras, deduplicação, gates, repetições, apagamentos, reatribuição |
| `admin/inc/pipe2_engine.php` (novo) | 10 (+11) | motor das frentes |
| `admin/inc/pipe2_deal.php` (novo) | 11 | movimentos do sistema do negócio, Kickoff, fecho |
| `admin/inc/pipe2_billing.php` (novo) | 12 | avença, pagamento final, pedido de fatura |
| `admin/inc/pipe2_tick.php` (novo) | 13 | relógio da v2 |
| `admin/inc/pipe2_leads.php` (novo) | 14 | serviços da lead |
| `admin/inc/pipe2_undo.php` (novo) | 15 | «Anular» em cascata |
| `admin/inc/pipe2_cc.php` (novo) | 16 | Review e rondas por frente |

**Ordem e dependências** (o mesmo ficheiro existente nunca está em duas tarefas paralelas):
- 1 primeiro (sobe o `SCHEMA_VERSION`; todas dependem dela);
- 2, 3 e 4 em paralelo; 5 depois de 2 e 3 (usa `pipe2_deal_category` e `pipe2_funnel_codes` da 2); 6 depois de 3;
  8 depois de 2 e 3; 7 depois de 3 e 5; 9 depois de 3, 4 e 5;
- 10 depois de 3, 4, 5 e 9; 11 depois de 2, 3, 5, 9 e 10; 12 depois de 9, 10 e 11; 13 depois de 5, 10, 11 e 12;
- 14 depois de 2, 3 e 11; 15 depois de 10, 11 e 13; 16 depois de 9, 11 e 13 (14, 15 e 16 podem correr em paralelo);
- 17 no fim.
Ficheiros existentes partilhados: `admin/inc/pipeline.php` (3 → 11); `admin/inc/pipeline_flow.php` (9 → 11 → 12 → 13);
`admin/inc/xsell.php` (12 → 13); `admin/inc/tasks.php` (9 → 10); `admin/deal_flow.php` (9 → 16);
`admin/inc/pipe2_engine.php` (10 → 11); testes partilhados: `pipe2_fronts_test` (3 → 10), `pipe2_deps_test` (5 → 10),
`pipe2_repeat_test` (9 → 10), `pipe2_race.php` (só a 10; a 11 e a 13 usam-no sem o mudar), `pipe2_probe.php` e
`pipe2_v2_lib.php` (só a 1; a 4, a 6 e a 11 usam o probe e a 10 o `p2v_go_wait` sem os mudar),
`pipe2_dates_test` (5 → 13), `pipe2_engine_test` (11 → 13), `pipe2_close_test` (11 → 12), `pipe2_monthly_test`
(12 → 13), `pipe2_undo_test` (10 → 15).

---

### Task 1: Passo 44 e base de testes da fase 1

**Files:**
- Create: `admin/inc/migrate_pipeline2_44.php`, `tests/pipe2_schema44_test.php`, `tests/pipe2_v2_lib.php`,
  `tests/pipe2_v2_lib_test.php`, `tests/pipe2_probe.php`, `tests/pipe2_tree.php`
- Modify: `admin/inc/migrate.php` (`SCHEMA_VERSION = 44`; depois da chamada ao passo 43, antes do upsert:
  `// ── 44 · Pipeline v2, fase 1: avisos, ação e N das regras, gate partilhado (só acrescenta) ──`,
  `require_once __DIR__ . '/migrate_pipeline2_44.php';`, `migrate_pipeline2_44($pdo);`)
- Modify: `admin/inc/pipe2.php` (só `PIPE2_CODES`)
- Modify: `tests/pipe2_lib.php` (opções e limpeza), `tests/pipe2_schema_test.php` (`::idempotent`),
  `tests/pipe2_v1_unchanged_test.php` (versão), `tests/pipe2_rollback_test.php` (árvores, versão, `::phase1_rollback`)

**Interfaces:**
- Consumes: fase 0 (`migrate_pipeline2.php`: `PIPE2_SEED_FRONTS`, `PIPE2_SEED_DEPTS`; `pipe2_lib.php`; `pipe2_v1_snap.php`);
  `migrate_pipeline.php` (`PIPE_RULES_DELIVERY`, `PIPE_RULES_DONE`, `migrate_pipeline_rule`).
- Produces:
  ```php
  function migrate_pipeline2_44(PDO $pdo): void;          // lock → DDL → RELEASE_LOCK no finally
  function migrate_pipeline2_44_lock(PDO $pdo): string;   // 'topper_pipe2_' . DATABASE() (md5 se > 64); GET_LOCK 30 s; ≠ 1 → RuntimeException('Passo 44: …'); '' em SQLite
  function migrate_pipeline2_44_schema(PDO $pdo): void;
  ```
  `PIPE2_CODES` acrescenta (exatamente):
  `'stage_automations.action' => ['task','notify']`, `'stage_automations.assign_to' => ['svc_head']`,
  `'stage_events.step' => ['p2_front','p2_wait']`, `'pipe2_notices.kind' => ['replan','stale','dep_met','rule','cycle']`,
  `'tasks.cycle_ym' => ['2026-11']`, e `deal_history.event` passa a
  `['v2_convert','p2_front','p2_wait','p2_undo','p2_auto','p2_units','p2_dep','p2_replan','p2_billing','p2_services','p2_category']`.

  `tests/pipe2_v2_lib.php` (incluir depois de `boot.php` e `pipe2_lib.php`; requer `migrate_pipeline2.php` e
  `migrate_pipeline.php`; **nunca** requer um `admin/inc/pipe2_*.php`):
  ```php
  function p2v_db(): array;                                     // [$db, $tp]: base privada do dump 42, migrada num subprocesso (to === SCHEMA_VERSION, senão FALHA e exit 1); pipe2_use($tp)
  function p2v_funnel(PDO $pdo, string $code, array $o = []): int;  // «P2T · <code>»: pipe2_funnel + Em execução (70, exec) + Entrega e pagamento final (80, delivery) + as 6 sub-etapas de PIPE2_SEED_FRONTS[$code] (110…160, revisions/ready, rounds_included)
        // o: 'code' (bool, true: service_code = $code; lança InvalidArgumentException se DATABASE() = 'topper'), 'active' (1), 'plan_days' (int|array por posição 0–5), 'units' ([pos => rótulo]), 'dept' (?int; por defeito o de PIPE2_SEED_DEPTS pelo nome), 'auto' (1)
  function p2v_bench_funnel(PDO $pdo, string $label, int $n = 4): int; // na bancada: v2 sem service_code, v2_only 1, is_base 0, n sub-etapas «P2T · s1»… (as 2 últimas revisions/ready)
  function p2v_stages(PDO $pdo, int $funnelId): array;          // ['xray','proposal','feedback','kickoff','exec','delivery','done' => id, 'fronts' => [ids por ordem], 'revisions' => id, 'ready' => id, 'name' => [nome => id]]
  function p2v_world(PDO $pdo, array $codes = ['vfx','3d','md','cmp','ed','adv','cam']): array;   // código => funil
  function p2v_deal(PDO $pdo, array $world, array $codes, array $o = []): array;  // ['id' => int, 'lines' => [code => line id]]
        // principal = $codes[0]; o: 'at' (xray|proposal|feedback|kickoff|exec|delivery|done; kickoff), 'owner' (99), 'exec' (null), 'delivery_date', 'budget', 'value_estimate',
        // 'status' (das frentes: planned antes do Kickoff, queued no Kickoff, running em exec/delivery), 'front_at' ([code => stage id]), 'billing' ([code => [fee, months]]), 'lines' (false = sem deal_lines)
  function p2v_front(PDO $pdo, int $lineId, array $set): void;  // UPDATE direto de deal_lines (preparar o estado)
  function p2v_steps(PDO $pdo, int $lineId, array $o = []): array;  // deal_line_steps de cada sub-etapa, ciclo da frente; o: 'plan_days', 'start', 'hold' ([stage => why]), 'units' ([stage => [plano, feitas]]); devolve [stage => id]
  function p2v_task(PDO $pdo, int $dealId, array $o = []): int; // pipe2_task + v2 (line_id, stage_id, is_gate, cycle_ym, gate_stage_id, dedup_key, repeat_rule, created_at, done_at, checklist, attachments, member_id, help_by)
  function p2v_rule(PDO $pdo, int $stageId, array $o = []): int; // stage_automations: title 'P2T · regra', trig 'f_enter', kind 'task', assign_to 'execution', due_mode 'none', is_gate 1, is_active 1, action 'task', trig_n NULL, doc_kind '' (o sobrepõe)
  function p2v_closing_rules(PDO $pdo, int $deliveryStageId): array;  // as 4 de PIPE_RULES_DELIVERY + «Validar pagamento final · {ID}» (G, pay) na Entrega, por migrate_pipeline_rule; ids
  function p2v_as(?int $uid, ?PDO $pdo = null): void;           // $_SESSION['user'] = linha de users (null: sem sessão)
  function p2v_probe(string $db, int $uid, array $calls): array; // tests/pipe2_probe.php num subprocesso; resultados pela ordem
  function p2v_race(array $runs): array;                         // lança [[script, args], …] com --go=<F> (F único no sys_get_temp_dir()); espera um «F.ready.*» por corrida ainda viva (uma que já saiu deixa de contar) até 15 s; cria F (também no fim do prazo, para ninguém ficar à espera); proc_close de todas; [['code','out','err','json','timeout' => bool], …] pela ordem; apaga F e os .ready
  function p2v_go_wait(array $argv): void;                       // para os scripts das corridas: com --go=F, escreve «F.ready.<getmypid()>» e espera (≤ 10 s, passos de 10 ms) que F exista; sem F a tempo → stderr «sem sinal de partida» e exit(2); sem --go não faz nada
  function p2v_sig(PDO $pdo, array $tables, string $where = '1=1'): array;  // tabela => [COUNT(*), SUM(CRC32(CONCAT_WS('|', todas as colunas)))]
  function p2v_rows(PDO $pdo, string $sql, array $a = []): array;
  function p2v_col(PDO $pdo, string $sql, array $a = []);
  ```
  `tests/pipe2_probe.php --db=<privada> --uid=<n|0> --calls=<ficheiro.json> [--go=F]`: liga à base, `pipe2_use`,
  sessão do utilizador (0 = sem sessão), faz `require_once` de `admin/inc/alerts.php`, `pipeline_view.php`, `reach.php`
  e de todos os `admin/inc/pipe2_*.php` que existam, chama `p2v_go_wait($argv)` (por isso serve de script de eco no
  `p2v_race`; `--calls` vazio ou com `[]` → `{"results": []}`), e para cada `{"fn": "...", "args": [...]}` com `fn` em
  `/^(can|is_admin|current_user|record_reach|reach_sql|flow_can_edit|flow_can_bill|flow_is_commercial|my_alerts|notifications|pipe2_[a-z0-9_]+)$/`
  substitui `{"deal": id}`, `{"line": id}` e `{"task": id}` pela linha (`deal_get`, `pipe2_lib`/SQL de `deal_lines` com
  `funnel_name`, `task_by_id`), põe a ligação à frente dos args quando o 1.º parâmetro da função é `PDO` (Reflection:
  `my_alerts`, `notifications`, `record_reach`, `flow_can_edit`, …) e imprime `{"results": [...]}` (ou
  `{"error": "..."}`, código 1). **Esta lista cobre todas as chamadas por probe deste plano** (tarefa 4: `notifications`
  e `my_alerts` em `::preferences`/`::v1_noop`; tarefa 6: `can`, `record_reach`, `flow_can_edit`, `pipe2_*`; tarefa 11:
  `my_alerts` em `::no_gate_alert`): nenhuma outra tarefa muda `pipe2_probe.php`; uma função de leitura que falte
  chama-se no próprio processo do teste, com `p2v_as`.

  `tests/pipe2_tree.php`: `pipe2_tree(string $commit, array $overlay = [], array $testFiles = [], string $tag = 'tree'): string`
  (`git archive $commit admin` numa pasta `p2t_<pid>_<tag>` do `sys_get_temp_dir()`, ligações simbólicas para a
  configuração local ignorada pelo Git, `$overlay = [caminho => commit]` copiados com `git show`, `$testFiles` copiados da
  cópia de trabalho; apagada no fim do processo) e `pipe2_tree_php(string $dir, string $script, array $args): array`
  (como `pipe2_sub`, mas na árvore).

- [ ] **Step 0: Confirmar a bancada.** `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8765/admin/` e
  `mariadb topper -N -e "SELECT svalue FROM settings WHERE skey IN ('schema_version','pipe2_seeded')"`. Esperado: 200 ou
  302, `43` e uma data. Se a bancada não responder: `bash /tmp/claude-0/-home-user-portfolio-website/07a7b6fb-2708-535b-b71f-5de7fa1226a8/scratchpad/bench.sh`.
- [ ] **Step 1: Escrever `tests/pipe2_schema44_test.php`** (bases privadas; «na 43» = fresca + `migrate_pipeline2_43`):
  - `::columns`: na 43 + 44, `COLUMN_TYPE`/`IS_NULLABLE`/`COLUMN_DEFAULT` exatos: `stage_automations.action`
    `varchar(8)` NO `task`; `stage_automations.trig_n` `smallint(6)` YES NULL; `tasks.gate_stage_id` `int(10) unsigned`
    YES NULL. `pipe2_notices` tem exatamente `id, deal_id, line_id, stage_id, cycle_ym, kind, rule_id, user_id, title,
    body, nkey, created_at` (tipos da secção «Esquema 44» abaixo) e os índices `idx_p2n_user (user_id, created_at)`,
    `idx_p2n_key (nkey)`, `idx_p2n_deal (deal_id)`, todos `NON_UNIQUE = 1`.
  - `::existing_untouched`: o retrato de `information_schema.columns` e `statistics` antes/depois do 44 só difere no
    acrescentado.
  - `::rows_defaults`: depois do 44, `SELECT COUNT(*) FROM stage_automations WHERE action <> 'task' OR trig_n IS NOT NULL`
    = 0 e `SELECT COUNT(*) FROM tasks WHERE gate_stage_id IS NOT NULL` = 0; `p2v_sig` de `deals`, `deal_lines`, `tasks`
    (colunas v1), `stage_automations` (colunas v1), `deal_history`, `stage_events` iguais antes/depois.
  - `::idempotent`: uma 2.ª chamada não muda o retrato.
  - `::strict_modes`: com `pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES')` e com `''`, migra sem exceção.
  - `::from_42_and_43`: `pipe2_sub('pipe2_v1_snap.php', ['--migrate','--db=' . $db42])` → `{from: 42, to: 44}`; numa
    base na 43 (`schema_version` posto a 43 depois de `migrate_pipeline2_43`) → `{from: 43, to: 44}`;
    `schema_version = '44'`.
  - `::lock`: com outra ligação a segurar `GET_LOCK(CONCAT('topper_pipe2_', DATABASE()), 30)`, `migrate_pipeline2_44`
    lança `RuntimeException` cuja mensagem começa por «Passo 44»; o lock fica livre depois.
- [ ] **Step 2: Escrever `tests/pipe2_v2_lib_test.php`:**
  - `p2v_db()` → `setting($tp,'schema_version') === (string)SCHEMA_VERSION` e `$GLOBALS['pdo'] === $tp`.
  - `p2v_funnel($tp,'vfx')`: etapas `level 'deal'` = `[['X-Ray','xray',10,''],['Proposta','commercial',20,'proposal'],
    ['Aguardar feedback','commercial',21,'feedback'],['Kickoff','kickoff',50,''],['Em execução','production',70,'exec'],
    ['Entrega e pagamento final','production',80,'delivery'],['Concluído','done',90,'']]`; sub-etapas = os nomes de
    `PIPE2_SEED_FRONTS['vfx'][0]`, `rounds_included` 2 em Revisões; `service_code 'vfx'`, `is_active 1`,
    `department_id` = id de «Pós-Produção e VFX».
  - `p2v_world($tp)` dá 7 funis; `p2v_deal($tp, $w, ['vfx','3d'])` → `phase 'production'`, `stage_id` = Kickoff do
    funil de teste vfx, 2 frentes (`vfx` com `is_main 1`), `status 'queued'`; com `['at' => 'exec']` → frentes
    `running` na 1.ª sub-etapa.
  - Na bancada (`$pdo`), `p2v_funnel($pdo,'vfx')` lança `InvalidArgumentException`; `p2v_bench_funnel($pdo,'lib')` tem
    `service_code ''`, `v2_only 1`, `is_base 0`, e a limpeza apaga-o.
  - `p2v_probe($db, 1, [['fn'=>'can','args'=>['financeiro', 1]]])` → `[true]`; com uid 3 → `[false]`; com uid 0 e
    `['fn'=>'can','args'=>['pipeline',1]]` → `[false]`.
  - `p2v_probe($db, 99, [['fn'=>'my_alerts','args'=>[]]])` → um array com a chave `'deal'` (o probe pôs o PDO à
    frente); `['fn'=>'exec','args'=>['ls']]` → `{"error": …}` e código 1 (fora da lista).
  - `p2v_race` com duas corridas de um script de eco (`tests/pipe2_probe.php` com `--calls` de `[]`) devolve duas saídas
    com código 0, `timeout false`, e os dois `.ready` foram escritos antes de F (o `mtime` de F ≥ o dos dois); uma
    corrida que sai antes de escrever o `.ready` (`--db=p2_inexistente`) não prende o `p2v_race`: volta em < 16 s com o
    código ≠ 0 dessa corrida e a outra com 0; no fim não fica nenhum `F*` no `sys_get_temp_dir()`.
  - `pipe2_cleanup` numa base privada apaga também as linhas de `deal_line_steps`, `deal_line_deps`, `deal_line_execs`,
    `task_deletions`, `pipe2_notices` dos negócios P2T (inserir uma de cada e confirmar 0 depois).
- [ ] **Step 3: Correr e ver falhar.** `php tests/pipe2_schema44_test.php; php tests/pipe2_v2_lib_test.php`. Esperado:
  erro fatal (faltam `migrate_pipeline2_44.php` e `pipe2_v2_lib.php`).
- [ ] **Step 4: Implementar** `migrate_pipeline2_44.php` (secção «Esquema 44»), `migrate.php`, `PIPE2_CODES`,
  `pipe2_v2_lib.php`, `pipe2_probe.php`, `pipe2_tree.php`. Em `tests/pipe2_lib.php`: `pipe2_deal` aceita também
  `last_touch`, `stage_entered_at`, `delivered_at`, `is_paid`, `origin_funnel_id` (sobrepõe o por defeito), `done_at`,
  `contact_id`; `pipe2_front` aceita `started_at`, `done_at`, `stage_entered_at`, `start_mode`, `start_after_id`;
  `pipe2_task` aceita `created_at`, `repeat_rule`, `checklist`, `attachments`, `member_id`, `help_by` e (v2)
  `gate_stage_id`; `pipe2_delete_deals` apaga também, se a tabela existir, de `deal_line_steps`, `deal_line_deps`,
  `deal_line_execs`, `task_deletions`, `pipe2_notices`, `cc_rv_rounds`, `cc_rv_items`, `cc_cards` (dos `cc_boards` do
  negócio), `cc_boards` e `fin_docs` por `deal_id`. O `pipe2_lib.php` continua sem incluir nenhum `pipe2_*.php` (corre
  nas árvores revertidas).

  **Esquema 44** (`db_create` com corpo MySQL; `db_add_column` com definição MySQL / SQLite):
  `stage_automations.action` `VARCHAR(8) NOT NULL DEFAULT 'task'` / `TEXT NOT NULL DEFAULT 'task'`;
  `stage_automations.trig_n` `SMALLINT NULL` / `INTEGER NULL`; `tasks.gate_stage_id` `INT UNSIGNED NULL` / `INTEGER NULL`;
  `pipe2_notices`: `id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`, `deal_id INT UNSIGNED NOT NULL`,
  `line_id INT UNSIGNED NULL`, `stage_id INT UNSIGNED NULL`, `cycle_ym VARCHAR(7) NOT NULL DEFAULT ''`,
  `kind VARCHAR(10) NOT NULL DEFAULT 'rule'`, `rule_id INT UNSIGNED NULL`, `user_id INT UNSIGNED NULL`,
  `title VARCHAR(200) NOT NULL DEFAULT ''`, `body VARCHAR(255) NOT NULL DEFAULT ''`, `nkey VARCHAR(64) NOT NULL DEFAULT ''`,
  `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`, os 3 índices de `::columns`.
- [ ] **Step 5: Atualizar os testes da fase 0 para a 44.** `pipe2_schema_test::idempotent`: `run_migrations` da 42 dá
  `{from: 42, to: SCHEMA_VERSION}` e `schema_version === (string)SCHEMA_VERSION`. `pipe2_v1_unchanged_test`: o `'43'`
  da versão atual passa a `(string)SCHEMA_VERSION` e `[42, 43]` a `[42, SCHEMA_VERSION]` (o 42 da linha de base fica).
  `pipe2_rollback_test`: monta as árvores com `pipe2_tree`; os 8 de `P2R_KEEP` passam a vir de
  `const P2R_PHASE0 = '8c581ae'` (`git show`), não da cópia de trabalho (os de agora já têm ganchos que pedem ficheiros
  que a árvore de 428480f não tem); `[42, 43]`/`[43, 43]`/`43` da base migrada passam a `SCHEMA_VERSION`; novo
  `::phase1_rollback`: a árvore de 8c581ae **inteira** contra uma base privada migrada pelo código de agora → `--migrate`
  dá `{from: SCHEMA_VERSION, to: SCHEMA_VERSION}` (o `migrate.php` da fase 0 é 43 < 44: não migra nem desfaz) e a
  fotografia `--php` dessa árvore dá `pipe2_diff(base42.json.php, …) === []`. Atualizar o comentário do cabeçalho.
- [ ] **Step 6: Correr e ver passar.** `php tests/pipe2_schema44_test.php; php tests/pipe2_v2_lib_test.php; php tests/pipe2_codes_test.php; for t in lib core schema model filters screens includes v1_unchanged rollback; do php tests/pipe2_${t}_test.php || echo FALHOU $t; done`
  e `php -l` nos ficheiros mudados. Esperado: `OK n` em todos, nenhum «FALHOU». Confirmar
  `mariadb topper -N -e "SELECT svalue FROM settings WHERE skey='schema_version'"` → `44`.
- [ ] **Step 7: Entregar** a lista de ficheiros. Sem commit.

### Task 2: Categorias e códigos dos serviços (`pipe2_category.php`)

**Files:**
- Create: `admin/inc/pipe2_category.php`, `tests/pipe2_category_test.php`

**Interfaces:**
- Consumes: `PIPE2_SERVICES`, `PIPE2_V1_MAP` (`pipe2.php`); tabela `pipe2_categories` (43).
- Produces:
  ```php
  function pipe2_category_rules(PDO $pdo): array;              // ativas, por position, id: [['code','name','need_all'=>[],'need_any'=>[],'need_min'=>int,'exclude_any'=>[]], …]; tabela em falta → []
  function pipe2_category_of(array $codes, array $rules): string; // puro: < 2 códigos distintos → ''; senão a 1.ª regra que bate (todos os need_all, ≥ need_min de need_any, nenhum de exclude_any); nenhuma → ''
  function pipe2_category_name(PDO $pdo, string $code): string;  // '' → ''
  function pipe2_services_label(array $codes): string;         // nomes de PIPE2_SERVICES pela ordem v2, juntos com ' + '
  function pipe2_funnel_codes(array $funnel): array;           // [service_code] se preenchido; senão PIPE2_V1_MAP de cada xray_kind da lista (vírgulas), sem repetir, pela ordem v2
  function pipe2_deal_codes(PDO $pdo, array $deal): array;     // códigos dos funis das deal_lines vivas (status ≠ merged); sem linhas, os do funil do negócio
  function pipe2_deal_category(PDO $pdo, array $deal): string; // calculada agora
  function pipe2_category_store(PDO $pdo, array $deal, ?int $by = null): string;   // grava deals.category/category_at só se category_at for NULL; deal_log 'p2_category' (system se $by null); devolve a gravada
  function pipe2_category_recalc(PDO $pdo, array $deal, ?int $by = null): string;  // «Recalcular»: grava sempre e regista
  ```

- [ ] **Step 1: Escrever o teste a falhar** (TDD obrigatório). Com `$rules = pipe2_category_rules($tp)` da base migrada:
  - `::cases`: `pipe2_category_of(['vfx','3d','md'], $rules) === 'cgi'`; `['md','3d'] → ''`; `['vfx'] → ''`;
    `['ed'] → ''`; `['ed','vfx'] → 'postprod'`; `['vfx','3d','md','cmp','ed'] → 'postprod'`; `['vfx','3d'] → 'cgi'`;
    `['3d','cmp'] → 'cgi'`; `['vfx','3d','ed'] → 'postprod'`; `['ed','adv'] → ''`; `['cam','adv'] → ''`; a ordem e os
    repetidos não contam (`['3d','vfx','3d'] → 'cgi'`).
  - `::order_postprod_first`: com uma regra extra inserida com `position 0` que também bate em `vfx,3d,ed`, ganha a de
    menor posição; `is_active 0` ignora-se.
  - `::names`: `pipe2_category_name($tp,'postprod') === 'Pós-produção'`, `'cgi' → 'CGI'`;
    `pipe2_services_label(['md','3d']) === '3D + Motion Design'`.
  - `::funnel_codes`: `['service_code'=>'3d','xray_kind'=>'cgi'] → ['3d']`; `['service_code'=>'','xray_kind'=>'ads'] → ['adv']`;
    `'buddy' → ['cam']`; `'vfx,cgi' → ['vfx','3d']`; `'' → []`; nenhum resultado contém `'blaine'`.
  - `::deal_codes_and_category`: num negócio `p2v_deal(['vfx','3d','md'])`, `pipe2_deal_codes` = `['vfx','3d','md']` e a
    categoria `'cgi'`; com a frente md `merged` → `['vfx','3d']`.
  - `::stored_once_and_recalc`: `pipe2_category_store` grava `'cgi'` e `category_at`; mudar a regra `cgi` para
    `need_min 3` e chamar outra vez → continua `'cgi'` (congelada); `pipe2_category_recalc` → `''` e regista
    `deal_history 'p2_category'`.
  - `::v1_noop`: nenhuma função grava num negócio v1 sem ser chamada (o `pipe2_category_store` não é chamado por nenhum
    código v1 — `grep` no teste: só `admin/inc/pipe2_*.php` o chamam).
- [ ] **Step 2: Correr e ver falhar.** `php tests/pipe2_category_test.php` → erro fatal (função indefinida).
- [ ] **Step 3: Implementar** `pipe2_category.php`.
- [ ] **Step 4: Correr e ver passar.** `php tests/pipe2_category_test.php; php tests/pipe2_includes_test.php; php -l admin/inc/pipe2_category.php` → `OK n`.
- [ ] **Step 5: Entregar.** Sem commit.

### Task 3: Frentes e modelo (`pipe2_fronts.php`, `funnel_create`, `del_stage`)

**Files:**
- Create: `admin/inc/pipe2_fronts.php`, `tests/pipe2_fronts_test.php`, `tests/pipe2_stage_guard_test.php`
- Modify: `admin/inc/pipeline.php` (só `funnel_create`, D2), `admin/funis.php` (`del_stage`: depois da verificação dos
  negócios, `require_once __DIR__ . '/inc/pipe2_fronts.php';` e `if (($why = pipe2_stage_delete_block($pdo, (int)$s['id'])) !== '') throw new RuntimeException($why);`),
  `tests/pipe2_model_test.php` (casos novos no fim)

**Interfaces:**
- Consumes: tarefa 1; `pipe2_schema_ready`, `PIPE2_LINE_STATUS`; `xsell_values`, `budget_sheet_get`.
- Produces:
  ```php
  const PIPE2_FRONT_LIVE = ['planned','queued','running','depwait','ready','ongoing'];
  function pipe2_funnel_is_v2(PDO $pdo, ?int $funnelId): bool;   // D1
  function pipe2_deal_is_v2(PDO $pdo, ?array $deal): bool;       // D1 (deal['funnel_id'])
  function pipe2_v2_funnels_sql(): string;                       // subconsulta «SELECT s.funnel_id FROM funnel_stages s WHERE s.level = 'deal' AND s.retired_at IS NULL AND s.role IN ('exec','delivery') GROUP BY s.funnel_id HAVING COUNT(DISTINCT s.role) = 2»
  function pipe2_line_is_v2(array $line): bool;                  // puro (D1)
  function pipe2_front_stages(PDO $pdo, int $funnelId): array;   // level 'front', retired_at NULL, ORDER BY position, id (sem cache)
  function pipe2_deal_stage(PDO $pdo, int $funnelId, string $key): ?array;  // key xray|proposal|feedback|kickoff|exec|delivery|done; vivas, level deal; sem cache
  function pipe2_fronts(PDO $pdo, int $dealId, bool $liveOnly = true): array;  // l.* + funnel_name, service_code, stage_name, stage_role, stage_position; is_main DESC, position, id
  function pipe2_front_get(PDO $pdo, int $lineId): ?array;       // a mesma forma
  function pipe2_user_ok(PDO $pdo, $uid): ?int;                  // ativo, sem deactivated_at, não superadmin
  function pipe2_service_head(PDO $pdo, int $funnelId): ?int;    // head_user_id do funnels.department_id que passe pipe2_user_ok; senão o do departments.parent_id; senão null
  function pipe2_services_ensure(PDO $pdo, array $deal): int;    // v2: garante a frente principal (funil do negócio, is_main 1, planned, created_at); devolve o id
  function pipe2_service_add(PDO $pdo, array $deal, int $funnelId, ?int $by = null): int;  // um serviço planned (funil v2, antes do Kickoff); idempotente; XSELL_FRESH
  function pipe2_kickoff_fronts(PDO $pdo, array $deal, ?int $by = null): array;  // ['created' => [ids], 'queued' => [ids]] (D4/D6)
  function pipe2_line_is_monthly(array $line): bool;
  function pipe2_line_value(array $line): ?float;                // avença: monthly_fee × fee_months; senão deal_lines.value
  function pipe2_deal_value(PDO $pdo, array $deal): float;       // D15
  function pipe2_store_values(PDO $pdo, array $deal): void;      // X3
  function pipe2_billing_period(array $line, string $start): array;  // ['start' => 'AAAA-MM-01', 'end' => último dia de início + fee_months − 1]
  function pipe2_billing_ended(array $line, string $today): bool;    // cancelled_at preenchido ou (billing_end e $today > billing_end)
  function pipe2_requirements(PDO $pdo, array $deal): array;     // M4 (o substituto v2 de xsell_requirements)
  function pipe2_stage_delete_block(PDO $pdo, int $stageId): string; // D21; '' = pode apagar
  ```
  `pipe2_kickoff_fronts` não toma lock nem abre transação: é uma peça de `pipe2_kickoff` (tarefa 11), que a chama
  **dentro** de `pipe2_lock_deal`, numa transação com a linha do negócio bloqueada (`FOR UPDATE`) e depois de reler o
  negócio e as frentes (Review Focus 7); o SELECT antes de INSERT só é idempotente aí (`deal_line_steps` não tem UNIQUE
  e `deal_lines` tem o UNIQUE `deal_id, funnel_id`, que rebentaria numa corrida). Nenhum outro código de produção a
  chama (o teste chama-a em sequência). Garante a principal;
  cada frente `planned` viva → `queued` (`stage_id` NULL); responsável vazio → (principal: `deals.exec_owner_id`) →
  `pipe2_service_head`; cria as linhas de `deal_line_steps` em falta (uma por sub-etapa, ciclo `''`, `plan_days` da
  sub-etapa); `XSELL_FRESH = true`. `pipe2_store_values`: 1 frente viva → valor = `pipe2_deal_value`; 2+ →
  `xsell_values` com o negócio passado com `'budget'` = total efetivo (`(float)budget > 0 ? budget : value_estimate`,
  contornando o «0.00» verdadeiro em PHP), e as avenças com `pipe2_line_value`. `pipe2_requirements`: num negócio v2
  com 2+ serviços vivos, `[['label' => 'Orçamento: cada linha com o serviço', 'ok' => bool, 'hint' => 'Falta o serviço em N linha(s) do orçamento.']]`
  (linhas da versão aceite, senão da última, com `funnel_id` fora dos funis das frentes); senão `[]`.

- [ ] **Step 1: Escrever `tests/pipe2_fronts_test.php`:**
  - `::predicate`: um funil `p2v_funnel` é v2; o VFX real (`xray_kind 'vfx'`, `is_base 1`) não; o Motion Design real é;
    para **todos** os negócios do dump, `pipe2_deal_is_v2` é `false`; negócio com `funnel_id` NULL → `false`; numa
    fresca **não migrada** (42) → `false` sem exceção; o funil com só `exec` (sem `delivery`) → `false`; com o `exec`
    retirado (`retired_at`) → `false`.
  - `::predicate_bench` (bancada, só leitura, com `pipe2_lock($pdo)` e depois de `pipe2_cleanup($pdo)`):
    `SELECT id FROM funnels WHERE id IN (pipe2_v2_funnels_sql()) AND name NOT LIKE 'P2T%'` = os ids de `service_code`
    `md`/`cmp`/`ed`; 0 negócios nesses funis; `pipe2_deal_is_v2` falso para todos os negócios com `code` que não
    começa por `P2T`.
  - `::line_v2`: `['status'=>'queued'] → true`, `'active' → false`, `'planned' → false`, `['status'=>'planned','billing'=>'monthly'] → true`.
  - `::single_service_has_front`: negócio v2 de um só serviço sem linhas (`'lines' => false`) → `pipe2_services_ensure`
    cria 1 frente `planned`, `is_main 1`; a 2.ª chamada devolve o mesmo id e não cria outra.
  - `::kickoff_idempotent`: `pipe2_kickoff_fronts` num `p2v_deal(['vfx','3d'], ['at'=>'kickoff','status'=>'planned'])`
    → `queued` 2, `created` []; 6 linhas de `deal_line_steps` por frente; a 2.ª chamada devolve `created` e `queued`
    vazios e não muda contagens; num negócio sem linhas cria a principal (`created` = [id]).
  - `::main_not_special`: a principal e a outra recebem o mesmo tratamento (estado, linhas de passos, responsável).
  - `::service_head`: funil vfx de teste → 6 (responsável de «Pós-Produção e VFX»); adv e cam → 7 (departamento-pai de
    8/9); com o 6 desativado (`deactivated_at`) → o do pai do 6 (nenhum) → `null`; com um superadmin como responsável →
    `null`.
  - `::values`: 1 frente, `budget '0.00'`, `value_estimate 1500` → `deal_lines.value = 1500.00`; 2 frentes com linhas de
    orçamento 300 (vfx) e 100 (3d), total 4000 → 3000 e 1000; frente avença 500 × 12 → 6000.
  - `::deal_value`: só avença 500 × 12 → 6000; avença + pontual com `budget 9000` → 9000; sem avenças → `budget`, senão
    `value_estimate`.
  - `::billing_period`: início `2026-11-15`, 12 meses → `['start'=>'2026-11-01','end'=>'2027-10-31']`; 1 mês →
    `2026-11-30`; `pipe2_billing_ended` com `cancelled_at` → `true`; `today` = `billing_end` → `false`; dia seguinte → `true`.
  - `::budget_service_required`: v2 com 2 serviços e uma linha de orçamento sem `funnel_id` → item `ok false` e hint
    «Falta o serviço em 1 linha(s) do orçamento.»; com o `funnel_id` → `ok true`; um só serviço → `[]`; v1 → `[]`.
  - `::stage_in_use`: etapa sem nada → `''`; com uma `deal_line` → a mensagem de D21; com uma tarefa feita → a mesma.
  - `::strict`: as consultas de `pipe2_v2_funnels_sql`, `pipe2_fronts` e `pipe2_kickoff_fronts` em
    `ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES`.
- [ ] **Step 2: Acrescentar a `tests/pipe2_model_test.php`** (depois do `::roles`, na mesma fresca):
  `::funnel_create_six` — `funnel_create($tp, 'P2T · seis', [], '', true)` dá as 7 etapas de negócio de D2 (nome, kind,
  posição, role); `funnel_create($tp, 'P2T · cinco')` (sem bandeira) dá as 5 de antes; com
  `INSERT … ('pipeline_v2_at', …)` numa transação, `funnel_create($tp, 'P2T · bandeira')` dá 7; `rollBack`.
  `::del_stage_guards` — `pipe2_stage_delete_block` como no `::stage_in_use`.
- [ ] **Step 3: Escrever `tests/pipe2_stage_guard_test.php`** (bancada, HTTP, `pipe2_lock`, uid 1, regras de dados do
  plano da fase 0: só POST para funis `P2T · ` deste teste): funil `pipe2_funnel($pdo,'guarda')` + etapa `production`
  inserida (posição 60); com uma frente P2T nessa etapa, `POST funis.php do=del_stage` deixa a etapa e mostra a
  mensagem de D21; sem a frente e com uma tarefa P2T → recusa igual; sem nada → apaga; uma etapa com um negócio
  continua com «Há negócios nesta etapa: mova-os primeiro.».
- [ ] **Step 4: Correr e ver falhar.** `php tests/pipe2_fronts_test.php; php tests/pipe2_model_test.php; php tests/pipe2_stage_guard_test.php`
  → erro fatal / FALHA em `::funnel_create_six` e no guarda HTTP.
- [ ] **Step 5: Implementar** `pipe2_fronts.php`, `funnel_create` (D2) e o guarda em `funis.php`.
- [ ] **Step 6: Correr e ver passar** os três testes + `php tests/pipe2_lib_test.php` (o `pipe2_funnel` continua com 5
  etapas) + `php tests/pipe2_screens_test.php` + `php tests/pipe2_v1_unchanged_test.php` + `php tests/pipe2_includes_test.php`
  + `php -l` → `OK n`.
- [ ] **Step 7: Entregar.** Sem commit.

### Task 4: Avisos (`pipe2_notify.php`, `alerts.php`)

**Files:**
- Create: `admin/inc/pipe2_notify.php`, `tests/pipe2_notify_test.php`
- Modify: `admin/inc/alerts.php` (`require_once __DIR__ . '/pipe2.php';` no topo; em `my_alerts`, depois do bloco
  «Pipeline (§19.4, §19.10)»: `require_once __DIR__ . '/pipe2_notify.php'; $out['deal'] = array_merge($out['deal'], pipe2_notices_for($pdo, $uid));`)

**Interfaces:**
- Produces:
  ```php
  const PIPE2_NOTICE_ICONS = ['replan' => 'clock', 'stale' => 'pause', 'dep_met' => 'check', 'rule' => 'bell', 'cycle' => 'refresh'];
  function pipe2_notice_key(string $kind, int $dealId, ?int $lineId, ?int $stageId, string $cycle = '', ?int $ruleId = null): string;  // "kind:deal:line:stage:cycle[:rule]" (≤ 64; 0 para null)
  function pipe2_notice_add(PDO $pdo, array $n): ?int;    // n: deal_id, line_id, stage_id, cycle_ym, kind, rule_id, user_id, title, body, nkey; sem user_id ou com a nkey já gravada → null
  function pipe2_notices_for(PDO $pdo, int $uid, int $days = 14): array;  // itens de my_alerts: key 'p2n:<id>', kind 'deal', icon, sev 'warn', title, sub (body), date (AAAA-MM-DD), url '/admin/negocio.php?id=<deal>'; sem a tabela → []
  ```
- [ ] **Step 1: Escrever o teste a falhar:**
  - `::once_per_step`: duas `pipe2_notice_add` com a mesma `nkey` → 1 linha; a 2.ª devolve `null`; outra sub-etapa →
    nova linha.
  - `::no_recipient`: `user_id` null → `null`, nada gravado.
  - `::for_user`: só as do utilizador, dos últimos 14 dias, ordem por data; os campos exatos acima.
  - `::preferences` (`p2v_probe` como 99): `notifications` traz a chave `'p2n:<id>'` com `unread true`; com
    `users.prefs = '{"notif":{"deal":{"app":0}}}'` para o 99 → não traz.
  - `::v1_noop`: numa base sem avisos, `pipe2_notices_for` dá `[]`; numa fresca na 43 (sem a tabela) dá `[]` sem
    exceção; o probe `notifications` do uid 1 numa base privada sem avisos não tem nenhuma chave `p2n:` e tem as mesmas
    chaves que o probe `my_alerts` do uid 1 (ambas na lista do probe da tarefa 1) sem o grupo dos avisos (o gancho não
    acrescenta nem tira nada).
- [ ] **Step 2: Correr e ver falhar.** `php tests/pipe2_notify_test.php` → erro fatal.
- [ ] **Step 3: Implementar.** A verificação da tabela usa `db_has_table` guardado num `static` só quando `true`.
- [ ] **Step 4: Correr e ver passar.** `php tests/pipe2_notify_test.php; php tests/pipe2_includes_test.php; php tests/pipe2_v1_unchanged_test.php; php -l admin/inc/alerts.php admin/inc/pipe2_notify.php`.
- [ ] **Step 5: Entregar.** Sem commit.

### Task 5: Dependências, plano e datas (`pipe2_deps.php`)

**Files:**
- Create: `admin/inc/pipe2_deps.php`, `tests/pipe2_deps_test.php`, `tests/pipe2_dates_test.php`

**Interfaces:**
- Consumes: tarefa 3 (`pipe2_fronts`, `pipe2_front_stages`, `pipe2_front_get`), tarefa 2 (`pipe2_deal_category`,
  `pipe2_funnel_codes`); `flow_add_workdays`, `flow_due`, `deal_log`.
- Produces:
  ```php
  function pipe2_models_for(PDO $pdo, string $category): array;   // [[from_code, from_name, to_code, to_name], …] por position; is_active = 1; código pelo service_code do funil da sub-etapa
  function pipe2_model_links(array $links, array $codes): array;  // puro (D8): contração dos ausentes, sem poda, sem repetidos, pela ordem de D8; $links = [[from_code, from_name, to_code, to_name], …] pela posição
  function pipe2_deps_instantiate(PDO $pdo, array $deal, ?int $by = null): int;   // pela deals.category (senão calculada); resolve cada código pela frente viva desse serviço e o nome pela sub-etapa do funil dela; idempotente por SELECT antes de INSERT (deal_line_deps não tem UNIQUE: só é seguro dentro do lock e da transação de pipe2_kickoff, tarefa 11, que é quem a chama); nunca recria removidas; source 'model'
  function pipe2_deps_into(PDO $pdo, int $lineId, int $stageId, bool $unmetOnly = true): array;  // ligações vivas (removed_at NULL) para (frente, sub-etapa)
  function pipe2_deps_mark_met(PDO $pdo, int $dealId, int $fromLineId, int $fromStageId): array;  // ['met' => [ids], 'released' => [[line_id, stage_id], …]] (só os destinos sem mais nenhuma por cumprir)
  function pipe2_dep_add(PDO $pdo, array $deal, int $fromLineId, int $fromStageId, int $toLineId, int $toStageId, ?int $by = null): int;  // source 'manual'; recusa frentes de outro negócio, a mesma frente, sub-etapas de outro funil e ciclos («Esta ligação criava um ciclo.»); deal_log 'p2_dep'
  function pipe2_dep_remove(PDO $pdo, array $deal, int $depId, ?int $by = null): void;  // removed_at/removed_by; deal_log 'p2_dep'
  function pipe2_plan_front(PDO $pdo, array $line, string $start): int;   // D17; linhas planeadas
  function pipe2_replan(PDO $pdo, array $line, string $today): int;       // D17; dias úteis deslizados agora (0 em depwait/queued/ready ou sem plano); deal_log 'p2_replan' (system) quando > 0
  function pipe2_front_late_days(array $line, ?array $step, string $today): int;  // dias úteis depois de plan_end; 0 em depwait/queued/ready
  function pipe2_front_delivery(array $deal, array $line): ?string;       // D17 (puro)
  function pipe2_deal_delivery(PDO $pdo, array $deal): ?string;           // deals.delivery_date, senão a maior das frentes vivas
  function pipe2_dates_save(PDO $pdo, array $deal, ?string $common, array $byLine, ?int $by = null): int;  // D17; devolve as tarefas com prazo mudado; deal_log 'dates'
  function pipe2_due_refresh(PDO $pdo, int $dealId): int;                 // como flow_dates_changed, com a entrega da frente, e updated_at = agora nas que mudam
  function pipe2_plan_save(PDO $pdo, array $deal, array $in, ?int $by = null): string;  // ramo v2 de xsell_save_plan: $in['delivery_mode'] 'common'|'front', $in['delivery_date'], $in['line_delivery'][id], $in['line_exec'][id] (só is_admin(), pipe2_user_ok); sem validação de modo; mensagem pt-PT
  ```
- [ ] **Step 1: Escrever `tests/pipe2_deps_test.php`** (TDD obrigatório; funis `p2v_world`):
  - `::model_postprod_full`: os 5 serviços de pós-produção, categoria `postprod` → as 4 ligações do modelo exatamente
    (`3D:Lighting e render → Composit:Preparação de plates`, `VFX:Finais e QC → Composit:Integração e look`,
    `Composit:Composição → Edição:Picture lock`, `Motion Design:Revisões → Edição:Picture lock`).
  - `::partial_combination_links` (`t_eq` exato: a ordem é a de D8): `pipe2_model_links` do modelo `postprod` com
    `['vfx','3d','ed']` → `[['3d','Lighting e render','ed','Picture lock'], ['vfx','Finais e QC','ed','Picture lock']]`
    (exemplo da §34.9; o 3D primeiro porque a sua ligação é a 1.ª do modelo e a da VFX a 2.ª); `postprod` com
    `['3d','ed']` → `[['3d','Lighting e render','ed','Picture lock']]`; modelo `cgi` com `['vfx','3d']` →
    `[['3d','Modelação e lookdev','vfx','Look dev / shot de referência']]` (as duas que entram em Composit não têm
    saída e caem); `cgi` com `['3d','cmp']` → `[['3d','Modelação e lookdev','cmp','Integração e look'],
    ['3d','Lighting e render','cmp','Preparação de plates']]` (a 1.ª contraída por VFX, a 2.ª direta; sem poda);
    sem categoria (modelo vazio) → `[]`.
  - `::contraction_multi_hop` (puro, ligações sintéticas): `[['vfx','A','3d','B'], ['3d','C','md','D'], ['md','E','cmp','F']]`
    com `['vfx','cmp']` → `[['vfx','A','cmp','F']]`; com `['vfx','md','cmp']` → `[['vfx','A','md','D'], ['md','E','cmp','F']]`;
    uma cadeia com repetido (duas ligações de VFX para o mesmo destino por caminhos diferentes) → uma só, na 1.ª posição;
    um ciclo entre dois ausentes (`3d → md` e `md → 3d`) termina e não gera ligações de um serviço para si próprio.
  - `::instantiate_idempotent`: `pipe2_deps_instantiate` num negócio `cgi` (vfx+3d, frentes queued) cria 1 ligação com
    os ids das sub-etapas dos funis de teste; a 2.ª chamada cria 0. Num negócio 3d+cmp (`cgi`) cria as 2 ligações de
    `::partial_combination_links`, com `source 'model'`.
  - `::removed_not_recreated`: depois de `pipe2_dep_remove`, `pipe2_deps_instantiate` cria 0 e regista nada.
  - `::manual_add_rules`: `pipe2_dep_add` de vfx para 3d e depois de 3d para vfx noutra sub-etapa → a 2.ª lança «Esta
    ligação criava um ciclo.»; frente de outro negócio → «Frente não encontrada.»; histórico `p2_dep` com a nota.
  - `::mark_met_releases_when_all_met`: duas ligações para (Ed, Picture lock); marcar a 1.ª → `released` vazio; a 2.ª →
    `released = [[edLine, picture]]`.
  - `::waiting_not_late`: `pipe2_front_late_days` de uma frente `depwait` com `plan_end` no passado → 0; `running` →
    > 0.
  - `::no_replan_while_waiting` (dados): frente `depwait` com a sub-etapa atrasada → `pipe2_replan` devolve 0 e não
    mexe nas linhas.
- [ ] **Step 2: Escrever `tests/pipe2_dates_test.php`:**
  - `::common_or_per_front`: comum `2026-12-15` → `deals.delivery_date` e as frentes NULL; por frente
    `[vfx => 2026-12-10, 3d => 2026-12-20]` → frentes gravadas e `deals.delivery_date = 2026-12-20`.
  - `::front_tasks_use_front_delivery`: uma tarefa de frente aberta com regra `due_mode 'delivery'`, `due_days -2`,
    `due_unit 'd'` → `due_date = 2026-12-08` (da frente vfx), e `updated_at` mudou; a tarefa do negócio fica com a comum.
  - `::plan_and_replan`: `pipe2_plan_front` com `plan_days` 2,3,4,2,1,0 a partir de `2026-11-02` (segunda-feira) →
    datas em dias úteis contíguas; com a 1.ª sub-etapa por fechar em `2026-11-06` (2 dias úteis depois do `plan_end`) →
    `pipe2_replan` = 2, as seguintes deslizam 2 dias úteis, `replanned_days` 2, `deals.delivery_date` igual; no dia
    seguinte → 1; sem plano → 0.
  - `::plan_save_v2`: `pipe2_plan_save` sem sessão de admin com `line_exec` → os responsáveis não mudam; as datas sim;
    num negócio v1 a função não é chamada (ver tarefa 13).
  - `::v1_noop`: num negócio v1 (frentes `active`, `sync_mode 'sync'`), `pipe2_due_refresh` dá os mesmos `due_date`
    que `flow_dates_changed` (as funções desta tarefa só são chamadas pelos ganchos v2 das tarefas 10 e 13).
- [ ] **Step 3: Correr e ver falhar.** `php tests/pipe2_deps_test.php; php tests/pipe2_dates_test.php` → erro fatal.
- [ ] **Step 4: Implementar** `pipe2_deps.php` (D8, D17).
- [ ] **Step 5: Correr e ver passar** + `php tests/pipe2_includes_test.php` + `php -l` → `OK n`.
- [ ] **Step 6: Entregar.** Sem commit.

### Task 6: Leitura por frente e ações de frente (`pipe2_access.php`)

**Files:**
- Create: `admin/inc/pipe2_access.php`, `tests/pipe2_perm_test.php`

**Interfaces:**
- Consumes: tarefa 3 (`pipe2_deal_is_v2`, `pipe2_fronts`); `current_user`, `can`, `is_admin`, `flow_can_edit`,
  `flow_can_bill` (`pipeline_view.php`).
- Produces (todas de sessão, só de leitura, D23):
  ```php
  const PIPE2_FRONT_READ = ['queued','running','depwait','ready','ongoing'];   // frentes depois do Kickoff (nem planned nem merged)
  function pipe2_front_lines_of(PDO $pdo, int $dealId, int $uid): array;  // ids das frentes em PIPE2_FRONT_READ, de um negócio v2 em phase production|done, a que o uid está ligado (D23: responsável, executor interno ou tarefas)
  function pipe2_front_reader(PDO $pdo, array $deal): bool;               // can('negocios') && v2 && phase ∈ {production, done} && pipe2_front_lines_of ≠ []; nunca dá edição
  function pipe2_front_reach_sql(PDO $pdo, string $dealAlias = 'd'): array;  // sem sessão ou sem can('negocios') → ['0=1', []]; senão [sql, args]: <alias>.phase IN ('production','done') AND <alias>.funnel_id IN (pipe2_v2_funnels_sql()) AND EXISTS das frentes em PIPE2_FRONT_READ ligadas ao utilizador da sessão
  function pipe2_front_owner(PDO $pdo, array $deal, array $line): bool;      // sessão: deal_lines.exec_owner_id = uid (só ele; deal_line_execs não conta), frente em PIPE2_FRONT_READ, negócio em production
  function pipe2_can_front_act(PDO $pdo, array $deal, array $line): bool;    // v2, line.deal_id = deal.id, status ≠ merged, e (is_admin() || flow_can_edit($pdo, $deal) || (pipe2_front_owner && can('negocios', PERM_EDIT)))
  function pipe2_can_front_switch(PDO $pdo, array $deal, array $line): bool; // v2, a mesma frente, status ≠ merged, e (is_admin() || (pipe2_front_owner && can('negocios', PERM_EDIT)))
  function pipe2_can_request_invoice(PDO $pdo, array $deal): bool;           // v2 && flow_can_bill($pdo, $deal)
  ```
  «Responsável da frente» (ações e interruptor) = só `deal_lines.exec_owner_id` = uid; `deal_line_execs.user_id` só dá
  leitura (D23). Nenhuma função lê `$_GET`/`$_POST`; o uid vem sempre de `current_user()`.
- [ ] **Step 1: Escrever o teste a falhar** (base privada; todas as verificações por `p2v_probe`). Utilizadores:
  1 admin; 99 comercial (`member`, dono dos negócios do teste); **3** — `member`, que no dump tem Negócios EDIT por
  `perm_defaults('member')` (as exceções dele são só Painel, Leads, Pipeline, Relatórios e Tarefas), por isso o teste
  insere `INSERT INTO permissions (role, user_id, tab, level, scope) VALUES ('', 3, 'negocios', 1, NULL)` (o 3 passa a só
  VER Negócios); `P2T membro` e `P2T executor` inseridos com `role 'member'` sem departamento (Negócios EDIT por
  defeito, âmbito `own`); `P2T sem negócios` (`role 'member'`, exceção `('', <id>, 'negocios', 0, NULL)`). **Antes das
  asserções**, `perm_grid($tp, <linha de users>)['negocios']['level']` tem de dar `PERM_VIEW` para o 3, `PERM_EDIT`
  para o membro e o executor, `PERM_NONE` para o sem negócios e `PERM_EDIT` para o 99; se algum não bater, o teste FALHA
  logo («premissa das permissões errada: …») e não corre os casos. Negócio v2 principal: `p2v_deal(['vfx','3d'],
  ['at' => 'exec', 'owner' => 99])`, frente vfx com `exec_owner_id` = membro e `deal_line_execs.user_id` = executor,
  frente 3d com `exec_owner_id` = 3. Os vínculos de cada caso que não são estes (tarefas, o sem negócios como
  responsável, outro negócio) preparam-se com `p2v_front`/INSERT antes do probe desse caso e desfazem-se depois.
  - `::front_reader_read_only`: com o 3 responsável da frente 3d → para o 3: `record_reach(deal) false`,
    `pipe2_front_reader true`, `record_reach(deal, 2) false`, `flow_can_edit false`; o executor interno (não
    responsável) → `pipe2_front_reader true`; o mesmo vínculo num negócio v1 → `pipe2_front_reader false`; frente
    `merged` → `false`; sem vínculo → `false`; vínculos por tarefa da frente com `member_id` = `team_member_id` do 3, com
    `assignee_id` = 3 e com `help_by` = 3 → `true` cada; o `P2T sem negócios` responsável da frente → `false`.
  - `::not_before_kickoff`: negócio v2 na fase `pipeline` (`p2v_deal(..., ['at' => 'proposal', 'status' => 'planned'])`)
    com o 3 responsável de uma frente `planned` → `pipe2_front_reader false`, `pipe2_front_lines_of` = `[]`, e o
    negócio não sai em `::reach_sql`; o mesmo negócio no Kickoff (frente `queued`) → `true`.
  - `::front_actions`: admin `true`; 99 (dono, `flow_can_edit`) `true`; o membro responsável da frente `true`; o 3
    responsável (só VER Negócios) `false`; o **executor interno** (Negócios EDIT, `deal_line_execs`, não responsável)
    `false`; o sem negócios responsável `false`; outra pessoa `false`; frente de outro negócio `false`; v1 `false`.
  - `::front_switch`: admin e o membro responsável `true`; 99 (dono, não responsável) `false`; o executor interno
    `false`; o 3 responsável (só VER) `false`.
  - `::can_bill`: 99 dono `true`; 3 `false`; admin `true`; num negócio v1 `pipe2_can_request_invoice` é `false` também
    para o admin.
  - `::reach_sql`: `SELECT d.id FROM deals d WHERE <sql>` para o 3 dá exatamente o negócio v2 em produção em que está
    ligado (não o da fase `pipeline` de `::not_before_kickoff`); para o `P2T sem negócios`, ligado ao mesmo negócio, a
    função devolve `['0=1', []]` e a consulta 0 linhas; sem sessão (uid 0) → `['0=1', []]`.
  - `::no_side_effects`: `p2v_sig` de `deals`, `deal_lines`, `tasks`, `permissions` igual antes/depois de todas as
    chamadas (o retrato é tirado depois de inserir as permissões do teste).
- [ ] **Step 2: Correr e ver falhar.** `php tests/pipe2_perm_test.php` → os resultados do probe trazem erro (funções
  indefinidas).
- [ ] **Step 3: Implementar.** Colunas `assignee_id`/`help_by` só se existirem (`db_has_column`).
- [ ] **Step 4: Correr e ver passar** + includes + `php -l`. Anotar no relatório os pontos para a security-review.
- [ ] **Step 5: Entregar.** Sem commit.

### Task 7: «Em risco» (`pipe2_risk.php`)

**Files:**
- Create: `admin/inc/pipe2_risk.php`, `tests/pipe2_risk_test.php`

**Interfaces:**
- Consumes: tarefa 3, tarefa 5 (`pipe2_deal_delivery`, `pipe2_front_delivery`, `pipe2_front_late_days`);
  `alert_limits`, `deal_last_move`, `fin_overdue_days`.
- Produces:
  ```php
  const PIPE2_RISK = ['late_pay' => ['Pagamento em atraso', 'euro', 3], 'late_delivery' => ['Entrega ultrapassada', 'clock', 3],
                      'push' => ['Atraso empurra a entrega', 'clock', 2], 'stale' => ['Sem movimento', 'pause', 1]];
  function pipe2_risk_limit(PDO $pdo): int;                                  // alert_limits($pdo)['deal']
  function pipe2_risk_many(PDO $pdo, array $deals, ?string $today = null): array;  // id => [['code','label','sev','icon','line' => ?id], …] mais grave primeiro; consultas agregadas (sem N+1)
  function pipe2_risk(PDO $pdo, array $deal, ?string $today = null): array; // pipe2_risk_many de um
  function pipe2_risk_label(array $reasons): string;                         // rótulo do mais grave + (n > 1 ? ' +' . (n − 1) : '')
  ```
  Rótulos exatos: «Sem movimento há N d», «Pagamento em atraso», «Entrega ultrapassada há N d», «<Serviço>: entrega
  ultrapassada há N d», «<Serviço>: o atraso empurra a entrega» (D25).
- [ ] **Step 1: Escrever o teste a falhar** (datas fixas com `$today`):
  - `::limit_ge`: `settings deal_stale_days = 5` na base privada (`settings($tp, true)`); último movimento há 5 dias →
    `stale`; há 4 → nada; o rótulo «Sem movimento há 5 d».
  - `::movement_includes_tasks_and_substeps`: `last_touch` há 20 dias mas uma tarefa feita ontem → nada; ou uma linha
    de `deal_line_steps` com `started_at` ontem → nada.
  - `::waiting_and_queued_dont_count`: negócio em Em execução com as frentes todas `depwait`/`queued` e sem movimento há
    20 dias → sem `stale`.
  - `::late_payment_only_issued`: `fin_docs` `requested` vencido → nada; `issued` com `due_date` passado e por pagar →
    `late_pay`; negócio concluído sem faturas (o «legacy» do `fin_receivables`) → nada; `paid` → nada.
  - `::late_delivery_deal_and_front`: `deals.delivery_date` ontem, sem `delivered_at`, em produção → `late_delivery`;
    só a frente 3d com entrega ontem e não Pronta → «3D: entrega ultrapassada há 1 d»; frente Pronta → nada.
  - `::substep_late_only_if_pushes`: sub-etapa atrasada com o `plan_end` da Revisões ainda antes da entrega da frente →
    nada; depois → `push`.
  - `::label_plus_one`: `late_pay` + `stale` → «Pagamento em atraso +1».
  - `::no_money_in_labels`: nenhum rótulo contém «€», `FAT-` nem o número da fatura.
  - `::strict_group_by`: `pipe2_risk_many` de 3 negócios em `ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES` sem exceção e com o
    mesmo resultado; o número de consultas não cresce com o número de negócios (contar com um PDO de `query` contado ou
    comparar 3 vs 30 negócios: `< 12` consultas nos dois).
  - `::v1_negocio`: um negócio v1 em produção com `delivery_date` ultrapassada → `late_delivery` (a função serve os dois;
    não está ligada a ecrã nenhum nesta fase).
- [ ] **Step 2: Correr e ver falhar.** `php tests/pipe2_risk_test.php`.
- [ ] **Step 3: Implementar.** `pagamento em atraso` = `fin_docs` `direction 'in'`, `status IN ('issued','partial')`,
  `deleted_at IS NULL`, `doc_type <> 'credit'`, saldo `total − paid_amount > 0.005` e `fin_overdue_days(row) > 0`.
- [ ] **Step 4: Correr e ver passar** + includes + `php -l`.
- [ ] **Step 5: Entregar.** Sem commit.

### Task 8: Métricas v2 (`pipe2_metrics.php`, `budget_accept`)

**Files:**
- Create: `admin/inc/pipe2_metrics.php`, `tests/pipe2_metrics_test.php`, `tests/pipe2_totals_test.php`
- Modify: `admin/inc/budget.php` (`require_once __DIR__ . '/pipe2.php';` no topo; no fim de `budget_accept`:
  `require_once __DIR__ . '/pipe2_fronts.php'; $d = deal_get($pdo, (int)$sheet['deal_id']); if ($d && $d['phase'] === 'production' && pipe2_deal_is_v2($pdo, $d)) pipe2_store_values($pdo, $d);`)

**Interfaces:**
- Consumes: tarefa 2 (`pipe2_funnel_codes`, `pipe2_category_of`, `pipe2_category_rules`), tarefa 3
  (`pipe2_deal_value`, `pipe2_store_values`); `metrics_deals`, `mx_goal_actual`, `mx_in`, `MX_GOAL_METRICS`.
- Produces:
  ```php
  const PIPE2_GOAL_METRICS = ['concluidos' => 'Concluídos', 'valor_concluido' => 'Valor concluído'];
  function pipe2_mx_funnel_codes(PDO $pdo): array;                       // funnel_id => [códigos] (D26)
  function pipe2_mx_codes(array $d, array $fcodes): array;               // códigos de um registo de metrics_deals: cur_fu e sv; nunca fu
  function pipe2_mx_categories(PDO $pdo): array;                         // deal_id => deals.category (gravada)
  function pipe2_mx_filter(array $deals, array $fcodes, array $cats, array $f): array;  // f: svc (código), cat (gravada; sem gravada, calculada pelos códigos), or, pe
  function pipe2_goal_actual(array $deals, array $fcodes, string $metric, string $scope, string $key, string $from, string $to): array;  // a mesma forma de mx_goal_actual; scope company|service|user; metric ∈ MX_GOAL_METRICS ∪ PIPE2_GOAL_METRICS (concluidos/valor_concluido por done_at)
  function pipe2_deal_values(PDO $pdo, array $deals): array;             // id => pipe2_deal_value, por lote
  function pipe2_totals(array $deals, array $values, bool $withProduction): array;  // ['open' => ['n','value'], 'won_to_bill' => ['n','value','by' => ['kickoff','exec','delivery' => n]]]; open = pipeline (+ production com $withProduction); won_to_bill = production em Kickoff/Em execução/Entrega; sem ponderado
  function pipe2_substep_times(PDO $pdo, array $o = []): array;          // [['code','stage_id','stage','n','secs','avg'], …] por serviço → sub-etapa, de stage_events com line_id; espera fora; reentradas somadas; o: from, to, deal_ids
  function pipe2_front_margin(PDO $pdo, int $dealId): array;             // line_id => ['value','costs','team','shared','margin'] (só para quem tem Financeiro: quem chama decide)
  ```
  `pipe2_substep_times`: por frente, ordenar os eventos `p2_front`/`p2_wait` por `at, id`; um `p2_front` para S abre um
  intervalo em S que fecha no evento seguinte da frente; `p2_wait` abre um intervalo de espera (não conta); o
  intervalo ainda aberto não conta. `pipe2_front_margin`: valor = `pipe2_line_value`; custos = `costs.line_id` + `fin_docs`
  `direction 'out'` com `line_id` (não anulados nem apagados); equipa = custo das `budget_lines kind 'team'` da versão
  aceite com `funnel_id` = funil da frente (principal: também as sem `funnel_id` quando há uma só frente); o que não tem
  frente (custos sem `line_id`, linhas de equipa sem serviço com 2+ frentes) reparte-se pelo valor de cada frente em
  `shared`; `margin = value − costs − team − shared`.
- [ ] **Step 1: Escrever `tests/pipe2_metrics_test.php`:**
  - `::by_v2_service`: negócio com `origin_funnel_id` = Ads (`xray_kind 'ads'`) e `funnel_id` = Adverts → aparece em
    `svc 'adv'`; um com funil combinado `xray_kind 'vfx,cgi'` aparece em `vfx` e em `3d`; frentes 3d de um negócio vfx
    aparecem em `3d`.
  - `::origin_untouched`: negócio com origem VFX e funil atual 3D não aparece em `svc 'vfx'`; o `mx_filter` v1 com
    `fu` = VFX continua a mostrá-lo (igual a antes).
  - `::goal_service`: `pipe2_goal_actual(…, 'kickoffs', 'service', '3d', …)` conta só os ganhos (Kickoff) com 3D;
    `concluidos` conta pelo `done_at` no período; `valor_concluido` soma o valor.
  - `::category_filter_frozen` (`::category_frozen`): negócio com `deals.category = 'cgi'` gravada; mudar as regras
    para o que daria `''` → `cat 'cgi'` continua a incluí-lo.
  - `::substep_time`: eventos inseridos para uma frente: entra em S1 às 10:00, `p2_wait` às 12:00, entra em S2 às
    15:00, volta a S1 às 16:00 («Desfazer»), entra em S2 às 17:00 → S1 = 2 h + 1 h = 10 800 s em 2 entradas; a espera
    (12:00–15:00) fora; S2 = 1 h (16:00−15:00) e o intervalo aberto desde 17:00 não conta.
  - `::front_margin`: 2 frentes (3000 e 1000), custo de 200 com `line_id` vfx, 100 sem frente, equipa 400 (vfx) →
    vfx `costs 200`, `team 400`, `shared 75`, `margin 2325`; 3d `shared 25`, `margin 975`.
  - `::values_follow_accepted_budget`: negócio v2 em produção, 2 frentes; aceitar uma versão nova com outro preço
    (`budget_accept`) → `deal_lines.value` acompanha; num negócio v1 não muda nenhuma `deal_lines.value`.
  - `::strict`: `pipe2_substep_times` e `pipe2_mx_funnel_codes` em modo estrito.
- [ ] **Step 2: Escrever `tests/pipe2_totals_test.php`:**
  - `::header_and_won`: 3 abertos no pipeline (1000, 2000, 3000) e 3 em produção (Kickoff 4000, Em execução 5000,
    Entrega 6000) + 1 concluído → `open` 6 · 21 000 com produção, `won_to_bill` 3 · 15 000 com
    `by = ['kickoff'=>1,'exec'=>1,'delivery'=>1]`.
  - `::without_negocios_excludes_production`: com `$withProduction = false` → `open` 3 · 6000 (e `won_to_bill` igual:
    quem chama não o mostra).
  - `::avenca_value`: um negócio só de avença 500 × 12 conta 6000.
  - `::concluidos`: `PIPE2_GOAL_METRICS` não está em `MX_GOAL_METRICS` (o formulário de Metas não muda).
- [ ] **Step 3: Correr e ver falhar.** `php tests/pipe2_metrics_test.php; php tests/pipe2_totals_test.php`.
- [ ] **Step 4: Implementar** `pipe2_metrics.php` e o gancho de `budget_accept`.
- [ ] **Step 5: Correr e ver passar** + `php tests/pipe2_v1_unchanged_test.php` + includes + `php -l`.
- [ ] **Step 6: Entregar.** Sem commit.

### Task 9: Tarefas, deduplicação e regras (`pipe2_tasks.php`)

**Files:**
- Create: `admin/inc/pipe2_tasks.php`, `tests/pipe2_dedup_test.php`, `tests/pipe2_repeat_test.php`,
  `tests/pipe2_reassign_test.php`, `tests/pipe2_noise_test.php`
- Modify: `admin/inc/pipeline_flow.php` (só `flow_task_from_rule`: primeira linha
  `require_once __DIR__ . '/pipe2_fronts.php'; if (!empty($deal['_v2']) || pipe2_deal_is_v2($pdo, $deal)) { require_once __DIR__ . '/pipe2_tasks.php'; return pipe2_task_from_rule($pdo, $r, $deal + ['_v2' => true], $stage, $from, $by); }`)
- Modify: `admin/inc/tasks.php` (`require_once __DIR__ . '/pipe2.php';` no topo; em `task_repeat_next`, depois de
  `if (!$step) return null;`: `require_once __DIR__ . '/pipe2_tasks.php'; $extra = pipe2_repeat_extra($pdo, $t); if ($extra === null) return null;`
  e, depois do INSERT, se `$extra`, um UPDATE dessas colunas no id novo)
- Modify: `admin/deal_flow.php` (`require_once __DIR__ . '/inc/pipe2.php';`; no caso `'people'`, o UPDATE da
  reatribuição ganha `. pipe2_reassign_scope_sql($pdo, $deal)` no fim do WHERE, com `require_once __DIR__ . '/inc/pipe2_tasks.php';`)
- Modify: `admin/inc/team.php` (`require_once __DIR__ . '/pipe2.php';`; antes do `return $before` de `team_reassign`:
  `try { require_once __DIR__ . '/pipe2_tasks.php'; pipe2_team_reassign($pdo, $f, $t); } catch (Throwable $e) { error_log('[topper] pipe2 team_reassign: ' . $e->getMessage()); }`)

**Interfaces:**
- Consumes: tarefas 3, 4, 5 (`pipe2_front_delivery`, `pipe2_notice_add`, `pipe2_notice_key`); `flow_due`,
  `flow_title`, `flow_rule_parts`, `flow_rules`, `flow_created`, `deal_gate`, `member_for_user`,
  `task_next_position`, `task_checklist_parse`, `deal_log`.
- Produces:
  ```php
  function pipe2_title_norm(string $title): string;                         // D12
  function pipe2_dedup_key(string $title, int $dealId, string $scope, string $cycle): string;  // sha1 de D12
  function pipe2_vdeal(array $deal, ?array $line, ?string $cycle = null): array;  // '_v2' true; com frente: funnel_id, stage_id, exec_owner_id (frente ?: negócio), delivery_date (pipe2_front_delivery), '_line', '_main', '_service', '_cycle' (o da frente); sem frente: '_line' null, '_cycle' $cycle ?? ''
  function pipe2_stage_match(PDO $pdo, array $stage, int $funnelId): ?array;   // etapa de negócio do outro funil: pelo role (proposal|feedback|exec|delivery), senão pelo kind (xray|kickoff|done), senão (commercial sem role) pelo nome; sem cache
  function pipe2_rules_for(PDO $pdo, array $deal, array $stage, string $trig, ?array $line = null): array;  // [[regra, etapa da regra], …] (D10, D11; 'f_enter' apanha também 'enter' nas sub-etapas); a «etapa da regra» só serve para ler a regra, nunca se grava
  function pipe2_assignee(PDO $pdo, array $r, array $vdeal): ?int;          // D11; svc_head = pipe2_service_head do funil da frente (ou do negócio); sempre por pipe2_user_ok
  function pipe2_task_from_rule(PDO $pdo, array $r, array $vdeal, ?array $stage, string $from, ?int $by): ?int;  // D12; $stage = onde a tarefa fica: nas de negócio a etapa do negócio (deals.stage_id, D10 — nunca a etapa do outro funil de onde a regra veio), nas de frente a sub-etapa da frente; grava stage_id = $stage['id']; ciclo = $vdeal['_cycle'] (numa regra v1 'monthly': o mês de $from); notify → aviso (pipe2_notice_add, kind 'rule', nkey = a chave da tarefa) e null; invoice/payment numa frente → null; grava dedup_key, cycle_ym, line_id; flow_created([$id])
  function pipe2_run_rules(PDO $pdo, array $deal, array $stage, string $trig, ?array $line, ?int $by, ?string $from = null): array;  // ['tasks' => [ids], 'notices' => [ids]]; deal_log 'auto' («N tarefas automáticas criadas (<etapa>)», system se $by null)
  function pipe2_step_gate(PDO $pdo, array $line, array $stage): array;     // ['total','done','missing' => [títulos],'units_plan' => ?int,'units_done' => int,'complete' => bool,'auto_ok' => bool] (D7)
  function pipe2_deal_gate(PDO $pdo, array $deal, array $stage): array;     // itens do deal_gate pelo papel (sem os de tarefas) + obrigatórias do negócio (line_id NULL, stage_id = etapa, cycle_ym '') + as de gate_stage_id = etapa; formato de deal_gate
  function pipe2_repeat_extra(PDO $pdo, array $t): ?array;                  // D18: v1 (ou sem pipe2_schema_ready) → []; frente saiu da sub-etapa ou mudou de ciclo → null; senão ['line_id','stage_id','auto_rule_id','doc_kind','cycle_ym'] a copiar e 'is_gate' => 0 (nunca copia is_gate)
  function pipe2_task_delete_human(PDO $pdo, array $t, ?int $by): void;     // D19: com pipe2_schema_ready && db_has_table('task_deletions'), INSERT task_deletions (task_id, deal_id, line_id, stage_id, auto_rule_id, deleted_by, title, task_created_at) em try/catch com error_log; depois, sempre e fora do try, DELETE de tasks e task_log (como hoje)
  function pipe2_reassign_scope_sql(PDO $pdo, array $deal): string;         // ' AND line_id IS NULL' num v2; '' num v1
  function pipe2_team_reassign(PDO $pdo, int $from, int $to): array;        // D20; sem pipe2_schema_ready → zeros sem consultas; deal_line_execs só com db_has_table; ['deals' => n, 'lines' => n, 'execs' => n]
  ```
- [ ] **Step 1: Escrever `tests/pipe2_dedup_test.php`** (TDD obrigatório; negócio `p2v_deal(['vfx','3d'], ['at' => 'exec'])`):
  - `::deal_deal`: as Entregas dos funis vfx e 3d têm a regra «Aprovação final do cliente por escrito» (3d com
    `is_gate 0`, vfx com `is_gate 1`) → `pipe2_run_rules(Entrega do negócio, 'enter')` cria 1 tarefa, `is_gate 1`,
    `stage_id` = Entrega do negócio, `line_id` NULL; correr outra vez → 0.
  - `::deal_front` (D12 ponto 3, §34.11 T2: abertas **e** feitas do mesmo ciclo; um negócio novo em cada caso): uma
    tarefa **aberta** da frente 3d «Aprovação final do cliente por escrito» (`cycle_ym ''`) → a regra obrigatória do
    negócio na Entrega não cria nada e essa tarefa fica com `gate_stage_id` = Entrega do negócio; com a da frente
    **feita** → também não cria e a feita fica com `gate_stage_id` = Entrega; com o título só igual depois de
    normalizar («  aprovação FINAL do cliente  por escrito ») → o mesmo; com a da frente noutro ciclo (`cycle_ym
    '2026-11'`) → cria a do negócio (`line_id` NULL) e a da frente fica sem `gate_stage_id`; com as duas frentes a
    terem-na (vfx feita, 3d aberta) → não cria e **as duas** ficam com `gate_stage_id`; uma regra **não** obrigatória
    com a da frente feita → não cria e não muda `gate_stage_id`.
  - `::delivery_reentry`: com a da frente feita e o negócio a entrar na Entrega, a voltar a Em execução e a entrar
    outra vez (`pipe2_run_rules(Entrega, 'enter')` duas vezes, com a frente a sair de Pronto e a voltar entre elas) →
    nenhuma tarefa de negócio com esse título (`COUNT` = 0) e um só `gate_stage_id` posto.
  - `::skipped_counts_for_gate`: `pipe2_deal_gate(Entrega)` lista a tarefa aberta da frente com `ok false`; feita →
    `ok true`; a feita do caso «feita» de `::deal_front` aparece logo com `ok true`; no caso das duas frentes, dois
    itens, e o gate só fica cumprido quando a aberta (3d) é feita.
  - `::other_funnel_rule_on_deal_stage` (D10): uma regra obrigatória («P2T · só no 3D», `is_gate 1`) **só** na Entrega
    do funil 3d (não principal; a Entrega do funil vfx não tem nenhuma com esse título) → `pipe2_run_rules(Entrega do
    negócio, 'enter')` cria 1 tarefa com `stage_id` = Entrega do **negócio** (a do funil vfx, `deals.stage_id`), nunca a
    Entrega do funil 3d, `line_id` NULL, `is_gate 1`; `pipe2_deal_gate(Entrega do negócio)` lista-a com `ok false`.
  - `::front_front`: na sub-etapa S1 da frente vfx, uma regra `f_enter` e uma `dep_met` com o mesmo título → 1 tarefa;
    a mesma regra na frente 3d → outra (âmbito diferente).
  - `::cycle`: avença com `cycle_ym '2026-11'`: a regra cria uma; outra vez → 0 (feita ou aberta); com
    `cycle_ym '2026-12'` → nova.
  - `::rule_dedup_line_cycle`: a mesma regra para duas frentes → 2 tarefas (a v1 daria 1).
  - `::invoice_only_deal`: regra `doc_kind 'invoice'` numa sub-etapa → nada; numa Entrega do funil não principal →
    nada; na do principal → 1.
  - `::notify_action`: `action 'notify'` → nenhuma tarefa; 1 aviso `kind 'rule'` para o responsável da frente; outra vez
    → nenhum.
  - `::assignee_never_invalid` (Review Focus 5): `svc_head` com o chefe desativado e sem pai com chefe → `member_id`
    NULL; `assign_to 'user'` com o superadmin → NULL; `'execution'` numa frente sem responsável e negócio sem execução →
    NULL.
  - `::svc_head`: Adverts → 7.
  - `::v1_noop`: num negócio v1, `flow_task_from_rule` cria a mesma tarefa de antes (deduplicação `deal_id + auto_rule_id`,
    `dedup_key ''`, `cycle_ym ''`, `line_id` NULL); a 2.ª chamada → null.
  - `::strict`: as consultas em modo estrito.
- [ ] **Step 2: Escrever `tests/pipe2_repeat_test.php`** (esta tarefa cria o ficheiro; a 10 acrescenta um caso):
  `::copies_and_closes` — tarefa de frente semanal obrigatória na sub-etapa atual, **com outra obrigatória por fazer na
  mesma sub-etapa** (para o gate não ficar completo e a frente não avançar quando a tarefa 10 ligar o motor):
  `task_done` → a seguinte tem `line_id`, `stage_id`, `auto_rule_id`, `doc_kind`, `cycle_ym` iguais e **`is_gate 0`**
  (D18: a série conta uma vez); com a frente já noutra sub-etapa, `task_done` → `null` e nenhuma tarefa nova.
  `::v1_noop` — tarefa v1 semanal com `stage_id` e `auto_rule_id`: a seguinte nasce como antes (sem `line_id`,
  `stage_id`, `auto_rule_id`, `is_gate`).
- [ ] **Step 3: Escrever `tests/pipe2_reassign_test.php`:** `::by_line` (bancada, HTTP, `pipe2_lock`, uid 1): negócio
  P2T num `p2v_bench_funnel`, uma tarefa do negócio (regra `assign_to 'commercial'`) e uma de frente (regra
  `'commercial'`, `line_id`); `POST deal_flow.php do=people owner_id=<outro>` → só a do negócio muda de `member_id`;
  `::v1_unchanged` — o mesmo num funil `pipe2_funnel` (v1) muda as duas, como hoje. `::team_reassign_exec` (base
  privada): `team_reassign(de, para)` passa `deals.exec_owner_id` de um negócio v2, `deal_lines.exec_owner_id` e
  `deal_line_execs.user_id` das frentes v2 vivas; um negócio v1 e uma frente v1 `active` com o mesmo `exec_owner_id`
  ficam como estavam. `::v1_at_42` — numa base na 42 (`pipe2_tmpdb_create(pipe2_base_dump())`, sem migrar,
  `pipe2_use`): `team_reassign(de, para)` de um utilizador com negócios e tarefas abertas faz exatamente o de hoje
  (`deals.owner_id`, `tasks.member_id`, `users.reports_to` mudados; `p2v_sig` das outras tabelas igual), sem exceção e
  sem avisos de PHP (um `set_error_handler` que regista qualquer aviso: 0 no fim); `pipe2_team_reassign` devolve zeros.
- [ ] **Step 4: Escrever `tests/pipe2_noise_test.php`:** `::deletion_logged` — `pipe2_task_delete_human` grava em
  `task_deletions` todos os campos (incl. `task_created_at`) e depois apaga `tasks` e `task_log`; `::v1_also_logged` —
  uma tarefa v1 também fica registada (D19); `::insert_fails_delete_runs` — com um `TRIGGER` que falha em
  `INSERT ON task_deletions`, a tarefa é apagada na mesma (`tasks` e `task_log` sem a linha), sem exceção, e o
  `error_log` tem «[topper] pipe2»; `::v1_at_42` — numa base na 42 (sem `task_deletions`), `pipe2_task_delete_human` de
  uma tarefa v1 apaga-a e ao seu `task_log`, sem exceção nem aviso de PHP (o mesmo `set_error_handler`).
- [ ] **Step 5: Correr e ver falhar.** `php tests/pipe2_dedup_test.php; php tests/pipe2_repeat_test.php; php tests/pipe2_reassign_test.php; php tests/pipe2_noise_test.php`.
- [ ] **Step 6: Implementar** `pipe2_tasks.php` e os 4 ganchos.
- [ ] **Step 7: Correr e ver passar** + `php tests/pipe2_v1_unchanged_test.php; php tests/pipe2_screens_test.php; php tests/pipe2_includes_test.php`
  + `php -l` dos 5 ficheiros → `OK n`.
- [ ] **Step 8: Entregar.** Sem commit.

### Task 10: Motor das frentes (`pipe2_engine.php`)

**Files:**
- Create: `admin/inc/pipe2_engine.php`, `tests/pipe2_autoadvance_test.php`, `tests/pipe2_undo_test.php`,
  `tests/pipe2_race.php`
- Modify: `admin/inc/tasks.php` (em `task_done`, o gancho corre **antes** da repetição (D18): a última linha
  `return task_repeat_next($pdo, $t);` passa a
  `try { require_once __DIR__ . '/pipe2_engine.php'; pipe2_task_changed($pdo, $t, 'done'); } catch (Throwable $e) { error_log('[topper] pipe2 task_done: ' . $e->getMessage()); }`
  seguido de `return task_repeat_next($pdo, $t);` — num negócio v1 o gancho não escreve nada, por isso a v1 fica igual;
  em `task_move`: `$reopened = $row['status'] === 'done';` no ramo da reabertura e, depois do `commit`, o mesmo bloco
  com `pipe2_task_changed($pdo, task_by_id($pdo, $id) ?? ['id' => $id], 'reopen')`)
- Modify: `admin/calendar.php` (`require_once __DIR__ . '/inc/pipe2.php';`; em `delete_task`, as duas linhas DELETE
  passam a `require_once __DIR__ . '/inc/pipe2_tasks.php'; pipe2_task_delete_human($pdo, $old, (int)$me['id']);` e,
  depois de `tinv_sync`, o bloco try com `pipe2_task_changed($pdo, $old, 'delete')`)
- Modify: `admin/inc/deals.php` (`deal_log`: `$uid = !empty($d['system']) ? null : ($d['user_id'] ?? ($u['id'] ?? null));`
  usado no INSERT e passado a `metrics_on_log` como `'user_id' => $uid`)
- Modify: `tests/pipe2_deps_test.php`, `tests/pipe2_fronts_test.php`, `tests/pipe2_repeat_test.php` (casos novos)

**Interfaces:**
- Consumes: tarefas 3, 4, 5, 9; `deal_log`, `mx_event`, `metrics_ready`, `today_lisbon`, `GateBlocked`, `task_log`.
- Produces:
  ```php
  const PIPE2_AUTO_NOTE = 'Avanço automático';
  function pipe2_request_reset(): void;                 // zera pipe2_once, pipe2_moves e pipe2_note
  function pipe2_once(string $key): bool;               // true só da 1.ª vez por pedido
  function pipe2_moves(?array $add = null): array;      // registo do pedido (D13); entrada 'front': ['kind'=>'front','deal','line','from'=>[stage_id,status,stage_entered_at,done_at,cycle_ym],'to'=>[stage_id,status],'steps'=>[[id, [coluna => antes]], …],'steps_new'=>[ids],'tasks'=>[ids],'closed'=>[[id, status antes, done_at antes, task_log id], …],'history'=>[ids],'events'=>[ids],'deps'=>[[id, met_at antes], …],'notices'=>[ids],'undoable'=>true]
  function pipe2_note(?string $add = null): array;      // mensagens para quem chamou
  function pipe2_lock_deal(PDO $pdo, int $dealId): void;    // GET_LOCK('topper_p2d_' . md5(DATABASE()) . '_' . id, 10), reentrante (contador por negócio no processo: só o 1.º faz GET_LOCK); falha → RuntimeException('Outro pedido está a mexer neste negócio; tente outra vez.')
  function pipe2_unlock_deal(PDO $pdo, int $dealId): void;  // desce o contador; só o nível de fora faz RELEASE_LOCK. Regra: quem abre uma transação dentro do lock faz commit (ou rollback) antes de o soltar, para o pedido seguinte ler o que ficou gravado
  function pipe2_auto_on(PDO $pdo, array $line, array $stage): bool;   // D5
  function pipe2_step_row(PDO $pdo, array $line, int $stageId, bool $create = true): ?array;  // (frente, sub-etapa, ciclo da frente); com $create toma pipe2_lock_deal (reentrante: nada muda se quem chamou já o tem) e faz o SELECT e o INSERT dentro dele — deal_line_steps não tem UNIQUE
  function pipe2_next_stage(PDO $pdo, array $line): ?array;    // a sub-etapa viva seguinte pela posição; sem etapa → a 1.ª; de Pronto → null
  function pipe2_prev_stage(PDO $pdo, array $line): ?array;
  function pipe2_front_enter(PDO $pdo, array $deal, array $line, array $to, array $o = []): string;  // 'entered' | 'waiting' | 'lost'
  function pipe2_front_reevaluate(PDO $pdo, array $deal, array $line): string;  // 'advanced'|'waiting'|'released'|'held'|'incomplete'|'no_mandatory'|'off'|'once'|'idle'
  function pipe2_front_release(PDO $pdo, array $deal, array $fromLine, int $fromStageId): array;  // ids das frentes que entraram em Y
  function pipe2_task_changed(PDO $pdo, array $task, string $why): void;      // why: done|reopen|delete|gate; nunca lança
  function pipe2_after_front_change(PDO $pdo, int $dealId, string $why): void; // nesta tarefa não faz nada; a tarefa 11 liga-a ao negócio
  function pipe2_front_start(PDO $pdo, array $deal, array $line, ?int $by = null): string;  // D6
  function pipe2_front_move(PDO $pdo, array $deal, array $line, int $toStageId, string $force = '', ?int $by = null): string;  // movimento humano
  function pipe2_front_undo(PDO $pdo, array $deal, array $line, ?int $by = null): string;   // «Desfazer» (D14)
  function pipe2_front_set_auto(PDO $pdo, array $deal, array $line, ?bool $on, ?int $by = null): void;
  function pipe2_step_units(PDO $pdo, array $deal, array $line, int $done, ?int $plan = null, ?int $by = null): void;
  function pipe2_task_set_gate(PDO $pdo, int $taskId, bool $on, ?int $by = null): void;
  ```
  `pipe2_front_enter` — `$o`: `by` (?int; null = Sistema), `why` (`auto`|`manual`|`start`|`dep`|`undo`|`cycle`|`force`|`cancel`),
  `note`, `status` (sobrepõe), `cycle` (ciclo novo), `rules` (true), `record` (true). Corre dentro de `pipe2_lock_deal`
  (reentrante; toma-o se quem chamou não o tem) e relê a frente depois do lock. Pela ordem: (1) com ligações por
  cumprir para `$to` e `why` ∉ {`force`,`undo`,`cycle`,`cancel`}: UPDATE condicional para `depwait` (a etapa fica),
  `done_at` na linha de passos atual, fecho das séries da sub-etapa concluída (D18), `deal_log 'p2_wait'` («<Serviço>:
  à espera de <A> («<X>»)», system), `mx_event 'p2_wait'` → `'waiting'`; (2) senão, numa transação própria se não houver
  uma aberta: UPDATE condicional
  (estado novo: o de `$o['status']`, senão `ready`/`ongoing` em `role 'ready'` conforme avença não terminada, senão
  `ongoing` numa avença, senão `running`; `stage_entered_at`, `started_at = COALESCE(started_at, agora)`, `done_at` só
  num Pronto final), passos (para a frente: `done_at` na atual; na nova `started_at = COALESCE(…)` e `done_at NULL`),
  para a frente (e no Pronto final, e com ciclo novo) o fecho das séries de D18 — UPDATE direto das tarefas e INSERT
  direto em `task_log` com `user_id` NULL, ids em `'closed'`; para trás (`why 'undo'` ou destino antes da atual) não fecha —,
  `deal_log 'p2_front'` (`from_stage`, `to_stage`, `to_funnel`, nota «<Serviço>: <de> → <para>» + « · Avanço
  automático» quando `by` é null + a `note`, `'system' => by === null`, `'user_id' => by`), `mx_event 'p2_front'`
  (`line`, `funnel`, `from`, `to`, `user` = by, `reason` = why), `deals.last_touch = hoje`; `rowCount() = 0` → rollback e
  `'lost'`; (3) depois do commit, para a frente: `pipe2_front_release($deal, $line, <etapa de onde saiu>)`; (4) regras
  (try/catch): `f_enter` em `$to`, `f_ready` se `role 'ready'`, `dep_met` se `why = 'dep'`; (5) regista em
  `pipe2_moves` (ids por `id > máximo antes` em `deal_history`/`stage_events` do negócio, `flow_created` antes/depois).
  `pipe2_front_reevaluate`: `depwait` → se já não há ligações por cumprir para a seguinte, entra (`why 'dep'`) →
  `'released'`; não `running`/`ongoing` ou em `role 'ready'` → `'idle'`; depois, por esta ordem, `no_mandatory`,
  `incomplete`, `held`, `off`, `once`, e por fim entra na seguinte (`why 'auto'`). `pipe2_task_changed`: sai se não houver
  `deal_id` ou o negócio não for v2; `pipe2_lock_deal`; **relê o negócio e a frente depois do lock** (`deal_get`,
  `pipe2_front_get`: o que veio de quem chamou pode estar velho); se a tarefa tem `line_id` e `stage_id` e é (ou deixou de ser)
  obrigatória e `why` ∈ {done, reopen, delete, gate}: tira a suspensão da linha (frente, sub-etapa, ciclo da tarefa ou da
  frente); se a frente está nessa sub-etapa: `pipe2_front_reevaluate`; depois `pipe2_after_front_change($pdo, $dealId, $why)`;
  tudo em try/catch com `error_log`, desbloqueio no finally; reabrir nunca recua a frente. `pipe2_front_move`: só
  sub-etapas vivas do funil da frente; para trás passa sempre e suspende o destino (`undo`); para a frente só a
  seguinte («avançar uma sub-etapa de cada vez»), com o gate completo e sem ligações por cumprir, senão `GateBlocked`
  com a lista; `$force` só com `is_admin()` (senão «Só os admins podem forçar a passagem.»), nota «Passagem forçada:
  <motivo> · faltava: …» e as ligações por cumprir ficam `met_at`; mensagem «<Serviço>: passou a «<etapa>».». No fim de
  cada ação humana e de cada avanço: `pipe2_after_front_change`. Todas as funções públicas deste ficheiro que escrevem
  num negócio (`pipe2_front_start`, `pipe2_front_move`, `pipe2_front_undo`, `pipe2_front_set_auto`, `pipe2_step_units`,
  `pipe2_task_set_gate`, `pipe2_front_release`, `pipe2_front_enter`, `pipe2_step_row` com `$create`) tomam
  `pipe2_lock_deal` (reentrante) e relêem o negócio e a frente depois dele; o `$deal`/`$line` de quem chamou só dá os
  ids e o estado esperado. `pipe2_front_start`: frente `queued` (ou `depwait` sem
  etapa); negócio no Kickoff ou em Em execução; gate do Kickoff (`pipe2_deal_gate`) cumprido senão `GateBlocked`;
  `pipe2_plan_front($line, hoje)`; avença: `billing_end` e `cycle_ym` pelo `pipe2_billing_period` (se ainda vazios);
  entra na 1.ª sub-etapa (`why 'start'`). `pipe2_front_undo`: «A frente já está na primeira sub-etapa.» na 1.ª; volta à
  anterior (`why 'undo'`, sem regras novas), suspende-a, `deal_log 'p2_undo'` («<Serviço>: voltou a «<etapa>» · avanço
  automático suspenso nesta sub-etapa»). `pipe2_front_set_auto`: `NULL`/`0`/`1` na frente, `deal_log 'p2_auto'`,
  reavalia quando não desliga. `pipe2_step_units`: grava, `deal_log 'p2_units'`, reavalia (sem tirar a suspensão).
  `pipe2_task_set_gate`: UPDATE `is_gate`, `task_log` («Obrigatória: sim/não»), `pipe2_task_changed($t, 'gate')`.

  `tests/pipe2_race.php --db=X --uid=N --go=F [--do=task_done --task=ID | --do=tick --today=AAAA-MM-DD | --do=deal_move --deal=ID --stage=ID [--force=motivo]]`:
  liga à base privada, sessão do uid (0 = sem sessão), **lê o que a ação precisa antes do sinal** (a tarefa; no
  `deal_move`, o negócio por `deal_get`, como dois pedidos que carregaram a página antes de o outro mover), chama
  `p2v_go_wait($argv)` (tarefa 1: escreve `F.ready.<pid>` e espera ≤ 10 s por `F`) e faz a ação: `task_done` (por
  defeito), `pipe2_tick($pdo, $today)` (`require_once` de `admin/inc/pipe2_tick.php`; se o ficheiro ainda não existir,
  `{"error":"pipe2_tick.php em falta"}` e código 1 — a tarefa 13 usa-o sem mudar o script) ou
  `deal_move($pdo, $dealLido, $stage, $force)` (função da v1; a tarefa 11 usa-o no `::kickoff_race`); imprime
  `{"ok":true}` (no `deal_move`, `{"ok":true,"msg":…}`). Só esta tarefa escreve o ficheiro; as tarefas 11 e 13 usam-no.

- [ ] **Step 1: Escrever `tests/pipe2_autoadvance_test.php`** (TDD obrigatório; frente vfx `running` na S1 com 2
  obrigatórias e passos com `p2v_steps`; S2 com uma regra `f_enter` obrigatória; `pipe2_request_reset()` entre
  «pedidos»):
  - `::advance_on_last_mandatory`: `task_done` da 1.ª → frente fica em S1; da 2.ª → S2, a tarefa de S2 criada (com
    `line_id`, `stage_id` S2, `dedup_key`), `deal_history 'p2_front'` com `user_id` NULL e nota que contém «Avanço
    automático», `stage_events` `step 'p2_front'`, `line_id` = frente, `to_stage_id` = S2; `deals.last_touch` = hoje;
    `deal_line_steps` S1 `done_at`, S2 `started_at`.
  - `::no_mandatory_no_advance`: S1 sem obrigatórias e uma tarefa normal feita → não avança (`'no_mandatory'`); com
    `units_plan 10` e `pipe2_step_units(…, 10)` → avança.
  - `::one_step_per_request`: S1 e S2 com todas as obrigatórias já feitas → um `pipe2_task_changed` leva só a S2; outro
    no mesmo pedido → fica (`'once'`); depois de `pipe2_request_reset()` → S3.
  - `::concurrent_last_mandatory` (Review Focus do plano geral): duas obrigatórias por fechar em S1, `p2v_race` com dois
    `pipe2_race.php` (uma tarefa cada) → as duas `done`, exatamente 1 `p2_front` S1→S2, a frente em S2 (não S3), as
    tarefas `f_enter` de S2 criadas uma vez, e exatamente 1 linha de `deal_line_steps` por (frente, sub-etapa, ciclo)
    (`SELECT line_id, stage_id, cycle_ym, COUNT(*) … GROUP BY … HAVING COUNT(*) > 1` vazio). 5 rondas, cada uma com um
    negócio novo; todas têm de passar.
  - `::conditional_update`: dois `pipe2_front_enter` com a mesma frente lida antes → o 2.º devolve `'lost'` e não
    regista nada.
  - `::switches`: `funnels.auto_advance 0` → `'off'`; `funnel_stages.auto_advance 0` → `'off'`; frente 0 → `'off'`;
    frente 1 com o serviço 0 → `'off'`; frente NULL com tudo 1 → avança.
  - `::reevaluate_hooks`: reabrir (`task_reopen`) uma obrigatória de S2 com a frente em S2 → fica em S2 (nunca recua);
    apagar pela Agenda (bancada: `p2v_bench_funnel`, HTTP `POST calendar.php do=delete_task` como uid 1) a única
    obrigatória por fazer → `task_deletions` com a linha e a frente avança; `pipe2_task_set_gate($id, false)` da única
    por fazer → avança.
  - `::system_actor`: com `p2v_as(99)`, o avanço automático grava `user_id` NULL (histórico e `stage_events`);
    `pipe2_front_move` humano grava 99.
  - `::no_session` (Review Focus 2): `p2v_race` com uma só corrida de `pipe2_race.php --uid=0` → avança, `user_id` NULL,
    `err` sem «Warning»/«Notice».
  - `::never_cancels_completion`: um `TRIGGER` que falha em `INSERT ON deal_history` com `event = 'p2_front'` →
    `task_done` devolve sem exceção, a tarefa fica `done`, a frente não avançou (rollback) e o `error_log` tem
    «[topper] pipe2».
  - `::reentry_no_duplicates` (Review Focus 3): S1 → S2 (tarefa T2 criada), `pipe2_front_undo` → S1 suspensa, reabrir e
    fechar uma obrigatória de S1 → S2 outra vez: continua só uma T2 (`COUNT` = 1) e, no mesmo pedido, não passa a S3.
  - `::retired_stage_next`: S2 com `retired_at` → de S1 entra em S3.
  - `::human_move_rules`: para a frente 2 sub-etapas → `GateBlocked` «avançar uma sub-etapa de cada vez»; com o gate
    incompleto → `GateBlocked` com os títulos; forçar sem admin → «Só os admins podem forçar a passagem.»; admin com
    motivo → passa e a nota tem «Passagem forçada»; para trás → passa e suspende o destino.
  - `::start`: `queued` com o gate do Kickoff por cumprir → `GateBlocked`; cumprido → entra na 1.ª sub-etapa, com o
    plano feito; avença → `billing_end` e `cycle_ym`.
  - `::v1_noop`: num negócio v1 com frentes `active`, `task_done` de uma tarefa com `line_id` → `p2v_sig` de
    `deal_lines`, `deal_line_steps`, `deal_history`, `stage_events` igual menos a linha `task_done` do histórico (que
    já existia na v1).
- [ ] **Step 2: Escrever `tests/pipe2_undo_test.php`** (esta tarefa cria o ficheiro):
  `::undo_suspends_until_mandatory_touch` (TDD) — S2 → «Desfazer» → S1 (todas as obrigatórias de S1 feitas):
  `pipe2_front_reevaluate` → `'held'`; reabrir uma de S1 → suspensão levantada, `'incomplete'`; fechá-la → S2.
  `::undo_first_step_refused` — na 1.ª sub-etapa, «A frente já está na primeira sub-etapa.».
- [ ] **Step 3: Acrescentar a `tests/pipe2_deps_test.php`:** `::waits_before_y` — Ed com a ligação «Composit conclui
  Composição → Edição Picture lock» por cumprir: fechar a última obrigatória de «Montagem offline» → Ed `depwait` em
  «Montagem offline», sem tarefas de Picture lock, `p2_wait` em `stage_events`; `::tasks_born_on_release` — Composit sai
  de Composição → a ligação `met_at`, Ed entra em Picture lock, as tarefas `f_enter`/`dep_met` de Picture lock criadas,
  1 aviso `dep_met` para o responsável de Ed (e não outro numa 2.ª libertação); `::no_replan_while_waiting` (motor) —
  com Ed `depwait` e a sub-etapa anterior atrasada, `pipe2_replan` 0 e `pipe2_front_reevaluate` `'idle'`/`'waiting'`.
  Acrescentar a `tests/pipe2_fronts_test.php`: `::main_not_special_engine` — a principal avança pelo gate da sua
  sub-etapa como as outras e as tarefas dela têm `line_id`.
  Acrescentar a `tests/pipe2_repeat_test.php` (D18): `::mandatory_series_closes_on_advance` — S1 com uma só obrigatória,
  semanal (`repeat_rule 'weekly'`, `is_gate 1`), e S2 com uma regra `f_enter`: `task_done` dela → a frente passa a S2
  (avanço automático) e **não nasce nenhuma instância nova** em S1 (`COUNT` das tarefas da série = 1, `task_done`
  devolve `null`); `::series_counts_once` — S1 com a semanal obrigatória e outra obrigatória por fazer: fechar a semanal
  → nasce a seguinte com `is_gate 0`; fechar a outra → a frente avança (a instância aberta da série não bloqueia o gate)
  e essa instância **fecha pelo sistema** no mesmo movimento (§34.11 T4, D18): `status 'done'`, `done_at` preenchido,
  uma linha `task_log 'done'` com `user_id` NULL e a nota «Fechada pelo sistema: a frente saiu da sub-etapa», nenhum
  `deal_history 'task_done'` dela e nenhuma instância nova (`COUNT` das abertas da série = 0); o id está em
  `pipe2_moves()[…]['closed']`. `::closes_on_leave` — uma série aberta em S1 fecha também quando: a frente é movida à mão
  para S2 (`pipe2_front_move`, sessão 99) e quando passa por força de admin; a frente fica `depwait` no fim de S1 (uma
  ligação por cumprir para S2); com ciclo novo (avença em Pronto, `pipe2_front_enter` da 1.ª sub-etapa com
  `why 'cycle'` e `cycle '2026-12'`, como o fará o relógio da tarefa 12) fecham as abertas do ciclo antigo em todas as
  sub-etapas da frente e as do ciclo novo não; uma série de **outra** frente na mesma sub-etapa e uma tarefa sem
  `repeat_rule` ficam abertas. `::back_move_keeps_open` — S2 com uma obrigatória por fazer e uma série semanal
  (`is_gate 0`) com duas instâncias abertas, A e B (`p2v_task`); `pipe2_front_undo` para S1 → A e B continuam abertas;
  `task_done(A)` com a frente em S1 → `null` (nenhuma nova); a frente volta a S2 (`pipe2_front_move` humano, gate de S1
  completo) e `task_done(B)` → nasce a seguinte, C (a série continua); fechar a obrigatória de S2 → a frente avança e C
  fecha pelo sistema.
- [ ] **Step 4: Correr e ver falhar.** `php tests/pipe2_autoadvance_test.php; php tests/pipe2_undo_test.php; php tests/pipe2_deps_test.php; php tests/pipe2_fronts_test.php; php tests/pipe2_repeat_test.php`.
- [ ] **Step 5: Implementar** `pipe2_engine.php`, `pipe2_race.php` e os ganchos.
- [ ] **Step 6: Correr e ver passar** os cinco + `php tests/pipe2_dedup_test.php; php tests/pipe2_noise_test.php; php tests/pipe2_v1_unchanged_test.php; php tests/pipe2_includes_test.php`
  + `php -l` → `OK n`.
- [ ] **Step 7: Entregar.** Sem commit.

### Task 11: Motor do negócio (`pipe2_deal.php`, `deal_move`, `flow_on_enter`, `flow_gate_check`)

**Files:**
- Create: `admin/inc/pipe2_deal.php`, `tests/pipe2_engine_test.php`, `tests/pipe2_close_test.php`
- Modify: `admin/inc/pipeline.php`: `deal_move(PDO $pdo, array $deal, int $stageId, string $force = '', array $opts = []): string`
  — `$v2 = pipe2_deal_is_v2(...)` (com `require_once pipe2_fronts.php`); o bloqueio «a produção anda por frente» passa a
  `!$v2 && …`; num v2 sem `$opts['system']`, entrar ou sair de uma etapa `role` `exec`/`delivery` (exceto Entrega →
  Concluído) recusa-se com «Em execução e Entrega mudam sozinhas, pelas frentes; só um admin pode forçar, com motivo.»,
  a não ser `is_admin()` com `$force`; o `deal_log 'stage'` leva `'note' => $opts['note'] ?? ''` e
  `'system' => !empty($opts['system'])`. `deal_change_funnel`: num v2 em `phase 'production'`, «Neste negócio em
  execução, mudar de funil ainda não está disponível.».
- Modify: `admin/inc/pipeline_flow.php`: `flow_on_enter` — `$v2 = pipe2_deal_is_v2($pdo, $deal)`; num v2,
  `$n = pipe2_on_deal_enter($pdo, $deal, $stage, current_user()['id'] ?? null)` no lugar do ciclo das regras e do bloco
  do crosselling; o resto (Relações no Concluído, `cc_on_kickoff`) igual e pela mesma ordem; `flow_gate_check` — logo a
  seguir ao `if (!$from …) return ''`, num v2 sem `_line`: `$missing = pipe2_gate_missing($pdo, $deal, $from, $to)` e o
  mesmo tratamento de `$force`/`GateBlocked`.
- Modify: `admin/inc/metrics.php` (em `metrics_on_log`:
  `$uid = !empty($d['system']) ? null : ($d['user_id'] ?? (current_user()['id'] ?? null));` e `'line' => $d['line'] ?? null` no `$o`)
- Modify: `admin/inc/pipe2_engine.php` (`pipe2_after_front_change`: `require_once __DIR__ . '/pipe2_deal.php'; pipe2_deal_reevaluate($pdo, $dealId, $why);`)

**Interfaces:**
- Consumes: tarefas 2, 3, 5, 9, 10.
- Produces:
  ```php
  function pipe2_on_deal_enter(PDO $pdo, array $deal, array $stage, ?int $by): int;  // tudo dentro de pipe2_lock_deal (reentrante), relendo o negócio depois do lock: Kickoff → pipe2_kickoff; depois pipe2_run_rules('enter'); Concluído → xsell_after_delivery com os funis das frentes vivas; devolve tarefas criadas
  function pipe2_kickoff(PDO $pdo, array $deal, ?int $by = null): array;     // idempotente também em concorrência (Review Focus 7): pipe2_lock_deal; transação própria (ou a de quem chamou, se houver) com SELECT id FROM deals WHERE id = ? FOR UPDATE (sem FOR UPDATE em SQLite, como deal_assign_code); relê o negócio (deal_get) e as frentes (pipe2_fronts) — o $deal recebido só dá o id; negócio já fora do Kickoff ou da fase production → tudo vazio; senão pipe2_kickoff_fronts, pipe2_category_store, pipe2_deps_instantiate, pipe2_store_values (nenhuma destas abre transação própria: abriria «already an active transaction»); commit da própria antes de soltar o lock (finally; rollback e relança em erro, apanhado pelo gancho); ['created','queued','deps','category']; não entra em pipe2_moves (o «Anular» do Kickoff usa o snapshot, D29)
  function pipe2_front_concluded(array $line, string $today): bool;          // D16
  function pipe2_fronts_missing(PDO $pdo, array $deal, string $today): array; // «<Serviço> (<sub-etapa>)», «<Serviço> (à espera de <A>)», «<Serviço> (por começar)», «<Serviço> (avença até dd/mm/aaaa)»
  function pipe2_close_missing(PDO $pdo, array $deal): array;               // E2: pipe2_deal_gate(Entrega) por cumprir + pipe2_fronts_missing
  function pipe2_close_auto_missing(PDO $pdo, array $deal): array;          // D16: o que falta para o Concluído poder ser do sistema — ['Aprovação final'] se nenhuma obrigatória do gate da Entrega (do negócio na Entrega ou com gate_stage_id = Entrega) tem o título normalizado a começar por «aprovação final», ['Pagamento final'] se nenhuma tem doc_kind 'payment'; [] = pode concluir sozinho (feitas ou não: isso é o pipe2_close_missing)
  function pipe2_gate_missing(PDO $pdo, array $deal, array $from, array $to): array;  // ramo v2 de flow_gate_check: para trás []; uma etapa de cada vez (etapas vivas de negócio do funil); Kickoff → Em execução: gate do Kickoff; Em execução → Entrega: frentes; Entrega → Concluído: pipe2_close_missing; as outras: pipe2_deal_gate da origem
  function pipe2_deal_system_move(PDO $pdo, array $deal, array $to, string $note, int $expectStageId): string;  // 'moved'|'deferred'|'blocked'|'stale'; dentro do lock do negócio (quem chama já o tem): relê deals.stage_id e, se já não é $expectStageId (outro pedido moveu), devolve 'stale' sem mexer; senão deal_move com ['system'=>true,'note'=>…]; regista ['kind'=>'deal', …, 'undoable' => $to['kind'] !== 'done']
  function pipe2_deal_reevaluate(PDO $pdo, int $dealId, string $why = ''): string;  // 'started'|'to_exec'|'to_delivery'|'back_exec'|'done'|'blocked'|'no_mandatory'|'deferred'|'idle' (D6, D16)
  function pipe2_conclude_delivered(PDO $pdo, array $deal): void;           // E6
  ```
  `pipe2_deal_reevaluate` (com `pipe2_lock_deal` e `pipe2_once('deal:'.id)` para os movimentos). **Concorrência**
  (Review Focus 6): `deal_move` não é um UPDATE condicional, por isso, depois de obter o lock, relê o negócio
  (`deal_get`) e as frentes (`pipe2_fronts`) e decide **só** sobre o que releu (nunca sobre o `$deal` de quem chamou);
  cada movimento passa a `pipe2_deal_system_move` o `stage_id` relido como esperado, e esta não move se ele já mudou
  (`'stale'` → `pipe2_deal_reevaluate` devolve `'idle'`). As regras `all_ready` correm só no pedido que vai mover para
  a Entrega, dentro do lock e logo antes do movimento; as regras de fecho correm pelo `flow_on_enter` desse `deal_move`
  (só quem move); a deduplicação D12 é a segunda guarda, nunca a única. O lock solta-se no `finally`. **Kickoff** — gate
  cumprido e ≥ 1 obrigatória → `pipe2_front_start` de cada `queued`; com uma frente a entrar numa sub-etapa (ou já
  `running`), «Em execução» (nota «Uma frente começou»). **Em execução** — todas concluídas → `pipe2_run_rules('all_ready')`
  e «Entrega e pagamento final» (nota «Todas as frentes prontas»); senão, com ≥ 1 concluída,
  `pipe2_note('Pronto para a Entrega — falta: ' . implode(', ', pipe2_fronts_missing(...)))`. **Entrega** — uma frente
  não concluída → volta a «Em execução» (nota «Voltou a Em execução: <serviço> saiu de Pronto»); senão, com
  `pipe2_close_missing` vazio: se `pipe2_close_auto_missing` também está vazio → «Concluído» (nota «Concluído
  automático: aprovação final e pagamento final validados») e `pipe2_conclude_delivered`; se não está (D16: a Entrega não
  tem como obrigatórias a aprovação final e o pagamento final — por exemplo um funil sem regras de fecho) → não move,
  devolve `'no_mandatory'` e, se `$why ≠ 'cron'`, `pipe2_note('Pronto para concluir: conclui-se à mão (a Entrega não tem
  como obrigatória: ' . implode(', ', …) . ').')`; com `pipe2_close_missing` por cumprir → `'blocked'` e, se
  `$why = 'done'`, `pipe2_note('Pronto para concluir — falta: …')`. Com uma transação aberta → `'deferred'` (Review
  Focus 1). **`flow_on_enter` do Kickoff** (Review Focus 7): `pipe2_on_deal_enter` toma `pipe2_lock_deal` antes de
  tudo e `pipe2_kickoff` faz o resto (transação, `FOR UPDATE`, releitura, commit antes de soltar), por isso dois
  `deal_move` para o Kickoff ao mesmo tempo criam as frentes, os passos, as ligações e as tarefas `enter` uma vez; o
  `deal_history 'stage'` pode ficar duas vezes nesse caso — é o `deal_move` da v1, que não muda nesta fase, e o teste não
  o conta.
- [ ] **Step 1: Escrever `tests/pipe2_engine_test.php`** (negócios `p2v_deal`, regras de fecho `p2v_closing_rules` nas
  Entregas dos funis de teste):
  - `::kickoff_creates_fronts`: `deal_move` de Aguardar feedback (com o gate cumprido ou forçado por admin) para o
    Kickoff de um negócio `vfx,3d,ed` → frentes `queued`, passos, ligações do modelo `postprod`
    (`3D:Lighting e render → Edição:Picture lock`, `VFX:Finais e QC → Edição:Picture lock`, D8), `deals.category 'postprod'`
    com `category_at`, valores das frentes, e as regras `enter` do Kickoff criadas uma vez.
  - `::kickoff_idempotent`: `flow_on_enter` do Kickoff duas vezes → as mesmas contagens.
  - `::kickoff_reread_after_lock` (determinístico, Review Focus 7): ler o negócio no Kickoff com as frentes `planned`
    (`$velho`), chamar `pipe2_kickoff($tp, $velho)` e outra vez com o mesmo `$velho` (que ainda diz `planned`) → a 2.ª
    devolve `created`, `queued` e `deps` vazios, sem exceção (nenhum «Duplicate entry» do UNIQUE `deal_id, funnel_id`),
    e as contagens de `deal_lines`, `deal_line_steps` e `deal_line_deps` do negócio não mudam; com o negócio já em Em
    execução (UPDATE direto) e um `$velho` do Kickoff → não faz nada.
  - `::kickoff_race` (Review Focus 7, M4): negócio `vfx,3d,ed` em Aguardar feedback com as frentes `planned` e uma regra
    `enter` obrigatória no Kickoff; `p2v_race` com dois `pipe2_race.php --do=deal_move --deal=<id> --stage=<Kickoff>
    --force=teste --uid=1` (cada um leu o negócio antes do sinal) → os dois com código 0, sem «Warning» nem «Duplicate
    entry» em `err`; exatamente 3 `deal_lines` (1 `is_main`), todas `queued`; 6 linhas de `deal_line_steps` por frente
    (ciclo `''`), sem repetidos por (frente, sub-etapa, ciclo); 2 `deal_line_deps` (as de `::kickoff_creates_fronts`);
    1 tarefa da regra do Kickoff (`COUNT` por `auto_rule_id`); 1 `deal_history 'p2_category'`. O número de
    `deal_history 'stage'` não se verifica (v1). 5 rondas, cada uma com um negócio novo; todas têm de passar.
  - `::kickoff_gate_starts_fronts`: fechar a obrigatória do Kickoff → as 3 frentes entram na 1.ª sub-etapa (nenhuma
    ligação do modelo chega a uma 1.ª sub-etapa), negócio em «Em execução», `deal_history 'stage'` com `user_id` NULL e
    nota «Uma frente começou»; com uma ligação manual para a 1.ª sub-etapa de Ed, Ed fica `depwait` com `stage_id` NULL;
    sem obrigatórias no Kickoff, fechar uma tarefa normal não começa nada (`pipe2_front_start` à mão começa).
  - `::multi_front_deal_moves`: o negócio v2 de 2 frentes passa Kickoff → Em execução → Entrega pelo `deal_move` do
    sistema; um negócio v1 com 2 `deal_lines` continua a receber «Neste negócio de crosselling a produção anda por
    frente…».
  - `::system_moves_only`: `deal_move` humano (sessão 99) para Em execução → recusa com a mensagem; admin com `$force` →
    passa e fica `gate_force`.
  - `::all_ready_rules`: a última frente fica Pronta → regra `all_ready` da Em execução criada 1 vez; negócio na Entrega;
    as 4 regras de fecho + «Validar pagamento final» criadas uma vez.
  - `::blocked_gate_message`: VFX Pronta e 3D em «Animação» → depois do `task_done` que deixou VFX Pronta,
    `pipe2_note()` contém exatamente «Pronto para a Entrega — falta: 3D (Animação)».
  - `::done_gate`: na Entrega, `deal_move` humano para Concluído com obrigatórias por fazer → `GateBlocked` com os
    títulos; uma avença por terminar mantém o negócio em Em execução (`pipe2_fronts_missing` com «(avença até …)»).
  - `::done_needs_mandatory` (D16): negócio v2 vfx+3d num funil de teste **sem** regras na Entrega (nem
    `p2v_closing_rules`), as duas frentes Prontas e o negócio na Entrega → `pipe2_deal_reevaluate($id, 'done')` devolve
    `'no_mandatory'`, o negócio fica na Entrega (nenhum `deal_history 'stage'` novo, `delivered_at` NULL) e
    `pipe2_note()` contém exatamente «Pronto para concluir: conclui-se à mão (a Entrega não tem como obrigatória:
    Aprovação final, Pagamento final).»; com só as 4 regras de `PIPE_RULES_DELIVERY` (sem «Validar pagamento final»),
    todas feitas → `'no_mandatory'` e a nota só com «Pagamento final»; com uma regra de pagamento mas sem a de aprovação →
    só «Aprovação final»; o tick (`$why 'cron'`) também não move e não deixa nota; um `deal_move` humano (sessão 99) para
    Concluído passa (gate cumprido) e marca o negócio `done`. (Os funis de `p2v_funnel` nascem sem regras, como os de
    `funnel_create` com `$v2 = true`, D2.)
  - `::auto_done_delivered_at`: fechar «Aprovação final…», «Enviar entregáveis finais…» (feita às 10:00), «Documento de
    direitos…», «Emitir fatura final» (por `flow_complete_doc_tasks`) e «Validar pagamento final» → Concluído
    automático, `delivered_at` = `done_at` de «Enviar entregáveis finais…», `deal_history 'delivered'` com `user_id`
    NULL, com `portfolio_at`/`testimonial_at` vazios (checklist não bloqueia); `xsell_after_delivery` correu.
  - `::back_from_delivery`: na Entrega, `pipe2_front_undo` da VFX → o negócio volta a Em execução.
  - `::concurrent_fronts_ready` (Review Focus 6): negócio v2 vfx+3d em Em execução, cada frente na última sub-etapa
    antes de Pronto com uma só obrigatória por fechar, regra `all_ready` na Em execução e `p2v_closing_rules` na Entrega;
    `p2v_race` com dois `pipe2_race.php --do=task_done` (a última obrigatória de cada frente, sessões 99 e 1) → as duas
    frentes Prontas, **exatamente 1** `deal_history 'stage'` Em execução → Entrega (e 1 `stage_events` do negócio),
    `deals.stage_id` = Entrega, a tarefa da regra `all_ready` criada **1** vez e as 5 de fecho **1** vez cada (contar
    por `auto_rule_id`), nenhum `'stage'` repetido nem para Concluído; os dois processos saem com código 0 e sem
    «Warning». 5 rondas, cada uma com um negócio novo; todas têm de passar.
  - `::stale_move_refused` (determinístico, complementa a corrida): ler o negócio em Em execução, movê-lo para a
    Entrega por outro caminho (UPDATE direto de `deals.stage_id`, como faria o pedido concorrente) e chamar
    `pipe2_deal_system_move($dealLido, <Entrega>, 'x', <id de Em execução>)` → `'stale'`, nenhum `deal_history 'stage'`
    novo; `pipe2_deal_reevaluate($id)` a seguir relê a Entrega e não cria regras `all_ready` nem outro movimento para a
    Entrega.
  - `::deferred_in_transaction` (Review Focus 1): com `$tp->beginTransaction()`, fechar a última obrigatória da última
    frente → a frente fica Pronta, `pipe2_deal_reevaluate` devolve `'deferred'`, o negócio fica em Em execução e a
    transação continua aberta (`inTransaction()` true); depois do commit, `pipe2_deal_reevaluate` → Entrega.
  - `::system_actor_deal`: com sessão 99, os movimentos do sistema gravam `user_id` NULL em `deal_history` e
    `stage_events`.
  - `::no_gate_alert` (T5): depois dos movimentos automáticos, a consulta do aviso «Gate de produção passado»
    (`alerts.php`) para o dono não devolve linhas (probe `my_alerts` do 99: nenhum título começa por «Gate de produção
    passado»).
  - `::change_funnel_refused`: v2 em produção → a mensagem; v1 → como antes.
  - `::v1_noop`: um negócio v1 de 2 frentes no Kickoff (`flow_on_enter`) → as frentes v1 `active`/`waiting` como antes
    (`xsell_kickoff`), nenhum `deal_line_steps`, `category` vazia.
- [ ] **Step 2: Escrever `tests/pipe2_close_test.php`** (esta tarefa cria o ficheiro): `::once_per_deal` — negócio
  vfx+3d com as regras de fecho nas duas Entregas → na Entrega, 5 tarefas no total (as de `invoice`/`payment` só do
  funil principal), nenhuma com `line_id`, todas com `stage_id` = Entrega do negócio.
  `::other_funnel_mandatory_blocks_done` (D10) — como o anterior, mais uma regra obrigatória «P2T · só no 3D» **só** na
  Entrega do funil 3d (não principal): ao entrar na Entrega, a tarefa dela fica com `stage_id` = Entrega do negócio;
  fechar as 5 de fecho com ela por fazer → o negócio fica na Entrega (`pipe2_deal_reevaluate` → `'blocked'`,
  `pipe2_note()` contém «Pronto para concluir — falta: P2T · só no 3D») e `pipe2_close_missing` lista-a; fechá-la →
  Concluído automático.
- [ ] **Step 3: Correr e ver falhar.** `php tests/pipe2_engine_test.php; php tests/pipe2_close_test.php`.
- [ ] **Step 4: Implementar** `pipe2_deal.php` e os ganchos.
- [ ] **Step 5: Correr e ver passar** + `php tests/pipe2_autoadvance_test.php; php tests/pipe2_deps_test.php; php tests/pipe2_filters_test.php; php tests/pipe2_v1_unchanged_test.php; php tests/pipe2_rollback_test.php; php tests/pipe2_includes_test.php`
  + `php -l` → `OK n`.
- [ ] **Step 6: Entregar.** Sem commit.

### Task 12: Avença e faturação (`pipe2_billing.php`)

**Files:**
- Create: `admin/inc/pipe2_billing.php`, `tests/pipe2_billing_test.php`, `tests/pipe2_monthly_test.php`,
  `tests/pipe2_invoice_test.php`
- Modify: `admin/inc/xsell.php` (`xsell_is_recurring`: `|| ($line['billing'] ?? '') === 'monthly'`)
- Modify: `admin/inc/pipeline_flow.php`: `flow_payment_default(PDO $pdo, float $price, ?int $funnelId, ?array $deal = null)`
  (primeira linha: `if ($deal !== null && ($deal['billing'] ?? '') === 'monthly') return (string)setting($pdo, 'pay_terms_buddy', '');`);
  `billing_validate_payment`: `$final = (<a condição de agora>) || pipe2_payment_is_final($pdo, $deal, $task);`
  (com `require_once pipe2_billing.php`); `billing_submit_invoice`: passa `$task ? (int)$task['id'] : null` a
  `fin_from_billing`
- Modify: `admin/inc/budget_view.php` (`flow_payment_default_view` passa `$deal` como 4.º argumento)
- Modify: `admin/inc/fin.php` (`fin_doc_issued`: `flow_complete_doc_tasks($pdo, (int)$d['deal_id'], 'invoice', !empty($d['task_id']) ? (int)$d['task_id'] : null);`;
  `fin_from_billing(…, ?int $by, ?int $taskId = null)`: só com `$taskId !== null` **e**
  `db_has_column($pdo, 'fin_docs', 'task_id')` procura primeiro o pedido `requested` com `task_id = $taskId`; senão (ou
  sem esse pedido) a consulta de agora, sem mudança — numa base sem a coluna, a v1 submete a fatura como hoje)
- Modify: `tests/pipe2_close_test.php` (`::is_paid_on_delivery_rule`)

**Interfaces:**
- Consumes: tarefas 3, 5, 9, 10, 11; `fin_request_invoice`, `billing_deal`, `fin_doc`, `FIN_ST`.
- Produces:
  ```php
  function pipe2_billing_set(PDO $pdo, array $deal, array $line, float $fee, int $months, ?int $by = null): void;  // fee > 0, 1 ≤ months ≤ 120, negócio v2; line: billing 'monthly', monthly_fee, fee_months, value; deals.billing 'monthly'; deal_log 'p2_billing'
  function pipe2_billing_clear(PDO $pdo, array $deal, array $line, ?int $by = null): void;   // volta a pontual (só antes de a frente começar)
  function pipe2_billing_cancel(PDO $pdo, array $deal, array $line, string $reason, ?int $by = null): void;  // motivo obrigatório; cancelled_at, billing_end = min(billing_end, hoje); a frente vai para Pronto final (pipe2_front_enter why 'cancel', status 'ready'); deal_log 'p2_billing' «Avença cancelada: <motivo>»; quem chama reavalia o negócio
  function pipe2_billing_day(PDO $pdo, array $line): int;       // N de D15: o menor trig_n (> 0) das regras fee_day ativas da Em execução do funil da frente; senão 1
  function pipe2_billing_day_ok(string $today, int $dayN): bool;  // puro: dia de $today ≥ min($dayN, último dia do mês de $today)
  function pipe2_billing_invoice_due(array $line, string $today, int $dayN): bool;  // puro, D15 (a): avença (billing 'monthly') começada (started_at), sem cancelled_at, AAAA-MM(started_at) ≤ AAAA-MM($today) ≤ AAAA-MM(billing_end) (billing_end vazio → pipe2_billing_period(line, started_at)['end']) e pipe2_billing_day_ok; NÃO olha para cycle_ym (a fatura do mês de início também abre)
  function pipe2_billing_restart_due(array $line, string $today, int $dayN): bool;  // puro, D15 (b): em Pronto (status 'ongoing' e stage_role 'ready'), não terminada (pipe2_billing_ended falso), cycle_ym < AAAA-MM($today) e pipe2_billing_day_ok
  function pipe2_billing_tick(PDO $pdo, string $today): array;   // ['opened' => n, 'restarted' => n, 'ended' => n] (D15)
  function pipe2_payment_is_final(PDO $pdo, array $deal, ?array $task): bool;  // v2 && doc_kind 'payment' && etapa da tarefa com role 'delivery'
  function pipe2_invoice_src(PDO $pdo, array $deal, array $task, array $in): array;  // $src de fin_request_invoice: scope 'deal', deal_id, NIF, nome e email do cliente de billing_deal (servidor), base/vat/total de $in, description; recusa tarefas que não sejam invoice abertas deste negócio
  function pipe2_invoice_request(PDO $pdo, array $deal, array $task, array $in, ?int $by = null): array;  // fin_request_invoice + UPDATE fin_docs.task_id; ['id' => int, 'code' => 'FAT-…'] (nunca o NIF)
  function pipe2_invoice_state(PDO $pdo, int $taskId): ?array;   // ['status' => código FIN_ST, 'label' => FIN_ST[...], 'code' => req_code]; sem NIF nem valores
  ```
  `pipe2_billing_tick`: por cada frente v2 avença viva (negócios em produção), com `pipe2_lock_deal` do negócio e
  **relendo o negócio e a frente depois do lock** (try/catch por negócio, como o `pipe2_tick`; as linhas de passos do
  ciclo novo nascem por `pipe2_step_row`, que também toma o lock), por esta ordem e com as duas condições
  de D15 **separadas**: (1) terminada (`pipe2_billing_ended`) e não Pronta final → Pronto final (`why 'cancel'` se
  cancelada, senão `'cycle'`, nota «Avença terminou») e passa à frente seguinte; (2) **fatura**: para cada regra
  `fee_day` ativa da Em execução do funil da frente com `pipe2_billing_invoice_due($line, $today, trig_n ?: 1)` →
  `pipe2_task_from_rule` com `pipe2_vdeal($deal, null, 'AAAA-MM' de hoje)` (uma por regra e ciclo, D12; conta em
  `opened` só quando cria); (3) **recomeço**: com `pipe2_billing_restart_due($line, $today, pipe2_billing_day(...))` →
  volta à 1.ª sub-etapa (`why 'cycle'`, `cycle` = mês de hoje, `status 'ongoing'`, novas linhas de passos do ciclo;
  `restarted`); fora de Pronto com `cycle_ym` < mês de hoje e `pipe2_billing_day_ok` → aviso `cycle` uma vez
  (`pipe2_notice_key('cycle', …, 'AAAA-MM' de hoje)`). `pipe2_front_start` (tarefa 10) grava `cycle_ym` = mês de início,
  o que só afeta o recomeço (b), nunca a fatura (a).
- [ ] **Step 1: Escrever `tests/pipe2_billing_test.php`:** `::recurring_reads_column` — `xsell_is_recurring` com
  `xray_kind 'buddy'` → true (como antes), `billing 'monthly'` → true, outra → false; `flow_payment_default` com um
  negócio `billing 'monthly'` → `pay_terms_buddy`; sem 4.º argumento → igual ao de antes para os 6 funis (a fotografia
  `pay_default` do `pipe2_v1_unchanged_test` continua igual).
- [ ] **Step 2: Escrever `tests/pipe2_monthly_test.php`:**
  - `::value`: `pipe2_billing_set(500, 12)` → `deal_lines.value 6000`, `deals.billing 'monthly'`, `pipe2_deal_value` 6000;
    meses 0 ou 121 → «Indique entre 1 e 120 meses.»; mensalidade 0 → «Indique a mensalidade.».
  - `::due_functions` (puro, D15): frente avença 12 meses começada a `2026-11-03` (`cycle_ym '2026-11'`,
    `billing_end 2027-10-31`), N = 5: `pipe2_billing_invoice_due` a `2026-11-04` → false; a `2026-11-05` → **true** (o
    `cycle_ym` já ser `'2026-11'` não impede a fatura do mês de início); a `2027-10-05` → true; a `2027-11-05` → false;
    com `cancelled_at` → false; sem `started_at` → false. `pipe2_billing_restart_due` com a frente em Pronto: a
    `2026-11-05` → false (mesmo mês); a `2026-12-04` → false (dia < N); a `2026-12-05` → true; com a frente em curso
    (`running`) → false; terminada → false. `pipe2_billing_day_ok('2027-02-28', 31)` → true, `('2027-02-27', 31)` →
    false. `pipe2_billing_day` sem regras `fee_day` → 1; com duas (`trig_n` 10 e 5) → 5.
  - `::one_invoice_per_month`: regra `fee_day` (`doc_kind 'invoice'`, `trig_n 5`, `is_gate 0`) na Em execução do funil
    cam de teste; frente começada em `2026-11-03` por `pipe2_front_start` (que grava `cycle_ym '2026-11'`): tick a
    `2026-11-04` → 0; `2026-11-05` → 1 tarefa com `cycle_ym '2026-11'`; outra vez → 0; `2026-12-05` → 2.ª
    (`'2026-12'`).
  - `::short_month_and_missed_day` (Review Focus 4): `trig_n 31` → abre a `2027-02-28`; com o cron a falhar o dia 5,
    abre a `2026-12-08` e só uma vez.
  - `::cycle_restart`: frente em Pronto (`ongoing`) com `cycle_ym '2026-11'` → tick a `2026-12-05` recomeça na 1.ª
    sub-etapa com `'2026-12'` e as tarefas `f_enter` do ciclo novo; fora de Pronto → fica e há 1 aviso `cycle`.
  - `::cancel_and_end`: `pipe2_billing_cancel(…, '')` → «Indique o motivo.»; com motivo → `cancelled_at`, frente
    `ready`; outra frente com `billing_end` ontem → o tick põe-na `ready`.
- [ ] **Step 3: Escrever `tests/pipe2_invoice_test.php`:**
  - `::request_src`: `pipe2_invoice_src` lê o NIF de `billing_deal` mesmo que `$in` traga outro (`'party_nif' => '999'`
    ignorado); recusa uma tarefa `payment`, uma feita, uma de outro negócio.
  - `::closes_own_task`: duas tarefas de fatura (ciclos 2026-11 e 2026-12) e dois `fin_docs` `requested` inseridos com
    `task_id` (cada um a sua); `fin_doc_issued(doc da 1.ª)` → só a 1.ª fica `done`; um `fin_docs` v1 sem `task_id` →
    fecha todas as abertas, como hoje.
  - `::submit_prefers_task_request`: `fin_from_billing(…, $taskId)` com a tarefa da 2.ª → o `fin_docs` da 2.ª passa a
    `issued` (não o mais antigo); sem `$taskId` → o mais antigo, como hoje (`billing_submit_invoice` precisa de um
    upload e não se chama em CLI: a passagem do id confirma-se lendo a linha alterada).
  - `::state_without_nif`: `pipe2_invoice_state` não tem a chave `nif` nem valores; `json_encode` não contém o NIF.
  - `::v1_at_42`: numa base na 42 (`pipe2_tmpdb_create(pipe2_base_dump())`, sem migrar, sem `fin_docs.task_id`):
    `fin_from_billing(…, $taskId)` de um negócio v1 com um pedido `requested` passa esse pedido a `issued` (o mais
    antigo, como hoje), sem exceção nem aviso de PHP (`set_error_handler`); `fin_doc_issued` dele fecha as tarefas de
    fatura abertas do negócio, como hoje.
- [ ] **Step 4: Acrescentar a `tests/pipe2_close_test.php`:** `::is_paid_on_delivery_rule` — v2: validar o pagamento
  final da Entrega → `deals.is_paid 1`; um pagamento de um ciclo (tarefa da Em execução) → `is_paid 0`; v1: o de
  Concluído → 1, como hoje.
- [ ] **Step 5: Correr e ver falhar.** `php tests/pipe2_billing_test.php; php tests/pipe2_monthly_test.php; php tests/pipe2_invoice_test.php; php tests/pipe2_close_test.php`.
- [ ] **Step 6: Implementar** `pipe2_billing.php` e os ganchos.
- [ ] **Step 7: Correr e ver passar** + `php tests/pipe2_engine_test.php; php tests/pipe2_v1_unchanged_test.php; php tests/pipe2_includes_test.php`
  + `php -l`. Anotar para a security-review: NIF só no servidor, `fin_docs.task_id`.
- [ ] **Step 8: Entregar.** Sem commit.

### Task 13: Relógio da v2 e ganchos do crosselling (`pipe2_tick.php`, `xsell.php`, `flow_tick`)

**Files:**
- Create: `admin/inc/pipe2_tick.php`, `tests/pipe2_tick_test.php`
- Modify: `admin/inc/pipeline_flow.php` (`flow_tick`: depois de `xsell_tick($pdo, $today);`,
  `try { require_once __DIR__ . '/pipe2_tick.php'; pipe2_tick($pdo, $today); } catch (Throwable $e) { error_log('[topper] pipe2_tick: ' . $e->getMessage()); }`)
- Modify: `admin/inc/xsell.php` (primeiras linhas, todas com `require_once` do módulo dentro do `if`):
  `xsell_close_missing` → `pipe2_close_missing`; `xsell_line_move` → `pipe2_front_move($pdo, $deal, pipe2_front_get($pdo, (int)$line['id']), $stageId, $force, current_user()['id'] ?? null)`;
  `xsell_requirements` → `pipe2_requirements`; `xsell_save_plan` → `pipe2_plan_save`; `xsell_vdeal` (puro): com
  `isset(PIPE2_LINE_STATUS[$line['status']]) || ($line['billing'] ?? '') === 'monthly'`, `$v['_v2'] = true` e a entrega
  da frente quando preenchida (antes da regra v1 do `independent`)
- Modify: `tests/pipe2_engine_test.php`, `tests/pipe2_dates_test.php`, `tests/pipe2_monthly_test.php` (casos novos)

**Interfaces:**
- Consumes: tarefas 5, 9, 10, 11, 12; `alert_limits`, `days_since`, `flow_due`, `flow_rules`.
- Produces:
  ```php
  function pipe2_tick(PDO $pdo, ?string $today = null): array;   // ['billing' => [...], 'fronts','advanced','replanned','stale','dated','deals']; nunca lança
  function pipe2_front_last_move(PDO $pdo, array $line): string; // o mais recente de stage_entered_at, tarefas feitas da frente e passos
  ```
  `pipe2_tick`: `pipe2_billing_tick`; depois, para cada negócio em produção num funil v2 (`pipe2_v2_funnels_sql`), com
  `pipe2_lock_deal` e try/catch por negócio, **relendo o negócio e as frentes depois do lock** (um pedido pode tê-los
  mudado entre a lista e o lock): em cada frente viva `running`/`ongoing` na sub-etapa S — `pipe2_replan`
  (> 0 → aviso `replan` uma vez, `pipe2_notice_key('replan', …)`); parada há ≥ limite (`max_days` de S, senão
  `alert_limits['deal']`) → aviso `stale` uma vez, e regras `f_stale` de S com `trig_n ?: limite`; regras `date` de S
  com `flow_due` sobre `pipe2_vdeal` e prazo ≤ hoje; `pipe2_front_reevaluate`; no fim do negócio,
  `pipe2_deal_reevaluate($pdo, $id, 'cron')`.
- [ ] **Step 1: Escrever `tests/pipe2_tick_test.php`:** `::reevaluate_cron` (obrigatória fechada por SQL direto → o tick
  avança a frente e, se for a última, o negócio); `::stale_once` (frente parada 6 dias com `max_days 5` → 1 aviso
  `stale` e a tarefa `f_stale`; 2.º tick → nada novo); `::replan_notice_once`; `::date_rules_on_substages` (regra
  `date` `due_mode 'delivery'` `-3` numa sub-etapa: cria 3 dias antes da entrega da frente, uma vez);
  `::v1_noop` (`pipe2_tick` numa base privada sem negócios v2 devolve todas as contagens a 0 e `p2v_sig` de
  `tasks`, `deal_lines`, `deal_history`, `deal_line_steps`, `pipe2_notices` fica igual);
  `::never_throws` (um negócio v2 com dados partidos — frente com `stage_id` de outro funil — não impede os outros);
  `::cron_and_request` (Review Focus 6): negócio v2 vfx+3d em Em execução, regra `all_ready` na Em execução e
  `p2v_closing_rules` na Entrega; a última obrigatória da frente vfx fechada por SQL direto (o relógio vai avançá-la
  para Pronto) e a da 3d aberta; `p2v_race` com `pipe2_race.php --do=tick --today=<hoje> --uid=0` e
  `pipe2_race.php --do=task_done --task=<a da 3d> --uid=99` → as duas frentes Prontas, exatamente 1 `p2_front` para
  Pronto por frente, **exatamente 1** `deal_history 'stage'` Em execução → Entrega, a tarefa `all_ready` 1 vez e as 5
  de fecho 1 vez cada; os dois processos com código 0 e sem «Warning». 5 rondas, cada uma com um negócio novo.
- [ ] **Step 2: Acrescentar a `tests/pipe2_engine_test.php`:** `::front_move_bypasses_deal_move` (`xsell_line_move`
  num v2 → frente em S2, `deals.stage_id` igual, evento `p2_front`, nenhum `'stage'`); `::close_missing_hook`
  (`xsell_close_missing` de um v2 = `pipe2_close_missing`; de um v1 = como antes); `::proposal_gate_v2`
  (`proposal_requirements` de um v2 com 2 serviços: sem o item do crosselling (sincronização) e com o item do orçamento;
  v1: com o da sincronização); `::xsell_tick_ignores_v2` (`xsell_tick` não mexe em frentes v2).
  A `tests/pipe2_dates_test.php`: `::vdeal_front_delivery` (`xsell_vdeal` de uma frente v2 com `delivery_date` usa a da
  frente com `sync_mode 'sync'`; frente v1 `active` em `sync` usa a do negócio, como hoje); `::save_plan_v2`
  (`xsell_save_plan` de um v2 com `sync_mode` inválido não lança e grava as datas por frente).
  A `tests/pipe2_monthly_test.php`: `::cycle_opens_once` (pelo `flow_tick`) e `::ends` (avença que termina → frente
  Pronta → o tick leva o negócio à Entrega).
- [ ] **Step 3: Correr e ver falhar.** `php tests/pipe2_tick_test.php; php tests/pipe2_engine_test.php; php tests/pipe2_dates_test.php; php tests/pipe2_monthly_test.php`.
- [ ] **Step 4: Implementar** `pipe2_tick.php` e os ganchos.
- [ ] **Step 5: Correr e ver passar** + `php tests/pipe2_filters_test.php; php tests/pipe2_v1_unchanged_test.php; php tests/pipe2_rollback_test.php; php tests/pipe2_includes_test.php`
  + `php -l`.
- [ ] **Step 6: Entregar.** Sem commit.

### Task 14: Leads com vários serviços (`pipe2_leads.php`, `lead_convert`)

**Files:**
- Create: `admin/inc/pipe2_leads.php`, `tests/pipe2_lead_services_test.php`
- Modify: `admin/inc/leads.php` (`require_once __DIR__ . '/pipe2.php';`; em `lead_convert`, dentro da transação, logo
  a seguir ao `UPDATE deals SET phase = 'pipeline' …`: `require_once __DIR__ . '/pipe2_leads.php'; pipe2_lead_convert_lines($pdo, $lead, $funnelId);`)

**Interfaces:**
- Consumes: tarefas 2, 3; `deal_log`.
- Produces:
  ```php
  function pipe2_codes_clean($codes): string;             // string CSV ou array → só códigos de PIPE2_SERVICES, sem repetidos, pela ordem v2, sem espaços
  function pipe2_codes_list(string $csv): array;
  function pipe2_v1_service_codes(string $label): array;  // ['codes' => [...], 'ambiguous' => bool] (D27)
  function pipe2_site_service_codes($value): array;       // 'vfx' → ['vfx','3d'] (ambíguo), 'marketing' → ['cam'], 'both' → ['vfx','3d','cam']; os 7 códigos aceites tal e qual; arrays juntam-se
  function pipe2_lead_services_set(PDO $pdo, int $leadId, $codes, ?int $by = null): string;  // só phase lead ou pipeline; grava deals.service_codes; deal_log 'p2_services'; deals.service não muda
  function pipe2_lead_category(PDO $pdo, array $lead): string;   // pipe2_category_of(service_codes) — nunca grava
  function pipe2_funnel_for_code(PDO $pdo, string $code): ?int;  // funil v2 com esse service_code: is_active DESC, v2_only ASC, position, id
  function pipe2_lead_convert_lines(PDO $pdo, array $lead, int $funnelId): int;  // só se o funil for v2: frente principal planned + uma planned por cada outro código com funil v2; XSELL_FRESH; devolve as criadas
  ```
- [ ] **Step 1: Escrever o teste a falhar:** `::clean_and_order` (`'3d, vfx ,xx,vfx' → 'vfx,3d'`; array igual; `'' → ''`;
  o máximo `'vfx,3d,md,cmp,ed,adv,cam'` tem 24 caracteres); `::v1_label_map` (`'Marketing Buddy' → ['cam'], false`;
  `'VFX / 3D' → ['vfx','3d'], true`; `'Ambos' → ['vfx','3d','cam'], true`; `'' → [], false`); `::site_values`;
  `::set_on_lead` (grava os códigos, regista, `service` igual; numa fase `production` → «Os serviços mudam-se nas
  frentes.»); `::category_on_lead` (`vfx,3d,ed → 'postprod'`, `md,3d → ''`, e `deals.category` continua vazia);
  `::convert_creates_planned_lines` (lead com `vfx,3d` convertida para o funil vfx de teste → 2 `deal_lines` `planned`,
  vfx principal, `xsell_is_multi` true; com `XSELL_FRESH`); `::v1_convert_unchanged` (lead convertida para um funil v1 →
  nenhuma `deal_lines`, como hoje); `::lists_untouched` (`LEAD_SERVICES_LIST` igual; `lead_input(['service' => 'vfx,3d'])`
  → `service ''`, como hoje).
- [ ] **Step 2: Correr e ver falhar.** `php tests/pipe2_lead_services_test.php`.
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Correr e ver passar** + `php tests/pipe2_v1_unchanged_test.php; php tests/pipe2_includes_test.php` + `php -l`.
- [ ] **Step 5: Entregar.** Sem commit.

### Task 15: «Anular» em cascata (`pipe2_undo.php`, `undo.php`)

**Files:**
- Create: `admin/inc/pipe2_undo.php`
- Modify: `admin/inc/undo.php` (`require_once __DIR__ . '/pipe2.php';`), `tests/pipe2_undo_test.php` (casos novos)

**Interfaces:**
- Consumes: tarefas 10, 11 (`pipe2_moves`, `pipe2_lock_deal`, `pipe2_task_changed`), tarefa 3 (`pipe2_store_values`).
- Produces:
  ```php
  function pipe2_undo_offer(array $moves): bool;              // false se algum não se desfaz (D13)
  function pipe2_undo_snapshot(PDO $pdo, array $deal): array; // só v2: ['lines' => [[id, status, stage_id, exec_owner_id, value]], 'steps_max', 'deps_max', 'category', 'category_at']; v1 → []
  function pipe2_undo_check(PDO $pdo, array $moves): void;    // cada frente/negócio continua no 'to' do seu movimento (do último para o primeiro); senão RuntimeException('O registo mudou entretanto; não foi anulado.')
  function pipe2_undo_moves(PDO $pdo, array $moves): void;    // do último para o primeiro: repõe frente/negócio, linhas de passos, met_at; reabre as instâncias de séries fechadas pelo sistema ('closed': estado e done_at de antes) e apaga as linhas de task_log desse fecho; apaga as tarefas criadas ainda por fazer, o histórico, os stage_events, os passos novos e os avisos
  function pipe2_undo_kickoff(PDO $pdo, array $snap): void;   // D29
  ```
  Ganchos em `undo.php`: `undo_push` — `require_once pipe2_engine.php` e `pipe2_undo.php`; com `pipe2_moves()` não vazio,
  `if (!pipe2_undo_offer($moves)) return '';` e `$data['p2_moves'] = $moves`. `undo_deal_snapshot` — junta
  `['p2' => pipe2_undo_snapshot(...)]` quando não vazio. `undo_apply` — à entrada, com `p2_moves`, abre uma transação
  se não houver, `pipe2_undo_check`, corre o caso, `pipe2_undo_moves`, commit (rollback e relança em erro).
  `'deal_stage'` — com `p2`, `pipe2_undo_kickoff` no lugar do `xsell_reopen`. `'line_stage'` — num v2 não corre
  `xsell_reopen` nem o `deal_log 'stage'` («Anulado» fica num `p2_front` pela cascata). `'task'` e `'contact_task'` —
  depois do UPDATE, `pipe2_task_changed($pdo, <tarefa relida>, <estado reposto> === 'done' ? 'done' : 'reopen')`.
  `'budget_accept'` — no fim, num v2 em produção, `pipe2_store_values`.
- [ ] **Step 1: Acrescentar a `tests/pipe2_undo_test.php`** (com `p2v_as(1)` e `undo_push`/`undo_run` como num pedido):
  - `::undo_cascades`: `task_done` da última obrigatória de S1 → frente em S2, tarefa de S2 criada; `undo_run` → a
    tarefa volta a `todo`, a frente a S1 (estado e `stage_entered_at` de antes), a tarefa de S2 apagada, os
    `deal_history`/`stage_events` do movimento apagados, as linhas de passos como estavam; a mensagem «Tarefa reposta.».
  - `::cascade_with_release`: o movimento de A libertou B → o «Anular» repõe B em `depwait` e o `met_at` a NULL.
  - `::undo_reopens_closed_series` (D18): S1 com uma série aberta e a última obrigatória por fechar; `task_done` da
    obrigatória → a frente em S2 e a série fechada pelo sistema; `undo_run` → a frente em S1, a instância da série outra
    vez aberta (estado e `done_at` de antes) e sem a linha de `task_log` do fecho; o `task_log` da obrigatória fica como
    o «Anular» da v1 o deixa.
  - `::undo_kickoff_removes_fronts`: `deal_move` para o Kickoff de um negócio só com a principal por criar e um serviço
    `planned` → `undo_apply('deal_stage', …)` apaga a principal criada, o serviço volta a `planned`, 0 linhas de passos
    e de ligações, `category` e `category_at` como estavam; com um cartão num quadro da frente → «Já há trabalho no
    Toppernote ou no Review destas frentes; não foi anulado.» e nada muda.
  - `::not_offered_after_auto_done`: o `task_done` que leva ao Concluído automático → `undo_push('task', …)` devolve `''`.
  - `::refuses_if_changed`: depois do avanço automático, um `pipe2_front_move` humano para S3 noutro pedido → o
    «Anular» do 1.º dá «O registo mudou entretanto; não foi anulado.» e nada muda (a tarefa continua `done`).
  - `::inside_multi`: um `'multi'` com um `'task'` que tem `p2_moves` → desfaz numa só transação, sem «already an
    active transaction».
  - `::v1_noop`: o «Anular» de um `deal_stage` e de uma `task` de um negócio v1 dá o mesmo que antes (snapshot sem `p2`,
    dados sem `p2_moves`, mesmo estado final).
- [ ] **Step 2: Correr e ver falhar.** `php tests/pipe2_undo_test.php`.
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Correr e ver passar** + `php tests/pipe2_autoadvance_test.php; php tests/pipe2_engine_test.php; php tests/pipe2_includes_test.php` + `php -l`.
- [ ] **Step 5: Entregar.** Sem commit.

### Task 16: Toppernote e Review por frente (`pipe2_cc.php`)

**Files:**
- Create: `admin/inc/pipe2_cc.php`, `tests/pipe2_review_test.php`, `tests/pipe2_cc_test.php`
- Modify: `admin/inc/cc_review.php` (`require_once __DIR__ . '/pipe2.php';`; ganchos no topo de `cc_rv_stage_for`
  (v2 → `pipe2_cc_revisions_stage`), em `cc_rv_item_create` e `cc_rv_item_save` (`pipe2_cc_item_line_check` antes de
  gravar), no INSERT de `cc_rv_rounds` de `cc_rv_send` e de `cc_rv_client_submit` (`line_id` num v2), no
  `cc_rv_client_submit` (v2: `pipe2_rounds_add(…, 'portal')` em vez de `rounds_add`, e sem `cc_rv_change_order_task`) e
  em `cc_rv_approval_tasks` (o filtro passa a `pipe2_cc_approval_line_sql`))
- Modify: `admin/deal_flow.php` (caso `'round'`: num v2 com `line`, `pipe2_round_manual_check` e
  `flash(pipe2_rounds_add($pdo, $deal, $line, $note, $me, 'manual')['msg'])`)

**Interfaces:**
- Consumes: tarefas 3, 9, 11; `rounds_count`, `deal_log`.
- Produces:
  ```php
  function pipe2_cc_revisions_stage(PDO $pdo, array $deal, ?int $lineId): ?int;   // v2: sub-etapa role 'revisions' do funil da frente (sem frente e com uma só frente viva: a dela); v1 → null
  function pipe2_cc_item_line_check(PDO $pdo, array $deal, ?int $lineId): void;   // v2 com 2+ frentes vivas e sem frente válida → RuntimeException('Escolha a frente desta peça (o negócio tem várias frentes).')
  function pipe2_rounds_count(PDO $pdo, int $lineId, string $cycle): int;
  function pipe2_rounds_add(PDO $pdo, array $deal, array $line, string $note, int $by, string $src): array;  // ['round_id','count','over','msg']; deal_rounds com line_id, cycle_ym, stage_id = Revisões, user_id = $by (0 no portal); deal_log 'round'; acima das incluídas: deal_log 'rounds' e pipe2_run_rules(Revisões, 'rounds', frente)
  function pipe2_round_manual_check(PDO $pdo, array $deal, array $line): void;     // D28
  function pipe2_cc_approval_line_sql(PDO $pdo, array $item): string;              // v2 com 2+ frentes: ' AND line_id = <frente>'; v2 com uma: ' AND (line_id IS NULL OR line_id = <frente>)'; v1: o filtro de hoje
  ```
- [ ] **Step 1: Escrever `tests/pipe2_review_test.php`:** `::piece_needs_front` (v2 com 2 frentes, `cc_rv_item_create`
  sem frente → a mensagem; com frente → `stage_id` = Revisões dessa frente; uma só frente, sem frente → Revisões dela);
  `::rounds_per_front_cycle` (`cc_rv_client_submit` numa peça da frente vfx → `deal_rounds` com `line_id`, `cycle_ym` da
  frente, `stage_id` Revisões vfx, `user_id 0`; `cc_rv_rounds.line_id` = vfx); `::portal_not_manual`
  (`pipe2_round_manual_check` dessa frente → «As rondas desta frente contam-se pelo portal do cliente.»; outra frente →
  passa); `::over_included_uses_rounds_trigger` (Revisões vfx com 2 incluídas e uma regra `rounds`: a 3.ª ronda cria a
  tarefa da regra 1 vez, nenhuma «Pedido de alteração · …»; a 4.ª não cria outra no mesmo ciclo);
  `::approval_only_own_front` (`cc_rv_approval_tasks` de uma peça vfx não devolve tarefas `review` da 3d nem sem
  frente); `::v1_noop` (num negócio v1: `cc_rv_stage_for` = etapa atual, `cc_rv_change_order_task` cria a tarefa como
  hoje, `cc_rv_approval_tasks` inclui `line_id IS NULL`).
- [ ] **Step 2: Escrever `tests/pipe2_cc_test.php`:** `::fronts_before_board` (C1) — `deal_move` para o Kickoff de um v2
  `vfx,3d` → `cc_boards` do projeto com um sub-quadro `front` por frente (`line_id` das duas); um v2 de um só serviço →
  o quadro do projeto sem quadros `front`; `cc_stages` do funil continua a escolher os sub-quadros (não as sub-etapas).
- [ ] **Step 3: Correr e ver falhar.** `php tests/pipe2_review_test.php; php tests/pipe2_cc_test.php`.
- [ ] **Step 4: Implementar.**
- [ ] **Step 5: Correr e ver passar** + `php tests/cc_comments_last_test.php; php tests/pipe2_v1_unchanged_test.php; php tests/pipe2_includes_test.php`
  + `php -l`.
- [ ] **Step 6: Entregar.** Sem commit.

### Task 17: Prova de que a v1 não muda, includes e §10.34

**Files:**
- Create: `tests/pipe2_v1_scenario.php`, `tests/pipe2_v1_hooks_test.php`
- Modify: `tests/pipe2_includes_test.php`, `docs/ESPECIFICACAO-PLATAFORMA.md` (nova `### 10.34 Pipeline v2 — fase 1:
  frentes, motor de avanço e regras — §34.11 (6 nov 2026)` logo a seguir à 10.33)

**Interfaces:**
- Consumes: tudo; `tests/pipe2_tree.php` (tarefa 1).
- Produces: `tests/pipe2_v1_scenario.php --db=X` — só funções da v1 que existem em 8c581ae; com a sessão do uid 1 e a
  data fixa do cenário, numa base privada: `lead_convert` de uma lead para o VFX real; `deal_move` (com `$force` de
  admin onde o gate pedir) X-Ray → Proposta → Aguardar feedback → Kickoff → as etapas de produção → Concluído; `task_done`
  de tarefas criadas (uma obrigatória, uma que se repete), `task_reopen`; um negócio de crosselling v1 (VFX + CGI) no
  Kickoff, `xsell_line_move` das frentes, `xsell_close_missing`; `flow_tick` com uma data fixa; `undo_apply('deal_stage')`
  e `('task')`; `billing_validate_payment` de uma tarefa de pagamento; `fin_doc_issued` de um pedido inserido;
  `deal_change_funnel`; `team_reassign` de um utilizador de teste; `cc_rv_item_create` e `cc_rv_approval_tasks`; e por
  fim `my_alerts` do dono. Imprime um JSON normalizado: as linhas dos negócios do cenário em `deals`, `deal_lines`,
  `tasks`, `deal_history`, `stage_events`, `deal_rounds`, `cc_rv_items`, `fin_docs`, só com as colunas da v1, com os ids
  trocados pela ordem de criação, sem datas/horas de relógio; as chaves de `my_alerts`; os `kind` e as chaves de
  `$_SESSION['undo']`.
- [ ] **Step 1: Escrever `tests/pipe2_v1_hooks_test.php`:** `::same_as_phase0` — duas bases privadas do dump; uma
  migrada e corrida com o código de agora (`pipe2_sub`), a outra com a árvore de 8c581ae (`pipe2_tree('8c581ae', [],
  ['tests/boot.php','tests/lib.php','tests/pipe2_lib.php','tests/pipe2_v1_snap.php','tests/pipe2_v1_scenario.php'])`,
  migrada e corrida nela); `t_eq(pipe2_diff($old, $new), [], …)`. `::scenario_runs` — o cenário sai com código 0 nas duas
  e mexe em pelo menos 30 linhas (prova que não é vazio).
- [ ] **Step 2: Estender `tests/pipe2_includes_test.php`:** para cada chamada `pipe2_xxx(` num ficheiro de `admin/` ou
  `admin/inc/`, o ficheiro que define `function pipe2_xxx` (procurado em `admin/inc/pipe2*.php` e
  `admin/inc/migrate_pipeline2*.php`) é o próprio ou tem `require_once __DIR__ . '/<ficheiro>'` (ou `'/inc/<ficheiro>'`)
  no ficheiro que chama; falha com «<ficheiro> chama <função> sem require_once de <definidor>».
- [ ] **Step 3: Correr.** `php tests/pipe2_v1_hooks_test.php; php tests/pipe2_includes_test.php`. Se falhar,
  superpowers:systematic-debugging: o caminho exato do diff diz o gancho; corrigir na tarefa dona do ficheiro.
- [ ] **Step 4: Escrever a §10.34** (10 a 14 linhas, estilo da 10.33): os 15 módulos e o que cada um faz numa frase;
  como se reconhece um negócio v2 (D1); o passo 44; os ganchos por ficheiro; «Sistema»; o que fica para a fase 2 (D30);
  a ordem de publicação e a reversão (Global Constraints); as decisões por confirmar (D21; D18: as séries não fecham
  num movimento para trás); os testes `pipe2_*` novos. Sem
  «§» em texto de interface (a especificação pode citar secções).
- [ ] **Step 5: Bateria inteira.** `for t in tests/pipe2_*_test.php; do php "$t" > /tmp/p2_$$.txt 2>&1 || { echo "FALHOU $t"; tail -5 /tmp/p2_$$.txt; }; done`
  e `bash /tmp/claude-0/-home-user-portfolio-website/07a7b6fb-2708-535b-b71f-5de7fa1226a8/scratchpad/suite33.sh`
  (≈ 25 min; exit 0). Esperado: nenhum «FALHOU» e exit 0. Um teste UI que falhe corre-se outra vez; se continuar, ver se
  também falha em 8c581ae (`git worktree add` no scratchpad, servidor noutra porta; nunca `git stash`).
- [ ] **Step 6: Verificação final** (superpowers:verification-before-completion): colar a saída dos `pipe2_*_test.php`,
  o resumo da bateria, `git status --short` (só os ficheiros deste plano; `tests/.pipe2` não aparece) e
  `mariadb topper -N -e "SELECT COUNT(*) FROM settings WHERE skey='pipeline_v2_at'; SELECT svalue FROM settings WHERE skey='schema_version'"`
  → `0` e `44`. Sem commit.
