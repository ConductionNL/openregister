# Proposal: app-leaf-provider-registration

## Summary

Give any sibling Nextcloud app a sanctioned server-side seam to register itself
as a **leaf** on OpenRegister objects — contributing render surfaces (a
tab/widget on an object) and data (notes, records, annotations backed by the
app's own store) — through a typed collect-event, the same idiom OpenRegister
already uses for MCP tool providers and flow nodes. This is the umbrella "apps
hook themselves into OpenRegister" system. It is render-and-read only by
construction; cross-app *commands* stay ADR-041 typed events.

## The gap

OpenRegister already has a rich leaf system, but it is closed to sibling apps on
the server:

- **PHP side.** The `IntegrationProvider` contract (list / get / create / update
  / delete + `getRequiredApp`, `getStorageStrategy`, `getOpenConnectorSource`,
  `requiresPermission`, `authRequirements`, `health`) is a full data-provider
  contract. But every provider is registered inside OpenRegister's own
  `Application::boot()` through `bootBuiltinIntegrationProviders()` ->
  `IntegrationRegistry::addProvider()`. There is no event, hook, or DI mechanism
  for a sibling app to contribute one. Its `getStorageStrategy()` enum offers
  `magic-column` and `link-table` (data in OpenRegister) and `external` (data
  over OpenConnector HTTP) but has **no strategy for data that lives in another
  Nextcloud app's own store**. ADR-041 records this exact limitation.

- **JS side.** The opposite is already true: `registry.js` in `@conduction/nextcloud-vue`
  ships `registerIntegration()` plus a stub-queue on
  `window.OCA.OpenRegister.integrations` so a leaf app's bundle, loaded on every
  page via `Util::addInitScript()`, can register its tab and widget components
  cross-app regardless of bundle load order. That path works and is documented
  in the library's own guide — but it is not specified anywhere in OpenRegister,
  it is invisible to the server, and nothing ties a JS registration back to a
  server-declared capability.

So the render layer is solved on the client and unspecified on the server; the
data layer is specified on the server and closed to siblings. This change closes
both by declaring one cross-app registration seam and correlating the two
layers by a shared leaf id.

## What changes

- **`RegisterLeafProvidersEvent`** — a typed `IEventDispatcher` collect-event in
  OpenRegister, mirroring `RegisterMcpToolProvidersEvent`. A sibling app writes
  one `IEventListener` that contributes a leaf. The event is dispatched once,
  lazily, when the leaf catalogue is first read. A throwing listener costs its
  own leaf and nothing else.

- **`LeafDescriptor`** — the server-side declaration a listener contributes. It
  carries the shared `id`, `label`, `icon`, `requiredApp`, `group`, the render
  `surfaces` it targets, an optional `referenceType`, `requiresPermission`, and
  a `kinds` set naming which leaf kinds the app offers: `render-surface`,
  `data-provider`, and the reserved forward-reference `agent-runner`. The
  descriptor is availability plus capability metadata; it does not carry Vue
  components.

- **App-local data providers.** The `IntegrationProvider` storage-strategy enum
  gains `app-local`: data lives in the contributing app's own store and read /
  optional write are served by the provider's own methods, which run in that
  app's DI context because the listener constructed the provider there. This is
  the generalisation of the built-in files / notes / calendar leaves — which
  already aggregate another app's data onto an object — to any sibling app. It
  is the concrete shape behind "apps offering notes to OpenRegister": a
  read-list of notes for an object, plus an optional add-note write.

- **Discovery and parity.** Registered descriptors are exposed for discovery
  through OpenRegister's OCS capabilities surface so OpenRegister and manifest
  apps can see which leaves exist without loading every app's JS. The ADR-019
  tab+widget parity contract is preserved and extended cross-app: a descriptor
  that declares the `render-surface` kind MUST have a matching JS registration
  supplying both a tab and a widget under the same id. Collisions follow ADR-013
  first-wins on both layers.

## ADR alignment

- **ADR-019 (integration registry — render/link).** This change keeps the
  registry render-and-link only. The data-provider kind reads linked things and
  optionally writes a note against an object; it never invokes a business action
  in the sibling app. Tab+widget parity is preserved.

- **ADR-041 (cross-app commands via events).** ADR-041 rejected a bespoke
  `RegisterIntegrationProvidersEvent` **for delegation** — a provider registry
  is the wrong shape for a *command*, and it told authors not to extend the OR
  registry with cross-app contribution *until the IReferenceProvider
  convergence question is decided*. This proposal is that decision, scoped
  strictly to collect / render / read: a collect-event is the *correct* shape
  for "give me all the leaf providers" (it is exactly what the MCP event does),
  and nothing here can invoke a verb in another app. Commands remain ADR-041
  typed `*RequestedEvent` contracts. Because this lifts an explicit ADR-041
  moratorium, it warrants a companion ADR (see below) and human ratification.

## Cross-repo impact

- **openregister** — new event, descriptor, registry wiring, the `app-local`
  storage strategy, and the OCS capabilities discovery field. (This change.)
- **@conduction/nextcloud-vue** — the existing `registerIntegration()` JS
  bootstrap is formalised as the render-surface half of the contract; the parity
  gate extends to cross-app leaves. (Separate coordinated change.)
- **hydra** — a companion ADR ratifying the lifted ADR-041 moratorium and the
  render/read boundary; the `integration-parity` gate learns the cross-app
  descriptor<->JS correlation. (Recommended, see design.md.)

## Out of scope

- **Command invocation.** Invoking a verb in another app stays ADR-041 typed
  `IEventDispatcher` events. This change never adds a command path.
- **The hermiq agent leaf.** hermiq registering as an agent-runner leaf is the
  first consumer and is specified separately; here only the `agent-runner` kind
  name is reserved on the descriptor as a forward reference.
- **The manifest `type: agent` action.** The manifest-renderer action that
  surfaces an agent-runner leaf is a `@conduction/nextcloud-vue` change.
- **IReferenceProvider convergence.** Whether read-only cross-app render should
  migrate onto Nextcloud's native `IReferenceProvider` is flagged in design.md
  and deferred to the companion ADR; this change does not migrate existing
  leaves.
