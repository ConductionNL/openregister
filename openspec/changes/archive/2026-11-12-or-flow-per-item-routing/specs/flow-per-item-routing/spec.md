## ADDED Requirements

### Requirement: A step can route each item to its own output (REQ-PIR-001)

The flow engine SHALL deliver a produced item that names an output
(`FlowItems::OUTPUT`) only to the output place with that name, and SHALL
broadcast an item that names no output to every output place. The output tag
SHALL be removed as the item is delivered. A step whose items carry no tag SHALL
distribute exactly as it did before this change.

#### Scenario: A router splits its items across branches

- **GIVEN** a step routing items to outputs "high" and "low"
- **WHEN** it tags some items "high" and the rest "low"
- **THEN** the "high" branch receives only the "high" items
- **AND** the "low" branch receives only the "low" items
- **AND** neither branch's items still carry the routing tag

#### Scenario: An untagged split still broadcasts to every branch

- **GIVEN** a fork whose items carry no output tag
- **WHEN** it fires
- **THEN** every output receives every item

### Requirement: The router tags items by rule (REQ-PIR-002)

OpenRegister SHALL provide a `openregister.route` node that tags each item for
the output of the first rule whose condition holds, falling back to a configured
default, and dropping an item that matches neither. A router with no rules SHALL
be refused at save time.

#### Scenario: The first matching rule wins

- **GIVEN** an item that satisfies two rules
- **WHEN** it is routed
- **THEN** it is tagged for the earlier rule's output

#### Scenario: An unmatched item with no default is dropped

- **GIVEN** an item matching no rule and no default is set
- **WHEN** it is routed
- **THEN** it is not emitted

@e2e exclude engine + node — covered by FlowEngineTest and RouterNodeTest and
live-verified on 8080 (real dispatcher split 3 items 1/2 across two branches);
the builder affordance for router outputs is a separate change
