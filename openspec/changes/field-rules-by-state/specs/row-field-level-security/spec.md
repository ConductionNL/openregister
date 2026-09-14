# row-field-level-security

## ADDED Requirements

### Requirement: Field rules by state are enforced on save and published on read

On save the system SHALL refuse with 422 an empty field the object's
resulting state requires for the user, and a changed field that state makes
read only; a hidden field SHALL be stripped on read and refused on write
through the property RBAC path. Every object read SHALL carry
`@self.fieldRules` with the effective `hidden`, `readOnly` and `required`
lists for the current user and state.

#### Scenario: closing without an outcome is refused

- **GIVEN** a lifecycle whose state `closed` requires `outcome` and an open object without one
- **WHEN** a user transitions it to `closed`
- **THEN** the response is 422 naming `outcome` and the object stays `open`
- @e2e exclude {proposal only; task 3.1 adds tests/e2e/ci/field-rules-by-state.spec.ts when the form reads the rules}

#### Scenario: a closed object's decision is read only for handlers

- **GIVEN** state `closed` marks `decision` read only for group `handlers` and a handler
- **WHEN** the handler changes `decision` on a closed object
- **THEN** the response is 422 naming `decision`, and `@self.fieldRules.readOnly` on the read contains `decision`
- @e2e exclude {readOnly path, covered by SaveObject and RenderObject unit tests}

#### Scenario: a hidden field is absent for the role and present for another

- **GIVEN** state `intake` hides `internalNote` for group `frontdesk`
- **WHEN** a front desk user and a handler read the same object
- **THEN** the front desk user's response lacks `internalNote` and the handler's holds it
- @e2e exclude {stripping, covered by PropertyRbacHandler unit tests}

### Requirement: A field rule may be conditional on the object's own data

A `hidden`, `readOnly` or `required` entry MAY carry a condition over the
object's data. The condition operand MAY be any property the schema
declares, including one declared through an extending form, and not only
the lifecycle field or a built-in scalar. The rule SHALL apply only when
its condition holds, and `@self.fieldRules` SHALL report the rules that
apply to this object as it stands, not the rules that could apply.

#### Scenario: a field becomes required because of a value

- **GIVEN** state `open` requiring `motivering` when `bedrag` is above 50000
- **WHEN** an object with `bedrag` of 60000 is saved without `motivering`
- **THEN** the save fails with 422 naming `motivering`

#### Scenario: the same field is not required below the threshold

- **GIVEN** the same rule
- **WHEN** an object with `bedrag` of 400 is saved without `motivering`
- **THEN** the save succeeds
- **AND** `@self.fieldRules.required` does not contain `motivering`

#### Scenario: a condition reads a property an extending form declared

- **GIVEN** a rule whose condition reads a property authored through an extending form
- **WHEN** the object is saved
- **THEN** the condition is evaluated against that property's value
- @e2e exclude {evaluator, covered by unit tests}
