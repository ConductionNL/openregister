# platform-collaboration-resources

## ADDED Requirements

### Requirement: Any object may be a collaboration resource (REQ-PCR-001)

The system SHALL implement a collaboration resource provider answering,
for any object, its name, its icon, its link, and whether a named user may
access it. The access answer SHALL be resolved through the object read
path, so a user who may not read the object SHALL NOT see it in a
collection. A schema SHALL declare whether its objects may join
collections, and the default SHALL be that they may not.

#### Scenario: a case joins the project it belongs to

- **GIVEN** a schema declaring that its objects may join collections
- **WHEN** one of its objects is added to a collection
- **THEN** it appears there with its name, icon and link

#### Scenario: a collection does not widen access

- **GIVEN** a collection holding an object a user may not read
- **WHEN** that user opens the collection
- **THEN** the object is not shown to them

#### Scenario: reference data stays out

- **GIVEN** a schema that declares nothing
- **WHEN** a user searches for one of its objects to add
- **THEN** it is not offered

### Requirement: Membership is changed by anyone who may update the object, and is readable from it (REQ-PCR-002)

Adding an object to a collection or removing it SHALL require the right to
update the object, and SHALL be recorded on the audit trail. Reading an
object SHALL be able to return the collections it belongs to and the other
members of those collections that the reader may access.

#### Scenario: the case page can show the folder and the conversation

- **GIVEN** an object in a collection that also holds a folder and a conversation
- **WHEN** the object's collections are read by a user who may access all three
- **THEN** the folder and the conversation are returned

#### Scenario: a reader without update rights cannot change membership

- **GIVEN** a user who may read but not update an object
- **WHEN** they remove it from a collection
- **THEN** the removal is refused
