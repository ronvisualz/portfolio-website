/**
 * topper. — servidor de tempo real dos quadros (Centro Criativo, fase 3).
 *
 * Um Durable Object por quadro (sala «b<id>»), com Yjs (y-partyserver) e a API de hibernação:
 * um quadro sem ninguém não gasta. O documento fica no SQLite do próprio Durable Object.
 *
 * Entrada: só com um token curto emitido pelo PHP (ECDSA P-256 / ES256; a chave pública vem de RT_PUBLIC_KEY ou,
 * sem ela, do próprio servidor da topper. em RT_KEY_URL — publicar o Worker não pede chave nenhuma):
 *   { b: <quadro>, u: <utilizador>, r: "edit" | "view" | "portal", exp: <unix> }
 * O token diz o quadro: não serve para outro. «view» e «portal» só leem (as alterações são
 * ignoradas pelo servidor); o portal do cliente também não envia presença.
 *
 *   GET  /health                        estado (o browser usa-o para saber se o limite do dia foi atingido)
 *   WS   /parties/board/b<id>?t=<token> o quadro
 */
import { routePartykitRequest } from "partyserver";
import { YServer } from "y-partyserver";
import * as Y from "yjs";
import * as encoding from "lib0/encoding";
import * as decoding from "lib0/decoding";
import * as syncProtocol from "y-protocols/sync";
import * as awarenessProtocol from "y-protocols/awareness";

const enc = new TextEncoder();
const ALG_KEY = { name: "ECDSA", namedCurve: "P-256" };
const ALG_SIGN = { name: "ECDSA", hash: "SHA-256" };
let keyCache = null;

function b64urlToBytes(s) {
  s = s.replace(/-/g, "+").replace(/_/g, "/");
  while (s.length % 4) s += "=";
  const bin = atob(s);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

/**
 * A chave pública (ECDSA P-256, SPKI em base64url): RT_PUBLIC_KEY (se estiver definida) ou a que o servidor da topper. publica em
 * RT_KEY_URL (pública; guardada 10 min). Assim publicar o Worker não pede chave nenhuma.
 */
async function publicKey(env, fresh) {
  let raw = env.RT_PUBLIC_KEY || "";
  if (!raw) {
    if (!fresh && keyCache && keyCache.at > Date.now() - 600000) return keyCache.key;
    const r = await fetch(env.RT_KEY_URL, { cf: { cacheTtl: 300 } });
    if (!r.ok) throw new Error("chave indisponível");
    raw = (await r.json()).pk || "";
  }
  if (keyCache && keyCache.raw === raw) { keyCache.at = Date.now(); return keyCache.key; }
  const key = await crypto.subtle.importKey("spki", b64urlToBytes(raw), ALG_KEY, false, ["verify"]);
  keyCache = { raw, key, at: Date.now() };
  return key;
}

/** Verifica o token; devolve o conteúdo ou null. */
async function verify(token, env) {
  if (!token || (!env.RT_PUBLIC_KEY && !env.RT_KEY_URL)) return null;
  const parts = token.split(".");
  if (parts.length !== 2) return null;
  try {
    const sig = b64urlToBytes(parts[1]), data = enc.encode(parts[0]);
    let ok = await crypto.subtle.verify(ALG_SIGN, await publicKey(env, false), sig, data);
    // a chave pode ter mudado no servidor: confirma uma vez com a chave acabada de ler
    if (!ok && !env.RT_PUBLIC_KEY && keyCache && keyCache.at < Date.now() - 30000) ok = await crypto.subtle.verify(ALG_SIGN, await publicKey(env, true), sig, data);
    if (!ok) return null;
    const p = JSON.parse(new TextDecoder().decode(b64urlToBytes(parts[0])));
    if (!p || typeof p.b !== "number" || typeof p.u !== "number" || !["edit", "view", "portal"].includes(p.r)) return null;
    if (typeof p.exp !== "number" || p.exp < Date.now() / 1000) return null;
    return p;
  } catch (e) {
    return null;
  }
}

function originOk(req, env) {
  const o = req.headers.get("Origin");
  if (!o) return true; // clientes que não são browsers (o token continua obrigatório)
  return (env.ALLOWED_ORIGINS || "").split(",").map((s) => s.trim()).filter(Boolean).includes(o);
}

export class Board extends YServer {
  static options = { hibernate: true };
  // grava o documento um pouco depois de cada alteração (no máximo de 10 em 10 s)
  static callbackOptions = { debounceWait: 2000, debounceMaxWait: 10000 };

  async onLoad() {
    this.ctx.storage.sql.exec("CREATE TABLE IF NOT EXISTS doc (id INTEGER PRIMARY KEY, state BLOB NOT NULL, saved_at INTEGER NOT NULL)");
    const rows = this.ctx.storage.sql.exec("SELECT state FROM doc WHERE id = 1").toArray();
    if (!rows.length) return;
    const doc = new Y.Doc();
    Y.applyUpdate(doc, new Uint8Array(rows[0].state));
    return doc;
  }

  async onSave() {
    const state = Y.encodeStateAsUpdate(this.document);
    this.ctx.storage.sql.exec("INSERT OR REPLACE INTO doc (id, state, saved_at) VALUES (1, ?, ?)", state, Date.now());
  }

  onConnect(conn, ctx) {
    const h = ctx.request.headers;
    const r = h.get("x-topper-role") || "view";
    conn.setState({ u: Number(h.get("x-topper-user") || 0), r });
    if (r !== "portal") return super.onConnect(conn, ctx);
    // o portal do cliente: só o documento (sem a presença da equipa)
    const e = encoding.createEncoder();
    encoding.writeVarUint(e, 0);
    syncProtocol.writeSyncStep1(e, this.document);
    conn.send(encoding.toUint8Array(e));
  }

  isReadOnly(conn) {
    return !conn.state || conn.state.r !== "edit";
  }

  handleMessage(conn, message) {
    if (typeof message === "string") return super.handleMessage(conn, message);
    const b = message instanceof ArrayBuffer ? new Uint8Array(message) : new Uint8Array(message.buffer, message.byteOffset, message.byteLength);
    if (b[0] !== 1) return super.handleMessage(conn, b);
    // presença (Yjs awareness): o portal não envia nem recebe
    if (conn.state && conn.state.r === "portal") return;
    try {
      const d = decoding.createDecoder(b);
      decoding.readVarUint(d);
      awarenessProtocol.applyAwarenessUpdate(this.document.awareness, decoding.readVarUint8Array(d), conn);
    } catch (e) {
      return;
    }
    for (const c of this.getConnections()) {
      if (c.state && c.state.r === "portal") continue;
      try { c.send(b); } catch (e) { /* ligação a fechar */ }
    }
  }

  onCustomMessage(conn, message) {
    // avisos leves entre clientes (ex.: «há comentários novos»): só de quem pode escrever
    if (this.isReadOnly(conn) || typeof message !== "string" || message.length > 2000) return;
    this.broadcastCustomMessage(message, conn);
  }
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (url.pathname === "/health") {
      return new Response(JSON.stringify({ ok: true, t: Date.now() }), {
        headers: { "content-type": "application/json", "access-control-allow-origin": "*", "cache-control": "no-store" }
      });
    }
    const res = await routePartykitRequest(request, env, {
      onBeforeConnect: async (req, lobby) => {
        if (!originOk(req, env)) return new Response("origem não autorizada", { status: 403 });
        const p = await verify(new URL(req.url).searchParams.get("t"), env);
        if (!p || lobby.name !== "b" + p.b) return new Response("sem acesso", { status: 401 });
        const headers = new Headers(req.headers);
        headers.set("x-topper-user", String(p.u));
        headers.set("x-topper-role", p.r);
        return new Request(req, { headers });
      },
      // pedidos HTTP às salas não são usados
      onBeforeRequest: () => new Response("não", { status: 404 })
    });
    return res || new Response("não encontrado", { status: 404 });
  }
};
