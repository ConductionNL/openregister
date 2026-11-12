# party-model Delta

## ADDED Requirements

### Requirement: A party write needs the update right on the object (REQ-PRM-005)

Giving a party a role on an object, replacing the object's primary party and taking a party off an object SHALL each
require the caller's `update` right on that object, as the object's schema (and a per-object authorization) grants
it. A caller without it SHALL get a 403 and nothing SHALL be written. Reading the parties SHALL need the read right
only.

#### Scenario: a reader cannot link a party

- **GIVEN** an organisation the caller may read but not update
- **WHEN** the caller gives a person a role on it
- **THEN** the answer is 403 and the organisation holds no new party
- @e2e exclude {authorization behaviour, covered by unit tests on the controller}

#### Scenario: a reader cannot unlink a party or replace the primary party

- **GIVEN** an organisation with a main contact, which the caller may read but not update
- **WHEN** the caller takes the main contact off, or names another primary party
- **THEN** each answer is 403 and the organisation's parties are unchanged
- @e2e exclude {authorization behaviour, covered by unit tests on the controller}

#### Scenario: an editor links a party

- **GIVEN** an organisation the caller may update
- **WHEN** the caller gives a person the role `chair` on it
- **THEN** the answer is 201 with the link
- @e2e exclude {authorization behaviour, covered by unit tests on the controller}
