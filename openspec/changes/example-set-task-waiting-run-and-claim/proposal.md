## Why

DqTaak draws two things an example set cannot seed today.

1. "Wat deze taak tegenhoudt": the task belongs to a step of a running flow (the bezwaar hearing step), so the page shows the flow run that waits on it. The engine links a task to its run and step through `runUuid` and `nodeId`, and the task builder reads both. But a set's flow may only carry finished runs (`ExampleSetFlows::runsRefusal`), because a queued, running or waiting run would be picked up by the flow worker and executed. There is no run a seeded task could point at.
2. The history line "Taak opgepakt door Pieter Jansen". Import writes one `create` audit entry by the actor. A claim is a separate audit entry by the assignee, written by TaskService's claim verb, and the loader never writes one.

## What changes

- A set's flow MAY declare a waiting run: status `waiting`, parked on one node, that the flow worker does not resume on its own. The run is only woken by its task completing, the same as a live waiting run. This needs a decision on how the worker tells a seeded waiting run apart from a stuck one (for example: a waiting run is never picked up by the queue, only resumed by its task, which is already true for human-task nodes; to verify).
- A new reference `{{flowRun:<flow>.<run>}}` gives a task the uuid of a run the same fragment declares, so the task can declare `runUuid` and `nodeId`. The check refuses a `nodeId` that is not a node of that run's flow, and a run that is not waiting.
- A fragment's task MAY declare `claimedBy` and `claimedAt`. After import the loader writes a `claim` audit entry by that account at that moment, through TaskService (not a direct insert), so the history reads like a live claim.

## Open questions

- Does a seeded waiting run need its own flag, or is the human-task wait enough to keep the worker away from it?
- Should the claim be written by calling the claim verb as the assignee (a real state change, then a re-date of the audit row), or by an audit-only trusted path?
- Remove: the run must be deleted before its flow, and the task before its run.
