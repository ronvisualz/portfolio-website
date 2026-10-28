/* topper. stage — type in the scene. A DOM heading marked data-t3d is drawn word by word onto a
   canvas (same font, same line breaks: each word is placed where the browser laid it out), and that
   canvas becomes a plane hung off the camera at a chosen depth, locked to the heading's box on screen.
   So layout stays CSS, but the type lives in the 3D: the hat passes in front of it, the bloom and the
   grade touch it, and it can burn away. The DOM text stays for screen readers (it is made transparent). */
import * as THREE from '../../vendor/three/three.module.min.js';

/* draw an element's words onto a canvas, where the browser put them. Returns { canvas, w, h, scale }. */
export function rasterDOM(el, maxScale = 2) {
  const box = el.getBoundingClientRect();
  const scale = Math.min(maxScale, Math.max(1, devicePixelRatio || 1));
  const w = Math.max(1, Math.ceil(box.width)), h = Math.max(1, Math.ceil(box.height));
  const c = document.createElement('canvas'); c.width = Math.ceil(w * scale); c.height = Math.ceil(h * scale);
  const g = c.getContext('2d'); g.scale(scale, scale); g.fillStyle = '#fff'; g.textBaseline = 'alphabetic';
  const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
  const range = document.createRange();
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const cs = getComputedStyle(n.parentElement);
    g.font = `${cs.fontStyle} ${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
    if ('letterSpacing' in g) g.letterSpacing = cs.letterSpacing === 'normal' ? '0px' : cs.letterSpacing;
    const m = g.measureText('Hg'), asc = m.fontBoundingBoxAscent || parseFloat(cs.fontSize) * 0.8, desc = m.fontBoundingBoxDescent || parseFloat(cs.fontSize) * 0.2;
    const text = n.textContent, re = /\S+/g;
    let mm;
    while ((mm = re.exec(text))) {
      range.setStart(n, mm.index); range.setEnd(n, mm.index + mm[0].length);
      const rs = range.getClientRects(); if (!rs.length) continue;
      const r = rs[0];
      g.fillText(mm[0], r.left - box.left, r.top - box.top + (r.height - asc - desc) / 2 + asc);
    }
  }
  return { canvas: c, w, h, scale };
}

const vert = `varying vec2 vUv; void main(){ vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }`;
const frag = `precision highp float;
  uniform sampler2D uMap; uniform vec3 uColor; uniform float uO; uniform float uBurn; uniform float uT; uniform vec2 uLight; uniform vec2 uRes;
  varying vec2 vUv;
  float h21(vec2 p){ return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }
  float vn(vec2 p){ vec2 i = floor(p), f = fract(p); f = f * f * (3.0 - 2.0 * f);
    return mix(mix(h21(i), h21(i + vec2(1, 0)), f.x), mix(h21(i + vec2(0, 1)), h21(i + vec2(1, 1)), f.x), f.y); }
  float fbm(vec2 p){ return vn(p) * 0.55 + vn(p * 2.3) * 0.3 + vn(p * 5.1) * 0.15; }
  void main(){
    float a = texture2D(uMap, vUv).a;
    if (a < 0.01) discard;
    // the stage light: type is a little brighter where the spotlight falls
    vec2 s = gl_FragCoord.xy / uRes;
    float lit = 0.82 + 0.28 * smoothstep(0.75, 0.0, distance(s, uLight));
    vec3 c = uColor * lit;
    // burn: a noisy edge eats the letters, glowing white and light blue as it goes
    if (uBurn > 0.0) {
      float n = fbm(vUv * vec2(9.0, 3.0) + uT * 0.15);
      float e = n - uBurn * 1.15;
      if (e < 0.0) discard;
      float rim = 1.0 - smoothstep(0.0, 0.08, e);
      c = mix(c, mix(vec3(1.0), vec3(0.51, 0.81, 0.89), n) * 3.0, rim);
    }
    gl_FragColor = vec4(c, a * uO);
  }`;

/* a plane per heading, hung off the camera at depth d (world units in front of the lens) */
export function makeType(camera) {
  const group = new THREE.Group(); camera.add(group);
  const items = [];
  const geo = new THREE.PlaneGeometry(1, 1);
  function add(el, d = 7.5, color = 0xf2f1ee) {
    const mat = new THREE.ShaderMaterial({
      transparent: true, depthWrite: false,
      uniforms: { uMap: { value: null }, uColor: { value: new THREE.Color(color) }, uO: { value: 1 }, uBurn: { value: 0 }, uT: { value: 0 }, uLight: { value: new THREE.Vector2(0.7, 0.6) }, uRes: { value: new THREE.Vector2(1, 1) } },
      vertexShader: vert, fragmentShader: frag
    });
    const mesh = new THREE.Mesh(geo, mat); mesh.renderOrder = 1; mesh.frustumCulled = false;
    group.add(mesh);
    const it = { el, d, mesh, mat, w: 1, h: 1, tex: null, ready: false };
    items.push(it); return it;
  }
  /* redraw every heading (after fonts load and on resize) */
  function refresh() {
    for (const it of items) {
      if (!it.el.offsetParent && getComputedStyle(it.el).position !== 'fixed') { it.ready = false; continue; }
      const r = rasterDOM(it.el);
      if (it.tex) it.tex.dispose();
      it.tex = new THREE.CanvasTexture(r.canvas); it.tex.anisotropy = 4; it.tex.generateMipmaps = false; it.tex.minFilter = THREE.LinearFilter;
      it.mat.uniforms.uMap.value = it.tex; it.w = r.w; it.h = r.h; it.ready = true;
    }
  }
  /* lock each plane to its heading's box on screen (y is read live, so the type scrolls with the page) */
  const visH = d => 2 * d * Math.tan(THREE.MathUtils.degToRad(camera.fov) / 2);
  function update(time, res, light) {
    for (const it of items) {
      const r = it.el.getBoundingClientRect(), on = it.ready && r.bottom > -40 && r.top < innerHeight + 40 && it.mat.uniforms.uO.value > 0.002;
      it.mesh.visible = on; if (!on) continue;
      const k = visH(it.d) / innerHeight;
      it.mesh.position.set((r.left + it.w / 2 - innerWidth / 2) * k, -(r.top + it.h / 2 - innerHeight / 2) * k, -it.d);
      it.mesh.scale.set(it.w * k, it.h * k, 1);
      const u = it.mat.uniforms; u.uT.value = time; u.uRes.value.copy(res); if (light) u.uLight.value.copy(light);
    }
  }
  return { group, add, refresh, update, items };
}
