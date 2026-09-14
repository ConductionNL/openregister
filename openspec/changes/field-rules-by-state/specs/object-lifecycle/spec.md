# object-lifecycle

## ADDED Requirements

### Requirement: A lifecycle state declares hidden, read-only and required fields per role

`x-openregister-lifecycle.states.<state>.fields` MAY declare `hidden`,
`readOnly` and `required` lists of `{fields, groups}`. Schema-save
validation SHALL refuse a field the schema does not declare, a state the
lifecycle does not declare, and a transition `inputs` entry naming a field
`hidden` in the target state.

#### Scenario: an unknown field is refused at schema save

- **GIVEN** a lifecycle whose state `open` requires field `outcome` and a schema without `outcome`
- **WHEN** the schema is saved
- **THEN** the save fails with 422 naming `outcome` and `open`
- @e2e exclude {validator, covered by unit tests}
