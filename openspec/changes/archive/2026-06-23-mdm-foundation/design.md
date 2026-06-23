# Design — MDM foundation (data-quality scoring + duplicate detection)

## Context

OpenRegister materialises declarative derived fields on save via
`CalculationOnSaveListener`, which listens to `ObjectCreatingEvent` /
`ObjectUpdatingEvent`, reads an annotation off the schema configuration, runs a
**pure** evaluator, and patches the result onto the `ObjectEntity` before
`MagicMapper` persists it. Annotation keys live in `Schema::ANNOTATION_VOCABULARY`
and are shape-validated at schema import by `SchemaMapper`, which degrades a
malformed advisory annotation to a non-fatal warning. This change reuses that
exact idiom for two MDM primitives.

## Capability 1 — Data-quality scoring

### Annotation: `x-openregister-quality`

```json
{
  "x-openregister-quality": {
    "field": "qualityScore",
    "statusField": "qualityStatus",
    "rules": [
      { "type": "required",  "field": "name" },
      { "type": "required",  "field": "email", "weight": 2 },
      { "type": "format",    "field": "email", "format": "email" },
      { "type": "format",    "field": "kvk",   "pattern": "^[0-9]{8}$" },
      { "type": "freshness", "field": "updatedAt", "halfLifeDays": 180 }
    ],
    "thresholds": { "good": 0.8, "fair": 0.5 }
  }
}
```

- `field` (optional, default `qualityScore`) — where the score is materialised.
- `statusField` (optional) — when set, a `good` / `fair` / `poor` label is materialised.
- `rules` (required, non-empty) — each rule has a `type`, a `field` (dotted paths
  supported for nesting), and an optional numeric `weight` (default 1).
  - `required`: 1.0 when the field is present and non-empty, else 0.0.
  - `format`: 1.0 when the value matches a named `format` (`email` / `url` / `date`)
    or a custom `pattern`, else 0.0; an absent field scores 0.0.
  - `freshness`: half-life decay `2^(-ageDays/halfLifeDays)` of a date field against
    now; absent / unparseable date scores 0.0.
- `thresholds` (optional) — `good` / `fair` cut-offs for the status label.

### Output

The object score is the **weight-normalised mean** of per-rule sub-scores, in `[0,1]`,
rounded to 4 dp. An object with no usable rule scores 1.0 (trivially compliant).

### Components

- `Service\Quality\QualityScorer` — pure: `score(array $object, array $rules,
  DateTimeImmutable $now): float` + `status(float, array): string`. No I/O, null-safe,
  never fatal (unknown rule type → 0.0 sub-score).
- `Listener\QualityScoreOnSaveListener` — on `ObjectCreatingEvent` /
  `ObjectUpdatingEvent`, loads the schema, reads `x-openregister-quality`, scores the
  payload, and patches `field` (+ optional `statusField`) onto the entity. Fail-soft:
  any error logs a warning and leaves the save untouched.
- `Service\Quality\QualityAnnotationValidator` — `validate(array $shape): array` of
  `{code, message}`; invoked by `SchemaMapper::validateQualityAnnotation()` which
  degrades errors to a non-fatal warning.

## Capability 2 — Duplicate detection

### Annotation: `x-openregister-dedup`

```json
{
  "x-openregister-dedup": {
    "blockingKeys": ["postalCode"],
    "matchRules": [
      { "field": "email", "method": "exact",       "weight": 0.5 },
      { "field": "name",  "method": "normalized",   "weight": 0.3 },
      { "field": "name",  "method": "levenshtein",  "weight": 0.2 }
    ],
    "threshold": 0.85
  }
}
```

- `blockingKeys` (optional) — fields whose normalised composite token must be equal
  for two objects to be compared at all; restricts the comparison set so detection
  does not degrade to all-pairs on large registers.
- `matchRules` (required, non-empty) — per-field similarity: `exact` (byte-identical),
  `normalized` (case/whitespace/accent-folded equality), `levenshtein`
  (`1 - editDistance/maxLen`). Optional numeric `weight` (default 1).
- `threshold` (optional, default 0.85) — minimum weighted score for a pair to be reported.

### Service contract

```php
DuplicateDetectionService::findDuplicates(
    int|string $register,
    int|string $schema,
    ?array $matchRules = null,   // null → use the schema's x-openregister-dedup rules
    ?float $threshold = null     // null → annotation threshold, else 0.85
): array  // [ { objectA, objectB, score, matchedOn[] }, ... ] sorted desc by score
```

- DI-resolvable; consuming apps inject `DuplicateDetectionService`.
- Candidates loaded via `ObjectService::findAll(['filters' => ['register' => …,
  'schema' => …], 'limit' => 1000])` — **RBAC + tenant scoped** under the caller's
  session, capped at `MAX_CANDIDATES`.
- Objects partitioned into blocking buckets; only intra-bucket pairs compared
  (O(n²) within a bucket, not across the whole set). Singleton buckets dropped.
- Score = weight-normalised mean of per-rule similarities; `matchedOn` lists fields
  whose similarity ≥ 0.9.

### Components

- `Service\Quality\SimilarityCalculator` — pure: `similarity(method, a, b): float`
  + `blockingToken(method, value): string`. Null/non-scalar → 0.0 / empty token.
- `Service\Quality\DuplicateDetectionService` — orchestrates load → partition → score.
- `Service\Quality\DedupAnnotationValidator` — shape validator invoked by
  `SchemaMapper::validateDedupAnnotation()` (non-fatal warning on malformed).

## Wiring

- `Schema::ANNOTATION_VOCABULARY` gains `x-openregister-quality` and `x-openregister-dedup`.
- `SchemaMapper` calls the two new validators alongside the existing annotation validators.
- `Application::registerEventListeners()` registers `QualityScoreOnSaveListener` on
  `ObjectCreatingEvent` + `ObjectUpdatingEvent`, next to `CalculationOnSaveListener`.

## Trade-offs

- **Levenshtein over Jaro-Winkler.** Levenshtein is in the PHP core and sufficient for a
  foundation; richer methods are an additive follow-up.
- **Blocking is opt-in.** Without `blockingKeys` the whole RBAC-scoped set is compared
  pairwise; the design caps the candidate set and documents the recommendation to declare
  blocking keys for large registers.
- **No golden record.** Survivorship/merge is deferred; `findDuplicates` returns candidate
  pairs only, leaving the merge policy to the consuming app.
