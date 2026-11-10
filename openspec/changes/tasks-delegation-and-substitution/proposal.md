---
kind: code
depends_on: [flow-task-entity]
---

# Proposal: tasks-delegation-and-substitution

## Summary

A caseworker who delegates a workflow task can only hand it to a colleague who
holds the mandate the task needs, and the refusal says which mandate is
missing. A task names that mandate as a permission from the instance's
permission catalogue, so "mandate" stops being a sentence nobody checks. A
colleague who sets an absence in Nextcloud with a replacement gets their new
and open tasks routed to that stand-in for the absence period, provided the
stand-in holds the mandate too. The task and its audit show who the stand-in
acts for, why, and until when. When the absence ends, tasks the stand-in never
touched go back.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| buildiq | logic-task-delegate-mandate | Delegate a single workflow task to a colleague, limited to what that colleague is mandated to do. | no |
| buildiq | logic-task-substitute | Send a workflow task to a stand-in automatically when the person it is assigned to is absent. | no |

Both are rows in buildiq's matrix, owned here because `built.owner` is
ConductionNL/openregister: buildiq's task surface
(`src/components/runtime/MyApprovalsWidget.vue`) runs on Open Register's task
engine.

Demand rows:

- logic-task-delegate-mandate: tender, VGGM wens W7,
  https://www.tenderned.nl/aankondigingen/overzicht/310787
- logic-task-substitute: tender, VGGM wens W3,
  https://www.tenderned.nl/aankondigingen/overzicht/310787

Competitor yes cells: none recorded for either row in the packet.

## Why

Delegation exists and records a mandate it never checks:

- `TaskService::delegate()` at `lib/Service/Task/TaskService.php:470-497`
  refuses an empty delegate and an empty mandate (`:473-479`), then sets
  `onBehalfOf`, `assignee` and `mandate` and audits (`:486-491`). Nothing asks
  whether the delegate may do the work. `mandate` is a free string
  (`lib/Db/Task.php:505`), seeded as prose such as "Volmacht inkoop 2026,
  artikel 4 lid 2" (`lib/Repair/SeedTaskFixtures.php:280`).
- `TaskAuthorizationService` checks only that the caller is the assignee
  (`lib/Service/Task/TaskAuthorizationService.php:65-68`, `:407-415`). The
  delegate is never looked at.
- Open Register already has what a checkable mandate needs: a published
  catalogue of grantable permissions including app-declared verbs
  (`lib/Service/Rbac/PermissionCatalogue.php:195-301`), grants that end
  (`until`) and grants scoped to an area (`scopedTo`)
  (`lib/Service/Rbac/GrantConstraints.php:62-69`, `:223-240`), per-user
  evaluation on an object (`lib/Service/Object/PermissionHandler.php:414-421`,
  which accepts a `userId`), app-decided custom verbs through
  `CustomScopeEvaluatingEvent` (`PermissionHandler.php:1567`), and a
  provenance record naming the rule that granted
  (`lib/Service/Rbac/ProvenanceResolver.php:155-165`). No separate mandate
  register is needed; what is missing is the task naming the permission and the
  delegation asking for it.

Substitution does not exist:

- No absence or stand-in routing exists in `lib/Service/Task/`. Routing picks
  from the pool with no notion of presence
  (`lib/Service/Task/TaskPerformerResolver.php:77-123`).
- Nextcloud already records an absence with a period and a replacement user
  (`OCP\User\IAvailabilityCoordinator::getCurrentOutOfOfficeData()`,
  `OCP\User\IOutOfOfficeData::getReplacementUserId()` since Nextcloud 30) and
  announces its start and end (`OCP\User\Events\OutOfOfficeStartedEvent`,
  `OutOfOfficeEndedEvent`). Open Register requires Nextcloud 32
  (`appinfo/info.xml:129`), so it can read them. Nothing does.

## What changes

- A task may declare `requiredMandate`: a verb from the permission catalogue,
  validated at creation. The user-task node gains the same config key.
- `delegate` checks the delegate holds `requiredMandate` on the task's subject
  object, through the same per-user permission check the object endpoints use.
  A delegate without it is refused with 422 naming the verb. Without a
  `requiredMandate`, the delegate must at least be able to read the subject.
- The evidence is recorded: the task and the audit entry carry
  `mandateEvidence` (verb, the rule that granted it, and its `until`), beside
  the existing free-text `mandate`.
- Substitution reads the Nextcloud absence of a task's assignee. A task
  assigned to someone whose absence is in effect and names a replacement goes
  to that replacement, with `onBehalfOf` naming the absent person and an audit
  entry `substitute` that names the absence period.
- It happens on every path that sets an assignee (create, assign, reassign,
  offer routing, claim fallback) and, through the absence start event, for
  tasks already assigned when the absence begins. Pool routing skips absent
  members.
- A stand-in without the task's `requiredMandate` is not used: the task stays,
  the audit says `substitute-refused` with the missing verb, and the inbox row
  flags the assignee as absent.
- When the absence ends, a substituted task the stand-in has not acted on
  returns to the original assignee, audited `substitute-return`.

## Consumers

- buildiq (logic-task-delegate-mandate, logic-task-substitute): the My
  approvals widget shows who a task is handled for and why, and offers delegate
  only to colleagues who hold the mandate. The widget change is buildiq's.
- dossiq: its mandate matrix (`mandate`, `organisatieRol`,
  `medewerkerRolToewijzing` schemas) answers a dossiq-declared verb through
  `CustomScopeEvaluatingEvent`, so a dossiq task's `requiredMandate` is checked
  against the matrix without Open Register knowing its shape. That listener is
  dossiq's.

## ADRs

- hydra ADR-005 (security): the check is on the backend, per object, and fails
  closed when the subject or the verb cannot be resolved.
- hydra ADR-022: apps consume Open Register's permission layer instead of each
  holding its own mandate check.
- hydra ADR-023 (action authorization): the mandate is an action permission from
  the published set, not a new vocabulary.
- hydra ADR-069 and ADR-078: the absence-start rerouting runs as a queued job,
  never inside the event.
- hydra ADR-099: the stand-in acts as themselves, for someone, and the audit
  names both. No identity is borrowed.
- openregister ADR-010 (permission verbs): a mandate is a catalogue verb,
  canonical or declared by an app.

## Impact

- Extends the `flow-tasks` capability (open change `flow-task-entity`).
- Affected code: `lib/Service/Task/TaskService.php`, `TaskBuilder.php`,
  `TaskPerformerResolver.php`, a new `lib/Service/Task/TaskSubstitution.php`,
  `lib/Service/Task/TaskInboxService.php` (the absent flag), `lib/Db/Task.php`
  and `lib/Db/TaskAudit.php` (two columns and a migration), a listener for the
  two absence events and a queued job, `lib/Service/Flow/Nodes/UserTaskNode.php`
  (config key), `lib/Controller/TaskController.php` (422 mapping).
- Backwards compatibility: tasks without `requiredMandate` delegate as before,
  except that a delegate who cannot read the subject is now refused. That is a
  tightening and is named in the release notes. Substitution only acts when
  Nextcloud's absence feature is enabled and an absence names a replacement.
- Size: M.

## Out of scope

- A mandate matrix inside Open Register. The matrix with its decisions,
  ceilings and case types is dossiq's data; it plugs in through the voting
  event.
- Checking the mandate on `assign` and `reassign` by the requester. Those are a
  requester's act, not a hand-over, and a later change can extend the same
  check.
- A stand-in chosen by someone other than the absent person (a manager setting
  a stand-in for sick leave). Nextcloud's absence is set by the user; an
  administrator path is a later change.
- Substitution for group, agent, worker and external performers. Only a user
  assignee is absent.
- buildiq's and dossiq's screens.
