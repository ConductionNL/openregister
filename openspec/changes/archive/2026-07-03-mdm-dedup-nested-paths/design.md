# Design — Nested dot-path field resolution for duplicate detection

## Context

`DuplicateDetectionService` reads match-rule and blocking-key field values
straight off the top level of an object's decoded payload:

- `blockingTokenFor()` (~L307-320): `$data[$key] ?? null`
- `scorePair()` (~L354-385): `$dataA[$field] ?? null` / `$dataB[$field] ?? null`

Both call sites need to become dot-path aware so `x-openregister-dedup` can
target `goldenRecord.email` the same way `x-openregister-quality` rules and
the calculation engine's `@self.<path>` tokens already do.

## ADR-011 reuse decision

Searched `lib/` for a shared dot-path / property-path utility before adding
one. OpenRegister does **not** have a single shared `DataAccessor`/`Dot`
class; the dot-path idiom is instead re-implemented as a small private
helper in each consuming service, all following the identical shape (split
on `.`, walk the array, `array_key_exists` guard, return `null` on any
missing segment, never throw):

- `Service\Quality\QualityScorer::fieldValue()` — sibling class in the same
  `Quality` namespace; existing precedent for `x-openregister-quality`'s
  documented "dotted paths supported for nesting."
- `Service\Calculation\ReferenceResolver::readPath()`
- `Service\Calculation\AggregateReferenceResolver` (dot-path in `resolvePath`-style helper)
- `Service\Object\DataManipulationHandler`, `Service\Object\RenderObject`

There is no cross-cutting util to import — each service owns its own
version because the resolution rules differ slightly per caller (e.g.
`ReferenceResolver::readPath()` special-cases `@self.<field>`, which does not
apply here). Per that established pattern, this change adds a private
`resolvePath(array $data, string $path): mixed` helper directly on
`DuplicateDetectionService`, mirroring `QualityScorer::fieldValue()`'s exact
shape (its nearest sibling, same directory, same annotation family) rather
than introducing a new shared class or generalising an existing one — that
generalisation is out of scope for this small, focused change and would
touch call sites that don't need to change.

## Resolution semantics

- `resolvePath($data, "name")` — no dot, single segment: behaves exactly as
  the current `$data['name'] ?? null` read (backward compatible).
- `resolvePath($data, "goldenRecord.name")` — splits on `.`, walks the
  array one segment at a time; if any intermediate value is not an array, or
  the key is absent, returns `null` immediately (no exception, no notice).
- Both `blockingTokenFor()` and `scorePair()` call the same helper; the
  existing `SimilarityCalculator::similarity()` / `::blockingToken()` methods
  are unchanged — they already accept a resolved scalar/mixed value, not a
  path, so they need no change.

## Validator scope

`DedupAnnotationValidator::validateRule()` only checks that `field` is a
non-empty string (`(string) ($rule['field'] ?? '') === ''`). A dotted path is
still just a non-empty string, so no validator change is required for
acceptance; a test is added to lock in that a nested-path rule validates
cleanly (regression guard, not new logic).

## Backward compatibility

Every existing top-level `x-openregister-dedup` annotation continues to
resolve identically — a key with no `.` never enters the traversal branch
differently than a direct array read. No annotation shape change, no
migration.

## Unblocks

pipelinq's `mdm-consume-or-surface` change built a `match*` top-level
flattening projection specifically to work around the top-level-only
limitation this change removes (ADR-045 follow-on #2). Once this ships,
pipelinq can declare `goldenRecord.name` / `goldenRecord.email` directly in
its `x-openregister-dedup` annotation and delete the projection — tracked as
a separate, app-side follow-up.

## Seed Data

None — no schema/config seeding required; this is a pure resolution-logic
change with no new declarative surface.
