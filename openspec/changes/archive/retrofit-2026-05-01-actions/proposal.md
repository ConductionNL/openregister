# Retrofit — actions

Describes observed behavior of 9 methods across 5 files under `actions` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Controller/ActionsController.php` — index, show, create, update, patch, destroy, test, logs, migrateFromHooks
- `lib/Service/ActionService.php` — createAction, updateAction, deleteAction, testAction, migrateFromHooks, updateStatistics
- `lib/Service/ActionExecutor.php` — executeActions, buildCloudEventPayload
- `lib/BackgroundJob/ActionRetryJob.php` — run, calculateDelay
- `lib/BackgroundJob/ActionScheduleJob.php` — run
- `lib/Listener/ActionListener.php` — handle (already annotated via b2b-crossrefs; adding actions task annotation)

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces observed-but-suspicious behavior (retry delay bug, optional cron dependency)

## REQ map

| REQ | Methods |
|-----|---------|
| REQ-001 | ActionsController::index/show/create/update/patch/destroy, ActionService::createAction/updateAction/deleteAction/updateStatistics |
| REQ-002 | ActionListener::handle, ActionExecutor::executeActions |
| REQ-003 | ActionExecutor::buildCloudEventPayload + executeSingleAction (private) + processWorkflowResult (private) |
| REQ-004 | ActionExecutor::handleFailure (private), ActionRetryJob::run, ActionRetryJob::calculateDelay |
| REQ-005 | ActionScheduleJob::run |

Source: openspec/coverage-report.md generated 2026-05-01. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
