---
kind: code
---

# Proposal: flow-bpmn-interchange

## Summary

Add BPMN 2.0 XML import and export for flows. Export serialises a flow's
node/edge graph to a standards-conformant `bpmn:process` with diagram
interchange (BPMN DI), so a flow can be opened in Camunda Modeler,
bpmn.io, or handed to an auditor. Import reads a BPMN file into a flow
document, accepting a documented subset and producing a **lossy-mapping
report** that names every construct it could not express — never importing
silently less than the file said.

## Why

Three pressures, none served today:

1. **Procurement and audit.** Dutch government buyers ask "can we get our
   processes out?" BPMN 2.0 is the interchange format that question means.
   ADR-065 chose symfony/workflow over a BPMN engine deliberately, and its
   Decision 7 records the consequence: "XML interchange is ours to write on
   every path" — no maintained PHP package supplies BPMN XML ↔ objects. The
   decision to own the serializer was made a year ago; this change schedules
   the work.
2. **Migration in.** Organisations arriving from Camunda/Zeebe-adjacent
   tooling have BPMN files. Today the answer is "redraw it by hand on the
   canvas"; with import the answer is "import it and read the report of what
   needs attention".
3. **Documentation out.** A flow's only current representations are the
   canvas and raw JSON. An exported BPMN diagram is a process picture every
   BPM-literate reader already knows how to read.

The engine's model makes this tractable: since `or-flow-action-nodes`, a flow
IS a node/edge graph with behaviour on typed nodes — structurally the same
shape as a BPMN process (flow nodes joined by sequence flows). The mapping is
mostly direct; the design documents the corners where it is not.

## What Changes

- **New `FlowBpmnExporter` and `FlowBpmnImporter`** under
  `lib/Service/Flow/Bpmn/`, plus `GET /api/flows/{id}/bpmn` and
  `POST /api/flows/import/bpmn` on `FlowController` (guarded by the existing
  `flow.create` / read rights — export requires read, import creates).
- **Export mapping** (full table in `design.md`):
  - `openregister.trigger-manual` → none start event; `trigger-schedule` →
    timer start event (cron in a `timerEventDefinition`); `trigger-object` →
    conditional start event carrying the event/register/schema subject;
  - `switch` / `route` → exclusive gateway with condition expressions on the
    outgoing sequence flows;
  - a node with multiple outgoing edges → implicit parallel split, exported
    as a parallel gateway; a `join: true` node → converging parallel gateway;
  - `await-signal` → intermediate message catch event; `wait` → intermediate
    timer catch event;
  - `sub-flow` → call activity referencing the child flow;
  - `end` → end event (error end event when `config.error` is true);
  - every other step → `bpmn:serviceTask`, with the node's `type` and
    `config` carried in `extensionElements` under an `openregister`
    namespace so a round-trip through export/import is lossless for our own
    files.
- **Import** accepts a schema-validated subset (the constructs above, read in
  reverse) and produces a lossy-mapping report: every element it mapped
  approximately, dropped, or refused, each with the BPMN element id and what
  the author should do about it. Import NEVER guesses behaviour: a
  `serviceTask` with no openregister extension imports as a node with no
  `type`, which the builder and preflight already refuse/flag by name — the
  imported flow is honest about being unfinished.
- **Diagram interchange.** Export writes BPMN DI from the canvas positions;
  import reads DI when present and auto-layouts when absent.

## What does NOT change

- The execution engine. BPMN is an interchange FORMAT here, never an
  execution semantic: symfony/workflow remains the core (ADR-065 Decision 2),
  and no imported construct executes differently from the same graph drawn
  on the canvas.
- The stored flow document. BPMN is produced and consumed at the boundary;
  nothing BPMN-shaped is persisted.
- DMN/CMMN. Out of scope (DMN interchange is tracked by openregister#466 per
  ADR-065; CMMN is deferred by the same ADR).

## Impact

- **Affected specs**: new capability `flow-bpmn-interchange`
- **Affected code**: new `lib/Service/Flow/Bpmn/`, `FlowController`,
  `appinfo/routes.php`; UI export/import buttons in the flow detail surface
  (nextcloud-vue follow-up)
- **Affected apps**: none — consumers get the endpoints for free
- **ADRs**: ADR-065 Decision 7 (own serializer — this implements it),
  Decision 2 (engine unchanged — this respects it)

## Capabilities

### New Capabilities
- `flow-bpmn-interchange` — BPMN 2.0 XML export and subset import with a
  lossy-mapping report
