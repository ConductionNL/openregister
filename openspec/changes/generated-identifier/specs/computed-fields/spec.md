# computed-fields

## ADDED Requirements

### Requirement: A property declares a generated identifier from a sequence and a format

A string property MAY declare `x-openregister-generated` with a sequence
name, a format holding `{seq:n}`, `{year}`, `{month}` and literal text, and
`resetOn` of `never` or `year`. Schema save SHALL refuse an unknown
placeholder or a non-string property. On create, when the property is
empty, the system SHALL take the next value of the sequence under a lock
within the create transaction and render the format. Values SHALL be unique
per sequence and period and SHALL never be reused after a rollback.

#### Scenario: two creates get consecutive identifiers

- **GIVEN** schema `case` whose `identifier` declares sequence `case`, format `Z-{year}-{seq:5}`, `resetOn: year`
- **WHEN** two cases are created in 2026 without an identifier
- **THEN** their identifiers are `Z-2026-00001` and `Z-2026-00002`
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/api-direct/generated-identifier.spec.ts when the listener ships}

#### Scenario: parallel creates never collide

- **GIVEN** the same schema
- **WHEN** fifty cases are created concurrently
- **THEN** fifty distinct identifiers result
- @e2e exclude {concurrency is exercised by a dedicated unit test on both databases}

### Requirement: A generated identifier is frozen after creation

An update that changes a generated property SHALL be refused with 422. An
import or create that supplies a value SHALL keep it and SHALL advance the
sequence to at least the `{seq}` parsed from that value.

#### Scenario: editing the identifier is refused

- **GIVEN** a case with identifier `Z-2026-00001`
- **WHEN** a user updates the case with identifier `Z-2026-00009`
- **THEN** the update is refused with 422 and the identifier is unchanged
- @e2e exclude {proposal only; task 3.2 adds tests/e2e/api-direct/generated-identifier.spec.ts when the guard ships}

#### Scenario: an imported value advances the counter

- **GIVEN** an import creating a case with identifier `Z-2026-00120`
- **WHEN** the next case is created without an identifier
- **THEN** it receives `Z-2026-00121`
- @e2e exclude {import handling is covered by unit tests on the listener}

### Requirement: Two schemas may share one sequence

Two schemas naming the same sequence SHALL draw from one counter.

#### Scenario: cases and complaints number from one counter

- **GIVEN** schemas `case` and `complaint` both naming sequence `register`
- **WHEN** a case and then a complaint are created
- **THEN** the complaint's `{seq}` is one higher than the case's
- @e2e exclude {shared counters are covered by unit tests on the sequence service}
