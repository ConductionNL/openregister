# settings-management

## ADDED Requirements

### Requirement: The settings facade writes drafts and seeds defaults from an administered action (REQ-CAD-006)

Every domain handler behind the settings facade SHALL accept a draft write
as well as a live write, so that no domain can be changed outside the
deployment lifecycle once drafting is switched on. Seeding a working
default configuration SHALL be available as an administered action on a
running instance, and SHALL write drafts rather than live values.

#### Scenario: no domain escapes the lifecycle

- **GIVEN** drafting switched on
- **WHEN** a setting in any domain is written
- **THEN** it is written as a draft and the live value is unchanged

#### Scenario: a new instance gets a working vocabulary to review

- **GIVEN** an administrator on a fresh instance
- **WHEN** they run the seed action
- **THEN** the defaults exist as a draft set, ready to review and deploy
