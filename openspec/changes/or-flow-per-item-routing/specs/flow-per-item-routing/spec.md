## ADDED Requirements

### Requirement: A choice point can route each item to its matching output (REQ-PIR-001)

The flow engine SHALL support routing each item of a step's output to the
`to` place named by that item, so a Switch/Filter splits its input across its
outputs rather than sending the whole batch down one branch. An item that names
no output SHALL be delivered to every `to` place, preserving today's behaviour
for every existing node.

#### Scenario: A switch splits its items across branches

- **GIVEN** a switch whose items are tagged for output "a" or output "b"
- **WHEN** the step runs
- **THEN** the "a" branch receives only the "a" items
- **AND** the "b" branch receives only the "b" items

#### Scenario: An untagged multi-output step still copies to all

- **GIVEN** a step with several outputs whose items carry no output tag
- **WHEN** the step runs
- **THEN** every output receives the full item list

> NOTE: Design-only change. This spec records the intended contract; the
> implementation is a follow-up (see proposal, option 2).
