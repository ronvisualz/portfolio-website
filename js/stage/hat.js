/* topper. stage — the hat. loadHat() reads the hand-made GLB from Blender; if that fails,
   makeHat() builds the code sketch from the same profiles, so the page never goes empty.
   Both return the same shape: { hat, body, light, glowMat, felt, brimFelt, meshes, width, height, bottom, opening }. */
import * as THREE from '../../vendor/three/three.module.min.js';
import { GLTFLoader } from '../../vendor/three/addons/loaders/GLTFLoader.js';
import { MeshoptDecoder } from '../../vendor/three/addons/libs/meshopt_decoder.module.js';

/* the site's materials (3D-PLAN palette) */
function materials() {
  const felt = new THREE.MeshPhysicalMaterial({ color: 0x131318, roughness: 0.86, metalness: 0, sheen: 0.75, sheenRoughness: 0.42, sheenColor: new THREE.Color(0x50566a), envMapIntensity: 0.16 });
  const brimFelt = new THREE.MeshPhysicalMaterial({ color: 0x0a0a0d, roughness: 0.9, metalness: 0, sheen: 0.3, sheenRoughness: 0.45, sheenColor: new THREE.Color(0x3d4150), envMapIntensity: 0.12 });
  const satin = new THREE.MeshPhysicalMaterial({ color: 0x0c0c0f, roughness: 0.38, metalness: 0, clearcoat: 0.35, clearcoatRoughness: 0.32, sheen: 0.22, sheenRoughness: 0.3, sheenColor: new THREE.Color(0xfbae9c), envMapIntensity: 0.4 });
  const lining = nightLining();
  return { felt, brimFelt, satin, lining };
}

/* the lining: the inside of the hat is already the night sky you fall into — deep blue, a little lighter
   toward the rim, with stars in the brand's light blue and white that twinkle */
function nightLining() {
  return new THREE.ShaderMaterial({
    side: THREE.DoubleSide,
    uniforms: { uT: { value: 0 } },
    vertexShader: `varying vec3 vPos; varying vec3 vN; varying vec3 vV;
      void main(){ vPos = position; vec4 mv = modelViewMatrix * vec4(position, 1.0); vV = -mv.xyz; vN = normalMatrix * normal; gl_Position = projectionMatrix * mv; }`,
    fragmentShader: `precision highp float; uniform float uT; varying vec3 vPos; varying vec3 vN; varying vec3 vV;
      float h31(vec3 p){ return fract(sin(dot(p, vec3(127.1, 311.7, 74.7))) * 43758.5453); }
      vec3 stars(vec3 p, float k, float th){
        vec3 c = floor(p * k), f = fract(p * k) - 0.5; float h = h31(c);
        if (h < th) return vec3(0.0);
        vec3 o = vec3(h31(c + 1.3), h31(c + 2.7), h31(c + 4.1)) - 0.5;
        float s = smoothstep(0.32, 0.0, length(f - o * 0.5)) * (0.55 + 0.45 * sin(uT * (1.5 + h * 3.0) + h * 60.0));
        vec3 col = h > th + (1.0 - th) * 0.66 ? vec3(0.85, 0.93, 1.0) : h > th + (1.0 - th) * 0.33 ? vec3(0.51, 0.81, 0.89) : vec3(1.0);
        return col * s;
      }
      void main(){
        float fr = pow(1.0 - abs(dot(normalize(vN), normalize(vV))), 2.0);
        vec3 c = mix(vec3(0.004, 0.009, 0.03), vec3(0.02, 0.06, 0.16), fr);
        c += stars(vPos, 55.0, 0.965) * 2.2 + stars(vPos + 3.1, 130.0, 0.975) * 1.2;
        gl_FragColor = vec4(c, 1.0);
      }`
  });
}

/* the light that lives inside the hat: an additive disc + a cool point light */
function addGlow(body, y) {
  const glowMat = new THREE.ShaderMaterial({
    transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
    uniforms: { uI: { value: 0 }, uC: { value: new THREE.Color(0xd9f1ff) } },
    vertexShader: 'varying vec2 vUv; void main(){ vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
    fragmentShader: 'uniform float uI; uniform vec3 uC; varying vec2 vUv; void main(){ float d = length(vUv - 0.5) * 2.0; float a = pow(max(0.0, 1.0 - d), 2.2) * uI; gl_FragColor = vec4(uC * a, a); }'
  });
  const glow = new THREE.Mesh(new THREE.CircleGeometry(0.52, 48), glowMat);
  glow.rotation.x = Math.PI / 2; glow.position.y = y; glow.renderOrder = 2;
  body.add(glow);
  const light = new THREE.PointLight(0xbfe6ff, 0, 3.2, 1.6);
  light.position.set(0, y + 0.2, 0);
  body.add(light);
  return { glowMat, light };
}

/* centre the body on its bounding box so the hat turns about its middle */
function finish(body, M, meshes, extra) {
  const box = new THREE.Box3().setFromObject(body);
  const cy = (box.min.y + box.max.y) / 2;
  body.position.y = -cy;
  const g = addGlow(body, 0.06);
  const hat = new THREE.Group();
  hat.add(body);
  return Object.assign({
    hat, body, light: g.light, glowMat: g.glowMat, lining: M.lining, felt: M.felt, brimFelt: M.brimFelt, satin: M.satin, meshes,
    width: box.max.x - box.min.x, height: box.max.y - box.min.y, bottom: box.min.y - cy,
    opening: new THREE.Vector3(0, 0.02, 0)               // in body space; the opening faces body -Y
  }, extra);
}

/* ── the hand-made GLB ── */
export function loadHat(url, renderer, onProgress) {
  const loader = new GLTFLoader();
  loader.setMeshoptDecoder(MeshoptDecoder);
  return new Promise((resolve, reject) => {
    loader.load(url, gltf => {
      const M = materials();
      const body = new THREE.Group();
      const root = gltf.scene.getObjectByName('Hat') || gltf.scene;
      const meshes = [];
      const aniso = renderer.capabilities.getMaxAnisotropy();
      root.traverse(o => {
        if (!o.isMesh) return;
        const part = (o.name + ' ' + (o.parent ? o.parent.name : '')).toLowerCase();
        const src = o.material;
        let m = part.includes('lining') ? M.lining : part.includes('brim') && !part.includes('binding') ? M.brimFelt
          : part.includes('crown') ? M.felt : M.satin;
        m = m.clone();
        if (part.includes('lining')) { o.material = m; M.lining = m; o.userData.role = 'lining'; meshes.push(o); return; }   // the night sky needs no baked maps
        // keep the baked detail from Blender: normal map, occlusion, roughness
        for (const k of ['normalMap', 'aoMap', 'roughnessMap']) if (src[k]) { m[k] = src[k]; m[k].anisotropy = aniso; }
        if (src.normalMap) { m.normalScale.copy(src.normalScale || new THREE.Vector2(1, 1)).multiplyScalar(part.includes('crown') ? 0.75 : 1); }
        if (src.aoMap) m.aoMapIntensity = 1;
        if (src.roughnessMap) m.roughness = Math.min(1, m.roughness * 1.1);
        o.material = m;
        if (part.includes('crown')) M.felt = m;
        else if (part.includes('brim') && !part.includes('binding')) M.brimFelt = m;
        else if (part.includes('band')) M.satin = m;
        o.userData.role = part.includes('lining') ? 'lining' : (part.includes('crown') || (part.includes('brim') && !part.includes('binding'))) ? 'shell' : 'trim';
        meshes.push(o);
      });
      body.add(root);
      root.position.set(0, 0, 0);
      resolve(finish(body, M, meshes, { gltf: true }));
    }, e => { if (onProgress && e.total) onProgress(e.loaded / e.total); }, reject);
  });
}

/* ── the code sketch (fallback) ── */
const V = (x, y) => new THREE.Vector2(x, y);
const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
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
export function makeHat() {
  const SEG = 128, M = materials();
  const crown = new THREE.Mesh(new THREE.LatheGeometry([V(0.555, 0), V(0.556, 0.2), V(0.562, 0.5), V(0.572, 0.8), V(0.586, 1.08), V(0.598, 1.26), V(0.6, 1.29), V(0.596, 1.31), V(0.585, 1.322), V(0.5, 1.328), V(0.25, 1.331), V(0, 1.332)], SEG), M.felt);
  const inside = new THREE.Mesh(new THREE.LatheGeometry([V(0, 1.29), V(0.3, 1.288), V(0.53, 1.28), V(0.545, 1.25), V(0.54, 0.6), V(0.535, 0.02), V(0.53, -0.005)], SEG), M.lining);
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
  const brim = new THREE.Mesh(brimGeo, M.brimFelt);
  const band = new THREE.Mesh(new THREE.LatheGeometry([V(0.566, 0.03), V(0.572, 0.03), V(0.575, 0.05), V(0.579, 0.24), V(0.576, 0.262), V(0.569, 0.265)], SEG), M.satin);
  const body = new THREE.Group();
  body.add(crown, inside, brim, band);
  crown.userData.role = brim.userData.role = 'shell'; band.userData.role = 'trim'; inside.userData.role = 'lining';
  return finish(body, M, [crown, brim, band, inside], { gltf: false });
}

/* ── the trick: the hat changes what it is made of, like the wordmark in the opening film.
   Each look dresses the shell (crown and brim) and the trim (band, bow, binding); the lining stays the night sky. ── */
const LOOKS = [
  null,                                                                                           // felt: the hat as it is
  { shell: { color: 0x81cfe3, roughness: 0.82, sheen: 0.3, sheenColor: 0xe6f7fc }, trim: { color: 0x1b2a44, roughness: 0.7 } },                       // clay
  { shell: { color: 0xbfe8f2, roughness: 0.04, transmission: 1, thickness: 0.9, ior: 1.45, attenuationColor: 0x5fb8d6, attenuationDistance: 1.4 }, trim: { color: 0x101012, roughness: 0.25, clearcoat: 1 } },   // glass
  { shell: { color: 0xd9a441, metalness: 1, roughness: 0.28 }, trim: { color: 0x101012, roughness: 0.5 } },                                          // gold
  { shell: { color: 0x2f77b1, roughness: 0.18, transmission: 0.65, thickness: 1.2, ior: 1.35, attenuationColor: 0x0d2f57, attenuationDistance: 0.9, clearcoat: 1 }, trim: { color: 0xffffff, roughness: 0.3 } },   // jelly
  { shell: { color: 0xffffff, metalness: 1, roughness: 0.06 }, trim: { color: 0x101012, roughness: 0.35, clearcoat: 1 } }                          // chrome
];
export function makeLooks(H) {
  const base = new Map(H.meshes.map(m => [m, m.material]));
  const built = LOOKS.map(look => {
    if (!look) return null;
    const mk = role => {
      const o = Object.assign({}, look[role]);
      for (const k of ['color', 'sheenColor', 'attenuationColor']) if (o[k] != null) o[k] = new THREE.Color(o[k]);
      return new THREE.MeshPhysicalMaterial(Object.assign({ envMapIntensity: 0.7 }, o));
    };
    return { shell: mk('shell'), trim: mk('trim') };
  });
  let i = 0;
  function apply(k) {
    i = ((k % LOOKS.length) + LOOKS.length) % LOOKS.length;
    for (const m of H.meshes) {
      const b = base.get(m), role = m.userData.role;
      if (!built[i] || role === 'lining') { m.material = b; continue; }
      const mat = built[i][role];
      if (b.aoMap && !mat.aoMap) { mat.aoMap = b.aoMap; mat.aoMapIntensity = 0.8; mat.needsUpdate = true; }   // keep the baked contact shading
      m.material = mat;
    }
  }
  return { next: () => apply(i + 1), reset: () => apply(0), set: k => { if (k !== i) apply(k); }, get index() { return i; }, count: LOOKS.length, materials: built.filter(Boolean).flatMap(b => [b.shell, b.trim]) };
}
