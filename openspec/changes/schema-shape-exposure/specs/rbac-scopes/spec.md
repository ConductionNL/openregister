# rbac-scopes

## ADDED Requirements

### Requirement: A property's existence is described only to a caller who may read it (REQ-RBAC-141)

A generated API description, of any kind, SHALL describe only the properties the
caller may read. A `required` list SHALL name only properties the same document
describes. The number of properties withheld SHALL be disclosed; their names
SHALL NOT. An administrator SHALL receive the complete description.

#### Scenario: a name is information

- **GIVEN** a property carrying an authorization block or a scope
- **AND** a caller outside it
- **WHEN** they read the generated API description
- **THEN** the property is absent from it
- **AND** its example and permitted values are absent with it

#### Scenario: the document stays satisfiable

- **GIVEN** a withheld property that the schema marks required
- **WHEN** the description is generated
- **THEN** it is not listed as required

#### Scenario: there is more here and it is not yours

- **GIVEN** a caller from whom properties were withheld
- **WHEN** they read the description
- **THEN** it states how many were withheld
- **AND** does not name them

#### Scenario: an administrator sees the schema

- **GIVEN** an administrator
- **WHEN** they read the description
- **THEN** every property is present
- @e2e exclude {authorization, covered by unit tests}

#### Scenario: an ungoverned schema is described in full

- **GIVEN** a schema with no property-level authorization
- **WHEN** any caller reads the description
- **THEN** every property is present and no withheld count is stated
