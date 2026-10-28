/* topper. stage — magic dust: the world inside the hat. A few hundred thousand points live in
   camera space. Adrift, they fill the void and stream upward as you scroll (you are falling).
   On cue they gather into a shape — a heading, the magician, a ring of daylight — and let go again.
   Each shape is a target texture (one texel per point); the vertex shader blends the drift and the
   shape, so the CPU never touches a point after start-up. */
import * as THREE from '../../vendor/three/three.module.min.js';
import { rasterDOM } from './type.js?v=c8593b5444';

/* the light of the void: the brand's light blue and blue, and white (seven slots, weighted to the cool ones) */
const PALETTE = ['#81cfe3', '#ffffff', '#2f77b1', '#81cfe3', '#cfeaf3', '#ffffff', '#81cfe3'].map(h => new THREE.Color(h));

const vert = `precision highp float;
  attribute vec2 aRef; attribute vec4 aRnd;
  uniform sampler2D uAmb; uniform sampler2D uForm;
  uniform float uMix; uniform float uBurst; uniform float uT; uniform float uFall; uniform float uTan; uniform float uAspect;
  uniform vec2 uRes; uniform vec2 uEl; uniform float uPR; uniform float uSize; uniform float uCalm; uniform vec3 uPal[7]; uniform float uNear; uniform float uWarp;
  varying vec3 vC; varying float vA;
  vec3 toCam(vec2 px, float d){   // a screen pixel at depth d, in camera space
    float hh = d * uTan; return vec3((px.x / uRes.x - 0.5) * 2.0 * hh * uAspect, -(px.y / uRes.y - 0.5) * 2.0 * hh, -d); }
  void main(){
    vec4 A = texture2D(uAmb, aRef);
    // adrift: rise as the page falls, wrap top to bottom at each depth, and wander a little
    float d0 = -A.z, hh = d0 * uTan * 1.25;
    float d = 2.5 + mod(d0 - 2.5 - uWarp, 13.5);             // uWarp: the cloud streams toward the lens (x, y stay put, so it flies outward)
    float y = A.y + uFall + uT * 0.06 * (0.4 + aRnd.y) * (1.0 - uCalm);
    y = mod(y + hh, 2.0 * hh) - hh;
    vec3 amb = vec3(A.x + sin(uT * 0.3 + aRnd.z * 6.28) * 0.08 * (1.0 - uCalm), y, -d);
    // the burst: everything leaves one point (the hat's opening) and finds its place
    float b = clamp(uBurst * 1.3 - aRnd.w * 0.3, 0.0, 1.0); b = 1.0 - pow(1.0 - b, 3.0);
    amb = mix(vec3(0.0, 0.0, -1.2), amb, b);
    // the shape: points that have a texel in it gather there, staggered, on a swirl
    vec4 F = texture2D(uForm, aRef);
    vec3 p = amb; float formed = 0.0;
    if (F.w > 0.5 && uMix > 0.0) {
      vec3 tgt = toCam(F.xy + uEl, F.z);
      float t = clamp(uMix * 1.6 - aRnd.x * 0.6, 0.0, 1.0); t = t * t * (3.0 - 2.0 * t);
      vec3 sw = normalize(aRnd.yzw - 0.5) * (0.6 + aRnd.x) * F.z * 0.12;
      p = mix(amb, tgt, t) + sw * sin(t * 3.14159);
      p.xy += vec2(sin(uT * 2.0 + aRnd.y * 40.0), cos(uT * 1.7 + aRnd.z * 40.0)) * 0.0025 * F.z * t * (1.0 - uCalm);   // shimmer in place
      formed = t;
    }
    vec4 mv = modelViewMatrix * vec4(p, 1.0);
    float size = uSize * (0.6 + aRnd.y * 0.9) * mix(1.0, 0.75, formed);
    gl_PointSize = size * uPR * (6.0 / -mv.z);
    gl_Position = projectionMatrix * mv;
    vec3 pc = uPal[int(aRnd.z * 6.999)];
    vC = mix(pc, vec3(1.0), 0.25 + formed * 0.55);
    float twinkle = 0.65 + 0.35 * sin(uT * (1.0 + aRnd.w * 3.0) + aRnd.x * 30.0);
    // near the cards the drift keeps behind them: nothing adrift is drawn closer than uNear
    float keep = mix(smoothstep(uNear - 0.8, uNear, -mv.z), 1.0, formed);
    vA = mix(0.25 + 0.35 * aRnd.w, 0.95, formed) * mix(twinkle, 1.0, formed) * smoothstep(0.0, 0.25, b) * keep;
  }`;
const frag = `precision highp float;
  uniform float uO; varying vec3 vC; varying float vA;
  void main(){ float r = length(gl_PointCoord - 0.5); float a = smoothstep(0.5, 0.1, r) * vA * uO; if (a < 0.003) discard; gl_FragColor = vec4(vC * a, a); }`;

export function makeMagicDust(camera, side) {
  const N = side * side;
  const ref = new Float32Array(N * 2), rnd = new Float32Array(N * 4);
  for (let i = 0; i < N; i++) {
    ref[i * 2] = ((i % side) + 0.5) / side; ref[i * 2 + 1] = (Math.floor(i / side) + 0.5) / side;
    for (let k = 0; k < 4; k++) rnd[i * 4 + k] = Math.random();
  }
  const geo = new THREE.BufferGeometry();
  geo.setAttribute('position', new THREE.BufferAttribute(new Float32Array(N * 3), 3));
  geo.setAttribute('aRef', new THREE.BufferAttribute(ref, 2));
  geo.setAttribute('aRnd', new THREE.BufferAttribute(rnd, 4));
  const tex = data => { const t = new THREE.DataTexture(data, side, side, THREE.RGBAFormat, THREE.FloatType); t.minFilter = t.magFilter = THREE.NearestFilter; t.needsUpdate = true; return t; };

  // the drift: a cloud that fills the frustum from 2.5 to 16 units deep
  const tanH = () => Math.tan(THREE.MathUtils.degToRad(camera.fov) / 2);
  const amb = new Float32Array(N * 4);
  function fillAmbient() {
    const t = tanH(), asp = camera.aspect;
    for (let i = 0; i < N; i++) {
      const d = 2.5 + Math.pow(Math.random(), 0.7) * 13.5, hh = d * t * 1.25;
      amb[i * 4] = (Math.random() * 2 - 1) * hh * asp; amb[i * 4 + 1] = (Math.random() * 2 - 1) * hh; amb[i * 4 + 2] = -d; amb[i * 4 + 3] = 1;
    }
  }
  fillAmbient();
  const ambTex = tex(amb);
  const empty = tex(new Float32Array(N * 4));

  const mat = new THREE.ShaderMaterial({
    transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
    uniforms: {
      uAmb: { value: ambTex }, uForm: { value: empty }, uMix: { value: 0 }, uBurst: { value: 1 }, uT: { value: 0 }, uFall: { value: 0 },
      uTan: { value: tanH() }, uAspect: { value: camera.aspect }, uRes: { value: new THREE.Vector2(innerWidth, innerHeight) }, uEl: { value: new THREE.Vector2() },
      uPR: { value: 1 }, uSize: { value: 2.2 }, uO: { value: 0 }, uCalm: { value: 0 }, uPal: { value: PALETTE }, uNear: { value: 0 }, uWarp: { value: 0 }
    },
    vertexShader: vert, fragmentShader: frag
  });
  const points = new THREE.Points(geo, mat); points.frustumCulled = false; points.renderOrder = 3;
  camera.add(points);

  /* a shape from points in screen pixels relative to an anchor element: list of [x, y] at depth d */
  function shape(pts, d, share = 0.8) {
    const data = new Float32Array(N * 4), n = Math.min(pts.length, Math.floor(N * share));
    // spread the shape's points over the whole pool, so every colour and size takes part
    const order = new Uint32Array(N); for (let i = 0; i < N; i++) order[i] = i;
    for (let i = N - 1; i > 0; i--) { const j = (Math.random() * (i + 1)) | 0, t = order[i]; order[i] = order[j]; order[j] = t; }
    for (let k = 0; k < n; k++) {
      const p = pts[(Math.random() * pts.length) | 0], i = order[k];
      data[i * 4] = p[0]; data[i * 4 + 1] = p[1]; data[i * 4 + 2] = d * (1 + (Math.random() - 0.5) * 0.04); data[i * 4 + 3] = 1;
    }
    return tex(data);
  }
  /* a heading's letters (filled pixels of its raster), or an image's dark pixels (the magician) */
  function fromElement(el, d) {
    const r = rasterDOM(el, 1), g = r.canvas.getContext('2d'), img = g.getImageData(0, 0, r.canvas.width, r.canvas.height).data;
    const pts = [];
    for (let y = 0; y < r.canvas.height; y++) for (let x = 0; x < r.canvas.width; x++) if (img[(y * r.canvas.width + x) * 4 + 3] > 128) pts.push([x + Math.random(), y + Math.random()]);
    return pts.length ? shape(pts, d) : empty;
  }
  function fromImage(image, w, h, d) {
    const c = document.createElement('canvas'); c.width = Math.max(1, Math.round(w)); c.height = Math.max(1, Math.round(h));
    const g = c.getContext('2d');
    // cover-fit, as the <img> does
    const s = Math.max(c.width / image.naturalWidth, c.height / image.naturalHeight);
    g.drawImage(image, (c.width - image.naturalWidth * s) / 2, (c.height - image.naturalHeight * s) / 2, image.naturalWidth * s, image.naturalHeight * s);
    const px = g.getImageData(0, 0, c.width, c.height).data, pts = [];
    for (let y = 0; y < c.height; y++) for (let x = 0; x < c.width; x++) {
      const i = (y * c.width + x) * 4, l = (px[i] * 0.3 + px[i + 1] * 0.59 + px[i + 2] * 0.11) / 255;
      if (Math.random() < Math.pow(1 - l, 2.2) * 0.9) pts.push([x + Math.random(), y + Math.random()]);   // the darker, the denser
    }
    return pts.length ? shape(pts, d, 0.9) : empty;
  }
  /* the hat's opening seen from inside: a ring around the middle of the screen */
  function ring(d) {
    const pts = [], R = Math.min(innerWidth, innerHeight) * 0.3;
    for (let i = 0; i < 40000; i++) { const a = Math.random() * 6.2832, r = R * (1 + (Math.random() - 0.5) * 0.08 * Math.random()); pts.push([innerWidth / 2 + Math.cos(a) * r * 1.25, innerHeight / 2 + Math.sin(a) * r * 0.62]); }
    return shape(pts, d, 0.7);
  }

  function resize(pr) {
    const u = mat.uniforms; u.uRes.value.set(innerWidth, innerHeight); u.uAspect.value = camera.aspect; u.uTan.value = tanH(); u.uPR.value = pr;
    fillAmbient(); ambTex.needsUpdate = true;
  }
  return { points, mat, empty, fromElement, fromImage, ring, resize, N };
}
