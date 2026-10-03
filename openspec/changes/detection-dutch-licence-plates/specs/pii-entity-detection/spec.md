# pii-entity-detection

## ADDED Requirements

### Requirement: The detector recognises a licence plate as its own entity type

Open Register's entity detector SHALL know an entity type `LICENSE_PLATE` with
category personal data. Every list that enumerates entity types (category,
risk tier, placeholder label) SHALL include it, and its label SHALL be
translatable, reading `KENTEKEN` on a Dutch instance.

#### Scenario: a plate in a document becomes a licence plate entity

- **GIVEN** a privacy officer on an instance with the `nl` pattern set enabled and the regex method
- **AND** a text file whose content reads "Het voertuig met kenteken 12-GBK-3 stond geparkeerd"
- **WHEN** the officer calls `POST /api/files/{fileId}/extract` and then `GET /api/entities`
- **THEN** the response lists one entity with type `LICENSE_PLATE` and value `12-GBK-3`
- @e2e exclude {specified only; task 4.1 adds tests/e2e/ci/licence-plate-detection.spec.ts}

#### Scenario: the anonymised copy shows a Dutch placeholder

- **GIVEN** the same file with its plate detected, on an instance whose language is Dutch
- **WHEN** the officer calls `POST /api/files/{fileId}/anonymize`
- **THEN** the anonymised copy reads `[KENTEKEN-1]` where the plate was, and the plate text appears nowhere in it
- @e2e exclude {specified only; task 4.1 adds tests/e2e/ci/licence-plate-detection.spec.ts}

### Requirement: Jurisdiction identifiers come from a pattern set per country

Identifiers that belong to one country SHALL be recognised by a jurisdiction
pattern set, not by the generic patterns. The `nl` set SHALL recognise a
licence plate in any of the sidecodes 1 to 14 and a BSN that passes the
elfproef. Each rule SHALL live once, in `lib/Formats/`, and the pattern set
SHALL call it rather than carry its own copy.

#### Scenario: every sidecode is recognised

- **GIVEN** a text holding one hyphenated plate for each of the sidecodes 1 to 14
- **WHEN** the `nl` pattern set runs over it
- **THEN** fourteen `LICENSE_PLATE` entities are returned, each carrying the sidecode it matched
- @e2e exclude {specified only; task 2.2 adds NlPatternSetTest, task 4.1 adds tests/e2e/ci/licence-plate-detection.spec.ts for the end-to-end path}

#### Scenario: a BSN is found only when it passes the elfproef

- **GIVEN** a text holding "BSN 111222333" and "nummer 123456789"
- **WHEN** the `nl` pattern set runs over it
- **THEN** one `SSN` entity is returned, for `111222333`, and `123456789` is not flagged
- @e2e exclude {specified only; task 4.1 adds tests/e2e/ci/licence-plate-detection.spec.ts}

### Requirement: A plate-shaped token is not a plate without proof

The `nl` set SHALL flag a hyphenated candidate only when it matches one
sidecode exactly, uses only letters allowed for that sidecode and stands alone
as a token. It SHALL flag an unhyphenated candidate only when a context word
such as `kenteken` or `licence plate` sits within 30 characters before it. A
span already claimed by a BSN or IBAN SHALL NOT also be a plate.

#### Scenario: a case number that looks like a plate is left alone

- **GIVEN** a text holding "zaak ZK-12-AB-34", "datum 2024-12-AB", "postcode 1234 AB" and "artikel 12GBK3"
- **WHEN** the `nl` pattern set runs over it
- **THEN** no `LICENSE_PLATE` entity is returned
- @e2e exclude {specified only; task 2.2 adds NlPatternSetTest, task 4.1 adds tests/e2e/ci/licence-plate-detection.spec.ts}

#### Scenario: an unhyphenated plate counts with a context word

- **GIVEN** a text holding "kenteken 12GBK3"
- **WHEN** the `nl` pattern set runs over it
- **THEN** one `LICENSE_PLATE` entity is returned with confidence 0.6
- @e2e exclude {specified only; task 2.2 adds NlPatternSetTest, task 4.1 adds tests/e2e/ci/licence-plate-detection.spec.ts}

### Requirement: Enabled pattern sets run under every detection method

The pattern sets an administrator enabled SHALL run after whichever detection
method is active (regex, Presidio, OpenAnonymiser, LLM or hybrid), and their
matches SHALL be merged into the result. Where a backend already returned an
entity for the same span, the backend's entity SHALL be kept.

#### Scenario: Presidio does not hide a plate

- **GIVEN** an instance using Presidio that returns a `PERSON` entity and no plate for a text holding "Jan de Vries, kenteken 12-GBK-3"
- **WHEN** entities are detected for that text
- **THEN** the result holds the `PERSON` entity from Presidio and a `LICENSE_PLATE` entity from the `nl` set
- @e2e exclude {specified only; task 2.3 adds EntityRecognitionHandlerTest cases, task 4.1 adds tests/e2e/ci/licence-plate-detection.spec.ts}

### Requirement: An administrator chooses the enabled pattern sets

File settings SHALL carry `entityPatternSets`, a list of jurisdiction codes.
When it was never set, it SHALL default to `["nl"]` on an instance whose
`default_phone_region` is `NL` and to an empty list otherwise. Saving an
unknown code SHALL be refused.

#### Scenario: an instance outside the Netherlands finds no Dutch plates

- **GIVEN** an instance with `default_phone_region` `BE` and no saved `entityPatternSets`
- **WHEN** a file holding "12-GBK-3" is extracted with the regex method
- **THEN** no `LICENSE_PLATE` entity is stored
- @e2e exclude {specified only; task 3.1 adds FileSettingsHandlerTest, task 4.1 adds tests/e2e/ci/licence-plate-detection.spec.ts}

#### Scenario: an unknown pattern set is refused

- **GIVEN** an administrator on the file configuration settings
- **WHEN** they call `PATCH /api/settings/files` with `entityPatternSets: ["xx"]`
- **THEN** the response is 400 and its message names `xx`
- @e2e exclude {specified only; task 3.1 adds the API check, task 4.1 adds tests/e2e/ci/licence-plate-detection.spec.ts}
