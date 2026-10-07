# Design: tasks-delegation-and-substitution

Read at openregister development c53dd0685c.

## D-1: a mandate is a catalogue permission, named on the task

Open Register has no mandate register, and it does not get one. A mandate is
"this person may do this kind of act here, until then", and the permission
layer already says exactly that:

- the grantable set is published (`lib/Service/Rbac/PermissionCatalogue.php:195`
  `all()`, `:270` `isGrantable()`), including verbs an app declares through
  `PermissionsDeclaringEvent`;
- a grant can end and be confined to an area
  (`lib/Service/Rbac/GrantConstraints.php:62` `until`, `:69` `scopedTo`);
- an app can decide its own verb through `CustomScopeEvaluatingEvent`
  (`lib/Service/Object/PermissionHandler.php:1567`).

So the minimal mandate is a grant of a catalogue verb. A task declares which
one it needs in a new nullable column `required_mandate` on
`openregister_tasks`, read by `TaskBuilder` from `requiredMandate` (beside
`mandate` at `lib/Service/Task/TaskBuilder.php:156`) and offered as a config key
on `UserTaskNode` (`lib/Service/Flow/Nodes/UserTaskNode.php:225-240`). An
unknown verb is refused at creation with 400 naming it, through
`PermissionCatalogue::isGrantable()`, so a typo cannot make every delegation
fail for a year.

## D-2: the delegation asks the permission layer about the delegate

`TaskService::delegate()` (`lib/Service/Task/TaskService.php:470`) gains one
step after the existing empty checks (`:473-479`) and before the mutation
(`:481`): a new `TaskMandateGuard::assertHolds(Task $task, string $uid): array`.

- With a subject (`objectUuid`, `register`, `schema` on the task,
  `lib/Db/Task.php:605-621`) and a `requiredMandate`, it loads the subject and
  calls `PermissionHandler::hasPermission(schema, action: requiredMandate,
  userId: delegate, object: subject)` (`PermissionHandler.php:414-421`). That is
  the same call the object endpoints make, so the answer cannot differ from what
  the delegate would get acting on the object themselves.
- With a subject and no `requiredMandate`, the verb is `read`: nobody receives
  work on a case they cannot open.
- With a `requiredMandate` and no subject, the check runs against the register
  and schema the task names, and refuses when neither is set, because a
  mandate with nowhere to be checked cannot be confirmed.
- Refusal throws a new `TaskMandateRefusedException`, mapped to 422 in
  `TaskController::respondWith()` (`lib/Controller/TaskController.php:621-662`)
  beside `TaskSubjectWriteRefusedException`. The message names the verb and the
  delegate, never the subject's content.

On success the guard returns the evidence: `{verb, source, rule, until}` built
with `ProvenanceResolver::forAction()` (`lib/Service/Rbac/ProvenanceResolver.php:155`).
A custom verb decided by an app's vote records `source: custom` and the app id.

## D-3: evidence is stored on the task and the audit entry

A nullable JSON column `mandate_evidence` on `openregister_tasks` and on
`openregister_task_audit`. `appendAudit()` (`TaskService.php:1414-1427`) copies
it the way it copies `mandate` today (`:1423`). The free-text `mandate` stays:
it is what the delegator says, the evidence is what the system confirmed.
`assignInternal()` clears both on assign and reassign, as it clears `mandate`
today (`:1060-1062`).

## D-4: substitution reads Nextcloud's absence

A new `TaskSubstitution` service with one entry point,
`routeIfAbsent(Task $task, DateTimeInterface $now): ?Task`, reading
`IAvailabilityCoordinator::isEnabled()`, `getCurrentOutOfOfficeData()` and
`isInEffect()`, and `IOutOfOfficeData::getReplacementUserId()` (Nextcloud 30,
Open Register requires 32 per `appinfo/info.xml:129`). It acts only when the
task's performer type is `user`, the assignee's absence is in effect and names
a replacement, and the replacement is a different, enabled user.

It sets `assignee` to the stand-in and `onBehalfOf` to the absent person,
leaves `mandate` alone, sets `mandate_evidence` from D-2 run for the stand-in,
and audits `substitute` with actor `absence:<absence id>` and a reason naming
the period in ISO dates (the convention the timer uses,
`lib/Service/Flow/Timer/FlowTimerService.php:1264`). The absence message is
never copied: it is the user's personal text.

It is called from every path that sets an assignee: `assignInternal()`
(`TaskService.php:1050`), the routing pick in `offer()` (`:342`), `create()`
and `import()` when an assignee is given (`:216`, `:263`), and the claim
fallback. `TaskPerformerResolver::resolveAssignee()`
(`lib/Service/Task/TaskPerformerResolver.php:77`) drops absent members from the
pool before `round-robin`, `least-loaded` and `hierarchical` pick, so routing
does not choose somebody who is away when a present colleague is in the pool.

## D-5: an absence that starts or ends later

A listener for `OutOfOfficeStartedEvent` and `OutOfOfficeEndedEvent` only
queues a `TaskSubstitutionJob` (QueuedJob) with the user id (hydra ADR-069,
ADR-078). The job selects that user's open tasks through the index `or_tasks_assignee_open` on
`(assignee, is_terminal, due_at)` (`lib/Migration/Version1Date20260831120000.php:268`),
at most 200 per run, and requeues itself with a
watermark when more remain.

- On start: each task goes through `routeIfAbsent()`.
- On end: a task whose last `substitute` audit entry names this absence, and
  that has no later audit entry with the stand-in as actor, returns to the
  original assignee (`onBehalfOf` cleared, audit `substitute-return`). A task
  the stand-in has acted on stays with them: taking half-done work away is
  worse than leaving it.

## D-6: a stand-in without the mandate is not used

When the stand-in fails D-2, the task stays with the absent assignee, the
audit gets `substitute-refused` naming the verb and the stand-in, and the inbox
row built by `TaskInboxService::row()` (`lib/Service/Task/TaskInboxService.php:153`)
carries `assigneeAbsent: true` and `absentUntil`, so a requester sees work that
is waiting on somebody away. Routing to someone without the authority would
make the substitution the way to get around the mandate.

## Declarative-vs-imperative decision

Imperative, in the task service. This is task routing and authorization, not
object lifecycle or a schema-declared rule: the task engine owns assignment,
and the permission layer already owns the declarative half (grants with
`until` and `scopedTo`). A schema annotation would duplicate the grant.

## Risks

- Security (hydra ADR-005): the guard fails closed. A subject that cannot be
  loaded, a verb the catalogue does not know, or a permission check that throws
  refuses the delegation. The guard never runs as the system
  (`SystemOperationContext`, `PermissionHandler.php:431-433`) because that
  would pass every check.
- Information leak: the 422 names the verb and the delegate only. It does not
  say which rule was missing or anything about the subject, so a delegator
  cannot probe another user's rights beyond yes or no for the task's own verb.
- Performance (hydra ADR-058): the job is bounded to 200 tasks per run on an
  indexed query. The absence read is cached by Nextcloud per user
  (`IAvailabilityCoordinator::clearCache()` exists for that).
- Multitenancy (openregister ADR-002): a stand-in in another organisation fails
  the subject read in D-2 and is not used.
