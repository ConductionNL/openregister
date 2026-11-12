## Why

OpenRegister can only serve objects from its own magic tables. The object read path is hardcoded:
`ObjectService::find()/findAll()` → `GetObject` → `MagicMapper` → the database. There is no way to
expose a non-OR-native source — a CalDAV VTODO collection, or any leaf-integration entity — as
queryable OpenRegister objects without first **copying** the data in (the `data-sync-harvesting`
`Source` mechanism is harvest/sync into OR tables, not a live projection).

This blocks the "authoritative source lives outside OR, OR projects it read-only" pattern. The
concrete driver is decidesk's **action items** (REQ-AI-DECK-006): the CalDAV VTODO is the
authoritative action-item record (ADR-002), and decidesk wants its `ActionItem` schema to be a
**read-only projection** of those VTODOs so existing OR aggregations/KPIs keep working — *without*
decidesk hand-rolling a bespoke CalDAV→OR copier. That projection mechanism belongs in OpenRegister,
not in each consuming app. The same capability generalises to any leaf source (Deck cards, Calendar
events, Contacts) that an app wants to query through the uniform OR object API.

The foundations are adjacent but insufficient: the integration registry's `query-time` storage
strategy does live, copy-free reads but only for **sub-resource** integration endpoints (not
schema-level objects); `SystemEntityObjectAdapter` shows how to wrap a foreign entity as a virtual
`ObjectEntity`; `RegisterCalendar` is a read-only projection of OR objects *outward*. None of them
let a schema's objects be *read from* an external source.

## What Changes

- Add an **`ObjectSourceProvider`** interface (parallel to `IntegrationProvider`): `getId()`,
  `find(register, schema, id)`, `findAll(register, schema, query)`, `count(register, schema, query)`,
  `isEnabled()`. It returns non-persisted `ObjectEntity` instances built on the fly. It is
  **read-only**: there is no create/update/delete — mutations are rejected upstream.
- Add an **`ObjectSourceRegistry`** that discovers providers via a DI tag at bootstrap (mirroring
  `IntegrationRegistry`), keyed by provider id, with the same collision policy (first wins; dev
  throws on duplicate id).
- Add a schema extension key **`x-openregister-object-source`** — `{ "provider": "<id>", "config":
  { … }, "readOnly": true }` — declaring that a schema's objects are served by the named provider
  rather than the magic table.
- Route the read path through the provider: `GetObject::find()`/`findAll()`/`count()` (and the
  `ObjectService` entry points) check the schema for `x-openregister-object-source`; if present and
  the provider is enabled, **delegate** to it; otherwise fall back to `MagicMapper` (current
  behaviour, unchanged for every existing schema).
- **Fail-closed + RBAC parity:** virtual objects pass through the same object-level authorization as
  native objects and return uniform 404s when absent/denied (mirroring `integration-leaf-foundation`
  read semantics) — no enumeration oracle, no data persisted to OR tables.
- **Reject writes** to a virtual-sourced schema with a clear error (consistent with the
  `query-time` integration strategy's `NotImplementedException`): the external source stays
  authoritative; OR never becomes a second write path.
- Ship a first provider: **`CalDavVtodoObjectSourceProvider`** — maps VTODO properties
  (SUMMARY/title, ATTENDEE/assignee, DUE/dueDate, STATUS/status, plus X-OPENREGISTER-* link props)
  onto schema fields and returns them as virtual objects. Reuses the existing `TaskService` /
  CalDAV plumbing.

## Capabilities

### New Capabilities
- `object-source-providers`: Pluggable, read-only object-source provider interface + registry +
  the `x-openregister-object-source` schema key + read-path delegation in `GetObject`, plus a
  reference CalDAV-VTODO provider. Lets a schema's objects be served live from an external source
  with full RBAC parity and no local persistence. Consumed first by decidesk's `ActionItem`
  projection (REQ-AI-DECK-006).

### Modified Capabilities
<!-- None at the spec level. ObjectService / GetObject read methods gain a delegation branch at the
implementation level; their existing spec requirements (DB-backed reads) are unchanged for schemas
without x-openregister-object-source. -->

## Impact

- **Backend:** new `ObjectSourceProvider` interface + `ObjectSourceRegistry` + `CalDavVtodoObjectSourceProvider`
  (`lib/Service/ObjectSource/…`); DI tag + container registration in `Application.php`; a delegation
  branch in `GetObject::find()/findAll()/count()` and the matching `ObjectService` entry points; the
  schema validator accepts the new `x-openregister-object-source` key.
- **No database changes** — virtual objects are never written to magic tables.
- **No change for existing schemas** — the delegation branch only fires when the key is present.
- **Dependent apps:** decidesk binds its `ActionItem` schema to the `caldav-vtodo` provider (separate
  decidesk change `action-items-vtodo-deck-reconcile`); opencatalogi / softwarecatalog unaffected.
- **Risk:** the read path is hot — the delegation check must be a cheap schema-key lookup with the
  MagicMapper fallback fully preserved. CalDAV reads must be RBAC-scoped to the current user and
  fail closed.
