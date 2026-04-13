# Design: unit-test-coverage

## Overview

Comprehensive unit test coverage for OpenRegister's PHP codebase. Tests live under tests/Unit/ mirroring lib/ structure. Each test extends PHPUnit\Framework\TestCase with phpunit-unit.xml and bootstrap-unit.php.

## Implementation

317 test files cover events, exceptions, formats, entities, mappers, handlers, background jobs, commands, cron jobs, listeners, controllers, and services. Coverage targets 75% line and method coverage.

## Testing

- All tests run in isolated PHPUnit environment (ADR-009)
- Documentation maintained (ADR-010)
- Dutch and English translations supported (ADR-005)
