---
kind: capability
---

# Proposal: flow-task-subject-authorization

## Why

`POST /api/flow-tasks` took any `objectUuid` from any signed-in account.
Reproduced against a live instance: an ordinary user with no relationship to
a case gets 404 from `GET /api/objects/dossiq/case/{uuid}`, and the very
next request, `POST /api/flow-tasks` naming that same uuid and an
administrator as the assignee, answered 201. The row landed on the
administrator's work list with the stranger's name in `createdBy`.

Knowing a uuid was the whole check. That is the same shape the task
capability already closed on `POST /api/flow-runs/{uuid}/resume`, left open
one door along, because create is the verb with no task to have a
relationship with and nobody asked what it should have a relationship with
instead.

An unauthenticated caller was refused correctly, so the hole is the
authenticated but unrelated principal, which is every account on the
instance.

## What changes

- A task may be created only on objects its creator may read. The check runs
  through the canonical object read path, `ObjectService::find()` with
  `_rbac: true, _multitenancy: true`, so whatever that path decides about a
  principal this decides identically. No second authorization vocabulary.
- The refusal is 404 carrying the object endpoint's own words, so a create
  cannot be used to learn which objects exist.
- The anchor and every typed relation are checked, because a relation is the
  same attachment under a role.
- The trusted in-process path, `TaskService::import()`, is unchanged. There
  the actor is a flow's attribution rather than the session, so an RBAC read
  would answer about the wrong principal.
- No `DELETE /api/flow-tasks/{uuid}` is added. The reasoning is written into
  the route table beside the verbs that do exist.

## Impact

`lib/Service/Task/TaskSubjectAccessGuard.php` (new),
`lib/Exception/TaskSubjectNotFoundException.php` (new),
`lib/Service/Task/TaskService.php`, `lib/Controller/TaskController.php`,
`appinfo/routes.php`. No migration, no stored data changes. A caller who
could already read the object sees no difference.

## Capabilities

- Modified: `flow-tasks`: creating a task is authorized against the object
  the task is about.
