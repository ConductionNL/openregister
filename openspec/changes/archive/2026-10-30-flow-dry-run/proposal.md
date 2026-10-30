# Proposal: flow-dry-run

## Why

An author who changes a flow cannot see what it would do to a real record without running it, and a run writes,
sends and starts work that cannot be taken back. Dossiq's flow page (board DqFlow) draws "Testen met een zaak": pick
a case, see per step which branch the flow takes, which fields would change and which messages would go out.

## What changes

- `POST /api/flows/{id}/dry-run` with `{register, schema, objectId}` runs the flow's graph through the real engine
  against that object, with a preview dispatcher in place of the one that performs steps. Gated by the `flow.update`
  right; the object is read with the caller's own access.
- The preview dispatcher performs only steps that declare themselves free of side effects
  (`IFlowSideEffectFree`: set-fields, switch, route, filter, merge, explode, end and the triggers), so data a later
  condition reads is shaped as in a real run. Every other step is described, not performed: a node that implements
  `IFlowNodePreview` says what it would do (object-write: the fields it would set; send-email, send-notification,
  send-talk-message: the recipients and the text, composed but not delivered); any other node is named as a step that
  would run.
- The answer lists the steps in the order the engine reached them, each with its status, its item counts and its
  preview, and the run's end status. Nothing is stored: the marking lives on an unsaved run record, no run row is
  queued, no audit or message is written.
