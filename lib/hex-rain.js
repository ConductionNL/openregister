  /* Hex-rain hydrator + "Twelve apps" mini-game. The rain itself is a
     decorative continuous spawn loop; first click on a hex starts the
     game (60s timer, score = unique apps collected). Clicking a hex of
     an already-collected app DESELECTS that app (the twist), so
     misclicks during the late-game chaos punish you. Win = 12 / 12.
     Game ends fire `connext:gameend` so the gaming-modal component
     can update its cookie + reveal the cross-site progress panel. */
  (function () {
    const ICONS = [
      /* 0 Catalogi */  '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 12l9 4 9-4"/><path d="M3 17l9 4 9-4"/>',
      /* 1 Register */  '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 4v16"/>',
      /* 2 Connector */ '<circle cx="6" cy="12" r="3"/><circle cx="18" cy="6" r="3"/><circle cx="18" cy="18" r="3"/><path d="M9 12h9M9 12l9-6M9 12l9 6"/>',
      /* 3 DocuDesk */  '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
      /* 4 MyDash */    '<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/>',
      /* 5 AI Bridge */ '<path d="M12 2a4 4 0 0 0-4 4v3"/><rect x="6" y="9" width="12" height="13" rx="2"/>',
      /* 6 Pipeline */  '<path d="M4 7h6l4 5h6"/><path d="M4 17h6l4-5"/>',
      /* 7 Calendar */  '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
      /* 8 Lock */      '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
      /* 9 Mail */      '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
      /* 10 Cloud */    '<path d="M7 18a4 4 0 0 1 0-8 5 5 0 0 1 9.6-2A4 4 0 0 1 17 18z"/>',
      /* 11 People */   '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><circle cx="17" cy="9" r="2.5"/><path d="M14 20a4 4 0 0 1 7 0"/>',
    ];
    const APP_NAMES = [
      'OpenCatalogi', 'OpenRegister', 'OpenConnector', 'DocuDesk',
      'MyDash', 'AI Bridge', 'PipelinQ', 'OpenCalendar',
      'OpenSAML', 'OpenMail', 'NextCloud', 'OpenZaak',
    ];
    const APP_COUNT = ICONS.length;
    const GAME_SECONDS = 60;

    const rand = (a, b) => Math.random() * (b - a) + a;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* Wrap the per-container init in a named function so we can expose
       it as window.HexRain.hydrate() for SPA route changes that mount
       fresh .hex-rain nodes. The dataset.rainHydrated guard makes
       repeated calls a no-op for already-mounted containers. */
    function hydrateContainer(container) {
      if (container.dataset.rainHydrated === '1') return;
      container.dataset.rainHydrated = '1';

      // ===== HUD =====
      const hud = document.createElement('div');
      hud.className = 'rain-hud';
      hud.innerHTML =
        '<div class="hud-top">' +
          '<div class="hud-block hud-counter"><span class="hud-num" data-counter>0</span><span class="hud-label">Apps / 12</span></div>' +
          '<div class="hud-block hud-timer"><span class="hud-num" data-timer>' + GAME_SECONDS + '</span><span class="hud-label">Seconds</span></div>' +
        '</div>' +
        '<div class="hud-icons" data-icons></div>';
      container.appendChild(hud);
      const hudCounter = hud.querySelector('[data-counter]');
      const hudTimer = hud.querySelector('[data-timer]');
      const hudCounterBlock = hud.querySelector('.hud-counter');
      const hudTimerBlock = hud.querySelector('.hud-timer');
      const hudIcons = hud.querySelector('[data-icons]');
      for (let i = 0; i < APP_COUNT; i++) {
        const slot = document.createElement('span');
        slot.className = 'hud-icon';
        slot.dataset.app = String(i);
        slot.title = APP_NAMES[i];
        slot.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">' + ICONS[i] + '</svg>';
        hudIcons.appendChild(slot);
      }

      // ===== Game state =====
      function newGame() {
        return { started: false, over: false, timeLeft: GAME_SECONDS, collected: new Set() };
      }
      let game = newGame();
      let timerInt = null;
      let spawnTimer = null;

      function updateHud() {
        hudCounter.textContent = String(game.collected.size);
        hudTimer.textContent = String(Math.max(0, game.timeLeft));
        if (game.timeLeft <= 5 && game.started) hudTimerBlock.classList.add('urgent');
        else hudTimerBlock.classList.remove('urgent');
      }

      function flashCounter(direction) {
        hudCounterBlock.classList.remove('hud-tick', 'hud-untick');
        void hudCounterBlock.offsetWidth;
        hudCounterBlock.classList.add(direction === 'up' ? 'hud-tick' : 'hud-untick');
      }

      function startGame() {
        if (game.started || game.over) return;
        game.started = true;
        hud.classList.add('active');
        updateHud();
        timerInt = setInterval(() => {
          if (game.over) return;
          game.timeLeft -= 1;
          updateHud();
          if (game.timeLeft <= 0) endGame(false);
        }, 1000);
      }

      function endGame(won) {
        if (game.over) return;
        game.over = true;
        if (timerInt) { clearInterval(timerInt); timerInt = null; }
        hud.classList.remove('active');
        const elapsed = GAME_SECONDS - game.timeLeft;
        const summary = won
          ? '12 / 12 in ' + elapsed + 's'
          : game.collected.size + ' / 12 collected';
        window.dispatchEvent(new CustomEvent('connext:gameend', {
          detail: { id: 'hexrain', won, score: game.collected.size, summary },
        }));
      }

      function resetGame() {
        if (timerInt) { clearInterval(timerInt); timerInt = null; }
        game = newGame();
        hud.classList.remove('active');
        hudTimerBlock.classList.remove('urgent');
        hudIcons.querySelectorAll('.hud-icon').forEach((el) => el.classList.remove('collected', 'just'));
        // Clean any in-flight clicked hexes from a previous round
        container.querySelectorAll('.h.clicking').forEach((el) => el.remove());
        updateHud();
      }

      window.addEventListener('connext:gamereplay', (e) => {
        if (e.detail && e.detail.id === 'hexrain') resetGame();
      });

      // ===== Spawn loop. Idle (pre-game): slow constant rain.
      // Once the game starts, spawn rate + fall speed ramp with progress. =====
      function progress() {
        return game.started ? (GAME_SECONDS - game.timeLeft) / GAME_SECONDS : 0;
      }
      function getSpawnInterval() {
        if (!game.started) return rand(900, 1400);
        return Math.max(280, 1200 - progress() * 920);
      }
      function getFallDuration() {
        // Idle: 38-55s. Game peak: 14-22s.
        const base = 50 - progress() * 30;
        return Math.max(12, base + rand(-4, 4));
      }
      function pickAppId() {
        // Bias spawns toward apps the player hasn't collected yet so
        // they get a fair shot at finding all twelve in 60s.
        const uncollected = [];
        for (let i = 0; i < APP_COUNT; i++) if (!game.collected.has(i)) uncollected.push(i);
        if (uncollected.length && Math.random() < 0.7) {
          return uncollected[Math.floor(Math.random() * uncollected.length)];
        }
        return Math.floor(Math.random() * APP_COUNT);
      }

      function spawnHex(opts) {
        opts = opts || {};
        const appId = opts.appId !== undefined ? opts.appId : pickAppId();
        const size = rand(54, 96);
        const x = rand(8, 92);
        const dur = getFallDuration();
        const delay = opts.delay || 0;
        const isAlt = Math.random() < 0.35;

        const hex = document.createElement('span');
        hex.className = 'h' + (isAlt ? ' alt' : '');
        hex.style.setProperty('--rain-x', x.toFixed(1) + '%');
        hex.style.setProperty('--rain-size', size.toFixed(1) + 'px');
        hex.style.setProperty('--rain-dur', dur.toFixed(1) + 's');
        if (delay) hex.style.setProperty('--rain-delay', delay.toFixed(1) + 's');
        hex.style.setProperty('--rain-end', (container.clientHeight + size + 32).toFixed(1) + 'px');
        hex.dataset.app = String(appId);
        hex.dataset.size = size.toFixed(1);
        hex.title = APP_NAMES[appId] + ', click to collect';
        hex.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">' + ICONS[appId] + '</svg>';

        if (reduceMotion) {
          // Static placement, rain doesn't move but hexes are still clickable.
          hex.style.top = rand(8, 88).toFixed(1) + '%';
          hex.style.transform = 'translate(-50%, -50%)';
          hex.style.opacity = '1';
        }

        // Once the fall keyframe completes, GC the element. (Click-out
        // animations dispatch their own `finished` cleanup below.)
        hex.addEventListener('animationend', (e) => {
          if (e.animationName === 'hex-rain-fall' && hex.parentNode) hex.remove();
        });

        container.appendChild(hex);
        return hex;
      }

      function scheduleNextSpawn() {
        spawnTimer = setTimeout(() => {
          spawnHex();
          scheduleNextSpawn();
        }, getSpawnInterval());
      }

      // Pre-populate so the rain looks alive immediately (negative delay
      // = hexes start mid-flight).
      const initialPool = reduceMotion ? 9 : 14;
      for (let i = 0; i < initialPool; i++) {
        const dur = rand(38, 60);
        spawnHex({ delay: -rand(0, dur) });
      }
      if (!reduceMotion) scheduleNextSpawn();

      // ===== Click handling, toggles the clicked hex's app on/off.
      // Uses event delegation so spawned hexes don't need re-wiring. =====
      container.addEventListener('click', (e) => {
        const hex = e.target.closest('.h');
        if (!hex || hex.classList.contains('clicking')) return;
        const appId = parseInt(hex.dataset.app, 10);
        if (Number.isNaN(appId)) return;

        if (game.over) return; // post-end clicks ignored; rain still drips
        if (!game.started) startGame();

        const wasCollected = game.collected.has(appId);
        const slot = hudIcons.children[appId];
        if (wasCollected) {
          game.collected.delete(appId);
          slot.classList.remove('collected', 'just');
          flashCounter('down');
        } else {
          game.collected.add(appId);
          slot.classList.add('collected');
          slot.classList.remove('just'); void slot.offsetWidth; slot.classList.add('just');
          flashCounter('up');
        }
        animateHexOut(hex, !wasCollected);
        updateHud();

        if (game.collected.size >= APP_COUNT) endGame(true);
      });

      // Freeze the falling hex at its current Y (read from computed
      // transform), then run a WAAPI scale + fade for collect (orange
      // pop) or deselect (red shake).
      function animateHexOut(hex, isCollect) {
        hex.classList.add('clicking');
        let y = 0;
        try {
          const m = new DOMMatrixReadOnly(getComputedStyle(hex).transform);
          y = m.m42 || 0;
        } catch (err) { /* keep y=0 */ }
        hex.style.animation = 'none';
        hex.style.transform = 'translate(-50%, ' + y + 'px)';
        void hex.offsetWidth;

        const dur = 380;
        const collectKeyframes = [
          { transform: 'translate(-50%, ' + y + 'px) scale(1)',   filter: 'none', opacity: 1 },
          { transform: 'translate(-50%, ' + y + 'px) scale(1.5)', filter: 'brightness(1.6) drop-shadow(0 0 14px rgba(255,107,0,0.95))', opacity: 1, offset: 0.5 },
          { transform: 'translate(-50%, ' + y + 'px) scale(1.8)', opacity: 0 },
        ];
        const deselectKeyframes = [
          { transform: 'translate(-50%, ' + y + 'px) scale(1)    rotate(0deg)',  filter: 'none' },
          { transform: 'translate(-50%, ' + y + 'px) scale(0.85) rotate(-9deg)', filter: 'hue-rotate(-100deg) brightness(1.5)', offset: 0.3 },
          { transform: 'translate(-50%, ' + y + 'px) scale(0.85) rotate(9deg)',  filter: 'hue-rotate(-100deg) brightness(1.5)', offset: 0.6 },
          { transform: 'translate(-50%, ' + y + 'px) scale(0.7)  rotate(0deg)',  opacity: 0 },
        ];
        const anim = hex.animate(
          isCollect ? collectKeyframes : deselectKeyframes,
          { duration: dur, easing: 'ease-out', fill: 'forwards' }
        );
        anim.finished.then(() => hex.remove()).catch(() => {});
      }
    }

    function init() {
      document.querySelectorAll('.hex-rain').forEach(hydrateContainer);
    }

    /* SPA-friendly: expose so React/MDX components can re-trigger after
       route changes without double-hydrating already-hydrated nodes. */
    window.HexRain = {hydrate: init};

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  })();
