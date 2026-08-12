# Design: or-flow-connectivity-and-last-run

## What makes a node an exit node

Without a definition, the rule is circular: "a node with no outgoing edge is a
dead end, unless it is an exit node" is vacuous when an exit node is *also*
defined as a node with no outgoing edge. Under the pre-inversion model there was
no marker at all — terminal simply meant "a place nothing leaves" — so the
question could not be asked.

A node is an **exit node** iff either holds:

1. **Its step type is registered terminal.** `StopNode` (`openregister.stop`)
   already raises `FlowStop`, which the engine treats as a deliberate end
   distinct from a step failure. That existing distinction is the definition;
   it just was not queryable.
2. **It carries `exit: true`.** The escape hatch for a step whose terminality is
   a property of the flow rather than of the step type — a `set-fields` node
   that records an outcome and is genuinely the end of that branch.

Terminal-ness is declared by a **new marker interface**, not a method on
`IFlowNode`:

```php
interface IFlowTerminalNode {}   // StopNode implements this
```

`or-flow-preflight` established why: openconnector and hermiq implement
`IFlowNode` from their own repositories, so adding a method to it fatals their
node classes on load. `IFlowNodeConfigKeys` exists for exactly this reason and
is the precedent being followed.

Registry-driven rather than a hardcoded type list, so an app contributing its
own terminal step (a "dead letter" node, a "hand off to n8n" node) is terminal
without an OpenRegister change.

## Where the check runs, and where it bites

| Moment | Behaviour |
| --- | --- |
| `POST /api/flow/validate` | reported under `warnings`, never `blocking` |
| `POST` / `PUT /api/flows` | saved, response carries the warning |
| `POST /api/flows/{id}/run` | **refused** — no run created, flow `status` = `error` |
| trigger / schedule dispatch | same refusal, same status write |

Save must not refuse. A half-wired flow is the normal state of an unfinished
one, and a save gate pushes authors to keep work outside the tool — which is
worse than a dead end, because then nothing checks it at all.

The refusal must cover the **trigger and schedule** paths too, not only the
manual-run endpoint. The Hydra sequencer fires on cron every five minutes and
would never touch `POST /run`; a guard only on the HTTP path would leave the
flows most likely to rot completely unguarded. This mirrors `or-flow-preflight`,
which deliberately guarded the object events rather than the controller for the
same reason.

## Status is not the last run's status

Two fields, because they disagree precisely when it matters:

- `status` / `status_message` — can this flow execute? Set to `error` when a run
  is refused; cleared when a run is accepted.
- `last_run_*` — what happened the last time it did execute.

A flow refused for a dead end has **no** last run, by construction. A UI reading
only run history would show it as never-run and healthy, which is the exact
misreading this change exists to prevent.

`status` is a small closed set: `ok`, `error`. Deliberately not a copy of the
run lifecycle (`running | completed | stopped | dead_letter | suspended |
failed`) — conflating the two is how the fields would drift back into one.

## Storage

Six nullable columns on `oc_openregister_flows`:

| Column | Type | Meaning |
| --- | --- | --- |
| `status` | `varchar(16)` | `ok` / `error`, null before first evaluation |
| `status_message` | `text` | why, when `error` |
| `last_run_uuid` | `varchar(36)` | the run |
| `last_run_status` | `varchar(16)` | its terminal status |
| `last_run_message` | `text` | its error, when it had one |
| `last_run_at` | `timestamp` | when it finished |

All nullable, no backfill: a flow with no runs correctly has no last run, and
`status` is null until something evaluates it. A migration that invented values
here would be asserting facts it does not have.

Written by `FlowRunService` when a run reaches a **terminal** state — not on
every step, which would make a hot flow's row a write-contention point for no
gain, and not on queue, which would report a run that has not happened.

## Seed Data

Not applicable (ADR-001). Flows are native rows, not OpenRegister objects, so
this change touches no schema and generates no `_registers.json` entries.

## Declarative-vs-imperative decision

Not applicable (ADR-031). The connectivity check is a structural precondition on
flow execution, evaluated inside the engine. It is not a lifecycle, aggregation,
derived field, notification, relation or widget, so there is no
`x-openregister-*` surface that could carry it. The last-run fields are columns
on a native table, not object properties.

## Alternatives considered

**Refuse the save.** Rejected above — it drives unfinished work out of the tool.

**Treat any sink node as an intentional exit.** This is today's behaviour and is
exactly the ambiguity being removed: it makes "I forgot to connect this" and
"this is the end" the same document.

**Derive the last run with a join at read time.** Correct but costs a query per
flow on every list render, and the field is wanted precisely on the list. The
denormalised columns are written once per run, at a terminal state.
