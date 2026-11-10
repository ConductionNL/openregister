---
kind: code
---

## Why

Master-data management (MDM) — data-quality scoring and duplicate detection — is
being hand-rolled inside individual apps. pipelinq ships its own `DataQualityScorer`,
`DuplicateDetectionService`, and a `masterEntity`/`trustConfiguration` schema family;
other apps will need the same primitives. Per ADR-022 (apps consume OR abstractions),
the foundational, declarative parts of MDM belong in OpenRegister so any app can
declare quality + dedup on a schema and consume a shared, RBAC/tenant-scoped service
instead of re-implementing scoring and similarity per app.

OpenRegister already has the right pattern for this: declarative schema annotations
(`x-openregister-calculations`, `-aggregations`) materialised on save by a listener,
validated at schema-import time, and evaluated by a pure, null-safe evaluator. This
change adds two foundational MDM capabilities in exactly that idiom.

## What Changes

- **Data-quality scoring** — a new schema annotation `x-openregister-quality` declaring
  a list of quality rules (`required` / `format` / `freshness`). A pure `QualityScorer`
  computes a weight-normalised per-object score in `[0,1]`; a `QualityScoreOnSaveListener`
  materialises it into a configurable field (default `qualityScore`) on
  `ObjectCreatingEvent` / `ObjectUpdatingEvent`, mirroring `CalculationOnSaveListener`.
  An optional `statusField` materialises a `good` / `fair` / `poor` label from
  thresholds. The annotation key is registered in `Schema::ANNOTATION_VOCABULARY` and
  shape-validated (non-fatal warning on malformed) by `QualityAnnotationValidator` via
  `SchemaMapper`.
- **Duplicate detection** — a new schema annotation `x-openregister-dedup` declaring
  blocking keys + per-field match rules (`exact` / `normalized` / `levenshtein`) and a
  threshold, plus a DI-resolvable `DuplicateDetectionService::findDuplicates(register,
  schema, matchRules?, threshold?)`. Candidates are loaded through `ObjectService::findAll`
  (RBAC + tenant scoped), partitioned into blocking buckets to avoid all-pairs
  comparison, scored by a pure `SimilarityCalculator`, and returned as scored candidate
  pairs (`objectA`, `objectB`, `score`, `matchedOn`). Match rules default to the schema
  annotation when the caller omits them. `DedupAnnotationValidator` shape-validates the
  annotation (non-fatal warning on malformed) via `SchemaMapper`.

This change is **additive and backward-compatible**: two new annotation keys, two new
listeners/services, no change to existing operators, routes, or save behaviour. Schemas
without the annotations are unaffected.

## Capabilities

### New Capabilities

- `data-quality-scoring`
- `duplicate-detection`

### Modified Capabilities

<!-- none -->

## Non-Goals (Deferred)

- **Golden record / survivorship / merge.** Trust-tier resolution, attribute provenance,
  merge/reverse-merge, and the `masterEntity`/`trustConfiguration`/`syncQueue` schema
  family remain app-specific (pipelinq) for now. This change delivers only the two
  reusable, declarative primitives (scoring + candidate detection); golden-record
  construction is a follow-up that can build on top of `findDuplicates`.
- **Phonetic / Jaro-Winkler / TF-IDF similarity.** The foundation ships exact / normalized
  / levenshtein; richer similarity methods are an additive follow-up to `SimilarityCalculator`.
