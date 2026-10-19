<?php
/**
 * topper. admin — a pasta «Topper Digital» no Google Drive
 *
 * Usa a mesma aplicação web do Apps Script que a folha (sheets/Code.gs,
 * ações drive_*), por isso não há credenciais novas. O Code.gs só deixa
 * mexer dentro da pasta raiz; aqui só se valida o formato dos ids.
 *
 * Precisa de sheet.php (sheet_call, SheetException).
 */

declare(strict_types=1);

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

const DRIVE_ROOT_ID     = '11mgqHYd1oqLvOMQdj6C0R-Mgtn9WNXNF';
const DRIVE_MAX_BYTES   = 25 * 1024 * 1024;
const DRIVE_TOO_BIG     = 'O ficheiro tem mais de 25 MB, o máximo que passa por aqui. '
                        . 'Para vídeos grandes use «Abrir no Drive» e carregue o ficheiro diretamente no Google Drive.';

/** Um id do Drive com aspeto válido (letras, números, - e _). */
function drive_id_ok(string $id): bool {
    return preg_match('/^[A-Za-z0-9_-]{10,100}$/', $id) === 1;
}

/** sheet_call para as ações drive_*, com a mensagem certa se o Code.gs for antigo. */
function drive_call(string $method, array $params, int $timeout = 30): array {
    try {
        return sheet_call($method, $params, $timeout);
    } catch (SheetException $e) {
        if (strpos($e->getMessage(), 'A folha não conhece este pedido') === 0) {
            throw new SheetException(
                'O Code.gs implementado ainda não tem as funções do Drive. Atualize o Code.gs '
              . '(cole a versão nova, autorize o Drive e faça Implementar > Gerir implementações > Nova versão).'
            );
        }
        // Tirar o prefixo genérico da folha: aqui fala-se do Drive.
        $m = preg_replace('/^A folha Google (devolveu um erro|recusou a alteração): /u', '', $e->getMessage());
        throw new SheetException((string)$m);
    }
}

function drive_list(string $folder = ''): array {
    $res = drive_call('GET', ['action' => 'drive_list'] + ($folder !== '' ? ['folder' => $folder] : []));
    if (!isset($res['folder']['id']) || !is_array($res['children'] ?? null)) {
        throw new SheetException('O Drive respondeu sem a lista da pasta. Atualize o Code.gs.');
    }
    return $res;
}

function drive_mkdir(string $parent, string $name): array {
    return (array)(drive_call('POST', ['action' => 'drive_mkdir', 'parent' => $parent, 'name' => $name])['folder'] ?? []);
}

function drive_upload(string $parent, string $name, string $mime, string $path): array {
    $size = @filesize($path);
    if ($size === false) throw new SheetException('Não foi possível ler o ficheiro enviado.');
    if ($size > DRIVE_MAX_BYTES) throw new SheetException(DRIVE_TOO_BIG);
    $data = @file_get_contents($path);
    if ($data === false) throw new SheetException('Não foi possível ler o ficheiro enviado.');
    $res = drive_call('POST', [
        'action' => 'drive_upload', 'parent' => $parent, 'name' => $name,
        'mimeType' => $mime, 'base64' => base64_encode($data),
    ], 180);
    return (array)($res['file'] ?? []);
}

/** Garante 02 Projetos/<ano>/<nome>/ (com Vídeos, Faturas, Documentos). */
function drive_project(string $name, int $year): array {
    $f = (array)(drive_call('POST', ['action' => 'drive_project', 'name' => $name, 'year' => (string)$year], 60)['folder'] ?? []);
    if (!drive_id_ok((string)($f['id'] ?? ''))) throw new SheetException('O Drive não devolveu a pasta do trabalho. Atualize o Code.gs.');
    return $f;
}

function drive_trash(string $id): void {
    drive_call('POST', ['action' => 'drive_trash', 'id' => $id]);
}

/** «1,2 MB». */
function drive_size(int $b): string {
    if ($b <= 0) return '—';
    $u = ['B', 'KB', 'MB', 'GB']; $i = 0; $n = (float)$b;
    while ($n >= 1024 && $i < 3) { $n /= 1024; $i++; }
    return ($i === 0 ? (string)$b : number_format($n, $n < 10 ? 1 : 0, ',', ' ')) . ' ' . $u[$i];
}

/** Bytes de um valor do php.ini («32M»). */
function drive_ini_bytes(string $v): int {
    $v = trim($v);
    if ($v === '') return 0;
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024; // no break
        case 'm': $n *= 1024; // no break
        case 'k': $n *= 1024;
    }
    return $n;
}

/** O limite real de upload deste servidor (o menor dos dois). */
function drive_php_limit(): int {
    $a = drive_ini_bytes((string)ini_get('upload_max_filesize'));
    $b = drive_ini_bytes((string)ini_get('post_max_size'));
    $l = array_filter([$a, $b], static fn($x) => $x > 0);
    return $l ? min($l) : 0;
}

/** Uma etiqueta curta para o tipo do ficheiro. */
function drive_kind(string $mime, bool $folder): array {
    if ($folder) return ['Pasta', '▣'];
    $map = [
        'application/vnd.google-apps.document'     => ['Doc', '≡'],
        'application/vnd.google-apps.spreadsheet'  => ['Folha', '▦'],
        'application/vnd.google-apps.presentation' => ['Slides', '▭'],
        'application/pdf'                          => ['PDF', '▤'],
        'application/zip'                          => ['ZIP', '◫'],
    ];
    if (isset($map[$mime])) return $map[$mime];
    if (strpos($mime, 'video/') === 0) return ['Vídeo', '▶'];
    if (strpos($mime, 'image/') === 0) return ['Imagem', '◩'];
    if (strpos($mime, 'audio/') === 0) return ['Áudio', '♪'];
    if (strpos($mime, 'text/') === 0)  return ['Texto', '≡'];
    return ['Ficheiro', '□'];
}
