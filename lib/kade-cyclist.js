/* Kade Cyclist: hidden minigame on the canal-footer kade strip. First
   click on a drifting kade bike clears the ambient kade traffic, drops
   the player cyclist under the blue Conduction house in the skyline,
   and starts a slow-then-ramping dodge round. Hazards (bike → scooter
   → car → bus → truck → tram) unlock progressively as the player racks
   up dodges, and both spawn rate + drift speed creep up.

   ↑/W and ↓/S switch between the top and bottom lane. Collision ends
   the round, fires `connext:gameend` on window so the GameModal picks
   it up, and the modal's "Play again" button fires `connext:gamereplay`
   which we listen for to re-run the round. The ambient kade is
   restored when the round ends. */
(function () {
  const PLAYER_COLLISION_X_TOL = 24;   // px tolerance around the player x
  const HAZARD_BASE_MS = 6000;         // very slow first hazards (drift duration)
  const HAZARD_FLOOR_MS = 1900;        // cap at hardest difficulty
  const SPAWN_INITIAL_MS = 4500;       // very slow first spawn interval
  const SPAWN_FLOOR_MS = 700;          // cap at hardest spawn interval

  /* Difficulty tiers — kind unlocks happen at fixed dodge counts. The
     speed/spawn multipliers grow continuously per dodge; tiers only
     control which kinds are eligible to spawn. Vehicles get
     progressively bigger and harder to read at speed. */
  const KIND_TIERS = [
    { atDodges: 0,  kinds: ['bike'] },
    { atDodges: 3,  kinds: ['bike', 'scooter'] },
    { atDodges: 6,  kinds: ['bike', 'scooter', 'car'] },
    { atDodges: 10, kinds: ['bike', 'scooter', 'car', 'bus'] },
    { atDodges: 15, kinds: ['bike', 'scooter', 'car', 'bus', 'truck'] },
    { atDodges: 22, kinds: ['bike', 'scooter', 'car', 'bus', 'truck', 'tram'] },
  ];

  function currentKinds(dodges) {
    let active = KIND_TIERS[0].kinds;
    for (let i = 0; i < KIND_TIERS.length; i++) {
      if (dodges >= KIND_TIERS[i].atDodges) active = KIND_TIERS[i].kinds;
    }
    return active;
  }

  function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Inline SVG library. Kept in JS so the runtime doesn't depend on the
     host page's <template> blocks. Sizes match the kade band's 16px
     content height; viewBoxes leave a small bleed above for handlebars,
     poles, and exhaust. */
  function buildHazardSvg(kind) {
    if (kind === 'bike') {
      return (
        '<svg class="kc-hazard-svg kc-hk-bike" width="22" height="16" viewBox="0 -2 22 18" aria-hidden="true">' +
          '<g stroke="#0A172F" stroke-width="1.4" fill="none" stroke-linecap="round">' +
            '<circle cx="4" cy="12" r="3"/>' +
            '<circle cx="18" cy="12" r="3"/>' +
            '<line x1="4" y1="12" x2="11" y2="6"/>' +
            '<line x1="11" y1="6" x2="18" y2="12"/>' +
            '<line x1="11" y1="6" x2="14" y2="12"/>' +
            '<line x1="11" y1="6" x2="11" y2="3"/>' +
            '<line x1="11" y1="3" x2="14" y2="6"/>' +
          '</g>' +
          '<circle cx="11" cy="1.5" r="1.6" fill="#0A172F"/>' +
        '</svg>'
      );
    }
    if (kind === 'scooter') {
      return (
        '<svg class="kc-hazard-svg kc-hk-scooter" width="22" height="16" viewBox="0 -2 22 18" aria-hidden="true">' +
          '<g stroke="#3A3F4B" stroke-width="1.4" fill="none" stroke-linecap="round">' +
            '<circle cx="5" cy="12" r="3"/>' +
            '<circle cx="17" cy="12" r="3"/>' +
            '<path d="M 5,12 L 11,12 L 14,5 L 17,12" stroke-width="1.6"/>' +
            '<line x1="14" y1="5" x2="16" y2="2"/>' +
            '<line x1="14" y1="5" x2="12" y2="2"/>' +
          '</g>' +
        '</svg>'
      );
    }
    if (kind === 'car') {
      return (
        '<svg class="kc-hazard-svg kc-hk-car" width="38" height="16" viewBox="0 0 38 16" aria-hidden="true">' +
          '<rect x="0" y="6" width="38" height="6" rx="2" fill="#0A172F"/>' +
          '<path d="M 5,6 L 9,2 L 29,2 L 33,6 Z" fill="#0A172F"/>' +
          '<rect x="11" y="3" width="6" height="3" fill="rgba(255,255,255,0.4)"/>' +
          '<rect x="20" y="3" width="6" height="3" fill="rgba(255,255,255,0.4)"/>' +
          '<circle cx="9" cy="13" r="2" fill="#C8482F"/>' +
          '<circle cx="29" cy="13" r="2" fill="#C8482F"/>' +
        '</svg>'
      );
    }
    if (kind === 'bus') {
      /* GVB-style city bus, side profile. Cobalt body, white windows. */
      return (
        '<svg class="kc-hazard-svg kc-hk-bus" width="58" height="16" viewBox="0 -1 58 17" aria-hidden="true">' +
          '<rect x="0" y="2" width="58" height="11" rx="2" fill="#21468B"/>' +
          '<rect x="2" y="4" width="6" height="4" fill="rgba(255,255,255,0.85)"/>' +
          '<rect x="10" y="4" width="6" height="4" fill="rgba(255,255,255,0.85)"/>' +
          '<rect x="18" y="4" width="6" height="4" fill="rgba(255,255,255,0.85)"/>' +
          '<rect x="26" y="4" width="6" height="4" fill="rgba(255,255,255,0.85)"/>' +
          '<rect x="34" y="4" width="6" height="4" fill="rgba(255,255,255,0.85)"/>' +
          '<rect x="42" y="4" width="6" height="4" fill="rgba(255,255,255,0.85)"/>' +
          '<rect x="50" y="4" width="6" height="4" fill="rgba(255,255,255,0.85)"/>' +
          '<rect x="50" y="9" width="6" height="2" fill="#F77F0E"/>' +
          '<circle cx="10" cy="13" r="2" fill="#0A172F"/>' +
          '<circle cx="48" cy="13" r="2" fill="#0A172F"/>' +
        '</svg>'
      );
    }
    if (kind === 'truck') {
      /* Cab + container, two sets of wheels. Cobalt cab, white box. */
      return (
        '<svg class="kc-hazard-svg kc-hk-truck" width="68" height="16" viewBox="0 0 68 16" aria-hidden="true">' +
          '<rect x="0" y="3" width="42" height="9" fill="#FFFFFF" stroke="#0A172F" stroke-width="0.8"/>' +
          '<rect x="42" y="5" width="20" height="7" fill="#21468B"/>' +
          '<path d="M 62,5 L 66,8 L 62,8 Z" fill="#21468B"/>' +
          '<rect x="46" y="6" width="6" height="3" fill="rgba(255,255,255,0.5)"/>' +
          '<circle cx="8" cy="13" r="2" fill="#0A172F"/>' +
          '<circle cx="22" cy="13" r="2" fill="#0A172F"/>' +
          '<circle cx="50" cy="13" r="2" fill="#0A172F"/>' +
          '<circle cx="60" cy="13" r="2" fill="#0A172F"/>' +
        '</svg>'
      );
    }
    /* tram — GVB Amsterdam, blue body with yellow band, overhead pole */
    return (
      '<svg class="kc-hazard-svg kc-hk-tram" width="80" height="16" viewBox="0 -3 80 19" aria-hidden="true">' +
        '<line x1="40" y1="-3" x2="40" y2="2" stroke="#3A3F4B" stroke-width="0.8"/>' +
        '<rect x="0" y="2" width="80" height="11" rx="1" fill="#21468B"/>' +
        '<rect x="0" y="9" width="80" height="2" fill="#F4D04A"/>' +
        '<rect x="2" y="4" width="5" height="4" fill="rgba(255,255,255,0.9)"/>' +
        '<rect x="9" y="4" width="5" height="4" fill="rgba(255,255,255,0.9)"/>' +
        '<rect x="16" y="4" width="5" height="4" fill="rgba(255,255,255,0.9)"/>' +
        '<rect x="23" y="4" width="5" height="4" fill="rgba(255,255,255,0.9)"/>' +
        '<rect x="32" y="4" width="5" height="4" fill="rgba(255,255,255,0.9)"/>' +
        '<rect x="39" y="4" width="5" height="4" fill="rgba(255,255,255,0.9)"/>' +
        '<rect x="46" y="4" width="5" height="4" fill="rgba(255,255,255,0.9)"/>' +
        '<rect x="53" y="4" width="5" height="4" fill="rgba(255,255,255,0.9)"/>' +
        '<rect x="60" y="4" width="5" height="4" fill="rgba(255,255,255,0.9)"/>' +
        '<rect x="67" y="4" width="5" height="4" fill="rgba(255,255,255,0.9)"/>' +
        '<rect x="74" y="4" width="4" height="4" fill="rgba(255,255,255,0.9)"/>' +
        '<circle cx="14" cy="13" r="1.6" fill="#0A172F"/>' +
        '<circle cx="22" cy="13" r="1.6" fill="#0A172F"/>' +
        '<circle cx="58" cy="13" r="1.6" fill="#0A172F"/>' +
        '<circle cx="66" cy="13" r="1.6" fill="#0A172F"/>' +
      '</svg>'
    );
  }

  /* Player bike: like the ki-bike-1 outline but with an orange jersey
     so it reads as the player tile, not ambient traffic. */
  function buildPlayerSvg() {
    return (
      '<svg class="kc-player-svg" width="22" height="16" viewBox="0 -2 22 18" aria-hidden="true">' +
        '<g stroke="#0A172F" stroke-width="1.4" fill="none" stroke-linecap="round">' +
          '<circle cx="4" cy="12" r="3"/>' +
          '<circle cx="18" cy="12" r="3"/>' +
          '<line x1="4" y1="12" x2="11" y2="6"/>' +
          '<line x1="11" y1="6" x2="18" y2="12"/>' +
          '<line x1="11" y1="6" x2="14" y2="12"/>' +
          '<line x1="11" y1="6" x2="11" y2="3"/>' +
          '<line x1="11" y1="3" x2="14" y2="6"/>' +
        '</g>' +
        '<circle cx="11" cy="1.5" r="1.6" fill="var(--c-orange-knvb, #F77F0E)"/>' +
        '<circle cx="4" cy="12" r="1.6" fill="var(--c-orange-knvb, #F77F0E)"/>' +
        '<circle cx="18" cy="12" r="1.6" fill="var(--c-orange-knvb, #F77F0E)"/>' +
      '</svg>'
    );
  }

  function findRoot() { return document.querySelector('.canal-footer'); }

  /* Per-root state stash so the connext:gamereplay listener can find
     the right root and tear-down can restore the ambient items. */
  const ACTIVE = new WeakMap();

  function hydrate() {
    const root = findRoot();
    if (!root) return;
    if (root.dataset.kadeHydrated === '1') return;
    const kade = root.querySelector('.kade');
    if (!kade) return;
    root.dataset.kadeHydrated = '1';

    const onBikeClick = function (e) {
      if (root.dataset.kadeActive === '1') return;
      const target = e.target.closest('.ki-bike-1, .ki-bike-2');
      if (!target || !kade.contains(target)) return;
      e.preventDefault();
      e.stopPropagation();
      startGame(root, kade);
    };
    kade.addEventListener('click', onBikeClick, true);

    /* Modal "Play again" → re-run the round. */
    window.addEventListener('connext:gamereplay', function (e) {
      if (e.detail && e.detail.id === 'kade-cyclist') {
        if (root.dataset.kadeActive === '1') tearDownActive(root, kade);
        setTimeout(function () { startGame(root, kade); }, 80);
      }
    });
    /* Modal "Close" → tear down the game stage and restore ambient. */
    window.addEventListener('connext:gameclose', function (e) {
      if (e.detail && e.detail.id === 'kade-cyclist') {
        if (root.dataset.kadeActive === '1') tearDownActive(root, kade);
      }
    });
  }

  function startGame(root, kade) {
    root.dataset.kadeActive = '1';

    /* Clear the ambient kade — actually remove the items, not just
       pause them, so the field reads as a clean playfield. We stash
       the cleared markup on the kade-items container so tearDown can
       restore it. */
    const kadeItems = kade.querySelector('.kade-items');
    const ambientHTML = kadeItems ? kadeItems.innerHTML : '';
    if (kadeItems) kadeItems.innerHTML = '';

    /* Compute player x: align under the blue Conduction house in the
       skyline. The skyline is randomised at every page load by canal-
       footer.js but always marks the centre house with .house-
       conduction. Fall back to 14% of viewport if the marker isn't
       present yet. */
    const blueHouse = root.querySelector('.house-conduction');
    const rootRect = root.getBoundingClientRect();
    let playerCenterX;
    if (blueHouse) {
      const r = blueHouse.getBoundingClientRect();
      playerCenterX = (r.left + r.width / 2) - rootRect.left;
    } else {
      playerCenterX = rootRect.width * 0.14 + 11;
    }

    const stage = document.createElement('div');
    stage.className = 'kc-stage';
    stage.innerHTML =
      '<div class="kc-track" data-track>' +
        '<div class="kc-lane kc-lane-top" data-lane="top"></div>' +
        '<div class="kc-lane kc-lane-bottom" data-lane="bottom"></div>' +
        '<div class="kc-player" data-player>' + buildPlayerSvg() + '</div>' +
      '</div>' +
      '<div class="kc-hud">' +
        '<div class="kc-score-block"><span class="kc-score-num" data-score>0</span><span class="kc-score-label">Score</span></div>' +
        '<div class="kc-controls" aria-hidden="true">' +
          '<kbd>&uarr;</kbd>/<kbd>W</kbd> &middot; <kbd>&darr;</kbd>/<kbd>S</kbd>' +
        '</div>' +
        '<button type="button" class="kc-close" data-close aria-label="Stop spel">&times;</button>' +
      '</div>';
    kade.appendChild(stage);

    const trackEl = stage.querySelector('[data-track]');
    const playerEl = stage.querySelector('[data-player]');
    const scoreSpan = stage.querySelector('[data-score]');
    const closeBtn = stage.querySelector('[data-close]');

    /* Position the player centred on the blue-house x. CSS has the
       player at left: var(--kc-player-x). */
    stage.style.setProperty('--kc-player-x', (playerCenterX - 11) + 'px');

    let lane = 'bottom';
    playerEl.dataset.lane = lane;

    let score = 0;
    let speedMul = 1;
    let spawnTimer = null;
    let rafId = null;
    let over = false;
    const hazards = [];
    const startTime = performance.now();

    function setLane(next) {
      if (over) return;
      if (lane === next) return;
      lane = next;
      playerEl.dataset.lane = next;
    }

    function spawnHazard() {
      if (over) return;
      /* Pick a kind from the currently-unlocked tier — heavier
         vehicles are still possible at low scores, but only the
         starter set. */
      const kinds = currentKinds(score);
      const kind = kinds[(Math.random() * kinds.length) | 0];
      const hazardLane = Math.random() < 0.5 ? 'top' : 'bottom';
      const hazard = document.createElement('div');
      hazard.className = 'kc-hazard';
      hazard.dataset.lane = hazardLane;
      hazard.dataset.kind = kind;
      hazard.innerHTML = buildHazardSvg(kind);
      const dur = clamp(HAZARD_BASE_MS / speedMul, HAZARD_FLOOR_MS, HAZARD_BASE_MS);
      hazard.style.animationDuration = dur + 'ms';
      trackEl.appendChild(hazard);
      const entry = {
        el: hazard, lane: hazardLane, kind: kind,
        startedAt: performance.now(), durationMs: dur, scored: false,
      };
      hazards.push(entry);
      hazard.addEventListener('animationend', function () {
        if (!entry.scored && !over) {
          entry.scored = true;
          score++;
          scoreSpan.textContent = String(score);
          /* Smooth ramp: per dodge bump speed and spawn rate. The
             clamp in spawn / drift duration formulas caps it. */
          speedMul = clamp(speedMul + 0.05, 1, 2.6);
        }
        hazard.remove();
        const idx = hazards.indexOf(entry);
        if (idx >= 0) hazards.splice(idx, 1);
      });
    }

    function scheduleSpawn() {
      if (over) return;
      const interval = clamp(SPAWN_INITIAL_MS / speedMul, SPAWN_FLOOR_MS, SPAWN_INITIAL_MS);
      spawnTimer = setTimeout(function () {
        spawnHazard();
        scheduleSpawn();
      }, interval);
    }

    function checkCollisions() {
      if (over) return;
      const trackRect = trackEl.getBoundingClientRect();
      const playerRect = playerEl.getBoundingClientRect();
      const playerCx = playerRect.left + playerRect.width / 2 - trackRect.left;
      for (let i = 0; i < hazards.length; i++) {
        const h = hazards[i];
        if (h.scored) continue;
        if (h.lane !== lane) continue;
        const hr = h.el.getBoundingClientRect();
        const hazardCx = hr.left + hr.width / 2 - trackRect.left;
        /* Use the hazard's actual width as the X tolerance so a tram
           is harder to dodge late than a bike — generous gameplay
           while still rewarding swap timing. */
        const tol = Math.max(PLAYER_COLLISION_X_TOL, hr.width / 2 + 6);
        if (Math.abs(hazardCx - playerCx) < tol) {
          gameOver();
          return;
        }
      }
      rafId = requestAnimationFrame(checkCollisions);
    }

    function gameOver() {
      if (over) return;
      over = true;
      if (spawnTimer) { clearTimeout(spawnTimer); spawnTimer = null; }
      if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
      hazards.forEach(function (h) { h.el.style.animationPlayState = 'paused'; });
      playerEl.classList.add('crashed');
      const elapsedSec = Math.round((performance.now() - startTime) / 1000);
      /* Fire on window so the GameModal picks it up. The modal handles
         the win/loss copy + replay button. */
      window.dispatchEvent(new CustomEvent('connext:gameend', {
        detail: {
          id: 'kade-cyclist',
          won: false,
          score: score,
          summary: score + ' dodges · ' + elapsedSec + 's',
          title: 'Bots.',
          subtitle: 'You crashed into a vehicle on the kade. Try again — start slow, watch the lane.',
        }
      }));
    }

    function tearDown() {
      tearDownActive(root, kade);
    }

    function onKey(e) {
      if (e.key === 'Escape') { tearDown(); return; }
      if (over) return;
      if (e.key === 'ArrowUp' || e.key === 'w' || e.key === 'W') { setLane('top'); e.preventDefault(); }
      else if (e.key === 'ArrowDown' || e.key === 's' || e.key === 'S') { setLane('bottom'); e.preventDefault(); }
    }
    document.addEventListener('keydown', onKey);
    closeBtn.addEventListener('click', tearDown);

    ACTIVE.set(root, {
      ambientHTML: ambientHTML,
      stage: stage,
      onKey: onKey,
      cleanup: function () {
        if (spawnTimer) clearTimeout(spawnTimer);
        if (rafId) cancelAnimationFrame(rafId);
        over = true;
      },
    });

    if (reduceMotion) speedMul = 1.4;

    /* Start the round. */
    scheduleSpawn();
    rafId = requestAnimationFrame(checkCollisions);
  }

  function tearDownActive(root, kade) {
    const state = ACTIVE.get(root);
    if (!state) return;
    if (state.cleanup) state.cleanup();
    if (state.onKey) document.removeEventListener('keydown', state.onKey);
    if (state.stage && state.stage.parentNode) state.stage.parentNode.removeChild(state.stage);
    /* Restore the ambient kade items. */
    const kadeItems = kade.querySelector('.kade-items');
    if (kadeItems && state.ambientHTML) kadeItems.innerHTML = state.ambientHTML;
    delete root.dataset.kadeActive;
    ACTIVE.delete(root);
  }

  window.KadeCyclist = window.KadeCyclist || {};
  window.KadeCyclist.hydrate = hydrate;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hydrate);
  } else {
    hydrate();
  }
})();
