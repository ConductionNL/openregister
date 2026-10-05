---
kind: code
depends_on: []
---

## Why

Six apps in the fleet independently implemented the same "run this as that user"
function, and all six use the wrong Nextcloud primitive. `IUserSession::setUser()`
sets the active user *and writes `user_id` into the PHP session*;
`setVolatileActiveUser()` (Nextcloud 29.0+, and every fleet app declares
`min-version="32"`) sets it without persisting. `lib/base.php` starts a real
session on every request but `status.php`, so an identity swapped in with
`setUser()` outlives the call whenever the `finally` does not run — a fatal, an
`exit()`, or, on Integriq's endpoint authentication path, because there is no
`finally` at all.

Two further defects ride on the same seam. `FlowRun.triggeredBy` answers "who
caused this run" and is simultaneously being used to answer "whose rights does
this execute with" — different questions with different lifetimes, since
provenance is immutable and authorization must be re-evaluated. And the acting
identity for a scheduled run is read from the flow definition, so a cron run
executes as whoever happened to author the flow.

ADR-099 settles all three. This change lands its mechanical half.

## What Changes

- **`ObjectService::runAs()` switches to `IUserSession::setVolatileActiveUser()`.**
  Same scoping and restore-previous semantics, no session persistence.
- **`ObjectService::runAs()` becomes the fleet's single implementation.** The
  duplicates in Integriq, Buildiq, Humaniq and Dossiq delegate to it in their own
  changes; this change publishes the contract they bind to.
- **BREAKING — `FlowRun` gains `runAs`**, the sole authorization source.
  `triggeredBy` keeps its meaning (provenance), is backfilled into `runAs` for
  existing rows, and is never read to decide access again.
- **`FlowRunAttribution` keeps caller-wins and loses its flow-owner fallback for
  identity.** Its non-caller branch resolves the trigger node's `runAs` and fails
  closed. Tenant resolution is untouched and keeps falling back to the flow's
  organisation — identity and tenancy resolve independently.
- **BREAKING — a schedule trigger node must declare a resolvable `runAs`.**
  `TriggerScheduleNode::validate()` refuses the save otherwise, alongside the
  cron-expression check it already performs. `FlowTriggerIndex` carries the
  identity so the registration and the identity it fires under derive from one
  node.
- **Identity is re-resolved at every fire and every resume**, never snapshotted
  at queue time. A user who has been deleted or disabled fails the run closed,
  and a schedule whose identity has died is disabled with its owner notified
  rather than silently skipped.
- **`runAsSystem()` becomes code-initiated only** — unreachable from a flow node,
  an agent tool, or an endpoint request path.

Explicitly **not** in this change: the delegation grant store, the consent
lifecycle, and the `awaiting_consent` run state. Those need a store that does not
exist yet and land in `or-delegation-grants`. Here `runAs` must be *present and
resolvable*; whether its author was *entitled* to name it is the next change.

## Capabilities

### New Capabilities
- `delegated-identity`: how a piece of work acquires the identity it executes
  as — the primitive, its scoping and restore guarantees, where a run's identity
  comes from, and when it is re-resolved.

### Modified Capabilities
- `rbac-scopes`: `runAs()` changes primitive; `runAsSystem()` gains a
  reachability boundary it did not previously state.
- `flow-engine`: a run carries an authorization identity distinct from its
  attribution; a schedule trigger must declare one; identity is re-resolved on
  resume.

## Impact

**Code** — `lib/Service/ObjectService.php` (`runAs`, `runAsSystem`),
`lib/Service/Flow/FlowRunAttribution.php`, `lib/Service/Flow/FlowRunService.php`,
`lib/Service/Flow/FlowScheduleService.php`,
`lib/Service/Flow/Nodes/TriggerScheduleNode.php`,
`lib/Service/Flow/FlowTriggerIndex.php`,
`lib/Service/Flow/Nodes/ObjectReadNode.php`,
`lib/Service/Flow/Nodes/ObjectWriteNode.php`, `lib/Db/FlowRun.php` + migration.

**Behaviour** — flows whose schedule trigger names no user stop saving, and
existing scheduled flows without one stop firing until an identity is declared.
The live count is measured before the enforcement lands (task 1).

**Downstream** — Integriq, Hermiq, Buildiq, Humaniq and Dossiq each retire a
local copy against the contract published here. Integriq's endpoint
authentication path additionally gains a scope it never had.

**Not affected** — `Flow.owner` / `Flow.organisation` keep their meaning as
ownership of the *definition* (who may edit, which tenant) and their fail-closed
write-side enforcement. ADR-002's tenancy model is unchanged.
