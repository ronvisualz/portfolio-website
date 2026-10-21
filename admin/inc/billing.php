<?php
/**
 * topper. admin — pré-visualização de cobrança (§11.15)
 *
 * Ainda não há faturas nem orçamentos na plataforma (Orçamentação está
 * por desenhar, §8.7). Até lá, cada negócio tem um resumo de cobrança
 * com os valores que o sistema calcula: serve para ver, descarregar,
 * copiar o link e marcar como pago. É um documento interno — a fatura
 * continua a sair do programa de faturação.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/ui.php';

/** O negócio com o contacto principal, para o documento. */
function billing_deal(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT d.*, c.name AS contact_name, c.nif AS contact_nif, c.email AS contact_email
                           FROM deals d LEFT JOIN contacts c ON c.id = d.contact_id WHERE d.id = ? AND d.code IS NOT NULL');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** O resumo de cobrança em HTML (sempre em papel claro, como um PDF). */
function billing_doc(PDO $pdo, array $d, float $rate): string {
    $m = deal_money($d, deal_costs($pdo, (int)$d['id'], $rate), $rate);
    $when = $d['month'] && $d['year'] ? month_year((int)$d['month'], (int)$d['year']) : ($d['year'] ? (string)$d['year'] : '—');
    $desc = $d['name'] . ($d['job_type'] ? ' — ' . $d['job_type'] : '');
    return '<article class="doc" aria-label="Resumo de cobrança ' . e($d['code']) . '">'
         . '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">'
         . '<div>' . wordmark_svg('doc-wm') . '<div class="muted" style="font-size:12px;margin-top:6px">Resumo de cobrança · documento interno</div></div>'
         . '<div style="text-align:right"><div class="mono" style="font-size:15px;font-weight:600">' . e($d['code']) . '</div>'
         . '<div class="muted" style="font-size:12px">' . e($when) . '</div></div></div>'
         . '<div style="margin:22px 0 14px;font-size:13px"><div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.1em">Cliente</div>'
         . '<b>' . e($d['contact_name'] ?: '—') . '</b>'
         . ($d['contact_nif'] ? '<div>NIF ' . e($d['contact_nif']) . '</div>' : '')
         . ($d['contact_email'] ? '<div>' . e($d['contact_email']) . '</div>' : '') . '</div>'
         . '<table><thead><tr><th>Descrição</th><th class="num">Valor</th></tr></thead><tbody>'
         . '<tr><td>' . e($desc) . '</td><td class="num">' . eur($m['budget_net']) . '</td></tr></tbody><tfoot>'
         . '<tr><td>Subtotal s/ IVA</td><td class="num">' . eur($m['budget_net']) . '</td></tr>'
         . '<tr><td>' . ((int)$d['has_vat'] ? 'IVA ' . rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',') . '%' : ((int)$d['is_international'] ? 'IVA — autoliquidação / internacional' : 'Sem IVA')) . '</td><td class="num">' . eur($m['vat_out']) . '</td></tr>'
         . '<tr><td><b>Total</b></td><td class="num"><b>' . eur($m['budget_gross']) . '</b></td></tr></tfoot></table>'
         . '<p style="margin:16px 0 0;font-size:12px"><span class="chip ' . ((int)$d['is_paid'] ? 'ok">Pago' : 'wait">Por pagar') . '</span></p>'
         . '<p class="muted" style="font-size:11px;margin:14px 0 0">Pré-visualização calculada pela plataforma. Não substitui a fatura emitida no programa de faturação.</p>'
         . '</article>';
}
