## Why

DqTaak draws two things an example set cannot seed today.

1. "Wat deze taak tegenhoudt": the task belongs to a step of a running flow (the bezwaar hearing step), so the page shows that the flow waits on it. The engine links a task to its run and step through `runUuid` and `nodeId`, and the task builder reads both. But a set's flow may only carry finished runs (`ExampleSetFlows::runsRefusal`), so there is no run a seeded task could point at.
2. The history line "Taak opgepakt door Pieter Jansen". Import writes one `create` audit entry by the actor. A claim is a separate audit entry by the assignee, and the loader never writes one.

## What changes

- A set's flow MAY declare a waiting run: status `suspended`, parked on one `openregister.user-task` step, named by a run `key`. It is written the way the user-task step leaves a live wait, so every engine path treats it as one: the heartbeat re-reads the task and parks again, and completing the task continues the run.
- A task quotes the run with the existing flow reference, `{{flow:<flow>.runs.<run>}}`, as its `runUuid`, and names the step as its `nodeId`. Writing the task fills the run's resume slot with the task's uuid.
- A fragment's task MAY declare `claimedBy` and `claimedAt`. Install writes one `claim` entry in the task's audit by that account at that moment.

See `design.md` for the answers to the proposal's open questions.
