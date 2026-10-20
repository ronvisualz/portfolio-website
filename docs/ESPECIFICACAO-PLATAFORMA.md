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

## 0.1 Ponto de partida — o que já está implementado vs. o que falta

Levantamento do código em 20 out 2026 (ramo principal, base na versão 7).
**Tudo o que está na coluna «Falta» foi decidido nas sessões de conceção e
ainda não existe no código.**

| Área | Já implementado (código atual) | Falta (decidido, por implementar) |
|---|---|---|
| **Fonte de dados** | Folha Google via Apps Script (`sheets/Code.gs`, `admin/inc/sheet.php`) para Painel, Carteira, Leads e Fornecedores; MySQL só para utilizadores, login, configurações e Calendário. Tabelas antigas `projects`, `crew`, `company_costs`, `leads` existem sem uso. | MySQL como fonte única; tabelas novas; migração da folha; folha só como exportação; backup diário (§5) |
| **Menu** | Painel · Calendário · Leads · Fornecedores · Drive · Tools (`admin/inc/layout.php`) | Novo menu de 15 abas (§1); páginas «Em construção» para as PENDENTE |
| **Utilizadores e papéis** | Tabela `users` com campo `role`; o código só conhece `admin` (`is_admin()` em `bootstrap.php`). Membros da equipa (`team_members`: nome, cor) são **separados** dos utilizadores e não fazem login. | Superadmin (`info@topperdigital.xyz`), Admin, Chefe de equipa, Colaborador; departamentos; membros = utilizadores; verificação de permissões e visibilidade no servidor (§3.9) |
| **ID do negócio** | Não existe. Trabalhos identificados pelo **nome** (chave na folha; equipa e tarefas ligam-se pelo nome). | ID `ID2610-01` atribuído na conversão, imutável; tudo ligado por ID (§2.1) |
| **Leads** | Lista com contagens, filtros (texto, fase, tipo, prioridade), fases Novo/Contactado/Em conversa/Proposta enviada/Ganho/Perdido; mudar fase atualiza o último contacto; criar/editar/eliminar. Sem responsável, sem origem. | Origem + campanha; fila «Por atribuir»; responsável com regras de hierarquia; visibilidade por papel; estados reduzidos a Novo/Contactado/Em conversa; Converter (funil, ID, Contacto, X-Ray); Arquivar/reativar com motivo; duplicados; alerta de lead parada (§3.1) |
| **Formulário no site** | Não existe; só o link de email. | Formulário que cria lead «Site», com anti-spam (§6) |
| **Anúncios** | Não existe. | Estrutura para webhooks Meta/LinkedIn (fase posterior) |
| **Pipeline / funis** | Não existe. | Tudo (§3.2) |
| **Negócios** | Carteira › Trabalhos (no Painel) + Ficha do trabalho (`project.php`): editar, gastos soma/manual, custos de equipa, pasta no Drive. | Aba própria; ficha central agregando tudo pelo ID; etapas de produção (§3.3) |
| **Contactos** | Só Fornecedores (aba própria, na folha). | Aba Contactos com tipos; Fornecedores passam a separador; criação na conversão (§3.4) |
| **Tarefas** | Calendário em MySQL: Mês, kanban por pessoa, arrastar e largar, Equipa com cor (`calendar.php`, `tasks_api.php`). Trabalho ligado pelo nome vindo da folha. | Renomear para Tarefas; ligar por ID do negócio; membros = utilizadores (§3.5) |
| **Drive** | Completo em R2 (`drive.php`, `drive_api.php`, `inc/r2.php`); pastas `Projetos/NN_NOME_PROJECT/` com aliases manuais. | Pastas `Projetos/<ID>_<NOME>/` encontradas por ID (§2.2) |
| **Financeiro** | Carteira › Custos e IVA no Painel; cálculos feitos pelas fórmulas da folha. | Aba própria; cálculos pelo sistema com resultados idênticos (§3.7) |
| **Dashboard** | Painel: 3 cartões + seletor de ano + Atualizar. | Ler do MySQL; Carteira sai daqui (§3.8) |
| **Segurança** | Completa (§4). | Permissões por papel/aba no servidor |
| **Orçamentação, Centro Criativo, Relatórios, Marketing, Centro de Conhecimento** | Não existem. | Só a entrada no menu, por agora (PENDENTE) |
| **Tools** | Link para o Render farm. | Nada |

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
