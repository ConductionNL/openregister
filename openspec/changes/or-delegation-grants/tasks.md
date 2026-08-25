# Tasks — or-delegation-grants

Implements the open half of ADR-099. Depends on `or-delegated-identity`.

## 1. Measure before enforcing

- [ ] 1.1 Count what would start refusing: flows whose schedule trigger names a user other than the flow's owner; agents whose `actingUser` differs from the agent's owner; Consumers whose `userId` differs from the record's owner. Record the counts, dated, with the query.

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

- [ ] 2.1 `DelegationGrant` entity + mapper + migration: `principal`, `actingAs`, `scope`, `status`, `expiresAt`, `grantedBy`, `reason`, `revokedAt`, `requestedAt`. Status is `requested | pending | granted | denied | expired | revoked`.
- [ ] 2.2 Read the store through the MAPPER, never `ObjectService`. A grant lookup must need neither a subject nor an elevation — see design.md; routing it through object RBAC makes resolving a delegation require the delegation.
- [ ] 2.3 Declare the lifecycle and the consent notification declaratively (`x-openregister-lifecycle`, `x-openregister-notifications`) per ADR-031, not as a service class.

  Acceptance criteria:
  - `denied` and `expired` are distinguishable in the record and in every read of it
  - No grant can be stored with neither an expiry nor a scope

## 3. The check

- [ ] 3.1 `ObjectService::runAsDelegated(IUser $principal, string $actingAs, callable $op)`: resolve the grant, refuse when absent/expired/revoked/out-of-scope, write the audit record naming `(principal, actingAs, grantId, reason)`. Acting as self short-circuits without touching the store.
- [ ] 3.2 Save-time refusal in `FlowTriggerValidator` when a schedule trigger names a user the author holds no grant for. Naming yourself stays free.
- [ ] 3.3 Fire-time and resume-time re-check, so a revoked grant stops a flow that was saved while it was live. Decide and implement the migration posture from 1.1 — grandfathered grants, or refuse until granted. If grandfathering: the grants must be visible, expiring, and attributed to the migration rather than to a person.

  Acceptance criteria:
  - Revoking a grant stops the next firing, and the refusal names the revocation rather than reporting a permission error against the acted-as user
  - Save-time passing is never treated as standing authorization

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
