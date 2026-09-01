# Tasks: flow-approval-consolidation

## 1. Storage

- [ ] 1.1 Migration: create `openregister_task_sequences` (`uuid`,
      `template_id`, `template_version`, `template_snapshot`,
      `anchor_object_uuid`, `register_id`, `schema_id`, `chain_key`,
      `requester_id`, `resolved_tier`, `position_cursor`, `status`, `outcome`,
      `run_uuid` nullable, `node_id` nullable, `opened_at`, `closed_at`) with
      indexes on `(anchor_object_uuid, template_id)` and
      `(status, template_id)`; add `sequence_uuid` + `sequence_position` +
      `legacy_step_id` to `openregister_tasks`; add `migrated_task_uuid` to
      `openregister_approval_steps`; add `correlation_key` to
      `openregister_flow_runs` with an index on `(status, correlation_key)`.
      No table is dropped.
- [ ] 1.2 `lib/Db/TaskSequence.php` + `TaskSequenceMapper` (find by uuid, find
      the running sequence for an anchor+template, list an anchor's sequences
      newest-first, list positions in ordinal order). Ordinals are stable and
      unique within a sequence — enforced by a unique index, not by the
      service, because two writers provisioning the same gated write is the
      concurrent case.

## 2. The sequence

- [ ] 2.1 `lib/Service/Task/TaskSequenceService.php`: provision (create every
      position, enable only the first, freeze `template_snapshot` and
      `resolved_tier`), advance (enable the next position in the SAME request
      as the completing decision — the behaviour at
      `lib/Service/ApprovalService.php:193-204`), reject (terminate the
      sequence and every non-terminal task it owns with a reason naming the
      rejecting position), and terminate. Each verb goes through
      `TaskService`'s authorized lifecycle verbs; none writes a task row
      directly.
- [ ] 2.2 Sequence authorization: separation of duties resolved from the
      chain's declarative entry, defaulting to ON when the entry exists
      (`ApprovalService.php:371-397`), evaluated against the acting identity
      AND the `on_behalf_of` identity, refused BEFORE the performer check so
      the reason is honest (`ApprovalService.php:165-168`); rejecting outcomes
      require a comment.
- [ ] 2.3 `lib/Event/TaskSequenceCompletedEvent.php`, dispatched at the moment
      the retired `ApprovalStepCompletedEvent` was — the final position
      completing with an approving outcome — carrying sequence, final task,
      decider and resolved approving status.

## 3. The declarative surface, re-pointed

- [ ] 3.1 `ApprovalChainAnnotationInstaller` compiles
      `x-openregister-approval-chains` into a task TEMPLATE instead of an
      `ApprovalChain` row (`:134-175`), idempotent so a re-save produces no
      second template and no new template version. The annotation's declared
      shape and its vocabulary registration are unchanged.
- [ ] 3.2 `ApprovalChainGateListener` asks the SEQUENCE whether the approval is
      complete instead of scanning step rows (`:225-244`); keeps
      `approval-chain-pending` and `approval-chain-misconfigured` verbatim;
      keeps failing closed on an unprovisionable chain (`:200-206`); CLOSES a
      rejected sequence and opens a new one instead of deleting rows
      (`:232`); freezes the amount tier at provisioning
      (`resolveStepsOverride()`, `:277-300`) so a mid-cycle amount edit cannot
      re-route a running approval.
- [ ] 3.3 `ApprovalChainAdvanceListener` subscribes to
      `TaskSequenceCompletedEvent`; the `onApprove: advanceTransition` lookup
      and the fail-soft `TransitionEngine::transition()` call (`:110-121`) are
      otherwise unchanged.

## 4. Retirement

- [ ] 4.1 Delete `lib/Db/ApprovalChain.php`, `ApprovalChainMapper.php`,
      `ApprovalStep.php`, `ApprovalStepMapper.php`,
      `lib/Service/ApprovalService.php`,
      `lib/Controller/ApprovalController.php`, the four
      `lib/Event/ApprovalStep*Event.php`, and the nine routes at
      `appinfo/routes.php:1231-1240`. No facade, alias or deprecation shim.
      Assert the removal left no orphan route entry, service registration or
      listener registration.
- [ ] 4.2 Delete `src/components/workflow/ApprovalChainPanel.vue` and
      `ApprovalStepList.vue`; `src/views/schemas/SchemaWorkflowTab.vue:42`
      mounts the task-sequence panel instead. Deciding happens in the task
      inbox, not in a schema tab.
- [ ] 4.3 Publish the event replacement mapping as migration documentation:
      per retired event, the replacement, the replacement for EVERY field it
      carried, the ordering guarantee, and any field with no replacement named
      as such. Referenced by `filinq: migrate-signing-to-or-tasks`.

## 5. Correlation

- [ ] 5.1 `correlationKey` added to `AwaitSignalNode::configKeys()`
      (`lib/Service/Flow/Nodes/AwaitSignalNode.php:180`) and its config form,
      resolved from items/context and written to the run's indexed
      `correlation_key` at suspension; one new route beside
      `appinfo/routes.php:1312` delivering a signal by key, resolving
      fail-closed (0 matches → not found and NOT buffered; >1 → ambiguous and
      wakes nothing) with the same authority as `resume()` and no ability to
      complete, claim or advance a user task.

## 6. Data migration

- [ ] 6.1 Repair step: chains → templates; each `(chain_id, object_uuid)` →
      one sequence; each step → a task at its `stepOrder` with `role` as
      candidate group and performer type `group`, `requesterId` as sequence
      requester, `created` preserved, `pending` → enabled, `waiting` →
      not enabled, `approved`/`rejected` → terminal; terminal steps append a
      migrated task-audit entry attributed to the ORIGINAL decider with the
      original comment and time. Reconciliation columns written on both sides;
      every stage guarded on them so a second run changes nothing.
- [ ] 6.2 In-migration verification that FAILS LOUDLY: every non-terminal step
      has exactly one non-terminal task; every chain has exactly one template;
      no (object, template) has two running sequences; every migrated task's
      ordinal equals its step's `stepOrder`; enabled-task count equals the
      count of steps that were `pending`. Any mismatch names chain, object and
      step and stops the migration. The cutover timestamp is recorded where an
      operator will find it.
- [ ] 6.3 Reverse repair step for the post-cutover rollback path: write
      migrated tasks' decisions back onto their originating step rows for the
      fields the legacy schema can express, and REPORT the fields it cannot
      (performer type, on-behalf-of, mandate, per-entry audit). Dropping the
      legacy tables is NOT part of this change and MUST NOT be part of any
      rollback path.

## 7. Contracts

- [ ] 7.1 openconnector HITL retirement inventory as a checked-in fixture with
      a test asserting it covers every property of
      `../openconnector/lib/Settings/register.d/hitl-approval-rule-action.json`:
      approver group, requester, comment, `expiresAt` + `onTimeout` (to
      `flow-business-timers`), `onReject` (to the sequence's rejecting
      outcome, with `skip` given the behaviour its enum always promised and
      `../openconnector/lib/Service/ApprovalService.php:662` never gave it),
      and `consumedAt`. A property with no named home fails the test.
- [ ] 7.2 The leaf migration contract as the spec's enforceable rules, handed
      to the hydra-gates anti-pattern gate: no home-grown step engine, no own
      approver-group resolution, no stored `overdue`, no schema mirroring
      flow-definition or task fields, and a hard finding for any call to a
      removed approval route or listener registration for a removed event
      class. Includes the flow-definition rule hermiq's
      `agentflow`/`agentflowrun` (`../hermiq/lib/Settings/hermiq_register.json:3589,3678`)
      violate; their removal is `hermiq: retire-agentflow-object-store`.

## 8. Tests

- [ ] 8.1 Sequence, gate and correlation tests: one position enabled at a
      time; advance inside the completing request; rejection terminating every
      remaining task; rejection without a comment refused; a delegated
      self-approval refused; a frozen tier surviving a mid-cycle amount edit;
      a rejected cycle still readable after resubmission; and the four
      correlation cases (hit, ambiguous, unmatched-and-not-buffered, cannot
      decide a user task).
- [ ] 8.2 Migration and regression: the repair over a seeded database with
      in-flight, rejected and fully decided chains, run twice with identical
      results; verification failing loudly on a corrupted fixture; the reverse
      repair reporting what it cannot carry; and a full pass with opencatalogi
      and softwarecatalog installed proving they declare no approval chain and
      are unaffected — asserted, not assumed.

## Acceptance criteria

- No code path can decide a migrated approval through the retired engine. A
  grep for the removed class names and route paths returns matches only in
  migration and repair code.
- Every schema in the fleet that declared `x-openregister-approval-chains`
  before this change declares it identically after, with no edit, and its gate
  still refuses the transition it refused before.
- Every in-flight approval survives the migration at the same ordinal, with
  the same role, requester and creation time, and appears in exactly one
  inbox. The count of enabled tasks after migration equals the count of steps
  that were `pending` before it.
- No approval can be decided twice. A second decision on a terminal task is
  refused with a reason naming its terminal state.
- A rejected approval, its comment and its decider are still readable after a
  resubmission opens a new sequence.
- The migration either completes with its verification passing or stops with a
  message naming the chain, the object and the step. It never reports a
  partial success.
- A correlation-addressed signal wakes exactly one run or none. It never picks
  between two candidates and never completes a user task.
- Every property of openconnector's `approval_request` has a named home, or an
  explicit recorded decision not to carry it. The runner cannot be retired
  otherwise.
- `openregister.await-signal` behaves identically to before for every flow
  that does not declare a correlation key.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- New PHP files carry `@license EUPL-1.2` and `@copyright 2026 Conduction B.V.`
- `@spec` annotations point at
  `openspec/specs/flow-approval-consolidation/spec.md` and
  `openspec/specs/approval-workflow/spec.md` anchors.
- References ADR-098 D1 (the consolidation half), D2, D3, D6; ADR-065;
  ADR-022 (the rule this makes enforceable); ADR-031 (argued in design.md, not
  assumed); ADR-005 (fail-closed authorization); ADR-001 (no seed data, argued
  in design.md).
- No leaf app is edited. procest, decidiq, pipelinq, planix, buildiq, filinq,
  hermiq and openconnector migrations are named as follow-ups and shipped
  elsewhere.
- No partial hook for skip, quorum, parallel positions or per-step deadlines
  is left behind.
