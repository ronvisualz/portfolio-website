<?php
/**
 * topper. admin — Partilhar para a topper. (§26.3)
 *
 * No Android, com a aplicação instalada (PWA), «topper.» aparece no menu Partilhar de qualquer app.
 * O que se partilha chega aqui: o service worker (sw.js) guarda-o e esta página mostra-o, deixa
 * escolher o destino (Caixa de entrada ou um quadro) e uma nota, e envia com a sessão (capture_api).
 * Tudo passa pela análise da IA e entra na Caixa de entrada. Sem nada partilhado, a página serve
 * para guardar um link à mão e explica como instalar.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/cc.php';
require_once __DIR__ . '/inc/capture.php';
$user = require_login();
require_perm('criativo', PERM_EDIT);

// sem o service worker (primeira vez, ou browser sem ele): o envio chega diretamente.
// Só se aceita vindo do próprio sistema (Partilhar) ou da plataforma, nunca de outro site.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $site = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if (!in_array($site, ['none', 'same-origin'], true)) { flash('Esta partilha não foi aceite. Abra a topper. e partilhe outra vez.', 'err'); redirect('/admin/partilhar.php'); }
    $n = 0; $err = '';
    try {
        $base = ['title' => (string)($_POST['title'] ?? ''), 'text' => (string)($_POST['text'] ?? ''), 'url' => (string)($_POST['url'] ?? ''), 'board' => '-1', 'via' => 'android'];
        $files = $_FILES['files'] ?? null;
        if ($files && is_array($files['tmp_name'])) {
            foreach ($files['tmp_name'] as $i => $tmp) {
                if ($n >= 10 || !is_uploaded_file($tmp) || $files['size'][$i] > CAP_MAX_BYTES) continue;
                cap_save($pdo, $user, $base + ['kind' => 'file'], ['bytes' => (string)file_get_contents($tmp), 'name' => (string)$files['name'][$i], 'mime' => (string)$files['type'][$i]]);
                $n++;
            }
        }
        if (!$n && ($base['url'] !== '' || $base['text'] !== '')) { cap_save($pdo, $user, $base + ['kind' => $base['url'] !== '' ? 'link' : 'text'], null); $n = 1; }
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
    flash($err !== '' ? $err : ($n ? ($n === 1 ? 'Guardado na Caixa de entrada.' : $n . ' itens guardados na Caixa de entrada.') . ' A IA analisa a seguir.' : 'Não chegou nada para guardar.'), $err !== '' || !$n ? 'err' : 'ok');
    redirect('/admin/partilhar.php');
}

render_header('Partilhar para a topper.', 'criativo', '', ['Toppernote', 'Partilhar']);
render_flashes();
?>
<div class="page-head"><div><h1>Guardar na topper.</h1>
  <p class="sub">O que partilhar entra na Caixa de entrada do Toppernote e a IA analisa. Pode pô-lo também num quadro.</p></div></div>

<div class="panel share-pg" id="share" data-csrf="<?= e(csrf_token()) ?>">
  <div class="share-items" data-items><p class="muted" data-empty>A ver se chegou alguma coisa…</p></div>
  <form class="share-f" data-form>
    <div class="field" data-manual hidden><label for="sh-url">Link</label><input id="sh-url" type="url" placeholder="https://…" autocomplete="off"></div>
    <div class="field"><label for="sh-dest">Destino</label>
      <select id="sh-dest"><option value="0">Caixa de entrada</option></select></div>
    <div class="field"><label for="sh-note">Nota (opcional)</label><textarea id="sh-note" rows="2" placeholder="Porque guardo isto…"></textarea></div>
    <div class="actions"><button type="submit" class="btn" data-go>Guardar</button><a class="btn ghost" href="/admin/toppernote.php?t=entrada">Abrir a Caixa de entrada</a></div>
    <p class="small muted" data-st role="status"></p>
  </form>
</div>

<details class="panel share-help">
  <summary><b>Como pôr a topper. no menu Partilhar</b></summary>
  <ol class="small">
    <li><b>Android (Chrome):</b> abra a topper. no Chrome, menu ⋮ › <b>Instalar aplicação</b> (ou «Adicionar ao ecrã principal»). A partir daí, «topper.» aparece no menu Partilhar de qualquer app (Instagram, YouTube, Galeria…).</li>
    <li><b>iPhone e iPad:</b> o Safari não deixa uma página entrar no menu Partilhar. Use o Atalho «Guardar na topper.» — os passos estão em <a href="/admin/conta.php#ligacoes">Perfil › Ligações</a>.</li>
    <li><b>Computador:</b> a extensão «Guardar na topper.» para Chrome e Edge, também em <a href="/admin/conta.php#ligacoes">Perfil › Ligações</a>.</li>
  </ol>
</details>
<script src="<?= asset_url('share.js') ?>" defer></script>
<?php
render_footer();
