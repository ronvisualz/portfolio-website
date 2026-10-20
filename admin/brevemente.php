<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();

// As abas cujo conceito ainda está por decidir (docs/ESPECIFICACAO-PLATAFORMA.md §8.7):
// já existem no menu e nas permissões, mas ainda sem lógica.
const SOON = [
    'orcamentacao' => ['Orçamentação', 'Criação e gestão de orçamentos, ligados a cada negócio pelo ID.'],
    'criativo'     => ['Centro Criativo', 'Produção criativa: briefings, referências, versões, feedback e aprovações do cliente.'],
    'relatorios'   => ['Relatórios', 'Histórico e análise por período, funil, campanha e pessoa.'],
    'marketing'    => ['Marketing', 'Atração de leads e campanhas.'],
    'conhecimento' => ['Centro de Conhecimento', 'Processos, templates e guias da equipa.'],
];

$tab = (string)($_GET['t'] ?? '');
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
