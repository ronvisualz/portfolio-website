<?php
/**
 * topper. — link de partilha do Drive (página pública, sem login).
 *
 *   partilha.php?t=<token>            a página (um ficheiro ou uma pasta)
 *   partilha.php?t=<token>&dl=1       descarregar o ficheiro partilhado (302 para o R2)
 *   partilha.php?t=<token>&pv=1       a pré-visualização em linha (302 para um URL curto do R2)
 *   partilha.php?t=<token>&f=<rel>    descarregar um ficheiro de dentro da pasta partilhada
 *   partilha.php?t=<token>&p=<rel/>   uma subpasta (nunca acima da pasta partilhada)
 *   partilha.php?t=<token>&pv=1&f=…   pré-visualizar um ficheiro da pasta; &th=1&f=… a miniatura
 *   POST JSON (X-Share-Form)          as ações de quem tem «Pode editar» (inc/drive_share.php)
 *
 * Numa pasta, os documentos privados e a faturação só aparecem se a partilha os incluir (drive_share_sees).
 *
 * Um token inválido, expirado ou revogado mostra sempre a mesma página
 * («Este link expirou ou não existe.»), sem dizer mais nada.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/drive.php';
require __DIR__ . '/inc/drive_store.php';
session_write_close();   // página pública: a sessão do admin não entra aqui

const SHARE_DL_TTL = 300;          // URL de download (o browser segue-o logo)
// A pré-visualização não leva o URL do R2 na página: aponta para &pv=1, que volta a confirmar o
// link a cada pedido e redireciona para um URL curto. Revogar um link corta-a em minutos, não horas.
// (Os vídeos e o áudio ficam com mais tempo, para dar para ver e saltar até ao fim sem recarregar.)
const SHARE_PREVIEW_TTL = ['image' => 600, 'pdf' => 900, 'video' => 1800, 'audio' => 1800];

function share_h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** «26/10/2026 às 18:00», hora de Lisboa. */
function share_when(int $ts): string {
    $d = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Lisbon'));
    return $d->format('d/m/Y') . ' às ' . $d->format('H:i');
}

/** «Disponível até 26/10/2026 às 18:00», ou «Sem prazo de validade» nos links de pasta sem prazo. */
function share_valid(array $s): string {
    return $s['forever'] ? 'Sem prazo de validade' : 'Disponível até ' . share_when($s['exp']);
}

/** Ícones (20×20, traço), os mesmos desenhos do Drive. */
function share_icon(string $kind): string {
    $p = [
        'lock' => '<rect x="4" y="9" width="12" height="8" rx="1.5"/><path d="M7 9V6.5a3 3 0 0 1 6 0V9"/>',
        'video' => '<rect x="2.5" y="4.5" width="15" height="11" rx="1.5"/><path d="M8.5 7.5v5l4.2-2.5z"/>',
        'image' => '<rect x="2.5" y="3.5" width="15" height="13" rx="1.5"/><circle cx="7" cy="8" r="1.4"/><path d="M3 15l4.5-4.5 3 3 2.5-2.5 4.5 4.5"/>',
        'audio' => '<path d="M8 14.5V4.5l8-1.5v10"/><circle cx="6" cy="14.5" r="2"/><circle cx="14" cy="13" r="2"/>',
        'pdf'   => '<path d="M5 2.5h6.5L15.5 6.5v11H5z"/><path d="M11.5 2.5v4h4"/><path d="M7.5 12.5h5M7.5 15h3"/>',
        'folder' => '<path d="M2.5 5.5a1 1 0 0 1 1-1h4l1.6 1.8h7.4a1 1 0 0 1 1 1v8.2a1 1 0 0 1-1 1h-13a1 1 0 0 1-1-1z"/>',
        'download' => '<path d="M10 3.5v10M6 9.5l4 4 4-4M3.5 13v3.5h13V13"/>',
        'upload' => '<path d="M10 13.5v-10M6 7.5l4-4 4 4M3.5 13v3.5h13V13"/>',
        'other' => '<path d="M5 2.5h6.5L15.5 6.5v11H5z"/><path d="M11.5 2.5v4h4"/>',
    ][$kind] ?? '';
    if ($p === '') $p = '<path d="M5 2.5h6.5L15.5 6.5v11H5z"/><path d="M11.5 2.5v4h4"/>';
    return '<svg class="ico" viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor"'
         . ' stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

/** O tipo para o ícone e a pré-visualização: video|image|audio|pdf|other. */
function share_kind(string $name): array {
    [$label, , $pv] = drive_kind($name, false);
    $mime = r2_mime($name);
    $icon = strpos($mime, 'video/') === 0 ? 'video' : (strpos($mime, 'image/') === 0 ? 'image'
          : (strpos($mime, 'audio/') === 0 ? 'audio' : ($mime === 'application/pdf' ? 'pdf' : 'other')));
    return [$label, $icon, $pv];
}

/** Um ficheiro de assets/ com a versão (data de alteração), para o browser não guardar uma versão antiga. */
function share_asset(string $file): string {
    $p = __DIR__ . '/assets/' . $file;
    return '/admin/assets/' . $file . '?v=' . (is_file($p) ? filemtime($p) : time());
}

function share_go(string $url): void {
    header('Location: ' . $url, true, 302);
    exit;
}

function share_head(string $title): void {
    ?><!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="referrer" content="no-referrer">
<title><?= share_h($title) ?> · topper.</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<style>
  :root { --yellow: #FFC718; --ink: #000; --ink-2: #5B5B5B; --ink-3: #767676; --line: #E3E3E3; --surface: #FFF; --page: #F6F6F4; --r: 8px; }
  * { box-sizing: border-box; }
  html, body { margin: 0; }
  body { min-height: 100vh; display: flex; flex-direction: column; background: var(--page); color: var(--ink);
         font: 15px/1.5 'Inter', 'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif; -webkit-font-smoothing: antialiased; }
  a { color: inherit; }
  .top { background: var(--ink); color: #fff; }
  .top-in, .wrap { width: min(880px, 100%); margin: 0 auto; padding: 0 20px; }
  .top-in { display: flex; align-items: center; justify-content: space-between; gap: 12px; height: 64px; }
  .wm { width: 104px; height: auto; display: block; }
  .tag { font-size: 11px; letter-spacing: .13em; text-transform: uppercase; color: #9A9A9A; }
  .band { height: 6px; background: var(--yellow); }
  main { flex: 1; padding: 36px 0 48px; }
  .card { background: var(--surface); border: 1px solid var(--line); border-radius: calc(var(--r) + 4px); box-shadow: 0 1px 0 rgba(0,0,0,.04), 0 18px 40px -28px rgba(0,0,0,.45); overflow: hidden; }
  .head { display: flex; gap: 16px; align-items: flex-start; padding: 24px 24px 20px; }
  .badge { flex: none; width: 52px; height: 52px; border-radius: 12px; background: var(--yellow); color: var(--ink); display: grid; place-items: center; }
  .badge .ico { width: 28px; height: 28px; }
  .kicker { margin: 2px 0 2px; font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3); }
  h1 { margin: 0; font-size: 22px; line-height: 1.25; font-weight: 700; overflow-wrap: anywhere; }
  .meta { margin: 6px 0 0; color: var(--ink-2); font-size: 14px; }
  .meta b { color: var(--ink); font-weight: 600; }
  .actions { padding: 0 24px 24px; display: flex; flex-wrap: wrap; gap: 10px; }
  .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; border-radius: var(--r); border: 1.5px solid var(--ink);
         background: var(--yellow); color: var(--ink); font-weight: 600; font-size: 15px; text-decoration: none; }
  .btn:hover { background: var(--ink); color: var(--yellow); }
  .btn:focus-visible, .dl:focus-visible { outline: 3px solid var(--ink); outline-offset: 2px; }
  .btn .ico { width: 18px; height: 18px; }
  .preview { border-top: 1px solid var(--line); background: #111; display: flex; justify-content: center; align-items: center; }
  .preview img, .preview video { display: block; max-width: 100%; max-height: 70vh; }
  .preview audio { width: 100%; margin: 20px; }
  .preview.light { background: var(--page); }
  .preview iframe { display: block; width: 100%; height: 75vh; border: 0; background: #fff; }
  .list { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--line); }
  .row { display: flex; align-items: center; gap: 12px; padding: 12px 24px; border-bottom: 1px solid var(--line); }
  .row:last-child { border-bottom: 0; }
  .row .ico { color: var(--ink-2); }
  .name { flex: 1; min-width: 0; overflow-wrap: anywhere; }
  .name .dir { color: var(--ink-3); }
  .size { flex: none; color: var(--ink-2); font-size: 13px; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .dl { flex: none; display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border: 1px solid var(--line); border-radius: var(--r);
        text-decoration: none; font-weight: 600; font-size: 13px; background: var(--surface); }
  .dl:hover { border-color: var(--ink); background: #FFF8DD; }
  .dl .ico { width: 16px; height: 16px; }
  .empty, .more { padding: 20px 24px; color: var(--ink-2); border-top: 1px solid var(--line); margin: 0; }
  .gone { text-align: center; padding: 48px 24px; }
  .gone .badge { margin: 0 auto 18px; background: var(--page); color: var(--ink-2); }
  .pw { display: flex; gap: 8px; justify-content: center; margin: 22px auto 0; max-width: 360px; }
  .pw input { flex: 1; min-width: 0; padding: 12px 14px; border: 1.5px solid var(--ink); border-radius: var(--r); font: inherit; }
  .pw input[aria-invalid=true] { border-color: #B3261E; }
  .pwerr { color: #B3261E !important; font-weight: 600; }
  .gone p { color: var(--ink-2); margin: 10px auto 0; max-width: 420px; }
  footer { padding: 0 0 28px; color: var(--ink-3); font-size: 12.5px; }
  @media (max-width: 560px) {
    main { padding: 20px 0 32px; }
    .top-in, .wrap { padding: 0 14px; }
    .head { padding: 18px 16px 16px; gap: 12px; }
    .badge { width: 44px; height: 44px; border-radius: 10px; }
    h1 { font-size: 19px; }
    .actions { padding: 0 16px 18px; }
    .actions .btn { width: 100%; justify-content: center; }
    .row { padding: 12px 16px; flex-wrap: wrap; }
    .row .name { flex-basis: calc(100% - 32px); }
    .row .size { margin-left: 32px; }
    .row .dl { margin-left: auto; }
    .empty, .more { padding: 16px; }
  }
</style>
<link rel="stylesheet" href="<?= share_h(share_asset('partilha.css')) ?>">
</head>
<body>
<header class="top">
  <div class="top-in">
    <svg class="wm" viewBox="0 0 432.74 121.88" role="img" aria-label="topper."><path fill="currentColor" fill-rule="nonzero" d="M 64.7 55.0 C 64.7 60.6 66.2 65.1 69.2 68.3 C 72.3 71.6 76.4 73.3 81.5 73.3 C 86.7 73.3 90.8 71.6 93.8 68.3 C 96.9 65.1 98.4 60.7 98.4 55.2 C 98.4 49.8 96.9 45.4 93.8 42.0 C 90.8 38.7 86.7 37.0 81.5 37.0 C 76.4 37.0 72.3 38.7 69.2 42.0 C 66.2 45.4 64.7 49.7 64.7 55.0 M 45.1 54.8 C 45.1 49.8 46.0 45.2 47.8 41.0 C 49.6 36.8 52.1 33.2 55.3 30.1 C 58.6 27.1 62.4 24.7 66.9 22.9 C 71.3 21.2 76.2 20.3 81.5 20.3 C 86.8 20.3 91.7 21.1 96.2 22.8 C 100.6 24.6 104.5 26.9 107.7 30.0 C 111.0 33.1 113.5 36.8 115.3 41.0 C 117.1 45.3 118.0 50.0 118.0 55.2 C 118.0 60.3 117.1 65.0 115.3 69.3 C 113.5 73.6 111.0 77.3 107.7 80.3 C 104.5 83.4 100.6 85.8 96.1 87.5 C 91.6 89.2 86.7 90.1 81.3 90.1 C 76.0 90.1 71.2 89.2 66.7 87.5 C 62.3 85.8 58.5 83.4 55.3 80.3 C 52.1 77.3 49.6 73.5 47.8 69.2 C 46.0 64.8 45.1 60.0 45.1 54.8 M 146.6 55.0 C 146.6 57.6 147.0 59.9 147.8 62.1 C 148.7 64.3 149.8 66.3 151.4 67.9 C 152.9 69.5 154.7 70.8 156.8 71.7 C 158.9 72.7 161.3 73.2 163.8 73.2 C 166.4 73.2 168.7 72.7 170.8 71.8 C 172.9 70.9 174.6 69.6 176.1 68.0 C 177.5 66.4 178.6 64.5 179.5 62.3 C 180.3 60.1 180.7 57.7 180.7 55.2 C 180.7 49.8 179.2 45.3 176.3 41.9 C 173.4 38.5 169.2 36.8 163.9 36.8 C 158.7 36.8 154.5 38.5 151.4 41.9 C 148.2 45.3 146.6 49.7 146.6 55.0 M 147.6 22.0 L 147.4 29.7 L 147.6 29.7 C 150.6 26.6 153.9 24.2 157.3 22.6 C 160.7 21.0 164.5 20.1 168.7 20.1 C 173.4 20.1 177.8 21.1 181.7 22.9 C 185.6 24.8 189.0 27.3 191.7 30.4 C 194.5 33.6 196.7 37.3 198.2 41.5 C 199.6 45.7 200.4 50.3 200.4 55.2 C 200.4 59.9 199.6 64.4 198.1 68.6 C 196.6 72.8 194.4 76.5 191.6 79.6 C 188.8 82.8 185.5 85.3 181.6 87.1 C 177.7 88.9 173.5 89.8 168.8 89.8 C 164.5 89.8 160.7 89.0 157.2 87.5 C 153.8 86.0 150.5 83.6 147.6 80.3 L 147.4 80.3 C 147.6 82.4 147.7 84.3 147.7 86.0 L 147.7 121.8 L 128.1 121.8 L 128.1 22.0 Z M 147.6 22.0 M 228.9 55.0 C 228.9 57.6 229.3 59.9 230.1 62.1 C 230.9 64.3 232.1 66.3 233.6 67.9 C 235.1 69.5 237.0 70.8 239.1 71.7 C 241.2 72.7 243.5 73.2 246.0 73.2 C 248.7 73.2 251.0 72.7 253.1 71.8 C 255.1 70.9 256.9 69.6 258.3 68.0 C 259.8 66.4 260.9 64.5 261.7 62.3 C 262.5 60.1 262.9 57.7 262.9 55.2 C 262.9 49.8 261.5 45.3 258.5 41.9 C 255.6 38.5 251.5 36.8 246.2 36.8 C 241.0 36.8 236.8 38.5 233.6 41.9 C 230.5 45.3 228.9 49.7 228.9 55.0 M 229.8 22.0 L 229.7 29.7 L 229.8 29.7 C 232.9 26.6 236.1 24.2 239.6 22.6 C 243.0 21.0 246.8 20.1 250.9 20.1 C 255.7 20.1 260.0 21.1 263.9 22.9 C 267.9 24.8 271.2 27.3 274.0 30.4 C 276.8 33.6 278.9 37.3 280.4 41.5 C 281.9 45.7 282.6 50.3 282.6 55.2 C 282.6 59.9 281.9 64.4 280.3 68.6 C 278.8 72.8 276.7 76.5 273.9 79.6 C 271.1 82.8 267.7 85.3 263.9 87.1 C 260.0 88.9 255.7 89.8 251.0 89.8 C 246.8 89.8 243.0 89.0 239.5 87.5 C 236.0 86.0 232.8 83.6 229.8 80.3 L 229.7 80.3 C 229.9 82.4 230.0 84.3 230.0 86.0 L 230.0 121.8 L 210.4 121.8 L 210.4 22.0 Z M 229.8 22.0 M 309.2 46.4 L 335.9 46.4 C 335.4 42.8 334.0 40.0 331.7 38.0 C 329.3 36.0 326.3 35.0 322.6 35.0 C 319.0 35.0 316.1 36.1 313.8 38.1 C 311.5 40.2 310.0 43.0 309.2 46.4 M 322.3 20.3 C 327.5 20.3 332.1 21.1 336.2 22.7 C 340.3 24.3 343.8 26.7 346.7 29.7 C 349.5 32.8 351.8 36.5 353.3 40.9 C 354.9 45.3 355.7 50.2 355.7 55.7 L 355.7 59.2 L 308.4 59.2 C 308.8 64.1 310.2 68.0 312.7 70.9 C 315.2 73.9 318.7 75.3 323.3 75.3 C 326.4 75.3 329.2 74.6 331.5 73.0 C 333.8 71.5 335.8 69.1 337.6 65.8 L 354.8 71.5 C 351.8 77.7 347.5 82.4 341.9 85.5 C 336.3 88.5 330.0 90.1 323.0 90.1 C 317.9 90.1 313.2 89.2 309.0 87.6 C 304.9 86.0 301.2 83.7 298.2 80.7 C 295.1 77.6 292.7 74.0 291.1 69.7 C 289.4 65.4 288.6 60.6 288.6 55.3 C 288.6 50.0 289.4 45.2 291.1 40.8 C 292.7 36.5 295.1 32.8 298.0 29.8 C 301.0 26.8 304.6 24.4 308.7 22.8 C 312.8 21.1 317.4 20.3 322.3 20.3 M 365.8 22.0 L 385.4 22.0 L 385.2 28.8 L 385.6 28.8 C 388.1 25.7 390.5 23.5 392.9 22.2 C 395.3 20.9 398.3 20.3 402.0 20.3 C 404.9 20.3 408.2 21.3 411.9 23.3 L 406.6 42.0 C 405.2 41.1 403.7 40.3 402.3 39.7 C 400.9 39.1 399.3 38.8 397.6 38.8 C 389.5 38.8 385.4 44.6 385.4 56.1 L 385.4 88.2 L 365.8 88.2 Z M 365.8 22.0 M 409.2 79.0 C 409.2 75.7 410.4 73.1 412.7 71.0 C 415.0 68.9 417.8 67.9 420.9 67.9 C 424.1 67.9 426.8 68.9 429.2 71.0 C 431.5 73.1 432.7 75.7 432.7 79.0 C 432.7 82.2 431.5 84.9 429.2 86.9 C 426.8 89.0 424.1 90.1 420.9 90.1 C 417.8 90.1 415.0 89.0 412.7 86.9 C 410.4 84.9 409.2 82.2 409.2 79.0 M 10.5 88.2 L 30.0 88.2 L 30.0 48.7 L 10.5 48.7 Z M 10.5 88.2 M 31.3 23.8 L 31.3 0.0 L 10.5 0.0 L 10.5 23.8 C 10.5 24.6 9.8 25.2 9.1 25.0 L 1.4 23.6 C 0.6 23.5 0.0 24.0 0.0 24.8 L 0.0 40.7 L 41.8 40.7 L 41.8 24.8 C 41.8 24.0 41.1 23.5 40.4 23.6 L 32.7 25.0 C 32.0 25.2 31.3 24.6 31.3 23.8"/></svg>
    <span class="tag">Partilha</span>
  </div>
</header>
<div class="band" aria-hidden="true"></div>
<main>
<div class="wrap">
<?php
}

function share_foot(): void {
    ?>
</div>
</main>
<footer><div class="wrap">Topper Digital · Partilhado a partir do Drive.</div></footer>
</body>
</html>
<?php
}

/** A mesma página para tudo o que não serve: não diz se o link existiu, expirou ou foi revogado. */
function share_gone(int $code): void {
    http_response_code($code);
    share_head('Link indisponível');
    echo '<section class="card gone"><div class="badge">', share_icon('other'), '</div>',
         '<h1>Este link expirou ou não existe.</h1>',
         '<p>Se ainda precisa do ficheiro, peça um novo link a quem o enviou.</p></section>';
    share_foot();
    exit;
}

function share_failed(): void {
    http_response_code(502);
    share_head('Indisponível');
    echo '<section class="card gone"><div class="badge">', share_icon('other'), '</div>',
         '<h1>Não foi possível abrir este link agora.</h1>',
         '<p>Tente outra vez daqui a alguns minutos.</p></section>';
    share_foot();
    exit;
}

// ── qual partilha? ──────────────────────────────────────────
$t = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
$s = $t !== '' && r2_config() ? drive_share_by_token($pdo, $t) : null;
if ($s === null || drive_hidden($s['k'])) share_gone(404);
if ($s['state'] !== 'ok') share_gone(410);
// ── password: o link pode pedir uma; a prova é um cookie que só quem a deu recebe ──
$unlocked = !$s['protected'];
$isJson = $_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');
if (!$unlocked && $isJson) {   // as ações de edição (§32.2) só depois de a password ter sido dada nesta página
    $ck = 'td_sh_' . substr(hash('sha256', $s['token']), 0, 16);
    if (!(isset($_COOKIE[$ck]) && is_string($_COOKIE[$ck]) && hash_equals(drive_share_pw_cookie($s), $_COOKIE[$ck]))) {
        http_response_code(401); header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['ok' => false, 'error' => 'Abra o link com a password primeiro.']));
    }
    $unlocked = true;
}
if (!$unlocked) {
    $ck = 'td_sh_' . substr(hash('sha256', $s['token']), 0, 16);
    $unlocked = isset($_COOKIE[$ck]) && is_string($_COOKIE[$ck]) && hash_equals(drive_share_pw_cookie($s), $_COOKIE[$ck]);
    $pwError = '';
    if (!$unlocked && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $wait = drive_share_pw_wait($s);
        if ($wait > 0) $pwError = 'Demasiadas tentativas. Tente outra vez dentro de ' . max(1, (int)ceil($wait / 60)) . ' min.';
        elseif (drive_share_pw_check($pdo, $s, is_string($_POST['pw'] ?? null) ? $_POST['pw'] : '')) {
            setcookie($ck, drive_share_pw_cookie($s), ['expires' => 0, 'path' => '/admin/', 'secure' => !IS_LOCAL, 'httponly' => true, 'samesite' => 'Lax']);
            share_go('partilha.php?t=' . rawurlencode($t));   // POST → GET: recarregar não repete o envio
        } else {
            $pwError = drive_share_pw_wait(drive_share_by_token($pdo, $t) ?? $s) > 0
                ? 'Demasiadas tentativas. Tente outra vez dentro de ' . (int)ceil(DRIVE_SHARE_PW_LOCK / 60) . ' min.' : 'Password errada.';
        }
    } elseif (!$unlocked && drive_share_pw_wait($s) > 0) {
        $pwError = 'Demasiadas tentativas. Tente outra vez dentro de ' . max(1, (int)ceil(drive_share_pw_wait($s) / 60)) . ' min.';
    }
    if (!$unlocked) {
        http_response_code($pwError !== '' ? 401 : 200);
        share_head('Link protegido');
        ?>
  <section class="card gone">
    <div class="badge"><?= share_icon('lock') ?></div>
    <h1>Este link tem password.</h1>
    <p>Escreva a password que lhe enviaram para ver <?= $s['folder'] ? 'a pasta' : 'o ficheiro' ?>.</p>
    <form method="post" action="partilha.php?t=<?= share_h(rawurlencode($t)) ?>" class="pw">
      <input type="password" name="pw" autocomplete="off" required autofocus maxlength="1000" aria-label="Password" <?= $pwError !== '' ? 'aria-invalid="true"' : '' ?>>
      <button class="btn" type="submit">Abrir</button>
    </form>
    <?php if ($pwError !== ''): ?><p class="pwerr" role="alert"><?= share_h($pwError) ?></p><?php endif; ?>
  </section>
<?php
        share_foot();
        exit;
    }
}
$left = $s['forever'] ? 31536000 : $s['exp'] - time();   // sem prazo: o limite dos URLs curtos é só o do tipo de ficheiro

// ── o que quem tem um link «Pode editar» faz (§32.2): POST em JSON com o token do formulário da página ──
// Carregar, criar pastas, renomear, substituir (a anterior fica no histórico). Não há nenhuma ação que apague.
require_once __DIR__ . '/inc/drive_share.php';
if ($isJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $out = ['ok' => false, 'error' => 'Erro.']; $code = 500;
    if (!hash_equals(drive_share_form_token($s), (string)($_SERVER['HTTP_X_SHARE_FORM'] ?? ''))) { $code = 403; $out['error'] = 'Recarregue a página e tente outra vez.'; }
    elseif (!is_array($in = json_decode((string)file_get_contents('php://input'), true))) { $code = 400; $out['error'] = 'Pedido inválido.'; }
    else {
        try { [$code, $out] = drive_share_visitor($pdo, $s, $in); }
        catch (RuntimeException $e) { $c = (int)$e->getCode(); $code = $c >= 400 && $c < 600 ? $c : 500; $out = ['ok' => false, 'error' => $e->getMessage()]; }
        catch (Throwable $e) { error_log('partilha edit: ' . $e->getMessage()); $code = 500; $out = ['ok' => false, 'error' => 'Não foi possível concluir agora. Tente outra vez.']; }
    }
    http_response_code($code);
    exit(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
$ft = drive_share_form_token($s);

try {
    // ── um ficheiro ─────────────────────────────────────────
    if (!$s['folder']) {
        $key = $s['k'];
        $h = r2_head($key);
        if ($h === null) share_gone(404);
        if (!empty($_GET['dl'])) {
            drive_share_hit($pdo, $s['id']);
            share_go(r2_get_url($key, false, '', SHARE_DL_TTL));
        }
        $name = drive_base($key);
        [$label, $icon, $pv] = share_kind($name);
        if (!empty($_GET['pv'])) {
            if ($pv === '') share_gone(404);
            share_go(r2_get_url($key, true, r2_mime($name), max(60, min(SHARE_PREVIEW_TTL[$pv] ?? 600, $left))));
        }
        $dl = 'partilha.php?t=' . rawurlencode($t) . '&dl=1';
        $src = $pv !== '' ? 'partilha.php?t=' . rawurlencode($t) . '&pv=1' : '';
        share_head($name);
        ?>
  <section class="card" data-ft="<?= share_h($ft) ?>" data-perm="<?= share_h($s['perm']) ?>">
    <div class="head">
      <div class="badge"><?= share_icon($icon) ?></div>
      <div>
        <p class="kicker">Ficheiro partilhado</p>
        <h1><?= share_h($name) ?></h1>
        <p class="meta"><b><?= share_h(drive_size((int)$h['size'])) ?></b> · <?= share_h($label) ?> · <?= share_h(share_valid($s)) ?></p>
      </div>
    </div>
    <div class="actions">
      <a class="btn" href="<?= share_h($dl) ?>" rel="nofollow"><?= share_icon('download') ?> Descarregar</a>
    </div>
    <?php if ($pv === 'image'): ?>
      <div class="preview light"><img src="<?= share_h($src) ?>" alt="<?= share_h($name) ?>"></div>
    <?php elseif ($pv === 'video'): ?>
      <div class="preview"><video src="<?= share_h($src) ?>" controls playsinline preload="metadata"></video></div>
    <?php elseif ($pv === 'audio'): ?>
      <div class="preview light"><audio src="<?= share_h($src) ?>" controls preload="metadata"></audio></div>
    <?php elseif ($pv === 'pdf'): ?>
      <div class="preview light"><iframe src="<?= share_h($src) ?>" title="<?= share_h($name) ?>"></iframe></div>
    <?php endif; ?>
  </section>
<?php
        share_foot();
        exit;
    }

    // ── uma pasta (§32.3): grelha com miniaturas, subpastas (&p=), pré-visualização (&pv=1&f=) e miniaturas (&th=1&f=) ──
    $prefix = $s['k'];
    $tq = 'partilha.php?t=' . rawurlencode($t);
    /** Um caminho pedido (relativo à pasta partilhada) → a chave, se existir neste link; senão a página «não existe». */
    $inside = static function (string $rel, bool $folder) use ($prefix, $s): string {
        $key = drive_share_rel($prefix, $rel, $folder);
        if ($key === null || !drive_share_sees($s, $key)) share_gone(404);
        return $key;
    };
    if (isset($_GET['f'])) {
        $key = $inside(is_string($_GET['f']) ? $_GET['f'] : '', false);
        $h = r2_head($key);
        if ($h === null) share_gone(404);
        $name = drive_base($key);
        if (!empty($_GET['th'])) {   // a miniatura da Drive (por ETag) se existir; uma imagem serve-se a si própria
            $tk = DRIVE_THUMBS . preg_replace('/[^A-Za-z0-9_-]/', '', trim((string)($h['etag'] ?? ''), '"')) . '.jpg';
            [, $icon] = share_kind($name);
            if (r2_head($tk) !== null) share_go(r2_get_url($tk, true, 'image/jpeg', max(60, min(3600, $left))));
            if ($icon === 'image') share_go(r2_get_url($key, true, r2_mime($name), max(60, min(SHARE_PREVIEW_TTL['image'], $left))));
            share_gone(404);
        }
        if (!empty($_GET['pv'])) {
            [, , $pv] = share_kind($name);
            if ($pv === '') share_gone(404);
            share_go(r2_get_url($key, true, r2_mime($name), max(60, min(SHARE_PREVIEW_TTL[$pv] ?? 600, $left))));
        }
        drive_share_hit($pdo, $s['id']);
        share_go(r2_get_url($key, false, '', SHARE_DL_TTL));
    }
    $sub = is_string($_GET['p'] ?? null) && $_GET['p'] !== '' ? $_GET['p'] : '';
    $cur = $sub === '' ? $prefix : $inside($sub, true);
    $sub = substr($cur, strlen($prefix));   // normalizado, com «/» no fim
    $ls = r2_list($cur, 20);                 // só este nível, sempre atual
    $folders = $files = [];
    foreach ($ls['folders'] as $fk) {
        if (!drive_share_sees($s, $fk) || strncmp($fk, $cur, strlen($cur)) !== 0) continue;
        $folders[] = ['name' => rtrim(substr($fk, strlen($cur)), '/'), 'rel' => substr($fk, strlen($prefix))];
    }
    foreach ($ls['files'] as $o) {
        if (!drive_share_sees($s, $o['key']) || strpos($o['name'], '/') !== false) continue;
        [$label, $icon, $pv] = share_kind($o['name']);
        $files[] = ['name' => $o['name'], 'rel' => substr($o['key'], strlen($prefix)), 'size' => (int)$o['size'], 'label' => $label, 'kind' => $icon, 'pv' => $pv];
    }
    if (!$folders && !$files && !drive_folder_exists($cur)) share_gone(404);
    usort($folders, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    usort($files, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    $max = 2000;
    $more = max(0, count($files) - $max);
    $files = array_slice($files, 0, $max);
    $total = array_sum(array_column($files, 'size'));
    $name = drive_base($prefix);
    $edit = drive_share_allows($s, 'upload');
    $count = [];
    if ($folders) $count[] = count($folders) === 1 ? '1 pasta' : count($folders) . ' pastas';
    $count[] = count($files) === 1 ? '1 ficheiro' : count($files) . ' ficheiros';
    // o que o visualizador precisa (sem URLs do armazenamento: cada pedido volta a passar por aqui)
    $data = ['base' => $tq, 'sub' => $sub, 'edit' => $edit, 'files' => array_map(static fn($f) => [
        'n' => $f['name'], 'r' => $f['rel'], 's' => drive_size($f['size']), 'l' => $f['label'], 'k' => $f['kind'], 'pv' => $f['pv']], $files)];
    share_head($sub === '' ? $name : drive_base(rtrim($cur, '/')));
    ?>
  <section class="card sh-page" data-ft="<?= share_h($ft) ?>" data-perm="<?= share_h($s['perm']) ?>">
    <div class="head">
      <div class="badge"><?= share_icon('folder') ?></div>
      <div class="head-txt">
        <p class="kicker"><?= $edit ? 'Pasta partilhada · pode editar' : 'Pasta partilhada' ?></p>
        <h1><?= share_h($name) ?></h1>
        <p class="meta"><b><?= share_h(implode(' · ', $count)) ?></b> · <?= share_h(drive_size($total)) ?> · <?= share_h(share_valid($s)) ?></p>
      </div>
    </div>
    <?php if ($sub !== ''): ?>
    <nav class="sh-crumbs" aria-label="Caminho">
      <a href="<?= share_h($tq) ?>"><?= share_h($name) ?></a>
      <?php $acc = ''; foreach (array_filter(explode('/', rtrim($sub, '/')), 'strlen') as $seg): $acc .= $seg . '/'; ?>
        <span aria-hidden="true">›</span><a href="<?= share_h($tq . '&p=' . rawurlencode($acc)) ?>"<?= $acc === $sub ? ' aria-current="page"' : '' ?>><?= share_h($seg) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <?php if ($edit): ?>
      <div class="sh-tools">
        <label class="sh-who"><span>O seu nome</span><input id="sh-who" type="text" maxlength="80" autocomplete="name" placeholder="Para quem partilhou saber quem enviou"></label>
        <button type="button" class="btn" data-up><?= share_icon('upload') ?> Carregar ficheiros</button>
        <button type="button" class="btn ghost" data-mkdir><?= share_icon('folder') ?> Nova pasta</button>
        <input type="file" multiple hidden data-up-input>
        <p class="sh-note">Pode carregar, criar pastas, renomear e substituir. Nada se apaga por este link; as versões substituídas ficam guardadas.</p>
        <p class="sh-status" role="status" aria-live="polite" data-status></p>
      </div>
    <?php endif; ?>
    <?php if (!$folders && !$files): ?>
      <p class="empty">Esta pasta está vazia.</p>
    <?php else: ?>
      <div class="sh-grid">
      <?php foreach ($folders as $fo): ?>
        <div class="sh-cell">
          <a class="sh-folder" href="<?= share_h($tq . '&p=' . rawurlencode($fo['rel'])) ?>">
            <span class="sh-thumb"><?= share_icon('folder') ?></span>
            <span class="sh-name"><?= share_h($fo['name']) ?></span>
          </a>
          <?php if ($edit): ?><button type="button" class="sh-more" data-rename="<?= share_h($fo['rel']) ?>" data-name="<?= share_h($fo['name']) ?>" aria-label="Renomear <?= share_h($fo['name']) ?>">Renomear</button><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php foreach ($files as $i => $fi): $th = $tq . '&th=1&f=' . rawurlencode($fi['rel']); ?>
        <div class="sh-cell">
          <button type="button" class="sh-card" data-kind="<?= share_h($fi['kind']) ?>" data-i="<?= $i ?>" aria-label="Abrir <?= share_h($fi['name']) ?>">
            <span class="sh-thumb">
              <?php if ($fi['kind'] === 'image'): ?>
                <img src="<?= share_h($th) ?>" alt="" loading="lazy" decoding="async">
              <?php elseif ($fi['kind'] === 'video' || $fi['kind'] === 'pdf'): ?>
                <img src="<?= share_h($th) ?>" alt="" loading="lazy" decoding="async" data-fallback="<?= share_h($fi['kind']) ?>"><span class="sh-ico"><?= share_icon($fi['kind']) ?></span>
              <?php else: ?>
                <span class="sh-ico"><?= share_icon($fi['kind']) ?></span>
              <?php endif; ?>
              <?php if ($fi['kind'] === 'video'): ?><span class="sh-play" aria-hidden="true"></span><?php endif; ?>
            </span>
            <span class="sh-name"><?= share_h($fi['name']) ?></span>
            <span class="sh-size"><?= share_h(drive_size($fi['size'])) ?></span>
          </button>
          <?php if ($edit): ?>
            <button type="button" class="sh-more" data-rename="<?= share_h($fi['rel']) ?>" data-name="<?= share_h($fi['name']) ?>" aria-label="Renomear <?= share_h($fi['name']) ?>">Renomear</button>
            <button type="button" class="sh-more" data-replace="<?= share_h($fi['rel']) ?>" aria-label="Substituir <?= share_h($fi['name']) ?>">Substituir</button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
      <?php if ($more): ?>
        <p class="more">E mais <?= $more ?> ficheiros. Peça a quem partilhou um link para uma subpasta.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
  <div class="sh-viewer" hidden role="dialog" aria-modal="true" aria-label="Pré-visualização">
    <div class="sh-v-bar">
      <span class="sh-v-name"></span><span class="sh-v-pos"></span>
      <a class="sh-v-btn" data-v-dl href="#" rel="nofollow"><?= share_icon('download') ?><span>Descarregar</span></a>
      <button type="button" class="sh-v-btn" data-v-close aria-label="Fechar">✕</button>
    </div>
    <div class="sh-v-stage"></div>
    <button type="button" class="sh-v-nav prev" data-v-prev aria-label="Anterior">‹</button>
    <button type="button" class="sh-v-nav next" data-v-next aria-label="Seguinte">›</button>
  </div>
  <script type="application/json" id="sh-data"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script src="<?= share_h(share_asset('partilha.js')) ?>" defer></script>
<?php
    share_foot();
} catch (R2Exception $e) {
    error_log('partilha: ' . $e->getMessage());
    share_failed();
}
