---
title: Deprecate Published/Depublished Object Metadata
status: implemented
type: refactoring
priority: high
---

# Deprecate Published/Depublished Object Metadata

## Purpose

@e2e exclude backend migration/config deprecation — covered by PHPUnit

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
Schema configuration containing the deprecated keys (`objectPublishedField`, `objectDepublishedField`, `autoPublish`) MUST be ignored without raising an error, and a deprecation warning MUST be logged when these keys are encountered. Nextcloud file publish/depublish operations are out of scope (handled by Nextcloud share management). The `published`/`depublished` fields on the Register and Schema entities were previously out of scope because the multi-tenancy filter used them as an anonymous-access bypass; that bypass has since been removed — see REQ-5 below, which is now the normative statement for those fields.

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

### Requirement: REQ-5: The multi-tenancy filter MUST NOT bypass on published state
`MultiTenancyTrait::applyOrganisationFilter()` MUST decide visibility from the organisation column alone and MUST NOT read a `published` or `depublished` column on any entity, including `Register` and `Schema`. The former published/depublished bypass — which widened a query for anonymous callers whenever a Register or Schema was marked published — is removed; anonymous visibility is now governed exclusively by RBAC (`authorization.read` containing `public` on the register/schema entity).

The filter's remaining behaviour MUST be: it is skipped entirely when the caller passes `multiTenancyEnabled = false` or when the `openregister`/`multitenancy` app config declares `enabled: false`; an active organisation MUST match the caller's active organisation together with its parent organisations; an admin MUST see all entities only when `adminOverride` is enabled, and never in SaaS mode; and a caller with no active organisation MUST see `NULL`-organisation rows only when the caller explicitly passes `allowNullOrg`, otherwise the query MUST match nothing.

#### Scenario: No published column is consulted
- **GIVEN** any register, schema or object query that applies the organisation filter
- **WHEN** the resulting SQL is inspected
- **THEN** it MUST NOT reference a `published` or `depublished` column
- **AND** visibility MUST be decided by the organisation column and RBAC alone

#### Scenario: A published register is not anonymously visible by that fact alone
- **GIVEN** a register whose legacy `published` field is set but whose RBAC `authorization.read` does not contain `public`
- **WHEN** an anonymous caller lists registers
- **THEN** the register MUST NOT be returned

#### Scenario: No active organisation blocks results unless null-org is permitted
- **GIVEN** a caller with no active organisation
- **WHEN** the filter is applied without `allowNullOrg`
- **THEN** the query MUST match nothing
- **AND** with `allowNullOrg` it MUST match only rows whose organisation is `NULL`
