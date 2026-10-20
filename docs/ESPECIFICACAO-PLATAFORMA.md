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
20. **Dicas (tooltips) em todos os ícones — regra geral da plataforma:** qualquer
    ícone, seja botão ou apenas informativo (menu recolhido, ações, estados,
    indicadores), mostra o seu nome ao passar o cursor — rato, touchpad ou
    trackpad do iPad. Aparece após ~300 ms, desaparece ao sair. Em ecrãs só de
    toque, aparece com toque longo; também aparece ao receber foco pelo teclado.
    O mesmo texto serve de nome acessível (`aria-label`) do ícone. Nenhum ícone
    sem nome.
19. **Motion:** 150–200 ms, só para confirmar ações (cartão que muda de coluna,
    gravação feita, contagem de números). Respeitar `prefers-reduced-motion`.

Fora: navegação em pílulas no topo (não serve para 15 abas), recompensas, mensagens.
