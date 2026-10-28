/* topper. stage — the room around the hat: lights, floor, the spotlight beam, dust in the
   light, sparks out of the hat, and the shaft of light that pours from the opening. */
import * as THREE from '../../vendor/three/three.module.min.js';

export const FLOOR_Y = -1.35;

export function makeLights(scene) {
  const key = new THREE.SpotLight(0xfff1e2, 210, 30, 0.42, 0.85, 1.4);
  key.position.set(0.6, 7.5, 2.2);
  scene.add(key, key.target);
  const rimP = new THREE.DirectionalLight(0xffeab0, 4.2); rimP.position.set(-5, 2.5, -4); scene.add(rimP);
  const rimB = new THREE.DirectionalLight(0x81cfe3, 3.6); rimB.position.set(5, 1.8, -3.5); scene.add(rimB);
  const hemi = new THREE.HemisphereLight(0xa9b3c9, 0x08080a, 0.22); scene.add(hemi);
  return { key, rimP, rimB, hemi };
}

function radialTexture(inner, outer) {
  const c = document.createElement('canvas'); c.width = c.height = 128;
  const g = c.getContext('2d'), grd = g.createRadialGradient(64, 64, 0, 64, 64, 64);
  grd.addColorStop(0, inner); grd.addColorStop(1, outer);
  g.fillStyle = grd; g.fillRect(0, 0, 128, 128);
  const t = new THREE.CanvasTexture(c); t.colorSpace = THREE.SRGBColorSpace; return t;
}

/* the floor: a cheap unlit shader (a full-screen PBR plane cost ~5 fps on integrated GPUs) —
   the spotlight's pool under the hat, a pink and a blue spill from the rims, fog to the backdrop */
export function makeFloor(scene) {
  const floorMat = new THREE.ShaderMaterial({
    transparent: true,
    uniforms: { uBg: { value: new THREE.Color() }, uHat: { value: new THREE.Vector3() }, uKey: { value: 1 }, uRim: { value: new THREE.Vector2(1, 1) }, uO: { value: 1 },
      uPink: { value: new THREE.Color(0xfbae9c) }, uBlue: { value: new THREE.Color(0x81cfe3) }, uWarm: { value: new THREE.Color(0xfff1e2) } },
    vertexShader: 'varying vec3 vW; void main(){ vec4 w = modelMatrix * vec4(position, 1.0); vW = w.xyz; gl_Position = projectionMatrix * viewMatrix * w; }',
    fragmentShader: `uniform vec3 uBg; uniform vec3 uHat; uniform float uKey; uniform vec2 uRim; uniform float uO; uniform vec3 uPink; uniform vec3 uBlue; uniform vec3 uWarm; varying vec3 vW;
      void main(){
        float d = length(vW.xz - uHat.xz);
        float pool = exp(-d * d * 0.22) * uKey;
        vec3 c = uBg * 0.9 + uWarm * pool * 0.085;
        float near = smoothstep(16.0, 2.0, length(vW.xz - cameraPosition.xz));
        c += (uPink * smoothstep(4.0, -8.0, vW.x) * 0.055 * uRim.x + uBlue * smoothstep(-4.0, 8.0, vW.x) * 0.04 * uRim.y) * near;
        c = mix(c, uBg, smoothstep(9.0, 26.0, length(vW - cameraPosition)));
        gl_FragColor = vec4(c, uO); }`
  });
  const floor = new THREE.Mesh(new THREE.PlaneGeometry(90, 90), floorMat);
  floor.rotation.x = -Math.PI / 2; floor.position.y = FLOOR_Y; scene.add(floor);
  const shadow = new THREE.Mesh(new THREE.PlaneGeometry(3.4, 3.4), new THREE.MeshBasicMaterial({ map: radialTexture('rgba(0,0,0,0.75)', 'rgba(0,0,0,0)'), transparent: true, depthWrite: false }));
  shadow.rotation.x = -Math.PI / 2; shadow.position.y = FLOOR_Y + 0.003; scene.add(shadow);
  return { floor, floorMat, shadow };
}

/* volumetric cone: fresnel-faded, brighter near the source, dithered so it never bands */
const coneVert = `varying vec2 vUv; varying vec3 vN; varying vec3 vV;
  void main(){ vUv = uv; vec4 mv = modelViewMatrix * vec4(position, 1.0); vN = normalize(normalMatrix * normal); vV = normalize(-mv.xyz); gl_Position = projectionMatrix * mv; }`;
const coneFrag = `uniform float uI; uniform vec3 uC; uniform float uK; varying vec2 vUv; varying vec3 vN; varying vec3 vV;
  void main(){ float h = vUv.y; float f = pow(abs(dot(vN, vV)), 1.8);
    float a = uI * f * pow(h, 0.5) * (1.0 - smoothstep(0.9, 1.0, h)) * smoothstep(0.0, 0.3, h) * uK;
    a += (fract(sin(dot(gl_FragCoord.xy, vec2(12.9898, 78.233))) * 43758.5453) - 0.5) / 255.0;
    gl_FragColor = vec4(uC * max(a, 0.0), max(a, 0.0)); }`;
export function makeBeam(scene) {
  const beamMat = new THREE.ShaderMaterial({
    transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, side: THREE.DoubleSide,
    uniforms: { uI: { value: 1 }, uC: { value: new THREE.Color(0xfff0df) }, uK: { value: 0.26 } },
    vertexShader: coneVert, fragmentShader: coneFrag
  });
  const beam = new THREE.Mesh(new THREE.ConeGeometry(2.6, 9, 72, 1, true), beamMat);
  beam.position.set(0, FLOOR_Y + 4.5, 0); scene.add(beam);
  return { beam, beamMat };
}

/* the shaft of light out of the hat's opening (child of the hat body, pointing down its -Y) */
export function makeShaft(body) {
  const mat = new THREE.ShaderMaterial({
    transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, side: THREE.DoubleSide,
    uniforms: { uI: { value: 0 }, uC: { value: new THREE.Color(0xffc9b0) }, uK: { value: 0.5 } },
    vertexShader: coneVert,
    fragmentShader: coneFrag.replace('pow(h, 0.5) * (1.0 - smoothstep(0.9, 1.0, h)) * smoothstep(0.0, 0.3, h)', 'pow(h, 2.2) * smoothstep(0.0, 0.6, h)')
  });
  // as wide as the opening where it starts, flaring out away from the hat (v = 1 at the opening)
  const geo = new THREE.CylinderGeometry(0.5, 1.5, 3.4, 48, 1, true);
  geo.translate(0, -1.7, 0);
  const shaft = new THREE.Mesh(geo, mat);
  shaft.position.y = 0.04; shaft.renderOrder = 3;
  body.add(shaft);
  return { shaft, shaftMat: mat };
}

/* dust drifting through the beam */
export function makeDust(scene, count, pr) {
  const geo = new THREE.BufferGeometry();
  const pos = new Float32Array(count * 3), seed = new Float32Array(count);
  for (let i = 0; i < count; i++) {
    const r = Math.sqrt(Math.random()) * 3.2, a = Math.random() * Math.PI * 2;
    pos[i * 3] = Math.cos(a) * r; pos[i * 3 + 1] = FLOOR_Y + Math.random() * 7; pos[i * 3 + 2] = Math.sin(a) * r;
    seed[i] = Math.random();
  }
  geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  geo.setAttribute('seed', new THREE.BufferAttribute(seed, 1));
  const mat = new THREE.ShaderMaterial({
    transparent: true, depthWrite: false, blending: THREE.AdditiveBlending,
    uniforms: { uT: { value: 0 }, uO: { value: 1 }, uPR: { value: pr }, uBeam: { value: new THREE.Vector2() }, uFloor: { value: FLOOR_Y } },
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
  const dust = new THREE.Points(geo, mat); dust.frustumCulled = false; scene.add(dust);
  return { dust, dustMat: mat };
}

/* sparks: a CPU particle pool. They leave the opening, rise on warm air and swirl about
   the hat's axis (a vortex), with a little sine-sum turbulence. */
const PALETTE = [new THREE.Color(0xffe9c7), new THREE.Color(0xfbae9c), new THREE.Color(0x81cfe3), new THREE.Color(0xffffff)];
export function makeSparks(scene, count, pr) {
  const sp = { pos: new Float32Array(count * 3), vel: new Float32Array(count * 3), life: new Float32Array(count), max: new Float32Array(count), col: new Float32Array(count * 3), a: new Float32Array(count), next: 0, n: count };
  const geo = new THREE.BufferGeometry();
  geo.setAttribute('position', new THREE.BufferAttribute(sp.pos, 3).setUsage(THREE.DynamicDrawUsage));
  geo.setAttribute('color', new THREE.BufferAttribute(sp.col, 3).setUsage(THREE.DynamicDrawUsage));
  geo.setAttribute('alpha', new THREE.BufferAttribute(sp.a, 1).setUsage(THREE.DynamicDrawUsage));
  const mat = new THREE.ShaderMaterial({
    transparent: true, depthWrite: false, blending: THREE.AdditiveBlending, vertexColors: true,
    uniforms: { uPR: { value: pr } },
    vertexShader: `attribute float alpha; uniform float uPR; varying float vA; varying vec3 vC;
      void main(){ vA = alpha; vC = color; vec4 mv = modelViewMatrix * vec4(position, 1.0); gl_PointSize = (2.0 + alpha * 3.5) * uPR * (6.0 / -mv.z); gl_Position = projectionMatrix * mv; }`,
    fragmentShader: `varying float vA; varying vec3 vC;
      void main(){ float d = length(gl_PointCoord - 0.5); float a = smoothstep(0.5, 0.0, d) * vA; gl_FragColor = vec4(vC * a * 1.6, a); }`
  });
  const points = new THREE.Points(geo, mat); points.frustumCulled = false; scene.add(points);
  const _o = new THREE.Vector3(), _n = new THREE.Vector3(), _t = new THREE.Vector3();
  const axis = new THREE.Vector3(), center = new THREE.Vector3();

  function spawn(H, n, speed) {
    H.body.updateWorldMatrix(true, false);
    _o.copy(H.opening).applyMatrix4(H.body.matrixWorld);
    _n.set(0, -1, 0).transformDirection(H.body.matrixWorld);   // the opening faces the hat's -Y
    for (let k = 0; k < n; k++) {
      const i = sp.next; sp.next = (sp.next + 1) % count;
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

  // swirl: strength of the vortex about the hat's axis (0 = sparks just rise)
  function step(H, dt, time, swirl) {
    H.body.updateWorldMatrix(true, false);
    center.copy(H.opening).applyMatrix4(H.body.matrixWorld);
    axis.set(0, -1, 0).transformDirection(H.body.matrixWorld);
    for (let i = 0; i < count; i++) {
      if (sp.life[i] <= 0) { sp.a[i] = 0; continue; }
      sp.life[i] -= dt;
      const j = i * 3;
      let x = sp.pos[j], y = sp.pos[j + 1], z = sp.pos[j + 2];
      if (swirl > 0) {
        // tangential push = axis × (p - c), weaker further out
        const dx = x - center.x, dy = y - center.y, dz = z - center.z;
        const tx = axis.y * dz - axis.z * dy, ty = axis.z * dx - axis.x * dz, tz = axis.x * dy - axis.y * dx;
        const d2 = dx * dx + dy * dy + dz * dz + 0.2;
        const k = swirl * dt * 2.2 / d2;
        sp.vel[j] += tx * k; sp.vel[j + 1] += ty * k; sp.vel[j + 2] += tz * k;
      }
      // sine-sum turbulence (cheap curl stand-in)
      const tt = time * 0.8 + i * 0.37;
      sp.vel[j] += Math.sin(y * 2.1 + tt) * 0.35 * dt; sp.vel[j + 2] += Math.cos(x * 1.9 - tt) * 0.35 * dt;
      sp.vel[j + 1] += 0.18 * dt;                    // a little lift, like warm air
      sp.vel[j] *= 0.985; sp.vel[j + 2] *= 0.985;
      sp.pos[j] = x + sp.vel[j] * dt; sp.pos[j + 1] = y + sp.vel[j + 1] * dt; sp.pos[j + 2] = z + sp.vel[j + 2] * dt;
      const k2 = sp.life[i] / sp.max[i];
      sp.a[i] = Math.max(0, Math.sin(k2 * Math.PI)) * (0.6 + 0.4 * Math.sin(sp.life[i] * 18 + i));
    }
    geo.attributes.position.needsUpdate = true; geo.attributes.alpha.needsUpdate = true; geo.attributes.color.needsUpdate = true;
  }
  return { points, sparkMat: mat, spawn, step };
}
