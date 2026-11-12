# Proposal: task-inbox-open-first

## Why

Board DqTaken lists open tasks by deadline and puts finished ones last ("Ontvangst bevestigen, Klaar op 6 okt" is
the last row although its date is the earliest). The inbox sorts only by deadline, priority or creation, so a
deadline sort puts finished tasks first in a month's list.

## What changes

`GET /api/flow-tasks` takes `openFirst=true`:

- the inbox orders by terminality first (`is_terminal` ascending);
- then it orders by the chosen sort, in the chosen direction, with the existing id tiebreak.

Without the parameter the order is unchanged.

## Scope

- `TaskInboxCriteria::$openFirst`, `TaskMapper::applyInboxOrder`, `TaskController::index`.
- nextcloud-vue's tasks source sends it (feat/tasks-source-open-first).
