# Unit Test Coverage

## Problem
Achieve comprehensive unit test code coverage for all PHP source files in OpenRegister's `lib/` directory (excluding `Migration/` and `AppInfo/Application.php`), targeting 75% line and method coverage as the enforced gate with a stretch goal of 100%. This spec defines the testing standards, mocking strategies, coverage enforcement mechanisms, and per-category test requirements that ensure every code path -- happy flows, error branches, edge cases, and boundary conditions -- is exercised by automated tests. Reliable test coverage is essential for Dutch government deployments where untested features lead to regressions, broken APIs, and failed tender compliance (ref: ADR-009 Mandatory Test Coverage).

## Proposed Solution
Implement Unit Test Coverage following the detailed specification. Key requirements include:
- Requirement: Coverage Gate Enforcement at 75% Line and Method Coverage
- Requirement: All Unit Tests SHALL Use PHPUnit\Framework\TestCase with Comprehensive Mocking
- Requirement: Test All Code Paths Including Error Branches and Edge Cases
- Requirement: Use Real Entity Instances, Never Mock Nextcloud Entities
- Requirement: Use Data Providers for Parameterized Scenarios

## Scope
This change covers all requirements defined in the unit-test-coverage specification.

## Success Criteria
- Coverage gate blocks regression
- Coverage gate allows improvement
- Coverage baseline update after improvement
- Coverage reports are generated in multiple formats
- Excluded directories do not count against coverage
