## Why

DqTaak draws a task with "Gevraagd door Anouk Bakker" and "Verloopt 14 okt". The task engine stores a requester and an expiry, and its builder reads both, but the example-set loader refused `requester` and `expiresAt` as fields a set may not write. A seeded task could never show either line.

## What changes

- A fragment's task MAY declare `requester`: an account the set creates or adopts, usually written as `{{cast:<person>.uid}}`. Any other account is refused at check time.
- A fragment's task MAY declare `expiresAt`: a date, usually written as `{{date:n}}` or `{{datetime:n HH:MM}}`. A value that is not a date, or a token that does not resolve, is refused at check time. An expiry before the task's `dueAt` is refused at check time too, the same rule the task builder applies at write time.

Out of scope: a task's flow run and step (`runUuid`, `nodeId`) and the claim line in its history. Those are proposed in `example-set-task-waiting-run-and-claim`.
