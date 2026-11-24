# Other apps add flow triggers to the event catalog

## Why

The flow builder's trigger palette and `GET /api/flow/event-catalog` list a
fixed set of OpenRegister triggers (`EventCatalogService::CATALOG`). An app that
fires its own events into `FlowTriggerService::fire()` (Keepiq's
`clients-vault-event-flow-triggers` change fires `keepiq.<audit event type>`)
has no way to show them there, so an administrator would have to type a trigger
id by hand, and a case sentry naming such an event is refused because the id is
not in `knownTriggerIds()`.

## What Changes

- **`RegisterFlowTriggersEvent`** (`lib/Event/`): dispatched once per request
  when the catalog is first read, carrying `addTrigger(string $id, string $label, string $group)`.
- **`EventCatalogService`**: `getCatalog()` and `knownTriggerIds()` return the
  constant entries followed by the contributed ones. A contribution with an
  empty field, or an id or legacy alias the catalog already has, is refused and
  logged; the first offer of an id wins. A throwing listener is logged and the
  triggers added before it are kept. `aliasesFor()` is unchanged: a contributed
  id matches only itself.

## Impact

- No interface signature changes. `EventCatalogService`'s constructor gains two
  optional collaborators (`IEventDispatcher`, `LoggerInterface`); without them
  it answers its own entries as before.
- Consumers: Keepiq `lib/Listener/FlowTriggerCatalogListener.php`, bound to the
  event by class name.
