# data-import-export Delta: export-honours-selected-ids

## ADDED Requirements

### Requirement: An export of selected ids holds only those objects
@e2e exclude REST API export parameter; proven by tests/Unit/Service/ExportServiceSelectedIdsTest.php

The export endpoint SHALL accept `_ids`, as a list or as a comma-separated
string, and SHALL export only the objects with those ids, within what the
caller may read under RBAC and multitenancy. Without `_ids` the export SHALL be
unchanged.

#### Scenario: Two selected case types
- **GIVEN** a schema with 52 objects
- **WHEN** a caller requests `export?format=json&_ids[]=a&_ids[]=b`
- **THEN** the search behind the export SHALL be narrowed to the ids `a` and `b`

#### Scenario: A comma-separated selection
- **GIVEN** the same schema
- **WHEN** a caller requests `export?format=csv&_ids=a,b`
- **THEN** the search SHALL be narrowed to the ids `a` and `b`
