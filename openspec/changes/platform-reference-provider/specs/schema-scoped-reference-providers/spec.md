# schema-scoped-reference-providers

## ADDED Requirements

### Requirement: Any object URL resolves to a reference card, per reader (REQ-PRP-001)

The system SHALL resolve any object URL the deep link registry can parse
into a reference, for every register and schema, through one provider.
Resolution SHALL apply the reading user's own access: a user who may not
read the object SHALL receive the plain link with no metadata.

#### Scenario: a zaak pasted in Talk renders as a card

- **GIVEN** a conversation member who may read an object
- **WHEN** its URL is posted
- **THEN** a card is rendered for that object

#### Scenario: a reader without access sees only the link

- **GIVEN** a conversation member who may not read the object
- **WHEN** the same URL is posted
- **THEN** they see the plain link and no metadata

### Requirement: A schema declares what its reference card shows (REQ-PRP-002)

A schema MAY declare a card: a title property, up to three summary
properties, an icon and an optional image property. A schema declaring
none SHALL render the object's title and its schema name.

#### Scenario: a declared card shows the fields that matter

- **GIVEN** a schema declaring a title and two summary properties
- **WHEN** one of its objects is referenced
- **THEN** the card shows those three values

#### Scenario: an undeclared schema is dull, not wrong

- **GIVEN** a schema declaring no card
- **WHEN** one of its objects is referenced
- **THEN** the card shows the object's title and its schema name
