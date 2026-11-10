---
kind: code
---

# Proposal: flow-node-config-forms

## Summary

Give every built-in flow node a declarative configuration form. The contract
already exists — `IFlowNodeConfigForm` is defined, spec'd ("A node type declares
its own form, and its own run-log actions") and rendered by the editor — but
exactly ONE of the nineteen built-in types implements it: `AwaitSignalNode`.
Every other node an author drops on the canvas is configured through the
raw "Configuration (JSON)" textarea, guessing keys against documentation that
does not exist (see `flow-engine-docs`).

This change implements `configForm()` on all sixteen step/end types and all
three trigger types, and pins the editor's contract with two invariants: the
JSON pane remains available as the honest fallback, and switching between the
form and the JSON pane round-trips the config losslessly.

## Why

The form interface was designed for contributed nodes — an app that ships a
node ships its form, so the engine never hard-codes another app's keys. That
design is right and is not touched here. But it was landed with only the one
node that motivated it (`AwaitSignalNode`, whose approval question is authored
by non-technical operators), leaving the engine's OWN nodes on the raw pane.

The cost is concrete:

- `openregister.trigger-object` refuses to default `event`, `register` or
  `schema` — correctly, per the flow-engine spec ("A trigger with no subject is
  refused rather than defaulted"). An author typing JSON has to know those three
  key names and the sixteen-event vocabulary (`EventCatalogService::CATALOG`)
  by heart. A select fed from the catalogue makes the refusal unreachable
  instead of merely explained.
- `openregister.switch` and `openregister.route` configs carry per-branch
  expressions; a typo in a branch key silently routes nothing, and the JSON
  pane offers no hint which keys are read. `IFlowNodeConfigKeys` already knows;
  the knowledge just never reaches the author.
- The one node WITH a form proves the rendering path works end to end
  (`CnFlowSidebar` in nextcloud-vue renders the declared fields today), so this
  change is pure declaration work plus two guard-rail behaviours — no new
  editor architecture.

## What Changes

- **All 19 built-in node types implement `IFlowNodeConfigForm`.** Sixteen
  steps/ends (`openregister.object-read`, `object-write`, `set-fields`, `map`,
  `filter`, `switch`, `route`, `merge`, `explode`, `iterate`, `batch`,
  `flow-state`, `wait`, `sub-flow`, `await-signal` (exists), `end`) and three
  triggers (`trigger-object`, `trigger-schedule`, `trigger-manual` — the last
  declares an EMPTY form, which is itself information: "this node takes no
  configuration" rendered as such, not as an empty JSON object to puzzle over).
- **Every field maps to a key the node reads.** The field list is validated
  against `configKeys()` in a unit test per node — a form field over a key the
  node ignores looks like it works and changes nothing.
- **Select fields resolve from live catalogues via `optionsFrom`** — the event
  list from `EventCatalogService`, registers/schemas from their stores, flows
  (for `sub-flow`) from the flow store. Never inline snapshots.
- **The JSON pane stays, and round-trips.** Editing in the form then opening
  the JSON tab shows exactly the config the form wrote; keys the form does not
  cover survive a form edit untouched. This is what makes a partial form safe.
- **Contributed nodes are explicitly out of scope.** openconnector's nodes
  (`openconnector.source-call` et al., ADR-094) and hermiq's nodes get their
  forms in their own repos, via the same interface — that is the interface's
  entire point. This change files no requirement against those repos; it only
  proves the pattern at full breadth so those repos have nineteen examples.

## What does NOT change

- `IFlowNodeConfigForm` itself. The field vocabulary (`key`, `label`, `type`,
  `help`, `required`, `optionsFrom`) is sufficient for all nineteen nodes; no
  new field types are needed, and none are added speculatively.
- The editor's rendering (`CnFlowSidebar`). It already renders declared forms;
  the round-trip requirement is a behaviour it must keep, not one it gains.
- The raw-JSON pane. It is the documented fallback and the power-user surface.

## Impact

- **Affected specs**: `flow-engine` (the form requirement gains built-in
  coverage and the round-trip invariant)
- **Affected code**: all 18 not-yet-implementing classes in
  `lib/Service/Flow/Nodes/`, their unit tests; `FlowNodeRegistry` palette
  output already ships forms and needs no change
- **Affected apps**: none directly. openconnector and hermiq inherit worked
  examples, not obligations — tracked as follow-ups in their own repos.
- **ADRs**: ADR-065 (the single engine whose editor this serves) — consistent,
  no change needed.

## Capabilities

### Modified Capabilities
- `flow-engine` — the node-form requirement is strengthened from "a node MAY
  declare a form" to "every built-in node DOES", and the form/JSON round-trip
  becomes a stated invariant
