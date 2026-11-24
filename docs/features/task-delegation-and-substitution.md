# Task delegation with a mandate, and substitution

A workflow task can name the mandate whoever performs it must hold. Open
Register checks that mandate when a task is delegated and when it is handed to
the stand-in of someone who is away.

## The mandate a task requires

A task may carry `requiredMandate`: one permission from the instance's
permission catalogue (`GET /api/permissions`), canonical or declared by an
app. A user-task step offers the same setting as **Required mandate**.

Creating a task with a verb the catalogue does not hold is refused with 400,
and the message names the verb, so a typo cannot make every later delegation of
the task fail.

## Delegating a task

`POST /api/flow-tasks/{uuid}/delegate` with `delegate` and `mandate` (the
sentence naming the authority relied on) hands the task to a colleague. Before
anything changes, Open Register asks the permission layer whether the delegate
holds the task's mandate:

| The task has | The delegate must hold |
| --- | --- |
| a subject and a `requiredMandate` | that permission on the subject object |
| a subject and no `requiredMandate` | read access to the subject |
| a `requiredMandate` and no subject | that permission on the schema the task names |
| neither | nothing is checked |

This is the same per-user question the object endpoints ask, so the answer
matches what the delegate would get acting on the object themselves. A
delegate who does not hold it is refused with 422 naming the permission and the
delegate; the task and its audit are unchanged. Anything that cannot be
answered (a subject that cannot be loaded, an unknown permission, a check that
fails) refuses as well.

A successful delegation records `mandateEvidence` on the task and on the
`delegate` audit entry: the permission, where it was granted (`object`,
`schema`, `register`, `role`, or `custom` with the app that decided it), the
rule, and its `until` when the grant ends. The free-text `mandate` stays beside
it: the first is what the delegator said, the second what the system confirmed.

Before this change a task without a `requiredMandate` could be delegated to
anyone. It now needs a delegate who can read the task's subject.

## Substitution when someone is away

When a user sets an absence in Nextcloud and names a replacement, tasks
assigned to them go to that stand-in for the absence period:

- on every path that sets an assignee: creation, import, assign, reassign and
  routing an offered task;
- for tasks they already hold, when the absence starts (a queued job handles
  at most 200 tasks per run and continues where it stopped).

The task's `onBehalfOf` names the absent person, and the audit has a
`substitute` entry by `absence:<id>` naming the period. Routing a pool skips
members who are away while somebody present is in the pool.

The stand-in must hold the task's mandate, checked as for a delegation. When
they do not, the task stays with the absent person, the audit gets a
`substitute-refused` entry naming the permission, and the task's row in
`GET /api/flow-tasks` carries `assigneeAbsent: true` and `absentUntil`, so the
requester sees the work is waiting on somebody away.

When the absence ends, a substituted task the stand-in has not acted on goes
back to the original assignee, audited `substitute-return`. A task the stand-in
has worked on (a checklist tick, a comment, a completion attempt) stays with
them.

Substitution only acts when Nextcloud's absence feature is enabled and the
absence names a replacement who is an enabled user. The absence message is
never read. Only user performers are substituted; group, agent, worker and
external performers are not.
