/* topper. stage — the work deck. Each project is a card (3:2, like the films) dealt out of the
   hat onto a rising spiral; scroll turns the spiral so one card at a time faces you and plays
   its film. Cards are laid out in camera space, so the layout holds at any aspect ratio.
   The cards write alpha 0, which keeps them out of the bloom and the tone mapping (see post.js). */
import * as THREE from '../../vendor/three/three.module.min.js';
import { clamp, lerp, fit, ease, Spring } from './util.js?v=c8593b5444';

const ASPECT = 1.5;

const vert = `uniform float uBend; varying vec2 vUv; varying vec3 vP;
  void main(){ vUv = uv; vec3 p = position; p.z += uBend * (p.x * p.x - 0.5);
    vP = p; gl_Position = projectionMatrix * modelViewMatrix * vec4(p, 1.0); }`;
const frag = `precision highp float;
  uniform sampler2D uPoster; uniform sampler2D uVideo; uniform sampler2D uBack;
  uniform float uVid; uniform float uDim; uniform float uHover; uniform vec2 uGlint; uniform float uFade; uniform float uRadius; uniform float uSat;
  varying vec2 vUv;
  float rbox(vec2 p, vec2 b, float r){ vec2 q = abs(p) - b + r; return length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - r; }
  vec3 img(vec2 uv){ vec3 c = texture2D(uPoster, uv).rgb; if (uVid > 0.001) c = mix(c, texture2D(uVideo, uv).rgb, uVid); return c; }
  void main(){
    vec2 p = (vUv - 0.5) * vec2(${ASPECT.toFixed(2)}, 1.0);
    float d = rbox(p, vec2(${(ASPECT / 2).toFixed(3)}, 0.5), uRadius);
    if (d > 0.0) discard;
    vec3 c;
    if (gl_FrontFacing) {
      c = img(vUv);
      // a soft glint that follows the cursor across the focused card
      float g = exp(-dot(vUv - uGlint, vUv - uGlint) * 9.0) * uHover;
      c += vec3(1.0, 0.92, 0.86) * g * 0.07;
    } else {
      c = texture2D(uBack, vec2(1.0 - vUv.x, vUv.y)).rgb;
    }
    // a hairline of light on the edge, like the bevel of a card
    c += vec3(1.0, 0.95, 0.9) * smoothstep(-0.006, 0.0, d) * 0.12;
    c = mix(vec3(dot(c, vec3(0.2126, 0.7152, 0.0722))), c, uSat);
    c *= 1.0 - uDim;
    c = mix(vec3(0.047, 0.047, 0.063) * 0.12, c, uFade);
    gl_FragColor = vec4(c, 0.0);
  }`;

/* the card back: brand colours, a lattice of tiny hats, the wordmark in the middle */
function cardBack(wordmark) {
  const W = 1200, H = 800, c = document.createElement('canvas'); c.width = W; c.height = H;
  const g = c.getContext('2d');
  g.fillStyle = '#111116'; g.fillRect(0, 0, W, H);
  // diagonal lattice
  g.save(); g.globalAlpha = 0.09; g.strokeStyle = '#f2f1ee'; g.lineWidth = 1.2;
  for (let i = -H; i < W + H; i += 28) { g.beginPath(); g.moveTo(i, 0); g.lineTo(i + H, H); g.stroke(); g.beginPath(); g.moveTo(i, H); g.lineTo(i + H, 0); g.stroke(); }
  g.restore();
  const frame = (inset, col, w, r) => { g.strokeStyle = col; g.lineWidth = w; g.beginPath(); g.roundRect(inset, inset, W - inset * 2, H - inset * 2, r); g.stroke(); };
  g.fillStyle = '#111116'; g.fillRect(W / 2 - 230, H / 2 - 92, 460, 184);
  frame(34, 'rgba(242,241,238,.35)', 1.5, 0);
  if (wordmark && wordmark.width) {
    const ww = 360, wh = ww * wordmark.height / wordmark.width;
    g.drawImage(wordmark, W / 2 - ww / 2, H / 2 - wh / 2 - 6, ww, wh);
  }
  const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace; t.anisotropy = 8;
  return t;
}

export function makeDeck({ renderer, camera, onFocus, assetBase = '', autoplay = true }) {
  const group = new THREE.Group();
  group.visible = false;
  camera.add(group);
  const loader = new THREE.TextureLoader();
  const aniso = renderer.capabilities.getMaxAnisotropy();
  const geo = new THREE.PlaneGeometry(ASPECT, 1, 32, 1);
  const black = new THREE.DataTexture(new Uint8Array([12, 12, 16, 255]), 1, 1); black.needsUpdate = true;
  let back = black;
  const word = new Image();
  word.onload = () => { back = cardBack(word); cards.forEach(c => { c.mat.uniforms.uBack.value = back; }); };
  word.src = assetBase + 'assets/brand/topper-wordmark-white.svg';

  const posters = new Map(), videos = new Map();
  function poster(url) {
    if (!posters.has(url)) {
      const t = loader.load(url); t.colorSpace = THREE.SRGBColorSpace; t.anisotropy = aniso;
      posters.set(url, t);
    }
    return posters.get(url);
  }
  // one <video> per project, created when its card first comes to the front
  function video(p) {
    if (!videos.has(p.video)) {
      const v = document.createElement('video');
      Object.assign(v, { muted: true, loop: true, playsInline: true, preload: 'auto' });
      v.setAttribute('muted', ''); v.setAttribute('playsinline', '');
      v.src = p.video;
      const t = new THREE.VideoTexture(v); t.colorSpace = THREE.SRGBColorSpace;
      videos.set(p.video, { v, t });
    }
    return videos.get(p.video);
  }

  let cards = [];
  function build(list) {
    cards.forEach(c => { group.remove(c.mesh); c.mat.dispose(); });
    cards = list.map((p, i) => {
      const mat = new THREE.ShaderMaterial({
        uniforms: {
          uPoster: { value: poster(p.poster) }, uVideo: { value: black }, uBack: { value: back },
          uVid: { value: 0 }, uDim: { value: 0 }, uHover: { value: 0 }, uGlint: { value: new THREE.Vector2(0.5, 0.5) },
          uFade: { value: 1 }, uRadius: { value: 0.05 }, uBend: { value: 0 }, uSat: { value: 1 }
        },
        vertexShader: vert, fragmentShader: frag, side: THREE.DoubleSide
      });
      const mesh = new THREE.Mesh(geo, mat);
      mesh.userData.k = i;
      mesh.frustumCulled = false;
      group.add(mesh);
      return { p, mesh, mat, spin: (Math.random() - 0.5) * 2.4, tilt: new Spring(1.6, 0.6, 1, [0, 0]) };
    });
  }

  /* filter: fold the cards back into the hat, swap the set, deal again */
  let pending = null, shuffle = 1;
  function setProjects(list, animate) {
    if (!animate || !cards.length) { build(list); shuffle = 1; return; }
    pending = list; shuffle = 0;
  }

  const hatLocal = new THREE.Vector3();
  const tmpE = new THREE.Euler(), qa = new THREE.Quaternion(), qb = new THREE.Quaternion();
  let focus = -1, size = { w: 1, h: 1, d: 6, cx: 0, cy: 0 }, hoverK = -1;

  /* where the focused card sits, and how big, in camera space */
  function layout(aspect, fovDeg) {
    const portrait = aspect < 0.85;
    const D = 6;
    const vH = 2 * D * Math.tan(THREE.MathUtils.degToRad(fovDeg) / 2), vW = vH * aspect;
    const w = portrait ? 0.86 * vW : Math.min(0.5 * vW, 0.6 * vH * ASPECT);
    const sx = portrait ? 0.5 : 0.53, sy = portrait ? 0.46 : 0.44;     // screen fractions (y down)
    size = { w, h: w / ASPECT, d: D, cx: (sx - 0.5) * vW, cy: (0.5 - sy) * vH, portrait };
  }

  /* p: scroll position along the deck (0 … n-1); deal: 0 in the hat … 1 dealt; vel: scroll speed */
  function update(dt, time, s) {
    group.visible = s.visible && cards.length > 0;
    if (shuffle < 1) {
      shuffle = Math.min(1, shuffle + dt / 1.3);
      if (pending && shuffle >= 0.5) { build(pending); pending = null; focus = -1; }
    }
    if (!group.visible) { pauseAll(); return; }
    const fold = shuffle < 0.5 ? 1 - ease.house(shuffle * 2) : ease.house((shuffle - 0.5) * 2);
    const n = cards.length, gap = size.w * 0.06, sN = 0.58, dz = 1.6;
    // the neighbours sit beside the card in front, pushed back and turned in, far enough out that
    // (after perspective) their inner edge never crosses the front card: x = (w/2 + gap)(D + dz)/D + sN·w/2
    const X1 = (size.w / 2 + gap) * (size.d + dz) / size.d + size.w * sN / 2, X2 = size.w * sN + gap;
    const p = clamp(s.p, 0, Math.max(0, n - 1));
    // the hat's opening, in camera space: cards come out of (and go back into) it
    hatLocal.copy(s.hatOpening); camera.worldToLocal(hatLocal);
    const nf = Math.round(p);
    if (nf !== focus && s.deal > 0.5) { focus = nf; onFocus && onFocus(cards[focus] ? cards[focus].p : null, focus, n); }

    for (let i = 0; i < n; i++) {
      const c = cards[i], k = i - p, u = c.mat.uniforms;
      // the slot in the row: the front card, its neighbours turned in on either side
      const ak0 = Math.abs(k), t1 = ease.inOut(Math.min(ak0, 1)), sg = Math.sign(k);
      const sx = size.cx + sg * (t1 * X1 + Math.max(0, ak0 - 1) * X2), sy = size.cy, sz = -size.d - Math.min(ak0, 1.5) * dz;
      const th = -sg * t1 * 0.55, slot = lerp(1, sN, t1);
      // dealt in one after another (the focused card first), then folded back for a filter
      const order = Math.abs(i - Math.round(p));
      const e = ease.house(clamp((s.deal * (1 + n * 0.08) - order * 0.08), 0, 1)) * fold;
      const arc = Math.sin(e * Math.PI) * size.h * 0.6;
      c.mesh.position.set(lerp(hatLocal.x, sx, e), lerp(hatLocal.y, sy, e) + arc, lerp(hatLocal.z, sz, e));
      const sc = size.h * lerp(0.08, slot, e);
      c.mesh.scale.set(sc, sc, sc);
      // rotation: lying flat in the hat with a random spin → facing out of the spiral
      const tl = c.tilt.update(dt, i === focus && hoverK === i && s.pointer ? [s.pointer.y * 0.12, s.pointer.x * 0.16] : [0, 0]);
      qa.setFromEuler(tmpE.set(-Math.PI / 2, c.spin, 0));
      qb.setFromEuler(tmpE.set(tl[0] - s.vel * 0.004, th + tl[1], 0));
      c.mesh.quaternion.slerpQuaternions(qa, qb, e);
      // depth cues: dim and fade with distance from the front
      const ak = Math.abs(k);
      // the film in front owns the frame: the others are darker, greyer, and the ones already seen fade early
      u.uDim.value = fit(ak, 0.08, 1.1, 0, 0.64) + (1 - e) * 0.3;
      u.uSat.value = 1 - fit(ak, 0.1, 1.0, 0, 0.8);
      u.uFade.value = fit(ak0, 1.3, 1.9, 1, 0) * fit(e, 0, 0.25, 0, 1);
      c.mesh.visible = u.uFade.value > 0.002;
      u.uBend.value = clamp(s.vel * 0.0025, -0.12, 0.12) * e;
      u.uHover.value += ((hoverK === i ? 1 : 0) - u.uHover.value) * Math.min(1, dt * 8);
      if (s.glint && hoverK === i) u.uGlint.value.copy(s.glint);
      // the front card plays its film
      const playing = autoplay && i === focus && ak < 0.35 && e > 0.9;
      if (playing || u.uVid.value > 0.001) {
        const V = video(c.p);
        if (playing) {
          if (V.v.paused) { const pr = V.v.play(); if (pr && pr.catch) pr.catch(() => {}); }
          u.uVideo.value = V.t;
          if (V.v.readyState >= 2) u.uVid.value = Math.min(1, u.uVid.value + dt * 2.5);
        } else {
          u.uVid.value = Math.max(0, u.uVid.value - dt * 3);
          if (u.uVid.value <= 0.001 && !V.v.paused) V.v.pause();
        }
      }
    }
  }
  function pauseAll() { videos.forEach(V => { if (!V.v.paused) V.v.pause(); }); cards.forEach(c => { c.mat.uniforms.uVid.value = 0; }); }

  /* picking: which card is under the pointer (and where on it, for the glint) */
  const ray = new THREE.Raycaster(), ndc = new THREE.Vector2();
  function pick(x, y) {
    if (!group.visible) return null;
    ndc.set((x / innerWidth) * 2 - 1, -(y / innerHeight) * 2 + 1);
    ray.setFromCamera(ndc, camera);
    const hits = ray.intersectObjects(cards.filter(c => c.mesh.visible && c.mat.uniforms.uFade.value > 0.5).map(c => c.mesh), false);
    return hits.length ? { k: hits[0].object.userData.k, uv: hits[0].uv } : null;
  }
  function hover(k) { hoverK = k; }

  return { group, setProjects, update, layout, pick, hover, get focus() { return focus; }, get count() { return cards.length; }, textures: () => [...posters.values(), back] };
}
