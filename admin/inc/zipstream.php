<?php
/**
 * topper. admin — ZIP em streaming (§32.4), sem ZipArchive nem ficheiros temporários: cada ficheiro passa do R2
 * para o browser bloco a bloco, por isso a memória não cresce com o tamanho e o download começa logo.
 *
 * Método «store» (sem compressão: fotos e vídeos já vêm comprimidos e o CPU do alojamento é curto), bit 3
 * (CRC e tamanhos num descritor depois dos dados, porque só se sabem no fim), nomes em UTF-8 (bit 11) e ZIP64 a
 * partir de $zip64At (4 GB por omissão; os testes baixam-no para o exercitar).
 *
 * Se a leitura de um ficheiro falhar a meio, a exceção sobe e o ZIP nunca recebe o diretório central: o browser dá o
 * download como falhado em vez de guardar um ZIP que abre mas está incompleto.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

final class ZipStream {
    private $out;
    private int $zip64At;
    private int $offset = 0;
    private array $entries = [];
    private bool $broken = false;
    private bool $done = false;

    /** @param callable $out recebe cada bloco de bytes (echo + flush, ou um buffer nos testes) */
    public function __construct(callable $out, int $zip64At = 0xFFFFFFFF) {
        $this->out = $out;
        $this->zip64At = max(1, min($zip64At, 0xFFFFFFFF));
    }

    /**
     * Um ficheiro. $read($sink) chama $sink(string) por cada bloco. $size (se conhecido, p.ex. da listagem do R2)
     * permite um cabeçalho sem ZIP64 para ficheiros pequenos e confirma no fim que se leu o ficheiro inteiro.
     */
    public function addFile(string $name, int $mtime, callable $read, ?int $size = null): void {
        $this->guard();
        $name = $this->clean($name);
        $offset = $this->offset;
        $z64 = $size === null || $size >= $this->zip64At || $offset >= $this->zip64At;
        [$time, $date] = self::dos($mtime);
        $extra = $z64 ? pack('vvPP', 0x0001, 16, 0, 0) : '';
        $this->emit(pack('VvvvvvVVVvv', 0x04034b50, $z64 ? 45 : 20, 0x0808, 0, $time, $date, 0,
                         $z64 ? 0xFFFFFFFF : 0, $z64 ? 0xFFFFFFFF : 0, strlen($name), strlen($extra)) . $name . $extra);
        $crc = hash_init('crc32b');
        $len = 0;
        try {
            $read(function (string $chunk) use ($crc, &$len): void {
                if ($chunk === '') return;
                hash_update($crc, $chunk);
                $len += strlen($chunk);
                $this->emit($chunk);
            });
        } catch (Throwable $e) {
            $this->broken = true;
            throw $e;
        }
        if (($size !== null && $len !== $size) || (!$z64 && $len >= 0xFFFFFFFF)) {
            $this->broken = true;
            throw new RuntimeException('O ficheiro «' . $name . '» mudou enquanto era enviado: tente outra vez.');
        }
        $c = (int)hexdec(hash_final($crc));
        $this->emit($z64 ? pack('VVPP', 0x08074b50, $c, $len, $len) : pack('VVVV', 0x08074b50, $c, $len, $len));
        $this->entries[] = ['name' => $name, 'time' => $time, 'date' => $date, 'crc' => $c, 'size' => $len, 'offset' => $offset, 'z64' => $z64, 'dir' => false];
    }

    /** Uma pasta (para as vazias aparecerem; as outras já vêm nos caminhos dos ficheiros). */
    public function addDir(string $name, int $mtime): void {
        $this->guard();
        $name = rtrim($this->clean($name), '/') . '/';
        [$time, $date] = self::dos($mtime);
        $offset = $this->offset;
        $this->emit(pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, 0, 0, 0, strlen($name), 0) . $name);
        $this->entries[] = ['name' => $name, 'time' => $time, 'date' => $date, 'crc' => 0, 'size' => 0, 'offset' => $offset, 'z64' => false, 'dir' => true];
    }

    /** O diretório central e o fim do ficheiro (com o registo ZIP64 quando é preciso). */
    public function finish(): void {
        if ($this->broken) throw new LogicException('O ZIP ficou incompleto: não pode ser fechado.');
        $this->guard();
        $cdStart = $this->offset;
        foreach ($this->entries as $e) {
            $bigOff = $e['offset'] >= $this->zip64At;
            $x = '';
            if ($e['z64']) $x .= pack('PP', $e['size'], $e['size']);
            if ($bigOff) $x .= pack('P', $e['offset']);
            $extra = $x === '' ? '' : pack('vv', 0x0001, strlen($x)) . $x;
            $attr = $e['dir'] ? (0x10 | (0040755 << 16)) : (0100644 << 16);
            $this->emit(pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 0x0300 | 45, ($e['z64'] || $bigOff) ? 45 : 20, $e['dir'] ? 0x0800 : 0x0808, 0,
                $e['time'], $e['date'], $e['crc'], $e['z64'] ? 0xFFFFFFFF : $e['size'], $e['z64'] ? 0xFFFFFFFF : $e['size'],
                strlen($e['name']), strlen($extra), 0, 0, 0, $attr, $bigOff ? 0xFFFFFFFF : $e['offset']) . $e['name'] . $extra);
        }
        $cdSize = $this->offset - $cdStart;
        $n = count($this->entries);
        $z64 = $cdStart >= $this->zip64At || $cdSize >= $this->zip64At || $n >= 0xFFFF;
        if ($z64) {
            $rec = $this->offset;
            $this->emit(pack('VPvvVVPPPP', 0x06064b50, 44, 0x0300 | 45, 45, 0, 0, $n, $n, $cdSize, $cdStart));
            $this->emit(pack('VVPV', 0x07064b50, 0, $rec, 1));
        }
        $this->emit(pack('VvvvvVVv', 0x06054b50, 0, 0, $z64 ? 0xFFFF : $n, $z64 ? 0xFFFF : $n,
                         $z64 ? 0xFFFFFFFF : $cdSize, $z64 ? 0xFFFFFFFF : $cdStart, 0));
        $this->done = true;
    }

    private function guard(): void {
        if ($this->done) throw new LogicException('O ZIP já foi fechado.');
        if ($this->broken) throw new LogicException('O ZIP ficou incompleto.');
    }

    private function emit(string $bytes): void {
        $this->offset += strlen($bytes);
        ($this->out)($bytes);
    }

    /** Sem «..», barras invertidas nem «/» inicial — o ZIP nunca escreve fora da pasta onde é aberto. */
    private function clean(string $name): string {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $name)) as $p) {
            if ($p === '' || $p === '.' || $p === '..') continue;
            $parts[] = $p;
        }
        if (!$parts) throw new InvalidArgumentException('Nome inválido no ZIP.');
        return implode('/', $parts) . (substr($name, -1) === '/' ? '/' : '');
    }

    /** Data e hora no formato MS-DOS (hora de Lisboa; de 1980 a 2107). */
    private static function dos(int $ts): array {
        $d = (new DateTimeImmutable('@' . max(315532800, $ts)))->setTimezone(new DateTimeZone('Europe/Lisbon'));
        $y = min(2107, (int)$d->format('Y'));
        return [((int)$d->format('G') << 11) | ((int)$d->format('i') << 5) | intdiv((int)$d->format('s'), 2),
                (($y - 1980) << 9) | ((int)$d->format('n') << 5) | (int)$d->format('j')];
    }
}
