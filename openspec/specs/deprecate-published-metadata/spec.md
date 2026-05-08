---
title: Deprecate Published/Depublished Object Metadata
status: implemented
type: refactoring
priority: high
---

# Deprecate Published/Depublished Object Metadata

## Purpose

Remove the dedicated `published`/`depublished` object metadata system from OpenRegister. The RBAC `$now` dynamic variable replaces this functionality, allowing publication control via authorization rules rather than dedicated metadata columns. This eliminates a parallel publication-state mechanism that overlapped with — and frequently conflicted with — the existing RBAC time-based access controls.

## Requirements

### Requirement: Remove Object Published Metadata Columns
The magic tables (`oc_or_*`) MUST NOT contain `_published` or `_depublished` columns, and the legacy `openregister_objects` table MUST NOT contain `published` or `depublished` columns. A database migration MUST handle column removal idempotently so re-running the migration on an already-migrated database is a no-op.

#### Scenario: Object CRUD Without Published Metadata
- **GIVEN** the deprecation migration has run
- **WHEN** a new object is created or updated
- **THEN** no `_published` or `_depublished` columns MUST be written
- **AND** the object MUST be saved successfully

### Requirement: Remove Published Metadata from Code
`MagicMapper` MUST NOT define or reference `_published`/`_depublished` columns, `SaveObject` MUST NOT process `objectPublishedField`, `objectDepublishedField`, or `autoPublish` schema configuration, search and facet handlers MUST NOT include published/depublished in metadata field lists, and the Solr index service MUST NOT accept or filter by the `$published` parameter.

#### Scenario: SaveObject ignores deprecated configuration keys
- **GIVEN** a schema configuration containing `objectPublishedField` and `autoPublish`
- **WHEN** an object is saved through `SaveObject`
- **THEN** the deprecated keys MUST be ignored
- **AND** no `_published`/`_depublished` columns MUST be referenced in the resulting SQL

### Requirement: RBAC $now Replacement
`ConditionMatcher::resolveDynamicValue()` MUST resolve `$now` to an ISO 8601 datetime, and `MagicRbacHandler::resolveDynamicValue()` MUST resolve `$now` to a SQL datetime format. Both resolvers MUST support `$now` inside operator expressions such as `{"$lte": "$now"}` and `{"$gte": "$now"}` so authorization rules can express time-based publication windows.

#### Scenario: RBAC Publication Control
- **GIVEN** a schema with authorization rule `{"read": [{"group": "public", "match": {"publicatieDatum": {"$lte": "$now"}}}]}`
- **WHEN** a public user queries objects
- **THEN** only objects with `publicatieDatum` in the past MUST be returned

### Requirement: Backward Compatibility
Schema configuration containing the deprecated keys (`objectPublishedField`, `objectDepublishedField`, `autoPublish`) MUST be ignored without raising an error, and a deprecation warning MUST be logged when these keys are encountered. The `published`/`depublished` fields on the Register and Schema entities are out of scope (used for multi-tenancy bypass), and Nextcloud file publish/depublish operations are out of scope (handled by Nextcloud share management).

#### Scenario: Deprecated Config Keys Ignored
- **GIVEN** a schema with `objectPublishedField` in its configuration
- **WHEN** an object is saved
- **THEN** the config key MUST be ignored
- **AND** a deprecation warning MUST be logged

### Requirement: Migration Guide
The deprecation MUST ship with documentation that explains how to migrate from `objectPublishedField` to RBAC authorization rules using `$now`, including a working example for the most common publication-window pattern.

#### Scenario: Operator follows the migration guide
- **WHEN** an operator with a schema using `objectPublishedField` consults the migration documentation
- **THEN** the documentation MUST provide a step-by-step replacement using a `$now`-based RBAC rule
- **AND** the example MUST be runnable against the current OpenRegister codebase
