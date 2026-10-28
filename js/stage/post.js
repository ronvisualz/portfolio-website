/* topper. stage — post-processing.
   Alpha carries meaning through the chain: materials that are part of the "stage" (hat, room,
   sparks) write alpha 1; the project cards write alpha 0. Bloom only picks up alpha-1 pixels and
   only alpha-1 pixels are tone-mapped, so the studio's films keep their own colours.
   Final pass: tone map (ACES, as before), smoke smear, chromatic split, vignette, grain, dither. */
import * as THREE from '../../vendor/three/three.module.min.js';
import { EffectComposer } from '../../vendor/three/addons/postprocessing/EffectComposer.js';
import { RenderPass } from '../../vendor/three/addons/postprocessing/RenderPass.js';
import { UnrealBloomPass } from '../../vendor/three/addons/postprocessing/UnrealBloomPass.js';
import { ShaderPass } from '../../vendor/three/addons/postprocessing/ShaderPass.js';

const highPass = `uniform sampler2D tDiffuse; uniform vec3 defaultColor; uniform float defaultOpacity; uniform float luminosityThreshold; uniform float smoothWidth;
  varying vec2 vUv;
  void main(){ vec4 t = texture2D(tDiffuse, vUv); float v = dot(t.rgb, vec3(0.299, 0.587, 0.114));
    float a = smoothstep(luminosityThreshold, luminosityThreshold + smoothWidth, v) * clamp(t.a, 0.0, 1.0);
    gl_FragColor = vec4(t.rgb * a, 1.0); }`;

const finalFrag = `precision highp float;
uniform sampler2D tDiffuse; uniform sampler2D tPaint; uniform float uPaint;
uniform float uT; uniform float uExp; uniform float uAmt; uniform float uVig; uniform float uCA; uniform vec2 uRes; uniform float uFrame;
uniform vec3 uHaze; uniform float uDay; uniform float uFlash; uniform vec3 uFlashC;
varying vec2 vUv;
vec3 RRTAndODTFit(vec3 v){ vec3 a = v * (v + 0.0245786) - 0.000090537; vec3 b = v * (0.983729 * v + 0.4329510) + 0.238081; return a / b; }
vec3 aces(vec3 color){
  const mat3 ACESInputMat = mat3(vec3(0.59719, 0.07600, 0.02840), vec3(0.35458, 0.90834, 0.13383), vec3(0.04823, 0.01566, 0.83777));
  const mat3 ACESOutputMat = mat3(vec3(1.60475, -0.10208, -0.00327), vec3(-0.53108, 1.10813, -0.07276), vec3(-0.07367, -0.00605, 1.07602));
  color *= uExp / 0.6; color = ACESInputMat * color; color = RRTAndODTFit(color); color = ACESOutputMat * color; return clamp(color, 0.0, 1.0); }
vec3 toSRGB(vec3 c){ return mix(c * 12.92, 1.055 * pow(c, vec3(1.0 / 2.4)) - 0.055, step(0.0031308, c)); }
float ign(vec2 p){ return fract(52.9829189 * fract(dot(p, vec2(0.06711056, 0.00583715)))); }   // interleaved gradient noise
vec4 grab(vec2 uv){ vec4 s = texture2D(tDiffuse, uv); s.rgb = mix(clamp(s.rgb, 0.0, 1.0), aces(s.rgb), clamp(s.a, 0.0, 1.0) * (1.0 - uDay)); return s; }
void main(){
  vec2 uv = vUv;
  vec4 pnt = texture2D(tPaint, uv) * uPaint;
  vec2 vel = pnt.xy;
  // smear along the smoke's velocity (9 taps)
  vec3 c;
  if (dot(vel, vel) < 1e-9) c = grab(uv).rgb;
  else { c = vec3(0.0); float w = 0.0;
    for (int i = 0; i < 9; i++) { float k = float(i) / 8.0; float wk = 1.0 - k * 0.6;
      c += grab(uv - vel * (0.6 + 2.4 * k)).rgb * wk; w += wk; }
    c /= w; }
  // chromatic split: lens edge + scroll speed + a thin fringe along the smoke
  vec2 d = uv - 0.5; float r2 = dot(d, d);
  vec2 ca = d * uCA * r2 + vel * 0.9;
  c.r = mix(c.r, grab(uv - vel * 1.2 + ca).r, 0.85);
  c.b = mix(c.b, grab(uv - vel * 1.2 - ca).b, 0.85);
  // warm haze where the smoke is dense
  c += uHaze * pnt.z * 0.05;
  c *= mix(1.0, smoothstep(0.98, 0.22, length(d) * 1.28), uVig * 0.75);
  c = mix(c, uFlashC, uFlash);                       // the flashes: into the hat, and out into daylight
  c = toSRGB(clamp(c, 0.0, 1.0));
  float n = ign(gl_FragCoord.xy + uFrame * 5.588238) - 0.5;
  c += n * uAmt + (ign(gl_FragCoord.yx + uFrame * 3.17) - 0.5) / 255.0;
  gl_FragColor = vec4(c, 1.0);
}`;

export function makePost(renderer, scene, camera, paint, opt = {}) {
  const size = new THREE.Vector2(innerWidth, innerHeight);
  const rt = new THREE.WebGLRenderTarget(size.x, size.y, { type: THREE.HalfFloatType, samples: opt.msaa && renderer.capabilities.isWebGL2 ? 4 : 0 });
  const composer = new EffectComposer(renderer, rt);
  composer.addPass(new RenderPass(scene, camera));
  const bloom = new UnrealBloomPass(size, 0.55, 0.65, 0.82);
  bloom.materialHighPassFilter.fragmentShader = highPass;
  bloom.materialHighPassFilter.needsUpdate = true;
  // add the glow onto the image but leave the alpha (the stage/cards mask) untouched
  Object.assign(bloom.blendMaterial, { blending: THREE.CustomBlending, blendEquation: THREE.AddEquation, blendSrc: THREE.OneFactor, blendDst: THREE.OneFactor, blendSrcAlpha: THREE.ZeroFactor, blendDstAlpha: THREE.OneFactor });
  // bloom at reduced resolution: it is soft anyway, and full-res bloom was the single biggest cost on integrated GPUs
  const bloomSize = bloom.setSize.bind(bloom), k = opt.bloomScale || 0.75;
  bloom.setSize = (w, h) => bloomSize(Math.max(2, Math.round(w * k)), Math.max(2, Math.round(h * k)));
  composer.addPass(bloom);
  const final = new ShaderPass({
    uniforms: {
      tDiffuse: { value: null }, tPaint: { value: null }, uPaint: { value: 1 }, uT: { value: 0 }, uExp: { value: 1 }, uAmt: { value: 0.045 }, uVig: { value: 1 },
      uCA: { value: 0.012 }, uRes: { value: size.clone() }, uFrame: { value: 0 }, uHaze: { value: new THREE.Color(0xbfe6ff) },
      uDay: { value: 0 }, uFlash: { value: 0 }, uFlashC: { value: new THREE.Color(1, 1, 1) }
    },
    vertexShader: 'varying vec2 vUv; void main(){ vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
    fragmentShader: finalFrag
  });
  composer.addPass(final);
  let frame = 0;
  function resize(w, h, pr) {
    composer.setPixelRatio(pr); composer.setSize(w, h);
    final.uniforms.uRes.value.set(w * pr, h * pr);
  }
  function render(dt) {
    final.uniforms.tPaint.value = paint.texture;
    final.uniforms.uPaint.value = paint.enabled ? 1 : 0;
    final.uniforms.uFrame.value = (frame++) % 64;
    composer.render(dt);
  }
  return { composer, bloom, final, resize, render };
}
