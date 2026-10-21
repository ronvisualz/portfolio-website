<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/billing.php';
$user = require_login();
require_page('financeiro');

// Resumo de cobrança de um negócio, numa página própria para imprimir ou
// guardar em PDF; com ?dl=1 descarrega-se como ficheiro (§11.15).

$deal = billing_deal($pdo, (int)($_GET['id'] ?? 0));
if (!$deal) {
    http_response_code(404);
    exit('Negócio não encontrado.');
}
$rate = vat_rate($pdo);
$prefs = ui_prefs();
$dl = !empty($_GET['dl']);
$css = $dl ? (string)@file_get_contents(__DIR__ . '/assets/app.css') : '';
if ($dl) {
    header('Content-Disposition: attachment; filename="Resumo-' . preg_replace('/[^A-Za-z0-9-]/', '', $deal['code']) . '.html"');
}
?><!DOCTYPE html>
<html lang="pt"<?= !$dl && $prefs['pref_theme'] !== '' ? ' data-theme="' . e($prefs['pref_theme']) . '"' : '' ?>>
<head>
<?php if ($dl): ?>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Resumo de cobrança <?= e($deal['code']) ?> · topper.</title>
<style><?= $css ?></style>
<?php else: render_head('Resumo de cobrança ' . $deal['code']); endif; ?>
<style>
  body { padding: 32px 16px; }
  .sheet { max-width: 720px; margin: 0 auto; }
  .sheet .doc { padding: 40px 44px; }
  .print-bar { display: flex; gap: 8px; justify-content: flex-end; margin-bottom: 14px; }
  @media print { body { padding: 0; background: #fff; } .print-bar { display: none; } .sheet .doc { box-shadow: none; padding: 0; } }
</style>
</head>
<body>
<div class="sheet">
  <?php if (!$dl): ?>
  <div class="print-bar">
    <a class="btn ghost sm" href="/admin/financeiro.php?tab=receber&amp;doc=<?= (int)$deal['id'] ?>">← Financeiro</a>
    <a class="btn ghost sm" href="?id=<?= (int)$deal['id'] ?>&amp;dl=1"><?= icon('download') ?> Descarregar</a>
    <button class="btn sm" type="button" id="print"><?= icon('file') ?> Imprimir / PDF</button>
  </div>
  <script>document.getElementById('print').addEventListener('click', function () { window.print(); });</script>
  <?php endif; ?>
  <?= billing_doc($pdo, $deal, $rate) ?>
</div>
</body>
</html>
