/* topper. website v2 — the stage: a top hat under a spotlight that follows the page.
   Each <section data-scene="…"> names a state below; scrolling blends between them.
   The hat is a sketch built in code (lathe profiles); swap makeHat() for a GLB later. */
import * as THREE from '../vendor/three/three.module.min.js';
import { EffectComposer } from '../vendor/three/addons/postprocessing/EffectComposer.js';
import { RenderPass } from '../vendor/three/addons/postprocessing/RenderPass.js';
import { UnrealBloomPass } from '../vendor/three/addons/postprocessing/UnrealBloomPass.js';
import { ShaderPass } from '../vendor/three/addons/postprocessing/ShaderPass.js';
import { OutputPass } from '../vendor/three/addons/postprocessing/OutputPass.js';
import { RoomEnvironment } from '../vendor/three/addons/environments/RoomEnvironment.js';

const canvas = document.getElementById('stage');
const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
const fine = matchMedia('(hover: hover) and (pointer: fine)').matches;
const emit = (name, detail) => window.dispatchEvent(new CustomEvent(name, { detail }));
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
const smooth = (a, b, x) => { const t = clamp((x - a) / (b - a), 0, 1); return t * t * (3 - 2 * t); };
const damp = (cur, target, lambda, dt) => cur + (target - cur) * (1 - Math.exp(-lambda * dt));

let renderer;
try {
  renderer = new THREE.WebGLRenderer({ canvas, antialias: true, powerPreference: 'high-performance' });
  if (!renderer.getContext()) throw new Error('no webgl');
} catch (e) {
  emit('topper:stage', { ok: false });
  throw e;
}

/* ── renderer, camera, scene ─────────────────────────────── */
let small = Math.min(innerWidth, innerHeight) < 700;
let dprCap = small ? 1.5 : 1.75;
renderer.setPixelRatio(Math.min(devicePixelRatio, dprCap));
renderer.setSize(innerWidth, innerHeight, false);
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 1;

const scene = new THREE.Scene();
const camera = new THREE.PerspectiveCamera(32, innerWidth / innerHeight, 0.1, 100);
const pmrem = new THREE.PMREMGenerator(renderer);
scene.environment = pmrem.fromScene(new RoomEnvironment(renderer), 0.04).texture;
const bgColor = new THREE.Color('#0c0c10');
scene.background = bgColor;
scene.fog = new THREE.Fog(bgColor, 9, 26);

/* ── the hat (sketch) ────────────────────────────────────── */
const V = (x, y) => new THREE.Vector2(x, y);
function healLatheSeam(geo, segments, count) {
  // a lathe repeats its first column at the end; average the two so no seam shows
  const n = geo.attributes.normal;
  for (let j = 0; j < count; j++) {
    const a = j, b = segments * count + j;
    const x = (n.getX(a) + n.getX(b)) / 2, y = (n.getY(a) + n.getY(b)) / 2, z = (n.getZ(a) + n.getZ(b)) / 2;
    const l = Math.hypot(x, y, z) || 1;
    n.setXYZ(a, x / l, y / l, z / l); n.setXYZ(b, x / l, y / l, z / l);
  }
  n.needsUpdate = true;
}
function makeHat() {
  const SEG = 128;
  const felt = new THREE.MeshPhysicalMaterial({ color: 0x15151a, roughness: 0.88, metalness: 0, sheen: 1, sheenRoughness: 0.35, sheenColor: new THREE.Color(0x8a90a3), envMapIntensity: 0.18 });
  const satin = new THREE.MeshPhysicalMaterial({ color: 0x0c0c0f, roughness: 0.42, clearcoat: 0.35, clearcoatRoughness: 0.35, sheen: 0.6, sheenColor: new THREE.Color(0xfbae9c), envMapIntensity: 0.35 });
  const lining = new THREE.MeshPhysicalMaterial({ color: 0x6b1322, roughness: 0.42, sheen: 0.7, sheenColor: new THREE.Color(0xff8f86), side: THREE.DoubleSide, envMapIntensity: 0.5, emissive: new THREE.Color(0x3a0710), emissiveIntensity: 0.4 });

  const crownProfile = [V(0.555, 0), V(0.556, 0.2), V(0.562, 0.5), V(0.572, 0.8), V(0.586, 1.08), V(0.598, 1.26), V(0.6, 1.29), V(0.596, 1.31), V(0.585, 1.322), V(0.5, 1.328), V(0.25, 1.331), V(0, 1.332)];
  const crown = new THREE.Mesh(new THREE.LatheGeometry(crownProfile, SEG), felt);

  const liningProfile = [V(0, 1.29), V(0.3, 1.288), V(0.53, 1.28), V(0.545, 1.25), V(0.54, 0.6), V(0.535, 0.02), V(0.53, -0.005)];
  const inside = new THREE.Mesh(new THREE.LatheGeometry(liningProfile, SEG), lining);

  const brimProfile = [V(0.5, 0.018), V(0.75, 0.02), V(0.95, 0.018), V(1.03, 0.012), V(1.055, 0), V(1.058, -0.01), V(1.045, -0.022), V(0.98, -0.028), V(0.75, -0.026), V(0.5, -0.022), V(0.5, 0.018)];
  const brimGeo = new THREE.LatheGeometry(brimProfile, SEG);
  const p = brimGeo.attributes.position;
  for (let i = 0; i < p.count; i++) {
    const x = p.getX(i), z = p.getZ(i), r = Math.hypot(x, z), phi = Math.atan2(z, x);
    const t = clamp((r - 0.56) / (1.058 - 0.56), 0, 1), c = Math.cos(phi), s = Math.sin(phi);
    p.setY(i, p.getY(i) + t * t * (0.24 * c * c - 0.045 * s * s));   // sides curl up, front and back dip
  }
  brimGeo.computeVertexNormals();
  healLatheSeam(brimGeo, SEG, brimProfile.length);
  const brimFelt = felt.clone(); brimFelt.sheen = 0.45; brimFelt.sheenColor = new THREE.Color(0x4d5262); brimFelt.roughness = 0.93; brimFelt.color = new THREE.Color(0x111115);
  const brim = new THREE.Mesh(brimGeo, brimFelt);

  const bandProfile = [V(0.566, 0.03), V(0.572, 0.03), V(0.575, 0.05), V(0.579, 0.24), V(0.576, 0.262), V(0.569, 0.265)];
  const band = new THREE.Mesh(new THREE.LatheGeometry(bandProfile, SEG), satin);

  // the light that lives inside the hat
  const glowMat = new THREE.ShaderMaterial({
    transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
    uniforms: { uI: { value: 0 }, uC: { value: new THREE.Color(0xffd9c4) } },
    vertexShader: 'varying vec2 vUv; void main(){ vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
    fragmentShader: 'uniform float uI; uniform vec3 uC; varying vec2 vUv; void main(){ float d = length(vUv - 0.5) * 2.0; float a = pow(max(0.0, 1.0 - d), 2.2) * uI; gl_FragColor = vec4(uC * a, a); }'
  });
  const glow = new THREE.Mesh(new THREE.CircleGeometry(0.52, 48), glowMat);
  glow.rotation.x = Math.PI / 2; glow.position.y = 0.06;

  const body = new THREE.Group();
  body.add(crown, inside, brim, band, glow);
  const box = new THREE.Box3().setFromObject(body);
  const cy = (box.min.y + box.max.y) / 2;
  body.position.y = -cy;                                   // pivot in the middle
  const hat = new THREE.Group();
  hat.add(body);
  const light = new THREE.PointLight(0xffb59a, 0, 3.2, 1.6);   // lights the lining from inside
  light.position.set(0, 0.25, 0);
  body.add(light);
  return {
    hat, body, light, glowMat, felt, brimFelt, meshes: [crown, brim, band, inside],
    width: (box.max.x - box.min.x), height: (box.max.y - box.min.y), bottom: box.min.y - cy,
    opening: new THREE.Vector3(0, 0.02, 0)               // in body space
  };
}
const H = makeHat();
scene.add(H.hat);

/* ── lights, floor, beam ─────────────────────────────────── */
const FLOOR_Y = -1.35;
const key = new THREE.SpotLight(0xfff1e2, 210, 30, 0.42, 0.85, 1.4);
key.position.set(0.6, 7.5, 2.2);
scene.add(key, key.target);
const rimP = new THREE.DirectionalLight(0xfbae9c, 4.2); rimP.position.set(-5, 2.5, -4); scene.add(rimP);
const rimB = new THREE.DirectionalLight(0x81cfe3, 3.6); rimB.position.set(5, 1.8, -3.5); scene.add(rimB);
const hemi = new THREE.HemisphereLight(0xa9b3c9, 0x08080a, 0.25); scene.add(hemi);

const floorMat = new THREE.MeshStandardMaterial({ color: 0x0d0d11, roughness: 0.62, metalness: 0, envMapIntensity: 0.12, transparent: true });
const floor = new THREE.Mesh(new THREE.PlaneGeometry(90, 90), floorMat);
floor.rotation.x = -Math.PI / 2; floor.position.y = FLOOR_Y; scene.add(floor);

function radialTexture(inner, outer) {
  const c = document.createElement('canvas'); c.width = c.height = 128;
  const g = c.getContext('2d'), grd = g.createRadialGradient(64, 64, 0, 64, 64, 64);
  grd.addColorStop(0, inner); grd.addColorStop(1, outer);
  g.fillStyle = grd; g.fillRect(0, 0, 128, 128);
  const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace; return t;
}
const shadow = new THREE.Mesh(new THREE.PlaneGeometry(3.4, 3.4), new THREE.MeshBasicMaterial({ map: radialTexture('rgba(0,0,0,0.75)', 'rgba(0,0,0,0)'), transparent: true, depthWrite: false }));
shadow.rotation.x = -Math.PI / 2; shadow.position.y = FLOOR_Y + 0.003; scene.add(shadow);

const beamMat = new THREE.ShaderMaterial({
  transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, side: THREE.DoubleSide,
  uniforms: { uI: { value: 1 }, uC: { value: new THREE.Color(0xfff0df) } },
  vertexShader: `varying vec2 vUv; varying vec3 vN; varying vec3 vV;
    void main(){ vUv = uv; vec4 mv = modelViewMatrix * vec4(position, 1.0); vN = normalize(normalMatrix * normal); vV = normalize(-mv.xyz); gl_Position = projectionMatrix * mv; }`,
  fragmentShader: `uniform float uI; uniform vec3 uC; varying vec2 vUv; varying vec3 vN; varying vec3 vV;
    void main(){ float h = vUv.y; float f = pow(abs(dot(vN, vV)), 1.8);
      float a = uI * f * pow(h, 0.5) * (1.0 - smoothstep(0.9, 1.0, h)) * smoothstep(0.0, 0.3, h) * 0.26;
      gl_FragColor = vec4(uC * a, a); }`
});
const beam = new THREE.Mesh(new THREE.ConeGeometry(2.6, 9, 72, 1, true), beamMat);
beam.position.set(0, FLOOR_Y + 4.5, 0); scene.add(beam);

/* ── dust in the light ───────────────────────────────────── */
const DUST = small ? 450 : 1100;
const dustGeo = new THREE.BufferGeometry();
{
  const pos = new Float32Array(DUST * 3), seed = new Float32Array(DUST);
  for (let i = 0; i < DUST; i++) {
    const r = Math.sqrt(Math.random()) * 3.2, a = Math.random() * Math.PI * 2;
    pos[i * 3] = Math.cos(a) * r; pos[i * 3 + 1] = FLOOR_Y + Math.random() * 7; pos[i * 3 + 2] = Math.sin(a) * r;
    seed[i] = Math.random();
  }
  dustGeo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  dustGeo.setAttribute('seed', new THREE.BufferAttribute(seed, 1));
}
const dustMat = new THREE.ShaderMaterial({
  transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
  uniforms: { uT: { value: 0 }, uO: { value: 1 }, uPR: { value: renderer.getPixelRatio() }, uBeam: { value: new THREE.Vector2() }, uFloor: { value: FLOOR_Y } },
  vertexShader: `attribute float seed; uniform float uT; uniform float uPR; uniform vec2 uBeam; uniform float uFloor; varying float vA;
    void main(){ vec3 p = position; float H = 7.0;
      p.y = uFloor + mod(p.y - uFloor + uT * (0.05 + seed * 0.08), H);
      p.x += uBeam.x + sin(uT * 0.3 + seed * 6.283) * 0.12; p.z += cos(uT * 0.23 + seed * 4.0) * 0.12;
      vec4 mv = modelViewMatrix * vec4(p, 1.0);
      float inBeam = 1.0 - smoothstep(0.4, 3.0, length(p.xz - vec2(uBeam.x, 0.0)));
      float edge = smoothstep(0.0, 0.6, (p.y - uFloor)) * (1.0 - smoothstep(H - 1.2, H, p.y - uFloor));
      vA = (0.25 + 0.75 * inBeam) * edge * (0.55 + 0.45 * sin(uT * (1.0 + seed * 2.0) + seed * 40.0));
      gl_PointSize = (1.4 + seed * 2.6) * uPR * (6.0 / -mv.z);
      gl_Position = projectionMatrix * mv; }`,
  fragmentShader: `uniform float uO; varying float vA;
    void main(){ float d = length(gl_PointCoord - 0.5); float a = smoothstep(0.5, 0.0, d) * vA * uO; gl_FragColor = vec4(vec3(1.0, 0.95, 0.88) * a, a); }`
});
const dust = new THREE.Points(dustGeo, dustMat); dust.frustumCulled = false; scene.add(dust);

/* ── sparks out of the hat ───────────────────────────────── */
const SPARKS = small ? 260 : 520;
const sp = { pos: new Float32Array(SPARKS * 3), vel: new Float32Array(SPARKS * 3), life: new Float32Array(SPARKS), max: new Float32Array(SPARKS), col: new Float32Array(SPARKS * 3), a: new Float32Array(SPARKS), next: 0 };
const sparkGeo = new THREE.BufferGeometry();
sparkGeo.setAttribute('position', new THREE.BufferAttribute(sp.pos, 3).setUsage(THREE.DynamicDrawUsage));
sparkGeo.setAttribute('color', new THREE.BufferAttribute(sp.col, 3).setUsage(THREE.DynamicDrawUsage));
sparkGeo.setAttribute('alpha', new THREE.BufferAttribute(sp.a, 1).setUsage(THREE.DynamicDrawUsage));
const sparkMat = new THREE.ShaderMaterial({
  transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, vertexColors: true,
  uniforms: { uPR: { value: renderer.getPixelRatio() } },
  vertexShader: `attribute float alpha; uniform float uPR; varying float vA; varying vec3 vC;
    void main(){ vA = alpha; vC = color; vec4 mv = modelViewMatrix * vec4(position, 1.0); gl_PointSize = (2.0 + alpha * 3.5) * uPR * (6.0 / -mv.z); gl_Position = projectionMatrix * mv; }`,
  fragmentShader: `varying float vA; varying vec3 vC;
    void main(){ float d = length(gl_PointCoord - 0.5); float a = smoothstep(0.5, 0.0, d) * vA; gl_FragColor = vec4(vC * a * 1.6, a); }`
});
const sparks = new THREE.Points(sparkGeo, sparkMat); sparks.frustumCulled = false; scene.add(sparks);
const PALETTE = [new THREE.Color(0xffe9c7), new THREE.Color(0xfbae9c), new THREE.Color(0x81cfe3), new THREE.Color(0xffffff)];
const _o = new THREE.Vector3(), _n = new THREE.Vector3(), _t = new THREE.Vector3();
function spawn(count, speed) {
  H.body.updateWorldMatrix(true, false);
  _o.copy(H.opening).applyMatrix4(H.body.matrixWorld);
  _n.set(0, -1, 0).transformDirection(H.body.matrixWorld);   // the opening faces the hat's -Y
  for (let k = 0; k < count; k++) {
    const i = sp.next; sp.next = (sp.next + 1) % SPARKS;
    const a = Math.random() * Math.PI * 2, r = Math.random() * 0.42 * H.hat.scale.x;
    _t.set(Math.cos(a) * r, 0, Math.sin(a) * r).applyQuaternion(H.hat.quaternion);
    sp.pos[i * 3] = _o.x + _t.x; sp.pos[i * 3 + 1] = _o.y + _t.y; sp.pos[i * 3 + 2] = _o.z + _t.z;
    const s = speed * (0.6 + Math.random() * 0.8);
    sp.vel[i * 3] = _n.x * s + (Math.random() - 0.5) * 0.5; sp.vel[i * 3 + 1] = _n.y * s + (Math.random() - 0.3) * 0.4; sp.vel[i * 3 + 2] = _n.z * s + (Math.random() - 0.5) * 0.5;
    sp.max[i] = sp.life[i] = 1.4 + Math.random() * 1.8;
    const c = PALETTE[(Math.random() * PALETTE.length) | 0];
    sp.col[i * 3] = c.r; sp.col[i * 3 + 1] = c.g; sp.col[i * 3 + 2] = c.b;
  }
}
function stepSparks(dt) {
  for (let i = 0; i < SPARKS; i++) {
    if (sp.life[i] <= 0) { sp.a[i] = 0; continue; }
    sp.life[i] -= dt;
    sp.vel[i * 3 + 1] += 0.18 * dt;                    // a little lift, like warm air
    sp.vel[i * 3] *= 0.985; sp.vel[i * 3 + 2] *= 0.985;
    sp.pos[i * 3] += sp.vel[i * 3] * dt; sp.pos[i * 3 + 1] += sp.vel[i * 3 + 1] * dt; sp.pos[i * 3 + 2] += sp.vel[i * 3 + 2] * dt;
    const k = sp.life[i] / sp.max[i];
    sp.a[i] = Math.max(0, Math.sin(k * Math.PI)) * (0.6 + 0.4 * Math.sin(sp.life[i] * 18 + i));
  }
  sparkGeo.attributes.position.needsUpdate = true; sparkGeo.attributes.alpha.needsUpdate = true; sparkGeo.attributes.color.needsUpdate = true;
}

/* ── post: bloom, then grain + vignette on the final image ── */
const composer = new EffectComposer(renderer);
composer.addPass(new RenderPass(scene, camera));
const bloom = new UnrealBloomPass(new THREE.Vector2(innerWidth, innerHeight), 0.55, 0.65, 0.82);
composer.addPass(bloom);
composer.addPass(new OutputPass());
const grain = new ShaderPass({
  uniforms: { tDiffuse: { value: null }, uT: { value: 0 }, uAmt: { value: 0.07 }, uVig: { value: 1 }, uCA: { value: 0.012 }, uRes: { value: new THREE.Vector2(innerWidth, innerHeight) } },
  vertexShader: 'varying vec2 vUv; void main(){ vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
  fragmentShader: `uniform sampler2D tDiffuse; uniform float uT; uniform float uAmt; uniform float uVig; uniform float uCA; uniform vec2 uRes; varying vec2 vUv;
    float hash(vec2 p){ p = fract(p * vec2(123.34, 456.21)); p += dot(p, p + 45.32); return fract(p.x * p.y); }
    void main(){ vec2 d = vUv - 0.5; float r2 = dot(d, d);
      vec3 c; c.r = texture2D(tDiffuse, vUv + d * uCA * r2).r; c.g = texture2D(tDiffuse, vUv).g; c.b = texture2D(tDiffuse, vUv - d * uCA * r2).b;
      c *= mix(1.0, smoothstep(0.98, 0.22, length(d) * 1.28), uVig * 0.75);
      float n = hash(floor(vUv * uRes) + fract(uT) * 917.0) - 0.5;
      c += n * uAmt;
      gl_FragColor = vec4(c, 1.0); }`
});
composer.addPass(grain);

/* ── scene states ────────────────────────────────────────── */
const S = {
  hero:     { cam: [0, 0.45, 6.2], look: [0, 0.1, 0], hat: [1.5, 0.38, 0], rot: [0.16, -0.55, 0.1], scale: 0.96, bg: '#0c0c10', beam: 1, dust: 1, magic: 0.12, exposure: 1, key: 1, rimP: 1, rimB: 1, vig: 1 },
  services: { cam: [0, 0.7, 5.6], look: [0.6, 0.4, 0], hat: [1.6, 0.2, 0], rot: [-2.25, -0.25, 0.2], scale: 1.08, bg: '#0b0a0e', beam: 0.7, dust: 0.8, magic: 1, exposure: 1, key: 0.9, rimP: 1.4, rimB: 0.8, vig: 1 },
  work:     { cam: [0, 0.2, 7.2], look: [0, 0.2, 0], hat: [3.7, 0.35, -1.6], rot: [-1.2, 0.3, 0.9], scale: 0.8, bg: '#09090c', beam: 0.25, dust: 0.5, magic: 0.15, exposure: 0.82, key: 0.6, rimP: 0.8, rimB: 1.2, vig: 1 },
  clients:  { cam: [0, 0.5, 6.4], look: [0, 0.4, 0], hat: [2.35, 1.25, -1], rot: [0.05, 0.5, -0.34], scale: 0.8, bg: '#0b0b0f', beam: 0.5, dust: 0.7, magic: 0.3, exposure: 0.95, key: 0.8, rimP: 1, rimB: 1, vig: 1 },
  studio:   { cam: [0, 0.4, 6], look: [0, 0.2, 0], hat: [-1.7, 0.2, 0], rot: [0.05, 0.9, -0.05], scale: 1.05, bg: '#0d0c0e', beam: 0.85, dust: 0.9, magic: 0.2, exposure: 1, key: 1, rimP: 1.2, rimB: 0.9, vig: 1 },
  contact:  { cam: [0, 0.5, 6.8], look: [0, 0.3, 0], hat: [2.1, 0.9, -0.6], rot: [0.4, -0.8, 0.15], scale: 0.9, bg: '#0c0c10', beam: 0.6, dust: 0.8, magic: 0.5, exposure: 1, key: 0.9, rimP: 1, rimB: 1, vig: 1 },
  outro:    { cam: [0, 0.4, 6.4], look: [0, 0.3, 0], hat: [0, 0, 0], rot: [0, 0, 0], scale: 1, bg: '#f2f1ee', beam: 0, dust: 0, magic: 0, exposure: 0.9, key: 0.25, rimP: 0.2, rimB: 0.2, vig: 0.2, slot: true }
};
S.loop = S.hero;
const portrait = () => innerWidth / innerHeight < 0.85;
function adapt(st) {
  // phones: in the hero the hat sits above the headline; further down it waits small
  // in the top corner, so it never covers the text
  if (!portrait() || st.slot) return st;
  if (st === S.hero) return Object.assign({}, st, { cam: [st.cam[0], st.cam[1], st.cam[2] + 2.4], hat: [st.hat[0] * 0.18, st.hat[1] + 0.95, st.hat[2]], scale: st.scale * 0.92 });
  return Object.assign({}, st, { cam: [st.cam[0], st.cam[1], st.cam[2] + 2.4], hat: [0.8, 2.35, -1.2], scale: 0.5 });
}

const sections = [...document.querySelectorAll('[data-scene]')].map(el => ({ el, key: el.dataset.scene }));
const slotEl = document.getElementById('hatSlot');
function anchors() {
  const vh = innerHeight;
  return sections.filter(s => !s.el.hidden).map(s => {
    const r = s.el.getBoundingClientRect(), top = r.top + scrollY;
    let at;
    if (s.key === 'hero') at = 0;
    else if (s.key === 'loop') at = top;
    else if (s.key === 'outro') at = top + r.height - vh;
    else at = top - vh * 0.35;
    return { key: s.key, at };
  }).sort((a, b) => a.at - b.at);
}

/* ── live state (damped) ─────────────────────────────────── */
const cur = { cam: new THREE.Vector3(0, 0.45, 6.2), look: new THREE.Vector3(0, 0.1, 0) };
const tmp = { q1: new THREE.Quaternion(), q2: new THREE.Quaternion(), e: new THREE.Euler(), v1: new THREE.Vector3(), v2: new THREE.Vector3(), c1: new THREE.Color(), c2: new THREE.Color() };
let smoothY = scrollY;
const pointer = { x: 0, y: 0, sx: 0, sy: 0 };
let hover = 0, hoverT = 0, trickT = -1, lastT = performance.now() / 1000, time = 0;

window.addEventListener('topper:jump', e => { smoothY += (e.detail && e.detail.delta) || 0; });

function slotTransform(outPos, outQuat) {
  // where the hat must sit (and how big) to fill the hat-shaped gap in the footer logo
  const r = slotEl.getBoundingClientRect();
  const D = 6, vH = 2 * D * Math.tan(THREE.MathUtils.degToRad(camera.fov) / 2), vW = vH * camera.aspect, pxw = vH / innerHeight;
  const s = (r.width * pxw) / H.width;
  const cx = r.left + r.width / 2, bottomY = r.bottom;
  const cyPx = bottomY + (H.bottom * s) / pxw;                // H.bottom is negative (below the pivot)
  const nx = (cx / innerWidth) * 2 - 1, ny = -((cyPx / innerHeight) * 2 - 1);
  tmp.v1.set(nx * vW / 2, ny * vH / 2, -D).applyQuaternion(camera.quaternion);
  outPos.copy(camera.position).add(tmp.v1);
  _look.lookAt(outPos, camera.position, tmp.v2.set(0, 1, 0).applyQuaternion(camera.quaternion));
  outQuat.setFromRotationMatrix(_look).multiply(_flip);
  return s;
}

function blendState(y) {
  const list = anchors();
  let i = 0;
  while (i < list.length - 1 && y >= list[i + 1].at) i++;
  const a = list[i], b = list[Math.min(i + 1, list.length - 1)];
  const t = b === a ? 0 : smooth(0.12, 0.88, (y - a.at) / Math.max(1, b.at - a.at));
  return { A: adapt(S[a.key]), B: adapt(S[b.key]), t };
}

const lerpArr = (a, b, t, out) => out.set(a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t);
const lerp = (a, b, t) => a + (b - a) * t;
const _look = new THREE.Matrix4(), _flip = new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(0, 1, 0), Math.PI);
const hatPos = new THREE.Vector3(), hatQuat = new THREE.Quaternion(), slotPos = new THREE.Vector3(), slotQuat = new THREE.Quaternion();
const raycaster = new THREE.Raycaster(), ndc = new THREE.Vector2();

/* ── interaction: hover and click on the hat ─────────────── */
function overUI(target) { return target && target.closest && target.closest('a, button, input, select, textarea, label, [role="button"], .proj, .lightbox, .mnav'); }
function hitHat(clientX, clientY) {
  ndc.set((clientX / innerWidth) * 2 - 1, -(clientY / innerHeight) * 2 + 1);
  raycaster.setFromCamera(ndc, camera);
  return raycaster.intersectObjects(H.meshes, false).length > 0;
}
if (fine) {
  addEventListener('pointermove', e => {
    pointer.x = (e.clientX / innerWidth) * 2 - 1; pointer.y = (e.clientY / innerHeight) * 2 - 1;
    const on = !overUI(e.target) && hitHat(e.clientX, e.clientY);
    hover = on ? 1 : 0;
    document.body.style.cursor = on ? 'pointer' : '';
  }, { passive: true });
}
addEventListener('click', e => {
  if (overUI(e.target) || !hitHat(e.clientX, e.clientY)) return;
  if (trickT >= 0 && time - trickT < 1.2) return;
  trickT = time;
  spawn(reduce ? 40 : 160, 2.6);
  emit('topper:trick');
});

/* ── resize + adaptive quality ───────────────────────────── */
function resize() {
  small = Math.min(innerWidth, innerHeight) < 700;
  camera.aspect = innerWidth / innerHeight; camera.updateProjectionMatrix();
  renderer.setPixelRatio(Math.min(devicePixelRatio, dprCap));
  renderer.setSize(innerWidth, innerHeight, false);
  composer.setPixelRatio(renderer.getPixelRatio());
  composer.setSize(innerWidth, innerHeight);
  grain.uniforms.uRes.value.set(innerWidth, innerHeight);
  dustMat.uniforms.uPR.value = sparkMat.uniforms.uPR.value = renderer.getPixelRatio();
}
addEventListener('resize', resize);
let frames = 0, slowSum = 0, degraded = 0, bloomOn = true;
function watchQuality(dt) {
  if (degraded >= 2 || frames > 400) return;
  frames++;
  if (frames < 30) return;                                  // let the page settle first
  slowSum += dt;
  if (frames % 90 === 0) {
    const avg = slowSum / 90; slowSum = 0;
    if (avg > 0.026) {                                      // under ~38 fps: lighten the load
      degraded++;
      if (degraded === 1) { bloomOn = false; dprCap = 1.25; }
      else { dprCap = 1; dust.visible = small ? false : dust.visible; }
      resize();
    }
  }
}

/* ── frame ───────────────────────────────────────────────── */
let first = true;
function frame() {
  const now = performance.now() / 1000;
  const realDt = Math.min(0.25, now - lastT), dt = Math.min(0.05, realDt); lastT = now; time += dt;
  watchQuality(dt);

  smoothY = reduce ? scrollY : damp(smoothY, scrollY, 5.5, realDt);
  const { A, B, t } = blendState(smoothY);

  // camera (+ a little parallax from the pointer)
  pointer.sx = damp(pointer.sx, reduce ? 0 : pointer.x, 2.5, dt); pointer.sy = damp(pointer.sy, reduce ? 0 : pointer.y, 2.5, dt);
  lerpArr(A.cam, B.cam, t, tmp.v1); lerpArr(A.look, B.look, t, tmp.v2);
  const par = (1 - (A.slot ? 1 - t : 0) - (B.slot ? t : 0));
  tmp.v1.x += pointer.sx * 0.28 * par; tmp.v1.y -= pointer.sy * 0.16 * par;
  cur.cam.copy(tmp.v1); cur.look.copy(tmp.v2);
  camera.position.copy(cur.cam); camera.lookAt(cur.look); camera.updateMatrixWorld();

  // hat: blend the two states; a slot state is computed live so it stays locked to the logo
  const posOf = (st, out, q) => {
    if (st.slot) return slotTransform(out, q);
    lerpArr(st.hat, st.hat, 0, out); q.setFromEuler(tmp.e.set(st.rot[0], st.rot[1], st.rot[2])); return st.scale;
  };
  const sA = posOf(A, hatPos, hatQuat);
  const sB = posOf(B, slotPos, slotQuat);
  hatPos.lerp(slotPos, t); hatQuat.slerp(slotQuat, t);
  let scale = lerp(sA, sB, t);
  const live = 1 - ((A.slot ? 1 - t : 0) + (B.slot ? t : 0));   // 0 when locked in the logo

  // idle float, sway, hover lift and the click trick
  hoverT = damp(hoverT, hover, 6, dt);
  const idle = reduce ? 0 : live;
  hatPos.y += (Math.sin(time * 0.9) * 0.05 + hoverT * 0.08) * idle;
  const sway = Math.sin(time * 0.35) * 0.3 * idle + pointer.sx * 0.25 * idle;
  let spin = 0, hop = 0;
  if (trickT >= 0) {
    const k = clamp((time - trickT) / 1.15, 0, 1), e = 1 - Math.pow(1 - k, 3);
    spin = e * Math.PI * 2 * (reduce ? 0 : 1); hop = Math.sin(k * Math.PI) * 0.35 * (reduce ? 0 : 1);
    if (k >= 1) trickT = -1;
  }
  hatPos.y += hop * live;
  H.hat.position.copy(hatPos);
  H.hat.quaternion.copy(hatQuat);
  H.hat.rotateY(sway + spin * live);
  H.hat.rotateZ(-hoverT * 0.06 * idle);
  H.hat.scale.setScalar(scale);

  // light, colour, atmosphere
  const L = k => lerp(A[k], B[k], t);
  tmp.c1.set(A.bg); tmp.c2.set(B.bg); bgColor.copy(tmp.c1).lerp(tmp.c2, t);
  scene.fog.color.copy(bgColor);
  floorMat.color.copy(bgColor).multiplyScalar(0.9); floorMat.opacity = live;
  renderer.toneMappingExposure = L('exposure');
  key.intensity = 210 * L('key'); rimP.intensity = 4.2 * L('rimP'); rimB.intensity = 3.6 * L('rimB');
  key.position.set(hatPos.x * 0.6 + 0.6, 7, 3.4); key.target.position.copy(hatPos);
  beam.position.x = hatPos.x * 0.85; beam.position.z = hatPos.z * 0.6;
  beamMat.uniforms.uI.value = L('beam');
  dustMat.uniforms.uO.value = L('dust'); dustMat.uniforms.uT.value = time; dustMat.uniforms.uBeam.value.set(beam.position.x, 0);
  const magic = L('magic') + hoverT * 0.4;
  H.glowMat.uniforms.uI.value = magic * 1.2; H.light.intensity = magic * 7;
  shadow.position.x = hatPos.x; shadow.position.z = hatPos.z;
  shadow.material.opacity = clamp(1 - (hatPos.y - FLOOR_Y - 1) / 2.4, 0, 1) * live * 0.9;
  H.felt.envMapIntensity = 0.18 * live + 0.02; H.brimFelt.envMapIntensity = H.felt.envMapIntensity;
  grain.uniforms.uVig.value = L('vig'); grain.uniforms.uT.value = time;
  grain.uniforms.uAmt.value = 0.07 - (1 - live) * 0.03;

  // sparks: a steady trickle while the hat is open, more on hover
  if (!reduce && magic > 0.25 && live > 0.5) {
    const rate = (magic - 0.25) * 70 + hoverT * 30;
    const n = Math.floor(rate * dt + Math.random());
    if (n > 0) spawn(n, 1.1);
  }
  stepSparks(dt);

  bloom.strength = 0.55 * live; bloom.enabled = bloomOn && live > 0.02;
  composer.render(dt);
  if (first) { first = false; canvas.classList.add('on'); emit('topper:stage', { ok: true }); }
  requestAnimationFrame(frame);
}
resize();
requestAnimationFrame(frame);
