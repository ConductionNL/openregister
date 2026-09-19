---
kind: code
---

# Proposal: macro-flows-with-next-item

## Summary

Let one action apply several changes and say where the handler goes next.
A manual-trigger flow (`openregister.trigger-manual`) is a flow a person
starts; a declared action (`declared-actions`) is a named, authorised verb
on an object. Nothing binds the two, and nothing lets a flow tell the list
that started it "stay here", "open the next one" or "go back to the list".
This change binds a declared action to a manual flow, runs it for one
object or a selection, and returns a `next` hint the list host honours.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 3.20 | Macro that makes several changes and sets what the handler sees next | partial | M |

## Why

The register's note: "`#Cases/bulkActions.reassign` and
`BulkTransitionDialog.vue` do one thing each." The best competitor,
verbatim from the `best` column: "Zammad 7: app/models/macro.rb with
ux_flow_next_up (`_round3/compare/proposed-rows.md`)".

The register's `why`: "one action applying several changes is a manually
triggered flow; where the handler goes next is the list host's". The
navigation is nextcloud-vue's half; the binding and the hint are here.

## What changes

- A declared action MAY carry `flow: "<flow slug>"` and `macro: true`. The
  flow must have a manual trigger and be published; the schema validator
  refuses otherwise. Authorisation stays the action's (ADR-023).
- `POST /api/objects/{register}/{schema}/{id}/actions/{action}` queues the
  flow with the object as subject, `executionMode: sync` by default so the
  changes are visible when the call returns, and answers with the run id,
  the run's outcome and `next`.
- `POST /api/objects/{register}/{schema}/actions/{action}` takes a
  selection (ids or a query) and queues one run per object under the
  bulk-write path (`or-flow-bulk-object-write`), answering with a summary
  and `next`.
- A flow declares `next: "stay" | "next" | "list"` on its manual trigger
  node, overridable per run by an end node. The hint is data for the host;
  the engine does not navigate.
- The run is attributed to the person who started it (ADR-099), and each
  object's audit trail names the action and the run.

## Consumers

- dossiq: mark flows as macros on the case. nextcloud-vue: next-item
  navigation after a write. Specified in dossiq by the dossiq lane and in
  nextcloud-vue by its lane (register row 3.20).
- pipelinq (a "won" macro), decidiq, humaniq: the same binding.

## ADRs

- ADR-065 and ADR-098 decision 1: one engine, the macro is a flow.
- ADR-023: the action is the authorised verb; the flow does not widen it.
- ADR-099: the run acts as the person.
- ADR-031: the binding is schema data.

## Impact

- Extends: `declared-actions` requirement "A schema may declare additional
  actions, and only declared ones may be authorised" and `flow-engine`
  requirement "A TRIGGER is a node, and a flow may carry several".
- Affected code: the declared-actions validator, an actions controller
  (single and bulk), `FlowRunService::queue()` (subject and attribution),
  the manual trigger node config (`next`), the run result envelope.
- Size: M.
