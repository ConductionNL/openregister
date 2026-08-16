# Proposal: unit-test-coverage-phase2

## Summary
Write ~136 new test files to achieve 100% unit test code coverage for all ~395 PHP source files in `lib/`. Phase 1 (fixing broken tests) is complete — 1,144 tests pass with 0 errors. Phase 2 covers the ~370 source files that currently have zero test coverage.

## Motivation
- Only ~26 of ~395 source files have test coverage
- Estimated coverage is well below the 75% threshold
- No tests exist for 39 Event classes, 54 Controllers, 18 Db handlers, 7 Listeners, 9 BackgroundJobs, 3 Commands, 4 Cron jobs, 5 Tools, 9 Exceptions, and ~170 Service files
- Untested code hides regressions introduced during frequent refactoring

## Approach
- Work in batches by category, starting with simple/high-volume classes (Events, Exceptions, Entities) and progressing to complex ones (Services, Controllers)
- Each test file covers ALL code paths — every branch, error path, and edge case
- Use data providers to group similar classes (e.g., all CRUD events in one test class)
- Follow established patterns: `PHPUnit\Framework\TestCase`, comprehensive mocking, real Entity instances (not mocks)

## Risks
- Large scope (~136 new test files) — mitigated by batching and parallel implementation
- Source code may change during implementation — mitigated by reading source before writing each test
- Some classes may have untestable dependencies — mark as skipped with explanation

## Current Coverage Baseline

As of 2026-03-16, the project has **310 test files** covering **492 source files** in `lib/`. However, many test files were created during Phase 1 or early Phase 2 work. The breakdown by category:

| Category | Source Files | Test Files | Gap |
|---|---|---|---|
| Event | 39 | 5 | Covered via DataProvider grouping (5 test files cover all 39 events) |
| Exception | 10 | 2 | 8 uncovered (note: 10 exceptions, not 9 — `ReferentialIntegrityException` was added) |
| Formats | 2 | 1 | 1 uncovered |
| Db (entities+mappers+handlers) | 65 | 31 | 34 uncovered |
| Controller | 58 (46 root + 12 Settings) | 78 | Well covered (some controllers have multiple test files) |
| Service | 175 (43 root + 132 subdirs) | 147 | 28 uncovered |
| BackgroundJob | 10 | 8 | 2 uncovered |
| Command | 3 | 4 | Covered (extra coverage/deep tests) |
| Cron | 4 | 4 | Covered |
| Listener | 8 | 7 | 1 uncovered |
| Tools | 0 | 0 | Directory is empty — Tools were removed from codebase |
| Notification/Repair/Search/Sections/Settings | 5 | in "Other: 23" | Likely covered |
| GraphQL (new) | 12 | Not in original plan | 12 uncovered — not in original batches |
| Migration | 91 | 0 | Excluded from coverage in phpunit-unit.xml |
| AppInfo | 1 | 0 | Excluded from coverage in phpunit-unit.xml |

**Key finding:** The `lib/Db/` directory is **excluded from coverage** in `phpunit-unit.xml` (`<exclude><directory>lib/Db/</directory></exclude>`). This means Batch 4 (Entities), Batch 5 (Mappers), and Batch 6 (Handlers) tests will not count toward coverage metrics unless the exclusion is narrowed. This should be addressed.

**Key finding:** The `lib/Tools/` directory is empty — the Tools classes were removed. Batch 11 Task 11.1 is obsolete.

**Key finding:** 12 GraphQL service files exist (`lib/Service/GraphQL/`) but were not included in any batch.

## Test Infrastructure Status

- **PHPUnit version:** 10.5+ (xsd reference: `phpunit.xsd` 10.5)
- **Bootstrap:** `tests/bootstrap-unit.php` loads full Nextcloud environment via `lib/base.php` when running inside Docker container. Also registers `Test\` namespace from server test lib.
- **Execution order:** `depends,defects` — failed tests run first
- **Strictness:** `failOnRisky=true`, `failOnWarning=true`, `beStrictAboutOutputDuringTests=true`, `beStrictAboutCoverageMetadata=true`
- **Coverage reports:** Clover XML, HTML, and text output configured; requires `php-pcov` in Docker container
- **Cache:** `.phpunit.cache` directory used for test result caching
- **Coverage scope:** `lib/` included, `lib/Migration/`, `lib/Db/`, and `lib/AppInfo/Application.php` excluded

## Standards & Best Practices

- **PHPUnit 10+ attributes:** Use `#[DataProvider('name')]` attribute (not `@dataProvider` annotation)
- **Intersection types for mocks:** Use `ClassName&MockObject` (PHPUnit 10 style, already used in existing tests)
- **Nextcloud Entity testing:** Use real entity instances, never mocks — Entity uses `__call` magic for getters/setters
- **Named args prohibition:** NEVER use named arguments on Entity setters — `__call` passes `['name' => val]` but setter expects `$args[0]`
- **Final class constraint:** `ArrayLoader` and some Nextcloud classes are `final` — use real instances, not mocks
- **Positional parameters only:** PHPUnit API calls must use positional parameters
- **Reflection for id:** Entity `$id` is private in parent — use `ReflectionProperty` to set it in tests

## Spec Reference
- `openregister/openspec/specs/unit-test-coverage/spec.md` (Phase 2 section)
