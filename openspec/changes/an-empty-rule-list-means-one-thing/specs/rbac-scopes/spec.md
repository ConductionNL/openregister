# rbac-scopes

## ADDED Requirements

### Requirement: An empty rule list means one thing in each layer (REQ-RBAC-142)

A schema cascade SHALL treat an empty or absent action as denied, because nothing
runs after it. A property authorization block SHALL treat an action it does not
name as carrying no restriction at that layer, with the object cascade still
deciding. Neither SHALL be changed to match the other without first measuring the
declarations that rely on it.

#### Scenario: a named property action still narrows

- **GIVEN** a property naming a group for `read`
- **WHEN** somebody outside that group reads it
- **THEN** it is refused

#### Scenario: an unnamed property action does not narrow

- **GIVEN** the same property, naming nothing for `update`
- **WHEN** the property layer is asked
- **THEN** it raises no objection, and the object cascade decides
- @e2e exclude {layer semantics, covered by unit tests}

#### Scenario: a schema cascade's empty action is denied

- **GIVEN** a schema cascade declaring an action as an empty list
- **WHEN** permission is checked
- **THEN** it is denied, with only the admin and owner bypasses surviving
