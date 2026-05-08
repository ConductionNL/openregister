/* Clients Flow: continuous-spawn marquee runtime for the Clients hex
   wall. Replaces the old CSS-animated track-with-duplicate approach,
   which had a one-pixel seam at the wrap boundary. Each row is a
   simple relative container; the runtime spawns absolutely-positioned
   <a class="hex"> children on the right, drifts them left at a per-row
   speed via translateX, and removes them once they leave the left
   edge. Logos are picked at random from the supplied client pool,
   avoiding immediate repeats so the same shield doesn't keep flashing
   in one row.

   Hooks:
     - data-memory-marquee on the marquee element (also serves as the
       Logo Memory game trigger surface)
     - data-clients='[{"src":"...","name":"..."}, ...]' on the marquee
     - data-hex-class on the marquee (optional) — class name for spawned
       hex anchors. CSS Modules hashes the React build's class to
       something like hex_QXzL; pass that through. Falls back to "hex"
       which the preview HTML uses literally.
     - data-row-stagger on a row (optional) — px offset added to the
       row's first hex x. Used by row 2 to slot half-hex into the
       honeycomb stagger between row 1 and row 3.
     - data-row-speed on a row (optional) — drift speed in px/sec.
       Defaults to 50; row 2 defaults to 58 and row 3 to 46 for slight
       visual desync.

   Public API:
     window.ConductionClientsFlow.hydrate()   — find + initialise marquees
     window.ConductionClientsFlow.pause()     — freeze drift (used by Logo
                                                Memory while a game is in
                                                progress)
     window.ConductionClientsFlow.resume()    — un-freeze
     window.ConductionClientsFlow.reset()     — clear all hex children
                                                and re-fill from scratch.
                                                Logo Memory calls this on
                                                game tear-down so the
                                                marquee comes back fresh.
*/
(function () {
  const DEFAULT_SPEED = 50;
  /* All rows use the same default speed so honeycomb alignment is
     preserved (rows 1 and 3 stay column-aligned, row 2 stays at the
     half-hex offset). Per-row data-row-speed still lets a host page
     override this if that's the look they want. The "more dynamic"
     feel comes from random-logo selection on each spawn, not from
     per-row drift desync. */
  const DEFAULT_ROW_SPEEDS = [50, 50, 50];
  const RECENT_REPEAT_AVOID = 5;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* All running marquees. Each entry holds the per-row state for that
     marquee plus the rAF id so global pause/resume can address them. */
  const REGISTRY = [];
  let rafId = null;

  function readPx(value, fallback) {
    const n = parseFloat(value);
    return isFinite(n) && n >= 0 ? n : fallback;
  }

  function pickLogo(state, clients) {
    let pick;
    let attempts = 0;
    do {
      pick = clients[(Math.random() * clients.length) | 0];
      attempts++;
    } while (state.recent.includes(pick.src) && attempts < 10);
    state.recent.push(pick.src);
    if (state.recent.length > RECENT_REPEAT_AVOID) state.recent.shift();
    return pick;
  }

  function spawnHex(state, baseX, scrollPx) {
    /* baseX is the hex's "world" x at scrollPx=0. Current visible
       position = baseX - scrollPx. Storing baseX lets every tick
       recompute current x from the shared scroll accumulator without
       per-hex per-row drift accumulation. */
    const logo = pickLogo(state, state.clients);
    const x = baseX - (scrollPx || 0);
    const a = document.createElement('a');
    a.className = state.hexClass;
    a.href = '#';
    a.setAttribute('aria-label', logo.name);
    a.style.position = 'absolute';
    a.style.top = '0';
    a.style.left = '0';
    a.style.transform = 'translateX(' + x + 'px)';
    a.style.willChange = 'transform';
    const img = document.createElement('img');
    img.src = logo.src;
    img.alt = logo.name;
    img.title = logo.name;
    img.loading = 'lazy';
    if (state.hexLogoClass) img.className = state.hexLogoClass;
    a.appendChild(img);
    a.addEventListener('click', function (e) { e.preventDefault(); });
    state.row.appendChild(a);
    state.hexes.push({ el: a, baseX: baseX, x: x });
    return a;
  }

  function initialFill(state) {
    /* Pre-populate the row with hexes from its stagger offset out
       past the right edge, so users never see an empty row. baseX
       starts at stagger; advance by pitch and spawn while still in
       the right-edge spawn zone. */
    const containerWidth = state.row.getBoundingClientRect().width;
    let baseX = state.stagger;
    while (baseX < containerWidth + state.hexW) {
      spawnHex(state, baseX, 0);
      baseX += state.pitch;
    }
    state.cursorBaseX = baseX;
  }

  function tickMarquee(marqueeState, scrollPx) {
    /* `scrollPx` is the cumulative drift since the marquee started, in
       pixels, shared across all rows. Each hex stores its spawn-time
       baseline (h.baseX = the x at scroll = 0); current position is
       baseX - scrollPx. Going via this single shared accumulator
       guarantees rows stay perfectly in lockstep — there is no per-
       row dt to diverge on. */
    marqueeState.rows.forEach(function (state) {
      if (state.paused) return;
      const containerWidth = state.row.getBoundingClientRect().width;
      /* Update every hex's transform from its baseline. */
      for (let i = 0; i < state.hexes.length; i++) {
        const h = state.hexes[i];
        h.x = h.baseX - scrollPx;
        h.el.style.transform = 'translateX(' + h.x + 'px)';
      }
      /* Cull anything that fully left the viewport on the left. */
      for (let i = state.hexes.length - 1; i >= 0; i--) {
        if (state.hexes[i].x + state.hexW < -16) {
          state.hexes[i].el.remove();
          state.hexes.splice(i, 1);
        }
      }
      /* Cursor is also baseline-relative: cursor world position =
         cursorBaseX - scrollPx. Spawn while it falls inside the spawn
         zone (the right-edge buffer). */
      let safety = 0;
      while ((state.cursorBaseX - scrollPx) < containerWidth + state.hexW && safety < 50) {
        const newBaseX = state.cursorBaseX;
        spawnHex(state, newBaseX, scrollPx);
        state.cursorBaseX += state.pitch;
        safety++;
      }
    });
  }

  /* Single shared scroll accumulator for all marquees + rows. Stays
     constant while paused, advances by speed*dt while running. Each
     hex's visible position is baseX - scrollPx, so all hexes (any
     row, any marquee) move in lockstep. */
  let scrollStart = null;       // ts when the current run began
  let scrollAccum = 0;          // total scroll px captured at last pause
  let running = true;

  function currentScroll(timestamp) {
    const speed = REGISTRY[0]?.rows[0]?.speed ?? DEFAULT_SPEED;
    if (!running) return scrollAccum;
    if (scrollStart === null) scrollStart = timestamp;
    return scrollAccum + (timestamp - scrollStart) * speed / 1000;
  }

  function tick(timestamp) {
    const scrollPx = currentScroll(timestamp);
    REGISTRY.forEach(function (m) { tickMarquee(m, scrollPx); });
    rafId = requestAnimationFrame(tick);
  }

  function startLoop() {
    if (rafId !== null) return;
    lastTimestamp = null;
    rafId = requestAnimationFrame(tick);
  }

  function hydrateMarquee(marquee) {
    if (marquee.dataset.flowHydrated === '1') return;
    marquee.dataset.flowHydrated = '1';

    let clients;
    /* Two ways to pass the client pool: a JSON string in
       data-clients (compact, used by the React build) or an inner
       <script type="application/json" data-clients-source> (less
       quote-noise, used by the preview HTML). */
    try { clients = JSON.parse(marquee.dataset.clients || '[]'); }
    catch (e) { clients = []; }
    if (!clients.length) {
      const inline = marquee.querySelector('script[type="application/json"][data-clients-source]');
      if (inline) {
        try { clients = JSON.parse(inline.textContent); }
        catch (e) { clients = []; }
      }
    }
    if (!clients || !clients.length) return;

    const hexClass = marquee.dataset.hexClass || 'hex';
    const hexLogoClass = marquee.dataset.hexLogoClass || 'hexLogo';
    const hexW = readPx(getComputedStyle(marquee).getPropertyValue('--hex-w'), 120);
    const gap = readPx(getComputedStyle(marquee).getPropertyValue('--gap'), 8);
    const pitch = hexW + gap;

    const rowEls = Array.from(marquee.querySelectorAll('[data-flow-row]'));
    if (!rowEls.length) return;

    const rows = rowEls.map(function (row, idx) {
      const stagger = readPx(row.dataset.rowStagger, idx === 1 ? pitch / 2 : 0);
      const speedAttr = parseFloat(row.dataset.rowSpeed);
      const speed = isFinite(speedAttr) && speedAttr > 0
        ? speedAttr
        : (DEFAULT_ROW_SPEEDS[idx] || DEFAULT_SPEED);
      return {
        row: row,
        stagger: stagger,
        speed: reduceMotion ? 0 : speed,
        hexW: hexW,
        pitch: pitch,
        clients: clients,
        hexClass: hexClass,
        hexLogoClass: hexLogoClass,
        hexes: [],
        cursorBaseX: stagger,
        recent: [],
        paused: false,
      };
    });

    const marqueeState = { marquee: marquee, rows: rows };
    rows.forEach(initialFill);
    REGISTRY.push(marqueeState);
    if (!reduceMotion) startLoop();
  }

  function hydrate() {
    document.querySelectorAll('[data-memory-marquee]').forEach(hydrateMarquee);
  }

  function pause() {
    if (!running) return;
    /* Capture scroll into the accumulator and FULLY halt the rAF.
       Setting paused flags isn't enough — Logo Memory snapshots and
       lifts hex positions, and any subsequent tick that reads/writes
       a transform on a now-lifted hex would corrupt its placement. */
    scrollAccum = currentScroll(performance.now());
    scrollStart = null;
    running = false;
    if (rafId !== null) {
      cancelAnimationFrame(rafId);
      rafId = null;
    }
    REGISTRY.forEach(function (m) {
      m.rows.forEach(function (r) { r.paused = true; });
    });
  }

  function resume() {
    if (running) return;
    scrollStart = null;          // first tick after resume re-anchors
    running = true;
    REGISTRY.forEach(function (m) {
      m.rows.forEach(function (r) { r.paused = false; });
    });
    if (!reduceMotion) startLoop();
  }

  function reset() {
    /* Halt rAF before mutating state so a stray tick can't write to
       elements we're about to remove. */
    if (rafId !== null) {
      cancelAnimationFrame(rafId);
      rafId = null;
    }
    scrollAccum = 0;
    scrollStart = null;
    running = true;
    REGISTRY.forEach(function (m) {
      m.rows.forEach(function (r) {
        r.hexes.forEach(function (h) { h.el.remove(); });
        r.hexes.length = 0;
        r.recent.length = 0;
        r.cursorBaseX = r.stagger;
        r.paused = false;
        initialFill(r);
      });
    });
    if (!reduceMotion) startLoop();
  }

  window.ConductionClientsFlow = window.ConductionClientsFlow || {};
  window.ConductionClientsFlow.hydrate = hydrate;
  window.ConductionClientsFlow.pause = pause;
  window.ConductionClientsFlow.resume = resume;
  window.ConductionClientsFlow.reset = reset;
  window.ConductionClientsFlow._inspect = function () {
    return REGISTRY.map(function (m) {
      return {
        rows: m.rows.map(function (r) {
          return {
            stagger: r.stagger,
            speed: r.speed,
            cursorBaseX: r.cursorBaseX,
            hexCount: r.hexes.length,
            baseXs: r.hexes.map(function (h) { return h.baseX; }),
            currentXs: r.hexes.map(function (h) { return Math.round(h.x * 100) / 100; }),
          };
        }),
      };
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hydrate);
  } else {
    hydrate();
  }
})();
