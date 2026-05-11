---
status: proposed
---

# Cleanup: Remove LinkedEntityService::TYPE_COLUMN_MAP

## Purpose

Complete the migration away from the hardcoded type-column constant to the `IntegrationRegistry`. Pure removal — no behaviour change.

**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

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
