# flow-engine

## ADDED Requirements

### Requirement: Running a flow is decided per flow, in one place (REQ-FRH-001)

Every path that runs a flow SHALL consult one resolver before the run is
queued or executed. The resolver SHALL permit an administrator, the flow's
owner, and a caller holding the `flow.update` right, and SHALL refuse
everyone else. A flow with no owner SHALL be refused to every caller,
agreeing with the engine's own refusal to dispatch one. The resolver SHALL
fail closed when there is no session and when it cannot reach what it needs
to decide.

#### Scenario: a colleague cannot run a flow that is not theirs

- **GIVEN** a signed-in user in the same organisation as a flow, who does not own it and does not hold `flow.update`
- **WHEN** they call any endpoint that runs it
- **THEN** the run is refused and no run row is created

#### Scenario: the owner may run their own flow

- **GIVEN** the flow's owner, holding only the seeded `flow.run` right
- **WHEN** they run it
- **THEN** it runs

#### Scenario: an unowned flow is refused to everyone

- **GIVEN** an imported flow that nobody has adopted
- **WHEN** an administrator runs it
- **THEN** the run is refused, naming the missing owner
- @e2e exclude {door-level refusal, covered by unit tests}

### Requirement: A declaration says which store it governs (REQ-FRH-002)

An authorization block declared on a schema whose store a subsystem does not
read SHALL state, where it is declared, what it governs and what it does not.

#### Scenario: a reader is not told something untrue by a file

- **GIVEN** `flow_register.json`, whose `flow` schema declares `scope: private`
- **WHEN** a reader looks for what protects a flow RUN
- **THEN** the declaration says that the run path reads the native table and names the resolver that governs it
- @e2e exclude {descriptor content, covered by a unit test reading the shipped file}
