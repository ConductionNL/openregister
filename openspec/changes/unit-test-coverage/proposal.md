# Unit Test Coverage

## Why

OpenRegister is the foundation for every Conduction app — opencatalogi, docudesk, softwarecatalog, zaakafhandelapp, mydash, larpingapp, procest, pipelinq — meaning every undetected regression in `lib/` cascades into the entire product line. The codebase has ~708 PHP source files with only ~422 unit tests today, leaving the largest classes (MagicMapper, ObjectService, organisation/multi-tenancy paths, webhook delivery, format converters) under-covered on error branches and edge cases. ADR-008 already mandates a 75% coverage gate but it is not enforced in CI, so coverage drifts. This change writes the missing tests, locks in the gate, and standardises how unit tests are written across the codebase.

## What Changes

- Coverage gate enforcement: 75% line + method coverage minimum in `composer check:strict`; build fails below threshold.
- Standardise on PHPUnit `TestCase` with comprehensive mocking; ban Entity mocks (use real instances per `__call` quirk).
- Branch coverage for ~175 service classes including all error paths and edge cases.
- Coverage for ~46 root + 12 Settings controllers across CRUD and error handling.
- Coverage for ~65 Db entities and mapper handlers (full field coverage).
- Coverage for ~39 Event classes via DataProvider grouping.
- Coverage for BackgroundJob, Command, Cron, Listener, Exception, Format classes.
- Targeted suites for OrganisationService multi-tenancy, WebhookService delivery/retry, Import/Export handlers.
- Use of `DataProvider` for parameterised scenarios and Reflection for private methods/final classes.
- Standardised file organisation and naming convention under `tests/Unit/` mirroring `lib/` structure.
- Resolve the `phpunit-unit.xml` Db exclusion that currently hides Db handler coverage.
- CI integration: PCOV-instrumented run wired into `composer check:strict`.

## Problem
Achieve comprehensive unit test code coverage for all PHP source files in OpenRegister's `lib/` directory (excluding `Migration/` and `AppInfo/Application.php`), targeting 75% line and method coverage as the enforced gate with a stretch goal of 100%. This spec defines the testing standards, mocking strategies, coverage enforcement mechanisms, and per-category test requirements that ensure every code path -- happy flows, error branches, edge cases, and boundary conditions -- is exercised by automated tests.

## Proposed Solution
Achieve comprehensive unit test code coverage for all PHP source files in OpenRegister's `lib/` directory (excluding `Migration/` and `AppInfo/Application.php`), targeting 75% line and method coverage as the enforced gate with a stretch goal of 100%. This spec defines the testing standards, mocking strategies, coverage enforcement mechanisms, and per-category test requirements that ensure every code path -- happy flows, error branches, edge cases, and boundary conditions -- is exercised by autom
