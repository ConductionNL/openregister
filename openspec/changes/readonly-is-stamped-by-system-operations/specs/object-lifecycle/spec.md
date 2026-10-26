## ADDED Requirements

### Requirement: A system operation may stamp a readOnly property

On update, a changed value of a `readOnly: true` property SHALL be refused, except inside a `SystemOperationContext`.
`immutable: true` SHALL be enforced inside a `SystemOperationContext` too.

#### Scenario: A client update to a readOnly property is refused
- **GIVEN** a case whose `paymentState` is `readOnly: true` and stored as null
- **WHEN** a user updates the case with `paymentState: "paid"`
- **THEN** the update is refused with a validation error naming `paymentState`

#### Scenario: A system operation stamps it
- **WHEN** the same update runs inside `SystemOperationContext::run()`
- **THEN** the update is not refused
