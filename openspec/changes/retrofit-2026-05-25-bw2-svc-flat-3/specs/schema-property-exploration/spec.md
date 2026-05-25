---
retrofit: true
---

# Schema Property Exploration

## Why

`SchemaService` carries two methods — `exploreSchemaProperties` and
`updateSchemaFromExploration` — that implement a schema-authoring aid: they
analyse the JSON payloads actually stored under a schema to surface
undeclared or under-specified properties, and apply confirmed suggestions
back onto the schema. This is a distinct concern from schema CRUD,
validation, or facet configuration (those are owned by their own
capabilities), and no existing capability spec captures the
data-driven discovery loop. This new capability anchors the observed
behaviour of that loop. Code already exists in production; this is an
annotation-only reverse-spec.

## ADDED Requirements

### Requirement: SchemaService MUST discover undeclared properties by analysing a schema's stored objects

`SchemaService::exploreSchemaProperties(int $schemaId)` MUST inspect every `ObjectEntity` stored under the given schema and return a property-discovery report describing the JSON payload shape actually present in the data, independent of the schema's declared `properties`. The method MUST resolve the schema via `SchemaMapper::find()` and MUST throw an `\Exception` carrying the schema id when the schema does not exist. It MUST load the objects via `ObjectEntityMapper::findBySchema($schemaId)` and, when no objects exist, MUST return a report whose `total_objects` is `0`, whose `discovered_properties` and `suggestions` are empty, whose `existing_properties` echoes the schema's declared properties, and whose `message` states that no objects were available for analysis.

For each object the method MUST analyse the `getObject()` payload after removing the `@self` metadata key, MUST count per-property usage across all objects (including objects where the value is `null` or the empty string), and MUST derive, for each non-empty value, the detected scalar/array/object type, string length range, detected string format, and numeric range. Per-property usage MUST be expressed both as a raw count and as a percentage of total objects. The returned report MUST include `discovered_properties`, `existing_properties`, `property_usage_stats`, `data_types`, an `analysis_date` in ISO-8601 (`c`) format, and a `suggestions` array that merges suggestions for newly-discovered properties with improvement suggestions for already-declared properties, plus an `analysis_summary` carrying `new_properties_count`, `existing_properties_improvements`, and `total_recommendations`.

#### Scenario: Exploring a schema with no objects returns an empty report

- **GIVEN** a schema with id `7` that has zero stored objects
- **WHEN** `SchemaService::exploreSchemaProperties(7)` is called
- **THEN** the report's `total_objects` MUST be `0`
- **AND** `discovered_properties` and `suggestions` MUST be empty arrays
- **AND** `existing_properties` MUST equal the schema's declared properties
- **AND** a `message` field MUST indicate that no objects were available for analysis

#### Scenario: Discovered properties carry usage counts and percentages

- **GIVEN** a schema with 4 stored objects, 3 of which carry a `phoneNumber` string in their payload
- **WHEN** `SchemaService::exploreSchemaProperties()` runs
- **THEN** `property_usage_stats['counts']['phoneNumber']` MUST be `3`
- **AND** `property_usage_stats['percentages']['phoneNumber']` MUST be `75.0`
- **AND** the discovered `phoneNumber` entry MUST record a `usage_percentage` of `75.0`

#### Scenario: The `@self` metadata key is excluded from analysis

- **GIVEN** stored objects whose payloads include a `@self` key alongside business properties
- **WHEN** the payloads are analysed
- **THEN** the `@self` key MUST NOT appear in `discovered_properties` or `property_usage_stats`

#### Scenario: A missing schema raises an exception

- **GIVEN** a schema id that does not resolve via `SchemaMapper::find()`
- **WHEN** `SchemaService::exploreSchemaProperties()` is called
- **THEN** an `\Exception` MUST be thrown whose message references the requested schema id

### Requirement: SchemaService MUST apply confirmed exploration suggestions back onto a schema

`SchemaService::updateSchemaFromExploration(int $schemaId, array $propertyUpdates)` MUST merge the supplied property definitions onto the schema's existing `properties` map — each key in `$propertyUpdates` overwriting or adding the corresponding entry while leaving untouched properties intact — and MUST persist the result. After merging, the method MUST call `Schema::regenerateFacetsFromProperties()` so that facet configuration stays consistent with the new property set, then persist via `SchemaMapper::update()` and return the updated `Schema`. Any failure during lookup, merge, or persistence MUST be logged at `error` level and re-thrown as an `\Exception` whose message wraps the underlying error.

#### Scenario: New and changed properties are merged without dropping existing ones

- **GIVEN** a schema whose `properties` already contains `name`
- **WHEN** `updateSchemaFromExploration($id, ['email' => ['type' => 'string'], 'name' => ['type' => 'string', 'format' => 'fullname']])` is called
- **THEN** the persisted schema MUST contain `name` (with the updated definition) AND `email`
- **AND** `Schema::regenerateFacetsFromProperties()` MUST be invoked before persistence
- **AND** the updated `Schema` entity MUST be returned

#### Scenario: A persistence failure is logged and re-thrown

- **GIVEN** `SchemaMapper::update()` throws during the save
- **WHEN** `updateSchemaFromExploration()` runs
- **THEN** an `error` log MUST be emitted referencing the schema id
- **AND** an `\Exception` whose message wraps the underlying error MUST be re-thrown
