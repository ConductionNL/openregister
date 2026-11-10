---
kind: code
depends_on: []
---

# Proposal: flow-trigger-transitions-and-changed-fields

## Summary

A maker who builds an automation in buildiq can start it when a record moves to
another state, and can start an update automation only when the fields it cares
about changed. Both are settings on OpenRegister's object trigger node
(`openregister.trigger-object`), so every app that wires a flow to a record
gets them, not only buildiq.

## Halves this closes

This is the OpenRegister half of two buildiq changes merged on buildiq
`development` (974af86). Neither has a row in OpenRegister's matrix; the owner
moves pass of 28 Sep 2026 handed them here after the OpenRegister lane had
finished.

| requesting repo | change | what it asks of OpenRegister |
|---|---|---|
| buildiq | `logic-automation-actions-that-run` | "A way to start a flow on a lifecycle transition: `openregister.trigger-object` only knows `object.created`, `object.updated` and `object.deleted`" |
| buildiq | `ai-llm-steps-and-computed-fields` | "a changed-fields condition on `openregister.trigger-object` ... a flow on `object.updated` that writes the same record starts itself again. OpenRegister owes a way to start only when named fields changed." |

Buildiq's rows behind them, in buildiq's matrix: `logic-action-update-record`
(3 competitors rate yes), `logic-action-webhook` (4 yes), `logic-rules-engine`
(2 yes), `ai-llm-action` (2 yes) and `ai-computed-column` (2 yes: Budibase and
Power Apps). Until this lands, buildiq runs AI steps and AI fields on
`object-created` and `manual` only, and cannot offer "when the record reaches
state X" as a trigger.

The third ask in `logic-automation-actions-that-run`, a signed-in app user
starting a published manual flow on a record, is already specified by the open
change `macro-flows-with-next-item` (a declared action bound to a published
manual flow, `POST /api/objects/{register}/{schema}/{id}/actions/{action}`).
It is not repeated here.

## What changes

- `openregister.trigger-object` accepts `object.transitioned` as its `event`,
  with an optional `transition` filter: action names, `from` states and `to`
  states. A trigger with no filter starts on every transition of the schema.
- `openregister.trigger-object` accepts an optional `changedFields` list on
  `object.updated`. The flow starts only when at least one named field changed
  value in that save.
- The trigger listener puts the changed field names on the run context, so a
  flow can also branch on them.
- A flow whose own write back to the record changes none of its watched fields
  does not start itself again.

## Out of scope

- A trigger on changes inside nested objects by path. `changedFields` names
  top-level properties.
- Starting a flow from a button. That is `macro-flows-with-next-item`.

## Impact

- `lib/Service/Flow/Nodes/TriggerObjectNode.php` (`EVENTS`, `configKeys()`,
  `validateConfig()`).
- `lib/Listener/FlowTriggerListener.php` (`contextFor()`).
- `lib/Service/Flow/FlowTriggerService.php` (a filter before `queue()`).
- `openspec/specs/flow-engine/spec.md` (trigger requirements).
