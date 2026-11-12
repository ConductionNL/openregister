## Context

`@self.relations` is a derived map maintained by the save/import pipeline. The decision of which
string values become relations runs through, in the single-save path:
`scanForRelations()` → `scanPropertyForRelation()` → `scanStringForRelation()` → `isReference()`
in `lib/Service/Object/SaveObjects.php`. `isReference()` first accepts canonical UUIDs,
prefixed-UUIDs, and URLs (correct), then falls through to a loose catch-all: any string of 8+ chars
containing a hyphen or underscore, no whitespace, and not in a tiny hardcoded stop-list, is treated
as a reference. This catch-all is the source of the false positives observed live on shillinq
`ARInvoice` objects (dates, enum values, business identifiers polluting `@self.relations`).

The same scan logic is **duplicated across three files**, and the three copies already differ:

- `lib/Service/Object/SaveObjects.php` — the loose `isReference()` catch-all (primary offender).
- `lib/Service/Object/SaveObjects/BulkRelationHandler.php` — `scanForRelations()` here is already
  the tight version: schema-declared (`type:object`, `format` in uuid/uri/url) OR
  `Uuid::isValid()` / `FILTER_VALIDATE_URL` when no schema info. It does NOT have the loose
  length+separator branch.
- `lib/Service/Object/SaveObject/RelationCascadeHandler.php` — its own `isReference()` +
  `looksLikeObjectReference()` + `$ref`-aware `scanForRelations()`.

`RelationHandler::getUses()` (lib/Service/Object/RelationHandler.php) already catches exceptions and
logs them via `$this->logger->error(...)`, then returns the empty `{results:[],total:0}` shape —
indistinguishable to the caller from a genuine "no relations" result. The polluted `@self.relations`
drives `getUses()` to run magic-table lookups that resolve nothing.

## Goals / Non-Goals

**Goals:**
- Record a string in `@self.relations` only when it is a UUID / prefixed-UUID / URL, or the schema
  property declares it a reference (`type:object`, `format` uuid/uri/url, `$ref`/`inversedBy`).
- Remove the loose "8+ chars with hyphen/underscore" heuristic.
- Make the three scan code paths produce identical results for the same payload, ideally by
  consolidating onto one shared detection helper so the rule cannot drift again.
- Preserve exact backward behavior for real UUID-/URL-valued relations.

**Non-Goals:**
- No schema, lifecycle, aggregation, or notification declaration changes.
- No change to the public response contract of `getUses()`/`used()`.
- No change to `collectNamesForResults()` (it already restricts to UUID-format values).
- Not committing to a data backfill of already-polluted objects in this change (see Open Questions).

## Decisions

**Decision 1 — Remove the loose catch-all, keep pattern + schema-declared detection.**
`isReference()` keeps the UUID, prefixed-UUID, and URL branches verbatim and drops the
length+separator branch (including the hardcoded stop-list, which becomes dead). Schema-declared
detection (already present in the `scanForRelations` callers) remains authoritative and takes
precedence. *Alternative considered:* gate the heuristic behind a strict regex (e.g. require the
hyphenated segment to itself be UUID-shaped). Rejected — it reintroduces guessing; schema declaration
is the correct authority, and pattern matching already covers genuine bare references.

**Decision 2 — Consolidate the three copies onto one shared detector.**
Extract a single detection helper (a small private method reused via a trait, or a dedicated
`RelationDetector` collaborator) used by `SaveObjects.php`, `BulkRelationHandler.php`, and
`RelationCascadeHandler.php`. `BulkRelationHandler` is already tight and serves as the reference
shape; the other two are aligned to it. *Alternative considered:* fix each copy in place without
consolidation. Rejected as the primary long-term cause of the bug is duplication-drift (memory:
"long-term decisions favor unification"). If full consolidation proves disproportionate, the minimum
acceptable outcome is that all three copies enforce the identical rule and are covered by tests that
would fail if any copy drifts.

**Decision 3 — Backfill is out of scope by default; document the consequence.**
Tightening detection only affects objects saved/imported AFTER deploy. Existing objects keep their
already-polluted `@self.relations` until they are next re-saved. Shipping a repair step that re-scans
all objects is a larger, riskier operation (touches every magic table) and is deferred — see Open
Questions / DEFERRED.

**Decision 4 — `getUses()` error-surfacing deferred.**
`getUses()` already logs caught exceptions, so the observability gap is "caller can't distinguish
error from empty", not "errors are silent". Changing the response to surface that distinction risks
the public contract and is out of scope here — see Open Questions / DEFERRED.

## Risks / Trade-offs

- [A schema-declared reference property legitimately holds a non-UUID/non-URL key (e.g. a slug)] →
  Schema-declared detection accepts ANY string for a reference property regardless of pattern, so
  these are still recorded. Pattern matching is only the fallback for properties with no schema
  reference declaration.
- [A real bare reference is stored in a property with no schema reference declaration AND is not
  UUID/URL-shaped] → It will no longer be recorded. This is acceptable: such a value is
  indistinguishable from a scalar, and the prior heuristic was guessing. Correct fix is to declare
  the property a reference in the schema.
- [Consolidation refactor changes behavior of an already-tight copy] → Lock current correct behavior
  with characterization tests BEFORE refactoring; the suite must pass identically pre- and post-
  consolidation.
- [Existing polluted relations linger] → Documented; cleared on next re-save. Backfill can be added
  later if the noise proves operationally significant.

## Migration Plan

- Deploy is code-only; no DB migration, no schema change. Bump `info.xml` `<version>` so the JS/PHP
  bundle cache-busts (per the immutable-cache rule).
- Rollback: revert the code change; detection returns to prior (loose) behavior. No data migration to
  unwind because no backfill is shipped.
- Verification: unit tests prove UUIDs/prefixed-UUIDs/URLs/schema-declared refs still detected and
  dates/enums/business-keys rejected, across all three paths.

## Open Questions

- **DEFERRED — backfill.** Should we ship a repair step that re-scans existing objects to clean
  already-polluted `@self.relations`? Provisional: NO (out of scope; cleared on next re-save).
- **DEFERRED — `getUses()` error-surfacing.** Should `getUses()` distinguish a caught error from a
  genuine empty result (without breaking the response contract, e.g. via an internal diagnostic flag
  / metric)? Provisional: defer; it already logs.
