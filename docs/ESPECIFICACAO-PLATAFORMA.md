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
| 6 | Orçamentação | Criação e gestão de orçamentos | Novo — decidido na §14, aplicado (§10.6) |
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
- **12.5 Painel:** largo (até 980 px) em duas colunas — dados e Converter / Arquivar fixos no
  fundo; **«Editar»** (ou a tecla `E`) troca os dados por um formulário com todos os campos e
  **«Gravar»** (ou ⌘/Ctrl+Enter) grava-os de uma vez (`ui_api.php?a=lead_save`): mesmas regras e
  permissões da edição no campo, tudo ou nada, um só «Anular»; Esc cancela; Nova ação, Foco, Notas & Histórico à direita; empilhado em ecrãs
  estreitos. Setas ↑ ↓ fora do painel, em cima à esquerda, pela ordem e filtros da página atual
  da lista; teclas ↑/↓ e J/K, Esc fecha. Aberto pela pesquisa ⌘K noutra aba, o painel traz consigo
  os formulários e o `leads.js`.
- **12.6 Ficha completa:** `admin/lead.php?id=…` com o caminho Comercial / Leads / nome; «Voltar
  às leads» regressa com os mesmos filtros e à linha da lead.
- **12.7 Tarefas e histórico:** Nova ação (8 tipos: Chamada, Reunião, Tarefa, Documento, Email,
  Almoço, Jantar, Evento — base de dados **versão 14**: as tarefas «Visita» passaram a Reunião;
  reunião, email, almoço e jantar feitos contam como contacto; Em 1h / Em 3h / Amanhã / Próxima semana, data,
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
  migração** (contar desde a criação poria todas em atraso de uma vez). Depois da migração
  foi criada, a pedido, a chamada «Ligar a …» em cada uma das 111 leads migradas (para o
  responsável, com prazo no fim do prazo do estado), como numa lead nova.
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
  recuperação e de partilha do Drive já enviados continuam a funcionar. Só as páginas abertas
  são redirecionadas: um envio (gravar um formulário, mudar o modo, qualquer ação) feito num
  separador antigo recebe a explicação «A plataforma mudou-se para topperhub.xyz… nada foi
  gravado», em vez de os dados se perderem no redirecionamento (`inc/bootstrap.php`). Foi o que
  aconteceu a 21 out: num separador antigo o modo escuro não ficou guardado e a lead nova não foi
  criada. O botão de modo passou também a repor o modo anterior se a escolha não ficar guardada.
- **Emails:** o link de «Recuperar palavra-passe» aponta para `topperhub.xyz`; o remetente
  continua `no-reply@topperdigital.xyz`, o domínio com o email configurado.
- **Publicar:** os ficheiros de `admin/` e do portefólio continuam a ir para `topperdigital.xyz`;
  os de `hub/` vão para a raiz de `topperhub.xyz`.
- **Drive (Cloudflare R2):** a política CORS do bucket (R2 › bucket › Settings › CORS Policy)
  permite `https://topperhub.xyz` e `https://topperdigital.xyz`; confirmado com pedidos de teste
  (PUT e GET autorizados para os dois, recusados para outra origem). Sem isto o browser recusaria
  os envios para o Drive a partir do domínio novo.
- Cada pessoa tem de entrar outra vez uma vez (a sessão está presa ao endereço).

### 10.4 Aba Contactos v2 — §13 (21 out 2026)

A §13 está aplicada. Base de dados **versão 15** (`inc/migrate_contacts_v2.php`): em `contacts` os
campos `role` (cargo), `links` (site e redes), `address`, `is_active`, `birthday` e telefone até 255
caracteres (vários); em `deals` o campo `brought_by_id` («Trazido por»); em `tasks` o campo
`contact_id`; tabelas novas `contact_history`, `services`, `contact_services` (linhas de preço com
histórico), `contact_ratings` (uma avaliação por custo) e `relations`; em `settings` as cadências
`rel_cadence_a` = 7, `rel_cadence_b` = 15 e `rel_cadence_c` = 30. A lógica está em
`inc/contacts.php`; a vista do contacto em `inc/contact_view.php`. Ponto a ponto:

- **13.1 Modelo:** Pessoa (nome, cargo, telefone(s), email, site e redes, NIF, morada, aniversário,
  empresa opcional; tipos Cliente · Fornecedor · Freelancer · Parceiro) e Empresa (nome, NIF, site e
  redes, morada, telefone, email; tipos Cliente · Fornecedor · Parceiro). Uma empresa tem várias
  pessoas; mudar a pessoa de empresa fica no histórico. Freelancer = pessoa sem empresa (confirmado
  no servidor). Uma pessoa conta também os tipos da empresa (menos Freelancer). As leads criam a
  empresa e a pessoa neste modelo (§12.1), com entrada no histórico do contacto. Pessoa ↔ Empresa
  pode trocar-se em «Editar» (uma empresa com pessoas continua empresa).
- **13.2 Separadores:** Todos (por defeito: pessoas com a empresa em pequeno, empresas com
  «Empresa») · Clientes · Fornecedores · Freelancers · Parceiros · Empresas (cartões com tipos,
  n.º de pessoas, negócios e fontes, e as pessoas com cargo, telefone e email) · Relações. Clientes
  e Parceiros contam os tipos herdados da empresa; Fornecedores e Freelancers só os próprios (é aí
  que vivem os preços). «+ Adicionar contacto» (tecla `N`) e «Importar» na linha dos separadores,
  com a animação da §13.8; o formulário no fundo da página saiu. Indicadores: relações em atraso,
  pessoas e empresas, fornecedores ativos, novos este mês. Em Todos, o filtro «Possíveis duplicados».
- **13.3 Serviços e preços:** ao marcar Fornecedor ou Freelancer aparece, em destaque, «Serviços e
  preços» — no formulário de criação e na ficha: Serviço (lista comum) · Unidade (hora, dia, meio
  dia, projeto, segundo de animação, peça) · Preço s/ IVA (aceita «1 500», «1.500», «1.500,50»,
  «32,5»; vazio = «por rever») · cobra IVA · notas · válido desde. Mudar o preço, a unidade, o IVA
  ou o serviço guarda a linha antiga em «Histórico de preços» e abre outra; tirar uma linha tem
  «Anular». **Lista de serviços** em A minha conta › Serviços (só superadmin e admins): nome, ordem,
  ativo e acrescentar; não se apagam (os preços apontam para eles). **Ativo / Inativo** só para
  superadmin e admins (papel confirmado na base de dados em cada pedido; os outros veem o estado sem
  o poder mudar), com «Anular». Para a calculadora da Orçamentação (§14, aplicada na §10.6) fica pronta
  `suppliers_for_service()`: só fornecedores/freelancers ativos, com preço em vigor, unidade e
  avaliação média. **Avaliação por trabalho:** ao fechar (= pago) um custo de fornecedor ou
  freelancer na ficha do negócio — na lista ou ao gravar o custo — aparece «Como correu o
  trabalho?» com 1–5 estrelas (ou «Agora não»); uma avaliação por custo; a avaliação do contacto é
  a média real, nas listas e na ficha. Os custos gerais do Financeiro (sem negócio) não têm contacto.
- **13.4 Fontes de negócio:** «Trazido por» em cada lead e negócio, separado do Cliente (quem paga,
  `contact_id`) e do Criado por (o utilizador, automático): no formulário da lead nova, no «Editar»
  da lead (com «Anular»), na ficha da lead (com «Criada … por …»), na ficha do negócio (Cliente ·
  Trazido por · Criado por; muda-o quem edita o negócio, com «Anular») e no painel do negócio. O
  campo sugere a partir de Contactos enquanto se escreve; sem JavaScript aceita o nome exato. Fica no
  histórico da lead/negócio e no do contacto. Na ficha do contacto, «Fontes de negócio» lista as
  leads e negócios que vieram dele — de uma pessoa contam também os da empresa, de uma empresa os das
  suas pessoas — com cliente, estado e valor; abrir uma linha mostra essa lead ou negócio no painel.
  Totais: gerados, faturado (budget s/ IVA dos ganhos) e conversão (ganhos ÷ ganhos + perdidos +
  leads arquivadas). Só entra o que quem vê pode ver. «+ Nova lead trazida por …» abre a lead nova
  já preenchida.
- **13.5 Relações:** «Acompanhar relação» na ficha: Formalidade · Potencial 1–5 · Tipo de negócio ·
  responsável (pelas regras de atribuição) e exceção de cadência opcional. **Nível automático:**
  pontos = potencial × 2 + tipo (Corporate 3 · Médio 2 · Pequeno 1) + formalidade (Próxima 2 ·
  Cordial 1 · Formal 0); **A ≥ 11 · B ≥ 7 · C abaixo** — o formulário mostra o nível e a cadência
  enquanto se escolhe. Cadências em A minha conta › Relações (só admins, globais). Na data, a tarefa
  «Follow-up de relação: <nome>» (ícone de coração, com uma sugestão de toque) para o responsável,
  uma de cada vez — criada ao abrir qualquer página (com as notificações), e também pela tarefa
  agendada da noite (`cron_run.php backup`, ou só `cron_run.php relations`). Concluí-la conta como
  toque e abre «Como correu?»: **Falámos** (nota + próxima data) · **Não atendeu** (o toque não conta,
  nova tentativa em 2 dias) · **Reunião marcada** (cria a tarefa da reunião) · **Gerou uma lead** (abre
  «+ Adicionar Lead» com «Trazido por» preenchido). Chamadas, reuniões, emails, almoços e jantares
  feitos para o contacto, e chamadas/emails/reuniões registados, contam também como toque.
  Separador **Relações:** nível, saúde (anel: tempo desde o último toque face à próxima data), próxima
  data, valor gerado (faturado) e responsável; filtros Em atraso (com contador) · Todas · Esta semana ·
  Nível A/B/C · Pausadas. **Revisão a cada 6 meses** na ficha: propõe subir o potencial (≥ 1 negócio
  ganho ou ≥ 2 gerados nesse tempo), descer (nenhum) ou manter — Aceitar / Manter. **Variação do
  toque:** uma sugestão diferente a cada follow-up (projeto novo, café, novidade, almoço, perguntar
  pelos projetos) e o aniversário quando faltam 14 dias ou menos. **Pausar** com data de regresso (o
  follow-up aberto sai e volta nesse dia), Retomar e Deixar de acompanhar — tudo com «Anular».
- **13.6 Duplicados e importar:** a ficha avisa quando outro contacto do mesmo tipo tem o mesmo email
  ou telefone; **«Juntar»** (quem gere Contactos, com confirmação) passa negócios, leads, custos,
  tarefas, serviços, avaliações, pessoas, relação e o histórico dos dois para o que fica e preenche
  os campos vazios; não se anula. O formulário de criação avisa enquanto se escreve (nome, email,
  telefone). **Importar** CSV (cabeçalho em português ou inglês, «;» ou «,») ou vCard: mostra o que
  encontrou antes de gravar; quem já existe (mesmo email, telefone ou nome) fica por defeito
  «Completar» — só preenche o que falta e junta telefones e redes, nunca apaga —, ou «Criar outro» /
  «Ignorar».
- **13.7 Ficha:** painel lateral largo (clique na linha ou no nome; setas ↑ ↓ e J/K) e ficha completa
  em `contact.php?id=…` com o caminho Comercial / Contactos / nome. À esquerda: dados com
  Editar/Gravar (tecla `E`, ⌘/Ctrl+Enter, Esc, «Anular»), Ligar / WhatsApp / Email, Ativo/Inativo,
  avaliação, a empresa ou as pessoas ligadas (abrem no próprio painel) e a Relação. À direita:
  Serviços e preços, Fontes de negócio, Nova ação, Foco e Notas & Histórico (criado, dados
  alterados, mudou de empresa, preços, avaliações, ativo/inativo, juntado, importado, trouxe um
  negócio, relação, tarefas, notas e o que aconteceu nos negócios ligados — cada tipo com ícone,
  cor e dica). Eliminar (quem gere Contactos) só sem ligações a negócios, leads, custos ou pessoas,
  e continua a pedir confirmação. A pesquisa ⌘K mostra pessoas e empresas com empresa/cargo e abre
  a ficha nova. As tarefas de um contacto aparecem em Tarefas como «Contacto · nome» ou
  «Relação · nome».
- **13.8 Animação:** a regra passou do `leads.js` para o `app.js` e vale para todos os formulários
  por cima (`data-new`, `data-from`, `data-mdl-close`): «+ Adicionar Lead», «+ Adicionar contacto»,
  «Importar» (e a pré-visualização), «Como correu?» das leads e das relações, Arquivar, Acompanhar
  relação, Pausar, Juntar e a avaliação do trabalho. A tecla `N` abre o «+ Adicionar …» da página
  (na ficha da lead ou do contacto, leva à lista com o formulário aberto).
- **Migração dos contactos** (passo 15, automático, uma vez): cada contacto atual ficou Empresa; com
  «Pessoa de contacto», criou-se a Pessoa ligada (com o email, o telefone e os tipos do contacto).
  **Decisão:** quando a «Pessoa de contacto» é o próprio contacto (o mesmo nome), o registo é uma
  pessoa e não uma empresa — passou a Pessoa, Freelancer se era fornecedor (é o caso dos dois
  fornecedores atuais). O texto livre de «Tarifa» ficou nas notas das linhas de serviço («Tarifa por
  rever: …», preço vazio = «por rever»), uma linha por serviço da lista reconhecido no texto ou uma
  linha com o serviço antigo como etiqueta; «Site / redes» passou a «Site e redes» (um link por
  linha); a avaliação antiga entrou como a primeira avaliação. Lista inicial de serviços:
  3D Animation · 2D Animation · VFX · CGI · Edição · Compositing.
- **Permissões:** tudo passa por `contactos.php`, `contact.php`, `negocio.php`, `conta.php` ou
  `ui_api.php`, que confirmam em cada pedido o nível da aba Contactos (ver; editar; gerir para juntar
  e eliminar), o papel na base de dados para Ativo/Inativo, cadências e serviços, o Financeiro
  (editar) para avaliar trabalhos, o alcance de cada lead/negócio (`record_reach`) para «Trazido por»
  e fontes, e as regras de atribuição (`can_assign_to`) para tarefas e responsáveis das relações. Um
  «Gravar» só muda os campos que vêm no pedido.

Ficheiros novos: `contact.php`, `assets/contacts.js`, `inc/contacts.php`, `inc/contact_view.php`,
`inc/migrate_contacts_v2.php`.

### 10.5 Profundidade e degradês — §11.21 (21 out 2026)

A §11.21 está aplicada em toda a zona admin, só onde ajuda a ler a página (o que está por cima de
quê, o que se pega ou abre, qual é a ação principal). Exemplos antes/depois aprovados antes de
implementar. Tudo em tokens no `assets/app.css` (`--g-*`, `--hl`, `--sh-*`), com valor para o modo
claro, o escuro e o «como o sistema»; sem animação nos degradês; no máximo 2 camadas por elemento.

- **Fundo da área de trabalho:** brilho radial muito suave de céu (canto superior direito) e coral
  (canto inferior esquerdo), preso ao ecrã.
- **Menu lateral:** degradê grafite → preto; item ativo com brilho amarelo além da barra.
- **Amarelo** (botão principal, separador ativo, indicador em destaque, «Converter em Negócio» /
  «Gerou uma lead» no «Como correu?»): degradê #FFD84F → #FFC718 → #F2B300 com reflexo.
  **Botões escuros** («+ Adicionar …»): degradê grafite com reflexo; no escuro, o inverso.
- **Cartões** (indicadores, «O teu dia», destaques do Dashboard, empresas, Pipeline, Tarefas):
  degradê de 2–3%, linha de luz no topo e sombra em duas camadas, que sobe ao passar o rato.
- **Painel lateral e fichas** (lead, contacto, resumo do negócio): fundo com degradê vertical e
  brilho céu/azul; faixa céu → azul por trás do nome; os blocos da coluna direita em cartão; o
  rodapé fixo (Converter / Arquivar / Eliminar) fica translúcido.
- **Camadas por cima** (formulários, pesquisa ⌘K, notificações): o mesmo fundo do painel. Enquanto
  um formulário sai do botão ou volta para ele (§13.8), o degradê sai de cena para a animação
  partir da cor do botão.
- **Sem degradê:** tabelas e listas (só o contorno ganha relevo), histórico, notas e texto longo,
  campos, filtros, chips, gráficos, calendário, barra de topo e login. O Drive não foi alterado
  (herda o menu e os botões, como o resto da plataforma).

### 10.6 Aba Orçamentação — §14 (22 out 2026)

A §14 está aplicada. Base de dados **versão 16** (`inc/migrate_budget.php`): em `users` os campos
`hourly_cost` (custo/hora interno) e `monthly_hours` (horas disponíveis por mês); em `costs` o campo
`budget_key` (a linha do orçamento a que o custo corresponde); tabelas novas `budget_sheets` (uma
por negócio), `budget_versions`, `budget_lines`, `budget_history` e `budget_templates`; em `settings`
`budget_mso_pct` = 5, `budget_margin_pct` = 65, `budget_day_hours` = 8, `budget_month_target` = 8000
e `budget_conditions` (condições por defeito da proposta). A migração cria logo a folha (v1) de todos
os negócios que já existem. O cálculo está em `inc/budget.php`; as partes da folha em
`inc/budget_view.php`; a proposta em `inc/budget_pdf.php`. Ponto a ponto:

- **14.1 Folha e separadores:** a folha nasce na conversão da lead (X-Ray) com o mesmo ID, nunca se
  apaga e segue o negócio — o gancho está no `deal_log()` (conversão, etapa, perdido, reaberto, funil),
  por isso vale para o Pipeline, a ficha, o «Anular» e a migração. `orcamentacao.php` (no menu, em
  Produção): **Em orçamentação** (fase comercial; por defeito) · **Em produção** (desde o Kickoff) ·
  **Ganhos** (concluídos) · **Arquivo** (perdidos, agrupados por ano e fechados por defeito; a
  pesquisa própria abre os anos com resultados). Colunas: ID · Negócio (cliente · trazido por) ·
  Versão (aceite / há uma mais recente) · Preço s/ IVA · Lucro/mês (semáforo, só com Financeiro) ·
  Etapa. Indicadores: em orçamentação (e quantas sem linhas), valor em proposta, abaixo da meta (com
  Financeiro) e fechados no ano. Um negócio reaberto volta a Em orçamentação. A pesquisa ⌘K tem o
  grupo «Orçamentos» (ID, negócio ou cliente, em qualquer separador). Botão **«Orçamento»** na ficha
  do negócio (com um resumo: versão, preço, margem, lucro/mês e, em produção, custos reais vs
  orçados), no Pipeline e no painel lateral do negócio.
- **14.2 Linhas de custo** (`orcamento.php?id=<negócio>`): «+ Adicionar linha» (tecla `N`, com a
  animação da §13.8) em três tipos — **Fornecedor / freelancer:** serviço → só os fornecedores
  **ativos** com esse serviço, com preço, unidade e avaliação (os «por rever» aparecem mas não se
  escolhem) → quantidade; o preço é **copiado** para a folha nesse momento (alterar a quantidade, a
  nota ou a unidade da mesma linha não o muda; trocar de fornecedor ou serviço copia o preço novo).
  **Equipa interna:** a pessoa (só quem tem custo/hora na Equipa) e a descrição, em horas ou dias.
  **Outro custo interno:** descrição, unidade (valor fixo, unidade, hora, dia) e custo. Horas ↔ dias
  convertem-se sozinhos (1 dia = 8 h, das Definições; a versão guarda o valor com que nasceu);
  projeto, peça e segundo de animação são unidades fixas. Quantidade e unidade mudam-se no próprio
  campo; alterar (lápis) reabre o formulário preenchido; tirar tem «Anular». Uma linha cujo
  fornecedor ficou inativo continua na folha, marcada «agora inativo».
- **14.3 Cálculo** (no servidor, a cada mudança; cada campo grava-se sozinho): custos diretos = Σ
  linhas · MSO = diretos × 5% · duração em dias úteis seg–sex por **Datas** (conta os dias úteis),
  **Semanas** (× 5), **Dias úteis** ou **Fases** (Pré-produção, Produção, Pós-produção, Entregas —
  editáveis; com data de início mostram as datas de cada fase) · meses = dias ÷ 21 (mínimo 1 dia) ·
  **custos fixos mensais** = média dos custos da empresa (Financeiro, sem negócio, valor sem IVA;
  um custo sem mês conta 1/12) nos 3 meses completos anteriores, editável com «Usar a média» para
  voltar · **% de ocupação** sugerida = horas internas ÷ (horas por mês da equipa ativa × meses),
  sempre editável à mão, com «Usar a sugestão» e a conta escrita por baixo · estrutura = fixos ×
  meses × ocupação · base = diretos + MSO + estrutura · **preço = base ÷ (1 − margem)** (margem 65% por
  defeito, até 95%, editável em cada folha; mudanças seguidas da mesma pessoa juntam-se numa entrada
  «65% → 60%» no histórico) · c/ IVA e markup equivalente · gross profit e gross margin · lucro por
  mês = gross profit ÷ meses contra a meta (8 000 €): 🟢 na meta, 🟠 abaixo, 🔴 abaixo de 75%, com
  «Para cumprir a meta: preço mínimo X € (base + meta × meses) ou entrega em ≤ Y meses». Verificado
  com o caso da referência: 8 400 € diretos, 4 000 € fixos, 30%, 21 dias → **28 629 €**, lucro/mês
  **18 609 €**.
- **14.4 Ajuda:** um ⓘ em cada campo do cálculo (custos diretos, MSO, duração, ocupação, custos
  fixos — com o detalhe dos 3 meses —, estrutura, base, margem, preço, gross, lucro por mês, meta)
  com os textos da §14.4, por cima do cursor, do teclado (foco) ou do toque longo.
- **14.5 Resultado** (separador da folha, só com Financeiro): orçado vs real por linha — o real são
  os custos do negócio no Financeiro ligados à linha. Na ficha do negócio, o custo tem «Linha do
  orçamento»: por defeito **automática** (a linha desse fornecedor, se houver uma só), ou outra, ou
  «Fora do orçamento» (aparece à parte). Diferença em € e %, derrapagens a vermelho; linhas da equipa
  sem registo contam o orçado. Totais: custos diretos, **MSO usado** (a derrapagem paga pela
  almofada), prazo real (do Kickoff ou da data de início até à conclusão ou hoje) vs orçado, preço
  (o valor do negócio no Financeiro, quando existe), gross profit, gross margin (em pp) e lucro por
  mês. Ao concluir o negócio o Resultado **fecha** (fica o retrato desse dia); reabrir o negócio
  reabre a folha. **Relatórios** (com Financeiro): margem orçada vs real média e lucro/mês real por
  funil e por tipo de trabalho, e os serviços que mais derrapam, dos Resultados fechados no ano.
- **14.6 Versões, modelos, proposta:** v1, v2… com data e autor; «+ nova versão» copia a versão vista
  (com «Anular»); só a **última** se edita, e só enquanto não for a aceite e a folha estiver aberta.
  **«Marcar vN como aceite»** passa o preço para o valor do negócio no Pipeline e conta para o
  Resultado (com «Anular»); uma versão aceite (ou que ficou para trás) fica com a % de ocupação fixa,
  para o preço proposto não mudar quando a Equipa muda. **Comparar** duas versões lado a lado (linhas
  e totais, diferenças marcadas). **Modelos:** «Guardar como modelo» (linhas e duração, sem preços) e
  «A partir de um modelo» (numa versão vazia preenche-a; senão cria uma versão nova; os preços vêm do
  preçário e da Equipa desse dia; fornecedores que deixaram de estar ativos ficam de fora e são
  ditos), com «Anular»; a lista de modelos está no fundo da Orçamentação (apaga quem o criou ou quem
  gere a aba, com «Anular»). **«Gerar proposta (PDF)»:** logótipo topper., descrição, fases e prazos,
  preço s/ IVA, IVA e total, condições — nunca custos nem margens; guarda em
  `Projetos/<ID>_<NOME>/Propostas/Proposta_<ID>_vN.pdf` no Drive (quando o R2 está ligado) e
  descarrega. PDF gerado pelo próprio servidor (Helvetica, A4), sem bibliotecas novas.
- **Equipa:** «Horas disponíveis por mês» (por defeito 168 nas pessoas novas; coluna Horas/mês) e
  «Custo/hora interno (€)» (campo e coluna só para quem vê o Financeiro; quem não o vê não o apaga
  ao gravar), com ⓘ.
- **Definições** (A minha conta › Orçamentação, só superadmin e admins): MSO, margem por defeito,
  horas de um dia, meta mensal e condições por defeito da proposta. MSO, margem e horas entram nas
  folhas novas (as existentes guardam os seus); a meta vale já para todas.
- **Permissões (no servidor):** a aba Orçamentação, como Contactos, não tem âmbito — quem a vê vê
  todas as folhas (editar para mexer; gerir para apagar modelos de outros). **Quem não vê o
  Financeiro** preenche linhas mas não recebe preços de fornecedores, custos da equipa, custos fixos,
  base, MSO, margem, lucro nem o Resultado — nem na página, nem nas respostas do `ui_api.php`
  (o cálculo volta filtrado por `budget_public`/`budget_calc_view`), nem no PDF (que nunca os tem);
  vê a duração, a ocupação e o preço final. Mudar a margem ou os custos fixos pede o Financeiro.
- **§11:** modo claro e escuro com os tokens, degradês da §11.21 (cartões, bloco do preço em
  destaque amarelo, versão aceite em amarelo), animação dos formulários, «Anular» em vez de «Tens a
  certeza?», dicas em todos os ícones e ⓘ, e a página cabe num telemóvel (as tabelas rolam por
  dentro; o preço unitário esconde-se em ecrãs estreitos).

Ficheiros novos: `orcamentacao.php`, `orcamento.php`, `orcamento_pdf.php`, `assets/budget.js`,
`inc/budget.php`, `inc/budget_view.php`, `inc/budget_pdf.php`, `inc/migrate_budget.php`.

### 10.7 Aba Negócios — ficha central — §15 (22 out 2026)

A §15 está aplicada. Base de dados **versão 17** (`inc/migrate_deal_file.php`): em `deals` os campos
`prod_step` (etapa de produção enquanto o funil não tiver etapas de produção próprias), `delivered_at`,
`portfolio_at` e `testimonial_at` (o que a checklist de fecho regista). Os negócios que já estavam
concluídos ficam com as entregas feitas na data da conclusão. A lógica está em `inc/deal_file.php`.

- **15.1 Onde vivem:** um negócio aparece em Negócios quando chega a Kickoff em qualquer funil, e os
  **concluídos passam a viver em Negócios** — as permissões, a pesquisa ⌘K e o painel respondem à
  aba Negócios para `production` e `done` (`deal_tab_for`). O Pipeline fica com a fase comercial e
  os Perdidos (o separador «Concluídos» saiu; os endereços antigos levam a Negócios › Concluídos e
  abrir um negócio em produção ou concluído no Pipeline leva à ficha). Lista: **Em produção** (por
  defeito) · **Concluídos** · **Todos**; vistas Os meus · Entrega esta semana · Em risco · Por
  receber (esta só com Financeiro); colunas ID · negócio (cliente e tipo em pequeno) · etapa ·
  valor s/ IVA · margem real · % recebido (estas três só com Financeiro) · entrega. «Em risco» =
  tarefas atrasadas, entrega prevista ultrapassada, ou concluído com a checklist incompleta.
- **15.2 Regra de ouro:** a ficha não cria dados. Cada cartão lê da aba de origem pelo ID e o «Ver
  detalhe» (ou o «›») abre o **painel lateral dessa aba já filtrado para o negócio**, sem sair da
  ficha (`ui_api.php?a=panel&type=dcontacts|dbudget|dresult|dcosts|dtasks|dfiles|ddocs|dhist|dclose`),
  cada um com a permissão da aba de origem confirmada no servidor. O que se regista por negócio
  continua no seu sítio: «Contas e custos» (Financeiro: contas, custos, pagar, avaliar) e «Dados e
  contactos» (pessoas ligadas, «Trazido por», valor estimado, notas) são vistas da ficha, a que os
  painéis levam com «Registar…» / «Ligar…».
- **15.3 Topo:** voltar, ID, nome (editável no campo), tipo, estado e «Fecho incompleto» quando falta
  algo; cliente · trazido por · responsável · funil · IVA (com IVA, sem IVA, internacional).
  **Barra de etapas de produção:** Pré-produção · Produção · Pós-produção · Entrega · Concluído —
  clicar muda a etapa (com «Anular»; num concluído, voltar a uma etapa reabre-o no Kickoff). Quando
  o funil tiver etapas de produção (`funnel_stages.kind = 'production'`), a barra passa a usá-las e
  muda a etapa do negócio. **Ações:** pasta no Drive · abrir orçamento · nova tarefa (fica ligada
  ao negócio e aparece em Tarefas, com «Anular») · adicionar documento (abre a pasta do negócio no
  Drive) · «Mais» (Contas e custos, Dados e contactos, histórico completo, resumo de cobrança, ver
  no Pipeline). **Pulso:** valor s/ IVA · custos reais · margem real vs a meta da casa (a margem por
  defeito da Orçamentação, 65%) · % recebido · lucro e lucro/mês quando há prazo (real Kickoff →
  conclusão, ou o da folha de orçamento); sem Financeiro, o pulso mostra etapa, tarefas, entrega
  prevista e fecho. **«Precisa de atenção»:** margem abaixo da meta, entrega em ≤ 7 dias ou
  atrasada, fatura em atraso (o limite das Definições), custos por pagar, tarefas atrasadas,
  fornecedores por avaliar, sem orçamento na plataforma, fecho incompleto — e «Tudo pago e
  recebido» quando é o caso; cada chip com a sua dica.
- **15.4 Cartões:** Cliente e contactos (cliente, pessoas, trazido por, outros negócios do
  cliente) · Orçamento (versão aceite, preço, margem e prazo orçados; abrir a folha) · Resultado
  (preço, custos, gross profit, gross margin com barra custos/lucro; o painel mostra o orçado vs
  real da §14.5) · Equipa e fornecedores (custos por pessoa/fornecedor, valor, pago/por pagar, IVA
  suportado) · Produção e tarefas (etapa, tarefas abertas/feitas/atrasadas, entregas, prazo real vs
  orçado) · Ficheiros (pasta `<ID>_<NOME>`, contagens por tipo, último ficheiro) · Documentos
  (contratuais, fiscais e de fornecedores, pelo nome e pela subpasta no Drive, mais as propostas
  geradas na Orçamentação) · Criativos (estado vazio claro até o Centro Criativo existir) ·
  Histórico de **todas as fontes** (interações e etapas, folha de orçamento, tarefas feitas e, com
  Financeiro, custos — ícones e cores da §12.7) · Fecho do negócio. Sem o Drive ligado no servidor,
  Ficheiros e Documentos dizem-no; sem acesso a uma aba, o cartão diz que não há acesso.
- **15.5 Fecho:** «Concluído» na barra abre a checklist: custos todos pagos · cliente pagou ·
  entregas feitas · fornecedores avaliados (§13.3) · resultado fechado (§14.5; sem orçamento na
  plataforma conta como feito). Opcional: **«Enviar para o portfólio»** (regista e cria a tarefa
  de preparar o material para o Marketing) e **«Pedir testemunho»** (cria o email ao cliente para o
  responsável). Concluir com itens em falta é possível: o negócio fica com «Fecho incompleto» e
  conta como «Em risco» até fechar. Tudo com «Anular» (a conclusão anula-se de uma vez: etapa,
  entregas e tarefas criadas). Depois de concluído, o cartão tem Entregas feitas · Enviar para o
  portfólio · Pedir testemunho.
- **15.6 Negócios anteriores:** sem linhas na Orçamentação mostram «Sem orçamento na plataforma» e o
  Resultado usa o valor e os custos existentes do Financeiro.
- **Permissões:** quem não vê o Financeiro não recebe valor, custos, margens, lucro, IVA nem o
  Resultado — nem na ficha, nem na lista, nem nos painéis (`dresult` e `dcosts` pedem o Financeiro;
  o histórico não leva custos nem margens). Mudar etapas, concluir e criar tarefas pede editar
  Negócios e ter o negócio ao alcance.
- **§11:** modo claro e escuro com os tokens, degradês da §11.21 nos cartões e no pulso, animação
  dos formulários (checklist e nova tarefa saem do botão), «Anular», dicas em todos os ícones e
  chips, e a ficha cabe num telemóvel.
- **Voltar pelo caminho feito (22 out 2026):** quem salta entre abas a partir do ecossistema de um
  negócio (ficha → orçamento → contacto…) leva consigo de onde veio (`?de=`); «Voltar» faz o caminho
  inverso, passo a passo, e diz para onde vai («Voltar ao negócio», «Voltar ao orçamento»…). Sem
  origem, volta à lista da aba. Nas listas de Negócios e Orçamentação a linha inteira abre a ficha
  (Ctrl/⌘ ou botão do meio abre noutro separador). Na tabela de serviços de um contacto, editar
  abre o formulário no lugar do serviço.

Ficheiros novos: `inc/deal_file.php`, `inc/migrate_deal_file.php`, `assets/deal.js`; `negocio.php` e
`negocios.php` reescritos.

### 10.8 Aba Marketing — §16 (22 out 2026)

A §16 está aplicada (fase 1 de publicação; a fase 2 fica preparada). Base de dados **versão 18**
(`inc/migrate_marketing.php`): pilares (`mkt_pillars`), peças (`mkt_pieces` — uma ideia que avança
para Produção), votos, versões por rede (`mkt_versions`), publicações por rede com o link com
rastreio e as métricas (`mkt_posts`), cliques nesse link (`mkt_clicks`), dias de gravação
(`mkt_shoots`), histórico (`mkt_log`), registo de uso da IA (`mkt_ai_log`) e resumos mensais
(`mkt_summaries`). Em `deals`: `disclose` / `disclose_from` («Pode ser divulgado?») e `mkt_post_id`
(a publicação de onde a lead veio); a origem das leads ganha «Redes sociais» e «Talento». Em `tasks`:
`mkt_piece_id`. Lógica em `inc/marketing.php`, ecrãs em `inc/marketing_view.php`, `marketing.php`
(separadores), `marketing_peca.php` (a peça), pedidos em `mkt_api.php`, comportamento em
`assets/marketing.js`.

- **16.1 Separadores:** Ideias · Estúdio · Produção · Calendário · Desempenho (Comercial › Marketing).
- **16.2 Pilares:** os 6 de partida (Portfólio e breakdowns · Bastidores · Educação · Casos de
  cliente · Serviços · **Employer branding**), editáveis em Definições › Marketing (nome, nome curto,
  cor, ordem, ativo, «Talento»). A descrição de cada pilar vai para a IA.
- **16.3 Ideias:** «+ Ideia» na barra de topo e **tecla I em qualquer ecrã** (só o texto é
  obrigatório; em Marketing também N), captura rápida na lista, pilar · formato · redes · modelo ·
  autor · **votos**. Triagem Novas → **Avançar** (Produção, Ideia aprovada, com a checklist do modelo,
  uma publicação e um link com rastreio por rede e a pasta no Drive) · Guardar · Descartar, tudo
  com «Anular». **Automáticas:** «Enviar para o portfólio» na ficha do negócio cria «Breakdown:
  <negócio>» ligada ao negócio e aos ficheiros dele no Drive (o «Anular» tira-a se ninguém mexeu);
  peças com ≥ 1,5× a média de interações da rede sugerem «Criar parte 2». **Centro Criativo:**
  enquanto não existir, o breakdown liga só ao negócio e ao Drive. **Carreiras:** a origem «Talento»
  está preparada (o formulário de carreiras do site, quando existir, envia `form=careers`) —
  **PENDENTE** a página de carreiras. **«Pode ser divulgado?»** na ficha do negócio (Dados e
  contactos): por decidir · sim · não · a partir de <data>, com «Anular» e histórico; a nota está
  também no cartão «Fecho do negócio».
- **16.4 Estúdio (IA):** escolhe-se a ideia ou peça; a ideia base, o modelo, o pilar e o formato
  gravam sozinhos; versão por rede (gancho, texto, CTA, hashtags) com contagem de caracteres (X: 280
  por post; uma thread separa os posts com «---»). Botões: **Adaptar às redes · 5 ganchos · Guião
  cena a cena · Encurtar · Alongar · Mudar o tom · Sugerir CTA · Criar thread · Hashtags**. A IA
  **propõe**: as propostas aparecem ao lado e só gravam com «Usar» (com «Anular»). Modelos: Breakdown
  VFX · Bastidores · Dica técnica · Caso de cliente · Antes e depois 3D · Apresentação de serviço ·
  Pessoas da topper. · Um dia na topper. (cada um com checklist, material de gravação e pista para a
  IA). **Voz da topper.** em Conhecimento › Voz da topper. (editável por quem edita o Conhecimento),
  enviada em todos os pedidos. Motor: **Claude, API da Anthropic**, pelo mesmo cliente do assistente
  do Drive (`inc/ai.php`): **chave só no servidor** em `admin/inc/ai_config.php` (fora do Git), nunca
  no browser; o gasto conta em `ai_usage` (finalidade «mkt») com o mesmo limite mensal, e cada pedido
  fica em `mkt_ai_log` (quem, peça, ação, tokens, custo, o que propôs). **Registo de uso** em
  Definições › Marketing › IA do Estúdio. Sem chave, os botões ficam desativados com o aviso.
- **16.5 Produção:** kanban Ideia aprovada → Guião → Gravação → Edição → Revisão → Agendado →
  Publicado (arrastar, ou o seletor no cartão, com «Anular»); cartão com pilar, redes, responsável,
  prazo, checklist n/m, negócio e autorização, dia de gravação. **Revisão:** aprovam **só superadmin
  e admins** (papel lido na base em cada pedido); Aprovar · Pedir alterações (com comentário: volta a
  Edição e o responsável recebe uma tarefa, que fecha com a aprovação). Depois de aprovada, os textos
  fecham; voltar atrás tira a aprovação. **Agendado e Publicado** pedem a peça aprovada e, se ligada
  a um negócio, autorização de divulgação nessa data — **bloqueio no servidor** (`mkt_block_reason`),
  com a tentativa no histórico. **Dias de gravação:** juntar peças num dia gera a lista de planos (dos
  guiões) e o material (dos modelos), para imprimir. **Pasta no Drive** `Marketing/AAAA-MM/<peça>/`
  criada ao avançar (ou pelo botão na peça). Página da peça: dados (Editar/Gravar), checklist, etapas,
  Revisão, publicação por rede, textos, guião e histórico com ícones.
- **16.6 Calendário:** semana e mês, uma linha por rede; cores = pilares; coluna **Alvo** por semana
  (Definições: **Instagram 2–3 · LinkedIn 5 · X 10**) e semanas abaixo do alvo destacadas;
  **arrastar uma peça aprovada (da lista «Prontas a agendar») para um dia = agendar** (10:00, ou a
  hora que já tinha); arrastar uma agendada para outro dia muda a data; **equilíbrio de pilares** do
  mês (barra e pilares sem publicações).
- **16.7 Publicação — fase 1 (assistida):** ao agendar, quem publica recebe a tarefa no dia e à hora
  (Tarefas); na peça, «Copiar legenda» junta gancho, texto, CTA, link com rastreio e hashtags; o
  ficheiro final está na pasta no Drive; «Marcar como publicada» pede o link de cada rede, fecha o
  lembrete e cria o das **métricas 7 dias depois**. **Fase 2 (preparada, por fazer):** `mkt_accounts`
  (rede, conta, estado da API), `mkt_posts.external_id` / `sync_source` / `synced_at`, e Definições ›
  Marketing › Ligações às redes («por ligar»).
- **16.8 Desempenho:** por mês: alcance, cliques (link com rastreio + cliques na rede), **leads**,
  **negócios e €** (o valor só com Financeiro), **talento**, melhor pilar / rede / dia e hora;
  métricas por publicação (à mão, com aviso quando faltam depois de 7 dias); médias por pilar,
  formato, rede e dia/hora; **resumo do mês escrito pela IA** (guardado). **Link com rastreio por
  publicação:** `https://topperdigital.xyz/go.php?c=<código>` conta o clique (sem guardar o IP,
  sem contar robôs nem a mesma pessoa duas vezes em 30 min), lembra o código 30 dias e leva ao site
  (destino escolhido na peça); a lead do formulário do site entra com origem **«<rede> · <peça>»**
  (Origem «Redes sociais», Campanha «LinkedIn · Breakdown: …») e fica ligada à publicação.
- **Também:** peças na pesquisa ⌘K e no painel lateral (`type=mkt`); atalhos I e N em Conhecimento ›
  Navegação; ícones novos (ideia, IA, copiar, câmara, voto, X).
- **§11:** modo claro e escuro com os tokens, degradês nos cartões, animação dos formulários (saem do
  botão), «Anular» em triagem, etapas, revisão, agendar, publicar, métricas e textos, dicas em
  ícones e chips, histórico com ícones e cores, e tudo cabe num telemóvel (o kanban e o calendário
  deslizam).

Ficheiros novos: `inc/migrate_marketing.php`, `inc/marketing.php`, `inc/marketing_view.php`,
`marketing.php`, `marketing_peca.php`, `mkt_api.php`, `assets/marketing.js` e, na raiz do site,
`go.php`. Alterados: `contacto.php` e `index.html` (origem «rede · peça»), Negócios (divulgação,
portfólio → ideia), Conhecimento (Voz da topper.), Definições, menu, ⌘K.

### 10.9 Aba Equipa — §17 (22 out 2026)

A §17 está aplicada, com as contas iniciais dos sócios (§17.9). Base de dados **versão 19**
(`inc/migrate_team.php`): em `users` — `is_partner`, `reports_to`, `phone`, `photo`, `birthday`,
`skills`, `work_hours`, `last_seen_at`, `focus_until`, `invite_pending`, `invited_at`; cargos
(`positions`, `user_positions`), posições por preencher (`team_openings`), subdepartamentos
(`departments.parent_id`), histórico da conta (`user_history`), checklists de entrada e saída
(`team_checklists`), chat (`chat_messages`), pedidos de reunião (`meetings`), férias e ausências
(`absences`), convites (`password_resets.kind = invite`) e **Ocupado/Livre** nas tarefas
(`tasks.busy`). Lógica em `inc/team.php`, ecrãs em `inc/team_view.php`, `equipa.php` (separadores),
pedidos em `team_api.php`, fotos em `avatar.php`, comportamento em `assets/chat.js` (todas as
páginas) e `assets/team.js` (a página Equipa).

- **17.1 Separadores:** Equipa (todos) · Organograma (superadmin, admins, chefes de equipa) ·
  Utilizadores e acessos (superadmin, admins; inclui a **grelha de permissões** e as exceções por
  pessoa) · Departamentos (superadmin, admins). A visibilidade decide-se pelo papel lido na base;
  a aba Equipa fica sempre visível (no mínimo «Ver») na grelha.
- **17.2 Papel ≠ cargo ≠ sócio:** cargos geridos pelos admins (Utilizadores e acessos › Cargos), vários
  por pessoa; a marca Sócio é à parte. Lista inicial: CEO · COO · CMO · CTO · CFO · Business Developer
  · Post Production Lead · Desenvolvedor de Produto · 3D Artist · Compositor · Editor · Produtor (os
  antigos «Função» passaram a cargos).
- **17.3 Estrutura inicial:** Direção · Comercial · Pós-Produção e VFX · Campanhas e Adverts (com os
  subdepartamentos **Adverts** e **Campanhas**, «equipa a definir») · Produto e Otimização de
  Processos; os quatro respondem ao CEO; **CFO por preencher** (a tracejado). Os departamentos de
  arranque sem uso (Orçamentação, VFX, Desenvolvimento) foram retirados.
- **17.4 Organograma:** árvore por «responde a» (avatar, nome, cargos, departamento, sócio
  marcado), subdepartamentos sem pessoas e posições por preencher a tracejado; quem ainda não
  responde a ninguém nem tem equipa fica numa faixa «Sem «responde a»». Clicar abre a
  disponibilidade; **admins arrastam** uma pessoa para cima de outra (ou para «Topo») com «Anular»;
  o servidor recusa círculos. Posições por preencher geridas no próprio separador.
- **17.5 Cartão da pessoa:** foto ou iniciais, sócio, cargos, departamento, **online/offline em
  tempo real** (ponto verde/cinzento, «visto há X min»), estado da agenda (Disponível · Ocupado até
  HH:MM · Em foco até HH:MM · Ausente · Fora do horário), competências, telemóvel e email, botões
  Chat · Ver disponibilidade · Ligar. Pesquisa por nome, cargo ou **competência** e filtro por
  departamento. **Custo/hora e horas/mês só para admins** — no HTML e na API (`team_people` tira-os
  para os outros; testado).
- **Presença:** sinal da sessão a cada ~30 s com a página à vista (`team_api.php?a=ping`): grava só a
  hora (`last_seen_at`); online = sinal nos últimos 75 s. **Não prolonga a sessão** (o «sair por
  inatividade» continua a valer) e **nunca regista o que a pessoa faz**. Sem registo de horas.
- **17.6 Chat:** só **1:1**, painel lateral em todas as páginas (botão na barra de topo, **tecla C**,
  Esc fecha), lista de pessoas com presença e não lidas, contador na barra de topo e aviso «Nova
  mensagem de …» com «Abrir»; Enter envia (Shift+Enter muda de linha). Links de lead, negócio,
  contacto, orçamento, tarefa ou peça de Marketing viram **cartões** — só se quem lê os pode ver.
  Mensagens no servidor; só os dois participantes as leem. Também nas notificações (sino).
- **17.7 Disponibilidade e reuniões:** tarefas com hora têm **Ocupado/Livre** e duração (formulário
  das Tarefas; por defeito Ocupado, 1 h); o horário de cada pessoa (por defeito **seg–sex
  09:00–18:00**), as férias/ausências aprovadas e o Foco bloqueiam. «Ver disponibilidade»: semana em
  blocos de 30 min; **os outros veem só «Ocupado»/«Ausente», admins veem o título**. Clicar num bloco
  livre → pedido de reunião (dia, hora, duração, assunto, negócio opcional, **Google Meet ou
  Discord**, link opcional). O pedido chega **no chat** como cartão com **Aceitar · Recusar · Propor
  outra hora** (quem pediu pode cancelar). **Ao aceitar:** tarefa «Reunião: …» Ocupado na agenda
  dos dois (num pedido a várias pessoas, quem pediu fica com uma só), histórico do negócio se houver,
  e **email aos dois com o link**. **Integrações:** `admin/inc/meet_config.php` (fora do Git; modelo
  em `meet_config.example.php`) com `google` (client_id, client_secret, refresh_token, calendar_id →
  cria o evento com Meet pela API do Google Calendar) e `discord` (invite_url). **Credenciais só no
  servidor.** **PENDENTE** configurar as duas; até lá, quem aceita **cola o link** (o cartão pede-o).
  **«Encontrar hora»** (botão na página Equipa): várias pessoas, duração e semana → espaços livres
  comuns → pedido a todos de uma vez.
- **17.8 Utilizadores e acessos:** lista Ativas · Convite pendente · Desativadas; **«+ Utilizador»**
  (tecla N) com papel, departamento/subdepartamento, cargos, sócio, responde a, horário, custo/hora e
  horas/mês → **convite por email** para definir a palavra-passe (link de 7 dias; ecrã «Bem-vindo,
  <nome>»; reenviar convite). Página da conta: editar (com «Anular»), permissões, **desativar nunca
  apagar** — com trabalho aberto (leads, negócios, tarefas, relações, peças) pede a quem passa e
  reatribui; também retira chefias, «responde a» e convites por usar; reativar. **Só o superadmin
  mexe em admins** (e só ele dá o papel de admin) — confirmado no servidor, também contra pedidos
  forjados; ninguém mexe no superadmin.
- **17.9 Contas dos sócios:** a migração cria (ou, se o email já existir, **atualiza sem duplicar**)
  Rodrigo Nicolau (CEO, Direção), Santiago Apolónio (COO · Business Developer, Comercial), Guilherme
  Albano (Post Production Lead, Pós-Produção e VFX), Valdir Furtado (CMO, Campanhas e Adverts) e
  Diogo Vasconcelos (**CTO** · Desenvolvedor de Produto, Produto e Otimização de Processos,
  `diogovasconcelos@topperhub.xyz`) como **Admin**, sócio, responsável do seu departamento, e envia
  o **convite por email** a cada conta nova. O superadmin continua a ser só `info@topperdigital.xyz`.
  Contas que já existiam (ex.: Ron) ficam como estavam.
- **17.10 Sem microgestão:** **Carga da equipa** (admins; Equipa › Carga): por semana, horas Ocupado
  vs horas disponíveis (horário menos ausências), 🟢 <80% · 🟠 80–100% · 🔴 >100%. **Entrada e
  saída:** checklist de entrada criada com a conta (conta e convite marcados; primeira tarefa
  marca-se sozinha; pasta no Drive e apresentação com atalhos) e de saída ao desativar. **Férias e
  ausências:** pedido em A minha conta → aprovação por admin (Equipa › Férias e ausências, com
  «Anular», email à pessoa, contador no menu e notificação) → bloqueia a agenda; calendário das
  próximas 5 semanas. **«Hoje na equipa»:** ausentes, em reunião, em foco, aniversários. **Modo
  Foco:** 30 min a fim do dia (no chat ou em A minha conta): silencia o chat e as notificações, os
  pedidos de reunião ficam em espera, o cartão mostra «Em foco até HH:MM» e quem escreve é avisado.
  **Competências** pesquisáveis. **O meu perfil** (A minha conta): foto (recortada a 256 px, guardada
  em `inc/avatars/`, fora do acesso direto, servida só com sessão), telemóvel, competências, horário,
  aniversário (opcional).
- **§11:** claro e escuro com os tokens, degradês nos cartões, painel do chat a deslizar, formulários
  que saem do botão, «Anular» em contas, organograma, férias e perfil, dicas nos campos, e cabe no
  telemóvel (sem deslocamento horizontal).

Ficheiros novos: `inc/migrate_team.php`, `inc/team.php`, `inc/team_view.php`, `team_api.php`,
`avatar.php`, `assets/chat.js`, `assets/team.js`, `inc/meet_config.example.php`. Alterados:
`equipa.php` (reescrita), `conta.php` (O meu perfil, Foco, férias), `index.php` (convite), Tarefas
(hora, duração, Ocupado/Livre), alertas e notificações, permissões, menu e barra de topo.

### 10.10 Aba Tarefas — §18 (22 out 2026)

A §18 está aplicada. Base de dados **versão 20** (`inc/migrate_tasks.php`): nas tarefas — `location`,
`platform`, `private` (pessoal/bloqueio), `checklist`, `attachments` (links), `participants`,
`help_by` (pedido de ajuda); histórico das tarefas (`task_log`); vistas guardadas (`task_views`);
`meetings.location` (reunião presencial). Lógica em `inc/tasks.php`, ecrãs em `inc/tasks_view.php`,
página `calendar.php` (reescrita), pedidos em `tasks_api.php`, comportamento em `assets/tasks.js`.

- **18.0 Conta do superadmin:** a migração muda o email da conta existente `admin@topperdigital.xyz`
  para `info@topperdigital.xyz` e o papel para **Superadmin**, sem tocar no hash da palavra-passe
  (não cria outra conta; testado: o hash fica igual). Fica uma linha no histórico da conta.
- **18.1 Separadores:** Tarefas (por defeito) · Quadro da equipa · Agenda (antes «Mês»/Calendário).
  O separador «Equipa» desta aba saiu. Os endereços antigos (`?view=…`) continuam a abrir o sítio certo.
- **18.2 O superadmin não é operador:** nunca é responsável possível (leads, negócios, tarefas —
  regra central em `can_assign_to`), não tem coluna no quadro nem entra em distribuições; uma lead
  nova criada por ele fica «Por atribuir». Continua a ver e gerir tudo. **Redistribuição única:** as
  leads dele, pela ordem de criação, em rotação Rodrigo → Santiago → Valdir → Guilherme → Diogo
  (111 → 23/22/22/22/22); as tarefas abertas de cada lead (as «Ligar a…») acompanham; uma linha
  «Atribuída» no histórico de cada lead; as outras tarefas abertas dele ficam «Por atribuir». O
  resumo antes/depois fica em `settings.tasks_redistribution`.
- **18.3 Lista:** linhas compactas (concluir · ícone do tipo · marca de origem · título com
  checklist n/m, ↻, 🙋, plataforma/local, privada · ligação · prazo · responsável); grupos Em atraso
  (sempre aberto, vermelho) · Hoje · Esta semana · Mais tarde · Sem data, recolhíveis (o browser
  lembra-se); concluir com «Concluída · Anular»; concluídas no filtro próprio (com Reabrir).
  **Agrupamento:** as chamadas de leads («Ligar a…» de leads) e os follow-ups de relação aparecem
  numa linha por pessoa — «Chamadas de leads · 22 · 10 em atraso · Abrir fila →» (Leads com
  «Urgente» / Contactos › Relações); «Ver uma a uma» desfaz o agrupamento.
- **18.4 Quadro da equipa:** Por atribuir + uma coluna por operador, com a barra de carga da semana
  (§17.10) e atrasadas · hoje · total; **no máximo 6 cartões** (os mais urgentes, grupos contam como
  um) e «+ N tarefas» que abre a lista dessa pessoa; sem coluna Feito. **Entreajuda:** arrastar um
  cartão para outra coluna (a sua), «Pegar» nas tarefas dos colegas, «Pedir ajuda» (vai para Por
  atribuir com 🙋 e avisa os operadores no sino); tudo com «Anular» e no histórico da tarefa (e do
  negócio, se houver).
- **18.5 Filtros:** Estado · Urgência (com o número em atraso) · Quem (eu, todos, por atribuir, cada
  operador) · Tipo · Origem · Negócio, combináveis; **vistas guardadas** por pessoa.
- **18.6 Tipos e origens:** 16 tipos com ícone e cor própria (Chamada, Reunião, Email,
  Mensagem/WhatsApp, Follow-up, Visita, Gravação, Edição, Render, Revisão, Entrega,
  Orçamento/proposta, Documento, Tarefa, Pessoal, Bloqueio); 6 origens com o ícone da aba de origem
  (Leads, Negócios, Marketing, Contactos, Pessoal, Tarefas); cores em tokens claro/escuro; dica com
  o nome. Os tipos antigos mapeiam-se (relação → follow-up; almoço/jantar/evento → reunião).
- **18.7 «+ Nova tarefa»** (botão e tecla N, com a animação da §13.8): tipo só com ícones, título,
  notas, atalhos (Em 1 h · Em 3 h · Amanhã 9:00 · Próxima semana · Sem data), data, hora, duração,
  Ocupado/Livre, responsável (nunca o superadmin), negócio/lead, contacto, prioridade, repetir,
  checklist («[x]» = feito), **anexos como links** (Drive, WeTransfer…; o Drive não foi alterado),
  participantes — **convite pelo chat (§17.7)**: com dia e hora é um pedido de reunião
  (Aceitar/Recusar/Propor), sem hora uma mensagem; ao aceitarem não se duplica a tarefa de quem
  pediu. **Reunião:** Google Meet, Discord ou nenhuma = **presencial** com o campo **Local** (também
  nos pedidos do chat: «Presencial», o local segue no email). Editar abre o mesmo formulário, com o
  histórico e «Eliminar» (confirmação). Criar tem «Anular».
- **18.8 Agenda:** Dia · Semana · Mês, **24 horas**; abre na hora atual (linha vermelha de
  «agora»); horas fora do horário de trabalho mais escuras; a minha ou a de um colega. **Arrastar na
  grelha** (na minha) → Tarefa · Tarefa pessoal · Bloqueio, com o formulário já preenchido. Mostra
  tarefas com hora (largura partilhada quando se sobrepõem), reuniões, ausências, prazos de tarefas
  sem hora e os eventos antigos. **Pessoal e bloqueio são privados:** todos — admins incluídos —
  veem só «Ocupado» (agenda, disponibilidade da Equipa, lista e quadro); a agenda de um colega segue
  a §17.7 (não-admins veem «Ocupado», admins o título).
- **§11:** claro e escuro, degradês, «Anular», dicas, e cabe no telemóvel (o quadro desliza coluna a
  coluna; a agenda desliza na horizontal). A criação de «eventos» à parte saiu: agora é uma tarefa
  (Reunião, Bloqueio…); os eventos antigos continuam visíveis na Agenda.

Ficheiros novos: `inc/migrate_tasks.php`, `inc/tasks_view.php`. Reescritos: `calendar.php`,
`tasks_api.php`, `assets/tasks.js`. Alterados: `inc/tasks.php`, `inc/perm.php` (`can_assign_to`),
`inc/team.php` (presencial, privacidade), `inc/alerts.php` (pedidos de ajuda), `inc/undo.php`,
`leads.php`, ligações antigas para as Tarefas.

---

### 10.11 Pipeline, funis e automações — §19 (22 out 2026)

A §19 está aplicada (a estrutura e os valores iniciais da §19.5–19.6, todos editáveis). Base de dados
**versão 21** (`inc/migrate_pipeline.php`): funis com perguntas do X-Ray; etapas com papel fixo
(Proposta, Aguardar feedback), requisito da etapa ligado/desligado e rondas de revisão incluídas;
`stage_automations` com gatilho, tipo, responsável, prazo, obrigatória, documento pedido, repetição e
checklist; nos negócios `exec_owner_id`, `delivery_date`, `shoot_date`, `xray` (+ validação),
`stage_entered_at`; nas tarefas `auto_rule_id`, `stage_id`, `is_gate`, `doc_kind`; nos orçamentos as
linhas `opt` (A/B) e na versão o âmbito e a margem da B, prazo, pagamento, revisões, direitos, validade
e a opção aceite; tabelas `proposals`, `deal_billing` e `deal_rounds`. Motor em `inc/pipeline_flow.php`,
ecrãs em `inc/pipeline_view.php`, ações em `deal_flow.php`.

- **Funis (19.5):** 6 funis — VFX & Pós-produção, CGI / 3D, **Adverts** (novo), Campanhas, Ads,
  Marketing Buddy — com as etapas de produção da §19.5 (os funis base não tinham nenhuma; não se
  tocou em etapas que já existissem). **Proposta** e **Aguardar feedback** entram em todos os funis,
  também nos combinados. Um funil novo (ou combinado, §9.1) nasce com as etapas fixas, junta as
  perguntas do X-Ray dos funis de origem e copia as automações das etapas iguais.
- **Gates (19.1):** verificados no servidor em **todos** os movimentos (arrastar no quadro, «Avançar»,
  barra de etapas da ficha, Concluir). Para a frente só uma etapa de cada vez e com os requisitos
  cumpridos: os da etapa (X-Ray preenchido e validado · Opção A com linhas, prazo, pagamento,
  revisões, direitos, validade e PDF · resposta do cliente validada) e as **tarefas obrigatórias**
  criadas nela. Para trás passa sempre. **Forçar:** só admins, com motivo obrigatório, fica no
  histórico («Passagem forçada …: motivo · faltava: …»); com «Anular». Uma tarefa obrigatória só um
  admin a elimina. No quadro, cada cartão mostra «n/m» requisitos (dica com o que falta).
- **Automações (19.6, 19.7):** as tarefas da §19.6 (A comercial, B fecho, C por funil) são os valores
  iniciais: 45 regras no VFX, por exemplo. Gatilhos: entra na etapa · data do negócio (entrega,
  rodagem, validade) · todos os meses (avenças, no dia escolhido) · ao validar a aceitação · ao
  validar um pagamento. Responsável: comercial · execução · comercial com a execução a participar ·
  chefe de departamento · pessoa · por atribuir — **nunca o superadmin** (sem responsável válido vai
  para «Por atribuir»). Prazo em dias úteis desde a entrada ou relativo à entrega/rodagem (−5 = antes);
  títulos com {cliente} {negócio} {ID} {etapa}. Cada regra cria a tarefa uma vez por negócio (as
  mensais uma por mês); mudar as datas do negócio move os prazos das tarefas que dependem delas;
  mudar o responsável passa-lhe as tarefas automáticas abertas. «Anular» um movimento retira as
  tarefas que ele criou (se ninguém lhes mexeu). As de data e mensais correm todas as noites com a
  cópia de segurança (`cron_run.php backup`; também `cron_run.php pipeline`).
- **Definições › Funis e automações** (`funis.php`, ligação em Definições e no ⌘K): separador por
  funil; nome, perguntas do X-Ray, etapas (renomear, ordenar, eliminar as variáveis, rondas incluídas,
  requisito da etapa); por etapa as regras em frase legível («Quando o negócio entra em Kickoff → criar
  Documento «…» para responsável comercial com prazo +2 dias úteis · OBRIGATÓRIA») com Editar,
  Duplicar, Ativar/Desativar, ↑ ↓, Eliminar (com «Anular»), **Aplicar a todos os funis** e
  **Pré-visualizar** («ao entrar em Kickoff vão ser criadas estas 6 tarefas»). Só superadmin e admins
  mudam as automações (os outros com acesso de gestão ao Pipeline veem-nas). Mudar uma regra não mexe
  nas tarefas já criadas. Em Definições também as **condições de pagamento por defeito** (50/50;
  acima de 10 000 €: 40/40/20; Marketing Buddy mensal adiantado, mínimo 3 meses).
- **X-Ray (19.2):** `xray.php` — campos comuns (decisor e aprovadores, objetivo, entregáveis,
  formatos, referências, prazo, **faixa de orçamento**, ativos, como nos encontrou, concorrência,
  uso/território/duração), pontuação 1–3 (orçamento, autoridade, necessidade, prazo; n/12 no painel),
  perguntas do funil e as secções «Pedido do cliente» e «Proposta de valor». Validar é do
  comercial ou de um admin e só com os obrigatórios; validar conclui a tarefa «Preencher e validar o
  X-Ray»; mudar um X-Ray validado tira a validação.
- **Orçamento com duas opções (19.3, altera §14):** separadores **Opção A · Pedido** / **Opção B ·
  Proposta de valor** na folha, cada uma com as suas linhas, preço, margem (a B pode ter a sua) e
  lucro/mês; «Começar a partir da Opção A» copia as linhas. Bloco «Proposta» com o âmbito de cada
  opção, prazo, validade, condições de pagamento (sugere as de Definições pelo valor/funil), revisões
  incluídas e preço da ronda extra, direitos de utilização e outras condições. O **PDF** mostra as
  duas opções lado a lado, o investimento de cada uma e estas condições. Resultado, valor do negócio e
  Financeiro seguem a opção aceite. Uma versão enviada ao cliente fica bloqueada (mudar = nova versão).
- **Resposta do cliente (19.4):** «Enviar» (no painel, com os requisitos ✓/✗) cria um link com
  token (só o hash fica na base), válido até à validade, e envia um email com **um único botão** «Ver
  e responder à proposta»; o negócio passa a Aguardar feedback; enviar outra vez invalida o link
  anterior. `proposta.php` é pública e **sem sessão** (não toca na sessão de quem esteja ligado):
  **GET só mostra** (opções, preços c/ e s/ IVA, condições, validade) e não escreve nada; aceitar é
  **POST** com a opção, «Li e aceito as condições» e o **nome escrito**; recusar pede o nome e um motivo
  opcional; formulário assinado pelo servidor (HMAC, 24 h) e mesma origem. Regista data/hora, IP,
  navegador, nome, opção, versão e hash do conteúdo (se a proposta mudar, o link deixa de aceitar);
  confirmação por email ao cliente e ao comercial; o cliente pode mudar a resposta até à validação.
  **Nada passa sozinho:** chega «Resposta do cliente: aceitou a Opção B» (notificação ao comercial) e
  o comercial (ou um admin) carrega em **Validar** (aceite → versão aceite com a opção, Kickoff e as
  regras «ao validar a aceitação»; recusa → Perdido com o motivo) ou **Contactar cliente** (cria a
  chamada; a resposta fica por validar).
- **Faturação (19.6 E, 19.9):** a plataforma **não emite faturas**. «Emitir fatura …» só fica feita
  quando o documento é submetido no negócio (número, data, s/ IVA, IVA, total que tem de bater certo,
  PDF verificado); «Validar pagamento» pede a data de receção (valor e comprovativo opcionais) e dispara
  as regras «ao validar um pagamento»; o pagamento final (em Concluído) marca o negócio como pago.
  Estas tarefas não se concluem à mão (Tarefas, Leads, Contactos, ficha). O PDF vai para a pasta do
  negócio no Drive (`Faturas/`) quando o Drive está ligado, senão para uma pasta privada do servidor;
  descarrega-se pelo negócio (comercial, Financeiro ou admins). A integração com o Financeiro fica
  para o desenho dessa aba.
- **Rondas (19.6 D):** nas etapas com rondas incluídas, «Registar ronda» conta-as; acima do incluído
  fica no histórico como pedido de alteração orçamentado e o comercial é notificado.
- **Comercial e Execução (19.10):** dois responsáveis por negócio, atribuídos por um admin no painel;
  o comercial fica até ao fim; notificações ao comercial quando outra pessoa passa um gate de produção
  ou quando as rondas se esgotam; ao entrar em Concluído o cliente passa às **Relações** com o
  comercial como dono.
- **Painel do negócio (19.8):** no Pipeline o cartão abre o painel ao lado (igual ao da lead): resumo,
  comercial/execução/datas, atalhos (X-Ray · Orçamento · Proposta · Ficha do negócio a partir do
  Kickoff · Drive), barra de etapas com os requisitos ✓/✗ (as tarefas obrigatórias concluem-se ali),
  Avançar / Forçar, proposta e resposta, faturação, rondas, Nova ação, Foco e Notas & Histórico; as
  ações voltam com o painel aberto. A ficha da aba Negócios tem os mesmos blocos.
- **Ajustes de 22 out (aprovados na demonstração):** o topo azul do painel ocupa a largura toda e
  mostra as etapas do funil **numa linha** (feitas ✓, a atual em amarelo com «Etapa atual», as
  seguintes apagadas; desliza na horizontal no telemóvel); a mesma linha, conforme o funil do
  negócio, substitui a barra de etapas da ficha na aba Negócios (as de produção carregam-se para
  mudar de etapa, com os gates e «Anular»; «Concluído» abre a checklist de fecho). «Nova ação» deu
  lugar a **Nova tarefa**, logo a seguir a «Para avançar»: o botão e os atalhos por tipo abrem o
  formulário da aba Tarefas (§18.7) com o negócio escolhido; gravar volta ao negócio com o painel
  aberto. **Arrastar cartões** (Quadro da equipa e quadro do Pipeline): o arrastar nativo do browser
  foi trocado por um próprio que funciona com rato, trackpad e dedo (pressão longa de ~0,3 s e
  arrastar; um toque rápido ou um deslize continuam a abrir/deslocar); junto às margens o quadro
  desliza. Os cartões agrupados («Chamadas de leads · n») não se arrastam: abrem a fila.
- **Ajustes de 22 out (segunda ronda):**
  - **Enviar a proposta** abre uma **pré-visualização** (folha de orçamento, painel do Pipeline e
    ficha): requisitos ✓/✗, email do cliente, mensagem opcional (entra no email, a seguir à
    introdução), o email tal como o cliente o recebe (assunto, texto, botão «Ver e responder à
    proposta», validade), as opções com preço s/ e c/ IVA e «Ver o PDF». O texto do email é o
    mesmo no envio e na pré-visualização (`proposal_email`). Fora da etapa Proposta / Aguardar
    feedback o botão aparece desligado com o motivo (`flow_can_send`).
  - **PDF da proposta** abre num **separador novo**, para ver no browser (inline). O cookie da
    sessão passou de `SameSite=Strict` a `Lax`: com Strict, o Safari não o enviava ao voltar atrás
    de um PDF (nem ao abrir um link de um email) e a página de entrada criava uma sessão nova —
    parecia um logout. Os POST de outros sites continuam sem cookie e levam token CSRF.
  - **Tarefas › Tarefas:** o filtro de tipo passou a um botão **«Tipo de tarefa»** que abre uma
    caixa com os 15 tipos (5 × 3, ícone e nome); a Origem segue o mesmo modelo (3 × 2). Aparece
    **«Limpar filtros»** quando algum filtro difere do que a aba mostra ao abrir. Depois de «Ver uma
    a uma» há **«Voltar a agrupar»**. Cada linha mostra o **prazo** (data · hora; por baixo a duração
    e «vencido há 3d 19h», «daqui a 2h», «amanhã», «em 5 dias») e as **ações rápidas** subtis:
    **Adiar** (+1 dia, +3 dias, +1 semana — a partir do prazo, ou de hoje se já passou — ou
    Personalizar com data e hora; fica no histórico da tarefa e tem «Anular»), Editar e Abrir o
    negócio/lead. No telemóvel o prazo passa para baixo do título.
  - **Ficha da lead e Nova tarefa do negócio:** os ícones dos tipos são os da aba Tarefas, com as
    mesmas cores (Documento passou ao ícone de ficheiro; Almoço, Jantar e Evento na cor de Reunião).
  - **Terceira ronda:** o filtro **«Quem»** passou a um só botão («Quem: Eu») que abre a lista
    (Eu, Todos, Por atribuir e cada pessoa, com as iniciais na cor dela). **Almoço, Jantar e Evento**
    passam a tipos da aba Tarefas (19 tipos; o filtro mostra 18, em 6 × 3), cada um com a sua cor
    (Reunião violeta, Almoço âmbar, Jantar bordeaux, Evento verde-oliva); levam o campo **Local**.
    Na Nova ação da lead, **Reunião** mostra Google Meet · Discord · Presencial (com Local), como na
    aba Tarefas.
  - **Quarta ronda (Pipeline com o funil inteiro):** o quadro de cada funil mostra **todas as
    etapas** — X-Ray, Proposta, Aguardar feedback, um separador «Produção», Kickoff, as etapas de
    produção e Concluído (só os últimos 30 dias, com ligação para Negócios › Concluídos) — e desliza
    na horizontal. São os mesmos negócios da aba Negócios: mover aqui (arrastar ou «Mover para»)
    muda lá, com os mesmos gates e «Anular». Em produção não se volta à fase comercial e concluir
    faz-se na checklist de fecho. A produção aparece a quem vê a aba Negócios, no âmbito dessa aba;
    os indicadores do topo continuam a contar só a fase comercial. **«Perdidos»** passou para a
    direita (não é um funil). Na **folha de orçamento**, um ícone ao lado do ID abre a ficha do
    negócio: no Pipeline (painel aberto) na fase comercial, em Negócios a partir do Kickoff.
  - **Correções:** a bolinha amarela da etapa atual já não fica cortada em cima; no formulário de
    tarefa, Data, Hora e Duração já não se sobrepõem no iPad (as caixas de data/hora do Safari têm
    largura própria — agora encolhem à coluna).
- **Testado na bancada** (MariaDB, Playwright): migração idempotente; X-Ray → Proposta → envio →
  página do cliente (GET sem escrita nem cookie, POST sem a caixa ou com assinatura errada recusado) →
  validação → Kickoff (Opção B, valor do negócio) → fatura e pagamento → produção, forçar e anular,
  não-admin a forçar recusado, Colab sem acesso ao negócio nem ao editor; recusa → Perdido; conversão
  de lead cria as tarefas do X-Ray; superadmin como dono → tarefas por atribuir; mensais uma vez por
  mês; funil combinado com etapas, perguntas e 58 regras; claro/escuro e telemóvel sem deslocamento.
- **Não feito / a decidir:** os negócios que já estavam numa etapa antes da versão 21 não recebem
  tarefas retroativas (só ao entrarem na etapa seguinte); faturação ligada ao Financeiro e software
  certificado (§19.9); a fase do Marketing Buddy não leva as tarefas de entrega do fecho (B) por ser
  uma avença.

Ficheiros novos: `inc/migrate_pipeline.php`, `inc/pipeline_flow.php`, `inc/pipeline_view.php`,
`deal_flow.php`, `xray.php`, `proposta.php`. Reescrito: `funis.php`. Alterados: `inc/pipeline.php`
(gates e automações em `deal_move`), `inc/budget.php`, `inc/budget_view.php`, `inc/budget_pdf.php`,
`orcamento.php`, `assets/budget.js`, `ui_api.php`, `pipeline.php`, `negocio.php`, `inc/panel.php`,
`inc/deal_file.php`, `inc/leads.php`, `inc/tasks.php`, `tasks_api.php`, `calendar.php`, `inc/undo.php`,
`inc/alerts.php`, `inc/interactions.php`, `inc/cron_run.php`, `inc/bootstrap.php` (página sem sessão),
`conta.php`, `assets/app.js`, `assets/app.css`.

### 10.12 Perfil e Definições, ajustes a Leads e Tarefas — §20, §21, §19.10 (22 out 2026)

Base de dados **v22** (`inc/migrate_settings.php`, idempotente): tabela `settings_log` (registo
das Definições: quem, o quê, quando, antes → depois) e `users.prefs` (JSON com as preferências do
Perfil). O passo tira também as referências «(§…)» que passos anteriores deixaram em notas dos
históricos (`user_history`, `deal_history`).

- **Topo e barra lateral (§20.1):** avatar no canto superior direito com menu **Perfil ·
  Definições (só admins) · Sair** (setas e Esc); o botão claro/escuro fica fora do menu. No fundo
  da barra lateral, só a engrenagem **Definições**, só para superadmin e admins.
- **Perfil (`conta.php`):** nome, palavra-passe, tema, menu, **vista com que cada aba abre**
  (Leads: «As minhas» / «Todas»; Tarefas: «Eu» / «Todos») e **notificações por categoria**
  (leads, fila, negócios, faturas, equipa) no sino e/ou por email. As secções de admin saíram
  daqui (um aviso aponta para Definições). O sino e os emails (equipa, negócio ao comercial)
  respeitam estas escolhas (`user_wants`).
- **Definições (`definicoes.php`, `inc/settings_def.php`, `assets/settings.js`):** um separador
  por aba — Geral, Leads, Pipeline, Contactos, Orçamentação, Negócios, Tarefas, Marketing,
  Financeiro, Drive (só leitura), Integrações, Segurança — mais **Registo de alterações** e a
  ligação a **Utilizadores e permissões** (continuam na aba Equipa). Cada campo tem ⓘ com a
  explicação, **grava sozinho** (aviso «… · Anular»), **«Repor (valor de origem)»** quando difere,
  e validação no servidor (ex.: IVA 0–50 %, remetente só @topperdigital.xyz, fim do dia depois do
  início). Mudanças que mexem em dados existentes mostram antes um **aviso de impacto** com
  Confirmar / Cancelar (prazos das leads: quantas leads ativas e quantas passam a estar em atraso;
  cadências das relações; IVA; dias sem mexer). **Pesquisa** por nome, grupo e ajuda, que leva ao
  campo noutro separador. O registo mostra cada alteração (nas listas, «+ item · − item») com
  «Repor». Tabelas (serviços, pilares) gravam célula a célula. Junta o que estava espalhado:
  prazos e listas das leads, regras anti-spam do formulário do site, motivos de perda, validade e
  condições de pagamento da proposta, assunto e introdução do email da proposta, cadências,
  serviços, orçamentação (MSO, margem, objetivo, ocupação, custos fixos, modelos, condições),
  dias sem mexer, prazos de tarefas e faturas, tarefas (máximo por coluna, agrupar automáticas),
  marketing (pilares, objetivos, voz), dados da empresa (rodapé do PDF), horário e dias úteis
  (contam nos prazos), Discord e remetente dos emails, sessão e tentativas de entrada. O editor
  de funis abre a partir de Definições › Pipeline. As páginas de origem têm um atalho
  **«Configurar»** (só admins). Sem autenticação de dois passos. **Permissões no servidor:**
  página e API recusam (403) a quem não é admin.
- **Leads (§21.1–2):** na quarentena cada lead tem **«Não é spam»** (sai da quarentena para
  «Por atribuir», com contactos, prazo e a chamada) e **«É spam»** (elimina com «Anular», que a
  repõe com o mesmo ID e histórico), na lista (com o motivo) e no painel; só admins e chefes de
  equipa. A aba abre em **«As minhas»** para operadores e em **«Todas»** para o superadmin (ou na
  vista escolhida no Perfil); os botões mostram total e atrasos («As minhas · 23 · 22 ⚠»).
- **Tarefas (§21.3):** «Quem» abre pela preferência do Perfil (e «Limpar filtros» volta a ela);
  **Participantes** só em Reunião, Gravação e Visita, com o rótulo «Participantes — recebem o
  convite no chat» (o servidor ignora participantes nos outros tipos). Não há referências à
  especificação na interface.
- **§19.10, §19.11 e §19.6 E confirmados**, com três acertos: «Entregas feitas» também passa o
  cliente às **Relações** (já acontecia ao entrar em Concluído); o comercial é avisado quando as
  rondas incluídas **se esgotam** (a última incluída) e quando há **pedido de alteração** (acima
  do incluído), com títulos diferentes no sino; uma tarefa feita à mão **«Emitir fatura…»** ligada
  a um negócio só fica feita com o PDF da fatura submetido, como as automáticas. O Marketing Buddy
  tem o funil de agente de IA (Onboarding, Configuração do agente, Ativo, Revisão trimestral e
  renovação).
- **Testado na bancada** (MariaDB, Playwright): gravação automática, impacto e Confirmar, Repor,
  lista com «Anular», valores inválidos recusados, tabela de serviços, pesquisa, registo e Repor,
  menu do avatar; Colab sem engrenagem, 403 na página e na API; Perfil; quarentena (spam →
  Anular → volta; não é spam → Por atribuir com chamada; Colab recusado); vistas iniciais de
  operador, admin e superadmin; Participantes por tipo; fatura manual recusada sem PDF; rondas
  2 de 2 e 3 de 2 notificadas; Relações após entrega; claro/escuro e telemóvel sem deslocamento.

Ficheiros novos: `definicoes.php`, `assets/settings.js`, `inc/settings_def.php`,
`inc/migrate_settings.php`. Alterados: `conta.php`, `inc/layout.php`, `inc/ui.php`,
`inc/bootstrap.php`, `inc/migrate.php`, `ui_api.php`, `inc/undo.php`, `inc/alerts.php`,
`inc/team.php`, `team_api.php`, `inc/pipeline_flow.php`, `inc/pipeline_view.php`, `inc/budget.php`,
`inc/budget_pdf.php`, `inc/leads.php`, `inc/lead_view.php`, `inc/inline.php`, `inc/interactions.php`,
`inc/tasks.php`, `inc/tasks_view.php`, `inc/deal_file.php`, `deal_flow.php`, `leads.php`,
`calendar.php`, `funis.php`, `contactos.php`, `orcamentacao.php`, `negocios.php`, `marketing.php`,
`pipeline.php`, `../contacto.php`, `assets/app.js`, `assets/tasks.js`, `assets/app.css`.

### 10.13 Crosselling: um negócio, várias frentes — §22 (23 out 2026)

Base de dados **v23** (`inc/migrate_xsell.php`, idempotente): `deal_lines` (os serviços de cada
negócio; no Kickoff, as frentes com etapa, estado, execução, arranque, entrega e valor),
`packages`, `xsell_opps`, `xsell_rules`; em `deals` `sync_mode`, `xsell_from_id` e `package_id`;
`budget_lines.funnel_id` e `tasks.line_id`. Motor em `inc/xsell.php`, ecrãs em `inc/xsell_view.php`.

- **Correção (Leads):** a chamada de uma lead só fica feita com o resultado do «Como correu?»;
  fechar o formulário (clicar ao lado, Esc, «Fechar») deixa-a por fazer. O servidor recusa
  concluir a chamada de uma lead sem resultado venha de onde vier (na aba Tarefas abre a ficha
  da lead). «Arquivar» e «Converter» registam a chamada como atendida.
- **Fase comercial:** no painel do negócio, **Serviços** (juntar/tirar serviços adicionais ou
  aplicar um pacote) e o **Plano das frentes** (sincronizadas ou timelines diferentes, obrigatório
  e requisito da proposta; arranque «no Kickoff / depois de outra frente / numa data», entrega por
  frente e execução por frente, só admins). Na conversão da lead escolhe-se já um pacote ou os
  serviços adicionais. O X-Ray junta as perguntas de todos os serviços (sem repetir) e tem as
  **Oportunidades de crosselling** (serviços falados e não incluídos). As automações das etapas
  comerciais dos serviços adicionais juntam-se às do principal, sem tarefas repetidas.
- **Kickoff e produção:** cada serviço passa a uma **frente** com as etapas, gates e automações do
  funil do seu serviço (lidos do funil, sem cópias); as frentes «à espera» arrancam quando a frente
  anterior conclui ou na data (relógio diário). Na ficha e no painel, uma linha de etapas por
  frente, os requisitos, «Avançar», «Forçar» (admins) e as rondas de revisão de cada frente; o topo
  resume onde está cada frente e as listas mostram o mesmo. No quadro do Pipeline, cada frente
  aparece como cartão no funil do seu serviço e arrasta-se (com os gates). As tarefas de fatura e
  pagamento só vêm do funil principal (um plano de pagamento). **Concluir:** sincronizadas — todas
  na última etapa de produção com os requisitos cumpridos e passam juntas a Concluído; timelines
  diferentes — cada frente conclui sozinha e o negócio conclui quando todas terminaram (o Marketing
  Buddy fica como avença ativa). A checklist de fecho mostra o que falta; admins podem forçar com
  motivo. Tudo com «Anular» e no histórico (com o serviço).
- **Medir:** as linhas da folha de orçamento podem ser marcadas com o serviço; o valor de cada
  frente é o preço dividido pelo custo de cada serviço (guardado no Kickoff e no fecho).
  **Relatórios › Crosselling:** % de ganhos com 2+ serviços, taxa de fecho com pacote vs. isoladas,
  valor médio, crosselling posterior (e dias até lá), matriz serviço × serviço, % por serviço
  principal, combinações e oportunidades.
- **Sugerir:** Definições › Pipeline › **Pacotes e crosselling** (página dos funis, separador
  Crosselling): pacotes (serviços, principal, sincronização, modelo de orçamento) e regras
  «depois de entregar X, propor Y ao fim de N dias» — ao concluir, o comercial recebe «Propor Y a
  {cliente}» e fica uma oportunidade (se o cliente ainda não tiver Y). Na ficha do contacto,
  **Serviços comprados** (o que comprou, o que falta oferecer, oportunidades com «Criar negócio» —
  negócio novo em X-Ray ligado ao anterior — e «Descartar»). «Combinar funis» saiu.
- **Permissões no servidor:** serviços, plano e frentes pedem edição do negócio; pacotes e regras só
  admins; oportunidades pedem edição do Pipeline.
- **Testado na bancada:** timelines diferentes (frente à espera arranca depois da principal, sem
  tarefas de fatura, conclusão e regra pós-entrega com oportunidade), sincronizadas (frente não
  conclui sozinha, fecho bloqueado e forçado, todas concluem juntas), negócio de um só serviço sem
  alterações, conversão com serviço adicional, plano, ficha, quadro, pacote, orçamento, relatório,
  contacto e «Criar negócio», Colab recusado, claro/escuro e telemóvel sem deslocamento.
- **A decidir mais tarde:** juntar um serviço depois do Kickoff (hoje faz-se como negócio novo
  ligado); dependências entre frentes sincronizadas a meio da produção.

Ficheiros novos: `inc/migrate_xsell.php`, `inc/xsell.php`, `inc/xsell_view.php`,
`inc/xsell_settings.php`, `xsell_act.php`. Alterados: `inc/migrate.php`, `inc/pipeline.php`,
`inc/pipeline_flow.php`, `inc/pipeline_view.php`, `inc/deal_file.php`, `inc/undo.php`,
`inc/interactions.php`, `inc/budget.php`, `inc/budget_view.php`, `inc/contact_view.php`,
`inc/settings_def.php`, `inc/tasks.php`, `inc/lead_view.php`, `deal_flow.php`, `ui_api.php`,
`tasks_api.php`, `pipeline.php`, `negocio.php`, `leads.php`, `xray.php`, `funis.php`,
`relatorios.php`, `assets/app.js`, `assets/leads.js`, `assets/tasks.js`, `assets/budget.js`,
`assets/app.css`.

### 10.14 Centro Criativo — fase 1 — §23 (23 out 2026)

Base de dados **v24** (`inc/migrate_creative.php`, idempotente): `cc_boards` (quadros e sub-quadros),
`cc_assets` (biblioteca: uma referência existe uma vez, os quadros apontam para ela), `cc_frames`
(frames-chave dos vídeos), `cc_cards`, `cc_comments`, `cc_pages` (páginas do pré-criativo e o
tratamento), `cc_jobs` (fila da IA e das importações), `cc_ai_usage` (gasto à parte do assistente do
Drive); preparadas para as fases 2 e 3: `cc_versions` (Review v001…), `cc_share` (portal do cliente,
um link por negócio) e `cc_capture` (extensão do browser / captura do telemóvel); em `funnels`
`cc_pre_mode` e `cc_stages`. Modelo em `inc/cc.php`, IA em `inc/cc_ai.php`, ligação ao negócio em
`inc/cc_flow.php`, importação em `inc/cc_import.php`; página `criativo.php`, pedidos `cc_api.php`,
ficheiros `cc_file.php`, interface `assets/cc.js` e `assets/cc.css`.

- **Separadores:** Projetos (pré-criativos, projetos e quadros livres, com capa; «Negócios sem quadro»
  com «Criar quadro»), Biblioteca, Caixa de entrada e Review (fase 2, já com o lugar).
- **Quadro (tela infinita):** colunas com encaixe, grelha suave (snap de 20 px; Alt larga sem
  encaixar), cartões de imagem, vídeo, link (pré-visualização trazida pelo servidor), nota, lista,
  amostra de cor, desenho, ficheiro, seta e sub-quadro; seleção em caixa, redimensionar, duplicar,
  apagar com «Anular», ⌘Z/⇧⌘Z; comentários e @menções por cartão (avisam no sino); modo
  apresentação; etapas do projeto em cima (Brief · Referências · Conceito · Tratamento · Styleframes ·
  Storyboard · Lista de planos · Review); painel IA · Detalhe · Comentários; outros separadores
  abertos atualizam-se sozinhos (a edição em tempo real é da fase 3).
- **Fluidez:** colar ou arrastar imagens/ficheiros/links/texto/código de cor; atalhos N, C, L, /,
  espaço, ⌘/Ctrl+roda, 0, 1, F, V, D, A (em Conhecimento › Navegação); só o que está à vista é
  desenhado; miniaturas 256/768/1600 px escolhidas pela largura no ecrã; vídeo só toca ao passar
  o cursor; mover e zoom mexem só num transform (60 fps); iPad e telemóvel com arrastar, pan e pinça.
- **Biblioteca:** pesquisa por texto, **por cor** (paleta extraída por k-means em Lab, determinística;
  ΔE2000), **«Mais como este»** (embeddings multimodais; sem chave, por etiquetas, facetas e paleta) e
  filtros de estilo, técnica, câmara, ambiente, luz, origem (externas · topper.) e tipo. Detalhe com
  análise editável (a edição humana prevalece), paleta, frames, quadros onde está e «Pode ser divulgado?»
  das referências da casa. **Caixa de entrada:** captura (carregar, colar, arrastar, «Capturar» no
  telemóvel) já analisada; triagem = enviar para um quadro ou arrumar na biblioteca (com «Anular»).
- **Envios:** o browser calcula a impressão digital (duplicados reaproveitados), faz a
  pré-visualização (HEIC/AVIF no Safari) e, nos vídeos, os **6–12 frames-chave por deteção de corte**
  (sem precisar de ffmpeg no servidor). Com o R2 ligado, o original vai diretamente para o
  armazenamento e passa logo para o seu sítio no Drive (ver §10.15); as miniaturas e os frames
  ficam em `.topper/cc/` (invisível no Drive); sem R2, pasta privada.
- **IA de qualidade máxima:** o modelo Claude mais capaz (`cc_config.php › model`, por defeito
  Claude Fable 5.1; se a conta não tiver acesso, Claude Opus 5.5), esforço «high», saídas
  estruturadas (JSON Schema) e **fallbacks do servidor** ligados; embeddings multimodais Voyage
  (API paga). Análise por referência (legenda, estilo, técnica, ambiente, luz, composição, câmara,
  cor, «porque funciona», etiquetas, confiança; vídeo plano a plano) com o vocabulário da casa
  (Definições). Ações «/»: Analisar · Ângulos para este projeto · Referências para o brief · Mais
  como este · Arrumar o quadro · Quadro → tratamento (página para imprimir/PDF) · Storyboard → lista
  de planos (→ tarefas no negócio). A fila corre pelo browser e pelo cron (`cron_run.php cc`). Chaves
  só no servidor (`admin/inc/cc_config.php`, fora do Git); uso, custo e limite mensal em Definições ›
  Integrações. Transcrição dos vídeos preparada, por ligar (falta escolher o serviço).
- **Pré-criativo:** nasce ao validar o X-Ray — quadro «Pré-criativo · <negócio>» com o brief e as
  faixas **Opção A · Pedido** e **Opção B · Proposta de valor**, e a tarefa «Pré-criativo · <cliente>»
  para a execução (+2 d.u., Definições). «Preparar com IA» (3 ângulos por opção e até 9 referências
  da biblioteca por faixa) → manter / tirar / trocar → «Gerar página» (título, conceito, abordagem, 6
  referências, paleta, técnica, complexidade; sem IA, a partir das notas) → «Serve as duas» →
  «Enviar para a Orçamentação» (liga à versão atual ainda não enviada; conclui a tarefa). As páginas
  entram no **PDF da proposta** (uma página por opção, com as imagens) e na **página do cliente**, ao
  lado de cada opção (imagens servidas só por token da proposta). Gerar outra vez não muda uma
  proposta já enviada. Na etapa Proposta é **recomendado** (aparece, não trava) ou **obrigatório**,
  por funil. Perdido → arquivado; reaberto → volta. **Kickoff** → quadro do projeto com os
  sub-quadros do modelo do funil; a opção escolhida pelo cliente entra em «Conceito» e a outra fica
  como «Rota não escolhida». **Crosselling:** um sub-quadro por frente (no pré-criativo e no projeto),
  cada um com o seu responsável de execução.
- **Importação:** ZIP, PDF ou Markdown (exportações do Milanote) ou imagem → quadro novo em grelha,
  notas como notas, imagens para a biblioteca com análise; pela fila, em pedaços. Mecanismo pronto,
  nada importado.
- **Definições › Centro Criativo:** prazo do pré-criativo, por funil (recomendado · obrigatório ·
  desligado e sub-quadros do projeto), vocabulário da casa e atalho para a importação. A ficha do
  negócio tem o cartão **Criativos** com o pré-criativo, as páginas e o quadro do projeto.
- **Permissões no servidor:** ver (quadros, biblioteca, ficheiros), editar (tudo o que grava) e
  gerir (apagar quadros, «Analisar o que falta»); sem acesso, nem os ficheiros abrem.
- **Testado na bancada (MariaDB, IA simulada):** migração v24; paleta e ΔE; duplicados; fila da
  IA, análise e embeddings; pré-criativo completo até ao PDF e à página do cliente; perdido/reaberto;
  Kickoff com Conceito e «Rota não escolhida»; crosselling; importação; quadro no browser (atalhos,
  colunas, «/», rotas a partir de ângulos, páginas, enviar, manter/trocar, comentários com @menção,
  apresentação, apagar com «Anular», carregar com frames de vídeo), iPad (pan e pinça), telemóvel,
  claro/escuro, permissões (editar, só ver, sem acesso).
- **Fase 2 (preparada):** Review (versões lado a lado, comentários no frame com desenho, Aprovar ·
  Pedir alterações, rondas) e portal do cliente. **Fase 3 (preparada):** edição em tempo real por
  várias pessoas e extensão do browser.

Ficheiros novos: `inc/migrate_creative.php`, `inc/cc.php`, `inc/cc_ai.php`, `inc/cc_flow.php`,
`inc/cc_import.php`, `inc/cc_config.example.php`, `criativo.php`, `cc_api.php`, `cc_file.php`,
`assets/cc.js`, `assets/cc.css`. Alterados: `inc/migrate.php`, `inc/pipeline.php`,
`inc/pipeline_flow.php`, `inc/pipeline_view.php`, `inc/budget_pdf.php`, `inc/alerts.php`,
`inc/interactions.php`, `inc/settings_def.php`, `inc/undo.php`, `inc/ui.php`, `inc/perm.php`,
`inc/cron_run.php`, `proposta.php`, `negocio.php`, `definicoes.php`, `ui_api.php`, `brevemente.php`,
`assets/app.css`, `.gitignore`.

---

### 10.15 Drive organizada: todos os ficheiros no sítio certo — §25 (23 out 2026)

Base de dados **v25** (`inc/migrate_files.php`, idempotente): `files` (o registo de ficheiros:
chave atual no Drive, ETag, tamanho, origem, secção, negócio/lead, peça de marketing, referência do
Centro Criativo, estado) e `file_moves` (a fila das pastas que mudam de sítio); os caminhos do Drive
guardados noutras tabelas passam a 600 caracteres. Modelo em `inc/files.php`, lista em
`inc/files_view.php`, pedidos em `files_api.php`.

- **Estrutura** (pastas de topo fixas, não se movem nem vão para o Lixo): `Empresa/` ·
  `Leads/<NOME>_<AAAA-MM>/` · `Projetos/<ID>_<NOME>/` (em curso e concluídos) · `Marketing/<AAAA-MM>/<peça>/`
  (peças sem negócio) · `Biblioteca criativa/<quadro>/` · `Showreel e Portfolio/` ·
  `Arquivo/Negócios/<ID>_<NOME>/` e `Arquivo/Leads/<NOME>_<AAAA-MM>/`. Dentro da pasta do negócio, as
  secções nascem com o primeiro ficheiro: `Propostas/`, `Faturação/`, `Criativo/` (com `Referências/`,
  `Opção A/`, `Opção B/`, um sub-quadro por pasta), `Marketing/<peça>/`, `Produção/`, `Entregas/`,
  `Lead/`. As pastas antigas `NN_NOME_PROJECT` ligadas a um negócio mantêm o nome.
- **Gravação automática:** proposta (`<ID>_Proposta_v<n>.pdf`, gerar de novo a mesma versão
  substitui), faturas e comprovativos (`<ID>_Fatura-<número>.pdf`, `<ID>_Comprovativo_<data>.pdf`),
  originais do Centro Criativo (quadro de um negócio → `Criativo/Referências/` ou a pasta do
  sub-quadro; quadro livre → `Biblioteca criativa/<quadro>/`; sem quadro → `Biblioteca criativa/Caixa
  de entrada/`), páginas do pré-criativo em PDF ao enviar para a Orçamentação (`Criativo/Opção A|B/`),
  o tratamento em PDF quando é gerado, as peças de marketing de um negócio dentro da pasta dele, e as
  importações (CSV/vCard de contactos, ZIP/PDF/MD do Centro Criativo) em `Empresa/Importações/<AAAA-MM>/`.
  Uma imagem usada em vários quadros fica uma só vez.
- **Anexar:** «Anexar ficheiro» na ficha da lead, no painel do Pipeline, no painel «Ficheiros» da ficha
  do negócio (escolhendo a secção) e na peça de marketing; o ficheiro vai do browser diretamente para a
  pasta certa no Drive (até 5 GB; acima, pelo Drive) e fica no histórico do registo.
- **Ciclo de vida:** a pasta acompanha o registo — lead arquivada → `Arquivo/Leads/`, reativada →
  volta; lead convertida → a pasta inteira passa a `Projetos/<ID>_<NOME>/Lead/`; negócio perdido →
  `Arquivo/Negócios/` com tudo o que tem dentro; reaberto (também pelo «Anular») → volta. O destino fica
  logo ligado (o que chega a seguir já vai para lá); a cópia corre na fila — alguns segundos no
  próprio pedido e o resto pelo cron de 5 em 5 minutos (`cron_run.php cc`); a ficha mostra «A pasta
  está a mudar…». Ida e volta antes de a cópia começar anula-se. No fim, favoritos, recentes,
  partilhas, índice de conteúdo e todos os caminhos guardados (registo, Centro Criativo, faturação,
  peças) seguem a mudança — também quando alguém move uma pasta à mão no Drive.
- **Encontrar:** cada ficheiro tem «Mostrar no Drive» (abre a pasta com ele selecionado: `drive.php?f=`)
  e abre pela plataforma com as permissões verificadas; o cartão e o painel «Ficheiros» da ficha
  agrupam por secção (Propostas, Faturação, Criativo, Marketing, Produção, Entregas, Da lead, Outros),
  com origem, data, tamanho e autor — incluindo o que foi posto à mão na pasta. Se um ficheiro já não
  estiver onde o registo diz, encontra-se pela mudança em curso, pela impressão digital (ETag e
  tamanho) no Drive ou no Lixo («está no Lixo do Drive»); o registo corrige-se sozinho.
- **Faturação só para o Financeiro:** qualquer pasta `Faturação/` (ou as antigas `Faturas/`) abaixo do
  primeiro nível só aparece a quem vê o Financeiro — no Drive (catálogo, abrir, enviar, Lixo,
  pesquisa, partilhas), no assistente do Drive e nas fichas. Quem não a vê também não pode enviar
  para o Lixo nem partilhar uma pasta que a contenha.
- **Testado na bancada (MariaDB, R2 simulado):** migração v25; lead → arquivo → reativada → convertida
  → perdida → reaberta com os ficheiros e o registo a seguir; ida e volta anulada; ficheiro movido à mão
  encontrado pela impressão digital; Lixo e reposição; proposta, fatura, original do Centro Criativo
  (painel e envio no browser), páginas do pré-criativo e tratamento em PDF; anexar no browser com
  progresso; «Mostrar no Drive» com o ficheiro selecionado; faturação escondida e protegida para um
  colaborador; cron; claro/escuro e telemóvel.
- **Fase 2 — «Reorganizar o Drive»** (Definições › Drive, só administradores; `reorganizar.php`,
  `inc/files_reorg.php`, base **v26** com `file_jobs`): passa o que já existia para a estrutura nova.
  Pré-visualização, sem mexer em nada, agrupada por tipo e com caixas para escolher: documentos que
  passam a ficar registados (propostas e faturas que já estavam no Drive — não mudam de sítio),
  pastas `Faturas/` antigas → `Faturação/` (para `Faturação/Anteriores/` se já houver), peças de
  marketing de um negócio → pasta do negócio, negócios perdidos e leads arquivadas → `Arquivo/` (também
  pastas antigas encontradas pelo ID e ainda sem ligação), originais do Centro Criativo guardados no
  sistema → o seu sítio no Drive (agrupados por destino). A execução corre em segundo plano pela mesma
  fila das pastas — a página mostra o progresso enquanto está aberta e o cron continua — e termina com
  um relatório (feito, resultado de cada item, erros). Nada é apagado; links, favoritos, partilhas e
  caminhos guardados acompanham. Testado na bancada com um cenário antigo completo.

Ficheiros novos: `inc/migrate_files.php`, `inc/files.php`, `inc/files_view.php`, `files_api.php`,
`inc/files_reorg.php`, `reorganizar.php` (fase 2; também `inc/settings_def.php`).
Alterados: `inc/migrate.php`, `inc/deal_drive.php`, `inc/drive.php`, `inc/drive_store.php`,
`drive_api.php`, `ai_api.php`, `assets/drive/app.js`, `inc/leads.php`, `leads.php`, `inc/undo.php`,
`inc/pipeline.php`, `inc/pipeline_flow.php`, `inc/pipeline_view.php`, `inc/budget_pdf.php`,
`inc/cc.php`, `inc/cc_ai.php`, `inc/cc_flow.php`, `inc/cc_import.php`, `cc_api.php`,
`inc/marketing.php`, `marketing_peca.php`, `contactos.php`, `deal_flow.php`, `negocio.php`,
`inc/deal_file.php`, `inc/lead_view.php`, `inc/interactions.php`, `inc/cron_run.php`,
`assets/app.js`, `assets/app.css`, `assets/cc.js`.

### 10.16 Centro Criativo — fase 2: Review e Portal do cliente — §24 (23 out 2026)

Base de dados **v27** (`inc/migrate_review.php`, idempotente): `cc_rv_items` (peças de review por
negócio e frente, etapa de aprovação, estado, marca de água, download, aprovação com nome, e-mail, IP e
data), `cc_rv_versions` (v001, v002… — nunca se apagam; original, proxy, poster, tira, páginas de PDF,
marca de água, fps, frames, duração, timecode de início), `cc_rv_rounds` (uma ronda por envio ao cliente,
fechada pelo envio consolidado), `cc_share_log` (acessos e ações no portal) e `cc_portal_hits` (limite
por IP); `cc_comments` ganha peça, frame/intervalo, pino (x, y, página), visibilidade, ronda, rascunho do
cliente e anexos; `cc_share` ganha o link cifrado (AES-256-GCM, para «Copiar link»), separadores e idioma.
Modelo em `inc/cc_review.php`, pedidos da equipa em `rv_api.php`, leitor em `assets/review.js`.

- **Versões e vídeo no servidor:** o original vai do browser direto para o Drive, em
  `<pasta do negócio>/Review/<peça>/v00N_<nome>` (registado na secção Review). O cron (`cron_run.php cc`,
  pela linha de comandos) lê-o com o `ffprobe` (fps, frames, duração, dimensões, timecode de início, drop
  frame em 29,97/59,94) e faz com o `ffmpeg` o proxy H.264 (CFR, GOP de meio segundo para procurar ao
  frame, até 1920 px, AAC, faststart), o poster e a tira de miniaturas; ao enviar ao cliente, o proxy com
  a marca de água (logótipo topper. e nome do cliente). O cron diz às páginas se há ffmpeg; sem ele, o browser faz o
  poster e a tira e o original serve de proxy (fps e timecode por confirmar). Imagens: proxy e poster
  no pedido (GD). PDF: as páginas são desenhadas no browser (pdf.js, `assets/vendor/pdfjs/`, Apache 2.0)
  e guardadas em imagem.
- **Leitor (sala da peça, `criativo.php?rv=<peça>`):** ← → um frame, Shift ±1 s, J/K/L (J anda para trás
  frame a frame), velocidade, I/O e repetir o intervalo, timecode SMPTE (ou frames, num clique), tira de
  miniaturas com os marcadores dos comentários (cliente amarelo, interno azul, para o cliente salmão,
  resolvidos a cinzento), ecrã inteiro. Desenho vetorial por cima do frame (mão livre, seta, retângulo,
  círculo, texto, 7 cores, anular o último traço); pinos numerados em imagens e páginas de PDF.
  Comentários em fio, presos ao frame, ao intervalo, ao pino ou gerais, com @menções (avisam a pessoa),
  anexos (vão para `Review/<peça>/Anexos/`), Resolver/Reabrir, Apagar com «Anular», «Internos» ou «Para o
  cliente» (muda-se depois). Comparar duas versões lado a lado, em cortina ou sobreposição (opacidade
  ou diferença), sempre no mesmo frame. Exportar os comentários de uma versão em PDF, CSV, EDL (marcadores
  DaVinci Resolve), XML FCP7 (Premiere Pro, DaVinci) e lista de marcadores do Avid — todos ou só os do
  cliente. Histórico da peça com ícones (versões, envios, rondas, aprovação, visitas do cliente).
  Testado ao frame na bancada (frame 37 = 01:00:01:12 a 25 fps; 29,97 DF: 00:59:59;00 + 30 = 01:00:00;00).
- **Rondas e aprovação:** «Enviar ao cliente» põe a versão no portal e abre uma ronda; o cliente escreve
  em rascunho e «Enviar comentários» manda tudo de uma vez — uma ronda, que conta nas rondas da etapa do
  negócio (ou da frente). A sala mostra «Ronda X de Y incluídas»; na última o aviso, acima do incluído o
  Comercial é avisado e nasce a tarefa «Pedido de alteração · <peça>» (uma por peça). «Aprovar» (equipa,
  ou cliente com o nome escrito e confirmação; fica a data, o IP e a versão) conclui a tarefa de
  aprovação do Pipeline pelo mapa de Definições › Centro Criativo e guarda a versão na Biblioteca como
  referência da casa (tags «aprovado» e a etapa). «Pedir alterações» devolve a peça à equipa.
- **Portal do cliente (`portal.php?t=…`):** um link por negócio, token longo e aleatório (só o hash e
  uma cópia cifrada na base), revogável, renovável e com validade (por defeito 60 dias depois da entrega).
  Sem conta e sem sessão: nome e e-mail na primeira ação (guardados no browser), comentários como
  «Nome», vários aprovadores. Separadores só os partilhados: Proposta (opções, condições e o pré-criativo),
  Quadros (só leitura: tratamento e rotas dos quadros escolhidos) e Review (as peças enviadas, só versões
  enviadas, só comentários «Para o cliente»). Nunca custos, margens, notas, tarefas, e-mails da equipa nem
  comentários internos (verificado no JSON). GET nunca altera nada (até o registo de acesso vai por POST);
  as ações levam um formulário assinado pelo servidor e a mesma origem. Ficheiros por URLs assinados de
  curta duração (5 min imagens, 15 min vídeo), sempre revalidados; marca de água por peça — logótipo topper. e nome do
  cliente (no vídeo pelo ffmpeg, nas imagens e páginas pelo GD, e por cima no browser enquanto o vídeo não está pronto);
  download do original por peça; noindex, limite de pedidos por IP, registo de acessos. PT/EN (por link,
  e o cliente pode trocar), claro/escuro, telemóvel.
- **Ficha do negócio:** cartões «Review» (peças e estado) e «Portal do cliente» (criar, copiar, abrir,
  renovar, revogar, validade, idioma, separadores, quadros partilhados, acessos e ações com ícones,
  nome, e-mail e IP). Notificações ao comercial e ao responsável de execução em cada envio de comentários
  e aprovação do cliente; @menções do Review no sino.
- **Definições › Centro Criativo › Review e Portal do cliente:** validade do link, marca de água e
  download por defeito nas peças novas, idioma do portal, rondas incluídas (nos funis) e o mapa etapa de
  review → tarefa de aprovação.
- **Na publicação:** instalar o ffmpeg/ffprobe estático em `~/bin/` do alojamento (o cron encontra-o;
  sem ele, o Review funciona com o original e o que o browser prepara).

Ficheiros novos: `inc/migrate_review.php`, `inc/cc_review.php`, `rv_api.php`, `portal.php`,
`assets/review.js`, `assets/review.css`, `assets/portal.js`, `assets/portal.css`, `assets/rv_share.js`,
`assets/vendor/pdfjs/` (pdf.js 4.10.38). Alterados: `inc/migrate.php`, `inc/cc.php` (fila só pelo cron
para o vídeo), `inc/cron_run.php`, `inc/files.php`, `inc/interactions.php`, `inc/alerts.php`,
`inc/settings_def.php`, `criativo.php`, `negocio.php`.

### 10.17 Centro Criativo — fase 3: Tempo real e captura — §26 (23 out 2026)

Base de dados **v28** (`inc/migrate_rt.php`, idempotente): `cc_board_versions` (histórico do quadro:
estado dos cartões por versão, tipo e autor), `user_tokens` (tokens pessoais: só o hash, os últimos 4
caracteres, último uso, IP e browser, revogação), `rt_usage` e `rt_usage_win` (uso do tempo real por
dia e quadro); `cc_comments.reactions` e `cc_assets.capture` (de onde veio o que se guardou de fora).
Modelo em `inc/cc_rt.php` e `inc/capture.php`.

- **Serviço de tempo real (`rt/`):** Worker da Cloudflare com PartyServer / y-partyserver e um Durable
  Object por quadro (sala `b<id>`), SQLite do próprio objeto e API de hibernação (um quadro sem ninguém
  não gasta). Entra-se só com um token curto (10 min) que o PHP assina com ECDSA P-256 (ES256, pelo OpenSSL — o alojamento não tem sodium): a chave privada fica
  em `settings` no servidor; a pública (não é segredo) o Worker lê-a de `/admin/rt_key.php` e guarda-a
  10 min (ou fixa-se em `RT_PUBLIC_KEY`) — não há segredo nenhum no Worker nem no código. O token diz o quadro, a pessoa e o papel: `edit` escreve,
  `view` só lê (o servidor ignora as alterações), `portal` só lê e não envia nem recebe presença (o
  servidor filtra). Origens permitidas: topperhub.xyz e topperdigital.xyz. Testado com `wrangler dev`:
  token de outro quadro recusado, leitor sem escrita, portal sem cursores, ~13 ms entre dois browsers.
- **Quadro em tempo real (`assets/cc_rt.js`, ligado pelo `cc.js`):** a base de dados continua a ser o
  registo — cada pessoa grava o que muda (como antes) e o Yjs leva logo aos outros: mover (também durante
  o arrasto), redimensionar, editar, cartões novos e apagados; ações feitas no servidor (Arrumar,
  Preparar com IA, restaurar, páginas) avisam os outros para recarregar. Ao entrar, ganha o mais recente
  (a base ou o documento), com a hora do servidor. Presença: avatares no topo (iniciais e a cor da
  Equipa), cursores com nome, seleções dos outros contornadas na cor da pessoa, «Ana está a escrever…»
  nas notas. **Seguir**: clique no avatar; sai com Esc ou ao mexer na tela. **Sem ligação**: continua a
  trabalhar, as alterações ficam numa fila (no browser) e gravam-se ao voltar, com aviso discreto
  (ponto verde/âmbar e texto ao lado dos avatares). «Anular» continua a ser de cada pessoa.
  **Histórico** (separador do painel lateral): uma versão por sessão de trabalho (antes da primeira
  alteração, se a última tem mais de 30 min) e antes de Arrumar o quadro, Preparar com IA e apagar 5 ou
  mais cartões; «Guardar esta versão», **Ver** (miniatura da versão) e **Restaurar** (guarda primeiro o
  estado de agora). **Comentários** em tempo real: os outros veem logo os novos, resolvidos e apagados;
  @menção avisa a pessoa no momento (e no sino, como antes); **reações** 👍 ❤️ 🔥 ✅ 👀 😂.
- **Permissões no servidor:** um quadro de um negócio só abre (página, API e token) para quem tem
  acesso ao negócio — no âmbito em Negócios, responsável de execução (do negócio ou de uma frente),
  com tarefas no negócio, ou quem criou o quadro; a lista de Projetos mostra só esses.
- **Portal do cliente:** nos Quadros partilhados liga-se só de leitura e sem presença; quando a equipa
  refaz as páginas aparece «A equipa atualizou este quadro · Atualizar».
- **Plano gratuito e uso:** os browsers contam o que enviam (ligações, mensagens, janelas de 10 s com
  atividade) e o PHP soma por dia (UTC): pedidos ≈ ligações × 2 + mensagens ÷ 20; GB-s ≈ janelas × 10 s ×
  128 MB. Definições › Integrações mostra o uso do dia (barra e números); aos 80% os admins são avisados
  no sino; aos 100% o tempo real fica em pausa até às 00:00 UTC e os quadros continuam como antes
  (gravam e atualizam a cada 8 s). Com o tempo real ligado, a sondagem passa a 30 s (rede de segurança).
- **Extensão «Guardar na topper.» (`ext/chrome/`, Chrome, Edge, Brave, Arc):** descarrega-se em
  Perfil › Ligações (ZIP já com o endereço; «Carregar sem compactação» no modo de programador — não está
  na Chrome Web Store). Botão direito numa imagem, vídeo, link ou texto selecionado › «Guardar na
  topper.» › Caixa de entrada ou um dos quadros recentes. O botão abre o popup: destino (recentes ou
  procurar), nota, «Guardar esta página», «Capturar uma área» e «Frame do vídeo (m:ss)» (YouTube, Vimeo,
  Instagram e qualquer `<video>`: o frame à vista, com o link no tempo certo — `&t=83s` no YouTube).
  Guarda a página de origem e o autor/créditos quando a página os diz. Atalhos Alt+Shift+S (abrir) e
  Alt+Shift+A (área), configuráveis. Entra com um **token pessoal** (Perfil › Ligações, revogável pela
  pessoa e por qualquer admin em Definições › Integrações); nunca pede nem guarda a palavra-passe.
  Testado num Chromium com a extensão carregada (ligar, quadros no menu, área cortada e guardada,
  token inválido recusado).
- **Telemóvel e tablet:** a plataforma é instalável (PWA: `manifest.php`, `sw.js`, ícones em
  `assets/pwa/`). No Android, «topper.» aparece no menu Partilhar (Web Share Target): o service worker
  guarda o que chega e `partilhar.php` mostra-o, com destino e nota (sessão normal, sem token). No
  iPhone/iPad, o Atalho do iOS «Guardar na topper.» com os passos em Perfil › Ligações (o mesmo token);
  um admin pode pôr o link de partilha do Atalho em Definições para os outros instalarem com um toque.
- **Tudo o que é capturado** entra na Caixa de entrada (mesmo o que já existia) e vai para a análise da
  IA (§23.5); com um quadro, fica também lá como cartão (o texto selecionado vira nota ao lado).
  O detalhe da referência mostra «Guardado de fora»: por onde, a página (com o tempo), o autor, o texto
  e a nota.
- **Definições:** Centro Criativo › Histórico dos quadros (versão a cada N min de trabalho, versões
  guardadas por quadro) e Captura (destino por defeito: Caixa de entrada ou o último quadro usado);
  Integrações › Tempo real (estado, custo 0 €, uso do dia, endereço do serviço, ligar/desligar e o
  comando para publicar) e Captura (tokens de todos, com Revogar; link do Atalho do iOS).
- **Publicado a 23 out 2026:** Worker `topper-rt` em `https://topper-rt.topperhub.workers.dev`
  (subdomínio workers.dev da conta: `topperhub`), Durable Object `Board` com SQLite; «Endereço do
  serviço» já preenchido. Verificado a partir do servidor da topper: `/health` 200, token válido 101,
  token de outro quadro e assinatura errada 401, origem estranha 403 (a chave pública lida de
  `/admin/rt_key.php` funciona).
- **Para publicar de novo (só se o código de `rt/` mudar):** `cd rt && npm ci && npx wrangler deploy`
  (também em Definições › Integrações › Tempo real). Se o endereço mudar (ex.: rt.topperhub.xyz, se o
  domínio passar para a Cloudflare), atualizar «Endereço do serviço». Sem o serviço, tudo o resto
  funciona e os quadros atualizam como antes.

Ficheiros novos: `rt/` (Worker: `src/index.js`, `wrangler.toml`, `client/entry.js`, `package.json`),
`admin/assets/vendor/rt/topper-rt.js` (Yjs + y-partyserver + y-indexeddb, MIT), `inc/migrate_rt.php`,
`inc/cc_rt.php`, `inc/capture.php`, `capture_api.php`, `partilhar.php`, `manifest.php`, `sw.js`, `rt_key.php`,
`ext.php`, `ext/chrome/` (extensão), `assets/cc_rt.js`, `assets/share.js`, `assets/pwa/`. Alterados:
`inc/migrate.php`, `inc/bootstrap.php` (`csp_send()`: o quadro e o portal juntam o serviço ao
connect-src), `inc/cc.php`, `inc/alerts.php`, `inc/cron_run.php`, `inc/settings_def.php`, `inc/layout.php`
(manifest e service worker), `inc/ui.php`, `cc_api.php`, `criativo.php`, `portal.php`, `conta.php`,
`definicoes.php`, `assets/cc.js`, `assets/app.js` (avisos com botão), CSS.

### 10.18 Centro Criativo — Áudio — §27 (23 out 2026)

Base de dados **v29** (`inc/migrate_audio.php`, idempotente): `cc_assets.audio_cat` (categoria),
`cc_assets.peaks` e `cc_rv_versions.peaks` (a forma de onda: 600 picos 0–255 em base64). A migração
converte as referências e os cartões «Ficheiro» que eram áudio (mp3, wav, m4a, aac, flac, ogg, aif,
aiff — pelo tipo ou pela extensão) para o tipo `audio`; a forma de onda dos antigos é feita na
primeira vez que alguém com edição os vê.

- **Tipo «Áudio»:** `CC_CARD_TYPES` e `cc_kind_for` conhecem `audio`; `cc_card_type_for` decide o
  cartão em todo o lado (envio, Drive, link, captura, trocar, Preparar com IA). Os tipos, as extensões e
  o ícone vêm da Drive (`assets/drive/core.js`: `TD.kind`, `TD.icon('audio')`), carregada nas páginas
  do Centro Criativo.
- **No browser (`assets/cc_audio.js`):** ao carregar, o Web Audio lê o ficheiro (até 150 MB) e faz a
  forma de onda (guardada, não se recalcula), as medidas (BPM estimado com confiança, energia, pico,
  dinâmica, brilho, silêncio) e uma imagem com a forma de onda e o espectrograma (escala log) — é o que
  a IA «vê». O **leitor único** da página garante um áudio de cada vez (e pára se um vídeo começar).
- **Cartão no quadro:** tocar/pausar, nome, categoria e duração (o tempo corre ao tocar), forma de onda
  com o progresso e clique para ir a esse ponto. Duplo clique abre a **vista aberta** (também na
  gaveta da Biblioteca): forma de onda grande (clique = ir; arrastar = marcar a repetição A–B),
  velocidade 0,5×–2×, A / B / sem repetição, volume, transcrição com os tempos clicáveis e «Copiar»,
  e a análise editável (categoria, energia, BPM, ambiente, estilo, instrumentos, uso sugerido). Formato
  que o browser não toca: aviso e «Original» para descarregar.
- **Biblioteca:** filtro «Áudio» (inclui os leitores de música), grupo «Áudio» com as categorias
  Música · Voz-off · Efeito sonoro (SFX) · Ambiente · Outro e as quantidades; mosaico com a forma de
  onda e tocar; a pesquisa inclui a transcrição, a categoria e a análise; «Capturar» aceita áudio.
- **Review e Portal:** uma peça pode ser áudio. A forma de onda fica no lugar da imagem e da tira, com
  os comentários marcados no tempo (m:ss,d) por cima; as mesmas teclas (espaço, J K L, I O, ← →), sem
  desenho nem pinos. **Comparar** duas versões: as duas formas de onda e «Ouvir A» / «Ouvir B» (ou as
  teclas A e B) no mesmo ponto. Aprovação, rondas, permissões e avisos como no vídeo; a versão aprovada
  entra na Biblioteca como áudio (com a forma de onda). MP3, WAV, M4A, AAC, FLAC e OGG tocam como estão;
  **AIFF** passa a MP3 no cron (com ffmpeg), que faz também a forma de onda. No portal o áudio não leva
  marca de água (só a imagem da forma de onda).
- **IA (no limite mensal de sempre):** **transcrição** com marcas de tempo pelo serviço de fala
  configurado no servidor — proposta: **OpenAI Whisper** (`whisper-1`, ~0,006 US$/min) ou **Groq**
  (mesma API) — em `admin/inc/cc_config.php` (`transcribe_key`, `transcribe_model`, `transcribe_url`,
  `transcribe_usd_min`, `transcribe_lang`; exemplo em `cc_config.example.php`). Serve áudio e **vídeo**
  (o campo `transcript` que a análise do vídeo já lia): até 25 MB em formato aceite vai direto; vídeos,
  AIFF e ficheiros grandes passam pelo ffmpeg no cron (mono 16 kHz). Fica uma linha por frase,
  «[m:ss] texto»; custo registado como «Transcrição (fala)». Depois, a **análise da música** pelo Claude
  (espectrograma + medidas + transcrição + contexto): categoria, ambiente, estilo, instrumentos, BPM
  (o medido quando é fiável), energia, uso sugerido, porquê e etiquetas — editável. Definições ›
  Integrações › Centro Criativo mostra o estado da transcrição (ou a proposta, se ainda não houver chave).
- **Captura:** link direto para um ficheiro de áudio ou uma página com pré-escuta (`og:audio`, comum
  nas bibliotecas de música) → descarrega (até 50 MB) e cria um áudio a sério, com título e fonte;
  SoundCloud, Spotify, Mixcloud, Apple Music e Bandcamp → cartão de áudio com o **leitor do serviço**
  (botão «Ouvir aqui»; a CSP das páginas do Centro Criativo deixa estes leitores). Pela extensão,
  Partilhar e Atalho, igual. **Arrastar da Drive** para o quadro (um ou vários ficheiros) cria os cartões
  com o tipo certo, sem copiar o original (fica na Drive).
- **Testado na bancada:** conversão dos cartões antigos; envio MP3/WAV (forma de onda, cartão, um a
  tocar de cada vez, vista aberta com A–B e 1,5×); Biblioteca (filtro, categorias, pesquisa pela
  transcrição); Drive → quadro (WAV e AIFF; AIFF com forma de onda, transcrição e análise pelo cron);
  Review (2 versões, comentário no tempo, comparar a ouvir B, aprovar → Biblioteca) e Portal (AIFF
  convertido, tocar); transcrição e análise com serviços simulados; captura por link (com um servidor
  local, a bancada não tem DNS); claro/escuro e telemóvel sem deslocamento lateral; sem «§» na interface.
- **Também corrigido:** o duplo clique nos cartões (com a captura do ponteiro chegava como clique na
  tela e criava uma nota) passa a abrir o cartão certo.

Ficheiros novos: `inc/migrate_audio.php`, `inc/cc_stt.php`, `assets/cc_audio.js`. Alterados:
`inc/migrate.php`, `inc/cc.php`, `inc/cc_ai.php`, `inc/cc_review.php`, `inc/capture.php`,
`inc/bootstrap.php` (`csp_send()` aceita frame-src), `inc/settings_def.php`, `inc/cc_config.example.php`,
`cc_api.php`, `rv_api.php`, `portal.php`, `criativo.php`, `definicoes.php`, `assets/cc.js`,
`assets/review.js`, `assets/cc.css`, `assets/review.css`.

### 10.19 Financeiro — §28 (23 out 2026)

Base de dados **v30** (`inc/migrate_finance.php`, idempotente). Tabelas `fin_params` + `fin_param_log`
(parâmetros), `fin_irs` (tabelas de retenção), `fin_docs` (todos os lançamentos), `fin_payments`,
`fin_fixed` (custos fixos), `fin_accounts` / `fin_imports` / `fin_bank_tx` (tesouraria), `fin_employees`
/ `fin_payroll` (salários), `fin_scenarios` (simulador), `fin_mail` (documentos que chegam por email), `fin_done`
(obrigações já com tarefa); `contacts.wh_exempt` e `contacts.vat_exempt`.

- **Migração dos dados atuais, sem perder nada:** os custos da empresa viviam em `costs` (sem negócio)
  desde a passagem para a plataforma (a `company_costs` é a tabela da versão 10, já copiada para `costs`).
  Cada um passa a lançamento «Despesa · Empresa» (base/IVA pelo modo de IVA de então, mês → data, «pago»
  → «Paga»), com `legacy_cost_id` para nunca se repetir; as linhas originais ficam onde estavam. Se a
  passagem da versão 10 não tiver sido feita, entram também as de `company_costs` (`legacy_cc_id`).
  Os negócios antigos sem fatura continuam em «A receber» («Sem fatura») e no «pago» de antes.
- **Parâmetros (nada fixo no código):** SS 11% / TSU 23,75%, FCT 0%, seguro de acidentes (taxa da
  apólice), salário mínimo 920 €, refeição isenta 6,15 € / 10,46 €, meses dos subsídios, IRS (ano das
  tabelas, adicionais por deficiência, −1 p.p. com 3+ dependentes), retenções 23% / 25%, IVA 23/13/6% e
  período, menção de autoliquidação, IRC 15% até 50 000 € / 19% e derrama, viaturas (IVA dedutível,
  limites, depreciação, tributação autónoma por escalão e motorização), amortizações, runway (N = 6 e
  limiar, só admins), cenários 0,5× / 1,5×, probabilidade por etapa e todas as datas do calendário
  fiscal — cada um com fonte legal, ano e data de verificação (vazia = por confirmar). Definições ›
  Financeiro edita-os (só superadmin/admins, com «Anular» e registo); lá estão também o email do
  contabilista, o IBAN, o prazo de pagamento, o responsável financeiro, as categorias e as **tabelas I–VII
  do Despacho n.º 233-A/2026** (continente, guardadas como dados; «Importar tabelas novas» por ano/região).
- **Acesso:** ver a aba Financeiro + nível por ação (calcular no simulador e o saldo semanal do responsável
  pedem só «ver»; tudo o resto «editar» ou mais); ficheiros e exportações por `fin_file.php`
  (só Financeiro; o recibo de vencimento também à própria pessoa); `fin_api.php` idem. O papel «Chefe de
  equipa» deixa de ver o Financeiro por omissão. A pasta da Drive `Empresa/Financeiro/AAAA/MM/` conta como
  faturação (só Financeiro). A ficha de salário na Equipa só aparece a quem tem Financeiro.
- **Lançamentos (`inc/fin.php`):** sentido, âmbito (Empresa ou Negócio + frente), contraparte/NIF,
  categoria, base/IVA/total/retenção/moeda, datas, PDF + comprovativos + recibo. Validação no servidor:
  âmbito sempre, negócio obrigatório no âmbito Negócio, valores que batem, duplicados (NIF + nº). As
  despesas de um negócio entram em `deal_costs()` (margem da ficha, Orçamentação, Resultado) e aparecem na
  ficha só para ver («No Financeiro»). Os custos da empresa (`company_cost_rows`, `budget_fixed_average`)
  passam a ler os lançamentos.
- **A receber:** Fatura pedida → Emitida → Parcialmente paga → Paga → Recibo arquivado · Anulada. Só fica
  paga com comprovativo ou movimento conciliado; pagamentos parciais; antiguidade em dia / 1–30 / 31–60 /
  61–90 / +90 pelo vencimento (barra empilhada clicável; alertas, Dashboard e ficha usam os dias de
  atraso). O PDF submetido na tarefa «Emitir fatura» é o mesmo lançamento (`fin_from_billing`); «Validar
  pagamento» com comprovativo regista-o na fatura em aberto. Lembrete ao cliente: rascunho da IA (tom pelo
  atraso), só sai depois de aprovado. Quando todas as faturas do negócio estão pagas, o negócio fica pago.
- **A pagar:** Por confirmar → Por pagar → Paga → Recibo arquivado · Em disputa; vistas «esta semana»,
  «vencidas», 30 dias. Largar o PDF: a IA (`inc/fin_ai.php`, o Claude do assistente) lê emitente, NIF,
  número, datas e valores e sugere categoria/âmbito/retenção pelo histórico do NIF; tudo fica por confirmar.
  Freelancer: retenção do parâmetro (0% com dispensa na ficha do fornecedor), «pagar ao freelancer» e
  «entregar ao Estado»; rendas: 25%.
- **Custos fixos:** periodicidade → valor mensal; geram «Por pagar» em cada vencimento (cron, sem repetir);
  os salários entram sozinhos (custo anual ÷ 12); mudanças de mês para mês; barras por categoria.
- **Salários (`inc/fin_pay.php`):** ficha (vencimento, refeição dinheiro/cartão, situação IRS,
  dependentes, deficiência, residência, duodécimos, IBAN, NIF, NISS, contrato, entrada). Cálculo: IRS pela
  tabela (fórmula R × taxa − parcela, com «k» nos primeiros escalões, − dependentes), subsídios retidos
  autonomamente ou em duodécimos à taxa efetiva, excesso da refeição tributado, SS 11% e TSU 23,75%,
  seguro e FCT. Conferido à mão (ex.: 1 500 € → IRS 168,17 €, líquido 1 396,95 €, custo 2 086,37 €).
  «Emitir» grava o recibo em PDF (enviado à pessoa e no Perfil dela) e três lançamentos: líquido, IRS
  (pagar até dia 20) e SS 34,75%; resumo CSV para o contabilista; «Anular» o mês.
- **Tesouraria e runway (`inc/fin_cash.php`):** contas, importação CSV (cabeçalhos de bancos PT, débito/
  crédito, saldo) e OFX, sem repetir movimentos; conciliação com sugestão por valor, data, NIF, nº e nome
  (conta como comprovativo) ou «criar lançamento»; saldo disponível = contas − compromissos até ao fim do
  mês (faturas, salários/IRS/SS, IVA); previsão 6/12 meses. Runway total e útil (total − N), leituras
  Conservador e Com tendência («Sustentável — o saldo cresce X €/mês»); alerta aos admins abaixo do limiar
  e ao entrar na reserva. Tendência: recebido e faturado, médias de 3 e 6 meses, crescimento composto de
  6 e 12, projeção com banda pessimista–otimista e o pipeline (ganhos por faturar; propostas × probabilidade).
- **Simulador (`inc/fin_sim.php`):** contratar, arrendar escritório, investir, comprar viatura (IVA
  dedutível, consumos, depreciação aceite, tributação autónoma, crédito/renting, comparação das
  motorizações a 4 anos); custo mensal e anual, de uma vez, poupança de IRC (diferença do imposto com e sem
  o gasto), runway útil antes → depois, mês em que entra na reserva, receita extra (custo ÷ margem média);
  atualiza enquanto se escreve; cenários guardados, lado a lado e «Aplicar» (vira custo fixo previsto);
  aviso fixo «Estimativa com as regras de [ano]. Confirma com o contabilista antes de decidir.»
- **Impostos (`inc/fin_tax.php`):** IVA por período a partir dos lançamentos (e dos negócios antigos), com
  as datas de declaração e pagamento; calendário fiscal (DMR, SS, retenções, IVA, anuais) com tarefa ao
  responsável financeiro 10 dias antes; ZIP do mês (PDFs + CSV dos lançamentos e dos salários).
- **Contabilista (`inc/fin_mail.php`, §28.14):** «Pedir fatura» (no Financeiro e na tarefa «Emitir
  fatura») envia por SMTP `[topper FAT-2026-0001] Pedido de fatura — ID…` e cria «Fatura pedida». Liga
  pelo código; sem código, pelos dados (NIF do cliente, total, data) e só sozinho com confiança alta e um
  candidato claro — senão «Por confirmar» com as sugestões; compara valores e NIF (avisa), passa a
  «Emitida», conclui a tarefa e avisa o responsável e o comercial («A fatura … do … está pronta para enviar
  ao cliente»). «Enviar ao cliente» (PDF, IBAN, vencimento) fica no histórico do negócio e do contacto.
  Recibos («Pedir recibo», REC-…) pelo mesmo circuito.
- **Leitura das caixas (revista a 24 out 2026, §28.14 ponto 2; base de dados v32: `fin_mail.box` e
  `fin_mail.source`):**
  - **Caixas existentes, várias:** Definições › Integrações › Email › «Caixas a ler» (ex.:
    info@topperdigital.xyz), uma por linha. Não é precisa uma caixa dedicada.
  - **Palavras-passe pela plataforma, guardadas só no servidor (24 out 2026):** em «Estado das caixas» cada
    caixa (lidas e o email geral) tem um campo só de escrita (só superadmin/admins); cada pessoa põe a do seu
    próprio email no Perfil › Email de envio. Antes de gravar, a plataforma entra na caixa por IMAP (só ler);
    se estiver certa, grava-a em `inc/fin_mail_config.php` (`boxes` → endereço => palavra-passe; ficheiro
    fora do Git, fechado à web, 0600, escrita atómica). Nunca na base de dados, no registo (diz só «guardada
    no servidor») nem na página; «Tirar» apaga-a do ficheiro.
  - **Só leitura:** `EXAMINE` (nunca `SELECT`) e `BODY.PEEK` — não marca `\Seen`, não move, não apaga;
    o cliente IMAP não tem comandos de escrita. Guarda por caixa o último UID visto e o UIDVALIDITY
    (`settings.fin_mail_state`); na primeira leitura, ou se o UIDVALIDITY mudar, olha só os últimos 7 dias.
    A mesma mensagem em duas caixas (Message-ID) trata-se uma vez.
  - **Filtro barato primeiro:** cabeçalhos e tamanho primeiro; remetentes a ignorar (lista editável:
    endereço ou @domínio) e mensagens sem anexos nem chegam a ser descarregadas. Só os anexos PDF, XML e
    imagem (≥ 30 KB) são analisados; até 15 mensagens por caixa e volta do cron, máx. 25 MB cada.
  - **QR fiscal primeiro, IA depois (`inc/fin_qr.php`, PHP puro, sem bibliotecas):** lê o QR code da
    Portaria n.º 195/2020 — no PDF (texto do QR, quadrados vetoriais, imagens Flate/DCT/máscara, páginas
    digitalizadas) ou na imagem — e tira NIF do emitente e do adquirente, tipo, número, ATCUD, data, bases
    e IVA por taxa, total e retenção; descodificador próprio (localização, amostragem, Reed-Solomon).
    Faturas eletrónicas XML (UBL 2.1 / CIUS-PT) também sem IA. A IA só lê os anexos sem QR claro.
  - **Classificação pelo NIF da empresa (Definições › Geral):** adquirente = topper. → despesa em «A
    pagar», por confirmar (nome pelo contacto com esse NIF; recibos «RC» juntam-se à fatura do
    fornecedor); emitente = topper. → receita, ligada ao pedido «Fatura pedida». Documentos entre
    terceiros, orçamentos e anulados: ignorados, nada fica guardado. Sem o NIF da empresa, fica tudo por
    confirmar (com aviso em Definições).
  - **Privacidade:** o corpo dos emails nunca é guardado (o código FAT/REC lê-se em memória); fica só o
    anexo financeiro (Drive, Empresa/Financeiro) e o remetente, o assunto, a data e a caixa.
  - **Estado e «Testar» por caixa** em Definições › Integrações › Email: última leitura, erro, mensagens
    vistas e com documento, UID; «Testar» entra só para ler e autentica o SMTP, sem enviar nada.
- **Quem envia (24 out 2026):** os emails que alguém envia pela plataforma (pedidos ao contabilista, faturas
  e lembretes aos clientes, recibos de vencimento, propostas, convites, reuniões) levam o nome dessa pessoa
  e saem do seu email de registo (se for do domínio da empresa e tiver a palavra-passe no servidor) ou do
  **email geral** (Definições › Integrações › Email, por omissão info@topperdigital.xyz). A escolha fica no
  Perfil › Email de envio e pode mudar-se em cada envio («Enviar de»); se a escolhida não estiver pronta,
  usa a outra. Os pedidos ao contabilista enviados de uma caixa que não é lida levam Reply-To para a
  primeira caixa lida, para a resposta voltar a ser lida. Os emails automáticos (recuperar palavra-passe,
  confirmações ao cliente, cron) continuam com o nome e o endereço dos «Emails automáticos».
- **Gráficos (`inc/charts.php`):** runway (barra com reserva tracejada), fluxo de caixa 12 meses (entradas/
  saídas + saldo, previsão tracejada), antiguidade empilhada, custos fixos por categoria, receita com
  tendência; dicas com valores exatos, animação de entrada (desligada com «reduzir movimento»), claro/escuro.
  Quatro números no topo: saldo disponível · por receber (atraso a vermelho) · a pagar 30 dias · runway útil.
- **Anular:** todas as ações (lançar, editar, estados, pagamentos, recibos, custos fixos, fichas, recibos
  de vencimento, conciliação, cenários, parâmetros) têm «Anular» (`fin_undo_apply`).
- **Testado na bancada:** migração (8 custos → lançamentos), cálculos do IRS/SS contra contas à mão,
  circuito do contabilista com SMTP/IMAP simulados e a IA simulada, pagamentos parciais e comprovativo,
  conciliação CSV/OFX, custos fixos, salários, permissões (colaborador, outro colaborador, API),
  claro/escuro e telemóvel (390 px sem deslocamento horizontal). Leitura revista: o descodificador nas 40
  versões × 4 níveis de correção e com erros; QR em PNG/JPEG, rodado, desfocado, página digitalizada, PDF
  com imagem, JPEG, 1 píxel por módulo, máscara de 1 bit, vetorial e em texto; duas caixas simuladas que
  registam todos os comandos (só EXAMINE/PEEK, ficheiros intactos, UID por caixa, segunda volta sem nada
  novo), despesa, receita ligada ao pedido pelo QR, código, XML, recibo, foto, ignorados (antigos,
  remetente, sem anexo, terceiros, orçamento), IA só sem QR/XML, corpo nunca guardado.
- **Para ligar em produção:** escolher as caixas em Definições › Integrações › Email e escrever lá a
  palavra-passe de cada uma (e cada pessoa a do seu email no Perfil); confirmar o NIF da
  empresa em Definições › Geral; indicar o email do contabilista e o IBAN em Definições › Financeiro; a taxa do seguro de acidentes; a IA
  de leitura de PDFs usa a mesma chave do assistente (`ai_config.php`) — sem ela, preenche-se à mão.

### 10.20 Tesouraria semanal e reservas dos sócios — §28.7 e §28.15 (24 out 2026)

Base de dados **v31** (`migrate_finance_31`, idempotente): `fin_accounts.owner_id / balance_by /
balance_at`; `fin_balance_log` (cada atualização de saldo: valor, data, quem, quando); `fin_reserve_rules`
(negócio, sócio, % ou valor fixo, nota) e `fin_reserve_payouts` (levantamentos com data, conta e
comprovativo). Sem contas, cria uma «Conta principal» sem saldo (o nome muda-se em Editar).
- **Saldos (`inc/fin_res.php`):** Definições › Financeiro › Tesouraria tem o **responsável pelos saldos**
  (só quem vê o Financeiro; vazio = responsável financeiro) e o **dia da atualização** (segunda a domingo).
  Cada conta pode ter um responsável próprio (só superadmin/admins o escolhem). No dia, o cron dá a cada
  responsável com contas por atualizar a tarefa «Atualizar os saldos das contas» (uma por semana,
  `fin_done` `bal:<utilizador>:<semana ISO>`); a tarefa conclui sozinha quando as contas dele ficam em dia
  (saldo do dia ou da véspera). Saldo com mais de 7 dias, ou nunca atualizado: aviso na Tesouraria e no sino.
  «Atualizar saldo» (modal: valor e data, sem extrato) pode o responsável da conta mesmo só com «ver», e
  quem edita o Financeiro; importar um extrato com saldo conta como atualização. «Anular» repõe o saldo.
- **Contas:** «Nova conta» e «Editar» em modal (nome, tipo, IBAN, ativa, responsável; saldo inicial só na
  conta nova). «Importar extrato» também em modal. Os valores aceitam «2.500» e «2.500,00» como milhares.
- **Reservas:** só superadmin/admins criam, apagam e registam levantamentos (servidor: 403 aos outros).
  % sobre o que o cliente pagou (sem IVA, pelos pagamentos; negócios de antes das faturas: o «pago») ou
  valor fixo que acumula na proporção do pago. Entram em `deal_costs()` como custo sem IVA (margem da ficha,
  Orçamentação, Relatórios); na ficha do negócio, só leitura («Reserva · sócio» para admins, «Reserva da
  equipa» para os outros). `fin_cash()` tira o reservado por levantar do saldo disponível e, por isso, do
  runway; os levantamentos contam como saída de caixa. Comprovativo obrigatório (`fin_file.php?payout=`,
  só admins). Os outros veem só o total reservado.
- **Também:** formulário dos custos fixos passou a modal («Novo custo fixo», tecla N); as tabelas de «A
  receber» e «A pagar» ocupam a largura toda quando não há documento aberto, com margem nas pontas.
- **Testado na bancada:** migração (conta principal, idempotente), validações, tarefa semanal (dia,
  repetição, responsável da conta, conclusão automática), alertas, reservas (%, fixo, substituição,
  acumulação, margem, total, disponível e runway), levantamentos (comprovativo, limite, «Anular»), permissões
  pelo HTTP (só ver + responsável, editar sem ser admin, admin), claro/escuro, iPad e telemóvel.

### 10.21 Relatórios — §29 (24 out 2026)

Base de dados **v33** (`inc/migrate_metrics.php`, idempotente): `stage_events` (registo, etapa, funil, de/para,
quando, quem, motivo, `live`/`rebuilt`), `deals.first_response_at / first_response_channel / first_response_by /
source / utm`, `deals.origin` passa a texto curto (canais novos), `fin_docs.acq_channel / acq_source`,
`funnel_stages.max_days`, `metric_goals`, `metrics_daily` e `metrics_weekly`. A migração reconstrói uma vez o
histórico de etapas a partir do `deal_history`, das interações e dos campos dos negócios (os importados da folha
contam no mês do trabalho) e dá o canal «Parceiro» / «Indicação de cliente» às leads com «Trazido por».
- **Uma só biblioteca (`inc/metrics.php`)** para Relatórios, Dashboard e alertas: `metrics_deals()` lê leads e
  negócios uma vez (sem quarentena nem spam) com a data de chegada a cada etapa (Lead → Abordada → X-Ray →
  Proposta → Aguardar feedback → Kickoff; etapas saltadas ficam com a data da seguinte), o fim (aberto, ganho,
  perdido) e o motivo. `deal_log` escreve cada passagem em `stage_events` (criar, converter, etapa, funil,
  perder, arquivar, reabrir); a primeira chamada, email, WhatsApp ou reunião registada é a primeira resposta
  (as notas e as reuniões internas não contam). WhatsApp passou a tipo de interação.
- **Página (`relatorios.php`, `inc/report_tabs.php`, `inc/metrics_view.php`, `assets/reports.js`):** separadores
  Atenção (abre aqui) · Metas · Funil comercial · Velocidade · Origens · Atividade · Equipa · Operacional ·
  Financeiro (só com Financeiro). Período (semana, mês, trimestre, ano, personalizado, ‹ › para recuar) com
  comparação ao anterior até ao mesmo ponto; filtros funil, origem, pessoa, serviço mantidos entre separadores.
  Cada número abre a lista dos registos numa janela (com o acesso de quem vê: os de colegas contam mas não
  aparecem), com CSV; ⓘ com a fórmula; «pouco fiável» abaixo de 10 casos; CSV de todas as tabelas do separador
  e PDF pela impressão (estilo próprio). Euros só com Financeiro; operadores filtram só por si e veem os seus
  números e a média da equipa, sem nomes.
- **Atenção:** leads sem primeira resposta fora do prazo do canal (horas úteis), parados acima do limite da
  etapa (Definições › Funis, «Parado ao fim de») ou da média histórica × 1,5, propostas em «Aguardar feedback»
  sem interação há N dias, gates e tarefas atrasadas, kickoffs sem data de entrega, reviews à espera do
  cliente, faturas em atraso e pedidas sem resposta (Financeiro), metas em risco. «Só as minhas»; o mesmo
  número no menu lateral (guardado 5 min na sessão). Mostra 40 e «Mostrar todas».
- **Metas** (só superadmin/admins gravam; registo de alterações): por mês ou trimestre, empresa, funil ou
  pessoa: leads, abordadas, pipeline, propostas, kickoffs, valor ganho, % lead → pipeline, % pipeline → kickoff.
  Cascata a partir dos kickoffs com as taxas reais dos últimos 6 meses (editável antes de gravar). Bullet com
  o ritmo esperado e a previsão a tracejado; estados Cumprida · Em linha · Em risco · Atrás; histórico.
- **Funil comercial:** por coorte (por defeito) ou por período; por etapa (entraram, passaram, perdidas,
  abertas, dias média/mediana), motivos de perda por etapa, coortes mensais em mapa de calor, funis lado a
  lado (a partir do X-Ray). Mantém «Valor ganho · por funil / origem / mês» + tabela (agora a partir do X-Ray;
  as leads sem funil numa nota fora do total; «sem perdas registadas» em vez de 100%), com barras de valor e
  gross profit sobreposto ordenadas por gross profit, o desempenho financeiro por funil (ticket médio, gross
  profit e margem, faturado e recebido, pipeline aberto e ponderado, ciclo, valor por mês de ciclo) e o
  Crosselling. Probabilidades por etapa em Definições › Pipeline › Relatórios.
- **Velocidade:** mediana, p90 e % no prazo (Definições › Leads › Prazo da primeira resposta, por canal: site e
  anúncio 1 h, redes 2 h, os outros 4 h úteis), por canal e por pessoa; velocidade × conversão com frase só
  com amostra; tendência mensal do prazo, do ciclo e do tempo por etapa.
- **Origens:** canal (formulário do site, anúncio, redes, parceiro, indicação de cliente, evento, prospeção
  ativa, manual) + fonte + campanha + quem trouxe; o site guarda os UTM da primeira visita 30 dias e um
  `utm_medium` pago faz a lead entrar como «Anúncio». Dispersão volume × % para pipeline (tamanho = valor) em
  quadrantes; qualidade (0–100); custo por lead/kickoff e retorno pelas despesas marcadas no Financeiro com
  «Custo de aquisição».
- **Atividade, Equipa, Operacional, Financeiro:** contactos por semana (barras empilhadas) e mapa dia × hora;
  ranking com mini-barras e o traço da média; em execução por etapa, entregas no prazo, kickoff → entrega,
  rondas usadas vs incluídas, aprovação à 1.ª ronda, tarefas atrasadas por etapa, carga por pessoa; margem por
  negócio, funil, serviço e cliente, custos orçados → reais, prazo de recebimento, atraso por cliente
  (antiguidade), custos por categoria vs anterior, margem orçada vs real da Orçamentação.
- **Fotografia diária e resumo semanal (cron `cc`, ou `cron_run.php metrics [semana]`):** `metrics_daily`
  (empresa e por funil: leads, abordadas, pipeline, propostas, kickoffs, valor ganho, perdidos, pipeline aberto e
  ponderado, leads abertas, prazo e mediana de resposta a 30 dias; da primeira vez, os últimos 90 dias). À segunda
  a partir das 9h: resumo da equipa (metas, melhorou/piorou, «3 coisas a fazer esta semana» pela IA a partir da
  Atenção, ou as 3 mais graves sem IA) e de cada pessoa; aparece no topo de Atenção e no sino (categoria «Resumo
  semanal» no Perfil) e vai por email aos sócios e a quem não o desligou. Não há chat de equipa (o chat é 1:1).
- **Dashboard:** linha «Comercial» lida da fotografia (pipeline ponderado com mini-linha de 30 dias, metas em
  semáforo, respostas no prazo, o que pede atenção), com ligação aos Relatórios.
- **Corrigido:** o formulário de contacto do site dava erro 500 desde 22 out (`setting()` sem o bootstrap).
- **Testado na bancada:** migração e reconstrução (sem duplicar), ganchos em uso real (lead, WhatsApp,
  conversão, etapa, perda com motivo, reabrir), horas úteis, períodos e comparação, cascata, coorte vs período,
  as 9 páginas sem avisos nem «§», CSV, lista por trás dos números (acesso), metas (admin grava, operador 403),
  Equipa sem nomes para operadores, sem euros sem Financeiro, UTM do site, fotografia e resumo (com e sem IA),
  claro/escuro, telemóvel e impressão.

### 10.22 Tarefas: a fila abre no quadro e as chamadas pedem o resultado ali mesmo (24 out 2026)

- **«Abrir fila»** (chamadas de leads, follow-ups de relação) abre o grupo no próprio quadro, na lista e no Quadro
  da equipa, com todas as tarefas do grupo; «Fechar fila» recolhe (lembra-se por grupo). Saiu «Ver uma a uma».
- **Concluir a chamada de uma lead** na aba Tarefas abre logo o «Como correu?» (não atendida, Follow-Up com nota,
  Arquivar com motivo, Converter em negócio → ficha da lead). O resultado fica no histórico da lead, como na aba
  Leads; fechar sem escolher deixa a chamada por fazer.
- **Concluir outra chamada** (negócio, contacto ou interna) abre «Chamada feita» com uma nota opcional; fica no
  histórico do negócio ou do contacto como chamada (sem nota: «Chamada feita · <título>»).

### 10.25 Tarefas: um só quadro, filtros mais simples e «Abrir na origem» em todas as fontes (29 out 2026)

- **Separador Tarefas:** uma só lista (§18.3) em vez das secções Em atraso · Hoje · Esta semana · Mais tarde · Sem
  data; as em atraso ficam no topo com uma barra vermelha. Em «Todas», as concluídas vêm no fim da mesma lista.
- **Filtros (§18.5):** «Estado» passou a um botão (Por fazer · Concluídas · Todas); «Em atraso» e «Por atribuir»
  são botões próprios, com o número; saíram «Hoje» e «Esta semana» da lista (o quadro da equipa mantém-nos).
- **«Abrir na origem»:** além de lead, negócio, contacto e Marketing, agora também as tarefas do Financeiro (saldos →
  Tesouraria, calendário fiscal → Impostos, pela tabela `fin_done`; nova origem «Financeiro» no filtro e na marca)
  e os planos tirados de um quadro do Centro Criativo (abrem o quadro). Visível também no telemóvel.
- **Quem vê o quê (§18.5):** abre em «Eu»; admins escolhem «Todos» ou cada pessoa; chefes «A minha equipa» ou cada
  membro; membros só as suas. As por atribuir passam a ser por equipa (`task_team_sql`, `task_unassigned_sql`,
  `task_can_see`), também no quadro, na agenda, na pesquisa ⌘K, na API das tarefas, no Google Calendar e no «Anular».
- Ficheiros: `calendar.php`, `tasks_api.php`, `gcal_api.php`, `ui_api.php`, `inc/tasks_view.php`, `inc/tasks.php`,
  `inc/undo.php`, `assets/app.css`.

### 10.26 Sem contas Google nos emails de trabalho — §31.5 (29 out 2026)

- **Base de dados, passo 36:** `users.google_email` e `google_linked_at` (a conta Google associada), `users.ical_hash`,
  `ical_enc` e `ical_at` (o link da agenda: sha256 para o encontrar, token cifrado com `inc/app_key.php` para o mostrar)
  e a tabela `mail_confirm` (links de confirmação dos reencaminhamentos do Hostinger).
- **Perfil › Ligações › «Conta Google»:** «Ligar conta Google» (com sessão; qualquer conta Google, ex.: Gmail pessoal;
  uma conta Google só pode estar numa pessoa) e «Ligar conta e Google Calendar» (uma só autorização para as duas
  coisas); «Desligar» no mesmo sítio. Associar e desligar ficam no registo de entradas (`login_log`: `g_link`,
  `g_unlink`). O login por email e palavra-passe e as chaves de acesso não mudam.
- **«Entrar com Google»:** primeiro a conta associada (`google_sub`), mesmo com outro email; sem associação, a regra do
  email igual verificado (e essa conta fica associada). Conta desconhecida: mensagem a explicar como associar.
- **«Ligar Google Calendar»:** sugere a conta associada (`login_hint`), por isso a Google só pede a autorização do
  calendário; quem ainda não tinha conta associada fica com a do calendário associada também.
- **«Ligar conta da topper.»** (Definições › Integrações › Google, só admins): OAuth com `geraltopperdigital@gmail.com`
  sugerida, scopes de eventos e ocupado/livre, token em `inc/meet_config.php`; «Desligar» revoga na Google. Avisos dos
  60 minutos e da conta Google dos convidados. O guia mostra o URI de redirecionamento e a origem JavaScript.
- **Começar noutro endereço** (ex.: `www.`) passa primeiro para o `app_url`, para a volta da Google encontrar a sessão.
- **«Subscrever a agenda»** (Perfil › Ligações): `admin/ical.php?t=<token>`, só leitura, sem sessão; tarefas com hora
  da pessoa (responsável ou participante) de 30 dias para trás a 180 para a frente, com o link do Meet; «Gerar outro
  link» revoga o anterior; «Revogar». Token errado ou revogado: 404.
- **Confirmação dos reencaminhamentos:** um email do Hostinger com link de confirmação que chega pela Cloudflare fica em
  `mail_confirm` (só o link, remetente e assunto; nunca o corpo) e aparece em Definições › Integrações › Email, no passo
  das caixas, com «Abrir o link de confirmação» e «Já confirmei». O link procura-se também no HTML (quoted-printable e
  base64).
- Ficheiros: `google_auth.php`, `ical.php`, `conta.php`, `definicoes.php`, `inc/google_oauth.php`, `inc/ical.php`,
  `inc/auth.php`, `inc/mail_inbound.php`, `inc/migrate_auth.php`, `inc/migrate.php`.

### 10.27 Reuniões criadas no formulário da tarefa — §31.6 (29 out 2026)

- **Base de dados, passo 37:** `tasks.invitees` (os convidados externos de «Convidar», JSON), `tasks.organizer`
  (`topper` | `me`), `contacts.lang` (`pt` | `en`, o idioma dos convites) e a tabela `task_invites` (os convites por
  email das reuniões Discord e presenciais: email, canal, idioma, sequência do .ics, assinatura do que foi enviado, o
  resumo do evento — para cancelar mesmo depois de a tarefa ser apagada —, estado, resposta e último erro).
- **Formulário (Tipo Reunião):** a plataforma (Google Meet · Discord · Presencial) muda os campos. «Convidar» com
  etiquetas de email: sugestões da ficha de contacto (pessoa e empresa), do negócio/lead (email da lead, pessoa,
  empresa, contactos do negócio) e das outras pessoas da mesma empresa, com a origem e «EN» para quem tem inglês;
  email livre validado (Enter, vírgula, colar vários; um email errado não deixa gravar). Mudar o negócio ou o contacto
  traz outras sugestões; numa reunião nova o contacto vem marcado. Os internos (responsável e participantes) vão por
  defeito com o email de trabalho. A resposta de cada convidado (Sim / Não / Talvez / Sem resposta / Não enviado)
  aparece ao lado do email.
- **Google Meet:** ao gravar (com dia e hora) o Meet é criado e os convites enviados pela Google, sem outro botão.
  Organizador por defeito: a conta da topper.; «Enviar do meu Google Calendar» só para quem o tem ligado. Depois de
  criado, o organizador fica o mesmo. Editar atualiza o evento e avisa (`sendUpdates=all`); tirar um email cancela-lhe
  o convite; apagar a tarefa (ou mudá-la para Discord/presencial, ou tirar-lhe a hora) cancela a reunião. Quem já é
  convidado não leva cópia no seu calendário. O link fica na tarefa, no histórico da tarefa e do negócio/lead e no
  chat dos participantes. Sem conta da topper.: aviso com atalho para Definições › Integrações (para os admins) e o
  campo para colar o link; quem tem o calendário ligado pode enviar do seu.
- **Discord:** dois links em Definições › Integrações › Reuniões — o convite do servidor (`discord.gg`, para os
  convidados externos entrarem no servidor) e o «Link direto do canal (para a equipa)» (`https://discord.com/channels/…`,
  abre a app no canal). A tarefa e os convites da equipa (e os pedidos de reunião da Equipa) levam o link direto; os
  externos levam o convite do servidor. Sem o link direto, vale o convite para todos. O convite diz «Se o browser pedir
  para abrir a app do Discord, carregue em Permitir.» (EN: «If your browser asks to open the Discord app, click
  Allow.»). A equipa recebe sempre o convite em português.
- **Presencial:** «Local» com o botão «Usar a morada de …» (empresa ou contacto) e convite por email.
- **Convites por email (Discord e presencial):** pelo serviço de envio (§31.1; sem ele, pelo servidor), com o evento em
  `.ics` (`METHOD:REQUEST`, organizador a caixa geral, «responder a» quem marcou). Só se reenvia quando muda a hora, o
  título, o local ou o link («Reunião atualizada», sequência +1); a quem sai vai `METHOD:CANCEL`. Recebem-no os
  externos e os internos sem o Google Calendar ligado (os outros têm o espelho). As respostas (`METHOD:REPLY`) voltam à
  caixa geral pela Cloudflare e ficam ao lado do email e no histórico da tarefa.
- **Idioma e título:** em português, ou em inglês quando o contacto da tarefa (ou a empresa) tem inglês na ficha, ou
  quando todos os convidados externos o têm (nos emails, cada convidado no seu idioma). Título com o código e o nome do
  negócio; a descrição leva o tipo, o negócio, o link e a ordem de trabalhos — sem links internos da plataforma.
- **Ficha de contacto:** campo «Idioma dos convites» (Português / English) em Editar.
- **Tarefas apagadas por outro caminho** (lead apagada, Anular «Criar tarefa»…): Anular cancela logo; o cron (`cc`)
  cancela os Meet e os convites por email que ficaram sem tarefa.
- Ficheiros: `calendar.php`, `gcal_api.php` (`a=guests`, respostas dos convites por email em `a=event`),
  `assets/gcal.js`, `assets/tasks.js`, `assets/app.css`, `inc/task_invites.php` (novo), `inc/gcal.php`,
  `inc/tasks_view.php`, `inc/mailer.php` (anexos e «responder a» em `app_mail`), `inc/mail_inbound.php`,
  `inc/contacts.php`, `inc/contact_view.php`, `ui_api.php`, `inc/undo.php`, `inc/cron_run.php`,
  `inc/migrate_auth.php`, `inc/migrate.php`.

### 10.28 «Por confirmar · emails» revisto — §28.16 (30 out 2026)

- **Porque é que a fatura da Gesvalor não foi lida** (verificado no servidor e no próprio PDF): (1) o QR fiscal desse
  PDF (FastReport) é desenhado com polígonos «m l l l h f*», não com retângulos «re», e os módulos vêm arredondados a
  1,71 e 1,78 pt — o leitor só conhecia retângulos e acumulava erro ao contar os módulos; (2) a IA não está ligada no
  servidor (não existe `inc/ai_config.php`); (3) o texto do PDF está em glifos hexadecimais, ilegível sem o mapa
  ToUnicode da fonte. Corrigido: o leitor vetorial aceita polígonos e lê cada módulo pelo centro com o passo real
  (tamanho do QR pelos padrões dos cantos) — a fatura passa a ler-se em 0,01 s (emitente 513939610 → adquirente
  519503147, FT 1.1/16488, 196,80 €, ATCUD); todos os PDFs de teste anteriores continuam a ler.
- **Ordem de leitura:** QR / XML → IA (o PDF vai inteiro: texto e, se for digitalizado, a imagem) → texto do próprio
  PDF (`fin_pdf_text`: Tj/TJ com os mapas ToUnicode; `fin_text_invoice`: NIFs válidos sem telefones, quem emite pelo
  contexto de cada NIF — capital social/IBAN/sede vs. Exmo./cliente/contribuinte —, nome da outra entidade, nº,
  ATCUD, base e IVA pelo par que soma o total a uma taxa legal, data de emissão e vencimento). Com QR, os nomes e o
  vencimento vêm do texto. Sem IA, o motivo exato aparece no cartão («IA indisponível: falta a chave da Anthropic no
  servidor…», «…a chave guardada não é válida», ou o erro da leitura).
- **Identidade:** Definições › Financeiro › Empresa — «Nome legal da empresa» (Feitiço Constante, Lda.) e «NIF da
  empresa» (passou de Geral para aqui). Lado do documento: NIF da empresa como emitente → receita (A receber); como
  adquirente → despesa (A pagar); senão o nome legal («Topper Digital»; «topper» sozinho não conta no texto); senão a
  regra escolhida antes para o emitente/remetente; senão o contabilista sem código de «Pedir fatura» → despesa (com
  o código FAT-… é a resposta a um pedido: fatura emitida). Documento entre terceiros (QR/XML com os dois NIFs, sem o
  nosso): ignorado.
- **Base de dados, passo 38:** `fin_mail.body_text` (o texto do email até 1 000 caracteres, só nos emails guardados
  como documento financeiro), `fin_mail.direction` (sugestão: `out` / `in` / '' indeciso) e `fin_class_rules`
  (emitente por NIF, ou remetente sem NIF → direção).
- **Painel «Por confirmar · emails»** em A pagar (despesas e indecisos), A receber (faturas emitidas e indecisos) e na
  Visão geral (todos); contador nos separadores. Cada cartão: miniatura do anexo e «Abrir PDF» (painel lateral, com
  «Abrir noutro separador»), assunto, remetente, caixa, data, a sugestão com o ícone discreto ⇄ (A pagar ⇄ A receber,
  com «Anular»; a escolha vira regra para o mesmo emitente/remetente), o motivo, os valores lidos (emitente,
  adquirente, NIF, nº, data, total, IVA, de onde foram lidos) e o texto do email. Ações: «Ligar a este pedido»,
  «Confirmar como despesa» / «Confirmar como fatura emitida» (abre o formulário preenchido, com a contraparte certa;
  a escolha também vira regra), «Ler outra vez» e «Pôr de parte» (com motivo; «Anular» repõe).
- **Lançamentos:** o mesmo ícone ⇄ em cada linha de A pagar e A receber passa o lançamento para o outro lado (contraparte
  do documento lido, estado «Por confirmar»/«Emitida»), com «Anular» (repõe também a regra). Não aparece em
  lançamentos com pagamentos, de salários, custos fixos ou faturação de negócios.
- **Releitura:** uma vez depois da atualização, o cron relê os emails por confirmar (a Gesvalor passa a despesa em A
  pagar, por confirmar). Ligar a IA em Definições › Integrações › IA (colar a chave da Anthropic: confirmada na API sem
  gastar e guardada só no servidor, com o limite mensal) e depois «Ler outra vez».
- **Número do menu Financeiro e «Por tratar»:** o número contava avisos (ex.: «3 documentos por confirmar» valia 1) e
  a dica dizia sempre «por receber em atraso». Agora é a soma real do que está por tratar — documentos por email,
  despesas por confirmar, faturas prontas a enviar, por receber em atraso, a pagar vencidas, obrigações fiscais nos
  próximos 7 dias, saldos desatualizados, runway (admins) — e a Visão geral abre com a lista «Por tratar» com as
  mesmas linhas (cada uma leva ao sítio onde se trata). A dica do menu e «O teu dia» dizem «Financeiro · por tratar».
- Ficheiros: `financeiro.php`, `definicoes.php`, `assets/fin.js`, `assets/app.css`, `inc/fin_qr.php`, `inc/fin_mail.php`,
  `inc/fin_ai.php`, `inc/ai.php`, `inc/fin_view.php`, `inc/fin_tabs.php`, `inc/fin.php`, `inc/alerts.php`,
  `inc/settings_def.php`, `inc/cron_run.php`, `inc/migrate_finance.php`, `inc/migrate.php`.

### 10.29 Comprovativos de pagamento e recibos por email — §28.17 (30 out 2026)

- **PDFs cifrados:** os comprovativos do Millennium vêm cifrados (AES-128, sem palavra-passe de abertura). `fin_pdf_decrypt`
  decifra RC4/AES-128 (R2–R4) e AES-256 (R5/R6) em PHP puro (OpenSSL) e confirma que abre sem palavra-passe; o texto
  e o QR passam a ler-se. O texto passou também a respeitar «'», «T*» e Td horizontais e a ignorar imagens embutidas.
- **Leitura** (`inc/fin_proof.php`, `fin_proof_read`): valor, data do movimento, beneficiário e IBAN (validado mod 97),
  ordenante, descritivo e descritivo do extrato, referência do banco, entidade + referência MB (ou referência de
  pagamento ao Estado), comissões (com imposto do selo), total movimentado, banco, sentido (conta da empresa, DB/CR,
  «a débito/a crédito») e a fatura-recibo do banco pelas comissões. Exige pelo menos dois sinais de comprovativo (uma
  fatura que diga «envie o comprovativo» não chega). Um comprovativo lê-se do texto antes de chamar a IA (não a gasta);
  a IA tem o tipo `payment_proof` para os digitalizados. Caso real: transferência de 615,00 € a um freelancer (IBAN de outro
  banco), 29/10, comissão 1,25 € (fatura-recibo do BCP).
- **Faturas:** ao chegarem, guardam o IBAN para pagar e a referência MB (`fin_docs.pay_iban`, `mb_entity`, `mb_ref`;
  na ficha: «Pagar por»). A Gesvalor: Ent. 11683 · Ref. 835 691 114 · IBAN PT50 0018 …. O cron preenche uma vez as
  faturas a pagar que já tinham PDF.
- **Base de dados, passo 39:** essas três colunas, `fin_mail.kind` ('proof', 'receipt'), `fin_mail.proof` (o lido),
  `fin_payments.mail_id` e `fin_ibans` (IBAN → NIF, nome, contacto, aprendido ao validar). Categoria nova «Comissões
  bancárias».
- **Cartão por validar** (A pagar ou A receber, e Visão geral): valor, data, para/de com IBAN, descritivo, referência
  MB, banco e comissão; as sugestões de agora (referência MB, IBAN, valor, nome, nº, NIF, data; e os pagamentos já
  registados pelo extrato sem comprovativo), «Outro lançamento» e, num pagamento feito, «criar a despesa já paga». Só
  uma sugestão forte (≥ 60%) vem escolhida. «Validar pagamento»: confirma o lançamento se estava por confirmar, regista
  o pagamento com o PDF como comprovativo, aprende o IBAN e lança a comissão (despesa «Comissões bancárias», fatura-
  -recibo do banco, paga e arquivada; não repete se já estiver lançada). «Anular» desfaz tudo.
- **Recibos sem pedido** ficam por validar (antes juntavam-se sozinhos por NIF + total); sugestão por NIF, nº da fatura
  no texto do recibo e valor. «Validar e juntar o recibo» junta-o e, com a opção (marcada), regista o pagamento que
  falta, com o recibo como prova. Os de «Pedir recibo» (REC-…) continuam automáticos.
- **Extrato (Tesouraria › Conciliação):** um movimento igual a um pagamento já registado (±7 dias), ou ao pagamento +
  comissão do mesmo comprovativo (ex.: −616,25 €), mostra «Já pago: …» e liga-se sem criar outro pagamento («Anular»
  desliga). A referência MB de uma fatura no descritivo do movimento conta na sugestão.
- Releitura única dos emails por confirmar depois da atualização. Os comprovativos que chegaram antes não ficaram no
  Financeiro (não eram reconhecidos): reencaminhá-los para info@ se for preciso.
- Ficheiros: `inc/fin_proof.php` (novo), `inc/fin_qr.php`, `inc/fin_mail.php`, `inc/fin_ai.php`, `inc/fin_view.php`,
  `inc/fin_tabs.php`, `inc/fin_cash.php`, `inc/fin.php`, `inc/alerts.php`, `inc/cron_run.php`, `inc/migrate_finance.php`,
  `inc/migrate.php`, `financeiro.php`, `assets/fin.js`, `assets/app.css`.

### 10.23 Dashboard Executivo — §30 (24 out 2026)

**Base de dados — passo 34** (`inc/migrate_exec.php`): `fin_assets` (ativos: valor, data, taxa, motorização das
viaturas, abate), `fin_loans` (empréstimos e leasings: capital, TAN, prazo, início, dia, residual) e
`fin_docs.loan_id`; `exec_months` (a fotografia mensal do resultado), `exec_reading` (a leitura do mês, com e sem
euros, e os números em que se baseou), `exec_hof` (pódio por mês, entradas, recordes, negócio do mês, comercial do
mês, destaque de produção, fecho do mês) e `team_posts` (avisos da equipa no chat). Parâmetro novo «Horas produtivas
por pessoa e por ano» (1 600 h, só admins). O custo/hora interno de quem tem ficha de salário passa a vir dela.

**O resultado (P&L), `inc/exec.php`** — sem contas próprias: lê o Financeiro e a camada de métricas.
- Revenue: faturado s/ IVA pela data da fatura (notas de crédito abatem); antes das faturas, o negócio no mês do
  trabalho. Recebido em nota (pelos pagamentos).
- Custos diretos: despesas com âmbito «Negócio» e das categorias do tipo «Custo direto» (pela data do documento),
  custos da equipa da altura da folha (mês do custo/negócio), gastos escritos à mão, reservas dos sócios (na proporção
  do faturado) e o tempo das pessoas declaradas na Orçamentação.
- Salários: o custo total do processamento do mês; sem processamento, a estimativa pela ficha (custo anual ÷ 12) a
  partir da data de entrada; com lançamentos «Salários» feitos à mão nesse mês, contam esses (sem estimativa).
- **Equipa nos negócios (sem dupla contagem):** horas da Orçamentação (versão e opção que contam; dias × horas do
  dia) × custo/hora interno (custo anual ÷ horas produtivas), repartidas pelos dias de execução (Kickoff → entrega,
  prevista ou feita); cada mês, o imputado sai dos salários operacionais e entra nos diretos, nunca mais do que o
  custo real de cada pessoa nesse mês (aviso quando limita). Quem não tem ficha de salário conta horas, não custo.
- Operacionais: categorias do tipo «Operacional» (âmbito Empresa) + salários − imputado. Amortizações: ativos
  (valor × taxa ÷ 12 desde o mês de aquisição, até ao valor ou ao abate) + tipo «Amortização». Juros: plano de cada
  empréstimo (prestação constante, residual no fim) + tipo «Financeiro». As prestações nascem «Por pagar» (categoria
  «Empréstimos e leasings», como os custos fixos) e não contam no resultado (só o juro). Tipo «Imposto»: não é custo.
- IRC estimado sobre o resultado do ano até ao mês (taxa PME até ao limite, taxa geral, derrama — parâmetros),
  repartido pelos meses; tributação autónoma das viaturas registadas (depreciação × taxa pela motorização e preço) e
  das despesas «Viaturas» (taxa da viatura principal ou do 1.º escalão). Net Profit marcado «estimativa».
- A fotografia (`exec_months`, 24 meses + o ano anterior inteiro) refaz-se de hora a hora no cron, ao mudar um tipo
  de categoria, um ativo ou um empréstimo, e com «atualizar agora»; o Dashboard lê dela.

**Definições:** Financeiro › Categorias › «Tipo contabilístico de cada categoria» (Custo direto · Operacional ·
Amortização · Financeiro · Imposto; grava ao mudar, com «Anular»; Salários e Empréstimos são automáticos). Geral ›
Dashboard executivo: meta anual de faturação (a mesma da Visão geral do Financeiro), limites dos semáforos,
concentração, mínimo do comercial do mês e hora da leitura. Financeiro: separador novo **Ativos e empréstimos**
(registo, valor líquido, juros do mês, dívida; «Anular» em tudo). Perfil: «O teu dia» compacto ou aberto.

**O Dashboard, de cima para baixo** (`dashboard.php`, `inc/exec_view.php`, `assets/dash.js`):
1. Saudação e **«O teu dia» numa linha de contadores** (Tarefas a vencer · Leads em atraso · Negócios parados · Por
   receber em atraso) que abrem o detalhe no painel lateral (`ui_api.php?a=panel&type=today`); Ctrl-clique abre a
   página. «Abrir expandido» (preferência da pessoa, desligada por defeito) mostra o bloco antigo.
2. **Leitura do mês:** de manhã (a partir da hora das Definições) o cron escreve 3–4 frases pela IA, só com os números
   da fotografia (escritos já formatados); se aparecer um número que não está nos dados, o texto da IA cai e fica um
   texto fixo com os mesmos números. Duas versões: com euros (Financeiro) e sem euros nem margens (os outros).
   Admins podem «Escrever agora».
3. **Saúde da empresa:** Finanças (últimos 3 meses: gross margin vs meta e net profit > 0) · Comercial (metas do
   período e pipeline ponderado vs meta de valor do mês seguinte × 1,5) · Operação (90 dias: entregas no prazo e
   rondas a mais) · Tesouraria (runway útil e % do por receber em atraso). Cada um diz a razão e abre o relatório.
4. **Resultados (só Financeiro):** Mês · Trimestre · Ano até hoje · Últimos 12 meses, contra o período anterior e o
   do ano anterior (▲▼). Cinco cartões (Revenue, Gross Profit, EBITDA, EBIT, Net Profit) com margem, ⓘ com a fórmula
   e mini-linha de 12 meses; o número abre os documentos/negócios por trás. Cascata do período e barras de Revenue
   com a linha da gross margin (escala própria, um só eixo). Indicadores: runway útil, saldo disponível, por receber
   em atraso, receita por pessoa, concentração dos 3 maiores clientes (alerta acima do limite), clientes recorrentes.
5. **Crescimento e previsão:** Revenue e Gross Profit de 12 meses com a média de 3; previsão do ano = faturado +
   ganho por faturar + pipeline ponderado + tendência (o que a tendência espera para lá do que já se conhece), banda
   pessimista/otimista e distância à meta; mix do gross profit por funil e por serviço (barra a 100%). Sem
   Financeiro: ganhos no ano, kickoffs do mês, negócios em pipeline e o mix em número de negócios.
6. **Hall of fame:** pódio (1.º amarelo, 2.º azul-céu, 3.º coral) com código, negócio, cliente, funil, mês e equipa;
   gross profit = valor − custos − tempo da equipa, «provisório» até à entrega; euros só com Financeiro. Negócio do
   mês com a % do 1.º do mês anterior e do recorde. Quando um negócio entra no pódio pela primeira vez ou passa a 1.º
   acima do recorde: registo, aviso no chat («Equipa · avisos») e o pódio anima uma vez. No dia 1 (a partir das 9h):
   pódio do mês, negócio do mês, **comercial do mês** (melhor conversão pipeline → kickoff com pelo menos N negócios
   em pipeline; desempate pelo gross profit e pela primeira resposta) e **destaque de produção** (entregas no prazo e
   aprovação à 1.ª ronda), com aviso no chat; histórico de 12 meses.
7. **Comercial** (o bloco de antes) e **Pedem atenção agora** (com ligação ao separador Atenção).

**Visibilidade:** sem Financeiro, o servidor não envia euros (P&L, previsão, valores do pódio e do «Pedem atenção»
ficam de fora; verificado: nenhum «€» no HTML). **Chat:** a conversa «Equipa · avisos» (só leitura) aparece no topo
da lista com as não lidas; o aviso novo chega como notificação com «Ver». **Cron:** `exec_tick` corre na tarefa «cc»
(prestações, fotografia, hall of fame, leitura); `php admin/inc/cron_run.php exec [leitura | fecho AAAA-MM]` à mão.

**Também:** a lista de documentos por trás de um número (Relatórios) mostra o estado inteiro («Emitida», não «E»).

**Ajustes (24 out 2026):** trocar o período dos Resultados (Mês, Trimestre, Ano até hoje, 12 meses) já não recarrega
a página: o Dashboard pede só as secções Resultados e Crescimento (`?frag=per`, com as mesmas permissões e sem euros
para quem não tem Financeiro), troca-as no sítio e atualiza o endereço; sem JavaScript continua a ser um link. Em
Relatórios › Metas, o formulário «Definir metas» fica atrás de um botão (abre sozinho quando se está a escolher
âmbito, período ou cascata). No Financeiro, os cartões Saldo disponível, Por receber e A pagar mostram os cêntimos, e
o pedido de fatura ao contabilista mostra o destinatário (o email do contabilista de Definições › Financeiro) à
parte do email do cliente, que vai na fatura.

### 10.24 Email sem palavras-passe, login rápido e calendário — §31 (24 out 2026)

Passo 35 da base de dados (`inc/migrate_auth.php`): `mail_inbound` (só remetente, assunto, caixa e resultado — nunca o
corpo; serve também contra repetições), `user_passkeys`, `login_log`, `user_google` (token cifrado, cache do
ocupado/livre, syncToken e canal push), `calendar_links`, `users.google_sub` e `tasks.call_link`. Quem já tinha o
leitor IMAP a funcionar fica no «Modo IMAP» e no envio por SMTP até mudar em Definições (para não perder emails
pelo caminho); instalações novas começam pela Cloudflare e pelo serviço de envio.

**31.1 Email.** Worker próprio em `mail/` (`topper-mail`, mesma conta do `rt/`): `email()` recusa > 25 MB e faz POST
do email em bruto para `admin/fin_inbound.php` com `X-Topper-Ts`, `X-Topper-Id` (Message-ID), `X-Topper-From`
(envelope) e `X-Topper-Sig` = HMAC-SHA256(segredo, «ts.id.sha256(corpo)»); 5xx → erro temporário (o servidor que
reencaminhou volta a tentar), 4xx → não repete. `inc/mail_inbound.php`: assinatura, 5 minutos, repetições pelo
Message-ID, caixa de origem pelos cabeçalhos do reencaminhamento (Delivered-To, X-Original-To, X-Forwarded-*, To/Cc,
SRS do envelope); caixas fora de «Caixas ligadas» são ignoradas sem guardar nada; depois `fin_mail_handle` (as
regras de sempre: só anexos, QR/ATCUD, IA só sem QR, NIF, código no assunto). Envio: `inc/mailer.php` — Resend ou
Brevo (chave confirmada no serviço e gravada só em `inc/fin_mail_config.php`), remetente = a pessoa, «Responder para»
= o email geral; os emails automáticos (recuperar palavra-passe, convites, avisos) também saem por aqui quando está
pronto. Endereço de entrada: `entrada@in.topperhub.xyz` (Email Routing só no subdomínio `in.`, para os MX de
topperhub.xyz continuarem no Hostinger). Definições › Integrações › Email: guia em 5 passos (MX do subdomínio, regra, `wrangler deploy` +
«Gerar o segredo», reencaminhamento em cada caixa, «Testar»), «Como chegam as faturas» (Cloudflare | Modo IMAP),
«Como saem os emails» (serviço | SMTP), estado por caixa com a data do último email e «Testar» (manda um email com
um código e mostra-o a chegar), e «Enviar um email de teste para mim». O Perfil › Email de envio deixa de pedir
palavra-passe no modo do serviço. Testado na bancada com o próprio código do Worker (Node): teste, caixa não ligada,
sem anexos, fatura (por confirmar), repetição, assinatura errada (401), carimbo antigo (409).

**31.2 Login rápido.** Chaves de acesso com verificação própria (`inc/webauthn.php`: CBOR, ES256/RS256/Ed25519,
desafio na sessão, origem, rpId, presença e verificação do utilizador, contador) — a biblioteca lbuchs/WebAuthn não
se pôde descarregar neste ambiente. `admin/passkey.php` + `assets/passkey.js`: criar em Perfil › Segurança (várias,
com nome, último uso, Remover), entrar pelo botão ou pela proposta automática do browser no campo do email.
«Entrar com Google» (`inc/google_oauth.php`, `admin/google_auth.php`): OpenID Connect com state, PKCE e nonce; só
entra quem já tem utilizador com o mesmo email verificado; nunca cria contas; fica ligada a conta Google da primeira
vez (`google_sub`). Definições › Segurança › «Chave de acesso obrigatória» (ninguém | admins | admins e chefes |
todos): quem já tem chave deixa de entrar com palavra-passe ou Google; quem não tem é levado ao Perfil para a criar.
Registo de entradas (método, aparelho, rede parcial, data) em Perfil › Segurança. Testado com um autenticador
virtual do Chromium (criar, entrar pela proposta automática e pelo botão, contador) e com uma Google simulada
(entrar, email sem conta recusado).

**31.3 / 31.4 Calendário.** Camada única `inc/gcal.php` (nada mais fala com a API do Google Calendar): contas
«topper» (`inc/meet_config.php`) e «user:<id>» (Perfil › Ligações › «Ligar Google Calendar», scopes
`calendar.events` + `calendar.freebusy`, token cifrado com a chave do servidor `inc/app_key.php`, revogável).
«Criar Google Meet» no formulário de qualquer tarefa com hora (que é como leads, negócios, kickoff, review, Agenda e
Equipa abrem os eventos), com convidados externos; organizador = quem marca se tiver o calendário ligado, senão a
conta da topper.; o link fica na tarefa, no histórico, no negócio e no chat. `calendar_links` liga evento ↔ registo:
mudar a hora ou apagar na plataforma atualiza ou cancela no Google, sem duplicados. Espelho no calendário de cada
pessoa ligada (reuniões, gravações, visitas e prazos com hora), sem convites, e sem cópia para quem já é convidado do
Meet. Os pedidos de reunião da Equipa criam o Meet pela mesma camada e partilham o evento com as tarefas dos dois.
Ao marcar: quem está ocupado nessa hora (plataforma + Google) e os 3 próximos horários livres em comum
(`gcal_api.php?a=slots`). Opção por pessoa de mostrar o «ocupado» do Google na Agenda e na disponibilidade da equipa.
Respostas dos convidados lidas ao abrir. Fase 2 preparada: `gcal_push.php`, canais renovados pelo cron (Definições ›
Integrações › Google › «Mudanças do Google para a plataforma»), syncToken e `gcal_pull` (por agora traz as respostas;
as mudanças de hora feitas no Google ainda não voltam). Cron «cc»: `gcal_tick` (tarefas mudadas, apagadas, primeira
sincronização, ocupado/livre, canais). Definições › Integrações › Google: guia, URI de redirecionamento e o cliente
OAuth (gravado em `inc/auth_config.php`, fora do Git). Sem credenciais, tudo funciona como antes (link colado à mão).
Testado com uma Google simulada só na bancada (variável `TOPPER_API_MOCK`, ignorada fora de localhost): criar Meet
com convidado externo, mudar a hora, espelho, apagar, sugestões com o ocupado do Google, reunião da Equipa, cron,
aviso push e desligar (revoga e retira as cópias). Mudanças feitas noutros sítios (arrastar no quadro, concluir)
chegam ao Google pelo cron em até 5 minutos.

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
  - esquerda: dados da lead e do contacto, botões **Converter em negócio** e **Arquivar**
    fixos no fundo. **Sem lápis campo a campo:** um botão **«Editar»** abre todos os campos
    num formulário e **«Gravar»** grava-os de uma vez, com «Anular» (decidido 21 out 2026;
    substitui a §11.8 na lead);
  - direita: **Nova ação** · **Foco** · **Notas & Histórico** (12.7).
  - ecrãs estreitos: colunas empilhadas.
- Setas `↑ ↓` pequenas **fora do painel, no canto superior esquerdo**, para passar à lead
  de cima/baixo **na ordem e filtros atuais** da lista. Teclas `↑/↓` (ou `J/K`); `Esc` fecha.

### 12.6 Ficha completa
«Abrir a ficha completa» abre uma **página própria da lead** (ex.: `admin/lead.php?id=…`)
com o menu lateral e o caminho Comercial / Leads / <nome>. «Voltar» regressa à lista com
os mesmos filtros e posição. Acaba a ficha no fundo da página da lista.

### 12.7 Tarefas, Foco e Histórico
- **Nova ação:** tipo por ícones (Chamada, Reunião, Tarefa, Documento, Email, Almoço,
  Jantar, Evento — «Visita» saiu, decidido 21 out 2026) · notas · atalhos Em 1h / Em 3h / Amanhã / Próxima semana · data, hora, duração ·
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

---

## 14. Aba Orçamentação (decidido 21–22 out 2026)

Aplica a §11 (design, degradês, animação de formulários §13.8, «Anular», dicas) e o
modelo de contactos/serviços da §13.

### 14.1 Folha de orçamento e ciclo de vida
- Cada negócio tem **uma folha de orçamento** (mesmo ID), criada **automaticamente na
  conversão** da lead (X-Ray). Nunca se apaga; acompanha o estado do negócio.
- Separadores:
  | Separador | Conteúdo |
  |---|---|
  | **Em orçamentação** (por defeito) | negócios na fase comercial (X-Ray → Kickoff) |
  | **Em produção** | aprovados (desde Kickoff); Resultado a acompanhar custos reais |
  | **Ganhos** | negócios concluídos; consulta fácil |
  | **Arquivo** | negócios perdidos, agrupados por ano (fechados por defeito), pesquisa própria |
- Negócio reaberto → folha volta a «Em orçamentação». Pesquisa ⌘K encontra qualquer folha.
- Ficha do negócio tem botão **«Orçamento»** que abre a folha.

### 14.2 Linhas de custo
- **Fornecedor/freelancer:** escolher serviço → lista só fornecedores **ativos** que o
  prestam (preço, unidade, avaliação) → quantidade → custo. O preço é **copiado para a
  folha** no momento (alterações futuras do preçário não mudam orçamentos feitos).
- **Interno:** linhas manuais (descrição, quantidade, unidade, custo): equipa interna,
  licenças, render farm, deslocações, aluguer…
- **Custo/hora interno** de cada membro da Equipa, introduzido manualmente na aba Equipa;
  usado nas linhas de equipa interna.
- Quantidade em **horas ou dias**; **1 dia = 8 horas úteis** (editável em Definições);
  conversão automática entre a unidade do fornecedor e a introduzida. «Projeto»/«peça» = fixo.
- «+ Adicionar linha» com a animação da §13.8.

### 14.3 Cálculo
1. Custos diretos = Σ linhas.
2. **MSO** = custos diretos × **5%** (Definições, superadmin/admins).
3. **Duração** em dias úteis **segunda a sexta** (data de início e entrega, ou semanas;
   fases opcionais: pré-produção, produção, pós-produção, entregas). 1 mês = 21 dias úteis.
4. **Custos fixos mensais**: média dos últimos 3 meses dos custos da empresa no
   Financeiro (corrigível à mão por quem tem permissão).
5. **% de ocupação** — sugerida automaticamente:
   ocupação = horas internas da folha ÷ (horas disponíveis da equipa por mês × meses);
   requer horas disponíveis por mês de cada membro na aba Equipa. **Pode sempre ser
   escrita à mão** (substitui a sugestão; botão para voltar à sugestão).
6. **Custo de estrutura** = custos fixos mensais × meses × % ocupação.
7. **Base** = custos diretos + MSO + custo de estrutura.
8. **Preço s/ IVA = Base ÷ (1 − margem)**; margem por defeito **65%** (Definições;
   ajustável por folha, com histórico). Mostrar também c/ IVA e o markup equivalente.
9. **Gross profit** = preço − base · **Gross margin** = gross profit ÷ preço.
10. **Lucro por mês** = gross profit ÷ meses. **Meta mensal** em Definições (provisório
    **8 000 €/mês**): 🟢 ≥ meta · 🟠 abaixo · 🔴 muito abaixo (< 75% da meta), com
    «Para cumprir a meta: preço mínimo X € ou entrega em ≤ Y meses»
    (preço mínimo = base + meta × meses).

### 14.4 Ícones de informação na calculadora
Cada célula/campo de cálculo tem um ícone ⓘ discreto; ao passar o cursor (ou toque longo)
mostra o significado. Textos:
- **Custos diretos:** «O que pagamos a fornecedores, freelancers e custos internos deste projeto.»
- **MSO:** «Margem de segurança operacional: almofada para imprevistos, em % dos custos diretos.»
- **Duração:** «A duração diz durante quanto tempo o projeto usa a empresa.»
- **% de ocupação:** «A % de ocupação diz quanto da capacidade da empresa é usada nesse tempo.»
- **Custos fixos mensais:** «Renda, software, seguros e outros custos que a empresa paga todos os meses.»
- **Custo de estrutura:** «A parte dos custos fixos que este projeto deve pagar: custos fixos × meses × ocupação.»
- **Base:** «Tudo o que o projeto custa à empresa antes do lucro.»
- **Margem:** «Percentagem do preço final que é lucro.»
- **Preço:** «Valor a propor ao cliente: base ÷ (1 − margem).»
- **Gross profit / Gross margin:** «Lucro esperado em € e em % do preço.»
- **Lucro por mês:** «Lucro dividido pelos meses de duração; compara projetos de durações diferentes.»
- **Meta mensal:** «Lucro por mês mínimo que a empresa quer em cada projeto.»

### 14.5 Separador Resultado
Orçado vs real linha a linha (real = custos do negócio no Financeiro, pelo ID), diferença
em € e %, linhas que derraparam em destaque. Totais: custos, MSO usada, gross profit, gross
margin, **prazo real vs orçado**, **lucro por mês real vs orçado**. Fecha ao concluir o
negócio. Relatórios: margem média real por funil e por serviço; serviços que mais derrapam.

### 14.6 Versões, modelos, PDF e permissões
- **Versões** v1, v2… com data, autor e comparação lado a lado; a **aceite** é a que conta
  para o Resultado e para o valor do negócio no Pipeline.
- **Modelos** de orçamento (ex.: «Spot 3D 30s») com linhas típicas; criar folha a partir de modelo.
- **Proposta em PDF** («Gerar proposta») com a imagem topper.: descrição, fases e prazos,
  preço s/ e c/ IVA, condições — nunca custos nem margens. Guardada na pasta do negócio no Drive.
- **Permissões:** quem não tem acesso ao Financeiro preenche linhas mas não vê preços de
  fornecedores, margens nem lucro; admins veem tudo; configurável na grelha da aba Equipa.

---

## 15. Aba Negócios — ficha central (decidido 22 out 2026)

Substitui o que contradiga a §3.3 e a §9.5. Aplica a §11 (design, degradês, «Anular»,
dicas, histórico com ícones/cores).

### 15.1 Quando aparece e onde vivem os concluídos
- O negócio aparece na aba Negócios quando chega a **Kickoff** em qualquer funil.
- **Os concluídos passam a viver na aba Negócios** (separador Concluídos). O Pipeline
  fica só com a fase comercial e com os Perdidos (o separador «Concluídos» do Pipeline sai).
- Lista: separadores **Em produção** (por defeito) · **Concluídos** · **Todos**; vistas
  Os meus · Entrega esta semana · Em risco · Por receber; colunas ID · negócio (cliente em
  pequeno) · etapa · valor · margem real · % recebido · entrega.

### 15.2 Regra de ouro
A ficha **não cria dados**: cada cartão lê de outra aba pelo ID. «Ver detalhe» abre o
**painel lateral dessa aba já filtrado para este negócio**, sem sair da ficha; edita-se
sempre no sítio de origem.

### 15.3 Topo da ficha
- ID, nome, tipo, estado; cliente · trazido por · responsável · funil · IVA.
- **Barra de etapas de produção**: por agora Pré-produção · Produção · Pós-produção ·
  Entrega · Concluído; **no futuro usa as etapas de produção do funil** (quando definidas).
  Clicar avança a etapa, com «Anular».
- Ações: pasta no Drive · abrir orçamento · nova tarefa · adicionar documento · mais.
- **Pulso** (5 indicadores): valor s/ IVA · custos reais · margem real vs meta · % recebido ·
  lucro (e lucro/mês quando há prazo).
- **«Precisa de atenção»** (chips automáticos): margem abaixo da meta, entrega próxima,
  fatura em atraso, custos por pagar, fornecedores por avaliar, sem orçamento.

### 15.4 Cartões
| Cartão | Origem / conteúdo |
|---|---|
| Cliente e contactos | Contactos: cliente, pessoas, trazido por, outros negócios do cliente |
| Orçamento | Orçamentação: versão aceite, preço e margem orçados, abrir folha |
| Resultado | Orçamentação + Financeiro: preço, custos, gross profit, gross margin, orçado vs real |
| Equipa e fornecedores | Custos por pessoa/fornecedor, valor, IVA, pago/fechado |
| Produção e tarefas | Tarefas: etapa, tarefas abertas/concluídas, entregas, prazo real vs orçado |
| Ficheiros | Drive: pasta `<ID>_<NOME>`, contagens por tipo, último ficheiro |
| Documentos | Contratuais (proposta, contrato) · fiscais (faturas emitidas) · faturas de fornecedores |
| Criativos | Centro Criativo (quando desenhado): versões, aprovações do cliente |
| Histórico | linha do tempo de **todas as fontes** com ícones/cores da §12.7 |
| Fecho do negócio | checklist (15.5) |

### 15.5 Fecho do negócio
Ao passar a Concluído aparece a checklist: custos todos pagos · cliente pagou · entregas
feitas · fornecedores avaliados (§13.3) · resultado fechado (§14.5). Ações opcionais:
**«Enviar para o portfólio»** (Marketing) e **«Pedir testemunho»** ao cliente. Pode concluir
com itens em falta, mas o negócio fica assinalado e conta como «Em risco» até fechar.

### 15.6 Negócios anteriores à plataforma
Negócios migrados sem orçamento mostram «Sem orçamento na plataforma» e usam o valor e
os custos existentes para o Resultado.

---

## 16. Aba Marketing — conteúdo para redes sociais (decidido 22 out 2026)

Motor de criação e gestão de conteúdo da topper. para **Instagram, LinkedIn e X**. Não
confundir com o Centro Criativo (criatividade dos serviços a clientes). Aplica a §11.

### 16.1 Separadores
**Ideias · Estúdio · Produção · Calendário · Desempenho** — ciclo: ideias → criar → produzir
→ agendar → medir → novas ideias.

### 16.2 Pilares de conteúdo (editáveis em Definições)
1. Portfólio e breakdowns · 2. Bastidores · 3. Educação (dicas VFX/3D/pós) · 4. Casos de
cliente · 5. Serviços (Marketing Buddy, VFX, CGI) · 6. **Employer branding** — mostrar que é
excelente e exclusivo trabalhar na topper.: pessoas da equipa, projetos de topo em que
trabalham, ferramentas e condições, crescimento, cultura, «um dia na topper.».
Objetivo deste pilar: quem vê deve querer trabalhar aqui. Métrica própria: candidaturas
espontâneas e contactos de talento gerados (via link com rastreio para uma página/
formulário de carreiras — a criar no site), seguidores e interação por publicação.

### 16.3 Ideias
- Captura rápida «+ Ideia» e atalho `I` em qualquer ecrã (só o texto é obrigatório).
- Campos: pilar · formato (reel, carrossel, post, thread, vídeo longo) · plataformas · autor
  · votos da equipa.
- Triagem: fila de ideias novas → Avançar (vai para Produção) · Guardar · Descartar.
- **Automáticas:** «Enviar para o portfólio» na ficha do negócio (§15.5) cria «Breakdown:
  <negócio>» ligada ao negócio e aos ficheiros; conteúdos com bom desempenho sugerem
  repetição/parte 2.
- **Ligação ao Centro Criativo:** quando existir, a ideia de breakdown dá acesso também às
  versões e referências do projeto no Centro Criativo (ex.: mostrar como foi feito o
  processo criativo de um serviço). Até lá, liga só ao negócio e aos ficheiros do Drive.
- **Carreiras:** a métrica de talento do pilar Employer branding precisa de uma página ou
  formulário de carreiras no site — **PENDENTE**, a decidir com a aba Equipa/site; deixar a
  origem «talento» preparada.
- **Autorização de divulgação** na ficha do negócio: sim · não · a partir de <data>. Sem
  autorização, não pode ser publicado (bloqueio no servidor).

### 16.4 Estúdio (IA)
- Editor: escreve-se a ideia base uma vez; a IA gera versões por rede (IG: gancho, legenda
  curta, hashtags, roteiro do reel · LinkedIn: tom profissional, história e aprendizagem ·
  X: post curto ou thread, dentro do limite de caracteres).
- Ações em botões: Gerar 5 ganchos · Escrever guião (cenas e duração) · Encurtar/alongar ·
  Mudar o tom · Sugerir CTA · Criar thread · Hashtags.
- **Voz da topper.** guardada no Centro de Conhecimento e usada sempre pela IA.
- Modelos de formato: Breakdown VFX (antes/depois) · Bastidores · Dica técnica · Caso de
  cliente · Antes e depois 3D · Apresentação de serviço · **Pessoas da topper.** (employer
  branding) · **Um dia na topper.**
- Motor de IA: **Claude (API da Anthropic)**, chave só no servidor; registo de uso/custo
  em Definições. A IA nunca publica: propõe, uma pessoa aprova.

### 16.5 Produção (kanban)
Etapas: **Ideia aprovada → Guião → Gravação → Edição → Revisão → Agendado → Publicado**.
- Cartão = peça: responsável, prazo, checklist do formato, pasta automática no Drive
  `Marketing/AAAA-MM/<peça>/`, versões por rede.
- **Dias de gravação:** agrupar várias peças num dia; gera lista de planos e material.
- **Revisão:** aprovam **superadmin ou admins** (por agora); ver peça final + legendas de
  cada rede; Aprovar · Pedir alterações (com comentário).
- Arrastar entre etapas, «Anular», histórico com ícones.

### 16.6 Calendário editorial
Mês/semana com linha por rede; arrastar peça aprovada para um dia = agendar.
**Cadência alvo por semana** (Definições): **Instagram 2–3 · LinkedIn 5 · X 10**; semanas
abaixo do alvo destacadas. Indicador de equilíbrio de pilares no mês.

### 16.7 Publicação
- **Fase 1 (agora): publicação assistida** — lembrete a quem publica no dia, copiar legenda
  por rede num clique, ficheiro final pronto; depois colar o link da publicação; métricas
  introduzidas à mão (lembrete 7 dias depois).
- **Fase 2 (depois):** publicação e métricas automáticas via APIs (Meta/Instagram, LinkedIn
  página, X — paga). Preparar a estrutura, não implementar já.

### 16.8 Desempenho
Métricas por publicação (alcance, interações, guardados, partilhas, cliques); médias por
pilar, formato, rede, dia/hora. **Link com rastreio por publicação**: leads do formulário do
site vindas dele entram com Origem «<rede> · <peça>» → medir conteúdo → lead → negócio → €.
Para employer branding: candidaturas/contactos de talento. Resumo mensal escrito pela IA.

---

## 17. Aba Equipa (decidido 22 out 2026)

Substitui o que contradiga a §3.9 e completa a §9.3/§9.6. Aplica a §11.

### 17.1 Separadores
| Separador | Quem vê | Conteúdo |
|---|---|---|
| **Equipa** (por defeito) | todos | cartão de cada pessoa |
| **Organograma** | superadmin, admins, chefes de equipa | estrutura da empresa |
| **Utilizadores e acessos** | superadmin, admins | contas, papéis, **permissões** (grelha papel × aba e exceções por utilizador, §9.3) |
| **Departamentos** | superadmin, admins | departamentos, subdepartamentos e chefes |

### 17.2 Papel, cargo e sócio
- **Papel** = o que pode fazer (Superadmin · Admin · Chefe de equipa · Colaborador).
- **Cargo** = título na empresa; não dá permissões. Lista gerida por admins; uma pessoa pode
  ter vários cargos; um cargo pode estar **por preencher** (posição aberta no organograma).
  Lista inicial: **CEO · COO · CMO · CTO · CFO** · Business Developer · Post Production Lead ·
  Desenvolvedor de Produto + cargos de produção (3D Artist, Compositor, Editor, Produtor…).
- **Sócio** = marca sim/não. Sócios: Rodrigo Nicolau, Valdir Furtado, Guilherme Albano,
  Diogo Vasconcelos, Santiago Apolónio.

### 17.3 Estrutura inicial (documento «Estrutura da Topper Digital», 18 out 2026)
| Departamento | Responsável | Cargo |
|---|---|---|
| Direção | Rodrigo Nicolau | CEO (transversal: entra em todas as áreas) |
| Comercial | Santiago Apolónio | COO e Business Developer |
| Pós-Produção e VFX | Guilherme Albano | Post Production Lead |
| Campanhas e Adverts (subdep.: **Adverts**, **Campanhas**; equipas a definir) | Valdir Furtado | CMO |
| Produto e Otimização de Processos | Diogo Vasconcelos | **CTO** (e Desenvolvedor de Produto); passa a CPO, ou acumula, se a área vender produtos |
- Os quatro departamentos respondem ao CEO. Suportar **subdepartamentos**.
- A estrutura vai mudar: tudo editável pelos admins, sem código.

### 17.4 Organograma
Árvore a partir de «responde a»: foto, nome, cargo(s), departamento; sócios marcados;
cargos por preencher a tracejado; clicar abre o cartão; admins editam arrastando.

### 17.5 Cartão da pessoa (separador Equipa)
Foto · nome · cargo(s) · departamento · telemóvel e email (ligar / email) ·
**online/offline em tempo real** (ponto verde/cinzento; «visto há X min» quando offline;
presença por sinal periódico da sessão, ~30 s) · estado da agenda (Disponível · Ocupado até
HH:MM · Ausente) · botões **Chat** e **Ver disponibilidade**.
Custo/hora e horas disponíveis por mês (Orçamentação) no mesmo perfil, **visíveis só a admins**.

### 17.6 Chat interno
- **Só conversas privadas 1:1** (grupos ficam para depois).
- Painel lateral, sem sair da página; contador de não lidas na barra de topo + notificação.
- Links de registos (lead, negócio, tarefa) aparecem como cartões clicáveis.
- Mensagens guardadas no servidor; só os dois participantes as leem.

### 17.7 Disponibilidade e reuniões
- Tarefas com hora têm **Ocupado / Livre**; Ocupado bloqueia o bloco na agenda.
- Horário de trabalho por pessoa (por defeito **seg–sex 09:00–18:00**, editável); férias e
  ausências bloqueiam.
- «Ver disponibilidade»: semana com blocos Livre/Ocupado; os outros veem só «Ocupado»;
  **admins veem o título**.
- **Pedir reunião:** clicar num bloco livre → duração, assunto, negócio (opcional),
  **plataforma: Google Meet ou Discord**. O pedido chega **no chat** como cartão com
  **Aceitar / Recusar** (e «Propor outra hora»).
- **Ao aceitar:** cria a tarefa Ocupado na agenda dos dois (e no histórico do negócio, se
  houver) e **envia email** aos participantes com o **link da chamada**:
  - Google Meet: link criado pela integração Google (a configurar — credenciais só no servidor);
  - Discord: link de redirecionamento para o canal/servidor de voz da topper. (a configurar).
  Até as integrações estarem configuradas, o campo de link fica manual.
- «Encontrar hora» para várias pessoas (espaços livres comuns).

### 17.8 Utilizadores e acessos
Criar utilizador → convite por email para definir palavra-passe. **Desativar, nunca
apagar**; ao desativar, pede reatribuição de leads, negócios e tarefas abertas. Campos:
papel, departamento/subdepartamento, cargo(s), sócio, responde a, horário, custo/hora,
horas/mês. Permissões geridas aqui. Só o superadmin mexe em admins.

### 17.9 Contas iniciais dos sócios
Criar com convite por email (definir palavra-passe), papel **Admin**, sócio = sim, e cada
um como chefe do seu departamento. O superadmin continua a ser só `info@topperdigital.xyz`.
| Nome | Email | Departamento | Cargo |
|---|---|---|---|
| Rodrigo Nicolau | rodrigonicolau@topperdigital.xyz | Direção | CEO |
| Santiago Apolónio | santiagoapolonio@topperdigital.xyz | Comercial | COO · Business Developer |
| Guilherme Albano | guilhermealbano@topperdigital.xyz | Pós-Produção e VFX | Post Production Lead |
| Valdir Furtado | valdirfurtado@topperdigital.xyz | Campanhas e Adverts | CMO |
| Diogo Vasconcelos | diogovasconcelos@topperhub.xyz | Produto e Otimização de Processos | CTO |
Se alguma destas contas já existir, atualizar em vez de duplicar.

### 17.10 Gerir sem microgestão
Princípios: **sem registo de horas, sem vigiar atividade**; online/offline diz só se a
pessoa está na plataforma, nunca o que faz.
- **Carga da equipa** (admins): mapa semanal por pessoa — horas Ocupado vs horas disponíveis
  (🟢 <80% · 🟠 80–100% · 🔴 >100%), para distribuir trabalho.
- **Entrada e saída:** checklist automática de entrada (conta, acessos, pasta no Drive,
  apresentação à equipa, primeira tarefa); saída = reatribuir trabalho e retirar acessos.
- **Férias e ausências:** pedido pela pessoa → aprovação por admin → bloqueia a agenda;
  calendário de ausências da equipa.
- **«Hoje na equipa»:** linha no topo do separador Equipa — ausentes, em reunião, aniversários.
- **Modo Foco / Não incomodar:** a pessoa silencia chat e notificações por um período;
  cartão mostra «Em foco até HH:MM»; pedidos de reunião ficam em espera até ao fim.
- **«Quem sabe o quê»:** competências por pessoa (ex.: Houdini, Nuke, After Effects, color
  grading), pesquisáveis no separador Equipa.
- **O meu perfil:** cada pessoa edita foto, telemóvel, competências, horário e data de
  aniversário (opcional).

---

## 18. Aba Tarefas (decidido 22 out 2026)

Substitui o que contradiga a §3.5. Aplica a §11 e a §17.7.

### 18.0 Conta do superadmin
A conta de login atual **admin@topperdigital.xyz** (fictícia) passa a ter o email
**info@topperdigital.xyz**, **mantendo a mesma palavra-passe** e o papel **Superadmin**
(alterar o email da conta existente; não criar outra; não tocar no hash da palavra-passe).
A partir daí, todas as referências a «admin» como pessoa passam a ser o superadmin `info@`.

### 18.1 Separadores
**Tarefas** (abre por defeito) · **Quadro da equipa** · **Agenda** (antes «Calendário»).
O separador «Equipa» desta aba sai (existe a aba Equipa).

### 18.2 O superadmin não é operador
- Operadores: **Rodrigo, Santiago, Valdir, Guilherme, Diogo**. O superadmin (`info@`) não
  aparece como responsável possível, nem como coluna no quadro, nem em distribuições;
  continua a ver e gerir tudo.
- **Redistribuição única** das leads atribuídas à conta admin: em rotação pelos 5 operadores
  (por ordem de criação; 23/22/22/22/22 para 111). As tarefas «Ligar a…» de cada lead
  acompanham o novo responsável. Registar no histórico de cada lead.

### 18.3 Tarefas (lista)
- Linhas compactas: ícone do tipo · marca de origem · título · ligação · prazo · responsável.
- **Um só quadro** (desde 29 out 2026, §10.25): todas as tarefas numa lista, pela ordem de urgência — as em
  atraso primeiro, com uma barra vermelha à esquerda; o cabeçalho diz quantas há e quantas estão em atraso.
  (Antes: grupos recolhíveis Em atraso · Hoje · Esta semana · Mais tarde · Sem data.)
- Concluídas não aparecem (filtro próprio; em «Todas» vêm no fim da mesma lista); ao concluir, «Concluída · Anular».
- **«Abrir na origem»** (ícone ↗ em cada linha) em todas as tarefas criadas fora da aba Tarefas: lead, negócio,
  contacto/relação, peça de Marketing, quadro do Centro Criativo e Financeiro (saldos → Tesouraria, calendário
  fiscal → Impostos). As criadas na aba Tarefas só o têm se tiverem sido ligadas a um negócio ou contacto.
- **Agrupamento de tarefas automáticas iguais:** chamadas de leads e follow-ups de relação
  aparecem numa linha por pessoa, ex.: «☎ Chamadas de leads · 22 · 5 em atraso · Abrir fila →»
  (abre a aba Leads com «Urgente»/a lista de Relações).

### 18.4 Quadro da equipa
- Colunas: **Por atribuir** + uma por operador; topo de cada coluna com barra de carga
  (§17.10) e contadores atrasadas · hoje · total.
- **Máximo 6 cartões por coluna** (os mais urgentes) + «+ N tarefas» que expande/abre a
  lista da pessoa. Tarefas automáticas agrupadas como na lista.
- **Sem coluna «Feito»** (filtro Concluídas).
- Entreajuda: **arrastar** para a própria coluna; botão **«Pegar»** em tarefas de colegas;
  botão **«Pedir ajuda»** (envia para Por atribuir com 🙋 e notifica a equipa). Tudo no histórico.

### 18.5 Filtros (lista e quadro)
Lista (desde 29 out 2026): **Estado** num só botão (Por fazer · Concluídas · Todas) · **Em atraso** (um botão, com
o número) · **Por atribuir** (um botão, com o número; volta a carregar para tirar) · **Quem** (eu · todos · cada
operador) · **Tipo** · **Origem** (Lead · Negócio · Marketing · Relação · Financeiro · Pessoal · Manual) ·
**Negócio** (ID/nome). «Hoje» e «Esta semana» só vêm de vistas guardadas antigas (aparecem como filtro a tirar).
Quadro da equipa: Urgência (Em atraso · Hoje · Esta semana) · Tipo · Origem · Negócio.
Filtros guardáveis como vistas.

**Quem vê o quê (29 out 2026), sempre conferido no servidor (lista, quadro, agenda, pesquisa e ações):** a lista
abre só com as tarefas da própria pessoa («Quem: Eu»). Admins e superadmin podem escolher «Todos» ou cada pessoa
(o superadmin, que não tem tarefas, abre em «Todos»). Chefes de equipa: «A minha equipa» (as suas e as dos membros
do departamento) ou cada membro. Membros: só as suas — sem botão «Quem». **Por atribuir:** admins veem todas; o
chefe vê as da sua equipa e as que ainda não têm equipa; o membro só as da sua equipa. A equipa de uma tarefa por
atribuir é o departamento de quem pediu ajuda, senão o do comercial do negócio, senão o de quem a criou; quem a
criou ou pediu ajuda vê-a sempre. O âmbito de cada função é o da grelha de permissões (Tarefas: Todos ·
Do departamento · Só os meus).

### 18.6 Tipos, origens, ícones e cores
- **Tipo** = ícone principal da tarefa (16): Chamada · Reunião · Email · Mensagem/WhatsApp ·
  Follow-up · Visita/deslocação · Gravação · Edição · Render · Revisão/aprovação · Entrega ·
  Orçamento/proposta · Documento · Tarefa genérica · Pessoal · Bloqueio.
- **Origem** = marca pequena com **o mesmo ícone da aba de origem no menu** e a cor dessa
  origem: Lead · Negócio · Marketing · Relação (Contactos) · Pessoal · Manual (Tarefas).
- Cada tipo e cada origem têm cor própria a partir da paleta (tokens claro/escuro); dica com
  o nome ao passar o cursor (§11). A mesma linguagem na lista, no quadro e na agenda.

### 18.7 «+ Nova tarefa»
Mesmo formulário da «Nova ação» das Leads (§12.7), com a animação da §13.8, mais completo:
tipo (**só ícones**, sem texto; o nome do tipo aparece ao passar o cursor ou com toque longo — §18.6) · título · notas · atalhos de tempo · data, hora, duração · responsável
(nunca o superadmin) · ligação a negócio/lead/contacto · prioridade · checklist · anexos ·
repetir · Ocupado/Livre · participantes (convite pelo chat, §17.7).
**Tipo Reunião:** escolher **Google Meet** ou **Discord**; sem nenhum = **presencial** e
aparece o campo **Local**.

### 18.8 Agenda
- Vistas Dia · Semana · Mês com as **24 horas completas**; ao abrir, desloca-se para a hora
  atual; horas fora do horário de trabalho mais escuras.
- Criar arrastando na grelha → **Tarefa**, **Tarefa pessoal** ou **Bloqueio**.
- **Bloqueio:** tempo reservado sem tarefa; ocupado.
- **Tarefa pessoal:** sempre ocupada e **privada** — **todos, incluindo admins, veem só
  «Ocupado»** (exceção à §17.7).
- Mostra tarefas com hora, reuniões, ausências e prazos de tarefas sem hora.

---

## 19. Pipeline, funis e automações — PROPOSTA v1 (22 out 2026, a validar com a equipa)

> Estado (23 out 2026): **6 funis e etapas aprovados**; tarefas automáticas da §19.6 definidas
> após pesquisa, a confirmar com a equipa no domingo. Implementar a **estrutura** (etapas configuráveis, gates,
> X-Ray, propostas com duas opções, resposta do cliente, motor de automações) e carregar as
> etapas/tarefas abaixo como **valores iniciais editáveis** em Definições.

### 19.1 Fase comercial (igual em todos os funis)
**X-Ray → Proposta → Aguardar feedback → Kickoff**, cada passagem com **gate** (requisitos):
| Etapa | Para avançar é obrigatório |
|---|---|
| X-Ray | Formulário X-Ray preenchido e **validado** pelo responsável |
| Proposta | Folha de orçamento com **opção «Pedido»** (e opcional «Proposta de valor») · prazo · condições de pagamento · revisões incluídas · direitos de uso · validade · PDF gerado → **enviar** move para Aguardar feedback |
| Aguardar feedback | Resposta do cliente **validada internamente** (aceite → Kickoff; recusa → Perdido com motivo) |
| Kickoff | Contrato assinado + pagamento de adjudicação **validado** → entra na produção |
- Gates verificados no servidor. Admins podem forçar a passagem com motivo obrigatório (histórico).
- «Perdido» continua a ser botão em qualquer etapa comercial (§3.2).

### 19.2 X-Ray (qualificação e briefing)
Formulário no painel do negócio; campos **comuns** + campos **por funil**.
- Comuns: decisor e aprovadores · objetivo/problema · entregáveis e quantidades · formatos e
  canais · referências · prazo e datas fixas · **faixa de orçamento** (lista) · ativos
  disponíveis · como nos encontrou · outros fornecedores em concurso · uso/território/duração ·
  pontuação de qualificação (orçamento, autoridade, necessidade, prazo — 1 a 3 cada).
- Por funil (iniciais):
  - VFX & Pós: corte/EDL ou guião · nº e duração de shots · tipos de trabalho (cleanup, roto,
    comp, CG, grade, som) · câmara/resolução/pipeline de cor · handles · datas de turnover.
  - CGI/3D: CAD/fotos/amostras do produto · estilo · duração e formatos · personagem/líquidos/
    simulação · reutilização dos assets.
  - Adverts: objetivo e KPI · público · mensagem-chave · canais · guidelines · talento,
    locais, música.
  - Campanhas: pilares · nº de peças por rede · calendário · quem publica · rodagem existente
    reutilizável · tempo de resposta.
  - Ads: oferta · público · plataformas · anúncios atuais e resultados · KPI (CPA, ROAS, hook
    rate) · conceitos × variações · specs · quem gere o media.
  - Marketing Buddy: objetivos a 12 meses · equipa e ferramentas atuais · canais · capacidade
    mensal · fluxo de aprovação · relatórios.
- Duas secções finais: **«Pedido do cliente»** (âmbito tal como pedido) e **«Proposta de
  valor»** (melhorias/upsell: ex. cutdowns e formatos sociais, reels da mesma rodagem,
  consultoria de acompanhamento, ronda de revisão extra, licença de assets, uso alargado,
  urgência).

### 19.3 Proposta com duas opções (altera §14)
Cada versão da folha de orçamento pode ter **Opção A — Pedido** e **Opção B — Proposta de
valor**, cada uma com as suas linhas, preço, margem e lucro/mês. O PDF mostra as duas lado a
lado (cada uma com âmbito claramente delimitado). Condições de pagamento por defeito
(Definições): **50% adjudicação / 50% entrega**; acima de 10 000 €: **40% / 40% / 20%**
(adjudicação / início da pós ou rodagem / entrega). Marketing Buddy: mensal adiantado,
mínimo 3 meses.

### 19.4 Resposta do cliente por email (segura)
- O email tem **um único botão «Ver e responder à proposta»** — nunca botões que aceitam ou
  recusam diretamente. Abrir o link (GET) **nunca altera nada** (os filtros de segurança de
  email abrem links automaticamente).
- A página mostra as opções, preço, condições e validade; o cliente escolhe **Opção A ou B**,
  marca «Li e aceito as condições», **escreve o nome** e confirma (POST com proteção CSRF).
  Recusar pede motivo opcional.
- Regista: data/hora, IP, navegador, nome escrito, opção, versão e hash da proposta; envia
  confirmação ao cliente e ao responsável. Link com validade (= validade da proposta),
  pode ser aberto várias vezes; a proposta fica bloqueada após resposta (alterações = nova versão).
- **Nada passa automaticamente:** a resposta chega como «Resposta do cliente: aceitou a Opção
  B» e o responsável carrega em **Validar** (aceite → Kickoff) ou **Contactar cliente**
  (clique por engano). Até à validação, o cliente pode mudar a resposta na mesma página.

### 19.5 Fase de produção por funil (iniciais; Concluído é fixo e tem o fecho da §15.5)
| Funil | Etapas de produção |
|---|---|
| **VFX & Pós-produção** | Turnover e breakdown → Look dev / shot de referência → Produção de shots → Revisões do cliente → Finais e QC → Entrega |
| **CGI / 3D** | Styleframes → Storyboard e animatic → Modelação e lookdev → Animação → Render e composição → Revisões → Entrega |
| **Adverts** | Conceito e tratamento → Pré-produção (PPM) → Rodagem → Montagem offline → Picture lock → Pós (cor, VFX, som, música) → Versões e entrega |
| **Campanhas** | Estratégia e calendário → Guiões do lote → Gravação do lote → Edição do lote → Revisão → Entrega e agendamento → Relatório (repete por lote) |
| **Ads** | Ângulos e ganchos → Guiões → Produção → Edição e variações → Revisão → Exportação por plataforma → Lançamento e leitura |
| **Marketing Buddy** | Onboarding → Configuração do agente → Ativo (ciclo mensal: otimizar → reportar, repete) → Revisão trimestral e renovação — *serviço de agente de IA, ver 19.11* |
- Funis: os 5 atuais passam a **6**, separando **Adverts** de **Campanhas** (aprovado). Funis combinados (§9.1) juntam as etapas.
- Rondas de revisão incluídas por defeito (Definições, por funil): VFX 2 por shot · CGI 2 no
  styleframe, 2 no animatic, 1 no render · Adverts 2 offline + 1 online · Campanhas/Ads 1 por lote.
  Alterações fora disso = pedido de alteração orçamentado.
- Gates de produção (iniciais): VFX turnover verificado; CGI animatic aprovado antes de
  modelação final; Adverts PPM aprovado antes da rodagem e picture lock por escrito antes da
  pós; todos: aprovação final do cliente por escrito antes de Entrega.

### 19.6 Tarefas automáticas por etapa (v2, após pesquisa — 23 out 2026)
Legenda: tipo (§18.6) · **C** = responsável comercial do negócio · **E** = responsável de
execução do negócio · prazo relativo à entrada na etapa (d.u. = dias úteis) ou à data de
entrega/rodagem · **[gate]** = tem de estar concluída para sair da etapa (só quando saltá-la
cria risco legal, financeiro ou de qualidade). Todas editáveis no motor de automações (§19.7).

**Responsáveis:** cada negócio tem **responsável comercial** (fase comercial) e **responsável
de execução** (produção), atribuídos por um admin (por agora qualquer operador). As
automações usam estes dois papéis; se não houver responsável atribuído, a tarefa vai para
«Por atribuir».

#### A. Fase comercial (todos os funis)
- **X-Ray:** Chamada de descoberta (objetivo, público, orçamento, prazo, decisor) · Chamada ·
  C · +1 [gate] — Recolher brief, referências e materiais da marca · Email · C · +2 —
  Confirmar decisor, orçamento e data-alvo · Tarefa · C · +2 — Validar viabilidade técnica e
  capacidade com a execução · Reunião · E · +3 — Preencher e validar X-Ray · Documento · C ·
  +3 [gate].
- **Proposta:** Orçamento Opção A (linhas, IVA, extras, deslocações) · Orçamento · C · +3
  [gate] — Proposta de valor (Opção B) · Orçamento · C · +3 — Âmbito escrito: entregáveis,
  formatos, nº de rondas de revisão incluídas e preço de ronda extra · Documento · C · +3
  [gate] — Direitos de utilização propostos (meios, território, duração, orgânico/pago) ·
  Documento · C · +3 [gate] — Cronograma com marcos de aprovação · Documento · E · +3 —
  Enviar e apresentar a proposta numa chamada · Reunião · C · +4.
- **Aguardar feedback:** Follow-up 1 · Follow-up · C · +3 — Follow-up 2 (lembrar validade) ·
  Follow-up · C · +7 — Sem resposta: decidir perder ou manter · Tarefa · C · +15 (sugere
  «Perdido» com motivo «Sem resposta»).
- **Kickoff:** Contrato assinado (âmbito, rondas, alterações, direitos, pagamentos) ·
  Documento · C · +2 [gate] — Emitir fatura de adjudicação · Orçamento · C · +1 [gate] —
  Validar receção do pagamento de adjudicação · Tarefa · C · +5 [gate] — Reunião de passagem
  Comercial → Execução (brief, contrato, contactos, riscos) · Reunião · C+E · +3 [gate] —
  Reunião de arranque com o cliente (aprovadores e canal único de feedback) · Reunião · E · +5
  — Criar pasta no Drive, canal e cronograma detalhado · Tarefa · E · +3.

#### B. Fecho (Entrega → Concluído, todos os funis; junta-se à §15.5)
Aprovação final por escrito · Email · E · +2 [gate] — Enviar entregáveis finais com
comprovativo de download · Entrega · E · +1 [gate] — Documento de direitos e licenças
(música, stock, talento: âmbito e validade) · Documento · C · +2 [gate] — Emitir fatura final
· Orçamento · C · +1 [gate] — Validar pagamento final · Tarefa · C · +30 [gate para fechar o
Resultado] — Arquivar projeto (masters, projetos, autorizações) · Tarefa · E · +10 — Avaliar
fornecedores · Tarefa · E · +5 — Pedir testemunho e autorização de portfólio · Email · C · +5
— Case study (após autorização) · Documento · C · +20 — Follow-up de upsell · Follow-up · C ·
+45 (cria tarefa de Relação).

#### C. Produção por funil
**1. VFX & Pós-produção**
- Turnover e breakdown: Receber turnover (EDL/XML, plates EXR, handles, corte de referência,
  LUT, specs) · Tarefa · E · +1 [gate] — Verificar lineup (frames, handles, timecode,
  formato) · Revisão · E · +2 [gate] — Breakdown e orçamento por shot · Documento · E · +3 —
  Teste de pipeline com um shot · Render · E · +3.
- Look dev / shot de referência: Produzir shot de referência · Edição · E · +5 — Aprovação do
  look por escrito · Revisão · E · +7 [gate].
- Produção de shots: Tracker de shots (estado e versões) · Tarefa · E · contínuo — Dailies
  internos e QC por versão · Revisão · E · diário (recorrente) — Status semanal ao cliente ·
  Email · E · semanal (recorrente).
- Revisões do cliente: Enviar versões com contador de rondas · Revisão · E · por ronda —
  Consolidar feedback numa lista única · Documento · E · +1 — Fora do âmbito ou acima das
  rondas → pedido de alteração aprovado por escrito · Orçamento · C · antes de executar [gate].
- Finais e QC: Render dos finais nas specs · Render · E · −5 dias da entrega — QC técnico
  (frames, artefactos, espaço de cor, nomes) · Revisão · E · −3 [gate].
- Entrega: Entregar EXR/ProRes e documento de entrega · Entrega · E · data de entrega.

**2. CGI / 3D**
- Styleframes: Moodboard e referências · Documento · E · +2 — 2 a 3 styleframes · Edição · E ·
  +5 — Aprovação do estilo por escrito · Revisão · E · +7 [gate].
- Storyboard e animatic: Storyboard · Documento · E · +3 — Animatic com timing e áudio guia ·
  Edição · E · +5 — Aprovação do animatic (fecha câmara e duração) · Revisão · E · +7 [gate].
- Modelação e lookdev: Receber CAD/ficheiros do produto · Email · E · +1 — Modelos e
  materiais · Tarefa · E · conforme plano — Turntables para aprovação · Revisão · E · fim da
  etapa [gate].
- Animação: Blocking · Edição · E — Revisão do blocking com o cliente · Revisão · E — Polish ·
  Edição · E.
- Render e composição: Teste de render e estimativa de tempo de farm · Render · E · +1 —
  Render final · Render · E · −5 da entrega — Composição e cor · Edição · E · −3.
- Revisões: como VFX (pedido de alteração [gate]).
- Entrega: QC técnico · Revisão · E · −1 [gate] — Entregar masters e versões · Entrega · E.

**3. Adverts**
- Conceito e tratamento: Tratamento (conceito, look, referências) · Documento · E · +5 —
  Guião · Documento · E · +5 — Aprovação do tratamento e guião · Revisão · E · +7 [gate].
- Pré-produção (PPM): Casting com contratos e **autorização de imagem por escrito** (RGPD e
  art. 79.º do Código Civil; âmbito e duração) · Documento · E · −5 dias da rodagem [gate] —
  **Licença de filmagem municipal** (Lisboa Film Commission/CML; estradas e linhas de comboio
  ≥ 15 d.u. antes) · Documento · E · −15 d.u. da rodagem [gate] — Seguros (RC, equipamento,
  acidentes de trabalho) · Documento · E · −5 [gate] — Autorizações dos locais · Documento · E
  · −5 [gate] — Visita técnica ao local · Visita · E · −5 — Reunião de PPM com o cliente
  (lista de planos, guarda-roupa, cronograma) · Reunião · E · −3 [gate] — Folha de serviço
  (call sheet) · Documento · E · −1.
- Rodagem: Rodagem · Gravação · E · dia D — Recolher autorizações assinadas no set (incl.
  figurantes) · Documento · E · dia D [gate] — Cópia de segurança dupla do material ·
  Tarefa · E · dia D [gate].
- Montagem offline: Sincronizar e importar · Edição · E · +1 — Primeiro corte · Edição · E · +5
  — Revisão do cliente · Revisão · E.
- Picture lock: Aprovação do lock por escrito (depois disto, mudanças = pedido de alteração) ·
  Revisão · E [gate] — Turnover para a pós · Tarefa · E · +1.
- Pós (cor, VFX, som, música): **Licença de música** (library com licença para meios pagos,
  ou sync + master) · Documento · C · antes da mistura [gate] — Cor · Edição · E — Mistura de
  som com loudness por plataforma · Edição · E — VFX e grafismo · Edição · E.
- Versões e entrega: Cutdowns e formatos · Render · E · −3 da entrega — QC final (legendas,
  ortografia, loudness, safe areas) · Revisão · E · −2 [gate] — Entregar · Entrega · E.

**4. Campanhas** (repete por lote)
- Estratégia e calendário: Workshop de pilares e formatos · Reunião · E · +3 — Calendário
  editorial aprovado · Documento · E · +5 [gate só no 1.º lote].
- Guiões do lote: Guiões e ganchos · Documento · E · +3 — Aprovação dos guiões · Revisão · E ·
  −3 da gravação [gate].
- Gravação do lote: Confirmar local, talento e autorizações de imagem válidas · Documento · E ·
  −2 [gate] — Gravação · Gravação · E — Cópia de segurança · Tarefa · E · dia D.
- Edição do lote: Edição com legendas e música licenciada · Edição · E · +5 — QC interno ·
  Revisão · E · +6.
- Revisão: Enviar com contador de rondas · Revisão · E — Aprovação final do lote · Email · E [gate].
- Entrega e agendamento: Exportar e entregar · Entrega · E — Agendar publicação (se no âmbito) · Tarefa · E.
- Relatório: Métricas e aprendizagens do lote · Documento · E · +7 após publicação — Faturar o
  lote (se o contrato for por lote) · Orçamento · C [gate].

**5. Ads**
- Ângulos e ganchos: Pesquisa (anúncios da concorrência, reviews, dores do público) · Tarefa
  · E · +2 — Matriz ângulos × ganchos aprovada · Documento · E · +3 [gate].
- Guiões: Guiões por ângulo com **afirmações confirmadas pelo cliente** (publicidade) ·
  Documento · E · +3 [gate].
- Produção: Gravação/UGC com **autorizações que cobrem meios pagos** · Gravação · E [gate].
- Edição e variações: Master por ângulo · Edição · E — Variações de gancho, CTA e duração com
  convenção de nomes para testes · Edição · E.
- Revisão: Rondas de revisão · Revisão · E — Aprovação · Email · E [gate].
- Exportação por plataforma: Formatos 9:16, 4:5, 1:1, 16:9, safe zones, legendas · Render · E ·
  −1 — QC · Revisão · E [gate].
- Lançamento e leitura: Confirmar upload/lançamento com quem gere o media · Mensagem · E · dia
  D — Leitura de resultados (CTR, hook rate, CPA) e recomendações · Documento · E · +7 a +14.

**6. Marketing Buddy** (agente de IA para otimizar campanhas; avença — ver 19.11)
- Onboarding: Acessos às contas de anúncios, redes e analytics do cliente · Email · E · +2
  [gate] — Reunião de objetivos e KPI (CPA, ROAS, orçamento de media) · Reunião · E · +3 —
  Faturação mensal recorrente configurada · Orçamento · C · +2 [gate] — Acordo de tratamento
  de dados (RGPD) para acesso às contas · Documento · C · +2 [gate].
- Configuração do agente: Ligar fontes de dados e definir regras/limites do agente · Tarefa ·
  E · +5 — Validar configuração com o cliente · Revisão · E · +7 [gate].
- Ativo (recorrente mensal): Rever recomendações/otimizações do agente · Revisão · E ·
  semanal — Relatório mensal ao cliente · Documento · E · dia 5 — Fatura mensal (submeter
  documento) e validar pagamento · Orçamento · C · dia 1 [gate] — Oportunidades de
  cross-selling (ex.: Campanhas) · Tarefa · C · mensal.
- Revisão trimestral e renovação: Reunião de resultados · Reunião · C+E · fim do trimestre —
  Proposta de renovação/upsell · Orçamento · C · −15 dias do fim — Contrato renovado ou
  terminado · Documento · C [gate].

#### D. Rondas de revisão e pedidos de alteração
- **Ronda de revisão** = um conjunto **único e consolidado** de comentários do cliente sobre
  uma versão. Cada proposta diz quantas estão incluídas por fase (valores por defeito em
  Definições, por funil: VFX 2 por shot · CGI 2 no styleframe, 2 no animatic, 1 no render ·
  Adverts 2 offline + 1 online · Campanhas/Ads 1 por lote) e o preço de cada ronda extra.
- Comentários que contradizem ou reabrem algo já aprovado contam como nova ronda.
- **Pedido de alteração:** tudo o que sai do âmbito (novos entregáveis, versões, locais) ou
  mexe depois de um gate de aprovação (look, animatic, picture lock) é orçamentado à parte,
  aprovado por escrito **antes** de se fazer e faturado separadamente. A plataforma conta as
  rondas por fase e avisa quando se ultrapassa o incluído.

#### E. Faturação (atualiza §19.9)
A topper. **ainda não tem software de faturação certificado**. Até existir, as faturas são
emitidas **fora da plataforma**, e a tarefa «Emitir fatura …» **só fica concluída quando o
documento da fatura (PDF) é submetido na plataforma**, com número, data, valor s/ IVA, IVA e
total. O documento fica na pasta do negócio no Drive (`<ID>_<NOME>/Faturas/`) e no Financeiro.
«Validar pagamento» pede a data de recebimento e, opcionalmente, o comprovativo. Sem
documento submetido, o gate não passa. Recomendação: escolher software certificado pela AT com API (ATCUD/QR,
SAF-T) para integrar mais tarde.

### 19.7 Motor de automações (Definições › Funis e automações)
- Por funil → por etapa → lista de regras em frase legível:
  «Quando o negócio **entra em** [etapa] → criar tarefa [tipo] «[título]» para [responsável]
  com prazo [+N dias úteis / data relativa] · [obrigatória sim/não] · [checklist]».
- **Gatilhos v1:** entra na etapa · N dias antes/depois de uma data do negócio (entrega,
  validade da proposta) · recorrente mensal (retainers) · ao validar aceitação · ao validar pagamento.
- **Responsável:** responsável comercial · responsável de execução · responsável do
  departamento · pessoa concreta · por atribuir. Nunca o superadmin (§18.2).
- **Títulos com variáveis:** {cliente} {negócio} {ID} {etapa}.
- Ações: criar, editar, duplicar, ativar/desativar, eliminar (com «Anular»), reordenar;
  **«Aplicar a todos os funis»** para regras comuns; pré-visualização («ao entrar em Kickoff
  vão ser criadas estas 8 tarefas»). Alterações não mexem em tarefas já criadas.
- Só superadmin e admins editam.

### 19.8 Ficha do negócio no Pipeline
Igual ao painel da lead (§12.5), mais completo: barra de etapas com os requisitos de cada
gate (✓/✗); **atalhos**: Formulário X-Ray · Folha de orçamento · Proposta (PDF e estado da
resposta) · Ficha na aba Negócios (a partir do Kickoff) · Pasta no Drive. Foco, Nova ação e
Notas & Histórico como nas Leads.

### 19.9 Faturação
A plataforma cria as tarefas «Emitir fatura» e regista o valor e o estado, mas **não emite
faturas legais** (em Portugal exigem software certificado pela AT). Integração com o software
de faturação a decidir no desenho do Financeiro.

### 19.10 Papel do Comercial e da Execução
- **O Comercial é o principal elo entre o exterior e a empresa.** O responsável comercial
  conduz a lead desde o primeiro contacto, faz o X-Ray e leva o negócio até ao Kickoff; depois
  **mantém-se no negócio até ao fim**: orçamentos, prazos, faturação, alterações de âmbito
  (pedidos de alteração) e acompanhamento do cliente.
- **A Execução** trata do alinhamento do produto com o cliente durante a produção (versões,
  revisões, aprovações técnicas e criativas).
- Consequências na plataforma:
  - o responsável comercial **não sai** do negócio no Kickoff; os dois responsáveis coexistem;
  - tarefas de pedidos de alteração, faturação, prazos e renovações → **C**; tarefas de
    produto, revisões e entregas técnicas → **E**;
  - o responsável comercial recebe notificação quando o negócio passa um gate de produção,
    quando há pedido de alteração ou quando as rondas incluídas se esgotam;
  - **depois da entrega**, o contacto do cliente entra automaticamente nas **Relações**
    (§13.5) com o responsável comercial como dono, para gerar trabalho futuro.

### 19.11 Marketing Buddy
Serviço **independente** dos outros: é o **agente de IA** da topper. para otimizar campanhas
dos clientes (avença mensal). Pode ser vendido em **cross-selling** com Campanhas (funil
combinado §9.1). As etapas e tarefas do funil Marketing Buddy refletem um serviço de agente
(acessos, configuração, otimização contínua, relatórios), não de produção de vídeo.

---

## 20. Perfil e Definições (decidido 22 out 2026)

Aplica a §11. Objetivo: zero ruído operacional e zero microgestão.

### 20.1 Barra de topo e menu do perfil
- O **avatar do utilizador sai da barra lateral** e passa para o **canto superior direito**.
- Clicar no avatar abre um menu: **Perfil** (todos) · **Definições** (só superadmin e admins) ·
  **Sair**.
- O **botão de modo claro/escuro fica fora do menu**, sempre visível na barra de topo (um clique).
- **Perfil** = «O meu perfil» (§17.10) + **preferências pessoais** de cada um: tema · menu
  recolhido/aberto · notificações (quais e por onde: plataforma/email) · modo Foco · vista com
  que cada aba abre (ex.: Leads em «As minhas»). Ninguém mexe nas preferências dos outros.

### 20.2 Barra lateral
**Definições** (ícone de engrenagem) ocupa o lugar onde estava o perfil, no fundo da barra.
Só visível para superadmin e admins.

### 20.3 Separadores das Definições (um por aba com campos editáveis)
| Separador | Conteúdo |
|---|---|
| Geral | dados da empresa (nome, NIF, morada, logo) · IVA 23% · dias úteis seg–sex · horário padrão 09–18 · 1 dia = 8 h · fuso horário |
| Leads | prazos por estado (§12.4) · motivos de arquivo · tipos de lead · origens e campanhas · regras de quarentena · regra da 3.ª tentativa |
| Pipeline | funis e etapas · gates · **automações** (§19.7) · campos do X-Ray por funil · motivos de perda · rondas de revisão incluídas · condições de pagamento · validade das propostas · textos dos emails de proposta |
| Contactos | categorias de fornecedor · lista de serviços e unidades · cadências das Relações (A/B/C) |
| Orçamentação | MSO · margem · meta mensal · % de ocupação padrão · custos fixos mensais (correção) · modelos de orçamento · modelo do PDF |
| Negócios | checklist de fecho · limites dos avisos «Precisa de atenção» |
| Tarefas | tipos (ícone e cor) · cores das origens · máximo de cartões por coluna · agrupamento de tarefas automáticas |
| Marketing | pilares · cadência por rede · voz da topper. · modelos de formato |
| Financeiro | a definir quando a aba for desenhada |
| Drive | pastas base e estrutura das pastas de negócio |
| Integrações | Google Meet · Discord · email de envio · IA (Claude: estado, uso e custo) · futuras: Meta, LinkedIn, software de faturação |
| Segurança | sessão (60 min inativo / 12 h) · bloqueio após 6 tentativas. **Sem autenticação de dois passos.** |
- **Utilizadores, papéis, permissões, cargos e departamentos continuam na aba Equipa** (§17);
  Definições tem apenas um atalho para lá.
- Regra futura: cada nova aba com campos editáveis ganha o seu separador.
- Definições que hoje vivem noutras páginas (ex.: editor de funis, prazo de lead parada) passam
  para aqui; as páginas de origem mostram um atalho «Configurar» só para admins.

### 20.4 Funcionamento
1. **Pesquisa nas Definições** (ex.: «margem» leva direto ao campo).
2. **Grava sozinho**, sem botão Gravar; aviso «<campo> alterado para <valor> · Anular».
3. **Aviso de impacto** antes de alterações que mexem em dados existentes (ex.: «recalcula 14
   leads ativas»), com Confirmar/Cancelar.
4. **«Repor valor de origem»** em cada campo.
5. **Registo de alterações:** quem, o quê, quando, antes → depois, com «Repor» por linha.
6. **ⓘ em cada definição** com uma frase a explicar para que serve.
Todas as alterações verificadas no servidor (só superadmin/admins).

---

## 21. Ajustes a Leads e Tarefas (decidido 22 out 2026)

1. **Quarentena (Leads):** cada lead em quarentena tem dois botões: **«Não é spam»** (passa
   para «Por atribuir» como lead normal, sai da quarentena) e **«É spam»** (elimina, com
   «Anular»). O motivo da quarentena continua visível nas notas.
2. **Vista inicial «As minhas» (Leads):** para operadores (chefes de equipa e colaboradores,
   e admins que sejam operadores), a aba Leads abre em **«As minhas»**; o superadmin abre em
   «Todas». Cada um pode mudar a vista inicial no Perfil (§20.1). O botão mostra o total e os
   atrasos, ex.: «As minhas · 22 · 3 ⚠».
3. **Participantes (formulário de tarefa, §18.7):** o campo só aparece quando o tipo é
   **Reunião, Gravação ou Visita**. Texto do rótulo: «Participantes — recebem o convite no
   chat». **Nunca mostrar referências à especificação (ex.: «§17.7») na interface** — regra geral.

---

## 22. Crosselling: um negócio, várias frentes (decidido 23 out 2026)

Substitui os «funis combinados» da §9.1 (cópias de etapas que não acompanhavam os funis de
origem). Não chegou a ser criado nenhum funil combinado, por isso não há nada a converter; a
opção «Combinar funis» sai das Definições › Funis.

1. **Modelo.** Um negócio de crosselling tem **um serviço principal** (o funil do negócio) e um
   ou mais **serviços adicionais**. A fase comercial é **uma só** (X-Ray → Proposta → Aguardar
   feedback → Kickoff, iguais em todos os funis): o X-Ray junta as perguntas dos serviços (sem
   repetir), há uma proposta e as automações das etapas comerciais dos vários funis juntam-se
   (a mesma tarefa não se cria duas vezes). No **Kickoff** abre-se uma **frente** por serviço
   (o principal incluído). Cada frente segue as etapas, gates e automações **do funil do seu
   serviço** — sem cópias: mudar o funil de VFX muda todas as frentes de VFX — e tem o seu
   responsável de execução. O comercial continua dono do negócio inteiro (§19.10).
2. **Sincronização — escolha obrigatória** num negócio de crosselling, requisito para enviar
   a proposta (e portanto para o Kickoff):
   - **Sincronizadas:** todas as frentes arrancam no Kickoff, partilham a data de entrega e
     **entregam juntas** — nenhuma frente passa a Concluído sozinha; o negócio conclui (com a
     checklist de fecho) quando todas estão na última etapa de produção com os requisitos
     cumpridos, e aí todas passam a Concluído.
   - **Timelines diferentes:** cada frente adicional tem o seu **arranque** (no Kickoff, depois
     de outra frente concluir, ou numa data) e a sua **data de entrega**, e conclui sozinha
     (com as automações de Concluído do seu funil). O negócio conclui quando todas as frentes
     estão concluídas. Sempre **vinculadas** ao mesmo negócio, proposta e plano de pagamento.
3. **Um único plano de pagamento.** A proposta, o orçamento e a faturação são do negócio. As
   tarefas de fatura e de pagamento vêm só do funil principal; as das frentes adicionais não se
   criam (não há faturas em duplicado).
4. **Marketing Buddy em pacote** (por enquanto): a frente Buddy é uma avença; o negócio pode
   concluir quando as outras frentes terminam e a frente Buddy continua «Ativa», ligada ao
   negócio.
5. **Medir.** Cada frente guarda o seu valor (s/ IVA): as linhas da folha de orçamento podem
   ser marcadas por serviço e o preço divide-se pelo custo de cada serviço. Cada negócio guarda
   como nasceu o crosselling: **pacote** (na mesma proposta) ou **posterior** (negócio novo de
   um cliente que já comprou, ligado ao negócio anterior). **Relatórios › Crosselling:** matriz
   serviço × serviço (negócios ganhos e valor), % de negócios de cada serviço com um segundo
   serviço, taxa de fecho das propostas com pacote vs. isoladas, tempo médio até ao crosselling
   posterior, combinações que mais vendem e o resultado das oportunidades.
6. **Também na primeira versão:**
   - **Pacotes** pré-definidos (Definições › Pipeline): nome, serviços, sincronização por defeito
     e modelo de orçamento opcional; escolher um pacote preenche os serviços (e o orçamento).
   - **Oportunidades no X-Ray:** os serviços falados com o cliente e não incluídos ficam
     registados como oportunidade (proposto e não vendido conta nos relatórios).
   - **Crosselling depois da entrega:** regras nas Definições (ex.: VFX → propor Ads e Marketing
     Buddy, 30 dias depois); ao concluir, o comercial recebe a tarefa «Propor … a {cliente}» se o
     cliente ainda não tiver esse serviço, e fica uma oportunidade.
   - **Serviços comprados** na ficha do contacto: o que já comprou, o que falta oferecer e as
     oportunidades abertas, com atalho para um negócio novo.

---

## 23. Centro Criativo (decidido 23 out 2026)

Espaço de trabalho criativo da equipa de execução — «Milanote com esteroides» ligado ao
negócio, à produção e à IA. Não confundir com Marketing (§16). Aplica a §11.

### 23.1 Separadores
**Projetos · Biblioteca · Caixa de entrada · Review.**
- **Projetos:** um quadro por negócio, com sub-quadros por etapa (Brief · Referências ·
  Conceito · Tratamento · Styleframes · Storyboard · Lista de planos · Review), a partir de um
  modelo por funil. Tela infinita com colunas e grelha suave (snap), cartões: imagem, vídeo,
  link (pré-visualização), nota, lista, coluna, amostra de cor, desenho, ficheiro, setas,
  sub-quadro. Comentários e @menções por cartão. Modo apresentação.
- **Biblioteca:** todas as referências; uma referência pode estar em vários quadros **sem
  cópia** (referência, não duplicado). Pesquisa por texto, **por cor** (paleta extraída) e
  **«mais como este»** (semelhança visual). Filtros: estilo, técnica, câmara, ambiente, luz,
  cor, origem (projetos topper. / externas). Frames aprovados nos projetos entram na
  biblioteca como referências da casa (ligados ao negócio; respeita «Pode ser divulgado?»
  apenas para uso externo).
- **Caixa de entrada:** tudo o que é capturado (colar, arrastar, partilha do telemóvel,
  extensão do browser na fase 3) entra aqui já analisado pela IA; triagem = arrastar para um
  quadro.
- **Review:** versões v001… lado a lado; comentários no **frame exato** (timecode) com desenho;
  Aprovar · Pedir alterações. Aprovar conclui a tarefa de aprovação correspondente no Pipeline
  (styleframes, animatic, picture lock…); conta rondas (§19.6 D) e avisa o Comercial quando se
  passa das incluídas.

### 23.2 Quadro do projeto
Criado no **Kickoff**. Se existir pré-criativo (23.3), o quadro nasce a partir dele: a opção
escolhida pelo cliente passa a ser o sub-quadro **Conceito**; a outra fica guardada dentro do
quadro como «Rota não escolhida». O brief vem do X-Ray.
Negócios com várias frentes (crosselling, §22): um quadro por negócio com **um sub-quadro
por frente**; cada frente tem o seu responsável de execução e o seu Review.

### 23.3 Pré-criativo (fase comercial, X-Ray → Proposta)
- **Nasce sozinho** quando o X-Ray é validado: cria o quadro «Pré-criativo · <negócio>» com o
  brief do X-Ray e **duas faixas: Opção A (Pedido) e Opção B (Proposta de valor)**; cria a
  tarefa «Pré-criativo · <cliente>» para o responsável de execução (prazo por defeito +2 d.u.,
  Definições). **Um só quadro com duas faixas**, não dois quadros.
- **Rápido por desenho** (orçamentos em massa; objetivo < 30 min):
  1. «Preparar com IA»: a IA lê o brief, propõe 3 ângulos por opção e sugere 6–9 referências da
     biblioteca para cada faixa;
  2. a equipa só **mantém / tira / troca** cartões e ajusta o texto;
  3. «Gerar página»: monta automaticamente **uma página por opção** (título, conceito numa
     frase, abordagem, 6 referências, paleta, técnica e complexidade) com o modelo visual da
     topper.; sem paginação manual.
- **Enviar para a Orçamentação:** a página da faixa A liga-se à Opção A e a da faixa B à Opção
  B (§19.3); uma faixa pode ser marcada **«Serve as duas»**. As páginas entram no PDF da
  proposta e na página de resposta do cliente, ao lado de cada opção.
- Na etapa Proposta, o pré-criativo é **recomendado, não obrigatório** (configurável por
  funil nas Definições) para não travar orçamentos rápidos.
- Negócio **perdido/arquivado** → pré-criativo arquivado com ele (reaberto se o negócio reabrir).
  Negócio em **Kickoff** → vira a base do quadro do projeto (23.2).

### 23.4 Portal do cliente (feedback sem conta)
- Um **link por negócio** (token longo, revogável, com validade; abrir não altera nada — POST
  para ações, §19.4). O cliente identifica-se com nome e email na primeira ação.
- Mostra só o que a topper. partilha: proposta e pré-criativo (fase comercial); quadros
  partilhados (tratamento, rotas) e **Review** (versões, comentários no frame, Aprovar / Pedir
  alterações) na produção. Nunca dados internos (custos, notas, tarefas).
- Comentários do cliente aparecem marcados «Cliente»; um envio consolidado de comentários =
  uma ronda. Aprovar pelo portal pede confirmação com nome escrito e fica registado.
- O responsável comercial e o de execução são notificados de cada ação do cliente.

### 23.5 IA (qualidade premium)
- Análise por referência (uma vez, guardada): legenda, estilo, técnica, ambiente, luz,
  composição, movimento de câmara, cor, **«porque funciona»**; vocabulário controlado da
  topper. + etiquetas livres; confiança e edição humana.
- Vídeo: 6–12 frames-chave por deteção de corte (ffmpeg/PySceneDetect) + transcrição; análise
  plano a plano; frames pesquisáveis individualmente.
- Paleta: extração determinística (k-means em Lab), não por IA; pesquisa por cor com ΔE.
- Ações «/»: Analisar · Ângulos para este projeto · Referências para o brief · Mais como este ·
  Arrumar o quadro · Quadro → tratamento (documento/PDF) · Storyboard → lista de planos (→ tarefas).
- **Modelos: a melhor qualidade disponível**, porque o serviço é premium: o modelo Claude mais
  capaz para ângulos, tratamentos e análise; **embeddings multimodais de topo por API paga**
  (não modelo local) para «mais como este» e referências para o brief. Chaves só no servidor;
  uso e custo visíveis em Definições › Integrações.

### 23.6 Fluidez
Colar/arrastar qualquer coisa (imagem/ficheiro → cartão; link → pré-visualização; texto →
nota; código de cor → amostra). Atalhos (N nota, C coluna, ⌘K, «/» IA, espaço para mover;
documentados em Conhecimento › Navegação). Miniaturas por nível de zoom, carregamento só do
visível, vídeo só reproduz ao passar o cursor. 60 fps a mover e fazer zoom; funciona no iPad
(arrastar com o dedo).

### 23.7 Importação
Sem importar nada agora, mas criar o **mecanismo de importação**: aceitar um ZIP/PDF/Markdown
(formatos de exportação do Milanote) ou pasta de imagens → cria um quadro com os cartões em
grelha, notas como cartões de nota, e envia as imagens para a biblioteca (com análise da IA).

### 23.8 Fases
1. Quadros, Biblioteca, Caixa de entrada, IA de análise e ângulos, **pré-criativo**.
2. Review com comentários no frame e **portal do cliente**.
3. Edição em tempo real por várias pessoas e extensão do browser.

---

## 24. Centro Criativo — Fase 2: Review e Portal do cliente (23 out 2026)

Desenvolve a §23.1 (Review) e a §23.4 (Portal). Fase 1 já está no main. Aplica a §11.

### 24.1 Versões
- Uma **peça de review** pertence a um negócio (e a uma frente, §22) e a uma etapa de
  aprovação (styleframes, animatic, offline, picture lock, finais, lote…).
- Carregar ficheiro (vídeo, imagem, PDF) → versão **v001, v002…**; nunca se apaga uma versão.
- Vídeo: gerar no servidor um **proxy H.264** para o browser, **poster**, **tira de miniaturas**
  e ler o **fps e o timecode de início**; o original fica no Drive (`<ID>_<NOME>/Review/`).
- Estados da peça: Em preparação · Enviada ao cliente · Com comentários · Alterações pedidas ·
  **Aprovada**.

### 24.2 Leitor e comentários no frame exato
- Leitor com **precisão ao frame**: avançar/recuar 1 frame (← →), J/K/L, velocidade, repetir
  um intervalo, timecode SMPTE, tira de miniaturas.
- Comentário preso a um **frame** (ou a um **intervalo**), com **desenho por cima** (seta,
  retângulo, círculo, mão livre, texto) guardado como vetor; marcadores coloridos na barra do tempo.
- Imagens e PDF: **pinos** no ponto clicado, com desenho.
- Respostas em fio, @menções, anexos, **Resolver/Reabrir**.
- Comentários **Internos** (só a equipa) ou **Para o cliente**; os internos nunca chegam ao portal.
- Comparar versões: **lado a lado**, **cortina** (wipe) e **sobreposição**, sincronizadas.
- Exportar comentários: PDF/CSV e **marcadores para Premiere/DaVinci/Avid** (EDL/XML).

### 24.3 Rondas e aprovação
- **Enviar ao cliente** abre uma ronda; o cliente envia os comentários **de uma vez** (botão
  «Enviar comentários»): um envio consolidado = **uma ronda** (§19.6 D).
- Contador «Ronda X de Y incluídas» por etapa (Y vem da proposta/Definições). Ao **passar do
  incluído**: aviso ao Comercial e sugestão de **pedido de alteração** (§19.6 D).
- **Aprovar** (equipa ou cliente pelo portal) → peça Aprovada, **conclui a tarefa de aprovação
  correspondente no Pipeline** (mapa etapa de review ↔ tarefa em Definições › Centro Criativo)
  e os frames/versão aprovados entram na Biblioteca como referência da casa (§23.1).
- Aprovação do cliente pede **nome escrito** e confirmação; regista data, IP e versão.
- Notificações ao responsável de execução e ao comercial em cada ação do cliente.

### 24.4 Portal do cliente
- **Um link por negócio**, longo e aleatório, **revogável** e com **validade** (por defeito até 60
  dias após a entrega; Definições). Sem conta. Abrir (GET) **nunca altera nada**; ações por POST.
- Primeira ação: o cliente indica **nome e email** (guardado nesse browser); os comentários
  aparecem como «Cliente · Nome». Vários aprovadores do cliente podem usar o mesmo link.
- Separadores visíveis só se a topper. partilhar: **Proposta** (com pré-criativo) · **Quadros**
  partilhados (só leitura: tratamento, rotas) · **Review** (peças enviadas).
- **Nunca** mostra custos, margens, notas, tarefas ou comentários internos.
- Imagem topper., modo claro/escuro, funciona no telemóvel, **PT/EN** (clientes internacionais).
- Ficheiros por **URLs assinados de curta duração**; vídeos de review com **marca de água** (logótipo topper. e
  nome do cliente; opção por peça); **download** só se ativado por peça.
- Sem indexação (noindex), limite de pedidos por IP, registo de acessos (quem/quando).
- Na ficha do negócio: botão «Portal do cliente» — copiar link, revogar, ver acessos.

### 24.5 Definições › Centro Criativo
Validade do link do portal · marca de água por defeito · downloads por defeito · rondas por
etapa (herda §19.6 D) · mapa etapa de review ↔ tarefa de aprovação · idioma do portal por defeito.

## 25. Drive organizada: todos os ficheiros no sítio certo (decidido 23 out 2026)

Tudo o que entra na plataforma ou é gerado por ela fica guardado **uma vez** no Drive, organizado
por negócio quando pertence a um negócio, por origem e por tipo de documento; o mais importante é
que **qualquer ficheiro, seja qual for o formato e a origem, se encontre depressa**.

- **Estrutura:** `Empresa/`, `Leads/`, `Projetos/` (negócios em curso e concluídos — o portfólio fica
  aqui), `Marketing/`, `Biblioteca criativa/`, `Showreel e Portfolio/` e `Arquivo/` (`Negócios/` e
  `Leads/`). Dentro do negócio: `Propostas/`, `Faturação/`, `Criativo/` (`Opção A/`, `Opção B/`,
  `Referências/`), `Marketing/`, `Produção/`, `Entregas/`, `Lead/`. Nomes de ficheiro com o padrão da
  casa (ID, tipo, descrição/versão).
- **Arquivo:** negócio perdido ou lead arquivada → a pasta inteira, com todos os ficheiros ligados, vai
  para `Arquivo/`; reaberto → volta. Lead convertida → a pasta passa para dentro da do negócio (`Lead/`).
- **Encontrar:** «Mostrar no Drive» em cada ficheiro, cartão «Ficheiros» por secção nas fichas, a
  pesquisa e o assistente do Drive; um ficheiro movido à mão volta a encontrar-se.
- **Faturação:** só quem tem acesso ao Financeiro a vê.
- **Fora do Drive (de propósito):** fotografias de perfil, miniaturas e pré-visualizações, cópias de
  segurança da base de dados.
- **Autorizado mexer no Drive** para: as pastas de topo fixas, o aviso ao registo quando algo muda de
  sítio à mão, e a regra da faturação.
- **Fase 2 (decidido 23 out 2026):** «Reorganizar o Drive» para o que já existia, com pré-visualização
  do que vai mudar antes de executar e relatório no fim.

---

## 26. Centro Criativo — Fase 3: Tempo real e captura (23 out 2026)

Completa a §23.8 (fase 3). Aplica a §11. Depende das fases 1 (§23) e 2 (§24).

### 26.1 Edição em tempo real
- Várias pessoas no **mesmo quadro ao mesmo tempo**, sem conflitos e sem «recarregar»:
  sincronização por **CRDT (Yjs)**; cada alteração aparece aos outros em < 200 ms.
- **Presença:** avatares de quem está no quadro (no topo), **cursores com nome e cor** da pessoa
  (cor da aba Equipa), seleção dos outros com contorno da cor deles, «está a escrever…» em notas.
- **Seguir** uma pessoa (clicar no avatar → a vista acompanha o que ela vê; útil em reuniões e
  apresentações). Sair com Esc ou ao mexer.
- **Sem ligação:** continua a trabalhar; sincroniza ao voltar a ligação (aviso discreto).
- **Histórico do quadro:** versões automáticas (a cada sessão de trabalho e antes de ações
  grandes, ex.: «Arrumar o quadro» da IA) com «Ver» e «Restaurar»; «Anular» por pessoa.
- Comentários, reações e @menções em tempo real; notificação a quem é mencionado.
- Permissões continuam no servidor: só entra quem tem acesso ao negócio (§9.3); o portal do
  cliente (§24.4) mostra quadros partilhados **só de leitura**, sem cursores da equipa.
- **Servidor de tempo real (decidido):** **PartyServer / y-partyserver** (Yjs) em **Cloudflare
  Workers + Durable Objects com SQLite**, na **conta Cloudflare da topper.** (a mesma do R2).
  Um Durable Object por quadro, com a **API de hibernação** (quadros vazios não gastam).
  Autenticação por token curto assinado pelo PHP (segredo partilhado gerado pelo agente e
  guardado no servidor PHP e como secret do Worker). Endereço `rt.topperhub.xyz` se o domínio
  estiver na Cloudflare; senão `*.workers.dev`.
- **Plano: gratuito** (100 000 pedidos/dia — 20 mensagens WebSocket contam 1 —, 13 000 GB-s/dia,
  5 GB; repõe às 00:00 UTC). Em **Definições › Integrações**: indicador do uso do dia e **aviso aos
  80%** aos admins. Se o limite for atingido, o tempo real pausa até à reposição: o quadro
  continua a funcionar localmente (y-indexeddb) e sincroniza depois, com aviso discreto. Passar
  ao plano pago (Workers Paid, 5 $/mês) é decisão manual dos admins.
- **Publicação:** com um API token Cloudflare («Edit Cloudflare Workers») e o Account ID nas
  **variáveis secretas do ambiente** (`CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ACCOUNT_ID`) — nunca no
  código nem no chat; em alternativa, o agente entrega um único comando `wrangler deploy` para o
  utilizador correr.
- Reconexão automática do cliente; persistência local no browser; nada de imagens pelo WebSocket
  (vão para o R2).

### 26.2 Extensão do browser «Guardar na topper.»
- Chrome/Edge (e Brave/Arc) primeiro; Firefox a seguir; Safari mais tarde.
- **Clique direito** numa imagem, vídeo, link ou texto selecionado → «Guardar na topper.».
  **Botão da extensão:** guardar a página inteira, **captura de uma área do ecrã** ou do
  frame atual de um vídeo (YouTube/Vimeo/Instagram) com o link e o tempo.
- Escolher destino: **Caixa de entrada** (por defeito) ou um quadro recente/pesquisado;
  nota opcional; etiquetas sugeridas pela IA depois.
- Guarda o URL de origem e o autor/créditos quando a página os indicar.
- **Autenticação:** token pessoal gerado em **Perfil › Ligações** (revogável); a extensão
  nunca guarda a palavra-passe.
- Atalho de teclado configurável (ex.: Alt+Shift+S).

### 26.3 Captura no telemóvel e tablet
- **Instalar a plataforma como app** (PWA) no iPhone/iPad/Android.
- Android: aparece no menu **Partilhar** do sistema (Web Share Target) → envia imagem, vídeo ou
  link para a Caixa de entrada.
- iPhone/iPad: **Atalho do iOS** «Guardar na topper.» (fornecido pela plataforma em Perfil ›
  Ligações) no menu Partilhar, usando o mesmo token pessoal.
- Tudo o que chega passa pela análise da IA (§23.5) e fica na Caixa de entrada para triagem.

### 26.4 Definições
Centro Criativo: frequência das versões automáticas do quadro, número de versões guardadas,
destino por defeito das capturas. Integrações: serviço de tempo real (estado e custo) e
gestão dos tokens pessoais (admins podem revogar os de qualquer pessoa).

## 27. Centro Criativo — Áudio (decidido 23 out 2026)

**Estado atual (verificado no main):** o Centro Criativo não reconhece áudio. `cc_kind_for` só distingue imagem e vídeo; um MP3/WAV vira cartão «Ficheiro» sem leitor, sem análise e sem Review. A Drive já trata áudio (tipos, ícone, filtro, leitor em `assets/drive/preview.js`, partilha) — reaproveitar.

### 27.1 Tipo de cartão «Áudio»
- Novo tipo `audio` em `CC_CARD_TYPES`; `cc_kind_for` passa a devolver `audio` para `audio/*` e extensões mp3, wav, m4a, aac, flac, ogg, aif, aiff.
- Os cartões «Ficheiro» antigos que sejam áudio são convertidos automaticamente (migração única).
- Cartão: nome, duração, forma de onda (gerada no browser ao carregar e guardada como lista de picos, para não recalcular), play/pausa inline, barra de progresso clicável. Só um áudio toca de cada vez no quadro.
- Na vista aberta: leitor grande, velocidade (0,5×–2×), repetição de um troço (A–B), volume.

### 27.2 Biblioteca
- Filtro «Áudio» ao lado de Imagem/Vídeo/Link/Ficheiro.
- Categoria do áudio: Música · Voz-off · Efeito sonoro (SFX) · Ambiente · Outro (sugerida pela IA, editável).
- Pesquisa inclui a transcrição e os campos da análise.

### 27.3 Review e Portal do cliente
- Áudio pode ir a Review como o vídeo: comentários marcados no tempo sobre a forma de onda (marcadores clicáveis que saltam para o segundo), versões V1/V2 com comparação A/B (alternar entre versões no mesmo ponto), aprovação/pedido de alterações.
- Mesmas regras de rondas, permissões e notificações da §24.

### 27.4 IA
- Voz-off/fala: transcrição com marcas de tempo, pesquisável e copiável; a transcrição alimenta comentários («na frase X»).
- Música: ambiente/emoção, estilo, instrumentos, ritmo (BPM estimado), energia, adequação sugerida (ex.: «reveal de produto»). Guarda no campo `analysis` como as outras referências.
- A análise corre ao pedido ou automaticamente conforme a definição já existente para vídeo; respeita o limite de custo da IA.
- Nota de execução: a transcrição exige um serviço de reconhecimento de fala (o Claude não recebe áudio diretamente) — usar o mesmo caminho que o vídeo já usa para `transcript`, se existir; caso contrário escolher um serviço e configurá-lo em Definições › Integrações.

### 27.5 Captura e importação
- Importação por link (SoundCloud, bibliotecas de música, link direto de ficheiro) cria cartão de áudio com título, autor e fonte; quando não houver ficheiro descarregável, fica como link com leitor embutido do serviço.
- Arrastar da Drive para o quadro mantém o tipo áudio.

### 27.6 Limites
- Tamanho máximo por ficheiro igual ao do vídeo; formatos fora da lista ficam «Ficheiro».
- Interface sem referências «§» (§21).

## 28. Financeiro (decidido 23 out 2026)

**Ponto de partida (verificado no main):** `admin/financeiro.php` já tem os separadores Negócios · Por receber · Fluxo de caixa · Custos · IVA, a tabela `company_costs` (custos da empresa por ano/mês com IVA e «pago») e gráficos em `inc/charts.php`. A §28 alarga isto; não se perde nada do que existe (migração dos dados atuais).

**Acesso:** só superadmin, admins (sócios) e quem tem a permissão «Financeiro». Verificado no servidor em todas as rotas, ficheiros e exportações. O comercial continua a ver só o estado «pago / por receber» do seu negócio (já existe na ficha). O colaborador vê os seus próprios recibos de vencimento em Perfil.

**Princípio legal:** a plataforma **não é software de faturação certificado**. As faturas e recibos continuam a ser emitidos no programa certificado (por escolher) e são *registados* aqui com o PDF. Todas as taxas e limites fiscais ficam como **parâmetros editáveis** em Definições › Financeiro, cada um com a fonte legal, o ano e a data da última verificação; o contabilista certificado valida-os no início de cada ano. Nunca valores fixos no código.

### 28.1 Separadores
Visão geral · A receber (faturas emitidas) · A pagar (faturas recebidas) · Custos fixos · Salários · Tesouraria · Simulador · Impostos (IVA e calendário fiscal). Os separadores atuais «Negócios» e «Fluxo de caixa» passam para dentro de Visão geral e Tesouraria.

### 28.2 Movimentos: interno vs. negócio
Todo o documento (fatura, recibo, nota de crédito, despesa) tem obrigatoriamente:
- **Sentido:** Receita (emitimos) ou Despesa (recebemos).
- **Âmbito:** «Empresa (interno)» ou «Negócio» — neste caso escolhe-se o negócio (código + nome) e, se houver crosselling (§22), a frente. **Pagamentos a fornecedores e freelancers não podem ser guardados sem âmbito**; se forem de um negócio, o negócio é obrigatório.
- **Contraparte:** contacto/empresa (§13) ou fornecedor/freelancer; NIF.
- Categoria (lista editável: Software, Equipamento, Renda, Telecomunicações, Contabilidade, Seguros, Viaturas, Deslocações, Freelancers, Fornecedores de produção, Marketing, Formação, Impostos, Salários, Outros).
- Valores: base, taxa de IVA, IVA, total, retenção na fonte (se houver), moeda.
- Datas: emissão, vencimento, pagamento.
- Ficheiros: PDF do documento + comprovativo de pagamento + recibo — guardados na Drive (§25) em `Financeiro/AAAA/MM/` e, se for de negócio, também visíveis na pasta do negócio.
As despesas ligadas a um negócio entram automaticamente nos custos reais desse negócio (margem e gross profit da ficha e da Orçamentação).

### 28.3 A receber (faturas emitidas)
Estados: **Emitida → Parcialmente paga → Paga → Recibo arquivado**, e «Anulada» (com nota de crédito).
- Lançar: carregar o PDF da fatura; a IA lê nº, data, NIF, base, IVA, total e vencimento e preenche (o utilizador confirma). É o mesmo PDF que conclui a tarefa «Emitir fatura» do pipeline (§19.6 E) — um só lançamento.
- **Só passa a «Paga» com comprovativo de pagamento submetido** (PDF/imagem do comprovativo ou movimento bancário conciliado, §28.7). Pagamentos parciais acumulam até ao total (ex.: 50/50, 40/40/20).
- Antiguidade: em dia · 1–30 · 31–60 · 61–90 · +90 dias, com cores e alerta no menu (já existe o alerta de atraso).
- Lembrete ao cliente: rascunho de email gerado pela IA (cordial → firme conforme o atraso), enviado só depois de aprovado.
- Faturas de clientes estrangeiros sem IVA (autoliquidação/exportação) com a menção indicada.

### 28.4 A pagar (faturas recebidas)
Estados: **Por pagar → Paga → Recibo arquivado**, «Em disputa».
- Lançar arrastando o PDF (ou por email reencaminhado para um endereço próprio, sugestão para fase 2); a IA extrai os dados, sugere categoria e âmbito pelo NIF e histórico; deteta **duplicados** (mesmo NIF + nº).
- Pagar: marcar paga exige comprovativo (ou conciliação). Lista «A pagar esta semana» e «Vencidas».
- Freelancers (recibo verde): calcula a **retenção na fonte de IRS** a fazer (taxa parâmetro, hoje 23% para atividades do art. 151.º; 0% se o freelancer tiver dispensa — campo na ficha do fornecedor) e o IVA (23% ou isento art. 53.º). Mostra «pagar ao freelancer» e «entregar ao Estado».
- Rendas pagas a senhorio com contabilidade organizada / pessoa coletiva: retenção de 25% (parâmetro).

### 28.5 Custos fixos operacionais
Registo de todos os custos recorrentes: nome, categoria, fornecedor, valor, IVA (dedutível ou não), periodicidade (mensal, trimestral, anual — convertida para valor mensal), data de início e fim, método de pagamento, âmbito (sempre interno).
- Gera automaticamente o lançamento «Por pagar» em cada vencimento (com PDF a juntar).
- Salários (custo total da empresa, §28.6) entram automaticamente como custo fixo.
- Total mensal de custos fixos = base do runway (§28.8). Mostra alterações de mês para mês («+120 €/mês desde março: novo software»).

### 28.6 Salários (processamento)
Ficha de colaborador (Equipa §17) passa a ter, só visível a quem tem Financeiro: vencimento base bruto, subsídio de refeição (valor/dia, dinheiro ou cartão), situação para IRS (não casado / casado único titular / casado dois titulares), nº de dependentes, deficiência, residência (Continente/Açores/Madeira), duodécimos sim/não, IBAN, NIF, NISS, tipo de contrato, data de entrada.

**Emitir pagamento:** escolher colaborador(es) e mês → «Emitir». O sistema calcula e mostra:
| | Colaborador | Empresa |
|---|---|---|
| Bruto | vencimento + subsídios do mês | |
| Segurança Social | −11% | +23,75% (TSU) |
| IRS retido | −(R × taxa − parcela a abater − parcela por dependente × nº) | |
| Subsídio de refeição | isento até 6,15 €/dia (dinheiro) ou 10,46 €/dia (cartão); excesso tributado | custo |
| Seguro de acidentes de trabalho | | % da apólice (parâmetro, obrigatório) |
| **Total** | **Líquido a receber** | **Custo total para a empresa** |
- IRS: tabelas de retenção do Continente do **Despacho n.º 233-A/2026** (em vigor desde 1 jan 2026) guardadas como dados (escalões, taxa, parcela a abater, parcela por dependente); importadas de novo a cada ano e quando houver tabelas novas a meio do ano. Subsídios de férias e de Natal retidos **autonomamente** (como manda a lei), ou em duodécimos se escolhido.
- FCT/FGCT: contribuições suspensas — parâmetro a 0% (pode voltar).
- Avisos: vencimento abaixo do salário mínimo (920 € em 2026, parâmetro); dados em falta (NIF, NISS, IBAN).
- Saídas: **recibo de vencimento PDF** (enviado ao colaborador e no Perfil dele); lançamentos «A pagar»: líquido ao colaborador; IRS retido ao Estado (DMR até dia 10 e pagamento até dia 20 do mês seguinte); Segurança Social 34,75% (declaração até dia 10, pagamento entre 10 e 20 do mês seguinte). Ficheiro para o contabilista (CSV/PDF resumo mensal).
- Custo anual mostrado na ficha: 14 meses × (bruto × 1,2375) + refeição + seguro.

### 28.7 Tesouraria
- Contas: bancos, cartões, caixa — criadas e nomeadas na plataforma (começa com uma, «Conta principal»); saldo atual atualizado por **importação do extrato (CSV/OFX)** ou manualmente (valor e data).
- **Atualização semanal (decidido 24 out 2026):** uma vez por semana, no dia escolhido em Definições › Financeiro (editável), o responsável pelos saldos recebe a tarefa de atualizar o saldo de cada conta. O responsável geral é nomeado por superadmin ou admin; cada conta pode ter um responsável próprio, que fica com as tarefas dessa conta. Cada atualização fica registada (valor, data, quem); saldos com mais de 7 dias aparecem como desatualizados.
- **Conciliação:** cada movimento do extrato é emparelhado com uma fatura/custo/salário (sugestão automática por valor, data e NIF; o utilizador confirma). Conciliar conta como comprovativo de pagamento.
- Saldo disponível = soma das contas − compromissos já conhecidos até ao fim do mês (IVA a entregar, IRS e SS dos salários, faturas a pagar vencidas) − reservas dos sócios por levantar (§28.15).
- Previsão de tesouraria 6/12 meses: saldo + recebimentos esperados (faturas emitidas por vencimento, prestações dos negócios ganhos) − custos fixos − pagamentos previstos.

### 28.8 Runway
- **Reserva de emergência (capital morto)** = N meses × custos fixos mensais. N = **6 por defeito**, editável só por superadmin/admins (Definições › Financeiro, com registo de alterações).
- Runway total = saldo disponível ÷ custos fixos mensais.
- **Runway útil = runway total − N** (ex.: 24 meses de saldo → 18 meses úteis). É o número principal da aba.
- Duas leituras lado a lado: **Conservador** (sem receita, só custos fixos) e **Com tendência** (custos fixos − receita média recebida dos últimos 6 meses ajustada pela tendência). Se a receita cobre os custos: «Sustentável — o saldo cresce X €/mês».
- Alerta aos sócios quando o runway útil desce abaixo de 6 meses (limiar editável) e quando se entra na reserva.

### 28.9 Tendência de crescimento
- Receita recebida e faturada por mês; média móvel de 3 e 6 meses; crescimento médio mensal (composto) dos últimos 6 e 12 meses; projeção com três cenários (pessimista = metade da tendência, realista, otimista = tendência + 50%), parâmetros editáveis.
- A projeção usa também o pipeline: negócios ganhos ainda por faturar (certos) e propostas abertas × probabilidade da etapa (§19) (prováveis), mostrados à parte.

### 28.10 Simulador de decisões
Uma ferramenta «E se…?»: escolhe-se o tipo, preenchem-se poucos campos, e mostra o impacto em **custo mensal real, custo anual, runway útil antes → depois, mês em que se entra na reserva** e **receita extra necessária para manter o runway** (custo ÷ margem média dos negócios). Cenários guardáveis e comparáveis lado a lado; podem ser «aplicados» (viram custo fixo/colaborador previsto).
1. **Contratar:** salário bruto, subsídio de refeição, data de início → custo total empresa (14 meses, TSU 23,75%, seguro, refeição), mais equipamento inicial opcional. Mostra também o líquido que a pessoa recebe.
2. **Arrendar escritório:** renda, caução (meses), despesas fixas (eletricidade, água, internet, condomínio, limpeza, seguro), obras/mobiliário iniciais. Regras: renda é gasto dedutível em IRC; arrendamento normalmente isento de IVA (sem IVA a deduzir) salvo renúncia do senhorio; retenção de 25% quando aplicável (§28.4).
3. **Investir num serviço/software/equipamento:** valor único ou mensal, IVA dedutível, duração; equipamento amortizado (taxa por tipo, parâmetro).
4. **Comprar viatura:** tipo (ligeiro de passageiros / comercial), motorização (elétrico, híbrido plug-in, híbrido normal, gasolina, gasóleo, GPL/GNV), preço sem IVA, compra a pronto / crédito / renting (AOV), km/ano, consumo, seguro, manutenção. Calcula:
   - **IVA dedutível na compra** (ligeiros de passageiros): elétrico até 62.500 € — 100%; plug-in até 50.000 € — 100%; GPL/GNV até 37.500 € — 50%; gasolina, gasóleo e híbrido normal — 0%. Acima do limite, não dedutível. Comerciais: regra própria (parâmetro).
   - **IVA dos consumos:** eletricidade 100% (elétrico/plug-in), gasóleo/GPL/GNV 50%, gasolina 0% (parâmetros).
   - **Depreciação fiscal:** 25%/ano; só aceite até ao limite fiscal (combustão 25.000 €, plug-in 50.000 €, elétrico 62.500 €, GPL/GNV 37.500 €, Portaria 467/2010 — parâmetros).
   - **Tributação autónoma** sobre todos os encargos da viatura (depreciação, renting, seguro, manutenção, combustível, portagens): gasolina/gasóleo/híbrido normal: 8% (< 37.500 €), 25% (37.500–45.000 €), 32% (≥ 45.000 €); plug-in: 2,5% / 7,5% / 15% (se cumprir os requisitos: Euro 6e-bis até 80 g CO₂/km, ou Euro 6 anterior com ≥ 50 km de autonomia elétrica e < 50 g CO₂/km); elétrico: 0% até 62.500 €, 10% acima.
   - Resultado: custo real mensal e a 4 anos de cada opção, com comparação lado a lado (ex.: elétrico vs gasóleo) e poupança de IRC.
- **Efeito do IRC** em todos os cenários (só quando a empresa tem lucro): gasto dedutível poupa IRC à taxa marginal — 15% nos primeiros 50.000 € de matéria coletável (PME) e 19% acima (2026), + derrama municipal (parâmetro, Lisboa até 1,5%).
- Aviso fixo no simulador: «Estimativa com as regras de [ano]. Confirma com o contabilista antes de decidir.»

### 28.11 Impostos
- IVA: apuramento por período (mensal ou trimestral, parâmetro) a partir dos lançamentos: liquidado − dedutível = a entregar/recuperar (já existe; passa a usar as faturas reais).
- **Calendário fiscal** com alertas: DMR e Segurança Social (dia 10/20), declaração periódica de IVA, pagamentos por conta e Modelo 22 de IRC, IES, Modelo 10 — datas como parâmetros. Cada obrigação gera tarefa ao responsável financeiro.
- Exportação para o contabilista: ZIP mensal com PDFs + CSV de lançamentos.

### 28.12 Gráficos (Visão geral)
Seguem a linha de `inc/charts.php` e da §11 (claro/escuro, degradês suaves, animação de entrada, dica ao passar o rato com valores exatos):
1. **Runway** — barra horizontal: meses úteis (cor da marca) + reserva de N meses (tracejado), com o número grande «18 meses úteis» e a data estimada.
2. **Fluxo de caixa 12 meses** — barras de entradas vs saídas por mês + linha do saldo; os meses futuros a tracejado (previsão).
3. **Por receber por antiguidade** — barra empilhada (em dia · 1–30 · 31–60 · 61–90 · +90), clicável para filtrar.
4. **Custos fixos por categoria** — barras horizontais ordenadas (não gráfico circular).
5. **Receita com tendência** — barras mensais + média móvel e projeção com banda pessimista/otimista.
Quatro números no topo: Saldo disponível · Por receber (em atraso a vermelho) · A pagar 30 dias · Runway útil.

### 28.13 Sugestões incluídas
- Conciliação bancária por extrato (elimina «marcar pago» à mão).
- IA a ler PDFs de faturas e a sugerir categoria/âmbito.
- Lembretes de cobrança aprovados antes de enviar.
- Integração futura com o programa de faturação certificado escolhido (InvoiceXpress, Moloni, TOConline, etc.) para emitir a fatura a partir do negócio e importar automaticamente — fase 2.
- Interface sem referências «§» (§21); «Anular» em todas as ações (§11).

### 28.14 Faturas pelo contabilista (enquanto não há programa de faturação)
Hoje a topper. não tem programa de faturação: envia os dados ao contabilista e ele devolve a fatura por email. O sistema automatiza o circuito:
1. **Pedir fatura** — botão no negócio, em «A receber» e na tarefa «Emitir fatura» (§19.6 E). Abre um rascunho já preenchido: cliente (nome, NIF, morada, email), descrição do serviço, base, taxa de IVA ou menção de isenção/autoliquidação, total, prestação (ex.: «1.ª de 2 — 50%»), vencimento, referência do negócio. O utilizador revê e envia. O email sai do email de quem envia ou do email geral, com o nome dessa pessoa (SMTP Hostinger), para o contabilista (email em Definições › Financeiro), com o **código de referência no assunto**, ex.: `[topper FAT-2026-0142] Pedido de fatura — NEG-0123`. Fica um lançamento «Fatura pedida» (estado novo, antes de «Emitida»).
2. **Receber a fatura** (revisto 24 out 2026) — a plataforma liga-se às **caixas de email existentes** da topper. no Hostinger (ex.: `info@topperdigital.xyz` e outras que o admin escolher em Definições › Integrações › Email, cada uma com a sua palavra-passe só no servidor; não é preciso criar uma caixa nova). Leitura por IMAP (`imap.hostinger.com`, 993, SSL) a cada 5 minutos pelo cron. Regras para caixas partilhadas:
   - **Só leitura:** não marca como lido, não move, não apaga nada; guarda o UID da última mensagem vista por caixa.
   - **Filtro barato primeiro, IA depois:** só analisa mensagens com anexo PDF/XML/imagem; o resto é ignorado e nunca guardado. Sinal forte: as faturas portuguesas têm **QR code fiscal e ATCUD** — o sistema lê o QR do PDF (NIF do emitente e do adquirente, data, total, IVA, ATCUD) sem custo de IA. Só os anexos sem QR claro vão à IA para classificar (fatura, fatura-recibo, recibo, nota de crédito, orçamento, outro).
   - NIF adquirente = topper. → **despesa** («A pagar», por confirmar); emitente = topper. → **receita** («A receber», ligada ao pedido). Outros documentos são ignorados.
   - Privacidade: o corpo dos emails não é guardado; só o anexo classificado como documento financeiro e remetente/assunto/data. Lista de remetentes a ignorar editável.
3. **Ligar ao pedido** — por esta ordem: código de referência no assunto/corpo; se faltar, a IA lê o PDF (NIF do cliente, total, data) e procura o pedido aberto que corresponde. Se houver dúvida, fica em «Por confirmar» com as opções sugeridas — nunca liga sozinho com baixa confiança.
4. **Registar** — o PDF vai para a Drive (Financeiro e pasta do negócio), a IA preenche nº da fatura, data, base, IVA e total e **compara com o pedido** (avisa se o valor ou o NIF não batem). O lançamento passa a «Emitida» e a tarefa «Emitir fatura» conclui com esse PDF.
5. **Avisar** — notificação ao responsável e ao comercial do negócio: «A fatura FT 2026/142 do NEG-0123 está pronta para enviar ao cliente».
6. **Enviar ao cliente** — botão que abre um email pronto (PDF anexado, dados de pagamento/IBAN, vencimento), revisto e enviado da caixa da topper.; fica registado no histórico do negócio e do contacto. A partir daqui segue o circuito normal (comprovativo → «Paga»).
- O mesmo leitor da caixa `faturas@` aceita **faturas de fornecedores** reencaminhadas para lá: entram em «A pagar» para confirmar (§28.4).
- Também para **recibos**: quando o pagamento é confirmado, botão «Pedir recibo» ao contabilista com o mesmo circuito.
- Credenciais da caixa (palavra-passe IMAP/SMTP) guardadas só no servidor, num ficheiro de configuração fora do repositório (como `cc_config.php`), nunca no código, na base de dados em claro, nem no chat. Definições › Integrações › Email mostra o estado da ligação («última leitura há 3 min») e um botão «Testar».
- Leitura IMAP em PHP com uma biblioteca pura (ex.: webklex/php-imap) para não depender da extensão `imap` do PHP (removida do núcleo a partir do PHP 8.4).
- Mensagens lidas são movidas para uma pasta «Processadas» na caixa; nada é apagado.
- Quando houver programa de faturação certificado, este circuito é substituído pela integração direta (§28.13), mantendo os mesmos estados.

### 28.15 Reservas dos sócios (decidido 24 out 2026)
Em certos serviços reserva-se uma parte da faturação como «salário» para a equipa de execução (hoje, os sócios). Esse capital pertence ao sócio e não conta como saldo real da empresa.
- Superadmin ou admin cria a reserva num negócio: sócio + **% do que o cliente pagar** (sem IVA) ou **valor fixo**. É automática: acumula à medida que o cliente paga (o valor fixo na proporção do que foi pago).
- Conta como **custo do negócio** e entra na margem de lucro.
- O que está reservado e ainda não foi levantado **sai do saldo disponível e do runway** e aparece à parte («Reservado aos sócios»).
- Cada levantamento fica registado com **data e comprovativo** (e a conta de onde saiu); conta como saída de caixa.
- Só superadmin e admins veem e mudam o detalhe por sócio; quem tem o Financeiro sem ser admin vê apenas o total reservado.

## 29. Relatórios (decidido 24 out 2026)

**Princípio:** Relatórios = **micro** (onde está o problema, quem, que registo, o que fazer). Dashboard Executivo = **macro** (Revenue, Gross Profit e margem, EBITDA, margem operacional, Net Profit e margem, runway, saúde geral — já interpretados). Os dois leem a **mesma camada de métricas** (§29.11), para os números baterem sempre certo.

**Ponto de partida (verificado no main):** `admin/relatorios.php` já mostra entradas / convertidas / ganhos / perdidos por funil, por origem e por mês. Mantém-se e passa a ser parte do separador «Funil».

**Estado (24 out 2026):** implementado — ver §10.21.

### 29.1 Regras comuns a todos os separadores
- **Período** no topo (Esta semana · Este mês · Trimestre · Ano · Personalizado) com **comparação automática ao período anterior** (▲▼ e %).
- **Filtros globais** que se mantêm entre separadores: funil, origem, pessoa, serviço/frente.
- **Tudo é clicável:** cada número abre a lista dos registos que o compõem (leads, negócios, tarefas, faturas), com ligação direta.
- ⓘ em cada indicador com a fórmula em linguagem simples.
- Exportar CSV/PDF por separador.
- Amostras pequenas sinalizadas («só 4 leads — pouco fiável»).
- Valores em euros só para quem vê o Financeiro (regra que já existe).
- Interface sem referências «§».

### 29.2 Separadores
**Atenção · Metas · Funil comercial · Velocidade · Origens · Atividade · Equipa · Operacional · Financeiro**. Abre em «Atenção».

### 29.3 Atenção (o que é urgente agora)
Lista única ordenada por gravidade e antiguidade, cada linha com dono, há quanto tempo e botão «Abrir»:
- Leads sem primeira resposta fora do prazo (SLA, §29.6).
- Leads e negócios parados numa etapa acima do tempo normal dessa etapa (média histórica × 1,5, ou limite definido no funil).
- Propostas em «Aguardar feedback» há mais de N dias sem seguimento.
- Gates bloqueados, tarefas atrasadas, kickoffs sem data.
- Reviews à espera do cliente há mais de N dias.
- Faturas em atraso e faturas pedidas ao contabilista sem resposta (só Financeiro).
- Metas em risco (§29.4).
Filtro «Só as minhas». O mesmo número aparece no menu lateral como alerta.

### 29.4 Metas
- Definir metas por mês ou trimestre, para a empresa, por funil e por pessoa (só superadmin/admins): leads abordadas, passadas a pipeline, propostas enviadas, kickoffs, valor ganho, e taxas (ex.: 75 de 200 = 37,5%; 10% das que entram em pipeline fecham).
- **Meta em cascata (sugestão):** define-se só o objetivo final (ex.: 8 kickoffs/mês) e o sistema calcula, com as taxas reais dos últimos 6 meses, quantas leads, X-Rays e propostas são precisas. Pode-se ajustar à mão.
- Progresso com **ritmo esperado**: «dia 18 de 30 — 60% do mês; leads abordadas 112/200 (56%) — ligeiramente atrás» e **previsão de fim de período** ao ritmo atual. Estados: Em linha · Em risco · Atrás · Cumprida.
- Histórico de metas cumpridas por mês.

### 29.5 Funil comercial
Etapas: **Lead → Abordada → X-Ray (entrou em pipeline) → Proposta enviada → Aguardar feedback → Kickoff** (+ Perdido em qualquer etapa).
- Por etapa: quantos entraram, % que passou à seguinte, % perdida com **motivos de perda** (obrigatórios ao perder), tempo médio e mediano na etapa.
- Conversão total Lead → Kickoff e valor médio ganho.
- Duas leituras: **Por período** (o que aconteceu neste mês) e **Por coorte** (das leads que entraram em março, quantas já chegaram a cada etapa) — a coorte é a leitura correta de conversão; mostrada por defeito.
- Comparação entre os 6 funis lado a lado.

### 29.5.1 Desempenho financeiro por funil (mantém e alarga o que já existe)
O bloco atual «Valor ganho · por funil» + tabela (entradas, convertidas, conversão, ganhos, perdidos, taxa de ganho, valor ganho) e o bloco «Crosselling» mantêm-se. Acrescenta-se, por funil (euros só com Financeiro):
- **Valor ganho, ticket médio, gross profit e margem %** (custos reais dos lançamentos do Financeiro), **valor faturado vs recebido**.
- **Pipeline aberto** e **pipeline ponderado** (valor × probabilidade da etapa) — o que cada funil deve trazer a seguir.
- **Ciclo médio** (entrada no funil → kickoff) e **valor ganho por mês de ciclo**.
- Gráfico: barras horizontais de valor ganho com a margem sobreposta (gross profit a cor forte, resto a cor clara), ordenadas por gross profit — o funil que mais **lucra** nem sempre é o que mais **fatura**.
Correções de leitura detetadas no ecrã atual (24 out 2026):
- «Ainda lead (sem funil)» (112) entra no total e baixa a conversão para 6%, enquanto cada funil mostra 100% porque só conta registos que já são negócio. Separar: a conversão lead → negócio lê-se no funil comercial (§29.5); a tabela por funil mede **a partir da entrada em pipeline** (X-Ray → Kickoff), e as leads sem funil aparecem numa linha à parte fora do total.
- «Taxa de ganho 100% · 0 perdidos»: indica que os negócios perdidos não estão a ser marcados. Com o motivo de perda obrigatório (§29.11) e o aviso de negócios parados (§29.3) a taxa passa a ser real; até lá mostra «sem perdas registadas» em vez de 100%.

### 29.6 Velocidade
- **Tempo até à primeira resposta:** do registo da lead (manual ou automática) até à primeira interação de saída registada (chamada, email, WhatsApp, reunião). Mediana e p90, por canal/origem, por pessoa, contado em **horas úteis** (horário de trabalho em Definições).
- **% dentro do SLA** (ex.: 1 h útil; editável por canal). Leads de formulários e anúncios com SLA mais curto.
- Relação velocidade × conversão («leads respondidas em < 1 h convertem 2,3× mais») — calculada com os dados reais.
- Duração do ciclo Lead → Kickoff e tempo por etapa (tendência mensal).

### 29.7 Origens (qualidade das fontes)
Origem estruturada em: **canal** (formulário do site, anúncio, parceiro, indicação de cliente, redes sociais, evento, prospeção ativa, manual) + **fonte** (qual parceiro, qual campanha, qual formulário) — UTM capturados nos formulários.
Por origem: volume, % para pipeline, % kickoff, valor ganho, ticket médio, margem média (Financeiro), ciclo médio e, quando há custo registado (Marketing/Financeiro), **custo por lead, custo por kickoff e retorno**. Índice de qualidade da fonte (conversão × valor × margem) com ranking. Parceiros: o que cada parceiro trouxe e o valor gerado.

### 29.8 Atividade
Global e por pessoa: chamadas, emails, reuniões, WhatsApp, tarefas criadas / concluídas / atrasadas, propostas enviadas, X-Rays feitos, follow-ups. Semana a semana e mapa dia × hora (quando a equipa trabalha as leads).

### 29.9 Equipa (performance comercial)
Por utilizador: leads atribuídas, tempo de resposta, % lead → pipeline, % pipeline → kickoff, kickoffs, valor ganho, margem média, atividade e metas cumpridas. Normalizado pelo volume recebido (taxas, não só totais) e com aviso de amostra pequena.
Visibilidade: superadmin/admins veem todos; cada operador vê os seus números e a **média da equipa** (sem nomes dos colegas).

### 29.10 Operacional e Financeiro (micro)
- **Operacional:** negócios em execução por etapa, % entregas no prazo, kickoff → entrega (dias), rondas de revisão usadas vs incluídas (excesso), aprovação na 1.ª ronda (Review), tarefas atrasadas por etapa e carga por pessoa.
- **Financeiro (só com acesso):** margem por negócio, funil, serviço e cliente; **orçamentado vs real** por negócio (desvio de custos); top clientes por receita e por margem; prazo médio de recebimento por cliente; faturas em atraso por cliente; custos por categoria vs período anterior. (P&L, EBITDA e Net Profit ficam no Dashboard.)

### 29.11 Base de dados das métricas
- **Histórico de etapas estruturado** (`stage_events`: registo, de, para, quando, por quem, motivo de perda) para leads, negócios e frentes; preenchido daqui em diante e reconstruído do `deal_history` / `contact_history` onde possível.
- `first_response_at` e canal da primeira resposta nas leads.
- Origem: canal + fonte + campanha + parceiro; UTM nos formulários.
- Motivo de perda obrigatório.
- Tabela de metas.
- **Fotografia diária** das métricas principais (cron) para tendências rápidas e para o Dashboard Executivo.
- Uma só biblioteca de cálculo (`inc/metrics.php`) usada por Relatórios, Dashboard e alertas.

### 29.12 Resumo semanal
Segunda às 9h: resumo automático no chat da equipa (e email aos sócios) com metas, o que melhorou/piorou e **«3 coisas a fazer esta semana»** escritas pela IA a partir do separador Atenção. Cada pessoa recebe também o seu.

### 29.13 Gráficos
Linha da §11 e de `inc/charts.php` (claro/escuro, degradês suaves, animação de entrada, dica com valores exatos, clicáveis). Sem gráficos circulares, velocímetros ou 3D.
- **Metas:** barra de progresso com marcador do ritmo esperado (bullet chart) e previsão a tracejado.
- **Funil:** barras horizontais decrescentes por etapa com a % de passagem entre elas e a perda a cinzento; coorte em tabela com mapa de calor.
- **Velocidade:** barras por canal com mediana e p90 + linha da % dentro do SLA ao longo dos meses.
- **Origens:** gráfico de dispersão volume × conversão (tamanho = valor ganho) em quadrantes («escalar», «otimizar», «rever», «cortar») + tabela com barras.
- **Atividade:** barras empilhadas por semana e mapa de calor dia × hora.
- **Equipa:** tabela de ranking com mini-barras e linha da média da equipa.
- **Tendências:** linhas com o período anterior a tracejado; mini-linhas (sparklines) junto aos números.
- **Financeiro:** barras horizontais de margem; cascata orçamentado → real por negócio; barra empilhada por antiguidade.

### 29.14 Ligação ao Dashboard Executivo
O Dashboard (a desenhar) não recalcula nada: lê a fotografia diária e mostra a leitura macro — P&L (Revenue, Gross Profit e margem, EBITDA, margem operacional, Net Profit e margem), runway, pipeline ponderado, metas em semáforo, índice de saúde e um parágrafo da IA «o que mudou e porquê», com ligação ao separador de Relatórios que explica cada número.

## 30. Dashboard Executivo (decidido 24 out 2026)

**Princípio:** leitura **macro** em 30 segundos. Não recalcula nada: lê a camada de métricas e a fotografia diária (`inc/metrics.php`, §29.11) e o Financeiro (§28). Cada número leva ao separador de Relatórios ou Financeiro que o explica.

**Ponto de partida (verificado no main):** `admin/dashboard.php` tem «O teu dia», o resumo do ano (faturação, lucro antes de impostos, gastos, por receber), o bloco Comercial (pipeline ponderado, metas, respostas no prazo, pedem atenção) e «Pedem atenção agora». Mantém-se «O teu dia» no topo; o resto reorganiza-se como abaixo.

### 30.1 Estrutura (de cima para baixo)
1. **Saudação + barra compacta «O teu dia»** (revisto 24 out 2026): o bloco atual de 4 colunas deixa de abrir expandido no Dashboard — tira os holofotes aos KPIs. Passa a uma linha de contadores clicáveis («Tarefas a vencer 23 · Leads em atraso 23 · Negócios parados 0 · Por receber em atraso 5») que abre o detalhe num painel lateral. O detalhe completo continua em Tarefas, Leads e Relatórios › Atenção. Preferência por utilizador «Abrir expandido» (desligada por defeito).
2. **Leitura do mês** — um parágrafo da IA (3–4 frases) com o que mudou e porquê, e a ação mais importante. Ex.: «Outubro fecha com gross profit 18% acima de setembro, puxado por CGI / 3D. A margem caiu 3 p.p. por excesso de rondas em 2 negócios. Runway útil estável em 18 meses. Prioridade: 4 propostas sem feedback (31 500 €).» Gerado de manhã a partir da fotografia; nunca inventa números (só usa os da fotografia).
3. **Saúde da empresa** — 4 semáforos: Finanças · Comercial · Operação · Tesouraria, cada um com uma frase e ligação (regras em §30.4).
4. **Resultados (P&L)** — só Financeiro (§30.2).
5. **Crescimento e previsão** (§30.3).
6. **Hall of fame** — top 3 negócios de sempre por gross profit, recorde a bater e comercial do mês (§30.5).
7. **Comercial** (bloco atual) e **Pedem atenção agora** (resumo, com ligação ao separador Atenção).

### 30.2 Resultados (P&L) — só com acesso ao Financeiro
Período: Mês · Trimestre · Ano até hoje · Últimos 12 meses; comparação com o período anterior e com o mesmo período do ano anterior (▲▼).
Cartões grandes com mini-linha de 12 meses:
- **Revenue** (faturado s/ IVA, pela data da fatura) — e recebido no período em nota.
- **Gross Profit** = Revenue − custos diretos dos negócios (freelancers, fornecedores de produção, despesas com âmbito «Negócio»); **Gross Margin %**.
- **EBITDA** = Gross Profit − custos operacionais da empresa (salários com encargos, renda, software, contabilidade, seguros, marketing… tudo com âmbito «Empresa», exceto amortizações, juros e impostos sobre lucros).
- **Operating Profit (EBIT)** = EBITDA − amortizações e depreciações; **Operating Margin %**.
- **Net Profit** = EBIT − encargos financeiros − IRC estimado (taxas dos parâmetros §28: 15%/19% + derrama) − tributação autónoma; **Net Margin %**. Marcado «estimativa» até o contabilista fechar o ano.
- **Exemplo de leitura (mês):** Revenue 42 300 € − custos diretos 24 950 € = Gross Profit 17 350 € (Gross Margin 41%) − operacionais 8 240 € = EBITDA 9 110 € (21%) − amortizações 600 € = EBIT 8 510 € (Operating Margin 20%) − juros 0 € − IRC e tributação autónoma 1 490 € = Net Profit 7 020 € (Net Margin 17%).
- **Dados necessários para o cálculo automático:** (1) faturas emitidas registadas (Revenue pela data da fatura); (2) todas as despesas com âmbito e categoria; (3) **tipo contabilístico por categoria**; (4) salários processados pela plataforma (§28.6) com encargos; (5) **registo de ativos** (equipamento, viaturas, obras — valor, data, taxa de amortização dos parâmetros) para as amortizações mensais; (6) empréstimos/leasings com juros; (7) parâmetros de IRC, derrama e tributação autónoma (§28). **Salários (decidido 24 out 2026):** o salário de cada pessoa vem da ficha na aba Equipa (custo total para a empresa, §28.6). Por defeito é **custo operacional** (entra no EBITDA/EBIT). Quando uma pessoa está **declarada na Orçamentação de um negócio** (linha de equipa interna com dias/horas), esse tempo é **custo direto** desse negócio e entra no Gross Profit:
  - custo/hora interno = custo total anual da pessoa (14 meses × bruto × 1,2375 + refeição + seguro) ÷ horas produtivas por ano (parâmetro, ex.: 1 600 h); atualizado quando o salário muda;
  - custo direto do negócio = horas declaradas × custo/hora (orçamentado); quando houver horas reais registadas no negócio usam-se essas;
  - **sem dupla contagem:** o valor imputado aos negócios no mês é retirado aos custos operacionais de salários desse mês (operacional = custo salarial total − imputado aos negócios); se a imputação passar o custo do mês, fica limitada ao custo real e é mostrado aviso;
  - o mês da imputação é o mês da execução do negócio (kickoff → entrega, repartido pelos meses);
  - quem não tem Financeiro vê só horas, nunca o custo/hora.
- Cada categoria de custo do Financeiro tem um **tipo contabilístico** (Custo direto · Operacional · Amortização · Financeiro · Imposto) definido em Definições › Financeiro, para estes cálculos serem automáticos.
Gráfico: **cascata do P&L** do período (Revenue → custos diretos → Gross Profit → operacionais → EBITDA → amortizações → EBIT → impostos → Net Profit) e **barras mensais de Revenue com a linha de Gross Margin %** (12 meses).
Outros indicadores macro (pequenos): **Runway útil** (§28.8), **saldo disponível**, **por receber em atraso**, **receita por pessoa** (revenue ÷ equipa), **concentração** (% da receita dos 3 maiores clientes — alerta acima de 50%), **clientes recorrentes** (% da receita de clientes que já tinham comprado).

### 30.3 Crescimento e previsão
- Revenue e Gross Profit dos últimos 12 meses com a tendência (§28.9).
- **Previsão até ao fim do ano** = realizado + ganho por faturar (backlog) + pipeline ponderado + tendência; banda pessimista/otimista. Mostra a distância ao objetivo anual (meta anual em Definições, só sócios).
- **Mix por funil e por serviço** (barra empilhada 100%) — de onde vem o gross profit.
- Sem Financeiro: mostra só contagens (negócios ganhos, kickoffs, pipeline em nº), sem euros.

### 30.4 Saúde da empresa (regras dos semáforos; limites editáveis em Definições)
- **Finanças:** verde se Gross Margin ≥ meta (ex. 40%) e Net Profit > 0; amarelo se um falha; vermelho se ambos.
- **Tesouraria:** verde runway útil ≥ 12 meses e atrasos < 15% do por receber; amarelo 6–12 meses ou atrasos 15–30%; vermelho < 6 meses ou dentro da reserva.
- **Comercial:** metas do período em linha (verde), alguma em risco (amarelo), alguma atrás ou pipeline ponderado < 1,5× a meta do mês seguinte (vermelho).
- **Operação:** entregas no prazo ≥ 85% e rondas a mais ≤ 0,5 (verde); senão amarelo/vermelho.
Cada semáforo diz a razão numa frase e abre o relatório certo.

### 30.5 Hall of fame
- **Top 3 de sempre por gross profit** em pódio (1.º, 2.º, 3.º): código, nome do negócio, cliente, funil, mês, equipa envolvida. Euros só para quem tem Financeiro; os restantes veem o pódio sem valores.
- **Recorde a bater:** cartão «Negócio do mês» com o melhor gross profit do mês corrente e a % do 1.º lugar do mês anterior e do recorde de sempre («a 78% do recorde»). Quando um negócio entra no top 3 ou bate o recorde (ao chegar a Kickoff com custos fechados, ou ao fechar contas na entrega — regra: conta o gross profit **real** depois da entrega; até lá aparece como «provisório»): animação discreta no Dashboard, aviso no chat da equipa e registo no histórico do hall of fame (quem esteve no pódio em cada mês).
- **Comercial do mês:** utilizador com a melhor **conversão pipeline → kickoff** no mês, com mínimo de 5 negócios em pipeline (editável) para contar; desempate por gross profit ganho e depois por tempo de resposta. Mostra foto, taxa, kickoffs e a comparação com a média da equipa. Anunciado no chat no dia 1 do mês seguinte; histórico dos vencedores.
- Sugestão: **Destaque de produção do mês** (melhor % de entregas no prazo e aprovação à 1.ª ronda) para reconhecer também a equipa de execução.

### 30.6 Visibilidade
- Sócios (superadmin/admins) e Financeiro: tudo.
- Restantes: «O teu dia», leitura do mês sem euros, semáforos Comercial e Operação, hall of fame sem valores, comercial do mês, bloco Comercial e atenção. O servidor nunca envia valores financeiros a quem não tem acesso.

### 30.7 Design
Linha da §11 e de `inc/charts.php` (claro/escuro, degradês suaves, animação de entrada, dicas com valores exatos). Cartões de KPI com número grande, variação ▲▼ colorida e mini-linha. Pódio com 1.º lugar em amarelo da marca (#FFC718), 2.º em azul-céu, 3.º em coral. Sem gráficos circulares nem velocímetros. Em telemóvel: leitura do mês, semáforos e os 4 KPIs principais primeiro. Interface sem referências «§».

## 31. Email sem palavras-passe, login rápido e calendário (decidido 24 out 2026)

### 31.1 Entrada de emails pela Cloudflare (substitui a leitura IMAP da §28.14 ponto 2)
- **Circuito:** em cada caixa do Hostinger escolhida (ex.: info@topperdigital.xyz) cria-se um **reencaminhamento com cópia** para `entrada@in.topperhub.xyz`. Esse endereço é recebido pelo **Cloudflare Email Routing** (gratuito), ativo **só no subdomínio `in.topperhub.xyz`** — o domínio `topperhub.xyz` continua com os MX do Hostinger (mx1/mx2.hostinger) e entregue a um **Email Worker** que envia o email à plataforma por HTTPS. Nenhuma palavra-passe de email é guardada.
- **Worker:** novo handler `email()` no Worker existente em `rt/` (ou Worker próprio `mail/`, mesma conta e mesmo deploy por wrangler). Recebe a mensagem, rejeita > 25 MB, e faz POST do email em bruto (MIME) para `https://topperhub.xyz/admin/fin_inbound.php` com **assinatura HMAC-SHA256** (segredo partilhado guardado como secret do Worker e em `inc/fin_mail_config.php` no servidor) e carimbo de tempo (rejeita > 5 min e repetições pelo Message-ID).
- **Plataforma (`fin_inbound.php`):** valida a assinatura; identifica a caixa de origem pelo cabeçalho do reencaminhamento (Delivered-To / X-Forwarded-For / To original); **ignora caixas que não estejam na lista de Definições › Integrações › Email** («Caixas ligadas», com data do último email recebido de cada uma); depois aplica as regras da §28.14: só anexos, QR fiscal/ATCUD primeiro, IA só sem QR claro, receita/despesa pelo NIF, corpo nunca guardado, «Por confirmar» em caso de dúvida, ligação ao pedido pelo código no assunto.
- **Envio** (pedir fatura, enviar ao cliente): mantém-se por SMTP do Hostinger da caixa escolhida **ou**, para não guardar nenhuma palavra-passe, envio por um serviço transacional (ex.: Resend/Brevo com domínio verificado e chave API) com «Responder para» = info@. Escolha em Definições; por defeito o serviço transacional.
- O leitor IMAP atual (`inc/fin_mail.php`) fica como alternativa desligada por defeito («Modo IMAP») para quem quiser; sem configuração, não corre.
- **Configuração (feita pelo admin, com guia passo a passo em Definições):** (1) DNS do topperhub.xyz na Cloudflare; (2) Email Routing só no subdomínio `in.topperhub.xyz` e a regra `entrada@in.topperhub.xyz` → Worker (sem mexer nos MX de topperhub.xyz); (3) `wrangler deploy`; (4) em cada caixa do Hostinger, Reencaminhamento → `entrada@topperhub.xyz` com «manter cópia»; (5) botão «Testar» que mostra o email de teste a chegar.

### 31.2 Login rápido
- **Chaves de acesso (passkeys / WebAuthn):** Face ID, impressão digital ou PIN do dispositivo, sem palavra-passe. Em Perfil › Segurança: «Criar chave de acesso» (várias por pessoa: telemóvel, portátil), lista com nome do dispositivo, último uso e «Remover». No ecrã de entrada o browser propõe a chave automaticamente. Biblioteca PHP de WebAuthn (ex.: lbuchs/WebAuthn); desafios guardados na sessão; contador e chave pública na base de dados.
- **«Entrar com Google»** (OpenID Connect, sem Workspace): só entra quem já tem utilizador na plataforma com o **mesmo email** verificado pelo Google; nunca cria contas sozinho. A conta Google pode ser gratuita criada com o email de trabalho («usar o meu endereço de email atual»). Credenciais OAuth no servidor (`inc/auth_config.php`, fora do Git).
- Palavra-passe atual mantém-se como recurso. Admins podem tornar a chave de acesso obrigatória por função.
- Sessões e permissões exatamente como hoje (o método de entrada não muda o que cada um vê). Registo de entradas (método, dispositivo, data) visível em Perfil.

### 31.3 Calendário Google por pessoa
- Hoje (verificado no main) o Google Meet usa **uma conta da topper.** (`inc/meet_config.php`). Mantém-se para criar as reuniões.
- Novo: cada pessoa pode **ligar o seu Google Calendar** em Perfil › Ligações («Ligar Google Calendar», OAuth com scope de eventos; o token fica cifrado no servidor e pode ser revogado). Funciona com conta Google gratuita.
- Sincroniza num sentido: reuniões, gravações, visitas e prazos com hora da plataforma → calendário da pessoa (criar, mudar, cancelar). Opcional: mostrar na Agenda da plataforma os períodos «ocupado» do Google (só ocupado/livre, sem detalhes) para marcar reuniões sem conflitos.
- Nota na interface: em contas gratuitas, chamadas Meet com 3+ pessoas param aos 60 minutos.

### 31.4 Google Calendar e Google Meet em toda a plataforma (preparação e criação direta)
**Já existe (main):** criar Meet nas reuniões da aba Equipa pela conta da topper. (`inc/meet_config.php`), ativo quando as credenciais estiverem preenchidas.
**Passa a existir:**
- **Uma só camada de calendário** (`inc/gcal.php`): criar/alterar/cancelar evento, criar Meet, ler ocupado/livre, por **conta da topper.** ou **conta da pessoa** (quando ligada, §31.3). Todas as partes da plataforma usam esta camada — nada fala com a API do Google diretamente.
- **«Criar Google Meet» em qualquer evento com hora:** reuniões e gravações/visitas de leads, negócios (incl. kickoff e reuniões de review), tarefas do tipo Reunião, Equipa e Agenda. Um clique cria o evento com link Meet, título e descrição gerados (código do negócio, ordem de trabalhos), convidados = participantes internos + contactos externos escolhidos (o cliente recebe o convite do Google). O link fica guardado no registo, no histórico e no chat.
- **Organizador:** a pessoa que marca, se tiver o calendário ligado; senão a conta da topper. (com a pessoa como convidada).
- **Ligação evento ↔ registo:** tabela `calendar_links` (registo, tipo, id do evento Google, calendário, conta, etag, última sincronização). Mudar a data/hora ou cancelar na plataforma atualiza o Google; nunca cria duplicados.
- **Preparado para sincronização nos dois sentidos (fase 2):** pedir já os scopes `calendar.events` e `calendar.freebusy` na ligação; guardar `syncToken`; endpoint `gcal_push.php` pronto para as notificações push do Google (canal por calendário, renovado pelo cron). Na fase 2: mudanças feitas no Google (hora, cancelamento, respostas dos convidados Sim/Não/Talvez) voltam para a plataforma.
- **Disponibilidade ao marcar:** ao escolher hora, mostra ocupado/livre dos participantes internos com calendário ligado e sugere os 3 próximos horários livres comuns.
- **Respostas dos convidados** visíveis no evento (fase 2 via push; fase 1 lidas ao abrir).
- Tudo opcional: sem credenciais Google, os botões mostram «Ligar Google» (admin) e o link pode ser colado à mão, como hoje.

### 31.5 Sem contas Google nos emails de trabalho (decidido 27 out 2026)
A Google não deixa criar contas gratuitas com os emails @topperdigital.xyz sem Google Workspace (pago, ~16 €/utilizador/mês). Decisão: **não usar Workspace**.
- **Login:** principal = chaves de acesso. «Entrar com Google» passa a aceitar **qualquer conta Google associada** pela própria pessoa em Perfil › Ligações › «Ligar conta Google» (com sessão iniciada; grava `google_sub`; desligar no mesmo sítio; registo no login_log). Sem associação, mantém-se a regra do email igual.
- **Google Meet:** conta da topper. = uma **conta Gmail gratuita criada para a empresa** (ex.: topper.reunioes@gmail.com), ligada pelo botão «Ligar conta da topper.». Convites seguem para os emails de trabalho. Limite do Meet gratuito: 60 min com 3+ pessoas (aviso na interface).
- **Calendário:** cada pessoa pode ligar o Google Calendar de **qualquer** conta Google sua (já suportado). Novo: **link de subscrição iCal privado por pessoa** (Perfil › Ligações › «Subscrever a agenda»; URL com token revogável/regenerável; só leitura) para Calendário do iPhone/Mac, Outlook ou Google — reuniões, gravações, visitas e prazos com hora. Atualização segundo a app de calendário (15 min a algumas horas).
- Ecrã de consentimento OAuth publicado (não modo de teste).
- **Atualização (28 out 2026):** a conta da topper. para o Meet/Calendar e para a Google Cloud Console passa a ser **Google Workspace com info@topperdigital.xyz** (1 licença), comprada diretamente na Google. O email continua no Hostinger: domínio verificado por TXT, **Gmail não ativado e MX nunca alterados**. Reuniões criadas pela plataforma em nome do info@ com **acesso aberto** (quem tem o link entra sem conta Google e sem pedir para entrar); comerciais e clientes recebem o convite nos seus emails — ninguém partilha o login do info@. Coanfitriões opcionais pela conta Google associada no Perfil. geral@ fica para mais tarde.
- **Atualização (29 out 2026) — substitui a de 28 out:** sem Workspace. A conta da topper. para o Meet e para a Google Cloud Console é a **conta Gmail gratuita `geraltopperdigital@gmail.com`**, ligada em Definições › Integrações › Google › «Ligar conta da topper.» (o token de atualização fica em `inc/meet_config.php`). Por ser gratuita: chamadas com 3+ pessoas param aos **60 minutos** e quem entra precisa de **conta Google** (sem conta fica à espera que o anfitrião aceite) — avisos na interface. Os convites seguem para os emails de trabalho.

### 31.6 Reuniões na criação da tarefa (decidido 29 out 2026)
Fluxo pedido: tudo acontece **no próprio formulário da tarefa**, sem gravar primeiro nem abrir outro botão.
1. Tipo **Reunião** → escolha da plataforma: **Google Meet · Discord · Presencial** (já existe a escolha; passa a mudar os campos abaixo).
2. **Google Meet** → campo **«Convidar»** (etiquetas de email):
   - **Sugestões automáticas** quando a tarefa nasce numa lead, negócio ou contacto: emails da ficha de contacto associada (pessoa e empresa, todos os emails registados), outros contactos do mesmo negócio/empresa e os participantes internos da tarefa. Um clique acrescenta; também se escreve qualquer email (validado).
   - Participantes internos (utilizadores da plataforma) são convidados por defeito, com o email de trabalho.
   - Ao **guardar a tarefa**, o Meet é criado e os convites enviados de imediato; o link aparece no formulário, na tarefa, no histórico da lead/negócio e no chat.
   - **Organizador por defeito: a conta da topper. (geraltopperdigital@gmail.com).** Opção secundária «Enviar do meu Google Calendar» só para quem tem o calendário ligado. O evento aparece também (em espelho, sem convite duplicado) no calendário de quem tem o Google ligado.
   - Editar a tarefa (hora, convidados) atualiza o evento e avisa os convidados; remover um convidado cancela-lhe o convite; apagar a tarefa cancela a reunião. Respostas (Sim/Não/Talvez) visíveis ao lado de cada email.
   - Se a conta da topper. não estiver ligada: aviso com atalho para Definições › Integrações e campo para colar o link à mão.
3. **Discord** → o link do canal (Definições) entra automaticamente na tarefa e no convite por email (enviado pelo serviço de envio, §31.1) aos mesmos convidados sugeridos.
4. **Presencial** → campo «Local» com sugestão da morada da empresa/contacto e convite por email com ficheiro .ics.
5. Convites por email em PT (EN se o contacto tiver idioma inglês), com o nome e o código do negócio no título.

### 28.16 «Por confirmar · emails» — revisão (30 out 2026, primeira fatura real)
Caso real: fatura da Gesvalor (contabilista) **à topper.** («Avença agosto – 196,80 EUR») ficou por confirmar em «A receber», com «Sem QR fiscal legível e sem IA». Era uma despesa.
- **Identidade da empresa:** nome legal **Feitiço Constante, Lda.** + NIF da empresa (Definições › Financeiro › Empresa). Regra: documento cujo **adquirente/cliente** é Feitiço Constante / NIF da empresa → **despesa (A pagar)**; cujo **emitente** é a empresa → receita (A receber). Usar nome legal, NIF, «topper»/«Topper Digital» e o remetente (contabilista ⇒ quase sempre despesa, exceto quando responde a um «Pedir fatura» com o código).
- **IA sempre como segunda leitura** quando não há QR (texto do PDF; PDF digitalizado → IA com visão). Se a IA não estiver configurada ou falhar, dizê-lo claramente («IA indisponível: …»), nunca só «sem IA».
- **Painel único** «Por confirmar · emails» visível em **A pagar** e **A receber** (filtrado pela sugestão; os indecisos em ambos) e na Visão geral; contador no separador.
- Cada cartão mostra: remetente, caixa, data, assunto, **texto do email** (até ~1 000 caracteres, só para emails classificados como documento financeiro; o resto continua a não ser guardado), **anexo com pré-visualização** (miniatura + botão «Abrir PDF» bem visível, abre em painel lateral), valores lidos (emitente, adquirente, NIF, nº, data, total, IVA) e o **motivo** de estar por confirmar.
- **Ícone de classificação subtil** em cada cartão (e em qualquer lançamento): alternar **A pagar ⇄ A receber** num clique, com «Anular»; a escolha alimenta a regra (o mesmo emitente/NIF passa a ser classificado igual).
- Ações claras: «Confirmar como despesa» / «Confirmar como fatura emitida» (abre o formulário já preenchido), «Pôr de parte» (com motivo).

### 28.17 Comprovativos de pagamento e recibos por email (30 out 2026, decidido com o caso real)
Caso real: o Millennium envia para info@ o comprovativo de cada transferência («Fatura-Recibo» do banco com a comissão, PDF cifrado). Objetivo: o sistema lê o comprovativo e o recibo, sugere a fatura e **só fica pago depois de alguém validar**.
- **Comprovativo:** valor, data do movimento, beneficiário e IBAN, ordenante, descritivo, referência Multibanco (entidade + referência) ou de pagamento ao Estado, comissões do banco. Lido do texto do PDF (sem IA; PDFs cifrados sem palavra-passe são decifrados); a IA só para comprovativos digitalizados (imagem).
- **Associação** por pontos: referência MB igual à da fatura, IBAN da fatura / já conhecido (aprendido em validações anteriores, ficha de salário), valor igual ao que falta, nome/nº/NIF no descritivo, data perto do vencimento. Nas faturas que chegam guardam-se o IBAN para pagar e a referência MB.
- **Validação humana sempre:** o cartão em «Por confirmar · emails» propõe o lançamento (ou outro da lista, ou «criar a despesa já paga» quando ainda não está lançado — a fatura/recibo junta-se depois). Validar regista o pagamento com o PDF como comprovativo; a comissão do banco é lançada à parte, já paga, com a fatura-recibo do banco. Tudo com «Anular».
- **Extrato:** o movimento de um pagamento já registado (com ou sem a comissão do mesmo comprovativo) liga-se a ele — nunca dois pagamentos. Um comprovativo que chega depois de o extrato já ter pago a fatura junta-se a esse pagamento.
- **Recibos:** os que respondem a «Pedir recibo» (código REC-…) continuam a juntar-se sozinhos; os outros ficam por validar, com a fatura sugerida (NIF, nº da fatura no recibo, valor). Ao validar, se ainda faltar pagar, o recibo serve de prova e regista também o pagamento.

## 32. Drive — melhorias pedidas pela equipa (31 out 2026)

Verificado no main: mover/copiar faz uma **cópia servidor a servidor no R2 ficheiro a ficheiro** (`drive_transfer`, CopyObject + apagar), em pedidos encadeados com barra de progresso — o R2 não tem «mudar de nome», por isso uma pasta grande demora e parece que está a ser enviada outra vez. A partilha de **ficheiro** já tem pré-visualização (imagem, vídeo, áudio, PDF); a de **pasta** é só uma lista com «Descarregar» por ficheiro, sem pré-visualização nem ZIP. Partilhas são só de leitura.

### 32.1 Mover instantâneo
- O que o utilizador vê muda **logo** (o item aparece no destino de imediato, com «Anular»); a cópia física no R2 corre **em segundo plano** no servidor (cron/fila), sem depender da página aberta, com indicador discreto «A arrumar 3 ficheiros…».
- Enquanto a cópia não acaba, o ficheiro abre/descarrega a partir da origem (mapa origem→destino na base); links de partilha, cartões do Centro Criativo e anexos continuam a funcionar.
- Mover dentro do mesmo sítio (renomear) e mover pastas grandes nunca bloqueia a interface. Conflitos de nome resolvidos antes (manter ambos/substituir/saltar).
- Alternativa estrutural a avaliar pelo executor (preferível se viável): guardar os ficheiros no R2 por **ID imutável** e manter os caminhos só na base (`drive_index`) — mover e renomear passam a ser só uma alteração na base, verdadeiramente instantâneos. Inclui migração gradual.

### 32.2 Partilha com permissões
- Ao partilhar, escolher **«Só ver»** ou **«Pode editar»**, validade e (opcional) palavra-passe; mudar a permissão depois sem mudar o link.
- **Pode editar** (pastas) — confirmado 31 out 2026: quem tem o link pode **carregar ficheiros, criar subpastas, renomear e substituir versões**, mas **nunca apagar nada** (nem ficheiros, nem pastas, nem versões; substituir guarda a anterior no histórico). Verificado no servidor, não só escondido na interface. A **validade do link** continua obrigatória à escolha (como hoje). Tudo o que entra fica registado (nome indicado pelo visitante, data, IP parcial) e é notificado ao dono da pasta. Limite de tamanho por envio (parâmetro).
- Ficheiro: «Pode editar» = substituir por nova versão (a anterior fica no histórico).

### 32.3 Pré-visualização no link partilhado
- Pasta partilhada mostra **grelha com miniaturas** (imagens, vídeos com poster, PDF 1.ª página) e lista; clicar abre **pré-visualização em ecrã inteiro** com setas (anterior/seguinte), como na Drive interna; vídeo/áudio com leitor; restantes com ícone e «Descarregar».
- Navegação por subpastas dentro do link.

### 32.4 Descarregar tudo
- Botão **«Descarregar tudo (.zip)»** no topo da pasta partilhada e «Descarregar seleção» quando se marcam vários. ZIP gerado em streaming (sem esperar), com a estrutura de subpastas; para pastas muito grandes, aviso do tamanho e divisão em partes.

### 32.5 Copiar link — bug
- Reportado: o botão «Copiar link» não copia o link certo. Reproduzir e corrigir (suspeita: copia o URL de outra partilha/da linha errada, um URL relativo ou expirado). Copiar sempre o URL público absoluto `https://topperhub.xyz/admin/partilha.php?t=…` da partilha desse item, confirmar com aviso «Link copiado» e mostrar o link num campo selecionável se a cópia falhar (iPad/Safari).

### 32.6 Partilha rápida (clique direito)
- Clique direito (ou toque longo) num ficheiro → **«Copiar link de partilha»**: cria (ou reaproveita) uma partilha «Só ver» com a validade por defeito e copia logo o link, num só passo. Também na barra de ações quando há um ficheiro selecionado.
