---
kind: code
depends_on: [flow-task-entity, flow-user-task-node]
---

# Proposal: flow-portal-task

## Summary

Ask a person who has no account. A new node type `openregister.portal-task`
creates a task for an EXTERNAL party (ADR-098 Decision 3 as amended
2026-08-31: performer type `external`), matched from the subject case
object's party role (default: the initiator), and suspends the run
heartbeat-safe until that party answers. The task reaches the party through
portaliq's contribution surface (ADR-046): a portal inbox message plus a
mail, never a Nextcloud notification. The answer can carry an upload, and
that file lands as an OpenRegister file attachment on the case object
(ADR-022); any dossier folder view is a projection of that attachment. Only
the matched party may complete. A caseworker who is not satisfied routes the
flow back into the node with a reason, and the party is asked again. The
overdue path (reminder, escalation, enforcement) is consumed from
`flow-business-timers`, not rebuilt here.

## Why

**The engine can now ask a person with an account. Dutch case handling
mostly waits on a person without one.** `flow-user-task-node` gives the
graph a performer who can be found, told and allowed to say no, but its
performer model resolves against Nextcloud: a uid, a group, a role, an
agent. The hersteltermijn (ADR-098 Context: wait-on-citizen is the
CMMN-shaped core of Dutch case handling) is addressed to a resident who
authenticates with DigiD at the portal's edge and will never hold a
Nextcloud account. Today that resident is unreachable from the graph, so
every "send the applicant a letter and wait" step stays manual.

**The projection alternative was measured and rejected on 2026-08-31.** A
portaliq-side mirror of tasks would be a second store that can drift, the
`agentflow` defect class ADR-098 Decision 2 forbids, and it could not
suspend a run on the resident's answer. Residents become first-class
performers by EXTENDING the performer model instead. That amendment is
recorded in ADR-098 Decision 3.

**The delivery surface already exists and already enforces the boundary.**
Portaliq discovers a leaf app's `PortalContributionProvider` by convention
(ADR-046), aggregates DESCRIPTORS never data, and serves rows through
subject-scoped readers; its endpoint actions forward server-to-server with a
signed `X-Portal-Subject` assertion, never the client bearer
(`portaliq/lib/Contribution/IPortalContributionProvider.php`). A portal task
that rides this contract inherits the tenant boundary instead of
re-implementing it. What portaliq renders is portaliq's change to make; this
change specifies the OpenRegister half of the seam.

**The upload has exactly one correct home.** OpenRegister already attaches
files to an object's own folder (`lib/Service/FileService.php`,
`addFile()`). Decided 2026-08-31: a resident's uploaded file lands as an OR
file attachment on the CASE object, and any dossier folder view of it is a
projection. A file held in a portal-private store is evidence the case
cannot see.

**And the suspension discipline is measured, not optional.**
`FlowRunMapper::findAbandonedSignals()` matches `resume_at IS NULL`
(`lib/Db/FlowRunMapper.php:589-605`) and `FlowRunWorker` FAILS those runs at
14 days (`lib/BackgroundJob/FlowRunWorker.php:94`, `:311-349`). A
hersteltermijn is routinely longer than 14 days. A portal-task node that
parked on a null `resumeAt` would hand the reaper its first input and fail
exactly the waiting-on-citizen runs this node exists to hold open. The node
copies `AwaitSignalNode`'s non-null heartbeat discipline verbatim.

## What Changes

- **A new node, `openregister.portal-task`**, in `lib/Service/Flow/Nodes/`,
  implementing `IFlowNode`, `IFlowNodeConfigKeys` and `IFlowNodeConfigForm`,
  registered through `lib/Listener/FlowNodeRegistrationListener.php`. A
  SEPARATE node, not an external-performer mode of
  `openregister.user-task`; design.md D-1 argues the choice. In one line:
  the performer resolution, the delivery channel and the completion payload
  all differ, and a mode would make half of each node's config keys invalid
  depending on the mode.
- **The `external` performer type on the task entity** (Modified capability
  `flow-tasks`): performer reference is a party reference resolved to a
  portal subject, such tasks appear in NO Nextcloud inbox or candidate
  pool, `claim`/`unclaim`/`delegate` are refused for them, and completion
  authorization matches the acting portal subject to the stored party
  reference, fail-closed.
- **Party matching from the case, not from config.** The node names a party
  ROLE on the subject case object (default `initiator`); the concrete party
  is resolved at task creation, frozen on the task, and recorded in the
  audit. A case that names nobody for that role fails the firing loudly.
- **Suspend and resume exactly as `flow-user-task-node`**: one task per
  node per run via the node's own resume slot, continuation on task
  terminality, outcome written onto every item, non-null heartbeat
  `resumeAt` (15-minute default, 5-minute floor, matching
  `AwaitSignalNode.php:87`, `:98`).
- **Delivery through portaliq**: the task is exposed subject-scoped to the
  portal contribution seam, and its creation requests a portal inbox
  message plus a mail to the party. Nothing is delivered through
  `INotificationManager` or the VTODO projection; an external performer has
  neither.
- **Upload completion onto the case**: a completion may carry one or more
  files; each is stored as an OR file attachment on the case object via the
  file service, and the task's completion record references the stored
  files. Node config declares whether an upload is required and constrains
  type and size.
- **Re-ask as graph re-entry**: when the flow routes back into the node
  after its task went terminal, the node creates a NEW task carrying a
  mandatory reason, increments the cycle count, and delivers again. The
  previous task and its audit survive untouched.
- **The overdue path consumes `flow-business-timers`**: the node passes
  `due_at`/`expires_at` references to the task; a `preBreach` escalation
  rung is the resident's reminder (through the portal delivery seam), a
  `slaBreached` rung escalates to the caseworker role, and expiry
  enforcement transitions the task there, never here. The node owns no
  clock.

## What does NOT change

- **`flow-task-entity` and `flow-user-task-node`** own everything they
  already specify: table, lifecycle, verbs, authorization, inbox, the
  user-task node's mechanics. This change adds one performer type to the
  entity's model and one sibling node; it redefines nothing.
- **Portaliq's own surface.** The contribution that renders the portal task
  and its upload form is a follow-up change in portaliq's repository. One
  canonical home per spec: this change specifies the OpenRegister seam it
  will consume, and tasks.md carries exactly one pointer line for it.
- **`flow-business-timers`** owns reminders, escalation ladders,
  opschorting and expiry enforcement. This change names the rungs it
  consumes and stores the two timestamps.
- **`flow-task-inbox-projections`** stays Nextcloud-facing. External tasks
  are explicitly outside its notification and VTODO scope.
- **`AwaitSignalNode`, `POST /api/flow-runs/{uuid}/resume`** and every
  existing node and endpoint are untouched. The resume endpoint cannot
  complete a portal task, exactly as it cannot complete a user task.

## Capabilities

### New Capabilities

- `flow-portal-task`: the `openregister.portal-task` step node: party
  matching from the case, external task creation, heartbeat-safe
  suspension, portal delivery contract, upload-to-case completion,
  matched-party-only completion, re-ask cycles, and the timer consumption
  contract.

### Modified Capabilities

- `flow-tasks`: gains the `external` performer type (ADR-098 D3 amendment
  2026-08-31) with portal-scoped visibility and authorization; delta in
  `specs/flow-tasks/spec.md`.

## Impact

- **Affected specs**: new `flow-portal-task`; delta on `flow-tasks`.
- **Affected code**: new `lib/Service/Flow/Nodes/PortalTaskNode.php`; a
  party-matching resolver against the subject object; the external-performer
  branch in `flow-task-entity`'s authorization service; a portal delivery
  seam (subject-scoped task read plus a delivery request record) consumed
  by portaliq; completion handling that stores uploads through
  `FileService::addFile()` onto the case object.
- **Affected apps**: portaliq (follow-up change in its own repo renders the
  contribution); dossiq consumes the node in its hersteltermijn flow, in
  its own change.
- **Depends on**: `flow-task-entity` (the task, its lifecycle and audit)
  and `flow-user-task-node` (the suspend/resume, outcome placement and
  cancellation mechanics this node reuses).
- **ADRs**: ADR-098 D3 as amended 2026-08-31 (`external` performer; upload
  lands on the case object), D2 (no second store), D4 (the case object is
  the anchor); ADR-046 (portal contribution contract); ADR-108 (public
  surface placement belongs to portaliq); ADR-022 (apps consume OR
  abstractions; the file attachment IS the record); ADR-005 (fail-closed
  authorization); ADR-031 (declarative-vs-imperative, argued in design.md).
