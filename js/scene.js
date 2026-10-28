/* topper. website v5 — "Into the hat". Daylight first: the logo film, then a black top hat on white.
   Scroll, and the camera dives into the hat; inside is space — smoke and magic dust that gathers into
   each act's words, and the films dealt as cards; at the end we fly back out into daylight and the hat
   lands in the logo.
   Sections name what they are with data-scene. The hat's poses are screen-space states (fractions of the
   viewport) blended along the scroll; the dive and the exit are progress values of their own sections.
   Modules: stage/hat.js (GLB + fallback), fx.js (room, beam, dust, sparks), type.js (headings in 3D),
   dust.js (magic dust), void.js (the void's smoke), deck.js (the work cards), paint.js (cursor
   smoke), post.js (bloom + grade), audio.js (sound), util.js (fit, easing, springs). */
import * as THREE from '../vendor/three/three.module.min.js';
import { RoomEnvironment } from '../vendor/three/addons/environments/RoomEnvironment.js';
import { clamp, lerp, damp, fit, ease, Spring, brownian } from './stage/util.js?v=c8593b5444';
import { loadHat, makeHat, makeLooks } from './stage/hat.js?v=c8593b5444';
import { FLOOR_Y, makeLights, makeFloor, makeBeam, makeShaft, makeDust, makeSparks } from './stage/fx.js?v=c8593b5444';
import { makePaint } from './stage/paint.js?v=c8593b5444';
import { makePost } from './stage/post.js?v=c8593b5444';
import { makeDeck } from './stage/deck.js?v=c8593b5444';
import { makeType } from './stage/type.js?v=c8593b5444';
import { makePhoto } from './stage/photo.js?v=c8593b5444';
import { makeMagicDust } from './stage/dust.js?v=c8593b5444';
import { makeVoid } from './stage/void.js?v=c8593b5444';
import { makeAudio } from './stage/audio.js?v=c8593b5444';

const canvas = document.getElementById('stage');
const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
const fine = matchMedia('(hover: hover) and (pointer: fine)').matches;
const emit = (name, detail) => window.dispatchEvent(new CustomEvent(name, { detail }));
const progress = p => emit('topper:progress', { p });
const shared = window.__topper || { projects: [] };
const quality = new URLSearchParams(location.search).get('quality');   // ?quality=low|med|high overrides the defaults

/* ── sound: chosen at the door (main.js), toggled in the header ── */
const audio = makeAudio();
window.addEventListener('topper:sound', e => { if (e.detail.on) audio.start(); else audio.mute(); });

let renderer;
try {
  renderer = new THREE.WebGLRenderer({ canvas, antialias: false, powerPreference: 'high-performance', stencil: false });
  if (!renderer.getContext() || !renderer.capabilities.isWebGL2) throw new Error('no webgl2');
} catch (e) {
  emit('topper:stage', { ok: false });
  throw e;
}
progress(0.2);

/* ── GPU tier: integrated laptop GPUs get no MSAA, 1× pixels, less dust (measured: HD 620 at 1440×900) ── */
const gpu = (() => { try { const gl = renderer.getContext(), d = gl.getExtension('WEBGL_debug_renderer_info'); return d ? gl.getParameter(d.UNMASKED_RENDERER_WEBGL) : ''; } catch (e) { return ''; } })();
const lowGPU = /Intel(?!.*Arc)|HD Graphics|UHD|Iris|Mali|PowerVR|SwiftShader|llvmpipe|Adreno \(TM\) [1-5]\d\d/i.test(gpu);

/* ── renderer, camera, scene ─────────────────────────────── */
let small = Math.min(innerWidth, innerHeight) < 700;
let dprCap = quality === 'low' ? 1 : small ? 1.5 : lowGPU ? 1 : 1.75;
const pixelRatio = () => Math.min(devicePixelRatio, dprCap, Math.sqrt(3.7e6 / (innerWidth * innerHeight)));
renderer.setPixelRatio(pixelRatio());
renderer.setSize(innerWidth, innerHeight, false);

const scene = new THREE.Scene();
const FOV = 32;
const camera = new THREE.PerspectiveCamera(FOV, innerWidth / innerHeight, 0.05, 100);
scene.add(camera);                                           // the deck, the type and the dust hang off the camera
const pmrem = new THREE.PMREMGenerator(renderer);
scene.environment = pmrem.fromScene(new RoomEnvironment(renderer), 0.04).texture;
const bgColor = new THREE.Color('#0c0c10');
scene.background = bgColor;
scene.fog = new THREE.Fog(bgColor, 9, 26);
const C = { stage: new THREE.Color('#0c0c10'), void: new THREE.Color('#040406'), day: new THREE.Color('#e6e1dc'), flashIn: new THREE.Color(0.86, 0.95, 1), flashOut: new THREE.Color('#e6e1dc'), _x: new THREE.Color(1, 1, 1) };

const tier = quality === 'low' ? 0 : (lowGPU || small) ? 1 : 2;
const L = makeLights(scene);
const F = makeFloor(scene);
const B = makeBeam(scene);
const D = makeDust(scene, small ? 450 : 1100, renderer.getPixelRatio());
const SP = makeSparks(scene, small ? 360 : 900, renderer.getPixelRatio());
const V = makeVoid(scene, tier === 2 ? 3 : 2);
const MD = makeMagicDust(camera, [150, 210, 320][tier]);
const T = makeType(camera);
const studioFig = document.querySelector('.studio-img');
const PH = studioFig ? makePhoto(camera, studioFig) : null;   // the magician's photo, drawn as a plate the hat can float in front of
const paint = makePaint(renderer);
const post = makePost(renderer, scene, camera, paint, { msaa: quality ? quality === 'high' : !small && !lowGPU, bloomScale: lowGPU || small ? 0.5 : 0.75 });
if (reduce || quality === 'low') paint.enabled = false;

/* ── the hat: hand-made GLB, or the code sketch if it can't load ── */
let H = null, S_ = null, LOOKS = null, swapPending = false, studioLook = -1, popT = -1, bowT = -1, bowArmed = true;
function useHat(h) {
  H = h; scene.add(H.hat);
  LOOKS = makeLooks(H);                                      // the trick: click, and the hat changes what it is made of
  for (const m of H.meshes) if (m.userData.role !== 'lining') { m.material.alphaHash = true; m.material.needsUpdate = true; }   // compiled once, used by the entrance
  S_ = makeShaft(H.body);
  H.meshes.forEach(m => { m.userData.hat = true; });
}

/* ── the work deck ───────────────────────────────────────── */
const deckEl = document.getElementById('deck');
const deck = makeDeck({
  renderer, camera, autoplay: !reduce,                       // reduced motion: posters only, films play in the lightbox
  onFocus: (p, k, n) => { emit('topper:focus', { p, k, n }); audio.play('card', 0.7); }
});
const visibleProjects = () => (shared.projects || []).filter(p => !shared.tab || shared.tab === 'all' || p.tab === shared.tab);
deck.setProjects(visibleProjects(), false);
window.addEventListener('topper:filter', () => { deck.setProjects(visibleProjects(), !reduce); measure(); });

/* ── type in the scene: the hero headline behind the hat; the void's headings, which the dust also forms ── */
const heroType = [...document.querySelectorAll('.hero [data-t3d]')].map(el => T.add(el, 7.8, 0x0c0c10));   // ink on daylight
const dustEls = [...document.querySelectorAll('[data-dust]')];
const dustType = new Map(dustEls.filter(el => el.hasAttribute('data-t3d')).map(el => [el, T.add(el, 5.6)]));
const forms = [];                                            // { el, tex, img? }
function buildForms() {
  forms.length = 0;
  for (const el of dustEls) {
    if (!el.offsetParent) continue;
    const r = el.getBoundingClientRect();
    if (el.tagName === 'IMG') {
      if (!el.complete || !el.naturalWidth) { el.addEventListener('load', () => requestAnimationFrame(buildForms), { once: true }); continue; }
      forms.push({ el, tex: MD.fromImage(el, r.width, r.height, 5.2), img: true });
    } else forms.push({ el, tex: MD.fromElement(el, 5.6) });
  }
  ringTex = MD.ring(5);
}
let ringTex = null;
document.documentElement.classList.add('t3d');               // the DOM headings go transparent: the stage draws them

/* ── hat states (screen space: x, y = viewport fractions, h = hat height / viewport height; dist = distance
   from the lens when the camera dives in). intro: waits below the film. hero: presented on white. dive: tips
   its opening to you and swallows the camera. above: waits over the daylight at the end. outro: lands in the
   wordmark and becomes the logo's own glyph. Light is daylight: a soft key and rims, no spotlight. ── */
const DAY = { beam: 0, dust: 0, shaft: 0, swirl: 0, exposure: 0.95, key: 0.42, rimP: 0.55, rimB: 0.55, vig: 0.2 };
const S = {
  intro:  { ...DAY, at: [0.74, 1.45], h: 0.4, rot: [0.5, -0.9, 0.2], cam: [0, 0.45, 6.2], look: [0, 0.1, 0], magic: 0 },
  hero:   { ...DAY, at: [0.76, 0.3], h: 0.36, rot: [0.16, -0.55, 0.1], cam: [0, 0.45, 6.2], look: [0, 0.1, 0], magic: 0.12 },
  diveA:  { ...DAY, at: [0.5, 0.58], h: 0.46, rot: [-1.05, 0.25, 0.08], cam: [0, 0.4, 6.2], look: [0, 0.1, 0], magic: 0.9, rimP: 0.9, rimB: 0.9 },
  diveB:  { ...DAY, at: [0.5, 0.5], h: 0.46, rot: [-Math.PI / 2, 0, 0], dist: 0.42, cam: [0, 0.4, 6.2], look: [0, 0.1, 0], magic: 4, exposure: 1.05, rimP: 1.2, rimB: 1.2 },
  gone:   { ...DAY, hide: true, at: [0.5, 0.5], h: 0.3, rot: [0, 0, 0], cam: [0, 0.4, 6.2], look: [0, 0.1, 0], magic: 0, vig: 1 },
  exitA:  { ...DAY, at: [0.5, 0.5], h: 0.42, rot: [-Math.PI / 2, 0, 0], dist: 0.42, cam: [0, 0.4, 6.2], look: [0, 0.1, 0], magic: 2.5 },
  exitB:  { ...DAY, at: [0.5, 0.52], h: 0.42, rot: [0.12, -0.45, 0.06], cam: [0, 0.45, 6.2], look: [0, 0.1, 0], magic: 0.1 },
  studio: { ...DAY, at: [0.5, 0.5], h: 0.2, pin: '.studio-img', box: [0.77, 0.32, 0.17], rot: [0.2, -0.6, 0.12], cam: [0, 0.45, 6.2], look: [0, 0.1, 0], magic: 0 },   // floats out of the magician's open hand
  contact:{ ...DAY, at: [0.87, 0.68], h: 0.2, rot: [0.3, -0.85, 0.1], cam: [0, 0.45, 6.2], look: [0, 0.1, 0], magic: 0 },
  outro:  { ...DAY, at: [0.5, 0.5], h: 0.2, rot: [0, 0, 0], cam: [0, 0.4, 6.4], look: [0, 0.3, 0], magic: 0, exposure: 0.9, key: 0.25, rimP: 0.2, rimB: 0.2, slot: true }
};
for (const k in S) S[k].name = k;
const PORTRAIT = { intro: { at: [0.5, 1.4], h: 0.2 }, hero: { at: [0.5, 0.45], h: 0.19 }, diveA: { h: 0.3 },
  // on a phone the daylight hats ride with their lines (a fixed spot would sit on the photo or the form)
  contact: { at: [0.8, -0.13], h: 0.09, pin: '.contact .sec-title' } };
const pins = {};                                                    // each pinned element's box: left (px), top in the document (px), width, height
const portrait = () => innerWidth / innerHeight < 0.85;
const stateOf = key => (portrait() && PORTRAIT[key]) ? Object.assign({}, S[key], PORTRAIT[key]) : S[key];

/* ── the timeline: measured on resize only ───────────────── */
const sec = {};
document.querySelectorAll('[data-scene]').forEach(el => { sec[el.dataset.scene] = { el, top: 0, h: 1 }; });
const slotEl = document.getElementById('hatSlot');
let anchors = [], deckRange = { top: 0, end: 1, on: false };
const range = { dive: [0, 1], exit: [0, 1], studio: [0, 1] };               // scroll positions where each spacer's progress runs 0 → 1
function measure() {
  const vh = innerHeight;
  for (const k in sec) { const r = sec[k].el.getBoundingClientRect(); sec[k].top = r.top + scrollY; sec[k].h = r.height; }
  for (const st of [...Object.values(S), ...Object.values(PORTRAIT)]) { const sel = st.pin, el = sel && document.querySelector(sel); if (el) { const r = el.getBoundingClientRect(); pins[sel] = { left: r.left, top: r.top + scrollY, w: r.width, h: r.height }; } }
  const dv = sec.dive, ex = sec.exit, he = sec.hero, ou = sec.outro, st = sec.studio, ct = sec.contact;
  range.dive = [dv.top - vh * 0.35, dv.top + dv.h - vh]; range.exit = [ex.top - vh * 0.6, ex.top + ex.h - vh]; range.studio = [st.top - vh * 0.55, st.top + st.h - vh * 0.45];
  const out = lerp(range.exit[0], range.exit[1], 0.58);      // we come out of the hat here (under the flash)
  const cut = lerp(range.dive[0], range.dive[1], 0.6);       // the hat swallows the camera here (under the flash)
  anchors = [
    ...(he.top > vh * 0.6 ? [{ key: 'intro', at: 0 }] : []),            // a short film band (phones): the hat is already there
    { key: 'hero', at: Math.max(0, he.top - vh * 0.1) },
    { key: 'diveA', at: range.dive[0] + vh * 0.3 },
    { key: 'diveB', at: cut, win: [0, 1] },
    { key: 'gone', at: cut + 1, win: [0, 0] },
    { key: 'gone', at: out - 1, win: [0, 0] },
    { key: 'exitA', at: out, win: [0, 0] },
    { key: 'exitB', at: lerp(range.exit[0], range.exit[1], 0.97), win: [0, 1] },
    { key: 'studio', at: st.top - vh * 0.1 },
    { key: 'contact', at: ct.top - vh * 0.25 },
    { key: 'outro', at: ou.top + ou.h - vh, from: ou.top - vh * 0.55 }
  ];
  if (deckEl && deck.count) {
    const dr = deckEl.getBoundingClientRect(), dTop = dr.top + scrollY;
    deckRange = { top: dTop, end: dTop + dr.height - vh, on: true };
  }
}
function blendAt(y) {
  let i = 0;
  while (i < anchors.length - 1 && y >= anchors[i + 1].at) i++;
  const a = anchors[i], b = anchors[Math.min(i + 1, anchors.length - 1)];
  const gap = Math.max(1, b.at - a.at);
  let w = b.from != null ? [clamp((b.from - a.at) / gap, 0, 0.95), 1] : b.win || [0.12, 0.88];
  if (stateOf(a.key).pin && stateOf(b.key).pin) w = [0.55, 0.95];   // pinned to lines: it rides with the first one, then crosses over
  const t = b === a ? 0 : (w[1] <= w[0] ? (y >= b.at ? 1 : 0) : fit(y, a.at + gap * w[0], a.at + gap * w[1], 0, 1, b.key === 'diveB' ? ease.in : b.key === 'exitB' ? ease.out : ease.inOut));
  return { A: stateOf(a.key), B: stateOf(b.key), t };
}
const prog = k => fit(smoothY, range[k][0], range[k][1]);

/* ── placing the hat ─────────────────────────────────────── */
const tmp = { v1: new THREE.Vector3(), v2: new THREE.Vector3(), e: new THREE.Euler(), c1: new THREE.Color(), m: new THREE.Matrix4() };
const _flip = new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(0, 1, 0), Math.PI);
const DIST = 6.2;
function screenToWorld(sx, sy, d, out) {
  const vH = 2 * d * Math.tan(THREE.MathUtils.degToRad(camera.fov) / 2), vW = vH * camera.aspect;
  tmp.v1.set((sx - 0.5) * vW, (0.5 - sy) * vH, -d).applyQuaternion(camera.quaternion);
  return out.copy(camera.position).add(tmp.v1);
}
const visH = d => 2 * d * Math.tan(THREE.MathUtils.degToRad(camera.fov) / 2);
function stateTransform(st, d, outPos, outQuat) {
  if (st.slot) return slotTransform(outPos, outQuat);
  const pb = st.pin && pins[st.pin];
  let sx = st.at[0], sy = st.at[1] + (st.name === 'hero' ? heroLift : 0), h = st.h;   // the hero hat scrolls in with its section, 1:1
  if (pb && st.box) { sx = (pb.left + st.box[0] * pb.w) / innerWidth; sy = (pb.top + st.box[1] * pb.h - scrollY) / innerHeight; h = st.box[2] * pb.h / innerHeight; }   // a spot inside a box (the magician's hand)
  else if (pb) sy = (pb.top + pb.h / 2 - scrollY) / innerHeight + st.at[1];   // a pinned hat rides with its line
  screenToWorld(sx, sy, d, outPos);
  // rotations are relative to the camera's facing, so a state looks the same wherever the camera is
  outQuat.setFromEuler(tmp.e.set(st.rot[0], st.rot[1], st.rot[2])).premultiply(camera.quaternion);
  return h * visH(DIST) / H.height;                          // the size is set at the stage distance: coming closer, it grows
}
function slotTransform(outPos, outQuat) {
  // the hat fills the hat-shaped gap in the footer logo, locked to it pixel for pixel
  const r = slotEl.getBoundingClientRect();
  const pxw = visH(DIST) / innerHeight;
  const s = (r.width * pxw) / H.width;
  const cyPx = r.bottom + (H.bottom * s) / pxw;               // H.bottom is negative (below the pivot)
  screenToWorld((r.left + r.width / 2) / innerWidth, cyPx / innerHeight, DIST, outPos);
  tmp.m.lookAt(outPos, camera.position, tmp.v2.set(0, 1, 0).applyQuaternion(camera.quaternion));
  outQuat.setFromRotationMatrix(tmp.m).multiply(_flip);
  return s;
}

/* ── input ───────────────────────────────────────────────── */
const pointer = { x: 0, y: 0, cx: -1, cy: -1, in: false };
const raycaster = new THREE.Raycaster(), ndc = new THREE.Vector2();
let hover = 0, hoverT = 0, trickT = -1, cursorLabel = '', deckHover = null;
function overUI(target) { return target && target.closest && target.closest('a, button, input, select, textarea, label, [role="button"], .lightbox, .mnav, .loader'); }
function hitHat(x, y) {
  if (!H || !H.hat.visible) return false;
  ndc.set((x / innerWidth) * 2 - 1, -(y / innerHeight) * 2 + 1);
  raycaster.setFromCamera(ndc, camera);
  return raycaster.intersectObjects(H.meshes, false).length > 0;
}
function setCursor(label) {
  if (label === cursorLabel) return;
  cursorLabel = label; document.body.style.cursor = label ? 'pointer' : '';
  emit('topper:cursor', { label });
}
addEventListener('pointermove', e => {
  pointer.x = (e.clientX / innerWidth) * 2 - 1; pointer.y = (e.clientY / innerHeight) * 2 - 1;
  pointer.cx = e.clientX; pointer.cy = e.clientY; pointer.in = true;
  paint.pointer(e.clientX / innerWidth, 1 - e.clientY / innerHeight);
  if (!fine) return;
  const ui = overUI(e.target);
  const prev = deckHover ? deckHover.k : -1;
  deckHover = ui ? null : deck.pick(e.clientX, e.clientY);
  deck.hover(deckHover ? deckHover.k : -1);
  if (deckHover && deckHover.k !== prev) audio.play('tick');
  hover = !ui && !deckHover && hitHat(e.clientX, e.clientY) ? 1 : 0;
}, { passive: true });
addEventListener('pointerleave', () => { pointer.in = false; setCursor(''); });
addEventListener('click', e => {
  if (overUI(e.target)) return;
  const d = deck.pick(e.clientX, e.clientY);
  if (d) {
    if (d.k === deck.focus) emit('topper:open', { k: d.k });
    else emit('topper:goto', { k: d.k });
    return;
  }
  if (!hitHat(e.clientX, e.clientY)) return;
  if (trickT >= 0 && time - trickT < 1.2) return;
  trickT = time; swapPending = true;
  audio.play('trick');
  emit('topper:trick');
});

/* ── resize + adaptive quality ───────────────────────────── */
let typeDirty = true;
function resize() {
  if (!innerWidth || !innerHeight) return;                   // a hidden pane or tab reports 0×0: keep the last size
  small = Math.min(innerWidth, innerHeight) < 700;
  camera.aspect = innerWidth / innerHeight; camera.updateProjectionMatrix();
  renderer.setPixelRatio(pixelRatio());
  renderer.setSize(innerWidth, innerHeight, false);
  post.resize(innerWidth, innerHeight, renderer.getPixelRatio());
  paint.resize(innerWidth, innerHeight);
  D.dustMat.uniforms.uPR.value = SP.sparkMat.uniforms.uPR.value = renderer.getPixelRatio();
  MD.resize(renderer.getPixelRatio());
  V.mat.uniforms.uRes.value.set(innerWidth * renderer.getPixelRatio(), innerHeight * renderer.getPixelRatio());
  deck.layout(camera.aspect, FOV);
  measure();
  typeDirty = true;
}
let resizeT = 0;
addEventListener('resize', () => { resize(); clearTimeout(resizeT); resizeT = setTimeout(() => { typeDirty = true; }, 250); });
window.addEventListener('topper:layout', () => requestAnimationFrame(() => { measure(); typeDirty = true; }));
if (document.fonts) document.fonts.ready.then(() => { typeDirty = true; requestAnimationFrame(measure); });
let frames = 0, slowSum = 0, degraded = quality ? 2 : 0, bloomOn = quality !== 'low';
function watchQuality(dt) {
  if (degraded >= 2 || frames > 600 || dt > 0.1 || document.hidden) return;   // a hitch or a background tab is not a slow GPU
  frames++;
  if (frames < 40 || introT < 1) return;                    // let the page and the intro settle first
  slowSum += dt;
  if (frames % 90 === 0) {
    const avg = slowSum / 90; slowSum = 0;
    if (avg > 0.026) {                                      // under ~38 fps: lighten the load
      degraded++;
      if (degraded === 1) { bloomOn = false; dprCap = 1.25; V.mat.uniforms.uLayers.value = 2; }
      else { dprCap = 1; paint.enabled = false; D.dust.visible = !small; }
      resize();
    }
  }
}

/* ── springs and live state ──────────────────────────────── */
const tilt = new Spring(1.2, 0.9, 1, [0, 0]);                // cursor tilt (critically damped: no wobble)
const follow = new Spring(1.6, 1, 0, [0, 0, 0]);             // the hat trails its target a little, never overshooting
const pop = new Spring(2.4, 0.32, 2, 1);                     // the click "pop" (r > 1 anticipates)
const wobble = new Spring(1.1, 0.7, 0, 0);                   // scroll-speed lean
const deckP = new Spring(1.4, 0.85, 0, 0);
let smoothY = scrollY, lastY = scrollY, vel = 0, time = 0, lastT = performance.now() / 1000;
let introT = reduce ? 1 : -1, introStart = 0, followed = false, appearT = reduce ? 0 : -1, heroLift = 0;
window.addEventListener('topper:intro', () => { if (introT < 0) { introT = 0; introStart = time; } });
const hatPos = new THREE.Vector3(), hatQuat = new THREE.Quaternion(), bPos = new THREE.Vector3(), bQuat = new THREE.Quaternion();
const opening = new THREE.Vector3(), glint = new THREE.Vector2(), res = new THREE.Vector2(), light = new THREE.Vector2();
let lastDive = 0, lastExit = 0, ambLevel = -1;
const cue = (prev, now, at, name, g) => { if (prev < at && now >= at) audio.play(name, g); };

/* the entrance: the hat materialises out of a fine dither (alpha hash), so it is never see-through
   (a transparent hat shows its own lining and back faces). The night-sky lining waits until it is solid. */
let hatOpacity = 1;
function fadeHat(o) {
  if (o === hatOpacity) return;
  hatOpacity = o;
  for (const m of H.meshes) {
    if (m.userData.role === 'lining') { m.visible = o >= 0.999; continue; }
    m.material.opacity = o;
  }
}

/* ── frame ───────────────────────────────────────────────── */
let first = true, lastFrame = performance.now() / 1000, lastInk = -1;
function frame() {
  const now = performance.now() / 1000;
  const realDt = Math.min(0.25, now - lastT), dt = Math.min(0.05, realDt); lastT = now;
  if (!innerWidth || !innerHeight) { requestAnimationFrame(frame); return; }
  time += dt;
  watchQuality(now - lastFrame); lastFrame = now;
  if (typeDirty) { typeDirty = false; T.refresh(); buildForms(); }

  // scroll: damped read-back, and its speed as a signal
  smoothY = reduce ? scrollY : damp(smoothY, scrollY, 6, realDt);
  vel = damp(vel, (smoothY - lastY) / Math.max(dt, 1e-3), 8, dt); lastY = smoothY;
  const speed = clamp(Math.abs(vel) / 2500, 0, 1);
  const { A, B: Bs, t } = blendAt(smoothY);
  const mix = k => lerp(A[k], Bs[k], t);

  // the acts: the stage, the dive, the void, the way out, daylight
  const dive = prog('dive'), exit = prog('exit');
  const flashIn = fit(dive, 0.5, 0.62, 0, 1, ease.in) * (1 - fit(dive, 0.66, 0.95, 0, 1, ease.out));
  const preW = 1 - fit(dive, 0.6, 0.62);                     // daylight until the cut, hidden by the flash
  const dayW = Math.min(1, preW + fit(exit, 0.575, 0.585));  // and daylight again on the way out
  const voidW = 1 - dayW, stageW = 0;                        // (the dark spotlight stage of v3 is gone)
  const flashOut = fit(exit, 0.46, 0.57, 0, 1, ease.in) * (1 - fit(exit, 0.585, 0.66, 0, 1, ease.out));   // into the daylight colour, then the hat we came out of
  cue(lastDive, dive, 0.3, 'dive', 0.9); cue(lastExit, exit, 0.42, 'reveal', 0.8);
  lastDive = dive; lastExit = exit;
  const amb = preW * 0.25 + voidW * 0.85 + fit(exit, 0.58, 0.7) * 0.15;
  if (Math.abs(amb - ambLevel) > 0.02) { audio.setAmbient(amb); ambLevel = amb; }

  // the entrance: the first time the hat's section comes into view (after the door), the hat rises into place
  // on one clean ease — no springs, no flicker. Until then it is not drawn.
  if (introT >= 0 && introT < 1) introT = Math.min(1, (time - introStart) / 2.6);
  const iT = introT < 0 ? 0 : introT, iE = 1, lightOn = 1;
  heroLift = sec.hero ? Math.max(0, (sec.hero.top - scrollY) / innerHeight) : 0;
  if (appearT < 0 && introT >= 0 && heroLift < 0.55) appearT = time;   // when the spot the hat will stand on is well on screen
  const appear = reduce ? 1 : appearT < 0 ? 0 : ease.house(clamp((time - appearT) / 1.6, 0, 1));

  // camera: state blend + pointer parallax + Brownian drift; dolly zoom on the way in
  const ts = tilt.update(dt, reduce ? [0, 0] : [pointer.x, pointer.y]);
  camera.fov = FOV;
  const back = 0, calm = 1 - fit(dive, 0.2, 0.55) * preW;   // the drift settles as the camera lines up with the hat
  camera.position.set(lerp(A.cam[0], Bs.cam[0], t) + (ts[0] * 0.22 + brownian(time, 1) * 0.06) * calm, lerp(A.cam[1], Bs.cam[1], t) + (-ts[1] * 0.12 + brownian(time, 7) * 0.04) * calm, lerp(A.cam[2], Bs.cam[2], t) + back);
  tmp.v2.set(lerp(A.look[0], Bs.look[0], t), lerp(A.look[1], Bs.look[1], t), lerp(A.look[2], Bs.look[2], t));
  camera.lookAt(tmp.v2); camera.updateProjectionMatrix(); camera.updateMatrixWorld();

  let liveNow = 1;
  if (H) {
    // hat: blend the two states; the slot state is computed live so it stays locked to the logo
    const th = Bs.slot ? fit(t, 0, 0.82, 0, 1) : A.slot ? fit(t, 0.18, 1, 0, 1) : t;
    const dA = A.dist || DIST, dB = Bs.dist || DIST, d = Math.exp(lerp(Math.log(dA), Math.log(dB), th));   // the dive closes in at an even pace
    const sA = stateTransform(A, d, hatPos, hatQuat);
    const sB = stateTransform(Bs, d, bPos, bQuat);
    hatPos.lerp(bPos, th); hatQuat.slerp(bQuat, th);
    const scale = lerp(sA, sB, th);
    const hidden = (A.hide && th < 1) || (Bs.hide && th > 0) || voidW > 0.5;
    const live = 1 - ((A.slot ? 1 - th : 0) + (Bs.slot ? th : 0));   // 0 when locked in the logo
    liveNow = 1 - ((A.slot ? 1 - t : 0) + (Bs.slot ? t : 0));
    const idle = (reduce ? 0 : live) * calm;

    // springs: trail the target, tilt toward the cursor, wobble with scroll speed, pop on click
    if (!followed) { follow.reset([hatPos.x, hatPos.y, hatPos.z]); followed = true; }
    const f = follow.update(dt, [hatPos.x, hatPos.y, hatPos.z]);
    const pinW = (A.pin ? 1 - th : 0) + (Bs.pin ? th : 0);   // a hat pinned to a line moves exactly with it
    const fw = idle * (1 - clamp(heroLift * 4, 0, 1)) * (1 - pinW);   // while its section scrolls in, the hat moves exactly with the page
    hatPos.set(lerp(hatPos.x, f[0], fw), lerp(hatPos.y, f[1], fw), lerp(hatPos.z, f[2], fw));
    hoverT = damp(hoverT, hover, 6, dt);
    hatPos.y -= (1 - appear) * visH(DIST) * 0.1;              // the entrance: rises a little as it arrives
    hatPos.y += (Math.sin(time * 0.9) * 0.05 + hoverT * 0.08) * idle;
    const wob = wobble.update(dt, clamp(vel / 3000, -1, 1));
    let spin = 0, hop = 0;
    if (trickT >= 0) {
      const k = clamp((time - trickT) / 1.15, 0, 1), e = 1 - Math.pow(1 - k, 3);
      spin = e * Math.PI * 2 * (reduce ? 0 : 1); hop = Math.sin(k * Math.PI) * 0.35 * (reduce ? 0 : 1);
      if (k >= 1) trickT = -1;
    }
    if (swapPending && trickT >= 0 && time - trickT > (reduce ? 0 : 0.32)) { swapPending = false; LOOKS.next(); }   // at the top of the hop, mid-spin
    // studio: the hat juggles what it is made of, one look per step of the scroll (felt, clay, glass, gold,
    // jelly, chrome, felt again), turning as it goes — the opening film's idea, done by the hat itself
    const studioW = (A.name === 'studio' ? 1 - t : 0) + (Bs.name === 'studio' ? t : 0);
    const sp = fit(smoothY, range.studio[0], range.studio[1]);
    const look = sp > 0 && sp < 1 ? Math.floor(sp * 7) % 6 : -1;
    if (look !== studioLook) { studioLook = look; LOOKS.set(Math.max(0, look)); if (look >= 0 && !reduce) { popT = time; audio.play('trick', 0.5); } }
    // contact: it tips itself to you, once each time you arrive
    const contactW = (A.name === 'contact' ? 1 - t : 0) + (Bs.name === 'contact' ? t : 0);
    if (contactW > 0.9 && bowArmed) { bowArmed = false; bowT = time; } else if (contactW < 0.5) bowArmed = true;
    const bow = bowT < 0 || reduce ? 0 : Math.sin(clamp((time - bowT) / 1.5, 0, 1) * Math.PI) * 0.5 * contactW;
    const pp = pop.update(dt, (trickT >= 0 && time - trickT < 0.12) || (popT >= 0 && time - popT < 0.1) ? 1.16 : 1);
    hatPos.y += hop * live * calm;
    H.hat.position.copy(hatPos);
    H.hat.quaternion.copy(hatQuat);
    H.hat.rotateY((Math.sin(time * 0.35) * 0.3 + ts[0] * 0.35) * idle + spin * live * calm - (1 - appear) * 1.1 + sp * Math.PI * 2 * studioW);
    H.hat.rotateX((ts[1] * 0.12 + wob * 0.12) * idle + bow);
    H.hat.rotateZ((-hoverT * 0.06 - wob * 0.1) * idle);
    const sy = lerp(1, pp, live), sxz = 1 / Math.sqrt(sy);   // squash and stretch, volume kept
    const grow = lerp(0.86, 1, appear);                       // the entrance: grows into its size and fades in
    fadeHat(fit(appear, 0, 0.45));
    H.hat.scale.set(scale * sxz * grow, scale * sy * grow, scale * sxz * grow);
    H.hat.visible = !hidden && appear > 0;
    setCursor(deckHover ? (deckHover.k === deck.focus ? 'Play film' : 'Show') : hover && !hidden ? 'Click' : '');

    // light, colour, atmosphere
    bgColor.copy(C.stage).lerp(C.void, 1 - stageW).lerp(C.day, dayW);
    scene.fog.color.copy(bgColor);
    const sw = dayW;                                         // the hat is lit only in daylight
    const fu = F.floorMat.uniforms; fu.uBg.value.copy(bgColor); fu.uO.value = liveNow * stageW; fu.uHat.value.copy(hatPos); fu.uKey.value = mix('key') * lightOn; fu.uRim.value.set(mix('rimP'), mix('rimB'));
    F.floor.visible = false; F.shadow.visible = dayW > 0.01 && !hidden;
    // daylight: soft white light all round (the colours of the rims fade to white), a soft shadow on the paper below
    L.rimP.color.setRGB(1, 0.92 + 0.08 * dayW, 0.7 + 0.3 * dayW);   // warm-white and light-blue rims L.rimB.color.setRGB(0.51 + 0.49 * dayW * 0.7, 0.81 + 0.19 * dayW * 0.7, 0.89 + 0.11 * dayW * 0.7);
    L.hemi.intensity = 0.22 + dayW * 0.55; L.hemi.color.setRGB(1, 1, 1); L.hemi.groundColor.copy(C.day).multiplyScalar(dayW * 0.6);
    post.final.uniforms.uExp.value = mix('exposure');
    L.key.intensity = 210 * mix('key') * lightOn * sw; L.rimP.intensity = 4.2 * mix('rimP') * (0.3 + 0.7 * lightOn); L.rimB.intensity = 3.6 * mix('rimB') * (0.3 + 0.7 * lightOn);
    L.key.position.set(hatPos.x * 0.6 + 0.6, hatPos.y + 6.6, hatPos.z + 3.4); L.key.target.position.copy(hatPos);   // the same light wherever the hat is
    B.beam.position.x = hatPos.x * 0.85; B.beam.position.z = hatPos.z * 0.6;
    B.beamMat.uniforms.uI.value = mix('beam') * lightOn * stageW;
    D.dustMat.uniforms.uO.value = mix('dust') * iE * stageW; D.dustMat.uniforms.uT.value = time; D.dustMat.uniforms.uBeam.value.set(B.beam.position.x, 0);
    const magic = mix('magic') + hoverT * 0.4;
    if (H.lining && H.lining.uniforms) H.lining.uniforms.uT.value = time;
    H.glowMat.uniforms.uI.value = magic * 1.1 * lightOn; H.light.intensity = magic * 4 * lightOn;
    S_.shaftMat.uniforms.uI.value = mix('shaft') * (0.8 + 0.2 * Math.sin(time * 2.1)) * live * stageW;
    F.shadow.position.x = hatPos.x; F.shadow.position.z = hatPos.z;
    F.shadow.material.opacity = clamp(1.2 - (hatPos.y - FLOOR_Y - 1) / 3, 0, 1) * live * 0.35 * dayW * hatOpacity * (1 - studioW);   // the shadow arrives with the hat (none while it floats over the photo)
    H.felt.envMapIntensity = 0.16 * live + 0.02; H.brimFelt.envMapIntensity = H.felt.envMapIntensity;

    // sparks: a trickle while the hat is open, a vortex as it tips toward you, more on hover
    if (!reduce && magic > 0.25 && live > 0.5 && iT > 0.5 && stageW > 0.5) {
      const rate = Math.min(4, magic - 0.25) * 70 + hoverT * 30;
      const n = Math.floor(rate * dt + Math.random());
      if (n > 0) SP.spawn(H, n, 1.1);
    }
    SP.step(H, dt, time, mix('swirl'));
    SP.points.visible = stageW > 0.01;
    H.body.updateWorldMatrix(true, false);
    opening.copy(H.opening).applyMatrix4(H.body.matrixWorld);
    tmp.v1.copy(hatPos).project(camera); light.set(tmp.v1.x * 0.5 + 0.5, 0.75);
  }

  // the type: the hero headline burns away as the hat opens toward you
  res.set(innerWidth * renderer.getPixelRatio(), innerHeight * renderer.getPixelRatio());
  for (const it of heroType) { it.mat.uniforms.uBurn.value = reduce ? 0 : fit(dive, 0.02, 0.4); it.mat.uniforms.uO.value = (reduce ? 1 - fit(dive, 0.1, 0.4) : 1) * preW; }

  // the void: smoke, and the dust — adrift, bursting out of the hat's opening, or gathered into a heading
  const vh = innerHeight, fall = smoothY * visH(1) / vh;
  V.quad.visible = voidW > 0.002;
  const vu = V.mat.uniforms; vu.uO.value = voidW; vu.uT.value = time; vu.uFall.value = fall * 0.6; vu.uRed.value = (1 - fit(dive, 0.62, 0.8)) * (1 - stageW) * 0.8; vu.uOpen.value = fit(exit, 0.22, 0.56, 0, 1, ease.in) * 1.7;
  const mu = MD.mat.uniforms;
  mu.uTan.value = Math.tan(THREE.MathUtils.degToRad(camera.fov) / 2);   // the intro's dolly zoom changes the lens
  mu.uO.value = voidW; mu.uT.value = reduce ? 0 : time; mu.uFall.value = reduce ? 0 : fall; mu.uBurst.value = fit(dive, 0.6, 0.9); mu.uCalm.value = reduce ? 1 : 0;
  mu.uWarp.value = reduce ? 0 : ease.in(fit(exit, 0, 0.56)) * 34;   // the way out: the stars rush past toward the light
  MD.points.visible = voidW > 0.002;
  // while the deck is up, the drift keeps behind the cards (nothing adrift is drawn in front of them)
  const deckW = deckRange.on ? fit(smoothY, deckRange.top - vh * 0.7, deckRange.top - vh * 0.2) * (1 - fit(smoothY, deckRange.end + vh * 0.2, deckRange.end + vh * 0.7)) : 0;
  mu.uNear.value = 9.5 * deckW;
  let best = null, bw = 0;
  for (const fm of forms) {
    const r = fm.el.getBoundingClientRect();
    const w = fit(r.top, vh * 0.92, vh * 0.42, 0, 1) * (1 - fit(r.bottom, vh * 0.12, -vh * 0.02, 0, 1));   // readable until it has nearly left the screen
    fm.w = w; fm.r = r;
    if (w > bw) { bw = w; best = fm; }
  }
  const ringW = fit(exit, 0.04, 0.26);
  if (ringW > bw && ringTex) { mu.uForm.value = ringTex; mu.uMix.value = ringW; mu.uEl.value.set(0, 0); }
  else if (best) { mu.uForm.value = best.tex; mu.uMix.value = bw; mu.uEl.value.set(best.r.left, best.r.top); }
  else mu.uMix.value = 0;
  // the ring is the hat's opening seen from inside: it opens around you on the way out
  MD.points.scale.setScalar(1 + fit(exit, 0.24, 0.56, 0, 1, ease.in) * 6);
  for (const fm of forms) {
    const o = fit(fm.w, 0.7, 1);
    if (fm.img) fm.el.style.opacity = voidW > 0.5 ? o : 1;
    else { const it = dustType.get(fm.el); if (it) it.mat.uniforms.uO.value = o * voidW; }
  }
  T.update(time, res, light);
  if (PH) PH.update(time, dt);

  // the deck: scroll position along it, dealt out of the dust below the frame
  if (deckRange.on) {
    const n = deck.count, raw = fit(smoothY, deckRange.top, deckRange.end, 0, Math.max(0, n - 1));
    const fl = Math.floor(raw), magnet = fl + ease.inOut(clamp((raw - fl - 0.22) / 0.56, 0, 1));
    const pv = reduce ? Math.round(raw) : deckP.update(dt, magnet);
    const deal = fit(smoothY, deckRange.top - vh * 0.5, deckRange.top + vh * 0.04) * (1 - fit(smoothY, deckRange.end + vh * 0.05, deckRange.end + vh * 0.6));
    tmp.v1.set(0, -visH(6) * 0.62, -6); camera.localToWorld(tmp.v1);
    if (deckHover && pointer.in) glint.copy(deckHover.uv);
    deck.update(dt, time, {
      p: pv, deal: reduce ? (deal > 0.5 ? 1 : 0) : deal, vel: reduce ? 0 : vel / 60, hatOpening: tmp.v1,
      visible: smoothY > deckRange.top - vh * 1.1 && smoothY < deckRange.end + vh * 0.9,
      pointer: fine ? { x: pointer.x, y: pointer.y } : null, glint
    });
  }

  // post: bloom, the flashes, daylight, the grade
  post.bloom.strength = (0.55 + flashIn * 0.8) * liveNow * (1 - dayW); post.bloom.enabled = bloomOn && liveNow > 0.02 && dayW < 1;
  const ink = fit(liveNow, 0.16, 0.04, 0, 1);
  if (ink !== lastInk) { slotEl.style.opacity = ink; slotEl.classList.toggle('flat', ink > 0); lastInk = ink; }
  if (H && ink >= 0.98) H.hat.visible = false;
  const u = post.final.uniforms;
  u.uVig.value = mix('vig') * (1 - dayW); u.uT.value = time; u.uDay.value = dayW;
  u.uFlash.value = Math.max(flashIn, flashOut); u.uFlashC.value.copy(flashIn > flashOut ? C.flashIn : C.flashOut);
  u.uAmt.value = 0.045 * (1 - dayW * 0.6);
  u.uCA.value = (0.01 + speed * 0.022) * (1 - dayW);   // a touch of lens fringing, never enough to double the type
  paint.step(dt, time);
  post.render(dt);
  if (first && H) { first = false; canvas.classList.add('on'); }
  requestAnimationFrame(frame);
}

/* ── start: load, upload and compile behind the loader, then go ── */
async function start() {
  let h;
  try { h = await loadHat('assets/models/hat.glb', renderer, k => progress(0.2 + k * 0.45)); }
  catch (e) { console.warn('[topper] hat.glb not loaded, using the code hat', e); h = makeHat(); }
  useHat(h);
  progress(0.7);
  resize();
  // upload textures and compile every shader now, so the first scroll never hitches
  for (const tx of deck.textures()) if (tx && tx.image) renderer.initTexture(tx);
  H.hat.position.set(0, 0, -6); deck.group.visible = true; V.quad.visible = true; MD.points.visible = true;
  // (some drivers never report KHR_parallel_shader_compile completion, so never wait more than 2.5 s)
  try {
    const done = renderer.compileAsync ? renderer.compileAsync(scene, camera) : Promise.resolve(renderer.compile(scene, camera));
    await Promise.race([done, new Promise(r => setTimeout(r, 2500))]);
  } catch (e) { console.warn('[topper] compile', e); }
  deck.group.visible = false; V.quad.visible = false; MD.points.visible = false;
  progress(0.95);
  requestAnimationFrame(frame);
  requestAnimationFrame(() => { progress(1); emit('topper:stage', { ok: true, deck: deck.count > 0 }); });
  // compile the trick's looks while nobody is looking, so the first swap never stutters
  setTimeout(() => { try { for (let k = 1; k < LOOKS.count; k++) { LOOKS.next(); renderer.compile(scene, camera); } LOOKS.reset(); } catch (e) { LOOKS.reset(); } }, 1500);
}
if (!window.__topperNoStart) start();
