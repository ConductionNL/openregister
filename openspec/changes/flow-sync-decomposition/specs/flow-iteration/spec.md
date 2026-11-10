# flow-iteration

## ADDED Requirements

### Requirement: A loop is a declared region, not a drawn cycle

The engine SHALL provide a node type `openregister.iterate` that owns a `source`
step and an ordered `body` chain, and runs the body once per batch the source
produces.

Loop membership SHALL be declared data, not inferred from graph topology. A
client SHALL be able to determine which steps belong to a loop without tracing
edges, so an authoring surface can render the loop as a region rather than as an
edge that happens to point backwards.

#### Scenario: Membership is readable without tracing
- **WHEN** a flow containing an iterate step is read
- **THEN** the steps inside the loop are listed on the loop itself, and no edge direction has to be interpreted to know which steps repeat.

#### Scenario: The body runs once per batch
- **WHEN** a source produces three batches and the body is a single step
- **THEN** that step runs three times, once per batch.

#### Scenario: The whole body chain runs, in order
- **WHEN** a loop body declares three steps
- **THEN** all three run per iteration, in the declared order.

### Requirement: Iteration terminates on an exhausted source

A loop SHALL stop when its source produces no items. This SHALL be the only
termination signal, so that pagination requires no separate concept — a page
past the end is an empty batch.

The source SHALL receive its iteration index, so it can request the correct page.

#### Scenario: An exhausted source ends the loop
- **WHEN** a source returns items for pages 0..2 and nothing for page 3
- **THEN** the loop ends after page 2 and the body ran exactly three times.

#### Scenario: An immediately-empty source runs the body not at all
- **WHEN** a source returns nothing on its first call
- **THEN** the body does not run, and the step completes with no items.

#### Scenario: The source can page
- **WHEN** a source is called across several iterations
- **THEN** each call receives an iteration index, increasing from zero.

### Requirement: Items accumulate across iterations

A loop SHALL return every item produced across all its iterations, not only
those of the final pass.

Returning only the last batch would discard all earlier work while still
reporting the step as completed — a silent loss indistinguishable from a source
that only ever had one page.

#### Scenario: Every page survives
- **WHEN** a loop runs over three pages of one item each
- **THEN** the step returns three items.

### Requirement: A loop that cannot converge fails

Every loop SHALL carry an iteration limit, defaulting to a bounded value rather
than to unlimited. On reaching the limit with the source still producing, the run
SHALL fail by default, naming the limit.

A loop MAY declare that it stops and keeps what it gathered instead, but this
SHALL be an explicit choice. Silently stopping SHALL NOT be the default: a
truncated result that reports success is indistinguishable from a complete one.

#### Scenario: Overrun fails by default
- **WHEN** a source never runs out and the loop declares a limit of five
- **THEN** the run fails with a message naming the limit, rather than ending quietly.

#### Scenario: Stopping is available but must be chosen
- **WHEN** a loop declares that it stops on limit
- **THEN** it returns the items gathered so far and the run continues.

### Requirement: An unusable loop is refused at save time

A loop with no source, an empty body, a body step without a type, or a
non-positive limit SHALL be refused when the flow is saved.

Save-time refusal is required rather than run-time detection because a loop that
cannot terminate pays its cost in side effects: by the time execution notices,
the body has already written whatever it writes, as many times as it managed.

#### Scenario: A sourceless loop is refused
- **WHEN** a flow is saved with an iterate step that declares no source
- **THEN** the save is refused with a message naming what is missing.

#### Scenario: An empty body is refused
- **WHEN** an iterate step declares no steps to repeat
- **THEN** the save is refused.

#### Scenario: A non-positive limit is refused
- **WHEN** an iterate step declares a limit of zero
- **THEN** the save is refused.

### Requirement: A corrected node id migrates its stored references

When a node id is corrected, stored flow definitions SHALL be migrated to the new
id, and the old id SHALL remain resolvable for one release.

A node id is a reference the system writes into a flow definition, unlike an
identifier a person typed into a template — so it can be corrected and the data
rewritten, rather than the wrong name being kept indefinitely. Resolution through
the compatibility alias SHALL be logged, so the number of definitions still
carrying the old id is observable rather than assumed to be zero.

#### Scenario: Stored definitions are rewritten
- **WHEN** the instance upgrades past the rename
- **THEN** every stored flow referencing the old id references the new one, and the count of updated definitions is reported.

#### Scenario: An old export still imports
- **WHEN** a flow exported before the rename is imported after it
- **THEN** its steps resolve through the alias rather than failing as an unknown node type, and the resolution is logged.
