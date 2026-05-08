/* Conduction background — parallax honeycomb hydrator.

   Walks every .conduction-bg in the document, injects depth-tiered hex
   spans, and wires them up to scroll-based parallax. Smaller hexes have
   smaller depth → less translate per scroll-pixel → read as further away.

   Per huisstijl: hexes are pointy-top only, never rotated.

   Hosts opt in by adding <div class="conduction-bg"></div> inside any
   position:relative + overflow:hidden container, plus this script tag
   and conduction-bg.css. The script auto-runs on DOMContentLoaded and
   is idempotent — calling init() twice on the same element is a no-op.

   Optional dataset hooks on .conduction-bg:
     data-count="N"  — override default hex count (default 18)
*/
(function () {
  const TIERS = [
    { d: 0.10, sMin: 32,  sMax: 56,  oMin: 0.03, oMax: 0.10, w: 5 },
    { d: 0.25, sMin: 56,  sMax: 96,  oMin: 0.04, oMax: 0.13, w: 4 },
    { d: 0.45, sMin: 96,  sMax: 150, oMin: 0.05, oMax: 0.16, w: 3 },
    { d: 0.65, sMin: 150, sMax: 220, oMin: 0.07, oMax: 0.18, w: 2 },
    { d: 0.85, sMin: 220, sMax: 300, oMin: 0.08, oMax: 0.20, w: 1 },
  ];
  const PARALLAX_FACTOR = 0.4;
  const TIER_TOTAL = TIERS.reduce((s, t) => s + t.w, 0);

  const rand = (a, b) => Math.random() * (b - a) + a;
  const pickTier = () => {
    let r = Math.random() * TIER_TOTAL;
    for (const t of TIERS) { if ((r -= t.w) <= 0) return t; }
    return TIERS[0];
  };

  function hydrate(bg) {
    if (bg.dataset.cbgHydrated === '1') return;
    bg.dataset.cbgHydrated = '1';

    const host = bg.parentElement;
    if (!host) return;
    const onLight = bg.classList.contains('on-light');
    /* Cobalt-on-white reads stronger per opacity-unit than white-on-cobalt,
       so scale back the on-light range to keep the field a soft veil. */
    const opaScale = onLight ? 0.55 : 1.0;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const hexCount = parseInt(bg.dataset.count, 10) || 18;
    const accentIndex = Math.floor(hexCount / 2);

    const hexes = [];
    for (let i = 0; i < hexCount; i++) {
      const tier = pickTier();
      const size = rand(tier.sMin, tier.sMax);
      const isAccent = (i === accentIndex);
      const hex = document.createElement('span');
      hex.className = isAccent ? 'hex accent' : 'hex';
      hex.style.width = size + 'px';
      hex.style.height = (size * 1.1547) + 'px';
      hex.style.left = rand(-5, 105) + '%';
      hex.style.top = rand(-25, 125) + '%';
      hex.style.setProperty('--cbg-opa-min', (tier.oMin * opaScale).toFixed(3));
      hex.style.setProperty(
        '--cbg-opa-max',
        ((isAccent ? Math.min(tier.oMax + 0.05, 0.28) : tier.oMax) * opaScale).toFixed(3)
      );
      hex.style.setProperty('--cbg-dur', rand(11, 18).toFixed(1) + 's');
      hex.style.setProperty('--cbg-delay', rand(-18, 0).toFixed(1) + 's');
      hex.dataset.depth = tier.d;
      bg.appendChild(hex);
      hexes.push(hex);
    }

    if (reduceMotion) return;

    let visible = false;
    let ticking = false;

    const update = () => {
      const rect = host.getBoundingClientRect();
      const offset = (rect.top + rect.height / 2) - window.innerHeight / 2;
      for (const h of hexes) {
        const d = parseFloat(h.dataset.depth);
        const ty = -offset * d * PARALLAX_FACTOR;
        h.style.transform = `translate(-50%, -50%) translateY(${ty.toFixed(1)}px)`;
      }
      ticking = false;
    };

    const onScroll = () => {
      if (!visible || ticking) return;
      ticking = true;
      requestAnimationFrame(update);
    };

    const io = new IntersectionObserver(([entry]) => {
      visible = entry.isIntersecting;
      if (visible) update();
    }, { rootMargin: '300px 0px' });
    io.observe(host);

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    update();
  }

  function init() {
    /* hydrate() has its own dataset.cbgHydrated guard so calling it
       repeatedly across SPA route changes is a no-op for already-
       mounted nodes. */
    document.querySelectorAll('.conduction-bg').forEach(hydrate);
  }

  /* SPA-friendly: expose so React/MDX components can re-trigger after
     route changes without double-hydrating already-hydrated nodes. */
  window.ConductionBg = {hydrate: init};

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
