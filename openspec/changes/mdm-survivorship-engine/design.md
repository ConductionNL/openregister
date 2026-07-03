## Context

`mdm-foundation` (archived) delivered OpenRegister's MDM primitives — declarative
`x-openregister-quality` scoring and `x-openregister-dedup` detection, each materialised
on save by a listener over a pure evaluator. It explicitly deferred golden record /
survivorship. That logic today lives, hardcoded to contact/account/product, inside
pipelinq: `MasterEntityService::resolveGoldenRecord` / `pickWinner` (the resolution
algorithm) and `TrustConfigurationService` (the `(entityType, attribute, sourceSystem)`
trust-tier lookup + freshness decay over a `trustConfiguration` schema).

ADR-045 assigns survivorship to OpenRegister: an "entity-type-agnostic" engine
"configured per schema, not hardcoded". ADR-031 dictates the shape: survivorship is
materialised derived state computed on save, so it belongs in a schema annotation +
a save-time listener, mirroring `x-openregister-quality`, not a bespoke app service.

This design lifts pipelinq's survivorship into OpenRegister as the `mdm-survivorship`
capability, improving on the reference where warranted (date-correct tie-break).

## Goals / Non-Goals

**Goals:**
- A generic `x-openregister-survivorship` annotation any register can declare.
- A pure, entity-type-agnostic `SurvivorshipResolver` generalised from pipelinq's
  algorithm: running-max tier resolution, discard-drop, freshness decay, date-correct
  tie-break, `goldenRecord` + `attributeProvenance` output.
- A generic OR-owned `trustConfiguration` register + `TrustTierResolver` (3-tuple lookup
  honouring `effectiveFrom`, freshness decay).
- On-save materialisation via a fail-soft `SurvivorshipRecomputeListener`, wired exactly
  like `QualityScoreOnSaveListener`.

**Non-Goals:**
- **Reversible MERGE** (snapshot/relink/recompute/audit/reverse) → chained follow-on
  `mdm-merge-engine`.
- **Nested-path dedup projection** (the `match*` flat-field hack) → separate small OR
  dedup-engine change making `x-openregister-dedup` read nested `goldenRecord.*` paths.
- **Frontend** (`mdm-frontend`), **GDPR right-of-deletion**, **pipelinq migration** — later.

## Decisions

### Declarative-vs-imperative decision (ADR-031)

**The survivorship surface is DECLARATIVE.** The behaviour a leaf app declares is a
schema annotation (`x-openregister-survivorship`) plus a save-time listener that
materialises `goldenRecord` + `attributeProvenance` onto the object — precisely the
`x-openregister-quality` idiom (annotation + `QualityScoreOnSaveListener` materialising
`qualityScore`/`qualityStatus`). A leaf app writes **zero** survivorship service code;
it declares config on its schema, same as it declares quality rules today. This directly
satisfies ADR-045 ("survivorship configured per schema, not hardcoded") and ADR-031
("when OR can express behaviour as schema metadata, prefer that over a service class").
The anti-pattern ADR-045 names — an app-local `*MasterEntity*` / `*Survivorship*`
service — is exactly what this change makes unnecessary.

**The `SurvivorshipResolver` is the imperative engine BEHIND the declarative surface.**
This is NOT an ADR-031 exception. It mirrors the sanctioned split already shipped for
quality: `x-openregister-quality` (declarative) is backed by the pure `QualityScorer`
(imperative engine), invoked by the listener. Survivorship follows the identical
topology — `x-openregister-survivorship` (declarative) backed by `SurvivorshipResolver`
+ `TrustTierResolver` (pure engines), invoked by `SurvivorshipRecomputeListener`. The
engines are OpenRegister-internal machinery that implements a declarative extension;
they are not app-authored business logic. No new `x-openregister-*` extension is missing
here, so ADR-031 exceptions (1)/(2)/(3) do not apply — this IS the declarative path, and
the engine is OR's to own per ADR-045.

### Algorithm — generalised from pipelinq, with fixes

- **Entity-type-agnostic.** `resolveGoldenRecord(entityType, sourceRecords, config,
  trustResolver)` takes `entityType` as a parameter and reads attribute names from the
  data, never hardcoding `name`/`email`/`kvkNumber`. pipelinq's flat `matchName` /
  `matchEmail` / `matchKvkNumber` / `matchPhone` materialisation is **dropped** — it is
  the dedup-nested-path workaround, out of scope here (see Non-Goals).
- **Running-max on `tierOrder`.** `pickWinner` keeps the pipelinq shape: iterate
  candidates, track the best by tier rank (index in `tierOrder`), replacing on strictly
  higher rank or on equal rank + more-recent update.
- **Tie-break compared as DATES, not lexically.** pipelinq compares
  `(string) $option['lastUpdated'] > (string) $winner['lastUpdated']` — lexical, which
  misorders differently-formatted timestamps. The resolver parses both anchors via
  `DateTimeImmutable` and compares timestamps; unparseable/absent sorts as oldest and
  never throws. This is the one deliberate behavioural improvement over the reference.
- **Freshness decay.** Generalised from `TrustConfigurationService::applyFreshnessDecay`:
  if elapsed since the anchor date > `freshnessDecayDays`, step the tier down one level
  on `tierOrder`; a resulting `discardTier` is then excluded.
- **Default / discard tiers configurable.** pipelinq hardcodes `'bronze'` default and
  `'discard'`; here both come from the annotation (`defaultTier`, `discardTier`) with
  those same values as defaults, so the engine is genuinely generic.

### Trust configuration as a register (not annotation-inline)

Trust rows live in an OR-owned `trustConfiguration` register/schema (the pipelinq shape:
`entityType`, `attribute`, `sourceSystem`, `trustTier`, `freshnessDecayDays`,
`effectiveFrom`), resolved by `TrustTierResolver`. Rationale: trust config is
**operational, time-versioned, and cross-cutting** — stewards edit it, it carries
`effectiveFrom` history, and one row set can serve many schemas of the same entity type.
Freezing it inside each schema's annotation would make it un-queryable, un-auditable, and
duplicated per schema. Keeping it as data makes it RBAC-scoped and auditable via OR's
existing object infrastructure (ADR-022). The annotation only names the **lookup keys**
(`trustLookup.keys`), not the rows.

### Files (mirroring the quality trio)

| New file | Mirrors |
|---|---|
| `lib/Service/Survivorship/SurvivorshipResolver.php` | `Service/Quality/QualityScorer.php` (pure engine) |
| `lib/Service/Survivorship/TrustTierResolver.php` | (new — the 3-tuple/effectiveFrom/decay lookup) |
| `lib/Service/Survivorship/SurvivorshipAnnotationValidator.php` | `Service/Quality/QualityAnnotationValidator.php` |
| `lib/Listener/SurvivorshipRecomputeListener.php` | `Listener/QualityScoreOnSaveListener.php` |
| `lib/Settings/…trustConfiguration…` (register/schema) | pipelinq `trustConfiguration` schema |

`Schema::ANNOTATION_VOCABULARY` gains `x-openregister-survivorship`; `SchemaMapper` gains
`validateSurvivorshipAnnotation()` (non-fatal warning); `Application.php` registers the
listener on `ObjectCreatingEvent` + `ObjectUpdatingEvent`.

## Seed Data

This change adds the `x-openregister-survivorship` vocabulary key (a validator entry, not
data) AND a new OR-owned `trustConfiguration` register/schema. The register SHALL ship
with **generic, entity-type-agnostic seed rows** demonstrating gold/silver/bronze trust
across realistic non-domain-specific source systems, so the engine is exercisable
out-of-the-box without any leaf app. All example UUIDs use the nil UUID
`00000000-0000-0000-0000-000000000000`; no real secrets or tokens appear.

Illustrative seed rows (entityType `organisation`, generic sources — a municipal base
registry, a consultancy CRM, a travel-agency booking system):

| entityType | attribute | sourceSystem | trustTier | freshnessDecayDays | effectiveFrom |
|---|---|---|---|---|---|
| organisation | legalName | municipal-registry | gold | 365 | 2026-01-01 |
| organisation | legalName | consultancy-crm | silver | 180 | 2026-01-01 |
| organisation | legalName | travel-agency-booking | bronze | 90 | 2026-01-01 |
| organisation | email | consultancy-crm | gold | 120 | 2026-01-01 |
| organisation | email | municipal-registry | silver | 365 | 2026-01-01 |
| organisation | phone | travel-agency-booking | silver | 60 | 2026-01-01 |

These are demonstration rows only; a leaf app declaring `x-openregister-survivorship`
supplies (or the steward edits) its own trust rows via the register. The seed uses the
`x-openregister-seed` mechanism already present on OR schemas.

## Risks / Trade-offs

- **Generic is harder than hardcoded** (ADR-045 calls this out). Mitigation: keep the
  resolver a pure function driven entirely by the annotation config + trust rows;
  exhaustive unit tests over the branch matrix (tier win, discard, decay, tie-break,
  empty/withdrawn, default fallback).
- **Two-object lookup on every save** (the master object + its trust rows). Mitigation:
  the listener short-circuits immediately when the schema has no annotation (the common
  case), exactly like the quality listener; trust rows are small and query-cached by OR.
- **`trustConfiguration` as a register adds a schema to own.** Accepted per ADR-045 — OR
  owns the trust-config contract. It reuses OR's object infra (RBAC, audit) rather than a
  bespoke table (ADR-022).
- **Tie-break date-parse divergence from pipelinq.** The date-correct comparison can pick
  a different winner than pipelinq's lexical one in edge cases (mixed timestamp formats).
  This is intended and specced; the pipelinq migration will inherit the corrected
  behaviour.
