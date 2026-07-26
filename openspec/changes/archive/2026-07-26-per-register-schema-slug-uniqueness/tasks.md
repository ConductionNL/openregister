# Tasks — Per-register schema slug uniqueness

Scope note: this change introduces no new OpenRegister schemas/registers, no
lifecycle/aggregation/notification/widget behaviour (ADR-031 does not apply), so
there is no seed-data task.

## 1. Backend — register-scoped import resolution

- [x] 1.1 `ImportHandler::importFromJson()` (`lib/Service/Configuration/ImportHandler.php`,
      ~:1791): before the two-pass schema import loop, compute
      `schemaSlug(lower) => int[]` — the union of the existing (pre-import)
      `getSchemas()` id lists of every register this import's raw
      `components.registers.*.schemas` declares for that slug. Registers that do
      not yet exist contribute an empty set.
- [x] 1.2 `ImportHandler::importSchema()` (~:1194): add an optional
      `?array $registerSchemaIds = null` parameter (appended last — no existing
      caller's positional args shift). When non-null, resolve the existing schema
      via `SchemaMapper::findBySlugInIds($slug, $registerSchemaIds)` ahead of the
      app-scoped/global branch. Not found → fall through to the existing create
      path (do not fall back to app/global lookup). Found → existing
      version-gate/content-diff update-in-place logic, unchanged. When null,
      previous app-scoped/global behaviour is unchanged (backward compatible).
- [x] 1.3 Thread `registerSchemaIds` from the precomputed map into both PASS 1 and
      PASS 2 `importSchema()` calls in `importFromJson()`.
- [x] 1.4 Keep the foreign-owner visibility log (info-level) when a same-slug row
      exists elsewhere but not in the target register's set — do not upgrade it to
      a warning/error, and do not use it to bind/reuse the foreign row.

## 2. Database migration

- [x] 2.1 New `lib/Migration/VersionXXXXDate.php`: idempotently drop
      `schemas_org_app_slug_unique` on `openregister_schemas` (checks table +
      index existence before dropping, mirroring
      `Version1Date20260723000000::widenSlugUniqueIndex()`'s guard style). Do
      **not** touch `registers_org_app_slug_unique` on `openregister_registers`.
      Do **not** add a replacement index (see design.md D4).
- [x] 2.2 Bump `appinfo/info.xml` `<version>` per repo convention (matches the new
      migration's date-based class name).

## 3. Tests (must prove the fix)

- [x] 3.1 Two different registers each import a schema with the same slug →
      two distinct schema rows, each attached to its own register (the core bug
      fix / two-registers-same-slug proof).
- [x] 3.2 Re-importing the same slug into the same register updates the existing
      schema in place — no duplicate row within that register.
- [x] 3.3 A schema already shared across multiple registers (present in more than
      one register's existing `schemas` id list) is left untouched by an
      unrelated import — regression guard for the 769-shared-schema case.
- [x] 3.4 `importSchema()` unit coverage: `registerSchemaIds` provided + found →
      update path; provided + not found → create path (does not reuse a foreign
      same-slug row); `null` → unchanged app-scoped/global fallback behaviour.
- [x] 3.5 Existing `ImportHandlerTest`/`ImportHandlerCoverageTest`/
      `ImportHandlerSluglessSkipTest`/`ImportHandlerRefResolverTest` suites still
      green (no regressions from the new optional parameter or the new
      `RegisterMapper::find()` precompute calls).

## 4. Quality gates

- [x] 4.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) clean on touched
      files, run in-container.
- [x] 4.2 Full PHPUnit run in-container; no new failures vs. baseline.
- [x] 4.3 SPDX headers + `@spec` tags on changed public methods
      (`openspec/changes/per-register-schema-slug-uniqueness/specs/data-import-export/spec.md`).

## Acceptance criteria

- Two registers (same app or different apps) can each import their own schema
  under a shared, generic slug without one clobbering or being silently bound to
  the other's row.
- Re-importing an app/register's own schema is idempotent (no duplicate growth).
- A schema shared by multiple registers today (769 rows, up to 6 registers each)
  is provably untouched by this change.
- No new DB unique index; `schemas_org_app_slug_unique` is dropped, idempotently.

## Quality reminders

- Do not use sed/awk/scripting to modify code files; use real edits.
- Fix pre-existing quality issues encountered along the way rather than leaving
  them.
- No PR, merge or release steps belong in this list.
- Repairing the OpenBuild vs. CRM `automation` schema collision itself is out of
  scope — a separate, post-merge re-import step.
