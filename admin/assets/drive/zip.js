/* topper. admin — Drive: vários ficheiros num ZIP (TD.zip).
 *
 * ZIP «store» (sem compressão — vídeo e imagens já vêm comprimidos), escrito
 * em streaming à medida que os ficheiros chegam do R2: CRC32 calculado pelo
 * caminho, tamanhos nos «data descriptors» (depois dos dados), nomes em UTF-8
 * (bit 11) e ZIP64 quando um tamanho, uma posição ou o número de ficheiros
 * passa os limites de 32/16 bits.
 * Com showSaveFilePicker (Chrome, Edge) escreve diretamente no disco, sem
 * limite; sem ele junta tudo em memória, até 2 GB. */
(function () {
  'use strict';
  const TD = window.TD = window.TD || {};

  const MAX32 = 0xFFFFFFFF, MAX16 = 0xFFFF;
  const MEM_MAX = 2 * 1024 * 1024 * 1024;
  const URL_BATCH = 100, URL_TTL = 50 * 60e3, RETRIES = 3;
  const enc = new TextEncoder();

  // ── CRC32 (polinómio 0xEDB88320, por tabela) ────────────
  const TABLE = (() => {
    const t = new Int32Array(256);
    for (let n = 0; n < 256; n++) {
      let c = n;
      for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
      t[n] = c;
    }
    return t;
  })();
  /** crc32(0, bytes) → número; crc32(anterior, mais) continua. */
  function crc32(crc, b) {
    let c = ~crc;
    for (let i = 0, n = b.length; i < n; i++) c = TABLE[(c ^ b[i]) & 255] ^ (c >>> 8);
    return ~c >>> 0;
  }

  // ── cabeçalhos ──────────────────────────────────────────
  class Bytes {
    constructor(n) { this.a = new Uint8Array(n); this.v = new DataView(this.a.buffer); this.p = 0; }
    u8(x) { this.v.setUint8(this.p, x); this.p += 1; }
    u16(x) { this.v.setUint16(this.p, x, true); this.p += 2; }
    u32(x) { this.v.setUint32(this.p, x >>> 0, true); this.p += 4; }
    /** até 2^53 (sem BigInt) */
    u64(x) { this.u32(x % 4294967296); this.u32(Math.floor(x / 4294967296)); }
    bytes(b) { this.a.set(b, this.p); this.p += b.length; }
  }
  /** Data e hora MS-DOS (hora local), de um unix timestamp. */
  function dosTime(ts) {
    const d = new Date(ts * 1000);
    const y = d.getFullYear();
    if (y < 1980) return { time: 0, date: (1 << 5) | 1 };
    if (y > 2107) return { time: (23 << 11) | (59 << 5) | 29, date: (127 << 9) | (12 << 5) | 31 };
    return {
      time: (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1),
      date: ((y - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate(),
    };
  }
  /** Extra «UT» (0x5455): a data exata em UTC, além da data DOS. */
  function extTime(b, mtime) { b.u16(0x5455); b.u16(5); b.u8(1); b.u32(Math.max(0, Math.min(mtime, 0x7FFFFFFF))); }

  const FLAGS = 0x0808;   // bit 3: data descriptor · bit 11: nomes em UTF-8
  function localHeader(r) {
    const extra = (r.z64 ? 20 : 0) + 9;
    const b = new Bytes(30 + r.name.length + extra);
    b.u32(0x04034b50);
    b.u16(r.z64 ? 45 : 20); b.u16(FLAGS); b.u16(0); b.u16(r.time); b.u16(r.date);
    // CRC e tamanhos vêm no data descriptor; em ZIP64 ficam 0xFFFFFFFF e o extra diz que o descriptor tem 8 bytes
    b.u32(0); b.u32(r.z64 ? MAX32 : 0); b.u32(r.z64 ? MAX32 : 0);
    b.u16(r.name.length); b.u16(extra);
    b.bytes(r.name);
    if (r.z64) { b.u16(0x0001); b.u16(16); b.u64(0); b.u64(0); }
    extTime(b, r.mtime);
    return b.a;
  }
  function descriptor(r) {
    const b = new Bytes(r.z64 ? 24 : 16);
    b.u32(0x08074b50); b.u32(r.crc);
    if (r.z64) { b.u64(r.n); b.u64(r.n); } else { b.u32(r.n); b.u32(r.n); }
    return b.a;
  }
  function centralHeader(r, force) {
    const bigSize = force || r.n >= MAX32, bigOff = force || r.offset >= MAX32;
    const zlen = (bigSize ? 16 : 0) + (bigOff ? 8 : 0);
    const extra = (zlen ? 4 + zlen : 0) + 9;
    const ver = r.z64 || zlen ? 45 : 20;
    const b = new Bytes(46 + r.name.length + extra);
    b.u32(0x02014b50);
    b.u16(0x0300 | ver); b.u16(ver);          // feito em «Unix» (permissões abaixo)
    b.u16(FLAGS); b.u16(0); b.u16(r.time); b.u16(r.date); b.u32(r.crc);
    b.u32(bigSize ? MAX32 : r.n); b.u32(bigSize ? MAX32 : r.n);
    b.u16(r.name.length); b.u16(extra); b.u16(0); b.u16(0); b.u16(0);
    b.u32(0x81A40000);                        // -rw-r--r--
    b.u32(bigOff ? MAX32 : r.offset);
    b.bytes(r.name);
    if (zlen) {
      b.u16(0x0001); b.u16(zlen);
      if (bigSize) { b.u64(r.n); b.u64(r.n); }
      if (bigOff) b.u64(r.offset);
    }
    extTime(b, r.mtime);
    return b.a;
  }
  function endRecords(count, cdStart, cdSize, force) {
    const z = force || count >= MAX16 || cdStart >= MAX32 || cdSize >= MAX32;
    const b = new Bytes((z ? 56 + 20 : 0) + 22);
    if (z) {
      const at = cdStart + cdSize;
      b.u32(0x06064b50); b.u64(44); b.u16(0x0300 | 45); b.u16(45);   // ZIP64 end of central directory
      b.u32(0); b.u32(0); b.u64(count); b.u64(count); b.u64(cdSize); b.u64(cdStart);
      b.u32(0x07064b50); b.u32(0); b.u64(at); b.u32(1);              // locator
    }
    const n16 = z && (force || count >= MAX16) ? MAX16 : count;
    b.u32(0x06054b50); b.u16(0); b.u16(0); b.u16(n16); b.u16(n16);
    b.u32(z && (force || cdSize >= MAX32) ? MAX32 : cdSize);
    b.u32(z && (force || cdStart >= MAX32) ? MAX32 : cdStart);
    b.u16(0);
    return b.a;
  }

  // ── erros ───────────────────────────────────────────────
  function abortErr() {
    try { return new DOMException('A descarga foi cancelada.', 'AbortError'); } catch (e) {
      const x = new Error('A descarga foi cancelada.'); x.name = 'AbortError'; return x;
    }
  }
  const isAbort = (e, signal) => (signal && signal.aborted) || (e && e.name === 'AbortError');
  function checkAbort(signal) { if (signal && signal.aborted) throw abortErr(); }
  function wait(ms, signal) {
    return new Promise((res, rej) => {
      const t = setTimeout(res, ms);
      if (signal) signal.addEventListener('abort', () => { clearTimeout(t); rej(abortErr()); }, { once: true });
    });
  }

  // ── caminhos dentro do ZIP ──────────────────────────────
  const baseName = k => { const t = String(k).replace(/\/$/, ''); return t.slice(t.lastIndexOf('/') + 1); };
  function cleanPath(p) {
    return String(p || '').replace(/\\/g, '/').split('/').map(s => s.replace(/[\x00-\x1f\x7f]/g, ''))
      .filter(s => s && s !== '.' && s !== '..').join('/');
  }
  /** Sem nomes repetidos (sem distinguir maiúsculas, como no Windows/macOS): «a (2).txt». */
  function unique(path, used) {
    let p = path, i = 2;
    while (used.has(p.toLowerCase())) {
      const dot = path.lastIndexOf('.'), slash = path.lastIndexOf('/');
      p = dot > slash + 1 ? path.slice(0, dot) + ' (' + i + ')' + path.slice(dot) : path + ' (' + i + ')';
      i++;
    }
    used.add(p.toLowerCase());
    return p;
  }

  /**
   * O escritor. items = [{path, size, modified, chunks: () => AsyncIterator<Uint8Array>}];
   * sink = {write(bytes), close(), abort()} (podem devolver Promises).
   * Um ficheiro cujo iterador lança {missing:true} antes do primeiro bloco fica de fora.
   * opt: {zip64 (forçar), signal, onProgress(bytes, total, {file, files, name})}.
   */
  async function stream(items, sink, opt) {
    opt = opt || {};
    const signal = opt.signal, force = !!opt.zip64;
    const used = new Set();
    const list = (items || []).map(it => {
      const path = unique(cleanPath(it.path) || 'ficheiro', used);
      return { path, name: enc.encode(path), size: typeof it.size === 'number' && it.size >= 0 ? it.size : -1, modified: +it.modified || 0, chunks: it.chunks };
    });
    const total = list.reduce((a, e) => a + Math.max(0, e.size), 0);
    let offset = 0, done = 0, lastP = 0;
    const cd = [], skipped = [];
    const out = async b => { if (b.length) { await sink.write(b); offset += b.length; } };
    const progress = (i, name, final) => {
      const now = Date.now();
      if (!opt.onProgress || (!final && now - lastP < 100)) return;
      lastP = now;
      try { opt.onProgress(Math.min(done, total || done), total, { file: i + 1, files: list.length, name }); } catch (e) { console.error(e); }
    };
    for (let i = 0; i < list.length; i++) {
      checkAbort(signal);
      const e = list[i];
      const it = e.chunks();
      try {
        let r;
        try { r = await it.next(); } catch (err) {
          if (err && err.missing) { skipped.push(e.path); done += Math.max(0, e.size); continue; }
          throw err;
        }
        const mtime = e.modified > 0 ? Math.floor(e.modified) : Math.floor(Date.now() / 1000);
        const t = dosTime(mtime);
        // ZIP64 por entrada: quando o tamanho pode passar 4 GB (ou não se sabe)
        const rec = { name: e.name, mtime, time: t.time, date: t.date, offset, z64: force || !(e.size >= 0 && e.size < MAX32 - 65536), crc: 0, n: 0 };
        await out(localHeader(rec));
        let crc = 0, n = 0;
        while (!r.done) {
          const b = r.value;
          if (b && b.length) {
            crc = crc32(crc, b);
            n += b.length; done += b.length;
            if (!rec.z64 && n >= MAX32) throw new Error('O ficheiro «' + e.path + '» mudou durante a descarga. Tente outra vez.');
            await out(b);
            progress(i, e.path);
          }
          checkAbort(signal);
          r = await it.next();
        }
        if (e.size > n) done += e.size - n;
        rec.crc = crc; rec.n = n;
        await out(descriptor(rec));
        cd.push(rec);
      } finally {
        if (it.return) { try { await it.return(); } catch (err) { /* já fechado */ } }
      }
    }
    if (!cd.length) throw new Error(skipped.length ? 'Os ficheiros escolhidos já não existem.' : 'Não há ficheiros para descarregar.');
    const cdStart = offset;
    for (const r of cd) await out(centralHeader(r, force));
    await out(endRecords(cd.length, cdStart, offset - cdStart, force));
    progress(list.length - 1, '', true);
    return { files: cd.length, bytes: offset, skipped };
  }

  // ── a origem: o R2 (URLs pré-assinados pelo drive_api › urls) ──
  function urlGetter(entries) {
    const map = new Map(), pos = new Map();
    entries.forEach((e, i) => { if (!pos.has(e.key)) pos.set(e.key, i); });
    const fresh = k => { const c = map.get(k); return c && Date.now() - c.t < URL_TTL; };
    return async function get(key, again) {
      if (!again && fresh(key)) return map.get(key).url;
      // este e os seguintes que ainda não têm URL, em lotes
      const keys = [key];
      for (let j = (pos.get(key) || 0) + 1; j < entries.length && keys.length < URL_BATCH; j++) {
        const k = entries[j].key;
        if (!fresh(k) && keys.indexOf(k) < 0) keys.push(k);
      }
      let r = null;
      for (let a = 0; a < 3; a++) {
        r = await TD.api({ do: 'urls', keys });
        if (r.ok || (r.status && r.status < 500)) break;
        await wait(800 * (a + 1));
      }
      if (!r.ok) throw new Error(r.error || 'Não foi possível preparar a descarga.');
      const t = Date.now();
      Object.keys(r.urls || {}).forEach(k => map.set(k, { url: r.urls[k], t }));
      if (!map.has(key)) { const e = new Error('missing'); e.missing = true; throw e; }
      return map.get(key).url;
    };
  }
  /** Os bytes de um ficheiro do R2; se a ligação cair a meio, continua com Range. */
  function r2Chunks(entry, getUrl, signal) {
    return (async function* () {
      let got = 0, attempt = 0, resigned = false;
      let url = await getUrl(entry.key);
      const fail = () => new Error('Não foi possível descarregar «' + entry.path + '». Verifique a ligação e tente outra vez.');
      for (;;) {
        checkAbort(signal);
        let res;
        try {
          res = await fetch(url, { signal, credentials: 'omit', cache: 'no-store', headers: got > 0 ? { Range: 'bytes=' + got + '-' } : {} });
        } catch (e) {
          if (isAbort(e, signal)) throw abortErr();
          if (++attempt > RETRIES) throw fail();
          await wait(1000 * attempt, signal);
          continue;
        }
        if (res.status === 404 || res.status === 410) {
          if (got === 0) { const e = new Error('missing'); e.missing = true; throw e; }
          throw new Error('O ficheiro «' + entry.path + '» foi apagado durante a descarga.');
        }
        if (res.status === 403) {
          // URL caducado: assina outra vez; se mesmo assim for recusado, vê se o ficheiro ainda existe
          // (o S3 responde 403, e não 404, a um ficheiro que não existe quando não se pode listar o bucket)
          if (!resigned) { resigned = true; url = await getUrl(entry.key, true); continue; }
          if (got === 0) {
            const j = await TD.api({ do: 'info', key: entry.key });
            if (j.ok && !j.object) { const e = new Error('missing'); e.missing = true; throw e; }
          }
          throw new Error('O R2 recusou «' + entry.path + '» (HTTP 403). Tente outra vez.');
        }
        if (!res.ok) {
          if (res.status >= 500 && ++attempt <= RETRIES) { await wait(1000 * attempt, signal); continue; }
          throw new Error('Não foi possível descarregar «' + entry.path + '» (HTTP ' + res.status + ').');
        }
        // a continuar: 206 a partir de onde ficou (o Content-Range só se lê se o CORS o expuser);
        // com 200 (Range ignorado) salta o que já veio
        let skip = 0;
        if (got > 0) {
          if (res.status === 206) {
            const m = /bytes (\d+)-/.exec(res.headers.get('Content-Range') || '');
            skip = m ? got - (+m[1]) : 0;
          } else skip = got;
          if (skip < 0) throw fail();
        }
        if (!res.body) return;
        const reader = res.body.getReader();
        try {
          for (;;) {
            const { done, value } = await reader.read();
            if (done) return;
            let v = value;
            if (skip) {
              if (v.length <= skip) { skip -= v.length; continue; }
              v = v.subarray(skip); skip = 0;
            }
            got += v.length;
            attempt = 0;
            yield v;
          }
        } catch (e) {
          if (isAbort(e, signal)) throw abortErr();
          if (++attempt > RETRIES) throw fail();
          await wait(1000 * attempt, signal);
        } finally {
          try { reader.cancel().catch(() => {}); } catch (e) { /* já fechado */ }
        }
      }
    })();
  }

  // ── destinos ────────────────────────────────────────────
  /** Em memória: blocos de 32 MB viram Blobs (o browser pode passá-los para o disco). */
  function memorySink(name) {
    let parts = [], cur = [], size = 0;
    return {
      write(b) {
        cur.push(b); size += b.length;
        if (size >= 32 * 1024 * 1024) { parts.push(new Blob(cur)); cur = []; size = 0; }
      },
      close() {
        parts.push(new Blob(cur)); cur = [];
        const blob = new Blob(parts, { type: 'application/zip' });
        parts = [];
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = name; a.rel = 'noopener'; a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { a.remove(); URL.revokeObjectURL(url); }, 120000);
      },
      abort() { parts = []; cur = []; },
    };
  }
  /** No ficheiro escolhido (File System Access): escritas de 4 MB. */
  async function fileSink(handle) {
    const w = await handle.createWritable();
    let buf = [], size = 0;
    const flush = async () => {
      if (!size) return;
      const all = new Uint8Array(size);
      let p = 0;
      buf.forEach(b => { all.set(b, p); p += b.length; });
      buf = []; size = 0;
      await w.write(all);
    };
    return {
      async write(b) { buf.push(b); size += b.length; if (size >= 4 * 1024 * 1024) await flush(); },
      async close() { await flush(); await w.close(); },
      async abort() { buf = []; size = 0; try { await w.abort(); } catch (e) { /* já fechado */ } },
    };
  }

  /**
   * TD.zip.download([{key, path, size, modified}], 'nome.zip', {onProgress, signal, zip64?})
   * → Promise<{files, bytes, skipped:[path]}>. Cancelar (signal) rejeita com AbortError.
   */
  async function download(entries, zipName, opt) {
    opt = opt || {};
    const signal = opt.signal;
    checkAbort(signal);
    const list = (entries || []).filter(e => e && typeof e.key === 'string' && e.key !== '' && e.key.slice(-1) !== '/')
      .map(e => ({ key: e.key, path: cleanPath(e.path) || baseName(e.key), size: +e.size >= 0 ? +e.size : -1, modified: +e.modified || 0 }));
    if (!list.length) throw new Error('Não há ficheiros para descarregar.');
    let name = cleanPath(zipName).replace(/\//g, '-') || 'topper-drive.zip';
    if (!/\.zip$/i.test(name)) name += '.zip';
    const total = list.reduce((a, e) => a + Math.max(0, e.size), 0);
    const overhead = list.reduce((a, e) => a + 160 + 2 * enc.encode(e.path).length, 200);

    // o seletor primeiro: tem de vir logo a seguir ao clique (gesto do utilizador)
    let sink = opt.sink || null;
    if (!sink && opt.picker !== false && typeof window.showSaveFilePicker === 'function') {
      let handle = null;
      try {
        handle = await window.showSaveFilePicker({ suggestedName: name, types: [{ description: 'Ficheiro ZIP', accept: { 'application/zip': ['.zip'] } }] });
      } catch (e) {
        if (e && e.name === 'AbortError') throw abortErr();
        handle = null;   // sem permissão (ex.: sem gesto): fica em memória
      }
      if (handle) sink = await fileSink(handle);
    }
    if (!sink) {
      if (total + overhead > MEM_MAX) throw new Error('Para descarregar mais de 2 GB de uma vez use o Chrome ou o Edge.');
      sink = memorySink(name);
    }
    const getUrl = urlGetter(list);
    const items = list.map(e => ({ path: e.path, size: e.size, modified: e.modified, chunks: () => r2Chunks(e, getUrl, signal) }));
    try {
      const r = await stream(items, sink, { zip64: !!opt.zip64, signal, onProgress: opt.onProgress });
      await sink.close();
      if (r.skipped.length && TD.toast) {
        TD.toast(r.skipped.length === 1 ? '«' + baseName(r.skipped[0]) + '» já não existia e ficou de fora do ZIP.'
          : r.skipped.length + ' ficheiros já não existiam e ficaram de fora do ZIP.', 'info', { ms: 8000 });
      }
      return r;
    } catch (e) {
      try { await sink.abort(); } catch (e2) { /* nada a fazer */ }
      if (isAbort(e, signal)) throw abortErr();
      throw e;
    }
  }

  TD.zip = { download, stream, crc32 };
})();
