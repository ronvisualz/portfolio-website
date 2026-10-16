<?php
/**
 * topper. admin — a folha Google como base de dados
 *
 * O Painel, a Carteira e as Leads leem e escrevem na folha «TOPPER DIGITAL»
 * através da aplicação web do Apps Script (sheets/Code.gs). O contrato
 * (separadores, colunas, pedidos) está em sheets/CONTRACT.md.
 *
 *   sheet_data()               lê tudo (com cache curta) e devolve arrays prontos
 *   sheet_update/append/delete escrevem uma linha e limpam a cache
 *
 * Qualquer falha — folha por configurar, rede, token errado, resposta
 * estranha, «a folha mudou entretanto» — chega às páginas como uma
 * SheetException com uma mensagem em português que se pode mostrar tal
 * e qual ao utilizador.
 *
 * Precisa de bootstrap.php (MONTHS_PT, money_in, e()…) e layout.php (flash).
 */

declare(strict_types=1);

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

/** Erro da folha, já com uma mensagem legível. */
class SheetException extends RuntimeException {}

/** A taxa que está nas fórmulas da folha. Os números do site seguem a folha. */
const SHEET_VAT = 23.0;

/** Segundos durante os quais uma leitura é reaproveitada. */
const SHEET_TTL = 10;

/** Endereço da folha, quando o sheet_config.php não diz outro. */
const SHEET_DEFAULT_URL = 'https://docs.google.com/spreadsheets/d/1ANl0YVMQ4gDziF9BuMp3Btrd_1QCzW_B1gPNDAWo2mY/edit';

/** Colunas que o site usa em cada separador. Faltando uma, a página explica qual. */
const SHEET_COLUMNS = [
    'Trabalhos'      => ['Ano', 'Mês', 'Nome', 'Tipo', 'Budget (s/ IVA)', 'Internacional',
                         'Fatura com IVA', 'Gastos manuais', 'Pago', 'Notas',
                         'Budget (c/ IVA)', 'Gastos', 'Lucro'],
    'Equipa'         => ['Trabalho', 'Pessoa', 'Função', 'Valor', 'Tipo de IVA', 'Fechado',
                         'Contacto', 'Notas', 'Valor s/ IVA', 'IVA', 'Total'],
    'Custos empresa' => ['Ano', 'Mês', 'Nome', 'Valor', 'Tipo de IVA', 'Pago', 'Notas',
                         'Valor s/ IVA', 'IVA', 'Total'],
    'Leads'          => ['Nome', 'Tipo', 'Contactos (redes / site)', 'Telefone', 'Fase',
                         'Serviço', 'Prioridade', 'Detalhe', 'Porquê abordar', 'Email',
                         'Notas', 'Último toque'],
];

/**
 * O que se escreve na coluna «Tipo de IVA». O site continua a mostrar
 * «+ IVA» (VAT_MODES), mas na folha tem de ser «Acresce IVA»: uma célula
 * que começa por «+» passa a ser uma fórmula.
 */
const SHEET_VAT_LABELS = [
    'none' => 'Sem IVA',
    'plus' => 'Acresce IVA',
    'incl' => 'IVA incluído',
];

const JOB_TYPES       = ['Animação 3D', 'VFX', 'Edição', 'Produção Completa', 'Fotografia'];
const LEAD_TYPES      = ['Produtora', 'Agência de Marketing', 'Empresa Particular', 'Realizador', 'VFX company'];
const LEAD_SERVICES   = ['VFX / 3D', 'Marketing Buddy', 'Ambos'];
const LEAD_PRIORITIES = ['Alta', 'Média', 'Baixa'];
const LEAD_STAGES = [
    'novo'        => 'Novo',
    'contactado'  => 'Contactado',
    'em_conversa' => 'Em conversa',
    'proposta'    => 'Proposta enviada',
    'ganho'       => 'Ganho',
    'perdido'     => 'Perdido',
];

// ── configuração ────────────────────────────────────────────

/** O conteúdo de sheet_config.php, ou null quando o ficheiro não existe. */
function sheet_config_raw(): ?array {
    static $cfg = false;
    if ($cfg !== false) return $cfg;
    $file = __DIR__ . '/sheet_config.php';
    if (!is_file($file)) return $cfg = null;
    $c = require $file;
    return $cfg = is_array($c) ? $c : [];
}

/** Endereço e token da aplicação web. Lança SheetException se faltar algo. */
function sheet_config(): array {
    $c = sheet_config_raw();
    if ($c === null) {
        throw new SheetException(
            'A ligação à folha Google ainda não está configurada: falta o ficheiro '
          . 'admin/inc/sheet_config.php no servidor. Copie sheet_config.example.php para '
          . 'esse nome e preencha o endereço da aplicação web (Apps Script) e o token.'
        );
    }
    $url   = trim((string)($c['url'] ?? ''));
    $token = (string)($c['token'] ?? '');
    $parts = parse_url($url);
    $host  = strtolower((string)($parts['host'] ?? ''));
    $local = in_array($host, ['127.0.0.1', 'localhost'], true);
    $okUrl = $url !== '' && strpos($url, '…') === false && is_array($parts)
          && (($parts['scheme'] ?? '') === 'https' || (($parts['scheme'] ?? '') === 'http' && $local));
    if (!$okUrl) {
        throw new SheetException(
            'O ficheiro admin/inc/sheet_config.php está incompleto: o «url» tem de ser o endereço '
          . 'https da aplicação web do Apps Script (o que termina em /exec).'
        );
    }
    if (strlen($token) < 16 || strpos($token, '…') !== false || $token === '__TOKEN__') {
        throw new SheetException(
            'O ficheiro admin/inc/sheet_config.php está incompleto: o «token» tem de ser uma '
          . 'palavra-passe longa (16 caracteres ou mais), igual à do TOKEN no Code.gs.'
        );
    }
    return ['url' => $url, 'token' => $token];
}

/** O endereço para abrir a folha no browser. Nunca falha. */
function sheet_link(): string {
    $c = sheet_config_raw() ?? [];
    $u = trim((string)($c['sheet_url'] ?? ''));
    return preg_match('~^https://docs\.google\.com/~', $u) ? $u : SHEET_DEFAULT_URL;
}

// ── HTTP ────────────────────────────────────────────────────

/**
 * Um pedido à aplicação web. Devolve a resposta já descodificada e com
 * ok:true; tudo o resto vira SheetException.
 *
 * O Apps Script responde sempre com um 302 para script.googleusercontent.com.
 * O cURL segue-o; depois de um POST, um 301/302/303 é seguido com GET
 * (CURLOPT_POSTREDIR = 0, o comportamento por omissão), que é exatamente o
 * que o Google espera — o POST já foi executado e o GET vai buscar a resposta.
 */
function sheet_call(string $method, array $params): array {
    $cfg = sheet_config();
    if (!function_exists('curl_init')) {
        throw new SheetException('Este servidor não tem a extensão cURL do PHP ativa, que é precisa para falar com a folha Google.');
    }

    $url  = $cfg['url'];
    $body = null;
    if ($method === 'GET') {
        $url .= (strpos($url, '?') === false ? '?' : '&')
              . http_build_query(['token' => $cfg['token']] + $params);
    } else {
        $body = json_encode(['token' => $cfg['token']] + $params,
                            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) throw new SheetException('Não foi possível preparar os dados para a folha.');
    }

    $headers = ['Accept: application/json'];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'topper-admin/1.0',
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $body;
        $opts[CURLOPT_POSTREDIR]  = 0;   // 302 depois do POST → GET
        $headers[] = 'Content-Type: application/json';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0 || $raw === false) {
        error_log('sheet: curl ' . $errno . ' ' . $err);
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new SheetException('A folha Google demorou demasiado a responder. Tente outra vez daqui a pouco.');
        }
        throw new SheetException('Não foi possível falar com a folha Google (' . $err . '). Tente outra vez daqui a pouco.');
    }
    $raw = (string)$raw;
    if ($code >= 400) {
        error_log('sheet: HTTP ' . $code);
        throw new SheetException(
            'A folha Google respondeu com o erro HTTP ' . $code . '. '
          . ($code === 404 ? 'Confirme o endereço da aplicação web em sheet_config.php.' : 'Tente outra vez daqui a pouco.')
        );
    }

    $res = json_decode($raw, true);
    if (!is_array($res)) {
        error_log('sheet: resposta não-JSON (HTTP ' . $code . ', ' . strlen($raw) . ' bytes)');
        throw new SheetException(
            stripos($raw, '<html') !== false
              ? 'A folha Google devolveu uma página em vez de dados. Confirme que a aplicação web do '
              . 'Apps Script está implementada com acesso «Qualquer pessoa» e que o endereço em sheet_config.php é o certo.'
              : 'A folha Google devolveu uma resposta que não se percebe. Tente outra vez daqui a pouco.'
        );
    }
    if (($res['ok'] ?? false) !== true) {
        $msg = trim((string)($res['error'] ?? ''));
        if ($msg === 'token') {
            throw new SheetException('A folha recusou o pedido: o token em sheet_config.php não é igual ao TOKEN do Code.gs.');
        }
        if ($msg === 'action') {
            throw new SheetException('A folha não conhece este pedido. Confirme que o Code.gs implementado é a versão atual.');
        }
        if ($msg === '') $msg = 'erro desconhecido.';
        if (strpos($msg, 'A folha mudou') === 0) throw new SheetException($msg);
        throw new SheetException(($method === 'GET' ? 'A folha Google devolveu um erro: ' : 'A folha Google recusou a alteração: ') . $msg);
    }
    return $res;
}

// ── leitura, com cache ──────────────────────────────────────

function sheet_cache_file(): ?string {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) return null;
        // inc/.htaccess já recusa tudo aqui dentro; isto é uma segunda tranca.
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return is_writable($dir) ? $dir . '/sheet.json' : null;
}

/** Esquece a última leitura — depois de escrever, a próxima página vai à folha. */
function sheet_cache_clear(): void {
    $GLOBALS['__sheet_mem'] = null;
    $GLOBALS['__sheet_data'] = null;
    $f = sheet_cache_file();
    if ($f !== null && is_file($f)) @unlink($f);
}

/**
 * A resposta crua do «read»: ['fetched' => unix, 'at' => …, 'tabs' => …].
 * $fresh salta a cache em disco (não a do próprio pedido).
 */
function sheet_read(bool $fresh = false): array {
    if (!empty($GLOBALS['__sheet_mem'])) return $GLOBALS['__sheet_mem'];

    $file = sheet_cache_file();
    if (!$fresh && $file !== null && is_file($file)) {
        $c = json_decode((string)@file_get_contents($file), true);
        $age = is_array($c) ? time() - (int)($c['fetched'] ?? 0) : -1;
        if (is_array($c) && isset($c['tabs']) && is_array($c['tabs']) && $age >= 0 && $age < SHEET_TTL) {
            return $GLOBALS['__sheet_mem'] = $c;
        }
    }

    $res = sheet_call('GET', ['action' => 'read']);
    if (!isset($res['tabs']) || !is_array($res['tabs'])) {
        throw new SheetException('A folha Google respondeu sem os separadores. Confirme que o Code.gs implementado é a versão atual.');
    }
    $c = ['fetched' => time(), 'at' => (string)($res['at'] ?? ''), 'tabs' => $res['tabs']];

    if ($file !== null) {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($c, JSON_UNESCAPED_UNICODE)) !== false) {
            @chmod($tmp, 0600);
            if (!@rename($tmp, $file)) @unlink($tmp);
        }
    }
    return $GLOBALS['__sheet_mem'] = $c;
}

/** As linhas de um separador, depois de confirmar que as colunas estão lá. */
function sheet_tab(array $read, string $tab): array {
    $t = $read['tabs'][$tab] ?? null;
    if (!is_array($t)) {
        throw new SheetException('A folha Google não tem o separador «' . $tab . '» (ou o nome foi mudado).');
    }
    $header = array_map(static fn($h) => trim((string)$h), (array)($t['header'] ?? []));
    foreach (SHEET_COLUMNS[$tab] as $col) {
        if (!in_array($col, $header, true)) {
            throw new SheetException(
                'No separador «' . $tab . '» da folha falta a coluna «' . $col . '». '
              . 'Os títulos da linha 1 têm de ficar com estes nomes.'
            );
        }
    }
    // Uma linha só com caixas por marcar (FALSE) e colunas calculadas é uma
    // linha vazia da folha — as caixas de seleção vêm preenchidas até ao fim.
    $computed = [];
    foreach ((array)($t['computed'] ?? []) as $i => $c) {
        if ($c && isset($header[$i])) $computed[$header[$i]] = true;
    }
    $rows = [];
    foreach ((array)($t['rows'] ?? []) as $r) {
        if (!is_array($r) || (int)($r['_row'] ?? 0) < 2) continue;
        $filled = false;
        foreach ($r as $k => $v) {
            if ($k === '_row' || isset($computed[$k])) continue;
            if (!sheet_blank($v) && $v !== false) { $filled = true; break; }
        }
        if ($filled) $rows[] = $r;
    }
    return $rows;
}

// ── conversões de célula ────────────────────────────────────

function sheet_blank($v): bool {
    return $v === null || (is_string($v) && trim($v) === '');
}

/** Texto de uma célula. Números inteiros sem «.0». */
function sheet_str($v): string {
    if ($v === null) return '';
    if (is_bool($v)) return $v ? 'TRUE' : 'FALSE';
    if (is_float($v) && floor($v) === $v && abs($v) < 1e15) return (string)(int)$v;
    return trim((string)$v);
}

function sheet_num($v): float {
    if (is_int($v) || is_float($v)) return (float)$v;
    if (is_bool($v) || sheet_blank($v)) return 0.0;
    $s = trim((string)$v);
    if (is_numeric($s)) return (float)$s;
    $neg = preg_match('/^\s*[-−]/u', $s) === 1;
    $n = money_in($s);
    return $neg ? -$n : $n;
}

function sheet_bool($v): bool {
    if (is_bool($v)) return $v;
    if (is_int($v) || is_float($v)) return $v != 0;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['true', 'verdadeiro', 'sim', 'x', '1', 'yes', 'pago', 'fechado'], true);
}

function sheet_fold(string $s): string {
    $s = strtr(trim($s), ['ç' => 'c', 'Ç' => 'c', 'ã' => 'a', 'Ã' => 'a', 'á' => 'a', 'Á' => 'a',
                          'é' => 'e', 'É' => 'e', 'í' => 'i', 'Í' => 'i', 'ó' => 'o', 'Ó' => 'o',
                          'ú' => 'u', 'Ú' => 'u', 'â' => 'a', 'ê' => 'e', 'ô' => 'o']);
    return strtolower($s);
}

/** «Março» (ou 3) → 3. Vazio ou desconhecido → null. */
function sheet_month_in($v): ?int {
    if (is_int($v) || is_float($v)) {
        $n = (int)$v;
        return ($n >= 1 && $n <= 12) ? $n : null;
    }
    $s = sheet_fold((string)$v);
    if ($s === '') return null;
    if (ctype_digit($s)) return sheet_month_in((int)$s);
    foreach (MONTHS_PT as $i => $m) {
        if (sheet_fold($m) === $s) return $i;
    }
    return null;
}

/** 3 → «Março»; nada → "". É o que se escreve na folha. */
function sheet_month_out(?int $m): string {
    return ($m && isset(MONTHS_PT[$m])) ? MONTHS_PT[$m] : '';
}

/** «Acresce IVA» → plus. Aceita também o antigo «+ IVA». */
function sheet_vat_mode($v): string {
    $s = sheet_fold(sheet_str($v));
    if ($s === 'acresce iva' || $s === '+ iva' || $s === '+iva' || $s === 'mais iva') return 'plus';
    if ($s === 'iva incluido') return 'incl';
    return 'none';
}

/** none|plus|incl → o texto da coluna «Tipo de IVA». */
function sheet_vat_label(string $mode): string {
    return SHEET_VAT_LABELS[$mode] ?? SHEET_VAT_LABELS['plus'];
}

/**
 * Texto escrito por uma pessoa, a caminho da folha. Começando por = + - @
 * a folha leria uma fórmula (ou um número, nos telefones); o apóstrofo
 * à frente obriga a ficar texto e não aparece na célula.
 */
function sheet_text(string $s, bool $forceText = false): string {
    $s = trim($s);
    if ($s === '') return '';
    if ($forceText || preg_match('/^[=+\-@]/', $s)) return "'" . $s;
    return $s;
}

// ── o que as páginas usam ───────────────────────────────────

/**
 * Tudo o que a folha tem, já convertido:
 *   jobs, crew, company, leads, years, fetched
 * $fresh null → segue o ?fresh=1 do endereço.
 */
function sheet_data(?bool $fresh = null): array {
    if (!empty($GLOBALS['__sheet_data'])) return $GLOBALS['__sheet_data'];
    if ($fresh === null) $fresh = !empty($_GET['fresh']);

    try {
        $read = sheet_read($fresh);
        $d = sheet_map($read);
    } catch (SheetException $e) {
        throw $e;
    } catch (Throwable $e) {
        error_log('sheet: ' . get_class($e) . ': ' . $e->getMessage());
        throw new SheetException('A resposta da folha Google não tem o formato esperado. Confirme que os separadores e os títulos estão como no contrato.');
    }
    return $GLOBALS['__sheet_data'] = $d;
}

function sheet_map(array $read): array {
    $vat = SHEET_VAT;

    // Equipa
    $crew = [];
    foreach (sheet_tab($read, 'Equipa') as $r) {
        $crew[] = [
            'row'          => (int)$r['_row'],
            'job'          => sheet_str($r['Trabalho'] ?? ''),
            'person'       => sheet_str($r['Pessoa'] ?? ''),
            'role'         => sheet_str($r['Função'] ?? ''),
            'fee'          => sheet_num($r['Valor'] ?? 0),
            'vat_mode'     => sheet_vat_mode($r['Tipo de IVA'] ?? ''),
            'is_confirmed' => sheet_bool($r['Fechado'] ?? false),
            'contact'      => sheet_str($r['Contacto'] ?? ''),
            'notes'        => sheet_str($r['Notas'] ?? ''),
            'net'          => sheet_num($r['Valor s/ IVA'] ?? 0),
            'vat'          => sheet_num($r['IVA'] ?? 0),
            'total'        => sheet_num($r['Total'] ?? 0),
            'job_row'      => null,
        ];
    }
    $crewByJob = [];
    foreach ($crew as $i => $c) $crewByJob[$c['job']][] = $i;

    // Trabalhos
    $jobs = [];
    foreach (sheet_tab($read, 'Trabalhos') as $r) {
        $manualRaw = $r['Gastos manuais'] ?? '';
        $isManual  = !sheet_blank($manualRaw);
        $net       = sheet_num($r['Budget (s/ IVA)'] ?? 0);
        $gross     = sheet_num($r['Budget (c/ IVA)'] ?? 0);
        $hasVat    = sheet_bool($r['Fatura com IVA'] ?? false);
        $manual    = $isManual ? sheet_num($manualRaw) : 0.0;
        $name      = sheet_str($r['Nome'] ?? '');

        $cNet = 0.0; $cVat = 0.0; $cConf = 0.0; $cCount = 0;
        foreach ($crewByJob[$name] ?? [] as $i) {
            $crew[$i]['job_row'] = (int)$r['_row'];
            $cNet += $crew[$i]['net'];
            $cVat += $crew[$i]['vat'];
            if ($crew[$i]['is_confirmed']) $cConf += $crew[$i]['net'];
            $cCount++;
        }
        $jobs[] = [
            'row'              => (int)$r['_row'],
            'kind'             => 'job',
            'name'             => $name,
            'year'             => (int)sheet_num($r['Ano'] ?? 0),
            'start_month'      => sheet_month_in($r['Mês'] ?? ''),
            'type_label'       => sheet_str($r['Tipo'] ?? ''),
            'budget_net'       => $net,
            'budget_gross'     => $gross,
            'is_international' => sheet_bool($r['Internacional'] ?? false),
            'has_vat'          => $hasVat ? 1 : 0,
            'expenses_mode'    => $isManual ? 'manual' : 'crew',
            'expenses_manual'  => $manual,
            'is_paid'          => sheet_bool($r['Pago'] ?? false),
            'notes'            => sheet_str($r['Notas'] ?? ''),
            'expenses'         => sheet_num($r['Gastos'] ?? 0),     // [c]
            'profit'           => sheet_num($r['Lucro'] ?? 0),      // [c]
            'vat_out'          => round($gross - $net, 2),
            'vat_in'           => $isManual
                                    ? ($hasVat ? round($manual * $vat / 100, 2) : 0.0)
                                    : round($cVat, 2),
            'crew_net'         => round($cNet, 2),
            'crew_vat'         => round($cVat, 2),
            'crew_confirmed'   => round($cConf, 2),
            'crew_count'       => $cCount,
        ];
    }
    usort($jobs, 'sheet_cmp_dated');

    // Custos empresa
    $company = [];
    foreach (sheet_tab($read, 'Custos empresa') as $r) {
        $company[] = [
            'row'         => (int)$r['_row'],
            'year'        => (int)sheet_num($r['Ano'] ?? 0),
            'start_month' => sheet_month_in($r['Mês'] ?? ''),
            'name'        => sheet_str($r['Nome'] ?? ''),
            'amount'      => sheet_num($r['Valor'] ?? 0),
            'vat_mode'    => sheet_vat_mode($r['Tipo de IVA'] ?? ''),
            'is_paid'     => sheet_bool($r['Pago'] ?? false),
            'notes'       => sheet_str($r['Notas'] ?? ''),
            'net'         => sheet_num($r['Valor s/ IVA'] ?? 0),
            'vat'         => sheet_num($r['IVA'] ?? 0),
            'total'       => sheet_num($r['Total'] ?? 0),
        ];
    }
    usort($company, 'sheet_cmp_dated');

    // Leads
    $stageFold = array_map('sheet_fold', LEAD_STAGES);
    $leads = [];
    foreach (sheet_tab($read, 'Leads') as $r) {
        $stage = sheet_str($r['Fase'] ?? '');
        $hit   = $stage === '' ? 'novo' : array_search(sheet_fold($stage), $stageFold, true);
        $key   = $hit === false ? '' : (string)$hit;
        $touch = sheet_str($r['Último toque'] ?? '');
        $leads[] = [
            'row'         => (int)$r['_row'],
            'name'        => sheet_str($r['Nome'] ?? ''),
            'types'       => sheet_split(sheet_str($r['Tipo'] ?? ''), ','),
            'contacts'    => sheet_split(sheet_str($r['Contactos (redes / site)'] ?? ''), '|'),
            'phone'       => sheet_str($r['Telefone'] ?? ''),
            'stage'       => $key,                 // '' = fase que o site não conhece
            'stage_label' => $stage === '' ? LEAD_STAGES['novo'] : $stage,
            'service'     => sheet_str($r['Serviço'] ?? ''),
            'priority'    => sheet_str($r['Prioridade'] ?? ''),
            'detail'      => sheet_str($r['Detalhe'] ?? ''),
            'why'         => sheet_str($r['Porquê abordar'] ?? ''),
            'email'       => sheet_str($r['Email'] ?? ''),
            'notes'       => sheet_str($r['Notas'] ?? ''),
            'last_touch'  => $touch,
        ];
    }

    // Anos com registos, mais o corrente
    $years = [(int)date('Y')];
    foreach ($jobs as $j)    if ($j['year'] > 0) $years[] = $j['year'];
    foreach ($company as $c) if ($c['year'] > 0) $years[] = $c['year'];
    $years = array_values(array_unique($years));
    rsort($years);

    return [
        'jobs'    => $jobs,
        'crew'    => $crew,
        'company' => $company,
        'leads'   => $leads,
        'years'   => $years,
        'fetched' => (int)$read['fetched'],
    ];
}

/** Ano mais recente primeiro, depois o mês (sem mês no fim), depois a ordem da folha. */
function sheet_cmp_dated(array $a, array $b): int {
    return [$b['year'], $a['start_month'] ?? 13, $a['row']] <=> [$a['year'], $b['start_month'] ?? 13, $b['row']];
}

/** «a, b» → ['a','b'] sem vazios. */
function sheet_split(string $s, string $sep): array {
    $out = [];
    foreach (explode($sep, $s) as $p) {
        $p = trim($p);
        if ($p !== '') $out[] = $p;
    }
    return $out;
}

function sheet_job_by_row(array $d, int $row): ?array {
    foreach ($d['jobs'] as $j) if ($j['row'] === $row) return $j;
    return null;
}

function sheet_job_by_name(array $d, string $name): ?array {
    foreach ($d['jobs'] as $j) if ($j['name'] === trim($name)) return $j;
    return null;
}

/** As linhas da Equipa de um trabalho, pela ordem da folha. */
function sheet_job_crew(array $d, string $name): array {
    return array_values(array_filter($d['crew'], static fn($c) => $c['job'] === $name));
}

function sheet_crew_by_row(array $d, int $row): ?array {
    foreach ($d['crew'] as $c) if ($c['row'] === $row) return $c;
    return null;
}

function sheet_company_by_row(array $d, int $row): ?array {
    foreach ($d['company'] as $c) if ($c['row'] === $row) return $c;
    return null;
}

function sheet_lead_by_row(array $d, int $row): ?array {
    foreach ($d['leads'] as $l) if ($l['row'] === $row) return $l;
    return null;
}

/** Os números grandes de um ano, com as colunas calculadas da folha. */
function sheet_year_totals(array $d, int $year): array {
    $revenue = 0.0; $costs = 0.0; $unpaid = 0.0; $vatOut = 0.0; $vatIn = 0.0; $count = 0;
    foreach ($d['jobs'] as $j) {
        if ($j['year'] !== $year) continue;
        $count++;
        $revenue += $j['budget_net'];
        $costs   += $j['expenses'];
        $vatOut  += $j['vat_out'];
        $vatIn   += $j['vat_in'];
        if (!$j['is_paid']) $unpaid += $j['budget_net'];
    }
    foreach ($d['company'] as $c) {
        if ($c['year'] !== $year) continue;
        $costs += $c['net'];
        $vatIn += $c['vat'];
    }
    return [
        'revenue' => round($revenue, 2),
        'costs'   => round($costs, 2),
        'profit'  => round($revenue - $costs, 2),
        'unpaid'  => round($unpaid, 2),
        'vat_out' => round($vatOut, 2),
        'vat_in'  => round($vatIn, 2),
        'count'   => $count,
    ];
}

// ── escrita ─────────────────────────────────────────────────

function sheet_update(string $tab, int $row, array $match, array $values): int {
    try {
        $r = sheet_call('POST', ['action' => 'update', 'tab' => $tab, 'row' => $row,
                                 'match' => (object)$match, 'values' => (object)$values]);
    } finally {
        sheet_cache_clear();
    }
    return (int)($r['row'] ?? $row);
}

function sheet_append(string $tab, array $values): int {
    try {
        $r = sheet_call('POST', ['action' => 'append', 'tab' => $tab, 'values' => (object)$values]);
    } finally {
        sheet_cache_clear();
    }
    return (int)($r['row'] ?? 0);
}

function sheet_delete(string $tab, int $row, array $match): void {
    try {
        sheet_call('POST', ['action' => 'delete', 'tab' => $tab, 'row' => $row, 'match' => (object)$match]);
    } finally {
        sheet_cache_clear();
    }
}

/**
 * Corre uma escrita e deixa o resultado num aviso para a página seguinte.
 * Devolve true quando a folha aceitou.
 */
function sheet_try(callable $fn, string $ok = ''): bool {
    try {
        $fn();
        if ($ok !== '') flash($ok);
        return true;
    } catch (SheetException $e) {
        flash($e->getMessage(), 'err');
        return false;
    }
}

// ── pedaços de página ───────────────────────────────────────

/** Hora local (Lisboa) de um instante. */
function sheet_clock(int $ts, string $fmt = 'H:i:s'): string {
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format($fmt);
}

function sheet_today(): string {
    return sheet_clock(time(), 'Y-m-d');
}

/** O endereço desta página com ?fresh=1 (sem as confirmações pendentes). */
function sheet_refresh_url(): string {
    $q = $_GET;
    foreach (['fresh', 'del', 'delcost', 'delcrew', 'edit', 'editcost', 'editcrew'] as $k) unset($q[$k]);
    $q['fresh'] = '1';
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/admin/'), PHP_URL_PATH);
    return $path . '?' . http_build_query($q);
}

/** «Dados da folha Google · atualizados às HH:MM:SS · Abrir folha · Atualizar» */
function sheet_status_line(?array $d): string {
    $parts = ['Dados da folha Google'];
    if ($d !== null) $parts[] = 'atualizados às ' . e(sheet_clock((int)$d['fetched']));
    $parts[] = '<a href="' . e(sheet_link()) . '" target="_blank" rel="noopener noreferrer">Abrir folha</a>';
    $parts[] = '<a href="' . e(sheet_refresh_url()) . '">Atualizar</a>';
    return '<div class="sheetline">' . implode(' · ', $parts) . '</div>';
}

/** A caixa que aparece quando a folha não responde. */
function sheet_error_box(Throwable $e): string {
    $msg = $e instanceof SheetException ? $e->getMessage() : 'Erro inesperado ao ler a folha Google.';
    return '<div class="flash err"><b>Não foi possível ler a folha Google.</b> ' . e($msg)
         . '<div style="margin-top:0.5rem">'
         . '<a href="' . e(sheet_link()) . '" target="_blank" rel="noopener noreferrer">Abrir folha</a>'
         . ' · <a href="' . e(sheet_refresh_url()) . '">Tentar outra vez</a></div></div>';
}

/** Opções de um <select>, com o valor atual mantido mesmo que não esteja na lista. */
function sheet_options(array $list, string $current, string $empty = ''): string {
    $html = $empty !== '' ? '<option value="">' . e($empty) . '</option>' : '';
    if ($current !== '' && !in_array($current, $list, true)) {
        $html .= '<option value="' . e($current) . '" selected>' . e($current) . '</option>';
    }
    foreach ($list as $v) {
        $html .= '<option value="' . e($v) . '"' . ($v === $current ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return $html;
}
