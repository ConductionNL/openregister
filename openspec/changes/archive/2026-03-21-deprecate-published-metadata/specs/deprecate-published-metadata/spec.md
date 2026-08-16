---
title: Deprecate Published/Depublished Object Metadata
status: implemented
type: refactoring
priority: high
---

# Deprecate Published/Depublished Object Metadata

## Summary

Remove the dedicated `published`/`depublished` object metadata system from OpenRegister. The RBAC `$now` dynamic variable replaces this functionality, allowing publication control via authorization rules rather than dedicated metadata columns.

## Requirements

### REQ-DPM-001: Remove Object Published Metadata Columns
- Magic tables (`oc_or_*`) MUST NOT contain `_published` or `_depublished` columns
- The legacy `openregister_objects` table MUST NOT contain `published` or `depublished` columns
- A database migration MUST handle column removal idempotently

### REQ-DPM-002: Remove Published Metadata from Code
- `MagicMapper` MUST NOT define or reference `_published`/`_depublished` columns
- `SaveObject` MUST NOT process `objectPublishedField`, `objectDepublishedField`, or `autoPublish` schema configuration
- Search and facet handlers MUST NOT include published/depublished in metadata field lists
- Index service (Solr) MUST NOT accept or filter by `$published` parameter

### REQ-DPM-003: RBAC $now Replacement
- `ConditionMatcher::resolveDynamicValue()` MUST resolve `$now` to ISO 8601 datetime
- `MagicRbacHandler::resolveDynamicValue()` MUST resolve `$now` to SQL datetime format
- Both MUST support `$now` inside operator expressions: `{"$lte": "$now"}`, `{"$gte": "$now"}`

### REQ-DPM-004: Backward Compatibility
- Schema configuration with deprecated keys MUST be ignored (no error)
- Deprecation warning MUST be logged when these keys are encountered
- Register/Schema entity `published`/`depublished` fields are OUT OF SCOPE (multi-tenancy bypass)
- File publish/depublish operations are OUT OF SCOPE (Nextcloud share management)

### REQ-DPM-005: Migration Guide
- Documentation MUST explain how to migrate from `objectPublishedField` to RBAC authorization rules with `$now`

## Scenarios

### SCENARIO-DPM-001: Object CRUD Without Published Metadata
- GIVEN the deprecation migration has run
- WHEN a new object is created or updated
- THEN no `_published` or `_depublished` columns are written
- AND the object is saved successfully

### SCENARIO-DPM-002: RBAC Publication Control
- GIVEN a schema with authorization rule `{"read": [{"group": "public", "match": {"publicatieDatum": {"$lte": "$now"}}}]}`
- WHEN a public user queries objects
- THEN only objects with `publicatieDatum` in the past are returned

### SCENARIO-DPM-003: Deprecated Config Keys Ignored
- GIVEN a schema with `objectPublishedField` in its configuration
- WHEN an object is saved
- THEN the config key is ignored
- AND a deprecation warning is logged
