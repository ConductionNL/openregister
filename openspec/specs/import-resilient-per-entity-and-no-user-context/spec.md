---
status: done
---

# import-resilient-per-entity-and-no-user-context Specification

## Purpose
Makes configuration import resilient by wrapping each register, object, and seed-data entity in its own try/catch so a single failure is logged, counted, and skipped instead of aborting the whole import. Returns a `skipped` map of per-entity-kind counters for callers and tests to assert against. When no user session exists (the `occ`/installer/cron path), resolves a fallback admin acting user so folder and object operations succeed, without overriding a real session user when one is present.
## Requirements
### Requirement: Per-register import resilience
The register import loop in `importFromJson()` SHALL wrap each register in its own try/catch. When `importRegister()` throws for one register, the handler SHALL log a WARNING carrying the register slug and the failure reason, increment a skipped-register counter in the returned result, and CONTINUE with the remaining registers, mappings, objects and seed data. A single failing register SHALL NOT abort the import of sibling registers or of any later import phase.

#### Scenario: One bad register does not abort sibling registers or schemas
- **GIVEN** a configuration whose `components.registers` contains one register that fails to persist and one that is valid
- **WHEN** `importFromJson()` runs
- **THEN** the valid register SHALL be created and present in `result['registers']`
- **AND** the failing register SHALL be skipped with a logged WARNING naming its slug and reason
- **AND** `result['skipped']['registers']` SHALL be incremented by one
- **AND** all schemas in the same configuration SHALL still be created

### Requirement: Per-object import resilience for the main object loop
The main object loop in `importFromJson()` (`components.objects`) SHALL wrap each object's resolve/search/save sequence in its own try/catch. When an object fails validation or persistence (e.g. a missing required property such as `name`), the handler SHALL log a WARNING carrying the object slug and reason, increment a skipped-object counter, and CONTINUE with the remaining objects. A single failing object SHALL NOT abort sibling object imports nor the overall app import.

#### Scenario: A name-missing object is skipped, not fatal
- **GIVEN** a configuration whose `components.objects` includes one object missing a required property and one valid object
- **WHEN** `importFromJson()` runs
- **THEN** the valid object SHALL be saved and present in `result['objects']`
- **AND** the failing object SHALL be skipped with a logged WARNING (not re-thrown)
- **AND** `result['skipped']['objects']` SHALL be incremented by one
- **AND** the import SHALL complete and return a result rather than throwing

### Requirement: Seed-data import resilience
The `importSeedData()` invocation in `importFromJson()` SHALL be wrapped so a throw inside seed-data import never aborts the already-completed register/schema/object import. Inside `importSeedData()`, the per-schema preamble (target-register/schema resolution) SHALL be wrapped per `$schemaSlug` so one unresolvable or invalid schema slug skips only that schema's seed objects with a logged WARNING and continues. The existing per-object try/catch inside the seed loop SHALL be preserved.

#### Scenario: A bad seed schema slug skips only that schema's objects
- **GIVEN** seed data whose `x-openregister.seedData.objects` references one schema slug that cannot be resolved and one that can
- **WHEN** `importSeedData()` runs
- **THEN** seed objects for the resolvable schema SHALL still be imported
- **AND** the unresolvable schema's seed objects SHALL be skipped with a logged WARNING
- **AND** the overall import SHALL not throw

### Requirement: No-user-context acting-user fallback
When `IUserSession::getUser()` is null at import time (the `occ`/installer/cron path), the handler SHALL resolve a fallback system acting user — the first member of the `admin` group via `IGroupManager`, otherwise the first enabled user via `IUserManager` — and forward it as the explicit `currentUser` argument to `saveObject()` so folder/object operations that require an acting user succeed. When a real user session IS present, the handler SHALL NOT change behaviour (it MUST NOT override the session user). When no fallback user is resolvable, the handler SHALL log a WARNING and skip only the user-dependent operation, never the whole import.

#### Scenario: Import under no user session still creates registers and schemas
- **GIVEN** an import invoked with no logged-in user (e.g. `occ upgrade` repair step)
- **WHEN** `importFromJson()` runs against a configuration with registers, schemas and seed objects
- **THEN** all registers and schemas SHALL be created
- **AND** object/folder operations SHALL resolve a fallback admin acting user instead of failing default-deny
- **AND** if no admin is resolvable, only the folder-dependent operation SHALL be skipped with a WARNING while registers and schemas are still created

#### Scenario: Real user session is not overridden
- **GIVEN** an import invoked within an authenticated HTTP request with a real session user
- **WHEN** the handler resolves the acting user for `saveObject()`
- **THEN** the session user SHALL be used and the admin-fallback resolution SHALL NOT run

### Requirement: Skipped-entity observability
The result returned by `importFromJson()` (and surfaced through `importFromApp()`) SHALL include a `skipped` map with integer counters per entity kind (`registers`, `objects`, `mappings`, `seedObjects`) so callers, migrations and tests can assert how many entities were skipped. Counters SHALL default to zero and only increment when an entity is skipped due to a caught failure.

#### Scenario: Result reports skip counters
- **GIVEN** a configuration that produces at least one skipped register and one skipped object
- **WHEN** `importFromJson()` returns
- **THEN** `result['skipped']['registers']` and `result['skipped']['objects']` SHALL each be at least one
- **AND** entity kinds with no skips SHALL report zero

