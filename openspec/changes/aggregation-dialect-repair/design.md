## Context

Diagnosed live, 2026-08-19, during decidesk's organisation-goals apply (Back to Six programme):
declaring `x-openregister-aggregations`/`x-openregister-calculations` on a schema produces no
computed fields, and Meeting's own `quorumPercentage`/`actionItemCount` — the pattern cited as
"already proven in production" in decidesk design docs — fails identically. The only observable
signal is a `nextcloud.log` warning line matching `annotation on schema`, emitted by
`SchemaMapper::validate*Annotation()`'s degrade-to-warning-and-continue pattern
(`lib/Db/SchemaMapper.php:1044-1249`), which never reaches a schema author working through a normal
config-import or admin-UI schema save. A cited-as-proven declarative pattern was dead platform-wide
with zero PHPUnit test failing.

Reading the code confirms three distinct, independently-triggerable defects (see proposal.md's
"Why" for exact file:line citations):

1. `AggregationAnnotationValidator`'s field-existence check only exempts a spec carrying a literal
   top-level `from` key (`AggregationAnnotationValidator.php:116-123`); any other way of addressing a
   related schema's field is checked against the declaring schema's own `properties`
   (`SchemaMapper.php:1044-1056` supplies only the declaring schema's shape) and rejected.
2. `CalculationAnnotationValidator`'s v1 operator vocabulary (`CalculationAnnotationValidator.php:60-93`)
   has no `mul`/`add`/`sub`/`div` aliases, only the bare arithmetic symbols.
3. Nothing requires a calculation's declared name to also be a schema property, so a calculation
   that validates cleanly and evaluates correctly still evaporates at `MagicMapper::reportDroppedProperties()`
   (`MagicMapper.php:3855-3905`) because its output key isn't in `properties`.

All three share one shape: a validator (or the persistence layer) makes an implicit assumption about
where a name lives, the assumption doesn't hold for a legitimate authoring pattern, and the failure
mode is silent discard rather than a clear, author-visible rejection. That shared shape is why this
change adds a fourth, cross-cutting fix (loud discard) rather than three point-fixes: fixing the
three known triggers without also fixing the surfacing gap leaves every *next* undiscovered trigger
of the same discard shape equally invisible.

Two adjacent defects surfaced in the same investigation wave (appointment-decision-type apply) share
enough of the "declared correctly, silently wrong at runtime" shape to fold into this repair:
seed-imported `$ref` values landing as raw slugs, and a nested `$ref` inside an array-of-objects
403ing on direct writes.

## Goals / Non-Goals

**Goals:**
- `AggregationAnnotationValidator` validates a filter/groupBy field against the schema that actually
  owns it, so a cross-schema-shaped aggregation (Meeting's own quorum/actionItem pattern) validates
  and runs.
- `CalculationAnnotationValidator` and `CalculationEvaluator` agree on an operator vocabulary that
  includes `mul`/`add`/`sub`/`div` as aliases, without removing the existing symbol forms.
- A calculation's declared name is guaranteed materialisable — either the validator requires it to
  be a declared property, or the property is implicitly added — closing the "valid annotation, empty
  column" gap.
- A discarded `x-openregister-*` annotation (any of the seven families sharing the discard shape) is
  visible in the schema-save API response, not only in `nextcloud.log`.
- Seed-imported `$ref` values resolve to the target UUID.
- A nested `$ref` inside an array-of-objects property validates a valid UUID on direct writes.

**Non-Goals:**
- No change to the `x-openregister-aggregations`/`-calculations` DSL shape itself beyond what's
  needed for these fixes — no new operators, no new aggregation metrics.
- No re-materialisation of already-discarded annotations on existing schemas — an operator re-saves
  the schema (or re-imports) to pick up corrected validation; `occ
  openregister:rematerialise-calculations` (existing `computed-fields` capability) then backfills
  values on existing objects.
- Retiring `lib/Settings/ori_register.json` — tracked as a task, not executed here (procest still
  depends on it; decidesk's own ORI adoption isn't complete).
- Any change to `row-field-level-security`, `deprecate-published-metadata`, or the
  `confidentiality-classification` primitive (same wave, separate change) — unrelated capabilities.

## Decisions

### Fix 1 — cross-schema field resolution (ADR-011: fix the real gap, not the symptom)

The correct-by-design escape hatch already exists: `validateCrossSchemaSpec()`
(`AggregationAnnotationValidator.php:221-259`) deliberately skips field-existence checks for a spec
carrying a `from` key, because the target schema isn't loaded at annotation-save time. The actual
defect is narrower than "the validator checks the wrong schema" — it is **"the validator has no way
to recognise a cross-schema-shaped spec that doesn't spell `from`."** Two authoring patterns need to
both reach the lighter cross-schema validation path:

1. **Explicit `from`** — already correct; keep as-is.
2. **Implicit cross-schema via a relation/aggregate-reference field** — Meeting's own aggregations
   reference fields that live on a related schema reached through a declared relation (per the
   proposal's evidence: filter keys like `goal`, `meeting`, `lifecycle`). The fix extends field
   resolution so that when a filter/groupBy field is NOT in the declaring schema's `propKeys` but IS
   a name the schema's own relation/`$ref` declarations resolve through (mirroring how
   `CalculationAnnotationValidator` already resolves `@ref.<name>.<field>` tokens against
   `x-openregister-references`, `CalculationAnnotationValidator.php:146-153`), the field is treated
   as valid rather than rejected outright.

This keeps the validator's job (catch a genuinely unknown field, e.g. a typo) while no longer
rejecting a field that legitimately lives on a related schema. Task 1 requires reproducing Meeting's
actual aggregation spec shape as a fixture first — the exact DSL variant Meeting uses (does it use
`from`, or reference a relation field directly?) determines whether the fix is "recognise more shapes
as cross-schema" or "extend the cross-schema resolution to actually load the target schema and check
against it" (the literal framing in the diagnosis). Both converge on the same externally observable
behavior (spec.md is written at that level); the task list below front-loads the reproduction step so
the implementation targets the actual defect, not a fixture I authored from the diagnosis note alone.

### Fix 2 — operator aliases (consistency with the existing alias idiom)

`AggregationAnnotationValidator` already aliases `metric`/`select` and `filter`/`where`
(`AggregationAnnotationValidator.php:130`, `:169`) — accepting a more natural-language key alongside
the terse one is an established idiom in this file family, not a new pattern. `mul`/`add`/`sub`/`div`
become aliases resolved to `*`/`+`/`-`/`/` at both validation time (`VALID_OPS` membership) and
evaluation time (`CalculationEvaluator`'s operator dispatch) — both sides MUST agree, or a
calculation could validate under the alias and then fail evaluation, reproducing the same
validate-vs-runtime mismatch this whole change exists to close.

### Fix 3 — calculation-output materialisability (fail validation, don't silently drop)

Two candidate fixes were considered:

- **(a) Reject at validation time**: `CalculationAnnotationValidator` adds a check that every
  calculation name in `calcNames` is also present in `propKeys`, emitting a new
  `calculation-output-not-in-properties` error when it isn't.
- **(b) Auto-add the property**: the schema-save path silently synthesises a matching property
  (typed from the calculation's `type`) for every calculation name not already declared.

**Chosen: (a), reject at validation time.** (b) would silently mutate the schema's `properties` list
the author didn't write, which is its own kind of invisible behavior — the exact failure mode this
change is repairing elsewhere. Rejecting with a clear, specific error message ("calculation `total`'s
output property is not declared — add `total` to `properties` with type `number`") is symptomatic of
the loud-discard philosophy (Fix 4) applied one level earlier: catch the author's mistake at
save-time with an actionable message, rather than validating successfully and discarding silently at
persist-time three layers away in `MagicMapper`.

### Fix 4 — loud discard (cross-cutting)

The `SchemaMapper::validate*Annotation()` family (`SchemaMapper.php:1044-1249`) is a single shared
shape repeated seven times (`lifecycle`, `aggregations`, `calculations`, `quality`, `dedup`,
`survivorship`, `merge`): validate, and on error, `logger->warning()` + continue. This change does
not remove the "continue" half — an advisory annotation genuinely should not abort a schema import,
and that design choice predates this change and is out of scope to reverse. What it fixes is the
**surfacing** half: the schema-save response (whatever entry point calls
`SchemaMapper::save()`/`update()` — the REST schema controller, the config-import pipeline) must
collect the accumulated validator errors across all seven families for the schema being saved and
return them as a structured `warnings` array on the response, keyed by annotation family and schema
slug.

This is deliberately additive and non-breaking: a caller that ignores the new `warnings` field sees
identical behavior to today (schema still imports, computed features still don't run when
misdeclared). A caller that reads it — a schema-authoring UI, an `occ` command's output, an import
script checking its own result — gets the signal that was previously log-only. The `confidentiality-
classification-primitive` change (same wave) explicitly depends on this warnings surface for its own
loud-discard requirement, rather than inventing a second one — see that change's design.md Open
Questions.

### Adjacent repair A — seed `$ref` resolution

`ImportHandler::importSeedDataObjects()` deliberately bypasses `ObjectService`/`SaveObject`
("Use MagicMapper directly for seedData objects to avoid complex ObjectService dependencies... don't
require cascading or complex validation", `ImportHandler.php:5095-5096`) and writes
`$objectData` verbatim via `$objectEntity->setObject($objectData)` (`ImportHandler.php:5114`). The
fix adds a narrow, seed-import-scoped `$ref` resolution pass — walk the target schema's declared
`$ref` properties (scalar and array-of-`$ref`), and for each one whose value is a slug rather than a
UUID, resolve it against the schemas/objects already imported in this run (mirroring the existing
`schemasMap`/`registersMap` slug-keyed lookup pattern already used elsewhere in this method) before
constructing the `ObjectEntity`. This deliberately stays narrower than routing seed import through
the full `ObjectService` write path (which the method's own comment says was avoided for good
reason — complexity and dependency surface) — it borrows only the resolution step, not the full
validation/cascade pipeline.

### Adjacent repair B — nested `$ref` in array-of-objects

`ValidateObject.php`'s array-items handling (`lines 547-591`) already recurses into
`transformObjectPropertyForOpenRegister()` for an item schema of `type: object`
(line 588-589). The reported failure (`candidates[].person` → `"Unresolved reference:
schema:///Person#"`) means that recursive call is not stripping the nested `person` property's own
`$ref` before Opis JSON Schema validates it — either the recursion doesn't reach nested properties at
all, or it reaches them but the per-property `$ref`-stripping branch (`lines 598-608`, scalar
`$ref`-on-`string`-type) isn't triggered from within the nested call for some structural reason (e.g.
the nested property's schema arrives as a plain array rather than the `stdClass` the top-level walk
normalises, mirroring the exact `items`-object-cast issue already fixed once for array items at
`lines 551-558`). Task 2 requires reproducing the exact failing shape as a fixture first, for the
same reason as Fix 1: the diagnosis names the symptom precisely but not the exact code path, and
guessing the wrong mechanism risks a fix that passes a narrower fixture than the real bug.

## Risks / Trade-offs

- **[Fix 1's "which shapes count as cross-schema" heuristic could over- or under-match]** →
  Mitigation: reproduce Meeting's actual spec as the first task (below), so the implementation is
  driven by a real fixture rather than a guess; spec.md's scenarios are written at the observable-
  behavior level so the exact heuristic can be adjusted without a spec change.
- **[Operator aliases could mask a genuine typo]** (e.g. `mult` half-matching `mul`) → Mitigation:
  aliases are an exact-match allowlist addition (`mul`/`add`/`sub`/`div`), not a fuzzy match; an
  unrecognised operator still fails validation exactly as today.
- **[Fix 3 is stricter than today]** — a calculation whose name isn't a declared property currently
  validates "successfully" (and silently loses its output); after this change it fails validation.
  This is an intentional behavior change for a schema that was never actually working. Flagged
  explicitly here because a strict reading of "no breaking change" could miss it: no *currently
  functional* schema is affected, but a schema that appeared to validate while being silently
  non-functional will now show a validation error on next save.
- **[Fix 4 changes response shape]** → Mitigation: purely additive (`warnings` field, new and
  optional); no existing response field is renamed or removed.
- **[Seed $ref resolution (Adjacent A) could resolve to the wrong object on a slug collision]** →
  Mitigation: resolution is scoped to schemas/objects imported in the same run first (the existing
  `schemasMap`/`registersMap` pattern), falling back to a database lookup only when not found in-run,
  matching the existing schema-slug resolution precedent a few lines above in the same method
  (`ImportHandler.php:4812-4851`).

## Migration Plan

No database migration. No new OR schema. Existing schemas with a currently-valid
aggregations/calculations annotation are unaffected by Fixes 1-3 (they already validate; the fixes
only change outcomes for specs that currently fail validation). A schema whose calculation output
was silently dropped (Fix 3's new rejection) requires an author action — add the missing property —
on next save; this is a deliberate, visible failure replacing a silent one, not a regression.
Rollback for each fix is independent (they touch different methods); Fix 4's `warnings` field can be
dropped without affecting Fixes 1-3's validation behavior.

## Open Questions

- Fix 1's exact resolution mechanism (recognise more shapes as `from`-equivalent vs. actually
  resolving and loading the target schema at validation time) is deferred to task 1's reproduction
  step — see tasks.md.
- Adjacent repair B's exact failing code path (recursion not reached vs. object-cast gap) is
  similarly deferred to task 2's reproduction step.
- Whether `occ openregister:rematerialise-calculations` should gain a `--reason` flag noting "this
  object's calculation was previously silently dropped" for operator visibility during the fleet-wide
  rollout of Fix 3 — deferred as a nice-to-have, not blocking.
