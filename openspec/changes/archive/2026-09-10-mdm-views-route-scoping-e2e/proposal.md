## Why

The MDM "Data quality" steward views (ADR-045 #3 / #C / #E) share a
`RegisterSchemaSelector` that commits the selected (register, schema) pair to
the `quality` Pinia store, but nothing is reflected into the URL. A steward
cannot bookmark or share `Data Quality for register X / schema Y`, and — more
consequentially — the committed e2e specs cannot deterministically land on a
known scored dataset. They drive the selector, pick "the first option", and
then `test.skip()` whenever the environment has no survivorship/duplicate
data, so the duplicate→merge→reverse and conflict-resolution chains never
actually run in CI.

This change makes the selector **route-scoped** (deep-linkable) and gives the
committed specs a **self-seeding fixture** so they RUN their full chains
against real, known data instead of asserting empty states and skipping. It is
the durable enabler for a true Playwright e2e over the MDM surface.

## What Changes

- `RegisterSchemaSelector.vue`: on mount, an explicit `?register=` (and
  optional `?schema=`) route query pre-selects and loads with no clicks and
  wins over the persisted store selection; every in-UI selection change mirrors
  back into the hash-mode route via `$router.replace`. The store stays the
  source of truth; the route is a mirror + entry point. Stable `data-testid`s
  are added to the two selects and the key action controls the specs click
  (merge launch, reverse, view-golden-record, resolve-conflicts, merge confirm
  + reason, conflict source picker + save).
- `tests/e2e/mdm-seed.ts` (new): a Playwright `APIRequestContext` seeder that
  discovers the `pipelinq` register + `masterEntity` schema, idempotently seeds
  a duplicate pair, a multi-source conflict entity, and a few scored entities
  through the real OR REST API, verifies the pair is detectable, and writes the
  ids to `tests/e2e/.mdm-seed.json`. Hooked into `globalSetup`; guarded so a
  non-pipelinq instance no-ops.
- The three committed MDM spec suites read `.mdm-seed.json` and, when present,
  deep-link route-scoped and run the full chains; absent a seed they keep their
  `test.skip()` fallback. Pre-existing navigation bugs (non-hash `/quality`
  paths that 404, the wrong `/masterEntities` route) are fixed to hash-mode.

## Capabilities

### New Capabilities
- `mdm-views-route-scoping`: the MDM Data-quality views are route-scoped and
  deep-linkable — `#/quality?register=<id>&schema=<id>` auto-selects and loads,
  and selection changes mirror into the URL; the selects expose stable test
  handles.

### Modified Capabilities
<!-- None. mdm-frontend / mdm-merge-ui / mdm-survivorship-override spec-level
     requirements are unchanged. Their e2e spec-coverage files are re-pointed
     to hash-mode routes + the seeded fixture (test-harness change only), and
     RegisterSchemaSelector keeps satisfying selection-persists-across-mdm-views. -->

## Impact

- **Frontend:** `src/views/quality/RegisterSchemaSelector.vue` (route sync +
  testids); `data-testid`s added to `DuplicatesIndex.vue`,
  `MasterEntitiesIndex.vue`, `MergeOperationsIndex.vue`,
  `GoldenRecordDetail.vue`, `modals/mdm/MdmMergeWizardModal.vue`,
  `modals/mdm/MdmConflictResolutionModal.vue`. No store or backend change.
- **Tests:** new `tests/e2e/mdm-seed.ts`; `tests/e2e/global-setup.ts` hook;
  the three `tests/e2e/spec-coverage/mdm-*.spec.ts` suites re-pointed to
  hash-mode + seed-driven chains. `tests/e2e/.mdm-seed.json` is a generated
  artefact (git-ignored).
- **Routing:** hash-mode router (`src/main.js`) — query params are parsed from
  the fragment; no server route change.
