# Tasks: flow-definition-versioning

## 1. Storage

- [ ] 1.1 Migration: `version` (int, notnull, default 1) + `lifecycle_status`
      (string 16, notnull, default `draft`) on `openregister_flows`;
      `flow_version` (int, nullable) on `openregister_flow_runs`; new
      `openregister_flow_versions` (`flow_uuid`, `version`, `status`, `nodes`,
      `edges`, `limits`, `execution_mode`, `owner`, `organisation`,
      `published_at`, `published_by`, `deprecated_at`) with a UNIQUE index on
      `(flow_uuid, version)` and an index on `(flow_uuid, status)`.
- [ ] 1.2 `lib/Db/FlowVersion.php` + `FlowVersionMapper` (find by flow+version,
      find the single published version, list a flow's versions); `Flow` and
      `FlowRun` entities extended with the new fields and their accessors.
- [ ] 1.3 Repair step in the same migration: publish version 1 of every
      existing flow from its stored graph, set its head to `published`, and
      stamp `flow_version = 1` on every non-terminal run. Guarded on existence
      so a second run changes nothing.

## 2. Lifecycle

- [ ] 2.1 `FlowLifecycleGuard` — the preconditions, modelled on
      `procest/lib/Service/Workflow/WorkflowLifecycleGuard.php:53-57`: only a
      draft may be published, only after the dead-end preflight passes on the
      graph being published; only a published version may be deprecated; a
      version with a non-terminal run pinned to it may not be deleted. Every
      refusal logged with its reason.
- [ ] 2.2 `FlowVersionService` — the transitions: publish (snapshot the head,
      deprecate the predecessor, rebuild the trigger set) and create-draft
      (copy the published graph to version N+1, head back to `draft`), each in
      ONE transaction so a flow is never observed with two published versions
      or none.
- [ ] 2.3 Trigger-set rebuild wired to publish/deprecate only:
      `openregister_flow_triggers` rows are derived from the published version
      and from nothing else; the table keeps its columns and
      `or_flowtrig_match_idx` unchanged.

## 3. Pinning and resolution

- [ ] 3.1 `FlowRunService::queue()` (`lib/Service/Flow/FlowRunService.php:321`)
      resolves the published version, writes it onto the run, and refuses with
      a named reason when there is none. All six dispatch paths inherit it —
      assert that with a test per path rather than by reading the code.
- [ ] 3.2 `refuseDeadEnd()` preflights the version being pinned, not
      `$flow->getNodes()` off the head, so a broken draft cannot refuse a run
      of a sound published version and a broken published version cannot be
      masked by a repaired draft.
- [ ] 3.3 `FlowLocator::resolveFlow()` takes a version; memo key becomes
      flow + version (`FlowLocator.php:89-93` is keyed by flow alone today and
      would serve one run's graph to another in the same worker batch).
- [ ] 3.4 `FlowRunAdvancer.php:92` resolves the run's pinned version, and `:98`
      gains a second, distinct refusal naming flow AND version. No fallback to
      head or to the latest published version on any path.
- [ ] 3.5 Interactive test run of a draft carries the resolved draft document
      on the run context; the advancer prefers that snapshot when present.
      Test runs are the only runs that carry one, and are marked as such where
      runs are listed.

## 4. Callers

- [ ] 4.1 `SubFlowNode::execute()` (`lib/Service/Flow/Nodes/SubFlowNode.php:209`)
      resolves the CHILD flow's published version at call time and pins it on
      the child run, for both the waiting and fire-and-forget shapes; a child
      with no published version fails the step naming the flow and its state.
      Apps that SHIP flow definitions (hermiq, procest, openconnector under
      ADR-098 Decision 1) publish version 1 on install, so a shipped flow is
      never delivered as an unrunnable draft.

## 5. API and editor

- [ ] 5.1 `PUT /api/flows/{id}` (`appinfo/routes.php:539`) refuses a definition
      write against a published head with a 409 carrying a machine-readable
      reason; the stored graph is left untouched.
- [ ] 5.2 New routes and controller methods: create-draft, publish, deprecate,
      list a flow's versions, read one version — each with its Nextcloud auth
      attribute and each placed so no literal segment can be captured as a
      flow uuid (the trap `appinfo/routes.php:529` already warns about).
- [ ] 5.3 Editor: `FlowDetailPage.vue` read-only for a published or deprecated
      version with a "create draft version" action; `FlowDetailSidebar.vue`
      shows version + lifecycle badge and the version list; run detail shows
      the pinned version and marks a run on a deprecated version.

## 6. Tests

- [ ] 6.1 Pinning tests: a run pinned before a publish executes the old graph;
      two runs on different versions advance correctly in one worker batch
      (the memo-key regression); a suspended run resumes on its own version
      after a rename that would have dangled its marking.
- [ ] 6.2 Failure tests: a deleted pinned version fails the run with the
      version-specific message, never re-points it at a newer version, and
      never leaves it queued.
- [ ] 6.3 Lifecycle, migration and regression: one published version per flow
      through publish/deprecate cycles; a draft's trigger nodes match nothing;
      migration back-fill over a seeded database with in-flight runs, applied
      twice with identical results; and a pass with opencatalogi and
      softwarecatalog installed proving their flows still resolve by uuid and
      still run after the migration.

## Acceptance criteria

- No code path resolves a flow definition for a queued run without a version.
  A grep for `resolveFlow(` returns only version-aware callers plus the two
  documented head callers (draft test run, editor preview).
- A run's executed graph is a function of its `flow_version` alone. Publishing,
  deprecating or deleting anything while a run is in flight changes what that
  run does in exactly zero cases.
- A pinned version that cannot be resolved produces a failed run whose error
  names the version. It never produces a completed run.
- The trigger match path still touches one table with one index; no version
  column was added to `openregister_flow_triggers`.
- An author cannot edit a published flow anywhere — the API refuses it and the
  editor does not offer it.
- After migration, every flow has exactly one published version and no
  non-terminal run has a null `flow_version`.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- New PHP files carry `@license EUPL-1.2` and `@copyright 2026 Conduction B.V.`
- `@spec` annotations point at
  `openspec/specs/flow-definition-versioning/spec.md` anchors.
- References ADR-098 Decision 6 (versioning before humans), ADR-065 (the
  lifecycle lands in the one engine), ADR-031 (the imperative guard is argued
  in design.md, not assumed).
- In-flight instance migration is NOT implemented here and no partial hook for
  it is left behind.
