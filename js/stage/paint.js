/* topper. stage — "magic smoke": the cursor paints a velocity field at quarter resolution
   (Lusion's screen paint). Each frame the field advects itself, swirls a little and decays.
   The final pass smears the image along it and adds a faint warm haze where it is dense.
   Field texel: rg = velocity (screen units / frame), b = density. */
import * as THREE from '../../vendor/three/three.module.min.js';
import { FullScreenQuad } from '../../vendor/three/addons/postprocessing/Pass.js';

const frag = `precision highp float;
uniform sampler2D tPrev; uniform vec2 uPx; uniform float uAspect;
uniform vec2 uA; uniform vec2 uB; uniform vec2 uVel; uniform float uR; uniform float uStr;
uniform vec3 uDecay; uniform float uT;
varying vec2 vUv;
float seg(vec2 p, vec2 a, vec2 b){ vec2 pa = p - a, ba = b - a; float h = clamp(dot(pa, ba) / max(dot(ba, ba), 1e-6), 0.0, 1.0); return length(pa - ba * h); }
void main(){
  vec4 c = texture2D(tPrev, vUv);
  // self-advection along a blurred copy of its own velocity
  vec2 v = (texture2D(tPrev, vUv + vec2(uPx.x * 4.0, 0.0)).xy + texture2D(tPrev, vUv - vec2(uPx.x * 4.0, 0.0)).xy
          + texture2D(tPrev, vUv + vec2(0.0, uPx.y * 4.0)).xy + texture2D(tPrev, vUv - vec2(0.0, uPx.y * 4.0)).xy) * 0.25;
  vec4 a = texture2D(tPrev, vUv - v * 0.85);
  // a slow curl-like swirl so the smoke curls instead of sliding
  float s = sin(vUv.x * 9.0 + uT * 0.7) * cos(vUv.y * 7.0 - uT * 0.5);
  a.xy += vec2(-a.y, a.x) * s * 0.06;
  // decay (velocity faster than density), with a floor step so small values reach zero
  a.xy = a.xy * uDecay.x; a.xy -= sign(a.xy) * min(abs(a.xy), vec2(0.00002));
  a.z = max(a.z * uDecay.y - 0.002, 0.0);
  // brush: a capsule from the last pointer position to this one
  vec2 p = vec2(vUv.x * uAspect, vUv.y), pa = vec2(uA.x * uAspect, uA.y), pb = vec2(uB.x * uAspect, uB.y);
  float m = smoothstep(uR, 0.0, seg(p, pa, pb)) * uStr;
  a.xy = mix(a.xy, uVel, m);
  a.z = min(1.0, a.z + m * 0.55);
  gl_FragColor = vec4(a.xy, a.z, 1.0);
}`;

export function makePaint(renderer) {
  const type = renderer.capabilities.isWebGL2 ? THREE.HalfFloatType : THREE.UnsignedByteType;
  const opts = { type, minFilter: THREE.LinearFilter, magFilter: THREE.LinearFilter, depthBuffer: false, stencilBuffer: false };
  let A = new THREE.WebGLRenderTarget(4, 4, opts), B = A.clone();
  const mat = new THREE.ShaderMaterial({
    uniforms: {
      tPrev: { value: null }, uPx: { value: new THREE.Vector2() }, uAspect: { value: 1 },
      uA: { value: new THREE.Vector2(-1, -1) }, uB: { value: new THREE.Vector2(-1, -1) }, uVel: { value: new THREE.Vector2() },
      uR: { value: 0.05 }, uStr: { value: 0 }, uDecay: { value: new THREE.Vector3(0.975, 0.95, 0.8) }, uT: { value: 0 }
    },
    vertexShader: 'varying vec2 vUv; void main(){ vUv = uv; gl_Position = vec4(position.xy, 0.0, 1.0); }',
    fragmentShader: frag, depthTest: false, depthWrite: false
  });
  const quad = new FullScreenQuad(mat);
  const last = new THREE.Vector2(-1, -1), cur = new THREE.Vector2(-1, -1);
  let moved = false, enabled = type === THREE.HalfFloatType;   // 8-bit fields can't hold signed velocity; skip there

  function resize(w, h) {
    const W = Math.max(8, Math.round(w / 4)), H = Math.max(8, Math.round(h / 4));
    A.setSize(W, H); B.setSize(W, H);
    mat.uniforms.uPx.value.set(1 / W, 1 / H); mat.uniforms.uAspect.value = w / h;
  }
  function pointer(x, y) { cur.set(x, y); moved = true; }      // x, y in 0..1, y up
  function step(dt, time, extra = 0) {
    if (!enabled) return;
    const u = mat.uniforms;
    if (last.x < 0) last.copy(cur);
    const dx = cur.x - last.x, dy = cur.y - last.y, sp = Math.hypot(dx, dy);
    u.uA.value.copy(last); u.uB.value.copy(cur);
    u.uVel.value.set(dx, dy).multiplyScalar(0.9);
    u.uR.value = 0.035 + Math.min(sp, 0.08) * 0.6;
    u.uStr.value = moved ? Math.min(1, sp * 60) : 0;
    u.uT.value = time;
    last.copy(cur); moved = false;
    u.tPrev.value = A.texture;
    renderer.setRenderTarget(B); quad.render(renderer); renderer.setRenderTarget(null);
    const t = A; A = B; B = t;
  }
  return { resize, pointer, step, get texture() { return A.texture; }, get enabled() { return enabled; }, set enabled(v) { enabled = v && type === THREE.HalfFloatType; } };
}
