# Design: tables-object-source-provider

## Context

The `object-source-providers` change built the seam this design plugs into — it
is done (unarchived) and unchanged here:

```
ObjectService::find()/searchObjectsPaginated()   lib/Service/ObjectService.php
  → GetObject::resolveObjectSource()             lib/Service/Object/GetObject.php:95
    → ObjectSourceRegistry::get(providerId)       lib/Service/ObjectSource/ObjectSourceRegistry.php
      → <provider>->find()/findAll()/count()      returns non-persisted ObjectEntity
```

A schema opts in by carrying `x-openregister-object-source` (parsed by
`Schema::getObjectSource()`, `lib/Db/Schema.php:1592`). Writes to such a schema
are already rejected in `SaveObject` (`lib/Service/Object/SaveObject.php:2688`)
and `DeleteObject`. This change adds one new provider (`getId() = 'tables'`), a
schema-seeder + sync machinery (Repair step, occ command, Tables event
listeners — D8), and a `NcEntitySemanticMap` row; it touches none of the
interface, registry, read-path delegation, or write-guard.

Discovery decision (user-confirmed): **auto-seed all tables** — OpenRegister
materialises one virtual schema per Tables table under the `tables` virtual
register (Repair step + `occ openregister:tables:sync` + event listeners, see
D8). Seeded schemas are instance-global; per-user visibility is enforced at read
time by Tables RBAC (D3), so a user without access to a table simply gets
empty/404 from its schema — anti-oracle parity preserved.

Reference providers to mirror (verified 2026-07-09):
- **`GroupObjectSourceProvider`** — shape, `toObjectEntity()` no-persist
  (`lib/Service/ObjectSource/GroupObjectSourceProvider.php:254-267`: `new
  ObjectEntity`, `setUuid`, `setRegister`, `setSchema`, `setObject`, never saved).
- **`DeckObjectSourceProvider`** — the another-app precedent: `isEnabled()` via
  `IAppManager::isInstalled('deck')`, internal service classes referenced by FQCN
  string constants, resolved through a guarded `resolveService()` that returns
  null when `class_exists($class) === false`. Tables mirrors this exactly.

Nextcloud Tables (upstream v2.2.0, NC 33-35) is a **soft dependency**, NOT
installed in the dev env, and exposes **no stable public OCP API**. Integration
is via its internal DI services `OCA\Tables\Service\{TableService, ColumnService,
RowService, PermissionsService}` — the same pattern Tables' own
AnalyticsDatasource uses. Every service method takes an acting `$userId`, so
Tables itself enforces RBAC.

## Declarative-vs-imperative decision

The **binding is declarative**: a schema names its source with the
`x-openregister-object-source` config block — data, not code — exactly like every
other object-source provider (ADR-031 keeps app↔OR wiring declarative in schema
config). No imperative per-app copier, harvester, or resolution branch is
introduced; the read-path delegation is unchanged.

The **provider itself is imperative PHP**, and that is the correct altitude: it
is an *external-integration* adapter that must call Tables' internal DI services,
resolve numeric `columnId`s to property names, map six column-type families onto
JSON-schema types, and defensively guard `class_exists`/app-enabled — none of
which is expressible declaratively. This matches the `object-source-providers`
precedent (the CalDAV/Deck/Group providers are all imperative classes behind a
declarative schema key). So: declarative binding, imperative adapter — no new
declarative machinery, no imperative wiring leaking into consuming apps.

## Decisions

### D1 — Provider (`getId() = 'tables'`), read-only
Implements `ObjectSourceProvider` (`find`/`findAll`/`count`/`getId`/`isEnabled`).
Constructor injects `IAppManager`, the acting-user resolver (`IUserSession` /
`$userId`), `LoggerInterface`, and a container for guarded lookup of the Tables
services (FQCN string constants, as `DeckObjectSourceProvider` does). Returned
`ObjectEntity` instances are built in `toObjectEntity()` and **never** saved —
`setUuid(<derived uuid, see D9>)`, `setRegister`, `setSchema`, `setObject($data)`;
`find()` accepts both the raw numeric rowId and the derived UUID.
**Rejected:** a read/write Tables adapter — Tables stays authoritative; the
existing write-guard is kept.

### D2 — `isEnabled()` + defensive guards
`isEnabled()` returns `$this->appManager->isEnabledForUser('tables')` wrapped so a
missing app / thrown lookup ⇒ `false` (never a fatal). Every Tables service is
obtained through a `resolveService(FQCN): ?object` that returns null when
`class_exists($class) === false`, so the class references never fatal on an
instance without Tables installed. Disabled ⇒ reads degrade to empty + a single
logged warning (the read-path delegation already does this for a missing/disabled
provider).

### D3 — RBAC parity (Tables enforces it)
Every call passes the acting `$userId`:
`RowService::findAllByTable($tableId, $userId, $limit, $offset)`,
`findAllByView($viewId, $userId, $limit, $offset)`, `find($rowId)`,
`getRowsCount($tableId)`, `getViewRowsCount($view, $userId)`. Tables enforces
ownership/shares/contexts; a denied table/row surfaces as null/empty/thrown,
which the provider maps to **null (find) / omitted (findAll) / 0 (count)** — a
uniform 404 with no oracle distinguishing "denied" from "absent", matching the
other providers and ADR-005.

### D4 — Row → object mapping (`Row2`)
`Row2 = { id, tableId, createdBy, createdAt, lastEditBy, lastEditAt,
data: [{ columnId, value }, …] }`. Cells are keyed by **numeric `columnId`**, so
the provider first loads the table's columns via `ColumnService`, builds a
`columnId → propertyName` map (from column `technicalName`, falling back to a
slug of `title`), then projects each cell onto its property name. Row metadata is
mapped to `@self`/object fields: `id → object id (uuid)`, `createdAt →
@self.created`, `lastEditAt → @self.updated`, `createdBy → @self.owner`,
`lastEditBy → last-editor`.

### D5 — Column-type → JSON-schema mapping

| Tables column type (subtype)        | JSON-schema property                                   | Notes |
| ----------------------------------- | ------------------------------------------------------ | ----- |
| `text` (line / long / rich)         | `string`                                               | rich → string (markdown/html passes through) |
| `text` (link)                       | `string`, `format: uri`                                | |
| `number`                            | `number` (or `integer` when step is integral)          | `minimum`/`maximum` from `numberMin`/`numberMax` |
| `number` (progress)                 | `integer`, `minimum: 0`, `maximum: 100`                | |
| `number` (stars)                    | `integer`, `minimum: 0`, `maximum: 5`                  | |
| `datetime`                          | `string`, `format: date-time`                          | `format: date` / `time` for the date-only / time-only subtypes |
| `selection`                         | `string`, `enum: [...]`                                | options from the column definition |
| `selection` (check)                 | `boolean`                                              | |
| `selection` (multi)                 | `array`, `items: { enum: [...] }`                      | |
| `usergroup`                         | `array`, `items: { type: object }` `{ id, type }`      | user/group/circle references |
| `relation`                          | `string`, `format: uuid` (referenced object's UUID)    | derived from target table + rowId (D9); falls back to the raw rowId when the target schema is missing |
| column `mandatory: true`            | property added to schema `required[]`                  | |
| row `id`                            | object `id` / `uuid`                                   | metadata, not a column cell |
| `createdAt` / `lastEditAt`          | `@self.created` / `@self.updated`                      | metadata |
| `createdBy` / `lastEditBy`          | `@self.owner` / last-editor                            | metadata |

A **schema-seeder service** runs this table forward: given a `tableId`, it reads
`ColumnService` and emits the virtual schema `properties` block plus the
`x-openregister-object-source` config. The auto-seed machinery (D8) runs it for
**every** Tables table; it is also reusable standalone (occ sync, tests).

### D6 — Filtering, sort, pagination (v1)
`RowService` raw-table endpoints support only `limit`/`offset`. So:
- `limit`/`offset` from the query → passed natively to `findAllByTable` /
  `findAllByView`.
- Any other filter/sort operator → applied **provider-side in PHP** after fetch;
  when the provider must cap a fetch to satisfy a filter it cannot push down, it
  logs a warning (no silent truncation — per the interface contract).
- Optional `config.viewId` binds a Tables **View** (server-side filtered/sorted)
  instead of a raw `config.tableId`; then `findAllByView`/`getViewRowsCount` are
  used and the View's own filters apply server-side.

### D7 — Auto-seed all tables + semantic-map row
OpenRegister **auto-creates one virtual schema per Tables table** under the
`tables` virtual register:
- **Slug derivation (deterministic, idempotent):** `nc-<slug(title)>-t<tableId>`
  — the slugified table title for readability, suffixed with the numeric
  `tableId` so re-seeding is idempotent, renames are detectable, and two tables
  with the same title never collide.
- **Instance-global schemas, read-time visibility:** every seeded schema exists
  for all users; the provider's read path (D3) passes the acting `$userId` so
  Tables RBAC decides per user — no access ⇒ empty list / 404 find, identical to
  a non-existent schema's objects (no oracle). Enumeration of the schema
  *catalog* reveals table titles; accepted trade-off of the auto-seed decision,
  mitigated by the read-time gate on all data.
- **Semantic map:** add to `NcEntitySemanticMap::ENTITIES` a `tables` row shaped
  like the existing app-gated rows (`register` = `tables`, `provider` =
  `tables`, `requiredApp` = `tables`, `application` = `tables`). Because Tables
  hosts *many* tables (unlike Group = one entity kind), the row records the
  provider + app gate; the concrete per-table schemas come from the auto-seed.
- Manual authoring of a hand-written schema bound via `config.tableId`/`viewId`
  (e.g. a curated View projection) remains possible — the seeder never
  overwrites a schema it did not create.

### D8 — Sync mechanism (Repair + occ + event listeners)
Three complementary triggers keep seeded schemas in step with Tables — chosen
because Tables emits **no table-created and no column-changed event** (verified:
`OCA\Tables\Event\{RowAddedEvent, RowUpdatedEvent, RowDeletedEvent,
TableDeletedEvent}` — plus ownership-transfer), so live creation must not be
promised:
1. **Repair step** (install/upgrade, following the
   `SeedDirectoryVirtualSchemas`/`SeedAppVirtualSchemas` pattern): enumerate all
   Tables tables via `TableService`, run the schema-seeder for each, retire
   schemas whose table is gone. Idempotent; no-op when Tables is absent.
2. **`occ openregister:tables:sync`**: the same reconcile on demand — the
   admin's tool for picking up newly created tables and column changes between
   upgrades.
3. **Event listeners** (registered with `class_exists` guards so registration is
   safe without Tables): `TableDeletedEvent` → remove/retire the bound virtual
   schema immediately; `TableOwnershipTransferredEvent` → update schema
   ownership metadata if trivially applicable, else ignored (next sync
   reconciles).
4. **Opportunistic on-read refresh:** column drift is already handled on-read
   (the `columnId → name` map is rebuilt per read, unknown cells skipped +
   logged); when a read detects drift against the seeded schema, it flags the
   schema for refresh on the next sync. Honest limitation: a table created
   after the last sync has **no** schema until occ sync / upgrade repair runs.

### D9 — Relation columns → referenced object's UUID
A `relation` cell carries the referenced `rowId`; the target `tableId` comes
from the relation column's definition (no per-row lookup needed). The provider
maps the cell to the **referenced virtual object's UUID** so OR-level
deep-linking across virtual schemas works (`/api/objects/tables/<target-schema>/
<uuid>`):
- **Deterministic derivation (chosen over per-row lookups):** UUIDv5 over a
  fixed OpenRegister namespace UUID with name `tables:<tableId>:<rowId>`. Every
  virtual object's own uuid is derived the same way in `toObjectEntity()`, so a
  relation's UUID and the target object's uuid always agree — computed in O(1)
  from data already in hand, zero extra Tables calls.
- **Find by UUID:** UUIDv5 is one-way, so `find()` first tries the id as a
  numeric rowId (direct `RowService::find`); a UUID-shaped id is resolved by
  scanning the bound table's rows and comparing derived UUIDs — O(n) in table
  size, bounded and logged. Documented cost; acceptable because deep links are
  the secondary access path and v1 tables are modest.
- **Fallback:** when the referenced table's schema is missing (not yet seeded,
  table deleted, or Tables gone), the cell falls back to the raw integer rowId
  and a warning is logged — never a 500, never a dangling fabricated link.

## Seed Data (ADR-001)

Illustrative end-to-end example — a municipality's **"Speeltoestellen inspectie"**
(playground-equipment inspection) Tables table, auto-seeded into a virtual
schema by the D8 sync. All values are safe placeholders.

**Tables table (`tableId: <TABLE_ID>`), columns:**

| columnId | title (technicalName)      | type / subtype      | mandatory |
| -------- | -------------------------- | ------------------- | --------- |
| 101      | Toestel (`toestel`)        | text / line         | yes       |
| 102      | Locatie (`locatie`)        | text / line         | yes       |
| 103      | Inspectiedatum (`datum`)   | datetime / date     | yes       |
| 104      | Status (`status`)          | selection (enum)    | no        |
| 105      | Veiligheidsscore (`score`) | number / stars      | no        |
| 106      | Opmerkingen (`opmerking`)  | text / long         | no        |
| 107      | Inspecteur (`inspecteur`)  | usergroup           | no        |
| 108      | Contract (`contract`)      | relation → "Onderhoudscontracten" table | no |

**Auto-seeded virtual schema (excerpt) —** `register: tables`, `schema:
nc-speeltoestellen-inspectie-t<TABLE_ID>` (D7 slug derivation):

```jsonc
{
  "title": "Speeltoestellen inspectie",
  "x-schema-org": "schema:Action",
  "x-openregister-object-source": {
    "provider": "tables",
    "readOnly": true,
    "config": { "tableId": "<TABLE_ID>" }
  },
  "required": ["toestel", "locatie", "datum"],
  "properties": {
    "toestel":    { "type": "string" },
    "locatie":    { "type": "string" },
    "datum":      { "type": "string", "format": "date" },
    "status":     { "type": "string", "enum": ["open", "hersteld", "afgekeurd"] },
    "score":      { "type": "integer", "minimum": 0, "maximum": 5 },
    "opmerking":  { "type": "string" },
    "inspecteur": { "type": "array", "items": { "type": "object" } },
    "contract":   { "type": "string", "format": "uuid" }
  }
}
```

**Example virtual object returned by
`GET /api/objects/tables/nc-speeltoestellen-inspectie-t<TABLE_ID>/<ROW_ID>`:**

```jsonc
{
  "id": "<ROW_ID>",
  "toestel": "Schommel A3",
  "locatie": "Speeltuin Kerckebosch, Zeist",
  "datum": "2026-05-14",
  "status": "hersteld",
  "score": 4,
  "opmerking": "Ketting vervangen; volgende inspectie Q4.",
  "inspecteur": [{ "id": "<USER_ID>", "type": "user" }],
  "contract": "00000000-0000-0000-0000-000000000000",
  "@self": {
    "id": "<ROW_ID>",
    "uuid": "00000000-0000-0000-0000-000000000000",
    "register": "tables",
    "schema": "nc-speeltoestellen-inspectie-t<TABLE_ID>",
    "created": "2026-05-14T09:12:00+00:00",
    "updated": "2026-05-14T09:40:00+00:00",
    "owner": "<INSPECTOR_UID>"
  }
}
```

No row is written to any OR magic table — the object is built in memory from the
live Tables row on each read. The `uuid` and the `contract` relation value are
both UUIDv5-derived (D9) from `tables:<tableId>:<rowId>` — shown here as the nil
UUID placeholder.

## Error / edge handling

| Condition | Behaviour |
| --- | --- |
| Tables app missing / not installed | `class_exists` false ⇒ `resolveService` null; `isEnabled()` false ⇒ reads degrade to empty + one logged warning. No fatal, no DB fallback. |
| Tables app disabled for user | `isEnabledForUser('tables')` false ⇒ same as above. |
| Bound `tableId`/`viewId` deleted | `TableDeletedEvent` listener retires the seeded schema; until/if that fires, the Tables lookup throws/returns null ⇒ provider logs a warning and returns null (find) / empty (findAll) / 0 (count) — uniform 404. |
| Table created after last sync | No table-created event exists in Tables ⇒ no schema until `occ openregister:tables:sync` or upgrade repair runs (documented, not over-promised). |
| Relation target schema missing (unseeded / deleted / Tables gone) | Relation cell falls back to the raw integer rowId + logged warning — no fabricated link, no 500. |
| Permission denied (not owner/shared) | Tables returns null/empty for the acting user ⇒ denied == absent (no oracle). |
| Column drift (column dropped/renamed after binding) | The `columnId → name` map is rebuilt on every read; an unknown `columnId` in a cell is skipped + logged; a schema property with no matching column resolves to absent/null. No schema-change event exists in Tables, so drift is handled on-read only. |
| `columnId → name` collision (two columns slug to the same property) | Prefer `technicalName`; on collision, disambiguate with the numeric `columnId` suffix and log a warning so the binding stays deterministic. |
| Unsupported query operator | Applied provider-side in PHP; if a fetch must be capped, a warning is logged (no silent truncation). |
| Write attempt (`saveObject`/`deleteObject`) | Rejected by the existing `SaveObject`/`DeleteObject` read-only-projection guard before any persistence. |

## Out of scope (explicit)

- **Write-back** — the existing read-only-projection guard stays; Tables is
  authoritative. No create/update/delete path.
- **Facets / faceted search** over virtual objects.
- **Audit trail** (ADR-003) — virtual objects are not persisted, nothing to hash-chain.
- **OR-native relation expansion** — a `relation` column's value is surfaced as
  the referenced virtual object's UUID (D9), enabling deep links, but is NOT
  expanded into a full OR relation record.
- **Locking** — no lock state for virtual objects.
- **File attachments** — Tables attachment columns are out of v1 scope.
- **Row caching / cache invalidation** — v1 reads live on every request. The
  Tables `Row*Event` hooks for future row-cache invalidation are deferred; the
  event listeners in scope (D8) handle schema lifecycle only
  (`TableDeletedEvent`, optionally ownership transfer).

## Risks / mitigations
- **Hot-path cost** → the delegation guard is the existing in-memory schema-key
  check; the MagicMapper path for non-sourced schemas is byte-for-byte unchanged.
- **Tables latency / large tables on `findAll`** → native `limit`/`offset`
  pushed down; provider-side filtering caps + logs, never silently truncates.
- **No public Tables API (internal DI)** → guarded `class_exists`/app-enabled
  lookup, mirroring `DeckObjectSourceProvider`; a Tables upgrade that renames a
  service degrades to "disabled" rather than fatal, surfaced by unit tests.
- **RBAC leak** → every call is `$userId`-scoped and Tables enforces it; tests
  assert denied == absent. Auto-seeded schemas expose only table *titles* in the
  catalog; all data stays behind the read-time gate.
- **UUID find is O(n)** (UUIDv5 not invertible) → numeric rowId stays the
  primary access path; UUID resolution scans the one bound table, bounded +
  logged (D9).
- **Stale seed set** (no table-created event) → occ sync + upgrade repair +
  on-read drift flagging (D8); documented, not over-promised.
