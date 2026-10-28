/* topper. stage — sound. Web Audio, started by the visitor's choice at the door (browsers only allow
   sound after a click). Each cue plays a file from assets/audio/ when it exists, and a small
   synthesised stand-in when it does not, so the site always has its sound design.
   Cues: ambient (loop), dive, trick, card, tick, reveal. assets/audio/manifest.json maps cue → file,
   e.g. { "ambient": "ambient.mp3" }. The shortlist of licensed files is in brief/AUDIO.md. */


export function makeAudio(base = 'assets/audio/') {
  let ctx = null, master = null, amb = null, ambGain = null, on = false, level = 0.6;
  const buffers = {};

  async function loadAll() {
    let list = {};
    try { const r = await fetch(base + 'manifest.json'); if (r.ok) list = await r.json(); } catch (e) { /* no files yet: stand-ins */ }
    await Promise.all(Object.keys(list).map(async name => {
      try { const r = await fetch(base + list[name]); if (r.ok) buffers[name] = await ctx.decodeAudioData(await r.arrayBuffer()); }
      catch (e) { /* this cue keeps its stand-in */ }
    }));
  }
  function start() {
    if (ctx) { resume(); return; }
    const AC = window.AudioContext || window.webkitAudioContext; if (!AC) return;
    ctx = new AC();
    master = ctx.createGain(); master.gain.value = 0; master.connect(ctx.destination);
    ambGain = ctx.createGain(); ambGain.gain.value = 0; ambGain.connect(master);
    on = true; fade(master.gain, level, 1.2);
    loadAll().then(startAmbient);
  }
  function resume() { if (!ctx) return; ctx.resume(); on = true; fade(master.gain, level, 0.4); }
  function mute() { if (!ctx) return; on = false; fade(master.gain, 0, 0.3); }
  function fade(param, v, s) { const t = ctx.currentTime; param.cancelScheduledValues(t); param.setValueAtTime(param.value, t); param.linearRampToValueAtTime(v, t + s); }

  /* ── stand-ins ── */
  function noise(sec) {
    const b = ctx.createBuffer(1, Math.ceil(ctx.sampleRate * sec), ctx.sampleRate), d = b.getChannelData(0);
    for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
    return b;
  }
  function startAmbient() {
    if (amb) return;
    if (buffers.ambient) {
      const s = ctx.createBufferSource(); s.buffer = buffers.ambient; s.loop = true; s.connect(ambGain); s.start(); amb = s; return;
    }
    // a low drone of two detuned tones and filtered air, breathing slowly
    const lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 520; lp.Q.value = 0.7; lp.connect(ambGain);
    for (const [f, g] of [[55, 0.16], [82.6, 0.1], [110.4, 0.06], [164.8, 0.03]]) {
      const o = ctx.createOscillator(); o.type = 'sawtooth'; o.frequency.value = f; o.detune.value = (Math.random() - 0.5) * 14;
      const og = ctx.createGain(); og.gain.value = g; o.connect(og).connect(lp); o.start();
    }
    const air = ctx.createBufferSource(); air.buffer = noise(4); air.loop = true;
    const bp = ctx.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = 900; bp.Q.value = 0.6;
    const ag = ctx.createGain(); ag.gain.value = 0.05; air.connect(bp).connect(ag).connect(ambGain); air.start();
    const lfo = ctx.createOscillator(); lfo.frequency.value = 0.07; const lg = ctx.createGain(); lg.gain.value = 260; lfo.connect(lg).connect(lp.frequency); lfo.start();
    amb = air;
  }
  function play(name, gain = 1, rate = 1) {
    if (!ctx || !on) return;
    const out = ctx.createGain(); out.gain.value = gain; out.connect(master);
    if (buffers[name]) { const s = ctx.createBufferSource(); s.buffer = buffers[name]; s.playbackRate.value = rate; s.connect(out); s.start(); return; }
    const t = ctx.currentTime;
    if (name === 'dive' || name === 'reveal') {
      // a rising (dive: falling) filtered rush
      const s = ctx.createBufferSource(); s.buffer = noise(2.4);
      const f = ctx.createBiquadFilter(); f.type = 'bandpass'; f.Q.value = 1.4;
      const up = name === 'reveal';
      f.frequency.setValueAtTime(up ? 300 : 2400, t); f.frequency.exponentialRampToValueAtTime(up ? 4200 : 140, t + 2.2);
      const e = ctx.createGain(); e.gain.setValueAtTime(0.0001, t); e.gain.exponentialRampToValueAtTime(0.9, t + 0.5); e.gain.exponentialRampToValueAtTime(0.0001, t + 2.3);
      s.connect(f).connect(e).connect(out); s.start(t); s.stop(t + 2.4);
      if (up) chime(out, t + 0.2, [523.3, 659.3, 784, 1046.5, 1318.5], 0.08);
    } else if (name === 'trick') {
      chime(out, t, [1318.5, 1568, 1760, 2093, 2637, 3136], 0.045);
    } else if (name === 'card') {
      const s = ctx.createBufferSource(); s.buffer = noise(0.12);
      const f = ctx.createBiquadFilter(); f.type = 'highpass'; f.frequency.value = 1800;
      const e = ctx.createGain(); e.gain.setValueAtTime(0.5, t); e.gain.exponentialRampToValueAtTime(0.001, t + 0.09);
      s.connect(f).connect(e).connect(out); s.start(t);
    } else if (name === 'tick') {
      const o = ctx.createOscillator(); o.frequency.value = 1900;
      const e = ctx.createGain(); e.gain.setValueAtTime(0.06, t); e.gain.exponentialRampToValueAtTime(0.0001, t + 0.05);
      o.connect(e).connect(out); o.start(t); o.stop(t + 0.06);
    }
  }
  function chime(out, t0, notes, step) {
    notes.forEach((f, i) => {
      const t = t0 + i * step + Math.random() * 0.02;
      const o = ctx.createOscillator(); o.type = 'sine'; o.frequency.value = f * (1 + (Math.random() - 0.5) * 0.004);
      const e = ctx.createGain(); e.gain.setValueAtTime(0.0001, t); e.gain.exponentialRampToValueAtTime(0.12, t + 0.01); e.gain.exponentialRampToValueAtTime(0.0001, t + 1.2);
      o.connect(e).connect(out); o.start(t); o.stop(t + 1.3);
    });
  }
  /* the ambient bed follows the story: quiet on the stage, full in the void, gone in daylight */
  function setAmbient(v) { if (ambGain && ctx) ambGain.gain.setTargetAtTime(v, ctx.currentTime, 0.4); }

  return { start, resume, mute, play, setAmbient, get on() { return on; }, get started() { return !!ctx; } };
}
