---
kind: code
---

## Why

ADR-022's declarative-capability table lists `x-openregister-approval-chains` as an
OpenRegister-owned abstraction, but `grep -rn 'openregister-approval' lib/` returns
**zero hits** — no code ever reads that schema key. It is a phantom key, but NOT a
phantom *feature*: OpenRegister already ships a full imperative approval-chain
engine (`ApprovalChain`/`ApprovalStep` entities, `ApprovalService`,
`ApprovalController`, four typed events — capability `approval-workflow`, `status:
done`), reachable only through admin-authored `POST /api/approval-chains` CRUD. The
schema-declarative *entry point* into that engine was simply never built. shillinq
already authored the consumer side of the missing entry point:
`lib/Settings/register.d/bookkeeping-bcf-vat-compensation.json` declares
`x-openregister-approval-chains` on `BcfClaim`, and
`bookkeeping-verplichtingenadministratie.json`'s `Goedkeuringsstap` schema
documents itself as "Created by `MandaatEnforcer` when a verplichting moves to
`in_goedkeuring`" — but `MandaatEnforcer.php` (~339 lines) only resolves mandate
*thresholds*; it never calls into the approval engine, never blocks a transition,
never records a decision. Per ADR-022, wiring the declarative key to the existing
engine belongs in OR, not as imperative PHP in leaf apps.

## What Changes

- **New annotation installer** `ApprovalChainAnnotationInstaller` (mirrors
  `NotificationsAnnotationInstaller` exactly: `SchemaCreatedEvent`/
  `SchemaUpdatedEvent` listener, upserts DB rows from a schema's declarative
  block). For every entry in a schema's `x-openregister-approval-chains`, upserts
  an `ApprovalChain` config row (`name` = the chain key, `schemaId`, `steps` seeded
  from the declared `approvers`) — the SAME row shape `POST /api/approval-chains`
  already produces, just provisioned declaratively instead of by hand.
- **New guard listener** `ApprovalChainGateListener` on `ObjectUpdatingEvent`,
  registered directly after `LifecycleValidationListener` (same event, same
  schema-parse-off-`Schema::getConfiguration()` pattern every `x-openregister-*`
  engine uses). When the matched lifecycle transition is named by a declared
  chain's `transition` key: no steps yet for this object → resolve the
  applicable approver tier (role list, or the single amount-routed tier when
  `amountField` is declared), call `ApprovalService::initializeChain()` with that
  resolved tier and **block** the transition (`HookStoppedException` via the
  existing `stopPropagation()` path, same as `lifecycle-guard-denied`); steps still
  in progress → block again (no duplicate rows); all steps `approved` → **release**
  the transition; the latest cycle was `rejected` → clear it and open a fresh
  cycle (resubmission).
- **`ApprovalService::initializeChain()`** gains two additive, backward-compatible
  parameters: `$requesterId` (stamped onto each created `ApprovalStep`, new nullable
  `requester_id` column) and `$stepsOverride` (the amount-routed tier list, when it
  differs from the chain's static declared steps). Existing callers (unchanged
  signature-compatible) keep exact existing behaviour.
- **`ApprovalService::approveStep()`/`rejectStep()`** enforce separation of duties:
  when the chain's schema declares a matching `x-openregister-approval-chains`
  entry (default `separationOfDuties: true`), a decision whose `userId` equals the
  step's `requesterId` is rejected. Chains with no matching declarative entry
  (the pre-existing pure-CRUD flow) are completely unaffected — this only engages
  when a declaration exists.
- **New listener** `ApprovalChainAdvanceListener` on the EXISTING
  `ApprovalStepCompletedEvent`: when the completed chain's schema declares
  `onApprove: advanceTransition` for that chain, it calls
  `TransitionEngine::transition($objectUuid, $transition)` — the SAME action the
  gate blocked — so the parent object's lifecycle field advances automatically.
- **No new controller, no new routes.** `GET /api/approval-steps?objectUuid=`
  and `POST /api/approval-steps/{id}/approve|reject` already cover "list pending
  chains for an object" and "decide". This change is pure wiring onto the existing
  HTTP surface.
- **Resolves the phantom**: shillinq's `bookkeeping-bcf-vat-compensation.json`
  declaration becomes real and engine-backed. shillinq is **not** touched in this
  change — `MandaatEnforcer` keeps doing mandate-threshold resolution (a
  legitimate ADR-031 exception-path guard); migrating its `in_goedkeuring` routing
  onto this primitive and retiring the undead `Goedkeuringsstap` doc-comment is a
  named follow-up (`migrate-mandaat-to-approval-chains`, shillinq repo).

## Capabilities

### New Capabilities
<!-- none -->

### Modified Capabilities
- `approval-workflow`: adds a declarative entry point
  (`x-openregister-approval-chains` on a schema) that provisions chains, gates a
  named lifecycle transition until the chain completes, enforces separation of
  duties, routes to an amount-threshold tier, and can auto-advance the gated
  transition on completion — layered on the existing CRUD/step/event engine with
  zero behavioural change to schemas or chains that don't declare it.

## Impact

- **Code**: `lib/Service/ApprovalChainAnnotationInstaller.php`,
  `lib/Listener/ApprovalChainGateListener.php`,
  `lib/Listener/ApprovalChainAdvanceListener.php`,
  `lib/Service/ApprovalService.php` (additive params + separation-of-duties),
  `lib/Db/ApprovalStep.php`/`ApprovalStepMapper.php` (new `requesterId` field +
  `deleteByChainAndObject()`), `lib/Db/ApprovalChainMapper.php` (new
  `findBySchemaAndName()`), `lib/Migration/Version1Date20260714010000.php`,
  `lib/AppInfo/Application.php` (three listener registrations).
- **DB migration**: one additive nullable column (`requester_id` on
  `openregister_approval_steps`). No data migration, no breaking change.
- **APIs**: unchanged surface; `/transition` can now additionally reject with
  `approval-chain-pending`.
- **Consumers**: shillinq's `bcf-vat-compensation` declaration goes live with no
  shillinq code change. `bookkeeping-verplichtingenadministratie`'s
  `MandaatEnforcer`/`Goedkeuringsstap` migration is an explicit **follow-up**.
- **No breaking change**: schemas/chains without a matching
  `x-openregister-approval-chains` entry are completely unaffected.
