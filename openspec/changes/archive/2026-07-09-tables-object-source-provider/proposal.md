---
kind: code
---

# Proposal: Nextcloud Tables tables as read-only virtual registers/schemas

## Why

Municipalities and teams keep large amounts of authoritative, structured data in
**Nextcloud Tables** — inspection logs, asset inventories, intake lists — that
today is invisible to OpenRegister. To query, aggregate, relate, or expose that
data through the uniform OR object API (`/api/objects/{register}/{schema}`,
GraphQL, MCP, the object picker, KPIs) a team must first *copy* it into OR magic
tables, forking the authoritative record and inviting drift. The
`object-source-providers` seam already lets a schema's objects be served **live,
read-only** from an external system (CalDAV VTODOs, Groups, Deck cards). Tables
is the highest-value remaining source and fits that seam exactly: a Tables table
is a typed, column-defined collection — a natural virtual schema.

## What Changes

- Add a **`TablesObjectSourceProvider`** (`getId() = 'tables'`) in
  `lib/Service/ObjectSource/`, modelled on `GroupObjectSourceProvider` (shape,
  no-persist `toObjectEntity`) and `DeckObjectSourceProvider` (another-app
  integration via `class_exists` + `IAppManager` guard + defensive container
  lookup of internal `OCA\Tables\Service\*` services). It reads rows from one
  Tables table (or Tables **View**) via `RowService`/`ColumnService`, mapping
  each `Row2` to a non-persisted `ObjectEntity`.
- **Auto-seeded virtual schemas — one per Tables table.** OpenRegister
  auto-creates a virtual schema for every Tables table under the `tables` virtual
  register. Each seeded schema carries `x-openregister-object-source:
  { provider: "tables", readOnly: true, config: { tableId: <int>, viewId?:
  <int>, … } }` — the existing `Schema::getObjectSource()` key and read-path
  delegation, **no** new resolution machinery. Seeded schemas are
  **instance-global**; per-user visibility is enforced at *read time* by Tables
  RBAC — a user without access to a table gets empty/404 from its schema, so
  anti-oracle parity is preserved.
- **Sync mechanism.** Seeding and refresh run via (a) a **Repair step** on
  install/upgrade, (b) an **`occ openregister:tables:sync`** command, and (c)
  **Tables event listeners** for the events Tables actually emits:
  `TableDeletedEvent` → remove/retire the bound schema (plus
  `TableOwnershipTransferredEvent` where trivially applicable). Tables emits
  **no** table-created or column-changed event, so new tables and column drift
  are picked up by occ sync, upgrade repair, and opportunistic on-read refresh —
  not live.
- **Column-type → JSON-schema mapping.** The seeder/provider resolves numeric
  `columnId`s to property names via `ColumnService` and maps Tables column types
  (text/number/datetime/selection/usergroup/relation) onto schema properties.
  **Relation columns map to the referenced virtual object's UUID**
  (deterministically derived from the target table + row id — enabling OR-level
  deep-linking across auto-seeded virtual schemas), falling back to the raw
  rowId when the referenced table's schema is missing.
- **RBAC parity, fail-closed.** Every Tables service call passes the acting
  `$userId`; Tables enforces ownership/shares/contexts and denied ⇒ null/empty ⇒
  uniform 404 (same anti-oracle stance as the other providers). When the Tables
  app is missing/disabled `isEnabled()` is false and reads degrade to empty +
  logged warning (never a 500, never a DB fallback).
- **Semantic-map row.** Add a `tables` row to `NcEntitySemanticMap` gated on
  `requiredApp = tables` so the binding participates in ADR-048 semantic
  resolution and the app-enabled gate, alongside the existing user/group/etc.
  rows.
- **Filtering v1.** Native `limit`/`offset` via `RowService`; other query
  filters/sort applied provider-side in PHP after fetch (logged when capping);
  optional `viewId` binds a server-side-filtered Tables View instead of a raw
  table.

**Out of scope (explicit):** write-back (the existing `SaveObject`/`DeleteObject`
read-only guard stays — Tables remains authoritative), facets, audit trail,
OR-native relation expansion (relation cells carry the referenced object's UUID,
not an expanded relation), locking, file attachments, and row caching (v1 reads
live; row-cache invalidation via `OCA\Tables\Event\Row*Event` is deferred —
event listeners in scope are for schema lifecycle only).

## Capabilities

### New Capabilities
- `tables-virtual-register`: A read-only `tables` object-source provider plus
  auto-seeding that exposes every Nextcloud Tables table (or View) as a virtual
  OpenRegister register/schema — live row reads, column-type → property mapping,
  relation-to-UUID deep-linking, read-time RBAC parity, fail-closed when the app
  is absent, sync via Repair step + `occ openregister:tables:sync` + Tables
  event listeners (TableDeletedEvent), and a `tables` semantic-map row.
  Write-back and the other virtual-schema exclusions (facets/audit/relation-
  expansion/locking/files/row-caching) are out of scope.

### Modified Capabilities
<!-- None at the spec level. The provider plugs into the existing
object-source-providers seam (interface, registry, read-path delegation, schema
key, write-rejection) whose spec requirements are unchanged; this change adds a
new provider capability rather than modifying those requirements. -->

## Impact

- **Backend:** new `lib/Service/ObjectSource/TablesObjectSourceProvider.php`
  + a schema-seeder service (table columns → virtual schema); a `tables` row in
  `NcEntitySemanticMap`; a seeding Repair step (following the existing
  `SeedDirectoryVirtualSchemas`/`SeedAppVirtualSchemas` pattern); an
  `occ openregister:tables:sync` command; Tables event listeners
  (`TableDeletedEvent`, optionally `TableOwnershipTransferredEvent`) registered
  with `class_exists` guards; DI registration in `lib/AppInfo/Application.php`
  (`registerObjectSourceProviders` / `registerObjectSourceProviderInstances` /
  `bootObjectSourceProviders`). No changes to the interface, registry,
  read-path delegation, or write-guard (all reused).
- **No database changes** — virtual objects are never written to magic tables.
- **No change for existing schemas** — the delegation branch only fires when the
  `x-openregister-object-source` key is present.
- **Soft dependency:** Nextcloud Tables (upstream v2.2.0, NC 33-35) is NOT a hard
  dependency and NOT installed in the dev env — the provider guards every use of
  `OCA\Tables\Service\*` with `class_exists` + `IAppManager::isEnabledForUser`.
  Tables exposes no stable public OCP API, so integration is via its internal DI
  services (the pattern its own AnalyticsDatasource uses).
- **Dependent apps:** opencatalogi / softwarecatalog unaffected; any app can bind
  a schema to a Tables table once the provider ships.
- **Risk:** the read path is hot — the delegation check is the existing cheap
  schema-key lookup; Tables reads must be RBAC-scoped to the acting user and fail
  closed. Column drift (a column dropped/renamed after binding) is handled
  on-read (unknown `columnId` skipped, logged), not via a schema-change event
  (Tables emits none).
