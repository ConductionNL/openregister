---
retrofit_extensions:
  - REQ-001
---

# Linked Entity Types — Retrofit Delta

Adds 1 REQ extending `linked-entity-types` with the install/post-migration repair step that surfaces schemas whose `configuration.linkedTypes` reference integration ids the registry can no longer resolve. The step is strictly informational — it never throws and never modifies data; its goal is operational visibility so admins can plan provider installation before the deprecated `VALID_LINKED_TYPES` fallback disappears.

## Requirements

### REQ-001: The system SHALL run a non-destructive repair step that warns when schema `linkedTypes` reference unregistered integrations

`OCA\OpenRegister\Repair\LogDanglingLinkedTypes` implements `OCP\Migration\IRepairStep` and is registered via `appinfo/info.xml`. Nextcloud invokes it on every `occ app:enable` / `occ maintenance:repair` / app upgrade. The step's `getName()` returns `"Log schemas with linkedTypes referencing unregistered integrations"`, which appears in the occ output and the admin Repair UI.

`run(IOutput $output)` is the entry point. It:
1. Writes `[OpenRegister] Scanning schemas for dangling linkedTypes...` via `$output->info()`.
2. Resolves the SchemaMapper lazily through the DI `ContainerInterface` (`$this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper')`). Hard-binding the mapper would create a circular dependency at app boot; lazy resolution avoids that.
3. If the mapper cannot be resolved (or `findAll()` is absent / returns non-array), `loadSchemas()` returns null. The step writes `Schema mapper unavailable — scan skipped (this is normal on first install).` and returns immediately. The throwable (if any) is logged at DEBUG via `LoggerInterface`.
4. Calls `IntegrationRegistry::listIds()` to get the set of currently-registered integration ids.
5. Calls `scan(schemas, registeredIds)` to walk every schema, pull its `linkedTypes` array via `extractLinkedTypes()`, and collect string entries not present in `registeredIds`. Each dangling row carries `slug`, `id`, `danglingType`.
6. Empty result → writes `All schemas linkedTypes are covered by registered integrations.` and returns.
7. Non-empty result → for each row writes both `LoggerInterface::warning(...)` AND `$output->warning(...)` with the message `Schema "<slug>" (id=<id>) declares linkedType "<type>" which is not registered. Add the matching IntegrationProvider before the deprecated VALID_LINKED_TYPES fallback is removed.`

`extractLinkedTypes(schema)` tolerates two accessor variants: it prefers `getLinkedTypes()` when present (must return an array), otherwise falls back to `getConfiguration()['linkedTypes']` when both the configuration array and the `linkedTypes` sub-key exist. Any accessor throwable returns `[]` for that schema (skip-and-continue). `safeStringAccessor()` is a generic helper that walks an ordered list of method names on the schema entity, returning the first non-empty string (or `int` cast to string) result; throwables are caught and the next accessor is tried; null is returned when none succeed.

#### Scenario: First-install run with no schemas yet

- **GIVEN** a fresh OpenRegister install where the database schema is being prepared and the SchemaMapper is not yet wired
- **WHEN** Nextcloud invokes `run()` as part of `occ app:enable`
- **THEN** `loadSchemas()` returns null
- **AND** the step writes `Schema mapper unavailable — scan skipped (this is normal on first install).` via `$output->info()`
- **AND** the step exits without throwing or writing any WARNING

#### Scenario: All linkedTypes are registered

- **GIVEN** the schemas in the register declare `linkedTypes: ["files", "mail", "contacts"]` and the `IntegrationRegistry` lists all three ids
- **WHEN** `run()` executes the scan
- **THEN** `scan()` produces an empty dangling list
- **AND** the step writes `All schemas linkedTypes are covered by registered integrations.` via `$output->info()`
- **AND** no warnings are emitted

#### Scenario: A linkedType references an unregistered integration

- **GIVEN** schema `meldingen` (id `42`) declares `linkedTypes: ["files", "xwiki-pages"]`
- **AND** `IntegrationRegistry::listIds()` returns `["files", "mail", "contacts"]` (no `xwiki-pages`)
- **WHEN** `run()` executes the scan
- **THEN** the dangling list contains one row: `{ slug: 'meldingen', id: '42', danglingType: 'xwiki-pages' }`
- **AND** both `LoggerInterface::warning()` and `$output->warning()` are called with `Schema "meldingen" (id=42) declares linkedType "xwiki-pages" which is not registered. Add the matching IntegrationProvider before the deprecated VALID_LINKED_TYPES fallback is removed.`
- **AND** the step exits normally — no exception is thrown

#### Scenario: A schema accessor throws

- **GIVEN** a malformed schema where `getLinkedTypes()` throws (e.g. JSON decode error in the configuration column)
- **WHEN** `extractLinkedTypes()` calls `$schema->getLinkedTypes()`
- **THEN** the throwable is swallowed and the loop tries `getConfiguration()` next
- **AND** if both throw, the method returns `[]` and the schema is silently skipped
- **AND** the scan continues with the next schema

#### Scenario: Schema has linkedTypes only in configuration map (no getLinkedTypes method)

- **GIVEN** a Schema entity whose accessors expose `getConfiguration()` returning `{ "linkedTypes": ["mail"] }` but no `getLinkedTypes()` method
- **WHEN** `extractLinkedTypes()` is called
- **THEN** the `getLinkedTypes` branch is skipped (method does not exist)
- **AND** the `getConfiguration` branch returns `["mail"]` from `$value['linkedTypes']`

#### Scenario: Non-string entries in linkedTypes are silently skipped

- **GIVEN** a schema whose `linkedTypes` contains `["mail", 42, null]`
- **WHEN** `scan()` iterates the values
- **THEN** the non-string entries fail the `is_string($type) === false` guard and are skipped
- **AND** only `"mail"` is evaluated against the registry

### Notes

- **Step is strictly informational.** It never throws and never modifies any data — even when the mapper is unavailable. Repair UI output is the only side-effect channel besides the application log.
- **Lazy mapper resolution is load-bearing.** The class doc-block explicitly calls out the circular-dep risk of binding SchemaMapper directly into the constructor. Future refactors must preserve the lazy `ContainerInterface::get()` pattern.
- **`safeStringAccessor()` is over-engineered for the two accessor families it serves** (`getSlug`/`getName`, `getId`/`getUuid`) — it does not currently encode a preferred order beyond the parameter list. Surfaced for awareness; not a bug.
- **Pre-existing `@spec` pointer.** The file already carries `@spec openspec/changes/pluggable-integration-registry/tasks.md#task-11`. That change does not exist on this branch. This retrofit retargets the methods to `linked-entity-types/REQ-001` while leaving the original pointer in place so a future merge of the integration-registry change re-links cleanly.
