---
kind: code
---

## Why

Declaring `x-openregister-aggregations`/`x-openregister-calculations` on a schema silently produces
no computed fields on the current build. This was diagnosed live (2026-08-19, decidesk
organisation-goals apply): **even Meeting's own `quorumPercentage`/`actionItemCount`
aggregations — the pattern every design doc cites as "already proven in production" — fail
identically**, with the only tell being a `nextcloud.log` warning line (`grep "annotation on schema"`)
that never reaches the schema author. A cited-as-proven declarative pattern can be dead
platform-wide with zero PHPUnit test failing, because the discard path is a log line, not a
save-time rejection or an API-visible warning.

Root-caused to three defects, all in `openregister/`:

1. **`AggregationAnnotationValidator` validates a filter/groupBy field against the wrong schema's
   `properties`.** `lib/Service/Aggregation/AggregationAnnotationValidator.php:91-95` builds its
   allow-list (`$propKeys`) exclusively from the schema shape `SchemaMapper::validateAggregationsAnnotation()`
   passes in — the **declaring** schema's own `properties`
   (`lib/Db/SchemaMapper.php:1044-1056`). The validator's only escape hatch from that allow-list is a
   top-level `from` key on the aggregation spec (lines 116-123, delegating to
   `validateCrossSchemaSpec()` at lines 221-259, which correctly skips field-existence checks
   because — per its own docblock, lines 42-48 — "the target schema is not available at
   annotation-save time"). Any aggregation whose `filter`/`groupBy` addresses a field that lives on
   a *related* schema (a relation/aggregate-reference target) without using that exact `from`-keyed
   cross-schema DSL — which is how Meeting's own `quorumPercentage`/`actionItemCount` are
   authored — is validated against the declaring schema's own property list and rejected as
   `aggregation-filter-field-unknown` / `aggregation-groupby-field-unknown`
   (lines 156-165, 176-187, 190-206). The whole annotation is then discarded.
2. **`CalculationAnnotationValidator` rejects the `mul` operator.** The v1 operator vocabulary at
   `lib/Service/Calculation/CalculationAnnotationValidator.php:60-93` lists the arithmetic symbol
   `*` but not the natural-language alias `mul` (nor `add`/`sub`/`div` for `+`/`-`/`/`). A
   calculation authored with `{"mul": [...]}`  — the form decidesk's design docs use — fails
   `calculation-bad-operator` (message built at line ~273) and discards the whole
   `x-openregister-calculations` block, exactly like defect 1.
3. **`MagicMapper` silently discards a calculation's output when its name isn't also a schema
   property.** `CalculationAnnotationValidator::validate()` (lines 137-144) computes `propKeys` and
   `calcNames` separately and only merges them to make cross-calc `prop` references resolvable — it
   never requires a calculation's own name to be a declared property. At persist time,
   `MagicMapper::reportDroppedProperties()` (`lib/Db/MagicMapper.php:3855-3905`) treats any payload
   key absent from `properties` as unknown and drops it (now logged, per the earlier
   `or-silent-field-loss` change, but still dropped: "They are NOT stored anywhere" —
   `MagicMapper.php:3891`). A calculation whose output name was never mirrored into `properties`
   evaporates on every save even when the calculation itself evaluates successfully.

None of these three failures abort the schema save — `SchemaMapper::validate*Annotation()`
(`lib/Db/SchemaMapper.php:1044-1109`) treats aggregations/calculations as "ADVISORY metadata" and
degrades every validator error to a `logger->warning()` call, importing the schema anyway. That
degrade-and-continue design is reasonable for a genuinely optional feature — it is not reasonable
when the practical effect, for two of the three defects above, is "the annotation you just declared
does nothing, and the only way to find out is grepping `nextcloud.log` after the fact."

## What Changes

- **Fix 1 — cross-schema field resolution**: `AggregationAnnotationValidator` MUST validate a
  filter/groupBy field against the schema that actually owns it — the target schema when the spec
  is (or should be recognised as) cross-schema, the declaring schema otherwise — instead of always
  falling back to the declaring schema's `properties`.
- **Fix 2 — `mul`/`add`/`sub`/`div` operator aliases**: `CalculationAnnotationValidator`'s (and the
  paired `CalculationEvaluator`'s) operator vocabulary MUST accept `mul`, `add`, `sub`, `div` as
  aliases for `*`, `+`, `-`, `/` respectively — matching the existing alias idiom already used
  elsewhere in the same file family (`AggregationAnnotationValidator` already aliases
  `metric`/`select` and `filter`/`where`).
- **Fix 3 — calculation-output materialisability**: `CalculationAnnotationValidator` MUST reject (or
  the schema-save path MUST otherwise refuse) a calculation whose declared name is not also present
  in the schema's `properties`, so a calculation that passes validation is guaranteed storable by
  `MagicMapper` — closing the gap between "annotation is valid" and "output is persisted."
- **Fix 4 (new, cross-cutting) — loud discard**: the `SchemaMapper::validate*Annotation()` degrade-
  to-`warning`-and-continue pattern (covering `lifecycle`, `aggregations`, `calculations`, `quality`,
  `dedup`, `survivorship`, `merge` — seven annotation families sharing one discard shape) MUST stop
  being invisible outside `nextcloud.log`. A discarded declaration MUST surface in the schema-save
  API response itself (a structured warnings list), so "the save succeeded" and "your annotation was
  silently ignored" are never conflated in the caller's own view of the result.
- **Adjacent repair A — seed importer resolves `$ref` to UUID, not raw slug**: `ImportHandler::importSeedDataObjects()`
  writes seed object payloads verbatim via `MagicMapper`/`objectEntityMapper->insert()`
  (`lib/Service/Configuration/ImportHandler.php:5095-5126`), bypassing the `$ref`-resolution step the
  normal `ObjectService`/`SaveObject` write path performs — by design, per the method's own comment
  ("Use MagicMapper directly for seedData objects to avoid complex ObjectService dependencies").
  The effect: a seed object's `$ref`-typed property (e.g. `amends: "motie-duurzaamheid-2025"`) is
  stored as the raw slug string it was authored with, not the resolved target UUID, register-wide.
  Any consumer expecting a UUID (relation expansion, UI reference pickers) sees a raw slug for
  seeded data.
- **Adjacent repair B — nested `$ref` inside array-of-objects on direct writes**: a schema property
  shaped as an array of objects, where a nested property of each item is itself a `$ref` (e.g.
  `candidates[].person`), fails direct object writes with `"Unresolved reference:
  schema:///Person#"` even when the nested value is a valid UUID — observed live, inherited
  unchanged from an existing schema pattern (Voordracht.kandidaten), not a new regression.
  `lib/Service/Object/ValidateObject.php`'s `$ref`-stripping logic (`dropUnusableRef()` /
  `transformObjectPropertyForOpenRegister()`, the array-items handling around lines 547-591) already
  strips a `$ref` on an array item that is itself a `$ref` string (line 574-587) and on a top-level
  object property (line 594-596); this repair confirms and closes the gap for a nested property
  *inside* an array item's object schema, which appears not to receive the same recursive
  transformation before Opis JSON Schema validates it.
- **Task-only — orphaned `ori_register.json`**: `lib/Settings/ori_register.json` (the ORI mock
  register, `openspec/specs/mock-registers/`) becomes retirement-eligible once decidesk's ORI
  adoption (via the leaf-app openconnector integration path, decidesk Back to Six programme)
  completes. Not retired by this change — procest still declares the same six ORI slugs in its own
  operational register — but tracked as a follow-up task so it is not forgotten once decidesk no
  longer needs the mock.

## Capabilities

### New Capabilities
- `nested-reference-validation`: a schema property shaped as an array of objects whose nested
  property is itself a `$ref` MUST validate a valid UUID successfully on direct object writes,
  matching the existing support for a top-level `$ref` property and for an array item that is
  itself a `$ref`.
- `annotation-validation-surfacing`: a schema-save response MUST include a structured warnings list
  naming every `x-openregister-*` advisory annotation that failed validation and was discarded, so
  discard is visible in the API response, not only in `nextcloud.log`.

### Modified Capabilities
- `aggregation-api`: the named-annotation surface (`x-openregister-aggregations`) MUST validate a
  filter/groupBy field against the schema that owns it, not unconditionally against the declaring
  schema.
- `computed-fields`: the JSON-AST calculation operator vocabulary gains `mul`/`add`/`sub`/`div`
  aliases; a calculation's declared name MUST also be a declared schema property for the annotation
  to validate.
- `import-resilient-per-entity-and-no-user-context`: seed-data object import MUST resolve `$ref`
  property values to their target UUID rather than persisting the raw seed-authored slug.

## Impact

- **Changed code**: `AggregationAnnotationValidator`, `CalculationAnnotationValidator`,
  `CalculationEvaluator` (operator dispatch), `SchemaMapper::validate*Annotation()` family (warnings
  surfacing), `ImportHandler::importSeedDataObjects()` ($ref resolution), `ValidateObject.php`
  (nested array-of-objects `$ref` stripping).
- **No new OR schema.** No database migration. No breaking change to a schema that already validates
  cleanly today — every fix widens acceptance (aliases, correct target-schema resolution) or adds a
  new rejection only for a shape that was already silently non-functional (calc name not in
  properties).
- **Consumers**: decidesk's Meeting quorum/actionItem aggregations, and any other app that declared
  `x-openregister-aggregations`/`-calculations` and silently got nothing, become functional once
  this ships — a re-import (or a schema re-save) is required to pick up the corrected validation;
  this change does not retroactively re-materialise already-discarded annotations on existing
  schemas (that is an operator action, `occ openregister:rematerialise-calculations` per the existing
  `computed-fields` capability, once the schema itself is re-saved with a now-valid annotation).
- **Downstream**: decidesk's Back to Six programme depends on this landing before Meeting's
  organisation-goal aggregations can be verified live.
