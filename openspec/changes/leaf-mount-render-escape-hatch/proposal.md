# Proposal: leaf-mount-render-escape-hatch

## Summary

Add an optional `mount(el, props)` / `unmount(el)` render mode to the cross-app
leaf registry so a sibling app's render-surface leaf can render on an
OpenRegister host object page **even when the host and the leaf are built
against different Vue majors**. The host renders a bare container element and
hands it to the leaf; the leaf instantiates its own framework (its own
`createApp(...).mount(el)`) inside that element and tears it down on `unmount`.
This is Vue-major-agnostic: each app renders with its own Vue into a shared DOM
node. It preserves the existing single-frame SFC `tab`/`widget` path unchanged
for same-major and built-in leaves.

This resolves hermiq#44 systemically and unblocks the hermiq, decidesk, and
openconnector leaves, all of which are Vue 3 and cannot render inside the Vue
2.7 host today.

## The problem (confirmed live — hermiq#44)

ADR-019 cross-app leaf rendering passes a sibling app's Single File Component
(SFC) object to the host and renders it through the host's own dynamic-component
machinery. This works **only when host and leaf share a Vue major**:

- **Host** — OpenBuild / OpenRegister object pages consume `@conduction/nextcloud-vue`
  (the v1/beta line), which peer-depends on `vue ^2.7` + `@nextcloud/vue ^8`
  (Vue 2.7). This is the host renderer.
- **Leaves** — hermiq (`CnAgentChatTab` / `CnAgentRunsWidget`), decidesk, and
  openconnector are Vue 3 apps (`@nextcloud/vue` v9). Their SFCs are compiled by
  the Vue 3 compiler.

A Vue-3-compiled SFC rendered under the Vue 2.7 host renderer crashes: the body
renders blank and the console shows `undefined` reads on data properties, because
the Vue 3 render function and reactivity shape are not what the Vue 2.7 runtime
expects. The **only** cross-app leaves that render today are the library's own
built-ins — and they render only because the host swaps them to its **own local**
SFC at resolve time (the `resolveTab` / `resolveWidget` `LIB_INTEGRATION_COMPONENTS`
path added for openregister#1958, so a lib-owned id is always bound to the
rendering bundle's Vue). A sibling app's bespoke component has no such local
twin, so it hits the raw cross-Vue trap and dies.

This is not a hermiq-only bug. It is structural: any Vue 3 leaf on a Vue 2.7 host
is blocked, and per ADR-066 (Vue 3 migration) the fleet will run **both Vue
majors in parallel for a long time** — the shared library gates the whole
migration and cannot flip app-by-app. So "just move the host to Vue 3" is not
available, and "hold every leaf on Vue 2.7" defeats the reason those apps exist
(hermiq's agent canvas needs Vue-3-only libraries per ADR-065). A render bridge
that does not care which Vue major each side uses is the only durable fix.

## Why SFC-passing cannot cross a Vue major

Passing an SFC object across the app boundary implicitly requires **one shared
renderer** to interpret it: the component's compiled render function, its
reactivity, and its injected dependencies (`inject()` inside nested `@nextcloud/vue`
controls) all assume the Vue instance that compiled them is the one rendering
them. When the registering bundle's Vue differs from the rendering bundle's Vue
— either a different copy of the same major (the openregister#1958 trap) or a
different major entirely (hermiq#44) — that assumption breaks. The #1958 fix
papers over the same-major case for lib-owned ids by re-resolving to a local
component; it cannot help a **cross-major** bespoke component, because there is
no compatible local twin to swap in. The DOM node, by contrast, is neutral
ground: both Vue majors can mount into a plain element and own their own subtree.
So the fix is to stop shipping a component across the boundary and instead ship a
`mount(el, props)` function the leaf runs with its own framework.

## What changes

- **Registry descriptor (`@conduction/nextcloud-vue`).** A render-surface
  provider MAY supply `mount(el, props)` + `unmount(el)` **instead of** (or, for
  progressive enhancement, **alongside**, as a same-major fallback) the SFC
  `tab`/`widget`. A descriptor is valid when it provides a render pair **or** a
  mount pair for each surface family it targets.

- **Host consumption (`@conduction/nextcloud-vue`, the consuming change).**
  `CnObjectSidebar`, `CnDetailPage`, and `CnDashboardPage` detect a mount-mode
  descriptor, render a bare container element, call `provider.mount(el, props)`
  on show, and call `provider.unmount(el)` on hide / teardown / object change.
  The existing SFC path stays the default for same-major and built-in leaves
  (fully backward compatible). Leaf mount failures are isolated so one bad leaf
  cannot blank the sidebar.

- **Server descriptor (OpenRegister).** `LeafDescriptor` gains a `renderMode`
  (`component` | `mount`) so the OCS discovery surface reports how a render-surface
  leaf renders, and the parity check knows which correlation to enforce.

- **Leaf migrations.** hermiq's `CnAgentChatTab` / `CnAgentRunsWidget`, decidesk's
  leaf, and openconnector's leaf move from `tab`/`widget` SFC registration to
  `mount`. Built-ins stay SFC.

## Scope boundary

This is the **render** contract only. The ADR-041 cross-app **command** boundary
is unchanged: a mount surface renders read/append UI exactly as an SFC tab/widget
does; it invokes no verb in the host, and cross-app commands remain ADR-041 typed
events. ADR-066 (the render-and-read boundary companion to
app-leaf-provider-registration) is amended to state that the render half now
explicitly permits a mount escape hatch for cross-Vue-major leaves — see
design.md for the amendment text.

## Cross-repo impact

- **openregister** — `LeafDescriptor.renderMode` field + discovery surface +
  parity correlation. (This change's server delta.)
- **@conduction/nextcloud-vue** — registry descriptor accepts `mount`/`unmount`;
  `CnObjectSidebar` / `CnDetailPage` / `CnDashboardPage` grow the mount lifecycle;
  the parity gate learns that a mount provider satisfies render-surface parity
  without an SFC pair. (Coordinated consuming change.)
- **hermiq / decidesk / openconnector** — migrate their leaf registration from
  SFC to `mount`. (Per-app changes.)
- **hydra** — amend ADR-066 with the mount escape-hatch clause; the gate-24
  `integration-parity` check accepts a mount provider.

## Out of scope

- Migrating built-in leaves off SFC — they are same-major with the host and keep
  the SFC path.
- Any cross-app command / verb invocation — stays ADR-041 events.
- The general fleet Vue 3 migration (ADR-066 proper) — this change is the render
  bridge that lets leaves cross the major boundary while that migration proceeds.
