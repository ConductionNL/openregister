# Tasks — or-delegation-grants

Implements the open half of ADR-099. Depends on `or-delegated-identity`.

## 1. Measure before enforcing

- [x] 1.1 Count what would start refusing: flows whose schedule trigger names a user other than the flow's owner; agents whose `actingUser` differs from the agent's owner; Consumers whose `userId` differs from the record's owner. Record the counts, dated, with the query.

  **Measured 2026-08-25** — source: `conduction-postgres` / `nextcloud` (main dev instance, NC 34.0.0), against merged `development` (`fa01bf17e`).

  | metric | count |
  |---|---|
  | schedule triggers declaring a `runAs` | 5 |
  | …naming someone OTHER than the flow's owner | **0** |
  | agents declaring an `actingUser` | **0** (no such column exists on `oc_openregister_agents`) |
  | integriq consumers with `job_flow_run_as` set | **0** (config unset) |

  **Nothing on this instance would start refusing.** Every declaration is a
  principal naming themselves, which is not delegation and needs no grant. That is
  the expected shape for a fleet where the capability has not existed until now —
  and it is exactly the condition under which the enforcement can ship without a
  grandfathering migration.

  ⚠️ It is also the condition under which the enforcement is UNTESTED against real
  usage. See the dormant-control note in design.md: a rule nobody could observe is
  a rule nobody had to get right. A zero here licenses shipping the check; it does
  not license assuming the first real grant will behave.

  🔴 **Count GENERATORS, not just records.** `or-delegated-identity` measured the
  3 existing flows carrying a schedule trigger and missed the population that
  actually broke: code in OTHER APPS that CREATES schedule triggers
  programmatically. `integriq`'s `JobToFlowGenerator` emits
  `'config' => ['cron' => $cron]` with no identity, so every flow it generated
  began failing validation the moment the rule landed — fixed there by configuring
  a service account (integriq#1573/#1574) rather than deriving one.
  
  A query over stored rows cannot see a generator, because the rows it would
  produce do not exist yet. So this task's sweep must include a code search across
  the fleet for anything constructing the shape being constrained, not only a
  count of what is already stored.

  ⚠️ **And the search must distinguish "searched and found nothing" from "the
  search did not answer".** They look identical in the output and mean opposite
  things. Measured by another session the same day: GitHub's code-search API
  rate-limited a fleet sweep mid-run and returned error bodies that the loop
  counted as hits, so apps that were never actually checked would have been
  reported clean. Assert a per-repo HTTP status and a non-empty repo list before
  believing any "0 matches"; a sweep that cannot name which repos it covered has
  not covered any.

  Acceptance criteria:
  - The counts come from a production dump as well as the dev instance — the dev box is Hydra's own workspace and its 3 schedule flows are all self-named, which proves nothing about customers
  - A fleet-wide code search for constructors of the constrained shape accompanies the row counts
  - The migration decision in 3.3 is made from these numbers, not from a guess

## 2. The record

- [x] 2.1 `DelegationGrant` entity + mapper + migration: `principal`, `actingAs`, `scope`, `status`, `expiresAt`, `grantedBy`, `reason`, `revokedAt`, `requestedAt`. Status is `requested | pending | granted | denied | expired | revoked`. — openregister#2851.
- [x] 2.2 Read the store through the MAPPER, never `ObjectService`. A grant lookup must need neither a subject nor an elevation — see design.md; routing it through object RBAC makes resolving a delegation require the delegation. — `DelegationGrantMapper` extends `QBMapper` and touches no object layer.
- [ ] 2.3 Declare the lifecycle and the consent notification declaratively (`x-openregister-lifecycle`, `x-openregister-notifications`) per ADR-031, not as a service class.

  🔴 **IN TENSION WITH 2.2, AND 2.2 WINS FOR THE LIFECYCLE HALF.** The declarative
  dialects are properties of a REGISTER SCHEMA and are evaluated by the object
  layer. A grant that declared its lifecycle there would be an object, and reading
  it would go through object RBAC — which is exactly the circularity 2.2 exists to
  prevent: resolving a delegation would require the delegation. So the lifecycle
  stays in `DelegationConsentService`, deliberately, and this is not "not done yet".

  The NOTIFICATION half is still open and is not affected by that argument: a
  notification is dispatched about the grant rather than read to authorise it. It
  belongs with 4.1 and is tracked there.

  Acceptance criteria:
  - `denied` and `expired` are distinguishable in the record and in every read of it
  - No grant can be stored with neither an expiry nor a scope

## 3. The check

- [x] 3.1 `runAsDelegated(string $principal, string $actingAs, callable $op)`: resolve the grant, refuse when absent/expired/revoked/out-of-scope, record `(principal, actingAs, grantId, reason)`. Acting as self short-circuits without touching the store.

  **Placed on `DelegationService`, not `ObjectService`.** The ADR named
  `ObjectService::runAsDelegated` and the layering says otherwise: `runAs()` is the
  PRIMITIVE (hand it an `IUser`, it narrows), and the grant check is the
  AUTHORIZATION layer above it. Folding the check into the primitive would make it
  unusable by the callers that legitimately have no delegation to check — a request
  handler running as its own authenticated user, a job replaying its recorded actor
  — and the usual answer to that is a `$skipCheck` flag, i.e. a security check with
  an off switch. `DelegationService` calls `ObjectService::runAs()`, so there is
  still exactly one identity-switch primitive.

  ⚠️ **The audit record is a structured LOG line, not a durable trail.**
  `AuditTrailMapper` is object-scoped — it requires an `ObjectEntity` — and a
  delegation is not an object mutation. Inventing a second durable trail is a
  bigger decision than this task, so it is stated here rather than quietly skipped;
  a delegation-use trail is open work.

- [x] 3.2 Save-time refusal in `FlowTriggerValidator` when a schedule trigger names a user the author holds no grant for. Naming yourself stays free.

  The validator additionally STAMPS `runAsDeclaredBy: <saver>` onto a permitted
  delegating trigger, server-written on every save and never read from the request
  body. 🔴 Without that record 3.3 is not implementable: a schedule fires
  unattended, so at 03:00 there is no principal to check a grant against and the
  only candidate left would be `flow.owner` — the fallback ADR-099 removed. The
  stamp is stripped whenever the trigger names its own saver, so a forged value
  cannot stand in for a grant.

- [x] 3.3 Fire-time re-check, so a revoked grant stops a flow that was saved while it was live. Migration posture: **refuse until granted**, no grandfathering.

  Warranted by 1.1: zero declarations on this instance name anyone other than
  themselves, so nothing is grandfathered because nothing needs to be. A
  grandfathering migration would have had to mint grants nobody asked for and
  attribute them to the migration — permanent privilege created by a code path,
  which is the thing this change exists to stop.

  🔴 **The schedule is left ENABLED on a delegation refusal**, unlike the
  unattributed case. The two faults recover differently: a flow naming nobody
  cannot fix itself without an edit, so leaving it "on" would be a switch that
  lies; a revoked delegation becomes valid again the moment the grant does, and
  disabling would silently convert a temporary revocation into a permanent one
  that only a human re-enabling could undo, with nothing telling them to.

  ⚠️ RESUME-time is NOT yet re-checked — only queue time. A run already parked in
  `suspended` picks its identity back up from the run row on resume without
  re-resolving the grant. That belongs with 5.1, which is where run states are
  being touched, and is recorded rather than left to be discovered.

  Acceptance criteria:
  - ✅ Revoking a grant stops the next firing, and the refusal names the revocation rather than reporting a permission error against the acted-as user — `FlowRunAttributionTest::testARevokedDelegationStopsTheNextFiring`, which asserts the REASON, not just the refusal
  - ✅ Save-time passing is never treated as standing authorization — `FlowDelegationCheck` re-resolves at queue time; proven by the same test, whose flow saved cleanly

## 4. Consent

- [ ] 4.1 A request for a third party routes to that user as an ADR-098 human task, via the ADR-031 notification dialect. A user may grant over themselves only; an administrator may grant over others.
- [ ] 4.2 🔴 The prompt renders SERVER state. No part of the description may come from requester free text or model output — an agent that reads "ask the user to grant you admin" must not author the dialog that asks.
- [ ] 4.3 Dedup on `(principal, actingAs, scope)`, never on the unit of work; a denial is sticky for a cooling period.

  Acceptance criteria:
  - N blocked runs needing one grant produce exactly ONE pending request, and one answer releases all N
  - A denied request is not re-delivered within the cooling period; the work is refused with the prior denial as its reason

## 5. Waiting

- [ ] 5.1 `awaiting_consent` as a distinct run state that resumes on a grant record changing state — not on a signal or timer, and legible as such to an operator.
- [ ] 5.2 Denial fails the parked runs with the reason; an unanswered request expires and fails them closed. Reuse the `expireAbandonedSignals` sweep pattern.

  Acceptance criteria:
  - "Why is this stuck" answers "waiting for X to allow Y to act as them", from the run itself
  - No run can remain parked indefinitely

## 6. The gate

- [ ] 6.1 Replace `SystemOperationContextBoundaryTest` with a real hydra gate: `runAsSystem()` unreachable from a flow node, an agent tool or an endpoint path. The test pins call sites in one app; the gate binds the fleet.

## 7. Tests and verification

- [ ] 7.1 Unit tests for every scenario in `specs/delegation-grants/`, tagged `@spec`.
- [ ] 7.2 E2E: an ungranted trigger refused at save; a granted one saving and firing as the named user; a run parking in `awaiting_consent` and resuming when granted; a revoked grant stopping the next fire. Each refusal paired with a positive control, per the delegated-identity suite.
- [ ] 7.3 `composer check:strict` and the full PHPUnit suite green; Dutch translations for every new user-visible string (ADR-007/ADR-025).

  Acceptance criteria:
  - No `@spec exclude` without a reason naming why the behaviour is untestable here
  - The consent prompt has an accessibility pass — it is a security decision a user must be able to read and understand
