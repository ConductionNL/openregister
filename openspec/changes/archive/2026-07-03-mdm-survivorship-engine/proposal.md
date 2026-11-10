---
kind: code
depends_on: []
---

## Why

The `mdm-foundation` change (archived) gave OpenRegister the MDM *primitives* —
declarative `x-openregister-quality` scoring and `x-openregister-dedup`
detection, both materialised on save. It explicitly deferred **golden record /
survivorship**: resolving one master object's authoritative attributes from its
linked source records via trust tiers. That logic still lives, hardcoded, inside
pipelinq (`MasterEntityService` + `TrustConfigurationService`).

ADR-045 makes this the OpenRegister team's problem: OpenRegister owns the generic
MDM surface, and **survivorship is one of its named engines** — "entity-type-agnostic,
configured per schema, not hardcoded to contact/account/product". ADR-031 dictates
*how*: survivorship IS materialised derived state computed on save, so it MUST be a
schema annotation + a save-time listener (the `x-openregister-quality` idiom), not a
bespoke per-app service. This change lifts pipelinq's survivorship into OpenRegister as
a declarative capability, so any register with linked source records gets golden-record
resolution for free by declaring config — no engine code per app.

## What Changes

- **New schema annotation `x-openregister-survivorship`** declaring which fields
  hold the linked source records, the golden record, and provenance, plus the trust
  tier order, the freshness anchor, and the tie-break strategy. Registered in
  `Schema::ANNOTATION_VOCABULARY` and shape-validated (non-fatal warning on malformed)
  by a new `SurvivorshipAnnotationValidator`, mirroring `QualityAnnotationValidator` /
  `DedupAnnotationValidator`.
- **A pure `SurvivorshipResolver` service** (entity-type-agnostic) generalising
  pipelinq's `MasterEntityService::resolveGoldenRecord` / `pickWinner`: per attribute,
  resolve each non-withdrawn source's trust tier (running-max on the configured
  `tierOrder`), drop discard-tier, apply freshness decay (step one tier down once
  elapsed > `freshnessDecayDays`), and break ties by most-recent update — **compared as
  dates, not lexically** (an improvement over pipelinq's string comparison). Emits
  `goldenRecord` + `attributeProvenance`.
- **A new OpenRegister-owned `trustConfiguration` register/schema** carrying the
  generic 3-tuple trust rows (`entityType` + `attribute` + `sourceSystem` → tier +
  `freshnessDecayDays` + `effectiveFrom`), queried by a `TrustTierResolver`. Generic and
  queryable, so trust config is data (governable, RBAC-scoped, auditable) rather than
  frozen inside the annotation.
- **A save-time `SurvivorshipRecomputeListener`** on `ObjectCreatingEvent` /
  `ObjectUpdatingEvent`: when the schema declares `x-openregister-survivorship`, it
  recomputes and materialises `goldenRecord` + `attributeProvenance` onto the master
  object. Fail-soft — logs a warning and never aborts the save — exactly like
  `QualityScoreOnSaveListener`. Registered in `lib/AppInfo/Application.php`.

This change is **additive and backward-compatible**: one new annotation key, one new
register/schema, one resolver, one trust resolver, one validator, one listener. Schemas
without the annotation are unaffected; existing save behaviour is unchanged.

## Capabilities

### New Capabilities

- `mdm-survivorship`: declarative, entity-type-agnostic golden-record resolution — the
  `x-openregister-survivorship` annotation, the trust-tier resolution algorithm, the
  generic `trustConfiguration` register, and on-save materialisation of
  `goldenRecord` + `attributeProvenance`.

### Modified Capabilities

<!-- none — data-quality-scoring and duplicate-detection are NOT modified. -->

## Impact

- **New files** (all `openregister/`): `lib/Service/Survivorship/SurvivorshipResolver.php`,
  `lib/Service/Survivorship/TrustTierResolver.php`,
  `lib/Service/Survivorship/SurvivorshipAnnotationValidator.php`,
  `lib/Listener/SurvivorshipRecomputeListener.php`, a `trustConfiguration` register/schema
  in `lib/Settings/`, and PHPUnit tests under `tests/Unit/Service/Survivorship/`.
- **Edited files**: `lib/Db/Schema.php` (add key to `ANNOTATION_VOCABULARY`),
  `lib/Db/SchemaMapper.php` (wire `validateSurvivorshipAnnotation()`),
  `lib/AppInfo/Application.php` (register the listener).
- **Downstream**: unblocks the follow-on `mdm-merge-engine` (reversible merge) and the
  pipelinq `mdm-consume-or-surface` migration that deletes pipelinq's
  `MasterEntityService` / `TrustConfigurationService`. No app migrates on this change;
  it only ships the engine.

### Non-Goals (deferred to chained follow-on changes)

- **Reversible MERGE** (snapshot / relink / recompute / audit / reverse). That is the
  separate `mdm-merge-engine` change. This change resolves the golden record but does not
  merge master objects.
- **Nested-path dedup projection.** pipelinq materialises flat `match*` fields
  (`matchName`, `matchEmail`, …) because OpenRegister's `x-openregister-dedup`
  `SimilarityCalculator` reads top-level keys only, not nested `goldenRecord.*` paths.
  The *proper* fix is a small OR dedup-engine change making `x-openregister-dedup` read
  nested paths, so leaf apps drop the `match*` hack. This resolver does **not** emit the
  `match*` fields; the nested-path fix is a related follow-on, tracked separately.
- **Frontend** (the steward-facing survivorship view) — that is `mdm-frontend`.
- **GDPR right-of-deletion** integration and the **pipelinq migration** — later changes.
