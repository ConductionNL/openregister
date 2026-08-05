# Design: leaf-mount-render-escape-hatch

## The core idea — ship a mount function, not a component

The existing ADR-019 render contract stores a Vue **component object** on the
registry descriptor (`tab`, `widget`, and the surface overrides) and the host
renders it through its own dynamic-component machinery. That implicitly binds the
component to the host's Vue runtime. The escape hatch replaces the shipped
artefact for cross-major leaves: instead of a component object, the leaf ships a
**mount function** the host calls with a plain DOM element. The leaf then runs
its own framework inside that element. The host never interprets the leaf's
render function or reactivity — it only owns the container element's lifecycle.
The DOM node is the neutral hand-off boundary that both Vue majors can share.

This is the standard micro-frontend hand-off (the same shape as a Web Component's
`connectedCallback` / `disconnectedCallback`, or a framework-agnostic
`mount`/`unmount` pair), narrowed to the leaf registry.

## The mount contract

A render-surface provider descriptor gains two optional functions:

- `mount(el, props)` — called by the host with:
  - `el` — a host-owned, empty DOM element (a bare `div`) already inserted into
    the surface at the position the SFC widget/tab would occupy.
  - `props` — the same context object the SFC path forwards:
    `{ register, schema, objectId, surface, integrationContext, ... }` (the exact
    forwarded shape per surface is listed under "Props forwarded" below).
  - The leaf instantiates its own framework rooted at `el` (for a Vue 3 leaf,
    `createApp(RootComponent, props).mount(el)`), holds a reference to that
    instance keyed by `el`, and returns. The return value MAY be a teardown
    handle but the host MUST NOT depend on it — `unmount` is the teardown seam.
- `unmount(el)` — called by the host when the surface is hidden, the host
  component is destroyed, or the bound object changes. The leaf destroys the
  framework instance it created for that `el` (for a Vue 3 leaf, `app.unmount()`)
  and releases any listeners. After `unmount` returns the host is free to remove
  `el` from the DOM.

Both functions are keyed by `el`, not by leaf id: the same leaf may be mounted
into several elements at once (e.g. a detail-page widget and a sidebar tab on the
same page), so the leaf keeps a per-element instance map rather than a single
singleton.

`mount` and `unmount` are optional and travel as a pair — a descriptor that
supplies one MUST supply the other. A descriptor MAY supply both the SFC pair
and the mount pair: the SFC pair is then a same-major fast path and `mount` is
the cross-major fallback (see "renderMode" for how the host chooses).

## renderMode — how the host and discovery know which path to use

The render mode is explicit rather than inferred, so discovery, parity, and the
host all agree without sniffing function references.

- **JS descriptor (`@conduction/nextcloud-vue` registry).** A new optional
  `renderMode` field, `'component'` (default) or `'mount'`. Registration
  validation:
  - `renderMode: 'component'` (or omitted) — requires `tab` and `widget` as
    today (unchanged behaviour, backward compatible).
  - `renderMode: 'mount'` — requires `mount` and `unmount` functions; `tab` /
    `widget` become optional and, when present, are treated as the same-major
    fast path.
  - Mixing rules are validated at registration so a malformed descriptor throws
    in dev and warns-and-drops in prod, exactly as the current required-field
    checks do.
- **Server descriptor (`LeafDescriptor`).** A matching `renderMode`
  (`component` | `mount`, default `component`) is carried on the descriptor and
  emitted in `toArray()` / the OCS discovery surface, so a manifest app or admin
  UI can report *how* a render-surface leaf renders without loading its JS. The
  server value MUST equal the JS registration's `renderMode` under the same id —
  this is the cross-layer parity correlation (below).

## Host-side lifecycle — the consuming change in @conduction/nextcloud-vue

The host components named explicitly as the consuming change are
`CnObjectSidebar`, `CnDetailPage`, and `CnDashboardPage`. Each already resolves a
provider from `useIntegrationRegistry()` and renders its SFC. The change adds a
mount branch:

1. **Detect.** When rendering a provider whose resolved `renderMode` is `mount`,
   the component renders a bare container element (`div` with a `ref`) instead of
   a dynamic component. A tiny wrapper component (`CnLeafMountHost`, new, in the
   library) owns this element and the lifecycle so the three surfaces share one
   implementation.
2. **Mount timing.** `CnLeafMountHost` calls `provider.mount(el, props)`:
   - **Sidebar tab** — on the tab becoming active/visible (lazy: not mounted
     while the tab is hidden), so a Vue 3 leaf app is not instantiated until the
     user opens its tab.
   - **Detail-page / dashboard widget** — when the widget enters the layout and
     its container is in the DOM.
3. **Unmount timing.** `provider.unmount(el)` is called on: the tab becoming
   inactive/hidden, the widget leaving the layout, and the host component's
   `beforeUnmount`/`beforeDestroy`. The host MUST call `unmount` before it
   removes `el`.
4. **Re-mount on object change.** When the bound `objectId` (or
   `register`/`schema`) changes while the surface stays visible, the host calls
   `unmount(el)` then `mount(el, newProps)` — a full teardown+rebuild rather than
   prop diffing, because the host cannot reach into the leaf's own reactive tree
   to push new props. Leaves therefore read their context from the `props` passed
   at mount, not from a live-updating binding. (A leaf that wants live updates can
   subscribe to the host's registry/object events itself; that is a leaf concern,
   not a host prop channel.)
5. **Error isolation.** The `mount` call is wrapped so a throwing leaf mount is
   caught, logged, and rendered as an inline error/empty state in that container
   only. One leaf's mount failure MUST NOT propagate to blank the sidebar, the
   detail page, or the sibling widgets — this mirrors the registry's existing
   "one bad `onChange` subscriber shouldn't take down the rest" isolation and the
   server-side "a broken listener costs only its own leaf" rule.

## Props forwarded

The host forwards the same context to `mount(el, props)` that it forwards to an
SFC widget/tab today, so a leaf's mount root receives an equivalent contract:

- **Sidebar tab** — `{ register, schema, objectId, surface: 'single-entity' | 'detail-page', ...sidebarProps }`.
- **Detail-page widget** — `{ register, schema, objectId, surface: 'detail-page', integrationContext, ...widgetProps }`.
- **Dashboard widget** — `{ register, schema, objectId?, surface: 'app-dashboard' | 'user-dashboard', integrationContext, ...widgetProps }`.

`integrationContext` is the existing `{ register, schema, objectId }` bag
`CnDetailPage` / `CnDashboardPage` already derive; the mount path forwards it
verbatim.

## Backward compatibility

- Descriptors without `renderMode` behave exactly as today: `component` mode,
  `tab` + `widget` required, rendered through the existing dynamic-component
  path. No existing consumer descriptor changes shape.
- The five built-in leaves stay `component` mode and keep the openregister#1958
  local-resolution path (`LIB_INTEGRATION_COMPONENTS`) untouched — they are
  same-major with the host, so the mount hatch is neither needed nor used for
  them.
- `renderMode: 'mount'` is purely additive: it is opt-in per leaf, and a leaf may
  keep an SFC pair alongside `mount` so it renders in-frame on a future
  same-major host and via mount on the current Vue 2.7 host.
- The registry singleton, stub-queue replay, collision policy (ADR-013 first-wins),
  and `useIntegrationRegistry` reactive snapshot are all unchanged; only the
  descriptor validation and the surface components' render branch are extended.

## gate-24 (integration-parity) implication

Gate-24 today asserts every registered integration declares **both** `tab` and
`widget` (`scripts/check-integration-parity.js`, wrapped by
`openregister/scripts/check-integration-parity.sh`). A mount-mode descriptor
deliberately has **no** SFC `tab`/`widget`, so the parity rule must learn a
second valid shape:

- A `renderMode: 'component'` (or omitted) descriptor still requires `tab` +
  `widget` — unchanged.
- A `renderMode: 'mount'` descriptor satisfies render-surface parity by declaring
  `mount` + `unmount` functions instead; it does **not** need an SFC pair.
- The cross-layer correlation extends: for a render-surface leaf the server
  `LeafDescriptor.renderMode` MUST equal the JS registration's `renderMode` under
  the same id, and the declared render/mount pair for that mode MUST be present.

So the parity contract moves from "tab AND widget" to "a complete render pair for
the declared renderMode", keeping the AD-11/AD-13 guarantee that a render-surface
leaf can actually render, while admitting the mount shape.

## ADR-066 amendment text

ADR-066 (the render-and-read boundary companion to
app-leaf-provider-registration; ratified in hydra) is amended by appending the
following clause. It does not change the command boundary — it only records that
the render half may cross a Vue major via a mount hand-off:

> **Amendment (leaf-mount-render-escape-hatch): render may cross a Vue major via
> a mount hand-off.** The render-and-read boundary permits a render-surface leaf
> to render either as a Single File Component interpreted by the host's Vue
> runtime (`renderMode: 'component'`, the default, for same-major and built-in
> leaves) **or** by supplying a `mount(el, props)` / `unmount(el)` pair
> (`renderMode: 'mount'`) that the host invokes against a bare, host-owned DOM
> element. In mount mode the leaf instantiates its own framework inside that
> element, so a leaf built against a different Vue major than the host still
> renders — the DOM element is the neutral hand-off boundary. This is a **render**
> affordance only: a mount surface renders read/append UI under the identical
> constraints as an SFC tab/widget, it invokes no verb in the host, and cross-app
> commands remain ADR-041 typed events. The host MUST isolate a leaf mount
> failure to that leaf's own container. Parity (ADR-019) is preserved: a
> render-surface leaf MUST declare a complete render pair for its declared
> renderMode — `tab` + `widget` for `component`, `mount` + `unmount` for `mount`
> — and the server descriptor's renderMode MUST equal the JS registration's under
> the shared id.

## Leaf migration list

| App | Today (SFC) | After (mount) |
| --- | --- | --- |
| hermiq | `CnAgentChatTab` (tab) + `CnAgentRunsWidget` (widget), Vue 3 — currently blank under Vue 2.7 host | `renderMode: 'mount'`, its bundle exposes `mount`/`unmount` that `createApp` the chat tab and runs widget roots | fixes hermiq#44 |
| decidesk | Vue 3 leaf (blocked identically) | `renderMode: 'mount'` | unblocked |
| openconnector | Vue 3 leaf (blocked identically) | `renderMode: 'mount'` | unblocked |
| built-ins (files, notes, calendar, …) | SFC, same-major with host | unchanged — stay `renderMode: 'component'` | no change |

hermiq is the reference migration; decidesk and openconnector follow the same
recipe (expose a `mount`/`unmount` from their init bundle, flip `renderMode`,
drop the `tab`/`widget` SFC refs from the descriptor). Each app also sets
`LeafDescriptor.renderMode = 'mount'` on its server-side leaf registration so
discovery and parity agree.

## Why not Web Components / an iframe

- **Web Components** would also cross the Vue major, but they force the leaf to
  ship a custom-element wrapper and re-plumb Nextcloud CSS-variable theming and
  `@nextcloud/*` context across the shadow boundary. `mount(el, props)` into a
  light-DOM element keeps the leaf's existing SFC and inherits the host's theme
  variables for free.
- **An iframe** isolates too much: separate document, no shared NC auth/CSS
  context, awkward sizing. The mount hatch shares the DOM and the page context;
  only the framework instance is separate.

The mount hand-off is the minimum that crosses the Vue major while keeping the
leaf's code, theming, and context intact.
