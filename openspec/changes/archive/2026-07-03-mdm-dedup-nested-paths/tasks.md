## 1. Path resolution helper

- [x] 1.1 Add a private `resolvePath(array $data, string $path): mixed` helper to `DuplicateDetectionService` (`lib/Service/Quality/DuplicateDetectionService.php`), mirroring the existing `QualityScorer::fieldValue()` dot-path idiom: split on `.`, walk the array, return `null` on any missing segment or non-array container, never throw. Tag with `@spec openspec/changes/mdm-dedup-nested-paths/tasks.md#task-1`.

## 2. Wire the resolver into both read-sites

- [x] 2.1 Replace the direct `$data[$key] ?? null` read in `blockingTokenFor()` with `resolvePath($data, $key)`.
- [x] 2.2 Replace the direct `$dataA[$field] ?? null` / `$dataB[$field] ?? null` reads in `scorePair()` with `resolvePath($dataA, $field)` / `resolvePath($dataB, $field)`.
- [x] 2.3 Confirm a plain, dot-free field/key resolves identically to the prior direct-read behaviour (no `.` in the string never enters a differing code path in outcome).

## 3. Validator check (no over-restriction)

- [x] 3.1 Confirm `DedupAnnotationValidator::validateRule()` accepts a dotted-path `field` string as-is (it only checks non-empty string) — no code change expected; add a regression test proving it.

## 4. Tests

- [x] 4.1 Unit test: match rule on a nested path (`goldenRecord.email`) finds a duplicate pair scoring above threshold.
- [x] 4.2 Unit test: blocking key on a nested path (`goldenRecord.postalCode`) partitions candidates correctly.
- [x] 4.3 Unit test: missing intermediate segment (absent or non-array `goldenRecord`) resolves to `null` without throwing, scoring `0.0` for that field.
- [x] 4.4 Unit test: plain top-level field/key backward-compat — existing behaviour unchanged.
- [x] 4.5 Unit test: `DedupAnnotationValidator` validates a rule whose `field` is a nested dot-path.

## 5. Verification

- [x] 5.1 Run `vendor/bin/phpunit tests/Unit/Service/Quality` green (CI-equivalent php:8.3-cli container).
- [x] 5.2 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) on changed files; fix genuine pre-existing issues encountered in touched files.
- [x] 5.3 Run `openspec validate mdm-dedup-nested-paths --strict` and confirm it passes.
- [x] 5.4 Mark this file's checkboxes `[x]` once implementation + tests + checks are green.
