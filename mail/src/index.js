/**
 * topper. — entrada dos emails do Financeiro pela Cloudflare (§31.1).
 *
 * Cada caixa escolhida do Hostinger (ex.: info@topperdigital.xyz) reencaminha uma cópia para
 * entrada@in.topperhub.xyz (Email Routing só no subdomínio in.; os MX de topperhub.xyz ficam no Hostinger). O Cloudflare Email Routing entrega essa cópia a este Worker, que a envia em bruto (MIME)
 * à plataforma por HTTPS, assinada. Nenhuma palavra-passe de email é guardada em lado nenhum.
 *
 *   POST INBOUND_URL   corpo: o email em bruto (message/rfc822)
 *     X-Topper-Ts    carimbo de tempo (unix, segundos)
 *     X-Topper-Id    o Message-ID do email (ou o sha256 do email, se não tiver)
 *     X-Topper-Sig   HMAC-SHA256(INBOUND_SECRET, «ts.id.sha256hex(corpo)») em hex
 *     X-Topper-From  o remetente do envelope (diz que caixa reencaminhou)
 *
 * A plataforma recusa assinaturas erradas, carimbos com mais de 5 minutos e repetições do mesmo Message-ID.
 * Respostas 4xx: o email não volta a ser tentado. 5xx ou sem ligação: o Worker falha e a Cloudflare devolve um
 * erro temporário ao servidor que reencaminhou, que volta a tentar mais tarde (nada se perde).
 *
 *   GET /health   estado (sem segredos)
 */

const MAX_BYTES = 25 * 1024 * 1024;
const enc = new TextEncoder();

function hex(buf) {
  return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, "0")).join("");
}

export async function sha256hex(bytes) {
  return hex(await crypto.subtle.digest("SHA-256", bytes));
}

/** A assinatura que a plataforma confere (a mesma fórmula de mailer_inbound_sign no PHP). */
export async function sign(secret, ts, id, bodyHash) {
  const key = await crypto.subtle.importKey("raw", enc.encode(secret), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
  return hex(await crypto.subtle.sign("HMAC", key, enc.encode(`${ts}.${id}.${bodyHash}`)));
}

/** Prepara o pedido à plataforma (separado para se poder testar sem a Cloudflare). */
export async function buildRequest(raw, messageId, envFrom, env, now = Date.now()) {
  const bodyHash = await sha256hex(raw);
  const id = (messageId || "").trim().slice(0, 998) || "sha256:" + bodyHash;
  const ts = String(Math.floor(now / 1000));
  const sig = await sign(env.INBOUND_SECRET, ts, id, bodyHash);
  return new Request(env.INBOUND_URL, {
    method: "POST",
    headers: {
      "content-type": "message/rfc822",
      "x-topper-ts": ts,
      "x-topper-id": id,
      "x-topper-sig": sig,
      "x-topper-from": (envFrom || "").slice(0, 320),
      "user-agent": "topper-mail-worker"
    },
    body: raw
  });
}

export default {
  async email(message, env) {
    if (!env.INBOUND_SECRET || !env.INBOUND_URL) {
      // sem configuração: erro temporário (o servidor que reencaminhou volta a tentar)
      throw new Error("topper-mail: falta INBOUND_SECRET ou INBOUND_URL");
    }
    if (message.rawSize > MAX_BYTES) {
      message.setReject("Mensagem maior do que 25 MB.");
      return;
    }
    const raw = await new Response(message.raw).arrayBuffer();
    const req = await buildRequest(raw, message.headers.get("message-id"), message.from, env);
    const res = await fetch(req);
    if (res.status >= 500) throw new Error("topper-mail: a plataforma respondeu " + res.status + " (tentar depois)");
    // só o estado (nunca o conteúdo): aparece em «npx wrangler tail topper-mail»
    if (res.status >= 400) console.log("topper-mail: recusado pela plataforma", res.status, await res.text());
    else console.log("topper-mail: entregue", res.status, (await res.text()).slice(0, 200));
  },

  async fetch(request, env) {
    const url = new URL(request.url);
    if (url.pathname === "/health") {
      return new Response(JSON.stringify({ ok: true, configured: Boolean(env.INBOUND_SECRET && env.INBOUND_URL) }), {
        headers: { "content-type": "application/json", "cache-control": "no-store" }
      });
    }
    return new Response("não encontrado", { status: 404 });
  }
};
