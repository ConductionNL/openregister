## 1. Data-quality scorer (pure)

- [x] 1.1 Add `Service\Quality\QualityScorer` — pure `score()` (weighted mean of `required` / `format` / `freshness` sub-scores, null-safe, never fatal) + `status()` (good/fair/poor from thresholds) in `lib/Service/Quality/QualityScorer.php`.

## 2. Quality on-save listener

- [x] 2.1 Add `Listener\QualityScoreOnSaveListener` listening to `ObjectCreatingEvent` + `ObjectUpdatingEvent`, reading `x-openregister-quality`, materialising the score (+ optional status) onto the entity, fail-soft, in `lib/Listener/QualityScoreOnSaveListener.php`. Register it in `lib/AppInfo/Application.php`.

## 3. Quality annotation vocabulary + validation

- [x] 3.1 Register `x-openregister-quality` in `Schema::ANNOTATION_VOCABULARY` (`lib/Db/Schema.php`); add `Service\Quality\QualityAnnotationValidator` and wire `validateQualityAnnotation()` into `lib/Db/SchemaMapper.php` (non-fatal warning on malformed).

## 4. Similarity primitives (pure)

- [x] 4.1 Add `Service\Quality\SimilarityCalculator` — pure `similarity()` (`exact` / `normalized` / `levenshtein`) + `blockingToken()`, null/non-scalar safe, in `lib/Service/Quality/SimilarityCalculator.php`.

## 5. Dedup annotation vocabulary + validation

- [x] 5.1 Register `x-openregister-dedup` in `Schema::ANNOTATION_VOCABULARY`; add `Service\Quality\DedupAnnotationValidator` and wire `validateDedupAnnotation()` into `lib/Db/SchemaMapper.php` (non-fatal warning on malformed).

## 6. Duplicate-detection service

- [x] 6.1 Add `Service\Quality\DuplicateDetectionService::findDuplicates(register, schema, matchRules?, threshold?)` — load candidates via `ObjectService::findAll` (RBAC + tenant scoped, capped), partition by blocking keys, score intra-bucket pairs, return scored candidate pairs sorted desc; rules default to the schema annotation. In `lib/Service/Quality/DuplicateDetectionService.php`.

## 7. Tests

- [x] 7.1 Unit-test `QualityScorer` (complete vs incomplete object, weighting, format valid/invalid, freshness decay, null-safe, unknown type, status thresholds).
- [x] 7.2 Unit-test `SimilarityCalculator` (exact / normalized / levenshtein, unknown method, non-scalar, blocking token).
- [x] 7.3 Unit-test `QualityAnnotationValidator` + `DedupAnnotationValidator` (valid, absent, malformed shapes).
- [x] 7.4 Unit-test `DuplicateDetectionService` (finds near-dup, below-threshold empty, <2 objects empty, annotation-rule fallback, blocking-key partition, no-rules empty) with mocked `ObjectService` / `SchemaMapper`.

## 8. Validation

- [x] 8.1 Run the Quality unit suite green; confirm no regression in OR's existing Unit suite (pre-existing failures unchanged).
- [x] 8.2 Run `composer check:strict` (PHPCS, PHPMD, Psalm/PHPStan) on the changed files; fix what is touched + pre-existing in touched.
- [x] 8.3 Live-verify on `:8080` — seed a scratch register/schema with both annotations; confirm `findDuplicates` flags a seeded near-duplicate and the scorer computes a score (direct invocation + units; on-save materialisation noted as env-dependent).
