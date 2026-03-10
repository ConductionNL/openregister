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

## Spec Reference
- `openregister/openspec/specs/unit-test-coverage/spec.md` (Phase 2 section)
