/* topper. stage — the magician's photograph as a plate in the scene. The <img> keeps its place in the
   layout (and stays for screen readers); the stage draws it on a plane hung off the camera, locked to the
   image's box like the headings are. Being in the 3D lets the hat float in front of it, out of his hand.
   The plate is rounded like every box on the page, leans a little toward the cursor, and its picture
   drifts inside the frame as the page scrolls (a window, not a sticker). */
import * as THREE from '../../vendor/three/three.module.min.js';

const vert = `varying vec2 vUv; void main(){ vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }`;
const frag = `precision highp float;
  uniform sampler2D uMap; uniform vec2 uSize; uniform float uR; uniform float uO; uniform vec2 uShift; uniform float uZoom; uniform vec2 uTilt; uniform float uScale;
  varying vec2 vUv;
  float rbox(vec2 p, vec2 b, float r){ vec2 q = abs(p) - b + r; return length(max(q, 0.0)) + min(max(q.x, q.y), 0.0) - r; }
  void main(){
    vec2 px = (vUv - 0.5) * uSize;
    float d = rbox(px, uSize * 0.5, uR);
    vec2 uv = (vUv - 0.5) / uZoom + 0.5 + uShift;
    vec3 t = texture2D(uMap, uv).rgb;                                 // sampled before any discard, so the mip level is right
    float a = 1.0 - smoothstep(-1.0 * uScale, 0.6 * uScale, d);      // the rounded edge, antialiased in screen pixels
    if (a < 0.002) discard;
    float g = dot(t, vec3(0.2126, 0.7152, 0.0722));
    g = clamp((g - 0.5) * 1.06 + 0.5, 0.0, 1.0) * 0.93;              // the same grade the page gave the <img>
    vec3 c = vec3(g) * vec3(1.015, 1.0, 0.975);                      // a breath of the paper's warmth
    // a soft sheen that slides across the print as it leans
    float band = vUv.x * 0.8 + (1.0 - vUv.y) * 0.6 - (0.7 + uTilt.y * 2.2 - uTilt.x * 1.2);
    c += exp(-band * band * 14.0) * 0.045;
    c *= 1.0 - 0.18 * pow(length((vUv - 0.5) * vec2(1.1, 0.9)), 2.2);   // a little falloff toward the corners
    gl_FragColor = vec4(c, a * uO);
  }`;

export function makePhoto(camera, figure, d = 7.6) {
  const img = figure.querySelector('img');
  const mat = new THREE.ShaderMaterial({
    transparent: true, depthWrite: false,
    uniforms: { uMap: { value: null }, uSize: { value: new THREE.Vector2(1, 1) }, uR: { value: 24 }, uO: { value: 0 }, uShift: { value: new THREE.Vector2() }, uZoom: { value: 1.08 }, uTilt: { value: new THREE.Vector2() }, uScale: { value: 1 } },
    vertexShader: vert, fragmentShader: frag
  });
  const mesh = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), mat);
  mesh.renderOrder = 0; mesh.frustumCulled = false; mesh.visible = false;
  camera.add(mesh);

  let ready = false, seen = -1;
  const done = () => {
    // copied to a canvas first: the upload then has one fixed size, whatever the <img> is doing in the layout
    const c = document.createElement('canvas'); c.width = img.naturalWidth; c.height = img.naturalHeight;
    c.getContext('2d').drawImage(img, 0, 0);
    const tex = new THREE.CanvasTexture(c); tex.colorSpace = THREE.SRGBColorSpace; tex.anisotropy = 4;
    mat.uniforms.uMap.value = tex; ready = true;
    document.documentElement.classList.add('photo3d');
  };
  if (img.complete && img.naturalWidth) done(); else img.addEventListener('load', done, { once: true });

  // the plate leans toward the cursor while it is over (or near) the photo
  const ptr = { x: 0, y: 0, in: 0 }, tilt = new THREE.Vector2();
  addEventListener('pointermove', e => { ptr.x = e.clientX; ptr.y = e.clientY; ptr.in = 1; }, { passive: true });

  const visH = () => 2 * d * Math.tan(THREE.MathUtils.degToRad(camera.fov) / 2);
  function update(time, dt) {
    const r = figure.getBoundingClientRect();
    const on = ready && r.bottom > -40 && r.top < innerHeight + 40 && r.width > 0;
    mesh.visible = on; if (!on) return;
    if (seen < 0) seen = time;
    const u = mat.uniforms, k = visH() / innerHeight;
    u.uO.value = Math.min(1, (time - seen) / 0.6);
    u.uSize.value.set(r.width, r.height);
    u.uR.value = parseFloat(getComputedStyle(figure).borderTopLeftRadius) || 24;
    // lean: up to ~6° toward the cursor, measured from the photo's centre
    const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    const tx = ptr.in ? THREE.MathUtils.clamp((ptr.x - cx) / (r.width * 0.9), -1, 1) : 0;
    const ty = ptr.in ? THREE.MathUtils.clamp((ptr.y - cy) / (r.height * 0.9), -1, 1) : 0;
    const f = 1 - Math.exp(-dt * 4);
    tilt.x += (tx - tilt.x) * f; tilt.y += (ty - tilt.y) * f;
    u.uTilt.value.copy(tilt);
    mesh.position.set((cx - innerWidth / 2) * k, -(cy - innerHeight / 2) * k, -d);
    mesh.scale.set(r.width * k, r.height * k, 1);
    mesh.rotation.set(tilt.y * 0.09, tilt.x * 0.11, 0);
    // the picture drifts inside its frame as the page scrolls
    u.uShift.value.set(0, -((cy - innerHeight / 2) / innerHeight) * 0.035);
  }
  return { mesh, update, get ready() { return ready; } };
}
