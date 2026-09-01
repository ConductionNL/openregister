# Adopting a shipped flow is a deliberate act with a mechanism

## Why

An imported `x-openregister-flows` flow deliberately arrives
`enabled=false, owner=null`, and `Flow::canDispatch()` fails closed on the
missing owner. That half is correct — a schema save is not a person
volunteering to run a graph as themselves. But "stored, visible and inert
until somebody makes it theirs" had no second half: `owner` is not among
`FlowService::applyEditableFields()`'s allowlist (correctly, so a payload
cannot hand a flow to an arbitrary uid), no occ command sets it, and
`dispatchableUuids()` drops the ownerless flow with only a log line. Measured
2026-09-01 on a clean instance: the only route from "shipped" to "runnable"
was raw SQL on the flows table.

## What changes

- `POST /api/flows/{id}/adopt` sets the flow's owner to the CALLING user.
  The request body is ignored — an endpoint that accepted a uid would let any
  caller with `flow.update` volunteer a colleague's identity for unattended
  execution.
- The endpoint requires the `flow.update` right and reaches the flow through
  `FlowService::find()`, so the organisation scope applies and a foreign flow
  answers the same 404 as a missing one.
- A flow already owned by ANOTHER user is refused with a machine-readable
  409 (`already-owned`): adoption is not a takeover. Adopting a flow one
  already owns is idempotent.
- Enabling stays a separate act. Adoption answers "whose identity", `enabled`
  answers "may it run"; the flow dispatches only when both are true.
- The adoption is audited: an info-level log line naming the flow, the
  adopter and the previous (null) owner.
- No occ command: the owner is the identity a run is attributed to, and occ
  has no acting user to volunteer — the same reason the import listener
  cannot set one.

## Impact

- Affected specs: flow-storage (ownership / dispatch)
- Affected code: `lib/Controller/FlowController.php`,
  `lib/Service/Flow/FlowService.php`,
  `lib/Service/Flow/FlowAdoptionRefused.php` (new), `appinfo/routes.php`
