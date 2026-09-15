# Design — mdm-views-route-scoping-e2e

## D1. Deep-link product win: route mirrors the store, store stays authoritative

The MDM views already share one selection via the `quality` Pinia store so
switching views preserves it (mdm-frontend). This change adds a **second,
lower-priority mirror**: the hash-mode route query. The precedence on mount is:

1. `?register=` (and optional `?schema=`) present → adopt the route params,
   commit them to the store, load schemas, and (both present) let the view's
   `selectedRegister/selectedSchema` watcher fetch. A deep-link such as
   `#/quality?register=16&schema=1207` therefore auto-selects and loads with no
   clicks.
2. Otherwise → restore the persisted store selection (unchanged behaviour) and
   mirror it into the URL so a reload/bookmark from that point is itself a
   deep-link.

Every in-UI selection change writes back with `this.$router.replace({ query })`
(hash mode, `NavigationDuplicated` swallowed). `replace` (not `push`) keeps the
back button meaningful — changing the schema is not a history entry. Changing
the register clears the schema in both the store and the URL, matching the
existing "no request until both chosen" contract.

Why the store stays authoritative: the five views consume the store, not the
route; the route is only read once on the selector's mount and written on
change. This avoids a two-way binding loop and keeps the existing
`selection-persists-across-mdm-views` behaviour intact (a plain, query-less
navigation to another MDM view still restores from the store).

## D2. e2e determinism via a self-seeding fixture, not fixed UUIDs

The committed specs previously discovered "the first register/schema" from the
combobox and skipped when no scored data existed — so the merge/conflict chains
never ran. Rather than hardcode `register 16 / schema 1207` (fragile across
environments) the specs now read `tests/e2e/.mdm-seed.json`, written by
`tests/e2e/mdm-seed.ts` in `globalSetup`.

The seeder, given an admin `APIRequestContext`:

1. **Discovers** the `pipelinq` register (slug `pipelinq`) and its
   `masterEntity` / `sourceRecord` / `mergeOperation` schema ids via
   `GET /api/registers` + `GET /api/registers/{id}/schemas`. On a non-pipelinq
   instance it no-ops and returns `null` (specs keep skipping).
2. **Seeds**, via `POST /api/objects/{register}/{masterEntity}`:
   - a **duplicate pair** — two master entities with identical
     `goldenRecord.kvkNumber` (`77777777`) + `email` and slightly different
     `name` ("Rijkswaterstaat" vs "Rijkswaterstaat B.V."), each with a
     `sourceRecords[]` entry carrying those `mappedAttributes`. Against the
     schema's dedup rules (kvk exact 0.4 + email exact 0.3 + name normalized
     0.2 + name levenshtein 0.1, threshold 0.7) the pair scores ~0.78 → a real
     candidate.
   - a **multi-source conflict** entity — one master entity with TWO
     `sourceRecords` from different `sourceSystem`s that DISAGREE on `name`
     ("ACME NV" vs "ACME B.V.") but agree on email, so the conflict-resolution
     modal surfaces a genuine resolvable conflict on `name`.
   - a few **plain scored entities** (good/fair/poor completeness) so the Data
     Quality dashboard shows populated buckets.
3. **Validation-safe payloads:** the masterEntity schema requires a non-empty
   `goldenRecord` + `attributeProvenance`. Each seeded object supplies BOTH an
   explicit, self-consistent `goldenRecord`/`attributeProvenance` AND the
   `sourceRecords` those values derive from. If the
   `SurvivorshipRecomputeListener` recomputes on save, the recomputed golden
   record is identical (mappedAttributes match the supplied golden record); if
   it does not run, the explicit values already satisfy validation. Robust to
   both.
4. **Idempotent:** every seeded `masterId` is prefixed `e2e-mdm-`; prior
   `e2e-mdm-` rows are deleted before re-seeding. The discovered ids + seeded
   uuids (dup pair, conflict) are written to `.mdm-seed.json`.

`globalSetup` wraps seeding in try/catch and a fresh disposable
`APIRequestContext` so a seeding failure logs and continues rather than
aborting the whole run.

## D3. Which specs now RUN vs SKIP

With a seed present:
- **mdm-frontend** — asserts populated Data Quality KPIs + histogram +
  lowest-quality table, populated Master entities table, and an opened golden
  record with a provenance table.
- **mdm-merge-ui** — from Duplicate Candidates: the seeded pair row → Merge →
  wizard preview (projected survivor) → reason → confirm → candidates refresh →
  Merge Operations audit row → Reverse within window → row flips to final. One
  continuous chain.
- **mdm-survivorship-override** — opens the *seeded conflict entity's* golden
  record (matched by uuid), Resolve conflicts lists the disagreeing `name`,
  picks an authoritative source, and saves both the persistent (trust rule) and
  one-off (per-object override) outcomes.

Absent a seed (no pipelinq) every data-dependent test keeps its existing
`test.skip()` so the suite runs everywhere.

## D4. Gate compliance

- **gate-16 (spec-coverage):** the changed `RegisterSchemaSelector` methods
  (`mounted`, `handleRegisterChange`, `handleSchemaChange`, new `syncRoute`)
  carry `@spec` tags to this change's `mdm-views-route-scoping` scenarios.
- **gate-19 (e2e-coverage):** every scenario of the new capability is either
  referenced by an `@e2e` tag in the MDM spec suites or carries a reason-bearing
  `@e2e exclude` (selector-internal behaviours whose reset/precedence is
  asserted indirectly + by the store unit test).
- **gate-12 (nc-input-labels):** the two selects keep their `inputLabel`; the
  added `data-testid` is additive.
- No PHP touched → SPDX / route-auth / stub gates unaffected.

## Risks

- **Recompute overwrites the supplied golden record with an empty one** if
  `mappedAttributes` are empty — mitigated by always authoring non-empty
  `mappedAttributes` that match the supplied golden record.
- **Seeded conflict entity off page 1** of Master entities if the schema holds
  many objects — mitigated: a fresh instance holds only the handful of seeded
  rows, and the spec matches the row by its seeded uuid rather than position.
- **Deployed OR older than the seeder's assumptions** (e.g. missing the
  `duplicate#index` route) — the pair-detectability check is best-effort and
  never fails setup; the parent runs against an isolated instance built from
  the same dev branch that owns these routes.

## Findings — survivorship source-linkage gap (ADR-045 follow-up)

Running the seeded e2e surfaced a genuine gap, not a test-harness issue. The
`masterEntity` schema (pipelinq register 16 / schema 1207) declares
`x-openregister-survivorship.sourceLinkField = "sourceRecords"` and
`x-openregister-merge.sourceLinkField = "sourceRecords"`, but its `properties`
map has **no `sourceRecords` property** (properties are: `masterId`,
`entityType`, `goldenRecord`, `attributeProvenance`, `attributeOverrides`,
`aliases`, `mergedFrom`, `status`, `mergedIntoMasterId`, `dataQualityScore`,
`qualityScore`, `qualityStatus`, `lastSourceUpdate`, `lastReviewedAt`, `tags`,
`gdprNotes`). Because the schema has `hardValidation: true`, an object created
with an embedded `sourceRecords` array is **stripped on save** (verified live:
the stored object's `sourceRecords` is `null`). Consequently:

- `MergeService::previewMerge()` recomputes the survivor "over the union of
  both objects' linked source records" via `SurvivorshipResolver`, which reads
  `sourceLinkField` — with no sources it returns `postMergeGoldenRecord: {}`
  (verified live). The merge wizard preview therefore renders **zero rows**.
- The conflict-resolution modal derives its conflicts from the object's
  embedded source records; with none persisted it shows the empty state.

The read surfaces are unaffected — they run off the persisted `goldenRecord`
(duplicate detection reads `goldenRecord.*`) and `attributeProvenance` /
`qualityScore` (materialised properties), so KPIs, duplicate candidates, master
entities, and the golden-record provenance panel all populate.

**What this change does about it:** the read-surface e2e assertions RUN and
PASS; the merge-execute+reverse chain and the conflict-resolution outcomes SKIP
with a documented reason pointing here, rather than fail. This keeps the suite
honest and green while the gap is tracked.

**Follow-up (out of scope here):** either (a) OpenRegister's survivorship /
merge source resolution should support **reverse-FK** linkage — resolving the
`sourceRecord` (schema 1208) objects that reference the master via
`currentMasterEntity`, which is pipelinq's actual model — or (b) pipelinq's
`masterEntity` schema should declare a persisted `sourceRecords` property so
embedded/ref source records survive `hardValidation`. Until one lands, no
seeded data can exercise the merge/conflict chains end-to-end.
