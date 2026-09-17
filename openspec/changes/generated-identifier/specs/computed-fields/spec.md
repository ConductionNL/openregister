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
- @e2e tests/e2e/api-direct/generated-identifier.spec.ts

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
- @e2e tests/e2e/api-direct/generated-identifier.spec.ts

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

### Requirement: A sequence may be random rather than counted (REQ-GID-004)

A sequence MAY be declared random, with a length and an alphabet, and
SHALL allocate under the same uniqueness guarantee as a counted sequence.
A random identifier SHALL NOT be derivable from any other issued
identifier.

#### Scenario: the case volume is not readable from a case number

- **GIVEN** a property generated from a random sequence of ten characters
- **WHEN** three objects are created
- **THEN** each carries a unique identifier and none implies the others

#### Scenario: uniqueness holds under concurrency

- **GIVEN** a random sequence
- **WHEN** many objects are created at once
- **THEN** no identifier is issued twice
- @e2e exclude {concurrency, covered by unit tests}

### Requirement: An object carries foreign identifiers that name their issuer (REQ-GID-005)

An object MAY carry identifiers allocated by another system, each naming
the issuing system. Foreign identifiers SHALL be indexed and SHALL resolve
an object exactly, so an inbound message can find its object by the
sender's own identifier. Two systems MAY issue the same value without
ambiguity.

#### Scenario: a ZGW message finds its zaak

- **GIVEN** an object carrying a foreign identifier issued by a named system
- **WHEN** a lookup is made for that system and value
- **THEN** the object is returned

#### Scenario: the same number from two senders is not ambiguous

- **GIVEN** two objects carrying the same value from two different issuers
- **WHEN** each is looked up with its issuer
- **THEN** each lookup returns its own object

### Requirement: A second human identifier, reserved values, and a scheme change as a migration (REQ-GID-006)

A schema MAY declare a second generated identifier beside the first, with
its own sequence and format. A value or a pattern MAY be reserved so that
no sequence issues it. Changing the format of an already-issued identifier
SHALL run as a background job reporting progress, SHALL keep each previous
value as a foreign identifier issued by this instance, and SHALL be
refused while another migration of the same sequence is running.

#### Scenario: the number in the letter is not the system's

- **GIVEN** a schema declaring a second identifier with its own prefix
- **WHEN** an object is created
- **THEN** it carries both identifiers, each from its own sequence

#### Scenario: a reserved value is never issued

- **GIVEN** a reserved value
- **WHEN** the sequence would reach it
- **THEN** it is skipped and the next value is issued

#### Scenario: an old zaaknummer in a brief still finds the case

- **GIVEN** an identifier scheme change that has run
- **WHEN** the previous value is looked up
- **THEN** the object is returned through its foreign identifier

#### Scenario: two renumberings do not run at once

- **GIVEN** a scheme migration running on a sequence
- **WHEN** a second is started on the same sequence
- **THEN** it is refused, naming the running one
