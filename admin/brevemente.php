<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
$user = require_login();

// As abas cujo conceito ainda está por decidir (docs/ESPECIFICACAO-PLATAFORMA.md §8.7):
// já existem no menu e nas permissões, mas ainda sem lógica.
const SOON = [
    'orcamentacao' => ['Orçamentação', 'Criação e gestão de orçamentos, ligados a cada negócio pelo ID.'],
    'criativo'     => ['Toppernote', 'Produção criativa: briefings, referências, versões, feedback e aprovações do cliente.'],
    'marketing'    => ['Marketing', 'Atração de leads e campanhas.'],
];

$tab = (string)($_GET['t'] ?? '');
if ($tab === 'relatorios') redirect('/admin/relatorios.php');   // já têm página própria
if ($tab === 'conhecimento') redirect('/admin/conhecimento.php');
if ($tab === 'criativo') redirect('/admin/toppernote.php');
if (!isset(SOON[$tab])) redirect(home_url());
require_page($tab);
[$title, $what] = SOON[$tab];

render_header($title, $tab);
?>
<h1><?= e($title) ?></h1>
<div class="panel" style="max-width:640px">
  <p><b>Em construção.</b></p>
  <p class="muted"><?= e($what) ?></p>
</div>
<?php render_footer();
