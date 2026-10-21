# topper. admin — especificação da plataforma (v1)

Documento de passagem para o agente de execução. Reúne tudo o que foi
decidido nas sessões de conceção. Onde algo ainda **não está decidido**,
está marcado como **PENDENTE** — não implementar essas partes por conta
própria; deixar a estrutura preparada e perguntar.

Contexto do código atual: ver `README.md`, `sheets/CONTRACT.md`,
`admin/inc/migrate.php` (base de dados na versão 7) e as páginas em `admin/`.

---

## 0. Decisões estruturais (fechadas)

1. **MySQL passa a ser a fonte única de verdade** de toda a plataforma.
   A folha Google «TOPPER DIGITAL» deixa de ser lida ou escrita pelo admin
   como base; passa a ser **apenas um espelho** (exportação). Os dados
   atuais da folha são migrados (ver §5).
2. **Lead → Oportunidade → Negócio são o mesmo registo** em estados de
   evolução diferentes. Nunca se duplica o registo ao mudar de fase.
3. **Cada negócio tem um ID único, imutável, nunca reutilizado**, atribuído
   no momento da conversão da lead. Tudo o que se liga a um negócio
   (orçamentos, faturas, custos, ficheiros, tarefas, criativos, contactos)
   liga-se pelo ID — **nunca pelo nome**. O nome continua editável.
4. **Utilizadores individuais**: cada membro entra com o seu próprio email.
   Permissões e utilizadores geridos na aba Equipa.

---

## 1. Esqueleto — abas (por esta ordem no menu)

| # | Aba | Papel | Estado atual |
|---|---|---|---|
| 1 | Dashboard Executivo | Visão geral da empresa, só leitura, indicadores de todas as abas | Existe como «Painel» (só financeiro) |
| 2 | Leads | Leads frias, geridas até converter ou arquivar | Existe (parcial) |
| 3 | Pipeline | Oportunidades em fase comercial, vários funis | Novo |
| 4 | Contactos | Base única de pessoas/empresas. Separadores: Clientes · Fornecedores · Parceiros · Todos | Fornecedores existe (passa a tipo de contacto) |
| 5 | Negócios | **Central de informação de cada negócio** (ver §3.3) | Existe como Carteira › Trabalhos + Ficha |
| 6 | Orçamentação | Criação e gestão de orçamentos | Novo — **PENDENTE** detalhe |
| 7 | Centro Criativo | Produção criativa (1.ª hipótese: briefings, referências, versões, feedback e aprovações do cliente) | Novo — **PENDENTE** conceito |
| 8 | Tarefas | Kanban de tarefas + calendário | Existe como «Calendário» |
| 9 | Drive | Ficheiros (Cloudflare R2) | Existe |
| 10 | Financeiro | Faturas, custos, IVA, tesouraria | Existe como Carteira › Custos + IVA |
| 11 | Relatórios | Histórico e análise (períodos, funis, campanhas, pessoas) | Novo — **PENDENTE** detalhe |
| 12 | Marketing | Atração de leads, campanhas | Novo — **PENDENTE** detalhe |
| 13 | Equipa | Membros, departamentos, utilizadores e permissões | Existe (mínimo, dentro do Calendário) |
| 14 | Centro de Conhecimento | Processos, templates, guias | Novo — **PENDENTE** detalhe |
| 15 | Tools | Ferramentas externas (Render farm) | Existe |

Abas marcadas PENDENTE: criar a entrada no menu e uma página vazia
(«Em construção»), sem lógica.

---

## 2. Ciclo de vida do negócio

```
LEAD ──[Converter]──► OPORTUNIDADE (Pipeline) ──chega a Kickoff──► NEGÓCIO (aba Negócios) ──► Concluído
  │                     X-Ray → … → Kickoff                           Kickoff → … → Concluído
  └─[Arquivar]          └─[Perdido] (botão, qualquer etapa comercial)  └─[Cancelado]* (botão)
```

\* «Concluído» e «Cancelado» foram propostos e ainda **PENDENTE** de
confirmação (ver §8). Implementar a estrutura de forma a suportá-los.

### 2.1 ID do negócio

- Formato: **`ID` + AA + MM + `-` + sequência de 2 dígitos**, ex.: `ID2610-01`
  = primeira lead convertida em outubro de 2026.
- Atribuído **automaticamente na conversão** da lead (não no Kickoff).
- A sequência reinicia todos os meses. Imutável, nunca reutilizado: negócios
  perdidos mantêm o seu ID, por isso pode haver «buracos» na numeração.
- Preparar para crescer para 3 dígitos sem partir IDs existentes.
- Atribuição atómica (sem dois negócios com o mesmo ID em conversões simultâneas).
- As leads antes de convertidas têm só um ID interno (não visível).

### 2.2 Pasta no Drive

- Passa a ser `Projetos/<ID>_<NOME>/`, ex.: `Projetos/ID2610-01_CAMPANHA_X/`.
- A pasta é encontrada **pelo ID**; mudar o nome do negócio não a parte.
- As pastas antigas `NN_NOME_PROJECT` e os nomes associados à mão são
  ligados aos IDs atribuídos na migração (§5).

---

## 3. Lógica por aba (decidida até agora)

### 3.1 Leads

**Origens** (campo `origem`): `Manual` · `Site` · `Anúncio` (+ campo `campanha`).
- Manual: formulário no admin.
- Site: formulário de contacto novo no `index.html` público (hoje não existe;
  só há o email `info@topperdigital.xyz`). Com proteção anti-spam (campo
  escondido + limite por IP; suspeitas vão para quarentena).
- Anúncio: entrada automática via webhook — **Meta e LinkedIn** (ainda não
  há anúncios a correr; preparar a estrutura, integração numa fase posterior).
- Leads que entram automaticamente (Site/Anúncio) ficam **sem responsável**,
  numa fila **«Por atribuir»** visível a admins e chefes de equipa.
- **Duplicados**: ao entrar uma lead com email ou telefone já existente
  (em leads ou contactos), avisar e propor juntar.

**Estados**: `Novo` → `Contactado` → `Em conversa`.
- «Proposta enviada», «Ganho» e «Perdido» **deixam de ser estados de lead**
  (passam a pertencer ao Pipeline).
- Mudar o estado grava logo e põe o «último contacto» com a data de hoje (como hoje).
- **Alerta de lead parada**: destacar leads sem atividade há mais de X dias
  (X configurável).

**Ações**
- **Converter**: pede o **funil**; atribui o ID; cria ou associa o **Contacto**
  (pessoa/empresa); o registo entra no Pipeline **na etapa X-Ray** do funil
  escolhido; sai da lista de Leads.
- **Arquivar** com motivo (sem interesse, sem resposta, fora de perfil, spam,
  outro). Separador «Arquivadas». Uma lead arquivada pode ser **reativada**.
- Criar, editar e eliminar (com confirmação), como hoje.

**Campos**: nome · tipo (múltiplo: Produtora, Agência de Marketing, Empresa
Particular, Realizador, VFX company) · contactos/redes · telefone · email ·
serviço (VFX / 3D · Marketing Buddy · Ambos) · prioridade (Alta/Média/Baixa) ·
detalhe · porquê abordar · notas · **origem** · **campanha** · **responsável** ·
automáticos: estado, último contacto, criado por, data de criação.

**Responsável** — por defeito, quem cria. Regras de atribuição (valem também
para reatribuir e para atribuir leads da fila «Por atribuir»):

| Quem atribui | Pode atribuir a |
|---|---|
| Superadmin | Qualquer utilizador |
| Admin | Admins, chefes de equipa, colaboradores |
| Chefe de equipa | Colaboradores **do seu departamento** (ou a si próprio) |
| Colaborador | Ninguém — a lead fica sempre com ele |

**Visibilidade**: colaborador vê só as suas · chefe de equipa vê as do seu
departamento · admin e superadmin veem todas.

### 3.2 Pipeline

**Funis**
- Vários funis, um por produto/serviço **ou combinação** (cross-selling),
  ex.: «VFX / 3D», «Marketing Buddy», «VFX + Marketing Buddy». A lista real
  de funis está **PENDENTE**.
- Cada negócio pertence a **um único funil**.
- Etapas de cada funil **configuráveis no admin** (criar, renomear, reordenar)
  sem tocar no código.

**Estrutura de um funil** — duas fases, com etapas fixas e variáveis:

```
FASE COMERCIAL (aba Pipeline)                  FASE DE PRODUÇÃO (aba Negócios)
X-Ray → [variáveis] → Kickoff → [variáveis] → Concluído*
fixa                   fixa                     fixa*
```
- **X-Ray** = orçamentação; entrada fixa de todos os funis.
- **Kickoff** (também chamado «Victory Lap») = negócio fechado. A partir daqui
  o registo aparece na aba Negócios.
- As etapas variáveis de cada funil (comerciais e de produção) estão **PENDENTE**.
- **Perdido** não é etapa: é um **botão** disponível em qualquer etapa da fase
  comercial (incluindo X-Ray). Guarda **motivo** e **etapa em que se perdeu**.
  Um negócio perdido pode ser **reaberto**. Mantém o ID.

**Vista**: kanban por funil (um separador por funil), uma coluna por etapa,
arrastar e largar para mudar de etapa.

**Histórico**: cada mudança de etapa grava etapa de origem, etapa de destino,
utilizador e data/hora — base para taxas de conversão, tempo por etapa e
onde se perdem os negócios (Relatórios).

**Mudar de funil**: permitido. O negócio mantém ID, dados e histórico.
Guarda-se o **funil de origem** (imutável) e o **funil atual**, mais o
histórico de mudanças. Etapa no novo funil: X-Ray/Kickoff → mesma etapa;
etapa variável → quem muda escolhe a etapa de destino.

**Valor**: cada negócio mostra o valor estimado (do orçamento, ou escrito à
mão antes de haver orçamento); total por etapa no topo de cada coluna.
Probabilidade por etapa / previsão ponderada: **PENDENTE**.

**Responsável**: transita da lead e pode ser reatribuído com as mesmas regras
de §3.1. Um responsável único vs. responsável comercial + gestor de projeto:
**PENDENTE**.

### 3.3 Negócios

- Lista de negócios a partir do Kickoff + **ficha do negócio**.
- A ficha **não gera dados próprios**: é a **central** que mostra tudo o que
  as outras abas criaram e ligaram ao negócio pelo ID:
  - informação e contactos do negócio (Contactos);
  - orçamentação realizada (Orçamentação);
  - resultados financeiros — faturação, custos, lucro (Financeiro);
  - documentos contratuais e fiscais/financeiros (Drive);
  - criativos (Centro Criativo);
  - tarefas (Tarefas);
  - etapa de produção atual e histórico de etapas.
- Comportamento atual da Ficha do trabalho a preservar: editar nome, tipo,
  data, budget e IVA; gastos = soma da equipa **ou** valor manual; custos de
  equipa (pessoa, função, valor, modo de IVA, estado) com criar, editar,
  fechar e eliminar; botão de pasta no Drive (agora pelo ID, §2.2).

### 3.4 Contactos

- Base única de pessoas e empresas, com **tipo**: Cliente · Fornecedor ·
  Parceiro · Freelancer.
- Separadores: Clientes · Fornecedores · Parceiros · Todos.
- **Fornecedores deixam de ser aba própria** e passam a ser contactos do tipo
  Fornecedor, com o separador dedicado. Mantêm os campos e as 10 categorias
  atuais (Produção, Imagem, Som, Pós-produção, 3D/VFX, Elenco,
  Material/aluguer, Locais, Software/serviços, Outro), avaliação 1–5, etc.
- Um contacto pode ligar-se a vários negócios.

### 3.5 Tarefas (atual Calendário)

Mantém o comportamento atual (já em MySQL): vista Mês, kanban por pessoa com
«Por atribuir» e «Feito», arrastar e largar com gravação imediata e reversão
em caso de falha, filtro por pessoa. Alterações:
- a ligação de uma tarefa/evento a um trabalho passa a ser pelo **ID do negócio**
  (hoje é pelo nome vindo da folha);
- os membros passam a vir da aba Equipa (utilizadores).

### 3.6 Drive

Mantém tudo o que existe (R2, envios diretos, multipart 16 MB × 4, fila,
confirmação de substituição, pré-visualizações, raiz com `Empresa/`,
`Projetos/`, `Showreel e Portfolio/`). Única alteração: pastas de projeto por ID (§2.2).

### 3.7 Financeiro

Recebe o que hoje está em Carteira › Custos e IVA (custos de equipa por
negócio + custos da empresa, modos de IVA sem/acresce/incluído, fechado,
pago; IVA liquidado vs suportado com aviso de estimativa). As **fórmulas
passam a ser calculadas pelo sistema** (IVA 23%, valor s/ IVA, gastos,
lucro), com o mesmo resultado que a folha produz hoje (ver `sheets/CONTRACT.md`).
Detalhe adicional (faturas, tesouraria): **PENDENTE**.

### 3.8 Dashboard Executivo

Mantém por agora os cartões do Painel atual (faturação s/ e c/ IVA, lucro
antes de impostos, gastos totais e % da faturação, seletor de ano), agora
lidos do MySQL. A Carteira sai do Dashboard (vai para Negócios/Financeiro).
Novos indicadores: **PENDENTE**.

### 3.9 Equipa

- **Departamentos** (ex.: Comercial, Orçamentação, VFX, Desenvolvimento),
  cada um com um **chefe de equipa opcional**. Enquanto um departamento não
  tiver chefe, são os admins que o gerem (não precisa de lógica especial).
- **Papéis** (hierarquia):
  - **Superadmin** — só `info@topperdigital.xyz`. Conta única, não pode ser
    apagada nem despromovida. Mesmas permissões que um admin **mais** autoridade
    sobre os utilizadores admin (criar, editar, desativar admins).
  - **Admin** — acesso total à plataforma; não gere o superadmin nem outros admins.
  - **Chefe de equipa** — gere o seu departamento.
  - **Colaborador** — com uma função (comercial, orçamentista, engenheiro de
    VFX, desenvolvedor, …).
- Gestão de utilizadores e permissões feita nesta aba.
- Membros mantêm nome e cor (usados nas Tarefas).
- Permissões detalhadas por aba e por papel: **PENDENTE** (proposta em
  discussão: por aba, níveis «sem acesso / ver / editar»).

### 3.10 Tools

Sem alterações (link para o «Render farm», repositório `render-farm-topper`).

---

## 4. Segurança (manter tudo o que existe)

Login por email + palavra-passe; bloqueio após 6 falhas em 15 min (por email
ou IP) com mensagem que não revela se o email existe; só HTTPS; CSRF em todos
os formulários; sessão termina após 60 min de inatividade ou 12 h no total;
cabeçalhos anti-embed e CSP; `.htaccess` bloqueia ficheiros sensíveis e `inc/`;
admin fora dos motores de pesquisa; credenciais só no servidor, fora do Git.

Novo com vários utilizadores: **toda a verificação de permissões e de
visibilidade é feita no servidor**, nunca só escondendo botões.

---

## 5. Migração Folha → MySQL

**Ordem**: as tabelas novas são desenhadas a partir **deste modelo**, não das
tabelas antigas (`projects`, `crew`, `company_costs`, `leads`, que existem sem
uso). Migrar uma só vez, para a estrutura final.

**Correspondência**

| Separador da folha | Destino |
|---|---|
| Trabalhos | Negócios já fechados (Kickoff/Concluído), cada um com ID atribuído pelo ano, mês e ordem na folha |
| Equipa | Custos do negócio, ligados pelo **ID** (não pelo nome) |
| Custos empresa | Custos gerais no Financeiro |
| Leads em Novo / Contactado / Em conversa | Leads com o mesmo estado |
| Leads em «Proposta enviada» | Negócios na etapa X-Ray, com ID |
| Leads em «Ganho» | Negócios (com ID) |
| Leads em «Perdido» | **PENDENTE**: negócio perdido com ID, ou lead arquivada sem ID |
| Fornecedores | Contactos do tipo Fornecedor |

**Passos**
1. Cópia de segurança da folha (já existe uma de 16 out 2026; fazer outra no dia).
2. Correr a migração num ambiente de teste.
3. **Validar os totais linha a linha** contra as colunas calculadas da folha
   (Budget c/ IVA, Gastos, Lucro, Valor s/ IVA, IVA, Total). A migração só é
   aceite com números idênticos.
4. Dados que o sistema não consiga resolver sozinho (nomes repetidos, meses
   vazios, valores como texto) → lista para validação manual, nunca adivinhar.
5. Ligar as pastas antigas do Drive (incl. as associadas à mão) aos novos IDs.
6. Dia da migração final: a folha fica **só de leitura** a partir desse momento.
7. Ligar a **exportação** MySQL → folha (noturna e/ou botão «Exportar»), para
   consulta e para o contabilista. Edições na folha deixam de contar.
8. **Backup diário automático** do MySQL, com cópia no R2.

O Apps Script (`sheets/Code.gs`) e `admin/inc/sheet.php` deixam de ser usados
para ler/gravar; só a exportação escreve na folha.

---

## 6. Site público (`index.html`)

- Acrescentar **formulário de contacto** que cria uma lead com origem «Site»
  (§3.1). Manter o link de email `info@topperdigital.xyz`.
- Os números (10+, 150+, 12, 3) continuam marcados como provisórios — fora
  do âmbito desta fase.

---

## 7. Ordem de execução sugerida

1. Base: tabelas novas em MySQL (utilizadores com papéis e departamentos,
   leads/negócios num só registo com ID, funis e etapas, histórico de etapas,
   contactos, custos) + verificação de permissões no servidor.
2. Equipa (utilizadores, papéis, departamentos) — necessária para os responsáveis.
3. Leads (incl. fila «Por atribuir», arquivo, conversão).
4. Pipeline (funis configuráveis, kanban, Perdido, histórico, mudar de funil).
5. Negócios (ficha central) + Contactos (com Fornecedores).
6. Financeiro e Dashboard lidos do MySQL; Tarefas e Drive ligados por ID.
7. Migração da folha (§5) + exportação + backups.
8. Formulário do site.
9. Menu com as abas PENDENTE como páginas «Em construção».

---

## 8. Questões em aberto (não implementar sem resposta)

1. Etapa fixa **«Concluído»** no fim da produção e botão **«Cancelado»** para negócios ganhos que não avançam.
2. **Probabilidade por etapa** e previsão ponderada de faturação.
3. **Responsáveis por fase**: um só, ou comercial + gestor de projeto.
4. **Lista de funis** e **etapas variáveis** de cada um (comerciais e de produção).
5. Leads antigas em **«Perdido»** na migração: negócio perdido com ID ou lead arquivada.
6. **Permissões por aba** para chefes de equipa e colaboradores.
7. Conceito e lógica de: Orçamentação, Centro Criativo, Relatórios, Marketing,
   Centro de Conhecimento, indicadores do Dashboard, faturas/tesouraria no Financeiro.
8. Valor de X dias para o alerta de lead parada.

---

## 9. Decisões de 20 out 2026 (respondem a parte da §8)

1. **Funis** — base: VFX/3D · CGI · Campanhas · Ads · Marketing Buddy.
   Funis de cross-selling **criados na própria plataforma** quando forem
   precisos: «Novo funil» do zero ou **combinando funis existentes** (as
   etapas variáveis dos escolhidos são juntadas, sem repetir, e editadas
   antes de gravar). Cada funil é independente dos funis de onde veio.
   Os 5 funis base são criados só com as etapas fixas; as etapas variáveis
   exatas serão dadas depois. Cada etapa terá **automações de tarefas**
   (tarefas criadas ao entrar na etapa): fica a estrutura preparada, a
   lógica entra numa fase seguinte.
2. **Alerta de lead parada**: 14 dias por defeito, configurável (§8.8).
3. **Permissões** (§8.6) — duas camadas, sempre verificadas no servidor:
   - acesso por aba: sem acesso · ver · editar · gerir (gerir = apagar e configurar);
   - âmbito dos registos (Leads, Pipeline, Negócios, Tarefas): só os meus ·
     do meu departamento · todos.
   Por defeito: superadmin/admin = todos, gerir tudo; chefe de equipa =
   departamento, editar nas abas comerciais e de produção, ver Financeiro e
   Dashboard, ver Equipa; colaborador = só os seus, editar nas abas de
   trabalho, sem Financeiro, Dashboard e Equipa. Grelha papel × aba editável
   pelos admins na aba Equipa, com exceções por utilizador.
4. **Leads em «Perdido» na migração** (§8.5): passam a **leads arquivadas**
   (motivo «Perdido (migração)»). As que chegaram a ter orçamento passam a
   **negócios perdidos com ID**, perdidos na etapa **X-Ray**.
5. **«Concluído»** (§8.1): existe como etapa fixa final. Os negócios
   concluídos vão para um **separador «Concluídos» dentro do Pipeline**,
   com o funil em que foram concluídos registado. Os Trabalhos da folha
   migram como Concluídos. «Cancelado» continua PENDENTE.
6. **Utilizadores**: superadmin e admins criam utilizadores e atribuem
   papéis, departamentos e funções. **Só o superadmin** edita, desativa ou
   elimina admins; ninguém mexe no superadmin. Admins podem atribuir e
   reatribuir tarefas (e registos) a outros admins. **Eliminar = desativar**:
   a conta deixa de entrar e todo o histórico se mantém.

---

## 10. Estado da execução (20 out 2026)

Os 9 passos da §7 estão feitos e publicados. Entretanto o `main` tinha trazido a folha para o
MySQL (versão 10: `jobs`, `job_crew`, `company_costs`, `leads`, `suppliers`) e o Drive novo
(versões 8 e 9). A plataforma entra como **versão 11** por cima disso:

- **Migração (§5):** em vez de ler a folha, passa os dados da versão 10 para a estrutura nova
  (`admin/inc/platform_import.php`, página **Migração** ou `php admin/inc/cron_run.php migrate`).
  As contas de cada trabalho e de cada ano são refeitas e comparadas com as da versão 10;
  uma diferença de um cêntimo desfaz tudo. As tabelas da versão 10 ficam guardadas.
  Funil por tipo de trabalho: VFX e Edição → VFX / 3D · Animação 3D → CGI ·
  Produção Completa e Fotografia → Campanhas (pode mudar-se em cada negócio).
- **Drive:** o Drive novo (R2, assistente) não foi alterado. A permissão da aba Drive é
  verificada no `require_login()`; a pasta de cada negócio (`Projetos/<ID>_<NOME>/`) usa as
  funções do Drive a partir de `inc/deal_drive.php`. O assistente lê os dados de gestão por
  `inc/data.php`, que agora vem da estrutura nova e respeita as permissões de quem pergunta.
- **Exportação (§5.7):** em vez do espelho na folha, um botão «Descarregar Excel»
  (`admin/export.php`, só admins). A folha Google fica como arquivo.
- **Backups (§5.8):** cópia diária da base para o R2 em `.topper/backups/` (pasta de sistema,
  invisível no Drive), pelo cron `php admin/inc/cron_run.php backup`.

Continua PENDENTE tudo o que está na §8 e não foi respondido na §9.

### 10.1 Linha de design e features transversais — §11 (21 out 2026)

A §11 está aplicada em toda a zona admin (base de dados **versão 12**: `users.display_name`,
`pref_theme`, `pref_nav`; tabelas `interactions`, `notification_reads`, `password_resets`;
limites `deal_stale_days` = 10, `task_due_days` = 2, `invoice_overdue_days` = 30 em `settings`).
A lógica das abas não mudou; o Drive não foi alterado (o modo escuro chega-lhe pelos tokens do
`app.css`). Ponto a ponto:

1–2. **Modo claro/escuro** em todos os ecrãs (tokens com valor nos dois modos em `assets/app.css`),
   botão na barra de topo, escolha guardada na conta; troca em círculo a partir do clique
   (View Transitions, ~560 ms), direta sem suporte ou com `prefers-reduced-motion`.
3–4. **Dashboard:** «Bom dia / Boa tarde / Boa noite, <nome>» pela hora do browser; nome em
   **A minha conta › Definições** («Como quer ser tratado»; vazio = primeiro nome). «O teu dia»:
   tarefas a vencer, leads paradas (e fila por atribuir, para gestores), negócios sem movimento,
   valores por receber em atraso — cada coluna só com a permissão da aba.
5. **Resumo antes do detalhe:** 3–4 indicadores com um em destaque no Dashboard, Leads, Pipeline,
   Negócios, Contactos, Tarefas, Financeiro, Relatórios e Equipa.
6. **Notificações** no sino da barra de topo (`inc/alerts.php`): lead parada, negócio sem
   movimento, tarefa a vencer/atrasada, por receber em atraso, fila por atribuir. Lidas por pessoa.
7. **Painel lateral:** Leads abrem no painel (clique na linha ou no nome; `?open=<id>`); Pipeline e
   Negócios abrem a ficha completa com o menu visível. Contactos: decidido painel lateral também
   (a ficha completa abre a partir dele). Tarefas e ficheiros ficam como estão.
8. **Edição no campo** (lápis) no painel lateral e na ficha do negócio (nome, valor estimado, notas);
   lista fechada de campos por registo em `inc/inline.php`.
9. **Histórico de interações** em leads, negócios e contactos: «Registar interação» (chamada,
   email, reunião, nota) mais o automático de `deal_history` (agora também a mudança de estado da lead).
   Registar uma interação avança o «último contacto».
10. **«Anular» 8 s** em: mudar etapa (Pipeline e ficha), marcar perdido, estado e responsável da lead,
    arquivar lead, marcar pago, fechar custo, tarefa feita/movida, edição no campo. Eliminar continua a
    pedir confirmação. Mover ficheiro: o Drive já tinha o seu «Anular».
11. **Pesquisa ⌘K / Ctrl+K:** IDs, negócios, leads, contactos, tarefas, ficheiros (pelo nome, no índice
    do Drive, sem `.topper/` nem documentos privados) e «ir para»; Enter abre, ⌘/Ctrl+Enter abre no
    painel lateral. Só devolve o que a pessoa pode ver (`ui_api.php`, mesmo âmbito das abas).
12. **Vistas guardadas** «Todos / Os meus / Em risco / Este mês» em Leads, Pipeline e Negócios
    (em Negócios, «em risco» = com tarefas atrasadas); filtros aplicam logo, sem «Filtrar».
13. **Pipeline em tempo real:** arrastar (ou «Mover para», no toque) grava sem recarregar e atualiza
    colunas e indicadores; largar em Kickoff fecha o negócio com «Anular».
14. **Cartões de destaque** no Dashboard: negócios sem movimento, por receber em atraso e maior valor
    em aberto.
15. **Financeiro:** separadores novos «Por receber» (antiguidade 0–30 · 31–60 · 61–90 · 90+ e
    pré-visualização com Descarregar, Copiar link e Marcar como paga) e «Fluxo de caixa» (faturação
    por mês e acumulado contra a meta anual, editável por quem gere o Financeiro).
    **Atenção:** ainda não há faturas nem datas de pagamento (§8.7) — a pré-visualização é um
    «resumo de cobrança» interno calculado pela plataforma, a antiguidade conta da conclusão (ou do
    fim do mês do trabalho) e o fluxo usa o mês do trabalho. Quando a Orçamentação/faturação
    existir, estes três pontos passam a ler os documentos reais.
16. **Relatórios** (`relatorios.php`): entradas, conversões, ganhos, perdas e valor ganho por funil,
    por origem da lead ou por mês, num ano ou trimestre; o agrupamento muda sem sair da página.
    Valores em euros só para quem vê o Financeiro.
17. **Login** como momento de marca (fundo animado, cartão em vidro, nos dois modos) com
    «Recuperar palavra-passe»: link por email válido 30 min, só o hash guardado, uso único,
    resposta igual exista ou não o email, limite de pedidos por IP e por conta. O email sai pelo
    `mail()` do servidor, de `no-reply@topperdigital.xyz`.
18. **Menu lateral** agrupado (Geral · Comercial · Produção · Gestão), recolhível (só ícones, com
    contador em ponto pequeno), estado guardado na conta; no telemóvel abre por cima do conteúdo.
    Os contadores mostram só o que pede atenção (os mesmos alertas das notificações).
19. **Dicas** em todos os ícones (~300 ms com rato, logo com teclado, toque longo); o mesmo texto é
    o `aria-label`.
20. **Motion** 150–200 ms só a confirmar ações (cartão que muda de coluna, gravação feita, contagem
    dos números); tudo desligado com `prefers-reduced-motion`.

Ficheiros novos: `assets/app.js`, `ui_api.php`, `relatorios.php`, `documento.php` e, em `inc/`,
`ui.php`, `alerts.php`, `reach.php`, `inline.php`, `undo.php`, `interactions.php`, `panel.php`,
`billing.php`, `charts.php`, `migrate_design.php`.

### 10.2 Aba Leads v2 — §12 (21 out 2026)

A §12 está aplicada. Base de dados **versão 13** (`inc/migrate_leads_v2.php`): em `deals` os campos
`company`, `person`, `company_id`, `person_id`, `state_since`, `no_answer`, `last_contact_at`; em
`tasks` os campos `kind`, `due_time`, `duration_min`, `repeat_rule`; em `settings` os prazos
`lead_deadline_novo` = 24, `lead_deadline_sem_resposta` = 24 e `lead_deadline_follow_up` = 48.
Ponto a ponto:

- **12.0 Seta do menu:** botão redondo fixo na borda direita do menu, a meio do ecrã; aparece ao
  passar o cursor no menu e fica sempre visível (discreta) em ecrãs só de toque. `‹` aberto, `›`
  recolhido, dica «Recolher menu» / «Expandir menu», atalho `[`. Substitui o botão do rodapé.
- **12.1 Criar lead:** «+ Adicionar Lead» à direita dos separadores, atalho `N`. A animação segue a
  regra geral da §13.8: o botão transforma-se no formulário (estica da sua posição e forma até ao
  centro, o fundo escurece, o conteúdo aparece a seguir, pequeno ressalto no fim, ~350 ms) e, ao
  fechar, o conteúdo desaparece e o formulário encolhe de volta para dentro do botão, que recebe o
  foco. O mesmo em «Como correu?» (a partir do visto do Foco) e em Arquivar (a partir do botão
  Arquivar da lead, também quando se escolhe Arquivar em «Como correu?»). Com
  `prefers-reduced-motion` aparece e fecha sem movimento. Pelo menos Empresa ou Pessoa (verificado no servidor). Responsável só aparece a quem
  pode atribuir e é validado pelas regras de hierarquia. Aviso de duplicado enquanto se escreve
  telefone/email: as leads fora do alcance de quem escreve só contam («1 registo fora do seu
  alcance»), sem nome; «usar o existente» liga a pessoa que já está em Contactos. «Gravar» abre a
  lead no painel; «Gravar e adicionar outra» reabre o formulário. A empresa e a pessoa ficam em
  Contactos, reutilizadas pelo nome ou pelo mesmo email/telefone (sem duplicar), a pessoa ligada à
  empresa. O formulário no fundo da lista saiu. O formulário do site (`contacto.php`) cria da
  mesma forma (nome → pessoa, empresa → empresa; em quarentena só ao libertar).
- **12.2 Nome:** a pessoa, com a empresa em pequeno; só empresa → a empresa.
- **12.3 Contactos:** Telefone(s) (vários separados por «|»), Email e Redes sociais (um link por
  linha, ícone reconhecido: Instagram, LinkedIn, Facebook, YouTube, Vimeo, TikTok, WhatsApp, site).
  Botões Ligar (`tel:`), WhatsApp (`wa.me`) e Email, desativados quando falta o dado.
- **12.4 Estados e prazos:** Novo · Sem resposta · Follow-Up, prazo em horas seguidas 24/7 a partir
  de `state_since`. Recomeça ao mudar de estado, a cada chamada não atendida e a cada follow-up
  (resultado da chamada, ou reunião/email/visita feitos em Follow-Up). Termina ao arquivar ou
  converter. Coluna **Prazo** com anel (amarelo suave até 50 %, laranja até 100 %, vermelho depois)
  e «18 h» / «+3 h atraso», hora limite (Lisboa) na dica. Botão **Urgente** com contador vermelho;
  filtros por estado que combinam com «Urgente» e com as vistas. Contador no menu: superadmin e
  admins todas as leads em atraso, os outros só as suas. Notificações ao responsável quando a lead
  passa a «A aproximar» e a «Em atraso». Os prazos mudam-se em **A minha conta › Prazos das
  leads**, que só superadmin e admins veem (o papel é confirmado na base de dados ao gravar).
- **12.5 Painel:** largo (até 980 px) em duas colunas — dados com edição no campo e Converter /
  Arquivar fixos no fundo; Nova ação, Foco, Notas & Histórico à direita; empilhado em ecrãs
  estreitos. Setas ↑ ↓ fora do painel, em cima à esquerda, pela ordem e filtros da página atual
  da lista; teclas ↑/↓ e J/K, Esc fecha. Aberto pela pesquisa ⌘K noutra aba, o painel traz consigo
  os formulários e o `leads.js`.
- **12.6 Ficha completa:** `admin/lead.php?id=…` com o caminho Comercial / Leads / nome; «Voltar
  às leads» regressa com os mesmos filtros e à linha da lead.
- **12.7 Tarefas e histórico:** Nova ação (7 tipos, Em 1h / Em 3h / Amanhã / Próxima semana, data,
  hora, duração, para quem pelas regras de atribuição, repetir diário/semanal/mensal — a próxima
  ocorrência cria-se ao concluir); as tarefas aparecem na aba Tarefas como «Lead · nome». Foco com
  concluir e «Anular». Notas & Histórico automático + «Registar nota». Cada tipo de entrada tem
  ícone, cor (tokens dos dois modos) e dica, em todos os históricos (leads, negócios, contactos).
  Último contacto automático. Foco vazio = «Sem próxima ação» = vista **Em risco**.
- **12.8 Chamada:** a lead nova (fora da quarentena) recebe a tarefa «Ligar a …» com prazo no fim
  do prazo do estado. Concluí-la abre «Como correu?»: não atendida (Sem resposta, n.º da tentativa,
  prazo recomeça, nova chamada num clique; à 3.ª sugere arquivar com «Sem resposta»), Follow-Up
  (nota + foco na Nova ação), Arquivar, Converter. «Próxima urgente →» abre a lead em atraso há
  mais tempo dentro do alcance de quem usa.
- **12.9 Arquivar:** motivos em botões, «Outro» pede texto; aviso «Lead arquivada · Anular» (8 s).
  As tarefas abertas saem com a lead e voltam com o «Anular».
- **12.10 Lista:** Prazo · Nome · Estado · Responsável · Próxima ação · Último contacto; as notas
  ficam no painel.
- **12.11 Conhecimento › Navegação:** `admin/conhecimento.php` (deixou de ser «Em construção»); a
  lista vem de `SHORTCUTS` em `inc/ui.php`, que é onde se acrescenta um atalho novo.
- **Migração dos dados** (passo 13, automático): nome → Empresa; telefones (recuperados da tabela
  `leads` da versão 10 quando tinham sido cortados), emails e links separados; o que não é claro
  vai para as notas com «[Migração para a v2]»; Contactado e Em conversa → Follow-Up; empresas e
  pessoas criadas em Contactos sem duplicar. O prazo das leads ativas começa **no momento da
  migração** (contar desde a criação poria todas em atraso de uma vez). As leads migradas não
  recebem a chamada automática: aparecem com «Sem próxima ação» até alguém marcar a primeira.
- Também: Dashboard «Leads em atraso / a aproximar» (substitui «Leads paradas»); Excel com Empresa,
  Pessoa de contacto e os estados novos.
- **Permissões:** todas as ações (painel, Nova ação, concluir, resultado, arquivar, atribuir,
  duplicados) passam por `ui_api.php` ou `leads.php`, que confirmam a aba, o alcance da lead
  (`lead_in_reach` / `record_reach`) e as regras de atribuição (`can_assign_to`) em cada pedido.

Ficheiros novos: `lead.php`, `conhecimento.php`, `assets/leads.js`, `inc/lead_view.php`,
`inc/migrate_leads_v2.php`.

### 10.3 A plataforma em topperhub.xyz (21 out 2026)

A plataforma passou a abrir em **https://topperhub.xyz/admin/** (a raiz do domínio abre-a também).
O `topperdigital.xyz` fica como portefólio público e mantém o formulário de contacto, que continua
a criar leads na mesma base de dados.

- **No alojamento:** os dois domínios estão na mesma conta. `topperhub.xyz/public_html/admin` é
  uma ligação simbólica para `topperdigital.xyz/public_html/admin` — o mesmo código, o mesmo
  `config.php`, a mesma base de dados e as mesmas tarefas agendadas; não há cópias. A letra dos
  títulos vem por `topperhub.xyz/public_html/assets/fonts` (outra ligação simbólica).
- **Raiz do domínio** (`hub/` no repositório: `.htaccess`, `index.php`, `robots.txt`): sempre
  HTTPS e sem `www` (a sessão vale para um só endereço), a raiz e a página de omissão do
  alojamento abrem `/admin/`, nada é indexado.
- **Endereço antigo:** `topperdigital.xyz/admin/…` redireciona para `topperhub.xyz/admin/…` com o
  mesmo caminho e parâmetros (regra no `admin/.htaccess`, só para o anfitrião antigo). Os links de
  recuperação e de partilha do Drive já enviados continuam a funcionar.
- **Emails:** o link de «Recuperar palavra-passe» aponta para `topperhub.xyz`; o remetente
  continua `no-reply@topperdigital.xyz`, o domínio com o email configurado.
- **Publicar:** os ficheiros de `admin/` e do portefólio continuam a ir para `topperdigital.xyz`;
  os de `hub/` vão para a raiz de `topperhub.xyz`.
- **Drive (Cloudflare R2):** a política CORS do bucket (R2 › bucket › Settings › CORS Policy)
  permite `https://topperhub.xyz` e `https://topperdigital.xyz`; confirmado com pedidos de teste
  (PUT e GET autorizados para os dois, recusados para outra origem). Sem isto o browser recusaria
  os envios para o Drive a partir do domínio novo.
- Cada pessoa tem de entrar outra vez uma vez (a sessão está presa ao endereço).

---

## 11. Linha de design e features transversais (decidido 21 out 2026)

Referências analisadas: 4 vídeos (dashboards CRM Code & Chill, login em vidro e
Dashboard V14 de code.xr). Interessam as **funcionalidades e o fluxo**, não as cores.
Proposta visual de referência: https://claude.ai/artifact/TVFbyWNwB3HjfaKR7d2RLB

**Princípio:** a plataforma é para trabalhar — fluida, simples, intuitiva, eficiente.
Nada de efeitos que atrasem uma ação. Identidade topper.: Futura nos títulos,
monoespaçada em IDs/valores/datas, amarelo como sinal (aba ativa, ação principal,
número em destaque) e nunca como fundo de áreas grandes; azul, céu e coral como
cores de estado.

**Implementar já em toda a plataforma** (cada aba adapta quando for desenhada a fundo):

1. **Modo claro e modo escuro, sempre os dois.** Botão na barra de topo; escolha
   guardada por utilizador (na conta, não só no browser). Todos os componentes
   usam tokens de cor com valores para os dois modos.
2. **Transição ao mudar de modo** (como no vídeo Dashboard V14): o novo modo
   cresce num **círculo a partir do botão clicado** até cobrir o ecrã (~500–600 ms,
   ease-out). Usar a View Transitions API (`document.startViewTransition` +
   `clip-path: circle()` a partir das coordenadas do clique); sem suporte ou com
   `prefers-reduced-motion`, troca direta sem animação.
3. **Saudação personalizada** no Dashboard: «Bom dia / Boa tarde / Boa noite, <nome>».
   Bom dia 06:00–12:59 · Boa tarde 13:00–19:59 · Boa noite 20:00–05:59, pela hora
   do browser do utilizador. Nome = campo **«Como quer ser tratado»** da conta;
   se vazio, o primeiro nome do utilizador. Cada utilizador edita-o em
   **A minha conta › Definições**.
4. **«O teu dia»** por baixo da saudação: o que é do utilizador hoje — tarefas a
   vencer, leads paradas, negócios sem movimento, faturas em atraso (conforme as
   permissões dele).
5. **Resumo antes do detalhe:** cada aba abre com 3–4 indicadores; um em destaque.
6. **Alertas e notificações:** centro de notificações na barra de topo. O alerta
   de lead parada passa a valer também para negócios sem movimento no Pipeline,
   tarefas a vencer e faturas em atraso.
7. **Painel lateral de detalhe:** clicar num registo de uma lista abre à direita
   um resumo editável com a próxima ação, sem sair da lista; a página completa
   abre-se a partir dele. Regra por aba (detalhe fica para o desenho de cada aba):
   - **Leads:** abrir uma lead → painel lateral.
   - **Pipeline** e **Negócios:** abrir um negócio → **página completa** (ficha do
     negócio), mantendo o menu lateral das abas visível.
   - Restantes listas (contactos, tarefas, ficheiros): definido ao desenhar cada aba.
8. **Edição no próprio campo** (ícone de lápis) nas fichas e no painel lateral.
9. **Histórico de interações** em leads, negócios e contactos: chamadas, emails,
   reuniões, mudanças de etapa, orçamentos — quem, quando, o quê. Registo manual
   («Registar interação») + automático para ações do sistema.
10. **«Anular» em vez de «Tens a certeza?»** em ações reversíveis (mudar etapa,
    arquivar, atribuir, marcar pago/feito, mover ficheiro): a ação faz-se logo e
    aparece um aviso em baixo com «Anular» durante 8 s. Eliminar continua a pedir
    confirmação.
11. **Pesquisa global ⌘K / Ctrl+K:** procura IDs, negócios, leads, contactos,
    ficheiros e tarefas (respeitando permissões) e permite navegar («ir para
    Financeiro»). Enter abre; ⌘Enter abre no painel lateral.
12. **Vistas guardadas** em vez de filtros com botão «Filtrar»: separadores como
    «Os meus», «Em risco», «Este mês», mais filtros que aplicam logo.
13. **Totais em tempo real:** mover/ganhar um negócio atualiza logo os totais da
    página, sem recarregar.
14. **Cartões de destaque** no Dashboard: negócios que pedem atenção agora.
15. **Financeiro:** pré-visualização de faturas/orçamentos (Descarregar, Copiar
    link, Marcar como paga); faturas por receber por antiguidade
    (0–30 · 31–60 · 61–90 · 90+ dias); fluxo de caixa mensal contra a meta anual.
16. **Relatórios por segmento** (funil, origem da lead, período) mudando o
    agrupamento sem sair da página.
17. **Login como único momento de marca:** cartão sobre fundo animado da topper.
    (3D/motion só aqui), transição suave entre Entrar e Recuperar palavra-passe.
18. **Menu lateral agrupado e recolhível** com contadores e alertas: Geral
    (Dashboard) · Comercial (Leads, Pipeline, Contactos, Marketing) · Produção
    (Negócios, Orçamentação, Centro Criativo, Tarefas, Drive) · Gestão (Financeiro,
    Relatórios, Equipa, Conhecimento, Tools).
    - Botão para recolher/expandir. **Recolhido:** só ícones, no lugar dos nomes;
      contadores passam a um ponto/número pequeno sobre o ícone.
    - Estado (recolhido/expandido) guardado por utilizador.
    - Em ecrãs estreitos (telemóvel) o menu fica escondido e abre por cima do conteúdo.
19. **Dicas (tooltips) em todos os ícones — regra geral da plataforma:** qualquer
    ícone, seja botão ou apenas informativo (menu recolhido, ações, estados,
    indicadores), mostra o seu nome ao passar o cursor — rato, touchpad ou
    trackpad do iPad. Aparece após ~300 ms, desaparece ao sair. Em ecrãs só de
    toque, aparece com toque longo; também aparece ao receber foco pelo teclado.
    O mesmo texto serve de nome acessível (`aria-label`) do ícone. Nenhum ícone
    sem nome.
20. **Motion:** 150–200 ms, só para confirmar ações (cartão que muda de coluna,
    gravação feita, contagem de números). Respeitar `prefers-reduced-motion`.

21. **Profundidade e degradês (sem exageros):** painéis, fichas e cartões deixam de ser
    branco/preto lisos. Tudo a partir da paleta topper. e em tokens com valor para os
    dois modos:
    - **Fundo da área de trabalho:** brilho radial muito suave de céu (canto superior
      direito) e coral (canto inferior esquerdo) sobre o off-white / quase-preto.
    - **Painéis laterais e fichas:** degradê vertical subtil (branco → off-white; grafite →
      quase-preto) + brilho de céu/azul no canto superior direito.
    - **Cabeçalho da ficha:** faixa com degradê céu → azul transparente por trás do nome.
    - **Cartões e blocos:** degradê de 2–3% + linha de luz no topo (`inset 0 1px`) + sombra
      suave em duas camadas, para dar sensação de relevo.
    - **Amarelo (ação principal, indicador em destaque, separador ativo):** degradê
      #FFD84F → #FFC718 → #F2B300 com reflexo no topo.
    - **Botões escuros:** degradê grafite com reflexo; no modo escuro, o inverso.
    - **Menu lateral:** degradê vertical grafite → preto; item ativo com brilho amarelo.
    - Regras: no máximo 2 camadas de degradê por elemento; nunca por trás de tabelas
      densas ou texto longo; contraste do texto mantém-se AA; sem animação nos degradês.

Fora: navegação em pílulas no topo (não serve para 15 abas), recompensas, mensagens.

---

## 12. Aba Leads v2 (decidido 21 out 2026)

Substitui o que contradiga a §3.1. Aplica também a §11 (design transversal).

### 12.0 Menu lateral — seta de recolher/expandir
Ao passar o cursor na barra lateral aparece uma seta pequena na **borda direita, a meio
da altura do ecrã, fixa** (não acompanha o scroll da barra): `‹` com o menu aberto, `›`
recolhido. Tem dica («Recolher menu» / «Expandir menu»). Atalho `[`. Em ecrãs só de
toque a seta está sempre visível (discreta).

### 12.1 Criar lead
- Botão **«+ Adicionar Lead»** na linha dos separadores (Ativas · Por atribuir ·
  Arquivadas · Quarentena), encostado à direita. O formulário no fundo da página desaparece.
- Abre um formulário que **cresce a partir do botão** até ao centro (~250 ms, fundo
  escurece); ao fechar volta ao botão. Atalho `N`.
- Campos: **Empresa** (procura existentes ou cria; opcional) · **Pessoa de contacto** ·
  **Telefone(s)** · **Email** · **Redes sociais** (links; ícone reconhecido automaticamente) ·
  Tipo · Serviço · Origem · Notas · **Responsável** (só para quem pode atribuir).
  Pelo menos Empresa ou Pessoa preenchido.
- Aviso de duplicado enquanto se escreve telefone/email, com opção de usar o existente.
- Botões «Gravar» e «Gravar e adicionar outra».
- Ao gravar: a pessoa é criada (ou reutilizada) na aba **Contactos**, ligada à empresa
  quando indicada; a empresa também fica registada.

### 12.2 Nome apresentado
- Só empresa → mostra a empresa.
- Com pessoa de contacto → mostra **sempre a pessoa**; se houver empresa, aparece em
  pequeno ao lado/abaixo do nome.
- Migração das leads atuais: o «nome» atual passa a Empresa; pessoa fica por preencher.

### 12.3 Contactos separados
Telefone(s), Email e Redes sociais são campos distintos. Botões **Ligar** (`tel:`),
**WhatsApp** e **Email** junto aos campos. Migração: números → telefone, endereços com @ →
email, links → redes sociais; o que não for claro vai para as notas.

### 12.4 Estados e prazo (timer)
- **Estados da lead:** **Novo** (nunca contactada) · **Sem resposta** (última chamada não
  atendida) · **Follow-Up** (houve conversa, há passo seguinte). Substituem os estados
  anteriores: na migração, Contactado e Em conversa passam a **Follow-Up**.
- **Prazo em horas seguidas (24/7, sem pausas).** Cada estado tem o seu prazo, editável
  em **Definições** só por superadmin e admins; a alteração vale **para toda a plataforma**:
  | Estado | Prazo por defeito | Recomeça quando |
  |---|---|---|
  | Novo | 24 h | — (conta desde a criação) |
  | Sem resposta | 24 h | cada chamada não atendida registada |
  | Follow-Up | 48 h | cada follow-up registado |
- O prazo **só termina** quando a lead é **arquivada** ou **convertida**.
- Mudar de estado recomeça o prazo com o valor do novo estado. Alterar um prazo nas
  Definições aplica-se a todas as leads ativas (os indicadores são recalculados).
- Indicador por percentagem do prazo do estado atual: 0–50% **No prazo** (amarelo suave,
  só no indicador) · 50–100% **A aproximar** (laranja) · >100% **Em atraso** (vermelho).
- Coluna **«Prazo»** à esquerda do Nome: anel que enche + tempo restante («18 h» /
  «+3 h atraso»); dica com a hora limite. (O campo «Prioridade» Alta/Média/Baixa mantém-se.)
- Botão **«Urgente»** junto às vistas, com contador vermelho das leads em atraso; filtra-as.
- **Filtro por estado** em botões rápidos (Novo · Sem resposta · Follow-Up), para quem
  está a tratar só de um tipo (ex.: só follow-ups). Combina com «Urgente» e com as vistas.
- Contador de leads em atraso junto a **Leads** no menu lateral: total para superadmin e
  admins; para os outros, só as suas.
- Notificações quando uma lead passa a «A aproximar» e a «Em atraso».

### 12.5 Painel lateral da lead
- Abre à direita, **mais largo e em duas colunas**:
  - esquerda: dados da lead e do contacto (edição no campo), botões **Converter em
    negócio** e **Arquivar** fixos no fundo;
  - direita: **Nova ação** · **Foco** · **Notas & Histórico** (12.7).
  - ecrãs estreitos: colunas empilhadas.
- Setas `↑ ↓` pequenas **fora do painel, no canto superior esquerdo**, para passar à lead
  de cima/baixo **na ordem e filtros atuais** da lista. Teclas `↑/↓` (ou `J/K`); `Esc` fecha.

### 12.6 Ficha completa
«Abrir a ficha completa» abre uma **página própria da lead** (ex.: `admin/lead.php?id=…`)
com o menu lateral e o caminho Comercial / Leads / <nome>. «Voltar» regressa à lista com
os mesmos filtros e posição. Acaba a ficha no fundo da página da lista.

### 12.7 Tarefas, Foco e Histórico
- **Nova ação:** tipo por ícones (Chamada, Reunião, Tarefa, Documento, Email, Visita,
  Evento) · notas · atalhos Em 1h / Em 3h / Amanhã / Próxima semana · data, hora, duração ·
  para quem (regras de atribuição) · repetir · «Criar tarefa». As tarefas aparecem também
  na aba Tarefas, ligadas à lead.
- **Foco:** tarefas abertas da lead, por prazo, com botão concluir.
- **Notas & Histórico:** linha do tempo automática de tudo o que acontece (criada,
  atribuída, estado, tarefa criada/concluída, chamada atendida/não atendida com nº da
  tentativa, arquivada com motivo, convertida) + notas manuais («Registar nota»); data,
  hora e autor em cada entrada.
- **Cada tipo de entrada do histórico tem ícone e cor próprios**; ao passar o cursor
  (ou toque longo) sobre o ícone aparece o nome do tipo. Aplica-se a todos os históricos
  da plataforma (leads, negócios, contactos). Cores sempre a partir dos tokens dos dois modos:
  | Tipo | Ícone | Cor |
  |---|---|---|
  | Lead criada | estrela/+ | neutro |
  | Atribuída / reatribuída | pessoa com seta | céu |
  | Mudança de estado | setas | neutro |
  | Chamada atendida (Follow-Up) | telefone | azul |
  | Chamada não atendida | telefone cortado | laranja |
  | Email | envelope | azul |
  | Reunião / visita | pessoas / pin | azul |
  | Tarefa criada | caixa vazia | neutro |
  | Tarefa concluída | caixa com visto | verde |
  | Nota manual | balão | amarelo suave |
  | Arquivada | caixa de arquivo | vermelho |
  | Reativada | seta circular | céu |
  | Convertida em negócio | seta para a direita | amarelo |
  | Prazo em atraso | relógio | vermelho |
  Novos tipos que surjam seguem a mesma regra (ícone + cor + nome na dica).
- **Último contacto** passa a ser automático, a partir do histórico.
- **Sem próxima ação:** lead ativa com Foco vazio mostra «Sem próxima ação» e conta como
  **Em risco** nas vistas.

### 12.8 Chamada automática e resultado
- Ao criar a lead (estado **Novo**) é criada a tarefa **«Ligar a <pessoa/empresa>»** em
  Foco, com prazo igual ao fim do prazo do estado.
- Ao concluí-la abre **«Como correu?»** (animação de surgimento) com 4 botões:
  1. **Chamada não atendida** — estado passa a **Sem resposta**, regista a tentativa (nº),
     recomeça o prazo (24 h) e propõe a próxima chamada num clique (ex.: em 3h). À
     **3.ª tentativa** sem resposta sugere arquivar com motivo «Sem resposta».
  2. **Follow-Up** — nota rápida da conversa + abre Nova ação para o passo seguinte;
     estado passa a **Follow-Up** e o prazo recomeça (48 h).
  3. **Arquivar** — abre o formulário de motivo (12.9).
  4. **Converter em Negócio** — conversão existente (funil, ID, X-Ray).
- Depois do resultado, botão **«Próxima urgente →»** abre a lead em atraso seguinte.

### 12.9 Arquivar
Formulário com animação de surgimento e motivos em botões: Sem interesse · Sem resposta ·
Fora de perfil · Spam · Outro (pede texto). Depois aviso «Lead arquivada · Anular» (8 s).

### 12.10 Lista
Colunas: **Prazo** · Nome (pessoa + empresa em pequeno) · Estado · Responsável ·
Próxima ação · Último contacto. A coluna Notas sai da lista (fica no painel).

### 12.11 Aba Conhecimento — separador «Navegação»
Criar o separador **Navegação** na aba Centro de Conhecimento com a lista de atalhos de
teclado e gestos da plataforma: `⌘K/Ctrl+K` pesquisa · `[` recolher/expandir menu ·
`N` nova lead · `↑/↓` ou `J/K` lead anterior/seguinte no painel · `Esc` fechar painel ou
formulário · toque longo = ver nome de um ícone. Atualizar sempre que surgir um atalho novo.

---

## 13. Aba Contactos v2 (decidido 21 out 2026)

Substitui o que contradiga a §3.4. Aplica a §11 (design transversal) e o padrão das Leads
(painel lateral, histórico com ícones/cores, «Anular»).

### 13.1 Modelo: Pessoa e Empresa
- **Empresa:** nome, NIF, site/redes, morada, tipos (cliente · fornecedor · parceiro).
- **Pessoa:** nome, cargo, telefone(s), email, redes sociais, empresa (opcional), tipos
  (cliente · fornecedor · freelancer · parceiro). Uma empresa tem **várias pessoas**; se a
  pessoa muda de empresa fica o histórico. Freelancer = pessoa sem empresa.
- Migração: cada contacto atual vira Empresa (se tiver «Pessoa de contacto», cria também a
  Pessoa ligada). Contactos criados pelas Leads (§12.1) usam este modelo.

### 13.2 Separadores e criação
- **Todos** abre por defeito (pessoas, com a empresa em pequeno). Depois: Clientes ·
  Fornecedores · Freelancers · Parceiros · **Empresas** · **Relações**.
- **Empresas:** lista com nº de pessoas; abrir mostra todas as pessoas (telefones, emails)
  e os negócios da empresa.
- Botão **«+ Adicionar contacto»** na linha dos separadores (o formulário no fundo da
  página desaparece), com a animação da §13.8.

### 13.3 Serviços e preçário (fornecedores e freelancers)
- Ao marcar fornecedor/freelancer aparece, em destaque, **«Serviços e preços»**: linhas com
  **Serviço** (lista comum) · **Unidade** (hora, dia, meio dia, projeto, segundo de animação,
  peça) · **Preço s/ IVA** · cobra IVA (sim/não) · notas · válido desde. Histórico de preços.
- **Lista de serviços** gerida por admins em Definições. Inicial: **3D Animation · 2D
  Animation · VFX · CGI · Edição · Compositing**.
- **Estado Ativo / Inativo** do fornecedor/freelancer, alterado **só por superadmin e
  admins**. Só os **ativos** aparecem como disponíveis na calculadora da Orçamentação.
- Orçamentação (a desenhar): ao escolher um serviço, a calculadora lista os fornecedores
  ativos que o prestam, com preço, unidade e avaliação.
- **Avaliação por trabalho:** quando um custo de fornecedor é marcado como pago no
  Financeiro, pede 1–5 estrelas; a avaliação do fornecedor é a média real.

### 13.4 Fontes de negócio
- Cada lead e negócio tem **«Trazido por»** (pessoa ou empresa externa que originou o
  negócio — ex.: agência que traz o cliente final), separado do **Cliente** (quem paga) e
  do **Criado por** (utilizador da plataforma, automático).
- Ficha do contacto: bloco **«Fontes de negócio»** com leads ativas, negócios no Pipeline,
  concluídos e perdidos que vieram dele (estado, valor, cliente final); abrir mostra o
  histórico. Totais no topo: nº gerado, faturado, taxa de conversão.

### 13.5 Relações (nurturing)
- Botão **«Acompanhar relação»** na ficha pede: **Formalidade** (Próxima · Cordial ·
  Formal) · **Potencial** (1–5) · **Tipo de negócio** (Corporate · Médio · Pequeno) e o
  responsável da relação.
- **Nível automático** A/B/C a partir dessas classificações; cadência por nível em
  Definições (admins, global): **A 7 dias · B 15 · C 30**; exceção por contacto possível.
- Na data, cria a tarefa **«Follow-up de relação: <nome>»** para o responsável. Ao
  concluir, «Como correu?»: **Falámos** (nota + próxima data) · **Não atendeu** (nova
  tentativa em 2 dias) · **Reunião marcada** (cria tarefa) · **Gerou uma lead** (abre
  «+ Adicionar Lead» com «Trazido por» preenchido).
- Separador **Relações:** nível, **saúde** (anel: dias desde o último toque vs cadência),
  próxima data, valor gerado; filtros Em atraso · Esta semana · Por nível.
- **Revisão de nível a cada 6 meses** (o sistema propõe subir/descer conforme negócios
  gerados) · **variação do toque** (sugere enviar projeto novo, convite para café/almoço,
  aniversário se houver data) · **Pausar relação** com data de regresso.

### 13.6 Outras
- **Juntar duplicados** (mesmo telefone/email), mantendo o histórico dos dois.
- **Importar** CSV e vCard, com deteção de duplicados.
- Painel lateral, histórico com ícones/cores e «Anular» como nas Leads.

### 13.7 Ficha do contacto (painel lateral → ficha completa)
Dados · empresa/pessoas ligadas · Serviços e preços (se aplicável) · Fontes de negócio ·
Relação (se acompanhada) · Nova ação / Foco / Notas & Histórico.

### 13.8 Animação dos formulários de criação (regra geral, substitui a da §12.1)
Todos os «+ Adicionar …»: **o botão transforma-se no formulário** — estica a partir da sua
posição e forma (como a sair de um funil) até ao centro; o conteúdo aparece a seguir;
pequeno ressalto no fim (~350 ms). **Ao fechar, o inverso:** conteúdo desaparece, o
formulário encolhe de volta para dentro do botão. `prefers-reduced-motion`: aparece sem
movimento. O mesmo vale para os formulários «Como correu?» e Arquivar, a partir do botão
que os abre.
