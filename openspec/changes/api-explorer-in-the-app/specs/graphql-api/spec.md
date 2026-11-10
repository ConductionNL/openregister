# graphql-api Specification (delta)

## ADDED Requirements

### Requirement: REQ-APIX-001 The API can be tried from inside the app

The register screen SHALL link to an API page that runs REST and GraphQL calls as the signed-in user and shows a curl and a JavaScript sample for each operation. The page SHALL load no script from outside the instance.

#### Scenario: a developer tries a call

- **GIVEN** a register with one schema
- **WHEN** a developer opens API from the register screen and runs the list operation
- **THEN** the page shows the response from their own data and a curl sample for the same call
- @e2e exclude {specified only; task 1 adds the test}

#### Scenario: the explorer works under a strict policy

- **GIVEN** an instance that blocks outside scripts
- **WHEN** a developer opens the GraphQL explorer
- **THEN** the explorer loads and runs a query
- @e2e exclude {specified only; task 1 adds the test}
