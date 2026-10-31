# Como trabalhar neste repositório

Plataforma topper. (PHP 8 sem framework, MySQL em produção, MariaDB na bancada). A especificação está em
`docs/ESPECIFICACAO-PLATAFORMA.md`; a §10 regista o que foi implementado. Responder sempre em português europeu.

## Processo (skills do superpowers — obrigatório)

- **Antes de implementar cada secção da especificação:** `superpowers:writing-plans` (plano escrito, passos pequenos).
- **Funcionalidades novas:** `superpowers:test-driven-development` — o teste primeiro, vê-lo falhar, depois o código.
- **Qualquer bug:** `superpowers:systematic-debugging` — causa primeiro, depois a correção.
- **Antes de dizer que algo está feito:** `superpowers:verification-before-completion`, com a prova (teste, registo
  ou captura do resultado real).
- **Antes de publicar no main:** `superpowers:requesting-code-review`.
- **Partes com credenciais, emails ou permissões:** `security-review`.

## Regras que se mantêm

- Não publicar no main nem na Hostinger sem pedido explícito; perguntar no fim. O main só avança por fast-forward.
- Credenciais só no servidor, em ficheiros de configuração fora do Git (`fin_mail_config.php`, `auth_config.php`,
  `meet_config.php`, `ai_config.php`, `app_key.php`); nunca no código, nunca na base de dados em claro, nunca no chat.
  O token da Cloudflare e o `INBOUND_SECRET` nunca aparecem.
- Permissões sempre verificadas no servidor. Valores em euros só para quem tem acesso ao Financeiro.
- Nunca mostrar «§» na interface.
- Sem identificadores de modelos em commits, PRs ou código.
- Migrações idempotentes (`inc/migrate*.php`, `SCHEMA_VERSION` em `inc/migrate.php`); correm sozinhas no bootstrap.
  Ao publicar, enviar a migração antes do `inc/migrate.php`.
- Testes: ainda não há framework de testes; os novos ficam em `tests/` como scripts PHP simples, corridos com
  `php tests/<ficheiro>.php` (sem dependências externas — o servidor não tem Composer nem `exec()`).
