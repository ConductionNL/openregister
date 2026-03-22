---
status: draft
---
# Deprecate Published/Depublished Metadata

## Purpose

Replace the dedicated `published`/`depublished` object metadata system in OpenRegister with RBAC conditional rules using the `$now` dynamic variable. The legacy system adds two datetime columns (`_published`, `_depublished`) to every magic table, requires specialized hydration logic in `SaveObject`, pollutes search and facet handlers, and conflates visibility control (an authorization concern) with publication lifecycle timestamps (a data concern). The RBAC `$now` mechanism, already implemented in both `ConditionMatcher` and `MagicRbacHandler`, provides a more flexible, composable, and architecturally sound replacement that aligns with the row-level security direction outlined in the `row-field-level-security` and `rbac-zaaktype` specs.

**Scope note**: This spec covers object-level published/depublished metadata only. Register/Schema `published`/`depublished` fields (multi-tenancy bypass via `MultiTenancyTrait`) and File publish/depublish (`FilePublishingHandler` / Nextcloud share management) are explicitly out of scope. The `autoPublish` key in `FilePropertyHandler` (which controls file sharing, not object metadata) is also out of scope.

## ADDED Requirements

### Requirement: Dynamic `$now` Variable in RBAC Conditions

The RBAC system SHALL support a `$now` dynamic variable that resolves to the current datetime, enabling time-based access control rules that replace the legacy published/depublished mechanism. `ConditionMatcher::resolveDynamicValue()` MUST resolve `$now` to ISO 8601 format (`(new DateTime())->format('c')`) for in-memory evaluation. `MagicRbacHandler::resolveDynamicValue()` MUST resolve `$now` to SQL datetime format (`(new DateTime())->format('Y-m-d H:i:s')`) for query-level filtering. Both implementations MUST resolve `$now` inside nested operator expressions such as `{"$lte": "$now"}` and `{"$gte": "$now"}`.

#### Scenario: Public read access for objects with past publication date
- **GIVEN** a schema with authorization rule `{"read": [{"group": "public", "match": {"publicatieDatum": {"$lte": "$now"}}}]}`
- **AND** an object with `publicatieDatum: "2024-01-01T00:00:00+00:00"`
- **WHEN** an unauthenticated user requests the object
- **THEN** the request MUST succeed because `publicatieDatum` is before `$now`

#### Scenario: Public read denied for objects with future publication date
- **GIVEN** the same schema authorization rule as above
- **AND** an object with `publicatieDatum: "2099-12-31T23:59:59+00:00"`
- **WHEN** an unauthenticated user requests the object
- **THEN** the request MUST return HTTP 403 or omit the object from list results

#### Scenario: Publication window with start and end dates
- **GIVEN** a schema with authorization rule `{"read": [{"group": "public", "match": {"publicatieDatum": {"$lte": "$now"}, "einddatum": {"$gte": "$now"}}}]}`
- **AND** an object with `publicatieDatum: "2024-01-01"` and `einddatum: "2099-12-31"`
- **WHEN** an unauthenticated user requests the object
- **THEN** the request MUST succeed (object is within the publication window)

#### Scenario: Expired publication window
- **GIVEN** the same schema authorization rule as above
- **AND** an object with `publicatieDatum: "2024-01-01"` and `einddatum: "2024-06-30"`
- **WHEN** an unauthenticated user requests the object
- **THEN** the request MUST be denied (object's publication window has passed)

#### Scenario: Admin user bypasses `$now` RBAC rules
- **GIVEN** any schema with `$now`-based RBAC rules
- **AND** an object with a future `publicatieDatum`
- **WHEN** an admin user requests the object
- **THEN** the admin MUST have access regardless of publication date conditions

---

### Requirement: Remove `published`/`depublished` from ObjectEntity

`ObjectEntity` MUST NOT contain `published` or `depublished` properties, getters, setters, or JSON serialization keys. Object API responses MUST NOT include `published` or `depublished` in the `@self` metadata block. This ensures the data model no longer carries legacy publication state as first-class metadata.

#### Scenario: Object JSON response excludes published metadata
- **GIVEN** an object retrieved via `GET /api/objects/{register}/{schema}/{id}`
- **WHEN** the response JSON is inspected
- **THEN** the `@self` block MUST NOT contain `published` or `depublished` keys

#### Scenario: Object creation ignores published input
- **GIVEN** a POST request to create an object with `{"published": "2024-01-01", "depublished": null, "name": "Test"}`
- **WHEN** the object is created
- **THEN** the `published` and `depublished` input values MUST be silently ignored
- **AND** the created object MUST NOT have `published` or `depublished` in its `@self` metadata

#### Scenario: Object update ignores published input
- **GIVEN** an existing object
- **AND** a PUT request including `{"published": "2024-06-01"}`
- **WHEN** the update is processed
- **THEN** the `published` value MUST be silently ignored
- **AND** other fields in the request MUST be updated normally

---

### Requirement: Remove Publish/Depublish API Endpoints

All dedicated object publish and depublish API routes MUST be removed from `routes.php`. Requests to former publish/depublish endpoints MUST return HTTP 404 Not Found. This includes both single-object and bulk operations.

#### Scenario: Single object publish endpoint returns 404
- **GIVEN** a valid object ID
- **WHEN** a client sends `POST /api/objects/{register}/{schema}/{id}/publish`
- **THEN** the server MUST return HTTP 404 Not Found

#### Scenario: Single object depublish endpoint returns 404
- **GIVEN** a valid object ID
- **WHEN** a client sends `POST /api/objects/{register}/{schema}/{id}/depublish`
- **THEN** the server MUST return HTTP 404 Not Found

#### Scenario: Bulk publish endpoint returns 404
- **GIVEN** a valid register and schema
- **WHEN** a client sends `POST /api/bulk/{register}/{schema}/publish`
- **THEN** the server MUST return HTTP 404 Not Found

---

### Requirement: Remove Published Columns from MagicMapper

`MagicMapper::getBaseMetadataColumns()` MUST NOT include `_published` or `_depublished` column definitions. All metadata column lists in `MagicMapper` (table creation in `ensureTableForRegisterSchema()`, table update paths, `buildInsertData()`, `buildObjectFromRow()`) MUST NOT reference `published` or `depublished`. Magic table index definitions (`$idxMetaFields`) MUST NOT include `_published`. This ensures newly created magic tables are clean and existing code paths do not attempt to read or write these columns.

#### Scenario: New magic table does not include published columns
- **GIVEN** a new register-schema combination is created
- **WHEN** `MagicMapper::ensureTableForRegisterSchema()` creates the magic table
- **THEN** the table MUST NOT have `_published` or `_depublished` columns
- **AND** the table MUST NOT have an `idx__published` index

#### Scenario: Object insert does not write published metadata
- **GIVEN** an existing magic table (with or without legacy `_published` columns)
- **WHEN** `MagicMapper::buildInsertData()` constructs insert data for an object
- **THEN** the insert data MUST NOT include `published` or `depublished` fields

#### Scenario: Object row extraction does not read published metadata
- **GIVEN** a row retrieved from a magic table
- **WHEN** `MagicMapper::buildObjectFromRow()` constructs an object from the row
- **THEN** the object MUST NOT have `published` or `depublished` set from metadata columns

#### Scenario: Magic table update path does not add published columns
- **GIVEN** an existing magic table that lacks `_published` and `_depublished` columns
- **WHEN** `ensureTableForRegisterSchema()` runs its table update path
- **THEN** no `_published` or `_depublished` columns MUST be added

---

### Requirement: Database Migration Drops Legacy Columns

A database migration (`Version1Date20260313130000`) MUST drop `_published` and `_depublished` columns from all existing magic tables (tables matching the `or_*` naming pattern). The migration MUST also drop `published` and `depublished` columns from the legacy `openregister_objects` table. The migration MUST drop all published-related indexes. The migration MUST be idempotent -- it SHALL handle tables where the columns do not exist without error.

#### Scenario: Migration drops columns from magic tables with published columns
- **GIVEN** a database with magic tables `oc_or_1_2` and `oc_or_3_4` that have `_published` and `_depublished` columns
- **WHEN** the migration runs
- **THEN** both columns MUST be dropped from both tables
- **AND** the `idx__published` index MUST be dropped if present

#### Scenario: Migration handles magic tables without published columns
- **GIVEN** a database with magic table `oc_or_5_6` that does NOT have `_published` or `_depublished` columns
- **WHEN** the migration runs
- **THEN** no error MUST occur
- **AND** the migration MUST skip that table gracefully

#### Scenario: Migration drops columns from legacy objects table
- **GIVEN** the `openregister_objects` table has `published` and `depublished` columns
- **WHEN** the migration runs
- **THEN** both columns and all published-related indexes (e.g., `objects_published_idx`, `objects_register_schema_published_idx`) MUST be dropped

#### Scenario: Migration is idempotent on repeated execution
- **GIVEN** the migration has already been run once
- **WHEN** it is run again (e.g., via `occ migrations:execute`)
- **THEN** no error MUST occur and the database state MUST remain consistent

---

### Requirement: Remove Published from Search and Facet Handlers

`MariaDbSearchHandler` MUST NOT list `published` or `depublished` as searchable metadata fields or in its `DATE_FIELDS` constant. `MetaDataFacetHandler` MUST NOT define `published` or `depublished` in its facet metadata definitions or column mappings. `MagicFacetHandler` MUST NOT include `published` in date field handling. `SearchQueryHandler` MUST NOT pass `published` as a parameter or list it as an `@self` metadata field. These removals ensure that search queries, facet aggregations, and ordering no longer reference the deprecated columns.

#### Scenario: Search query without published filter
- **GIVEN** a schema with objects indexed for search
- **WHEN** a user performs a search via `GET /api/objects/{register}/{schema}?_search=test`
- **THEN** the query MUST NOT include any WHERE clause on `_published` or `_depublished`
- **AND** results MUST be returned based on RBAC rules, not published state

#### Scenario: Facet response excludes published facets
- **GIVEN** a schema with faceting enabled
- **WHEN** a user requests facets via `GET /api/objects/{register}/{schema}?_facets=metadata`
- **THEN** the response MUST NOT include `published` or `depublished` as available facet fields

#### Scenario: Ordering by published returns error or is ignored
- **GIVEN** a client sends `GET /api/objects/{register}/{schema}?_order[@self.published]=asc`
- **WHEN** the query is processed
- **THEN** the `@self.published` ordering MUST be silently ignored or return an error indicating the field does not exist

#### Scenario: Date range filter on published is not available
- **GIVEN** a client sends `GET /api/objects/{register}/{schema}?_filter[@self.published][$gte]=2024-01-01`
- **WHEN** the request is processed
- **THEN** the filter on `@self.published` MUST be silently ignored or return an error

---

### Requirement: Remove Published from Solr Index Service

`SearchBackendInterface::searchObjects()` MUST NOT have a `$published` parameter in its method signature. `IndexService::searchObjects()` MUST NOT accept or pass a `$published` parameter. `ObjectHandler::searchObjects()` and `ObjectHandler::buildSolrQuery()` MUST NOT apply a `published:true` filter. This ensures that the Solr search backend does not filter results based on the removed published metadata.

#### Scenario: Solr query does not filter by published status
- **GIVEN** a schema with Solr indexing enabled
- **WHEN** a search query is executed through `IndexService`
- **THEN** the Solr query MUST NOT include a `published:true` filter clause

#### Scenario: Interface implementations do not break
- **GIVEN** a class that implements `SearchBackendInterface`
- **WHEN** the interface method signature for `searchObjects()` is updated to remove `$published`
- **THEN** all implementations MUST be updated accordingly and pass static analysis

#### Scenario: Solr results respect RBAC instead of published state
- **GIVEN** a schema with RBAC rules using `$now` for visibility
- **WHEN** an unauthenticated user searches via Solr
- **THEN** results MUST be filtered by RBAC authorization rules, not by a `published` boolean

---

### Requirement: Remove Schema Configuration Keys for Published Hydration

`SaveObject::hydrateObjectMetadata()` MUST NOT process `objectPublishedField`, `objectDepublishedField`, or `autoPublish` schema configuration keys for object metadata hydration. `Schema.php` MUST NOT include `autoPublish` in `boolFields` for object-level metadata purposes. When these deprecated configuration keys are encountered in a schema's configuration, the system SHALL log a deprecation warning at `warning` level via `LoggerInterface` with a message guiding administrators to migrate to RBAC rules with `$now`.

#### Scenario: Schema with objectPublishedField is silently ignored
- **GIVEN** a schema with configuration `{"objectPublishedField": "publicatieDatum"}`
- **WHEN** an object is saved
- **THEN** the `publicatieDatum` value MUST NOT be copied to `_published` metadata
- **AND** a deprecation warning MUST be logged

#### Scenario: Schema with autoPublish is silently ignored
- **GIVEN** a schema with configuration `{"autoPublish": true}`
- **WHEN** an object is saved
- **THEN** the object MUST NOT be auto-published
- **AND** a deprecation warning MUST be logged suggesting RBAC migration

#### Scenario: Schema without deprecated keys works normally
- **GIVEN** a schema that does not use `objectPublishedField`, `objectDepublishedField`, or `autoPublish`
- **WHEN** an object is saved
- **THEN** no deprecation warning MUST be logged
- **AND** object saving MUST proceed normally

#### Scenario: File-level autoPublish remains functional
- **GIVEN** a schema with `autoPublish` in file property configuration (handled by `FilePropertyHandler`)
- **WHEN** an object with a file property is saved
- **THEN** the file-level `autoPublish` MUST continue to function (it controls Nextcloud file sharing, not object metadata)
- **AND** no deprecation warning MUST be logged for the file-level key

---

### Requirement: Backward-Compatible API Response Handling

Clients that previously relied on `@self.published` or `@self.depublished` fields in API responses MUST NOT receive errors when those fields are absent. The API MUST maintain structural compatibility in the `@self` metadata block by simply omitting the removed fields rather than introducing breaking schema changes. Clients sending `published` or `depublished` in request bodies MUST have those values silently ignored.

#### Scenario: Legacy client reading object list handles missing published field
- **GIVEN** a client that previously parsed `@self.published` from object list responses
- **WHEN** the client receives a response from the updated API
- **THEN** the `@self` block MUST NOT contain `published` or `depublished`
- **AND** no HTTP error MUST occur (the response structure is valid, just with fewer fields)

#### Scenario: Legacy client sending published in create request
- **GIVEN** a client that includes `{"@self": {"published": "2024-01-01"}}` in a create request body
- **WHEN** the server processes the request
- **THEN** the `@self.published` value MUST be silently ignored
- **AND** the object MUST be created successfully with all other valid fields

#### Scenario: Legacy SDK ordering by published gracefully degrades
- **GIVEN** a client SDK that sends `_order[@self.published]=desc` as a default ordering parameter
- **WHEN** the server processes the request
- **THEN** the ordering parameter MUST be silently ignored
- **AND** objects MUST be returned with default ordering (e.g., by `@self.updated` descending)

---

### Requirement: Cross-App Cleanup in OpenCatalogi

OpenCatalogi MUST remove all references to the object-level published/depublished metadata system. Specifically: `MassPublishObjects.vue` and `MassDepublishObjects.vue` modal components MUST be deleted. Store actions `publishObject()` and `depublishObject()` MUST be removed. `ObjectCreatedEventListener` and `ObjectUpdatedEventListener` MUST NOT read `@self.published` or `@self.depublished`. `PublicationsController` MUST NOT list `published` or `depublished` as universal order fields. WOO publication schemas MUST be migrated to use RBAC authorization rules with `$now` instead of `objectPublishedField`/`objectDepublishedField`.

#### Scenario: Mass publish modal is no longer accessible
- **GIVEN** a user navigating the OpenCatalogi object list
- **WHEN** the user looks for a bulk publish action
- **THEN** no mass publish or mass depublish option MUST be available in the UI

#### Scenario: WOO publication uses RBAC for visibility
- **GIVEN** a WOO publication schema migrated to RBAC with rule `{"read": [{"group": "public", "match": {"publicatieDatum": {"$lte": "$now"}}}]}`
- **AND** an object with `publicatieDatum` in the past
- **WHEN** an unauthenticated user accesses the publication
- **THEN** the publication MUST be visible

#### Scenario: WOO publication with future date is hidden
- **GIVEN** the same RBAC-migrated WOO schema
- **AND** an object with `publicatieDatum` in the future
- **WHEN** an unauthenticated user attempts to access it
- **THEN** the publication MUST NOT be visible

#### Scenario: Event listeners do not read published metadata
- **GIVEN** an object is created or updated in an OpenCatalogi-managed schema
- **WHEN** `ObjectCreatedEventListener` or `ObjectUpdatedEventListener` fires
- **THEN** the listener MUST NOT attempt to read `@self.published` or `@self.depublished`
- **AND** no PHP error or warning MUST occur from missing published keys

---

### Requirement: Cross-App Cleanup in Softwarecatalogus

Softwarecatalogus MUST remove all object-level published/depublished UI components. `MassPublishObjects.vue` and `MassDepublishObjects.vue` MUST be deleted. `PublishedIcon.vue` MUST be deleted or repurposed for RBAC-based visibility indication.

#### Scenario: Softwarecatalogus object list has no publish actions
- **GIVEN** a user viewing the Softwarecatalogus object list
- **WHEN** the user inspects available bulk actions
- **THEN** no publish or depublish action MUST be present

#### Scenario: Date-based faceting still works
- **GIVEN** the Softwarecatalogus uses date-based faceting on data fields (not `@self.published`)
- **WHEN** a user applies a date range filter
- **THEN** faceting MUST work correctly using the data field values directly

#### Scenario: Published icon is removed or repurposed
- **GIVEN** a list view that previously showed a `PublishedIcon` component
- **WHEN** the view renders
- **THEN** no publish/depublish icon based on `@self.published` MUST appear

---

### Requirement: Migration Documentation and Administrator Guidance

The system SHOULD provide documentation or log messages that guide administrators in migrating schemas from `objectPublishedField`/`objectDepublishedField` configuration to RBAC authorization rules with `$now`. The deprecation warning log messages MUST include actionable guidance (the target RBAC rule format).

#### Scenario: Deprecation log includes migration example
- **GIVEN** a schema with deprecated `objectPublishedField: "publicatieDatum"` configuration
- **WHEN** the deprecation warning is logged
- **THEN** the log message MUST include an example of the equivalent RBAC rule, such as: `Migrate to authorization: {"read": [{"group": "public", "match": {"publicatieDatum": {"$lte": "$now"}}}]}`

#### Scenario: Repair step scans for deprecated configuration
- **GIVEN** an administrator runs `occ maintenance:repair`
- **WHEN** the repair step encounters schemas with `objectPublishedField`, `objectDepublishedField`, or `autoPublish`
- **THEN** the repair step SHOULD report which schemas need migration
- **AND** optionally offer auto-migration to equivalent RBAC rules

#### Scenario: Documentation covers common migration patterns
- **GIVEN** an administrator consulting the migration documentation
- **WHEN** they look up how to convert published-based visibility
- **THEN** the documentation SHOULD show the pattern: old (`objectPublishedField` + `autoPublish`) mapped to new (RBAC `{"$lte": "$now"}` rule)

---

### Requirement: Audit Trail for Deprecation Events

All encounters of deprecated schema configuration keys MUST be logged at `warning` level via `\Psr\Log\LoggerInterface`. The log entries MUST include the schema ID, the deprecated key name, and a migration suggestion. This provides an audit trail for tracking which schemas still use the legacy system and supports compliance reporting.

#### Scenario: Each deprecated key is logged individually
- **GIVEN** a schema with both `objectPublishedField` and `autoPublish` in its configuration
- **WHEN** the schema is processed during object save
- **THEN** two separate warning log entries MUST be created, one for each deprecated key

#### Scenario: Log entries include schema identification
- **GIVEN** schema ID `42` with title "WOO Publicaties" has deprecated config
- **WHEN** the deprecation warning fires
- **THEN** the log MUST include `['schema' => 42, 'key' => 'objectPublishedField']` or equivalent context

#### Scenario: No log noise for clean schemas
- **GIVEN** a schema without any deprecated configuration keys
- **WHEN** objects are saved against this schema
- **THEN** no deprecation warning MUST be logged

---

### Requirement: MultiTenancyTrait Documentation Cleanup

`MultiTenancyTrait` documentation comments that reference "Published entity bypass" for object-level metadata MUST be updated to remove those references. The actual published bypass logic for Register and Schema entities (which use their own `published`/`depublished` columns for multi-tenancy purposes) MUST remain untouched, as that is out of scope for this spec.

#### Scenario: Object-level published bypass references removed from comments
- **GIVEN** `MultiTenancyTrait` documentation referencing object published bypass
- **WHEN** the code is reviewed after this spec is applied
- **THEN** no comments about object-level `published` bypass MUST remain

#### Scenario: Register/Schema published bypass remains functional
- **GIVEN** a Register entity with `published` set to a past date
- **WHEN** a user accesses the register via multi-tenancy bypass
- **THEN** the bypass MUST work as before (this functionality is preserved)

#### Scenario: Schema published bypass remains functional
- **GIVEN** a Schema entity with `published` set and `depublished` set to null
- **WHEN** the schema is accessed via the multi-tenancy bypass path
- **THEN** the schema MUST be visible as before

## Cross-References

- **`row-field-level-security` spec**: The `$now` dynamic variable and RBAC conditional matching system are foundational to the row-level security model. This deprecation ensures published/depublished is handled through the same RBAC pipeline rather than as a special case.
- **`rbac-zaaktype` spec**: Per-schema authorization policies are the mechanism that replaces the published/depublished system. Schemas define authorization rules that can include `$now`-based date conditions.
- **Competitor analysis**: NocoDB and Baserow handle record visibility through role-based permissions and view filters rather than dedicated published/depublished metadata, validating this architectural direction.

## Implementation Notes

- **Files primarily affected in OpenRegister**: `MagicMapper.php`, `MagicRbacHandler.php`, `MagicFacetHandler.php`, `MetaDataFacetHandler.php`, `MariaDbSearchHandler.php`, `SearchQueryHandler.php`, `SaveObject.php`, `IndexService.php`, `ObjectHandler.php`, `SearchBackendInterface.php`, `Schema.php`, `MultiTenancyTrait.php`, `ObjectsController.php`, `BulkController.php`
- **Migration**: `Version1Date20260313130000` handles column drops idempotently
- **RBAC implementation**: `ConditionMatcher::resolveDynamicValue()` (ISO 8601) and `MagicRbacHandler::resolveDynamicValue()` (SQL datetime) already support `$now`
- **File-level autoPublish**: `FilePropertyHandler` uses `autoPublish` for Nextcloud file share management -- this is deliberately preserved and is NOT part of this deprecation
