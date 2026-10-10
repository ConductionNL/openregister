# form-and-journey-registry Delta: form-destination-validator

**Status**: draft
**Scope**: replaces the staging clauses of "The run API MUST stage answers and commit only at declared steps" in the open change `or-form-and-journey-registry`, per hydra ADR-117. Task 5.1 edits that change to match.

## ADDED Requirements

### Requirement: A journey run MUST store no written ids and MUST commit a step all or none

The API SHALL expose start, answer, resume and submit operations over a `journeyRun`. Answers MAY be persisted to the run only while ADR-117 approves drafts. The run SHALL NOT store ids of written objects. Objects SHALL be created only when a step declaring `writes[]` is submitted, through `FormSubmitService`, all or none, with a later entry able to reference an earlier entry's id.

#### Scenario: Advancing without a writes step creates nothing

- **GIVEN** a run advanced past two steps, neither declaring `writes[]`
- **WHEN** the target registers are queried
- **THEN** no object has been created

#### Scenario: A partial failure leaves nothing behind

- **GIVEN** a step whose second write is refused after the first succeeded
- **WHEN** the step is submitted
- **THEN** the first object is deleted before the response, and the filer sees the second write's findings
