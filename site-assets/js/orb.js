/* ═══════════ Spread AI — علامة الأورب السائل ═══════════
   نقل مباشر لدالة glsSiriFluid + قشرة الزجاج من الشيدر الأصلي (style = 9)
   إلى WebGL — عشان يشتغل على كل المتصفحات، مش WebGPU اللي مش مدعوم
   على سفاري الموبايل ولا فايرفوكس.

   الاستخدام:
     SpreadOrb.mount(element)
     SpreadOrb.setState('thinking' | 'idle')
     SpreadOrb.unmount()
*/
(function () {
  'use strict';

  /* ── البذور: منقولة حرفيًا من stateSeeds بتاع الملف الأصلي ── */
  const SEED = {
    idle: {
      speed: 0.246, radius: 0.72, zoom: 0.3384, warp: 1.664, ridgeAmt: 0.24,
      shade: 0.12, sheen: 0.28, gloss: 0.24,
      shellMidAlpha: 0.18, shellEdgeAlpha: 0.18, exposure: 1.36,
      glassOpacity: 0.44,
      colorA: [0.7098039, 0.6509804, 0.4549020],
      colorB: [0.3686275, 0.5294118, 0.5803922],
      colorC: [0.6039216, 0.3921569, 0.5411765],
      colorD: [0.3882353, 0.3568628, 0.5411765],
      highlight: [0.7137255, 0.7686275, 0.8235294],
      shellInner: [1, 1, 1],
      shellMid: [0.6078431, 0.9568627, 1],
      shellEdge: [0.7725490, 0.6627451, 1],
      sheenColor: [0.9176471, 0.9568627, 1],
      specColor: [0.8627451, 0.9176471, 1],
      canvasColor: [0.0117647, 0.0156863, 0.0352941],
      glowColor: [0.4235294, 0.4078431, 0.5607843],
    },
    thinking: {
      speed: 0.82, radius: 0.72, zoom: 0.36, warp: 3.2, ridgeAmt: 0.5,
      shade: 0.12, sheen: 0.28, gloss: 0.24,
      shellMidAlpha: 0.18, shellEdgeAlpha: 0.18, exposure: 2.0,
      glassOpacity: 0.44,
      colorA: [1, 0.8470588, 0.4196078],
      colorB: [0.5098039, 0.9568627, 1],
      colorC: [1, 0.4823529, 0.8352941],
      colorD: [1, 0.5568628, 0.4235294],
      highlight: [1, 1, 1],
      shellInner: [1, 1, 1],
      shellMid: [0.6078431, 0.9568627, 1],
      shellEdge: [0.7725490, 0.6627451, 1],
      sheenColor: [0.9176471, 0.9568627, 1],
      specColor: [0.8627451, 0.9176471, 1],
      canvasColor: [0.0117647, 0.0156863, 0.0352941],
      glowColor: [0.5843138, 0.4235294, 1],
    },
  };

  const SCALARS = ['speed', 'radius', 'zoom', 'warp', 'ridgeAmt', 'shade', 'sheen',
    'gloss', 'shellMidAlpha', 'shellEdgeAlpha', 'exposure', 'glassOpacity'];
  const COLORS = ['colorA', 'colorB', 'colorC', 'colorD', 'highlight', 'shellInner',
    'shellMid', 'shellEdge', 'sheenColor', 'specColor', 'canvasColor', 'glowColor'];

  const ACTIVATION_MS = 220;
  const SETTLE_MS = 650;

  const reduceMotion = window.matchMedia
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ── الشيدر: نقل حرفي من glsSiriFluid + الغلاف الزجاجي ── */
  const VS = `
attribute vec2 aPos;
void main() { gl_Position = vec4(aPos, 0.0, 1.0); }`;

  const FS = `
precision highp float;

uniform vec2  uSize;
uniform float uTime;
uniform float uSpeed, uRadius, uZoom, uWarp, uRidgeAmt, uShade, uSheen, uGloss;
uniform float uShellMidAlpha, uShellEdgeAlpha, uExposure, uGlassOpacity;
uniform vec3  uColorA, uColorB, uColorC, uColorD, uHighlight;
uniform vec3  uShellInner, uShellMid, uShellEdge, uSheenColor, uSpecColor;
uniform vec3  uCanvasColor, uGlowColor;

// --- glsSiriBand ---
vec2 siriBand(vec2 q, float drift, float phaseOffset, float amplitude,
              float mainY, float envelope, float softness) {
  float y = amplitude * envelope * sin(q.x * 1.0 + drift + phaseOffset);
  float dl = abs(q.y - y);
  float line = 0.018 / (sqrt(dl * dl + softness * softness) + 0.026);
  float bandDistance = max(0.0, max(q.y - max(mainY, y), min(mainY, y) - q.y));
  float band = 0.018 / (bandDistance + 0.075);
  return vec2(line, band);
}

// --- glsFinishEmissionFluid (glassEnabled = 1) ---
vec3 finishEmission(vec3 c, vec2 p) {
  c = mix(c, uHighlight,
          uShade * 0.22 * smoothstep(0.15, 1.15, dot(p, vec2(-0.32, 0.78))));
  c *= 1.0 - uShade * 0.34 * smoothstep(-0.1, 1.2, dot(p, vec2(0.45, -0.62)));
  c *= 1.0 - uShade * 0.22 * smoothstep(0.72, 1.08, length(p));
  return clamp(c, 0.0, 1.0);
}

// --- glsSiriFluid ---
vec3 siriFluid(vec2 p, float t) {
  float scale = 0.74 + uZoom * 0.34;
  vec2  q = p / scale;
  float envelopeBase = cos(1.57079633 * min(abs(0.9 * q.x), 1.0));
  float envelope = envelopeBase * envelopeBase;
  float low  = 0.5 + 0.5 * cos(t * 0.37);
  float mid  = 0.5 + 0.5 * sin(t * 0.51 + 1.2);
  float high = 0.5 + 0.5 * cos(t * 0.73 + 2.1);
  float drift = t * 2.4;
  float mainAmplitude = 0.25 + uRidgeAmt * 0.075 + low * 0.018;
  float bandAmplitude = mainAmplitude + mid * 0.025 + high * 0.018;
  float mainY = mainAmplitude * envelope * sin(q.x * 1.1 + drift);
  float separation = 1.85 + uWarp * 0.2 + mid * 0.28;
  float softness = 0.035 + (1.0 - uRidgeAmt) * 0.018 + mid * 0.006;

  vec2 b0 = siriBand(q, drift, -separation,        bandAmplitude, mainY, envelope, softness);
  vec2 b1 = siriBand(q, drift, -separation * 0.34, bandAmplitude, mainY, envelope, softness);
  vec2 b2 = siriBand(q, drift,  separation * 0.34, bandAmplitude, mainY, envelope, softness);
  vec2 b3 = siriBand(q, drift,  separation,        bandAmplitude, mainY, envelope, softness);
  float w0 = b0.x + b0.y, w1 = b1.x + b1.y, w2 = b2.x + b2.y, w3 = b3.x + b3.y;
  float total = w0 + w1 + w2 + w3;
  float d0 = w0 * w0, d1 = w1 * w1, d2 = w2 * w2, d3 = w3 * w3;
  float dTotal = d0 + d1 + d2 + d3;
  vec3 spectral = (uColorA * d0 + uColorC * d1 + uColorB * d2 + uColorD * d3)
                / max(dTotal, 0.0001);
  float energy = (1.0 - exp(-total * 0.58)) * envelope;
  float mainDistance = abs(q.y - mainY);
  float whiteCore = exp(-mainDistance * mainDistance / 0.0028) * envelope;
  // glassFill = 1 لأن الغلاف مفعّل
  vec3 atmosphere = mix(uColorD, uColorB, smoothstep(-0.7, 0.7, q.y)) * 0.018;
  vec3 color = atmosphere + spectral * energy * 1.14;
  color += uHighlight * whiteCore * (0.18 + 0.1 * low);
  color = color / (vec3(1.0) + color * 0.18);
  return finishEmission(color, p);
}

vec3 over(vec3 dst, vec3 src, float a) {
  float k = clamp(a, 0.0, 1.0);
  return src * k + dst * (1.0 - k);
}

float refractionProfileFn(float t) {
  float depth = clamp(t, 0.0, 1.0);
  float circular = sqrt(max(1.0 - (1.0 - depth) * (1.0 - depth), 0.0));
  return 1.0 - circular;
}

float highlightLobe(vec2 n, vec2 dir, float cut, float power) {
  float angular = clamp((dot(n, dir) - cut) / max(1.0 - cut, 0.001), 0.0, 1.0);
  return pow(angular, power);
}

void main() {
  vec2 fc = gl_FragCoord.xy;
  vec2 uv = (2.0 * fc - uSize) / max(min(uSize.x, uSize.y), 1.0);
  float rad = max(uRadius, 0.05);
  float t = uTime * uSpeed;

  if (length(uv) > rad * 1.01) { gl_FragColor = vec4(0.0); return; }

  vec2  p  = uv / rad;
  float pd = length(p);
  float clearFa = 1.0 - smoothstep(0.995, 1.04, pd);

  // contourDeform = 0 → العمودي هو الشعاعي، وstyle != 23 فمفيش تشويه إضافي
  vec2 normal = pd > 0.0001 ? p / pd : vec2(0.0);

  float edgeDepth = max(1.0 - pd, 0.0);
  float refractionWidth = 0.015 + 0.95 * clamp(uShellMidAlpha, 0.0, 1.0);
  float refractionT = edgeDepth / max(refractionWidth, 0.001);
  float refractionProfile = pow(refractionProfileFn(refractionT), 0.68);
  float refractionAmount = 1.6 * clamp(uGlassOpacity, 0.0, 1.0) * refractionProfile;
  vec2  refractedP = p - normal * refractionAmount;

  vec3 fcol = vec3(0.0);
  if (clearFa > 0.0) {
    // تشتيت لوني حقيقي: ثلاث تقييمات للمائع
    float channelSplit = 0.14 * clamp(uGloss, 0.0, 2.0)
                       * clamp(uGlassOpacity, 0.0, 1.0) * refractionProfile;
    vec3 r = siriFluid(refractedP - normal * channelSplit, t);
    vec3 g = siriFluid(refractedP, t);
    vec3 b = siriFluid(refractedP + normal * channelSplit, t);
    fcol = vec3(r.r, g.g, b.b);
  }

  float lum = dot(fcol, vec3(0.213, 0.715, 0.072));
  vec3 clearSat = clamp(vec3(lum) + (fcol - vec3(lum)) * 1.22, 0.0, 1.0);
  vec3 col = over(uCanvasColor, clearSat, 0.99 * clearFa);

  // ── قشرة الزجاج ──
  float surfaceWidth = 0.026 + 0.055 * clamp(uShellEdgeAlpha, 0.0, 1.0);
  float surfaceBand = (1.0 - smoothstep(0.0, surfaceWidth, edgeDepth)) * clearFa;
  float opticalRim = pow(surfaceBand, 1.8);
  col = over(col, uShellInner, opticalRim * uGlassOpacity * 0.45);

  vec2 coolDirection = normalize(vec2(0.84, 0.54));
  vec2 warmDirection = normalize(vec2(-0.62, -0.78));
  float coolSplit = highlightLobe(normal, coolDirection, -0.32, 1.8);
  float warmSplit = highlightLobe(normal, warmDirection, -0.28, 2.0);
  float dispersion = opticalRim * clamp(uGloss, 0.0, 2.0) * (0.8 + 0.8 * uShellEdgeAlpha);
  col = over(col, uShellMid, dispersion * coolSplit);
  col = over(col, uShellEdge, dispersion * warmSplit);

  float edgeShadow = opticalRim * (0.015 + 0.15 * uShellEdgeAlpha)
                   * (0.15 + 0.85 * max(dot(normal, vec2(0.45, -0.89)), 0.0));
  col *= 1.0 - edgeShadow;

  vec2 keyDirection  = normalize(vec2(-0.68, 0.73));
  vec2 fillDirection = normalize(vec2(0.74, -0.67));
  float key  = opticalRim * highlightLobe(normal, keyDirection, 0.2, 2.8)
             * clamp(uSheen, 0.0, 2.0) * 1.4;
  float fill = opticalRim * highlightLobe(normal, fillDirection, 0.4, 3.6)
             * clamp(uSheen, 0.0, 2.0) * 1.0;
  col = over(col, uSheenColor, key);
  col = over(col, uSpecColor, fill);

  float ballA = 1.0 - smoothstep(0.99, 1.01, pd);
  col = clamp(col * max(uExposure, 0.0), 0.0, 1.0) * ballA;
  gl_FragColor = vec4(col, ballA);
}`;

  /* ── الحالة والانتقال ── */
  let state = 'idle';
  let fromP = snapshot(SEED.idle);
  let toP = snapshot(SEED.idle);
  const shown = snapshot(SEED.idle);
  let transAt = 0;
  let transMs = 0;

  function snapshot(s) {
    const o = {};
    SCALARS.forEach((k) => { o[k] = s[k]; });
    COLORS.forEach((k) => { o[k] = s[k].slice(); });
    return o;
  }

  function srgbToLinear(v) { return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }
  function linearToSrgb(v) { return v <= 0.0031308 ? v * 12.92 : 1.055 * Math.pow(v, 1 / 2.4) - 0.055; }
  function mixSrgb(a, b, k) {
    return linearToSrgb(srgbToLinear(a) + (srgbToLinear(b) - srgbToLinear(a)) * k);
  }

  function sample(now) {
    let k = 1;
    if (transMs > 0) {
      const raw = Math.min(1, Math.max(0, (now - transAt) / transMs));
      k = state === 'thinking' ? 1 - Math.pow(1 - raw, 3) : raw * raw * (3 - 2 * raw);
    }
    SCALARS.forEach((n) => { shown[n] = fromP[n] + (toP[n] - fromP[n]) * k; });
    COLORS.forEach((n) => {
      for (let c = 0; c < 3; c += 1) shown[n][c] = mixSrgb(fromP[n][c], toP[n][c], k);
    });
    return shown;
  }

  /* ── محرك WebGL ── */
  let canvas = null, gl = null, prog = null, loc = {}, host = null;
  let raf = 0, running = false, phase = 0, lastAt = null, failed = false;

  function initGL() {
    canvas = document.createElement('canvas');
    canvas.className = 'orb-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    gl = canvas.getContext('webgl', { alpha: true, premultipliedAlpha: false, antialias: false })
      || canvas.getContext('experimental-webgl', { alpha: true, premultipliedAlpha: false });
    if (!gl) { failed = true; canvas = null; return false; }

    function compile(type, src) {
      const sh = gl.createShader(type);
      gl.shaderSource(sh, src);
      gl.compileShader(sh);
      if (!gl.getShaderParameter(sh, gl.COMPILE_STATUS)) {
        console.warn('orb shader:', gl.getShaderInfoLog(sh));
        return null;
      }
      return sh;
    }
    const vs = compile(gl.VERTEX_SHADER, VS);
    const fs = compile(gl.FRAGMENT_SHADER, FS);
    if (!vs || !fs) { failed = true; canvas = null; return false; }

    prog = gl.createProgram();
    gl.attachShader(prog, vs);
    gl.attachShader(prog, fs);
    gl.linkProgram(prog);
    if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) { failed = true; canvas = null; return false; }
    gl.useProgram(prog);

    const buf = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buf);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
    const aPos = gl.getAttribLocation(prog, 'aPos');
    gl.enableVertexAttribArray(aPos);
    gl.vertexAttribPointer(aPos, 2, gl.FLOAT, false, 0, 0);

    ['uSize', 'uTime'].concat(
      SCALARS.map((n) => 'u' + n[0].toUpperCase() + n.slice(1)),
      COLORS.map((n) => 'u' + n[0].toUpperCase() + n.slice(1)),
    ).forEach((n) => { loc[n] = gl.getUniformLocation(prog, n); });

    gl.disable(gl.DEPTH_TEST);
    return true;
  }

  function resize() {
    if (!canvas || !host) return;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const r = host.getBoundingClientRect();
    const w = Math.max(1, Math.round(r.width * dpr));
    const h = Math.max(1, Math.round(r.height * dpr));
    if (canvas.width !== w || canvas.height !== h) {
      canvas.width = w; canvas.height = h;
      gl.viewport(0, 0, w, h);
    }
  }

  function draw(now) {
    if (!running || !gl) return;
    resize();
    const p = sample(now);
    const dt = lastAt === null ? 0 : Math.min(0.1, Math.max(0, (now - lastAt) / 1000));
    lastAt = now;
    if (!reduceMotion) phase += dt * p.speed;

    gl.useProgram(prog);
    gl.uniform2f(loc.uSize, canvas.width, canvas.height);
    gl.uniform1f(loc.uTime, phase / Math.max(p.speed, 0.001));
    SCALARS.forEach((n) => {
      const u = loc['u' + n[0].toUpperCase() + n.slice(1)];
      if (u) gl.uniform1f(u, p[n]);
    });
    COLORS.forEach((n) => {
      const u = loc['u' + n[0].toUpperCase() + n.slice(1)];
      if (u) gl.uniform3f(u, p[n][0], p[n][1], p[n][2]);
    });

    gl.clearColor(0, 0, 0, 0);
    gl.clear(gl.COLOR_BUFFER_BIT);
    gl.drawArrays(gl.TRIANGLES, 0, 3);
    raf = requestAnimationFrame(draw);
  }

  /* ── بديل بسيط لو WebGL مش متاح ── */
  function fallbackEl() {
    const d = document.createElement('div');
    d.className = 'orb-fallback';
    d.setAttribute('aria-hidden', 'true');
    return d;
  }

  const SpreadOrb = {
    mount(el) {
      if (!el) return;
      if (!canvas && !failed) initGL();
      if (failed && !canvas) {
        canvas = fallbackEl();
        canvas.dataset.state = state;
      }
      if (!canvas) return;
      if (host !== el) { host = el; el.appendChild(canvas); }
      if (failed) return;
      if (!running) { running = true; lastAt = null; raf = requestAnimationFrame(draw); }
    },

    unmount() {
      running = false;
      cancelAnimationFrame(raf);
      if (canvas && canvas.parentNode) canvas.parentNode.removeChild(canvas);
      host = null;
    },

    setState(next) {
      if (!SEED[next] || next === state) return;
      const now = performance.now();
      sample(now);
      fromP = snapshot(shown);
      toP = snapshot(SEED[next]);
      transAt = now;
      transMs = next === 'thinking' ? ACTIVATION_MS : SETTLE_MS;
      state = next;
      if (failed && canvas) canvas.dataset.state = next;
    },

    getState() { return state; },
  };

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) cancelAnimationFrame(raf);
    else if (running) { lastAt = null; raf = requestAnimationFrame(draw); }
  });

  window.SpreadOrb = SpreadOrb;
})();
