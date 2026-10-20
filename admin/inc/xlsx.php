<?php
/**
 * topper. admin — um .xlsx simples, sem bibliotecas (ZipArchive + XML).
 *
 *   xlsx_build([['name' => 'Trabalhos', 'head' => [...], 'rows' => [[...], …], 'types' => [...], 'widths' => [...]], …])
 *     → caminho de um ficheiro temporário com o livro
 *
 * types por coluna: 's' texto, 'n' número, 'm' euros (2 casas), 'b' verdadeiro/falso.
 * A primeira linha (os títulos) fica a negrito, fixa ao rolar, e com filtro.
 */

declare(strict_types=1);

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

function xlsx_esc(string $s): string {
    // só caracteres que o XML aceita
    $s = (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** A1, B1, …, Z1, AA1… */
function xlsx_col(int $i): string {
    $s = '';
    for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s;
    return $s;
}

function xlsx_sheet(array $sh): string {
    $types = $sh['types'] ?? [];
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
       . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
       . '<sheetFormatPr defaultRowHeight="15"/>';
    if (!empty($sh['widths'])) {
        $x .= '<cols>';
        foreach ($sh['widths'] as $i => $w) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
        $x .= '</cols>';
    }
    $x .= '<sheetData>';
    $x .= '<row r="1">';
    foreach ($sh['head'] as $i => $h) {
        $x .= '<c r="' . xlsx_col($i) . '1" t="inlineStr" s="1"><is><t>' . xlsx_esc((string)$h) . '</t></is></c>';
    }
    $x .= '</row>';
    $r = 1;
    foreach ($sh['rows'] as $row) {
        $r++;
        $x .= '<row r="' . $r . '">';
        foreach (array_values($row) as $i => $v) {
            $ref = xlsx_col($i) . $r;
            $t = $types[$i] ?? 's';
            if ($v === null || $v === '') continue;
            if ($t === 'b') {
                $x .= '<c r="' . $ref . '" t="b"><v>' . ($v ? 1 : 0) . '</v></c>';
            } elseif ($t === 'n' || $t === 'm') {
                $x .= '<c r="' . $ref . '"' . ($t === 'm' ? ' s="2"' : '') . '><v>' . (0 + $v) . '</v></c>';
            } else {
                $x .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc((string)$v) . '</t></is></c>';
            }
        }
        $x .= '</row>';
    }
    $x .= '</sheetData>';
    $last = xlsx_col(max(0, count($sh['head']) - 1)) . max(1, $r);
    $x .= '<autoFilter ref="A1:' . $last . '"/>';
    $x .= '<pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>';
    return $x;
}

/** Escreve o livro num ficheiro temporário e devolve o caminho. */
function xlsx_build(array $sheets): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('O servidor não tem a extensão zip do PHP.');
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Não foi possível criar o ficheiro.');

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $defs = '';
    foreach (array_values($sheets) as $i => $sh) {
        $n = $i + 1;
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        // nomes de folha: até 31 caracteres, sem []:*?/\
        $name = mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', (string)$sh['name']), 0, 31);
        $wb .= '<sheet name="' . xlsx_esc($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $last = xlsx_col(max(0, count($sh['head']) - 1)) . max(1, count($sh['rows']) + 1);
        $defs .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . xlsx_esc(str_replace("'", "''", $name)) . '\'!$A$1:$' . preg_replace('/(\d+)$/', '\$$1', $last) . '</definedName>';
        $z->addFromString('xl/worksheets/sheet' . $n . '.xml', xlsx_sheet($sh));
    }
    $ct .= '</Types>';
    $wb .= '</sheets><definedNames>' . $defs . '</definedNames></workbook>';
    $rels .= '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00\ &quot;€&quot;"/></numFmts>'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFC718"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>TOPPER DIGITAL</dc:title><dc:creator>topper. admin</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '</Relationships>';

    $z->addFromString('[Content_Types].xml', $ct);
    $z->addFromString('_rels/.rels', $rootRels);
    $z->addFromString('docProps/core.xml', $core);
    $z->addFromString('xl/workbook.xml', $wb);
    $z->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $z->addFromString('xl/styles.xml', $styles);
    $z->close();
    return $tmp;
}
