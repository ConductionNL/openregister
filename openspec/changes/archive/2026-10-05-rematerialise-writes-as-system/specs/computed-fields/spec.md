# computed-fields

## ADDED Requirements

### Requirement: The rematerialise command writes as the system

`occ openregister:rematerialise-calculations <register> <schema>` SHALL save each re-evaluated object without RBAC and without multitenancy filtering, because occ runs without a user session and a maintenance command acts for the system. It SHALL exit non-zero when any object could not be saved.

#### Scenario: a schema with an authorization block is rematerialised

- **GIVEN** a schema whose authorization block grants update only to `instructors`, with three objects whose materialised calculation is stale
- **WHEN** an administrator runs the command from occ
- **THEN** all three objects are saved and the command reports "Touched 3, unchanged 0, failed 0" and exits 0
- @e2e exclude {occ command, covered by RematerialiseCalculationsAsSystemTest}
