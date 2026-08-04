---
status: done
---

# cleanup-linked-entity-type-map Specification

## Purpose
Removes the hardcoded `LinkedEntityService::TYPE_COLUMN_MAP` and `Schema::VALID_LINKED_TYPES` constants so integration discovery and linked-type validation are driven entirely by `IntegrationRegistry`. Existing schemas continue to validate through `IntegrationRegistry::listIds()`, and an organisation-wide grep sweep migrates any external callers before the constants are deleted.
## Requirements
### Requirement: Constants Removed

`LinkedEntityService::TYPE_COLUMN_MAP` and `Schema::VALID_LINKED_TYPES` SHALL be absent from the codebase after this change.

#### Scenario: Grep confirms absence

- **WHEN** the codebase is grep'd for `TYPE_COLUMN_MAP` or `VALID_LINKED_TYPES`
- **THEN** zero matches MUST exist in OR core or `@conduction/nextcloud-vue`

### Requirement: Registry-Driven Behaviour Unchanged

All integration discovery and schema validation SHALL continue to function via `IntegrationRegistry`.

#### Scenario: Existing schemas continue to validate

- **GIVEN** a schema with `configuration.linkedTypes: ["files", "notes"]`
- **WHEN** the schema is saved after this change
- **THEN** validation MUST succeed via `IntegrationRegistry::listIds()`

### Requirement: Pre-Removal Grep Sweep

A grep sweep of the ConductionNL organisation SHALL be run before the removal commit, and any remaining references outside OR core MUST be migrated before removal.

#### Scenario: External callers migrated before removal

- **GIVEN** the W25 sweep is preparing to remove `TYPE_COLUMN_MAP`
- **WHEN** `git grep -lE 'TYPE_COLUMN_MAP|VALID_LINKED_TYPES'` is run across the Conduction org repositories
- **THEN** every match outside OR core MUST be migrated to the registry-driven equivalent before the removal commit lands

