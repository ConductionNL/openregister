# Tasks — aggregation-dialect-repair (kind: code)

Repairs three defects that silently discard `x-openregister-aggregations`/`-calculations`
annotations (diagnosed live 2026-08-19 — even Meeting's own quorum/actionItem aggregations fail),
adds a fourth cross-cutting loud-discard fix, folds in two adjacent silent-failure repairs (seed
`$ref` slugs, nested `$ref` in array-of-objects), and tracks the orphaned ORI mock register.

## 1. Reproduce before fixing (aggregation cross-schema)

- [ ] 1.1 Reproduce Meeting's actual `quorumPercentage`/`actionItemCount` aggregation spec as a PHPUnit fixture against the current `AggregationAnnotationValidator`, confirming the exact `aggregation-filter-field-unknown`/`aggregation-groupby-field-unknown` failure and which DSL shape (explicit `from` vs. an implicit relation-field reference) triggers it.

## 2. Fix 1 — cross-schema field resolution

- [ ] 2.1 Extend `AggregationAnnotationValidator` field resolution so a filter/groupBy field addressing a related schema (via `from` or via the declaring schema's own relation/reference declarations) validates against the field's owning schema, not unconditionally against the declaring schema's `properties`.
- [ ] 2.2 Ensure a field that is genuinely unknown on both the declaring schema and any related schema still produces `aggregation-filter-field-unknown`/`aggregation-groupby-field-unknown`.

## 3. Fix 2 — operator aliases

- [ ] 3.1 Add `mul`/`add`/`sub`/`div` to `CalculationAnnotationValidator::VALID_OPS` as aliases of `*`/`+`/`-`/`/`.
- [ ] 3.2 Add matching dispatch in `CalculationEvaluator` so the aliases evaluate identically to their symbol forms — validation and evaluation MUST agree on the vocabulary.

## 4. Fix 3 — calculation-output materialisability

- [ ] 4.1 Add a `calculation-output-not-in-properties` check to `CalculationAnnotationValidator`: every calculation name in the annotation MUST also be a key in the schema's `properties`.
- [ ] 4.2 Confirm `MagicMapper::reportDroppedProperties()` no longer needs to warn about a calculation output once the property is required — the property now exists, so the payload key is recognised.

## 5. Fix 4 — loud discard (cross-cutting)

- [ ] 5.1 Add a `warnings` collection point in the schema-save path that aggregates the validation errors from all seven `SchemaMapper::validate*Annotation()` families (`lifecycle`, `aggregations`, `calculations`, `quality`, `dedup`, `survivorship`, `merge`) for the schema being saved, without changing the existing warn-and-continue import behavior.
- [ ] 5.2 Surface the aggregated `warnings` list on the schema-save API response (REST schema controller and the config-import pipeline's result structure), keyed by annotation family and schema slug.
- [ ] 5.3 Keep the existing `nextcloud.log` warning emission unchanged (additive, not a replacement).

## 6. Adjacent repair A — seed $ref resolution

- [ ] 6.1 Add a `$ref`-value resolution pass in `ImportHandler::importSeedDataObjects()` that resolves a seed object's `$ref`-typed property (scalar or array-of-`$ref`) from a slug to the target UUID, checking the current run's slug-keyed maps first, then a scoped database lookup.
- [ ] 6.2 Leave an unresolvable `$ref` slug as-authored with a logged warning naming the property and slug — do not abort the seed object's import (per-entity resilience unchanged).

## 7. Adjacent repair B — nested $ref in array-of-objects

- [ ] 7.1 Reproduce the `candidates[].person` → `"Unresolved reference: schema:///Person#"` failure as a PHPUnit fixture against `ValidateObject`, confirming whether the recursive `transformObjectPropertyForOpenRegister()` call for array-of-object items reaches nested `$ref` properties at all, or reaches them without the object-cast normalisation already applied to top-level `items`.
- [ ] 7.2 Fix the identified gap so a nested `$ref` property inside an array-of-objects item is stripped/transformed the same way a top-level scalar `$ref` property already is, before Opis JSON Schema validation runs.

## 8. Housekeeping — orphaned ORI mock register

- [ ] 8.1 Add a tracking note (code comment in `lib/Settings/ori_register.json`'s `x-openregister` block, and/or a line in `mock-registers`' spec Purpose) flagging that the ORI mock register becomes retirement-eligible once decidesk's ORI adoption (via the openconnector leaf-app path) completes — do not remove it now (procest still depends on the same six slugs).

## 9. Tests

- [ ] 9.1 Add PHPUnit tests for Fixes 1-2 covering: a related-schema filter field is accepted, a genuinely unknown field is still rejected, an explicit `from` spec is unaffected, and `mul`/`add`/`sub`/`div` evaluate identically to `*`/`+`/`-`/`/` at both validation and evaluation.
- [ ] 9.2 Add PHPUnit tests for Fixes 3-4 covering: a calculation name absent from `properties` is rejected and present-and-matching validates/persists; a discarded annotation appears in the save response `warnings` (and a clean schema's list is empty, multiple families each report separately, the log warning still fires).
- [ ] 9.3 Add PHPUnit tests for Adjacent A-B covering: an in-run seed `$ref` slug resolves to a UUID and an unresolvable one is left as-authored with a warning; a valid nested array-of-objects `$ref` UUID validates and existing single-level `$ref` support is unaffected (regression guard).

## 10. Verification

- [ ] 10.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the Hydra mechanical gates; fix any pre-existing issues touched.
- [ ] 10.2 Run `openspec validate --change aggregation-dialect-repair --strict`; resolve any errors.

## Acceptance Criteria

- Meeting's own `quorumPercentage`/`actionItemCount` aggregations validate and resolve via the API
  once re-saved.
- A calculation authored with `mul`/`add`/`sub`/`div` validates and evaluates identically to its
  symbol-form equivalent.
- A calculation whose output name is not a declared property is rejected at save time with a clear,
  actionable error — not silently dropped at persist time.
- A schema-save response's `warnings` list names every discarded annotation family with its error
  messages, without changing the underlying warn-and-continue import behavior.
- A seed object's `$ref` property resolves to a UUID when the target was imported in the same run.
- A nested `$ref` property inside an array-of-objects item validates a valid UUID on direct writes.
- `ori_register.json` carries a tracking note; it is not removed by this change.

## Quality Checklist

- Every fix cites its exact file:line locus in proposal.md/design.md — no fix proceeds without a
  reproducing fixture first (tasks 1.1, 7.1) per the "an expression of a pattern matches the pattern"
  lesson: guessing the mechanism from a symptom risks fixing a narrower bug than the one reported.
- Fix 4 (`annotation-validation-surfacing`) is additive only — no existing response field renamed or
  removed, no change to whether an advisory annotation aborts a save.
- SPDX + `@license`/`@copyright` docblock headers on every new/modified PHP file (EUPL-1.2).
- No jurisdiction-specific behavior added — this is a platform-wide correctness repair, not a
  decidesk-specific one, even though decidesk's live instance is where it was diagnosed.
