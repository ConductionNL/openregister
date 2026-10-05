# cross-register-existence-query

## ADDED Requirements

### Requirement: A caller can ask whether a row exists without reading it

The system SHALL offer `POST /api/objects/exists`, taking up to ten probes of
`{register, schema, filters}` and answering per probe whether a row matched and
how many. The answer SHALL be assembled from named fields and SHALL NOT carry
any property of a matched row unless the caller named it in `reveal`. A probe
naming more than the bound SHALL be refused rather than truncated.

#### Scenario: a household is known elsewhere, and nothing else is learned

- **GIVEN** a register holding one open row for a person, carrying a title, a status and a case number
- **WHEN** a caller probes for that person with no `reveal`
- **THEN** the answer says a row exists and how many, and carries none of the title, the status or the case number
- @e2e exclude {projection, covered by CrossRegisterExistenceServiceTest}

#### Scenario: a probe that matches nothing says so

- **GIVEN** a register holding no row for a person
- **WHEN** a caller probes for them
- **THEN** the answer says no row exists, and is not an error
- @e2e exclude {covered by CrossRegisterExistenceServiceTest}

#### Scenario: more probes than the bound are refused

- **GIVEN** a call carrying eleven probes
- **WHEN** it is sent
- **THEN** the response is 422 naming the bound, and no register is queried
- @e2e exclude {covered by CrossRegisterExistenceServiceTest}

### Requirement: Revealed fields are bounded by the schema, not by the caller

`reveal` SHALL default to empty. A field a caller names SHALL be returned only
when the schema declares it and does not mark it sensitive. A refused field
SHALL be reported by name in the answer, so the caller learns the request was
narrowed rather than silently receiving less.

#### Scenario: a contact field is revealed on request

- **GIVEN** a schema declaring a non-sensitive handler field
- **WHEN** a caller probes with that field in `reveal`
- **THEN** the answer carries that field and nothing else from the row
- @e2e exclude {covered by CrossRegisterExistenceServiceTest}

#### Scenario: a sensitive field is refused by name

- **GIVEN** a schema marking a field sensitive
- **WHEN** a caller names it in `reveal`
- **THEN** the field is absent from the answer and is listed as refused
- @e2e exclude {covered by CrossRegisterExistenceServiceTest}

#### Scenario: a field the schema does not declare is refused too

- **GIVEN** a caller naming a property no schema declares
- **WHEN** the probe runs
- **THEN** the field is absent from the answer and is listed as refused
- @e2e exclude {covered by CrossRegisterExistenceServiceTest}

### Requirement: A probe is authorised as the read it replaces

Every probe SHALL be authorised as a read of that register and schema by the
calling identity. A caller who could not have searched a register SHALL NOT
learn from this endpoint whether it holds a row, and SHALL be told that the
probe was refused rather than told that nothing exists.

#### Scenario: an unauthorised register answers refused, not empty

- **GIVEN** a caller with no read access to a register
- **WHEN** they probe it
- **THEN** the probe reports that it was refused
- **AND** it does NOT report that no row exists
- @e2e exclude {covered by CrossRegisterExistenceServiceTest}

#### Scenario: an unauthenticated caller is refused before any register is asked

- **GIVEN** no session
- **WHEN** the endpoint is called
- **THEN** the response is 401 and no register is queried
- @e2e exclude {covered by ObjectsControllerExistsTest}
