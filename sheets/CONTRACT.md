# Admin ↔ Google Sheet contract

> **Since the platform migration (docs/ESPECIFICACAO-PLATAFORMA.md §5) MySQL is the single
> source of truth.** The admin no longer reads or writes the tabs below; `admin/migracao.php`
> reads them once to migrate. After that the sheet is a read-only mirror: the admin rewrites
> the tabs `Espelho · Negócios`, `Espelho · Custos`, `Espelho · Leads` and `Espelho · Contactos`
> with the `mirror` action (nightly via `admin/inc/cron_run.php export`, or the button in
> Migração). `mirror` only accepts tab names starting with `Espelho · `.
>
> POST `{token, action:"mirror", tab:"Espelho · …", header:[…], rows:[[…]…]}` → `{ok:true, rows:n}`
> (clears the tab and writes header + rows; creates the tab if missing).
>
> The rest of this document describes the sheet as it was before the migration.

The Google Sheet "TOPPER DIGITAL" is the single source of truth for the admin's
Painel, Carteira (jobs, job crew/costs, company costs, IVA), Leads and Fornecedores.
The admin talks to it through an Apps Script web app (`sheets/Code.gs`).
The MySQL tables `projects`, `crew`, `company_costs`, `leads` are no longer read or
written by these pages (do not drop them). Users, login, settings and the Calendar
stay in MySQL.

## Tabs (row 1 = titles, one record per row; [c] = computed by the sheet, never written)

**Trabalhos** — jobs only (company running costs are in "Custos empresa")
Ano (number) · Mês (Portuguese month name "Janeiro"…"Dezembro", or "") · Nome (unique) ·
Tipo (Animação 3D | VFX | Edição | Produção Completa | Fotografia) · Budget (s/ IVA) (number) ·
Internacional (bool) · Fatura com IVA (bool = admin has_vat) ·
Gastos manuais (number, or "" = costs are the sum of this job's Equipa rows) · Pago (bool) · Notas ·
[c] Budget (c/ IVA) · [c] Gastos (net costs used for profit) · [c] Lucro

**Equipa** — cost lines of a job (admin `crew`)
Trabalho (= Trabalhos.Nome) · Pessoa · Função · Valor (as written on the invoice) ·
Tipo de IVA (Sem IVA | Acresce IVA | IVA incluído  ↔ admin vat_mode none | plus | incl; the admin may keep showing "+ IVA" as the label, but the sheet value must be "Acresce IVA" because a cell starting with "+" becomes a formula) · Fechado (bool = is_confirmed) ·
Contacto · Notas · [c] Valor s/ IVA · [c] IVA · [c] Total

**Custos empresa** — company running costs (admin `company_costs`)
Ano · Mês · Nome · Valor · Tipo de IVA · Pago (bool) · Notas · [c] Valor s/ IVA · [c] IVA · [c] Total

**Leads**
Nome · Tipo (one or more of: Produtora, Agência de Marketing, Empresa Particular, Realizador,
VFX company — stored comma-separated, e.g. "Produtora, VFX company") · Contactos (redes / site)
(URLs separated by " | ") · Telefone · Fase (Novo | Contactado | Em conversa | Proposta enviada |
Ganho | Perdido) · Serviço (VFX / 3D | Marketing Buddy | Ambos) · Prioridade (Alta | Média | Baixa) ·
Detalhe · Porquê abordar · Email · Notas · Último toque ("yyyy-mm-dd" or "")

**Fornecedores** — suppliers / freelancers / rentals (admin page `fornecedores.php`)
Nome · Categoria (Produção | Imagem | Som | Pós-produção | 3D / VFX | Elenco | Material / aluguer |
Locais | Software / serviços | Outro) · Serviço · Pessoa de contacto · Email · Telefone (written as
text, leading apostrophe) · Site / redes (URLs separated by " | ") · NIF (written as text) · Tarifa ·
Avaliação (★ | ★★ | ★★★ | ★★★★ | ★★★★★ or "") · Último trabalho · Notas. No computed columns.
Optional tab: if it does not exist yet, `read` simply leaves it out; the admin's other pages keep
working and only the Fornecedores page asks for it.

VAT is 23% inside the sheet formulas. Use the sheet's computed columns for totals
(Gastos, Valor s/ IVA, IVA, Budget (c/ IVA)) instead of recomputing them in PHP, so the
site and the sheet always show the same numbers. VAT on a job's manual costs (no Equipa
rows) = Gastos manuais × 23% when "Fatura com IVA" is ticked (same rule as today).

## Web app API (JSON; every call carries `token`)

GET  `{url}?token=…&action=read` →
`{ok:true, at, tabs:{ "<Tab>": {header:[…], computed:[bool…], rows:[{_row:2, "<Title>":value,…}]}}}`
Values: numbers, booleans, strings; dates come as "yyyy-mm-dd". Fully empty rows are skipped,
so `_row` (the real sheet row number) can jump.

POST `{url}` with JSON body:
- `{token, action:"update", tab, row, match:{"<Title>":value}, values:{"<Title>":value}}` → `{ok:true,row}`
- `{token, action:"append", tab, values:{…}}` → `{ok:true,row}` (written after the last data row)
- `{token, action:"delete", tab, row, match:{…}}` → `{ok:true}`
`match` is checked before writing: if the row no longer holds those values (someone edited the
sheet meanwhile) the call fails with `{ok:false,error:"A folha mudou entretanto. Recarregue…"}`.
Always send `match` with the row's key: Trabalhos/Custos empresa/Leads/Fornecedores → Nome; Equipa → Trabalho + Função.
Renaming a job (Trabalhos.Nome) renames its Equipa rows; deleting a job deletes its Equipa rows.
Computed columns are silently ignored on write. Errors: `{ok:false,error:"…"}`.
Apps Script answers with a 302 to script.googleusercontent.com: follow redirects
(after a POST the redirect target must be fetched with GET).

`sheets/fixture-read.json` is a sample `read` response.

## Drive (same web app, same token)

The script also manages the Google Drive folder **"Topper Digital"**
(`ROOT_ID = 11mgqHYd1oqLvOMQdj6C0R-Mgtn9WNXNF`; children 01 Empresa, 02 Projetos
`1p7VKCw1iZSpb4xFTugX1wzyVLsfSho-a`, 03 Faturas, 04 Showreel e Portfolio). Every
action checks — by walking parents — that the folder/file is inside ROOT; anything
else fails with `{ok:false,error:"… fora da pasta Topper Digital."}`. Ids must match
`[A-Za-z0-9_-]{10,100}`. Needs the Drive scope: after pasting, run `autorizarDrive`
once in the editor and deploy a new version.

GET `{url}?token=…&action=drive_list[&folder=<id>]` (default ROOT) →
`{ok:true, folder:{id,name,url,root}, breadcrumb:[{id,name}…] (ROOT first),
children:[{id,name,mimeType,size,modified(ISO),url,folder(bool),thumbnail?}…] (folders first, by name),
max_bytes}`

POST:
- `{token, action:"drive_mkdir", parent, name}` → `{ok:true, folder:{id,name,url}}` (fails if a folder with that name exists there)
- `{token, action:"drive_upload", parent, name, mimeType, base64}` → `{ok:true, file:{id,name,url,size}}`.
  Max 25 MB decoded (Apps Script payload limits); bigger files must be uploaded in Drive itself
  ("Abrir no Drive"). The admin checks the size before encoding and never sends bigger files.
- `{token, action:"drive_project", name, year}` → ensures `02 Projetos/<year>/<name>/` with
  `Vídeos`, `Faturas`, `Documentos`; idempotent → `{ok:true, folder:{id,name,url}}`.
  Renaming a job does not rename its folder.
- `{token, action:"drive_trash", id}` → `{ok:true}` (moves to the Drive trash; ROOT and its
  four top folders are refused).

An older deployed script answers `{ok:false,error:"action"}` to these; the admin then says
"Atualize o Code.gs".
