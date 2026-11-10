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
- @e2e exclude {asserted in tests/Unit/Service/Lifecycle/LifecycleStateFieldValidationTest.php::testAnUnknownFieldIsRefusedAtSchemaSave; the HTTP half rides tests/e2e/ci/field-rules-by-state.spec.ts}

### Requirement: A state declares entry and exit conditions

`x-openregister-lifecycle.states.<state>` MAY declare `entry` and `exit`
conditions over the object's data, grouped with and or or. An entry
condition SHALL be evaluated on every path into the state and an exit
condition on every path out of it, whichever transition is used. A refusal
SHALL name the clause that failed, in the schema author's declared
message where one is given. Schema-save validation SHALL refuse a
condition naming a property the schema does not declare.

#### Scenario: one rule guards every path into a state

- **GIVEN** a state `besloten` whose entry condition requires `besluit` to be present, reachable by three transitions
- **WHEN** an object without `besluit` is moved into it by any of the three
- **THEN** the move is refused and the refusal names `besluit`

#### Scenario: the failing clause is named

- **GIVEN** an entry condition grouping two clauses with and
- **WHEN** the second clause is false
- **THEN** the refusal names the second clause
- @e2e exclude {asserted in tests/Unit/Service/Lifecycle/StateConditionEvaluatorTest.php::testTheFailingClauseOfAnAndIsNamed}

#### Scenario: a condition on an undeclared property is refused at schema save

- **GIVEN** an exit condition naming a property the schema does not declare
- **WHEN** the schema is saved
- **THEN** the save fails with 422 naming the property
- @e2e exclude {asserted in tests/Unit/Service/Lifecycle/LifecycleStateFieldValidationTest.php::testAConditionOnAnUndeclaredPropertyIsRefused}
