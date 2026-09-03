# One flow engine, and it is ours

## Why

ADR-065 already says OpenRegister is the only home for a flow engine in this
fleet. The external-engine layer predates that decision and was never retired
with it: adapters for n8n and windmill, a registry, an engines table, a settings
screen, actions, hooks, deployed workflows, scheduled workflows, five background
jobs and two event listeners.

Measured before removing any of it:

| table | rows |
| --- | --- |
| `workflow_engines` | **0** |
| `actions` | 0 |
| `action_logs` | 0 |
| `deployed_workflows` | 0 |
| `scheduled_workflows` | 4 |
| `workflow_executions` | 4 |

Nobody has configured an engine, so every path through the layer is unreachable:
each one resolves an engine id first and throws when it finds none.

The four scheduled workflows are the interesting part, and they say more than
the zeroes do. Every one names `engine=openconnector` — **an engine type the
registry never supported**, since `resolveAdapter()` matched only `n8n` and
`windmill` and threw on anything else. All four carried `last_status=error`,
unchanged since 2026-08-30. They had been failing every run for days.

So this is not the removal of a working capability. It is the removal of a layer
that could not work, in favour of the engine that does.

## What goes

The adapters, the registry, the engines table and its settings screen; actions
and hooks and their executors, listeners, retry and schedule jobs; deployed and
scheduled workflows and their controllers; the config export/import paths that
deployed workflows into an engine; and the frontend that drove all of it.

## What stays, and is easy to confuse

`lib/WorkflowEngine/` held **two different things** behind one directory name.
`RunFlowOperation` and `RegisterObjectEntity` integrate OUR flow engine into
Nextcloud's NATIVE workflow engine — that is the opposite of what this change
removes, and deleting them would have removed the very thing "rely purely on our
own engine" asks for. They stay.

`SchemaWorkflowTab` also survives, stripped rather than deleted: its hook and
engine sections go, its `TaskSequencePanel` stays, because task sequences are
our approval flow.

## A defect this change introduced and then found

The migration reports each scheduled workflow before dropping it, so the intent
somebody wrote down is not destroyed in silence. The first version queried with
backticks around the table name — MySQL syntax. On Postgres it threw, the catch
swallowed it, and the report never fired. Rewritten through the query builder
and verified on Postgres.

That failure mode is worth naming: a report that exists to prevent silent data
loss, which silently does not run, is worse than no report at all.
