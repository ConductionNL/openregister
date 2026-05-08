---
status: reference
---
# Unit Test Coverage — Phase 2 Inventory

## Purpose
Captures the source-file inventory and per-category test status snapshot taken on 2026-03-16 during Phase 2 of the `unit-test-coverage` initiative, plus the concrete PHPUnit patterns adopted across openregister (Event DataProvider, Entity, Service mock, Controller mock). This spec is informational and supports the canonical `unit-test-coverage` spec in `openregister/openspec/specs/unit-test-coverage/spec.md`; the normative coverage rules live there.

## Requirements

### Requirement: Test additions in covered categories MUST follow the documented patterns
New unit tests for openregister source files in the categories listed above MUST follow the patterns recorded in this spec (Event DataProvider, Entity reflection, Service mock with `createMock`, Controller intersection-type mocks). Deviating patterns MUST be justified in the change proposal that introduces them.

#### Scenario: Adding a new event test
- **WHEN** a developer adds a unit test for a new `*Event` class
- **THEN** the test MUST follow the DataProvider pattern recorded in this spec (single shared test method dispatching by entity class and getter)
- **AND** the new event MUST be appended to the existing `singleEntityEventProvider` data set instead of duplicating the test method

#### Scenario: Adding a controller test
- **WHEN** a developer writes a new controller test
- **THEN** they MUST use PHPUnit 10 intersection-type mocks (`IRequest&MockObject`) as recorded here
- **AND** the test MUST resolve dependencies through the constructor with explicit mocks rather than container lookups
