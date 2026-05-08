/* Logo Memory: in-place transformation of the Clients hex marquee.
   No overlay, no clones-with-new-DOM-tiles — the actual <a class="hex">
   anchors that are already on screen become the game tiles.

   Phases on click:
     1. Freeze the sideways scroll, restore color (drop the grayscale).
     2. Lift every visible hex out of its track into absolute positioning
        anchored to its current snapshot coords. Pick the 4 hexes nearest
        the horizontal centre per row as keepers (12 total). Animate the
        rest off-screen left or right with stagger + fade.
     3. Reposition the 12 keepers into a clean centred 4×3 honeycomb
        cluster.
     4. Clone each keeper, slide the clone down 3 rows so we have a
        4×6 = 24-tile cluster.
     5. Blink-shuffle: 12 random client logos picked, each duplicated
        into pair assignments, each tile fades out, swaps to its
        assigned logo, fades back in. Stagger across all 24.
     6. Flip every tile to a cobalt back face with the Conduction C-in-
        hex avatar. Stagger.

   From there it's classic memory: click two, match holds at orange-glow
   ring, mismatch flips back. Win fires `connext:gameend` on window so
   the GameModal picks it up.

   The tear-down restores the original marquee innerHTML so the scroll
   animation resumes cleanly.
*/
(function () {
  const PAIRS = 12;
  const KEEP_PER_ROW = 4;
  const FLIP_MS = 600;
  const MISMATCH_MS = 900;
  const STAGGER_MS = 35;
  const BLINK_OUT_MS = 180;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const CONDUCTION_BACK_SVG =
    '<svg class="lm-back-svg" viewBox="-86.6 -100 173.2 200" aria-hidden="true">' +
      '<polygon fill="#21468B" points="0,-100 86.6,-50 86.6,50 0,100 -86.6,50 -86.6,-50"/>' +
      '<polygon fill="#FFFFFF" points="0,-74.5 64.5,-37.3 64.5,37.3 0,74.5 -64.5,37.3 -64.5,-37.3"/>' +
      '<polygon fill="#21468B" points="-0.2,-25.2 20.1,-13.5 43.7,-27.1 -0.2,-52.4 -45.6,-26.2 -45.6,26.2 -0.2,52.4 43.7,27.1 20.1,13.5 -0.2,25.2 -22,12.6 -22,-12.6"/>' +
    '</svg>';

  function shuffle(arr) {
    const a = arr.slice();
    for (let i = a.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      const t = a[i]; a[i] = a[j]; a[j] = t;
    }
    return a;
  }

  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function collectLogos(marquee) {
    const seen = new Map();
    marquee.querySelectorAll('a').forEach(function (a) {
      const img = a.querySelector('img');
      if (!img) return;
      const src = img.getAttribute('src');
      if (!src || seen.has(src)) return;
      const name = a.getAttribute('aria-label') || img.getAttribute('alt') || '';
      seen.set(src, name);
    });
    return Array.from(seen, function (e) { return { src: e[0], name: e[1] }; });
  }

  function hydrateContainer(container) {
    if (container.dataset.memoryHydrated === '1') return;
    container.dataset.memoryHydrated = '1';

    const marquee = container.querySelector('[data-memory-marquee]') ||
                    container.querySelector('.marquee');
    if (!marquee) return;

    /* On replay (modal "Play again" button) we want a fresh round. The
       runtime listens for connext:gamereplay and re-arms the marquee. */
    window.addEventListener('connext:gamereplay', function (e) {
      if (e.detail && e.detail.id === 'logo-memory') {
        if (container.dataset.memoryActive === '1') {
          tearDownActive(container, marquee);
        }
      }
    });
    /* On Close — restore the marquee. The cluster is no longer needed
       once the user is done with the round. */
    window.addEventListener('connext:gameclose', function (e) {
      if (e.detail && e.detail.id === 'logo-memory') {
        if (container.dataset.memoryActive === '1') {
          tearDownActive(container, marquee);
        }
      }
    });

    marquee.addEventListener('click', function (e) {
      if (container.dataset.memoryActive === '1') return;
      const a = e.target.closest('a');
      if (!a || !marquee.contains(a)) return;
      e.preventDefault();
      const logos = collectLogos(marquee);
      if (logos.length < PAIRS) return;
      startGame(container, marquee, logos);
    });
  }

  /* Stash for active-game state so tearDown can clean up keyboard
     listeners and revert the marquee. */
  const ACTIVE = new WeakMap();

  function tearDownActive(container, marquee) {
    const state = ACTIVE.get(container);
    if (!state) return;
    document.removeEventListener('keydown', state.onKey);
    /* The marquee is now driven by clients-flow.js which manages its
       own hex pool. Calling reset() removes every existing hex (the
       ones the game lifted out of rows AND any leftovers) and re-fills
       the rows from scratch. Falls back to an innerHTML restore for
       the transitional case where the flow runtime hasn't loaded. */
    if (window.ConductionClientsFlow && typeof window.ConductionClientsFlow.reset === 'function') {
      /* Strip everything we appended during the game (lifted hex
         anchors, the HUD pill, the win panel, etc) — anything that
         isn't a row container. The runtime's reset() will repopulate
         the rows from scratch. */
      Array.from(marquee.children).forEach(function (child) {
        if (!child.hasAttribute('data-flow-row')) child.remove();
      });
      window.ConductionClientsFlow.reset();
    } else if (state.originalHTML) {
      marquee.innerHTML = state.originalHTML;
    }
    marquee.classList.remove('lm-active', 'lm-overflow');
    delete container.dataset.memoryActive;
    ACTIVE.delete(container);
  }

  async function startGame(container, marquee, logos) {
    container.dataset.memoryActive = '1';
    const originalHTML = marquee.innerHTML;

    /* Freeze the continuous-spawn runtime so hexes stop drifting and
       stay in their snapshot positions for phase 2 to lift cleanly. */
    if (window.ConductionClientsFlow && typeof window.ConductionClientsFlow.pause === 'function') {
      window.ConductionClientsFlow.pause();
    }

    /* === Phase 1: Freeze + colorize === */
    marquee.classList.add('lm-active');
    /* Briefly let the CSS color transition + animation pause take effect
       so the snapshot rects are taken from a still frame. */
    await wait(reduceMotion ? 30 : 280);

    /* === Phase 2: Snapshot, lift to absolute, pick keepers === */
    /* The row class is hashed by CSS modules in the React build (e.g.
       row_cD73), so .row doesn't match there. Match any descendant
       whose class string contains "row" — works for both the literal
       "row row1" of the preview HTML and the CSS-modules-hashed
       "row_cD73 row2_w4Mq" of the React build. */
    const rows = Array.from(marquee.querySelectorAll('[class*="row"]'));
    const marqueeRect = marquee.getBoundingClientRect();

    const rowsHexes = rows.map(function (row, rowIdx) {
      const hexes = Array.from(row.querySelectorAll('a'));
      hexes.forEach(function (hex) {
        const r = hex.getBoundingClientRect();
        hex._lmX = r.left - marqueeRect.left;
        hex._lmY = r.top - marqueeRect.top;
        hex._lmW = r.width;
        hex._lmH = r.height;
        hex._lmRowIdx = rowIdx;
      });
      /* Don't filter by viewport bounds — we always need 4 keepers per
         row, and a hex slightly off-screen will get repositioned
         centred anyway. Just exclude zero-size hexes (in case any are
         display:none for some other reason). The blink-shuffle re-
         assigns logos so it doesn't matter which DOM duplicate gets
         picked. */
      return hexes.filter(function (h) { return h._lmW > 0; });
    });

    const stageWidth = marqueeRect.width;
    const stageCenterX = stageWidth / 2;

    /* Pick the keepers per row, but guarantee a total of PAIRS keepers
       so we always end up with 24 tiles. If a row's first 4 picks
       overlap with another row's (rare in practice), we fall back to
       picking the next-nearest hex from the densest row. */
    const allKept = [];
    const allDropping = [];

    rowsHexes.forEach(function (rowHexes) {
      const sorted = rowHexes.slice().sort(function (a, b) {
        const da = Math.abs((a._lmX + a._lmW / 2) - stageCenterX);
        const db = Math.abs((b._lmX + b._lmW / 2) - stageCenterX);
        return da - db;
      });
      const pickCount = Math.min(KEEP_PER_ROW, sorted.length);
      for (let k = 0; k < pickCount; k++) {
        sorted[k]._lmKept = true;
        allKept.push(sorted[k]);
      }
      for (let k = pickCount; k < sorted.length; k++) {
        sorted[k]._lmKept = false;
        allDropping.push(sorted[k]);
      }
    });
    /* Top up keepers from droppers' nearest-to-centre if any row was
       short. Pulls from the front of allDropping (which is row-sorted),
       preferring the nearest-to-centre droppers. */
    while (allKept.length < PAIRS && allDropping.length > 0) {
      const sortedDrops = allDropping.slice().sort(function (a, b) {
        const da = Math.abs((a._lmX + a._lmW / 2) - stageCenterX);
        const db = Math.abs((b._lmX + b._lmW / 2) - stageCenterX);
        return da - db;
      });
      const promote = sortedDrops[0];
      promote._lmKept = true;
      allKept.push(promote);
      const idx = allDropping.indexOf(promote);
      if (idx >= 0) allDropping.splice(idx, 1);
    }

    /* Lift every visible hex to position:absolute at its snapshot coords
       so it's no longer affected by track flex layout. The flow runtime
       drives drift via inline transform: translateX(...) — clear that
       so the game's own left/top take over cleanly. Otherwise the hex
       would render at left + transform, leaking the pre-pause drift. */
    const allLifted = allKept.concat(allDropping);
    allLifted.forEach(function (hex) {
      hex.style.transform = '';
      hex.style.position = 'absolute';
      hex.style.left = hex._lmX + 'px';
      hex.style.top = hex._lmY + 'px';
      hex.style.width = hex._lmW + 'px';
      hex.style.height = hex._lmH + 'px';
      hex.style.margin = '0';
      hex.style.transition = 'transform 700ms cubic-bezier(0.65, 0, 0.35, 1), opacity 600ms ease, left 700ms cubic-bezier(0.65, 0, 0.35, 1), top 700ms cubic-bezier(0.65, 0, 0.35, 1)';
      hex.style.zIndex = '5';
      marquee.appendChild(hex);
    });
    /* Hide the leftover (aria-hidden duplicates and any non-visible
       originals) hexes so the rows collapse without ghost slots. */
    marquee.querySelectorAll('a').forEach(function (h) {
      if (allLifted.indexOf(h) === -1) h.style.display = 'none';
    });
    /* Collapse rows so there's no empty band where the tracks used to
       sit. Marquee gets its own min-height set below for the cluster.
       Reuse the rows array we captured at snapshot — `marquee.children`
       now includes the lifted anchors as well so re-querying is risky. */
    rows.forEach(function (r) {
      r.style.height = '0';
      r.style.margin = '0';
      r.style.overflow = 'visible';
    });
    /* Allow the cluster to expand below the marquee's original 3-row
       height — overflow:visible during play. */
    marquee.classList.add('lm-overflow');

    /* === Phase 3: Drop edges off-screen === */
    allDropping.forEach(function (hex, i) {
      const cx = hex._lmX + hex._lmW / 2;
      const goLeft = cx < stageCenterX;
      const tx = goLeft ? -(stageWidth / 2 + 120) : (stageWidth / 2 + 120);
      setTimeout(function () {
        hex.style.transform = 'translateX(' + tx + 'px) rotate(' + (goLeft ? -8 : 8) + 'deg)';
        hex.style.opacity = '0';
      }, i * 22);
    });
    await wait(reduceMotion ? 30 : 700 + allDropping.length * 22);
    /* Drop fully done — remove dropped tiles. */
    allDropping.forEach(function (h) { h.remove(); });

    /* Track the originalHTML in ACTIVE before any phase that might
       mutate the marquee, so the early-abort path can fall back to
       tearDownActive without needing setupGame to have run. */
    ACTIVE.set(container, { onKey: function () {}, originalHTML: originalHTML });

    /* === Phase 4: Reposition keepers into clean centred 4×3 grid === */
    if (allKept.length === 0) {
      tearDownActive(container, marquee);
      return;
    }
    const hexW = allKept[0]._lmW;
    const hexH = allKept[0]._lmH;
    /* Read the actual --gap from the marquee so the cluster math
       matches whatever the CSS sets (8px desktop, 6px mobile, etc).
       Fallback to 8 if the variable isn't resolvable. */
    const cssGap = parseFloat(getComputedStyle(marquee).getPropertyValue('--gap'));
    const gap = isFinite(cssGap) && cssGap >= 0 ? cssGap : 8;
    const cellW = hexW + gap;
    /* Isotropic row spacing: vertical pitch matches the horizontal
       pitch on the diagonal axis, same formula as the marquee CSS. */
    const rowSpacingY = (hexW + gap) * 0.866;

    /* Group keepers by their original row to preserve the 3-row visual,
       then space them evenly within each row centred on stageCenterX. */
    const keptByRow = {};
    allKept.forEach(function (h) {
      const r = h._lmRowIdx;
      (keptByRow[r] = keptByRow[r] || []).push(h);
    });
    const sortedRowIds = Object.keys(keptByRow).map(Number).sort(function (a, b) { return a - b; });
    /* Top y of the cluster — start where the topmost keeper currently
       sits so the relayout doesn't jump vertically. */
    const minTopY = Math.min.apply(null, allKept.map(function (h) { return h._lmY; }));
    sortedRowIds.forEach(function (rIdx, i) {
      const rowHexes = keptByRow[rIdx].slice().sort(function (a, b) { return a._lmX - b._lmX; });
      const stagger = (i % 2 === 1) ? cellW / 2 : 0;
      const totalRowWidth = (rowHexes.length - 1) * cellW + hexW;
      const startX = stageCenterX - totalRowWidth / 2 + (stagger - cellW / 4);
      const newY = minTopY + i * rowSpacingY;
      rowHexes.forEach(function (hex, j) {
        const newX = startX + j * cellW;
        hex.style.left = newX + 'px';
        hex.style.top = newY + 'px';
        hex._lmFinalX = newX;
        hex._lmFinalY = newY;
      });
    });

    await wait(reduceMotion ? 30 : 720);

    /* === Phase 5: Duplicate keepers downward === */
    const downOffset = 3 * rowSpacingY;
    const halfCell = cellW / 2;
    /* Each clone needs its lateral position shifted so the bottom 3
       rows continue the honeycomb stagger pattern. The top half has
       stagger [0, ½, 0] for layout rows 0/1/2; the bottom half is
       rows 3/4/5 which have stagger [½, 0, ½]. The shift is the
       difference. Pre-compute per clone for use during both the
       slide-in animation and the final position lock. */
    function lateralShiftFor(orig) {
      const origLayoutRow = sortedRowIds.indexOf(orig._lmRowIdx);
      const origStaggered = (origLayoutRow % 2 === 1);
      const cloneStaggered = ((origLayoutRow + 3) % 2 === 1);
      return (cloneStaggered ? halfCell : 0) - (origStaggered ? halfCell : 0);
    }
    const clones = allKept.map(function (orig) {
      const clone = orig.cloneNode(true);
      clone.classList.add('lm-clone');
      clone.style.position = 'absolute';
      clone.style.left = orig._lmFinalX + 'px';
      clone.style.top = orig._lmFinalY + 'px';
      clone.style.width = orig._lmW + 'px';
      clone.style.height = orig._lmH + 'px';
      clone.style.margin = '0';
      clone.style.transition = orig.style.transition;
      clone.style.opacity = '0';
      clone.style.transform = 'scale(0.6)';
      clone.style.zIndex = '5';
      marquee.appendChild(clone);
      return clone;
    });
    /* One frame for the clones to be in DOM before the transition. */
    await wait(50);
    clones.forEach(function (clone, i) {
      const orig = allKept[i];
      const dx = lateralShiftFor(orig);
      setTimeout(function () {
        clone.style.opacity = '1';
        clone.style.transform = 'translate(' + dx + 'px, ' + downOffset + 'px) scale(1)';
      }, i * 30);
    });
    await wait(reduceMotion ? 30 : 700 + clones.length * 30);
    /* After slide-down, lock clones at their final left/top and clear
       transform so flip rotateY is purely Y-rotation. */
    clones.forEach(function (clone, i) {
      const orig = allKept[i];
      const dx = lateralShiftFor(orig);
      clone.style.transition = 'opacity 200ms ease';
      clone.style.transform = '';
      clone.style.left = (orig._lmFinalX + dx) + 'px';
      clone.style.top = (orig._lmFinalY + downOffset) + 'px';
      clone._lmFinalX = orig._lmFinalX + dx;
      clone._lmFinalY = orig._lmFinalY + downOffset;
    });

    /* === Phase 6: Blink-shuffle the logos === */
    const allTiles = allKept.concat(clones);
    const picked = shuffle(logos).slice(0, PAIRS);
    const pairAssignments = shuffle(picked.flatMap(function (l, i) {
      return [
        { src: l.src, name: l.name, pairId: i },
        { src: l.src, name: l.name, pairId: i },
      ];
    }));
    /* Every tile fades, swaps img src to its assigned logo, fades back. */
    const shuffleStagger = reduceMotion ? 0 : STAGGER_MS;
    allTiles.forEach(function (tile, i) {
      setTimeout(function () {
        tile.style.opacity = '0';
        setTimeout(function () {
          const a = pairAssignments[i];
          const img = tile.querySelector('img');
          if (img) {
            img.src = a.src;
            img.alt = a.name;
          }
          tile.dataset.lmPair = String(a.pairId);
          tile.setAttribute('aria-label', 'Memory tile: ' + a.name);
          tile.style.opacity = '1';
        }, BLINK_OUT_MS);
      }, i * shuffleStagger);
    });
    await wait(reduceMotion ? 30 : allTiles.length * shuffleStagger + BLINK_OUT_MS + 240);

    /* === Phase 7: Flip every tile to the cobalt back face === */
    allTiles.forEach(function (tile) { enhanceTileForFlip(tile); });
    /* Force reflow before flip transition so the new transform-style
       takes effect. */
    void marquee.offsetHeight;
    const flipStagger = reduceMotion ? 0 : 30;
    allTiles.forEach(function (tile, i) {
      setTimeout(function () { tile.classList.add('lm-back-up'); }, i * flipStagger);
    });
    await wait(reduceMotion ? 30 : allTiles.length * flipStagger + 600);

    /* === Phase 8: Game ready === */
    setupGame(container, marquee, allTiles, originalHTML);
  }

  function enhanceTileForFlip(tile) {
    if (tile.classList.contains('lm-tile')) return;
    tile.classList.add('lm-tile');
    const img = tile.querySelector('img');
    const flipper = document.createElement('div');
    flipper.className = 'lm-flipper';
    const front = document.createElement('div');
    front.className = 'lm-face lm-front';
    if (img) front.appendChild(img);
    const back = document.createElement('div');
    back.className = 'lm-face lm-back';
    back.innerHTML = CONDUCTION_BACK_SVG;
    flipper.appendChild(front);
    flipper.appendChild(back);
    tile.appendChild(flipper);
  }

  function setupGame(container, marquee, tiles, originalHTML) {
    /* HUD pill positioned at the top of the marquee (over the cluster). */
    const hud = document.createElement('div');
    hud.className = 'lm-hud';
    hud.innerHTML =
      '<div class="lm-counter"><span data-matched>0</span> / ' + PAIRS + '</div>' +
      '<div class="lm-moves"><span data-moves>0</span> zetten</div>' +
      '<button class="lm-close" type="button" aria-label="Sluit spel">&times;</button>';
    marquee.appendChild(hud);

    const matchedSpan = hud.querySelector('[data-matched]');
    const movesSpan = hud.querySelector('[data-moves]');
    const closeBtn = hud.querySelector('.lm-close');

    let firstTile = null;
    let busy = false;
    let moves = 0;
    let matched = 0;
    let phase = 'play';
    const startTime = performance.now();

    function onTileClick(tile) {
      if (busy || phase !== 'play') return;
      if (tile.classList.contains('lm-matched')) return;
      if (!tile.classList.contains('lm-back-up')) return;
      tile.classList.remove('lm-back-up');
      if (!firstTile) {
        firstTile = tile;
        return;
      }
      const second = tile;
      moves++;
      movesSpan.textContent = String(moves);
      const t1 = firstTile;
      firstTile = null;
      if (t1.dataset.lmPair === second.dataset.lmPair) {
        busy = true;
        setTimeout(function () {
          t1.classList.add('lm-matched');
          second.classList.add('lm-matched');
          matched++;
          matchedSpan.textContent = String(matched);
          busy = false;
          if (matched === PAIRS) onWin();
        }, FLIP_MS);
      } else {
        busy = true;
        setTimeout(function () {
          t1.classList.add('lm-back-up');
          second.classList.add('lm-back-up');
          busy = false;
        }, MISMATCH_MS);
      }
    }

    tiles.forEach(function (tile) {
      tile.addEventListener('click', function (e) {
        e.preventDefault();
        onTileClick(tile);
      });
    });

    function onWin() {
      phase = 'won';
      const elapsedSec = Math.round((performance.now() - startTime) / 1000);
      window.dispatchEvent(new CustomEvent('connext:gameend', {
        detail: {
          id: 'logo-memory',
          won: true,
          score: matched,
          summary: matched + ' pairs · ' + moves + ' zetten · ' + elapsedSec + 's',
          title: 'Klaar.',
          subtitle: 'You matched all 12 pairs across the clients marquee.',
        }
      }));
    }

    function tearDown() {
      tearDownActive(container, marquee);
    }

    function onKey(e) {
      if (e.key === 'Escape') tearDown();
    }
    document.addEventListener('keydown', onKey);
    closeBtn.addEventListener('click', tearDown);

    ACTIVE.set(container, { onKey: onKey, originalHTML: originalHTML });
  }

  function hydrate() {
    document.querySelectorAll('[data-logo-memory]').forEach(hydrateContainer);
  }

  window.LogoMemory = window.LogoMemory || {};
  window.LogoMemory.hydrate = hydrate;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hydrate);
  } else {
    hydrate();
  }
})();
