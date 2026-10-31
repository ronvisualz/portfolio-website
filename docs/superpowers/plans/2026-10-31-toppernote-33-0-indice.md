# §33 Toppernote — índice dos planos, ordem e riscos

**Spec:** `docs/ESPECIFICACAO-PLATAFORMA.md` §33.0–33.13 (ramo `claude/kind-allen-xxk2vi`, junto em 31 out).

Seis planos, um por grupo, executados por esta ordem (cada um deixa a plataforma a funcionar e testada):

| # | Plano | Spec | Porquê nesta ordem |
|---|-------|------|--------------------|
| 1 | `…-1-renome.md` | 33.0, 33.0.1 | Muda textos em quase todos os ficheiros que os outros planos tocam; fazê-lo primeiro evita conflitos e garante que os textos novos já nascem com «Toppernote/Topperboard». |
| 2 | `…-2-edicao.md` | 33.1, 33.2, 33.3, 33.4, 33.7, 33.10 (nota, listas Tab/Ctrl Enter, últimas 8 cores, P/E/1–5, balão só leitura, Anular/Refazer por pessoa) | Cria `assets/cc_logic.js` (lógica pura testada em Node) que os planos 4 e 5 também usam. |
| 3 | `…-3-media.md` | 33.5, 33.10 (envio otimista, visualizador), 33.11 | Depende do 2 (popover de cor e paleta partilham o seletor de cor). Começa por debugging das imagens desfocadas. |
| 4 | `…-4-selecao.md` | 33.6, 33.12, 33.10 (colar desloca) | Depende do 2 (`cc_logic.js`, pilha de anular por pessoa). |
| 5 | `…-5-apresentacao.md` | 33.10 (navegação sem recarregar), 33.13 | Toca no arranque do quadro (`viewBoard`): depois de tudo o resto estar estável. |
| 6 | `…-6-drive-links.md` | 33.8, 33.9 | Servidor + migração 41; security-review aos pedidos externos e à leitura de ficheiros da Drive. |

Fecho: accessibility-audit (foco, teclado, contraste claro/escuro, alvos de 44 px), capturas claro/escuro em computador e iPad (viewport 1180×820 com toque), §10.31 da especificação, requesting-code-review (revisor no modelo mais capaz), security-review, publicar só a pedido.

## Riscos (e o que se faz)

1. **`cc.js` é um ficheiro de 2436 linhas com o quadro numa só função (`viewBoard`).** Risco de regressões em cadeia. → Lógica nova em `cc_logic.js` (pura, testada); em `cc.js` só a ligação ao DOM; teste de UI de regressão do quadro (`tests/ui/cc_board_smoke_test.js`, plano 2 tarefa 1) corre no fim de cada tarefa.
2. **Renome «quadro» apanha usos que não são do Toppernote** (Tarefas › «Quadro da equipa», kanban do Marketing). → Só ficheiros e chaves do Toppernote; teste estático com lista de ficheiros e frases permitidas.
3. **Tempo real (`cc_rt.js`) e anular por pessoa.** Anular aplica estados antigos e pode apagar o trabalho de um colega. → `CCL.undoPlan`: só repõe o que ainda está como ficou depois da ação própria; o resto salta com aviso.
4. **Pedidos externos (oEmbed/Open Graph)**: SSRF e respostas grandes/lentas. → Reutilizar `cc_fetch` (IP público verificado a cada salto, limite de tamanho e tempo); endpoints oEmbed só de uma lista fixa; security-review.
5. **Ficheiros da Drive no Toppernote** ficam acessíveis a quem vê o Toppernote. → `cc_file.php` mantém `drive_can_see()` para chaves `r2:` vindas da Drive (faturação/privados); security-review.
6. **iPad**: gestos (toque longo para selecionar, pinça, sem hover). → Testes de UI com `hasTouch` em 1180×820; balão de comentários abre ao tocar.
7. **Som em Apresentar**: políticas de autoplay. → O clique em «Apresentar» é o gesto que autoriza; se `play()` rejeitar, mostra «Tocar» no cartão.
