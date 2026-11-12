# Design: flow-bpmn-interchange

## Decision 1: own serializer over a schema-validated subset

ADR-065 Decision 7 already surveyed the field: "No package in any category
supplies BPMN/DMN/CMMN XML ↔ PHP objects. `phpmentors/workflower` has the
ecosystem's best BPMN importer but is abandoned (released 2019-12-03, last
push 2023-08-18) — read it as a reference, do not depend on it."

Options:

1. **Depend on workflower anyway.** Rejected — an abandoned dependency at an
   interchange boundary is a security surface nobody patches, and the fleet
   already has a rule against agent-vendored/unmaintained libraries.
2. **Full BPMN 2.0 metamodel in PHP.** Rejected — the metamodel is enormous
   (choreographies, conversations, compensation) and the engine can express
   a fraction of it. A full metamodel would be 90% dead code whose only
   caller is the XSD.
3. **Own serializer over a declared subset, XSD-validated at the boundary**
   (chosen). `DOMDocument` + `XMLReader` against the OMG XSD (vendored,
   version-pinned — the schema files are stable artifacts of the standard,
   not code). The subset is exactly what the mapping table names, and
   everything outside it flows into the mapping report by construction:
   the importer walks known elements and files each unknown one as
   `refused`, so the subset boundary and the report are the same code path
   and cannot drift apart. Workflower is consulted as a reference for XSD
   corner cases, per the ADR.

## Decision 2: the mapping table and its asymmetries

Export is total (every flow exports); import is partial (BPMN is bigger than
the engine). The table is symmetric where it can be and honest where it
cannot:

| Flow construct | BPMN (export) | Import accepts additionally |
| --- | --- | --- |
| `trigger-manual` | none start event | — |
| `trigger-schedule` | timer start event | ISO-8601 cycles that map to cron; others → `approximated` |
| `trigger-object` | conditional start event | message start event → `await-signal`-style note, `approximated` |
| `switch` / `route` | exclusive gateway + flow conditions | inclusive gateway with default flow → `route`, `mapped` |
| multi-out node | parallel gateway (diverging) | explicit diverging parallel gateway → plain multi-edge node |
| `join: true` | parallel gateway (converging) | converging parallel/exclusive gateway |
| `await-signal` | intermediate message catch | `userTask` → `await-signal`, `mapped` (a user task IS "wait for a person") |
| `wait` | intermediate timer catch | — |
| `sub-flow` | call activity | call activity naming an unknown flow → node created, report entry |
| `end` (`error`) | (error) end event | terminate end event → `end`, `approximated` (the engine ends the RUN either way) |
| other steps | `serviceTask` + extensionElements | `scriptTask`/`sendTask`/`task` → typeless node, report entry |

Not importable, always `refused`: event sub-processes, boundary events other
than the timer approximation, compensation, transactions, lanes/pools beyond
the first participant, multiple processes per file.

**Why refusal drops the element instead of failing the import by default:**
the primary import user is migrating a diagram they will finish by hand on
the canvas. A hard fail on the first exotic construct gives them nothing;
a flow with a named gap plus a report gives them 90% of the redraw. The run
guard is what keeps this safe — a flow with typeless nodes cannot run, so an
incomplete import can never silently execute less than the diagram said. The
`strict` parameter exists for the automation case where a human is not going
to read the report.

## Decision 3: extension elements make our own round-trip exact

BPMN cannot natively carry `openregister.set-fields`'s config. The standard's
own answer is `extensionElements` with a foreign namespace, which conformant
tools must preserve and may ignore. Export writes `type` + `config` (JSON,
CDATA) there; import prefers it over any inference. This gives three tiers of
fidelity, each honest:

1. our file → our instance: exact (asserted by the round-trip test);
2. our file → Camunda and back untouched: exact (extensions preserved);
3. foreign file → our instance: the mapping report is the contract.

## Decision 4: where the code sits

`lib/Service/Flow/Bpmn/{FlowBpmnExporter,FlowBpmnImporter,BpmnMappingReport}` —
a sibling namespace to `Nodes/`, not inside the engine services, because
nothing in the run path may reach into it (the "never an execution semantic"
requirement is enforced by dependency direction: `Bpmn\*` depends on the flow
store and node registry, nothing depends on `Bpmn\*`). The registry dependency
is what lets the importer ask "is this a known type" and the exporter ask for
roles without hard-coding the node list a second time.
