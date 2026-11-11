## Context

The query layer has no acting-user parameter. `MagicRbacHandler` and
`MagicOrganizationHandler` read `IUserSession::getUser()` directly at roughly a
dozen points, so `findAll()` and everything under it resolve their subject from
ambient state. A caller holding an explicit `IUser` has nowhere to put it. Every
existing `runAs` in the fleet arrived at the same workaround — set the session
subject, restore it in a `finally` — independently, and for this reason.

`FlowRunAttribution` already exists in the working tree as the seam that
centralises "who does this run act as", written to replace five one-call-site
patches of the same defect (or#2158). Its precedence is caller-then-flow. This
change keeps the seam and the caller half, and replaces the flow half.

Constraints that shape the approach:

- **Nextcloud 32+** is the floor for every fleet app, so `setVolatileActiveUser()`
  (29.0+) is available without a compatibility shim.
- **ADR-002** — tenancy is an `Organisation` UUID, resolved through
  `OrganisationService` and cached per UID. Identity and tenancy are separate
  axes and must stay separately resolvable.
- **ADR-010** — the core permission verb set is core's bitmask; extensions are
  enforced at the endpoint performing the action, never by widening the RBAC
  vocabulary.
- **Flow triggers are already nodes** (`flow-engine`, "A TRIGGER is a node, and a
  flow may carry several"). The per-trigger identity model needs no new concept,
  only a field.

## Goals / Non-Goals

**Goals:**

- One `runAs` implementation in the fleet, on the non-persisting primitive.
- A run that states whose rights it executes with, separately from what caused it.
- A scheduled run that executes as a declared person, refused at save when it
  names none and refused at fire when that person is gone.
- Identity re-resolved at each fire and each resume.
- `runAsSystem()` unreachable from flow, agent-tool and request paths.

**Non-Goals:**

- **The delegation grant store and the consent lifecycle.** This change requires
  `runAs` to be *present and resolvable*; whether its author was *entitled* to
  name that user is `or-delegation-grants`. Until that lands, naming another user
  on a schedule trigger is unauthorized-but-recorded, which is strictly better
  than today's silent flow-owner fallback and strictly worse than the end state.
- **Retiring the five duplicate implementations.** Each app retires its own
  against the contract published here.
- **Threading an acting user through the query layer.** See Decisions.
- **Any change to `Flow.owner` / `Flow.organisation` semantics or enforcement.**

## Decisions

### Swap the session subject rather than thread a parameter

Threading `?IUser` through `findAll()` and its callees is the honest fix: no
ambient state, no restore discipline, nothing to leak. It is rejected on
measurement — roughly twenty signatures across six layers, plus
`OrganisationService` and its UID-keyed caches. Missing any one of them yields a
query whose authorization predicate disagrees with its tenancy predicate, and
that disagreement is silent.

Setting the subject moves every reader in lockstep, *including* the caches, which
are keyed by UID and therefore stay correct by construction. This remains a
workaround for a missing parameter and is recorded as such; ADR-099 keeps the
threaded version as the end state.

### `setVolatileActiveUser()`, and restore the previous user rather than clearing

`setUser()` writes `user_id` into the session. Restoring in a `finally` covers a
throw but not a fatal or an `exit()`, and on Integriq's endpoint path there is no
`finally` at all — so a request that authenticates by API key currently rewrites
the session's identity and returns a session cookie for it.

Restoring the *previous* user, not `null`, is what makes nesting compose: a
sub-flow acting as B returning into a parent acting as A must leave A in force.

### Identity per trigger node; the flow keeps ownership of the definition

Putting `runAs` on the flow row cannot express a flow with a manual trigger and a
schedule trigger — the identity differs per entry point, and that capability is
exactly why triggers became nodes. Putting it on the node also means there is
nowhere in a definition to declare an ambient identity override for *steps*,
which is a structural answer to authoring-time escalation rather than a checked
one.

`Flow.owner` / `Flow.organisation` are untouched: they answer "who may edit this"
and "which tenant", and `Flow::belongsTo()` is fail-closed on both. The
uncommitted `flowToSave()` refusal that requires both on create is correct and is
folded in as-is.

### Refuse at the queue, not at the node

An unattributable dispatch currently produces a run row that each
attribution-requiring node then rejects separately. Refusing at
`FlowRunService::queue()` means one refusal naming the cause, instead of a
half-executed run and a per-node message that reads like a permissions problem.

### Re-resolve, never snapshot

Rights are read at the moment work runs. A run parked for three weeks resumes
against the identity's *current* rights. The alternative — capturing an effective
permission set at queue time — means a revoked permission keeps executing for as
long as the run lives, and there is no upper bound on that.

Save-time validation is retained anyway, because failing at authoring time is far
cheaper for the author than failing at 03:00 in cron.

### Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Schedule disabled when its identity dies, owner notified | **Declarative** — `x-openregister-notifications` on the flow schema | ADR-031's default. The trigger is a state change on a stored object; the notification is a projection of it, not a service. |
| Run status transitions (`queued → running → failed`) | **Existing** | The flow run lifecycle already exists; this change adds no state. `awaiting_consent` arrives with `or-delegation-grants`. |
| Identity resolution and re-resolution | **Imperative** — ADR-031 exception: lifecycle guard | A guard that must run at a specific moment (fire, resume) against live user state, deciding whether execution proceeds. Not a derived field. |
| The `runAs` scope primitive | **Imperative** — ADR-031 exception: framework interaction | It manipulates `IUserSession`, an OCP interface. There is no declarative expression of "swap the acting subject for this callable". |

### Seed data (ADR-001)

**Not applicable.** This change introduces no OpenRegister schema and no register
objects — `runAs` is a column on the existing `oc_openregister_flow_runs` table
and a config key on an existing node type. The grant store in
`or-delegation-grants` introduces persisted records and carries the seed-data
section.

## Risks / Trade-offs

**Ambient state is still ambient.** A `finally` does not run on a fatal or an
`exit()`. Moving to `setVolatileActiveUser()` reduces the blast radius from "the
session is now that user" to "this dying request is now that user", which is
survivable, but it does not eliminate the class. The parameter-threading end
state does.

**Enforcement makes things start refusing.** Scheduled flows with no declared
identity stop firing, and flows whose schedule trigger names nobody stop saving.
This is the intended outcome and it is also a live-traffic change, so task 1
measures the affected count before task 5 enforces. If that count is large, the
enforcement ships behind a grace period that logs rather than refuses — the
decision needs the number, not a guess.

**`triggeredBy` has two readers during the transition.** Until every node reads
`runAs`, both fields are live and can disagree. The backfill makes them identical
for existing rows, and the node changes land in the same change to keep the
window closed.

**A grant-less `runAs` is a real gap, deliberately shipped.** Between this change
and `or-delegation-grants`, anyone who may edit a flow may name any user on a
schedule trigger. That is not worse than today — today the same user gets the
flow author's identity with no declaration at all — but it is not the end state
and must not be described as done. `or-delegation-grants` is a hard dependency of
the ADR, not an optional follow-up.

**`runAsSystem()` reachability is enforced by a gate, and a gate can be wrong.**
A static check on call sites cannot see a dynamically dispatched call. The gate is
a control against drift, not a proof; the narrow constructor-level restriction
(the service is not injected into node/tool/endpoint classes at all) is what
actually holds it.
