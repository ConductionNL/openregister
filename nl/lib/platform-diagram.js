/*
 * <platform-diagram> — declarative workspace + corner-hexes + lists + flows.
 *
 * Authoring shape:
 *   <platform-diagram>
 *     <pd-workspace logo="..." role="Workspace" alt="Nextcloud"></pd-workspace>
 *     <pd-list position="top" family="nextcloud">
 *       <pd-item name="Files" desc="Sync, share, and version any file.">
 *         <svg viewBox="0 0 24 24"><path d="..."/></svg>
 *       </pd-item>
 *       ...
 *     </pd-list>
 *     <pd-list position="top-left" family="core" label="Technical Core">
 *       <pd-item name="OpenRegister" meta="Data" desc="..."> <svg .../></pd-item>
 *       ...
 *     </pd-list>
 *     <pd-list position="bottom-center" family="builder" label="App Builder" badge="COMING SOON">
 *       ...
 *     </pd-list>
 *     <pd-list position="bottom-right" family="integrate" label="Integrated Apps" columns="2">
 *       ...   <!-- columns≥2 splits items into N side-by-side columns -->
 *     </pd-list>
 *     <pd-flow from="top-left:left" to="bottom-left:left" shape="c-bracket-left"></pd-flow>
 *     <pd-flow from="bottom-center:left" to="bottom-left:bottom" shape="l-h"></pd-flow>
 *   </platform-diagram>
 *
 * Positions: top, top-left, top-right, bottom-left, bottom-right, bottom-center.
 *   "top" renders only a list (no hex tag) — used for the Nextcloud-workspace
 *   functions list. The other five render a hex tag plus the list.
 *
 * Flow shapes (orthogonal segments only):
 *   straight       direct line — use only when from/to share an axis.
 *   l-h            L-bend, horizontal first then vertical.
 *   l-v            L-bend, vertical first then horizontal.
 *   c-bracket-left out left, parallel, back in right (use when both anchors
 *                  are on the same vertical column, going around).
 *   c-bracket-right out right.
 *   c-bracket-top   out top.
 *   c-bracket-bottom out bottom.
 *
 * Anchor format on `from`/`to`: "<position>:<side>" where side is one of
 *   left | right | top | bottom (the four hex side midpoints / peaks).
 *
 * The whole component is light-DOM by design — it renders into its own
 * children, which means CSS scopes via `platform-diagram` selectors and the
 * structure ports directly to React/Docusaurus by capitalising tag names.
 */
(function () {
  const NS = 'http://www.w3.org/2000/svg';

  // Maps the public position name to the legacy class used by the CSS layout.
  // Keeping these stable means the diagram-overview page can opt to use the
  // legacy class names if it ever wants to bypass the component.
  const POSITION_CLASS = {
    'top':           'nextcloud-workspace',
    'top-left':      'tech-core',
    'top-right':     'solutions',
    'bottom-left':   'workspace',
    'bottom-right':  'integrated',
    'bottom-center': 'app-builder',
  };

  // Hex centres in workspace-relative coords (px).
  // Derived from the layout in platform-diagram.css — keep in sync if the
  // hex placement ever changes.
  const HEX_CENTRES = {
    'top-left':      { cx: -204, cy: -104 },
    'top-right':     { cx:  204, cy: -104 },
    'bottom-left':   { cx: -204, cy:  104 },
    'bottom-right':  { cx:  204, cy:  104 },
    'bottom-center': { cx:    0, cy:  232 },
  };

  // Hex side midpoints / peaks (160×185 pointy-top hex).
  const HEX_SIDES = {
    left:   { dx: -80,   dy:    0 },
    right:  { dx:  80,   dy:    0 },
    top:    { dx:   0,   dy: -92.5 },
    bottom: { dx:   0,   dy:  92.5 },
  };

  function anchor(spec) {
    if (!spec || !spec.includes(':')) return null;
    const [position, side] = spec.split(':');
    const c = HEX_CENTRES[position];
    const s = HEX_SIDES[side];
    if (!c || !s) {
      console.warn('[platform-diagram] unknown anchor:', spec);
      return null;
    }
    return { x: c.cx + s.dx, y: c.cy + s.dy };
  }

  function pathFor(shape, a, b, clearance) {
    /* `clearance` is how far past the further endpoint the bracket runs
       (in workspace-relative px). Defaults to 36 — enough to clear an
       adjacent hex's own outline. Bump it (e.g. 150) when routing around
       a hex that sits between the two anchors. */
    const c = (typeof clearance === 'number' && clearance > 0) ? clearance : 36;
    switch (shape) {
      case 'l-h':
      case 'l-bend':
      case 'l-bend-h':
        return `M ${a.x} ${a.y} L ${b.x} ${a.y} L ${b.x} ${b.y}`;
      case 'l-v':
      case 'l-bend-v':
        return `M ${a.x} ${a.y} L ${a.x} ${b.y} L ${b.x} ${b.y}`;
      case 'c-bracket-left': {
        const x = Math.min(a.x, b.x) - c;
        return `M ${a.x} ${a.y} L ${x} ${a.y} L ${x} ${b.y} L ${b.x} ${b.y}`;
      }
      case 'c-bracket-right': {
        const x = Math.max(a.x, b.x) + c;
        return `M ${a.x} ${a.y} L ${x} ${a.y} L ${x} ${b.y} L ${b.x} ${b.y}`;
      }
      case 'c-bracket-top': {
        const y = Math.min(a.y, b.y) - c;
        return `M ${a.x} ${a.y} L ${a.x} ${y} L ${b.x} ${y} L ${b.x} ${b.y}`;
      }
      case 'c-bracket-bottom': {
        const y = Math.max(a.y, b.y) + c;
        return `M ${a.x} ${a.y} L ${a.x} ${y} L ${b.x} ${y} L ${b.x} ${b.y}`;
      }
      case 'straight':
      default:
        return `M ${a.x} ${a.y} L ${b.x} ${b.y}`;
    }
  }

  // Parses a <pd-list> (or any source element) into a config object. Reads
  // its <pd-item> children eagerly so we can blow the source tree away after.
  function parseList(el) {
    const items = [];
    for (const itemEl of el.querySelectorAll(':scope > pd-item')) {
      // The item's icon is the first <svg> child. We snapshot a clone now so
      // the original is safe to remove when we replace the host's innerHTML.
      const svg = itemEl.querySelector(':scope > svg');
      items.push({
        name:       itemEl.getAttribute('name') || '',
        meta:       itemEl.getAttribute('meta') || '',
        desc:       itemEl.getAttribute('desc') || '',
        href:       itemEl.getAttribute('href') || '',
        brand:      itemEl.hasAttribute('brand') || itemEl.hasAttribute('brand-color'),
        brandColor: itemEl.getAttribute('brand-color') || '',
        glyph:      svg ? svg.cloneNode(true) : null,
      });
    }
    const cols = parseInt(el.getAttribute('columns') || '1', 10);
    return {
      position: el.getAttribute('position'),
      family:   el.getAttribute('family') || '',
      label:    el.getAttribute('label') || '',
      badge:    el.getAttribute('badge') || '',
      columns:  Number.isFinite(cols) && cols > 1 ? cols : 1,
      items,
    };
  }

  function renderList(list) {
    const wrap = document.createElement('div');
    const positionClass = POSITION_CLASS[list.position] || list.position;
    const familyClass   = list.family ? `fam-${list.family}` : '';
    const colsClass     = list.columns > 1 ? `cols-${list.columns}` : '';
    const badgeClass = list.badge ? 'has-badge' : '';
    wrap.className = `box-wrap ${positionClass} ${familyClass} ${colsClass} ${badgeClass}`.trim();

    if (list.badge) {
      const badge = document.createElement('span');
      badge.className = 'badge';
      badge.textContent = list.badge;
      wrap.appendChild(badge);
    }

    const box = document.createElement('div');
    box.className = 'box' + (list.columns > 1 ? ` cols-${list.columns}` : '');

    function makeRow(item) {
      const row = document.createElement(item.href ? 'a' : 'div');
      row.className = 'row';
      if (item.href) row.setAttribute('href', item.href);

      const glyph = document.createElement('span');
      glyph.className = 'glyph' + (item.brand ? ' brand' : '');
      if (item.brandColor) glyph.style.color = item.brandColor;
      if (item.glyph) glyph.appendChild(item.glyph);
      row.appendChild(glyph);

      const name = document.createElement('span');
      name.className = 'name';
      name.textContent = item.name;
      row.appendChild(name);

      if (item.meta) {
        const meta = document.createElement('span');
        meta.className = 'meta';
        meta.textContent = item.meta;
        row.appendChild(meta);
      }

      if (item.desc) {
        const desc = document.createElement('div');
        desc.className = 'desc';
        desc.textContent = item.desc;
        row.appendChild(desc);
      }

      return row;
    }

    if (list.columns > 1) {
      // Split items across N columns, filling top-to-bottom (col 1 first).
      // Each column wraps its rows so .row + .row borders only apply within
      // a single column.
      const perCol = Math.ceil(list.items.length / list.columns);
      for (let c = 0; c < list.columns; c++) {
        const col = document.createElement('div');
        col.className = 'col';
        const start = c * perCol;
        for (const item of list.items.slice(start, start + perCol)) {
          col.appendChild(makeRow(item));
        }
        if (col.childElementCount > 0) box.appendChild(col);
      }
    } else {
      for (const item of list.items) box.appendChild(makeRow(item));
    }

    wrap.appendChild(box);
    return wrap;
  }

  function renderHex(list) {
    if (!list.label || list.position === 'top') return null;
    const positionClass = POSITION_CLASS[list.position] || list.position;
    const familyClass   = list.family ? `fam-${list.family}` : '';
    const badgeClass    = list.badge ? 'has-badge' : '';
    const hex = document.createElement('div');
    hex.className = `workspace-corner-hex ${positionClass} ${familyClass} ${badgeClass}`.trim();
    // Allow newline in label to render a <br>; otherwise straight text.
    if (list.label.includes('\n')) {
      list.label.split('\n').forEach((line, i) => {
        if (i) hex.appendChild(document.createElement('br'));
        hex.appendChild(document.createTextNode(line));
      });
    } else {
      hex.textContent = list.label;
    }
    return hex;
  }

  function renderFlows(flows) {
    if (!flows.length) return null;
    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('class', 'flow-overlay');
    // Symmetric viewBox: x∈[-340,+340], y∈[-325,+325]. The SVG element is
    // centred in the workspace's grid cell, so SVG (0,0) lands exactly on the
    // workspace centre and path coords match the workspace-relative arithmetic.
    svg.setAttribute('viewBox', '-340 -325 680 650');
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
    svg.setAttribute('aria-hidden', 'true');
    for (const f of flows) {
      const path = document.createElementNS(NS, 'path');
      path.setAttribute('class', 'flow-line');
      if (f.path) {
        // Custom raw path overrides shape-based routing.
        path.setAttribute('d', f.path);
      } else {
        const a = anchor(f.from);
        const b = anchor(f.to);
        if (!a || !b) continue;
        path.setAttribute('d', pathFor(f.shape, a, b, f.clearance));
      }
      if (f.color) path.setAttribute('stroke', f.color);
      svg.appendChild(path);
    }
    return svg;
  }

  class PlatformDiagram extends HTMLElement {
    connectedCallback() {
      if (this._rendered) return;
      this._rendered = true;
      this._build();
      this._setupScrollProgress();
    }

    disconnectedCallback() {
      if (this._teardownScroll) this._teardownScroll();
    }

    /* Scroll-linked entrance: writes --pd-hex-progress / --pd-list-progress /
       --pd-flow-progress on `this` based on where the diagram sits in the
       viewport. Scrolling up reverses the entrance because the same mapping
       runs in reverse. rAF-throttled so we coalesce wheel/touch bursts. */
    _setupScrollProgress() {
      const reduced = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduced) return;  /* CSS defaults to 1 — diagram stays settled */

      let raf = null;
      const tick = () => { raf = null; this._updateProgress(); };
      const onScroll = () => { if (!raf) raf = requestAnimationFrame(tick); };

      window.addEventListener('scroll',  onScroll, { passive: true });
      window.addEventListener('resize',  onScroll, { passive: true });
      this._teardownScroll = () => {
        window.removeEventListener('scroll', onScroll);
        window.removeEventListener('resize', onScroll);
        if (raf) cancelAnimationFrame(raf);
      };
      this._updateProgress();
    }

    _updateProgress() {
      /* Anchor on the workspace hex's centre — that's the user's "the diagram
         is here" point. Anchoring on `this` is wrong because the host has
         240px of top-padding before any visible content, so its rect.top
         crosses the viewport long before the workspace appears. */
      const target = this.querySelector('.workspace') || this;
      const rect = target.getBoundingClientRect();
      const vh = window.innerHeight || document.documentElement.clientHeight;
      const centre = rect.top + rect.height / 2;

      /* Trigger window: progress runs 0 → 1 as the workspace centre moves from
         the viewport bottom (vh) up to the viewport centre (vh * 0.5).
         Beyond the centre, progress stays at 1; below the viewport, 0.
         Scrolling back up reverses the mapping. */
      const start = vh;
      const end   = vh * 0.5;
      const p = Math.max(0, Math.min(1, (start - centre) / (start - end)));

      /* Stage progress (each stage clamped 0..1).
           hex   covers 0.00 → 0.40 (move + fade in)
           list  covers 0.30 → 0.75 (slide + fade in after hexes)
           flow  covers 0.75 → 1.00 (fade in last) */
      const hex  = Math.max(0, Math.min(1, (p - 0.00) / 0.40));
      const list = Math.max(0, Math.min(1, (p - 0.30) / 0.45));
      const flow = Math.max(0, Math.min(1, (p - 0.75) / 0.25));

      this.style.setProperty('--pd-hex-progress',  String(hex));
      this.style.setProperty('--pd-list-progress', String(list));
      this.style.setProperty('--pd-flow-progress', String(flow));
    }

    _build() {
      // 1. Read declarative config from the light-DOM children.
      let workspace = null;
      const lists = [];
      const flows = [];

      for (const child of [...this.children]) {
        const tag = child.tagName.toLowerCase();
        if (tag === 'pd-workspace') {
          workspace = {
            logo: child.getAttribute('logo') || '',
            role: child.getAttribute('role') || '',
            alt:  child.getAttribute('alt')  || '',
          };
        } else if (tag === 'pd-list') {
          lists.push(parseList(child));
        } else if (tag === 'pd-flow') {
          const cl = parseFloat(child.getAttribute('clearance'));
          flows.push({
            from:      child.getAttribute('from'),
            to:        child.getAttribute('to'),
            shape:     child.getAttribute('shape') || 'l-h',
            color:     child.getAttribute('color') || '',
            clearance: Number.isFinite(cl) ? cl : 0,
            // Raw SVG `d` string for custom multi-bend routes that don't
            // fit any built-in shape. Coords are workspace-relative (origin
            // at workspace centre, same system as anchor(...)). When
            // present, it overrides shape/from/to.
            path: child.getAttribute('path') || '',
          });
        }
      }

      // 2. Replace the source tree with the rendered diagram.
      this.innerHTML = '';
      const grid = document.createElement('div');
      grid.className = 'diagram-grid';

      if (workspace) {
        const k = document.createElement('div');
        k.className = 'workspace';
        const img = document.createElement('img');
        img.className = 'workspace-logo';
        if (workspace.logo) img.src = workspace.logo;
        img.alt = workspace.alt;
        k.appendChild(img);
        if (workspace.role) {
          const r = document.createElement('div');
          r.className = 'role';
          r.textContent = workspace.role;
          k.appendChild(r);
        }
        grid.appendChild(k);
      }

      // Lists first, then their corresponding hex tags (so DOM order matches
      // the original platform-overview layout: hex tags appear after lists).
      for (const list of lists) grid.appendChild(renderList(list));
      for (const list of lists) {
        const hex = renderHex(list);
        if (hex) grid.appendChild(hex);
      }

      const flowSvg = renderFlows(flows);
      if (flowSvg) grid.appendChild(flowSvg);

      this.appendChild(grid);
    }
  }

  if (!customElements.get('platform-diagram')) {
    customElements.define('platform-diagram', PlatformDiagram);
  }
})();
