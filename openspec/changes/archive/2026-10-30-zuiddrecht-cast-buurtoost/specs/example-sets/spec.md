# example-sets Delta

## ADDED Requirements

### Requirement: The Zuiddrecht cast holds Buurtvereniging Oost and its chair

The Zuiddrecht cast SHALL hold the organisation Buurtvereniging Oost with KvK and establishment entries, and its contact Marja Verbeek with a BRP entry.

#### Scenario: A fragment quotes the association
- **GIVEN** a fragment refers to `{{cast:buurtoost.kvk}}`
- **WHEN** the set is checked
- **THEN** the placeholder SHALL resolve
