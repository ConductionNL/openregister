---
status: done
---

# tables-virtual-register Specification

## Purpose

Expose Nextcloud Tables tables as read-only **virtual registers**: each Tables table (or Tables View) becomes an auto-seeded Schema under a `tables` Register, and rows are served live through the existing object-source-provider seam (`x-openregister-object-source`, ADR-049 mechanism) — no copy or sync. Tables column types are mapped to JSON Schema (text/number/datetime/selection/usergroup; relation columns resolve to the referenced virtual object's deterministic UUIDv5), RBAC is delegated to Tables via the acting user (denied == absent, anti-oracle parity), the provider fails closed when the Tables app is missing/disabled, and writes are rejected as a read-only projection.

**OpenSpec changes**: [tables-object-source-provider](../../changes/archive/2026-07-09-tables-object-source-provider/) _(archived 2026-07-09)_

## Requirements

### Requirement: Tables object-source provider
The system SHALL provide a read-only `ObjectSourceProvider` with `getId()`
returning `tables` that serves a schema's objects live from a single Nextcloud
Tables table (or Tables View), returning non-persisted `ObjectEntity` instances
and never writing to OpenRegister storage. It SHALL integrate via the Tables
internal services (`OCA\Tables\Service\{RowService, ColumnService, TableService,
PermissionsService}`) guarded by `class_exists`, since Tables exposes no stable
public API.

#### Scenario: Bound schema returns live rows
- **GIVEN** a schema declaring `x-openregister-object-source` with `provider: tables` and `config.tableId` naming a table the acting user may read
- **WHEN** `GET /api/objects/{register}/{schema}` is requested
- **THEN** each Tables row is returned as a virtual object with cells mapped to schema properties by column
- **AND** no row is written to any OpenRegister magic table.
- `@e2e exclude` Requires the Tables app installed in the dev env (not present); covered by `TablesObjectSourceProviderTest` with Tables services mocked, and live-verified once Tables is installed.

#### Scenario: Single object find by row id
- **GIVEN** a schema bound to a Tables table holding row `<ROW_ID>`
- **WHEN** `find(register, schema, '<ROW_ID>')` is called for the acting user
- **THEN** it returns one `ObjectEntity` for that row with `@self.id`/`uuid` set from the row id.
- `@e2e exclude` Backend provider contract; unit-tested with Tables `RowService::find` mocked.

#### Scenario: Count reflects the bound table
- **GIVEN** a schema bound to a Tables table with N rows the acting user may read
- **WHEN** `count(register, schema, query)` is called
- **THEN** it returns N via `RowService::getRowsCount` (or `getViewRowsCount` for a View binding).
- `@e2e exclude` Backend provider contract; unit-tested.

### Requirement: RBAC parity and fail-closed absence
Tables reads SHALL pass the acting `$userId` to every Tables service call so
Tables enforces ownership/shares/contexts. A denied or absent table/row SHALL
yield a uniform not-found — `null` from `find`, omission from `findAll`, and not
counted — without distinguishing "denied" from "absent".

#### Scenario: Permission denied is indistinguishable from absent
- **GIVEN** a Tables row the acting user is not authorized to read
- **WHEN** `find` is called for it
- **THEN** it returns `null` (the read path translates this to a 404 identical to a non-existent id).
- `@e2e exclude` Backend authorization/anti-oracle; unit-tested with Tables returning null for the user.

#### Scenario: Deleted bound table degrades cleanly
- **GIVEN** a schema whose bound `config.tableId` no longer exists
- **WHEN** its objects are read
- **THEN** an empty result is returned and a warning is logged (no 500).
- `@e2e exclude` Backend degradation; unit-tested.

### Requirement: Provider disabled when Tables app absent
The provider SHALL report `isEnabled()` false when the Tables app is not
installed or not enabled for the acting user. Every reference to a Tables service
MUST be guarded by `class_exists` so no fatal occurs, and reads of a bound schema
SHALL degrade to an empty result plus a logged warning rather than erroring or
reading the database.

#### Scenario: Tables app disabled → fail-closed empty
- **GIVEN** the Tables app is not enabled on the instance
- **WHEN** `isEnabled()` is checked and a bound schema is read
- **THEN** `isEnabled()` returns false and the read returns empty with a logged warning (no 500, no DB fallback).
- `@e2e exclude` Backend capability gating; unit-tested with `IAppManager` reporting Tables absent.

### Requirement: Writes to a Tables-bound schema are rejected
The system SHALL reject create/update/delete on a schema declaring
`x-openregister-object-source` with `provider: tables`, throwing a
read-only-projection error before any persistence (the existing object-source
write-guard), keeping Tables authoritative.

#### Scenario: Saving a Tables-sourced object is rejected
- **GIVEN** a schema bound to the `tables` provider
- **WHEN** `ObjectService::saveObject()` is called for that schema
- **THEN** it throws a clear read-only-projection error and writes nothing.
- `@e2e exclude` Backend write-guard (inherited from object-source-providers); unit-tested.

### Requirement: Column-type to JSON-schema mapping
The provider SHALL resolve each Tables cell's numeric `columnId` to its property
name via `ColumnService` and map Tables column types onto schema property types:
text/line/long/rich → string; text/link → string with `format: uri`; number →
number or integer with `minimum`/`maximum` from the column bounds (progress
0-100, stars 0-5); datetime → string with `format` date-time/date/time;
selection → string enum; selection/check → boolean; selection/multi → array of
enum; usergroup → array of `{ id, type }`; relation → the referenced virtual
object's UUID (see the relation requirement). Mandatory columns SHALL be
reflected in the schema `required` list.

#### Scenario: Column types map to correct property types
- **GIVEN** a table with a stars-number column, a date-only datetime column, a single-select column, and a mandatory text column
- **WHEN** a row is projected to a virtual object and the schema is generated
- **THEN** the stars value is an integer 0-5, the date is a `format: date` string, the selection is one of its enum values, and the mandatory column's property appears in `required`.
- `@e2e exclude` Backend mapping logic; unit-tested per column type.

#### Scenario: Column drift and columnId collision handled on read
- **GIVEN** a bound table where one column was dropped and two columns slug to the same property name
- **WHEN** a row is read
- **THEN** the dropped column's cell is skipped and logged, and the collision is disambiguated by `columnId` and logged, so the projection stays deterministic without a 500.
- `@e2e exclude` Backend resilience; unit-tested.

### Requirement: Pagination, filtering, and optional View binding
The provider SHALL push `limit`/`offset` natively to `RowService`
(`findAllByTable` / `findAllByView`), apply any other query filters/sort
provider-side in PHP, and log a warning whenever it caps a fetch it cannot push
down (no silent truncation). A `config.viewId` SHALL bind a Tables View (with the
View's server-side filters/sort) instead of a raw `config.tableId`.

#### Scenario: limit/offset paginate the bound table
- **GIVEN** a schema bound to a Tables table with 50 readable rows
- **WHEN** `findAll` is called with `limit: 10, offset: 20`
- **THEN** rows 21-30 are returned via `RowService::findAllByTable(tableId, userId, 10, 20)`.
- `@e2e exclude` Backend pagination; unit-tested.

#### Scenario: viewId binds a Tables View
- **GIVEN** a schema declaring `config.viewId` instead of `config.tableId`
- **WHEN** its objects are read
- **THEN** rows are read via `findAllByView`/`getViewRowsCount` and the View's own server-side filters/sort apply.
- `@e2e exclude` Backend View binding; unit-tested.

### Requirement: Relation columns map to the referenced virtual object's UUID
A `relation` cell SHALL be mapped to the UUID of the referenced virtual object,
derived deterministically (UUIDv5 over a fixed OpenRegister namespace with name
`tables:<tableId>:<rowId>`) from the relation column's target table and the
referenced rowId — no per-row lookup. Every virtual object's own uuid SHALL be
derived the same way so relation links and object uuids always agree, enabling
OR-level deep-linking across virtual schemas. When the referenced table's schema
is missing, the cell SHALL fall back to the raw integer rowId with a logged
warning. `find()` SHALL accept both the raw numeric rowId and the derived UUID.

#### Scenario: Relation cell deep-links to the referenced virtual object
- **GIVEN** a row whose relation cell references row `<ROW_ID>` in a target table with an auto-seeded schema
- **WHEN** the row is projected to a virtual object
- **THEN** the relation property holds the UUID derived from the target table + `<ROW_ID>`
- **AND** finding that UUID in the target table's schema returns the referenced virtual object.
- `@e2e exclude` Backend relation derivation; unit-tested (derivation + round-trip with Tables services mocked).

#### Scenario: Missing target schema falls back to raw rowId
- **GIVEN** a relation cell whose target table has no seeded schema
- **WHEN** the row is projected
- **THEN** the relation property holds the raw integer rowId and a warning is logged (no 500, no fabricated link).
- `@e2e exclude` Backend fallback; unit-tested.

### Requirement: Auto-seeded virtual schemas for all Tables tables
The system SHALL auto-create one virtual schema per Nextcloud Tables table under
the `tables` virtual register, with a deterministic idempotent slug
(`nc-<slug(title)>-t<tableId>`), via (a) a Repair step on install/upgrade, (b) an
`occ openregister:tables:sync` command, and (c) a `TableDeletedEvent` listener
that retires the schema of a deleted table. Seeded schemas SHALL be
instance-global, with per-user visibility enforced at read time by Tables RBAC
(a user without access gets empty/404 — anti-oracle parity). Because Tables
emits no table-created or column-changed event, tables created after the last
sync SHALL NOT be expected to appear until the next occ sync or upgrade repair;
column drift SHALL be handled on-read. The system SHALL also add a `tables` row
to `NcEntitySemanticMap` gated on `requiredApp = tables` so the seeded schemas
participate in ADR-048 semantic resolution and the app-enabled gate.

#### Scenario: Sync seeds a schema per table
- **GIVEN** the Tables app holds two tables and no seeded schemas exist
- **WHEN** `occ openregister:tables:sync` (or the upgrade Repair step) runs
- **THEN** two virtual schemas exist under the `tables` register with deterministic slugs, each bound to its table via `x-openregister-object-source`
- **AND** re-running the sync changes nothing (idempotent).
- `@e2e exclude` Requires the Tables app in the dev env; unit-tested with `TableService` mocked.

#### Scenario: Deleted table retires its schema
- **GIVEN** a seeded schema bound to a Tables table
- **WHEN** the table is deleted and `TableDeletedEvent` fires
- **THEN** the bound virtual schema is removed/retired.
- `@e2e exclude` Backend event listener; unit-tested with a synthetic event.

#### Scenario: New table appears only after the next sync
- **GIVEN** a Tables table created after the last sync
- **WHEN** no sync has run since
- **THEN** no schema exists for it yet, and running `occ openregister:tables:sync` seeds it.
- `@e2e exclude` Backend sync semantics (no table-created event in Tables); unit-tested.

#### Scenario: Instance-global schema, per-user read-time visibility
- **GIVEN** an auto-seeded schema for a table shared with user A but not user B
- **WHEN** both users list its objects
- **THEN** user A gets the rows and user B gets an empty result / 404 on find, indistinguishable from a table with no rows.
- `@e2e exclude` Read-time RBAC gate; unit-tested with Tables returning per-user results.

#### Scenario: Tables binding participates in the app-enabled gate
- **GIVEN** the `tables` semantic-map row with `requiredApp: tables`
- **WHEN** the Tables app is uninstalled
- **THEN** the ADR-048 app-enabled gate degrades the Tables-bound virtual schemas (no objects served), consistent with the other app-gated rows.
- `@e2e exclude` Backend semantic-map gating; unit-tested.
