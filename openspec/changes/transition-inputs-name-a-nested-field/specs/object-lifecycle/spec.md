# Object lifecycle

## ADDED Requirements

### Requirement: A transition input MAY name a field inside an object property

A transition's `inputs[].field` SHALL be read as a path: `parent.child` names
the property `child` inside the object property `parent`.

- The payload MAY carry `parent` holding an object; its keys MUST be declared
  children, and an undeclared child MUST be refused naming its dotted path.
  A value under `parent` that is not an object MUST be refused naming `parent`.
- A literal dotted payload key SHALL be read as the nested shape.
- A `required` dotted input SHALL be missing when its parent or the child is
  absent, or the child is an empty string.
- Accepted children SHALL be written into the stored parent one by one, so the
  parent's other stored fields are kept in the same write.
- A parent that is itself declared keeps accepting its whole value.

#### Scenario: The dialog's nested payload is accepted
- **GIVEN** a transition declaring `inputs: [{"field": "feedback.route", "required": true}, {"field": "feedback.note"}]`
- **WHEN** it is applied with `{"feedback": {"route": "mas", "note": "n"}}`
- **THEN** the write MUST carry `feedback.route` and `feedback.note` with the state change
- **AND** fields already stored under `feedback` MUST be kept

#### Scenario: A required child is checked inside its parent
- **GIVEN** the same transition
- **WHEN** it is applied with `{"feedback": {}}`
- **THEN** the call MUST be refused naming `feedback.route` as missing

#### Scenario: An undeclared child is refused by its path
- **GIVEN** the same transition
- **WHEN** it is applied with `{"feedback": {"other": "x"}}`
- **THEN** the call MUST be refused naming `feedback.other` as undeclared
