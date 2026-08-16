# form-and-journey-registry Delta: or-form-and-journey-registry

**Status**: in-progress
**Scope**: openregister
**OpenSpec changes**:

- [or-form-and-journey-registry](../../)

## Purpose

Declares `form`, `journey` and `journeyRun` in a `forms` register, the API that
drives a run, the named formats that replace app-local validator forks, and the
retention job that purges expired runs. Implements the OpenRegister half of
ADR-085 and of the cross-app delta
`hydra/openspec/changes/portaliq-phase-two/specs/forms-and-journeys/spec.md`.
Related: ADR-065 (the flow engine this does not extend), ADR-005 (fail-closed),
ADR-082 (throttling).

## ADDED Requirements

### Requirement: A form body MUST validate through the canonical manifest validator

A `form` object's `config` SHALL be validated on write by the same
`validateManifestV2()` path an app manifest is validated by. A separate or
subset schema for form bodies MUST NOT exist.

#### Scenario: The validator rejects what it rejects in a manifest

- **GIVEN** a form config whose field declares `validation: { minLength: 2 }`
- **WHEN** the object is written
- **THEN** the write is rejected with the same error the manifest validator
  emits for that input

#### Scenario: A valid manifest form page is a valid form body

- **GIVEN** a `type: "form"` page config taken from a shipping app manifest
- **WHEN** it is written as a form body
- **THEN** the write succeeds

### Requirement: A journey MUST declare steps, branching, writes and access

A `journey` SHALL declare an ordered `steps[]` of `form` | `review` |
`confirmation`; optional per-step `next` rules whose conditions `$ref` the
canonical `$defs.visibleWhen`; optional per-step `writes[]` of
`{ register, schema, mapping }`; and a required `access` of `anonymous` |
`authenticated` | `minTrust`.

#### Scenario: A branching rule using an unknown operator is rejected on write

- **GIVEN** a `next` rule declaring `op: "contains"`
- **WHEN** the journey is written
- **THEN** the write is rejected naming the accepted operators

#### Scenario: A write mapping is validated against the target schema

- **GIVEN** a `writes[]` entry whose mapping names a property absent from the
  target schema
- **WHEN** the journey is written
- **THEN** the write is rejected naming the property and the target schema

#### Scenario: A journey with no declared access is rejected

- **GIVEN** a journey object with no `access`
- **WHEN** it is written
- **THEN** the write is rejected — access is never inferred

### Requirement: The run API MUST stage answers and commit only at declared steps

The API SHALL expose start, answer, resume and submit operations over a
`journeyRun`. Answers SHALL be persisted to the run. Objects SHALL be created
or updated only at a step declaring `writes[]`, in declared order, with a later
entry able to reference an earlier entry's id.

#### Scenario: Advancing without a writes step creates nothing

- **GIVEN** a run advanced past two steps, neither declaring `writes[]`
- **WHEN** the target registers are queried
- **THEN** no object has been created

#### Scenario: A dependent write receives the preceding write's id

- **GIVEN** a step declaring an organisation write followed by a contact write
  whose mapping references the organisation's id
- **WHEN** the step commits
- **THEN** the contact carries the organisation's id

#### Scenario: A partial failure is recorded and is not duplicated on retry

- **GIVEN** a step whose second write fails validation after the first
  succeeded
- **WHEN** the step is re-submitted
- **THEN** the failure is recorded on the run, and the first object is updated
  rather than created a second time

#### Scenario: An answer for a field the current step does not declare is refused

- **GIVEN** a run at step one
- **WHEN** an answer is submitted for a field belonging to step three
- **THEN** it is refused — the client does not choose which fields are in scope

### Requirement: A run MUST be resumable without becoming an oracle

A run SHALL be resumable by account for an authenticated filer and by an
unguessable single-purpose token for an anonymous one. A token presented
against a run it does not belong to, and a token for a run that does not exist,
SHALL produce indistinguishable responses.

#### Scenario: An anonymous filer resumes on a new device

- **GIVEN** an anonymous run two steps in, and its resume token
- **WHEN** the token is presented from a session-less client
- **THEN** the run resumes at the recorded step with the recorded answers

#### Scenario: A mismatched token and an unknown run look identical

- **GIVEN** a valid token for run A and an identifier for run B
- **WHEN** they are presented together
- **THEN** the response is identical to the response for a wholly unknown run

### Requirement: Anonymous submission MUST be throttled and MUST stamp no ownership

A journey declaring `access: anonymous` SHALL be startable and submittable with
no session. Its writes SHALL NOT stamp subject or organisation ownership. Its
endpoints SHALL be rate-limited.

#### Scenario: An anonymous write carries no owner

- **GIVEN** a completed anonymous journey
- **WHEN** the written object is inspected
- **THEN** it carries no subject or organisation ownership stamp

#### Scenario: Excess anonymous submissions are refused

- **GIVEN** an anonymous journey
- **WHEN** submissions from one source exceed the configured rate
- **THEN** further submissions are refused
- **AND** the refusal is confirmed by two independent discriminators, because
  an absent success is not by itself evidence that throttling fired

### Requirement: Named formats MUST replace the app-local validator forks

OpenRegister SHALL provide `email`, `website` and `nl-phone` named formats
alongside the existing `bsn`, `iso8601-datetime` and `user` formats,
referenceable by name from a form field. They SHALL enforce identically whether
reached through the UI or directly through the API.

#### Scenario: The API rejects what the UI rejects

- **GIVEN** a value the client-side check rejects for `nl-phone`
- **WHEN** the same value is submitted directly to the API
- **THEN** it is rejected with the named-format error

#### Scenario: A rejected value never reaches the target register

- **GIVEN** a `writes[]` step whose input fails a named format
- **WHEN** the step commits
- **THEN** no object is written

### Requirement: Expired runs MUST be purged, and the purge MUST report its work

A `journeyRun` SHALL carry a retention period. A background job SHALL delete
expired runs, including any staged uploads, and SHALL report the number
deleted.

#### Scenario: A purge run reports a count

- **WHEN** the retention job executes
- **THEN** it reports the number of runs deleted
- **AND** a run that deletes nothing is distinguishable from a job that never
  executed

#### Scenario: A purge failure is not swallowed

- **GIVEN** a deletion that fails
- **WHEN** the job executes
- **THEN** the failure is surfaced, not caught and discarded

#### Scenario: Staged uploads do not outlive their run

- **GIVEN** an expired run carrying an uploaded file
- **WHEN** the job executes
- **THEN** the file is deleted with the run
