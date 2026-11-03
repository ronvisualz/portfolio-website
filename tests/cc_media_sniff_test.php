<?php
// Áudio num contentor de vídeo ou com extensão estranha (o «WhatsApp Audio … .mpeg», um MP3 com ID3 dado como
// video/mpeg): o tipo decide-se pelo conteúdo, nunca por o browser não conseguir mostrar a imagem.
//   php tests/cc_media_sniff_test.php
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';
require_once __DIR__ . '/../admin/inc/cc.php';

// ── bytes feitos à mão (sem ffmpeg) ──
$box = fn(string $type, string $body) => pack('N', 8 + strlen($body)) . $type . $body;
// hdlr: versão/flags, pre_defined (QuickTime: tipo de componente 'mhlr'/'dhlr'), tipo do handler, 12 reservados, nome
$hdlr = fn(string $h, string $pre = "\0\0\0\0") => $box('hdlr', "\0\0\0\0" . $pre . $h . str_repeat("\0", 12) . "\0");
$trak = fn(string $h, int $stbl = 64, string $pre = "\0\0\0\0") => $box('trak', $box('tkhd', str_repeat("\0", 84))
    . $box('mdia', $box('mdhd', str_repeat("\0", 24)) . $hdlr($h, $pre) . $box('minf', $hdlr('alis', 'dhlr') . $box('stbl', str_repeat("\0", $stbl)))));
$ftyp = fn(string $brand = 'isom') => $box('ftyp', $brand . "\0\0\2\0" . $brand . 'mp41');
$mvhd = $box('mvhd', "\0\0\0\0" . pack('NNNN', 0, 0, 1000, 5000) . str_repeat("\0", 80));   // 5 s
$moov = fn(string ...$traks) => $box('moov', $mvhd . implode('', $traks));
$sniff = function (string $file, ?int $headLen = 65536, ?int $tailLen = 0) {
    $head = substr($file, 0, $headLen ?? strlen($file));
    $tail = $tailLen ? substr($file, -$tailLen) : '';
    return cc_media_sniff($head, $tail, strlen($file));
};
$kind = fn(?array $r) => $r === null ? null : [$r['kind'], $r['mime']];

// MPEG áudio: MPEG-1 Layer III, 128 kb/s, 44,1 kHz → 417 bytes por frame; MPEG-2 Layer III 64 kb/s 22,05 kHz → 208
$frame = fn(string $h, int $len) => $h . str_repeat("\0", $len - 4);
$mp3 = $frame("\xFF\xFB\x90\x64", 417) . $frame("\xFF\xFB\x90\x64", 417) . $frame("\xFF\xFB\x90\x64", 417);
$id3 = "ID3\x04\x00\x00" . "\x00\x00\x00\x16" . 'TSSE' . "\x00\x00\x00\x0C\x00\x00" . "\x03Lavf60.3.10";   // 10 + 22 bytes (TSSE Lavf60.3.100)
t_eq(strlen($id3), 32, 'o cabeçalho ID3 de teste tem 32 bytes');

if (!function_exists('cc_media_sniff')) { t_true(false, 'falta cc_media_sniff()'); t_done(); }

t_eq($kind(cc_media_sniff($id3 . $mp3, '', strlen($id3 . $mp3))), ['audio', 'audio/mpeg'], 'ID3 + frames MPEG (o MP3 do WhatsApp) → áudio audio/mpeg');
t_eq($kind(cc_media_sniff("ID3\x03\x00\x00\x00\x01\x00\x00" . str_repeat("\0", 128), '', 0)), ['audio', 'audio/mpeg'], 'ID3 com a etiqueta maior do que o que se leu → áudio audio/mpeg');
t_eq($kind(cc_media_sniff($mp3, '', strlen($mp3))), ['audio', 'audio/mpeg'], 'frames MPEG sem ID3 (FF FB) → áudio');
$m2 = $frame("\xFF\xF3\x80\x40", 208) . $frame("\xFF\xF3\x80\x40", 208);
t_eq($kind(cc_media_sniff($m2, '', strlen($m2))), ['audio', 'audio/mpeg'], 'MPEG-2 Layer III (FF F3) → áudio');
$crc = $frame("\xFF\xFA\x90\x64", 417) . $frame("\xFF\xFA\x90\x64", 417);
t_eq($kind(cc_media_sniff($crc, '', strlen($crc))), ['audio', 'audio/mpeg'], 'frames MPEG com CRC (FF FA) → áudio');
t_eq(cc_media_sniff("\xFF\xFB\x90\x64" . str_repeat("\x11", 413) . "\x12\x34\x56\x78" . str_repeat("\0", 400), '', 0), null, 'FF FB sem o frame seguinte onde devia estar → desconhecido');
t_eq(cc_media_sniff("\xFF\xFB\xF0\x64" . str_repeat("\0", 1000), '', 0), null, 'FF FB com débito inválido → desconhecido');
// duração «barata»: o cabeçalho Xing/Info do primeiro frame (MPEG-1 estéreo: 32 bytes de informação lateral)
$info = "\xFF\xFB\x90\x64" . str_repeat("\0", 32) . 'Info' . pack('N', 1) . pack('N', 192) . str_repeat("\0", 417 - 4 - 32 - 12);
$withInfo = $id3 . $info . $mp3;
$r = cc_media_sniff($withInfo, '', strlen($withInfo));
t_true($r !== null && abs((float)($r['dur'] ?? 0) - 192 * 1152 / 44100) < 0.01, 'MP3 com cabeçalho Info: duração dos frames (' . json_encode($r) . ')');

// MP4/MOV: decide-se pelos handlers das pistas (vide/soun), não por o browser descodificar a imagem
$m4a = $ftyp('M4A ') . $moov($trak('soun')) . $box('mdat', str_repeat("\0", 2000));
t_eq($kind($sniff($m4a)), ['audio', 'audio/mp4'], 'MP4 só com pista de som → áudio audio/mp4');
t_true(abs((float)($sniff($m4a)['dur'] ?? 0) - 5.0) < 0.001, 'MP4 de áudio: duração do mvhd (5 s)');
$mp4 = $ftyp() . $moov($trak('vide'), $trak('soun')) . $box('mdat', str_repeat("\0", 2000));
t_eq($kind($sniff($mp4)), ['video', ''], 'MP4 com pista de vídeo → vídeo');
$late = $ftyp() . $moov($trak('soun'), $trak('vide')) . $box('mdat', str_repeat("\0", 2000));
t_eq($kind($sniff($late)), ['video', ''], 'MP4 com a pista de vídeo depois da de som → vídeo');
$qt = $ftyp('qt  ') . $box('wide', '') . $box('mdat', str_repeat("\0", 3000000)) . $moov($trak('vide', 64, 'mhlr'), $trak('soun', 64, 'mhlr'));
t_eq($kind($sniff($qt, 65536, 2097152)), ['video', ''], 'MOV (QuickTime, moov no fim) com vídeo → vídeo');
t_eq($sniff($qt, 65536, 0), null, 'MOV com o moov no fim e sem ler o fim → desconhecido (não muda nada)');
$tailA = $ftyp('M4A ') . $box('mdat', str_repeat("\0", 3000000)) . $moov($trak('soun'));
t_eq($kind($sniff($tailA, 65536, 2097152)), ['audio', 'audio/mp4'], 'MP4 só de som com o moov no fim → áudio');
// moov grande no início: a primeira pista (som) cabe nos 64 KB, a segunda (vídeo) não → desconhecido, nunca áudio
$big = $ftyp() . $moov($trak('soun', 90000), $trak('vide')) . $box('mdat', str_repeat("\0", 1000));
t_eq($sniff($big, 65536, 0), null, 'moov maior do que o que se leu, só com o som à vista → desconhecido (um vídeo nunca vira áudio)');
t_eq($kind($sniff($big, null, 0)), ['video', ''], 'o mesmo ficheiro lido todo → vídeo');
$bigA = $ftyp() . $moov($trak('soun', 90000)) . $box('mdat', str_repeat("\0", 1000));
t_eq($kind($sniff($bigA, null, 0)), ['audio', 'audio/mp4'], 'moov grande só de som, lido todo → áudio');

// WebM/Matroska: TrackType 1 (vídeo) / 2 (áudio)
$vint = fn(int $n) => $n < 0x7F ? chr(0x80 | $n) : ($n < 0x3FFF ? chr(0x40 | ($n >> 8)) . chr($n & 0xFF) : "\x10" . substr(pack('N', $n), 1));
$el = fn(string $id, string $body) => $id . $vint(strlen($body)) . $body;
$ebml = fn(string $doc) => $el("\x1A\x45\xDF\xA3", $el("\x42\x82", $doc));
$entry = fn(int $type, string $codec) => $el("\xAE", $el("\xD7", "\x01") . $el("\x83", chr($type)) . $el("\x86", $codec));
$seg = fn(string $tracks) => "\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF" . $el("\x15\x49\xA9\x66", $el("\x2A\xD7\xB1", "\x0F\x42\x40"))
    . $el("\x16\x54\xAE\x6B", $tracks) . $el("\x1F\x43\xB6\x75", str_repeat("\0", 200));
$wa = $ebml('webm') . $seg($entry(2, 'A_OPUS'));
t_eq($kind($sniff($wa)), ['audio', 'audio/webm'], 'WebM só com áudio (Opus) → áudio audio/webm');
$wv = $ebml('webm') . $seg($entry(1, 'V_VP9') . $entry(2, 'A_OPUS'));
t_eq($kind($sniff($wv)), ['video', ''], 'WebM com vídeo (VP9) → vídeo');
$mk = $ebml('matroska') . $seg($entry(2, 'A_FLAC'));
t_eq($kind($sniff($mk)), ['audio', 'audio/x-matroska'], 'Matroska só com áudio → áudio');

// outros áudios e o resto
$ogg = fn(string $packet) => 'OggS' . "\x00\x02" . str_repeat("\0", 8) . "\x01\0\0\0" . "\0\0\0\0" . "\0\0\0\0" . "\x01" . chr(strlen($packet)) . $packet;
t_eq($kind($sniff($ogg("OpusHead\x01\x02" . str_repeat("\0", 9)))), ['audio', 'audio/ogg'], 'Ogg Opus → áudio audio/ogg');
t_eq($kind($sniff($ogg("\x80theora" . str_repeat("\0", 30)))), ['video', ''], 'Ogg Theora → vídeo');
t_eq($kind($sniff('RIFF' . pack('V', 1000) . 'WAVEfmt ' . str_repeat("\0", 100))), ['audio', 'audio/wav'], 'RIFF WAVE → áudio audio/wav');
t_eq($kind($sniff('RIFF' . pack('V', 1000) . 'AVI LIST' . str_repeat("\0", 100))), ['video', ''], 'RIFF AVI → vídeo');
t_eq($kind($sniff('fLaC' . str_repeat("\0", 100))), ['audio', 'audio/flac'], 'FLAC → áudio audio/flac');
$adts = "\xFF\xF1\x50\x80\x02\x1F\xFC" . str_repeat("\0", 9) . "\xFF\xF1\x50\x80\x02\x1F\xFC" . str_repeat("\0", 9);   // frames de 16 bytes
t_eq($kind($sniff($adts)), ['audio', 'audio/aac'], 'AAC ADTS → áudio audio/aac');
t_eq($sniff("\x00\x00\x01\xBA" . str_repeat("\0", 2000)), null, 'MPEG-PS → desconhecido');
t_eq($sniff('%PDF-1.7' . str_repeat("\0", 200)), null, 'PDF → desconhecido');
t_eq($sniff(''), null, 'vazio → desconhecido');
t_eq($sniff(random_bytes(4000)), null, 'bytes ao acaso → desconhecido');

t_done();
