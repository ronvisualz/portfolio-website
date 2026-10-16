# Admin ↔ Google Sheet contract

The Google Sheet "TOPPER DIGITAL" is the single source of truth for the admin's
Painel, Carteira (jobs, job crew/costs, company costs, IVA) and Leads.
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
Always send `match` with the row's key: Trabalhos/Custos empresa/Leads → Nome; Equipa → Trabalho + Função.
Renaming a job (Trabalhos.Nome) renames its Equipa rows; deleting a job deletes its Equipa rows.
Computed columns are silently ignored on write. Errors: `{ok:false,error:"…"}`.
Apps Script answers with a 302 to script.googleusercontent.com: follow redirects
(after a POST the redirect target must be fetched with GET).

`sheets/fixture-read.json` is a sample `read` response.
