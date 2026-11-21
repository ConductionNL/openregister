## ADDED Requirements

### Requirement: An administrator can reset one shipped part from occ and over HTTP

The reset of REQ-LCA-004 SHALL be reachable through an occ command and an admin-only API route. Each call SHALL name exactly one schema and one part (a dotted path such as `authorization.read`); there SHALL be no default that resets more than one part. Without an explicit apply, the call SHALL report the part's current value and the shipped value and SHALL write nothing. An apply SHALL write only the named part, SHALL leave every other part of the schema as it is, and SHALL record the actor, the schema, the part and both values on the audit trail after the schema write succeeded. The occ command SHALL refuse to apply without an `--actor` that is an administrator.

#### Scenario: a shipped authorization rule that the instance lacks is put back

- **GIVEN** schema `learner-profile` whose stored `authorization.read` lacks the shipped rule `{"group":"authenticated","match":{"ncUserId":"$userId"}}`, and which carries another local edit to a property
- **WHEN** an administrator resets `authorization.read` of `learner-profile`
- **THEN** the stored `authorization.read` equals the shipped value, including that rule
- **AND** the other local edit is unchanged
- **AND** the audit trail has one reset row naming the administrator, `learner-profile`, `authorization.read`, the old and the new value
- @e2e exclude {admin repair with no UI surface; covered by ShippedBaselineResetServiceTest and ResetSchemaToShippedCommandTest}

#### Scenario: the command only shows what it would change unless told to apply

- **GIVEN** the same schema
- **WHEN** `occ openregister:schema:reset-to-shipped learner-profile authorization.read` runs without `--apply`
- **THEN** it prints the current and the shipped value
- **AND** nothing is written and no audit row is added
- @e2e exclude {occ command; covered by ResetSchemaToShippedCommandTest}

#### Scenario: no part named, or no administrator named, means no reset

- **GIVEN** the same schema
- **WHEN** a reset is applied with an empty part, or the command is run with `--apply` but without an `--actor` that is an administrator
- **THEN** it is refused with a reason
- **AND** nothing is written
- @e2e exclude {refusal path; covered by unit tests}
