# example-sets Delta: example-set-verbatim-fields

## ADDED Requirements

### Requirement: A declared object may keep fields verbatim

A declared object MAY carry `verbatimFields`, a list of top-level field names of its `data`. Install and check SHALL write those fields exactly as declared, without filling placeholders in them. Every other field SHALL be filled as before, and a placeholder in it that cannot be filled SHALL refuse the set.

#### Scenario: A mail template with its own placeholders
- **GIVEN** an object with `subject` "Uw verzoek {{zaakNummer}} is ontvangen" and `verbatimFields: ["subject", "body"]`
- **WHEN** the set is checked and installed
- **THEN** the check SHALL pass and the subject SHALL be stored with `{{zaakNummer}}` as written

#### Scenario: A typo elsewhere still refuses
- **GIVEN** the same object with `owner` "{{cast:esraa.uid}}" outside its verbatim fields
- **WHEN** the set is checked
- **THEN** the set SHALL be refused naming that placeholder
