# Design: Integration — Time Tracker

> Umbrella decisions apply
>
> **Cross-repo note**: file paths under `nextcloud-vue/src/...` or bare component names (`CnXxxTab`, `CnXxxCard`) are **expected locations** in the `@conduction/nextcloud-vue` shared library, not binding spec. The frontend implementation PR lands in that separate repo and MAY choose different paths.

## Approach

Thin wrapper. `TimeEntryService` delegates to Time Manager's Entry API. Link table tracks per-entry + per-object totals for fast dashboard rendering.

## Architecture Decisions

### AD-1: Backing app is configurable

**Decision**: Admin setting `time-tracker.backend` selects which NC time-tracking app to use (default `timemanager`). The service implements a thin adapter per backend.

**Why**: Multiple time-tracking apps exist in NC ecosystem; different customers prefer different ones. Locking to one limits adoption.

**Trade-off**: Adapter maintenance per backend. Acceptable — each adapter is small.

### AD-2: Totals denormalized into link table

**Decision**: Link table stores per-object hour total, recalculated on write. Dashboard fetches one row instead of aggregating many.

**Why**: Dashboards with 50 objects × N entries each would be O(N×M) otherwise. Denormalization keeps dashboard fast.

**Trade-off**: Slight risk of drift. Mitigated by periodic reconcile job.

## Files Affected

### Backend (new)
- `TimeEntryService`, per-backend adapters, `TimeController`, `TimeLink` entity + mapper + migration, `TimeProvider`, reconcile command, unit tests

### Backend (modified)
- `Application.php`, `routes.php`

### Frontend (new)
- `CnTimeTab/*`, `CnTimeCard/*`, `src/integrations/builtin/time-tracker.js`, barrels + tests

## Risks

| Risk | Mitigation |
|---|---|
| Backend app mismatched with admin expectation | Clear admin setting documentation; auto-detect common apps and suggest |
| Total drift vs reconciliation | Nightly occ command recalculates totals; sanity check before invoicing |
| Per-user privacy on totals | Optional per-user-only visibility setting; admins see all |
