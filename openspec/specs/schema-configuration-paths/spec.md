# schema-configuration-paths Specification

## Purpose
A schema's configuration names the properties that act as an object's name, description and similar fields. This spec lets those settings point into nested data with a dot-notation path.

## Requirements

### Requirement: A name or description field may be a dot-notation path (REQ-SCP-001)

Saving a schema SHALL accept an `objectNameField` or `objectDescriptionField` that is a dot-notation path whose first segment is a property of the schema, alone or as one entry of a `|` fallback list, because the metadata reader walks such paths. A plain name, or a path whose first segment is no property, SHALL still be refused.

#### Scenario: opencatalogi's publiccode schema imports
- **GIVEN** a schema with an object property `description` and `objectDescriptionField` `description.nl.longDescription | description.en.longDescription`
- **WHEN** the schema is saved or imported
- **THEN** it SHALL be stored, and the import SHALL NOT report it as a rejected schema
- @e2e exclude {save-time validation; covered by SchemaMapperConfigurationPathTest}

#### Scenario: a path into a property the schema does not have is refused
- **GIVEN** a schema without a property `summary`
- **WHEN** it is saved with `objectDescriptionField` `summary.nl.longDescription`
- **THEN** it SHALL be refused naming the field
- @e2e exclude {save-time validation; covered by SchemaMapperConfigurationPathTest}
