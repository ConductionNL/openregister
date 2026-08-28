# Tasks: leaf-mount-render-escape-hatch

## Ratification (do first)

- [ ] Amend ADR-066 in `hydra/openspec/architecture/` with the mount escape-hatch
      clause (design.md "ADR-066 amendment text"): render may cross a Vue major
      via a mount hand-off; command boundary unchanged (ADR-041). Human review
      required before code.

## openregister — server descriptor + discovery + parity

- [ ] Add a `renderMode` field to `LeafDescriptor` (`component` | `mount`,
      default `component`); expose it via a getter and in `toArray()`.
- [ ] Validate on registration: a render-surface leaf declaring `renderMode: 'mount'`
      is accepted with no bespoke tab/widget expectation; a `component` (or
      omitted) render-surface leaf keeps today's expectation.
- [ ] Emit `renderMode` on the OCS capabilities discovery surface so a manifest
      app / admin UI can read how a render-surface leaf renders without loading
      its JS.
- [ ] Extend the cross-layer parity correlation: for a render-surface leaf the
      server descriptor `renderMode` MUST equal the JS registration `renderMode`
      under the shared id.
- [ ] PHPUnit: renderMode default, mount-mode descriptor accepted, discovery
      surface reports renderMode, render-mode mismatch flagged.

## @conduction/nextcloud-vue — registry + host lifecycle (consuming change)

- [ ] Registry descriptor: accept optional `renderMode` (`component` default |
      `mount`), `mount(el, props)`, `unmount(el)`. Validation — `component`
      requires `tab`+`widget` (unchanged); `mount` requires `mount`+`unmount`,
      makes `tab`/`widget` optional (same-major fast path); a half mount pair
      throws in dev / warns-and-drops in prod.
- [ ] Add `CnLeafMountHost` — the shared wrapper that owns the bare container
      element and the mount/unmount lifecycle (detect, mount timing, unmount
      timing, re-mount on object change, error isolation).
- [ ] `CnObjectSidebar` — render a mount-mode leaf through `CnLeafMountHost`;
      mount lazily when the tab becomes active, unmount on tab hide / teardown.
- [ ] `CnDetailPage` — render a mount-mode integration widget through
      `CnLeafMountHost`; forward `{ register, schema, objectId, surface, integrationContext }`.
- [ ] `CnDashboardPage` — same, for the dashboard surface.
- [ ] Error isolation: a throwing leaf mount/unmount is caught, logged, and shown
      as an inline error in that container only (never blanks the surface).
- [ ] Re-mount on object change: unmount then mount with new props on
      `objectId`/`register`/`schema` change.
- [ ] Keep the built-in / same-major SFC path (`LIB_INTEGRATION_COMPONENTS`,
      `resolveTab`/`resolveWidget`, openregister#1958) unchanged.
- [ ] gate-24 `check-integration-parity.js`: a `renderMode: 'mount'` descriptor
      satisfies parity via `mount`+`unmount` instead of `tab`+`widget`; correlate
      renderMode across the server descriptor and JS registration.
- [ ] Unit tests: descriptor validation for both modes; mount called on show,
      unmount on hide/teardown; re-mount on object change; error isolation;
      backward-compat of the SFC path.
- [ ] Docs: registry guide + `CnObjectSidebar`/`CnDetailPage`/`CnDashboardPage`
      doc pages describe `renderMode: 'mount'` and the mount/unmount contract.

## hermiq — leaf migration (reference; fixes #44)

- [ ] Expose `mount(el, props)` / `unmount(el)` from hermiq's init bundle that
      `createApp` the agent chat tab and the runs widget roots against `el`.
- [ ] Flip the JS registration and the server `LeafDescriptor` to
      `renderMode: 'mount'`; drop the `tab`/`widget` SFC refs from the descriptor.
- [ ] Live-verify on the Vue 2.7 host object page: chat tab + runs widget render
      (no blank body, no `undefined` reads), unmount on tab switch, re-mount on
      object change.

## decidesk — leaf migration

- [ ] Expose `mount`/`unmount` from decidesk's init bundle; flip JS + server
      descriptor to `renderMode: 'mount'`; live-verify on the host object page.

## openconnector — leaf migration

- [ ] Expose `mount`/`unmount` from openconnector's init bundle; flip JS + server
      descriptor to `renderMode: 'mount'`; live-verify on the host object page.

## hydra — gate

- [ ] Confirm gate-24 `integration-parity` accepts a mount provider (mirror of
      the nextcloud-vue parity change) and correlates renderMode across layers.
