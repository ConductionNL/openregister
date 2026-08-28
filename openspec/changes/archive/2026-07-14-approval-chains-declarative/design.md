## Context

ADR-022 requires leaf apps to consume OpenRegister declarative capabilities rather
than reimplement them in PHP. `x-openregister-approval-chains` is listed in
ADR-022's capability table with zero implementation (`grep -rn
'openregister-approval' lib/` → no hits on `origin/development`). The FIRST research
step (house rule) turned up something the task brief didn't anticipate: OR already
ships a complete imperative approval engine — `ApprovalChain`/`ApprovalStep`
entities, `ApprovalService` (initialize/approve/reject), `ApprovalController`
(CRUD + decide), four typed events (`ApprovalStepInitiatedEvent`/
`ApprovedEvent`/`RejectedEvent`/`CompletedEvent`) — capability `approval-workflow`
(`openspec/specs/approval-workflow/spec.md`, `status: done`). It is reachable only
through hand-authored `POST /api/approval-chains`; nothing parses a schema's
`x-openregister-approval-chains` block to provision or gate anything. So the
phantom here is narrower than "the whole feature is missing" — it is "the
declarative *entry point* into an otherwise-complete engine is missing". Building a
second, parallel chain/step engine next to the existing one would itself be an
ADR-022 violation (two ways to do the same thing). This change wires the existing
engine to the schema annotation instead.

## Goals / Non-Goals

**Goals**: provision `ApprovalChain` config from a schema's
`x-openregister-approval-chains` declaration; block a named lifecycle transition
until the provisioned chain's steps are all `approved`; route to a single
amount-threshold tier when declared; enforce approver ≠ requester when declared;
auto-advance the gated transition on chain completion; reuse the existing engine's
audit trail (`workflow_executions` via `WorkflowExecutionMapper`) unchanged.

**Non-Goals** (named follow-ups): a UI inbox (leaf-app concern, same as
`/available-actions` has no OR-shipped UI); `onApprove` strategies other than
`advanceTransition`; `min > 1` (multiple distinct approvers required within a
single tier) — the existing `ApprovalStep` model is one-decision-per-step, so a
declared tier maps to exactly one step regardless of `min`; a background timeout
sweep for `timeoutDays` (accepted in the schema shape, not enforced yet); migrating
`MandaatEnforcer`/`Goedkeuringsstap` in shillinq (separate change,
`migrate-mandaat-to-approval-chains`).

## Which existing OR declarative pattern this mirrors

Studied before writing any code (per house rules):

- `lib/Service/Notification/NotificationsAnnotationInstaller.php` — the **exact**
  shape for "provision a persisted config entity from a schema's declarative
  block": `IEventListener` on `SchemaCreatedEvent`/`SchemaUpdatedEvent`, reads
  `$schema->getConfiguration()['x-openregister-<key>']`, upserts one DB row per
  declared entry (find-by-name-then-update-or-insert), fail-soft `try/catch` +
  logger warning on upsert failure. `ApprovalChainAnnotationInstaller` copies this
  file's structure line for line, upserting `ApprovalChain` rows instead of
  `Webhook` rows.
- `lib/Listener/LifecycleValidationListener.php` — the schema-parse **gate** hook:
  reads the annotation inside an `ObjectUpdatingEvent` handler, re-derives the
  matched transition from old/new field values, and rejects via
  `$event->setErrors(...); $event->stopPropagation();`.
  `ApprovalChainGateListener` copies this exact shape (own small
  transition-matching helper, own `getConfiguration()` read) rather than
  refactoring the shipped lifecycle listener to expose its private
  `findTransitionByTarget()` — every `x-openregister-*` listener independently
  re-parses the annotation off the schema; there is no shared "annotation
  registry".
- `lib/Service/Lifecycle/TransitionEngine.php` — reused **verbatim** by
  `ApprovalChainAdvanceListener` for the `advanceTransition` strategy; the
  listener does not reimplement mutate+save+dispatch.
  `lib/AppInfo/Application.php:2323` — `ApprovalChainGateListener` is registered
  on `ObjectUpdatingEvent` **immediately after** `LifecycleValidationListener`
  (transition legality must be established before approval-chain gating runs
  against it), following the existing multiple-listeners-per-event convention
  (`:2328` `CalculationOnSaveListener`, `:2334` `QualityScoreOnSaveListener`).
- `lib/Service/Object/PermissionHandler.php` — NOT used for approver eligibility:
  the existing approval engine already has its own role check
  (`ApprovalService::verifyRole()` → `IGroupManager::isInGroup()`), which this
  change leaves untouched rather than swapping in the lifecycle engine's
  `{"role": "<name>"}` → `Schema::getAuthorization()` resolution. Two approval
  concepts (`x-openregister-lifecycle.transitions[*].authorization` and
  `x-openregister-approval-chains[*].approvers[*].role`) intentionally share the
  same NC-group-membership *primitive* (`IGroupManager`) but are resolved through
  each engine's own existing mechanism — approval-chain roles are plain NC group
  ids (matching the existing `ApprovalStep.role` column and `verifyRole()`
  contract), not the lifecycle engine's named-role indirection table.
- `lib/Event/ApprovalStepCompletedEvent.php` — reused, not duplicated: it already
  fires exactly once per chain completion with `getObjectUuid()`. This is the
  correct, already-shipped signal for "release the gate" — no new event type is
  introduced.

## The key could not even persist: `Schema::ANNOTATION_VOCABULARY`

`Schema::setConfiguration()` filters the configuration array against a private
whitelist, `ANNOTATION_VOCABULARY` (`lib/Db/Schema.php:1984`), dropping any
`x-openregister-*` key not declared in it — deliberately, so a typo'd annotation
is caught at save time "instead of silently round-tripping through the
configuration column and having the corresponding listener never fire" (its own
docblock). `x-openregister-approval-chains` was **not** in that list.

This is the load-bearing half of the phantom, and it cuts both ways:

- It is *why* shillinq's declaration was inert beyond "no listener read it" — the
  key never even reached the `configuration` column. Any listener added without
  this entry would read an array that does not contain the key, and no-op forever
  while every test that constructs a `Schema` in-process would show the same
  empty read. A gate listener wired up without this one-line change is a
  **phantom replacing a phantom**.
- It also means OR's anti-phantom guard was working exactly as designed: it
  correctly refused to store a key no engine claimed. Registering the key in the
  vocabulary is therefore part of *declaring the capability real*, not a
  workaround.

Verified empirically before/after (a `Schema` with both `x-openregister-lifecycle`
and `x-openregister-approval-chains` set): before the change `getConfiguration()`
returned only the lifecycle key; after it returns both.

## Contract extracted from shillinq's declaration

`lib/Settings/register.d/bookkeeping-bcf-vat-compensation.json:176-196`
(`BcfClaim.x-openregister-approval-chains`):

```json
"bcf-claim-submit-approval": {
  "transition": "submit",
  "approvers": [ { "role": "bcf-administrator", "min": 1 } ],
  "timeoutDays": 7,
  "onApprove": "advanceTransition",
  "onReject": "notifyOperator",
  "auditEvents": ["task.created", "task.approved", "task.rejected", "task.timeout"]
}
```

This is the de-facto shape OR must honour: a chain names the `transition` it
gates, a list of `{role, min}` approver tiers, and `onApprove`/`onReject`
strategy keys. This change implements `onApprove: advanceTransition` (the only
declared value) as described above. `timeoutDays` is accepted (schema-shape only,
no sweep — Non-Goal). `onReject` strategies beyond "leave the chain's last step
`rejected`, audited via the existing `workflow_executions` row" are a follow-up
once `x-openregister-notifications` gains an `approval-chain.rejected` trigger
source; `min` beyond 1 is a Non-Goal (see above) — every currently-declared
shillinq chain uses `min: 1`, so this is not a regression against any real
declaration.

`bookkeeping-verplichtingenadministratie.json`'s `Goedkeuringsstap` schema
documents itself as "Created by `MandaatEnforcer`" — grep for
`Goedkeuringsstap`/`ApprovalStep`/`WorkflowStep` across `shillinq/lib/*.php`
returns no hits, confirming that claim is itself phantom. This change does not
touch shillinq; the `migrate-mandaat-to-approval-chains` follow-up is where that
schema's fate (drop it in favour of `GET /api/approval-steps?objectUuid=`, or keep
it as a read-projection) gets decided.

## Threshold / tier routing

`approvers` entries may declare `minAmount` (compared against
`object[amountField]`, `amountField` a top-level key on the chain spec). When
`amountField` is present, `ApprovalChainGateListener` selects the **single** tier
with the highest `minAmount` that is `<= object[amountField]` (ties broken by
declaration order) and passes ONLY that tier's role as the `$stepsOverride` to
`initializeChain()` — this is "routing": exactly one required step applies, and
it is resolved **per object**, not baked into the schema-level `ApprovalChain.steps`
row (which stores the full declared tier list for CRUD/admin visibility only, via
the installer). When `amountField` is absent (the shipped `bcf-claim-submit-approval`
shape), every listed `approvers` entry becomes a step in parallel/sequence —
identical behaviour to the persisted chain's own static `steps`, so `initializeChain()`
is called with no override and falls back to `$chain->getStepsArray()` exactly as
it already does for the pure-CRUD flow.

## Separation of duties

`ApprovalService::approveStep()`/`rejectStep()` gain a private
`resolveSeparationOfDuties(ApprovalChain $chain): bool` that loads the chain's
schema (`SchemaMapper::find($chain->getSchemaId())`) and looks for an
`x-openregister-approval-chains` entry whose key equals `$chain->getName()`.
When found, `separationOfDuties` defaults to `true` (fail-safe: a declared chain
enforces separation unless a schema explicitly opts out with
`"separationOfDuties": false`). When NO matching declarative entry exists — the
pre-existing pure-CRUD-provisioned chain, exercised by the current
`ApprovalServiceTest` suite — the method returns `false`, so existing tests and
behaviour are completely unaffected. The check itself: reject when
`$step->getRequesterId() !== null && $step->getRequesterId() === $userId`,
evaluated before the role-membership check so a self-approval attempt gets a
distinct, honest error rather than being masked by (or coincidentally passing)
the group check.

## Declarative vs imperative (ADR-031)

`MandaatEnforcer::resolveApplicableMandate()` walks a `Mandaat` register matching
`soort_verplichting`/`geldig_van`/`geldig_tot`/`is_override` — domain-specific
eligibility logic OR's engine cannot see, correctly an ADR-031 exception-path
guard, not a regression. `x-openregister-approval-chains` covers the generic half
of the split every approval workflow needs once a domain-specific guard (or a
plain amount-field routing rule) has decided "this needs approval": who approves,
how many, whether the approver may be the requester, and what happens to the
parent transition. `MandaatEnforcer` keeps its narrow job; the named follow-up is
where it would become the routing/threshold input rather than being replaced.

## Risks / trade-offs

- **`min > 1` is not enforced.** Declaring `min: 2` on a tier is accepted
  (shape-only) but only one approval is required, matching the existing
  one-decision-per-step engine. Every currently-declared shillinq chain uses
  `min: 1`, so this is not a live regression; flagged as a Non-Goal rather than
  silently mis-implemented.
- **No background timeout sweep.** `timeoutDays` is accepted but nothing expires
  a stale chain. Flagged as a follow-up (mirrors `ScheduledNotificationJob`'s
  `BackgroundJob` pattern) rather than shipped half-wired.
- **`ApprovalChainGateListener` duplicates ~20 lines of transition-matching
  logic** from `LifecycleValidationListener` rather than sharing it — accepted
  trade-off (see pattern section above), to avoid touching the already-shipped
  lifecycle engine in a change whose blast radius should stay confined to the new
  wiring.
- **Rejection clears and re-opens a fresh cycle.** A `rejected` step set for
  (chainId, objectUuid) is deleted by the gate listener on the next attempt at the
  same transition rather than preserved as permanent history in
  `openregister_approval_steps` — full history of a rejected cycle still survives
  in `workflow_executions` (`WorkflowExecutionMapper`), which is the engine's
  existing durable audit trail; `ApprovalStep` rows are working-state, not audit
  log.

## Seed Data

Test fixtures use a minimal schema mirroring the real
`bookkeeping-bcf-vat-compensation` shape without depending on the shillinq repo:

```json
{
  "slug": "test-commitment",
  "properties": { "status": { "type": "string" }, "amount": { "type": "integer" } },
  "authorization": { "roles": {} },
  "configuration": {
    "x-openregister-lifecycle": {
      "field": "status",
      "transitions": { "submit": { "from": "draft", "to": "submitted" } }
    },
    "x-openregister-approval-chains": {
      "submit-approval": {
        "transition": "submit",
        "amountField": "amount",
        "separationOfDuties": true,
        "approvers": [
          { "role": "finance-clerks", "min": 1, "minAmount": 0 },
          { "role": "finance-directors", "min": 1, "minAmount": 100000 }
        ],
        "onApprove": "advanceTransition"
      }
    }
  }
}
```

Test users: `requester` (uid, member of neither approver group), `clerk-1`
(`finance-clerks`), `director-1` (`finance-directors`). Test objects: a
low-amount commitment (`amount: 5000`, routes to `finance-clerks`) and a
high-amount commitment (`amount: 250000`, routes to `finance-directors`).
