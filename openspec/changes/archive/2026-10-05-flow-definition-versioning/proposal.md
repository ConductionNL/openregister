---
kind: code
---

# Proposal: flow-definition-versioning

## Summary

Give a flow definition a **version** and a lifecycle (`draft` / `published` /
`deprecated`), pin the version onto the run at queue time, and make the
advancer resolve THAT version for the whole life of the run. A run stops
being a walk over whatever the graph happens to look like when the worker
next wakes up, and becomes a walk over the definition it started on.

## Why

`FlowRunAdvancer::advance()` re-resolves the live definition on **every**
worker pass:

```php
// lib/Service/Flow/FlowRunAdvancer.php:92
$flow = $this->resolvers->resolveFlow((string)$run->getFlowId());
```

`FlowLocator::resolveFlow()` (`lib/Service/Flow/FlowLocator.php:88-115`)
answers from `FlowMapper::findByUuid()` — the single current row in
`openregister_flows`. There is no version column: the table
(`lib/Migration/Version1Date20260803000000.php:67-126`) has `nodes`,
`edges`, `limits` and nothing that says *which* nodes and edges. So the
definition a run executes is a moving target, and the engine has no way to
notice it moved.

That is tolerable while runs are short. It stops being tolerable the moment
a run can wait for a person. `openregister.await-signal` suspends with
`resumeAt = null` and the run is only reaped after **days**
(`lib/BackgroundJob/FlowRunWorker.php`); a run parked two weeks on an approval wakes
against a graph an author has edited three times since. Two concrete
failures follow:

1. **The marking dangles.** `FlowGraph::inPlace()` makes a node's input
   place its node id, so the run's persisted `marking` is a set of node ids.
   Rename or delete a node while a token sits in it and the marking now
   names a place the rebuilt Petri net does not contain. The run cannot be
   advanced and cannot explain why.
2. **The process silently changes under a decision already taken.** An
   approver answered a question the flow no longer asks. Nothing in the run
   log records that the definition changed mid-run, because nothing knows.

Every other change in the ADR-098 programme makes this worse rather than
better: human tasks, business timers and approval chains all lengthen the
window between "queued" and "finished". Pinning is therefore the hard
prerequisite, and it is ADR-098 Decision 6 — *versioning before humans*.

The lifecycle is not invented here. Procest already runs it in production
for workflow definitions: `lifecycleStatus` with enum
`["draft","published","deprecated"]`
(`procest/lib/Settings/register.d/70-cmmn-case-model.json:26` — "draft =
editable, cannot back new cases. published = immutable, can back new cases
(one active per caseType). deprecated = immutable, existing cases keep using
it"), with the preconditions in
`procest/lib/Service/Workflow/WorkflowLifecycleGuard.php:53-57` and the
transitions in `WorkflowDefinitionService`. This change moves that proven
shape onto OR Flow, which is where ADR-098 says the fleet's one engine
lives.

## What Changes

- **A flow row gets a lifecycle.** `openregister_flows` gains `version`
  (integer, the head's number) and `lifecycle_status`
  (`draft|published|deprecated`, default `draft`). The row keeps being the
  flow's identity: its `uuid` does not move, so `FlowMapper::findByUuid()`,
  `openregister_flow_triggers.flow_uuid` and every `flowId` an app has
  stored keep resolving.
- **Published definitions become immutable snapshots.** A new table
  `openregister_flow_versions` holds one row per published version
  (`flow_uuid`, `version`, `status`, `nodes`, `edges`, `limits`,
  `execution_mode`, `owner`, `organisation`, `published_at`,
  `published_by`). At most **one** `published` row per flow; publishing
  version N+1 deprecates N in the same transaction.
- **The run pins its definition.** `openregister_flow_runs` gains
  `flow_version`. `FlowRunService::queue()`
  (`lib/Service/Flow/FlowRunService.php:321`) resolves the version that will
  back the run and writes it onto the record — every dispatch path (manual,
  object trigger, schedule, MCP, workflow-engine operation, sub-flow) already
  funnels through that one method, so pinning is applied once and covers all
  of them.
- **The advancer resolves the pinned version.**
  `FlowLocator::resolveFlow()` gains a version argument, and its memo
  (`FlowLocator.php:89-93`, keyed by `flowId` alone today) is re-keyed by
  `flowId + version` — otherwise two runs of the same flow on different
  versions in one worker batch would read each other's graph.
  `FlowRunAdvancer.php:92` passes `$run->getFlowVersion()`.
- **A missing pinned version fails loudly.** The existing "No app provides
  flow" refusal (`FlowRunAdvancer.php:98`) is joined by a distinct one that
  names the flow *and* the version. The engine MUST NOT re-point a run at
  another version — silently promoting a run to a newer graph is precisely
  the bug this change removes.
- **Editing a published flow is refused, not merged.** `PUT /api/flows/{id}`
  (`appinfo/routes.php:539`) returns a machine-readable 409 when the head is
  `published`. The author's path is explicit: create a draft (version N+1),
  edit it, publish it. New routes: create-draft, publish, deprecate, list
  versions, read one version.
- **The editor shows which version it is looking at.** A published flow's
  canvas (`src/views/flows/FlowDetailPage.vue`) renders read-only with a
  "Create draft version" action; the sidebar
  (`src/views/flows/FlowDetailSidebar.vue`) carries the version selector and
  lifecycle badge; a run's detail shows the version it is pinned to.
- **Existing flows and in-flight runs are back-filled**, so the upgrade does
  not strand anything — see Impact.

## What does NOT change

- **In-flight instance MIGRATION is explicitly out of scope.** Camunda's
  activity-mapping ("move the token from old node A to new node B, then
  continue on version N+1") is a different feature with a different risk
  profile, and it needs a mapping UI, a validation pass and an audit story of
  its own. This change makes pinning MANDATORY and migration IMPOSSIBLE: a
  run finishes on the version it started on, or it fails visibly. Migration
  is a later change, and it is buildable only once pinning exists.
- **The trigger index stays version-free.** `openregister_flow_triggers`
  (`lib/Migration/Version1Date20260810140000.php:77-101`) keeps its
  `(enabled, event, register, schema_slug)` match index and its `flow_uuid`
  column. Only the published version contributes rows, so a match answers
  "which flow", and `queue()` answers "which version" one step later. Adding
  a version column would put a second dimension on the hot path that every
  object write pays for, to express something the queue path already knows.
- **The Petri-net lowering, merge/join semantics, oversight and the
  `MAX_TRANSITIONS` backstop.** Versioning changes which document is lowered,
  not how.
- **`enabled`.** It stays orthogonal: a published flow can be switched off,
  and a disabled flow is not a deprecated one.

## Capabilities

### New Capabilities
- `flow-definition-versioning`: versioned flow definitions with a
  `draft`/`published`/`deprecated` lifecycle, per-run version pinning at
  queue time, version-faithful resolution for the life of a run, and loud
  failure when a pinned version is gone.

### Modified Capabilities
<!-- None. flow-engine's requirements are unchanged: the engine still lowers a
     document, still refuses dead ends, still matches triggers off the index.
     Which document it lowers is what this new capability governs. -->

## Impact

- **Affected code**: `lib/Db/Flow.php`, `lib/Db/FlowRun.php`, new
  `lib/Db/FlowVersion.php` + mapper; `lib/Service/Flow/FlowLocator.php`
  (version-aware resolve + memo key); `lib/Service/Flow/FlowRunAdvancer.php`
  (pinned resolve, missing-version refusal);
  `lib/Service/Flow/FlowRunService.php` (`queue()` pins; `refuseDeadEnd()`
  must preflight the version being pinned, not the head);
  `lib/Service/Flow/Nodes/SubFlowNode.php:209` (a sub-flow call resolves the
  child's published version at CALL time and pins it on the child run);
  `lib/Controller/FlowController.php` + `appinfo/routes.php`; new
  `FlowVersionService` and lifecycle guard.
- **Affected data**: two migrations — columns on `openregister_flows` and
  `openregister_flow_runs`, plus the new `openregister_flow_versions` table.
  A repair step publishes version 1 of every existing flow from its current
  graph and stamps `flow_version = 1` on every non-terminal run, so nothing
  in flight at upgrade time is left with an unresolvable pin.
- **Affected apps**: every consumer of the shared engine gains the lifecycle;
  no app-side change is required to keep working, because the head row and
  its uuid are unmoved. Apps that ship flow definitions (hermiq, procest,
  openconnector under ADR-098 Decision 1) publish version 1 on install.
- **Affected UI**: `src/views/flows/FlowDetailPage.vue`,
  `FlowDetailSidebar.vue`, `FlowsIndex.vue`.
- **ADRs**: ADR-098 Decision 6 (this change is that decision), ADR-065 (one
  engine — the lifecycle lands in the engine, not per app), ADR-031
  (declarative-first: why the guard is imperative here is argued in
  design.md).
- **Blocks**: `flow-task-entity` and `flow-parallel-streams` both declare
  `depends_on: flow-definition-versioning`; the rest of the ADR-098 chain
  sits behind those.
