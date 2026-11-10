# saved-search-views Specification (delta)

## ADDED Requirements

### Requirement: REQ-QTYPE-001 A saved view can back a read-only record type

A schema that declares `x-openregister-view` SHALL return the rows of that view query as its objects and SHALL refuse create, update and delete with 405.

#### Scenario: the type lists what the query finds

- **GIVEN** a view "active permits" over schema `permit` filtering `status=active`, and a schema `active-permit` backed by it
- **WHEN** a client lists objects of `active-permit`
- **THEN** the result holds exactly the active permits
- @e2e exclude {specified only; task 1 adds the test}

#### Scenario: the type is read-only

- **GIVEN** the same schema
- **WHEN** a client posts an object to it
- **THEN** the API answers 405
- @e2e exclude {specified only; task 1 adds the test}
