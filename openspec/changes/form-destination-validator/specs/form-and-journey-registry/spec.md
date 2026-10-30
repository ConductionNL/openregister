# form-and-journey-registry Delta: form-destination-validator

**Status**: draft
**Scope**: replaces the staging clauses of "The run API MUST stage answers and commit only at declared steps" in the open change `or-form-and-journey-registry`, per hydra ADR-117. Task 5.1 edits that change to match.

## ADDED Requirements

### Requirement: A saved journey MUST be its draft objects and MUST commit a step all or none

There SHALL be no `journeyRun` object holding answers. A saved journey SHALL be its `writes[]` destination objects in status `draft` (decision 180). Objects SHALL leave `draft`, or be created, only when a step declaring `writes[]` is submitted, through `FormSubmitService`, all or none, with a later entry able to reference an earlier entry's id.

#### Scenario: Advancing without a writes step activates nothing

- **GIVEN** a journey advanced past two steps and saved, neither step declaring `writes[]` as submitted
- **WHEN** the target registers are queried
- **THEN** any object for this journey is in status `draft`, and no `journeyRun` object exists

#### Scenario: A partial failure leaves nothing behind

- **GIVEN** a step whose second write is refused after the first succeeded
- **WHEN** the step is submitted
- **THEN** the first object is deleted before the response, and the filer sees the second write's findings
