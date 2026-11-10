# Design: app-leaf-provider-registration

## The layer decision — server *and* JS, correlated by id

A leaf has two faces: something the server must know exists and can serve data
for, and something the browser must mount as a Vue tab/widget. The honest state
of the codebase is that each face is already half-built on a different layer, so
the design is not "pick one layer" but "declare the seam on the server, keep the
render registration on the client, and bind them with a shared id."

**Server layer — the real gap. Owns discovery + data + capability declaration.**
Today an `IntegrationProvider` can only be added inside OpenRegister's own
`Application::boot()`. A sibling app cannot contribute one. We add
`RegisterLeafProvidersEvent`, a typed collect-event dispatched once when the leaf
catalogue is first read. A sibling app's single `IEventListener` contributes a
`LeafDescriptor` (availability + capability metadata) and, when it offers data,
an `IntegrationProvider` instance. Because the listener runs in the sibling
app's DI context, the provider it constructs closes over that app's own services
— the same property ADR-041 relies on for command listeners. This mirrors
`RegisterMcpToolProvidersEvent` line for line, so an app that already writes an
MCP or flow-node listener writes the same shape here.

**JS layer — already built. Owns render component registration.**
`@conduction/nextcloud-vue`'s `registry.js` already exposes `registerIntegration()`
plus a `window.OCA.OpenRegister.integrations` stub-queue that survives any
bundle load order, and `CnObjectSidebar` / `CnDashboardPage` / `CnDetailPage` /
`CnFormDialog` already render from it. A leaf app ships a small init bundle via
`Util::addInitScript()` that calls `registerIntegration({ id, tab, widget, ... })`.
This change does not rebuild that; it *specifies* it as the render-surface half
of the leaf contract and requires the JS `id` to equal the server descriptor
`id`.

**Why both, not one.** The server cannot mount Vue components (they live in the
app's own bundle, and ADR-019's cross-Vue trap, openregister#1958, means a
component must resolve in its own render bundle). The client cannot be the
source of truth for discovery (an app whose JS has not loaded on this page is
still installed, still has data, and must still be discoverable by admin UI, OCS
capabilities, and manifest apps). So render registration stays on the client,
discovery + data stay on the server, and the shared `id` is the join key. The
parity contract (below) is what keeps the two halves honest.

## Contract shapes

### LeafDescriptor (server declaration)

A read-only value the listener contributes. Fields:

- `id` — stable kebab-case identifier, unique across the registry, equal to the
  JS registration id.
- `label`, `icon`, `group` — presentation metadata mirroring the JS descriptor.
- `requiredApp` — the Nextcloud app id that must be installed and enabled for
  the leaf to be usable; null for always-available.
- `surfaces` — the render surfaces the leaf targets (a subset of
  `user-dashboard`, `app-dashboard`, `detail-page`, `single-entity`); empty when
  the leaf offers no render surface.
- `referenceType` — optional marker so a schema reference property can target
  this leaf's single-entity widget (ADR-019 / AD-18).
- `requiresPermission` — optional permission string gating visibility.
- `kinds` — a set drawn from `render-surface`, `data-provider`, `agent-runner`.
  At least one is required. `agent-runner` is reserved here and defined by the
  separate hermiq change.

### App-local data provider

An `IntegrationProvider` whose `getStorageStrategy()` returns the new value
`app-local`. Semantics:

- `list(register, schema, objectId, filters)` — returns the items the app holds
  for that object (e.g. the notes on it), in the existing flat-list or
  `{ items, total, nextCursor }` envelope shape. This is the read path every
  data leaf must implement.
- `create(register, schema, objectId, payload)` — optional write. An app that
  offers add-a-note implements it; a read-only data leaf lets it throw
  `NotImplementedException`, exactly as `query-time` providers do today.
- `get` / `update` / `delete` — optional, same rule.
- The provider's methods run in the sibling app's DI context and read/write the
  app's own store. OpenRegister does not persist the data; it routes the call.

The note case is the canonical minimum: a `list` returning notes for the object
and a `create` adding one. "Apps offering notes to OpenRegister" is this and
nothing more.

## How it relates to the existing built-in leaves

The built-in files / notes / calendar leaves already do exactly this pattern
internally: they aggregate another Nextcloud subsystem's data onto an
OpenRegister object through an `IntegrationProvider`, registered in
`bootBuiltinIntegrationProviders()`. `FilesObjectSourceProvider` and the
NC-native "backend already shipped" leaves read a foreign store and present it
against `(register, schema, objectId)`. The `app-local` strategy is the
generalisation: it takes that same shape and makes it available to *any* sibling
app via the event, instead of only to leaves OpenRegister boots itself. No new
data contract is invented — `IntegrationProvider` already speaks about "linked
things" rather than OR entities on purpose (its own docblock notes this, AD-14).
The only additions are the registration seam and the `app-local` enum value.

## Discovery + parity

- **Discovery.** Registered descriptors are surfaced through OpenRegister's OCS
  capabilities response (the same channel `IntegrationProvider::health()` already
  feeds, AD-17). Manifest apps and admin UI read the catalogue there without
  loading any leaf's JS bundle.
- **Parity (ADR-019).** A descriptor declaring `render-surface` MUST have a JS
  registration under the same id that supplies both `tab` and `widget` — the JS
  `register()` already throws when either is missing. The cross-app extension is
  that the correlation is checked between the server descriptor and the JS
  registration, not only within the JS call.
- **Collision (ADR-013).** Duplicate leaf id: first registration wins, second is
  ignored with a logged warning — the policy `ObjectSourceRegistry` and the JS
  registry already implement. Across apps the same rule holds; boot/dispatch
  order determines the winner, which is why ids are namespaced by convention
  (e.g. `hermiq-agent`, not `agent`).

## Migration / compatibility

- The five built-in leaves are unaffected: they keep registering in
  `bootBuiltinIntegrationProviders()`. The event is collected in addition to,
  not instead of, the built-in boot — the same coexistence pattern the MCP
  registration event uses for one release.
- `app-local` is a new enum value; existing `magic-column` / `link-table` /
  `external` / `query-time` providers are untouched.
- The JS `registerIntegration()` path is already shipped and backwards
  compatible; specifying it changes no runtime behaviour.
- No object data migrates; app-local data never lived in OpenRegister.

## Risks

- **Lifting the ADR-041 moratorium.** ADR-041 said do not extend the OR registry
  with cross-app contribution until the IReferenceProvider convergence is
  decided. This change makes that decision for the collect/render/read case
  only. The mitigation is the hard render-and-read boundary: the descriptor and
  the provider contract expose no verb, so the registry cannot become a command
  bus, and gate-27 (`no-phantom-cross-app-rpc`) still forbids the `getLeaf` /
  `registry->call` patterns. This must be ratified by a companion ADR before
  implementation — flagged as an open question.
- **A sibling declaring a data-provider without a render surface, or vice
  versa.** The `kinds` set makes each face independently declarable; a
  data-only leaf (notes, no bespoke widget) is legitimate and renders through a
  generic list widget, while a render-only leaf declares no provider. Parity is
  enforced only for the `render-surface` kind.
- **Discovery cost.** The event is dispatched lazily and once per request when
  the catalogue is read, and a throwing listener is swallowed — the same
  non-fatal contract as MCP discovery — so one broken app cannot remove leaves
  from the instance.
- **IReferenceProvider overlap.** For pure read-only cross-app *references*,
  Nextcloud's native `IReferenceProvider` is cross-app by design and may be the
  better long-term home. This change deliberately does not migrate onto it; the
  companion ADR should record whether app-local read leaves eventually converge
  there, keeping OR's bespoke layer only for the tab/widget parity and
  linked-entity CRUD that `IReferenceProvider` cannot express.

## Companion ADR (recommended, not created here)

File in `hydra/openspec/architecture/` (org-wide): an ADR that (1) ratifies
lifting the ADR-041 moratorium for the collect/render/read case, (2) fixes the
render-and-read boundary so the leaf seam can never carry a command, (3) records
the IReferenceProvider convergence decision, and (4) extends gate
`integration-parity` to the cross-app descriptor<->JS id correlation. It should
cross-reference ADR-019, ADR-041, and ADR-013. Do not author it in hydra without
human review — it changes an org-wide contract.
