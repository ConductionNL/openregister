# Tasks — aggregation-dialect-repair (kind: code)

Repairs three defects that silently discard `x-openregister-aggregations`/`-calculations`
annotations (diagnosed live 2026-08-19 — even Meeting's own quorum/actionItem aggregations fail),
adds a fourth cross-cutting loud-discard fix, folds in two adjacent silent-failure repairs (seed
`$ref` slugs, nested `$ref` in array-of-objects), and tracks the orphaned ORI mock register.

## 1. Reproduce before fixing (aggregation cross-schema)

- [x] 1.1 Reproduce Meeting's actual `quorumPercentage`/`actionItemCount` aggregation spec as a PHPUnit fixture against the current `AggregationAnnotationValidator`, confirming the exact `aggregation-filter-field-unknown`/`aggregation-groupby-field-unknown` failure and which DSL shape (explicit `from` vs. an implicit relation-field reference) triggers it.
      > 2026-11-13: neither. Meeting (decidesk_register.json, copied verbatim into `tests/Unit/Search/Service/Aggregation/fixtures/decidesk-meeting-aggregations.json`) names the counted schema with a `schema` key (`"schema": "Participant"`), which neither the validator nor the runner read: the validator checked `governanceBody`, `attendanceStatus`, `meeting`, `taskStatus` against Meeting's own properties (`aggregation-filter-field-unknown`), and the runner would have counted Meetings. `CrossSchemaTargetTest::testMeetingsAggregationsWereRejectedForFieldsOfTheRelatedSchemas` pins the failure.

## 2. Fix 1 — cross-schema field resolution

- [x] 2.1 Extend `AggregationAnnotationValidator` field resolution so a filter/groupBy field addressing a related schema (via `from` or via the declaring schema's own relation/reference declarations) validates against the field's owning schema, not unconditionally against the declaring schema's `properties`.
      > 2026-11-13: `CrossSchemaTarget::resolve()` reads a `schema` key naming another schema as `from`, in the validator (with the slug and title `AdvisoryAnnotationChecks::checkAggregations()` now passes) and in `AggregationRunner::declaredSpec()`, so a spec validates as the schema it counts and is gated on that schema's read as `from` is. A `schema` key naming the declaring schema (shipped by `dataSubjectRequest`) stays intra-schema, unchanged.
- [x] 2.2 Ensure a field that is genuinely unknown on both the declaring schema and any related schema still produces `aggregation-filter-field-unknown`/`aggregation-groupby-field-unknown`.
      > 2026-11-13: an intra-schema spec, or one whose `schema` names this schema, is checked as before (`testAGenuinelyUnknownFieldIsStillRejected`, `testASchemaKeyNamingItselfKeepsTheIntraSchemaChecks`). A spec counting another schema skips the field check, as an explicit `from` always has: the target schema is not loaded at save time.

## 3. Fix 2 — operator aliases

- [x] 3.1 Add `mul`/`add`/`sub`/`div` to `CalculationAnnotationValidator::VALID_OPS` as aliases of `*`/`+`/`-`/`/`.
      > 2026-11-13: `add`/`sub`/`mul`/`div` are in `CalculationEvaluator::OPERATORS` (which `VALID_OPS` is), so the validator and the operator catalogue know them; `CalculationEvaluator::ARITHMETIC_ALIASES` names the pairs.
- [x] 3.2 Add matching dispatch in `CalculationEvaluator` so the aliases evaluate identically to their symbol forms — validation and evaluation MUST agree on the vocabulary.
      > 2026-11-13: each word shares its symbol's match arm in `evaluateArithmeticOperator()` (`'*', 'mul' => ...`), so they cannot compute differently (`ArithmeticAliasesTest`).

## 4. Fix 3 — calculation-output materialisability

- [x] 4.1 Add a `calculation-output-not-in-properties` check to `CalculationAnnotationValidator`: every calculation name in the annotation MUST also be a key in the schema's `properties`.
      > 2026-11-13: `checkMaterialisedOutput()`: only for a calculation with `materialise: true`; a virtual one (the default) is computed on read and needs no property. No shipped `lib/Settings/*.json` schema trips it (`MaterialisedOutputPropertyTest`; `DateAddTest`'s fixture gained the property it now needs).
- [x] 4.2 Confirm `MagicMapper::reportDroppedProperties()` no longer needs to warn about a calculation output once the property is required — the property now exists, so the payload key is recognised.
      > 2026-11-13: confirmed, no change: `MagicDroppedProperties::report()` warns only for payload keys absent from `properties`, which a materialised output can no longer be.

## 5. Fix 4 — loud discard (cross-cutting)

- [x] 5.1 Add a `warnings` collection point in the schema-save path that aggregates the validation errors from all seven `SchemaMapper::validate*Annotation()` families (`lifecycle`, `aggregations`, `calculations`, `quality`, `dedup`, `survivorship`, `merge`) for the schema being saved, without changing the existing warn-and-continue import behavior.
- [x] 5.2 Surface the aggregated `warnings` list on the schema-save API response (REST schema controller and the config-import pipeline's result structure), keyed by annotation family and schema slug.
- [x] 5.3 Keep the existing `nextcloud.log` warning emission unchanged (additive, not a replacement).
      > 2026-11-14: `DiscardedAnnotations` collects, per save, what `AdvisoryAnnotationChecks` (aggregations, quality, dedup, survivorship, merge, plus hinge and party), `CalculationAnnotationCheck` and `LifecycleAnnotationCheck` log; each check records next to its unchanged log line, and nothing new refuses a save. `SchemaMapper::discardedAnnotations()` (also on `SchemaMapperInterface`) answers for the last save. The schemas API create and update answer with the schema's fields plus `warnings: [{family, schema, messages}]` (empty when none); `publishDraft` reads the stored schema back by id, since the update body is no longer the entity. A configuration import returns `warnings` for every imported schema's last save.

## 6. Adjacent repair A — seed $ref resolution

- [x] 6.1 Add a `$ref`-value resolution pass in `ImportHandler::importSeedDataObjects()` that resolves a seed object's `$ref`-typed property (scalar or array-of-`$ref`) from a slug to the target UUID, checking the current run's slug-keyed maps first, then a scoped database lookup.
- [x] 6.2 Leave an unresolvable `$ref` slug as-authored with a logged warning naming the property and slug — do not abort the seed object's import (per-entity resilience unchanged).
      > 2026-11-14: `SeedReferenceResolver` replaces a slug in a seed object's reference property (a property with a `$ref`, or an array whose items have one) by the uuid of the object it names: objects stored or found earlier in the same seed pass first, then the register through the object mapper. A uuid is kept; a slug neither knows is kept as written, with a warning naming the property and the slug, and the object is still imported. `SeedReferenceResolverTest`, `ImportHandlerSeedReferencesTest`.

## 7. Adjacent repair B — nested $ref in array-of-objects

- [x] 7.1 Reproduce the `candidates[].person` → `"Unresolved reference: schema:///Person#"` failure as a PHPUnit fixture against `ValidateObject`, confirming whether the recursive `transformObjectPropertyForOpenRegister()` call for array-of-object items reaches nested `$ref` properties at all, or reaches them without the object-cast normalisation already applied to top-level `items`. (verified: lib/Service/Object/ValidateObject.php, tests/Unit/Service/Object/ValidateObjectNestedItemRefTest.php)
- [x] 7.2 Fix the identified gap so a nested `$ref` property inside an array-of-objects item is stripped/transformed the same way a top-level scalar `$ref` property already is, before Opis JSON Schema validation runs. (verified: lib/Service/Object/ValidateObject.php, tests/Unit/Service/Object/ValidateObjectNestedItemRefTest.php)

## 8. Housekeeping — orphaned ORI mock register

- [x] 8.1 Add a tracking note (code comment in `lib/Settings/ori_register.json`'s `x-openregister` block, and/or a line in `mock-registers`' spec Purpose) flagging that the ORI mock register becomes retirement-eligible once decidesk's ORI adoption (via the openconnector leaf-app path) completes — do not remove it now (procest still depends on the same six slugs).
      > 2026-11-13: a line in the Purpose of `openspec/specs/mock-registers/spec.md` (a JSON register file takes no comments).

## 9. Tests

- [x] 9.1 Add PHPUnit tests for Fixes 1-2 covering: a related-schema filter field is accepted, a genuinely unknown field is still rejected, an explicit `from` spec is unaffected, and `mul`/`add`/`sub`/`div` evaluate identically to `*`/`+`/`-`/`/` at both validation and evaluation.
      > 2026-11-13: Fix 2 in `ArithmeticAliasesTest`; Fix 1 in `CrossSchemaTargetTest`, `AdvisoryAnnotationChecksTest::testAnAggregationOverARelatedSchemaIsKeptAtSave` and the `schema-key-*` cases of `AggregationRunnerRunGoldenTest`.
- [x] 9.2 Add PHPUnit tests for Fixes 3-4 covering: a calculation name absent from `properties` is rejected and present-and-matching validates/persists; a discarded annotation appears in the save response `warnings` (and a clean schema's list is empty, multiple families each report separately, the log warning still fires).
      > 2026-11-13 (partial): the Fix 3 half is in `MaterialisedOutputPropertyTest`; the Fix 4 half waits for Fix 4.
      > 2026-11-14: the Fix 4 half in `DiscardedAnnotationsTest`, `AdvisoryAnnotationChecksTest` (recorded per family, nothing without a fault), `SchemaMapperDiscardedAnnotationsTest` (two families each listed and still logged, a clean save lists nothing), `SchemasControllerTest` (create and update) and `ImportHandlerImportFromJsonGoldenTest::testDiscardedAnnotationsReachTheImportResult`.
- [x] 9.3 Add PHPUnit tests for Adjacent A-B covering: an in-run seed `$ref` slug resolves to a UUID and an unresolvable one is left as-authored with a warning; a valid nested array-of-objects `$ref` UUID validates and existing single-level `$ref` support is unaffected (regression guard).
      > 2026-11-14: Adjacent A in `SeedReferenceResolverTest` and `ImportHandlerSeedReferencesTest`; Adjacent B was covered by `ValidateObjectNestedItemRefTest` (task 7).

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
