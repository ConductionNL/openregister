---
kind: code
depends_on: [flow-portal-task]
---

# Proposal: a-portal-task-names-the-app-that-completes-it

## Summary

A task may name the app action that completes it: `metadata.completeVia` =
`{app, action}`. The portal seam then refuses a direct completion of that
task with 409 `complete-via-app`, naming the app and the action, and only the
named app completes it in-process (`PortalTaskService::complete(viaApp: ...)`).
A portal reads the same key off the task row and opens that app's action
instead of the generic upload form.

## Why

Some tasks are more than "send a file and a comment". dossiq's checklist task
for an external inspector must be checked against the checklist frozen on the
task and completed with the rule's outcome; that check lives in dossiq. Before
this change the portal's "Mijn taken" completed every task straight through
this seam, so the party could skip the app's checks simply by using the
generic form, or by calling the seam directly. Hiding the form in the portal
would not close that: the seam would still accept the call. The refusal
belongs where the completion is recorded.

The key is generic. Any app that completes its own tasks (a checklist, a
signature, a payment) sets it on the tasks it creates; nothing here names a
procedure (decision 182).

## What changes

- `PortalTaskService::COMPLETE_VIA` and `completeVia(Task)`: the whole
  `{app, action}` or null; half of one is ignored rather than locking a task.
- `PortalTaskService::complete()` takes `viaApp`. After authorization (so a
  stranger still reads absence and the denial is still audited), a task that
  names an app is refused unless `viaApp` is that app. Nothing is stored.
- `TaskCompleteViaAppException` (a `TaskConflictException`) carries the route.
- `PortalTaskController::complete` answers 409 `complete-via-app` with
  `completeVia`.

## Out of scope

- The internal task API: a staff user completing a task in Nextcloud is not
  the portal seam, and the app owning the task decides what its own UI offers.
- The portal side (portaliq opens the action) and the app side (dossiq sets the
  key on its checklist tasks) ship in their own repos.
