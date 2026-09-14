# object-interactions

## ADDED Requirements

### Requirement: Each claiming app has its own comments entity collection (REQ-PCE-001)

For each app that claims a (register, schema) pair, the system SHALL
register a comments entity collection named for that app, from the same
listener that registers `openregister`, with a validation closure that
accepts only objects of that app's claimed pairs. The `openregister`
collection SHALL stay registered, and comments stored under it SHALL keep
resolving.

#### Scenario: a comment on a zaak is filed under the zaak app

- **GIVEN** an app claiming a register and schema
- **WHEN** a comment is created on one of its objects through its collection
- **THEN** it is accepted and attributed to that app

#### Scenario: a foreign object is refused by an app's collection

- **GIVEN** the same collection
- **WHEN** a comment names an object outside that app's claimed pairs
- **THEN** the validation closure refuses it

#### Scenario: existing comments still resolve

- **GIVEN** comments stored under the `openregister` collection
- **WHEN** they are read
- **THEN** they resolve as before

### Requirement: A collection carries a display name and does not change visibility (REQ-PCE-002)

Each registered collection SHALL carry a display name and an icon for
platform surfaces that list comment sources. A comment's readability SHALL
remain exactly the readability of the object it is on, whichever
collection it was filed under.

#### Scenario: the source is named for a person, not for a machine

- **GIVEN** a platform surface listing comment sources
- **WHEN** it renders an app's collection
- **THEN** the app's display name and icon are shown

#### Scenario: the collection grants nothing

- **GIVEN** a user who may not read an object
- **WHEN** they read comments through that app's collection
- **THEN** they see none
