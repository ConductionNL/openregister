# faceting-configuration

## ADDED Requirements

### Requirement: A facet carries a bucket for the objects missing the value (REQ-SQF-001)

A facet response SHALL carry a bucket counting the objects in scope that
hold no value for the faceted property, alongside the value buckets. The
count SHALL be produced by the same query that produces the value buckets,
never by a second pass over the result set, and the bucket SHALL be
selectable as a filter that returns exactly those objects.

#### Scenario: the cases with no result type are counted and clickable

- **GIVEN** forty objects of which twelve hold no `resultType`
- **WHEN** the facet for `resultType` is requested
- **THEN** the response holds a missing bucket with count twelve
- **AND** selecting it returns those twelve objects

#### Scenario: the missing bucket obeys the caller's access

- **GIVEN** a user who may read twenty of the forty objects, eight of them missing the value
- **WHEN** the same facet is requested
- **THEN** the missing bucket counts eight
- @e2e exclude {access resolution, covered by unit tests}
