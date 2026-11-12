# Proposal: task-subject-identifier

## Why

A task row names its subject (`subject: { uuid, register, schema, title }`), so a task list can show "Bezwaar
parkeerboete Havenstraat". Board DqTaken (dossiq) also shows the case number under it ("2026-0074"), and the task
page says "bij zaak 2026-0074". The row did not carry that number, so every app showing tasks would have had to read
each subject object again.

## What changes

The subject context gains `identifier`: the subject object's own `identifier` property when it is a non-empty string
or number, else null. It is read from the same batched read that already resolves the title, so it costs no query.

The single-task read (`GET /api/flow-tasks/{uuid}`) and the verb responses built their row with no subject at all
(`subjects: []`), so a task page could not name its case even by title. They now use `TaskInboxService::enrich`, the
same row the inbox serves, subject context included.

## Scope

- `lib/Service/Task/TaskInboxService.php`: `contextRow` + `subjectIdentifier`.
- `lib/Controller/TaskController.php`: `show` and the verb responses use `enrich`.
- Additive: one new key on `subject`; nothing else in the row changes.
