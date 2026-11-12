# magic-table-reads Specification

## Purpose
Objects of a schema with its own magic table are read back from that table's columns. This spec sets how reserved-looking column names, such as a property whose name starts with an underscore, read back as the object's own properties.

## Requirements

### Requirement: A property whose name starts with an underscore is read as a property (REQ-MTR-001)

A magic-table column whose name starts with `_` SHALL be read as the schema property it holds when the schema declares that property, unless ObjectEntity has an attribute of the same name, in which case it SHALL be read as that metadata. Every reader of a magic-table row SHALL apply this rule, and the bulk writer SHALL write such a property. A writer, bulk or single-object, SHALL NOT write a declared property into a metadata column: when the column a property sanitises to (`_owner`, `_Owner`, `__owner_`, `_authorization`, `_status`, ...) is one ObjectEntity has an attribute for, the property value is not written, so a client value can never replace the value the server stamped there. A metadata column ObjectEntity has no attribute for SHALL be skipped without failing the row, and SHALL be logged at debug level at most once per field per process.

#### Scenario: shillinq's `_meta` property survives a read
- **GIVEN** a schema with properties `code` and `_meta` (an object), and a stored row with `_meta` = `{"source":"seeded"}`
- **WHEN** the row is converted to an ObjectEntity
- **THEN** the entity SHALL carry `_meta` = `{"source":"seeded"}` in its object data, and no error SHALL be logged
- @e2e exclude {row conversion has no screen of its own; covered by MagicStatisticsHandlerTest}

#### Scenario: an unknown metadata column does not fail the row
- **GIVEN** a row with a `_meta` column that is no property of the schema, and `_schema_version` = `1.2.0`
- **WHEN** the row is converted
- **THEN** the entity SHALL be returned with its uuid and `schemaVersion` `1.2.0`, and `_meta` SHALL be skipped
- @e2e exclude {row conversion has no screen of its own; covered by MagicStatisticsHandlerTest}

#### Scenario: a property named like a metadata column does not overwrite it
- **GIVEN** a schema declaring properties `_owner`, `_authorization` and `_status`, and an object whose payload carries a value for each
- **WHEN** the object is prepared for a bulk or single-object write
- **THEN** the `_owner` column SHALL hold the owner the server stamped, and no client value SHALL reach `_authorization` or `_status`
- @e2e exclude {write preparation has no screen of its own; covered by MagicBulkHandlerRawAppendTest and MagicMetadataColumnTest}
