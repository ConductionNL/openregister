# platform-share-provider

## ADDED Requirements

### Requirement: An object share is a Nextcloud share (REQ-PSH-001)

The system SHALL register a share provider with the platform share
manager, so that a share of an object appears in the recipient's shared
with you, is manageable from the platform's sharing surfaces, and carries
the platform's expiry and note fields. The provider SHALL read and write
the existing object share model and SHALL NOT keep a store of its own. A
schema SHALL declare whether its objects are shareable this way.

#### Scenario: a shared case appears where shares appear

- **GIVEN** a schema declaring its objects shareable and an object shared with a colleague
- **WHEN** the colleague opens their shares
- **THEN** the object is listed

#### Scenario: one model, one answer

- **GIVEN** a share created through the platform surface
- **WHEN** the object's own share list is read
- **THEN** the same share is there, once

#### Scenario: an opted-out schema is not offered

- **GIVEN** a schema declaring nothing
- **WHEN** a user opens the platform sharing surface for one of its objects
- **THEN** sharing is not offered

### Requirement: Permission bits map explicitly and an unmapped bit is refused (REQ-PSH-002)

The platform's permission bits SHALL map explicitly onto object verbs. A
bit with no meaning for an object SHALL be refused, naming the bit, rather
than being accepted and ignored.

#### Scenario: a read share grants reading and nothing else

- **GIVEN** a share created with the read bit
- **WHEN** the recipient attempts an update
- **THEN** it is refused

#### Scenario: a meaningless bit is refused loudly

- **GIVEN** a share request carrying a bit that maps to no object verb
- **WHEN** it is created
- **THEN** it is refused, naming the bit
