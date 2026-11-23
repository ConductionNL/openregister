# Audit trail

## ADDED Requirements

### Requirement: The readable audit list can be narrowed to one register

`GET /api/audit-trails/readable` SHALL accept `register`, a register id, and
SHALL then list only entries of that register. A `register` that is not a whole
number SHALL be ignored. The filter SHALL NOT widen what the caller may read.

#### Scenario: An app lists its own register's entries

- **GIVEN** entries in registers 5 and 6 the caller may read
- **WHEN** the caller asks for `/api/audit-trails/readable?register=5`
- **THEN** the trail is queried with register 5 only

#### Scenario: A register that is not an id is ignored

- **GIVEN** the readable audit list
- **WHEN** the caller passes `register=humaniq`
- **THEN** the list is the same as without the parameter
- @e2e exclude a query-filter detail of an API endpoint; covered by PHPUnit
