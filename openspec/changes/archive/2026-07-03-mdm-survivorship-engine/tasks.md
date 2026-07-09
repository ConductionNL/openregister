## 1. Trust-tier resolver (pure)

- [x] 1.1 Add `Service\Survivorship\TrustTierResolver` — resolve the effective tier for a `(entityType, attribute, sourceSystem)` tuple honouring `effectiveFrom` (most-recent on/before as-of date wins; null when none) + `applyFreshnessDecay()` (step one tier down on `tierOrder` when elapsed > `freshnessDecayDays`), pure + null-safe, in `lib/Service/Survivorship/TrustTierResolver.php`.

## 2. Survivorship resolver (pure)

- [x] 2.1 Add `Service\Survivorship\SurvivorshipResolver::resolveGoldenRecord(entityType, sourceRecords, config, trustResolver)` — iterate non-withdrawn sources, skip null/empty values, resolve tier via `TrustTierResolver`, running-max `pickWinner` on `tierOrder` rank, drop `discardTier`, tie-break by most-recent anchor **parsed as a date not lexically**, emit `goldenRecord` + `attributeProvenance`; entity-type-agnostic, pure, never fatal, in `lib/Service/Survivorship/SurvivorshipResolver.php`.

## 3. Annotation vocabulary + validation

- [x] 3.1 Register `x-openregister-survivorship` in `Schema::ANNOTATION_VOCABULARY` (`lib/Db/Schema.php`).
- [x] 3.2 Add `Service\Survivorship\SurvivorshipAnnotationValidator` (shape-validate `sourceLinkField`, `tierOrder` array, `defaultTier`/`discardTier` ∈ `tierOrder`, field names) and wire `validateSurvivorshipAnnotation()` into `lib/Db/SchemaMapper.php` (non-fatal warning on malformed).

## 4. On-save materialisation listener

- [x] 4.1 Add `Listener\SurvivorshipRecomputeListener` on `ObjectCreatingEvent` + `ObjectUpdatingEvent` — read `x-openregister-survivorship`, load linked sources from `sourceLinkField`, invoke `SurvivorshipResolver`, materialise `goldenRecordField` + `provenanceField` (only when changed), fail-soft (log, never abort), in `lib/Listener/SurvivorshipRecomputeListener.php`; register both events in `lib/AppInfo/Application.php`.

## 5. Trust configuration register/schema + seed

- [x] 5.1 Add the OR-owned `trustConfiguration` register/schema (`entityType`, `attribute`, `sourceSystem`, `trustTier`, `freshnessDecayDays`, `effectiveFrom`) in `lib/Settings/`, with the generic `x-openregister-seed` rows from design.md (nil-UUID examples; generic org/source data — municipal-registry/consultancy-crm/travel-agency-booking, gold/silver/bronze).

## 6. Compliance headers + spec tags (gate-16)

- [x] 6.1 Add SPDX headers (`SPDX-License-Identifier: EUPL-1.2` in the file docblock) and `@spec openspec/changes/mdm-survivorship-engine/specs/mdm-survivorship/spec.md` tags to every new PHP file + changed method.

## 7. Tests (PHPUnit, CI way)

- [x] 7.1 Unit-test `TrustTierResolver` (most-recent effectiveFrom wins, future row ignored → null, no match → null, freshness-decay steps one tier, null-safe) — runnable under `php:8.3-cli` + OCP stubs.
- [x] 7.2 Unit-test `SurvivorshipResolver` (higher tier wins, discard excluded, uncontested→default tier, withdrawn/empty excluded, date-correct tie-break vs mixed formats, malformed source skipped) and `SurvivorshipAnnotationValidator` (valid / absent / malformed shapes).

## 8. Validation

- [x] 8.1 Run the Survivorship unit suite green; confirm no regression in OR's existing Unit suite (pre-existing failures unchanged).
- [x] 8.2 Run `composer check:strict` (PHPCS, PHPMD, Psalm/PHPStan) on the changed files; fix touched + pre-existing-in-touched.
- [ ] 8.3 Live-verify on `:8080` — seed a scratch register/schema declaring `x-openregister-survivorship` with linked source records; confirm the golden record + provenance materialise on save and honour tier + freshness. NOT DONE this session: the running `:8080` container binds a different openregister checkout, and deploying this worktree's code into it was out of scope per the "don't touch any other checkout" constraint. Needs a follow-up live-verify pass.

## Acceptance criteria (plain bullets — not checkboxes)

- A schema declaring `x-openregister-survivorship` materialises `goldenRecord` + `attributeProvenance` on create and update; a schema without it is untouched.
- The resolver is entity-type-agnostic (no hardcoded attribute/entity names) and pure (no I/O, never fatal).
- Higher trust tier wins; `discardTier` never selected; an uncontested source populates via `defaultTier`.
- Freshness decay steps a stale source down exactly one tier; ties break by the most-recent anchor compared **as dates**.
- Trust rows resolve from the generic `trustConfiguration` register honouring `effectiveFrom`; no match → `defaultTier` fallback.
- The listener is fail-soft: a resolution error logs a warning and never aborts the save.
- A malformed annotation is a non-fatal warning at import; the schema still stores objects.

## Quality checklist (plain bullets — not checkboxes)

- No leaf-app survivorship service is added; the surface is the annotation + listener (ADR-045 / ADR-031).
- The `SurvivorshipResolver` mirrors `QualityScorer`'s pure-engine contract; the listener mirrors `QualityScoreOnSaveListener` (fail-soft).
- The `match*` flat-projection hack is NOT introduced (nested-path dedup fix is a separate follow-on).
- SPDX + `@spec` tags on every new PHP file (gate-16); PHPUnit runs the CI way (`php:8.3-cli` + OCP stubs).
- Placeholder hygiene: nil UUID / `YOUR_API_KEY_HERE` only in examples; no real secrets.
- `composer check:strict` green on touched files; existing OR unit suite shows no new regressions.
