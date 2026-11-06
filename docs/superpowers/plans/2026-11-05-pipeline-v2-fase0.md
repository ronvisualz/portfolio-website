# Pipeline v2 — Fase 0: bandeira e esquema — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> Cada tarefa é feita por um agente diferente e os conjuntos de ficheiros são disjuntos. **Ninguém faz commit nem
> push**: o orquestrador faz o commit no fim da fase. Nada vai para o main nem para a Hostinger.

**Goal:** Criar a bandeira `settings.pipeline_v2_at` (lida por `pipe2_on()`) e o esquema da Pipeline v2 (passo 43). A
semente corre uma só vez e cria os funis Motion Design, Composit e Edição inativos, as sub-etapas dos 7 serviços, as
categorias e os modelos de dependências. Os leitores da v1 passam a filtrar o que é só da v2. A plataforma tem de
continuar **exatamente** em modo v1, e isso prova-se por teste.

**Architecture:** O passo novo `migrate_pipeline2_43()` fica em `admin/inc/migrate_pipeline2.php` e é chamado no fim
de `run_migrations`. Corre assim: GET_LOCK, depois o DDL só com os helpers, depois a semente numa transação (sem DDL),
reclamada com INSERT IGNORE, e por fim RELEASE_LOCK. As constantes, a bandeira e os fragmentos SQL dos filtros ficam em
`admin/inc/pipe2.php`. Os leitores da v1 escondem as etapas com `level <> 'deal'` ou `retired_at` preenchido, e também
os funis `v2_only = 1` enquanto a bandeira estiver vazia. Os testes correm numa base privada restaurada do estado v42,
guardado antes de se mexer no `SCHEMA_VERSION`.

**Tech Stack:** PHP 8 sem framework; MySQL em produção, sem modo estrito; MariaDB 10.11 na bancada, com
`STRICT_TRANS_TABLES` e sem `ONLY_FULL_GROUP_BY`. Os testes são scripts PHP em `tests/*_test.php` (`tests/lib.php`:
`t_true`, `t_eq`, `t_done`, `tests_http`).

**Spec:** `docs/ESPECIFICACAO-PLATAFORMA.md` §34.3, §34.8, §34.9, §34.10, **§34.11 (prevalece)**; notas
`docs/superpowers/plans/2026-11-05-pipeline-v2-notas.md` (nota 00 e nota 0 prevalecem); plano geral
`docs/superpowers/plans/2026-11-05-pipeline-v2.md` (fase 0, rastreabilidade F1–F3, M1, M2, M5–M7).

## Global Constraints

- **Regra de ouro.** Até ao «Aplicar» (fase 3), nenhum destes muda de comportamento: ecrãs, listas, funis ativos,
  `LEAD_SERVICES_LIST`, Definições (`funis.php`, `definicoes.php?t=pipeline`), Pipeline, aba Negócios, `negocio.php`,
  Dashboard, Relatórios, Financeiro, Tarefas e automações, portal. A fase 0 não tem interface nova.
- O passo novo vai no **fim** de `run_migrations`, num ficheiro próprio (`admin/inc/migrate_pipeline2.php`), com
  `SCHEMA_VERSION = 43`. Ao publicar, a migração vai antes do `inc/migrate.php`.
- Só `db_has_column`/`db_create`/`db_column_type` (e o `db_add_column`, que usa o `db_has_column`). Nenhum `ALTER` que
  possa falhar por causa dos dados (UNIQUE, ENUM, NOT NULL sem DEFAULT). Nada de `ADD COLUMN IF NOT EXISTS`, de
  `CREATE INDEX IF NOT EXISTS` nem de `RETURNING`, que só existem em MariaDB.
- As consultas têm de ser compatíveis com `ONLY_FULL_GROUP_BY`. Larguras: `trig`/`status` ≤ 10,
  `doc_kind`/`repeat_rule`/`source` ≤ 8, `step` ≤ 12. A produção corta valores sem avisar.
- A semente corre uma vez: GET_LOCK em MySQL e bandeira `pipe2_seeded` reclamada com INSERT IGNORE na mesma transação
  que os dados.
- Nada se apaga: funis novos com `is_active = 0` e nunca DELETE. Etapas existentes não mudam de `kind` nem de posição.
  Não há `kind` novo. Nenhum funil é desativado, renomeado nem reposicionado, e o `xray_kind` não muda.
- Na fase 0 não se escreve: `settings.pipeline_v2_at`, regras em `stage_automations`, nenhum UPDATE a `deals`,
  `deal_lines`, `tasks` nem ao histórico, `deal_stale_days` (fica 10) e `settings_def.php` (as chaves novas não
  aparecem nas Definições).
- Os leitores por id (`stage_get`, `funnel_get`, `mx_stages`, os JOIN em `d.stage_id`, o histórico) **não** levam
  filtro: o histórico tem de continuar a ver etapas retiradas.
- Código, comentários e textos em pt-PT. Nunca «§» na interface. Sem identificadores de modelos no código.
- Nunca ligar `pipeline_v2_at` na bancada partilhada (`topper`). Para testar a bandeira ligada, usar uma transação
  desfeita no fim ou uma base privada.
- **Ordem de publicação** (fica também escrita na 10.33, T6):
  1. `admin/inc/pipe2.php`;
  2. todos os leitores e ecrãs da T4 e da T5, juntos: `admin/inc/deals.php`, `admin/inc/pipeline.php`,
     `admin/inc/pipeline_flow.php`, `admin/inc/xsell.php`, `admin/funis.php`, `admin/negocios.php` e
     `admin/relatorios.php`;
  3. `admin/inc/migrate_pipeline2.php`;
  4. `admin/inc/migrate.php`, **por último**.

  Porquê: assim que o `migrate.php` (43) chega ao servidor, o pedido seguinte semeia 6 sub-etapas (`kind 'production'`)
  em cada funil ativo VFX, CGI, Adverts e Campanhas e cria os funis Motion Design, Composit e Edição. Com os leitores
  antigos ainda no servidor:
  - `funnel_stages()` e `flow_order` mostravam as sub-etapas nas colunas do Pipeline, em `funis.php` e em
    `deal_steps`, e a «última etapa de produção» do `xsell_close_missing` passava a ser «Pronto»;
  - o `deal_move` e o `xsell_line_move` antigos deixavam arrastar um negócio v1 para uma sub-etapa. Com o
    `flow_order` já filtrado mas a guarda ainda por publicar, o `flow_gate_check` (`pipeline_flow.php:463`) devolve
    `''` para um destino fora do `flow_order` e não há gate nenhum. Por isso os filtros e as guardas vão juntos;
  - o `funis.php` antigo listava os funis `v2_only` e deixava fazer «Ativar funil».

  Os leitores novos funcionam numa base ainda na 42 (D5): os fragmentos dão `'1=1'` e `pipe2_funnel_hidden` dá `false`,
  porque falta `v2_only`. Teste: `pipe2_filters_test::before_migration_no_error` (T4). O `inc/deals.php` passa a
  incluir o `inc/pipe2.php` em todos os pedidos (o `bootstrap.php` inclui sempre o `deals.php`), por isso o
  `pipe2.php` vai primeiro. A regra «a migração vai antes do `inc/migrate.php`» continua a valer.
- **Reversão** (fica também escrita na 10.33). A migração não se desfaz em produção: nada se apaga e não há função de
  desfazer fora dos testes. O que se reverte é o código:
  - com a base ainda na 42 (entre os passos 1 e 3 da ordem de publicação), repõe-se tudo;
  - depois do passo 43 (`schema_version` 43 ou `pipe2_seeded` em `settings`), repõe-se o código anterior **menos**
    `inc/pipe2.php` e os 7 leitores e ecrãs (`inc/deals.php`, `inc/pipeline.php`, `inc/pipeline_flow.php`,
    `inc/xsell.php`, `funis.php`, `negocios.php`, `relatorios.php`), que ficam na versão da fase 0. Os antigos não
    filtram nada e partiam a v1 como na ordem de publicação: sub-etapas nas colunas do Pipeline e em `funis.php`,
    «última etapa de produção» = «Pronto» no `xsell_close_missing`, funis `v2_only` listados e ativáveis. Um erro num
    desses 8 corrige-se para a frente;
  - o `inc/migrate.php` antigo (42) não volta a migrar nem desfaz nada (`bootstrap.php`: só migra se a base estiver
    abaixo do `SCHEMA_VERSION`): a base fica na 43, o `inc/migrate_pipeline2.php` pode sair, e quando o
    `migrate.php` (43) regressar a semente não volta a correr (versão já na 43 e `pipe2_seeded`);
  - desfazer também a base só repondo a cópia de antes do passo 43 **junto** com todo o código antigo (perde o que se
    escreveu depois); nunca com DELETE.

  Prova: `pipe2_rollback_test` («a migração aplicada e revertida numa cópia da base», das notas). Monta numa pasta
  temporária o `admin/` de 428480f com os 8 por cima e corre-o contra uma cópia migrada: `--migrate` dá 43 → 43 e a
  fotografia `--php` dá diff `[]` com `base42.json` (`::code_rollback`). O controlo `::full_rollback_breaks` corre o
  428480f inteiro e tem de mostrar as sub-etapas e os funis só da v2. Com o código revertido, as linhas injetadas do
  `::injected_rows_hidden` continuam escondidas (`::rollback_injected_rows`) e as escritas forjadas para uma
  sub-etapa continuam recusadas (`::rollback_guards`; com o 428480f inteiro passam). Tirar qualquer um dos 5
  ficheiros da biblioteca da lista faz falhar pelo menos um caso; os 3 ecrãs são os que o `pipe2_screens_test` e o
  `::after_migration_http` provam. `::documented` verifica que esta regra e a 10.33 nomeiam os 8 ficheiros.

## Review Focus

1. **A produção é diferente da bancada.** Os ids podem ser outros, o CGI pode ser um combinado sem funil base, pode
   haver um funil da equipa chamado «Edição», e pode faltar o departamento «Adverts». A semente procura pelo código ou
   pelo `xray_kind` exato dos funis base, nunca pelo id nem pelo nome. Salta o que falta sem falhar e nunca cria
   departamentos. Teste na T3: `pipe2_schema_test::seed_lookup_edges`.
2. **O leitor corre numa base ainda na versão 42.** Acontece em todas as publicações, entre o passo 2 e o passo 4 da
   ordem de publicação (os leitores e os ecrãs vão antes da migração), e numa base privada. Não pode aparecer
   «Unknown column», nem na biblioteca nem nas consultas de funis dos ecrãs. Depois de a base migrar, a mesma ligação
   já vê o filtro, porque a cache só guarda o `true`. Teste na T4: `pipe2_filters_test::before_migration_no_error` e
   `::ready_not_cached_false`.
3. **POST forjado com o id de uma sub-etapa ou de uma etapa retirada.** Cobre mover o negócio, mover a frente, mudar
   de funil e guardar, apagar ou reordenar uma etapa em `funis.php`. Tem de ser recusado e nada pode mudar. Testes na
   T4 (`pipe2_filters_test::writes_refuse_hidden_stages`) e na T5 (`pipe2_screens_test::funis_stage_guard`).
4. **Endereço direto de um funil escondido.** Cobre `funis.php?f=` e `pipeline.php?f=` (GET, também nos funis reais
   Motion Design, Composit e Edição) e os POST `toggle`, `add_stage`, `rename` e `create from[]` (num funil descartável
   marcado `v2_only`, ver o ponto 6). Tudo se comporta como se o funil não existisse, e o funil não se ativa na v1.
   Teste na T5: `pipe2_screens_test::hidden_funnel_direct_url`.
5. **A semente falha a meio.** Nada pode ficar meio semeado nem marcado como semeado. A corrida seguinte semeia e o
   lock fica livre. Teste na T3: `pipe2_schema_test::fault_rolls_back`.
6. **A corrida vermelha da T5 corre na bancada partilhada com o `funis.php` antigo.** Nesse momento os POST fazem
   mesmo as escritas, e a semente já não volta a correr (`pipe2_seeded`). Um POST contra o Motion Design ou contra uma
   sub-etapa real do VFX estragava a bancada para a T6 e para as fases 1–3. Por isso nenhum POST do teste aponta para
   um funil ou etapa reais: só para funis `P2T · ` criados pelo próprio teste, e o helper de POST recusa outro
   `funnel_id`. A limpeza (T1) nunca apaga um funil com `service_code` nem com negócios. Testes:
   `pipe2_screens_test::bench_untouched` (T5), que tem de passar logo na corrida vermelha, e o caso da guarda da
   limpeza em `pipe2_lib_test` (T1).

---

## Decisões (tomadas aqui; o resto do plano segue-as)

**D1 — Código do serviço numa coluna nova, `funnels.service_code` (VARCHAR(8), `''` por defeito).** O
`xray_kind` não se toca até à conversão: a v1 lê-o (`flow_payment_default`, `xsell_is_recurring`, X-Ray, CSV nos
combinados), e `'vfx'` na v1 seria ambíguo com `'vfx'` na v2. O nome evita `code`, que se confunde com `deals.code`.
A semente dá os códigos assim:
- `md`, `cmp` e `ed` vão para as linhas novas;
- `vfx`, `3d`, `adv` e `cam` vão para os funis base (`is_base = 1`) cujo `xray_kind` é exatamente `vfx`, `cgi`,
  `adverts` e `campanhas` (o primeiro por `position, id`), mas só se nenhum funil tiver já esse código;
- Ads, Buddy, os combinados e os funis da equipa ficam com `''`.

Nenhum código v1 lê esta coluna, por isso a v1 não muda.

**D2 — As etapas comuns novas dos funis ativos só se criam na conversão, mas as sub-etapas semeiam-se já.**
- *Funis novos* (MD, Composit, Edição): levam já a estrutura v2 completa, isto é, as 7 etapas de negócio (as 6 comuns
  e Concluído) e as 6 sub-etapas.
- *Funis ativos* (`vfx`, `cgi`, `adverts`, `campanhas`): na fase 0 recebem só as 6 sub-etapas (`level 'front'`), além
  do código e do departamento. **Em execução** (`role 'exec'`) e **Entrega e pagamento final** (`role 'delivery'`)
  são `level 'deal'`, e o filtro de nível não as esconde. Se as acrescentássemos já, mudavam `flow_order`, o gate
  «uma etapa de cada vez», `deal_steps`, as colunas do Pipeline, a «última etapa de produção» de
  `xsell_close_missing`, a lista de `funis.php` e o `MAX(position)` do `add_stage`. Por isso criam-se na conversão, na
  mesma transação que retira as etapas de produção v1.
- *Porque se semeiam já as sub-etapas:* são o destino do mapa «etapa v1 → sub-etapa» de «Rever migração» (fase 3) e da
  pré-visualização das Definições (fase 2). O filtro de nível esconde-as em todos os leitores da v1.

**D3 — Os funis só da v2 levam a marca `funnels.v2_only = 1` e escondem-se enquanto `pipe2_on()` for falso.**
`is_active = 0` não chega, porque `funis.php`, `negocios.php` e `relatorios.php` (que também alimenta
`report_tabs.php`) listam todos os funis. Um funil inativo da v1 (por exemplo, Ads depois da conversão) tem de
continuar visível, por isso usa-se uma marca própria, e não o estado. Em `funis.php`, um funil escondido trata-se como
inexistente: no `?f=`, em todos os POST (sem `toggle`, portanto não se ativa na v1) e nos `from[]` do `create`.
`pipeline.php?f=` já volta ao primeiro funil ativo quando o funil não está em `funnels_active`, e só se acrescenta um
teste a isso. Os mapas id → nome (`dashboard.php:253`, `exec.php:858`, `metrics.php:972/1285`,
`metrics_view.php:138`, `budget_view.php:167`, `xsell_report`) ficam sem filtro: só resolvem nomes de negócios, e
nenhum negócio aponta para os funis novos.

**D4 — Os leitores e escritores filtrados são estes, e não há outros:**

| Onde | O quê | Filtro |
|---|---|---|
| `admin/inc/pipeline.php` `funnel_stages()` | leitor central | `AND pipe2_stage_cond($pdo)`. Por herança chega a `flow_order` (`stage_match`, `flow_stage_with`, `flow_on_event`, `flow_gate_check`, `flow_stages_html`, `flow_stepper_html`, `xsell_close_missing`, `xsell_lane_steps_html`, `xsell_shared_rules`, `xsell_line_start`, `xsell_close`, envio da proposta, `create`/`rule_all` de `funis.php`), a `deal_funnel_prod_stages` (`deal_steps`, `deal_step_set`, `deal_step_label`), às colunas, «Mover» e «Mudar de funil» de `pipeline.php`, à lista e ao `move_stage` de `funis.php`, a `negocio.php:258` e a `funnels_combined_stages` |
| `admin/inc/deals.php` `funnel_fixed_stage()` | SQL direto | `AND pipe2_stage_cond($pdo)` |
| `admin/inc/pipeline_flow.php` `flow_tick` | JOIN direto | extrair `flow_tick_rules(PDO): array` com `AND pipe2_stage_cond($pdo, 's')` |
| `admin/funis.php` `add_stage` | `MAX(position)` direto | `AND pipe2_stage_cond($pdo)` |
| `admin/inc/pipeline.php` `deal_move`, `deal_change_funnel` (etapa variável) | escrita | recusar `!pipe2_stage_live($to)` |
| `admin/inc/xsell.php` `xsell_line_move` | escrita (também a de `ui_api.php` e `deal_flow.php`) | recusar `!pipe2_stage_live($to)` |
| `admin/funis.php` ações de etapa (`save_stage`, `move_stage`, `del_stage`, `rule_*`) | escrita | «Etapa não encontrada.» se `!pipe2_stage_live($s)` |
| `admin/funis.php` `$all`, `?f=`, POST, `create from[]` e modelo `is_base` | funis | `pipe2_funnel_cond` / `pipe2_funnel_hidden` |
| `admin/negocios.php:104`, `admin/relatorios.php:26` | funis | `WHERE pipe2_funnel_cond($pdo)` |

Ficam sem filtro: `stage_get`, `mx_stages`, `stage_row_fresh`, `fin_cash.php:398`, `budget.php:638`, `alerts.php`,
`interactions.php`, `export.php`, `contacts.php:498`, `undo.php:438` e todos os JOIN por `stage_id`/`funnel_id`. São
leitores por id e o histórico precisa deles.

**D5 — A guarda de esquema é `pipe2_schema_ready(PDO)`, ou seja, `schema_version >= 43`.** A cache é por ligação
(`spl_object_id`) e só guarda o `true`. Segue o padrão já usado em `layout.php:219`, `files_reorg.php:36` e
`cc.php:74`. Enquanto a base não estiver pronta, os fragmentos devolvem `'1=1'`, e um leitor publicado antes da
migração continua a funcionar como na v1. É isto que permite publicar todos os leitores e ecrãs **antes** da
migração (ordem nas Global Constraints). `pipe2_stage_live` e `pipe2_funnel_hidden` não dependem da versão: leem a
própria linha (`level`, `retired_at`, `v2_only`). Numa base na 42 essas chaves faltam, e os dois dão o resultado da v1.

Há uma janela que se aceita. No pedido que migra, a semente faz commit uns milissegundos antes de o `run_migrations`
gravar `schema_version = 43`. Um pedido que já ia a meio pode, nesse instante, desenhar as sub-etapas, porque os
fragmentos ainda dão `'1=1'`. Não passa nenhuma escrita: as guardas aplicam `pipe2_stage_live` à linha lida por
`stage_get`. Os pedidos que chegam durante a migração ficam à espera do GET_LOCK do passo 43.

**D6 — A semente é atómica sem depender do motor da tabela `settings`.** Ordem:
1. Se `pipe2_seeded` existe, sai.
2. Abre a transação e cria os dados, cada operação idempotente por código.
3. Por último, ainda dentro da transação, faz `INSERT IGNORE` de `pipe2_seeded`. Se `rowCount() !== 1`, faz rollBack
   e devolve `false`.
4. Faz commit.

Assim, uma falha nunca deixa a bandeira gravada, mesmo que a `settings` da produção não seja InnoDB (na bancada é). O
GET_LOCK, com nome `'topper_pipe2_' . DATABASE()` e 30 s, protege o DDL e a semente contra pedidos simultâneos. Se o
lock falhar, o passo **lança uma exceção**: um `return` deixaria o `run_migrations` marcar 43 sem esquema. O
`RELEASE_LOCK` fica num `finally`. Na fase 0 não se criam regras de automação: as regras de dois níveis são da fase 1,
e uma regra ativa numa sub-etapa correria no `flow_tick` da v1.

**D7 — Estados e códigos novos são constantes em `pipe2.php` (`PIPE2_CODES`), e o teste de larguras percorre-as.**
Os estados da frente são códigos novos, diferentes de `planned`, `waiting`, `active` e `done` (`XSELL_STATUS`).
`planned` continua a ser o «serviço do negócio» antes do Kickoff. As fases seguintes acrescentam códigos a
`PIPE2_CODES`, e o mesmo teste verifica-os.

**D8 — Os testes usam bases privadas (`topper_p2_<pid>_<n>`) restauradas de `tests/.pipe2/base42.sql`.** Esse ficheiro
e a fotografia `base42.json` são gerados **antes** de alguém subir o `SCHEMA_VERSION` (T1), e o diretório fica fora do
Git. Migrar e fotografar faz-se sempre num subprocesso, porque `flow_order`, `deal_funnel_prod_stages`, `settings` e
`xsell_is_multi` têm cache estática. A bancada `topper` migra para 43 assim que a T3 corre o primeiro teste, o que é
esperado. A partir daí o «antes» só existe no dump. Entre a T3 e o fim da T5, os ecrãs da v1 na bancada mostram as
sub-etapas (até à T4) e os funis só da v2 (até à T5), porque os leitores ainda são os antigos. É o estado que a ordem
de publicação evita na produção. Nesse
intervalo ninguém corre testes UI (a bateria inteira só corre na T6), e nenhum teste escreve em funis ou etapas reais
da bancada (Review Focus 6).

---

## Esquema (passo 43)

Todas as colunas vão com `db_add_column` (definição MySQL / SQLite) e todas as tabelas com `db_create` (corpo MySQL,
traduzido por `sqlite_ddl`). Não há FK nem UNIQUE. O índice novo é verificado em `information_schema.statistics`,
como em `migrate_pipeline.php:339`.

| Tabela.coluna | MySQL | Para quê (spec) |
|---|---|---|
| `funnels.service_code` | `VARCHAR(8) NOT NULL DEFAULT ''` | código fixo `vfx 3d md cmp ed adv cam` (§34.11 Funis) |
| `funnels.v2_only` | `TINYINT(1) NOT NULL DEFAULT 0` | funil só da v2, escondido da v1 (D3) |
| `funnels.department_id` | `INT UNSIGNED NULL` | chefe do serviço (§34.11 M7), sem FK |
| `funnels.auto_advance` | `TINYINT(1) NOT NULL DEFAULT 1` | interruptor do serviço (§34.9) |
| `funnel_stages.level` | `VARCHAR(8) NOT NULL DEFAULT 'deal'` | `'deal'` ou `'front'` (§34.11 Modelo) |
| `funnel_stages.retired_at` | `DATETIME NULL` | etapa v1 retirada (§34.11) |
| `funnel_stages.auto_advance` | `TINYINT(1) NOT NULL DEFAULT 1` | «avançar automaticamente» da sub-etapa (§34.4) |
| `funnel_stages.plan_days` | `SMALLINT NULL` | dias previstos por defeito (§34.9 progresso e replaneamento) |
| `funnel_stages.unit_label` | `VARCHAR(20) NOT NULL DEFAULT ''` | unidades da sub-etapa, por exemplo «shots» (§34.9) |
| `deal_lines.billing` | `VARCHAR(8) NOT NULL DEFAULT ''` | `'monthly'` = avença (§34.11 M7) |
| `deal_lines.monthly_fee` | `DECIMAL(12,2) NULL` | mensalidade (D4 dos sócios) |
| `deal_lines.fee_months` | `SMALLINT NULL` | meses contratados |
| `deal_lines.billing_end` | `DATE NULL` | fim do período contratado |
| `deal_lines.cancelled_at` | `DATETIME NULL` | avença cancelada |
| `deal_lines.cycle_ym` | `VARCHAR(7) NOT NULL DEFAULT ''` | ciclo atual (`AAAA-MM`) |
| `deal_lines.merged_into_id` | `INT UNSIGNED NULL` | frente absorvida (§34.11 M5) |
| `deal_lines.exec_mode` | `VARCHAR(8) NOT NULL DEFAULT ''` | `internal` ou `external` (nota 3) |
| `deal_lines.auto_advance` | `TINYINT(1) NULL` | interruptor da frente; NULL herda |
| `deals.service_codes` | `VARCHAR(40) NOT NULL DEFAULT ''` | lista de códigos da lead ou do negócio (decisão de 5 nov) |
| `deals.category` | `VARCHAR(8) NOT NULL DEFAULT ''` | categoria gravada no Kickoff ou na conversão (X3) |
| `deals.category_at` | `DATETIME NULL` | quando se gravou |
| `deals.billing` | `VARCHAR(8) NOT NULL DEFAULT ''` | avença no negócio antes do Kickoff (M7) |
| `tasks.dedup_key` | `VARCHAR(64) NOT NULL DEFAULT ''` | chave de deduplicação (§34.9, T2) |
| `tasks.cycle_ym` | `VARCHAR(7) NOT NULL DEFAULT ''` | ciclo da tarefa |
| índice `tasks.idx_task_dedup` | `(deal_id, dedup_key)`, não UNIQUE | procura da deduplicação |
| `deal_rounds.line_id` | `INT UNSIGNED NULL` | rondas por frente (C2) |
| `deal_rounds.cycle_ym` | `VARCHAR(7) NOT NULL DEFAULT ''` | rondas por ciclo |
| `cc_rv_rounds.line_id` | `INT UNSIGNED NULL` | rondas da Review por frente (C2) |
| `fin_docs.task_id` | `INT UNSIGNED NULL` | o pedido guarda a tarefa (T3) |
| `costs.line_id` | `INT UNSIGNED NULL` | margem por frente (X3) |

Tabelas novas (`id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` em todas):

- **`deal_line_steps`** (frente × sub-etapa: prazo, unidades e suspensão, §34.9): `deal_id`, `line_id` e `stage_id`
  `INT UNSIGNED NOT NULL`; `cycle_ym VARCHAR(7) NOT NULL DEFAULT ''`; `plan_days SMALLINT NULL`;
  `units_plan SMALLINT NULL`; `units_done SMALLINT NOT NULL DEFAULT 0`; `plan_start DATE NULL`; `plan_end DATE NULL`;
  `replanned_days SMALLINT NOT NULL DEFAULT 0`; `started_at`, `done_at` e `auto_hold_at` `DATETIME NULL`;
  `auto_hold_why VARCHAR(8) NOT NULL DEFAULT ''`; `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`. Índices:
  `INDEX idx_dls_line (line_id, stage_id)` e `INDEX idx_dls_deal (deal_id)`.
- **`deal_line_execs`** (vários executores por frente, tabela-filha, M5): `deal_id` e `line_id`
  `INT UNSIGNED NOT NULL`; `user_id`, `contact_id`, `budget_line_id` e `created_by` `INT UNSIGNED NULL`;
  `position INT NOT NULL DEFAULT 0`; `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`. Índices:
  `INDEX idx_dle_line (line_id)` e `INDEX idx_dle_contact (contact_id)`.
- **`deal_line_deps`** (ligações de um negócio, §34.4/§34.9): `deal_id`, `from_line_id`, `from_stage_id`, `to_line_id`
  e `to_stage_id` `INT UNSIGNED NOT NULL`; `source VARCHAR(8) NOT NULL DEFAULT 'model'`; `met_at` e `removed_at`
  `DATETIME NULL`; `removed_by` e `created_by` `INT UNSIGNED NULL`;
  `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`. Índices: `INDEX idx_dld_deal (deal_id)` e
  `INDEX idx_dld_to (to_line_id)`.
- **`pipe2_dep_models`** (modelos por categoria, §34.9): `category VARCHAR(8) NOT NULL`; `from_stage_id` e
  `to_stage_id` `INT UNSIGNED NOT NULL`; `position INT NOT NULL DEFAULT 0`; `is_active TINYINT(1) NOT NULL DEFAULT 1`;
  `updated_at DATETIME NULL`; `updated_by INT UNSIGNED NULL`. Índice: `INDEX idx_p2dm_cat (category)`.
- **`pipe2_categories`** (regras editáveis, §34.2): `code VARCHAR(8) NOT NULL`; `name VARCHAR(60) NOT NULL`;
  `position INT NOT NULL DEFAULT 0`; `need_all`, `need_any` e `exclude_any` `VARCHAR(40) NOT NULL DEFAULT ''` (CSV de
  códigos); `need_min TINYINT NOT NULL DEFAULT 0`; `is_active TINYINT(1) NOT NULL DEFAULT 1`; `updated_at DATETIME
  NULL`; `updated_by INT UNSIGNED NULL`. Regra: a categoria aplica-se quando estão todos os `need_all`, há pelo menos
  `need_min` dos `need_any` e nenhum dos `exclude_any`. Avalia-se por `position` e ganha a primeira (fase 1).
- **`pipe2_convert_batches`** (lotes da conversão, fase 3): `status VARCHAR(10) NOT NULL DEFAULT 'review'`;
  `backup_key VARCHAR(255) NOT NULL DEFAULT ''`; `note TEXT NULL`; `created_by INT UNSIGNED NULL`;
  `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`; `applied_at` e `reverted_at` `DATETIME NULL`.
- **`pipe2_convert_log`** (registo linha a linha para «Reverter», V1): `batch_id INT UNSIGNED NOT NULL`;
  `op VARCHAR(8) NOT NULL DEFAULT 'update'`; `tbl VARCHAR(40) NOT NULL`; `row_id INT UNSIGNED NOT NULL`;
  `col VARCHAR(40) NOT NULL DEFAULT ''`; `old_val TEXT NULL`; `new_val TEXT NULL`;
  `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`. Índices: `INDEX idx_p2cl_batch (batch_id)` e
  `INDEX idx_p2cl_row (tbl, row_id)`.
- **`task_deletions`** (apagamentos humanos registados antes do DELETE, T6): `task_id INT UNSIGNED NOT NULL`;
  `deal_id`, `line_id`, `stage_id`, `auto_rule_id` e `deleted_by` `INT UNSIGNED NULL`;
  `title VARCHAR(200) NOT NULL DEFAULT ''`; `task_created_at DATETIME NULL`;
  `deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`. Índice: `INDEX idx_td_rule (auto_rule_id)`.

Nomes escolhidos para evitar palavras reservadas e ambiguidades: `cycle_ym` em vez de `cycle`, `fee_months` em vez de
`months`, `tbl`/`col`/`old_val`/`new_val` e `need_*`. As fases seguintes podem acrescentar colunas num passo 44+, mas
nunca mudam estas.

## Semente (uma vez, numa transação)

Constantes em `migrate_pipeline2.php` (valores da §34.3/§34.9, copiar à letra):

- `PIPE2_SEED_DEAL_STAGES`: as 7 etapas de negócio dos funis **novos**, no formato `[nome, kind, posição, role]`:
  `['X-Ray','xray',10,'']`, `['Proposta','commercial',20,'proposal']`, `['Aguardar feedback','commercial',21,'feedback']`,
  `['Kickoff','kickoff',50,'']`, `['Em execução','production',70,'exec']`,
  `['Entrega e pagamento final','production',80,'delivery']`, `['Concluído','done',90,'']`.
- `PIPE2_SEED_FRONTS`: as sub-etapas por código, com `kind 'production'`, `level 'front'`, posições 110, 120, …, 160,
  e `rounds_included` só em Revisões. As duas últimas levam `role 'revisions'` e `'ready'`:
  - vfx: Turnover e breakdown · Look dev / shot de referência · Produção de shots · Finais e QC · Revisões (2) · Pronto
  - 3d: Styleframes · Modelação e lookdev · Animação · Lighting e render · Revisões (1) · Pronto
  - md: Guião visual e styleframes · Animatic · Animação · Som e ritmo · Revisões (1) · Pronto
  - cmp: Preparação de plates · Composição · Integração e look · Finais e QC · Revisões (1) · Pronto
  - ed: Seleção e assembly · Montagem offline · Picture lock · Finalização (cor, som, legendas) · Revisões (2) · Pronto
  - adv: Conceito e tratamento · Pré-produção (PPM) · Rodagem · Montagem e pós · Revisões (2) · Pronto
  - cam: Estratégia e calendário · Guiões do lote · Gravação do lote · Edição do lote · Revisões (1) · Pronto
- `PIPE2_SEED_DEPTS`: `vfx`, `3d`, `md`, `cmp` e `ed` → `'Pós-Produção e VFX'`; `adv` → `'Adverts'`; `cam` →
  `'Campanhas'`. É a procura exata pelo nome em `departments` (o primeiro por id) e só preenche `department_id` se
  ainda for NULL. Se o departamento não existir, fica NULL. Nunca se criam departamentos.
- `PIPE2_SEED_CATEGORIES`, no formato `[code, name, position, need_all, need_any, need_min, exclude_any]`:
  `['postprod','Pós-produção',1,'ed','vfx,3d,md,cmp',1,'']` e `['cgi','CGI',2,'','vfx,3d,cmp',2,'ed']`.
- `PIPE2_SEED_DEP_MODELS`, no formato `[categoria, svc de, sub-etapa de, svc para, sub-etapa para]`, por esta ordem
  (`position` 1, 2, …):
  - postprod: `3d` Lighting e render → `cmp` Preparação de plates; `vfx` Finais e QC → `cmp` Integração e look; `cmp`
    Composição → `ed` Picture lock; `md` Revisões → `ed` Picture lock.
  - cgi: `3d` Modelação e lookdev → `vfx` Look dev / shot de referência; `3d` Lighting e render → `cmp` Preparação de
    plates; `vfx` Finais e QC → `cmp` Integração e look.

  Os ids resolvem-se pela sub-etapa `level 'front'` com esse nome no funil desse `service_code`. Uma ligação sem um
  dos lados (serviço sem funil) salta-se.

Operações, por ordem, todas idempotentes por código:
1. **Funis novos.** Para `md`, `cmp` e `ed`, se não houver nenhum funil com esse `service_code`, faz-se INSERT com
   nome `Motion Design`, `Composit` ou `Edição`, `is_base 1`, `is_active 0`, `v2_only 1`, `xray_kind ''`,
   `position = MAX(position) + 1` e as 7 etapas de negócio.
2. **Códigos dos funis base.** Para `vfx`, `3d`, `adv` e `cam` (D1), UPDATE de `service_code`.
3. **Sub-etapas.** Cada funil com código que não tenha nenhuma etapa `level 'front'` recebe as 6 sub-etapas.
4. **Departamentos.**
5. **Categorias**, quando o `code` ainda não existe.
6. **Modelos de dependências**, quando não existe nenhuma linha com o mesmo `(category, from_stage_id, to_stage_id)`.
7. **Bandeira.** `INSERT IGNORE` de `('pipe2_seeded', data e hora)` (D6).

---

## Ficheiros

| Ficheiro | Tarefa | Responsabilidade |
|---|---|---|
| `tests/pipe2_lib.php` (novo) | T1 | base de testes do Pipeline: bases privadas, subprocessos, diff, papéis, criar negócio, frentes e tarefas, limpeza |
| `tests/pipe2_v1_snap.php` (novo) | T1 | fotografia v1 (PHP e HTTP), linha de base v42 e migração de uma base privada |
| `tests/pipe2_lib_test.php` (novo) | T1 | testa a base de testes |
| `.gitignore` | T1 | `tests/.pipe2/` (dump e fotografia ficam locais) |
| `admin/inc/pipe2.php` (novo) | T2 | bandeira, serviços, códigos, fragmentos SQL e predicados |
| `tests/pipe2_core_test.php` (novo) | T2 | testa o `pipe2.php` |
| `admin/inc/migrate_pipeline2.php` (novo) | T3 | passo 43: lock, DDL e semente |
| `admin/inc/migrate.php` | T3 | `SCHEMA_VERSION = 43` e chamada ao passo |
| `tests/cc_media_kind_test.php` | T3 | a linha 128 passa a `>= 42` |
| `tests/pipe2_schema_test.php`, `tests/pipe2_codes_test.php`, `tests/pipe2_model_test.php`, `tests/pipe2_run43.php` (novos) | T3 | F2, F3, M1, M5, M6, M7 |
| `admin/inc/pipeline.php`, `admin/inc/deals.php`, `admin/inc/pipeline_flow.php`, `admin/inc/xsell.php` | T4 | filtros e guardas da biblioteca |
| `tests/pipe2_filters_test.php` (novo) | T4 | M2 |
| `admin/funis.php`, `admin/negocios.php`, `admin/relatorios.php` | T5 | funis escondidos e guardas dos ecrãs |
| `tests/pipe2_screens_test.php` (novo) | T5 | HTTP |
| `tests/pipe2_v1_unchanged_test.php` (novo), `docs/ESPECIFICACAO-PLATAFORMA.md` (§10) | T6 | F1 e registo do que se fez |
| `tests/pipe2_rollback_test.php` (novo) | revisão da fase 0 | reversão do código depois do passo 43 (Global Constraints, «Reversão») |

**Ordem:** T1 ∥ T2 → T3 → T4 → T5 → T6.
- A T1 tem de acabar antes de alguém tocar no `SCHEMA_VERSION`.
- A T5 espera pela T4 porque os testes HTTP usam o servidor da bancada, que serve esta mesma cópia: uma
  `inc/pipeline.php` a meio de edição daria falhas falsas.
- Em worktrees, com servidor próprio, a T4 e a T5 podiam correr em paralelo.

---

### Task 1: Base de testes do Pipeline e linha de base v42

**Files:**
- Create: `tests/pipe2_lib.php`, `tests/pipe2_v1_snap.php`, `tests/pipe2_lib_test.php`
- Modify: `.gitignore` (acrescentar `tests/.pipe2/`)
- Gera (fora do Git): `tests/.pipe2/base42.sql`, `tests/.pipe2/base42.json`

**Interfaces:**
- Consumes: `tests/boot.php`, `tests/lib.php` e as funções v1 (`funnels_active`, `funnel_stages`, `flow_order`,
  `commercial_stages`, `funnel_fixed_stage`, `flow_stage_with`, `stage_match`, `deal_funnel_prod_stages`, `deal_steps`,
  `flow_rules`, `xray_questions`, `flow_payment_default`, `flow_index`, `xsell_is_multi`, `xsell_lines`,
  `xsell_close_missing`, `alert_limits`, `perm_grid`, `funnel_create`, `run_migrations`, `settings`).
- Produces (`tests/pipe2_lib.php`, incluir depois de `boot.php`):
  ```php
  const PIPE2_T = 'P2T';                                  // prefixo de todos os dados de teste
  function pipe2_dir(): string;                           // getenv('PIPE2_BASE_DIR') ?: <raiz>/tests/.pipe2 (cria)
  function pipe2_base_dump(): string;                     // caminho de base42.sql; sem ele: FALHA com a instrução e exit(1)
  function pipe2_base_json(): array;                      // base42.json decodificado; idem
  function pipe2_lock(PDO $pdo): void;                    // GET_LOCK('topper_pipe2_tests', 300); lança se não obtiver
  function pipe2_tmpdb_create(string $dump): string;      // 'topper_p2_<pid>_<n>', utf8mb4_unicode_ci, carregada com `mariadb <db> < dump`; DROP no shutdown
  function pipe2_tmpdb_pdo(string $db): PDO;              // root pelo socket /run/mysqld/mysqld.sock, sem palavra-passe, atributos iguais aos do bootstrap
  function pipe2_tmpdb_drop(string $db): void;
  function pipe2_use(PDO $pdo): void;                     // $GLOBALS['pdo'] = $pdo; settings($pdo, true)
  function pipe2_sql_mode(PDO $pdo, string $mode): void;  // SET SESSION sql_mode = ?
  function pipe2_sub(string $script, array $args = []): array;     // php <raiz>/tests/<script> <args>; ['code'=>int,'out'=>string,'err'=>string,'json'=>mixed]
  function pipe2_sub_parallel(array $runs): array;        // [[script, args], …] lançados juntos (proc_open); resultados pela mesma ordem
  function pipe2_diff($a, $b, string $path = ''): array;  // ["caminho.a.b: <json a> ≠ <json b>", …]; chave em falta = «(falta)»; [] = iguais
  function pipe2_users(PDO $pdo): array;                  // ['admin'=>1,'comercial'=>99,'leitor'=>3], confirmado com perm_grid; lança se a bancada não bater certo
  function pipe2_funnel(PDO $pdo, string $label, bool $active = false): int;   // funnel_create('P2T · '.$label); is_active conforme
  function pipe2_deal(PDO $pdo, array $o = []): int;      // INSERT direto, sem efeitos laterais; code 'P2T'+13 hex (16), name 'P2T · …'; opções phase, funnel_id, stage_id, owner_id, exec_owner_id, value_estimate, budget, delivery_date, prod_step, sync_mode (+ colunas v2 só se existirem)
  function pipe2_front(PDO $pdo, int $dealId, int $funnelId, array $o = []): int;   // INSERT em deal_lines (is_main, position, stage_id, status, exec_owner_id, delivery_date, value, created_at); $GLOBALS['XSELL_FRESH'] = true
  function pipe2_task(PDO $pdo, int $dealId, array $o = []): int;  // INSERT em tasks (title 'P2T · …', status 'todo', stage_id, line_id, auto_rule_id, is_gate, doc_kind, kind, assignee_id, due_date)
  function pipe2_cleanup(PDO $pdo): void;                 // regras em «Limpeza», logo abaixo; corre no shutdown e à mão
  ```
- **Limpeza** (`pipe2_cleanup`, normativo):
  1. Apaga por id o que os helpers registaram nessa ligação.
  2. Apaga as sobras com `code`, `name` ou `title` `LIKE 'P2T%'` em `tasks`, `deal_lines`, `stage_events`,
     `deal_rounds` e `deals`.
  3. Apaga os funis com `name LIKE 'P2T · %'` (o prefixo exato, com o ponto do meio e o espaço). As etapas e as regras
     vão por CASCADE (`fk_stage_funnel`, `stage_automations.stage_id`).
  4. Em todos os passos, mesmo nos ids registados, **nunca** apaga um funil com `is_base = 1`, um com
     `service_code <> ''` (quando a coluna existe), nem um que ainda tenha negócios depois do passo 2. Os helpers
     criam sempre funis com `is_base 0` (`funnel_create`). Também nunca apaga etapas de funis que não sejam `P2T · `.

  Os funis da bancada (os 6 da v1 e os 3 da semente) são todos `is_base 1`. Se um teste renomear um deles por engano
  para «P2T · …», o funil fica na base e o teste que o renomeou falha (`::bench_untouched` na T5). Um nome «P2T» sem
  o ponto do meio nem sequer é apanhado. Assim o CASCADE nunca apaga as etapas de um funil real.
- Produces (`tests/pipe2_v1_snap.php`, CLI):
  - `--baseline [--force]`: recusa se `settings.schema_version` não for 42 ou se `SCHEMA_VERSION !== 42`. Faz o
    `mariadb-dump --single-transaction --skip-lock-tables topper` → `base42.sql`, cria uma base privada a partir do
    dump, corre `--php --db=<privada>` num subprocesso, corre `--http` e grava
    `base42.json = {meta:{schema_version, taken_at, commit}, php:{…}, http:{…}}`.
  - `--php [--db=X]`: imprime `{meta:{schema_version}, php:{…}}`. Tudo dentro de uma transação desfeita no fim, só
    leitura.
  - `--http`: imprime `{http:{…}}` da bancada viva.
  - `--migrate --db=X`: se `schema_version < SCHEMA_VERSION`, corre `run_migrations` na base X. Imprime
    `{"from":n,"to":m}`.
  - Chaves de `php` (normativas):
    - `constants` (`LEAD_SERVICES_LIST`, `STAGE_KINDS`, `STAGE_ZONE`, chaves de `FLOW_TRIGGERS` e de `XSELL_STATUS`);
    - `funnels_active` (`[id, name, position, xray_kind]`);
    - `funnel_list`, ou seja `SELECT id, name FROM funnels WHERE <pipe2_funnel_cond se existir, senão 1=1> ORDER BY
      position, name`;
    - `funnels.<id>`, para cada funil ativo:
      - `stages`: linhas de `funnel_stages()` projetadas em `id, name, kind, position, role, gate_on,
        rounds_included, max_days`;
      - `flow_order`, `commercial` e `prod`: ids;
      - `fixed`: xray, kickoff e done;
      - `with`: proposal, feedback, xray, kickoff e done;
      - `xray_q`;
      - `pay_default` = `flow_payment_default($pdo, 1000.0, id)`;
      - `rules`: por etapa, os ids de `flow_rules(…, true)`;
      - `match`: por etapa e por cada outro funil ativo, o id de `stage_match` ou null;
    - `tick_rules`: ids de `flow_tick_rules()` se existir, senão do SELECT v1 do `flow_tick`;
    - `deals.<id>`, para `code IS NOT NULL` e fase pipeline, production, done ou lost:
      - `steps` = `deal_steps($pdo, linha)`;
      - `idx` = `flow_index(flow_order(funil), stage_id)`;
      - `multi`;
      - `close_missing`;
      - `lines` (`[id, funnel_id, is_main, stage_id, status]`);
    - `settings` (`alert_limits($pdo)['deal']`, `deal_stale_days`, `prob_*`, `pay_terms_*`);
    - `xsell` (ids de pacotes e de regras ativos).
  - Chaves de `http`, extraídas por expressão regular e normalizadas (sem CSRF, contagens, euros nem datas):
    - `u1`, `u99` e `u3`: `{pipeline_tabs:[ids], pipeline_cols:{fid:[data-stage ids das <section class="col">]}}`;
    - `admin`, para o uid 1:
      - `funis_tabs`: `[id, muted]` de `funis.php`;
      - `funis_blocks`: os `id="st-N"` de cada `funis.php?f=<id>`;
      - `negocios_funil`: as opções de `<select name="funil">`;
      - `relatorios_fu` e `relatorios_sv`: as opções dos respetivos seletores;
      - `metas_gi`: o `<select name="gi">` de `relatorios.php?tab=metas&gs=funnel`;
      - `leads_services`: os textos de `#nl-se`;
    - `u3_funis`: o código HTTP de `funis.php` para o uid 3.

- [ ] **Step 0: Confirmar a bancada.** Correr `curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8765/admin/` e
  `mariadb topper -N -e "SELECT svalue FROM settings WHERE skey='schema_version'"`. Esperado: 302 ou 200, e `42`. Se a
  bancada não responder, correr `bash /tmp/claude-0/-home-user-portfolio-website/07a7b6fb-2708-535b-b71f-5de7fa1226a8/scratchpad/bench.sh`
  e esperar. Se a versão não for 42, **parar e avisar o orquestrador**.
- [ ] **Step 1: Escrever o teste a falhar.** `tests/pipe2_lib_test.php` faz `boot.php` + `pipe2_lib.php` +
  `pipe2_lock`, e verifica:
  - `pipe2_users($pdo) === ['admin'=>1,'comercial'=>99,'leitor'=>3]`.
  - `pipe2_diff(['a'=>1,'b'=>[1,2]], ['a'=>1,'b'=>[1,3]]) === ['b.1: 2 ≠ 3']` e `pipe2_diff([1], [1]) === []`.
  - Com uma base privada `$db` de `pipe2_base_dump()`:
    - `db_has_table($tp,'deals')`;
    - depois de `pipe2_use($tp)`, `setting($tp,'schema_version') === '42'`, o que prova a cache refrescada;
    - `$GLOBALS['pdo'] === $tp`.
  - Na base privada, `pipe2_deal` (fase pipeline, funil 1, X-Ray) + `pipe2_front` ×2 + `pipe2_task`: as linhas existem,
    o `code` começa por `P2T` e `strlen ≤ 16`. Depois de `pipe2_cleanup($tp)` deixam de existir.
  - `pipe2_funnel($tp,'lib')`: `is_active 0` e 5 etapas. Desaparece com a limpeza.
  - Guarda da limpeza (Review Focus 6), numa 2.ª base privada do mesmo dump, para não mexer nos funis da primeira:
    - `UPDATE funnels SET name = 'P2T' WHERE id = 1`, depois `pipe2_cleanup($tp2)`: o funil 1 e as etapas dele
      continuam lá;
    - `pipe2_funnel($tp2,'guarda')` e um negócio que não é P2T nesse funil (INSERT direto, `code` e `name` sem o
      prefixo, fora dos helpers): depois da limpeza, o funil continua lá; apaga-se o negócio à mão, e a limpeza
      seguinte já apaga o funil;
    - `UPDATE funnels SET name = 'P2T · base' WHERE id = 2` (`is_base 1`): a limpeza deixa o funil 2 e as etapas
      dele;
    - só nesta base privada, `ALTER TABLE funnels ADD COLUMN service_code VARCHAR(8) NOT NULL DEFAULT ''` e um funil
      `pipe2_funnel($tp2,'sc')` com `service_code 'md'`: a limpeza deixa-o lá.
  - `pipe2_sub('pipe2_v1_snap.php', ['--php','--db='.$db])`: `code 0` e `count(json.php.funnels_active) === 6`.
  - `pipe2_sub_parallel` com duas cópias da mesma corrida devolve duas saídas iguais.
  - Com o lock seguro, uma 2.ª ligação faz `SELECT GET_LOCK('topper_pipe2_tests',0)` e obtém `0`.
- [ ] **Step 2: Correr e ver falhar.** `php tests/pipe2_lib_test.php`. Esperado: erro fatal, porque
  `tests/pipe2_lib.php` não existe.
- [ ] **Step 3: Implementar `tests/pipe2_lib.php` e `tests/pipe2_v1_snap.php`** (interfaces acima). Para o
  `pipe2_tmpdb_*` e o dump usar `shell_exec` (a bancada tem `exec`, como `cc_paste_test.php`). A fotografia PHP nunca
  escreve: abre uma transação no início e faz rollBack no fim. Acrescentar `tests/.pipe2/` ao `.gitignore`.
- [ ] **Step 4: Correr e ver passar.** `php tests/pipe2_lib_test.php`. Esperado: `OK n`.
- [ ] **Step 5: Gerar a linha de base.** `php tests/pipe2_v1_snap.php --baseline`. Depois confirmar `ls -la tests/.pipe2/`
  (os dois ficheiros) e `php -r '$j=json_decode(file_get_contents("tests/.pipe2/base42.json"),true); echo $j["meta"]["schema_version"], " ", count($j["http"]["u1"]["pipeline_cols"]["1"]), "\n";'`.
  Esperado: `42 11` (o VFX tem 11 colunas na bancada). Confirmar também que `git status --short` não mostra
  `tests/.pipe2`.
- [ ] **Step 6: Entregar** a lista de ficheiros. Sem commit.

### Task 2: `admin/inc/pipe2.php` — bandeira, serviços, códigos e filtros

**Files:**
- Create: `admin/inc/pipe2.php`, `tests/pipe2_core_test.php`

**Interfaces:**
- Consumes: nada da v2. Guarda `if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }` e não inclui nenhum
  ficheiro.
- Produces:
  ```php
  const PIPE2_SCHEMA = 43;
  const PIPE2_SERVICES = [   // ordem v2; v1 = xray_kind do funil base que mantém o id (null = funil novo)
      'vfx' => ['name' => 'VFX',           'token' => '--s-vfx', 'v1' => 'vfx'],
      '3d'  => ['name' => '3D',            'token' => '--s-3d',  'v1' => 'cgi'],
      'md'  => ['name' => 'Motion Design', 'token' => '--s-md',  'v1' => null],
      'cmp' => ['name' => 'Composit',      'token' => '--s-cmp', 'v1' => null],
      'ed'  => ['name' => 'Edição',        'token' => '--s-ed',  'v1' => null],
      'adv' => ['name' => 'Adverts',       'token' => '--s-adv', 'v1' => 'adverts'],
      'cam' => ['name' => 'Campanhas',     'token' => '--s-cam', 'v1' => 'campanhas'],
  ];
  const PIPE2_V1_MAP = ['vfx'=>'vfx','cgi'=>'3d','adverts'=>'adv','ads'=>'adv','campanhas'=>'cam','buddy'=>'cam'];  // §34.8
  const PIPE2_LEVELS = ['deal' => 'Negócio', 'front' => 'Frente'];
  const PIPE2_ROLES = ['exec' => 'Em execução', 'delivery' => 'Entrega e pagamento final', 'revisions' => 'Revisões', 'ready' => 'Pronto'];
  const PIPE2_LINE_STATUS = ['queued' => 'Por começar', 'running' => 'Em curso', 'depwait' => 'À espera', 'ready' => 'Pronto', 'ongoing' => 'Ativa', 'merged' => 'Juntada'];
  const PIPE2_TRIGGERS = ['f_enter' => 'A frente entra na sub-etapa', 'f_ready' => 'A frente fica Pronta', 'all_ready' => 'Todas as frentes Prontas',
                          'dep_met' => 'Dependência cumprida', 'f_stale' => 'Frente parada há N dias', 'rounds' => 'Rondas de revisão ultrapassadas',
                          'fee_day' => 'Avença mensal (dia N)'];
  const PIPE2_CODES = [      // coluna => códigos novos que lá se gravam (o teste de larguras percorre isto; as fases seguintes acrescentam)
      'funnels.service_code' => ['vfx','3d','md','cmp','ed','adv','cam'],  'funnel_stages.level' => ['deal','front'],
      'funnel_stages.role' => ['exec','delivery','revisions','ready'],     'deal_lines.status' => ['queued','running','depwait','ready','ongoing','merged'],
      'deal_lines.billing' => ['monthly'],  'deal_lines.exec_mode' => ['internal','external'],  'deal_lines.cycle_ym' => ['2026-11'],
      'deals.billing' => ['monthly'],  'deals.category' => ['postprod','cgi'],  'deals.service_codes' => ['vfx,3d,md,cmp,ed,adv,cam'],
      'stage_automations.trig' => ['f_enter','f_ready','all_ready','dep_met','f_stale','rounds','fee_day'],
      'deal_line_deps.source' => ['model','manual','convert'],  'deal_line_steps.auto_hold_why' => ['undo','convert'],
      'pipe2_dep_models.category' => ['postprod','cgi'],  'pipe2_categories.code' => ['postprod','cgi'],
      'pipe2_convert_batches.status' => ['review','applied','reverted','failed'],  'pipe2_convert_log.op' => ['update','insert'],
      'deal_history.event' => ['v2_convert'],  'metric_goals.scope' => ['service'],  'metric_goals.metric' => ['concluidos','valor_concluido'],
  ];
  function pipe2_on(PDO $pdo): bool;                            // SELECT direto de settings.pipeline_v2_at; trim !== ''; erro ou ausência → false; sem cache
  function pipe2_services(): array;                             // PIPE2_SERVICES
  function pipe2_schema_ready(PDO $pdo): bool;                  // schema_version >= PIPE2_SCHEMA; cache por spl_object_id($pdo), só do true
  function pipe2_stage_live(array $stage): bool;                // ($stage['level'] ?? 'deal') === 'deal' e retired_at null ou ''
  function pipe2_stage_cond(PDO $pdo, string $alias = ''): string;   // "{a.}level = 'deal' AND {a.}retired_at IS NULL", ou '1=1' se !ready
  function pipe2_funnel_cond(PDO $pdo, string $alias = ''): string;  // "{a.}v2_only = 0" se ready && !pipe2_on, senão '1=1'
  function pipe2_funnel_hidden(PDO $pdo, ?array $funnel): bool;      // $funnel && (int)($funnel['v2_only'] ?? 0) === 1 && !pipe2_on($pdo)
  ```
  `$alias` aceita `'s'` ou `'s.'`.

- [ ] **Step 1: Escrever o teste a falhar.** `tests/pipe2_core_test.php` corre na bancada `topper` (na 42 ou já na 43) e
  verifica:
  - `array_keys(pipe2_services()) === ['vfx','3d','md','cmp','ed','adv','cam']`, os nomes exatos acima, os tokens
    `'--s-'.código` (com `--s-3d`) e `v1` = `vfx`/`cgi`/`null`×3/`adverts`/`campanhas`.
  - `PIPE2_V1_MAP` exatamente como acima.
  - Coerência:
    - `PIPE2_CODES['funnels.service_code'] === array_keys(PIPE2_SERVICES)`;
    - `PIPE2_CODES['funnel_stages.role'] === array_keys(PIPE2_ROLES)`;
    - `PIPE2_CODES['deal_lines.status'] === array_keys(PIPE2_LINE_STATUS)`;
    - `PIPE2_CODES['stage_automations.trig'] === array_keys(PIPE2_TRIGGERS)`;
    - `deals.service_codes[0] === implode(',', array_keys(PIPE2_SERVICES))`.
  - Nenhum nome de serviço contém `Blaine`, `Buddy` nem `Ads` (o Blaine não é serviço, §34.1).
  - `pipe2_stage_live`:
    - dá `true` para `[]`, `['level'=>'deal','retired_at'=>null]` e `['level'=>'deal','retired_at'=>'']`;
    - dá `false` para `['level'=>'front']` e `['level'=>'deal','retired_at'=>'2026-11-06 00:00:00']`.
  - Sem `pipeline_v2_at` na bancada: `pipe2_on($pdo) === false`.
  - Dentro de `$pdo->beginTransaction()`:
    - com `INSERT INTO settings (skey, svalue) VALUES ('pipeline_v2_at','2026-11-06 10:00:00') ON DUPLICATE KEY
      UPDATE svalue = VALUES(svalue)`, `pipe2_on` passa a `true`;
    - com o valor `'  '`, volta a `false`;
    - com o valor de novo preenchido, `pipe2_funnel_hidden($pdo, ['v2_only'=>1]) === false` e
      `pipe2_funnel_cond($pdo,'f') === '1=1'`;
    - `rollBack()` e `pipe2_on` volta a `false`.
  - Com `$ready = (int)$pdo->query("SELECT svalue FROM settings WHERE skey='schema_version'")->fetchColumn() >= 43`:
    - `pipe2_schema_ready($pdo) === $ready`;
    - `pipe2_stage_cond($pdo,'s') === ($ready ? "s.level = 'deal' AND s.retired_at IS NULL" : '1=1')`;
    - `pipe2_stage_cond($pdo,'s.')` igual;
    - `pipe2_stage_cond($pdo) === ($ready ? "level = 'deal' AND retired_at IS NULL" : '1=1')`;
    - `pipe2_funnel_cond($pdo,'f') === ($ready ? 'f.v2_only = 0' : '1=1')`.
  - `pipe2_funnel_hidden`: `['v2_only'=>1]` dá `true`; `['v2_only'=>0]`, `[]` e `null` dão `false`.
- [ ] **Step 2: Correr e ver falhar.** `php tests/pipe2_core_test.php`. Esperado: erro fatal (falta
  `admin/inc/pipe2.php`). O teste faz `require_once __DIR__ . '/../admin/inc/pipe2.php'` explicitamente.
- [ ] **Step 3: Implementar `admin/inc/pipe2.php`** com o cabeçalho «topper. admin — Pipeline v2: bandeira, serviços
  e filtros da v1» e as interfaces acima. Nenhum ficheiro existente passa a incluí-lo nesta tarefa.
- [ ] **Step 4: Correr e ver passar.** `php tests/pipe2_core_test.php`. Esperado: `OK n`. Confirmar com `mariadb topper
  -N -e "SELECT COUNT(*) FROM settings WHERE skey='pipeline_v2_at'"` que o resultado é `0`.
- [ ] **Step 5: Entregar.** Sem commit.

### Task 3: Passo 43 — `migrate_pipeline2.php`, `SCHEMA_VERSION` 43 e testes do esquema

**Files:**
- Create: `admin/inc/migrate_pipeline2.php`, `tests/pipe2_schema_test.php`, `tests/pipe2_codes_test.php`,
  `tests/pipe2_model_test.php`, `tests/pipe2_run43.php`
- Modify: `admin/inc/migrate.php:16` (`SCHEMA_VERSION = 43`). A seguir a `migrate.php:354` (`migrate_cc_42($pdo);`)
  e antes do `if (db_is_sqlite($pdo))` do upsert, acrescentar
  `// ── 43 · Pipeline v2, fase 0: bandeira, serviços, sub-etapas e esquema (só acrescenta) ──`,
  `require_once __DIR__ . '/migrate_pipeline2.php';` e `migrate_pipeline2_43($pdo);`.
- Modify: `tests/cc_media_kind_test.php:128` → `t_true(SCHEMA_VERSION >= 42, 'SCHEMA_VERSION 42 ou mais');`

**Interfaces:**
- Consumes: T2 (`PIPE2_SERVICES`, `PIPE2_CODES`, `PIPE2_SCHEMA`); `db_add_column`/`db_create` (`migrate_platform.php`,
  incluído com `require_once` dentro do ficheiro); `db_has_column`/`db_has_table`/`db_is_sqlite` (`migrate.php`); T1
  (`pipe2_lib.php`, `base42.sql`).
- Produces:
  ```php
  function migrate_pipeline2_43(PDO $pdo): void;      // lock → schema → seed → RELEASE_LOCK no finally
  function migrate_pipeline2_lock(PDO $pdo): string;  // 'topper_pipe2_' . DATABASE(); GET_LOCK(…, 30) ≠ 1 → RuntimeException; '' em SQLite
  function migrate_pipeline2_schema(PDO $pdo): void;  // só DDL (tabela «Esquema»)
  function migrate_pipeline2_seed(PDO $pdo): bool;    // true = semeou agora; false = já estava (D6, secção «Semente»)
  // constantes PIPE2_SEED_DEAL_STAGES, PIPE2_SEED_FRONTS, PIPE2_SEED_DEPTS, PIPE2_SEED_CATEGORIES, PIPE2_SEED_DEP_MODELS
  ```
  `tests/pipe2_run43.php --db=X`: faz o boot, `pipe2_use(pipe2_tmpdb_pdo(X))` e `migrate_pipeline2_43`, e imprime
  `{"ok":true}`. Em erro imprime `{"ok":false,"error":"…"}` e sai com 1.

Todos os testes desta tarefa começam com `require boot.php`, `pipe2_lib.php`, `pipe2_lock($pdo)` e
`require_once …/admin/inc/migrate_pipeline2.php`. Trabalham sobre bases privadas novas de `pipe2_base_dump()`, ou seja,
no estado v42. «Fresca» quer dizer `pipe2_tmpdb_create` + `pipe2_tmpdb_pdo`. Na fresca, «migrar» quer dizer
`migrate_pipeline2_43($tp)`, salvo indicação em contrário.

- [ ] **Step 1: Escrever `tests/pipe2_schema_test.php` (casos com o nome em comentário `// ::caso`).**
  - `::columns` (M5, M7). Para cada linha da tabela «Esquema»: `COLUMN_TYPE` e `IS_NULLABLE` exatos e o
    `COLUMN_DEFAULT` normalizado com `trim($d, "'")` (o MariaDB devolve-o entre aspas). As 8 tabelas novas existem. O
    índice `idx_task_dedup` existe em `information_schema.statistics` e com `NON_UNIQUE = 1`.
  - `::existing_untouched`. Um retrato de `information_schema.columns` (tabela, coluna, tipo, nulo, default), tirado
    antes e depois de migrar, só difere nas colunas novas. Os índices existentes ficam iguais.
  - `::deals_untouched`. `COUNT(*)` e `SUM(CRC32(CONCAT_WS('|', …colunas v1…)))` de `deals`, `deal_lines`, `tasks`,
    `stage_automations`, `deal_history` e `stage_events` são iguais antes e depois.
  - `::new_funnels_inactive` (M6). Há exatamente um funil com `service_code` `md`, `cmp` e `ed`, com os nomes
    `Motion Design`, `Composit` e `Edição`, e com `is_active 0`, `v2_only 1`, `is_base 1` e `xray_kind ''`. As posições
    são `> MAX(position)` do dump e crescentes por esta ordem. As etapas `level 'deal'` correspondem exatamente a
    `PIPE2_SEED_DEAL_STAGES`.
  - `::kept_funnels` (M6). Os funis do dump com `xray_kind` `vfx`, `cgi`, `adverts` e `campanhas` têm `service_code`
    `vfx`, `3d`, `adv` e `cam` (os mesmos ids). `name`, `position`, `is_active`, `is_base`, `xray_kind` e as etapas
    `level 'deal'` ficam iguais ao dump. Ads e Buddy ficam com `service_code ''`. `COUNT(*)` de `funnels` com
    `is_active = 1` não muda.
  - `::substages`. Para cada código, o funil tem exatamente as sub-etapas de `PIPE2_SEED_FRONTS` pela ordem: nomes
    exatos, `kind 'production'`, `level 'front'`, posições 110–160, `rounds_included` 2 ou 1 só em Revisões, e
    `role 'revisions'`/`'ready'` nas duas últimas.
  - `::departments`. `department_id` dos códigos vfx/3d/md/cmp/ed é o id de «Pós-Produção e VFX»; adv → «Adverts»; cam
    → «Campanhas». Ads e Buddy ficam NULL.
  - `::categories_and_models`. As 2 linhas de `pipe2_categories` são iguais a `PIPE2_SEED_CATEGORIES`. As 7 linhas de
    `pipe2_dep_models`, lidas em junção com `funnel_stages`/`funnels` como `[cat, svc, nome, svc, nome]`, são iguais a
    `PIPE2_SEED_DEP_MODELS`.
  - `::no_rules_no_flag`. O `COUNT(*)` de `stage_automations` não muda. Não há regras em etapas de funis `v2_only` nem
    em sub-etapas. Não existe `settings.pipeline_v2_at`, e `pipe2_on($tp) === false`. `deal_stale_days` fica igual ao
    dump. `pipe2_seeded` existe.
  - `::idempotent` (F2). Uma 2.ª chamada a `migrate_pipeline2_43($tp)` deixa iguais o retrato de
    `information_schema.columns` e as assinaturas `COUNT(*)`/`SUM(CRC32(…))` de `funnels`, `funnel_stages`,
    `pipe2_categories` e `pipe2_dep_models`. Depois de `DELETE FROM settings WHERE skey='pipe2_seeded'`, mais uma
    chamada devolve as mesmas assinaturas (sem duplicados, por código) e volta a gravar a bandeira. Por fim, a base
    privada migrada com `run_migrations($tp, 42)` (subprocesso `pipe2_v1_snap.php --migrate`) fica com
    `schema_version = '43'`.
  - `::seed_once` (F2). Numa fresca, `pipe2_sub_parallel([['pipe2_run43.php',['--db='.$db]], ['pipe2_run43.php',['--db='.$db]]])`.
    As duas corridas saem com código 0. Fica um funil por `md`/`cmp`/`ed`, `COUNT(*)` de `level 'front'` é 42, há uma
    linha `pipe2_seeded` e `SELECT IS_FREE_LOCK(CONCAT('topper_pipe2_', DATABASE()))` dá 1.
  - `::fault_rolls_back` (Review Focus 5). Numa fresca:
    - `CREATE TRIGGER p2t_fail BEFORE INSERT ON …`: a tabela `pipe2_dep_models` ainda não existe, por isso chama-se
      antes `migrate_pipeline2_schema($tp)`;
    - … `FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p2t'`;
    - `migrate_pipeline2_43($tp)` lança uma exceção;
    - não há funil com `service_code` `md`, não há `service_code` no VFX, não há `pipe2_seeded` e o lock está livre;
    - `DROP TRIGGER p2t_fail`, e uma nova chamada semeia tudo (`::new_funnels_inactive` passa).
  - `::strict_modes` (F3). Numa fresca, `pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES')` e migrar sem
    exceção. Noutra fresca, `pipe2_sql_mode($tp, '')` e migrar. Relido da base, cada nome e código semeado
    (`PIPE2_SEED_*`, os nomes dos funis e os `service_code`) é igual ao valor pretendido, o que prova que nada foi
    cortado em silêncio.
  - `::seed_lookup_edges` (Review Focus 1). Numa fresca, antes de migrar:
    - o funil `xray_kind 'cgi'` passa a `xray_kind 'cgi,vfx'` e `is_base 0` (combinado);
    - acrescenta-se `INSERT INTO funnels (name, is_base, position, is_active) VALUES ('Edição', 0, 99, 1)`;
    - faz-se `DELETE FROM departments WHERE name = 'Adverts'`.

    Depois de migrar, verificar:
    - nenhum funil com `service_code '3d'` e nenhuma sub-etapa de 3D;
    - em `pipe2_dep_models` só ficam as ligações sem 3D (postprod: 3, cgi: 1);
    - o funil «Edição» da equipa fica intacto (`service_code ''`, `is_active 1`, `v2_only 0`) e existe um
      `service_code 'ed'` diferente, com `v2_only 1`;
    - o `department_id` de adv é NULL;
    - não houve exceção.
- [ ] **Step 2: Escrever `tests/pipe2_codes_test.php`** (corre na `topper` depois do boot). Casos:
  - `::widths` (F3): para cada `'tabela.coluna' => códigos` de `PIPE2_CODES`, `CHARACTER_MAXIMUM_LENGTH` de
    `information_schema.columns` `>= max(mb_strlen)`;
  - para cada nome de `PIPE2_SEED_FRONTS` e `PIPE2_SEED_DEAL_STAGES`, `mb_strlen <= 120` (`funnel_stages.name`);
  - `array_intersect(PIPE2_CODES['deal_lines.status'], array_keys(XSELL_STATUS)) === []`;
  - `array_intersect(PIPE2_CODES['stage_automations.trig'], array_keys(FLOW_TRIGGERS)) === []`.
- [ ] **Step 3: Escrever `tests/pipe2_model_test.php`.** Caso `::roles` (M1, parte da fase 0), numa fresca migrada:
  - em cada funil `md`/`cmp`/`ed`, `role 'exec'` e `'delivery'` existem uma vez cada, com `kind 'production'`,
    `level 'deal'` e posições 70 e 80;
  - em toda a base, `SELECT DISTINCT kind FROM funnel_stages` ⊂ `array_keys(STAGE_KINDS)`;
  - nenhuma etapa dos funis ativos tem `role` `exec`/`delivery` (D2);
  - todas as sub-etapas têm `kind 'production'` (nunca `'done'`, por causa de `funnel_fixed_stage`).
- [ ] **Step 4: Correr e ver falhar.** `php tests/pipe2_schema_test.php; php tests/pipe2_codes_test.php; php tests/pipe2_model_test.php`.
  Esperado: erro fatal, porque falta `migrate_pipeline2.php`.
- [ ] **Step 5: Implementar `migrate_pipeline2.php`** (cabeçalho «topper. admin — passo 43 · Pipeline v2, fase 0»,
  guarda `TOPPER_ADMIN`, `require_once` de `migrate_platform.php` e `pipe2.php`), seguindo a tabela «Esquema», a
  secção «Semente», D6 e as interfaces. Sem `funnel_create` (cria funis ativos e lê `current_user()`). O DDL fica
  **antes** de `beginTransaction`. Na semente, as datas vêm de `date('Y-m-d H:i:s')` e não de `NOW()`, para o SQLite.
  Depois, alterar `migrate.php` e `cc_media_kind_test.php` (Files).
- [ ] **Step 6: Correr e ver passar.** Os três testes, mais `php tests/pipe2_core_test.php` (agora `ready` é `true`
  na `topper` migrada) e `php tests/cc_media_kind_test.php`. Esperado: `OK n` em todos. Confirmar a bancada com
  `mariadb topper -N -e "SELECT svalue FROM settings WHERE skey='schema_version'; SELECT id,name,is_active,service_code,v2_only FROM funnels"`:
  43, 9 funis e os 6 da v1 ativos e iguais.
- [ ] **Step 7: Entregar.** Sem commit.

### Task 4: Filtros e guardas na biblioteca (`inc/pipeline.php`, `inc/deals.php`, `inc/pipeline_flow.php`, `inc/xsell.php`)

**Files:**
- Modify: `admin/inc/pipeline.php`:
  - `require_once __DIR__ . '/pipe2.php';` no topo;
  - `funnel_stages()` (37-44) com `AND ' . pipe2_stage_cond($pdo) . '`;
  - `deal_move` (79-80) passa a `if (!$to || !pipe2_stage_live($to) || …) throw new RuntimeException('Etapa inválida.');`;
  - `deal_change_funnel`, no ramo da etapa variável (170-171), acrescenta `|| !pipe2_stage_live($to)` à condição que
    já lança «Escolha a etapa de destino no funil novo.».
- Modify: `admin/inc/deals.php`: `require_once __DIR__ . '/pipe2.php';` e `funnel_fixed_stage` (112-117) com
  `AND ' . pipe2_stage_cond($pdo) . '`.
- Modify: `admin/inc/pipeline_flow.php:346-356`: extrair
  `function flow_tick_rules(PDO $pdo): array` (o SELECT atual + `AND ' . pipe2_stage_cond($pdo, 's') . '`), e o
  `flow_tick` passa a usá-la. Nada mais muda.
- Modify: `admin/inc/xsell.php:325-326` (`xsell_line_move`): `if (!$to || !pipe2_stage_live($to) || …) throw new RuntimeException('Etapa inválida.');`
- Create: `tests/pipe2_filters_test.php`

**Interfaces:**
- Consumes: T2 (`pipe2_stage_cond`, `pipe2_stage_live`, `pipe2_schema_ready`), T1 (`pipe2_tmpdb_*`, `pipe2_use`,
  `pipe2_sub`, `pipe2_deal`, `pipe2_front`, `pipe2_sql_mode`) e T3 (esquema e semente).
- Produces: `flow_tick_rules(PDO $pdo): array`, que devolve as linhas `a.*, s.funnel_id` das regras ativas `monthly`
  e `date` em etapas vivas. A T1 (fotografia) e a T6 usam-na.

O teste corre tudo numa base privada `$db` de `pipe2_base_dump()`, com `$tp` e `pipe2_use($tp)`. A migração é feita
**num subprocesso** (`pipe2_sub('pipe2_v1_snap.php', ['--migrate','--db='.$db])`), para que as caches estáticas deste
processo fiquem limpas. Antes de migrar, nunca se chama `flow_order` nem `deal_funnel_prod_stages`.

- [ ] **Step 1: Escrever o teste a falhar.** `tests/pipe2_filters_test.php`:
  - `::before_migration_no_error` (Review Focus 2). Na base privada em 42:
    - `pipe2_schema_ready($tp) === false`;
    - para cada funil do dump, `$before[$fid] = array_column(funnel_stages($tp,$fid),'id')`, sem exceção;
    - `funnel_fixed_stage($tp,$vfx,'xray')` não é null;
    - `$tickBefore = array_column(flow_tick_rules($tp),'id')`;
    - a ordem de publicação (Global Constraints) põe os ecrãs da T5 antes da migração, por isso testam-se aqui as
      peças deles numa base na 42:
      - `pipe2_stage_cond($tp,'s') === '1=1'` e `pipe2_funnel_cond($tp,'f') === '1=1'`;
      - `pipe2_funnel_hidden($tp, funnel_get($tp,$vfx)) === false`, porque a linha não tem `v2_only`;
      - `pipe2_stage_live(stage_get($tp, <X-Ray do VFX>)) === true`;
      - correm sem erro e devolvem todos os funis do dump: `SELECT id, name FROM funnels WHERE ' .
        pipe2_funnel_cond($tp) . ' ORDER BY position, name` (`negocios.php`/`relatorios.php`) e a consulta do `$all`
        do `funis.php` com `WHERE ' . pipe2_funnel_cond($tp,'f') . '`;
      - o `MAX(position)` do `add_stage` com `AND ' . pipe2_stage_cond($tp) . '` corre sem erro.
  - Migrar no subprocesso. `::ready_not_cached_false`: na mesma ligação, `pipe2_schema_ready($tp) === true`.
  - Injetar, na base privada, no funil `xray_kind 'vfx'`:
    - uma etapa `production` com posição 67 e `retired_at = NOW()`;
    - uma `xray` com posição 5 e `retired_at = NOW()`;
    - uma regra ativa `trig 'monthly'` numa sub-etapa `front` do VFX;
    - uma regra ativa `trig 'date'` na etapa retirada de produção.
  - `::front_and_retired_hidden` (M2). Primeira chamada de cada leitor depois de injetar:
    - para todos os funis, `array_column(funnel_stages($tp,$fid),'id') === $before[$fid]`;
    - `flow_order($tp,$vfx)` dá os ids de `$before[$vfx]`;
    - `deal_funnel_prod_stages($tp,$vfx)` dá os ids de produção de `$before[$vfx]`;
    - `funnel_fixed_stage($tp,$vfx,'xray')` é o X-Ray original (e não o retirado da posição 5), e o mesmo para o
      `'done'`;
    - `flow_stage_with($tp,$vfx,'proposal')` e `'kickoff'` são os originais;
    - `array_column(flow_tick_rules($tp),'id') === $tickBefore` (as duas regras injetadas ficam de fora);
    - `deal_steps($tp, ['funnel_id'=>$vfx,'stage_id'=><1.ª de produção>,'phase'=>'production','prod_step'=>null])['items']`
      tem como rótulos os nomes de produção v1 + `'Concluído'`;
    - `xsell_close_missing` de um negócio `pipe2_deal` em produção (`sync_mode 'sync'`), com duas frentes `active`
      (VFX e CGI), cada uma na última etapa de produção v1 do seu funil, não tem nenhuma mensagem «falta chegar a …»,
      porque a «última» continua a ser a v1 e não uma sub-etapa.
  - `::writes_refuse_hidden_stages` (Review Focus 3):
    - `$d = pipe2_deal($tp, ['phase'=>'pipeline','funnel_id'=>$vfx,'stage_id'=><X-Ray>])`;
    - `deal_move($tp, deal_get($tp,$d), <sub-etapa VFX>)` e `deal_move(…, <etapa retirada>)` lançam
      `RuntimeException` com `'Etapa inválida.'`, e o `stage_id` não muda;
    - com um negócio em Proposta do CGI, `deal_change_funnel($tp, $deal, $vfx, <sub-etapa VFX>)` lança «Escolha a
      etapa de destino no funil novo.»;
    - com um negócio em produção e uma frente `pipe2_front(…, ['status'=>'active','stage_id'=><1.ª de produção>])`,
      `xsell_line_move($tp, $deal, xsell_line_get($tp,$lineId), <sub-etapa VFX>)` lança `'Etapa inválida.'` e o
      `stage_id` da frente não muda.
  - `::strict_readers` (F3): com `pipe2_sql_mode($tp,'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES')`, `funnel_stages`,
    `funnel_fixed_stage`, `flow_tick_rules` e `commercial_stages(funnel_stages(…))` correm sem exceção.
- [ ] **Step 2: Correr e ver falhar.** `php tests/pipe2_filters_test.php`. Esperado: FALHA em
  `::front_and_retired_hidden` (as sub-etapas aparecem) e erro por falta de `flow_tick_rules`.
- [ ] **Step 3: Implementar** as alterações listadas em Files. Só as linhas indicadas mudam.
- [ ] **Step 4: Correr e ver passar.** `php tests/pipe2_filters_test.php`, mais os testes da T2 e da T3 (que não podem
  regredir). Esperado: `OK n`. Correr ainda `php -l` nos 4 ficheiros.
- [ ] **Step 5: Entregar.** Sem commit.

### Task 5: Ecrãs — funis só da v2 escondidos e guardas em `funis.php`, `negocios.php`, `relatorios.php`

**Files:**
- Modify: `admin/funis.php`:
  - `require_once __DIR__ . '/inc/pipe2.php';`;
  - POST: depois de `$f = funnel_get($pdo, $fid)` (180), `if (!$f || pipe2_funnel_hidden($pdo, $f))` → «Funil não
    encontrado.»;
  - `create` (152-153): saltar os `from[]` escondidos;
  - modelo (165): `WHERE is_base = 1 AND ' . pipe2_funnel_cond($pdo) . '`;
  - `add_stage` (202): `AND ' . pipe2_stage_cond($pdo) . '`;
  - ações de etapa (209-210): `if (!$s || (int)$s['funnel_id'] !== $fid || !pipe2_stage_live($s))` → «Etapa não
    encontrada.»;
  - `$all` (315-316): `WHERE ' . pipe2_funnel_cond($pdo, 'f') . '`;
  - `$fid`/`$ef` (317-318): se `pipe2_funnel_hidden($pdo, $ef)`, passa ao primeiro de `$all`.
- Modify: `admin/negocios.php:104` e `admin/relatorios.php:26`: `SELECT id, name FROM funnels WHERE ' . pipe2_funnel_cond($pdo) . ' ORDER BY position, name`,
  mais `require_once __DIR__ . '/inc/pipe2.php';`.
- Create: `tests/pipe2_screens_test.php`
- **Não muda:** `admin/pipeline.php`. A linha 103 já volta ao primeiro funil ativo; o teste fixa isso.

**Interfaces:**
- Consumes: T2 (`pipe2_funnel_cond`, `pipe2_funnel_hidden`, `pipe2_stage_cond`, `pipe2_stage_live`), T1
  (`tests_http`, `pipe2_funnel`, `pipe2_cleanup` com a guarda da «Limpeza», `pipe2_lock`), T3 (bancada migrada: funis
  `v2_only` e sub-etapas) e T4 (servidor estável).
- Produces: nada para outras tarefas.

O teste corre na bancada `topper` (HTTP em `127.0.0.1:8765`, uid 1 salvo indicação), que é partilhada e já está na
43 (T3). Os ids `$md`, `$cmp` e `$ed` vêm de `service_code`, `$vfx` é o funil com `service_code 'vfx'` e `$vfront` é a
1.ª sub-etapa do VFX.

**Regra dos dados (Review Focus 6).** Na corrida vermelha (Step 2) o `funis.php` ainda é o antigo: os POST fazem
mesmo as escritas, e a semente não volta a correr (`pipe2_seeded`). Por isso:
- Contra os funis e as etapas reais (`$md`, `$cmp`, `$ed`, `$vfx`, `$vfront`) **só há GET**.
- Todos os POST vão para funis descartáveis criados pelo teste:
  - `$hf = pipe2_funnel($pdo,'oculto')`, seguido de `UPDATE funnels SET v2_only = 1 WHERE id = $hf`. Fica como um
    funil só da v2 (`is_active 0`, `v2_only 1`, `service_code ''`). `$hfStages` são os ids das etapas dele, lidos com
    SELECT direto;
  - `$gf = pipe2_funnel($pdo,'guarda')`, com duas etapas inseridas à mão: `$gfront` (`kind 'production'`,
    `level 'front'`, posição 110) e `$gret` (`kind 'production'`, `level 'deal'`, posição 67, `retired_at` agora);
  - `$tf = pipe2_funnel($pdo,'add_stage')`, para o `::add_stage_position`.
- O helper de POST do teste, `p2_post(array $data)`, recusa um `funnel_id`, `from[]`, `from_funnel_id` ou
  `to_funnel_id` que não seja `$hf`, `$gf` ou `$tf`, e um `stage_id` que não seja de um desses três funis (o `create`
  não leva `funnel_id`). Recusa antes de enviar: falha o teste e sai com `exit(1)`.
- Todos os nomes enviados começam por `'P2T · '`. Assim a limpeza apanha-os mesmo que a escrita passe. Como só os
  funis descartáveis recebem POST, nenhum funil real fica com um desses nomes (e, de qualquer modo, a limpeza nunca
  apaga um funil `is_base 1` nem um com `service_code`).
- Antes de criar os funis descartáveis, guarda-se `$sig0`, a assinatura da bancada, com três partes:
  - `COUNT(*)` e `SUM(CRC32(CONCAT_WS('|', id, name, is_base, made_from, position, is_active, xray_kind, v2_only,
    service_code)))` de `funnels` com `name NOT LIKE 'P2T · %'`;
  - o mesmo de `funnel_stages` desses funis, com `id, funnel_id, name, kind, position, role, level, retired_at,
    gate_on, rounds_included`;
  - `COUNT(*)` de `stage_automations` dessas etapas.

- [ ] **Step 1: Escrever o teste a falhar.** `tests/pipe2_screens_test.php`:
  - `::hidden_funnel_direct_url` (Review Focus 4):
    - só GET:
      - `GET /admin/funis.php` não tem `href` com `f=$md`, `f=$cmp`, `f=$ed` nem `f=$hf`;
      - para `$x` em `$md` e `$hf`, `GET /admin/funis.php?f=$x` não tem nenhum `id="st-<etapa de $x>"`, e o
        `funnel_id` escondido do formulário `rename` não é `$x`;
      - para `$x` em `$md` e `$hf`, `GET /admin/pipeline.php?f=$x` tem os mesmos `data-stage` que
        `GET /admin/pipeline.php?f=<1.º de funnels_active>` e nenhum id de etapa de `$x`;
    - POST, só em `$hf`:
      - `do=toggle&funnel_id=$hf` mantém `is_active` 0 na base;
      - `do=add_stage&funnel_id=$hf&name=P2T · x&kind=production` não altera a contagem de etapas de `$hf`;
      - `do=rename&funnel_id=$hf&name=P2T · renomeado` mantém o nome `P2T · oculto`;
      - `do=create&name=P2T · c&from[]=$hf` cria um funil (limpo no fim) com `made_from` sem `P2T · oculto`.
  - `::funis_stage_guard` (Review Focus 3):
    - só GET: `GET /admin/funis.php?f=$vfx` não tem nenhum `id="st-<sub-etapa do VFX>"` (`$vfront` incluída);
    - `GET /admin/funis.php?f=$gf` não tem `id="st-$gfront"` nem `id="st-$gret"`;
    - POST, só em `$gf`, para `$s` em `$gfront` e `$gret`, por esta ordem:
      - `do=save_stage&funnel_id=$gf&stage_id=$s&name=P2T · mudou` mantém o nome;
      - `do=move_stage&…&dir=up` e depois `dir=down` mantêm a posição;
      - `do=del_stage&funnel_id=$gf&stage_id=$s` deixa a etapa a existir.
  - `::add_stage_position`:
    - `INSERT` de uma etapa `production`, `level 'front'`, posição 110, em `$tf`;
    - um POST `do=add_stage&funnel_id=$tf&name=P2T · nova&kind=production` dá à etapa nova a posição `60`.
  - `::lists_hide_v2` (só GET):
    - no `GET /admin/negocios.php`, o `<select name="funil">` não tem as opções `$md`, `$cmp`, `$ed` nem `$hf`, e tem
      todas as dos funis com `v2_only = 0`;
    - no `GET /admin/relatorios.php`, o mesmo nos `<select name="fu">` e `name="sv"`;
    - no `GET /admin/relatorios.php?tab=metas&gs=funnel`, o mesmo no `<select name="gi">`.
  - `::leitor_403`: uid 3, `GET /admin/funis.php` → 403 (não muda).
  - `::bench_untouched` (Review Focus 6), no `finally`, depois de `pipe2_cleanup($pdo)`:
    - a assinatura é igual a `$sig0`;
    - já não há funis `name LIKE 'P2T%'`;
    - `$md`, `$cmp` e `$ed` continuam com `is_active 0`, `v2_only 1` e os nomes da semente;
    - `COUNT(*)` de `funnel_stages` com `level 'front'` continua 42.
- [ ] **Step 2: Correr e ver falhar.** Antes de correr, `grep -n "p2_post\|tests_http('POST'" tests/pipe2_screens_test.php`.
  Todos os POST têm de passar por `p2_post`. Depois, `php tests/pipe2_screens_test.php`. Esperado:
  - FALHA em `::hidden_funnel_direct_url`, `::funis_stage_guard`, `::add_stage_position` e `::lists_hide_v2`;
  - `::leitor_403` e `::bench_untouched` passam, o que prova que a corrida vermelha não estragou a bancada.

  Confirmar ainda com `mariadb topper -N -e "SELECT COUNT(*) FROM funnels WHERE name LIKE 'P2T%'; SELECT service_code, name, is_active, v2_only FROM funnels WHERE service_code IN ('md','cmp','ed'); SELECT COUNT(*) FROM funnel_stages WHERE level = 'front'"`.
  Esperado: `0`, as três linhas com `is_active 0` e `v2_only 1`, e `42`. Se alguma coisa diferir, **parar e avisar o
  orquestrador**: a semente não volta a correr, e a bancada tem de ser reposta antes da T6.
- [ ] **Step 3: Implementar** as alterações listadas em Files.
- [ ] **Step 4: Correr e ver passar.** `php tests/pipe2_screens_test.php` e `php -l` nos 3 ficheiros. Esperado:
  `OK n`. Repetir a confirmação `mariadb` do Step 2, com o mesmo resultado.
- [ ] **Step 5: Entregar.** Sem commit.

### Task 6: Prova de que a v1 não muda (F1), bateria inteira e §10

**Files:**
- Create: `tests/pipe2_v1_unchanged_test.php`
- Modify: `docs/ESPECIFICACAO-PLATAFORMA.md`: nova `### 10.33 Pipeline v2 — fase 0: bandeira e esquema — §34.11 (6 nov 2026)`
  logo a seguir à 10.32, antes da `### 10.23`.

**Interfaces:**
- Consumes: T1 (`pipe2_v1_snap.php`, `pipe2_base_json`, `pipe2_base_dump`, `pipe2_diff`, `pipe2_sub`,
  `pipe2_cleanup`), T2 (`pipe2_on`, `pipe2_stage_live`), T3, T4 e T5.
- Produces: nada.

- [ ] **Step 1: Escrever o teste.** `tests/pipe2_v1_unchanged_test.php` (com `pipe2_lock`, e com `pipe2_cleanup($pdo)`
  no início para varrer sobras `P2T`):
  - `::invariants`, na bancada `topper`:
    - `pipe2_on($pdo) === false`;
    - `schema_version === '43'`;
    - os funis `v2_only = 1` são exatamente os de `service_code` `md`/`cmp`/`ed`, todos com `is_active 0`;
    - para cada funil, todas as linhas de `funnel_stages()` cumprem `pipe2_stage_live`;
    - `SELECT COUNT(*) FROM stage_automations a JOIN funnel_stages s ON s.id = a.stage_id WHERE a.is_active = 1 AND (s.level <> 'deal' OR s.retired_at IS NOT NULL)`
      é 0;
    - `LEAD_SERVICES_LIST === ['VFX / 3D', 'Marketing Buddy', 'Ambos']`;
    - `setting($pdo,'deal_stale_days')` é igual ao valor em `base42.json` (`php.settings`).
  - `::after_migration` (F1):
    - criar `$db` a partir de `pipe2_base_dump()`;
    - `pipe2_sub('pipe2_v1_snap.php',['--migrate','--db='.$db])` sai com código 0 e `to = 43`;
    - `$after = pipe2_sub('pipe2_v1_snap.php',['--php','--db='.$db])['json']['php']`;
    - `t_eq(pipe2_diff(pipe2_base_json()['php'], $after), [], …)`.
  - `::injected_rows_hidden` (M2 integrado). Em `$db`, injetar as mesmas 4 linhas da T4 e fotografar de novo com
    `--php`: o diff com a linha de base é `[]`.
  - `::after_migration_http` (F1):
    - `$live = pipe2_sub('pipe2_v1_snap.php',['--http'])['json']['http']`;
    - `t_eq(pipe2_diff(pipe2_base_json()['http'], $live), [], …)`.
  - Sem `base42.json`, o teste FALHA com a instrução «gere a linha de base na 42 (T1)». Nunca passa em silêncio.
- [ ] **Step 2: Correr.** `php tests/pipe2_v1_unchanged_test.php`. Esperado: `OK n`. Se falhar, aplicar
  superpowers:systematic-debugging: procurar a causa no diff (caminho exato) e corrigi-la na tarefa a que o ficheiro
  pertence. Não se mexe na linha de base.
- [ ] **Step 3: Correr a bateria inteira.** `for t in tests/*_test.php; do php "$t" >/tmp/p2_out.txt 2>&1 || { echo "FALHOU $t"; tail -5 /tmp/p2_out.txt; }; done`
  e `for t in tests/ui/*_test.js; do node "$t" >/tmp/p2_ui.txt 2>&1 || { echo "FALHOU $t"; tail -5 /tmp/p2_ui.txt; }; done`.
  Esperado: nenhuma linha «FALHOU». Se um teste UI falhar, corrê-lo outra vez. Se continuar a falhar, verificar se
  também falha no commit 428480f antes de o atribuir à fase 0. Não usar `git stash`, que mexe na cópia de trabalho
  partilhada: usar `git worktree add` numa pasta do scratchpad, com o servidor noutra porta.
- [ ] **Step 4: Escrever a §10.33** em 8 a 12 linhas, no estilo das outras entradas:
  - passo 43 (`migrate_pipeline2.php`);
  - bandeira `pipeline_v2_at` (vazia = v1, `pipe2_on`) e chave da semente `pipe2_seeded`;
  - colunas e tabelas novas (resumo da tabela «Esquema»);
  - funis Motion Design/Composit/Edição inativos e escondidos (`v2_only`);
  - códigos `service_code`;
  - sub-etapas dos 7 serviços;
  - categorias e modelos de dependências;
  - filtros `level`/`retired_at` (lista D4);
  - o que fica para a conversão (Em execução/Entrega nos 4 funis ativos, posições, nomes, `xray_kind`,
    `LEAD_SERVICES_LIST`, `deal_stale_days`);
  - a ordem de publicação das Global Constraints: `inc/pipe2.php`, depois todos os leitores e ecrãs juntos
    (`inc/deals.php`, `inc/pipeline.php`, `inc/pipeline_flow.php`, `inc/xsell.php`, `funis.php`, `negocios.php`,
    `relatorios.php`), depois `inc/migrate_pipeline2.php` e, por último, `inc/migrate.php`. Explicar numa frase porquê:
    o `migrate.php` dispara a semente no pedido seguinte, e os leitores já têm de filtrar;
  - os testes `pipe2_*`.
- [ ] **Step 5: Verificação final.** Aplicar superpowers:verification-before-completion: colar no relatório a saída
  dos `pipe2_*_test.php` e o resumo da bateria. Confirmar com `git status --short` que só aparecem os ficheiros deste
  plano e que `tests/.pipe2` não aparece. Sem commit.

---

## Rastreabilidade da fase 0 (§34.11 → teste)

| # | Teste |
|---|---|
| F1 | `pipe2_v1_unchanged_test::after_migration`, `::after_migration_http`, `::invariants` (T6); depois de reverter o código: `pipe2_rollback_test::code_rollback`, `::rollback_injected_rows`, `::rollback_guards` (controlo `::full_rollback_breaks`) |
| F2 | `pipe2_schema_test::idempotent`, `::seed_once`, `::fault_rolls_back` (T3) |
| F3 | `pipe2_schema_test::strict_modes`, `pipe2_codes_test::widths` (T3); `pipe2_filters_test::strict_readers` (T4) |
| M1 | `pipe2_model_test::roles` (T3); os casos `funnel_create_six` e `del_stage_guards` são da fase 1 |
| M2 | `pipe2_filters_test::front_and_retired_hidden` (T4); `pipe2_screens_test::funis_stage_guard`, `::add_stage_position` (T5); `pipe2_v1_unchanged_test::injected_rows_hidden` (T6). As regras das etapas retiradas a `is_active = 0` são da fase 3 |
| M5 | `pipe2_schema_test::columns` (`merged_into_id`, `deal_line_execs`), `pipe2_codes_test::widths` (estados novos ≠ `XSELL_STATUS`) |
| M6 | `pipe2_schema_test::new_funnels_inactive`, `::kept_funnels`, `::seed_lookup_edges` |
| M7 | `pipe2_schema_test::columns` (`deal_lines.billing`, `deals.billing`, `funnels.department_id`), `::departments` |
