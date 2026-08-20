# Design — retrofit-2026-05-24-actions

**Retrofit change. Tasks describe retroactive annotation, not new implementation work.**

## Why a second retrofit pass on the same capability?

The first pass (`retrofit-2026-05-01-actions`) explicitly deferred two follow-ups in its Notes section:
- The dry-run **test endpoint** (`ActionService::testAction()` + `ActionsController::test()`)
- The **hook-migration utility** (`ActionService::migrateFromHooks()` + `ActionsController::migrateFromHooks()`)

The Phase-2 coverage scan additionally surfaced three uncovered sub-behaviors that the first pass touched only obliquely:
- `requireAdmin()` body (defence-in-depth admin gate)
- `logs()` endpoint (paginated log retrieval + statistics aggregation)
- `index()` pagination/filter/search semantics (REQ-001 references the endpoint but does not pin down `_page`/`_limit`/`_offset`/`_search` behavior, the whitelisted `filterableFields` set, or the dual-query `total` count)

The cluster has 96 entries; this pass picks the 10 most-cohesive method bodies inside the actual `actions` capability and DROPs the 78+ `TextExtraction/*` siblings (most of which were already DROP-triaged in the JSON).

## Granularity choices

- **5 REQs, 6 methods directly annotated**. `testAction` + `test`, and `migrateFromHooks` (service) + `migrateFromHooks` (controller) each pair up under one REQ — the controller is a thin pass-through.
- One REQ per **observable behavior**, not per method. REQ-006 (admin gating) covers the gate helper; the per-method-level admin enforcement is documented in the REQ text rather than minted as separate REQs.
- REQ-010 splits OFF from REQ-001. REQ-001 already covers "list with filters and pagination" at the surface level; REQ-010 pins down the exact field whitelist, the `_page` vs `_offset` precedence, the post-page search ordering quirk, and the dual-query total-count strategy.

## Observed-but-flagged behavior (notes, not fixes)

- **REQ-007/008 do not invoke the workflow engine** — confirmed by reading the bodies; the dry-run is genuinely side-effect-free.
- **REQ-008 duplicate-check is O(N) across all active actions** per hook — fine for one-shot, suspect for hot-loop use.
- **REQ-009 does not 404 on unknown action IDs** — returns empty results with `total=0` instead. Minor quirk; surfaced not fixed.
- **REQ-010 search applied after pagination** — early pages may be sparser than `_limit` suggests; could mislead a client that expects exactly `_limit` rows.
- **REQ-010 dual-query total** — two mapper calls per list request; would be replaced by a `countAll(filters:)` mapper method.

## Why not extend REQ-001 vs minting REQ-010?

REQ-001's title is *"The system SHALL provide a CRUD API for schema-attached workflow actions"* — covers the surface contract. REQ-010 is about the **observable side effects of the list endpoint**: which fields filter, which don't, how `_page`/`_offset` interact, and the search-after-paginate quirk. Distinct enough to mint its own REQ.

## Annotation strategy

`@spec` tags are appended to the **method-level docblock** (not the file-level docblock — file-level already carries the previous pass's tag for `requireAdmin` indirectly). Where a method already carries an `@spec` tag from the previous pass (e.g. `index`, `requireAdmin` via file-level), a second `@spec` tag is added alongside — multi-tag is supported by the convention.

## Out of scope

- Does NOT touch any TextExtraction/* file — those are sibling capabilities.
- Does NOT modify `actions/spec.md` directly — delta only; archive step merges.
- Does NOT renumber existing REQ-001..REQ-005.
- Does NOT enforce, fix, or reorganise the observed quirks — they are flagged in Notes.
