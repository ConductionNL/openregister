# object-lifecycle

## ADDED Requirements

### Requirement: A conditionally required field is enforced on every save

A property MAY carry `x-openregister-required-when` with one condition
`{ field, op, value }` or a list of them, where `op` is one of `eq`, `neq`,
`gt`, `gte`, `lt`, `lte`, `empty` and `notEmpty`. When all its conditions hold
on the object as it will be saved and the property is empty, OpenRegister
SHALL refuse the create or update with 422 naming the property and the
condition, on every write path that dispatches the object create and update
events. The schema validator SHALL refuse an unknown operator or a `field` the
schema does not declare.

#### Scenario: a complaint without a category is refused

- **GIVEN** schema `verzoek` where `complaintCategory` carries `x-openregister-required-when: { "field": "requestType", "op": "eq", "value": "Klacht" }`
- **WHEN** a client that skips the form calls `POST /api/objects/{register}/verzoek` with `requestType: "Klacht"` and no `complaintCategory`
- **THEN** the answer is 422 naming `complaintCategory` and the condition on `requestType`
- **AND** the same payload with a `complaintCategory` is created
- @e2e exclude {specified only; task 2.2 adds the Newman case}

#### Scenario: the rule does not apply when its condition does not hold

- **GIVEN** the same schema
- **WHEN** the client creates a `verzoek` with `requestType: "Vraag"` and no `complaintCategory`
- **THEN** the object is created
- @e2e exclude {specified only; covered by RequiredWhenListenerTest in task 2.1}

#### Scenario: a condition on an undeclared field is refused at schema save

- **GIVEN** an administrator editing schema `verzoek`
- **WHEN** the administrator saves a property with `x-openregister-required-when: { "field": "soort", "op": "eq", "value": "x" }` while the schema has no `soort`
- **THEN** the schema save is refused with 422 naming the property and `soort`
- @e2e exclude {API contract; covered by PropertyValidatorHandlerTest in task 1.2}
