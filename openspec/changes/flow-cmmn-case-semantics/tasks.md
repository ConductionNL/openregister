# Tasks: flow-cmmn-case-semantics

## 1. Storage

- [ ] 1.1 Migration creating `openregister_case_items` and
      `openregister_case_item_audit` with the columns and indexes in
      design.md — Data model. Additive only: no existing table altered, no
      data backfilled, and `openregister_tasks` gains NO column. Verify
      neither table has an `overdue`, `days_until_due` or `days_overdue`
      column.
- [ ] 1.2 Entities + mappers under `lib/Db/`: `CaseItem`/`CaseItemMapper`,
      `CaseItemAudit`/`CaseItemAuditMapper`, following `lib/Db/FlowRun.php`
      conventions (docblock `@method` block, `@spec` tag, `@license
      EUPL-1.2` + `@copyright 2026 Conduction B.V.`, `jsonSerialize()`).
      `CaseItemAuditMapper` exposes NO update and NO delete method. Tree,
      anchor and "stuck where" reads are mapper queries — filtering,
      sorting, pagination and totals all in the datastore.

## 2. Plan-item lifecycle

- [ ] 2.1 `lib/Service/Case/CasePlanTransitions.php` — pure, stateless,
      injected as a collaborator: the six states, the three types, the
      exhaustive per-type edge table and the explicit terminal set, ported
      from `procest/lib/Service/Cmmn/PlanItemTransitions.php:41-97`
      including the milestone asymmetry (two edges only). `assertLegal()`
      throws naming item, type, from-state and to-state; a same-state
      "transition" is illegal.
- [ ] 2.2 `lib/Service/Case/CasePlanStateMachine.php` — one transition,
      `is_terminal` written in the same statement as `state`, audit row
      appended in the SAME transaction as the mutation (successes AND
      denials, `authorized: false`), and stage-exit cascade: non-terminal
      children terminated, unentered children disabled, each individually
      audited with `cause: cascade` and the parent uuid as `cause_ref`.
      References `PlanItemStateMachine.php:121-170`.

## 3. Sentries

- [ ] 3.1 Append `case.item.completed`, `case.item.terminated`,
      `case.item.disabled` to `EventCatalogService::CATALOG`
      (`lib/Service/Flow/EventCatalogService.php:52-69`) and emit them from
      the state machine. Purely additive: `aliasesFor()` behaviour unchanged
      and no row enters `openregister_flow_triggers`.
- [ ] 3.2 `lib/Service/Case/CaseSentryEvaluator.php` — AND within a sentry,
      OR across the criteria array; on-part resolved against current
      plan-item state for the monotonic terminal events and against the
      event being handled otherwise; if-part evaluated ONLY through
      `FlowExpression::isTrue()` over a `dataFor()` document extended with a
      `case` key. No new operator vocabulary, no second evaluator. A
      malformed sentry never fires. Save-time validation lands here too: a
      sentry naming an event outside `knownTriggerIds()`
      (`EventCatalogService.php:85`) is REFUSED naming the event, and an
      if-part is checked with `FlowExpression::isValid()` — the editor
      fails, not the run.

## 4. Item kinds and completion rules

- [ ] 4.1 `lib/Service/Case/CasePlanCascade.php` — the fixpoint evaluation
      loop with a named bound (reference: `PlanItemCascade.php:64`,
      `MAX_CASCADE_DEPTH = 50`), failing loudly at the bound and rolling
      back rather than leaving a half-cascaded plan.
- [ ] 4.2 Stage semantics: nesting to arbitrary depth, a child never
      actionable while its parent is not `active`, and auto-completion when
      every REQUIRED child is terminal AND no child is `active` — including
      the `$mandatoryFound` guard so a stage with only optional children
      does NOT auto-complete on activation
      (`PlanItemTree.php:98-117`). Milestones land here too: no work
      performed, completion immediate on entry, and completion emits the
      catalog event so another item's sentry can consume it.
- [ ] 4.3 Repetition: a repeating plan item produces a new realisation per
      repetition while remaining ONE plan item, each realisation
      individually addressable via `realisation_count`; the item is terminal
      only when the rule is exhausted AND every realisation is terminal.

## 5. Realisation

- [ ] 5.1 A `humanTask` item entering `active` creates a task through the
      task capability, carrying the case anchor triple, the candidate
      users/groups/role and the deadline values. The task's terminal outcome
      drives the item's terminal state; the item writes to the task ONLY to
      terminate it on exit or cascade. Nothing else in either direction.
- [ ] 5.2 A `stage` bound to a flow queues a run through
      `FlowRunService::queue()` (`lib/Service/Flow/FlowRunService.php:321`)
      against the flow's pinned published version; the run's terminal status
      drives the item. Assert by dependency direction that nothing under
      `lib/Service/Flow/` depends on `lib/Service/Case/`.

## 6. Discretionary, ad-hoc and authorization

- [ ] 6.1 `lib/Service/Case/CasePlanAuthorizationService.php` — DECIDES
      fail-closed before any write, denying on indeterminate (unresolvable
      role, unavailable group backend). No nullable "could not determine"
      return a caller can read as "check skipped". Contrast the reference,
      which returns a list for the REST layer to compare
      (`CaseModelEngine.php:244-268`).
- [ ] 6.2 Enable-a-discretionary-item and attach-an-ad-hoc-item verbs, plus
      the enableable-items query (discretionary, entry-satisfied, parent
      `active` — `CaseModelEngine.php:269-276`). An ad-hoc item derives its
      authorization from its parent stage or the plan root and CANNOT
      declare itself unguarded; creating one modifies no flow definition and
      creates no definition version.

## 7. Write-through and API

- [ ] 7.1 Business-state write-through: the anchoring object's status and
      result are mirrored via the ordinary object-write path so
      `x-openregister-lifecycle` governs them and `object.transitioned`
      fires as usual. One-directional — an object write is never read back
      as a plan-item transition, though it may satisfy a sentry. Deleting
      plan items leaves mirrored state intact.
- [ ] 7.2 `lib/Controller/CaseController.php` + `appinfo/routes.php`: read
      the plan by object uuid, transition an item, enable a discretionary
      item, attach an ad-hoc item, list enableable items, list cases by item
      type and state. Every method declares its auth posture attribute AND
      routes its real check through `CasePlanAuthorizationService` — the
      attribute is never the whole check. No CMMN XML endpoint exists.

## 8. Zaaktype import

- [ ] 8.1 `lib/Service/Case/ZaaktypeCaseSkeletonMapper.php` — a PURE
      transformation over a supplied zaaktype document, no HTTP: ordered
      `statustypen` → milestones in sequence order; `roltypen` → candidate
      roles preserving the generic designation; `resultaattypen` → the
      constrained end-state set (completing outside it is refused);
      `doorlooptijd`/`servicenorm` carried onto items and NOT computed on.
      Includes the mapping report and the draft rule: every unmapped and
      approximately-mapped element named with what the author should do,
      nothing silently dropped or guessed, and the produced skeleton marked
      as a draft that only an explicit publish makes live. Its endpoint is
      routed with 7.2 and carries the same authorization posture.

## 9. Seed data

- [ ] 9.1 Install the six seed fixtures from design.md — Seed Data (the
      two-stage permit case, the discretionary advice item, the ad-hoc item
      on a run-less task, the terminated stage with its cascade audit rows,
      the repeating item with two realisations, and the zaaktype fixture
      with out-of-order sequence numbers and unmappable elements) through
      the existing seeding path, idempotent on uuid, nil-placeholder UUIDs
      and obviously fake uids.

## 10. Tests

- [ ] 10.1 Table-driven unit tests for the transition table (every legal
      edge, and the illegal ones naming all four facts — including
      milestone → `active` and any transition out of a terminal state) and
      for sentry evaluation (AND-within / OR-across, an unevaluable if-part
      being FALSE, an unknown event refused at save time, a malformed sentry
      never firing).
- [ ] 10.2 Structural and authorization tests: a stage with only optional
      children staying open; the cascade bound failing loudly and rolling
      back; a non-member denied on enable and on attach with the denial
      audited; an unresolvable role denying; two overlapping transitions on
      different items both succeeding with their own audit rows; the
      invariant that a plan item is terminal iff all its realisations are. Also
  here: a run's `marking`, `status` and `log` byte-identical across a full
  case-plan evaluation; the zaaktype fixture producing sequence-ordered
  milestones, a refused out-of-set result and a report naming both
  unmappable elements; and opencatalogi + softwarecatalog suites green
  (additive-migration-only consumers — the check is that no shared service
  signature changed).
- [ ] 10.3 Playwright coverage for the six `@e2e`-marked scenarios in
      `specs/flow-cases/spec.md`: reading a case plan by object uuid, a
      milestone satisfying another item's sentry, terminating a stage
      cascading to its children, completing a task completing its plan item,
      attaching an ad-hoc item, and the object carrying mirrored business
      status.

## Acceptance criteria

- No case identity exists other than the anchoring object's. Every plan item
  is reachable by object uuid, and a case plan works for an object that has
  never had a flow run.
- No plan state is stored as an encoded document anywhere. "Which cases have
  an active item of type X?" is answered by an indexed query with the total
  computed in the datastore.
- Nothing under `lib/Service/Flow/` references `lib/Service/Case/`, and a
  full case-plan evaluation leaves every run's marking, status and log
  unchanged.
- A discretionary or ad-hoc item is created without editing any flow
  definition and without creating any definition version.
- Every enable and attach verb denies before writing when the authorization
  answer cannot be determined; no verb is reachable by knowing a uuid alone.
- The only condition language used by a sentry if-part is the engine's
  existing JSONLogic; grep finds no second operator vocabulary under
  `lib/Service/Case/`.
- No endpoint, service or test parses or emits CMMN XML.
- `openregister_tasks`, `openregister_flows`, `openregister_flow_runs` and
  `openregister_flow_state` are unchanged by this work, and procest's own
  CMMN implementation is untouched.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- Every new PHP file carries `@license EUPL-1.2` and
  `@copyright 2026 Conduction B.V.`; every public/protected method carries a
  `@spec openspec/specs/flow-cases/spec.md` anchor.
- Regression check against opencatalogi and softwarecatalog: both are
  additive-migration-only consumers here, so the check is that their suites
  are green and no shared service signature changed.
- Depends on `flow-task-entity` (a human plan item's realisation IS a task,
  and its optional `run_uuid`/`node_id` is what makes an ad-hoc item
  expressible) and transitively on `flow-definition-versioning` (whose
  immutable published version is why a runtime-added item must be a row).
- References ADR-098 (D4 CMMN semantics lead, D1 one engine, D2 the task
  entity), ADR-065 (D6 concepts not notation, D8 no leaf-app engines),
  ADR-031 (declarative-vs-imperative, design.md D-1), ADR-001 (seed data),
  ADR-022 (the register owns business state), ADR-011 (reuse before
  implement — `FlowExpression`).
