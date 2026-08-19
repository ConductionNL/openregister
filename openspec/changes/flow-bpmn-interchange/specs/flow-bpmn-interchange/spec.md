## ADDED Requirements

### Requirement: A flow exports to conformant BPMN 2.0 XML

`GET /api/flows/{id}/bpmn` SHALL return the flow serialised as a BPMN 2.0 XML
document containing exactly one `bpmn:process`, validating against the OMG
BPMN 2.0 XSD. The caller SHALL need read access to the flow and nothing more —
export is a read.

The mapping SHALL be:

- `openregister.trigger-manual` → none start event;
- `openregister.trigger-schedule` → timer start event whose
  `timerEventDefinition` carries the cron expression;
- `openregister.trigger-object` → conditional start event carrying the
  event/register/schema subject in its condition;
- `openregister.switch` and `openregister.route` → exclusive gateway, with
  each branch's condition on its outgoing `sequenceFlow`;
- a node with several outgoing edges → diverging parallel gateway (that is
  what the engine's lowering does with it);
- a node declaring `join: true` → converging parallel gateway;
- `openregister.await-signal` → intermediate message catch event;
- `openregister.wait` → intermediate timer catch event;
- `openregister.sub-flow` → call activity naming the child flow;
- `openregister.end` → end event, or error end event when the node's config
  sets `error`;
- every other node → `bpmn:serviceTask`.

Every exported element SHALL carry the node's `type` and `config` in
`extensionElements` under a declared `openregister` XML namespace, so that a
file exported here and imported here reproduces the flow exactly. A consumer
that ignores extension elements — which the BPMN spec requires consumers to
tolerate — still sees the correct process structure.

The export SHALL include BPMN DI (`bpmndi:BPMNDiagram`) derived from the
canvas positions, so the file opens in Camunda Modeler / bpmn.io looking like
the canvas, not as an unlaid-out graph.

#### Scenario: An exported flow validates and round-trips

- **GIVEN** a flow using a trigger, a switch, a join, a sub-flow and an end
- **WHEN** it is exported and the result imported into the same instance
- **THEN** the exported XML MUST validate against the BPMN 2.0 XSD
- **AND** the imported flow's nodes and edges MUST be semantically identical
  to the original (same types, configs, joins and wiring; canvas positions
  preserved via DI)
- @e2e exclude covered by a PHPUnit round-trip test asserting on the document,
  with XSD validation in the same test

#### Scenario: A failing end is an error end event

- **GIVEN** a flow whose end node carries `config.error: true`
- **WHEN** it is exported
- **THEN** that node MUST serialise as an error end event, not a plain one
- @e2e exclude covered by exporter unit tests

#### Scenario: Export requires only read access

- **GIVEN** a user who can read but not edit a flow shared with them
- **WHEN** they request the BPMN export
- **THEN** it MUST succeed
- **AND** a user without read access MUST receive the same refusal the flow
  read endpoint gives
- @e2e exclude covered by controller tests against the rights matrix

### Requirement: BPMN import accepts a documented subset and reports every loss

`POST /api/flows/import/bpmn` SHALL create a flow from a BPMN 2.0 file. It is
guarded by `flow.create`. The importer SHALL accept the constructs the
exporter emits (read in reverse) and SHALL handle everything else in exactly
one of three declared ways, each landing in the **mapping report** returned
alongside the created flow:

- **mapped** — constructs with a faithful equivalent (e.g. a `userTask`
  becomes `openregister.await-signal`; an inclusive gateway with a default
  flow becomes `openregister.route`); reported so the author can confirm the
  reading;
- **approximated** — constructs imported with reduced semantics (e.g. a
  boundary timer event becomes a note on the node it was attached to, since
  the engine has no boundary events); the report SHALL say what was lost;
- **refused** — constructs with no honest mapping (event sub-processes,
  compensation, transactions, multiple `bpmn:process` elements in one file,
  choreography/collaboration content). A refused construct SHALL NOT abort
  the whole import by default: the element is dropped, the report names it as
  refused, and the resulting flow is left visibly unfinished at that point. A
  `strict=true` request parameter SHALL turn any refusal into a failed import
  with no flow created.

Every report entry SHALL carry the BPMN element id, its element kind, the
verdict (`mapped` / `approximated` / `refused`), and a human sentence saying
what to do. An import that loses something and says nothing is the failure
mode this requirement exists to prevent.

A `serviceTask` without openregister extension elements SHALL import as a
node with an empty `type`. The engine already refuses to RUN such a flow by
name; the import report SHALL additionally list each such node as needing a
type assigned. Import SHALL NOT guess a type from the task's name.

The importer SHALL validate the file against the BPMN 2.0 XSD before mapping,
and refuse a non-validating file naming the first violation — a mapping
report over a malformed document would attribute XML problems to process
constructs.

#### Scenario: An unsupported construct is named, not silently dropped

- **GIVEN** a BPMN file containing an event sub-process
- **WHEN** it is imported without `strict`
- **THEN** a flow MUST be created without that construct
- **AND** the report MUST carry a `refused` entry naming the element id and
  kind
- **AND** with `strict=true` the same file MUST create no flow and return the
  same entries as errors
- @e2e exclude covered by importer unit tests over fixture files

#### Scenario: A task with no engine type imports honestly

- **GIVEN** a BPMN file authored in Camunda Modeler whose `serviceTask` has
  no openregister extension elements
- **WHEN** it is imported
- **THEN** the created node MUST have no `type`
- **AND** the report MUST list it as needing a type
- **AND** running the flow MUST be refused exactly as for any typeless node
- @e2e exclude covered by importer unit tests

#### Scenario: Diagram positions survive, and absence is laid out

- **GIVEN** one file with BPMN DI and one without
- **WHEN** both are imported
- **THEN** the first flow's nodes MUST sit at the DI positions
- **AND** the second MUST receive an automatic layout with no two nodes
  overlapping, rather than a pile at the origin
- @e2e exclude covered by importer unit tests asserting on stored positions

#### Scenario: An invalid file is refused before mapping

- **GIVEN** a file that is XML but does not validate against the BPMN 2.0 XSD
- **WHEN** it is imported
- **THEN** no flow MUST be created
- **AND** the response MUST name the first schema violation
- @e2e exclude covered by importer unit tests

### Requirement: BPMN is an interchange boundary, never an execution semantic

The engine SHALL NOT execute BPMN. An imported flow SHALL be stored as an
ordinary flow document and SHALL behave identically to the same graph drawn
by hand; export SHALL read the stored document and SHALL persist nothing
BPMN-shaped. No run-time code path SHALL depend on whether a flow was ever
imported or exported.

This keeps ADR-065 intact: symfony/workflow is the execution core, and BPMN
support is a serializer at the API boundary — the "ours to write" XML
interchange of Decision 7, not a second engine.

#### Scenario: An imported flow is indistinguishable at run time

- **GIVEN** a flow imported from BPMN and the identical flow drawn by hand
- **WHEN** both are lowered and run against the same input
- **THEN** their definitions MUST be identical
- **AND** their run logs MUST record the same steps
- @e2e exclude engine-internal — covered by a definition-equality unit test
