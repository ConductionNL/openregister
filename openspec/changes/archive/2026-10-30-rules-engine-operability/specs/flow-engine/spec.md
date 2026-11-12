# flow-engine

## ADDED Requirements

### Requirement: Every rule that can act on a schema is listed in evaluation order (REQ-REO-001)

The system SHALL publish, per schema, every rule that can act on its
objects: lifecycle conditions, state field blocks, declared calculations
and the flows its objects trigger. The list SHALL be in the order the save
pipeline evaluates them, and each entry SHALL name its source annotation,
its last run, its last error and whether it is enabled. The list SHALL be
derived from the schema and the flow definitions and SHALL NOT be stored
separately. A rule SHALL be switched off and on from this surface, and the
switch SHALL be recorded in the audit trail with the actor.

#### Scenario: an administrator reads what will run

- **GIVEN** a schema with one lifecycle condition, one state field block and two triggering flows
- **WHEN** the rule inventory for that schema is read
- **THEN** four entries are returned in evaluation order
- **AND** each names its source annotation and whether it is enabled

#### Scenario: a removed annotation leaves the inventory

- **GIVEN** the same schema with the lifecycle condition removed and saved
- **WHEN** the inventory is read again
- **THEN** three entries are returned and the condition is absent
- @e2e exclude {projection behaviour, covered by unit tests}

#### Scenario: switching a rule off is an audited act

- **GIVEN** an enabled calculation on a schema
- **WHEN** an administrator disables it from the inventory
- **THEN** the entry reports it disabled, the calculation does not run on the next save
- **AND** an audit trail entry names the actor and the rule

### Requirement: A rule evaluation records the operand that decided it (REQ-REO-002)

Every rule evaluation SHALL record the rule, the object, the verdict
(`fired`, `no_match`, `refused`, `error`) and, when the verdict is
`no_match` or `refused`, the first operand that decided it with the value
it read. The log SHALL be readable per rule with filters on verdict and
period. A rule with no run inside the configured window SHALL be reported
as such in the inventory. The log SHALL be pruned by the daily retention
pass under its own configured period, and the inventory SHALL keep the
last run and the last error after the detail rows are pruned.

#### Scenario: a rule that did not fire says why

- **GIVEN** a lifecycle condition requiring `bedrag` above 500 and an object whose `bedrag` is 120
- **WHEN** the object is saved and the rule is evaluated
- **THEN** the run log records verdict `no_match` for that rule and object
- **AND** the entry names `bedrag` and the value 120

#### Scenario: a rule that has not fired for ninety days is visible

- **GIVEN** a rule whose last run is older than the configured window
- **WHEN** the inventory for its schema is read
- **THEN** that entry is flagged as not having run inside the window
- @e2e exclude {time-dependent, covered by unit tests with a clock fixture}

#### Scenario: pruning keeps the summary and drops the detail

- **GIVEN** a rule with run log rows older than the configured retention period
- **WHEN** the daily retention pass runs
- **THEN** those rows are removed
- **AND** the inventory still reports the rule's last run and last error
- @e2e exclude {background job, covered by unit tests}

### Requirement: A run is bounded, simulated and replayable (REQ-REO-003)

A rule MAY declare `maxObjects` for one run. The system SHALL count the
objects a run would touch before the first write and SHALL refuse the run
as a whole above the ceiling, naming the rule and the count, leaving no
partial mutation. The system SHALL evaluate a rule against a named object
or a stored sample payload without committing, returning the verdict and
the writes it would have made, and SHALL NOT require the rule to be saved
first. The system SHALL apply a rule to existing objects as a background
job with a preview and a per-object outcome, under the same ceiling.

#### Scenario: a runaway rule writes nothing

- **GIVEN** a rule with `maxObjects: 100` and a selection matching 4,000 objects
- **WHEN** the rule runs
- **THEN** the run is refused, the refusal names the rule and 4,000
- **AND** no object was modified

#### Scenario: a rule is tried before it is saved

- **GIVEN** an unsaved rule and a sample payload
- **WHEN** it is evaluated with `commit: false`
- **THEN** the response carries the verdict and the writes it would make
- **AND** no object and no schema were changed

#### Scenario: a replay reports per object

- **GIVEN** a saved rule and a selection of 30 existing objects
- **WHEN** the replay job runs
- **THEN** each object has an outcome of applied, skipped with a reason, or failed with an error
- @e2e exclude {background job, covered by the bulk-action-jobs e2e and unit tests}
