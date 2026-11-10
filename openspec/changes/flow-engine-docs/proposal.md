---
kind: code
---

# Proposal: flow-engine-docs

## Summary

Write the user-facing documentation for the flow engine: a new
`docs/features/flows.md` covering the canvas, triggers, steps, approvals,
run history, retry/resume, the kill switch, sharing and shipping — plus the
corrections to `docs/features/README.md`, whose feature table still tells
users that workflow automation in OpenRegister means n8n/Windmill.

## Why

The fleet's single flow engine (ADR-065) has zero user documentation. The
engine has a canvas, nineteen node types, a sixteen-event trigger catalogue,
approval suspension, run history with per-node logs, retry/resume, and
sharing — and a user searching `docs/features/` finds none of it. Worse than
absence, the docs that DO mention the word point the wrong way:
`docs/features/README.md` maps "Workflow Automation" to
`workflow-automation.md` with the note "n8n, Windmill, BPMN" — the
EXTERNAL-automation story that predates the native engine, presented as the
whole story. ADR-094 settled that editorial automation targets or-flow, not
n8n; the README still recommends what the ADR retired for this purpose.

Undocumented features fail in a specific way here: the config panes are raw
JSON today (`flow-node-config-forms` fixes that in parallel), so
documentation is currently the ONLY way an author can learn a node's keys —
and there is none. The two changes attack the same gap from both ends.

## What Changes

- **New `docs/features/flows.md`** — structure in `tasks.md`. Every factual
  claim grounded in the engine as shipped: the sixteen trigger events from
  `EventCatalogService::CATALOG`, the actual node list from the registry,
  the actual sharing semantics (a shared flow can be READ and RUN, never
  edited), the actual shipping mechanism (`x-openregister-flows` — flows
  shipped inside a schema/register configuration and imported on install).
- **`docs/features/README.md` feature table updated**: a new "Flows
  (Workflow Automation)" row pointing at `flows.md`, and the existing
  Workflow Automation row re-scoped to what `workflow-automation.md`
  actually is — integrating EXTERNAL automation tools — with a cross-link
  each way. The n8n/Windmill page is corrected, not deleted: external
  orchestration remains a real integration surface; it is just not the
  native engine.
- **Boundary statements written down** where users will look for them:
  - *Notifications notify; flows orchestrate.* Declarative
    `x-openregister-notifications` (ADR-031) for "whenever X, tell Y";
    flow messaging nodes for "at this point in the process, tell Y".
  - *API calls go through OpenConnector.* A flow calls any external API via
    `openconnector.source-call` nodes against configured sources (ADR-094);
    the engine deliberately ships no native HTTP node.
- **The trigger cutover documented honestly.** The flow-engine spec records
  that trigger COLUMNS remain authoritative for unconverted flows, with a
  per-flow nodes-first cutover and a column fallback as the deprecation
  surface. The docs say so in user terms — which flows fire from nodes,
  which from legacy columns, and what re-authoring a flow with trigger
  nodes changes — instead of describing the end state as if the fallback
  did not exist.

## What does NOT change

- The engine. This change writes no PHP; where writing the docs exposes a
  behaviour gap, the gap is filed as an issue, never papered over with
  aspirational prose — a doc that describes the intended behaviour of a
  broken feature is worse than no doc.
- `workflow-automation.md`'s subject. External tools keep their page; it
  stops masquerading as the native story.

## Impact

- **Affected specs**: new capability `flow-engine-docs` (the documentation
  contract: exists, accurate, maintained)
- **Affected files**: `docs/features/flows.md` (new),
  `docs/features/README.md`, `docs/features/workflow-automation.md`
  (re-scoping note + cross-link)
- **ADRs referenced**: ADR-065 (the engine and canvas), ADR-031
  (notifications boundary), ADR-094 (OpenConnector boundary)

## Capabilities

### New Capabilities
- `flow-engine-docs` — user documentation for the flow engine, accurate to
  the shipped behaviour
