# file-risk-classification

## ADDED Requirements

### Requirement: A licence plate raises a file to the medium tier

`RiskLevelService` SHALL map the entity type `LICENSE_PLATE` to the base tier
`medium`, beside `PERSON`, `PHONE` and `ADDRESS`, so a file whose only
personal data is a plate is not reported as low risk through the unrecognised
type default.

#### Scenario: a file with only a plate reads medium

- **GIVEN** a file whose only detected entity is a `LICENSE_PLATE`
- **WHEN** its risk level is computed and shown in the Files sidebar
- **THEN** the risk level is `medium`
- @e2e exclude {specified only; task 2.1 adds the RiskLevelServiceTest case, task 4.1 adds tests/e2e/ci/licence-plate-detection.spec.ts}
