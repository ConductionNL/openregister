# Tasks — calc-engine-reference-lookup

## 1. Reference resolver (core)
- [x] Add `lib/Service/Calculation/ReferenceResolver.php` — pure-ish helper that, given the object payload (with `@self` already injected), a register/schema context, and an `x-openregister-references` map, returns a `@ref` array. Injects `ObjectService` + `LoggerInterface`.
- [x] Implement a tiny criterion-token resolver (`@self.<field>` tokens + single-key AST nodes like `{year: @self.date}` + literals) — NOT the full evaluator, to keep the evaluator pure and uncoupled. (Note: `@self.<field>` resolves the object's OWN top-level field when not a system-metadata field, so lookups parameterise by the saving object.)
- [x] Implement `relatedObject` mode: read local FK `field` → `ObjectService::find(id, schema, _rbac:true, _multitenancy:true)`; empty/missing → null.
- [x] Implement `lookup` mode: build `filters` (with register+schema context) → `ObjectService::findAll([...])`; support optional `effectiveDate` (desc sort + first row); take first match.
- [x] Wrap every resolution in try/catch → on `\Throwable` inject `null` + `logger->warning`; never rethrow.

## 2. Wire into the save + recompute paths
- [x] Extend `CalculationOnSaveListener::process()` to call `ReferenceResolver` in the same pre-step as `@self`, inject `@ref`, and strip it before persist. Inject `ReferenceResolver` via the constructor.
- [x] Mirror the same pre-resolution in `RematerialiseCalculationsCommand` so the recompute path resolves references too.

## 3. Validation
- [x] Extend `CalculationAnnotationValidator` to accept `@ref.<name>.<field>` prop tokens when `<name>` is a declared reference, and to validate the `x-openregister-references` shape (each ref has `schema` + valid `mode`; `relatedObject` requires `field`; `lookup` requires `filters`).
- [x] Register `x-openregister-references` in `Schema::ANNOTATION_VOCABULARY` (Db/Schema.php) + thread it through `SchemaMapper::validateCalculationsAnnotation()` — DISCOVERED: the schema-save fold drops any `x-openregister-*` key not in that allowlist, so the annotation was silently lost on import until added.

## 4. Tests
- [x] Add `tests/Unit/Service/Calculation/ReferenceResolverTest.php`: FK resolve, criteria/effective-dated resolve, missing-ref→null, exception-safety→null, RBAC scoping (assert `_rbac`/`_multitenancy` true on the mock). 5/5 green in NC34 container (PHP 8.4).

## 5. Quality + traceability
- [x] `php -l` every changed/new PHP file — all clean.
- [x] SPDX headers on the new file; `@spec` tags on changed/new methods.
- [x] Run the repo quality gate — PHPCS / PHPMD / Psalm / PHPStan all clean on changed files; full calc test suite 78/78 green. No issues introduced.

## 6. Live verify (shillinq, part of verification)
- [x] In `../shillinq/lib/Settings/shillinq_register.json`, added `country` property + `x-openregister-references.rate` block on `MileageEntry` + a `@ref`-reading materialised `ratePerKm` calc (and `totalAmount` reads `@ref.rate.ratePerKm` directly); re-imported. POSTed a `MileageRate` (NL/car/2026=0.21) + a `MileageEntry` → ratePerKm resolved to **0.21**, totalAmount computed = 150 × 0.21 = **31.5**. Missing-ref (country=DE) → ratePerKm null, save succeeds (no fatal).
