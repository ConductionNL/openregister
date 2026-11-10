# flow-engine

## ADDED Requirements

### Requirement: A condition is named once and used inside other rules (REQ-RCT-001)

A condition MAY be saved under a name with a description, expressed in the
shared expression vocabulary, and referenced by that name from any rule,
transition guard or field rule. Changing the named condition SHALL change
every rule that references it. Schema save SHALL refuse a reference to a
name that does not exist and a cycle among named conditions, naming the
name. References SHALL be resolvable within an administered depth, and a
reference that cannot be resolved at evaluation time SHALL be a refusal
recorded in the run log, never a pass. The rule inventory SHALL report which
rules use each named condition.

#### Scenario: one correction reaches twenty rules

- **GIVEN** a named condition `spoedeisend` referenced by twenty rules
- **WHEN** the named condition is corrected
- **THEN** all twenty evaluate with the corrected expression

#### Scenario: a cycle is refused at save

- **GIVEN** two named conditions that reference each other
- **WHEN** the schema is saved
- **THEN** the save fails naming both conditions
- @e2e exclude {validator, covered by unit tests}

#### Scenario: an unresolvable reference refuses rather than passes

- **GIVEN** a rule whose named condition cannot be resolved at evaluation time
- **WHEN** the rule is evaluated
- **THEN** the verdict is a refusal
- **AND** the run log names the condition that could not be resolved

#### Scenario: the inventory says who uses it

- **GIVEN** a named condition used by three rules
- **WHEN** the inventory for the schema is read
- **THEN** the three rules are listed against that condition
- @e2e exclude {inventory read, covered by unit tests}

### Requirement: A condition may read the value before the write and the value after it (REQ-RCT-002)

A condition MAY address a property's value before the write as well as its
value after, so that entering a state is distinguishable from being in it. A
rule whose condition requires a prior value SHALL declare that it does, and
attaching such a rule to a trigger that has no prior value SHALL be refused
at schema save naming the rule. The run log SHALL record which of the two
operands decided the verdict.

#### Scenario: entering a status fires once

- **GIVEN** a rule whose condition is that `status` moved from anything to `afgehandeld`
- **WHEN** the object is saved into `afgehandeld` and then saved twice more while in it
- **THEN** the rule fires on the first save only

#### Scenario: being in a status fires every time

- **GIVEN** a rule whose condition reads only the value after the write
- **WHEN** the same three saves happen
- **THEN** the rule fires on all three

#### Scenario: a rule that needs a before value cannot be attached to a create

- **GIVEN** a rule declaring that it requires a prior value, attached to a create trigger
- **WHEN** the schema is saved
- **THEN** the save fails naming the rule
- @e2e exclude {annotation validator, covered by unit tests}

### Requirement: A condition compares a date to now, in calendar or business units (REQ-RCT-003)

A condition MAY compare a date property to the present moment with an
offset expressed in hours, working hours, calendar days or business days.
Business units SHALL resolve against the working calendar that the record
type, unit and instance resolution already selects, and an unresolvable
calendar SHALL be refused at schema save rather than downgraded at
evaluation. The comparison SHALL be compiled into an indexed query over the
objects rather than evaluated per object.

#### Scenario: escalate after three working hours

- **GIVEN** a rule whose condition is that `createdAt` is more than 3 working hours ago, against a calendar working 09:00 to 17:00
- **WHEN** an object created on Friday at 16:30 is evaluated on Monday at 09:30
- **THEN** the condition holds

#### Scenario: the weekend does not count

- **GIVEN** the same rule and object
- **WHEN** it is evaluated on Saturday at 09:30
- **THEN** the condition does not hold
- @e2e exclude {time-dependent, covered by unit tests with a clock fixture}

#### Scenario: a missing calendar is refused at save

- **GIVEN** a rule in business units whose calendar cannot be resolved
- **WHEN** the schema is saved
- **THEN** the save fails naming the calendar
- @e2e exclude {validator, covered by unit tests}

#### Scenario: the sweep is a query, not a loop

- **GIVEN** a relative-time rule over a schema holding a hundred thousand objects
- **WHEN** the rule is evaluated over the schema
- **THEN** the due objects are selected by an indexed comparison
- @e2e exclude {query plan, covered by a performance unit test}
