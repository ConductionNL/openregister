# Design: a-task-may-wait-on-another-task

## D-1. One column for the declaration, nothing stored for the state

`blocked_by` holds the blocker's uuid. `blocked` is computed from the
blocker's `is_terminal` whenever a task is read. A stored flag would need a
writer on every terminal path, and `terminateForRun()` and the timer sweep
close tasks without passing through one place a listener could trust. A
derived flag cannot drift: a blocker closed by any path releases the task on
the next read.

The declaration stays after release. It is history: the case can still say
which task this one waited on.

## D-2. The inbox predicate is one uncorrelated subquery

The inbox adds `blocked_by IS NULL OR blocked_by NOT IN (SELECT uuid FROM
tasks WHERE is_terminal = false AND uuid IS NOT NULL)`. Same predicate builder
for the page and for the total, so the badge count agrees with the list by
construction. `uuid IS NOT NULL` keeps `NOT IN` from turning every row
unknown. A blocker that was removed is not open, so its dependants are not
blocked.

An object or run anchor skips the predicate. That read is the case looking at
its own work, the same exception `applyExternalExclusion()` makes, and the
case must show a waiting task with its blocker.

## D-3. The flag on the row is batched

`TaskInboxService::inbox()` asks the mapper once which of the page's
blockers are still open, and each row gets `blocked` from that set. A single
task read (`enrich()`) asks for its one blocker. No query per row.

## D-4. Release is post-event work

`TaskBlockerReleaseListener` listens for the committed `TaskTerminalEvent`.
It finds the open tasks whose `blocked_by` is the closed task and calls
`TaskService::record()` with action `released` for each. `record()` appends
the audit entry and announces the task, so its notification and calendar
entry are written again. A failure for one dependant is logged and the rest
still run: the blocker is closed either way. The listener is idempotent: a
second event for the same blocker writes a second `released` entry, which is
true and harmless.

The in-transaction dispatch (`committed: false`) is ignored. Releasing inside
the blocker's transaction would make closing a task wait on its dependants.

## D-5. Cycles are refused at create

A task created with a blocker walks the chain of `blocked_by` uuids from that
blocker, at most 50 steps. Meeting the new task's own uuid is a cycle and is
refused. Because there is no update verb, only a caller that sets its own uuid
can build a cycle at all, so the walk is cheap insurance, not a hot path.

## D-6. Writing `blocked` by hand is refused on the HTTP path

`TaskService::create()` refuses a payload carrying `blocked`, and a state
that normalises from `blocked`. The trusted `import()` path keeps the legacy
mapping, because migrated approval chains still arrive as `blocked`.
