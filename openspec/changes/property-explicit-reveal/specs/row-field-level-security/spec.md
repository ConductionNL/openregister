# row-field-level-security Delta

## ADDED Requirements

### Requirement: An explicit-reveal property is masked until the reader asks to see it

A property whose authorization declares `"reveal": "explicit"` SHALL be answered on the objects API as its mask (five
stars and its last four characters) to a reader who may read it, without recording a reveal. A read naming the
property in `_reveal` SHALL answer the value only when the reader may read it, and SHALL record the reveal when the
property is audited. An update that sends the mask SHALL keep the stored value.

#### Scenario: The BSN on a contact page

- **GIVEN** brpPerson.citizenServiceNumber with read rights for dossiq-sensitive, `audit: true`, `reveal: "explicit"`
- **WHEN** Pieter (dossiq-sensitive) opens Sanne's contact page
- **THEN** the page receives "*****7609" and no reveal is recorded
- **WHEN** he presses "Tonen", which reads `?_reveal=citizenServiceNumber`
- **THEN** he receives the number and one `property.revealed` row is written

#### Scenario: No read rights

- **GIVEN** a reader outside dossiq-sensitive
- **WHEN** the read names `_reveal=citizenServiceNumber`
- **THEN** the property is absent and nothing is recorded
