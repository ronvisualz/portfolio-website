/* topper. stage — the void inside the hat: one full-screen backdrop, drawn behind everything.
   Three layers of slow smoke at different depths drift past (and rise with the scroll), each lit
   in one of the brand's colours, so the dark has shape and the bloom has something to hold. */
import * as THREE from '../../vendor/three/three.module.min.js';

const frag = `precision highp float;
  uniform float uT; uniform float uO; uniform float uFall; uniform vec2 uRes; uniform vec3 uBase; uniform float uRed; uniform int uLayers; uniform float uOpen;
  uniform vec3 uC1; uniform vec3 uC2; uniform vec3 uC3; uniform vec3 uC4;
  varying vec2 vUv;
  float h21(vec2 p){ return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }
  float vn(vec2 p){ vec2 i = floor(p), f = fract(p); f = f * f * (3.0 - 2.0 * f);
    return mix(mix(h21(i), h21(i + vec2(1, 0)), f.x), mix(h21(i + vec2(0, 1)), h21(i + vec2(1, 1)), f.x), f.y); }
  float fbm(vec2 p){ float v = 0.0, a = 0.5; for (int i = 0; i < 4; i++){ v += vn(p) * a; p = p * 2.03 + 17.1; a *= 0.5; } return v; }
  float smoke(vec2 uv, float k, float s){
    vec2 q = uv * k + vec2(uT * 0.02 * s, -uFall * 0.35 * s - uT * 0.015);
    vec2 w = vec2(fbm(q + uT * 0.03), fbm(q + 5.2 - uT * 0.025));            // domain warp: smoke, not clouds
    float n = fbm(q + w * 1.6);
    return smoothstep(0.45, 0.95, n);
  }
  void main(){
    vec2 uv = (gl_FragCoord.xy - 0.5 * uRes) / uRes.y;
    vec3 c = uBase;
    // each layer drifts between two of the brand's colours, so the void is never one tint
    float hue = vn(uv * 0.7 + vec2(uT * 0.01, -uFall * 0.1));
    float s1 = smoke(uv, 1.1, 0.6);
    c += mix(uC1 * 1.6, uC3 * 0.7, hue) * s1 * 0.3;
    if (uLayers > 1) { float s2 = smoke(uv + 3.7, 1.9, 1.0); c += mix(uC2, uC1 * 1.4, 1.0 - hue) * s2 * 0.16; }
    if (uLayers > 2) { float s3 = smoke(uv - 8.1, 3.2, 1.6); c += mix(uC3, uC4, hue) * s3 * 0.1; }
    // a faint glow at the far end, where the light came in
    c += vec3(0.5, 0.75, 0.9) * 0.05 * smoothstep(0.9, 0.0, length(uv));
    c = mix(c, vec3(0.02, 0.05, 0.14), uRed);                                   // the lining's night blue, just after the dive
    // the way out: daylight floods in through the hat's opening, an ellipse that opens from the middle
    float e = length(uv * vec2(0.62, 1.25));
    c = mix(c, vec3(3.4, 3.1, 2.9), smoothstep(uOpen + 0.06, uOpen - 0.04, e) * step(0.001, uOpen));   // warm daylight
    gl_FragColor = vec4(c * uO, 1.0);
  }`;

export function makeVoid(scene, layers) {
  const mat = new THREE.ShaderMaterial({
    depthTest: false, depthWrite: false,
    uniforms: {
      uT: { value: 0 }, uO: { value: 0 }, uFall: { value: 0 }, uRes: { value: new THREE.Vector2(1, 1) }, uBase: { value: new THREE.Color(0x050507) }, uRed: { value: 0 }, uLayers: { value: layers }, uOpen: { value: 0 },
      uC1: { value: new THREE.Color(0x2f77b1) }, uC2: { value: new THREE.Color(0x81cfe3) }, uC3: { value: new THREE.Color(0x81cfe3) }, uC4: { value: new THREE.Color(0xdff3f8) }
    },
    vertexShader: 'varying vec2 vUv; void main(){ vUv = uv; gl_Position = vec4(position.xy, 0.9999, 1.0); }',
    fragmentShader: frag
  });
  const quad = new THREE.Mesh(new THREE.PlaneGeometry(2, 2), mat);
  quad.frustumCulled = false; quad.renderOrder = -10; quad.visible = false;
  scene.add(quad);
  return { quad, mat };
}
