# Tasks: tables-object-source-provider

## 1. Provider class
- [x] 1.1 Add `lib/Service/ObjectSource/TablesObjectSourceProvider.php` implementing `ObjectSourceProvider` (`getId()` → `tables`), modelled on `GroupObjectSourceProvider` (no-persist `toObjectEntity`) and `DeckObjectSourceProvider` (FQCN string constants + guarded `resolveService`).
- [x] 1.2 `isEnabled()` → `IAppManager::isEnabledForUser('tables')`, wrapped so a missing app or thrown lookup returns false; `resolveService(FQCN)` returns null when `class_exists` is false.
- [x] 1.3 `find()`/`findAll()`/`count()` call `RowService`/`ColumnService` with the acting `$userId`; map denied/absent/thrown to null / omitted / 0 (uniform 404, no oracle).

## 2. Row and column mapping
- [x] 2.1 Build the `columnId → propertyName` map from `ColumnService` (`technicalName`, slug of `title` fallback; disambiguate collisions by `columnId` + log).
- [x] 2.2 Project each `Row2` cell to its property; map row metadata (`id`→uuid, `createdAt`/`lastEditAt`/`createdBy`/`lastEditBy`→`@self`); skip + log unknown `columnId` (column drift).
- [x] 2.3 Implement the column-type → JSON-schema mapping (design D5 table): text/number/datetime/selection/usergroup subtypes; `mandatory` → schema `required`.
- [x] 2.4 Relation columns + object identity (design D9): derive every virtual object's uuid as UUIDv5 over the OR namespace with name `tables:<tableId>:<rowId>`; map relation cells to the referenced object's derived UUID (target tableId from the column definition, no per-row lookup); fall back to the raw rowId + log when the target schema is missing; `find()` accepts numeric rowId natively and a UUID via a bounded, logged scan.
- [x] 2.5 Schema-seeder service: given a `tableId`, emit the virtual schema (`properties`, `required`, deterministic slug `nc-<slug(title)>-t<tableId>`, `x-openregister-object-source` config) from the table's columns; never overwrite a schema the seeder did not create.

## 3. Query handling
- [x] 3.1 Push `limit`/`offset` natively to `findAllByTable`/`findAllByView`; apply other filters/sort provider-side in PHP; log a warning when capping a fetch (no silent truncation).
- [x] 3.2 Support optional `config.viewId` binding a Tables View (`findAllByView`/`getViewRowsCount`) instead of raw `config.tableId`.

## 4. Registration, semantic map + sync (design D7/D8)
- [x] 4.1 Register the provider in `lib/AppInfo/Application.php` (`registerObjectSourceProviders` / `registerObjectSourceProviderInstances` / `bootObjectSourceProviders`).
- [x] 4.2 Add a `tables` row to `NcEntitySemanticMap::ENTITIES` gated on `requiredApp: tables` (register `tables`, provider `tables`, application `tables`).
- [x] 4.3 Auto-seed Repair step (following `SeedDirectoryVirtualSchemas`/`SeedAppVirtualSchemas`): enumerate all tables via `TableService`, run the seeder per table, retire schemas whose table is gone; idempotent; no-op when Tables is absent.
- [x] 4.4 `occ openregister:tables:sync` command running the same reconcile on demand (the only way new tables appear — Tables emits no table-created event).
- [x] 4.5 Tables event listeners registered with `class_exists` guards: `TableDeletedEvent` → remove/retire the bound schema; `TableOwnershipTransferredEvent` only if trivially applicable (else next sync reconciles).

## 5. Tests
- [x] 5.1 Add `tests/Unit/Service/ObjectSource/TablesObjectSourceProviderTest.php` with the Tables services (`RowService`/`ColumnService`/`IAppManager`) mocked (repo mock conventions).
- [x] 5.2 Cover: row→object mapping, each column-type mapping, find by rowId and by derived UUID, relation→UUID derivation + missing-target fallback, count, limit/offset, viewId binding, denied==absent, per-user read-time visibility, disabled-when-Tables-absent, column drift + columnId collision, seeder idempotency + deterministic slugs, TableDeletedEvent retirement, occ sync reconcile (`TableService` mocked).

## 6. Verify
- [x] 6.1 `openspec validate tables-object-source-provider --strict`; `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) clean on new code; Hydra gates (spdx + `@spec` on new methods) pass.

## Acceptance criteria
- Running the Repair step or `occ openregister:tables:sync` seeds one virtual schema per Tables table under the `tables` register (deterministic slug `nc-<slug(title)>-t<tableId>`, idempotent re-run), each carrying `x-openregister-object-source: {provider:"tables", readOnly:true, config:{tableId}}`.
- A seeded schema serves its table's rows live via `GET /api/objects/{register}/{schema}` with cells mapped to properties and no OR magic-table row written; schemas are instance-global with per-user visibility enforced at read time (no access ⇒ empty/404, anti-oracle).
- `find` by rowId or derived UUID, `count`, and `limit`/`offset` pagination resolve through `RowService` for the acting user; a `config.viewId` binds a Tables View instead.
- Relation cells carry the referenced virtual object's UUIDv5-derived UUID (deep-linkable in the target schema) and fall back to the raw rowId + log when the target schema is missing.
- `TableDeletedEvent` retires the bound schema; a table created after the last sync gets its schema only via occ sync / upgrade repair (Tables emits no table-created event — do not promise live creation).
- Permission-denied and absent are indistinguishable (uniform 404); with Tables uninstalled the provider is disabled and reads fail-closed to empty + a logged warning (no 500, no DB fallback).
- Write attempts on a Tables-bound schema are rejected by the existing read-only-projection guard.
- Column-type mapping matches the design D5 table; column drift and `columnId` collisions are handled on read without a 500.

## Quality reminders
- Tables is a soft dependency NOT installed in the dev env — every `OCA\Tables\Service\*` and `OCA\Tables\Event\*` reference (provider, seeder, listeners) MUST be guarded by `class_exists` + app-enabled; no hard dependency in `info.xml`.
- Do NOT touch the object-source interface, registry, read-path delegation, or write-guard — all reused unchanged.
- SPDX + `@spec` tags in every new file's docblock (ADR conventions); no `sed`/`awk`/scripted edits.
- E2E through the object API is gated on installing the Tables app in the dev env; until then coverage is unit tests with Tables services mocked (`@e2e exclude` on each scenario).
- Out of scope (do not implement): write-back, facets, audit, OR-native relation expansion (UUID deep-link only), locking, files, row caching / `Row*Event`-based invalidation (in-scope listeners are schema-lifecycle only).
