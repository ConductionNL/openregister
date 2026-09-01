## ADDED Requirements

### Requirement: The flow engine has user documentation, and it is accurate

`docs/features/flows.md` SHALL exist and SHALL document, for a flow AUTHOR
(not an engine developer): the canvas and node palette; the three trigger
node types and the full event catalogue; the built-in step and end nodes
with their configuration keys; approvals (`await-signal`) end to end,
including how the waiting person is told (`flow-messaging-nodes`); run
history and per-node run logs; retry and resume; the kill switch; sharing;
and shipping flows with a configuration.

Accuracy SHALL be structural, not aspirational:

- the trigger event list SHALL enumerate exactly the events in
  `EventCatalogService::CATALOG` — sixteen at time of writing — and SHALL be
  regenerated or checked against the catalogue, never hand-maintained into
  drift;
- the node catalogue SHALL name exactly the types the registry registers;
- a feature the engine does not have SHALL NOT be described. Where writing
  the docs exposes a gap between intended and shipped behaviour, the gap is
  filed as an issue and the docs describe what ships.

#### Scenario: The documented event list is the shipped event list

- **GIVEN** `docs/features/flows.md` and `EventCatalogService::CATALOG`
- **WHEN** the documented event ids are compared with the catalogue's
- **THEN** the two sets MUST be identical
- **AND** the comparison is a test or doc-lint step, so an event added to
  the catalogue without a docs update fails visibly
- @e2e exclude a docs-consistency check — covered by a PHPUnit doc-lint test

#### Scenario: The documented node list is the shipped palette

- **GIVEN** the documented step/trigger catalogue and the node registry's
  built-in registrations
- **WHEN** the two lists of `openregister.*` type ids are compared
- **THEN** they MUST be identical
- @e2e exclude same doc-lint mechanism as the event list

### Requirement: The feature index routes flow automation to the flow engine

`docs/features/README.md` SHALL carry a feature-table row for the native
flow engine pointing at `flows.md`. The existing "Workflow Automation" row
SHALL be re-scoped to external-tool integration (n8n, Windmill), and
`workflow-automation.md` SHALL open by directing readers who want built-in
automation to `flows.md`. Neither page SHALL present an external tool as
the way to automate OpenRegister processes — ADR-094 settled that fleet
automation targets or-flow, and ADR-065 makes the engine the fleet's single
one.

#### Scenario: A reader looking for automation lands on the engine

- **GIVEN** a reader starting at the `docs/features/README.md` feature table
- **WHEN** they look for process automation
- **THEN** they MUST find a row for the native flow engine linking to
  `flows.md`
- **AND** the external-tools row MUST describe itself as integration with
  external automation, cross-linking `flows.md`
- @e2e exclude prose review — checked in the change's review, plus a
  doc-lint assertion that `README.md` links `flows.md`

### Requirement: The documentation states the subsystem boundaries

`flows.md` SHALL carry, verbatim in substance, the two boundary statements:

- **Notifications notify; flows orchestrate.** "Whenever X happens, tell Y"
  belongs to the declarative `x-openregister-notifications` annotation
  (ADR-031); a flow's messaging nodes are for sends at a point in a
  process. Each doc section SHALL link the other subsystem's documentation
  at this fork.
- **API calls go through OpenConnector.** A flow calls external APIs
  through `openconnector.source-call` nodes against configured sources
  (ADR-094). The engine ships no native HTTP node, deliberately: source
  configuration is where credentials, base URLs and rate limits live once.

#### Scenario: The fork in the road is signposted

- **GIVEN** a reader deciding between a schema notification annotation and
  a flow with a send node
- **WHEN** they read the messaging section of `flows.md`
- **THEN** it MUST state the boundary rule and link the notification
  documentation
- **AND** the section on calling APIs MUST name OpenConnector sources as
  the path and state that no native HTTP node exists
- @e2e exclude prose review

### Requirement: The trigger cutover is documented as it is, not as it will be

`flows.md` SHALL document the trigger-node cutover honestly, in user terms:
a flow whose graph carries trigger NODES fires from those nodes alone; a
flow with no trigger nodes still fires from its legacy trigger COLUMNS (the
compatibility fallback the flow-engine spec mandates); re-authoring a flow
with trigger nodes switches it to nodes-first matching, including that
deleting a trigger node then genuinely unsubscribes it. The docs SHALL name
the one genuinely blocked case — an unscoped ("any register, any schema")
object trigger cannot be expressed as a trigger node and keeps its column
until scoped — and SHALL NOT describe the columns as gone while any flow
still fires from them.

#### Scenario: An operator can tell which regime a flow is in

- **GIVEN** the cutover section of `flows.md`
- **WHEN** an operator with one converted and one unconverted flow reads it
- **THEN** they MUST be able to determine which of their flows fires from
  nodes and which from columns, and what re-authoring changes
- **AND** the unscoped-object-trigger limitation MUST be stated with its
  workaround (one trigger node per register/schema pair)
- @e2e exclude prose review against the flow-engine spec's cutover
  requirement
