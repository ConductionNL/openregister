# public-access-links

## ADDED Requirements

### Requirement: A publication link opens one object, view or file as its own principal (REQ-ABL-001)

The system SHALL mint a link that opens one object, one saved view or one
file without an account. The link SHALL carry a random anchor that cannot
be derived from the subject's identifier, and SHALL resolve a principal
that is the link itself rather than any user.

#### Scenario: a besluitenlijst is published from the record

- **GIVEN** a saved view of published decisions
- **WHEN** a link is minted for it and opened by somebody with no account
- **THEN** the view is served

#### Scenario: knowing the case number is not knowing the link

- **GIVEN** an object with a known identifier
- **WHEN** a link anchor is constructed from that identifier
- **THEN** it resolves to nothing

### Requirement: A link declares its capabilities, carries an expiry and may carry a password (REQ-ABL-002)

A link SHALL declare which capabilities its holder has, from read, comment
and upload, defaulting to read only. Anything not declared SHALL be
refused. A link SHALL carry an expiry and SHALL be refused without one. A
link MAY carry a password, checked at use.

#### Scenario: an adviser may read and comment, and not upload

- **GIVEN** a link declaring read and comment
- **WHEN** the holder tries to upload a file
- **THEN** it is refused

#### Scenario: a link without an end date is not minted

- **GIVEN** a mint request carrying no expiry
- **WHEN** it is submitted
- **THEN** it is refused, naming the requirement

#### Scenario: a forwarded link still asks

- **GIVEN** a link carrying a password
- **WHEN** it is opened without the password
- **THEN** the content is not served

### Requirement: A revoked or expired link answers 404, and every use is recorded (REQ-ABL-003)

A revoked, expired or disabled link SHALL answer 404 rather than a refusal
or a reduced page. Every use of a link SHALL be written to the audit trail
with the link, the act, the time and the calling address.

#### Scenario: revocation is immediate and silent

- **GIVEN** a link that is revoked
- **WHEN** it is opened
- **THEN** the response is 404 and reveals nothing about the record

#### Scenario: a wrong publication can be reconstructed

- **GIVEN** a link used four times
- **WHEN** the audit trail is read
- **THEN** four entries name the link, the act, the time and the address
- @e2e exclude {audit assertion, covered by unit tests}

### Requirement: A link never sees past the object's own rules (REQ-ABL-004)

A link's reads SHALL apply the same property visibility, field-level rules
and timeline entry visibility as any other read. A hidden property, an
internal entry and a file the object does not carry SHALL NOT be served
through a link.

#### Scenario: an internal note stays internal

- **GIVEN** an object with one internal and one public timeline entry
- **WHEN** it is read through a publication link
- **THEN** only the public entry is served

#### Scenario: a hidden property stays hidden

- **GIVEN** a schema hiding a property
- **WHEN** the object is read through a link
- **THEN** the property is absent
