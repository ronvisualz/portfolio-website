/* topper. stage — small maths shared by the stage modules. */

export const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
export const lerp = (a, b, t) => a + (b - a) * t;
export const damp = (cur, target, lambda, dt) => cur + (target - cur) * (1 - Math.exp(-lambda * dt));

/* cubic-bezier easing, solved by Newton steps (same curves as the CSS) */
export function bezier(x1, y1, x2, y2) {
  const A = (a, b) => 1 - 3 * b + 3 * a, B = (a, b) => 3 * b - 6 * a, C = a => 3 * a;
  const at = (t, a, b) => ((A(a, b) * t + B(a, b)) * t + C(a)) * t;
  const slope = (t, a, b) => 3 * A(a, b) * t * t + 2 * B(a, b) * t + C(a);
  return x => {
    if (x <= 0) return 0; if (x >= 1) return 1;
    let t = x;
    for (let i = 0; i < 6; i++) { const s = slope(t, x1, x2); if (Math.abs(s) < 1e-6) break; t -= (at(t, x1, x2) - x) / s; }
    return at(t, y1, y2);
  };
}
export const ease = {
  house: bezier(0.35, 0, 0, 1),            // the topper. ease (also in CSS as --ease-house)
  io: t => t * t * (3 - 2 * t),
  inOut: bezier(0.65, 0, 0.35, 1),
  out: t => 1 - Math.pow(1 - t, 3),
  in: t => t * t * t,
  linear: t => t
};

/* fit(x, a, b, from, to, ease): clamped remap with easing — the scroll choreography primitive */
export function fit(x, a, b, from = 0, to = 1, e = ease.linear) {
  const t = clamp((x - a) / (b - a || 1), 0, 1);
  return from + (to - from) * e(t);
}

/* second-order dynamics (t3ssel8r): a spring with frequency f, damping z, response r.
   Stable for any dt. Works on numbers or on arrays of numbers. */
export class Spring {
  constructor(f, z, r, x0) {
    this.set(f, z, r);
    const v = Array.isArray(x0) ? x0.slice() : [x0 || 0];
    this.scalar = !Array.isArray(x0);
    this.xp = v.slice(); this.y = v.slice(); this.yd = v.map(() => 0);
  }
  set(f, z, r) {
    this.k1 = z / (Math.PI * f);
    this.k2 = 1 / ((2 * Math.PI * f) ** 2);
    this.k3 = r * z / (2 * Math.PI * f);
  }
  reset(x) { const v = this.scalar ? [x] : x; for (let i = 0; i < v.length; i++) { this.xp[i] = this.y[i] = v[i]; this.yd[i] = 0; } }
  update(dt, x) {
    if (dt <= 0) return this.value;
    const v = this.scalar ? [x] : x;
    const k2 = Math.max(this.k2, dt * dt / 2 + dt * this.k1 / 2, dt * this.k1);
    for (let i = 0; i < v.length; i++) {
      const xd = (v[i] - this.xp[i]) / dt; this.xp[i] = v[i];
      this.y[i] += dt * this.yd[i];
      this.yd[i] += dt * (v[i] + this.k3 * xd - this.y[i] - this.k1 * this.yd[i]) / k2;
      if (!Number.isFinite(this.y[i]) || !Number.isFinite(this.yd[i])) { this.y[i] = this.xp[i] = Number.isFinite(v[i]) ? v[i] : 0; this.yd[i] = 0; }   // never let a bad frame poison the spring
    }
    return this.value;
  }
  get value() { return this.scalar ? this.y[0] : this.y; }
}

/* cheap smooth noise (sums of sines) and a 3-octave Brownian drift for idle camera life */
export const sn = (t, s) => Math.sin(t * 1.0 + s) * 0.5 + Math.sin(t * 2.3 + s * 1.7) * 0.3 + Math.sin(t * 4.1 + s * 2.9) * 0.2;
export function brownian(t, seed, freq = 0.25) {
  let v = 0, amp = 1, f = freq, norm = 0;
  for (let o = 0; o < 3; o++) { v += sn(t * f * 6.283, seed + o * 13.1) * amp; norm += amp; amp *= 0.5; f *= 2; }
  return v / norm;
}
