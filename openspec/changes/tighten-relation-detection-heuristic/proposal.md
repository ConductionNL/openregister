---
kind: code
---

## Why

OpenRegister auto-populates each object's `@self.relations` map at save and import time by scanning
every string property and deciding whether it "looks like" a reference to another object. The
deciding method, `isReference()`, ends in a loose catch-all branch: ANY string of 8+ characters that
contains a hyphen or underscore and no whitespace is treated as a relation. This produces false
positives. Observed live on shillinq `ARInvoice` objects whose `@self.relations` filled with plain
scalars — dates (`2026-05-20`), enum values (`bank_transfer`), and business identifiers
(`DEMO-F-2026-04-02`, `demo-administration`, country-prefixed registry keys) — none of which are
object UUIDs. The polluted map then drives `RelationHandler::getUses()` to run magic-table lookups
that resolve nothing, so the relations field is both misleading and wasteful.

## What Changes

- Tighten `isReference()` so a string is recorded as a relation ONLY when it is a genuine reference:
  it matches a UUID, a prefixed-UUID, or a URL pattern (all kept exactly as today), OR the schema
  property explicitly declares it a reference (`type: object`, `format` in `uuid`/`uri`/`url`, or a
  `$ref`/`inversedBy` on the property). Schema-driven detection is authoritative.
- **BREAKING (derived-field behavior only)**: REMOVE the loose "8+ chars with hyphen/underscore"
  catch-all heuristic. Ordinary scalars (dates, enum values, business identifiers) are NO LONGER
  recorded as relations. Real UUID-/URL-valued relations are detected exactly as before — no schema,
  lifecycle, aggregation, or notification declaration changes.
- Apply the same tightened detection consistently to all three duplicated copies of the
  scan/`isReference` logic (`SaveObjects.php`, `BulkRelationHandler.php`,
  `RelationCascadeHandler.php`), consolidating onto one shared implementation where feasible so the
  rule cannot drift between copies again.
- Add unit tests proving UUIDs/prefixed-UUIDs/URLs and schema-declared references are still detected,
  and that dates, enum values, and business identifiers are rejected.
- Note (secondary, optional): `RelationHandler::getUses()` already logs caught exceptions but returns
  the same empty `{results:[],total:0}` shape as a genuine "no relations" result; the spec MAY add a
  distinguishable diagnostic without changing the public response contract.

## Capabilities

### New Capabilities
<!-- None. This is a correctness fix to existing pipeline behavior. -->

### Modified Capabilities
- `object-lifecycle`: The relation-detection step of the layered save/import pipeline changes its
  acceptance rule for what string values become entries in `@self.relations` — only genuine
  references (UUID/prefixed-UUID/URL or schema-declared reference properties) are recorded; the loose
  length+separator heuristic is removed.

## Impact

- Code: `lib/Service/Object/SaveObjects.php` (`scanForRelations` / `scanPropertyForRelation` /
  `scanStringForRelation` / `isReference`), `lib/Service/Object/SaveObjects/BulkRelationHandler.php`
  (`scanForRelations`), `lib/Service/Object/SaveObject/RelationCascadeHandler.php`
  (`scanForRelations` / `isReference` / `looksLikeObjectReference`). Optionally
  `lib/Service/Object/RelationHandler.php` (`getUses` diagnostics).
- Behavior: `@self.relations` on newly saved/imported objects becomes accurate. Existing objects keep
  their already-polluted relations until re-saved (backfill is a separate decision — see design).
- Dependent apps (shillinq, opencatalogi, softwarecatalog, decidesk): consumers of `@self.relations`
  see fewer, accurate entries. No API contract change. No schema change.
- Tests: new PHPUnit coverage for `isReference` / `scanForRelations` across the three handlers.
