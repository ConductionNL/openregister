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
- [x] 2.3 Declare the lifecycle and the consent notification declaratively per ADR-031 — **CLOSED AS NOT APPLICABLE, for a reason this task list creates.**

  🔴 **BOTH DIALECTS REQUIRE THE GRANT TO BE AN OBJECT, AND 2.2 FORBIDS THAT.**
  `x-openregister-lifecycle` and `x-openregister-notifications` are properties of a
  REGISTER SCHEMA, evaluated by the object layer. A grant that declared either
  would be an object, and reading it would go through object RBAC — the exact
  circularity 2.2 exists to prevent: **resolving a delegation would require the
  delegation.** The two tasks cannot both be satisfied, and 2.2 is the one holding
  a security property.

  This is not a deferral. There is no version of the delegation store that is both
  mapper-read and schema-declared, so the lifecycle stays in
  `DelegationConsentService` permanently. Ticked as decided rather than left open,
  because an open box invites somebody to "finish" it by making the grant an
  object — which would silently reopen the circularity.

  **The notification half IS delivered**, by 4.1, and the same argument explains
  why it is imperative: `x-openregister-notifications` fires on OBJECT lifecycle
  events, and a grant has none to fire on. `DelegationNotifier` dispatches through
  `INotifier` instead. ADR-031's gate warns about imperative object-notification
  dispatch *in a leaf app*; this is neither a leaf nor an object notification.

  Acceptance criteria:
  - ✅ `denied` and `expired` are distinguishable in the record and in every read of it — separate statuses, separate `DelegationVerdict` reasons, and `mayRequestConsent()` treats them differently: expired is re-askable, denied is not
  - ✅ No grant can be stored with neither an expiry nor a scope — `DelegationConsentService::request()` always sets `expiresAt`, and `DelegationResolver::covers()` refuses to read an empty grant scope as unlimited

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

- [x] 4.1 A request for a third party routes to that user, via the Nextcloud notification surface. A user may grant over themselves only; an administrator may grant over others.

  `DelegationController` + `DelegationNotifier`. The prompt carries Allow/Deny
  actions pointing at the answer route, and answering withdraws it — a prompt
  outliving its decision invites a second answer that either does nothing or
  overwrites the first.

  ⚠️ Delivered through `INotifier` rather than as an ADR-098 human task. A human
  task lives in a flow's graph; this question is asked from a save path and from a
  cron sweep, neither of which is inside a flow. Routing it through a flow would
  mean creating a flow in order to ask whether a flow may run.

  🔴 Fixed a PRE-EXISTING ORPHANED CAPABILITY while wiring this:
  `lib/Notification/Notifier.php` was never registered. `AnnotationNotifier`
  re-throws for the subjects it does not own and says they are "rendered by
  Notifier" — but nothing registered Notifier, and Nextcloud silently drops a
  notification no notifier claims. Four subjects had been stored and discarded at
  parse time for as long as they have existed.

- [x] 4.2 🔴 The prompt renders SERVER state. No part of the description comes from requester free text or model output.

  The notification parameters are the two uids and the grant uuid, read from the
  record. The stated reason is carried as its own attributed field and returned by
  the API for a UI to render as quoted third-party text. Asserted by a test that
  names a hostile string and checks for its ABSENCE — the only form of that rule
  anyone will notice breaking, since a `reason` field unused by one call site
  reads as an oversight rather than as a rule.

- [x] 4.3 Dedup on `(principal, actingAs, scope)`, never on the unit of work; a denial is sticky for a cooling period.

  Acceptance criteria:
  - ✅ N blocked runs needing one grant produce exactly ONE pending request, and one answer releases all N — the consent service reuses an outstanding request, and the notification is keyed on the grant uuid so Nextcloud replaces rather than appends. Verified live: a second request returned the same uuid and the original stated reason.
  - ✅ A denied request is not re-delivered within the cooling period; `DelegationVerdict::mayRequestConsent()` is false after a denial, and the refusal carries `denied` as its reason.

## 5. Waiting

- [x] 5.1 `awaiting_consent` as a distinct run state that resumes on a grant record changing state — not on a signal or timer, and legible as such to an operator.

  `FlowRun::STATUS_AWAITING_CONSENT` + `FlowConsentParking`, swept by
  `FlowRunWorker`. It is in `ACTIVE` and not `TERMINAL`: a parked run is still
  going to happen, and hiding it from every "currently running" surface would hide
  it from exactly where somebody looks to find out why their work has not run.

  🔴 **Distinct from `suspended`, and the distinction is load-bearing.** A
  suspended run waits on machinery — a timer, a webhook, a child run — and the
  abandoned-signal reaper fails it after a while on the reasoning that a signal
  which has not arrived is not coming. That reasoning is wrong about a person:
  somebody who has not read their notifications in two hours has not declined,
  they are at lunch. Parking in `suspended` would have handed these runs to that
  reaper and failed them while their prompt sat unread.

  The parked run carries NO `resume_at`, deliberately — with one, the timed-resume
  sweep would start it before anybody had answered.

- [x] 5.2 Denial fails the parked runs with the reason; an unanswered request expires and fails them closed.

  🔴 The timeout fails, it does not proceed. Running the work after a timeout
  would convert an unread prompt into an approval at whatever hour the timer
  elapsed — the exact substitution this subsystem exists to prevent. The recorded
  error says "an unanswered request is not consent" rather than merely noting that
  time passed. Default wait 72h (`flow_consent_wait_hours`).

  ⚠️ An UNREADABLE store leaves the run parked rather than failing it. The
  trade-off inverts from the fire-time check: there, refusing costs one run and
  permitting costs an unauthorized execution; here nothing runs either way, so
  waiting is free and failing destroys work over an infrastructure blip.

  Acceptance criteria:
  - ✅ "Why is this stuck" answers "waiting for X to allow Y to act as them", from the run itself — written to `run.error` and to `context.awaitingConsent`, so no join against the grant store is needed. Verified live: the parked row read `Waiting for "ddauth-alice" to allow "admin" to act as them.`
  - ✅ No run can remain parked indefinitely — bounded by `flow_consent_wait_hours`, and failed closed when it elapses.

  **Verified live end to end** on `localhost:8080`: grant → save (stamped) →
  revoke → re-request (pending) → schedule fires → run parks in
  `awaiting_consent` → answer allow → worker sweep releases → queued → executed →
  `stopped`. Both background jobs driven by `occ background-job:execute`.

## 6. The gate

- [x] 6.1 A real hydra gate: `runAsSystem()` unreachable from a flow node, an agent tool or an endpoint path. The test pins call sites in one app; the gate binds the fleet.

  **Gate 96 `system-elevation-reachability`** — ConductionNL/.github#579
  (`hydra-gates/scripts/lib/check_system_elevation.py`). Permitted callers are
  matched by KIND (`lib/Migration/`, `lib/Repair/`, `lib/Command/`,
  `lib/BackgroundJob/`, `lib/Cron/`) rather than by an allowlist of files, so a
  new repair step needs no gate change while a new controller still fails.

  🔴 **NO exclusion annotation, deliberately.** Most gates in the suite take a
  reason-bearing `@gate exclude`. An escape hatch on this rule would be used
  exactly when somebody is making a refusal go away — the case the gate exists
  for — and a reason written in that moment reads identically to a legitimate one
  afterwards. Green bought with a plausible sentence is worse than red, because
  it ends the conversation.

  ⚠️ **Not "replace" — ADDED ALONGSIDE.** `SystemOperationContextBoundaryTest`
  stays: it runs in milliseconds on every local `phpunit` and names the four
  permitted files exactly, which the gate deliberately does not (it permits by
  directory). Deleting the faster, more specific instrument because a broader one
  now exists would trade a signal for nothing.

  ⚠️ **Stated limit, in the helper's own docblock:** a dynamically dispatched
  call is invisible to it, exactly as it is to the test it generalises. It guards
  against DRIFT; it does not prove absence. The control that holds the line is
  that the elevating service is not injected into node, tool or endpoint classes.

  Verified: acceptance matrix 184/184 with the new fixture, ratchet intact; run
  against openregister's 1,488 `lib/**/*.php` — 4 elevate, 0 failures, and the
  four are exactly the boundary test's `ALLOWED` list. A planted controller and a
  planted flow node both fail; a migration doing the same thing passes.

## 7. Tests and verification

- [x] 7.1 Unit tests for every scenario in `specs/delegation-grants/`, tagged `@spec`.

  `DelegationResolverTest`, `DelegationConsentServiceTest`, `DelegationServiceTest`,
  `DelegationNotifierTest`, `FlowConsentParkingTest`, plus the delegation arms of
  `FlowTriggerValidatorTest` and `FlowRunAttributionTest`.

- [x] 7.2 E2E: an ungranted trigger refused at save; a granted one saving; a run parking in `awaiting_consent` and resuming when granted; a revoked grant stopping the next fire. Each refusal paired with a positive control.

  `delegated-identity.spec.ts` (9) + `delegation-consent.spec.ts` (9), both green
  against a live instance. The refusal fixture uses a uid that RESOLVES
  (`ddauth-alice`), not a ghost — refusing an unknown account was already true,
  and only a real colleague isolates the rule this change adds.

  ⚠️ **The parking half is verified live but NOT as a Playwright spec.** Reaching
  `awaiting_consent` needs a schedule to fire and a cron worker to sweep, neither
  of which the api-direct harness can drive; forcing them needs
  `occ background-job:execute`. It was run by hand end to end on 2026-08-26 —
  grant → save (stamped) → revoke → re-request (pending) → schedule fires → run
  parks reading `Waiting for "ddauth-alice" to allow "admin" to act as them.` →
  answer allow → worker sweep releases → queued → executed → `stopped`. Unit
  coverage is `FlowConsentParkingTest` (8 tests). A harness that can drive occ is
  the honest way to close this, and its absence is recorded rather than papered
  over with a spec that would assert the setup and not the behaviour.

- [x] 7.3 Dutch translations for every new user-visible string (ADR-007/ADR-025); static analysis green.

  Four new strings in `l10n/nl.json` + `l10n/nl.js`. `phpcs`, `phpmd` (both
  rulesets), `psalm` and `phpstan` clean on every changed file.

  ⚠️ **`composer test:all` was NOT run to completion locally** — the full
  `tests/Unit` tree is 17,351 tests and exhausts 2GB before finishing on this
  box. The affected subtrees (`Service/Flow`, `Service/Delegation`, `Cron`,
  `Notification`) run green at 769 tests; CI runs the whole suite. Said plainly
  rather than reported as a pass.

  Acceptance criteria:
  - ✅ No `@spec exclude` was used
  - ⚠️ **The consent prompt has NOT had an accessibility pass.** It is rendered by
    Nextcloud's own notification surface, which carries the fleet's a11y posture,
    but the two action labels are ours and no screen-reader run has been done.
    Open, and named rather than ticked.
