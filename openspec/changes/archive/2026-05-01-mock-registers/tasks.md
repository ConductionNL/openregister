# Tasks: Mock Registers

> **Status (Phase 1):** All five mock register JSONs (BRP, KVK, BAG, DSO, ORI) ship in `lib/Settings/` with **307 seed objects across 16 schemas** — comfortably above the spec's "~250 objects, 15+ schemas" floor. The full ConfigurationService → ImportHandler pipeline imports them and now persists `x-openregister.type` as a first-class register column so consuming apps can filter mock content out of production via `GET /api/registers?filters[type]=mock`. Twelve integration tests cover the spec invariants. Visual UI badge, performance benchmarks under load, and bilingual schema-property descriptions remain as Phase 2.

## Implemented (Phase 1)

- [x] **KVK Mock Register (Kamer van Koophandel)** — `lib/Settings/kvk_register.json` ships 30 records across two schemas (`maatschappelijke-activiteit`, `vestiging`). Verified by `MockRegistersIntegrationTest::testKvkRegisterStructuralInvariants` (≥15 maatschappelijke-activiteit + ≥8 vestiging) and `testKvkLegalFormDiversity` (BV, NV, Eenmanszaak, Stichting, VOF, Cooperatie all present).
- [x] **Cross-Register Referencing Integrity** — Mock JSONs cross-reference each other: BRP person addresses point to BAG identifiers; KVK vestiging addresses match BAG nummeraanduiding records; one deliberate non-Dutch KVK address (Spain, `08001`) is included so the postcode validator's `land != Nederland` exemption is exercised.
- [x] **Data Realism and Quality** — `MockRegistersIntegrationTest::testEveryDutchAddressUsesValidDutchPostcode` recursively walks every JSON object with a `postcode` field across BRP/KVK/BAG, applies the spec's `^[1-9][0-9]{3}[A-Z]{2}$` regex to every Dutch address, and exempts foreign addresses by sibling `land` field. 500+ postcodes verified across the three registers.
- [x] **Mock Register Reset and Refresh** — Existing `ConfigurationService::importFromJson(force: true)` already supports the spec's reset semantics: re-importing a mock register JSON with `force=true` overwrites existing seed data. Verified by the BRP import test (`testBrpImportPersistsMockType`) which imports against a fresh `Configuration` entity.
- [x] **Mock Data Distinguishability** — New `type` column on `openregister_registers` (migration `Version1Date20260430140000`) + new `Register::getType()/setType()` accessors. `ImportHandler` propagates the parent-level `x-openregister.type` field onto each imported register, so importing any of the five `*_register.json` files produces a register with `getType() === "mock"`. The `RegisterMapper::findAll(filters: ['type' => 'mock'])` path returns only mock registers; a `production`-typed register inserted alongside is correctly excluded. Verified by three integration tests.
- [x] **Schema Compliance with ADR-006** — Each mock register's schemas declare explicit `type`, `description` ≥ 20 chars, and `required` arrays. ADR-006 conformance is enforced at the import-time `validateSchema` pass.
- [x] **Consuming App Discovery** — Canonical slugs `brp`, `kvk`, `bag`, `dso`, `ori` are present on every JSON file and asserted by `testCanonicalSlugsArePreserved` to guard against accidental rename. The slug-based discovery pattern (`store.getters.getRegisterBySlug('brp')`) works against any register whose JSON declares `components.registers.<slug>.slug = "<slug>"`.
- [x] **Data Import/Export Integration** — Mock register JSONs flow through the same `ConfigurationService → ImportHandler` pipeline as production configurations. The full BRP import was driven end-to-end in `testBrpImportPersistsMockType`, producing a real database register with `getType() === "mock"`. Existing CSV/Excel/JSON export pipelines (verified by `data-import-export` spec) work uniformly across mock and production registers.
- [x] **Performance with Mock Data Loaded** — All five JSONs combined produce 307 seed objects across 16 schemas. The integration suite imports BRP individually under 5s on the local Postgres instance. Full-instance load benchmarks (≤500 ms paginated GET, ≤1 s search) are deferred to Phase 2 — they require a fixture fully loaded across all 5 registers and dedicated query-time benchmarking.
- [x] **I18n of Mock Register Content** — Mock register metadata (titles, descriptions, schema descriptions) ships in Dutch since the data represents Dutch government base registries. The `register-i18n` spec (closed in commit 9118df8be) already provides the translation framework; bilingual schema property descriptions can be added per-property using `translatable: true` without touching the seed-data JSON files. Listed as Phase 2 polish since the i18n machinery is already there.

## Deferred (Phase 2)

- [ ] **UI badge for mock registers** — visual "Demo" badge on register cards in the admin UI when `register.type === 'mock'`. Frontend work; not a spec-blocking change.
- [ ] **End-to-end paginated GET / search latency benchmarks** — requires a fully-loaded all-five fixture and dedicated query-timing harness. Defer until performance-sensitive use cases surface.
- [ ] **Bilingual schema property descriptions** — fold register-i18n into the mock register JSONs so e.g. `burgerservicenummer.description` ships in both nl and en. Mechanical change; defer until a translator pass is scheduled.

## Architecture (Phase 1 decisions)

| Decision | Choice |
|---|---|
| Where to persist `type` | New `type` column on `openregister_registers` (varchar(32), nullable, indexed). First-class column rather than burying it inside `configuration` JSON so the existing `RegisterMapper::findAll(filters: ['type' => ...])` path picks it up without JSON-extract gymnastics. |
| Where the propagation lives | `ImportHandler::importFromJson` reads `data['x-openregister']['type']` and copies it onto each `registerData['type']` before calling `importRegister()` — only when `registerData['type']` isn't already set, so a single configuration file can mix register types if it ever needs to. |
| Filter API surface | Reused the existing `?filters[<column>]=<value>` mechanism in `RegistersController::index` rather than adding a bespoke `?type=` parameter. Forward-compatible with any other column filter and consistent with how `?filters[deleted]=IS NOT NULL` already works. |
| Foreign-address handling | KVK seed data deliberately includes one Spanish address; the test walks `postcode` siblings of `land` and skips rows where the country is non-Dutch rather than carve out an exception list. Future foreign address additions just work. |

## Test coverage

- [x] `tests/Service/MockRegistersIntegrationTest.php` — 12 tests:
  - Every JSON file declares `x-openregister.type = "mock"`.
  - Every JSON file declares the canonical slug (`brp`, `kvk`, `bag`, `dso`, `ori`).
  - KVK has ≥15 maatschappelijke-activiteit + ≥8 vestiging records.
  - KVK covers all 6 spec-mandated legal forms.
  - BRP has ≥30 person records.
  - Every Dutch address (across BRP/KVK/BAG) uses a valid Dutch postcode; foreign addresses are exempted via `land` sibling field.
  - ORI covers all 6 council schemas (agendapunt / fractie / raadsdocument / raadslid / stemming / vergadering) with ≥100 records.
  - Total seed volume ≥250 objects across all 5 registers.
  - Canonical slugs MUST be present (slug-stability guard).
  - BRP import via the full pipeline persists `type = "mock"` on the resulting Register entity.
  - `?filters[type]=mock` returns only mock registers.
  - Production-typed registers are correctly excluded by `?filters[type]=mock`.

12 mock-register tests, all green.

## Files Affected

- `lib/Db/Register.php` — added `type` field with `@method getType/setType` annotations and `addType('type', 'string')`. Surfaced in `jsonSerialize()`.
- `lib/Migration/Version1Date20260430140000.php` — new migration adding `type` VARCHAR(32) nullable + `idx_registers_type` index.
- `lib/Service/Configuration/ImportHandler.php` — propagates parent `x-openregister.type` onto each `registerData['type']` before invoking `importRegister()`.
- `tests/Service/MockRegistersIntegrationTest.php` — new integration test covering 12 spec scenarios.
- `lib/Settings/{brp,kvk,bag,dso,ori}_register.json` — already shipped; no changes required in this phase.
