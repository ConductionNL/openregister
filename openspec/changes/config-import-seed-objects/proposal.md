---
kind: code
---

## Why

App register-seed objects placed in a register JSON's **top-level `objects` array**
are silently dropped on import: OpenRegister's `ImportHandler::importFromJson()`
only ever reads `data['components']['objects']`, never the top-level `objects` key.
shillinq ships 78 seed objects (e.g. `SalesOrderLine`) in the top-level `objects`
array — its schemas import correctly, but **0** objects materialise. This blocks
every app that authored seed data the way the export format reads (top-level) and
undermines the "ship an app with starter data" path (ADR-031 declarative apps).

## What Changes

- **Accept the top-level `objects` array as a seed-object source.** When a
  configuration JSON carries a top-level `objects` array (and not / in addition to
  `components.objects`), `ImportHandler::importFromJson()` SHALL fold it into the
  same object-import loop that already handles `components.objects`. `components.objects`
  remains the canonical export location; the top-level array becomes an accepted
  equivalent so seed bundles authored either way import identically.
- **Idempotent re-import.** Seed objects MUST match existing objects by their
  `@self` identity (slug within register+schema, falling back to `@self.id`/uuid)
  so a second import updates-or-skips rather than duplicating — reusing the existing
  search-by-(register, schema, slug) + uuid path already in the loop.
- **Observability.** Folded top-level objects are counted in the same `result`
  counters (`objects`, `skipped.objects`) as `components.objects`, so callers and
  tests see them.
- **No change** to the slug requirement, the per-entity resilience try/catch, the
  no-user-context acting-user resolution, or `@ref` seed-token resolution — those
  already work; this change only makes the loop *see* top-level objects.

This is **backward-compatible**: apps using `components.objects` are unaffected
(that branch is untouched); apps using the top-level array start importing.

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `import-resilient-per-entity-and-no-user-context`: the object-import phase of
  `importFromJson()` gains a requirement that a top-level `objects` array is an
  accepted seed-object source (merged with `components.objects`), imported
  idempotently by `@self` identity. (This capability already owns the object-import
  loop's resilience/no-user-context behaviour, so the seed-source requirement lands
  here.)

## Impact

- **Code:** `lib/Service/Configuration/ImportHandler.php` — `importFromJson()`
  normalises top-level `objects` into the existing `components.objects` loop (a small
  merge before the loop at ~line 1960). No new method signatures; `ConfigurationService`
  and `AppHostSettingsService::loadConfiguration()` (`importFromApp` → forced path)
  are unchanged.
- **Tests:** `tests/Unit/Service/Configuration/` — a test proving top-level seed
  objects materialise after import, and that re-import does not duplicate.
- **Consumers:** shillinq's 78 seed objects (and any app authoring top-level
  `objects`) begin importing. **No app-side change is required** — the fix is purely
  in OpenRegister (see design.md "Where the fix lands").
- **No** DB, API, route, or dependency changes.
