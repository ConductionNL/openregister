---
kind: code
depends_on: [flow-definition-versioning]
---

# Proposal: migrate-run-between-versions

## Summary

Move a running flow from the definition version it is pinned to onto a
newer one, deliberately, with a mapping and a reason. `flow-definition-versioning`
pins a run to a version at queue time and says a run whose version is gone
"fails loudly and is never re-pointed". That is the right default and it
leaves no door: a process changed by a new law has to finish on the old
version or be restarted. This change adds the door, as an explicit
migration with a node mapping the engine validates, a dry run, and a
journal entry on the run.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| 3.16 | Process or case migration between definition versions | partial | M |

## Why

The register's note: "`case.workflowVersion`,
`lib/Command/MigrateWorkflowDefinitionsToFlowsCommand.php`; no per-case
migration UI". The best competitor, verbatim from the `best` column:
"xxllnc Zaken:
`backend/zaken/src/zsnl_case_management_http/routes/routes.py`
(case/casetype/update) (`_round2/compare/M1-functionality.md`)".

The register's `why`: "moving a pinned run to another definition version
is engine work; dossiq supplies the reason and the UI", and its `covered`
note: "flow-definition-versioning pins a run, it does not move one".

## What changes

- `POST /api/flows/{flow}/runs/{run}/migrate` with a target version, a
  reason, and an optional node mapping (`{oldNodeId: newNodeId}`) for nodes
  the target renamed or split. Allowed for a user with the flow's `run`
  right and `manage` on the run's subject.
- The engine validates the migration before touching the run: every place
  in the run's marking must map to a node the target version has, with a
  compatible type; a pending user task must map to a task node; a
  suspended await must map to an await node. A failing validation names
  the offending places and nothing changes.
- `dryRun: true` returns the validation and the resulting marking without
  applying.
- Applying rewrites the run's pinned version and marking in one
  transaction, re-arms business timers whose node changed (supersession
  with reason `migrated`), keeps the run log, and appends a `migrated`
  entry with the old and new version, the mapping, the reason and the
  actor.
- `POST /api/flows/{flow}/migrate-runs` migrates every run pinned to one
  version onto another with one mapping, in bounded batches, reporting per
  run; a run that fails validation is skipped and named.
- A `deprecated` version whose runs are all migrated can be retired.

## Consumers

- dossiq: the per-case migration action, paired with 2.13 (rebinding a
  running case). Specified in dossiq by the dossiq lane (register row
  3.16).
- decidiq, humaniq, shillinq: every app whose long-running flows outlive a
  definition.

## ADRs

- ADR-098 decision 6 (versioning before humans): migration is the second
  half of pinning.
- ADR-065: one engine.
- ADR-099: the migration runs as the person who asked for it.
- openregister ADR-003: the run log is append-only; the migration is an
  entry.

## Impact

- Extends: `flow-definition-versioning` requirements "A run advances
  against its pinned version, never the live definition" and "A run whose
  pinned version is gone fails loudly and is never re-pointed" (the door is
  explicit, never automatic).
- Affected code: `lib/Service/Flow/FlowRunMigrationService.php`, a
  controller, `FlowRunAdvancer` (marking rewrite under lock),
  `FlowTimerService::supersede()` (reason `migrated`), the run log.
- Size: M.
