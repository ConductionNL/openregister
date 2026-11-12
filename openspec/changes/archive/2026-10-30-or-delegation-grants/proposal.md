---
kind: code
depends_on: [or-delegated-identity]
---

## Why

`or-delegated-identity` made every run state whose rights it executes with, and
made that identity impossible to forge from a payload. It deliberately stopped
short of asking the only question that turns a *declaration* into an
*authorization*: was the person who named that identity entitled to name it?

Today they are not checked. Anyone who may edit a flow may point its schedule
trigger at any user on the instance and have it execute with that user's rights,
unattended, forever. That is not a regression — before ADR-099 the same author
silently got the flow owner's identity with no declaration at all — but it is the
open half of the ADR, and the half that makes the other half safe.

The same gap exists wherever an identity is named rather than held: an agent
record's `actingUser`, and an Integriq Consumer's bound `userId`.

## What Changes

- **A delegation grant becomes a record**: `principal` (who may act), `actingAs`
  (whose identity), `scope`, `expiresAt`, `grantedBy`, `reason`, `revokedAt`,
  and a status of `requested → pending → granted | denied | expired | revoked`.
- **BREAKING — naming another user requires a grant.** A schedule trigger, an
  agent's `actingUser`, or a Consumer's `userId` naming somebody other than the
  author is refused at save unless the author holds a live grant for them.
  **Acting as yourself is not delegation and needs no grant.**
- **`runAsDelegated(IUser $principal, string $actingAs, callable $op)`** joins
  `runAs()` on `ObjectService`: it resolves the grant, refuses loudly when it is
  absent, expired, revoked or out of scope, and writes an audit record naming
  `(principal, actingAs, grantId, reason)`. Bare `runAs()` stays for the
  already-authorized case — a run acting as its own trigger identity is
  attribution, not delegation.
- **Consent can be asked for.** A request naming a third party is routed to that
  user through the canonical `x-openregister-notifications` dialect (ADR-031) and
  lands on the ADR-098 human-task rail. A user may grant delegation over
  themselves and nobody else.
- **BREAKING — a run needing a grant it does not hold parks in
  `awaiting_consent`**, a distinct run state. Deduped on
  `(principal, actingAs, scope)`, so one request parks every run that needs it and
  one answer resumes them all. Denial fails the parked runs with the reason;
  a pending request expires and fails closed; a denial is sticky for a cooling
  period.
- **The grant store is read through a mapper, not `ObjectService`.** Reading the
  record that decides authorization must not itself require authorization.
- **A gate** makes `runAsSystem()` unreachable from flow, agent-tool and endpoint
  paths, replacing the structural test that currently pins its call sites.

Explicitly **not** here: retiring the five duplicate `runAs` implementations
(each app's own change), and the capability-grant relocation from Hermiq
(`or-capability-grants`). Both bind to contracts this change does not alter.

## Capabilities

### New Capabilities
- `delegation-grants`: who may act as whom — the grant record, its lifecycle,
  how consent is requested and answered, and what a run does while it waits.

### Modified Capabilities
- `delegated-identity`: naming an identity other than your own now requires a
  grant; `runAsDelegated()` joins the primitive it already describes.
- `flow-engine`: a run may park in `awaiting_consent`, and a trigger declaring
  another user is refused at save without a grant.
- `rbac-scopes`: the grant store is deliberately outside RBAC's own read path.

## Impact

**Code** — new `lib/Db/DelegationGrant*` (entity, mapper, migration),
`lib/Service/Delegation/*`, `ObjectService::runAsDelegated()`,
`FlowTriggerValidator`, `FlowRunService` (the new run state),
`lib/Service/Flow/Nodes/TriggerScheduleNode.php`.

**Behaviour** — flows, agents and consumers naming another user stop saving until
a grant exists. The live count must be measured before enforcement lands: on the
dev instance all 3 schedule flows name `admin` and are authored by `admin`, so
they need no grant — but that instance is Hydra's own workspace and is not
representative.

**Downstream** — `hermiq` (`Agent.actingUser`) and `integriq` (Consumer
`userId`) each gain a save-time grant check in their own changes.

**Security** — this is the change that makes ADR-099's central invariant
enforceable: identity narrows along an invocation chain, and widening requires a
grant checked against the caller.
